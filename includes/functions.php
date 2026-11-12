<?php
// includes/functions.php

function sanitize_input($data) {
    // A malformed or hostile client can send an array where a scalar
    // field is expected (classic PHP query-string trick: field[]=x) —
    // trim() on that throws a fatal TypeError in PHP 8. Every caller
    // across the app expects a string back, so an array input becomes
    // '' rather than propagating the crash.
    if (!is_scalar($data)) {
        return '';
    }
    return htmlspecialchars(stripslashes(trim($data)));
}

// Part 3 (Universal Pagination System): $pagination is an optional 4th
// argument — every one of this app's existing json_response() call
// sites (hundreds, across every api/*.php file) passes exactly 3 args
// and keeps working byte-for-byte unchanged. Paginated list endpoints
// pass paginate_meta()'s return value here so `pagination` sits
// alongside `data`, matching the required response shape exactly.
function json_response($status, $message, $data = [], $pagination = null) {
    header('Content-Type: application/json');
    $response = [
        'status' => $status,
        'message' => $message,
        'data' => $data
    ];
    if ($pagination !== null) {
        $response['pagination'] = $pagination;
    }
    echo json_encode($response);
    exit;
}

// ---------------------------------------------------------------
// Part 3/4 (Universal Pagination System) — one shared implementation
// every list endpoint in api/*.php uses instead of hand-rolling its
// own page/limit math or, worse, returning an unbounded SELECT *.
// ---------------------------------------------------------------

// Reads ?page=&?limit= from either GET or a decoded JSON POST body,
// clamps them (page >= 1, 1 <= limit <= 100, default 20), and returns
// [page, limit, offset] ready to drop straight into a LIMIT/OFFSET
// clause. $source defaults to $_GET since most list endpoints are GET;
// pass a decoded POST $input array for the few that aren't.
function paginate_params($source = null) {
    $source = $source ?? $_GET;
    $page = max(1, (int)($source['page'] ?? 1));
    $limit = (int)($source['limit'] ?? 20);
    if ($limit <= 0) {
        $limit = 20;
    }
    $limit = min(100, $limit);
    $offset = ($page - 1) * $limit;
    return [$page, $limit, $offset];
}

// Builds the exact pagination envelope Part 3 requires:
// {current_page, per_page, total_records, total_pages}.
function paginate_meta($page, $limit, $totalRecords) {
    return [
        'current_page' => $page,
        'per_page' => $limit,
        'total_records' => $totalRecords,
        'total_pages' => $limit > 0 ? (int)ceil($totalRecords / $limit) : 0,
    ];
}

// Crockford's Base32 alphabet: 32 symbols (0-9 plus A-Z minus I/L/O/U,
// which are dropped for looking too much like 1/1/0/V) so a 5-bit chunk
// always maps to a valid, unambiguous index.
const PUBLIC_ID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function public_id_prefix_for_user_type($user_type) {
    switch ($user_type) {
        case 'rider':
            return 'RDR';
        case 'driver':
            return 'DRV';
        case 'admin':
            return 'ADM';
        default:
            return 'USR';
    }
}

// 8 characters at 5 bits/char = 40 bits, so 5 secure random bytes in.
function generate_public_id_code() {
    $bytes = random_bytes(5);
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }

    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $chunk = substr($bits, $i * 5, 5);
        $code .= PUBLIC_ID_ALPHABET[bindec($chunk)];
    }

    return $code;
}

// Generates a unique public identifier like VEH-A82F91BC for any table/
// column, retrying on the rare collision. $table/$column must always be
// hardcoded call-site literals (never user input) since they're
// interpolated directly. Caller is responsible for persisting the
// returned value.
function generate_unique_public_id($pdo, $table, $column, $prefix) {
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $candidate = $prefix . '-' . generate_public_id_code();

        $stmt = $pdo->prepare("SELECT 1 FROM `$table` WHERE `$column` = ?");
        $stmt->execute([$candidate]);
        if (!$stmt->fetchColumn()) {
            return $candidate;
        }
    }

    throw new RuntimeException("Could not generate a unique $column for $table after 10 attempts");
}

// Generates a unique public user_id like RDR-A82F91BC, retrying on the
// rare collision. Caller is responsible for persisting the returned value.
function generate_unique_user_id($pdo, $user_type) {
    $prefix = public_id_prefix_for_user_type($user_type);
    return generate_unique_public_id($pdo, 'users', 'user_id', $prefix);
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header("Location: ../index.php");
        exit;
    }
}

/**
 * Ensures the logged-in user is verified.
 */
function require_verified($pdo) {
    require_login();
    require_once __DIR__ . '/verification.php';
    if (!is_user_verified($pdo, $_SESSION['user_id'])) {
        header("Location: ../verify-account.php");
        exit;
    }
}

// Gates pricing/commission/regional/promo-mutating admin endpoints.
// users.is_super_admin is a separate, narrower tier from the ordinary
// user_type='admin' check every other admin action already uses —
// regular admins keep their existing access (drivers, vehicles,
// locations, announcements); only this smaller set of money-affecting
// actions requires the extra flag.
function require_super_admin($pdo) {
    if (!is_logged_in() || ($_SESSION['user_type'] ?? '') !== 'admin') {
        json_response('error', 'Unauthorized');
    }
    $stmt = $pdo->prepare("SELECT is_super_admin FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    if (!$stmt->fetchColumn()) {
        json_response('error', 'Only Super Admins can perform this action.');
    }
}

// ---------------------------------------------------------------
// CSRF protection for authenticated, state-changing JSON endpoints.
//
// The app historically had no CSRF layer (session auth + same-origin
// fetch only). MOHTOH NETWORK introduces authenticated state-changing
// actions the browser initiates via JS — joining the programme,
// requesting a withdrawal, admin partner/reward/withdrawal decisions —
// so those endpoints (api/network.php, api/network-admin.php) carry a
// per-session token that must accompany the request. This is
// intentionally self-contained and used ONLY by the new endpoints; it does
// not retrofit or alter any existing action's request contract.
//
// The token is a 32-byte CSPRNG value kept in the session. It is exposed to
// same-origin pages that need it (rendered into a meta tag / JS global) and
// echoed back in the X-CSRF-Token header or a csrf_token field.
function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_validate(?string $token): bool {
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

// Reads the token from the X-CSRF-Token header first, then a csrf_token body
// field, and aborts the request with a JSON error if it is missing/invalid.
// Call at the top of every authenticated state-changing handler that a page's
// JavaScript invokes.
function require_csrf(?array $input = null): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if ($token === null && is_array($input)) {
        $token = $input['csrf_token'] ?? null;
    }
    if ($token === null) {
        $token = $_POST['csrf_token'] ?? null;
    }
    if (!csrf_validate(is_string($token) ? $token : null)) {
        json_response('error', 'Your session has expired. Please refresh the page and try again.');
    }
}

function calculate_distance_km($lat1, $lng1, $lat2, $lng2) {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
        return 0.0;
    }

    $earthRadiusKm = 6371;
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLng = deg2rad((float)$lng2 - (float)$lng1);
    $lat1Rad = deg2rad((float)$lat1);
    $lat2Rad = deg2rad((float)$lat2);

    $a = sin($dLat / 2) * sin($dLat / 2) + sin($dLng / 2) * sin($dLng / 2) * cos($lat1Rad) * cos($lat2Rad);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return round($earthRadiusKm * $c, 2);
}

// Lowercases, collapses whitespace, and expands a small set of common
// address abbreviations before matching — so "Ojuelegba rd" or "VI"
// style shorthand still lines up with full names in both the MohToh
// location table and the external geocoder query.
function normalize_search_query($query) {
    $query = strtolower(trim(preg_replace('/\s+/', ' ', (string)$query)));

    $abbreviations = [
        '/\bst\.?\b/' => 'street',
        '/\brd\.?\b/' => 'road',
        '/\bave\.?\b/' => 'avenue',
        '/\bjn\.?\b/' => 'junction',
        '/\bjct\.?\b/' => 'junction',
        '/\bgovt\.?\b/' => 'government',
        '/\bintl\.?\b/' => 'international',
        '/\bapt\.?\b/' => 'apartment',
        '/\bblvd\.?\b/' => 'boulevard',
    ];

    return preg_replace(array_keys($abbreviations), array_values($abbreviations), $query);
}

// A 0-100 relevance score for how well $needle matches $haystack —
// exact/prefix/substring matches always outrank fuzzy ones, and
// similar_text() catches misspellings (transposed/missing/extra
// letters) that a plain LIKE would miss entirely.
function calculate_relevance_score($needle, $haystack) {
    $needle = strtolower(trim($needle));
    $haystack = strtolower(trim($haystack));
    if ($needle === '' || $haystack === '') {
        return 0;
    }

    if ($haystack === $needle) {
        return 100;
    }
    if (strpos($haystack, $needle) === 0) {
        return 85;
    }
    if (strpos($haystack, $needle) !== false) {
        return 65;
    }

    similar_text($needle, $haystack, $percent);
    if ($percent >= 55) {
        return (int)round($percent * 0.55); // caps below the substring tiers above
    }

    return 0;
}

// Distance-based ranking bonus (0-25), nearest scoring highest, so a
// mediocre text match five minutes away can still beat a perfect text
// match on the other side of the country once a user location exists.
function calculate_distance_bonus($distanceKm) {
    if ($distanceKm === null) {
        return 0;
    }
    return max(0, 25 - min(25, $distanceKm));
}

// Generic system_settings reader every pricing-related setting goes
// through, so every setting the Admin Pricing Center exposes is read
// the same way instead of each getting its own copy of this try/query
// pattern. $default is returned as-is (caller casts) when the key is
// absent — this is what keeps a brand new setting inert until an admin
// (or a seeding migration) actually gives it a value.
function get_pricing_setting($pdo, $key, $default) {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        if ($value !== false && trim((string)$value) !== '') {
            return $value;
        }
    } catch (Exception $e) {
        // fall through to default
    }

    return $default;
}

function get_price_per_km($pdo) {
    return (float) get_pricing_setting($pdo, 'price_per_km', 1.20);
}

// MohToh's commission rate, taken from a passenger's seat price on
// each completed ride share. $driverId is optional: when given, a
// driver-specific override (users.custom_commission_percentage) wins
// over the global system_settings.commission_percentage — existing
// callers that don't pass it keep the exact original global-only
// behavior.
//
// $fallbackPercentage lets a lane that already resolved its own rate
// (e.g. Private Pickup's per-vehicle-class commission) supply it as the
// base, so the driver override still wins but the global Join Ride rate
// is not silently substituted for that lane's configured rate. Omitted,
// the global setting is used exactly as before.
function get_commission_percentage($pdo, $driverId = null, $fallbackPercentage = null) {
    if ($driverId !== null) {
        $stmt = $pdo->prepare("SELECT custom_commission_percentage FROM users WHERE id = ?");
        $stmt->execute([$driverId]);
        $custom = $stmt->fetchColumn();
        if ($custom !== false && $custom !== null) {
            return max(0, min(100, (float)$custom));
        }
    }

    if ($fallbackPercentage !== null && trim((string)$fallbackPercentage) !== '') {
        return max(0, min(100, (float)$fallbackPercentage));
    }

    return max(0, min(100, (float) get_pricing_setting($pdo, 'commission_percentage', 8.00)));
}

// How far a driver may move the recommended per-seat price, as a
// percentage band either side of the system-computed recommendation.
function get_price_band_percent($pdo) {
    return max(0, (float) get_pricing_setting($pdo, 'price_band_percent', 12.5));
}

// Nearest active region whose radius contains the given point, or null
// if none matches (falls back to global settings). Small table, no
// index needed — matches the existing pattern of straightforward
// queries elsewhere in this file over premature optimization.
function find_region_for_point($pdo, $lat, $lng) {
    if ($lat === null || $lng === null) {
        return null;
    }

    $stmt = $pdo->query("SELECT * FROM regions WHERE is_active = 1");
    $best = null;
    $bestDistance = null;
    foreach ($stmt->fetchAll() as $region) {
        $distance = calculate_distance_km($lat, $lng, $region['center_lat'], $region['center_lng']);
        if ($distance <= (float)$region['radius_km'] && ($bestDistance === null || $distance < $bestDistance)) {
            $best = $region;
            $bestDistance = $distance;
        }
    }

    return $best;
}

// Computes the recommended PASSENGER SEAT price plus the allowed band a
// driver may adjust within. Centralized here so create/update/preview
// never compute this differently.
//
// MohToh prices per passenger, not per vehicle — this does NOT divide
// anything by $totalSeats. Every seat costs the same calculated fare;
// $totalSeats is only used afterward, by the caller, to project
// potential_revenue (fare × seats) for display. (Earlier versions of
// this function computed a whole-trip value and divided it by seats,
// which is the Uber vehicle-trip-price model — wrong for MohToh's
// shared-seat model, where a passenger books one seat at a fixed price
// regardless of how many seats the vehicle has.)
//
// $options (all optional, all additive — omitting every one reproduces
// the exact original distance*price_per_km-only calculation):
//   driver_id        -> per-driver commission override (get_commission_percentage)
//   pickup_lat/lng    -> regional price_per_km/base_fare override (find_region_for_point)
//   departure_date    -> holiday multiplier lookup
//   departure_time    -> night/peak multiplier lookup
//   estimated_minutes -> price-per-minute component (omitted entirely if not supplied)
//   vehicle_type      -> vehicle-class rate override (e.g. keke) when the
//                        admin has configured one; otherwise inert
function calculate_recommended_seat_price($pdo, $distanceKm, $totalSeats, $options = []) {
    $totalSeats = max(1, (int)$totalSeats);

    $pickupLat = $options['pickup_lat'] ?? null;
    $pickupLng = $options['pickup_lng'] ?? null;
    $region = ($pickupLat !== null && $pickupLng !== null) ? find_region_for_point($pdo, $pickupLat, $pickupLng) : null;

    $pricePerKm = ($region && $region['price_per_km'] !== null) ? (float)$region['price_per_km'] : get_price_per_km($pdo);
    $baseFare = ($region && $region['base_fare'] !== null) ? (float)$region['base_fare'] : (float) get_pricing_setting($pdo, 'base_fare', 0);

    // Vehicle-class rate override. A keke is not a car: it carries fewer
    // passengers over shorter distances, so charging it the car rate is
    // exactly the "make the keke driver pretend to be a car driver"
    // problem. When the admin has set <type>_price_per_km / <type>_base_fare
    // those win for this class; when they haven't, nothing changes at all
    // (get_pricing_setting returns the '' default and we keep the value
    // resolved above) - so no rate is ever hard-coded here.
    $vehicleClass = $options['vehicle_type'] ?? null;
    if ($vehicleClass !== null && trim((string)$vehicleClass) !== '') {
        $classKey = preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string)$vehicleClass)));
        if ($classKey !== '') {
            $classPerKm = get_pricing_setting($pdo, $classKey . '_price_per_km', '');
            if (trim((string)$classPerKm) !== '') {
                $pricePerKm = (float)$classPerKm;
            }
            $classBase = get_pricing_setting($pdo, $classKey . '_base_fare', '');
            if (trim((string)$classBase) !== '') {
                $baseFare = (float)$classBase;
            }
        }
    }

    $pricePerMinute = (float) get_pricing_setting($pdo, 'price_per_minute', 0);
    $bookingFee = (float) get_pricing_setting($pdo, 'booking_fee', 0);
    $serviceCharge = (float) get_pricing_setting($pdo, 'rider_service_charge', 0);
    $minimumFare = (float) get_pricing_setting($pdo, 'minimum_fare', 5.00);
    $maximumFare = (float) get_pricing_setting($pdo, 'maximum_fare', 0); // 0 = uncapped

    $distanceCost = round($distanceKm * $pricePerKm, 2);
    $estimatedMinutes = $options['estimated_minutes'] ?? null;
    $timeCost = $estimatedMinutes !== null ? round((float)$estimatedMinutes * $pricePerMinute, 2) : 0.0;

    $subtotal = round($baseFare + $distanceCost + $timeCost + $bookingFee + $serviceCharge, 2);

    // Multipliers — each stays 1.0 (no-op) unless the relevant window/date
    // setting is both configured and actually matched.
    $nightMultiplier = 1.0;
    $peakMultiplier = 1.0;
    $holidayMultiplier = 1.0;

    $departureTime = $options['departure_time'] ?? null;
    if ($departureTime !== null) {
        $hour = (int)substr((string)$departureTime, 0, 2);

        $nightStart = (int) get_pricing_setting($pdo, 'night_start_hour', 22);
        $nightEnd = (int) get_pricing_setting($pdo, 'night_end_hour', 6);
        $isNight = $nightStart > $nightEnd
            ? ($hour >= $nightStart || $hour < $nightEnd)
            : ($hour >= $nightStart && $hour < $nightEnd);
        if ($isNight) {
            $nightMultiplier = (float) get_pricing_setting($pdo, 'night_fare_multiplier', 1.0);
        }

        $peakStart = (int) get_pricing_setting($pdo, 'peak_start_hour', 7);
        $peakEnd = (int) get_pricing_setting($pdo, 'peak_end_hour', 9);
        if ($hour >= $peakStart && $hour < $peakEnd) {
            $peakMultiplier = (float) get_pricing_setting($pdo, 'peak_hour_multiplier', 1.0);
        }
    }

    $departureDate = $options['departure_date'] ?? null;
    if ($departureDate !== null) {
        $holidayDates = array_filter(array_map('trim', explode(',', (string) get_pricing_setting($pdo, 'holiday_dates', ''))));
        if (in_array((string)$departureDate, $holidayDates, true)) {
            $holidayMultiplier = (float) get_pricing_setting($pdo, 'holiday_multiplier', 1.0);
        }
    }

    $multiplier = $nightMultiplier * $peakMultiplier * $holidayMultiplier;

    // This IS the passenger's seat price — no division by seat count.
    // minimum_fare/maximum_fare are PER-PASSENGER bounds (admin-facing
    // labels: "Minimum/Maximum Passenger Fare"), applied here, after the
    // multiplier, directly to the fare a single passenger will pay.
    $passengerFare = round($subtotal * $multiplier, 2);
    if ($minimumFare > 0) {
        $passengerFare = max($passengerFare, $minimumFare);
    }
    if ($maximumFare > 0) {
        $passengerFare = min($passengerFare, $maximumFare);
    }

    $recommended = $passengerFare;
    // Potential revenue if every seat sells — a display/planning figure
    // for the driver, never used as an intermediate step in computing
    // the fare itself.
    $potentialRevenue = round($recommended * $totalSeats, 2);

    $band = get_price_band_percent($pdo);
    $min = round($recommended * (1 - $band / 100), 2);
    $max = round($recommended * (1 + $band / 100), 2);

    return [
        'recommended_price' => $recommended,
        'potential_revenue' => $potentialRevenue,
        'min_price' => $min,
        'max_price' => $max,
        'breakdown' => [
            'base_fare' => $baseFare,
            'distance_km' => $distanceKm,
            'price_per_km' => $pricePerKm,
            'distance_cost' => $distanceCost,
            'time_cost' => $timeCost,
            'booking_fee' => $bookingFee,
            'service_charge' => $serviceCharge,
            'night_multiplier' => $nightMultiplier,
            'peak_multiplier' => $peakMultiplier,
            'holiday_multiplier' => $holidayMultiplier,
            'region' => $region ? $region['name'] : null,
            'vehicle_class' => $vehicleClass !== null && trim((string)$vehicleClass) !== '' ? strtolower(trim((string)$vehicleClass)) : null,
            'passenger_fare' => $recommended,
        ],
    ];
}

// Section 4 (Driver Override): resolves the actual per-passenger seat
// price a driver ends up publishing at, given the system recommendation
// and whatever they supplied, branching on the admin-configured
// pricing_mode. Shared by rideshare.php's create and update actions so
// they can never drift apart on this logic. minimum_fare/maximum_fare
// are already per-passenger bounds (see calculate_recommended_seat_price()),
// so Option B compares the driver's price against them directly — no
// seat-count math needed. $totalSeats is accepted for signature
// stability with existing callers but no longer used here.
// Throws RuntimeException with a user-facing message on an
// out-of-bounds driver price; caller is expected to catch and
// json_response('error', ...) it.
function resolve_price_per_passenger($pdo, $pricing, $requestedPriceRaw, $totalSeats = 1) {
    $pricingMode = (string) get_pricing_setting($pdo, 'pricing_mode', 'hybrid');
    $hasRequestedPrice = $requestedPriceRaw !== null && trim((string)$requestedPriceRaw) !== '';

    if ($pricingMode === 'system') {
        // Option A: system controls pricing — driver input ignored.
        return $pricing['recommended_price'];
    }

    if (!$hasRequestedPrice) {
        return $pricing['recommended_price'];
    }

    $requestedPrice = round((float)$requestedPriceRaw, 2);

    if ($pricingMode === 'driver') {
        // Option B: free choice within the admin's absolute per-passenger
        // fare bounds.
        $minimumFare = (float) get_pricing_setting($pdo, 'minimum_fare', 0);
        $maximumFare = (float) get_pricing_setting($pdo, 'maximum_fare', 0);
        if ($minimumFare > 0 && $requestedPrice < $minimumFare) {
            throw new RuntimeException("Passenger fare must be at least ₦{$minimumFare}.");
        }
        if ($maximumFare > 0 && $requestedPrice > $maximumFare) {
            throw new RuntimeException("Passenger fare cannot exceed ₦{$maximumFare}.");
        }
        return $requestedPrice;
    }

    // Option C (hybrid, default): band around the recommendation —
    // the original, pre-pricing-mode behavior.
    if ($requestedPrice < $pricing['min_price'] || $requestedPrice > $pricing['max_price']) {
        throw new RuntimeException("Price per seat must be between ₦{$pricing['min_price']} and ₦{$pricing['max_price']} (recommended: ₦{$pricing['recommended_price']}).");
    }
    return $requestedPrice;
}

// Intelligent Trip Engine foundation. Moves a ride_share to a new
// granular trip_state and logs the transition. This is deliberately
// separate from the existing `status` column — every existing query
// that filters on status keeps working untouched; trip_state is an
// additive layer read by anything that needs the finer-grained view.
//
// $triggerType: 'manual' (a driver/rider action caused it),
// 'geofence' (a location ping crossed a proximity threshold), or
// 'system' (an automatic consequence of another action, e.g. the
// first booking on a ride).
function transition_trip_state($pdo, $rideId, $toState, $triggerType = 'manual', $meta = null) {
    $stmt = $pdo->prepare('SELECT trip_state, driver_id, destination FROM ride_shares WHERE id = ?');
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch();
    if (!$ride) {
        return false;
    }
    $fromState = $ride['trip_state'];

    $stmt = $pdo->prepare('UPDATE ride_shares SET trip_state = ?, trip_state_updated_at = NOW() WHERE id = ?');
    $stmt->execute([$toState, $rideId]);

    $stmt = $pdo->prepare('INSERT INTO trip_state_events (ride_share_id, from_state, to_state, trigger_type, meta) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$rideId, $fromState, $toState, $triggerType, $meta !== null ? json_encode($meta) : null]);

    dispatch_trip_state_notification($pdo, $rideId, $ride, $toState);

    return true;
}

// Notification Engine hook for the Intelligent Trip Engine: every
// trip_state a ride can reach that someone should be told about is
// mapped here ONCE. transition_trip_state() calls this on every
// transition, so manual driver actions, geofence pings, and
// mark_ride_started() all get the right notification automatically —
// none of those call sites notify by hand anymore (that would create
// duplicates now that this exists).
function dispatch_trip_state_notification($pdo, $rideId, $ride, $toState) {
    // Chat system messages piggyback on this exact dispatcher — one
    // place every trip_state transition already fans out from, per
    // includes/chat.php's design note ("do not create a parallel event
    // system"). require_once is safe/cheap here (chat.php only defines
    // functions) and keeps this file from having to know chat.php's
    // load order relative to every caller of transition_trip_state().
    require_once __DIR__ . '/chat.php';
    require_once __DIR__ . '/referral.php';
    // Lookup-only: a ride with zero bookings (e.g. cancelled while still
    // unbooked) has no chat, and shouldn't get a ghost conversation just
    // because it transitioned — get_or_create_ride_conversation() is
    // reserved for the one place that SHOULD create one, a real booking.
    $chatConversationId = find_ride_conversation($pdo, $rideId);
    switch ($toState) {
        case 'PASSENGERS_ASSEMBLED':
            post_system_message($pdo, $chatConversationId, 'Driver has arrived at the pickup location.');
            break;
        case 'TRIP_STARTED':
            post_system_message($pdo, $chatConversationId, 'Trip has started.');
            break;
        case 'TRIP_COMPLETED':
            post_system_message($pdo, $chatConversationId, 'Trip completed successfully.');
            archive_ride_conversation($pdo, $rideId);
            break;
        case 'CANCELLED':
            post_system_message($pdo, $chatConversationId, 'This trip has been cancelled.');
            archive_ride_conversation($pdo, $rideId);
            break;
    }

    $riderIds = null;
    $loadRiders = function () use ($pdo, $rideId, &$riderIds) {
        if ($riderIds === null) {
            $stmt = $pdo->prepare("SELECT rider_id FROM ride_share_bookings WHERE ride_share_id = ? AND status NOT IN ('cancelled')");
            $stmt->execute([$rideId]);
            $riderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        return $riderIds;
    };
    $notifyRiders = function ($title, $message, $priority = 'normal') use ($pdo, $rideId, $loadRiders) {
        foreach ($loadRiders() as $riderId) {
            create_notification($pdo, $riderId, [
                'title' => $title, 'message' => $message, 'notification_type' => 'trip', 'priority' => $priority,
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);
        }
    };

    switch ($toState) {
        case 'PUBLISHED':
            notify_nearby_riders_of_published_ride($pdo, $rideId);
            break;
        case 'PASSENGERS_ASSEMBLED':
            create_notification($pdo, $ride['driver_id'], [
                'title' => 'You have arrived', 'message' => 'All passengers are ready to board.', 'notification_type' => 'gps',
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);
            $notifyRiders('Driver has arrived', 'Your driver is waiting at the pickup point.', 'high');
            break;
        case 'TRIP_STARTED':
            $notifyRiders('Trip started', 'Your ride to ' . $ride['destination'] . ' has started!', 'high');
            break;
        case 'PASSENGER_DROPPING':
            create_notification($pdo, $ride['driver_id'], [
                'title' => 'Arriving at destination', 'message' => 'You are approaching the drop-off point.', 'notification_type' => 'gps',
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);
            break;
        case 'AUTO_COMPLETED_PENDING':
            create_notification($pdo, $ride['driver_id'], [
                'title' => 'Trip ready to complete', 'message' => 'You have arrived at the destination. Confirm to complete this trip.',
                'notification_type' => 'trip', 'priority' => 'high',
                'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
            ]);
            break;
        case 'TRIP_COMPLETED':
            $notifyRiders('Trip completed', 'Your trip has been completed. Thanks for riding with MohToh!');
            // MohToh Community Rewards: a completed trip is the
            // qualifying event for both a referred driver (their own
            // first completed trip) and every referred rider aboard
            // (their first completed paid ride) — track_referral_event()
            // no-ops for anyone who wasn't referred.
            track_referral_event($pdo, $ride['driver_id'], 'FIRST_TRIP_COMPLETED');
            foreach ($loadRiders() as $riderId) {
                track_referral_event($pdo, $riderId, 'FIRST_TRIP_COMPLETED');
            }
            break;
        case 'CANCELLED':
            // Callers (e.g. rideshare.php's cancel action) already set
            // every booking's status to 'cancelled' before this fires —
            // that's the whole point of this transition — so the
            // NOT IN ('cancelled') filter $loadRiders() uses for every
            // other case would find nobody here. Query independently.
            $stmt = $pdo->prepare("SELECT rider_id FROM ride_share_bookings WHERE ride_share_id = ?");
            $stmt->execute([$rideId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $riderId) {
                create_notification($pdo, $riderId, [
                    'title' => 'Trip cancelled', 'message' => 'Your booked ride to ' . $ride['destination'] . ' has been cancelled by the driver.',
                    'notification_type' => 'trip', 'priority' => 'high',
                    'action' => 'open_trip', 'action_id' => $rideId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
                ]);
            }
            break;
    }
}

// Ride Published Event: notifies riders whose last known location
// (users.latitude/longitude, kept fresh by the same location pings the
// Intelligent Trip Engine already relies on) is within the configured
// radius of this ride's pickup point. Best-effort — riders with no
// recent location simply aren't matched, same as everywhere else in
// this app that treats a missing GPS fix as "unknown" rather than an error.
function notify_nearby_riders_of_published_ride($pdo, $rideId) {
    $stmt = $pdo->prepare("SELECT pickup_lat, pickup_lng, destination, driver_id FROM ride_shares WHERE id = ?");
    $stmt->execute([$rideId]);
    $ride = $stmt->fetch();
    if (!$ride || $ride['pickup_lat'] === null || $ride['pickup_lng'] === null) {
        return;
    }

    $radiusKm = (float) get_system_setting($pdo, 'nearby_rider_notification_radius_km', 10);
    $maxNotified = (int) get_system_setting($pdo, 'nearby_rider_notification_max', 25);

    $stmt = $pdo->prepare("SELECT id, latitude, longitude FROM users WHERE user_type = 'rider' AND is_suspended = 0 AND latitude IS NOT NULL AND longitude IS NOT NULL AND id != ?");
    $stmt->execute([$ride['driver_id']]);

    $notified = 0;
    foreach ($stmt->fetchAll() as $rider) {
        if ($notified >= $maxNotified) {
            break;
        }
        $distance = calculate_distance_km($ride['pickup_lat'], $ride['pickup_lng'], $rider['latitude'], $rider['longitude']);
        if ($distance !== null && $distance <= $radiusKm) {
            create_notification($pdo, $rider['id'], [
                'title' => 'New ride near you',
                'message' => "A driver just published a ride to {$ride['destination']}, about " . round($distance, 1) . "km from you.",
                'notification_type' => 'ride',
                'action' => 'search_ride',
                'action_id' => $rideId,
                'related_table' => 'ride_shares',
                'link' => 'dashboard.php',
            ]);
            $notified++;
        }
    }
}

// Haversine distance in meters — same formula as calculate_distance_km
// but returning meters, which geofence radius checks need directly.
function calculate_distance_meters($lat1, $lng1, $lat2, $lng2) {
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) {
        return null;
    }
    return calculate_distance_km($lat1, $lng1, $lat2, $lng2) * 1000;
}

// Speed in km/h between two location pings. Returns null if either
// point or the elapsed time is missing/non-positive (first-ever ping
// on a ride, or two pings that landed in the same second).
function calculate_speed_kmh($prevLat, $prevLng, $lat, $lng, $secondsElapsed) {
    if ($prevLat === null || $prevLng === null || $secondsElapsed === null || $secondsElapsed <= 0) {
        return null;
    }
    $distanceKm = calculate_distance_km($prevLat, $prevLng, $lat, $lng);
    $hours = $secondsElapsed / 3600;
    return round($distanceKm / $hours, 2);
}

// A transparent, simple confidence score (0-100) for an
// automatically-detected trip event, built from which individual
// signals actually held rather than a black-box formula. Kept as
// plain addition so it's obvious in the code (and to anyone
// reviewing trip_state_events.meta) exactly what earned the score.
function calculate_completion_confidence($withinGeofence, $isStopped, $isSecondConfirmation) {
    $score = 0;
    if ($withinGeofence) $score += 40;
    if ($isStopped) $score += 30;
    if ($isSecondConfirmation) $score += 30;
    return $score;
}

// The one place a ride actually "starts" — updates the legacy status
// column, booking statuses, the granular trip_state, notifications,
// and (for the automatic path) the auto_started flag. Both the manual
// start_ride action and the GPS auto-start detection call this, so
// "starting a ride" only has one implementation regardless of what
// triggered it. Returns true if it actually applied.
function mark_ride_started($pdo, $rideId, $driverId, $triggerType = 'MANUAL_DRIVER_ACTION', $meta = null) {
    $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'in_progress' WHERE id = ? AND driver_id = ? AND status IN ('active', 'full')");
    $stmt->execute([$rideId, $driverId]);

    if ($stmt->rowCount() === 0) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE ride_share_bookings SET status = 'in_progress' WHERE ride_share_id = ? AND status != 'cancelled'");
    $stmt->execute([$rideId]);

    // transition_trip_state()'s own dispatch_trip_state_notification()
    // notifies every booked rider on TRIP_STARTED — do not also notify
    // here, or every trip start would fire the notification twice.
    transition_trip_state($pdo, $rideId, 'TRIP_STARTED', $triggerType, $meta);

    if ($triggerType === 'SYSTEM_GPS_START') {
        $pdo->prepare("UPDATE ride_shares SET auto_started = 1 WHERE id = ?")->execute([$rideId]);
    }

    return true;
}

// ---------------------------------------------------------------
// Intelligent Trip Engine — Phase 3 (Collaborative Driver & Passenger
// Intelligence). Everything below is additive: it reads/writes the new
// ride_share_bookings.passenger_state layer and booking_state_events
// (database/migrate_trip_engine_phase3.php), and never touches
// ride_shares.trip_state directly except through the existing
// transition_trip_state()/mark_ride_started() functions above.
// ---------------------------------------------------------------

// The per-passenger sibling of transition_trip_state(): same shape
// (read current state, write new state + timestamp, log an audit row),
// just scoped to one booking instead of the whole ride. $confidence is
// stored on both the booking (last known score) and the event row (the
// score that justified THIS specific transition).
function transition_passenger_state($pdo, $bookingId, $toState, $triggerType = 'manual', $confidence = null, $meta = null) {
    $stmt = $pdo->prepare('SELECT rb.passenger_state, rb.ride_share_id, rb.rider_id, rs.driver_id FROM ride_share_bookings rb JOIN ride_shares rs ON rs.id = rb.ride_share_id WHERE rb.id = ?');
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking) {
        return false;
    }
    $fromState = $booking['passenger_state'];
    $rideShareId = $booking['ride_share_id'];

    $timestampColumn = [
        'arrived' => 'arrived_at',
        'boarded' => 'boarded_at',
        'dropped_off' => 'dropped_off_at',
    ][$toState] ?? null;

    $sql = 'UPDATE ride_share_bookings SET passenger_state = ?, passenger_state_updated_at = NOW(), last_confidence_score = ?'
        . ($timestampColumn ? ", $timestampColumn = NOW()" : '')
        . ' WHERE id = ?';
    $pdo->prepare($sql)->execute([$toState, $confidence, $bookingId]);

    $publicId = generate_unique_public_id($pdo, 'booking_state_events', 'public_id', 'BSE');
    $stmt = $pdo->prepare('INSERT INTO booking_state_events (public_id, booking_id, ride_share_id, from_state, to_state, trigger_type, confidence_score, meta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$publicId, $bookingId, $rideShareId, $fromState, $toState, $triggerType, $confidence, $meta !== null ? json_encode($meta) : null]);

    dispatch_passenger_state_notification($pdo, $rideShareId, $booking['rider_id'], $booking['driver_id'], $toState);

    return true;
}

// Notification Engine hook for the passenger side of the Intelligent
// Trip Engine — mirrors dispatch_trip_state_notification() above, one
// mapping covering every existing and future transition_passenger_state()
// call site (geofence auto-detection, manual confirm in
// api/trip-collaboration.php, Phase 3 boarding/no-show logic).
function dispatch_passenger_state_notification($pdo, $rideShareId, $riderId, $driverId, $toState) {
    $notify = function ($userId, $title, $message, $priority = 'normal') use ($pdo, $rideShareId) {
        create_notification($pdo, $userId, [
            'title' => $title, 'message' => $message, 'notification_type' => 'gps', 'priority' => $priority,
            'action' => 'open_trip', 'action_id' => $rideShareId, 'related_table' => 'ride_shares', 'link' => 'trips.php',
        ]);
    };

    switch ($toState) {
        case 'arrived':
            $notify($driverId, 'Passenger arrived', 'A passenger has arrived at the pickup point.');
            break;
        case 'boarded':
            $notify($driverId, 'Passenger boarded', 'A passenger has boarded your vehicle.');
            $notify($riderId, "You're on board", 'Enjoy your trip!');
            break;
        case 'dropped_off':
            $notify($riderId, 'You have arrived', 'You were dropped off at your destination.');
            break;
        case 'no_show':
            $notify($driverId, 'Passenger no-show', 'A passenger did not show up within the wait window and was marked as a no-show.', 'high');
            break;
    }
}

// A transparent, multi-signal confidence score (0-100) for a
// passenger-side automatic transition (arrival/boarding/drop-off).
// Every contributing factor is broken out in $breakdown so it can be
// logged verbatim into booking_state_events.meta — nothing here is a
// black box. A manual confirmation is always fully trusted (100) since
// a human already vouched for it; everything else is additive evidence
// capped per category so no single weak signal can fake a strong one.
function calculate_collaborative_confidence($signals) {
    if (!empty($signals['manual_confirmation'])) {
        return ['score' => 100, 'breakdown' => ['manual_confirmation' => 100]];
    }

    $breakdown = [];

    $freshnessScore = function ($ageSeconds) {
        if ($ageSeconds === null) return 0;
        if ($ageSeconds <= 15) return 20;
        if ($ageSeconds <= 60) return 12;
        if ($ageSeconds <= 180) return 5;
        return 0;
    };
    $breakdown['driver_gps_freshness'] = $freshnessScore($signals['driver_gps_age_seconds'] ?? null);
    $breakdown['passenger_gps_freshness'] = $freshnessScore($signals['passenger_gps_age_seconds'] ?? null);

    $distance = $signals['geofence_distance_meters'] ?? null;
    if ($distance !== null && $distance <= 30) $breakdown['geofence_accuracy'] = 20;
    elseif ($distance !== null && $distance <= 75) $breakdown['geofence_accuracy'] = 12;
    elseif ($distance !== null && $distance <= 150) $breakdown['geofence_accuracy'] = 6;
    else $breakdown['geofence_accuracy'] = 0;

    $breakdown['movement_consistency'] = !empty($signals['movement_confirmed']) ? 15 : 0;

    $accuracy = $signals['gps_accuracy_meters'] ?? null;
    if ($accuracy !== null && $accuracy <= 20) $breakdown['signal_quality'] = 15;
    elseif ($accuracy !== null && $accuracy <= 50) $breakdown['signal_quality'] = 8;
    else $breakdown['signal_quality'] = 0;

    $breakdown['booking_status_valid'] = !empty($signals['booking_valid']) ? 10 : 0;

    $score = min(100, array_sum($breakdown));
    return ['score' => $score, 'breakdown' => $breakdown];
}

function mt_ride_has_boarded_passenger($pdo, $rideShareId) {
    $stmt = $pdo->prepare("SELECT 1 FROM ride_share_bookings WHERE ride_share_id = ? AND passenger_state = 'boarded' LIMIT 1");
    $stmt->execute([$rideShareId]);
    return (bool) $stmt->fetchColumn();
}

// Starts (or restarts) the no-show countdown for every passenger who
// hasn't reached the pickup yet, the moment the driver's own GPS
// confirms they're at the pickup point (PASSENGERS_ASSEMBLED). Called
// additively right after that existing transition fires — it never
// forces a cancellation itself, only records the deadline a driver's
// Wait/Skip/Cancel decision (api/rideshare.php) later checks against.
function mt_start_no_show_countdown($pdo, $rideShareId) {
    $timeoutMinutes = (int) get_pricing_setting($pdo, 'no_show_timeout_minutes', 10);
    $stmt = $pdo->prepare("
        UPDATE ride_share_bookings
        SET no_show_deadline = DATE_ADD(NOW(), INTERVAL ? MINUTE)
        WHERE ride_share_id = ? AND passenger_state IN ('booked', 'navigating') AND no_show_deadline IS NULL
    ");
    $stmt->execute([$timeoutMinutes, $rideShareId]);
}

// Cross-checks every passenger still short of "boarded" against the
// ride's current driver position/speed (kept fresh on every driver
// ping) and each booking's own last reported passenger position (kept
// fresh on every passenger ping, api/passenger-location.php) — auto
// boarding requires driver AND passenger both inside the pickup
// geofence, with the vehicle no longer stationary. Safe to call from
// either side's location handler; it always re-reads current state
// rather than trusting whichever caller invoked it.
function mt_check_auto_boarding($pdo, $rideShareId) {
    $stmt = $pdo->prepare("
        SELECT trip_state, pickup_lat, pickup_lng, current_latitude, current_longitude, last_speed,
               TIMESTAMPDIFF(SECOND, last_location_update, NOW()) AS driver_gps_age_seconds
        FROM ride_shares WHERE id = ?
    ");
    $stmt->execute([$rideShareId]);
    $ride = $stmt->fetch();
    if (!$ride || !in_array($ride['trip_state'], ['PASSENGERS_JOINING', 'PASSENGERS_ASSEMBLED', 'TRIP_STARTED'], true)) {
        return;
    }
    if ($ride['current_latitude'] === null || $ride['pickup_lat'] === null) {
        return;
    }

    $driverDistance = calculate_distance_meters($ride['current_latitude'], $ride['current_longitude'], $ride['pickup_lat'], $ride['pickup_lng']);
    $driverInGeofence = $driverDistance !== null && $driverDistance <= MT_GEOFENCE_RADIUS_METERS;
    $vehicleMoving = $ride['last_speed'] !== null && (float)$ride['last_speed'] > MT_STOPPED_SPEED_KMH;

    if (!$driverInGeofence || !$vehicleMoving) {
        return;
    }

    $stmt = $pdo->prepare("
        SELECT id, passenger_state, passenger_latitude, passenger_longitude, status,
               TIMESTAMPDIFF(SECOND, passenger_last_location_update, NOW()) AS passenger_gps_age_seconds
        FROM ride_share_bookings
        WHERE ride_share_id = ? AND passenger_state IN ('booked', 'navigating', 'arrived') AND status != 'cancelled'
    ");
    $stmt->execute([$rideShareId]);
    $candidates = $stmt->fetchAll();

    foreach ($candidates as $booking) {
        if ($booking['passenger_latitude'] === null || $booking['passenger_gps_age_seconds'] === null || $booking['passenger_gps_age_seconds'] > 120) {
            continue; // stale or absent passenger location — not enough evidence to auto-board
        }
        $passengerDistance = calculate_distance_meters($booking['passenger_latitude'], $booking['passenger_longitude'], $ride['pickup_lat'], $ride['pickup_lng']);
        if ($passengerDistance === null || $passengerDistance > MT_GEOFENCE_RADIUS_METERS) {
            continue;
        }

        $confidence = calculate_collaborative_confidence([
            'driver_gps_age_seconds' => $ride['driver_gps_age_seconds'],
            'passenger_gps_age_seconds' => $booking['passenger_gps_age_seconds'],
            'geofence_distance_meters' => max($driverDistance, $passengerDistance),
            'movement_confirmed' => $vehicleMoving,
            'booking_valid' => $booking['status'] !== 'cancelled',
        ]);

        // Below this floor, evidence is treated as inconclusive rather
        // than wrong — the passenger simply stays in their current
        // state until either stronger signals arrive or they confirm
        // manually, instead of risking a false "boarded".
        if ($confidence['score'] < 50) {
            continue;
        }

        transition_passenger_state($pdo, $booking['id'], 'boarded', 'SYSTEM_GPS_BOARD', $confidence['score'], [
            'driver_distance_meters' => round($driverDistance, 1),
            'passenger_distance_meters' => round($passengerDistance, 1),
            'confidence_breakdown' => $confidence['breakdown'],
        ]);
    }
}

// The passenger-side counterpart to update-location.php's driver
// AUTO_COMPLETED_PENDING staging: if every non-cancelled passenger on
// the ride has independently reached dropped_off/completed/no_show,
// the ride is ready for the driver's final confirmation even if the
// driver's own GPS hasn't (yet) registered two stationary pings at the
// destination. This only ever stages AUTO_COMPLETED_PENDING — exactly
// like the existing GPS path, it never completes the ride or releases
// payment; complete_ride is still the one gate for that.
function mt_check_all_passengers_complete($pdo, $rideShareId) {
    $stmt = $pdo->prepare('SELECT trip_state FROM ride_shares WHERE id = ?');
    $stmt->execute([$rideShareId]);
    $tripState = $stmt->fetchColumn();
    if (!in_array($tripState, ['TRIP_STARTED', 'PASSENGER_DROPPING'], true)) {
        return;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(passenger_state IN ('dropped_off', 'completed', 'no_show')) AS settled FROM ride_share_bookings WHERE ride_share_id = ? AND status != 'cancelled'");
    $stmt->execute([$rideShareId]);
    $counts = $stmt->fetch();

    if ((int)$counts['total'] === 0 || (int)$counts['settled'] < (int)$counts['total']) {
        return;
    }

    $confidence = calculate_collaborative_confidence(['booking_valid' => true, 'movement_confirmed' => true]);
    transition_trip_state($pdo, $rideShareId, 'AUTO_COMPLETED_PENDING', 'ALL_PASSENGERS_COMPLETE', [
        'note' => 'every booked passenger independently reached dropped_off/completed/no_show',
        'confidence' => $confidence['score'],
    ]);
    $pdo->prepare('UPDATE ride_shares SET completion_confidence = ? WHERE id = ?')->execute([$confidence['score'], $rideShareId]);
}

if (!defined('MT_GEOFENCE_RADIUS_METERS')) {
    define('MT_GEOFENCE_RADIUS_METERS', 150); // meters — mirrors api/update-location.php's MOHTOH_GEOFENCE_RADIUS_METERS
}
if (!defined('MT_STOPPED_SPEED_KMH')) {
    define('MT_STOPPED_SPEED_KMH', 3); // km/h — mirrors api/update-location.php's MOHTOH_STOPPED_SPEED_KMH
}

// ---------------------------------------------------------------
// Notification Engine — the ONE place any code in this app creates a
// notification. Every module (rideshare, wallet, verification, auth,
// admin broadcasts, the Intelligent Trip Engine) calls
// create_notification() — or the pre-existing notify_user() wrapper
// kept below for the ~13 call sites already written against it — so
// preference gating, category metadata (icon/color/default priority)
// and admin category kill-switches all live in exactly one function
// instead of being copy-pasted at each call site.
// ---------------------------------------------------------------

// Category catalog: icon/color/default-priority + which per-user
// notify_* preference column gates it. 'pref' => null means the
// category is never suppressible by the user (security/emergency).
// A function (not a top-level const) to match this file's existing
// all-function style and keep the map lazily built once per request.
function notification_type_catalog() {
    static $catalog = null;
    if ($catalog === null) {
        $catalog = [
            'ride'         => ['icon' => 'bi-signpost-split',            'color' => 'primary', 'priority' => 'normal',   'pref' => 'notify_on_booking'],
            'booking'      => ['icon' => 'bi-ticket-perforated',         'color' => 'primary', 'priority' => 'normal',   'pref' => 'notify_on_booking'],
            'trip'         => ['icon' => 'bi-compass',                   'color' => 'primary', 'priority' => 'normal',   'pref' => 'notify_ride_start'],
            'wallet'       => ['icon' => 'bi-wallet2',                   'color' => 'success', 'priority' => 'normal',   'pref' => 'notify_wallet'],
            'payment'      => ['icon' => 'bi-credit-card',                'color' => 'success', 'priority' => 'high',     'pref' => 'notify_payment_alerts'],
            'verification' => ['icon' => 'bi-patch-check',               'color' => 'info',    'priority' => 'high',     'pref' => 'notify_system'],
            'vehicle'      => ['icon' => 'bi-car-front',                 'color' => 'info',    'priority' => 'normal',   'pref' => 'notify_system'],
            'admin'        => ['icon' => 'bi-megaphone',                 'color' => 'primary', 'priority' => 'normal',   'pref' => 'notify_system'],
            'promotion'    => ['icon' => 'bi-gift',                      'color' => 'accent',  'priority' => 'low',      'pref' => 'notify_promotions'],
            'security'     => ['icon' => 'bi-shield-exclamation',        'color' => 'danger',  'priority' => 'critical', 'pref' => null],
            'system'       => ['icon' => 'bi-info-circle',               'color' => 'soft',    'priority' => 'normal',   'pref' => 'notify_system'],
            'support'      => ['icon' => 'bi-headset',                   'color' => 'info',    'priority' => 'normal',   'pref' => 'notify_system'],
            'premium'      => ['icon' => 'bi-gem',                       'color' => 'accent',  'priority' => 'normal',   'pref' => 'notify_system'],
            'emergency'    => ['icon' => 'bi-exclamation-triangle-fill', 'color' => 'danger',  'priority' => 'critical', 'pref' => null],
            'gps'          => ['icon' => 'bi-geo-alt',                   'color' => 'primary', 'priority' => 'normal',   'pref' => 'notify_ride_start'],
            'chat'         => ['icon' => 'bi-chat-dots',                 'color' => 'info',    'priority' => 'normal',   'pref' => 'notify_chat'],
            'referral'     => ['icon' => 'bi-people-fill',               'color' => 'accent',  'priority' => 'normal',   'pref' => 'notify_referrals'],
        ];
    }
    return $catalog;
}

// Priority Management System — centralized behavior/metadata per
// priority tier, independent of notification_type_catalog()'s per-
// category defaults. `rank` is the sort key (lower sorts first — used
// by api/notifications.php's ORDER BY and the admin center table).
// `can_disable`/`always_display` describe user-facing behavior;
// `badge_class` is the CSS hook assets/js/navigation.js and
// admin/dashboard.php both use for consistent styling in one place.
function notification_priority_catalog() {
    static $catalog = null;
    if ($catalog === null) {
        $catalog = [
            'critical' => ['label' => 'Critical', 'rank' => 0, 'can_disable' => false, 'always_display' => true,  'badge_class' => 'is-critical'],
            'high'     => ['label' => 'High',     'rank' => 1, 'can_disable' => true,  'always_display' => true,  'badge_class' => 'is-high'],
            'normal'   => ['label' => 'Normal',   'rank' => 2, 'can_disable' => true,  'always_display' => false, 'badge_class' => 'is-normal'],
            'low'      => ['label' => 'Low',      'rank' => 3, 'can_disable' => true,  'always_display' => false, 'badge_class' => 'is-low'],
        ];
    }
    return $catalog;
}

// Pure data helper for future SMS/email/push routing — returns delivery
// hints for a given priority without sending anything. The real
// channel integrations (see includes/sms-providers.php /
// includes/email-providers.php for the provider-abstraction pattern
// this should eventually plug into) read this when that work happens;
// nothing calls it yet.
function get_notification_delivery_priority($priority) {
    $catalog = notification_priority_catalog();
    $rank = ($catalog[$priority] ?? $catalog['normal'])['rank'];
    switch ($priority) {
        case 'critical':
            return ['immediate_sms' => true,  'immediate_email' => true,  'immediate_push' => true,  'batch' => false, 'rank' => $rank];
        case 'high':
            return ['immediate_sms' => false, 'immediate_email' => false, 'immediate_push' => true,  'batch' => false, 'rank' => $rank];
        case 'low':
            return ['immediate_sms' => false, 'immediate_email' => false, 'immediate_push' => false, 'batch' => true,  'rank' => $rank];
        case 'normal':
        default:
            return ['immediate_sms' => false, 'immediate_email' => false, 'immediate_push' => false, 'batch' => false, 'rank' => $rank];
    }
}

// Accurately-named alias for non-pricing system_settings reads.
// system_settings is a generic key/value table; get_pricing_setting()
// already reads it exactly this way (try/query/fallback-to-default) —
// this is that same single implementation under the honest name for
// notification toggles/retention/etc, not a second copy of the logic.
function get_system_setting($pdo, $key, $default) {
    return get_pricing_setting($pdo, $key, $default);
}

// The Notification Service. $options: message (required), title,
// notification_type (one of notification_type_catalog()'s keys,
// default 'system'), priority (one of notification_priority_catalog()'s
// keys — 'critical'/'high'/'normal'/'low'; defaults to the type's
// catalog priority if omitted, and falls back to that same default if
// an unrecognized value is supplied, rather than failing the insert),
// icon, color, action, action_id, action_url (aliased to the legacy
// `link` column so every existing reader keeps working), related_table,
// related_public_id, meta (array or pre-encoded JSON string),
// delivery_method (string or array, default 'database,in_app'),
// created_by (admin user id, for admin-originated notifications),
// expires_at, force (bool — bypass the user's per-category opt-out; use
// only for things a user must see regardless of preference, on top of
// the always-on security/emergency categories). A resolved priority of
// 'critical' is ALSO always-on — regardless of type — bypassing both
// the per-user opt-out and the platform-wide category kill switch, so
// e.g. an escalated payment/security-adjacent notification still reaches
// a user who has that category's toggle off. Returns the new row's
// public_id, or null if nothing was created (missing message, category
// disabled platform-wide, or the user has opted out).
function create_notification($pdo, $userId, $options) {
    if (!isset($options['message']) || trim((string)$options['message']) === '') {
        return null;
    }

    $type = $options['notification_type'] ?? 'system';
    $catalog = notification_type_catalog();
    $defaults = $catalog[$type] ?? $catalog['system'];

    // Priority: explicit value wins if it's a real priority-catalog key;
    // an invalid/unrecognized value is dropped (falls back to the
    // type's default) rather than reaching the INSERT — the column is a
    // real ENUM, so a bad value would otherwise throw a PDOException and
    // fail the whole notification instead of just ignoring the bad hint.
    $priority = $options['priority'] ?? $defaults['priority'];
    if (!isset(notification_priority_catalog()[$priority])) {
        $priority = $defaults['priority'];
    }

    // Priority Management System: a notification is "always-on" — never
    // suppressed by the platform kill switch or the user's per-category
    // opt-out — if its TYPE is one of the two hardcoded always-on
    // categories (unchanged, pre-existing behavior), OR its resolved
    // PRIORITY is 'critical', regardless of type. This is what lets e.g.
    // a 'payment' notification explicitly escalated to critical still
    // reach a user who has payment alerts turned off.
    $alwaysOn = in_array($type, ['security', 'emergency'], true) || $priority === 'critical';

    // Admin-level kill switch — an entire category disabled platform-
    // wide via System Settings. Never applies to security/emergency/critical.
    if (!$alwaysOn) {
        if (!get_system_setting($pdo, "notify_category_{$type}_enabled", true)) {
            return null;
        }
    }

    // Per-user preference gate — this is what used to be a
    // "SELECT notify_x FROM users WHERE id=?" duplicated ad hoc at each
    // call site; centralized here so new integrations don't repeat it.
    $prefColumn = $defaults['pref'];
    $userType = $options['user_type'] ?? null;
    if (($prefColumn !== null && empty($options['force'])) || $userType === null) {
        $stmt = $pdo->prepare("SELECT user_type" . ($prefColumn !== null ? ", {$prefColumn} AS pref_enabled" : '') . " FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null; // user not found
        }
        $userType = $userType ?? $row['user_type'];
        if (!$alwaysOn && $prefColumn !== null && empty($options['force']) && !$row['pref_enabled']) {
            return null; // user opted out of this category
        }
    }

    $link = $options['action_url'] ?? $options['link'] ?? null;
    $meta = $options['meta'] ?? null;
    $deliveryMethod = $options['delivery_method'] ?? 'database,in_app';
    if (is_array($deliveryMethod)) {
        $deliveryMethod = implode(',', $deliveryMethod);
    }

    $publicId = generate_unique_public_id($pdo, 'notifications', 'public_id', 'NOTIF');
    $stmt = $pdo->prepare("
        INSERT INTO notifications
            (public_id, user_id, user_type, title, message, notification_type, priority, icon, color,
             link, action, action_id, action_url, related_table, related_public_id,
             created_by, meta, delivery_method, status, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', ?)
    ");
    $stmt->execute([
        $publicId, $userId, $userType,
        $options['title'] ?? null,
        trim((string)$options['message']),
        $type,
        $priority,
        $options['icon'] ?? $defaults['icon'],
        $options['color'] ?? $defaults['color'],
        $link,
        $options['action'] ?? null,
        $options['action_id'] ?? null,
        $link,
        $options['related_table'] ?? null,
        $options['related_public_id'] ?? null,
        $options['created_by'] ?? null,
        $meta !== null ? (is_string($meta) ? $meta : json_encode($meta)) : null,
        $deliveryMethod,
        $options['expires_at'] ?? null,
    ]);

    // Extension point for real-time delivery: once WebSockets/SSE
    // replace polling, publish the freshly-inserted row to the user's
    // channel right here. Every caller in the app — this function
    // directly, or notify_user() below — already funnels through this
    // exact point, so no business logic anywhere else has to change
    // when that swap happens.

    return $publicId;
}

// Kept for every notify_user($pdo, $userId, $message, $link) call site
// written before the Notification Engine existed (~13 of them across
// rideshare.php, admin-api.php, driver-comms.php, driver-settings.php,
// update-location.php) — same signature, same behavior, now routed
// through create_notification() instead of a raw INSERT so those
// callers get the richer schema/preference-gating for free.
function notify_user($pdo, $userId, $message, $link = null) {
    return create_notification($pdo, $userId, [
        'message' => $message,
        'link' => $link,
    ]);
}

// Single insert point for pricing_change_logs — every mutation made
// through api/pricing-admin.php (and the regional/promo/driver-settings
// endpoints built on top of it) calls this once per field actually
// changed, so "who changed what, from what, to what, when, from where"
// is always captured the same way.
function log_pricing_change($pdo, $adminId, $settingKey, $oldValue, $newValue) {
    $publicId = generate_unique_public_id($pdo, 'pricing_change_logs', 'public_id', 'PCL');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("INSERT INTO pricing_change_logs (public_id, admin_id, setting_key, old_value, new_value, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$publicId, $adminId, $settingKey, $oldValue, $newValue, $ip]);
    return $publicId;
}

// The only two functions allowed to touch users.wallet_balance. Every
// caller gets a paired ledger row for free — no code path can move
// money without leaving an audit trail. Both must run inside the
// caller's own transaction (debit_wallet's FOR UPDATE only locks
// within an active transaction). Return the new balance on success;
// return value must be checked with === false, since a legitimate
// resulting balance of 0.0 is falsy in a loose comparison.
function credit_wallet($pdo, $userId, $amount, $category, $description, $rideShareId = null, $bookingId = null, $meta = null) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
    $stmt->execute([$amount, $userId]);

    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $newBalance = (float)$stmt->fetchColumn();

    $reference = generate_unique_public_id($pdo, 'wallet_transactions', 'reference', 'TXN');
    $stmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, category, ride_share_id, booking_id, balance_after, meta, reference) VALUES (?, ?, 'credit', ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $amount, $description, $category, $rideShareId, $bookingId, $newBalance, $meta !== null ? json_encode($meta) : null, $reference]);

    // Notification Engine: every real wallet credit notifies its
    // recipient, using the ledger's own $description as the message so
    // this stays accurate for every category (top-up, driver earning,
    // released commission, refund, ...) without a per-category branch.
    create_notification($pdo, $userId, [
        'title' => 'Wallet credited: ₦' . number_format($amount, 2),
        'message' => $description,
        'notification_type' => 'wallet',
        'priority' => 'high', // a real balance change is always worth surfacing above default 'wallet' noise
        'action' => 'open_wallet',
        'related_table' => 'wallet_transactions',
        'related_public_id' => $reference,
        'meta' => ['category' => $category, 'amount' => $amount, 'balance_after' => $newBalance],
    ]);

    return $newBalance;
}

// $allowNegative exists only for rare, explicit admin-style overrides —
// every ordinary debit path in this app blocks on insufficient funds.
function debit_wallet($pdo, $userId, $amount, $category, $description, $rideShareId = null, $bookingId = null, $meta = null, $allowNegative = false) {
    $amount = round((float)$amount, 2);
    if ($amount <= 0) {
        return false;
    }

    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $currentBalance = (float)$stmt->fetchColumn();

    if (!$allowNegative && $currentBalance < $amount) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ?");
    $stmt->execute([$amount, $userId]);
    $newBalance = $currentBalance - $amount;

    $reference = generate_unique_public_id($pdo, 'wallet_transactions', 'reference', 'TXN');
    $stmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, category, ride_share_id, booking_id, balance_after, meta, reference) VALUES (?, ?, 'debit', ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $amount, $description, $category, $rideShareId, $bookingId, $newBalance, $meta !== null ? json_encode($meta) : null, $reference]);

    create_notification($pdo, $userId, [
        'title' => 'Wallet debited: ₦' . number_format($amount, 2),
        'message' => $description,
        'notification_type' => 'wallet',
        'priority' => 'high', // a real balance change is always worth surfacing above default 'wallet' noise
        'action' => 'open_wallet',
        'related_table' => 'wallet_transactions',
        'related_public_id' => $reference,
        'meta' => ['category' => $category, 'amount' => $amount, 'balance_after' => $newBalance],
    ]);

    return $newBalance;
}

// ---- Driver Wallet Commission Reservation System ----
// A logical hold layered on top of users.wallet_balance — it never
// moves real money itself (credit_wallet()/debit_wallet() above remain
// the only two functions that do that). ride_commission_reservations
// tracks, per ride, how much of a driver's balance is currently
// promised to MohToh commission; get_available_wallet_balance() is
// what every solvency check in this system should use instead of the
// raw column from here on, so a driver can't double-commit the same
// naira to two rides created back-to-back.

// Writes a wallet_transactions row for a reservation event
// (reserve/release/use) WITHOUT touching wallet_balance — balance_after
// is just the current, unchanged balance. Kept separate from
// credit_wallet()/debit_wallet() specifically so those two remain the
// only functions that ever move real money; this is a transparency/
// audit-trail entry only ("why is my available balance lower than my
// total balance"), not a transaction.
function log_wallet_event($pdo, $userId, $amount, $type, $category, $description, $rideShareId = null, $bookingId = null) {
    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentBalance = (float)$stmt->fetchColumn();

    $reference = generate_unique_public_id($pdo, 'wallet_transactions', 'reference', 'TXN');
    $stmt = $pdo->prepare("INSERT INTO wallet_transactions (user_id, amount, type, description, category, ride_share_id, booking_id, balance_after, reference) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, round((float)$amount, 2), $type, $description, $category, $rideShareId, $bookingId, $currentBalance, $reference]);
    return $reference;
}

// wallet_balance minus every active reservation's still-outstanding
// commitment (reserved - used - released). This is "available" per the
// spec's two-balance model; wallet_balance itself stays the "total".
function get_available_wallet_balance($pdo, $driverId) {
    $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
    $stmt->execute([$driverId]);
    $walletBalance = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(reserved_amount - used_amount - released_amount), 0) FROM ride_commission_reservations WHERE driver_id = ? AND status = 'active'");
    $stmt->execute([$driverId]);
    $reservedOutstanding = (float)$stmt->fetchColumn();

    return round($walletBalance - $reservedOutstanding, 2);
}

// Total currently locked across a driver's active reservations — the
// "Reserved for Active Trips" figure the wallet UI shows.
function get_reserved_wallet_balance($pdo, $driverId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(reserved_amount - used_amount - released_amount), 0) FROM ride_commission_reservations WHERE driver_id = ? AND status = 'active'");
    $stmt->execute([$driverId]);
    return round((float)$stmt->fetchColumn(), 2);
}

// Called once, right after a ride is inserted, in the same transaction.
// Does not touch wallet_balance — only records the hold and logs it.
function create_commission_reservation($pdo, $driverId, $rideShareId, $reservedAmount) {
    $publicId = generate_unique_public_id($pdo, 'ride_commission_reservations', 'public_id', 'RES');
    $stmt = $pdo->prepare("INSERT INTO ride_commission_reservations (public_id, driver_id, ride_share_id, reserved_amount) VALUES (?, ?, ?, ?)");
    $stmt->execute([$publicId, $driverId, $rideShareId, round((float)$reservedAmount, 2)]);

    log_wallet_event($pdo, $driverId, $reservedAmount, 'debit', 'COMMISSION_RESERVED', "Commission reserved for ride #{$rideShareId}", $rideShareId);
    create_notification($pdo, $driverId, [
        'title' => 'Commission reserved',
        'message' => "₦" . number_format((float)$reservedAmount, 2) . " reserved from your wallet as the maximum possible commission for this trip.",
        'notification_type' => 'wallet',
        'action' => 'open_wallet',
        'related_table' => 'ride_commission_reservations',
        'related_public_id' => $publicId,
    ]);
    return $publicId;
}

// Ride edited (seats/price changed) after its reservation was already
// created — keeps reserved_amount in sync with the ride's current
// actual max-possible commission instead of letting it silently drift.
// Returns false if the increase can't be covered by available balance
// (caller should reject the edit); true otherwise (including no-op).
function adjust_commission_reservation($pdo, $driverId, $rideShareId, $newReservedAmount) {
    $stmt = $pdo->prepare("SELECT id, reserved_amount FROM ride_commission_reservations WHERE ride_share_id = ? AND status = 'active' FOR UPDATE");
    $stmt->execute([$rideShareId]);
    $reservation = $stmt->fetch();
    if (!$reservation) {
        // No active reservation (shouldn't normally happen for a ride
        // still open for edits) — create one fresh rather than fail the edit.
        create_commission_reservation($pdo, $driverId, $rideShareId, $newReservedAmount);
        return true;
    }

    $delta = round((float)$newReservedAmount - (float)$reservation['reserved_amount'], 2);
    if ($delta === 0.0) {
        return true;
    }

    if ($delta > 0 && get_available_wallet_balance($pdo, $driverId) < $delta) {
        return false;
    }

    $pdo->prepare("UPDATE ride_commission_reservations SET reserved_amount = ? WHERE id = ?")
        ->execute([round((float)$newReservedAmount, 2), $reservation['id']]);

    log_wallet_event($pdo, $driverId, abs($delta), $delta > 0 ? 'debit' : 'credit', $delta > 0 ? 'COMMISSION_RESERVED' : 'COMMISSION_RELEASED', ($delta > 0 ? 'Additional commission reserved' : 'Excess commission reservation released') . " after ride #{$rideShareId} was updated", $rideShareId);
    return true;
}

// Ride completion settlement for the reservation itself — sums actual
// commission owed from *completed* bookings' payments rows (both
// wallet- and direct-paid; direct's real wallet debit still only
// happens at confirm_direct_payment, this just formally reclassifies
// it from "held" to "used" the moment the trip completes), moves the
// unused remainder to released, and closes the reservation out.
function settle_ride_reservation($pdo, $rideShareId) {
    $stmt = $pdo->prepare("SELECT * FROM ride_commission_reservations WHERE ride_share_id = ? AND status = 'active' FOR UPDATE");
    $stmt->execute([$rideShareId]);
    $reservation = $stmt->fetch();
    if (!$reservation) {
        return; // already settled/cancelled, or never had one — nothing to do
    }

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.commission_amount), 0)
        FROM payments p
        JOIN ride_share_bookings b ON b.id = p.booking_id
        WHERE p.ride_share_id = ? AND b.status = 'completed'
    ");
    $stmt->execute([$rideShareId]);
    $usedAmount = round((float)$stmt->fetchColumn(), 2);
    $usedAmount = min($usedAmount, (float)$reservation['reserved_amount']); // never exceed what was actually held
    $releasedAmount = round((float)$reservation['reserved_amount'] - $usedAmount, 2);

    $pdo->prepare("UPDATE ride_commission_reservations SET used_amount = ?, released_amount = ?, status = 'settled', released_at = NOW() WHERE id = ?")
        ->execute([$usedAmount, $releasedAmount, $reservation['id']]);

    $driverId = $reservation['driver_id'];
    if ($usedAmount > 0) {
        log_wallet_event($pdo, $driverId, $usedAmount, 'debit', 'COMMISSION_USED', "MohToh commission collected on ride #{$rideShareId}", $rideShareId);
    }
    if ($releasedAmount > 0) {
        log_wallet_event($pdo, $driverId, $releasedAmount, 'credit', 'COMMISSION_RELEASED', "Unused commission reservation released — ride #{$rideShareId} completed with unfilled seats", $rideShareId);
        create_notification($pdo, $driverId, [
            'title' => 'Commission released',
            'message' => "₦" . number_format($releasedAmount, 2) . " of your reserved commission was released back to your wallet — ride #{$rideShareId} completed with unfilled seats.",
            'notification_type' => 'wallet',
            'action' => 'open_wallet',
            'related_table' => 'ride_commission_reservations',
            'related_public_id' => $reservation['public_id'],
        ]);
    }
}

// Cancellation (driver- or admin-initiated) — releases whatever wasn't
// already used back to available. Idempotent: no-ops if already
// settled/cancelled, so it's always safe to call from a cancel path.
function release_ride_reservation($pdo, $rideShareId) {
    $stmt = $pdo->prepare("SELECT * FROM ride_commission_reservations WHERE ride_share_id = ? AND status = 'active' FOR UPDATE");
    $stmt->execute([$rideShareId]);
    $reservation = $stmt->fetch();
    if (!$reservation) {
        return;
    }

    $releasedAmount = round((float)$reservation['reserved_amount'] - (float)$reservation['used_amount'], 2);

    $pdo->prepare("UPDATE ride_commission_reservations SET released_amount = ?, status = 'cancelled', released_at = NOW() WHERE id = ?")
        ->execute([$releasedAmount, $reservation['id']]);

    if ($releasedAmount > 0) {
        log_wallet_event($pdo, $reservation['driver_id'], $releasedAmount, 'credit', 'TRIP_CANCELLED_RELEASE', "Commission reservation released — ride #{$rideShareId} cancelled", $rideShareId);
        create_notification($pdo, $reservation['driver_id'], [
            'title' => 'Commission released',
            'message' => "₦" . number_format($releasedAmount, 2) . " reserved commission was released back to your wallet — ride #{$rideShareId} was cancelled.",
            'notification_type' => 'wallet',
            'action' => 'open_wallet',
            'related_table' => 'ride_commission_reservations',
            'related_public_id' => $reservation['public_id'],
        ]);
    }
}

// Shared ride-completion settlement: the per-booking driver-earning
// credit (unchanged logic, previously inline in rideshare.php's
// complete_ride) plus the new reservation settlement. Used by both the
// driver's own complete_ride action AND admin's complete_ride_share —
// the latter previously skipped earning-credit entirely, a pre-existing
// gap that would otherwise leave every admin-completed ride's
// reservation stuck open forever.
function settle_ride_completion($pdo, $rideId) {
    require_once __DIR__ . '/network.php';

    $stmt = $pdo->prepare("SELECT id, payment_method FROM ride_share_bookings WHERE ride_share_id = ? AND status = 'completed'");
    $stmt->execute([$rideId]);
    $completedBookings = $stmt->fetchAll();

    $totalEarningCredited = 0.0;
    foreach ($completedBookings as $booking) {
        if ($booking['payment_method'] === 'wallet') {
            $stmt2 = $pdo->prepare("SELECT driver_earning_amount, driver_id FROM payments WHERE booking_id = ? AND payment_method = 'wallet'");
            $stmt2->execute([$booking['id']]);
            $paymentRow = $stmt2->fetch();
            $earning = (float)($paymentRow['driver_earning_amount'] ?? 0);
            if ($earning > 0) {
                credit_wallet($pdo, $paymentRow['driver_id'], $earning, 'driver_earning', "Earning from completed ride #{$rideId} (booking #{$booking['id']})", $rideId, $booking['id']);
                $totalEarningCredited += $earning;
            }
        }
        // direct payments settle their driver-earning credit via
        // confirm_direct_payment, but EVERY completed booking (wallet
        // or direct) is a candidate for a MOHTOH NETWORK reward — the
        // engine itself checks payment confirmation and is idempotent,
        // so it's safe to call here regardless of payment method or
        // call order. Never let a NETWORK failure affect settlement.
        try {
            network_settle_booking_reward($pdo, $booking['id']);
        } catch (Throwable $e) {
            error_log('MOHTOH NETWORK settlement failed for booking #' . $booking['id'] . ': ' . $e->getMessage());
        }
    }

    settle_ride_reservation($pdo, $rideId);

    return round($totalEarningCredited, 2);
}

// ---- Paystack HTTP client ----
// Updated to read configuration directly from the 'integrations' database table.
function paystack_config() {
    global $pdo; // Assumes $pdo is available in the scope where called
    $stmt = $pdo->prepare("SELECT configuration FROM integrations WHERE provider = 'paystack' LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Paystack integration not configured.');
    }
    $config = json_decode($row['configuration'], true);
    
    // Decrypt the secret key
    require_once __DIR__ . '/secrets.php';
    $decryptedSecret = decrypt_secret($config['secret_key']);
    
    return [
        'secret' => $decryptedSecret,
        'public' => $config['public_key'],
        'mode' => $config['sandbox_mode'] === '1' ? 'test' : 'live'
    ];
}

// Whether the admin has the Paystack integration switched ON, read from the
// same `integrations` row's `status` column that the Service Integration
// Center's Payment card controls. This is the backend enforcement point for
// the admin Enable/Disable toggle — see paystack_initialize_transaction().
// Deliberately reads only `status` (not the config/secret) so it stays cheap
// and never touches decryption. Kept here, next to paystack_config(), so the
// runtime payment path has no dependency on the admin-only
// includes/payment-integration.php service file.
function paystack_is_enabled() {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status FROM integrations WHERE provider = 'paystack' LIMIT 1");
    $stmt->execute();
    return $stmt->fetchColumn() === 'enabled';
}

// Minimal cURL wrapper — no SDK dependency. Callers check the
// returned array's ['status'] bool (Paystack's own success/failure
// signal), same contract Paystack's own SDKs expose. Throws only on
// transport-level failure (network error, non-JSON response), never
// on a well-formed "declined" response from Paystack itself.
// Requires includes/config.php to already be loaded by the caller
// (paystack_config(), PAYSTACK_BASE_URL) — functions.php itself has
// no config.php dependency otherwise, so this is deliberately not
// required at the top of this file.
function paystack_request($method, $path, $body = null) {
    $config = paystack_config();
    $ch = curl_init(PAYSTACK_BASE_URL . $path);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $config['secret'],
        'Content-Type: application/json',
    ]);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Paystack request failed: ' . $error);
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Paystack returned a non-JSON response.');
    }
    return $decoded;
}

// $amountKobo must already be an integer number of kobo (Naira * 100)
// — converting is the caller's job, kept out of here so this function
// never silently guesses a currency multiplier.
//
// Respects the admin Enable/Disable switch: if the Integration Center has the
// Paystack row set to 'disabled', NO new charge is started — we throw before
// contacting Paystack. The two callers (api/wallet.php, api/v1/wallet/topup.php)
// already wrap this in try/catch and mark the pending top-up PAYMENT_FAILED, so
// a disabled provider fails safe (no money moves, no partial state). NOTE: the
// disable gate is intentionally ONLY here, on new-charge initiation. Transaction
// VERIFICATION (paystack_verify_transaction) and the webhook/callback that
// confirm an already-charged payment are NOT gated — blocking those after a
// disable would strand a customer's in-flight money. "Existing payments must
// not break."
function paystack_initialize_transaction($email, $amountKobo, $reference, $callbackUrl) {
    if (!paystack_is_enabled()) {
        throw new RuntimeException('Paystack payments are currently disabled by the administrator.');
    }
    return paystack_request('POST', '/transaction/initialize', [
        'email' => $email,
        'amount' => $amountKobo,
        'reference' => $reference,
        'callback_url' => $callbackUrl,
    ]);
}

function paystack_verify_transaction($reference) {
    return paystack_request('GET', '/transaction/verify/' . rawurlencode($reference));
}

// The one place a verified Paystack top-up actually credits a wallet.
// Called from both the browser-redirect callback and the webhook —
// whichever reaches here first does the crediting; the other finds
// the row already PAYMENT_CONFIRMED and safely no-ops. The atomic
// UPDATE...WHERE status='PAYMENT_PENDING' + rowCount check is what
// makes that race safe (same pattern as mark_ride_started()).
function finalize_wallet_topup($pdo, $topupId, $paystackData) {
    $stmt = $pdo->prepare("SELECT id, user_id, amount, public_id, status FROM wallet_topups WHERE id = ? FOR UPDATE");
    $stmt->execute([$topupId]);
    $topup = $stmt->fetch();
    if (!$topup) {
        return false;
    }
    if ($topup['status'] !== 'PAYMENT_PENDING') {
        return true; // already handled by the other path — not an error
    }

    $paidKobo = (int)($paystackData['amount'] ?? 0);
    $expectedKobo = (int)round((float)$topup['amount'] * 100);
    if ($paidKobo !== $expectedKobo) {
        // Amount mismatch: never credit. Mark failed so it's visible
        // and investigable rather than silently stuck pending forever.
        $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_FAILED', raw_response = ?, verified_at = NOW() WHERE id = ? AND status = 'PAYMENT_PENDING'")
            ->execute([json_encode($paystackData), $topupId]);
        return false;
    }

    $stmt = $pdo->prepare("UPDATE wallet_topups SET status = 'PAYMENT_CONFIRMED', paystack_transaction_id = ?, channel = ?, raw_response = ?, verified_at = NOW() WHERE id = ? AND status = 'PAYMENT_PENDING'");
    $stmt->execute([
        $paystackData['id'] ?? null,
        $paystackData['channel'] ?? null,
        json_encode($paystackData),
        $topupId
    ]);
    if ($stmt->rowCount() === 0) {
        return true; // lost the race to the other path — fine, not an error
    }

    credit_wallet($pdo, $topup['user_id'], (float)$topup['amount'], 'topup', "Wallet top-up via Paystack ({$topup['public_id']})", null, null, [
        'gateway' => 'paystack',
        'reference' => $topup['public_id'],
        'paystack_transaction_id' => $paystackData['id'] ?? null,
        'channel' => $paystackData['channel'] ?? null,
    ]);

    return true;
}
?>
