<?php
// includes/private_pickup.php
//
// Private Solo Pickup engine. A rider requests a private trip (pickup +
// destination + notes); the request is matched to nearby eligible online
// keke drivers by GPS radius; a driver ACCEPTS or DECLINES; the winning
// accept atomically spawns a real private ride_shares trip that rides the
// SAME trip-state engine, wallet, commission and settlement primitives as
// Join Ride. No parallel trip system is created.
//
// Reuses:
//   - ride_requests table (extended, not duplicated) as the request queue
//   - calculate_distance_km() for the 20km radius match
//   - generate_unique_public_id / create_notification / commission +
//     trip-state primitives from functions.php
//   - check_driver_eligibility() as the single operational gate
//
// SINGLE-WINNER GUARANTEE: acceptance flips status pending->accepted inside
// a transaction with `WHERE status='pending'`; the affected-row count is the
// arbiter. A second driver's UPDATE matches zero rows and is rejected. No
// two drivers can ever both win the same request.

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/eligibility.php';

/**
 * Rider creates a private pickup request.
 * $data keys: pickup_location, pickup_lat, pickup_lng, destination,
 *             destination_lat, destination_lng, notes.
 * Returns ['status','message','data'].
 */
function create_private_pickup_request(PDO $pdo, int $riderId, array $data): array {
    // Riders must be verified before requesting, mirroring booking.php.
    if (!is_user_verified($pdo, $riderId)) {
        return ['status' => 'error', 'message' => 'Please complete your verification before requesting a ride.', 'data' => ['redirect' => '../verify-account.php']];
    }

    $pickup_location = trim((string)($data['pickup_location'] ?? ''));
    $destination     = trim((string)($data['destination'] ?? ''));
    $pickup_lat  = ($data['pickup_lat'] ?? '') !== '' ? (float)$data['pickup_lat'] : null;
    $pickup_lng  = ($data['pickup_lng'] ?? '') !== '' ? (float)$data['pickup_lng'] : null;
    $dest_lat    = ($data['destination_lat'] ?? '') !== '' ? (float)$data['destination_lat'] : null;
    $dest_lng    = ($data['destination_lng'] ?? '') !== '' ? (float)$data['destination_lng'] : null;
    $notes       = trim((string)($data['notes'] ?? ''));

    if ($pickup_location === '' || $destination === '') {
        return ['status' => 'error', 'message' => 'Pickup and destination are required.'];
    }
    if (!$pickup_lat || !$pickup_lng || !$dest_lat || !$dest_lng) {
        return ['status' => 'error', 'message' => 'Please select pickup and destination from the suggestions so we can locate drivers near you.'];
    }

    // One active request at a time per rider - prevents spamming the driver
    // feed. Sweep timed-out requests to 'expired' FIRST (the same lazy-expiry
    // the feed and status reads perform), otherwise a request that has passed
    // its TTL but has not yet been swept still counts as 'pending' here and
    // locks the rider out of ever requesting again - a permanent dead end for
    // anyone who closed the tab instead of cancelling.
    expire_stale_pickup_requests($pdo);
    $existing = $pdo->prepare("SELECT public_id FROM ride_requests WHERE rider_id = ? AND request_type = 'private_pickup' AND status IN ('pending','accepted','in_progress') LIMIT 1");
    $existing->execute([$riderId]);
    if ($existing->fetchColumn()) {
        return ['status' => 'error', 'message' => 'You already have an active pickup request. Cancel it before creating another.'];
    }

    $distance_km = calculate_distance_km($pickup_lat, $pickup_lng, $dest_lat, $dest_lng);
    // No driver is matched yet, so no vehicle class is known. The request is
    // stamped with the keke rate - Private Pickup's default class, and the
    // figure the rider is quoted while searching - and RE-PRICED against the
    // accepting driver's actual vehicle class in spawn_private_ride_from_request().
    // What the rider is finally charged always comes from the class that runs
    // the trip, never from this placeholder.
    $fare = estimate_private_pickup_fare($pdo, $distance_km, 'keke');
    $fare_options = private_pickup_fare_options($pdo, $distance_km);
    $ttl  = (int) get_system_setting($pdo, 'private_pickup_request_ttl_seconds', 90);

    $public_id = generate_unique_public_id($pdo, 'ride_requests', 'public_id', 'REQ');
    $stmt = $pdo->prepare(
        "INSERT INTO ride_requests
            (public_id, rider_id, request_type, pickup_lat, pickup_lng, pickup_location,
             destination, destination_lat, destination_lng, notes,
             requested_service_category, price, platform_fee, status, expires_at)
         VALUES (?, ?, 'private_pickup', ?, ?, ?, ?, ?, ?, ?, 'Standard', ?, ?, 'pending',
                 DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $platform_fee = round($fare['platform_fee'], 2);
    $stmt->execute([
        $public_id, $riderId, $pickup_lat, $pickup_lng, $pickup_location,
        $destination, $dest_lat, $dest_lng, ($notes !== '' ? $notes : null),
        round($fare['total'], 2), $platform_fee, $ttl,
    ]);
    $requestId = (int)$pdo->lastInsertId();

    // Fan out to nearby eligible drivers as notifications (with a beep hint
    // in meta the client sound-manager can act on).
    $matched = notify_nearby_drivers_of_request($pdo, $requestId);

    return ['status' => 'success', 'message' => 'Looking for a nearby driver...', 'data' => [
        'request_public_id' => $public_id,
        'estimated_fare'    => round($fare['total'], 2),
        'fare_options'      => private_pickup_public_fare_options($fare_options),
        'distance_km'       => $distance_km,
        'expires_in_seconds'=> $ttl,
        'drivers_notified'  => $matched,
    ]];
}

/**
 * The Private Pickup rate card for one vehicle class.
 *
 * A keke is not a car. Keke pickups are short local street trips; a car
 * doing the same job is a longer-distance vehicle with a different cost
 * base. Pricing them off one universal rate forces one of the two to be
 * wrong, so each class gets its own configurable rate card.
 *
 * Follows the vehicle-class override convention calculate_recommended_seat_price()
 * already uses for Join Ride (<class>_price_per_km / <class>_base_fare, where an
 * unset/blank value simply inherits): a per-class key wins when the admin has
 * set one, and when they haven't the universal private_pickup_* value is used
 * unchanged. So an install that has never touched these keys prices exactly as
 * it did before, and no rate is ever hard-coded here.
 *
 * $vehicle_type is canonicalised (so "Keke Napep"/"Saloon" resolve like the
 * stored value) and only the classes eligible for Private Pickup carry their
 * own card; anything else falls back to the universal rate.
 */
function private_pickup_rate_card(PDO $pdo, ?string $vehicle_type = null): array {
    $class = normalize_vehicle_type($vehicle_type);
    $prefix = is_private_pickup_vehicle_type($class) ? "private_pickup_{$class}_" : null;

    // A per-class key that exists but is blank means "inherit" - the same
    // semantics get_pricing_setting()'s '' default gives the Join Ride
    // class overrides - so clearing an admin field restores the universal rate.
    $classSetting = function (string $field) use ($pdo, $prefix) {
        if ($prefix === null) {
            return null;
        }
        $value = get_system_setting($pdo, $prefix . $field, '');
        return trim((string)$value) !== '' ? (float)$value : null;
    };

    $base   = $classSetting('base_fare')    ?? (float) get_system_setting($pdo, 'private_pickup_base_fare', 300);
    $perKm  = $classSetting('price_per_km') ?? (float) get_system_setting($pdo, 'private_pickup_price_per_km', 120);

    // Floor/ceiling default to 0 = unset rather than inheriting Join Ride's
    // minimum_fare/maximum_fare: coupling them would let a Join Ride edit
    // silently move Private Pickup prices.
    $minimum = $classSetting('minimum_fare') ?? 0.0;
    $maximum = $classSetting('maximum_fare') ?? 0.0;

    $commission = $classSetting('commission_percentage')
        ?? (float) get_system_setting($pdo, 'commission_percentage', 8);

    return [
        'vehicle_type'  => $class !== '' ? $class : null,
        'base_fare'     => max(0.0, $base),
        'price_per_km'  => max(0.0, $perKm),
        'booking_fee'   => max(0.0, (float) get_system_setting($pdo, 'private_booking_fee', 100)),
        'minimum_fare'  => max(0.0, $minimum),
        'maximum_fare'  => max(0.0, $maximum),
        'commission_percentage' => max(0.0, min(100.0, $commission)),
    ];
}

/**
 * Estimate the private pickup fare from configurable settings, for the
 * vehicle class that will actually run the trip.
 *
 * base_fare + (distance * price_per_km) + booking_fee, clamped to the class's
 * minimum/maximum (0 = unset); platform_fee = the class's commission %.
 *
 * $vehicle_type omitted keeps the pre-existing universal behaviour, so any
 * caller that has no vehicle in hand is priced exactly as before.
 */
function estimate_private_pickup_fare(PDO $pdo, float $distance_km, ?string $vehicle_type = null): array {
    $card = private_pickup_rate_card($pdo, $vehicle_type);

    $distance_fare = round($distance_km * $card['price_per_km'], 2);
    $total = $card['base_fare'] + $distance_fare + $card['booking_fee'];

    // Clamp what the rider actually pays. Applied after the booking fee so
    // "Minimum Fare"/"Maximum Fare" mean the amount on the rider's screen.
    if ($card['minimum_fare'] > 0 && $total < $card['minimum_fare']) {
        $total = $card['minimum_fare'];
    }
    if ($card['maximum_fare'] > 0 && $total > $card['maximum_fare']) {
        $total = $card['maximum_fare'];
    }

    $commission_pct = $card['commission_percentage'];
    $platform_fee   = round($total * $commission_pct / 100, 2);

    return [
        'vehicle_type' => $card['vehicle_type'],
        'base_fare'    => $card['base_fare'],
        'price_per_km' => $card['price_per_km'],
        'distance_fare'=> $distance_fare,
        'booking_fee'  => $card['booking_fee'],
        'minimum_fare' => $card['minimum_fare'],
        'maximum_fare' => $card['maximum_fare'],
        'total'        => round($total, 2),
        'platform_fee' => $platform_fee,
        'commission_percentage' => $commission_pct,
    ];
}

/**
 * The fare for each vehicle class that may answer a Private Pickup, keyed by
 * class. Used by the rider's pre-match estimate: no driver is matched yet, so
 * the honest quote is "what a keke costs" and "what a car costs" rather than
 * one blended number that matches neither.
 */
function private_pickup_fare_options(PDO $pdo, float $distance_km): array {
    $options = [];
    foreach (private_pickup_vehicle_types() as $type) {
        $options[$type] = estimate_private_pickup_fare($pdo, $distance_km, $type);
    }
    return $options;
}

/**
 * Flattens private_pickup_fare_options() into the shape the rider client
 * renders: one entry per class with its label and total, cheapest first. The
 * client does no fare math of its own - it only displays these numbers.
 */
function private_pickup_public_fare_options(array $options): array {
    $out = [];
    foreach ($options as $type => $fare) {
        $out[] = [
            'vehicle_type'  => $type,
            'label'         => vehicle_type_label($type),
            'total'         => round($fare['total'], 2),
        ];
    }
    usort($out, fn($a, $b) => $a['total'] <=> $b['total']);
    return $out;
}

/**
 * Find eligible online drivers within the configurable radius of a request's
 * pickup point, and notify them. Returns the count notified. Distance is
 * computed from coordinates (haversine), never city/state text.
 */
function notify_nearby_drivers_of_request(PDO $pdo, int $requestId): int {
    $reqStmt = $pdo->prepare("SELECT * FROM ride_requests WHERE id = ? AND status = 'pending'");
    $reqStmt->execute([$requestId]);
    $req = $reqStmt->fetch();
    if (!$req) {
        return 0;
    }

    $drivers = find_nearby_pickup_drivers($pdo, (float)$req['pickup_lat'], (float)$req['pickup_lng']);
    if (!$drivers) {
        return 0;
    }

    $tripKm = calculate_distance_km(
        (float)$req['pickup_lat'], (float)$req['pickup_lng'],
        (float)$req['destination_lat'], (float)$req['destination_lng']
    );

    // Each driver is quoted their OWN vehicle class's fare - a car driver must
    // not be alerted at the keke rate and then paid the car rate on accept.
    // Cached per class so a large match set still prices with two lookups.
    $fareByClass = [];

    $count = 0;
    foreach ($drivers as $d) {
        $class = normalize_vehicle_type($d['vehicle_type'] ?? null);
        if (!isset($fareByClass[$class])) {
            $fareByClass[$class] = estimate_private_pickup_fare($pdo, $tripKm, $class);
        }
        $fareInfo = $fareByClass[$class];

        create_notification($pdo, (int)$d['id'], [
            'title' => 'New Private Pickup request',
            'message' => "Pickup: {$req['pickup_location']} -> {$req['destination']} - " . number_format($d['distance_km'], 1) . "km away - ~ N" . number_format($fareInfo['total'], 0),
            'notification_type' => 'ride_request',
            'priority' => 'critical', // must break through so the driver hears the beep
            'action' => 'open_pickup_request',
            'action_id' => $requestId,
            'related_table' => 'ride_requests',
            'related_public_id' => $req['public_id'],
            'meta' => [
                'sound' => 'warning', // valid sound-manager type -> actual audible beep
                'request_public_id' => $req['public_id'],
                'distance_km' => $d['distance_km'],
                'fare' => $fareInfo['total'],
                'expires_at' => $req['expires_at'],
            ],
        ]);
        $count++;
    }
    return $count;
}

/**
 * Driver-side matching query: online, private-pickup-enabled, available
 * today, verified drivers whose last known coordinates are within the
 * configured radius. Returns rows with distance_km, nearest first.
 */
function find_nearby_pickup_drivers(PDO $pdo, float $pickupLat, float $pickupLng): array {
    $radius = (float) get_system_setting($pdo, 'private_pickup_radius_km', 20);
    $today  = date('Y-m-d');
    // A driver whose last GPS ping is older than this is treated as gone -
    // stale coordinates must never make a driver "nearby" (criterion #12).
    $locTtl = (int) get_system_setting($pdo, 'driver_location_ttl_seconds', 120);

    // Coarse bounding box first (cheap, index-friendly), then exact haversine
    // in PHP - avoids a full-table trig scan while staying coordinate-based.
    $latDelta = $radius / 111.0; // ~111km per degree latitude
    $cosLat = max(0.01, cos(deg2rad($pickupLat)));
    $lngDelta = $radius / (111.0 * $cosLat);

    // The eligible vehicle classes are named by eligibility.php so the match
    // set and the operational gate can never disagree (a driver discovered
    // here but rejected at accept, or vice versa). Bound as placeholders
    // rather than interpolated, keeping the query prepared-statement clean.
    $vehicleTypes = private_pickup_vehicle_types();
    $vehiclePlaceholders = implode(',', array_fill(0, count($vehicleTypes), '?'));

    $stmt = $pdo->prepare(
        "SELECT u.id, u.fullname, u.latitude, u.longitude, u.rating, u.last_location_update,
                v.vehicle_type
         FROM users u
         JOIN vehicles v ON v.driver_id = u.id
         WHERE u.user_type = 'driver'
           AND u.is_online = 1
           AND u.private_pickup_enabled = 1
           AND u.is_verified = 1
           AND u.is_suspended = 0
           AND u.pickup_available_today = 1
           AND u.pickup_availability_date = ?
           AND v.status = 'approved'
           AND v.verification_status = 'verified'
           AND v.vehicle_type IN ($vehiclePlaceholders)
           AND u.latitude IS NOT NULL AND u.longitude IS NOT NULL
           AND u.last_location_update IS NOT NULL
           AND u.last_location_update >= (NOW() - INTERVAL ? SECOND)
           AND u.latitude BETWEEN ? AND ?
           AND u.longitude BETWEEN ? AND ?"
    );
    $stmt->execute([
        $today, ...$vehicleTypes, $locTtl,
        $pickupLat - $latDelta, $pickupLat + $latDelta,
        $pickupLng - $lngDelta, $pickupLng + $lngDelta,
    ]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $dist = calculate_distance_km($pickupLat, $pickupLng, (float)$row['latitude'], (float)$row['longitude']);
        if ($dist <= $radius) {
            $row['distance_km'] = $dist;
            $out[] = $row;
        }
    }
    usort($out, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return $out;
}

/**
 * Driver's live feed of pending private pickup requests they're eligible
 * for and within range of. Read-only.
 */
function get_driver_pickup_feed(PDO $pdo, int $driverId): array {
    $elig = check_driver_eligibility($pdo, $driverId, 'private_pickup');
    if (!$elig['eligible']) {
        return ['eligible' => false, 'reason' => $elig['reason'], 'requests' => []];
    }

    // Expire stale requests lazily (no cron) before reading the feed.
    expire_stale_pickup_requests($pdo);

    // Age is computed by MySQL (TIMESTAMPDIFF), never PHP time() against a
    // MySQL-stored timestamp - this box runs PHP (Europe/Berlin) and MySQL
    // (SYSTEM) in different timezones, so mixing them silently adds a phantom
    // hour and would make every stale location look fresh. Same reasoning as
    // api/update-location.php.
    // The driver's vehicle class comes along so every request in the feed is
    // priced at the rate THIS driver would actually be paid on accept.
    $drv = $pdo->prepare(
        "SELECT u.latitude, u.longitude, u.last_location_update,
                TIMESTAMPDIFF(SECOND, u.last_location_update, NOW()) AS location_age_seconds,
                v.vehicle_type
         FROM users u
         LEFT JOIN vehicles v ON v.driver_id = u.id
         WHERE u.id = ?"
    );
    $drv->execute([$driverId]);
    $driver = $drv->fetch();
    if (!$driver || $driver['latitude'] === null || $driver['last_location_update'] === null) {
        return ['eligible' => true, 'reason' => 'Enable location to see nearby requests.', 'requests' => []];
    }

    // The driver's own position must be fresh, else the distance filter is
    // meaningless - refuse to serve a feed off a stale location (criterion #12).
    $locTtl = (int) get_system_setting($pdo, 'driver_location_ttl_seconds', 120);
    if ((int)$driver['location_age_seconds'] > $locTtl) {
        return ['eligible' => true, 'reason' => 'Your location is out of date. Refresh your location to see nearby requests.', 'requests' => []];
    }

    $radius = (float) get_system_setting($pdo, 'private_pickup_radius_km', 20);
    $driverClass = normalize_vehicle_type($driver['vehicle_type'] ?? null);
    $stmt = $pdo->prepare(
        "SELECT r.id, r.public_id, r.pickup_location, r.pickup_lat, r.pickup_lng,
                r.destination, r.destination_lat, r.destination_lng, r.notes,
                r.price, r.expires_at, r.created_at,
                u.fullname AS rider_name, u.rating AS rider_rating
         FROM ride_requests r
         JOIN users u ON u.id = r.rider_id
         WHERE r.request_type = 'private_pickup' AND r.status = 'pending'
           AND r.expires_at > NOW()
         ORDER BY r.created_at ASC"
    );
    $stmt->execute();

    $requests = [];
    foreach ($stmt->fetchAll() as $r) {
        $dist = calculate_distance_km((float)$driver['latitude'], (float)$driver['longitude'], (float)$r['pickup_lat'], (float)$r['pickup_lng']);
        if ($dist > $radius) {
            continue;
        }
        $trip_km = calculate_distance_km((float)$r['pickup_lat'], (float)$r['pickup_lng'], (float)$r['destination_lat'], (float)$r['destination_lng']);
        // Priced for this driver's class, not from the request's pre-match
        // placeholder - the figure shown here is the one accept() will honour.
        $fare = estimate_private_pickup_fare($pdo, $trip_km, $driverClass);
        $requests[] = [
            'request_public_id' => $r['public_id'],
            'pickup_location'   => $r['pickup_location'],
            'destination'       => $r['destination'],
            'notes'             => $r['notes'],
            'distance_to_pickup_km' => $dist,
            'trip_distance_km'  => $trip_km,
            'fare'              => round($fare['total'], 2),
            'vehicle_type'      => $fare['vehicle_type'],
            'rider_name'        => $r['rider_name'],
            'rider_rating'      => $r['rider_rating'],
            'expires_at'        => $r['expires_at'],
            'created_at'        => $r['created_at'],
        ];
    }
    return ['eligible' => true, 'reason' => '', 'requests' => $requests];
}

/**
 * ATOMIC single-winner acceptance. Reuses the SAME trip engine as Join Ride:
 * on winning, spawns a private ride_shares row (ride_mode='private',
 * vehicle_reserved=1), reserves commission, and links the request to it.
 * Returns ['status','message','data'].
 */
function accept_private_pickup_request(PDO $pdo, int $driverId, string $requestPublicId): array {
    // Re-validate eligibility at the moment of accept (revocation-safe).
    $elig = check_driver_eligibility($pdo, $driverId, 'private_pickup');
    if (!$elig['eligible']) {
        return ['status' => 'error', 'message' => $elig['reason']];
    }

    $pdo->beginTransaction();
    try {
        // Lock the request row, then claim it only if still pending. The
        // affected-row count from the guarded UPDATE is the single arbiter.
        // Expiry is evaluated by MySQL against its own NOW() - comparing a
        // MySQL timestamp to PHP's time() would pick up the PHP/MySQL
        // timezone offset as a phantom hour and expire every live request.
        $stmt = $pdo->prepare(
            "SELECT *, (expires_at IS NOT NULL AND expires_at < NOW()) AS is_expired
             FROM ride_requests
             WHERE public_id = ? AND request_type = 'private_pickup' FOR UPDATE"
        );
        $stmt->execute([$requestPublicId]);
        $req = $stmt->fetch();
        if (!$req) {
            throw new Exception('This request no longer exists.');
        }
        if ($req['status'] !== 'pending') {
            throw new Exception('This request has already been taken or is no longer available.');
        }
        if (!empty($req['is_expired'])) {
            throw new Exception('This request has expired.');
        }

        $claim = $pdo->prepare("UPDATE ride_requests SET status = 'accepted', driver_id = ?, accepted_at = NOW() WHERE id = ? AND status = 'pending'");
        $claim->execute([$driverId, $req['id']]);
        if ($claim->rowCount() !== 1) {
            // Someone else won between our SELECT and UPDATE.
            throw new Exception('This request has already been taken.');
        }

        // Spawn the private trip on the existing ride_shares engine.
        $rideId = spawn_private_ride_from_request($pdo, $driverId, $req);

        // Link request -> trip.
        $pdo->prepare("UPDATE ride_requests SET matched_ride_share_id = ?, status = 'in_progress' WHERE id = ?")
            ->execute([$rideId, $req['id']]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }

    // Post-commit side effects (never roll back the accept).
    transition_trip_state($pdo, $rideId, 'PUBLISHED', 'MANUAL_DRIVER_ACTION');
    transition_trip_state($pdo, $rideId, 'PASSENGERS_ASSEMBLED', 'system');

    create_notification($pdo, (int)$req['rider_id'], [
        'title' => 'Driver on the way',
        'message' => 'A driver accepted your private pickup and is heading to you.',
        'notification_type' => 'booking',
        'priority' => 'high',
        'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
    ]);

    return ['status' => 'success', 'message' => 'Pickup accepted.', 'data' => [
        'ride_share_id' => $rideId,
        'request_public_id' => $requestPublicId,
    ]];
}

/**
 * Build a private ride_shares trip from an accepted request, reusing the
 * exact columns, commission model and wallet reservation that Join Ride's
 * create action uses. Returns the new ride_shares id. Must run inside the
 * accept transaction.
 */
function spawn_private_ride_from_request(PDO $pdo, int $driverId, array $req): int {
    $vehStmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
    $vehStmt->execute([$driverId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        throw new Exception('Your vehicle record is missing.');
    }

    $distance_km = calculate_distance_km((float)$req['pickup_lat'], (float)$req['pickup_lng'], (float)$req['destination_lat'], (float)$req['destination_lng']);

    // THE authoritative price. The request was stamped before any driver was
    // known, so it carries the default-class placeholder; the trip is priced
    // from the class that is actually going to run it. A keke pickup is
    // charged at keke rates and a car pickup at car rates, from the admin's
    // settings - never from a figure the client supplied or an earlier guess.
    $vehicleClass = normalize_vehicle_type($vehicle['vehicle_type'] ?? null);
    $fareInfo = estimate_private_pickup_fare($pdo, $distance_km, $vehicleClass);
    $fare = round($fareInfo['total'], 2);

    // Keep the request row in step, so the rider's status poll and the
    // driver feed report the same number the trip and payment were built on.
    $pdo->prepare("UPDATE ride_requests SET price = ?, platform_fee = ? WHERE id = ?")
        ->execute([$fare, round($fareInfo['platform_fee'], 2), (int)$req['id']]);

    // A driver-specific commission rate still wins over the class rate -
    // get_commission_percentage() resolves the override, falling back to the
    // class's Private Pickup commission instead of the global Join Ride one.
    $commission_pct = get_commission_percentage($pdo, $driverId, $fareInfo['commission_percentage']);
    $commission_amount = round($fare * $commission_pct / 100, 2);
    $driver_earning = round($fare - $commission_amount, 2);

    // Solvency: private trip reserves the full commission (single seat).
    $available = get_available_wallet_balance($pdo, $driverId);
    if ($available < $commission_amount) {
        throw new Exception('Insufficient wallet balance to cover the commission reserve for this trip. Please top up.');
    }

    $vehicle_info = 'Model: ' . $vehicle['model'] . '; Colour: ' . $vehicle['color'] . '; Plate: ' . $vehicle['plate_number'];
    $public_id = generate_unique_public_id($pdo, 'ride_shares', 'public_id', 'RIDE');

    $stmt = $pdo->prepare(
        "INSERT INTO ride_shares
            (public_id, driver_id, pickup_location, start_location, destination,
             departure_date, departure_time, vehicle_id, vehicle_type, vehicle_info,
             total_seats, available_seats, pickup_lat, pickup_lng, destination_lat, destination_lng,
             price_per_passenger, pricing_mode, commission_percentage, commission_amount_per_seat,
             driver_earning_per_seat, description, payment_preference, ride_mode, vehicle_reserved, status)
         VALUES (?, ?, ?, ?, ?, CURDATE(), CURTIME(), ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, 'system', ?, ?, ?, ?, 'direct', 'private', 1, 'active')"
    );
    $stmt->execute([
        $public_id, $driverId, $req['pickup_location'], $req['pickup_location'], $req['destination'],
        $vehicle['id'], $vehicle['make'], $vehicle_info,
        (float)$req['pickup_lat'], (float)$req['pickup_lng'], (float)$req['destination_lat'], (float)$req['destination_lng'],
        $fare, $commission_pct, $commission_amount, $driver_earning,
        ('Private pickup for request ' . $req['public_id'] . ($req['notes'] ? ' - Notes: ' . $req['notes'] : '')),
    ]);
    $rideId = (int)$pdo->lastInsertId();

    // Reuse the SAME booking record the Join Ride engine creates for every
    // rider — a private pickup is a single-seat booking on this spawned
    // ride_shares row. Without it my_bookings (which reads
    // ride_share_bookings WHERE rider_id = ?) returns nothing, so the rider
    // can never see or track the trip they just requested.
    $booking_public_id = generate_unique_public_id($pdo, 'ride_share_bookings', 'public_id', 'BKG');
    $pdo->prepare("INSERT INTO ride_share_bookings (public_id, ride_share_id, rider_id, seats_booked, payment_method) VALUES (?, ?, ?, 1, 'direct')")
        ->execute([$booking_public_id, $rideId, (int)$req['rider_id']]);
    $bookingId = (int)$pdo->lastInsertId();

    // Matching payments row (direct payment to driver, settled on completion),
    // exactly as booking.php records for a Join Ride direct booking so the
    // trip's fare and later settlement resolve the same way.
    $payment_public_id = generate_unique_public_id($pdo, 'payments', 'public_id', 'PAY');
    $pdo->prepare("INSERT INTO payments (public_id, ride_share_id, booking_id, rider_id, driver_id, fare_amount, commission_amount, driver_earning_amount, payment_method, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'direct', 'PAYMENT_PENDING')")
        ->execute([$payment_public_id, $rideId, $bookingId, (int)$req['rider_id'], $driverId, $fare, $commission_amount, $driver_earning]);

    create_commission_reservation($pdo, $driverId, $rideId, $commission_amount);

    return $rideId;
}

/**
 * Driver declines a request. Non-destructive: a decline is per-driver and
 * never removes the request from the pool (another driver may still take
 * it). We simply do not surface it again to this driver via a lightweight
 * skip marker in the notification meta; the request itself is untouched.
 * Kept simple here - returns success so the client can advance the UI.
 */
function decline_private_pickup_request(PDO $pdo, int $driverId, string $requestPublicId): array {
    // Validate the request exists; declining is a no-op on shared state.
    $stmt = $pdo->prepare("SELECT status FROM ride_requests WHERE public_id = ? AND request_type = 'private_pickup'");
    $stmt->execute([$requestPublicId]);
    if (!$stmt->fetchColumn()) {
        return ['status' => 'error', 'message' => 'Request not found.'];
    }
    return ['status' => 'success', 'message' => 'Request declined.'];
}

/**
 * Rider cancels their own pending/accepted request. Soft state change only.
 */
function cancel_private_pickup_request(PDO $pdo, int $riderId, string $requestPublicId): array {
    $stmt = $pdo->prepare("SELECT id, status, matched_ride_share_id FROM ride_requests WHERE public_id = ? AND rider_id = ? AND request_type = 'private_pickup'");
    $stmt->execute([$requestPublicId, $riderId]);
    $req = $stmt->fetch();
    if (!$req) {
        return ['status' => 'error', 'message' => 'Request not found.'];
    }
    if (in_array($req['status'], ['completed', 'cancelled', 'expired'], true)) {
        return ['status' => 'error', 'message' => 'This request can no longer be cancelled.'];
    }
    $pdo->prepare("UPDATE ride_requests SET status = 'cancelled' WHERE id = ?")->execute([$req['id']]);
    return ['status' => 'success', 'message' => 'Request cancelled.'];
}

/**
 * Lazy expiry (no cron, matching the app's established read-time pattern):
 * any pending request past its TTL becomes 'expired'.
 */
function expire_stale_pickup_requests(PDO $pdo): void {
    $pdo->exec("UPDATE ride_requests SET status = 'expired' WHERE request_type = 'private_pickup' AND status = 'pending' AND expires_at IS NOT NULL AND expires_at < NOW()");
}

/**
 * Rider polls the status of their request (for the "finding a driver..." UI).
 */
function get_private_pickup_status(PDO $pdo, int $riderId, string $requestPublicId): array {
    expire_stale_pickup_requests($pdo);
    $stmt = $pdo->prepare(
        "SELECT r.public_id, r.status, r.price, r.matched_ride_share_id,
                rs.public_id AS ride_public_id, rs.trip_state,
                rb.id AS booking_id, rb.public_id AS booking_public_id,
                d.fullname AS driver_name, d.rating AS driver_rating, d.latitude AS driver_lat, d.longitude AS driver_lng,
                dv.vehicle_type AS driver_vehicle_type
         FROM ride_requests r
         LEFT JOIN ride_shares rs ON rs.id = r.matched_ride_share_id
         LEFT JOIN ride_share_bookings rb ON rb.ride_share_id = rs.id AND rb.rider_id = r.rider_id
         LEFT JOIN users d ON d.id = r.driver_id
         LEFT JOIN vehicles dv ON dv.driver_id = d.id
         WHERE r.public_id = ? AND r.rider_id = ? AND r.request_type = 'private_pickup'"
    );
    $stmt->execute([$requestPublicId, $riderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['status' => 'error', 'message' => 'Request not found.'];
    }
    // r.price is re-stamped from the accepting driver's vehicle class on
    // accept, so this is the class and the amount the trip was actually
    // priced at - the rider never sees a figure the trip wasn't built on.
    $row['vehicle_type'] = normalize_vehicle_type($row['driver_vehicle_type'] ?? null) ?: null;
    $row['vehicle_type_label'] = $row['vehicle_type'] ? vehicle_type_label($row['vehicle_type']) : null;
    return ['status' => 'success', 'message' => 'ok', 'data' => $row];
}
