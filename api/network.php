<?php
// api/network.php
// User-facing MOHTOH NETWORK endpoint — the Network Partner's own
// dashboard (join, referral code/link, stats, referral/reward/withdrawal
// history, bank details, withdrawal requests). Thin by design, same
// shape as api/referrals.php: every rule lives in includes/network.php;
// this file only authenticates, validates input shape, and routes.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php'; // APP_BASE_URL
require_once '../includes/network.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}
$user_id = (int) $_SESSION['user_id'];

function network_join_url($code) {
    return rtrim(APP_BASE_URL, '/') . '/join.php?ref=' . urlencode($code);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'status';

    if ($action === 'status') {
        $partner = get_network_partner($pdo, $user_id);
        json_response('success', 'Status fetched', [
            'is_partner' => (bool) $partner,
            'partner' => $partner,
            'join_url' => $partner ? network_join_url($partner['referral_code']) : null,
            'network_enabled' => network_enabled($pdo),
            'registrations_enabled' => network_registrations_enabled($pdo),
            'terms_version' => network_setting($pdo, 'network_terms_version', '1.0'),
            'terms_text' => network_setting($pdo, 'network_terms_text', ''),
            'announcement' => network_setting($pdo, 'network_announcement', ''),
        ]);
    }

    if ($action === 'stats') {
        if (!get_network_partner($pdo, $user_id)) {
            json_response('error', 'You are not a Network Partner yet.');
        }
        json_response('success', 'Stats fetched', get_network_partner_stats($pdo, $user_id));
    }

    if ($action === 'referrals') {
        json_response('success', 'Referrals fetched', ['referrals' => get_network_partner_referrals($pdo, $user_id)]);
    }

    if ($action === 'reward_history') {
        $stmt = $pdo->prepare("
            SELECT public_id, booking_id, beneficiary_role, commission_base, reward_pct, reward_amount, status,
                   hold_until, available_at, reversed_at, reversal_reason, created_at
            FROM network_reward_ledger
            WHERE beneficiary_user_id = ?
            ORDER BY created_at DESC
            LIMIT 100
        ");
        $stmt->execute([$user_id]);
        json_response('success', 'Reward history fetched', ['rewards' => $stmt->fetchAll()]);
    }

    if ($action === 'withdrawal_history') {
        $stmt = $pdo->prepare("
            SELECT w.public_id, w.amount, w.status, w.requested_at, w.payment_reference, w.rejection_reason,
                   a.bank_name, a.account_number_last4
            FROM network_withdrawals w
            JOIN network_withdrawal_accounts a ON a.id = w.withdrawal_account_id
            WHERE w.user_id = ?
            ORDER BY w.requested_at DESC
            LIMIT 100
        ");
        $stmt->execute([$user_id]);
        json_response('success', 'Withdrawal history fetched', ['withdrawals' => $stmt->fetchAll()]);
    }

    if ($action === 'withdrawal_accounts') {
        $stmt = $pdo->prepare("SELECT id, public_id, bank_name, account_number_last4, account_name, is_default FROM network_withdrawal_accounts WHERE user_id = ? ORDER BY is_default DESC, created_at DESC");
        $stmt->execute([$user_id]);
        json_response('success', 'Withdrawal accounts fetched', ['accounts' => $stmt->fetchAll()]);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    // Every state-changing action below (join, share_link, bank account
    // changes, withdrawal requests) requires a valid per-session CSRF
    // token — checked once here rather than per action, so nothing new
    // added below can accidentally skip it. See includes/functions.php's
    // require_csrf(); the token is rendered into every page that includes
    // partials/network-sheet.php via <meta name="csrf-token">.
    require_csrf($input);
    $action = $input['action'] ?? '';

    if ($action === 'join') {
        if (empty($input['accept_terms'])) {
            json_response('error', 'You must accept the programme terms to continue.');
        }
        $result = network_join($pdo, $user_id, $input['terms_version'] ?? null);
        if (isset($result['error'])) {
            json_response('error', $result['error']);
        }
        json_response('success', $result['status'] === 'approved' ? 'Welcome to MOHTOH NETWORK!' : 'Application submitted — pending review.', ['partner' => $result]);
    }

    if ($action === 'share_link') {
        $partner = get_network_partner($pdo, $user_id);
        if (!$partner || $partner['status'] !== 'approved') {
            json_response('error', 'Your MOHTOH NETWORK account is not yet approved.');
        }
        $joinUrl = network_join_url($partner['referral_code']);
        $shareText = "Don't just use MOHTOH. Grow with MOHTOH. Join with my link: {$joinUrl}";
        json_response('success', 'Share link ready', [
            'referral_code' => $partner['referral_code'],
            'join_url' => $joinUrl,
            'share_text' => $shareText,
            'whatsapp_url' => 'https://wa.me/?text=' . urlencode($shareText),
            'sms_url' => 'sms:?body=' . urlencode($shareText),
        ]);
    }

    if ($action === 'add_withdrawal_account') {
        $bankName = trim((string)($input['bank_name'] ?? ''));
        $accountNumber = trim((string)($input['account_number'] ?? ''));
        $accountName = trim((string)($input['account_name'] ?? ''));
        if ($bankName === '' || $accountNumber === '' || $accountName === '') {
            json_response('error', 'Bank name, account number and account name are all required.');
        }
        if (!preg_match('/^[0-9]{6,20}$/', $accountNumber)) {
            json_response('error', 'Enter a valid account number.');
        }
        $id = network_add_withdrawal_account($pdo, $user_id, $bankName, $accountNumber, $accountName, true);
        json_response('success', 'Bank account added.', ['id' => $id]);
    }

    if ($action === 'request_withdrawal') {
        $accountId = (int)($input['withdrawal_account_id'] ?? 0);
        $amount = (float)($input['amount'] ?? 0);
        // Explicit transaction: network_request_withdrawal() takes a
        // SELECT ... FOR UPDATE row lock on network_wallets, which only
        // actually serializes concurrent requests when held inside a
        // real transaction (MySQL's default autocommit mode releases a
        // FOR UPDATE lock at the end of its own single statement) —
        // same convention credit_wallet()/debit_wallet() document and
        // rely on their callers for.
        $pdo->beginTransaction();
        try {
            $result = network_request_withdrawal($pdo, $user_id, $accountId, $amount);
            if (isset($result['error'])) {
                $pdo->rollBack();
                json_response('error', $result['error']);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        json_response('success', 'Withdrawal requested.', $result);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
