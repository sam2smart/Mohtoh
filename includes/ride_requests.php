<?php
// includes/ride_requests.php
// Ride Demand System Restructure — SYSTEM 1: Ride Requests. All business
// rules for the ride_demand_requests table live here; api/ride-requests.php
// is a thin HTTP wrapper only (no SQL/business logic there), same
// pattern as includes/ride_queue.php sitting under api/ride-queue.php.
//
// This is a genuinely separate concept from the Ride Queue: a Ride
// Request exists because NO matching ride currently exists ("I need a
// ride from X to Y tomorrow at 7am, please someone create one"); a Ride
// Queue entry exists because a SPECIFIC ride already exists but is full
// ("notify me when a seat on THIS ride opens up"). Never merge the two.
//
// Table name note: a different, pre-existing `ride_requests` table
// already backs the instant/on-demand ride-hailing flow — see
// database/migrate_ride_requests.php's header comment. This file's
// table is `ride_demand_requests`.

// ---------------------------------------------------------------
// Config knobs — admin-tunable later the same way OTP/notification
// settings are (get_system_setting()), sane defaults for now.
// ---------------------------------------------------------------
function rr_max_open_requests_per_user($pdo) {
    return (int) get_system_setting($pdo, 'ride_request_max_open_per_user', 10);
}
function rr_match_pickup_radius_km($pdo) {
    return (float) get_system_setting($pdo, 'ride_request_match_pickup_radius_km', 5);
}
function rr_match_destination_radius_km($pdo) {
    return (float) get_system_setting($pdo, 'ride_request_match_destination_radius_km', 5);
}
function rr_match_time_window_hours($pdo) {
    return (float) get_system_setting($pdo, 'ride_request_match_time_window_hours', 2);
}

// Lazily closes out anything whose travel_date has already passed —
// called opportunistically at the top of every read path (list/feed/
// stats) since this app has no cron runner; same lazy-expiry pattern
// OTP/token expiry already uses elsewhere (checked at read time, not on
// a schedule). Only OPEN/MATCHED are still "live"; everything else is
// already a terminal state.
function expire_ride_requests($pdo) {
    $stmt = $pdo->query("SELECT id, public_id, user_id, pickup_location, destination FROM ride_demand_requests WHERE status IN ('OPEN', 'MATCHED') AND travel_date < CURDATE()");
    $expiring = $stmt->fetchAll();
    if (empty($expiring)) {
        return 0;
    }

    $ids = array_column($expiring, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("UPDATE ride_demand_requests SET status = 'EXPIRED', updated_at = NOW() WHERE id IN ({$placeholders})")->execute($ids);

    foreach ($expiring as $request) {
        create_notification($pdo, $request['user_id'], [
            'title' => 'Ride request expired',
            'message' => "Your ride request from {$request['pickup_location']} to {$request['destination']} has expired — its travel date has passed.",
            'notification_type' => 'ride',
            'action' => 'view_ride_request', 'related_table' => 'ride_demand_requests', 'related_public_id' => $request['public_id'],
        ]);
    }

    return count($expiring);
}

// Validates the shared field set for create/update. Returns null if
// valid, or an error message string. $forCreate relaxes nothing extra
// today but keeps the door open for create-only rules later.
function rr_validate_fields(array $fields, bool $forCreate): ?string {
    if ($forCreate) {
        foreach (['pickup_location', 'destination', 'travel_date'] as $required) {
            if (empty($fields[$required])) {
                return ucfirst(str_replace('_', ' ', $required)) . ' is required.';
            }
        }
    }
    if (isset($fields['travel_date'])) {
        $date = DateTime::createFromFormat('Y-m-d', $fields['travel_date']);
        if (!$date || $date->format('Y-m-d') !== $fields['travel_date']) {
            return 'Please enter a valid travel date (YYYY-MM-DD).';
        }
        if ($fields['travel_date'] < date('Y-m-d')) {
            return 'Travel date cannot be in the past.';
        }
    }
    if (isset($fields['travel_time']) && $fields['travel_time'] !== '' && $fields['travel_time'] !== null) {
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $fields['travel_time'])) {
            return 'Please enter a valid travel time (HH:MM).';
        }
    }
    if (isset($fields['passenger_count']) && ((int)$fields['passenger_count'] < 1 || (int)$fields['passenger_count'] > 8)) {
        return 'Passenger count must be between 1 and 8.';
    }
    foreach (['pickup_latitude', 'destination_latitude'] as $latField) {
        if (isset($fields[$latField]) && $fields[$latField] !== null && ($fields[$latField] < -90 || $fields[$latField] > 90)) {
            return 'Invalid latitude value.';
        }
    }
    foreach (['pickup_longitude', 'destination_longitude'] as $lngField) {
        if (isset($fields[$lngField]) && $fields[$lngField] !== null && ($fields[$lngField] < -180 || $fields[$lngField] > 180)) {
            return 'Invalid longitude value.';
        }
    }
    if (isset($fields['pickup_location']) && mb_strlen($fields['pickup_location']) > 255) {
        return 'Pickup location is too long.';
    }
    if (isset($fields['destination']) && mb_strlen($fields['destination']) > 255) {
        return 'Destination is too long.';
    }
    return null;
}

// A passenger creates a new Ride Request — demand, not a booking.
// $fields: pickup_location (required), pickup_latitude/pickup_longitude,
// destination (required), destination_latitude/destination_longitude,
// travel_date (required, Y-m-d), travel_time (H:i, optional), passenger_count
// (default 1), vehicle_preference, notes.
function create_ride_request($pdo, $user_id, array $fields) {
    $validationError = rr_validate_fields($fields, true);
    if ($validationError !== null) {
        return ['status' => 'error', 'message' => $validationError];
    }

    // Spam/duplicate prevention: cap total still-open demand per user,
    // and reject an exact-duplicate open request (same route + date)
    // rather than letting the same need pile up multiple times.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ride_demand_requests WHERE user_id = ? AND status = 'OPEN'");
    $stmt->execute([$user_id]);
    if ((int)$stmt->fetchColumn() >= rr_max_open_requests_per_user($pdo)) {
        return ['status' => 'error', 'message' => 'You have reached the maximum number of active ride requests. Cancel one before creating another.'];
    }

    $stmt = $pdo->prepare("SELECT id FROM ride_demand_requests WHERE user_id = ? AND status = 'OPEN' AND pickup_location = ? AND destination = ? AND travel_date = ?");
    $stmt->execute([$user_id, $fields['pickup_location'], $fields['destination'], $fields['travel_date']]);
    if ($stmt->fetch()) {
        return ['status' => 'error', 'message' => 'You already have an open request for this exact route and date.'];
    }

    $publicId = generate_unique_public_id($pdo, 'ride_demand_requests', 'public_id', 'REQ');
    $stmt = $pdo->prepare("
        INSERT INTO ride_demand_requests
            (public_id, user_id, pickup_location, pickup_latitude, pickup_longitude,
             destination, destination_latitude, destination_longitude,
             travel_date, travel_time, passenger_count, vehicle_preference, notes, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'OPEN')
    ");
    $stmt->execute([
        $publicId, $user_id,
        $fields['pickup_location'], $fields['pickup_latitude'] ?? null, $fields['pickup_longitude'] ?? null,
        $fields['destination'], $fields['destination_latitude'] ?? null, $fields['destination_longitude'] ?? null,
        $fields['travel_date'], ($fields['travel_time'] ?? '') !== '' ? $fields['travel_time'] : null,
        max(1, (int)($fields['passenger_count'] ?? 1)),
        $fields['vehicle_preference'] ?? null,
        $fields['notes'] ?? null,
    ]);

    create_notification($pdo, $user_id, [
        'title' => 'Ride request created',
        'message' => "Your ride request from {$fields['pickup_location']} to {$fields['destination']} is now visible to drivers.",
        'notification_type' => 'ride',
        'action' => 'view_ride_request', 'related_table' => 'ride_demand_requests', 'related_public_id' => $publicId,
    ]);

    return ['status' => 'success', 'message' => 'Your ride request has been created.', 'data' => ['public_id' => $publicId]];
}

// Owner-only, and only while still OPEN — once a driver has matched
// against it (or it's concluded), the details are locked; the
// passenger cancels and creates a fresh one instead of editing a
// request a driver may already be acting on.
function update_ride_request($pdo, $user_id, $request_public_id, array $fields) {
    $stmt = $pdo->prepare("SELECT * FROM ride_demand_requests WHERE public_id = ? AND user_id = ?");
    $stmt->execute([$request_public_id, $user_id]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['status' => 'error', 'message' => 'Ride request not found.'];
    }
    if ($request['status'] !== 'OPEN') {
        return ['status' => 'error', 'message' => 'Only an open request can be edited.'];
    }

    $merged = array_merge($request, array_filter($fields, fn($v) => $v !== null));
    $validationError = rr_validate_fields($fields, false);
    if ($validationError !== null) {
        return ['status' => 'error', 'message' => $validationError];
    }

    $editable = ['pickup_location', 'pickup_latitude', 'pickup_longitude', 'destination', 'destination_latitude', 'destination_longitude', 'travel_date', 'travel_time', 'passenger_count', 'vehicle_preference', 'notes'];
    $setClauses = [];
    $params = [];
    foreach ($editable as $column) {
        if (array_key_exists($column, $fields)) {
            $setClauses[] = "{$column} = ?";
            $params[] = $column === 'passenger_count' ? max(1, (int)$fields[$column]) : $fields[$column];
        }
    }
    if (empty($setClauses)) {
        return ['status' => 'error', 'message' => 'No changes were provided.'];
    }
    $params[] = $request['id'];
    $pdo->prepare("UPDATE ride_demand_requests SET " . implode(', ', $setClauses) . ", updated_at = NOW() WHERE id = ?")->execute($params);

    return ['status' => 'success', 'message' => 'Your ride request has been updated.'];
}

// Owner-only soft-close — keeps the row as history (Cancelled tab /
// admin demand analytics) instead of erasing it.
function cancel_ride_request($pdo, $user_id, $request_public_id) {
    $stmt = $pdo->prepare("SELECT id, status, pickup_location, destination FROM ride_demand_requests WHERE public_id = ? AND user_id = ?");
    $stmt->execute([$request_public_id, $user_id]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['status' => 'error', 'message' => 'Ride request not found.'];
    }
    if (in_array($request['status'], ['FULFILLED', 'CANCELLED', 'EXPIRED'], true)) {
        return ['status' => 'error', 'message' => 'This ride request is already closed.'];
    }

    $pdo->prepare("UPDATE ride_demand_requests SET status = 'CANCELLED', updated_at = NOW() WHERE id = ?")->execute([$request['id']]);

    create_notification($pdo, $user_id, [
        'title' => 'Ride request cancelled',
        'message' => "Your ride request from {$request['pickup_location']} to {$request['destination']} has been cancelled.",
        'notification_type' => 'ride',
        'action' => 'view_ride_request', 'related_table' => 'ride_demand_requests', 'related_public_id' => $request_public_id,
    ]);

    return ['status' => 'success', 'message' => 'Your ride request has been cancelled.'];
}

// Owner-only hard delete — restricted to already-concluded requests
// (cancelled/expired/fulfilled) so a passenger can tidy up their own
// history without erasing still-meaningful demand (OPEN/MATCHED stay
// protected; cancel first).
function delete_ride_request($pdo, $user_id, $request_public_id) {
    $stmt = $pdo->prepare("SELECT id, status FROM ride_demand_requests WHERE public_id = ? AND user_id = ?");
    $stmt->execute([$request_public_id, $user_id]);
    $request = $stmt->fetch();
    if (!$request) {
        return ['status' => 'error', 'message' => 'Ride request not found.'];
    }
    if (!in_array($request['status'], ['CANCELLED', 'EXPIRED', 'FULFILLED'], true)) {
        return ['status' => 'error', 'message' => 'Cancel this request before deleting it.'];
    }

    $pdo->prepare("DELETE FROM ride_demand_requests WHERE id = ?")->execute([$request['id']]);
    return ['status' => 'success', 'message' => 'Ride request deleted.'];
}

// Passenger-facing paginated list (My Ride Requests). Returns
// ['rows'=>, 'total'=>] so the API layer builds the pagination envelope
// via paginate_meta() — no SQL lives in api/ride-requests.php itself.
// $status accepts a single status string, an array of statuses (e.g.
// the rider UI's "Active" tab = ['OPEN','MATCHED']), or empty for all.
function get_user_requests($pdo, $user_id, $status, $limit, $offset) {
    expire_ride_requests($pdo);

    // Column names must be qualified with rd. — both `users` (public
    // user_id string) and `ride_shares` (status enum) joined below have
    // their OWN column of the same bare name, which MySQL rejects as
    // ambiguous the moment the join is in scope.
    $where = ['rd.user_id = ?'];
    $params = [$user_id];
    if (!empty($status)) {
        $statuses = is_array($status) ? $status : [$status];
        $where[] = 'rd.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT rd.*, rs.public_id AS matched_ride_public_id, rs.departure_date AS matched_departure_date,
               rs.departure_time AS matched_departure_time, rs.price_per_passenger AS matched_price,
               u.fullname AS matched_driver_name
        FROM ride_demand_requests rd
        LEFT JOIN ride_shares rs ON rs.id = rd.matched_ride_id
        LEFT JOIN users u ON u.id = rs.driver_id
        WHERE {$whereSql}
        ORDER BY FIELD(rd.status, 'OPEN', 'MATCHED', 'FULFILLED', 'EXPIRED', 'CANCELLED'), rd.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ride_demand_requests rd WHERE {$whereSql}");
    $countStmt->execute($params);
    return ['rows' => $rows, 'total' => (int)$countStmt->fetchColumn()];
}

// Driver-facing feed of open passenger demand ("nearby passenger
// demand" — Phase 6). Never exposes which OTHER driver has seen/ignored
// a request (there's no such concept — any driver can act on any open
// request by creating a matching ride). Optionally ranked by pickup
// proximity when the driver's current location is known.
function get_driver_requests($pdo, $driverLat, $driverLng, $limit, $offset) {
    expire_ride_requests($pdo);

    $stmt = $pdo->prepare("
        SELECT rd.*, u.fullname AS passenger_name
        FROM ride_demand_requests rd
        JOIN users u ON u.id = rd.user_id
        WHERE rd.status = 'OPEN'
        ORDER BY rd.travel_date ASC, rd.created_at DESC
    ");
    $stmt->execute();
    $all = $stmt->fetchAll();

    if ($driverLat !== null && $driverLng !== null) {
        foreach ($all as &$row) {
            $row['distance_km'] = ($row['pickup_latitude'] !== null && $row['pickup_longitude'] !== null)
                ? round(calculate_distance_km($driverLat, $driverLng, $row['pickup_latitude'], $row['pickup_longitude']), 1)
                : null;
        }
        unset($row);
        usort($all, function ($a, $b) {
            if ($a['distance_km'] === null) return 1;
            if ($b['distance_km'] === null) return -1;
            return $a['distance_km'] <=> $b['distance_km'];
        });
    } else {
        foreach ($all as &$row) {
            $row['distance_km'] = null;
        }
        unset($row);
    }

    $total = count($all);
    $rows = array_slice($all, $offset, $limit);
    return ['rows' => $rows, 'total' => $total];
}

// Single request's details — used by both the passenger (their own
// request) and a driver (any open request, to decide whether to create
// a matching ride) and admin (any request).
function get_ride_request_details($pdo, $request_public_id) {
    $stmt = $pdo->prepare("
        SELECT rd.*, u.fullname AS passenger_name,
               rs.public_id AS matched_ride_public_id, rs.departure_date AS matched_departure_date, rs.departure_time AS matched_departure_time
        FROM ride_demand_requests rd
        JOIN users u ON u.id = rd.user_id
        LEFT JOIN ride_shares rs ON rs.id = rd.matched_ride_id
        WHERE rd.public_id = ?
    ");
    $stmt->execute([$request_public_id]);
    return $stmt->fetch() ?: null;
}

// THE MATCHING ENGINE (Phase 7). Called right after a driver publishes
// a new ride (see api/rideshare.php's 'create' action) — searches OPEN
// requests for anything this new ride could plausibly serve, using
// pickup proximity + destination similarity + date + a time window +
// available seats. NEVER books anyone — only flags the request MATCHED
// and notifies the passenger; they decide whether to actually book.
function match_ride_requests($pdo, $ride_share_id) {
    $stmt = $pdo->prepare("SELECT id, driver_id, start_location, destination, pickup_lat, pickup_lng, destination_lat, destination_lng, departure_date, departure_time, available_seats FROM ride_shares WHERE id = ?");
    $stmt->execute([$ride_share_id]);
    $ride = $stmt->fetch();
    if (!$ride || (int)$ride['available_seats'] <= 0) {
        return ['matched' => 0];
    }

    $pickupRadius = rr_match_pickup_radius_km($pdo);
    $destRadius = rr_match_destination_radius_km($pdo);
    $timeWindowHours = rr_match_time_window_hours($pdo);

    $stmt = $pdo->prepare("SELECT * FROM ride_demand_requests WHERE status = 'OPEN' AND travel_date = ? AND passenger_count <= ?");
    $stmt->execute([$ride['departure_date'], (int)$ride['available_seats']]);
    $candidates = $stmt->fetchAll();

    $matchedCount = 0;
    foreach ($candidates as $request) {
        // Pickup proximity — both sides need coordinates to compare;
        // without them, proximity can't be evaluated so this candidate
        // is skipped rather than guessed at.
        if ($request['pickup_latitude'] === null || $request['pickup_longitude'] === null || $ride['pickup_lat'] === null || $ride['pickup_lng'] === null) {
            continue;
        }
        $pickupDistance = calculate_distance_km($request['pickup_latitude'], $request['pickup_longitude'], $ride['pickup_lat'], $ride['pickup_lng']);
        if ($pickupDistance === null || $pickupDistance > $pickupRadius) {
            continue;
        }

        // Destination similarity — coordinate distance if both sides
        // have it, otherwise fall back to a case-insensitive substring
        // match on the destination text (same tolerance search already
        // uses elsewhere in this app, e.g. api/rideshare.php's search action).
        $destinationMatches = false;
        if ($request['destination_latitude'] !== null && $request['destination_longitude'] !== null && $ride['destination_lat'] !== null && $ride['destination_lng'] !== null) {
            $destDistance = calculate_distance_km($request['destination_latitude'], $request['destination_longitude'], $ride['destination_lat'], $ride['destination_lng']);
            $destinationMatches = $destDistance !== null && $destDistance <= $destRadius;
        } else {
            $destinationMatches = stripos($ride['destination'], $request['destination']) !== false
                || stripos($request['destination'], $ride['destination']) !== false;
        }
        if (!$destinationMatches) {
            continue;
        }

        // Time window — only enforced if the passenger specified one;
        // an "any time that day" request matches on date alone.
        if ($request['travel_time'] !== null) {
            $requestMinutes = rr_time_to_minutes($request['travel_time']);
            $rideMinutes = rr_time_to_minutes($ride['departure_time']);
            if (abs($requestMinutes - $rideMinutes) > $timeWindowHours * 60) {
                continue;
            }
        }

        $pdo->prepare("UPDATE ride_demand_requests SET status = 'MATCHED', matched_ride_id = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$ride_share_id, $request['id']]);

        create_notification($pdo, $request['user_id'], [
            'title' => 'Matching ride found',
            'message' => "A ride matching your request is available: {$ride['start_location']} → {$ride['destination']} on {$ride['departure_date']}.",
            'notification_type' => 'ride',
            'priority' => 'high',
            'action' => 'view_matched_ride', 'action_id' => $ride_share_id, 'related_table' => 'ride_demand_requests', 'related_public_id' => $request['public_id'],
        ]);
        $matchedCount++;
    }

    return ['matched' => $matchedCount];
}

function rr_time_to_minutes($time) {
    [$h, $m] = array_map('intval', explode(':', $time));
    return $h * 60 + $m;
}

// Called once the passenger actually books the matched ride (wired into
// api/rideshare.php's booking action — see the ride_demand_requests hook
// there) — marks the request FULFILLED, the true "demand satisfied" end
// state, distinct from a passenger just cancelling it themselves.
function complete_ride_request($pdo, $request_public_id) {
    $stmt = $pdo->prepare("SELECT id, user_id, pickup_location, destination FROM ride_demand_requests WHERE public_id = ? AND status IN ('OPEN', 'MATCHED')");
    $stmt->execute([$request_public_id]);
    $request = $stmt->fetch();
    if (!$request) {
        return false;
    }

    $pdo->prepare("UPDATE ride_demand_requests SET status = 'FULFILLED', updated_at = NOW() WHERE id = ?")->execute([$request['id']]);
    return true;
}

// Admin — Ride Request Management (Phase 10): filterable/paginated
// cross-user view, popular routes, unserved demand (open requests with
// no match yet). No database logic lives in api/ride-requests.php
// itself; this is the one place that builds these queries.
function get_admin_ride_requests($pdo, $filters, $limit, $offset) {
    expire_ride_requests($pdo);

    $where = ['1=1'];
    $params = [];
    if (!empty($filters['status'])) {
        $where[] = 'rd.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['route'])) {
        $where[] = '(rd.pickup_location LIKE ? OR rd.destination LIKE ?)';
        $like = '%' . $filters['route'] . '%';
        $params[] = $like;
        $params[] = $like;
    }
    if (!empty($filters['date'])) {
        $where[] = 'rd.travel_date = ?';
        $params[] = $filters['date'];
    }
    $whereSql = implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT rd.*, u.fullname AS user_name, u.email AS user_email
        FROM ride_demand_requests rd
        JOIN users u ON u.id = rd.user_id
        WHERE {$whereSql}
        ORDER BY rd.created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ride_demand_requests rd JOIN users u ON u.id = rd.user_id WHERE {$whereSql}");
    $countStmt->execute($params);
    return ['rows' => $rows, 'total' => (int)$countStmt->fetchColumn()];
}

// Demand analytics (Phase 10): most-requested routes and unserved
// demand (open requests older than a day with no match yet — a proxy
// for "nobody has created a ride for this route").
function get_ride_request_analytics($pdo) {
    $popularRoutes = $pdo->query("
        SELECT pickup_location, destination, COUNT(*) AS request_count
        FROM ride_demand_requests
        GROUP BY pickup_location, destination
        ORDER BY request_count DESC
        LIMIT 10
    ")->fetchAll();

    $unservedDemand = $pdo->query("
        SELECT pickup_location, destination, travel_date, COUNT(*) AS request_count
        FROM ride_demand_requests
        WHERE status = 'OPEN' AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
        GROUP BY pickup_location, destination, travel_date
        ORDER BY request_count DESC
        LIMIT 20
    ")->fetchAll();

    $stmt = $pdo->query("
        SELECT
            SUM(status = 'OPEN') AS open_count,
            SUM(status = 'MATCHED') AS matched_count,
            SUM(status = 'FULFILLED') AS fulfilled_count,
            SUM(status = 'EXPIRED') AS expired_count,
            SUM(status = 'CANCELLED') AS cancelled_count,
            COUNT(*) AS total
        FROM ride_demand_requests
    ");
    $counts = $stmt->fetch();

    return [
        'popular_routes' => $popularRoutes,
        'unserved_demand' => $unservedDemand,
        'status_counts' => [
            'open' => (int)$counts['open_count'],
            'matched' => (int)$counts['matched_count'],
            'fulfilled' => (int)$counts['fulfilled_count'],
            'expired' => (int)$counts['expired_count'],
            'cancelled' => (int)$counts['cancelled_count'],
            'total' => (int)$counts['total'],
        ],
    ];
}
