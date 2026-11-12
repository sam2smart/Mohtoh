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
 *             destination_lat, destination_lng, notes, vehicle_type.
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

    // Passenger's vehicle-class choice (criterion #5). Private Pickup is a
    // whole-vehicle trip that a keke and a private car price and match
    // differently, so the passenger must pick which they want and the
    // request is stamped with it. Canonicalised so "Keke Napep"/"Saloon"
    // resolve to the stored class, then validated server-side against the
    // classes Private Pickup actually serves - the client is never trusted
    // to send a legal value.
    $vehicle_type = normalize_vehicle_type($data['vehicle_type'] ?? '');
    if (!is_private_pickup_vehicle_type($vehicle_type)) {
        return ['status' => 'error', 'message' => 'Please choose Private Car or Private Keke for your pickup.'];
    }

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
    // Priced at the CHOSEN class over the trip distance (pickup -> destination).
    // This is the passenger's authoritative opening fare - the single number
    // shown to both sides while searching (criterion #2). For a car this is the
    // final fare (car pricing is trip-only, criterion #9). For a keke it is a
    // preliminary figure: the keke's run to the pickup point (criterion #7)
    // cannot be known until a specific keke is matched, so the matched driver's
    // repositioning-inclusive fare goes back to the passenger for consent
    // (criterion #3) rather than silently overwriting this number.
    $fare = estimate_private_pickup_fare($pdo, $distance_km, $vehicle_type);
    $ttl  = (int) get_system_setting($pdo, 'private_pickup_request_ttl_seconds', 90);

    $public_id = generate_unique_public_id($pdo, 'ride_requests', 'public_id', 'REQ');
    $stmt = $pdo->prepare(
        "INSERT INTO ride_requests
            (public_id, rider_id, request_type, pickup_lat, pickup_lng, pickup_location,
             destination, destination_lat, destination_lng, notes,
             requested_service_category, requested_vehicle_type, price, platform_fee, status, expires_at)
         VALUES (?, ?, 'private_pickup', ?, ?, ?, ?, ?, ?, ?, 'Standard', ?, ?, ?, 'pending',
                 DATE_ADD(NOW(), INTERVAL ? SECOND))"
    );
    $platform_fee = round($fare['platform_fee'], 2);
    $stmt->execute([
        $public_id, $riderId, $pickup_lat, $pickup_lng, $pickup_location,
        $destination, $dest_lat, $dest_lng, ($notes !== '' ? $notes : null),
        $vehicle_type, round($fare['total'], 2), $platform_fee, $ttl,
    ]);
    $requestId = (int)$pdo->lastInsertId();

    // Fan out to nearby eligible drivers OF THE CHOSEN CLASS as notifications
    // (with a beep hint in meta the client sound-manager can act on).
    $matched = notify_nearby_drivers_of_request($pdo, $requestId);

    return ['status' => 'success', 'message' => 'Looking for a nearby driver...', 'data' => [
        'request_public_id' => $public_id,
        'vehicle_type'      => $vehicle_type,
        'vehicle_type_label'=> vehicle_type_label($vehicle_type),
        'estimated_fare'    => round($fare['total'], 2),
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

    // Only drivers of the class the passenger chose are alerted (criterion #5):
    // a car request never rings a keke driver and vice versa. The class filter
    // is applied in SQL by find_nearby_pickup_drivers(), so this is enforced at
    // the data layer, not merely in the copy of the notification.
    $requestedClass = normalize_vehicle_type($req['requested_vehicle_type'] ?? null);
    $drivers = find_nearby_pickup_drivers($pdo, (float)$req['pickup_lat'], (float)$req['pickup_lng'],
        is_private_pickup_vehicle_type($requestedClass) ? $requestedClass : null);
    if (!$drivers) {
        return 0;
    }

    // The alert quotes the passenger's opening fare (the trip-only figure the
    // passenger is looking at). For a car this is the final price; for a keke
    // it is the starting point the matched driver may revise upward for the
    // repositioning leg, with the passenger's consent (criterion #3). It is
    // deliberately the SAME number both sides see while searching (criterion #2).
    $openingFare = round((float)$req['price'], 2);

    $count = 0;
    foreach ($drivers as $d) {
        create_notification($pdo, (int)$d['id'], [
            'title' => 'New Private Pickup request',
            'message' => "Pickup: {$req['pickup_location']} -> {$req['destination']} - " . number_format($d['distance_km'], 1) . "km away - ~ N" . number_format($openingFare, 0),
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
                'fare' => $openingFare,
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
 *
 * $vehicleType, when a valid Private Pickup class, restricts the match to
 * drivers of exactly that class (criterion #5) - a car request must reach
 * only car drivers. When null the match spans every Private Pickup class
 * (used where the caller has no specific class in hand).
 */
function find_nearby_pickup_drivers(PDO $pdo, float $pickupLat, float $pickupLng, ?string $vehicleType = null): array {
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

    // Class restriction: a specific requested class narrows to that one class;
    // otherwise the whole Private Pickup set (named by eligibility.php so the
    // match set and the operational gate can never disagree). Bound as
    // placeholders rather than interpolated, keeping the query prepared-clean.
    $wantClass = normalize_vehicle_type($vehicleType);
    if (is_private_pickup_vehicle_type($wantClass)) {
        $vehicleTypes = [$wantClass];
    } else {
        $vehicleTypes = private_pickup_vehicle_types();
    }
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
    $driverLat = (float)$driver['latitude'];
    $driverLng = (float)$driver['longitude'];

    // Only requests whose passenger chose THIS driver's class are shown
    // (criterion #5). The equality bind excludes the pre-column NULL rows
    // (there are none in practice) rather than mis-serving them.
    $stmt = $pdo->prepare(
        "SELECT r.id, r.public_id, r.pickup_location, r.pickup_lat, r.pickup_lng,
                r.destination, r.destination_lat, r.destination_lng, r.notes,
                r.price, r.requested_vehicle_type, r.expires_at, r.created_at,
                u.fullname AS rider_name, u.rating AS rider_rating
         FROM ride_requests r
         JOIN users u ON u.id = r.rider_id
         WHERE r.request_type = 'private_pickup' AND r.status = 'pending'
           AND r.expires_at > NOW()
           AND r.requested_vehicle_type = ?
         ORDER BY r.created_at ASC"
    );
    $stmt->execute([$driverClass]);

    $ctx = ['lat' => $driverLat, 'lng' => $driverLng, 'location_fresh' => true];

    $requests = [];
    foreach ($stmt->fetchAll() as $r) {
        $dist = calculate_distance_km($driverLat, $driverLng, (float)$r['pickup_lat'], (float)$r['pickup_lng']);
        if ($dist > $radius) {
            continue;
        }
        // The exact fare THIS driver would be paid on accept. For a keke this
        // includes the repositioning leg (driver -> pickup) + trip (criterion
        // #7); for a car it is trip-only (criterion #9). Computed by the same
        // helper accept() uses, so the number shown here is the number honoured.
        $fareCtx = pp_compute_driver_fare($pdo, $r, $ctx, $driverClass);
        $passengerOffer = round((float)$r['price'], 2);
        $yourFare = $fareCtx['fare'];
        // If the driver's fare differs from what the passenger is looking at,
        // accepting cannot silently change the price - it must be PROPOSED and
        // the passenger must consent (criteria #2/#3/#4). The client uses this
        // flag to show "Propose N.." instead of a one-tap "Accept".
        $requiresConsent = abs($yourFare - $passengerOffer) >= 0.01;
        $requests[] = [
            'request_public_id' => $r['public_id'],
            'pickup_location'   => $r['pickup_location'],
            'destination'       => $r['destination'],
            'notes'             => $r['notes'],
            'distance_to_pickup_km' => $dist,
            'reposition_km'     => round($fareCtx['reposition_km'], 2),
            'trip_distance_km'  => round($fareCtx['trip_km'], 2),
            'passenger_offer'   => $passengerOffer,
            'your_fare'         => $yourFare,
            'requires_consent'  => $requiresConsent,
            'fare'              => $yourFare, // back-compat alias for existing client reads
            'vehicle_type'      => $driverClass,
            'vehicle_type_label'=> vehicle_type_label($driverClass),
            'rider_name'        => $r['rider_name'],
            'rider_rating'      => $r['rider_rating'],
            'expires_at'        => $r['expires_at'],
            'created_at'        => $r['created_at'],
        ];
    }
    // Outstanding proposals this driver is waiting on. Once a driver proposes
    // a fare the request leaves the pending pool (status='accepted',
    // proposed_fare set, no trip spawned yet), so it would otherwise vanish
    // from the driver's screen entirely. Surface it as a distinct, read-only
    // "waiting for the passenger" card so the driver sees the live negotiation
    // status without stale data (criteria #5/#8). It disappears on its own when
    // the rider accepts (→ trip, shows under Trips) or rejects (→ the request
    // reopens into the pending feed). This is additive; the pending `requests`
    // feed above is untouched.
    $propStmt = $pdo->prepare(
        "SELECT r.public_id, r.pickup_location, r.destination, r.price, r.proposed_fare,
                r.requested_vehicle_type, r.accepted_at,
                u.fullname AS rider_name
         FROM ride_requests r
         JOIN users u ON u.id = r.rider_id
         WHERE r.request_type = 'private_pickup' AND r.status = 'accepted'
           AND r.driver_id = ? AND r.proposed_fare IS NOT NULL
           AND r.matched_ride_share_id IS NULL
         ORDER BY r.accepted_at DESC"
    );
    $propStmt->execute([$driverId]);
    $proposals = [];
    foreach ($propStmt->fetchAll() as $p) {
        $pClass = normalize_vehicle_type($p['requested_vehicle_type'] ?? null);
        $proposals[] = [
            'request_public_id'  => $p['public_id'],
            'pickup_location'    => $p['pickup_location'],
            'destination'        => $p['destination'],
            'passenger_offer'    => round((float)$p['price'], 2),
            'proposed_fare'      => round((float)$p['proposed_fare'], 2),
            'vehicle_type'       => $pClass,
            'vehicle_type_label' => vehicle_type_label($pClass),
            'rider_name'         => $p['rider_name'],
        ];
    }

    return ['eligible' => true, 'reason' => '', 'requests' => $requests, 'proposals' => $proposals];
}

/**
 * The driver's class and freshest known location - everything needed to
 * price their run and to decide whether a keke's location is fresh enough
 * to include a repositioning leg (criteria #7/#8). Age is computed by MySQL
 * (TIMESTAMPDIFF) for the same PHP/MySQL timezone reason documented above.
 */
function pp_driver_context(PDO $pdo, int $driverId): array {
    $stmt = $pdo->prepare(
        "SELECT u.latitude, u.longitude,
                TIMESTAMPDIFF(SECOND, u.last_location_update, NOW()) AS age,
                v.vehicle_type
         FROM users u
         LEFT JOIN vehicles v ON v.driver_id = u.id
         WHERE u.id = ?"
    );
    $stmt->execute([$driverId]);
    $r = $stmt->fetch() ?: [];
    $ttl = (int) get_system_setting($pdo, 'driver_location_ttl_seconds', 120);
    $hasLoc = ($r['latitude'] ?? null) !== null && ($r['longitude'] ?? null) !== null && ($r['age'] ?? null) !== null;
    return [
        'class' => normalize_vehicle_type($r['vehicle_type'] ?? null),
        'lat' => $hasLoc ? (float)$r['latitude'] : null,
        'lng' => $hasLoc ? (float)$r['longitude'] : null,
        'location_fresh' => $hasLoc && (int)$r['age'] <= $ttl,
    ];
}

/**
 * Compute the fare THIS driver would be paid for a given request.
 *
 *   Keke Private Pickup = (driver's current location -> passenger pickup)
 *                       + (pickup -> destination), priced on the keke card.
 *   Car  Private Pickup = (pickup -> destination) only, on the car card.
 *
 * This is the ONLY place the four-lane distinction for Private Pickup is
 * applied (criteria #7/#9/#10); Join Ride pricing is never touched.
 * Returns fare (rounded total) plus the leg breakdown for transparency.
 */
function pp_compute_driver_fare(PDO $pdo, array $req, array $ctx, string $driverClass): array {
    $trip_km = calculate_distance_km((float)$req['pickup_lat'], (float)$req['pickup_lng'], (float)$req['destination_lat'], (float)$req['destination_lng']);
    $reposition_km = 0.0;
    if ($driverClass === 'keke' && ($ctx['lat'] ?? null) !== null && ($ctx['lng'] ?? null) !== null) {
        $reposition_km = calculate_distance_km((float)$ctx['lat'], (float)$ctx['lng'], (float)$req['pickup_lat'], (float)$req['pickup_lng']);
    }
    $billed_km = $reposition_km + $trip_km;
    $fareInfo = estimate_private_pickup_fare($pdo, $billed_km, $driverClass);
    return [
        'fare' => round($fareInfo['total'], 2),
        'trip_km' => $trip_km,
        'reposition_km' => $reposition_km,
        'billed_km' => $billed_km,
        'info' => $fareInfo,
    ];
}

/**
 * Core driver engagement. ATOMIC single-winner claim, then either:
 *   - FINALISE (fare unchanged from the passenger's offer -> agreement) by
 *     spawning the private ride_shares trip and moving it to PASSENGERS_JOINING
 *     (driver en route to the one passenger), or
 *   - PROPOSE (fare changed) by recording proposed_fare and notifying the
 *     passenger, WITHOUT spawning a trip and WITHOUT touching price.
 *
 * $proposedAmount null = "accept" (only finalises at the unchanged offer, else
 * auto-proposes the computed fare); a value = "propose that amount" (finalises
 * only if it equals the offer). A changed price can NEVER be made final by the
 * driver alone - the passenger must consent (criteria #2/#3/#4), enforced here
 * in the backend rather than by hiding a button.
 */
function pp_engage_request(PDO $pdo, int $driverId, string $requestPublicId, ?float $proposedAmount): array {
    // Re-validate eligibility at the moment of engagement (revocation-safe).
    $elig = check_driver_eligibility($pdo, $driverId, 'private_pickup');
    if (!$elig['eligible']) {
        return ['status' => 'error', 'message' => $elig['reason']];
    }

    $ctx = pp_driver_context($pdo, $driverId);
    $driverClass = $ctx['class'];
    if (!is_private_pickup_vehicle_type($driverClass)) {
        return ['status' => 'error', 'message' => 'Your vehicle is not eligible for Private Pickup.'];
    }

    $pdo->beginTransaction();
    try {
        // Lock the request, then claim only if still pending. Expiry is judged
        // by MySQL against its own NOW() - never PHP time() - to avoid the
        // PHP/MySQL timezone offset expiring live requests.
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

        // Class must match what the passenger asked for (criterion #5),
        // enforced in the backend - a car driver can never take a keke request
        // or vice versa, even by calling this endpoint directly.
        $reqClass = normalize_vehicle_type($req['requested_vehicle_type'] ?? null);
        if ($reqClass !== '' && $reqClass !== $driverClass) {
            throw new Exception('This request is for a ' . vehicle_type_label($reqClass) . ' and cannot be taken with your vehicle.');
        }

        // A keke's fare depends on its current location, so that location must
        // be fresh; refuse rather than price a repositioning leg off a stale
        // fix (criteria #8/#18 - never fabricate location). Cars are trip-only
        // and location-independent, so freshness is not required to price them.
        if ($driverClass === 'keke' && !$ctx['location_fresh']) {
            throw new Exception('Your location is out of date. Refresh your location before taking a Keke pickup.');
        }

        $fareCtx = pp_compute_driver_fare($pdo, $req, $ctx, $driverClass);
        $offer = round((float)$req['price'], 2);
        $fare  = $proposedAmount !== null ? round($proposedAmount, 2) : $fareCtx['fare'];
        if ($fare <= 0) {
            throw new Exception('That fare is not valid.');
        }

        // Same number on both sides -> finalise. Any difference -> proposal.
        if (abs($fare - $offer) < 0.01) {
            $claim = $pdo->prepare("UPDATE ride_requests SET status = 'accepted', driver_id = ?, accepted_at = NOW(), proposed_fare = NULL WHERE id = ? AND status = 'pending'");
            $claim->execute([$driverId, $req['id']]);
            if ($claim->rowCount() !== 1) {
                throw new Exception('This request has already been taken.');
            }

            // Spawn at the agreed price - NO re-pricing (criteria #2/#14).
            $rideId = spawn_private_ride_from_request($pdo, $driverId, $req, $offer, $driverClass);
            $pdo->prepare("UPDATE ride_requests SET matched_ride_share_id = ?, status = 'in_progress' WHERE id = ?")
                ->execute([$rideId, $req['id']]);
            $pdo->commit();

            // En route to pickup. PUBLISHED is a Join Ride broadcast state that
            // would spam nearby riders; a private trip goes straight to
            // PASSENGERS_JOINING (driver heading to the single passenger). The
            // driver marks arrival later, moving it to PASSENGERS_ASSEMBLED.
            transition_trip_state($pdo, $rideId, 'PASSENGERS_JOINING', 'MANUAL_DRIVER_ACTION');

            create_notification($pdo, (int)$req['rider_id'], [
                'title' => 'Driver on the way',
                'message' => 'A driver accepted your private pickup and is heading to you.',
                'notification_type' => 'booking', 'priority' => 'high',
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);

            return ['status' => 'success', 'message' => 'Pickup accepted.', 'data' => [
                'outcome' => 'accepted',
                'ride_share_id' => $rideId,
                'request_public_id' => $requestPublicId,
                'fare' => $offer,
            ]];
        }

        // PROPOSAL: claim the request for this driver and record the counter
        // offer WITHOUT spawning a trip. `price` (what the passenger currently
        // agrees to) is deliberately left untouched; proposed_fare carries the
        // new number until the passenger consents (criterion #3).
        $claim = $pdo->prepare("UPDATE ride_requests SET status = 'accepted', driver_id = ?, accepted_at = NOW(), proposed_fare = ? WHERE id = ? AND status = 'pending'");
        $claim->execute([$driverId, $fare, $req['id']]);
        if ($claim->rowCount() !== 1) {
            throw new Exception('This request has already been taken.');
        }
        $pdo->commit();

        create_notification($pdo, (int)$req['rider_id'], [
            'title' => 'Driver proposed a fare',
            'message' => 'A driver offered to take your pickup for N' . number_format($fare, 0) . '. Review and confirm.',
            'notification_type' => 'booking', 'priority' => 'high',
            'action' => 'open_pickup_proposal', 'related_table' => 'ride_requests',
            'related_public_id' => $requestPublicId, 'link' => 'private-pickup.php',
            'meta' => ['sound' => 'info', 'request_public_id' => $requestPublicId, 'proposed_fare' => $fare],
        ]);

        return ['status' => 'success', 'message' => 'Fare proposed. Waiting for the passenger to confirm.', 'data' => [
            'outcome' => 'proposed',
            'request_public_id' => $requestPublicId,
            'proposed_fare' => $fare,
            'passenger_offer' => $offer,
        ]];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Driver accepts a request at the passenger's current fare. Finalises only if
 * the driver's computed fare equals the passenger's offer (car, or a keke
 * already at the pickup point); if repositioning makes it differ, this cannot
 * silently finalise and instead becomes a proposal the passenger must confirm.
 */
function accept_private_pickup_request(PDO $pdo, int $driverId, string $requestPublicId): array {
    return pp_engage_request($pdo, $driverId, $requestPublicId, null);
}

/**
 * Driver proposes a fare (default = their computed fare, or a custom amount).
 * Never final on its own: the passenger must consent unless the proposed
 * number happens to equal the passenger's current offer (criteria #3/#4).
 */
function propose_private_pickup_fare(PDO $pdo, int $driverId, string $requestPublicId, ?float $amount = null): array {
    if ($amount !== null && $amount <= 0) {
        return ['status' => 'error', 'message' => 'Enter a valid fare to propose.'];
    }
    return pp_engage_request($pdo, $driverId, $requestPublicId, $amount);
}

/**
 * Passenger responds to a driver's proposed fare.
 *   accept -> the proposed fare BECOMES the authoritative price and the trip
 *             is spawned at exactly that number (criteria #2/#14), then moved
 *             to PASSENGERS_JOINING.
 *   reject -> the request re-opens (status back to 'pending') with driver_id,
 *             proposed_fare and accepted_at cleared and a fresh TTL, WITHOUT
 *             corrupting the original price (criterion #3). Another driver -
 *             or the same one - can offer again.
 */
function respond_to_pickup_proposal(PDO $pdo, int $riderId, string $requestPublicId, bool $accept): array {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM ride_requests
             WHERE public_id = ? AND rider_id = ? AND request_type = 'private_pickup' FOR UPDATE"
        );
        $stmt->execute([$requestPublicId, $riderId]);
        $req = $stmt->fetch();
        if (!$req) {
            throw new Exception('Request not found.');
        }
        if ($req['status'] !== 'accepted' || $req['proposed_fare'] === null) {
            throw new Exception('There is no fare waiting for your confirmation.');
        }
        $driverId = (int)$req['driver_id'];

        if ($accept) {
            $agreed = round((float)$req['proposed_fare'], 2);
            $driverClass = pp_driver_context($pdo, $driverId)['class'];
            if (!is_private_pickup_vehicle_type($driverClass)) {
                throw new Exception('The driver is no longer available.');
            }
            // The proposed fare becomes THE price; then spawn at that number.
            $pdo->prepare("UPDATE ride_requests SET price = ?, proposed_fare = NULL WHERE id = ?")
                ->execute([$agreed, (int)$req['id']]);
            $req['price'] = $agreed; // spawn reads the agreed number from the row
            $rideId = spawn_private_ride_from_request($pdo, $driverId, $req, $agreed, $driverClass);
            $pdo->prepare("UPDATE ride_requests SET matched_ride_share_id = ?, status = 'in_progress' WHERE id = ?")
                ->execute([$rideId, (int)$req['id']]);
            $pdo->commit();

            transition_trip_state($pdo, $rideId, 'PASSENGERS_JOINING', 'MANUAL_PASSENGER_CONSENT');
            create_notification($pdo, $driverId, [
                'title' => 'Passenger accepted your fare',
                'message' => 'Your proposed fare of N' . number_format($agreed, 0) . ' was accepted. Head to the pickup point.',
                'notification_type' => 'booking', 'priority' => 'high',
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares',
            ]);
            return ['status' => 'success', 'message' => 'Fare confirmed. Your driver is on the way.', 'data' => [
                'outcome' => 'accepted', 'ride_share_id' => $rideId, 'fare' => $agreed,
            ]];
        }

        // Reject: reopen with a fresh TTL, price untouched.
        $ttl = (int) get_system_setting($pdo, 'private_pickup_request_ttl_seconds', 90);
        $pdo->prepare(
            "UPDATE ride_requests
             SET status = 'pending', driver_id = NULL, proposed_fare = NULL, accepted_at = NULL,
                 expires_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE id = ?"
        )->execute([$ttl, (int)$req['id']]);
        $pdo->commit();

        if ($driverId) {
            create_notification($pdo, $driverId, [
                'title' => 'Passenger declined the fare',
                'message' => 'The passenger declined your proposed fare. The request is open again.',
                'notification_type' => 'booking', 'priority' => 'normal',
            ]);
        }
        return ['status' => 'success', 'message' => "Fare declined. We'll keep looking for a driver.", 'data' => [
            'outcome' => 'rejected',
            'expires_in_seconds' => $ttl, // fresh TTL so the rider's countdown resumes correctly
        ]];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

/**
 * Driver marks that they have physically arrived at the pickup point. Moves a
 * PRIVATE trip from PASSENGERS_JOINING to PASSENGERS_ASSEMBLED, which the trip
 * engine dispatches as an "arrived" notification to the passenger (criterion
 * #11). Idempotent; only the assigned driver of a private trip can call it and
 * it never starts the trip - that still needs the passenger's confirmation.
 */
function mark_private_pickup_arrived(PDO $pdo, int $driverId, int $rideId): array {
    $stmt = $pdo->prepare("SELECT id, driver_id, ride_mode, trip_state, status FROM ride_shares WHERE id = ?");
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch();
    if (!$ride) {
        return ['status' => 'error', 'message' => 'Trip not found.'];
    }
    if ((int)$ride['driver_id'] !== $driverId) {
        return ['status' => 'error', 'message' => 'This is not your trip.'];
    }
    if ($ride['ride_mode'] !== 'private') {
        return ['status' => 'error', 'message' => 'This action is only for private pickups.'];
    }
    if ($ride['status'] === 'in_progress') {
        return ['status' => 'error', 'message' => 'The trip has already started.'];
    }
    if (!in_array($ride['trip_state'], ['PASSENGERS_JOINING', 'PASSENGERS_ASSEMBLED'], true)) {
        return ['status' => 'error', 'message' => 'You can only mark arrival once you are on the way to the passenger.'];
    }
    if ($ride['trip_state'] !== 'PASSENGERS_ASSEMBLED') {
        transition_trip_state($pdo, $rideId, 'PASSENGERS_ASSEMBLED', 'MANUAL_DRIVER_ACTION');
    }
    return ['status' => 'success', 'message' => 'Passenger notified that you have arrived.', 'data' => ['ride_share_id' => $rideId]];
}

/**
 * Passenger confirms the driver has actually arrived. Records
 * passenger_arrival_confirmed_at, which is the fact the hard start-trip gate
 * (criterion #12) requires before the driver may start. Only valid once the
 * driver has marked arrival (trip_state = PASSENGERS_ASSEMBLED).
 */
function confirm_private_pickup_arrival(PDO $pdo, int $riderId, string $requestPublicId): array {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.matched_ride_share_id, rs.trip_state, rs.driver_id, rs.status,
                rs.passenger_arrival_confirmed_at
         FROM ride_requests r
         JOIN ride_shares rs ON rs.id = r.matched_ride_share_id
         WHERE r.public_id = ? AND r.rider_id = ? AND r.request_type = 'private_pickup'"
    );
    $stmt->execute([$requestPublicId, $riderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['status' => 'error', 'message' => 'Trip not found.'];
    }
    if ($row['passenger_arrival_confirmed_at'] !== null) {
        return ['status' => 'success', 'message' => 'Arrival already confirmed.', 'data' => ['already' => true, 'ride_share_id' => (int)$row['matched_ride_share_id']]];
    }
    if ($row['trip_state'] !== 'PASSENGERS_ASSEMBLED') {
        return ['status' => 'error', 'message' => 'Your driver has not marked arrival yet.'];
    }
    $pdo->prepare("UPDATE ride_shares SET passenger_arrival_confirmed_at = NOW() WHERE id = ?")
        ->execute([(int)$row['matched_ride_share_id']]);

    if (!empty($row['driver_id'])) {
        create_notification($pdo, (int)$row['driver_id'], [
            'title' => 'Passenger confirmed arrival',
            'message' => 'Your passenger confirmed you have arrived. You can start the trip.',
            'notification_type' => 'booking', 'priority' => 'high',
            'action' => 'open_trip', 'action_id' => (int)$row['matched_ride_share_id'], 'related_table' => 'ride_shares',
        ]);
    }
    return ['status' => 'success', 'message' => 'Arrival confirmed. Your driver can now start the trip.', 'data' => ['ride_share_id' => (int)$row['matched_ride_share_id']]];
}

/**
 * Build a private ride_shares trip from an accepted request, reusing the
 * exact columns, commission model and wallet reservation that Join Ride's
 * create action uses. Returns the new ride_shares id. Must run inside the
 * caller's transaction.
 *
 * $agreedFare is THE authoritative fare both sides settled on - passed in by
 * the caller (the passenger's unchanged offer, or a proposed fare the
 * passenger consented to). This function does NOT re-price and does NOT
 * overwrite ride_requests.price: the number the passenger agreed to is the
 * number the trip, payment, commission and receipt are all built on
 * (criteria #2/#14). $vehicleClass selects only the commission rate card.
 */
function spawn_private_ride_from_request(PDO $pdo, int $driverId, array $req, float $agreedFare, string $vehicleClass): int {
    $vehStmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
    $vehStmt->execute([$driverId]);
    $vehicle = $vehStmt->fetch();
    if (!$vehicle) {
        throw new Exception('Your vehicle record is missing.');
    }

    $fare = round($agreedFare, 2);
    if ($fare <= 0) {
        throw new Exception('The agreed fare is not valid.');
    }

    // Commission comes from the class's Private Pickup rate card, then any
    // driver-specific override (get_commission_percentage) still wins - the
    // same resolution order used everywhere else, never the global Join Ride
    // rate by accident.
    $card = private_pickup_rate_card($pdo, $vehicleClass);
    $commission_pct = get_commission_percentage($pdo, $driverId, $card['commission_percentage']);
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
        "SELECT r.public_id, r.status, r.price, r.proposed_fare, r.requested_vehicle_type,
                r.matched_ride_share_id,
                rs.public_id AS ride_public_id, rs.trip_state, rs.driver_id AS ride_driver_id,
                rs.passenger_arrival_confirmed_at,
                rb.id AS booking_id, rb.public_id AS booking_public_id,
                d.fullname AS driver_name, d.rating AS driver_rating, d.latitude AS driver_lat, d.longitude AS driver_lng,
                dv.vehicle_type AS driver_vehicle_type, dv.make AS driver_vehicle_make,
                dv.model AS driver_vehicle_model, dv.color AS driver_vehicle_color, dv.plate_number AS driver_plate
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

    // The vehicle class is the one the PASSENGER chose (and the trip runs as),
    // not merely whatever the matched driver drives - they are the same by the
    // class-restricted match, but requested_vehicle_type is the source of truth.
    $reqClass = normalize_vehicle_type($row['requested_vehicle_type'] ?? null);
    $row['vehicle_type'] = $reqClass !== '' ? $reqClass : (normalize_vehicle_type($row['driver_vehicle_type'] ?? null) ?: null);
    $row['vehicle_type_label'] = $row['vehicle_type'] ? vehicle_type_label($row['vehicle_type']) : null;

    // Normalise the fare fields the client renders. `price` is the single
    // authoritative current fare (criterion #2); `proposed_fare` is a pending
    // driver counter-offer awaiting this passenger's consent (NULL if none).
    $row['current_fare'] = $row['price'] !== null ? round((float)$row['price'], 2) : null;
    $row['proposed_fare'] = $row['proposed_fare'] !== null ? round((float)$row['proposed_fare'], 2) : null;
    $row['awaiting_consent'] = ($row['status'] === 'accepted' && $row['proposed_fare'] !== null);

    // Arrival control (criteria #11/#12): driver_arrived once the trip reaches
    // PASSENGERS_ASSEMBLED; passenger_confirmed_arrival once this passenger has
    // confirmed. The client shows the "Confirm my driver has arrived" button
    // exactly when arrived && !confirmed.
    $row['driver_arrived'] = ($row['trip_state'] === 'PASSENGERS_ASSEMBLED');
    $row['passenger_confirmed_arrival'] = ($row['passenger_arrival_confirmed_at'] !== null);

    return ['status' => 'success', 'message' => 'ok', 'data' => $row];
}
