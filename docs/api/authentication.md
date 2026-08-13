# Authentication

MohToh's mobile API auth is a bearer token, completely independent of the web app's session cookies. Logging in on mobile does not create or touch a web session, and vice versa — the two coexist without interfering.

Tokens are opaque random strings. Only a SHA-256 hash of the token is ever stored (in `user_tokens.token_hash`) — same one-time-reveal contract as this app's email-verification and password-reset tokens. There is no way to look up or recover a lost token; the client must store it (e.g. `flutter_secure_storage`) and re-login if it's lost.

Tokens expire **30 days** after issue (`API_TOKEN_TTL_DAYS` in `includes/api_tokens.php`). Use `auth/refresh.php` before that to avoid forcing a full re-login.

## POST /api/v1/auth/login.php

No auth required (this is how you get a token).

**Body:**
```json
{
  "email": "rider@example.com",
  "password": "the-users-password",
  "device_type": "android",
  "device_name": "Pixel 8"
}
```
`device_type`/`device_name` are optional, free-form strings stored purely for the user's own reference (e.g. a "manage devices" screen later) — they are not validated against a fixed enum.

**Success `data`:**
```json
{
  "token": "64-character-hex-string",
  "token_type": "Bearer",
  "expires_in_days": 30,
  "needs_verification": false,
  "user": {
    "id": 114,
    "public_id": "USR-XXXX",
    "fullname": "Jane Rider",
    "email": "rider@example.com",
    "phone": "+2348012345678",
    "user_type": "rider",
    "email_verified": true,
    "phone_verified": true,
    "profile_image": "profile_114_abc.png"
  }
}
```

Enforces exactly the same account-state rules as the web login: suspension, failed-login lockout (`is_account_locked()`/`record_failed_login()`/`record_successful_login()`), and — if the admin Integration Center has `integration_require_email_to_login`/`integration_require_phone_to_login` turned on — `needs_verification: true` is returned instead of blocking login outright, matching the web behavior.

Errors: `validation_error` (400), `invalid_credentials` (401), `account_suspended` (403), `account_locked` (423).

## POST /api/v1/auth/logout.php

**Auth:** required. Revokes only the token presented in this call (not every device the user is logged in on).

**Body:** none required.

**Success:** `{"status":"success","message":"Logged out successfully.","data":[]}`

## POST /api/v1/auth/refresh.php

Rotates a still-valid token for a brand new one; the old token is revoked in the same call (full rotation, not a copy). Call this proactively (e.g. on app foreground, or when close to the 30-day expiry) rather than waiting for a 401.

**Auth:** required (the token being refreshed, via the `Authorization` header — no body).

**Success `data`:**
```json
{ "token": "new-64-char-hex-string", "token_type": "Bearer", "expires_in_days": 30 }
```

**Error:** `unauthorized` (401) if the presented token is already invalid/expired — at that point the app must send the user back to `auth/login.php`.

## GET /api/v1/auth/validate.php

Cheap check of whether a stored token is still valid — e.g. on app cold start, before deciding whether to show the login screen. Does **not** rotate/extend the token.

**Auth:** required.

**Success `data`:**
```json
{
  "user": {
    "id": 114,
    "public_id": "USR-XXXX",
    "fullname": "Jane Rider",
    "user_type": "rider",
    "email_verified": true,
    "phone_verified": true
  }
}
```

## Sending the token

Every other endpoint in this API requires:
```
Authorization: Bearer <token>
```
Missing or invalid tokens return `{"status":"error","message":"Invalid or expired session. Please log in again.","code":"unauthorized"}` with HTTP 401 — this is the app's signal to route back to the login screen.

## Roles

Some endpoints are restricted to `rider` or `driver`. A logged-in user of the wrong role gets HTTP 403:
```json
{"status":"error","message":"You are not authorized to perform this action.","code":"forbidden"}
```
