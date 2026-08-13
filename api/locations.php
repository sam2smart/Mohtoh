<?php
// api/locations.php
// MohToh's curated location database — the "known pickup points" half
// of the hybrid location system (the other half is the existing
// Nominatim-backed initAddressAutocomplete). Only approved locations
// are ever returned by search(); anyone can suggest a new one, but it
// starts pending and only becomes visible after admin approval
// (api/admin-api.php's approve_location/reject_location).

session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? 'search';

try {
    if ($action === 'search' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $query = sanitize_input(trim($_GET['q'] ?? ''));
        if (strlen($query) < 2) {
            json_response('success', 'Query too short', ['locations' => []]);
        }

        $stmt = $pdo->prepare("
            SELECT id, name, address, city, state, type, latitude, longitude
            FROM locations
            WHERE status = 'approved' AND name LIKE ?
            ORDER BY name ASC
            LIMIT 8
        ");
        $stmt->execute(['%' . $query . '%']);
        json_response('success', 'Locations found', ['locations' => $stmt->fetchAll()]);
    }

    if ($action === 'suggest' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = sanitize_input(trim($input['name'] ?? ''));
        $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
        $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
        $address = sanitize_input(trim($input['address'] ?? ''));
        $type = $input['type'] ?? 'popular_area';
        $allowedTypes = ['bus_stop', 'junction', 'landmark', 'motor_park', 'popular_area'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'popular_area';
        }

        if ($name === '' || strlen($name) < 3) {
            json_response('error', 'Please give this location a short, recognizable name.');
        }
        if (!$latitude || !$longitude) {
            json_response('error', 'A location must have coordinates — select it from the address suggestions first.');
        }

        $location_public_id = generate_unique_public_id($pdo, 'locations', 'public_id', 'LOC');
        $stmt = $pdo->prepare("
            INSERT INTO locations (public_id, name, latitude, longitude, address, type, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)
        ");
        $stmt->execute([$location_public_id, $name, $latitude, $longitude, $address, $type, (int)$_SESSION['user_id']]);

        json_response('success', 'Thanks! This location will appear for everyone once an admin reviews it.', [
            'location_id' => $pdo->lastInsertId(),
            'location_public_id' => $location_public_id
        ]);
    }

    json_response('error', 'Invalid action');
} catch (Exception $e) {
    json_response('error', 'Database error: ' . $e->getMessage());
}
