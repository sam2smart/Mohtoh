<?php
// database/test_keke_classification.php
//
// Verifies criterion #2 (Keke driver recognised system-wide, capability
// consistent with the verified keke vehicle) and criterion #3 (Keke Join
// Ride auto-uses the keke config instead of making the driver pretend to
// be a car driver).
//
// Asserts against REAL database rows, then removes every row it created.
//
// Run:  php database/test_keke_classification.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/eligibility.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else { $fail++; echo "  FAIL  $label\n"; }
}

$U = [];

function cleanup(PDO $pdo, array $U) {
    if (!$U) return;
    $in = implode(',', array_map('intval', $U));
    try {
        $pdo->exec("DELETE FROM notifications WHERE user_id IN ($in)");
        $pdo->exec("DELETE FROM vehicles WHERE driver_id IN ($in)");
        $pdo->exec("DELETE FROM users WHERE id IN ($in)");
    } catch (Throwable $e) { echo "cleanup warning: " . $e->getMessage() . "\n"; }
}

echo "=== Keke classification + Join Ride auto-config ===\n";

try {
    // ---- 1. normalizer: the actual labels the registration form posts ----
    check("normalize('Keke Napep' label) => keke", normalize_vehicle_type('keke napep') === 'keke');
    check("normalize('keke') => keke", normalize_vehicle_type('keke') === 'keke');
    check("normalize('Tricycle') => keke", normalize_vehicle_type('Tricycle') === 'keke');
    check("normalize('car') => car", normalize_vehicle_type('car') === 'car');
    check("normalize('Bus') => bus", normalize_vehicle_type('Bus') === 'bus');
    check("normalize('motorcycle') => motorcycle", normalize_vehicle_type('motorcycle') === 'motorcycle');
    check("normalize('') => ''", normalize_vehicle_type('') === '');
    check("unknown type passed through, not defaulted", normalize_vehicle_type('Tuk Tuk XL') === 'tuk tuk xl');
    check("is_keke_vehicle_type('keke napep') true", is_keke_vehicle_type('keke napep') === true);
    check("is_keke_vehicle_type('car') false", is_keke_vehicle_type('car') === false);

    // ---- 2. sync_keke_classification derives the flag from the vehicle ----
    $mk = function (string $rawType) use ($pdo, &$U) {
        $pdo->prepare("INSERT INTO users (user_id, fullname, phone, email, password, user_type, email_verified, phone_verified, is_verified) VALUES (?, 'KK Test', ?, ?, 'x', 'driver', 1, 1, 1)")
            ->execute(['kk-'.uniqid(), 'kk-'.uniqid(), 'kk-'.uniqid().'@t.co']);
        $id = (int)$pdo->lastInsertId(); $U[] = $id;
        $pdo->prepare("INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number, seats, passenger_capacity, vehicle_type, status, verification_status) VALUES (?, ?, 'Bajaj', 'RE', 2022, 'Yellow', ?, 3, 1, ?, 'approved', 'verified')")
            ->execute([generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH'), $id, 'KK-'.substr(uniqid(), -6), $rawType]);
        return $id;
    };

    // The exact string the driver registration <select> submits for a keke.
    $kekeDriver = $mk('keke napep');
    $carDriver  = $mk('car');

    $flag = sync_keke_classification($pdo, $kekeDriver);
    check('sync classifies "keke napep" registration as keke', $flag === 1);

    $stored = $pdo->prepare("SELECT vehicle_type, passenger_capacity, seats FROM vehicles WHERE driver_id = ?");
    $stored->execute([$kekeDriver]); $kv = $stored->fetch();
    check('stored vehicle_type canonicalised to "keke"', $kv['vehicle_type'] === 'keke');
    check('passenger_capacity synced to seats', (int)$kv['passenger_capacity'] === (int)$kv['seats']);

    $uf = $pdo->prepare("SELECT is_keke_driver FROM users WHERE id = ?");
    $uf->execute([$kekeDriver]);
    check('users.is_keke_driver = 1 persisted', (int)$uf->fetchColumn() === 1);

    check('sync classifies car driver as NOT keke', sync_keke_classification($pdo, $carDriver) === 0);
    $uf->execute([$carDriver]);
    check('users.is_keke_driver = 0 for car driver', (int)$uf->fetchColumn() === 0);

    // ---- 3. conflicting data cannot grant keke functionality ----
    // Force the flag on for a driver whose vehicle is a car (the exact
    // "conflicting data" case criterion #2 forbids).
    $pdo->prepare("UPDATE users SET is_keke_driver = 1, private_pickup_enabled = 1, is_online = 1, pickup_available_today = 1, pickup_availability_date = CURDATE() WHERE id = ?")
        ->execute([$carDriver]);
    $elig = check_driver_eligibility($pdo, $carDriver, 'private_pickup');
    check('car driver with forced is_keke_driver still rejected', $elig['eligible'] === false && $elig['code'] === 'not_keke');
    // And re-syncing repairs the conflicting flag from the vehicle of record.
    sync_keke_classification($pdo, $carDriver);
    $uf->execute([$carDriver]);
    check('re-sync repairs the conflicting flag back to 0', (int)$uf->fetchColumn() === 0);

    // ---- 4. Keke Join Ride auto-config: keke rate, not car rate ----
    $origKekeKm = get_pricing_setting($pdo, 'keke_price_per_km', null);
    $set = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $set->execute(['keke_price_per_km', '35']);   // deliberately different from price_per_km
    $set->execute(['keke_base_fare', '50']);

    $carPrice  = calculate_recommended_seat_price($pdo, 10, 4, ['vehicle_type' => 'car']);
    $kekePrice = calculate_recommended_seat_price($pdo, 10, 3, ['vehicle_type' => 'keke']);
    $noneGiven = calculate_recommended_seat_price($pdo, 10, 4);

    check('keke Join Ride priced with keke rate, not car rate', $kekePrice['recommended_price'] !== $carPrice['recommended_price']);
    check('keke breakdown reports keke price_per_km', (float)$kekePrice['breakdown']['price_per_km'] === 35.0);
    check('keke breakdown reports keke base_fare', (float)$kekePrice['breakdown']['base_fare'] === 50.0);
    check('keke breakdown labels vehicle_class', ($kekePrice['breakdown']['vehicle_class'] ?? null) === 'keke');
    check('car pricing unchanged by keke settings', $carPrice['recommended_price'] === $noneGiven['recommended_price']);
    check('omitting vehicle_type reproduces original behaviour', $noneGiven['breakdown']['price_per_km'] === get_price_per_km($pdo));

    // A vehicle class with no configured rate must fall through to the
    // platform default - not to zero, and not to the keke rate.
    $busPrice = calculate_recommended_seat_price($pdo, 10, 14, ['vehicle_type' => 'bus']);
    check('unconfigured class falls back to platform rate', $busPrice['recommended_price'] === $noneGiven['recommended_price']);

    // restore
    if ($origKekeKm !== null) $set->execute(['keke_price_per_km', $origKekeKm]);
    $set->execute(['keke_base_fare', (string) get_pricing_setting($pdo, 'base_fare', '100')]);

    cleanup($pdo, $U);
    echo "\nfixture cleaned up\n";
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
    cleanup($pdo, $U);
    $fail++;
}

echo "\n=== RESULT: $pass passed, $fail failed ===\n";
exit($fail === 0 ? 0 : 1);
