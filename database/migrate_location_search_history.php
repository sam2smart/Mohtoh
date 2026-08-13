<?php
require_once __DIR__ . '/../includes/db.php';

try {
    $pdo->exec("DROP TABLE IF EXISTS location_search_history");
    $pdo->exec("
        CREATE TABLE location_search_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            searched_location VARCHAR(255) NOT NULL,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            usage_count INT NOT NULL DEFAULT 1,
            last_used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY uq_user_location (user_id, searched_location),
            INDEX idx_last_used (last_used_at)
        )
    ");
    echo "Table 'location_search_history' recreated successfully with UNIQUE key.";
} catch (Exception $e) {
    echo "Error creating table: " . $e->getMessage();
}
