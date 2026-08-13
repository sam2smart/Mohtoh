# Error Codes

Every error response has the shape:
```json
{ "status": "error", "message": "Human-readable, safe-to-display text.", "code": "one_of_the_slugs_below" }
```

`message` is always safe to show directly to the end user (never a raw PHP/SQL error — the API's global error/exception/shutdown handlers guarantee this: any unexpected PHP error, uncaught exception, or fatal error is converted to `internal_error` before it can leak). `code` is for the app's own branching logic (e.g. "show login screen" vs "show a toast").

| Code | Typical HTTP status | Meaning |
|---|---|---|
| `validation_error` | 400 | A required field was missing or malformed. `message` says which. |
| `unauthorized` | 401 | Bearer token missing, invalid, expired, or revoked. Send the user to login. |
| `invalid_credentials` | 401 | Login email/password didn't match. |
| `forbidden` | 403 | Authenticated, but this account's role/ownership doesn't permit the action. |
| `account_suspended` | 403 | Account is suspended; contact support. |
| `account_locked` | 423 | Too many failed login attempts; temporarily locked. `message` includes the wait time. |
| `contact_not_verified` | 403 | Email/phone verification required before this action (e.g. creating a ride). |
| `not_found` | 404 | The requested resource (ride, etc.) doesn't exist. |
| `method_not_allowed` | 405 | Wrong HTTP verb for this endpoint (e.g. GET on a POST-only endpoint). |
| `internal_error` | 500 | An unexpected server-side error was caught and safely converted — never raw PHP/SQL output. If this recurs, it's a bug worth reporting with the endpoint and payload (minus secrets). |
| `booking_failed` | 400 | Seat booking rejected — `message` gives the specific reason (full ride, insufficient balance, etc). |
| `cancel_failed` | 400 | Booking cancellation rejected (not found, not owned, already terminal). |
| `rating_failed` | 400 | Rating rejected (not completed, not owned, already rated). |
| `queue_join_failed` / `queue_leave_failed` | 400 | Ride Queue join/leave rejected — see `message`. |
| `vehicle_not_verified` | 400 | Driver has no vehicle on file, or it isn't `approved` yet. |
| `driver_not_verified` | 400 | `users.is_verified` is false for this driver. |
| `max_active_rides` | 400 | Driver is at their active-ride cap (non-premium tier). |
| `pricing_error` | 400 | A driver-supplied `price_per_passenger` failed validation. |
| `insufficient_wallet_reserve` / `insufficient_wallet_balance` | 400 | Driver's wallet can't cover the commission reserve required to publish/accept rides. |
| `create_ride_failed` | 400 | Ride publish failed after passing all checks (rare — DB-level failure). |
| `start_trip_failed` / `end_trip_failed` | 400 | Trip start/end rejected — ride not found for this driver or in the wrong state. |
| `no_vehicle` | 400 | File upload (`vehicle_photo`/`document`) attempted with no vehicle on file. |
| `invalid_file_type` | 400 | Uploaded file's extension isn't allowed for the given `type`. |
| `upload_error` / `upload_failed` | 400 | No file present / PHP upload error, or the file couldn't be saved to disk. |
| `topup_init_failed` / `paystack_init_failed` / `paystack_unreachable` | 400 | Wallet top-up couldn't be started — see `message` for which stage failed. |
| `payment_confirmation_failed` | 400 | Direct-payment confirmation rejected (no pending payment, trip not completed, or insufficient commission balance). |

If you hit a `code` not listed here, treat it the same as `internal_error` for UI purposes (generic failure toast) and report it — it likely means this document is behind the code; grep `api/v1/**/*.php` for the exact string to find its source.
