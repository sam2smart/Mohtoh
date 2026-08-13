<?php
// database/migrate_api_v1.php
// API-First Backend Transformation — mobile (Flutter) token auth and
// push-notification device registration. Purely additive: no existing
// table/column is touched, and the existing web session-based login
// (api/auth.php, $_SESSION) is completely unchanged — web and mobile
// authentication coexist side by side.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== user_tokens ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_tokens (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            device_type VARCHAR(20) NULL,
            device_name VARCHAR(150) NULL,
            last_used DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (token_hash),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM user_tokens WHERE Key_name = 'idx_user_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE user_tokens ADD INDEX idx_user_id (user_id)");
        echo "Added index idx_user_id.\n";
    }
    echo "Ready.\n";

    echo "\n=== user_devices ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS user_devices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            device_token VARCHAR(255) NOT NULL,
            platform VARCHAR(20) NOT NULL,
            last_active TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (device_token),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM user_devices WHERE Key_name = 'idx_user_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE user_devices ADD INDEX idx_user_id (user_id)");
        echo "Added index idx_user_id.\n";
    }
    echo "Ready.\n";

    echo "\nAPI v1 migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
