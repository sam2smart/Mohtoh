<?php
// database/test_private_pickup_pricing.php
//
// Proves Admin-managed Private Pickup pricing is real, per-vehicle, and
// independent - over HTTP, through the same endpoints the panel and the apps
// use:
//
//   admin  : api/pricing-admin.php          (save keke / car rate cards)
//   rider  : api/private-pickup.php estimate (what the rider is quoted)
//   driver : api/private-pickup.php feed     (what each driver is offered)
//   engine : accept -> spawn, the fare the trip and payment are built on
//
// It checks:
//   A  admin can save a keke rate card, and the keke price follows it
//   B  admin can save a car rate card, and the car price follows it
//   C  changing keke leaves car untouched, and vice versa (independence)
//   D  minimum / maximum clamps apply per class
//   E  the passenger's CHOSEN-class fare is stamped at creation and flows
//      UNCHANGED into the trip + payment - a car finalises trip-only, a keke's
//      repositioning-inclusive fare reaches the trip only via passenger consent,
//      and neither path silently re-prices what the passenger agreed to (#2/#3)
//   F  Join Ride pricing is unchanged by any of it
//
// Every setting it touches is restored in the teardown, and every row it
// creates is deleted.
//
//   php database/test_private_pickup_pricing.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

// Target the app this test file physically lives in - so running it from a git
// worktree hits THAT worktree's endpoints (Apache serves everything under
// htdocs), not a stale main deployment that predates the fix. Override with
// MOHTOH_BASE_URL for any other host.
$BASE = getenv('MOHTOH_BASE_URL') ?: (function () {
    $appRoot = str_replace('\\', '/', dirname(__DIR__));   // <docroot>/.../<app>
    $pos = stripos($appRoot, '/htdocs/');
    if ($pos === false) { return 'http://localhost/Mostmerit/merit_drives%20-%20Copy.v1'; }
    $rel = substr($appRoot, $pos + strlen('/htdocs/'));    // path under the web root
    return 'http://localhost/' . implode('/', array_map('rawurlencode', explode('/', $rel)));
})();
$SESSION_DIR = ini_get('session.save_path') ?: sys_get_temp_dir();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): bool {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  --> $detail" : '') . "\n"; }
    return $ok;
}
function near(float $a, float $b): bool { return abs($a - $b) < 0.02; }

function make_session(array $user): string {
    global $SESSION_DIR;
    $sid = 'ppprice' . bin2hex(random_bytes(10));
    $data = '';
    foreach ([
        'user_id' => (int)$user['id'], 'user_type' => $user['user_type'],
        'fullname' => $user['fullname'], 'email' => $user['email'],
    ] as $k => $v) {
        $data .= $k . '|' . serialize($v);
    }
    file_put_contents(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

function call(string $sid, string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
        CURLOPT_COOKIE => 'PHPSESSID=' . $sid, CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? ['status' => 'parse_error', 'raw' => substr($body, 0, 400)];
}

/** Save pricing settings the way the admin panel does. */
function admin_save(string $sid, array $settings): array {
    global $BASE;
    return call($sid, "$BASE/api/pricing-admin.php", ['settings' => $settings]);
}

/** The rider's quoted price for one class, from the estimate endpoint. */
function quoted_for(string $sid, array $coords, string $class): ?float {
    global $BASE;
    $q = http_build_query([
        'action' => 'estimate',
        'pickup_lat' => $coords['plat'], 'pickup_lng' => $coords['plng'],
        'destination_lat' => $coords['dlat'], 'destination_lng' => $coords['dlng'],
    ]);
    $res = call($sid, "$BASE/api/private-pickup.php?$q");
    foreach ($res['data']['fare_options'] ?? [] as $o) {
        if (($o['vehicle_type'] ?? '') === $class) return (float)$o['total'];
    }
    return null;
}

// PRICING keys this test writes, so teardown can put every one of them back
// exactly as found (including "row did not exist").
$TOUCHED = [
    'private_pickup_keke_base_fare', 'private_pickup_keke_price_per_km',
    'private_pickup_keke_minimum_fare', 'private_pickup_keke_maximum_fare',
    'private_pickup_keke_commission_percentage',
    'private_pickup_car_base_fare', 'private_pickup_car_price_per_km',
    'private_pickup_car_minimum_fare', 'private_pickup_car_maximum_fare',
    'private_pickup_car_commission_percentage',
    'private_pickup_base_fare', 'private_pickup_price_per_km', 'private_booking_fee',
    'base_fare', 'price_per_km', 'commission_percentage',
];

echo "=== Private Pickup pricing: admin-managed, per-vehicle, independent ===\n";
echo "base: $BASE\n\n";

$sessions = [];
$createdUsers = [];
$original = [];
$requestIds = [];

try {
    // ---- snapshot every setting we will write ----
    foreach ($TOUCHED as $key) {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $v = $stmt->fetchColumn();
        $original[$key] = $v === false ? null : $v;
    }

    // pricing-admin.php is require_super_admin() gated, so only a super admin
    // can drive this test - the same rule that protects it in production.
    $superAdmin = $pdo->query(
        "SELECT id, user_type, fullname, email FROM users
         WHERE user_type = 'admin' AND is_super_admin = 1 ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$superAdmin) { echo "No super admin account to save settings with. Aborting.\n"; exit(1); }
    $sessions['admin'] = make_session($superAdmin);
    echo "admin: {$superAdmin['email']} (id {$superAdmin['id']})\n";

    // The rider we quote estimates for in A-D is a throwaway created below with
    // the other test users (see $mkRider), so this test never depends on there
    // being a pre-existing verified rider in the database.

    // A 10 km-ish leg, fixed, so every assertion below compares like with like.
    $coords = ['plat' => 6.5244, 'plng' => 3.3792, 'dlat' => 6.6018, 'dlng' => 3.3515];
    $distance = calculate_distance_km($coords['plat'], $coords['plng'], $coords['dlat'], $coords['dlng']);
    $booking = (float) get_pricing_setting($pdo, 'private_booking_fee', 100);

    // Join Ride's recommended price BEFORE anything is touched. Re-read at the
    // end to prove Private Pickup's settings never leak into Join Ride.
    $joinRideBefore = calculate_recommended_seat_price($pdo, $distance, 4);

    // ---- throwaway riders + one keke and one car driver, both in range ----
    $hash = password_hash('PpPrice#2026', PASSWORD_DEFAULT);
    $now  = $pdo->query("SELECT NOW()")->fetchColumn();   // MySQL's clock, not PHP's

    $mkRider = function (string $name) use ($pdo, $hash, &$createdUsers) {
        $email = 'ppprice-r-' . uniqid() . '@t.co';
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
                email_verified, phone_verified, is_verified)
             VALUES (?, ?, ?, ?, ?, 'rider', 1, 1, 1)"
        )->execute(['ppp-' . uniqid(), $name, 'ppp-' . uniqid(), $email, $hash]);
        $id = (int)$pdo->lastInsertId();
        $createdUsers[] = $id;
        return ['id' => $id, 'user_type' => 'rider', 'fullname' => $name, 'email' => $email];
    };

    $mkDriver = function (string $name, string $vtype, float $lat, float $lng) use ($pdo, $hash, $now, &$createdUsers) {
        $email = 'ppprice-d-' . uniqid() . '@t.co';
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
                email_verified, phone_verified, is_verified, is_online, is_keke_driver,
                private_pickup_enabled, pickup_available_today, pickup_availability_date,
                latitude, longitude, last_location_update, wallet_balance)
             VALUES (?, ?, ?, ?, ?, 'driver', 1, 1, 1, 1, ?, 1, 1, ?, ?, ?, ?, 500000)"
        )->execute([
            'ppp-' . uniqid(), $name, 'ppp-' . uniqid(), $email, $hash,
            // pickup_availability_date MUST be seeded with PHP date('Y-m-d'),
            // NOT MySQL CURDATE(): eligibility.php:127 and the driver feed both
            // compare this column against PHP date('Y-m-d'). When the PHP and
            // MySQL clocks straddle midnight (they can differ by hours here)
            // CURDATE() != PHP today, the driver reads as "unavailable", and the
            // whole accept/propose flow is refused. Match the code under test.
            $vtype === 'keke' ? 1 : 0, date('Y-m-d'), $lat, $lng, $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        $createdUsers[] = $id;
        $pdo->prepare(
            "INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number,
                seats, passenger_capacity, vehicle_type, status, verification_status)
             VALUES (?, ?, ?, 'Test', 2022, 'Yellow', ?, ?, ?, ?, 'approved', 'verified')"
        )->execute([
            generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH'), $id,
            $vtype === 'keke' ? 'Bajaj' : 'Toyota',
            'PPP-' . substr(uniqid(), -6),
            $vtype === 'keke' ? 3 : 4, $vtype === 'keke' ? 3 : 4, $vtype,
        ]);
        return ['id' => $id, 'user_type' => 'driver', 'fullname' => $name, 'email' => $email];
    };

    $riderKeke = $mkRider('PPPrice Rider Keke');
    $riderCar  = $mkRider('PPPrice Rider Car');
    $riderQuote = $mkRider('PPPrice Rider Quote');
    $sessions['rider_keke'] = make_session($riderKeke);
    $sessions['rider_car']  = make_session($riderCar);
    $sessions['rider']      = make_session($riderQuote);
    echo "rider (quotes): {$riderQuote['email']} (id {$riderQuote['id']})\n";

    $kekeDriver = $mkDriver('PPPrice Keke Driver', 'keke', $coords['plat'] + (3 / 111.0), $coords['plng']);
    $carDriver  = $mkDriver('PPPrice Car Driver',  'car',  $coords['plat'] + (4 / 111.0), $coords['plng']);
    $sessions['keke_driver'] = make_session($kekeDriver);
    $sessions['car_driver']  = make_session($carDriver);

    printf("\ntrip distance: %.3f km, booking fee: %s\n\n", $distance, $booking);

    // =================================================================
    echo "---- A. admin saves a KEKE rate card, keke price follows ----\n";
    // =================================================================
    $res = admin_save($sessions['admin'], [
        'private_pickup_keke_base_fare'    => '250',
        'private_pickup_keke_price_per_km' => '90',
        'private_pickup_keke_minimum_fare' => '0',
        'private_pickup_keke_maximum_fare' => '0',
    ]);
    check('admin can save keke Private Pickup pricing', ($res['status'] ?? '') === 'success', json_encode($res));

    $expectKeke = round(250 + ($distance * 90) + $booking, 2);
    $gotKeke = quoted_for($sessions['rider'], $coords, 'keke');
    check('rider quote for keke uses the saved keke rate',
        $gotKeke !== null && near($gotKeke, $expectKeke), "expected $expectKeke got " . var_export($gotKeke, true));

    // ================================================================
    echo "\n---- B. admin saves a CAR rate card, car price follows ----\n";
    // ================================================================
    $res = admin_save($sessions['admin'], [
        'private_pickup_car_base_fare'    => '600',
        'private_pickup_car_price_per_km' => '210',
        'private_pickup_car_minimum_fare' => '0',
        'private_pickup_car_maximum_fare' => '0',
    ]);
    check('admin can save car Private Pickup pricing', ($res['status'] ?? '') === 'success', json_encode($res));

    $expectCar = round(600 + ($distance * 210) + $booking, 2);
    $gotCar = quoted_for($sessions['rider'], $coords, 'car');
    check('rider quote for car uses the saved car rate',
        $gotCar !== null && near($gotCar, $expectCar), "expected $expectCar got " . var_export($gotCar, true));

    check('keke and car are NOT the same price', $gotKeke !== null && $gotCar !== null && !near($gotKeke, $gotCar),
        "keke=$gotKeke car=$gotCar");

    // =========================================================
    echo "\n---- C. the two rate cards are independent ----\n";
    // =========================================================
    // Move keke only. Car must not budge.
    $carBefore = quoted_for($sessions['rider'], $coords, 'car');
    admin_save($sessions['admin'], ['private_pickup_keke_price_per_km' => '95']);
    $kekeAfter = quoted_for($sessions['rider'], $coords, 'keke');
    $carAfter  = quoted_for($sessions['rider'], $coords, 'car');
    check('changing keke per-km moved the keke price',
        $kekeAfter !== null && near($kekeAfter, round(250 + ($distance * 95) + $booking, 2)), "got " . var_export($kekeAfter, true));
    check('changing keke left the car price untouched',
        $carAfter !== null && near($carAfter, (float)$carBefore), "before=$carBefore after=$carAfter");

    // Move car only. Keke must not budge.
    $kekeBefore = $kekeAfter;
    admin_save($sessions['admin'], ['private_pickup_car_price_per_km' => '230']);
    $carAfter2  = quoted_for($sessions['rider'], $coords, 'car');
    $kekeAfter2 = quoted_for($sessions['rider'], $coords, 'keke');
    check('changing car per-km moved the car price',
        $carAfter2 !== null && near($carAfter2, round(600 + ($distance * 230) + $booking, 2)), "got " . var_export($carAfter2, true));
    check('changing car left the keke price untouched',
        $kekeAfter2 !== null && near($kekeAfter2, (float)$kekeBefore), "before=$kekeBefore after=$kekeAfter2");

    // ==================================================
    echo "\n---- D. per-class minimum / maximum clamps ----\n";
    // ==================================================
    admin_save($sessions['admin'], ['private_pickup_keke_minimum_fare' => '99999']);
    $clamped = quoted_for($sessions['rider'], $coords, 'keke');
    check('keke minimum fare raises the keke price', $clamped !== null && near($clamped, 99999.0), "got " . var_export($clamped, true));
    $carUnclamped = quoted_for($sessions['rider'], $coords, 'car');
    check("keke's minimum did not touch car", $carUnclamped !== null && near($carUnclamped, (float)$carAfter2), "got " . var_export($carUnclamped, true));
    admin_save($sessions['admin'], ['private_pickup_keke_minimum_fare' => '0']);

    admin_save($sessions['admin'], ['private_pickup_car_maximum_fare' => '150']);
    $capped = quoted_for($sessions['rider'], $coords, 'car');
    check('car maximum fare caps the car price', $capped !== null && near($capped, 150.0), "got " . var_export($capped, true));
    $kekeUncapped = quoted_for($sessions['rider'], $coords, 'keke');
    check("car's maximum did not touch keke", $kekeUncapped !== null && near($kekeUncapped, round(250 + ($distance * 95) + $booking, 2)), "got " . var_export($kekeUncapped, true));
    admin_save($sessions['admin'], ['private_pickup_car_maximum_fare' => '0']);

    // ==================================================================
    echo "\n---- E. chosen-class fare flows unchanged into trip + payment (#2/#3) ----\n";
    // ==================================================================
    // Live rate cards now: keke 250 + 95/km, car 600 + 230/km, clamps off.
    $expectKekeTripOnly = round(250 + ($distance * 95) + $booking, 2);
    $expectCarTripOnly  = round(600 + ($distance * 230) + $booking, 2);

    // Create a request for a SPECIFIC vehicle class (the passenger's choice,
    // criterion #5 - the backend now requires it).
    $mkRequest = function (string $riderSession, string $vehicleType) use ($coords, &$requestIds) {
        global $BASE;
        $res = call($riderSession, "$BASE/api/private-pickup.php", [
            'action' => 'create',
            'pickup_location' => 'PPPrice pickup',
            'pickup_lat' => $coords['plat'], 'pickup_lng' => $coords['plng'],
            'destination' => 'PPPrice destination',
            'destination_lat' => $coords['dlat'], 'destination_lng' => $coords['dlng'],
            'notes' => 'pricing test', 'vehicle_type' => $vehicleType,
        ]);
        $id = $res['data']['request_public_id'] ?? null;
        if ($id) { $requestIds[] = $id; }
        return [$res, $id];
    };

    // What THIS driver is offered for THIS request, from their own feed.
    $feedRow = function (string $driverSession, string $publicId) {
        global $BASE;
        $res = call($driverSession, "$BASE/api/private-pickup.php?action=feed");
        foreach ($res['data']['requests'] ?? [] as $r) {
            if (($r['request_public_id'] ?? '') === $publicId) { return $r; }
        }
        return null;
    };

    // The money rows the trip is actually built on, plus the negotiation state.
    $dbFare = function (string $publicId) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT r.price AS request_price, r.proposed_fare, r.status AS req_status,
                    r.matched_ride_share_id, rs.price_per_passenger, p.fare_amount
             FROM ride_requests r
             LEFT JOIN ride_shares rs ON rs.id = r.matched_ride_share_id
             LEFT JOIN payments p ON p.ride_share_id = rs.id
             WHERE r.public_id = ?"
        );
        $stmt->execute([$publicId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    };

    $status = function (string $riderSession, string $publicId) {
        global $BASE;
        $res = call($riderSession, "$BASE/api/private-pickup.php?" . http_build_query([
            'action' => 'status', 'request_public_id' => $publicId,
        ]));
        return $res['data'] ?? [];
    };

    // ---- E1. a CAR request: trip-only, one-tap finalise, NO repricing ----
    [$resC, $carReq] = $mkRequest($sessions['rider_car'], 'car');
    check('rider can create a CAR pickup request', $carReq !== null, json_encode($resC));

    if ($carReq) {
        $stampedCar = (float)$pdo->query("SELECT price FROM ride_requests WHERE public_id = " . $pdo->quote($carReq))->fetchColumn();
        check('car request stamped at the CAR trip-only fare on creation', near($stampedCar, $expectCarTripOnly), "expected $expectCarTripOnly got $stampedCar");

        $carOffer = $feedRow($sessions['car_driver'], $carReq);
        check('car driver is offered the CAR fare (trip-only)', $carOffer !== null && near((float)$carOffer['your_fare'], $expectCarTripOnly),
            "expected $expectCarTripOnly got " . var_export($carOffer['your_fare'] ?? null, true));
        check('car offer needs NO consent (your_fare == passenger_offer)',
            $carOffer !== null && $carOffer['requires_consent'] === false && near((float)$carOffer['your_fare'], (float)$carOffer['passenger_offer']));

        $acc = call($sessions['car_driver'], "$BASE/api/private-pickup.php", ['action' => 'accept', 'request_public_id' => $carReq]);
        check('car driver accept FINALISES', ($acc['status'] ?? '') === 'success' && ($acc['data']['outcome'] ?? '') === 'accepted', json_encode($acc));

        $row = $dbFare($carReq);
        check('car: ride_requests.price UNCHANGED (no silent repricing #2/#3)', near((float)($row['request_price'] ?? 0), $expectCarTripOnly), json_encode($row));
        check('car: ride_shares.price_per_passenger is the car fare', near((float)($row['price_per_passenger'] ?? 0), $expectCarTripOnly), json_encode($row));
        check('car: payments.fare_amount is the car fare', near((float)($row['fare_amount'] ?? 0), $expectCarTripOnly), json_encode($row));

        $st = $status($sessions['rider_car'], $carReq);
        check('car: rider status reports the car fare and class',
            near((float)($st['price'] ?? 0), $expectCarTripOnly) && ($st['vehicle_type'] ?? '') === 'car',
            json_encode(['price' => $st['price'] ?? null, 'vehicle_type' => $st['vehicle_type'] ?? null]));
    }

    // ---- E2. a KEKE request: repositioning fare reaches the trip only via consent ----
    [$resK, $kekeReq] = $mkRequest($sessions['rider_keke'], 'keke');
    check('rider can create a KEKE pickup request', $kekeReq !== null, json_encode($resK));

    if ($kekeReq) {
        $stampedKeke = (float)$pdo->query("SELECT price FROM ride_requests WHERE public_id = " . $pdo->quote($kekeReq))->fetchColumn();
        check('keke request stamped at the KEKE trip-only fare on creation', near($stampedKeke, $expectKekeTripOnly), "expected $expectKekeTripOnly got $stampedKeke");
        check('keke and car opening fares differ (separate lanes #6/#10)', !near($expectKekeTripOnly, $expectCarTripOnly));

        // keke driver is ~3km from the pickup point -> its fare includes that
        // repositioning leg (#7), so it is HIGHER than the trip-only offer and
        // needs the passenger's consent.
        $kekeOffer = $feedRow($sessions['keke_driver'], $kekeReq);
        $proposedFare = $kekeOffer !== null ? (float)$kekeOffer['your_fare'] : 0.0;
        check('keke driver fare INCLUDES repositioning (> trip-only offer, #7)',
            $kekeOffer !== null && $proposedFare > (float)$kekeOffer['passenger_offer'] && (float)$kekeOffer['reposition_km'] > 0,
            json_encode($kekeOffer ? ['your_fare'=>$proposedFare,'offer'=>$kekeOffer['passenger_offer'],'repo'=>$kekeOffer['reposition_km']] : null));
        check('keke offer REQUIRES consent', $kekeOffer !== null && $kekeOffer['requires_consent'] === true);

        // accept must PROPOSE, not finalise: no trip, price untouched (#3).
        $acc = call($sessions['keke_driver'], "$BASE/api/private-pickup.php", ['action' => 'accept', 'request_public_id' => $kekeReq]);
        check('keke accept becomes a PROPOSAL', ($acc['status'] ?? '') === 'success' && ($acc['data']['outcome'] ?? '') === 'proposed', json_encode($acc));
        $mid = $dbFare($kekeReq);
        check('keke proposal: NO trip spawned', empty($mid['matched_ride_share_id']), json_encode($mid));
        check('keke proposal: price STILL the trip-only offer (untouched #2)', near((float)($mid['request_price'] ?? 0), $expectKekeTripOnly), json_encode($mid));
        check('keke proposal: proposed_fare = repositioning-inclusive fare', near((float)($mid['proposed_fare'] ?? 0), $proposedFare), json_encode($mid));

        // passenger consents -> the agreed fare becomes authoritative.
        $cf = call($sessions['rider_keke'], "$BASE/api/private-pickup.php", ['action' => 'accept_fare', 'request_public_id' => $kekeReq]);
        check('passenger accept_fare succeeds', ($cf['status'] ?? '') === 'success', json_encode($cf));

        $row = $dbFare($kekeReq);
        check('keke after consent: ride_requests.price = agreed (repositioning) fare', near((float)($row['request_price'] ?? 0), $proposedFare), json_encode($row));
        check('keke after consent: ride_shares.price_per_passenger = agreed fare', near((float)($row['price_per_passenger'] ?? 0), $proposedFare), json_encode($row));
        check('keke after consent: payments.fare_amount = agreed fare', near((float)($row['fare_amount'] ?? 0), $proposedFare), json_encode($row));

        $st = $status($sessions['rider_keke'], $kekeReq);
        check('keke: rider status reports the agreed fare and keke class',
            near((float)($st['price'] ?? 0), $proposedFare) && ($st['vehicle_type'] ?? '') === 'keke',
            json_encode(['price' => $st['price'] ?? null, 'vehicle_type' => $st['vehicle_type'] ?? null]));
    }
    // ==========================================================
    echo "\n---- F. Join Ride pricing is untouched by all of it ----\n";
    // ==========================================================
    // Same function, same inputs, before and after every Private Pickup
    // setting above was written. Join Ride must not have moved by a kobo.
    $joinRideAfter = calculate_recommended_seat_price($pdo, $distance, 4);
    check('Join Ride recommended price is unchanged',
        near((float)($joinRideBefore['recommended_price'] ?? 0), (float)($joinRideAfter['recommended_price'] ?? 0)),
        'before=' . ($joinRideBefore['recommended_price'] ?? 'null') . ' after=' . ($joinRideAfter['recommended_price'] ?? 'null'));
    check('Join Ride base fare / per-km inputs are unchanged',
        near((float)($joinRideBefore['breakdown']['base_fare'] ?? 0), (float)($joinRideAfter['breakdown']['base_fare'] ?? 0))
        && near((float)($joinRideBefore['breakdown']['price_per_km'] ?? 0), (float)($joinRideAfter['breakdown']['price_per_km'] ?? 0)),
        'before=' . json_encode($joinRideBefore['breakdown'] ?? null) . ' after=' . json_encode($joinRideAfter['breakdown'] ?? null));
    check('Join Ride driver price band is unchanged',
        near((float)($joinRideBefore['min_price'] ?? 0), (float)($joinRideAfter['min_price'] ?? 0))
        && near((float)($joinRideBefore['max_price'] ?? 0), (float)($joinRideAfter['max_price'] ?? 0)),
        "before={$joinRideBefore['min_price']}-{$joinRideBefore['max_price']} after={$joinRideAfter['min_price']}-{$joinRideAfter['max_price']}");

    echo "\n==== RESULT: $pass passed, $fail failed ====\n";

} finally {
    // ---- restore every setting exactly as found ----
    foreach ($original as $key => $value) {
        if ($value === null) {
            // The row did not exist before this test; do not leave one behind.
            $pdo->prepare("DELETE FROM system_settings WHERE setting_key = ?")->execute([$key]);
        } else {
            $pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
            )->execute([$key, $value]);
        }
    }

    // ---- delete every row this test created ----
    foreach ($requestIds as $publicId) {
        $stmt = $pdo->prepare("SELECT id, matched_ride_share_id FROM ride_requests WHERE public_id = ?");
        $stmt->execute([$publicId]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($rideId = (int)$r['matched_ride_share_id']) {
                $pdo->prepare("DELETE FROM payments WHERE ride_share_id = ?")->execute([$rideId]);
                $pdo->prepare("DELETE FROM ride_share_bookings WHERE ride_share_id = ?")->execute([$rideId]);
                $pdo->prepare("DELETE FROM ride_commission_reservations WHERE ride_share_id = ?")->execute([$rideId]);
                $pdo->prepare("DELETE FROM ride_shares WHERE id = ?")->execute([$rideId]);
            }
            $pdo->prepare("DELETE FROM ride_requests WHERE id = ?")->execute([(int)$r['id']]);
        }
    }

    if ($createdUsers) {
        $in = implode(',', array_map('intval', $createdUsers));
        foreach ([
            "DELETE FROM notifications WHERE user_id IN ($in)",
            "DELETE FROM wallet_transactions WHERE user_id IN ($in)",
            "DELETE FROM ride_commission_reservations WHERE driver_id IN ($in)",
            "DELETE FROM vehicles WHERE driver_id IN ($in)",
            "DELETE FROM users WHERE id IN ($in)",
        ] as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) { /* table may not exist on this install */ }
        }
    }

    // Forged test sessions never outlive the test.
    foreach ($sessions as $sid) {
        @unlink(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    }

    echo "cleanup: settings restored, " . count($requestIds) . " request(s) and "
        . count($createdUsers) . " user(s) removed\n";
}

exit($fail > 0 ? 1 : 0);
