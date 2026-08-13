# Notifications & Push Devices API

`Authorization: Bearer <token>` required on every endpoint below.

## GET /api/v1/notifications.php

General, cross-role notification feed (rider, driver, or admin — whoever the token belongs to). Riders can alternatively use [`GET /api/v1/rider/notifications.php`](rider-api.md#get-apiv1ridernotificationsphp), which returns the identical shape restricted to rider accounts — both run the same priority-ordered query, there is no second notification system.

Paginated (`?page=&limit=`). Ordering is always critical → high → normal → low, newest first within each tier — this is a deliberate rule from the Notification Priority Management System: critical notifications are never suppressed or buried, regardless of volume.

**Success `data`:**
```json
{
  "notifications": [
    {
      "public_id": "NOTIF-XXXX", "title": "…", "message": "…", "notification_type": "wallet_credit",
      "priority": "normal", "icon": "…", "color": "…", "link": "…", "action": null, "action_id": null,
      "action_url": null, "related_table": null, "related_public_id": null,
      "is_read": false, "read_at": null, "created_at": "2026-07-27 04:12:00"
    }
  ],
  "unread_count": 3
}
```

## POST /api/v1/device/register.php

Registers (or re-associates) a push-notification device token against the calling user. Upsert — re-registering the same `device_token` reassigns it to whoever is currently logged in on that device and refreshes `last_active`. This lets the same physical device be handed between accounts (e.g. logout/login as a different user) without leaving a stale registration pointing at the wrong user.

**Body:** `{ "device_token": "fcm-or-apns-token-string", "platform": "android" }` — `platform` must be `"ios"` or `"android"`.

**Success:** `"Device registered for push notifications."`

**Error code:** `validation_error` (400) — missing token or invalid platform.

## POST /api/v1/device/remove.php

Removes a device token belonging to the calling user only (never another user's registration, even if you somehow knew their token). Call this on logout or when push permission is revoked, so a signed-out device stops receiving pushes meant for that account.

**Body:** `{ "device_token": "fcm-or-apns-token-string" }`

**Success:** `"Device removed."` — always succeeds even if the token wasn't registered (idempotent delete).
