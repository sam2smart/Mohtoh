<?php
// api/ride-requests.php
// Ride Requests System (SYSTEM 1) endpoints — passenger DEMAND, created
// when no matching ride currently exists. Distinct from the Ride Queue
// (api/ride-queue.php — a specific ride already exists but is full).
// Thin HTTP wrapper only; every business rule lives in
// includes/ride_requests.php.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/ride_requests.php';

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

    if ($action === 'my_requests') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        [$page, $limit, $offset] = paginate_params();
        // Comma-separated so the "Active" tab can ask for OPEN,MATCHED
        // in one paginated query instead of two separate ones.
        $statusList = array_filter(array_map('trim', explode(',', $_GET['status'] ?? '')));
        foreach ($statusList as $s) {
            if (!in_array($s, ['OPEN', 'MATCHED', 'FULFILLED', 'EXPIRED', 'CANCELLED'], true)) {
                json_response('error', 'Invalid status filter.');
            }
        }
        $result = get_user_requests($pdo, $_SESSION['user_id'], $statusList, $limit, $offset);
        json_response('success', 'Ride requests fetched', ['requests' => $result['rows']], paginate_meta($page, $limit, $result['total']));
    }

    if ($action === 'driver_feed') {
        if ($_SESSION['user_type'] !== 'driver') {
            json_response('error', 'Unauthorized');
        }
        [$page, $limit, $offset] = paginate_params();
        $lat = isset($_GET['lat']) && $_GET['lat'] !== '' ? (float)$_GET['lat'] : null;
        $lng = isset($_GET['lng']) && $_GET['lng'] !== '' ? (float)$_GET['lng'] : null;
        $result = get_driver_requests($pdo, $lat, $lng, $limit, $offset);
        json_response('success', 'Ride requests fetched', ['requests' => $result['rows']], paginate_meta($page, $limit, $result['total']));
    }

    if ($action === 'details') {
        $requestPublicId = $_GET['public_id'] ?? '';
        if ($requestPublicId === '') {
            json_response('error', 'public_id is required');
        }
        $request = get_ride_request_details($pdo, $requestPublicId);
        if (!$request) {
            json_response('error', 'Ride request not found.');
        }
        // A rider may only view their OWN request's details; drivers and
        // admin can view any open (or previously-open) request — this
        // is public passenger demand by design, not a private booking.
        if ($_SESSION['user_type'] === 'rider' && (int)$request['user_id'] !== (int)$_SESSION['user_id']) {
            json_response('error', 'Unauthorized');
        }
        json_response('success', 'Ride request fetched', ['request' => $request]);
    }

    if ($action === 'statistics') {
        if ($_SESSION['user_type'] !== 'admin') {
            json_response('error', 'Unauthorized');
        }
        json_response('success', 'Ride request analytics fetched', get_ride_request_analytics($pdo));
    }

    // Admin — Ride Request Management: cross-user filterable/paginated view.
    if ($action === 'admin_list') {
        if ($_SESSION['user_type'] !== 'admin') {
            json_response('error', 'Unauthorized');
        }
        [$page, $limit, $offset] = paginate_params();
        $filters = [
            'status' => $_GET['status'] ?? '',
            'route' => $_GET['route'] ?? '',
            'date' => $_GET['date'] ?? '',
        ];
        $result = get_admin_ride_requests($pdo, $filters, $limit, $offset);
        json_response('success', 'Ride requests fetched', ['requests' => $result['rows']], paginate_meta($page, $limit, $result['total']));
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf($input);
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $result = create_ride_request($pdo, $_SESSION['user_id'], $input);
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    if ($action === 'update') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $requestPublicId = $input['public_id'] ?? '';
        if ($requestPublicId === '') {
            json_response('error', 'public_id is required');
        }
        $result = update_ride_request($pdo, $_SESSION['user_id'], $requestPublicId, $input);
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    if ($action === 'cancel') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $requestPublicId = $input['public_id'] ?? '';
        if ($requestPublicId === '') {
            json_response('error', 'public_id is required');
        }
        $result = cancel_ride_request($pdo, $_SESSION['user_id'], $requestPublicId);
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    if ($action === 'delete') {
        if ($_SESSION['user_type'] !== 'rider') {
            json_response('error', 'Unauthorized');
        }
        $requestPublicId = $input['public_id'] ?? '';
        if ($requestPublicId === '') {
            json_response('error', 'public_id is required');
        }
        $result = delete_ride_request($pdo, $_SESSION['user_id'], $requestPublicId);
        json_response($result['status'], $result['message'], $result['data'] ?? []);
    }

    // Manual/admin re-trigger of the matching engine for one ride (the
    // primary trigger is automatic — see api/rideshare.php's 'create'
    // action, which calls match_ride_requests() itself right after
    // publishing a new ride). Restricted to the ride's own driver or an
    // admin — never a rider, who has no ride to match against.
    if ($action === 'match') {
        $rideShareId = (int)($input['ride_share_id'] ?? 0);
        if ($rideShareId <= 0) {
            json_response('error', 'ride_share_id is required');
        }
        if ($_SESSION['user_type'] === 'admin') {
            // allowed
        } elseif ($_SESSION['user_type'] === 'driver') {
            $stmt = $pdo->prepare("SELECT driver_id FROM ride_shares WHERE id = ?");
            $stmt->execute([$rideShareId]);
            $ownerId = $stmt->fetchColumn();
            if ((int)$ownerId !== (int)$_SESSION['user_id']) {
                json_response('error', 'Unauthorized');
            }
        } else {
            json_response('error', 'Unauthorized');
        }
        $result = match_ride_requests($pdo, $rideShareId);
        json_response('success', "Matched {$result['matched']} ride request(s).", $result);
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
