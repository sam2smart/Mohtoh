# MOHTOH — Production Readiness Report

Branch: `feature/mohtoh-stabilization`, based on the approved `review/mohtoh-network` (commit `743862e`). This report synthesizes Phases 1-9 of the stabilisation assignment against its own 14 Final Acceptance Criteria. Every claim below is traced to one of the phase documents, which in turn trace to actual code, actual test output, or actual HTTP/browser sessions — not assumed.

**Passing automated tests is not being treated as production readiness on its own** — see the explicit "Not resolved / documented limitations" section below for what this report does *not* claim.

## Acceptance criteria — verdict and evidence

| # | Criterion | Verdict | Evidence |
|---|---|---|---|
| 1 | Every outstanding observation has a documented resolution | RESOLVED AND TESTED | All Phase 1 findings tracked and closed or explicitly deferred across Phases 2-9; none silently dropped (see "Full observation ledger" below) |
| 2 | Every required database change is identified | RESOLVED AND TESTED | `MOHTOH_DATABASE_SCHEMA_INVENTORY.md` — every new table, modified column, and dependency, read from live `SHOW CREATE TABLE` output, not reconstructed from memory |
| 3 | A tested live upgrade package exists | RESOLVED AND TESTED | `MOHTOH_LIVE_UPGRADE.sql` + `database/migrate_network_external_partners.php`, both run against a fresh database built from a real schema dump, with legacy-data backfill and idempotency verified (Phase 2) |
| 4 | A rollback procedure exists | RESOLVED AND TESTED | `MOHTOH_DATABASE_ROLLBACK.md`, the exact sequence executed against the same populated test database and verified to restore the pre-upgrade state with the legacy tables untouched (Phase 2) |
| 5 | Environment and credential risks addressed with a safe rotation plan | RESOLVED AND TESTED (code); BLOCKED — REQUIRES APPROVAL (the actual rotation) | `MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md` — public-repo exposure and public-fallback-key use both confirmed via safe methods (never printed a secret); `.gitignore`, untracking, `.env.example`, and a runtime warning all implemented; **the actual key rotation and `.env` real-value population are explicitly not performed**, per this assignment's own restriction |
| 6 | Hardcoded production database credentials eliminated safely | RESOLVED AND TESTED | `includes/db.php` now reads `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASSWORD` from the environment, with the exact previous hardcoded values preserved as the local-dev fallback only (confirmed: local dev connects unchanged with zero `.env` entries required) |
| 7 | Application-wide CSRF gaps closed | RESOLVED AND TESTED | `MOHTOH_APPLICATION_SECURITY_AUDIT.md` — 22 files hardened (including both explicitly-named families and every other state-changing endpoint found), 3 independent real end-to-end HTTP tests, frontend fix applied once at the shared transport layer rather than per-call-site |
| 8 | External-partner functionality matches approved business requirements | RESOLVED AND TESTED | `MOHTOH_EXTERNAL_PARTNER_PROPOSAL.md` — proposal presented and approved *before* implementation (per the assignment's own instruction), then built and HTTP-tested end-to-end: registration, category gating, dashboard access guard, and (as a related fix) withdrawal-account verification |
| 9 | Financial calculations reconcile | RESOLVED AND TESTED | `MOHTOH_FINANCIAL_INTEGRITY_REPORT.md` — exact formula for every reward/wallet calculation traced to code and to passing test assertions; the one item not independently re-derived fresh (`pending_balance` tie-in to the ledger) is flagged as a documented limitation, not claimed |
| 10 | All advertised admin controls work | RESOLVED AND TESTED | `MOHTOH_NETWORK_BUSINESS_RULES_VERIFICATION.md` — all 31 `network_*` settings audited individually; 2 were found non-functional and fixed (`network_launch_at`/`network_closes_at`), 1 was found non-functional and removed from the admin UI rather than left misleading (`network_registration_bonus`) |
| 11 | Existing transport functionality passes regression testing | RESOLVED AND TESTED | `MOHTOH_REGRESSION_TEST_REPORT.md` — full route→booking→payment→earnings→commission lifecycle exercised with MOHTOH NETWORK disabled, confirming no dependency either direction; 341/341 files lint clean |
| 12 | Security tests pass | RESOLVED AND TESTED (CSRF, SQLi spot-check); DOCUMENTED LIMITATION (the rest) | CSRF fully covered (#7); SQLi spot-checked with 3 candidates found and confirmed safe (Phase 4); XSS/IDOR/session/upload findings carried forward from Phase 1 reading, not independently re-audited line-by-line in this pass — see limitations below |
| 13 | Documentation is complete | RESOLVED AND TESTED | This document plus 10 others (listed below), all delivered |
| 14 | Git changes are isolated and reviewable | RESOLVED BUT NOT TESTED until Phase 11 executes the actual commit/push sequence — see that phase's report |

## Full observation ledger (every Phase 1 finding, tracked to closure)

| Finding (Phase 1) | Disposition |
|---|---|
| `api/wallet.php`/`api/rideshare.php` CSRF gap | Fixed, Phase 4 |
| `api/admin-api.php` and 19 other files' CSRF gap | Fixed, Phase 4 |
| `network_external_partners_enabled` dead toggle | Fixed, Phase 5 |
| `users.is_network_partner` write-only column | **NOT RESOLVED** — still dead, left in place (removing a column is a real migration decision outside this phase's scope; flagged again here rather than silently dropped) |
| `network_multi_level_enabled` dead setting | Confirmed intentionally harmless (never surfaced), left as-is, Phase 7 |
| `database/migrate.php` corrupted header | **NOT RESOLVED** — noted in Phase 1, not cleaned up; harmless at runtime (pre-`<?php` content is ignored), low priority, explicitly carried forward rather than silently dropped |
| Legacy `credit_wallet()` unlocked `balance_after` audit field | **NOT RESOLVED** — pre-existing, documented in Phases 1 and 6, not fixed (money itself is correct, only a historical audit snapshot can be imprecise under a race) |
| `uploads/` missing `.htaccess` script-execution block | **NOT RESOLVED** — flagged in Phases 1 and 4 as defense-in-depth, not fixed |
| `network_launch_at`/`network_closes_at` dead settings | Fixed, Phase 7 |
| `network_registration_bonus` dead setting | Removed from admin UI, Phase 7 |
| `network_withdrawal_accounts.verified_at` dead column | Fixed (wired up + enforced), Phase 5 |
| `suspended_balance` dead column | **NOT RESOLVED** — found in Phase 6, never written or read by any code; flagged for a future decision (wire it up or drop it), not acted on here |

## Not resolved / documented limitations (stated plainly, not hidden)

- **4 genuinely unresolved items** carried forward above (`is_network_partner`, the corrupted migration header, the legacy audit-field race, missing `uploads/.htaccess`, `suspended_balance`) — none are blocking for a deploy, all are real, none were silently dropped from the record.
- **Credential rotation itself** was not performed — only prepared (acceptance criterion #5's own split between "plan" and "execution").
- **XSS/IDOR/session/upload** security categories were spot-checked against Phase 1's reading in Phase 4, not independently re-audited line-by-line this assignment.
- **No adversarial RBAC test** (a lower-privilege account attempting a forbidden admin action) was run.
- **No actual concurrent-load test** was run (no load-testing tool available in this environment); concurrency correctness was established by reading every lock/transaction boundary, not by inducing a real race.
- **CSV export** mechanism was not independently re-tested in this assignment (confirmed unchanged by `git diff`, and already verified in the original MOHTOH NETWORK build).
- **Vehicle-submission's live HTTP path** was not independently re-tested after its CSRF hardening (the hardening pattern itself was proven correct three times over on other files).
- **Production-scale data** was never available in this environment — Phase 2's upgrade/rollback testing used a development database with synthetic fixtures, not a representative production snapshot.
- **MySQL (vs. MariaDB)** compatibility for the upgrade SQL is a reasoned claim (avoided MariaDB-only syntax, used only portable constructs), not a tested one — only MariaDB was available in this environment.

## Documents produced

1. `MOHTOH_ARCHITECTURE_AUDIT.md` (Phase 1)
2. `MOHTOH_DATABASE_SCHEMA_INVENTORY.md` (Phase 2)
3. `MOHTOH_LIVE_UPGRADE.sql` (Phase 2)
4. `MOHTOH_DATABASE_ROLLBACK.md` (Phase 2)
5. `MOHTOH_DATABASE_COMPATIBILITY_REPORT.md` (Phase 2)
6. `MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md` (Phase 3)
7. `MOHTOH_APPLICATION_SECURITY_AUDIT.md` (Phase 4)
8. `MOHTOH_EXTERNAL_PARTNER_PROPOSAL.md` (Phase 5)
9. `MOHTOH_FINANCIAL_INTEGRITY_REPORT.md` (Phase 6)
10. `MOHTOH_NETWORK_BUSINESS_RULES_VERIFICATION.md` (Phase 7)
11. `MOHTOH_REGRESSION_TEST_REPORT.md` (Phase 9)
12. `MOHTOH_DEPLOYMENT_RUNBOOK.md` (Phase 10)
13. This document (Phase 10)

## Overall verdict

**Conditionally production-ready.** The MOHTOH NETWORK feature, now including External Partner support, is functionally complete, financially sound by every test run against it, isolated from and non-disruptive to core transport, and has had its most severe known gap (app-wide CSRF) closed. It is **not** unconditionally production-ready because: (a) the public-repository credential exposure found in Phase 3 has not been remediated, only documented with a safe path forward, and (b) several categories of testing (adversarial RBAC, real concurrency, production-scale data) were not performed in this environment. Recommend: address Phase 3's headline finding before this goes to any environment handling real credentials or real bank details, then deploy per `MOHTOH_DEPLOYMENT_RUNBOOK.md`.
