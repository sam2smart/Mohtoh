<?php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized');
}

// This feature is part of the on-demand taxi model and is not used in the "Join Ride" (carpool) platform.
// Drivers manage their own published rides, they do not browse for rider requests.
json_response('success', 'No open requests available in this model.', [
    'open_requests' => [],
    'active_ride' => null
]);
?>
