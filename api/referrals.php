<?php
// api/referrals.php
// User-facing MohToh Community Rewards endpoint. Thin by design — every
// rule (code generation, qualification, reward math) lives in
// includes/referral.php; this file only authenticates, validates input
// shape, and routes to it. Same action-routing shape as every other
// user-facing endpoint this session (api/notifications.php,
// api/messages.php).
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php'; // APP_BASE_URL
require_once '../includes/referral.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'validate_code') {
    // Public — no login required. Used by the registration sheet to show
    // "Invited by X" before the account even exists.
    $code = trim((string)($_GET['code'] ?? ''));
    if ($code === '') {
        json_response('error', 'A referral code is required.');
    }
    $stmt = $pdo->prepare("SELECT fullname, user_type FROM users WHERE referral_code = ?");
    $stmt->execute([$code]);
    $referrer = $stmt->fetch();
    if (!$referrer) {
        json_response('error', 'This referral code was not found.');
    }
    json_response('success', 'Referral code found', ['inviter_name' => $referrer['fullname'], 'inviter_type' => $referrer['user_type']]);
}

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'my_code';

    if ($action === 'my_code') {
        $code = get_or_create_referral_code($pdo, $user_id);
        $joinUrl = rtrim(APP_BASE_URL, '/') . '/join.php?ref=' . urlencode($code);
        json_response('success', 'Referral code fetched', ['referral_code' => $code, 'join_url' => $joinUrl]);
    }

    if ($action === 'my_referrals') {
        json_response('success', 'Referrals fetched', ['referrals' => get_user_referrals($pdo, $user_id)]);
    }

    if ($action === 'statistics') {
        json_response('success', 'Statistics fetched', get_referral_statistics($pdo, $user_id));
    }

    if ($action === 'reward_history') {
        $stmt = $pdo->prepare("
            SELECT rr.public_id, rr.reward_type, rr.amount, rr.status, rr.created_at, r.public_id AS referral_public_id
            FROM referral_rewards rr
            JOIN referrals r ON r.id = rr.referral_id
            WHERE rr.user_id = ?
            ORDER BY rr.created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$user_id]);
        json_response('success', 'Reward history fetched', ['rewards' => $stmt->fetchAll()]);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';

    if ($action === 'share_link') {
        $code = get_or_create_referral_code($pdo, $user_id);
        $joinUrl = rtrim(APP_BASE_URL, '/') . '/join.php?ref=' . urlencode($code);
        $shareText = "Join MohToh and get your first ride benefit: {$joinUrl}";
        json_response('success', 'Share link ready', [
            'referral_code' => $code,
            'join_url' => $joinUrl,
            'share_text' => $shareText,
            'whatsapp_url' => 'https://wa.me/?text=' . urlencode($shareText),
            'sms_url' => 'sms:?body=' . urlencode($shareText),
        ]);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
