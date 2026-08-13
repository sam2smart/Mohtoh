<?php
// database/migrate_integration_settings.php
// Schema for the Service Integration Center: admin-editable settings for
// every external service (email, SMS, OTP, payments, maps, push, WhatsApp,
// feature toggles, registration policy), an audit trail for changes, and
// a generic event log for channels without an existing log table
// (verification_events already covers OTP/email verification and is
// reused as-is, not duplicated). Safe to re-run: every step self-heals
// via SHOW TABLES/SHOW COLUMNS checks, same pattern as
// migrate_pricing_center.php.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function it_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== integration_settings ===\n";
    if (!it_table_exists($pdo, 'integration_settings')) {
        $pdo->exec("
            CREATE TABLE integration_settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT NULL,
                is_secret TINYINT(1) NOT NULL DEFAULT 0,
                updated_by_admin_id INT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (updated_by_admin_id) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== integration_change_logs ===\n";
    if (!it_table_exists($pdo, 'integration_change_logs')) {
        $pdo->exec("
            CREATE TABLE integration_change_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                admin_id INT NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                old_value TEXT NULL,
                new_value TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_setting_key (setting_key),
                INDEX idx_created_at (created_at)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== integration_logs ===\n";
    if (!it_table_exists($pdo, 'integration_logs')) {
        $pdo->exec("
            CREATE TABLE integration_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                channel VARCHAR(30) NOT NULL,
                event VARCHAR(100) NOT NULL,
                status VARCHAR(20) NOT NULL,
                message TEXT NULL,
                meta TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_channel (channel),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    // Backfill public_id on any pre-existing rows in the two log tables
    // (mirrors migrate_auth_verification.php's verification_events backfill).
    foreach (['integration_change_logs' => 'INTG', 'integration_logs' => 'INTL'] as $table => $prefix) {
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
