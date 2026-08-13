# MohToh — MAUI Integration Guide

## Purpose

This document exists so a future .NET MAUI app can wrap the existing
MohToh web app in a WebView **without rebuilding any business logic**.
The PHP API (`api/*.php`) remains the single source of truth for fare
calculation, wallet logic, payment logic, trip-state logic, permission
logic, and notification rules. The web app is a platform layer: HTML/CSS/JS
that talks to that API and, for device capabilities, talks through one
JavaScript bridge — `assets/js/mohtoh-bridge.js` (`window.MohToh`).

**The contract that matters**: every page calls `window.MohToh.<capability>.<method>()`.
Today, every method inside `mohtoh-bridge.js` is implemented with browser
APIs (`navigator.geolocation`, `Notification`, `<input type=file>`, etc.).
When MAUI hosts these same pages in a `WebView`/`BlazorWebView`/`WebView2`
control, only the **inside** of those methods needs to change to call
native MAUI APIs via JS interop — the method names, arguments, and return
shapes never change, so no page-level JavaScript is touched.

```
Web (today)                              MAUI (future)
─────────────────────────────────────────────────────────────────────
MohToh.location.getCurrentLocation()  →  same JS call, but the method
  → navigator.geolocation                 body calls window.chrome
    .getCurrentPosition()                 .webview.postMessage(...) to
                                           a native GPS handler, which
                                           resolves the same Promise
                                           with the same {latitude,
                                           longitude, accuracy, speed,
                                           heading, timestamp} shape.
```

## How the swap point works

`mohtoh-bridge.js` reads `window.MOHTOH_RUNTIME` once, at the top of the
file:

```js
const device = {
  runtime: (window.MOHTOH_RUNTIME) || 'web',
  isMaui() { return device.runtime === 'maui'; },
  ...
};
```

A future MAUI host page sets `window.MOHTOH_RUNTIME = 'maui'` before the
page's other scripts run (e.g. in the WebView's initialization script).
Each adapter can then branch on `device.isMaui()` to call a native
JS-interop bridge instead of the browser API, while every other file in
the app — every page, every feature module — keeps calling
`window.MohToh.location.getCurrentLocation()` exactly as it does today.
`assets/js/pwa-init.js` already uses this flag to skip service-worker
registration entirely inside a MAUI shell, since MAUI has its own native
shell instead of a browser service worker — the same pattern every other
adapter should follow.

## The bridge contract (`window.MohToh`)

| Namespace | Methods | Web implementation today |
|---|---|---|
| `device` | `runtime`, `isMaui()`, `isStandalone()`, `userAgent()`, `viewport()`, `isTouch()` | `window.MOHTOH_RUNTIME`, `matchMedia('(display-mode: standalone)')`, `navigator.userAgent` |
| `network` | `isOnline()`, `onChange(fn)`, `getConnectionInfo()` | `navigator.onLine`, `online`/`offline` window events |
| `location` | `getCurrentLocation(options)`, `watchLocation(onUpdate, onError, options)`, `stopWatching(watchId)` | `navigator.geolocation.getCurrentPosition/watchPosition/clearWatch` |
| `camera` | `capture(options)`, `upload(file, endpoint, extraFields)`, `permission()` | Hidden `<input type="file" capture>` + `apiUpload()` |
| `files` | `select(options)`, `upload(file, endpoint, extraFields)`, `preview(file)` | Hidden `<input type="file">` + `FileReader`/object URL |
| `share` | `shareText(text, title)`, `shareLink(url, title, text)` | `navigator.share()` → clipboard → `execCommand('copy')` fallback chain |
| `storage` | `save(key, value)`, `get(key, defaultValue)`, `remove(key)` | `localStorage`, key-name guarded (see Security) |
| `biometric` | `isAvailable()`, `authenticate()` | Stubbed (`false`/`{success:false}`) — no browser biometric API in scope |
| `notification` | `initialize()`, `requestPermission()`, `show(title, options)`, `playSound(type)`, `vibrate(pattern)`, `setBadge(count)` | `Notification` API, `navigator.vibrate`, `navigator.setAppBadge` |

Return shapes (so a MAUI adapter reproduces them exactly):

- `location.getCurrentLocation()` / each `watchLocation()` update resolves/calls back with
  `{ latitude, longitude, accuracy, speed, heading, timestamp }`; rejects/errors with `{ code, message }`.
- `camera.upload()` / `files.upload()` resolve with whatever `api-client.js`'s `apiUpload()` returns —
  the standard `{ status, message, data }` envelope, unchanged.
- `share.shareText()` / `share.shareLink()` resolve `true`/`false` (whether a share/copy actually completed).
- `storage.get()` returns the stored value (JSON-parsed) or the caller's `defaultValue`.

## API requirements

The API is untouched by this upgrade and stays the source of truth. Every
call from the frontend — web or, later, MAUI — goes through
`assets/js/api-client.js`'s three functions:

- `apiGet(url, params, options)`
- `apiPost(url, payload, options)`
- `apiUpload(url, formData, options)`

All three return the same envelope, `{ status: 'success'|'error', message, data }`,
even when the network fails, times out, or the server returns non-JSON —
so a MAUI WebView calling the exact same JavaScript never needs a
different error-handling path.

## Authentication flow

MohToh uses PHP session cookies, not bearer tokens. `api-client.js`
sends `credentials: 'same-origin'` on every request. This app's
`is_logged_in()` gate never returns a real HTTP 401 — an expired or
missing session responds `200 OK` with `{status:'error', message:'Unauthorized'}`.
`api-client.js` detects this by message content (`_mtIsAuthFailure()`)
and calls `window.mtOnAuthFailure()`, which by default redirects to
`index.php`.

A MAUI WebView hosting these same pages inherits this automatically as
long as its cookie jar persists across navigations (standard for
`WebView2`/`WKWebView`). No separate MAUI-side auth flow is needed for
V1 — the WebView **is** the browser session.

## Notification flow

`MohToh.notification` is initialized once per page (`initialize()` sets
up the permission state), then every feature calls `show()`, `playSound()`,
`vibrate()`, or `setBadge()` — never `new Notification(...)` or
`navigator.vibrate()` directly. `assets/js/sound-manager.js` backs
`playSound()`/`MohToh.sound.play()` with cached, non-overlapping
`<audio>` elements loaded from `assets/sounds/*.wav`.

MAUI swap point: `notification.show()`'s body calls a native local/push
notification API instead of the `Notification` constructor; `playSound()`
can either keep using `sound-manager.js` (an `<audio>` element still
works inside a WebView) or delegate to a native sound API. The calling
convention — `MohToh.notification.show(title, options)` — does not change.

## GPS flow

Every page-level call site (`search.js`, `rider-trip.js`, `map.js`,
`driver-ride-requests.js`, `ride-requests.js`, `driver.js`, `messages.js`,
`helper.js`) calls `MohToh.location.getCurrentLocation()` /
`watchLocation()` / `stopWatching()` — none of them touch
`navigator.geolocation` directly. `mohtoh-bridge.js` is the only file
in the app allowed to do so. Swapping in native GPS for MAUI means
editing three functions in one file; zero page-level changes.

## Camera flow

Vehicle photos, profile photos, and verification documents all go
through `MohToh.camera.capture()` (hidden file input routed to the
camera on mobile browsers via the `capture="environment"` attribute)
and `MohToh.camera.upload()` (delegates to `apiUpload()` — no duplicate
FormData/fetch logic). MAUI swap: `capture()`'s body calls the native
camera picker via JS interop and returns a `File`-like object (or a
data URI converted to one) so `upload()` keeps working unmodified.

## What ships as pure device/browser plumbing vs. business logic

`mohtoh-bridge.js` contains **no** business logic — no fare math, no
wallet math, no trip-state rules, no permission decisions. It only:

1. Talks to a device capability (GPS, camera, share sheet, notifications,
   local storage), or
2. Delegates to `api-client.js` for anything that touches the backend
   (`camera.upload()`, `files.upload()`).

This is deliberate: it's the boundary a MAUI rebuild can replace without
ever needing to know how MohToh prices a ride, reserves a wallet
commission, or decides what `trip_state` transition is legal next. Those
decisions stay in `includes/functions.php`, `includes/FareCalculator.php`,
`includes/booking.php`, and `api/*.php` — completely untouched by this
upgrade.

## Security constraints (Phase 15) carried into any MAUI build

- **No secrets in JavaScript.** No API keys, no Paystack secret keys —
  those live server-side only (`includes/config.php`). The frontend
  never holds anything more sensitive than the session cookie itself.
- **No sensitive local storage.** `MohToh.storage.save()` structurally
  refuses to persist any key matching `/password|token|secret|wallet|card|cvv|pin|auth/i` —
  it logs an error and returns `false` rather than silently storing it.
  This is enforced in code, not just by convention, so it holds even if
  a future contributor forgets the rule.
- **Never cached offline.** `service-worker.js`'s `NEVER_CACHE_PATTERNS`
  excludes `/api/`, wallet, payment/Paystack, profile, and admin URLs
  from both the cache-first static-asset path and the navigation
  fallback — those always hit the network live.

A MAUI build inherits all of this for free as long as it keeps loading
the same pages and the same `mohtoh-bridge.js` — there is no separate
"MAUI security model" to design.
