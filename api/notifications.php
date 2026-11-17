<?php
// api/notifications.php
// User-facing Notification Engine endpoint. get_unread/get_all/
// mark_as_read keep their original response shape exactly (every field
// that existed before is still there) — assets/js/navigation.js's
// pollNotifications() and the legacy consumers of this file keep
// working unchanged. Everything else here is additive: richer per-row
// fields (title, notification_type, priority, icon, color, action*),
// filters/search/pagination for get_all, and mark_one_read/delete/
// archive for the new bottom-sheet notification center UI.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];

// Shared SELECT list for both get_unread and get_all — one place, so
// the bottom sheet's row rendering never has to special-case which
// action returned it.
const NOTIF_ROW_COLUMNS = "public_id, title, message, notification_type, priority, icon, color, link, action, action_id, action_url, related_table, related_public_id, is_read, read_at, is_archived, created_at";

// Priority Management System — Display ordering: CRITICAL, then HIGH,
// then NORMAL, then LOW; newest-first within each tier. Matches
// notification_priority_catalog()'s rank order in includes/functions.php.
const NOTIF_PRIORITY_ORDER = "FIELD(priority, 'critical', 'high', 'normal', 'low')";

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'get_unread';

    if ($action === 'get_unread') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0");
        $stmt->execute([$user_id]);
        $unreadCount = (int)$stmt->fetchColumn();

        // Priority Management System: critical unread notifications get
        // their own flag so the mobile badge can show "!" instead of a
        // number — a locked account or failed payment shouldn't just
        // look like "3 more of the usual".
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0 AND is_deleted = 0 AND priority = 'critical'");
        $stmt->execute([$user_id]);
        $criticalUnread = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT " . NOTIF_ROW_COLUMNS . " FROM notifications WHERE user_id = ? AND is_deleted = 0 ORDER BY " . NOTIF_PRIORITY_ORDER . ", created_at DESC LIMIT 20");
        $stmt->execute([$user_id]);

        json_response('success', 'Unread notifications fetched', [
            'unread_count' => $unreadCount,
            'critical_unread' => $criticalUnread,
            'notifications' => $stmt->fetchAll(),
        ]);
    }

    if ($action === 'get_all') {
        // Filterable, searchable, paginated (infinite scroll: pass the
        // last row's `created_at` back as `before`) — same table,
        // same columns as get_unread, just a fuller query.
        $where = ['user_id = ?', 'is_deleted = 0'];
        $params = [$user_id];

        $status = $_GET['status'] ?? 'all'; // all | unread | read | archived
        if ($status === 'unread') {
            $where[] = 'is_read = 0';
        } elseif ($status === 'read') {
            $where[] = 'is_read = 1';
        } elseif ($status === 'archived') {
            $where[] = 'is_archived = 1';
        }
        if ($status !== 'archived') {
            // Archived items only show up under the explicit 'archived'
            // filter, never mixed into "all".
            $where[] = 'is_archived = 0';
        }

        if (!empty($_GET['type'])) {
            $where[] = 'notification_type = ?';
            $params[] = $_GET['type'];
        }
        if (!empty($_GET['priority'])) {
            $where[] = 'priority = ?';
            $params[] = $_GET['priority'];
        }
        if (!empty($_GET['search'])) {
            $where[] = '(message LIKE ? OR title LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        // Priority Management System: the list is sorted by priority
        // tier first (critical/high/normal/low), newest-first within
        // each tier — so the cursor for infinite scroll has to be the
        // same compound key, not just created_at, or a page boundary
        // that falls mid-tier would skip or repeat rows. `before` is
        // still the last row's created_at; `before_rank` is the same
        // row's FIELD(priority,...) rank (1-4) — both come back in
        // next_before/next_before_rank and must be passed together.
        if (!empty($_GET['before']) && isset($_GET['before_rank']) && $_GET['before_rank'] !== '') {
            $where[] = '(' . NOTIF_PRIORITY_ORDER . ' > ? OR (' . NOTIF_PRIORITY_ORDER . ' = ? AND created_at < ?))';
            $beforeRank = (int) $_GET['before_rank'];
            $params[] = $beforeRank;
            $params[] = $beforeRank;
            $params[] = $_GET['before'];
        } elseif (!empty($_GET['before'])) {
            // First page always includes before_rank; this fallback only
            // matters for any old cached client still sending the
            // pre-priority cursor shape.
            $where[] = 'created_at < ?';
            $params[] = $_GET['before'];
        }

        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $sql = "SELECT " . NOTIF_ROW_COLUMNS . " FROM notifications WHERE " . implode(' AND ', $where) . " ORDER BY " . NOTIF_PRIORITY_ORDER . ", created_at DESC LIMIT " . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $priorityRank = ['critical' => 1, 'high' => 2, 'normal' => 3, 'low' => 4];
        $lastRow = count($rows) ? end($rows) : null;
        json_response('success', 'Notifications fetched', [
            'notifications' => $rows,
            'has_more' => count($rows) === $limit,
            'next_before' => $lastRow ? $lastRow['created_at'] : null,
            'next_before_rank' => $lastRow ? ($priorityRank[$lastRow['priority']] ?? 3) : null,
        ]);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    require_csrf($input);
    $action = $input['action'] ?? '';

    if ($action === 'mark_as_read') {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$user_id]);
        json_response('success', 'Notifications marked as read');
    }

    if ($action === 'mark_one_read') {
        $publicId = $input['public_id'] ?? '';
        if ($publicId === '') {
            json_response('error', 'public_id is required');
        }
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = ? AND public_id = ?");
        $stmt->execute([$user_id, $publicId]);
        json_response('success', 'Notification marked as read');
    }

    if ($action === 'delete') {
        $publicId = $input['public_id'] ?? '';
        if ($publicId === '') {
            json_response('error', 'public_id is required');
        }
        // Soft delete — the row stays for admin's audit/delivery-status
        // view, it just stops appearing in this user's list.
        $stmt = $pdo->prepare("UPDATE notifications SET is_deleted = 1 WHERE user_id = ? AND public_id = ?");
        $stmt->execute([$user_id, $publicId]);
        json_response('success', 'Notification deleted');
    }

    if ($action === 'archive' || $action === 'unarchive') {
        $publicId = $input['public_id'] ?? '';
        if ($publicId === '') {
            json_response('error', 'public_id is required');
        }
        $stmt = $pdo->prepare("UPDATE notifications SET is_archived = ? WHERE user_id = ? AND public_id = ?");
        $stmt->execute([$action === 'archive' ? 1 : 0, $user_id, $publicId]);
        json_response('success', $action === 'archive' ? 'Notification archived' : 'Notification unarchived');
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
