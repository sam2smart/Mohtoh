<?php
// POST /api/v1/driver/end-trip.php — same orchestration as
// api/rideshare.php's complete_ride action: transition_trip_state() and
// settle_ride_completion() do all the real state/financial work; the
// booking-sync SQL around them is thin bookkeeping, not a business rule.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

$input = v1_input();
$rideId = (int)($input['ride_id'] ?? 0);
if ($rideId <= 0) {
    v1_error('ride_id is required.', 'validation_error');
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT trip_state FROM ride_shares WHERE id = ? AND driver_id = ? FOR UPDATE");
    $stmt->execute([$rideId, $driverId]);
    $wasAutoPending = $stmt->fetchColumn() === 'AUTO_COMPLETED_PENDING';

    $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'completed' WHERE id = ? AND driver_id = ? AND status = 'in_progress'");
    $stmt->execute([$rideId, $driverId]);
    if ($stmt->rowCount() === 0) {
        throw new Exception('Could not complete ride. It may not be in progress.');
    }

    $pdo->prepare("UPDATE ride_share_bookings SET status = 'completed' WHERE ride_share_id = ? AND status != 'cancelled'")->execute([$rideId]);
    $pdo->prepare("UPDATE ride_share_bookings SET passenger_state = 'completed', passenger_state_updated_at = NOW() WHERE ride_share_id = ? AND status = 'completed' AND passenger_state NOT IN ('completed', 'no_show')")->execute([$rideId]);
    transition_trip_state($pdo, $rideId, 'TRIP_COMPLETED', 'MANUAL_DRIVER_ACTION');
    if ($wasAutoPending) {
        $pdo->prepare("UPDATE ride_shares SET auto_completed = 1 WHERE id = ?")->execute([$rideId]);
    }

    $driverEarningCredited = settle_ride_completion($pdo, $rideId);
    $pdo->commit();

    v1_success('Ride completed successfully.', ['driver_earning_credited' => $driverEarningCredited]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error($e->getMessage(), 'end_trip_failed');
}
