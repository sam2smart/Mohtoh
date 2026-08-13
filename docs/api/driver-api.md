# Driver API

All endpoints below require `Authorization: Bearer <token>` for a user whose `user_type` is `driver` (mismatched role → 403 `forbidden`). See [authentication.md](authentication.md).

## GET /api/v1/driver/profile.php

**Success `data`:** `id`, `public_id`, `fullname`, `email`, `phone`, `rating`, `completed_rides`, `wallet_balance`, `profile_image`, `is_verified` (bool), `is_online` (bool), `driver_tier` (`standard`/`premium`), `payment_preference`, `email_verified`, `phone_verified`, and `vehicle` (the driver's full `vehicles` row, or `null` if none registered yet).

## GET /api/v1/driver/dashboard.php

**Success `data`:** `wallet_balance` (float), `available_balance` (float — via `get_available_wallet_balance()`, i.e. total balance minus active commission reservations), `unread_notifications` (int), `is_online` (bool), `is_verified` (bool), `active_ride` (the driver's own currently `active`/`full`/`in_progress` ride, or `null`).

## GET /api/v1/driver/wallet.php

Paginated. Like the rider wallet endpoint, plus driver-specific figures.

**Success `data`:** `balance`, `available_balance`, `reserved_balance` (sum of active commission reservations), `active_reservations` (array — `public_id`, `ride_share_id`, `ride_public_id`, `destination`, `reserved_amount`, `used_amount`, `released_amount`, `status`, `created_at`), `payment_preference`, `history` (same `wallet_transactions` shape as the rider endpoint).

## POST /api/v1/driver/create-ride.php

Publishes a new ride share. This is the most complex write endpoint — it runs the same pricing/commission/solvency pipeline as the web "Create Ride" flow, calling every real function in order: `calculate_distance_km()` → `calculate_recommended_seat_price()` → `resolve_price_per_passenger()` → `get_commission_percentage()` → `get_available_wallet_balance()` (solvency check) → insert `ride_shares` → `transition_trip_state(..., 'PUBLISHED', ...)` → `create_commission_reservation()` → `track_referral_event(..., 'FIRST_RIDE_CREATED')` → `match_ride_requests()` (notifies anyone whose open Ride Request matches this new ride).

**Body:**
```json
{
  "pickup_location": "Ikeja",
  "destination": "Lekki Phase 1",
  "departure_date": "2026-07-28",
  "departure_time": "08:00:00",
  "pickup_lat": 6.6018,
  "pickup_lng": 3.3515,
  "destination_lat": 6.4432,
  "destination_lng": 3.4235,
  "price_per_passenger": null,
  "description": ""
}
```
Coordinates are required (distance/pricing cannot be computed without them). `price_per_passenger` is optional — omit/`null` to accept the system-recommended price; supplying a value asks `resolve_price_per_passenger()` to validate it's within the allowed band.

**Preconditions enforced (in order), each its own error `code`:**
- `vehicle_not_verified` (400) — no vehicle on file, or vehicle not yet `approved` (message distinguishes `rejected` from `pending`)
- `driver_not_verified` (400) — `users.is_verified` false (normally flips true automatically once the vehicle is approved)
- `contact_not_verified` (403) — email or phone not verified
- `max_active_rides` (400) — driver already at the `max_active_rides_per_driver` pricing-setting cap (non-premium drivers only)
- `validation_error` (400) — missing required field or missing coordinates
- `pricing_error` (400) — a supplied `price_per_passenger` fails `resolve_price_per_passenger()`'s validation
- `insufficient_wallet_reserve` / `insufficient_wallet_balance` (400) — wallet can't cover the required commission reserve

**Success `data`:** `ride_share_id`, `ride_public_id`, `price_per_passenger`, `total_seats`, `commission_amount_per_seat`, `driver_earning_per_seat`, `potential_revenue`, `driver_expected_earnings`, `commission_reserved`, `breakdown` (pricing breakdown object).

## GET /api/v1/driver/rides.php

Paginated (the equivalent web action returns everything unpaginated — pagination was added here specifically for the mobile list view, per the "check references before changing a shared file" safety rule: nothing about the web action's behavior was touched, this is a new endpoint).

**Success `data`:** `rides` (array of the driver's `ride_shares` rows plus `passengers_joined`, `waiting_count` (Ride Queue), and active reservation figures) and `stats` (`total_rides`, `upcoming_rides`, `completed_rides`, `total_passengers`).

## POST /api/v1/driver/start-trip.php

Delegates directly to `mark_ride_started()`.

**Body:** `{ "ride_id": 42 }`

**Error code:** `start_trip_failed` (400) — ride not found for this driver, or not in a startable state.

## POST /api/v1/driver/end-trip.php

Same orchestration as the web "Complete Ride" action: marks the ride/bookings completed, calls `transition_trip_state(..., 'TRIP_COMPLETED', ...)`, then `settle_ride_completion()` (the function that actually credits the driver's earnings — never done manually here).

**Body:** `{ "ride_id": 42 }`

**Success `data`:** `{ "driver_earning_credited": 4500.0 }`

**Error code:** `end_trip_failed` (400) — ride not found for this driver or not currently `in_progress`.

## POST /api/v1/driver/update-location.php

Live GPS ping while a trip is active. **This endpoint is a bridge, not a reimplementation**: it authenticates the caller via bearer token, then runs the original `api/update-location.php` file completely unmodified — that file is the entire Intelligent Trip Engine (geofencing, auto-boarding, auto-start, auto-complete staging). It is intentionally never duplicated.

**Body:** same shape `api/update-location.php` already expects, e.g.:
```json
{ "latitude": 6.6018, "longitude": 3.3515 }
```

**Success `data`:** whatever the trip engine returns, e.g. `{ "latitude": 6.6018, "longitude": 3.3515, "proximity_triggered": false, "new_status": null }`.

## GET /api/v1/driver/waitlist.php?ride_id=

Ride Queue demand summary for one of the driver's **own** rides — a count/summary only, never the identities of queued passengers (same privacy rule the web driver view enforces).

**Errors:** `validation_error` (400, missing `ride_id`), `not_found` (404), `forbidden` (403, ride belongs to a different driver).

**Success `data`:** `route` (string), `summary` (whatever `get_ride_queue_summary()` returns).
