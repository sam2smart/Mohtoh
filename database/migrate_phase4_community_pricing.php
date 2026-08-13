<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 4: Community Keke Pricing Intelligence migration...\n";

    // Community route fares definition (The final recommendation)
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_route_fares (
        id INT AUTO_INCREMENT PRIMARY KEY,
        community_name VARCHAR(100) NOT NULL,
        route_name VARCHAR(255) NOT NULL,
        vehicle_type ENUM('keke', 'car', 'bus') NOT NULL,
        starting_point VARCHAR(255) NOT NULL,
        destination VARCHAR(255) NOT NULL,
        recommended_fare DECIMAL(10, 2) NOT NULL,
        minimum_fare DECIMAL(10, 2) NOT NULL,
        maximum_fare DECIMAL(10, 2) NOT NULL,
        status ENUM('active', 'pending', 'disabled') NOT NULL DEFAULT 'pending',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // Driver fare suggestions
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_fare_suggestions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        driver_id INT NOT NULL,
        community_name VARCHAR(100) NOT NULL,
        route_name VARCHAR(255) NOT NULL,
        suggested_fare DECIMAL(10, 2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES users(id)
    )");

    // Passenger feedback for completed trips
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_trip_feedback (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trip_id INT NOT NULL,
        route_name VARCHAR(255) NOT NULL,
        vehicle_type ENUM('keke', 'car', 'bus') NOT NULL,
        fare_paid DECIMAL(10, 2) NOT NULL,
        passenger_feedback ENUM('fair', 'high', 'low') NOT NULL,
        suggested_fare DECIMAL(10, 2) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (trip_id) REFERENCES ride_shares(id)
    )");

    echo "Phase 4: Community Keke Pricing foundation migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
