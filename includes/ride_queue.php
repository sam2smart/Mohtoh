<?php
// includes/ride_queue.php
// Ride Queue business logic — renamed/converted from the original
// includes/waitlist.php per the Ride Demand System Restructure: this is
// SYSTEM 2 only ("a ride already exists but every seat is taken; the
// passenger waits for one to open up"). It is NOT the same concept as
// Ride Requests (SYSTEM 1, includes/ride_requests.php — "no matching
// ride exists yet; the passenger describes what they need"). The two
// must never be merged back together.
//
// Reuses existing engines throughout: generate_unique_public_id() for
// public IDs, create_notification() for every notification,
// includes/booking.php's create_ride_booking() for the actual BOOK_NOW
// conversion (in api/ride-queue.php, not duplicated here). This file
// only manages the ride_waitlists table itself (table name kept as-is —
// a pure storage detail with zero user visibility; renaming it is not
// required and only adds migration risk) — it never touches
// ride_shares.available_seats or ride_share_bookings directly, so it
// can't drift from the real seat count the booking engine already owns.

// A passenger joins the ride queue for a ride that currently has no
// available seats. Returns ['status'=>'success'|'error', 'message'=>,
// 'data'=>] — same result shape as create_ride_booking(), so
// api/ride-queue.php can respond with json_response($result['status'], ...)
// identically to how api/rideshare.php handles booking.
function join_queue($pdo, $user_id, $ride_share_id, $seats_requested) {
    $seats_requested = max(1, (int)$seats_requested);
    $ride_share_id = (int)$ride_share_id;

    $stmt = $pdo->prepare("SELECT id, driver_id, destination, start_location, available_seats, status, departure_date, departure_time FROM ride_shares WHERE id = ?");
    $stmt->execute([$ride_share_id]);
    $ride = $stmt->fetch();
    if (!$ride) {
        return ['status' => 'error', 'message' => 'Ride not found.'];
    }
    if (in_array($ride['status'], ['cancelled', 'completed'], true)) {
        return ['status' => 'error', 'message' => 'This ride is no longer available to join.'];
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_share_bookings WHERE ride_share_id = ? AND rider_id = ? AND status != 'cancelled'");
    $stmt->execute([$ride_share_id, $user_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['status' => 'error', 'message' => 'You already have a seat booked on this ride.'];
    }

    if ((int)$ride['available_seats'] > 0) {
        return ['status' => 'error', 'message' => 'This ride still has seats available — book directly instead of joining the ride queue.'];
    }

    $stmt = $pdo->prepare("SELECT id FROM ride_waitlists WHERE ride_share_id = ? AND user_id = ? AND status IN ('WAITING', 'NOTIFIED', 'RESERVED')");
    $stmt->execute([$ride_share_id, $user_id]);
    if ($stmt->fetch()) {
        return ['status' => 'error', 'message' => 'You are already in the ride queue for this ride.'];
    }

    $publicId = generate_unique_public_id($pdo, 'ride_waitlists', 'public_id', 'QUEUE');
    $stmt = $pdo->prepare("INSERT INTO ride_waitlists (public_id, ride_share_id, user_id, seats_requested, status) VALUES (?, ?, ?, ?, 'WAITING')");
    $stmt->execute([$publicId, $ride_share_id, $user_id, $seats_requested]);

    create_notification($pdo, $user_id, [
        'title' => 'Added to ride queue',
        'message' => "You're in the ride queue for the ride to {$ride['destination']}. We'll notify you the moment a seat opens up.",
        'notification_type' => 'ride',
        'action' => 'view_ride_queue', 'action_id' => $ride_share_id, 'related_table' => 'ride_waitlists', 'related_public_id' => $publicId,
    ]);

    return ['status' => 'success', 'message' => 'You have joined the ride queue for this ride.', 'data' => ['queue_public_id' => $publicId]];
}

// A passenger removes themselves from a ride's queue. $identifier may
// be the numeric id or the public_id — api/ride-queue.php always passes
// the public_id (never exposes raw ids to the client), the numeric form
// is accepted too since process_queue() and other server-side callers
// already have the row's numeric id on hand.
function leave_queue($pdo, $user_id, $identifier) {
    $column = is_numeric($identifier) ? 'id' : 'public_id';
    $stmt = $pdo->prepare("SELECT id, status FROM ride_waitlists WHERE {$column} = ? AND user_id = ?");
    $stmt->execute([$identifier, $user_id]);
    $entry = $stmt->fetch();
    if (!$entry) {
        return ['status' => 'error', 'message' => 'Ride queue entry not found.'];
    }
    if (in_array($entry['status'], ['CONVERTED', 'CANCELLED', 'EXPIRED'], true)) {
        return ['status' => 'error', 'message' => 'This ride queue entry is no longer active.'];
    }

    $pdo->prepare("UPDATE ride_waitlists SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$entry['id']]);
    return ['status' => 'success', 'message' => 'You have left the ride queue.'];
}

// Marks every still-active (WAITING/NOTIFIED/RESERVED) entry on a ride
// EXPIRED without notifying anyone — used when the ride itself stops
// being joinable (cancelled, or hard-deleted by admin's
// remove_ride_share). Idempotent: no-ops once nothing is active.
function expire_ride_queue($pdo, $ride_share_id) {
    $pdo->prepare("UPDATE ride_waitlists SET status = 'EXPIRED', updated_at = NOW() WHERE ride_share_id = ? AND status IN ('WAITING', 'NOTIFIED', 'RESERVED')")
        ->execute([(int)$ride_share_id]);
}

// The core event handler: call this whenever a ride's available_seats
// may have increased (rider cancels a booking, driver cancels the
// whole ride, admin raises seat capacity) — see api/rideshare.php's
// cancel_booking/cancel actions and api/admin-api.php's
// update_ride_share/remove_ride_share for the call sites. Never books
// anyone; only decides who to notify.
//
// Ranking ("First: exact route match, Second: closest pickup, Third:
// earliest joined"): every ride_waitlists row is already scoped to one
// specific ride_share_id (see database/migrate_waitlist.php — the table
// has no independent route/pickup fields), so every candidate in this
// function's pool already shares the exact same route and pickup point
// by construction — there is no "closer" pickup to rank against within
// a single ride. The one differentiator that actually varies between
// queued passengers is join order, so joined_at ASC (FIFO) is what
// implements tier 3 here, with tiers 1-2 satisfied trivially.
function process_queue($pdo, $ride_share_id) {
    $ride_share_id = (int)$ride_share_id;
    $stmt = $pdo->prepare("SELECT id, status, available_seats, destination, start_location, departure_date, departure_time, price_per_passenger FROM ride_shares WHERE id = ?");
    $stmt->execute([$ride_share_id]);
    $ride = $stmt->fetch();

    if (!$ride || in_array($ride['status'], ['cancelled', 'completed'], true)) {
        expire_ride_queue($pdo, $ride_share_id);
        return ['notified' => 0, 'reason' => 'ride_not_joinable'];
    }

    $seatsAvailable = (int)$ride['available_seats'];
    if ($seatsAvailable <= 0) {
        return ['notified' => 0, 'reason' => 'no_seats_available'];
    }

    // FIFO among still-active candidates. WAITING and NOTIFIED both
    // re-enter ranking here — a NOTIFIED passenger who didn't book yet
    // stays eligible for the next round rather than being skipped.
    $stmt = $pdo->prepare("
        SELECT rw.id, rw.public_id, rw.user_id, rw.seats_requested, rw.status
        FROM ride_waitlists rw
        WHERE rw.ride_share_id = ? AND rw.status IN ('WAITING', 'NOTIFIED')
        ORDER BY rw.joined_at ASC
    ");
    $stmt->execute([$ride_share_id]);
    $candidates = $stmt->fetchAll();

    $notifiedCount = 0;
    $remainingSeats = $seatsAvailable;
    $windowMinutes = (int) get_system_setting($pdo, 'waitlist_notification_window_minutes', 30);
    // MySQL's own clock, not PHP's date() — this box runs PHP and MySQL
    // in different timezones (see includes/functions.php's notify_user()
    // area and api/update-location.php for the same documented issue);
    // a PHP-computed timestamp compared against DB rows later would be
    // silently wrong by the offset between the two.
    $expiresAt = $pdo->query("SELECT DATE_ADD(NOW(), INTERVAL {$windowMinutes} MINUTE)")->fetchColumn();

    foreach ($candidates as $candidate) {
        if ($remainingSeats <= 0) {
            break; // nobody else can realistically join this round
        }
        // Only notify someone whose full request actually fits in what's
        // left — "notify only users who can realistically join".
        if ((int)$candidate['seats_requested'] > $remainingSeats) {
            continue;
        }

        $pdo->prepare("UPDATE ride_waitlists SET status = 'NOTIFIED', notified_at = NOW(), expires_at = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$expiresAt, $candidate['id']]);

        create_notification($pdo, $candidate['user_id'], [
            'title' => 'Seat Available From Queue',
            'message' => 'A seat is now available for your ride — you were next in the queue.',
            'notification_type' => 'ride',
            'priority' => 'high',
            'force' => true, // demand-response window is short; not user-suppressible
            'action' => 'BOOK_NOW',
            'action_id' => $ride_share_id,
            'related_table' => 'ride_shares',
            'meta' => [
                'queue_public_id' => $candidate['public_id'],
                'route' => $ride['start_location'] . ' → ' . $ride['destination'],
                'departure_date' => $ride['departure_date'],
                'departure_time' => $ride['departure_time'],
                'fare' => (float)$ride['price_per_passenger'],
                'seats_requested' => (int)$candidate['seats_requested'],
            ],
        ]);

        $remainingSeats -= (int)$candidate['seats_requested'];
        $notifiedCount++;
    }

    return ['notified' => $notifiedCount, 'reason' => $notifiedCount > 0 ? 'notified' : 'no_matching_candidates'];
}

// Passenger-facing list for api/ride-queue.php's my_queue action.
function get_user_ride_queue_entries($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT rw.public_id, rw.seats_requested, rw.status, rw.joined_at, rw.notified_at, rw.expires_at,
               rs.id AS ride_share_id, rs.public_id AS ride_public_id, rs.start_location, rs.destination,
               rs.departure_date, rs.departure_time, rs.price_per_passenger, rs.available_seats, rs.status AS ride_status
        FROM ride_waitlists rw
        JOIN ride_shares rs ON rs.id = rw.ride_share_id
        WHERE rw.user_id = ?
        ORDER BY rw.status IN ('WAITING', 'NOTIFIED', 'RESERVED') DESC, rw.joined_at DESC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll();
}

// Driver-facing demand summary — a count only, never the queued
// passengers' identities. Driver only ever sees "⏳ X waiting".
function get_ride_queue_summary($pdo, $ride_share_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) AS waiting_count, COALESCE(SUM(seats_requested), 0) AS seats_wanted FROM ride_waitlists WHERE ride_share_id = ? AND status IN ('WAITING', 'NOTIFIED')");
    $stmt->execute([(int)$ride_share_id]);
    $row = $stmt->fetch();
    return ['waiting_count' => (int)$row['waiting_count'], 'seats_wanted' => (int)$row['seats_wanted']];
}
