<?php
// api/fetch-offers.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'rider') {
    json_response('error', 'Unauthorized');
}

// This feature is part of the on-demand taxi model and is not used in the "Join Ride" (carpool) platform.
json_response('error', 'This feature is not available in the Join Ride model.');
?>
