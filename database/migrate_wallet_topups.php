<?php
// database/migrate_wallet_topups.php
// Paystack integration: wallet_topups tracks a gateway transaction's
// own lifecycle (pending -> confirmed/failed) BEFORE any money moves.
// It never duplicates wallet_transactions — once verified, the normal
// credit_wallet() engine (includes/functions.php) is the only thing
// that ever credits a balance or writes a ledger row.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting wallet_topups migration...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS wallet_topups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            user_id INT NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            gateway ENUM('paystack') NOT NULL DEFAULT 'paystack',
            paystack_transaction_id VARCHAR(50) NULL,
            channel VARCHAR(30) NULL,
            status ENUM('PAYMENT_PENDING','PAYMENT_CONFIRMED','PAYMENT_FAILED') NOT NULL DEFAULT 'PAYMENT_PENDING',
            raw_response JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            verified_at TIMESTAMP NULL,
            UNIQUE KEY uniq_public_id (public_id),
            INDEX idx_user (user_id),
            INDEX idx_status (status)
        )
    ");

    echo "wallet_topups migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
