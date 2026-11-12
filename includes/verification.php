<?php
// includes/verification.php
// Authentication, Verification & Account Recovery — all the logic that
// isn't already covered by includes/functions.php (password hashing,
// generic notify_user(), the public-ID generator) or the provider
// abstractions (sms-providers.php / email-providers.php). Kept as its
// own file rather than bloating functions.php further.
require_once __DIR__ . '/sms-providers.php';
require_once __DIR__ . '/email-providers.php';
require_once __DIR__ . '/integration-settings.php';
require_once __DIR__ . '/referral.php';

// ---------------------------------------------------------------
// OTP Manager settings — admin-configurable (System Settings -> Service
// Integration Center -> OTP Manager), falling back to the
// includes/config.php constants of the same meaning. Hashing algorithm
// itself (password_hash, used throughout this file) is NOT a tuning
// knob — only these limits/windows are.
// ---------------------------------------------------------------
function ot_ttl_minutes($pdo) { return (int)get_integration_setting($pdo, 'integration_otp_ttl_minutes', OTP_TTL_MINUTES); }
function ot_max_verify_attempts($pdo) { return (int)get_integration_setting($pdo, 'integration_otp_max_attempts', OTP_MAX_VERIFY_ATTEMPTS); }
function ot_max_resends_per_hour($pdo) { return (int)get_integration_setting($pdo, 'integration_otp_max_resends', OTP_MAX_RESENDS_PER_HOUR); }
function ot_email_verification_ttl_minutes($pdo) { return (int)get_integration_setting($pdo, 'integration_email_verification_ttl_minutes', EMAIL_VERIFICATION_TTL_MINUTES); }
function ot_email_resend_cooldown_seconds($pdo) { return (int)get_integration_setting($pdo, 'integration_email_resend_cooldown_seconds', EMAIL_RESEND_COOLDOWN_SECONDS); }
function ot_reset_token_ttl_minutes($pdo) { return (int)get_integration_setting($pdo, 'integration_reset_token_ttl_minutes', RESET_TOKEN_TTL_MINUTES); }

// ---------------------------------------------------------------
// Independent OTP verification channel switches (Admin -> System
// Settings -> Service Integration Center -> Verification Channels).
// These control ONLY whether the OTP verification step is REQUIRED /
// offered — they are completely separate from provider availability
// (SMTP / SMS credentials), transactional emails, and other
// notifications, which keep flowing regardless. Default enabled (1),
// so an install that never touches the toggle behaves exactly as
// before this layer existed.
//
// Deliberately NO shared "verification enabled" helper: email OTP
// logic must consult ONLY the email switch, phone OTP logic ONLY the
// SMS switch. Keeping them as two independent functions is what
// guarantees disabling one channel can never affect the other.
// ---------------------------------------------------------------
function is_email_verification_enabled($pdo): bool {
    return get_integration_setting_bool($pdo, 'integration_email_verification_enabled', true);
}
function is_phone_verification_enabled($pdo): bool {
    // Phone verification has its OWN independent key, deliberately NOT the
    // SMS-provider enable flag — disabling the phone verification step must
    // never touch SMS provider config (Termii/Twilio keep sending reset
    // codes, notifications, etc.). One-line backward-compat: an install that
    // saved the pre-rename key (integration_sms_verification_enabled) still
    // reads through until it's re-saved under the new key.
    if (get_integration_setting($pdo, 'integration_phone_verification_enabled', null) === null
        && get_integration_setting($pdo, 'integration_sms_verification_enabled', null) !== null) {
        return get_integration_setting_bool($pdo, 'integration_sms_verification_enabled', true);
    }
    return get_integration_setting_bool($pdo, 'integration_phone_verification_enabled', true);
}

// ---------------------------------------------------------------
// Phone normalization — accepts 08031234567 / +2348031234567 /
// 2348031234567 and always stores/compares E.164 (+2348031234567).
// Nigeria-first (per the brief) but the shape generalizes: any other
// country's number already in +<countrycode>... form passes through
// unchanged; only a bare leading-0 local number assumes Nigeria.
// ---------------------------------------------------------------
function normalize_phone_number($raw, $defaultCountryCode = '234') {
    $digits = preg_replace('/[^\d+]/', '', (string)$raw);

    if (strpos($digits, '+') === 0) {
        $digits = substr($digits, 1);
    } elseif (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    } elseif (strpos($digits, '0') === 0) {
        // Local format (e.g. 08031234567) — drop the trunk 0, prefix country code.
        $digits = $defaultCountryCode . substr($digits, 1);
    } elseif (strpos($digits, $defaultCountryCode) !== 0) {
        // Bare local number with no leading 0 (rare) — assume default country.
        $digits = $defaultCountryCode . $digits;
    }

    if (strlen($digits) < 8 || strlen($digits) > 15) {
        return null;
    }
    return '+' . $digits;
}

// ---------------------------------------------------------------
// Secure token/OTP generation
// ---------------------------------------------------------------
function generate_secure_token() {
    return bin2hex(random_bytes(32));
}

// A malformed or hostile client can send an array where a scalar is
// expected (e.g. {"proof":["x"]}) — trim()/(string) on that either
// throws (PHP 8's trim() requires a string) or emits a warning that
// leaks the server file path into the HTTP response body ahead of the
// JSON payload, breaking response.json() client-side. Every auth
// endpoint that reads a request field into a string should route it
// through this first.
function mt_scalar_string($value): string {
    return is_scalar($value) ? trim((string) $value) : '';
}

// Shared strength rule for every place a password gets set (registration,
// change-password, forgot-password reset) — kept in one place so the
// three flows can never drift into three different rules.
function password_meets_policy(string $password): ?string {
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one letter and one number.';
    }
    return null; // null = passes
}
function hash_token($rawToken) {
    return hash('sha256', $rawToken);
}
function ot_otp_length($pdo) {
    $length = (int)get_integration_setting($pdo, 'integration_otp_length', 6);
    return ($length >= 4 && $length <= 8) ? $length : 6; // guard against a bad saved value
}
function generate_otp_code($length = 6) {
    $min = (int) str_pad('1', $length, '0');
    $max = (int) str_pad('', $length, '9');
    return (string) random_int($min, $max);
}

function log_verification_event($pdo, $userId, $type, $action, $meta = null) {
    $publicId = generate_unique_public_id($pdo, 'verification_events', 'public_id', 'VEV');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare('INSERT INTO verification_events (public_id, user_id, type, action, ip_address, meta) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$publicId, $userId, $type, $action, $ip, $meta !== null ? json_encode($meta) : null]);
}

/**
 * Checks if the user is verified based on enabled channels.
 */
function is_user_verified($pdo, $userId): bool {
    $stmt = $pdo->prepare('SELECT email_verified, phone_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }
    
    $emailChannelEnabled = is_email_verification_enabled($pdo);
    $phoneChannelEnabled = is_phone_verification_enabled($pdo);
    
    $emailComplete = $user['email_verified'] || !$emailChannelEnabled;
    $phoneComplete = $user['phone_verified'] || !$phoneChannelEnabled;
    
    return $emailComplete && $phoneComplete;
}

// ---------------------------------------------------------------
// Email verification
// ---------------------------------------------------------------
function send_email_verification($pdo, $userId) {
    error_log("DEBUG: send_email_verification called for UserID: $userId\n", 3, __DIR__ . '/../error_log.txt');
    // Email OTP channel gate — checks ONLY the email switch, never SMS.
    // A disabled channel is "not required": we simply don't send, and
    // crucially we do NOT touch users.email_verified (it stays whatever
    // it historically was). Callers treat channel_disabled as "skip".
    if (!is_email_verification_enabled($pdo)) {
        error_log("DEBUG: Email verification is disabled.\n", 3, __DIR__ . '/../error_log.txt');
        return ['success' => false, 'channel_disabled' => true, 'message' => 'Email verification is disabled.'];
    }

    $stmt = $pdo->prepare('SELECT email, fullname, last_verification_sent, email_resend_count FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    error_log("DEBUG: User fetched: " . print_r($user, true) . "\n", 3, __DIR__ . '/../error_log.txt');
    if (!$user) {
        error_log("DEBUG: User not found.\n", 3, __DIR__ . '/../error_log.txt');
        return ['success' => false, 'message' => 'User not found.'];
    }

    $resendCooldown = ot_email_resend_cooldown_seconds($pdo);
    if ($user['last_verification_sent'] && strtotime($user['last_verification_sent']) > time() - $resendCooldown) {
        $wait = $resendCooldown - (time() - strtotime($user['last_verification_sent']));
        log_verification_event($pdo, $userId, 'email', 'rate_limited', ['wait_seconds' => $wait]);
        return ['success' => false, 'message' => "Please wait {$wait}s before requesting another verification email."];
    }

    $emailTtl = ot_email_verification_ttl_minutes($pdo);
    $rawToken = generate_secure_token();
    $expires = date('Y-m-d H:i:s', time() + $emailTtl * 60);
    // last_verification_sent/otp_last_sent_at/*_verified_at are written
    // using PHP's clock (not SQL NOW()) because the rate-limit and
    // expiry checks above compare against PHP's time() — if MySQL's
    // clock differs from PHP's (as it can when they're configured with
    // different timezones), NOW() would silently defeat the cooldown.
    $stmt = $pdo->prepare('UPDATE users SET email_verification_token = ?, email_verification_expires = ?, last_verification_sent = ?, email_resend_count = email_resend_count + 1 WHERE id = ?');
    $stmt->execute([hash_token($rawToken), $expires, date('Y-m-d H:i:s'), $userId]);

    $link = rtrim(APP_BASE_URL, '/') . '/verify-email.php?token=' . $rawToken;
    $html = mt_email_template_verify($user['fullname'], $link);
    $result = get_email_provider($pdo)->send($user['email'], 'Verify your MohToh account', $html);

    log_verification_event($pdo, $userId, 'email', 'sent', ['delivery_success' => $result['success']]);
    return ['success' => true, 'message' => 'Verification email sent. It expires in ' . $emailTtl . ' minutes.'];
}

function verify_email_token($pdo, $rawToken) {
    $stmt = $pdo->prepare('SELECT id, email_verification_expires FROM users WHERE email_verification_token = ?');
    $stmt->execute([hash_token($rawToken)]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'This verification link is invalid. It may have already been used.'];
    }
    if (strtotime($user['email_verification_expires']) < time()) {
        log_verification_event($pdo, $user['id'], 'email', 'expired');
        return ['success' => false, 'message' => 'This verification link has expired. Please request a new one.'];
    }

    $pdo->prepare('UPDATE users SET email_verified = 1, email_verified_at = ?, email_verification_token = NULL, email_verification_expires = NULL WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $user['id']]);
    log_verification_event($pdo, $user['id'], 'email', 'verified');
    create_notification($pdo, $user['id'], [
        'title' => 'Email verified', 'message' => 'Your email address has been verified.',
        'notification_type' => 'verification', 'action' => 'open_security', 'link' => 'profile.php',
    ]);
    track_referral_event($pdo, $user['id'], 'EMAIL_VERIFIED');
    return ['success' => true, 'message' => 'Email verified successfully.', 'user_id' => $user['id']];
}

// ---------------------------------------------------------------
// Phone OTP verification
// ---------------------------------------------------------------
function send_phone_otp($pdo, $userId) {
    // Phone OTP channel gate — checks ONLY the SMS switch, never email.
    // Same "not required, don't touch users.phone_verified" contract as
    // send_email_verification() above.
    if (!is_phone_verification_enabled($pdo)) {
        return ['success' => false, 'channel_disabled' => true, 'message' => 'Phone verification is disabled.'];
    }

    $stmt = $pdo->prepare('SELECT phone, otp_last_sent_at, otp_resend_count FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['success' => false, 'message' => 'User not found.'];
    }

    // Resend budget resets on its own once an hour has passed since the
    // first send in the current window (tracked via otp_last_sent_at).
    if ($user['otp_last_sent_at'] && strtotime($user['otp_last_sent_at']) > time() - 3600) {
        if ((int)$user['otp_resend_count'] >= ot_max_resends_per_hour($pdo)) {
            log_verification_event($pdo, $userId, 'phone', 'rate_limited');
            return ['success' => false, 'message' => 'Too many OTP requests. Please try again later.'];
        }
    } else {
        $pdo->prepare('UPDATE users SET otp_resend_count = 0 WHERE id = ?')->execute([$userId]);
    }

    $otpTtl = ot_ttl_minutes($pdo);
    $code = generate_otp_code(ot_otp_length($pdo));
    $expires = date('Y-m-d H:i:s', time() + $otpTtl * 60);
    $stmt = $pdo->prepare('UPDATE users SET otp_code = ?, otp_expires = ?, otp_attempts = 0, otp_last_sent_at = ?, otp_resend_count = otp_resend_count + 1 WHERE id = ?');
    $stmt->execute([password_hash($code, PASSWORD_DEFAULT), $expires, date('Y-m-d H:i:s'), $userId]);

    $message = "Your MohToh verification code is {$code}. It expires in " . $otpTtl . " minutes. Never share this code.";
    $result = get_sms_provider($pdo)->send($user['phone'], $message);

    log_verification_event($pdo, $userId, 'phone', 'sent', ['delivery_success' => $result['success']]);
    return ['success' => true, 'message' => 'OTP sent to ' . mt_mask_phone($user['phone']) . '.'];
}

function verify_otp_code($pdo, $userId, $submittedCode) {
    $stmt = $pdo->prepare('SELECT otp_code, otp_expires, otp_attempts FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user || !$user['otp_code']) {
        return ['success' => false, 'message' => 'No OTP request found. Please request a new code.'];
    }
    $maxAttempts = ot_max_verify_attempts($pdo);
    if ((int)$user['otp_attempts'] >= $maxAttempts) {
        log_verification_event($pdo, $userId, 'phone', 'failed', ['reason' => 'max_attempts']);
        return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new OTP.'];
    }
    if (strtotime($user['otp_expires']) < time()) {
        log_verification_event($pdo, $userId, 'phone', 'expired');
        return ['success' => false, 'message' => 'This OTP has expired. Please request a new one.'];
    }
    if (!password_verify((string)$submittedCode, $user['otp_code'])) {
        $pdo->prepare('UPDATE users SET otp_attempts = otp_attempts + 1 WHERE id = ?')->execute([$userId]);
        log_verification_event($pdo, $userId, 'phone', 'failed', ['reason' => 'wrong_code']);
        $remaining = $maxAttempts - (int)$user['otp_attempts'] - 1;
        return ['success' => false, 'message' => "Incorrect code. {$remaining} attempt(s) remaining."];
    }

    $pdo->prepare('UPDATE users SET phone_verified = 1, phone_verified_at = ?, otp_code = NULL, otp_expires = NULL, otp_attempts = 0 WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $userId]);
    log_verification_event($pdo, $userId, 'phone', 'verified');
    create_notification($pdo, $userId, [
        'title' => 'Phone verified', 'message' => 'Your phone number has been verified.',
        'notification_type' => 'verification', 'action' => 'open_security', 'link' => 'profile.php',
    ]);
    track_referral_event($pdo, $userId, 'PHONE_VERIFIED');
    return ['success' => true, 'message' => 'Phone number verified successfully.'];
}

function mt_mask_phone($e164) {
    return substr($e164, 0, 4) . str_repeat('•', max(0, strlen($e164) - 7)) . substr($e164, -3);
}

// ---------------------------------------------------------------
// Forgot password — one flow, two channels. The "proof" (emailed raw
// token, or texted OTP) is always hashed the same way in reset_token so
// verify/consume logic doesn't need to branch on channel.
// ---------------------------------------------------------------
function request_password_reset($pdo, $identifier) {
    $isEmail = strpos($identifier, '@') !== false;

    // Password reset is itself an OTP verification flow, so it honours the
    // same independent channel switches. A disabled channel simply can't
    // be used to reset — checked BEFORE any account lookup, so the answer
    // depends only on the channel state, never on whether the account
    // exists (no enumeration leak). Each branch consults ONLY its own
    // switch.
    if ($isEmail && !is_email_verification_enabled($pdo)) {
        return ['success' => false, 'channel_disabled' => true, 'message' => 'Email verification is currently disabled.'];
    }
    if (!$isEmail && !is_phone_verification_enabled($pdo)) {
        return ['success' => false, 'channel_disabled' => true, 'message' => 'Phone verification is currently disabled.'];
    }

    if ($isEmail) {
        $stmt = $pdo->prepare('SELECT id, fullname, email FROM users WHERE email = ?');
        $stmt->execute([trim($identifier)]);
    } else {
        $normalized = normalize_phone_number($identifier);
        if (!$normalized) {
            return ['success' => false, 'message' => 'Please enter a valid email or phone number.'];
        }
        $stmt = $pdo->prepare('SELECT id, fullname, phone FROM users WHERE phone = ?');
        $stmt->execute([$normalized]);
    }
    $user = $stmt->fetch();

    // Deliberately the same success response whether or not the account
    // exists — this is the one place account enumeration would
    // otherwise leak which emails/phones are registered.
    $genericResponse = ['success' => true, 'message' => $isEmail
        ? 'If that email is registered, a reset link has been sent.'
        : 'If that phone number is registered, an OTP has been sent.'];
    if (!$user) {
        return $genericResponse;
    }

    if ($isEmail) {
        $rawToken = generate_secure_token();
        $expires = date('Y-m-d H:i:s', time() + ot_reset_token_ttl_minutes($pdo) * 60);
        $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ?, reset_method = 'email', reset_used = 0 WHERE id = ?")
            ->execute([hash_token($rawToken), $expires, $user['id']]);

        $link = rtrim(APP_BASE_URL, '/') . '/reset-password.php?token=' . $rawToken;
        get_email_provider($pdo)->send($user['email'], 'Reset your MohToh password', mt_email_template_reset($user['fullname'], $link));
    } else {
        $otpTtl = ot_ttl_minutes($pdo);
        $code = generate_otp_code(ot_otp_length($pdo));
        $expires = date('Y-m-d H:i:s', time() + $otpTtl * 60);
        $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ?, reset_method = 'phone', reset_used = 0, otp_attempts = 0 WHERE id = ?")
            ->execute([password_hash($code, PASSWORD_DEFAULT), $expires, $user['id']]);
        get_sms_provider($pdo)->send($user['phone'], "Your MohToh password reset code is {$code}. It expires in " . $otpTtl . " minutes.");
    }

    log_verification_event($pdo, $user['id'], 'password_reset', 'sent', ['method' => $isEmail ? 'email' : 'phone']);
    return $genericResponse;
}

// Verifies the proof (token from link, or OTP) without consuming it —
// used by the OTP path's "step 2 -> step 3" transition so the user can
// move to the new-password screen before it's spent. The email path
// skips straight to reset_password() since clicking the link IS step 2.
function check_password_reset_proof($pdo, $identifier, $proof) {
    $user = mt_find_user_for_reset($pdo, $identifier);
    if (!$user) {
        return ['success' => false, 'message' => 'Invalid request.'];
    }
    // Honour the channel switch for the whole reset lifecycle, not just
    // the initial request: if the channel that issued this proof has since
    // been disabled, the proof can no longer be consumed. Consults only
    // the switch matching how the reset was started.
    $channelDisabled = $user['reset_method'] === 'phone'
        ? !is_phone_verification_enabled($pdo)
        : !is_email_verification_enabled($pdo);
    if ($channelDisabled) {
        return ['success' => false, 'message' => $user['reset_method'] === 'phone'
            ? 'Phone verification is currently disabled.'
            : 'Email verification is currently disabled.'];
    }
    if ($user['reset_used'] || !$user['reset_token'] || strtotime($user['reset_expires']) < time()) {
        return ['success' => false, 'message' => 'This code has expired or was already used. Please request a new one.'];
    }
    $matches = $user['reset_method'] === 'phone'
        ? password_verify((string)$proof, $user['reset_token'])
        : hash_equals($user['reset_token'], hash_token((string)$proof));
    if (!$matches) {
        return ['success' => false, 'message' => 'Incorrect or invalid code.'];
    }
    return ['success' => true, 'user_id' => $user['id']];
}

function reset_password($pdo, $identifier, $proof, $newPassword) {
    $policyError = password_meets_policy($newPassword);
    if ($policyError !== null) {
        return ['success' => false, 'message' => $policyError];
    }
    $check = check_password_reset_proof($pdo, $identifier, $proof);
    if (!$check['success']) {
        return $check;
    }

    $pdo->prepare("UPDATE users SET password = ?, password_is_set = 1, reset_token = NULL, reset_expires = NULL, reset_used = 1, failed_login_attempts = 0, lockout_until = NULL WHERE id = ?")
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $check['user_id']]);
    log_verification_event($pdo, $check['user_id'], 'password_reset', 'verified');
    return ['success' => true, 'message' => 'Your password has been reset. You can now log in.'];
}

function mt_find_user_for_reset($pdo, $identifier) {
    $isEmail = strpos($identifier, '@') !== false;
    if ($isEmail) {
        $stmt = $pdo->prepare('SELECT id, reset_token, reset_expires, reset_method, reset_used FROM users WHERE email = ?');
        $stmt->execute([trim($identifier)]);
    } else {
        $normalized = normalize_phone_number($identifier);
        $stmt = $pdo->prepare('SELECT id, reset_token, reset_expires, reset_method, reset_used FROM users WHERE phone = ?');
        $stmt->execute([$normalized]);
    }
    return $stmt->fetch() ?: null;
}

// ---------------------------------------------------------------
// Login brute-force protection
// ---------------------------------------------------------------
function is_account_locked($user) {
    return !empty($user['lockout_until']) && strtotime($user['lockout_until']) > time();
}

function record_failed_login($pdo, $userId) {
    $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = ?');
    $stmt->execute([$userId]);

    $stmt = $pdo->prepare('SELECT failed_login_attempts FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $attempts = (int) $stmt->fetchColumn();

    if ($attempts >= LOGIN_MAX_FAILED_ATTEMPTS) {
        $lockoutUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60);
        $pdo->prepare('UPDATE users SET lockout_until = ? WHERE id = ?')->execute([$lockoutUntil, $userId]);
        log_verification_event($pdo, $userId, 'login', 'locked', ['attempts' => $attempts]);
        create_notification($pdo, $userId, [
            'title' => 'Account locked',
            'message' => "Too many failed login attempts. Your account is locked until {$lockoutUntil}.",
            'notification_type' => 'security', 'priority' => 'critical',
        ]);
        return $lockoutUntil;
    }
    log_verification_event($pdo, $userId, 'login', 'failed', ['attempts' => $attempts]);
    return null;
}

function record_successful_login($pdo, $userId) {
    $pdo->prepare('UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?')->execute([$userId]);
}

function admin_unlock_account($pdo, $userId) {
    $pdo->prepare('UPDATE users SET failed_login_attempts = 0, lockout_until = NULL WHERE id = ?')->execute([$userId]);
    log_verification_event($pdo, $userId, 'login', 'unlocked');
}

// ---------------------------------------------------------------
// Email templates — plain, inline-styled HTML (no external CSS,
// since these render in email clients, not the app's own stylesheets).
// ---------------------------------------------------------------
function mt_email_template_verify($fullname, $link) {
    $name = htmlspecialchars($fullname);
    $safeLink = htmlspecialchars($link);
    $ttl = EMAIL_VERIFICATION_TTL_MINUTES;
    return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;">
  <h2 style="color:#0F8B8D;">Verify your MohToh account</h2>
  <p>Hi {$name},</p>
  <p>Tap the button below to verify your email address. This link expires in {$ttl} minutes.</p>
  <p style="text-align:center;margin:32px 0;">
    <a href="{$safeLink}" style="background:#0F8B8D;color:#fff;text-decoration:none;padding:14px 28px;border-radius:999px;font-weight:bold;display:inline-block;">Verify Email</a>
  </p>
  <p style="color:#888;font-size:12px;">If you didn't create a MohToh account, you can safely ignore this email.</p>
</div>
HTML;
}

function mt_email_template_reset($fullname, $link) {
    $name = htmlspecialchars($fullname);
    $safeLink = htmlspecialchars($link);
    $ttl = RESET_TOKEN_TTL_MINUTES;
    return <<<HTML
<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;">
  <h2 style="color:#0F8B8D;">Reset your MohToh password</h2>
  <p>Hi {$name},</p>
  <p>We received a request to reset your password. This link expires in {$ttl} minutes.</p>
  <p style="text-align:center;margin:32px 0;">
    <a href="{$safeLink}" style="background:#0F8B8D;color:#fff;text-decoration:none;padding:14px 28px;border-radius:999px;font-weight:bold;display:inline-block;">Reset Password</a>
  </p>
  <p style="color:#888;font-size:12px;">If you didn't request this, you can safely ignore this email — your password will not change.</p>
</div>
HTML;
}
