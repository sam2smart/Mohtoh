<?php
// POST /api/v1/auth/refresh.php — rotates a still-valid token for a new
// one (old token is revoked in the same call). Prevents an app needing
// to force a full re-login every 30 days.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$oldToken = v1_bearer_token();
$newToken = refresh_api_token($pdo, $oldToken);
if (!$newToken) {
    v1_error('Invalid or expired session. Please log in again.', 'unauthorized', 401);
}

v1_success('Token refreshed.', [
    'token' => $newToken,
    'token_type' => 'Bearer',
    'expires_in_days' => API_TOKEN_TTL_DAYS,
]);
