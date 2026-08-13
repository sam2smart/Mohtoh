<?php
// database/migrate_ride_pricing_mode_snapshot.php
// Intelligent Dynamic Pricing Experience: ride_shares already snapshots
// commission_percentage and fare_breakdown at creation time, so a
// published ride's price stays historically accurate even if admin
// settings change later. pricing_mode needs the same treatment — the
// rider-facing "System/Hybrid/Driver Price" badge is per-ride, and a
// ride created under one mode must keep showing that mode even if the
// admin later switches the global setting.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting ride pricing_mode snapshot migration...\n";

    $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE 'pricing_mode'");
    if (!$check->fetch()) {
        echo "Adding pricing_mode column to ride_shares...\n";
        $pdo->exec("ALTER TABLE ride_shares ADD COLUMN pricing_mode ENUM('system','driver','hybrid') NOT NULL DEFAULT 'hybrid' AFTER price_per_passenger");
    } else {
        echo "pricing_mode already exists, skipping.\n";
    }

    echo "Ride pricing_mode snapshot migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
