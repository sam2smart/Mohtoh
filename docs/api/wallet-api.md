# Wallet & Payment API

All under `/api/v1/wallet/`. `Authorization: Bearer <token>` required. `balance.php` and `history.php` work for either role (drivers additionally see reservation figures); `payment.php` is driver-only.

**The wallet balance column is never written to directly anywhere in this API.** Every credit/debit goes through `credit_wallet()`/`debit_wallet()` (`includes/functions.php`), exactly like the web app.

## GET /api/v1/wallet/balance.php

**Success `data`:**
```json
{
  "balance": 5000.0,
  "available_balance": 4200.0,
  "reserved_balance": 800.0,
  "payment_preference": "wallet"
}
```
For riders, `available_balance` equals `balance` and `reserved_balance` is always `0`. For drivers, `available_balance`/`reserved_balance` come from `get_available_wallet_balance()`/`get_reserved_wallet_balance()` (balance minus/showing active commission reservations).

## GET /api/v1/wallet/history.php

Paginated (`?page=&limit=`).

**Success `data`:** `balance`, `available_balance`, `reserved_balance`, `active_reservations` (drivers only — same shape as [driver-api.md](driver-api.md#get-apiv1driverwalletphp)'s reservations array; empty array for riders), `payment_preference`, `history` (array of `wallet_transactions` rows — `id`, `reference`, `amount`, `type`, `category`, `description`, `ride_share_id`, `booking_id`, `balance_after`, `status`, `created_at`).

## POST /api/v1/wallet/topup.php

Starts a Paystack top-up. Creates a `wallet_topups` row (`PAYMENT_PENDING`) and calls the real `paystack_initialize_transaction()` — the same function the web dashboard's top-up button uses. **The wallet is never credited by this endpoint.** Crediting happens later, only once Paystack itself confirms the charge via `api/paystack-callback.php`/`api/paystack-webhook.php` calling `finalize_wallet_topup()` — identical to the web flow.

**Body:** `{ "amount": 5000 }` — minimum ₦100, maximum ₦1,000,000 per transaction.

**Success `data`:**
```json
{ "authorization_url": "https://checkout.paystack.com/xxxxxxxxxx", "reference": "TOPUP-XXXXXXXX" }
```
Open `authorization_url` in an in-app browser/webview for the user to complete payment, then poll `GET wallet/history.php` (or a topup-status check) for the `reference` to confirm it landed — same pattern the web dashboard already uses.

**Error codes:** `validation_error` (400, amount out of range), `topup_init_failed` (400, DB error before Paystack was even contacted), `paystack_init_failed` (400, Paystack rejected the request), `paystack_unreachable` (400, network/API failure — the pending topup is marked `PAYMENT_FAILED`).

## POST /api/v1/wallet/payment.php

Driver confirms a **direct** (cash/off-wallet) passenger payment arrived for a completed booking. The only wallet movement is `debit_wallet()` for MohToh's commission on that fare — never the fare itself.

**Body:** `{ "booking_id": 501 }`

**Preconditions:** a `PAYMENT_PENDING` direct-payment `payments` row must exist for this booking, owned by the calling driver, and the booking must be `completed`.

**Success:** `"Direct payment confirmed and commission settled."`

**Error code:** `payment_confirmation_failed` (400) — e.g. no matching pending payment, trip not completed yet, or wallet balance can't cover the commission (in which case the driver must top up before confirming).
