<?php
// database/migrate_ride_requests.php
// Ride Demand System Restructure — SYSTEM 1: Ride Requests. A passenger
// describes a trip they need ("Redemption Camp to Ikeja tomorrow 7AM")
// when no matching ride currently exists; drivers discover this demand
// and create supply against it. This is a completely separate concept
// from the Ride Queue (ride_waitlists — "a specific ride is already
// full, notify me when a seat opens") and must never be merged with it.
// Same self-healing, safe-to-re-run pattern as every other
// database/migrate_*.php in this project.
//
// Table name: a DIFFERENT, pre-existing `ride_requests` table already
// backs the instant/on-demand ride-hailing flow (rider_id/driver_id/
// pickup_lat/status enum — see api/request-ride.php, api/respond-ride.php,
// api/accept-offer.php, api/rate-driver.php, api/rate-passenger.php,
// api/admin-api.php). To avoid colliding with that active, unrelated
// feature, this one is named `ride_demand_requests` instead.
//
// No FK on matched_ride_id — same reasoning as ride_waitlists.ride_share_id
// (see database/migrate_waitlist.php): admin's remove_ride_share
// hard-deletes ride_shares rows, and a fulfilled/matched request should
// survive that as demand-history rather than being silently wiped by a
// cascade or blocked by a dangling FK. user_id DOES get a FK — a
// request has no meaning once the user account is gone.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== ride_demand_requests ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ride_demand_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            user_id INT NOT NULL,
            pickup_location VARCHAR(255) NOT NULL,
            pickup_latitude DECIMAL(10,8) NULL,
            pickup_longitude DECIMAL(11,8) NULL,
            destination VARCHAR(255) NOT NULL,
            destination_latitude DECIMAL(10,8) NULL,
            destination_longitude DECIMAL(11,8) NULL,
            travel_date DATE NOT NULL,
            travel_time TIME NULL,
            passenger_count INT NOT NULL DEFAULT 1,
            vehicle_preference VARCHAR(50) NULL,
            notes TEXT NULL,
            status ENUM('OPEN', 'MATCHED', 'FULFILLED', 'EXPIRED', 'CANCELLED') NOT NULL DEFAULT 'OPEN',
            matched_ride_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE (public_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    $indexes = [
        'idx_user_id' => 'user_id',
        'idx_status' => 'status',
        'idx_travel_date' => 'travel_date',
        'idx_pickup' => 'pickup_location',
        'idx_destination' => 'destination',
    ];
    foreach ($indexes as $name => $column) {
        $indexCheck = $pdo->query("SHOW INDEX FROM ride_demand_requests WHERE Key_name = '{$name}'");
        if (!$indexCheck->fetch()) {
            $pdo->exec("ALTER TABLE ride_demand_requests ADD INDEX {$name} ({$column})");
            echo "Added index {$name}.\n";
        }
    }
    echo "Ready.\n";

    echo "\nRide Requests migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
