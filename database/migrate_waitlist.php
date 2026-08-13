<?php
// database/migrate_waitlist.php
// Phase 4 — Ride Waitlist System. Adds ride_waitlists: a passenger's
// standing request to be notified (never auto-booked) when a full
// ride opens a seat. Same self-healing, safe-to-re-run pattern as
// every other database/migrate_*.php in this project.
//
// No FK on ride_share_id — same reasoning as
// database/migrate_commission_reservations.php's ride_commission_reservations:
// admin's remove_ride_share hard-deletes ride_shares rows, and a
// waitlist entry should survive that as a demand-history record
// rather than being silently wiped by a cascade (release logic in
// includes/waitlist.php closes out active entries before the delete
// runs, same as release_ride_reservation() already does there).
// user_id DOES get a FK — same as ride_commission_reservations.driver_id
// — a waitlist entry has no meaning once the user account is gone.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "=== ride_waitlists ===\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ride_waitlists (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NOT NULL,
            ride_share_id INT NOT NULL,
            user_id INT NOT NULL,
            seats_requested INT NOT NULL DEFAULT 1,
            status ENUM('WAITING', 'NOTIFIED', 'RESERVED', 'CONVERTED', 'EXPIRED', 'CANCELLED') NOT NULL DEFAULT 'WAITING',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            notified_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            converted_booking_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE (public_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ");
    $indexCheck = $pdo->query("SHOW INDEX FROM ride_waitlists WHERE Key_name = 'idx_ride_status'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE ride_waitlists ADD INDEX idx_ride_status (ride_share_id, status)");
    }
    $indexCheck = $pdo->query("SHOW INDEX FROM ride_waitlists WHERE Key_name = 'idx_user'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE ride_waitlists ADD INDEX idx_user (user_id)");
    }
    echo "Ready.\n";

    echo "\nWaitlist migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
