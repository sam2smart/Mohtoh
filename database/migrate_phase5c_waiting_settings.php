<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 5C: Making waiting_settings vehicle-type aware...\n";

    $pdo->exec("ALTER TABLE waiting_settings ADD COLUMN vehicle_type ENUM('keke', 'car', 'bus') DEFAULT 'keke'");

    echo "Phase 5C: Waiting settings migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
