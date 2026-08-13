# Rider API

All endpoints below require `Authorization: Bearer <token>` for a user whose `user_type` is `rider` (mismatched role → 403 `forbidden`). See [authentication.md](authentication.md).

## GET /api/v1/rider/profile.php

**Success `data`:** `id`, `public_id`, `fullname`, `email`, `phone`, `rating` (float), `completed_rides` (int), `wallet_balance` (float), `profile_image`, `payment_preference`, `email_verified` (bool), `phone_verified` (bool), `created_at`.

## GET /api/v1/rider/dashboard.php

Single-call summary screen.

**Success `data`:**
```json
{
  "wallet_balance": 5000.0,
  "unread_notifications": 3,
  "active_trip": {
    "ride_share_id": 42, "public_id": "RIDE-XXXX", "start_location": "…", "destination": "…",
    "departure_date": "2026-07-28", "departure_time": "08:00:00", "trip_state": "IN_PROGRESS",
    "ride_status": "in_progress", "driver_name": "…", "driver_phone": "…", "driver_rating": 4.8,
    "booking_id": 501, "seats_booked": 1, "passenger_state": "on_trip"
  }
}
```
`active_trip` is `null` if the rider has no live (non-cancelled/completed) booking on a ride that is `active`/`full`/`in_progress`.

## GET /api/v1/rider/wallet.php

Paginated (`?page=&limit=`). **Success `data`:** `balance` (float), `payment_preference`, `history` (array of `wallet_transactions` rows: `id`, `reference`, `amount`, `type`, `category`, `description`, `ride_share_id`, `booking_id`, `balance_after`, `status`, `created_at`).

## GET /api/v1/rider/trips.php

Paginated. Full ride history (all bookings, any status), same shape as the web "My Bookings" tab.

**Success `data`:** `rides` (array — booking + ride + driver + vehicle + payment + rating fields joined together per row) and `stats` (`joined_rides`, `upcoming_trips`, `completed_trips`, `cancelled_trips`, all int).

## GET /api/v1/rider/notifications.php

Paginated. Priority-ordered (critical → high → normal → low, newest first within each tier) — same ordering rule as the rest of the app's notification system.

**Success `data`:** `notifications` (array of `public_id`, `title`, `message`, `notification_type`, `priority`, `icon`, `color`, `link`, `action`, `action_id`, `action_url`, `related_table`, `related_public_id`, `is_read`, `read_at`, `created_at`), `unread_count` (int).

## POST /api/v1/rider/book-seat.php

Books seat(s) on a ride share. Delegates entirely to `create_ride_booking()` — the exact same wallet/payment/commission/validation logic the web app uses, no shortcuts.

**Body:**
```json
{ "ride_share_id": 42, "seats_booked": 1, "payment_method": "wallet", "promo_code": "" }
```
`seats_booked` defaults to 1 if omitted. `payment_method` and `promo_code` are optional.

**Success `data`:** whatever `create_ride_booking()` returns (booking id, fare breakdown, etc. — identical to the web app's response for the same action).

**Error code:** `booking_failed` (400) — `message` carries the specific reason (e.g. insufficient wallet balance, ride full).

## POST /api/v1/rider/cancel-booking.php

Cancels the rider's own active (non-cancelled/completed) booking. Refunds via `credit_wallet()` if the booking was wallet-paid (wallet balance is never written to directly); if it was a direct/cash payment, marks the pending payment `PAYMENT_FAILED` instead. Posts a system chat message and removes the rider from the ride's chat, then runs `process_queue()` so anyone on the Ride Queue for that ride is notified of the newly-freed seat.

**Body:** `{ "booking_id": 501 }`

**Success:** `"Booking cancelled successfully."`, no data payload.

**Error code:** `cancel_failed` (400) — e.g. booking not found, already cancelled/completed, or not owned by the caller.

## POST /api/v1/rider/rating.php

Rates a completed ride. One rating per booking — a second attempt is rejected.

**Body:** `{ "booking_id": 501, "rating": 5, "comment": "Great ride!" }`

`rating` must be an integer 1–5. `comment` is optional.

**Success:** `"Thank you for your feedback!"`. Recomputes the driver's average `rating` and, if the driver has `notify_on_rating` enabled, sends them a notification.

**Errors:** `validation_error` (400, missing/out-of-range rating), `rating_failed` (400 — booking not completed, not owned by caller, or already rated).

## POST /api/v1/rider/waitlist/join.php

Joins the **Ride Queue** for a specific full ride (this is System 2 — demand against one specific `ride_shares` row — not the general Ride Requests system). Delegates to `join_queue()`.

**Body:** `{ "ride_share_id": 42, "seats_requested": 1 }` (`seats_requested` defaults to 1)

**Error code:** `queue_join_failed` (400).

## POST /api/v1/rider/waitlist/leave.php

Leaves a Ride Queue entry. Delegates to `leave_queue()`.

**Body:** `{ "queue_public_id": "QUEUE-XXXX" }` (the field `waitlist_public_id` is also accepted as an alias)

**Error code:** `queue_leave_failed` (400).
