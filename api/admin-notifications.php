<?php
// api/admin-notifications.php
// Admin Notification Management — view/search/filter every
// notification in the system, browadcast history, resend/delete, and
// the System Settings the Notification Engine reads
// (includes/functions.php: get_system_setting()). Broadcast SENDING
// stays in api/admin-api.php's send_announcement action (already
// existed there before this module) — not duplicated here.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized');
}

// Every notification-related setting the Admin Notification Center
// exposes, with its default — single source of truth for both the
// GET (what to show) and POST (what's allowed to be written) sides.
const NOTIFICATION_SETTING_DEFAULTS = [
    'notify_category_ride_enabled' => '1',
    'notify_category_booking_enabled' => '1',
    'notify_category_trip_enabled' => '1',
    'notify_category_wallet_enabled' => '1',
    'notify_category_payment_enabled' => '1',
    'notify_category_verification_enabled' => '1',
    'notify_category_vehicle_enabled' => '1',
    'notify_category_admin_enabled' => '1',
    'notify_category_promotion_enabled' => '1',
    'notify_category_system_enabled' => '1',
    'notify_category_support_enabled' => '1',
    'notify_category_premium_enabled' => '1',
    'notify_category_gps_enabled' => '1',
    'notify_channel_email_enabled' => '0',
    'notify_channel_sms_enabled' => '0',
    'notify_channel_in_app_enabled' => '1',
    'notify_channel_browser_enabled' => '0',
    'notification_retention_days' => '90',
    'notification_polling_interval_seconds' => '30',
    'notification_max_per_user' => '500',
    'notification_auto_delete_old' => '1',
    'nearby_rider_notification_radius_km' => '10',
    'nearby_rider_notification_max' => '25',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $where = ['n.is_deleted = 0'];
        $params = [];

        if (!empty($_GET['user_type'])) {
            $where[] = 'n.user_type = ?';
            $params[] = $_GET['user_type'];
        }
        if (!empty($_GET['notification_type'])) {
            $where[] = 'n.notification_type = ?';
            $params[] = $_GET['notification_type'];
        }
        if (!empty($_GET['priority'])) {
            $where[] = 'n.priority = ?';
            $params[] = $_GET['priority'];
        }
        if (!empty($_GET['status'])) {
            if ($_GET['status'] === 'read') $where[] = 'n.is_read = 1';
            elseif ($_GET['status'] === 'unread') $where[] = 'n.is_read = 0';
        }
        if (!empty($_GET['delivery_status'])) {
            $where[] = 'n.status = ?';
            $params[] = $_GET['delivery_status'];
        }
        if (!empty($_GET['date_from'])) {
            $where[] = 'n.created_at >= ?';
            $params[] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'n.created_at <= ?';
            $params[] = $_GET['date_to'] . ' 23:59:59';
        }
        if (!empty($_GET['search'])) {
            $where[] = '(n.message LIKE ? OR n.title LIKE ? OR u.fullname LIKE ? OR u.email LIKE ?)';
            $like = '%' . $_GET['search'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        // Part 3 (Universal Pagination System) — standard page/limit
        // params + envelope, same paginate_params()/paginate_meta() every
        // other list endpoint in the app now uses.
        [$page, $limit, $offset] = paginate_params();

        $sql = "SELECT n.public_id, n.user_id, u.fullname AS user_name, u.email AS user_email, n.user_type, n.title, n.message,
                       n.notification_type, n.priority, n.is_read, n.status, n.delivery_method, n.created_at
                FROM notifications n
                LEFT JOIN users u ON u.id = n.user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY n.created_at DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications n LEFT JOIN users u ON u.id = n.user_id WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        json_response('success', 'Notifications fetched', ['notifications' => $rows], paginate_meta($page, $limit, $total));
    }

    if ($action === 'get_broadcasts') {
        $stmt = $pdo->query("
            SELECT b.public_id, b.target_type, b.title, b.message, b.notification_type, b.priority, b.recipient_count,
                   b.scheduled_at, b.sent_at, b.status, b.created_at, u.fullname AS admin_name
            FROM notification_broadcasts b
            LEFT JOIN users u ON u.id = b.admin_id
            ORDER BY b.created_at DESC
            LIMIT 100
        ");
        json_response('success', 'Broadcasts fetched', ['broadcasts' => $stmt->fetchAll()]);
    }

    if ($action === 'search_users') {
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            json_response('success', 'Users fetched', ['users' => []]);
        }
        $stmt = $pdo->prepare("SELECT id, fullname, email, user_type FROM users WHERE (fullname LIKE ? OR email LIKE ?) AND user_type IN ('driver', 'rider') AND is_suspended = 0 ORDER BY fullname ASC LIMIT 20");
        $like = '%' . $q . '%';
        $stmt->execute([$like, $like]);
        json_response('success', 'Users fetched', ['users' => $stmt->fetchAll()]);
    }

    if ($action === 'get_settings') {
        $settings = [];
        foreach (NOTIFICATION_SETTING_DEFAULTS as $key => $default) {
            $settings[$key] = get_system_setting($pdo, $key, $default);
        }
        json_response('success', 'Settings fetched', ['settings' => $settings]);
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

    if ($action === 'delete') {
        $publicId = $input['public_id'] ?? '';
        if ($publicId === '') {
            json_response('error', 'public_id is required');
        }
        $stmt = $pdo->prepare("UPDATE notifications SET is_deleted = 1 WHERE public_id = ?");
        $stmt->execute([$publicId]);
        json_response('success', 'Notification deleted');
    }

    if ($action === 'resend') {
        $publicId = $input['public_id'] ?? '';
        if ($publicId === '') {
            json_response('error', 'public_id is required');
        }
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE public_id = ?");
        $stmt->execute([$publicId]);
        $original = $stmt->fetch();
        if (!$original) {
            json_response('error', 'Notification not found');
        }
        $newId = create_notification($pdo, $original['user_id'], [
            'title' => $original['title'],
            'message' => $original['message'],
            'notification_type' => $original['notification_type'],
            'priority' => $original['priority'],
            'action_url' => $original['action_url'] ?: $original['link'],
            'related_table' => $original['related_table'],
            'related_public_id' => $original['related_public_id'],
            'created_by' => (int)$_SESSION['user_id'],
            'force' => true,
        ]);
        if ($newId === null) {
            json_response('error', 'Could not resend — recipient no longer exists.');
        }
        json_response('success', 'Notification resent', ['public_id' => $newId]);
    }

    if ($action === 'update_settings') {
        $settings = $input['settings'] ?? [];
        if (!is_array($settings) || empty($settings)) {
            json_response('error', 'No settings provided');
        }
        $changed = [];
        foreach ($settings as $key => $value) {
            if (!array_key_exists($key, NOTIFICATION_SETTING_DEFAULTS)) {
                continue; // whitelist — same guard pattern as pricing-admin.php's PRICING_SETTING_KEYS
            }
            $stmt = $pdo->prepare("
                INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $stmt->execute([$key, (string)$value]);
            $changed[] = $key;
        }
        json_response('success', 'Settings updated', ['changed' => $changed]);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
