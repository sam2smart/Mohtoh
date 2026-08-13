<?php
// _tmp_test_car_pickup.php — Task 3 verification (temporary, deleted after run).
//
// Exercises the REAL engine (includes/private_pickup.php + eligibility.php)
// on a seeded fixture, then deletes every row it created.

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/private_pickup.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}

$RIDER_LAT = 6.5244; $RIDER_LNG = 3.3792;
function km_north($lat, $km) { return $lat + ($km / 111.0); }

$U = []; $V = []; $RQ = []; $RS = [];

function cleanup(PDO $pdo, array $U, array $RS) {
    try {
        $inU = $U ? implode(',', array_map('intval', $U)) : '0';
        $inRS = $RS ? implode(',', array_map('intval', $RS)) : '0';
        $pdo->exec("DELETE FROM trip_state_events WHERE ride_share_id IN ($inRS)");
        $pdo->exec("DELETE FROM ride_commission_reservations WHERE driver_id IN ($inU)");
        $pdo->exec("DELETE FROM notifications WHERE user_id IN ($inU)");
        foreach (['wallet_transactions','wallet_logs','wallet_events'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE user_id IN ($inU)"); } catch (Throwable $e) {}
        }
        $pdo->exec("DELETE FROM ride_shares WHERE id IN ($inRS)");
        $pdo->exec("DELETE FROM ride_requests WHERE rider_id IN ($inU) OR driver_id IN ($inU)");
        $pdo->exec("DELETE FROM vehicles WHERE driver_id IN ($inU)");
        $pdo->exec("DELETE FROM users WHERE id IN ($inU)");
    } catch (Throwable $e) { echo "cleanup warning: " . $e->getMessage() . "\n"; }
}

echo "=== Private Pickup — car + keke eligibility ===\n";

try {
    // One rider PER request: create_private_pickup_request() allows only one
    // active request per rider (includes/private_pickup.php:60), so reusing a
    // single rider for the three accept scenarios would silently skip two.
    $make_rider = function() use ($pdo, &$U) {
        $pdo->prepare("INSERT INTO users (user_id, fullname, phone, email, password, user_type, email_verified, phone_verified, is_verified) VALUES (?, 'T3 Rider', ?, ?, 'x', 'rider', 1, 1, 1)")
            ->execute(['t3-'.uniqid(), 't3-r-'.uniqid(), 't3-rider-'.uniqid().'@t.co']);
        $id = (int)$pdo->lastInsertId(); $U[] = $id;
        return $id;
    };
    $riderId = $make_rider();
    $riderId2 = $make_rider();
    $riderId3 = $make_rider();

    // MySQL clock, not PHP's (see database/test_private_pickup_e2e.php).
    $clock = $pdo->query("SELECT NOW() AS fresh, DATE_SUB(NOW(), INTERVAL 3600 SECOND) AS stale")->fetch();
    $now = $clock['fresh']; $stale = $clock['stale'];

    $make_driver = function(array $o) use ($pdo, &$U, &$V) {
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
                email_verified, phone_verified, is_verified, is_online, is_keke_driver,
                private_pickup_enabled, pickup_available_today, pickup_availability_date,
                latitude, longitude, last_location_update, wallet_balance)
             VALUES (?, ?, ?, ?, 'x', 'driver', 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100000)"
        )->execute([
            't3-'.uniqid(), $o['name'], 't3-d-'.uniqid(), 't3-d-'.uniqid().'@t.co',
            $o['is_verified'], $o['is_online'], $o['is_keke'],
            $o['pickup_enabled'], $o['avail_today'], ($o['avail_today'] ? date('Y-m-d') : null),
            $o['lat'], $o['lng'], $o['last_loc'],
        ]);
        $id = (int)$pdo->lastInsertId(); $U[] = $id;

        $vpub = generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH');
        $pdo->prepare(
            "INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number,
                seats, passenger_capacity, vehicle_type, status, verification_status)
             VALUES (?, ?, 'Toyota', 'Corolla', 2020, 'Silver', ?, 4, 4, ?, ?, ?)"
        )->execute([$vpub, $id, 'PLT-'.substr(uniqid(),-6), $o['vtype'], $o['vstatus'], $o['vverif']]);
        $V[] = (int)$pdo->lastInsertId();
        return $id;
    };

    $base = [
        'is_verified'=>1,'is_online'=>1,'is_keke'=>1,'pickup_enabled'=>1,'avail_today'=>1,
        'vtype'=>'keke','vstatus'=>'approved','vverif'=>'verified','last_loc'=>$now,
        'lat'=>$RIDER_LAT,'lng'=>$RIDER_LNG,
    ];
    $car    = ['is_keke'=>0,'vtype'=>'car'];
    $unver  = ['is_verified'=>0,'vstatus'=>'pending','vverif'=>'pending'];

    // Verified CAR driver 5km away (Test 1)
    $CAR   = $make_driver(['name'=>'CAR verified 5km','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $car + $base);
    // Verified KEKE driver 12km away (Test 2 — no regression)
    $KEKE  = $make_driver(['name'=>'KEKE verified 12km','lat'=>km_north($RIDER_LAT,12),'lng'=>$RIDER_LNG] + $base);
    // Unverified CAR driver 5km away (Test 3)
    $UCAR  = $make_driver(['name'=>'CAR unverified 5km','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $unver + $car + $base);
    // Unverified KEKE driver 5km away
    $UKEKE = $make_driver(['name'=>'KEKE unverified 5km','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $unver + $base);
    // 20KM-matching guards (unchanged behaviour)
    $FAR   = $make_driver(['name'=>'CAR 21km','lat'=>km_north($RIDER_LAT,21),'lng'=>$RIDER_LNG] + $car + $base);
    $NEAR  = $make_driver(['name'=>'CAR 19km','lat'=>km_north($RIDER_LAT,19),'lng'=>$RIDER_LNG] + $car + $base);
    $OFF   = $make_driver(['name'=>'CAR offline','is_online'=>0,'lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $car + $base);
    $STALE = $make_driver(['name'=>'CAR stale gps','last_loc'=>$stale,'lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $car + $base);
    // Excluded vehicle class — the lane must not become "any vehicle".
    $BUS   = $make_driver(['name'=>'BUS 5km','is_keke'=>0,'vtype'=>'bus','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);

    // ---------- TEST 1: verified CAR driver ----------
    echo "\n-- Test 1: verified car driver --\n";
    $eCar = check_driver_eligibility($pdo, $CAR, 'private_pickup');
    check('car: eligible for private_pickup', $eCar['eligible'] === true);
    $eCarCap = check_driver_eligibility($pdo, $CAR, 'private_pickup_capability');
    check('car: eligible for private_pickup_capability (can go online)', $eCarCap['eligible'] === true);

    // toggle-online path: capability form is what api/toggle-online.php calls.
    $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?")->execute([$CAR]);
    $offCap = check_driver_eligibility($pdo, $CAR, 'private_pickup_capability');
    check('car: offline driver still passes capability gate (go-online allowed)', $offCap['eligible'] === true);
    $pdo->prepare("UPDATE users SET is_online = 1 WHERE id = ?")->execute([$CAR]);

    $matched = find_nearby_pickup_drivers($pdo, $RIDER_LAT, $RIDER_LNG);
    $matchedIds = array_map(fn($m) => (int)$m['id'], $matched);
    check('car: discoverable by find_nearby_pickup_drivers (20KM)', in_array($CAR, $matchedIds, true));

    // ---------- TEST 2: verified KEKE driver (no regression) ----------
    echo "\n-- Test 2: verified keke driver --\n";
    $eKeke = check_driver_eligibility($pdo, $KEKE, 'private_pickup');
    check('keke: still eligible for private_pickup', $eKeke['eligible'] === true);
    check('keke: still discoverable within 20KM', in_array($KEKE, $matchedIds, true));

    // ---------- TEST 3: unverified drivers ----------
    echo "\n-- Test 3: unverified drivers --\n";
    $eU = check_driver_eligibility($pdo, $UCAR, 'private_pickup');
    check('unverified car: NOT eligible', $eU['eligible'] === false);
    check('unverified car: code is a verification code (' . $eU['code'] . ')',
        in_array($eU['code'], ['vehicle_unverified','account_unverified'], true));
    check('unverified car: NOT discoverable', !in_array($UCAR, $matchedIds, true));
    check('unverified car: capability gate refuses go-online',
        check_driver_eligibility($pdo, $UCAR, 'private_pickup_capability')['eligible'] === false);
    $eUK = check_driver_eligibility($pdo, $UKEKE, 'private_pickup');
    check('unverified keke: NOT eligible', $eUK['eligible'] === false);
    check('unverified keke: NOT discoverable', !in_array($UKEKE, $matchedIds, true));

    // ---------- 20KM matching unchanged ----------
    echo "\n-- 20KM matching / algorithm unchanged --\n";
    $radius = (float) get_system_setting($pdo, 'private_pickup_radius_km', 20);
    check('radius setting still 20', abs($radius - 20.0) < 0.0001);
    check('21km driver excluded', !in_array($FAR, $matchedIds, true));
    check('19km driver included', in_array($NEAR, $matchedIds, true));
    check('offline driver excluded', !in_array($OFF, $matchedIds, true));
    check('stale-GPS driver excluded', !in_array($STALE, $matchedIds, true));
    check('bus excluded — lane is keke+car only', !in_array($BUS, $matchedIds, true));
    check('bus eligibility code=ineligible_vehicle',
        check_driver_eligibility($pdo, $BUS, 'private_pickup')['code'] === 'ineligible_vehicle');
    // distances still returned, still ascending
    $dists = array_map(fn($m) => (float)$m['distance_km'], $matched);
    $sorted = $dists; sort($sorted);
    check('matches carry distance_km and are sorted nearest-first', $dists === $sorted && count($dists) > 0);
    check('every match within radius', count(array_filter($dists, fn($d) => $d > $radius)) === 0);

    // ---------- full flow: request -> feed -> accept (car driver) ----------
    echo "\n-- Car driver end-to-end: request -> feed -> accept --\n";
    $res = create_private_pickup_request($pdo, $riderId, [
        'pickup_location' => 'T3 Pickup, Lagos', 'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'destination' => 'T3 Dest, Lagos', 'destination_lat' => km_north($RIDER_LAT,8), 'destination_lng' => $RIDER_LNG,
        'notes' => 't3',
    ]);
    check('rider request created', $res['status'] === 'success');
    $reqPub = $res['data']['request_public_id'] ?? '';
    $notified = (int)($res['data']['drivers_notified'] ?? -1);
    check("drivers_notified counts car+keke matches (got $notified)", $notified === count($matchedIds));

    $q = $pdo->prepare("SELECT * FROM ride_requests WHERE public_id = ?");
    $q->execute([$reqPub]); $reqRow = $q->fetch();
    if ($reqRow) $RQ[] = (int)$reqRow['id'];

    $feedCar = get_driver_pickup_feed($pdo, $CAR);
    check('car: feed eligible', $feedCar['eligible'] === true);
    check('car: request appears in feed',
        in_array($reqPub, array_map(fn($r)=>$r['request_public_id'], $feedCar['requests']), true));

    $feedKeke = get_driver_pickup_feed($pdo, $KEKE);
    check('keke: feed eligible', $feedKeke['eligible'] === true);
    check('keke: request appears in feed',
        in_array($reqPub, array_map(fn($r)=>$r['request_public_id'], $feedKeke['requests']), true));

    check('unverified car: feed ineligible', get_driver_pickup_feed($pdo, $UCAR)['eligible'] === false);

    $acc = accept_private_pickup_request($pdo, $CAR, $reqPub);
    check('car: accept succeeds', $acc['status'] === 'success');
    $rideId = (int)($acc['data']['ride_share_id'] ?? 0);
    if ($rideId) $RS[] = $rideId;
    $rs = $pdo->prepare("SELECT * FROM ride_shares WHERE id = ?"); $rs->execute([$rideId]); $ride = $rs->fetch();
    check('car: private ride spawned (existing trip engine)', $ride && $ride['ride_mode'] === 'private');
    check('car: ride belongs to the car driver', $ride && (int)$ride['driver_id'] === $CAR);
    $q->execute([$reqPub]); $after = $q->fetch();
    check('car: request now accepted/in_progress', in_array($after['status'], ['accepted','in_progress'], true));

    // ---------- keke accept still works (separate request) ----------
    echo "\n-- Keke driver end-to-end: request -> accept --\n";
    $res2 = create_private_pickup_request($pdo, $riderId, [
        'pickup_location' => 'T3 Pickup 2, Lagos', 'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'destination' => 'T3 Dest 2, Lagos', 'destination_lat' => km_north($RIDER_LAT,8), 'destination_lng' => $RIDER_LNG,
    ]);
    $reqPub2 = $res2['data']['request_public_id'] ?? '';
    $q->execute([$reqPub2]); $r2 = $q->fetch(); if ($r2) $RQ[] = (int)$r2['id'];
    $acc2 = accept_private_pickup_request($pdo, $KEKE, $reqPub2);
    check('keke: accept succeeds', $acc2['status'] === 'success');
    $rideId2 = (int)($acc2['data']['ride_share_id'] ?? 0);
    if ($rideId2) $RS[] = $rideId2;
    $rs->execute([$rideId2]); $ride2 = $rs->fetch();
    check('keke: private ride spawned', $ride2 && $ride2['ride_mode'] === 'private');
    check('keke: ride belongs to the keke driver', $ride2 && (int)$ride2['driver_id'] === $KEKE);

    // ---------- unverified car cannot accept ----------
    echo "\n-- Unverified car cannot accept --\n";
    $res3 = create_private_pickup_request($pdo, $riderId, [
        'pickup_location' => 'T3 Pickup 3, Lagos', 'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'destination' => 'T3 Dest 3, Lagos', 'destination_lat' => km_north($RIDER_LAT,8), 'destination_lng' => $RIDER_LNG,
    ]);
    $reqPub3 = $res3['data']['request_public_id'] ?? '';
    $q->execute([$reqPub3]); $r3 = $q->fetch(); if ($r3) $RQ[] = (int)$r3['id'];
    $acc3 = accept_private_pickup_request($pdo, $UCAR, $reqPub3);
    check('unverified car: accept REJECTED', $acc3['status'] === 'error');

    cleanup($pdo, $U, $RS);
    echo "\nfixture cleaned up\n";

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    cleanup($pdo, $U, $RS);
    $fail++;
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
