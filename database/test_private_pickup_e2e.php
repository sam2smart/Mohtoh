<?php
// database/test_private_pickup_e2e.php
//
// End-to-end verification of the Private Solo Pickup rider->driver flow
// against the REAL engine (includes/private_pickup.php + eligibility.php),
// asserting on ACTUAL database rows, not HTTP 200s. Seeds a controlled
// fixture, runs the multi-driver matrix from the spec, verifies the atomic
// single-winner accept, then DELETES every row it created (tracked by id) so
// the test is repeatable and leaves production data untouched.
//
// It does NOT wrap the run in an outer transaction, because the accept path
// opens its own transaction (single-winner guarantee) and MySQL has no
// nested transactions — so cleanup is explicit id-scoped deletes instead.
//
// Run:  php database/test_private_pickup_e2e.php
//
// Matrix (spec):
//   A keke 5km / B keke 12km / C keke 19km        -> RECEIVE
//   D keke 21km (out of radius)                    -> NOT
//   E keke offline                                 -> NOT
//   F keke unverified vehicle                      -> NOT
//   G car (non-keke)                               -> NOT (keke-only lane)
//   H keke stale GPS (> ttl)                       -> NOT (freshness)

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

$pass = 0; $fail = 0; $notes = [];
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}

$RIDER_LAT = 6.5244; $RIDER_LNG = 3.3792;
function km_north($lat, $km) { return $lat + ($km / 111.0); }

$U = [];   // user ids
$V = [];   // vehicle ids
$RQ = [];  // ride_requests ids
$RS = [];  // ride_shares ids

function cleanup(PDO $pdo, array $U, array $V, array $RQ, array $RS) {
    try {
        $inU = $U ? implode(',', array_map('intval', $U)) : '0';
        $inRS = $RS ? implode(',', array_map('intval', $RS)) : '0';
        // children first
        $pdo->exec("DELETE FROM trip_state_events WHERE ride_share_id IN ($inRS)");
        $pdo->exec("DELETE FROM ride_commission_reservations WHERE driver_id IN ($inU)");
        $pdo->exec("DELETE FROM notifications WHERE user_id IN ($inU)");
        // wallet logs table name varies; ignore failures
        foreach (['wallet_transactions','wallet_logs','wallet_events'] as $t) {
            try { $pdo->exec("DELETE FROM $t WHERE user_id IN ($inU)"); } catch (Throwable $e) {}
        }
        $pdo->exec("DELETE FROM ride_shares WHERE id IN ($inRS)");
        $pdo->exec("DELETE FROM ride_requests WHERE rider_id IN ($inU) OR driver_id IN ($inU)");
        $pdo->exec("DELETE FROM vehicles WHERE driver_id IN ($inU)");
        $pdo->exec("DELETE FROM users WHERE id IN ($inU)");
    } catch (Throwable $e) {
        echo "cleanup warning: " . $e->getMessage() . "\n";
    }
}

echo "=== Private Pickup E2E ===\n";

try {
    // ---- seed rider ----
    $pdo->prepare("INSERT INTO users (user_id, fullname, phone, email, password, user_type, email_verified, phone_verified, is_verified) VALUES (?, 'E2E Rider', ?, ?, 'x', 'rider', 1, 1, 1)")
        ->execute(['e2e-'.uniqid(), 'e2e-r-'.uniqid(), 'e2e-rider-'.uniqid().'@t.co']);
    $riderId = (int)$pdo->lastInsertId(); $U[] = $riderId;

    // Timestamps MUST come from MySQL's clock, not PHP's. Production writes
    // users.last_location_update via MySQL NOW() (api/update-location.php),
    // and this box runs PHP (Europe/Berlin) an hour ahead of MySQL (SYSTEM) —
    // seeding a PHP-computed "1 hour ago" would land on MySQL's "now" and the
    // stale-GPS case would silently test nothing.
    $clock = $pdo->query("SELECT NOW() AS fresh, DATE_SUB(NOW(), INTERVAL 3600 SECOND) AS stale")->fetch();
    $now = $clock['fresh'];
    $stale = $clock['stale'];

    $make_driver = function(array $o) use ($pdo, &$U, &$V) {
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
                email_verified, phone_verified, is_verified, is_online, is_keke_driver,
                private_pickup_enabled, pickup_available_today, pickup_availability_date,
                latitude, longitude, last_location_update, wallet_balance)
             VALUES (?, ?, ?, ?, 'x', 'driver', 1, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 100000)"
        )->execute([
            'e2e-'.uniqid(), $o['name'], 'e2e-d-'.uniqid(), 'e2e-d-'.uniqid().'@t.co',
            $o['is_verified'], $o['is_online'], $o['is_keke'],
            $o['pickup_enabled'], $o['avail_today'], ($o['avail_today'] ? date('Y-m-d') : null),
            $o['lat'], $o['lng'], $o['last_loc'],
        ]);
        $id = (int)$pdo->lastInsertId(); $U[] = $id;

        $vpub = generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH');
        $pdo->prepare(
            "INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number,
                seats, passenger_capacity, vehicle_type, status, verification_status)
             VALUES (?, ?, 'Bajaj', 'RE', 2022, 'Yellow', ?, 3, 3, ?, ?, ?)"
        )->execute([$vpub, $id, 'PLT-'.substr(uniqid(),-6), $o['vtype'], $o['vstatus'], $o['vverif']]);
        $V[] = (int)$pdo->lastInsertId();
        return $id;
    };

    $base = [
        'is_verified'=>1,'is_online'=>1,'is_keke'=>1,'pickup_enabled'=>1,'avail_today'=>1,
        'vtype'=>'keke','vstatus'=>'approved','vverif'=>'verified','last_loc'=>$now,
        'lat'=>$RIDER_LAT,'lng'=>$RIDER_LNG,
    ];
    $A = $make_driver(['name'=>'A keke 5km','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);
    $B = $make_driver(['name'=>'B keke 12km','lat'=>km_north($RIDER_LAT,12),'lng'=>$RIDER_LNG] + $base);
    $C = $make_driver(['name'=>'C keke 19km','lat'=>km_north($RIDER_LAT,19),'lng'=>$RIDER_LNG] + $base);
    $D = $make_driver(['name'=>'D keke 21km','lat'=>km_north($RIDER_LAT,21),'lng'=>$RIDER_LNG] + $base);
    $E = $make_driver(['name'=>'E offline','is_online'=>0,'lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);
    $F = $make_driver(['name'=>'F unverified','is_verified'=>0,'vstatus'=>'pending','vverif'=>'pending','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);
    $G = $make_driver(['name'=>'G car','is_keke'=>0,'vtype'=>'car','lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);
    $H = $make_driver(['name'=>'H stale gps','last_loc'=>$stale,'lat'=>km_north($RIDER_LAT,5),'lng'=>$RIDER_LNG] + $base);

    // ---- 1. matching returns exactly A,B,C ----
    $matched = find_nearby_pickup_drivers($pdo, $RIDER_LAT, $RIDER_LNG);
    $matchedIds = array_map(fn($m) => (int)$m['id'], $matched); sort($matchedIds);
    $expected = [$A, $B, $C]; sort($expected);
    check('Matching returns exactly A,B,C', $matchedIds === $expected);
    check('D (21km) excluded — outside radius', !in_array($D, $matchedIds, true));
    check('E (offline) excluded', !in_array($E, $matchedIds, true));
    check('F (unverified vehicle) excluded', !in_array($F, $matchedIds, true));
    check('G (car, non-keke) excluded — keke-only', !in_array($G, $matchedIds, true));
    check('H (stale GPS) excluded — freshness TTL', !in_array($H, $matchedIds, true));

    // ---- 2. eligibility gate per-driver ----
    check('eligibility(A) eligible', is_driver_eligible($pdo, $A, 'private_pickup'));
    check('eligibility(G car) code=not_keke', check_driver_eligibility($pdo, $G, 'private_pickup')['code'] === 'not_keke');
    check('eligibility(E offline) code=offline', check_driver_eligibility($pdo, $E, 'private_pickup')['code'] === 'offline');
    check('eligibility(F) code in {vehicle_unverified,account_unverified}', in_array(check_driver_eligibility($pdo, $F, 'private_pickup')['code'], ['vehicle_unverified','account_unverified'], true));

    // ---- 3. rider creates a real request ----
    $res = create_private_pickup_request($pdo, $riderId, [
        'pickup_location' => 'Test Pickup, Lagos', 'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'destination' => 'Test Dest, Lagos', 'destination_lat' => km_north($RIDER_LAT,8), 'destination_lng' => $RIDER_LNG,
        'notes' => 'e2e',
    ]);
    check('create returns success', $res['status'] === 'success');
    $reqPub = $res['data']['request_public_id'] ?? '';
    check('request has public_id', $reqPub !== '');
    check('drivers_notified == 3', ($res['data']['drivers_notified'] ?? -1) === 3);

    $row = $pdo->prepare("SELECT * FROM ride_requests WHERE public_id = ?");
    $row->execute([$reqPub]); $reqRow = $row->fetch();
    if ($reqRow) $RQ[] = (int)$reqRow['id'];
    check('ride_requests row persisted, type=private_pickup', $reqRow && $reqRow['request_type'] === 'private_pickup');
    check('status=pending', $reqRow && $reqRow['status'] === 'pending');
    check('pickup coords stored', $reqRow && $reqRow['pickup_lat'] !== null && $reqRow['pickup_lng'] !== null);
    check('expires_at set (TTL)', $reqRow && !empty($reqRow['expires_at']));

    $nc = $pdo->prepare("SELECT user_id, priority FROM notifications WHERE related_public_id = ?");
    $nc->execute([$reqPub]); $notifs = $nc->fetchAll();
    $notifUsers = array_map(fn($n)=>(int)$n['user_id'], $notifs); sort($notifUsers);
    check('notifications created for exactly A,B,C', $notifUsers === $expected);
    check('all notifications critical priority', count($notifs) > 0 && array_reduce($notifs, fn($c,$n)=>$c && $n['priority']==='critical', true));

    // ---- 4. driver feed ----
    $feedB = get_driver_pickup_feed($pdo, $B);
    $feedBIds = array_map(fn($r)=>$r['request_public_id'], $feedB['requests']);
    check('B feed eligible', $feedB['eligible'] === true);
    check('B feed contains request', in_array($reqPub, $feedBIds, true));
    $feedD = get_driver_pickup_feed($pdo, $D);
    check('D feed excludes request (out of range)', !in_array($reqPub, array_map(fn($r)=>$r['request_public_id'], $feedD['requests']), true));
    check('G feed ineligible (not keke)', get_driver_pickup_feed($pdo, $G)['eligible'] === false);

    $card = null; foreach ($feedB['requests'] as $r) if ($r['request_public_id']===$reqPub) $card=$r;
    check('feed card exposes distance_to_pickup_km', $card && isset($card['distance_to_pickup_km']));
    check('feed card exposes fare>0', $card && isset($card['fare']) && $card['fare'] > 0);
    check('feed card exposes rider_name', $card && !empty($card['rider_name']));

    // ---- 5. single-winner race ----
    $accA = accept_private_pickup_request($pdo, $A, $reqPub);
    $accB = accept_private_pickup_request($pdo, $B, $reqPub);
    $wins = ($accA['status']==='success'?1:0) + ($accB['status']==='success'?1:0);
    check('exactly ONE of A/B wins', $wins === 1);
    $winner = $accA['status']==='success' ? $A : $B;
    $rideId = $accA['status']==='success' ? ($accA['data']['ride_share_id'] ?? 0) : ($accB['data']['ride_share_id'] ?? 0);
    if ($rideId) $RS[] = (int)$rideId;

    $row->execute([$reqPub]); $after = $row->fetch();
    check('request accepted/in_progress', in_array($after['status'], ['accepted','in_progress'], true));
    check('request.driver_id == winner', (int)$after['driver_id'] === $winner);
    check('request linked to ride_share', (int)$after['matched_ride_share_id'] > 0);

    $rs = $pdo->prepare("SELECT * FROM ride_shares WHERE id = ?"); $rs->execute([$rideId]); $ride = $rs->fetch();
    check('spawned ride_shares row exists', (bool)$ride);
    check('ride_mode=private', $ride && $ride['ride_mode'] === 'private');
    check('vehicle_reserved=1', $ride && (int)$ride['vehicle_reserved'] === 1);
    check('ride belongs to winner', $ride && (int)$ride['driver_id'] === $winner);
    check('commission reservation created', (int)$pdo->query("SELECT COUNT(*) FROM ride_commission_reservations WHERE ride_share_id = ".(int)$rideId)->fetchColumn() === 1);

    // ---- 6. rider status: driver found ----
    $st = get_private_pickup_status($pdo, $riderId, $reqPub);
    check('rider status shows driver_name', !empty($st['data']['driver_name']));
    check('rider status shows ride public_id', !empty($st['data']['ride_public_id']));

    // ---- 7. late accept rejected ----
    $accLate = accept_private_pickup_request($pdo, ($winner===$A?$B:$A), $reqPub);
    check('late accept rejected', $accLate['status'] === 'error');

    // ---- 8. structural Join-Ride isolation ----
    check('create hard-codes request_type=private_pickup (isolation)', $reqRow && $reqRow['request_type'] === 'private_pickup');

    cleanup($pdo, $U, $V, $RQ, $RS);
    echo "\nfixture cleaned up (all test rows deleted)\n";

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    cleanup($pdo, $U, $V, $RQ, $RS);
    $fail++;
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
foreach ($notes as $n) echo "note: $n\n";
exit($fail === 0 ? 0 : 1);
