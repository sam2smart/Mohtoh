<?php
// api/rate-driver.php - Rider rates driver after ride completion

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'rider') {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

$input = json_decode(file_get_contents('php://input'), true);
require_csrf($input);

$request_id = (int)($input['request_id'] ?? 0);
$driver_id = (int)($input['driver_id'] ?? 0);
$rating = (int)($input['rating'] ?? 0);
$comment = sanitize_input($input['comment'] ?? '');
$vehicle_claims_match = !empty($input['vehicle_claims_match']) ? 1 : 0;
$driver_lied = !empty($input['driver_lied']) ? 1 : 0;

if ($request_id <= 0 || $driver_id <= 0 || $rating < 1 || $rating > 5) {
    json_response('error', 'Invalid parameters. Rating must be 1-5');
}

$rider_id = $_SESSION['user_id'];

try {
    // Check if ride request exists and is completed
    $stmt = $pdo->prepare("
        SELECT id, status FROM ride_requests 
        WHERE id = ? AND rider_id = ? AND driver_id = ? AND status = 'completed'
    ");
    $stmt->execute([$request_id, $rider_id, $driver_id]);
    $ride = $stmt->fetch();

    if (!$ride) {
        json_response('error', 'Ride not found or not completed');
    }

    // Check if already rated
    $stmt = $pdo->prepare("
        SELECT id FROM driver_ratings 
        WHERE request_id = ? AND rider_id = ?
    ");
    $stmt->execute([$request_id, $rider_id]);
    if ($stmt->fetch()) {
        json_response('error', 'You have already rated this driver for this ride');
    }

    // Insert rating
    $hasVehicleClaimsColumn = false;
    $hasDriverLiedColumn = false;
    try {
        $check = $pdo->prepare("SHOW COLUMNS FROM driver_ratings LIKE ?");
        $check->execute(['vehicle_claims_match']);
        $hasVehicleClaimsColumn = (bool)$check->fetchColumn();
        $check->execute(['driver_lied']);
        $hasDriverLiedColumn = (bool)$check->fetchColumn();
    } catch (Exception $e) {}

    $rating_public_id = generate_unique_public_id($pdo, 'driver_ratings', 'public_id', 'DRT');
    if ($hasVehicleClaimsColumn && $hasDriverLiedColumn) {
        $stmt = $pdo->prepare("
            INSERT INTO driver_ratings (public_id, request_id, driver_id, rider_id, rating, driving_quality, professionalism, cleanliness, vehicle_claims_match, driver_lied, comment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $params = [$rating_public_id, $request_id, $driver_id, $rider_id, $rating, $rating, $rating, $rating, $vehicle_claims_match, $driver_lied, $comment];
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO driver_ratings (public_id, request_id, driver_id, rider_id, rating, driving_quality, professionalism, cleanliness, comment)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $params = [$rating_public_id, $request_id, $driver_id, $rider_id, $rating, $rating, $rating, $rating, $comment];
    }
    
    // For simplicity, use the overall rating for all subcategories
    if ($stmt->execute($params)) {
        // Update driver's average rating
        $stmt = $pdo->prepare("
            SELECT AVG(rating) as avg_rating FROM driver_ratings WHERE driver_id = ?
        ");
        $stmt->execute([$driver_id]);
        $avgRating = $stmt->fetch()['avg_rating'];

        // Update user rating
        $stmt = $pdo->prepare("UPDATE users SET rating = ? WHERE id = ?");
        $stmt->execute([round($avgRating, 2), $driver_id]);

        json_response('success', 'Driver rated successfully', [
            'rating_id' => $pdo->lastInsertId(),
            'rating_public_id' => $rating_public_id,
            'driver_updated_rating' => round($avgRating, 2)
        ]);
    } else {
        json_response('error', 'Failed to submit rating');
    }

} catch (Exception $e) {
    json_response('error', 'Database error: ' . $e->getMessage());
}
?>
