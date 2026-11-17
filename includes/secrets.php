<?php
// includes/secrets.php
// AES-256-GCM encrypt/decrypt for credentials stored in integration_settings
// (SMTP passwords, API secret keys, etc). Keyed by APP_ENCRYPTION_KEY in
// includes/config.php. No external library needed — openssl_encrypt/decrypt
// are part of PHP's core ext-openssl, always available on this stack.

// Ciphertext is stored as base64(iv . tag . ciphertext) so the whole thing
// is a single opaque string that fits in one TEXT column.

// Derives a stable 32-byte (aes-256) key. If APP_ENCRYPTION_KEY is unset or
// is not valid hex of exactly 32 bytes, we derive one deterministically from
// the app name + a private salt — NOT from the empty/sha1('') fallback that
// would silently encrypt with an unusable key. This guarantees encryption
// always works on a fresh install and never depends on a runtime env that
// may be missing in some deployment.
function mt_secret_key(): string {
    $configured = defined('APP_ENCRYPTION_KEY') ? APP_ENCRYPTION_KEY : '';
    $key = '';
    if ($configured !== '') {
        $bin = @hex2bin($configured);
        if ($bin !== false && strlen($bin) === 32) {
            $key = $bin;
        }
    }
    if ($key === '') {
        // Deterministic fallback: sha256(app-name + app-encryption-salt).
        // SECURITY: this salt and app name are both in this file, which is
        // source-controlled — anyone who can read this repository can
        // derive this exact fallback key. It exists only so a fresh
        // install never hard-fails; it is NOT a substitute for a real
        // APP_ENCRYPTION_KEY wherever actual secrets (SMTP/API credentials,
        // MOHTOH NETWORK withdrawal bank account numbers) are stored. Log
        // once per process so this misconfiguration shows up in
        // error_log.txt instead of silently persisting forever.
        static $warned = false;
        if (!$warned) {
            $warned = true;
            error_log('SECURITY WARNING: APP_ENCRYPTION_KEY is unset or invalid — falling back to a key derivable from public source code (includes/secrets.php). Set a real 32-byte hex APP_ENCRYPTION_KEY in .env on any environment that stores real credentials.');
        }
        $salt = 'mohtoh-merit-enc-v1';
        $appName = defined('APP_NAME') ? APP_NAME : 'mohtoh';
        $key = hash('sha256', $appName . '|' . $salt, true); // always 32 bytes
    }
    return $key;
}

// Stable identity for logging/diagnostics — the first 8 hex chars of a
// sha256 of the key, so operators can tell "same key as before" vs "rotated"
// without ever exposing the key itself.
function mt_secret_key_id(): string {
    return substr(hash('sha256', mt_secret_key()), 0, 8);
}

function encrypt_secret(string $plain): string {
    $key = mt_secret_key();
    $iv = random_bytes(12); // GCM standard nonce size
    $tag = '';
    $ciphertext = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ciphertext);
}

// Returns null on any failure (wrong key, corrupted/foreign data, empty
// input) rather than throwing — callers treat a null decrypt exactly like
// a missing credential, never a fatal error.
function decrypt_secret(?string $encoded): ?string {
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $key = mt_secret_key();
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? null : $plain;
}

// True when a stored secret is present but cannot be decrypted with the
// current key (key rotated / data written by another key / corrupt). The
// admin UI can use this to prompt a re-entry of the credential instead of
// silently treating it as "not configured".
function secret_is_undecryptable(?string $encoded): bool {
    if ($encoded === null || $encoded === '') {
        return false;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 12 + 16) {
        return true; // present but corrupt shape
    }
    return decrypt_secret($encoded) === null;
}

// Admin-display masking — shows only the last 4 characters, never the
// full value. Used everywhere a secret is rendered in the Integration
// Center UI outside of the super-admin-only Reveal endpoint.
function mask_secret(?string $plain): string {
    if ($plain === null || $plain === '') {
        return '';
    }
    $len = strlen($plain);
    if ($len <= 4) {
        return str_repeat('•', $len);
    }
    return str_repeat('•', min($len - 4, 12)) . substr($plain, -4);
}
