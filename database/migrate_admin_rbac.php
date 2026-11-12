<?php
// database/migrate_admin_rbac.php
// Schema for the Admin Access-Control + Multi-Role Account System:
// the additive admin CAPABILITY flag (is_admin), the admin-scoped
// suspension state (admin_status), and the direct user->permission
// grant table (admin_permissions) that limits Subadmins. Safe to
// re-run: every step self-heals via SHOW COLUMNS/SHOW TABLES/SHOW INDEX
// checks, same pattern as migrate_pricing_center.php.
//
// Design note (why additive, not a new user_type): admin is a capability
// that coexists with rider/driver on ONE account, exactly like
// is_super_admin / is_keke_driver. users.user_type stays the account of
// record; $_SESSION['user_type'] is the current operating mode. This
// migration never alters an existing column and never touches money,
// pricing, verification, or ride data.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function column_exists_here($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    // ---- Admin capability flag ----
    // Decouples "is an admin" from the user_type ENUM so a rider/driver
    // can also be an admin without changing their account of record.
    echo "=== users.is_admin ===\n";
    if (!column_exists_here($pdo, 'users', 'is_admin')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added column.\n";
    } else {
        echo "Already exists.\n";
    }

    // ---- Admin-scoped suspension ----
    // Independent of is_suspended: suspending the ADMIN hat must never
    // block the same person's rider/driver login or wipe their history.
    echo "\n=== users.admin_status ===\n";
    if (!column_exists_here($pdo, 'users', 'admin_status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN admin_status ENUM('active','suspended') NOT NULL DEFAULT 'active'");
        echo "Added column.\n";
    } else {
        echo "Already exists.\n";
    }

    // ---- Direct user->permission grants (Subadmin scoping) ----
    // No catalog table: the permission catalog is a PHP constant in
    // includes/admin_permissions.php. Super Admins bypass this table
    // entirely (they are never limited by grants). granted_by records
    // WHO granted each permission for the audit story; ON DELETE CASCADE
    // means removing a user cleans up their grants automatically.
    echo "\n=== admin_permissions ===\n";
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
    echo "Ready.\n";

    // ---- Backfill existing admins ----
    // Every current user_type='admin' account (and any is_super_admin=1)
    // gets is_admin=1 so nobody loses access they already had. Built
    // conditionally: is_super_admin only exists if migrate_pricing_center
    // ran, so reference it only when present.
    echo "\n=== backfill is_admin ===\n";
    $conds = ["user_type = 'admin'"];
    if (column_exists_here($pdo, 'users', 'is_super_admin')) {
        $conds[] = 'is_super_admin = 1';
    }
    $affected = $pdo->exec("UPDATE users SET is_admin = 1 WHERE " . implode(' OR ', $conds));
    echo "Flagged existing admin accounts as is_admin (rows touched: " . (int)$affected . ").\n";

    echo "\nAdmin RBAC migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
