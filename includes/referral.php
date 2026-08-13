<?php
// includes/referral.php
// MohToh Community Rewards — the referral growth engine. Every business
// rule lives here; callers (api/auth.php, includes/verification.php,
// api/rideshare.php, api/admin-api.php) only call track_referral_event()
// or create_referral_relationship() at the exact moment a real thing
// happens — no poller, no cron, no client-supplied qualification flag.
//
// Reuses, never duplicates: credit_wallet() is the only function here
// that touches a wallet balance; create_notification() is the only
// notification writer; get_system_setting()/system_settings is the
// same admin-config store api/pricing-admin.php already uses.

// ---------------------------------------------------------------
// Referral code generation — MOHTOH-{FIRSTNAME}{2-digit suffix},
// e.g. MOHTOH-SAMUEL25. Retried on collision, same shape as
// generate_unique_public_id()'s retry loop.
// ---------------------------------------------------------------
function generate_referral_code($pdo, $fullname) {
    $firstName = strtoupper(preg_replace('/[^A-Za-z]/', '', explode(' ', trim((string)$fullname))[0] ?? ''));
    if ($firstName === '') {
        $firstName = 'MEMBER';
    }
    $maxNameLen = 30 - strlen('MOHTOH-') - 2; // fit VARCHAR(30) with room for the 2-digit suffix
    $firstName = substr($firstName, 0, $maxNameLen);

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $suffix = str_pad((string)random_int(0, 99), 2, '0', STR_PAD_LEFT);
        $candidate = "MOHTOH-{$firstName}{$suffix}";
        $stmt = $pdo->prepare("SELECT 1 FROM users WHERE referral_code = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }
    throw new RuntimeException('Could not generate a unique referral code after 20 attempts');
}

// Every user gets a code on first read, not just at registration — this
// is what lets pre-existing accounts (and the migration's backfill) get
// one without a separate one-off script.
function get_or_create_referral_code($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT referral_code, fullname FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }
    if ($user['referral_code']) {
        return $user['referral_code'];
    }
    $code = generate_referral_code($pdo, $user['fullname']);
    $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $userId]);
    return $code;
}

// ---------------------------------------------------------------
// Admin configuration — every amount/limit is read through here,
// never hardcoded. Mirrors get_system_setting()'s existing role for
// the Pricing Center / Notification Engine.
// ---------------------------------------------------------------
function referral_system_enabled($pdo) {
    return (bool) get_system_setting($pdo, 'referral_system_enabled', true);
}

// ---------------------------------------------------------------
// Relationship creation — called once, at registration, if a valid
// referral code was supplied.
// ---------------------------------------------------------------
function create_referral_relationship($pdo, $referrerUserId, $referredUserId, $referralType) {
    if (!referral_system_enabled($pdo)) {
        return ['error' => 'Referrals are currently disabled.'];
    }
    if ((int)$referrerUserId === (int)$referredUserId) {
        return ['error' => 'You cannot refer yourself.'];
    }

    $stmt = $pdo->prepare("SELECT id, fullname, phone, email FROM users WHERE id = ?");
    $stmt->execute([$referrerUserId]);
    $referrer = $stmt->fetch();
    $stmt->execute([$referredUserId]);
    $referred = $stmt->fetch();
    if (!$referrer || !$referred) {
        return ['error' => 'Invalid referral.'];
    }
    // Fraud control: matching contact details is the one concrete
    // duplicate-account signal checkable without new infrastructure —
    // real phone/email uniqueness is already enforced at registration,
    // this just refuses to LINK two rows that somehow share either.
    if ($referrer['phone'] === $referred['phone'] || $referrer['email'] === $referred['email']) {
        return ['error' => 'This referral could not be linked.'];
    }

    $publicId = generate_unique_public_id($pdo, 'referrals', 'public_id', 'REF');
    $stmt = $pdo->prepare("INSERT INTO referrals (public_id, referrer_user_id, referred_user_id, referral_type, status) VALUES (?, ?, ?, ?, 'REGISTERED')");
    $stmt->execute([$publicId, $referrerUserId, $referredUserId, $referralType]);
    $referralId = $pdo->lastInsertId();

    $pdo->prepare("UPDATE users SET referred_by = ? WHERE id = ?")->execute([$referrerUserId, $referredUserId]);

    // IP logged as a fraud-review signal (Phase 14's "same device abuse"
    // honestly implemented — this app has no device fingerprinting, so
    // a shared-IP-at-registration flag is what's actually checkable;
    // surfaced to admin, never auto-blocked).
    track_referral_event($pdo, $referredUserId, 'REGISTERED', ['ip' => $_SERVER['REMOTE_ADDR'] ?? null]);

    create_notification($pdo, $referrerUserId, [
        'title' => 'Someone joined your community',
        'message' => ($referred['fullname'] ?: 'A new user') . ' joined MohToh using your referral code.',
        'notification_type' => 'referral',
        'action' => 'open_referrals', 'related_table' => 'referrals', 'related_public_id' => $publicId,
    ]);

    return ['referral_id' => (int)$referralId, 'public_id' => $publicId];
}

// ---------------------------------------------------------------
// Event tracking — the single entry point every hook in the app calls.
// No-op for a user who wasn't referred (the common case).
// ---------------------------------------------------------------
function track_referral_event($pdo, $referredUserId, $eventType, $meta = null) {
    $stmt = $pdo->prepare("SELECT id, status FROM referrals WHERE referred_user_id = ?");
    $stmt->execute([$referredUserId]);
    $referral = $stmt->fetch();
    if (!$referral || $referral['status'] === 'CANCELLED') {
        return;
    }
    $referralId = $referral['id'];

    $stmt = $pdo->prepare("INSERT INTO referral_events (referral_id, event_type, metadata, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$referralId, $eventType, $meta !== null ? json_encode($meta) : null]);

    if (in_array($eventType, ['PHONE_VERIFIED', 'EMAIL_VERIFIED'], true)) {
        maybe_mark_referral_verified($pdo, $referralId);
    }
    if (in_array($eventType, ['FIRST_TRIP_COMPLETED', 'FIRST_RIDE_CREATED', 'DRIVER_VERIFIED'], true)) {
        qualify_referral($pdo, $referralId);
    }
}

function maybe_mark_referral_verified($pdo, $referralId) {
    $stmt = $pdo->prepare("SELECT status, referrer_user_id FROM referrals WHERE id = ?");
    $stmt->execute([$referralId]);
    $referral = $stmt->fetch();
    if (!$referral || $referral['status'] !== 'REGISTERED') {
        return; // already past this stage, or cancelled
    }
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT event_type) FROM referral_events WHERE referral_id = ? AND event_type IN ('PHONE_VERIFIED','EMAIL_VERIFIED')");
    $stmt->execute([$referralId]);
    if ((int)$stmt->fetchColumn() < 2) {
        return; // needs both, not just one
    }

    $pdo->prepare("UPDATE referrals SET status = 'VERIFIED', verified_at = NOW() WHERE id = ?")->execute([$referralId]);
    create_notification($pdo, $referral['referrer_user_id'], [
        'title' => 'Referral verified',
        'message' => 'Your invited friend verified their account.',
        'notification_type' => 'referral', 'action' => 'open_referrals',
    ]);
}

// ---------------------------------------------------------------
// Qualification — the activity gate. Never fires from a client flag,
// only from track_referral_event() being called at a real business
// event's own call site.
// ---------------------------------------------------------------
function qualify_referral($pdo, $referralId) {
    $stmt = $pdo->prepare("SELECT * FROM referrals WHERE id = ?");
    $stmt->execute([$referralId]);
    $referral = $stmt->fetch();
    if (!$referral || in_array($referral['status'], ['QUALIFIED', 'REWARDED', 'CANCELLED'], true)) {
        return;
    }

    // Expiry: a referral that overstays its qualification window is
    // cancelled outright rather than rewarded late.
    $expiryDays = (int) get_system_setting($pdo, 'referral_reward_expiry_days', 30);
    if ($expiryDays > 0 && strtotime($referral['registered_at']) < strtotime("-{$expiryDays} days")) {
        $pdo->prepare("UPDATE referrals SET status = 'CANCELLED' WHERE id = ?")->execute([$referralId]);
        create_notification($pdo, $referral['referrer_user_id'], [
            'title' => 'Reward cancelled',
            'message' => 'A pending referral reward expired before qualification.',
            'notification_type' => 'referral', 'action' => 'open_referrals',
        ]);
        return;
    }

    $stmt = $pdo->prepare("SELECT DISTINCT event_type FROM referral_events WHERE referral_id = ?");
    $stmt->execute([$referralId]);
    $events = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Phase 4's exact rules: rider = registered + phone+email verified +
    // first paid ride completed; driver = the same plus vehicle
    // approval, first ride published, first trip completed as driver.
    $required = $referral['referral_type'] === 'DRIVER'
        ? ['REGISTERED', 'PHONE_VERIFIED', 'EMAIL_VERIFIED', 'DRIVER_VERIFIED', 'FIRST_RIDE_CREATED', 'FIRST_TRIP_COMPLETED']
        : ['REGISTERED', 'PHONE_VERIFIED', 'EMAIL_VERIFIED', 'FIRST_TRIP_COMPLETED'];

    foreach ($required as $req) {
        if (!in_array($req, $events, true)) {
            return; // not all requirements met yet — stays at current status
        }
    }

    $pdo->prepare("UPDATE referrals SET status = 'QUALIFIED', qualified_at = NOW() WHERE id = ?")->execute([$referralId]);
    create_notification($pdo, $referral['referrer_user_id'], [
        'title' => 'Referral qualified',
        'message' => 'Your invited friend completed the requirements — your reward is on its way.',
        'notification_type' => 'referral', 'action' => 'open_referrals',
    ]);

    $isDriver = $referral['referral_type'] === 'DRIVER';
    $inviterType = $isDriver ? 'driver_inviter' : 'rider_inviter';
    $newUserType = $isDriver ? 'new_driver' : 'new_rider';

    $anyRewardPaid = false;
    $inviterAmount = calculate_referral_reward($pdo, $referral, $inviterType);
    if ($inviterAmount !== null) {
        release_referral_reward($pdo, $referral['referrer_user_id'], $referralId, $inviterType, $inviterAmount);
        $anyRewardPaid = true;
    }
    $newUserAmount = calculate_referral_reward($pdo, $referral, $newUserType);
    if ($newUserAmount !== null) {
        release_referral_reward($pdo, $referral['referred_user_id'], $referralId, $newUserType, $newUserAmount);
        $anyRewardPaid = true;
    }

    if ($anyRewardPaid) {
        $pdo->prepare("UPDATE referrals SET status = 'REWARDED' WHERE id = ?")->execute([$referralId]);
    }
}

// Returns the reward amount to pay, or null if disabled/unconfigured/capped.
function calculate_referral_reward($pdo, $referral, $rewardType) {
    if (!referral_system_enabled($pdo)) {
        return null;
    }
    $keyMap = [
        'rider_inviter' => 'referral_rider_reward_amount',
        'new_rider' => 'referral_new_rider_reward_amount',
        'driver_inviter' => 'referral_driver_reward_amount',
        'new_driver' => 'referral_new_driver_reward_amount',
    ];
    if (!isset($keyMap[$rewardType])) {
        return null;
    }
    $amount = (float) get_system_setting($pdo, $keyMap[$rewardType], 0);
    if ($amount <= 0) {
        return null; // admin hasn't configured a reward for this type — not an error, just nothing to pay
    }

    $userId = in_array($rewardType, ['rider_inviter', 'driver_inviter'], true)
        ? $referral['referrer_user_id']
        : $referral['referred_user_id'];

    $maxRewards = (int) get_system_setting($pdo, 'referral_max_rewards_per_user', 0); // 0 = unlimited
    if ($maxRewards > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM referral_rewards WHERE user_id = ? AND status = 'PAID'");
        $stmt->execute([$userId]);
        if ((int)$stmt->fetchColumn() >= $maxRewards) {
            return null; // capped
        }
    }

    return round($amount, 2);
}

// The only function in this file that touches money — routes through
// credit_wallet() exactly like every other wallet credit in the app.
function release_referral_reward($pdo, $userId, $referralId, $rewardType, $amount) {
    $publicId = generate_unique_public_id($pdo, 'referral_rewards', 'public_id', 'RRWD');
    $stmt = $pdo->prepare("INSERT INTO referral_rewards (public_id, user_id, referral_id, reward_type, amount, status, created_at) VALUES (?, ?, ?, ?, ?, 'PENDING', NOW())");
    $stmt->execute([$publicId, $userId, $referralId, $rewardType, $amount]);
    $rewardId = $pdo->lastInsertId();

    $labels = [
        'rider_inviter' => 'inviting a rider who completed their first paid ride',
        'new_rider' => 'joining MohToh through a referral',
        'driver_inviter' => 'inviting a driver who completed their first trip',
        'new_driver' => 'joining MohToh as a driver through a referral',
    ];
    $description = 'MohToh Community Rewards: ₦' . number_format($amount, 2) . ' for ' . ($labels[$rewardType] ?? 'a referral') . '.';

    // credit_wallet() already sends its own generic "wallet credited"
    // notification — this function's own notification below is a
    // distinct, referral-specific one (different action/deep-link),
    // not a duplicate of the same concern.
    $newBalance = credit_wallet($pdo, $userId, $amount, 'REFERRAL_REWARD', $description, null, null, ['referral_id' => $referralId, 'reward_type' => $rewardType]);
    if ($newBalance === false) {
        $pdo->prepare("UPDATE referral_rewards SET status = 'CANCELLED' WHERE id = ?")->execute([$rewardId]);
        return null;
    }

    $stmt = $pdo->prepare("SELECT id FROM wallet_transactions WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$userId]);
    $walletTransactionId = $stmt->fetchColumn();
    $pdo->prepare("UPDATE referral_rewards SET status = 'PAID', wallet_transaction_id = ? WHERE id = ?")->execute([$walletTransactionId, $rewardId]);

    $pdo->prepare("INSERT INTO referral_events (referral_id, event_type, metadata, created_at) VALUES (?, 'REWARD_RELEASED', ?, NOW())")
        ->execute([$referralId, json_encode(['user_id' => $userId, 'reward_type' => $rewardType, 'amount' => $amount])]);

    create_notification($pdo, $userId, [
        'title' => 'Reward received',
        'message' => $description,
        'notification_type' => 'referral',
        'action' => 'open_referrals', 'related_table' => 'referral_rewards', 'related_public_id' => $publicId,
    ]);

    return (int)$rewardId;
}

// ---------------------------------------------------------------
// Read models — power the user-facing API and the admin Community
// Growth tab. Direct referrals only (Phase 10): never recurses into a
// referral's own referred_by chain, so there is no multi-level
// structure to accidentally build on top of later.
// ---------------------------------------------------------------
function get_user_referrals($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT r.public_id, r.referral_type, r.status, r.registered_at, r.qualified_at,
               u.fullname, u.user_type, u.is_online,
               (
                   (SELECT COUNT(*) FROM ride_share_bookings b WHERE b.rider_id = u.id AND b.status = 'completed')
                   + (SELECT COUNT(*) FROM ride_shares rs WHERE rs.driver_id = u.id AND rs.trip_state = 'TRIP_COMPLETED')
               ) AS trips_generated
        FROM referrals r
        JOIN users u ON u.id = r.referred_user_id
        WHERE r.referrer_user_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function get_referral_statistics($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS invited,
               SUM(status IN ('QUALIFIED','REWARDED')) AS active,
               SUM(referral_type = 'RIDER') AS riders_invited,
               SUM(referral_type = 'DRIVER') AS drivers_invited
        FROM referrals WHERE referrer_user_id = ?
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch();

    $referrals = get_user_referrals($pdo, $userId);
    $tripsGenerated = (int) array_sum(array_column($referrals, 'trips_generated'));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM referral_rewards WHERE user_id = ? AND status = 'PAID'");
    $stmt->execute([$userId]);

    return [
        'invited' => (int)($stats['invited'] ?? 0),
        'active' => (int)($stats['active'] ?? 0),
        'riders_invited' => (int)($stats['riders_invited'] ?? 0),
        'drivers_invited' => (int)($stats['drivers_invited'] ?? 0),
        'trips_generated' => $tripsGenerated,
        'total_rewards' => round((float)$stmt->fetchColumn(), 2),
    ];
}
