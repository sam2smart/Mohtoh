<?php
// api/pricing-admin.php
// The single, consolidated writer of pricing- and driver-settings-related
// system_settings keys. Replaces three previously disconnected paths that
// didn't agree with each other or (mostly) reach the live fare engine:
// api/admin-api.php's update_pricing_settings/update_settings actions,
// and api/fare-settings.php. Every key here is read by
// includes/functions.php's get_pricing_setting()/calculate_recommended_seat_price(),
// so a change made here takes effect on the next ride priced, immediately.
//
// Super-admin gated (require_super_admin) — this is the one place money
// rules actually change, per the module's security requirement. Every
// value actually changed is logged via log_pricing_change() with the
// admin, old value, new value, and IP address.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

require_once '../includes/admin_permissions.php';
// Access-Control: this GET was previously open to ANY logged-in user (a rider
// could read pricing settings) — now it requires an active admin with
// pricing.view. The POST writes below keep their own require_super_admin($pdo)
// gate, so only a Super Admin ever changes money rules.
require_admin_permission($pdo, 'pricing.view');

// Whitelisted keys only — never let a caller write an arbitrary
// system_settings row through this endpoint.
const PRICING_SETTING_KEYS = [
    'base_fare', 'price_per_km', 'price_per_minute', 'minimum_fare', 'maximum_fare',
    'booking_fee', 'platform_service_fee', 'commission_percentage', 'rider_service_charge',
    'cancellation_fee', 'waiting_charge',
    'night_fare_multiplier', 'night_start_hour', 'night_end_hour',
    'peak_hour_multiplier', 'peak_start_hour', 'peak_end_hour',
    'holiday_multiplier', 'holiday_dates',
    'price_band_percent',
    'min_wallet_balance', 'max_active_rides_per_driver', 'require_vehicle_documents', 'pricing_mode',

    // Private Pickup / Solo Ride. The universal rate applies to any pickup
    // vehicle class the admin has not given its own card; the per-class keys
    // below override it, so a keke's short local trip and a car's longer one
    // are not forced onto one rate. Read by
    // includes/private_pickup.php's private_pickup_rate_card().
    'private_pickup_base_fare', 'private_pickup_price_per_km', 'private_booking_fee',
    'private_pickup_keke_base_fare', 'private_pickup_keke_price_per_km',
    'private_pickup_keke_minimum_fare', 'private_pickup_keke_maximum_fare',
    'private_pickup_keke_commission_percentage',
    'private_pickup_car_base_fare', 'private_pickup_car_price_per_km',
    'private_pickup_car_minimum_fare', 'private_pickup_car_maximum_fare',
    'private_pickup_car_commission_percentage',
];

// Read-only "what would this cost" calculator for the admin panel's
// Preview Recommended Price control. Calls calculate_recommended_seat_price()
// directly — the exact same function every ride is actually priced
// with — so this can never drift from real pricing behavior; it is
// not a second pricing implementation.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'preview') {
    $distanceKm = max(0, (float)($_GET['distance_km'] ?? 0));
    $seats = max(1, (int)($_GET['seats'] ?? 1));
    $pricing = calculate_recommended_seat_price($pdo, $distanceKm, $seats, [
        'departure_time' => $_GET['departure_time'] ?? null,
        'departure_date' => $_GET['departure_date'] ?? null,
    ]);
    json_response('success', 'Preview calculated', $pricing);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $placeholders = implode(',', array_fill(0, count(PRICING_SETTING_KEYS), '?'));
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute(PRICING_SETTING_KEYS);
    $settings = [];
    foreach ($stmt->fetchAll() as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $multipliers = $pdo->query("SELECT star_rating, price_per_km FROM pricing_configuration ORDER BY star_rating ASC")->fetchAll();

    json_response('success', 'Pricing settings fetched', [
        'settings' => $settings,
        'star_multipliers' => $multipliers,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_super_admin($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $adminId = $_SESSION['user_id'];
    $changed = [];

    $pdo->beginTransaction();
    try {
        if (isset($input['settings']) && is_array($input['settings'])) {
            foreach ($input['settings'] as $key => $value) {
                if (!in_array($key, PRICING_SETTING_KEYS, true)) {
                    continue; // silently skip unknown keys rather than error on a partial save
                }
                $value = trim((string)$value);

                $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $stmt->execute([$key]);
                $oldValue = $stmt->fetchColumn();
                $oldValue = $oldValue === false ? null : $oldValue;

                if ((string)$oldValue === $value) {
                    continue; // no-op, nothing to log
                }

                $stmt = $pdo->prepare("
                    INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);

                log_pricing_change($pdo, $adminId, $key, $oldValue, $value);
                $changed[] = $key;
            }
        }

        if (isset($input['star_multipliers']) && is_array($input['star_multipliers'])) {
            foreach ($input['star_multipliers'] as $starRating => $pricePerKm) {
                $starRating = (int)$starRating;
                $pricePerKm = (float)$pricePerKm;
                if ($starRating < 1 || $starRating > 5 || $pricePerKm <= 0) {
                    continue;
                }

                $stmt = $pdo->prepare("SELECT price_per_km FROM pricing_configuration WHERE star_rating = ?");
                $stmt->execute([$starRating]);
                $oldValue = $stmt->fetchColumn();

                $stmt = $pdo->prepare("
                    INSERT INTO pricing_configuration (star_rating, price_per_km, updated_at) VALUES (?, ?, NOW())
                    ON DUPLICATE KEY UPDATE price_per_km = VALUES(price_per_km), updated_at = NOW()
                ");
                $stmt->execute([$starRating, $pricePerKm]);

                if ($oldValue === false || (float)$oldValue !== $pricePerKm) {
                    log_pricing_change($pdo, $adminId, "star_rating_{$starRating}_price_per_km", $oldValue === false ? null : $oldValue, (string)$pricePerKm);
                    $changed[] = "star_rating_{$starRating}_price_per_km";
                }
            }
        }

        $pdo->commit();
        json_response('success', $changed ? 'Pricing settings updated.' : 'No changes to save.', ['changed' => $changed]);
    } catch (Exception $e) {
        $pdo->rollBack();
        json_response('error', 'Failed to update pricing settings: ' . $e->getMessage());
    }
}

json_response('error', 'Invalid request method');
