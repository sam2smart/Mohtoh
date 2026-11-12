<?php
// api/driver-settings.php
// Section 2 (Driver Settings) — per-driver mutations that don't belong
// in api/admin-api.php's existing approve/verify/suspend actions (kept
// as-is, not duplicated here) or in api/pricing-admin.php's global
// system_settings keys (min_wallet_balance, max_active_rides_per_driver,
// etc. — also unchanged, read from there). This endpoint covers:
// per-driver commission override, and bonus/referral-reward grants.
// Super-admin gated — these are money-affecting, same tier as pricing.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

require_once '../includes/admin_permissions.php';
// Access-Control: read access needs reports.view; the money-affecting POST
// mutations below keep their own require_super_admin($pdo) gate, so a Subadmin
// can never change commission overrides or grant bonuses/referral rewards.
require_admin_permission($pdo, 'reports.view');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_super_admin($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';
    $adminId = $_SESSION['user_id'];

    if ($action === 'set_custom_commission') {
        $driverId = (int)($input['driver_id'] ?? 0);
        if ($driverId <= 0) {
            json_response('error', 'Invalid driver.');
        }

        $stmt = $pdo->prepare("SELECT custom_commission_percentage FROM users WHERE id = ? AND user_type = 'driver'");
        $stmt->execute([$driverId]);
        $oldValue = $stmt->fetchColumn();
        if ($oldValue === false) {
            json_response('error', 'Driver not found.');
        }

        // Empty/absent clears the override back to the global rate.
        $rawValue = $input['custom_commission_percentage'] ?? '';
        $newValue = trim((string)$rawValue) === '' ? null : max(0, min(100, (float)$rawValue));

        $stmt = $pdo->prepare("UPDATE users SET custom_commission_percentage = ? WHERE id = ?");
        $stmt->execute([$newValue, $driverId]);

        log_pricing_change($pdo, $adminId, "driver_commission:{$driverId}", $oldValue, $newValue === null ? null : (string)$newValue);

        json_response('success', 'Driver commission updated.', ['custom_commission_percentage' => $newValue]);
    }

    if ($action === 'grant_bonus') {
        $driverId = (int)($input['driver_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        $type = ($input['type'] ?? 'bonus') === 'referral_reward' ? 'referral_reward' : 'bonus';
        $reason = sanitize_input(trim($input['reason'] ?? ''));

        if ($driverId <= 0 || $amount <= 0) {
            json_response('error', 'A driver and a positive amount are required.');
        }

        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND user_type = 'driver'");
        $stmt->execute([$driverId]);
        if (!$stmt->fetch()) {
            json_response('error', 'Driver not found.');
        }

        $pdo->beginTransaction();
        try {
            $newBalance = credit_wallet($pdo, $driverId, $amount, $type, $reason !== '' ? $reason : ucfirst(str_replace('_', ' ', $type)));
            if ($newBalance === false) {
                throw new Exception('Failed to credit wallet.');
            }

            $publicId = generate_unique_public_id($pdo, 'driver_bonuses', 'public_id', 'BNS');
            $stmt = $pdo->prepare("INSERT INTO driver_bonuses (public_id, driver_id, admin_id, type, amount, reason) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$publicId, $driverId, $adminId, $type, $amount, $reason]);

            notify_user($pdo, $driverId, "You received a " . ($type === 'referral_reward' ? 'referral reward' : 'bonus') . " of ₦{$amount}." . ($reason !== '' ? " Reason: {$reason}" : ''));

            $pdo->commit();
            json_response('success', 'Bonus granted.', ['public_id' => $publicId, 'new_balance' => $newBalance]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', 'Failed to grant bonus: ' . $e->getMessage());
        }
    }

    json_response('error', 'Invalid action.');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list_bonuses') {
    $stmt = $pdo->query("
        SELECT b.public_id, b.type, b.amount, b.reason, b.created_at, d.fullname AS driver_name, d.user_id AS driver_public_id
        FROM driver_bonuses b
        JOIN users d ON d.id = b.driver_id
        ORDER BY b.created_at DESC
        LIMIT 100
    ");
    json_response('success', 'Driver bonuses fetched', ['bonuses' => $stmt->fetchAll()]);
}

json_response('error', 'Invalid request method');
