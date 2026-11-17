# MOHTOH — Database Schema Inventory (Phase 2)

Captured against the live dev database (`merit_drives`, MariaDB 10.4.32, `utf8mb4`/`utf8mb4_general_ci`) on branch `feature/mohtoh-stabilization`, by reading actual `SHOW CREATE TABLE` output — not re-derived from memory or docs.

## A. Newly created tables (9)

All InnoDB, `utf8mb4`/`utf8mb4_general_ci`, created by `database/migrate_mohtoh_network.php` (idempotent — `SHOW TABLES`/`SHOW COLUMNS` guarded).

### `network_partners`
| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| id | int(11) PK AI | NO | — | |
| public_id | varchar(30) | YES | NULL | app-generated, `NWP` prefix |
| user_id | int(11) | NO | — | UNIQUE, FK→`users.id` ON DELETE CASCADE |
| referral_code | varchar(30) | NO | — | UNIQUE |
| status | enum(pending,approved,suspended,rejected) | NO | pending | indexed |
| terms_accepted_at / terms_version | timestamp / varchar(20) | YES | NULL | |
| approved_by / approved_at | int(11) / timestamp | YES | NULL | FK→`users.id` ON DELETE SET NULL |
| suspended_by / suspended_at / suspension_reason | int(11) / timestamp / text | YES | NULL | FK→`users.id` ON DELETE SET NULL |
| joined_at, created_at | timestamp | NO | CURRENT_TIMESTAMP | |
| updated_at | timestamp | NO | CURRENT_TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

Indexes: `uniq_user(user_id)`, `uniq_referral_code(referral_code)`, `idx_status(status)`, FK columns auto-indexed.

### `network_campaigns`
id PK AI · public_id(30) · name(100) NOT NULL · description(text) · driver_reward_pct/passenger_reward_pct/combined_ceiling_pct decimal(5,2) NULL · earning_days int NULL · starts_at/ends_at timestamp NULL · status enum(scheduled,active,ended,cancelled) DEFAULT scheduled · created_by int NULL FK→users SET NULL · created_at/updated_at timestamp.
Indexes: `idx_window(starts_at,ends_at)`, `idx_status(status)`.

### `network_referrals`
id PK AI · public_id(30) · referrer_user_id int NOT NULL FK→users CASCADE · referred_user_id int NOT NULL **UNIQUE** FK→users CASCADE · referred_category enum(driver,passenger) NOT NULL · campaign_id int NULL FK→network_campaigns SET NULL · status enum(registered,verified,active,expired,cancelled,legacy_migrated) DEFAULT registered · registered_at, phone_verified_at, email_verified_at, verified_at, first_qualifying_ride_at, earning_starts_at, earning_expires_at — timestamps · qualifying_ride_count int DEFAULT 0 · total_commission_generated/total_rewards_generated decimal(12,2) DEFAULT 0.00 · attribution_locked_at timestamp NULL · corrected_by int NULL FK→users SET NULL · correction_reason/cancelled_reason text NULL · **registration_ip varchar(45) NULL** (added by a *follow-up* idempotent `ALTER TABLE` inside the same migration — not part of the original CREATE; confirmed present in current schema) · created_at/updated_at.
Indexes: `uniq_referred_user`, `idx_referrer`, `idx_status`, `idx_earning_expiry`.
**The `uniq_referred_user` unique key is what enforces "direct referral only, one referrer per person" at the database layer**, not just in application code.

### `network_wallets`
id PK AI · user_id int NOT NULL **UNIQUE** FK→users CASCADE · pending_balance, available_balance, withdrawn_balance, reversed_balance, suspended_balance — all decimal(12,2) DEFAULT 0.00 · updated_at.
One row per network partner; this is MOHTOH NETWORK's entire balance model and is never touched by the legacy `users.wallet_balance`/`wallet_transactions` system (verified in Phase 1).

### `network_reward_ledger`
id PK AI · public_id(30) · network_referral_id int NOT NULL FK→network_referrals CASCADE · booking_id int NOT NULL · beneficiary_user_id int NOT NULL FK→users CASCADE · beneficiary_role enum(driver_referrer,passenger_referrer) NOT NULL · commission_base decimal(10,2) NOT NULL · reward_pct decimal(5,2) NOT NULL · reward_amount decimal(10,2) NOT NULL · status enum(pending,available,withdrawn,reversed,suspended) DEFAULT pending · hold_until/available_at/reversed_at timestamp NULL · reversal_reason text NULL · created_at.
Indexes: **`uniq_booking_beneficiary(booking_id, beneficiary_user_id, beneficiary_role)`** — this UNIQUE key is the actual mechanism behind "duplicate webhooks cannot generate duplicate rewards" (confirmed in Phase 1: a repeat `network_settle_booking_reward()` call hits a 1062 constraint violation and is caught, not re-inserted). `idx_beneficiary_status`, `idx_booking`, `idx_hold_until`.

### `network_withdrawal_accounts`
id PK AI · public_id(30) · user_id int NOT NULL FK→users CASCADE · bank_name(100) NOT NULL · **account_number_encrypted text NOT NULL** (encrypted at rest — see Phase 3's `APP_ENCRYPTION_KEY` dependency) · account_number_last4 varchar(4) NOT NULL (display only) · account_name(120) NOT NULL · is_default tinyint(1) DEFAULT 0 · verified_at timestamp NULL · created_at.
Index: `idx_user`.

### `network_withdrawals`
id PK AI · public_id(30) · user_id int NOT NULL FK→users CASCADE · withdrawal_account_id int NOT NULL FK→network_withdrawal_accounts **ON DELETE RESTRICT** (a withdrawal account cannot be deleted while a withdrawal references it) · amount decimal(10,2) NOT NULL · status enum(pending,under_review,approved,processing,paid,rejected,failed,reversed) DEFAULT pending · requested_at timestamp · reviewed_by/reviewed_at int/timestamp NULL FK→users SET NULL · payment_reference varchar(100) NULL (required by application logic before `paid`, not DB-enforced) · processed_by/processed_at int/timestamp NULL FK→users SET NULL · rejection_reason text NULL · created_at/updated_at.
Indexes: `idx_user_status(user_id,status)`, `idx_status`.

### `network_reward_adjustments`
id PK AI · public_id(30) · ledger_id int NULL FK→network_reward_ledger SET NULL · user_id int NOT NULL FK→users CASCADE · admin_id int NULL FK→users SET NULL · adjustment_type enum(credit,debit,reverse) NOT NULL · amount decimal(10,2) NOT NULL · reason text **NOT NULL** (DB-enforced: an adjustment without a reason is rejected at the schema level, not just application validation) · created_at.
Index: `idx_user`.

### `network_fraud_flags`
id PK AI · public_id(30) · user_id int NOT NULL FK→users CASCADE · network_referral_id int NULL FK→network_referrals SET NULL · flag_type varchar(50) NOT NULL · severity enum(low,medium,high) DEFAULT medium · status enum(open,reviewing,resolved,dismissed) DEFAULT open · details text NULL · raised_by_admin_id/resolved_by int NULL FK→users SET NULL · resolved_at timestamp NULL · resolution_notes text NULL · created_at.
Indexes: `idx_status`, `idx_user`.

## B. Existing tables modified (1)

### `users` — one column added
| Column | Type | Default | Notes |
|---|---|---|---|
| `is_network_partner` | tinyint(1) NOT NULL | 0 | **Set on join (`includes/network.php:155`), never read anywhere in the codebase.** Harmless but dead — flagged in Phase 1, candidate for Phase 7 cleanup (either wire it into a query/UI or drop it). |

No other `ALTER TABLE users` statements exist in `migrate_mohtoh_network.php`. (`users.referral_code`/`users.referred_by` predate this feature — part of the legacy Community Rewards system, read-only from MOHTOH NETWORK's perspective.)

## C. Existing tables used as dependencies, not modified

| Table | How MOHTOH NETWORK uses it |
|---|---|
| `users` | FK target for every `*_user_id`/`*_by` column above; `id`, `referred_by`, `referral_code` read for legacy backfill only |
| `payments` | **Read-only** — `commission_amount`, `driver_id`, `status='PAYMENT_CONFIRMED'` read by `network_settle_booking_reward()` to compute and gate reward eligibility |
| `ride_share_bookings` | **Read-only** — `status='completed'`, joined to resolve `rider_id`/`driver_id` for reward attribution |
| `system_settings` | 32 `network_*` keys share this existing key/value table (PK on `setting_key`) alongside pricing/promotions settings — no schema change, data-only |
| `admin_permissions`, and the schema `ensure_admin_rbac_schema()` self-heals (`users.is_super_admin`/`is_suspended`/`is_admin`/`admin_status` columns) | Consumed by `includes/admin_permissions.php` for every `api/network-admin.php` authorization check |
| `admin_audit_logs` | Written to by network admin actions (reward reversal, adjustments) via the existing generic audit-log mechanism, alongside the network-specific `network_reward_adjustments` table |
| `referrals`, `referral_rewards`, `referral_events` (legacy Community Rewards) | **Read-only, one-time backfill source** at migration time (see §D) — never written to going forward; kept in place as historical record, never deleted |

## D. Legacy backfill (migration-time only, not a schema change)

`migrate_mohtoh_network.php` also performs a one-time data backfill, guarded so re-running it is a no-op:
1. Every distinct legacy referrer (from `referrals` JOIN `users.referral_code`) becomes an auto-approved `network_partners` row, reusing their existing `referral_code` (so already-shared links keep resolving) — skipped if a `network_partners` row for that `user_id` already exists.
2. Every legacy `referrals` row is copied into `network_referrals` with `status='legacy_migrated'` and **no active earning window** (`attribution_locked_at` set, `earning_starts_at`/`earning_expires_at` left NULL) — deliberately so migrating history cannot retroactively create new reward liability for already-settled trips. `total_rewards_generated` is backfilled from `SUM(referral_rewards.amount WHERE status='PAID')` for continuity of display only.
3. `public_id` is backfilled on any row missed by the above (defensive, not expected to fire in normal operation).

Current dev DB state (test data, not representative of production volume): `referrals`=4 rows, `referral_rewards`=0, `referral_events`=9; `network_partners`=1, `network_referrals`=4, all other `network_*` tables near-empty (the 48-test suite runs inside a transaction that rolls back, so AUTO_INCREMENT counters are far ahead of row counts — e.g. `network_wallets` AUTO_INCREMENT=382 with 1 live row).

## E. Migration ordering

`migrate_mohtoh_network.php` must run (or the live-upgrade SQL equivalent) in this order, and does: `users.is_network_partner` → `network_partners` → `network_campaigns` → `network_referrals` (FK→campaigns) → `network_wallets` → `network_reward_ledger` (FK→referrals) → `network_withdrawal_accounts` → `network_withdrawals` (FK→withdrawal_accounts) → `network_reward_adjustments` (FK→ledger) → `network_fraud_flags` (FK→referrals) → settings seed → legacy backfill. This order is FK-dependency-safe; reversing any pair involving a FK reference fails.
