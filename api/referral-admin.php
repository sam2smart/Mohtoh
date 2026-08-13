<?php
// api/referral-admin.php
// Admin configuration for MohToh Community Rewards. Exact shape of
// api/pricing-admin.php: whitelist of system_settings keys, GET is
// open to any logged-in admin, POST is require_super_admin()-gated and
// diff-logged. Reuses pricing_change_logs (already a generic
// admin_id/setting_key/old_value/new_value/ip audit trail, not a
// pricing-specific one) via the existing log_pricing_change() rather
// than adding a near-duplicate log table/function for this feature.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

const REFERRAL_SETTING_KEYS = [
    'referral_system_enabled',
    'referral_rider_reward_amount',
    'referral_driver_reward_amount',
    'referral_new_rider_reward_amount',
    'referral_new_driver_reward_amount',
    'referral_max_rewards_per_user',
    'referral_reward_expiry_days',
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $placeholders = implode(',', array_fill(0, count(REFERRAL_SETTING_KEYS), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute(REFERRAL_SETTING_KEYS);
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    // Sensible defaults so the admin form always shows a real value,
    // matching what includes/referral.php's calculate_referral_reward()
    // falls back to when a key has never been saved.
    $defaults = [
        'referral_system_enabled' => '1',
        'referral_rider_reward_amount' => '0',
        'referral_driver_reward_amount' => '0',
        'referral_new_rider_reward_amount' => '0',
        'referral_new_driver_reward_amount' => '0',
        'referral_max_rewards_per_user' => '0',
        'referral_reward_expiry_days' => '30',
    ];
    foreach ($defaults as $key => $default) {
        if (!isset($settings[$key])) {
            $settings[$key] = $default;
        }
    }

    json_response('success', 'Referral settings fetched', ['settings' => $settings]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_super_admin($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $adminId = $_SESSION['user_id'];
    $changed = [];

    $pdo->beginTransaction();
    try {
        if (isset($input['settings']) && is_array($input['settings'])) {
            foreach ($input['settings'] as $key => $value) {
                if (!in_array($key, REFERRAL_SETTING_KEYS, true)) {
                    continue; // silently skip unknown keys rather than error on a partial save
                }
                $value = trim((string)$value);

                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $oldValue = $stmt->fetchColumn();
                $oldValue = $oldValue === false ? null : $oldValue;

                if ((string)$oldValue === $value) {
                    continue; // no-op, nothing to log
                }

                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);

                log_pricing_change($pdo, $adminId, $key, $oldValue, $value);
                $changed[] = $key;
            }
        }

        $pdo->commit();
        json_response('success', $changed ? 'Referral settings updated.' : 'No changes to save.', ['changed' => $changed]);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response('error', 'Failed to update referral settings: ' . $e->getMessage());
    }
}

json_response('error', 'Invalid request method');
