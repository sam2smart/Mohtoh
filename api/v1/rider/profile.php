<?php
// GET /api/v1/rider/profile.php
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);

v1_success('Profile fetched', [
    'id' => (int)$user['id'],
    'public_id' => $user['user_id'],
    'fullname' => $user['fullname'],
    'email' => $user['email'],
    'phone' => $user['phone'],
    'rating' => (float)$user['rating'],
    'completed_rides' => (int)$user['completed_rides'],
    'wallet_balance' => (float)$user['wallet_balance'],
    'profile_image' => $user['profile_image'],
    'payment_preference' => $user['payment_preference'],
    'email_verified' => (bool)$user['email_verified'],
    'phone_verified' => (bool)$user['phone_verified'],
    'created_at' => $user['created_at'],
]);
