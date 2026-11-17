<?php
// api/driver-comms.php
// Section 8 (Driver Communication) — targeted, single-driver messages:
// announcement/maintenance/promo/warning. Broadcast announcements
// already exist as api/admin-api.php's send_announcement action (now
// also calling notify_user() — not duplicated here). Suspend/restore
// reuse the existing suspend_user/unsuspend_user actions in
// api/admin-api.php, also not duplicated here — this file is only the
// new targeted-message capability. Regular admin access (not
// super-admin) — sending a message isn't a money-affecting action.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    require_csrf($input);
    $action = $input['action'] ?? '';

    if ($action === 'send_targeted') {
        $driverId = (int)($input['driver_id'] ?? 0);
        $type = $input['type'] ?? 'notice';
        $allowedTypes = ['announcement', 'maintenance', 'promo', 'warning'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'announcement';
        }
        $message = sanitize_input(trim($input['message'] ?? ''));

        if ($driverId <= 0 || $message === '') {
            json_response('error', 'A driver and a message are required.');
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND user_type = 'driver'");
        $stmt->execute([$driverId]);
        if (!$stmt->fetch()) {
            json_response('error', 'Driver not found.');
        }

        notify_user($pdo, $driverId, $message);

        // Warnings additionally join the driver's audit history
        // (admin_user_actions), same table admin-api.php's
        // suspend/unsuspend already write to — visible from
        // user_profile_reviews alongside every other action taken
        // against that driver.
        if ($type === 'warning') {
            $publicId = generate_unique_public_id($pdo, 'admin_user_actions', 'public_id', 'ACT');
            $stmt = $pdo->prepare("INSERT INTO admin_user_actions (public_id, user_id, admin_id, action, reason) VALUES (?, ?, ?, 'warning', ?)");
            $stmt->execute([$publicId, $driverId, $_SESSION['user_id'], $message]);
        }

        json_response('success', 'Message sent.');
    }

    json_response('error', 'Invalid action.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list_drivers') {
    // Small helper for the compose UI's driver picker — name + public id only.
    $stmt = $pdo->query("SELECT id, user_id, fullname FROM users WHERE user_type = 'driver' AND is_suspended = 0 ORDER BY fullname ASC");
    json_response('success', 'Drivers fetched', ['drivers' => $stmt->fetchAll()]);
}

json_response('error', 'Invalid request method');
