<?php
// includes/current_user.php
// Lightweight, read-only lookup of the logged-in user's own display data
// (profile image, wallet balance, rating, online state) for use in
// shared partials and screen headers/status cards. Deliberately NOT
// going through api/profile.php or api/wallet.php, both of which the
// pre-redesign audit found broken — this queries the same `users` row
// directly with the $pdo connection the page already has open, so it
// carries no new backend risk and touches no API contract.
//
// Requires $pdo (includes/db.php) and an active session to already be
// loaded by the including page. Produces $currentUser with sensible
// fallbacks if anything is missing.

$currentUser = [
    'fullname' => $_SESSION['fullname'] ?? 'User',
    'user_type' => $_SESSION['user_type'] ?? 'rider',
    'user_id' => $_SESSION['public_user_id'] ?? null,
    'profile_image' => null,
    'wallet_balance' => 0.0,
    'rating' => null,
    'is_online' => false,
    // Keke / Private Pickup capability. Read from the DB on every page load
    // rather than cached in the session, so an admin approving (or revoking)
    // a keke vehicle takes effect on the driver's next page view instead of
    // requiring them to log out and back in.
    'is_keke_driver' => false,
    'private_pickup_enabled' => false,
    'vehicle_type' => null,
    'vehicle_verification_status' => null,
    // Driver CAPABILITY, read from users.user_type — deliberately distinct
    // from 'user_type' above, which is the session's CURRENT operating mode
    // (see api/switch-mode.php). A driver who has switched to rider mode has
    // user_type 'rider' and has_driver_capability true, which is what lets
    // the sidebar offer "Switch to Driver" instead of "Become a Driver".
    'has_driver_capability' => false,
    // Admin CAPABILITY (additive to user_type, like is_keke_driver): whether
    // this account can wear the admin hat, and whether that hat is active.
    // Drives the sidebar's "Switch to Admin" entry for multi-role accounts.
    'is_admin' => false,
    'admin_status' => 'active',
];

if (isset($pdo, $_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare(
            'SELECT u.user_id, u.profile_image, u.wallet_balance, u.rating, u.is_online,
                    u.user_type AS account_user_type,
                    u.is_keke_driver, u.private_pickup_enabled,
                    v.vehicle_type, v.verification_status AS vehicle_verification_status
             FROM users u
             LEFT JOIN vehicles v ON v.driver_id = u.id
             WHERE u.id = ?'
        );
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $currentUser['user_id'] = $row['user_id'];
            if (!empty($row['profile_image']) && $row['profile_image'] !== 'default.png') {
                $currentUser['profile_image'] = $row['profile_image'];
            }
            $currentUser['wallet_balance'] = (float)$row['wallet_balance'];
            $currentUser['rating'] = $row['rating'] !== null ? round((float)$row['rating'], 2) : null;
            $currentUser['is_online'] = (bool)$row['is_online'];
            $currentUser['is_keke_driver'] = (bool)$row['is_keke_driver'];
            $currentUser['private_pickup_enabled'] = (bool)$row['private_pickup_enabled'];
            $currentUser['vehicle_type'] = $row['vehicle_type'];
            $currentUser['vehicle_verification_status'] = $row['vehicle_verification_status'];
            $currentUser['has_driver_capability'] = ($row['account_user_type'] === 'driver');
        }
    } catch (Exception $e) {
        // Fall back to the defaults above; never block page render on this.
    }

    // Admin capability, isolated in its own try/catch so a DB where the admin
    // RBAC columns don't exist yet (migrate_admin_rbac.php not run) can never
    // break the profile lookup above — the account simply reads as non-admin.
    try {
        $adminStmt = $pdo->prepare('SELECT is_admin, admin_status FROM users WHERE id = ?');
        $adminStmt->execute([$_SESSION['user_id']]);
        if ($adminRow = $adminStmt->fetch()) {
            $currentUser['is_admin'] = (bool)$adminRow['is_admin'];
            $currentUser['admin_status'] = $adminRow['admin_status'] ?: 'active';
        }
    } catch (Exception $e) {
        // No admin RBAC columns yet — treat as non-admin.
    }
}
