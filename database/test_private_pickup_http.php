<?php
// database/test_private_pickup_http.php
//
// Verifies the Private Solo Pickup flow over REAL HTTP with REAL sessions:
// the exact path a browser takes — JS -> api/*.php -> engine -> DB -> JSON.
//
// The engine-level suite (test_private_pickup_e2e.php) already proves the
// matching rules. This one proves the LAYER ABOVE it: that a logged-in
// rider's POST actually lands a ride_requests row, that the driver's own
// session sees it in the feed the driver page consumes, that role guards
// hold, and that Accept has exactly one winner when two drivers race over
// two separate HTTP connections.
//
// Every assertion that matters is re-checked against the database, never
// against the HTTP status code alone.
//
// Run:  php database/test_private_pickup_http.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

$BASE = getenv('MT_BASE_URL') ?: 'http://localhost/Mostmerit/merit_drives%20-%20Copy.v1';

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

$COOKIE_DIR = sys_get_temp_dir() . '/mt-http-test-' . getmypid();
@mkdir($COOKIE_DIR, 0777, true);

/** One cURL call carrying a named session's cookie jar. */
function http_call(string $who, string $url, ?array $json = null, array $form = []): array {
    global $COOKIE_DIR;
    $jar = $COOKIE_DIR . "/$who.txt";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($json !== null) {
        // Mirrors assets/js/api-client.js apiPost(): JSON body.
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    } elseif ($form) {
        // api/auth.php reads $_POST, so login must be form-encoded.
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body, 'json' => json_decode((string)$body, true), 'error' => $err];
}

$U = []; $RS = [];
function cleanup(PDO $pdo, array $U, array $RS) {
    if (!$U) return;
    $inU  = implode(',', array_map('intval', $U));
    $inRS = $RS ? implode(',', array_map('intval', $RS)) : '0';
    try {
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

echo "=== Private Pickup over real HTTP ===\n";
echo "base: $BASE\n\n";

$PW = 'HttpTest#2026';
$RIDER_LAT = 6.5244; $RIDER_LNG = 3.3792;
$km_north = fn($lat, $km) => $lat + ($km / 111.0);

try {
    // Reachability first — a dead vhost would otherwise look like 40 failures.
    $ping = http_call('ping', "$BASE/api/private-pickup.php?action=status");
    if ($ping['code'] !== 200) {
        echo "ABORT: $BASE unreachable (code {$ping['code']} {$ping['error']})\n";
        exit(1);
    }
    check('unauthenticated request is rejected', ($ping['json']['message'] ?? '') === 'Unauthorized');

    // ---------- seed ----------
    $hash = password_hash($PW, PASSWORD_DEFAULT);

    $riderEmail = 'http-rider-' . uniqid() . '@t.co';
    $pdo->prepare(
        "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
            email_verified, phone_verified, is_verified)
         VALUES (?, 'HTTP Rider', ?, ?, ?, 'rider', 1, 1, 1)"
    )->execute(['http-'.uniqid(), 'http-r-'.uniqid(), $riderEmail, $hash]);
    $riderId = (int)$pdo->lastInsertId(); $U[] = $riderId;

    // MySQL's clock, never PHP's — PHP here runs an hour ahead of MySQL, so a
    // PHP-computed "now" would write a location timestamp in MySQL's future.
    $now = $pdo->query("SELECT NOW()")->fetchColumn();

    $mkDriver = function (array $o) use ($pdo, &$U, $hash, $now) {
        $email = 'http-d-' . uniqid() . '@t.co';
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
                email_verified, phone_verified, is_verified, is_online, is_keke_driver,
                private_pickup_enabled, pickup_available_today, pickup_availability_date,
                latitude, longitude, last_location_update, wallet_balance)
             VALUES (?, ?, ?, ?, ?, 'driver', 1, 1, ?, ?, ?, ?, 1, CURDATE(), ?, ?, ?, 100000)"
        )->execute([
            'http-'.uniqid(), $o['name'], 'http-d-'.uniqid(), $email, $hash,
            $o['is_verified'] ?? 1, $o['is_online'] ?? 1, $o['is_keke'] ?? 1,
            $o['pickup_enabled'] ?? 1, $o['lat'], $o['lng'], $now,
        ]);
        $id = (int)$pdo->lastInsertId(); $U[] = $id;
        $pdo->prepare(
            "INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number,
                seats, passenger_capacity, vehicle_type, status, verification_status)
             VALUES (?, ?, 'Bajaj', 'RE', 2022, 'Yellow', ?, 3, 3, ?, ?, ?)"
        )->execute([
            generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH'), $id,
            'HTP-'.substr(uniqid(), -6), $o['vtype'] ?? 'keke',
            $o['vstatus'] ?? 'approved', $o['vverif'] ?? 'verified',
        ]);
        return ['id' => $id, 'email' => $email];
    };

    $near  = $mkDriver(['name' => 'HTTP Near keke 5km',  'lat' => $km_north($RIDER_LAT, 5),  'lng' => $RIDER_LNG]);
    $near2 = $mkDriver(['name' => 'HTTP Near2 keke 8km', 'lat' => $km_north($RIDER_LAT, 8),  'lng' => $RIDER_LNG]);
    $far   = $mkDriver(['name' => 'HTTP Far keke 21km',  'lat' => $km_north($RIDER_LAT, 21), 'lng' => $RIDER_LNG]);

    // ---------- login over HTTP ----------
    $login = fn($who, $email) => http_call($who, "$BASE/api/auth.php", null, [
        'action' => 'login', 'email' => $email, 'password' => $PW,
    ]);

    $r = $login('rider', $riderEmail);
    check('rider logs in over HTTP', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? $r['body']);
    $r = $login('near', $near['email']);
    check('near driver logs in over HTTP', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');
    $r = $login('near2', $near2['email']);
    check('second near driver logs in over HTTP', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');
    $r = $login('far', $far['email']);
    check('far driver logs in over HTTP', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');

    // ---------- role guards are enforced server-side ----------
    $r = http_call('rider', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    check('rider CANNOT read the driver feed', ($r['json']['status'] ?? '') === 'error');
    $r = http_call('near', "$BASE/api/private-pickup.php", [
        'action' => 'create', 'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'pickup_location' => 'x', 'destination' => 'y',
        'destination_lat' => 6.6, 'destination_lng' => 3.4,
    ]);
    check('driver CANNOT create a rider request', ($r['json']['status'] ?? '') === 'error');
    $rowsAfterGuard = $pdo->prepare("SELECT COUNT(*) FROM ride_requests WHERE rider_id = ?");
    $rowsAfterGuard->execute([$near['id']]);
    check('blocked create wrote NO database row', (int)$rowsAfterGuard->fetchColumn() === 0);

    // ---------- estimate (server-side fare) ----------
    $r = http_call('rider', "$BASE/api/private-pickup.php?" . http_build_query([
        'action' => 'estimate',
        'pickup_lat' => $RIDER_LAT, 'pickup_lng' => $RIDER_LNG,
        'destination_lat' => $km_north($RIDER_LAT, 6), 'destination_lng' => $RIDER_LNG,
    ]));
    $est = $r['json']['data'] ?? [];
    check('estimate returns a fare from the server', ($r['json']['status'] ?? '') === 'success' && ($est['estimated_fare'] ?? 0) > 0);
    check('estimate distance ~6km from coordinates', abs(($est['distance_km'] ?? 0) - 6) < 0.5, (string)($est['distance_km'] ?? 'n/a'));
    check('estimate exposes the configured radius', (float)($est['radius_km'] ?? 0) === (float) get_system_setting($pdo, 'private_pickup_radius_km', 20));

    // ---------- create ----------
    $r = http_call('rider', "$BASE/api/private-pickup.php", [
        'action'          => 'create',
        'pickup_location' => 'HTTP Test Pickup, Lagos',
        'pickup_lat'      => $RIDER_LAT,
        'pickup_lng'      => $RIDER_LNG,
        'destination'     => 'HTTP Test Destination, Lagos',
        'destination_lat' => $km_north($RIDER_LAT, 6),
        'destination_lng' => $RIDER_LNG,
        'notes'           => 'blue gate',
    ]);
    $created = $r['json']['data'] ?? [];
    $reqPub = $created['request_public_id'] ?? '';
    check('rider create returns success', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? $r['body']);
    check('create returns a public request id', $reqPub !== '');

    // The spec's hard requirement: a REAL row, not a frontend illusion.
    $row = $pdo->prepare("SELECT * FROM ride_requests WHERE public_id = ?");
    $row->execute([$reqPub]);
    $req = $row->fetch();
    check('DB: ride_requests row exists', (bool)$req);
    check('DB: request_type = private_pickup', ($req['request_type'] ?? '') === 'private_pickup');
    check('DB: status = pending', ($req['status'] ?? '') === 'pending');
    check('DB: rider_id is the logged-in rider', (int)($req['rider_id'] ?? 0) === $riderId);
    check('DB: pickup coordinates persisted', abs((float)($req['pickup_lat'] ?? 0) - $RIDER_LAT) < 0.0001
                                           && abs((float)($req['pickup_lng'] ?? 0) - $RIDER_LNG) < 0.0001);
    check('DB: destination coordinates persisted', (float)($req['destination_lat'] ?? 0) > 0);
    check('DB: expires_at set (TTL)', !empty($req['expires_at']));
    check('DB: fare persisted server-side', (float)($req['price'] ?? 0) > 0);
    check('DB: platform fee persisted server-side', (float)($req['platform_fee'] ?? 0) > 0);

    check('response reports drivers notified', (int)($created['drivers_notified'] ?? 0) === 2, 'got ' . ($created['drivers_notified'] ?? 'n/a'));

    // ---------- notifications: the audible-alert payload ----------
    // Scoped to THIS request's public id so an unrelated notification can
    // never make the count pass.
    $n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND related_public_id = ?");
    $n->execute([$near['id'], $reqPub]);
    check('DB: near driver got a notification row', (int)$n->fetchColumn() === 1);
    $n->execute([$far['id'], $reqPub]);
    check('DB: 21km driver got NO notification', (int)$n->fetchColumn() === 0);

    $nrow = $pdo->prepare("SELECT notification_type, priority, action, meta FROM notifications WHERE user_id = ? AND related_public_id = ? ORDER BY id DESC LIMIT 1");
    $nrow->execute([$near['id'], $reqPub]);
    $notif = $nrow->fetch() ?: [];
    check('notification is priority=critical (breaks through mute rules)', ($notif['priority'] ?? '') === 'critical');
    check('notification action routes to the pickup request', ($notif['action'] ?? '') === 'open_pickup_request');

    $m = json_decode((string)($notif['meta'] ?? ''), true) ?: [];
    // The sound name must be one the client sound-manager actually knows AND
    // the file must exist on disk, or the "beep" is a silent no-op.
    $soundJs = file_get_contents(__DIR__ . '/../assets/js/sound-manager.js');
    $soundName = $m['sound'] ?? '';
    check('notification carries a sound key', $soundName !== '');
    check("sound '$soundName' is a registered sound-manager type",
        $soundName !== '' && preg_match('/^\s*' . preg_quote($soundName, '/') . '\s*:\s*[\'"]([^\'"]+)[\'"]/m', $soundJs, $sm) === 1);
    $soundFile = $sm[1] ?? '';
    check("sound file '$soundFile' exists on disk",
        $soundFile !== '' && is_file(__DIR__ . '/../assets/sounds/' . $soundFile));
    check('notification meta carries the request id for the client', ($m['request_public_id'] ?? '') === $reqPub);
    check('notification meta carries driver->pickup distance', isset($m['distance_km']) && abs((float)$m['distance_km'] - 5) < 0.6);

    // ---------- driver feed over the driver's own session ----------
    $r = http_call('near', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    $feed = $r['json']['data']['requests'] ?? ($r['json']['data'] ?? []);
    $feedIds = array_column(is_array($feed) ? $feed : [], 'request_public_id');
    check('near driver feed contains the request', in_array($reqPub, $feedIds, true), json_encode($feedIds));

    $item = null;
    foreach ((array)$feed as $f) { if (($f['request_public_id'] ?? '') === $reqPub) { $item = $f; break; } }
    check('feed item shows pickup location', !empty($item['pickup_location']));
    check('feed item shows destination', !empty($item['destination']));
    check('feed item shows distance to pickup', isset($item['distance_to_pickup_km']) && (float)$item['distance_to_pickup_km'] > 0);
    check('feed item distance ~5km (driver->pickup, not destination)',
        isset($item['distance_to_pickup_km']) && abs((float)$item['distance_to_pickup_km'] - 5) < 0.6,
        (string)($item['distance_to_pickup_km'] ?? 'n/a'));
    check('feed item shows the trip distance separately', isset($item['trip_distance_km']) && (float)$item['trip_distance_km'] > 0);
    check('feed item shows fare', isset($item['fare']) && (float)$item['fare'] > 0);
    check('feed item shows rider name', !empty($item['rider_name']));
    check('feed item shows the TTL deadline', !empty($item['expires_at']));

    $r = http_call('far', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    $farFeed = $r['json']['data']['requests'] ?? ($r['json']['data'] ?? []);
    check('21km driver feed does NOT contain the request',
        !in_array($reqPub, array_column(is_array($farFeed) ? $farFeed : [], 'request_public_id'), true));

    // ---------- rider polls status ----------
    $r = http_call('rider', "$BASE/api/private-pickup.php?" . http_build_query([
        'action' => 'status', 'request_public_id' => $reqPub,
    ]));
    check('rider status poll works', ($r['json']['data']['status'] ?? '') === 'pending');

    // A different rider must not be able to read it.
    $r = http_call('near', "$BASE/api/private-pickup.php?" . http_build_query([
        'action' => 'status', 'request_public_id' => $reqPub,
    ]));
    check('driver CANNOT read the rider status endpoint', ($r['json']['status'] ?? '') === 'error');

    // ---------- accept: two drivers race over separate connections ----------
    $mh = curl_multi_init();
    $handles = [];
    foreach (['near', 'near2'] as $who) {
        $jar = $COOKIE_DIR . "/$who.txt";
        $ch = curl_init("$BASE/api/private-pickup.php");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR      => $jar,
            CURLOPT_COOKIEFILE     => $jar,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['action' => 'accept', 'request_public_id' => $reqPub]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$who] = $ch;
    }
    $running = null;
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
    $accepts = [];
    foreach ($handles as $who => $ch) {
        $accepts[$who] = json_decode((string)curl_multi_getcontent($ch), true);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    $winners = array_keys(array_filter($accepts, fn($a) => ($a['status'] ?? '') === 'success'));
    check('exactly ONE driver wins the concurrent accept', count($winners) === 1,
        'winners: ' . json_encode($winners) . ' payloads: ' . json_encode($accepts));

    $row->execute([$reqPub]);
    $req2 = $row->fetch();
    // Accept claims the row at 'accepted', then advances it to 'in_progress'
    // in the same transaction once the trip is spawned — so the terminal
    // state of a won request is in_progress, and it is no longer claimable.
    check('DB: request left the pending state', ($req2['status'] ?? '') !== 'pending');
    check('DB: request status = in_progress (claimed + trip spawned)', ($req2['status'] ?? '') === 'in_progress', (string)($req2['status'] ?? ''));
    check('DB: accepted_at stamped', !empty($req2['accepted_at']));
    check('DB: exactly one driver_id recorded', !empty($req2['driver_id']));
    check('DB: driver_id is one of the two racers',
        in_array((int)$req2['driver_id'], [$near['id'], $near2['id']], true));
    check('DB: matched_ride_share_id set', !empty($req2['matched_ride_share_id']));
    if (!empty($req2['matched_ride_share_id'])) { $RS[] = (int)$req2['matched_ride_share_id']; }

    // The accepted trip must be a private, reserved ride owned by the winner.
    if (!empty($req2['matched_ride_share_id'])) {
        $rs = $pdo->prepare("SELECT ride_mode, vehicle_reserved, driver_id, status FROM ride_shares WHERE id = ?");
        $rs->execute([(int)$req2['matched_ride_share_id']]);
        $share = $rs->fetch();
        check('DB: spawned ride_share is ride_mode=private', ($share['ride_mode'] ?? '') === 'private');
        check('DB: spawned ride_share is vehicle_reserved', (int)($share['vehicle_reserved'] ?? 0) === 1);
        check('DB: spawned ride_share belongs to the winning driver', (int)($share['driver_id'] ?? 0) === (int)$req2['driver_id']);
    }

    // ---------- loser is told, rider is told ----------
    $loser = $winners ? ($winners[0] === 'near' ? $near2 : $near) : null;
    if ($loser) {
        $loserSaid = $accepts[$winners[0] === 'near' ? 'near2' : 'near'];
        check('losing driver gets a clear error, not a silent failure',
            ($loserSaid['status'] ?? '') === 'error' && !empty($loserSaid['message']));
    }
    $n2 = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND related_table = 'ride_shares'");
    $n2->execute([$riderId]);
    check('DB: rider notified that a driver accepted', (int)$n2->fetchColumn() >= 1);

    // ---------- rider status flips to accepted ----------
    $r = http_call('rider', "$BASE/api/private-pickup.php?" . http_build_query([
        'action' => 'status', 'request_public_id' => $reqPub,
    ]));
    $st = $r['json']['data'] ?? [];
    // rider-private-pickup.js treats accepted OR in_progress as "Driver found",
    // so this is exactly what flips the rider's screen out of Searching.
    check('rider status now reports a matched state', in_array($st['status'] ?? '', ['accepted', 'in_progress'], true), (string)($st['status'] ?? ''));
    check('rider status names the driver', !empty($st['driver_name']));
    check('rider status exposes the trip to track', !empty($st['ride_public_id']));

    // ---------- accepted request leaves the other drivers feed ----------
    $r = http_call('near', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    $feed3 = $r['json']['data']['requests'] ?? ($r['json']['data'] ?? []);
    check('accepted request no longer appears as pending in the feed',
        !in_array($reqPub, array_column(is_array($feed3) ? $feed3 : [], 'request_public_id'), true));

    // ---------- JOIN RIDE ISOLATION ----------
    // Nothing above may have touched Join Ride demand. Count before/after is
    // proven by the request_type of every non-fixture row still being intact.
    $mix = $pdo->query("SELECT request_type, COUNT(*) c FROM ride_requests GROUP BY request_type")->fetchAll();
    $types = [];
    foreach ($mix as $mrow) { $types[$mrow['request_type']] = (int)$mrow['c']; }
    check('join_ride rows still exist and are still labelled join_ride', ($types['join_ride'] ?? 0) > 0, json_encode($types));
    check('no ride_requests row has an empty request_type',
        (int)$pdo->query("SELECT COUNT(*) FROM ride_requests WHERE request_type IS NULL OR request_type = ''")->fetchColumn() === 0);

    // The rider's Join Ride screen must still function while a private
    // pickup exists for that same rider.
    $r = http_call('rider', "$BASE/api/ride-requests.php?" . http_build_query(['action' => 'my_requests']));
    check('Join Ride rider API still responds for this rider', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? $r['body']);
    // ...and the private pickup must NOT leak into the Join Ride list.
    $jr = $r['json']['data']['requests'] ?? ($r['json']['data'] ?? []);
    check('private pickup does NOT appear in the Join Ride list',
        !in_array($reqPub, array_column(is_array($jr) ? $jr : [], 'public_id'), true));

    // ---------- offline driver stops receiving pickups but keeps Join Ride ----------
    $pdo->prepare("UPDATE users SET is_online = 0 WHERE id = ?")->execute([$near['id']]);
    $r2 = http_call('rider', "$BASE/api/private-pickup.php", [
        'action'          => 'create',
        'pickup_location' => 'Second HTTP Pickup',
        'pickup_lat'      => $RIDER_LAT,
        'pickup_lng'      => $RIDER_LNG,
        'destination'     => 'Second HTTP Destination',
        'destination_lat' => $km_north($RIDER_LAT, 6),
        'destination_lng' => $RIDER_LNG,
    ]);
    // The rider already has an accepted request, so this must be refused —
    // which is itself the correct one-active-request behaviour.
    check('rider with an active pickup cannot open a second one', ($r2['json']['status'] ?? '') === 'error', $r2['json']['message'] ?? '');

    // Criterion #7: going offline gates PRIVATE PICKUP only. The offline
    // driver must still reach the Join Ride driver feed.
    $r = http_call('near', "$BASE/api/ride-requests.php?" . http_build_query(['action' => 'driver_feed']));
    check('offline driver can STILL use Join Ride (offline gates pickup only)', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? $r['body']);
    // ...but is correctly shut out of the pickup feed.
    $r = http_call('near', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    check('offline driver is NOT eligible for the pickup feed',
        ($r['json']['data']['eligible'] ?? true) === false, json_encode($r['json']['data'] ?? []));

    // ---------- criterion #6/#7: the driver can actually GO ONLINE ----------
    // Regression guard for a real deadlock: toggle-online used to gate going
    // online on the FULL private_pickup eligibility check, which itself
    // requires is_online. That made being online a precondition of going
    // online, so an offline driver could never come online through the UI —
    // is_online could only ever be cleared, never set. Every other test
    // seeded is_online=1 directly in SQL, which is exactly why none of them
    // caught it. This drives the real endpoint from a real offline state.
    $onlineState = fn() => (int)$pdo->query("SELECT is_online FROM users WHERE id = " . (int)$near['id'])->fetchColumn();
    check('driver really is offline before the toggle', $onlineState() === 0);

    $r = http_call('near', "$BASE/api/toggle-online.php", ['is_online' => 1]);
    check('offline driver CAN go online via api/toggle-online.php',
        ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? $r['body']);
    check('going online persisted to the database', $onlineState() === 1);

    // Now online, the same driver becomes eligible for the pickup feed again.
    $r = http_call('near', "$BASE/api/private-pickup.php", ['action' => 'feed']);
    check('driver is eligible for the pickup feed once online',
        ($r['json']['data']['eligible'] ?? false) === true, json_encode($r['json']['data'] ?? []));

    // Offline and back online again — the toggle is repeatable, not one-shot.
    $r = http_call('near', "$BASE/api/toggle-online.php", ['is_online' => 0]);
    check('driver can go offline again', ($r['json']['status'] ?? '') === 'success' && $onlineState() === 0, $r['json']['message'] ?? '');
    $r = http_call('near', "$BASE/api/toggle-online.php", ['is_online' => 1]);
    check('driver can go online a second time (toggle is repeatable)',
        ($r['json']['status'] ?? '') === 'success' && $onlineState() === 1, $r['json']['message'] ?? '');

    // The online gate must still REJECT a driver who lacks the static
    // capability — the fix must not have turned it into a no-op. Revoking
    // today's availability is a capability failure, not a liveness one.
    $pdo->prepare("UPDATE users SET is_online = 0, pickup_available_today = 0 WHERE id = ?")->execute([$near['id']]);
    $r = http_call('near', "$BASE/api/toggle-online.php", ['is_online' => 1]);
    check('driver who has NOT opted in today is still refused going online',
        ($r['json']['status'] ?? '') === 'error' && ($r['json']['data']['code'] ?? '') === 'not_available_today',
        json_encode($r['json'] ?? []));
    check('refused toggle did not write is_online', $onlineState() === 0);

    // A non-keke driver must likewise never be able to flip themselves online.
    $pdo->prepare("UPDATE users SET pickup_available_today = 1 WHERE id = ?")->execute([$near['id']]);
    $pdo->prepare("UPDATE vehicles SET vehicle_type = 'car' WHERE driver_id = ?")->execute([$near['id']]);
    $pdo->prepare("UPDATE users SET is_keke_driver = 0 WHERE id = ?")->execute([$near['id']]);
    $r = http_call('near', "$BASE/api/toggle-online.php", ['is_online' => 1]);
    check('non-keke driver is still refused going online',
        ($r['json']['status'] ?? '') === 'error' && ($r['json']['data']['code'] ?? '') === 'not_keke',
        json_encode($r['json'] ?? []));
    check('refused non-keke toggle did not write is_online', $onlineState() === 0);

    cleanup($pdo, $U, $RS);
    echo "\nfixture cleaned up\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    cleanup($pdo, $U, $RS);
    $fail++;
}

foreach (glob($COOKIE_DIR . '/*') as $f) { @unlink($f); }
@rmdir($COOKIE_DIR);

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
