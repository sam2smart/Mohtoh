<?php
// POST /api/v1/rider/waitlist/leave.php — Ride Queue (see join.php's
// header comment for the naming note).
require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../../../../includes/ride_queue.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);

$input = v1_input();
$queuePublicId = $input['queue_public_id'] ?? $input['waitlist_public_id'] ?? '';
if ($queuePublicId === '') {
    v1_error('queue_public_id is required.', 'validation_error');
}

$result = leave_queue($pdo, (int)$user['id'], $queuePublicId);
if ($result['status'] === 'success') {
    v1_success($result['message']);
}
v1_error($result['message'], 'queue_leave_failed');
