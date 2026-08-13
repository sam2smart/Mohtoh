<?php
// driver/trips.php
// Driver Trips module — Active/Upcoming/Completed/Cancelled trip
// management. Reuses the existing Intelligent Trip Engine end to end:
// api/rideshare.php's my_rides/get_ride_history/get_passengers/
// get_earnings/start_ride/complete_ride/update/cancel/
// confirm_direct_payment/reject_direct_payment/get_trip_detail, and the
// ride_shares.trip_state + trip_state_events audit trail already built
// by the engine. No new trip logic — this is a view over what already
// exists, plus one new read-only action (get_trip_detail) for the
// financial/timeline bottom sheet.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

require_login();

if ($_SESSION['user_type'] !== 'driver') {
    header('Location: ../rider/dashboard.php');
    exit;
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/current_user.php';

$pageTitle = 'My Trips';
$activeTab = 'trips';
$userType = 'driver';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh Driver — Trips</title>

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
<body data-user-type="driver">

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
        <div class="u-flex u-gap-2 u-mb-4" id="tripFilterRow" style="flex-wrap:wrap;">
            <input type="date" id="tripFilterDate" class="field" style="width:auto;padding:8px 12px;font-size:var(--text-xs);">
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

<!-- ================= Contact passenger sheet ================= -->
<div class="sheet" id="contactPassengerSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Passengers</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="contactPassengerList"></div>
</div>

<!-- ================= Edit trip sheet ================= -->
<div class="sheet" id="editTripSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Edit trip</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="editTripAlert" class="alert-banner is-hidden"></div>
    <form id="editTripForm">
        <input type="hidden" id="editTripRideId">
        <div class="field-group">
            <label class="field-label">Pickup</label>
            <div class="field"><i class="bi bi-record-circle"></i><input type="text" id="editTripPickup" required></div>
            <input type="hidden" id="editTripPickupLat">
            <input type="hidden" id="editTripPickupLng">
        </div>
        <div class="field-group">
            <label class="field-label">Destination</label>
            <div class="field"><i class="bi bi-geo-alt-fill"></i><input type="text" id="editTripDestination" required></div>
            <input type="hidden" id="editTripDestinationLat">
            <input type="hidden" id="editTripDestinationLng">
        </div>
        <div class="search-card__row">
            <div class="field-group"><label class="field-label">Date</label><div class="field"><i class="bi bi-calendar3"></i><input type="date" id="editTripDate" required></div></div>
            <div class="field-group" style="flex:1;"><label class="field-label">Time</label><div class="field"><i class="bi bi-clock"></i><input type="time" id="editTripTime" required></div></div>
        </div>
        <div class="field-group">
            <label class="field-label">Price per seat</label>
            <div class="field"><i class="bi bi-cash"></i><input type="number" id="editTripPrice" step="0.01"></div>
        </div>
        <button type="submit" class="btn btn-primary" id="editTripSubmitBtn">Save changes</button>
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
<script src="../assets/js/driver-trips.js"></script>
</body>
</html>
