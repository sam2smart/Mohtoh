<?php
// partials/app-header.php
// Floating glass header used on full-screen map layers (Home, Ride
// Search, Live Trip). Expects $pageTitle (optional) from the caller.
// Optional $showAvailabilityToggle (bool) + $isOnline (bool) add the
// driver online/offline pill — the same header used by riders, just
// with one more slot filled in, not a second header.
$__showAvailability = !empty($showAvailabilityToggle);
$__isOnline = !empty($isOnline);
?>
<header class="app-floating-header layer-header container-pad">
    <button type="button" class="icon-pill" id="hamburgerBtn" aria-label="Open menu" aria-expanded="false" aria-controls="sidebarDrawer">
        <i class="bi bi-list"></i>
    </button>

    <?php if (!empty($pageTitle)): ?>
        <span class="app-floating-header__title"><?= htmlspecialchars($pageTitle) ?></span>
    <?php else: ?>
        <span class="app-floating-header__brand"><span class="brand-dot-sm"><i class="bi bi-signpost-split-fill"></i></span>MohToh</span>
    <?php endif; ?>

    <div class="app-floating-header__actions">
        <?php if ($__showAvailability): ?>
        <button type="button" class="availability-pill <?= $__isOnline ? 'is-online' : 'is-offline' ?>" id="availabilityToggle" aria-pressed="<?= $__isOnline ? 'true' : 'false' ?>">
            <span class="availability-pill__dot"></span>
            <span id="availabilityLabel"><?= $__isOnline ? 'Online' : 'Offline' ?></span>
        </button>
        <?php endif; ?>
        <button type="button" class="icon-pill" id="notificationBtn" aria-label="Notifications" data-open-sheet-id="notificationSheet">
            <i class="bi bi-bell"></i>
            <span class="notif-dot is-hidden" id="notificationDot"></span>
        </button>
    </div>
</header>
