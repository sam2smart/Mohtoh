<?php
// api/auth.php
// Authentication, Verification & Account Recovery module. Registration
// and login are extended in place (same action names, same request/
// response contract assets/js/auth.js already speaks) rather than
// replaced — only the body changed: normalized phone storage, email/OTP
// verification kicked off at registration, lockout/verification gates
// added to login.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/referral.php';
// normalize_vehicle_type() + sync_keke_classification() own the keke
// classification invariant used by driver registration below.
require_once __DIR__ . '/../includes/eligibility.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response('error', 'Invalid request method');
}

$action = $_POST['action'] ?? '';

if ($action === 'register') {
    $fullname = sanitize_input($_POST['fullname'] ?? '');
    $phone_raw = sanitize_input($_POST['phone'] ?? '');
    $email = sanitize_input($_POST['email'] ?? '');
    $password = mt_scalar_string($_POST['password'] ?? '');
    $confirm_password = array_key_exists('confirm_password', $_POST) ? mt_scalar_string($_POST['confirm_password']) : $password; // optional for API back-compat; the UI always sends it
    $user_type = sanitize_input($_POST['user_type'] ?? '');
    // Keke classification, chosen at registration (index.php reveals this
    // only for driver accounts). Riders never send it. This is the vehicle
    // CLASS the driver operates, not a separate auth role — the account is
    // an ordinary 'driver' either way.
    $driver_class = strtolower(trim(sanitize_input($_POST['driver_class'] ?? '')));
    // The registration form offers THREE choices (rider / driver / keke) but
    // MohToh has only TWO auth roles. 'keke' is the form's shorthand for "a
    // driver whose vehicle class is keke". assets/js/auth.js translates it
    // before submitting; the same translation is repeated here so the rule
    // lives with the validator that enforces it, and registration still works
    // if that client-side step never runs (stale cached auth.js, a JS error
    // earlier in the same DOMContentLoaded handler, or a non-browser client).
    // The account remains user_type='driver' — this adds no third auth role.
    if ($user_type === 'keke') {
        $user_type = 'driver';
        $driver_class = 'keke';
    }
    // MohToh Community Rewards — optional, never blocks registration if
    // absent, invalid, or self-referential (resolved silently below).
    $referral_code = trim(sanitize_input($_POST['referral_code'] ?? ''));

    if (empty($fullname) || empty($phone_raw) || empty($email) || empty($password) || empty($user_type)) {
        json_response('error', 'All fields are required');
    }

    if (!in_array($user_type, ['rider', 'driver'])) {
        json_response('error', 'Invalid user type');
    }

    // A driver must declare the vehicle class they operate. Anything other
    // than the two offered values is rejected rather than silently coerced,
    // so an unclassified driver can never end up half-way into the keke lane.
    if ($user_type === 'driver') {
        if ($driver_class === '') {
            json_response('error', 'Please choose whether you drive a car or a keke.');
        }
        if (!in_array($driver_class, ['car', 'keke'], true)) {
            json_response('error', 'Invalid driver type');
        }
    } else {
        $driver_class = '';
    }

    // Server-side validation
    if (strlen($fullname) < 3 || !preg_match("/^[a-zA-Z\s]+$/", $fullname)) {
        json_response('error', 'Full name must be at least 3 characters and contain only letters and spaces');
    }

    if ($password !== $confirm_password) {
        json_response('error', 'Password and confirm password do not match');
    }

    $passwordPolicyError = password_meets_policy($password);
    if ($passwordPolicyError !== null) {
        json_response('error', $passwordPolicyError);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_response('error', 'Please enter a valid email address');
    }

    // Store phone in international format from here on — see
    // includes/verification.php's normalize_phone_number() for the
    // accepted input shapes (080..., +234..., 234...).
    $phone = normalize_phone_number($phone_raw);
    if (!$phone) {
        json_response('error', 'Please enter a valid phone number.');
    }

    // Case-insensitive uniqueness regardless of the table's collation.
    $stmt = $pdo->prepare("SELECT id, email, phone FROM users WHERE LOWER(email) = LOWER(?) OR phone = ?");
    $stmt->execute([$email, $phone]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (strtolower($existing['email']) === strtolower($email)) {
            json_response('error', 'Email already registered');
        } else {
            json_response('error', 'Phone number already registered');
        }
    }

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    $public_user_id = generate_unique_user_id($pdo, $user_type);

    $stmt = $pdo->prepare("INSERT INTO users (fullname, phone, email, password, user_type, user_id) VALUES (?, ?, ?, ?, ?, ?)");
    if ($stmt->execute([$fullname, $phone, $email, $hashed_password, $user_type, $public_user_id])) {
        $user_id = $pdo->lastInsertId();
        $_SESSION['user_id'] = $user_id;
        $_SESSION['user_type'] = $user_type;
        $_SESSION['fullname'] = $fullname;
        $_SESSION['public_user_id'] = $public_user_id;

        // Persist the declared vehicle class.
        //
        // is_keke_driver records the class the driver signed up to operate, so
        // "Become a Keke Driver" is a real, readable account fact from the
        // moment the account exists. It is NOT a grant: check_driver_eligibility()
        // re-derives keke status from the live vehicles.vehicle_type on every
        // call (is_keke_vehicle_type), so this flag with no verified keke behind
        // it opens nothing. sync_keke_classification() then owns the value from
        // the moment a vehicle is added, and will correct it if the driver
        // actually registers a car.
        //
        // We do NOT seed a placeholder vehicle row: driver-api.php's
        // get_vehicle_info reports has_vehicle purely on row existence, and
        // driver.js swaps the "Register your vehicle" form for a vehicle card
        // when that is true — a stub would strand the driver on a card for a
        // vehicle they never entered.
        //
        // private_pickup_enabled is the driver's opt-in to the Private Pickup
        // lane. It also grants nothing on its own: eligibility still demands a
        // VERIFIED KEKE vehicle, online state, and today's availability before
        // any request reaches them.
        if ($user_type === 'driver' && normalize_vehicle_type($driver_class) === 'keke') {
            $pdo->prepare("UPDATE users SET is_keke_driver = 1, private_pickup_enabled = 1 WHERE id = ?")->execute([$user_id]);
        }

        // Account starts EMAIL_NOT_VERIFIED / PHONE_NOT_VERIFIED (column
        // defaults) — kick off a verification send for each channel that
        // is currently ENABLED so the Verify Account screen has something
        // to confirm arrived. A disabled channel is simply skipped here;
        // the verification columns are left at their defaults (never
        // auto-set) and NOTHING is generated/sent for it. The send_*
        // functions also self-guard, but checking explicitly keeps the
        // intent obvious and avoids a wasted call.
        $emailVerificationEnabled = is_email_verification_enabled($pdo);
        $phoneVerificationEnabled = is_phone_verification_enabled($pdo);
        if ($emailVerificationEnabled) {
            send_email_verification($pdo, $user_id);
        }
        if ($phoneVerificationEnabled) {
            send_phone_otp($pdo, $user_id);
        }

        // MOHTOH NETWORK — link the relationship if a valid, approved
        // Network Partner code was supplied. Never blocks or fails
        // registration itself; network_attribute_referral() returns an
        // ['error'=>...] array rather than throwing for any invalid case.
        if ($referral_code !== '') {
            $partner = get_network_partner_by_code($pdo, $referral_code);
            if ($partner && $partner['status'] === 'approved') {
                network_attribute_referral($pdo, (int)$partner['user_id'], (int)$user_id, $user_type);
            }
        }

        // Redirect target depends on which verification channels are live:
        //   * Both ON        -> verify-account.php shows BOTH cards.
        //   * Exactly one ON -> verify-account.php shows ONLY that card
        //                       (the disabled channel is never offered).
        //   * Both OFF       -> nothing to verify; skip the gate entirely
        //                       and land straight on the dashboard.
        json_response('success', 'Registration successful', [
            'redirect' => ($emailVerificationEnabled || $phoneVerificationEnabled)
                ? 'verify-account.php'
                : $user_type . '/dashboard.php'
        ]);
    } else {
        json_response('error', 'Registration failed');
    }
} elseif ($action === 'login') {
    $email = sanitize_input($_POST['email'] ?? '');
    $password = mt_scalar_string($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        json_response('error', 'Email and password are required');
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(?)");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response('error', 'Invalid email or password');
    }

    if ($user['is_suspended']) {
        json_response('error', 'Your account has been suspended. Please contact support.');
    }

    if (is_account_locked($user)) {
        $minutesLeft = max(1, (int)ceil((strtotime($user['lockout_until']) - time()) / 60));
        json_response('error', "Too many failed attempts. Please try again in {$minutesLeft} minute(s).");
    }

    if (!password_verify($password, $user['password'])) {
        $lockoutUntil = record_failed_login($pdo, $user['id']);
        if ($lockoutUntil) {
            $minutesLeft = (int)ceil((strtotime($lockoutUntil) - time()) / 60);
            json_response('error', "Too many failed attempts. Your account is locked for {$minutesLeft} minute(s).");
        }
        json_response('error', 'Invalid email or password');
    }

    record_successful_login($pdo, $user['id']);
    session_regenerate_id(true); // fresh session ID on every successful login — mitigates session fixation

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_type'] = $user['user_type'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['public_user_id'] = $user['user_id'];

    // Registration Policy (Admin -> System Settings -> Service Integration
    // Center -> Registration Policy) — DB-configured, falling back to the
    // includes/config.php constants for installs that never touch the UI.
    $requireEmailToLogin = get_integration_setting_bool($pdo, 'integration_require_email_to_login', defined('REQUIRE_EMAIL_VERIFIED_TO_LOGIN') ? REQUIRE_EMAIL_VERIFIED_TO_LOGIN : true);
    $requirePhoneToLogin = get_integration_setting_bool($pdo, 'integration_require_phone_to_login', defined('REQUIRE_PHONE_VERIFIED_TO_LOGIN') ? REQUIRE_PHONE_VERIFIED_TO_LOGIN : true);
    // A verification requirement only bites when that channel is ENABLED.
    // If email OTP is switched off, its "verified" column is irrelevant to
    // login (the requirement is ignored) — likewise for phone. This never
    // reads or writes the *_verified columns beyond the gate check itself.
    $needsVerification = ($requireEmailToLogin && is_email_verification_enabled($pdo) && !$user['email_verified'])
        || ($requirePhoneToLogin && is_phone_verification_enabled($pdo) && !$user['phone_verified']);

    json_response('success', 'Login successful', [
        'redirect' => $needsVerification ? 'verify-account.php' : $user['user_type'] . '/dashboard.php'
    ]);
} elseif ($action === 'logout') {
    session_destroy();
    json_response('success', 'Logged out successfully', ['redirect' => '../index.php']);
} else {
    json_response('error', 'Invalid action');
}
?>
