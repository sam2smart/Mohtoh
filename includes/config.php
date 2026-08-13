<?php
// includes/config.php
// Front-end configuration constants (map provider keys, etc).
// Kept separate from includes/db.php so redesign work never has to touch
// the database connection file.

require_once __DIR__ . '/environment.php';

if (!defined('APP_ENCRYPTION_KEY')) {
    define('APP_ENCRYPTION_KEY', $_ENV['APP_ENCRYPTION_KEY'] ?? getenv('APP_ENCRYPTION_KEY') ?? 'da39a3ee5e6b4b0d3255bfef95601890afd80709da39a3ee5e6b4b0d3255bfe');
}

// Google Maps API key — NOT stored here. The ONE source of truth is the
// `integrations` table's google_maps row (`configuration` JSON, field
// `api_key`), read via includes/google-maps.php's getGoogleMapsKey(). There is
// deliberately no constant fallback: a single authoritative source only, so a
// stale constant can never shadow the admin-managed key. Restrict the key by
// HTTP referrer in Google Cloud Console, since the browser key is readable
// from the browser by design.

// ---- Paystack (Driver Wallet Top-Up) ----
// Paystack configuration is now managed in the `integrations` database table.
// To switch modes, use the Admin Integration Center.
define('PAYSTACK_MODE', 'live'); // Forced to live by migration
define('PAYSTACK_BASE_URL', 'https://api.paystack.co');

// Used to build the absolute callback_url Paystack redirects back to.
// Update if the app is deployed somewhere other than this local path.
define('APP_BASE_URL', mt_get_base_url());

// ---- Registration & Login Policy Defaults ----
if (!defined('REQUIRE_EMAIL_VERIFIED_TO_LOGIN')) {
    define('REQUIRE_EMAIL_VERIFIED_TO_LOGIN', true);
}
if (!defined('REQUIRE_PHONE_VERIFIED_TO_LOGIN')) {
    define('REQUIRE_PHONE_VERIFIED_TO_LOGIN', true);
}

// ---- OTP / Verification Limits & Windows ----
if (!defined('OTP_TTL_MINUTES')) {
    define('OTP_TTL_MINUTES', 5);
}
if (!defined('OTP_MAX_VERIFY_ATTEMPTS')) {
    define('OTP_MAX_VERIFY_ATTEMPTS', 5);
}
if (!defined('OTP_MAX_RESENDS_PER_HOUR')) {
    define('OTP_MAX_RESENDS_PER_HOUR', 5);
}
if (!defined('EMAIL_VERIFICATION_TTL_MINUTES')) {
    define('EMAIL_VERIFICATION_TTL_MINUTES', 60);
}
if (!defined('EMAIL_RESEND_COOLDOWN_SECONDS')) {
    define('EMAIL_RESEND_COOLDOWN_SECONDS', 60);
}
if (!defined('RESET_TOKEN_TTL_MINUTES')) {
    define('RESET_TOKEN_TTL_MINUTES', 15);
}
if (!defined('LOGIN_MAX_FAILED_ATTEMPTS')) {
    define('LOGIN_MAX_FAILED_ATTEMPTS', 5);
}
if (!defined('LOGIN_LOCKOUT_MINUTES')) {
    define('LOGIN_LOCKOUT_MINUTES', 15);
}

// ---- Service Provider Fallbacks ----
if (!defined('EMAIL_PROVIDER_MODE')) {
    define('EMAIL_PROVIDER_MODE', 'smtp');
}
if (!defined('SMS_PROVIDER_MODE')) {
    define('SMS_PROVIDER_MODE', 'log');
}
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', '');
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', 587);
}
if (!defined('SMTP_USERNAME')) {
    define('SMTP_USERNAME', '');
}
if (!defined('SMTP_PASSWORD')) {
    define('SMTP_PASSWORD', '');
}
if (!defined('SMTP_ENCRYPTION')) {
    define('SMTP_ENCRYPTION', 'tls');
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', '');
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', 'MohToh');
}
if (!defined('TERMII_API_KEY')) {
    define('TERMII_API_KEY', '');
}
if (!defined('TERMII_SENDER_ID')) {
    define('TERMII_SENDER_ID', '');
}
if (!defined('TWILIO_ACCOUNT_SID')) {
    define('TWILIO_ACCOUNT_SID', '');
}
if (!defined('TWILIO_AUTH_TOKEN')) {
    define('TWILIO_AUTH_TOKEN', '');
}
// The Twilio-provisioned SMS sender used as the Messages API `From` (caller
// ID) — an E.164 number (+14155552671) or a Messaging Service SID (MG...).
// Never the Account SID (AC...) or a Verify Service SID (VA...): Twilio
// rejects those with error 21212, Invalid From Number.
if (!defined('TWILIO_FROM_NUMBER')) {
    define('TWILIO_FROM_NUMBER', '');
}
if (!defined('TWILIO_VERIFY_SERVICE_SID')) {
    define('TWILIO_VERIFY_SERVICE_SID', '');
}
if (!defined('VONAGE_API_KEY')) {
    define('VONAGE_API_KEY', '');
}
if (!defined('VONAGE_API_SECRET')) {
    define('VONAGE_API_SECRET', '');
}
if (!defined('VONAGE_BRAND_NAME')) {
    define('VONAGE_BRAND_NAME', '');
}
if (!defined('AFRICASTALKING_USERNAME')) {
    define('AFRICASTALKING_USERNAME', '');
}
if (!defined('AFRICASTALKING_API_KEY')) {
    define('AFRICASTALKING_API_KEY', '');
}
if (!defined('AFRICASTALKING_SENDER_ID')) {
    define('AFRICASTALKING_SENDER_ID', '');
}



