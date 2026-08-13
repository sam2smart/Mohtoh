# MohToh API v1

Base path: `/merit_drives/api/v1/` (adjust the host/base per environment — this is the same codebase the web app runs from, just a new entry point under `api/v1/`).

This API exists so the Flutter Rider and Driver apps can consume the exact same business logic the web app already uses — wallet, trip engine, booking, pricing, commission, notifications. It does not introduce a second implementation of any business rule. Every endpoint either:

1. Runs a read-only query directly (safe to duplicate — it's not a business rule, just a projection of data), or
2. Calls the same reusable functions the web app calls (`credit_wallet()`, `debit_wallet()`, `create_notification()`/`notify_user()`, `transition_trip_state()`, `transition_passenger_state()`, `create_ride_booking()`, `mark_ride_started()`, `settle_ride_completion()`, `join_queue()`/`leave_queue()`, etc.), or
3. For one endpoint only (`driver/update-location.php`), authenticates the caller and then runs the original, unmodified `api/update-location.php` file — the Intelligent Trip Engine's location/geofencing state machine is too large and too easy to accidentally fork into two divergent copies, so it is never duplicated, only bridged.

The existing web application (`api/*.php`, session-based) is untouched and continues to work exactly as before. Token auth (this API) and session auth (the web app) are two independent front doors to the same backend.

## Contents

- [authentication.md](authentication.md) — login, logout, refresh, validate, bearer tokens
- [rider-api.md](rider-api.md) — profile, dashboard, wallet, trips, notifications, booking, cancellation, rating, waitlist
- [driver-api.md](driver-api.md) — profile, dashboard, wallet, ride creation, trip start/end, location updates, waitlist
- [rides-api.md](rides-api.md) — search, details, book, cancel (shared rider/driver-facing ride catalog)
- [wallet-api.md](wallet-api.md) — balance, history, top-up (Paystack), direct-payment confirmation
- [notifications-api.md](notifications-api.md) — notification feed, push-device registration
- [files-api.md](files-api.md) — profile image, vehicle photo, vehicle document upload
- [error-codes.md](error-codes.md) — every `code` value the API can return, and what it means

## Response envelope

Every endpoint, with no exception, returns one of these two JSON shapes. Nothing else — no raw PHP warnings, no HTML, no bare database error text.

**Success:**
```json
{
  "status": "success",
  "message": "Human-readable summary of what happened.",
  "data": { }
}
```

**Success with pagination** (GET list endpoints only) adds a `pagination` key alongside `data`:
```json
{
  "status": "success",
  "message": "…",
  "data": { },
  "pagination": {
    "current_page": 1,
    "per_page": 20,
    "total_records": 57,
    "total_pages": 3
  }
}
```

**Error:**
```json
{
  "status": "error",
  "message": "Human-readable, safe-to-display explanation.",
  "code": "short_machine_readable_slug"
}
```

Always check `status` first. `data` is only present on success; `code` is only present on error. See [error-codes.md](error-codes.md) for the full list of `code` values and their HTTP status.

## Conventions for every endpoint

- **Auth**: every endpoint except `auth/login.php` requires `Authorization: Bearer <token>`. See [authentication.md](authentication.md).
- **Method**: GET endpoints read query-string parameters; POST endpoints read a JSON body (`Content-Type: application/json`), except `files/upload.php` which is `multipart/form-data`.
- **The acting user is always the token's own user.** No endpoint reads `user_id` from the request body or query string for identity — Flutter never needs to send it, and sending one has no effect.
- **File paths as URLs**: this codebase has no router — every path below is a literal `.php` file. Call it with the `.php` extension, e.g. `POST /api/v1/rider/book-seat.php`.
- **Pagination** (GET list endpoints): accepts `?page=` and `?limit=` query params (both optional, sensible defaults apply).
