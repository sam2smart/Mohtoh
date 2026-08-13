# MohToh — Complete System Analysis

> **Audit type:** Read-only codebase audit (no application files were modified).
> **Audited copy:** `merit_drives - Copy.v1`
> **Date:** 2026-08-02
> **Purpose:** Understand exactly how the app works today, before any implementation.

---

## 0. Executive Summary

MohToh is a **carpooling / shared-ride marketplace** (not a classic single-passenger
taxi hailer) built as a **server-rendered PHP application on XAMPP/MySQL**, with a
**mobile-first PWA** frontend and a parallel **token-authenticated REST API (`api/v1`)**
intended to back a future **.NET MAUI WebView app**.

Key facts:

- **Two coexisting product generations live in the same tree:**
  1. **Legacy single-passenger request/offer negotiation** (`api/request-ride.php`,
     `submit-offer.php`, `accept-offer.php`, etc.) — *dead code*, confirmed unreferenced
     in `LEGACY_CODE.md`.
  2. **Current shared-seat model** (`ride_shares` + `ride_share_bookings`) driven by the
     **"Intelligent Trip Engine"** (`transition_trip_state()` / `transition_passenger_state()`)
     — this is what every live dashboard uses.
- **Two auth systems coexist by design:** PHP **session cookies** for the web app, and
  **bearer tokens** (`user_tokens` table, `Authorization: Bearer`) for `api/v1`.
- **Maps: Leaflet + OpenStreetMap** is the active provider. Google Maps is "parked"
  (key present but not loaded).
- **Payments: Paystack** for driver wallet top-ups (well-implemented webhook with HMAC
  signature verification + re-verification + idempotency). Ride payments run through an
  internal **wallet ledger** with a **commission-reservation** system.
- **Notifications:** in-app **Notification Engine** (`notifications` table + polling),
  plus pluggable **email** (PHPMailer/SMTP) and **SMS** (Termii/Twilio/Vonage/Africa's
  Talking) providers — both defaulting to `log` mode.

> ⚠️ **Documentation staleness warning:** `IMPLEMENTATION_STATUS.md` (dated June 1, 2026,
> "~75% complete") describes the **legacy** request/offer flow as the product. It is
> **superseded** and partially contradicted by `LEGACY_CODE.md` and the actual live code.
> Trust the code + `LEGACY_CODE.md` over `IMPLEMENTATION_STATUS.md`.

---

## 1. Project Structure

### Framework / Architecture

- **No framework.** Plain PHP with a hand-rolled structure. Only Composer dependency is
  **PHPMailer** (`composer.json`).
- **Architecture pattern:** procedural PHP "pages + API endpoints + shared includes".
  - **Pages** (`index.php`, `rider/*.php`, `driver/*.php`, `admin/dashboard.php`) render
    HTML and are protected by session checks.
  - **API endpoints** (`api/*.php`, `api/v1/**`) return JSON.
  - **Business logic** lives in `includes/*.php` (the real "service layer").
- **Standard loader:** `bootstrap.php` (session + db + functions + config + current_user
  + verification). Legacy files still `require_once` includes directly; bootstrap is the
  canonical entry point for new code.

### Major folders

| Folder | Purpose |
|---|---|
| **`admin/`** | Single-file admin SPA-ish dashboard (`dashboard.php`, ~1950 lines) with ~24 tabs. Uses Bootstrap 5 + Leaflet. Session-gated to `user_type='admin'`. |
| **`api/`** | ~50 **legacy/web** JSON endpoints (session-authenticated). Action-dispatch style (`?action=...`). Includes both live endpoints and dead legacy ones. |
| **`api/v1/`** | Modern **REST API** (bearer-token). Organized by resource: `auth/`, `device/`, `driver/`, `rider/`, `rides/`, `wallet/`, `files/`, `notifications`. Shares the same `includes/` business logic. |
| **`assets/`** | Current frontend: `css/` (design-system split — variables/reset/layout/components/…), `js/` (the real app JS — 22 modules), `icons/`, `images/`, `sounds/`. |
| **`includes/`** | **The core.** `functions.php` (~79KB — wallet, trip engine, notifications, distance, pagination, IDs), `booking.php`, `verification.php` (auth/OTP/reset/lockout), `config.php`, `db.php`, `chat.php`, `referral.php`, `ride_requests.php`, `ride_queue.php`, `FareCalculator.php`, provider adapters (`email-providers.php`, `sms-providers.php`), `environment.php`, `api_tokens.php`, `secrets.php`, `current_user.php`, `integration-settings.php`, `audit.php`, `diagnostics.php`. |
| **`database/`** | `schema.sql` (15 base tables) + **31 `migrate_*.php` scripts** (idempotent) that build the full ~49-table schema, plus `seed_admin.php`, `notifications.php`, and a fix script. **There is no `database/migrations/` subfolder** — migrations are flat PHP files in `database/`. |
| **config files** | `.env` / `.env.example` (env + base URLs + allowed hosts), `includes/config.php` (keys/constants), `includes/environment.php` (env detection), `composer.json/.lock`, `manifest.json`, `Dockerfile`. |
| **frontend files (root)** | `index.php` (login/register landing), `join.php`, `forgot-password.php`, `reset-password.php`, `verify-account.php`, `verify-email.php`, `offline.html`, `service-worker.js`, `manifest.json`. |
| **`partials/`** | Reusable UI includes: `app-header.php`, `bottom-nav.php`, `sidebar.php`, `notification-sheet.php`, `messages-sheet.php`, `community-rewards-sheet.php`. |
| **mobile/app files** | **No native mobile project in-tree.** Instead: a **PWA** (`manifest.json` + `service-worker.js`) and a **device-abstraction bridge** (`assets/js/mohtoh-bridge.js`, `window.MohToh`) designed so a future **.NET MAUI** WebView can swap native GPS/camera/notifications behind identical JS method signatures. See `docs/maui-integration.md`. |
| **`_legacy_archive/`, `js/` (top-level), `css/` (top-level)** | Old/superseded frontend (`js/app.js`, `js/auth.js`, `js/map.js`, `css/style.css`). `LEGACY_CODE.md` confirms `js/app.js` is unreferenced. Distinct from the live `assets/js` & `assets/css`. |
| **`docs/`** | `maui-integration.md` + `docs/api/*.md` (per-resource API docs: auth, driver, rider, rides, wallet, files, notifications, errors). |
| **`uploads/`, `storage/`** | User-uploaded files (vehicle photos, verification docs, profile images) and app storage. |

---

## 2. Technology Stack

| Layer | Technology |
|---|---|
| **Backend language** | PHP (targeting **PHP 8** — `sanitize_input()` explicitly guards against the PHP 8 `trim(array)` TypeError). Runs on **XAMPP** (Apache + MySQL). |
| **Database** | **MySQL / MariaDB** (`merit_drives`), InnoDB, `utf8mb4`. Accessed via **PDO** with exceptions + prepared statements. |
| **Frontend** | Server-rendered PHP + **vanilla JS (ES6+, no framework)**. **Bootstrap 5** (CDN) for layout utilities only; visual identity from a custom CSS design system in `assets/css`. Bootstrap Icons + Font Awesome (admin). Google Fonts (Poppins/Inter). |
| **PWA** | `manifest.json`, `service-worker.js` (cache-first for static assets, network-only for `/api/`, wallet, payment, admin; `offline.html` fallback), `pwa-init.js`. |
| **APIs consumed** | **OpenStreetMap** tiles; **Nominatim** (geocoding/search — via `location-search.php`/cache); **Paystack** REST API (`https://api.paystack.co`). |
| **3rd-party integrations** | **Paystack** (payments), **PHPMailer/SMTP** (email), **Termii / Twilio / Vonage / Africa's Talking** (SMS — adapters present, config-gated). |
| **Authentication** | Web: **PHP sessions** (`$_SESSION['user_id']`, `session_regenerate_id(true)` on login). API v1: **bearer tokens** (`user_tokens`, `api_tokens.php`). Email + phone OTP verification, login lockout. |
| **Payments** | **Paystack** (wallet top-ups) + internal **wallet ledger** (`wallet_transactions`) + **commission reservations**. |
| **Maps / location** | **Leaflet 1.9.4 + OpenStreetMap** (active). Google Maps key parked in config. Haversine distance in PHP. |
| **Notifications** | In-app Notification Engine (DB + polling) with priority/preferences; email + SMS providers; sound manager + Web Notifications API via the bridge. |
| **Realtime** | **Polling only** (no WebSockets). Calls/chat/notifications all poll. |

---

## 3. Database Analysis

**~49 tables total** = 15 in `schema.sql` + the rest added by 31 idempotent `migrate_*.php`
scripts. Storage engine InnoDB with real FOREIGN KEYs. All PKs are `INT AUTO_INCREMENT id`
unless noted. Many tables also carry a **`public_id`** (Crockford Base32, e.g. `BKG-…`,
`PAY-…`, `TXN-…`, `RDR-…`) for safe external references.

### 3.1 Full table inventory (by domain)

**Users / Auth / Devices / Tokens**
- `users` — the central table. See added columns below.
- `user_tokens` — API v1 bearer tokens (hashed), per device/session.
- `user_devices` — registered push/devices (`device/register`).
- `verification_events` — OTP/email/reset audit trail.

**Rides — legacy single-passenger (mostly dead code, table retained)**
- `ride_requests` — PK `id`; FK `rider_id→users`, `driver_id→users (SET NULL)`. Still read
  by admin review-profile lookups; do **not** drop.
- `ride_offers` — FK `request_id→ride_requests`, `driver_id→users`.
- `ride_demand_requests` — newer "I want a ride to X" demand signal (matched to `ride_shares`).

**Rideshare / Booking (LIVE core)**
- `ride_shares` — a published trip with seats. PK `id`; FK `driver_id→users`. Key cols:
  `available_seats`, `total_seats`, `price_per_passenger`, `payment_preference`
  (`wallet`/`direct`/`both`), `commission_amount_per_seat`, `driver_earning_per_seat`,
  `status` (`active`/`full`/`cancelled`/`completed`), `trip_state` + `trip_state_updated_at`
  (trip engine), pickup/destination lat/lng.
- `ride_share_bookings` — PK `id`, `public_id`; FK `ride_share_id→ride_shares`,
  `rider_id→users`; `UNIQUE(ride_share_id, rider_id)`; `seats_booked`, `payment_method`,
  `status`, `passenger_state`.
- `ride_share_ratings` — post-trip rideshare ratings.
- `ride_waitlists` — waitlist rows tying a rider to a full ride.
- `trip_state_events` — append-only trip-state transition log.
- `booking_state_events` — append-only passenger-state transition log.

**Wallet / Payments / Financial**
- `wallet_transactions` — the **ledger**. `user_id`, `amount`, `type`(`credit`/`debit`),
  `category`, `balance_after`, `reference` (`TXN-…`), `ride_share_id`, `booking_id`, `meta`.
- `wallet_topups` — Paystack top-up intents. `public_id` = Paystack reference,
  `status`(`PAYMENT_PENDING`/`PAYMENT_FAILED`/…), `raw_response`, `verified_at`.
- `payments` — per-booking payment record: `fare_amount`, `commission_amount`,
  `driver_earning_amount`, `payment_method`, `status`(`PAYMENT_CONFIRMED`/`PAYMENT_PENDING`),
  `confirmed_at`; FK to `ride_shares`, `ride_share_bookings`, `users` (rider+driver).
- `ride_commission_reservations` — logical hold on driver balance per ride
  (reserved/used/released); backs `get_available_wallet_balance()`.
- `driver_bonuses` — referral/bonus credits.

**Locations / Pricing / Regions**
- `locations` — canonical/admin locations.
- `saved_locations` — a user's saved places (home/work/etc.); FK `user_id→users`.
- `location_search_cache` — cached geocode/search results (Nominatim).
- `regions` — regional definitions.
- `pricing_configuration` — PK `star_rating`; `price_per_km`.
- `system_settings` — PK `setting_key`; global config (base_fare, price_per_km, minimum_fare…).
- `pricing_change_logs` — audit of pricing edits.

**Vehicles**
- `vehicles` — PK `id`, `driver_id` UNIQUE→users; make/model/year/plate, `status`
  (`pending`/`approved`/`rejected`), multiple admin condition ratings, `service_category`,
  `admin_rating`, `edit_request_status`.
- `vehicle_photos`, `vehicle_verifications` (documents), `vehicle_edit_requests`,
  `vehicle_review_logs` — all FK `vehicle_id→vehicles`.

**Ratings**
- `driver_ratings` — rider→driver (with sub-scores + `driver_lied`, `vehicle_claims_match`).
- `passenger_ratings` — driver→rider (punctuality/behavior/communication).

**Notifications**
- `notifications` — in-app notification feed (title, message, type, priority, action,
  related_table/id/public_id, read state).
- `notification_broadcasts` — admin announcements.
- (notification preferences stored as `notify_*` columns on `users`.)

**Chat / Calls**
- `conversations`, `conversation_members`, `messages` (current chat), `message_reports`.
- `call_sessions` — call signaling (`call_id` UNIQUE, initiator/recipient, status,
  duration). Signaling only (no WebRTC media).

**Referral / Rewards**
- `referrals`, `referral_events`, `referral_rewards`.
- `promo_codes`, `promo_redemptions` (promo engine used in booking).

**Premium**
- `driver_premium_features`, `driver_premium_requests`.

**Admin / Integrations / Audit**
- `admin_user_actions` — warnings/suspensions/notes on users.
- `admin_audit_logs` — admin action audit.
- `integration_settings`, `integration_logs`, `integration_change_logs` — Service
  Integration Center (email/SMS/registration-policy config stored in DB).

### 3.2 Columns added to `users` via migrations

Base `users` (schema.sql) has: `id, fullname, phone, email, password, user_type,
profile_image, rating, wallet_balance, completed_rides, vehicle_model, vehicle_plate,
is_online, latitude, longitude, last_location_update, created_at`.

Migrations add (non-exhaustive but confirmed): `user_id` (public id, UNIQUE),
`email_verified`, `phone_verified`, `otp_code`, `otp_expires_at`, `reset_token`,
`reset_token_expires_at`, failed-login/lockout columns (`failed_login_attempts`,
`lockout_until`), `is_suspended`, `is_super_admin`, `driver_tier`,
`custom_commission_percentage`, `payment_preference`, `referral_code`, `referred_by`,
`notify_chat`, `notify_referrals` (and other `notify_*` preference flags).

### 3.3 Relationship explanation (text ER overview)

```
users (id) ──1:1── vehicles (driver_id UNIQUE)
                     ├─1:N─ vehicle_photos
                     ├─1:N─ vehicle_verifications
                     ├─1:N─ vehicle_edit_requests
                     └─1:N─ vehicle_review_logs

users (id, driver) ──1:N── ride_shares (driver_id)
                              ├─1:N─ ride_share_bookings (ride_share_id) ──N:1── users (rider_id)
                              │         └─1:1─ payments (booking_id)
                              ├─1:N─ trip_state_events
                              ├─1:N─ booking_state_events
                              ├─1:N─ ride_waitlists
                              ├─1:N─ ride_share_ratings
                              └─1:1─ ride_commission_reservations (per ride, driver hold)

users (id) ──1:N── wallet_transactions (ledger; balance_after snapshot)
users (id) ──1:N── wallet_topups (Paystack reference == public_id)
users (id) ──1:N── saved_locations
users (id) ──1:N── notifications
users (id) ──1:N── user_tokens (API v1 bearer)
users (id) ──1:N── user_devices

ride_share_bookings/ride_shares ──1:N── driver_ratings / passenger_ratings (per completed trip)

conversations ──1:N── conversation_members ──N:1── users
conversations ──1:N── messages
users ──N:N (call_sessions: initiator_id, recipient_id)

users (referrer) ──1:N── referrals ──N:1── users (referred)
promo_codes ──1:N── promo_redemptions ──N:1── users

system_settings / pricing_configuration / regions / integration_settings ── global config
```

**Central hub:** `users`. Almost every table FKs back to it (as rider, driver, admin,
initiator, or recipient). **Live ride hub:** `ride_shares` → `ride_share_bookings` →
`payments`. **Money hub:** `wallet_transactions` (ledger) with `wallet_topups` (in) and
`ride_commission_reservations` (holds).

---

## 4. User Flow Analysis

### 4.1 Rider

1. **Registration** — `index.php` → `api/auth.php?action=register`. Validates name/email/
   phone; **normalizes phone** to international format; enforces password policy; checks
   case-insensitive uniqueness; hashes password (`password_hash`, bcrypt default); creates
   `users` row with a public `user_id` (`RDR-…`); starts session; **sends email verification
   + phone OTP**; optionally links a **referral code**. Redirects to `verify-account.php`.
2. **Verification** — `verify-account.php` / `verify-email.php` confirm email + phone OTP
   (`includes/verification.php`). By default (config + integration settings) **both must be
   verified to log in and to book** (`booking.php` re-checks `email_verified` &
   `phone_verified`).
3. **Login** — `api/auth.php?action=login`. Blocks suspended accounts, enforces lockout
   after 5 failed attempts (15 min), `session_regenerate_id(true)`, redirects to
   `rider/dashboard.php` (or back to verify if unverified).
4. **Profile** — `rider/profile.php` + `api/profile.php` (+ `assets/js/profile.js`). Update
   details, change password (re-auth with current password via `password_verify`), photo
   upload through the bridge/`files` upload.
5. **Searching rides** — `rider/dashboard.php` + `assets/js/search.js` → `api/rideshare.php?
   action=search` / `get_recommended_rides` / `price_preview`. Map via Leaflet; geocode via
   `api/location-search.php` (Nominatim + cache). Distance via haversine.
6. **Booking seats** — `api/rideshare.php?action=book` (or `api/v1/rider/book-seat.php`,
   or `api/ride-queue.php` BOOK_NOW) → all funnel into **`create_ride_booking()`**
   (`includes/booking.php`). One transaction: verify-account gate → lock ride `FOR UPDATE`
   → resolve payment method against ride's `payment_preference` → apply promo (wallet only)
   → **debit wallet** if wallet → insert `ride_share_bookings` + `payments` → decrement
   seats → notify driver + rider → attach rider to ride chat → close any matched demand
   request. `UNIQUE(ride_share_id, rider_id)` prevents double-booking.
7. **Payment** — **wallet** (instant debit, `PAYMENT_CONFIRMED`) or **direct** (cash/transfer
   to driver, `PAYMENT_PENDING`; later `confirm_direct_payment`/`reject_direct_payment`).
8. **Notifications** — in-app feed (`notifications`) polled by `navigation.js`/notification
   sheet; wallet changes, booking confirmations, trip-state events all generate entries.
9. **Ratings** — after completion, rider rates driver (`api/rate-driver.php` /
   `api/v1/rider/rating.php`, `driver_ratings`), which updates `users.rating`.

### 4.2 Driver

1. **Registration** — same `api/auth.php?action=register` with `user_type=driver`
   (`DRV-…` public id).
2. **Verification** — `driver/verify-vehicle.php` → creates `vehicles` (status `pending`)
   + uploads `vehicle_photos` / `vehicle_verifications` (documents). **Admin approves** and
   sets `admin_rating`/`service_category` before the vehicle is usable.
3. **Creating rides** — `driver/dashboard.php` / `driver/trips.php` → `api/rideshare.php?
   action=create` (or `api/v1/driver/create-ride.php`). Sets route, seats, departure,
   `price_per_passenger`, `payment_preference`; the system snapshots
   `commission_amount_per_seat` / `driver_earning_per_seat` at creation.
4. **Wallet requirement (commission reservation)** — creating a ride **reserves** the
   platform commission against the driver's wallet via `ride_commission_reservations`;
   `get_available_wallet_balance()` = balance − outstanding reservations, so a driver can't
   double-commit the same funds. Insufficient available balance blocks ride creation.
5. **Managing passengers** — `api/rideshare.php?action=get_passengers` /
   `get_driver_location` / chat; bookings are **auto-confirmed** (no manual accept step).
6. **Trip lifecycle (Intelligent Trip Engine)** — `transition_trip_state()` +
   `transition_passenger_state()` move a ride through states
   (`PUBLISHED → PASSENGERS_JOINING → PASSENGERS_ASSEMBLED → TRIP_STARTED → TRIP_COMPLETED`,
   or `CANCELLED`). Each transition writes `trip_state_events`, dispatches notifications, and
   posts chat system messages. `start_ride` / `complete_ride` (web) and
   `api/v1/driver/start-trip.php` / `end-trip.php` (API) drive it.
7. **Settlement** — on completion, `settle_ride_completion()` / `settle_ride_reservation()`
   credit the driver's earnings and finalize/release the commission reservation via the
   ledger.

### 4.3 Admin (`admin/dashboard.php`, ~24 tabs)

- **Dashboard** — metrics (daily/weekly/monthly revenue, active drivers, active routes,
  peak hours, ride statistics) via `api/admin-api.php` / `api/admin-reports.php`.
- **User management** — Drivers, Passengers tabs: view/suspend/warn (`admin_user_actions`),
  review profiles (reads historic `ride_requests` + ratings).
- **Vehicles** — approve/reject, set ratings & service category, view documents/edit requests.
- **Ride management** — Ride Sessions, Bookings, Ride Queue Management, Ride Request
  Management, Waitlist, Live Map.
- **Payments/pricing** — Pricing Management, Regional Pricing, Commission, Promotions
  (money-affecting tabs gated by **`require_super_admin`**).
- **Settings/integrations** — Service Integration Center (`api/integration-center.php`,
  email/SMS/registration policy in `integration_settings`), Driver Settings, Driver
  Communication, Premium Features, Diagnostics, Chat Monitoring, Community Growth,
  Announcements.

---

## 5. API Analysis

Two API generations. **Response envelope is identical** for both:
`{ status: "success"|"error", message, data, [pagination] }` (via `json_response()` /
the v1 `v1_success`/`v1_error`).

### 5.1 `api/v1` (modern REST, bearer-token)

- **Auth model (`api/v1/_bootstrap.php`):** required first line of every v1 endpoint.
  Sets JSON content-type, installs global error/exception/shutdown handlers so **only JSON
  ever leaves** (no leaked PHP warnings). `v1_require_auth($pdo)` reads the
  `Authorization: Bearer` token, resolves the `users` row from `user_tokens`, and is the
  **only** way an endpoint learns the caller — **no v1 endpoint trusts a `user_id` in the
  body**. `v1_require_role($user, [...])` enforces user type. Does **not** touch `$_SESSION`.

| Endpoint | Method | Purpose | Auth (role) | Tables |
|---|---|---|---|---|
| `auth/login` | POST | Issue bearer token | public | users, user_tokens |
| `auth/logout` | POST | Revoke token | any | user_tokens |
| `auth/refresh` | POST | Rotate token | any | user_tokens |
| `auth/validate` | GET/POST | Check token validity | any | user_tokens, users |
| `device/register` / `remove` | POST | Push/device registration | any | user_devices |
| `rides/search` | GET | Search rideshares | any | ride_shares |
| `rides/details` | GET | One ride's detail | any | ride_shares, bookings |
| `rides/book` / `rider/book-seat` | POST | Book seat(s) → `create_ride_booking()` | rider | ride_shares, ride_share_bookings, payments, wallet_transactions, promo_* |
| `rides/cancel` / `rider/cancel-booking` | POST | Cancel booking | rider | ride_share_bookings, payments, wallet_transactions |
| `rider/dashboard` | GET | Rider home data | rider | ride_shares, bookings, notifications |
| `rider/trips` | GET | Rider trip history | rider | ride_share_bookings, ride_shares |
| `rider/profile` | GET/POST | View/update profile | rider | users |
| `rider/rating` | POST | Rate driver | rider | driver_ratings, users |
| `rider/notifications` | GET | Rider feed | rider | notifications |
| `rider/waitlist/join` / `leave` | POST | Waitlist a full ride | rider | ride_waitlists |
| `driver/create-ride` | POST | Publish ride (reserves commission) | driver | ride_shares, ride_commission_reservations, wallet_transactions |
| `driver/rides` | GET | Driver's rides | driver | ride_shares |
| `driver/dashboard` | GET | Driver home data | driver | ride_shares, payments |
| `driver/start-trip` / `end-trip` | POST | Trip-state transitions + settlement | driver | ride_shares, trip_state_events, payments, wallet_transactions |
| `driver/update-location` | POST | GPS update | driver | users (lat/lng) |
| `driver/profile` | GET/POST | Driver profile/vehicle | driver | users, vehicles |
| `driver/waitlist` | GET | Ride waitlist mgmt | driver | ride_waitlists |
| `driver/wallet` | GET | Driver wallet view | driver | users, wallet_transactions, ride_commission_reservations |
| `wallet/balance` | GET | Balance (total + available) | any | users, ride_commission_reservations |
| `wallet/history` | GET | Ledger (paginated) | any | wallet_transactions |
| `wallet/topup` | POST | Start Paystack top-up | any | wallet_topups |
| `wallet/payment` | POST | Wallet payment | any | wallet_transactions |
| `notifications` | GET | Notifications feed | any | notifications |
| `files/upload` | POST | File/photo upload | any | (filesystem: uploads/) |

### 5.2 `api/*.php` (legacy/web, session-authenticated, action-dispatch)

Authenticate via `$_SESSION['user_id']`; **note the app-wide quirk:** unauthorized responses
are **HTTP 200 with `{status:'error', message:'Unauthorized'}`** (not a real 401) — the
frontend detects auth failure by **message string** (`api-client.js` `_mtIsAuthFailure`).

Representative/live endpoints:

| File | Purpose | Auth |
|---|---|---|
| `auth.php` | register / login / logout | session (public for register/login) |
| `rideshare.php` | **the big one** — 22 actions: create/search/list/details/book/cancel/cancel_booking/start_ride/complete_ride/get_passengers/get_driver_location/get_earnings/get_ride_history/get_trip_detail/my_rides/my_bookings/price_preview/rate_ride/confirm_direct_payment/reject_direct_payment/get_recommended_rides/update | session |
| `ride-queue.php`, `ride-requests.php`, `ride-status.php` | demand queue / request mgmt / status polling | session |
| `wallet.php` | wallet view/actions | session |
| `paystack-callback.php` | browser return from Paystack (UI convenience) | session |
| `paystack-webhook.php` | **server-to-server** top-up confirmation (HMAC-verified) | Paystack signature |
| `location-search.php`, `locations.php`, `saved-locations.php`, `regions.php`, `maps-key.php` | geocode/search, saved places, regions, map key | session (maps-key returns parked Google key) |
| `nearby-drivers.php`, `nearby-riders.php`, `update-location.php`, `toggle-online.php` | presence/GPS | session |
| `rate-driver.php`, `rate-passenger.php` | ratings | session |
| `notifications.php`, `admin-notifications.php`, `messages.php`, `chat.php`, `call-management.php` | notifications, chat, calls | session (`chat.php` is dead legacy) |
| `vehicle-info.php`, `verification.php`, `password-reset.php`, `profile.php`, `dashboard.php` | vehicle/verify/reset/profile/dashboard | session |
| `driver-api.php`, `driver-settings.php`, `driver-comms.php`, `fare-settings.php` | driver + fare admin | session / admin |
| `admin-api.php`, `admin-reports.php`, `pricing-admin.php`, `referral-admin.php`, `integration-center.php`, `premium.php`, `promotions.php` | admin surfaces | session `admin` / `require_super_admin` for money |
| `referrals.php`, `trip-collaboration.php` | referrals, collaborative trips | session |
| `header.php`, `footer.php` | fragment endpoints | session |
| **Dead legacy** (`LEGACY_CODE.md`, 0 refs): `request-ride.php`, `submit-offer.php`, `accept-offer.php`, `respond-ride.php`, `fetch-offers.php`, `pending-requests.php`, `chat.php` | old single-passenger negotiation + old chat | — |

---

## 6. Authentication & Security Review

**Positive findings**
- **Password storage:** `password_hash(..., PASSWORD_DEFAULT)` (bcrypt) everywhere;
  verification via `password_verify`. OTPs and reset tokens are **also hashed** before
  storage (`verification.php`). ✔
- **SQL injection:** **PDO prepared statements throughout** — no string-interpolated user
  input in queries observed. Dynamic table/column names in `generate_unique_public_id()` are
  hardcoded call-site literals, not user input. ✔
- **Session fixation:** `session_regenerate_id(true)` on successful login. ✔
- **Brute force:** login lockout (5 attempts / 15 min), suspended-account block. ✔
- **Verification gates:** email+phone verification enforced at login and again inside
  `create_ride_booking()`. ✔
- **API v1 hardening:** never trusts body `user_id`; token-only identity; global handlers
  guarantee JSON-only output; bearer tokens stored hashed. ✔
- **Paystack webhook:** HMAC-SHA512 signature check (`hash_equals`), **re-verifies against
  Paystack API** before crediting, **idempotent** (`PAYMENT_PENDING` guard). ✔
- **Money-action gating:** `require_super_admin()` for pricing/commission/promo/regional. ✔
- **XSS:** `sanitize_input()` applies `htmlspecialchars`. Frontend `MohToh.storage` refuses
  to persist keys matching `password|token|secret|wallet|card|cvv|pin|auth`. SW never caches
  `/api/`/wallet/payment/admin. ✔

**Risks / weaknesses**
- 🔴 **`cookie.txt` committed** at repo root containing a real `PHPSESSID` value and host
  `app.mohtoh.com.ng` — a leftover curl cookie jar. Should be deleted / git-ignored.
- 🔴 **Default admin credentials** in `database/seed_admin.php`:
  `admin@meritdrives.com` / `adminpassword`. Must be rotated before any deployment.
- 🟠 **Hardcoded secrets in `includes/config.php`:**
  - A **real-looking Google Maps API key** (line ~20) — parked/unused but exposed in source.
  - Paystack keys are placeholders (`sk_test_REPLACE_ME`) — fine, but keys belong in `.env`.
  - `APP_ENCRYPTION_KEY` has a **hardcoded fallback default** if env is unset — weak if it
    ever runs with the default.
- 🟠 **No CSRF protection** on the session-authenticated `api/*.php` mutating endpoints
  (register/login/book/cancel/etc.). Same-origin `fetch` + session cookie only; a CSRF token
  scheme is absent. (Cookie `SameSite`/`HttpOnly`/`Secure` flags are **not explicitly set**
  anywhere — relies on PHP/Apache defaults.)
- 🟠 **Unauthorized = HTTP 200** everywhere on the legacy API. Functionally handled by the
  client, but it breaks HTTP semantics, complicates monitoring, and any new/native consumer
  that checks status codes will misread auth failures.
- 🟠 **`includes/db.php` runs DDL at runtime** (`ALTER TABLE users ADD wallet_balance` inside
  the connection path) and dies with the raw PDO message on failure — schema drift + info
  leak risk. DB credentials are the XAMPP defaults (`root` / empty).
- 🟡 **`APP_ENV=LOCAL`** and `display_errors` behavior differ by env; ensure production sets
  `PRODUCTION` and suppresses errors (v1 already forces `display_errors=0`; legacy pages don't
  uniformly).
- 🟡 Encryption: `APP_ENCRYPTION_KEY` exists but its usage is limited; most sensitive data is
  hashed rather than reversibly encrypted (good), so the key's blast radius is small.

---

## 7. Map & Location System

- **Provider (active): Leaflet 1.9.4 + OpenStreetMap tiles.** `assets/js/map.js` is the
  **single provider abstraction** — callers only use `mtMap`, `centerMapOnUser()`,
  `placeUserMarker()`, `drawRoute()`, `clearRoute()`, so swapping providers touches one file.
- **Google Maps: parked.** Key present in `config.php` and served by `api/maps-key.php` but
  **not loaded by any page** (blocked on Cloud billing per code comments).
- **Geocoding / search:** `api/location-search.php` (+ `location_search_cache` table) — an
  **OSM Nominatim-style** search with server-side caching to cut request volume.
- **Directions:** **None (no routing engine).** `drawRoute()` draws a **straight polyline**
  between pickup and destination — not road-following directions. No turn-by-turn.
- **Distance calculation:** **Haversine great-circle** in PHP (`calculate_distance_km()`),
  not driving distance. Feeds `FareCalculator`.
- **GPS tracking:** `MohToh.location` bridge (`navigator.geolocation`) → `api/update-location.php`
  / `api/v1/driver/update-location.php` writes `users.latitude/longitude/last_location_update`.
  `toggle-online.php` sets availability. Admin Live Map + `nearby-drivers/riders` read these.
- **Saved locations:** `saved_locations` table + `api/saved-locations.php` (home/work/etc.).
- **Limitations:** straight-line distance/route only (fares & ETAs will understate real road
  distance); no traffic/ETA; Nominatim rate limits; no map provider redundancy; geolocation
  accuracy depends on the device/browser.

---

## 8. Payment & Wallet System

- **Paystack (driver wallet top-ups):**
  - `paystack_config()` resolves test/live keys by `PAYSTACK_MODE`; **refuses to run `live`
    with empty live keys** (no silent fallback to test).
  - Top-up starts (`wallet/topup` / `wallet.php`) → `wallet_topups` row (`PAYMENT_PENDING`,
    `public_id` = Paystack reference).
  - **Webhook (`paystack-webhook.php`) is the source of truth:** HMAC-verify → ignore
    non-`charge.success` → look up `wallet_topups` by reference → **re-verify via Paystack
    API** → `finalize_wallet_topup()` in a transaction (credits wallet). Idempotent.
  - `paystack-callback.php` is the browser-return convenience path (can't be sole source
    since users may close the tab).
- **Wallet ledger:** `credit_wallet()` / `debit_wallet()` are the **only** functions that
  move money. Every move writes a `wallet_transactions` row with `balance_after` + unique
  `TXN-…` reference and fires a wallet notification. `debit_wallet()` locks the row
  `FOR UPDATE` and blocks on insufficient funds (unless explicit `$allowNegative`).
- **Driver commission (reservation model):** ride creation **reserves** commission in
  `ride_commission_reservations` (a logical hold, not a real debit). `log_wallet_event()`
  records reserve/use/release as **audit-only** ledger rows (no balance change).
  `get_available_wallet_balance()` = `wallet_balance − (reserved − used − released)` — this
  is what every solvency check should use, preventing double-committing funds across rides.
- **Passenger payments (`create_ride_booking()`):** resolved against the ride's
  `payment_preference` (`wallet`/`direct`/`both`). **Wallet** → immediate debit,
  `payments.status = PAYMENT_CONFIRMED`. **Direct** → `PAYMENT_PENDING`, later confirmed or
  rejected by the driver (`confirm_direct_payment` / `reject_direct_payment`). Fare +
  `commission_amount` + `driver_earning_amount` snapshotted per booking.
- **Promotions:** validated inside the booking transaction (wallet only); platform absorbs
  the discount out of its commission share, not driver earnings; `promo_redemptions` enforces
  per-user/global/first-ride limits.
- **Settlement:** on trip completion `settle_ride_completion()` / `settle_ride_reservation()`
  credit driver earnings and finalize/release the commission hold.
- **Refunds / cancellation:** booking cancellation paths (`rides/cancel`,
  `rideshare.php?action=cancel_booking`) reverse via the ledger and restore seats;
  `rideshare.php` line ~476 carries an explicit **bug-fix note** about a prior version that
  cancelled *every* booking on cancel — verify this path during any refactor.

---

## 9. Frontend Analysis

- **Main JS (`assets/js/`, vanilla ES6, no framework):**
  - `api-client.js` — **service layer** (`apiGet/apiPost/apiUpload`); guarantees the
    `{status,message,data}` envelope even on network/timeout/parse failure; central
    auth-failure redirect.
  - `mohtoh-bridge.js` — **device abstraction** (`window.MohToh`: location, camera, files,
    share, storage, notification, network, biometric) — the MAUI swap boundary.
  - `helper.js` (27KB), `state.js`, `navigation.js` (drawer, notification bell polling,
    logout), `search.js`, `driver.js` (71KB), `driver-trips.js`, `driver-ride-requests.js`,
    `rider-trips.js`, `rider-trip.js`, `ride-requests.js`, `profile.js`, `messages.js`,
    `referrals.js`, `map.js`, `sound-manager.js`, `auth.js`, `forgot/reset/verify-*.js`,
    `pwa-init.js`.
- **Page loading:** classic **multi-page app** — each PHP page is a full document that
  includes `partials/*` and loads its own JS modules. **Not an SPA**; navigation is real
  page loads (bottom-nav links + sidebar drawer). AJAX is used **within** a page for data.
- **Navigation:** `partials/bottom-nav.php` (primary), `partials/sidebar.php` drawer
  (hamburger), `partials/app-header.php`, plus bottom-sheets (notifications/messages/rewards).
  `navigation.js` wires drawer + "coming soon" affordances + notification polling.
- **AJAX/API calls:** all through `api-client.js` (new code) with `credentials:'same-origin'`;
  legacy modules still use `helper.js` `postJson()` (being migrated).
- **Components:** CSS design system in `assets/css` (`variables`, `reset`, `layout`,
  `components` 43KB, `mobile-shell`, `utilities`, `animations`, `responsive`); Bootstrap 5 for
  spacing utilities only.
- **Mobile responsiveness / PWA:** mobile-first "app shell" (`viewport-fit=cover`, safe-area
  insets, `mobile-shell.css`), installable (`manifest.json`, theme `#0F8B8D`), offline via
  `service-worker.js` (+ `offline.html`), sound + Web Notifications via the bridge. Admin
  dashboard is separately Bootstrap-based and desktop-oriented (off-canvas sidebar on mobile).

---

## 10. Existing Bugs / Risks

**Security (from §6)**
- 🔴 `cookie.txt` with a live session id committed at repo root.
- 🔴 Default admin creds in `seed_admin.php`.
- 🟠 Hardcoded Google Maps key + `APP_ENCRYPTION_KEY` default fallback in `config.php`.
- 🟠 No CSRF tokens; session cookie flags (`HttpOnly/Secure/SameSite`) not explicitly set.
- 🟠 `db.php` performs runtime `ALTER TABLE` and `die()`s with raw DB errors; root/empty creds.

**Architecture / maintainability**
- 🟠 **Two parallel generations** (legacy request/offer vs current rideshare) and **two APIs**
  (`api/` session vs `api/v1` token) increase surface area and confusion.
- 🟠 **Dead code retained** (`request-ride.php`, `submit-offer.php`, `accept-offer.php`,
  `respond-ride.php`, `fetch-offers.php`, `pending-requests.php`, `chat.php`, top-level
  `js/app.js`, `js/`, `css/`, `_legacy_archive/`) — per `LEGACY_CODE.md`, unreferenced but not
  removed. Risk: contributors editing dead files.
- 🟠 **Duplicate logic risk** mitigated well in places (`create_ride_booking()` shared by web +
  v1 + ride-queue) but `helper.js` `postJson()` vs `api-client.js` still coexist.
- 🟠 **`api/rideshare.php` is a 22-action monolith** — hard to test/maintain.
- 🟡 **Stale docs:** `IMPLEMENTATION_STATUS.md` describes the superseded flow as current.
- 🟡 **Unauthorized-as-200** breaks HTTP semantics across the legacy API.

**Functional / product limitations**
- 🟡 **Straight-line routing & distance only** (no road routing) — fares/ETAs understate reality.
- 🟡 **Polling, not realtime** — chat/calls/notifications/live-map all poll (load + latency).
- 🟡 **Call system is signaling-only** (no WebRTC media).
- 🟡 **Provider dependencies unconfigured by default** (email/SMS in `log` mode; Paystack in
  `test` with placeholder keys) — nothing actually sends/charges until configured.

**Missing files / broken links**
- No literal 404s were found in the includes graph (bootstrap + requires resolve). Main gaps
  are **unwired legacy files** (present but nothing links to them) rather than missing files.
  `admin/` has only `dashboard.php` — all admin actions run through `api/*.php`.

---

## 11. Implementation Map

### A. Current System Architecture (text diagram)

```
                          ┌──────────────────────────────────────────────┐
                          │                CLIENTS                        │
                          │  Mobile browser PWA  │  (future) .NET MAUI    │
                          │  installable, SW      │  WebView + native     │
                          │  offline.html         │  bridge (MOHTOH_RUNTIME)│
                          └───────────────┬──────────────────┬───────────┘
                                          │ session cookie    │ Bearer token
                                          │ (same-origin)     │ (user_tokens)
              ┌───────────────────────────▼───────┐   ┌──────▼────────────────────┐
              │  PHP PAGES (server-rendered)       │   │  api/v1/** (REST, JSON)   │
              │  index / rider/* / driver/* /      │   │  _bootstrap: v1_require_  │
              │  admin/dashboard.php + partials/*  │   │  auth + role, JSON-only   │
              └───────────────┬────────────────────┘   └──────────┬────────────────┘
                              │ AJAX (api-client.js)               │
                              ▼                                    ▼
              ┌───────────────────────────────────────────────────────────────────┐
              │  api/*.php  (legacy/web, action-dispatch, session-auth)            │
              │  auth · rideshare(22 actions) · wallet · paystack-* · location-*   │
              │  ratings · notifications · chat · admin-* · pricing/referral/…     │
              └───────────────┬───────────────────────────────────────────────────┘
                              │  (both API generations call the SAME core)
                              ▼
   ┌───────────────────────────────────────────────────────────────────────────────┐
   │  includes/  — BUSINESS LOGIC (single source of truth)                          │
   │  functions.php (wallet ledger, commission reservations, Trip Engine,           │
   │   notifications, haversine, IDs, pagination) · booking.php (create_ride_booking)│
   │  verification.php (OTP/email/reset/lockout) · FareCalculator · chat · referral  │
   │  ride_queue · ride_requests · config · environment · api_tokens · secrets       │
   └───────────────┬─────────────────────────────────┬─────────────────────────────┘
                   ▼                                   ▼
        ┌────────────────────┐              ┌───────────────────────────────┐
        │  MySQL (PDO)        │              │  External services            │
        │  ~49 tables         │              │  Paystack (payments)          │
        │  users hub +        │              │  OSM/Nominatim (map/geocode)  │
        │  ride_shares +      │              │  SMTP/PHPMailer (email)       │
        │  wallet ledger      │              │  Termii/Twilio/Vonage/AT (SMS)│
        └────────────────────┘              └───────────────────────────────┘
```

### B. Feature Inventory

**✅ Working (implemented + wired)**
- Registration / login / logout with hashing, phone normalization, lockout, session
  regeneration.
- Email + phone OTP verification; forgot/reset password.
- Rideshare create / search / details / **book** (wallet + direct + promo) / cancel.
- Wallet ledger (credit/debit) + Paystack top-up (webhook HMAC + re-verify + idempotent).
- Driver commission reservation + available-balance model + completion settlement.
- Intelligent Trip Engine (trip/passenger state machines + event logs + notifications + chat).
- Ratings (driver↔passenger) updating `users.rating`.
- In-app Notification Engine (feed + priorities + preferences).
- Chat (conversations/members/messages) + call signaling (`call_sessions`).
- Leaflet+OSM map, GPS updates, saved locations, Nominatim search w/ cache.
- Admin dashboard (24 tabs) incl. super-admin-gated money settings; Service Integration Center.
- Referrals/rewards, promo codes, premium features.
- PWA (install/offline/service worker) + device bridge for MAUI.
- `api/v1` token API mirroring the web flows through shared logic.

**⚠️ Incomplete / partial**
- **Routing/directions:** straight-line only; no road routing, ETA, or turn-by-turn.
- **Google Maps:** parked (key present, not loaded).
- **Email/SMS delivery:** adapters exist but default to `log` mode (no real sends until configured).
- **Paystack:** test mode with placeholder keys (no real charges until live keys set).
- **Realtime:** polling-based; WebSocket upgrade pending.
- **Call media:** signaling only, no WebRTC audio/video.
- **Admin UI depth:** some management flows described in `IMPLEMENTATION_STATUS.md` (fare/rating
  UIs) predate the current model — verify each tab against live endpoints.

**❌ Broken / dead (present but unreferenced — `LEGACY_CODE.md`)**
- Legacy single-passenger flow: `api/request-ride.php`, `submit-offer.php`, `accept-offer.php`,
  `respond-ride.php`, `fetch-offers.php`, `pending-requests.php` (and `nearby-drivers/riders`
  as used by that flow).
- Old chat backend: `api/chat.php`.
- Old frontend: top-level `js/app.js` (+ `js/`, `css/`), `_legacy_archive/`.
- `ride_requests` **table** is retained (read by admin review-profile) even though its API is dead.

### C. Recommended Implementation Order

**Fix first (safety/hygiene — low risk, high value)**
1. **Remove `cookie.txt`** from the tree and add to ignore; treat that session as compromised.
2. **Rotate/parametrize admin seed** — no default `adminpassword`; move to env/first-run setup.
3. **Move secrets to `.env`** — Google Maps key, Paystack keys, `APP_ENCRYPTION_KEY`
   (remove hardcoded fallback); ensure DB creds are env-driven, not `root`/empty in prod.
4. **Harden sessions** — set `HttpOnly`, `Secure`, `SameSite=Lax/Strict` cookie params;
   add **CSRF tokens** to session-authenticated mutating endpoints.
5. **Stop runtime DDL in `db.php`** and stop echoing raw PDO errors; fold that column into a
   migration.

**Then (correctness / clarity)**
6. **Return real HTTP 401** for unauthorized (or at least document the 200 quirk) before any
   new/native client is built against the legacy API.
7. **Verify cancellation/refund paths** (esp. `rideshare.php` ~L476 note) with tests around
   the wallet ledger + seat restoration + commission release.
8. **Archive dead code** into `_legacy_archive/` (per `LEGACY_CODE.md` plan) after re-running
   the reference search — **keep the `ride_requests` table**.
9. **Reconcile docs** — supersede `IMPLEMENTATION_STATUS.md` with the current rideshare/trip-
   engine reality (or mark it historical).

**Later (product upgrades)**
10. **Real routing/distance** (OSRM/GraphHopper/Google Directions) to fix fare/ETA accuracy —
    isolated to `map.js` + `FareCalculator` + distance helper.
11. **Realtime** (WebSockets/SSE) to replace polling for chat/notifications/live-map.
12. **Configure providers** (SMTP + one SMS provider + Paystack live) behind the Integration
    Center.

**Do NOT touch (working, load-bearing, or intentionally shared)**
- `includes/functions.php` wallet ledger (`credit_wallet`/`debit_wallet`) and commission
  reservation math — the money core; change only with tests.
- `includes/booking.php` `create_ride_booking()` — shared by web + `api/v1` + ride-queue;
  a change ripples to all three.
- `paystack-webhook.php` verification/idempotency logic — correct as-is.
- The `map.js` provider abstraction contract and `mohtoh-bridge.js` method signatures —
  changing signatures breaks every page and the MAUI plan.
- The `api/v1/_bootstrap.php` auth/JSON-envelope contract.
- The `ride_requests` table (historical admin dependency).

**Key dependencies between features**
- Booking → wallet ledger → commission reservations → settlement (tightly coupled; treat as one
  unit).
- Trip Engine (`transition_trip_state`) → notifications + chat system messages + referral hooks
  (one dispatcher fans out; don't add parallel notification paths).
- Any map/provider change is localized to `map.js`; any device-capability change is localized to
  `mohtoh-bridge.js` — this isolation is the project's main architectural asset and should be
  preserved.

---

*End of analysis. No application files were modified during this audit; the only file created is
this `MOHTOH_SYSTEM_ANALYSIS.md`.*
