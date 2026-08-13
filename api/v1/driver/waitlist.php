<?php
// GET /api/v1/driver/waitlist.php?ride_id=
// Ride Queue demand summary for one of the driver's own rides — a COUNT
// only, never the queued passengers' identities (same privacy rule the
// web driver view enforces via get_ride_queue_summary()).
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ride_queue.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);

$rideShareId = (int)($_GET['ride_id'] ?? 0);
if ($rideShareId <= 0) {
    v1_error('ride_id is required.', 'validation_error');
}

$stmt = $pdo->prepare("SELECT driver_id, start_location, destination FROM ride_shares WHERE id = ?");
$stmt->execute([$rideShareId]);
$ride = $stmt->fetch();
if (!$ride) {
    v1_error('Ride not found.', 'not_found', 404);
}
if ((int)$ride['driver_id'] !== (int)$user['id']) {
    v1_error('You are not authorized to view this ride.', 'forbidden', 403);
}

v1_success('Ride queue summary fetched', [
    'route' => $ride['start_location'] . ' → ' . $ride['destination'],
    'summary' => get_ride_queue_summary($pdo, $rideShareId),
]);
