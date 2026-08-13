<?php
// POST /api/v1/auth/login.php
// Mobile token login — reuses the EXACT same account-state checks the
// web login (api/auth.php) already enforces (suspension, lockout,
// failed-attempt tracking, verification policy), just issuing a bearer
// token instead of a $_SESSION. No business rule is duplicated: every
// check here calls the same includes/verification.php functions the
// web endpoint calls.
require_once __DIR__ . '/../_bootstrap.php'; // pulls in includes/verification.php too — is_account_locked(), record_failed_login/successful_login(), mt_scalar_string()

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$input = v1_input();
$email = trim((string)($input['email'] ?? ''));
$password = mt_scalar_string($input['password'] ?? '');
$deviceType = isset($input['device_type']) ? mt_scalar_string($input['device_type']) : null;
$deviceName = isset($input['device_name']) ? mt_scalar_string($input['device_name']) : null;

if ($email === '' || $password === '') {
    v1_error('Email and password are required.', 'validation_error');
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    v1_error('Invalid email or password.', 'invalid_credentials', 401);
}
if ($user['is_suspended']) {
    v1_error('Your account has been suspended. Please contact support.', 'account_suspended', 403);
}
if (is_account_locked($user)) {
    $minutesLeft = max(1, (int)ceil((strtotime($user['lockout_until']) - time()) / 60));
    v1_error("Too many failed attempts. Please try again in {$minutesLeft} minute(s).", 'account_locked', 423);
}
if (!password_verify($password, $user['password'])) {
    $lockoutUntil = record_failed_login($pdo, $user['id']);
    if ($lockoutUntil) {
        $minutesLeft = (int)ceil((strtotime($lockoutUntil) - time()) / 60);
        v1_error("Too many failed attempts. Your account is locked for {$minutesLeft} minute(s).", 'account_locked', 423);
    }
    v1_error('Invalid email or password.', 'invalid_credentials', 401);
}

record_successful_login($pdo, $user['id']);

$requireEmailToLogin = get_integration_setting_bool($pdo, 'integration_require_email_to_login', defined('REQUIRE_EMAIL_VERIFIED_TO_LOGIN') ? REQUIRE_EMAIL_VERIFIED_TO_LOGIN : true);
$requirePhoneToLogin = get_integration_setting_bool($pdo, 'integration_require_phone_to_login', defined('REQUIRE_PHONE_VERIFIED_TO_LOGIN') ? REQUIRE_PHONE_VERIFIED_TO_LOGIN : true);
// Mirror the web login gate exactly: a requirement only applies when the
// channel is enabled. Disabling a channel makes its verification
// requirement moot without ever mutating the *_verified columns.
$needsVerification = ($requireEmailToLogin && is_email_verification_enabled($pdo) && !$user['email_verified'])
    || ($requirePhoneToLogin && is_phone_verification_enabled($pdo) && !$user['phone_verified']);

$token = issue_api_token($pdo, $user['id'], $deviceType, $deviceName);

v1_success('Login successful', [
    'token' => $token,
    'token_type' => 'Bearer',
    'expires_in_days' => API_TOKEN_TTL_DAYS,
    'needs_verification' => $needsVerification,
    'user' => [
        'id' => (int)$user['id'],
        'public_id' => $user['user_id'],
        'fullname' => $user['fullname'],
        'email' => $user['email'],
        'phone' => $user['phone'],
        'user_type' => $user['user_type'],
        'email_verified' => (bool)$user['email_verified'],
        'phone_verified' => (bool)$user['phone_verified'],
        'profile_image' => $user['profile_image'],
    ],
]);
