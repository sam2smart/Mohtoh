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
//   E  the accepting driver's class - not the request placeholder - is what
//      the rider is finally charged
//   F  Join Ride pricing is unchanged by any of it
//
// Every setting it touches is restored in the teardown, and every row it
// creates is deleted.
//
//   php database/test_private_pickup_pricing.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

$BASE = getenv('MOHTOH_BASE_URL') ?: 'http://localhost/Mostmerit/merit_drives%20-%20Copy.v1';
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

    $rider = $pdo->query("SELECT id, user_type, fullname, email FROM users WHERE user_type = 'rider' AND is_verified = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$rider) { echo "No verified rider to quote for. Aborting.\n"; exit(1); }
    $sessions['rider'] = make_session($rider);
    echo "rider: {$rider['email']} (id {$rider['id']})\n";

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
             VALUES (?, ?, ?, ?, ?, 'driver', 1, 1, 1, 1, ?, 1, 1, CURDATE(), ?, ?, ?, 500000)"
        )->execute([
            'ppp-' . uniqid(), $name, 'ppp-' . uniqid(), $email, $hash,
            $vtype === 'keke' ? 1 : 0, $lat, $lng, $now,
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
    $sessions['rider_keke'] = make_session($riderKeke);
    $sessions['rider_car']  = make_session($riderCar);

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
    echo "\n---- E. the ACCEPTING driver's class is what is charged ----\n";
    // ==================================================================
    // Live rate cards now: keke 250 + 95/km, car 600 + 230/km, clamps off.
    $expectKekeFare = round(250 + ($distance * 95) + $booking, 2);
    $expectCarFare  = round(600 + ($distance * 230) + $booking, 2);

    $mkRequest = function (string $riderSession) use ($coords, &$requestIds) {
        global $BASE;
        $res = call($riderSession, "$BASE/api/private-pickup.php", [
            'action' => 'create',
            'pickup_location' => 'PPPrice pickup',
            'pickup_lat' => $coords['plat'], 'pickup_lng' => $coords['plng'],
            'destination' => 'PPPrice destination',
            'destination_lat' => $coords['dlat'], 'destination_lng' => $coords['dlng'],
            'notes' => 'pricing test',
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

    // The three money rows the trip is actually built on.
    $dbFare = function (string $publicId) use ($pdo) {
        $stmt = $pdo->prepare(
            "SELECT r.price AS request_price, rs.price_per_passenger, p.fare_amount
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

    // ---- E1. a KEKE driver accepts ----
    [$res, $kekeReq] = $mkRequest($sessions['rider_keke']);
    check('rider can create a pickup request', $kekeReq !== null, json_encode($res));

    if ($kekeReq) {
        $kekeOffer = $feedRow($sessions['keke_driver'], $kekeReq);
        $carOffer  = $feedRow($sessions['car_driver'], $kekeReq);
        check('keke driver is offered the KEKE fare',
            $kekeOffer !== null && near((float)$kekeOffer['fare'], $expectKekeFare),
            "expected $expectKekeFare got " . var_export($kekeOffer['fare'] ?? null, true));
        check('car driver is offered the CAR fare for the same request',
            $carOffer !== null && near((float)$carOffer['fare'], $expectCarFare),
            "expected $expectCarFare got " . var_export($carOffer['fare'] ?? null, true));

        $acc = call($sessions['keke_driver'], "$BASE/api/private-pickup.php",
            ['action' => 'accept', 'request_public_id' => $kekeReq]);
        check('keke driver can accept', ($acc['status'] ?? '') === 'success', json_encode($acc));

        $row = $dbFare($kekeReq);
        check('ride_requests.price is the keke fare', near((float)($row['request_price'] ?? 0), $expectKekeFare), json_encode($row));
        check('ride_shares.price_per_passenger is the keke fare', near((float)($row['price_per_passenger'] ?? 0), $expectKekeFare), json_encode($row));
        check('payments.fare_amount is the keke fare', near((float)($row['fare_amount'] ?? 0), $expectKekeFare), json_encode($row));

        $st = $status($sessions['rider_keke'], $kekeReq);
        check('rider status reports the keke fare and class',
            near((float)($st['price'] ?? 0), $expectKekeFare) && ($st['vehicle_type'] ?? '') === 'keke',
            json_encode(['price' => $st['price'] ?? null, 'vehicle_type' => $st['vehicle_type'] ?? null]));
    }

    // ---- E2. a CAR driver accepts: the keke placeholder must be replaced ----
    [$res2, $carReq] = $mkRequest($sessions['rider_car']);
    check('second rider can create a pickup request', $carReq !== null, json_encode($res2));

    if ($carReq) {
        $stamped = (float)$pdo->query("SELECT price FROM ride_requests WHERE public_id = " . $pdo->quote($carReq))->fetchColumn();
        check('a fresh request is stamped with the keke placeholder', near($stamped, $expectKekeFare), "got $stamped");

        $acc = call($sessions['car_driver'], "$BASE/api/private-pickup.php",
            ['action' => 'accept', 'request_public_id' => $carReq]);
        check('car driver can accept', ($acc['status'] ?? '') === 'success', json_encode($acc));

        $row = $dbFare($carReq);
        check('ride_requests.price was RE-PRICED to the car fare', near((float)($row['request_price'] ?? 0), $expectCarFare), json_encode($row));
        check('ride_shares.price_per_passenger is the car fare', near((float)($row['price_per_passenger'] ?? 0), $expectCarFare), json_encode($row));
        check('payments.fare_amount is the car fare', near((float)($row['fare_amount'] ?? 0), $expectCarFare), json_encode($row));

        $st = $status($sessions['rider_car'], $carReq);
        check('rider status reports the car fare and class',
            near((float)($st['price'] ?? 0), $expectCarFare) && ($st['vehicle_type'] ?? '') === 'car',
            json_encode(['price' => $st['price'] ?? null, 'vehicle_type' => $st['vehicle_type'] ?? null]));
        check('the car trip was NOT charged at the keke placeholder rate',
            !near((float)($row['fare_amount'] ?? 0), $expectKekeFare), json_encode($row));
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
