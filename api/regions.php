<?php
// api/regions.php
// Section 5 (Regional Pricing) CRUD. Super-admin gated for writes — same
// tier that guards api/pricing-admin.php, since a region's price_per_km/
// base_fare/commission_percentage overrides are just as money-affecting
// as the global settings. GET is admin-only (not super-admin) so the
// regular Drivers/Vehicles/etc. admin tabs could reference region names
// if needed later, without granting write access.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("SELECT id, public_id, name, center_lat, center_lng, radius_km, price_per_km, base_fare, commission_percentage, is_active, created_at FROM regions ORDER BY name ASC");
    json_response('success', 'Regions fetched', ['regions' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_super_admin($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';
    $adminId = $_SESSION['user_id'];

    if ($action === 'create') {
        $name = sanitize_input(trim($input['name'] ?? ''));
        $centerLat = isset($input['center_lat']) ? (float)$input['center_lat'] : null;
        $centerLng = isset($input['center_lng']) ? (float)$input['center_lng'] : null;
        $radiusKm = isset($input['radius_km']) ? (float)$input['radius_km'] : null;

        if ($name === '' || $centerLat === null || $centerLng === null || !$radiusKm || $radiusKm <= 0) {
            json_response('error', 'Name, center coordinates, and a positive radius are required.');
        }

        $pricePerKm = isset($input['price_per_km']) && trim((string)$input['price_per_km']) !== '' ? (float)$input['price_per_km'] : null;
        $baseFare = isset($input['base_fare']) && trim((string)$input['base_fare']) !== '' ? (float)$input['base_fare'] : null;
        $commissionPercentage = isset($input['commission_percentage']) && trim((string)$input['commission_percentage']) !== '' ? (float)$input['commission_percentage'] : null;

        $publicId = generate_unique_public_id($pdo, 'regions', 'public_id', 'REG');
        $stmt = $pdo->prepare("INSERT INTO regions (public_id, name, center_lat, center_lng, radius_km, price_per_km, base_fare, commission_percentage) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$publicId, $name, $centerLat, $centerLng, $radiusKm, $pricePerKm, $baseFare, $commissionPercentage]);

        log_pricing_change($pdo, $adminId, "region_created:{$publicId}", null, json_encode(['name' => $name, 'price_per_km' => $pricePerKm, 'base_fare' => $baseFare, 'commission_percentage' => $commissionPercentage]));

        json_response('success', 'Region created.', ['public_id' => $publicId]);
    }

    if ($action === 'update') {
        $regionId = (int)($input['id'] ?? 0);
        if ($regionId <= 0) {
            json_response('error', 'Invalid region.');
        }

        $stmt = $pdo->prepare("SELECT * FROM regions WHERE id = ?");
        $stmt->execute([$regionId]);
        $old = $stmt->fetch();
        if (!$old) {
            json_response('error', 'Region not found.');
        }

        $name = sanitize_input(trim($input['name'] ?? $old['name']));
        $centerLat = isset($input['center_lat']) ? (float)$input['center_lat'] : (float)$old['center_lat'];
        $centerLng = isset($input['center_lng']) ? (float)$input['center_lng'] : (float)$old['center_lng'];
        $radiusKm = isset($input['radius_km']) ? (float)$input['radius_km'] : (float)$old['radius_km'];
        $pricePerKm = isset($input['price_per_km']) && trim((string)$input['price_per_km']) !== '' ? (float)$input['price_per_km'] : null;
        $baseFare = isset($input['base_fare']) && trim((string)$input['base_fare']) !== '' ? (float)$input['base_fare'] : null;
        $commissionPercentage = isset($input['commission_percentage']) && trim((string)$input['commission_percentage']) !== '' ? (float)$input['commission_percentage'] : null;
        $isActive = isset($input['is_active']) ? (int)(bool)$input['is_active'] : (int)$old['is_active'];

        $stmt = $pdo->prepare("UPDATE regions SET name = ?, center_lat = ?, center_lng = ?, radius_km = ?, price_per_km = ?, base_fare = ?, commission_percentage = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $centerLat, $centerLng, $radiusKm, $pricePerKm, $baseFare, $commissionPercentage, $isActive, $regionId]);

        log_pricing_change($pdo, $adminId, "region_updated:{$old['public_id']}", json_encode($old), json_encode(['name' => $name, 'price_per_km' => $pricePerKm, 'base_fare' => $baseFare, 'commission_percentage' => $commissionPercentage, 'is_active' => $isActive]));

        json_response('success', 'Region updated.');
    }

    if ($action === 'delete') {
        $regionId = (int)($input['id'] ?? 0);
        if ($regionId <= 0) {
            json_response('error', 'Invalid region.');
        }

        $stmt = $pdo->prepare("SELECT public_id, name FROM regions WHERE id = ?");
        $stmt->execute([$regionId]);
        $old = $stmt->fetch();
        if (!$old) {
            json_response('error', 'Region not found.');
        }

        $pdo->prepare("DELETE FROM regions WHERE id = ?")->execute([$regionId]);
        log_pricing_change($pdo, $adminId, "region_deleted:{$old['public_id']}", $old['name'], null);

        json_response('success', 'Region deleted.');
    }

    json_response('error', 'Invalid action.');
}

json_response('error', 'Invalid request method');
