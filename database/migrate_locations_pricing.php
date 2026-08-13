<?php
// database/migrate_locations_pricing.php
// Adds the MohToh popular-locations table and the per-ride commission
// snapshot columns needed for the seat-locked, commission-aware
// pricing engine. Idempotent, like the other migrate_*.php scripts.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting locations + pricing migration...\n";

    echo "Creating locations table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            google_place_id VARCHAR(255) NULL,
            latitude DECIMAL(10,8) NOT NULL,
            longitude DECIMAL(11,8) NOT NULL,
            address VARCHAR(255) NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            type ENUM('bus_stop','junction','landmark','motor_park','popular_area') NOT NULL DEFAULT 'popular_area',
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_locations_status_type (status, type)
        )
    ");

    echo "Adding commission snapshot columns to ride_shares...\n";
    $columns = [
        'commission_percentage' => 'DECIMAL(5,2) NOT NULL DEFAULT 8.00 AFTER price_per_passenger',
        'commission_amount_per_seat' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER commission_percentage',
        'driver_earning_per_seat' => 'DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER commission_amount_per_seat',
    ];
    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE '$col'");
        if (!$check->fetch()) {
            echo "Adding column '$col' to ride_shares...\n";
            $pdo->exec("ALTER TABLE ride_shares ADD COLUMN $col $definition");
        }
    }

    echo "Seeding price_band_percent setting (driver flexibility band)...\n";
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('price_band_percent', '12.5')
        ON DUPLICATE KEY UPDATE setting_value = setting_value
    ");
    $stmt->execute();

    echo "Updating commission_percentage default to 8 (was 10, previously unused by any live code path)...\n";
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value)
        VALUES ('commission_percentage', '8')
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute();

    echo "Ensuring price_per_km has a realistic seed value (was falling back to a 1.20 default)...\n";
    $check = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'price_per_km'");
    if ($check->fetchColumn() === false) {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('price_per_km', '50.00')");
        $stmt->execute();
        echo "  -> seeded price_per_km = 50.00 (edit in Admin > Pricing if this isn't right)\n";
    } else {
        echo "  -> price_per_km already set, left unchanged\n";
    }

    // Pre-existing bug, unrelated to this feature but blocking it: the
    // start_ride/complete_ride actions have always tried to set
    // ride_shares.status = 'in_progress', but that value was never in
    // the ENUM (schema.sql, migrate.php and migrate_rideshare.php all
    // omit it). MySQL's default non-strict mode silently coerces the
    // invalid value to '' instead of erroring, so a ride could never
    // actually reach "in progress". Widening the enum to match what
    // the application has always assumed.
    echo "Widening ride_shares.status to include 'in_progress' (pre-existing gap; the app has always assumed it was there)...\n";
    $pdo->exec("ALTER TABLE ride_shares MODIFY COLUMN status ENUM('active','full','in_progress','cancelled','completed') NOT NULL DEFAULT 'active'");

    echo "Migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
