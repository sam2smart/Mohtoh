<?php
// api/profile.php
// Canonical profile bundle endpoint for the Profile Module (rider +
// driver mobile screens). Replaces the empty stub that rider/profile.php
// and rider/settings.php were already calling into.
//
// Sensitive fields (fullname, phone — per the Profile Module security
// spec) are never updated silently: changing either requires the
// caller's current password in the same request, same re-auth pattern
// already used for password changes. Vehicle info/documents have their
// own existing admin-reviewed queue (vehicle_edit_requests, see
// api/driver-api.php) and are untouched here.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/verification.php'; // password_meets_policy(), log_verification_event()

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'earnings') {
    if ($_SESSION['user_type'] !== 'driver') {
        json_response('error', 'Unauthorized');
    }

    $stmt = $pdo->prepare("SELECT SUM(driver_earning_amount) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_CONFIRMED' AND YEARWEEK(confirmed_at, 1) = YEARWEEK(CURDATE(), 1)");
    $stmt->execute([$user_id]);
    $week = round((float)$stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare("SELECT SUM(driver_earning_amount) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_CONFIRMED' AND YEAR(confirmed_at) = YEAR(CURDATE()) AND MONTH(confirmed_at) = MONTH(CURDATE())");
    $stmt->execute([$user_id]);
    $month = round((float)$stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare("SELECT SUM(driver_earning_amount) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_CONFIRMED'");
    $stmt->execute([$user_id]);
    $total = round((float)$stmt->fetchColumn(), 2);

    $stmt = $pdo->prepare("
        SELECT p.driver_earning_amount, p.confirmed_at, rs.destination, rs.status
        FROM payments p
        JOIN ride_shares rs ON rs.id = p.ride_share_id
        WHERE p.driver_id = ? AND p.status = 'PAYMENT_CONFIRMED'
        ORDER BY p.confirmed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $recent = $stmt->fetchAll();

    json_response('success', 'Earnings summary fetched', [
        'week' => $week,
        'month' => $month,
        'total' => $total,
        'recent_trips' => $recent
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ratings') {
    if ($_SESSION['user_type'] !== 'driver') {
        json_response('error', 'Unauthorized');
    }

    $stmt = $pdo->prepare("SELECT rating FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $average = $stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT rsr.rating, rsr.comment, rsr.created_at, u.fullname AS rider_name
        FROM ride_share_ratings rsr
        JOIN users u ON u.id = rsr.rider_id
        WHERE rsr.driver_id = ?
        ORDER BY rsr.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([$user_id]);
    $ratings = $stmt->fetchAll();

    json_response('success', 'Ratings fetched', [
        'average' => $average !== null ? round((float)$average, 2) : null,
        'count' => count($ratings),
        'ratings' => $ratings
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->prepare("SELECT user_id, fullname, email, phone, profile_image, user_type, rating, completed_rides, is_verified, is_suspended, wallet_balance, payment_preference, notify_on_rating, notify_on_booking, notify_ride_start, notify_ride_cancel, notify_wallet, notify_payment_alerts, notify_promotions, notify_system, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        json_response('error', 'User not found');
    }

    $user['wallet_balance'] = (float)$user['wallet_balance'];
    $user['rating'] = $user['rating'] !== null ? round((float)$user['rating'], 2) : null;

    if ($user['user_type'] === 'driver') {
        $stmt = $pdo->prepare("SELECT public_id, make, model, plate_number, seats, status FROM vehicles WHERE driver_id = ?");
        $stmt->execute([$user_id]);
        $user['vehicle'] = $stmt->fetch() ?: null;

        $stmt = $pdo->prepare("SELECT SUM(driver_earning_amount) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_CONFIRMED'");
        $stmt->execute([$user_id]);
        $user['lifetime_earnings'] = round((float)$stmt->fetchColumn(), 2);
    }

    json_response('success', 'Profile data fetched', $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $isJson = is_array($input);
    if (!$isJson) {
        $input = $_POST;
    }

    $update_fields = [];
    $params = [];

    // Sensitive fields: fullname and/or phone. Re-authentication with the
    // current password is required before either is applied.
    $wantsFullname = array_key_exists('fullname', $input) && trim((string)$input['fullname']) !== '';
    $wantsPhone = array_key_exists('phone', $input) && trim((string)$input['phone']) !== '';

    if ($wantsFullname || $wantsPhone) {
        $confirmPassword = $input['current_password'] ?? '';
        if ($confirmPassword === '') {
            json_response('error', 'Please confirm your current password to change your name or phone number.');
        }

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $existingUser = $stmt->fetch();
        if (!$existingUser || !password_verify($confirmPassword, $existingUser['password'])) {
            json_response('error', 'Current password is incorrect.');
        }

        if ($wantsFullname) {
            $fullname = sanitize_input($input['fullname']);
            if (strlen($fullname) < 3 || !preg_match("/^[a-zA-Z\s]+$/", $fullname)) {
                json_response('error', 'Full name must be at least 3 characters and contain only letters and spaces.');
            }
            $update_fields[] = "fullname = ?";
            $params[] = $fullname;
            $_SESSION['fullname'] = $fullname;
        }

        if ($wantsPhone) {
            $phone = sanitize_input($input['phone']);
            if (!preg_match("/^\d{7,15}$/", $phone)) {
                json_response('error', 'Phone number must contain between 7 and 15 digits.');
            }
            $stmt = $pdo->prepare("SELECT id FROM users WHERE phone = ? AND id != ?");
            $stmt->execute([$phone, $user_id]);
            if ($stmt->fetch()) {
                json_response('error', 'This phone number is already in use by another account.');
            }
            $update_fields[] = "phone = ?";
            $params[] = $phone;
        }
    }

    // Email — instant, still checked for uniqueness/format.
    if (array_key_exists('email', $input) && trim((string)$input['email']) !== '') {
        $email = sanitize_input($input['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response('error', 'Invalid email format.');
        }
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user_id]);
        if ($stmt->fetch()) {
            json_response('error', 'This email is already in use by another account.');
        }
        $update_fields[] = "email = ?";
        $params[] = $email;
    }

    // Password change — unrelated current_password/new_password pair.
    if (!empty($input['new_password'])) {
        $current_password = mt_scalar_string($input['current_password'] ?? '');
        $new_password = mt_scalar_string($input['new_password']);
        if (empty($current_password)) {
            json_response('error', 'To change your password, enter your current password too.');
        }
        $passwordPolicyError = password_meets_policy($new_password);
        if ($passwordPolicyError !== null) {
            json_response('error', $passwordPolicyError);
        }
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $existingUser = $stmt->fetch();
        if (!$existingUser || !password_verify($current_password, $existingUser['password'])) {
            json_response('error', 'Current password is incorrect.');
        }
        $update_fields[] = "password = ?";
        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        // Authentication & Recovery module: a manual password change
        // invalidates any outstanding forgot-password token/OTP so an
        // old, still-unexpired reset link can't be used afterward.
        $update_fields[] = "reset_token = NULL, reset_expires = NULL, reset_used = 1";
        $passwordChanged = true;
    }

    // Profile photo — instant, same upload contract as before.
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        $allowed = ['jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            json_response('error', 'Only JPG, JPEG, and PNG images are allowed.');
        }
        $filename = 'profile_' . $user_id . '_' . uniqid() . '.' . $ext;
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $upload_dir . $filename)) {
            $update_fields[] = "profile_image = ?";
            $params[] = $filename;
        } else {
            json_response('error', 'Failed to upload profile image.');
        }
    }

    // Notification settings — instant, not sensitive.
    foreach (['notify_on_rating', 'notify_on_booking', 'notify_ride_start', 'notify_ride_cancel', 'notify_wallet', 'notify_payment_alerts', 'notify_promotions', 'notify_system'] as $flag) {
        if (array_key_exists($flag, $input)) {
            $update_fields[] = "$flag = ?";
            $params[] = (int)(bool)$input[$flag];
        }
    }

    if (empty($update_fields)) {
        json_response('error', 'No data provided to update.');
    }

    $params[] = $user_id;
    $stmt = $pdo->prepare("UPDATE users SET " . implode(', ', $update_fields) . " WHERE id = ?");

    if ($stmt->execute($params)) {
        if (!empty($passwordChanged)) {
            log_verification_event($pdo, $user_id, 'password_reset', 'verified', ['method' => 'manual_change']);
            create_notification($pdo, $user_id, [
                'title' => 'Password changed',
                'message' => 'Your MohToh password was just changed. If this wasn\'t you, contact support immediately.',
                'notification_type' => 'security', 'priority' => 'critical',
                'action' => 'open_security', 'link' => 'profile.php',
            ]);
        }
        json_response('success', 'Profile updated successfully.');
    } else {
        json_response('error', 'Failed to update profile.');
    }
}

json_response('error', 'Invalid request method');
