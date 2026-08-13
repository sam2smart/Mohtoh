<?php
// api/saved-locations.php
// Rider "Saved locations" (Home, Work, etc.) — CRUD against the new
// saved_locations table (database/migrate_saved_locations.php).
// Distinct from api/locations.php, which manages the shared,
// admin-curated location list, not a rider's private favorites.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = $input['action'] ?? $_GET['action'] ?? 'list';

if ($action === 'list') {
    $stmt = $pdo->prepare("SELECT public_id, label, address, latitude, longitude, created_at FROM saved_locations WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    json_response('success', 'Saved locations fetched', ['locations' => $stmt->fetchAll()]);
}

if ($action === 'add') {
    $label = sanitize_input($input['label'] ?? '');
    $address = sanitize_input($input['address'] ?? '');
    $latitude = $input['latitude'] ?? null;
    $longitude = $input['longitude'] ?? null;

    if ($label === '' || $address === '' || $latitude === null || $longitude === null) {
        json_response('error', 'Label, address, and location are all required.');
    }
    if (strlen($label) > 50) {
        json_response('error', 'Label must be 50 characters or fewer.');
    }

    $stmt = $pdo->prepare("SELECT id FROM saved_locations WHERE user_id = ? AND label = ?");
    $stmt->execute([$user_id, $label]);
    if ($stmt->fetch()) {
        json_response('error', 'You already have a saved location with that label.');
    }

    $publicId = generate_unique_public_id($pdo, 'saved_locations', 'public_id', 'SVL');
    $stmt = $pdo->prepare("INSERT INTO saved_locations (user_id, label, address, latitude, longitude, public_id) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $label, $address, $latitude, $longitude, $publicId]);

    json_response('success', 'Location saved.', ['public_id' => $publicId]);
}

if ($action === 'delete') {
    $publicId = $input['public_id'] ?? '';
    if ($publicId === '') {
        json_response('error', 'Missing location id.');
    }
    $stmt = $pdo->prepare("DELETE FROM saved_locations WHERE public_id = ? AND user_id = ?");
    $stmt->execute([$publicId, $user_id]);
    json_response('success', 'Location removed.');
}

json_response('error', 'Invalid action.');
