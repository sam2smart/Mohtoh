<?php
// GET /api/v1/rider/wallet.php — same shape as api/wallet.php's
// get_history action, riders only (drivers use the equivalent
// api/v1/driver/wallet.php since they have reservation figures riders
// never do).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['rider']);
$userId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);
$stmt = $pdo->prepare("SELECT id, reference, amount, type, category, description, ride_share_id, booking_id, balance_after, status, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute([$userId]);
$history = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?");
$countStmt->execute([$userId]);
$total = (int)$countStmt->fetchColumn();

v1_success('Wallet fetched', [
    'balance' => (float)$user['wallet_balance'],
    'payment_preference' => $user['payment_preference'],
    'history' => $history,
], paginate_meta($page, $limit, $total));
