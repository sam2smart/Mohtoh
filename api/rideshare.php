<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/google-maps.php'; // real trip distance/ETA (Distance Matrix, haversine fallback)
require_once __DIR__ . '/../includes/referral.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? 'list';

try {
    if ($action === 'price_preview' && $_SESSION['user_type'] === 'driver') {
        $pickup_lat = isset($input['pickup_lat']) ? (float)$input['pickup_lat'] : null;
        $pickup_lng = isset($input['pickup_lng']) ? (float)$input['pickup_lng'] : null;
        $destination_lat = isset($input['destination_lat']) ? (float)$input['destination_lat'] : null;
        $destination_lng = isset($input['destination_lng']) ? (float)$input['destination_lng'] : null;

        if (!$pickup_lat || !$pickup_lng || !$destination_lat || !$destination_lng) {
            json_response('error', 'Pickup and destination coordinates are required for a price preview.');
        }

        $vehicleStmt = $pdo->prepare("SELECT seats, status, vehicle_type FROM vehicles WHERE driver_id = ?");
        $vehicleStmt->execute([(int)$_SESSION['user_id']]);
        $vehicle = $vehicleStmt->fetch();
        if (!$vehicle || strtolower((string)$vehicle['status']) !== 'approved') {
            json_response('error', 'Your vehicle must be verified before pricing a trip.');
        }

        $total_seats = max(1, (int)$vehicle['seats']);
        $driver_id = (int)$_SESSION['user_id'];
        $distance_km = calculate_trip_distance_km($pdo, $pickup_lat, $pickup_lng, $destination_lat, $destination_lng);
        $pricing = calculate_recommended_seat_price($pdo, $distance_km, $total_seats, [
            'driver_id' => $driver_id,
            'pickup_lat' => $pickup_lat,
            'pickup_lng' => $pickup_lng,
            'departure_date' => $input['departure_date'] ?? null,
            'departure_time' => $input['departure_time'] ?? null,
            // Keke Join Ride is priced with the keke rate automatically when
            // the admin has configured one - the driver never has to pretend
            // to be a car driver to publish a trip.
            'vehicle_type' => $vehicle['vehicle_type'],
        ]);

        $commission_percentage = get_commission_percentage($pdo, $driver_id);
        $commission_amount_per_seat = round($pricing['recommended_price'] * $commission_percentage / 100, 2);
        $driver_earning_per_seat = round($pricing['recommended_price'] - $commission_amount_per_seat, 2);
        // "Maximum Trip Commission" — the reserve the driver must have
        // available before publishing (Wallet Reservation Logic).
        $maximum_trip_commission = round($commission_amount_per_seat * $total_seats, 2);

        $walletStmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $walletStmt->execute([$driver_id]);
        $walletBalance = (float)$walletStmt->fetchColumn();
        $availableBalance = get_available_wallet_balance($pdo, $driver_id);

        // Display-only bounds for pricing_mode = 'driver' — already
        // per-passenger (calculate_recommended_seat_price() applies them
        // directly to the fare, no seat division). Surfaced here so the
        // UI can show the right range before the driver ever types a
        // price; resolve_price_per_passenger() is still the actual
        // server-side validation on submit, not this.
        $minimumFare = (float) get_pricing_setting($pdo, 'minimum_fare', 0);
        $maximumFare = (float) get_pricing_setting($pdo, 'maximum_fare', 0);

        json_response('success', 'Price preview calculated', [
            'distance_km' => $distance_km,
            'total_seats' => $total_seats,
            'recommended_price' => $pricing['recommended_price'],
            'min_price' => $pricing['min_price'],
            'max_price' => $pricing['max_price'],
            'minimum_fare_per_seat' => $minimumFare,
            'maximum_fare_per_seat' => $maximumFare,
            'breakdown' => $pricing['breakdown'],
            'pricing_mode' => (string) get_pricing_setting($pdo, 'pricing_mode', 'hybrid'),
            'commission_percentage' => $commission_percentage,
            'driver_earning_per_seat' => $driver_earning_per_seat,
            // Gross (fare × seats, before commission) vs net (after
            // commission) — the task's "Potential Revenue" and "Driver
            // Expected Earnings" are deliberately two different numbers.
            'potential_revenue' => $pricing['potential_revenue'],
            'estimated_earnings_if_full' => round($driver_earning_per_seat * $total_seats, 2),
            'maximum_trip_commission' => $maximum_trip_commission,
            'required_wallet_reserve' => $maximum_trip_commission,
            'wallet_balance' => $walletBalance,
            'available_balance' => $availableBalance,
            'wallet_sufficient' => $availableBalance >= $maximum_trip_commission
        ]);
    }

    if ($action === 'create' && $_SESSION['user_type'] === 'driver') {
        $required = ['pickup_location','destination','departure_date','departure_time'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                json_response('error', ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        $driver_id = (int)$_SESSION['user_id'];

        $vehicleStmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
        $vehicleStmt->execute([$driver_id]);
        $vehicle = $vehicleStmt->fetch();
        if (!$vehicle) {
            json_response('error', 'Your vehicle must be verified before creating rides.');
        }
        if (strtolower((string)$vehicle['status']) !== 'approved') {
            $message = strtolower((string)$vehicle['status']) === 'rejected'
                ? 'Your vehicle was rejected. Please update the vehicle verification details before creating rides.'
                : 'Your vehicle must be verified before creating rides.';
            json_response('error', $message);
        }

        // Two independent conditions gate ride creation: the vehicle
        // being approved (checked above) AND the driver account itself
        // being verified. In normal operation these move together (an
        // admin approving a vehicle also verifies the account — see
        // admin-api.php), but this is checked explicitly and separately
        // so a driver can never publish a ride from an account that
        // isn't verified, regardless of vehicle state.
        $verifiedStmt = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
        $verifiedStmt->execute([$driver_id]);
        if (!$verifiedStmt->fetchColumn()) {
            json_response('error', 'Your driver account is not yet verified. This is usually resolved automatically once your vehicle is approved.');
        }

        // Authentication & Recovery module: a third, independent gate —
        // vehicle approval and driver-account verification (above) say
        // nothing about whether this driver actually owns the email/phone
        // they registered with. Ride creation stays blocked until both
        // enabled verification methods are satisfied.
        $contactStmt = $pdo->prepare("SELECT email_verified, phone_verified FROM users WHERE id = ?");
        $contactStmt->execute([$driver_id]);
        $contact = $contactStmt->fetch();

        $emailEnabled = is_email_verification_enabled($pdo);
        $phoneEnabled = is_phone_verification_enabled($pdo);
        $emailBlocked = $emailEnabled && !$contact['email_verified'];
        $phoneBlocked = $phoneEnabled && !$contact['phone_verified'];

        if ($emailBlocked || $phoneBlocked) {
            json_response('error', 'Please complete your required verification(s) before creating rides.', ['redirect' => '../verify-account.php']);
        }

        $start_location = sanitize_input(trim($input['pickup_location'] ?? $input['start_location'] ?? ''));
        $destination = sanitize_input(trim($input['destination']));
        $departure_date = sanitize_input(trim($input['departure_date']));
        $departure_time = sanitize_input(trim($input['departure_time']));
        $pickup_lat = isset($input['pickup_lat']) && trim((string)$input['pickup_lat']) !== '' ? (float)$input['pickup_lat'] : null;
        $pickup_lng = isset($input['pickup_lng']) && trim((string)$input['pickup_lng']) !== '' ? (float)$input['pickup_lng'] : null;
        $destination_lat = isset($input['destination_lat']) && trim((string)$input['destination_lat']) !== '' ? (float)$input['destination_lat'] : null;
        $destination_lng = isset($input['destination_lng']) && trim((string)$input['destination_lng']) !== '' ? (float)$input['destination_lng'] : null;

        if (!$pickup_lat || !$pickup_lng || !$destination_lat || !$destination_lng) {
            json_response('error', 'Pickup and destination must be selected from the address suggestions so the trip distance and price can be calculated.');
        }

        $vehicle_id = $vehicle['id'];
        $vehicle_type = $vehicle['make'];
        $vehicle_info = 'Model: ' . $vehicle['model'] . '; Colour: ' . $vehicle['color'] . '; Plate: ' . $vehicle['plate_number'];

        // Seats are never taken from client input — they come from the
        // driver's own approved vehicle record.
        $total_seats = max(1, (int)$vehicle['seats']);
        $available_seats = $total_seats;
        $description = sanitize_input(trim($input['description'] ?? ''));

        // Section 2 (Driver Settings): min wallet balance / max active rides
        // are checked here, right alongside the existing wallet-reserve
        // check below — same code path, not a separate gate.
        $minWalletBalance = (float) get_pricing_setting($pdo, 'min_wallet_balance', 0);
        $maxActiveRides = (int) get_pricing_setting($pdo, 'max_active_rides_per_driver', 0);

        // Premium drivers are exempt from the standard active-rides cap
        // (Phase 3 driver tier) — everyone else still goes through the
        // exact same check that already existed here.
        $stmt = $pdo->prepare("SELECT driver_tier FROM users WHERE id = ?");
        $stmt->execute([$driver_id]);
        $isPremiumDriver = $stmt->fetchColumn() === 'premium';

        if ($maxActiveRides > 0 && !$isPremiumDriver) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_shares WHERE driver_id = ? AND status IN ('active', 'full', 'in_progress')");
            $stmt->execute([$driver_id]);
            if ((int)$stmt->fetchColumn() >= $maxActiveRides) {
                json_response('error', "You already have the maximum of {$maxActiveRides} active ride(s). Complete or cancel one before creating another.");
            }
        }

        $distance_km = calculate_trip_distance_km($pdo, $pickup_lat, $pickup_lng, $destination_lat, $destination_lng);
        $pricing = calculate_recommended_seat_price($pdo, $distance_km, $total_seats, [
            'driver_id' => $driver_id,
            'pickup_lat' => $pickup_lat,
            'pickup_lng' => $pickup_lng,
            'departure_date' => $departure_date,
            'departure_time' => $departure_time,
            'vehicle_type' => $vehicle['vehicle_type'],
        ]);

        // Section 4 (Driver Override): pricing_mode branches how a
        // driver-supplied price_per_passenger is treated. Default
        // 'hybrid' reproduces the original band-check behavior exactly.
        try {
            $price_per_passenger = resolve_price_per_passenger($pdo, $pricing, $input['price_per_passenger'] ?? null, $total_seats);
        } catch (RuntimeException $e) {
            json_response('error', $e->getMessage());
        }

        $commission_percentage = get_commission_percentage($pdo, $driver_id);
        $commission_amount_per_seat = round($price_per_passenger * $commission_percentage / 100, 2);
        $driver_earning_per_seat = round($price_per_passenger - $commission_amount_per_seat, 2);
        $required_wallet_reserve = round($commission_amount_per_seat * $total_seats, 2);

        $walletStmt = $pdo->prepare("SELECT wallet_balance, payment_preference FROM users WHERE id = ?");
        $walletStmt->execute([$driver_id]);
        $driverRow = $walletStmt->fetch();
        $walletBalance = (float)$driverRow['wallet_balance'];
        // Snapshotted onto the ride at creation — same reasoning as
        // commission_percentage: a later change to the driver's global
        // preference must never retroactively affect a ride already
        // published and being booked against.
        $ride_payment_preference = $driverRow['payment_preference'] ?: 'wallet';

        // Commission Reservation System: solvency is checked against
        // available balance (wallet_balance minus what's already locked
        // by this driver's other active reservations), not the raw
        // column — otherwise the same naira could pass this check for
        // two rides created back-to-back.
        $availableBalance = get_available_wallet_balance($pdo, $driver_id);
        if ($availableBalance < $required_wallet_reserve) {
            json_response('error', "Your wallet requires ₦{$required_wallet_reserve} commission reserve before publishing this trip. Please top up before publishing.", [
                'required_wallet_reserve' => $required_wallet_reserve,
                'available_balance' => $availableBalance,
                'wallet_balance' => $walletBalance
            ]);
        }
        if ($minWalletBalance > 0 && $availableBalance < $minWalletBalance) {
            json_response('error', "Your wallet balance must be at least ₦{$minWalletBalance} to accept rides.", [
                'min_wallet_balance' => $minWalletBalance,
                'available_balance' => $availableBalance
            ]);
        }

        $fare_breakdown = json_encode($pricing['breakdown']);
        // Snapshotted for the same reason as commission_percentage and
        // fare_breakdown above: the rider-facing pricing badge must
        // keep showing the mode this ride was actually published
        // under, unaffected by the admin changing the global setting later.
        $ride_pricing_mode = (string) get_pricing_setting($pdo, 'pricing_mode', 'hybrid');

        $pdo->beginTransaction();
        try {
            $ride_public_id = generate_unique_public_id($pdo, 'ride_shares', 'public_id', 'RIDE');
            $stmt = $pdo->prepare("INSERT INTO ride_shares (public_id, driver_id, pickup_location, start_location, destination, departure_date, departure_time, vehicle_id, vehicle_type, vehicle_info, total_seats, available_seats, price_per_passenger, pricing_mode, commission_percentage, commission_amount_per_seat, driver_earning_per_seat, description, pickup_lat, pickup_lng, destination_lat, destination_lng, payment_preference, fare_breakdown, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $ride_public_id,
                $driver_id,
                $start_location,
                $start_location,
                $destination,
                $departure_date,
                $departure_time,
                $vehicle_id,
                $vehicle_type,
                $vehicle_info,
                $total_seats,
                $available_seats,
                $price_per_passenger,
                $ride_pricing_mode,
                $commission_percentage,
                $commission_amount_per_seat,
                $driver_earning_per_seat,
                $description,
                $pickup_lat,
                $pickup_lng,
                $destination_lat,
                $destination_lng,
                $ride_payment_preference,
                $fare_breakdown
            ]);

            // Capture the ID before commit(): in this environment,
            // PDO::lastInsertId() returns 0 if called after an explicit
            // commit() rather than immediately after execute().
            $newRideId = $pdo->lastInsertId();
            $pdo->commit();
            transition_trip_state($pdo, $newRideId, 'PUBLISHED', 'MANUAL_DRIVER_ACTION');
            create_commission_reservation($pdo, $driver_id, $newRideId, $required_wallet_reserve);
            track_referral_event($pdo, $driver_id, 'FIRST_RIDE_CREATED');

            // Ride Requests matching engine (SYSTEM 1): a newly-published
            // ride may satisfy open passenger demand — flag any matching
            // requests and notify their owners. Never books anyone; the
            // passenger decides. Runs after commit() so a failure here
            // can never roll back the ride itself.
            require_once '../includes/ride_requests.php';
            match_ride_requests($pdo, $newRideId);
            json_response('success', 'Ride sharing session created successfully', [
                'ride_share_id' => $newRideId,
                'ride_public_id' => $ride_public_id,
                'price_per_passenger' => $price_per_passenger,
                'total_seats' => $total_seats,
                'commission_amount_per_seat' => $commission_amount_per_seat,
                'driver_earning_per_seat' => $driver_earning_per_seat,
                'potential_revenue' => round($price_per_passenger * $total_seats, 2),
                'driver_expected_earnings' => round($driver_earning_per_seat * $total_seats, 2),
                'commission_reserved' => $required_wallet_reserve,
                'breakdown' => $pricing['breakdown']
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', 'Unable to create the trip right now.');
        }
    }

    if ($action === 'my_rides' && $_SESSION['user_type'] === 'driver') {
        $driver_id = (int)$_SESSION['user_id'];
        $today = date('Y-m-d');

        // ---------------------------------------------------------------
        // Lazy auto-expiry: cancel any rides for this driver whose
        // departure_date has already passed but are still active/full.
        // This mirrors the pattern used by ride_demand_requests — no cron
        // runner is needed; it fires opportunistically at read time.
        // ---------------------------------------------------------------
        $expiredRideStmt = $pdo->prepare("
            SELECT id, public_id, start_location, destination, price_per_passenger
            FROM ride_shares
            WHERE driver_id = ? AND status IN ('active', 'full') AND departure_date < ?
        ");
        $expiredRideStmt->execute([$driver_id, $today]);
        $expiredRides = $expiredRideStmt->fetchAll();

        foreach ($expiredRides as $expRide) {
            $expRideId = (int)$expRide['id'];
            try {
                $pdo->beginTransaction();

                // Cancel active bookings and refund wallet payments.
                $bStmt = $pdo->prepare("SELECT id, rider_id, seats_booked, payment_method FROM ride_share_bookings WHERE ride_share_id = ? AND status NOT IN ('cancelled', 'completed') FOR UPDATE");
                $bStmt->execute([$expRideId]);
                $bkgs = $bStmt->fetchAll();
                foreach ($bkgs as $bkg) {
                    if ($bkg['payment_method'] === 'wallet') {
                        $refund = (float)$expRide['price_per_passenger'] * (int)$bkg['seats_booked'];
                        // Only refund if a PAYMENT_CONFIRMED record exists (wallet was actually debited).
                        $pmtStmt = $pdo->prepare("SELECT id FROM payments WHERE booking_id = ? AND status = 'PAYMENT_CONFIRMED' LIMIT 1");
                        $pmtStmt->execute([$bkg['id']]);
                        if ($pmtStmt->fetchColumn()) {
                            require_once '../includes/wallet.php';
                            credit_wallet($pdo, (int)$bkg['rider_id'], $refund, 'ride_refund', "Refund: ride {$expRide['public_id']} auto-cancelled (departure date passed)");
                            $pdo->prepare("UPDATE payments SET status = 'REFUNDED' WHERE booking_id = ?")->execute([$bkg['id']]);
                        }
                    }
                    $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$bkg['id']]);
                    create_notification($pdo, (int)$bkg['rider_id'], [
                        'title' => 'Ride cancelled — departure date passed',
                        'message' => "The ride from {$expRide['start_location']} to {$expRide['destination']} was automatically cancelled because the departure date has passed." . ($bkg['payment_method'] === 'wallet' ? ' Your payment has been refunded.' : ''),
                        'notification_type' => 'ride',
                    ]);
                }

                // Release commission reservation (same function used by the manual cancel action).
                release_ride_reservation($pdo, $expRideId);

                // Mark ride cancelled and record the state transition.
                $pdo->prepare("UPDATE ride_shares SET status = 'cancelled', updated_at = NOW() WHERE id = ?")->execute([$expRideId]);
                transition_trip_state($pdo, $expRideId, 'CANCELLED', 'AUTO_EXPIRED');

                // Reset any MATCHED passenger demand requests.
                $pdo->prepare("UPDATE ride_demand_requests SET status = 'OPEN', matched_ride_id = NULL, updated_at = NOW() WHERE matched_ride_id = ? AND status = 'MATCHED'")->execute([$expRideId]);

                $pdo->commit();
            } catch (Exception $e) {
                $pdo->rollBack();
                // Non-fatal: stale ride stays as-is until next attempt.
            }
        }

        $stmt = $pdo->prepare("
            SELECT rs.id, rs.public_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.total_seats, rs.available_seats, rs.price_per_passenger, rs.description, rs.vehicle_type, rs.vehicle_info, rs.status, rs.trip_state, rs.ride_mode, rs.passenger_arrival_confirmed_at, rs.current_latitude, rs.current_longitude, rs.last_location_update, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng,
                   (SELECT COUNT(*) FROM ride_share_bookings rb WHERE rb.ride_share_id = rs.id AND rb.status != 'cancelled') AS passengers_joined,
                   (SELECT COUNT(*) FROM ride_waitlists rw WHERE rw.ride_share_id = rs.id AND rw.status IN ('WAITING', 'NOTIFIED')) AS waiting_count,
                   cr.reserved_amount, cr.used_amount, cr.released_amount, cr.status AS reservation_status
            FROM ride_shares rs
            LEFT JOIN ride_commission_reservations cr ON cr.ride_share_id = rs.id AND cr.status = 'active'
            WHERE rs.driver_id = ? ORDER BY rs.departure_date ASC, rs.departure_time ASC
        ");
        $stmt->execute([$driver_id]);
        $rides = $stmt->fetchAll();

        $stats = [
            'total_rides' => count($rides),
            'upcoming_rides' => 0,
            'completed_rides' => 0,
            'total_passengers' => 0,
        ];

        foreach ($rides as $ride) {
            if ($ride['status'] === 'completed') {
                $stats['completed_rides']++;
            } elseif ($ride['status'] !== 'cancelled' && $ride['departure_date'] >= $today) {
                $stats['upcoming_rides']++;
            }
            $stats['total_passengers'] += (int)$ride['passengers_joined'];
        }

        json_response('success', 'Driver rides fetched', ['stats' => $stats, 'rides' => $rides]);
    }

    if ($action === 'update' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $required = ['pickup_location','destination','departure_date','departure_time'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
                json_response('error', ucfirst(str_replace('_', ' ', $field)) . ' is required');
            }
        }

        $stmt = $pdo->prepare("SELECT available_seats, status FROM ride_shares WHERE id = ? AND driver_id = ?");
        $stmt->execute([$ride_id, $_SESSION['user_id']]);
        $existing = $stmt->fetch();
        if (!$existing) {
            json_response('error', 'Ride not found');
        }

        $booked_seats = 0;
        $stmt = $pdo->prepare("SELECT SUM(seats_booked) FROM ride_share_bookings WHERE ride_share_id = ? AND status != 'cancelled'");
        $stmt->execute([$ride_id]);
        $booked_seats = (int)$stmt->fetchColumn();

        $vehicleStmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ? AND status = 'approved'");
        $vehicleStmt->execute([$_SESSION['user_id']]);
        $vehicle = $vehicleStmt->fetch();
        if (!$vehicle) {
            json_response('error', 'Your approved vehicle was not found. Cannot update ride.');
        }

        // Seats are locked to the vehicle, same as create. If the car's
        // registered capacity is somehow now below what's already
        // booked (e.g. admin corrected it after bookings existed),
        // refuse rather than silently shrinking below booked seats.
        $total_seats = max(1, (int)$vehicle['seats']);
        if ($total_seats < $booked_seats) {
            json_response('error', 'Your vehicle seat capacity (' . $total_seats . ') is lower than seats already booked (' . $booked_seats . '). Contact support.');
        }

        $start_location = sanitize_input(trim($input['pickup_location'] ?? $input['start_location'] ?? ''));
        $destination = sanitize_input(trim($input['destination']));
        $departure_date = sanitize_input(trim($input['departure_date']));
        $departure_time = sanitize_input(trim($input['departure_time']));
        $pickup_lat = isset($input['pickup_lat']) && trim((string)$input['pickup_lat']) !== '' ? (float)$input['pickup_lat'] : null;
        $pickup_lng = isset($input['pickup_lng']) && trim((string)$input['pickup_lng']) !== '' ? (float)$input['pickup_lng'] : null;
        $destination_lat = isset($input['destination_lat']) && trim((string)$input['destination_lat']) !== '' ? (float)$input['destination_lat'] : null;
        $destination_lng = isset($input['destination_lng']) && trim((string)$input['destination_lng']) !== '' ? (float)$input['destination_lng'] : null;

        if (!$pickup_lat || !$pickup_lng || !$destination_lat || !$destination_lng) {
            json_response('error', 'Pickup and destination must be selected from the address suggestions so the trip distance and price can be calculated.');
        }

        $vehicle_type = $vehicle['make'];
        $vehicle_info = 'Model: ' . $vehicle['model'] . '; Colour: ' . $vehicle['color'] . '; Plate: ' . $vehicle['plate_number'];

        $driver_id = (int)$_SESSION['user_id'];
        $distance_km = calculate_trip_distance_km($pdo, $pickup_lat, $pickup_lng, $destination_lat, $destination_lng);
        $pricing = calculate_recommended_seat_price($pdo, $distance_km, $total_seats, [
            'driver_id' => $driver_id,
            'pickup_lat' => $pickup_lat,
            'pickup_lng' => $pickup_lng,
            'departure_date' => $departure_date,
            'departure_time' => $departure_time,
            'vehicle_type' => $vehicle['vehicle_type'],
        ]);

        try {
            $price_per_passenger = resolve_price_per_passenger($pdo, $pricing, $input['price_per_passenger'] ?? null, $total_seats);
        } catch (RuntimeException $e) {
            json_response('error', $e->getMessage());
        }

        $commission_percentage = get_commission_percentage($pdo, $driver_id);
        $commission_amount_per_seat = round($price_per_passenger * $commission_percentage / 100, 2);
        $driver_earning_per_seat = round($price_per_passenger - $commission_amount_per_seat, 2);
        $new_available_seats = $total_seats - $booked_seats;
        $required_wallet_reserve = round($commission_amount_per_seat * $total_seats, 2);

        $description = sanitize_input(trim($input['description'] ?? ''));
        $fare_breakdown = json_encode($pricing['breakdown']);
        $ride_pricing_mode = (string) get_pricing_setting($pdo, 'pricing_mode', 'hybrid');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE ride_shares SET pickup_location = ?, start_location = ?, destination = ?, departure_date = ?, departure_time = ?, vehicle_type = ?, vehicle_info = ?, total_seats = ?, available_seats = ?, price_per_passenger = ?, pricing_mode = ?, commission_percentage = ?, commission_amount_per_seat = ?, driver_earning_per_seat = ?, description = ?, pickup_lat = ?, pickup_lng = ?, destination_lat = ?, destination_lng = ?, fare_breakdown = ? WHERE id = ? AND driver_id = ?");
            $stmt->execute([$start_location, $start_location, $destination, $departure_date, $departure_time, $vehicle_type, $vehicle_info, $total_seats, $new_available_seats, $price_per_passenger, $ride_pricing_mode, $commission_percentage, $commission_amount_per_seat, $driver_earning_per_seat, $description, $pickup_lat, $pickup_lng, $destination_lat, $destination_lng, $fare_breakdown, $ride_id, $driver_id]);

            // Commission Reservation System: keep the ride's reservation
            // in sync with its (possibly changed) max-possible commission.
            // Rejects only if the change is an *increase* the driver's
            // available balance can't cover — a decrease always succeeds.
            if (!adjust_commission_reservation($pdo, $driver_id, $ride_id, $required_wallet_reserve)) {
                $pdo->rollBack();
                json_response('error', 'Your wallet balance is insufficient to cover this trip\'s increased commission. Please top up before saving these changes.', [
                    'required_wallet_reserve' => $required_wallet_reserve
                ]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', 'Unable to update the trip right now.');
        }

        json_response('success', 'Ride updated successfully', [
            'price_per_passenger' => $price_per_passenger,
            'total_seats' => $total_seats,
            'breakdown' => $pricing['breakdown']
        ]);
    }

    if ($action === 'cancel' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT price_per_passenger FROM ride_shares WHERE id = ? AND driver_id = ? FOR UPDATE");
            $stmt->execute([$ride_id, $_SESSION['user_id']]);
            $pricePerPassenger = $stmt->fetchColumn();
            if ($pricePerPassenger === false) {
                throw new Exception('Ride not found or you do not have permission to cancel it.');
            }
            $pricePerPassenger = (float)$pricePerPassenger;

            // Bug fix: this action used to cancel every booking without
            // ever refunding a wallet-paid rider — their fare stayed
            // debited with no reversing entry. Mirrors cancel_booking's
            // (rider self-cancel) exact refund logic: wallet payments are
            // credited back, pending direct payments are just closed out
            // since no money moved for those yet.
            $stmt = $pdo->prepare("SELECT id, rider_id, seats_booked, payment_method FROM ride_share_bookings WHERE ride_share_id = ? AND status NOT IN ('cancelled', 'completed') FOR UPDATE");
            $stmt->execute([$ride_id]);
            $activeBookings = $stmt->fetchAll();

            foreach ($activeBookings as $booking) {
                $refundAmount = $pricePerPassenger * (int)$booking['seats_booked'];
                if ($booking['payment_method'] === 'wallet' && $refundAmount > 0) {
                    credit_wallet($pdo, $booking['rider_id'], $refundAmount, 'ride_refund', "Refund for cancelled ride #{$ride_id}", $ride_id, $booking['id']);
                } else {
                    $stmt2 = $pdo->prepare("UPDATE payments SET status = 'PAYMENT_FAILED', updated_at = NOW() WHERE booking_id = ? AND status = 'PAYMENT_PENDING'");
                    $stmt2->execute([$booking['id']]);
                }
            }

            $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'cancelled' WHERE id = ? AND driver_id = ?");
            $stmt->execute([$ride_id, $_SESSION['user_id']]);
            $stmt = $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled', passenger_state = 'cancelled', passenger_state_updated_at = NOW() WHERE ride_share_id = ? AND status != 'cancelled'");
            $stmt->execute([$ride_id]);
            // transition_trip_state()'s dispatch_trip_state_notification()
            // notifies every affected rider on CANCELLED — Notification
            // Engine, not a per-call-site loop (that would duplicate it).
            transition_trip_state($pdo, $ride_id, 'CANCELLED', 'MANUAL_DRIVER_ACTION');
            release_ride_reservation($pdo, $ride_id);

            // Bug fix: Reset matched passenger ride requests back to OPEN
            // so they can be matched again by other drivers.
            $stmt = $pdo->prepare("UPDATE ride_demand_requests SET status = 'OPEN', matched_ride_id = NULL, updated_at = NOW() WHERE matched_ride_id = ? AND status = 'MATCHED'");
            $stmt->execute([$ride_id]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }

        // Ride Queue: the ride itself is gone, so nobody can
        // realistically join it — process_queue() detects the
        // 'cancelled' status and expires every active queue entry
        // (no notification sent, there's nothing to book).
        require_once '../includes/ride_queue.php';
        process_queue($pdo, $ride_id);

        json_response('success', 'Ride cancelled successfully');
    }

    if ($action === 'get_passengers' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($_GET['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $stmt = $pdo->prepare("SELECT id FROM ride_shares WHERE id = ? AND driver_id = ?");
        $stmt->execute([$ride_id, $_SESSION['user_id']]);
        if (!$stmt->fetch()) {
            json_response('error', 'Ride not found or you do not have permission to view its passengers.');
        }

        $stmt = $pdo->prepare("
            SELECT u.fullname, u.phone, u.profile_image, rb.id AS booking_id, rb.rider_id, rb.seats_booked, rb.status, rb.payment_method, p.status AS payment_status,
                   rb.passenger_state, rb.last_confidence_score, rb.no_show_deadline,
                   (rb.no_show_deadline IS NOT NULL AND rb.no_show_deadline < NOW() AND rb.passenger_state IN ('booked', 'navigating', 'arrived')) AS no_show_pending,
                   (SELECT prr.rating FROM ride_share_passenger_ratings prr WHERE prr.booking_id = rb.id AND prr.driver_id = ?) AS driver_rating
            FROM ride_share_bookings rb
            JOIN users u ON rb.rider_id = u.id
            LEFT JOIN payments p ON p.booking_id = rb.id
            WHERE rb.ride_share_id = ? AND rb.status NOT IN ('cancelled')
        ");
        $stmt->execute([$_SESSION['user_id'], $ride_id]);
        $passengers = $stmt->fetchAll();

        $summary = [
            'passenger_count' => count($passengers),
            'seats_filled' => array_sum(array_column($passengers, 'seats_booked')),
            'boarded_count' => count(array_filter($passengers, fn($p) => $p['passenger_state'] === 'boarded')),
            'remaining_count' => count(array_filter($passengers, fn($p) => !in_array($p['passenger_state'], ['boarded', 'dropped_off', 'completed', 'no_show'], true))),
        ];

        json_response('success', 'Passengers fetched', ['passengers' => $passengers, 'summary' => $summary]);
    }

    if ($action === 'get_earnings' && $_SESSION['user_type'] === 'driver') {
        $driver_id = (int)$_SESSION['user_id'];
    
        // Fetch completed rides for the driver
        $stmt = $pdo->prepare("
            SELECT 
                rs.id, 
                rs.destination, 
                rs.departure_date, 
                rs.price_per_passenger,
                (SELECT SUM(rb.seats_booked) FROM ride_share_bookings rb WHERE rb.ride_share_id = rs.id AND rb.status = 'completed') as total_seats_booked
            FROM ride_shares rs
            WHERE rs.driver_id = ? AND rs.status = 'completed'
            ORDER BY rs.departure_date DESC
        ");
        $stmt->execute([$driver_id]);
        $completed_rides = $stmt->fetchAll();
    
        $earnings_history = [];
        $total_earnings = 0;
        $this_month_earnings = 0;
        $this_week_earnings = 0;
        $total_completed_rides = count($completed_rides);
    
        $current_date = new DateTime();
        $start_of_week = (clone $current_date)->modify('monday this week');
        $start_of_month = (clone $current_date)->modify('first day of this month');
    
        foreach ($completed_rides as $ride) {
            $ride_earning = (float)$ride['price_per_passenger'] * (int)$ride['total_seats_booked'];
            $total_earnings += $ride_earning;
    
            $ride_date = new DateTime($ride['departure_date']);
    
            if ($ride_date >= $start_of_month) {
                $this_month_earnings += $ride_earning;
            }
            if ($ride_date >= $start_of_week) {
                $this_week_earnings += $ride_earning;
            }
    
            $earnings_history[] = ['ride_id' => $ride['id'], 'destination' => $ride['destination'], 'date' => $ride['departure_date'], 'seats_booked' => (int)$ride['total_seats_booked'], 'price_per_seat' => (float)$ride['price_per_passenger'], 'earning' => $ride_earning];
        }
    
        $summary = ['total_earnings' => $total_earnings, 'this_month_earnings' => $this_month_earnings, 'this_week_earnings' => $this_week_earnings, 'total_completed_rides' => $total_completed_rides];
    
        json_response('success', 'Earnings data fetched successfully', ['summary' => $summary, 'history' => $earnings_history]);
    }

    if ($action === 'get_ride_history' && $_SESSION['user_type'] === 'driver') {
        $driver_id = (int)$_SESSION['user_id'];
        [$page, $limit, $offset] = paginate_params(); // GET ?page=&?limit=

        $stmt = $pdo->prepare("
            SELECT
                rs.id,
                rs.public_id,
                rs.start_location,
                rs.destination,
                rs.departure_date,
                rs.price_per_passenger,
                rs.status,
                rs.ride_mode,
                (SELECT SUM(rb.seats_booked) FROM ride_share_bookings rb WHERE rb.ride_share_id = rs.id AND rb.status = 'completed') as total_seats_booked,
                (SELECT COALESCE(SUM(p.fare_amount), 0) FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.ride_share_id = rs.id AND b.status = 'completed') AS gross_revenue,
                (SELECT COALESCE(SUM(p.commission_amount), 0) FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.ride_share_id = rs.id AND b.status = 'completed') AS total_commission,
                (SELECT COALESCE(SUM(p.driver_earning_amount), 0) FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.ride_share_id = rs.id AND b.status = 'completed') AS total_driver_earning,
                (SELECT COUNT(*) FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.ride_share_id = rs.id AND b.status = 'completed' AND p.status = 'PAYMENT_PENDING') AS pending_direct_payments,
                (SELECT AVG(rating) FROM ride_share_ratings WHERE ride_share_id = rs.id) AS average_rating,
                (SELECT COUNT(*) FROM ride_share_ratings WHERE ride_share_id = rs.id) AS rating_count
            FROM ride_shares rs
            WHERE rs.driver_id = ? AND rs.status IN ('completed', 'cancelled')
            ORDER BY rs.departure_date DESC, rs.departure_time DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$driver_id]);
        $history = $stmt->fetchAll();

        foreach ($history as &$ride) {
            if ($ride['status'] === 'completed') {
                $ride['earning'] = (float)$ride['total_driver_earning'];
            } else {
                $ride['earning'] = 0;
            }
            $ride['gross_revenue'] = (float)$ride['gross_revenue'];
            $ride['total_commission'] = (float)$ride['total_commission'];
            $ride['payment_status'] = ((int)$ride['pending_direct_payments'] > 0) ? 'partial' : 'settled';
            $ride['average_rating'] = $ride['average_rating'] !== null ? round((float)$ride['average_rating'], 2) : null;
        }
        unset($ride);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ride_shares WHERE driver_id = ? AND status IN ('completed', 'cancelled')");
        $countStmt->execute([$driver_id]);
        json_response('success', 'Ride history fetched', ['history' => $history], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    // Trips module detail sheet (driver) — one call for the map, the
    // trip_state_events timeline, and a full financial breakdown. Every
    // figure here is read from tables the engine already writes
    // (payments, ride_commission_reservations, trip_state_events); this
    // action introduces no new money-moving logic of its own.
    if ($action === 'get_trip_detail' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($_GET['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $stmt = $pdo->prepare("SELECT * FROM ride_shares WHERE id = ? AND driver_id = ?");
        $stmt->execute([$ride_id, $_SESSION['user_id']]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response('error', 'Ride not found or you do not have permission to view it.');
        }

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(p.fare_amount), 0) AS fare_collected,
                   COALESCE(SUM(p.commission_amount), 0) AS commission,
                   COALESCE(SUM(p.driver_earning_amount), 0) AS driver_earning
            FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id
            WHERE p.ride_share_id = ? AND b.status = 'completed'
        ");
        $stmt->execute([$ride_id]);
        $financials = $stmt->fetch();
        $financials = array_map('floatval', $financials);

        $stmt = $pdo->prepare("SELECT reserved_amount, used_amount, released_amount, status FROM ride_commission_reservations WHERE ride_share_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$ride_id]);
        $reservation = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare("SELECT id, from_state, to_state, trigger_type, meta, created_at FROM trip_state_events WHERE ride_share_id = ? ORDER BY created_at ASC, id ASC");
        $stmt->execute([$ride_id]);
        $timeline = $stmt->fetchAll();

        json_response('success', 'Trip detail fetched', [
            'ride' => $ride,
            'financials' => array_merge($financials, ['reservation' => $reservation]),
            'timeline' => $timeline,
        ]);
    }

    if ($action === 'start_ride' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        // Surface the exact Private Pickup arrival-gate message (criterion #12)
        // when that is the reason a start is refused. mark_ride_started()
        // enforces the same gate regardless (it is the single chokepoint), so
        // this only improves the message the driver sees; it can never be the
        // thing that lets a start through. No-op for Join Ride trips.
        $block = private_pickup_start_block_reason($pdo, $ride_id);
        if ($block !== null) {
            json_response('error', $block);
        }

        if (mark_ride_started($pdo, $ride_id, $_SESSION['user_id'], 'MANUAL_DRIVER_ACTION')) {
            json_response('success', 'Ride started successfully');
        } else {
            json_response('error', 'Could not start ride. It may have already been started, completed, or cancelled.');
        }
    }

    if ($action === 'complete_ride' && $_SESSION['user_type'] === 'driver') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $pdo->beginTransaction();
        try {
            // Was this GPS-staged (AUTO_COMPLETED_PENDING) before the
            // driver confirmed, or a direct manual completion with no
            // staging? Either path is valid — GPS staging is a
            // convenience, not a requirement — but auto_completed
            // records which one happened.
            $stmt = $pdo->prepare("SELECT trip_state FROM ride_shares WHERE id = ? AND driver_id = ? FOR UPDATE");
            $stmt->execute([$ride_id, $_SESSION['user_id']]);
            $wasAutoPending = $stmt->fetchColumn() === 'AUTO_COMPLETED_PENDING';

            $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'completed' WHERE id = ? AND driver_id = ? AND status = 'in_progress'");
            $stmt->execute([$ride_id, $_SESSION['user_id']]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Could not complete ride. It may not be in progress.');
            }

            $stmt = $pdo->prepare("UPDATE ride_share_bookings SET status = 'completed' WHERE ride_share_id = ? AND status != 'cancelled'");
            $stmt->execute([$ride_id]);
            // Phase 3: keep the granular passenger_state layer in sync
            // with this existing manual completion — anyone not already
            // no_show is now completed. Whichever passengers already
            // reached dropped_off/completed themselves are left as-is
            // (no event double-logged); only stragglers get swept here.
            $pdo->prepare("UPDATE ride_share_bookings SET passenger_state = 'completed', passenger_state_updated_at = NOW() WHERE ride_share_id = ? AND status = 'completed' AND passenger_state NOT IN ('completed', 'no_show')")
                ->execute([$ride_id]);

            // Release vehicle reservation on completion
            $pdo->prepare("UPDATE ride_shares SET vehicle_reserved = 0 WHERE id = ?")
                ->execute([$ride_id]);

            // Private Pickup: close the originating request so a completed trip
            // stops counting against the rider's "one active pickup request at a
            // time" guard (includes/private_pickup.php:60). The accept handler
            // moves the request to 'in_progress' but nothing ever advanced it to
            // a terminal state on completion, so it was stranded at 'in_progress'
            // forever and every future create() was rejected with "You already
            // have an active pickup request." — permanently locking a rider to a
            // single lifetime pickup. Scoped to the linked private_pickup request
            // only; Join Ride bookings have no ride_requests row, so this is a
            // no-op for them and cannot affect the Join Ride lane.
            $pdo->prepare("UPDATE ride_requests SET status = 'completed' WHERE matched_ride_share_id = ? AND request_type = 'private_pickup' AND status IN ('accepted','in_progress')")
                ->execute([$ride_id]);

            transition_trip_state($pdo, $ride_id, 'TRIP_COMPLETED', 'MANUAL_DRIVER_ACTION');
            if ($wasAutoPending) {
                $pdo->prepare("UPDATE ride_shares SET auto_completed = 1 WHERE id = ?")->execute([$ride_id]);
            }

            // Settle each booking independently — a carpool ride can
            // carry a mix of wallet and direct passengers, each with its
            // own payment record from the 'book' action. Direct-paid
            // bookings' commission stays pending until the driver calls
            // confirm_direct_payment; this only credits wallet-paid
            // earnings and settles the ride's commission reservation
            // (used vs released) — shared with admin's complete_ride_share.
            $total_earning_credited = settle_ride_completion($pdo, $ride_id);

            $pdo->commit();

            // Every completed-trip rider notification already went out via
            // transition_trip_state()'s dispatch_trip_state_notification()
            // (called above, inside the transaction) — Notification Engine,
            // not a second per-call-site loop here.

            json_response('success', 'Ride completed successfully', ['driver_earning_credited' => $total_earning_credited]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    // Driver confirms a direct (cash/off-wallet) passenger payment was
    // actually received. This is the only place a direct payment's
    // commission is ever settled — MohToh never saw the fare itself, so
    // instead of crediting the driver, it debits just the commission
    // the driver still owes on that fare.
    if ($action === 'confirm_direct_payment' && $_SESSION['user_type'] === 'driver') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            json_response('error', 'Booking ID is required');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT p.id, p.ride_share_id, p.commission_amount, b.status AS booking_status FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.booking_id = ? AND p.driver_id = ? AND p.payment_method = 'direct' AND p.status = 'PAYMENT_PENDING' FOR UPDATE");
            $stmt->execute([$booking_id, $_SESSION['user_id']]);
            $payment = $stmt->fetch();
            if (!$payment) {
                throw new Exception('No pending direct payment found for this booking.');
            }
            if ($payment['booking_status'] !== 'completed') {
                throw new Exception('This trip must be completed before confirming payment.');
            }

            if ((float)$payment['commission_amount'] > 0) {
                // The real money movement — settle_ride_completion() already
                // moved this booking's commission from "reserved" to "used"
                // on the ride's reservation when the trip completed; this is
                // that logical commitment finally becoming an actual debit.
                $newBalance = debit_wallet($pdo, $_SESSION['user_id'], (float)$payment['commission_amount'], 'COMMISSION_SETTLED', "MohToh commission on directly-paid booking #{$booking_id}", $payment['ride_share_id'], $booking_id);
                if ($newBalance === false) {
                    throw new Exception('Your wallet balance cannot cover MohToh\'s commission on this payment. Please top up and try again.');
                }
            }

            $stmt = $pdo->prepare("UPDATE payments SET status = 'PAYMENT_CONFIRMED', confirmed_at = NOW() WHERE id = ?");
            $stmt->execute([$payment['id']]);

            $pdo->commit();

            // MOHTOH NETWORK: a direct-paid booking only becomes a
            // qualifying transaction once BOTH the trip is completed
            // AND the driver confirms the cash arrived — this is that
            // second condition finally being met. Idempotent; never
            // allowed to affect the payment confirmation above.
            try {
                network_settle_booking_reward($pdo, $booking_id);
            } catch (Throwable $e) {
                error_log('MOHTOH NETWORK settlement failed for booking #' . $booking_id . ': ' . $e->getMessage());
            }

            json_response('success', 'Direct payment confirmed and commission settled.');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    // Driver reports a direct payment never arrived. No money moves —
    // this only closes out the pending record so it stops showing as
    // awaiting confirmation.
    if ($action === 'reject_direct_payment' && $_SESSION['user_type'] === 'driver') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            json_response('error', 'Booking ID is required');
        }

        $stmt = $pdo->prepare("UPDATE payments SET status = 'PAYMENT_FAILED' WHERE booking_id = ? AND driver_id = ? AND payment_method = 'direct' AND status = 'PAYMENT_PENDING'");
        $stmt->execute([$booking_id, $_SESSION['user_id']]);

        if ($stmt->rowCount() > 0) {
            json_response('success', 'Payment marked as not received.');
        } else {
            json_response('error', 'No pending direct payment found for this booking.');
        }
    }

    if ($action === 'search') {
        $pickup_location = sanitize_input(trim($input['pickup_location'] ?? $input['start_location'] ?? ''));
        $destination = sanitize_input(trim($input['destination'] ?? ''));
        $departure_date = sanitize_input(trim($input['departure_date'] ?? ''));
        $departure_time = sanitize_input(trim($input['departure_time'] ?? ''));

        if ($pickup_location === '' || $destination === '' || $departure_date === '') {
            json_response('error', 'Pickup location, destination and date are required');
        }

        // Phase 4 (Waitlist System): 'full' rides are now included too
        // (previously invisible to search entirely) — a rider searching
        // an already-full popular route needs to actually see it to be
        // offered the waitlist. rs.status still excludes
        // cancelled/in_progress/completed, unchanged from before.
        // Part 2 (Premium Driver Features) — "Priority Ride Visibility" /
        // "More Ride Exposure": a driver with an active approved request
        // for either feature is ranked first in search results, ahead of
        // departure-time ordering. u.driver_tier is the same aggregate
        // flag api/premium.php's refresh_driver_tier() keeps in sync
        // with driver_premium_requests — not a second, separate flag.
        $sql = "SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.total_seats, rs.available_seats, rs.status, rs.price_per_passenger, rs.pricing_mode, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, u.fullname AS driver_name, u.rating AS driver_rating, u.profile_image as driver_image, u.driver_tier, v.make as vehicle_make, v.model as vehicle_model, v.luggage_capacity, v.vehicle_type, v.verification_status, v.passenger_capacity, (SELECT vp.photo_path FROM vehicle_photos vp WHERE vp.vehicle_id = v.id ORDER BY vp.created_at DESC LIMIT 1) as vehicle_photo FROM ride_shares rs JOIN users u ON u.id = rs.driver_id LEFT JOIN vehicles v ON rs.vehicle_id = v.id WHERE rs.status IN ('active', 'full') AND rs.departure_date = ? AND rs.start_location LIKE ? AND rs.destination LIKE ?";
        $params = [$departure_date, '%' . $pickup_location . '%', '%' . $destination . '%'];

        if ($departure_time !== '') {
            $sql .= " AND rs.departure_time >= ?";
            $params[] = $departure_time;
        }

        $sql .= " ORDER BY (u.driver_tier = 'premium') DESC, rs.departure_time ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rides = $stmt->fetchAll();

        foreach ($rides as &$ride) {
            $distance = calculate_distance_km($ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
            if ($distance > 0) {
                $average_speed_kmh = 40;
                $duration_minutes = ($distance / $average_speed_kmh) * 60;
                $departure_timestamp = strtotime($ride['departure_date'] . ' ' . $ride['departure_time']);
                $arrival_timestamp = $departure_timestamp + ($duration_minutes * 60);
                $ride['estimated_arrival_time'] = date('H:i', $arrival_timestamp);
            } else {
                $ride['estimated_arrival_time'] = null;
            }
        }
        unset($ride);

        json_response('success', 'Matching rides fetched', ['rides' => $rides]);
    }

    if ($action === 'details') {
        $ride_id    = (int)($input['ride_id']   ?? $_GET['ride_id']   ?? 0);
        $public_id  = sanitize_input($input['public_id'] ?? $_GET['public_id'] ?? '');

        // Support share-link deep-link: caller may pass only public_id.
        if ($ride_id <= 0 && $public_id !== '') {
            $pid_stmt = $pdo->prepare("SELECT id FROM ride_shares WHERE public_id = ? LIMIT 1");
            $pid_stmt->execute([$public_id]);
            $ride_id = (int)($pid_stmt->fetchColumn() ?: 0);
        }

        if ($ride_id <= 0) {
            json_response('error', 'Ride ID or public ID is required');
        }

        $stmt = $pdo->prepare("
            SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.vehicle_type, rs.vehicle_info, rs.total_seats, rs.available_seats, rs.price_per_passenger, rs.pricing_mode, rs.fare_breakdown, rs.commission_percentage, rs.payment_preference, rs.description, rs.status, rs.created_at, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, 
                   u.fullname AS driver_name, u.profile_image as driver_photo, u.rating AS driver_rating, u.is_verified AS driver_verified,
                   (SELECT COUNT(*) FROM ride_requests WHERE driver_id = u.id AND status = 'completed') as completed_trips,
                   v.make as vehicle_make, v.model as vehicle_model, v.color as vehicle_color, v.plate_number as vehicle_plate, v.luggage_capacity, v.vehicle_type as v_type, v.verification_status, v.passenger_capacity,
                   (SELECT GROUP_CONCAT(photo_path) FROM vehicle_photos WHERE vehicle_id = v.id) as vehicle_photos
            FROM ride_shares rs 
            JOIN users u ON u.id = rs.driver_id 
            LEFT JOIN vehicles v ON rs.vehicle_id = v.id 
            WHERE rs.id = ?");
        $stmt->execute([$ride_id]);
        $ride = $stmt->fetch();

        if (!$ride) {
            json_response('error', 'Ride not found');
        }

        if ($ride) {
            $ride['vehicle_photos'] = $ride['vehicle_photos'] ? explode(',', $ride['vehicle_photos']) : [];
        }

        // Passenger Experience: riders see route/seats/fare only — never
        // MohToh's internal commission math. Only the ride's own driver
        // (this action is shared by both roles) gets the breakdown and
        // commission rate.
        $isOwnDriver = $_SESSION['user_type'] === 'driver' && (int)$ride['driver_id'] === (int)$_SESSION['user_id'];
        if ($isOwnDriver) {
            $ride['fare_breakdown'] = $ride['fare_breakdown'] ? json_decode($ride['fare_breakdown'], true) : null;
        } else {
            unset($ride['fare_breakdown'], $ride['commission_percentage']);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE ride_share_id = ? AND status != 'cancelled'");
        $stmt->execute([$ride_id]);
        $passengers_joined = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT SUM(seats_booked) FROM ride_share_bookings WHERE ride_share_id = ? AND status != 'cancelled'");
        $stmt->execute([$ride_id]);
        $booked_seats = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE ride_share_id = ? AND rider_id = ? AND status != 'cancelled'");
        $stmt->execute([$ride_id, $_SESSION['user_id']]);
        $ride['already_joined'] = (int)$stmt->fetchColumn() > 0;
        $ride['passengers_joined'] = $passengers_joined;
        $ride['booked_seats'] = $booked_seats;
        $ride['remaining_seats'] = (int)$ride['available_seats'];

        // Ride Queue: lets the rider's detail sheet show "Join Ride
        // Queue" vs "You're in the ride queue" vs "Seat available — Book
        // Now" without a second round-trip.
        $ride['queue_entry'] = null;
        if ($_SESSION['user_type'] === 'rider') {
            $stmt = $pdo->prepare("SELECT public_id, status, seats_requested FROM ride_waitlists WHERE ride_share_id = ? AND user_id = ? AND status IN ('WAITING', 'NOTIFIED', 'RESERVED') LIMIT 1");
            $stmt->execute([$ride_id, $_SESSION['user_id']]);
            $queueEntry = $stmt->fetch();
            if ($queueEntry) {
                $ride['queue_entry'] = $queueEntry;
            }
        }

        // Route summary — same distance math used for pricing, reused
        // here so drivers/riders see one consistent number rather than
        // a second estimate computed differently on the client.
        $ride['distance_km'] = null;
        $ride['estimated_duration_minutes'] = null;
        if ($ride['pickup_lat'] && $ride['pickup_lng'] && $ride['destination_lat'] && $ride['destination_lng']) {
            // Real road distance + ETA via Google Distance Matrix when
            // configured; haversine/40km/h fallback otherwise. Same
            // display path as pricing so drivers/riders see one consistent
            // number.
            $distance = calculate_trip_distance_km($pdo, $ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
            $ride['distance_km'] = $distance;
            $ride['estimated_duration_minutes'] = calculate_trip_duration_minutes($pdo, $ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
        }

        json_response('success', 'Ride details fetched', ['ride' => $ride]);
    }

    if ($action === 'my_bookings' && $_SESSION['user_type'] === 'rider') {
        [$page, $limit, $offset] = paginate_params(); // GET ?page=&?limit=
        $rider_id = (int)$_SESSION['user_id'];

        $stmt = $pdo->prepare("
            SELECT rb.id AS booking_id, rb.public_id AS booking_public_id, rb.ride_share_id, rs.public_id AS ride_public_id, rb.seats_booked, rb.status, rb.payment_method, rb.passenger_state,
                   rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.vehicle_type, rs.vehicle_info, rs.price_per_passenger, rs.available_seats, rs.status AS ride_status, rs.trip_state, rs.ride_mode,
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
        $stmt->execute([$rider_id]);
        $rides = $stmt->fetchAll();

        // Stats must reflect the rider's ENTIRE booking history, not just
        // the current page — computed as SQL aggregates over all rows,
        // never by iterating the (now paginated) $rides array above.
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
        $stmt->execute([$rider_id]);
        $statsRow = $stmt->fetch();
        $stats = [
            'joined_rides' => (int)$statsRow['joined_rides'],
            'upcoming_trips' => (int)$statsRow['upcoming_trips'],
            'completed_trips' => (int)$statsRow['completed_trips'],
            'cancelled_trips' => (int)$statsRow['cancelled_trips'],
        ];

        json_response('success', 'Your booked rides fetched', ['rides' => $rides, 'stats' => $stats], paginate_meta($page, $limit, (int)$statsRow['total_records']));
    }

    // Trips module detail sheet (rider) — mirrors the driver's
    // get_trip_detail: one call for the map, a merged timeline (the
    // ride's trip_state_events plus this booking's own
    // booking_state_events, so a rider sees both "trip started" and
    // "you boarded" on one shared timeline), and what they paid.
    if ($action === 'get_trip_detail' && $_SESSION['user_type'] === 'rider') {
        $booking_id = (int)($_GET['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            json_response('error', 'Booking ID is required');
        }

        $stmt = $pdo->prepare("
            SELECT rb.id AS booking_id, rb.public_id AS booking_public_id, rb.seats_booked, rb.status AS booking_status, rb.payment_method, rb.passenger_state, rb.created_at AS booked_at,
                   rs.id AS ride_share_id, rs.public_id AS ride_public_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.status AS ride_status, rs.trip_state,
                   rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, rs.current_latitude, rs.current_longitude,
                   u.fullname AS driver_name, u.phone AS driver_phone, u.profile_image AS driver_image, u.rating AS driver_rating,
                   v.make AS vehicle_make, v.model AS vehicle_model, v.plate_number AS vehicle_plate, v.color AS vehicle_color,
                   p.fare_amount, p.commission_amount, p.status AS payment_status
            FROM ride_share_bookings rb
            JOIN ride_shares rs ON rs.id = rb.ride_share_id
            JOIN users u ON u.id = rs.driver_id
            LEFT JOIN vehicles v ON rs.vehicle_id = v.id
            LEFT JOIN payments p ON p.booking_id = rb.id
            WHERE rb.id = ? AND rb.rider_id = ?
        ");
        $stmt->execute([$booking_id, $_SESSION['user_id']]);
        $trip = $stmt->fetch();
        if (!$trip) {
            json_response('error', 'Booking not found.');
        }

        $stmt = $pdo->prepare("SELECT 'trip' AS scope, from_state, to_state, trigger_type, meta, created_at FROM trip_state_events WHERE ride_share_id = ?
                                UNION ALL
                                SELECT 'passenger' AS scope, from_state, to_state, trigger_type, meta, created_at FROM booking_state_events WHERE booking_id = ?
                                ORDER BY created_at ASC");
        $stmt->execute([$trip['ride_share_id'], $booking_id]);
        $timeline = $stmt->fetchAll();

        json_response('success', 'Trip detail fetched', ['trip' => $trip, 'timeline' => $timeline]);
    }

    if ($action === 'list') {
        $stmt = $pdo->prepare("SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.vehicle_type, rs.vehicle_info, rs.available_seats, rs.price_per_passenger, rs.description, rs.status, rs.created_at, u.fullname AS driver_name, u.rating AS driver_rating, CASE WHEN v.status = 'approved' THEN 1 ELSE 0 END AS driver_verified, v.status AS driver_vehicle_status FROM ride_shares rs JOIN users u ON u.id = rs.driver_id LEFT JOIN vehicles v ON v.driver_id = rs.driver_id WHERE rs.status = 'active' ORDER BY rs.departure_date ASC, rs.departure_time ASC");
        $stmt->execute();
        json_response('success', 'Ride sharing sessions fetched', $stmt->fetchAll());
    }

    if ($action === 'cancel_booking' && $_SESSION['user_type'] === 'rider') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            json_response('error', 'Booking ID is required');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT ride_share_id, seats_booked, rider_id, payment_method FROM ride_share_bookings WHERE id = ? AND rider_id = ? AND status NOT IN ('cancelled', 'completed') FOR UPDATE");
            $stmt->execute([$booking_id, $_SESSION['user_id']]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new Exception('Active booking not found or you do not have permission to cancel it.');
            }

            $stmt = $pdo->prepare("SELECT price_per_passenger FROM ride_shares WHERE id = ?");
            $stmt->execute([$booking['ride_share_id']]);
            $price_per_passenger = (float)$stmt->fetchColumn();
            $refund_amount = $price_per_passenger * (int)$booking['seats_booked'];

            $stmt = $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled', passenger_state = 'cancelled', passenger_state_updated_at = NOW() WHERE id = ?");
            $stmt->execute([$booking_id]);

            $stmt = $pdo->prepare("UPDATE ride_shares SET available_seats = available_seats + ?, status = 'active' WHERE id = ?");
            $stmt->execute([(int)$booking['seats_booked'], $booking['ride_share_id']]);

            // Only a wallet booking ever actually moved money — a direct
            // booking's payment row is still PAYMENT_PENDING (nothing was
            // taken), so there's nothing to refund; just close it out so
            // it never surfaces as "awaiting confirmation".
            if ($booking['payment_method'] === 'wallet' && $refund_amount > 0) {
                credit_wallet($pdo, $booking['rider_id'], $refund_amount, 'ride_refund', "Refund for cancelled booking of ride #{$booking['ride_share_id']}", $booking['ride_share_id'], $booking_id);
            } else {
                $stmt = $pdo->prepare("UPDATE payments SET status = 'PAYMENT_FAILED', updated_at = NOW() WHERE booking_id = ? AND status = 'PAYMENT_PENDING'");
                $stmt->execute([$booking_id]);
            }

            $pdo->commit();

            // Chat: the rider leaves the ride's group chat — history stays
            // for the driver/other passengers, they just stop seeing new
            // messages and can't post further (includes/chat.php).
            require_once '../includes/chat.php';
            $chatConversationId = find_ride_conversation($pdo, $booking['ride_share_id']);
            if ($chatConversationId) {
                post_system_message($pdo, $chatConversationId, ($_SESSION['fullname'] ?? 'A passenger') . ' left the ride.');
                remove_conversation_member($pdo, $chatConversationId, $_SESSION['user_id']);
            }

            // Ride Queue: a cancelled booking just freed seat(s) —
            // notify whichever queued passengers can realistically take
            // them. Never books anyone automatically.
            require_once '../includes/ride_queue.php';
            process_queue($pdo, $booking['ride_share_id']);

            json_response('success', 'Booking cancelled successfully');
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    // Ride Queue: this action's entire transaction now lives in
    // includes/booking.php's create_ride_booking() — reused as-is by
    // api/ride-queue.php's BOOK_NOW flow so a queued passenger goes
    // through the exact same wallet/payment/commission/validation
    // logic, not a second copy of it. Behavior here is unchanged: same
    // checks, same order, same response shape.
    if ($action === 'book' && $_SESSION['user_type'] === 'rider') {
        require_once '../includes/booking.php';
        $result = create_ride_booking(
            $pdo,
            $_SESSION['user_id'],
            (int)($input['ride_share_id'] ?? 0),
            max(1, (int)($input['seats_booked'] ?? 1)),
            $input['payment_method'] ?? null,
            isset($input['promo_code']) ? (string)$input['promo_code'] : ''
        );
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    if ($action === 'rate_ride' && $_SESSION['user_type'] === 'rider') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        $rating = (int)($input['rating'] ?? 0);
        $comment = sanitize_input($input['comment'] ?? '');

        if ($booking_id <= 0 || $rating < 1 || $rating > 5) {
            json_response('error', 'Valid booking ID and a rating between 1 and 5 are required.');
        }

        $rider_id = $_SESSION['user_id'];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT b.ride_share_id, rs.driver_id FROM ride_share_bookings b JOIN ride_shares rs ON b.ride_share_id = rs.id WHERE b.id = ? AND b.rider_id = ? AND b.status = 'completed'");
            $stmt->execute([$booking_id, $rider_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                throw new Exception('This booking cannot be rated. It might not be completed or does not belong to you.');
            }

            $stmt = $pdo->prepare("SELECT id FROM ride_share_ratings WHERE booking_id = ? AND rider_id = ?");
            $stmt->execute([$booking_id, $rider_id]);
            if ($stmt->fetch()) {
                throw new Exception('You have already rated this ride.');
            }

            $rating_public_id = generate_unique_public_id($pdo, 'ride_share_ratings', 'public_id', 'RATING');
            $stmt = $pdo->prepare("INSERT INTO ride_share_ratings (public_id, ride_share_id, booking_id, rider_id, driver_id, rating, comment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$rating_public_id, $booking['ride_share_id'], $booking_id, $rider_id, $booking['driver_id'], $rating, $comment]);

            // Update driver's average rating
            $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating FROM ride_share_ratings WHERE driver_id = ?");
            $stmt->execute([$booking['driver_id']]);
            $avg_rating = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE users SET rating = ? WHERE id = ?");
            $stmt->execute([round($avg_rating, 2), $booking['driver_id']]);

            $pdo->commit();

            // Create a notification for the driver
            $stmt = $pdo->prepare("SELECT notify_on_rating FROM users WHERE id = ?");
            $stmt->execute([$booking['driver_id']]);
            if ($stmt->fetchColumn()) {
                $notification_message = "A rider gave you a {$rating}-star rating for a recent ride.";
                notify_user($pdo, $booking['driver_id'], $notification_message, 'trips.php?tab=completed');
            }

            json_response('success', 'Thank you for your feedback!');

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    // Driver -> passenger rating for a completed Join Ride. Symmetric twin of
    // rate_ride, keyed on the same booking relationship. Writes only to the
    // dedicated ride_share_passenger_ratings table; it deliberately does NOT
    // touch users.rating (that column is the DRIVER rating aggregate — a rider
    // being rated here may also be a driver, so writing it would clobber their
    // driver score). Rider-scoped authorization: the driver may only rate a
    // passenger on a completed ride they own.
    if ($action === 'rate_passenger' && $_SESSION['user_type'] === 'driver') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        $rating = (int)($input['rating'] ?? 0);
        $comment = sanitize_input($input['comment'] ?? '');

        if ($booking_id <= 0 || $rating < 1 || $rating > 5) {
            json_response('error', 'Valid booking ID and a rating between 1 and 5 are required.');
        }

        $driver_id = $_SESSION['user_id'];

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT b.ride_share_id, b.rider_id FROM ride_share_bookings b JOIN ride_shares rs ON b.ride_share_id = rs.id WHERE b.id = ? AND rs.driver_id = ? AND b.status = 'completed'");
            $stmt->execute([$booking_id, $driver_id]);
            $booking = $stmt->fetch();

            if (!$booking) {
                throw new Exception('This passenger cannot be rated. The ride might not be completed or is not one of yours.');
            }

            $stmt = $pdo->prepare("SELECT id FROM ride_share_passenger_ratings WHERE booking_id = ? AND driver_id = ?");
            $stmt->execute([$booking_id, $driver_id]);
            if ($stmt->fetch()) {
                throw new Exception('You have already rated this passenger.');
            }

            $rating_public_id = generate_unique_public_id($pdo, 'ride_share_passenger_ratings', 'public_id', 'PRATING');
            $stmt = $pdo->prepare("INSERT INTO ride_share_passenger_ratings (public_id, ride_share_id, booking_id, driver_id, rider_id, rating, comment) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$rating_public_id, $booking['ride_share_id'], $booking_id, $driver_id, $booking['rider_id'], $rating, $comment]);

            $pdo->commit();

            // Notify the rated passenger (gated on their notification preference).
            $stmt = $pdo->prepare("SELECT notify_on_rating FROM users WHERE id = ?");
            $stmt->execute([$booking['rider_id']]);
            if ($stmt->fetchColumn()) {
                $notification_message = "Your driver gave you a {$rating}-star rating for a recent ride.";
                notify_user($pdo, $booking['rider_id'], $notification_message, 'trips.php?tab=completed');
            }

            json_response('success', 'Thank you for rating your passenger!');

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', $e->getMessage());
        }
    }

    if ($action === 'get_driver_location' && $_SESSION['user_type'] === 'rider') {
        $ride_share_id = (int)($_GET['ride_share_id'] ?? 0);
        if ($ride_share_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        // 1. Verify the rider has an active booking for this ride. Broadened
        // beyond just 'in_progress' so the Trips module's live map also
        // works while the driver is still en route to/waiting at pickup
        // (trip_state PASSENGERS_JOINING/PASSENGERS_ASSEMBLED) — the
        // driver's GPS is already being tracked at that point, riders just
        // couldn't see it before.
        $stmt = $pdo->prepare("SELECT rs.driver_id, u.fullname as driver_name, rs.trip_state FROM ride_share_bookings b JOIN ride_shares rs ON b.ride_share_id = rs.id JOIN users u ON rs.driver_id = u.id WHERE b.ride_share_id = ? AND b.rider_id = ? AND b.status != 'cancelled' AND rs.status IN ('active', 'full', 'in_progress')");
        $stmt->execute([$ride_share_id, $_SESSION['user_id']]);
        $ride_info = $stmt->fetch();

        if (!$ride_info) {
            json_response('error', 'You do not have an active trip for this ride, or the ride is not in progress.');
        }

        // 2. Get the driver's location
        $driver_id = $ride_info['driver_id'];
        $stmt = $pdo->prepare("SELECT latitude, longitude FROM users WHERE id = ?");
        $stmt->execute([$driver_id]);
        $location = $stmt->fetch();

        if (!$location || !$location['latitude']) {
            json_response('error', 'Driver location is not available at the moment.');
        }

        json_response('success', 'Driver location fetched', ['latitude' => (float)$location['latitude'], 'longitude' => (float)$location['longitude'], 'driver_name' => $ride_info['driver_name']
        ]);
    }

    if ($action === 'get_recommended_rides') {
        $lat = (float)($_GET['lat'] ?? 0);
        $lng = (float)($_GET['lng'] ?? 0);
        $limit = 6; // Limit the number of recommended rides

        $params = [];
        $where_clauses = ["rs.status = 'active'", "rs.departure_date >= CURDATE()"];

        $distance_calc = "";
        if ($lat && $lng) {
            $distance_calc = ", ( 6371 * acos( cos( radians(?) ) * cos( radians( rs.pickup_lat ) ) * cos( radians( rs.pickup_lng ) - radians(?) ) + sin( radians(?) ) * sin( radians( rs.pickup_lat ) ) ) ) AS distance";
            $params[] = $lat;
            $params[] = $lng;
            $params[] = $lat;
        }

        $sql = "SELECT rs.id, rs.public_id, rs.driver_id, rs.start_location, rs.destination, rs.departure_date, rs.departure_time, rs.available_seats, rs.price_per_passenger, rs.pricing_mode, rs.pickup_lat, rs.pickup_lng, rs.destination_lat, rs.destination_lng, u.fullname AS driver_name, u.rating AS driver_rating, u.profile_image as driver_image, v.make as vehicle_make, v.model as vehicle_model, v.luggage_capacity, (SELECT vp.photo_path FROM vehicle_photos vp WHERE vp.vehicle_id = v.id ORDER BY vp.created_at DESC LIMIT 1) as vehicle_photo $distance_calc
                FROM ride_shares rs 
                JOIN users u ON u.id = rs.driver_id 
                LEFT JOIN vehicles v ON rs.vehicle_id = v.id
                WHERE " . implode(' AND ', $where_clauses) . "
                ORDER BY " . ($lat && $lng ? "distance ASC, " : "") . "rs.departure_date ASC, rs.departure_time ASC
                LIMIT $limit";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rides = $stmt->fetchAll();

        foreach ($rides as &$ride) {
            $distance = calculate_distance_km($ride['pickup_lat'], $ride['pickup_lng'], $ride['destination_lat'], $ride['destination_lng']);
            if ($distance > 0) {
                $average_speed_kmh = 40;
                $duration_minutes = ($distance / $average_speed_kmh) * 60;
                $departure_timestamp = strtotime($ride['departure_date'] . ' ' . $ride['departure_time']);
                $arrival_timestamp = $departure_timestamp + ($duration_minutes * 60);
                $ride['estimated_arrival_time'] = date('H:i', $arrival_timestamp);
            } else {
                $ride['estimated_arrival_time'] = null;
            }
        }
        unset($ride);

        json_response('success', 'Recommended rides fetched', ['rides' => $rides]);
    }

    json_response('error', 'Invalid action');
} catch (PDOException $e) {
    json_response('error', 'Database error: ' . $e->getMessage());
}
