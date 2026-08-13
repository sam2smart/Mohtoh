<?php
// GET /api/v1/rider/notifications.php — same priority-ordered query as
// api/notifications.php's get_all action (critical→high→normal→low,
// newest first within each tier).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$userId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);
$priorityOrder = "FIELD(priority, 'critical', 'high', 'normal', 'low')";

$stmt = $pdo->prepare("
    SELECT public_id, title, message, notification_type, priority, icon, color, link, action, action_id, action_url, related_table, related_public_id, is_read, read_at, created_at
    FROM notifications
    WHERE user_id = ? AND is_deleted = 0
    ORDER BY {$priorityOrder}, created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_deleted = 0");
$countStmt->execute([$userId]);
$total = (int)$countStmt->fetchColumn();

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0");
$unreadStmt->execute([$userId]);

v1_success('Notifications fetched', [
    'notifications' => $rows,
    'unread_count' => (int)$unreadStmt->fetchColumn(),
], paginate_meta($page, $limit, $total));
