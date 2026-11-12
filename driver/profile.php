<?php
// driver/profile.php
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

$pageTitle = 'Profile';
$activeTab = 'profile';
$userType = 'driver';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- App CSRF token for authenticated state-changing fetches (e.g. disconnecting a social login). Not a secret; scoped to this session. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>MohToh Driver — Profile</title>

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
        <!-- ================= Profile header ================= -->
        <section class="card profile-header anim-slide-up">
            <img src="" id="profileAvatar" alt="" class="avatar avatar-lg" onerror="this.src='https://ui-avatars.com/api/?name=Driver&background=0F8B8D&color=fff'">
            <div style="min-width:0;flex:1;">
                <div class="profile-header__name" id="profileName">—</div>
                <div class="profile-header__id" id="profileUserId">—</div>
                <div class="profile-header__contact" id="profilePhone">—</div>
                <div class="profile-header__contact" id="profileEmail">—</div>
                <div class="profile-header__meta">
                    <span class="status-pill" id="profileStatusPill">—</span>
                    <span class="rating-chip"><i class="bi bi-star-fill"></i> <span id="profileRating">—</span></span>
                    <span class="u-text-xs u-text-faint">Since <span id="profileMemberSince">—</span></span>
                </div>
            </div>
        </section>
        <button type="button" class="btn btn-outline" data-open-sheet-id="editProfileSheet"><i class="bi bi-pencil"></i> Edit profile</button>

        <!-- ================= Vehicle card ================= -->
        <section class="section-label">Vehicle</section>
        <a href="dashboard.php?open=vehicle" class="card" id="vehicleCard" style="padding:var(--space-4);display:flex;align-items:center;gap:var(--space-3);color:inherit;">
            <i class="bi bi-car-front" style="font-size:1.6rem;color:var(--color-primary);"></i>
            <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-family:var(--font-display);" id="vehicleCardName">Loading…</div>
                <div class="u-text-xs u-text-soft" id="vehicleCardPlate">—</div>
                <div class="u-text-xs u-text-faint" id="vehicleCardSeats">—</div>
            </div>
            <span class="status-pill" id="vehicleCardStatus">—</span>
            <i class="bi bi-chevron-right u-text-faint"></i>
        </a>

        <!-- ================= Trust / stats ================= -->
        <section class="section-label">Trust &amp; performance</section>
        <section class="card" style="padding:var(--space-4);">
            <div class="info-row"><span class="info-row__label">Account status</span><span class="info-row__value" id="trustAccountStatus">—</span></div>
            <div class="info-row"><span class="info-row__label">Completed trips</span><span class="info-row__value" id="trustCompletedTrips">—</span></div>
            <div class="info-row"><span class="info-row__label">Wallet balance</span><span class="info-row__value" id="driverWalletBalance">—</span></div>
            <div class="info-row"><span class="info-row__label">Driver since</span><span class="info-row__value" id="trustMemberSince">—</span></div>
        </section>

        <!-- ================= Menu list ================= -->
        <section class="card">
            <div class="menu-list">
                <button type="button" class="menu-row" data-open-sheet-id="earningsSheet"><i class="bi bi-cash-stack"></i><span>Earnings summary</span><i class="bi bi-chevron-right"></i></button>
                <a href="dashboard.php?open=wallet" class="menu-row" style="color:inherit;text-decoration:none;"><i class="bi bi-wallet2"></i><span>Wallet</span><i class="bi bi-chevron-right"></i></a>
                <button type="button" class="menu-row" data-open-sheet-id="ratingsReceivedSheet"><i class="bi bi-star"></i><span>Ratings received</span><i class="bi bi-chevron-right"></i></button>
                <button type="button" class="menu-row" data-open-sheet-id="networkSheet"><i class="bi bi-share-fill"></i><span>MOHTOH NETWORK</span><i class="bi bi-chevron-right"></i></button>
                <button type="button" class="menu-row" data-open-sheet-id="premiumCenterSheet"><i class="bi bi-award"></i><span>Premium Center</span><span class="status-pill" id="profilePremiumPill" style="margin-left:auto;margin-right:8px;">—</span><i class="bi bi-chevron-right"></i></button>
                <button type="button" class="menu-row" data-open-sheet-id="accountSettingsSheet"><i class="bi bi-gear"></i><span>Account settings</span><i class="bi bi-chevron-right"></i></button>
            </div>
        </section>

        <!-- ================= Login & security ================= -->
        <div class="section-label">Login &amp; security</div>
        <section class="card" id="connectedAccountsList">
            <div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">Loading…</div>
        </section>
    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/network-sheet.php'; ?>

<!-- ================= Edit profile sheet ================= -->
<div class="sheet" id="editProfileSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Edit profile</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="editProfileAlert" class="alert-banner is-hidden"></div>
    <form id="editProfileForm">
        <div class="u-flex u-items-center u-gap-3 u-mb-4">
            <img src="" id="editProfileAvatarPreview" alt="" class="avatar avatar-lg" onerror="this.src='https://ui-avatars.com/api/?name=Driver&background=0F8B8D&color=fff'">
            <label class="btn btn-outline" style="width:auto;padding-inline:16px;">Change photo<input type="file" id="editProfileImageInput" accept="image/*" class="u-hidden"></label>
        </div>
        <div class="field-group">
            <label class="field-label">Full name</label>
            <div class="field"><i class="bi bi-person"></i><input type="text" id="editFullname" required></div>
        </div>
        <div class="field-group">
            <label class="field-label">Phone number</label>
            <div class="field"><i class="bi bi-telephone"></i><input type="tel" id="editPhone" required></div>
        </div>
        <div class="field-group">
            <label class="field-label">Email address</label>
            <div class="field"><i class="bi bi-envelope"></i><input type="email" id="editEmail" required></div>
        </div>
        <p class="u-text-xs u-text-faint u-mb-2">Changing your name or phone number requires your current password, to confirm it's really you.</p>
        <div class="field-group">
            <label class="field-label">Current password (only needed for name/phone changes)</label>
            <div class="field"><i class="bi bi-shield-lock"></i><input type="password" id="editCurrentPassword" autocomplete="current-password"></div>
        </div>
        <button type="submit" class="btn btn-primary" id="editProfileSubmitBtn">Save changes</button>
    </form>
</div>

<!-- ================= Earnings summary sheet ================= -->
<div class="sheet" id="earningsSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Earnings summary</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="stat-tile-row u-mb-4">
        <div class="stat-tile"><div class="stat-tile__value" id="earningsWeekValue">₦0</div><div class="stat-tile__label">This week</div></div>
        <div class="stat-tile"><div class="stat-tile__value" id="earningsMonthValue">₦0</div><div class="stat-tile__label">This month</div></div>
        <div class="stat-tile"><div class="stat-tile__value" id="earningsTotalValue">₦0</div><div class="stat-tile__label">All time</div></div>
    </div>
    <!-- Part 2 (Premium Driver Features): "Advanced Earnings Report" —
         shown only while the driver has this feature unlocked; a
         locked upgrade prompt otherwise (populated by driver.js). -->
    <div id="advancedEarningsReport" class="u-mb-4"></div>
    <div class="section-label">Recent completed trips</div>
    <div id="earningsHistoryList"></div>
</div>

<!-- ================= Ratings received sheet ================= -->
<div class="sheet" id="ratingsReceivedSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Ratings received</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="stat-tile-row u-mb-4">
        <div class="stat-tile"><div class="stat-tile__value" id="ratingsAverageValue">—</div><div class="stat-tile__label">Average</div></div>
        <div class="stat-tile"><div class="stat-tile__value" id="ratingsCountValue">0</div><div class="stat-tile__label">Ratings</div></div>
    </div>
    <div id="ratingsReceivedList"></div>
</div>

<!-- ================= Premium Center sheet ================= -->
<div class="sheet" id="premiumCenterSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Premium Center</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div class="card u-mb-4" style="padding:var(--space-4);">
        <div class="u-flex u-justify-between u-items-center">
            <div>
                <div class="u-text-xs u-text-faint">Current Plan</div>
                <div style="font-weight:700;font-size:var(--text-lg);font-family:var(--font-display);" id="premiumCurrentPlan">Basic</div>
            </div>
            <div style="text-align:right;">
                <div class="u-text-xs u-text-faint">Wallet Balance</div>
                <div style="font-weight:700;font-size:var(--text-lg);font-family:var(--font-display);color:var(--color-primary);" id="premiumWalletBalance">₦0.00</div>
            </div>
        </div>
    </div>
    <div class="u-text-xs u-text-faint u-mb-3">Premium features are paid for from your wallet — top up first if a feature shows as unaffordable.</div>
    <div class="section-label">Premium Features</div>
    <div id="premiumFeatureList"></div>
</div>

<!-- ================= Account settings sheet ================= -->
<div class="sheet" id="accountSettingsSheet" role="dialog" aria-modal="true">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <h2 class="sheet-title">Account settings</h2>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="settingsAlert" class="alert-banner is-hidden"></div>

    <div class="section-label">Payment preference</div>
    <div class="choice-list" id="paymentPreferenceList">
        <label class="choice-option" data-value="wallet">
            <input type="radio" name="payment_preference" value="wallet">
            <div class="choice-option__body">
                <div class="choice-option__title">MohToh Wallet Only</div>
                <div class="choice-option__desc">Passengers pay through their MohToh wallet.</div>
            </div>
        </label>
        <label class="choice-option" data-value="direct">
            <input type="radio" name="payment_preference" value="direct">
            <div class="choice-option__body">
                <div class="choice-option__title">Direct Payment Only</div>
                <div class="choice-option__desc">Passengers pay you directly — cash or transfer.</div>
            </div>
        </label>
        <label class="choice-option" data-value="both">
            <input type="radio" name="payment_preference" value="both">
            <div class="choice-option__body">
                <div class="choice-option__title">Both</div>
                <div class="choice-option__desc">Passengers choose how to pay when they book.</div>
            </div>
        </label>
    </div>
    <button type="button" class="btn btn-outline u-mb-4" id="savePaymentPreferenceBtn">Save preference</button>

    <div class="section-label">Notifications</div>
    <div class="toggle-row">
        <div><div class="toggle-row__label">Booking alerts</div><div class="toggle-row__desc">Notify me when a passenger books a seat.</div></div>
        <label class="toggle-switch"><input type="checkbox" id="notifyOnBooking"><span class="toggle-switch__track"></span></label>
    </div>
    <div class="toggle-row">
        <div><div class="toggle-row__label">Rating reminders</div><div class="toggle-row__desc">Notify me when a passenger rates a trip.</div></div>
        <label class="toggle-switch"><input type="checkbox" id="notifyOnRating"><span class="toggle-switch__track"></span></label>
    </div>
    <button type="button" class="btn btn-primary u-mt-4" id="saveSettingsBtn">Save settings</button>
</div>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/network.js"></script>
<script src="../assets/js/profile.js"></script>
</body>
</html>
