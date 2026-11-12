<?php
// includes/network.php
// MOHTOH NETWORK — the direct-referral / revenue-share programme that
// REPLACES MohToh Community Rewards (includes/referral.php). Every
// business rule here is read through network_setting()/system_settings,
// never hardcoded, and every money-moving function is idempotent and
// fails SAFE: if anything here throws or a toggle is off, normal
// transport (booking/trip/payment) must never be affected — callers at
// the integration points (includes/functions.php, api/rideshare.php)
// wrap calls to this file defensively for exactly that reason.
//
// Reuses, never duplicates: payments.commission_amount is the ONLY
// source of "realized MOHTOH commission" (never recomputed here);
// users.referred_by / users.is_network_partner follow the same
// capability-flag pattern as users.is_admin; create_notification() is
// the one notification writer; generate_unique_public_id() is the one
// public-id generator; encrypt_secret()/decrypt_secret() (secrets.php)
// is the one credential cipher, reused here for bank account numbers;
// log_admin_action() (audit.php) is the one generic admin audit writer.
//
// Direct referrals ONLY — one level. A beneficiary is always the
// DIRECT referrer of the user whose ride just settled; this file has
// no concept of a referrer's referrer and must never grow one.

require_once __DIR__ . '/secrets.php';
require_once __DIR__ . '/audit.php';

// ---------------------------------------------------------------
// Programme settings — every admin-configurable value in the spec
// lives in system_settings under a network_* key, read through the
// same get_system_setting() every other admin-configurable area
// (pricing, promotions, the old referral system) already uses.
// ---------------------------------------------------------------
function network_setting($pdo, $key, $default) {
    return get_system_setting($pdo, $key, $default);
}

function network_enabled($pdo) {
    return (bool) network_setting($pdo, 'network_enabled', true) && !network_maintenance_mode($pdo);
}
function network_maintenance_mode($pdo) {
    return (bool) network_setting($pdo, 'network_maintenance_mode', false);
}
function network_registrations_enabled($pdo) {
    return network_enabled($pdo) && (bool) network_setting($pdo, 'network_registrations_enabled', true);
}
function network_links_enabled($pdo) {
    return network_enabled($pdo) && (bool) network_setting($pdo, 'network_links_enabled', true);
}
function network_rewards_enabled($pdo) {
    return network_enabled($pdo) && (bool) network_setting($pdo, 'network_rewards_enabled', true) && !network_payouts_suspended($pdo);
}
function network_withdrawals_enabled($pdo) {
    return network_enabled($pdo) && (bool) network_setting($pdo, 'network_withdrawals_enabled', true);
}
// The emergency switch (spec 4D): suspends REWARD PAYOUT GENERATION
// only. Never touches driver settlement, trip completion, or booking.
function network_payouts_suspended($pdo) {
    return (bool) network_setting($pdo, 'network_emergency_payout_suspended', false);
}
function network_category_enabled($pdo, $category) {
    // $category: 'driver' | 'passenger' | 'external'
    $key = [
        'driver' => 'network_driver_referrals_enabled',
        'passenger' => 'network_passenger_referrals_enabled',
        'external' => 'network_external_partners_enabled',
    ][$category] ?? null;
    return $key !== null && (bool) network_setting($pdo, $key, true);
}

// ---------------------------------------------------------------
// Referral code — NETWORK-{FIRSTNAME}{2-digit suffix}. Separate
// generator/column from the legacy users.referral_code (community
// rewards) so a historical link never silently starts resolving
// through the new programme or vice versa.
// ---------------------------------------------------------------
function generate_network_referral_code($pdo, $fullname) {
    $firstName = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', trim((string)$fullname))[0] ?? ''));
    if ($firstName === '') {
        $firstName = 'PARTNER';
    }
    $maxNameLen = 30 - strlen('NETWORK-') - 2;
    $firstName = substr($firstName, 0, $maxNameLen);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $candidate = "NETWORK-{$firstName}{$suffix}";
        $stmt = $pdo->prepare("SELECT 1 FROM network_partners WHERE referral_code = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not generate a unique network referral code after 20 attempts');
}

function get_network_partner($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT * FROM network_partners WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_network_partner_by_code($pdo, $code) {
    $stmt = $pdo->prepare("SELECT * FROM network_partners WHERE referral_code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function ensure_network_wallet($pdo, $userId) {
    $stmt = $pdo->prepare("INSERT INTO network_wallets (user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id");
    $stmt->execute([$userId]);
}

// ---------------------------------------------------------------
// Partner onboarding — voluntary opt-in. Existing driver/rider
// accounts are NEVER required to do this to keep using transport; a
// non-partner simply has no code/link/dashboard. Section 14's "accept
// current terms before activating participation" gate lives here.
// ---------------------------------------------------------------
function network_join($pdo, $userId, $termsVersion = null) {
    if (!network_registrations_enabled($pdo)) {
        return ['error' => 'MOHTOH NETWORK registrations are currently closed.'];
    }
    $stmt = $pdo->prepare("SELECT id, fullname, user_type FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return ['error' => 'Account not found.'];
    }
    $category = $user['user_type'] === 'driver' ? 'driver' : 'passenger';
    if (!network_category_enabled($pdo, $category)) {
        return ['error' => 'MOHTOH NETWORK is not currently open to your account type.'];
    }

    $existing = get_network_partner($pdo, $userId);
    if ($existing) {
        return $existing['status'] === 'suspended'
            ? ['error' => 'Your MOHTOH NETWORK participation is suspended.']
            : $existing;
    }

    $autoApprove = (bool) network_setting($pdo, 'network_partner_auto_approve', false);
    $code = generate_network_referral_code($pdo, $user['fullname']);
    $publicId = generate_unique_public_id($pdo, 'network_partners', 'public_id', 'NWP');
    $status = $autoApprove ? 'approved' : 'pending';

    $stmt = $pdo->prepare("
        INSERT INTO network_partners (public_id, user_id, referral_code, status, terms_accepted_at, terms_version, approved_at)
        VALUES (?, ?, ?, ?, NOW(), ?, " . ($autoApprove ? 'NOW()' : 'NULL') . ")
    ");
    $stmt->execute([$publicId, $userId, $code, $status, $termsVersion ?: network_setting($pdo, 'network_terms_version', '1.0')]);

    $pdo->prepare("UPDATE users SET is_network_partner = 1 WHERE id = ?")->execute([$userId]);
    ensure_network_wallet($pdo, $userId);

    create_notification($pdo, $userId, [
        'title' => $autoApprove ? 'Welcome to MOHTOH NETWORK' : 'MOHTOH NETWORK application received',
        'message' => $autoApprove
            ? "You're approved. Your referral code is {$code} — share the journey, create opportunity."
            : 'Your application is under review. We will notify you once approved.',
        'notification_type' => 'referral',
        'action' => 'open_network_dashboard',
    ]);

    return get_network_partner($pdo, $userId);
}

// ---------------------------------------------------------------
// Admin partner management (spec 8B) — approve/suspend never touches
// the person's ordinary rider/driver account, only their Network hat.
// ---------------------------------------------------------------
function network_admin_approve_partner($pdo, $adminId, $partnerId) {
    $stmt = $pdo->prepare("SELECT * FROM network_partners WHERE id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) {
        return ['error' => 'Partner not found.'];
    }
    $pdo->prepare("UPDATE network_partners SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$adminId, $partnerId]);
    log_admin_action($pdo, $adminId, 'network_partner_approved', 'network_partners', $partnerId, $partner['status'], 'approved');
    create_notification($pdo, $partner['user_id'], [
        'title' => 'Welcome to MOHTOH NETWORK',
        'message' => "You're approved. Your referral code is {$partner['referral_code']} — share the journey, create opportunity.",
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
    ]);
    return ['status' => 'approved'];
}

function network_admin_suspend_partner($pdo, $adminId, $partnerId, $reason) {
    $stmt = $pdo->prepare("SELECT * FROM network_partners WHERE id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) {
        return ['error' => 'Partner not found.'];
    }
    if (trim((string)$reason) === '') {
        return ['error' => 'A reason is required to suspend a Network Partner.'];
    }
    $pdo->prepare("UPDATE network_partners SET status = 'suspended', suspended_by = ?, suspended_at = NOW(), suspension_reason = ? WHERE id = ?")
        ->execute([$adminId, $reason, $partnerId]);
    log_admin_action($pdo, $adminId, 'network_partner_suspended', 'network_partners', $partnerId, $partner['status'], 'suspended', $reason);
    create_notification($pdo, $partner['user_id'], [
        'title' => 'MOHTOH NETWORK participation suspended',
        'message' => $reason,
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
    ]);
    return ['status' => 'suspended'];
}

function network_admin_reactivate_partner($pdo, $adminId, $partnerId) {
    $stmt = $pdo->prepare("SELECT * FROM network_partners WHERE id = ?");
    $stmt->execute([$partnerId]);
    $partner = $stmt->fetch();
    if (!$partner) {
        return ['error' => 'Partner not found.'];
    }
    $pdo->prepare("UPDATE network_partners SET status = 'approved', suspended_by = NULL, suspended_at = NULL, suspension_reason = NULL WHERE id = ?")->execute([$partnerId]);
    log_admin_action($pdo, $adminId, 'network_partner_reactivated', 'network_partners', $partnerId, $partner['status'], 'approved');
    create_notification($pdo, $partner['user_id'], [
        'title' => 'MOHTOH NETWORK participation reactivated',
        'message' => 'Your Network Partner account is active again.',
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
    ]);
    return ['status' => 'approved'];
}

// Documented administrative correction of referral attribution (spec
// 3's "resolve genuine referral disputes through an audited process").
// Reassigns which partner gets credit; never allowed onto a referral
// that's already past first-qualifying-ride (attribution_locked_at) —
// that would retroactively move a live reward stream to someone else.
function network_correct_attribution($pdo, $adminId, $networkReferralId, $newReferrerUserId, $reason) {
    if (trim((string)$reason) === '') {
        return ['error' => 'A reason is required to correct referral attribution.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM network_referrals WHERE id = ?");
    $stmt->execute([$networkReferralId]);
    $referral = $stmt->fetch();
    if (!$referral) {
        return ['error' => 'Referral not found.'];
    }
    if ($referral['attribution_locked_at']) {
        return ['error' => 'This referral is locked (past its first qualifying ride) and can no longer be reassigned.'];
    }
    if ((int)$newReferrerUserId === (int)$referral['referred_user_id']) {
        return ['error' => 'A user cannot be their own referrer.'];
    }
    $newPartner = get_network_partner($pdo, $newReferrerUserId);
    if (!$newPartner || $newPartner['status'] !== 'approved') {
        return ['error' => 'The new referrer must be an approved Network Partner.'];
    }

    $oldReferrerId = $referral['referrer_user_id'];
    $pdo->prepare("UPDATE network_referrals SET referrer_user_id = ?, corrected_by = ?, correction_reason = ? WHERE id = ?")
        ->execute([$newReferrerUserId, $adminId, $reason, $networkReferralId]);
    $pdo->prepare("UPDATE users SET referred_by = ? WHERE id = ?")->execute([$newReferrerUserId, $referral['referred_user_id']]);

    log_admin_action($pdo, $adminId, 'network_attribution_corrected', 'network_referrals', $networkReferralId, (string)$oldReferrerId, (string)$newReferrerUserId, $reason);

    return ['status' => 'corrected'];
}

// ---------------------------------------------------------------
// Attribution — called once, at registration, if a valid Network
// Partner referral code was supplied. Direct, one-level only.
// ---------------------------------------------------------------
function network_attribute_referral($pdo, $referrerUserId, $referredUserId, $referredCategory) {
    if (!network_links_enabled($pdo)) {
        return ['error' => 'Referral links are currently disabled.'];
    }
    if ((int)$referrerUserId === (int)$referredUserId) {
        return ['error' => 'You cannot refer yourself.'];
    }
    $referredCategory = strtolower($referredCategory) === 'driver' ? 'driver' : 'passenger';
    if (!network_category_enabled($pdo, $referredCategory)) {
        return ['error' => 'Referral rewards for this account type are currently disabled.'];
    }

    $partner = get_network_partner($pdo, $referrerUserId);
    if (!$partner || $partner['status'] !== 'approved') {
        return ['error' => 'This referral code is not currently active.'];
    }

    $stmt = $pdo->prepare("SELECT id, fullname, phone, email FROM users WHERE id = ?");
    $stmt->execute([$referrerUserId]);
    $referrer = $stmt->fetch();
    $stmt->execute([$referredUserId]);
    $referred = $stmt->fetch();
    if (!$referrer || !$referred) {
        return ['error' => 'Invalid referral.'];
    }
    // Fraud-lite: refuse to link two accounts that already share
    // contact details (same signal the legacy system used).
    if ($referrer['phone'] === $referred['phone'] || $referrer['email'] === $referred['email']) {
        network_flag_fraud($pdo, $referredUserId, 'shared_contact_at_referral', 'medium',
            'Referred user shares phone/email with referrer at registration.', null, null);
        return ['error' => 'This referral could not be linked.'];
    }

    $stmt = $pdo->prepare("SELECT id FROM network_referrals WHERE referred_user_id = ?");
    $stmt->execute([$referredUserId]);
    if ($stmt->fetchColumn()) {
        return ['error' => 'This account is already linked to a referral.'];
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $publicId = generate_unique_public_id($pdo, 'network_referrals', 'public_id', 'NREF');
    $stmt = $pdo->prepare("
        INSERT INTO network_referrals (public_id, referrer_user_id, referred_user_id, referred_category, status, registration_ip)
        VALUES (?, ?, ?, ?, 'registered', ?)
    ");
    $stmt->execute([$publicId, $referrerUserId, $referredUserId, $referredCategory, $ip]);
    $referralId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE users SET referred_by = ? WHERE id = ?")->execute([$referrerUserId, $referredUserId]);

    network_check_attribution_fraud($pdo, $referrerUserId, $referredUserId, (int)$referralId, $ip);

    create_notification($pdo, $referrerUserId, [
        'title' => 'Someone joined through your network link',
        'message' => ($referred['fullname'] ?? 'A new user') . ' registered using your MOHTOH NETWORK referral code.',
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        'related_table' => 'network_referrals', 'related_public_id' => $publicId,
    ]);

    return ['network_referral_id' => (int)$referralId, 'public_id' => $publicId];
}

// ---------------------------------------------------------------
// Fraud heuristics at attribution time (spec 9): "duplicate accounts /
// multiple accounts using the same identity", "suspicious device or
// account relationships". Never blocks the referral — only flags it
// for admin review, same principle as every other fraud check here.
// ---------------------------------------------------------------
function network_check_attribution_fraud($pdo, $referrerUserId, $referredUserId, $referralId, $ip) {
    // Velocity: an unusual number of NEW referrals from one partner in
    // a short window is the clearest sign of a referral farm.
    $maxPerDay = (int) network_setting($pdo, 'network_fraud_velocity_max_per_day', 10);
    if ($maxPerDay > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_referrals WHERE referrer_user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
        $stmt->execute([$referrerUserId]);
        $recentCount = (int) $stmt->fetchColumn();
        if ($recentCount > $maxPerDay) {
            network_flag_fraud($pdo, $referrerUserId, 'referral_velocity', 'medium',
                "{$recentCount} new referrals in the last 24 hours — above the configured threshold of {$maxPerDay}.", null, $referralId);
        }
    }

    // Multi-accounting: this SAME partner has already brought in another
    // "new" account from this exact IP — plausibly one person registering
    // several accounts to farm their own referral rewards.
    if ($ip) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM network_referrals WHERE referrer_user_id = ? AND registration_ip = ? AND referred_user_id != ?");
        $stmt->execute([$referrerUserId, $ip, $referredUserId]);
        if ((int) $stmt->fetchColumn() > 0) {
            // 'medium', not 'high': a shared IP among a partner's own
            // referrals is common and often entirely innocent (family
            // members, a shared office/campus network, carrier-grade
            // NAT) — it is a candidate worth an admin's look, never
            // proof of fraud on its own. Admins decide; this never
            // blocks the referral or the account.
            network_flag_fraud($pdo, $referrerUserId, 'shared_ip_multiple_referrals', 'medium',
                "Another account referred by this partner already registered from IP {$ip}. Shared IPs are common and not fraud on their own — review before acting.", null, $referralId);
        }
    }
}

// ---------------------------------------------------------------
// Lifecycle milestones — registration/verification/first-ride events
// that move a referral through registered -> verified -> active
// (earning window open). This is NOT where a reward is calculated;
// see network_settle_booking_reward() for the per-transaction engine.
// ---------------------------------------------------------------
function network_track_milestone($pdo, $referredUserId, $eventType, $meta = null) {
    $stmt = $pdo->prepare("SELECT * FROM network_referrals WHERE referred_user_id = ?");
    $stmt->execute([$referredUserId]);
    $referral = $stmt->fetch();
    if (!$referral || in_array($referral['status'], ['cancelled', 'legacy_migrated'], true)) {
        return; // no-op for anyone not under an active referral relationship
    }

    if ($eventType === 'PHONE_VERIFIED') {
        $pdo->prepare("UPDATE network_referrals SET phone_verified_at = NOW() WHERE id = ?")->execute([$referral['id']]);
        $referral['phone_verified_at'] = $referral['phone_verified_at'] ?: date('Y-m-d H:i:s');
    } elseif ($eventType === 'EMAIL_VERIFIED') {
        $pdo->prepare("UPDATE network_referrals SET email_verified_at = NOW() WHERE id = ?")->execute([$referral['id']]);
        $referral['email_verified_at'] = $referral['email_verified_at'] ?: date('Y-m-d H:i:s');
    }

    if ($referral['status'] === 'registered' && $referral['phone_verified_at'] && $referral['email_verified_at']) {
        $pdo->prepare("UPDATE network_referrals SET status = 'verified', verified_at = NOW() WHERE id = ?")->execute([$referral['id']]);
        create_notification($pdo, $referral['referrer_user_id'], [
            'title' => 'Referral verified',
            'message' => 'Your invited connection verified their MohToh account.',
            'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        ]);
    }

    // FIRST_TRIP_COMPLETED opens the earning window exactly once —
    // every ride before and after this only ever matters to the reward
    // engine (network_settle_booking_reward), never to this function.
    if ($eventType === 'FIRST_TRIP_COMPLETED' && in_array($referral['status'], ['registered', 'verified'], true)) {
        $earningDays = (int) network_setting($pdo, 'network_earning_days', 90);
        $pdo->prepare("
            UPDATE network_referrals
            SET status = 'active', first_qualifying_ride_at = NOW(),
                earning_starts_at = NOW(), earning_expires_at = DATE_ADD(NOW(), INTERVAL ? DAY),
                attribution_locked_at = NOW()
            WHERE id = ?
        ")->execute([$earningDays, $referral['id']]);
        create_notification($pdo, $referral['referrer_user_id'], [
            'title' => 'Your referral is now earning',
            'message' => 'Your invited connection completed their first qualifying ride — you will now earn from their rides for the next ' . $earningDays . ' days.',
            'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        ]);
    }
    // FIRST_RIDE_CREATED / DRIVER_VERIFIED: activity signals only, kept
    // for symmetry with the call sites that already fire them (no
    // column to update, nothing to reward on their own in this model).
}

// ---------------------------------------------------------------
// Reward engine — the ONLY place a MOHTOH NETWORK reward is computed.
// Called once per settled, paid booking. Idempotent via the ledger's
// UNIQUE(booking_id, beneficiary_user_id, beneficiary_role): calling
// this twice for the same booking (duplicate webhook/settlement call)
// never creates a duplicate reward. Fails safe: any exception here is
// swallowed by the caller (see integration points), never the booking.
// ---------------------------------------------------------------
function network_settle_booking_reward($pdo, $bookingId) {
    if (!network_rewards_enabled($pdo)) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT b.id AS booking_id, b.status AS booking_status,
               p.commission_amount, p.status AS payment_status, p.driver_id, p.rider_id
        FROM ride_share_bookings b
        JOIN payments p ON p.booking_id = b.id
        WHERE b.id = ?
    ");
    $stmt->execute([$bookingId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }
    if ($row['booking_status'] !== 'completed' || $row['payment_status'] !== 'PAYMENT_CONFIRMED') {
        return; // not a qualifying, settled transaction yet
    }
    $commission = round((float)$row['commission_amount'], 2);
    if ($commission <= 0) {
        return; // nothing eligible to share
    }

    $roles = [
        'driver_referrer'    => ['userId' => (int)$row['driver_id'], 'pctKey' => 'network_driver_reward_pct'],
        'passenger_referrer' => ['userId' => (int)$row['rider_id'],  'pctKey' => 'network_passenger_reward_pct'],
    ];

    $candidates = []; // role => ['referral'=>.., 'beneficiary'=>.., 'pct'=>.., 'amount'=>..]
    foreach ($roles as $role => $def) {
        $referral = network_resolve_active_referral($pdo, $def['userId']);
        if (!$referral) {
            continue;
        }
        if (!network_category_enabled($pdo, $referral['referred_category'])) {
            continue;
        }
        $pct = network_reward_rate_for($pdo, $role, $referral);
        if ($pct <= 0) {
            continue;
        }
        $amount = round($commission * $pct / 100, 2);
        if ($amount <= 0) {
            continue;
        }
        $candidates[$role] = ['referral' => $referral, 'beneficiary' => (int)$referral['referrer_user_id'], 'pct' => $pct, 'amount' => $amount];
    }
    if (!$candidates) {
        return;
    }

    // Fraud (spec 9): "coordinated driver-passenger transactions" — the
    // clearest version of this is the SAME partner being the direct
    // referrer of BOTH sides of one booking, i.e. paying themselves
    // twice from a single ride. Flagged, never blocked — a genuine
    // partner who happens to know both people is still allowed to earn.
    if (isset($candidates['driver_referrer'], $candidates['passenger_referrer'])
        && $candidates['driver_referrer']['beneficiary'] === $candidates['passenger_referrer']['beneficiary']) {
        network_flag_fraud($pdo, $candidates['driver_referrer']['beneficiary'], 'coordinated_both_sides', 'high',
            "Booking #{$bookingId}: the same partner directly referred both the driver and the passenger.",
            null, $candidates['driver_referrer']['referral']['id']);
    }

    // Combined payout ceiling (spec 4B/5): scale BOTH rewards down
    // proportionally if their sum exceeds the configured % of this
    // booking's eligible commission.
    $ceilingPct = (float) network_setting($pdo, 'network_combined_ceiling_pct', 25);
    $ceilingAmount = round($commission * $ceilingPct / 100, 2);
    $sum = array_sum(array_column($candidates, 'amount'));
    if ($ceilingAmount > 0 && $sum > $ceilingAmount) {
        $scale = $ceilingAmount / $sum;
        foreach ($candidates as $role => $c) {
            $candidates[$role]['amount'] = round($c['amount'] * $scale, 2);
        }
    }

    $maxPerTxn = (float) network_setting($pdo, 'network_max_combined_payout_per_txn', 0);
    if ($maxPerTxn > 0) {
        $sum = array_sum(array_column($candidates, 'amount'));
        if ($sum > $maxPerTxn) {
            $scale = $maxPerTxn / $sum;
            foreach ($candidates as $role => $c) {
                $candidates[$role]['amount'] = round($c['amount'] * $scale, 2);
            }
        }
    }

    $holdDays = (int) network_setting($pdo, 'network_reward_hold_days', 3);

    foreach ($candidates as $role => $c) {
        if ($c['amount'] <= 0) {
            continue;
        }
        if (!network_check_budget_available($pdo, $c['amount'])) {
            network_flag_fraud($pdo, $c['beneficiary'], 'budget_exceeded', 'low',
                "Reward of ₦{$c['amount']} for booking #{$bookingId} skipped — configured reward budget exceeded.", null, $c['referral']['id']);
            continue;
        }

        $publicId = generate_unique_public_id($pdo, 'network_reward_ledger', 'public_id', 'NRWD');
        try {
            $stmt = $pdo->prepare("
                INSERT INTO network_reward_ledger
                    (public_id, network_referral_id, booking_id, beneficiary_user_id, beneficiary_role,
                     commission_base, reward_pct, reward_amount, status, hold_until)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? DAY))
            ");
            $stmt->execute([$publicId, $c['referral']['id'], $bookingId, $c['beneficiary'], $role, $commission, $c['pct'], $c['amount'], $holdDays]);
        } catch (PDOException $e) {
            if ((int)$e->errorInfo[1] === 1062) {
                continue; // duplicate settlement call for this booking/role — already rewarded
            }
            throw $e;
        }

        ensure_network_wallet($pdo, $c['beneficiary']);
        $pdo->prepare("UPDATE network_wallets SET pending_balance = pending_balance + ? WHERE user_id = ?")->execute([$c['amount'], $c['beneficiary']]);
        $pdo->prepare("
            UPDATE network_referrals
            SET qualifying_ride_count = qualifying_ride_count + 1,
                total_commission_generated = total_commission_generated + ?,
                total_rewards_generated = total_rewards_generated + ?
            WHERE id = ?
        ")->execute([$commission, $c['amount'], $c['referral']['id']]);

        create_notification($pdo, $c['beneficiary'], [
            'title' => 'MOHTOH NETWORK reward earned',
            'message' => '₦' . number_format($c['amount'], 2) . ' earned from a ride completed by someone in your network. Available after a ' . $holdDays . '-day verification hold.',
            'notification_type' => 'referral', 'action' => 'open_network_dashboard',
            'related_table' => 'network_reward_ledger', 'related_public_id' => $publicId,
        ]);

        network_check_repeated_pair_fraud($pdo, $c['beneficiary'], (int)$row['driver_id'], (int)$row['rider_id'], (int)$c['referral']['id']);
    }

    network_release_matured_rewards($pdo);
}

// Fraud (spec 9): "artificial booking activity" / "unusual transaction
// patterns" — the same driver and rider pair generating an outsized
// share of one beneficiary's rewards suggests two accounts ping-ponging
// fake rides to farm a single referral relationship. Flag only; never
// reverses a reward on its own.
function network_check_repeated_pair_fraud($pdo, $beneficiaryUserId, $driverId, $riderId, $networkReferralId) {
    $maxPair = (int) network_setting($pdo, 'network_fraud_same_pair_max_rewards', 5);
    if ($maxPair <= 0) {
        return;
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT l.booking_id)
        FROM network_reward_ledger l
        JOIN ride_share_bookings b ON b.id = l.booking_id
        JOIN payments p ON p.booking_id = b.id
        WHERE l.beneficiary_user_id = ? AND p.driver_id = ? AND p.rider_id = ? AND l.status != 'reversed'
    ");
    $stmt->execute([$beneficiaryUserId, $driverId, $riderId]);
    $pairCount = (int) $stmt->fetchColumn();
    if ($pairCount > $maxPair) {
        network_flag_fraud($pdo, $beneficiaryUserId, 'repeated_pair_rewards', 'medium',
            "{$pairCount} rewarded bookings between the same driver/rider pair for this beneficiary — above the configured threshold of {$maxPair}.",
            null, $networkReferralId);
    }
}

// Active, non-expired earning window for a REFERRED user. Lazily
// expires a stale 'active' referral on read rather than needing a cron.
function network_resolve_active_referral($pdo, $referredUserId) {
    $stmt = $pdo->prepare("SELECT * FROM network_referrals WHERE referred_user_id = ? AND status = 'active'");
    $stmt->execute([$referredUserId]);
    $referral = $stmt->fetch();
    if (!$referral) {
        return null;
    }
    if ($referral['earning_expires_at'] && strtotime($referral['earning_expires_at']) < time()) {
        $pdo->prepare("UPDATE network_referrals SET status = 'expired' WHERE id = ?")->execute([$referral['id']]);
        create_notification($pdo, $referral['referrer_user_id'], [
            'title' => 'Referral earning window ended',
            'message' => 'Your earning window for this referral has expired. No further rewards will accrue from this relationship.',
            'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        ]);
        return null;
    }
    return $referral;
}

function network_reward_rate_for($pdo, $role, $referral) {
    $defaultKey = $role === 'driver_referrer' ? 'network_driver_reward_pct' : 'network_passenger_reward_pct';
    $default = (float) network_setting($pdo, $defaultKey, $role === 'driver_referrer' ? 15 : 10);

    if (empty($referral['campaign_id'])) {
        return $default;
    }
    $stmt = $pdo->prepare("SELECT * FROM network_campaigns WHERE id = ? AND status IN ('scheduled','active') AND NOW() BETWEEN starts_at AND ends_at");
    $stmt->execute([$referral['campaign_id']]);
    $campaign = $stmt->fetch();
    if (!$campaign) {
        return $default;
    }
    $campaignPct = $role === 'driver_referrer' ? $campaign['driver_reward_pct'] : $campaign['passenger_reward_pct'];
    return $campaignPct !== null ? (float)$campaignPct : $default;
}

// Total/daily/monthly reward budgets (spec 4D). 0 = unlimited. Checks
// against everything NOT reversed, i.e. real outstanding liability.
function network_check_budget_available($pdo, $amount) {
    $checks = [
        'network_total_budget'   => "1=1",
        'network_daily_budget'   => "DATE(created_at) = CURDATE()",
        'network_monthly_budget' => "YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())",
    ];
    foreach ($checks as $settingKey => $whereClause) {
        $budget = (float) network_setting($pdo, $settingKey, 0);
        if ($budget <= 0) {
            continue;
        }
        $stmt = $pdo->query("SELECT COALESCE(SUM(reward_amount), 0) FROM network_reward_ledger WHERE status != 'reversed' AND $whereClause");
        $spent = (float) $stmt->fetchColumn();
        if ($spent + $amount > $budget) {
            return false;
        }
    }
    return true;
}

// Moves matured pending rewards into available. No cron exists in this
// app (polling-based, see MOHTOH_SYSTEM_ANALYSIS.md) — called
// opportunistically from the reward engine and from withdrawal requests
// so balances stay accurate without needing a scheduled task yet.
function network_release_matured_rewards($pdo, $limit = 200) {
    $stmt = $pdo->prepare("SELECT * FROM network_reward_ledger WHERE status = 'pending' AND hold_until <= NOW() LIMIT ?");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        $update = $pdo->prepare("UPDATE network_reward_ledger SET status = 'available', available_at = NOW() WHERE id = ? AND status = 'pending'");
        $update->execute([$row['id']]);
        // The WHERE clause above is a compare-and-swap: MySQL re-checks
        // `status = 'pending'` against the current row at execution time
        // (not this function's earlier, un-locked SELECT), so only ONE
        // concurrent caller's UPDATE actually matches a row even if two
        // callers raced on the same SELECT. Only THAT caller may move the
        // matching wallet balance — otherwise two near-simultaneous calls
        // (this runs from both the reward engine and withdrawal requests)
        // would double-credit available_balance for one ledger row.
        if ($update->rowCount() === 0) {
            continue;
        }
        $pdo->prepare("UPDATE network_wallets SET pending_balance = pending_balance - ?, available_balance = available_balance + ? WHERE user_id = ?")
            ->execute([$row['reward_amount'], $row['reward_amount'], $row['beneficiary_user_id']]);
    }
}

// ---------------------------------------------------------------
// Reversal — a cancelled/refunded booking must claw back any reward it
// generated. Currently has NO live caller: this app's booking state
// machine never lets a 'completed' booking (the only kind that can have
// generated a reward) move back to 'cancelled' — every existing cancel/
// refund path (api/rideshare.php's cancel_booking, the driver/admin
// cancel action, the auto-expire sweep) is guarded by
// `status NOT IN ('cancelled','completed')`. Kept ready, tested, and
// race-safe for whatever post-completion dispute/refund flow this app
// adds next. Wrap the call in the caller's own transaction (same
// requirement credit_wallet()/debit_wallet() document) if/when that
// flow is built, so this function's compare-and-swap UPDATEs are
// atomic alongside whatever else that refund does.
// ---------------------------------------------------------------
function network_reverse_booking_rewards($pdo, $bookingId, $reason) {
    $stmt = $pdo->prepare("SELECT * FROM network_reward_ledger WHERE booking_id = ? AND status NOT IN ('reversed')");
    $stmt->execute([$bookingId]);
    foreach ($stmt->fetchAll() as $row) {
        $update = $pdo->prepare("UPDATE network_reward_ledger SET status = 'reversed', reversed_at = NOW(), reversal_reason = ? WHERE id = ? AND status != 'reversed'");
        $update->execute([$reason, $row['id']]);
        if ($update->rowCount() === 0) {
            continue; // already reversed by a concurrent call — never double-adjust the wallet
        }

        $column = $row['status'] === 'available' ? 'available_balance' : 'pending_balance';
        $pdo->prepare("UPDATE network_wallets SET `$column` = `$column` - ?, reversed_balance = reversed_balance + ? WHERE user_id = ?")
            ->execute([$row['reward_amount'], $row['reward_amount'], $row['beneficiary_user_id']]);

        $pdo->prepare("UPDATE network_referrals SET total_rewards_generated = GREATEST(0, total_rewards_generated - ?) WHERE id = ?")
            ->execute([$row['reward_amount'], $row['network_referral_id']]);

        $publicId = generate_unique_public_id($pdo, 'network_reward_adjustments', 'public_id', 'NADJ');
        $pdo->prepare("INSERT INTO network_reward_adjustments (public_id, ledger_id, user_id, admin_id, adjustment_type, amount, reason) VALUES (?, ?, ?, NULL, 'reverse', ?, ?)")
            ->execute([$publicId, $row['id'], $row['beneficiary_user_id'], $row['reward_amount'], $reason]);

        create_notification($pdo, $row['beneficiary_user_id'], [
            'title' => 'Reward reversed',
            'message' => '₦' . number_format($row['reward_amount'], 2) . ' MOHTOH NETWORK reward was reversed: ' . $reason,
            'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        ]);
    }
}

// Admin-initiated reversal of ONE specific ledger row (distinct from
// network_reverse_booking_rewards(), which is the system path that
// reverses EVERY reward tied to a booking on a refund/cancellation).
// Used by the admin Reward Management screen for a targeted correction.
function network_reverse_ledger_row($pdo, $adminId, $ledgerId, $reason) {
    $stmt = $pdo->prepare("SELECT * FROM network_reward_ledger WHERE id = ? AND status != 'reversed' FOR UPDATE");
    $stmt->execute([$ledgerId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['error' => 'Reward not found, or already reversed.'];
    }

    $pdo->prepare("UPDATE network_reward_ledger SET status = 'reversed', reversed_at = NOW(), reversal_reason = ? WHERE id = ?")
        ->execute([$reason, $ledgerId]);

    $column = $row['status'] === 'available' ? 'available_balance' : 'pending_balance';
    $pdo->prepare("UPDATE network_wallets SET `$column` = `$column` - ?, reversed_balance = reversed_balance + ? WHERE user_id = ?")
        ->execute([$row['reward_amount'], $row['reward_amount'], $row['beneficiary_user_id']]);

    $pdo->prepare("UPDATE network_referrals SET total_rewards_generated = GREATEST(0, total_rewards_generated - ?) WHERE id = ?")
        ->execute([$row['reward_amount'], $row['network_referral_id']]);

    $publicId = generate_unique_public_id($pdo, 'network_reward_adjustments', 'public_id', 'NADJ');
    $pdo->prepare("INSERT INTO network_reward_adjustments (public_id, ledger_id, user_id, admin_id, adjustment_type, amount, reason) VALUES (?, ?, ?, ?, 'reverse', ?, ?)")
        ->execute([$publicId, $ledgerId, $row['beneficiary_user_id'], $adminId, $row['reward_amount'], $reason]);

    log_admin_action($pdo, $adminId, 'network_reward_reversed', 'network_reward_ledger', $ledgerId, $row['status'], 'reversed', $reason);

    create_notification($pdo, $row['beneficiary_user_id'], [
        'title' => 'Reward reversed',
        'message' => '₦' . number_format($row['reward_amount'], 2) . ' MOHTOH NETWORK reward was reversed: ' . $reason,
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
    ]);

    return ['public_id' => $publicId];
}

// ---------------------------------------------------------------
// Withdrawals
// ---------------------------------------------------------------
function network_add_withdrawal_account($pdo, $userId, $bankName, $accountNumber, $accountName, $makeDefault = true) {
    $accountNumber = preg_replace('/\s+/', '', (string)$accountNumber);
    $publicId = generate_unique_public_id($pdo, 'network_withdrawal_accounts', 'public_id', 'NWA');
    if ($makeDefault) {
        $pdo->prepare("UPDATE network_withdrawal_accounts SET is_default = 0 WHERE user_id = ?")->execute([$userId]);
    }
    $stmt = $pdo->prepare("
        INSERT INTO network_withdrawal_accounts (public_id, user_id, bank_name, account_number_encrypted, account_number_last4, account_name, is_default)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$publicId, $userId, trim($bankName), encrypt_secret($accountNumber), substr($accountNumber, -4), trim($accountName), $makeDefault ? 1 : 0]);
    return (int) $pdo->lastInsertId();
}

function network_request_withdrawal($pdo, $userId, $withdrawalAccountId, $amount) {
    if (!network_withdrawals_enabled($pdo)) {
        return ['error' => 'Withdrawals are currently disabled.'];
    }
    $amount = round((float)$amount, 2);
    $minWithdrawal = (float) network_setting($pdo, 'network_min_withdrawal', 2000);
    if ($amount < $minWithdrawal) {
        return ['error' => 'Minimum withdrawal is ₦' . number_format($minWithdrawal, 2) . '.'];
    }

    $stmt = $pdo->prepare("SELECT id FROM network_withdrawal_accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$withdrawalAccountId, $userId]);
    if (!$stmt->fetchColumn()) {
        return ['error' => 'Withdrawal account not found.'];
    }

    network_release_matured_rewards($pdo);

    $stmt = $pdo->prepare("SELECT available_balance FROM network_wallets WHERE user_id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $available = (float) $stmt->fetchColumn();
    if ($available < $amount) {
        return ['error' => 'Insufficient available balance.'];
    }

    $dailyMax = (float) network_setting($pdo, 'network_max_withdrawal_per_day', 0);
    $monthlyMax = (float) network_setting($pdo, 'network_max_withdrawal_per_month', 0);
    if ($dailyMax > 0) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM network_withdrawals WHERE user_id = ? AND DATE(requested_at) = CURDATE() AND status != 'rejected'");
        $stmt->execute([$userId]);
        if ((float)$stmt->fetchColumn() + $amount > $dailyMax) {
            return ['error' => 'Daily withdrawal limit reached.'];
        }
    }
    if ($monthlyMax > 0) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM network_withdrawals WHERE user_id = ? AND YEAR(requested_at)=YEAR(CURDATE()) AND MONTH(requested_at)=MONTH(CURDATE()) AND status != 'rejected'");
        $stmt->execute([$userId]);
        if ((float)$stmt->fetchColumn() + $amount > $monthlyMax) {
            return ['error' => 'Monthly withdrawal limit reached.'];
        }
    }

    $pdo->prepare("UPDATE network_wallets SET available_balance = available_balance - ? WHERE user_id = ?")->execute([$amount, $userId]);

    $publicId = generate_unique_public_id($pdo, 'network_withdrawals', 'public_id', 'NWD');
    $stmt = $pdo->prepare("INSERT INTO network_withdrawals (public_id, user_id, withdrawal_account_id, amount, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$publicId, $userId, $withdrawalAccountId, $amount]);
    // Captured immediately — create_notification() below does its own
    // INSERT (into `notifications`) and would otherwise clobber this.
    $withdrawalId = (int) $pdo->lastInsertId();

    create_notification($pdo, $userId, [
        'title' => 'Withdrawal requested',
        'message' => '₦' . number_format($amount, 2) . ' withdrawal request submitted and pending review.',
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        'related_table' => 'network_withdrawals', 'related_public_id' => $publicId,
    ]);

    return ['withdrawal_id' => $withdrawalId, 'public_id' => $publicId];
}

// $decision: 'approve' | 'reject' | 'processing' | 'paid' | 'failed'.
// Never marks 'paid' without a $paymentReference (verified confirmation).
function network_admin_decide_withdrawal($pdo, $adminId, $withdrawalId, $decision, $reasonOrReference = null) {
    $stmt = $pdo->prepare("SELECT * FROM network_withdrawals WHERE id = ? FOR UPDATE");
    $stmt->execute([$withdrawalId]);
    $withdrawal = $stmt->fetch();
    if (!$withdrawal) {
        return ['error' => 'Withdrawal not found.'];
    }

    $map = ['approve' => 'approved', 'reject' => 'rejected', 'processing' => 'processing', 'paid' => 'paid', 'failed' => 'failed'];
    if (!isset($map[$decision])) {
        return ['error' => 'Invalid decision.'];
    }
    if ($decision === 'paid' && (!$reasonOrReference || trim((string)$reasonOrReference) === '')) {
        return ['error' => 'A payment reference is required to mark a withdrawal as paid.'];
    }
    // State-machine guard: without this, re-submitting a decision (a
    // double-click, a retried request, or two admins acting on the same
    // withdrawal) would re-apply its balance effect every time — e.g.
    // marking an already-PAID withdrawal "paid" again double-credits
    // withdrawn_balance, or "reject"-ing one that's already paid would
    // fabricate available_balance for money that already left the
    // platform. Every decision is only valid from specific prior states.
    $validFrom = [
        'approve' => ['pending', 'under_review'],
        'reject' => ['pending', 'under_review'],
        'processing' => ['approved'],
        'paid' => ['approved', 'processing'],
        'failed' => ['approved', 'processing'],
    ];
    if (!in_array($withdrawal['status'], $validFrom[$decision], true)) {
        return ['error' => "Cannot {$decision} a withdrawal that is currently '{$withdrawal['status']}'."];
    }

    $newStatus = $map[$decision];
    $restoresFunds = in_array($decision, ['reject', 'failed'], true);

    if ($restoresFunds) {
        $pdo->prepare("UPDATE network_wallets SET available_balance = available_balance + ? WHERE user_id = ?")
            ->execute([$withdrawal['amount'], $withdrawal['user_id']]);
    } elseif ($decision === 'paid') {
        $pdo->prepare("UPDATE network_wallets SET withdrawn_balance = withdrawn_balance + ? WHERE user_id = ?")
            ->execute([$withdrawal['amount'], $withdrawal['user_id']]);
    }

    $stmt = $pdo->prepare("
        UPDATE network_withdrawals
        SET status = ?, reviewed_by = COALESCE(reviewed_by, ?), reviewed_at = COALESCE(reviewed_at, NOW()),
            payment_reference = CASE WHEN ? = 'paid' THEN ? ELSE payment_reference END,
            rejection_reason = CASE WHEN ? IN ('reject','failed') THEN ? ELSE rejection_reason END,
            processed_by = CASE WHEN ? IN ('paid','processing') THEN ? ELSE processed_by END,
            processed_at = CASE WHEN ? IN ('paid','processing') THEN NOW() ELSE processed_at END
        WHERE id = ?
    ");
    $stmt->execute([$newStatus, $adminId, $decision, $reasonOrReference, $decision, $reasonOrReference, $decision, $adminId, $decision, $withdrawalId]);

    log_admin_action($pdo, $adminId, 'network_withdrawal_' . $decision, 'network_withdrawals', $withdrawalId, $withdrawal['status'], $newStatus, is_string($reasonOrReference) ? $reasonOrReference : null);

    create_notification($pdo, $withdrawal['user_id'], [
        'title' => 'Withdrawal update',
        'message' => 'Your ₦' . number_format($withdrawal['amount'], 2) . ' withdrawal is now: ' . str_replace('_', ' ', $newStatus) . '.',
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
        'related_table' => 'network_withdrawals', 'related_public_id' => $withdrawal['public_id'],
    ]);

    return ['status' => $newStatus];
}

// ---------------------------------------------------------------
// Manual admin adjustments (spec 4D / 8D) — every one is audited.
// ---------------------------------------------------------------
function network_adjust_wallet($pdo, $adminId, $userId, $amount, $type, $reason, $ledgerId = null) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0 || trim((string)$reason) === '') {
        return ['error' => 'An amount and reason are required.'];
    }
    ensure_network_wallet($pdo, $userId);

    if ($type === 'credit') {
        $pdo->prepare("UPDATE network_wallets SET available_balance = available_balance + ? WHERE user_id = ?")->execute([$amount, $userId]);
    } elseif ($type === 'debit') {
        $pdo->prepare("UPDATE network_wallets SET available_balance = GREATEST(0, available_balance - ?) WHERE user_id = ?")->execute([$amount, $userId]);
    } else {
        return ['error' => 'Invalid adjustment type.'];
    }

    $publicId = generate_unique_public_id($pdo, 'network_reward_adjustments', 'public_id', 'NADJ');
    $pdo->prepare("INSERT INTO network_reward_adjustments (public_id, ledger_id, user_id, admin_id, adjustment_type, amount, reason) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$publicId, $ledgerId, $userId, $adminId, $type, $amount, $reason]);

    log_admin_action($pdo, $adminId, 'network_manual_adjustment', 'network_wallets', $userId, null, $type, $reason);

    create_notification($pdo, $userId, [
        'title' => 'MOHTOH NETWORK balance adjusted',
        'message' => ucfirst($type) . ' of ₦' . number_format($amount, 2) . ': ' . $reason,
        'notification_type' => 'referral', 'action' => 'open_network_dashboard',
    ]);

    return ['public_id' => $publicId];
}

// ---------------------------------------------------------------
// Fraud (spec 9) — flags only; never auto-confiscates. $raisedByAdminId
// null means system-raised.
// ---------------------------------------------------------------
// Dedupes against an already-OPEN flag of the same type for the same
// user: an ongoing pattern (e.g. a partner referring 20 people in a row
// past the velocity threshold) must read as ONE open case for an admin
// to review, not one flag per occurrence. Once an admin resolves or
// dismisses it, the next genuine occurrence raises a fresh flag. The
// existing flag's `details` is refreshed to the latest occurrence so
// an admin reviewing it still sees current, specific evidence.
function network_flag_fraud($pdo, $userId, $flagType, $severity, $details, $raisedByAdminId = null, $networkReferralId = null) {
    $stmt = $pdo->prepare("SELECT id, public_id FROM network_fraud_flags WHERE user_id = ? AND flag_type = ? AND status IN ('open', 'reviewing') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId, $flagType]);
    $existing = $stmt->fetch();
    if ($existing) {
        $pdo->prepare("UPDATE network_fraud_flags SET details = ?, severity = ? WHERE id = ?")
            ->execute([$details, $severity, $existing['id']]);
        return $existing['public_id'];
    }

    $publicId = generate_unique_public_id($pdo, 'network_fraud_flags', 'public_id', 'NFLAG');
    $stmt = $pdo->prepare("
        INSERT INTO network_fraud_flags (public_id, user_id, network_referral_id, flag_type, severity, details, raised_by_admin_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$publicId, $userId, $networkReferralId, $flagType, $severity, $details, $raisedByAdminId]);
    return $publicId;
}

function network_resolve_fraud_flag($pdo, $adminId, $flagId, $status, $notes) {
    if (!in_array($status, ['resolved', 'dismissed', 'reviewing'], true)) {
        return ['error' => 'Invalid status.'];
    }
    $stmt = $pdo->prepare("
        UPDATE network_fraud_flags
        SET status = ?, resolved_by = CASE WHEN ? IN ('resolved','dismissed') THEN ? ELSE resolved_by END,
            resolved_at = CASE WHEN ? IN ('resolved','dismissed') THEN NOW() ELSE resolved_at END,
            resolution_notes = ?
        WHERE id = ?
    ");
    $stmt->execute([$status, $status, $adminId, $status, $notes, $flagId]);
    log_admin_action($pdo, $adminId, 'network_fraud_flag_' . $status, 'network_fraud_flags', $flagId, null, $status, $notes);
    return ['status' => $status];
}

// ---------------------------------------------------------------
// Read models — power the Network Partner dashboard (Phase 4) and
// admin reporting (Phase 3). Direct referrals only, same guarantee as
// the write side: never recurses into a referrer's own referred_by.
// ---------------------------------------------------------------
function get_network_partner_stats($pdo, $userId) {
    network_release_matured_rewards($pdo);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_referrals,
               SUM(referred_category = 'driver') AS drivers_referred,
               SUM(referred_category = 'passenger') AS passengers_referred,
               SUM(status = 'active') AS active_referrals,
               SUM(status = 'expired') AS expired_referrals,
               SUM(first_qualifying_ride_at IS NOT NULL) AS converted_first_ride,
               SUM(total_rewards_generated) AS lifetime_rewards
        FROM network_referrals WHERE referrer_user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch() ?: [];

    $stmt = $pdo->prepare("SELECT pending_balance, available_balance, withdrawn_balance, reversed_balance, suspended_balance FROM network_wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $wallet = $stmt->fetch() ?: ['pending_balance' => 0, 'available_balance' => 0, 'withdrawn_balance' => 0, 'reversed_balance' => 0, 'suspended_balance' => 0];

    return [
        'total_referrals' => (int) ($stats['total_referrals'] ?? 0),
        'drivers_referred' => (int) ($stats['drivers_referred'] ?? 0),
        'passengers_referred' => (int) ($stats['passengers_referred'] ?? 0),
        'active_referrals' => (int) ($stats['active_referrals'] ?? 0),
        'expired_referrals' => (int) ($stats['expired_referrals'] ?? 0),
        'converted_first_ride' => (int) ($stats['converted_first_ride'] ?? 0),
        'lifetime_rewards' => round((float) ($stats['lifetime_rewards'] ?? 0), 2),
        'pending_balance' => round((float) $wallet['pending_balance'], 2),
        'available_balance' => round((float) $wallet['available_balance'], 2),
        'withdrawn_balance' => round((float) $wallet['withdrawn_balance'], 2),
        'reversed_balance' => round((float) $wallet['reversed_balance'], 2),
        'suspended_balance' => round((float) $wallet['suspended_balance'], 2),
    ];
}

function get_network_partner_referrals($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT r.public_id, r.referred_category, r.status, r.registered_at, r.first_qualifying_ride_at,
               r.earning_starts_at, r.earning_expires_at, r.qualifying_ride_count, r.total_rewards_generated,
               u.fullname, u.user_type
        FROM network_referrals r
        JOIN users u ON u.id = r.referred_user_id
        WHERE r.referrer_user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
