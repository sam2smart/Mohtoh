<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Creating ride sharing tables...\n";

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS ride_shares (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            pickup_location VARCHAR(255) NULL,
            start_location VARCHAR(255) NULL,
            destination VARCHAR(255) NOT NULL,
            departure_date DATE NOT NULL,
            departure_time TIME NOT NULL,
            vehicle_id INT NULL,
            vehicle_type VARCHAR(100) NOT NULL,
            vehicle_info VARCHAR(255) NOT NULL,
            total_seats INT NOT NULL DEFAULT 1,
            available_seats INT NOT NULL DEFAULT 1,
            pickup_lat DECIMAL(10, 8) NULL,
            pickup_lng DECIMAL(11, 8) NULL,
            destination_lat DECIMAL(10, 8) NULL,
            destination_lng DECIMAL(11, 8) NULL,
            price_per_passenger DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            description TEXT DEFAULT NULL,
            status ENUM('active', 'full', 'cancelled', 'completed') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_rideshare_status_date (status, departure_date, departure_time)
        )
SQL);

    $columnsToAdd = [
        'pickup_location' => 'VARCHAR(255) NULL AFTER driver_id',
        'start_location' => 'VARCHAR(255) NULL AFTER pickup_location',
        'vehicle_id' => 'INT NULL AFTER departure_time',
        'total_seats' => 'INT NOT NULL DEFAULT 1 AFTER vehicle_info',
        'available_seats' => 'INT NOT NULL DEFAULT 1 AFTER total_seats',
        'pickup_lat' => 'DECIMAL(10, 8) NULL AFTER available_seats',
        'pickup_lng' => 'DECIMAL(11, 8) NULL AFTER pickup_lat',
        'destination_lat' => 'DECIMAL(10, 8) NULL AFTER pickup_lng',
        'destination_lng' => 'DECIMAL(11, 8) NULL AFTER destination_lat'
    ];

    foreach ($columnsToAdd as $column => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE '$column'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE ride_shares ADD COLUMN $column $definition");
        }
    }

    $pdo->exec("UPDATE ride_shares SET pickup_location = COALESCE(pickup_location, start_location), start_location = COALESCE(start_location, pickup_location), total_seats = COALESCE(total_seats, available_seats) WHERE pickup_location IS NULL OR start_location IS NULL OR total_seats IS NULL");

    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS ride_share_bookings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ride_share_id INT NOT NULL,
            rider_id INT NOT NULL,
            seats_booked INT NOT NULL DEFAULT 1,
            status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'confirmed',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_ride_share_booking (ride_share_id, rider_id),
            FOREIGN KEY (ride_share_id) REFERENCES ride_shares(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
        )
SQL);

    echo "Ride sharing tables created successfully.\n";
} catch (PDOException $e) {
    echo "Ride sharing migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
