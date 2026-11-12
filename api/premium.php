<?php
// api/premium.php
// Part 2 — Driver Premium Features. Feature access is gated on TWO
// things working together, not a new standalone flag: the existing
// users.driver_tier ('standard'/'premium', already used by
// api/admin-api.php's set_driver_tier action) for the driver's overall
// "Current Plan" badge, and driver_premium_requests for real per-
// feature locked/unlocked state (a driver can hold premium tier from
// one approved feature while others stay locked until requested).
// Every approve/reject/disable/delete here writes to admin_audit_logs
// via includes/audit.php's log_admin_action() — Part 6/Part 5.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/audit.php';
require_once '../includes/admin_permissions.php'; // admin RBAC gates (require_admin_permission / require_admin_access)

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// A driver's request is "live" (unlocking the feature right now) only
// while approved AND not past its own expires_at.
const PREMIUM_ACTIVE_CLAUSE = "status = 'approved' AND (expires_at IS NULL OR expires_at > NOW())";

// Wallet Billing: fired after any premium-feature debit. Reuses the
// same system_settings + get_pricing_setting() store the Pricing
// Center already writes admin-configurable numbers into, rather than a
// new settings table — key is generic on purpose so any future paid
// module's post-debit check can share it.
function notify_if_wallet_low($pdo, $driverId) {
    $threshold = (float) get_pricing_setting($pdo, 'low_wallet_balance_threshold', 500);
    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$driverId]);
    $balance = (float)$stmt->fetchColumn();
    if ($balance < $threshold) {
        create_notification($pdo, $driverId, [
            'title' => 'Wallet balance is low',
            'message' => "Your wallet balance is ₦" . number_format($balance, 2) . ", below the ₦" . number_format($threshold, 2) . " recommended minimum. Top up to avoid missing out on premium features or renewals.",
            'notification_type' => 'wallet', 'priority' => 'high',
            'action' => 'open_wallet',
        ]);
    }
}

function refresh_driver_tier($pdo, $driverId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM driver_premium_requests WHERE driver_id = ? AND " . PREMIUM_ACTIVE_CLAUSE);
    $stmt->execute([$driverId]);
    $hasActivePremium = (int)$stmt->fetchColumn() > 0;
    $pdo->prepare("UPDATE users SET driver_tier = ? WHERE id = ?")->execute([$hasActivePremium ? 'premium' : 'standard', $driverId]);
    return $hasActivePremium;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    // Driver's Premium Center: the catalog plus, for each feature,
    // whether THIS driver currently has it unlocked or has a pending
    // request already in flight.
    if ($action === 'catalog') {
        if ($_SESSION['user_type'] !== 'driver') {
            json_response('error', 'Unauthorized');
        }
        $driverId = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT f.id, f.public_id, f.feature_name, f.description, f.price, f.duration_days,
                   r.status AS my_status, r.expires_at AS my_expires_at, r.public_id AS my_request_public_id
            FROM driver_premium_features f
            LEFT JOIN driver_premium_requests r ON r.feature_id = f.id AND r.driver_id = ? AND r.id = (
                SELECT id FROM driver_premium_requests WHERE feature_id = f.id AND driver_id = ? ORDER BY created_at DESC LIMIT 1
            )
            WHERE f.status = 'active'
            ORDER BY f.feature_name ASC
        ");
        $stmt->execute([$driverId, $driverId]);
        $features = $stmt->fetchAll();
        foreach ($features as &$f) {
            $f['unlocked'] = $f['my_status'] === 'approved' && ($f['my_expires_at'] === null || strtotime($f['my_expires_at']) > time());
        }
        unset($f);

        $stmt = $pdo->prepare("SELECT driver_tier, wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$driverId]);
        $driver = $stmt->fetch();
        json_response('success', 'Premium catalog fetched', [
            'current_plan' => $driver['driver_tier'],
            'wallet_balance' => (float)$driver['wallet_balance'],
            'features' => $features,
        ]);
    }

    if ($action === 'my_requests') {
        if ($_SESSION['user_type'] !== 'driver') {
            json_response('error', 'Unauthorized');
        }
        [$page, $limit, $offset] = paginate_params();
        $stmt = $pdo->prepare("SELECT r.public_id, r.status, r.reason, r.created_at, r.reviewed_at, r.expires_at, f.feature_name, f.price FROM driver_premium_requests r JOIN driver_premium_features f ON f.id = r.feature_id WHERE r.driver_id = ? ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute([$_SESSION['user_id']]);
        $rows = $stmt->fetchAll();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM driver_premium_requests WHERE driver_id = ?");
        $countStmt->execute([$_SESSION['user_id']]);
        json_response('success', 'Requests fetched', ['requests' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    // Admin: catalog management list (includes inactive features).
    if ($action === 'features') {
        // Admin catalog view -> premium.view (read-only, grantable).
        require_admin_permission($pdo, 'premium.view');
        $rows = $pdo->query("SELECT * FROM driver_premium_features ORDER BY feature_name ASC")->fetchAll();
        json_response('success', 'Features fetched', ['features' => $rows]);
    }

    // Admin: request queue with filters/pagination (Part 3/5).
    if ($action === 'admin_list') {
        // Admin request-queue view -> premium.view (read-only, grantable).
        require_admin_permission($pdo, 'premium.view');
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['feature_id'])) {
            $where[] = 'r.feature_id = ?';
            $params[] = (int)$_GET['feature_id'];
        }
        $sql = "SELECT r.public_id, r.status, r.reason, r.created_at, r.reviewed_at, r.expires_at,
                       u.fullname AS driver_name, u.email AS driver_email, f.feature_name, f.price
                FROM driver_premium_requests r
                JOIN users u ON u.id = r.driver_id
                JOIN driver_premium_features f ON f.id = r.feature_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY r.created_at DESC LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM driver_premium_requests r WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Premium requests fetched', ['requests' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $input['action'] ?? '';

    // Driver requests a feature — creates a PENDING request for admin
    // review. Never grants access itself (Part 5: approval is manual).
    // Wallet Billing: the actual charge happens at approval time (the
    // moment the feature is truly delivered — see the 'approve' action
    // below), but a driver who plainly cannot afford the feature right
    // now is told so immediately, with no gateway ever involved.
    if ($action === 'request') {
        if ($_SESSION['user_type'] !== 'driver') {
            json_response('error', 'Unauthorized');
        }
        $featureId = (int)($input['feature_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, status, price, feature_name FROM driver_premium_features WHERE id = ? AND status = 'active'");
        $stmt->execute([$featureId]);
        $feature = $stmt->fetch();
        if (!$feature) {
            json_response('error', 'This feature is not available.');
        }

        $stmt = $pdo->prepare("SELECT id FROM driver_premium_requests WHERE driver_id = ? AND feature_id = ? AND status IN ('pending', 'approved')");
        $stmt->execute([$_SESSION['user_id'], $featureId]);
        if ($stmt->fetch()) {
            json_response('error', 'You already have a pending or active request for this feature.');
        }

        $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $walletBalance = (float)$stmt->fetchColumn();
        if ($walletBalance < (float)$feature['price']) {
            json_response('error', "Insufficient wallet balance. This feature costs ₦" . number_format($feature['price'], 2) . " — your wallet has ₦" . number_format($walletBalance, 2) . ". Top up your wallet to request it.");
        }

        $publicId = generate_unique_public_id($pdo, 'driver_premium_requests', 'public_id', 'PRQ');
        $stmt = $pdo->prepare("INSERT INTO driver_premium_requests (public_id, driver_id, feature_id, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$publicId, $_SESSION['user_id'], $featureId]);

        create_notification($pdo, $_SESSION['user_id'], [
            'title' => 'Premium request submitted', 'message' => 'Your premium feature request has been submitted for review.',
            'notification_type' => 'premium', 'related_table' => 'driver_premium_requests', 'related_public_id' => $publicId,
        ]);
        json_response('success', 'Premium request submitted.', ['request_public_id' => $publicId]);
    }

    if (in_array($action, ['approve', 'reject', 'disable', 'delete_request'], true)) {
        // Money/entitlement actions are Super-Admin-only. require_admin_access
        // first enforces admin mode + active + not suspended (rejecting a
        // suspended or non-admin-mode super), then require_super_admin confirms
        // the Super hat. No Subadmin permission can ever reach these.
        require_admin_access($pdo);
        require_super_admin($pdo);
        $requestPublicId = $input['request_public_id'] ?? '';
        if ($requestPublicId === '') {
            json_response('error', 'request_public_id is required');
        }
        $stmt = $pdo->prepare("SELECT r.*, f.duration_days, f.feature_name, f.price FROM driver_premium_requests r JOIN driver_premium_features f ON f.id = r.feature_id WHERE r.public_id = ?");
        $stmt->execute([$requestPublicId]);
        $request = $stmt->fetch();
        if (!$request) {
            json_response('error', 'Request not found.');
        }
        $oldStatus = $request['status'];
        $adminId = (int)$_SESSION['user_id'];
        $price = (float)$request['price'];

        // Wallet Billing: approval is the moment the feature is actually
        // delivered, so it's the moment the wallet is actually charged —
        // never at request time (the driver's balance may have moved
        // since then) and never via Paystack/any gateway. Debit and
        // status flip happen in one transaction: if the debit fails,
        // the request is never approved.
        if ($action === 'approve') {
            if ($oldStatus !== 'pending') {
                json_response('error', 'Only pending requests can be approved.');
            }

            $pdo->beginTransaction();
            try {
                $debitResult = debit_wallet(
                    $pdo, $request['driver_id'], $price, 'PREMIUM_FEATURE',
                    "Premium feature: {$request['feature_name']}", null, null,
                    ['request_public_id' => $requestPublicId, 'feature_id' => $request['feature_id']]
                );
                if ($debitResult === false) {
                    $pdo->rollBack();
                    json_response('error', "Cannot approve — the driver's wallet balance is insufficient to cover ₦" . number_format($price, 2) . ".");
                }

                $expiresAt = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL {$request['duration_days']} DAY)")->fetchColumn();
                $pdo->prepare("UPDATE driver_premium_requests SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), expires_at = ? WHERE id = ?")
                    ->execute([$adminId, $expiresAt, $request['id']]);
                $pdo->commit();
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                json_response('error', 'Could not approve this request: ' . $e->getMessage());
            }

            refresh_driver_tier($pdo, $request['driver_id']);
            log_admin_action($pdo, $adminId, 'approve', 'premium_request', $request['id'], $oldStatus, 'approved');
            create_notification($pdo, $request['driver_id'], [
                'title' => 'Premium feature approved',
                'message' => "Your request for \"{$request['feature_name']}\" was approved — ₦" . number_format($price, 2) . " was deducted from your wallet. It's active now.",
                'notification_type' => 'premium', 'priority' => 'high', 'force' => true,
                'related_table' => 'driver_premium_requests', 'related_public_id' => $requestPublicId,
            ]);
            notify_if_wallet_low($pdo, $request['driver_id']);
            json_response('success', 'Premium request approved and wallet charged.');
        }

        if ($action === 'reject') {
            if ($oldStatus !== 'pending') {
                json_response('error', 'Only pending requests can be rejected — use Disable to revoke an already-active feature.');
            }
            $reason = trim((string)($input['reason'] ?? ''));
            if ($reason === '') {
                json_response('error', 'A reason is required to reject a request.');
            }
            $pdo->prepare("UPDATE driver_premium_requests SET status = 'rejected', reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$reason, $adminId, $request['id']]);
            log_admin_action($pdo, $adminId, 'reject', 'premium_request', $request['id'], $oldStatus, 'rejected', $reason);
            create_notification($pdo, $request['driver_id'], [
                'title' => 'Premium request rejected', 'message' => "Your request for \"{$request['feature_name']}\" was rejected. Reason: {$reason}",
                'notification_type' => 'premium', 'priority' => 'high', 'force' => true,
                'related_table' => 'driver_premium_requests', 'related_public_id' => $requestPublicId,
            ]);
            json_response('success', 'Premium request rejected.');
        }

        if ($action === 'disable') {
            $reason = trim((string)($input['reason'] ?? ''));
            // Wallet Billing: disabling an approved (i.e. already-paid)
            // request revokes value the driver already paid for, so it
            // refunds in full — the same credit_wallet() every other
            // wallet credit in the app uses, never a manual balance edit.
            $refunded = false;
            if ($oldStatus === 'approved') {
                credit_wallet($pdo, $request['driver_id'], $price, 'PREMIUM_FEATURE_REFUND',
                    "Refund: \"{$request['feature_name']}\" disabled by admin" . ($reason !== '' ? " ({$reason})" : ''),
                    null, null, ['request_public_id' => $requestPublicId, 'feature_id' => $request['feature_id']]);
                $refunded = true;
            }
            $pdo->prepare("UPDATE driver_premium_requests SET status = 'disabled', reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$reason !== '' ? $reason : null, $adminId, $request['id']]);
            refresh_driver_tier($pdo, $request['driver_id']);
            log_admin_action($pdo, $adminId, 'disable', 'premium_request', $request['id'], $oldStatus, 'disabled', $reason ?: null);
            create_notification($pdo, $request['driver_id'], [
                'title' => 'Premium feature disabled',
                'message' => "Your access to \"{$request['feature_name']}\" has been disabled by an administrator." . ($refunded ? " ₦" . number_format($price, 2) . " was refunded to your wallet." : ''),
                'notification_type' => 'premium', 'priority' => 'high', 'force' => true,
                'related_table' => 'driver_premium_requests', 'related_public_id' => $requestPublicId,
            ]);
            json_response('success', $refunded ? 'Premium feature disabled and refunded.' : 'Premium feature disabled.');
        }

        if ($action === 'delete_request') {
            // Part 5: "Delete requires confirmation" — enforced client-side
            // (the admin UI confirms before sending this). Logged before
            // the row disappears, same reasoning as every other
            // no-FK-to-the-audited-row pattern in this app.
            // Wallet Billing: same refund rule as 'disable' — deleting an
            // already-paid request without refunding would be silently
            // keeping the driver's money for nothing delivered.
            if ($oldStatus === 'approved') {
                credit_wallet($pdo, $request['driver_id'], $price, 'PREMIUM_FEATURE_REFUND',
                    "Refund: \"{$request['feature_name']}\" request deleted by admin",
                    null, null, ['request_public_id' => $requestPublicId, 'feature_id' => $request['feature_id']]);
            }
            log_admin_action($pdo, $adminId, 'delete', 'premium_request', $request['id'], $oldStatus, null, $input['reason'] ?? null);
            $pdo->prepare("DELETE FROM driver_premium_requests WHERE id = ?")->execute([$request['id']]);
            refresh_driver_tier($pdo, $request['driver_id']);
            json_response('success', 'Premium request deleted.');
        }
    }

    // Admin catalog management (create/update/toggle a feature).
    if ($action === 'save_feature') {
        // Catalog write (price/duration) -> Super-Admin-only, same tier as the
        // approve/reject/disable/delete_request money actions above.
        require_admin_access($pdo);
        require_super_admin($pdo);
        $featureId = (int)($input['id'] ?? 0);
        $name = trim((string)($input['feature_name'] ?? ''));
        $description = trim((string)($input['description'] ?? ''));
        $price = (float)($input['price'] ?? 0);
        $duration = max(1, (int)($input['duration_days'] ?? 30));
        $status = ($input['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        if ($name === '') {
            json_response('error', 'Feature name is required.');
        }

        if ($featureId > 0) {
            $pdo->prepare("UPDATE driver_premium_features SET feature_name = ?, description = ?, price = ?, duration_days = ?, status = ? WHERE id = ?")
                ->execute([$name, $description, $price, $duration, $status, $featureId]);
            log_admin_action($pdo, (int)$_SESSION['user_id'], 'update', 'premium_feature', $featureId, null, $status);
            json_response('success', 'Feature updated.');
        }

        $publicId = generate_unique_public_id($pdo, 'driver_premium_features', 'public_id', 'PFT');
        $stmt = $pdo->prepare("INSERT INTO driver_premium_features (public_id, feature_name, description, price, duration_days, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$publicId, $name, $description, $price, $duration, $status]);
        log_admin_action($pdo, (int)$_SESSION['user_id'], 'create', 'premium_feature', (int)$pdo->lastInsertId(), null, $status);
        json_response('success', 'Feature created.');
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
