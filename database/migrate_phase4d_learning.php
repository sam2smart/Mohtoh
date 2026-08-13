<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 4D: Community Pricing Learning & Analytics migration...\n";

    // Caching for computed analytics
    $pdo->exec("CREATE TABLE IF NOT EXISTS community_learning_stats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        avg_fare DECIMAL(10, 2),
        demand_score ENUM('low', 'medium', 'high', 'very_high'),
        confidence_score INT,
        last_updated TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (route_id) REFERENCES community_route_fares(id)
    )");

    echo "Phase 4D: Learning stats table created.\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
