<?php
// database/migrate_saved_locations.php
// Rider "Saved locations" (Home, Work, etc.) — distinct from the
// `locations` table, which holds admin-curated, shared points (bus
// stops, junctions) rather than a rider's own private favorites.
// Safe to re-run: CREATE TABLE IF NOT EXISTS, matching the pattern used
// by migrate_financial_system.php's `payments` table.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "Creating saved_locations table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS saved_locations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            label VARCHAR(50) NOT NULL,
            address VARCHAR(255) NOT NULL,
            latitude DECIMAL(10,8) NOT NULL,
            longitude DECIMAL(11,8) NOT NULL,
            public_id VARCHAR(30) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )
    ");

    $check = $pdo->query("SHOW COLUMNS FROM saved_locations LIKE 'public_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE saved_locations ADD COLUMN public_id VARCHAR(30) NULL");
    }

    $stmt = $pdo->query("SELECT id FROM saved_locations WHERE public_id IS NULL OR public_id = ''");
    foreach ($stmt->fetchAll() as $row) {
        $publicId = generate_unique_public_id($pdo, 'saved_locations', 'public_id', 'SVL');
        $pdo->prepare("UPDATE saved_locations SET public_id = ? WHERE id = ?")->execute([$publicId, $row['id']]);
    }

    $indexCheck = $pdo->query("SHOW INDEX FROM saved_locations WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE saved_locations ADD UNIQUE (public_id)");
    }

    echo "Saved locations migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
