<?php
// database/seed_browser_fixture.php
//
// Seeds ONE rider + ONE keke driver + ONE pending private pickup request so
// the flow can be exercised in a real browser (the audible-alert check the
// spec requires cannot be done from PHP alone).
//
// Usage:
//   php database/seed_browser_fixture.php          -> create, print credentials
//   php database/seed_browser_fixture.php --request-> also create a live request
//   php database/seed_browser_fixture.php --clean  -> remove everything it made
//
// Everything it creates is tagged with the BROWSER_TAG marker so --clean can
// find and remove it precisely, without touching any other row.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/private_pickup.php';

const TAG = 'browserfix';
const PW  = 'BrowserTest#2026';

$mode = $argv[1] ?? '';

function clean(PDO $pdo) {
    $ids = $pdo->query("SELECT id FROM users WHERE user_id LIKE '" . TAG . "-%'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$ids) { echo "nothing to clean\n"; return; }
    $in = implode(',', array_map('intval', $ids));
    $rs = $pdo->query("SELECT id FROM ride_shares WHERE driver_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
    $inRS = $rs ? implode(',', array_map('intval', $rs)) : '0';
    $pdo->exec("DELETE FROM trip_state_events WHERE ride_share_id IN ($inRS)");
    $pdo->exec("DELETE FROM ride_commission_reservations WHERE driver_id IN ($in)");
    $pdo->exec("DELETE FROM notifications WHERE user_id IN ($in)");
    foreach (['wallet_transactions','wallet_logs','wallet_events'] as $t) {
        try { $pdo->exec("DELETE FROM $t WHERE user_id IN ($in)"); } catch (Throwable $e) {}
    }
    $pdo->exec("DELETE FROM ride_shares WHERE id IN ($inRS)");
    $pdo->exec("DELETE FROM ride_requests WHERE rider_id IN ($in) OR driver_id IN ($in)");
    $pdo->exec("DELETE FROM vehicles WHERE driver_id IN ($in)");
    $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    echo "cleaned " . count($ids) . " fixture users and their rows\n";
}

if ($mode === '--clean') { clean($pdo); exit(0); }

clean($pdo);   // always start from a known-empty state

$hash = password_hash(PW, PASSWORD_DEFAULT);
$LAT = 6.5244; $LNG = 3.3792;
$now = $pdo->query("SELECT NOW()")->fetchColumn();

$riderEmail  = 'browser.rider@mohtoh.test';
$driverEmail = 'browser.driver@mohtoh.test';

$pdo->prepare(
    "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
        email_verified, phone_verified, is_verified, latitude, longitude, last_location_update)
     VALUES (?, 'Browser Rider', ?, ?, ?, 'rider', 1, 1, 1, ?, ?, ?)"
)->execute([TAG.'-r-'.uniqid(), '0800'.random_int(1000000,9999999), $riderEmail, $hash, $LAT, $LNG, $now]);
$riderId = (int)$pdo->lastInsertId();

// Driver sits 5 KM north of the rider — inside the 20 KM radius.
$pdo->prepare(
    "INSERT INTO users (user_id, fullname, phone, email, password, user_type,
        email_verified, phone_verified, is_verified, is_online, is_keke_driver,
        private_pickup_enabled, pickup_available_today, pickup_availability_date,
        latitude, longitude, last_location_update, wallet_balance)
     VALUES (?, 'Browser Keke Driver', ?, ?, ?, 'driver', 1, 1, 1, 1, 1, 1, 1, CURDATE(), ?, ?, ?, 100000)"
)->execute([TAG.'-d-'.uniqid(), '0801'.random_int(1000000,9999999), $driverEmail, $hash, $LAT + (5/111.0), $LNG, $now]);
$driverId = (int)$pdo->lastInsertId();

$pdo->prepare(
    "INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number,
        seats, passenger_capacity, vehicle_type, status, verification_status)
     VALUES (?, ?, 'Bajaj', 'RE', 2022, 'Yellow', ?, 3, 3, 'keke', 'approved', 'verified')"
)->execute([generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH'), $driverId, 'BRW-'.random_int(100,999)]);

echo "rider  : $riderEmail / " . PW . "  (id $riderId)\n";
echo "driver : $driverEmail / " . PW . "  (id $driverId)\n";

if ($mode === '--request') {
    $res = create_private_pickup_request($pdo, $riderId, [
        'pickup_location' => 'Ikeja City Mall, Lagos',
        'pickup_lat'      => $LAT,
        'pickup_lng'      => $LNG,
        'destination'     => 'Lekki Phase 1, Lagos',
        'destination_lat' => $LAT + (6/111.0),
        'destination_lng' => $LNG,
        'notes'           => 'Blue gate, near the generator',
    ]);
    echo "request: " . json_encode($res['data'] ?? $res) . "\n";
}
