<?php
// api/wallet.php
// Real JSON wallet endpoint — replaces two previously broken versions:
// database/wallet.php sat in an unreachable folder, and this file used
// to be a legacy rider-only HTML page whose JS called itself instead
// of a real API. Available to any logged-in user (rider or driver);
// every wallet has the same shape.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = $input['action'] ?? $_GET['action'] ?? 'get_history';

if ($action === 'get_history') {
    [$page, $limit, $offset] = paginate_params(); // GET ?page=&?limit= (Part 3/4)
    $stmt = $pdo->prepare("SELECT wallet_balance, payment_preference, user_type FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT id, reference, amount, type, category, description, ride_share_id, booking_id, balance_after, status, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$user_id]);
    $history = $stmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?");
    $countStmt->execute([$user_id]);
    $walletTxnTotal = (int)$countStmt->fetchColumn();

    // Commission Reservation System: riders never have reservations —
    // these helpers return 0/empty for them, same response shape either
    // way so the frontend never has to branch on user_type.
    $availableBalance = $user['user_type'] === 'driver' ? get_available_wallet_balance($pdo, $user_id) : (float)$user['wallet_balance'];
    $reservedBalance = $user['user_type'] === 'driver' ? get_reserved_wallet_balance($pdo, $user_id) : 0.0;

    $activeReservations = [];
    if ($user['user_type'] === 'driver') {
        $stmt = $pdo->prepare("
            SELECT r.public_id, r.ride_share_id, rs.public_id AS ride_public_id, rs.destination, r.reserved_amount, r.used_amount, r.released_amount, r.status, r.created_at
            FROM ride_commission_reservations r
            JOIN ride_shares rs ON rs.id = r.ride_share_id
            WHERE r.driver_id = ? AND r.status = 'active'
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $activeReservations = $stmt->fetchAll();
    }

    json_response('success', 'Wallet history fetched', [
        'balance' => (float)$user['wallet_balance'],
        'available_balance' => $availableBalance,
        'reserved_balance' => $reservedBalance,
        'active_reservations' => $activeReservations,
        'payment_preference' => $user['payment_preference'],
        'history' => $history
    ], paginate_meta($page, $limit, $walletTxnTotal));
}

// Real Paystack top-up. Creates a PAYMENT_PENDING wallet_topups row —
// the wallet itself is never touched here. Crediting only ever happens
// in finalize_wallet_topup() (includes/functions.php), called from
// api/paystack-callback.php (browser redirect) or
// api/paystack-webhook.php (server-to-server) after Paystack itself
// confirms the charge. The frontend never creates its own reference
// and can never trigger a credit directly.
if ($action === 'initialize_topup') {
    $amount = (float)($input['amount'] ?? 0);

    if ($amount < 100) {
        json_response('error', 'Minimum top-up amount is ₦100.');
    }
    if ($amount > 1000000) {
        json_response('error', 'Maximum top-up amount is ₦1,000,000 per transaction.');
    }

    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $email = $stmt->fetchColumn();

    $publicId = generate_unique_public_id($pdo, 'wallet_topups', 'public_id', 'TOPUP');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO wallet_topups (public_id, user_id, amount, gateway, status) VALUES (?, ?, ?, 'paystack', 'PAYMENT_PENDING')");
        $stmt->execute([$publicId, $user_id, $amount]);
        $topupId = $pdo->lastInsertId();
        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_response('error', 'Could not start top-up. Please try again.');
    }

    try {
        $callbackUrl = APP_BASE_URL . '/api/paystack-callback.php';
        $result = paystack_initialize_transaction($email, (int)round($amount * 100), $publicId, $callbackUrl);

        if (empty($result['status']) || empty($result['data']['authorization_url'])) {
            $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED', raw_response = ? WHERE id = ?")
                ->execute([json_encode($result), $topupId]);
            json_response('error', $result['message'] ?? 'Could not start payment with Paystack.');
        }

        json_response('success', 'Redirecting to Paystack…', [
            'authorization_url' => $result['data']['authorization_url'],
            'reference' => $publicId
        ]);
    } catch (Exception $e) {
        $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED' WHERE id = ?")->execute([$topupId]);
        json_response('error', 'Could not reach Paystack right now. Please try again.');
    }
}

// Lets the dashboard poll a specific top-up's status after returning
// from the Paystack redirect, without re-fetching the whole history.
if ($action === 'topup_status') {
    $reference = $input['reference'] ?? $_GET['reference'] ?? '';
    if ($reference === '') {
        json_response('error', 'Reference is required.');
    }
    $stmt = $pdo->prepare("SELECT public_id, amount, status, created_at, verified_at FROM wallet_topups WHERE public_id = ? AND user_id = ?");
    $stmt->execute([$reference, $user_id]);
    $topup = $stmt->fetch();
    if (!$topup) {
        json_response('error', 'Top-up not found.');
    }
    json_response('success', 'Top-up status fetched', $topup);
}

if ($action === 'set_payment_preference' && $_SESSION['user_type'] === 'driver') {
    $preference = $input['payment_preference'] ?? '';
    if (!in_array($preference, ['wallet', 'direct', 'both'], true)) {
        json_response('error', 'Payment preference must be wallet, direct, or both.');
    }

    $stmt = $pdo->prepare("UPDATE users SET payment_preference = ? WHERE id = ?");
    $stmt->execute([$preference, $user_id]);
    json_response('success', 'Payment preference updated.', ['payment_preference' => $preference]);
}

json_response('error', 'Invalid wallet action.');
