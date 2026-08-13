<?php
// api/paystack-webhook.php
// Server-to-server only — Paystack calls this directly, no browser/
// session involved. This is the PRIMARY source of truth for crediting
// a wallet top-up (api/paystack-callback.php is a convenience path for
// instant UI feedback that can't be relied on alone, since a user can
// close the browser before it ever runs).
//
// Every request must carry a valid X-Paystack-Signature header — an
// HMAC-SHA512 of the raw request body, keyed with the Paystack secret
// key, exactly as Paystack's own docs specify. Anything that fails
// this check is rejected before the body is even parsed.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

http_response_code(200); // default; overridden below only for signature failure

$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

$config = paystack_config();
$expectedSignature = hash_hmac('sha512', $rawBody, $config['secret']);

if ($signature === '' || !hash_equals($expectedSignature, $signature)) {
    http_response_code(401);
    exit;
}

$event = json_decode($rawBody, true);
if (!is_array($event) || ($event['event'] ?? '') !== 'charge.success') {
    // Not an event this integration acts on (Paystack sends several
    // event types) — acknowledge so Paystack doesn't retry forever.
    exit;
}

$reference = $event['data']['reference'] ?? '';
if ($reference === '') {
    exit;
}

$stmt = $pdo->prepare("SELECT id, status FROM wallet_topups WHERE public_id = ?");
$stmt->execute([$reference]);
$topup = $stmt->fetch();

if (!$topup) {
    exit; // unknown reference — nothing to do, still 200 so Paystack stops retrying
}

if ($topup['status'] !== 'PAYMENT_PENDING') {
    exit; // already resolved (likely by the callback path) — idempotent no-op
}

// Never credit off the webhook body's own claimed status/amount alone
// — re-verify against Paystack's API with our secret key, the same
// defense-in-depth the callback path uses, so a correctly-signed but
// stale/replayed event still can't force a credit that doesn't match
// Paystack's current record of the transaction.
try {
    $verify = paystack_verify_transaction($reference);
    if (empty($verify['status']) || ($verify['data']['status'] ?? null) !== 'success') {
        $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED', raw_response = ?, verified_at = NOW() WHERE id = ? AND status = 'PAYMENT_PENDING'")
            ->execute([json_encode($verify['data'] ?? $verify), $topup['id']]);
        exit;
    }

    $pdo->beginTransaction();
    try {
        finalize_wallet_topup($pdo, $topup['id'], $verify['data']);
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
} catch (Exception $e) {
    // Transport failure reaching Paystack from our own server — leave
    // PENDING; either a later webhook retry or the browser callback
    // path can still resolve it.
}
