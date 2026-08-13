<?php
// GET /api/v1/wallet/history.php — same query/shape as api/wallet.php's
// get_history action, serving either role from one endpoint (drivers
// additionally get their active commission reservations).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
$userId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);
$stmt = $pdo->prepare("SELECT id, reference, amount, type, category, description, ride_share_id, booking_id, balance_after, status, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute([$userId]);
$history = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?");
$countStmt->execute([$userId]);
$total = (int)$countStmt->fetchColumn();

$isDriver = $user['user_type'] === 'driver';
$activeReservations = [];
if ($isDriver) {
    $stmt = $pdo->prepare("
        SELECT r.public_id, r.ride_share_id, rs.public_id AS ride_public_id, rs.destination, r.reserved_amount, r.used_amount, r.released_amount, r.status, r.created_at
        FROM ride_commission_reservations r
        JOIN ride_shares rs ON rs.id = r.ride_share_id
        WHERE r.driver_id = ? AND r.status = 'active'
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$userId]);
    $activeReservations = $stmt->fetchAll();
}

v1_success('Wallet history fetched', [
    'balance' => (float)$user['wallet_balance'],
    'available_balance' => $isDriver ? get_available_wallet_balance($pdo, $userId) : (float)$user['wallet_balance'],
    'reserved_balance' => $isDriver ? get_reserved_wallet_balance($pdo, $userId) : 0.0,
    'active_reservations' => $activeReservations,
    'payment_preference' => $user['payment_preference'],
    'history' => $history,
], paginate_meta($page, $limit, $total));
