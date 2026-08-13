<?php
// driver/ride-requests.php
// Ride Requests (SYSTEM 1) — nearby passenger DEMAND a driver can
// discover and create a matching ride against. NOT the Ride Queue
// (full-ride seat waiting, handled entirely inside the ride-detail
// sheet). Drivers never approve a specific passenger here — creating a
// ride is the only action; the matching engine (includes/ride_requests.php)
// then notifies whichever passengers it fits.
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

$pageTitle = 'Ride Requests';
$activeTab = 'ride-requests';
$userType = 'driver';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh Driver — Ride Requests</title>

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

        <!-- Lane chooser. Private Pickup (SYSTEM 2) used to be rendered inline
             on this page, which put the driver's ACCEPT/DECLINE lane on the
             same screen as Join Ride demand. It now has its own screen
             (driver/pickup.php, what the sidebar's "Pickup Ride" opens) and
             this page is purely Join Ride demand. -->
        <div class="trip-tabs" role="tablist">
            <button type="button" class="trip-tab is-active" role="tab" aria-current="page">Join Ride requests</button>
            <a class="trip-tab" href="pickup.php" role="tab">Pickup Ride</a>
        </div>

        <p class="u-text-sm u-text-soft u-mb-3">Passengers below need a ride you haven't published yet. Create a matching ride and they'll be notified automatically — you never approve a passenger directly.</p>

        <!-- ============ JOIN RIDE DEMAND (SYSTEM 1) ============ -->
        <div id="rrfListContainer">
            <div class="u-text-center" style="padding:32px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:22px;height:22px;display:inline-block;"></span></div>
        </div>
        <div id="rrfEmpty" class="notification-empty u-hidden">No open ride requests right now.</div>
        <button type="button" class="btn btn-outline u-mt-3 u-hidden" id="rrfLoadMore">Load more</button>
    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/messages-sheet.php'; ?>

<!-- ================= Request detail sheet ================= -->
<div class="sheet" id="rrfDetailSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Ride Request</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="rrfDetailContent"></div>
    <div id="rrfDetailFooter" class="u-mt-4"></div>
</div>

<!-- The Private Pickup request sheet and its driver-private-pickup.js client
     now live on driver/pickup.php, the dedicated Pickup Ride screen. -->

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/driver-ride-requests.js"></script>
</body>
</html>
