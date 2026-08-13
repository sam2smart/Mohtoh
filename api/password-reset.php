<?php
// api/password-reset.php
// Forgot Password flow — no session required (the whole point is the
// user is logged out and can't get back in). Wraps
// includes/verification.php's request_password_reset() /
// check_password_reset_proof() / reset_password(), which already
// enforce single-use tokens, expiry, and (for the OTP channel) a
// verify-attempt ceiling via the same otp_attempts column phone
// verification uses.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/verification.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? $_GET['action'] ?? '';

// Which reset channels are currently open. Public (no session) so the
// logged-out forgot-password page can hide/disable a channel before the
// user even submits. Reveals only two booleans — no account data — so
// it's safe to expose unauthenticated.
if ($action === 'channels') {
    json_response('success', 'Reset channels fetched', [
        'email_channel_enabled' => is_email_verification_enabled($pdo),
        'phone_channel_enabled' => is_phone_verification_enabled($pdo),
    ]);
}

// Step 1 — identify the account (email or phone). Always returns a
// generic success message regardless of whether the account exists,
// so this endpoint can't be used to enumerate registered accounts.
if ($action === 'request') {
    $identifier = mt_scalar_string($input['identifier'] ?? '');
    if ($identifier === '') {
        json_response('error', 'Please enter your email or phone number.');
    }
    $result = request_password_reset($pdo, $identifier);
    json_response($result['success'] ? 'success' : 'error', $result['message']);
}

// Step 2 (OTP channel only) — confirm the code before showing the
// new-password screen, without consuming it yet (reset_password()
// re-checks and is what actually consumes it).
if ($action === 'verify_otp') {
    $identifier = mt_scalar_string($input['identifier'] ?? '');
    $otp = mt_scalar_string($input['otp'] ?? '');
    if ($identifier === '' || $otp === '') {
        json_response('error', 'Please enter the code that was sent to you.');
    }
    $result = check_password_reset_proof($pdo, $identifier, $otp);
    json_response($result['success'] ? 'success' : 'error', $result['success'] ? 'Code verified.' : ($result['message'] ?? 'Invalid code.'));
}

// Step 3 — set the new password. $proof is the raw emailed token for
// the email channel, or the OTP digits for the phone channel.
if ($action === 'reset') {
    $identifier = mt_scalar_string($input['identifier'] ?? '');
    $proof = mt_scalar_string($input['proof'] ?? '');
    $newPassword = mt_scalar_string($input['new_password'] ?? '');
    $confirmPassword = array_key_exists('confirm_password', $input) ? mt_scalar_string($input['confirm_password']) : $newPassword;

    if ($identifier === '' || $proof === '') {
        json_response('error', 'This reset link or code is invalid. Please start over.');
    }
    if ($newPassword !== $confirmPassword) {
        json_response('error', 'Password and confirm password do not match.');
    }

    $result = reset_password($pdo, $identifier, $proof, $newPassword);
    json_response($result['success'] ? 'success' : 'error', $result['message']);
}

// Convenience for reset-password.php (the emailed-link landing page):
// resolves which account a raw token belongs to, so that page doesn't
// need the user to also type their email in by hand.
if ($action === 'resolve_token') {
    $token = mt_scalar_string($input['token'] ?? '');
    if ($token === '') {
        json_response('error', 'Invalid link.');
    }
    // Same email-channel guard as reset-password.php and the `reset`
    // action: a disabled email channel invalidates emailed reset links.
    if (!is_email_verification_enabled($pdo)) {
        json_response('error', 'This reset link is invalid or has expired.');
    }
    $stmt = $pdo->prepare("SELECT email, reset_expires, reset_used FROM users WHERE reset_token = ? AND reset_method = 'email'");
    $stmt->execute([hash_token($token)]);
    $user = $stmt->fetch();
    if (!$user || $user['reset_used'] || strtotime($user['reset_expires']) < time()) {
        json_response('error', 'This reset link is invalid or has expired.');
    }
    json_response('success', 'Token valid.', ['email' => $user['email']]);
}

json_response('error', 'Invalid action.');
