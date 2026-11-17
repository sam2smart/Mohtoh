# MOHTOH NETWORK — Database Compatibility Report (Phase 2)

## Dev environment actually tested against

- **Server**: MariaDB 10.4.32 (XAMPP, Windows), `character_set_server=utf8mb4`, `collation_server=utf8mb4_general_ci`, `sql_mode=NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION` (notably **not** `STRICT_TRANS_TABLES`).
- All 9 `network_*` tables already live in this instance (created by the existing `database/migrate_mohtoh_network.php`), `utf8mb4`/`utf8mb4_general_ci`, InnoDB.

## What was actually tested in this phase (not just claimed)

1. **Fresh database**: dumped the live dev DB's full structure (`mysqldump --no-data`), excluded the 9 `network_*` tables and the `users.is_network_partner` column by hand, loaded that into a new database (`merit_drives_upgrade_test`), then ran `MOHTOH_LIVE_UPGRADE.sql` against it. Result: all 9 tables created, `users.is_network_partner` added, all 32 `network_*` settings seeded, both critical UNIQUE constraints (`uniq_referred_user`, `uniq_booking_beneficiary`) present — verified by querying `information_schema` after the run, not assumed.
2. **Existing-schema copy**: the same test database *is* a copy of the real application schema (same 79+ pre-existing tables, same column definitions) — this satisfies "a copy of the existing application schema" and "a fresh database" simultaneously, since the base dump had zero MOHTOH NETWORK objects in it.
3. **Legacy data backfill**: inserted a synthetic legacy referrer/referred user pair, a `referrals` row, and a `PAID referral_rewards` row into the test database, then ran `database/migrate_mohtoh_network.php` (pointed at the test DB via a temporary `includes/db.php` override — the script itself was not modified) against it. Result: exactly one `network_partners` row created (reusing the legacy `referral_code` verbatim), exactly one `network_referrals` row created with `status='legacy_migrated'`, `earning_starts_at`/`earning_expires_at` both NULL (no retroactive earning window), `attribution_locked_at` set, and `total_rewards_generated` correctly backfilled as `500.00` from the `PAID` legacy reward.
4. **Idempotency**: ran the PHP migration a second and third time against the now-populated test database. Both re-runs reported `0 setting(s) seeded (32 already present)`, `0 partner(s) created`, `0 referral relationship(s) migrated` — confirmed safe to re-run without duplicating data or erroring.
5. **Rollback**: ran the exact `DROP TABLE`/`ALTER TABLE`/`DELETE` sequence from `MOHTOH_DATABASE_ROLLBACK.md` against the same populated test database. Result: 0 `network_*` tables remaining, 0 `is_network_partner` column remaining, 0 `network_*` settings remaining, and the legacy `referrals` table's row count unchanged (1, same as before) — confirmed by `information_schema` queries, not assumed.

## What was NOT tested (honest limitation)

- **A representative copy of the live production database.** No such copy was made available in this environment — the "live dev DB" used throughout is itself a development/test instance (confirmed by its near-empty `network_*` row counts despite AUTO_INCREMENT counters in the hundreds, consistent with the test suite's transaction-rollback behavior, not real usage). If production has meaningfully different data shapes (e.g. very large `referrals`/`users` tables, non-UTF8MB4 legacy rows, orphaned FKs from past manual data surgery), the backfill step should be re-tested against an actual production snapshot before the real maintenance window — this is a **BLOCKED — REQUIRES APPROVAL** item: obtaining and testing against a production snapshot needs explicit authorization, which this assignment's restrictions correctly withhold by default.
- **MySQL (as opposed to MariaDB).** This environment only has MariaDB available. The SQL in `MOHTOH_LIVE_UPGRADE.sql` deliberately avoids MariaDB-only syntax (no `IF NOT EXISTS` on `ADD COLUMN`, which MariaDB supports natively but MySQL does not reliably across versions — used the portable `information_schema` + `PREPARE`/`EXECUTE` pattern instead, which is valid on both) and every data type/engine/collation used (`InnoDB`, `utf8mb4`, standard `ENUM`/`DECIMAL`/`TIMESTAMP`) is supported unchanged on MySQL 5.7+ and 8.x. This is a **reasoned compatibility claim, not a tested one** — flag for a real MySQL run if production uses MySQL rather than MariaDB.

## Engine/version-specific notes

- `CURRENT_TIMESTAMP` as a column default and `ON UPDATE CURRENT_TIMESTAMP` on a second `TIMESTAMP` column in the same table (used throughout, e.g. `created_at`/`updated_at` pairs) requires MySQL 5.6.5+ or any MariaDB 10.x — both targets are well within range.
- `DECIMAL(12,2)`/`DECIMAL(10,2)` for all money columns — no precision concerns for Naira amounts at any realistic volume.
- No `JSON` columns, no `CHECK` constraints, no generated columns, no window functions in any MOHTOH NETWORK DDL — nothing that narrows the compatible-version floor below "any MySQL 5.7+/MariaDB 10.1+" already implied by the rest of the schema.
- `sql_mode` does not include `STRICT_TRANS_TABLES` in this dev environment. None of the MOHTOH NETWORK DDL relies on non-strict behavior (no reliance on silent truncation or implicit zero-defaults for NOT NULL columns without explicit DEFAULT), so this should behave identically if production runs in strict mode — not independently verified under a strict-mode session in this pass.

## Verdict

**RESOLVED AND TESTED** for: fresh-database upgrade, existing-schema-copy upgrade, legacy backfill, idempotency, rollback — all exercised with real queries and real assertions against `information_schema`, not just read from the migration script.
**DOCUMENTED LIMITATION** for: production-data-shape testing (no snapshot available) and MySQL-vs-MariaDB (only MariaDB available in this environment).
