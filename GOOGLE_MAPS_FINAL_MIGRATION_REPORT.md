# Google Maps — Single Source of Truth Restoration Report

**Project:** MohToh / merit_drives
**Date:** 2026-08-03
**Scope:** Route the entire Google Maps implementation through the ONE
authoritative store — the `integrations` table's `google_maps` provider row —
and remove every secondary/duplicate key source.

---

## 0. Source of truth (authoritative)

**The Google Maps API key lives in exactly one place:** the `integrations`
table (the Service Integration Center's provider registry), on the row
`provider = 'google_maps'`, inside its `configuration` TEXT column (a JSON
document), field `api_key`. It is stored **plaintext**, exactly like the
table's other provider rows (Paystack / Twilio / SMTP) — the project applies no
encryption layer to these, so none is applied here.

```
integrations
  provider      = 'google_maps'
  status        = 'enabled'
  is_default    = 1
  configuration = {
    "api_key":           "AIzaSyDeQRO-o-2nZZIRjlaW1wwT0qXS2Hsn_yU",
    "default_lat":       "6.5244",
    "default_lng":       "3.3792",
    "default_zoom":      "13",
    "enable_places":     "1",
    "enable_directions": "1",
    "enable_geocoding":  "1"
  }
```

**Lookup flow (the only flow):**

```
integrations → provider = google_maps → decode configuration JSON
    → configuration['api_key'] → includes/google-maps.php → Google Maps service
```

There is **no** config.php constant, **no** integration_settings row, **no**
environment variable, **no** browser constant, **no** duplicated row, and **no**
fallback key. One authoritative source only.

---

## 1. Root cause of the original breakage

The `integrations` table (28 seeded provider rows) is the real Integration
Center store, but **no PHP code read or wrote it** — it was orphaned. The maps
service layer instead read the key from `integration_settings` + a `config.php`
constant, so the admin-managed key in `integrations` never reached Google, and a
stale constant key (`AIzaSyD_V_454sI883eq60Blcz9u0lOy1c0KYD4`) could shadow it.
A prior repair attempt compounded this by seeding a **duplicate** `google_maps`
row into `integration_settings` (extra `provider`/`configuration` columns +
a `google_maps_config` row) — a second store to keep in sync, i.e. the exact
"duplicate storage" the task forbids.

**Fix:** repoint the entire service + admin + API layer at the `integrations`
table, and delete every other maps key source.

---

## 2. Files changed

### Backend — service layer
| File | Change |
|---|---|
| `includes/google-maps.php` | The single Google Maps service. `getGoogleMapsConfig()` reads/decodes the `integrations` google_maps `configuration` JSON (cached per-request). `setGoogleMapsConfig()` merge-writes the same JSON (partial saves preserve lat/lng/zoom + other fields). `getGoogleMapsKey()` / `getGoogleMapsServerKey()` return `configuration.api_key` (plaintext) — no constant, no integration_settings, no fallback. `googleMapsEnabledFlags()` reads the `enable_*` string toggles. All Google HTTP (geocode/reverse/distance/directions) unchanged and route through these. |

### Backend — API + admin
| File | Change |
|---|---|
| `api/integration-center.php` | `maps` GET block + `save_maps` action read/write the `integrations` google_maps `configuration` JSON via `getGoogleMapsConfig()` / `setGoogleMapsConfig()`. `MAPS_CONFIG_FIELDS` maps each UI field → JSON field. api_key masked on read (`mask_secret()`), blank-on-save keeps the stored key; toggles saved as `"1"/"0"`; `default_lat/lng/zoom` preserved. `test_maps` does a live reverse-geocode. Still uses `get/set_integration_setting()` for Email/SMS/OTP/policy/verification — those legitimately live in `integration_settings` and were not touched. |
| `api/maps-key.php` | Returns the browser key via `getGoogleMapsKey($pdo)` + `googleMapsEnabledFlags($pdo)` to logged-in sessions only, `Cache-Control: no-store`. Header comment corrected to name the `integrations` table (was: integration_settings / AES-256-GCM). |
| `admin/dashboard.php` | Google Maps card: one **API Key** field (`mi-api_key`, masked status) + three toggles (`mi-enable_places`, `mi-enable_directions`, `mi-enable_geocoding`) + Test connection. JS (`MAPS_SECRET_KEYS` / `MAPS_TOGGLE_KEYS`) loads from the `maps` GET block and posts `save_maps`. Blank key = keep saved. |

### Removed (duplicate / secondary sources eliminated)
| Item | Action |
|---|---|
| `integration_settings` `google_maps_config` row | **Deleted** — the duplicate maps store. |
| `integration_settings` `provider` + `configuration` columns | **Dropped** — added only for the wrong maps approach; no other row used them (verified 0 rows before drop). |
| `get_provider_configuration()` / `set_provider_configuration()` in `includes/integration-settings.php` | **Removed** — added only for the wrong maps approach; no live callers. |
| `database/migrate_google_maps_config.php` | **Deleted** — obsolete migration that created the duplicate store. |
| `GOOGLE_MAPS_API_KEY` constant in `includes/config.php` | **Removed** — a secondary key source (and a *different*, stale key). Comment now states the integrations table is the only source. |
| `api/location-search.php` comment referencing the parked constant | **Corrected** (endpoint itself is unchanged — keyless Nominatim by design). |

### Deliberately NOT touched
- Pricing engine, trip engine, wallet, authentication.
- `api/location-search.php` logic — Nominatim/OSM, keyless by design (never used the Google key).
- Email/SMS/OTP/policy/verification rows in `integration_settings`.
- All map-rendering JS (`map.js`, `helper.js`, `driver-trips.js`, `rider-trips.js`, `search.js`, `driver.js`) — already fetch the key only via `api/maps-key.php`; no hardcoded keys (verified by grep).

---

## 3. Bugs discovered, root cause, exact fix

| # | Bug | Root cause | Fix |
|---|---|---|---|
| 1 | Admin-saved key never used by Google | Service read `integration_settings`/constant, not the `integrations` row the admin edits | Repointed `getGoogleMapsConfig/Key` at `integrations` |
| 2 | Duplicate maps store | Prior attempt seeded a `google_maps_config` row + columns in `integration_settings` | Deleted row, dropped columns |
| 3 | Stale shadow key | `config.php` `GOOGLE_MAPS_API_KEY` held a different key that could win | Removed the constant entirely |
| 4 | Dead JSON-config helpers | `get/set_provider_configuration()` referenced dropped columns | Removed the functions |
| 5 | Misleading comments | `maps-key.php` / `location-search.php` / `config.php` described the old store | Comments corrected to the integrations table |

---

## 4. Verification performed (server-side, this environment)

Run against the live database + live Google endpoints:

| Check | Result |
|---|---|
| `getGoogleMapsKey()` returns the integrations key | ✅ `AIzaSyDeQRO-o-2nZZIRjlaW1wwT0qXS2Hsn_yU` (exact match) |
| Flags resolve from `enable_*` toggles | ✅ `{places, directions, distance_matrix, geocoding}` all true |
| Save-flow: toggle flip persists | ✅ `enable_places` "1"→"0"→"1" round-trips in the DB |
| Save-flow: blank api_key keeps stored key | ✅ key unchanged after a toggle-only save |
| Save-flow: lat/lng/zoom preserved on partial save | ✅ `6.5244 / 3.3792 / 13` retained |
| No maps rows left in `integration_settings` | ✅ 0 |
| No `GOOGLE_MAPS_API_KEY` / duplicate source in codebase | ✅ project-wide grep clean |
| **Geocoding** (Ikeja) | ✅ `6.601838,3.3514863` |
| **Reverse Geocoding** (6.5244,3.3792) | ✅ "1 Idowu Taylor St, Victoria Island, Lagos" |
| **Distance Matrix** | ✅ 28.81 km / 39 min |
| **Directions** (route + polyline) | ✅ 28.81 km / 39 min, polyline present |
| Raw Geocoding status | ✅ `OK` |

The live `OK` responses confirm the key is **valid**, the **Geocoding /
Directions / Distance Matrix APIs are enabled**, and **billing is active** for
this key server-side.

---

## 5. Browser verification — operator checklist (could not run here)

This environment can execute server-side PHP and inspect the JS, but cannot
drive a real browser. The following must be ticked in a logged-in browser. Note
that `RefererNotAllowedMapError` / `ApiNotActivatedMapError` /
`BillingNotEnabledMapError` originate in the **Google Cloud Console key
restrictions**, not in this code — if any appears, add the app's origin to the
key's HTTP-referrer allowlist and confirm the *Maps JavaScript API* + *Places
API* are enabled (the server-side APIs above are already confirmed enabled).

- [ ] Admin → Integration Center → Google Maps: save key, reload → shows masked; toggles reflect saved state.
- [ ] Driver & rider dashboards: map tiles render (no grey/blank, no "can't load Google Maps"), centers on GPS, "you are here" marker.
- [ ] Places autocomplete: predictions appear, selecting fills fields + coordinates + moves map.
- [ ] "Use my location" reverse-geocode (driver, search, ride-requests) resolves an address.
- [ ] Driver: create trip → pickup/destination search → real road route drawn with distance + ETA (not a straight line).
- [ ] Passenger: search location/destination → map + route render.
- [ ] Trip-detail mini-map (driver + rider): pickup/destination/live driver marker.
- [ ] Admin live-tracking map shows online users.
- [ ] Browser console: zero Google Maps errors (InvalidKey / RefererNotAllowed / ApiNotActivated / BillingNotEnabled / NoApiKeys).

---

## 6. Confirmation

Every Google Maps feature — browser (via `api/maps-key.php`) and server (via
`includes/google-maps.php`) — now reads its key from exactly one path:
`integrations → google_maps → configuration → api_key`. All duplicate stores,
fallback constants, and secondary sources have been removed and verified absent
project-wide. Server-side, every Google API returns `OK` with the stored key.
