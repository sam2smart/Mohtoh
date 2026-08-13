<?php
// api/pickup-availability.php
//
// Driver daily availability prompt for Private Pickup (criterion #6):
//   "Would you like to receive Private Pickup requests today?"
//
// YES -> private_pickup_enabled = 1, pickup_available_today = 1,
//        pickup_availability_date = today. The driver can now go online
//        and become discoverable.
// NO  -> pickup_available_today = 0 (private pickup off for today). Join
//        Ride remains fully available regardless.
//
// The date stamp means a "yes" never silently carries into the next day -
// the prompt must be answered again each day (see driver_available_for_pickup_today).

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/eligibility.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}
if (($_SESSION['user_type'] ?? '') !== 'driver') {
    json_response('error', 'Only drivers can set pickup availability.');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action  = $input['action'] ?? $_GET['action'] ?? 'get';
$user_id = (int) $_SESSION['user_id'];
$today   = date('Y-m-d');

try {
    if ($action === 'get') {
        // Report whether the driver has answered today's prompt, plus the
        // eligibility context the client needs to decide whether to SHOW it.
        $stmt = $pdo->prepare(
            "SELECT u.is_keke_driver, u.private_pickup_enabled, u.is_online,
                    u.pickup_available_today, u.pickup_availability_date,
                    v.vehicle_type
             FROM users u
             LEFT JOIN vehicles v ON v.driver_id = u.id
             WHERE u.id = ?"
        );
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();

        $answeredToday = !empty($row['pickup_availability_date']) && $row['pickup_availability_date'] === $today;
        $availableToday = $answeredToday && !empty($row['pickup_available_today']);

        // Eligibility (base) tells the client if this driver could EVER do
        // private pickup - unverified drivers shouldn't see the prompt.
        $elig = check_driver_eligibility($pdo, $user_id, 'base');

        // Whether this driver's OWN vehicle class may run Private Pickup, decided
        // here from the persisted vehicles.vehicle_type via the same helper the
        // matching query and the operational gate use (private_pickup_vehicle_types
        // = keke + car). The client used to infer this itself from is_keke_driver
        // with a raw `vehicle_type !== 'car'` string fallback, which is a frontend
        // vehicle-type decision: it hid Private Pickup - and therefore today's
        // opt-in prompt, without which private_pickup_enabled is never set and the
        // driver can never be matched - from an eligible car driver whose stored
        // label was any accepted car synonym ('saloon', 'sedan', 'suv') rather than
        // the literal 'car'. Keke was never affected because is_keke_driver short
        // -circuited the test, so the lane behaved as keke-only for cars.
        $pickupVehicleEligible = is_private_pickup_vehicle_type($row['vehicle_type'] ?? null);

        json_response('success', 'ok', [
            'is_keke_driver'        => (int)($row['is_keke_driver'] ?? 0),
            'eligible_base'         => $elig['eligible'],
            'eligibility_reason'    => $elig['reason'],
            'pickup_vehicle_eligible' => $pickupVehicleEligible,
            'private_pickup_enabled'=> (int)($row['private_pickup_enabled'] ?? 0),
            'is_online'             => (int)($row['is_online'] ?? 0),
            'vehicle_type'          => $row['vehicle_type'] ?? null,
            'answered_today'        => $answeredToday,
            'available_today'       => $availableToday,
            'should_prompt'         => $elig['eligible'] && !$answeredToday,
        ]);
    }

    if ($action === 'set') {
        $available = isset($input['available']) ? (int)$input['available'] : null;
        if ($available === null || !in_array($available, [0, 1], true)) {
            json_response('error', 'Please answer yes or no.');
        }

        // Saying YES requires base eligibility (verified account + vehicle).
        if ($available === 1) {
            $elig = check_driver_eligibility($pdo, $user_id, 'base');
            if (!$elig['eligible']) {
                json_response('error', $elig['reason'], ['code' => $elig['code']]);
            }
        }

        if ($available === 1) {
            // Opt in for today: enable private pickup capability + stamp today.
            $stmt = $pdo->prepare(
                "UPDATE users SET private_pickup_enabled = 1, pickup_available_today = 1, pickup_availability_date = ? WHERE id = ?"
            );
            $stmt->execute([$today, $user_id]);
            json_response('success', 'You are set to receive Private Pickup requests today. Go online to start.', [
                'available_today' => 1,
                'private_pickup_enabled' => 1,
            ]);
        } else {
            // Opt out for today: also drop offline for private pickup so no
            // requests arrive. Join Ride stays fully functional.
            $stmt = $pdo->prepare(
                "UPDATE users SET pickup_available_today = 0, pickup_availability_date = ?, is_online = 0 WHERE id = ?"
            );
            $stmt->execute([$today, $user_id]);
            json_response('success', 'You will not receive Private Pickup requests today. Join Ride is unaffected.', [
                'available_today' => 0,
            ]);
        }
    }

    json_response('error', 'Unknown action');
} catch (Exception $e) {
    json_response('error', 'An unexpected error occurred.');
}
