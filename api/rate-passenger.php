<?php
// api/rate-passenger.php - Driver rates passenger after ride completion

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);

$request_id = (int)($input['request_id'] ?? 0);
$rider_id = (int)($input['rider_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0);
$comment = sanitize_input($input['comment'] ?? '');

if ($request_id <= 0 || $rider_id <= 0 || $rating < 1 || $rating > 5) {
    json_response('error', 'Invalid parameters. Rating must be 1-5');
}

$driver_id = $_SESSION['user_id'];

try {
    // Check if ride request exists and is completed
    $stmt = $pdo->prepare("
        SELECT id, status FROM ride_requests 
        WHERE id = ? AND driver_id = ? AND status = 'completed'
    ");
    $stmt->execute([$request_id, $driver_id]);
    $ride = $stmt->fetch();

    if (!$ride) {
        json_response('error', 'Ride not found or not completed');
    }

    // Check if already rated
    $stmt = $pdo->prepare("
        SELECT id FROM passenger_ratings 
        WHERE request_id = ? AND driver_id = ?
    ");
    $stmt->execute([$request_id, $driver_id]);
    if ($stmt->fetch()) {
        json_response('error', 'You have already rated this passenger for this ride');
    }

    // Insert rating
    $rating_public_id = generate_unique_public_id($pdo, 'passenger_ratings', 'public_id', 'PRT');
    $stmt = $pdo->prepare("
        INSERT INTO passenger_ratings (public_id, request_id, driver_id, rider_id, rating, punctuality, behavior, communication, comment)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // For simplicity, use the overall rating for all subcategories
    if ($stmt->execute([$rating_public_id, $request_id, $driver_id, $rider_id, $rating, $rating, $rating, $rating, $comment])) {
        json_response('success', 'Passenger rated successfully', [
            'rating_id' => $pdo->lastInsertId(),
            'rating_public_id' => $rating_public_id
        ]);
    } else {
        json_response('error', 'Failed to submit rating');
    }

} catch (Exception $e) {
    json_response('error', 'Database error: ' . $e->getMessage());
}
?>
