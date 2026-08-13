<?php
// POST /api/v1/driver/create-ride.php — same orchestration as
// api/rideshare.php's create action: every calculation (pricing,
// commission, distance, wallet-reserve solvency) goes through the exact
// same reusable functions (calculate_distance_km(), calculate_recommended_seat_price(),
// resolve_price_per_passenger(), get_commission_percentage(),
// get_available_wallet_balance(), create_commission_reservation(),
// transition_trip_state(), match_ride_requests()) — no pricing/commission
// rule is duplicated, only the glue orchestrating them.
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/ride_requests.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

$input = v1_input();
foreach (['pickup_location', 'destination', 'departure_date', 'departure_time'] as $field) {
    if (!isset($input[$field]) || trim((string)$input[$field]) === '') {
        v1_error(ucfirst(str_replace('_', ' ', $field)) . ' is required.', 'validation_error');
    }
}

$vehicleStmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
$vehicleStmt->execute([$driverId]);
$vehicle = $vehicleStmt->fetch();
if (!$vehicle) {
    v1_error('Your vehicle must be verified before creating rides.', 'vehicle_not_verified');
}
if (strtolower((string)$vehicle['status']) !== 'approved') {
    $message = strtolower((string)$vehicle['status']) === 'rejected'
        ? 'Your vehicle was rejected. Please update the vehicle verification details before creating rides.'
        : 'Your vehicle must be verified before creating rides.';
    v1_error($message, 'vehicle_not_verified');
}

if (!$user['is_verified']) {
    v1_error('Your driver account is not yet verified. This is usually resolved automatically once your vehicle is approved.', 'driver_not_verified');
}

$emailEnabled = is_email_verification_enabled($pdo);
$phoneEnabled = is_phone_verification_enabled($pdo);
$emailBlocked = $emailEnabled && !$user['email_verified'];
$phoneBlocked = $phoneEnabled && !$user['phone_verified'];

if ($emailBlocked || $phoneBlocked) {
    v1_error('Please complete your required verification(s) before creating rides.', 'contact_not_verified', 403);
}

$startLocation = sanitize_input(trim($input['pickup_location'] ?? $input['start_location'] ?? ''));
$destination = sanitize_input(trim($input['destination']));
$departureDate = sanitize_input(trim($input['departure_date']));
$departureTime = sanitize_input(trim($input['departure_time']));
$pickupLat = isset($input['pickup_lat']) && trim((string)$input['pickup_lat']) !== '' ? (float)$input['pickup_lat'] : null;
$pickupLng = isset($input['pickup_lng']) && trim((string)$input['pickup_lng']) !== '' ? (float)$input['pickup_lng'] : null;
$destinationLat = isset($input['destination_lat']) && trim((string)$input['destination_lat']) !== '' ? (float)$input['destination_lat'] : null;
$destinationLng = isset($input['destination_lng']) && trim((string)$input['destination_lng']) !== '' ? (float)$input['destination_lng'] : null;

if (!$pickupLat || !$pickupLng || !$destinationLat || !$destinationLng) {
    v1_error('Pickup and destination must include coordinates so the trip distance and price can be calculated.', 'validation_error');
}

$vehicleId = $vehicle['id'];
$vehicleType = $vehicle['make'];
$vehicleInfo = 'Model: ' . $vehicle['model'] . '; Colour: ' . $vehicle['color'] . '; Plate: ' . $vehicle['plate_number'];
$totalSeats = max(1, (int)$vehicle['seats']);
$availableSeats = $totalSeats;
$description = sanitize_input(trim($input['description'] ?? ''));

$minWalletBalance = (float) get_pricing_setting($pdo, 'min_wallet_balance', 0);
$maxActiveRides = (int) get_pricing_setting($pdo, 'max_active_rides_per_driver', 0);
$isPremiumDriver = $user['driver_tier'] === 'premium';

if ($maxActiveRides > 0 && !$isPremiumDriver) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_shares WHERE driver_id = ? AND status IN ('active', 'full', 'in_progress')");
    $stmt->execute([$driverId]);
    if ((int)$stmt->fetchColumn() >= $maxActiveRides) {
        v1_error("You already have the maximum of {$maxActiveRides} active ride(s). Complete or cancel one before creating another.", 'max_active_rides');
    }
}

$distanceKm = calculate_trip_distance_km($pdo, $pickupLat, $pickupLng, $destinationLat, $destinationLng);
$pricing = calculate_recommended_seat_price($pdo, $distanceKm, $totalSeats, [
    'driver_id' => $driverId, 'pickup_lat' => $pickupLat, 'pickup_lng' => $pickupLng,
    'departure_date' => $departureDate, 'departure_time' => $departureTime,
]);

try {
    $pricePerPassenger = resolve_price_per_passenger($pdo, $pricing, $input['price_per_passenger'] ?? null, $totalSeats);
} catch (RuntimeException $e) {
    v1_error($e->getMessage(), 'pricing_error');
}

$commissionPercentage = get_commission_percentage($pdo, $driverId);
$commissionAmountPerSeat = round($pricePerPassenger * $commissionPercentage / 100, 2);
$driverEarningPerSeat = round($pricePerPassenger - $commissionAmountPerSeat, 2);
$requiredWalletReserve = round($commissionAmountPerSeat * $totalSeats, 2);
$ridePaymentPreference = $user['payment_preference'] ?: 'wallet';

$availableBalance = get_available_wallet_balance($pdo, $driverId);
if ($availableBalance < $requiredWalletReserve) {
    v1_error("Your wallet requires ₦{$requiredWalletReserve} commission reserve before publishing this trip. Please top up before publishing.", 'insufficient_wallet_reserve');
}
if ($minWalletBalance > 0 && $availableBalance < $minWalletBalance) {
    v1_error("Your wallet balance must be at least ₦{$minWalletBalance} to accept rides.", 'insufficient_wallet_balance');
}

$fareBreakdown = json_encode($pricing['breakdown']);
$ridePricingMode = (string) get_pricing_setting($pdo, 'pricing_mode', 'hybrid');

$pdo->beginTransaction();
try {
    $ridePublicId = generate_unique_public_id($pdo, 'ride_shares', 'public_id', 'RIDE');
    $stmt = $pdo->prepare("INSERT INTO ride_shares (public_id, driver_id, pickup_location, start_location, destination, departure_date, departure_time, vehicle_id, vehicle_type, vehicle_info, total_seats, available_seats, price_per_passenger, pricing_mode, commission_percentage, commission_amount_per_seat, driver_earning_per_seat, description, pickup_lat, pickup_lng, destination_lat, destination_lng, payment_preference, fare_breakdown, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    $stmt->execute([
        $ridePublicId, $driverId, $startLocation, $startLocation, $destination, $departureDate, $departureTime,
        $vehicleId, $vehicleType, $vehicleInfo, $totalSeats, $availableSeats, $pricePerPassenger, $ridePricingMode,
        $commissionPercentage, $commissionAmountPerSeat, $driverEarningPerSeat, $description,
        $pickupLat, $pickupLng, $destinationLat, $destinationLng, $ridePaymentPreference, $fareBreakdown,
    ]);

    $newRideId = $pdo->lastInsertId();
    $pdo->commit();
    transition_trip_state($pdo, $newRideId, 'PUBLISHED', 'MANUAL_DRIVER_ACTION');
    create_commission_reservation($pdo, $driverId, $newRideId, $requiredWalletReserve);
    track_referral_event($pdo, $driverId, 'FIRST_RIDE_CREATED');
    match_ride_requests($pdo, $newRideId);

    v1_success('Ride sharing session created successfully', [
        'ride_share_id' => $newRideId,
        'ride_public_id' => $ridePublicId,
        'price_per_passenger' => $pricePerPassenger,
        'total_seats' => $totalSeats,
        'commission_amount_per_seat' => $commissionAmountPerSeat,
        'driver_earning_per_seat' => $driverEarningPerSeat,
        'potential_revenue' => round($pricePerPassenger * $totalSeats, 2),
        'driver_expected_earnings' => round($driverEarningPerSeat * $totalSeats, 2),
        'commission_reserved' => $requiredWalletReserve,
        'breakdown' => $pricing['breakdown'],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error('Unable to create the trip right now.', 'create_ride_failed');
}
