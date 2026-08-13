<?php
// api/community-analytics.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Only allow admin
if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized');
}

$action = $_GET['action'] ?? '';

if ($action === 'get_route_stats') {
    $route_id = (int)($_GET['route_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM community_learning_stats WHERE route_id = ?");
    $stmt->execute([$route_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    json_response('success', 'Stats retrieved', ['stats' => $stats]);
}
?>
