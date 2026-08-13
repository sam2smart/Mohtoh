<?php
// includes/integration-settings.php
// Admin-configurable settings layer for the Service Integration Center.
// Every external-service provider file (email-providers.php,
// sms-providers.php, functions.php's paystack_config(), verification.php,
// whatsapp-providers.php, payment-providers.php, push-providers.php,
// map-config.php, feature-flags.php) reads its configuration through
// get_integration_setting() instead of a bare includes/config.php
// constant. If a key has never been saved via the admin UI, the given
// constant fallback is returned — so until an admin actually touches the
// Integration Center, every provider behaves exactly as it did before
// this layer existed. This is the mechanism that guarantees the
// admin-configurability work never breaks anything currently working.
require_once __DIR__ . '/secrets.php';

// Sentinel returned by get_integration_setting() for a stored secret that is
// present in the DB but cannot be decrypted with the current key (key was
// rotated after the value was saved, or the row is foreign/corrupt). Callers
// that only check `!== ''` or `!== null` keep their old behavior; callers that
// want to detect the "present but unreadable" state compare against this.
define('INTEGRATION_SECRET_UNDECRYPTABLE', "\x00__UNDECRYPTABLE__\x00");

// Loaded once per request on first access and reused — every provider
// file calls this multiple times per request (host/port/username/...),
// and a single query is cheap insurance against N+1 settings lookups.
// Kept in $GLOBALS (not a function-static) so set_integration_setting()
// can invalidate it within the same request — e.g. a save-then-test-
// connection flow needs to see the value it just wrote.
function _integration_settings_cache($pdo): array {
    if (!isset($GLOBALS['_integration_settings_cache'])) {
        $cache = [];
        $stmt = $pdo->query("SELECT setting_key, setting_value, is_secret FROM integration_settings");
        foreach ($stmt->fetchAll() as $row) {
            $cache[$row['setting_key']] = $row;
        }
        $GLOBALS['_integration_settings_cache'] = $cache;
    }
    return $GLOBALS['_integration_settings_cache'];
}

function get_integration_setting($pdo, string $key, $constantFallback = null) {
    $cache = _integration_settings_cache($pdo);
    if (!isset($cache[$key])) {
        return $constantFallback;
    }
    $row = $cache[$key];
    if ($row['setting_value'] === null) {
        return $constantFallback;
    }
    if ((int)$row['is_secret'] === 1) {
        // If the stored ciphertext cannot be decrypted with the current key,
        // return a sentinel (not the silent empty fallback) so the caller can
        // detect "present but unreadable" and prompt a re-entry, rather than
        // believing the credential is simply not configured.
        if (secret_is_undecryptable($row['setting_value'])) {
            return INTEGRATION_SECRET_UNDECRYPTABLE;
        }
        $decrypted = decrypt_secret($row['setting_value']);
        return $decrypted === null ? $constantFallback : $decrypted;
    }
    return $row['setting_value'];
}

// Bool-typed convenience for feature toggles / enable-disable flags.
// Accepts '1'/'0', 'true'/'false', 1/0 stored as text.
function get_integration_setting_bool($pdo, string $key, bool $constantFallback = false): bool {
    $value = get_integration_setting($pdo, $key, $constantFallback ? '1' : '0');
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
}

function set_integration_setting($pdo, int $adminId, string $key, ?string $value, bool $isSecret = false): void {
    $stmt = $pdo->prepare("SELECT setting_value, is_secret FROM integration_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $existing = $stmt->fetch();

    $oldValueForLog = $existing ? (((int)$existing['is_secret'] === 1) ? '***' : $existing['setting_value']) : null;
    $newValueForLog = $isSecret ? ($value === null || $value === '' ? null : '***') : $value;

    $storedValue = ($isSecret && $value !== null && $value !== '') ? encrypt_secret($value) : $value;

    $stmt = $pdo->prepare("
        INSERT INTO integration_settings (setting_key, setting_value, is_secret, updated_by_admin_id, updated_at)
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_secret = VALUES(is_secret),
            updated_by_admin_id = VALUES(updated_by_admin_id), updated_at = NOW()
    ");
    $stmt->execute([$key, $storedValue, $isSecret ? 1 : 0, $adminId]);
    unset($GLOBALS['_integration_settings_cache']); // next read in this request sees the new value

    if ($oldValueForLog !== $newValueForLog) {
        log_integration_change($pdo, $adminId, $key, $oldValueForLog, $newValueForLog);
    }
}

function log_integration_change($pdo, int $adminId, string $key, ?string $oldValue, ?string $newValue): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO integration_change_logs (admin_id, setting_key, old_value, new_value, ip_address, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$adminId, $key, $oldValue, $newValue, $ip]);
}

// Writes a row to the generic integration_logs table (used by channels
// without an existing log table — payments, webhooks, push, WhatsApp,
// maps, API errors). OTP/email verification keeps using the existing
// verification_events table, not this one.
function log_integration_event(string $channel, string $event, string $status, string $message = '', ?array $meta = null): void {
    global $pdo;
    if (!isset($pdo)) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO integration_logs (channel, event, status, message, meta, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$channel, $event, $status, $message, $meta ? json_encode($meta) : null]);
}
