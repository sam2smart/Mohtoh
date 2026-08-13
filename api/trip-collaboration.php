<?php
// api/trip-collaboration.php
// Intelligent Trip Engine — Phase 3 (Collaborative Driver & Passenger
// Intelligence). This is the explicit, user-initiated half of the
// engine — manual confirmations and driver no-show decisions — that
// complements the automatic GPS-driven detection added to
// api/update-location.php. Nothing here bypasses the existing payment
// gate: trip_state only ever reaches TRIP_COMPLETED (and therefore
// releases payment) through api/rideshare.php's existing complete_ride
// action, unchanged.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? $_GET['action'] ?? '';
$userType = $_SESSION['user_type'];
$userId = (int) $_SESSION['user_id'];

// Resolves the rider's own active booking, verifying ownership the
// same way every other rider-facing booking action in api/rideshare.php
// does (WHERE rider_id = session user).
function trip_collab_owned_booking($pdo, $bookingId, $riderId) {
    $stmt = $pdo->prepare("
        SELECT rb.*, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, rs.trip_state
        FROM ride_share_bookings rb JOIN ride_shares rs ON rs.id = rb.ride_share_id
        WHERE rb.id = ? AND rb.rider_id = ?
    ");
    $stmt->execute([$bookingId, $riderId]);
    return $stmt->fetch();
}

// Same, but scoped to a driver owning the ride the booking belongs to.
function trip_collab_driver_booking($pdo, $bookingId, $driverId) {
    $stmt = $pdo->prepare("
        SELECT rb.*, rs.driver_id, rs.trip_state, rs.price_per_passenger
        FROM ride_share_bookings rb JOIN ride_shares rs ON rs.id = rb.ride_share_id
        WHERE rb.id = ? AND rs.driver_id = ?
    ");
    $stmt->execute([$bookingId, $driverId]);
    return $stmt->fetch();
}

try {
    // -----------------------------------------------------------------
    // Rider actions — manual fallback for whenever GPS confidence is
    // too low to auto-transition (or the rider just prefers to confirm
    // by hand).
    // -----------------------------------------------------------------
    if ($action === 'set_navigating' && $userType === 'rider') {
        $booking = trip_collab_owned_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        if ($booking['passenger_state'] !== 'booked') {
            json_response('error', 'This booking has already moved past "booked".');
        }
        transition_passenger_state($pdo, $booking['id'], 'navigating', 'MANUAL_RIDER_ACTION', 100);
        json_response('success', 'You are now navigating to pickup.');
    }

    if ($action === 'confirm_arrival' && $userType === 'rider') {
        $booking = trip_collab_owned_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        if (!in_array($booking['passenger_state'], ['booked', 'navigating'], true)) {
            json_response('error', 'This booking is not awaiting arrival.');
        }
        transition_passenger_state($pdo, $booking['id'], 'arrived', 'MANUAL_RIDER_ACTION', 100, ['note' => 'manual confirmation (GPS confidence was insufficient or unavailable)']);
        json_response('success', 'Arrival confirmed.');
    }

    if ($action === 'confirm_boarded' && $userType === 'rider') {
        $booking = trip_collab_owned_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        if (!in_array($booking['passenger_state'], ['booked', 'navigating', 'arrived'], true)) {
            json_response('error', 'This booking is not awaiting boarding.');
        }
        transition_passenger_state($pdo, $booking['id'], 'boarded', 'MANUAL_RIDER_ACTION', 100);
        json_response('success', 'Boarding confirmed.');
    }

    if ($action === 'confirm_dropped_off' && $userType === 'rider') {
        $booking = trip_collab_owned_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        if ($booking['passenger_state'] !== 'boarded') {
            json_response('error', 'This booking is not awaiting drop-off.');
        }
        transition_passenger_state($pdo, $booking['id'], 'dropped_off', 'MANUAL_RIDER_ACTION', 100);
        mt_check_all_passengers_complete($pdo, $booking['ride_share_id']);
        json_response('success', 'Drop-off confirmed. Thanks for riding with MohToh!');
    }

    if ($action === 'get_live_status' && $userType === 'rider') {
        $stmt = $pdo->prepare("
            SELECT rb.id AS booking_id, rb.public_id AS booking_public_id, rb.passenger_state, rb.no_show_deadline,
                   rs.id AS ride_share_id, rs.trip_state, rs.destination, rs.start_location,
                   u.fullname AS driver_name, u.phone AS driver_phone, u.rating AS driver_rating,
                   v.make, v.model, v.plate_number
            FROM ride_share_bookings rb
            JOIN ride_shares rs ON rs.id = rb.ride_share_id
            JOIN users u ON u.id = rs.driver_id
            LEFT JOIN vehicles v ON v.driver_id = rs.driver_id
            WHERE rb.rider_id = ? AND rb.status IN ('confirmed', 'in_progress')
              AND rb.passenger_state NOT IN ('completed', 'no_show', 'cancelled')
            ORDER BY rb.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$userId]);
        $trip = $stmt->fetch();
        json_response('success', 'Live status fetched', ['trip' => $trip ?: null]);
    }

    // -----------------------------------------------------------------
    // Driver actions — manual override/confirmation for a specific
    // passenger, plus the three no-show decisions once a passenger's
    // countdown (set by mt_start_no_show_countdown()) has expired.
    // -----------------------------------------------------------------
    if ($action === 'confirm_passenger_boarded' && $userType === 'driver') {
        $booking = trip_collab_driver_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        transition_passenger_state($pdo, $booking['id'], 'boarded', 'MANUAL_DRIVER_ACTION', 100);
        json_response('success', 'Passenger marked as boarded.');
    }

    if ($action === 'confirm_passenger_dropped' && $userType === 'driver') {
        $booking = trip_collab_driver_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        transition_passenger_state($pdo, $booking['id'], 'dropped_off', 'MANUAL_DRIVER_ACTION', 100);
        mt_check_all_passengers_complete($pdo, $booking['ride_share_id']);
        json_response('success', 'Passenger marked as dropped off.');
    }

    if ($action === 'no_show_wait' && $userType === 'driver') {
        $booking = trip_collab_driver_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        $extendMinutes = (int) get_pricing_setting($pdo, 'no_show_timeout_minutes', 10);
        $pdo->prepare("UPDATE ride_share_bookings SET no_show_deadline = DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id = ?")
            ->execute([$extendMinutes, $booking['id']]);
        transition_passenger_state($pdo, $booking['id'], $booking['passenger_state'], 'NO_SHOW_WAIT', 100, ['extended_minutes' => $extendMinutes]);
        json_response('success', "Waiting {$extendMinutes} more minutes for this passenger.");
    }

    if ($action === 'no_show_skip' && $userType === 'driver') {
        $booking = trip_collab_driver_booking($pdo, (int)($input['booking_id'] ?? 0), $userId);
        if (!$booking) {
            json_response('error', 'Booking not found.');
        }
        // Skip: the passenger is recorded as a no-show but the booking
        // itself is left alone (no refund, seat not released) — this is
        // for a ride that's already moving on without them, not a
        // cancellation. mt_check_all_passengers_complete() treats
        // no_show the same as dropped_off/completed for the purposes of
        // "is this ride ready to wrap up".
        transition_passenger_state($pdo, $booking['id'], 'no_show', 'NO_SHOW_SKIP', 100);
        mt_check_all_passengers_complete($pdo, $booking['ride_share_id']);
        json_response('success', 'Passenger skipped as a no-show.');
    }

    if ($action === 'no_show_cancel' && $userType === 'driver') {
        $bookingId = (int)($input['booking_id'] ?? 0);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("
                SELECT rb.*, rs.driver_id, rs.price_per_passenger
                FROM ride_share_bookings rb JOIN ride_shares rs ON rs.id = rb.ride_share_id
                WHERE rb.id = ? AND rs.driver_id = ? AND rb.status NOT IN ('cancelled', 'completed') FOR UPDATE
            ");
            $stmt->execute([$bookingId, $userId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new Exception('Booking not found.');
            }

            // Cancel: same refund fairness as a rider-initiated
            // cancellation (api/rideshare.php's cancel_booking) — the
            // passenger didn't show, but if they already paid by
            // wallet, MohToh still refunds them; only the seat and
            // booking status actually change here.
            $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled' WHERE id = ?")->execute([$bookingId]);
            transition_passenger_state($pdo, $bookingId, 'no_show', 'NO_SHOW_CANCEL', 100);

            $pdo->prepare("UPDATE ride_shares SET available_seats = available_seats + ?, status = 'active' WHERE id = ?")
                ->execute([(int)$booking['seats_booked'], $booking['ride_share_id']]);

            $refund_amount = (float)$booking['price_per_passenger'] * (int)$booking['seats_booked'];
            if ($booking['payment_method'] === 'wallet' && $refund_amount > 0) {
                credit_wallet($pdo, $booking['rider_id'], $refund_amount, 'ride_refund', "Refund for no-show booking of ride #{$booking['ride_share_id']}", $booking['ride_share_id'], $bookingId);
            } else {
                $pdo->prepare("UPDATE payments SET status = 'PAYMENT_FAILED', updated_at = NOW() WHERE booking_id = ? AND status = 'PAYMENT_PENDING'")->execute([$bookingId]);
            }

            mt_check_all_passengers_complete($pdo, $booking['ride_share_id']);
            $pdo->commit();
            json_response('success', 'Passenger booking cancelled as a no-show.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    json_response('error', 'Invalid action.');
} catch (Exception $e) {
    json_response('error', 'Something went wrong: ' . $e->getMessage());
}
