<?php
// GET /api/v1/wallet/balance.php — works for either role, same as
// api/wallet.php already does; drivers additionally see their
// reservation figures (get_available_wallet_balance()/get_reserved_wallet_balance()).
require_once __DIR__ . '/../_bootstrap.php';

$user = v1_require_auth($pdo);
$userId = (int)$user['id'];

$isDriver = $user['user_type'] === 'driver';
$availableBalance = $isDriver ? get_available_wallet_balance($pdo, $userId) : (float)$user['wallet_balance'];
$reservedBalance = $isDriver ? get_reserved_wallet_balance($pdo, $userId) : 0.0;

v1_success('Wallet balance fetched', [
    'balance' => (float)$user['wallet_balance'],
    'available_balance' => $availableBalance,
    'reserved_balance' => $reservedBalance,
    'payment_preference' => $user['payment_preference'],
]);
