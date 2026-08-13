<?php
// database/migrate_chat_system.php
// Schema for the MohToh Intelligent Chat System: conversations (ride
// group chat, private driver-passenger chat, support, admin broadcast),
// membership/read-state tracking, message reporting, and the columns
// messages needs to belong to a conversation instead of being a flat
// sender/receiver pair. Extends the existing `messages` table rather
// than replacing it — api/call-management.php and admin-api.php's
// broadcast action already write to it. Safe to re-run: every step
// self-heals via SHOW COLUMNS/SHOW TABLES checks, same pattern as
// migrate_pricing_center.php / migrate_integration_settings.php.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function cs_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}
function cs_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== conversations ===\n";
    if (!cs_table_exists($pdo, 'conversations')) {
        $pdo->exec("
            CREATE TABLE conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                type VARCHAR(20) NOT NULL,
                ride_share_id INT NULL,
                created_by INT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                archived_at TIMESTAMP NULL,
                FOREIGN KEY (ride_share_id) REFERENCES ride_shares(id) ON DELETE SET NULL,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_type (type),
                INDEX idx_ride_share (ride_share_id),
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== conversations.audience (broadcast targeting) ===\n";
    // Broadcast-type conversations don't get a conversation_members row
    // per recipient (would mean millions of rows for a large audience).
    // Instead this mirrors notification_broadcasts.target_type so
    // list_conversations can find "conversations I can see" for a
    // broadcast by matching the caller's user_type against this column,
    // the same targeting data the existing broadcast admin action
    // already computes — not a second targeting system.
    if (!cs_column_exists($pdo, 'conversations', 'audience')) {
        $pdo->exec("ALTER TABLE conversations ADD COLUMN audience VARCHAR(20) NULL AFTER type");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== conversation_members ===\n";
    if (!cs_table_exists($pdo, 'conversation_members')) {
        $pdo->exec("
            CREATE TABLE conversation_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                user_id INT NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'member',
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_read_message_id INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                UNIQUE KEY uniq_conversation_user (conversation_id, user_id),
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== message_reports ===\n";
    if (!cs_table_exists($pdo, 'message_reports')) {
        $pdo->exec("
            CREATE TABLE message_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                message_id INT NOT NULL,
                conversation_id INT NOT NULL,
                reported_by INT NOT NULL,
                reason VARCHAR(255) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_by INT NULL,
                reviewed_at TIMESTAMP NULL,
                FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
                FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== messages (extend) ===\n";
    $additions = [
        'conversation_id' => "ALTER TABLE messages ADD COLUMN conversation_id INT NULL AFTER id",
        'message_type' => "ALTER TABLE messages ADD COLUMN message_type VARCHAR(20) NOT NULL DEFAULT 'text' AFTER message",
        'attachment' => "ALTER TABLE messages ADD COLUMN attachment VARCHAR(255) NULL AFTER message_type",
        'latitude' => "ALTER TABLE messages ADD COLUMN latitude DECIMAL(10,7) NULL AFTER attachment",
        'longitude' => "ALTER TABLE messages ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude",
        'is_read' => "ALTER TABLE messages ADD COLUMN is_read TINYINT(1) NOT NULL DEFAULT 0 AFTER longitude",
        'flagged_for_review' => "ALTER TABLE messages ADD COLUMN flagged_for_review TINYINT(1) NOT NULL DEFAULT 0 AFTER is_read",
        'public_id' => "ALTER TABLE messages ADD COLUMN public_id VARCHAR(30) NULL AFTER flagged_for_review",
    ];
    foreach ($additions as $col => $sql) {
        if (!cs_column_exists($pdo, 'messages', $col)) {
            $pdo->exec($sql);
            echo "Added column $col.\n";
        } else {
            echo "Column $col already exists.\n";
        }
    }
    // sender_id must be nullable for system messages (rendered as "MohToh").
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'sender_id'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Null'], 'NO') === 0) {
        $pdo->exec("ALTER TABLE messages MODIFY sender_id INT NULL");
        echo "sender_id made nullable.\n";
    } else {
        echo "sender_id already nullable.\n";
    }
    // receiver_id must be nullable for group (ride/broadcast) messages.
    $stmt = $pdo->query("SHOW COLUMNS FROM messages LIKE 'receiver_id'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Null'], 'NO') === 0) {
        $pdo->exec("ALTER TABLE messages MODIFY receiver_id INT NULL");
        echo "receiver_id made nullable.\n";
    } else {
        echo "receiver_id already nullable.\n";
    }

    // Index on conversation_id — added after the column exists (can't be
    // part of the ALTER above since it runs conditionally per-column).
    $stmt = $pdo->query("SHOW INDEX FROM messages WHERE Key_name = 'idx_conversation_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE messages ADD INDEX idx_conversation_id (conversation_id)");
        echo "Added idx_conversation_id.\n";
    } else {
        echo "idx_conversation_id already exists.\n";
    }

    echo "\n=== users.notify_chat ===\n";
    if (!cs_column_exists($pdo, 'users', 'notify_chat')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN notify_chat TINYINT(1) NOT NULL DEFAULT 1");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    // Backfill public_id on any pre-existing rows.
    foreach (['conversations' => 'CONV', 'message_reports' => 'MRPT', 'messages' => 'MSG'] as $table => $prefix) {
        echo "\n=== $table.public_id backfill ===\n";
        $rows = $pdo->query("SELECT id FROM `$table` WHERE public_id IS NULL")->fetchAll();
        foreach ($rows as $row) {
            $publicId = generate_unique_public_id($pdo, $table, 'public_id', $prefix);
            $stmt = $pdo->prepare("UPDATE `$table` SET public_id = ? WHERE id = ?");
            $stmt->execute([$publicId, $row['id']]);
        }
        echo count($rows) . " row(s) backfilled.\n";
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
