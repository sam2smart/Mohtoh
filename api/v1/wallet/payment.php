<?php
// POST /api/v1/wallet/payment.php — driver confirms a direct (cash/
// off-wallet) passenger payment arrived. Same orchestration as
// api/rideshare.php's confirm_direct_payment action; the only real
// money movement is debit_wallet() (MohToh's commission on that fare) —
// wallet_balance is never written to directly.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

$input = v1_input();
$bookingId = (int)($input['booking_id'] ?? 0);
if ($bookingId <= 0) {
    v1_error('booking_id is required.', 'validation_error');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT p.id, p.ride_share_id, p.commission_amount, b.status AS booking_status FROM payments p JOIN ride_share_bookings b ON b.id = p.booking_id WHERE p.booking_id = ? AND p.driver_id = ? AND p.payment_method = 'direct' AND p.status = 'PAYMENT_PENDING' FOR UPDATE");
    $stmt->execute([$bookingId, $driverId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        throw new Exception('No pending direct payment found for this booking.');
    }
    if ($payment['booking_status'] !== 'completed') {
        throw new Exception('This trip must be completed before confirming payment.');
    }

    if ((float)$payment['commission_amount'] > 0) {
        $newBalance = debit_wallet($pdo, $driverId, (float)$payment['commission_amount'], 'COMMISSION_SETTLED', "MohToh commission on directly-paid booking #{$bookingId}", $payment['ride_share_id'], $bookingId);
        if ($newBalance === false) {
            throw new Exception("Your wallet balance cannot cover MohToh's commission on this payment. Please top up and try again.");
        }
    }

    $pdo->prepare("UPDATE payments SET status = 'PAYMENT_CONFIRMED', confirmed_at = NOW() WHERE id = ?")->execute([$payment['id']]);
    $pdo->commit();

    v1_success('Direct payment confirmed and commission settled.');
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error($e->getMessage(), 'payment_confirmation_failed');
}
