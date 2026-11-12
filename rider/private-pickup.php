<?php
// rider/private-pickup.php
// Private Solo Pickup (SYSTEM 2) — the RIDER side. Deliberately a
// SEPARATE screen from rider/ride-requests.php (Join Ride demand,
// SYSTEM 1): a private pickup is an immediate, solo, whole-vehicle keke
// trip matched by live GPS proximity, not a dated seat-sharing request.
// The two flows must never be presented as the same feature.
//
// Pure UI — no direct database access. All behaviour goes through
// api/private-pickup.php (create / status / cancel), which is the single
// authority on eligibility, fare, radius and matching.
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

$pageTitle = 'Private Pickup';
$activeTab = 'ride-requests';
$userType = 'rider';

// Radius is administratively configurable — read it, never hard-code it,
// so the copy the rider sees always matches the actual matching rule.
$pickupRadiusKm = (float) get_system_setting($pdo, 'private_pickup_radius_km', 20);

// Resume a live request across a full reload or tab close — not only via
// sessionStorage. A pickup can sit in "driver on the way" / "arrived" for
// minutes, and the arrival confirmation (criterion #11) must stay reachable
// the whole time, so the page bootstraps the rider's current active request
// id from the server. The client re-polls its real status from this id.
$activeReq = $pdo->prepare(
    "SELECT public_id FROM ride_requests
     WHERE rider_id = ? AND request_type = 'private_pickup'
       AND status IN ('pending','accepted','in_progress')
     ORDER BY id DESC LIMIT 1"
);
$activeReq->execute([(int)$_SESSION['user_id']]);
$activePickupId = (string)($activeReq->fetchColumn() ?: '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Private Pickup</title>

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
<body data-user-type="rider" data-pickup-radius-km="<?php echo htmlspecialchars((string)$pickupRadiusKm, ENT_QUOTES); ?>" data-active-pickup-id="<?php echo htmlspecialchars($activePickupId, ENT_QUOTES); ?>">

<div class="app-shell screen">
    <?php include __DIR__ . '/../partials/app-header.php'; ?>

    <div class="screen-scroll screen-scroll--app">

        <!-- Lane chooser: makes the Join Ride / Private Solo separation
             explicit rather than implied (criterion #9). -->
        <div class="trip-tabs" role="tablist">
            <a class="trip-tab" href="ride-requests.php" role="tab">Join a Ride</a>
            <button type="button" class="trip-tab is-active" role="tab" aria-current="page">Private Solo Pickup</button>
        </div>

        <p class="u-text-sm u-text-soft u-mb-4">
            A whole vehicle to yourself, picked up where you are now. We alert verified Keke and private car
            drivers within <span id="ppRadiusLabel"><?php echo htmlspecialchars(rtrim(rtrim(number_format($pickupRadiusKm, 1), '0'), '.'), ENT_QUOTES); ?></span> KM of your pickup point — the first to accept comes to you.
        </p>

        <!-- ============ STATE 1: request form ============ -->
        <section id="ppFormSection">
            <div id="ppFormAlert" class="alert-banner is-hidden"></div>
            <form id="ppForm" class="card" style="padding:var(--space-4);">
                <div class="field-group">
                    <label class="field-label">Pickup location</label>
                    <div class="field">
                        <i class="bi bi-record-circle" style="color:var(--color-primary);"></i>
                        <input type="text" id="ppPickup" placeholder="Where should the driver meet you?" required autocomplete="off">
                        <button type="button" class="field-toggle-visibility" id="ppUseLocationBtn" title="Use my current location" aria-label="Use my current location"><i class="bi bi-crosshair"></i></button>
                    </div>
                    <input type="hidden" id="ppPickupLat">
                    <input type="hidden" id="ppPickupLng">
                    <p class="u-text-xs u-text-faint u-mt-1">Drivers are matched by distance to this exact point, so pick it from the suggestions or use your current location.</p>
                </div>

                <div class="field-group">
                    <label class="field-label">Destination</label>
                    <div class="field">
                        <i class="bi bi-geo-alt-fill" style="color:var(--color-danger);"></i>
                        <input type="text" id="ppDestination" placeholder="Where are you headed?" required autocomplete="off">
                    </div>
                    <input type="hidden" id="ppDestinationLat">
                    <input type="hidden" id="ppDestinationLng">
                </div>

                <!-- Vehicle class the passenger wants (criterion #5). Private
                     Car and Private Keke are separate lanes with separate
                     pricing; the request is matched only to drivers of the
                     chosen class. Required before the request can be sent. -->
                <div class="field-group">
                    <label class="field-label">Choose your vehicle</label>
                    <div class="u-flex u-gap-2" id="ppVehicleChoices" role="radiogroup" aria-label="Vehicle type">
                        <button type="button" class="pp-vehicle-choice card" data-vehicle-type="car" role="radio" aria-checked="false" style="flex:1;padding:var(--space-3);text-align:left;cursor:pointer;border:2px solid transparent;">
                            <div class="u-flex u-items-center u-gap-2 u-mb-1"><i class="bi bi-car-front-fill" style="color:var(--color-primary);font-size:1.1rem;"></i><span class="u-text-sm u-text-strong">Private Car</span></div>
                            <div class="u-text-xs u-text-faint" data-vehicle-fare="car">—</div>
                        </button>
                        <button type="button" class="pp-vehicle-choice card" data-vehicle-type="keke" role="radio" aria-checked="false" style="flex:1;padding:var(--space-3);text-align:left;cursor:pointer;border:2px solid transparent;">
                            <div class="u-flex u-items-center u-gap-2 u-mb-1"><i class="bi bi-scooter" style="color:var(--color-primary);font-size:1.1rem;"></i><span class="u-text-sm u-text-strong">Private Keke</span></div>
                            <div class="u-text-xs u-text-faint" data-vehicle-fare="keke">—</div>
                        </button>
                    </div>
                    <input type="hidden" id="ppVehicleType">
                </div>

                <div class="field-group">
                    <label class="field-label">Notes for the driver (optional)</label>
                    <textarea id="ppNotes" class="field" style="height:66px;align-items:flex-start;padding-top:12px;" placeholder="Landmark, gate colour, anything that helps them find you"></textarea>
                </div>

                <!-- Fare estimate, filled from the server's own settings once
                     both points are chosen. Never computed in JS. -->
                <div id="ppEstimate" class="u-hidden u-mb-3" style="padding:var(--space-3);background:var(--color-surface-alt,rgba(15,139,141,.06));border-radius:var(--radius-md,10px);">
                    <div class="u-flex u-justify-between u-items-center">
                        <span class="u-text-xs u-text-soft">Estimated fare</span>
                        <span class="u-text-base u-text-strong" id="ppEstimateFare">—</span>
                    </div>
                    <div class="u-text-xs u-text-faint u-mt-1" id="ppEstimateMeta"></div>
                </div>

                <button type="submit" class="btn btn-primary" id="ppSubmitBtn"><i class="bi bi-scooter"></i> Request Private Pickup</button>
            </form>
        </section>

        <!-- ============ STATE 2: searching ============ -->
        <section id="ppSearchingSection" class="u-hidden">
            <div class="card u-text-center" style="padding:var(--space-6) var(--space-4);">
                <div class="u-mb-3">
                    <span class="btn-spinner" style="border-color:rgba(15,139,141,.25);border-top-color:var(--color-primary);width:38px;height:38px;display:inline-block;"></span>
                </div>
                <h2 class="u-text-base u-text-strong u-mb-1" id="ppSearchingTitle">Searching for a nearby driver…</h2>
                <p class="u-text-sm u-text-soft u-mb-3" id="ppSearchingSubtext"></p>
                <div class="u-flex u-justify-center u-gap-2 u-mb-4">
                    <span class="pricing-badge is-info" id="ppSearchingFare">—</span>
                    <span class="pricing-badge is-neutral" id="ppSearchingCountdown">—</span>
                </div>
                <div class="u-text-left u-mb-4" style="padding:var(--space-3);background:var(--color-surface-alt,rgba(15,139,141,.06));border-radius:var(--radius-md,10px);">
                    <div class="ride-tile__dest" style="margin:0 0 4px;"><i class="bi bi-record-circle" style="color:var(--color-primary);font-size:.7rem;"></i><span id="ppSearchingPickup"></span></div>
                    <div class="ride-tile__dest" style="margin:0;"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);font-size:.7rem;"></i><span id="ppSearchingDest"></span></div>
                </div>
                <button type="button" class="btn btn-outline" id="ppCancelBtn">Cancel request</button>
            </div>
        </section>

        <!-- ============ STATE 2b: driver proposed a new fare ============ -->
        <!-- A matched driver (typically a Keke, whose fare includes the run to
             your pickup point) proposed a fare different from your opening
             offer. It is NOT applied until you accept (criterion #3). -->
        <section id="ppProposalSection" class="u-hidden">
            <div class="card" style="padding:var(--space-5) var(--space-4);border-left:3px solid var(--color-warning,#E8A33D);">
                <div class="u-flex u-items-center u-gap-2 u-mb-3">
                    <i class="bi bi-cash-coin" style="color:var(--color-warning,#E8A33D);font-size:1.4rem;"></i>
                    <h2 class="u-text-base u-text-strong" style="margin:0;">Driver proposed a fare</h2>
                </div>
                <p class="u-text-sm u-text-soft u-mb-3" id="ppProposalDriver">A nearby driver offered to take your pickup.</p>
                <div class="u-mb-4" style="padding:var(--space-3);background:var(--color-surface-alt,rgba(15,139,141,.06));border-radius:var(--radius-md,10px);">
                    <div class="u-flex u-justify-between u-items-center u-mb-1">
                        <span class="u-text-xs u-text-soft">Your offer</span>
                        <span class="u-text-sm" id="ppProposalOffer" style="text-decoration:line-through;opacity:.7;">—</span>
                    </div>
                    <div class="u-flex u-justify-between u-items-center">
                        <span class="u-text-sm u-text-strong">Driver's fare</span>
                        <span class="u-text-lg u-text-strong" id="ppProposalFare" style="color:var(--color-primary);">—</span>
                    </div>
                    <div class="u-text-xs u-text-faint u-mt-2" id="ppProposalNote"></div>
                </div>
                <div class="u-flex u-gap-2">
                    <button type="button" class="btn btn-primary" style="width:auto;flex:1;" id="ppAcceptFareBtn">Accept fare</button>
                    <button type="button" class="btn btn-outline" style="width:auto;flex:1;" id="ppRejectFareBtn">Decline</button>
                </div>
            </div>
        </section>

        <!-- ============ STATE 3: driver found ============ -->
        <section id="ppFoundSection" class="u-hidden">
            <div class="card" style="padding:var(--space-5) var(--space-4);border-left:3px solid var(--color-success,#1B998B);">
                <div class="u-flex u-items-center u-gap-2 u-mb-3">
                    <i class="bi bi-check-circle-fill" style="color:var(--color-success,#1B998B);font-size:1.4rem;"></i>
                    <h2 class="u-text-base u-text-strong" style="margin:0;">Driver found</h2>
                </div>
                <p class="u-text-sm u-text-soft u-mb-3">Your driver accepted and is on the way to your pickup point. Their vehicle is shown below.</p>

                <!-- Agreed fare, shown prominently and identical to the driver's
                     number (criteria #2/#6). This is THE price for the trip. -->
                <div class="u-flex u-justify-between u-items-center u-mb-3" style="padding:var(--space-3);background:rgba(27,153,139,.10);border-radius:var(--radius-md,10px);">
                    <span class="u-text-sm u-text-strong"><i class="bi bi-check-circle-fill" style="color:var(--color-success,#1B998B);"></i> Agreed fare</span>
                    <span class="u-text-lg u-text-strong" id="ppAgreedFare" style="color:var(--color-primary);">—</span>
                </div>

                <div class="u-mb-4" style="padding:var(--space-3);background:var(--color-surface-alt,rgba(15,139,141,.06));border-radius:var(--radius-md,10px);">
                    <div class="u-text-sm u-text-strong" id="ppDriverName">—</div>
                    <div class="u-text-xs u-text-soft u-mt-1" id="ppDriverMeta"></div>
                </div>

                <!-- Arrival confirmation (criteria #11/#12). Shown only once the
                     driver marks arrival; confirming here is what lets the
                     driver start the trip - the backend refuses to start until
                     this is done. -->
                <div id="ppArrivalBlock" class="u-hidden u-mb-4" style="padding:var(--space-3);background:rgba(232,163,61,.10);border-radius:var(--radius-md,10px);">
                    <div class="u-text-sm u-text-strong u-mb-1"><i class="bi bi-geo-alt-fill" style="color:var(--color-warning,#E8A33D);"></i> Your driver says they've arrived</div>
                    <p class="u-text-xs u-text-soft u-mb-3">Confirm only when you can see your driver at the pickup point. Your driver cannot start the trip until you confirm.</p>
                    <button type="button" class="btn btn-primary btn-sm" id="ppConfirmArrivalBtn">Confirm my driver has arrived</button>
                </div>
                <div id="ppArrivalConfirmed" class="u-hidden u-mb-4" style="padding:var(--space-3);background:rgba(27,153,139,.10);border-radius:var(--radius-md,10px);">
                    <div class="u-text-sm u-text-strong"><i class="bi bi-check-circle-fill" style="color:var(--color-success,#1B998B);"></i> Arrival confirmed</div>
                    <p class="u-text-xs u-text-soft" style="margin:4px 0 0;">Your driver can now start the trip.</p>
                </div>

                <a class="btn btn-primary" id="ppOpenTripBtn" href="trips.php"><i class="bi bi-geo"></i> Track my trip</a>
            </div>
        </section>

        <!-- ============ STATE 4: no driver available ============ -->
        <section id="ppNoDriverSection" class="u-hidden">
            <div class="card u-text-center" style="padding:var(--space-6) var(--space-4);">
                <i class="bi bi-emoji-frown" style="font-size:2rem;color:var(--color-warning,#E8A33D);"></i>
                <h2 class="u-text-base u-text-strong u-mt-3 u-mb-1" id="ppNoDriverTitle">No available driver nearby</h2>
                <p class="u-text-sm u-text-soft u-mb-4" id="ppNoDriverSubtext">
                    We couldn't find a verified Keke or private car driver online within range of your pickup point right now.
                </p>
                <div class="u-flex u-gap-2">
                    <button type="button" class="btn btn-primary" style="width:auto;flex:1;" id="ppRetryBtn">Try again</button>
                    <a class="btn btn-outline" style="width:auto;flex:1;" href="ride-requests.php">Join a Ride instead</a>
                </div>
            </div>
        </section>

    </div>

    <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
</div>

<?php include __DIR__ . '/../partials/sidebar.php'; ?>

<div class="sheet-scrim" data-sheet-scrim></div>

<?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
<?php include __DIR__ . '/../partials/messages-sheet.php'; ?>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/sound-manager.js"></script>
<script src="../assets/js/mohtoh-bridge.js"></script>
<script src="../assets/js/pwa-init.js"></script>
<script src="../assets/js/navigation.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/rider-private-pickup.js"></script>
</body>
</html>
