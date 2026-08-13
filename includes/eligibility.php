<?php
// includes/eligibility.php
//
// Single source of truth for "is this driver allowed to operate right now?"
// Before this existed, ride CREATION was gated (rideshare.php) but going
// online, being discoverable, accepting a private pickup, and starting a
// trip were NOT re-checked - an unverified or later-revoked driver leaked
// through. Every operational entry point now funnels through here so the
// gate is enforced at the API/backend level, never trusted from the client.
//
// Eligibility has three independent layers, matching the audit's
// "separate operational states" requirement:
//   1. Account contact verification (email/phone) - reuses is_user_verified().
//   2. Driver account + vehicle verification (users.is_verified AND the
//      vehicle being approved/verified).
//   3. Capability + live availability for the SPECIFIC operation requested
//      (join_ride vs private_pickup), which are distinct toggles.

require_once __DIR__ . '/verification.php';

/**
 * Core driver eligibility check. Returns:
 *   ['eligible' => bool, 'reason' => string, 'code' => string]
 *
 * $operation: 'base' (verified + vehicle approved, no capability check),
 *             'join_ride', 'private_pickup', or 'private_pickup_capability'.
 *
 * 'private_pickup' vs 'private_pickup_capability': the former is the full
 * operational gate (may this driver receive/accept a request RIGHT NOW),
 * which includes the live is_online flag. The latter answers only "is this
 * driver permitted to operate private pickup at all" - keke + verified
 * vehicle + capability enabled + opted in today + platform on - WITHOUT
 * consulting is_online. api/toggle-online.php must use the capability form:
 * gating the act of going online on the full check made is_online a
 * precondition of setting is_online, so an offline driver could never come
 * online (the flag can only ever be cleared, never set).
 *
 * Pure function of current DB state - safe to call on every request,
 * including mid-trip re-validation, so revoking a driver actually stops them.
 */
function check_driver_eligibility(PDO $pdo, int $driverId, string $operation = 'base'): array {
    $stmt = $pdo->prepare(
        "SELECT u.user_type, u.is_verified, u.is_suspended, u.email_verified, u.phone_verified,
                u.is_online, u.join_ride_enabled, u.private_pickup_enabled, u.is_keke_driver,
                u.pickup_available_today, u.pickup_availability_date,
                v.status AS vehicle_status, v.verification_status AS vehicle_verification_status,
                v.vehicle_type AS vehicle_type
         FROM users u
         LEFT JOIN vehicles v ON v.driver_id = u.id
         WHERE u.id = ?"
    );
    $stmt->execute([$driverId]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['eligible' => false, 'reason' => 'Driver account not found.', 'code' => 'not_found'];
    }
    if ($row['user_type'] !== 'driver') {
        return ['eligible' => false, 'reason' => 'This account is not a driver account.', 'code' => 'not_driver'];
    }
    if (!empty($row['is_suspended'])) {
        return ['eligible' => false, 'reason' => 'Your account is suspended. Please contact support.', 'code' => 'suspended'];
    }

    // Layer 1 - contact verification (reuses the existing channel logic).
    if (!is_user_verified($pdo, $driverId)) {
        return ['eligible' => false, 'reason' => 'Please complete your account verification before going on duty.', 'code' => 'contact_unverified'];
    }

    // Layer 2 - driver account + vehicle verification.
    if (empty($row['is_verified'])) {
        return ['eligible' => false, 'reason' => 'Your driver account is not yet verified.', 'code' => 'account_unverified'];
    }
    if ($row['vehicle_status'] === null) {
        return ['eligible' => false, 'reason' => 'You must add and verify a vehicle before operating.', 'code' => 'no_vehicle'];
    }
    $vehicleApproved = strtolower((string)$row['vehicle_status']) === 'approved'
        && strtolower((string)$row['vehicle_verification_status']) === 'verified';
    if (!$vehicleApproved) {
        return ['eligible' => false, 'reason' => 'Your vehicle is not verified. Complete vehicle verification to operate.', 'code' => 'vehicle_unverified'];
    }

    // Layer 3 - capability + live availability for the requested operation.
    if ($operation === 'join_ride') {
        if (empty($row['join_ride_enabled'])) {
            return ['eligible' => false, 'reason' => 'Join Ride is turned off for your account.', 'code' => 'join_ride_disabled'];
        }
    } elseif ($operation === 'private_pickup' || $operation === 'private_pickup_capability') {
        // Private Pickup serves two vehicle classes: keke and private car.
        // The gate reads the LIVE vehicles.vehicle_type (the source of truth
        // sync_keke_classification canonicalises on write), never the cached
        // is_keke_driver flag, so conflicting or stale flag data can neither
        // grant nor deny this lane. A bus/motorcycle is still excluded.
        if (!is_private_pickup_vehicle_type($row['vehicle_type'])) {
            return ['eligible' => false, 'reason' => 'Private Pickup is available to verified Keke and car drivers only.', 'code' => 'ineligible_vehicle'];
        }
        if (empty($row['private_pickup_enabled'])) {
            return ['eligible' => false, 'reason' => 'Private Pickup is not enabled for your account.', 'code' => 'private_pickup_disabled'];
        }
        if (!driver_available_for_pickup_today($row)) {
            return ['eligible' => false, 'reason' => 'Confirm your availability for Private Pickup today first.', 'code' => 'not_available_today'];
        }
        // Private pickup must also be enabled platform-wide.
        if (!(int) get_system_setting($pdo, 'private_pickup_enabled', 1)) {
            return ['eligible' => false, 'reason' => 'Private Pickup is temporarily unavailable.', 'code' => 'private_pickup_off_platform'];
        }
        // Live discoverability. Checked LAST and only for the full operational
        // gate, so a driver who is merely offline still passes the capability
        // form and can flip themselves online.
        if ($operation === 'private_pickup' && empty($row['is_online'])) {
            return ['eligible' => false, 'reason' => 'You are offline. Go online to receive private pickup requests.', 'code' => 'offline'];
        }
    }

    return ['eligible' => true, 'reason' => '', 'code' => 'ok'];
}

/**
 * Whether the driver's daily availability opt-in is for TODAY specifically.
 * A "yes" from a previous day never carries over. $row must contain
 * pickup_available_today + pickup_availability_date.
 */
function driver_available_for_pickup_today(array $row): bool {
    if (empty($row['pickup_available_today'])) {
        return false;
    }
    return (string)($row['pickup_availability_date'] ?? '') === date('Y-m-d');
}

/**
 * Convenience boolean wrapper.
 */
function is_driver_eligible(PDO $pdo, int $driverId, string $operation = 'base'): bool {
    return check_driver_eligibility($pdo, $driverId, $operation)['eligible'];
}

/**
 * Canonicalises a free-text vehicle type into the storage value the rest
 * of the system matches on ('keke', 'car', 'bus', 'motorcycle').
 *
 * vehicles.vehicle_type is a VARCHAR, and the driver registration form
 * submits the lowercased UI LABEL - so a keke driver actually posts
 * "keke napep", which would never equal 'keke' and would silently leave
 * them unclassified (and therefore permanently locked out of Private
 * Pickup despite driving a keke). Normalising on write, in one place,
 * is what makes "keke driver" a reliable system-wide fact rather than a
 * string-comparison accident.
 *
 * Unknown values are passed through lowercased/trimmed rather than
 * forced to a default, so an admin-added vehicle type is never silently
 * rewritten into something else.
 */
function normalize_vehicle_type(?string $raw): string {
    $value = strtolower(trim((string)$raw));
    if ($value === '') {
        return '';
    }
    // Any label containing "keke" (keke, keke napep, tricycle keke...) or
    // the local synonyms is the same physical vehicle class.
    if (strpos($value, 'keke') !== false || $value === 'tricycle' || $value === 'napep') {
        return 'keke';
    }
    if (strpos($value, 'bus') !== false) {
        return 'bus';
    }
    if (strpos($value, 'motorcycle') !== false || $value === 'okada' || $value === 'bike') {
        return 'motorcycle';
    }
    if (strpos($value, 'car') !== false || $value === 'saloon' || $value === 'sedan' || $value === 'suv') {
        return 'car';
    }
    return $value;
}

/**
 * Whether a canonicalised vehicle type is a keke.
 */
function is_keke_vehicle_type(?string $raw): bool {
    return normalize_vehicle_type($raw) === 'keke';
}

/**
 * The vehicle classes permitted to operate Private Pickup.
 *
 * Private Pickup is a solo, whole-vehicle trip, which a keke and a private
 * car both serve equally; a bus or motorcycle does not. Naming the set once
 * here keeps the operational gate (check_driver_eligibility) and the driver
 * matching query (find_nearby_pickup_drivers) from drifting apart - a driver
 * who passes one but fails the other would either see requests they can never
 * accept, or be silently undiscoverable while the UI says they are online.
 */
function private_pickup_vehicle_types(): array {
    return ['keke', 'car'];
}

/**
 * Whether a vehicle type may operate Private Pickup. Canonicalises first, so
 * a raw registration label ("Keke Napep", "Saloon") resolves the same way the
 * stored value does.
 */
function is_private_pickup_vehicle_type(?string $raw): bool {
    return in_array(normalize_vehicle_type($raw), private_pickup_vehicle_types(), true);
}

/**
 * Keeps the driver's keke classification consistent with their actual
 * vehicle record - the single invariant that prevents conflicting
 * vehicle/capability data from accidentally granting keke functionality
 * (criterion #2). is_keke_driver is ALWAYS derived from the persisted
 * vehicles.vehicle_type, never set independently, and passenger_capacity
 * is kept in step with the vehicle's seats. Safe to call after any vehicle
 * insert/update/approval. Returns the resolved is_keke_driver (0/1).
 */
function sync_keke_classification(PDO $pdo, int $driverId): int {
    $stmt = $pdo->prepare("SELECT vehicle_type, seats, passenger_capacity FROM vehicles WHERE driver_id = ?");
    $stmt->execute([$driverId]);
    $vehicle = $stmt->fetch();

    // No vehicle -> not a keke operator.
    if (!$vehicle) {
        $pdo->prepare("UPDATE users SET is_keke_driver = 0 WHERE id = ?")->execute([$driverId]);
        return 0;
    }

    // Canonicalise the stored type itself, so every later comparison
    // (matching query, eligibility gate, Join Ride auto-config) reads a
    // single consistent value rather than whatever label was submitted.
    $canonical = normalize_vehicle_type($vehicle['vehicle_type']);
    if ($canonical !== '' && $canonical !== (string)$vehicle['vehicle_type']) {
        $pdo->prepare("UPDATE vehicles SET vehicle_type = ? WHERE driver_id = ?")
            ->execute([$canonical, $driverId]);
    }

    $isKeke = $canonical === 'keke' ? 1 : 0;
    $pdo->prepare("UPDATE users SET is_keke_driver = ? WHERE id = ?")->execute([$isKeke, $driverId]);

    // Keep the vehicle's declared passenger_capacity in step with its seats
    // so keke Join Ride pricing/validation always has a capacity to use.
    if ((int)$vehicle['passenger_capacity'] !== (int)$vehicle['seats'] && (int)$vehicle['seats'] > 0) {
        $pdo->prepare("UPDATE vehicles SET passenger_capacity = seats WHERE driver_id = ?")->execute([$driverId]);
    }

    return $isKeke;
}

/**
 * Display label for a canonical vehicle type.
 *
 * Verification is where a vehicle's class actually matters to a human: an
 * admin reviewing documents needs to know whether they are looking at a
 * car, a keke or a bus, and a keke driver needs to see that MohToh
 * recorded their vehicle as a keke rather than silently as a car. The
 * stored value is canonical ('keke'), never the label, so labelling lives
 * here next to normalize_vehicle_type() rather than being re-invented in
 * each screen.
 */
function vehicle_type_label(?string $raw): string {
    $canonical = normalize_vehicle_type($raw);
    $labels = [
        'car'        => 'Car',
        'keke'       => 'Keke Napep',
        'bus'        => 'Bus',
        'motorcycle' => 'Motorcycle',
    ];
    if (isset($labels[$canonical])) {
        return $labels[$canonical];
    }
    // An admin-added type is shown as-is rather than mislabelled.
    return $canonical === '' ? 'Not set' : ucwords(str_replace('_', ' ', $canonical));
}

/**
 * The vehicle verification documents MohToh requires, in review order.
 *
 * These are the documents the verification workflow already deals in - the
 * upload field used to be free text prefilled "Driver License", so nothing
 * told a driver WHICH documents were expected and nothing told an admin
 * which ones were still missing. Naming them once, here, is what lets the
 * driver sheet render a checklist and the admin panel label what it is
 * reviewing.
 *
 * The list is deliberately NOT per vehicle type: MohToh's verification
 * configuration defines one document set, and a keke is verified against
 * the same set as a car or a bus. That is the point - keke must not be
 * excluded from the workflow, and inventing keke-only paperwork would be
 * inventing regulation the application does not have.
 */
function vehicle_verification_documents(): array {
    return [
        ['type' => 'licence_plate',   'label' => 'Licence Plate'],
        ['type' => 'vehicle_licence', 'label' => 'Vehicle Licence'],
        ['type' => 'insurance',       'label' => 'Insurance'],
        ['type' => 'driver_licence',  'label' => 'Driver Licence'],
    ];
}

/**
 * Whether a submitted document type is one the workflow accepts.
 * 'other' stays valid so the free-text upload's ability to carry an extra
 * document is not lost by moving to a fixed list.
 */
function is_valid_vehicle_document_type(?string $type): bool {
    if ($type === 'other') {
        return true;
    }
    foreach (vehicle_verification_documents() as $doc) {
        if ($doc['type'] === $type) {
            return true;
        }
    }
    return false;
}

/**
 * Display label for a document type.
 */
function vehicle_document_label(?string $type): string {
    foreach (vehicle_verification_documents() as $doc) {
        if ($doc['type'] === $type) {
            return $doc['label'];
        }
    }
    return 'Other document';
}

/**
 * Resolves the checklist slot a stored document belongs to.
 *
 * vehicle_verifications.document_type exists but was never written, so
 * every document uploaded before this change has document_type = NULL and
 * only the free-text name the driver typed. Those uploads are real
 * documents and must keep counting towards the checklist, so they are
 * matched on their name at read time. Nothing is back-filled or rewritten:
 * the driver's submitted record stays exactly as they submitted it.
 */
function resolve_vehicle_document_type(?string $documentType, ?string $documentName): string {
    $stored = strtolower(trim((string)$documentType));
    if ($stored !== '') {
        // An unrecognised stored value still has to land in a slot the UI
        // renders, or the document would silently disappear from both the
        // checklist and the "other documents" list.
        return is_valid_vehicle_document_type($stored) ? $stored : 'other';
    }
    $name = strtolower(trim((string)$documentName));
    if ($name === '') {
        return 'other';
    }
    $hasLicence = strpos($name, 'licen') !== false || strpos($name, 'lisen') !== false;
    if (strpos($name, 'plate') !== false) {
        return 'licence_plate';
    }
    if (strpos($name, 'insur') !== false) {
        return 'insurance';
    }
    if ($hasLicence && strpos($name, 'driver') !== false) {
        return 'driver_licence';
    }
    if ($hasLicence || strpos($name, 'registration') !== false || strpos($name, 'roadworth') !== false) {
        return 'vehicle_licence';
    }
    return 'other';
}

