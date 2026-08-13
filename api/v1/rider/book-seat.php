<?php
// POST /api/v1/rider/book-seat.php — reuses create_ride_booking() end
// to end (same wallet/payment/commission/validation as the web
// booking flow, no shortcuts, no second copy of the logic).
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/booking.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);

$input = v1_input();
$rideShareId = (int)($input['ride_share_id'] ?? 0);
if ($rideShareId <= 0) {
    v1_error('ride_share_id is required.', 'validation_error');
}

$result = create_ride_booking(
    $pdo,
    (int)$user['id'], // never trust a user_id from the request body — always the token's own user
    $rideShareId,
    max(1, (int)($input['seats_booked'] ?? 1)),
    $input['payment_method'] ?? null,
    isset($input['promo_code']) ? (string)$input['promo_code'] : ''
);

if ($result['status'] === 'success') {
    v1_success($result['message'], $result['data'] ?? []);
}
v1_error($result['message'], 'booking_failed');
