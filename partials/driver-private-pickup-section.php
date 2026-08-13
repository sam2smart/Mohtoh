<?php
// partials/driver-private-pickup-section.php
// The driver-side Private Pickup (SYSTEM 2) control surface: today's
// availability prompt, the Private Pickup-only online/offline toggle, the
// live request feed, and the ineligibility reason.
//
// Extracted verbatim from driver/ride-requests.php so the dedicated
// driver/pickup.php screen (what the sidebar's "Pickup Ride" opens) and any
// other host render the SAME markup and ids. assets/js/driver-private-pickup.js
// binds to these ids and no-ops when #pickupSection is absent, so including
// this partial is the only thing required to light the feature up.
//
// Keke + private car lane, and every read/accept is gated server-side on
// eligibility (verified + eligible vehicle + online + pickup enabled +
// available today + fresh GPS + within radius). Nothing here decides
// eligibility itself.
?>
<section id="pickupSection" class="u-mb-5">
    <div class="u-flex u-justify-between u-items-center u-mb-2">
        <h2 class="u-text-base u-text-strong" style="display:flex;align-items:center;gap:8px;"><i class="bi bi-scooter" style="color:var(--color-primary);"></i> Private Pickup</h2>
        <span id="pickupStatusBadge" class="pricing-badge is-warning">Loading…</span>
    </div>
    <p class="u-text-xs u-text-soft u-mb-3" id="pickupSubtext"></p>

    <div id="pickupPrompt" class="card u-mb-3 u-hidden" style="padding:var(--space-4);">
        <p class="u-text-sm u-text-strong u-mb-1">Would you like to receive Private Pickup requests today?</p>
        <p class="u-text-xs u-text-soft u-mb-3">This turns your online availability on for Private Pickup only. Your Join Ride supply is never affected.</p>
        <div class="u-flex u-gap-2">
            <button type="button" class="btn btn-primary btn-sm" style="width:auto;flex:1;" id="pickupYesBtn">Yes, receive requests</button>
            <button type="button" class="btn btn-outline btn-sm" style="width:auto;flex:1;" id="pickupNoBtn">No, not today</button>
        </div>
    </div>

    <!-- Online/offline controls ONLY Private Pickup discoverability.
         Join Ride is never affected by this toggle. -->
    <div id="pickupOnlineRow" class="card u-mb-3 u-hidden" style="padding:var(--space-3) var(--space-4);">
        <div class="u-flex u-justify-between u-items-center">
            <div>
                <div class="u-text-sm u-text-strong" id="pickupOnlineLabel">Offline</div>
                <div class="u-text-xs u-text-soft">Only affects Private Pickup — Join Ride stays on.</div>
            </div>
            <button type="button" class="btn btn-outline btn-sm" style="width:auto;" id="pickupOnlineToggle">Go online</button>
        </div>
    </div>

    <div id="pickupListContainer">
        <div class="u-text-center" style="padding:24px 0;"><span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:22px;height:22px;display:inline-block;"></span></div>
    </div>
    <div id="pickupEmpty" class="notification-empty u-hidden">No Private Pickup requests near you right now.</div>
    <div id="pickupIneligible" class="card u-mb-3 u-hidden" style="padding:var(--space-4);">
        <p class="u-text-sm u-text-strong u-mb-1">Private Pickup is not available to you right now</p>
        <p class="u-text-xs u-text-soft" id="pickupIneligibleReason"></p>
    </div>
</section>
