<?php
// rider/profile.php
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

$pageTitle = 'Profile';
$activeTab = 'profile';
$userType = 'rider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- App CSRF token for authenticated state-changing fetches (e.g. MOHTOH NETWORK join/withdrawal requests). Not a secret; scoped to this session. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>MohToh — Profile</title>

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
<body>

<div class="app-shell">
    <div class="screen">
        <?php include __DIR__ . '/../partials/app-header.php'; ?>

        <div class="screen-scroll screen-scroll--app">
            <!-- ================= Profile header ================= -->
            <section class="card profile-header anim-slide-up">
                <img id="profileAvatar" class="avatar avatar-lg" src="" alt="Profile photo">
                <div style="flex:1;min-width:0;">
                    <div class="profile-header__name" id="profileName">—</div>
                    <div class="profile-header__id" id="profileUserId">—</div>
                    <div class="profile-header__meta">
                        <span class="rating-chip"><i class="bi bi-star-fill"></i> <span id="profileRating">—</span></span>
                        <span id="profileStatusPill" class="status-pill approved">Active</span>
                    </div>
                </div>
                <button type="button" class="icon-pill profile-header__edit" data-open-sheet-id="editProfileSheet" aria-label="Edit profile"><i class="bi bi-pencil"></i></button>
            </section>

            <!-- ================= Account info ================= -->
            <section class="card">
                <div class="info-row"><span class="info-row__label">Phone</span><span class="info-row__value" id="profilePhone">—</span></div>
                <div class="info-row"><span class="info-row__label">Email</span><span class="info-row__value" id="profileEmail">—</span></div>
                <div class="info-row"><span class="info-row__label">Member since</span><span class="info-row__value" id="profileMemberSince">—</span></div>
            </section>

            <!-- ================= Wallet / payment methods ================= -->
            <div class="section-label">Payment &amp; Wallet</div>
            <section class="card">
                <div class="info-row"><span class="info-row__label">Wallet balance</span><span class="info-row__value" id="riderWalletBalance">₦0.00</span></div>
                <a href="dashboard.php?open=wallet" class="menu-row"><i class="bi bi-wallet2"></i><span>Wallet &amp; transaction history</span><i class="bi bi-chevron-right" style="margin-left:auto;"></i></a>
            </section>

            <!-- ================= Ride history ================= -->
            <div class="section-label">Trips</div>
            <section class="card menu-list">
                <a href="trips.php" class="menu-row"><i class="bi bi-clock-history"></i><span>Ride history</span><i class="bi bi-chevron-right" style="margin-left:auto;"></i></a>
            </section>

            <!-- ================= Saved locations ================= -->
            <div class="u-flex u-justify-between u-items-center" style="padding-inline:2px;">
                <div class="section-label" style="margin:0;">Saved locations</div>
                <button type="button" class="btn-text u-text-xs" data-open-sheet-id="addLocationSheet">+ Add</button>
            </div>
            <section class="card" id="savedLocationsList">
                <div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">Loading…</div>
            </section>

            <!-- ================= Ratings received ================= -->
            <div class="section-label">Ratings received</div>
            <section class="card">
                <div class="u-text-center u-text-faint u-text-sm" style="padding:16px 0;">No ratings yet.</div>
            </section>

            <!-- ================= Account settings ================= -->
            <div class="section-label">Notification settings</div>
            <section class="card">
                <div class="toggle-row">
                    <div><div class="toggle-row__label">Rating reminders</div><div class="toggle-row__desc">Notify me when a driver rates a trip</div></div>
                    <label class="toggle-switch"><input type="checkbox" data-notify-toggle="notify_on_rating"><span class="toggle-switch__track"></span></label>
                </div>
                <div class="toggle-row">
                    <div><div class="toggle-row__label">Booking updates</div><div class="toggle-row__desc">Notify me about new bookings</div></div>
                    <label class="toggle-switch"><input type="checkbox" data-notify-toggle="notify_on_booking"><span class="toggle-switch__track"></span></label>
                </div>
                <div class="toggle-row">
                    <div><div class="toggle-row__label">Ride start</div><div class="toggle-row__desc">Notify me when my ride starts</div></div>
                    <label class="toggle-switch"><input type="checkbox" data-notify-toggle="notify_ride_start"><span class="toggle-switch__track"></span></label>
                </div>
                <div class="toggle-row">
                    <div><div class="toggle-row__label">Ride cancellations</div><div class="toggle-row__desc">Notify me if a ride is cancelled</div></div>
                    <label class="toggle-switch"><input type="checkbox" data-notify-toggle="notify_ride_cancel"><span class="toggle-switch__track"></span></label>
                </div>
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
                <img src="" id="editProfileAvatarPreview" alt="" class="avatar avatar-lg" onerror="this.src='https://ui-avatars.com/api/?name=Rider&background=0F8B8D&color=fff'">
                <label class="btn btn-outline" style="width:auto;padding-inline:16px;">Change photo<input type="file" id="editProfileImageInput" accept="image/*" class="u-hidden"></label>
            </div>
            <div class="field-group">
                <label class="field-label" for="editFullname">Full name</label>
                <div class="field"><i class="bi bi-person"></i><input type="text" id="editFullname"></div>
            </div>
            <div class="field-group">
                <label class="field-label" for="editPhone">Phone number</label>
                <div class="field"><i class="bi bi-telephone"></i><input type="text" id="editPhone"></div>
            </div>
            <div class="field-group">
                <label class="field-label" for="editEmail">Email</label>
                <div class="field"><i class="bi bi-envelope"></i><input type="email" id="editEmail"></div>
            </div>
            <p class="u-text-xs u-text-faint u-mb-2">Changing your name or phone number requires your current password, to confirm it's really you.</p>
            <div class="field-group">
                <label class="field-label" for="editCurrentPassword">Current password (only needed for name/phone changes)</label>
                <div class="field"><i class="bi bi-lock"></i><input type="password" id="editCurrentPassword" autocomplete="current-password"></div>
            </div>
            <button type="submit" class="btn btn-primary" id="editProfileSubmitBtn">Save changes</button>
        </form>
    </div>

    <!-- ================= Add saved location sheet ================= -->
    <div class="sheet" id="addLocationSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Add saved location</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="addLocationAlert" class="alert-banner is-hidden"></div>

        <form id="addLocationForm">
            <div class="field-group">
                <label class="field-label" for="addLocationLabel">Label</label>
                <div class="field"><i class="bi bi-tag"></i><input type="text" id="addLocationLabel" maxlength="50" placeholder="Home, Work, Gym…" required></div>
            </div>
            <div class="field-group">
                <label class="field-label" for="addLocationAddress">Address</label>
                <div class="field"><i class="bi bi-geo-alt"></i><input type="text" id="addLocationAddress" placeholder="Search for an address" required autocomplete="off"></div>
                <input type="hidden" id="addLocationLat">
                <input type="hidden" id="addLocationLng">
            </div>
            <button type="submit" class="btn btn-primary" id="addLocationSubmitBtn">Save location</button>
        </form>
    </div>
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
