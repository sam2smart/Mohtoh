<?php
// database/migrate_matching_pricing.php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting matching and pricing system migrations...\n";

    // 1. Create pricing_configuration table
    echo "Creating pricing_configuration table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pricing_configuration (
            star_rating INT PRIMARY KEY,
            price_per_km DECIMAL(10, 2) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    // Seed default centralized price per kilometer rates (₦ or standard pricing units)
    echo "Seeding pricing_configuration...\n";
    $prices = [
        1 => 100.00,
        2 => 120.00,
        3 => 150.00,
        4 => 180.00,
        5 => 220.00
    ];
    foreach ($prices as $star => $price) {
        $stmt = $pdo->prepare("INSERT INTO pricing_configuration (star_rating, price_per_km) VALUES (?, ?) ON DUPLICATE KEY UPDATE price_per_km = ?");
        $stmt->execute([$star, $price, $price]);
    }

    // 2. Create vehicles table
    echo "Creating vehicles table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL UNIQUE,
            make VARCHAR(100) NOT NULL,
            model VARCHAR(100) NOT NULL,
            year INT NOT NULL,
            color VARCHAR(50) NOT NULL,
            plate_number VARCHAR(50) NOT NULL,
            ac_available TINYINT(1) DEFAULT 1,
            cleanliness VARCHAR(50) DEFAULT 'Good',
            comfort_level VARCHAR(50) DEFAULT 'Standard',
            seats INT DEFAULT 4,
            luggage_capacity VARCHAR(50) DEFAULT 'Standard',
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            exterior_condition INT DEFAULT 3,
            interior_condition INT DEFAULT 3,
            ac_condition INT DEFAULT 3,
            comfort_rating INT DEFAULT 3,
            cleanliness_rating INT DEFAULT 3,
            appearance_rating INT DEFAULT 3,
            safety_rating INT DEFAULT 3,
            overall_grade INT DEFAULT 3,
            admin_rating INT DEFAULT 3,
            review_remarks TEXT DEFAULT NULL,
            last_reviewed_at TIMESTAMP NULL DEFAULT NULL,
            edit_request_status ENUM('none', 'pending', 'approved', 'rejected') DEFAULT 'none',
            service_category VARCHAR(50) DEFAULT 'Standard',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // 3. Create vehicle_photos table
    echo "Creating vehicle_photos table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_photos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            photo_path VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )
    ");

    // 4. Create vehicle_verifications table
    echo "Creating vehicle_verifications table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            document_name VARCHAR(100) NOT NULL,
            document_path VARCHAR(255) NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            remarks TEXT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
        )
    ");

    // 5. Create vehicle_edit_requests table
    echo "Creating vehicle_edit_requests table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_edit_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            requested_changes TEXT NOT NULL,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            admin_remarks TEXT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // 6. Create vehicle_review_logs table
    echo "Creating vehicle_review_logs table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vehicle_review_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            admin_id INT NOT NULL,
            action ENUM('approved', 'rejected', 'edit_approved', 'edit_rejected') NOT NULL,
            remarks TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // 7. Create driver_ratings table (passenger rates driver)
    echo "Creating driver_ratings table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            driver_id INT NOT NULL,
            rider_id INT NOT NULL,
            rating INT NOT NULL,
            driving_quality INT NOT NULL,
            professionalism INT NOT NULL,
            cleanliness INT NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    // 6. Create passenger_ratings table (driver rates passenger)
    echo "Creating passenger_ratings table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS passenger_ratings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            request_id INT NOT NULL,
            rider_id INT NOT NULL,
            driver_id INT NOT NULL,
            rating INT NOT NULL,
            punctuality INT NOT NULL,
            behavior INT NOT NULL,
            communication INT NOT NULL,
            comment TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");

    echo "Migrations completed successfully!\n";

} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
