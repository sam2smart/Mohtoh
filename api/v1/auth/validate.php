<?php
// GET /api/v1/auth/validate.php — lets the app check on startup whether
// its stored token is still valid, without triggering a refresh/rotation.
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_success('Token is valid.', [
    'user' => [
        'id' => (int)$user['id'],
        'public_id' => $user['user_id'],
        'fullname' => $user['fullname'],
        'user_type' => $user['user_type'],
        'email_verified' => (bool)$user['email_verified'],
        'phone_verified' => (bool)$user['phone_verified'],
    ],
]);
