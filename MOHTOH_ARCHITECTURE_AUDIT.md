# MOHTOH — Phase 1 Architecture Audit & Dependency Map

Branch: `feature/mohtoh-stabilization` (based on `review/mohtoh-network` @ `743862e`)
Scope: complete repository audit per the stabilisation assignment, Phase 1.

## 1. Dependency map — MOHTOH NETWORK ↔ existing transport app

MOHTOH NETWORK touches the existing transport app at exactly these points, and nowhere else:

| Existing component | Touch point | Direction |
|---|---|---|
| `includes/functions.php: settle_ride_completion()` | calls `network_settle_booking_reward($pdo,$bookingId)` per completed wallet booking, try/catch-wrapped, after the money-moving loop | transport → network |
| `api/rideshare.php: confirm_direct_payment` | calls `network_settle_booking_reward()` after `$pdo->commit()`, try/catch-wrapped | transport → network |
| `api/auth.php: register` | calls `get_network_partner_by_code()` / `network_attribute_referral()` instead of the legacy `users.referral_code` lookup | transport → network |
| `api/referrals.php: validate_code` | looks up `network_partners` instead of `users.referral_code` | transport → network |
| `includes/referral.php` | `create_referral_relationship()` / `track_referral_event()` now delegate into `includes/network.php` (legacy tables kept read-only for history) | transport → network |
| `payments.commission_amount`, `payments.driver_id`, `ride_share_bookings.status` | read-only, to compute and gate reward eligibility | network → transport (read-only) |
| `users.id`, `users.referred_by`, `users.is_network_partner` | FK target / written by network on join | network → transport (write) |
| `includes/admin_permissions.php` | `api/network-admin.php` depends on `get_admin_context()`/`current_admin_can()`/`require_admin_permission()`/`require_super_admin()` for every admin action | network → transport (consumes) |
| `admin_audit_logs` | network admin actions (reward reversal, adjustments) also write here via the generic audit trail, alongside their own `network_reward_adjustments` | network → transport (write) |
| `includes/functions.php: csrf_token()/csrf_validate()/require_csrf()` | consumed by `api/network.php`, `api/network-admin.php`, and every page that renders the `<meta name="csrf-token">` tag | network → transport (consumes) |
| `system_settings` | 32 `network_*` keys live alongside existing settings, same table/mechanism as pricing/promotions | network → transport (shared table, namespaced keys) |

**Never touched by MOHTOH NETWORK:** `users.wallet_balance`, `wallet_transactions`, `ride_commission_reservations` — confirmed by direct grep, no `network_*` code path writes to any of them. The two wallet systems are structurally and operationally independent.

## 2. Outstanding observations (this audit pass)

Ranked roughly by relevance to the remaining phases.

### Security / CSRF (→ Phase 4)
- **Confirmed the two CSRF-exposed endpoint families**: `api/wallet.php` (financial — `initialize_topup`, `set_payment_preference`, session-cookie auth, zero CSRF) and `api/rideshare.php` (17 mutation sites incl. `confirm_direct_payment`/`reject_direct_payment`, zero CSRF). These match what `MOHTOH_NETWORK_FINAL_ACCEPTANCE.md §12.7` already named as the known app-wide gap.
- `api/admin-api.php` (29 mutation sites, 0 CSRF) is a lower-severity third candidate — narrowed by the `user_type==='admin'` session gate, but still in scope for Phase 4's "all state-changing endpoints" requirement.
- `api/v1/*` (token-bearer auth, no cookies) is **not CSRF-relevant** — confirmed out of scope correctly.
- `uploads/` has no `.htaccess` blocking script execution — defense-in-depth gap, low risk today given the extension allowlist in `api/v1/files/upload.php`.

### Database (→ Phase 2)
- `network_referrals.registration_ip` — confirmed real, added via an idempotent follow-up `ALTER TABLE` inside `migrate_mohtoh_network.php` (not part of the original `CREATE TABLE`), written at referral creation and read back for the same-IP multi-accounting fraud check. Working as designed.
- `network_reward_ledger` has `UNIQUE KEY uniq_booking_beneficiary (booking_id, beneficiary_user_id, beneficiary_role)` — confirmed present, which is what makes `network_settle_booking_reward()`'s duplicate-webhook protection (relying on a 1062 constraint violation) actually hold. Verified, not just assumed.
- `users.is_network_partner` is **write-only** — set on join, never read anywhere in the codebase. Harmless but dead; candidate for removal or for actually wiring it into a UI/query somewhere (Phase 7 call).
- `system_settings.network_multi_level_enabled` exists (default `'0'`), is never read by any code path, and is explicitly commented `// never surfaced in UI; exists only so this is explicit, not implicit, in config`. It provides no actual enforcement — the direct-only rule is hardcoded in the attribution logic itself, not conditional on this flag. Candidate for removal per Phase 7's "remove settings that don't affect the backend," though it's arguably intentional documentation-as-config and not misleading since it's never exposed in any admin UI.
- `database/migrate.php` has a corrupted header — a stray text fragment (`ettings page for drivers and otherspages`) sits before the opening `<?php` tag (confirmed via `cat -A`, CRLF line endings). Harmless at runtime (PHP ignores pre-tag content in a non-entry-point file) but indicates a bad edit/merge; worth a clean rewrite while touching migrations in Phase 2.
- 43 `database/migrate_*.php` scripts exist; the majority use idempotent existence checks, a handful don't — needs a file-by-file pass before Phase 2's upgrade SQL is finalized.
- None of the `migrate_*.php` scripts are invoked automatically by the live app — all are standalone CLI scripts, consistent with this project's convention.

### Financial integrity (→ Phase 6)
- `credit_wallet()`'s `balance_after` ledger snapshot (legacy `wallet_transactions`, not MOHTOH NETWORK) is read back without a lock after an atomic update — under concurrent credits to the same user, the audit snapshot field can show a value reflecting a race with another transaction. The money itself (`wallet_balance`) is correct; only the historical audit field can be momentarily imprecise. Pre-existing, not introduced by MOHTOH NETWORK, but worth a Phase 6 note since it's a ledger-auditability concern.
- No other financial correctness issues found in `network_settle_booking_reward()`'s read path, `confirm_direct_payment`, or `settle_ride_completion()` — eligibility gating (`booking.status==='completed' AND payment.status==='PAYMENT_CONFIRMED'`), transaction boundaries, and try/catch isolation all check out as designed.

### Architecture (→ Phase 5)
- `users.user_type` is `ENUM('rider','driver','admin')` — confirmed, no fourth value exists or is reachable today. Multi-role (an account holding more than one capability) is **already handled without touching the ENUM**: a separate driver-profile-row existence check (`includes/current_user.php`) plus `api/switch-mode.php` flipping the *session's* operating `user_type`. This is the existing precedent Phase 5's external-partner design should extend, not the ENUM.

### RBAC (no action needed)
- `includes/admin_permissions.php`'s granular layer and `functions.php`'s pre-existing `require_super_admin()` coexist deliberately with no collision — confirmed, documented inline in both files.
- `ensure_admin_rbac_schema()` self-heals its own columns/tables on first use; the standalone `migrate_admin_rbac.php` (excluded from this branch) is redundant with it, consistent with the prior review branch's exclusion reasoning.

## 3. Not yet covered in this pass
- Full per-action enumeration of `api/admin-api.php`'s 29 mutation sites (have file-level count, not an action-by-action table yet).
- `api/notifications.php` read/mark-read endpoints not inspected in detail.
- UX/admin interface walkthrough (Phase 8) — not started.
- External-partner technical proposal (Phase 5) — not started; this needs to be presented for approval before any ENUM/identity-model change, per the assignment's own instruction.
