<?php
// api/admin-api.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php';
require_once '../includes/verification.php';
require_once '../includes/audit.php';
require_once '../includes/referral.php';
// Canonical vehicle-type and verification-document vocabulary, shared with
// the driver side so admin review labels a keke as a keke.
require_once '../includes/eligibility.php';

header('Content-Type: application/json');

function table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

function ensure_mohtoh_admin_columns($pdo) {
    if (!column_exists($pdo, 'users', 'is_verified')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_online");
    }
    if (!column_exists($pdo, 'users', 'is_suspended')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0 AFTER is_verified");
    }
}

function fetch_user_review_profile($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, user_id, fullname, phone, email, user_type, rating, completed_rides, is_online, created_at FROM users WHERE id = ? AND user_type IN ('driver', 'rider')");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new Exception('User profile not found');
    }

    $isDriver = $user['user_type'] === 'driver';
    $reviewTable = $isDriver ? 'driver_ratings' : 'passenger_ratings';
    $reviewedUserColumn = $isDriver ? 'driver_id' : 'rider_id';
    $reviewerColumn = $isDriver ? 'rider_id' : 'driver_id';
    $reviewerLabel = $isDriver ? 'passenger' : 'driver';
    $reviewerNameAlias = $isDriver ? 'passenger_name' : 'driver_name';

    $reviews = [];
    $raters = [];
    $reviewStats = [
        'total_reviews' => 0,
        'average_rating' => $user['rating'] !== null ? round((float)$user['rating'], 2) : 0,
        'positive_reviews' => 0,
        'negative_reviews' => 0,
        'neutral_reviews' => 0,
        'complaints' => 0
    ];

    if (table_exists($pdo, $reviewTable)) {
        $stmt = $pdo->prepare("
            SELECT rr.id, rr.public_id, rr.request_id, rr.rating, rr.comment, rr.created_at,
                   rr.$reviewerColumn AS reviewer_id,
                   reviewer.fullname AS $reviewerNameAlias,
                   reviewer.email AS reviewer_email,
                   reviewer.phone AS reviewer_phone,
                   ride.destination,
                   ride.status AS ride_status,
                   ride.created_at AS ride_created_at
            FROM $reviewTable rr
            JOIN users reviewer ON rr.$reviewerColumn = reviewer.id
            LEFT JOIN ride_requests ride ON rr.request_id = ride.id
            WHERE rr.$reviewedUserColumn = ?
            ORDER BY rr.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $reviews = $stmt->fetchAll();

        foreach ($reviews as $review) {
            $rating = (int)$review['rating'];
            $reviewStats['total_reviews']++;
            if ($rating >= 4) {
                $reviewStats['positive_reviews']++;
            } elseif ($rating <= 2) {
                $reviewStats['negative_reviews']++;
                if (trim((string)($review['comment'] ?? '')) !== '') {
                    $reviewStats['complaints']++;
                }
            } else {
                $reviewStats['neutral_reviews']++;
            }
        }

        if ($reviewStats['total_reviews'] > 0) {
            $stmt = $pdo->prepare("SELECT AVG(rating) FROM $reviewTable WHERE $reviewedUserColumn = ?");
            $stmt->execute([$user_id]);
            $reviewStats['average_rating'] = round((float)$stmt->fetchColumn(), 2);
        }

        $stmt = $pdo->prepare("
            SELECT reviewer.id, reviewer.fullname, reviewer.email, reviewer.phone,
                   COUNT(*) AS review_count,
                   ROUND(AVG(rr.rating), 2) AS average_rating_given,
                   MAX(rr.created_at) AS last_reviewed_at
            FROM $reviewTable rr
            JOIN users reviewer ON rr.$reviewerColumn = reviewer.id
            WHERE rr.$reviewedUserColumn = ?
            GROUP BY reviewer.id, reviewer.fullname, reviewer.email, reviewer.phone
            ORDER BY last_reviewed_at DESC
        ");
        $stmt->execute([$user_id]);
        $raters = $stmt->fetchAll();
    }

    $tripUserColumn = $isDriver ? 'driver_id' : 'rider_id';
    $counterpartColumn = $isDriver ? 'rider_id' : 'driver_id';
    $counterpartNameAlias = $isDriver ? 'passenger_name' : 'driver_name';

    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_trips,
            SUM(status = 'completed') AS completed_trips,
            SUM(status IN ('pending', 'accepted', 'in_progress')) AS active_trips,
            SUM(status = 'cancelled') AS cancelled_trips
        FROM ride_requests
        WHERE $tripUserColumn = ?
    ");
    $stmt->execute([$user_id]);
    $tripSummary = $stmt->fetch();
    foreach ($tripSummary as $key => $value) {
        $tripSummary[$key] = (int)$value;
    }

    $stmt = $pdo->prepare("
        SELECT ride.id, ride.destination, ride.price, ride.platform_fee, ride.status, ride.created_at,
               counterpart.fullname AS $counterpartNameAlias
        FROM ride_requests ride
        LEFT JOIN users counterpart ON ride.$counterpartColumn = counterpart.id
        WHERE ride.$tripUserColumn = ?
        ORDER BY ride.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$user_id]);
    $tripHistory = $stmt->fetchAll();

    $complaints = array_values(array_filter($reviews, function ($review) {
        return (int)$review['rating'] <= 2 && trim((string)($review['comment'] ?? '')) !== '';
    }));

    $adminActions = [];
    if (table_exists($pdo, 'admin_user_actions')) {
        $stmt = $pdo->prepare("
            SELECT aua.id, aua.public_id, aua.action, aua.reason, aua.created_at, admin.fullname AS admin_name
            FROM admin_user_actions aua
            LEFT JOIN users admin ON aua.admin_id = admin.id
            WHERE aua.user_id = ?
            ORDER BY aua.created_at DESC
        ");
        $stmt->execute([$user_id]);
        $adminActions = $stmt->fetchAll();
    }

    // Commission Reservation System: wallet total/available/reserved,
    // active reservations, and commission collected/pending — admin-only
    // visibility into the same numbers the driver's own wallet UI shows.
    $walletSummary = null;
    if ($isDriver) {
        $stmt = $pdo->prepare("SELECT wallet_balance FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $totalBalance = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT public_id, ride_share_id, reserved_amount, used_amount, released_amount, status, created_at
            FROM ride_commission_reservations
            WHERE driver_id = ? AND status = 'active'
            ORDER BY created_at DESC
        ");
        $stmt->execute([$user_id]);
        $activeReservations = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_CONFIRMED'");
        $stmt->execute([$user_id]);
        $commissionCollected = round((float)$stmt->fetchColumn(), 2);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(commission_amount), 0) FROM payments WHERE driver_id = ? AND status = 'PAYMENT_PENDING'");
        $stmt->execute([$user_id]);
        $commissionPending = round((float)$stmt->fetchColumn(), 2);

        $walletSummary = [
            'total_balance' => $totalBalance,
            'available_balance' => get_available_wallet_balance($pdo, $user_id),
            'reserved_balance' => get_reserved_wallet_balance($pdo, $user_id),
            'active_reservations' => $activeReservations,
            'commission_collected' => $commissionCollected,
            'commission_pending' => $commissionPending,
        ];
    }

    return [
        'profile' => $user,
        'reviewer_label' => $reviewerLabel,
        'stats' => $reviewStats,
        'reviews' => $reviews,
        'raters' => $raters,
        'complaints' => $complaints,
        'trip_summary' => $tripSummary,
        'trip_history' => $tripHistory,
        'admin_actions' => $adminActions,
        'wallet_summary' => $walletSummary
    ];
}

// Ensure user is logged in and is an admin
if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized access');
}

ensure_mohtoh_admin_columns($pdo);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handling POST actions (Admin settings and actions)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $post_action = $input['action'] ?? '';

    if ($post_action === 'update_settings') {
        // Retired in favor of the consolidated, super-admin-gated,
        // audit-logged api/pricing-admin.php (settings.commission_percentage).
        json_response('error', 'This action has moved to api/pricing-admin.php.');

    } elseif ($post_action === 'delete_user') {
        $user_id = (int)($input['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response('error', 'Invalid user ID');
        }

        // Prevent admin from deleting themselves
        if ($user_id === (int)$_SESSION['user_id']) {
            json_response('error', 'You cannot delete your own admin account');
        }

        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
        if ($stmt->execute([$user_id])) {
            json_response('success', 'User deleted successfully');
        } else {
            json_response('error', 'Failed to delete user');
        }

    } elseif ($post_action === 'toggle_user_status') {
        // Allow admin to force a user offline (kick them) or similar administrative actions
        $user_id = (int)($input['user_id'] ?? 0);
        $is_online = (int)($input['is_online'] ?? 0);
        
        $stmt = $pdo->prepare("UPDATE users SET is_online = ? WHERE id = ?");
        if ($stmt->execute([$is_online, $user_id])) {
            json_response('success', 'User online status updated');
        } else {
            json_response('error', 'Failed to update status');
        }
    } elseif ($post_action === 'approve_driver') {
        $user_id = (int)($input['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response('error', 'Invalid user ID');
        }
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1, is_online = 1 WHERE id = ? AND user_type = 'driver'");
        if ($stmt->execute([$user_id])) {
            json_response('success', 'Driver approved successfully');
        } else {
            json_response('error', 'Failed to approve driver');
        }
    } elseif ($post_action === 'verify_driver') {
        $user_id = (int)($input['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response('error', 'Invalid user ID');
        }
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ? AND user_type = 'driver'");
        if ($stmt->execute([$user_id])) {
            json_response('success', 'Driver verification updated');
        } else {
            json_response('error', 'Failed to verify driver');
        }
    } elseif ($post_action === 'suspend_user' || $post_action === 'unsuspend_user') {
        $user_id = (int)($input['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response('error', 'Invalid user ID');
        }
        $suspended = $post_action === 'suspend_user' ? 1 : 0;
        $stmt = $pdo->prepare("UPDATE users SET is_suspended = ?, is_online = CASE WHEN ? = 1 THEN 0 ELSE is_online END WHERE id = ? AND user_type IN ('driver', 'rider')");
        if ($stmt->execute([$suspended, $suspended, $user_id])) {
            $action_public_id = generate_unique_public_id($pdo, 'admin_user_actions', 'public_id', 'ACT');
            $stmt = $pdo->prepare("INSERT INTO admin_user_actions (public_id, user_id, admin_id, action, reason) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$action_public_id, $user_id, $_SESSION['user_id'], $suspended ? 'suspension' : 'unsuspension', $input['reason'] ?? 'Administrative action']);
            json_response('success', $suspended ? 'User suspended successfully' : 'User unsuspended successfully');
        } else {
            json_response('error', 'Failed to update user access');
        }
    } elseif ($post_action === 'set_driver_tier') {
        // Intelligent Trip Engine Phase 3 — Standard drivers keep the
        // existing max_active_rides_per_driver cap (api/rideshare.php's
        // create action); Premium drivers are exempt from it entirely.
        $user_id = (int)($input['user_id'] ?? 0);
        $tier = $input['driver_tier'] ?? '';
        if ($user_id <= 0 || !in_array($tier, ['standard', 'premium'], true)) {
            json_response('error', 'A valid user ID and driver tier (standard/premium) are required.');
        }
        $stmt = $pdo->prepare("UPDATE users SET driver_tier = ? WHERE id = ? AND user_type = 'driver'");
        if ($stmt->execute([$tier, $user_id])) {
            json_response('success', "Driver tier set to {$tier}.");
        } else {
            json_response('error', 'Failed to update driver tier');
        }

    } elseif ($post_action === 'resend_verification') {
        // Authentication & Recovery module — admin-triggered resend, for
        // when a user is stuck (lost the email, changed phones, etc.)
        // and support needs to push a fresh link/OTP on their behalf.
        // Reuses the exact same send functions the user's own "Resend"
        // buttons on verify-account.php call.
        $user_id = (int)($input['user_id'] ?? 0);
        $channel = $input['channel'] ?? 'both';
        if ($user_id <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        $messages = [];
        if ($channel === 'email' || $channel === 'both') {
            $result = send_email_verification($pdo, $user_id);
            $messages[] = 'Email: ' . $result['message'];
        }
        if ($channel === 'phone' || $channel === 'both') {
            $result = send_phone_otp($pdo, $user_id);
            $messages[] = 'Phone: ' . $result['message'];
        }
        json_response('success', implode(' ', $messages));

    } elseif ($post_action === 'unlock_login_lockout') {
        // Clears the automatic brute-force lockout (failed_login_attempts/
        // lockout_until) so the user can try again immediately instead of
        // waiting out the timer. Distinct from is_suspended's
        // suspend_user/unsuspend_user above, which is a deliberate,
        // permanent-until-admin-acts restriction rather than a temporary
        // self-clearing one.
        $user_id = (int)($input['user_id'] ?? 0);
        if ($user_id <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        admin_unlock_account($pdo, $user_id);
        json_response('success', 'Login lockout cleared.');
    } elseif ($post_action === 'approve_vehicle' || $post_action === 'reject_vehicle') {
        $vehicle_id = (int)($input['vehicle_id'] ?? 0);
        $edit_request_id = isset($input['edit_request_id']) ? (int)$input['edit_request_id'] : 0;
        if ($vehicle_id <= 0) {
            json_response('error', 'Invalid vehicle ID');
        }

        $remarks = sanitize_input($input['remarks'] ?? '');
        $isApprove = $post_action === 'approve_vehicle';

        try {
            $pdo->beginTransaction();

            if ($isApprove) {
                $ratingKeys = [
                    'exterior_condition',
                    'interior_condition',
                    'ac_condition',
                    'comfort_rating',
                    'cleanliness_rating',
                    'appearance_rating',
                    'safety_rating'
                ];

                $ratings = [];
                foreach ($ratingKeys as $key) {
                    $value = max(1, min(5, (int)($input[$key] ?? 3)));
                    $ratings[$key] = $value;
                }

                $overallGrade = (int)round(array_sum($ratings) / count($ratings));
                $serviceCategory = $overallGrade >= 4 ? 'Premium' : ($overallGrade === 3 ? 'Standard' : ($overallGrade === 2 ? 'Comfort' : 'Basic'));

                if ($edit_request_id > 0) {
                    $stmt = $pdo->prepare("SELECT requested_changes FROM vehicle_edit_requests WHERE id = ? AND vehicle_id = ? AND status = 'pending'");
                    $stmt->execute([$edit_request_id, $vehicle_id]);
                    $editRequest = $stmt->fetch();
                    if (!$editRequest) {
                        throw new Exception('Invalid or already processed edit request');
                    }

                    $requestedChanges = json_decode($editRequest['requested_changes'], true);
                    if (!is_array($requestedChanges)) {
                        $requestedChanges = [];
                    }

                    $updateFields = [
                        'status' => 'approved',
                        'admin_rating' => $overallGrade,
                        'overall_grade' => $overallGrade,
                        'review_remarks' => $remarks,
                        'last_reviewed_at' => date('Y-m-d H:i:s'),
                        'service_category' => $serviceCategory,
                        'edit_request_status' => 'approved'
                    ];

                    $updateSql = "UPDATE vehicles SET status = :status, admin_rating = :admin_rating, overall_grade = :overall_grade, review_remarks = :review_remarks, last_reviewed_at = :last_reviewed_at, service_category = :service_category, edit_request_status = :edit_request_status";
                    foreach ($requestedChanges as $field => $value) {
                        if (in_array($field, ['make','model','year','color','plate_number','ac_available','cleanliness','comfort_level','seats','luggage_capacity'], true)) {
                            $updateSql .= ", {$field} = :{$field}";
                            $updateFields[$field] = $value;
                        }
                    }
                    foreach ($ratings as $key => $value) {
                        $updateSql .= ", {$key} = :{$key}";
                        $updateFields[$key] = $value;
                    }
                    $updateSql .= " WHERE id = :vehicle_id";
                    $updateFields['vehicle_id'] = $vehicle_id;

                    $stmt = $pdo->prepare($updateSql);
                    $stmt->execute($updateFields);

                    $stmt = $pdo->prepare("UPDATE vehicle_edit_requests SET status = 'approved', admin_remarks = ?, reviewed_at = ? WHERE id = ?");
                    $stmt->execute([$remarks, date('Y-m-d H:i:s'), $edit_request_id]);
                    $action = 'edit_approved';
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE vehicles SET status = ?, verification_status = ?, admin_rating = ?, overall_grade = ?, review_remarks = ?, last_reviewed_at = ?, service_category = ?, exterior_condition = ?, interior_condition = ?, ac_condition = ?, comfort_rating = ?, cleanliness_rating = ?, appearance_rating = ?, safety_rating = ?, edit_request_status = 'approved' WHERE id = ?"
                    );
                    $stmt->execute([
                        'approved',
                        'verified',
                        $overallGrade,
                        $overallGrade,
                        $remarks,
                        date('Y-m-d H:i:s'),
                        $serviceCategory,
                        $ratings['exterior_condition'],
                        $ratings['interior_condition'],
                        $ratings['ac_condition'],
                        $ratings['comfort_rating'],
                        $ratings['cleanliness_rating'],
                        $ratings['appearance_rating'],
                        $ratings['safety_rating'],
                        $vehicle_id
                    ]);
                    $action = 'approved';
                }
            } else {
                if ($edit_request_id > 0) {
                    $stmt = $pdo->prepare("UPDATE vehicle_edit_requests SET status = 'rejected', admin_remarks = ?, reviewed_at = ? WHERE id = ?");
                    $stmt->execute([$remarks, date('Y-m-d H:i:s'), $edit_request_id]);
                    $action = 'edit_rejected';
                } else {
                    $stmt = $pdo->prepare("UPDATE vehicles SET status = 'rejected', verification_status = 'rejected', review_remarks = ?, last_reviewed_at = ? WHERE id = ?");
                    $stmt->execute([$remarks, date('Y-m-d H:i:s'), $vehicle_id]);
                    $action = 'rejected';
                }
            }

            $adminId = (int)$_SESSION['user_id'];
            $review_log_public_id = generate_unique_public_id($pdo, 'vehicle_review_logs', 'public_id', 'RVL');
            $stmt = $pdo->prepare("INSERT INTO vehicle_review_logs (public_id, vehicle_id, admin_id, action, remarks) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$review_log_public_id, $vehicle_id, $adminId, $action, $remarks]);

            // Carry the decision down to the documents that were reviewed
            // to reach it. Approving a vehicle IS approving the documents
            // submitted for it, but vehicle_verifications.status was never
            // written, so every document stayed 'pending' forever and the
            // driver's sheet reported "Pending" under a verified vehicle.
            // Only documents still awaiting a decision are moved, so an
            // already-rejected document is not silently approved, and the
            // rows themselves are never deleted.
            if ($action === 'approved' || $action === 'rejected') {
                $stmt = $pdo->prepare(
                    "UPDATE vehicle_verifications
                        SET status = ?, rejection_reason = ?, reviewed_by = ?, reviewed_at = ?
                      WHERE vehicle_id = ? AND status = 'pending' AND archived_at IS NULL"
                );
                $stmt->execute([
                    $action === 'approved' ? 'approved' : 'rejected',
                    $action === 'rejected' ? ($remarks ?: null) : null,
                    $adminId,
                    date('Y-m-d H:i:s'),
                    $vehicle_id
                ]);
            }

            $stmt = $pdo->prepare("SELECT driver_id FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            $vehicleOwner = $stmt->fetch();
            $targetUserId = $vehicleOwner && isset($vehicleOwner['driver_id']) ? (int)$vehicleOwner['driver_id'] : 0;

            // Keep the driver account's is_verified flag in lockstep with
            // vehicle approval — this is the single fix for "driver still
            // shows unverified after their vehicle is approved" (the two
            // used to be maintained by entirely separate admin actions).
            // 'edit_rejected' leaves the vehicle's existing status
            // untouched, so it doesn't change verification either.
            if ($targetUserId > 0) {
                if ($action === 'approved' || $action === 'edit_approved') {
                    $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?")->execute([$targetUserId]);
                    // MohToh Community Rewards: this is the single source
                    // of truth for "driver verified" per the comment
                    // above, so it's the one place to fire the referral
                    // qualification event too — no-ops if this driver
                    // wasn't referred.
                    track_referral_event($pdo, $targetUserId, 'DRIVER_VERIFIED');
                } elseif ($action === 'rejected') {
                    $pdo->prepare("UPDATE users SET is_verified = 0 WHERE id = ?")->execute([$targetUserId]);
                }
            }

            if (table_exists($pdo, 'admin_user_actions') && $targetUserId > 0) {
                $vehicle_action_public_id = generate_unique_public_id($pdo, 'admin_user_actions', 'public_id', 'ACT');
                $stmt = $pdo->prepare("INSERT INTO admin_user_actions (public_id, user_id, admin_id, action, reason) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$vehicle_action_public_id, $targetUserId, $adminId, $action, $remarks]);
            }

            // Part 5/6: standardized approval lifecycle + generic audit
            // trail, on top of the vehicle-specific vehicle_review_logs
            // and user-specific admin_user_actions rows already written
            // above (kept, not replaced — those serve their own existing
            // views). Two entities change here: the vehicle's own review
            // status, and (when it moves) the driver account's
            // verification status.
            $oldVehicleStatus = $isApprove ? 'pending' : 'pending'; // this action only ever fires from a pending/edit-pending vehicle
            log_admin_action($pdo, $adminId, $isApprove ? 'approve' : 'reject', 'vehicle', $vehicle_id, $oldVehicleStatus, $isApprove ? 'approved' : 'rejected', $remarks ?: null);
            if ($targetUserId > 0 && ($action === 'approved' || $action === 'rejected')) {
                log_admin_action($pdo, $adminId, $isApprove ? 'approve' : 'reject', 'driver_verification', $targetUserId, null, $isApprove ? 'verified' : 'unverified', $remarks ?: null);
            }

            if ($targetUserId > 0 && ($action === 'approved' || $action === 'edit_approved')) {
                create_notification($pdo, $targetUserId, [
                    'title' => 'Vehicle verified',
                    'message' => 'Your vehicle has been approved. You can now create rides on MohToh.',
                    'notification_type' => 'verification', 'priority' => 'high', 'force' => true,
                    'action' => 'open_vehicle', 'action_id' => $vehicle_id, 'related_table' => 'vehicles', 'link' => 'dashboard.php',
                ]);
            } elseif ($targetUserId > 0 && ($action === 'rejected' || $action === 'edit_rejected')) {
                create_notification($pdo, $targetUserId, [
                    'title' => 'Vehicle verification rejected',
                    'message' => $remarks !== '' ? "Your vehicle verification was rejected. Reason: {$remarks}" : 'Your vehicle verification was rejected. Please review your submission and try again.',
                    'notification_type' => 'verification', 'priority' => 'high', 'force' => true,
                    'action' => 'open_vehicle', 'action_id' => $vehicle_id, 'related_table' => 'vehicles', 'link' => 'dashboard.php',
                    'meta' => ['reason' => $remarks],
                ]);
            }

            $pdo->commit();
            json_response('success', 'Vehicle review status updated successfully', ['vehicle_id' => $vehicle_id, 'status' => $isApprove ? 'approved' : 'rejected', 'edit_request_id' => $edit_request_id]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', 'Failed to update vehicle review: ' . $e->getMessage());
        }
    } elseif ($post_action === 'suspend_vehicle') {
        $vehicle_id = (int)($input['vehicle_id'] ?? 0);
        $remarks = sanitize_input($input['remarks'] ?? 'Suspended by admin');
        if ($vehicle_id <= 0) {
            json_response('error', 'Invalid vehicle ID');
        }
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE vehicles SET status = 'rejected', verification_status = 'suspended', review_remarks = ?, last_reviewed_at = ? WHERE id = ?");
            $stmt->execute([$remarks, date('Y-m-d H:i:s'), $vehicle_id]);
            
            $stmt = $pdo->prepare("UPDATE users SET is_verified = 0 WHERE id = (SELECT driver_id FROM vehicles WHERE id = ?)");
            $stmt->execute([$vehicle_id]);

            $adminId = (int)$_SESSION['user_id'];
            $review_log_public_id = generate_unique_public_id($pdo, 'vehicle_review_logs', 'public_id', 'RVL');
            $stmt = $pdo->prepare("INSERT INTO vehicle_review_logs (public_id, vehicle_id, admin_id, action, remarks) VALUES (?, ?, ?, 'rejected', ?)");
            $stmt->execute([$review_log_public_id, $vehicle_id, $adminId, $remarks]);

            $pdo->commit();
            json_response('success', 'Vehicle suspended successfully');
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response('error', 'Suspension failed: ' . $e->getMessage());
        }
    } elseif ($post_action === 'delete_vehicle') {
        // Part 5: Delete — confirmation is enforced client-side.
        $vehicle_id = (int)($input['vehicle_id'] ?? 0);
        if ($vehicle_id <= 0) {
            json_response('error', 'Invalid vehicle ID');
        }
        $stmt = $pdo->prepare("SELECT status FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicle_id]);
        $existingStatus = $stmt->fetchColumn();
        if ($existingStatus === false) {
            json_response('error', 'Vehicle not found');
        }
        log_admin_action($pdo, (int)$_SESSION['user_id'], 'delete', 'vehicle', $vehicle_id, $existingStatus, null, $input['reason'] ?? null);
        $pdo->prepare("DELETE FROM vehicles WHERE id = ?")->execute([$vehicle_id]);
        json_response('success', 'Vehicle deleted successfully');
    } elseif ($post_action === 'update_ride_share') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Invalid ride ID');
        }
        $fields = [];
        $params = [];
        $allowed = ['destination','departure_date','departure_time','price_per_passenger','description','status','total_seats'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $input)) {
                $fields[] = "$field = ?";
                $params[] = $input[$field];
            }
        }
        if (empty($fields)) {
            json_response('error', 'No fields provided');
        }

        // Ride Queue fix: total_seats could be changed here without
        // available_seats ever following it — an admin "increasing seat
        // capacity" wouldn't actually free any seats to notify the ride
        // queue about. available_seats now moves by the same delta,
        // clamped at 0, same math the booking/cancel flows already use
        // elsewhere in this codebase.
        $seatDelta = 0;
        if (array_key_exists('total_seats', $input)) {
            $stmt = $pdo->prepare("SELECT total_seats, available_seats FROM ride_shares WHERE id = ?");
            $stmt->execute([$ride_id]);
            $current = $stmt->fetch();
            if (!$current) {
                json_response('error', 'Ride not found');
            }
            $seatDelta = (int)$input['total_seats'] - (int)$current['total_seats'];
            if ($seatDelta !== 0) {
                $newAvailable = max(0, (int)$current['available_seats'] + $seatDelta);
                $fields[] = "available_seats = ?";
                $params[] = $newAvailable;
                // Respect an explicit status the admin also sent in this
                // same request; only auto-recompute when they didn't.
                if (!array_key_exists('status', $input)) {
                    $fields[] = "status = ?";
                    $params[] = $newAvailable <= 0 ? 'full' : 'active';
                }
            }
        }

        $params[] = $ride_id;
        $stmt = $pdo->prepare("UPDATE ride_shares SET " . implode(', ', $fields) . " WHERE id = ?");
        if ($stmt->execute($params)) {
            if ($seatDelta > 0) {
                require_once '../includes/ride_queue.php';
                process_queue($pdo, $ride_id);
            }
            json_response('success', 'Ride updated successfully');
        } else {
            json_response('error', 'Failed to update ride');
        }
    } elseif ($post_action === 'cancel_ride_share') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Invalid ride ID');
        }
        $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'cancelled' WHERE id = ?");
        if ($stmt->execute([$ride_id])) {
            // Commission Reservation System: releases whatever this ride's
            // reservation hadn't already used back to the driver's
            // available balance — same helper the driver-side cancel
            // action uses, so admin cancellation can't leave a reservation
            // stuck open forever.
            release_ride_reservation($pdo, $ride_id);
            // Ride Queue: ride is gone — expires any active queue
            // entries (process_queue() detects the cancelled status
            // itself, same as the driver-side path).
            require_once '../includes/ride_queue.php';
            process_queue($pdo, $ride_id);
            json_response('success', 'Ride cancelled successfully');
        } else {
            json_response('error', 'Failed to cancel ride');
        }
    } elseif ($post_action === 'complete_ride_share') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Invalid ride ID');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE ride_shares SET status = 'completed' WHERE id = ?");
            $stmt->execute([$ride_id]);
            if ($stmt->rowCount() === 0) {
                throw new Exception('Ride not found');
            }

            // This admin path previously only flipped ride_shares.status —
            // bookings were never marked completed, so driver earnings and
            // commission were never settled for an admin-completed ride.
            // Bringing it in line with the driver-side complete_ride action
            // (same booking-completion + settle_ride_completion() call) so
            // the two paths can't produce different financial outcomes for
            // the same event.
            $pdo->prepare("UPDATE ride_share_bookings SET status = 'completed' WHERE ride_share_id = ? AND status != 'cancelled'")
                ->execute([$ride_id]);
            $driverEarningCredited = settle_ride_completion($pdo, $ride_id);

            $pdo->commit();
            json_response('success', 'Ride marked as completed', ['driver_earning_credited' => $driverEarningCredited]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            json_response('error', 'Failed to complete ride: ' . $e->getMessage());
        }
    } elseif ($post_action === 'remove_ride_share') {
        $ride_id = (int)($input['ride_id'] ?? 0);
        if ($ride_id <= 0) {
            json_response('error', 'Invalid ride ID');
        }
        // Release first — the reservation row has no FK to ride_shares
        // specifically so it survives this delete as a financial record,
        // but it must be closed out before the ride disappears.
        release_ride_reservation($pdo, $ride_id);
        // Same reasoning for the ride queue (see database/migrate_waitlist.php):
        // no FK on ride_share_id, so entries survive this delete as
        // demand history, but must be closed out first.
        require_once '../includes/ride_queue.php';
        expire_ride_queue($pdo, $ride_id);
        $stmt = $pdo->prepare("DELETE FROM ride_shares WHERE id = ?");
        if ($stmt->execute([$ride_id])) {
            json_response('success', 'Ride removed successfully');
        } else {
            json_response('error', 'Failed to remove ride');
        }
    } elseif ($post_action === 'cancel_booking') {
        $booking_id = (int)($input['booking_id'] ?? 0);
        if ($booking_id <= 0) {
            json_response('error', 'Invalid booking ID');
        }
        $stmt = $pdo->prepare("UPDATE ride_share_bookings SET status = 'cancelled' WHERE id = ?");
        if ($stmt->execute([$booking_id])) {
            json_response('success', 'Booking cancelled successfully');
        } else {
            json_response('error', 'Failed to cancel booking');
        }
    } elseif ($post_action === 'update_pricing_settings') {
        // Retired in favor of the consolidated, super-admin-gated,
        // audit-logged api/pricing-admin.php — this used to write
        // pricing_configuration(star_rating=1) and two system_settings
        // keys (platform_commission, service_charge) that the live fare
        // engine never actually read.
        json_response('error', 'This action has moved to api/pricing-admin.php.');
    } elseif ($post_action === 'send_announcement') {
        // target_type is the Notification Engine's full targeting model
        // (single/multiple/all_drivers/all_riders/everyone). The
        // original 'target' param (drivers/passengers) still works,
        // mapped onto it, so nothing that already calls this breaks.
        $targetType = $input['target_type'] ?? null;
        if ($targetType === null) {
            $targetType = ($input['target'] ?? 'drivers') === 'passengers' ? 'all_riders' : 'all_drivers';
        }
        $allowedTargets = ['single', 'multiple', 'all_drivers', 'all_riders', 'everyone'];
        if (!in_array($targetType, $allowedTargets, true)) {
            json_response('error', 'Invalid target type.');
        }

        $title = sanitize_input(trim($input['title'] ?? ''));
        $message = sanitize_input(trim($input['message'] ?? ''));
        if ($message === '') {
            json_response('error', 'Message is required');
        }
        $priority = in_array($input['priority'] ?? 'normal', ['critical', 'high', 'normal', 'low'], true) ? $input['priority'] : 'normal';
        $scheduledAt = !empty($input['scheduled_at']) ? $input['scheduled_at'] : null;

        $recipientIds = [];
        if ($targetType === 'single' || $targetType === 'multiple') {
            $ids = $targetType === 'single' ? [(int)($input['user_id'] ?? 0)] : array_map('intval', (array)($input['user_ids'] ?? []));
            $ids = array_values(array_filter($ids, fn($id) => $id > 0));
            if (empty($ids)) {
                json_response('error', 'At least one recipient is required.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id IN ($placeholders) AND is_suspended = 0");
            $stmt->execute($ids);
            $recipientIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($targetType === 'all_drivers') {
            $recipientIds = $pdo->query("SELECT id FROM users WHERE user_type = 'driver' AND is_suspended = 0")->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($targetType === 'all_riders') {
            $recipientIds = $pdo->query("SELECT id FROM users WHERE user_type = 'rider' AND is_suspended = 0")->fetchAll(PDO::FETCH_COLUMN);
        } else { // everyone
            $recipientIds = $pdo->query("SELECT id FROM users WHERE user_type IN ('driver', 'rider') AND is_suspended = 0")->fetchAll(PDO::FETCH_COLUMN);
        }

        // Scheduled sends are recorded but not delivered here — no
        // background worker exists on this stack (same constraint noted
        // in api/update-location.php) to wake up and send them later.
        // Recorded 'scheduled' so a future sweep has a queue to drain
        // without changing this endpoint's contract.
        $status = $scheduledAt !== null ? 'scheduled' : 'sent';
        $broadcastPublicId = generate_unique_public_id($pdo, 'notification_broadcasts', 'public_id', 'BCT');
        $stmt = $pdo->prepare("INSERT INTO notification_broadcasts (public_id, admin_id, target_type, target_user_ids, title, message, notification_type, priority, recipient_count, scheduled_at, sent_at, status) VALUES (?, ?, ?, ?, ?, ?, 'admin', ?, ?, ?, ?, ?)");
        $stmt->execute([
            $broadcastPublicId, $_SESSION['user_id'], $targetType,
            in_array($targetType, ['single', 'multiple'], true) ? json_encode($recipientIds) : null,
            $title !== '' ? $title : null, $message, $priority, count($recipientIds),
            $scheduledAt, $status === 'sent' ? date('Y-m-d H:i:s') : null, $status,
        ]);

        if ($status === 'sent') {
            $insert = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
            foreach ($recipientIds as $userId) {
                $insert->execute([$_SESSION['user_id'], $userId, $message]);
                // force: true preserves this endpoint's original behavior
                // (it always delivered, with no per-user preference check)
                // — an admin broadcast reaching every targeted recipient
                // regardless of their generic "system notifications" toggle.
                create_notification($pdo, $userId, [
                    'title' => $title !== '' ? $title : 'MohToh Announcement',
                    'message' => $message,
                    'notification_type' => 'admin',
                    'priority' => $priority,
                    'force' => true,
                    'created_by' => $_SESSION['user_id'],
                    'related_table' => 'notification_broadcasts',
                    'related_public_id' => $broadcastPublicId,
                ]);
            }
        }

        json_response('success', $status === 'scheduled' ? 'Announcement scheduled' : 'Announcement sent successfully', [
            'recipients' => count($recipientIds), 'broadcast_id' => $broadcastPublicId, 'status' => $status,
        ]);
    } elseif ($post_action === 'approve_location' || $post_action === 'reject_location') {
        $location_id = (int)($input['location_id'] ?? 0);
        if ($location_id <= 0) {
            json_response('error', 'Invalid location ID');
        }
        $newStatus = $post_action === 'approve_location' ? 'approved' : 'rejected';
        $stmt = $pdo->prepare("UPDATE locations SET status = ?, approved_by = ? WHERE id = ?");
        if ($stmt->execute([$newStatus, (int)$_SESSION['user_id'], $location_id])) {
            json_response('success', $newStatus === 'approved' ? 'Location approved successfully' : 'Location rejected successfully');
        } else {
            json_response('error', 'Failed to update location');
        }
    } elseif ($post_action === 'resolve_chat_report') {
        // Chat Monitoring (Task 5): reviewing/dismissing a reported
        // message. Suspending the user themselves reuses the existing
        // suspend_user action above (same file, same gate) rather than
        // a second suspend implementation.
        $reportPublicId = trim((string)($input['report_public_id'] ?? ''));
        $resolution = $input['resolution'] ?? 'reviewed'; // 'reviewed' (approved/resolved) | 'dismissed' (rejected)
        if (!in_array($resolution, ['reviewed', 'dismissed'], true)) {
            $resolution = 'reviewed';
        }
        if ($reportPublicId === '') {
            json_response('error', 'report_public_id is required');
        }
        if ($resolution === 'dismissed' && trim((string)($input['reason'] ?? '')) === '') {
            json_response('error', 'A reason is required to reject/dismiss a report.');
        }
        $stmt = $pdo->prepare("SELECT id, status FROM message_reports WHERE public_id = ?");
        $stmt->execute([$reportPublicId]);
        $report = $stmt->fetch();
        if (!$report) {
            json_response('error', 'Report not found');
        }
        $stmt = $pdo->prepare("UPDATE message_reports SET status = ?, reviewed_by = ?, reviewed_at = NOW() WHERE public_id = ?");
        $stmt->execute([$resolution, $_SESSION['user_id'], $reportPublicId]);
        // Part 5/6: standardized approval lifecycle + audit trail —
        // 'reviewed' here is the Approve/Resolve outcome, 'dismissed' is Reject.
        log_admin_action($pdo, (int)$_SESSION['user_id'], $resolution === 'reviewed' ? 'approve' : 'reject', 'message_report', $report['id'], $report['status'], $resolution, $input['reason'] ?? null);
        json_response('success', 'Report updated');
    } elseif ($post_action === 'delete_chat_report') {
        // Part 5: Delete — confirmation is enforced client-side (admin
        // dashboard confirms before sending this).
        $reportPublicId = trim((string)($input['report_public_id'] ?? ''));
        if ($reportPublicId === '') {
            json_response('error', 'report_public_id is required');
        }
        $stmt = $pdo->prepare("SELECT id, status FROM message_reports WHERE public_id = ?");
        $stmt->execute([$reportPublicId]);
        $report = $stmt->fetch();
        if (!$report) {
            json_response('error', 'Report not found');
        }
        log_admin_action($pdo, (int)$_SESSION['user_id'], 'delete', 'message_report', $report['id'], $report['status'], null, $input['reason'] ?? null);
        $pdo->prepare("DELETE FROM message_reports WHERE public_id = ?")->execute([$reportPublicId]);
        json_response('success', 'Report deleted');
    } else {
        json_response('error', 'Invalid action');
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Handling GET requests (Fetch Stats and Lists)

    // Part 3/6: paginated, filterable view over admin_audit_logs — the
    // generic audit trail every approve/reject/delete action in the app
    // writes to via includes/audit.php's log_admin_action().
    if ($action === 'get_audit_logs') {
        [$page, $limit, $offset] = paginate_params();
        $where = ['1=1'];
        $params = [];
        if (!empty($_GET['entity_type'])) {
            $where[] = 'a.entity_type = ?';
            $params[] = $_GET['entity_type'];
        }
        if (!empty($_GET['action_filter'])) {
            $where[] = 'a.action = ?';
            $params[] = $_GET['action_filter'];
        }
        $sql = "SELECT a.public_id, a.action, a.entity_type, a.entity_id, a.old_status, a.new_status, a.reason, a.created_at, u.fullname AS admin_name
                FROM admin_audit_logs a
                LEFT JOIN users u ON u.id = a.admin_user_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.created_at DESC
                LIMIT $limit OFFSET $offset";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs a WHERE " . implode(' AND ', $where));
        $countStmt->execute($params);
        json_response('success', 'Audit log fetched', ['logs' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));
    }

    if ($action === 'get_dashboard_data') {
        try {
            // 1. Fetch Stats counts
            $total_users = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type != 'admin'")->fetchColumn();
            $total_drivers = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'driver'")->fetchColumn();
            $total_riders = $pdo->query("SELECT COUNT(*) FROM users WHERE user_type = 'rider'")->fetchColumn();
            $active_rides = $pdo->query("SELECT COUNT(*) FROM ride_requests WHERE status IN ('pending', 'accepted', 'in_progress')")->fetchColumn();
            $completed_rides = $pdo->query("SELECT COUNT(*) FROM ride_requests WHERE status = 'completed'")->fetchColumn();            $mohtoh_rides_total = $pdo->query("SELECT COUNT(*) FROM ride_shares")->fetchColumn();
            $pending_vehicle_reviews = $pdo->query("SELECT COUNT(*) FROM vehicles WHERE status = 'pending'")->fetchColumn();
            $total_platform_fees = $pdo->query("SELECT SUM(platform_fee) FROM ride_requests WHERE status = 'completed'")->fetchColumn();
            $total_platform_fees = $total_platform_fees ? round((float)$total_platform_fees, 2) : 0.00;

            $total_earnings = $pdo->query("SELECT SUM(price) FROM ride_requests WHERE status = 'completed'")->fetchColumn();
            $total_earnings = $total_earnings ? round((float)$total_earnings, 2) : 0.00;

            // 2. Fetch all active online driver/rider locations for map
            $stmt = $pdo->query("SELECT id, fullname, phone, user_type, latitude, longitude, is_online, profile_image FROM users WHERE user_type IN ('rider', 'driver') AND is_online = 1 AND latitude IS NOT NULL AND longitude IS NOT NULL");
            $online_users = $stmt->fetchAll();

            // Part 4 (List Limit Control): every list below this point
            // used to run with no LIMIT at all — on a growing users/
            // rides/vehicles/bookings table this endpoint (polled every
            // 15s by the dashboard) would load unboundedly more data
            // over time. Clamped to the same 100-record maximum Part 4
            // sets everywhere else; full paginated browsing of each
            // entity lives in its own ?action=list_* endpoint below.
            $dashboardListCap = 100;

            // 3. Fetch list of all users (capped)
            $stmt = $pdo->query("SELECT id, user_id, fullname, phone, email, user_type, rating, completed_rides, is_online, created_at FROM users WHERE user_type != 'admin' ORDER BY created_at DESC LIMIT " . $dashboardListCap);
            $users_list = $stmt->fetchAll();

            // 4. Fetch list of all rides (capped)
            $stmt = $pdo->query("
                SELECT r.id, r.pickup_lat, r.pickup_lng, r.destination, r.price, r.platform_fee, r.status, r.created_at,
                       u1.fullname as rider_name, u2.fullname as driver_name
                FROM ride_requests r
                JOIN users u1 ON r.rider_id = u1.id
                LEFT JOIN users u2 ON r.driver_id = u2.id
                ORDER BY r.created_at DESC
                LIMIT " . $dashboardListCap . "
            ");
            $rides_list = $stmt->fetchAll();

            // 5. Fetch list of all registered vehicles for review (same as before)
            $vehicleEditRequestExists = table_exists($pdo, 'vehicle_edit_requests');
            $pendingEditRequestSelect = $vehicleEditRequestExists
                ? "(SELECT COUNT(*) FROM vehicle_edit_requests er WHERE er.vehicle_id = v.id AND er.status = 'pending') AS pending_edit_requests"
                : "0 AS pending_edit_requests";

            $stmt = $pdo->query(
                "SELECT v.id, v.public_id, v.driver_id, u.fullname AS driver_name, u.phone AS driver_phone, u.email AS driver_email,
                        CONCAT(v.make, ' ', v.model) AS vehicle_name, v.year, v.color, v.plate_number,
                        v.ac_available, v.cleanliness, v.comfort_level, v.seats, v.luggage_capacity,
                        v.status, v.admin_rating, v.created_at,
                        (SELECT COUNT(*) FROM vehicle_photos vp WHERE vp.vehicle_id = v.id) AS photo_count,
                        (SELECT COUNT(*) FROM vehicle_verifications vv WHERE vv.vehicle_id = v.id) AS document_count,
                        COALESCE((SELECT SUM(vv.status = 'pending') FROM vehicle_verifications vv WHERE vv.vehicle_id = v.id), 0) AS pending_documents,
                        $pendingEditRequestSelect
                 FROM vehicles v
                 JOIN users u ON v.driver_id = u.id
                 ORDER BY v.created_at DESC
                 LIMIT " . $dashboardListCap
            );
            $vehicles_list = $stmt->fetchAll();

            $stmt = $pdo->query("SELECT id, user_id, fullname, phone, email, rating, is_verified, is_suspended, driver_tier, created_at, email_verified, phone_verified, email_verified_at, phone_verified_at, failed_login_attempts, lockout_until FROM users WHERE user_type = 'driver' ORDER BY created_at DESC LIMIT " . $dashboardListCap);
            $drivers_list = $stmt->fetchAll();

            $stmt = $pdo->query("SELECT id, user_id, fullname, phone, email, rating, is_suspended, created_at, email_verified, phone_verified, email_verified_at, phone_verified_at, failed_login_attempts, lockout_until FROM users WHERE user_type = 'rider' ORDER BY created_at DESC LIMIT " . $dashboardListCap);
            $passengers_list = $stmt->fetchAll();

            $stmt = $pdo->query("SELECT rs.id, rs.public_id, rs.destination, rs.departure_date, rs.departure_time, rs.price_per_passenger, rs.status, rs.total_seats, rs.available_seats, u.fullname AS driver_name, (SELECT COUNT(*) FROM ride_share_bookings rb WHERE rb.ride_share_id = rs.id AND rb.status != 'cancelled') AS bookings_count FROM ride_shares rs JOIN users u ON rs.driver_id = u.id ORDER BY rs.created_at DESC LIMIT " . $dashboardListCap);
            $mohtoh_rides_list = $stmt->fetchAll();

            $stmt = $pdo->query("SELECT rb.id, rb.public_id, rb.ride_share_id, rs.public_id AS ride_public_id, rb.rider_id, rb.seats_booked, rb.status, rb.created_at, rs.destination, u.fullname AS rider_name FROM ride_share_bookings rb JOIN ride_shares rs ON rb.ride_share_id = rs.id JOIN users u ON rb.rider_id = u.id ORDER BY rb.created_at DESC LIMIT " . $dashboardListCap);
            $bookings_list = $stmt->fetchAll();

            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'commission_percentage'");
            $stmt->execute();
            $commission = $stmt->fetchColumn();
            $commission = $commission !== false ? (int)$commission : 10;

            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'platform_commission'");
            $stmt->execute();
            $platform_commission = $stmt->fetchColumn();
            $platform_commission = $platform_commission !== false ? (float)$platform_commission : 0.00;

            $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'service_charge'");
            $stmt->execute();
            $service_charge = $stmt->fetchColumn();
            $service_charge = $service_charge !== false ? (float)$service_charge : 0.00;

            $stmt = $pdo->prepare("SELECT price_per_km FROM pricing_configuration WHERE star_rating = 1");
            $stmt->execute();
            $price_per_km = $stmt->fetchColumn();
            $price_per_km = $price_per_km !== false ? (float)$price_per_km : 0.00;

            $ride_reports = [
                'total_mohtoh_rides' => $mohtoh_rides_total,
                'active_mohtoh_rides' => $pdo->query("SELECT COUNT(*) FROM ride_shares WHERE status = 'active'")->fetchColumn(),
                'completed_mohtoh_rides' => $pdo->query("SELECT COUNT(*) FROM ride_shares WHERE status = 'completed'")->fetchColumn(),
                'bookings_confirmed' => $pdo->query("SELECT COUNT(*) FROM ride_share_bookings WHERE status = 'confirmed'")->fetchColumn(),
                'bookings_cancelled' => $pdo->query("SELECT COUNT(*) FROM ride_share_bookings WHERE status = 'cancelled'")->fetchColumn()
            ];

            $pending_locations = $pdo->query("SELECT COUNT(*) FROM locations WHERE status = 'pending'")->fetchColumn();
            $stmt = $pdo->query("
                SELECT l.id, l.public_id, l.name, l.address, l.city, l.state, l.type, l.status, l.latitude, l.longitude, l.created_at,
                       u.fullname AS created_by_name, u.user_type AS created_by_type
                FROM locations l
                JOIN users u ON l.created_by = u.id
                ORDER BY (l.status = 'pending') DESC, l.created_at DESC
                LIMIT 100
            ");
            $locations_list = $stmt->fetchAll();

            json_response('success', 'Dashboard data retrieved successfully', [
                'stats' => [
                    'total_users' => $total_users,
                    'total_drivers' => $total_drivers,
                    'total_riders' => $total_riders,
                    'active_rides' => $active_rides,
                    'completed_rides' => $completed_rides,
                    'total_platform_fees' => $total_platform_fees,
                    'total_earnings' => $total_earnings,
                    'mohtoh_rides_total' => $mohtoh_rides_total,
                    'pending_vehicle_reviews' => $pending_vehicle_reviews,
                    'pending_locations' => $pending_locations
                ],
                'online_users' => $online_users,
                'users' => $users_list,
                'rides' => $rides_list,
                'vehicles' => $vehicles_list,
                'drivers' => $drivers_list,
                'passengers' => $passengers_list,
                'mohtoh_rides' => $mohtoh_rides_list,
                'bookings' => $bookings_list,
                'locations' => $locations_list,
                'commission_percentage' => $commission,
                'pricing' => [
                    'price_per_km' => $price_per_km,
                    'platform_commission' => $platform_commission,
                    'service_charge' => $service_charge
                ],
                'reports' => $ride_reports
            ]);

        } catch (Exception $e) {
            json_response('error', 'Database query error: ' . $e->getMessage());
        }

    } elseif ($action === 'get_vehicles') {
        // Return a list of vehicles with optional status filter and simple pagination
        try {
            $status = $_GET['status'] ?? null; // pending, approved, rejected, any
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = min(100, max(10, (int)($_GET['limit'] ?? 25)));
            $offset = ($page - 1) * $limit;

            $params = [];
            $where = '';
            if ($status) {
                $where = 'WHERE v.status = ?';
                $params[] = $status;
            }

            // $limit/$offset are interpolated, not bound: with emulated
            // prepares MariaDB receives LIMIT '25' and rejects the query, so
            // the whole vehicle queue 500'd. Both are already int-cast and
            // clamped above, matching how the rest of this file paginates.

            $vehicleEditRequestExists = table_exists($pdo, 'vehicle_edit_requests');
            $pendingEditRequestSelect = $vehicleEditRequestExists
                ? "(SELECT COUNT(*) FROM vehicle_edit_requests er WHERE er.vehicle_id = v.id AND er.status = 'pending') AS pending_edit_requests"
                : "0 AS pending_edit_requests";

            $stmt = $pdo->prepare(
                "SELECT v.id, v.public_id, v.driver_id, u.fullname AS driver_name, u.phone AS driver_phone,
                        CONCAT(v.make, ' ', v.model) AS vehicle_name, v.vehicle_type, v.year, v.color, v.plate_number,
                        v.status, v.admin_rating, v.created_at,
                        (SELECT COUNT(*) FROM vehicle_photos vp WHERE vp.vehicle_id = v.id) AS photo_count,
                        (SELECT COUNT(*) FROM vehicle_verifications vv WHERE vv.vehicle_id = v.id) AS document_count,
                        $pendingEditRequestSelect
                 FROM vehicles v
                 JOIN users u ON v.driver_id = u.id
                 $where
                 ORDER BY v.created_at DESC
                 LIMIT $limit OFFSET $offset"
            );

            $stmt->execute($params);
            $vehicles = $stmt->fetchAll();

            // Label the class here rather than in the browser so the admin
            // queue and the driver's own sheet always agree on what a
            // vehicle is called.
            foreach ($vehicles as &$vehicleRow) {
                $vehicleRow['vehicle_type_label'] = vehicle_type_label($vehicleRow['vehicle_type'] ?? '');
            }
            unset($vehicleRow);

            json_response('success', 'Vehicles retrieved', ['vehicles' => $vehicles, 'page' => $page, 'limit' => $limit]);
        } catch (Exception $e) {
            json_response('error', 'Failed to fetch vehicles: ' . $e->getMessage());
        }

    } elseif ($action === 'user_profile_reviews') {
        try {
            $user_id = (int)($_GET['user_id'] ?? 0);
            if ($user_id <= 0) {
                json_response('error', 'Invalid user id');
            }

            json_response('success', 'User review profile retrieved', fetch_user_review_profile($pdo, $user_id));
        } catch (Exception $e) {
            json_response('error', 'Failed to fetch user review profile: ' . $e->getMessage());
        }

    } elseif ($action === 'vehicle_details') {
        // Return full vehicle details including photos, verification docs and logs
        try {
            $vehicle_id = (int)($_GET['vehicle_id'] ?? 0);
            if ($vehicle_id <= 0) {
                json_response('error', 'Invalid vehicle id');
            }

            $stmt = $pdo->prepare("SELECT v.*, u.fullname AS driver_name, u.phone AS driver_phone, u.email AS driver_email FROM vehicles v JOIN users u ON v.driver_id = u.id WHERE v.id = ?");
            $stmt->execute([$vehicle_id]);
            $vehicle = $stmt->fetch();
            if (!$vehicle) {
                json_response('error', 'Vehicle not found');
            }

            // photos
            $stmt = $pdo->prepare("SELECT id, photo_path, created_at FROM vehicle_photos WHERE vehicle_id = ? ORDER BY created_at DESC");
            $stmt->execute([$vehicle_id]);
            $photos = $stmt->fetchAll();

            // verifications / documents
            $documentRemarksSelect = column_exists($pdo, 'vehicle_verifications', 'remarks')
                ? 'remarks'
                : 'NULL AS remarks';
            $documentReviewedAtSelect = column_exists($pdo, 'vehicle_verifications', 'reviewed_at')
                ? 'reviewed_at'
                : 'NULL AS reviewed_at';
            $stmt = $pdo->prepare("SELECT id, public_id, document_name, document_type, document_path, status, rejection_reason, $documentRemarksSelect, created_at, $documentReviewedAtSelect FROM vehicle_verifications WHERE vehicle_id = ? ORDER BY created_at DESC");
            $stmt->execute([$vehicle_id]);
            $documents = $stmt->fetchAll();

            // Label each document's type so the review grid can say what it
            // is looking at; documents uploaded before document_type
            // existed are resolved from their free-text name.
            foreach ($documents as &$docRow) {
                $docRow['document_type'] = resolve_vehicle_document_type($docRow['document_type'], $docRow['document_name']);
                $docRow['document_type_label'] = vehicle_document_label($docRow['document_type']);
            }
            unset($docRow);

            // pending edit request details, if any
            $pendingEditRequest = null;
            if (table_exists($pdo, 'vehicle_edit_requests')) {
                $stmt = $pdo->prepare("SELECT id, public_id, requested_changes, status, admin_remarks, created_at, reviewed_at FROM vehicle_edit_requests WHERE vehicle_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
                $stmt->execute([$vehicle_id]);
                $pendingEditRequest = $stmt->fetch();
                if ($pendingEditRequest) {
                    $pendingEditRequest['requested_changes'] = json_decode($pendingEditRequest['requested_changes'], true);
                }
            }

            // recent admin actions / audit logs
            $audit = [];
            if ($pdo->query("SHOW TABLES LIKE 'vehicle_review_logs'")->fetchColumn()) {
                $stmt = $pdo->prepare("SELECT id, public_id, admin_id, action, remarks, created_at FROM vehicle_review_logs WHERE vehicle_id = ? ORDER BY created_at DESC LIMIT 20");
                $stmt->execute([$vehicle_id]);
                $audit = $stmt->fetchAll();
            }

            json_response('success', 'Vehicle details retrieved', ['vehicle' => $vehicle, 'vehicle_type_label' => vehicle_type_label($vehicle['vehicle_type'] ?? ''), 'photos' => $photos, 'documents' => $documents, 'pending_edit_request' => $pendingEditRequest, 'audit' => $audit]);
        } catch (Exception $e) {
            json_response('error', 'Failed to fetch vehicle details: ' . $e->getMessage());
        }

    } elseif ($action === 'list_reported_chats') {
        // Chat Monitoring (Task 5). Open reports first, then most recent —
        // an admin working the queue sees unresolved items up top without
        // needing a separate filter click.
        $stmt = $pdo->query("
            SELECT r.public_id AS report_public_id, r.reason, r.status, r.created_at AS reported_at,
                   reporter.fullname AS reported_by_name, reporter.user_id AS reported_by_public_id,
                   m.public_id AS message_public_id, m.message, m.message_type, m.created_at AS message_created_at,
                   sender.fullname AS sender_name, sender.user_id AS sender_public_id, sender.is_suspended AS sender_is_suspended, sender.id AS sender_id,
                   c.public_id AS conversation_public_id, c.type AS conversation_type, c.id AS conversation_id
            FROM message_reports r
            JOIN messages m ON m.id = r.message_id
            JOIN conversations c ON c.id = r.conversation_id
            JOIN users reporter ON reporter.id = r.reported_by
            LEFT JOIN users sender ON sender.id = m.sender_id
            ORDER BY (r.status = 'open') DESC, r.created_at DESC
            LIMIT 100
        ");
        json_response('success', 'Reported messages fetched', ['reports' => $stmt->fetchAll()]);

    } elseif ($action === 'search_conversations') {
        // Search by participant name/phone or a ride's public ID —
        // "Search conversation" per the spec. Normal private/support
        // chats stay private otherwise; this endpoint itself is only
        // reachable by an authenticated admin (gate at the top of this file).
        $search = trim((string)($_GET['q'] ?? ''));
        if ($search === '') {
            json_response('error', 'q (search term) is required');
        }
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id AS conversation_id, c.public_id, c.type, c.status, c.created_at, rs.public_id AS ride_public_id
            FROM conversations c
            LEFT JOIN conversation_members cm ON cm.conversation_id = c.id
            LEFT JOIN users u ON u.id = cm.user_id
            LEFT JOIN ride_shares rs ON rs.id = c.ride_share_id
            WHERE u.fullname LIKE ? OR u.phone LIKE ? OR u.user_id LIKE ? OR rs.public_id LIKE ? OR c.public_id LIKE ?
            ORDER BY c.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$like, $like, $like, $like, $like]);
        json_response('success', 'Conversations found', ['conversations' => $stmt->fetchAll()]);

    } elseif ($action === 'view_conversation') {
        // Admin bypass of the normal membership check (api/messages.php's
        // get_messages) — same idea, but this file's own admin gate is
        // the authorization, not conversation_members.
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            json_response('error', 'conversation_id is required');
        }
        $stmt = $pdo->prepare("SELECT c.*, rs.public_id AS ride_public_id, rs.start_location, rs.destination FROM conversations c LEFT JOIN ride_shares rs ON rs.id = c.ride_share_id WHERE c.id = ?");
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        if (!$conversation) {
            json_response('error', 'Conversation not found');
        }

        $stmt = $pdo->prepare("
            SELECT cm.role, cm.is_active, u.id AS internal_id, u.fullname, u.user_id AS public_user_id, u.is_suspended
            FROM conversation_members cm JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id = ?
        ");
        $stmt->execute([$conversationId]);
        $members = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT m.public_id, m.sender_id, u.fullname AS sender_name, m.message, m.message_type,
                   m.flagged_for_review, m.created_at
            FROM messages m LEFT JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ? ORDER BY m.id ASC LIMIT 500
        ");
        $stmt->execute([$conversationId]);
        $messages = $stmt->fetchAll();

        json_response('success', 'Conversation fetched', ['conversation' => $conversation, 'members' => $members, 'messages' => $messages]);

    } elseif ($action === 'referral_analytics') {
        // MohToh Community Rewards — Admin Dashboard "Community Growth" tab.
        $stmt = $pdo->query("
            SELECT COUNT(*) AS total,
                   SUM(status IN ('QUALIFIED','REWARDED')) AS successful,
                   SUM(referral_type = 'DRIVER') AS driver_referrals,
                   SUM(referral_type = 'RIDER') AS rider_referrals,
                   SUM(status = 'CANCELLED') AS cancelled
            FROM referrals
        ");
        $totals = $stmt->fetch();

        $stmt = $pdo->query("SELECT COALESCE(SUM(amount), 0) FROM referral_rewards WHERE status = 'PAID'");
        $rewardsIssued = round((float)$stmt->fetchColumn(), 2);

        // Top community builders — most QUALIFIED/REWARDED referrals.
        $stmt = $pdo->query("
            SELECT u.fullname, u.user_id AS public_user_id, u.user_type,
                   COUNT(*) AS total_referrals,
                   SUM(r.status IN ('QUALIFIED','REWARDED')) AS successful_referrals,
                   COALESCE((SELECT SUM(rr.amount) FROM referral_rewards rr WHERE rr.user_id = u.id AND rr.status = 'PAID'), 0) AS total_rewards
            FROM referrals r
            JOIN users u ON u.id = r.referrer_user_id
            GROUP BY u.id, u.fullname, u.user_id, u.user_type
            ORDER BY successful_referrals DESC, total_referrals DESC
            LIMIT 10
        ");
        $topBuilders = $stmt->fetchAll();

        json_response('success', 'Referral analytics fetched', [
            'total_referrals' => (int)($totals['total'] ?? 0),
            'successful_referrals' => (int)($totals['successful'] ?? 0),
            'driver_referrals' => (int)($totals['driver_referrals'] ?? 0),
            'rider_referrals' => (int)($totals['rider_referrals'] ?? 0),
            'cancelled_referrals' => (int)($totals['cancelled'] ?? 0),
            'rewards_issued' => $rewardsIssued,
            'top_builders' => $topBuilders,
        ]);

    } elseif ($action === 'list_referrals') {
        $where = [];
        $params = [];

        if (!empty($_GET['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $_GET['status'];
        }
        if (!empty($_GET['user_type'])) {
            $where[] = 'r.referral_type = ?';
            $params[] = strtoupper($_GET['user_type']);
        }
        if (!empty($_GET['date_from'])) {
            $where[] = 'r.created_at >= ?';
            $params[] = $_GET['date_from'] . ' 00:00:00';
        }
        if (!empty($_GET['date_to'])) {
            $where[] = 'r.created_at <= ?';
            $params[] = $_GET['date_to'] . ' 23:59:59';
        }
        // Same shared-IP-at-registration heuristic includes/referral.php
        // logs (Phase 14) — a review flag, not an auto-block.
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM referrals r $whereSql");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT r.public_id, r.referral_type, r.status, r.registered_at, r.qualified_at,
                   referrer.fullname AS referrer_name, referrer.user_id AS referrer_public_id,
                   referred.fullname AS referred_name, referred.user_id AS referred_public_id
            FROM referrals r
            JOIN users referrer ON referrer.id = r.referrer_user_id
            JOIN users referred ON referred.id = r.referred_user_id
            $whereSql
            ORDER BY r.created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute($params);

        json_response('success', 'Referrals fetched', ['referrals' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'limit' => $limit]);

    } else {
        json_response('error', 'Invalid action');
    }

} else {
    json_response('error', 'Invalid request method');
}
?>
