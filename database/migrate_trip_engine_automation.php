<?php
// database/migrate_trip_engine_automation.php
// Phase 2 of the Intelligent Trip Engine: GPS-driven automatic
// transitions. Adds live telemetry fields to ride_shares, a staging
// state (AUTO_COMPLETED_PENDING) that never releases payment on its
// own, and the more specific event-trigger vocabulary.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting trip engine automation migration...\n";

    echo "Adding trip monitoring columns to ride_shares...\n";
    $columns = [
        'current_latitude' => 'DECIMAL(10,8) NULL',
        'current_longitude' => 'DECIMAL(11,8) NULL',
        'last_speed' => 'DECIMAL(6,2) NULL',
        'last_location_update' => 'TIMESTAMP NULL DEFAULT NULL',
        'auto_started' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'auto_completed' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'completion_confidence' => 'TINYINT UNSIGNED NULL',
    ];
    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE '$col'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE ride_shares ADD COLUMN $col $definition");
        }
    }

    echo "Adding AUTO_COMPLETED_PENDING to trip_state...\n";
    $pdo->exec("
        ALTER TABLE ride_shares MODIFY COLUMN trip_state
        ENUM('CREATED','PUBLISHED','PASSENGERS_JOINING','PASSENGERS_ASSEMBLED','TRIP_STARTED',
             'PASSENGER_DROPPING','AUTO_COMPLETED_PENDING','TRIP_COMPLETED','CANCELLED')
        NOT NULL DEFAULT 'CREATED'
    ");

    echo "Widening trip_state_events.trigger_type with the GPS-specific event types...\n";
    $pdo->exec("
        ALTER TABLE trip_state_events MODIFY COLUMN trigger_type
        ENUM('manual','geofence','system','SYSTEM_GPS_START','SYSTEM_GPS_DROP','SYSTEM_GPS_COMPLETE','MANUAL_DRIVER_ACTION')
        NOT NULL DEFAULT 'manual'
    ");

    echo "Trip engine automation migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
