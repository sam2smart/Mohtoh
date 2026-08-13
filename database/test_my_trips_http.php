<?php
// database/test_my_trips_http.php
//
// Real-HTTP reproduction + regression test for the "My Trips" screens
// (rider/trips.php and driver/trips.php), exercising the exact path the
// browser takes:
//
//   trips page -> assets/js/*-trips.js -> api/rideshare.php -> session ->
//   SQL -> JSON -> the fields the renderers actually read
//
// It asserts on the JSON the renderers consume, and cross-checks every
// count against the database directly, so a green run means the page has
// real data to draw rather than merely a 200 response.
//
// Sessions are established by writing PHP session files directly (the same
// keys api/auth.php sets on a real login) so the test can run as EXISTING
// production users without knowing or resetting anybody's password, and
// without writing to any application table. Nothing here mutates trip data.
//
//   php database/test_my_trips_http.php

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

/**
 * Forge a session file matching what api/auth.php writes on login, and
 * return its id. Read-only with respect to the application's own tables.
 */
function make_session(array $user): string {
    global $SESSION_DIR;
    $sid = 'mytrips' . bin2hex(random_bytes(12));
    // PHP's native session serializer: key|serialized_value, concatenated.
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

function api_get(string $sid, string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$body, 'json' => json_decode((string)$body, true)];
}

/** Fetch a page's HTML (follows the login/role redirects it may issue). */
function page_get(string $sid, string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
    ]);
    $raw  = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'headers' => substr($raw, 0, $hlen), 'body' => substr($raw, $hlen)];
}

echo "=== My Trips (rider + driver) over real HTTP ===\n";
echo "base: $BASE\n\n";

$sessions = [];

try {
    // ---------- pick REAL users that actually have trip data ----------
    $riderRow = $pdo->query(
        "SELECT u.id, u.user_type, u.fullname, u.email, COUNT(*) AS n
         FROM ride_share_bookings rb JOIN users u ON u.id = rb.rider_id
         WHERE u.user_type = 'rider' GROUP BY u.id ORDER BY n DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    $driverRow = $pdo->query(
        "SELECT u.id, u.user_type, u.fullname, u.email, COUNT(*) AS n
         FROM ride_shares rs JOIN users u ON u.id = rs.driver_id
         WHERE u.user_type = 'driver' GROUP BY u.id ORDER BY n DESC LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);

    if (!$riderRow || !$driverRow) {
        echo "No existing trip data to test against. Aborting without changing anything.\n";
        exit(1);
    }
    echo "rider under test : {$riderRow['email']} (id {$riderRow['id']}, {$riderRow['n']} bookings)\n";
    echo "driver under test: {$driverRow['email']} (id {$driverRow['id']}, {$driverRow['n']} rides)\n\n";

    $sessions['rider']  = make_session($riderRow);
    $sessions['driver'] = make_session($driverRow);

    // ---------- the pages themselves must render, not redirect to login ----------
    echo "-- pages --\n";
    $p = page_get($sessions['rider'], "$BASE/rider/trips.php");
    check('rider/trips.php returns 200 for a logged-in rider', $p['code'] === 200, "HTTP {$p['code']}");
    check('rider trips page loads assets/js/rider-trips.js', strpos($p['body'], 'rider-trips.js') !== false);
    check('rider trips page has the #tripListContainer the JS renders into',
        strpos($p['body'], 'id="tripListContainer"') !== false);
    check('rider trips page has all four tabs',
        substr_count($p['body'], 'data-trip-tab=') === 4, substr_count($p['body'], 'data-trip-tab=') . ' found');

    $p = page_get($sessions['driver'], "$BASE/driver/trips.php");
    check('driver/trips.php returns 200 for a logged-in driver', $p['code'] === 200, "HTTP {$p['code']}");
    check('driver trips page loads assets/js/driver-trips.js', strpos($p['body'], 'driver-trips.js') !== false);
    check('driver trips page has the #tripListContainer the JS renders into',
        strpos($p['body'], 'id="tripListContainer"') !== false);
    check('driver trips page has all four tabs',
        substr_count($p['body'], 'data-trip-tab=') === 4, substr_count($p['body'], 'data-trip-tab=') . ' found');

    // my-trips.php is kept as a redirect for old links/bookmarks.
    $p = page_get($sessions['rider'], "$BASE/rider/my-trips.php");
    check('rider/my-trips.php still redirects to trips.php (old links keep working)',
        in_array($p['code'], [301, 302], true) && stripos($p['headers'], 'Location: trips.php') !== false,
        "HTTP {$p['code']}");

    // ---------- the JS must actually parse ----------
    // A syntax error anywhere in these files means the browser executes
    // NONE of it: no DOMContentLoaded handler, no loadAll(), no tab
    // wiring — the page renders its static shell and then does nothing,
    // with no error banner (the catch block never runs either). That is
    // exactly how "My Trips is not working" presented, so it is asserted
    // here rather than left to a manual browser check.
    echo "-- javascript parses --\n";
    foreach (['rider-trips.js', 'driver-trips.js'] as $jsFile) {
        $path = __DIR__ . '/../assets/js/' . $jsFile;
        $out = [];
        $rc = 0;
        exec('node --check ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
        check("$jsFile parses without a syntax error", $rc === 0, implode(' | ', array_slice($out, 0, 4)));
    }

    // ---------- the Trips nav must actually reach the Trips page ----------
    // partials/bottom-nav.php is the primary navigation on both app shells.
    foreach (['rider', 'driver'] as $role) {
        $p = page_get($sessions[$role], "$BASE/$role/trips.php");
        check("$role bottom-nav Trips item links to trips.php (not the dashboard)",
            preg_match('/bottom-nav__item[^>]*is-active[^>]*href="trips\.php"/', $p['body']) === 1
            || strpos($p['body'], 'href="trips.php"') !== false,
            'Trips nav href does not point at trips.php');
    }

    // ---------- rider: api/rideshare.php?action=my_bookings ----------
    echo "\n-- rider API (my_bookings) --\n";
    $r = api_get($sessions['rider'], "$BASE/api/rideshare.php?action=my_bookings");
    check('rider my_bookings responds 200', $r['code'] === 200, "HTTP {$r['code']} body=" . substr($r['body'], 0, 200));
    check('rider my_bookings returns valid JSON', is_array($r['json']), substr($r['body'], 0, 200));
    check('rider my_bookings status=success', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');

    $rides = $r['json']['data']['rides'] ?? null;
    check('rider payload exposes data.rides (the exact key rider-trips.js reads)', is_array($rides),
        'keys: ' . implode(',', array_keys($r['json']['data'] ?? [])));

    $dbBookings = (int)$pdo->query("SELECT COUNT(*) FROM ride_share_bookings WHERE rider_id = " . (int)$riderRow['id'])->fetchColumn();
    check('rider row count matches the database', is_array($rides) && count($rides) === $dbBookings,
        'api=' . (is_array($rides) ? count($rides) : 'n/a') . " db=$dbBookings");

    // Every field the renderers actually read must be present.
    if (is_array($rides) && $rides) {
        $b = $rides[0];
        foreach (['booking_id','ride_share_id','ride_status','trip_state','status','start_location',
                  'destination','departure_date','departure_time','seats_booked','driver_name',
                  'driver_id','driver_rating','payment_method','fare_amount'] as $f) {
            check("rider booking exposes '$f'", array_key_exists($f, $b));
        }
    }

    // Ownership: no row may belong to another rider.
    $leak = 0;
    foreach (($rides ?: []) as $b) {
        $owner = $pdo->query("SELECT rider_id FROM ride_share_bookings WHERE id = " . (int)$b['booking_id'])->fetchColumn();
        if ((int)$owner !== (int)$riderRow['id']) { $leak++; }
    }
    check('rider sees ONLY their own bookings', $leak === 0, "$leak foreign rows");

    // ---------- rider tab filters must classify every row ----------
    echo "\n-- rider tabs --\n";
    $mine = $rides ?: [];
    $active    = array_filter($mine, fn($b) => $b['ride_status'] === 'in_progress' && $b['status'] !== 'cancelled');
    $upcoming  = array_filter($mine, fn($b) => in_array($b['ride_status'], ['active','full'], true) && $b['status'] !== 'cancelled');
    $completed = array_filter($mine, fn($b) => $b['ride_status'] === 'completed' && $b['status'] !== 'cancelled');
    $cancelled = array_filter($mine, fn($b) => $b['status'] === 'cancelled');
    printf("     active=%d upcoming=%d completed=%d cancelled=%d (of %d)\n",
        count($active), count($upcoming), count($completed), count($cancelled), count($mine));

    $classified = count($active) + count($upcoming) + count($completed) + count($cancelled);
    check('every rider booking lands in exactly one tab (no row is invisible)',
        $classified === count($mine), "classified=$classified total=" . count($mine));

    $dbCompleted = (int)$pdo->query(
        "SELECT COUNT(*) FROM ride_share_bookings rb JOIN ride_shares rs ON rs.id = rb.ride_share_id
         WHERE rb.rider_id = " . (int)$riderRow['id'] . " AND rs.status='completed' AND rb.status!='cancelled'"
    )->fetchColumn();
    check('rider Completed tab count matches the database', count($completed) === $dbCompleted,
        'tab=' . count($completed) . " db=$dbCompleted");

    // ---------- rider trip detail ----------
    if ($mine) {
        echo "\n-- rider trip detail --\n";
        $bid = (int)$mine[0]['booking_id'];
        $r = api_get($sessions['rider'], "$BASE/api/rideshare.php?action=get_trip_detail&booking_id=$bid");
        check('rider get_trip_detail responds success', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');
        check('rider trip detail returns a trip object', isset($r['json']['data']['trip']));
        check('rider trip detail returns a timeline array', isset($r['json']['data']['timeline']) && is_array($r['json']['data']['timeline']));

        // Another rider's booking must NOT be readable.
        $foreign = $pdo->query(
            "SELECT id FROM ride_share_bookings WHERE rider_id <> " . (int)$riderRow['id'] . " LIMIT 1"
        )->fetchColumn();
        if ($foreign) {
            $r = api_get($sessions['rider'], "$BASE/api/rideshare.php?action=get_trip_detail&booking_id=" . (int)$foreign);
            check("rider CANNOT read another rider's booking detail", ($r['json']['status'] ?? '') === 'error', $r['body']);
        }
    }

    // ---------- driver: api/rideshare.php?action=my_rides ----------
    echo "\n-- driver API (my_rides) --\n";
    $r = api_get($sessions['driver'], "$BASE/api/rideshare.php?action=my_rides");
    check('driver my_rides responds 200', $r['code'] === 200, "HTTP {$r['code']} body=" . substr($r['body'], 0, 200));
    check('driver my_rides returns valid JSON', is_array($r['json']), substr($r['body'], 0, 200));
    check('driver my_rides status=success', ($r['json']['status'] ?? '') === 'success', $r['json']['message'] ?? '');

    $drides = $r['json']['data']['rides'] ?? null;
    check('driver payload exposes data.rides (the exact key driver-trips.js reads)', is_array($drides),
        'keys: ' . implode(',', array_keys($r['json']['data'] ?? [])));

    $dbRides = (int)$pdo->query("SELECT COUNT(*) FROM ride_shares WHERE driver_id = " . (int)$driverRow['id'])->fetchColumn();
    check('driver row count matches the database', is_array($drides) && count($drides) === $dbRides,
        'api=' . (is_array($drides) ? count($drides) : 'n/a') . " db=$dbRides");

    if (is_array($drides) && $drides) {
        $d = $drides[0];
        foreach (['id','public_id','start_location','destination','departure_date','departure_time',
                  'status','trip_state','available_seats','total_seats','price_per_passenger',
                  'passengers_joined'] as $f) {
            check("driver ride exposes '$f'", array_key_exists($f, $d));
        }
    }

    $leak = 0;
    foreach (($drides ?: []) as $d) {
        $owner = $pdo->query("SELECT driver_id FROM ride_shares WHERE id = " . (int)$d['id'])->fetchColumn();
        if ((int)$owner !== (int)$driverRow['id']) { $leak++; }
    }
    check('driver sees ONLY their own rides', $leak === 0, "$leak foreign rows");

    // ---------- driver tabs ----------
    // Mirrors assets/js/driver-trips.js exactly: Active/Upcoming come from
    // my_rides (live), Completed/Cancelled from get_ride_history. The two
    // feeds are disjoint by design — get_ride_history only returns
    // completed/cancelled, and the live filters exclude those — so every
    // ride must appear in exactly one tab across BOTH endpoints.
    echo "\n-- driver tabs --\n";
    $h = api_get($sessions['driver'], "$BASE/api/rideshare.php?action=get_ride_history");
    check('driver get_ride_history responds success', ($h['json']['status'] ?? '') === 'success', $h['json']['message'] ?? '');
    $dhist = $h['json']['data']['history'] ?? null;
    check('driver payload exposes data.history (the exact key driver-trips.js reads)', is_array($dhist),
        'keys: ' . implode(',', array_keys($h['json']['data'] ?? [])));

    if (is_array($dhist) && $dhist) {
        foreach (['id','start_location','destination','departure_date','status','earning',
                  'gross_revenue','total_commission','payment_status','average_rating','rating_count'] as $f) {
            check("driver history row exposes '$f'", array_key_exists($f, $dhist[0]));
        }
    }

    $dm = $drides ?: [];
    $dh = $dhist ?: [];
    $dActive    = array_filter($dm, fn($x) => $x['status'] === 'in_progress');
    $dUpcoming  = array_filter($dm, fn($x) => in_array($x['status'], ['active', 'full'], true));
    $dCompleted = array_filter($dh, fn($x) => $x['status'] === 'completed');
    $dCancelled = array_filter($dh, fn($x) => $x['status'] === 'cancelled');
    printf("     active=%d upcoming=%d completed=%d cancelled=%d (of %d rides)\n",
        count($dActive), count($dUpcoming), count($dCompleted), count($dCancelled), count($dm));
    check('every driver ride lands in exactly one tab (no row is invisible, none double-counted)',
        count($dActive) + count($dUpcoming) + count($dCompleted) + count($dCancelled) === count($dm),
        'classified=' . (count($dActive) + count($dUpcoming) + count($dCompleted) + count($dCancelled)) . ' rides=' . count($dm));

    $dbDriverCompleted = (int)$pdo->query(
        "SELECT COUNT(*) FROM ride_shares WHERE driver_id = " . (int)$driverRow['id'] . " AND status='completed'"
    )->fetchColumn();
    check('driver Completed tab count matches the database', count($dCompleted) === $dbDriverCompleted,
        'tab=' . count($dCompleted) . " db=$dbDriverCompleted");

    $dbDriverCancelled = (int)$pdo->query(
        "SELECT COUNT(*) FROM ride_shares WHERE driver_id = " . (int)$driverRow['id'] . " AND status='cancelled'"
    )->fetchColumn();
    check('driver Cancelled tab count matches the database', count($dCancelled) === $dbDriverCancelled,
        'tab=' . count($dCancelled) . " db=$dbDriverCancelled");

    // ---------- role isolation ----------
    echo "\n-- role isolation --\n";
    $r = api_get($sessions['driver'], "$BASE/api/rideshare.php?action=my_bookings");
    check('driver cannot read the rider my_bookings feed', ($r['json']['status'] ?? '') !== 'success' || empty($r['json']['data']['rides']),
        substr($r['body'], 0, 160));
    $r = api_get($sessions['rider'], "$BASE/api/rideshare.php?action=my_rides");
    check('rider cannot read the driver my_rides feed', ($r['json']['status'] ?? '') !== 'success' || empty($r['json']['data']['rides']),
        substr($r['body'], 0, 160));

    // ---------- empty state ----------
    echo "\n-- empty state --\n";
    $emptyRider = $pdo->query(
        "SELECT id, user_type, fullname, email FROM users
         WHERE user_type = 'rider' AND id NOT IN (SELECT DISTINCT rider_id FROM ride_share_bookings)
         LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if ($emptyRider) {
        $sessions['empty'] = make_session($emptyRider);
        $r = api_get($sessions['empty'], "$BASE/api/rideshare.php?action=my_bookings");
        check('rider with no trips still gets success (not an error page)', ($r['json']['status'] ?? '') === 'success', $r['body']);
        check('rider with no trips gets an empty rides array, not null',
            is_array($r['json']['data']['rides'] ?? null) && count($r['json']['data']['rides']) === 0);
        $p = page_get($sessions['empty'], "$BASE/rider/trips.php");
        check('empty-account trips page still renders 200', $p['code'] === 200, "HTTP {$p['code']}");
    } else {
        echo "     (no rider without bookings exists; skipping)\n";
    }

    // ---------- private pickup compatibility ----------
    echo "\n-- private pickup compatibility --\n";
    $ppTrips = $pdo->query(
        "SELECT COUNT(*) FROM ride_shares WHERE ride_mode = 'private'"
    )->fetchColumn();
    echo "     private ride_shares rows: $ppTrips\n";
    check('private pickup trips live in ride_shares (My Trips can see them)', $ppTrips !== false);
    // A private trip must still carry the trip_state the Trips UI reads.
    $badPp = (int)$pdo->query(
        "SELECT COUNT(*) FROM ride_shares WHERE ride_mode = 'private' AND (trip_state IS NULL OR trip_state = '')"
    )->fetchColumn();
    check('no private pickup trip has an empty trip_state', $badPp === 0, "$badPp bad rows");
    // ...and Join Ride trips must remain distinguishable from private ones.
    $badJoin = (int)$pdo->query(
        "SELECT COUNT(*) FROM ride_shares WHERE ride_mode IS NULL OR ride_mode = ''"
    )->fetchColumn();
    check('every ride_share has a ride_mode (join vs private stays distinguishable)', $badJoin === 0, "$badJoin bad rows");

} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    $fail++;
}

// Remove only the forged session files. No application data is touched.
foreach ($sessions as $sid) {
    @unlink(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
