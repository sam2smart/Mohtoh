<?php
// api/paystack-callback.php
// Browser lands here after Paystack's hosted checkout, via the
// callback_url passed to /transaction/initialize. This is a
// convenience path for instant UI feedback — it is NOT the source of
// truth (api/paystack-webhook.php is, since it doesn't depend on the
// user's browser staying open). Both paths call the same
// finalize_wallet_topup(), which is safe to call from either or both:
// only the first one to see PAYMENT_PENDING actually credits anything.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php';

if (!is_logged_in()) {
    header('Location: ../index.php');
    exit;
}

// Both roles' wallet UI is now a sheet on their own dashboard.php
// (assets/js/driver.js / assets/js/rider-trip.js both read this ?topup=
// param the same way) — rider/wallet.php is a retired redirect stub.
$dashboard = $_SESSION['user_type'] === 'driver' ? '../driver/dashboard.php' : '../rider/dashboard.php';
$reference = $_GET['reference'] ?? $_GET['trxref'] ?? '';

function redirect_with_status($dashboard, $status, $reference = '') {
    $query = http_build_query(array_filter(['topup' => $status, 'reference' => $reference]));
    header('Location: ' . $dashboard . '?' . $query);
    exit;
}

if ($reference === '') {
    redirect_with_status($dashboard, 'failed');
}

$stmt = $pdo->prepare("SELECT id, status FROM wallet_topups WHERE public_id = ? AND user_id = ?");
$stmt->execute([$reference, $_SESSION['user_id']]);
$topup = $stmt->fetch();

if (!$topup) {
    redirect_with_status($dashboard, 'failed');
}

if ($topup['status'] === 'PAYMENT_CONFIRMED') {
    redirect_with_status($dashboard, 'success', $reference);
}
if ($topup['status'] === 'PAYMENT_FAILED') {
    redirect_with_status($dashboard, 'failed', $reference);
}

// Still PENDING as far as our own DB knows — ask Paystack directly
// rather than trusting the fact that the browser landed here at all.
try {
    $verify = paystack_verify_transaction($reference);
    $paystackStatus = $verify['data']['status'] ?? null;

    if (!empty($verify['status']) && $paystackStatus === 'success') {
        $pdo->beginTransaction();
        try {
            finalize_wallet_topup($pdo, $topup['id'], $verify['data']);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            redirect_with_status($dashboard, 'failed', $reference);
        }
        redirect_with_status($dashboard, 'success', $reference);
    }

    // Paystack says it did not succeed (failed/abandoned/etc).
    $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED', raw_response = ?, verified_at = NOW() WHERE id = ? AND status = 'PAYMENT_PENDING'")
        ->execute([json_encode($verify['data'] ?? $verify), $topup['id']]);
    redirect_with_status($dashboard, 'failed', $reference);
} catch (Exception $e) {
    // Transport failure talking to Paystack — leave the row PENDING so
    // the webhook can still resolve it later; just tell the user we
    // couldn't confirm yet rather than falsely declaring failure.
    redirect_with_status($dashboard, 'pending', $reference);
}
