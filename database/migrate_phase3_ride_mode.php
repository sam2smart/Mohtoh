<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 3: Add ride_mode to ride_shares...\n";

    $pdo->exec("ALTER TABLE ride_shares ADD COLUMN ride_mode ENUM('shared', 'private') NOT NULL DEFAULT 'shared'");

    echo "Phase 3: Ride mode migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
