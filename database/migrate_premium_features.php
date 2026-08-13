<?php
// database/migrate_premium_features.php
// Part 2 — Driver Premium Features. Two tables: driver_premium_features
// (the admin-managed catalog — what premium features exist, at what
// price) and driver_premium_requests (a driver's request to buy one,
// carrying the pending/approved/rejected/disabled lifecycle Part 5
// requires). Feature access itself is gated off the EXISTING
// users.driver_tier ENUM('standard','premium') — already used
// elsewhere in this app (api/admin-api.php's set_driver_tier action) —
// not a new, second "is this driver premium" flag.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== driver_premium_features ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_premium_features (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            feature_name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration_days INT NOT NULL DEFAULT 30,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE (public_id)
        )
    ");
    echo "Ready.\n";

    echo "\n=== driver_premium_requests ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_premium_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            driver_id INT NOT NULL,
            feature_id INT NOT NULL,
            status ENUM('pending', 'approved', 'rejected', 'disabled') NOT NULL DEFAULT 'pending',
            reason TEXT NULL,
            reviewed_by INT NULL,
            reviewed_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE (public_id),
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (feature_id) REFERENCES driver_premium_features(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM driver_premium_requests WHERE Key_name = 'idx_driver_status'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE driver_premium_requests ADD INDEX idx_driver_status (driver_id, status)");
    }
    echo "Ready.\n";

    // Seed the example features named in the spec — safe to re-run
    // (INSERT IGNORE on the unique feature_name to avoid duplicates
    // on a second migration run).
    $indexCheck = $pdo->query("SHOW INDEX FROM driver_premium_features WHERE Key_name = 'idx_feature_name'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE driver_premium_features ADD UNIQUE INDEX idx_feature_name (feature_name)");
    }
    $seed = [
        ['Priority Ride Visibility', 'Your published rides are shown higher in rider search results.', 2500.00, 30],
        ['Featured Driver Profile', 'A highlighted profile badge riders see before booking.', 1500.00, 30],
        ['Advanced Earnings Report', 'Detailed breakdowns and trends beyond the standard earnings summary.', 1000.00, 30],
        ['Route Promotion', 'Boosted visibility for your most frequently published routes.', 2000.00, 30],
        ['More Ride Exposure', 'Your rides reach a wider radius of nearby riders when published.', 1800.00, 30],
    ];
    $stmt = $pdo->prepare("INSERT IGNORE INTO driver_premium_features (public_id, feature_name, description, price, duration_days) VALUES (?, ?, ?, ?, ?)");
    foreach ($seed as [$name, $desc, $price, $duration]) {
        $stmt->execute([generate_unique_public_id($pdo, 'driver_premium_features', 'public_id', 'PFT'), $name, $desc, $price, $duration]);
    }
    echo "Seeded " . count($seed) . " example features (skipped if already present).\n";

    echo "\nPremium features migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
