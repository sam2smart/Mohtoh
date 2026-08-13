<?php
// api/verification.php
// Session-based verification actions for the logged-in-but-unverified
// account (verify-account.php). All the real logic lives in
// includes/verification.php — this file is just the thin HTTP wrapper,
// same shape as every other api/*.php entry point in this app.
session_start();
error_log("API verification.php called. Input: " . print_r($_POST, true) . "\n", 3, __DIR__ . '/../error_log.txt');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/verification.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$userId = (int) $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
error_log("API verification.php called. Input: " . print_r($input, true) . "\n", 3, __DIR__ . '/../error_log.txt');
$action = $input['action'] ?? $_GET['action'] ?? '';

if ($action === 'status') {
    $stmt = $pdo->prepare('SELECT email, phone, email_verified, phone_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    // *_channel_enabled tell the frontend which cards/buttons to show and
    // which channels count as "not required" for navigation. The
    // *_verified values are the untouched historical record — a disabled
    // channel keeps whatever it was.
    json_response('success', 'Verification status fetched', [
        'email' => $user['email'],
        'phone' => $user['phone'],
        'email_verified' => (bool) $user['email_verified'],
        'phone_verified' => (bool) $user['phone_verified'],
        'email_channel_enabled' => is_email_verification_enabled($pdo),
        'phone_channel_enabled' => is_phone_verification_enabled($pdo),
    ]);
}

if ($action === 'resend_email') {
    if (!is_email_verification_enabled($pdo)) {
        json_response('error', 'Email verification is currently disabled.');
    }
    $result = send_email_verification($pdo, $userId);
    json_response($result['success'] ? 'success' : 'error', $result['message']);
}

if ($action === 'send_otp' || $action === 'resend_otp') {
    if (!is_phone_verification_enabled($pdo)) {
        json_response('error', 'Phone verification is currently disabled.');
    }
    $result = send_phone_otp($pdo, $userId);
    json_response($result['success'] ? 'success' : 'error', $result['message']);
}

if ($action === 'verify_otp') {
    $code = mt_scalar_string($input['otp'] ?? '');
    if ($code === '') {
        json_response('error', 'Please enter the code sent to your phone.');
    }
    $result = verify_otp_code($pdo, $userId, $code);
    json_response($result['success'] ? 'success' : 'error', $result['message']);
}

json_response('error', 'Invalid action.');
