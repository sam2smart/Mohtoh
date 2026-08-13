<?php
// POST /api/v1/wallet/topup.php — same orchestration as api/wallet.php's
// initialize_topup action. The wallet itself is never touched here or
// anywhere in this file — crediting only ever happens in
// finalize_wallet_topup() (includes/functions.php), called from
// api/paystack-callback.php/api/paystack-webhook.php once Paystack
// itself confirms the charge. The mobile app opens the returned
// authorization_url in an in-app browser/webview, then polls this same
// reference via GET .../wallet/history.php or its own topup-status
// check — same pattern the web dashboard already uses.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
$userId = (int)$user['id'];

$input = v1_input();
$amount = (float)($input['amount'] ?? 0);
if ($amount < 100) {
    v1_error('Minimum top-up amount is ₦100.', 'validation_error');
}
if ($amount > 1000000) {
    v1_error('Maximum top-up amount is ₦1,000,000 per transaction.', 'validation_error');
}

$publicId = generate_unique_public_id($pdo, 'wallet_topups', 'public_id', 'TOPUP');

$pdo->beginTransaction();
try {
    $pdo->prepare("INSERT INTO wallet_topups (public_id, user_id, amount, gateway, status) VALUES (?, ?, ?, 'paystack', 'PAYMENT_PENDING')")
        ->execute([$publicId, $userId, $amount]);
    $topupId = $pdo->lastInsertId();
    $pdo->commit();
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    v1_error('Could not start top-up. Please try again.', 'topup_init_failed');
}

try {
    $callbackUrl = APP_BASE_URL . '/api/paystack-callback.php';
    $result = paystack_initialize_transaction($user['email'], (int)round($amount * 100), $publicId, $callbackUrl);

    if (empty($result['status']) || empty($result['data']['authorization_url'])) {
        $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED', raw_response = ? WHERE id = ?")->execute([json_encode($result), $topupId]);
        v1_error($result['message'] ?? 'Could not start payment with Paystack.', 'paystack_init_failed');
    }

    v1_success('Redirecting to Paystack…', [
        'authorization_url' => $result['data']['authorization_url'],
        'reference' => $publicId,
    ]);
} catch (Exception $e) {
    $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED' WHERE id = ?")->execute([$topupId]);
    v1_error('Could not reach Paystack right now. Please try again.', 'paystack_unreachable');
}
