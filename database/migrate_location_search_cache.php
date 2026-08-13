<?php
// database/migrate_location_search_cache.php
// Caches the external (Nominatim) half of the hybrid location search so
// popular/repeated queries ("Lagos", "Airport", ...) don't re-hit the
// external geocoder every time. Keyed by a hash of the normalized query
// text; hit_count doubles as a cheap "popular searches" signal.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Creating location_search_cache table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS location_search_cache (
            id INT AUTO_INCREMENT PRIMARY KEY,
            query_hash CHAR(40) NOT NULL,
            query_text VARCHAR(255) NOT NULL,
            results_json LONGTEXT NOT NULL,
            hit_count INT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_query_hash (query_hash)
        )
    ");

    echo "Location search cache migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
