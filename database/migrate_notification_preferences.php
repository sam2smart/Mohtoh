<?php
// database/migrate_notification_preferences.php
// Account & Settings module: the Notifications screen exposes 6
// categories (Ride notifications, Trip reminders, Wallet notifications,
// Payment alerts, Promotions, System announcements). Two already map
// cleanly onto existing columns (notify_on_booking, notify_ride_start —
// left untouched, still used exactly as before by mark_ride_started()
// and the booking flow). The other four have no existing equivalent,
// so this adds them, defaulting to enabled like every other notify_*
// column.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting notification preferences migration...\n";

    $columns = [
        'notify_wallet' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notify_payment_alerts' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notify_promotions' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'notify_system' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ];

    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if (!$check->fetch()) {
            echo "Adding $col...\n";
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $definition");
        } else {
            echo "$col already exists, skipping.\n";
        }
    }

    echo "Notification preferences migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
