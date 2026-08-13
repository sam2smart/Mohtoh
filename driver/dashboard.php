<?php
// driver/dashboard.php
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
if ($_SESSION['user_type'] !== 'driver') {
    $roleHome = ['rider' => '../rider/dashboard.php', 'admin' => '../admin/dashboard.php'][$_SESSION['user_type']] ?? '../index.php';
    header('Location: ' . $roleHome);
    exit;
}

require_once __DIR__ . '/../includes/current_user.php';

$pageTitle = null;
$activeTab = 'home';
$userType = 'driver';
$showAvailabilityToggle = true;
$isOnline = $currentUser['is_online'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh Driver — Home</title>

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
            <!-- ================= Vehicle verification warning ================= -->
            <div class="alert-banner error" id="vehicleWarningCard" style="align-items:center;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <div style="flex:1;">
                    <div id="vehicleStatusText">Checking your vehicle status…</div>
                    <button type="button" class="btn-text u-text-xs" style="font-weight:700;" data-open-sheet-id="vehicleVerificationSheet">Go to verification <i class="bi bi-arrow-right"></i></button>
                </div>
            </div>

            <!-- ================= Status card ================= -->
            <section class="card status-card anim-slide-up">
                <div class="status-card__top">
                    <div class="status-card__state">
                        <label class="toggle-switch">
                            <input type="checkbox" id="onlineToggleSwitch" <?= $isOnline ? 'checked' : '' ?>>
                            <span class="toggle-switch__track"></span>
                        </label>
                        <div>
                            <div class="status-card__state-label"><?= htmlspecialchars($currentUser['fullname']) ?></div>
                            <div class="status-card__state-sub" id="statusLocationValue"><i class="bi bi-geo-alt"></i> Locating…</div>
                        </div>
                    </div>
                    <span id="driverRatingValue" class="rating-chip"><i class="bi bi-star-fill"></i> <?= $currentUser['rating'] !== null ? number_format($currentUser['rating'], 1) : '—' ?></span>
                </div>
                <div class="status-card__grid">
                    <div class="status-card__cell">
                        <div class="status-card__cell-label"><i class="bi bi-car-front"></i> Vehicle</div>
                        <div class="status-card__cell-value" id="statusVehicleName">—</div>
                    </div>
                    <div class="status-card__cell">
                        <div class="status-card__cell-label"><i class="bi bi-credit-card-2-front"></i> Plate number</div>
                        <div class="status-card__cell-value" id="statusVehiclePlate">—</div>
                    </div>
                </div>

                <!-- Keke driver identity. Rendered server-side from the
                     driver's real, derived classification (includes/
                     current_user.php -> users.is_keke_driver, which
                     sync_keke_classification() keeps tied to the verified
                     vehicle) so it can never disagree with what the
                     eligibility gate will actually allow. Hidden entirely
                     for car drivers rather than shown as "not a keke". -->
                <?php if ($currentUser['is_keke_driver']): ?>
                    <?php
                    $kekeVerified = $currentUser['vehicle_verification_status'] === 'verified';
                    $kekeStatusMeta = [
                        'verified'  => ['label' => 'Verified',  'class' => 'is-value'],
                        'pending'   => ['label' => 'Pending',   'class' => 'is-accent'],
                        'rejected'  => ['label' => 'Rejected',  'class' => 'is-danger'],
                        'suspended' => ['label' => 'Suspended', 'class' => 'is-danger'],
                    ][$currentUser['vehicle_verification_status']] ?? ['label' => 'Not submitted', 'class' => 'is-neutral'];
                    ?>
                    <div class="u-flex u-items-center u-gap-2 u-mt-3" id="kekeStatusRow">
                        <span class="pricing-badge is-info"><i class="bi bi-scooter"></i> Keke Driver</span>
                        <span class="pricing-badge <?= $kekeStatusMeta['class'] ?>">Verification: <?= htmlspecialchars($kekeStatusMeta['label']) ?></span>
                    </div>
                    <?php if ($kekeVerified && $currentUser['private_pickup_enabled']): ?>
                        <a href="ride-requests.php" class="btn-text u-text-xs u-mt-2" style="font-weight:700;display:inline-block;">
                            <i class="bi bi-arrow-right-circle"></i> Go to Private Pickup
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
                <button type="button" class="status-card__performance-teaser" id="performanceTeaser" data-open-sheet-id="performanceSheet">
                    <span id="performanceTeaserText"><i class="bi bi-graph-up"></i> Today's performance</span>
                    <i class="bi bi-chevron-right"></i>
                </button>
            </section>

            <!-- ================= Quick actions ================= -->
            <section class="quick-action-grid">
                <button type="button" class="quick-action-tile" id="quickStartRideBtn" disabled><i class="bi bi-play-circle"></i><span>Start ride</span></button>
                <button type="button" class="quick-action-tile" data-quick-action="create-ride"><i class="bi bi-plus-circle"></i><span>Create ride</span></button>
                <button type="button" class="quick-action-tile" data-quick-action="passengers"><i class="bi bi-people"></i><span>Passengers</span></button>
                <button type="button" class="quick-action-tile" data-open-sheet-id="walletSheet"><i class="bi bi-wallet2"></i><span>Wallet</span></button>
            </section>

            <!-- ================= Premium teaser (Part 2) ================= -->
            <a href="profile.php?open=premium" class="card u-flex u-items-center u-gap-3 anim-slide-up" id="premiumTeaserCard" style="padding:var(--space-4);color:inherit;text-decoration:none;">
                <i class="bi bi-award" style="font-size:1.4rem;color:var(--color-accent-600);"></i>
                <div style="flex:1;min-width:0;">
                    <div class="u-text-xs u-text-faint" id="premiumTeaserPlan">Basic Plan</div>
                    <div style="font-weight:700;" id="premiumTeaserText">Upgrade to Premium</div>
                </div>
                <i class="bi bi-chevron-right u-text-faint"></i>
            </a>

            <!-- ================= Current ride strip ================= -->
            <section class="card" id="currentRideCard" style="padding:var(--space-4);"></section>

            <!-- ================= Upcoming rides (open trigger) ================= -->
            <button type="button" class="btn btn-outline" data-open-sheet-id="upcomingRidesSheet">
                <i class="bi bi-calendar-check"></i> View upcoming rides
            </button>
        </div>

        <div class="map-fab-stack">
            <button type="button" class="fab" id="recenterBtn" aria-label="Center on my location"><i class="bi bi-crosshair"></i></button>
        </div>

        <?php include __DIR__ . '/../partials/bottom-nav.php'; ?>
    </div>

    <?php include __DIR__ . '/../partials/sidebar.php'; ?>

    <div class="sheet-scrim" data-sheet-scrim></div>

    <?php include __DIR__ . '/../partials/notification-sheet.php'; ?>
    <?php include __DIR__ . '/../partials/messages-sheet.php'; ?>
    <?php include __DIR__ . '/../partials/community-rewards-sheet.php'; ?>

    <!-- ================= Upcoming rides sheet ================= -->
    <div class="sheet" id="upcomingRidesSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Upcoming rides</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="upcomingRidesList"></div>
    </div>

    <!-- ================= Passengers sheet ================= -->
    <div class="sheet" id="passengersSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Passengers</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="passengersList"></div>
    </div>

    <!-- ================= Today's performance sheet ================= -->
    <div class="sheet" id="performanceSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Today's performance</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="stat-tile-row" id="statTileRow"></div>
        <button type="button" class="btn btn-outline u-mt-4" data-open-sheet-id="walletSheet">
            <i class="bi bi-wallet2"></i> View wallet
        </button>
    </div>

    <!-- ================= Wallet sheet (Wallet Home) ================= -->
    <div class="sheet" id="walletSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Wallet</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="wallet-hero">
            <div class="wallet-hero__label">Available Balance</div>
            <div class="wallet-hero__amount" id="walletHeroAmount">₦0.00</div>
            <div class="wallet-hero__updated" id="walletHeroUpdated">Last updated —</div>
        </div>

        <div class="u-flex u-justify-between u-items-center u-mt-3" id="walletReservedRow">
            <div class="u-text-sm">
                <span class="u-text-soft">Total Wallet:</span> <strong id="walletTotalAmount">₦0.00</strong>
                &nbsp;·&nbsp;
                <span class="u-text-soft">Reserved for Active Trips:</span> <strong id="walletReservedAmount">₦0.00</strong>
            </div>
            <button type="button" class="btn-text u-text-xs" id="walletReservedInfoBtn">Why is money reserved?</button>
        </div>
        <div class="alert-banner u-mt-2 u-hidden" id="walletReservedInfo" style="background:var(--color-primary-50);color:var(--color-text);">
            MohToh temporarily reserves commission funds for active trips. Unused amounts are automatically released once a trip completes or is cancelled.
        </div>

        <div class="wallet-action-grid">
            <button type="button" class="wallet-action-btn wallet-action-btn--primary" data-open-sheet-id="topUpSheet"><i class="bi bi-plus-circle"></i><span>Top Up</span></button>
            <button type="button" class="wallet-action-btn" id="walletWithdrawBtn"><i class="bi bi-arrow-down-circle"></i><span>Withdraw</span></button>
            <button type="button" class="wallet-action-btn" data-open-sheet-id="transactionHistorySheet"><i class="bi bi-clock-history"></i><span>Transaction History</span></button>
            <button type="button" class="wallet-action-btn" data-open-sheet-id="paymentPreferenceSheet"><i class="bi bi-sliders"></i><span>Payment Preference</span></button>
        </div>

        <div class="section-label">Active Reservations</div>
        <div id="walletActiveReservationsList" class="card" style="padding:var(--space-4);">
            <div class="u-text-center u-text-faint u-text-sm">No active trip reservations.</div>
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

            <label class="field-label">Payment Method</label>
            <div class="choice-list">
                <label class="choice-option is-selected">
                    <input type="radio" name="topup_method" value="paystack" checked>
                    <div class="choice-option__body">
                        <div class="choice-option__title">Paystack</div>
                        <div class="choice-option__desc">Pay instantly with your card via Paystack.</div>
                    </div>
                </label>
                <label class="choice-option">
                    <input type="radio" name="topup_method" value="card">
                    <div class="choice-option__body">
                        <div class="choice-option__title">Card</div>
                        <div class="choice-option__desc">Add funds directly from a debit or credit card.</div>
                    </div>
                </label>
                <label class="choice-option">
                    <input type="radio" name="topup_method" value="bank_transfer">
                    <div class="choice-option__body">
                        <div class="choice-option__title">Bank Transfer</div>
                        <div class="choice-option__desc">Transfer from your bank account to your wallet.</div>
                    </div>
                </label>
            </div>
            <p class="u-text-xs u-text-faint" style="margin-top:-12px;margin-bottom:var(--space-4);">Top-ups are processed instantly for now — live payment gateway coming soon.</p>

            <button type="submit" class="btn btn-primary" id="topUpSubmitBtn">Top Up</button>
        </form>
    </div>

    <!-- ================= Payment Preference sheet ================= -->
    <div class="sheet" id="paymentPreferenceSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Payment Preference</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="paymentPreferenceAlert" class="alert-banner is-hidden"></div>

        <div class="choice-list" id="paymentPreferenceList">
            <label class="choice-option" data-value="wallet">
                <input type="radio" name="payment_preference" value="wallet">
                <div class="choice-option__body">
                    <div class="choice-option__title">MohToh Wallet Only</div>
                    <div class="choice-option__desc">Passengers pay through their MohToh wallet. You're paid automatically when the trip completes.</div>
                </div>
            </label>
            <label class="choice-option" data-value="direct">
                <input type="radio" name="payment_preference" value="direct">
                <div class="choice-option__body">
                    <div class="choice-option__title">Direct Payment Only</div>
                    <div class="choice-option__desc">Passengers pay you directly — cash or transfer. You confirm each payment after the trip, and MohToh's commission is deducted from your wallet.</div>
                </div>
            </label>
            <label class="choice-option" data-value="both">
                <input type="radio" name="payment_preference" value="both">
                <div class="choice-option__body">
                    <div class="choice-option__title">Both</div>
                    <div class="choice-option__desc">Passengers choose how to pay when they book — MohToh wallet or direct to you.</div>
                </div>
            </label>
        </div>

        <button type="button" class="btn btn-primary" id="savePaymentPreferenceBtn">Save preference</button>
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

    <!-- ================= Ride history sheet ================= -->
    <div class="sheet" id="rideHistorySheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Ride history</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="rideHistoryList"></div>
    </div>

    <!-- ================= Operation Selection Modal ================= -->
    <div class="sheet" id="rideOperationSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Select Operation</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="p-4" id="operationSelectionContainer">
            <button class="btn btn-outline w-100 u-mb-3 op-shared" onclick="selectOperation('join')"><i class="bi bi-people"></i> Join Ride</button>
            <!-- "Pickup Ride" is deliberately no longer offered as a Create ride
                 operation here: Join Ride is the only car operation a driver
                 creates from the dashboard. Nothing about Private Pickup itself
                 was removed — the engine, driver/pickup.php, ride-requests.php
                 and window.selectOperation('pickup') are all still in place, so
                 rider pickup requests keep working exactly as before. -->
            <button class="btn btn-outline w-100 u-mb-3 op-keke" onclick="selectOperation('keke')"><i class="bi bi-bicycle"></i> Keke / Maruwa</button>
        </div>
    </div>

    <!-- ================= Create ride sheet (modified) ================= -->
    <div class="sheet" id="createRideSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Create ride</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="createRideAlert" class="alert-banner is-hidden"></div>

        <form id="createRideForm">
            <input type="hidden" name="ride_id" id="createRideId" value="">
            <input type="hidden" name="pickup_lat" id="createRidePickupLat">
            <input type="hidden" name="pickup_lng" id="createRidePickupLng">
            <input type="hidden" name="destination_lat" id="createRideDestinationLat">
            <input type="hidden" name="destination_lng" id="createRideDestinationLng">

            <div class="field-group">
                <label class="field-label" for="createRidePickup">Pickup</label>
                <div class="field"><i class="bi bi-record-circle" style="color:var(--color-primary);"></i><input type="text" id="createRidePickup" name="pickup_location" placeholder="Pickup location" required></div>
                <button type="button" class="btn-text u-text-xs u-hidden u-mt-2" id="suggestPickupBtn">+ Suggest this as a MohToh location</button>
            </div>
            <div class="field-group">
                <label class="field-label" for="createRideDestination">Destination</label>
                <div class="field"><i class="bi bi-geo-alt-fill" style="color:var(--color-danger);"></i><input type="text" id="createRideDestination" name="destination" placeholder="Destination" required></div>
                <button type="button" class="btn-text u-text-xs u-hidden u-mt-2" id="suggestDestinationBtn">+ Suggest this as a MohToh location</button>
            </div>
            <div class="search-card__row">
                <div class="field-group">
                    <label class="field-label" for="createRideDate">Date</label>
                    <div class="field"><i class="bi bi-calendar3"></i><input type="date" id="createRideDate" name="departure_date" required></div>
                </div>
                <div class="field-group" style="flex:1;">
                    <label class="field-label" for="createRideTime">Time</label>
                    <div class="field"><i class="bi bi-clock"></i><input type="time" id="createRideTime" name="departure_time" required></div>
                    <div class="u-text-xs u-text-soft u-mt-2" id="createRideTimePreview">Pick a departure time</div>
                </div>
            </div>
            <div class="search-card__row">
                <div class="field-group">
                    <label class="field-label">Vehicle</label>
                    <div class="field" style="background:var(--color-surface);"><i class="bi bi-car-front"></i><span id="createRideVehicleSummary" class="u-text-sm">—</span></div>
                </div>
                <div class="field-group" style="max-width:120px;">
                    <label class="field-label">Seats</label>
                    <div class="field" style="background:var(--color-surface);"><i class="bi bi-people"></i><span id="createRideSeatsDisplay" class="u-text-sm">—</span></div>
                </div>
            </div>
            <p class="u-text-xs u-text-faint" style="margin-top:-8px;">Seat count comes from your approved vehicle and can't be edited here.</p>

            <div class="field-group">
                <label class="field-label">Ride Mode</label>
                <!-- Join Ride is the shared/carpool lane and only that lane. A
                     "Private Ride" option used to sit here, which put the
                     Private/Solo operation inside the Join Ride creation form —
                     the two are separate systems (a private trip is REQUESTED by
                     a rider through rider/private-pickup.php, matched within
                     private_pickup_radius_km, and spawned by
                     spawn_private_ride_from_request() as ride_mode='private').
                     It was also dead: api/rideshare.php's create action never
                     reads or inserts ride_mode, so every ride published here has
                     always been stored with the column default 'shared' no
                     matter which radio the driver picked. Shared Ride stays (it
                     is the existing Join Ride architecture, not Private Pickup). -->
                <div class="choice-list" id="rideModeList">
                    <label class="choice-option is-selected" data-value="shared">
                        <input type="radio" name="ride_mode" value="shared" checked>
                        <div class="choice-option__body">
                            <div class="choice-option__title">Shared Ride</div>
                            <div class="choice-option__desc">Passengers pay per seat.</div>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Pricing Intelligence Assistant -->
            <div id="pricingAssistantContainer" class="u-mb-4"></div>

            <!-- Part 2 (Premium Driver Features): "Priority Ride Visibility" /
                 "More Ride Exposure" — shown only while active (driver.js). -->
            <div class="u-text-xs u-mb-2 u-hidden" id="createRidePremiumBadge" style="color:var(--color-accent-600);font-weight:600;"><i class="bi bi-award-fill"></i> Priority visibility active for this ride</div>
            <div class="card" id="pricePreviewCard" style="background:var(--color-primary-50);padding:var(--space-4);margin:var(--space-2) 0 var(--space-4);">
                <div id="pricePreviewEmpty" class="u-text-sm u-text-soft">Pick a pickup and destination to see the recommended price.</div>
                <div id="pricePreviewContent" class="u-hidden">
                    <div class="u-flex u-justify-between u-items-center u-mb-2">
                        <span class="u-text-xs u-text-soft"><i class="bi bi-signpost-2"></i> <span id="previewDistance">—</span> km trip</span>
                        <span class="pricing-mode-pill" id="pricingModePill">—</span>
                    </div>
                    <div class="u-flex u-gap-2 u-mb-2" id="pricingBadgeRow" style="flex-wrap:wrap;"></div>
                    <div class="u-text-xs u-text-soft u-mb-2">Recommended: <strong id="previewRecommended">—</strong> per passenger</div>
                    <div class="u-text-xs u-text-soft u-mb-2">Potential Revenue: <strong id="previewPotentialRevenue">—</strong> · Max Commission: <strong id="previewMaxCommission">—</strong></div>

                    <div id="priceFieldWrap">
                        <label class="field-label" for="createRidePrice" id="priceFieldLabel">Price per seat (₦<span id="previewMin">—</span> – ₦<span id="previewMax">—</span>)</label>
                        <div class="field"><i class="bi bi-cash"></i><input type="number" id="createRidePrice" name="price_per_passenger" step="0.01"></div>
                    </div>
                    <div id="systemModeLockedNote" class="alert-banner u-hidden" style="background:var(--color-surface);color:var(--color-text-soft);margin-top:var(--space-2);margin-bottom:0;">
                        <i class="bi bi-lock"></i> Fare is automatically calculated by MohToh.
                    </div>
                    <div id="priceRangeError" class="u-text-xs u-mt-2" style="color:var(--color-danger);"></div>

                    <div id="walletReserveNote" class="u-text-xs u-mt-2"></div>
                    <button type="button" class="btn-text u-text-xs u-mt-2" id="howPriceCalculatedBtn">How was this price calculated? <i class="bi bi-chevron-right"></i></button>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label">Payment Collection</label>
                <div class="choice-list" id="rideCollectionList">
                    <label class="choice-option" data-value="wallet">
                        <input type="radio" name="ride_payment_preference" value="wallet">
                        <div class="choice-option__body">
                            <div class="choice-option__title">Wallet</div>
                            <div class="choice-option__desc">Passengers pay through their MohToh wallet.</div>
                        </div>
                    </label>
                    <label class="choice-option" data-value="direct">
                        <input type="radio" name="ride_payment_preference" value="direct">
                        <div class="choice-option__body">
                            <div class="choice-option__title">Direct</div>
                            <div class="choice-option__desc">Passengers pay you directly; you confirm after the trip.</div>
                        </div>
                    </label>
                    <label class="choice-option" data-value="both">
                        <input type="radio" name="ride_payment_preference" value="both">
                        <div class="choice-option__body">
                            <div class="choice-option__title">Both</div>
                            <div class="choice-option__desc">Passengers choose wallet or direct when booking.</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="field-group">
                <label class="field-label" for="createRideNotes">Notes (optional)</label>
                <div class="field" style="align-items:flex-start;padding-top:12px;"><textarea id="createRideNotes" name="description" class="field" style="border:none;padding:0;height:64px;" placeholder="Anything passengers should know"></textarea></div>
            </div>
            <button type="submit" class="btn btn-primary" id="publishRideBtn">Publish ride</button>
        </form>
    </div>

    <!-- ================= Price breakdown sheet ================= -->
    <div class="sheet" id="priceBreakdownSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">How this price is calculated</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="priceBreakdownBody"></div>
    </div>

    <!-- ================= Vehicle verification sheet ================= -->
    <div class="sheet" id="vehicleVerificationSheet" role="dialog" aria-modal="true">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title">Vehicle Verification</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="vehicleVerificationBody"></div>
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
<script src="../assets/js/map.js"></script>
<script src="../assets/js/messages.js"></script>
<script src="../assets/js/referrals.js"></script>
<script src="../assets/js/driver.js"></script>
<script src="../assets/js/driver-ride-creation.js"></script>
</body>
</html>
