<?php
// GET /api/v1/rider/dashboard.php — a single-call summary screen: wallet
// balance, unread notification count, and the currently-active trip (if
// any). Every figure is read from the same tables/columns the web
// dashboard already reads; nothing new is computed here.
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$userId = (int)$user['id'];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0");
$stmt->execute([$userId]);
$unreadNotifications = (int)$stmt->fetchColumn();

// "Active" = a live/just-boarding trip state, mirroring rider/dashboard.php's
// own liveTripCard query — never cancelled/completed.
$stmt = $pdo->prepare("
    SELECT rs.id AS ride_share_id, rs.public_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time,
           rs.trip_state, rs.status AS ride_status, u.fullname AS driver_name, u.phone AS driver_phone, u.rating AS driver_rating,
           b.id AS booking_id, b.seats_booked, b.passenger_state
    FROM ride_share_bookings b
    JOIN ride_shares rs ON rs.id = b.ride_share_id
    JOIN users u ON u.id = rs.driver_id
    WHERE b.rider_id = ? AND b.status NOT IN ('cancelled', 'completed') AND rs.status IN ('active', 'full', 'in_progress')
    ORDER BY rs.departure_date ASC, rs.departure_time ASC
    LIMIT 1
");
$stmt->execute([$userId]);
$activeTrip = $stmt->fetch() ?: null;

v1_success('Dashboard fetched', [
    'wallet_balance' => (float)$user['wallet_balance'],
    'unread_notifications' => $unreadNotifications,
    'active_trip' => $activeTrip,
]);
