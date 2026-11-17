<?php
// api/ride-queue.php
// Ride Queue System (SYSTEM 2) endpoints — renamed/converted from
// api/waitlist.php per the Ride Demand System Restructure. This is only
// for "a ride already exists but every seat is taken; the passenger
// waits for a seat to open up." It is a distinct, separate concept from
// Ride Requests (SYSTEM 1, api/ride-requests.php — "no matching ride
// exists yet"). Every action here is a thin HTTP wrapper around
// includes/ride_queue.php (join/leave/process) and includes/booking.php's
// create_ride_booking() (the BOOK_NOW flow) — no business logic lives
// in this file itself, matching the same pattern as api/notifications.php
// sitting on top of create_notification().
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/ride_queue.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'my_queue') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $entries = get_user_ride_queue_entries($pdo, $_SESSION['user_id']);
        json_response('success', 'Ride queue fetched', ['queue' => $entries]);
    }

    // Admin Ride Queue Management: cross-ride view with route/date/status
    // filters, popular routes, and platform-wide conversion rate.
    // Distinct from 'ride_queue' below, which is scoped to one ride and
    // shared with the driver's (count-only) view.
    if ($action === 'admin_overview') {
        if ($_SESSION['user_type'] !== 'admin') {
            json_response('error', 'Unauthorized');
        }

        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['status'])) {
            $where[] = 'rw.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['route'])) {
            $where[] = '(rs.start_location LIKE ? OR rs.destination LIKE ?)';
            $like = '%' . $_GET['route'] . '%';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($_GET['date'])) {
            $where[] = 'rs.departure_date = ?';
            $params[] = $_GET['date'];
        }

        [$page, $limit, $offset] = paginate_params();
        $stmt = $pdo->prepare("
            SELECT rw.public_id, rw.seats_requested, rw.status, rw.joined_at, rw.notified_at,
                   rs.id AS ride_share_id, rs.public_id AS ride_public_id, rs.start_location, rs.destination,
                   rs.departure_date, rs.departure_time, rs.status AS ride_status,
                   u.fullname AS user_name, u.email AS user_email
            FROM ride_waitlists rw
            JOIN ride_shares rs ON rs.id = rw.ride_share_id
            JOIN users u ON u.id = rw.user_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY rw.joined_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ride_waitlists rw JOIN ride_shares rs ON rs.id = rw.ride_share_id JOIN users u ON u.id = rw.user_id WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        $filteredTotal = (int)$countStmt->fetchColumn();

        // Popular routes: which routes generate the most ride-queue demand.
        $popularRoutes = $pdo->query("
            SELECT rs.start_location, rs.destination, COUNT(*) AS queue_count
            FROM ride_waitlists rw
            JOIN ride_shares rs ON rs.id = rw.ride_share_id
            GROUP BY rs.start_location, rs.destination
            ORDER BY queue_count DESC
            LIMIT 10
        ")->fetchAll();

        // Conversion rate: of every queue entry that reached a final
        // outcome (converted, expired, or cancelled — excludes still-
        // active WAITING/NOTIFIED/RESERVED, which haven't concluded yet).
        $stmt = $pdo->query("
            SELECT
                SUM(status = 'CONVERTED') AS converted,
                SUM(status IN ('CONVERTED', 'EXPIRED', 'CANCELLED')) AS concluded,
                COUNT(*) AS total
            FROM ride_waitlists
        ");
        $rates = $stmt->fetch();
        $conversionRate = (int)$rates['concluded'] > 0 ? round(((int)$rates['converted'] / (int)$rates['concluded']) * 100, 1) : 0.0;

        json_response('success', 'Ride queue overview fetched', [
            'entries' => $entries,
            'popular_routes' => $popularRoutes,
            'conversion_rate' => $conversionRate,
            'total_entries' => (int)$rates['total'],
            'converted_count' => (int)$rates['converted'],
        ], paginate_meta($page, $limit, $filteredTotal));
    }

    if ($action === 'ride_queue') {
        $ride_share_id = (int)($_GET['ride_id'] ?? 0);
        if ($ride_share_id <= 0) {
            json_response('error', 'Ride ID is required');
        }

        $stmt = $pdo->prepare("SELECT driver_id, destination, start_location FROM ride_shares WHERE id = ?");
        $stmt->execute([$ride_share_id]);
        $ride = $stmt->fetch();
        if (!$ride) {
            json_response('error', 'Ride not found');
        }

        $isOwningDriver = $_SESSION['user_type'] === 'driver' && (int)$ride['driver_id'] === (int)$_SESSION['user_id'];
        $isAdmin = $_SESSION['user_type'] === 'admin';
        if (!$isOwningDriver && !$isAdmin) {
            json_response('error', 'Unauthorized');
        }

        $summary = get_ride_queue_summary($pdo, $ride_share_id);

        // The driver only ever sees a demand COUNT, never who's waiting —
        // that's the entire point of "do not expose private passenger
        // information". Admin's Ride Queue Management view needs the
        // actual entries to manage/filter them.
        $response = ['summary' => $summary, 'route' => $ride['start_location'] . ' → ' . $ride['destination']];
        if ($isAdmin) {
            $stmt = $pdo->prepare("
                SELECT rw.public_id, rw.seats_requested, rw.status, rw.joined_at, rw.notified_at,
                       u.fullname AS user_name, u.email AS user_email
                FROM ride_waitlists rw
                JOIN users u ON u.id = rw.user_id
                WHERE rw.ride_share_id = ?
                ORDER BY rw.joined_at ASC
            ");
            $stmt->execute([$ride_share_id]);
            $response['entries'] = $stmt->fetchAll();
        }

        json_response('success', 'Ride queue fetched', $response);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($input);
    $action = $input['action'] ?? '';

    if ($action === 'join') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $result = join_queue(
            $pdo,
            $_SESSION['user_id'],
            (int)($input['ride_share_id'] ?? 0),
            (int)($input['seats_requested'] ?? 1)
        );
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    if ($action === 'leave') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $queueId = $input['queue_public_id'] ?? $input['waitlist_public_id'] ?? '';
        if ($queueId === '') {
            json_response('error', 'queue_public_id is required');
        }
        $result = leave_queue($pdo, $_SESSION['user_id'], $queueId);
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    // BOOK_NOW. Reuses create_ride_booking() end to end (same
    // wallet/payment/commission/validation as a normal booking, no
    // shortcuts) and only additionally updates the ride-queue row.
    if ($action === 'book') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $queuePublicId = $input['queue_public_id'] ?? $input['waitlist_public_id'] ?? '';
        if ($queuePublicId === '') {
            json_response('error', 'queue_public_id is required');
        }

        $stmt = $pdo->prepare("SELECT id, ride_share_id, seats_requested, status FROM ride_waitlists WHERE public_id = ? AND user_id = ?");
        $stmt->execute([$queuePublicId, $_SESSION['user_id']]);
        $entry = $stmt->fetch();
        if (!$entry) {
            json_response('error', 'Ride queue entry not found.');
        }
        if (!in_array($entry['status'], ['WAITING', 'NOTIFIED', 'RESERVED'], true)) {
            json_response('error', 'This ride queue entry is no longer active.');
        }

        require_once '../includes/booking.php';
        $result = create_ride_booking(
            $pdo,
            $_SESSION['user_id'],
            (int)$entry['ride_share_id'],
            (int)$entry['seats_requested'],
            $input['payment_method'] ?? null,
            ''
        );

        if ($result['status'] === 'success') {
            $pdo->prepare("UPDATE ride_waitlists SET status = 'CONVERTED', converted_booking_id = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$result['data']['booking_id'], $entry['id']]);
            json_response('success', $result['message'], $result['data']);
        }

        // create_ride_booking()'s own SELECT...FOR UPDATE re-checks
        // authoritatively — if someone else took the seat first, it
        // fails with one of two messages depending on exactly how full
        // the ride is by the time this request's lock acquires: 'Not
        // enough seats available' if available_seats already hit 0, or
        // 'This ride session is no longer available' if that same
        // booking also flipped status to 'full' first (status is
        // checked before the seat count — see includes/booking.php).
        // Either way the queue entry stays exactly as it was (still
        // WAITING/NOTIFIED), so the passenger remains in the queue.
        if (in_array($result['message'], ['Not enough seats available', 'This ride session is no longer available'], true)) {
            json_response('error', 'Seat was taken. You can remain in the ride queue.', ['still_queued' => true]);
        }

        json_response('error', $result['message']);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
