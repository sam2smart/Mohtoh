# Rides API

Cross-role ride catalog — search and view any ride, book/cancel your own. All under `/api/v1/rides/`. `Authorization: Bearer <token>` required on every endpoint (any role — rider or driver may call `search`/`details`; `book`/`cancel` are functionally rider actions since they delegate to the rider booking endpoints).

There is exactly one ride model in this codebase, `ride_shares` (with `ride_share_bookings` for passenger bookings) — no separate model was introduced for the API.

## GET /api/v1/rides/search.php

**Query params (required unless noted):** `pickup_location`, `destination`, `departure_date` (`YYYY-MM-DD`), `departure_time` (optional — if given, only rides departing at or after this time are returned).

Matches use `LIKE '%…%'` on location text, same as the web search.

**Success `data`:** `{ "rides": [ … ] }` — each row: `id`, `public_id`, `driver_id`, `start_location`, `destination`, `departure_date`, `departure_time`, `total_seats`, `available_seats`, `status`, `price_per_passenger`, `pricing_mode`, coordinates, `driver_name`, `driver_rating`, `driver_image`, `driver_tier`, `vehicle_make`, `vehicle_model`, `luggage_capacity`, plus a computed `distance_km` (1 decimal place, straight-line via `calculate_distance_km()`).

Only rides with status `active` or `full` are returned.

## GET /api/v1/rides/details.php?ride_id=

**Success `data`:** `{ "ride": { … } }` — the ride's full public fields, plus:
- `already_joined` (bool) — whether the calling user already has a non-cancelled booking on this ride
- `remaining_seats` (int)
- `queue_entry` — the caller's own Ride Queue entry on this ride (`public_id`, `status`, `seats_requested`), or `null`; only ever populated for riders
- `distance_km`

`commission_percentage` is stripped from the response unless the caller is the ride's own driver — never exposed to riders or other drivers.

**Error:** `not_found` (404) if the ride doesn't exist.

## POST /api/v1/rides/book.php

Pure delegate — identical request/response contract as [`POST /api/v1/rider/book-seat.php`](rider-api.md#post-apiv1riderbook-seatphp). Provided so a Flutter client browsing the ride catalog can book without switching namespaces; it is not a second implementation.

## POST /api/v1/rides/cancel.php

Pure delegate — identical request/response contract as [`POST /api/v1/rider/cancel-booking.php`](rider-api.md#post-apiv1ridercancel-bookingphp).
