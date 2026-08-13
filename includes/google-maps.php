<?php
// includes/google-maps.php
// Central Google Maps service layer — the ONLY place in the PHP codebase
// that talks to Google Maps APIs. Every other module (api/maps-key.php,
// api/rideshare.php, api/v1/*, diagnostics, integration-center test_maps)
// goes through the functions here; never call Google from a random file.
//
// The Google Maps API key is read from ONE place only: the `integrations`
// table (the Service Integration Center's provider registry), on the row
// provider = 'google_maps', inside its `configuration` JSON, field `api_key`.
// There is NO other source — no includes/config.php constant, no
// integration_settings row, no environment variable, no hardcoded fallback.
// The project stores this config in plaintext (like its other provider rows),
// so no encrypt/decrypt step is applied here. A single api_key serves both the
// browser (Maps JS / Places / Geocoder / Directions via api/maps-key.php) and
// server-side PHP calls (Geocoding / Directions / Distance Matrix).

// Provider row in the `integrations` table.
const MT_MAPS_PROVIDER = 'google_maps';

/** Read and decode the google_maps `configuration` JSON from the `integrations`
 *  table — the ONE source of truth. Returns the decoded array, or [] if the
 *  row is missing/blank/unreadable. Cached per-request (invalidate with
 *  mt_maps_config_flush() after a write in the same request). */
function getGoogleMapsConfig($pdo): array {
    if (isset($GLOBALS['_mt_maps_config_cache'])) {
        return $GLOBALS['_mt_maps_config_cache'];
    }
    $config = [];
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT configuration FROM integrations WHERE provider = ? LIMIT 1");
            $stmt->execute([MT_MAPS_PROVIDER]);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            }
        } catch (PDOException $e) {
            $config = []; // table/row unavailable — treat as "not configured"
        }
    }
    $GLOBALS['_mt_maps_config_cache'] = $config;
    return $config;
}

/** Drop the per-request config cache so a read after a save in the same
 *  request sees the new value. */
function mt_maps_config_flush(): void {
    unset($GLOBALS['_mt_maps_config_cache']);
}

/** Merge-and-save the google_maps `configuration` JSON in the `integrations`
 *  table — the single write path, mirroring the single read path. Partial
 *  updates are merged into the existing document so unspecified fields (lat/
 *  lng/zoom, other toggles) are preserved. Values are stored as-is (plaintext,
 *  matching the table's existing rows). Returns true on a successful write. */
function setGoogleMapsConfig($pdo, array $incoming): bool {
    if (!isset($pdo)) {
        return false;
    }
    $merged = array_merge(getGoogleMapsConfig($pdo), $incoming);
    $stmt = $pdo->prepare("UPDATE integrations SET configuration = ?, updated_at = NOW() WHERE provider = ?");
    $stmt->execute([json_encode($merged), MT_MAPS_PROVIDER]);
    // Fall back to an insert only if the provider row somehow doesn't exist yet
    // (the seed normally creates it). Keeps this the sole writer either way.
    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM integrations WHERE provider = ?");
        $exists->execute([MT_MAPS_PROVIDER]);
        if ((int) $exists->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO integrations (provider, category, display_name, status, is_default, priority, configuration, created_at, updated_at) VALUES (?, 'maps', 'Google Maps', 'enabled', 1, 1, ?, NOW(), NOW())")
                ->execute([MT_MAPS_PROVIDER, json_encode($merged)]);
        }
    }
    mt_maps_config_flush();
    return true;
}

/** THE Google Maps API key — integrations → google_maps → configuration.api_key.
 *  No other source. Empty string when not configured. A single key serves both
 *  browser and server-side calls (the integrations row stores one api_key). */
function getGoogleMapsKey($pdo): string {
    $key = getGoogleMapsConfig($pdo)['api_key'] ?? '';
    return is_string($key) ? trim($key) : '';
}

/** Server-side calls use the same single api_key. Kept as a named function so
 *  existing callers (Distance Matrix / server geocoding) don't need to change. */
function getGoogleMapsServerKey($pdo): string {
    return getGoogleMapsKey($pdo);
}

/** Whether a Google Maps key is configured. */
function googleMapsConfigured($pdo): bool {
    return getGoogleMapsKey($pdo) !== '';
}

/** Per-API enable toggles, read from the integrations config. The seeded row
 *  uses enable_places / enable_directions / enable_geocoding (string "1"/"0").
 *  Distance Matrix has no separate switch in this schema, so it follows the
 *  directions (routing) toggle. Absent field => enabled. */
function googleMapsEnabledFlags($pdo): array {
    $config = getGoogleMapsConfig($pdo);
    $on = function ($v) {
        if ($v === null) { return true; }              // absent => on
        if (is_bool($v)) { return $v; }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    };
    $directions = $on($config['enable_directions'] ?? null);
    return [
        'places' => $on($config['enable_places'] ?? null),
        'directions' => $directions,
        'distance_matrix' => $directions,
        'geocoding' => $on($config['enable_geocoding'] ?? null),
    ];
}

/** Common HTTP GET with timeout + error capture. Returns decoded JSON or null. */
function _google_maps_http_get(string $url, ?string $apiKey): ?array {
    $url = $apiKey !== null && $apiKey !== '' ? $url . '&key=' . rawurlencode($apiKey) : $url;
    $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true, 'user_agent' => 'MohToh/1.0']]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return null;
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

/** Friendly, human-readable reason for a Google error_status — surfaced in
 *  the Integration Center "Test connection" and by server callers instead of
 *  a raw Google code. */
function google_maps_error_message(?string $status, ?string $fallback = 'Google Maps request failed.'): string {
    $messages = [
        'REQUEST_DENIED' => 'Google Maps rejected the request. Check that the API key is valid, the API is enabled in Google Cloud Console, and billing is active.',
        'INVALID_REQUEST' => 'Google Maps could not process the request (invalid parameters).',
        'OVER_QUERY_LIMIT' => 'Google Maps quota exceeded — requests are temporarily rate-limited.',
        'OVER_DAILY_LIMIT' => 'Google Maps daily quota exceeded for this key.',
        'NOT_FOUND' => 'The requested place or route could not be found.',
        'ZERO_RESULTS' => 'No matching results were found.',
        'MAX_ROUTE_LENGTH_EXCEEDED' => 'The route is too long for the Google Maps API.',
        'MAX_WAYPOINTS_EXCEEDED' => 'Too many waypoints were supplied for the Google Maps API.',
    ];
    return $status && isset($messages[$status]) ? $messages[$status] : ($fallback ?: 'Google Maps request failed.');
}

// ---------------------------------------------------------------
// Geocoding (Google Geocoding API, server side). Used by the
// integration-center "Test connection" and available to API modules.
// Shape mirrors what the JS Nominatim-replacement returns so callers
// can use either source interchangeably.
// ---------------------------------------------------------------

/** Geocode a free-text address → {address, formatted_address, city, state,
 *  country, latitude, longitude} or null. `formatted_address` is the full
 *  Google display string; `address` is a trimmed "first line". */
function geocodeAddress($pdo, string $address): ?array {
    if (!googleMapsConfigured($pdo)) {
        return null;
    }
    $data = _google_maps_http_get(
        'https://maps.googleapis.com/maps/api/geocode/json?address=' . rawurlencode($address),
        getGoogleMapsServerKey($pdo)
    );
    if (!$data || ($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
        return null;
    }
    return _google_geocode_result_to_shape($data['results'][0]);
}

/** Reverse geocode a lat/lng → same shape. Used by the server-side
 *  reverseGeocode() counterpart and the test action. */
function reverseGeocode($pdo, float $lat, float $lng): ?array {
    if (!googleMapsConfigured($pdo)) {
        return null;
    }
    $data = _google_maps_http_get(
        'https://maps.googleapis.com/maps/api/geocode/json?latlng=' . $lat . ',' . $lng,
        getGoogleMapsServerKey($pdo)
    );
    if (!$data || ($data['status'] ?? '') !== 'OK' || empty($data['results'])) {
        return null;
    }
    return _google_geocode_result_to_shape($data['results'][0]);
}

/** Flatten one Google Geocoder result into the app's standard shape. */
function _google_geocode_result_to_shape(array $result): array {
    $components = [];
    foreach ($result['address_components'] ?? [] as $comp) {
        foreach ($comp['types'] ?? [] as $type) {
            $components[$type] = $comp['long_name'];
        }
    }
    $formatted = (string) ($result['formatted_address'] ?? '');
    $firstLine = explode(',', $formatted)[0];
    return [
        'address' => trim($firstLine),
        'formatted_address' => $formatted,
        'city' => $components['locality'] ?? $components['postal_town'] ?? $components['city'] ?? null,
        'state' => $components['administrative_area_level_1'] ?? null,
        'country' => $components['country'] ?? null,
        'latitude' => (float) ($result['geometry']['location']['lat'] ?? 0),
        'longitude' => (float) ($result['geometry']['location']['lng'] ?? 0),
    ];
}

// ---------------------------------------------------------------
// Distance Matrix — server-side trip distance + ETA. Only the real-trip
// display path uses this (per confirmed scope); bulk proximity ranking
// keeps using the free haversine helpers.
// ---------------------------------------------------------------

/**
 * Trip-route distance in km via the Distance Matrix API.
 * Returns array {distance_km, duration_minutes} or null on any failure.
 * The caller decides what to do when null (fall back to haversine).
 */
function calculateDistance($pdo, float $originLat, float $originLng, float $destLat, float $destLng): ?array {
    if (!googleMapsConfigured($pdo) || !googleMapsEnabledFlags($pdo)['distance_matrix']) {
        return null;
    }
    $data = _google_maps_http_get(
        'https://maps.googleapis.com/maps/api/distancematrix/json?units=metric&origins=' . $originLat . ',' . $originLng . '&destinations=' . $destLat . ',' . $destLng,
        getGoogleMapsServerKey($pdo)
    );
    if (!$data || ($data['status'] ?? '') !== 'OK') {
        return null;
    }
    $row = $data['rows'][0] ?? null;
    $element = $row['elements'][0] ?? null;
    if (!$element || ($element['status'] ?? '') !== 'OK') {
        return null;
    }
    $distanceMeters = (int) ($element['distance']['value'] ?? 0);
    $durationSeconds = (int) ($element['duration']['value'] ?? 0);
    if ($distanceMeters <= 0) {
        return null;
    }
    return [
        'distance_km' => round($distanceMeters / 1000, 2),
        'duration_minutes' => $durationSeconds > 0 ? (int) round($durationSeconds / 60) : null,
    ];
}

// ---------------------------------------------------------------
// Directions — full route polyline (encoded) + distance + duration for
// a server-side route (used by the test action; the browser renders
// live routes via the JS DirectionsService).
// ---------------------------------------------------------------

/**
 * Full route via the Google Directions API.
 * Returns {distance_km, duration_minutes, polyline, start, end, steps} or
 * null on failure. `polyline` is the encoded path — decode with
 * google.maps.geometry.encoding.decodePath() on the client if needed.
 */
function getDirections($pdo, float $originLat, float $originLng, float $destLat, float $destLng, array $waypoints = []): ?array {
    if (!googleMapsConfigured($pdo) || !googleMapsEnabledFlags($pdo)['directions']) {
        return null;
    }
    $url = 'https://maps.googleapis.com/maps/api/directions/json?mode=driving&origin=' . $originLat . ',' . $originLng . '&destination=' . $destLat . ',' . $destLng;
    if (!empty($waypoints)) {
        $url .= '&waypoints=' . rawurlencode(implode('|', array_map(fn($w) => $w['lat'] . ',' . $w['lng'], $waypoints)));
    }
    $data = _google_maps_http_get($url, getGoogleMapsServerKey($pdo));
    if (!$data || ($data['status'] ?? '') !== 'OK' || empty($data['routes'])) {
        return null;
    }
    $route = $data['routes'][0];
    $leg = $route['legs'][0] ?? null;
    $distanceMeters = 0;
    $durationSeconds = 0;
    foreach ($route['legs'] ?? [] as $legItem) {
        $distanceMeters += (int) ($legItem['distance']['value'] ?? 0);
        $durationSeconds += (int) ($legItem['duration']['value'] ?? 0);
    }
    $start = $route['legs'][0]['start_location'] ?? null;
    $end = $route['legs'][count($route['legs'] ?? []) - 1]['end_location'] ?? null;
    return [
        'distance_km' => $distanceMeters > 0 ? round($distanceMeters / 1000, 2) : null,
        'duration_minutes' => $durationSeconds > 0 ? (int) round($durationSeconds / 60) : null,
        'polyline' => $route['overview_polyline']['points'] ?? null,
        'start' => $start ? ['lat' => $start['lat'], 'lng' => $start['lng']] : null,
        'end' => $end ? ['lat' => $end['lat'], 'lng' => $end['lng']] : null,
        'steps' => $leg ? array_map(fn($step) => ['text' => $step['html_instructions'] ?? '', 'distance' => $step['distance']['text'] ?? null, 'duration' => $step['duration']['text'] ?? null], $leg['steps'] ?? []) : [],
    ];
}

// ---------------------------------------------------------------
// Trip distance drop-in — the one function the pricing/trip display
// code calls. Google Distance Matrix first; haversine fallback so a
// keyless/offline/quota-exceeded state never breaks trip creation or
// fare display (the haversine number feeds the SAME pricing formula,
// so fares stay deterministic and unchanged).
// ---------------------------------------------------------------

/**
 * Distance (km) for a real trip route. Tries Google Distance Matrix;
 * falls back to the existing haversine helper. Returns float (rounded
 * to 2dp) or null if coordinates are missing. Never throws.
 */
function calculate_trip_distance_km($pdo, ?float $originLat, ?float $originLng, ?float $destLat, ?float $destLng): ?float {
    if ($originLat === null || $originLng === null || $destLat === null || $destLng === null) {
        return null;
    }
    $google = calculateDistance($pdo, $originLat, $originLng, $destLat, $destLng);
    if ($google !== null && isset($google['distance_km'])) {
        return (float) $google['distance_km'];
    }
    if (function_exists('calculate_distance_km')) {
        $haversine = calculate_distance_km($originLat, $originLng, $destLat, $destLng);
        if ($haversine !== null) {
            return round((float) $haversine, 2);
        }
    }
    return null;
}

/**
 * ETA (minutes) for a real trip route. Google Distance Matrix first;
 * falls back to the existing distance/40km/h estimate. Returns int or null.
 */
function calculate_trip_duration_minutes($pdo, ?float $originLat, ?float $originLng, ?float $destLat, ?float $destLng): ?int {
    if ($originLat === null || $originLng === null || $destLat === null || $destLng === null) {
        return null;
    }
    $google = calculateDistance($pdo, $originLat, $originLng, $destLat, $destLng);
    if ($google !== null && isset($google['duration_minutes']) && $google['duration_minutes'] !== null) {
        return (int) $google['duration_minutes'];
    }
    $distance = calculate_trip_distance_km($pdo, $originLat, $originLng, $destLat, $destLng);
    return $distance !== null && $distance > 0 ? (int) round(($distance / 40) * 60) : null; // 40km/h average, matches existing search estimate
}
