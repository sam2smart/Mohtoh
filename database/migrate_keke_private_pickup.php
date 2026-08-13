<?php
// database/migrate_keke_private_pickup.php
//
// Phase 6: Keke Private Pickup foundation.
// Adds driver capability/availability flags, extends ride_requests into a
// private-pickup request queue, adds per-document verification review fields
// + soft-delete + EXPIRED support, and registers the configurable pickup
// radius setting.
//
// SAFE + IDEMPOTENT: every ALTER is guarded against information_schema, so
// this can be re-run without error and never destroys existing data. No
// duplicate tables are created - private pickup reuses ride_requests, keke is
// classified via the existing vehicles.vehicle_type enum.
//
// NOTE: DDL (ALTER TABLE) implicitly commits in MariaDB, so no wrapping
// transaction is used - the information_schema guards are what make re-runs
// safe, statement by statement.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$DB = 'merit_drives';

/** Add a column only if it does not already exist. */
function col_add(PDO $pdo, string $db, string $table, string $column, string $definition): void {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$db, $table, $column]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        echo "  + {$table}.{$column} added\n";
    } else {
        echo "  = {$table}.{$column} already present\n";
    }
}

/** Add an index only if it does not already exist. */
function idx_add(PDO $pdo, string $db, string $table, string $index, string $definition): void {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?"
    );
    $stmt->execute([$db, $table, $index]);
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD {$definition}");
        echo "  + {$table} index {$index} added\n";
    } else {
        echo "  = {$table} index {$index} already present\n";
    }
}

/** Widen an ENUM to include new members, preserving existing ones. */
function enum_ensure(PDO $pdo, string $db, string $table, string $column, string $newDefinition): void {
    $stmt = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$db, $table, $column]);
    $current = (string)$stmt->fetchColumn();
    if ($current === '') {
        echo "  ! {$table}.{$column} not found - skipping enum widen\n";
        return;
    }
    // Only modify when the value list actually differs, to stay idempotent.
    // COLUMN_TYPE holds just the type ("enum('a','b')") with no DEFAULT/NOT
    // NULL clause, so compare against the type portion of the target
    // definition only - comparing the whole definition string would never
    // match and would re-ALTER the table on every run.
    $targetType = substr($newDefinition, strpos($newDefinition, '('));
    $targetType = 'enum' . strtolower(preg_replace('/\s+/', '', substr($targetType, 0, strrpos($targetType, ')') + 1)));
    $normalizedCurrent = strtolower(preg_replace('/\s+/', '', $current));
    if ($normalizedCurrent !== $targetType) {
        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$newDefinition}");
        echo "  ~ {$table}.{$column} enum ensured\n";
    } else {
        echo "  = {$table}.{$column} enum already current\n";
    }
}

try {
    echo "Phase 6: Keke Private Pickup migration starting...\n";

    // ------------------------------------------------------------------
    // 1. Driver capability + availability flags on users.
    //    These are SEPARATE operational states - join_ride_enabled keeps
    //    Join Ride working regardless of online/private-pickup state.
    //    Keke classification itself is derived from the driver's vehicle
    //    (vehicles.vehicle_type = 'keke'); is_keke_driver is a fast cached
    //    flag kept in sync with that vehicle for querying/matching.
    // ------------------------------------------------------------------
    echo "[1] users capability/availability flags\n";
    col_add($pdo, $DB, 'users', 'is_keke_driver',
        "is_keke_driver TINYINT(1) NOT NULL DEFAULT 0 AFTER driver_tier");
    col_add($pdo, $DB, 'users', 'join_ride_enabled',
        "join_ride_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER is_keke_driver");
    col_add($pdo, $DB, 'users', 'private_pickup_enabled',
        "private_pickup_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER join_ride_enabled");
    // Daily availability opt-in for private pickup: the flag plus the date it
    // was set, so a stale yes from a previous day does not carry over.
    col_add($pdo, $DB, 'users', 'pickup_available_today',
        "pickup_available_today TINYINT(1) NOT NULL DEFAULT 0 AFTER private_pickup_enabled");
    col_add($pdo, $DB, 'users', 'pickup_availability_date',
        "pickup_availability_date DATE DEFAULT NULL AFTER pickup_available_today");
    idx_add($pdo, $DB, 'users', 'idx_users_pickup_matching',
        "INDEX idx_users_pickup_matching (user_type, is_online, private_pickup_enabled, is_verified)");

    // Canonicalise any free-text vehicle_type already stored. vehicles.
    // vehicle_type is a VARCHAR and the driver form historically submitted
    // the lowercased UI label ("keke napep"), which never equals 'keke' -
    // leaving genuine keke drivers unclassified. normalize_vehicle_type()
    // is the same function the write path now uses, so storage and
    // comparison can no longer drift apart.
    require_once __DIR__ . '/../includes/eligibility.php';
    $vt = $pdo->query("SELECT id, vehicle_type FROM vehicles")->fetchAll();
    $vtFixed = 0;
    $updVt = $pdo->prepare("UPDATE vehicles SET vehicle_type = ? WHERE id = ?");
    foreach ($vt as $v) {
        $canonical = normalize_vehicle_type($v['vehicle_type']);
        if ($canonical !== '' && $canonical !== (string)$v['vehicle_type']) {
            $updVt->execute([$canonical, $v['id']]);
            $vtFixed++;
        }
    }
    echo "  canonicalised vehicle_type on {$vtFixed} vehicle(s)\n";

    // Backfill: existing keke-vehicle owners get classified; existing
    // approved-vehicle drivers keep Join Ride (default already 1).
    $pdo->exec(
        "UPDATE users u
         JOIN vehicles v ON v.driver_id = u.id
         SET u.is_keke_driver = 1
         WHERE u.user_type = 'driver' AND v.vehicle_type = 'keke'"
    );
    // And the inverse, so a driver who switched away from a keke (or whose
    // type was corrected above) loses the capability rather than keeping a
    // stale flag that would grant keke functionality with a non-keke vehicle.
    $pdo->exec(
        "UPDATE users u
         JOIN vehicles v ON v.driver_id = u.id
         SET u.is_keke_driver = 0
         WHERE u.user_type = 'driver' AND v.vehicle_type <> 'keke'"
    );
    echo "  backfilled is_keke_driver from existing vehicle types\n";

    // ------------------------------------------------------------------
    // 2. Extend ride_requests into the private-pickup request queue.
    //    Reuse the existing table - do NOT create a duplicate.
    // ------------------------------------------------------------------
    echo "[2] ride_requests private-pickup fields\n";
    col_add($pdo, $DB, 'ride_requests', 'public_id',
        "public_id VARCHAR(30) DEFAULT NULL AFTER id");
    // request_type MUST carry BOTH lanes and default to 'join_ride'. The
    // ride_requests table already held Join Ride demand rows long before
    // private pickup existed; a single-value enum defaulting to
    // 'private_pickup' would silently relabel every one of them and break
    // the Join Ride / Private Pickup separation.
    col_add($pdo, $DB, 'ride_requests', 'request_type',
        "request_type ENUM('join_ride','private_pickup') NOT NULL DEFAULT 'join_ride' AFTER driver_id");
    enum_ensure($pdo, $DB, 'ride_requests', 'request_type',
        "ENUM('join_ride','private_pickup') NOT NULL DEFAULT 'join_ride'");
    col_add($pdo, $DB, 'ride_requests', 'matched_ride_share_id',
        "matched_ride_share_id INT(11) DEFAULT NULL AFTER driver_id");
    col_add($pdo, $DB, 'ride_requests', 'pickup_location',
        "pickup_location VARCHAR(255) DEFAULT NULL AFTER pickup_lng");
    col_add($pdo, $DB, 'ride_requests', 'notes',
        "notes TEXT DEFAULT NULL AFTER destination_lng");
    col_add($pdo, $DB, 'ride_requests', 'accepted_at',
        "accepted_at TIMESTAMP NULL DEFAULT NULL AFTER status");
    col_add($pdo, $DB, 'ride_requests', 'expires_at',
        "expires_at TIMESTAMP NULL DEFAULT NULL AFTER accepted_at");
    col_add($pdo, $DB, 'ride_requests', 'updated_at',
        "updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    // 'expired' is a real terminal state for unaccepted requests.
    enum_ensure($pdo, $DB, 'ride_requests', 'status',
        "ENUM('pending','accepted','in_progress','rejected','completed','cancelled','expired') NOT NULL DEFAULT 'pending'");
    idx_add($pdo, $DB, 'ride_requests', 'idx_ride_requests_public_id',
        "UNIQUE INDEX idx_ride_requests_public_id (public_id)");
    idx_add($pdo, $DB, 'ride_requests', 'idx_ride_requests_status',
        "INDEX idx_ride_requests_status (status, request_type)");

    // Repair any pre-existing Join Ride demand rows that an earlier run of
    // this migration relabelled as 'private_pickup' via the old single-value
    // enum default. A genuine private pickup request ALWAYS carries an
    // expires_at (create_private_pickup_request sets the TTL on insert), so
    // a private_pickup row with no expires_at can only be a legacy Join Ride
    // row. Idempotent: after the first pass there is nothing left to match.
    $repaired = $pdo->exec(
        "UPDATE ride_requests
         SET request_type = 'join_ride'
         WHERE request_type = 'private_pickup' AND expires_at IS NULL"
    );
    echo "  repaired {$repaired} legacy Join Ride rows mislabelled as private_pickup\n";

    // Backfill public_id for any pre-existing rows so the unique index holds.
    $rows = $pdo->query("SELECT id FROM ride_requests WHERE public_id IS NULL OR public_id = ''")->fetchAll(PDO::FETCH_COLUMN);
    if ($rows) {
        $upd = $pdo->prepare("UPDATE ride_requests SET public_id = ? WHERE id = ?");
        foreach ($rows as $rid) {
            $upd->execute(['REQ' . str_pad((string)$rid, 8, '0', STR_PAD_LEFT), $rid]);
        }
        echo "  backfilled public_id for " . count($rows) . " existing rows\n";
    }

    // ------------------------------------------------------------------
    // 3. Per-document verification: review reason, soft-delete, EXPIRED.
    //    Audit-required docs are archived, never destructively deleted.
    // ------------------------------------------------------------------
    echo "[3] vehicle_verifications review + soft-delete + expiry\n";
    col_add($pdo, $DB, 'vehicle_verifications', 'document_type',
        "document_type VARCHAR(50) DEFAULT NULL AFTER document_name");
    col_add($pdo, $DB, 'vehicle_verifications', 'rejection_reason',
        "rejection_reason TEXT DEFAULT NULL AFTER status");
    col_add($pdo, $DB, 'vehicle_verifications', 'reviewed_by',
        "reviewed_by INT(11) DEFAULT NULL AFTER rejection_reason");
    col_add($pdo, $DB, 'vehicle_verifications', 'reviewed_at',
        "reviewed_at TIMESTAMP NULL DEFAULT NULL AFTER reviewed_by");
    col_add($pdo, $DB, 'vehicle_verifications', 'expiry_date',
        "expiry_date DATE DEFAULT NULL AFTER reviewed_at");
    col_add($pdo, $DB, 'vehicle_verifications', 'archived_at',
        "archived_at TIMESTAMP NULL DEFAULT NULL AFTER expiry_date");
    enum_ensure($pdo, $DB, 'vehicle_verifications', 'status',
        "ENUM('pending','approved','rejected','expired') DEFAULT 'pending'");

    // ------------------------------------------------------------------
    // 4. Configurable settings. Radius is administratively configurable,
    //    default 20km per spec. INSERT IGNORE keeps existing values intact.
    // ------------------------------------------------------------------
    echo "[4] system_settings\n";
    $settings = [
        ['private_pickup_radius_km', '20'],
        ['private_pickup_enabled', '1'],
        ['private_pickup_request_ttl_seconds', '90'],
        ['private_pickup_price_per_km', '120'],
        ['private_pickup_base_fare', '300'],
        // A driver's GPS ping older than this (seconds) is treated as stale,
        // so a driver who closed the app or lost signal stops being "nearby".
        ['driver_location_ttl_seconds', '120'],
        // Keke JOIN RIDE rates. calculate_recommended_seat_price() picks up
        // <vehicle_type>_price_per_km / <vehicle_type>_base_fare automatically,
        // so a keke driver publishing a Join Ride is priced as a keke instead
        // of at the car rate. Seeded at the platform defaults so behaviour is
        // unchanged until an admin edits them - nothing is hard-coded in code.
        ['keke_price_per_km', (string) get_pricing_setting($pdo, 'price_per_km', '60')],
        ['keke_base_fare', (string) get_pricing_setting($pdo, 'base_fare', '100')],
    ];
    $ins = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as $s) {
        $ins->execute($s);
        echo "  ensured setting {$s[0]}\n";
    }

    echo "Phase 6: Keke Private Pickup migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
