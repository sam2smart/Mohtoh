# MOHTOH NETWORK — Database Rollback Procedure (Phase 2)

## When to use this

Only if the MOHTOH NETWORK application code is being withdrawn entirely and
you need the database back to its pre-upgrade shape. **Do not roll back
the schema just because a code deploy needs reverting** — the network_*
tables are additive and harmless to leave in place even if the app code
that uses them is rolled back; the application simply stops writing to
them. Only run this if you specifically need the schema itself reverted
(e.g. decommissioning the feature, or recovering from a bad upgrade before
any real reward/withdrawal data has accumulated).

## What this does NOT do

This rollback **deletes the 9 `network_*` tables and everything in them** —
partners, referral attributions, reward ledger, withdrawal history, fraud
flags, adjustments. It does **not** touch `referrals`/`referral_rewards`/
`referral_events` (the legacy tables network migration only ever read
from) or any core transport table. `users.is_network_partner` is dropped
as well (a flag column, no data loss beyond the flag itself).

**If any reward has matured to `available`, been withdrawn, or any
withdrawal has been marked `paid`, rolling back destroys that financial
history with no way to reconstruct it from the legacy tables (they have no
knowledge of post-launch activity).** Treat this as a point of no return
once real partners have real balances. Export first (see below) regardless
of whether you intend to restore it anywhere — it's your only copy.

## Step 0 — Export before dropping anything

```bash
mysqldump -u <user> -p <database> \
  network_partners network_campaigns network_referrals network_wallets \
  network_reward_ledger network_withdrawal_accounts network_withdrawals \
  network_reward_adjustments network_fraud_flags \
  > mohtoh_network_pre_rollback_backup_$(date +%Y%m%d_%H%M%S).sql
```

Verify the dump is non-trivial (`wc -l` > a few dozen lines, or eyeball
rows for key tables) before proceeding — a failed/empty dump is not a
backup.

## Step 1 — Drop in FK-safe order (children before parents)

```sql
-- Children first (tables that reference other network_* tables)
DROP TABLE IF EXISTS `network_reward_adjustments`;  -- FK -> network_reward_ledger, users
DROP TABLE IF EXISTS `network_fraud_flags`;          -- FK -> users, network_referrals
DROP TABLE IF EXISTS `network_reward_ledger`;        -- FK -> network_referrals, users
DROP TABLE IF EXISTS `network_withdrawals`;          -- FK -> users, network_withdrawal_accounts
DROP TABLE IF EXISTS `network_withdrawal_accounts`;  -- FK -> users
DROP TABLE IF EXISTS `network_wallets`;              -- FK -> users
DROP TABLE IF EXISTS `network_referrals`;            -- FK -> users, network_campaigns
DROP TABLE IF EXISTS `network_campaigns`;            -- FK -> users
DROP TABLE IF EXISTS `network_partners`;             -- FK -> users
```

## Step 2 — Drop the users column

```sql
SET @col_exists := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_network_partner'
);
SET @ddl := IF(@col_exists > 0,
  'ALTER TABLE `users` DROP COLUMN `is_network_partner`',
  'SELECT ''users.is_network_partner already absent'' AS skipped');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;
```

## Step 3 — Remove the settings (optional)

The 32 `network_*` keys in `system_settings` are harmless to leave behind
(the app code reading them is also gone) but for a clean rollback:

```sql
DELETE FROM `system_settings` WHERE `setting_key` LIKE 'network\_%';
```

## Step 4 — Post-rollback validation

```sql
SELECT COUNT(*) AS network_tables_remaining FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'network\_%';
-- Expect 0.

SELECT COUNT(*) AS column_remaining FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_network_partner';
-- Expect 0.

SELECT COUNT(*) AS referrals_untouched FROM referrals;
-- Compare against your pre-rollback count — must be unchanged.
```

## Tested

This exact sequence was run against the Phase 2 fresh-DB test instance
(`merit_drives_upgrade_test`, after the live-upgrade SQL + legacy backfill
had populated one partner and one migrated referral with a non-zero
`total_rewards_generated`): all 9 tables dropped cleanly in the order
above with no FK errors, `users.is_network_partner` dropped cleanly,
`system_settings` cleanup removed exactly 32 rows, and the legacy
`referrals`/`referral_rewards` tables and their row counts were
confirmed unchanged afterward.
