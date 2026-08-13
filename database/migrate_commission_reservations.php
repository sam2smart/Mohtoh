<?php
// database/migrate_commission_reservations.php
// Driver Wallet Commission Reservation System. Adds ride_commission_reservations
// — a logical hold on top of users.wallet_balance, not a new money store.
// credit_wallet()/debit_wallet() remain the only functions that ever move
// real wallet money; this table (plus new wallet_transactions categories,
// logged without touching wallet_balance) exists purely to track what
// portion of a driver's balance is currently promised to MohToh commission
// on active trips. Safe to re-run, same self-healing pattern as every
// other database/migrate_*.php in this project.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== ride_commission_reservations ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ride_commission_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            ride_share_id INT NOT NULL,
            booking_id INT NULL,
            reserved_amount DECIMAL(10,2) NOT NULL,
            used_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            released_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            status ENUM('active', 'settled', 'cancelled') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            released_at TIMESTAMP NULL,
            metadata TEXT NULL,
            public_id VARCHAR(30) NULL,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    // Deliberately no FK on ride_share_id: reservation rows are financial
    // records that must survive admin's remove_ride_share hard-deleting
    // the ride row (release_ride_reservation() runs first, but the row
    // itself is kept for audit history, not cascade-deleted with the ride).
    $indexCheck = $pdo->query("SHOW INDEX FROM ride_commission_reservations WHERE Key_name = 'ride_share_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE ride_commission_reservations ADD INDEX (ride_share_id)");
    }
    $indexCheck = $pdo->query("SHOW INDEX FROM ride_commission_reservations WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE ride_commission_reservations ADD UNIQUE (public_id)");
    }
    echo "Ready.\n";

    // wallet_transactions.category is a hard ENUM (same class of bug
    // already fixed once this session for admin_user_actions.action) —
    // the 5 new reservation categories (COMMISSION_RESERVED, etc.) were
    // being silently truncated to '' on insert under non-strict SQL mode.
    // Widen it exactly the same way, rather than fight the ENUM forever.
    echo "\n=== wallet_transactions.category ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE 'category'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE wallet_transactions MODIFY category VARCHAR(50) NULL");
        echo "Converted from ENUM to VARCHAR(50).\n";
    } else {
        echo "Already VARCHAR.\n";
    }

    echo "\nCommission reservation migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
