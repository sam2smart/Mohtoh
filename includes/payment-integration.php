<?php
// includes/payment-integration.php
// Central Paystack payment-provider service layer for the ADMIN side of the
// Service Integration Center (the "Payment API Integration" card). It is the
// single read/write path the admin UI uses to view and change the Paystack
// credentials + on/off status.
//
// Source of truth (unchanged): the `integrations` table row provider='paystack'
// — its `status` column (enabled/disabled) and its `configuration` JSON
// (public_key, secret_key, webhook_secret, sandbox_mode, currency, callback_url,
// webhook_url). This mirrors includes/google-maps.php's use of the same table.
// There is NO second payment-config table and no config.php credential fallback.
//
// SECURITY: the secret_key and webhook_secret are stored ENCRYPTED (AES-256-GCM
// via includes/secrets.php), exactly like the email/SMS provider secrets. This
// layer encrypts them on write and NEVER returns a decrypted secret to a caller
// that echoes to the browser — the admin GET masks them (see mask_secret()).
// The runtime payment flow (functions.php paystack_config()) is the only place
// that decrypts the secret, server-side, to sign Paystack API calls.

// Provider row in the `integrations` table (same registry google_maps uses).
const MT_PAYMENT_PROVIDER = 'paystack';

// Fields inside the configuration JSON that hold secrets and must be stored
// encrypted. Everything else (public_key, sandbox_mode, currency, URLs) is
// non-secret and stored as-is, matching the existing row.
const MT_PAYMENT_SECRET_FIELDS = ['secret_key', 'webhook_secret'];

/** Read the paystack row (status + decoded configuration) from the
 *  `integrations` table — the ONE source of truth. Returns
 *  ['status' => string, 'configuration' => array] or null if the row is
 *  missing. Cached per-request (flush with mt_payment_config_flush()). */
function getPaymentIntegration($pdo): ?array {
    if (array_key_exists('_mt_payment_integration_cache', $GLOBALS)) {
        return $GLOBALS['_mt_payment_integration_cache'];
    }
    $result = null;
    if (isset($pdo)) {
        try {
            $stmt = $pdo->prepare("SELECT status, configuration FROM integrations WHERE provider = ? LIMIT 1");
            $stmt->execute([MT_PAYMENT_PROVIDER]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $config = [];
                if (is_string($row['configuration']) && $row['configuration'] !== '') {
                    $decoded = json_decode($row['configuration'], true);
                    if (is_array($decoded)) {
                        $config = $decoded;
                    }
                }
                $result = ['status' => (string) $row['status'], 'configuration' => $config];
            }
        } catch (PDOException $e) {
            $result = null; // table/row unavailable — treat as "not configured"
        }
    }
    $GLOBALS['_mt_payment_integration_cache'] = $result;
    return $result;
}

/** Drop the per-request cache so a read after a save in the same request
 *  sees the new value. */
function mt_payment_config_flush(): void {
    unset($GLOBALS['_mt_payment_integration_cache']);
}

/** Decoded Paystack configuration JSON (or [] when the row is missing). */
function getPaystackConfigArray($pdo): array {
    $row = getPaymentIntegration($pdo);
    return $row['configuration'] ?? [];
}

/** Whether the admin has the Paystack integration switched ON. The runtime
 *  payment-initiation path enforces this too (functions.php
 *  paystack_is_enabled()); this helper is the admin-side read. */
function paystackPaymentsEnabled($pdo): bool {
    $row = getPaymentIntegration($pdo);
    return $row !== null && $row['status'] === 'enabled';
}

/** Whether Paystack has both keys configured. */
function paystackConfigured($pdo): bool {
    $config = getPaystackConfigArray($pdo);
    return !empty($config['public_key']) && !empty($config['secret_key']);
}

/**
 * Merge-and-save the Paystack `configuration` JSON and (optionally) the row's
 * `status`, on the existing integrations row — the single write path, mirroring
 * setGoogleMapsConfig(). Partial updates merge into the stored document so
 * unspecified fields (currency, callback_url, webhook_url, an unchanged
 * secret) are preserved.
 *
 * Any secret field present in $incoming is expected to be PLAINTEXT and is
 * encrypted here before storage — so a secret can never be written in the
 * clear through this path. Callers must simply OMIT a secret field to leave
 * the stored (encrypted) value untouched (i.e. "blank = keep").
 *
 * $status, when non-null, sets the row's enabled/disabled state. Returns true
 * on a successful write, false if the paystack row does not exist (this layer
 * never creates the row — it only controls the existing integration).
 */
function setPaystackConfig($pdo, array $incoming, ?string $status = null): bool {
    if (!isset($pdo)) {
        return false;
    }
    $existingRow = getPaymentIntegration($pdo);
    if ($existingRow === null) {
        return false; // no paystack row to control — do not fabricate one
    }
    require_once __DIR__ . '/secrets.php';
    foreach (MT_PAYMENT_SECRET_FIELDS as $sf) {
        if (array_key_exists($sf, $incoming) && is_string($incoming[$sf]) && $incoming[$sf] !== '') {
            $incoming[$sf] = encrypt_secret($incoming[$sf]);
        }
    }
    $merged = array_merge($existingRow['configuration'], $incoming);
    $json = json_encode($merged);

    if ($status !== null && in_array($status, ['enabled', 'disabled'], true)) {
        $stmt = $pdo->prepare("UPDATE integrations SET configuration = ?, status = ?, updated_at = NOW() WHERE provider = ?");
        $stmt->execute([$json, $status, MT_PAYMENT_PROVIDER]);
    } else {
        $stmt = $pdo->prepare("UPDATE integrations SET configuration = ?, updated_at = NOW() WHERE provider = ?");
        $stmt->execute([$json, MT_PAYMENT_PROVIDER]);
    }
    mt_payment_config_flush();
    return true;
}
