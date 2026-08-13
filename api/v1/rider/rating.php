<?php
// POST /api/v1/rider/rating.php — same orchestration as
// api/rideshare.php's rate_ride action, reusing generate_unique_public_id()
// and notify_user()/create_notification() for the driver-facing notice.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$riderId = (int)$user['id'];

$input = v1_input();
$bookingId = (int)($input['booking_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0);
$comment = sanitize_input($input['comment'] ?? '');

if ($bookingId <= 0 || $rating < 1 || $rating > 5) {
    v1_error('A valid booking_id and a rating between 1 and 5 are required.', 'validation_error');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT b.ride_share_id, rs.driver_id FROM ride_share_bookings b JOIN ride_shares rs ON b.ride_share_id = rs.id WHERE b.id = ? AND b.rider_id = ? AND b.status = 'completed'");
    $stmt->execute([$bookingId, $riderId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new Exception('This booking cannot be rated. It might not be completed or does not belong to you.');
    }

    $stmt = $pdo->prepare("SELECT id FROM ride_share_ratings WHERE booking_id = ? AND rider_id = ?");
    $stmt->execute([$bookingId, $riderId]);
    if ($stmt->fetch()) {
        throw new Exception('You have already rated this ride.');
    }

    $ratingPublicId = generate_unique_public_id($pdo, 'ride_share_ratings', 'public_id', 'RATING');
    $pdo->prepare("INSERT INTO ride_share_ratings (public_id, ride_share_id, booking_id, rider_id, driver_id, rating, comment) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$ratingPublicId, $booking['ride_share_id'], $bookingId, $riderId, $booking['driver_id'], $rating, $comment]);

    $stmt = $pdo->prepare("SELECT AVG(rating) FROM ride_share_ratings WHERE driver_id = ?");
    $stmt->execute([$booking['driver_id']]);
    $avgRating = (float)$stmt->fetchColumn();
    $pdo->prepare("UPDATE users SET rating = ? WHERE id = ?")->execute([round($avgRating, 2), $booking['driver_id']]);

    $pdo->commit();

    $stmt = $pdo->prepare("SELECT notify_on_rating FROM users WHERE id = ?");
    $stmt->execute([$booking['driver_id']]);
    if ($stmt->fetchColumn()) {
        notify_user($pdo, $booking['driver_id'], "A rider gave you a {$rating}-star rating for a recent ride.", 'trips.php?tab=completed');
    }

    v1_success('Thank you for your feedback!');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error($e->getMessage(), 'rating_failed');
}
