<?php
// POST /api/v1/device/remove.php — called on logout (or when push
// permission is revoked) so a stale token never receives a push aimed
// at an account the device is no longer signed into. Only ever removes
// a device row belonging to the token's own authenticated user.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
$input = v1_input();

$deviceToken = mt_scalar_string($input['device_token'] ?? '');
if ($deviceToken === '') {
    v1_error('device_token is required.', 'validation_error');
}

$stmt = $pdo->prepare("DELETE FROM user_devices WHERE device_token = ? AND user_id = ?");
$stmt->execute([$deviceToken, (int)$user['id']]);

v1_success('Device removed.');
