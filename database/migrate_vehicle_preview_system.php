<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting vehicle preview & verification system migration...\n";

    echo "Adding vehicle_type to vehicles table...\n";
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN vehicle_type VARCHAR(50) NOT NULL DEFAULT 'car'");

    echo "Adding photo_type to vehicle_photos table...\n";
    $pdo->exec("ALTER TABLE vehicle_photos ADD COLUMN photo_type VARCHAR(50) NOT NULL DEFAULT 'other'");

    echo "Vehicle preview & verification system migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
