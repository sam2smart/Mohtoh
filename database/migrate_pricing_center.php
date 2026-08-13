<?php
// database/migrate_pricing_center.php
// Schema for the Admin Pricing & Driver Management module: the
// Super Admin flag, the pricing-change audit trail, regions,
// promotions, driver bonuses, and the new users/ride_shares columns
// the extended price engine (includes/functions.php) reads. Safe to
// re-run: every step self-heals via SHOW COLUMNS/SHOW TABLES checks,
// same pattern as migrate_public_ids.php.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function column_exists_here($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    // ---- Foundation: Super Admin flag ----
    // Every user_type='admin' account today has identical, undifferentiated
    // access — this migration flags all of them as super admins so nobody
    // loses capability they already had. Only admin accounts created AFTER
    // this migration default to is_super_admin=0 and need an explicit grant.
    echo "=== users.is_super_admin ===\n";
    if (!column_exists_here($pdo, 'users', 'is_super_admin')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("UPDATE users SET is_super_admin = 1 WHERE user_type = 'admin'");
        echo "Added column and flagged all existing admins as super admins.\n";
    } else {
        echo "Already exists.\n";
    }

    // ---- Foundation: fix admin_user_actions.action (was a hard ENUM that
    // api/admin-api.php's vehicle approve/reject already violates live) ----
    echo "\n=== admin_user_actions.action ===\n";
    $stmt = $pdo->query("SHOW COLUMNS FROM admin_user_actions LIKE 'action'");
    $col = $stmt->fetch();
    if ($col && stripos($col['Type'], 'enum') === 0) {
        $pdo->exec("ALTER TABLE admin_user_actions MODIFY action VARCHAR(50) NOT NULL DEFAULT 'note'");
        echo "Converted from ENUM to VARCHAR(50).\n";
    } else {
        echo "Already VARCHAR.\n";
    }

    // ---- Foundation: pricing_change_logs ----
    echo "\n=== pricing_change_logs ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pricing_change_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            old_value TEXT NULL,
            new_value TEXT NULL,
            ip_address VARCHAR(45) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            public_id VARCHAR(30) NULL,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    if (!column_exists_here($pdo, 'pricing_change_logs', 'public_id')) {
        $pdo->exec("ALTER TABLE pricing_change_logs ADD COLUMN public_id VARCHAR(30) NULL");
    }
    $indexCheck = $pdo->query("SHOW INDEX FROM pricing_change_logs WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE pricing_change_logs ADD UNIQUE (public_id)");
    }
    echo "Ready.\n";

    // ---- Section 2: per-driver commission + referral columns ----
    echo "\n=== users driver-settings columns ===\n";
    if (!column_exists_here($pdo, 'users', 'custom_commission_percentage')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN custom_commission_percentage DECIMAL(5,2) NULL");
        echo "Added custom_commission_percentage.\n";
    }
    if (!column_exists_here($pdo, 'users', 'referral_code')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN referral_code VARCHAR(20) NULL");
        $pdo->exec("ALTER TABLE users ADD UNIQUE (referral_code)");
        echo "Added referral_code.\n";
    }
    if (!column_exists_here($pdo, 'users', 'referred_by')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN referred_by INT NULL");
        echo "Added referred_by.\n";
    }

    // ---- Section 2: driver_bonuses ----
    echo "\n=== driver_bonuses ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS driver_bonuses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            driver_id INT NOT NULL,
            admin_id INT NOT NULL,
            type ENUM('bonus', 'referral_reward') NOT NULL DEFAULT 'bonus',
            amount DECIMAL(10,2) NOT NULL,
            reason TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            public_id VARCHAR(30) NULL,
            FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM driver_bonuses WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE driver_bonuses ADD UNIQUE (public_id)");
    }
    echo "Ready.\n";

    // ---- Section 3: fare breakdown on ride_shares ----
    echo "\n=== ride_shares.fare_breakdown ===\n";
    if (!column_exists_here($pdo, 'ride_shares', 'fare_breakdown')) {
        $pdo->exec("ALTER TABLE ride_shares ADD COLUMN fare_breakdown TEXT NULL");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    // ---- Section 5: regions ----
    echo "\n=== regions ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS regions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            center_lat DECIMAL(10,8) NOT NULL,
            center_lng DECIMAL(11,8) NOT NULL,
            radius_km DECIMAL(6,2) NOT NULL,
            price_per_km DECIMAL(10,2) NULL,
            base_fare DECIMAL(10,2) NULL,
            commission_percentage DECIMAL(5,2) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            public_id VARCHAR(30) NULL
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM regions WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE regions ADD UNIQUE (public_id)");
    }
    echo "Ready.\n";

    // ---- Section 6: promotions ----
    echo "\n=== promo_codes / promo_redemptions ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS promo_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(30) NOT NULL,
            type ENUM('percentage', 'fixed') NOT NULL,
            value DECIMAL(10,2) NOT NULL,
            applies_to ENUM('all', 'first_ride') NOT NULL DEFAULT 'all',
            max_uses INT NULL,
            per_user_limit INT NOT NULL DEFAULT 1,
            valid_from DATE NULL,
            valid_until DATE NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_admin_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            public_id VARCHAR(30) NULL,
            UNIQUE (code),
            FOREIGN KEY (created_by_admin_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM promo_codes WHERE Key_name = 'public_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE promo_codes ADD UNIQUE (public_id)");
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS promo_redemptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            promo_id INT NOT NULL,
            user_id INT NOT NULL,
            booking_id INT NULL,
            applied_amount DECIMAL(10,2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (promo_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (booking_id) REFERENCES ride_share_bookings(id) ON DELETE SET NULL
        )
    ");
    echo "Ready.\n";

    // ---- Seed default pricing-center settings (only if absent — never
    // overwrites a value an admin or earlier migration already set) ----
    echo "\n=== system_settings defaults ===\n";
    $defaults = [
        // These two already exist on databases where the older
        // database/migrate_call_system.php happened to run (it seeds
        // them as a side effect of an unrelated call-session feature);
        // INSERT IGNORE below leaves any such existing value untouched.
        // On databases where it never ran, this is what actually seeds
        // them for the Pricing Center.
        'base_fare' => '0',
        'minimum_fare' => '5.00',
        'price_per_minute' => '0',
        'maximum_fare' => '0',
        'booking_fee' => '0',
        'platform_service_fee' => '0',
        'rider_service_charge' => '0',
        'cancellation_fee' => '0',
        'waiting_charge' => '0',
        'night_fare_multiplier' => '1.0',
        'night_start_hour' => '22',
        'night_end_hour' => '6',
        'peak_hour_multiplier' => '1.0',
        'peak_start_hour' => '7',
        'peak_end_hour' => '9',
        'holiday_multiplier' => '1.0',
        'holiday_dates' => '',
        'min_wallet_balance' => '0',
        'max_active_rides_per_driver' => '0',
        'require_vehicle_documents' => '1',
        'pricing_mode' => 'hybrid',
    ];
    $insert = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $insert->execute([$key, $value]);
    }
    echo "Seeded " . count($defaults) . " default key(s) (existing values left untouched).\n";

    echo "\nPricing Center migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
