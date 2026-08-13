<?php
// api/vehicle-info.php - Get vehicle details for showing to riders

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response('error', 'Invalid request method');
}

$driver_id = (int)($_GET['driver_id'] ?? 0);

if ($driver_id <= 0) {
    json_response('error', 'Driver ID required');
}

try {
    // Get vehicle info
    $stmt = $pdo->prepare("
        SELECT id, driver_id, make, model, year, color, plate_number, ac_available, 
               cleanliness, comfort_level, seats, luggage_capacity, status, admin_rating,
               vehicle_type, passenger_capacity, verification_status
        FROM vehicles 
        WHERE driver_id = ?
    ");
    $stmt->execute([$driver_id]);
    $vehicle = $stmt->fetch();

    if (!$vehicle) {
        json_response('success', 'No vehicle registered for this driver', ['vehicle' => null]);
    }

    // Get vehicle photos
    $stmt = $pdo->prepare("SELECT id, photo_path, photo_type FROM vehicle_photos WHERE vehicle_id = ? LIMIT 5");
    $stmt->execute([$vehicle['id']]);
    $photos = $stmt->fetchAll();

    // Get average rating if available
    $stmt = $pdo->prepare("
        SELECT AVG(rating) as avg_rating, COUNT(*) as total_ratings
        FROM driver_ratings
        WHERE driver_id = ?
    ");
    $stmt->execute([$driver_id]);
    $ratingData = $stmt->fetch();

    json_response('success', 'Vehicle information retrieved', [
        'vehicle' => $vehicle,
        'photos' => $photos,
        'driver_rating' => [
            'avg_rating' => $ratingData['avg_rating'] ? (float)$ratingData['avg_rating'] : 5.0,
            'total_ratings' => (int)$ratingData['total_ratings']
        ]
    ]);

} catch (Exception $e) {
    json_response('error', 'Failed to retrieve vehicle info: ' . $e->getMessage());
}
?>
