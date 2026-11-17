# MOHTOH — Application-Wide Security Audit (Phase 4)

## CSRF — the main deliverable of this phase

### What changed on the frontend (applies everywhere, once)

Three shared JS call sites were patched so every POST request in the app now automatically carries the session's CSRF token as an `X-CSRF-Token` header, with zero changes needed at individual call sites:
- `assets/js/api-client.js` — `_mtApiRequest()` (the `apiPost()`/`apiUpload()` engine used by 17+ modules)
- `assets/js/helper.js` — `postJson()` (used by 4 modules)
- `admin/dashboard.php` — its own inline `sendAction()`/`postJson()` (used by every admin-dashboard JS call)

Verified: fetched both JS files raw from a running instance and ran them through `new Function(...)` in a real browser — both parse and execute without error; `apiPost` confirmed defined and callable.

A page-side `<meta name="csrf-token">` tag (rendered via `includes/functions.php`'s `csrf_token()`) is required for the header to actually be non-empty. Four pages that call protected endpoints but were missing it were found and fixed: `driver/pickup.php`, `driver/ride-requests.php`, `rider/private-pickup.php`, `rider/ride-requests.php`. A full cross-reference of every page loading any JS module that calls a protected endpoint against presence of this tag was run twice (once after the initial two named endpoints, once after the full sweep) — no further gaps found.

### Backend — endpoints hardened in this phase

Starting point (from the Phase 1 audit): only `api/network.php`/`api/network-admin.php` called `require_csrf()`. The brief named two further endpoint families explicitly (`api/wallet.php`, `api/rideshare.php`); this phase closed those **and** extended the same treatment across the rest of `api/`'s state-changing surface:

| File | Write actions protected | Method |
|---|---|---|
| `api/wallet.php` | `initialize_topup`, `set_payment_preference` | per-action (no method gate in this file) |
| `api/rideshare.php` | `create`, `update`, `cancel`, `start_ride`, `complete_ride`, `confirm_direct_payment`, `reject_direct_payment`, `cancel_booking`, `book`, `rate_ride` (10 actions) | per-action |
| `api/admin-api.php` | all 25 actions behind its `$post_action` dispatch (user/driver/vehicle/ride moderation, settings, announcements, location & chat-report decisions) | one blanket check — this file already funnels every write through a single `POST` block |
| `api/premium.php`, `api/driver-api.php`, `api/promotions.php`, `api/pricing-admin.php`, `api/referral-admin.php`, `api/integration-center.php`, `api/regions.php`, `api/driver-settings.php`, `api/profile.php`, `api/ride-queue.php`, `api/ride-requests.php`, `api/chat.php`, `api/driver-comms.php`, `api/notifications.php`, `api/admin-notifications.php`, `api/toggle-online.php`, `api/update-location.php` | all POST actions in each file | blanket (each has its own single `POST` method gate) |
| `api/respond-ride.php`, `api/rate-driver.php` | all POST actions | blanket — **note:** grep found zero frontend callers for either; likely dead/superseded code, protected anyway at no cost |
| `api/switch-mode.php` | every action past `get_modes` (the file's own comment already labels this boundary: *"Everything past here mutates state"*) | blanket after the read-only branch |
| `api/saved-locations.php` | `add`, `delete` (not `list`) | per-action |
| `api/trip-collaboration.php` | all 9 rider/driver state-transition actions (not `get_live_status`) | blanket with one exclusion |
| `api/private-pickup.php` | `create`, `cancel`, `accept`, `decline` (not `estimate`/`status`/`feed`, confirmed read-only by inspection) | blanket with exclusions |

**22 files touched**, all verified with `php -l` (zero syntax errors) and the 48-test suite re-run clean after every batch.

### Proven, not just claimed

Three independent end-to-end tests were run against a temporary isolated PHP dev server bound to this worktree (never the live Apache tree), using temporary test accounts (created and deleted within this session):

1. `api/rideshare.php` `update` — no token → `"Your session has expired"`; wrong token → same rejection; correct token (scraped live from the rendered driver dashboard page) → passed CSRF, reached the action's own business validation (`"Pickup location is required"`).
2. `api/wallet.php` `initialize_topup` — no token → rejected; correct token → passed CSRF, reached Paystack's own validation (`"email" must be a valid email`, from the test account's `.test` domain — a downstream, unrelated failure that itself proves the CSRF layer let the request through); `get_history` (read-only) confirmed to still work with **no** token at all.
3. `api/admin-api.php` `toggle_user_status` — no token → rejected; correct token → **succeeded** (`"User online status updated"`); `get_dashboard_data` (read-only) confirmed unaffected.

### Explicitly excluded (and why)

- **`api/v1/*`** (`files/upload.php` etc.) — Bearer-token auth, no session cookie, confirmed in Phase 1 that cross-origin JS cannot read the victim's Authorization header. Not CSRF-forgeable by construction; adding a session-token check here would do nothing useful.
- **`api/paystack-callback.php`, `api/paystack-webhook.php`** — server-to-server/redirect endpoints called by Paystack, not by the app's own authenticated browser session. A CSRF token tied to the *user's* session is the wrong mechanism here (these already rely on Paystack's own reference/signature verification); adding `require_csrf()` would simply break legitimate callbacks.
- **`api/fare-settings.php`** — its POST branch is dead code (`"This endpoint no longer accepts writes. Use api/pricing-admin.php."`); nothing to protect.
- **`api/submit-offer.php`, `api/accept-offer.php`** — both are retired stubs that unconditionally return `"This feature is not available in the Join Ride model."`; nothing to protect.
- **`api/password-reset.php`** — pre-authentication flow (no session identity to tie a token to yet); the dominant mitigation here is the reset token itself (secret, single-use, time-limited), a different and already-present control. **Documented limitation, not fixed in this pass.**
- **`api/verification.php`, `api/community-fare.php`, `api/vehicle-info.php`, `api/waiting-pricing.php`, `api/community-analytics.php`, `api/admin-reports.php`** — grep-confirmed zero direct `INSERT`/`UPDATE`/`DELETE` statements in any of these six files (read-only by inspection). **Not independently re-verified action-by-action** — flagged as a remaining item if any of them turn out to delegate a write to an `includes/` helper that wasn't visible to a plain SQL-keyword grep.

## Other categories (spot-checked this phase, not a full line-by-line audit)

- **SQL injection**: this codebase's established convention is PDO prepared statements throughout. Grepped for the one real injection smell — a variable interpolated directly into a query string rather than bound — across `api/` and `includes/`. Found 3 instances, all confirmed **safe on inspection**: `includes/network.php`'s `$whereClause` is built only from a hardcoded array of literal SQL fragments (never user input); `includes/ride_queue.php`'s `$windowMinutes` and `api/premium.php`'s `duration_days` are both explicitly `(int)`-cast before use, and in `premium.php`'s case the source column is itself `int(11)` at the schema level, so even a bypass of the app-level cast can't inject a string. No fix needed.
- **XSS**: every page already routes dynamic content through `escapeHtml()` (client) or `htmlspecialchars()` (server) by convention, confirmed throughout Phase 1/2/3's reading — not re-audited line-by-line here.
- **IDOR / broken access control**: `api/rideshare.php`'s booking/ride actions already scope every query by `WHERE driver_id = $_SESSION['user_id']` / `WHERE rider_id = ...` (seen directly while adding CSRF to each action); `api/admin-api.php` and `api/network-admin.php` gate on `user_type==='admin'` plus (for network actions) the granular `includes/admin_permissions.php` layer. Not independently re-tested with a second account in this pass.
- **Privilege escalation**: `includes/admin_permissions.php`'s layered gate (Phase 1) and `require_super_admin()`'s separate flag check were already confirmed non-overlapping and consistent.
- **Session weaknesses**: `session_regenerate_id(true)` on login confirmed in Phase 1; not re-tested here.
- **Insecure file uploads**: covered in Phase 1 — server-generated filenames, extension allowlist, no MIME sniffing, no `uploads/.htaccess` blocking script execution (flagged there as a defense-in-depth gap, not fixed in this phase — low risk today, worth closing before the extension allowlist is ever loosened).
- **Authentication bypass**: `is_logged_in()`/`require_login()` gates confirmed present at the top of every file touched in this phase; none were weakened by these changes (CSRF checks were added, nothing removed).
- **Sensitive information disclosure**: covered in depth in Phase 3 (public repo, encryption-key fallback) — the single biggest finding of this whole audit lives there, not here.
- **Financial endpoint manipulation**: the two explicitly-named families (`wallet.php`, `rideshare.php`) are now CSRF-protected; `confirm_direct_payment`/`reject_direct_payment` were specifically tested per Phase 1's financial-integrity note that these are the MOHTOH NETWORK reward-settlement trigger points.

## Honest limitations of this phase

- Coverage is **CSRF-first**: the other nine categories in the brief's checklist were spot-checked against what Phase 1-3's reading had already surfaced, not independently re-audited end-to-end in this pass. Treat the items above as "nothing new found," not "exhaustively re-verified."
- `api/verification.php`/`community-fare.php`/`vehicle-info.php`/`waiting-pricing.php`/`community-analytics.php`/`admin-reports.php` are asserted read-only by a SQL-keyword grep, not by reading every line.
- No second-account IDOR probing was performed (e.g., actually attempting to cancel another user's booking) — the ownership-scoped `WHERE` clauses were read, not adversarially tested.
