<?php
// database/migrate_join_ride_passenger_ratings.php
//
// STRICT JOIN RIDE FIX — Problem 5 (driver -> passenger rating).
//
// Join Ride already has a passenger->driver rating store (ride_share_ratings,
// booking-keyed). The reverse direction had no home: api/rate-passenger.php +
// the passenger_ratings table are hard-wired to Private Pickup's ride_requests
// (request_id NOT NULL) and cannot represent a Join Ride booking.
//
// This adds the symmetric twin of ride_share_ratings for the driver->passenger
// direction, keyed on the SAME Join Ride booking relationship (booking_id /
// ride_share_id). Private Pickup's passenger_ratings table is left completely
// untouched.
//
// Non-destructive, idempotent (CREATE TABLE IF NOT EXISTS) and backwards
// compatible: it only adds a new table, changing no existing schema or data.
// Safe to run repeatedly.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Creating ride_share_passenger_ratings table (Join Ride driver -> passenger)...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ride_share_passenger_ratings (
            id INT(11) NOT NULL AUTO_INCREMENT,
            ride_share_id INT(11) NOT NULL,
            booking_id INT(11) NOT NULL,
            driver_id INT(11) NOT NULL,
            rider_id INT(11) NOT NULL,
            rating INT(11) NOT NULL,
            comment TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            public_id VARCHAR(30) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_passenger_rating_per_booking (booking_id, driver_id),
            UNIQUE KEY public_id (public_id),
            KEY idx_rider (rider_id),
            KEY idx_ride_share (ride_share_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    echo "Done. ride_share_passenger_ratings is ready.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
