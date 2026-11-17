<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized or invalid user type');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);
require_csrf($input);

$request_id = $input['request_id'] ?? null;
$status = $input['status'] ?? null;

if (!$request_id || !in_array($status, ['accepted', 'rejected'])) {
    json_response('error', 'Valid request ID and status are required');
}

$driver_id = $_SESSION['user_id'];

// Verify request belongs to this driver and is pending
$stmt = $pdo->prepare("SELECT id FROM ride_requests WHERE id = ? AND driver_id = ? AND status = 'pending'");
$stmt->execute([$request_id, $driver_id]);
if (!$stmt->fetch()) {
    json_response('error', 'Invalid or expired ride request');
}

// Update status
$stmt = $pdo->prepare("UPDATE ride_requests SET status = ? WHERE id = ?");
if ($stmt->execute([$status, $request_id])) {
    json_response('success', "Ride request $status successfully");
} else {
    json_response('error', 'Failed to update ride request status');
}
?>
