<?php
// database/test_my_trips_lifecycle.php
//
// Drives one whole Join Ride through its real lifecycle over HTTP —
// create -> book -> start -> complete — and after EACH transition asserts
// that the trip appears under the correct My Trips tab for BOTH the driver
// and the rider, using the exact same feeds and filters the browser uses:
//
//   driver Active   : my_rides         status === 'in_progress'
//   driver Upcoming : my_rides         status in (active, full)
//   driver Completed: get_ride_history status === 'completed'
//   rider  Active   : my_bookings      ride_status === 'in_progress'
//   rider  Upcoming : my_bookings      ride_status in (active, full)
//   rider  Completed: my_bookings      ride_status === 'completed'
//
// This is what proves the Active tab — which no existing row currently
// exercises, because no trip happens to be in progress — actually
// populates, and it doubles as the Join Ride regression check: if
// create/book/start/complete broke, this fails before the tab assertions.
//
// Everything it creates is removed in the teardown at the end, so the
// database is left exactly as it was found.
//
//   php database/test_my_trips_lifecycle.php

require_once __DIR__ . '/../includes/db.php';

$BASE = getenv('MOHTOH_BASE_URL') ?: 'http://localhost/Mostmerit/merit_drives%20-%20Copy.v1';
$SESSION_DIR = ini_get('session.save_path') ?: sys_get_temp_dir();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): bool {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  --> $detail" : '') . "\n"; }
    return $ok;
}

function make_session(array $user): string {
    global $SESSION_DIR;
    $sid = 'mtlife' . bin2hex(random_bytes(12));
    $data = '';
    foreach ([
        'user_id'   => (int)$user['id'],
        'user_type' => $user['user_type'],
        'fullname'  => $user['fullname'],
        'email'     => $user['email'],
    ] as $k => $v) {
        $data .= $k . '|' . serialize($v);
    }
    file_put_contents(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

function call(string $sid, string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? ['status' => 'parse_error', 'raw' => $body];
}

/** The driver's My Trips tabs, exactly as assets/js/driver-trips.js computes them. */
function driver_tabs(string $sid, string $base): array {
    $live = call($sid, "$base/api/rideshare.php?action=my_rides");
    $hist = call($sid, "$base/api/rideshare.php?action=get_ride_history");
    $rides   = $live['data']['rides'] ?? [];
    $history = $hist['data']['history'] ?? [];
    return [
        'active'    => array_values(array_filter($rides,   fn($r) => $r['status'] === 'in_progress')),
        'upcoming'  => array_values(array_filter($rides,   fn($r) => in_array($r['status'], ['active', 'full'], true))),
        'completed' => array_values(array_filter($history, fn($r) => $r['status'] === 'completed')),
        'cancelled' => array_values(array_filter($history, fn($r) => $r['status'] === 'cancelled')),
    ];
}

/** The rider's My Trips tabs, exactly as assets/js/rider-trips.js computes them. */
function rider_tabs(string $sid, string $base): array {
    $res = call($sid, "$base/api/rideshare.php?action=my_bookings");
    $b = $res['data']['rides'] ?? [];
    return [
        'active'    => array_values(array_filter($b, fn($x) => $x['ride_status'] === 'in_progress' && $x['status'] !== 'cancelled')),
        'upcoming'  => array_values(array_filter($b, fn($x) => in_array($x['ride_status'], ['active', 'full'], true) && $x['status'] !== 'cancelled')),
        'completed' => array_values(array_filter($b, fn($x) => $x['ride_status'] === 'completed' && $x['status'] !== 'cancelled')),
        'cancelled' => array_values(array_filter($b, fn($x) => $x['status'] === 'cancelled')),
    ];
}

function has_ride(array $tab, int $rideId): bool {
    foreach ($tab as $r) { if ((int)$r['id'] === $rideId) return true; }
    return false;
}
function has_booking_for_ride(array $tab, int $rideId): bool {
    foreach ($tab as $b) { if ((int)$b['ride_share_id'] === $rideId) return true; }
    return false;
}

echo "=== My Trips lifecycle: create -> book -> start -> complete ===\n";
echo "base: $BASE\n\n";

$sessions = [];
$rideId = 0;
$bookingId = 0;
$riderRow = null;

try {
    // A driver who can actually publish: approved vehicle + verified account.
    $driverRow = $pdo->query(
        "SELECT u.id, u.user_type, u.fullname, u.email
         FROM users u JOIN vehicles v ON v.driver_id = u.id
         WHERE u.user_type = 'driver' AND u.is_verified = 1 AND v.status = 'approved'
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $riderRow = $pdo->query(
        "SELECT id, user_type, fullname, email FROM users WHERE user_type = 'rider' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$driverRow || !$riderRow) {
        echo "No eligible driver/rider to drive the lifecycle. Aborting without changing anything.\n";
        exit(1);
    }
    echo "driver: {$driverRow['email']} (id {$driverRow['id']})\n";
    echo "rider : {$riderRow['email']} (id {$riderRow['id']})\n\n";

    $sessions['driver'] = make_session($driverRow);
    $sessions['rider']  = make_session($riderRow);

    // Give the rider enough wallet balance to book, and remember the
    // original so it can be restored in teardown.
    $origBalance = $pdo->query("SELECT wallet_balance FROM users WHERE id = " . (int)$riderRow['id'])->fetchColumn();
    $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + 100000 WHERE id = ?")
        ->execute([$riderRow['id']]);

    // ---------- 1. Driver creates a Join Ride ----------
    echo "-- 1. create --\n";
    $tomorrow = $pdo->query("SELECT DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
    $route = [
        'pickup_lat'      => 6.6018, 'pickup_lng'      => 3.3515,
        'destination_lat' => 6.4474, 'destination_lng' => 3.4736,
        'departure_date'  => $tomorrow,
        'departure_time'  => '09:00',
    ];

    // Ask the app what this route may be priced at, exactly as the real
    // Create Ride screen does — the fare band is admin-configurable, so
    // hard-coding a price here would make this test fail whenever an
    // admin retunes pricing rather than when My Trips actually breaks.
    $preview = call($sessions['driver'], "$BASE/api/rideshare.php?action=price_preview", $route);
    check('price_preview returns a fare band for the route', ($preview['status'] ?? '') === 'success', json_encode($preview));
    $price = (float)($preview['data']['recommended_price'] ?? 0);
    check('price_preview gives a usable recommended price', $price > 0, json_encode($preview['data'] ?? []));
    echo "     recommended price = $price (band {$preview['data']['min_price']}–{$preview['data']['max_price']})\n";

    // The driver must be able to cover the commission reserve to publish.
    $reserve = (float)($preview['data']['required_wallet_reserve'] ?? 0);
    $origDriverBalance = $pdo->query("SELECT wallet_balance FROM users WHERE id = " . (int)$driverRow['id'])->fetchColumn();
    if (!($preview['data']['wallet_sufficient'] ?? false)) {
        $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?")
            ->execute([$reserve + 10000, $driverRow['id']]);
        echo "     topped up driver wallet to cover the ₦$reserve commission reserve\n";
    }

    $res = call($sessions['driver'], "$BASE/api/rideshare.php?action=create", $route + [
        'pickup_location'     => 'MYTRIPS LIFECYCLE Ikeja',
        'destination'         => 'MYTRIPS LIFECYCLE Lekki',
        'total_seats'         => 3,
        'price_per_passenger' => $price,
    ]);
    check('driver can create a Join Ride (regression)', ($res['status'] ?? '') === 'success', json_encode($res));

    $rideId = (int)($res['data']['ride_id'] ?? $res['data']['id'] ?? 0);
    if (!$rideId) {
        $rideId = (int)$pdo->query(
            "SELECT id FROM ride_shares WHERE driver_id = " . (int)$driverRow['id'] .
            " AND start_location = 'MYTRIPS LIFECYCLE Ikeja' ORDER BY id DESC LIMIT 1"
        )->fetchColumn();
    }
    check('the new ride exists in ride_shares', $rideId > 0);
    echo "     ride_id = $rideId\n";

    $tabs = driver_tabs($sessions['driver'], $BASE);
    check('after create: ride shows under driver UPCOMING', has_ride($tabs['upcoming'], $rideId));
    check('after create: ride is NOT under driver Active', !has_ride($tabs['active'], $rideId));
    check('after create: ride is NOT under driver Completed', !has_ride($tabs['completed'], $rideId));

    // ---------- 2. Rider books it ----------
    echo "\n-- 2. book --\n";
    $res = call($sessions['rider'], "$BASE/api/rideshare.php?action=book", [
        'ride_share_id' => $rideId,
        'seats_booked'  => 1,
        'payment_method' => 'wallet',
    ]);
    check('rider can book the ride (regression)', ($res['status'] ?? '') === 'success', json_encode($res));

    $bookingId = (int)$pdo->query(
        "SELECT id FROM ride_share_bookings WHERE ride_share_id = $rideId AND rider_id = " . (int)$riderRow['id'] . " ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    check('the booking exists in ride_share_bookings', $bookingId > 0);

    $rt = rider_tabs($sessions['rider'], $BASE);
    check('after book: trip shows under rider UPCOMING', has_booking_for_ride($rt['upcoming'], $rideId));
    check('after book: trip is NOT under rider Active', !has_booking_for_ride($rt['active'], $rideId));

    $tabs = driver_tabs($sessions['driver'], $BASE);
    $joined = 0;
    foreach ($tabs['upcoming'] as $r) { if ((int)$r['id'] === $rideId) $joined = (int)$r['passengers_joined']; }
    check('driver Upcoming tile reflects the passenger who joined', $joined === 1, "passengers_joined=$joined");

    // ---------- 3. Driver starts the trip ----------
    echo "\n-- 3. start --\n";
    $res = call($sessions['driver'], "$BASE/api/rideshare.php?action=start_ride", ['ride_id' => $rideId]);
    check('driver can start the trip (regression)', ($res['status'] ?? '') === 'success', json_encode($res));

    $row = $pdo->query("SELECT status, trip_state FROM ride_shares WHERE id = $rideId")->fetch(PDO::FETCH_ASSOC);
    check("start sets ride_shares.status = 'in_progress'", $row['status'] === 'in_progress', json_encode($row));
    check("start advances trip_state to TRIP_STARTED", $row['trip_state'] === 'TRIP_STARTED', json_encode($row));

    $tabs = driver_tabs($sessions['driver'], $BASE);
    check('after start: ride shows under driver ACTIVE', has_ride($tabs['active'], $rideId));
    check('after start: ride left driver Upcoming', !has_ride($tabs['upcoming'], $rideId));

    $rt = rider_tabs($sessions['rider'], $BASE);
    check('after start: trip shows under rider ACTIVE', has_booking_for_ride($rt['active'], $rideId));
    check('after start: trip left rider Upcoming', !has_booking_for_ride($rt['upcoming'], $rideId));

    // The Active tile reads trip_state and live GPS fields — they must be present.
    foreach ($tabs['active'] as $r) {
        if ((int)$r['id'] !== $rideId) continue;
        foreach (['trip_state','last_location_update','current_latitude','current_longitude',
                  'destination_lat','destination_lng','passengers_joined','available_seats'] as $f) {
            check("driver Active tile has '$f'", array_key_exists($f, $r));
        }
    }

    // ---------- 4. Driver completes the trip ----------
    echo "\n-- 4. complete --\n";
    $res = call($sessions['driver'], "$BASE/api/rideshare.php?action=complete_ride", ['ride_id' => $rideId]);
    check('driver can complete the trip (regression)', ($res['status'] ?? '') === 'success', json_encode($res));

    $row = $pdo->query("SELECT status, trip_state FROM ride_shares WHERE id = $rideId")->fetch(PDO::FETCH_ASSOC);
    check("complete sets status = 'completed'", $row['status'] === 'completed', json_encode($row));
    check("complete advances trip_state to TRIP_COMPLETED", $row['trip_state'] === 'TRIP_COMPLETED', json_encode($row));

    $tabs = driver_tabs($sessions['driver'], $BASE);
    check('after complete: ride shows under driver COMPLETED', has_ride($tabs['completed'], $rideId));
    check('after complete: ride left driver Active', !has_ride($tabs['active'], $rideId));

    $rt = rider_tabs($sessions['rider'], $BASE);
    check('after complete: trip shows under rider COMPLETED', has_booking_for_ride($rt['completed'], $rideId));
    check('after complete: trip left rider Active', !has_booking_for_ride($rt['active'], $rideId));

    // ---------- 5. still exactly one tab each ----------
    echo "\n-- 5. no double-counting --\n";
    $n = (has_ride($tabs['active'], $rideId) ? 1 : 0) + (has_ride($tabs['upcoming'], $rideId) ? 1 : 0)
       + (has_ride($tabs['completed'], $rideId) ? 1 : 0) + (has_ride($tabs['cancelled'], $rideId) ? 1 : 0);
    check('the ride appears in exactly ONE driver tab', $n === 1, "appears in $n tabs");
    $m = (has_booking_for_ride($rt['active'], $rideId) ? 1 : 0) + (has_booking_for_ride($rt['upcoming'], $rideId) ? 1 : 0)
       + (has_booking_for_ride($rt['completed'], $rideId) ? 1 : 0) + (has_booking_for_ride($rt['cancelled'], $rideId) ? 1 : 0);
    check('the trip appears in exactly ONE rider tab', $m === 1, "appears in $m tabs");

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n";
    $fail++;
}

// ---------------- teardown: remove everything this test created ----------------
echo "\n-- teardown --\n";
try {
    if ($rideId) {
        $pdo->prepare("DELETE FROM booking_state_events WHERE booking_id IN (SELECT id FROM ride_share_bookings WHERE ride_share_id = ?)")->execute([$rideId]);
        $pdo->prepare("DELETE FROM trip_state_events WHERE ride_share_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM payments WHERE ride_share_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM ride_commission_reservations WHERE ride_share_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM ride_share_ratings WHERE ride_share_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM ride_share_bookings WHERE ride_share_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM notifications WHERE related_table = 'ride_shares' AND action_id = ?")->execute([$rideId]);
        $pdo->prepare("DELETE FROM ride_shares WHERE id = ?")->execute([$rideId]);
        echo "     removed ride $rideId and its bookings/payments/events\n";
    }
    if ($riderRow && isset($origBalance)) {
        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$origBalance, $riderRow['id']]);
        echo "     restored rider wallet balance\n";
    }
    // The driver's balance moves too (commission reserve out, earning and
    // released reserve back in) and may have been topped up above, so it
    // needs the same absolute restore — otherwise every run leaves the
    // driver a little richer than they started.
    if ($driverRow && isset($origDriverBalance)) {
        $pdo->prepare("UPDATE users SET wallet_balance = ? WHERE id = ?")->execute([$origDriverBalance, $driverRow['id']]);
        echo "     restored driver wallet balance\n";
    }
    // Ledger rows point at a ride that no longer exists once the row above
    // is gone; drop them so the wallet history stays consistent with it.
    if ($rideId) {
        $pdo->prepare("DELETE FROM wallet_transactions WHERE ride_share_id = ?")->execute([$rideId]);
    }
    $left = $rideId ? (int)$pdo->query("SELECT COUNT(*) FROM ride_shares WHERE id = $rideId")->fetchColumn() : 0;
    check('teardown left no trace of the test ride', $left === 0);
} catch (Throwable $e) {
    echo "TEARDOWN FAILED: " . $e->getMessage() . "\n";
    $fail++;
}

foreach ($sessions as $sid) {
    @unlink(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
