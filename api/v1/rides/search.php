<?php
// GET /api/v1/rides/search.php?pickup_location=&destination=&departure_date=&departure_time=
// Same query as api/rideshare.php's search action — uses ride_shares
// only, no separate ride model.
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);

$pickupLocation = sanitize_input(trim($_GET['pickup_location'] ?? ''));
$destination = sanitize_input(trim($_GET['destination'] ?? ''));
$departureDate = sanitize_input(trim($_GET['departure_date'] ?? ''));
$departureTime = sanitize_input(trim($_GET['departure_time'] ?? ''));

if ($pickupLocation === '' || $destination === '' || $departureDate === '') {
    v1_error('pickup_location, destination and departure_date are required.', 'validation_error');
}

$sql = "SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.total_seats, rs.available_seats, rs.status, rs.price_per_passenger, rs.pricing_mode, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, u.fullname AS driver_name, u.rating AS driver_rating, u.profile_image AS driver_image, u.driver_tier, v.make AS vehicle_make, v.model AS vehicle_model, v.luggage_capacity
        FROM ride_shares rs
        JOIN users u ON u.id = rs.driver_id
        LEFT JOIN vehicles v ON rs.vehicle_id = v.id
        WHERE rs.status IN ('active', 'full') AND rs.departure_date = ? AND rs.start_location LIKE ? AND rs.destination LIKE ?";
$params = [$departureDate, '%' . $pickupLocation . '%', '%' . $destination . '%'];

if ($departureTime !== '') {
    $sql .= " AND rs.departure_time >= ?";
    $params[] = $departureTime;
}
$sql .= " ORDER BY rs.departure_time ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rides = $stmt->fetchAll();

foreach ($rides as &$ride) {
    $distance = calculate_distance_km($ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
    $ride['distance_km'] = $distance !== null ? round($distance, 1) : null;
}
unset($ride);

v1_success('Matching rides fetched', ['rides' => $rides]);
