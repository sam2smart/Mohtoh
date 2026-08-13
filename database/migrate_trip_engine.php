<?php
// database/migrate_trip_engine.php
// Foundation for the Intelligent Trip Engine: a granular trip_state on
// ride_shares (alongside the existing status column, which nothing
// existing has to stop using) plus an event log, and an audit column
// on locations for who approved/rejected a suggestion.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting trip engine migration...\n";

    echo "Adding trip_state columns to ride_shares...\n";
    $check = $pdo->query("SHOW COLUMNS FROM ride_shares LIKE 'trip_state'");
    if (!$check->fetch()) {
        $pdo->exec("
            ALTER TABLE ride_shares
                ADD COLUMN trip_state ENUM('CREATED','PUBLISHED','PASSENGERS_JOINING','PASSENGERS_ASSEMBLED','TRIP_STARTED','PASSENGER_DROPPING','TRIP_COMPLETED','CANCELLED') NOT NULL DEFAULT 'CREATED',
                ADD COLUMN trip_state_updated_at TIMESTAMP NULL DEFAULT NULL
        ");
    }

    echo "Creating trip_state_events table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trip_state_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ride_share_id INT NOT NULL,
            from_state VARCHAR(30) NULL,
            to_state VARCHAR(30) NOT NULL,
            trigger_type ENUM('manual','geofence','system') NOT NULL DEFAULT 'manual',
            meta JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ride_share_id) REFERENCES ride_shares(id) ON DELETE CASCADE,
            INDEX idx_trip_state_events_ride (ride_share_id)
        )
    ");

    echo "Backfilling trip_state for existing rides from their current status...\n";
    $pdo->exec("UPDATE ride_shares SET trip_state = 'PUBLISHED' WHERE status IN ('active','full') AND trip_state = 'CREATED'");
    $pdo->exec("UPDATE ride_shares SET trip_state = 'TRIP_STARTED' WHERE status = 'in_progress' AND trip_state = 'CREATED'");
    $pdo->exec("UPDATE ride_shares SET trip_state = 'TRIP_COMPLETED' WHERE status = 'completed' AND trip_state = 'CREATED'");
    $pdo->exec("UPDATE ride_shares SET trip_state = 'CANCELLED' WHERE status = 'cancelled' AND trip_state = 'CREATED'");

    echo "Adding approved_by to locations...\n";
    $check = $pdo->query("SHOW COLUMNS FROM locations LIKE 'approved_by'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE locations ADD COLUMN approved_by INT NULL AFTER created_by");
        $pdo->exec("ALTER TABLE locations ADD CONSTRAINT fk_locations_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL");
    }

    echo "Trip engine migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
