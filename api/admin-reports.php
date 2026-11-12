<?php
// api/admin-reports.php
// Section 9 (Reports) — every query here reuses the existing payments/
// ride_shares/ride_share_bookings tables (no new storage). Regular
// admin access, matching the existing get_dashboard_data action —
// reports are read-only, not money-affecting.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

require_once '../includes/admin_permissions.php';
// Access-Control: reports are read-only analytics -> reports.view. Enforces
// the full authenticated -> admin mode -> active -> permission chain.
require_admin_permission($pdo, 'reports.view');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response('error', 'Invalid request method');
}

$report = $_GET['report'] ?? '';

if ($report === 'revenue') {
    // One bucketed query per period, same date-bucketing pattern used by
    // api/profile.php's driver earnings action (week/month) added earlier.
    $daily = $pdo->query("
        SELECT DATE(confirmed_at) AS period, SUM(fare_amount) AS revenue, SUM(commission_amount) AS commission, SUM(driver_earning_amount) AS driver_earnings
        FROM payments WHERE status = 'PAYMENT_CONFIRMED' AND confirmed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(confirmed_at) ORDER BY period DESC
    ")->fetchAll();

    $weekly = $pdo->query("
        SELECT YEARWEEK(confirmed_at, 1) AS period, MIN(DATE(confirmed_at)) AS week_start, SUM(fare_amount) AS revenue, SUM(commission_amount) AS commission, SUM(driver_earning_amount) AS driver_earnings
        FROM payments WHERE status = 'PAYMENT_CONFIRMED' AND confirmed_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
        GROUP BY YEARWEEK(confirmed_at, 1) ORDER BY period DESC
    ")->fetchAll();

    $monthly = $pdo->query("
        SELECT DATE_FORMAT(confirmed_at, '%Y-%m') AS period, SUM(fare_amount) AS revenue, SUM(commission_amount) AS commission, SUM(driver_earning_amount) AS driver_earnings
        FROM payments WHERE status = 'PAYMENT_CONFIRMED' AND confirmed_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(confirmed_at, '%Y-%m') ORDER BY period DESC
    ")->fetchAll();

    $totals = $pdo->query("
        SELECT SUM(fare_amount) AS total_revenue, SUM(commission_amount) AS total_commission, SUM(driver_earning_amount) AS total_driver_earnings, COUNT(*) AS total_trips
        FROM payments WHERE status = 'PAYMENT_CONFIRMED'
    ")->fetch();

    json_response('success', 'Revenue report fetched', [
        'daily' => $daily,
        'weekly' => $weekly,
        'monthly' => $monthly,
        'totals' => $totals,
    ]);
}

if ($report === 'driver_earnings') {
    $stmt = $pdo->query("
        SELECT d.id, d.user_id AS driver_public_id, d.fullname,
               COUNT(p.id) AS completed_trips,
               SUM(p.driver_earning_amount) AS total_earnings,
               SUM(p.commission_amount) AS total_commission_generated
        FROM payments p
        JOIN users d ON d.id = p.driver_id
        WHERE p.status = 'PAYMENT_CONFIRMED'
        GROUP BY d.id, d.user_id, d.fullname
        ORDER BY total_earnings DESC
        LIMIT 50
    ");
    json_response('success', 'Driver earnings report fetched', ['drivers' => $stmt->fetchAll()]);
}

if ($report === 'most_active_drivers') {
    $stmt = $pdo->query("
        SELECT d.id, d.user_id AS driver_public_id, d.fullname, d.rating,
               COUNT(DISTINCT rs.id) AS rides_created,
               COUNT(p.id) AS completed_trips
        FROM users d
        LEFT JOIN ride_shares rs ON rs.driver_id = d.id
        LEFT JOIN payments p ON p.driver_id = d.id AND p.status = 'PAYMENT_CONFIRMED'
        WHERE d.user_type = 'driver'
        GROUP BY d.id, d.user_id, d.fullname, d.rating
        ORDER BY completed_trips DESC
        LIMIT 50
    ");
    json_response('success', 'Most active drivers report fetched', ['drivers' => $stmt->fetchAll()]);
}

if ($report === 'most_active_routes') {
    $stmt = $pdo->query("
        SELECT start_location, destination, COUNT(*) AS ride_count
        FROM ride_shares
        GROUP BY start_location, destination
        ORDER BY ride_count DESC
        LIMIT 50
    ");
    json_response('success', 'Most active routes report fetched', ['routes' => $stmt->fetchAll()]);
}

if ($report === 'peak_hours') {
    $stmt = $pdo->query("
        SELECT HOUR(departure_time) AS hour, COUNT(*) AS ride_count
        FROM ride_shares
        GROUP BY HOUR(departure_time)
        ORDER BY hour ASC
    ");
    json_response('success', 'Peak ride hours report fetched', ['hours' => $stmt->fetchAll()]);
}

if ($report === 'ride_statistics') {
    $stmt = $pdo->query("
        SELECT status, COUNT(*) AS count
        FROM ride_shares
        GROUP BY status
    ")->fetchAll();

    $bookingStats = $pdo->query("
        SELECT status, COUNT(*) AS count
        FROM ride_share_bookings
        GROUP BY status
    ")->fetchAll();

    json_response('success', 'Ride statistics fetched', [
        'ride_shares_by_status' => $stmt,
        'bookings_by_status' => $bookingStats,
    ]);
}

json_response('error', 'Invalid report requested');
