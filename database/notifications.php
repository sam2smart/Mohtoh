<?php
// api/notifications.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? 'get_all';

if ($action === 'get_unread') {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    $unread_count = $stmt->fetchColumn();

    json_response('success', 'Unread notifications fetched', [
        'notifications' => $notifications,
        'unread_count' => (int)$unread_count
    ]);
} elseif ($action === 'get_all') {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$user_id]);
    $notifications = $stmt->fetchAll();
    json_response('success', 'All notifications fetched', [
        'notifications' => $notifications
    ]);
} elseif ($action === 'mark_as_read') {
    $notification_id = (int)($input['notification_id'] ?? 0);
    
    if ($notification_id > 0) {
        // Mark a specific notification as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$notification_id, $user_id]);
    } else {
        // Mark all as read
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$user_id]);
    }
    
    json_response('success', 'Notifications marked as read');
} else {
    json_response('error', 'Invalid action');
}
?>