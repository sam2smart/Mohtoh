<?php
// includes/booking.php
// Ride Queue extraction: the exact booking transaction that used to
// live only inline in api/rideshare.php's 'book' action — moved here,
// unchanged in behavior, so api/ride-queue.php's BOOK_NOW flow can
// reuse the SAME wallet checks, payment resolution, promo handling,
// commission snapshotting, seat decrement and notifications instead of
// a second, drifting copy. api/rideshare.php's 'book' action now just
// calls this and forwards the result — byte-identical response to
// before this refactor.
//
// Unlike the api/*.php action handlers this was extracted from,
// create_ride_booking() RETURNS ['status'=>, 'message'=>, 'data'=>]
// instead of calling json_response() (which exits) — the ride-queue
// book flow needs the new booking_id back so it can record it on the
// ride_waitlists row before responding to the client itself.
require_once __DIR__ . '/chat.php';
require_once __DIR__ . '/verification.php';

function create_ride_booking($pdo, $rider_id, $ride_share_id, $seats_booked, $requested_payment_method, $promo_code = '') {
    $seats_booked = max(1, (int)$seats_booked);
    if ($ride_share_id <= 0) {
        return ['status' => 'error', 'message' => 'Ride session is required'];
    }

    // Authentication & Recovery module: riders can't book until their
    // enabled verification methods are satisfied.
    $contactStmt = $pdo->prepare("SELECT email_verified, phone_verified FROM users WHERE id = ?");
    $contactStmt->execute([$rider_id]);
    $contact = $contactStmt->fetch();

    $emailEnabled = is_email_verification_enabled($pdo);
    $phoneEnabled = is_phone_verification_enabled($pdo);
    $emailBlocked = $emailEnabled && !$contact['email_verified'];
    $phoneBlocked = $phoneEnabled && !$contact['phone_verified'];

    if ($emailBlocked || $phoneBlocked) {
        return ['status' => 'error', 'message' => 'Please complete your required verification(s) before booking a ride.', 'data' => ['redirect' => '../verify-account.php']];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, total_seats, available_seats, status, price_per_passenger, driver_id, payment_preference, commission_amount_per_seat, driver_earning_per_seat, destination, ride_mode, vehicle_reserved FROM ride_shares WHERE id = ? FOR UPDATE");
        $stmt->execute([$ride_share_id]);
        $ride = $stmt->fetch();

        if (!$ride) {
            throw new Exception('Ride session not found');
        }

        if ($ride['ride_mode'] === 'private' || $ride['vehicle_reserved']) {
            throw new Exception('This vehicle is already reserved for a private ride and cannot accept more passengers.');
        }

        // Resolve the actual payment method for THIS booking against
        // what the driver accepts on this ride. 'both' lets the rider
        // choose; 'wallet'/'direct' force that single method
        // regardless of what the client sent.
        if ($ride['payment_preference'] === 'both') {
            if ($requested_payment_method !== 'wallet' && $requested_payment_method !== 'direct') {
                throw new Exception('Please choose how you want to pay: wallet or direct to driver.');
            }
            $payment_method = $requested_payment_method;
        } else {
            $payment_method = $ride['payment_preference'];
        }

        $total_cost = (float)$ride['price_per_passenger'] * $seats_booked;

        // Section 6 (Promotions): validated and applied here, inside
        // the same transaction as the booking/payment insert. The
        // discount reduces what the rider is actually debited; the
        // ride's fare/commission/driver-earning snapshot from
        // creation time is untouched (the platform absorbs the
        // discount out of its own commission share, not the
        // driver's earnings). Only supported against wallet payment
        // — there's no way for this system to discount a direct
        // cash/transfer payment made straight to the driver.
        $promo_code = trim((string)$promo_code);
        $promo = null;
        $discount_amount = 0.0;
        if ($promo_code !== '') {
            if ($payment_method !== 'wallet') {
                throw new Exception('Promo codes can only be applied to wallet payments.');
            }

            $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE code = ? AND is_active = 1 FOR UPDATE");
            $stmt->execute([$promo_code]);
            $promo = $stmt->fetch();
            if (!$promo) {
                throw new Exception('This promo code is not valid.');
            }
            if ($promo['valid_from'] && $promo['valid_from'] > date('Y-m-d')) {
                throw new Exception('This promo code is not active yet.');
            }
            if ($promo['valid_until'] && $promo['valid_until'] < date('Y-m-d')) {
                throw new Exception('This promo code has expired.');
            }
            if ($promo['max_uses'] !== null) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ?");
                $stmt->execute([$promo['id']]);
                if ((int)$stmt->fetchColumn() >= (int)$promo['max_uses']) {
                    throw new Exception('This promo code has reached its usage limit.');
                }
            }
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM promo_redemptions WHERE promo_id = ? AND user_id = ?");
            $stmt->execute([$promo['id'], $rider_id]);
            if ((int)$stmt->fetchColumn() >= (int)$promo['per_user_limit']) {
                throw new Exception('You have already used this promo code.');
            }
            if ($promo['applies_to'] === 'first_ride') {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE rider_id = ? AND status != 'cancelled'");
                $stmt->execute([$rider_id]);
                if ((int)$stmt->fetchColumn() > 0) {
                    throw new Exception('This promo code is only valid on your first ride.');
                }
            }

            $discount_amount = $promo['type'] === 'percentage'
                ? round($total_cost * (float)$promo['value'] / 100, 2)
                : (float)$promo['value'];
            $discount_amount = min($discount_amount, $total_cost);
        }

        $amount_to_charge = round($total_cost - $discount_amount, 2);

        if ($payment_method === 'wallet') {
            $newBalance = debit_wallet($pdo, $rider_id, $amount_to_charge, 'ride_payment', "Payment for ride booking #{$ride_share_id}", $ride_share_id, null);
            if ($newBalance === false) {
                throw new Exception('Insufficient wallet balance. Please fund your wallet to book this ride.');
            }
        }

        if ($ride['status'] !== 'active') {
            throw new Exception('This ride session is no longer available');
        }

        if ($seats_booked > (int)$ride['available_seats']) {
            throw new Exception('Not enough seats available');
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE ride_share_id = ? AND rider_id = ? AND status != 'cancelled'");
        $stmt->execute([$ride_share_id, $rider_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new Exception('You have already joined this ride');
        }

        $booking_public_id = generate_unique_public_id($pdo, 'ride_share_bookings', 'public_id', 'BKG');
        $stmt = $pdo->prepare("INSERT INTO ride_share_bookings (public_id, ride_share_id, rider_id, seats_booked, payment_method) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$booking_public_id, $ride_share_id, $rider_id, $seats_booked, $payment_method]);
        // Captured before commit(): PDO::lastInsertId() returns 0 in
        // this environment if called after an explicit commit().
        $newBookingId = $pdo->lastInsertId();

        if ($promo) {
            $stmt = $pdo->prepare("INSERT INTO promo_redemptions (promo_id, user_id, booking_id, applied_amount) VALUES (?, ?, ?, ?)");
            $stmt->execute([$promo['id'], $rider_id, $newBookingId, $discount_amount]);
        }

        $commission_amount = round((float)$ride['commission_amount_per_seat'] * $seats_booked, 2);
        $driver_earning_amount = round((float)$ride['driver_earning_per_seat'] * $seats_booked, 2);
        $paymentStatus = $payment_method === 'wallet' ? 'PAYMENT_CONFIRMED' : 'PAYMENT_PENDING';
        $payment_public_id = generate_unique_public_id($pdo, 'payments', 'public_id', 'PAY');
        $stmt = $pdo->prepare("INSERT INTO payments (public_id, ride_share_id, booking_id, rider_id, driver_id, fare_amount, commission_amount, driver_earning_amount, payment_method, status, confirmed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, " . ($payment_method === 'wallet' ? 'NOW()' : 'NULL') . ")");
        $stmt->execute([$payment_public_id, $ride_share_id, $newBookingId, $rider_id, $ride['driver_id'], $total_cost, $commission_amount, $driver_earning_amount, $payment_method, $paymentStatus]);

        $new_available_seats = (int)$ride['available_seats'] - $seats_booked;
        $newStatus = $new_available_seats <= 0 ? 'full' : 'active';
        $stmt = $pdo->prepare("UPDATE ride_shares SET available_seats = ?, status = ? WHERE id = ?");
        $stmt->execute([$new_available_seats, $newStatus, $ride_share_id]);

        // If private, reserve the vehicle
        if ($ride['ride_mode'] === 'private') {
            $stmt = $pdo->prepare("UPDATE ride_shares SET vehicle_reserved = 1 WHERE id = ?");
            $stmt->execute([$ride_share_id]);
        }

        $pdo->commit();

        // First booking on this ride moves it from "published, no
        // one aboard yet" to "passengers joining" — system-triggered,
        // not something the driver or rider explicitly asked for.
        if ((int)$ride['available_seats'] === (int)$ride['total_seats']) {
            transition_trip_state($pdo, $ride_share_id, 'PASSENGERS_JOINING', 'system');
        }

        // Notification Engine handles the notify_on_booking preference
        // check itself — no per-call-site SELECT.
        $driver_id = $ride['driver_id'];
        if ($driver_id) {
            create_notification($pdo, $driver_id, [
                'title' => 'New passenger joined',
                'message' => "A new passenger booked {$seats_booked} seat(s) on your ride to {$ride['destination']}.",
                'notification_type' => 'booking',
                'action' => 'open_trip', 'action_id' => $ride_share_id, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);
        }

        // Bookings are auto-confirmed (no manual accept/reject step
        // exists), so "confirmed" is the driver-accepted moment from
        // the rider's point of view.
        create_notification($pdo, $rider_id, [
            'title' => 'Seat confirmed',
            'message' => 'Driver accepted your ride — your seat is confirmed!',
            'notification_type' => 'booking',
            'priority' => 'high', // "driver accepted ride" — Priority Management System spec
            'action' => 'open_trip', 'action_id' => $ride_share_id, 'related_table' => 'ride_shares', 'link' => 'trips.php',
        ]);

        // Chat: attach this rider to the ride's group conversation
        // (created on the very first booking) and post one system
        // message covering both "driver accepted" and "passenger
        // joined" — bookings are auto-confirmed (see the comment
        // above), so there's no separate accept step to represent as
        // a second, fabricated event.
        $chatConversationId = add_rider_to_ride_conversation($pdo, $ride_share_id, $rider_id);
        post_system_message($pdo, $chatConversationId, ($_SESSION['fullname'] ?? 'A passenger') . ' joined the ride.');

        // Ride Requests (SYSTEM 1): if this booking is the rider actually
        // taking a ride the matching engine flagged against one of their
        // own open requests, that request is now genuinely fulfilled —
        // demand satisfied, not just cancelled. Only closes a request
        // this exact ride was already matched to, never an unrelated
        // open request the rider happens to also have.
        $matchedRequestStmt = $pdo->prepare("SELECT public_id FROM ride_demand_requests WHERE user_id = ? AND matched_ride_id = ? AND status IN ('OPEN', 'MATCHED')");
        $matchedRequestStmt->execute([$rider_id, $ride_share_id]);
        $matchedRequestPublicId = $matchedRequestStmt->fetchColumn();
        if ($matchedRequestPublicId) {
            require_once __DIR__ . '/ride_requests.php';
            complete_ride_request($pdo, $matchedRequestPublicId);
        }

        return ['status' => 'success', 'message' => 'Ride seat booked successfully', 'data' => [
            'remaining_seats' => $new_available_seats,
            'booking_id' => $newBookingId,
            'booking_public_id' => $booking_public_id,
            'payment_method' => $payment_method,
            'fare_amount' => $total_cost,
            'discount_amount' => $discount_amount,
            'amount_charged' => $amount_to_charge,
        ]];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
