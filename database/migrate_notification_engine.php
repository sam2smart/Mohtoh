<?php
// database/migrate_notification_engine.php
// MohToh Intelligent Notification & Messaging Engine — upgrades the
// existing minimal `notifications` table (id, user_id, message, link,
// is_read, created_at, public_id) into the full schema the centralized
// Notification Service (includes/functions.php: create_notification())
// needs, without touching or renaming any existing column. `message`
// and `link` stay exactly as they are — every one of the ~13 existing
// notify_user() call sites, and api/notifications.php's SELECTs, keep
// working completely unchanged. Safe to re-run (same additive,
// self-healing pattern as every other database/migrate_*.php here).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function add_column_if_missing($pdo, $table, $column, $definition) {
    $check = $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
        echo "Added {$table}.{$column}\n";
    } else {
        echo "{$table}.{$column} already exists.\n";
    }
}

function add_index_if_missing($pdo, $table, $indexName, $definition) {
    $check = $pdo->query("SHOW INDEX FROM {$table} WHERE Key_name = '{$indexName}'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
        echo "Added index {$indexName} on {$table}\n";
    } else {
        echo "Index {$indexName} on {$table} already exists.\n";
    }
}

try {
    echo "=== notifications: rich schema columns ===\n";
    add_column_if_missing($pdo, 'notifications', 'user_type', "user_type ENUM('driver','rider','admin') NULL AFTER user_id");
    add_column_if_missing($pdo, 'notifications', 'title', "title VARCHAR(150) NULL AFTER message");
    add_column_if_missing($pdo, 'notifications', 'notification_type', "notification_type VARCHAR(30) NOT NULL DEFAULT 'system' AFTER title");
    add_column_if_missing($pdo, 'notifications', 'priority', "priority ENUM('critical','high','normal','low') NOT NULL DEFAULT 'normal' AFTER notification_type");
    add_column_if_missing($pdo, 'notifications', 'icon', "icon VARCHAR(50) NULL AFTER priority");
    add_column_if_missing($pdo, 'notifications', 'color', "color VARCHAR(20) NULL AFTER icon");
    add_column_if_missing($pdo, 'notifications', 'action', "action VARCHAR(50) NULL AFTER link");
    add_column_if_missing($pdo, 'notifications', 'action_id', "action_id INT NULL AFTER action");
    add_column_if_missing($pdo, 'notifications', 'action_url', "action_url VARCHAR(255) NULL AFTER action_id");
    add_column_if_missing($pdo, 'notifications', 'related_table', "related_table VARCHAR(50) NULL AFTER action_url");
    add_column_if_missing($pdo, 'notifications', 'related_public_id', "related_public_id VARCHAR(30) NULL AFTER related_table");
    add_column_if_missing($pdo, 'notifications', 'read_at', "read_at TIMESTAMP NULL AFTER is_read");
    add_column_if_missing($pdo, 'notifications', 'is_deleted', "is_deleted TINYINT(1) NOT NULL DEFAULT 0 AFTER read_at");
    add_column_if_missing($pdo, 'notifications', 'is_archived', "is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_deleted");
    add_column_if_missing($pdo, 'notifications', 'created_by', "created_by INT NULL AFTER is_archived");
    add_column_if_missing($pdo, 'notifications', 'meta', "meta TEXT NULL AFTER created_by");
    add_column_if_missing($pdo, 'notifications', 'delivery_method', "delivery_method VARCHAR(100) NOT NULL DEFAULT 'database,in_app' AFTER meta");
    add_column_if_missing($pdo, 'notifications', 'status', "status ENUM('pending','sent','delivered','failed') NOT NULL DEFAULT 'sent' AFTER delivery_method");
    add_column_if_missing($pdo, 'notifications', 'expires_at', "expires_at TIMESTAMP NULL AFTER status");

    echo "\n=== notifications: indexes ===\n";
    add_index_if_missing($pdo, 'notifications', 'idx_user_unread', 'INDEX idx_user_unread (user_id, is_read, is_deleted)');
    add_index_if_missing($pdo, 'notifications', 'idx_notification_type', 'INDEX idx_notification_type (notification_type)');
    add_index_if_missing($pdo, 'notifications', 'idx_created_at', 'INDEX idx_created_at (created_at)');
    add_index_if_missing($pdo, 'notifications', 'idx_priority', 'INDEX idx_priority (priority)');

    echo "\n=== notifications: backfill user_type on existing rows ===\n";
    $updated = $pdo->exec("
        UPDATE notifications n
        JOIN users u ON u.id = n.user_id
        SET n.user_type = u.user_type
        WHERE n.user_type IS NULL
    ");
    echo "Backfilled user_type on {$updated} existing row(s).\n";

    echo "\n=== notification_broadcasts: admin broadcast jobs ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notification_broadcasts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            admin_id INT NOT NULL,
            target_type ENUM('single','multiple','all_drivers','all_riders','everyone') NOT NULL,
            target_user_ids TEXT NULL,
            title VARCHAR(150) NULL,
            message TEXT NOT NULL,
            notification_type VARCHAR(30) NOT NULL DEFAULT 'admin',
            priority ENUM('critical','high','normal','low') NOT NULL DEFAULT 'normal',
            recipient_count INT NOT NULL DEFAULT 0,
            scheduled_at TIMESTAMP NULL,
            sent_at TIMESTAMP NULL,
            status ENUM('scheduled','sent','failed') NOT NULL DEFAULT 'sent',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (public_id),
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    echo "Ready.\n";

    echo "\nNotification Engine migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
