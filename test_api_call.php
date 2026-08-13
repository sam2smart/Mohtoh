<?php
// Simulate an API call to get_recommended_rides
$_GET['action'] = 'get_recommended_rides';
// Provide some mock coordinates
$_GET['lat'] = 6.5;
$_GET['lng'] = 3.3;

// We need a session, so start one
session_start();
// Mock user session
$_SESSION['user_id'] = 1; // Assuming a valid user ID
$_SESSION['user_type'] = 'rider';

// Include the API file
// Use output buffering to capture the JSON response
ob_start();
require 'api/rideshare.php';
$output = ob_get_clean();

echo "Output from api/rideshare.php:\n";
echo $output;
?>
