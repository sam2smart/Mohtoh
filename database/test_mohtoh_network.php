<?php
// database/test_mohtoh_network.php
// Automated test suite for MOHTOH NETWORK (database/migrate_mohtoh_network.php,
// includes/network.php). Same pattern as database/test_private_pickup_pricing.php
// / test_private_pickup_http.php — a standalone, re-runnable script, not a
// PHPUnit suite (this project has none).
//
// Runs entirely inside ONE transaction that is ALWAYS rolled back at the
// end (success or failure), so it is safe to run against a real/shared
// dev database — it never leaves fixture data behind. Run with:
//   php database/test_mohtoh_network.php
// Exits 0 if every check passed, 1 otherwise (CI-friendly).
//
// Covers spec section 17's acceptance criteria: attribution, direct-only
// referral (no multi-level), per-transaction % reward calculation,
// idempotency under duplicate settlement calls, the combined-reward
// ceiling, refund/reversal, earning-window expiry, withdrawal approval,
// and the fraud heuristics (velocity, shared-IP multi-accounting,
// coordinated-both-sides, repeated driver/rider pair).
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/network.php';

$pass = 0; $fail = 0; $failures = [];
function check($label, $cond) {
    global $pass, $fail, $failures;
    if ($cond) { echo "PASS: $label\n"; $pass++; }
    else { echo "FAIL: $label\n"; $fail++; $failures[] = $label; }
}

function make_user($pdo, $suffix, $tag, $userType = 'rider') {
    $pdo->prepare("INSERT INTO users (fullname, phone, email, password, user_type) VALUES (?,?,?,?,?)")
        ->execute(["Test $tag $suffix", "07" . substr(md5($tag . $suffix . mt_rand()), 0, 9), "{$tag}{$suffix}@test.mohtoh", 'x', $userType]);
    return (int) $pdo->lastInsertId();
}

function make_booking($pdo, $driverId, $riderId, $commission, $suffix) {
    $pdo->prepare("INSERT INTO ride_shares (driver_id, destination, departure_date, departure_time, vehicle_type, vehicle_info, price_per_passenger, status, trip_state) VALUES (?, 'Test Dest', CURDATE(), '09:00:00', 'car', 'Test Car', 5000, 'completed', 'TRIP_COMPLETED')")
        ->execute([$driverId]);
    $rideId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO ride_share_bookings (ride_share_id, rider_id, status, payment_method) VALUES (?, ?, 'completed', 'wallet')")
        ->execute([$rideId, $riderId]);
    $bookingId = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO payments (ride_share_id, booking_id, rider_id, driver_id, fare_amount, commission_amount, driver_earning_amount, payment_method, status, confirmed_at) VALUES (?, ?, ?, ?, 5000, ?, 4600, 'wallet', 'PAYMENT_CONFIRMED', NOW())")
        ->execute([$rideId, $bookingId, $riderId, $driverId, $commission]);
    return $bookingId;
}

$pdo->beginTransaction();
try {
    $suffix = substr(bin2hex(random_bytes(4)), 0, 8);

    // Preconditions: set every toggle this suite depends on explicitly
    // rather than trusting whatever an admin last left live-configured
    // (e.g. someone exploring the admin Settings tab and leaving the
    // emergency payout switch flipped would otherwise make every money
    // check below fail for a reason that has nothing to do with the code).
    $preconditions = [
        'network_enabled' => '1', 'network_registrations_enabled' => '1', 'network_links_enabled' => '1',
        'network_rewards_enabled' => '1', 'network_withdrawals_enabled' => '1', 'network_emergency_payout_suspended' => '0',
        'network_driver_referrals_enabled' => '1', 'network_passenger_referrals_enabled' => '1', 'network_maintenance_mode' => '0',
        'network_partner_auto_approve' => '1', 'network_driver_reward_pct' => '15', 'network_passenger_reward_pct' => '10',
        'network_combined_ceiling_pct' => '25', 'network_earning_days' => '90', 'network_reward_hold_days' => '3',
        'network_min_withdrawal' => '1', 'network_total_budget' => '0', 'network_daily_budget' => '0', 'network_monthly_budget' => '0',
        'network_max_combined_payout_per_txn' => '0', 'network_fraud_velocity_max_per_day' => '10', 'network_fraud_same_pair_max_rewards' => '5',
    ];
    foreach ($preconditions as $key => $value) {
        $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
            ->execute([$key, $value]);
    }

    // ================= Fixtures =================
    $referrer = make_user($pdo, $suffix, 'referrer', 'driver');
    $referred = make_user($pdo, $suffix, 'referred', 'rider');
    $thirdDriver = make_user($pdo, $suffix, 'thirddriver', 'driver');
    $testAdmin = make_user($pdo, $suffix, 'testadmin', 'admin'); // stands in for "the admin performing this action" — audit FK needs a real user row

    // ================= Partner onboarding =================
    $partner = network_join($pdo, $referrer);
    check('partner auto-approved', $partner['status'] === 'approved');
    check('partner code has NETWORK- prefix', strpos($partner['referral_code'], 'NETWORK-') === 0);

    // ================= Attribution =================
    check('self-referral rejected', isset(network_attribute_referral($pdo, $referrer, $referrer, 'passenger')['error']));
    $attr = network_attribute_referral($pdo, $referrer, $referred, 'passenger');
    check('attribution succeeded', isset($attr['network_referral_id']));
    check('duplicate attribution rejected', isset(network_attribute_referral($pdo, $referrer, $referred, 'passenger')['error']));

    // ================= Milestones =================
    network_track_milestone($pdo, $referred, 'PHONE_VERIFIED');
    network_track_milestone($pdo, $referred, 'EMAIL_VERIFIED');
    $stmt = $pdo->prepare("SELECT * FROM network_referrals WHERE referred_user_id = ?");
    $stmt->execute([$referred]);
    $referral = $stmt->fetch();
    check('status verified after both checks', $referral['status'] === 'verified');

    network_track_milestone($pdo, $referred, 'FIRST_TRIP_COMPLETED');
    $stmt->execute([$referred]);
    $referral = $stmt->fetch();
    check('status active + earning window + attribution locked', $referral['status'] === 'active' && $referral['earning_starts_at'] && $referral['attribution_locked_at']);

    // Attribution correction refused once locked (spec 3's immutability rule)
    $correction = network_correct_attribution($pdo, $testAdmin, $referral['id'], $thirdDriver, 'test');
    check('attribution correction refused after lock', isset($correction['error']));

    // ================= Reward engine: basic math + idempotency =================
    $bookingId = make_booking($pdo, $thirdDriver, $referred, 400, $suffix);
    network_settle_booking_reward($pdo, $bookingId);
    $stmt = $pdo->prepare("SELECT * FROM network_reward_ledger WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    $rows = $stmt->fetchAll();
    check('exactly one ledger row (passenger referrer only)', count($rows) === 1);
    check('reward = 10% of ₦400 commission (₦40)', count($rows) === 1 && (float)$rows[0]['reward_amount'] === 40.0);

    network_settle_booking_reward($pdo, $bookingId);
    network_settle_booking_reward($pdo, $bookingId);
    $stmt->execute([$bookingId]);
    check('idempotent — still exactly one row after repeat settlement calls (duplicate webhook safety)', count($stmt->fetchAll()) === 1);

    $stmt = $pdo->prepare("SELECT pending_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$referrer]);
    check('wallet pending_balance credited exactly once (₦40)', (float)$stmt->fetchColumn() === 40.0);

    // ================= Reversal =================
    network_reverse_booking_rewards($pdo, $bookingId, 'test: refund');
    $stmt = $pdo->prepare("SELECT status FROM network_reward_ledger WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    check('ledger row reversed on refund', $stmt->fetchColumn() === 'reversed');
    $stmt = $pdo->prepare("SELECT pending_balance, reversed_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$referrer]);
    $w = $stmt->fetch();
    check('reversal moves pending -> reversed_balance, never double-reverses', (float)$w['pending_balance'] === 0.0 && (float)$w['reversed_balance'] === 40.0);
    network_settle_booking_reward($pdo, $bookingId);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_reward_ledger WHERE booking_id = ?");
    $stmt->execute([$bookingId]);
    check('reversed booking cannot be re-rewarded', (int)$stmt->fetchColumn() === 1);

    // ================= Combined ceiling scaling =================
    $referrer2 = make_user($pdo, $suffix, 'referrer2', 'driver');
    $referredDriver = make_user($pdo, $suffix, 'referreddriver', 'driver');
    network_join($pdo, $referrer2);
    network_attribute_referral($pdo, $referrer2, $referredDriver, 'driver');
    network_track_milestone($pdo, $referredDriver, 'PHONE_VERIFIED');
    network_track_milestone($pdo, $referredDriver, 'EMAIL_VERIFIED');
    network_track_milestone($pdo, $referredDriver, 'FIRST_TRIP_COMPLETED');
    $pdo->exec("UPDATE network_referrals SET status='active', earning_starts_at=NOW(), earning_expires_at=DATE_ADD(NOW(), INTERVAL 90 DAY) WHERE referred_user_id = $referred");
    $pdo->exec("UPDATE system_settings SET setting_value = '20' WHERE setting_key IN ('network_driver_reward_pct','network_passenger_reward_pct')");

    $bookingId2 = make_booking($pdo, $referredDriver, $referred, 400, $suffix);
    network_settle_booking_reward($pdo, $bookingId2);
    $stmt = $pdo->prepare("SELECT reward_amount FROM network_reward_ledger WHERE booking_id = ?");
    $stmt->execute([$bookingId2]);
    $sum2 = array_sum($stmt->fetchAll(PDO::FETCH_COLUMN));
    check('combined ceiling caps payout at 25% of commission (₦100) when configured pcts would exceed it', abs($sum2 - 100.0) < 0.01);

    // ================= Fraud: coordinated both sides =================
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags WHERE flag_type = 'coordinated_both_sides' AND user_id = ?");
    $stmt->execute([$referrer]);
    check('no coordinated-both-sides flag when referrers differ', (int)$stmt->fetchColumn() === 0);

    $soloReferrer = make_user($pdo, $suffix, 'soloreferrer', 'driver');
    $soloDriver = make_user($pdo, $suffix, 'sologdriver', 'driver');
    $soloRider = make_user($pdo, $suffix, 'solorider', 'rider');
    network_join($pdo, $soloReferrer);
    foreach ([[$soloDriver, 'driver'], [$soloRider, 'passenger']] as [$u, $cat]) {
        network_attribute_referral($pdo, $soloReferrer, $u, $cat);
        network_track_milestone($pdo, $u, 'PHONE_VERIFIED');
        network_track_milestone($pdo, $u, 'EMAIL_VERIFIED');
        network_track_milestone($pdo, $u, 'FIRST_TRIP_COMPLETED');
    }
    $bookingId3 = make_booking($pdo, $soloDriver, $soloRider, 400, $suffix);
    network_settle_booking_reward($pdo, $bookingId3);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags WHERE flag_type = 'coordinated_both_sides' AND user_id = ?");
    $stmt->execute([$soloReferrer]);
    check('coordinated-both-sides flagged when one partner referred both driver and rider', (int)$stmt->fetchColumn() === 1);

    // ================= Fraud: repeated driver/rider pair =================
    $pdo->exec("UPDATE system_settings SET setting_value = '2' WHERE setting_key = 'network_fraud_same_pair_max_rewards'");
    for ($i = 0; $i < 3; $i++) {
        $b = make_booking($pdo, $soloDriver, $soloRider, 400, $suffix . $i);
        network_settle_booking_reward($pdo, $b);
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags WHERE flag_type = 'repeated_pair_rewards' AND user_id = ?");
    $stmt->execute([$soloReferrer]);
    check('repeated same driver/rider pair flagged once threshold exceeded', (int)$stmt->fetchColumn() >= 1);

    // ================= Fraud: referral velocity =================
    $pdo->exec("UPDATE system_settings SET setting_value = '2' WHERE setting_key = 'network_fraud_velocity_max_per_day'");
    $velocityReferrer = make_user($pdo, $suffix, 'velref', 'driver');
    network_join($pdo, $velocityReferrer);
    for ($i = 0; $i < 4; $i++) {
        $u = make_user($pdo, $suffix . 'v' . $i, 'velreferred', 'rider');
        network_attribute_referral($pdo, $velocityReferrer, $u, 'passenger');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags WHERE flag_type = 'referral_velocity' AND user_id = ?");
    $stmt->execute([$velocityReferrer]);
    // Exactly 1, not one-per-referral-over-threshold: an ongoing pattern
    // must read as a single open case for an admin, not flag-spam.
    check('referral velocity flagged exactly once despite 2 referrals over threshold (no duplicate flags)', (int)$stmt->fetchColumn() === 1);

    // ================= Fraud: shared IP multi-accounting =================
    // network_attribute_referral() reads $_SERVER['REMOTE_ADDR'] — set it
    // for this CLI run to simulate two registrations from one IP.
    $_SERVER['REMOTE_ADDR'] = '203.0.113.42';
    $ipReferrer = make_user($pdo, $suffix, 'ipref', 'driver');
    network_join($pdo, $ipReferrer);
    $ipReferredA = make_user($pdo, $suffix, 'ipreferredA', 'rider');
    $ipReferredB = make_user($pdo, $suffix, 'ipreferredB', 'rider');
    network_attribute_referral($pdo, $ipReferrer, $ipReferredA, 'passenger');
    network_attribute_referral($pdo, $ipReferrer, $ipReferredB, 'passenger');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_fraud_flags WHERE flag_type = 'shared_ip_multiple_referrals' AND user_id = ?");
    $stmt->execute([$ipReferrer]);
    check('shared-IP multi-accounting flagged on second registration from same IP', (int)$stmt->fetchColumn() === 1);

    // ================= Withdrawals =================
    $pdo->exec("UPDATE network_reward_ledger SET hold_until = DATE_SUB(NOW(), INTERVAL 1 DAY) WHERE beneficiary_user_id = $referrer2 AND status = 'pending'");
    network_release_matured_rewards($pdo);
    $stmt = $pdo->prepare("SELECT available_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$referrer2]);
    $available = (float) $stmt->fetchColumn();
    check('reward matured to available after hold expiry', $available > 0);

    $pdo->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'network_min_withdrawal'");
    $accountId = network_add_withdrawal_account($pdo, $referrer2, 'Test Bank', '0123456789', 'Test Account');
    $pdo->prepare("UPDATE network_withdrawal_accounts SET verified_at = NOW() WHERE id = ?")->execute([$accountId]); // Phase 5: approval now requires a verified account
    $req = network_request_withdrawal($pdo, $referrer2, $accountId, $available);
    check('withdrawal request accepted and debits available_balance', isset($req['withdrawal_id']));
    $stmt->execute([$referrer2]);
    check('available_balance zeroed after full withdrawal request', (float)$stmt->fetchColumn() === 0.0);

    // A withdrawal must be approved before it can be marked paid — mirrors
    // the admin UI, which only offers "Mark Paid" once a request is
    // approved/processing, never straight from "pending".
    $skipToPaid = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'paid', 'TESTREF');
    check('cannot mark a still-pending withdrawal paid (must be approved first)', isset($skipToPaid['error']));

    $approval = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'approve');
    check('admin approval succeeds from pending', $approval['status'] === 'approved');

    $decision = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'paid', 'TESTREF');
    check('admin mark-paid requires and accepts a payment reference', $decision['status'] === 'paid');
    $stmt = $pdo->prepare("SELECT withdrawn_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$referrer2]);
    check('withdrawn_balance credited after payout', (float)$stmt->fetchColumn() === $available);

    $decisionNoRef = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'paid', '');
    check('mark-paid without a reference is refused (spec: never mark paid without verified confirmation)', isset($decisionNoRef['error']));

    // State-machine guard: re-submitting ANY decision on an already-PAID
    // withdrawal must be refused — otherwise re-marking paid (or
    // rejecting one that already paid out) would double-credit
    // withdrawn_balance or fabricate available_balance for money that
    // already left the platform.
    $doublePaid = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'paid', 'TESTREF2');
    check('cannot mark an already-paid withdrawal paid again (no double-credit)', isset($doublePaid['error']));
    $rejectAfterPaid = network_admin_decide_withdrawal($pdo, $testAdmin, $req['withdrawal_id'], 'reject', 'too late');
    check('cannot reject an already-paid withdrawal (no fabricated refund)', isset($rejectAfterPaid['error']));
    $stmt->execute([$referrer2]);
    check('withdrawn_balance unchanged after rejected double-processing attempts', (float)$stmt->fetchColumn() === $available);

    // ================= Negative / boundary: cannot withdraw more than available, cannot go negative =================
    $overdraw = network_request_withdrawal($pdo, $referrer2, $accountId, 999999999);
    check('withdrawal request for far more than available balance is rejected', isset($overdraw['error']));
    $stmt = $pdo->prepare("SELECT available_balance, pending_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$referrer2]);
    $w2 = $stmt->fetch();
    check('no operation ever drives available_balance negative', (float)$w2['available_balance'] >= 0);
    check('no operation ever drives pending_balance negative', (float)$w2['pending_balance'] >= 0);

    // ================= Ledger reconciliation =================
    // network_reward_ledger.status never transitions to 'withdrawn' —
    // a withdrawal draws from the AGGREGATE available pool, not a
    // traceable FIFO queue of individual reward rows (the ENUM value
    // is reserved for a possible future per-reward audit trail; see
    // docs/mohtoh-network.md). The invariant that must therefore hold
    // is: pending_balance matches ledger rows still 'pending', reversed_
    // balance matches ledger rows 'reversed', and — because a reward's
    // ledger row stays 'available' forever once matured, regardless of
    // whether it was later withdrawn — available_balance PLUS
    // withdrawn_balance together must equal the ledger's 'available' sum.
    $stmt = $pdo->query("
        SELECT w.user_id, w.pending_balance, w.available_balance, w.withdrawn_balance, w.reversed_balance,
               COALESCE((SELECT SUM(reward_amount) FROM network_reward_ledger WHERE beneficiary_user_id = w.user_id AND status = 'pending'), 0) AS ledger_pending,
               COALESCE((SELECT SUM(reward_amount) FROM network_reward_ledger WHERE beneficiary_user_id = w.user_id AND status = 'available'), 0) AS ledger_available,
               COALESCE((SELECT SUM(reward_amount) FROM network_reward_ledger WHERE beneficiary_user_id = w.user_id AND status = 'reversed'), 0) AS ledger_reversed
        FROM network_wallets w
        WHERE w.user_id IN ($referrer, $referrer2, $soloReferrer)
    ")->fetchAll();
    $reconciled = true;
    foreach ($stmt as $row) {
        if (abs($row['pending_balance'] - $row['ledger_pending']) > 0.01
            || abs(($row['available_balance'] + $row['withdrawn_balance']) - $row['ledger_available']) > 0.01
            || abs($row['reversed_balance'] - $row['ledger_reversed']) > 0.01) {
            $reconciled = false;
        }
    }
    check('network_wallets balances reconcile exactly against network_reward_ledger for every test beneficiary', $reconciled);

    // ================= Reconciliation INCLUDING an in-flight (pending-review) withdrawal =================
    // A withdrawal spends a short time as neither "available" (debited at
    // request time) nor "withdrawn" (credited only once actually paid) —
    // it is tracked by its own network_withdrawals row in the meantime.
    // The complete invariant, proven here with a withdrawal deliberately
    // left un-resolved, is:
    //   available_balance + withdrawn_balance
    //     + SUM(network_withdrawals.amount WHERE status IN
    //           ('pending','under_review','approved','processing'))
    //   == SUM(network_reward_ledger.reward_amount WHERE status='available')
    $inFlightUser = make_user($pdo, $suffix, 'inflight', 'driver');
    network_join($pdo, $inFlightUser);
    ensure_network_wallet($pdo, $inFlightUser);
    $pdo->exec("UPDATE network_wallets SET available_balance = 75 WHERE user_id = $inFlightUser");
    $pdo->exec("INSERT INTO network_reward_ledger (public_id, network_referral_id, booking_id, beneficiary_user_id, beneficiary_role, commission_base, reward_pct, reward_amount, status, available_at) SELECT 'NRWD-TESTFIX', id, 0, $inFlightUser, 'passenger_referrer', 750, 10, 75, 'available', NOW() FROM network_referrals LIMIT 1");
    $inFlightAccount = network_add_withdrawal_account($pdo, $inFlightUser, 'InFlight Bank', '5566778899', 'InFlight Test');
    $inFlightReq = network_request_withdrawal($pdo, $inFlightUser, $inFlightAccount, 75);
    check('in-flight withdrawal request accepted', isset($inFlightReq['withdrawal_id']));

    $stmt = $pdo->prepare("SELECT pending_balance, available_balance, withdrawn_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$inFlightUser]);
    $wf = $stmt->fetch();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM network_withdrawals WHERE user_id = ? AND status IN ('pending','under_review','approved','processing')");
    $stmt->execute([$inFlightUser]);
    $inFlightAmount = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(reward_amount),0) FROM network_reward_ledger WHERE beneficiary_user_id = ? AND status = 'available'");
    $stmt->execute([$inFlightUser]);
    $ledgerAvailable = (float) $stmt->fetchColumn();
    $completeInvariant = abs(($wf['available_balance'] + $wf['withdrawn_balance'] + $inFlightAmount) - $ledgerAvailable) < 0.01;
    check('complete reconciliation invariant holds WITH an unresolved in-flight withdrawal (available + withdrawn + in-flight == ledger available)', $completeInvariant);

    // A rejected withdrawal must restore the available balance, not
    // permanently reduce it — reject the SAME in-flight request and
    // confirm the ₦75 comes back.
    $rejection = network_admin_decide_withdrawal($pdo, $testAdmin, $inFlightReq['withdrawal_id'], 'reject', 'Demo: bank details could not be verified');
    check('rejecting a pending withdrawal succeeds', $rejection['status'] === 'rejected');
    $stmt2 = $pdo->prepare("SELECT available_balance FROM network_wallets WHERE user_id = ?");
    $stmt2->execute([$inFlightUser]);
    check('rejected withdrawal fully restores available_balance (not permanently reduced)', abs((float)$stmt2->fetchColumn() - 75.0) < 0.01);

    // ================= Administrative adjustments =================
    $adjCredit = network_adjust_wallet($pdo, $testAdmin, $inFlightUser, 25.00, 'credit', 'Demo: goodwill credit for a support ticket');
    check('admin credit adjustment succeeds and is audited', isset($adjCredit['public_id']));
    $stmt2->execute([$inFlightUser]);
    check('credit adjustment increases available_balance by exactly the adjustment amount', abs((float)$stmt2->fetchColumn() - 100.0) < 0.01); // 75 restored + 25 credited

    $adjDebit = network_adjust_wallet($pdo, $testAdmin, $inFlightUser, 10.00, 'debit', 'Demo: correcting an over-credit');
    check('admin debit adjustment succeeds and is audited', isset($adjDebit['public_id']));
    $stmt2->execute([$inFlightUser]);
    check('debit adjustment decreases available_balance by exactly the adjustment amount', abs((float)$stmt2->fetchColumn() - 90.0) < 0.01);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_reward_adjustments WHERE user_id = ? AND admin_id = ?");
    $stmt->execute([$inFlightUser, $testAdmin]);
    check('both adjustments left a network_reward_adjustments audit row each', (int)$stmt->fetchColumn() === 2);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE admin_user_id = ? AND action = 'network_manual_adjustment'");
    $stmt->execute([$testAdmin]);
    check('both adjustments were ALSO logged to the generic admin_audit_logs trail', (int)$stmt->fetchColumn() === 2);

    $adjNoReason = network_adjust_wallet($pdo, $testAdmin, $inFlightUser, 5.00, 'credit', '');
    check('adjustment without a reason is refused', isset($adjNoReason['error']));

    // ================= Module switching (spec 16): network OFF must not block transport =================
    $pdo->exec("UPDATE system_settings SET setting_value = '0' WHERE setting_key = 'network_rewards_enabled'");
    $bookingOff = make_booking($pdo, $thirdDriver, $referred, 400, $suffix . 'off');
    network_settle_booking_reward($pdo, $bookingOff); // must not throw, must not reward
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_reward_ledger WHERE booking_id = ?");
    $stmt->execute([$bookingOff]);
    check('rewards_enabled=0 blocks new reward generation without error', (int)$stmt->fetchColumn() === 0);
    $pdo->exec("UPDATE system_settings SET setting_value = '1' WHERE setting_key = 'network_rewards_enabled'");

    echo "\n==== $pass passed, $fail failed ====\n";
    if ($failures) {
        echo "Failed checks:\n - " . implode("\n - ", $failures) . "\n";
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
} finally {
    $pdo->rollBack();
    echo "(transaction rolled back — no data persisted)\n";
}
exit($fail > 0 ? 1 : 0);
