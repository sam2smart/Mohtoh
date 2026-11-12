<?php
// database/migrate_mohtoh_network.php
// Schema for MOHTOH NETWORK — the direct-referral / revenue-share
// programme that REPLACES MohToh Community Rewards (includes/referral.php,
// tables `referrals`/`referral_events`/`referral_rewards`). Those legacy
// tables are left in place untouched (historical record — financial
// history is never deleted), and are backfilled into the new tables
// below for continuity. New registrations/rewards flow only through
// the tables created here from this point on.
//
// Idempotent: every step self-heals via SHOW TABLES/SHOW COLUMNS checks,
// same pattern as every other migrate_*.php in this project.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function mn_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}
function mn_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== users.is_network_partner ===\n";
    if (!mn_column_exists($pdo, 'users', 'is_network_partner')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_network_partner TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_partners ===\n";
    if (!mn_table_exists($pdo, 'network_partners')) {
        $pdo->exec("
            CREATE TABLE network_partners (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                referral_code VARCHAR(30) NOT NULL,
                status ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
                terms_accepted_at TIMESTAMP NULL,
                terms_version VARCHAR(20) NULL,
                approved_by INT NULL,
                approved_at TIMESTAMP NULL,
                suspended_by INT NULL,
                suspended_at TIMESTAMP NULL,
                suspension_reason TEXT NULL,
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user (user_id),
                UNIQUE KEY uniq_referral_code (referral_code),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (suspended_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_campaigns ===\n";
    if (!mn_table_exists($pdo, 'network_campaigns')) {
        $pdo->exec("
            CREATE TABLE network_campaigns (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                name VARCHAR(100) NOT NULL,
                description TEXT NULL,
                driver_reward_pct DECIMAL(5,2) NULL,
                passenger_reward_pct DECIMAL(5,2) NULL,
                combined_ceiling_pct DECIMAL(5,2) NULL,
                earning_days INT NULL,
                starts_at TIMESTAMP NULL,
                ends_at TIMESTAMP NULL,
                status ENUM('scheduled','active','ended','cancelled') NOT NULL DEFAULT 'scheduled',
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_window (starts_at, ends_at),
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_referrals ===\n";
    if (!mn_table_exists($pdo, 'network_referrals')) {
        $pdo->exec("
            CREATE TABLE network_referrals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                referrer_user_id INT NOT NULL,
                referred_user_id INT NOT NULL,
                referred_category ENUM('driver','passenger') NOT NULL,
                campaign_id INT NULL,
                status ENUM('registered','verified','active','expired','cancelled','legacy_migrated') NOT NULL DEFAULT 'registered',
                registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                phone_verified_at TIMESTAMP NULL,
                email_verified_at TIMESTAMP NULL,
                verified_at TIMESTAMP NULL,
                first_qualifying_ride_at TIMESTAMP NULL,
                earning_starts_at TIMESTAMP NULL,
                earning_expires_at TIMESTAMP NULL,
                qualifying_ride_count INT NOT NULL DEFAULT 0,
                total_commission_generated DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                total_rewards_generated DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                attribution_locked_at TIMESTAMP NULL,
                corrected_by INT NULL,
                correction_reason TEXT NULL,
                cancelled_reason TEXT NULL,
                registration_ip VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_referred_user (referred_user_id),
                FOREIGN KEY (referrer_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (referred_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (campaign_id) REFERENCES network_campaigns(id) ON DELETE SET NULL,
                FOREIGN KEY (corrected_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_referrer (referrer_user_id),
                INDEX idx_status (status),
                INDEX idx_earning_expiry (earning_expires_at)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
        if (!mn_column_exists($pdo, 'network_referrals', 'registration_ip')) {
            $pdo->exec("ALTER TABLE network_referrals ADD COLUMN registration_ip VARCHAR(45) NULL");
            echo "Added registration_ip.\n";
        }
    }

    echo "\n=== network_wallets ===\n";
    if (!mn_table_exists($pdo, 'network_wallets')) {
        $pdo->exec("
            CREATE TABLE network_wallets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                pending_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                available_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                withdrawn_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                reversed_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                suspended_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user (user_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_reward_ledger ===\n";
    if (!mn_table_exists($pdo, 'network_reward_ledger')) {
        $pdo->exec("
            CREATE TABLE network_reward_ledger (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                network_referral_id INT NOT NULL,
                booking_id INT NOT NULL,
                beneficiary_user_id INT NOT NULL,
                beneficiary_role ENUM('driver_referrer','passenger_referrer') NOT NULL,
                commission_base DECIMAL(10,2) NOT NULL,
                reward_pct DECIMAL(5,2) NOT NULL,
                reward_amount DECIMAL(10,2) NOT NULL,
                status ENUM('pending','available','withdrawn','reversed','suspended') NOT NULL DEFAULT 'pending',
                hold_until TIMESTAMP NULL,
                available_at TIMESTAMP NULL,
                reversed_at TIMESTAMP NULL,
                reversal_reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_booking_beneficiary (booking_id, beneficiary_user_id, beneficiary_role),
                FOREIGN KEY (network_referral_id) REFERENCES network_referrals(id) ON DELETE CASCADE,
                FOREIGN KEY (beneficiary_user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_beneficiary_status (beneficiary_user_id, status),
                INDEX idx_booking (booking_id),
                INDEX idx_hold_until (hold_until)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_withdrawal_accounts ===\n";
    if (!mn_table_exists($pdo, 'network_withdrawal_accounts')) {
        $pdo->exec("
            CREATE TABLE network_withdrawal_accounts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                bank_name VARCHAR(100) NOT NULL,
                account_number_encrypted TEXT NOT NULL,
                account_number_last4 VARCHAR(4) NOT NULL,
                account_name VARCHAR(120) NOT NULL,
                is_default TINYINT(1) NOT NULL DEFAULT 0,
                verified_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_user (user_id)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_withdrawals ===\n";
    if (!mn_table_exists($pdo, 'network_withdrawals')) {
        $pdo->exec("
            CREATE TABLE network_withdrawals (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                withdrawal_account_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                status ENUM('pending','under_review','approved','processing','paid','rejected','failed','reversed') NOT NULL DEFAULT 'pending',
                requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reviewed_by INT NULL,
                reviewed_at TIMESTAMP NULL,
                payment_reference VARCHAR(100) NULL,
                processed_by INT NULL,
                processed_at TIMESTAMP NULL,
                rejection_reason TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (withdrawal_account_id) REFERENCES network_withdrawal_accounts(id) ON DELETE RESTRICT,
                FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_user_status (user_id, status),
                INDEX idx_status (status)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_reward_adjustments ===\n";
    if (!mn_table_exists($pdo, 'network_reward_adjustments')) {
        $pdo->exec("
            CREATE TABLE network_reward_adjustments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                ledger_id INT NULL,
                user_id INT NOT NULL,
                admin_id INT NULL,
                adjustment_type ENUM('credit','debit','reverse') NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                reason TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (ledger_id) REFERENCES network_reward_ledger(id) ON DELETE SET NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_user (user_id)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== network_fraud_flags ===\n";
    if (!mn_table_exists($pdo, 'network_fraud_flags')) {
        $pdo->exec("
            CREATE TABLE network_fraud_flags (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                network_referral_id INT NULL,
                flag_type VARCHAR(50) NOT NULL,
                severity ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
                status ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
                details TEXT NULL,
                raised_by_admin_id INT NULL,
                resolved_by INT NULL,
                resolved_at TIMESTAMP NULL,
                resolution_notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (network_referral_id) REFERENCES network_referrals(id) ON DELETE SET NULL,
                FOREIGN KEY (raised_by_admin_id) REFERENCES users(id) ON DELETE SET NULL,
                FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_status (status),
                INDEX idx_user (user_id)
            )
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    // ---------------------------------------------------------------
    // Default programme settings — read through get_system_setting(),
    // same system_settings table every other admin-configurable area
    // (pricing, promotions, old referrals) already uses. Only seeded if
    // absent, so re-running this script never clobbers an admin's edits.
    // ---------------------------------------------------------------
    echo "\n=== network_* system_settings defaults ===\n";
    $defaults = [
        'network_enabled' => '1',
        'network_registrations_enabled' => '1',
        'network_links_enabled' => '1',
        'network_rewards_enabled' => '1',
        'network_withdrawals_enabled' => '1',
        'network_driver_referrals_enabled' => '1',
        'network_passenger_referrals_enabled' => '1',
        'network_external_partners_enabled' => '1',
        'network_maintenance_mode' => '0',
        'network_emergency_payout_suspended' => '0',
        'network_launch_at' => '',
        'network_closes_at' => '',
        'network_terms_version' => '1.0',
        'network_terms_text' => 'Participation in MOHTOH NETWORK is voluntary and free. There is no joining fee, and no payment is made simply for registering or recruiting others. Earnings arise only from qualifying transport transactions completed by people you directly referred, are limited to direct referrals only (no multi-level payouts), and are subject to a defined earning period, verification, and reversal for cancelled, refunded, or fraudulent transactions. No income is guaranteed. Programme rules may change prospectively.',
        'network_announcement' => '',
        'network_partner_auto_approve' => '0',
        'network_driver_reward_pct' => '15',
        'network_passenger_reward_pct' => '10',
        'network_combined_ceiling_pct' => '25',
        'network_earning_days' => '90',
        'network_min_withdrawal' => '2000',
        'network_registration_bonus' => '0',
        'network_multi_level_enabled' => '0', // never surfaced in UI; exists only so this is explicit, not implicit, in config
        'network_reward_hold_days' => '3',
        'network_total_budget' => '0',          // 0 = unlimited
        'network_daily_budget' => '0',
        'network_monthly_budget' => '0',
        'network_max_combined_payout_per_txn' => '0', // 0 = no extra per-txn cap beyond the ceiling %
        'network_max_withdrawal_per_day' => '0',
        'network_max_withdrawal_per_month' => '0',
        'network_fraud_velocity_max_per_day' => '10',  // flags a partner referring more than this many NEW people in 24h
        'network_fraud_same_pair_max_rewards' => '5',  // flags the same driver/rider pair generating this many rewarded bookings for one beneficiary
    ];
    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $insert = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())");
    $seeded = 0;
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key]);
        if ($stmt->fetchColumn() === false) {
            $insert->execute([$key, $value]);
            $seeded++;
        }
    }
    echo "$seeded setting(s) seeded (" . (count($defaults) - $seeded) . " already present).\n";

    // ---------------------------------------------------------------
    // Backfill from the legacy Community Rewards tables, for continuity
    // only. Every existing referrer becomes an auto-approved Network
    // Partner (reusing their existing users.referral_code value so any
    // link already shared keeps resolving). Every existing referral
    // relationship is copied as 'legacy_migrated' with NO active earning
    // window — migrating history must never retroactively create new
    // reward liability for already-settled trips (spec: config/engine
    // changes "must not retroactively modify rewards already earned or
    // pending"). Partners can refer NEW people going forward normally.
    // ---------------------------------------------------------------
    if (mn_table_exists($pdo, 'referrals')) {
        echo "\n=== Backfill: legacy referrers -> network_partners ===\n";
        $referrers = $pdo->query("
            SELECT DISTINCT r.referrer_user_id, u.referral_code, u.fullname
            FROM referrals r
            JOIN users u ON u.id = r.referrer_user_id
            WHERE u.referral_code IS NOT NULL
        ")->fetchAll();

        $checkExisting = $pdo->prepare("SELECT id FROM network_partners WHERE user_id = ?");
        $checkCodeTaken = $pdo->prepare("SELECT id FROM network_partners WHERE referral_code = ?");
        $insertPartner = $pdo->prepare("
            INSERT INTO network_partners (public_id, user_id, referral_code, status, approved_at, joined_at)
            VALUES (?, ?, ?, 'approved', NOW(), NOW())
        ");
        $partnerCount = 0;
        foreach ($referrers as $row) {
            $checkExisting->execute([$row['referrer_user_id']]);
            if ($checkExisting->fetchColumn()) {
                continue;
            }
            $code = $row['referral_code'];
            $checkCodeTaken->execute([$code]);
            if ($checkCodeTaken->fetchColumn()) {
                // Collision with another legacy code (shouldn't happen —
                // users.referral_code is unique — but stay safe).
                $code = $code . '-N';
            }
            $publicId = generate_unique_public_id($pdo, 'network_partners', 'public_id', 'NWP');
            $insertPartner->execute([$publicId, $row['referrer_user_id'], $code]);
            $partnerCount++;
        }
        echo "$partnerCount partner(s) created from legacy referrers.\n";

        echo "\n=== Backfill: legacy referrals -> network_referrals ===\n";
        $legacyReferrals = $pdo->query("SELECT * FROM referrals")->fetchAll();
        $checkReferred = $pdo->prepare("SELECT id FROM network_referrals WHERE referred_user_id = ?");
        $sumRewards = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_rewards WHERE referral_id = ? AND status = 'PAID'");
        $insertReferral = $pdo->prepare("
            INSERT INTO network_referrals
                (public_id, referrer_user_id, referred_user_id, referred_category, status, registered_at,
                 verified_at, first_qualifying_ride_at, total_rewards_generated, attribution_locked_at)
            VALUES (?, ?, ?, ?, 'legacy_migrated', ?, ?, ?, ?, ?)
        ");
        $refCount = 0;
        foreach ($legacyReferrals as $row) {
            $checkReferred->execute([$row['referred_user_id']]);
            if ($checkReferred->fetchColumn()) {
                continue;
            }
            $sumRewards->execute([$row['id']]);
            $publicId = generate_unique_public_id($pdo, 'network_referrals', 'public_id', 'NREF');
            $insertReferral->execute([
                $publicId,
                $row['referrer_user_id'],
                $row['referred_user_id'],
                strtolower($row['referral_type']) === 'driver' ? 'driver' : 'passenger',
                $row['registered_at'],
                $row['verified_at'],
                $row['qualified_at'],
                (float) $sumRewards->fetchColumn(),
                $row['qualified_at'] ?: $row['registered_at'],
            ]);
            $refCount++;
        }
        echo "$refCount referral relationship(s) migrated (legacy_migrated status — no active earning window, no new rewards).\n";
    } else {
        echo "\nNo legacy `referrals` table found — nothing to backfill.\n";
    }

    // Backfill public_id on any pre-existing rows missed above.
    foreach ([
        'network_partners' => 'NWP', 'network_referrals' => 'NREF', 'network_campaigns' => 'NCAMP',
        'network_reward_ledger' => 'NRWD', 'network_withdrawal_accounts' => 'NWA', 'network_withdrawals' => 'NWD',
        'network_reward_adjustments' => 'NADJ', 'network_fraud_flags' => 'NFLAG',
    ] as $table => $prefix) {
        $rows = $pdo->query("SELECT id FROM `$table` WHERE public_id IS NULL")->fetchAll();
        foreach ($rows as $row) {
            $publicId = generate_unique_public_id($pdo, $table, 'public_id', $prefix);
            $pdo->prepare("UPDATE `$table` SET public_id = ? WHERE id = ?")->execute([$publicId, $row['id']]);
        }
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
