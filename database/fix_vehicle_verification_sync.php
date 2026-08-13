<?php
// database/fix_vehicle_verification_sync.php
// One-time data repair for the Driver Vehicle Verification audit.
// Root cause: vehicle approval (vehicles.status) and driver account
// verification (users.is_verified) were maintained by two completely
// separate admin actions with no link between them, so a driver could
// have an approved vehicle yet still show as unverified. admin-api.php
// and driver-api.php now keep the two in sync going forward; this
// script corrects any drivers left inconsistent by that gap before today.
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Backfilling is_verified for drivers with an approved vehicle...\n";
    $stmt = $pdo->prepare("
        UPDATE users u
        JOIN vehicles v ON v.driver_id = u.id
        SET u.is_verified = 1
        WHERE u.user_type = 'driver' AND v.status = 'approved' AND u.is_verified = 0
    ");
    $stmt->execute();
    echo "  Verified {$stmt->rowCount()} driver(s) with an approved vehicle.\n";

    echo "Clearing is_verified for drivers whose only vehicle is pending/rejected...\n";
    $stmt = $pdo->prepare("
        UPDATE users u
        JOIN vehicles v ON v.driver_id = u.id
        SET u.is_verified = 0
        WHERE u.user_type = 'driver' AND v.status != 'approved' AND u.is_verified = 1
    ");
    $stmt->execute();
    echo "  Cleared {$stmt->rowCount()} driver(s) whose vehicle is no longer approved.\n";

    echo "Clearing is_verified for drivers with no vehicle record at all...\n";
    $stmt = $pdo->prepare("
        UPDATE users u
        LEFT JOIN vehicles v ON v.driver_id = u.id
        SET u.is_verified = 0
        WHERE u.user_type = 'driver' AND v.id IS NULL AND u.is_verified = 1
    ");
    $stmt->execute();
    echo "  Cleared {$stmt->rowCount()} driver(s) with no vehicle on file.\n";

    echo "Done.\n";
} catch (PDOException $e) {
    echo "Repair failed: " . $e->getMessage() . "\n";
    exit(1);
}
