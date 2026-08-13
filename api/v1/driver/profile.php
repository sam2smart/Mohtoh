<?php
// GET /api/v1/driver/profile.php
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
$stmt->execute([$user['id']]);
$vehicle = $stmt->fetch() ?: null;

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
    'is_verified' => (bool)$user['is_verified'],
    'is_online' => (bool)$user['is_online'],
    'driver_tier' => $user['driver_tier'],
    'payment_preference' => $user['payment_preference'],
    'email_verified' => (bool)$user['email_verified'],
    'phone_verified' => (bool)$user['phone_verified'],
    'vehicle' => $vehicle,
]);
