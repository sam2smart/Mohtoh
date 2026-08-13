<?php
// api/request-ride.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/FareCalculator.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'rider') {
    json_response('error', 'Unauthorized or invalid user type');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

// This feature is part of the on-demand taxi model and is not used in the "Join Ride" (carpool) platform.
// Riders search for and book pre-planned rides from drivers.
json_response('error', 'This feature is not available in the Join Ride model.');
?>
