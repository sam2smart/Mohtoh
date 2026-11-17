# MOHTOH — Deployment Runbook (Phase 10)

Covers deploying the work on `feature/mohtoh-stabilization` (based on the approved `review/mohtoh-network`) to a real environment. **Nothing in this runbook has been executed against production** — it is the procedure, prepared for the approved maintenance window, per this assignment's own restrictions (no production DB changes, no live deploys, no credential rotation without separate approval).

## 0. Pre-requisites / go-no-go

- [ ] `MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md`'s headline finding (public repo + fallback encryption key) has been addressed or explicitly accepted as a known risk by whoever owns the repo.
- [ ] A real `APP_ENCRYPTION_KEY` has been generated and is ready to set in the target `.env` (do not reuse the dev environment's — it has none effectively set).
- [ ] Real `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` values are ready for the target environment's `.env` (the code now reads these; it no longer silently works with `root`/empty outside local dev).
- [ ] Someone has reviewed and approved `MOHTOH_EXTERNAL_PARTNER_PROPOSAL.md`'s scope (already done in this engagement) and is aware `partner/dashboard.php` is new, user-facing surface.
- [ ] A maintenance window is scheduled and communicated, if this deploy includes the database upgrade (schema changes are additive and low-risk, but any deploy should still have one).

## 1. Backup

```bash
# Full database dump, timestamped, kept off the application server.
mysqldump -u <user> -p --single-transaction --routines --triggers <database> \
  > mohtoh_pre_deploy_$(date +%Y%m%d_%H%M%S).sql

# Application code: whatever the target already uses for releases (git tag,
# archive, or the hosting platform's own snapshot) — tag the pre-deploy
# commit so a code rollback has an exact point to return to.
git tag pre-mohtoh-stabilization-deploy
```
Verify the dump is non-trivial (size, row-count spot checks on `users`, `network_*` tables) before proceeding — an empty or truncated backup is not a backup.

## 2. Maintenance window (if taking one)

Not strictly required for this deploy — every schema change is additive (`CREATE TABLE IF NOT EXISTS`, one new nullable-default column) and every code change preserves backward compatibility with the existing app shape. A brief window is still recommended for the first deploy of this scope, out of caution, not necessity:
1. Put up a maintenance page, or at minimum pause any scheduled jobs that write to `system_settings`/`network_*` tables.
2. Confirm no in-flight withdrawal decisions or admin actions are mid-flight (check `network_withdrawals` for very recent `requested_at`/`reviewed_at` activity).

## 3. Application release

1. Deploy the code from `feature/mohtoh-stabilization` (or wherever it's merged to for release — see Phase 11 for branch handling) to the target server, by whatever mechanism that environment already uses (git pull/deploy script/CI pipeline).
2. **Do not copy this repo's `.env`** — the target needs its own, real one (see §4).
3. Confirm file permissions on `uploads/` remain correct if the deploy process touches that directory (nothing in this assignment changed upload handling).

## 4. Configuration

1. Set the target's `.env` from `.env.example` as the template:
   - `APP_ENV` = `PRODUCTION` (or `STAGING`)
   - `APP_BASE_URL` / the environment-specific URL variables, as already configured for that environment
   - `ALLOWED_HOSTS` matching the real domain(s)
   - `APP_ENCRYPTION_KEY` — a freshly generated 32-byte hex value (`php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`), **or**, if this environment already has one in use (pre-dating this deploy), leave it untouched — do not casually replace an in-use key (see `MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md`'s rotation procedure if a rotation is genuinely intended)
   - `DB_HOST` / `DB_NAME` / `DB_USER` / `DB_PASSWORD` — the target's real database credentials
2. Verify `.env`/`.env.bak` are **not** web-accessible and are excluded by the target's own deploy process from being served statically (this app's `.gitignore` now excludes them from the repo; that's a separate concern from the web server's own config).
3. Run the safe fingerprint check (`MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md`'s script) against the live environment to confirm `APP_ENCRYPTION_KEY` is NOT the public fallback before going further.

## 5. Database upgrade

```bash
# Schema (reviewable raw SQL, or skip straight to the PHP script):
mysql -u <user> -p <database> < MOHTOH_LIVE_UPGRADE.sql

# Legacy-data backfill + anything the SQL file above deferred to it (required step):
php database/migrate_mohtoh_network.php

# This assignment's addition (External Partner support):
php database/migrate_network_external_partners.php
```
Both PHP scripts are idempotent — safe to run even if some of their work was already done by an earlier partial deploy. Re-run `MOHTOH_LIVE_UPGRADE.sql`'s §13 post-migration validation queries and confirm expected counts (9 network tables, 1 new column, 31 settings — one fewer than Phase 2's original 32, since `network_registration_bonus` was removed from the admin-facing surface in Phase 7; the underlying `system_settings` row is harmless either way).

## 6. Smoke tests (post-deploy, before lifting the maintenance window)

1. `php database/test_mohtoh_network.php` against the **target** database — expect `48 passed, 0 failed`. **This writes and rolls back test data inside a transaction; still prefer running it against a staging copy first if the target is real production with real money.**
2. Log in as an existing rider and an existing driver — confirm both still reach their normal dashboard.
3. Register one new test account of each type (rider, driver, external partner) — confirm each redirects correctly and (for the external partner) lands on `partner/dashboard.php` with a referral code visible.
4. As an admin, open the MOHTOH NETWORK tab, confirm Overview/Settings/Partners/Referrals/Rewards/Withdrawals/Fraud sub-tabs all load without error.
5. Confirm a CSRF-protected action still works end-to-end from the real browser (any admin write action) — if it fails with "Your session has expired," the `<meta name="csrf-token">` tag or the token header isn't reaching the server; do not proceed past this.
6. Confirm an ordinary ride can still be created, booked, and completed (manually, through the real app) — this is the one regression check that matters most given the scope of this assignment's changes.
7. Clean up every test account/booking created in steps 2-6 before declaring the deploy complete.

## 7. Rollback

**Code**: redeploy the pre-deploy tag/release (`pre-mohtoh-stabilization-deploy`).

**Database**: the schema changes are additive and were specifically designed not to require a rollback alongside a code rollback — the old code simply ignores the new tables/column if rolled back to. Only run `MOHTOH_DATABASE_ROLLBACK.md`'s procedure if the decision is to remove MOHTOH NETWORK (and now, External Partner support) from this environment entirely, not merely to undo a bad deploy. If real reward/withdrawal activity has occurred since this deploy, re-read that document's warning about irreversible data loss before running it.

**Config**: if `.env` was changed as part of this deploy (new `APP_ENCRYPTION_KEY`, new DB credentials), reverting requires restoring the *previous* `.env` values, not just the code — keep a copy of the pre-deploy `.env` (outside the repo, securely) specifically for this.

## 8. Post-deployment monitoring

- Watch `error_log.txt` for the first hour after deploy, specifically for:
  - `SECURITY WARNING: APP_ENCRYPTION_KEY is unset or invalid` — means §4's key step was missed or the new `.env` isn't being loaded; fix before any real withdrawal account is submitted.
  - Any `MOHTOH NETWORK settlement failed for booking #...` lines — these are caught exceptions from the reward engine; transport itself is unaffected (by design, Phase 6/9), but repeated occurrences mean a real bug worth investigating promptly.
- Watch the admin MOHTOH NETWORK → Withdrawals tab for the first few real withdrawal requests — confirm the "Unverified" badge and "Verify Account" flow behaves as expected with real partner-submitted bank details (this is new, Phase 5, never exercised with real user data before this deploy).
- Confirm scheduled/periodic jobs (if any exist for this app) that might call `network_release_matured_rewards()` or similar continue running — nothing in this assignment changed how/whether those are scheduled, but it's worth a glance.
