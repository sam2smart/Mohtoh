<?php
// partials/bottom-nav.php
// Expects $activeTab ('home'|'trips'|'messages'|'profile') and
// $userType ('rider'|'driver') from the including page.
$__active = $activeTab ?? 'home';
$__type = $userType ?? ($_SESSION['user_type'] ?? 'rider');
$__homeHref = $__type === 'driver' ? 'dashboard.php' : 'dashboard.php';
// Both roles have their own trips.php (driver/trips.php, rider/trips.php)
// and both set $activeTab = 'trips', so this nav item highlights there —
// it has to actually land there too.
$__tripsHref = 'trips.php';
$__centerAction = $__type === 'driver' ? 'create-ride' : 'focus-search';
$__centerIcon = $__type === 'driver' ? 'bi-plus-lg' : 'bi-search';
?>
<nav class="bottom-nav layer-bottom-nav" aria-label="Primary">
    <a class="bottom-nav__item <?= $__active === 'home' ? 'is-active' : '' ?>" href="<?= $__homeHref ?>">
        <i class="bi bi-house<?= $__active === 'home' ? '-fill' : '' ?>"></i><span>Home</span>
    </a>
    <a class="bottom-nav__item <?= $__active === 'trips' ? 'is-active' : '' ?>" href="<?= $__tripsHref ?>">
        <i class="bi bi-signpost-split<?= $__active === 'trips' ? '-fill' : '' ?>"></i><span>Trips</span>
    </a>
    <button type="button" class="bottom-nav__center" data-center-action="<?= $__centerAction ?>" aria-label="<?= $__type === 'driver' ? 'Create ride' : 'Search rides' ?>">
        <i class="bi <?= $__centerIcon ?>"></i>
    </button>
    <a class="bottom-nav__item <?= $__active === 'messages' ? 'is-active' : '' ?>" href="#" data-open-sheet-id="messagesSheet">
        <span class="bottom-nav__icon-wrap">
            <i class="bi bi-chat-dots<?= $__active === 'messages' ? '-fill' : '' ?>"></i>
            <span class="notif-dot u-hidden" id="messagesNavDot"></span>
        </span>
        <span>Messages</span>
    </a>
    <a class="bottom-nav__item <?= $__active === 'profile' ? 'is-active' : '' ?>" href="profile.php">
        <i class="bi bi-person<?= $__active === 'profile' ? '-fill' : '' ?>"></i><span>Profile</span>
    </a>
</nav>
