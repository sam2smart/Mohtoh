<?php
// POST /api/v1/driver/start-trip.php — reuses mark_ride_started() as-is
// (already a standalone, callable trip-engine function on the web side).
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);

$input = v1_input();
$rideId = (int)($input['ride_id'] ?? 0);
if ($rideId <= 0) {
    v1_error('ride_id is required.', 'validation_error');
}

if (mark_ride_started($pdo, $rideId, (int)$user['id'], 'MANUAL_DRIVER_ACTION')) {
    v1_success('Ride started successfully.');
}
v1_error('Could not start ride. It may have already been started, completed, or cancelled.', 'start_trip_failed');
