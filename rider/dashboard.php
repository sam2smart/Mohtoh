<?php
// rider/dashboard.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

require_verified($pdo);

// Route a wrong-role session to its own role's home — never to the
// opposite-role dashboard (rider <-> driver would loop forever) nor to
// the login page (index.php bounces an authenticated session back here).
if ($_SESSION['user_type'] !== 'rider') {
    $roleHome = ['driver' => '../driver/dashboard.php', 'admin' => '../admin/dashboard.php'][$_SESSION['user_type']] ?? '../index.php';
    header('Location: ' . $roleHome);
    exit;
}

require_once __DIR__ . '/../includes/current_user.php';

$pageTitle = null; // null => show the MohToh brand mark instead of a page title
$activeTab = 'home';
$userType = 'rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- App CSRF token for authenticated state-changing fetches (e.g. MOHTOH NETWORK join/withdrawal requests). Not a secret; scoped to this session. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>MohToh — Home</title>

    <link rel="manifest" href="../manifest.json">
    <meta name="theme-color" content="#0F8B8D">
    <link rel="icon" href="../assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="../assets/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="../assets/css/variables.css">
    <link rel="stylesheet" href="../assets/css/reset.css">
    <link rel="stylesheet" href="../assets/css/layout.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/mobile-shell.css">
    <link rel="stylesheet" href="../assets/css/utilities.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
</head>
<body>

<div class="app-shell">
    <div class="map-screen">
        <div class="layer-map"><div id="mapCanvas"></div></div>
        <div class="layer-overlay"></div>

        <?php include __DIR__ . '/../partials/app-header.php'; ?>

        <div class="map-screen__scroll">
            <!-- ================= Live trip card (Phase 3) ================= -->
            <button type="button" class="card anim-slide-up u-hidden" id="liveTripCard" style="padding:var(--space-4);text-align:left;width:100%;display:flex;align-items:center;gap:var(--space-3);" data-open-sheet-id="liveTripSheet">
                <i class="bi bi-broadcast" style="font-size:1.4rem;color:var(--color-primary);"></i>
                <div style="flex:1;min-width:0;">
                    <div class="u-text-xs u-text-faint" id="liveTripDriverLine">Your ride</div>
                    <div style="font-weight:700;" id="liveTripStatusLine">—</div>
                </div>
                <i class="bi bi-chevron-right u-text-faint"></i>
            </button>

            <!-- ================= Floating search card ================= -->
            <section class="card search-card anim-slide-up">
                <h2 class="u-text-sm u-mb-2" style="font-family:var(--font-display);font-weight:700;">Find your next ride</h2>
                <form id="searchRidesForm">
                    <div class="search-card__route">
                        <div class="field">
                            <i class="bi bi-record-circle" style="color:var(--color-primary);"></i>
                            <input type="text" id="pickupLocation" name="pickup_location" placeholder="Pickup — your current location" required>
                            <button type="button" class="field-toggle-visibility" id="useLocationBtn" title="Use my current location" aria-label="Use my current location"><i class="bi bi-crosshair"></i></button>
                        </div>
                        <div class="field">
                            <i class="bi bi-geo-alt-fill" style="color:var(--color-danger);"></i>
                            <input type="text" id="destinationLocation" name="destination" placeholder="Where are you headed?" required>
                        </div>
                        <!-- Coordinates of the picked places. Filled by the address
                             autocomplete / "use my location" button, cleared again when
                             the rider edits the text by hand so they can never go stale. -->
                        <input type="hidden" id="searchPickupLat" name="pickup_lat">
                        <input type="hidden" id="searchPickupLng" name="pickup_lng">
                        <input type="hidden" id="searchDestinationLat" name="destination_lat">
                        <input type="hidden" id="searchDestinationLng" name="destination_lng">
                    </div>
                    <div class="search-card__row">
                        <div class="field-group">
                            <div class="field">
                                <i class="bi bi-calendar3"></i>
                                <input type="date" id="departureDate" name="departure_date" required>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary" style="width:auto;padding-inline:22px;"><i class="bi bi-search"></i></button>
                    </div>
                </form>
                <div id="recentSearches" class="u-flex u-gap-2 u-mt-4" style="flex-wrap:wrap;"></div>
            </section>

            <!-- ================= Ride results ================= -->
            <section>
                <div class="u-flex u-justify-between u-items-center u-mb-2" style="padding-inline:2px;">
                    <h3 id="rideResultsTitle" class="u-text-sm" style="font-family:var(--font-display);font-weight:700;color:#fff;text-shadow:0 1px 6px rgba(0,0,0,.25);">Rides starting soon</h3>
                </div>
                <div id="rideResultsAlert"></div>
                <div id="rideResultsList"></div>
            </section>
        </div>

        <div class="map-fab-stack">
            <button type="button" class="fab" id="recenterBtn" aria-label="Center on my location"><i class="bi bi-crosshair"></i></button>
        </div>

        <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
    </div>

    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <!-- ================= Ride detail sheet ================= -->
    <div class="sheet-scrim" data-sheet-scrim></div>

    <?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
    <?php include __DIR__ . '/../partials/messages-sheet.php'; ?>
    <?php include __DIR__ . '/../partials/network-sheet.php'; ?>
    <div class="sheet" id="rideDetailSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Ride details</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="rideDetailContent"></div>
        <div id="rideDetailFooter" class="u-mt-4"></div>
    </div>

    <!-- ================= Live trip sheet (Phase 3) ================= -->
    <div class="sheet" id="liveTripSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Live trip</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="liveTripContent"></div>
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

    <!-- ================= Wallet sheet ================= -->
    <div class="sheet" id="walletSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Wallet</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="wallet-hero">
            <div class="wallet-hero__label">Wallet Balance</div>
            <div class="wallet-hero__amount" id="walletHeroAmount">₦0.00</div>
            <div class="wallet-hero__updated" id="walletHeroUpdated">Last updated —</div>
        </div>

        <div class="wallet-action-grid">
            <button type="button" class="wallet-action-btn wallet-action-btn--primary" data-open-sheet-id="topUpSheet"><i class="bi bi-plus-circle"></i><span>Top Up</span></button>
            <button type="button" class="wallet-action-btn" data-open-sheet-id="transactionHistorySheet"><i class="bi bi-clock-history"></i><span>Transaction History</span></button>
        </div>
    </div>

    <!-- ================= Top Up sheet ================= -->
    <div class="sheet" id="topUpSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Top Up Wallet</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="topUpAlert" class="alert-banner is-hidden"></div>

        <form id="topUpForm">
            <div class="field-group">
                <label class="field-label" for="topUpAmount">Amount</label>
                <div class="field"><i class="bi bi-cash-stack"></i><input type="number" id="topUpAmount" name="amount" min="100" step="1" placeholder="5,000" required></div>
            </div>
            <button type="submit" class="btn btn-primary" id="topUpSubmitBtn">Top Up</button>
        </form>
    </div>

    <!-- ================= Transaction History sheet ================= -->
    <div class="sheet" id="transactionHistorySheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Transaction History</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="transactionHistoryList"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/transparency-components.js"></script>
<script src="../assets/js/map.js"></script>
<script src="../assets/js/search.js"></script>
<script src="../assets/js/rider-trip.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/network.js"></script>
<script>
    document.getElementById('recenterBtn')?.addEventListener('click', () => centerMapOnUser());
</script>
</body>
</html>
