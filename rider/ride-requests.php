<?php
// rider/ride-requests.php
// Ride Requests (SYSTEM 1) — passenger DEMAND: "I need a ride from X to
// Y on this date" when no matching ride currently exists yet. This is
// NOT the Ride Queue (joining the queue on a specific full ride, done
// from the ride-detail sheet in assets/js/search.js) — the two must
// never be presented as the same feature. Business logic lives entirely
// in includes/ride_requests.php via api/ride-requests.php; this page is
// pure UI, no direct database access.
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

$pageTitle = 'Ride Requests';
$activeTab = 'ride-requests';
$userType = 'rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Ride Requests</title>

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
        <!-- Lane chooser. Join Ride (this screen) and Private Solo Pickup
             are deliberately separate products: a Join Ride request is
             dated seat-sharing demand, a Private Pickup is an immediate
             whole-keke trip matched by live GPS proximity. Surfacing the
             choice here keeps the two from being mistaken for one feature. -->
        <div class="trip-tabs" role="tablist">
            <button type="button" class="trip-tab is-active" role="tab" aria-current="page">Join a Ride</button>
            <a class="trip-tab" href="private-pickup.php" role="tab">Private Solo Pickup</a>
        </div>

        <!-- ================= Tabs ================= -->
        <div class="trip-tabs" role="tablist">
            <button type="button" class="trip-tab is-active" data-rr-tab="active" role="tab">Active</button>
            <button type="button" class="trip-tab" data-rr-tab="past" role="tab">Past</button>
        </div>

        <button type="button" class="btn btn-primary u-mb-4" id="rrCreateBtn"><i class="bi bi-plus-lg"></i> Create Request</button>

        <div id="rrListContainer">
            <div class="u-text-center" style="padding:32px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:22px;height:22px;display:inline-block;"></span></div>
        </div>
        <div id="rrEmpty" class="notification-empty u-hidden">No ride requests yet.</div>
        <button type="button" class="btn btn-outline u-mt-3 u-hidden" id="rrLoadMore">Load more</button>
    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/messages-sheet.php'; ?>

<!-- ================= Create / Edit request sheet ================= -->
<div class="sheet" id="rrFormSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title" id="rrFormTitle">Create Ride Request</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="rrFormAlert" class="alert-banner is-hidden"></div>
    <form id="rrForm">
        <input type="hidden" id="rrEditPublicId">
        <div class="field-group">
            <label class="field-label">Pickup location</label>
            <div class="field">
                <i class="bi bi-record-circle" style="color:var(--color-primary);"></i>
                <input type="text" id="rrPickup" placeholder="Where should the driver pick you up?" required>
                <button type="button" class="field-toggle-visibility" id="rrUseLocationBtn" title="Use my current location" aria-label="Use my current location"><i class="bi bi-crosshair"></i></button>
            </div>
            <input type="hidden" id="rrPickupLat">
            <input type="hidden" id="rrPickupLng">
        </div>
        <div class="field-group">
            <label class="field-label">Destination</label>
            <div class="field"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);"></i><input type="text" id="rrDestination" placeholder="Where are you headed?" required></div>
            <input type="hidden" id="rrDestinationLat">
            <input type="hidden" id="rrDestinationLng">
        </div>
        <div class="search-card__row">
            <div class="field-group"><label class="field-label">Date</label><div class="field"><i class="bi bi-calendar3"></i><input type="date" id="rrDate" required></div></div>
            <div class="field-group" style="flex:1;"><label class="field-label">Time (optional)</label><div class="field"><i class="bi bi-clock"></i><input type="time" id="rrTime"></div></div>
        </div>
        <div class="search-card__row">
            <div class="field-group"><label class="field-label">Passengers</label><div class="field"><i class="bi bi-people"></i><input type="number" id="rrPassengers" min="1" max="8" value="1"></div></div>
            <div class="field-group" style="flex:1;">
                <label class="field-label">Vehicle preference</label>
                <div class="field">
                    <i class="bi bi-car-front"></i>
                    <select id="rrVehiclePreference">
                        <option value="">Any</option>
                        <option value="sedan">Sedan</option>
                        <option value="suv">SUV</option>
                        <option value="bus">Bus</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="field-group">
            <label class="field-label">Notes (optional)</label>
            <textarea id="rrNotes" class="field" style="height:70px;align-items:flex-start;padding-top:12px;" placeholder="Anything a driver should know"></textarea>
        </div>
        <button type="submit" class="btn btn-primary" id="rrFormSubmitBtn">Create Request</button>
    </form>
</div>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/ride-requests.js"></script>
</body>
</html>
