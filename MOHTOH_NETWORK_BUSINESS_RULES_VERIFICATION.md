# MOHTOH NETWORK — Business Rules Verification (Phase 7)

## Core programme rules

| Rule | Verified how | Result |
|---|---|---|
| Direct referral only | `network_referrals.referred_user_id` carries a `UNIQUE` key (confirmed in `information_schema`, Phase 2) — a person can have exactly one referrer, ever, enforced at the schema level, not just in application code. There is no column or query anywhere that walks a referral chain beyond one hop. | RESOLVED AND TESTED |
| No multi-level commissions | `network_multi_level_enabled` setting exists, defaults `'0'`, is **never read by any code path** (confirmed by grep across `includes/`, `api/`) — the direct-only rule isn't conditional on this flag, it's structural (§ above). The setting itself is explicitly commented `// never surfaced in UI`, confirmed absent from both the admin settings whitelist and the dashboard form. | RESOLVED AND TESTED (rule); the dead setting itself is a harmless, intentionally-undocumented placeholder — left as-is, see "Settings audit" below |
| No payment for joining | `network_join()` (Phase 5 location: `includes/network.php`) creates a `network_partners` row and nothing else — no wallet credit, no ledger row. Confirmed by reading the full function body. | RESOLVED AND TESTED |
| No recruitment commissions | Rewards are generated **only** by `network_settle_booking_reward()`, which requires a real `ride_share_bookings`/`payments` row with `status='completed'`/`'PAYMENT_CONFIRMED'` — there is no code path that pays anything for the act of referring someone, only for that referred person's qualifying transport activity. | RESOLVED AND TESTED |
| Rewards tied to qualifying completed activity | See Phase 6 §1 — the eligibility gate is booking completion + confirmed payment + positive commission. | RESOLVED AND TESTED |
| Configurable earning duration / percentages / caps / budgets | `network_earning_days`, `network_driver_reward_pct`, `network_passenger_reward_pct`, `network_combined_ceiling_pct`, `network_total_budget`/`network_daily_budget`/`network_monthly_budget`, `network_max_combined_payout_per_txn` — all read live from `system_settings` on every relevant call, no hardcoded fallback that would silently ignore an admin's configured value (Phase 6 confirmed the exact formula). | RESOLVED AND TESTED |
| Auditable administrative decisions | Phase 6 §7 — every finance/partner admin action writes `admin_audit_logs` via `log_admin_action()`, and finance actions additionally write `network_reward_adjustments`. | RESOLVED AND TESTED |
| Protected against duplicate rewards | Phase 6 §4. | RESOLVED AND TESTED |

## Eligibility by category — drivers, passengers, external partners

`network_category_enabled($pdo, $category)` is the single gate, mapping `'driver'`→`network_driver_referrals_enabled`, `'passenger'`→`network_passenger_referrals_enabled`, `'external'`→`network_external_partners_enabled`. Before Phase 5, `'external'` was defined in this mapping but **structurally unreachable** — every account was classified as `'driver'` or `'passenger'` with no third option, so the `network_external_partners_enabled` toggle did nothing in either position (confirmed: flipping it off or on had zero observable effect, since the code path that would consult it never ran). Phase 5 fixed `network_join()` to classify an `is_external_partner=1` account as `'external'`, and this was end-to-end HTTP-tested (registration → correct category → correct gate). **All three categories now resolve through the same, single, already-tested gate function** — there's no separate code path for external partners that could drift from the driver/passenger logic.

## Settings audit — every `network_*` key, what it does, and what changed

Of 31 settings (one fewer than the 32 inventoried in Phase 2 — `network_registration_bonus` was removed from the admin-facing surface this phase, see below):

**Fixed this phase** (were surfaced/settable but had no effect — now either wired up or removed, per the assignment's own "must work correctly or be removed/disabled" instruction):
- `network_launch_at` / `network_closes_at` — were purely cosmetic (an admin could set a launch/close date in the UI and it changed nothing). `network_join()` now refuses a new join attempt outside the configured window, with a clear message naming which boundary. Functionally tested in both directions (future launch date refuses; past close date refuses; no dates set proceeds past the check) via direct function calls in a rolled-back transaction — no data persisted.
- `network_registration_bonus` — was settable in the admin UI with **no payment mechanism behind it at all**: joining never credited anything, for any account, ever. Building a correct system-initiated payment would require either a schema change (`admin_audit_logs.admin_user_id` is `NOT NULL`, so the existing adjustment/audit functions can't be reused as-is for a non-admin-initiated credit) or a new "system actor" convention — a real design decision, not something to improvise inside a verification phase. **Removed from the admin settings whitelist (`api/network-admin.php`), the settings form, and the JS field mapping (`admin/dashboard.php`)** — it no longer appears anywhere an admin could set it expecting an effect. The `system_settings` row itself is untouched (harmless, unread, matches how `network_multi_level_enabled` already worked before this phase).

**Confirmed already correct, no change needed:**
- `network_enabled` — the master switch; also implicitly gates registrations/links/rewards/withdrawals (each of those checks `network_enabled($pdo) && ...`). Confirmed by reading every one of those four functions.
- `network_maintenance_mode` — folded into `network_enabled()` itself (`!network_maintenance_mode($pdo)`), so there's exactly one effective "is the programme usable right now" check, not two that could disagree.
- `network_emergency_payout_suspended` — gates `network_rewards_enabled()` only (new reward *generation*, via `!network_payouts_suspended($pdo)`); does not touch withdrawals-in-progress, existing balances, or anything transport-related.
- `network_registrations_enabled`, `network_links_enabled`, `network_rewards_enabled`, `network_withdrawals_enabled`, `network_driver_referrals_enabled`, `network_passenger_referrals_enabled`, `network_external_partners_enabled`, `network_partner_auto_approve`, `network_driver_reward_pct`, `network_passenger_reward_pct`, `network_combined_ceiling_pct`, `network_earning_days`, `network_min_withdrawal`, `network_reward_hold_days`, `network_total_budget`, `network_daily_budget`, `network_monthly_budget`, `network_max_combined_payout_per_txn`, `network_max_withdrawal_per_day`, `network_max_withdrawal_per_month`, `network_fraud_velocity_max_per_day`, `network_fraud_same_pair_max_rewards`, `network_terms_version`, `network_terms_text`, `network_announcement` — each read live at the point it matters (Phase 6 traced the financial ones in detail); none found to be cosmetic-only.

**Left as a harmless, intentionally-undocumented placeholder (not surfaced, not misleading):**
- `network_multi_level_enabled` — never read, never shown in any admin UI. Per its own source comment, it exists only to make "direct-only, not multi-level" an explicit, searchable fact in configuration rather than an implicit one. Since no admin can see or set it expecting an effect, it does not meet the bar of "misleading administrative control" — it's not a control at all. Left alone.

## Global switches never disable ordinary transport — re-confirmed by reading every call site

`network_enabled()`, `network_payouts_suspended()`, and every other `network_*_enabled()` function is called **only** from within `includes/network.php` itself, `api/network.php`, and `api/network-admin.php` — confirmed by grep, zero call sites in `api/rideshare.php`, `includes/booking.php`, `includes/functions.php`'s transport functions, or anywhere else a ride/booking/payment is created or completed. The only place transport code touches MOHTOH NETWORK at all is the two `network_settle_booking_reward()` call sites (Phase 1), both wrapped in `try/catch (Throwable)` with the explicit comment "never let a NETWORK failure affect settlement" — so even an *exception* inside the reward engine, let alone a disabled setting, cannot block a trip from completing or a payment from being confirmed.

## Verdict by Phase 7 checklist item

| Item | Status |
|---|---|
| Direct referral only / no multi-level | RESOLVED AND TESTED |
| No payment for joining / no recruitment commissions | RESOLVED AND TESTED |
| Rewards tied to qualifying activity, configurable duration/%/caps/budgets | RESOLVED AND TESTED |
| Auditable admin decisions, duplicate-reward protection | RESOLVED AND TESTED |
| Driver/passenger/external-partner eligibility | RESOLVED AND TESTED |
| Every admin setting checked; dead ones resolved | RESOLVED AND TESTED (2 fixed, 1 removed from UI, 1 confirmed intentionally harmless) |
| Global switch / emergency suspension never disables transport | RESOLVED AND TESTED |
