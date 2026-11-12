<?php
// api/admin-management.php
// Super-Admin-only administration of admin accounts: list admins, create
// Subadmins, promote an existing rider/driver into a Subadmin (multi-role),
// change a Subadmin's granular permissions, suspend/reactivate the admin
// hat, edit a Subadmin's profile, and read an admin's audit activity.
//
// Authorization: require_super_admin($pdo) gates EVERY action (GET and
// POST). A Subadmin can never reach this file, so a Subadmin can never
// create admins, grant themselves permissions, escalate to Super, or
// suspend/remove another admin — the endpoint simply isn't reachable
// without an active super session (re-verified from the DB on every call).
//
// Escalation is impossible by construction:
//   * is_super_admin is NEVER set here — there is no code path that writes
//     it to 1, so no UI action can create or become a Super Admin.
//   * set_permissions only accepts keys from the grantable catalog
//     (admin_permission_is_grantable) — super/Administration/money-config
//     keys are not in it, so they can never be granted.
//   * self-targeting is refused for suspend/edit; the last active Super
//     Admin can never be suspended (COUNT of other active supers > 0).
// Every mutation writes an admin_audit_logs row via log_admin_action().
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/config.php';
require_once '../includes/verification.php'; // normalize_phone_number()
require_once '../includes/audit.php';
require_once '../includes/admin_permissions.php';
require_once '../includes/eligibility.php'; // vehicle_type_label() for the "Add Existing User" search card

header('Content-Type: application/json');

// Single, method-agnostic gate: Super Admin only. Re-reads is_super_admin
// from the DB every request (see includes/functions.php), so a demoted or
// suspended super loses this endpoint immediately with no re-login.
require_super_admin($pdo);
ensure_admin_rbac_schema($pdo);

$actorId = (int)$_SESSION['user_id'];

// Count of ACTIVE Super Admins other than $exceptId — the "never remove the
// last super" guard. Active = is_super_admin AND admin_status active AND not
// account-suspended.
function other_active_super_count($pdo, $exceptId) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_super_admin = 1 AND admin_status = 'active' AND is_suspended = 0 AND id != ?");
    $stmt->execute([(int)$exceptId]);
    return (int)$stmt->fetchColumn();
}

// Fetch an admin row (or null). Only rows with is_admin=1 are "admins".
function fetch_admin_row($pdo, $userId) {
    $stmt = $pdo->prepare("SELECT id, fullname, email, phone, user_type, is_admin, is_super_admin, admin_status, is_suspended FROM users WHERE id = ?");
    $stmt->execute([(int)$userId]);
    return $stmt->fetch() ?: null;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'catalog') {
        // Server is the single source of truth for the permission catalog
        // the management UI renders as grouped checkboxes.
        json_response('success', 'Permission catalog', ['catalog' => ADMIN_PERMISSIONS]);

    } elseif ($action === 'list') {
        $stmt = $pdo->query("
            SELECT u.id, u.user_id AS public_id, u.fullname, u.email, u.phone, u.user_type,
                   u.is_super_admin, u.admin_status, u.is_suspended, u.created_at,
                   (SELECT COUNT(*) FROM admin_permissions ap WHERE ap.user_id = u.id) AS permission_count
            FROM users u
            WHERE u.is_admin = 1
            ORDER BY u.is_super_admin DESC, u.created_at ASC
        ");
        $admins = $stmt->fetchAll();
        foreach ($admins as &$a) {
            $a['role_label']   = $a['is_super_admin'] ? 'Super Admin' : 'Subadmin';
            $a['status_label'] = $a['admin_status'] === 'suspended' ? 'Suspended' : 'Active';
            $a['is_self']      = ((int)$a['id'] === $actorId);
        }
        unset($a);
        json_response('success', 'Admins fetched', ['admins' => $admins]);

    } elseif ($action === 'get_admin') {
        $userId = (int)($_GET['user_id'] ?? 0);
        $admin = $userId > 0 ? fetch_admin_row($pdo, $userId) : null;
        if (!$admin || !$admin['is_admin']) {
            json_response('error', 'Admin not found');
        }
        $stmt = $pdo->prepare("SELECT permission FROM admin_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        $perms = $stmt->fetchAll(PDO::FETCH_COLUMN);
        json_response('success', 'Admin fetched', [
            'admin'       => $admin,
            'permissions' => $perms,
        ]);

    } elseif ($action === 'get_activity') {
        // The target admin's own actions, from the generic audit trail.
        $userId = (int)($_GET['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        [$page, $limit, $offset] = paginate_params();
        $stmt = $pdo->prepare("
            SELECT public_id, action, entity_type, entity_id, old_status, new_status, reason, created_at
            FROM admin_audit_logs
            WHERE admin_user_id = ?
            ORDER BY created_at DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs WHERE admin_user_id = ?");
        $countStmt->execute([$userId]);
        json_response('success', 'Activity fetched', ['activity' => $rows], paginate_meta($page, $limit, (int)$countStmt->fetchColumn()));

    } elseif ($action === 'search_user') {
        // Look up ONE existing account to promote, by email / phone / public
        // user_id / numeric id. Powers the "Add Existing User" workflow: the
        // promotion path never inserts a new user, so an existing driver's
        // email must resolve to their real account here instead of colliding.
        //
        // Returns ONLY non-sensitive profile fields — never password, tokens,
        // OTP, or reset data — plus the driver/vehicle/verification context a
        // Super Admin needs to decide, mirroring the columns admin-api.php's
        // vehicle views already surface.
        $q = trim($_GET['q'] ?? '');
        if ($q === '') {
            json_response('error', 'Enter an email, phone number, or user ID to search.');
        }
        // Match the same identifiers the rest of the app keys on: normalized
        // phone (verification.php), the public user_id string, or numeric id.
        $normPhone = normalize_phone_number($q);
        $numericId = ctype_digit($q) ? (int)$q : 0;
        $stmt = $pdo->prepare("
            SELECT id, user_id AS public_id, fullname, email, phone, user_type,
                   driver_tier, is_keke_driver, is_verified, is_suspended,
                   is_admin, is_super_admin, admin_status,
                   email_verified, phone_verified, created_at
            FROM users
            WHERE LOWER(email) = LOWER(?) OR phone = ? OR user_id = ? OR id = ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$q, $normPhone ?: $q, $q, $numericId]);
        $u = $stmt->fetch();
        if (!$u) {
            json_response('error', 'No account matches that email, phone number, or ID.');
        }

        // Driver vehicle context: the class + verification a promotion decision
        // needs. Prefer a verified vehicle, else the most recent. Riders have
        // none, so the block is skipped for them.
        $vehicle = null;
        if ($u['user_type'] === 'driver') {
            $vstmt = $pdo->prepare("
                SELECT public_id, make, model, year, plate_number, vehicle_type,
                       status, verification_status
                FROM vehicles WHERE driver_id = ?
                ORDER BY (verification_status = 'verified') DESC, created_at DESC
                LIMIT 1
            ");
            $vstmt->execute([(int)$u['id']]);
            $vehicle = $vstmt->fetch() ?: null;
            if ($vehicle) {
                $vehicle['vehicle_type_label'] = vehicle_type_label($vehicle['vehicle_type'] ?? '');
            }
        }

        $isAdmin  = (bool)$u['is_admin'];
        // Only an existing rider/driver that is not already an admin can be
        // promoted (the server re-checks this in promote_admin regardless).
        $eligible = !$isAdmin && in_array($u['user_type'], ['rider', 'driver'], true);
        json_response('success', 'Account found', [
            'user' => [
                'id'             => (int)$u['id'],
                'public_id'      => $u['public_id'],
                'fullname'       => $u['fullname'],
                'email'          => $u['email'],
                'phone'          => $u['phone'],
                'user_type'      => $u['user_type'],
                'driver_tier'    => $u['driver_tier'],
                'is_keke_driver' => (bool)$u['is_keke_driver'],
                'is_verified'    => (bool)$u['is_verified'],
                'is_suspended'   => (bool)$u['is_suspended'],
                'is_admin'       => $isAdmin,
                'is_super_admin' => (bool)$u['is_super_admin'],
                'admin_status'   => $u['admin_status'],
                'email_verified' => (bool)$u['email_verified'],
                'phone_verified' => (bool)$u['phone_verified'],
                'created_at'     => $u['created_at'],
            ],
            'vehicle'       => $vehicle,
            'already_admin' => $isAdmin,
            'eligible'      => $eligible,
        ]);

    } else {
        json_response('error', 'Invalid action');
    }

} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    $post_action = $input['action'] ?? '';

    // Validate + normalize a requested permission set down to grantable keys.
    // Non-grantable/unknown keys are dropped silently here AND the caller is
    // told, so a crafted payload can never grant super/admins.*/money-config.
    $sanitize_permissions = function ($raw) {
        $out = [];
        foreach ((array)$raw as $p) {
            $p = is_string($p) ? trim($p) : '';
            if ($p !== '' && admin_permission_is_grantable($p) && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return $out;
    };

    if ($post_action === 'create_subadmin') {
        $fullname = sanitize_input($input['fullname'] ?? '');
        $email    = sanitize_input($input['email'] ?? '');
        $phone_raw= sanitize_input($input['phone'] ?? '');
        $password = mt_scalar_string($input['password'] ?? '');
        $permissions = $sanitize_permissions($input['permissions'] ?? []);

        if ($fullname === '' || $email === '' || $phone_raw === '' || $password === '') {
            json_response('error', 'Full name, email, phone and password are required.');
        }
        if (strlen($fullname) < 3) {
            json_response('error', 'Full name must be at least 3 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response('error', 'Please enter a valid email address.');
        }
        if (function_exists('password_meets_policy')) {
            $policyError = password_meets_policy($password);
            if ($policyError !== null) {
                json_response('error', $policyError);
            }
        }
        $phone = normalize_phone_number($phone_raw);
        if (!$phone) {
            json_response('error', 'Please enter a valid phone number.');
        }

        $stmt = $pdo->prepare("SELECT id, email, phone FROM users WHERE LOWER(email) = LOWER(?) OR phone = ?");
        $stmt->execute([$email, $phone]);
        $existing = $stmt->fetch();
        if ($existing) {
            json_response('error', strtolower($existing['email']) === strtolower($email)
                ? 'Email already registered' : 'Phone number already registered');
        }

        try {
            $pdo->beginTransaction();
            // A fresh Subadmin is a pure-admin account of record
            // (user_type='admin'), is_admin=1, is_super_admin=0. It gets
            // ONLY the permissions granted below — nothing implicit beyond
            // the dashboard baseline.
            //
            // Provisioned as already-verified: a Super Admin creating this
            // account IS the trust anchor, exactly like the driver/vehicle
            // verification a super performs elsewhere. There is no self-service
            // email/phone ownership step for internal staff (and no "mark
            // verified" control in the Administrators UI), so without this the
            // new admin would be bounced to verify-account.php on first login
            // (is_user_verified() gate) and could never reach the dashboard.
            // email/phone_verified clear the login gate; is_verified matches a
            // fully-provisioned account (harmless for a pure admin — it is the
            // driver-eligibility flag, never read on an admin path).
            $publicId = generate_unique_user_id($pdo, 'admin');
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (fullname, phone, email, password, user_type, user_id, is_admin, is_super_admin, admin_status, is_verified, email_verified, phone_verified) VALUES (?, ?, ?, ?, 'admin', ?, 1, 0, 'active', 1, 1, 1)");
            $stmt->execute([$fullname, $phone, $email, $hashed, $publicId]);
            $newId = (int)$pdo->lastInsertId();

            if ($permissions) {
                $ins = $pdo->prepare("INSERT INTO admin_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
                foreach ($permissions as $p) {
                    $ins->execute([$newId, $p, $actorId]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            json_response('error', 'Failed to create subadmin: ' . $e->getMessage());
        }

        log_admin_action($pdo, $actorId, 'create_subadmin', 'admin', $newId, null, 'active', 'Permissions: ' . (implode(',', $permissions) ?: 'none'));
        json_response('success', 'Subadmin created', ['user_id' => $newId, 'public_id' => $publicId]);

    } elseif ($post_action === 'promote_admin') {
        // Grant admin capability to an EXISTING rider/driver WITHOUT changing
        // their account of record — this is the multi-role path (one login
        // that is Rider/Driver AND Admin). Never touches is_super_admin.
        $userId = (int)($input['user_id'] ?? 0);
        $permissions = $sanitize_permissions($input['permissions'] ?? []);
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        $target = fetch_admin_row($pdo, $userId);
        if (!$target) {
            json_response('error', 'User not found');
        }
        if ($target['is_admin']) {
            json_response('error', 'This account is already an admin. Use Change Permissions instead.');
        }
        if (!in_array($target['user_type'], ['rider', 'driver'], true)) {
            json_response('error', 'Only rider or driver accounts can be promoted to Subadmin.');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET is_admin = 1, admin_status = 'active', is_super_admin = 0 WHERE id = ?")->execute([$userId]);
            if ($permissions) {
                $ins = $pdo->prepare("INSERT INTO admin_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
                foreach ($permissions as $p) {
                    $ins->execute([$userId, $p, $actorId]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            json_response('error', 'Failed to promote account: ' . $e->getMessage());
        }

        log_admin_action($pdo, $actorId, 'promote_admin', 'admin', $userId, null, 'active', 'Permissions: ' . (implode(',', $permissions) ?: 'none'));
        json_response('success', 'Account promoted to Subadmin');

    } elseif ($post_action === 'demote_admin') {
        // Remove the admin CAPABILITY from a multi-role account, the exact
        // reverse of promote_admin. Touches ONLY is_admin (+ resets the admin
        // hat to 'active' so a later re-promote starts clean) and clears the
        // explicit grants a non-admin should not keep. Everything that makes
        // them a rider/driver is left byte-for-byte: user_type, is_super_admin,
        // is_suspended, vehicles, rides, wallet, verification and history.
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        if ($userId === $actorId) {
            json_response('error', 'You cannot remove your own admin role.');
        }
        $target = fetch_admin_row($pdo, $userId);
        if (!$target || !$target['is_admin']) {
            json_response('error', 'This account is not an administrator.');
        }
        // A Super Admin's role is never stripped through this path (follows the
        // same protection as suspend/edit/permissions).
        if ($target['is_super_admin']) {
            json_response('error', 'A Super Admin\'s role cannot be removed here.');
        }
        // A dedicated admin account (user_type='admin') has no rider/driver hat
        // to fall back to — removing is_admin would strand a login with no
        // usable mode. Such accounts are suspended, not demoted.
        if ($target['user_type'] === 'admin') {
            json_response('error', 'This is a dedicated admin account with no rider/driver role. Suspend it instead of removing the admin role.');
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET is_admin = 0, admin_status = 'active' WHERE id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$userId]);
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            json_response('error', 'Failed to remove admin role: ' . $e->getMessage());
        }

        log_admin_action($pdo, $actorId, 'demote_admin', 'admin', $userId, 'active', 'removed', 'Admin role removed; account remains ' . $target['user_type']);
        json_response('success', 'Admin role removed. The ' . $target['user_type'] . ' account is unchanged.');

    } elseif ($post_action === 'set_permissions') {
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        if ($userId === $actorId) {
            json_response('error', 'You cannot change your own permissions.');
        }
        $target = fetch_admin_row($pdo, $userId);
        if (!$target || !$target['is_admin']) {
            json_response('error', 'Admin not found');
        }
        if ($target['is_super_admin']) {
            json_response('error', 'Super Admins have full access; permissions cannot be scoped for them.');
        }
        $permissions = $sanitize_permissions($input['permissions'] ?? []);

        // Capture the old set for the audit trail (no secrets — just keys).
        $stmt = $pdo->prepare("SELECT permission FROM admin_permissions WHERE user_id = ? ORDER BY permission");
        $stmt->execute([$userId]);
        $oldPerms = $stmt->fetchAll(PDO::FETCH_COLUMN);

        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM admin_permissions WHERE user_id = ?")->execute([$userId]);
            if ($permissions) {
                $ins = $pdo->prepare("INSERT INTO admin_permissions (user_id, permission, granted_by) VALUES (?, ?, ?)");
                foreach ($permissions as $p) {
                    $ins->execute([$userId, $p, $actorId]);
                }
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            json_response('error', 'Failed to update permissions: ' . $e->getMessage());
        }

        sort($permissions);
        log_admin_action($pdo, $actorId, 'set_permissions', 'admin', $userId, implode(',', $oldPerms) ?: 'none', implode(',', $permissions) ?: 'none', null);
        json_response('success', 'Permissions updated', ['permissions' => $permissions]);

    } elseif ($post_action === 'suspend_admin' || $post_action === 'reactivate_admin') {
        $userId = (int)($input['user_id'] ?? 0);
        $suspend = $post_action === 'suspend_admin';
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        if ($suspend && $userId === $actorId) {
            json_response('error', 'You cannot suspend your own admin access.');
        }
        $target = fetch_admin_row($pdo, $userId);
        if (!$target || !$target['is_admin']) {
            json_response('error', 'Admin not found');
        }
        // Never suspend the last active Super Admin (protects the primary
        // super and any sole remaining super).
        if ($suspend && $target['is_super_admin'] && other_active_super_count($pdo, $userId) === 0) {
            json_response('error', 'You cannot suspend the last active Super Admin.');
        }

        $newStatus = $suspend ? 'suspended' : 'active';
        if ($target['admin_status'] === $newStatus) {
            json_response('success', $suspend ? 'Admin already suspended' : 'Admin already active', ['admin_status' => $newStatus]);
        }
        // admin_status only — never touches is_suspended, so suspending the
        // admin hat leaves the person's rider/driver login and history intact.
        $pdo->prepare("UPDATE users SET admin_status = ? WHERE id = ?")->execute([$newStatus, $userId]);

        log_admin_action($pdo, $actorId, $suspend ? 'suspend_admin' : 'reactivate_admin', 'admin', $userId, $target['admin_status'], $newStatus, sanitize_input($input['reason'] ?? '') ?: null);
        json_response('success', $suspend ? 'Admin suspended' : 'Admin reactivated', ['admin_status' => $newStatus]);

    } elseif ($post_action === 'edit_subadmin') {
        $userId = (int)($input['user_id'] ?? 0);
        if ($userId <= 0) {
            json_response('error', 'A valid user ID is required.');
        }
        $target = fetch_admin_row($pdo, $userId);
        if (!$target || !$target['is_admin']) {
            json_response('error', 'Admin not found');
        }
        if ($target['is_super_admin']) {
            json_response('error', 'Super Admin profiles cannot be edited here.');
        }

        $fullname = sanitize_input($input['fullname'] ?? $target['fullname']);
        $email    = sanitize_input($input['email'] ?? $target['email']);
        $phone_raw= sanitize_input($input['phone'] ?? $target['phone']);
        if (strlen($fullname) < 3) {
            json_response('error', 'Full name must be at least 3 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_response('error', 'Please enter a valid email address.');
        }
        $phone = normalize_phone_number($phone_raw);
        if (!$phone) {
            json_response('error', 'Please enter a valid phone number.');
        }
        // Uniqueness excluding this account.
        $stmt = $pdo->prepare("SELECT id, email, phone FROM users WHERE (LOWER(email) = LOWER(?) OR phone = ?) AND id != ?");
        $stmt->execute([$email, $phone, $userId]);
        if ($existing = $stmt->fetch()) {
            json_response('error', strtolower($existing['email']) === strtolower($email)
                ? 'Email already in use' : 'Phone number already in use');
        }

        $pdo->prepare("UPDATE users SET fullname = ?, email = ?, phone = ? WHERE id = ?")->execute([$fullname, $email, $phone, $userId]);

        // Optional password reset when a non-empty password is supplied.
        if (($input['password'] ?? '') !== '') {
            $password = mt_scalar_string($input['password']);
            if (function_exists('password_meets_policy')) {
                $policyError = password_meets_policy($password);
                if ($policyError !== null) {
                    json_response('error', $policyError);
                }
            }
            $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($password, PASSWORD_DEFAULT), $userId]);
        }

        log_admin_action($pdo, $actorId, 'edit_subadmin', 'admin', $userId, null, null, null);
        json_response('success', 'Subadmin updated');

    } else {
        json_response('error', 'Invalid action');
    }

} else {
    json_response('error', 'Invalid request method');
}
