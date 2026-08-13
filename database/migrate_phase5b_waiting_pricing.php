<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 5B: Intelligent Waiting Pricing migration...\n";

    // Waiting events tracking
    $pdo->exec("CREATE TABLE IF NOT EXISTS waiting_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        trip_id INT NOT NULL,
        driver_id INT NOT NULL,
        passenger_id INT NOT NULL,
        start_time TIMESTAMP NULL,
        end_time TIMESTAMP NULL,
        duration_seconds INT DEFAULT 0,
        latitude DECIMAL(10, 8),
        longitude DECIMAL(11, 8),
        status ENUM('detected', 'active', 'completed') DEFAULT 'detected',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Waiting settings
    $pdo->exec("CREATE TABLE IF NOT EXISTS waiting_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        free_waiting_minutes INT DEFAULT 5,
        waiting_price_per_minute DECIMAL(10, 2) DEFAULT 10.00,
        minimum_wait_charge DECIMAL(10, 2) DEFAULT 0.00,
        maximum_wait_charge DECIMAL(10, 2) DEFAULT 500.00,
        enabled BOOLEAN DEFAULT 1
    )");

    // Initialize default settings
    $pdo->exec("INSERT IGNORE INTO waiting_settings (free_waiting_minutes, waiting_price_per_minute, minimum_wait_charge, maximum_wait_charge, enabled) VALUES (5, 10.00, 0.00, 500.00, 1)");

    echo "Phase 5B: Waiting Pricing tables created successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
