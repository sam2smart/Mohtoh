<?php
// GET /api/v1/rider/trips.php — same query/shape as api/rideshare.php's
// my_bookings action.
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$riderId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);

$stmt = $pdo->prepare("
    SELECT rb.id AS booking_id, rb.public_id AS booking_public_id, rb.ride_share_id, rs.public_id AS ride_public_id, rb.seats_booked, rb.status, rb.payment_method, rb.passenger_state,
           rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.vehicle_type, rs.vehicle_info, rs.price_per_passenger, rs.available_seats, rs.status AS ride_status, rs.trip_state,
           rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng,
           u.id as driver_id, u.fullname AS driver_name, u.phone AS driver_phone, u.profile_image AS driver_image, u.rating AS driver_rating,
           CASE WHEN v.status = 'approved' THEN 1 ELSE 0 END AS driver_verified, v.status AS driver_vehicle_status, v.make AS vehicle_make, v.model AS vehicle_model, v.plate_number AS vehicle_plate,
           p.status AS payment_status, p.fare_amount,
           rsr.id as rating_id, rsr.rating AS rating_given
    FROM ride_share_bookings rb
    JOIN ride_shares rs ON rs.id = rb.ride_share_id
    JOIN users u ON u.id = rs.driver_id
    LEFT JOIN vehicles v ON rs.vehicle_id = v.id
    LEFT JOIN payments p ON p.booking_id = rb.id
    LEFT JOIN ride_share_ratings rsr ON rsr.booking_id = rb.id
    WHERE rb.rider_id = ?
    ORDER BY rs.departure_date DESC, rs.departure_time DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$riderId]);
$rides = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT
        SUM(rb.status != 'cancelled' AND rs.status != 'cancelled') AS joined_rides,
        SUM(rb.status != 'cancelled' AND rs.status != 'cancelled' AND rs.status = 'completed') AS completed_trips,
        SUM(rb.status != 'cancelled' AND rs.status != 'cancelled' AND rs.status != 'completed' AND rs.departure_date >= CURDATE()) AS upcoming_trips,
        SUM(rb.status = 'cancelled' OR rs.status = 'cancelled') AS cancelled_trips,
        COUNT(*) AS total_records
    FROM ride_share_bookings rb
    JOIN ride_shares rs ON rs.id = rb.ride_share_id
    WHERE rb.rider_id = ?
");
$stmt->execute([$riderId]);
$statsRow = $stmt->fetch();

v1_success('Trips fetched', [
    'rides' => $rides,
    'stats' => [
        'joined_rides' => (int)$statsRow['joined_rides'],
        'upcoming_trips' => (int)$statsRow['upcoming_trips'],
        'completed_trips' => (int)$statsRow['completed_trips'],
        'cancelled_trips' => (int)$statsRow['cancelled_trips'],
    ],
], paginate_meta($page, $limit, (int)$statsRow['total_records']));
