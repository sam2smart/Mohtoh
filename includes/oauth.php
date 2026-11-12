<?php
// includes/oauth.php
// Secure Social Login service library (Continue with Google / Facebook).
//
// This is the ONLY place the OAuth 2.0 authorization-code flow lives. The
// thin route files under auth/oauth/ and the api/social-accounts.php endpoint
// call into these functions; they hold no flow logic of their own. Design
// rules enforced here:
//
//   * Authorization-code flow ONLY. The browser never sees a client/app
//     secret, an authorization code, or an access/refresh/id token. Code is
//     exchanged server-to-server over TLS (peer + host verified).
//   * Provider identity is taken from the provider via the authenticated flow
//     (Google id_token validated at Google's tokeninfo endpoint; Facebook
//     access token verified with debug_token + appsecret_proof) — NEVER from a
//     value the user could submit.
//   * A provider's stable unique id (Google `sub`, Facebook `id`) is the
//     permanent key (oauth_identities.UNIQUE(provider, provider_user_id)).
//     Email is never the sole permanent identifier and never auto-links an
//     account by itself.
//   * The resulting login is the SAME session the password login creates
//     (oauth_establish_session mirrors api/auth.php exactly) — one auth system,
//     one session, same role/verification gates. No weaker parallel session.
//   * Secrets are read decrypted server-side from integration_settings and are
//     never logged. Audit events go to integration_logs via oauth_log() and
//     carry only high-level metadata (never tokens/codes/secrets/session ids).
//
// Config is stored in the existing integration_settings table (encrypted
// secrets) and managed from the Admin Integration Center — no hardcoded
// credentials anywhere.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/integration-settings.php';
require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/verification.php';

const OAUTH_PROVIDERS = ['google', 'facebook'];
const OAUTH_STATE_TTL_SECONDS = 600;    // authorization round-trip window (10 min)
const OAUTH_PENDING_TTL_SECONDS = 900;  // signup/link completion window (15 min)

// ---------------------------------------------------------------------------
// Provider metadata + configuration
// ---------------------------------------------------------------------------

function oauth_is_valid_provider(string $provider): bool {
    return in_array($provider, OAUTH_PROVIDERS, true);
}

// Maps a provider to its integration_settings keys and its fixed callback
// path. The callback path is part of the application (a real file); it is
// NEVER taken from a query parameter, which is what prevents open-redirect /
// redirect-uri tampering.
function oauth_provider_meta(string $provider): ?array {
    switch ($provider) {
        case 'google':
            return [
                'label' => 'Google',
                'enabled_key' => 'integration_google_oauth_enabled',
                'client_id_key' => 'integration_google_oauth_client_id',
                'client_secret_key' => 'integration_google_oauth_client_secret',
                'redirect_uri_key' => 'integration_google_oauth_redirect_uri',
                'callback_path' => '/auth/oauth/google/callback.php',
            ];
        case 'facebook':
            return [
                'label' => 'Facebook',
                'enabled_key' => 'integration_facebook_oauth_enabled',
                'client_id_key' => 'integration_facebook_oauth_app_id',
                'client_secret_key' => 'integration_facebook_oauth_app_secret',
                'redirect_uri_key' => 'integration_facebook_oauth_redirect_uri',
                'callback_path' => '/auth/oauth/facebook/callback.php',
            ];
    }
    return null;
}

function oauth_provider_label(string $provider): string {
    $meta = oauth_provider_meta($provider);
    return $meta ? $meta['label'] : ucfirst($provider);
}

// The application's own, fixed callback URL for a provider — the value an
// admin must register with Google/Facebook. Auto-derived from the resolved
// base URL so it is correct per-deployment; an admin may override it in the
// Integration Center if their registered URI differs.
function oauth_default_redirect_uri(string $provider): string {
    $meta = oauth_provider_meta($provider);
    if (!$meta) {
        return '';
    }
    return rtrim(mt_get_base_url(), '/') . $meta['callback_path'];
}

// Full server-side config for a provider, including the DECRYPTED secret for
// server-to-server use. Never expose the returned client_secret to the client.
function oauth_provider_config($pdo, string $provider): ?array {
    $meta = oauth_provider_meta($provider);
    if (!$meta) {
        return null;
    }
    $enabled = get_integration_setting_bool($pdo, $meta['enabled_key'], false);
    $clientId = trim((string) get_integration_setting($pdo, $meta['client_id_key'], ''));

    $secretRaw = get_integration_setting($pdo, $meta['client_secret_key'], '');
    $secretUndecryptable = ($secretRaw === INTEGRATION_SECRET_UNDECRYPTABLE);
    $clientSecret = $secretUndecryptable ? '' : trim((string) $secretRaw);

    $redirectUri = trim((string) get_integration_setting($pdo, $meta['redirect_uri_key'], ''));
    if ($redirectUri === '') {
        $redirectUri = oauth_default_redirect_uri($provider);
    }

    $configured = ($clientId !== '' && $clientSecret !== '' && $redirectUri !== '');
    return [
        'provider' => $provider,
        'label' => $meta['label'],
        'enabled' => $enabled,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'configured' => $configured,
        'secret_undecryptable' => $secretUndecryptable,
        'usable' => ($enabled && $configured),
    ];
}

// A provider is usable only when it is BOTH enabled AND fully configured. This
// single check backs the frontend button rendering, the start endpoint, and
// the callback — so a disabled provider is rejected at every layer, not merely
// hidden in the UI.
function oauth_provider_usable($pdo, string $provider): bool {
    $cfg = oauth_provider_config($pdo, $provider);
    return $cfg !== null && $cfg['usable'];
}

// Browser-safe provider availability map (NO secrets) for the public
// providers.php endpoint and server-side button rendering.
function oauth_public_providers($pdo): array {
    $out = [];
    foreach (OAUTH_PROVIDERS as $p) {
        $out[$p] = oauth_provider_usable($pdo, $p);
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Client IP + rate limiting (OAuth needs IP-based limiting; the app's existing
// limiter is per-account lockout only)
// ---------------------------------------------------------------------------

function oauth_client_ip(): string {
    // REMOTE_ADDR only. X-Forwarded-* is deliberately NOT trusted for a
    // security decision because this deployment sits behind no trusted proxy;
    // trusting it would let a client spoof its own rate-limit identity.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return $ip !== '' ? $ip : 'unknown';
}

// Fixed-window counter in auth_rate_limits. Returns true when the request is
// ALLOWED (recording a hit), false when the window's limit is exceeded. Its
// own failures never block authentication (fail-open with an audit note) — a
// broken limiter must not become a lockout of all users.
function oauth_rate_limit($pdo, string $bucket, string $identifier, int $maxHits, int $windowSeconds): bool {
    try {
        $stmt = $pdo->prepare("SELECT id, hits, UNIX_TIMESTAMP(window_start) AS ws FROM auth_rate_limits WHERE bucket = ? AND identifier = ?");
        $stmt->execute([$bucket, $identifier]);
        $row = $stmt->fetch();
        $now = time();

        if (!$row) {
            try {
                $ins = $pdo->prepare("INSERT INTO auth_rate_limits (bucket, identifier, hits, window_start) VALUES (?, ?, 1, NOW())");
                $ins->execute([$bucket, $identifier]);
            } catch (\Throwable $dup) {
                // Concurrent insert won the race — treat as allowed this once.
            }
            return true;
        }
        if (($now - (int) $row['ws']) >= $windowSeconds) {
            $upd = $pdo->prepare("UPDATE auth_rate_limits SET hits = 1, window_start = NOW() WHERE id = ?");
            $upd->execute([$row['id']]);
            return true;
        }
        if ((int) $row['hits'] >= $maxHits) {
            return false;
        }
        $upd = $pdo->prepare("UPDATE auth_rate_limits SET hits = hits + 1 WHERE id = ?");
        $upd->execute([$row['id']]);
        return true;
    } catch (\Throwable $e) {
        oauth_log('rate_limit', 'error', 'Rate limiter error', ['bucket' => $bucket]);
        return true;
    }
}

// ---------------------------------------------------------------------------
// PKCE (Proof Key for Code Exchange) — used for Google to bind the auth code
// to this backend, so an intercepted code cannot be redeemed elsewhere.
// ---------------------------------------------------------------------------

function oauth_base64url(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function oauth_generate_code_verifier(): string {
    return oauth_base64url(random_bytes(48)); // 64 url-safe chars (43..128 allowed)
}

function oauth_code_challenge(string $verifier): string {
    return oauth_base64url(hash('sha256', $verifier, true));
}

// ---------------------------------------------------------------------------
// Anti-forgery state — cryptographically random, stored server-side (hashed),
// single-use, expiring. Reused/expired/missing/mismatched state is rejected.
// ---------------------------------------------------------------------------

function oauth_create_state($pdo, string $provider, string $mode, ?int $userId, ?string $roleHint, ?string $codeVerifier): string {
    oauth_gc_states($pdo);
    $raw = bin2hex(random_bytes(32)); // 64 hex chars
    $hash = hash('sha256', $raw);
    $stmt = $pdo->prepare("
        INSERT INTO oauth_states (state_hash, provider, mode, user_id, role_hint, code_verifier, created_ip, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
    ");
    $stmt->execute([$hash, $provider, $mode, $userId, $roleHint, $codeVerifier, oauth_client_ip(), OAUTH_STATE_TTL_SECONDS]);
    return $raw;
}

// Validates and CONSUMES (single-use) the returned state. The row is deleted
// on first touch regardless of outcome, so a replayed state finds nothing.
function oauth_consume_state($pdo, string $provider, string $rawState): ?array {
    if (strlen($rawState) !== 64 || !ctype_xdigit($rawState)) {
        return null;
    }
    $hash = hash('sha256', $rawState);
    $stmt = $pdo->prepare("SELECT * FROM oauth_states WHERE state_hash = ?");
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    if (!$row) {
        return null; // missing, already consumed, or forged
    }
    $del = $pdo->prepare("DELETE FROM oauth_states WHERE id = ?");
    $del->execute([$row['id']]);

    if ($row['provider'] !== $provider) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null; // expired
    }
    return $row;
}

function oauth_gc_states($pdo): void {
    try {
        $pdo->query("DELETE FROM oauth_states WHERE expires_at < NOW()");
    } catch (\Throwable $e) {
        // best-effort housekeeping
    }
}

// ---------------------------------------------------------------------------
// TLS-verified HTTP (server-to-server only)
// ---------------------------------------------------------------------------

function oauth_http(string $method, string $url, array $params = [], array $headers = []): array {
    $ch = curl_init();
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,  // reject invalid/forged TLS certs
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
    ];
    if (strtoupper($method) === 'POST') {
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
    } else {
        $q = $params ? ((strpos($url, '?') === false ? '?' : '&') . http_build_query($params)) : '';
        $opts[CURLOPT_URL] = $url . $q;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'status' => 0, 'json' => null, 'raw' => '', 'error' => $err];
    }
    $json = json_decode($body, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => $body,
        'error' => $err,
    ];
}

// ---------------------------------------------------------------------------
// Google — authorize URL, code exchange, and VALIDATED identity
// ---------------------------------------------------------------------------

function oauth_google_authorize_url(array $cfg, string $state, string $codeChallenge): string {
    $params = [
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'code_challenge' => $codeChallenge,
        'code_challenge_method' => 'S256',
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// Exchanges the code, then validates the returned id_token at Google's
// tokeninfo endpoint (verifies signature + expiry server-side) and additionally
// checks aud == our client_id and iss is a Google issuer, per OpenID Connect.
// Returns a normalized identity array or null on any failure.
function oauth_google_exchange_and_identity($pdo, array $cfg, string $code, string $codeVerifier): ?array {
    $token = oauth_http('POST', 'https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri' => $cfg['redirect_uri'],
        'grant_type' => 'authorization_code',
        'code_verifier' => $codeVerifier,
    ]);
    if (!$token['ok'] || empty($token['json']['id_token'])) {
        oauth_log('login', 'failed', 'Google token exchange failed', ['status' => $token['status']]);
        return null;
    }

    $idToken = (string) $token['json']['id_token'];
    $info = oauth_http('GET', 'https://oauth2.googleapis.com/tokeninfo', ['id_token' => $idToken]);
    if (!$info['ok'] || !is_array($info['json'])) {
        oauth_log('login', 'failed', 'Google id_token validation failed', ['status' => $info['status']]);
        return null;
    }
    $claims = $info['json'];
    $aud = (string) ($claims['aud'] ?? '');
    $iss = (string) ($claims['iss'] ?? '');
    $sub = (string) ($claims['sub'] ?? '');

    if (!hash_equals((string) $cfg['client_id'], $aud)) {
        oauth_log('login', 'failed', 'Google id_token aud mismatch');
        return null;
    }
    if (!in_array($iss, ['accounts.google.com', 'https://accounts.google.com'], true)) {
        oauth_log('login', 'failed', 'Google id_token iss invalid');
        return null;
    }
    if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
        oauth_log('login', 'failed', 'Google id_token expired');
        return null;
    }
    if ($sub === '') {
        return null;
    }

    $emailVerified = isset($claims['email_verified'])
        && ($claims['email_verified'] === true || $claims['email_verified'] === 'true' || $claims['email_verified'] === 1 || $claims['email_verified'] === '1');

    return [
        'provider' => 'google',
        'provider_user_id' => $sub,
        'email' => isset($claims['email']) ? strtolower(trim((string) $claims['email'])) : '',
        'email_verified' => (bool) $emailVerified,
        'name' => trim((string) ($claims['name'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// Facebook — authorize URL, code exchange, and VALIDATED identity
// ---------------------------------------------------------------------------

function oauth_facebook_authorize_url(array $cfg, string $state): string {
    $params = [
        'client_id' => $cfg['client_id'],
        'redirect_uri' => $cfg['redirect_uri'],
        'state' => $state,
        'response_type' => 'code',
        'scope' => 'email public_profile',
    ];
    return 'https://www.facebook.com/v19.0/dialog/oauth?' . http_build_query($params);
}

// Exchanges the code for an access token, verifies via debug_token that the
// token was minted for OUR app (prevents token-substitution from a different
// app), then reads the profile with an appsecret_proof-signed Graph call.
// Facebook email is treated as UNVERIFIED (see email_verified => false).
function oauth_facebook_exchange_and_identity($pdo, array $cfg, string $code): ?array {
    $token = oauth_http('GET', 'https://graph.facebook.com/v19.0/oauth/access_token', [
        'client_id' => $cfg['client_id'],
        'client_secret' => $cfg['client_secret'],
        'redirect_uri' => $cfg['redirect_uri'],
        'code' => $code,
    ]);
    if (!$token['ok'] || empty($token['json']['access_token'])) {
        oauth_log('login', 'failed', 'Facebook token exchange failed', ['status' => $token['status']]);
        return null;
    }
    $accessToken = (string) $token['json']['access_token'];
    $proof = hash_hmac('sha256', $accessToken, $cfg['client_secret']);

    $appToken = $cfg['client_id'] . '|' . $cfg['client_secret'];
    $debug = oauth_http('GET', 'https://graph.facebook.com/debug_token', [
        'input_token' => $accessToken,
        'access_token' => $appToken,
    ]);
    $debugData = $debug['json']['data'] ?? null;
    if (!$debug['ok'] || !is_array($debugData) || empty($debugData['is_valid'])
        || (string) ($debugData['app_id'] ?? '') !== (string) $cfg['client_id']) {
        oauth_log('login', 'failed', 'Facebook token app/validity check failed');
        return null;
    }

    $me = oauth_http('GET', 'https://graph.facebook.com/v19.0/me', [
        'fields' => 'id,name,email',
        'access_token' => $accessToken,
        'appsecret_proof' => $proof,
    ]);
    if (!$me['ok'] || empty($me['json']['id'])) {
        oauth_log('login', 'failed', 'Facebook profile fetch failed', ['status' => $me['status']]);
        return null;
    }
    $data = $me['json'];
    return [
        'provider' => 'facebook',
        'provider_user_id' => (string) $data['id'],
        'email' => isset($data['email']) ? strtolower(trim((string) $data['email'])) : '',
        'email_verified' => false, // Facebook does not assert verification to us
        'name' => trim((string) ($data['name'] ?? '')),
    ];
}

// ---------------------------------------------------------------------------
// Identity <-> account resolution, linking, session
// ---------------------------------------------------------------------------

function oauth_find_user_by_identity($pdo, string $provider, string $providerUserId): ?array {
    $stmt = $pdo->prepare("
        SELECT u.* FROM oauth_identities oi
        JOIN users u ON u.id = oi.user_id
        WHERE oi.provider = ? AND oi.provider_user_id = ?
    ");
    $stmt->execute([$provider, $providerUserId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function oauth_find_user_by_email($pdo, string $email): ?array {
    if ($email === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function oauth_identity_owner($pdo, string $provider, string $providerUserId) {
    $stmt = $pdo->prepare("SELECT user_id FROM oauth_identities WHERE provider = ? AND provider_user_id = ?");
    $stmt->execute([$provider, $providerUserId]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : (int) $val;
}

function oauth_identity_exists($pdo, string $provider, string $providerUserId): bool {
    return oauth_identity_owner($pdo, $provider, $providerUserId) !== null;
}

// Inserts the social-identity row, guarding the UNIQUE(provider,
// provider_user_id) so a provider account maps to at most one MohToh user.
function oauth_link_identity($pdo, int $userId, array $identity, string $ip): bool {
    if (oauth_identity_exists($pdo, $identity['provider'], $identity['provider_user_id'])) {
        return false;
    }
    $publicId = generate_unique_public_id($pdo, 'oauth_identities', 'public_id', 'OID');
    $stmt = $pdo->prepare("
        INSERT INTO oauth_identities
            (public_id, user_id, provider, provider_user_id, provider_email, provider_email_verified, provider_name, linked_ip)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $publicId,
        $userId,
        $identity['provider'],
        $identity['provider_user_id'],
        ($identity['email'] ?? '') !== '' ? $identity['email'] : null,
        !empty($identity['email_verified']) ? 1 : 0,
        ($identity['name'] ?? '') !== '' ? $identity['name'] : null,
        $ip,
    ]);
    return true;
}

// Refreshes the stored provider email/name on each successful login so the
// connected-accounts view stays current.
function oauth_touch_identity($pdo, string $provider, array $identity): void {
    try {
        $stmt = $pdo->prepare("
            UPDATE oauth_identities
            SET provider_email = ?, provider_email_verified = ?, provider_name = ?
            WHERE provider = ? AND provider_user_id = ?
        ");
        $stmt->execute([
            ($identity['email'] ?? '') !== '' ? $identity['email'] : null,
            !empty($identity['email_verified']) ? 1 : 0,
            ($identity['name'] ?? '') !== '' ? $identity['name'] : null,
            $provider,
            $identity['provider_user_id'],
        ]);
    } catch (\Throwable $e) {
        // non-fatal
    }
}

// Establishes the authenticated session — a byte-for-byte mirror of
// api/auth.php's login success (same keys, same fixation mitigation, same
// verification gate). Returns the app-root-relative redirect target.
function oauth_establish_session($pdo, array $user): string {
    record_successful_login($pdo, $user['id']);
    session_regenerate_id(true); // fresh session id — mitigates fixation

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['public_user_id'] = $user['user_id'];

    $requireEmailToLogin = get_integration_setting_bool($pdo, 'integration_require_email_to_login', defined('REQUIRE_EMAIL_VERIFIED_TO_LOGIN') ? REQUIRE_EMAIL_VERIFIED_TO_LOGIN : true);
    $requirePhoneToLogin = get_integration_setting_bool($pdo, 'integration_require_phone_to_login', defined('REQUIRE_PHONE_VERIFIED_TO_LOGIN') ? REQUIRE_PHONE_VERIFIED_TO_LOGIN : true);
    $needsVerification = ($requireEmailToLogin && is_email_verification_enabled($pdo) && !$user['email_verified'])
        || ($requirePhoneToLogin && is_phone_verification_enabled($pdo) && !$user['phone_verified']);

    return $needsVerification ? 'verify-account.php' : $user['user_type'] . '/dashboard.php';
}

// rider/driver have a profile.php; admin does not — send admins to their
// dashboard for any "back to profile" redirect.
function oauth_user_home(string $userType, string $page = 'dashboard'): string {
    if ($page === 'profile' && in_array($userType, ['rider', 'driver'], true)) {
        return $userType . '/profile.php';
    }
    return $userType . '/dashboard.php';
}

// ---------------------------------------------------------------------------
// New social user creation (called from social-complete.php)
// ---------------------------------------------------------------------------

// Creates a users row + oauth_identity from a server-stashed, provider-VALIDATED
// pending signup plus the role/phone/email the user supplied on the completion
// form. email_verified is set ONLY from the provider's trusted flag — never
// from a form value — so social auth never bypasses the verification policy.
function oauth_create_social_user($pdo, array $pending, string $role, string $phoneRaw, string $emailInput): array {
    $provider = $pending['provider'];
    $providerUserId = $pending['provider_user_id'];
    $providerLabel = oauth_provider_label($provider);

    if (oauth_identity_exists($pdo, $provider, $providerUserId)) {
        return ['ok' => false, 'error' => 'This ' . $providerLabel . ' account is already connected. Please log in instead.'];
    }

    // Role -> auth role + keke vehicle class (identical rule to api/auth.php).
    $driverClass = '';
    if ($role === 'keke') {
        $userType = 'driver';
        $driverClass = 'keke';
    } elseif ($role === 'driver') {
        $userType = 'driver';
        $driverClass = 'car';
    } elseif ($role === 'rider') {
        $userType = 'rider';
    } else {
        return ['ok' => false, 'error' => 'Please choose how you want to use MohToh.'];
    }

    // A provider-VERIFIED email is authoritative and locked; otherwise the
    // email the user typed is used (and will remain unverified until confirmed).
    $trustedEmail = (($pending['email'] ?? '') !== '' && !empty($pending['email_verified']));
    $email = $trustedEmail ? strtolower(trim($pending['email'])) : strtolower(trim($emailInput));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please enter a valid email address.'];
    }

    $phone = normalize_phone_number($phoneRaw);
    if (!$phone) {
        return ['ok' => false, 'error' => 'Please enter a valid phone number.'];
    }

    // Uniqueness — same rule as registration (case-insensitive email, exact phone).
    $stmt = $pdo->prepare("SELECT id, email, phone FROM users WHERE LOWER(email) = LOWER(?) OR phone = ?");
    $stmt->execute([$email, $phone]);
    $conflict = $stmt->fetch();
    if ($conflict) {
        if (strtolower($conflict['email']) === strtolower($email)) {
            return [
                'ok' => false,
                'email_taken' => true,
                'error' => 'An account with this email already exists. Please log in to connect your ' . $providerLabel . ' account.',
            ];
        }
        return ['ok' => false, 'error' => 'That phone number is already registered.'];
    }

    // Name from the provider, falling back to a safe default if it fails the
    // same letters/spaces policy registration enforces.
    $fullname = trim((string) ($pending['name'] ?? ''));
    if (strlen($fullname) < 3 || !preg_match('/^[a-zA-Z\s]+$/', $fullname)) {
        $fullname = 'MohToh User';
    }

    // Social-only account: an unusable random password satisfies NOT NULL while
    // making password login impossible until the user sets one via Forgot
    // Password. password_is_set = 0 records that state for the unlink safeguard.
    $randomPassword = bin2hex(random_bytes(32));
    $hashed = password_hash($randomPassword, PASSWORD_DEFAULT);
    $publicUserId = generate_unique_user_id($pdo, $userType);
    $emailVerifiedCol = $trustedEmail ? 1 : 0;

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO users (fullname, phone, email, password, user_type, user_id, email_verified, password_is_set)
            VALUES (?, ?, ?, ?, ?, ?, ?, 0)
        ");
        $stmt->execute([$fullname, $phone, $email, $hashed, $userType, $publicUserId, $emailVerifiedCol]);
        $userId = (int) $pdo->lastInsertId();

        if ($userType === 'driver' && $driverClass === 'keke') {
            $pdo->prepare("UPDATE users SET is_keke_driver = 1, private_pickup_enabled = 1 WHERE id = ?")->execute([$userId]);
        }

        $identity = [
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $pending['email'] ?? '',
            'email_verified' => !empty($pending['email_verified']),
            'name' => $pending['name'] ?? '',
        ];
        if (!oauth_link_identity($pdo, $userId, $identity, oauth_client_ip())) {
            throw new RuntimeException('identity link failed');
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        oauth_log('signup', 'failed', 'Social account creation failed', ['provider' => $provider]);
        return ['ok' => false, 'error' => 'We could not finish creating your account. Please try again.'];
    }

    // Kick off verification for each ENABLED channel not already satisfied —
    // same policy as registration. A trusted provider email is already
    // verified (skip its email send); phone always needs an OTP.
    if (is_email_verification_enabled($pdo) && !$emailVerifiedCol) {
        send_email_verification($pdo, $userId);
    }
    if (is_phone_verification_enabled($pdo)) {
        send_phone_otp($pdo, $userId);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    $redirect = oauth_establish_session($pdo, $user);
    oauth_log('signup', 'success', $providerLabel . ' signup', ['user_id' => $userId, 'provider' => $provider]);
    return ['ok' => true, 'redirect' => $redirect];
}

// ---------------------------------------------------------------------------
// Ownership-proof linking completion (called from api/auth.php after a
// successful PASSWORD login)
// ---------------------------------------------------------------------------

function oauth_complete_pending_link($pdo, int $userId, string $userEmail): ?string {
    if (empty($_SESSION['pending_social_link']) || !is_array($_SESSION['pending_social_link'])) {
        return null;
    }
    $p = $_SESSION['pending_social_link'];
    unset($_SESSION['pending_social_link']); // single-use regardless of outcome

    if ((int) ($p['expires'] ?? 0) < time()) {
        return null;
    }
    // Only valid if the account just authenticated owns the SAME verified email
    // the provider asserted — the password login IS the ownership proof.
    if (empty($p['email']) || empty($p['email_verified']) || strtolower($p['email']) !== strtolower($userEmail)) {
        return null;
    }
    if (oauth_identity_exists($pdo, $p['provider'], $p['provider_user_id'])) {
        return null; // already linked somewhere — never hijack
    }
    $identity = [
        'provider' => $p['provider'],
        'provider_user_id' => $p['provider_user_id'],
        'email' => $p['email'],
        'email_verified' => true,
        'name' => $p['name'] ?? '',
    ];
    if (oauth_link_identity($pdo, $userId, $identity, oauth_client_ip())) {
        oauth_log('link', 'success', oauth_provider_label($p['provider']) . ' linked after password login', ['user_id' => $userId, 'provider' => $p['provider']]);
        return oauth_provider_label($p['provider']) . ' connected.';
    }
    return null;
}

// ---------------------------------------------------------------------------
// Connected-accounts management (used by api/social-accounts.php)
// ---------------------------------------------------------------------------

function oauth_list_user_identities($pdo, int $userId): array {
    $stmt = $pdo->prepare("SELECT provider, provider_email, provider_name, created_at FROM oauth_identities WHERE user_id = ? ORDER BY created_at ASC");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

// Safeguard: a user may not remove their ONLY usable way back in. Usable =
// has a real password (password_is_set=1) OR at least one OTHER linked
// provider remains after this unlink.
function oauth_user_can_unlink($pdo, int $userId, string $provider): array {
    $stmt = $pdo->prepare("SELECT password_is_set FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $hasPassword = ((int) $stmt->fetchColumn() === 1);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oauth_identities WHERE user_id = ? AND provider <> ?");
    $stmt->execute([$userId, $provider]);
    $others = (int) $stmt->fetchColumn();

    if ($hasPassword || $others >= 1) {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'This is your only way to sign in. Set a password first (via Forgot Password), then you can disconnect it.'];
}

function oauth_unlink($pdo, int $userId, string $provider): bool {
    $stmt = $pdo->prepare("DELETE FROM oauth_identities WHERE user_id = ? AND provider = ?");
    $stmt->execute([$userId, $provider]);
    return $stmt->rowCount() > 0;
}

// ---------------------------------------------------------------------------
// Flash + redirect + audit helpers
// ---------------------------------------------------------------------------

// A one-shot, server-side flash surfaced by index.php / social-complete.php.
// `open` optionally asks the landing page to open a specific auth sheet.
function oauth_flash(string $type, string $message, ?string $open = null): void {
    $_SESSION['social_flash'] = ['type' => $type, 'message' => $message, 'open' => $open];
}

function oauth_take_flash(): ?array {
    if (empty($_SESSION['social_flash']) || !is_array($_SESSION['social_flash'])) {
        return null;
    }
    $f = $_SESSION['social_flash'];
    unset($_SESSION['social_flash']);
    return $f;
}

// Redirect to an app-root-relative target (or pass through an absolute URL).
// Relative targets are resolved against the resolved base URL so deep callback
// files (auth/oauth/<p>/callback.php) land on the right page.
function oauth_redirect(string $target): void {
    if (!preg_match('#^https?://#i', $target)) {
        $target = rtrim(mt_get_base_url(), '/') . '/' . ltrim($target, '/');
    }
    header('Location: ' . $target);
    exit;
}

// Audit trail via the existing integration_logs channel. NEVER pass secrets,
// authorization codes, access/refresh/id tokens, passwords or session ids in
// $meta — only high-level event metadata.
function oauth_log(string $event, string $status, string $message = '', array $meta = []): void {
    log_integration_event('oauth', $event, $status, $message, $meta ?: null);
}

// ---------------------------------------------------------------------------
// Route orchestration — the start + callback files are thin wrappers over these
// ---------------------------------------------------------------------------

function oauth_run_start($pdo, string $provider): void {
    if (!oauth_is_valid_provider($provider)) {
        oauth_flash('error', 'Unknown sign-in provider.');
        oauth_redirect('index.php');
    }
    // Backend enable/configured gate — a disabled provider never starts a flow.
    if (!oauth_provider_usable($pdo, $provider)) {
        oauth_log('start', 'blocked', oauth_provider_label($provider) . ' sign-in disabled/not configured', ['provider' => $provider]);
        oauth_flash('error', oauth_provider_label($provider) . ' sign-in is currently unavailable.', 'login');
        oauth_redirect('index.php');
    }
    if (!oauth_rate_limit($pdo, 'oauth_start', oauth_client_ip(), 20, 600)) {
        oauth_flash('error', 'Too many sign-in attempts. Please wait a few minutes and try again.', 'login');
        oauth_redirect('index.php');
    }

    $cfg = oauth_provider_config($pdo, $provider);

    // Mode: 'link' requires an active session and binds the state to that user.
    $mode = 'login';
    $userId = null;
    if (($_GET['mode'] ?? '') === 'link') {
        if (!is_logged_in()) {
            oauth_flash('error', 'Please log in first to connect an account.', 'login');
            oauth_redirect('index.php');
        }
        $mode = 'link';
        $userId = (int) $_SESSION['user_id'];
        if (!oauth_rate_limit($pdo, 'oauth_link', 'u' . $userId, 10, 600)) {
            oauth_flash('error', 'Too many attempts. Please wait and try again.');
            oauth_redirect(oauth_user_home($_SESSION['user_type'] ?? 'rider', 'profile'));
        }
    }

    // Optional role prefill for new signups (a HINT only, never a grant).
    $roleHint = strtolower(trim((string) ($_GET['role'] ?? '')));
    if (!in_array($roleHint, ['rider', 'driver', 'keke'], true)) {
        $roleHint = '';
    }

    $codeVerifier = ($provider === 'google') ? oauth_generate_code_verifier() : null;
    $state = oauth_create_state($pdo, $provider, $mode, $userId, $roleHint !== '' ? $roleHint : null, $codeVerifier);

    if ($provider === 'google') {
        $url = oauth_google_authorize_url($cfg, $state, oauth_code_challenge($codeVerifier));
    } else {
        $url = oauth_facebook_authorize_url($cfg, $state);
    }

    oauth_log('start', 'redirect', oauth_provider_label($provider) . ' authorize', ['provider' => $provider, 'mode' => $mode]);
    header('Location: ' . $url); // absolute provider URL
    exit;
}

function oauth_run_callback($pdo, string $provider): void {
    if (!oauth_is_valid_provider($provider)) {
        oauth_flash('error', 'Unknown sign-in provider.');
        oauth_redirect('index.php');
    }
    // Backend enable/configured gate — a disabled provider never authenticates.
    if (!oauth_provider_usable($pdo, $provider)) {
        oauth_log('callback', 'blocked', oauth_provider_label($provider) . ' sign-in disabled/not configured', ['provider' => $provider]);
        oauth_flash('error', oauth_provider_label($provider) . ' sign-in is currently unavailable.', 'login');
        oauth_redirect('index.php');
    }
    if (!oauth_rate_limit($pdo, 'oauth_callback', oauth_client_ip(), 30, 600)) {
        oauth_flash('error', 'Too many sign-in attempts. Please wait a few minutes and try again.', 'login');
        oauth_redirect('index.php');
    }

    // Provider-reported error (user cancelled / denied consent).
    if (($_GET['error'] ?? '') !== '') {
        oauth_log('callback', 'cancelled', 'Provider returned error', ['provider' => $provider, 'error' => substr((string) $_GET['error'], 0, 60)]);
        oauth_flash('info', 'Sign-in was cancelled.', 'login');
        oauth_redirect('index.php');
    }

    $code = (string) ($_GET['code'] ?? '');
    $rawState = (string) ($_GET['state'] ?? '');
    if ($code === '' || $rawState === '') {
        oauth_flash('error', 'Sign-in could not be completed. Please try again.', 'login');
        oauth_redirect('index.php');
    }

    // Validate + consume single-use state.
    $state = oauth_consume_state($pdo, $provider, $rawState);
    if ($state === null) {
        oauth_log('callback', 'failed', 'Invalid/expired/reused state', ['provider' => $provider]);
        oauth_flash('error', 'Your sign-in link expired. Please try again.', 'login');
        oauth_redirect('index.php');
    }

    $cfg = oauth_provider_config($pdo, $provider);

    // Exchange code for a PROVIDER-VALIDATED identity.
    if ($provider === 'google') {
        $identity = oauth_google_exchange_and_identity($pdo, $cfg, $code, (string) $state['code_verifier']);
    } else {
        $identity = oauth_facebook_exchange_and_identity($pdo, $cfg, $code);
    }
    if ($identity === null || $identity['provider_user_id'] === '') {
        oauth_flash('error', 'We could not verify your ' . $cfg['label'] . ' account. Please try again.', 'login');
        oauth_redirect('index.php');
    }

    // LINK MODE — attach to the logged-in initiator (exits).
    if (($state['mode'] ?? 'login') === 'link') {
        oauth_finish_link($pdo, $provider, $identity, $state);
    }

    // LOGIN/SIGNUP MODE.
    // 1) Already-linked identity -> straight login.
    $existing = oauth_find_user_by_identity($pdo, $provider, $identity['provider_user_id']);
    if ($existing) {
        if ($existing['is_suspended']) {
            oauth_log('login', 'blocked', 'Suspended account', ['user_id' => $existing['id'], 'provider' => $provider]);
            oauth_flash('error', 'Your account has been suspended. Please contact support.', 'login');
            oauth_redirect('index.php');
        }
        oauth_touch_identity($pdo, $provider, $identity);
        $redirect = oauth_establish_session($pdo, $existing);
        oauth_log('login', 'success', oauth_provider_label($provider) . ' login', ['user_id' => $existing['id'], 'provider' => $provider]);
        oauth_redirect($redirect);
    }

    // 2) Not linked. A provider-VERIFIED email may PROPOSE a match, but we
    //    never silently link — the user must prove ownership by logging in.
    if ($identity['email'] !== '' && $identity['email_verified']) {
        $match = oauth_find_user_by_email($pdo, $identity['email']);
        if ($match) {
            $_SESSION['pending_social_link'] = [
                'provider' => $identity['provider'],
                'provider_user_id' => $identity['provider_user_id'],
                'email' => $identity['email'],
                'email_verified' => true,
                'name' => $identity['name'],
                'expires' => time() + OAUTH_PENDING_TTL_SECONDS,
            ];
            oauth_log('login', 'link_prompt', 'Existing email matched; ownership proof required', ['provider' => $provider]);
            oauth_flash('info', 'An account with this email already exists. Please log in to connect your ' . $cfg['label'] . ' account.', 'login');
            oauth_redirect('index.php');
        }
    }

    // 3) Brand-new user -> completion step. The provider-validated identity is
    //    stashed SERVER-SIDE so provider_user_id / verified-email cannot be
    //    tampered with on the completion form.
    $_SESSION['pending_social_signup'] = [
        'provider' => $identity['provider'],
        'provider_user_id' => $identity['provider_user_id'],
        'email' => $identity['email'],
        'email_verified' => $identity['email_verified'],
        'name' => $identity['name'],
        'role_hint' => $state['role_hint'] ?? '',
        'expires' => time() + OAUTH_PENDING_TTL_SECONDS,
    ];
    oauth_log('signup', 'pending', 'New social user -> completion step', ['provider' => $provider]);
    oauth_redirect('social-complete.php');
}

// Link-mode completion. The link must belong to the same user the state was
// issued for AND who is still logged in now.
function oauth_finish_link($pdo, string $provider, array $identity, array $state): void {
    $label = oauth_provider_label($provider);
    $stateUserId = (int) ($state['user_id'] ?? 0);

    if (!is_logged_in() || $stateUserId === 0 || (int) $_SESSION['user_id'] !== $stateUserId) {
        oauth_flash('error', 'Please log in and try connecting your account again.', 'login');
        oauth_redirect('index.php');
    }

    $owner = oauth_identity_owner($pdo, $provider, $identity['provider_user_id']);
    $home = oauth_user_home($_SESSION['user_type'] ?? 'rider', 'profile');

    if ($owner !== null && $owner === $stateUserId) {
        oauth_flash('success', 'Your ' . $label . ' account is already connected.');
        oauth_redirect($home);
    }
    if ($owner !== null) {
        oauth_log('link', 'conflict', 'Provider identity already linked to another user', ['provider' => $provider]);
        oauth_flash('error', 'This ' . $label . ' account is already connected to a different MohToh account.');
        oauth_redirect($home);
    }
    if (oauth_link_identity($pdo, $stateUserId, $identity, oauth_client_ip())) {
        oauth_log('link', 'success', $label . ' account linked', ['user_id' => $stateUserId, 'provider' => $provider]);
        oauth_flash('success', 'Your ' . $label . ' account is now connected.');
    } else {
        oauth_flash('error', 'We could not connect your ' . $label . ' account. Please try again.');
    }
    oauth_redirect($home);
}
