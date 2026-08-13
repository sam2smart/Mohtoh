<?php
// database/migrate_financial_system.php
// MohToh financial system foundation: a real wallet ledger (instead of
// balance-only updates), per-booking payment records with explicit
// PAYMENT_PENDING/CONFIRMED/FAILED states, and driver payment
// preference (wallet / direct / both). All new columns default to
// 'wallet', matching exactly how money moves today, so this is a
// zero-behavior-change migration for existing data.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting financial system migration...\n";

    echo "Adding payment_preference to users...\n";
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'payment_preference'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN payment_preference ENUM('wallet','direct','both') NOT NULL DEFAULT 'wallet'");
    }

    echo "Adding payment_preference snapshot to ride_shares...\n";
    $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE 'payment_preference'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE ride_shares ADD COLUMN payment_preference ENUM('wallet','direct','both') NOT NULL DEFAULT 'wallet'");
    }

    echo "Adding payment_method to ride_share_bookings...\n";
    $check = $pdo->query("SHOW COLUMNS FROM ride_share_bookings LIKE 'payment_method'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE ride_share_bookings ADD COLUMN payment_method ENUM('wallet','direct') NOT NULL DEFAULT 'wallet'");
    }

    echo "Extending wallet_transactions into a real ledger...\n";
    $columns = [
        'balance_after' => 'DECIMAL(10,2) NULL',
        'category' => "ENUM('topup','ride_payment','ride_refund','driver_earning','commission','admin_adjustment') NOT NULL DEFAULT 'admin_adjustment'",
        'ride_share_id' => 'INT NULL',
        'booking_id' => 'INT NULL',
        'status' => "ENUM('completed','pending','failed') NOT NULL DEFAULT 'completed'",
        'meta' => 'JSON NULL',
    ];
    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM wallet_transactions LIKE '$col'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE wallet_transactions ADD COLUMN $col $definition");
        }
    }

    echo "Backfilling category on existing wallet_transactions from their description...\n";
    $pdo->exec("UPDATE wallet_transactions SET category = 'driver_earning' WHERE category = 'admin_adjustment' AND description LIKE 'Earning from%'");
    $pdo->exec("UPDATE wallet_transactions SET category = 'ride_payment' WHERE category = 'admin_adjustment' AND description LIKE 'Payment for ride booking%'");
    $pdo->exec("UPDATE wallet_transactions SET category = 'ride_refund' WHERE category = 'admin_adjustment' AND description LIKE 'Refund for%'");
    $pdo->exec("UPDATE wallet_transactions SET category = 'topup' WHERE category = 'admin_adjustment' AND description LIKE '%funding%'");

    echo "Creating payments table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ride_share_id INT NOT NULL,
            booking_id INT NOT NULL,
            rider_id INT NOT NULL,
            driver_id INT NOT NULL,
            fare_amount DECIMAL(10,2) NOT NULL,
            commission_amount DECIMAL(10,2) NOT NULL,
            driver_earning_amount DECIMAL(10,2) NOT NULL,
            payment_method ENUM('wallet','direct') NOT NULL,
            status ENUM('PAYMENT_PENDING','PAYMENT_CONFIRMED','PAYMENT_FAILED') NOT NULL DEFAULT 'PAYMENT_PENDING',
            confirmed_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_booking (booking_id),
            INDEX idx_ride (ride_share_id),
            INDEX idx_driver_status (driver_id, status)
        )
    ");

    echo "Financial system migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
