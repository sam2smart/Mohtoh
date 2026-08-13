<?php
// api/private-pickup.php
//
// HTTP layer for the Private Solo Pickup system. Thin action-router over
// includes/private_pickup.php, mirroring api/ride-requests.php exactly:
// same session guard, same JSON-in/JSON-out contract via json_response(),
// same public-id-only exposure, same switch-on-action shape.
//
// Kept entirely SEPARATE from api/rideshare.php (Join Ride). Nothing here
// touches the Join Ride create/book flow; private pickup is its own lane
// that only REUSES shared primitives (trip engine, wallet, commission).
//
// Role separation:
//   rider  -> create, cancel, status
//   driver -> feed, accept, decline
// Backend validates role on every action; the client is never trusted.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

session_start();
header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action    = $input['action'] ?? $_GET['action'] ?? '';
$user_id   = (int) $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';

try {
    switch ($action) {

        // ---------------- RIDER ----------------
        case 'create':
            if ($user_type !== 'rider') {
                json_response('error', 'Only riders can request a private pickup.');
            }
            $result = create_private_pickup_request($pdo, $user_id, [
                'pickup_location'   => $input['pickup_location'] ?? '',
                'pickup_lat'        => $input['pickup_lat'] ?? '',
                'pickup_lng'        => $input['pickup_lng'] ?? '',
                'destination'       => $input['destination'] ?? '',
                'destination_lat'   => $input['destination_lat'] ?? '',
                'destination_lng'   => $input['destination_lng'] ?? '',
                'notes'             => $input['notes'] ?? '',
            ]);
            json_response($result['status'], $result['message'], $result['data'] ?? []);
            break;

        // Fare preview for the request form. The fare MUST come from the
        // server's own configurable settings - the client never does fare
        // math, so what the rider is quoted is what create() will charge.
        case 'estimate':
            if ($user_type !== 'rider') {
                json_response('error', 'Only riders can estimate a private pickup.');
            }
            $eLat = ($input['pickup_lat'] ?? $_GET['pickup_lat'] ?? '') !== '' ? (float)($input['pickup_lat'] ?? $_GET['pickup_lat']) : null;
            $eLng = ($input['pickup_lng'] ?? $_GET['pickup_lng'] ?? '') !== '' ? (float)($input['pickup_lng'] ?? $_GET['pickup_lng']) : null;
            $eDLat = ($input['destination_lat'] ?? $_GET['destination_lat'] ?? '') !== '' ? (float)($input['destination_lat'] ?? $_GET['destination_lat']) : null;
            $eDLng = ($input['destination_lng'] ?? $_GET['destination_lng'] ?? '') !== '' ? (float)($input['destination_lng'] ?? $_GET['destination_lng']) : null;
            if (!$eLat || !$eLng || !$eDLat || !$eDLng) {
                json_response('error', 'Pickup and destination coordinates are required for an estimate.');
            }
            $eDistance = calculate_distance_km($eLat, $eLng, $eDLat, $eDLng);
            // Keke and car are priced separately, and no driver is matched at
            // preview time, so the rider is shown BOTH class prices rather than
            // one blended figure that matches neither. All of it is computed
            // here by the fare engine; the client only renders these numbers.
            $eOptions = private_pickup_fare_options($pdo, $eDistance);
            $eFare = $eOptions['keke'] ?? estimate_private_pickup_fare($pdo, $eDistance, 'keke');
            json_response('success', 'Estimate ready', [
                'distance_km'    => $eDistance,
                'estimated_fare' => round($eFare['total'], 2),
                'base_fare'      => $eFare['base_fare'],
                'distance_fare'  => $eFare['distance_fare'],
                'booking_fee'    => $eFare['booking_fee'],
                'fare_options'   => private_pickup_public_fare_options($eOptions),
                'radius_km'      => (float) get_system_setting($pdo, 'private_pickup_radius_km', 20),
            ]);
            break;

        case 'cancel':
            if ($user_type !== 'rider') {
                json_response('error', 'Only riders can cancel their pickup request.');
            }
            $result = cancel_private_pickup_request($pdo, $user_id, (string)($input['request_public_id'] ?? ''));
            json_response($result['status'], $result['message'], $result['data'] ?? []);
            break;

        case 'status':
            if ($user_type !== 'rider') {
                json_response('error', 'Only riders can view their pickup request status.');
            }
            $result = get_private_pickup_status($pdo, $user_id, (string)($input['request_public_id'] ?? $_GET['request_public_id'] ?? ''));
            json_response($result['status'], $result['message'], $result['data'] ?? []);
            break;

        // ---------------- DRIVER ----------------
        case 'feed':
            if ($user_type !== 'driver') {
                json_response('error', 'Only drivers can view the pickup request feed.');
            }
            $result = get_driver_pickup_feed($pdo, $user_id);
            json_response('success', 'Pickup feed fetched', $result);
            break;

        case 'accept':
            if ($user_type !== 'driver') {
                json_response('error', 'Only drivers can accept a pickup request.');
            }
            $result = accept_private_pickup_request($pdo, $user_id, (string)($input['request_public_id'] ?? ''));
            json_response($result['status'], $result['message'], $result['data'] ?? []);
            break;

        case 'decline':
            if ($user_type !== 'driver') {
                json_response('error', 'Only drivers can decline a pickup request.');
            }
            $result = decline_private_pickup_request($pdo, $user_id, (string)($input['request_public_id'] ?? ''));
            json_response($result['status'], $result['message'], $result['data'] ?? []);
            break;

        default:
            json_response('error', 'Unknown action');
    }
} catch (Exception $e) {
    json_response('error', 'An unexpected error occurred.');
}
