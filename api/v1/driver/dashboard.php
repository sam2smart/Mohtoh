<?php
// GET /api/v1/driver/dashboard.php
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0");
$stmt->execute([$driverId]);
$unreadNotifications = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT id, public_id, start_location, destination, departure_date, departure_time, total_seats, available_seats, trip_state, status
    FROM ride_shares
    WHERE driver_id = ? AND status IN ('active', 'full', 'in_progress')
    ORDER BY departure_date ASC, departure_time ASC
    LIMIT 1
");
$stmt->execute([$driverId]);
$activeRide = $stmt->fetch() ?: null;

$availableBalance = get_available_wallet_balance($pdo, $driverId);

v1_success('Dashboard fetched', [
    'wallet_balance' => (float)$user['wallet_balance'],
    'available_balance' => $availableBalance,
    'unread_notifications' => $unreadNotifications,
    'is_online' => (bool)$user['is_online'],
    'is_verified' => (bool)$user['is_verified'],
    'active_ride' => $activeRide,
]);
