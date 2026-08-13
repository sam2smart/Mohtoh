<?php
// api/submit-offer.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized or invalid user type');
}

// This feature is part of the on-demand taxi model and is not used in the "Join Ride" (carpool) platform.
// Drivers publish rides via the rideshare API, they do not bid on individual requests.
json_response('error', 'This feature is not available in the Join Ride model.');
?>
