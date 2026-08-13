<?php
// database/migrate_referral_system.php
// Schema for MohToh Community Rewards (referral growth engine).
// Reuses users.referral_code/referred_by, both already added (empty,
// unwired) by database/migrate_pricing_center.php — widens the code
// column and adds the FK that migration never added, rather than
// recreating either column. Safe to re-run: every step self-heals via
// SHOW COLUMNS/SHOW TABLES checks, same pattern as every other
// migration this session.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function rs_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}
function rs_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== users.referral_code (widen) ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'referral_code'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'varchar(20)') === 0) {
        $pdo->exec("ALTER TABLE users MODIFY referral_code VARCHAR(30) NULL");
        echo "Widened VARCHAR(20) -> VARCHAR(30).\n";
    } else {
        echo "Already wide enough (" . ($col['Type'] ?? 'missing') . ").\n";
    }

    echo "\n=== users.referred_by (add FK) ===\n";
    $stmt = $pdo->query("
        SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'referred_by' AND REFERENCED_TABLE_NAME IS NOT NULL
    ");
    if (!$stmt->fetchColumn()) {
        $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_referred_by FOREIGN KEY (referred_by) REFERENCES users(id) ON DELETE SET NULL");
        echo "Added FK.\n";
    } else {
        echo "FK already exists.\n";
    }

    echo "\n=== referrals ===\n";
    if (!rs_table_exists($pdo, 'referrals')) {
        $pdo->exec("
            CREATE TABLE referrals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                referrer_user_id INT NOT NULL,
                referred_user_id INT NOT NULL,
                referral_type ENUM('RIDER','DRIVER') NOT NULL,
                status ENUM('REGISTERED','VERIFIED','QUALIFIED','REWARDED','CANCELLED') NOT NULL DEFAULT 'REGISTERED',
                registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                verified_at TIMESTAMP NULL,
                qualified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_referred_user (referred_user_id),
                FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_referrer (referrer_user_id),
                INDEX idx_referred (referred_user_id),
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== referral_events ===\n";
    if (!rs_table_exists($pdo, 'referral_events')) {
        $pdo->exec("
            CREATE TABLE referral_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                referral_id INT NOT NULL,
                event_type ENUM('REGISTERED','PHONE_VERIFIED','EMAIL_VERIFIED','FIRST_TRIP_COMPLETED','FIRST_RIDE_CREATED','DRIVER_VERIFIED','REWARD_RELEASED') NOT NULL,
                metadata TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
                INDEX idx_referral (referral_id)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== referral_rewards ===\n";
    if (!rs_table_exists($pdo, 'referral_rewards')) {
        $pdo->exec("
            CREATE TABLE referral_rewards (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                referral_id INT NOT NULL,
                reward_type VARCHAR(30) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                status ENUM('PENDING','APPROVED','PAID','CANCELLED') NOT NULL DEFAULT 'PENDING',
                wallet_transaction_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referral_id) REFERENCES referrals(id) ON DELETE CASCADE,
                INDEX idx_user (user_id),
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== users.notify_referrals ===\n";
    if (!rs_column_exists($pdo, 'users', 'notify_referrals')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN notify_referrals TINYINT(1) NOT NULL DEFAULT 1");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    // Backfill referral codes for every existing user who predates this
    // feature — Phase 7/8 UX assumes every rider/driver has one.
    echo "\n=== users.referral_code backfill ===\n";
    require_once __DIR__ . '/../includes/referral.php';
    $rows = $pdo->query("SELECT id, fullname FROM users WHERE referral_code IS NULL AND user_type IN ('rider','driver')")->fetchAll();
    foreach ($rows as $row) {
        $code = generate_referral_code($pdo, $row['fullname']);
        $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $row['id']]);
    }
    echo count($rows) . " user(s) backfilled.\n";

    // Backfill public_id on any pre-existing rows.
    foreach (['referrals' => 'REF', 'referral_rewards' => 'RRWD'] as $table => $prefix) {
        echo "\n=== $table.public_id backfill ===\n";
        $rows = $pdo->query("SELECT id FROM `$table` WHERE public_id IS NULL")->fetchAll();
        foreach ($rows as $row) {
            $publicId = generate_unique_public_id($pdo, $table, 'public_id', $prefix);
            $pdo->prepare("UPDATE `$table` SET public_id = ? WHERE id = ?")->execute([$publicId, $row['id']]);
        }
        echo count($rows) . " row(s) backfilled.\n";
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
