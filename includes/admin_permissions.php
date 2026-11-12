<?php
// includes/admin_permissions.php
// Admin Access-Control core: the permission catalog, the fresh-from-DB
// admin context, and the fail-closed server-side gates every protected
// admin endpoint runs. This is the single source of truth for "can this
// admin do this" — menu visibility in the browser is cosmetic; THIS file
// is the authorization.
//
// Model (see database/migrate_admin_rbac.php):
//   * is_admin        — admin CAPABILITY, additive to user_type.
//   * is_super_admin  — full control; bypasses every permission check.
//   * admin_status    — 'active' | 'suspended' (admin hat only).
//   * admin_permissions table — direct user->permission grants that scope
//     a Subadmin. Super Admins are never limited by it.
//
// Authorization order enforced here (spec): authenticated user -> admin
// capability -> account status -> required permission. Nothing trusts a
// role/permission value supplied by the browser; every check re-reads the
// database, so a permission change or suspension takes effect on the very
// next request with no re-login.

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------------
// Permission catalog — GRANTABLE to Subadmins, grouped for the management UI.
// Keys are stable identifiers used in code + the admin_permissions table;
// labels/descriptions are display-only. Anything NOT listed here is, by
// definition, Super-Admin-only and can never be granted to a Subadmin:
//   * every money/config write (pricing/promotions/integrations/premium
//     approve-reject-disable-delete-save, regional pricing, commission),
//   * the entire Administration group (admins.*).
// This is why grantable permissions are strictly view + operational and
// no grantable permission can ever move money.
// ---------------------------------------------------------------------------
if (!defined('ADMIN_PERMISSIONS')) {
    define('ADMIN_PERMISSIONS', [
        'Dashboard' => [
            'dashboard.view' => 'View the admin dashboard',
        ],
        'Users' => [
            'users.view'    => 'View user profiles, reviews & activity',
            'users.suspend' => 'Suspend / unsuspend & force offline',
            'users.manage'  => 'Resend verification, clear login lockouts',
        ],
        'Drivers' => [
            'drivers.view'    => 'View drivers',
            'drivers.approve' => 'Approve / verify drivers',
            'drivers.manage'  => 'Set driver tier & driver settings',
        ],
        'Vehicles' => [
            'vehicles.view'   => 'View vehicles & documents',
            'vehicles.verify' => 'Approve / suspend vehicles',
            'vehicles.reject' => 'Reject / delete vehicles',
        ],
        'Rides' => [
            'rides.view'   => 'View rides, requests & the queue',
            'rides.manage' => 'Edit / complete / remove rides',
            'rides.cancel' => 'Cancel rides & bookings',
        ],
        'Locations' => [
            'locations.manage' => 'Approve / reject locations',
        ],
        'Reports' => [
            'reports.view'    => 'View reports, analytics & referrals',
            'audit_logs.view' => 'View the audit trail',
        ],
        'Communications' => [
            'announcements.send' => 'Send announcements & driver comms',
            'chat.moderate'      => 'Monitor & moderate chats',
        ],
        'Configuration (read-only)' => [
            'pricing.view'      => 'View pricing & fare settings',
            'promotions.view'   => 'View promotions',
            'integrations.view' => 'View integration settings',
            'premium.view'      => 'View premium requests & features',
        ],
        // MOHTOH NETWORK — mirrors the spec's role list (section 12):
        // Network Manager (network.partners.manage) and Finance Admin
        // (network.finance.manage) are grantable; Super Admin bypasses
        // every check as usual and is the ONLY one who can touch
        // programme settings/reward config/campaigns (api/network-admin.php
        // gates those with require_super_admin(), same as pricing/
        // promotions/commission — deliberately NOT in this catalog).
        'MOHTOH NETWORK' => [
            'network.view'             => 'View MOHTOH NETWORK overview, partners, referrals & rewards (read-only)',
            'network.partners.manage'  => 'Approve/suspend Network Partners, correct referral attribution',
            'network.finance.manage'   => 'Reconcile rewards, process withdrawals, resolve fraud flags',
        ],
    ]);
}

// Action -> permission maps for the two api/admin-api.php choke points.
// Every mutating POST action and every GET action is listed. An action
// absent from a map resolves to the '__deny__' sentinel at the call site
// (fail-closed: no Subadmin holds it; Super Admins bypass anyway).
//
// delete_user maps to the non-grantable Administration sentinel
// 'admins.remove' so it is Super-Admin-only through the choke point too;
// its handler adds require_super_admin() + an admin-target guard as a
// second, independent layer.
if (!defined('ADMIN_API_POST_PERMS')) {
    define('ADMIN_API_POST_PERMS', [
        'delete_user'         => 'admins.remove',   // Super-only sentinel + in-handler guard
        'toggle_user_status'  => 'users.suspend',
        'approve_driver'      => 'drivers.approve',
        'verify_driver'       => 'drivers.approve',
        'suspend_user'        => 'users.suspend',
        'unsuspend_user'      => 'users.suspend',
        'set_driver_tier'     => 'drivers.manage',
        'resend_verification' => 'users.manage',
        'unlock_login_lockout'=> 'users.manage',
        'approve_vehicle'     => 'vehicles.verify',
        'suspend_vehicle'     => 'vehicles.verify',
        'reject_vehicle'      => 'vehicles.reject',
        'delete_vehicle'      => 'vehicles.reject',
        'update_ride_share'   => 'rides.manage',
        'complete_ride_share' => 'rides.manage',
        'remove_ride_share'   => 'rides.manage',
        'cancel_ride_share'   => 'rides.cancel',
        'cancel_booking'      => 'rides.cancel',
        'send_announcement'   => 'announcements.send',
        'approve_location'    => 'locations.manage',
        'reject_location'     => 'locations.manage',
        'resolve_chat_report' => 'chat.moderate',
        'delete_chat_report'  => 'chat.moderate',
        // update_settings / update_pricing_settings are retired stubs that
        // only return an error — intentionally unmapped (default-deny).
    ]);
}
if (!defined('ADMIN_API_GET_PERMS')) {
    define('ADMIN_API_GET_PERMS', [
        'get_dashboard_data'   => 'dashboard.view', // baseline — any active admin
        'get_audit_logs'       => 'audit_logs.view',
        'get_vehicles'         => 'vehicles.view',
        'vehicle_details'      => 'vehicles.view',
        'user_profile_reviews' => 'users.view',
        'list_reported_chats'  => 'chat.moderate',
        'search_conversations' => 'chat.moderate',
        'view_conversation'    => 'chat.moderate',
        'referral_analytics'   => 'reports.view',
        'list_referrals'       => 'reports.view',
    ]);
}

// The implicit baseline permission every active admin holds without an
// explicit grant. It cannot be accidentally removed (it is not stored).
if (!defined('ADMIN_BASELINE_PERMISSION')) {
    define('ADMIN_BASELINE_PERMISSION', 'dashboard.view');
}

// ---------------------------------------------------------------------------
// Schema self-healing. Distinct helper names (admin_rbac_*) so this file
// can be included alongside api/admin-api.php, which declares its own
// table_exists()/column_exists(). Runs its SHOW checks once per request.
// ---------------------------------------------------------------------------
function admin_rbac_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

function admin_rbac_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function ensure_admin_rbac_schema($pdo) {
    static $ensured = false;
    if ($ensured) {
        return;
    }
    // Guarantee every column the context SELECT reads, so this include is
    // safe even on a database where migrate_admin_rbac.php was never run
    // (and where migrate_pricing_center.php's is_super_admin is absent).
    if (!admin_rbac_column_exists($pdo, 'users', 'is_super_admin')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!admin_rbac_column_exists($pdo, 'users', 'is_suspended')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_suspended TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!admin_rbac_column_exists($pdo, 'users', 'is_admin')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        // First-time heal: don't strand existing admins without the flag.
        $pdo->exec("UPDATE users SET is_admin = 1 WHERE user_type = 'admin' OR is_super_admin = 1");
    }
    if (!admin_rbac_column_exists($pdo, 'users', 'admin_status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_status ENUM('active','suspended') NOT NULL DEFAULT 'active'");
    }
    if (!admin_rbac_table_exists($pdo, 'admin_permissions')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS admin_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                permission VARCHAR(64) NOT NULL,
                granted_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, permission),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (granted_by) REFERENCES users(id) ON DELETE SET NULL
            )
        ");
    }
    $ensured = true;
}

// ---------------------------------------------------------------------------
// Catalog helpers
// ---------------------------------------------------------------------------

// Flat list of every grantable permission key (across all groups).
function admin_grantable_permissions() {
    $flat = [];
    foreach (ADMIN_PERMISSIONS as $group => $perms) {
        foreach ($perms as $key => $label) {
            $flat[] = $key;
        }
    }
    return $flat;
}

// True only for permissions a Super Admin may grant to a Subadmin. This is
// the guard that stops privilege escalation via set_permissions: super/
// Administration/money-config keys are simply not in the catalog.
function admin_permission_is_grantable($perm) {
    return in_array($perm, admin_grantable_permissions(), true);
}

// ---------------------------------------------------------------------------
// Fresh-from-DB admin context. Never cached across requests — this IS the
// session-invalidation mechanism (mirrors require_super_admin's live read).
// $userId defaults to the logged-in user. Returns a stable shape even for
// non-admins (is_admin=false) so callers can branch safely.
// ---------------------------------------------------------------------------
function get_admin_context($pdo, $userId = null) {
    ensure_admin_rbac_schema($pdo);

    $userId = $userId !== null ? (int)$userId : (int)($_SESSION['user_id'] ?? 0);
    $ctx = [
        'user_id'        => $userId,
        'is_admin'       => false,
        'is_super_admin' => false,
        'admin_status'   => 'active',
        'is_suspended'   => false,
        'permissions'    => [], // associative: ['perm' => true]
    ];
    if ($userId <= 0) {
        return $ctx;
    }

    $stmt = $pdo->prepare("SELECT is_admin, is_super_admin, admin_status, is_suspended FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return $ctx;
    }

    $ctx['is_admin']       = (bool)$row['is_admin'];
    $ctx['is_super_admin'] = (bool)$row['is_super_admin'];
    $ctx['admin_status']   = $row['admin_status'] ?: 'active';
    $ctx['is_suspended']   = (bool)$row['is_suspended'];

    // Explicit grants only matter for a non-super admin; a super bypasses
    // every check, so we skip the extra read for them.
    if ($ctx['is_admin'] && !$ctx['is_super_admin']) {
        $stmt = $pdo->prepare("SELECT permission FROM admin_permissions WHERE user_id = ?");
        $stmt->execute([$userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $perm) {
            $ctx['permissions'][$perm] = true;
        }
    }

    return $ctx;
}

// Does the CURRENT (or supplied) admin hold $perm? Super => everything;
// the baseline permission is implicit for any admin; otherwise it must be
// an explicit grant. Pass $ctx to avoid a second DB read.
function current_admin_can($pdo, $perm, $ctx = null) {
    if ($ctx === null) {
        $ctx = get_admin_context($pdo);
    }
    if (!$ctx['is_admin']) {
        return false;
    }
    if ($ctx['is_super_admin']) {
        return true;
    }
    if ($perm === ADMIN_BASELINE_PERMISSION) {
        return true;
    }
    return !empty($ctx['permissions'][$perm]);
}

// ---------------------------------------------------------------------------
// Gates. Both fail closed and terminate the request via json_response() on
// denial (same contract as require_super_admin). Both RETURN the context on
// success so a caller can reuse it without another DB read.
// ---------------------------------------------------------------------------

// Condition 1-3: authenticated + admin capability + active + not suspended,
// AND currently operating in admin mode ($_SESSION['user_type']==='admin').
// The session-mode check keeps a driver/rider who merely HAS admin capability
// from hitting admin endpoints while acting as a driver — they must switch to
// admin mode first (which re-runs these same checks).
function require_admin_access($pdo) {
    if (!is_logged_in() || ($_SESSION['user_type'] ?? '') !== 'admin') {
        json_response('error', 'Unauthorized access');
    }
    $ctx = get_admin_context($pdo);
    if (!$ctx['is_admin'] || $ctx['admin_status'] !== 'active' || $ctx['is_suspended']) {
        json_response('error', 'Your admin access is not active.');
    }
    return $ctx;
}

// Condition 4: the specific permission. Runs require_admin_access first, so
// a single call enforces the full authenticated->capability->status->
// permission chain the spec requires.
function require_admin_permission($pdo, $perm) {
    $ctx = require_admin_access($pdo);
    if (!current_admin_can($pdo, $perm, $ctx)) {
        json_response('error', 'You do not have permission to perform this action.');
    }
    return $ctx;
}
