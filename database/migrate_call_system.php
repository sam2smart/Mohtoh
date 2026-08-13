<?php
// database/migrate_call_system.php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting call system migration...\n";

    // Create call_sessions table
    echo "Creating call_sessions table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS call_sessions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            call_id VARCHAR(100) NOT NULL UNIQUE,
            initiator_id INT NOT NULL,
            recipient_id INT NOT NULL,
            status ENUM('ringing', 'active', 'rejected', 'ended') NOT NULL DEFAULT 'ringing',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ended_at TIMESTAMP NULL,
            duration_seconds INT DEFAULT 0,
            FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_call_id (call_id),
            INDEX idx_status (status)
        )
    ");

    // Add columns to system_settings if they don't exist
    echo "Updating system_settings for fare configuration...\n";
    $settingKeys = [
        'base_fare' => '2.50',
        'price_per_km' => '1.20',
        'minimum_fare' => '5.00'
    ];

    foreach ($settingKeys as $key => $defaultValue) {
        $stmt = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = setting_value
        ");
        $stmt->execute([$key, $defaultValue]);
    }

    echo "Call system migration completed successfully!\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
