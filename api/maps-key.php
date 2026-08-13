<?php
// api/maps-key.php
// Session-guarded endpoint that returns the Google Maps JavaScript API
// browser key — plus the per-API enable flags — to authenticated browser
// sessions only.
//
// The key comes from ONE source of truth: the `integrations` table's
// google_maps row (`configuration` JSON, field `api_key`), read via
// includes/google-maps.php's getGoogleMapsKey(). It is stored in plaintext
// like the table's other provider rows — there is no config.php constant, no
// integration_settings row, and no other fallback. It is never hardcoded in
// any JS file. Keep the key restricted by HTTP referrer in Google Cloud
// Console as defense-in-depth alongside this session guard.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/google-maps.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!is_logged_in()) {
    echo json_encode(['key' => null]);
    exit;
}

$key = getGoogleMapsKey($pdo);
echo json_encode([
    'key' => $key !== '' ? $key : null,
    'flags' => googleMapsEnabledFlags($pdo),
]);
