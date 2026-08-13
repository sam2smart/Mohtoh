<?php
// POST /api/v1/rider/cancel-booking.php — same orchestration as
// api/rideshare.php's cancel_booking action: refunds via credit_wallet()
// (never touches wallet_balance directly), then process_queue() to
// notify the Ride Queue, reusing the real functions throughout.
require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/../../../includes/chat.php';
require_once __DIR__ . '/../../../includes/ride_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$riderId = (int)$user['id'];

$input = v1_input();
$bookingId = (int)($input['booking_id'] ?? 0);
if ($bookingId <= 0) {
    v1_error('booking_id is required.', 'validation_error');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT ride_share_id, seats_booked, rider_id, payment_method FROM ride_share_bookings WHERE id = ? AND rider_id = ? AND status NOT IN ('cancelled', 'completed') FOR UPDATE");
    $stmt->execute([$bookingId, $riderId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        throw new Exception('Active booking not found or you do not have permission to cancel it.');
    }

    $stmt = $pdo->prepare("SELECT price_per_passenger FROM ride_shares WHERE id = ?");
    $stmt->execute([$booking['ride_share_id']]);
    $refundAmount = (float)$stmt->fetchColumn() * (int)$booking['seats_booked'];

    $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled', passenger_state = 'cancelled', passenger_state_updated_at = NOW() WHERE id = ?")->execute([$bookingId]);
    $pdo->prepare("UPDATE ride_shares SET available_seats = available_seats + ?, status = 'active' WHERE id = ?")->execute([(int)$booking['seats_booked'], $booking['ride_share_id']]);

    if ($booking['payment_method'] === 'wallet' && $refundAmount > 0) {
        credit_wallet($pdo, $booking['rider_id'], $refundAmount, 'ride_refund', "Refund for cancelled booking of ride #{$booking['ride_share_id']}", $booking['ride_share_id'], $bookingId);
    } else {
        $pdo->prepare("UPDATE payments SET status = 'PAYMENT_FAILED', updated_at = NOW() WHERE booking_id = ? AND status = 'PAYMENT_PENDING'")->execute([$bookingId]);
    }

    $pdo->commit();

    $chatConversationId = find_ride_conversation($pdo, $booking['ride_share_id']);
    if ($chatConversationId) {
        post_system_message($pdo, $chatConversationId, ($user['fullname'] ?? 'A passenger') . ' left the ride.');
        remove_conversation_member($pdo, $chatConversationId, $riderId);
    }

    process_queue($pdo, $booking['ride_share_id']);

    v1_success('Booking cancelled successfully.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error($e->getMessage(), 'cancel_failed');
}
