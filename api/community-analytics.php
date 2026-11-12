<?php
// api/community-analytics.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Access-Control: community analytics are read-only reports -> reports.view.
require_once '../includes/admin_permissions.php';
require_admin_permission($pdo, 'reports.view');

$action = $_GET['action'] ?? '';

if ($action === 'get_route_stats') {
    $route_id = (int)($_GET['route_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM community_learning_stats WHERE route_id = ?");
    $stmt->execute([$route_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    json_response('success', 'Stats retrieved', ['stats' => $stats]);
}
?>
