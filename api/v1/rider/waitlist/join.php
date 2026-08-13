<?php
// POST /api/v1/rider/waitlist/join.php
// "Waitlist" here is the Ride Queue system (a specific full ride —
// includes/ride_queue.php's join_queue()), per the Ride Demand System
// Restructure: it is NOT Ride Requests (passenger demand with no
// matching ride yet, api/v1/... — not part of this task's endpoint
// list, unchanged). The URL keeps the "waitlist" wording only because
// this task specified it verbatim as the mobile contract path.
require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../../../../includes/ride_queue.php';

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

$result = join_queue($pdo, (int)$user['id'], $rideShareId, max(1, (int)($input['seats_requested'] ?? 1)));
if ($result['status'] === 'success') {
    v1_success($result['message'], $result['data'] ?? []);
}
v1_error($result['message'], 'queue_join_failed');
