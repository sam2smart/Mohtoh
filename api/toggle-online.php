<?php
// api/toggle-online.php
//
// Online/offline ONLY controls Private Pickup availability. Going offline
// never touches Join Ride - a driver who is offline can still create and
// run Join Rides. Going ONLINE requires the driver to be genuinely
// eligible for private pickup (verified account + verified vehicle +
// private pickup enabled + available today), enforced at the backend so
// an unverified or ineligible driver can never flip themselves online and
// start receiving requests.

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/eligibility.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

if (($_SESSION['user_type'] ?? '') !== 'driver') {
    json_response('error', 'Only drivers can change online status.');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$is_online = isset($input['is_online']) ? (int)$input['is_online'] : null;
if ($is_online === null || !in_array($is_online, [0, 1], true)) {
    json_response('error', 'Invalid status');
}

$user_id = (int) $_SESSION['user_id'];

// Going ONLINE: enforce private-pickup eligibility at the backend. The
// eligibility check itself confirms verified account, verified vehicle,
// private_pickup_enabled and daily availability - so the client can never
// bypass verification by calling this endpoint directly.
//
// Deliberately the CAPABILITY form, not the full 'private_pickup' gate: the
// full gate also requires is_online, which is the very flag being set here.
// Using it would make being online a precondition for going online, so an
// offline driver could never come online at all.
if ($is_online === 1) {
    $elig = check_driver_eligibility($pdo, $user_id, 'private_pickup_capability');
    if (!$elig['eligible']) {
        json_response('error', $elig['reason'], ['code' => $elig['code']]);
    }
}

// Going OFFLINE is always allowed and only affects private pickup
// discoverability - Join Ride is untouched by this flag.
$stmt = $pdo->prepare("UPDATE users SET is_online = ? WHERE id = ?");
if ($stmt->execute([$is_online, $user_id])) {
    json_response('success', $is_online ? 'You are online for Private Pickup.' : 'You are offline for Private Pickup. Join Ride is unaffected.', ['is_online' => $is_online]);
} else {
    json_response('error', 'Failed to update status');
}
