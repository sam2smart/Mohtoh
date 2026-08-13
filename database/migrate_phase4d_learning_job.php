<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 4D: Community Learning Job infrastructure migration...\n";

    // Track routes that need learning update
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_learning_queue (
        route_id INT PRIMARY KEY,
        status ENUM('pending', 'processing', 'failed') DEFAULT 'pending',
        attempts INT DEFAULT 0,
        last_error TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    // Log execution history
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_learning_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        routes_processed INT DEFAULT 0,
        trips_processed INT DEFAULT 0,
        duration_seconds DECIMAL(10, 2),
        status ENUM('success', 'failure') NOT NULL
    )");

    echo "Phase 4D: Learning job infrastructure tables created.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
