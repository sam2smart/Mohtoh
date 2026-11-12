<?php
// partials/sidebar.php
// Slide-from-left drawer. Only links to routes that actually exist are
// real navigation; entries with no backend yet are visually present but
// marked data-soon so navigation.js shows a "coming soon" toast instead
// of a dead link. Become a Driver / Switch to Rider / Switch to Driver are
// NOT in that category any more — they open partials/mode-switch-sheet.php
// and are served by api/switch-mode.php. Ride Requests (SYSTEM 1 —
// passenger demand, distinct from the Ride Queue on full rides) is a
// real page for both roles: rider/ride-requests.php and
// driver/ride-requests.php. Pickup Ride is SYSTEM 2 (Private Pickup —
// immediate solo keke trips) and is role-specific: driver/pickup.php for
// drivers, rider/private-pickup.php for riders. It previously pointed at
// a bare "pickup.php" from the RIDER branch only, which 404'd for riders
// and left drivers with no route to Private Pickup at all.
// MOHTOH NETWORK is the single canonical entry point to the direct-
// referral/revenue-share programme (partials/network-sheet.php,
// included on every page this drawer is) — supersedes the old "Grow My
// Community" link to MohToh Community Rewards. Do not add a second
// entry point (e.g. a dashboard card) elsewhere.
// $currentUser (fullname, user_type, profile_image) is provided by
// includes/current_user.php, which the including page loads before
// this partial. Fall back gracefully if a page hasn't wired it in yet.
$__me = $currentUser ?? ['fullname' => $_SESSION['fullname'] ?? 'User', 'user_type' => $_SESSION['user_type'] ?? 'rider', 'profile_image' => null];
$__userType = $__me['user_type'];
// Driver CAPABILITY (users.user_type) vs CURRENT MODE ($__userType, from the
// session) — see api/switch-mode.php. A driver who switched to rider mode
// still has the capability, so they get "Switch to Driver" rather than being
// asked to sign up again.
$__hasDriverCapability = !empty($__me['has_driver_capability']) || $__userType === 'driver';
$__avatarSrc = $__me['profile_image']
    ? '../uploads/' . rawurlencode($__me['profile_image'])
    : 'https://ui-avatars.com/api/?name=' . urlencode($__me['fullname']) . '&background=0F8B8D&color=fff';
?>
<div class="sheet-scrim" id="sidebarScrim"></div>
<aside class="sidebar-drawer" id="sidebarDrawer" aria-hidden="true">
    <div class="sidebar-drawer__profile">
        <img src="<?= htmlspecialchars($__avatarSrc) ?>" alt="" class="sidebar-drawer__avatar" onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($__me['fullname']) ?>&background=0F8B8D&color=fff'">
        <div>
            <div class="sidebar-drawer__name"><?= htmlspecialchars($__me['fullname']) ?></div>
            <div class="sidebar-drawer__role"><?= $__userType === 'driver' ? 'Driver account' : 'Rider account' ?></div>
        </div>
        <button type="button" class="icon-pill icon-pill--ghost" id="sidebarCloseBtn" aria-label="Close menu"><i class="bi bi-x-lg"></i></button>
    </div>

    <nav class="sidebar-drawer__nav">
        <a href="profile.php"><i class="bi bi-person"></i>Profile</a>
        <?php if ($__userType === 'driver'): ?>
        <a href="#" data-open-sheet-id="vehicleVerificationSheet"><i class="bi bi-car-front"></i>My Vehicle</a>
        <a href="pickup.php"><i class="bi bi-geo-alt-fill"></i>Pickup Ride</a>
        <?php else: ?>
        <a href="dashboard.php"><i class="bi bi-people-fill"></i>Join Ride</a>
        <a href="private-pickup.php"><i class="bi bi-geo-alt-fill"></i>Pickup Ride</a>
        <?php endif; ?>
        <a href="#" data-open-sheet-id="walletSheet"><i class="bi bi-wallet2"></i>Wallet</a>
        <a href="trips.php"><i class="bi bi-signpost-split"></i>Trips</a>
        <?php if ($__userType === 'driver'): ?>
        <a href="trips.php?tab=completed"><i class="bi bi-clock-history"></i>Ride History</a>
        <a href="profile.php?open=ratings"><i class="bi bi-star"></i>Ratings</a>
        <a href="#" data-open-sheet-id="modeSwitchSheet"><i class="bi bi-arrow-left-right"></i>Switch to Rider</a>
        <?php elseif ($__hasDriverCapability): ?>
        <a href="#" data-open-sheet-id="modeSwitchSheet"><i class="bi bi-arrow-left-right"></i>Switch to Driver</a>
        <?php else: ?>
        <a href="#" data-open-sheet-id="modeSwitchSheet"><i class="bi bi-car-front"></i>Become a Driver</a>
        <?php endif; ?>
        <a href="#" data-open-sheet-id="networkSheet" id="sidebarNetworkLink"><i class="bi bi-share-fill"></i>MOHTOH NETWORK</a>
        <a href="ride-requests.php"><i class="bi bi-signpost"></i>Ride Requests</a>
        <a href="profile.php?open=settings"><i class="bi bi-gear"></i>Settings</a>
        <a href="mailto:support@meritdrives.com"><i class="bi bi-life-preserver"></i>Support</a>
        <!-- Install PWA Button (Hidden by default, shown if installation supported) -->
        <button type="button" class="btn btn-primary u-hidden u-mt-4 u-mx-3" id="pwaSidebarInstallBtn" style="width: calc(100% - var(--space-6)); font-size: var(--text-sm);">
            <i class="bi bi-download"></i> Install MohToh App
        </button>
    </nav>

    <button type="button" class="sidebar-drawer__logout" id="sidebarLogoutBtn">
        <i class="bi bi-box-arrow-right"></i>Log Out
    </button>
</aside>
<?php
// Mode-switching sheet lives with the drawer that opens it, so every page
// carrying this partial gets it without a per-page include to forget.
include __DIR__ . '/mode-switch-sheet.php';
?>
