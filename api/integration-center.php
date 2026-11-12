<?php
// api/integration-center.php
// Admin-facing read/write layer for the Service Integration Center (Email +
// SMS/OTP delivery). Every key here is one already read by
// includes/email-providers.php / includes/sms-providers.php through
// get_integration_setting() — saving here takes effect on the very next
// OTP/verification email or SMS sent, no code change, no deploy.
//
// Super-admin gated for writes and for sending real test messages (a test
// send is a real, billable/quota-consuming external API call on live
// provider accounts), same tier as api/pricing-admin.php. Reads are open to
// any logged-in admin so the dashboard tab can render for all admins, with
// every secret masked before it ever reaches the browser — see mask_secret()
// in includes/secrets.php. The raw secret is never sent back down once saved.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php';
require_once '../includes/integration-settings.php';
require_once '../includes/email-providers.php';
require_once '../includes/sms-providers.php';
require_once '../includes/verification.php'; // normalize_phone_number()
require_once '../includes/google-maps.php';   // Google Maps provider (map/geocode/directions/distance)
require_once '../includes/payment-integration.php'; // Paystack payment provider (status + configuration on the integrations row)
require_once '../includes/secrets.php';        // mask_secret()/decrypt_secret()/secret_is_undecryptable() for the payment card
require_once '../includes/oauth.php';           // Social Login (Google/Facebook) config + credential test helpers

header('Content-Type: application/json');

require_once '../includes/admin_permissions.php';
// Access-Control: reading (masked) integration settings needs integrations.view;
// every save and every real test-send below keeps its own require_super_admin($pdo)
// gate, so a Subadmin can view provider config but never write it or spend on a
// billable test call. Secrets stay masked before reaching the browser regardless.
require_admin_permission($pdo, 'integrations.view');

// Whitelisted keys only — mirrors api/pricing-admin.php's PRICING_SETTING_KEYS
// pattern so no caller can ever write an arbitrary integration_settings row.
// 'short' is the field name the admin-dashboard JS/HTML actually uses
// (element ids, JSON keys) — kept explicit rather than derived from the
// DB key by stripping a prefix, since 'integration_sms_mode' and
// 'integration_termii_api_key' don't share one uniform prefix.
const EMAIL_SETTING_KEYS = [
    'integration_email_mode' => ['short' => 'mode', 'fallback' => EMAIL_PROVIDER_MODE, 'secret' => false],
    'integration_email_host' => ['short' => 'host', 'fallback' => SMTP_HOST, 'secret' => false],
    'integration_email_port' => ['short' => 'port', 'fallback' => SMTP_PORT, 'secret' => false],
    'integration_email_username' => ['short' => 'username', 'fallback' => SMTP_USERNAME, 'secret' => false],
    'integration_email_password' => ['short' => 'password', 'fallback' => SMTP_PASSWORD, 'secret' => true],
    'integration_email_encryption' => ['short' => 'encryption', 'fallback' => SMTP_ENCRYPTION, 'secret' => false],
    'integration_email_from_email' => ['short' => 'from_email', 'fallback' => SMTP_FROM_EMAIL, 'secret' => false],
    'integration_email_from_name' => ['short' => 'from_name', 'fallback' => SMTP_FROM_NAME, 'secret' => false],
    'integration_email_reply_to' => ['short' => 'reply_to', 'fallback' => '', 'secret' => false],
];

const SMS_SETTING_KEYS = [
    'integration_sms_mode' => ['short' => 'mode', 'fallback' => SMS_PROVIDER_MODE, 'secret' => false],
    'integration_termii_api_key' => ['short' => 'termii_api_key', 'fallback' => TERMII_API_KEY, 'secret' => true],
    'integration_termii_sender_id' => ['short' => 'termii_sender_id', 'fallback' => TERMII_SENDER_ID, 'secret' => false],
    'integration_twilio_account_sid' => ['short' => 'twilio_account_sid', 'fallback' => TWILIO_ACCOUNT_SID, 'secret' => false],
    'integration_twilio_auth_token' => ['short' => 'twilio_auth_token', 'fallback' => TWILIO_AUTH_TOKEN, 'secret' => true],
    'integration_twilio_from_number' => ['short' => 'twilio_from_number', 'fallback' => TWILIO_FROM_NUMBER, 'secret' => false],
    'integration_twilio_verify_sid' => ['short' => 'twilio_verify_sid', 'fallback' => TWILIO_VERIFY_SERVICE_SID, 'secret' => false],
    'integration_vonage_api_key' => ['short' => 'vonage_api_key', 'fallback' => VONAGE_API_KEY, 'secret' => false],
    'integration_vonage_api_secret' => ['short' => 'vonage_api_secret', 'fallback' => VONAGE_API_SECRET, 'secret' => true],
    'integration_vonage_brand_name' => ['short' => 'vonage_brand_name', 'fallback' => VONAGE_BRAND_NAME, 'secret' => false],
    'integration_africastalking_username' => ['short' => 'africastalking_username', 'fallback' => AFRICASTALKING_USERNAME, 'secret' => false],
    'integration_africastalking_api_key' => ['short' => 'africastalking_api_key', 'fallback' => AFRICASTALKING_API_KEY, 'secret' => true],
    'integration_africastalking_sender_id' => ['short' => 'africastalking_sender_id', 'fallback' => AFRICASTALKING_SENDER_ID, 'secret' => false],
];

// OTP Manager — tuning knobs behind includes/verification.php's ot_*()
// helpers. None are secrets; all take effect on the very next OTP or
// verification email sent, no deploy needed.
const OTP_SETTING_KEYS = [
    'integration_otp_length' => ['short' => 'otp_length', 'fallback' => 6, 'secret' => false],
    'integration_otp_ttl_minutes' => ['short' => 'otp_ttl_minutes', 'fallback' => OTP_TTL_MINUTES, 'secret' => false],
    'integration_otp_max_attempts' => ['short' => 'otp_max_attempts', 'fallback' => OTP_MAX_VERIFY_ATTEMPTS, 'secret' => false],
    'integration_otp_max_resends' => ['short' => 'otp_max_resends', 'fallback' => OTP_MAX_RESENDS_PER_HOUR, 'secret' => false],
    'integration_email_verification_ttl_minutes' => ['short' => 'email_verification_ttl_minutes', 'fallback' => EMAIL_VERIFICATION_TTL_MINUTES, 'secret' => false],
    'integration_email_resend_cooldown_seconds' => ['short' => 'email_resend_cooldown_seconds', 'fallback' => EMAIL_RESEND_COOLDOWN_SECONDS, 'secret' => false],
    'integration_reset_token_ttl_minutes' => ['short' => 'reset_token_ttl_minutes', 'fallback' => RESET_TOKEN_TTL_MINUTES, 'secret' => false],
];

// Registration Policy — whether LOGIN ITSELF is blocked for an unverified
// account. Ride/booking creation is always hard-blocked regardless of
// this (api/rideshare.php has its own, separate, non-optional checks),
// and driver KYC/vehicle-approval gates likewise stay non-optional —
// only the login-time behavior is a policy toggle.
define('POLICY_SETTING_KEYS', [
    'integration_require_email_to_login' => ['short' => 'require_email_to_login', 'fallback' => (defined('REQUIRE_EMAIL_VERIFIED_TO_LOGIN') && REQUIRE_EMAIL_VERIFIED_TO_LOGIN) ? '1' : '0', 'secret' => false],
    'integration_require_phone_to_login' => ['short' => 'require_phone_to_login', 'fallback' => (defined('REQUIRE_PHONE_VERIFIED_TO_LOGIN') && REQUIRE_PHONE_VERIFIED_TO_LOGIN) ? '1' : '0', 'secret' => false],
]);

// Verification Channels — independent on/off switches for the two OTP
// verification channels. These control ONLY whether the OTP verification
// step is required/offered; they are deliberately separate from the
// Email/SMS provider config above (SMTP creds, Termii/Twilio keys), which
// keep serving transactional emails and other SMS regardless. Default
// enabled ('1'). Read via includes/verification.php's
// is_email_verification_enabled() / is_phone_verification_enabled().
// NOTE: the phone key is integration_phone_verification_enabled — a
// verification step switch, NOT integration_sms_mode / any SMS-provider
// enable flag. See is_phone_verification_enabled() for the one-line
// backward-compat read of the pre-rename key.
define('VERIFICATION_SETTING_KEYS', [
    'integration_email_verification_enabled' => ['short' => 'email_verification_enabled', 'fallback' => '1', 'secret' => false],
    'integration_phone_verification_enabled' => ['short' => 'phone_verification_enabled', 'fallback' => '1', 'secret' => false],
]);

// Google Maps — stored as a single `configuration` JSON document on the
// google_maps row of the `integrations` table (the provider registry), which
// is the ONE source of truth. Read + written here through the same service
// layer helpers in includes/google-maps.php (getGoogleMapsConfig() /
// setGoogleMapsConfig()). The api_key is stored in plaintext exactly like the
// table's other provider rows (Paystack/Twilio/SMTP) — no encryption layer is
// applied, matching the existing mechanism. The admin UI speaks the short
// field names below; this table maps each UI field → JSON field in the
// integrations config so read + write stay in sync.
//   api_key            -> the one Google Maps key (browser + server)
//   enable_places      -> Places / Autocomplete
//   enable_directions  -> Directions + Distance Matrix (routing family)
//   enable_geocoding   -> Geocoding / Reverse geocoding
const MAPS_PROVIDER = 'google_maps';
define('MAPS_CONFIG_FIELDS', [
    'api_key' => ['json' => 'api_key', 'secret' => true],
    'enable_places' => ['json' => 'enable_places', 'secret' => false],
    'enable_directions' => ['json' => 'enable_directions', 'secret' => false],
    'enable_geocoding' => ['json' => 'enable_geocoding', 'secret' => false],
]);

// Payment (Paystack) — the existing payment integration, controlled from here
// through the same `integrations` provider registry that Google Maps uses. The
// row's `status` column is the backend Enable/Disable switch (enforced in
// functions.php paystack_initialize_transaction()); the `configuration` JSON
// holds the credentials. public_key is the publishable key (safe to show in
// full); secret_key and webhook_secret are AES-encrypted at rest and only ever
// leave this API as a mask — the browser NEVER receives the decrypted secret.
// All read/write goes through includes/payment-integration.php; no second
// payment table is created and no credential is hardcoded anywhere.
//   public_key      -> Paystack publishable key (browser-safe)
//   secret_key      -> Paystack secret key (server-only, encrypted, masked)
//   webhook_secret  -> Paystack webhook signing secret (server-only, encrypted, masked)
//   sandbox_mode    -> "1" test / "0" live
//   enabled         -> the row's status column (enabled/disabled)

// Social Login (Google / Facebook OAuth) — credentials + the per-provider
// ON/OFF switch, stored in integration_settings exactly like the Email/SMS
// keys. The client secret / app secret are AES-encrypted at rest (secret =>
// true) and NEVER leave this API except as a mask. The enable flag controls
// BOTH the frontend button and the backend authorize/callback (enforced in
// includes/oauth.php's oauth_provider_usable()), not just the UI. The redirect
// URI defaults to the app's fixed callback file and is what the admin must
// register with the provider. 'bool' marks the enable toggle so a save stores
// it as a plain "1"/"0" rather than as an (encrypted) secret.
const GOOGLE_OAUTH_KEYS = [
    'integration_google_oauth_enabled'       => ['short' => 'enabled',       'fallback' => '0', 'secret' => false, 'bool' => true],
    'integration_google_oauth_client_id'     => ['short' => 'client_id',     'fallback' => '',  'secret' => false],
    'integration_google_oauth_client_secret' => ['short' => 'client_secret', 'fallback' => '',  'secret' => true],
    'integration_google_oauth_redirect_uri'  => ['short' => 'redirect_uri',  'fallback' => '',  'secret' => false],
];
const FACEBOOK_OAUTH_KEYS = [
    'integration_facebook_oauth_enabled'      => ['short' => 'enabled',       'fallback' => '0', 'secret' => false, 'bool' => true],
    'integration_facebook_oauth_app_id'       => ['short' => 'client_id',     'fallback' => '',  'secret' => false],
    'integration_facebook_oauth_app_secret'   => ['short' => 'client_secret', 'fallback' => '',  'secret' => true],
    'integration_facebook_oauth_redirect_uri' => ['short' => 'redirect_uri',  'fallback' => '',  'secret' => false],
];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (($_GET['action'] ?? '') === 'diagnostics') {
        require_once '../includes/diagnostics.php';
        json_response('success', 'Diagnostics collected', run_integration_diagnostics($pdo));
    }

    $out = ['email' => [], 'sms' => [], 'otp' => [], 'policy' => [], 'verification' => [], 'maps' => [], 'payment' => [], 'google_oauth' => [], 'facebook_oauth' => []];
    // A secret that is present in the DB but unreadable under the current key
    // (key rotated after the value was saved) surfaces as `needsReentry: true`
    // so the admin UI can prompt a re-save instead of pretending it's unset.
    foreach (EMAIL_SETTING_KEYS as $key => $meta) {
        $value = (string) get_integration_setting($pdo, $key, $meta['fallback']);
        $out['email'][$meta['short']] = $meta['secret']
            ? ['configured' => $value !== '', 'masked' => mask_secret($value), 'needsReentry' => $value === INTEGRATION_SECRET_UNDECRYPTABLE]
            : $value;
    }
    foreach (SMS_SETTING_KEYS as $key => $meta) {
        $value = (string) get_integration_setting($pdo, $key, $meta['fallback']);
        $out['sms'][$meta['short']] = $meta['secret']
            ? ['configured' => $value !== '', 'masked' => mask_secret($value), 'needsReentry' => $value === INTEGRATION_SECRET_UNDECRYPTABLE]
            : $value;
    }
    foreach (OTP_SETTING_KEYS as $key => $meta) {
        $out['otp'][$meta['short']] = (string) get_integration_setting($pdo, $key, $meta['fallback']);
    }
    foreach (POLICY_SETTING_KEYS as $key => $meta) {
        $out['policy'][$meta['short']] = get_integration_setting_bool($pdo, $key, $meta['fallback'] === '1');
    }
    foreach (VERIFICATION_SETTING_KEYS as $key => $meta) {
        $out['verification'][$meta['short']] = get_integration_setting_bool($pdo, $key, $meta['fallback'] === '1');
    }
    // Maps config lives in the google_maps row of the `integrations` table
    // (configuration JSON) — the single source of truth. Report exactly what
    // the service layer will serve: api_key masked (never echoed in full),
    // toggles as booleans, plus the map center/zoom defaults the card edits.
    $mapsConfig = getGoogleMapsConfig($pdo);
    $mapsFlags = googleMapsEnabledFlags($pdo);
    $flagShortToResolved = ['enable_places' => 'places', 'enable_directions' => 'directions', 'enable_geocoding' => 'geocoding'];
    foreach (MAPS_CONFIG_FIELDS as $short => $meta) {
        if ($meta['secret']) {
            $effective = getGoogleMapsKey($pdo);
            $out['maps'][$short] = [
                'configured' => $effective !== '',
                'masked' => mask_secret($effective),
                'needsReentry' => false, // plaintext store (like the table's other rows) — no decrypt failure mode
            ];
        } else {
            $out['maps'][$short] = (bool) ($mapsFlags[$flagShortToResolved[$short]] ?? true);
        }
    }
    $out['maps']['default_lat'] = (string) ($mapsConfig['default_lat'] ?? '');
    $out['maps']['default_lng'] = (string) ($mapsConfig['default_lng'] ?? '');
    $out['maps']['default_zoom'] = (string) ($mapsConfig['default_zoom'] ?? '');

    // Payment (Paystack) — status + configuration from the paystack row of the
    // `integrations` table (single source of truth). The secret_key and
    // webhook_secret are reported ONLY as a mask (bullet dots + last 4); the
    // decrypted secret is never placed in this response, so the browser can
    // never receive the Paystack secret key. `needsReentry` flags a secret that
    // is stored but no longer decryptable under the current app key.
    $payRow = getPaymentIntegration($pdo);
    $payConfig = $payRow['configuration'] ?? [];
    $out['payment']['provider'] = 'paystack';
    $out['payment']['enabled'] = $payRow !== null && $payRow['status'] === 'enabled';
    $out['payment']['configured'] = paystackConfigured($pdo);
    $out['payment']['public_key'] = (string) ($payConfig['public_key'] ?? '');
    $out['payment']['sandbox_mode'] = ((string) ($payConfig['sandbox_mode'] ?? '0')) === '1';
    $out['payment']['currency'] = (string) ($payConfig['currency'] ?? 'NGN');
    $out['payment']['mode'] = $out['payment']['sandbox_mode'] ? 'test' : 'live';
    foreach (['secret_key', 'webhook_secret'] as $payShort) {
        $enc = (string) ($payConfig[$payShort] ?? '');
        $plain = $enc !== '' ? decrypt_secret($enc) : '';
        $out['payment'][$payShort] = [
            'configured' => $enc !== '',
            'masked' => mask_secret($plain === null ? '' : $plain),
            'needsReentry' => $enc !== '' && secret_is_undecryptable($enc),
        ];
    }
    // Social Login (Google / Facebook) — the ON/OFF switch, public client id
    // and the fixed redirect URI, plus the secret reported ONLY as a mask. The
    // decrypted client secret / app secret is NEVER placed in this response, so
    // the browser can never receive it. `needsReentry` flags a secret that is
    // stored but no longer decryptable under the current app key. `configured`
    // and `usable` mirror exactly what includes/oauth.php enforces on the login
    // flow, so the admin card's status badge can never disagree with reality.
    $oauthBlock = function (string $provider) use ($pdo): array {
        $meta = oauth_provider_meta($provider);
        $cfg = oauth_provider_config($pdo, $provider); // authoritative enabled/configured/usable + redirect fallback
        $secretRaw = get_integration_setting($pdo, $meta['client_secret_key'], '');
        $secretUndecryptable = ($secretRaw === INTEGRATION_SECRET_UNDECRYPTABLE);
        $secretPlainForMask = $secretUndecryptable ? '' : (string) $secretRaw;
        return [
            'enabled' => $cfg['enabled'],
            'client_id' => $cfg['client_id'],
            'client_secret' => [
                'configured' => $secretRaw !== '', // sentinel is non-empty => stored-but-unreadable still counts as configured
                'masked' => mask_secret($secretPlainForMask),
                'needsReentry' => $secretUndecryptable,
            ],
            'redirect_uri' => $cfg['redirect_uri'],
            'default_redirect_uri' => oauth_default_redirect_uri($provider),
            'configured' => $cfg['configured'],
            'usable' => $cfg['usable'],
        ];
    };
    $out['google_oauth'] = $oauthBlock('google');
    $out['facebook_oauth'] = $oauthBlock('facebook');

    json_response('success', 'Integration settings fetched', $out);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';

    if ($action === 'save_email' || $action === 'save_sms' || $action === 'save_otp' || $action === 'save_policy' || $action === 'save_verification') {
        require_super_admin($pdo);
        $adminId = $_SESSION['user_id'];
        $keys = ['save_email' => EMAIL_SETTING_KEYS, 'save_sms' => SMS_SETTING_KEYS, 'save_otp' => OTP_SETTING_KEYS, 'save_policy' => POLICY_SETTING_KEYS, 'save_verification' => VERIFICATION_SETTING_KEYS][$action];
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $changed = [];

        foreach ($keys as $key => $meta) {
            $shortKey = $meta['short'];
            if (!array_key_exists($shortKey, $settings)) {
                continue; // field not part of this submit at all — leave untouched
            }
            $rawValue = $settings[$shortKey];
            $value = is_bool($rawValue) ? ($rawValue ? '1' : '0') : trim((string) $rawValue);
            if ($meta['secret'] && $value === '') {
                continue; // blank secret field means "leave the saved credential unchanged"
            }
            set_integration_setting($pdo, $adminId, $key, $value, $meta['secret']);
            $changed[] = $shortKey;
        }

        json_response('success', $changed ? 'Settings saved.' : 'No changes to save.', ['changed' => $changed]);
    }

    // Google Maps saves write the google_maps `configuration` JSON on the
    // `integrations` table — the single source of truth — via the service
    // layer's setGoogleMapsConfig(). The api_key is stored plaintext (matching
    // the table's other provider rows); a blank api_key field leaves the saved
    // key unchanged. Toggles are stored in the seeded "1"/"0" string form. The
    // merge in setGoogleMapsConfig() preserves default_lat/lng/zoom and any
    // other fields not present in this submit.
    if ($action === 'save_maps') {
        require_super_admin($pdo);
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $incoming = [];
        $changed = [];

        foreach (MAPS_CONFIG_FIELDS as $short => $meta) {
            if (!array_key_exists($short, $settings)) {
                continue; // field not part of this submit — leave untouched
            }
            $jsonKey = $meta['json'];
            $rawValue = $settings[$short];
            if ($meta['secret']) {
                $plain = is_string($rawValue) ? trim($rawValue) : '';
                if ($plain === '') {
                    continue; // blank key => keep the stored key
                }
                $incoming[$jsonKey] = $plain;
            } else {
                // Store toggles as "1"/"0" strings, matching the seeded row.
                $on = is_bool($rawValue) ? $rawValue : in_array(strtolower((string) $rawValue), ['1', 'true', 'yes', 'on'], true);
                $incoming[$jsonKey] = $on ? '1' : '0';
            }
            $changed[] = $short;
        }

        // Optional map center/zoom defaults the card may also submit.
        foreach (['default_lat', 'default_lng', 'default_zoom'] as $extra) {
            if (array_key_exists($extra, $settings) && trim((string) $settings[$extra]) !== '') {
                $incoming[$extra] = trim((string) $settings[$extra]);
                $changed[] = $extra;
            }
        }

        if ($changed) {
            setGoogleMapsConfig($pdo, $incoming);
            log_integration_change($pdo, $_SESSION['user_id'], 'google_maps.configuration', '***', '***');
        }
        json_response('success', $changed ? 'Settings saved.' : 'No changes to save.', ['changed' => $changed]);
    }

    if ($action === 'test_email') {
        require_super_admin($pdo);
        $to = trim((string) ($input['to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            json_response('error', 'Enter a valid email address to send the test to.');
        }
        $html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;">'
            . '<h2 style="color:#0F8B8D;">MohToh Integration Test</h2>'
            . '<p>This is a test email from the MohToh Service Integration Center. If you received this, your email delivery is configured correctly.</p>'
            . '</div>';
        $result = get_email_provider($pdo)->send($to, 'MohToh Integration Test Email', $html);
        log_integration_event('email', 'test_send', $result['success'] ? 'success' : 'failed', $result['message'], ['to' => $to]);
        json_response($result['success'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'test_sms') {
        require_super_admin($pdo);
        $normalized = normalize_phone_number(trim((string) ($input['to'] ?? '')));
        if (!$normalized) {
            json_response('error', 'Enter a valid phone number to send the test to.');
        }
        $result = get_sms_provider($pdo)->send($normalized, 'This is a test SMS from the MohToh Service Integration Center.');
        log_integration_event('sms', 'test_send', $result['success'] ? 'success' : 'failed', $result['message'], ['to' => $normalized]);
        json_response($result['success'] ? 'success' : 'error', $result['message']);
    }

    if ($action === 'test_maps') {
        require_super_admin($pdo);
        // A live, key-consuming call: geocode a known MohToh landmark
        // (Redemption Camp) via the server key. Proves the key is valid,
        // the Geocoding API is enabled, and billing is active — the same
        // credential path Distance Matrix/Directions use server-side.
        if (!googleMapsConfigured($pdo)) {
            log_integration_event('maps', 'test_connection', 'failed', 'No Google Maps key configured.');
            json_response('error', 'Save a Google Maps API key first, then test the connection.');
        }
        $geo = reverseGeocode($pdo, 6.9083, 3.3870);
        if ($geo !== null) {
            $detail = 'Google Maps reachable — resolved ' . ($geo['formatted_address'] ?: 'a location') . '.';
            log_integration_event('maps', 'test_connection', 'success', $detail);
            json_response('success', $detail);
        }
        // reverseGeocode returned null — re-issue the raw call to surface Google's own status.
        $raw = _google_maps_http_get('https://maps.googleapis.com/maps/api/geocode/json?latlng=6.9083,3.3870', getGoogleMapsServerKey($pdo));
        $status = $raw['status'] ?? null;
        $msg = google_maps_error_message($status, 'Could not reach Google Maps — check the key, enabled APIs, and billing.');
        if ($status && isset($raw['error_message'])) {
            $msg .= ' (' . $raw['error_message'] . ')';
        }
        log_integration_event('maps', 'test_connection', 'failed', $msg, ['google_status' => $status]);
        json_response('error', $msg);
    }

    // Payment (Paystack) save — writes the paystack `configuration` JSON and the
    // row's enabled/disabled `status` on the `integrations` table, the single
    // source of truth, via includes/payment-integration.php setPaystackConfig().
    // The publishable key is stored plaintext; secret_key/webhook_secret are
    // encrypted inside setPaystackConfig() and a blank field leaves the stored
    // credential unchanged. Enable/Disable here is enforced by the backend
    // payment flow (functions.php paystack_initialize_transaction()), not just
    // the UI. Super-admin only, matching every other money-adjacent write.
    if ($action === 'save_payment') {
        require_super_admin($pdo);
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $incoming = [];
        $changed = [];

        // Publishable key — safe to store/display in the clear.
        if (array_key_exists('public_key', $settings)) {
            $incoming['public_key'] = trim((string) $settings['public_key']);
            $changed[] = 'public_key';
        }
        // Secret fields — blank means "leave the stored (encrypted) value
        // unchanged"; a non-blank value is encrypted inside setPaystackConfig().
        foreach (['secret_key', 'webhook_secret'] as $payShort) {
            if (!array_key_exists($payShort, $settings)) {
                continue;
            }
            $plain = is_string($settings[$payShort]) ? trim($settings[$payShort]) : '';
            if ($plain === '') {
                continue;
            }
            $incoming[$payShort] = $plain;
            $changed[] = $payShort;
        }
        // Sandbox/live toggle, stored as "1"/"0" strings like the seeded row.
        if (array_key_exists('sandbox_mode', $settings)) {
            $raw = $settings['sandbox_mode'];
            $on = is_bool($raw) ? $raw : in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
            $incoming['sandbox_mode'] = $on ? '1' : '0';
            $changed[] = 'sandbox_mode';
        }
        // Enable/Disable → the row's status column (the backend-enforced switch).
        $status = null;
        if (array_key_exists('enabled', $settings)) {
            $raw = $settings['enabled'];
            $en = is_bool($raw) ? $raw : in_array(strtolower((string) $raw), ['1', 'true', 'yes', 'on'], true);
            $status = $en ? 'enabled' : 'disabled';
            $changed[] = 'enabled';
        }

        if ($changed) {
            if (!setPaystackConfig($pdo, $incoming, $status)) {
                json_response('error', 'Paystack integration row not found — cannot save.');
            }
            log_integration_change($pdo, $_SESSION['user_id'], 'paystack.configuration', '***', '***');
            log_integration_event('payment', 'config_saved', 'success', 'Paystack settings updated: ' . implode(', ', $changed) . '.');
        }
        json_response('success', $changed ? 'Payment settings saved.' : 'No changes to save.', ['changed' => $changed]);
    }

    // Payment (Paystack) Test Connection — a READ-ONLY authenticated probe
    // (GET /balance) that proves the saved secret key is valid and the account
    // is reachable, WITHOUT creating or charging any transaction (no real
    // customer payment is processed). It deliberately bypasses the
    // enable/disable gate — that gate only blocks new charges — so an admin can
    // verify credentials before switching payments on. The secret itself is
    // used server-side only and never returned to the browser.
    if ($action === 'test_payment') {
        require_super_admin($pdo);
        if (!paystackConfigured($pdo)) {
            log_integration_event('payment', 'test_connection', 'failed', 'Paystack keys not configured.');
            json_response('error', 'Save your Paystack public and secret keys first, then test the connection.');
        }
        try {
            $resp = paystack_request('GET', '/balance');
        } catch (\Throwable $e) {
            log_integration_event('payment', 'test_connection', 'failed', $e->getMessage());
            json_response('error', 'Could not reach Paystack — ' . $e->getMessage());
        }
        if (!empty($resp['status'])) {
            $cur = $resp['data'][0]['currency'] ?? null;
            $detail = 'Paystack reachable — secret key authenticated' . ($cur ? ' (' . $cur . ' balance visible).' : '.');
            log_integration_event('payment', 'test_connection', 'success', $detail);
            json_response('success', $detail);
        }
        $msg = $resp['message'] ?? 'Paystack rejected the request — check the secret key.';
        log_integration_event('payment', 'test_connection', 'failed', $msg);
        json_response('error', $msg);
    }

    // Social Login (Google / Facebook) save — writes the provider's enable
    // switch, public client id and redirect URI, plus the encrypted secret, to
    // integration_settings via set_integration_setting() (same encrypted-at-rest
    // path as the Email/SMS secrets). A blank secret field leaves the stored
    // credential unchanged, so re-saving other fields never wipes the secret.
    // Enable/Disable here is the SAME flag oauth_provider_usable() enforces on
    // the authorize + callback endpoints, so turning a provider off blocks the
    // backend too — not merely the button. Super-admin only, matching every
    // other credential write.
    if ($action === 'save_google_oauth' || $action === 'save_facebook_oauth') {
        require_super_admin($pdo);
        $adminId = $_SESSION['user_id'];
        $provider = $action === 'save_google_oauth' ? 'google' : 'facebook';
        $keys = $action === 'save_google_oauth' ? GOOGLE_OAUTH_KEYS : FACEBOOK_OAUTH_KEYS;
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];
        $changed = [];

        foreach ($keys as $key => $meta) {
            $shortKey = $meta['short'];
            if (!array_key_exists($shortKey, $settings)) {
                continue; // field not part of this submit — leave untouched
            }
            $rawValue = $settings[$shortKey];
            if (!empty($meta['bool'])) {
                $on = is_bool($rawValue) ? $rawValue : in_array(strtolower((string) $rawValue), ['1', 'true', 'yes', 'on'], true);
                set_integration_setting($pdo, $adminId, $key, $on ? '1' : '0', false);
                $changed[] = $shortKey;
                continue;
            }
            $value = is_bool($rawValue) ? ($rawValue ? '1' : '0') : trim((string) $rawValue);
            if ($meta['secret'] && $value === '') {
                continue; // blank secret field means "leave the saved credential unchanged"
            }
            set_integration_setting($pdo, $adminId, $key, $value, $meta['secret']);
            $changed[] = $shortKey;
        }

        // Audit — records WHICH fields changed (never their values; the secret
        // is masked to '***' inside set_integration_setting()'s own change log).
        if ($changed) {
            oauth_log('config_saved', 'success', oauth_provider_label($provider) . ' OAuth settings updated', ['provider' => $provider, 'changed' => $changed]);
        }
        json_response('success', $changed ? 'Settings saved.' : 'No changes to save.', ['changed' => $changed]);
    }

    // Social Login Test Configuration — a server-side probe that proves the
    // saved credentials are valid WITHOUT performing any real user login. The
    // secret is used server-to-server only and never returned to the browser;
    // only a short, safe status is surfaced (never the raw provider payload,
    // tokens, or codes).
    if ($action === 'test_google_oauth') {
        require_super_admin($pdo);
        $cfg = oauth_provider_config($pdo, 'google');
        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
            if (!empty($cfg['secret_undecryptable'])) {
                json_response('error', 'The saved Client Secret could not be read (encryption key changed). Re-enter it, save, then test.');
            }
            json_response('error', 'Enter and save the Google Client ID and Client Secret first, then test.');
        }
        // Redeem a deliberately invalid authorization code. If the CREDENTIALS
        // are valid, Google authenticates the client and rejects only the code
        // (invalid_grant); bad credentials fail earlier with invalid_client. No
        // real login occurs and no usable token is ever issued.
        $resp = oauth_http('POST', 'https://oauth2.googleapis.com/token', [
            'code' => 'connection-test-invalid-code',
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);
        $err = (string) ($resp['json']['error'] ?? '');
        if ($err === 'invalid_grant') {
            oauth_log('config_test', 'success', 'Google OAuth credentials verified', ['provider' => 'google']);
            json_response('success', 'Google credentials look valid — the client authenticated successfully. Make sure the redirect URI below is registered in your Google Cloud console.');
        }
        if ($err === 'invalid_client' || $err === 'unauthorized_client') {
            oauth_log('config_test', 'failed', 'Google OAuth client rejected', ['provider' => 'google', 'error' => $err]);
            json_response('error', 'Google rejected the Client ID/Secret. Double-check both values.');
        }
        if ($resp['status'] === 0) {
            oauth_log('config_test', 'failed', 'Google OAuth unreachable', ['provider' => 'google']);
            json_response('error', 'Could not reach Google to verify the credentials. Please try again.');
        }
        oauth_log('config_test', 'failed', 'Google OAuth test inconclusive', ['provider' => 'google', 'error' => substr($err, 0, 40)]);
        json_response('error', $err !== ''
            ? ('Google returned "' . $err . '" — check the Client ID, Secret and redirect URI.')
            : 'Google could not verify the credentials. Please re-check them.');
    }

    if ($action === 'test_facebook_oauth') {
        require_super_admin($pdo);
        $cfg = oauth_provider_config($pdo, 'facebook');
        if ($cfg['client_id'] === '' || $cfg['client_secret'] === '') {
            if (!empty($cfg['secret_undecryptable'])) {
                json_response('error', 'The saved App Secret could not be read (encryption key changed). Re-enter it, save, then test.');
            }
            json_response('error', 'Enter and save the Facebook App ID and App Secret first, then test.');
        }
        // Request an APP access token (client_credentials): proves the App ID +
        // App Secret pair is valid with no user interaction. The returned app
        // token is inspected only for presence and never stored or returned.
        $resp = oauth_http('GET', 'https://graph.facebook.com/v19.0/oauth/access_token', [
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'grant_type' => 'client_credentials',
        ]);
        if ($resp['ok'] && !empty($resp['json']['access_token'])) {
            oauth_log('config_test', 'success', 'Facebook OAuth credentials verified', ['provider' => 'facebook']);
            json_response('success', 'Facebook credentials look valid — the App ID and Secret were accepted. Make sure the redirect URI below is listed as a Valid OAuth Redirect URI in your Meta app.');
        }
        if ($resp['status'] === 0) {
            oauth_log('config_test', 'failed', 'Facebook OAuth unreachable', ['provider' => 'facebook']);
            json_response('error', 'Could not reach Facebook to verify the credentials. Please try again.');
        }
        oauth_log('config_test', 'failed', 'Facebook OAuth credentials rejected', ['provider' => 'facebook']);
        json_response('error', 'Facebook rejected the App ID/Secret. Double-check both values.');
    }

    json_response('error', 'Unknown action.');
}

json_response('error', 'Invalid request method');
