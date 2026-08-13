<?php
// GET /api/v1/driver/wallet.php — same shape as api/wallet.php's
// get_history action for a driver (includes reservation figures riders
// never have).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);
$driverId = (int)$user['id'];

[$page, $limit, $offset] = paginate_params($_GET);
$stmt = $pdo->prepare("SELECT id, reference, amount, type, category, description, ride_share_id, booking_id, balance_after, status, created_at FROM wallet_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT $limit OFFSET $offset");
$stmt->execute([$driverId]);
$history = $stmt->fetchAll();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM wallet_transactions WHERE user_id = ?");
$countStmt->execute([$driverId]);
$total = (int)$countStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT r.public_id, r.ride_share_id, rs.public_id AS ride_public_id, rs.destination, r.reserved_amount, r.used_amount, r.released_amount, r.status, r.created_at
    FROM ride_commission_reservations r
    JOIN ride_shares rs ON rs.id = r.ride_share_id
    WHERE r.driver_id = ? AND r.status = 'active'
    ORDER BY r.created_at DESC
");
$stmt->execute([$driverId]);

v1_success('Wallet fetched', [
    'balance' => (float)$user['wallet_balance'],
    'available_balance' => get_available_wallet_balance($pdo, $driverId),
    'reserved_balance' => get_reserved_wallet_balance($pdo, $driverId),
    'active_reservations' => $stmt->fetchAll(),
    'payment_preference' => $user['payment_preference'],
    'history' => $history,
], paginate_meta($page, $limit, $total));
