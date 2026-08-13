<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$data = json_decode(file_get_contents('php://input'), true);
$location = trim($data['location'] ?? '');
$lat = isset($data['lat']) ? (float)$data['lat'] : null;
$lng = isset($data['lng']) ? (float)$data['lng'] : null;
$userId = $_SESSION['user_id'];

if (empty($location)) {
    json_response('error', 'Invalid data');
}

try {
    $stmt = $pdo->prepare("
        INSERT INTO location_search_history (user_id, searched_location, latitude, longitude, usage_count)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE usage_count = usage_count + 1, last_used_at = CURRENT_TIMESTAMP
    ");
    $stmt->execute([$userId, $location, $lat, $lng]);
    json_response('success', 'Search logged');
} catch (Exception $e) {
    json_response('error', 'Failed to log search');
}
