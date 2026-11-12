<?php
// partials/mode-switch-sheet.php
// Rider <-> Driver <-> Car/Keke mode switching UI. Included by
// partials/sidebar.php itself (rather than by each page), so every screen
// that has the drawer gets the sheet automatically and none can drift out
// of sync. Same .sheet pattern as every other sheet — opening is handled by
// the generic [data-open-sheet-id] hook in assets/js/navigation.js.
//
// The shell below is static; assets/js/navigation.js fills it from
// api/switch-mode.php?action=get_modes each time it opens, so what the user
// sees is what the SERVER believes about their capability, vehicle class and
// verification state — not a guess made in the page template. Buttons are
// disabled from that same response, and the backend re-checks every rule
// anyway, so a re-enabled button still cannot switch to an unauthorised mode.
$__msMe = $currentUser ?? [];
?>
<div class="sheet" id="modeSwitchSheet" role="dialog" aria-modal="true" aria-labelledby="modeSwitchSheetTitle">
    <div class="sheet-handle"></div>
    <div class="sheet-header">
        <div>
            <h2 class="sheet-title" id="modeSwitchSheetTitle">Switch Mode</h2>
            <p class="u-text-xs u-text-faint" style="margin:0;">One MohToh account. Switch any time — your vehicle, verification, wallet and trip history stay exactly as they are.</p>
        </div>
        <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
    </div>

    <section class="card u-mb-4" style="padding:var(--space-4);background:var(--color-primary-50);">
        <div class="u-text-xs u-text-faint u-mb-1">Currently using MohToh as</div>
        <div id="msCurrentMode" style="font-family:var(--font-display);font-weight:700;font-size:var(--text-lg);color:var(--color-primary);">
            <?php $__msMode = $__msMe['user_type'] ?? 'rider'; echo $__msMode === 'admin' ? 'Admin' : ($__msMode === 'driver' ? 'Driver' : 'Rider'); ?>
        </div>
        <div class="u-text-xs u-text-faint" id="msCurrentDetail"></div>
    </section>

    <!-- ================= Admin (multi-role: a rider/driver who is also an admin) ================= -->
    <section class="u-mb-4 u-hidden" id="msAdminSection">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Admin</h3>
        <p class="u-text-xs u-text-faint u-mb-2">Manage the platform. Your rider/driver account, wallet and trip history stay exactly as they are.</p>
        <button type="button" class="btn btn-primary" id="msSwitchAdminBtn"><i class="bi bi-shield-lock"></i> Switch to Admin</button>
    </section>

    <!-- ================= Ride ================= -->
    <section class="u-mb-4 u-hidden" id="msRiderSection">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Ride</h3>
        <p class="u-text-xs u-text-faint u-mb-2">Book and join rides as a passenger. Your driver account is kept intact.</p>
        <button type="button" class="btn btn-outline" id="msSwitchRiderBtn"><i class="bi bi-person-walking"></i> Switch to Rider</button>
    </section>

    <!-- ================= Drive (already a driver) ================= -->
    <section class="u-mb-4 u-hidden" id="msDriverSection">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Drive</h3>
        <p class="u-text-xs u-text-faint u-mb-2" id="msDriverHint"></p>
        <button type="button" class="btn btn-primary u-mb-4" id="msSwitchDriverBtn"><i class="bi bi-car-front"></i> Switch to Driver</button>

        <div class="u-text-xs u-text-faint u-mb-2">Operating class — set by your verified vehicle</div>
        <div class="u-flex u-gap-2" style="flex-wrap:wrap;" id="msClassRow">
            <button type="button" class="btn btn-outline btn-sm" data-ms-class="car" style="width:auto;flex:1;min-width:120px;"><i class="bi bi-car-front"></i> Car</button>
            <button type="button" class="btn btn-outline btn-sm" data-ms-class="keke" style="width:auto;flex:1;min-width:120px;"><i class="bi bi-truck-front"></i> Keke Napep</button>
        </div>
        <div class="u-text-xs u-text-faint u-mt-2" id="msClassNote"></div>
    </section>

    <!-- ================= Become a Driver (rider, no driver capability) ================= -->
    <section class="u-mb-4 u-hidden" id="msBecomeSection">
        <h3 class="u-text-sm u-mb-2" style="font-weight:700;">Become a Driver</h3>
        <p class="u-text-xs u-text-faint u-mb-2">Choose what you drive. You keep this same account — next you'll add your vehicle details and documents for verification, and you can switch back to riding whenever you like.</p>
        <div class="u-flex u-gap-2" style="flex-wrap:wrap;">
            <button type="button" class="btn btn-outline btn-sm" data-ms-become="car" style="width:auto;flex:1;min-width:120px;"><i class="bi bi-car-front"></i> I drive a Car</button>
            <button type="button" class="btn btn-outline btn-sm" data-ms-become="keke" style="width:auto;flex:1;min-width:120px;"><i class="bi bi-truck-front"></i> I drive a Keke</button>
        </div>
    </section>

    <div class="u-text-xs u-text-faint" id="msStatus">Loading your modes…</div>
</div>
