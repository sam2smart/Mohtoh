<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting vehicle review workflow migration...\n";

    echo "Adding grading and review fields to vehicles table...\n";
    $pdo->exec(
        "ALTER TABLE vehicles
            ADD COLUMN exterior_condition INT NOT NULL DEFAULT 3,
            ADD COLUMN interior_condition INT NOT NULL DEFAULT 3,
            ADD COLUMN ac_condition INT NOT NULL DEFAULT 3,
            ADD COLUMN comfort_rating INT NOT NULL DEFAULT 3,
            ADD COLUMN cleanliness_rating INT NOT NULL DEFAULT 3,
            ADD COLUMN appearance_rating INT NOT NULL DEFAULT 3,
            ADD COLUMN safety_rating INT NOT NULL DEFAULT 3,
            ADD COLUMN overall_grade INT NOT NULL DEFAULT 3,
            ADD COLUMN review_remarks TEXT DEFAULT NULL,
            ADD COLUMN last_reviewed_at TIMESTAMP NULL DEFAULT NULL,
            ADD COLUMN edit_request_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
            ADD COLUMN service_category VARCHAR(50) NOT NULL DEFAULT 'Standard'"
    );

    echo "Creating vehicle_edit_requests table...\n";
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vehicle_edit_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            driver_id INT NOT NULL,
            requested_changes TEXT NOT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            admin_remarks TEXT DEFAULT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    echo "Creating vehicle_review_logs table...\n";
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS vehicle_review_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            vehicle_id INT NOT NULL,
            admin_id INT NOT NULL,
            action ENUM('approved','rejected','edit_approved','edit_rejected') NOT NULL,
            remarks TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )"
    );

    echo "Vehicle review workflow migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
