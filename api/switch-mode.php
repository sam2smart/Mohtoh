<?php
// api/switch-mode.php
//
// Operating-mode switching for ONE account: Rider <-> Driver, and within
// Driver, Car <-> Keke. There is no second account and no third auth role.
//
// Two distinct concepts, deliberately kept apart:
//
//   CAPABILITY  = users.user_type  (persisted, the account of record)
//   CURRENT MODE = $_SESSION['user_type']  (which hat the user is wearing now)
//
// $_SESSION['user_type'] is already the universal gate every page and API in
// this app reads, so it is reused as the current-mode field rather than
// adding a new column. Switching to Rider therefore only rewrites the
// session: users.user_type stays 'driver', and the vehicle, verification
// documents, wallet, ratings and ride history are all untouched. Switching
// back to Driver needs no re-registration because the capability never left.
//
// Car vs Keke needs no field at all: vehicles.driver_id is UNIQUE, so a
// driver has exactly one vehicle and vehicles.vehicle_type IS the class.
// "Switch to Keke" is therefore an authorisation question, not a write -
// it is allowed only if the driver's own verified vehicle is a keke. To
// actually change class a driver edits the vehicle through the existing
// api/driver-api.php setup_vehicle flow, which resets it to pending and
// sends it back through admin verification. Nothing here marks a vehicle
// verified, and nothing here bypasses check_driver_eligibility().
//
// Every decision below is made from the DB row for the authenticated
// session user. vehicle_type, user_type and mode arriving from the browser
// are treated purely as a REQUEST that must be matched against that row -
// posting vehicle_class=keke as a rider, or as a car driver, is refused.

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/eligibility.php';
require_once '../includes/admin_permissions.php'; // ensure_admin_rbac_schema() + admin capability fields

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

// Self-heal the admin RBAC columns the account SELECT below reads, so a DB
// where migrate_admin_rbac.php has not run yet cannot fatal on this endpoint.
ensure_admin_rbac_schema($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if ($action === '' && isset($input['action'])) {
    $action = $input['action'];
}

$userId = (int) $_SESSION['user_id'];

// The account of record. Read fresh on every call so an admin suspending an
// account, revoking a vehicle or changing its class takes effect on the next
// switch attempt rather than at next login.
$stmt = $pdo->prepare(
    "SELECT u.user_type, u.is_suspended, u.is_keke_driver, u.private_pickup_enabled,
            u.is_admin, u.admin_status,
            v.id AS vehicle_id, v.vehicle_type, v.status AS vehicle_status,
            v.verification_status AS vehicle_verification_status
     FROM users u
     LEFT JOIN vehicles v ON v.driver_id = u.id
     WHERE u.id = ?"
);
$stmt->execute([$userId]);
$account = $stmt->fetch();

if (!$account) {
    json_response('error', 'Account not found.');
}

// Admin is an authentication role, not an operating mode - it has no rider or
// driver side and must never be reachable or leavable through this endpoint.
if ($account['user_type'] === 'admin') {
    json_response('error', 'Admin accounts do not use rider/driver modes.');
}

$hasDriverCapability = ($account['user_type'] === 'driver');
$vehicleClass = normalize_vehicle_type($account['vehicle_type']); // '' when no vehicle
$sessionMode = $_SESSION['user_type'] ?? 'rider';
$currentMode = in_array($sessionMode, ['driver', 'admin'], true) ? $sessionMode : 'rider';

// Admin is an ADDITIVE capability (a rider/driver who is also an admin). It is
// usable as a mode only when the flag is set, the admin hat is active and the
// account is not suspended - all read fresh from the DB row above, so a
// suspension or de-admin takes effect on the next switch with no re-login.
$hasAdminCapability = !empty($account['is_admin'])
    && (($account['admin_status'] ?? 'active') === 'active')
    && empty($account['is_suspended']);

// Full operational eligibility (verified account + verified vehicle). Used to
// describe the driver side to the UI and to authorise a CLASS selection - not
// to gate entry into driver mode itself, because the driver dashboard is
// exactly where an unverified driver completes verification.
$baseEligibility = $hasDriverCapability
    ? check_driver_eligibility($pdo, $userId, 'base')
    : ['eligible' => false, 'reason' => 'This account is not a driver account.', 'code' => 'not_driver'];

/**
 * The mode/class picture for the authenticated user, built entirely from the
 * DB row above. Shared by every action so the client always gets back the
 * state the server actually believes in.
 */
function switch_mode_state(array $account, bool $hasDriverCapability, string $vehicleClass, string $currentMode, array $baseEligibility, bool $hasAdminCapability): array
{
    $classes = [];
    foreach (['car', 'keke'] as $class) {
        $owned = ($vehicleClass === $class);
        $available = $owned && $baseEligibility['eligible'];
        if ($available) {
            $reason = '';
        } elseif (!$hasDriverCapability) {
            $reason = 'Become a driver first.';
        } elseif (!$owned) {
            $reason = $vehicleClass === ''
                ? 'Register a ' . vehicle_type_label($class) . ' to operate this mode.'
                : 'Your registered vehicle is a ' . vehicle_type_label($vehicleClass) . '. Update your vehicle to switch class.';
        } else {
            $reason = $baseEligibility['reason'];
        }
        $classes[] = [
            'class' => $class,
            'label' => vehicle_type_label($class),
            'owned' => $owned,
            'available' => $available,
            'reason' => $reason,
        ];
    }

    return [
        'current_mode' => $currentMode,
        'current_class' => $currentMode === 'driver' && $vehicleClass !== '' ? $vehicleClass : null,
        'has_driver_capability' => $hasDriverCapability,
        'vehicle_class' => $vehicleClass !== '' ? $vehicleClass : null,
        'vehicle_label' => $vehicleClass !== '' ? vehicle_type_label($vehicleClass) : null,
        'has_vehicle' => !empty($account['vehicle_id']),
        'vehicle_verification_status' => $account['vehicle_verification_status'],
        'driver_ready' => (bool) $baseEligibility['eligible'],
        'driver_blocked_reason' => $baseEligibility['eligible'] ? '' : $baseEligibility['reason'],
        'driver_blocked_code' => $baseEligibility['eligible'] ? '' : $baseEligibility['code'],
        'is_admin' => $hasAdminCapability,
        'admin_status' => $account['admin_status'] ?? 'active',
        'classes' => $classes,
    ];
}

$state = switch_mode_state($account, $hasDriverCapability, $vehicleClass, $currentMode, $baseEligibility, $hasAdminCapability);

// ---------------------------------------------------------------- read only
if ($action === 'get_modes' || $action === '') {
    json_response('success', 'Modes loaded', $state);
}

// Everything past here mutates state.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

switch ($action) {

    // ------------------------------------------------------------- switching
    case 'switch': {
        $mode = strtolower(trim((string) ($input['mode'] ?? '')));
        if (!in_array($mode, ['rider', 'driver', 'admin'], true)) {
            json_response('error', 'Invalid mode.');
        }

        // Admin mode is the additive admin hat. Gated on the fresh DB read
        // ($hasAdminCapability = is_admin AND admin_status active AND not
        // suspended); the client's word is never trusted. Switching only
        // rewrites the session, exactly like the rider/driver switches below,
        // so the person's rider/driver capability and history stay intact.
        if ($mode === 'admin') {
            if (!$hasAdminCapability) {
                json_response('error', 'This account does not have active admin access.', $state + ['code' => 'not_admin']);
            }
            $_SESSION['user_type'] = 'admin';
            $state['current_mode'] = 'admin';
            $state['current_class'] = null;
            json_response('success', 'Switched to Admin mode.', $state + ['redirect' => '../admin/dashboard.php']);
        }

        // Rider mode is available to every non-admin account, always. It is a
        // pure session change: no row is deleted, disabled or downgraded, so
        // driver capability, the vehicle, its verification documents, the
        // wallet and all history survive untouched.
        if ($mode === 'rider') {
            $_SESSION['user_type'] = 'rider';
            $state['current_mode'] = 'rider';
            $state['current_class'] = null;
            json_response('success', 'Switched to Rider mode.', $state + ['redirect' => '../rider/dashboard.php']);
        }

        // Driver mode requires the persisted capability - never the client's
        // word for it.
        if (!$hasDriverCapability) {
            json_response('error', 'This account is not registered as a driver.', $state + ['code' => 'not_driver']);
        }
        if (!empty($account['is_suspended'])) {
            json_response('error', 'Your account is suspended. Please contact support.', $state + ['code' => 'suspended']);
        }

        // An optional class request ("switch to Keke"/"switch to Car") must
        // match the driver's OWN vehicle, and that vehicle must already be
        // through verification. This is the gate that stops a rider - or a
        // car driver - from posting vehicle_class=keke to operate as keke.
        $requestedClass = normalize_vehicle_type((string) ($input['vehicle_class'] ?? ''));
        if ($requestedClass !== '') {
            if (!in_array($requestedClass, ['car', 'keke'], true)) {
                json_response('error', 'Unsupported vehicle class.', $state);
            }
            if ($vehicleClass === '') {
                json_response('error', 'Add and verify a vehicle before choosing an operating class.', $state + ['code' => 'no_vehicle']);
            }
            if ($requestedClass !== $vehicleClass) {
                json_response('error', 'Your registered vehicle is a ' . vehicle_type_label($vehicleClass) . ', so you cannot operate as a ' . vehicle_type_label($requestedClass) . ' driver. Update your vehicle details to change class - the new vehicle goes through verification again.', $state + ['code' => 'class_mismatch']);
            }
            // Same class as the verified vehicle: still require the vehicle to
            // have actually passed verification before claiming the class.
            if (!$baseEligibility['eligible']) {
                json_response('error', $baseEligibility['reason'], $state + ['code' => $baseEligibility['code']]);
            }
        }

        $_SESSION['user_type'] = 'driver';
        $state['current_mode'] = 'driver';
        $state['current_class'] = $vehicleClass !== '' ? $vehicleClass : null;

        $message = $requestedClass !== ''
            ? 'Switched to Driver mode (' . vehicle_type_label($vehicleClass) . ').'
            : 'Switched to Driver mode.';
        json_response('success', $message, $state + ['redirect' => '../driver/dashboard.php']);
    }

    // ------------------------------------------------- rider -> driver, once
    // Grants the driver CAPABILITY to an existing rider account. Mirrors the
    // driver branch of api/auth.php register exactly: it sets user_type and,
    // for a declared keke, the same two flags registration sets. It
    // deliberately does NOT touch is_verified, does NOT create a vehicle row
    // and does NOT change the public user_id - so the driver lands on the
    // existing driver dashboard with no vehicle and completes the normal
    // setup_vehicle + admin verification flow, same as a fresh signup.
    case 'become_driver': {
        if ($hasDriverCapability) {
            // Already a driver (e.g. double-submit, or switched to rider
            // earlier) - re-entering driver mode is a switch, not a signup.
            $_SESSION['user_type'] = 'driver';
            $state['current_mode'] = 'driver';
            $state['current_class'] = $vehicleClass !== '' ? $vehicleClass : null;
            json_response('success', 'Switched to Driver mode.', $state + ['redirect' => '../driver/dashboard.php']);
        }
        if (!empty($account['is_suspended'])) {
            json_response('error', 'Your account is suspended. Please contact support.', $state + ['code' => 'suspended']);
        }

        $declaredClass = normalize_vehicle_type((string) ($input['vehicle_class'] ?? ''));
        if (!in_array($declaredClass, ['car', 'keke'], true)) {
            json_response('error', 'Choose whether you will drive a Car or a Keke Napep.');
        }

        // Guarded on user_type = 'rider' so two concurrent requests can only
        // promote the account once.
        $upd = $pdo->prepare("UPDATE users SET user_type = 'driver' WHERE id = ? AND user_type = 'rider'");
        $upd->execute([$userId]);

        // The declared class is intent only, recorded the way registration
        // records it. It grants nothing on its own: every keke/private-pickup
        // gate reads the live vehicles.vehicle_type, and sync_keke_classification()
        // re-derives is_keke_driver from the vehicle the driver actually
        // registers - so declaring keke and then registering a car self-corrects.
        if ($declaredClass === 'keke') {
            $pdo->prepare("UPDATE users SET is_keke_driver = 1, private_pickup_enabled = 1 WHERE id = ?")->execute([$userId]);
        }

        $_SESSION['user_type'] = 'driver';

        json_response('success', 'Driver mode unlocked. Add your ' . vehicle_type_label($declaredClass) . ' details to start verification.', [
            'current_mode' => 'driver',
            'current_class' => null,
            'has_driver_capability' => true,
            'vehicle_class' => null,
            'vehicle_label' => null,
            'has_vehicle' => false,
            'declared_class' => $declaredClass,
            'driver_ready' => false,
            'driver_blocked_reason' => 'You must add and verify a vehicle before operating.',
            'driver_blocked_code' => 'no_vehicle',
            'redirect' => '../driver/dashboard.php',
        ]);
    }

    default:
        json_response('error', 'Invalid action');
}
