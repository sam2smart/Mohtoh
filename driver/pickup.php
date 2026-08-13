<?php
// driver/pickup.php
// Pickup Ride — the driver's dedicated PRIVATE PICKUP (SYSTEM 2) screen.
//
// This is what the sidebar's "Pickup Ride" item opens. It is deliberately a
// SEPARATE screen from driver/ride-requests.php (Join Ride demand, SYSTEM 1):
// a private pickup is an immediate, solo, whole-vehicle trip (keke or private
// car) that the driver ACCEPTS or DECLINES for a specific rider, not
// seat-sharing demand they publish a ride against. Keeping the two on
// separate screens is what stops the lanes being read as one feature.
//
// Pure UI. The control surface itself is partials/driver-private-pickup-section.php
// (shared markup + ids) and all behaviour is assets/js/driver-private-pickup.js,
// a thin client over api/pickup-availability.php, api/toggle-online.php and
// api/private-pickup.php. No business logic, eligibility decision, or fare
// math happens on this page.
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

$pageTitle = 'Pickup Ride';
$activeTab = 'ride-requests';
$userType = 'driver';

// Radius is administratively configurable — read it rather than hard-coding,
// so the copy the driver sees always matches the actual matching rule.
$pickupRadiusKm = (float) get_system_setting($pdo, 'private_pickup_radius_km', 20);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh Driver — Pickup Ride</title>

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

        <!-- Lane chooser: makes the Join Ride / Private Pickup separation
             explicit rather than implied, mirroring the rider side. -->
        <div class="trip-tabs" role="tablist">
            <a class="trip-tab" href="ride-requests.php" role="tab">Join Ride requests</a>
            <button type="button" class="trip-tab is-active" role="tab" aria-current="page">Pickup Ride</button>
        </div>

        <p class="u-text-sm u-text-soft u-mb-4">
            Solo trips requested by a rider near you — a verified keke or private car
            driver can accept. You accept or decline each one
            yourself. Riders within
            <?php echo htmlspecialchars(rtrim(rtrim(number_format($pickupRadiusKm, 1), '0'), '.'), ENT_QUOTES); ?> KM
            of your current location can reach you while you are online and available today.
        </p>

        <?php include __DIR__ . '/../partials/driver-private-pickup-section.php'; ?>

    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/messages-sheet.php'; ?>

<!-- ========= Private Pickup incoming-request sheet ========= -->
<div class="sheet" id="pickupRequestSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Private Pickup Request</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="pickupRequestContent"></div>
    <div id="pickupRequestFooter" class="u-mt-4"></div>
</div>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/driver-private-pickup.js"></script>
</body>
</html>
