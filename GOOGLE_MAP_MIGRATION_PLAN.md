# Google Maps Platform Migration Plan

**Project:** MohToh / merit_drives
**Date:** 2026-08-02
**Status:** Awaiting approval — no code changed yet.

---

## 0. Important pre-flight notes

- **`MOHTOH_SYSTEM_ANALYSIS.md` does not exist** in this repository. The
  task brief said to read it first; a full-tree search found no such
  file. This plan was therefore built directly from the source code
  instead. If that document lives somewhere else, point me at it and I
  will reconcile this plan against it before implementing.

- **This migration is already ~50% complete.** A previous session moved
  the *Places / autocomplete / geocoding* half of the app onto Google.
  What remains is the *map rendering*, *routing*, *reverse-geocoding*,
  and *server-side trip distance* — plus wiring the key through the
  Integration Center so it is no longer a hardcoded constant. The plan
  below is scoped to exactly that remaining work, so we do not rewrite
  things that already work.

- **Two scope decisions confirmed with the product owner:**
  1. Google Directions / Distance Matrix is used for **real trip routes
     only** (ride detail, trip estimate, create-ride). The cheap
     haversine helper (`calculate_distance_km`) is **kept** for bulk
     proximity ranking (nearby-driver sort, "distance from you" chips,
     region detection) — no per-request billing or latency added to
     search.
  2. **Nominatim/OpenStreetMap is kept only as the keyless fallback** for
     location search when the Google key is missing or quota is
     exceeded. This *is* the graceful-degradation behaviour that
     requirement #8 asks for. All Leaflet map **tiles/markers/CSS** are
     still fully removed (requirement #9).

---

## 1. Current implementation (what exists today)

### 1a. Provider-agnostic map layer — `assets/js/map.js` (Leaflet)
The shared map is deliberately isolated behind a small contract so the
provider can be swapped in one file. Every caller only ever touches:

| Function | Purpose |
|---|---|
| `mtMap` (global) | the map instance |
| `initMohtohMap()` | mounts the map into `#mapCanvas` on `DOMContentLoaded` |
| `centerMapOnUser(cb)` | geolocates via the MohToh bridge, drops the "you are here" marker |
| `placeUserMarker({lat,lng})` | user location dot |
| `drawRoute(origin, dest, opts)` | draws a route line + pickup/destination markers, fits bounds |
| `clearRoute()` | clears the above |
| event `mohtoh:map-ready` | fired once the map is usable |

Currently backed by `L.map` + OpenStreetMap tiles. `drawRoute()` draws a
**straight polyline** — there is no real turn-by-turn routing today.

**Mounted on:** `driver/dashboard.php` and `rider/dashboard.php` (both
have `<div id="mapCanvas">` and load `map.js`).

### 1b. Places / search layer — `assets/js/helper.js` (ALREADY Google)
`initAddressAutocomplete()` + `mtLoadGoogleMaps()` already:
- Load the Google Maps JS API (+`places`) dynamically via the
  session-guarded `api/maps-key.php` — **key never hardcoded in JS**.
- Use Places `AutocompleteService`, `Geocoder` (for pasted addresses),
  and `PlacesService.getDetails` (to resolve coordinates on selection).
- Restrict to Nigeria (`country: 'ng'`) and bias to the driver's GPS or
  Redemption Camp (`6.9083, 3.3870`).
- Return `{name, address, lat, lng, place_id, source}` and save the pick
  into recent searches + the app's location fields.
- **Fall back to `api/location-search.php` (Nominatim) automatically**
  if Google is unavailable.
- Provide `mtShowLocationShare()` (share via `maps.google.com`) and
  `mtCenterMapOnSelectedLocation()` (currently calls Leaflet `setView`).

### 1c. Server-side location search — `api/location-search.php`
Hybrid ranked search: MohToh curated `locations` table + approved
driver-submitted spots + **Nominatim** (cached in
`location_search_cache`). Normalizes everything to one result shape.
This is the fallback the frontend hits when Google is down.

### 1d. Mini-maps and admin map (Leaflet, inline)
- `assets/js/driver-trips.js` `renderMiniMap()` — Leaflet trip detail map.
- `assets/js/rider-trips.js` — same, rider side.
- `admin/dashboard.php` `initMap()`/`renderMap()` — Leaflet live-users map,
  loads Leaflet CSS/JS from unpkg directly.

### 1e. Reverse-geocoding (Nominatim, direct fetch)
- `assets/js/driver.js` `showReverseGeocodedLocation()`
- `assets/js/search.js` "Use my location" button
- `assets/js/ride-requests.js`
All call `nominatim.openstreetmap.org/reverse` directly.

### 1f. GPS + tracking (provider-agnostic — no change needed)
`assets/js/mohtoh-bridge.js` `MohToh.location.getCurrentLocation/
watchLocation` is the ONLY place `navigator.geolocation` is used.
`api/update-location.php` persists pings and drives the trip engine.
**This layer is already provider-independent and stays as-is.**

### 1g. Server-side distance (haversine)
`includes/functions.php` `calculate_distance_km()` /
`calculate_distance_meters()` — used in ~15 places for both real trip
distance and cheap proximity ranking. `includes/FareCalculator.php` and
the pricing engine consume a `distanceKm` number; they do **not** care
where it comes from.

### 1h. Integration Center (exists, ready to extend)
- `includes/integration-settings.php` — `get_integration_setting()` /
  `set_integration_setting()` with per-request cache and encrypted
  secrets. Its own header comment already anticipates a `map-config.php`.
- `includes/secrets.php` — AES-256-GCM encrypt/decrypt/mask.
- `api/integration-center.php` — admin read/write, whitelisted keys,
  super-admin gated, secrets masked on read.
- `includes/config.php` — `GOOGLE_MAPS_API_KEY` constant (hardcoded,
  "parked"); `api/maps-key.php` serves it to logged-in sessions.
- `includes/diagnostics.php` — Maps channel currently reports "Leaflet".

---

## 2. Google APIs required

| API | Used by | Where the key lives |
|---|---|---|
| Maps JavaScript API | map render, mini-maps, admin map | browser, via `maps-key.php` (referrer-restricted key) |
| Places API (Autocomplete + Details) | search — **already live** | browser |
| Geocoding API | pasted addresses + reverse-geocode | browser |
| Directions API | `drawRoute()` real routes, trip ETA | browser (route draw) + server (trip calc) |
| Distance Matrix API | real trip distance + ETA for pricing input | server (`map-config.php`) |

Two logical keys, both stored in the Integration Center:
- **Browser key** — HTTP-referrer restricted (localhost + production
  domain), served by `maps-key.php`. Safe to expose by design.
- **Server key** — IP/none-restricted, used only by PHP for Distance
  Matrix / Directions; **never** sent to the browser. (May be the same
  key if the owner prefers; the plan supports one or two.)

---

## 3. Files that will change

### New files
| File | Purpose |
|---|---|
| `includes/map-config.php` | reads Google map settings from the Integration Center (key, per-API enable toggles); server-side Directions/Distance-Matrix helper for trip distance + ETA, with friendly error mapping |

### Modified — backend
| File | Change |
|---|---|
| `api/maps-key.php` | read key from `get_integration_setting()` (fallback to the config constant), and return the enabled-API flags so the browser knows what's on |
| `api/integration-center.php` | add `MAPS_SETTING_KEYS` (key + `places_enabled` / `directions_enabled` / `distance_matrix_enabled`), `save_maps` + `test_maps` actions |
| `includes/config.php` | keep `GOOGLE_MAPS_API_KEY` as fallback only; refresh the stale "Leaflet is active" comment |
| `includes/diagnostics.php` | Maps channel reports Google status (key present? which APIs enabled? last test) instead of "Leaflet" |
| `admin/dashboard.php` | add a **Google Maps** card to the Integration Center tab; migrate the admin live-map (`initMap`/`renderMap`) from Leaflet to Google; drop Leaflet CSS/JS `<script>`/`<link>` |
| ride detail / trip estimate endpoint(s) that show trip distance | switch the *displayed trip* distance/ETA to the Directions/Distance-Matrix helper where a real route is wanted (pricing math itself untouched) |

### Modified — frontend
| File | Change |
|---|---|
| `assets/js/map.js` | **rewrite on Google Maps JS API**, keeping the exact `mtMap`/`centerMapOnUser`/`placeUserMarker`/`drawRoute`/`clearRoute` + `mohtoh:map-ready` contract. Add zoom, fullscreen, map-type controls; user/driver/passenger custom markers; real `DirectionsService` routes returning distance + ETA |
| `assets/js/helper.js` | `mtCenterMapOnSelectedLocation()` → use Google map API instead of Leaflet `setView`; share the single loader with `map.js`; comment cleanup |
| `assets/js/driver-trips.js` | `renderMiniMap()` → Google mini-map (pickup/destination/driver markers + route) |
| `assets/js/rider-trips.js` | same, rider side |
| `assets/js/driver.js` | `showReverseGeocodedLocation()` → Google reverse-geocode via the shared loader (Nominatim as fallback) |
| `assets/js/search.js` | "Use my location" reverse-geocode → Google (Nominatim fallback) |
| `assets/js/ride-requests.js` | reverse-geocode → Google (Nominatim fallback) |
| `assets/js/messages.js` | shared-location "View on map" link → `maps.google.com` |
| `driver/dashboard.php`, `rider/dashboard.php`, `driver/trips.php`, `rider/trips.php` | remove Leaflet `<link>`/`<script>` tags once Google is confirmed working |

### Explicitly NOT touched
- `assets/js/mohtoh-bridge.js` (GPS) — already provider-agnostic.
- `api/update-location.php`, trip engine, `includes/functions.php`
  haversine — proximity ranking keeps using haversine by decision.
- `includes/FareCalculator.php` and all pricing/commission logic —
  **business logic unchanged**; only the `distanceKm` *source* improves
  for real trips.
- Payment, wallet, auth systems.
- `api/saved-locations.php` + `saved_locations` table — already exist
  with `label/address/latitude/longitude`; **no new table**, verified in
  step 6 only.
- Root `js/map.js`, `js/app.js` — confirmed unused legacy (`LEGACY_CODE.md`).

---

## 4. Migration steps (order of work)

1. **Integration Center provider** (backend, invisible to users):
   `includes/map-config.php`; add maps keys to
   `api/integration-center.php`; make `api/maps-key.php` read from
   settings with the config constant as fallback. → App behaves exactly
   as now until an admin saves a key.
2. **Admin UI:** Google Maps card in the Integration Center tab
   (API key inputs — masked secret — + three enable toggles + "Test
   connection"). Update diagnostics Maps channel.
3. **Rewrite `assets/js/map.js`** on Google, preserving the contract +
   adding controls, custom markers, and real Directions routing. Verify
   driver & rider dashboards still mount and center.
4. **Point `helper.js`** center/share helpers at the Google map; unify
   the loader.
5. **Mini-maps + admin map + reverse-geocode** → Google (Nominatim
   fallback retained for reverse-geocode text).
6. **Server-side trip distance/ETA** via `map-config.php` Distance
   Matrix for the real-trip display path; confirm `FareCalculator`
   output is byte-for-byte unchanged for the same `distanceKm`.
7. **Error handling:** friendly messages for invalid key / billing
   disabled / API not enabled / referrer blocked / quota exceeded — both
   the JS loader and the server helper.
8. **Remove Leaflet** CSS/JS tags and dead Leaflet code paths **only
   after** Google is confirmed working on every screen. Re-grep to prove
   nothing else references them.
9. **Testing checklist** (section 6) end-to-end, localhost + production
   domain.

Each step is independently shippable and leaves the app working.

---

## 5. Potential risks & mitigations

| Risk | Mitigation |
|---|---|
| **Billing / quota** — Directions & Distance Matrix are billable; a lapsed card silently breaks maps | Per-API enable toggles + server key guarded; Nominatim fallback for search; haversine still powers proximity so ranking never breaks; friendly error surfaced, not a blank map |
| **Referrer restriction** blocks localhost or prod | Document both origins to whitelist in Cloud Console; `ALLOWED_HOSTS`/base-URL layer already knows both; test on each |
| **Contract drift** — a caller reaches into Leaflet internals | `map.js` keeps the identical public contract; grep confirms callers only use the 5 documented functions (verified) |
| **Pricing regression** | Pricing logic untouched; add a before/after check that identical `distanceKm` yields identical fare; real-route distance only changes the *input number* on the trip display path, deliberately |
| **Key leakage** | Browser key referrer-restricted + served only to logged-in sessions; server key never sent to browser; both stored AES-256-GCM encrypted, masked in admin UI |
| **Mobile / MAUI WebView** | GPS stays behind the unchanged `MohToh.location` bridge; Google JS API works in WebView; no bridge contract change |
| **Removing Leaflet too early** | Removal is the last step, gated on Google confirmed working, with a fresh reference grep per `LEGACY_CODE.md` rules |

---

## 6. Testing checklist (from the brief)

- [ ] Search "Prayer Foyer Redemption Camp" (Places autocomplete)
- [ ] Search an address manually
- [ ] Copy-paste an address into search (Geocoder path)
- [ ] Select a place from autocomplete → fields + map update
- [ ] Open location on Google Maps (share chip)
- [ ] Share location / address
- [ ] Calculate route (Directions, real polyline + turn-by-turn)
- [ ] Calculate distance + ETA (Distance Matrix) → feeds pricing input
- [ ] Driver tracking (live marker via bridge watch)
- [ ] Rider tracking
- [ ] Mobile browser
- [ ] Production domain (referrer-restricted key resolves)
- [ ] Fare output unchanged for a fixed distance (pricing regression)
- [ ] Google key removed → Nominatim fallback still returns search results
