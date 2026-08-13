<?php
// database/migrate_trip_engine_phase3.php
// Intelligent Trip Engine — Phase 3 (Collaborative Driver & Passenger
// Intelligence). Purely additive on top of Phase 1/2
// (migrate_trip_engine.php, migrate_trip_engine_automation.php):
//   - ride_shares.trip_state and trip_state_events are untouched except
//     for one additive ENUM value on trigger_type.
//   - ride_share_bookings gets its own granular passenger_state layer,
//     exactly the same relationship trip_state already has to
//     ride_shares.status — the legacy status column keeps meaning
//     exactly what it always has.
//   - booking_state_events is the per-passenger sibling of
//     trip_state_events, same shape, same audit philosophy.
// Safe to re-run: every step is a SHOW COLUMNS/SHOW INDEX guarded check.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "Adding users.driver_tier (Standard max-active-rides cap vs Premium unlimited)...\n";
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'driver_tier'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN driver_tier ENUM('standard','premium') NOT NULL DEFAULT 'standard' AFTER user_type");
    }

    echo "Adding passenger-intelligence columns to ride_share_bookings...\n";
    $columns = [
        'passenger_state' => "ENUM('booked','navigating','arrived','boarded','dropped_off','completed','no_show','cancelled') NOT NULL DEFAULT 'booked'",
        'passenger_state_updated_at' => 'TIMESTAMP NULL',
        'passenger_latitude' => 'DECIMAL(10,8) NULL',
        'passenger_longitude' => 'DECIMAL(11,8) NULL',
        'passenger_last_location_update' => 'TIMESTAMP NULL',
        'passenger_last_speed' => 'DECIMAL(6,2) NULL',
        'passenger_gps_accuracy' => 'DECIMAL(6,2) NULL',
        'no_show_deadline' => 'TIMESTAMP NULL',
        'last_confidence_score' => 'TINYINT UNSIGNED NULL',
        'arrived_at' => 'TIMESTAMP NULL',
        'boarded_at' => 'TIMESTAMP NULL',
        'dropped_off_at' => 'TIMESTAMP NULL',
    ];
    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM ride_share_bookings LIKE '$col'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE ride_share_bookings ADD COLUMN $col $definition");
        }
    }

    echo "Creating booking_state_events (per-passenger audit trail, mirrors trip_state_events)...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS booking_state_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NULL,
            booking_id INT NOT NULL,
            ride_share_id INT NOT NULL,
            from_state VARCHAR(30) NULL,
            to_state VARCHAR(30) NOT NULL,
            trigger_type ENUM(
                'manual','geofence','system','driver_action',
                'SYSTEM_GPS_ARRIVE','SYSTEM_GPS_BOARD','SYSTEM_GPS_DROP',
                'MANUAL_RIDER_ACTION','MANUAL_DRIVER_ACTION',
                'NO_SHOW_WAIT','NO_SHOW_SKIP','NO_SHOW_CANCEL'
            ) NOT NULL DEFAULT 'manual',
            confidence_score TINYINT UNSIGNED NULL,
            meta JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_booking (booking_id),
            INDEX idx_ride (ride_share_id)
        )
    ");

    $check = $pdo->query("SHOW COLUMNS FROM booking_state_events LIKE 'public_id'");
    if ($check->fetch()) {
        $stmt = $pdo->query("SELECT id FROM booking_state_events WHERE public_id IS NULL OR public_id = ''");
        foreach ($stmt->fetchAll() as $row) {
            $publicId = generate_unique_public_id($pdo, 'booking_state_events', 'public_id', 'BSE');
            $pdo->prepare("UPDATE booking_state_events SET public_id = ? WHERE id = ?")->execute([$publicId, $row['id']]);
        }
        $indexCheck = $pdo->query("SHOW INDEX FROM booking_state_events WHERE Key_name = 'public_id'");
        if (!$indexCheck->fetch()) {
            $pdo->exec("ALTER TABLE booking_state_events ADD UNIQUE (public_id)");
        }
    }

    // Bug found while testing Phase 3, pre-dating it entirely:
    // includes/functions.php's mark_ride_started() (used by BOTH the
    // existing manual start_ride action and the existing GPS auto-start)
    // sets ride_share_bookings.status = 'in_progress', but that value was
    // never actually in this column's ENUM — non-strict SQL mode silently
    // stores '' instead of raising an error. This corrupts every booking
    // on every ride that has ever started, breaking any query that
    // expects status IN ('confirmed','in_progress') once a trip is under
    // way (including the new rider-side booking lookup added above).
    // Purely additive/corrective: widens the enum to the value the code
    // already intended, then repairs rows already silently blanked.
    echo "Widening ride_share_bookings.status to include the already-in-use 'in_progress' value...\n";
    $pdo->exec("ALTER TABLE ride_share_bookings MODIFY COLUMN status ENUM('pending','confirmed','in_progress','cancelled','completed') NOT NULL DEFAULT 'confirmed'");

    echo "Repairing bookings silently blanked by that bug (status='' on a ride whose trip_state shows it started)...\n";
    $pdo->exec("
        UPDATE ride_share_bookings rb
        JOIN ride_shares rs ON rs.id = rb.ride_share_id
        SET rb.status = 'in_progress'
        WHERE rb.status = '' AND rs.trip_state IN ('TRIP_STARTED', 'PASSENGER_DROPPING', 'AUTO_COMPLETED_PENDING')
    ");
    $pdo->exec("
        UPDATE ride_share_bookings rb
        JOIN ride_shares rs ON rs.id = rb.ride_share_id
        SET rb.status = 'completed'
        WHERE rb.status = '' AND rs.trip_state = 'TRIP_COMPLETED'
    ");

    echo "Widening trip_state_events.trigger_type with the collaborative-completion event type...\n";
    $pdo->exec("
        ALTER TABLE trip_state_events MODIFY COLUMN trigger_type
        ENUM('manual','geofence','system','SYSTEM_GPS_START','SYSTEM_GPS_DROP','SYSTEM_GPS_COMPLETE','MANUAL_DRIVER_ACTION','ALL_PASSENGERS_COMPLETE')
        NOT NULL DEFAULT 'manual'
    ");

    echo "Trip Engine Phase 3 migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
