-- =====================================================================
-- MOHTOH NETWORK — Live Database Upgrade Package
-- =====================================================================
-- Target: a production/staging database that has the base MOHTOH schema
-- (users, payments, ride_share_bookings, system_settings, admin_permissions,
-- admin_audit_logs, and the legacy referrals/referral_rewards/referral_events
-- tables) but does NOT yet have MOHTOH NETWORK.
--
-- SCHEMA-ONLY. This file deliberately does NOT replicate the legacy-data
-- backfill (§D of MOHTOH_DATABASE_SCHEMA_INVENTORY.md) that
-- database/migrate_mohtoh_network.php also performs: that step calls
-- generate_unique_public_id() (application logic, collision-checked per
-- row) and is not something that can be safely hand-translated into raw
-- SQL without risking a duplicate/garbled public_id on a production table
-- with real referrer history. RECOMMENDATION: run
-- database/migrate_mohtoh_network.php (already idempotent, already tested)
-- as the actual upgrade mechanism; treat this .sql file as a reviewable,
-- DBA-runnable reference of the schema change alone, or as a fallback if
-- running PHP against production is not permitted during the maintenance
-- window.
--
-- Safe to re-run: every statement is idempotent (checks information_schema
-- or uses IF NOT EXISTS / INSERT IGNORE before acting). Written portably
-- for both MySQL 5.7+/8.x and MariaDB 10.x — dev was verified against
-- MariaDB 10.4.32. Run inside a transaction-capable client; InnoDB DDL in
-- MySQL 8/MariaDB 10.2+ is atomic per-statement, but take a backup first
-- regardless (see MOHTOH_DEPLOYMENT_RUNBOOK.md).
--
-- DO NOT RUN AGAINST PRODUCTION WITHOUT A FRESH BACKUP AND THE APPROVED
-- MAINTENANCE WINDOW. This file is prepared for review/approval, not for
-- unattended execution.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 0. Pre-migration validation (run first, read-only — abort on unexpected
--    output before proceeding)
-- ---------------------------------------------------------------------
SELECT 'pre-check: users table has expected columns' AS step;
SELECT COUNT(*) AS users_table_present
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users';
-- Expect 1. If 0, STOP — this is not a MOHTOH database.

SELECT 'pre-check: any network_* tables already present?' AS step;
SELECT TABLE_NAME FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'network\_%';
-- Expect 0 rows on a fresh target. If non-empty, this script's guards
-- will simply skip existing objects — safe either way, but confirm you
-- are not accidentally re-running against an already-upgraded database
-- you meant to leave alone.

SELECT 'pre-check: legacy referrals table present (for later backfill)?' AS step;
SELECT COUNT(*) AS legacy_referrals_present
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'referrals';

-- ---------------------------------------------------------------------
-- 1. users.is_network_partner
-- ---------------------------------------------------------------------
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_network_partner'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `users` ADD COLUMN `is_network_partner` TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT ''users.is_network_partner already exists'' AS skipped');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2. network_partners
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_partners` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `user_id` INT NOT NULL,
  `referral_code` VARCHAR(30) NOT NULL,
  `status` ENUM('pending','approved','suspended','rejected') NOT NULL DEFAULT 'pending',
  `terms_accepted_at` TIMESTAMP NULL,
  `terms_version` VARCHAR(20) NULL,
  `approved_by` INT NULL,
  `approved_at` TIMESTAMP NULL,
  `suspended_by` INT NULL,
  `suspended_at` TIMESTAMP NULL,
  `suspension_reason` TEXT NULL,
  `joined_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user` (`user_id`),
  UNIQUE KEY `uniq_referral_code` (`referral_code`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`suspended_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 3. network_campaigns (must exist before network_referrals, FK target)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_campaigns` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT NULL,
  `driver_reward_pct` DECIMAL(5,2) NULL,
  `passenger_reward_pct` DECIMAL(5,2) NULL,
  `combined_ceiling_pct` DECIMAL(5,2) NULL,
  `earning_days` INT NULL,
  `starts_at` TIMESTAMP NULL,
  `ends_at` TIMESTAMP NULL,
  `status` ENUM('scheduled','active','ended','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_window` (`starts_at`,`ends_at`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 4. network_referrals (includes registration_ip from the start here;
--    the ALTER-after-CREATE pattern in migrate_mohtoh_network.php exists
--    only because that table may already have been created by an OLDER
--    deploy without the column — this fresh CREATE includes it directly)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_referrals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `referrer_user_id` INT NOT NULL,
  `referred_user_id` INT NOT NULL,
  `referred_category` ENUM('driver','passenger') NOT NULL,
  `campaign_id` INT NULL,
  `status` ENUM('registered','verified','active','expired','cancelled','legacy_migrated') NOT NULL DEFAULT 'registered',
  `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `phone_verified_at` TIMESTAMP NULL,
  `email_verified_at` TIMESTAMP NULL,
  `verified_at` TIMESTAMP NULL,
  `first_qualifying_ride_at` TIMESTAMP NULL,
  `earning_starts_at` TIMESTAMP NULL,
  `earning_expires_at` TIMESTAMP NULL,
  `qualifying_ride_count` INT NOT NULL DEFAULT 0,
  `total_commission_generated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total_rewards_generated` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `attribution_locked_at` TIMESTAMP NULL,
  `corrected_by` INT NULL,
  `correction_reason` TEXT NULL,
  `cancelled_reason` TEXT NULL,
  `registration_ip` VARCHAR(45) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_referred_user` (`referred_user_id`),
  FOREIGN KEY (`referrer_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`referred_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`campaign_id`) REFERENCES `network_campaigns`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`corrected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_referrer` (`referrer_user_id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_earning_expiry` (`earning_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Defensive follow-up in case network_referrals already existed on this
-- target WITHOUT registration_ip (e.g. upgrading from a pre-fraud-check
-- deploy) — matches migrate_mohtoh_network.php's own guard exactly.
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'network_referrals' AND COLUMN_NAME = 'registration_ip'
);
SET @ddl := IF(@col_exists = 0,
  'ALTER TABLE `network_referrals` ADD COLUMN `registration_ip` VARCHAR(45) NULL',
  'SELECT ''network_referrals.registration_ip already exists'' AS skipped');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. network_wallets
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_wallets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `pending_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `available_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `withdrawn_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `reversed_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `suspended_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_user` (`user_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 6. network_reward_ledger (depends on network_referrals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_reward_ledger` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `network_referral_id` INT NOT NULL,
  `booking_id` INT NOT NULL,
  `beneficiary_user_id` INT NOT NULL,
  `beneficiary_role` ENUM('driver_referrer','passenger_referrer') NOT NULL,
  `commission_base` DECIMAL(10,2) NOT NULL,
  `reward_pct` DECIMAL(5,2) NOT NULL,
  `reward_amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending','available','withdrawn','reversed','suspended') NOT NULL DEFAULT 'pending',
  `hold_until` TIMESTAMP NULL,
  `available_at` TIMESTAMP NULL,
  `reversed_at` TIMESTAMP NULL,
  `reversal_reason` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_booking_beneficiary` (`booking_id`,`beneficiary_user_id`,`beneficiary_role`),
  FOREIGN KEY (`network_referral_id`) REFERENCES `network_referrals`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`beneficiary_user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_beneficiary_status` (`beneficiary_user_id`,`status`),
  INDEX `idx_booking` (`booking_id`),
  INDEX `idx_hold_until` (`hold_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
-- NOTE: uniq_booking_beneficiary is the duplicate-reward safety net
-- (network_settle_booking_reward() relies on its constraint-violation
-- error to make re-settlement a safe no-op). Do not drop or weaken it.

-- ---------------------------------------------------------------------
-- 7. network_withdrawal_accounts
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_withdrawal_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `user_id` INT NOT NULL,
  `bank_name` VARCHAR(100) NOT NULL,
  `account_number_encrypted` TEXT NOT NULL,
  `account_number_last4` VARCHAR(4) NOT NULL,
  `account_name` VARCHAR(120) NOT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `verified_at` TIMESTAMP NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 8. network_withdrawals (depends on network_withdrawal_accounts)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_withdrawals` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `user_id` INT NOT NULL,
  `withdrawal_account_id` INT NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `status` ENUM('pending','under_review','approved','processing','paid','rejected','failed','reversed') NOT NULL DEFAULT 'pending',
  `requested_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by` INT NULL,
  `reviewed_at` TIMESTAMP NULL,
  `payment_reference` VARCHAR(100) NULL,
  `processed_by` INT NULL,
  `processed_at` TIMESTAMP NULL,
  `rejection_reason` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`withdrawal_account_id`) REFERENCES `network_withdrawal_accounts`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user_status` (`user_id`,`status`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 9. network_reward_adjustments (depends on network_reward_ledger)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_reward_adjustments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `ledger_id` INT NULL,
  `user_id` INT NOT NULL,
  `admin_id` INT NULL,
  `adjustment_type` ENUM('credit','debit','reverse') NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `reason` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`ledger_id`) REFERENCES `network_reward_ledger`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 10. network_fraud_flags (depends on network_referrals)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `network_fraud_flags` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `public_id` VARCHAR(30) NULL,
  `user_id` INT NOT NULL,
  `network_referral_id` INT NULL,
  `flag_type` VARCHAR(50) NOT NULL,
  `severity` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` ENUM('open','reviewing','resolved','dismissed') NOT NULL DEFAULT 'open',
  `details` TEXT NULL,
  `raised_by_admin_id` INT NULL,
  `resolved_by` INT NULL,
  `resolved_at` TIMESTAMP NULL,
  `resolution_notes` TEXT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`network_referral_id`) REFERENCES `network_referrals`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`raised_by_admin_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_status` (`status`),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------
-- 11. system_settings defaults — INSERT IGNORE so an admin's existing
--     edits (on a re-run, or a partially-configured prior attempt) are
--     never clobbered. setting_key is the PRIMARY KEY, so IGNORE is safe.
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
  ('network_enabled', '1', NOW()),
  ('network_registrations_enabled', '1', NOW()),
  ('network_links_enabled', '1', NOW()),
  ('network_rewards_enabled', '1', NOW()),
  ('network_withdrawals_enabled', '1', NOW()),
  ('network_driver_referrals_enabled', '1', NOW()),
  ('network_passenger_referrals_enabled', '1', NOW()),
  ('network_external_partners_enabled', '1', NOW()),
  ('network_maintenance_mode', '0', NOW()),
  ('network_emergency_payout_suspended', '0', NOW()),
  ('network_launch_at', '', NOW()),
  ('network_closes_at', '', NOW()),
  ('network_terms_version', '1.0', NOW()),
  ('network_terms_text', 'Participation in MOHTOH NETWORK is voluntary and free. There is no joining fee, and no payment is made simply for registering or recruiting others. Earnings arise only from qualifying transport transactions completed by people you directly referred, are limited to direct referrals only (no multi-level payouts), and are subject to a defined earning period, verification, and reversal for cancelled, refunded, or fraudulent transactions. No income is guaranteed. Programme rules may change prospectively.', NOW()),
  ('network_announcement', '', NOW()),
  ('network_partner_auto_approve', '0', NOW()),
  ('network_driver_reward_pct', '15', NOW()),
  ('network_passenger_reward_pct', '10', NOW()),
  ('network_combined_ceiling_pct', '25', NOW()),
  ('network_earning_days', '90', NOW()),
  ('network_min_withdrawal', '2000', NOW()),
  ('network_registration_bonus', '0', NOW()),
  ('network_multi_level_enabled', '0', NOW()),
  ('network_reward_hold_days', '3', NOW()),
  ('network_total_budget', '0', NOW()),
  ('network_daily_budget', '0', NOW()),
  ('network_monthly_budget', '0', NOW()),
  ('network_max_combined_payout_per_txn', '0', NOW()),
  ('network_max_withdrawal_per_day', '0', NOW()),
  ('network_max_withdrawal_per_month', '0', NOW()),
  ('network_fraud_velocity_max_per_day', '10', NOW()),
  ('network_fraud_same_pair_max_rewards', '5', NOW());

-- ---------------------------------------------------------------------
-- 12. Legacy backfill — DO NOT run as raw SQL. Run
--     `php database/migrate_mohtoh_network.php` for this step (it is
--     idempotent and safe to run after the schema-only statements above
--     have already been applied — it will detect the tables exist and
--     skip straight to the settings-seed/backfill portion). See the
--     header note at the top of this file.
-- ---------------------------------------------------------------------
SELECT 'Schema upgrade complete. Run database/migrate_mohtoh_network.php now to perform the legacy-data backfill (idempotent, safe to run after this script).' AS next_step;

-- ---------------------------------------------------------------------
-- 13. Post-migration validation
-- ---------------------------------------------------------------------
SELECT 'post-check: all 9 network_* tables exist' AS step;
SELECT COUNT(*) AS network_tables_found FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (
  'network_partners','network_campaigns','network_referrals','network_wallets',
  'network_reward_ledger','network_withdrawal_accounts','network_withdrawals',
  'network_reward_adjustments','network_fraud_flags'
);
-- Expect 9.

SELECT 'post-check: users.is_network_partner present' AS step;
SELECT COUNT(*) AS col_found FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_network_partner';
-- Expect 1.

SELECT 'post-check: network_* settings seeded' AS step;
SELECT COUNT(*) AS settings_found FROM system_settings WHERE setting_key LIKE 'network\_%';
-- Expect 32.

SELECT 'post-check: critical FK/unique constraints present' AS step;
SELECT CONSTRAINT_NAME, TABLE_NAME FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('network_referrals','network_reward_ledger')
  AND CONSTRAINT_TYPE = 'UNIQUE';
-- Expect to see uniq_referred_user on network_referrals and
-- uniq_booking_beneficiary on network_reward_ledger.
