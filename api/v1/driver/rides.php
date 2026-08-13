<?php
// GET /api/v1/driver/rides.php — same query as api/rideshare.php's
// my_rides action, with pagination added per Phase 13 (the original web
// action returns everything in one page; the mobile list view paginates).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);
$today = date('Y-m-d');

$stmt = $pdo->prepare("
    SELECT rs.id, rs.public_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.total_seats, rs.available_seats, rs.price_per_passenger, rs.description, rs.vehicle_type, rs.vehicle_info, rs.status, rs.trip_state, rs.current_latitude, rs.current_longitude, rs.last_location_update, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng,
           (SELECT COUNT(*) FROM ride_share_bookings rb WHERE rb.ride_share_id = rs.id AND rb.status != 'cancelled') AS passengers_joined,
           (SELECT COUNT(*) FROM ride_waitlists rw WHERE rw.ride_share_id = rs.id AND rw.status IN ('WAITING', 'NOTIFIED')) AS waiting_count,
           cr.reserved_amount, cr.used_amount, cr.released_amount, cr.status AS reservation_status
    FROM ride_shares rs
    LEFT JOIN ride_commission_reservations cr ON cr.ride_share_id = rs.id AND cr.status = 'active'
    WHERE rs.driver_id = ? ORDER BY rs.departure_date ASC, rs.departure_time ASC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$driverId]);
$rides = $stmt->fetchAll();

$statsStmt = $pdo->prepare("
    SELECT COUNT(*) AS total_rides,
           SUM(status = 'completed') AS completed_rides,
           SUM(status != 'cancelled' AND status != 'completed' AND departure_date >= ?) AS upcoming_rides,
           (SELECT COUNT(*) FROM ride_share_bookings rb JOIN ride_shares rs2 ON rs2.id = rb.ride_share_id WHERE rs2.driver_id = ? AND rb.status != 'cancelled') AS total_passengers
    FROM ride_shares WHERE driver_id = ?
");
$statsStmt->execute([$today, $driverId, $driverId]);
$statsRow = $statsStmt->fetch();

v1_success('Driver rides fetched', [
    'rides' => $rides,
    'stats' => [
        'total_rides' => (int)$statsRow['total_rides'],
        'upcoming_rides' => (int)$statsRow['upcoming_rides'],
        'completed_rides' => (int)$statsRow['completed_rides'],
        'total_passengers' => (int)$statsRow['total_passengers'],
    ],
], paginate_meta($page, $limit, (int)$statsRow['total_rides']));
