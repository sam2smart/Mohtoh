<?php
// POST /api/v1/auth/logout.php — revokes the presented token only
// (the device calling this, not every device the user is on).
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
revoke_api_token($pdo, v1_bearer_token());
v1_success('Logged out successfully.');
