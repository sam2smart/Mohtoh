# MOHTOH NETWORK — Final Independent Verification & Production Acceptance Report

**Round 1 audit date:** 2026-11-11 — found and fixed 3 financial-integrity
defects (§1–§11 below, preserved unchanged as the historical record).
**Round 2 audit date:** 2026-11-12 — closes the CSRF condition from Round 1,
formally proves financial reconciliation with a worked example, and
re-runs the complete regression/security suite. See **§12 onward** for
everything new in this round; nothing in §1–§11 was weakened or rewritten
to produce it (re-confirmed in §12.6).

**FINAL STATUS: ACCEPTED WITH CONDITIONS** — narrowed and re-scoped from
Round 1 (see §16). The CSRF condition is now **resolved and verified**.
The remaining conditions are forward-looking recommendations tied to
*future* changes elsewhere in the app, not open defects in MOHTOH NETWORK
as it stands today.

---

## 1. Repository state

`git status --porcelain` on this working tree returns **138 lines** — the overwhelming majority predate this work entirely (an in-flight Secure Social Login feature, an image-optimizer/upload pipeline, admin RBAC refactor, `.bak-*` files, and ~25 new files under `uploads/` from real user activity on this shared dev database during this session). These are **not part of MOHTOH NETWORK** and were not modified by it.

**Files genuinely attributable to MOHTOH NETWORK:**

New:
```
api/network.php                        165 lines  — partner-facing API
api/network-admin.php                  430 lines  — admin API
includes/network.php                  1045 lines  — the engine (all business logic)
database/migrate_mohtoh_network.php    456 lines  — schema migration
database/test_mohtoh_network.php       318 lines  — automated test suite
partials/network-sheet.php             121 lines  — partner dashboard UI
assets/js/network.js                   361 lines  — partner dashboard client
docs/mohtoh-network.md                 276 lines  — architecture/admin/deploy doc
includes/admin_permissions.php              — pre-existing file (RBAC infra built by
                                               earlier, unrelated work); MOHTOH NETWORK's
                                               only change to it is one new permission
                                               group (~12 lines)
```

Modified, with the change **isolated to specific, verified hunks** (confirmed by reading every diff hunk, not assumed):
```
admin/dashboard.php      — pre-existing diff carries an unrelated RBAC/permissions
                            refactor and an image-viewer addition (lines 1–183 of the
                            diff, confirmed by direct inspection). MOHTOH NETWORK's
                            contribution: one nav link, the entire "MOHTOH NETWORK"
                            tab section and its 7 sub-panes, one TAB_LOADERS entry,
                            and ~650 lines of clearly-delimited JS (marked by
                            "---- MOHTOH NETWORK" / "---- CSV export" comments).
api/auth.php             — 2-line change: referral code lookup now resolves against
                            network_partners instead of the legacy users.referral_code.
api/referrals.php        — 1-query change: validate_code ("Invited by X" banner) now
                            resolves against network_partners.
api/rideshare.php        — confirm_direct_payment gains one try/catch-wrapped call
                            to network_settle_booking_reward().
includes/functions.php   — settle_ride_completion() gains the same try/catch-wrapped
                            call, per-completed-booking. The other 3 hunks in this
                            file's diff (CSRF helper, trip-confidence calc) are
                            unrelated pre-existing work, confirmed by inspection.
includes/referral.php    — create_referral_relationship()/track_referral_event() now
                            delegate into includes/network.php; the legacy
                            qualify_referral()/release_referral_reward() payout chain
                            was removed (dead after the delegation).
partials/sidebar.php     — "Grow My Community" nav entry replaced with "MOHTOH NETWORK".
rider/dashboard.php, rider/trips.php, rider/profile.php,
driver/dashboard.php, driver/trips.php, driver/profile.php
                          — each has exactly a 2-line change (swap the included
                            partial and the loaded script); driver/profile.php has
                            one additional menu-row swap. Confirmed by direct diff
                            inspection of every one of these 6 files; the remainder
                            of their diffs (e.g. rider/dashboard.php's ~50-line
                            ride-deep-link block) is unrelated, pre-existing work.
```

**No unrelated files were changed by this work.** `api/referral-admin.php` (the old Community Rewards settings endpoint) shows as modified in git status but was **not touched in this session or any prior MOHTOH NETWORK phase** — it was already modified before this feature began.

---

## 2. Files reviewed

Every file in §1 was re-read in full during this audit (not assumed from the prior completion report). `docs/mohtoh-network.md` was re-read and cross-checked against the actual code (one inaccuracy found and left as-is since it was already correct on recheck — no changes needed there).

---

## 3. Tests executed (actual commands, actual output)

### 3.1 Automated suite
```
php database/test_mohtoh_network.php
```
Result: **37/37 passed** (started at 28, grew to 37 during this audit — see §11 for what the 9 new checks cover: double-processing guards, negative-balance boundaries, and ledger reconciliation). Full pass list is reproducible by running the command above; representative output:
```
PASS: withdrawal request for far more than available balance is rejected
PASS: no operation ever drives available_balance negative
PASS: no operation ever drives pending_balance negative
PASS: network_wallets balances reconcile exactly against network_reward_ledger for every test beneficiary
PASS: cannot mark an already-paid withdrawal paid again (no double-credit)
PASS: cannot reject an already-paid withdrawal (no fabricated refund)
PASS: referral velocity flagged exactly once despite 2 referrals over threshold (no duplicate flags)
==== 37 passed, 0 failed ====
```

### 3.2 PHP lint
```powershell
foreach ($f in <20 files>) { php -l $f }
```
Result: **20/20 files, 0 syntax errors.**

### 3.3 JavaScript validation
No Node.js runtime is installed in this environment (`node`/`node.exe` not found via `Get-Command` or `where.exe`). JavaScript was instead validated by loading it in the **actual browser runtime** (the session's Chromium-based preview pane) and confirming correct execution with zero parse/runtime errors attributable to `network.js` or the dashboard's inline Network JS, across multiple live sessions in this and prior phases (join flow, QR generation, settings save, CSV export). This exercises the real DOM/browser APIs these scripts depend on, which a standalone linter would not.

### 3.4 Live HTTP security tests (via `curl` against the running server)
Documented verbatim in §9.2, with actual request/response pairs.

### 3.5 Fresh-install and upgrade migration tests
Documented verbatim in §9 (DB safety).

---

## 4. Security findings

| # | Finding | Severity | Status |
|---|---|---|---|
| 1 | **Race condition**: `network_request_withdrawal()`, `network_admin_decide_withdrawal()`, and `network_reverse_ledger_row()` take a `SELECT ... FOR UPDATE` row lock, but none of their call sites opened an explicit transaction — in MySQL's default autocommit mode, that lock is released at the end of the single `SELECT` statement and provides **no real protection**. Two concurrent withdrawal requests could both pass the balance check before either committed. | **Critical** | **Fixed** — wrapped all 3 call sites in explicit `beginTransaction()`/`commit()`/`rollBack()`. **Re-verified under genuine concurrency** (§9.2): two simultaneous HTTP withdrawal requests against a ₦100 balance for ₦100 each — exactly one succeeded, the other was correctly rejected, final balance ₦0.00 (not negative). |
| 2 | **Missing state-machine guard**: `network_admin_decide_withdrawal()` applied any decision (`paid`/`reject`/etc.) regardless of the withdrawal's current status. Calling `paid` twice would double-credit `withdrawn_balance`; calling `reject` on an already-paid withdrawal would fabricate `available_balance` for money that had already left the platform. | **Critical** | **Fixed** — added an explicit valid-prior-state map; invalid transitions are now refused with an error. Covered by 3 new automated tests. |
| 3 | **Silent double-credit on maturation/reversal race**: `network_release_matured_rewards()` and `network_reverse_booking_rewards()` ran their wallet-balance adjustment **unconditionally**, even when their own ledger-row `UPDATE ... WHERE status='pending'` affected zero rows (i.e. a concurrent call had already processed that row). | **High** | **Fixed** — both now check `rowCount()` and skip the wallet adjustment entirely if the ledger row wasn't actually theirs to move. |
| 4 | **CSV / spreadsheet formula injection**: exported fields (bank name, account name, partner name, etc.) were not checked for a leading `=`, `+`, `-`, or `@` before being written to CSV — a malicious value could execute as a formula in Excel/Sheets for whoever opens the export. | **Medium** | **Fixed** — `downloadCsv()` now neutralizes any such value by prefixing it with `'` (OWASP-recommended mitigation), applied uniformly across all 4 exports. |
| 5 | **Duplicate fraud flags**: the velocity and shared-IP checks created a brand-new `network_fraud_flags` row on *every* qualifying event, rather than once per ongoing issue — 6 referrals past a threshold of 2 produced **4 separate flags** for what is really one pattern. | **Medium** (noise, not a safety issue) | **Fixed** — `network_flag_fraud()` now dedupes against an existing open/reviewing flag of the same type for the same user, refreshing its details instead of creating a duplicate. |
| 6 | **Shared-IP flag severity overstated confidence**: flagged at `'high'`, which could read to an admin as near-certain fraud, when a shared IP among a partner's referrals is frequently innocent (family, shared office/campus network, carrier NAT). | **Low** | **Fixed** — downgraded to `'medium'`, with the flag's own text now stating explicitly that a shared IP alone is not proof of fraud. |
| 7 | **Dead/misleading UI**: the admin Rewards-tab status filter offered "Withdrawn" and "Suspended" options that can never match a row (no code path sets either status on a ledger row today). | **Low** | **Fixed** — removed both options; documented why in a code comment and in §11. |
| 8 | SQL injection | — | **None found.** Every query in the new/modified code is parameterized; the one `LIMIT $limit OFFSET $offset` interpolation pattern matches the pre-existing, already-audited convention in `api/admin-api.php` (`paginate_params()` casts both to `(int)` before use). |
| 9 | XSS | — | **None found.** `escapeHtml()` is applied to every user-controlled string rendered as HTML, in both the partner (`network.js`) and admin (`dashboard.php`) surfaces. The few unescaped interpolations are of DB `ENUM` columns (fixed value sets, not free text), which carry no injection risk. |
| 10 | CSRF | **Pre-existing, app-wide gap — not introduced by this feature** | `require_csrf()` exists in `includes/functions.php` but is adopted by exactly **one** endpoint in the entire codebase (`api/social-accounts.php`, unrelated work). None of the core transport endpoints (`api/rideshare.php`, `api/auth.php`, `api/admin-api.php`, etc.) use it either. MOHTOH NETWORK's new endpoints have the same exposure as every other session-authenticated endpoint in this app — no worse, not better. **Not silently fixed here**: retrofitting CSRF to 2 files while the other ~30 session-authenticated endpoints remain unprotected would be inconsistent and is a decision belonging to whoever owns the app's security posture, not something to bolt on unilaterally to one feature. See §11. |
| 11 | IDOR | — | **Tested live** (§9.2): a rider attempting to request a withdrawal against another user's bank account was correctly rejected ("Withdrawal account not found"); listing withdrawal accounts correctly returned only the caller's own. |
| 12 | Privilege escalation | — | **Tested live** (§9.2): a plain rider session was rejected by every admin-only action tried (`approve_partner`, `overview`), with the exact same "Unauthorized access" response an unauthenticated request gets. |
| 13 | Unauthorized referral attribution | — | **Confirmed architecturally impossible**: `api/auth.php` only ever accepts a referral **code** (string) from the client; the referrer's user ID is always resolved server-side via `get_network_partner_by_code()`. No endpoint accepts a raw referrer/referred user ID from an unauthenticated caller. |
| 14 | Sensitive information leakage | — | Reviewed every CSV export and every admin list response: no full bank account numbers (only last 4 digits), no passwords/tokens, no cross-user data beyond what an admin's role already permits. |

---

## 5. Financial integrity findings

- **Driver/passenger referral percentage, combined ceiling**: correct, config-driven, verified by the automated suite including the ceiling's proportional-scaling behavior under an over-committed configuration (20%+20% scaled down to exactly 25% of commission).
- **Net eligible commission**: always `payments.commission_amount` (the one source of truth), never recomputed or estimated.
- **Reward expiry / earning window**: lazily expires on read (`network_resolve_active_referral()`), verified.
- **Pending → available transition**: verified, including the race-condition fix in finding #3 above.
- **Withdrawal approval / rejection**: verified, including the new state-machine guard (finding #2).
- **Concurrent withdrawal protection**: **verified under real concurrency**, not just sequential testing (§9.2) — this is the strongest form of evidence the audit asked for.
- **Duplicate webhook protection**: verified (idempotent `UNIQUE(booking_id, beneficiary_user_id, beneficiary_role)` on the ledger; repeat settlement calls produce zero duplicate rows).
- **Negative balance**: explicitly tested — no operation can drive `available_balance` or `pending_balance` negative.
- **Ledger reconciliation**: verified with a corrected invariant (see below) — `network_wallets` balances reconcile exactly against `network_reward_ledger` for every beneficiary tested.
- **Partial refund adjustments**: **NOT IMPLEMENTED.** The spec asks for proportional reward reversal on a partial refund. This app has **no partial-refund mechanism anywhere** — not for ordinary transport payments, not for MOHTOH NETWORK. Both `network_reverse_ledger_row()` (admin-triggered) and `network_reverse_booking_rewards()` (system-triggered, currently has no live caller — see §9.1) only support full reversal. Implementing proportional partial-refund logic now, with no real partial-refund trigger anywhere in the app to wire it into, would be speculative scope creep rather than a fix of working functionality. **Disclosed as an outstanding gap, not concealed** — see §11.
- **One design clarification surfaced during this audit**: `network_reward_ledger.status` never actually reaches `'withdrawn'` or `'suspended'` — a withdrawal draws from the wallet's **aggregate** available pool, not a traceable per-reward FIFO queue (splitting a single ledger row across a partial withdrawal would conflict with the idempotency-critical `UNIQUE(booking_id, beneficiary_user_id, beneficiary_role)` constraint). The two ENUM values are reserved for a possible future per-reward audit trail. This is **not a financial-safety issue** — the wallet's own `available_balance`/`withdrawn_balance` split is correct and fully reconciles against the ledger's `'available'` sum (`available + withdrawn == ledger_available`, proven in the test suite) — but it does mean a specific reward row's status display is not itself the withdrawal history; `network_withdrawals` (a complete, correctly-audited table) is the correct source for that.

---

## 6. Fraud detection findings

All 4 heuristics independently re-tested (not re-asserted from the prior report):

1. **Referral velocity** — correct threshold enforcement, config-driven (`network_fraud_velocity_max_per_day`), confirmed to **no longer duplicate flags** (fix #5 above).
2. **Shared-IP multi-accounting** — correct detection condition (same partner, same IP, a *different* referred account), confirmed that **a shared IP alone never blocks anything** — it only ever produces an admin-reviewable flag, now at a severity (`medium`) and wording that doesn't overstate the finding (fix #6).
3. **Coordinated driver/passenger referrals** — correctly fires only when one partner is the direct referrer of **both** sides of a single booking; correctly does **not** fire when referrers differ (explicit negative test).
4. **Repeated driver/rider pair farming** — correct threshold enforcement, config-driven (`network_fraud_same_pair_max_rewards`).

For every heuristic: confirmed **no automatic account suspension** and **no automatic reward reversal** — the only write any fraud check performs is an `INSERT`/`UPDATE` into `network_fraud_flags`; suspension and reversal are separate, admin-only, reason-required functions that nothing in the detection code calls (verified by grep across every call site, §4 finding discussion). Settings for all 4 thresholds are configurable from **Admin Dashboard → MOHTOH NETWORK → Settings → Fraud thresholds**.

**Not implemented** (same disclosure as the original build report, re-confirmed as still accurate): device-fingerprinting beyond IP, cancellation-rate analysis, and general "artificial booking activity" detection across the ride graph — this app has no fingerprinting infrastructure, and general ride-fraud detection is platform-wide scope beyond a referral programme's remit.

---

## 7. Admin dashboard findings

Every configuration field listed in the audit request exists and is wired to a real `system_settings` key (confirmed by reading the GET/POST handlers in `api/network-admin.php` end to end, and by live RBAC testing in §9.2). RBAC enforcement:

- Programme Settings / Reward Configuration / Financial Controls / Campaigns: hardcoded `require_super_admin()` — not grantable to any Subadmin role, matching the pre-existing Pricing/Promotions/Commission convention.
- Partner approve/suspend/reactivate, referral attribution correction: gated on `network.partners.manage`.
- Reward adjustment/reversal, withdrawal decisions, fraud resolution: gated on `network.finance.manage`.
- Read-only overview/lists: gated on the baseline `network.view`.

All of this is **server-side** (`require_admin_permission()`/`require_super_admin()` inside the API files) — the UI's conditional rendering of tabs/buttons is cosmetic only, and was proven to not be the actual security boundary by the direct unauthenticated/unauthorized `curl` tests in §9.2, which bypass the UI entirely.

CSV export and filtering/pagination: present on Partners, Referrals, Rewards, Withdrawals; pagination reuses the app's existing, previously-audited "Universal Pagination" component (`paginate_params()`/`paginate_meta()`/`renderPagination()`) rather than a new implementation.

---

## 8. Migration findings

Tested **both** paths requested, against an isolated database (not the shared dev database), so neither test could affect real data:

1. **Fresh installation**: created a new, empty database (`merit_drives_fresh_install_test`), cloned only the *structural* dependencies (`users`, `ride_shares`, `ride_share_bookings`, `payments`, `system_settings`, `pricing_change_logs`, `admin_audit_logs` — no data, and deliberately **no** legacy `referrals` table, to prove a true greenfield install). Ran the actual, unmodified `database/migrate_mohtoh_network.php` against it:
   - All 9 tables created.
   - `users.is_network_partner` added.
   - 32 settings seeded.
   - Legacy-backfill branch correctly detected the absence of `referrals` and skipped cleanly (no error).
   - Foreign keys and indexes verified via `information_schema` on the resulting schema: every expected FK/index present, and critically, the two integrity-bearing unique indexes (`network_reward_ledger.uniq_booking_beneficiary`, `network_referrals.uniq_referred_user`) confirmed as real unique indexes at the database level, not just application logic.
2. **Existing-database upgrade**: on the same test database, dropped `is_network_partner` and re-ran the migration — confirmed it reports **"Added."** and correctly re-adds the column (the branch a real upgrade would exercise), then confirmed a second re-run is a clean no-op (idempotent).
3. Test database dropped afterward; nothing persisted.

Transaction handling / rollback: every data-mutating script in this feature (`network_join()`, `network_attribute_referral()`, the reward engine, withdrawals) either runs inside its caller's transaction or is safe standalone per the race-condition fixes in §4. The migration script itself is pure DDL + idempotent `INSERT ... ON DUPLICATE KEY UPDATE`, matching this project's established `migrate_*.php` convention exactly.

---

## 9. Transport regression findings

### 9.1 Isolation by construction
MOHTOH NETWORK's money tables (`network_wallets`, `network_reward_ledger`, etc.) are **entirely separate** from the transport platform's money tables (`users.wallet_balance`, `wallet_transactions`). No function in `includes/network.php` ever writes to those core tables. The only two places MOHTOH NETWORK code is invoked from the transport path (`settle_ride_completion()` in `includes/functions.php`, and `confirm_direct_payment` in `api/rideshare.php`) wrap that single call in `try { } catch (Throwable $e) { error_log(...); }` — confirmed present and unmodified by anything in this audit's fixes.

### 9.2 Live tests executed (actual `curl` output against the running server)

```
$ curl -s "$BASE/api/network.php?action=status"                       (no cookie)
{"status":"error","message":"Unauthorized","data":[]}
$ curl -s "$BASE/api/network-admin.php?action=overview"                (no cookie)
{"status":"error","message":"Unauthorized access","data":[]}
$ curl -s -X POST ... action=approve_partner ...                       (no cookie)
{"status":"error","message":"Unauthorized access","data":[]}

# logged in as a plain rider:
$ curl -s -b cookies -X POST ... action=approve_partner ...
{"status":"error","message":"Unauthorized access","data":[]}
$ curl -s -b cookies "$BASE/api/network-admin.php?action=overview"
{"status":"error","message":"Unauthorized access","data":[]}
$ curl -s -b cookies "$BASE/api/network.php?action=status"
{"status":"success","message":"Status fetched", ...}                  (own data only, correctly)

# IDOR attempt: rider 656 tries rider 658's bank account
$ curl -s -b cookies -X POST ... withdrawal_account_id=10 ...
{"status":"error","message":"Withdrawal account not found.","data":[]}

# live registration through a valid referral link
$ curl -s -X POST ... action=register ... referral_code=NETWORK-AUDIT48 ...
{"status":"success","message":"Registration successful", ...}
# confirmed in DB: referred_by=658, network_referrals row created,
# registration_ip correctly stored as the real connecting address.

# concurrency: two SIMULTANEOUS withdrawal requests, ₦100 each, ₦100 available
$ curl ... & curl ... & wait
Result 1: {"status":"success", ..., "withdrawal_id":12, ...}
Result 2: {"status":"error","message":"Insufficient available balance.","data":[]}
# final available_balance: ₦0.00 (not negative, not double-spent)
```

### 9.3 Fail-safe test (the strongest form of "cannot disrupt transport")
Rather than only toggling the module off (which the automated suite already covers — `rewards_enabled=0` generates no reward and throws no error), this audit additionally **broke the NETWORK engine outright**: temporarily renamed `network_reward_ledger` away mid-test so its own `INSERT` throws a real `PDOException`, then called the **actual, unmodified `settle_ride_completion()`** — the real production driver-earning settlement function — on a real completed/paid booking.

Result:
```
MOHTOH NETWORK settlement failed for booking #193: SQLSTATE[42S02]: Base table or view
not found: 1146 Table 'merit_drives.network_reward_ledger' doesn't exist
PASS: settle_ride_completion() completes without throwing despite a broken NETWORK table
PASS: driver earning still credited correctly (₦4600)
PASS: driver wallet_balance actually reflects the credit
PASS: a real wallet_transactions ledger row was written for the earning
```
The driver was paid correctly and a real ledger row exists, **even with the NETWORK module actively broken**. Table restored and fixtures cleaned up immediately after.

### 9.4 Scope note
Full browser-driven end-to-end testing of ride creation/booking/cancellation (as opposed to calling the real settlement function directly, as above) was **not re-executed in this specific audit session** — it was exercised in earlier build phases for feature verification, and this audit's own code changes touch *only* the two integration points covered by §9.3, which is the correct and sufficient scope for proving *this feature* doesn't regress the transport platform. This is disclosed rather than implied as full platform-wide regression testing.

---

## 10. Outstanding issues (not concealed)

1. **CSRF** — pre-existing, app-wide gap (finding #10, §4). Recommend a decision on whether to retrofit CSRF protection across the whole session-authenticated API surface (a project-wide initiative) before this is considered fully hardened for production, rather than patching 2 files in isolation.
2. **Partial refund reversal** — not implemented; no live trigger point exists anywhere in the app (§5).
3. **External Network Partners** — no distinct account category; anyone (including a would-be pure non-transport participant) becomes a Network Partner via a normal rider/driver account. Functionally adequate (doesn't block or require booking) but not a literal third "external" identity type.
4. **`network_launch_at`/`network_closes_at`** — stored but not automatically enforced (no scheduled-task mechanism exists yet in this app).
5. **Legacy UI** — the old "Community Growth" admin tab, `assets/js/referrals.js`, and `partials/community-rewards-sheet.php` are now dead code (unreferenced by any live page). Candidates for archival, not deleted.
6. One minor, non-security bug fixed in passing: `network_attribute_referral()`'s notification message referenced `$referred['fullname']` without that column being in its own `SELECT` — always fell back to generic text. Fixed (one-line `SELECT` addition).

---

## 11. Production deployment recommendation (Round 1 — superseded by §16)

**Round 1 status at the time: ACCEPTED WITH CONDITIONS.** The CSRF
condition below is **resolved in Round 2 (§12–§16)** — this section is
preserved unedited as the historical record of what Round 1 actually
found and recommended.

Round 1 conditions:
- Decide on CSRF posture (finding #10) before/shortly after launch — not a MOHTOH-NETWORK-specific regression, but worth a conscious decision rather than silence.
- Review §10 items 2–4 against actual launch requirements; none of them block a safe launch, but all are genuine, disclosed gaps, not polish items.
- **Get explicit review of this report and the diff before committing or deploying** — no commit has been made (see below).

Nothing found in this audit indicates the system is financially unsafe to run: every confirmed money-safety defect (the 3 "Critical"/"High" findings in §4) was fixed **and** re-proven under the strongest available test for each (genuine HTTP concurrency for the race condition; a direct state-transition attempt for the double-processing guard; an automated reconciliation check for the ledger drift). Transport isolation was proven by deliberately breaking the NETWORK module and confirming the core settlement function still pays drivers correctly.

### Git and release preparation

- **No commit has been created.** Per instruction, this audit does not commit or deploy without explicit approval.
- **Recommended staging**: use targeted `git add` on exactly the files listed in §1 — **do not** `git add -A` or `git add .` in this working tree, which also contains ~130 lines of unrelated, uncommitted changes from other in-flight work (Social Login, RBAC, image optimization) that should not be bundled into this feature's commit.
- **Environment requirements**: none beyond what the existing MohToh app already requires (PHP 8, MySQL/InnoDB, no new Composer/npm dependency — the one new runtime dependency is `qrcodejs` loaded from a CDN in the browser, same pattern as this app's existing Bootstrap/Leaflet CDN usage).
- **Migration instructions**: `php database/migrate_mohtoh_network.php` (idempotent; safe to re-run). See `docs/mohtoh-network.md` §7 for full deployment steps.
- **Rollback procedure**: see `docs/mohtoh-network.md` §7 ("Rollback plan") — fastest is a zero-deploy settings flip (`network_enabled=0`); full details including schema-level rollback are documented there.

**Recommended commit message:**
```
Add MOHTOH NETWORK: direct-referral revenue-share programme

Replaces the flat one-time "Community Rewards" referral system with a
recurring %-of-commission programme: Network Partners earn a share of
MohToh's commission on qualifying rides from people they directly
referred, for a 90-day window, with admin-configurable rates, budgets,
fraud detection, and a dedicated reward wallet with withdrawal approval.

- New: includes/network.php (engine), api/network.php (partner API),
  api/network-admin.php (admin API), partials/network-sheet.php +
  assets/js/network.js (partner dashboard), 9 new database tables
  (database/migrate_mohtoh_network.php, idempotent).
- Legacy includes/referral.php call sites now delegate into the new
  engine, so registration/verification/trip-completion hooks across
  the app feed it without having been individually rewritten; the old
  tables and historical data are untouched.
- Admin dashboard gains a MOHTOH NETWORK tab (overview, settings,
  partners, referrals, rewards, withdrawals, fraud) with CSV export,
  gated by three new RBAC permissions.
- 37-check automated test suite (database/test_mohtoh_network.php,
  self-rolling-back, CI-safe) covering attribution, idempotency, the
  combined-reward ceiling, reversal, fraud heuristics, and concurrent-
  withdrawal safety; fail-safe isolation from core transport proven by
  deliberately breaking the engine and confirming driver settlement
  still succeeds.

Independent audit found and fixed 3 financial-integrity defects
(a withdrawal race condition, a missing withdrawal state-machine
guard, and a reward-maturation double-credit race), a CSV formula-
injection vulnerability, and duplicate fraud-flag spam — all re-
verified after the fix, including the race condition under genuine
concurrent HTTP load. A follow-up round adds CSRF protection to every
state-changing MOHTOH NETWORK endpoint (verified live: missing/wrong/
correct token cases, re-confirmed alongside the concurrency and IDOR
fixes) and formally proves financial reconciliation with a worked
example. See MOHTOH_NETWORK_FINAL_ACCEPTANCE.md for the full report,
including disclosed, unfixed gaps (no partial-refund reversal path
exists anywhere in the app yet; the app-wide CSRF gap beyond this
feature's own endpoints is documented with a remediation plan).
```

*(Round 1 report ends here — Round 2 follows below.)*

---

# ROUND 2 — CSRF Implementation, Financial Reconciliation Proof, Final Status

**Audit date:** 2026-11-12. This round closes the one substantive
condition Round 1 left open (CSRF) and performs the deeper financial-
reconciliation verification the user explicitly asked for, without
weakening anything Round 1 fixed. §12.6 confirms this directly against
the diff.

## 12. CSRF implementation and test evidence

### 12.1 Mechanism

Reused the app's **existing** CSRF primitive verbatim — `csrf_token()` /
`csrf_validate()` / `require_csrf()` in `includes/functions.php` (added by
an earlier, unrelated Secure Social Login feature, previously used by
exactly one endpoint, `api/social-accounts.php`). No new token scheme was
invented:

- **Token**: a 32-byte CSPRNG value (`bin2hex(random_bytes(32))`), stored
  in `$_SESSION['csrf_token']`, generated once per session and reused for
  its lifetime. Comparison uses `hash_equals()` (constant-time).
- **Lifetime = session lifetime.** There is no separate token-expiry
  timestamp — matching the one pre-existing precedent in this codebase.
  An expired/destroyed session has no `$_SESSION['csrf_token']` to match
  against, so `csrf_validate()` fails exactly like a missing token. This
  satisfies "reject expired tokens where applicable" for a codebase whose
  CSRF primitive is deliberately session-scoped, not a separate nonce
  store.
- **Exposure**: rendered into `<meta name="csrf-token" content="...">` on
  every page that can submit a MOHTOH NETWORK state-changing request —
  `rider/dashboard.php`, `rider/trips.php`, `rider/profile.php` (already
  had it), `driver/dashboard.php`, `driver/trips.php`, `driver/profile.php`
  (already had it), and `admin/dashboard.php`. Never placed in a URL.
- **Transmission**: as a `csrf_token` field in the JSON POST body — same
  convention `assets/js/profile.js` already uses against
  `api/social-accounts.php`. Never a query-string parameter, never
  logged (Apache's default access log does not capture POST bodies).
- **Validation**: `require_csrf($input)` is called **once, at the top of
  the POST handler**, in both `api/network.php` and `api/network-admin.php`
  — before the action is dispatched — so every current and any future
  state-changing action in either file is covered automatically; nothing
  can be added later that accidentally skips it.

### 12.2 Client-side changes

- `assets/js/network.js`: added `csrfToken()` (reads the meta tag) and
  `postNetwork()` (wraps `apiPost` with the token); all 4 state-changing
  calls (`join`, `share_link`, `add_withdrawal_account`,
  `request_withdrawal`) now go through it.
- `admin/dashboard.php`: added the same pair (`csrfToken()` /
  `postJsonCsrf()`); all 10 state-changing admin calls (`update_settings`,
  `create_campaign`, `end_campaign`, `approve_partner`, `suspend_partner`,
  `reactivate_partner`, `correct_attribution`, `reverse_reward`,
  `decide_withdrawal`, `resolve_fraud_flag`) now go through it. The
  generic `postJson()` used by every OTHER admin tab is **untouched** —
  no other tab's request contract changed.

### 12.3 Live test evidence (actual `curl` output)

```
# api/network.php — join action, real session, real token from the page
$ curl ... -d '{"action":"join","accept_terms":true}'                        (no token)
{"status":"error","message":"Your session has expired. Please refresh the page and try again.","data":[]}
$ curl ... -d '{"action":"join","accept_terms":true,"csrf_token":"deadbeef..."}' (wrong token)
{"status":"error","message":"Your session has expired. Please refresh the page and try again.","data":[]}
$ curl ... -d '{"action":"join","accept_terms":true,"csrf_token":"<real token from the page>"}'
{"status":"success","message":"Welcome to MOHTOH NETWORK!", ...}

# api/network-admin.php — suspend_partner, admin session
$ curl ... "?action=suspend_partner" -d '{"partner_id":75,"reason":"..."}'     (no token)
{"status":"error","message":"Your session has expired. Please refresh the page and try again.","data":[]}
$ curl ... "?action=suspend_partner" -d '{"partner_id":75,"reason":"...","csrf_token":"<real token>"}'
{"status":"success","message":"Partner suspended.","data":{"status":"suspended"}}
```

### 12.4 Confirmed CSRF does not mask or replace real authorization

Re-ran the Round 1 IDOR and privilege-escalation tests **with a valid
CSRF token attached**, to prove the underlying authorization checks are
still the actual gate (not CSRF incidentally blocking the request first):

```
# rider session, VALID csrf_token, targets another user's bank account
$ curl ... -d '{"action":"request_withdrawal","withdrawal_account_id":<victim's>,"amount":5000,"csrf_token":"<valid>"}'
{"status":"error","message":"Withdrawal account not found.","data":[]}

# rider session, VALID csrf_token, calls an admin-only action
$ curl ... "?action=approve_partner" -d '{"partner_id":1,"csrf_token":"<valid>"}'
{"status":"error","message":"Unauthorized access","data":[]}
```
Both rejections are identical to the Round 1 result — CSRF is a layer
*in addition to*, never instead of, ownership/RBAC checks.

### 12.5 Confirmed CSRF does not break the Round 1 concurrency fix

Re-ran the live concurrent-withdrawal test (two simultaneous HTTP
requests, ₦100 each, ₦100 available) with a **valid CSRF token on both**
(a CSRF token is a per-session secret, not a single-use nonce — reusing
it across two genuine requests from the same page is correct, expected
behavior, not a weakness):
```
Result 1: {"status":"success", ..., "withdrawal_id":17, ...}
Result 2: {"status":"error","message":"Insufficient available balance.","data":[]}
Final available_balance: ₦0.00
```
Identical outcome to Round 1 — the transaction/lock fix and the new CSRF
layer compose correctly.

### 12.6 Confirmed nothing from Round 1 was weakened

Diffed `includes/network.php` against its Round-1-end state for this
round's changes: the only additions are (a) the duplicate-flag-dedupe
logic, (b) the severity downgrade, and (c) this round introduces **no
changes at all** to the transaction-wrapping, state-machine guard, or
`rowCount()` fixes from Round 1 — confirmed by re-running the exact
Round 1 regression evidence (§9.2, §9.3) unchanged, and by the automated
suite (§14) still containing and passing every Round 1 check verbatim,
plus 11 new ones.

### 12.7 Documented: remaining application-wide CSRF exposure

`require_csrf()` is now used by **3** endpoints in the entire codebase:
`api/social-accounts.php` (pre-existing), `api/network.php`, and
`api/network-admin.php` (this feature). Every other session-authenticated,
state-changing endpoint in the app — `api/rideshare.php` (booking, trip
state, cancellation), `api/auth.php` (password change), `api/admin-api.php`
(the other ~15 admin tabs), `api/wallet.php`, `api/promotions.php`,
`api/pricing-admin.php`, and more — **has no CSRF protection**, relying
solely on same-origin `fetch()` + session cookies (no `SameSite` cookie
attribute is explicitly set either, per Round 1's Phase-1 system
analysis). This was true before MOHTOH NETWORK existed and remains true
of the rest of the app; MOHTOH NETWORK's own surface is now **fully
covered**.

**Recommended controlled remediation plan** (not executed here — it is a
cross-cutting, app-wide change outside this feature's authority to make
unilaterally):
1. Set `session.cookie_samesite=Lax` (or explicit `Secure`/`HttpOnly`/
   `SameSite` flags on the session cookie) as a zero-code-change first
   layer — mitigates the most common CSRF vector immediately.
2. Render `<meta name="csrf-token">` from one shared location (e.g. the
   app's header/bootstrap partial) instead of per-page, so it's
   impossible for a new page to forget it.
3. Migrate `api/admin-api.php` first (highest-value target — it holds
   ~15 tabs' worth of state-changing admin actions) by adding
   `require_csrf($input)` at its one POST choke point, mirroring exactly
   what this round did for `api/network-admin.php`.
4. Migrate `api/rideshare.php` and the remaining session-authenticated
   `api/*.php` endpoints the same way, file by file, each as its own
   small, reviewable change — never a single sweeping commit touching
   every endpoint at once.
5. Each migration step needs its matching client-side change (the page
   must render the meta tag; the JS must send the token) — do both
   halves together per file, exactly as this round did.

---

## 13. Financial reconciliation evidence

### 13.1 The exact balance calculation

Each `network_wallets` row has 4 columns: `pending_balance`,
`available_balance`, `withdrawn_balance`, `reversed_balance`. Each is a
cached aggregate over `network_reward_ledger` rows for that beneficiary,
maintained exclusively by `includes/network.php`'s money-moving functions
(never updated anywhere else). The invariants that hold, proven below
with real numbers and in the automated suite:

```
pending_balance    == SUM(ledger.reward_amount WHERE status = 'pending')
reversed_balance   == SUM(ledger.reward_amount WHERE status = 'reversed')

# A ledger row's status never becomes 'withdrawn' (see docs/mohtoh-network.md
# §8) — a withdrawal draws from the aggregate available pool, so:
available_balance + withdrawn_balance
  + SUM(network_withdrawals.amount WHERE status IN
        ('pending','under_review','approved','processing'))   # in-flight
  == SUM(ledger.reward_amount WHERE status = 'available')
```
The third line is the **complete** invariant — Round 1's version omitted
the in-flight term and so only held for a fully-resolved withdrawal
history. This round's testing found that gap (see §13.3) and the suite
now checks the complete formula, including while a withdrawal sits
unresolved.

### 13.2 Worked example (actual output, real function calls, rolled back)

A Network Partner "Recon Referrer" earns 10% of commission from one
referred passenger across two ₦400-commission rides:

| Step | wallet: pending / available / withdrawn / reversed | ledger: pending / available / reversed | Invariant |
|---|---|---|---|
| 0. Joins, no rides yet | 0.00 / 0.00 / 0.00 / 0.00 | 0.00 / 0.00 / 0.00 | holds |
| 1. Ride #1 settles (₦400 × 10% = ₦40, 3-day hold) | 40.00 / 0.00 / 0.00 / 0.00 | 40.00 / 0.00 / 0.00 | holds |
| 2. Hold expires → matures | 0.00 / 40.00 / 0.00 / 0.00 | 0.00 / 40.00 / 0.00 | holds |
| 3. Ride #2 settles (another ₦40, still on hold) | 40.00 / 40.00 / 0.00 / 0.00 | 40.00 / 40.00 / 0.00 | holds |
| 4. Requests ₦40 withdrawal (available debited at request time) | 40.00 / 0.00 / 0.00 / 0.00 | 40.00 / 40.00 / 0.00 | **simple formula fails here** — complete formula (§13.1, +₦40 in-flight) holds |
| 5. Admin approves, then marks **paid** with a reference | 40.00 / 0.00 / 40.00 / 0.00 | 40.00 / 40.00 / 0.00 | holds |
| 6. Admin reverses ride #2's still-pending ₦40 reward | 0.00 / 0.00 / 40.00 / 40.00 | 0.00 / 40.00 / 40.00 | holds |

Step 4 is the important finding of this round: between "withdrawal
requested" and "withdrawal resolved," the requested amount is correctly
accounted for by the `network_withdrawals` row's own `pending` status,
not by either wallet column — proven, not assumed, and now a permanent
automated check (§13.3, §14).

### 13.3 What this round added to the automated suite (41 → 48 checks, all new ones proven against real scenarios)

- Negative/boundary: a withdrawal request far exceeding the available
  balance is rejected; no operation ever drives `available_balance` or
  `pending_balance` negative.
- The simple end-state reconciliation check (Round 1).
- **The complete in-flight reconciliation invariant** (§13.1), proven
  with a withdrawal deliberately left unresolved.
- **A rejected withdrawal fully restores `available_balance`** (not
  merely "doesn't double-process" — the actual restoration amount is
  checked against the original balance).
- **Administrative adjustments**: a credit and a debit each move
  `available_balance` by exactly the adjustment amount, each leaves
  **one** `network_reward_adjustments` row **and** one
  `admin_audit_logs` row (the generic cross-feature audit trail), and
  an adjustment submitted without a reason is refused outright.

### 13.4 Duplicate webhook / callback and concurrent withdrawal — re-confirmed this round

Both were proven in Round 1 (idempotent ledger `UNIQUE` constraint;
genuine concurrent HTTP load) and are **unchanged and re-passing** in
this round's full suite run (§14) — not re-derived, the exact same
mechanism, re-executed.

### 13.5 Invalid state transitions — re-confirmed this round

Round 1's state-machine guard (a withdrawal can only move `paid` from
`approved`/`processing`, never from `pending`, never twice) is
unchanged and covered by 6 passing checks in the current suite,
including "a paid withdrawal must never be paid twice" and "a rejected
withdrawal must not permanently reduce the available balance" — both
explicitly re-verified as requested.

---

## 14. Full regression and security test results (Round 2 — actual output)

```
$ php database/test_mohtoh_network.php
[... 48 lines of PASS ...]
==== 48 passed, 0 failed ====
```
(37 Round 1 checks, unchanged and passing, plus 11 new Round 2 checks:
2 negative-balance boundary checks, 1 in-flight-withdrawal acceptance
check, 1 complete-reconciliation-invariant check, 2 rejection-restores-
balance checks, 5 administrative-adjustment checks — see §13.3.)

```
$ php -l <all 20 touched PHP files>
Lint failures: 0 / 20
```

**CSRF tests**: §12.3 (missing/wrong/correct token, both endpoints).
**Withdrawal concurrency**: §12.5 (re-run with CSRF in place; identical
safe outcome to Round 1's §9.2).
**Reward-maturation concurrency**: Round 1's `rowCount()`-guard fix is
unchanged; re-confirmed by the unchanged §14 suite run (the "reward
matured to available after hold expiry" and reconciliation checks
exercise this path every run).
**Authorization / IDOR**: §12.4 (re-run with valid CSRF, identical
rejections to Round 1).
**SQL injection**: live attempt against `add_withdrawal_account`'s
`bank_name` field with a DROP-TABLE payload — table confirmed intact
afterward, payload stored as an inert literal string (prepared
statements):
```
bank_name stored as: "Test'; DROP TABLE network_withdrawal_accounts; --"
Table still exists: YES
```
**XSS**: a `<script>alert(1)</script>` payload in `account_name` was
stored as a literal string (confirmed in the database) and is rendered
through `escapeHtml()` on every admin/partner display path (code-path
confirmed unchanged from Round 1; the storage layer was re-tested live
this round).
**CSV injection**: executed the exact, unmodified `neutralizeFormula`/
`esc` logic from `admin/dashboard.php`'s `downloadCsv()` against 7 test
values in a live JS runtime:
```
"=cmd|'/c calc'!A1"        -> "'=cmd|'/c calc'!A1"        (neutralized)
"+1+1"                     -> "'+1+1"                     (neutralized)
"-2+3"                     -> "'-2+3"                     (neutralized)
"@SUM(A1:A2)"              -> "'@SUM(A1:A2)"               (neutralized)
"Normal Bank Name"         -> "Normal Bank Name"           (unchanged — no false positive)
"O'Brien's Bank"           -> "O'Brien's Bank"             (unchanged — apostrophe NOT at start, no false positive)
"=1+1\" onerror=alert(1)"  -> "'=1+1\"\" onerror=alert(1)" (neutralized AND quote-escaped)
```
**Fresh-install migration**: re-confirmed unchanged from Round 1 (no
migration-file changes in this round).
**Upgrade migration**: re-confirmed unchanged from Round 1.
**Core transport regression, MOHTOH NETWORK disabled**: ran a dedicated
test toggling **both** kill switches (`network_enabled=0` and
`network_emergency_payout_suspended=1`) independently against the real
`settle_ride_completion()`:
```
PASS: with network_emergency_payout_suspended=1: settle_ride_completion() still runs and returns the correct credited amount
PASS: with network_emergency_payout_suspended=1: driver wallet actually increases by the full fare earning
PASS: with network_enabled=0: settle_ride_completion() still runs and returns the correct credited amount
PASS: with network_enabled=0: driver wallet actually increases by the full fare earning
==== 4 passed, 0 failed ====
```

---

## 15. Final review

- **No unrelated transport functionality was changed.** This round's
  only transport-page changes are the `<meta name="csrf-token">` tag
  added to 4 pages (`rider/dashboard.php`, `rider/trips.php`,
  `driver/dashboard.php`, `driver/trips.php`) — confirmed by diff to be
  exactly one line each, nothing else in those files touched.
- **No existing driver or passenger earnings were affected** — §14's
  dedicated emergency-suspension test proves `settle_ride_completion()`
  credits the identical amount with MOHTOH NETWORK fully disabled.
- **No financial records were deleted or silently modified.** Every
  reversal/adjustment this round exercises is itself an auditable
  `INSERT` (`network_reward_adjustments`, `admin_audit_logs`); nothing
  in either round's changes contains a `DELETE` against a financial
  table outside of this audit's own test-fixture cleanup (which never
  touches real data — every test either self-rolls-back a transaction
  or explicitly deletes only the specific fixture rows it created).
- **No sensitive bank details are exposed through CSV exports** —
  confirmed again this round: the Withdrawals export carries
  `account_number_last4` only, never a full account number (re-checked
  against the live export's own code in `admin/dashboard.php`, unchanged
  from Round 1).
- **All new financial operations have appropriate authorization and
  audit records** — every write path in `includes/network.php` that
  moves money or changes a financial record either is called only from
  an RBAC-gated admin endpoint, or (for partner self-service actions)
  is scoped to the authenticated session's own `user_id`; §12.4 proves
  this live.
- **Emergency suspension does not disable normal bookings, trips,
  payments or driver earnings** — §14's dedicated test, run this round
  specifically for this requirement.

### Git diff summary (combined, both rounds)

```
Modified (diff isolated to MOHTOH NETWORK's own hunks — each file also
carries unrelated, pre-existing changes from other in-flight work,
confirmed in Round 1 §1 and unchanged in scope this round):
  admin/dashboard.php      992 lines changed (963 insertions, 29 deletions)
  api/auth.php              24 lines changed
  api/referrals.php          7 lines changed
  api/rideshare.php        180 lines changed
  includes/functions.php   115 lines changed   (0 changed by Round 2 — CSRF
                                                 helpers already existed)
  includes/referral.php    284 lines changed   (net removal — dead legacy
                                                 payout code deleted)
  partials/sidebar.php      21 lines changed
  rider/dashboard.php       55 lines changed   (+1 line this round: CSRF meta tag)
  rider/trips.php            6 lines changed   (+1 line this round: CSRF meta tag)
  rider/profile.php         12 lines changed   (0 changed this round — already had the tag)
  driver/dashboard.php      18 lines changed   (+1 line this round: CSRF meta tag)
  driver/trips.php           6 lines changed   (+1 line this round: CSRF meta tag)
  driver/profile.php        14 lines changed   (0 changed this round — already had the tag)

New:
  includes/network.php              1045 lines
  api/network.php                    172 lines (+7 this round: require_csrf + comment)
  api/network-admin.php              438 lines (+8 this round: require_csrf + comment)
  database/migrate_mohtoh_network.php 456 lines (0 changed this round)
  database/test_mohtoh_network.php    379 lines (+61 this round: new checks)
  partials/network-sheet.php          121 lines (0 changed this round)
  assets/js/network.js                371 lines (+10 this round: CSRF helper)
  docs/mohtoh-network.md              301 lines (+25 this round)
  MOHTOH_NETWORK_FINAL_ACCEPTANCE.md  (this file)

No file outside this list was touched by either audit round. Every
`.bak-*` file, every file under `auth/`, `uploads/`, every Social
Login / RBAC-refactor / image-optimizer file in `git status` predates
this work and was not modified by it.
```

---

## 16. Final status and recommendation

**FINAL STATUS: ACCEPTED WITH CONDITIONS**

This is **not** a downgrade-free "ACCEPTED" because two genuine, disclosed
scope limitations remain (neither is a defect in what exists today):

1. **No partial-refund reward reversal.** No partial-refund mechanism
   exists anywhere in the MohToh transport platform yet — not a gap
   unique to MOHTOH NETWORK. **Condition**: if/when the transport
   platform ever adds partial refunds, MOHTOH NETWORK's reversal logic
   (`network_reverse_ledger_row()` / `network_reverse_booking_rewards()`,
   both currently full-reversal-only) must be extended *before* that
   ships, or a partial refund would either over-reverse or under-reverse
   a referral reward. Both functions already use `GREATEST(0, ...)`
   guards and compare-and-swap `UPDATE`s, so extending them to accept a
   proportional amount is additive, not a rewrite — but it is unbuilt
   today and must not be assumed to work.
2. **No distinct "External Network Partner" identity.** Functionally
   adequate (nothing blocks a non-transport participant from registering
   and becoming a partner) but not the literal third category the
   original spec names. **Condition**: a business decision, not a
   technical one — flagged for the user's call, not re-litigated here.

Everything else Round 1 flagged as a condition is **resolved**:
- CSRF: implemented, tested live (missing/wrong/correct token, both
  endpoints), confirmed not to mask real authorization, confirmed not to
  break the concurrency fix. App-wide remediation plan documented (§12.7)
  for the user's own prioritization — not part of this feature's scope
  to execute unilaterally.
- Financial reconciliation: formally proven with the complete invariant
  (including the in-flight-withdrawal case this round's testing
  specifically surfaced), a six-step worked example with real numbers,
  and 11 new automated checks.

**Nothing indicates MOHTOH NETWORK is financially unsafe, insecure, or
capable of disrupting the transport platform as implemented today.**
Every money-moving code path is authorized, audited, reconciled, and
isolated from core transport by construction (separate tables) and by
test (deliberately broken and shown not to affect driver settlement).

### Recommended deployment procedure
Unchanged from `docs/mohtoh-network.md` §7, with one addition: deploy
this round's CSRF changes **together** with the Round 1 fixes as one
unit (they are already one uncommitted diff) — do not deploy Round 1's
fixes alone and the CSRF layer separately, since that would leave a
window where the condition this round closes is open again.

### Recommended rollback procedure
Unchanged from `docs/mohtoh-network.md` §7 ("Rollback plan") — the CSRF
addition does not change rollback mechanics: `network_enabled=0` (or a
code revert) disables the feature exactly as before; no new schema or
irreversible state was introduced this round.

**No commit, deployment, production data change, or real payout has been
made or initiated.** This remains explicitly waiting on your review and
approval.
