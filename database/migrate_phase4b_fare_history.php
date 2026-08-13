<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 4B: Community Keke Pricing History migration...\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS community_fare_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        route_id INT NOT NULL,
        old_fare DECIMAL(10, 2) NOT NULL,
        new_fare DECIMAL(10, 2) NOT NULL,
        changed_by INT NOT NULL,
        reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (route_id) REFERENCES community_route_fares(id),
        FOREIGN KEY (changed_by) REFERENCES users(id)
    )");

    echo "Phase 4B: Community Keke Pricing history migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
