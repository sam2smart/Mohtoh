<?php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting Phase 1: Vehicle Verification Architecture migration...\n";

    echo "Adding passenger_capacity and verification_status to vehicles table...\n";
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN passenger_capacity INT DEFAULT 4");
    $pdo->exec("ALTER TABLE vehicles ADD COLUMN verification_status ENUM('pending', 'verified', 'rejected', 'suspended') DEFAULT 'pending'");

    echo "Migrating existing approved vehicles to verified status...\n";
    $pdo->exec("UPDATE vehicles SET verification_status = 'verified' WHERE status = 'approved'");

    echo "Phase 1: Vehicle Verification Architecture migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
