# MOHTOH NETWORK — External Partner Support: Technical Proposal & Impact Assessment (Phase 5)

**Status: APPROVED AND IMPLEMENTED (Option B).** The proposal below was presented and approved before any code was written, per the assignment's own instruction. Section "Implementation record" at the end covers what was actually built and tested.

## The problem, precisely

`users.user_type` is `ENUM('rider','driver','admin')` (confirmed in `database/migrate.php`). There is no fourth value, and none is reachable through any registration path today. `system_settings.network_external_partners_enabled` exists, is shown as a toggle in the admin Network Settings tab, and is read by a real function (`includes/network.php: network_category_enabled($pdo, 'external')`) — but that function is **never actually called with `'external'`** anywhere in the codebase; every real call site passes `'driver'` or `'passenger'`, because `network_referrals.referred_category` is itself `ENUM('driver','passenger')`. The toggle is wired to a dead code path: flipping it does nothing, in either direction. Someone built the plumbing for this feature and never connected the water.

A person who is not a driver or a rider — a business owner, a community organizer, a social media influencer — cannot create an account at all today, so they cannot become a Network Partner, cannot get a referral code, and cannot refer anyone.

## What "external partner" actually needs, re-read carefully

Re-reading the spec's own list: an external partner is a **referrer**, never a **referred person**. They register, get a referral code, refer drivers and passengers (who are the ones being referred — `network_referrals.referred_category` stays exactly `driver`/`passenger`, unchanged), and receive rewards for those referrals' qualifying activity. They never need to book a ride or drive one. This matters: it means the "referred_category" ENUM needs no change at all — only the question of **who is allowed to hold a `network_partners` row and a login** changes.

## Two architectures considered

### Option A — Add `'partner'` to the `users.user_type` ENUM

A new first-class account type, its own `partner/dashboard.php`, mirroring how `admin` already coexists with `rider`/`driver` as a distinct, non-overlapping type.

**In favor**: semantically clean; `api/auth.php`'s login/register redirects (`$user_type . '/dashboard.php'`, used at exactly 2 lines) would work unchanged — no special-casing needed there, since they already generically build the path from whatever `user_type` is. Matches the existing per-role-folder convention exactly.

**Against**: 23 files in this codebase branch on `user_type === 'rider'|'driver'|'admin'` (counted directly, not estimated). Every one of them needs to be read to confirm it has no unsafe implicit "else must be rider-or-driver" assumption that a 4th value would silently fall through incorrectly. This is a bounded but real audit — not huge, but not zero, and exactly the kind of blast radius the assignment says not to touch casually. Some of this surface is itself security-relevant (session role gates), so a mistake here is not cosmetic.

### Option B — Reuse `user_type='rider'`, add a new boolean flag, route by flag

An external partner registers through a **separate, dedicated** signup page (not the existing rider/driver registration form), which creates an ordinary `users` row with `user_type='rider'` (the least-privileged existing type, no vehicle/driver fields to fill) plus a new column `users.is_external_partner` (default `0`). Login/registration redirect logic checks this flag **before** falling back to the existing `$user_type . '/dashboard.php'` pattern, sending them to a new `partner/dashboard.php` instead of `rider/dashboard.php`. A `network_partners` row is created for them immediately (same `network_attribute_referral`-adjacent machinery, auto-approved or pending per the existing `network_partner_auto_approve` setting), exactly as it already works for any driver/rider who joins.

**In favor**: the ENUM and all 23 existing branch points are **completely untouched** — they see `'rider'` exactly as before, so there is no new fallthrough case to audit for correctness anywhere outside the two redirect lines. Reuses essentially 100% of the existing Network Partner machinery (`network_partners`, `network_wallets`, `network_reward_ledger`, `network_withdrawal_accounts`, `network_withdrawals`, the whole of `api/network.php`) with zero schema change to any of it. The blast radius shrinks from "23 files to re-verify" to "2 redirect lines + 1 new flag column + 1 new signup page + 1 new dashboard page."

**Against**: an external partner's account is technically still `user_type='rider'`, so nothing stops them from also navigating straight to `rider/dashboard.php` and booking a ride — this is not a security problem (it's an account they legitimately own, doing something a rider account is designed to do), but it means admin reporting that counts "riders" should filter out `is_external_partner=1` rows if it wants a clean number. This is a one-line filter addition wherever such reporting exists, not a structural problem.

## Recommendation: Option B

It is simpler, has a far smaller and more precisely bounded impact surface, and reuses more of the already-audited, already-tested MOHTOH NETWORK infrastructure than Option A. Option A is not wrong, and could be revisited later if the product genuinely needs external partners to be a first-class, non-rider-shaped identity (e.g., a corporate account with multiple seats) — but that is not what the current spec asks for, and "prefer the simplest secure architecture that fits the existing application" points at B.

## What Option B concretely requires (not yet built)

1. **`users.is_external_partner TINYINT(1) NOT NULL DEFAULT 0`** — one new column, additive, same idempotent-migration pattern as every other MOHTOH NETWORK column.
2. **A dedicated signup page** (e.g. `join-partner.php` or a mode on the existing `join.php` referral-landing page) collecting fullname/email/phone/password only — no vehicle, no driver fields. Creates the `users` row with `user_type='rider'`, `is_external_partner=1`, and immediately calls the existing partner-join logic (same path `api/network.php`'s `join` action already uses for any rider/driver) to create their `network_partners` row.
3. **Two redirect-site changes** in `api/auth.php` (registration success, line ~188; login success, line ~248): check `is_external_partner` before falling back to `$user_type . '/dashboard.php'`.
4. **A new `partner/dashboard.php`** — thin, reuses `partials/network-sheet.php`'s content model (overview/referrals/rewards/withdrawals), but as the partner's *entire* home screen rather than a slide-over sheet layered on a transport app. No new backend endpoint needed — it calls the same `api/network.php` any rider/driver's network sheet already calls.
5. **Close the dead toggle**: wire `network_category_enabled($pdo, 'external')` into the join/registration path itself — if `network_external_partners_enabled` is off, the dedicated signup page refuses new external-partner registrations (existing ones are unaffected, same pattern as `network_registrations_enabled` already gates general signups). This makes the setting do something real in both directions, closing the exact gap the assignment flagged.
6. **Identity verification**: reuse the existing generic email/phone OTP verification (`includes/verification.php`) unchanged — it already has no driver/rider-specific assumption baked in.
7. **Payment verification — a genuine, pre-existing gap, not unique to external partners**: `network_withdrawal_accounts.verified_at` exists in the schema but is **never set or read by any code today**, for any partner, driver or rider. Proposed minimum: an admin must set `verified_at` (a simple admin action, same audit-logged pattern as `approve_partner`/`decide_withdrawal`) on a withdrawal account before its *first* withdrawal is approved; `network_admin_decide_withdrawal()`'s `approve` transition gains one additional check. This is worth doing regardless of external partners, and becomes more important once a referrer the platform has less contextual trust in (never drove, never rode) can request a payout.

## What this proposal does NOT do

- Does not touch `network_referrals.referred_category` (stays `driver`/`passenger` only — correct, since external partners are never the referred person).
- Does not touch `users.user_type`'s ENUM definition.
- Does not implement anything yet — items 1-7 above are the plan, pending approval.

## If approved

Phases 6-11 of this assignment (financial integrity, business-rule verification, testing, documentation) will need to account for whichever decision is made here — the business-rule verification doc in particular should re-confirm the external-partner toggle's behavior once it's wired up. Recommend approving Option B and this scope, or redirecting before Phase 6 proceeds on an assumption that turns out wrong.

## Implementation record (after approval)

All 7 items were built exactly as scoped above:

1. `database/migrate_network_external_partners.php` — new, idempotent (confirmed by running it twice; second run reports "Already exists"), adds `users.is_external_partner`. Kept as its own file rather than editing the already-shipped `migrate_mohtoh_network.php`.
2. `includes/network.php: network_join()` — now reads `is_external_partner` and classifies the category as `'external'` (not `'passenger'`) when set, so `network_external_partners_enabled` is finally the gate that actually fires — closing the exact dead-toggle gap this proposal exists to fix.
3. Registration (`api/auth.php`) — the existing `register` action accepts `user_type=external_partner` as a 4th form value (same shorthand-translation pattern already used for `'keke'`), mapping it to `user_type='rider'` + `is_external_partner=1`, and auto-calls `network_join()` immediately after account creation (never blocking registration if it refuses). `index.php`'s registration select gained a 4th option; `assets/js/auth.js` gained a matching hint string. No separate signup page was needed in the end — reusing the existing form via the same shorthand pattern `'keke'` already established turned out simpler than a dedicated page, while keeping the exact same backend validation path (password policy, phone/email checks, uniqueness) with zero duplication.
4. Three redirect sites now check `is_external_partner` before falling back to `$user_type . '/dashboard.php'`: registration success and login success (`api/auth.php`), and post-verification (`verify-account.php`, which the proposal's text didn't call out explicitly but is a real third site a registering partner can pass through).
5. `partner/dashboard.php` — new, reuses `partials/network-sheet.php` and `assets/js/network.js` entirely unchanged (forced into an always-open, non-dismissible state via a few lines of page-scoped CSS/JS rather than new markup), calling the same `api/network.php` any rider/driver partner already uses. Guards against a non-partner loading it directly (re-checks the DB flag, redirects to the correct role home otherwise).
6. The dead-toggle fix is item 2 above.
7. Withdrawal-account verification: `network_withdrawal_accounts.verified_at` is now actually read and enforced — `network_admin_decide_withdrawal()` refuses an `approve` decision on an unverified account (reject/processing/paid/failed are unaffected, since they never move money to a new, unverified destination). A new admin action (`network_admin_verify_withdrawal_account()`, wired into `api/network-admin.php` under the existing `network.finance.manage` permission, audit-logged the same way every other finance action is) is the only way to set it — no un-verify action, since downgrading trust on an account that already received money isn't a decision this console needs to support. The admin withdrawals table now shows a Verified/Unverified badge and a "Verify Account" button in place of "Approve" until that's done. The existing test suite's withdrawal-approval fixture needed one line added (`SET verified_at = NOW()`) to keep passing — this is the one place the new requirement touches already-shipped test code, and it was expected and deliberate, not a sign the change broke something.

**Verified, not just written** (temporary isolated PHP dev server, test accounts created and deleted within this session, never the live Apache tree or any out-of-session account):
- Registered a new `external_partner` account end-to-end over real HTTP → `users` row created with `user_type='rider'`, `is_external_partner=1`; a `network_partners` row was created automatically with status `pending` (auto-approve is off by default) and its own `NETWORK-`-prefixed referral code.
- Login redirect confirmed to send the partner to `partner/dashboard.php`, while an ordinary rider account hitting that same URL directly is redirected away to `rider/dashboard.php`.
- The partner dashboard page itself returns 200 and renders the expected `#networkSheet` markup and CSRF token.
- The withdrawal-verification gate: an `approve` decision on a real (fixture) withdrawal was refused with the expected message before verification, succeeded immediately after calling the new `verify_withdrawal_account` admin action, with no changes to any other decision path.
- Full 48-test suite re-run clean after every step above; all 9 touched/created files pass `php -l`.

**Not done in this pass**: no live/manual browser click-through of the new registration form's 4th option or the partner dashboard's visual appearance (all verification above was via direct HTTP/cURL and the test suite, not a rendered browser) — worth a quick visual pass before this ships. The admin UI's "Verify Account" button has no screenshot-level visual QA either.
