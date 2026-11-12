<?php
// includes/referral.php
// MohToh Community Rewards — SUPERSEDED by MOHTOH NETWORK
// (includes/network.php). Kept only so historical data stays readable
// (api/referrals.php, partials/community-rewards-sheet.php still show
// a user's pre-migration history) and so every existing call site
// (api/auth.php, includes/verification.php, includes/functions.php,
// api/rideshare.php, api/admin-api.php, api/v1/driver/create-ride.php)
// keeps working unchanged. create_referral_relationship() and
// track_referral_event() below no longer write to the legacy
// referrals/referral_rewards tables — they delegate to network.php's
// network_attribute_referral()/network_track_milestone() instead, so
// every one of those call sites now feeds the NEW programme without
// having to be touched individually. generate_referral_code(),
// get_or_create_referral_code(), get_user_referrals() and
// get_referral_statistics() are untouched and still read the legacy
// tables as-is, for historical display only.
require_once __DIR__ . '/network.php';

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
// Relationship creation — SUPERSEDED. Delegates to MOHTOH NETWORK's
// network_attribute_referral() (includes/network.php) instead of
// writing a new `referrals` row, so every existing call site
// (api/auth.php at registration) now attributes into the new
// programme without having to change its own code.
// ---------------------------------------------------------------
function create_referral_relationship($pdo, $referrerUserId, $referredUserId, $referralType) {
    return network_attribute_referral($pdo, $referrerUserId, $referredUserId, $referralType);
}

// ---------------------------------------------------------------
// Event tracking — SUPERSEDED. Delegates to MOHTOH NETWORK's
// network_track_milestone() instead of writing to the legacy
// `referral_events`/qualifying/reward-release chain, so every existing
// call site (verification.php, functions.php's trip engine,
// rideshare.php, admin-api.php, api/v1/driver/create-ride.php) now
// feeds the new programme's referral lifecycle unchanged.
// ---------------------------------------------------------------
function track_referral_event($pdo, $referredUserId, $eventType, $meta = null) {
    network_track_milestone($pdo, $referredUserId, $eventType, $meta);
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
