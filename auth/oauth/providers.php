<?php
// auth/oauth/providers.php
// PUBLIC, browser-safe endpoint: reports ONLY which social providers are
// available (enabled AND configured) plus their fixed start URLs. It exposes
// NO client id, NO secret, NO token — nothing sensitive. index.php renders its
// buttons server-side from the same oauth_public_providers() source; this
// endpoint exists for any client that wants to discover availability without
// scraping HTML.
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/oauth.php';

header('Content-Type: application/json');

$base = rtrim(mt_get_base_url(), '/');
$providers = [];
foreach (oauth_public_providers($pdo) as $name => $available) {
    $providers[$name] = [
        'available' => $available,
        // Only advertise a start URL for a provider that can actually be used.
        'start_url' => $available ? $base . '/auth/oauth/' . $name . '/start.php' : null,
    ];
}

echo json_encode(['status' => 'success', 'data' => ['providers' => $providers]]);
