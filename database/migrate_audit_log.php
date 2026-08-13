<?php
// database/migrate_audit_log.php
// Generic cross-entity admin audit log. admin_user_actions (see
// database/migrate_*.php history) already logs actions taken against a
// specific USER (suspend/warn/etc) — it has no entity_type/entity_id/
// old_status/new_status columns because every row there is implicitly
// "against a user". This table is the generic version any approval
// workflow (vehicle, driver verification, premium request, ride report)
// writes to, via includes/audit.php's log_admin_action() — one shared
// function, not a copy of this INSERT at every call site.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== admin_audit_logs ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_audit_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            admin_user_id INT NOT NULL,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id INT NOT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (public_id),
            FOREIGN KEY (admin_user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM admin_audit_logs WHERE Key_name = 'idx_entity'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE admin_audit_logs ADD INDEX idx_entity (entity_type, entity_id)");
    }
    $indexCheck = $pdo->query("SHOW INDEX FROM admin_audit_logs WHERE Key_name = 'idx_created_at'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE admin_audit_logs ADD INDEX idx_created_at (created_at)");
    }
    echo "Ready.\n";
    echo "\nAudit log migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
