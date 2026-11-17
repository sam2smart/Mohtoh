<?php
// api/network-admin.php
// Admin surface for MOHTOH NETWORK — Phase 3. Shape follows
// api/referral-admin.php / api/pricing-admin.php exactly: GET is open to
// any admin holding 'network.view' (read-only reporting/investigation —
// Support Admin's role in the spec); every WRITE is gated per the
// spec's role list (section 12):
//   * Programme Settings / Reward Configuration / Financial Controls /
//     Campaigns -> require_super_admin() (same as pricing/promotions/
//     commission — never grantable, matches "Super Administrator: full
//     configuration and programme control").
//   * Partner approve/suspend/reactivate + referral attribution
//     corrections -> 'network.partners.manage' (Network Manager).
//   * Reward adjustments/reversals, withdrawal decisions, fraud flag
//     resolution -> 'network.finance.manage' (Finance Administrator).
// Every write is audited (log_admin_action() / log_pricing_change(),
// both reused from the existing admin infrastructure — no new audit
// table). Business logic itself lives in includes/network.php; this
// file only authenticates, authorizes, reads params, and reports.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/network.php';
require_once '../includes/admin_permissions.php';

header('Content-Type: application/json');

require_admin_permission($pdo, 'network.view'); // baseline for this whole file; writes re-check their own specific permission below

const NETWORK_SETTING_KEYS = [
    'network_enabled', 'network_registrations_enabled', 'network_links_enabled', 'network_rewards_enabled',
    'network_withdrawals_enabled', 'network_driver_referrals_enabled', 'network_passenger_referrals_enabled',
    'network_external_partners_enabled', 'network_maintenance_mode', 'network_emergency_payout_suspended',
    'network_launch_at', 'network_closes_at', 'network_terms_version', 'network_terms_text', 'network_announcement',
    'network_partner_auto_approve', 'network_driver_reward_pct', 'network_passenger_reward_pct',
    'network_combined_ceiling_pct', 'network_earning_days', 'network_min_withdrawal',
    'network_reward_hold_days', 'network_total_budget', 'network_daily_budget', 'network_monthly_budget',
    'network_max_combined_payout_per_txn', 'network_max_withdrawal_per_day', 'network_max_withdrawal_per_month',
    'network_fraud_velocity_max_per_day', 'network_fraud_same_pair_max_rewards',
];

$action = $_GET['action'] ?? ($_POST['action'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    if ($action === 'settings') {
        $placeholders = implode(',', array_fill(0, count(NETWORK_SETTING_KEYS), '?'));
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
        $stmt->execute(NETWORK_SETTING_KEYS);
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        foreach (NETWORK_SETTING_KEYS as $key) {
            if (!isset($settings[$key])) {
                $settings[$key] = '';
            }
        }
        json_response('success', 'Settings fetched', ['settings' => $settings]);
    }

    if ($action === 'overview') {
        $stats = $pdo->query("
            SELECT COUNT(*) AS total_partners,
                   SUM(status = 'approved') AS approved_partners,
                   SUM(status = 'pending') AS pending_partners,
                   SUM(status = 'suspended') AS suspended_partners
            FROM network_partners
        ")->fetch();
        $referralStats = $pdo->query("
            SELECT COUNT(*) AS total_referrals,
                   SUM(status = 'active') AS active_referrals,
                   SUM(status = 'expired') AS expired_referrals,
                   SUM(referred_category = 'driver') AS driver_referrals,
                   SUM(referred_category = 'passenger') AS passenger_referrals,
                   SUM(first_qualifying_ride_at IS NOT NULL) AS converted_referrals
            FROM network_referrals WHERE status != 'legacy_migrated'
        ")->fetch();
        $rewardStats = $pdo->query("
            SELECT SUM(reward_amount) AS total_generated,
                   SUM(CASE WHEN status = 'pending' THEN reward_amount ELSE 0 END) AS total_pending,
                   SUM(CASE WHEN status = 'available' THEN reward_amount ELSE 0 END) AS total_available,
                   SUM(CASE WHEN status = 'reversed' THEN reward_amount ELSE 0 END) AS total_reversed,
                   SUM(commission_base) AS referral_generated_commission
            FROM network_reward_ledger
        ")->fetch();
        $withdrawalStats = $pdo->query("
            SELECT SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) AS total_paid,
                   SUM(CASE WHEN status IN ('pending','under_review','approved','processing') THEN amount ELSE 0 END) AS total_in_flight,
                   COUNT(*) AS total_requests
            FROM network_withdrawals
        ")->fetch();
        $openFraudFlags = (int) $pdo->query("SELECT COUNT(*) FROM network_fraud_flags WHERE status = 'open'")->fetchColumn();

        json_response('success', 'Overview fetched', [
            'partners' => array_map('intval', $stats ?: []),
            'referrals' => array_map('intval', $referralStats ?: []),
            'rewards' => [
                'total_generated' => round((float)($rewardStats['total_generated'] ?? 0), 2),
                'total_pending' => round((float)($rewardStats['total_pending'] ?? 0), 2),
                'total_available' => round((float)($rewardStats['total_available'] ?? 0), 2),
                'total_reversed' => round((float)($rewardStats['total_reversed'] ?? 0), 2),
                'referral_generated_commission' => round((float)($rewardStats['referral_generated_commission'] ?? 0), 2),
            ],
            'withdrawals' => [
                'total_paid' => round((float)($withdrawalStats['total_paid'] ?? 0), 2),
                'total_in_flight' => round((float)($withdrawalStats['total_in_flight'] ?? 0), 2),
                'total_requests' => (int)($withdrawalStats['total_requests'] ?? 0),
            ],
            'open_fraud_flags' => $openFraudFlags,
        ]);
    }

    if ($action === 'partners') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'p.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['q'])) {
            $where[] = '(u.fullname LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR p.referral_code LIKE ?)';
            $q = '%' . $_GET['q'] . '%';
            array_push($params, $q, $q, $q, $q);
        }
        $sql = "
            SELECT p.id, p.public_id, p.referral_code, p.status, p.joined_at, p.suspension_reason,
                   u.id AS user_id, u.fullname, u.user_id AS public_user_id, u.user_type, u.phone, u.email,
                   (SELECT COUNT(*) FROM network_referrals r WHERE r.referrer_user_id = u.id AND r.status != 'legacy_migrated') AS total_referrals,
                   (SELECT COALESCE(SUM(total_rewards_generated), 0) FROM network_referrals r WHERE r.referrer_user_id = u.id) AS lifetime_rewards
            FROM network_partners p
            JOIN users u ON u.id = p.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.joined_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_partners p JOIN users u ON u.id = p.user_id WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Partners fetched', ['partners' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'partner_detail') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $partner = get_network_partner($pdo, $userId);
        if (!$partner) {
            json_response('error', 'Partner not found.');
        }
        json_response('success', 'Partner detail fetched', [
            'partner' => $partner,
            'stats' => get_network_partner_stats($pdo, $userId),
            'referrals' => get_network_partner_referrals($pdo, $userId),
        ]);
    }

    if ($action === 'referrals') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['category'])) {
            $where[] = 'r.referred_category = ?';
            $params[] = $_GET['category'];
        }
        if (!empty($_GET['q'])) {
            $where[] = '(ref.fullname LIKE ? OR rd.fullname LIKE ?)';
            $q = '%' . $_GET['q'] . '%';
            array_push($params, $q, $q);
        }
        $sql = "
            SELECT r.id, r.public_id, r.referred_category, r.status, r.registered_at, r.first_qualifying_ride_at,
                   r.earning_starts_at, r.earning_expires_at, r.qualifying_ride_count, r.total_commission_generated,
                   r.total_rewards_generated, r.attribution_locked_at,
                   ref.id AS referrer_id, ref.fullname AS referrer_name, ref.user_id AS referrer_public_id,
                   rd.id AS referred_id, rd.fullname AS referred_name, rd.user_id AS referred_public_id
            FROM network_referrals r
            JOIN users ref ON ref.id = r.referrer_user_id
            JOIN users rd ON rd.id = r.referred_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_referrals r JOIN users ref ON ref.id = r.referrer_user_id JOIN users rd ON rd.id = r.referred_user_id WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Referrals fetched', ['referrals' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'reward_ledger') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'l.status = ?';
            $params[] = $_GET['status'];
        }
        $sql = "
            SELECT l.id, l.public_id, l.booking_id, l.beneficiary_role, l.commission_base, l.reward_pct, l.reward_amount,
                   l.status, l.hold_until, l.available_at, l.reversed_at, l.reversal_reason, l.created_at,
                   u.fullname AS beneficiary_name, u.user_id AS beneficiary_public_id
            FROM network_reward_ledger l
            JOIN users u ON u.id = l.beneficiary_user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY l.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_reward_ledger l WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Reward ledger fetched', ['ledger' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'withdrawals') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'w.status = ?';
            $params[] = $_GET['status'];
        }
        $sql = "
            SELECT w.id, w.public_id, w.amount, w.status, w.requested_at, w.reviewed_at, w.payment_reference,
                   w.rejection_reason, w.processed_at,
                   u.fullname AS user_name, u.user_id AS public_user_id,
                   a.id AS withdrawal_account_id, a.bank_name, a.account_number_last4, a.account_name, a.verified_at AS account_verified_at
            FROM network_withdrawals w
            JOIN users u ON u.id = w.user_id
            JOIN network_withdrawal_accounts a ON a.id = w.withdrawal_account_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY w.requested_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_withdrawals w WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Withdrawals fetched', ['withdrawals' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'fraud_flags') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'f.status = ?';
            $params[] = $_GET['status'];
        }
        $sql = "
            SELECT f.id, f.public_id, f.flag_type, f.severity, f.status, f.details, f.resolution_notes, f.created_at, f.resolved_at,
                   u.fullname AS user_name, u.user_id AS public_user_id
            FROM network_fraud_flags f
            JOIN users u ON u.id = f.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags f WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Fraud flags fetched', ['flags' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'campaigns') {
        $rows = $pdo->query("SELECT * FROM network_campaigns ORDER BY created_at DESC")->fetchAll();
        json_response('success', 'Campaigns fetched', ['campaigns' => $rows]);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    // Every state-changing admin action below (settings, campaigns,
    // partner/referral/reward/withdrawal/fraud decisions) requires a
    // valid per-session CSRF token — checked once here, before the
    // RBAC/role gates further down, so nothing new added below can
    // accidentally skip it. See includes/functions.php's require_csrf();
    // the token is rendered into admin/dashboard.php via
    // <meta name="csrf-token">.
    require_csrf($input);
    $adminId = (int) $_SESSION['user_id'];

    if ($action === 'update_settings') {
        require_super_admin($pdo);
        $changed = [];
        $pdo->beginTransaction();
        try {
            if (isset($input['settings']) && is_array($input['settings'])) {
                foreach ($input['settings'] as $key => $value) {
                    if (!in_array($key, NETWORK_SETTING_KEYS, true)) {
                        continue;
                    }
                    $value = trim((string)$value);
                    $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                    $stmt->execute([$key]);
                    $oldValue = $stmt->fetchColumn();
                    $oldValue = $oldValue === false ? null : $oldValue;
                    if ((string)$oldValue === $value) {
                        continue;
                    }
                    $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()")
                        ->execute([$key, $value]);
                    log_pricing_change($pdo, $adminId, $key, $oldValue, $value);
                    $changed[] = $key;
                }
            }
            $pdo->commit();
            json_response('success', $changed ? 'Settings updated.' : 'No changes to save.', ['changed' => $changed]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', 'Failed to update settings: ' . $e->getMessage());
        }
    }

    if ($action === 'create_campaign') {
        require_super_admin($pdo);
        $name = trim((string)($input['name'] ?? ''));
        $startsAt = $input['starts_at'] ?? null;
        $endsAt = $input['ends_at'] ?? null;
        if ($name === '' || !$startsAt || !$endsAt) {
            json_response('error', 'Name, start date and end date are required.');
        }
        $publicId = generate_unique_public_id($pdo, 'network_campaigns', 'public_id', 'NCAMP');
        $stmt = $pdo->prepare("
            INSERT INTO network_campaigns (public_id, name, description, driver_reward_pct, passenger_reward_pct, combined_ceiling_pct, earning_days, starts_at, ends_at, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'scheduled', ?)
        ");
        $stmt->execute([
            $publicId, $name, $input['description'] ?? null,
            ($input['driver_reward_pct'] ?? '') !== '' ? $input['driver_reward_pct'] : null,
            ($input['passenger_reward_pct'] ?? '') !== '' ? $input['passenger_reward_pct'] : null,
            ($input['combined_ceiling_pct'] ?? '') !== '' ? $input['combined_ceiling_pct'] : null,
            ($input['earning_days'] ?? '') !== '' ? $input['earning_days'] : null,
            $startsAt, $endsAt,
            $adminId,
        ]);
        log_admin_action($pdo, $adminId, 'network_campaign_created', 'network_campaigns', (int)$pdo->lastInsertId(), null, 'scheduled', $name);
        json_response('success', 'Campaign created.', ['public_id' => $publicId]);
    }

    if ($action === 'end_campaign') {
        require_super_admin($pdo);
        $campaignId = (int)($input['campaign_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE network_campaigns SET status = 'ended' WHERE id = ?");
        $stmt->execute([$campaignId]);
        log_admin_action($pdo, $adminId, 'network_campaign_ended', 'network_campaigns', $campaignId, null, 'ended');
        json_response('success', 'Campaign ended.');
    }

    // Each action below checks its OWN required permission (Network
    // Manager vs. Finance Admin) — never a blanket check shared across
    // both groups, or one role would wrongly gate out the other.
    $partnerActions = ['approve_partner', 'suspend_partner', 'reactivate_partner', 'correct_attribution'];
    $financeActions = ['adjust_reward', 'reverse_reward', 'decide_withdrawal', 'resolve_fraud_flag', 'verify_withdrawal_account'];
    if (in_array($action, $partnerActions, true)) {
        require_admin_permission($pdo, 'network.partners.manage');
    } elseif (in_array($action, $financeActions, true)) {
        require_admin_permission($pdo, 'network.finance.manage');
    }

    if ($action === 'approve_partner') {
        $result = network_admin_approve_partner($pdo, $adminId, (int)($input['partner_id'] ?? 0));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Partner approved.', $result);
    }
    if ($action === 'suspend_partner') {
        $result = network_admin_suspend_partner($pdo, $adminId, (int)($input['partner_id'] ?? 0), trim((string)($input['reason'] ?? '')));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Partner suspended.', $result);
    }
    if ($action === 'reactivate_partner') {
        $result = network_admin_reactivate_partner($pdo, $adminId, (int)($input['partner_id'] ?? 0));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Partner reactivated.', $result);
    }
    if ($action === 'correct_attribution') {
        $result = network_correct_attribution($pdo, $adminId, (int)($input['network_referral_id'] ?? 0), (int)($input['new_referrer_user_id'] ?? 0), trim((string)($input['reason'] ?? '')));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Attribution corrected.', $result);
    }

    if ($action === 'adjust_reward') {
        $result = network_adjust_wallet($pdo, $adminId, (int)($input['user_id'] ?? 0), (float)($input['amount'] ?? 0), $input['type'] ?? '', trim((string)($input['reason'] ?? '')));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Balance adjusted.', $result);
    }
    // Both of these take a SELECT ... FOR UPDATE row lock that only
    // actually serializes concurrent requests inside a real transaction
    // (see the matching comment in api/network.php's request_withdrawal) —
    // e.g. two admins clicking "Mark Paid" on the same withdrawal at once,
    // or an admin reversing a reward the engine is settling concurrently.
    if ($action === 'reverse_reward') {
        $pdo->beginTransaction();
        try {
            $result = network_reverse_ledger_row($pdo, $adminId, (int)($input['ledger_id'] ?? 0), trim((string)($input['reason'] ?? '')));
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Reward reversed.', $result);
    }
    if ($action === 'decide_withdrawal') {
        $pdo->beginTransaction();
        try {
            $result = network_admin_decide_withdrawal($pdo, $adminId, (int)($input['withdrawal_id'] ?? 0), $input['decision'] ?? '', $input['note'] ?? null);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Withdrawal updated.', $result);
    }
    if ($action === 'resolve_fraud_flag') {
        $result = network_resolve_fraud_flag($pdo, $adminId, (int)($input['flag_id'] ?? 0), $input['status'] ?? '', trim((string)($input['notes'] ?? '')));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Fraud flag updated.', $result);
    }
    if ($action === 'verify_withdrawal_account') {
        $result = network_admin_verify_withdrawal_account($pdo, $adminId, (int)($input['account_id'] ?? 0));
        json_response(isset($result['error']) ? 'error' : 'success', $result['error'] ?? 'Withdrawal account verified.', $result);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
