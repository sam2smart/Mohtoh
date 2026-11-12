# MOHTOH NETWORK

**Share the Journey. Create Opportunity.**

A direct-referral, revenue-share programme layered onto the MohToh transport
platform. Registered drivers and passengers can opt in as **Network
Partners**, get a referral code/link/QR code, and earn a percentage of
MohToh's commission on qualifying rides completed by people they directly
referred — for a limited earning window, with no multi-level payouts.

This document is the implementation record, administrator guide, and
deployment/rollback plan in one place (this project's `docs/` convention is
one markdown file per feature area — see `docs/maui-integration.md`).

---

## 1. Relationship to "MohToh Community Rewards"

MohToh already had a one-level referral system (`includes/referral.php`,
tables `referrals` / `referral_events` / `referral_rewards`) that paid a
**flat, one-time ₦ amount** into a user's main wallet the moment a referral
first qualified. **MOHTOH NETWORK replaces it entirely** — it is
mechanically different (a recurring **% of commission**, per qualifying
ride, for a 90-day window, into a **separate** wallet with its own
withdrawal flow) and the two could not coexist without confusing users with
two referral codes.

- The legacy tables are **untouched** — financial history is never deleted.
  They were backfilled into `network_referrals` (status `legacy_migrated`)
  for continuity, with **no active earning window**, so migrating history
  never creates new, unexpected reward liability.
- `includes/referral.php`'s `create_referral_relationship()` and
  `track_referral_event()` now **delegate** into `includes/network.php`.
  This means registration, verification, ride-creation, and trip-completion
  call sites across the app (`api/auth.php`, `includes/verification.php`,
  `includes/functions.php`, `api/rideshare.php`, `api/admin-api.php`,
  `api/v1/driver/create-ride.php`) feed MOHTOH NETWORK **without having
  been individually modified**.
- The old admin "Community Growth" tab and `api/referral-admin.php` still
  render but no longer generate anything — they're read-only history at
  this point. Candidate for retirement in a future cleanup pass.

---

## 2. Architecture

```
Registration (api/auth.php)
  -> network_attribute_referral()          [includes/network.php]
       creates network_referrals row (status=registered)

Verification / first ride (existing call sites, unchanged)
  -> network_track_milestone()             [includes/network.php]
       registered -> verified -> active (earning window opens,
       attribution_locked_at set — no further reassignment allowed)

Ride settlement (includes/functions.php settle_ride_completion(),
api/rideshare.php confirm_direct_payment)
  -> network_settle_booking_reward($bookingId)   [includes/network.php]
       idempotent (UNIQUE booking_id+beneficiary+role), fails safe
       (try/catch around every call site — never affects the booking
       or payment it's attached to)
       -> writes network_reward_ledger row (status=pending, holds N days)
       -> updates network_wallets (pending_balance)
       -> runs fraud heuristics (section 5)

Refund / cancellation (pre-completion only — see note below)
  -> network_reverse_booking_rewards()

Admin (admin/dashboard.php "MOHTOH NETWORK" tab, api/network-admin.php)
Partner (partials/network-sheet.php, api/network.php)
```

**Why rewards can only be reversed by admin action today:** the booking
state machine never lets a `completed` booking move back to `cancelled`
(see `api/rideshare.php`'s `cancel_booking`/admin-cancel/auto-expire
actions — all guarded by `status NOT IN ('cancelled','completed')`), so a
rewarded booking can never hit the automatic refund path. `includes/
network.php`'s `network_reverse_ledger_row()` (admin-triggered, single
reward) and `network_reverse_booking_rewards()` (system-triggered, whole
booking) both exist and are tested, ready for whatever post-completion
dispute/refund flow this app adds in the future.

### Reused, not duplicated
`payments.commission_amount` is the only source of "realized MohToh
commission" (never recomputed). `users.referred_by` / `is_network_partner`
follow the same capability-flag pattern as `is_admin`. `create_notification()`,
`generate_unique_public_id()`, `log_admin_action()`, `encrypt_secret()` /
`decrypt_secret()`, and the admin dashboard's "Universal Pagination"
(`paginate_params()`/`paginate_meta()`/`renderPagination()`) are all reused
as-is — no parallel versions were created.

### New database tables
`network_partners`, `network_referrals`, `network_campaigns`,
`network_reward_ledger`, `network_wallets`, `network_withdrawal_accounts`,
`network_withdrawals`, `network_reward_adjustments`, `network_fraud_flags`
— see `database/migrate_mohtoh_network.php` for exact columns. Everything
else (settings, audit log, partner-management audit trail, notifications)
reuses existing tables (`system_settings`, `pricing_change_logs`,
`admin_audit_logs`, `notifications`).

---

## 3. Independent switching (never breaks transport)

Every toggle lives in `system_settings` under a `network_*` key, read
through `network_setting()` (which wraps the existing `get_system_setting()`
— same try/catch-and-fall-back-to-default behavior, so even a missing
settings row never throws). Every money-moving function in
`includes/network.php` is wrapped at its call site in a `try/catch` that
only logs on failure — a MOHTOH NETWORK bug can never interrupt a booking,
trip, or payment.

| Setting | Effect when off |
|---|---|
| `network_enabled` | Whole programme off; also implied by `network_maintenance_mode` |
| `network_registrations_enabled` | New partner sign-ups blocked; existing partners unaffected |
| `network_links_enabled` | New attributions blocked; existing referrals keep earning |
| `network_rewards_enabled` | No new rewards generated; everything else (partners, history) still visible |
| `network_withdrawals_enabled` | No new withdrawal requests; existing ones still admin-processable |
| `network_emergency_payout_suspended` | Same as rewards off, but framed as the spec's "emergency switch" — use this one for an incident |
| `network_driver_referrals_enabled` / `network_passenger_referrals_enabled` / `network_external_partners_enabled` | Per-category kill switches |

Suspending or reactivating a Network Partner (`api/network-admin.php`
`suspend_partner`/`reactivate_partner`) **never** touches their ordinary
rider/driver account — confirmed by `network_admin_suspend_partner()` only
writing to `network_partners`.

---

## 4. Administrator guide

**Admin Dashboard → MOHTOH NETWORK** (visible to any admin holding the
`network.view` permission; see `includes/admin_permissions.php`'s new
"MOHTOH NETWORK" group).

| Sub-tab | What it's for | Who can act |
|---|---|---|
| Overview | Live totals: partners, active referrals, reward liability, net contribution (realized commission − rewards) | Read-only for everyone with `network.view` |
| Settings | Every toggle/percentage/budget/fraud-threshold in section 3, plus Campaigns (time-boxed override reward %) | **Super Admin only** — same convention as Pricing/Promotions/Commission |
| Partners | Search, approve/suspend/reactivate a Network Partner | `network.partners.manage` (Network Manager role) |
| Referrals | The full referral register; "Reassign" corrects attribution (refused once the referral is past its first qualifying ride — see section 2) | `network.partners.manage` |
| Rewards | The reward ledger; manual reversal of one specific reward (always with a reason, always audited) | `network.finance.manage` (Finance Administrator role) |
| Withdrawals | Approve / reject / mark processing / **mark paid (requires a payment reference — never optional)** / mark failed | `network.finance.manage` |
| Fraud | Review flags raised by the heuristics in section 5; resolve or dismiss with notes | `network.finance.manage` |

Every list has an **Export CSV** button (client-side, no server round trip
beyond the existing list endpoint) for the "downloadable reports" the spec
asks for.

Role mapping to the spec's section 12: **Super Administrator** = full
config/campaigns (not grantable — hardcoded `require_super_admin()`, same
as pricing). **Finance Administrator** = `network.finance.manage`.
**Network Manager** = `network.partners.manage`. **Support Administrator**
= `network.view` alone (read-only investigation).

---

## 5. Fraud detection

Every check only **flags** (`network_fraud_flags`, reviewable in the admin
Fraud tab) — nothing here auto-blocks a referral or auto-confiscates a
reward, per the spec's explicit instruction. An admin always decides.
Flags are deduplicated: an ongoing pattern (e.g. a partner referring 20
people past the velocity threshold) stays ONE open flag, refreshed with
the latest evidence, not one row per occurrence — see
`network_flag_fraud()`.

| Flag type | Raised when | Where |
|---|---|---|
| `shared_contact_at_referral` | Referrer and referred share a phone or email at registration | `network_attribute_referral()` |
| `referral_velocity` | A partner creates more than `network_fraud_velocity_max_per_day` new referrals in 24h | `network_check_attribution_fraud()` |
| `shared_ip_multiple_referrals` (severity `medium` — a shared IP alone is common and often innocent, never proof of fraud on its own) | The same partner's referrals include two+ registrations from the same IP | `network_check_attribution_fraud()` |
| `coordinated_both_sides` | One partner directly referred **both** the driver and the passenger on the same booking | `network_settle_booking_reward()` |
| `repeated_pair_rewards` | The same driver+rider pair generates more than `network_fraud_same_pair_max_rewards` rewarded bookings for one beneficiary | `network_check_repeated_pair_fraud()` |
| `budget_exceeded` | A reward was skipped because a configured budget cap was hit | `network_settle_booking_reward()` |

**Not implemented** (would need infrastructure this app doesn't have yet,
and is arguably general transport-fraud scope rather than referral-specific):
device fingerprinting beyond IP, cancellation-rate analysis, and
general "artificial booking activity" detection across the whole ride
graph. Flagged here as a known gap rather than quietly skipped.

---

## 6. Testing

`database/test_mohtoh_network.php` is a standalone, CI-friendly script
(same convention as `database/test_private_pickup_pricing.php`) covering
attribution, direct-referral-only, milestone progression, attribution
locking, per-transaction reward math, **idempotency under duplicate
settlement calls**, the combined-reward ceiling, reversal, all five fraud
heuristics, earning-window maturation, the full withdrawal lifecycle
(including "never mark paid without a reference"), and the module
on/off switch. It runs entirely inside one transaction that is **always
rolled back**, so it's safe to run against a live/shared database:

```bash
php database/test_mohtoh_network.php
```

Exits `0` on success, `1` on any failed check, and prints every assertion.
28/28 passing as of this writing.

**Manually verified live in a browser** during development (not part of
the automated suite): the full partner journey — register, join
(pending), admin-approve, pull a working QR code and share link, a second
person registering through that link with correct attribution recorded —
and the full admin settings round-trip with its audit-log entry.

---

## 7. Deployment

1. **Back up the database** before running any migration against
   production (standard practice — this migration is additive/idempotent
   but a backup costs nothing).
2. Run the migration:
   ```bash
   php database/migrate_mohtoh_network.php
   ```
   Safe to re-run — every step self-heals via `SHOW TABLES`/`SHOW COLUMNS`
   checks, same as every other `migrate_*.php` in this project. It:
   - adds `users.is_network_partner`
   - creates the 9 new tables
   - seeds ~32 `network_*` settings (only if absent — never overwrites an
     admin's existing configuration on re-run)
   - backfills legacy `referrals` into `network_partners`/`network_referrals`
     (history only, no active earning windows — see section 1)
3. Run the test suite (`php database/test_mohtoh_network.php`) against the
   target database before considering the deploy complete.
4. Deploy the application code (no build step — plain PHP/JS, same as the
   rest of this app).
5. **New programme defaults to live but conservative**: registrations/links/
   rewards/withdrawals all `enabled=1`, but `network_partner_auto_approve=0`
   (new partners start `pending`, requiring an admin to approve them) and
   `network_min_withdrawal=2000`. Review `docs/mohtoh-network.md` section 3
   and adjust in **Admin Dashboard → MOHTOH NETWORK → Settings** before
   announcing the programme publicly.

### Rollback plan

Nothing here is destructive to existing data, so rollback is just
"stop using the new code paths":

- **Fastest, zero-deploy rollback:** set `network_enabled=0` (and/or
  `network_emergency_payout_suspended=1`) in **Admin Dashboard → MOHTOH
  NETWORK → Settings**. Every reward/attribution function fails safe and
  no-ops; transport is completely unaffected (section 3).
- **Code rollback:** revert the commit(s). The new tables stay in the
  database (harmless, inert) — nothing in the pre-MOHTOH-NETWORK codebase
  references them. `includes/referral.php`'s delegation would need
  reverting too if you want the OLD flat-reward system to resume paying
  (it currently delegates to the new engine instead of its old body).
- **Full schema rollback** (only if truly necessary — this discards the
  referral/reward history the programme has generated): drop the 9
  `network_*` tables and the `users.is_network_partner` column. The
  legacy `referrals`/`referral_events`/`referral_rewards` tables are
  untouched throughout and need no rollback of their own.

---

## 8. Known gaps / suggested next phases

- The old "Community Growth" admin tab and `assets/js/referrals.js` /
  `partials/community-rewards-sheet.php` are now dead code (no live page
  includes them) — candidates for moving into `_legacy_archive/`.
- No automated payout rail: withdrawals are admin-marked-paid after a
  manual bank transfer (the spec explicitly allows this — "use existing
  payment integrations where possible," and this app's Paystack
  integration is wired for top-ups, not payouts).
- Campaign management (time-boxed reward-% overrides) has basic
  create/end in the admin UI; no edit-in-place or campaign-level
  analytics yet.
- Fraud detection is referral-scoped by design (section 5) — general
  ride-fraud detection (fake bookings, rating manipulation, etc.) is a
  platform-wide concern outside MOHTOH NETWORK's remit.
- `network_launch_at` / `network_closes_at` are stored but not yet
  enforced automatically (no scheduled task currently gates registration/
  rewards by date — would need this app's first cron-style mechanism).
- `network_reward_ledger.status` never reaches `'withdrawn'` or
  `'suspended'` — a withdrawal draws from the wallet's aggregate
  available pool, not a traceable per-reward queue (see
  MOHTOH_NETWORK_FINAL_ACCEPTANCE.md §5). The invariant that actually
  holds is `available_balance + withdrawn_balance == SUM(ledger rows
  where status='available')`, not `available_balance` alone.
- Partial-refund proportional reward reversal is not implemented — no
  partial-refund mechanism exists anywhere in this app yet to trigger it.

## 9. Independent audit (2026-11-11/12)

A full independent verification pass (SQLi/XSS/CSRF/IDOR, concurrency,
migration fresh-install/upgrade, CSV security, duplicate-fraud-flag
behavior) found and fixed 3 real financial-integrity defects — a
withdrawal race condition (re-proven safe under genuine concurrent HTTP
load), a missing withdrawal state-transition guard, and a reward-
maturation double-credit race — plus a CSV formula-injection
vulnerability and fraud-flag spam. Full findings, every command actually
run, and the disclosed outstanding gaps are in
`MOHTOH_NETWORK_FINAL_ACCEPTANCE.md` at the project root. The automated
suite grew from 28 to 37 checks as a result.

A follow-up round (same file, §12 onward) added **CSRF protection** to
every state-changing endpoint in `api/network.php` and
`api/network-admin.php` — reusing this app's existing `csrf_token()`/
`require_csrf()` primitive (previously used by exactly one other
endpoint), verified live with missing/wrong/correct-token cases on both
files, and confirmed not to mask the IDOR/RBAC checks or interfere with
the concurrency fix. It also formally proved the financial reconciliation
invariant with a six-step worked example — surfacing one previously
undocumented nuance: between a withdrawal being *requested* and being
*resolved* (paid/rejected), the requested amount is correctly tracked by
the `network_withdrawals` row itself, not by `available_balance` or
`withdrawn_balance` — so the complete reconciliation formula includes
that in-flight term (see the report's §13.1). The suite grew to 48
checks. Remaining app-wide CSRF exposure (every other session-
authenticated endpoint in this codebase) is documented with a phased
remediation plan in the report's §12.7 — it predates this feature and
is unchanged by it.
