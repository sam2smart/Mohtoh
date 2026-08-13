<?php
// database/migrate_public_ids.php
// Generalized rollout of the public-ID pattern introduced by
// migrate_public_user_id.php to every other entity that needs one.
// Adds a public-facing identifier column alongside each table's existing
// numeric `id` primary key (which stays the FK used everywhere else,
// untouched), backfills existing rows, then enforces uniqueness once
// every row has a value. Safe to re-run: every step self-heals via
// SHOW COLUMNS / SHOW INDEX checks, same pattern as
// migrate_public_user_id.php and includes/db.php's lazy migrations.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// [table, column, prefix]. call_sessions is deliberately excluded — its
// call_id column already exists, is NOT NULL, and is now populated via
// generate_unique_public_id() at creation (see api/call-management.php).
$targets = [
    ['vehicles', 'public_id', 'VEH'],
    ['vehicle_verifications', 'public_id', 'VER'],
    ['vehicle_edit_requests', 'public_id', 'EDT'],
    ['vehicle_review_logs', 'public_id', 'RVL'],
    ['ride_shares', 'public_id', 'RIDE'],
    ['ride_share_bookings', 'public_id', 'BKG'],
    ['ride_share_ratings', 'public_id', 'RATING'],
    ['payments', 'public_id', 'PAY'],
    ['wallet_transactions', 'reference', 'TXN'],
    ['notifications', 'public_id', 'NOTIF'],
    ['locations', 'public_id', 'LOC'],
    ['admin_user_actions', 'public_id', 'ACT'],
    ['driver_ratings', 'public_id', 'DRT'],
    ['passenger_ratings', 'public_id', 'PRT'],
];

$failures = [];

try {
    foreach ($targets as [$table, $column, $prefix]) {
        echo "=== $table.$column ($prefix) ===\n";

        $check = $pdo->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if (!$check->fetch()) {
            echo "Adding $column column to $table...\n";
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` VARCHAR(30) NULL");
        }

        $stmt = $pdo->query("SELECT id FROM `$table` WHERE `$column` IS NULL OR `$column` = ''");
        $rows = $stmt->fetchAll();

        if ($rows) {
            echo "Backfilling " . count($rows) . " row(s)...\n";
            foreach ($rows as $row) {
                $publicId = generate_unique_public_id($pdo, $table, $column, $prefix);
                $update = $pdo->prepare("UPDATE `$table` SET `$column` = ? WHERE id = ?");
                $update->execute([$publicId, $row['id']]);
                echo "  id {$row['id']} -> $publicId\n";
            }
        } else {
            echo "Nothing to backfill.\n";
        }

        $indexCheck = $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = '$column'");
        if (!$indexCheck->fetch()) {
            echo "Adding UNIQUE constraint on $column...\n";
            $pdo->exec("ALTER TABLE `$table` ADD UNIQUE (`$column`)");
        }
        echo "\n";
    }

    echo "=== Duplicate check ===\n";
    foreach ($targets as [$table, $column, $prefix]) {
        $dupes = $pdo->query("SELECT `$column`, COUNT(*) c FROM `$table` GROUP BY `$column` HAVING c > 1")->fetchAll();
        if ($dupes) {
            $failures[] = "$table.$column";
            echo "FAIL: $table.$column has " . count($dupes) . " duplicate value(s)\n";
        } else {
            echo "PASS: $table.$column\n";
        }
    }

    if ($failures) {
        echo "\nMigration completed with duplicate-check failures: " . implode(', ', $failures) . "\n";
        exit(1);
    }

    echo "\nPublic ID migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
