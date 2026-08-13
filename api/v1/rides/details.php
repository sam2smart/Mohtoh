<?php
// GET /api/v1/rides/details.php?ride_id=
// Same query/shape as api/rideshare.php's details action.
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ride_queue.php';

$user = v1_require_auth($pdo);

$rideId = (int)($_GET['ride_id'] ?? 0);
if ($rideId <= 0) {
    v1_error('ride_id is required.', 'validation_error');
}

$stmt = $pdo->prepare("SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.vehicle_type, rs.vehicle_info, rs.total_seats, rs.available_seats, rs.price_per_passenger, rs.pricing_mode, rs.commission_percentage, rs.payment_preference, rs.description, rs.status, rs.created_at, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, u.fullname AS driver_name, u.profile_image, u.rating AS driver_rating, v.make as vehicle_make, v.model as vehicle_model, v.luggage_capacity FROM ride_shares rs JOIN users u ON u.id = rs.driver_id LEFT JOIN vehicles v ON rs.vehicle_id = v.id WHERE rs.id = ?");
$stmt->execute([$rideId]);
$ride = $stmt->fetch();
if (!$ride) {
    v1_error('Ride not found.', 'not_found', 404);
}

$isOwnDriver = $user['user_type'] === 'driver' && (int)$ride['driver_id'] === (int)$user['id'];
if (!$isOwnDriver) {
    unset($ride['commission_percentage']);
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE ride_share_id = ? AND rider_id = ? AND status != 'cancelled'");
$stmt->execute([$rideId, $user['id']]);
$ride['already_joined'] = (int)$stmt->fetchColumn() > 0;
$ride['remaining_seats'] = (int)$ride['available_seats'];

$ride['queue_entry'] = null;
if ($user['user_type'] === 'rider') {
    $stmt = $pdo->prepare("SELECT public_id, status, seats_requested FROM ride_waitlists WHERE ride_share_id = ? AND user_id = ? AND status IN ('WAITING', 'NOTIFIED', 'RESERVED') LIMIT 1");
    $stmt->execute([$rideId, $user['id']]);
    $queueEntry = $stmt->fetch();
    if ($queueEntry) {
        $ride['queue_entry'] = $queueEntry;
    }
}

$ride['distance_km'] = null;
$ride['estimated_duration_minutes'] = null;
if ($ride['pickup_lat'] && $ride['pickup_lng'] && $ride['destination_lat'] && $ride['destination_lng']) {
    // Real road distance + ETA via Google Distance Matrix when configured;
    // haversine/40km/h fallback otherwise (same display path as the web app).
    $distance = calculate_trip_distance_km($pdo, $ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
    $ride['distance_km'] = $distance !== null ? round($distance, 1) : null;
    $ride['estimated_duration_minutes'] = calculate_trip_duration_minutes($pdo, $ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
}

v1_success('Ride details fetched', ['ride' => $ride]);
