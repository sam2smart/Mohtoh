<?php
// rider/trips.php
// Rider Trips module — Active/Upcoming/Completed/Cancelled trip
// management. Reuses the existing engine end to end: api/rideshare.php's
// my_bookings/details/get_driver_location/cancel_booking/rate_ride, the
// new (read-only) get_trip_detail action, and ride_shares.trip_state +
// ride_share_bookings.passenger_state already built by the Intelligent
// Trip Engine. No new booking/payment/rating logic is introduced.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

require_login();

if ($_SESSION['user_type'] !== 'rider') {
    header('Location: ../driver/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/current_user.php';

$pageTitle = 'My Trips';
$activeTab = 'trips';
$userType = 'rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Trips</title>

    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0F8B8D">
    <link rel="icon" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/reset.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/mobile-shell.css">
    <link rel="stylesheet" href="../assets/css/utilities.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body data-user-type="rider">

<div class="app-shell screen">
    <?php include __DIR__ . '/../partials/app-header.php'; ?>

    <div class="screen-scroll screen-scroll--app">
        <!-- ================= Tabs ================= -->
        <div class="trip-tabs" role="tablist">
            <button type="button" class="trip-tab is-active" data-trip-tab="active" role="tab">Active</button>
            <button type="button" class="trip-tab" data-trip-tab="upcoming" role="tab">Upcoming</button>
            <button type="button" class="trip-tab" data-trip-tab="completed" role="tab">Completed</button>
            <button type="button" class="trip-tab" data-trip-tab="cancelled" role="tab">Cancelled</button>
        </div>

        <!-- ================= Search / filter ================= -->
        <div class="field trip-search-bar">
            <i class="bi bi-search"></i>
            <input type="text" id="tripSearchInput" placeholder="Search by destination…">
        </div>
        <div class="u-flex u-gap-2 u-mb-4" style="flex-wrap:wrap;">
            <input type="date" id="tripFilterDate" class="field" style="width:auto;padding:8px 12px;font-size:var(--text-xs);">
            <select id="tripFilterPayment" class="field" style="width:auto;padding:8px 12px;font-size:var(--text-xs);">
                <option value="">Any payment status</option>
                <option value="PAYMENT_CONFIRMED">Paid</option>
                <option value="PAYMENT_PENDING">Pending</option>
                <option value="PAYMENT_FAILED">Failed</option>
            </select>
        </div>

        <div id="tripListContainer">
            <div class="u-text-center" style="padding:32px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:22px;height:22px;display:inline-block;"></span></div>
        </div>
    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/messages-sheet.php'; ?>
<?php include __DIR__ . '/../partials/community-rewards-sheet.php'; ?>

<!-- ================= Trip detail sheet ================= -->
<div class="sheet" id="tripDetailSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Trip details</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="tripDetailContent"></div>
</div>

<!-- ================= Rating sheet ================= -->
<div class="sheet" id="ratingSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Rate your ride</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <form id="ratingForm">
        <input type="hidden" name="booking_id" id="ratingBookingId">
        <div class="rating-stars u-mb-4">
            <i class="bi bi-star-fill" data-value="1"></i>
            <i class="bi bi-star-fill" data-value="2"></i>
            <i class="bi bi-star-fill" data-value="3"></i>
            <i class="bi bi-star-fill" data-value="4"></i>
            <i class="bi bi-star-fill" data-value="5"></i>
        </div>
        <input type="hidden" name="rating" id="ratingValue" required>
        <div class="field-group">
            <textarea name="comment" class="field" style="height:80px;align-items:flex-start;padding-top:12px;" placeholder="Leave a comment (optional)"></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Submit rating</button>
    </form>
</div>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/map.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/referrals.js"></script>
<script src="../assets/js/rider-trips.js"></script>
</body>
</html>
