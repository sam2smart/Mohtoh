<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/CommunityFareService.php';
require_once '../includes/FareRecommendationEngine.php';

header('Content-Type: application/json');

if (!is_logged_in()) json_response('error', 'Unauthorized');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$service = new CommunityFareService($pdo);
$engine = new FareRecommendationEngine($pdo);

if ($action === 'get_route_fare') {
    $community = sanitize_input($_GET['community'] ?? '');
    $route = sanitize_input($_GET['route'] ?? '');
    $vehicle_type = sanitize_input($_GET['vehicle_type'] ?? 'keke');

    $fare = $service->getRecommendedFare($community, $route, $vehicle_type);
    if (!$fare) {
        $recommendation = $engine->calculateRecommendation($community, $route);
        json_response('success', 'Recommendation generated', ['fare' => $recommendation, 'source' => 'engine']);
    }
    json_response('success', 'Fare found', ['fare' => $fare, 'source' => 'community']);

} elseif ($action === 'submit_driver_fare' && $_SESSION['user_type'] === 'driver') {
    // Check verification
    $stmt = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!(bool)$stmt->fetchColumn()) json_response('error', 'Only verified drivers can submit fares');

    $community = sanitize_input($_POST['community'] ?? '');
    $route = sanitize_input($_POST['route'] ?? '');
    $fare = (float)($_POST['fare'] ?? 0);
    $service->submitSuggestion($_SESSION['user_id'], $community, $route, $fare);
    json_response('success', 'Suggestion submitted');
}
?>
