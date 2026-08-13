<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 5A: Private Ride Pricing migration...\n";

    // Track if vehicle is reserved for a private ride
    $pdo->exec("ALTER TABLE ride_shares ADD COLUMN vehicle_reserved BOOLEAN DEFAULT 0");

    // Add private ride settings to system_settings
    $settings = [
        ['private_ride_enabled', '1'],
        ['private_pricing_multiplier', '1.20'],
        ['max_reservation_distance', '50'],
        ['private_booking_fee', '100']
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $setting) {
        $stmt->execute($setting);
    }

    echo "Phase 5A: Migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
