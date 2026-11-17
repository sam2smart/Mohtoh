<?php
// api/update-location.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);
require_csrf($input);

$latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
$longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;

if ($latitude === null || $longitude === null) {
    json_response('error', 'Latitude and longitude are required');
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// 1. Update user's current location in DB
$stmt = $pdo->prepare("UPDATE users SET latitude = ?, longitude = ?, last_location_update = NOW() WHERE id = ?");
if (!$stmt->execute([$latitude, $longitude, $user_id])) {
    json_response('error', 'Failed to update location');
}

// 2. Intelligent Trip Engine — automatic detection. This is the only
// place "automatic" state detection can run on this stack (no
// background worker exists), so it evaluates opportunistically
// whenever a location ping arrives from a driver's watchPosition.
//
// Scoped to driver-side signals only (booking existence, driver
// geofence, driver speed) — this app has no passenger-side location
// tracking yet, so "passenger in geofence" / "passenger leaves
// vehicle" conditions are approximated by the driver's own movement
// pattern, not independently confirmed. Every automatic event records
// this plainly in its meta so it's never mistaken for a
// passenger-confirmed signal later.
//
// TRIP_STARTED and the final TRIP_COMPLETED payout remain gated
// behind a driver action (start_ride / complete_ride) — GPS can only
// stage AUTO_COMPLETED_PENDING, never release payment on its own.
$proximity_triggered = false;
$new_trip_state = null;
define('MOHTOH_GEOFENCE_RADIUS_METERS', 150);
define('MOHTOH_MOVEMENT_SPEED_KMH', 8);   // "sustained movement" threshold
define('MOHTOH_STOPPED_SPEED_KMH', 3);    // "vehicle stopped" threshold (allows GPS jitter)

if ($user_type === 'driver') {
    // seconds_elapsed is computed by MySQL itself (TIMESTAMPDIFF), not
    // PHP's time() against a MySQL-stored timestamp string — this box
    // runs PHP and MySQL in different timezones (PHP: Europe/Berlin,
    // MySQL: SYSTEM), so time() - strtotime(NOW()-string) silently
    // picks up the zone offset as a phantom ~1hr gap, which made every
    // speed reading compute near-zero and auto-start/drop-off never fire.
    $stmt = $pdo->prepare("
        SELECT id, trip_state, pickup_lat, pickup_lng, destination_lat, destination_lng,
               current_latitude, current_longitude, last_speed, last_location_update,
               TIMESTAMPDIFF(SECOND, last_location_update, NOW()) AS seconds_since_last_update
        FROM ride_shares
        WHERE driver_id = ? AND trip_state IN ('PASSENGERS_JOINING', 'PASSENGERS_ASSEMBLED', 'TRIP_STARTED', 'PASSENGER_DROPPING')
        ORDER BY departure_date ASC, departure_time ASC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $activeRide = $stmt->fetch();

    if ($activeRide) {
        // Speed since the last ping stored on THIS ride (not the
        // driver's general users.last_location_update, which is
        // overwritten regardless of which ride — or none — is active).
        $previousSpeed = $activeRide['last_speed'] !== null ? (float)$activeRide['last_speed'] : null;
        $secondsElapsed = $activeRide['last_location_update']
            ? (int)$activeRide['seconds_since_last_update']
            : null;
        $newSpeed = calculate_speed_kmh(
            $activeRide['current_latitude'], $activeRide['current_longitude'],
            $latitude, $longitude, $secondsElapsed
        );

        // Refresh this ride's own telemetry snapshot on every ping,
        // regardless of whether a state transition fires below.
        $stmt = $pdo->prepare("UPDATE ride_shares SET current_latitude = ?, current_longitude = ?, last_speed = ?, last_location_update = NOW() WHERE id = ?");
        $stmt->execute([$latitude, $longitude, $newSpeed, $activeRide['id']]);

        // Phase 3 (Collaborative Intelligence) — cross-check every
        // not-yet-boarded passenger's last reported position against
        // this fresh driver position/speed. Additive: reads its own
        // state, never touches trip_state directly, no-ops when
        // conditions aren't met.
        mt_check_auto_boarding($pdo, $activeRide['id']);

        if ($activeRide['trip_state'] === 'PASSENGERS_JOINING' && $activeRide['pickup_lat']) {
            // Driver-in-geofence — same as Phase 1, moves a ride from
            // "published, filling seats" to "driver has reached pickup".
            $distance = calculate_distance_meters($latitude, $longitude, $activeRide['pickup_lat'], $activeRide['pickup_lng']);
            if ($distance !== null && $distance <= MOHTOH_GEOFENCE_RADIUS_METERS) {
                // transition_trip_state()'s dispatch_trip_state_notification()
                // notifies both the driver and every booked rider on
                // PASSENGERS_ASSEMBLED — Notification Engine, not a
                // per-call-site loop (that would duplicate it).
                transition_trip_state($pdo, $activeRide['id'], 'PASSENGERS_ASSEMBLED', 'geofence', ['distance_meters' => round($distance, 1)]);
                // Phase 3: driver has reached pickup — start the
                // no-show countdown for whichever passengers haven't
                // arrived yet. Purely additive to the existing geofence
                // transition above; never blocks or alters it.
                mt_start_no_show_countdown($pdo, $activeRide['id']);

                $proximity_triggered = true;
                $new_trip_state = 'PASSENGERS_ASSEMBLED';
            }
        } elseif ($activeRide['trip_state'] === 'PASSENGERS_ASSEMBLED') {
            // Automatic trip start: sustained movement (two consecutive
            // pings above the movement threshold) while parked at pickup
            // with passengers assembled. Phase 3 extends this with one
            // more requirement — at least one passenger must already be
            // confirmed onboard — so a driver simply driving off without
            // anyone in the car no longer auto-starts the trip. Manual
            // start_ride is unaffected and always works regardless.
            if ($previousSpeed !== null && $previousSpeed >= MOHTOH_MOVEMENT_SPEED_KMH
                && $newSpeed !== null && $newSpeed >= MOHTOH_MOVEMENT_SPEED_KMH
                && mt_ride_has_boarded_passenger($pdo, $activeRide['id'])) {
                $started = mark_ride_started($pdo, $activeRide['id'], $user_id, 'SYSTEM_GPS_START', [
                    'previous_speed_kmh' => $previousSpeed,
                    'speed_kmh' => $newSpeed,
                    'note' => 'driver-signal only; passenger presence not independently confirmed'
                ]);
                if ($started) {
                    $proximity_triggered = true;
                    $new_trip_state = 'TRIP_STARTED';
                }
            }
        } elseif ($activeRide['trip_state'] === 'TRIP_STARTED' && $activeRide['destination_lat']) {
            // Arrival: within destination geofence and (this reading)
            // effectively stopped.
            $distance = calculate_distance_meters($latitude, $longitude, $activeRide['destination_lat'], $activeRide['destination_lng']);
            if ($distance !== null && $distance <= MOHTOH_GEOFENCE_RADIUS_METERS && $newSpeed !== null && $newSpeed <= MOHTOH_STOPPED_SPEED_KMH) {
                transition_trip_state($pdo, $activeRide['id'], 'PASSENGER_DROPPING', 'SYSTEM_GPS_DROP', [
                    'distance_meters' => round($distance, 1),
                    'speed_kmh' => $newSpeed,
                    'note' => 'driver-signal only; passenger egress not independently confirmed'
                ]);
                $proximity_triggered = true;
                $new_trip_state = 'PASSENGER_DROPPING';
            }
        } elseif ($activeRide['trip_state'] === 'PASSENGER_DROPPING' && $activeRide['destination_lat']) {
            // Dwell confirmation: a SECOND ping (the ride is already in
            // PASSENGER_DROPPING, so this state itself proves an earlier
            // ping already matched) still shows stopped at destination.
            // This only stages AUTO_COMPLETED_PENDING — it does not pay
            // the driver. complete_ride still has to be called to
            // release payment.
            $distance = calculate_distance_meters($latitude, $longitude, $activeRide['destination_lat'], $activeRide['destination_lng']);
            $withinGeofence = $distance !== null && $distance <= MOHTOH_GEOFENCE_RADIUS_METERS;
            $isStopped = $newSpeed !== null && $newSpeed <= MOHTOH_STOPPED_SPEED_KMH;
            if ($withinGeofence && $isStopped) {
                $confidence = calculate_completion_confidence($withinGeofence, $isStopped, true);
                transition_trip_state($pdo, $activeRide['id'], 'AUTO_COMPLETED_PENDING', 'SYSTEM_GPS_COMPLETE', [
                    'distance_meters' => round($distance, 1),
                    'speed_kmh' => $newSpeed,
                    'confidence' => $confidence
                ]);
                $pdo->prepare("UPDATE ride_shares SET completion_confidence = ? WHERE id = ?")->execute([$confidence, $activeRide['id']]);
                $proximity_triggered = true;
                $new_trip_state = 'AUTO_COMPLETED_PENDING';
            }
        }

        // Phase 3: an alternate, passenger-driven path to the same
        // AUTO_COMPLETED_PENDING staging state above — every booked
        // passenger may have independently reached dropped_off before
        // the driver's own GPS registers two stationary destination
        // pings. Still never completes the ride or releases payment.
        mt_check_all_passengers_complete($pdo, $activeRide['id']);
    }
} elseif ($user_type === 'rider') {
    // Phase 3 (Collaborative Intelligence) — the passenger side of the
    // same engine. Mirrors the driver branch above as closely as the
    // different signals allow: refresh this booking's own telemetry
    // every ping, then opportunistically auto-detect
    // navigating/arrived/dropped_off exactly like the driver branch
    // auto-detects PASSENGERS_ASSEMBLED/TRIP_STARTED/PASSENGER_DROPPING.
    // Low-confidence readings intentionally fall through without
    // transitioning — the rider app still exposes a manual confirm
    // action (api/trip-collaboration.php) for that case.
    $stmt = $pdo->prepare("
        SELECT rb.id AS booking_id, rb.ride_share_id, rb.passenger_state, rb.status,
               rb.passenger_latitude, rb.passenger_longitude,
               TIMESTAMPDIFF(SECOND, rb.passenger_last_location_update, NOW()) AS seconds_since_last_update,
               rs.trip_state, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng,
               TIMESTAMPDIFF(SECOND, rs.last_location_update, NOW()) AS driver_gps_age_seconds
        FROM ride_share_bookings rb
        JOIN ride_shares rs ON rs.id = rb.ride_share_id
        WHERE rb.rider_id = ? AND rb.status IN ('confirmed', 'in_progress')
          AND rb.passenger_state NOT IN ('dropped_off', 'completed', 'no_show', 'cancelled')
        ORDER BY rb.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $activeBooking = $stmt->fetch();

    if ($activeBooking) {
        $prevPassengerSpeed = null;
        $secondsElapsed = $activeBooking['passenger_latitude'] !== null ? (int)$activeBooking['seconds_since_last_update'] : null;
        if ($activeBooking['passenger_latitude'] !== null && $secondsElapsed !== null) {
            $prevPassengerSpeed = calculate_speed_kmh($activeBooking['passenger_latitude'], $activeBooking['passenger_longitude'], $latitude, $longitude, $secondsElapsed);
        }

        $accuracy = isset($input['accuracy']) ? (float)$input['accuracy'] : null;
        $stmt = $pdo->prepare("UPDATE ride_share_bookings SET passenger_latitude = ?, passenger_longitude = ?, passenger_last_speed = ?, passenger_gps_accuracy = ?, passenger_last_location_update = NOW() WHERE id = ?");
        $stmt->execute([$latitude, $longitude, $prevPassengerSpeed, $accuracy, $activeBooking['booking_id']]);

        // First-ever ping after booking: automatically report "navigating".
        if ($activeBooking['passenger_state'] === 'booked') {
            transition_passenger_state($pdo, $activeBooking['booking_id'], 'navigating', 'geofence', null, ['note' => 'first location ping received after booking']);
            $activeBooking['passenger_state'] = 'navigating';
        }

        if (in_array($activeBooking['passenger_state'], ['booked', 'navigating'], true) && $activeBooking['pickup_lat'] !== null) {
            $distance = calculate_distance_meters($latitude, $longitude, $activeBooking['pickup_lat'], $activeBooking['pickup_lng']);
            if ($distance !== null && $distance <= MOHTOH_GEOFENCE_RADIUS_METERS) {
                $confidence = calculate_collaborative_confidence([
                    'passenger_gps_age_seconds' => 0,
                    'driver_gps_age_seconds' => $activeBooking['driver_gps_age_seconds'],
                    'geofence_distance_meters' => $distance,
                    'gps_accuracy_meters' => $accuracy,
                    'booking_valid' => true,
                ]);
                if ($confidence['score'] >= 50) {
                    transition_passenger_state($pdo, $activeBooking['booking_id'], 'arrived', 'SYSTEM_GPS_ARRIVE', $confidence['score'], [
                        'distance_meters' => round($distance, 1),
                        'confidence_breakdown' => $confidence['breakdown'],
                    ]);
                    $proximity_triggered = true;
                    $new_trip_state = 'PASSENGER_ARRIVED';
                }
                mt_check_auto_boarding($pdo, $activeBooking['ride_share_id']);
            }
        } elseif ($activeBooking['passenger_state'] === 'boarded'
            && in_array($activeBooking['trip_state'], ['TRIP_STARTED', 'PASSENGER_DROPPING'], true)
            && $activeBooking['destination_lat'] !== null) {
            $distance = calculate_distance_meters($latitude, $longitude, $activeBooking['destination_lat'], $activeBooking['destination_lng']);
            if ($distance !== null && $distance <= MOHTOH_GEOFENCE_RADIUS_METERS) {
                $confidence = calculate_collaborative_confidence([
                    'passenger_gps_age_seconds' => 0,
                    'driver_gps_age_seconds' => $activeBooking['driver_gps_age_seconds'],
                    'geofence_distance_meters' => $distance,
                    'gps_accuracy_meters' => $accuracy,
                    'movement_confirmed' => $prevPassengerSpeed !== null && $prevPassengerSpeed <= MOHTOH_STOPPED_SPEED_KMH,
                    'booking_valid' => true,
                ]);
                if ($confidence['score'] >= 50) {
                    transition_passenger_state($pdo, $activeBooking['booking_id'], 'dropped_off', 'SYSTEM_GPS_DROP', $confidence['score'], [
                        'distance_meters' => round($distance, 1),
                        'confidence_breakdown' => $confidence['breakdown'],
                    ]);
                    mt_check_all_passengers_complete($pdo, $activeBooking['ride_share_id']);
                    $proximity_triggered = true;
                    $new_trip_state = 'PASSENGER_DROPPED_OFF';
                }
            }
        }
    }
}

json_response('success', 'Location updated', [
    'latitude' => $latitude,
    'longitude' => $longitude,
    'proximity_triggered' => $proximity_triggered,
    'new_status' => $new_trip_state
]);
?>
