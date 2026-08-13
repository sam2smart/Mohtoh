<?php
// api/passenger-request-details.php - Passenger request preview for drivers
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized');
}

$booking_id = (int)($_GET['booking_id'] ?? 0);
if ($booking_id <= 0) {
    json_response('error', 'Booking ID is required');
}

try {
    // Join ride_share_bookings with users (rider) and ride_shares to verify driver owns the ride
    $stmt = $pdo->prepare("
        SELECT rb.id, rb.rider_id, rb.seats_booked, rb.status,
               u.fullname, u.profile_image, u.rating, u.is_verified,
               (SELECT COUNT(*) FROM ride_share_bookings WHERE rider_id = u.id AND status = 'completed') as completed_trips,
               rs.destination,
               p.status as payment_status
        FROM ride_share_bookings rb
        JOIN users u ON rb.rider_id = u.id
        JOIN ride_shares rs ON rb.ride_share_id = rs.id
        LEFT JOIN payments p ON rb.id = p.booking_id
        WHERE rb.id = ? AND rs.driver_id = ?
    ");
    $stmt->execute([$booking_id, $_SESSION['user_id']]);
    $request = $stmt->fetch();

    if (!$request) {
        json_response('error', 'Request not found or unauthorized');
    }

    json_response('success', 'Passenger request details fetched', ['request' => $request]);
} catch (Exception $e) {
    json_response('error', 'Failed to retrieve request details: ' . $e->getMessage());
}
?>
