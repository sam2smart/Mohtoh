<?php
// POST /api/v1/device/register.php — records a push-notification device
// token against the token's own authenticated user (never a user_id
// from the request body). Upsert: re-registering the same device_token
// just re-associates it with whoever is currently logged in on that
// device and refreshes last_active.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
$input = v1_input();

$deviceToken = mt_scalar_string($input['device_token'] ?? '');
$platform = mt_scalar_string($input['platform'] ?? '');
if ($deviceToken === '' || !in_array($platform, ['ios', 'android'], true)) {
    v1_error('device_token and a valid platform (ios/android) are required.', 'validation_error');
}

$stmt = $pdo->prepare("
    INSERT INTO user_devices (user_id, device_token, platform, last_active)
    VALUES (?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), platform = VALUES(platform), last_active = NOW()
");
$stmt->execute([(int)$user['id'], $deviceToken, $platform]);

v1_success('Device registered for push notifications.');
