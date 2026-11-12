<?php
session_start();
require_once '../includes/functions.php';
require_once '../includes/db.php';                 // $pdo for the admin context/gate below
require_once '../includes/admin_permissions.php';  // get_admin_context(), current_admin_can()
require_login();

// Route a wrong-role session to its own role's home — sending a still
// logged-in rider/driver to index.php would only bounce them back to
// their dashboard, so redirect straight there.
if ($_SESSION['user_type'] !== 'admin') {
    $roleHome = ['rider' => '../rider/dashboard.php', 'driver' => '../driver/dashboard.php'][$_SESSION['user_type']] ?? '../index.php';
    header('Location: ' . $roleHome);
    exit;
}

// Server-side admin gate — the page equivalent of require_admin_access().
// The session is already in admin mode (checked above), but re-read the DB so
// a mid-session suspension or de-admin ejects on the very next refresh instead
// of rendering the console shell. A page redirect (not the API's JSON denial)
// so the person lands somewhere usable rather than staring at raw JSON.
$adminCtx = get_admin_context($pdo);
if (!$adminCtx['is_admin'] || $adminCtx['admin_status'] !== 'active' || $adminCtx['is_suspended']) {
    header('Location: ../index.php');
    exit;
}

// Display identity + capability, used by the topbar and (cosmetic) tab
// visibility. current_admin_can() with $adminCtx passed does NOT re-hit the
// DB, so wrapping ~20 nav links below is free.
$adminName    = $_SESSION['fullname'] ?? 'Administrator';
$adminIsSuper = !empty($adminCtx['is_super_admin']);
$adminCan = function (string $perm) use ($pdo, $adminCtx): bool {
    return current_admin_can($pdo, $perm, $adminCtx);
};

// Multi-role topbar switcher: does this admin ALSO hold a rider/driver hat? A
// pure-admin account of record (user_type='admin') does not; a promoted
// rider/driver does. switch-mode.php re-checks server-side, so this only
// decides which topbar buttons to render.
$adminOtherRole = 'admin';
try {
    $__roleStmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
    $__roleStmt->execute([(int) $_SESSION['user_id']]);
    $adminOtherRole = (string) $__roleStmt->fetchColumn();
} catch (Exception $e) { /* leave as 'admin' — no switcher */ }
$adminHasOtherRole = in_array($adminOtherRole, ['rider', 'driver'], true);

// Whether the "Operations" section has at least one visible tab (so its
// header doesn't render above an empty gap for a narrowly-scoped Subadmin).
$showOpsSection = $adminIsSuper || $adminCan('pricing.view') || $adminCan('promotions.view')
    || $adminCan('reports.view') || $adminCan('announcements.send') || $adminCan('rides.view')
    || $adminCan('premium.view') || $adminCan('integrations.view');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- App CSRF token for authenticated state-changing fetches (e.g. MOHTOH NETWORK admin actions). Not a secret; scoped to this session. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>Admin Dashboard - Merit Drives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/image-viewer.css">
    <style>
        :root { --primary:#2563eb; --secondary:#7c3aed; --success:#10b981; --warning:#f59e0b; --danger:#ef4444; --dark:#0f172a; --light:#f8fafc; --border:#e2e8f0; }
        /* Part 1: independent-scroll admin layout. Sidebar is fixed to
           the viewport with its own scroll region; .main-area is offset
           by the sidebar's width and scrolls independently. The old
           `position: sticky` sidebar shared one page-level scroll with
           the content — a sidebar taller than the viewport (many nav
           items) or a long content page both broke it. */
        html, body { height: 100%; overflow: hidden; }
        body { background: var(--light); color: var(--dark); font-family: 'Segoe UI', sans-serif; }
        .admin-shell { height: 100vh; }
        .sidebar {
            width: 260px;
            background: linear-gradient(135deg, var(--dark), #1e293b);
            position: fixed;
            top: 0; left: 0; bottom: 0;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1030;
            transition: width .2s ease, transform .2s ease;
        }
        .sidebar .nav-link { color: rgba(255,255,255,0.8); border-radius: 10px; margin: 2px 0; white-space: nowrap; overflow: hidden; }
        .sidebar .nav-link.active, .sidebar .nav-link:hover { background: rgba(255,255,255,0.12); color: #fff; }
        /* Collapsed state: icon-only rail. Toggled by adding .is-collapsed
           to .sidebar (see #sidebarCollapseBtn below). Nav-link labels
           aren't in their own span, so this clips the text via a fixed
           narrow width + hidden overflow rather than hiding a label
           element — no markup changes needed on the ~15 existing links. */
        .sidebar.is-collapsed { width: 72px; }
        .sidebar.is-collapsed .sidebar-brand-text { display: none; }
        .sidebar.is-collapsed .nav-link { width: 40px; padding-left: 12px; padding-right: 0; }
        .main-area {
            margin-left: 260px;
            height: 100vh;
            overflow-y: auto;
            transition: margin-left .2s ease;
        }
        body.sidebar-collapsed .main-area { margin-left: 72px; }
        .topbar { background: white; border-bottom: 1px solid var(--border); position: sticky; top: 0; z-index: 10; }
        /* Mobile/tablet: sidebar becomes an off-canvas drawer, hidden by
           default, slid in via .is-open (see #sidebarMobileToggle). */
        @media (max-width: 991.98px) {
            .sidebar { transform: translateX(-100%); width: 260px; }
            .sidebar.is-open { transform: translateX(0); }
            .sidebar.is-collapsed { width: 260px; } /* collapse is a desktop-only concept */
            .sidebar.is-collapsed .sidebar-brand-text { display: block; }
            .sidebar.is-collapsed .nav-link { width: auto; padding-left: 16px; padding-right: 16px; }
            .main-area, body.sidebar-collapsed .main-area { margin-left: 0; }
            .sidebar-scrim { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 1020; }
            .sidebar-scrim.is-open { display: block; }
            .sidebar-toggle-desktop { display: none !important; }
        }
        @media (min-width: 992px) {
            .sidebar-toggle-mobile { display: none !important; }
        }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .stat-card { border: 0; border-radius: 16px; box-shadow: 0 8px 18px rgba(15,23,42,0.06); }
        .map-card { border: 0; border-radius: 16px; overflow: hidden; }
        #admin-map { height: 420px; border-radius: 16px; }
        .table thead th { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; color: #64748b; }
        .form-card { max-width: 760px; }
    </style>
</head>
<body>
<div class="sidebar-scrim" id="sidebarScrimEl"></div>
<div class="d-flex admin-shell">
    <aside class="sidebar p-3" id="adminSidebar">
        <div class="text-white mb-4 sidebar-brand-text">
            <h4 class="fw-bold mb-0"><i class="fas fa-car-side me-2"></i>Merit Drives</h4>
            <small class="text-white-50">Mohtoh Admin</small>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link active" href="#" data-tab="dashboard"><i class="fas fa-chart-line me-2"></i>Dashboard</a>
            <?php if ($adminCan('drivers.view')): ?><a class="nav-link" href="#" data-tab="drivers"><i class="fas fa-user-tie me-2"></i>Drivers</a><?php endif; ?>
            <?php if ($adminCan('vehicles.view')): ?><a class="nav-link" href="#" data-tab="vehicles"><i class="fas fa-car me-2"></i>Vehicles</a><?php endif; ?>
            <?php if ($adminCan('locations.manage')): ?><a class="nav-link" href="#" data-tab="locations"><i class="fas fa-map-pin me-2"></i>Locations</a><?php endif; ?>
            <?php if ($adminCan('users.view')): ?><a class="nav-link" href="#" data-tab="passengers"><i class="fas fa-user me-2"></i>Passengers</a><?php endif; ?>
            <?php if ($adminCan('rides.view')): ?><a class="nav-link" href="#" data-tab="ride-sessions"><i class="fas fa-calendar-check me-2"></i>Ride Sessions</a><?php endif; ?>
            <?php if ($adminCan('rides.view')): ?><a class="nav-link" href="#" data-tab="bookings"><i class="fas fa-ticket-alt me-2"></i>Bookings</a><?php endif; ?>
            <a class="nav-link" href="#" data-tab="live-map"><i class="fas fa-map-marked-alt me-2"></i>Live Map</a>
            <?php if ($adminCan('announcements.send')): ?><a class="nav-link" href="#" data-tab="notifications"><i class="fas fa-bullhorn me-2"></i>Announcements</a><?php endif; ?>

            <?php if ($showOpsSection): ?><div class="text-white-50 text-uppercase small fw-bold px-2 pt-4 pb-1" style="letter-spacing:0.05em;">Operations</div><?php endif; ?>
            <?php if ($adminCan('pricing.view')): ?><a class="nav-link" href="#" data-tab="pricing"><i class="fas fa-tags me-2"></i>Pricing Management</a><?php endif; ?>
            <?php if ($adminCan('pricing.view')): ?><a class="nav-link" href="#" data-tab="driver-settings"><i class="fas fa-sliders-h me-2"></i>Driver Settings</a><?php endif; ?>
            <?php if ($adminCan('pricing.view')): ?><a class="nav-link" href="#" data-tab="regional-pricing"><i class="fas fa-map-marked me-2"></i>Regional Pricing</a><?php endif; ?>
            <?php if ($adminCan('promotions.view')): ?><a class="nav-link" href="#" data-tab="promotions"><i class="fas fa-percent me-2"></i>Promotions</a><?php endif; ?>
            <?php if ($adminCan('reports.view')): ?><a class="nav-link" href="#" data-tab="commission"><i class="fas fa-coins me-2"></i>Commission</a><?php endif; ?>
            <?php if ($adminCan('announcements.send')): ?><a class="nav-link" href="#" data-tab="driver-comms"><i class="fas fa-comment-dots me-2"></i>Driver Communication</a><?php endif; ?>
            <?php if ($adminCan('rides.view')): ?><a class="nav-link" href="#" data-tab="waitlist"><i class="fas fa-users-line me-2"></i>Ride Queue Management</a><?php endif; ?>
            <?php if ($adminCan('rides.view')): ?><a class="nav-link" href="#" data-tab="ride-requests"><i class="fas fa-hand-point-right me-2"></i>Ride Request Management</a><?php endif; ?>
            <?php if ($adminCan('premium.view')): ?><a class="nav-link" href="#" data-tab="premium"><i class="fas fa-crown me-2"></i>Premium Features</a><?php endif; ?>
            <?php if ($adminCan('reports.view')): ?><a class="nav-link" href="#" data-tab="reports"><i class="fas fa-chart-bar me-2"></i>Reports</a><?php endif; ?>
            <?php if ($adminCan('integrations.view')): ?><a class="nav-link" href="#" data-tab="integrations"><i class="fas fa-plug me-2"></i>Service Integration Center</a><?php endif; ?>
            <?php if ($adminCan('integrations.view')): ?><a class="nav-link" href="#" data-tab="diagnostics"><i class="fas fa-heart-pulse me-2"></i>Diagnostics</a><?php endif; ?>
            <?php if ($adminCan('chat.moderate')): ?><a class="nav-link" href="#" data-tab="chat-monitoring"><i class="fas fa-comments me-2"></i>Chat Monitoring</a><?php endif; ?>
            <?php if ($adminCan('reports.view')): ?><a class="nav-link" href="#" data-tab="community-growth"><i class="fas fa-people-group me-2"></i>Community Growth</a><?php endif; ?>
            <?php if ($adminCan('network.view')): ?><a class="nav-link" href="#" data-tab="mohtoh-network"><i class="fas fa-share-nodes me-2"></i>MOHTOH NETWORK</a><?php endif; ?>

            <?php if ($adminIsSuper): ?>
            <div class="text-white-50 text-uppercase small fw-bold px-2 pt-4 pb-1" style="letter-spacing:0.05em;">Administration</div>
            <a class="nav-link" href="#" data-tab="administrators"><i class="fas fa-user-shield me-2"></i>Administrators</a>
            <?php endif; ?>
        </nav>
        <div class="mt-5 border-top border-secondary pt-3">
            <button class="btn btn-outline-light btn-sm w-100" onclick="logout()"><i class="fas fa-sign-out-alt me-2"></i>Logout</button>
        </div>
    </aside>

    <main class="main-area flex-grow-1">
        <header class="topbar px-4 py-3 d-flex justify-content-between align-items-center">
            <div class="d-flex align-items-center gap-3">
                <button type="button" class="btn btn-outline-secondary btn-sm sidebar-toggle-mobile" id="sidebarMobileToggle" aria-label="Open menu"><i class="fas fa-bars"></i></button>
                <button type="button" class="btn btn-outline-secondary btn-sm sidebar-toggle-desktop" id="sidebarCollapseBtn" aria-label="Collapse sidebar"><i class="fas fa-angles-left"></i></button>
                <div>
                    <h5 class="fw-bold mb-0">Mohtoh Admin Console</h5>
                    <small class="text-muted">Monitor riders, drivers, rides and pricing</small>
                </div>
            </div>
            <div class="d-flex align-items-center gap-3">
                <?php if ($adminHasOtherRole): ?>
                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-arrow-right-arrow-left me-1"></i>Switch mode</button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><button class="dropdown-item" type="button" onclick="adminSwitchMode('rider')"><i class="fas fa-user me-2"></i>Rider mode</button></li>
                        <?php if ($adminOtherRole === 'driver'): ?>
                        <li><button class="dropdown-item" type="button" onclick="adminSwitchMode('driver')"><i class="fas fa-car me-2"></i>Driver mode</button></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php endif; ?>
                <span class="badge bg-success-subtle text-success"><i class="fas fa-circle me-1"></i>Online</span>
                <div class="text-end">
                    <div class="fw-semibold"><?= htmlspecialchars($adminName) ?></div>
                    <small class="text-muted"><?= $adminIsSuper ? 'Super Admin' : 'Subadmin' ?></small>
                </div>
            </div>
        </header>

        <div class="p-4">
            <section id="tab-dashboard" class="tab-pane active">
                <div class="row g-3 mb-4">
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Users</div><h3 class="fw-bold mb-0" id="stat-users">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Drivers</div><h3 class="fw-bold mb-0" id="stat-drivers">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Passengers</div><h3 class="fw-bold mb-0" id="stat-passengers">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Active Rides</div><h3 class="fw-bold mb-0" id="stat-active-rides">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Mohtoh Rides</div><h3 class="fw-bold mb-0" id="stat-mohtoh-rides">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Pending Vehicles</div><h3 class="fw-bold mb-0" id="stat-pending-vehicles">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Pending Locations</div><h3 class="fw-bold mb-0" id="stat-pending-locations">0</h3></div></div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-chart-pie me-2"></i>Mohtoh Reports</h5>
                        <div class="row g-3">
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Active Mohtoh Rides</div><h4 class="fw-bold mb-0" id="report-active-mohtoh">0</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Completed Mohtoh Rides</div><h4 class="fw-bold mb-0" id="report-completed-mohtoh">0</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Confirmed Bookings</div><h4 class="fw-bold mb-0" id="report-confirmed-bookings">0</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Cancelled Bookings</div><h4 class="fw-bold mb-0" id="report-cancelled-bookings">0</h4></div></div>
                        </div>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-users me-2"></i>Recent Registrations</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead><tr><th>Name</th><th>Type</th><th>Joined</th></tr></thead>
                                        <tbody id="recent-users-body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="card shadow-sm border-0">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-route me-2"></i>Recent Rides</h5>
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead><tr><th>ID</th><th>Destination</th><th>Status</th></tr></thead>
                                        <tbody id="recent-rides-body"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-drivers" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-user-tie me-2"></i>Driver Management</h5>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>User ID</th><th>Name</th><th>Email</th><th>Rating</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="drivers-body"></tbody>
                            </table>
                        </div>
                        <div id="drivers-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-vehicles" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-car me-2"></i>Vehicle Review Queue</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Driver</th><th>Vehicle</th><th>Type</th><th>Status</th><th>Photos</th><th>Documents</th><th>Actions</th></tr></thead>
                                <tbody id="vehicles-body"></tbody>
                            </table>
                        </div>
                        <div id="vehicles-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-locations" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-map-pin me-2"></i>MohToh Locations</h5>
                            <small class="text-muted">Driver- and rider-suggested pickup points. Approved ones become searchable for everyone.</small>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Name</th><th>Type</th><th>Address</th><th>Suggested by</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="locations-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-passengers" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-user me-2"></i>Passenger Management</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>User ID</th><th>Name</th><th>Email</th><th>Rating</th><th>Verification</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="passengers-body"></tbody>
                            </table>
                        </div>
                        <div id="passengers-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-ride-sessions" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-calendar-check me-2"></i>Mohtoh Ride Sessions</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Destination</th><th>Driver</th><th>Date</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="rides-body"></tbody>
                            </table>
                        </div>
                        <div id="rides-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-bookings" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-ticket-alt me-2"></i>Ride Bookings</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Ride</th><th>Rider</th><th>Seats</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="bookings-body"></tbody>
                            </table>
                        </div>
                        <div id="bookings-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-live-map" class="tab-pane">
                <div class="card map-card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-map-marked-alt me-2"></i>Live Tracking</h5>
                        <div id="admin-map"></div>
                    </div>
                </div>
            </section>

            <section id="tab-pricing" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-tags me-2"></i>Pricing Management</h5>
                        <p class="text-muted small mb-3">Every field here is read live by the fare engine on the next ride priced. Only Super Admins can save changes here.</p>
                        <div class="alert d-none" id="pricing-center-alert"></div>

                        <h6 class="fw-bold mb-2">Pricing Mode</h6>
                        <p class="text-muted small mb-2">Controls how a driver's per-seat price relates to MohToh's recommendation, app-wide.</p>
                        <div class="d-flex flex-column gap-2 mb-4" id="pc-pricing-mode-group">
                            <label class="border rounded p-2 d-flex gap-2 align-items-start"><input type="radio" name="pc-pricing_mode" value="system" class="mt-1"><span><strong>System</strong><br><span class="text-muted small">MohToh sets the price. The driver cannot change it.</span></span></label>
                            <label class="border rounded p-2 d-flex gap-2 align-items-start"><input type="radio" name="pc-pricing_mode" value="driver" class="mt-1"><span><strong>Driver</strong><br><span class="text-muted small">The driver sets any price within your Minimum/Maximum Fare below.</span></span></label>
                            <label class="border rounded p-2 d-flex gap-2 align-items-start"><input type="radio" name="pc-pricing_mode" value="hybrid" class="mt-1"><span><strong>Hybrid</strong><br><span class="text-muted small">MohToh recommends a price; the driver may adjust it within the Hybrid Band % below.</span></span></label>
                        </div>

                        <form id="pricing-center-form">
                            <div class="row g-3">
                                <div class="col-md-4"><label class="form-label">Base Fare (per passenger)</label><input type="number" step="0.01" class="form-control" id="pc-base_fare"><div class="form-text">Flat amount added to every passenger's seat fare before distance is calculated. Not divided by seats — each passenger is charged this in full.</div></div>
                                <div class="col-md-4"><label class="form-label">Passenger Fare Per KM</label><input type="number" step="0.01" class="form-control" id="pc-price_per_km"><div class="form-text">Amount charged per passenger for each kilometre travelled.</div></div>
                                <div class="col-md-4"><label class="form-label">Price Per Minute (optional)</label><input type="number" step="0.01" class="form-control" id="pc-price_per_minute"><div class="form-text">Optional extra charge per minute of estimated trip duration. Leave 0 to ignore.</div></div>
                                <div class="col-md-4"><label class="form-label">Minimum Passenger Fare</label><input type="number" step="0.01" class="form-control" id="pc-minimum_fare"><div class="form-text">No passenger seat is ever priced below this amount, however short the trip.</div></div>
                                <div class="col-md-4"><label class="form-label">Maximum Passenger Fare (optional, 0 = uncapped)</label><input type="number" step="0.01" class="form-control" id="pc-maximum_fare"><div class="form-text">Caps what a single passenger seat can cost, regardless of distance. 0 means no cap.</div></div>
                                <div class="col-md-4"><label class="form-label">Booking Fee</label><input type="number" step="0.01" class="form-control" id="pc-booking_fee"><div class="form-text">Flat fee added once per passenger seat, on top of the fare.</div></div>
                                <div class="col-md-4"><label class="form-label">Platform Service Fee</label><input type="number" step="0.01" class="form-control" id="pc-platform_service_fee"><div class="form-text">MohToh's flat platform fee, separate from the driver commission %.</div></div>
                                <div class="col-md-4"><label class="form-label">MohToh Commission Percentage</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="pc-commission_percentage"><div class="form-text">Percentage of each passenger's seat fare MohToh keeps; the rest is the driver's earning.</div></div>
                                <div class="col-md-4"><label class="form-label">Service Charge</label><input type="number" step="0.01" class="form-control" id="pc-rider_service_charge"><div class="form-text">Extra amount added to what each passenger pays, on top of the fare.</div></div>
                                <div class="col-md-4"><label class="form-label">Cancellation Fee</label><input type="number" step="0.01" class="form-control" id="pc-cancellation_fee"><div class="form-text">Charged when a rider cancels after a driver has already committed.</div></div>
                                <div class="col-md-4"><label class="form-label">Waiting Charge</label><input type="number" step="0.01" class="form-control" id="pc-waiting_charge"><div class="form-text">Charged when a driver waits beyond the grace period at pickup.</div></div>
                                <div class="col-md-4"><label class="form-label">Hybrid Band Percentage</label><input type="number" step="0.01" class="form-control" id="pc-price_band_percent"><div class="form-text">In Hybrid mode, how far above/below the recommendation a driver may set their price.</div></div>
                                <div class="col-md-4"><label class="form-label">Night Fare Multiplier</label><input type="number" step="0.01" class="form-control" id="pc-night_fare_multiplier"><div class="form-text">Multiplies the fare for trips departing inside the Night Window. 1.0 = no change.</div></div>
                                <div class="col-md-4"><label class="form-label">Night Window (hour, 0-23)</label><div class="input-group"><input type="number" min="0" max="23" class="form-control" id="pc-night_start_hour" placeholder="Start"><input type="number" min="0" max="23" class="form-control" id="pc-night_end_hour" placeholder="End"></div><div class="form-text">Hours (24h) during which the Night Fare Multiplier applies. Can wrap past midnight.</div></div>
                                <div class="col-md-4"><label class="form-label">Peak Hour Multiplier</label><input type="number" step="0.01" class="form-control" id="pc-peak_hour_multiplier"><div class="form-text">Multiplies the fare for trips departing inside the Peak Window. 1.0 = no change.</div></div>
                                <div class="col-md-4"><label class="form-label">Peak Window (hour, 0-23)</label><div class="input-group"><input type="number" min="0" max="23" class="form-control" id="pc-peak_start_hour" placeholder="Start"><input type="number" min="0" max="23" class="form-control" id="pc-peak_end_hour" placeholder="End"></div><div class="form-text">Hours (24h) during which the Peak Hour Multiplier applies.</div></div>
                                <div class="col-md-4"><label class="form-label">Holiday Multiplier</label><input type="number" step="0.01" class="form-control" id="pc-holiday_multiplier"><div class="form-text">Multiplies the fare for trips departing on one of the Holiday Dates below.</div></div>
                                <div class="col-md-8"><label class="form-label">Holiday Dates (comma-separated, YYYY-MM-DD)</label><input type="text" class="form-control" id="pc-holiday_dates" placeholder="2026-12-25, 2026-01-01"><div class="form-text">Dates the Holiday Multiplier applies on.</div></div>
                            </div>
                            <hr class="my-4">

                            <h6 class="fw-bold mb-1"><i class="fas fa-user-shield me-2"></i>Private Pickup / Solo Ride Pricing</h6>
                            <p class="text-muted small mb-3">Separate from the Join Ride fields above. A Private Pickup is one whole vehicle to a single rider, priced as <strong>Base Fare + (KM &times; Price Per KM) + Booking Fee</strong>, then clamped to the Minimum/Maximum below. <strong>Keke and Car are priced independently</strong> &mdash; leave a field blank to inherit the shared fallback.</p>

                            <div class="row g-3 mb-3">
                                <div class="col-md-4"><label class="form-label">Shared Base Fare (fallback)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_base_fare"><div class="form-text">Used for any pickup vehicle class with no Base Fare of its own below.</div></div>
                                <div class="col-md-4"><label class="form-label">Shared Price Per KM (fallback)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_price_per_km"><div class="form-text">Used for any pickup vehicle class with no Price Per KM of its own below.</div></div>
                                <div class="col-md-4"><label class="form-label">Private Booking Fee</label><input type="number" step="0.01" class="form-control" id="pc-private_booking_fee"><div class="form-text">Flat fee added once to every Private Pickup, both vehicle classes.</div></div>
                            </div>

                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <div class="border rounded p-3 h-100">
                                        <h6 class="fw-bold mb-1">Keke Private Pickup</h6>
                                        <p class="text-muted small mb-3">Short, local street trips. These values apply when a <strong>Keke Napep</strong> accepts the pickup.</p>
                                        <div class="row g-2">
                                            <div class="col-md-6"><label class="form-label small">Base Fare</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_keke_base_fare" placeholder="Inherit"></div>
                                            <div class="col-md-6"><label class="form-label small">Price Per KM</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_keke_price_per_km" placeholder="Inherit"></div>
                                            <div class="col-md-6"><label class="form-label small">Minimum Fare (0 = none)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_keke_minimum_fare"></div>
                                            <div class="col-md-6"><label class="form-label small">Maximum Fare (0 = uncapped)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_keke_maximum_fare"></div>
                                            <div class="col-12"><label class="form-label small">Commission % (blank = use the Commission Percentage above)</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="pc-private_pickup_keke_commission_percentage" placeholder="Inherit"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="border rounded p-3 h-100">
                                        <h6 class="fw-bold mb-1">Car Private Pickup</h6>
                                        <p class="text-muted small mb-3">Longer-distance trips. These values apply when a <strong>Car</strong> accepts the pickup, and are independent of the Keke block.</p>
                                        <div class="row g-2">
                                            <div class="col-md-6"><label class="form-label small">Base Fare</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_car_base_fare" placeholder="Inherit"></div>
                                            <div class="col-md-6"><label class="form-label small">Price Per KM</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_car_price_per_km" placeholder="Inherit"></div>
                                            <div class="col-md-6"><label class="form-label small">Minimum Fare (0 = none)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_car_minimum_fare"></div>
                                            <div class="col-md-6"><label class="form-label small">Maximum Fare (0 = uncapped)</label><input type="number" step="0.01" class="form-control" id="pc-private_pickup_car_maximum_fare"></div>
                                            <div class="col-12"><label class="form-label small">Commission % (blank = use the Commission Percentage above)</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="pc-private_pickup_car_commission_percentage" placeholder="Inherit"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <button class="btn btn-primary" type="submit">Save Changes</button>
                                <button class="btn btn-outline-secondary" type="button" id="pc-reset-defaults">Reset To Default</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h6 class="fw-bold mb-1">Preview Recommended Price</h6>
                        <p class="text-muted small mb-3">See what a trip would cost under the settings above (using whatever is currently saved, not unsaved edits) before publishing them to drivers.</p>
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3"><label class="form-label small">Distance (km)</label><input type="number" step="0.1" class="form-control form-control-sm" id="pv-distance" value="10"></div>
                            <div class="col-md-3"><label class="form-label small">Seats</label><input type="number" min="1" class="form-control form-control-sm" id="pv-seats" value="4"></div>
                            <div class="col-md-3"><label class="form-label small">Departure time (optional)</label><input type="time" class="form-control form-control-sm" id="pv-time"></div>
                            <div class="col-md-3"><button class="btn btn-outline-primary btn-sm w-100" type="button" id="pc-preview-btn">Preview</button></div>
                        </div>
                        <div class="mt-3 d-none" id="pv-result">
                            <div class="row g-2 text-center">
                                <div class="col-4"><div class="border rounded p-2"><div class="text-muted small">Recommended</div><div class="fw-bold" id="pv-recommended">—</div></div></div>
                                <div class="col-4"><div class="border rounded p-2"><div class="text-muted small">Min</div><div class="fw-bold" id="pv-min">—</div></div></div>
                                <div class="col-4"><div class="border rounded p-2"><div class="text-muted small">Max</div><div class="fw-bold" id="pv-max">—</div></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Vehicle Star-Rating Price Per KM</h6>
                        <div class="row g-2" id="pc-star-multipliers"></div>
                        <button class="btn btn-outline-primary btn-sm mt-3" type="button" onclick="saveStarMultipliers()">Save Star Multipliers</button>
                    </div>
                </div>
            </section>

            <section id="tab-driver-settings" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-sliders-h me-2"></i>Driver Settings</h5>
                        <div class="alert d-none" id="driver-settings-alert"></div>
                        <form id="driver-settings-form">
                            <div class="mb-3"><label class="form-label">Minimum wallet balance before accepting rides</label><input type="number" step="0.01" class="form-control" id="ds-min_wallet_balance"></div>
                            <div class="mb-3"><label class="form-label">Maximum active rides per driver (0 = unlimited)</label><input type="number" class="form-control" id="ds-max_active_rides_per_driver"></div>
                            <div class="mb-3 form-check"><input type="checkbox" class="form-check-input" id="ds-require_vehicle_documents"><label class="form-check-label" for="ds-require_vehicle_documents">Require vehicle verification documents</label></div>
                            <div class="mb-3"><label class="form-label">Ride pricing mode</label><select class="form-select" id="ds-pricing_mode"><option value="system">System controls pricing</option><option value="driver">Driver sets price within admin limits</option><option value="hybrid">Hybrid — system recommends, driver adjusts within a band</option></select></div>
                            <button class="btn btn-primary" type="submit">Save Driver Settings</button>
                        </form>
                    </div>
                </div>
                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h6 class="fw-bold mb-3">Per-Driver Commission &amp; Bonuses</h6>
                        <div class="alert d-none" id="driver-bonus-alert"></div>
                        <div class="mb-3"><label class="form-label">Driver</label><select class="form-select" id="db-driver-select"></select></div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-6"><label class="form-label small">Custom commission (%) — blank clears override</label><input type="number" step="0.01" min="0" max="100" class="form-control" id="db-custom-commission"></div>
                            <div class="col-md-6 d-flex align-items-end"><button class="btn btn-outline-primary w-100" type="button" onclick="saveCustomCommission()">Save Commission</button></div>
                        </div>
                        <div class="row g-2">
                            <div class="col-md-3"><input type="number" step="0.01" class="form-control" id="db-bonus-amount" placeholder="Amount"></div>
                            <div class="col-md-3"><select class="form-select" id="db-bonus-type"><option value="bonus">Bonus</option><option value="referral_reward">Referral reward</option></select></div>
                            <div class="col-md-4"><input type="text" class="form-control" id="db-bonus-reason" placeholder="Reason"></div>
                            <div class="col-md-2"><button class="btn btn-success w-100" type="button" onclick="grantBonus()">Grant</button></div>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm"><thead><tr><th>Driver</th><th>Type</th><th>Amount</th><th>Reason</th><th>Date</th></tr></thead><tbody id="bonus-history-body"></tbody></table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-regional-pricing" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-map-marked me-2"></i>Regional Pricing</h5>
                        <div class="alert d-none" id="region-alert"></div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="text" class="form-control" id="rg-name" placeholder="Region name (e.g. Lagos Island)"></div>
                            <div class="col-md-2"><input type="number" step="0.00000001" class="form-control" id="rg-center_lat" placeholder="Center lat"></div>
                            <div class="col-md-2"><input type="number" step="0.00000001" class="form-control" id="rg-center_lng" placeholder="Center lng"></div>
                            <div class="col-md-1"><input type="number" step="0.1" class="form-control" id="rg-radius_km" placeholder="Radius km"></div>
                            <div class="col-md-2"><input type="number" step="0.01" class="form-control" id="rg-price_per_km" placeholder="Price/km"></div>
                            <div class="col-md-2"><button class="btn btn-primary w-100" type="button" onclick="createRegion()">Add Region</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Name</th><th>Radius (km)</th><th>Price/KM</th><th>Base Fare</th><th>Commission %</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="regions-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-promotions" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-percent me-2"></i>Promotions</h5>
                        <div class="alert d-none" id="promo-alert"></div>
                        <div class="row g-2 mb-3">
                            <div class="col-md-2"><input type="text" class="form-control text-uppercase" id="pr-code" placeholder="CODE"></div>
                            <div class="col-md-2"><select class="form-select" id="pr-type"><option value="percentage">Percentage</option><option value="fixed">Fixed</option></select></div>
                            <div class="col-md-2"><input type="number" step="0.01" class="form-control" id="pr-value" placeholder="Value"></div>
                            <div class="col-md-2"><select class="form-select" id="pr-applies_to"><option value="all">All rides</option><option value="first_ride">First ride only</option></select></div>
                            <div class="col-md-2"><input type="number" class="form-control" id="pr-per_user_limit" placeholder="Per-user limit" value="1"></div>
                            <div class="col-md-2"><button class="btn btn-primary w-100" type="button" onclick="createPromo()">Create</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Applies to</th><th>Used</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="promos-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-commission" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-coins me-2"></i>Commission</h5>
                        <p class="text-muted small mb-3">Global commission and per-driver overrides are set from Pricing Management / Driver Settings. Per-region commission is set from Regional Pricing. This table is every completed trip's recorded fare/commission/earnings split.</p>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Driver</th><th>Completed Trips</th><th>Total Earnings</th><th>Total Commission Generated</th></tr></thead>
                                <tbody id="commission-body"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-driver-comms" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-comment-dots me-2"></i>Message a Driver</h5>
                        <div class="alert d-none" id="comms-alert"></div>
                        <div class="mb-3"><label class="form-label">Driver</label><select class="form-select" id="dc-driver-select"></select></div>
                        <div class="mb-3"><label class="form-label">Type</label><select class="form-select" id="dc-type"><option value="announcement">Announcement</option><option value="maintenance">Maintenance notice</option><option value="promo">Promotional</option><option value="warning">Warning</option></select></div>
                        <div class="mb-3"><label class="form-label">Message</label><textarea class="form-control" id="dc-message" rows="3"></textarea></div>
                        <button class="btn btn-primary" type="button" onclick="sendTargetedMessage()">Send</button>
                        <p class="text-muted small mt-3 mb-0">Broadcast announcements to all drivers/passengers are still sent from the Announcements tab. Suspending or restoring a driver is done from the Drivers tab.</p>
                    </div>
                </div>
            </section>

            <section id="tab-reports" class="tab-pane">
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-chart-bar me-2"></i>Reports</h5>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Total Revenue</div><h4 class="fw-bold mb-0" id="rep-total-revenue">—</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Total Commission</div><h4 class="fw-bold mb-0" id="rep-total-commission">—</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Total Driver Earnings</div><h4 class="fw-bold mb-0" id="rep-total-driver-earnings">—</h4></div></div>
                            <div class="col-md-3"><div class="border rounded p-3"><div class="text-muted small">Total Trips</div><h4 class="fw-bold mb-0" id="rep-total-trips">—</h4></div></div>
                        </div>
                        <ul class="nav nav-tabs mb-3" id="report-subtabs">
                            <li class="nav-item"><a class="nav-link active" href="#" data-report="daily">Daily Revenue</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="weekly">Weekly Revenue</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="monthly">Monthly Revenue</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="most_active_drivers">Most Active Drivers</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="most_active_routes">Most Active Routes</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="peak_hours">Peak Ride Hours</a></li>
                            <li class="nav-item"><a class="nav-link" href="#" data-report="ride_statistics">Ride Statistics</a></li>
                        </ul>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0" id="report-table"><thead id="report-table-head"></thead><tbody id="report-table-body"></tbody></table></div>
                    </div>
                </div>
            </section>

            <section id="tab-notifications" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-bullhorn me-2"></i>Broadcast Notification</h5>
                        <div class="alert d-none" id="announcement-alert"></div>
                        <form id="announcement-form">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Audience</label>
                                    <select class="form-select" id="announcement-target">
                                        <option value="all_drivers">All Drivers</option>
                                        <option value="all_riders">All Riders</option>
                                        <option value="everyone">Everyone</option>
                                        <option value="single">Single User</option>
                                        <option value="multiple">Multiple Users</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Priority</label>
                                    <select class="form-select" id="announcement-priority">
                                        <option value="normal">Normal</option>
                                        <option value="low">Low</option>
                                        <option value="high">High</option>
                                        <option value="critical">Critical</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Schedule (optional)</label>
                                    <input type="datetime-local" class="form-control" id="announcement-schedule">
                                </div>
                                <div class="col-12 d-none" id="announcement-user-picker-wrap">
                                    <label class="form-label">Recipient(s)</label>
                                    <input type="text" class="form-control" id="announcement-user-search" placeholder="Search by name or email…" autocomplete="off">
                                    <div class="list-group mt-1" id="announcement-user-results" style="max-height:180px;overflow-y:auto;"></div>
                                    <div class="d-flex flex-wrap gap-2 mt-2" id="announcement-user-chips"></div>
                                </div>
                                <div class="col-12"><label class="form-label">Title (optional)</label><input type="text" class="form-control" id="announcement-title" maxlength="150"></div>
                                <div class="col-12"><label class="form-label">Message</label><textarea class="form-control" id="announcement-message" rows="3" required></textarea></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Send</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-list-check me-2"></i>All Notifications</h5>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="nc-search" placeholder="Search message, title, user…"></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" id="nc-filter-type"><option value="">All types</option><option value="ride">Ride</option><option value="booking">Booking</option><option value="trip">Trip</option><option value="wallet">Wallet</option><option value="payment">Payment</option><option value="verification">Verification</option><option value="vehicle">Vehicle</option><option value="admin">Admin</option><option value="promotion">Promotion</option><option value="security">Security</option><option value="system">System</option><option value="support">Support</option><option value="premium">Premium</option><option value="emergency">Emergency</option><option value="gps">GPS</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" id="nc-filter-priority"><option value="">All priorities</option><option value="critical">Critical</option><option value="high">High</option><option value="normal">Normal</option><option value="low">Low</option></select></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" id="nc-filter-usertype"><option value="">Driver + Rider</option><option value="driver">Driver</option><option value="rider">Rider</option></select></div>
                            <div class="col-md-3"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="nc-filter-btn">Apply Filters</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>User</th><th>Title / Message</th><th>Type</th><th>Priority</th><th>Read</th><th>Sent</th><th></th></tr></thead>
                                <tbody id="nc-table-body"><tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div id="nc-pagination"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-clock-rotate-left me-2"></i>Broadcast History</h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Sent by</th><th>Audience</th><th>Message</th><th>Recipients</th><th>Status</th><th>Date</th></tr></thead>
                                <tbody id="nc-broadcast-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-sliders me-2"></i>Notification Settings</h5>
                        <p class="text-muted small mb-3">Category toggles apply platform-wide, on top of each user's own notification preferences — turning a category off here stops it for everyone regardless of their personal setting.</p>
                        <div class="alert d-none" id="nc-settings-alert"></div>
                        <form id="nc-settings-form">
                            <h6 class="fw-bold mb-2">Categories</h6>
                            <div class="row g-2 mb-3" id="nc-category-toggles"></div>
                            <h6 class="fw-bold mb-2">Delivery Channels</h6>
                            <div class="row g-2 mb-3" id="nc-channel-toggles"></div>
                            <h6 class="fw-bold mb-2">Limits</h6>
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">Retention Period (days)</label><input type="number" class="form-control" id="nc-notification_retention_days" min="1"></div>
                                <div class="col-md-3"><label class="form-label">Polling Interval (seconds)</label><input type="number" class="form-control" id="nc-notification_polling_interval_seconds" min="5"></div>
                                <div class="col-md-3"><label class="form-label">Max Notifications / User</label><input type="number" class="form-control" id="nc-notification_max_per_user" min="10"></div>
                                <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" id="nc-notification_auto_delete_old"><label class="form-check-label" for="nc-notification_auto_delete_old">Auto-delete old notifications</label></div></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Settings</button>
                        </form>
                    </div>
                </div>
            </section>

            <section id="tab-waitlist" class="tab-pane">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Conversion Rate</div>
                            <div class="fs-3 fw-bold" id="wl-conversion-rate">—</div>
                            <div class="text-muted small">of concluded ride-queue entries booked</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Total Entries</div>
                            <div class="fs-3 fw-bold" id="wl-total-entries">—</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Converted to Bookings</div>
                            <div class="fs-3 fw-bold" id="wl-converted-count">—</div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-signpost me-2"></i>Popular Routes (by ride-queue demand)</h5>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Route</th><th>Times Queued</th></tr></thead>
                                <tbody id="wl-routes-body"><tr><td colspan="2" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-list-ul me-2"></i>Active &amp; Historical Ride Queue Entries</h5>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="wl-filter-route" placeholder="Filter by route…"></div>
                            <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="wl-filter-date"></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" id="wl-filter-status"><option value="">All statuses</option><option value="WAITING">Waiting</option><option value="NOTIFIED">Notified</option><option value="RESERVED">Reserved</option><option value="CONVERTED">Converted</option><option value="EXPIRED">Expired</option><option value="CANCELLED">Cancelled</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="wl-filter-btn">Apply Filters</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Passenger</th><th>Route</th><th>Ride Date</th><th>Seats</th><th>Status</th><th>Joined</th></tr></thead>
                                <tbody id="wl-entries-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div id="wl-entries-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-ride-requests" class="tab-pane">
                <div class="row g-3 mb-3">
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Open</div>
                            <div class="fs-3 fw-bold" id="rr-open-count">—</div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Matched</div>
                            <div class="fs-3 fw-bold" id="rr-matched-count">—</div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Fulfilled</div>
                            <div class="fs-3 fw-bold" id="rr-fulfilled-count">—</div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Expired</div>
                            <div class="fs-3 fw-bold" id="rr-expired-count">—</div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Cancelled</div>
                            <div class="fs-3 fw-bold" id="rr-cancelled-count">—</div>
                        </div>
                    </div>
                    <div class="col-md-2 col-6">
                        <div class="card shadow-sm border-0 p-3">
                            <div class="text-muted small">Total</div>
                            <div class="fs-3 fw-bold" id="rr-total-count">—</div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 form-card h-100">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-signpost me-2"></i>Most Requested Routes</h5>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead><tr><th>Route</th><th>Requests</th></tr></thead>
                                        <tbody id="rr-popular-routes-body"><tr><td colspan="2" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card shadow-sm border-0 form-card h-100">
                            <div class="card-body">
                                <h5 class="fw-bold mb-3"><i class="fas fa-triangle-exclamation me-2"></i>Unserved Demand</h5>
                                <p class="text-muted small">Open requests, more than a day old, with no matching ride yet — routes no driver has served.</p>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle">
                                        <thead><tr><th>Route</th><th>Date</th><th>Requests</th></tr></thead>
                                        <tbody id="rr-unserved-body"><tr><td colspan="3" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-list-ul me-2"></i>All Ride Requests</h5>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="rr-filter-route" placeholder="Filter by route…"></div>
                            <div class="col-md-2"><input type="date" class="form-control form-control-sm" id="rr-filter-date"></div>
                            <div class="col-md-2"><select class="form-select form-select-sm" id="rr-filter-status"><option value="">All statuses</option><option value="OPEN">Open</option><option value="MATCHED">Matched</option><option value="FULFILLED">Fulfilled</option><option value="EXPIRED">Expired</option><option value="CANCELLED">Cancelled</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="rr-filter-btn">Apply Filters</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Passenger</th><th>Route</th><th>Travel Date</th><th>Passengers</th><th>Status</th><th>Created</th></tr></thead>
                                <tbody id="rr-entries-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div id="rr-entries-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-premium" class="tab-pane">
                <div class="card shadow-sm border-0 form-card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="fw-bold mb-0"><i class="fas fa-crown me-2"></i>Premium Feature Catalog</h5>
                            <button class="btn btn-primary btn-sm" type="button" id="pf-new-feature-btn">+ New Feature</button>
                        </div>
                        <div class="alert d-none" id="pf-feature-alert"></div>
                        <form id="pf-feature-form" class="d-none border rounded p-3 mb-3">
                            <input type="hidden" id="pf-feature-id">
                            <div class="row g-2">
                                <div class="col-md-6"><label class="form-label">Feature Name</label><input type="text" class="form-control" id="pf-feature-name" required></div>
                                <div class="col-md-3"><label class="form-label">Price</label><input type="number" step="0.01" class="form-control" id="pf-feature-price"></div>
                                <div class="col-md-3"><label class="form-label">Duration (days)</label><input type="number" class="form-control" id="pf-feature-duration" value="30"></div>
                                <div class="col-md-9"><label class="form-label">Description</label><input type="text" class="form-control" id="pf-feature-description"></div>
                                <div class="col-md-3"><label class="form-label">Status</label><select class="form-select" id="pf-feature-status"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
                            </div>
                            <button class="btn btn-primary btn-sm mt-3" type="submit">Save Feature</button>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Feature</th><th>Price</th><th>Duration</th><th>Status</th><th></th></tr></thead>
                                <tbody id="pf-features-body"><tr><td colspan="5" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-list-check me-2"></i>Premium Requests</h5>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><select class="form-select form-select-sm" id="pf-filter-status"><option value="">All statuses</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option><option value="disabled">Disabled</option></select></div>
                            <div class="col-md-3"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="pf-filter-btn">Apply Filter</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Driver</th><th>Feature</th><th>Status</th><th>Requested</th><th>Expires</th><th></th></tr></thead>
                                <tbody id="pf-requests-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div id="pf-requests-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-integrations" class="tab-pane">
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-envelope me-2"></i>Email Delivery</h5>
                        <p class="text-muted small mb-3">Verification emails and password reset links go through whichever provider is selected here, the moment you save — no code change, no deploy. <strong>Log Provider</strong> is the safe default: it writes the email to a local file instead of sending it, for development only.</p>
                        <div class="alert d-none" id="email-integration-alert"></div>
                        <form id="email-integration-form">
                            <div class="mb-3"><label class="form-label">Provider</label><select class="form-select" id="ei-mode"><option value="log">Log Provider (development only — no real email sent)</option><option value="smtp">SMTP (Gmail, Mailtrap, SendGrid, Mailgun, Amazon SES, Brevo — any SMTP relay)</option></select></div>
                            <div id="ei-smtp-fields">
                                <div class="row g-3">
                                    <div class="col-md-8"><label class="form-label">SMTP Host</label><input type="text" class="form-control" id="ei-host" placeholder="smtp.gmail.com"></div>
                                    <div class="col-md-4"><label class="form-label">Port</label><input type="number" class="form-control" id="ei-port" placeholder="587"></div>
                                    <div class="col-md-6"><label class="form-label">Encryption</label><select class="form-select" id="ei-encryption"><option value="tls">TLS</option><option value="ssl">SSL</option><option value="">None</option></select></div>
                                    <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" id="ei-username" placeholder="you@gmail.com"></div>
                                    <div class="col-md-6"><label class="form-label">Password <span class="text-muted small" id="ei-password-status"></span></label><input type="password" class="form-control" id="ei-password" placeholder="Leave blank to keep the current password"></div>
                                    <div class="col-md-6"><label class="form-label">Reply-To (optional)</label><input type="email" class="form-control" id="ei-reply_to" placeholder="support@meritdrives.com"></div>
                                    <div class="col-md-6"><label class="form-label">Sender Name</label><input type="text" class="form-control" id="ei-from_name" placeholder="MohToh"></div>
                                    <div class="col-md-6"><label class="form-label">Sender Email</label><input type="email" class="form-control" id="ei-from_email" placeholder="no-reply@meritdrives.com"></div>
                                </div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Email Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Send a Test Email</h6>
                        <div class="row g-2 align-items-start">
                            <div class="col-md-8"><input type="email" class="form-control" id="ei-test-to" placeholder="you@example.com"></div>
                            <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" id="ei-test-btn">Send Test Email</button></div>
                        </div>
                        <div class="alert mt-2 d-none" id="ei-test-alert"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-sms me-2"></i>Phone / OTP Delivery</h5>
                        <p class="text-muted small mb-3">Phone verification and OTP codes go through whichever provider is selected here. <strong>Log Provider</strong> is the safe default: it writes the code to a local file instead of texting it, for development only.</p>
                        <div class="alert d-none" id="sms-integration-alert"></div>
                        <form id="sms-integration-form">
                            <div class="mb-3"><label class="form-label">Provider</label>
                                <select class="form-select" id="si-mode">
                                    <option value="log">Log Provider (development only — no real SMS sent)</option>
                                    <option value="termii">Termii</option>
                                    <option value="twilio">Twilio</option>
                                    <option value="vonage">Vonage</option>
                                    <option value="africastalking">Africa's Talking</option>
                                </select>
                            </div>
                            <div id="si-fields-termii" class="si-provider-fields row g-3">
                                <div class="col-md-6"><label class="form-label">API Key <span class="text-muted small" id="si-termii_api_key-status"></span></label><input type="password" class="form-control" id="si-termii_api_key" placeholder="Leave blank to keep the current key"></div>
                                <div class="col-md-6"><label class="form-label">Sender ID</label><input type="text" class="form-control" id="si-termii_sender_id" placeholder="MohToh"></div>
                            </div>
                            <div id="si-fields-twilio" class="si-provider-fields row g-3">
                                <div class="col-md-6"><label class="form-label">Account SID</label><input type="text" class="form-control" id="si-twilio_account_sid"></div>
                                <div class="col-md-6"><label class="form-label">Auth Token <span class="text-muted small" id="si-twilio_auth_token-status"></span></label><input type="password" class="form-control" id="si-twilio_auth_token" placeholder="Leave blank to keep the current token"></div>
                                <div class="col-md-6"><label class="form-label">From Number</label><input type="text" class="form-control" id="si-twilio_from_number" placeholder="+14155552671"><div class="form-text">The Twilio-provisioned SMS sender, in E.164 format — or a Messaging Service SID (MG…). Not the Account SID or Verify Service SID.</div></div>
                                <div class="col-md-6"><label class="form-label">Verify Service SID (optional)</label><input type="text" class="form-control" id="si-twilio_verify_sid"></div>
                            </div>
                            <div id="si-fields-vonage" class="si-provider-fields row g-3">
                                <div class="col-md-6"><label class="form-label">API Key</label><input type="text" class="form-control" id="si-vonage_api_key"></div>
                                <div class="col-md-6"><label class="form-label">API Secret <span class="text-muted small" id="si-vonage_api_secret-status"></span></label><input type="password" class="form-control" id="si-vonage_api_secret" placeholder="Leave blank to keep the current secret"></div>
                                <div class="col-md-6"><label class="form-label">Brand Name (sender)</label><input type="text" class="form-control" id="si-vonage_brand_name" placeholder="MohToh"></div>
                            </div>
                            <div id="si-fields-africastalking" class="si-provider-fields row g-3">
                                <div class="col-md-6"><label class="form-label">Username</label><input type="text" class="form-control" id="si-africastalking_username"></div>
                                <div class="col-md-6"><label class="form-label">API Key <span class="text-muted small" id="si-africastalking_api_key-status"></span></label><input type="password" class="form-control" id="si-africastalking_api_key" placeholder="Leave blank to keep the current key"></div>
                                <div class="col-md-6"><label class="form-label">Sender ID</label><input type="text" class="form-control" id="si-africastalking_sender_id"></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Phone Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Send a Test SMS</h6>
                        <div class="row g-2 align-items-start">
                            <div class="col-md-8"><input type="text" class="form-control" id="si-test-to" placeholder="+2348012345678"></div>
                            <div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" id="si-test-btn">Send Test SMS</button></div>
                        </div>
                        <div class="alert mt-2 d-none" id="si-test-alert"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-key me-2"></i>OTP Manager</h5>
                        <p class="text-muted small mb-3">Tuning knobs behind every verification code and reset token. Takes effect on the next code generated — nothing already sent changes.</p>
                        <div class="alert d-none" id="otp-integration-alert"></div>
                        <form id="otp-integration-form">
                            <div class="row g-3">
                                <div class="col-md-3"><label class="form-label">OTP Length (digits)</label><input type="number" class="form-control" id="oi-otp_length" min="4" max="8"></div>
                                <div class="col-md-3"><label class="form-label">OTP Expiry (minutes)</label><input type="number" class="form-control" id="oi-otp_ttl_minutes" min="1"></div>
                                <div class="col-md-3"><label class="form-label">Max Verify Attempts</label><input type="number" class="form-control" id="oi-otp_max_attempts" min="1"></div>
                                <div class="col-md-3"><label class="form-label">Max Resends / Hour</label><input type="number" class="form-control" id="oi-otp_max_resends" min="1"></div>
                                <div class="col-md-4"><label class="form-label">Email Verification Link Expiry (minutes)</label><input type="number" class="form-control" id="oi-email_verification_ttl_minutes" min="1"></div>
                                <div class="col-md-4"><label class="form-label">Email Resend Cooldown (seconds)</label><input type="number" class="form-control" id="oi-email_resend_cooldown_seconds" min="1"></div>
                                <div class="col-md-4"><label class="form-label">Password Reset Token Expiry (minutes)</label><input type="number" class="form-control" id="oi-reset_token_ttl_minutes" min="1"></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save OTP Settings</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-shield-halved me-2"></i>Verification Channels</h5>
                        <p class="text-muted small mb-3">Turn each OTP verification channel on or off <strong>independently</strong>. These control only the verification step — your SMTP and SMS providers keep sending transactional emails, reset links and other notifications regardless. Disabling a channel does <strong>not</strong> change any user's existing verified status; that channel is simply no longer required.</p>
                        <div class="alert d-none" id="verification-integration-alert"></div>
                        <form id="verification-integration-form">
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="vi-email_verification_enabled"><label class="form-check-label" for="vi-email_verification_enabled">Enable Email Verification</label></div>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="vi-phone_verification_enabled"><label class="form-check-label" for="vi-phone_verification_enabled">Enable Phone Verification</label></div>
                            <button class="btn btn-primary mt-2" type="submit">Save Verification Channels</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-user-shield me-2"></i>Registration Policy</h5>
                        <p class="text-muted small mb-3">Ride/booking creation always requires both email and phone verified, and drivers always need vehicle + KYC approval, regardless of these toggles — those are non-optional platform safety rules. This only controls whether <strong>login itself</strong> is also blocked for an unverified account.</p>
                        <div class="alert d-none" id="policy-integration-alert"></div>
                        <form id="policy-integration-form">
                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pi-require_email_to_login"><label class="form-check-label" for="pi-require_email_to_login">Require verified email to log in</label></div>
                            <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pi-require_phone_to_login"><label class="form-check-label" for="pi-require_phone_to_login">Require verified phone to log in</label></div>
                            <button class="btn btn-primary mt-2" type="submit">Save Registration Policy</button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-map-location-dot me-2"></i>Google Maps</h5>
                        <p class="text-muted small mb-3">Powers every map, address autocomplete, reverse-geocode, route and distance/ETA in the app. Enter your <strong>Google Maps API key</strong> — the single source of truth is the <code>google_maps</code> row of the <code>integrations</code> table (its <code>configuration</code> JSON). Leave the key blank to keep the saved key. The frontend key is fetched from the session-guarded <code>api/maps-key.php</code> — never hardcoded. Restrict the key by HTTP referrer (and/or IP) in the Google Cloud console.</p>
                        <div class="alert d-none" id="maps-integration-alert"></div>
                        <form id="maps-integration-form">
                            <div class="row g-3">
                                <div class="col-md-12"><label class="form-label">Google Maps API Key <span class="text-muted small" id="mi-api_key-status"></span></label><input type="password" class="form-control" id="mi-api_key" placeholder="Leave blank to keep the current key"><div class="form-text">Used for both the browser (Maps/Places/Geocoder/Directions) and server-side calls (Directions/Distance Matrix).</div></div>
                            </div>
                            <h6 class="fw-bold mt-3 mb-2">Enabled APIs</h6>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="mi-enable_places"><label class="form-check-label" for="mi-enable_places">Places &amp; Autocomplete</label></div>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="mi-enable_directions"><label class="form-check-label" for="mi-enable_directions">Directions &amp; Distance Matrix (real road routes, distance &amp; ETA)</label></div>
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="mi-enable_geocoding"><label class="form-check-label" for="mi-enable_geocoding">Geocoding &amp; Reverse Geocoding</label></div>
                            <button class="btn btn-primary mt-2" type="submit">Save Google Maps Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Test Connection</h6>
                        <p class="text-muted small mb-2">Runs a live reverse-geocode against the saved key to confirm it works and is authorized for your domain/IP.</p>
                        <button class="btn btn-outline-primary" type="button" id="mi-test-btn">Test Google Maps</button>
                        <div class="alert mt-2 d-none" id="mi-test-alert"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-credit-card me-2"></i>Payment API (Paystack) <span class="badge bg-secondary align-middle ms-1" id="pay-provider-status">Loading…</span></h5>
                        <p class="text-muted small mb-3">Controls the live payment gateway used for wallet top-ups. The single source of truth is the <code>paystack</code> row of the <code>integrations</code> table — its <code>status</code> (Enabled/Disabled) and its <code>configuration</code> JSON. The <strong>secret key is encrypted at rest and never sent back to the browser</strong> — only a masked preview is shown; leave a secret field blank to keep the saved value. Enable/Disable is enforced by the backend payment flow, not just this UI: when disabled, no new charge is started. The publishable key is safe to display in full.</p>
                        <div class="alert d-none" id="payment-integration-alert"></div>
                        <form id="payment-integration-form">
                            <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="pay-enabled"><label class="form-check-label" for="pay-enabled">Enable Paystack payments</label></div>
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="pay-sandbox_mode"><label class="form-check-label" for="pay-sandbox_mode">Sandbox / test mode (off = live)</label></div>
                            <div class="row g-3">
                                <div class="col-md-12"><label class="form-label">Public Key (publishable)</label><input type="text" class="form-control" id="pay-public_key" placeholder="pk_live_… or pk_test_…"><div class="form-text">Safe to expose to the browser. Used to identify your Paystack account.</div></div>
                                <div class="col-md-12"><label class="form-label">Secret Key <span class="text-muted small" id="pay-secret_key-status"></span></label><input type="password" class="form-control" id="pay-secret_key" placeholder="Leave blank to keep the current key"><div class="form-text">Server-side only — stored encrypted, never returned to the browser. Signs all Paystack API calls.</div></div>
                                <div class="col-md-12"><label class="form-label">Webhook Secret <span class="text-muted small" id="pay-webhook_secret-status"></span></label><input type="password" class="form-control" id="pay-webhook_secret" placeholder="Leave blank to keep the current value"><div class="form-text">Optional. Used server-side to verify the X-Paystack-Signature on incoming webhook events.</div></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Payment Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Test Connection</h6>
                        <p class="text-muted small mb-2">Runs a read-only authenticated check against Paystack (fetches your account balance) to confirm the saved secret key is valid. <strong>No customer payment is created or charged.</strong></p>
                        <button class="btn btn-outline-primary" type="button" id="pay-test-btn">Test Connection</button>
                        <div class="alert mt-2 d-none" id="pay-test-alert"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fab fa-google me-2"></i>Continue with Google (OAuth) <span class="badge bg-secondary align-middle ms-1" id="google-oauth-status">Loading…</span></h5>
                        <p class="text-muted small mb-3">Lets users sign in with their Google account using the secure OAuth 2.0 <strong>authorization-code</strong> flow (no tokens ever touch the browser). The <strong>Enable</strong> switch controls BOTH the button on the login screen AND the backend authorize/callback — when off, the flow is rejected server-side, not merely hidden. The <strong>Client Secret is encrypted at rest and never returned to the browser</strong> (only a masked preview is shown; leave it blank to keep the saved value). Register the exact <strong>Redirect URI</strong> below in Google Cloud → APIs &amp; Services → Credentials → your OAuth 2.0 Client ID.</p>
                        <div class="alert d-none" id="google-oauth-alert"></div>
                        <form id="google-oauth-form">
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="goog-enabled"><label class="form-check-label" for="goog-enabled">Enable Continue with Google</label></div>
                            <div class="row g-3">
                                <div class="col-md-12"><label class="form-label">Client ID</label><input type="text" class="form-control" id="goog-client_id" placeholder="xxxxxxxx.apps.googleusercontent.com"><div class="form-text">The OAuth 2.0 Client ID from Google Cloud. Public by design — safe to store in the clear.</div></div>
                                <div class="col-md-12"><label class="form-label">Client Secret <span class="text-muted small" id="goog-client_secret-status"></span></label><input type="password" class="form-control" id="goog-client_secret" placeholder="Leave blank to keep the current secret"><div class="form-text">Server-side only — stored encrypted, never returned to the browser.</div></div>
                                <div class="col-md-12"><label class="form-label">Redirect URI</label><input type="text" class="form-control" id="goog-redirect_uri" placeholder="https://your-domain/auth/oauth/google/callback.php"><div class="form-text">Must EXACTLY match an Authorized redirect URI in Google Cloud. Default for this deployment: <code id="goog-redirect_uri-default"></code> · <a href="#" id="goog-redirect_uri-reset">use default</a></div></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Google Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Test Configuration</h6>
                        <p class="text-muted small mb-2">Verifies the saved Client ID/Secret against Google without performing any real login. <strong>No user is signed in and no token is issued.</strong></p>
                        <button class="btn btn-outline-primary" type="button" id="goog-test-btn">Test Configuration</button>
                        <div class="alert mt-2 d-none" id="goog-test-alert"></div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fab fa-facebook me-2"></i>Continue with Facebook (OAuth) <span class="badge bg-secondary align-middle ms-1" id="facebook-oauth-status">Loading…</span></h5>
                        <p class="text-muted small mb-3">Lets users sign in with Facebook (Meta Login) using the secure OAuth 2.0 <strong>authorization-code</strong> flow. The <strong>Enable</strong> switch controls BOTH the login-screen button AND the backend authorize/callback — a disabled provider is rejected server-side. The <strong>App Secret is encrypted at rest and never returned to the browser</strong> (masked preview only; leave blank to keep the saved value). Register the exact <strong>Redirect URI</strong> below as a Valid OAuth Redirect URI under Meta for Developers → your App → Facebook Login → Settings. Note: MohToh treats a Facebook email as <em>unverified</em>, so it never auto-matches an existing account by email alone.</p>
                        <div class="alert d-none" id="facebook-oauth-alert"></div>
                        <form id="facebook-oauth-form">
                            <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="fb-enabled"><label class="form-check-label" for="fb-enabled">Enable Continue with Facebook</label></div>
                            <div class="row g-3">
                                <div class="col-md-12"><label class="form-label">App ID</label><input type="text" class="form-control" id="fb-client_id" placeholder="Your Meta App ID (numeric)"><div class="form-text">The App ID from Meta for Developers. Public by design — safe to store in the clear.</div></div>
                                <div class="col-md-12"><label class="form-label">App Secret <span class="text-muted small" id="fb-client_secret-status"></span></label><input type="password" class="form-control" id="fb-client_secret" placeholder="Leave blank to keep the current secret"><div class="form-text">Server-side only — stored encrypted, never returned to the browser.</div></div>
                                <div class="col-md-12"><label class="form-label">Redirect URI</label><input type="text" class="form-control" id="fb-redirect_uri" placeholder="https://your-domain/auth/oauth/facebook/callback.php"><div class="form-text">Must EXACTLY match a Valid OAuth Redirect URI in your Meta app. Default for this deployment: <code id="fb-redirect_uri-default"></code> · <a href="#" id="fb-redirect_uri-reset">use default</a></div></div>
                            </div>
                            <button class="btn btn-primary mt-3" type="submit">Save Facebook Settings</button>
                        </form>
                        <hr class="my-4">
                        <h6 class="fw-bold mb-2">Test Configuration</h6>
                        <p class="text-muted small mb-2">Verifies the saved App ID/Secret against Facebook (an app-token check) without performing any real login. <strong>No user is signed in.</strong></p>
                        <button class="btn btn-outline-primary" type="button" id="fb-test-btn">Test Configuration</button>
                        <div class="alert mt-2 d-none" id="fb-test-alert"></div>
                    </div>
                </div>
            </section>
                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <h5 class="fw-bold mb-0"><i class="fas fa-heart-pulse me-2"></i>System Diagnostics</h5>
                            <button class="btn btn-outline-secondary btn-sm" type="button" id="diag-refresh-btn">Refresh</button>
                        </div>
                        <p class="text-muted small mb-3">Live checks — Database is queried directly; Email/SMS report real connectivity plus the result of the last "Send Test" from the Service Integration Center (a full send isn't repeated here since it costs quota on live provider accounts); Paystack/Maps/Webhook report configuration state.</p>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Channel</th><th>Status</th><th>Detail</th><th>Last Test</th></tr></thead>
                                <tbody id="diagnostics-body"><tr><td colspan="4" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card mt-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-clipboard-list me-2"></i>Audit Log</h5>
                        <p class="text-muted small mb-3">Every approve/reject/delete action across vehicles, driver verification, premium requests, and message reports (Parts 5/6) — who, what, when, and why.</p>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><select class="form-select form-select-sm" id="audit-filter-entity"><option value="">All entity types</option><option value="vehicle">Vehicle</option><option value="driver_verification">Driver Verification</option><option value="premium_request">Premium Request</option><option value="premium_feature">Premium Feature</option><option value="message_report">Message Report</option></select></div>
                            <div class="col-md-3"><select class="form-select form-select-sm" id="audit-filter-action"><option value="">All actions</option><option value="approve">Approve</option><option value="reject">Reject</option><option value="delete">Delete</option><option value="disable">Disable</option><option value="create">Create</option><option value="update">Update</option></select></div>
                            <div class="col-md-3"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="audit-filter-btn">Apply Filters</button></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead><tr><th>Admin</th><th>Action</th><th>Entity</th><th>Status Change</th><th>Reason</th><th>When</th></tr></thead>
                                <tbody id="audit-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div id="audit-pagination"></div>
                    </div>
                </div>
            </section>

            <section id="tab-chat-monitoring" class="tab-pane">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-1"><i class="fas fa-comments me-2"></i>Reported Messages</h5>
                        <p class="text-muted small mb-3">Normal ride/private/support chats stay private unless reported, under safety investigation, or required by law — this queue is the "reported" case. Open items are listed first.</p>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Reported</th><th>Message</th><th>Sender</th><th>Reason</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="chat-reports-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0 form-card">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-search me-2"></i>Search Conversations</h5>
                        <p class="text-muted small">Search by participant name, phone, public user ID, or ride ID.</p>
                        <div class="input-group mb-3" style="max-width:480px;">
                            <input type="text" class="form-control" id="chat-search-input" placeholder="e.g. Jane Doe, +234…, RDR-…, RIDE-…">
                            <button class="btn btn-primary" type="button" id="chat-search-btn">Search</button>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Type</th><th>Ride</th><th>Status</th><th>Created</th><th></th></tr></thead>
                                <tbody id="chat-search-results-body"><tr><td colspan="5" class="text-muted text-center py-3">Search to see results.</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>

            <section id="tab-community-growth" class="tab-pane">
                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-sliders-h me-2"></i>Referral Settings</h5>
                        <p class="text-muted small mb-3">MohToh Community Rewards — nothing here is hardcoded; rewards are only paid after real qualifying activity (never bare registration). Leave a reward amount at 0 to disable that specific reward.</p>
                        <div class="alert d-none" id="referral-settings-alert"></div>
                        <form id="referral-settings-form" class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">System enabled</label>
                                <select class="form-select" id="rs-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Max rewards per user</label>
                                <input type="number" min="0" class="form-control" id="rs-max-rewards" placeholder="0 = unlimited">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Reward expiry (days)</label>
                                <input type="number" min="0" class="form-control" id="rs-expiry-days">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Rider inviter reward (₦)</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="rs-rider-reward">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">New rider reward (₦)</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="rs-new-rider-reward">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Driver inviter reward (₦)</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="rs-driver-reward">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">New driver reward (₦)</label>
                                <input type="number" min="0" step="0.01" class="form-control" id="rs-new-driver-reward">
                            </div>
                            <div class="col-12"><button type="submit" class="btn btn-primary">Save Referral Settings</button></div>
                        </form>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Total Referrals</div><h3 class="fw-bold mb-0" id="cg-total">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Successful</div><h3 class="fw-bold mb-0" id="cg-successful">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Driver Referrals</div><h3 class="fw-bold mb-0" id="cg-driver">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Rider Referrals</div><h3 class="fw-bold mb-0" id="cg-rider">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Cancelled</div><h3 class="fw-bold mb-0" id="cg-cancelled">0</h3></div></div>
                    <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Rewards Issued</div><h3 class="fw-bold mb-0" id="cg-rewards">₦0</h3></div></div>
                </div>

                <div class="card shadow-sm border-0 mb-4">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-trophy me-2"></i>Top Community Builders</h5>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>User</th><th>Type</th><th>Total Referrals</th><th>Successful</th><th>Rewards Earned</th></tr></thead>
                                <tbody id="cg-top-builders-body"><tr><td colspan="5" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <h5 class="fw-bold mb-3"><i class="fas fa-list me-2"></i>All Referrals</h5>
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="date" class="form-control form-control-sm" id="cg-filter-date-from" placeholder="From"></div>
                            <div class="col-md-3"><input type="date" class="form-control form-control-sm" id="cg-filter-date-to" placeholder="To"></div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" id="cg-filter-type">
                                    <option value="">Any user type</option>
                                    <option value="RIDER">Rider</option>
                                    <option value="DRIVER">Driver</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select form-select-sm" id="cg-filter-status">
                                    <option value="">Any status</option>
                                    <option value="REGISTERED">Registered</option>
                                    <option value="VERIFIED">Verified</option>
                                    <option value="QUALIFIED">Qualified</option>
                                    <option value="REWARDED">Rewarded</option>
                                    <option value="CANCELLED">Cancelled</option>
                                </select>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Referrer</th><th>Referred</th><th>Type</th><th>Status</th><th>Registered</th></tr></thead>
                                <tbody id="cg-referrals-body"><tr><td colspan="5" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <span class="text-muted small" id="cg-pagination-info"></span>
                            <div>
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="cg-prev-page">Previous</button>
                                <button class="btn btn-outline-secondary btn-sm" type="button" id="cg-next-page">Next</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($adminCan('network.view')): ?>
            <section id="tab-mohtoh-network" class="tab-pane">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fas fa-share-nodes me-2"></i>MOHTOH NETWORK</h5>
                        <small class="text-muted">Share the Journey. Create Opportunity. — direct referral &amp; revenue-share programme.</small>
                    </div>
                    <ul class="nav nav-pills" id="net-subtabs">
                        <li class="nav-item"><a class="nav-link active" href="#" data-netsub="overview">Overview</a></li>
                        <?php if ($adminIsSuper): ?><li class="nav-item"><a class="nav-link" href="#" data-netsub="settings">Settings</a></li><?php endif; ?>
                        <?php if ($adminCan('network.partners.manage') || $adminCan('network.view')): ?><li class="nav-item"><a class="nav-link" href="#" data-netsub="partners">Partners</a></li><?php endif; ?>
                        <li class="nav-item"><a class="nav-link" href="#" data-netsub="referrals">Referrals</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-netsub="rewards">Rewards</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-netsub="withdrawals">Withdrawals</a></li>
                        <li class="nav-item"><a class="nav-link" href="#" data-netsub="fraud">Fraud</a></li>
                    </ul>
                </div>
                <div class="alert d-none" id="net-alert"></div>

                <div class="net-subtab" id="net-sub-overview">
                    <div class="row g-3 mb-4">
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Network Partners</div><h3 class="fw-bold mb-0" id="net-ov-partners">0</h3></div></div>
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Pending Approval</div><h3 class="fw-bold mb-0" id="net-ov-pending">0</h3></div></div>
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Active Referrals</div><h3 class="fw-bold mb-0" id="net-ov-active-referrals">0</h3></div></div>
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Converted (1st ride)</div><h3 class="fw-bold mb-0" id="net-ov-converted">0</h3></div></div>
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Open Fraud Flags</div><h3 class="fw-bold mb-0" id="net-ov-fraud">0</h3></div></div>
                        <div class="col-md-4 col-xl-2"><div class="card stat-card p-3"><div class="text-muted small">Withdrawals Paid</div><h3 class="fw-bold mb-0" id="net-ov-withdrawn">₦0</h3></div></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0"><div class="card-body">
                                <h6 class="fw-bold mb-3">Reward liability</h6>
                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Total generated</span><strong id="net-ov-generated">₦0</strong></div>
                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Pending (on hold)</span><strong id="net-ov-rpending">₦0</strong></div>
                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Available for withdrawal</span><strong id="net-ov-ravailable">₦0</strong></div>
                                <div class="d-flex justify-content-between"><span class="text-muted">Reversed</span><strong id="net-ov-rreversed">₦0</strong></div>
                            </div></div>
                        </div>
                        <div class="col-md-6">
                            <div class="card shadow-sm border-0"><div class="card-body">
                                <h6 class="fw-bold mb-3">Net contribution</h6>
                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Referral-generated commission</span><strong id="net-ov-commission">₦0</strong></div>
                                <div class="d-flex justify-content-between mb-2"><span class="text-muted">Minus rewards generated</span><strong id="net-ov-generated2">₦0</strong></div>
                                <div class="d-flex justify-content-between"><span class="text-muted">= Net contribution</span><strong id="net-ov-net" class="text-success">₦0</strong></div>
                            </div></div>
                        </div>
                    </div>
                </div>

                <?php if ($adminIsSuper): ?>
                <div class="net-subtab d-none" id="net-sub-settings">
                    <div class="card shadow-sm border-0 mb-4"><div class="card-body">
                        <h6 class="fw-bold mb-3">Programme switches</h6>
                        <form id="net-settings-form" class="row g-3">
                            <div class="col-md-3"><label class="form-label">MOHTOH NETWORK enabled</label><select class="form-select form-select-sm" id="net-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">New partner registrations</label><select class="form-select form-select-sm" id="net-registrations-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Referral links</label><select class="form-select form-select-sm" id="net-links-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Reward generation</label><select class="form-select form-select-sm" id="net-rewards-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Withdrawals</label><select class="form-select form-select-sm" id="net-withdrawals-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Driver referral rewards</label><select class="form-select form-select-sm" id="net-driver-referrals-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Passenger referral rewards</label><select class="form-select form-select-sm" id="net-passenger-referrals-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">External partner participation</label><select class="form-select form-select-sm" id="net-external-partners-enabled"><option value="1">Enabled</option><option value="0">Disabled</option></select></div>
                            <div class="col-md-3"><label class="form-label">Maintenance mode</label><select class="form-select form-select-sm" id="net-maintenance-mode"><option value="0">Off</option><option value="1">On</option></select></div>
                            <div class="col-md-3"><label class="form-label text-danger">Emergency: suspend ALL payouts</label><select class="form-select form-select-sm" id="net-emergency-payout-suspended"><option value="0">Off</option><option value="1">Suspended</option></select></div>
                            <div class="col-md-3"><label class="form-label">Auto-approve new partners</label><select class="form-select form-select-sm" id="net-partner-auto-approve"><option value="0">Manual review</option><option value="1">Auto-approve</option></select></div>
                            <div class="col-md-3"><label class="form-label">Programme launch date</label><input type="datetime-local" class="form-control form-control-sm" id="net-launch-at"></div>
                            <div class="col-md-3"><label class="form-label">Programme closing date</label><input type="datetime-local" class="form-control form-control-sm" id="net-closes-at"></div>

                            <div class="col-12"><hr></div>
                            <h6 class="fw-bold">Reward configuration</h6>
                            <div class="col-md-3"><label class="form-label">Driver referral reward (%)</label><input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" id="net-driver-reward-pct"></div>
                            <div class="col-md-3"><label class="form-label">Passenger referral reward (%)</label><input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" id="net-passenger-reward-pct"></div>
                            <div class="col-md-3"><label class="form-label">Combined reward ceiling (%)</label><input type="number" min="0" max="100" step="0.01" class="form-control form-control-sm" id="net-combined-ceiling-pct"></div>
                            <div class="col-md-3"><label class="form-label">Earning duration (days)</label><input type="number" min="1" class="form-control form-control-sm" id="net-earning-days"></div>
                            <div class="col-md-3"><label class="form-label">Minimum withdrawal (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-min-withdrawal"></div>
                            <div class="col-md-3"><label class="form-label">Registration bonus (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-registration-bonus"></div>
                            <div class="col-md-3"><label class="form-label">Reward holding period (days)</label><input type="number" min="0" class="form-control form-control-sm" id="net-reward-hold-days"></div>

                            <div class="col-12"><hr></div>
                            <h6 class="fw-bold">Financial controls <span class="text-muted small fw-normal">(0 = unlimited)</span></h6>
                            <div class="col-md-3"><label class="form-label">Total reward budget (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-total-budget"></div>
                            <div class="col-md-3"><label class="form-label">Daily reward budget (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-daily-budget"></div>
                            <div class="col-md-3"><label class="form-label">Monthly reward budget (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-monthly-budget"></div>
                            <div class="col-md-3"><label class="form-label">Max combined payout/txn (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-max-combined-payout-per-txn"></div>
                            <div class="col-md-3"><label class="form-label">Max withdrawal/day (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-max-withdrawal-per-day"></div>
                            <div class="col-md-3"><label class="form-label">Max withdrawal/month (₦)</label><input type="number" min="0" step="0.01" class="form-control form-control-sm" id="net-max-withdrawal-per-month"></div>

                            <div class="col-12"><hr></div>
                            <h6 class="fw-bold">Fraud thresholds <span class="text-muted small fw-normal">(flags only — never auto-blocks)</span></h6>
                            <div class="col-md-4"><label class="form-label">Max new referrals / partner / day</label><input type="number" min="0" class="form-control form-control-sm" id="net-fraud-velocity-max"></div>
                            <div class="col-md-4"><label class="form-label">Max rewarded bookings / same driver-rider pair</label><input type="number" min="0" class="form-control form-control-sm" id="net-fraud-same-pair-max"></div>

                            <div class="col-12"><hr></div>
                            <h6 class="fw-bold">Transparency</h6>
                            <div class="col-md-6"><label class="form-label">Announcement banner</label><input type="text" class="form-control form-control-sm" id="net-announcement" placeholder="Shown to partners on their dashboard"></div>
                            <div class="col-md-2"><label class="form-label">Terms version</label><input type="text" class="form-control form-control-sm" id="net-terms-version"></div>
                            <div class="col-md-12"><label class="form-label">Programme terms &amp; conditions</label><textarea class="form-control form-control-sm" id="net-terms-text" rows="4"></textarea></div>

                            <div class="col-12"><button type="submit" class="btn btn-primary">Save MOHTOH NETWORK Settings</button></div>
                        </form>
                    </div></div>

                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-bold mb-0">Campaigns</h6>
                            <button class="btn btn-outline-primary btn-sm" type="button" id="net-new-campaign-btn">New Campaign</button>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Name</th><th>Driver %</th><th>Passenger %</th><th>Ceiling %</th><th>Window</th><th>Status</th><th></th></tr></thead>
                            <tbody id="net-campaigns-body"><tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                    </div></div>
                </div>
                <?php endif; ?>

                <div class="net-subtab d-none" id="net-sub-partners">
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-4"><input type="text" class="form-control form-control-sm" id="net-p-q" placeholder="Search name, phone, email or code"></div>
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-p-status"><option value="">Any status</option><option value="pending">Pending</option><option value="approved">Approved</option><option value="suspended">Suspended</option><option value="rejected">Rejected</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="net-p-export"><i class="fas fa-file-csv me-1"></i>Export CSV</button></div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Partner</th><th>Code</th><th>Status</th><th>Referrals</th><th>Lifetime Rewards</th><th>Joined</th><th></th></tr></thead>
                            <tbody id="net-partners-body"><tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                        <div id="net-partners-pagination"></div>
                    </div></div>
                </div>

                <div class="net-subtab d-none" id="net-sub-referrals">
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><input type="text" class="form-control form-control-sm" id="net-r-q" placeholder="Search referrer or referred"></div>
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-r-category"><option value="">Any category</option><option value="driver">Driver</option><option value="passenger">Passenger</option></select></div>
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-r-status"><option value="">Any status</option><option value="registered">Registered</option><option value="verified">Verified</option><option value="active">Active (earning)</option><option value="expired">Expired</option><option value="cancelled">Cancelled</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="net-r-export"><i class="fas fa-file-csv me-1"></i>Export CSV</button></div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Referrer</th><th>Referred</th><th>Category</th><th>Status</th><th>Earning window</th><th>Qualifying rides</th><th>Rewards</th><th></th></tr></thead>
                            <tbody id="net-referrals-body"><tr><td colspan="8" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                        <div id="net-referrals-pagination"></div>
                    </div></div>
                </div>

                <div class="net-subtab d-none" id="net-sub-rewards">
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="row g-2 mb-3">
                            <!-- No "Withdrawn"/"Suspended" options: a reward row's own status never
                                 reaches either value today — withdrawal draws from the aggregate
                                 available pool (see docs/mohtoh-network.md), and no reward-level
                                 suspend action exists yet (only partner-level suspension does).
                                 Listing them here would silently always return zero rows. -->
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-rw-status"><option value="">Any status</option><option value="pending">Pending</option><option value="available">Available</option><option value="reversed">Reversed</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="net-rw-export"><i class="fas fa-file-csv me-1"></i>Export CSV</button></div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Beneficiary</th><th>Role</th><th>Commission base</th><th>%</th><th>Reward</th><th>Status</th><th>Created</th><th></th></tr></thead>
                            <tbody id="net-rewards-body"><tr><td colspan="8" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                        <div id="net-rewards-pagination"></div>
                    </div></div>
                </div>

                <div class="net-subtab d-none" id="net-sub-withdrawals">
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-w-status"><option value="">Any status</option><option value="pending">Pending</option><option value="under_review">Under review</option><option value="approved">Approved</option><option value="processing">Processing</option><option value="paid">Paid</option><option value="rejected">Rejected</option><option value="failed">Failed</option><option value="reversed">Reversed</option></select></div>
                            <div class="col-md-2"><button class="btn btn-outline-secondary btn-sm w-100" type="button" id="net-w-export"><i class="fas fa-file-csv me-1"></i>Export CSV</button></div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>Partner</th><th>Amount</th><th>Bank</th><th>Status</th><th>Requested</th><th>Reference</th><th></th></tr></thead>
                            <tbody id="net-withdrawals-body"><tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                        <div id="net-withdrawals-pagination"></div>
                    </div></div>
                </div>

                <div class="net-subtab d-none" id="net-sub-fraud">
                    <div class="card shadow-sm border-0"><div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-md-3"><select class="form-select form-select-sm" id="net-f-status"><option value="">Any status</option><option value="open">Open</option><option value="reviewing">Reviewing</option><option value="resolved">Resolved</option><option value="dismissed">Dismissed</option></select></div>
                        </div>
                        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                            <thead><tr><th>User</th><th>Flag</th><th>Severity</th><th>Details</th><th>Status</th><th>Raised</th><th></th></tr></thead>
                            <tbody id="net-fraud-body"><tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                        </table></div>
                        <div id="net-fraud-pagination"></div>
                    </div></div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($adminIsSuper): ?>
            <section id="tab-administrators" class="tab-pane">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fas fa-user-shield me-2"></i>Administrators</h5>
                        <small class="text-muted">Create and manage Subadmins and their permissions. Super Admin only — every action is server-enforced and audited.</small>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <button class="btn btn-outline-primary btn-sm" type="button" onclick="openPromoteExisting()"><i class="fas fa-user-plus me-1"></i>Add Existing User</button>
                        <button class="btn btn-primary btn-sm" type="button" onclick="openCreateSubadmin()"><i class="fas fa-plus me-1"></i>Create Subadmin</button>
                    </div>
                </div>
                <div class="alert d-none" id="admins-alert"></div>
                <div class="card shadow-sm border-0">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead><tr><th>Name</th><th>Contact</th><th>Role</th><th>Status</th><th>Permissions</th><th>Actions</th></tr></thead>
                                <tbody id="admins-body"><tr><td colspan="6" class="text-muted text-center py-3">Loading…</td></tr></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="detailModalLabel">Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="detailModalBody"></div>
            <div class="modal-footer" id="detailModalFooter"></div>
        </div>
    </div>
</div>

<div class="modal fade" id="imagePreviewModal" tabindex="-1" aria-labelledby="imagePreviewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imagePreviewModalLabel">Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body text-center p-0" id="imagePreviewBody"></div>
        </div>
    </div>
</div>

<?php if ($adminIsSuper): ?>
<div class="modal fade" id="adminMgmtModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="adminMgmtModalTitle">Subadmin</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="adminMgmtModalBody"></div>
            <div class="modal-footer">
                <div class="me-auto small" id="adminMgmtModalMsg"></div>
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="adminMgmtSaveBtn">Save</button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Emitted for the browser purely so client code can hide controls it has no
// permission for; it is NEVER the authorization — every admin endpoint
// re-checks server-side (includes/admin_permissions.php). Encoded with
// JSON_HEX_* so a crafted name can't break out of this inline script.
window.ADMIN_CTX = <?= json_encode([
    'is_super'    => $adminIsSuper,
    'name'        => $adminName,
    'permissions' => array_keys($adminCtx['permissions']),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.1/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/image-viewer.js"></script>
<script>
    // Admin live-tracking map — Google Maps. The browser key is fetched
    // from api/maps-key.php (Integration Center → maps-key.php → here); it
    // is never hardcoded. `map` is the google.maps.Map; `adminMarkers`
    // holds the current online-user markers so renderMap can clear them.
    let map = null; let adminMarkers = []; let dashboardData = {};
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.addEventListener('click', e => { e.preventDefault(); switchTab(link.dataset.tab); }));
        initMap(); loadDashboardData(); setInterval(loadDashboardData, 15000);
        document.getElementById('announcement-form').addEventListener('submit', handleAnnouncementSubmit);
        document.getElementById('pricing-center-form').addEventListener('submit', handlePricingCenterSubmit);
        document.getElementById('driver-settings-form').addEventListener('submit', handleDriverSettingsSubmit);
        document.querySelectorAll('#report-subtabs .nav-link').forEach(link => link.addEventListener('click', e => { e.preventDefault(); document.querySelectorAll('#report-subtabs .nav-link').forEach(l => l.classList.remove('active')); link.classList.add('active'); loadReport(link.dataset.report); }));
        document.getElementById('email-integration-form').addEventListener('submit', handleEmailIntegrationSubmit);
        document.getElementById('sms-integration-form').addEventListener('submit', handleSmsIntegrationSubmit);
        document.getElementById('ei-mode').addEventListener('change', toggleEmailProviderFields);
        document.getElementById('si-mode').addEventListener('change', toggleSmsProviderFields);
        document.getElementById('ei-test-btn').addEventListener('click', sendTestEmail);
        document.getElementById('si-test-btn').addEventListener('click', sendTestSms);
        document.getElementById('otp-integration-form').addEventListener('submit', handleOtpIntegrationSubmit);
        document.getElementById('policy-integration-form').addEventListener('submit', handlePolicyIntegrationSubmit);
        document.getElementById('verification-integration-form').addEventListener('submit', handleVerificationIntegrationSubmit);
        document.getElementById('maps-integration-form').addEventListener('submit', handleMapsIntegrationSubmit);
        document.getElementById('mi-test-btn').addEventListener('click', testMaps);
        document.getElementById('payment-integration-form').addEventListener('submit', handlePaymentIntegrationSubmit);
        document.getElementById('pay-test-btn').addEventListener('click', testPayment);
        OAUTH_CARDS.forEach(cfg => {
            const form = document.getElementById(cfg.form);
            if (form) form.addEventListener('submit', (e) => handleOAuthSubmit(cfg, e));
            const tbtn = document.getElementById(cfg.prefix + '-test-btn');
            if (tbtn) tbtn.addEventListener('click', () => testOAuth(cfg));
            const reset = document.getElementById(cfg.prefix + '-redirect_uri-reset');
            if (reset) reset.addEventListener('click', (e) => {
                e.preventDefault();
                const def = document.getElementById(cfg.prefix + '-redirect_uri-default');
                const inp = document.getElementById(cfg.prefix + '-redirect_uri');
                if (def && inp) inp.value = def.textContent || '';
            });
        });
        document.getElementById('diag-refresh-btn').addEventListener('click', loadDiagnostics);
        document.getElementById('chat-search-btn').addEventListener('click', searchChatConversations);
        document.getElementById('chat-search-input').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); searchChatConversations(); } });
        document.getElementById('referral-settings-form').addEventListener('submit', handleReferralSettingsSubmit);
        document.getElementById('cg-filter-date-from').addEventListener('change', () => loadReferralsList(1));
        document.getElementById('cg-filter-date-to').addEventListener('change', () => loadReferralsList(1));
        document.getElementById('cg-filter-type').addEventListener('change', () => loadReferralsList(1));
        document.getElementById('cg-filter-status').addEventListener('change', () => loadReferralsList(1));
        document.getElementById('cg-prev-page').addEventListener('click', () => loadReferralsList(Math.max(1, cgCurrentPage - 1)));
        document.getElementById('cg-next-page').addEventListener('click', () => loadReferralsList(cgCurrentPage + 1));
        document.querySelectorAll('#net-subtabs .nav-link').forEach(link => link.addEventListener('click', e => { e.preventDefault(); switchNetworkSubtab(link.dataset.netsub); }));
        const netSettingsForm = document.getElementById('net-settings-form');
        if (netSettingsForm) netSettingsForm.addEventListener('submit', handleNetworkSettingsSubmit);
        const netNewCampaignBtn = document.getElementById('net-new-campaign-btn');
        if (netNewCampaignBtn) netNewCampaignBtn.addEventListener('click', createNetworkCampaign);
        ['net-p-q', 'net-p-status'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener(id.endsWith('-q') ? 'input' : 'change', debounce(() => loadNetworkPartners(1), 300)); });
        ['net-r-q', 'net-r-category', 'net-r-status'].forEach(id => { const el = document.getElementById(id); if (el) el.addEventListener(id.endsWith('-q') ? 'input' : 'change', debounce(() => loadNetworkReferrals(1), 300)); });
        const netRwStatus = document.getElementById('net-rw-status');
        if (netRwStatus) netRwStatus.addEventListener('change', () => loadNetworkRewards(1));
        const netWStatus = document.getElementById('net-w-status');
        if (netWStatus) netWStatus.addEventListener('change', () => loadNetworkWithdrawals(1));
        const netFStatus = document.getElementById('net-f-status');
        if (netFStatus) netFStatus.addEventListener('change', () => loadNetworkFraud(1));
        document.getElementById('net-p-export')?.addEventListener('click', exportNetworkPartnersCsv);
        document.getElementById('net-r-export')?.addEventListener('click', exportNetworkReferralsCsv);
        document.getElementById('net-rw-export')?.addEventListener('click', exportNetworkRewardsCsv);
        document.getElementById('net-w-export')?.addEventListener('click', exportNetworkWithdrawalsCsv);

        // Part 1 — sidebar collapse (desktop) / off-canvas (mobile).
        const sidebarEl = document.getElementById('adminSidebar');
        const scrimEl = document.getElementById('sidebarScrimEl');
        document.getElementById('sidebarCollapseBtn').addEventListener('click', () => {
            sidebarEl.classList.toggle('is-collapsed');
            document.body.classList.toggle('sidebar-collapsed');
        });
        const openMobileSidebar = () => { sidebarEl.classList.add('is-open'); scrimEl.classList.add('is-open'); };
        const closeMobileSidebar = () => { sidebarEl.classList.remove('is-open'); scrimEl.classList.remove('is-open'); };
        document.getElementById('sidebarMobileToggle').addEventListener('click', openMobileSidebar);
        scrimEl.addEventListener('click', closeMobileSidebar);
        document.querySelectorAll('.sidebar .nav-link').forEach(link => link.addEventListener('click', closeMobileSidebar));
    });
    // Tabs that lazy-load their data the first time they're opened, rather
    // than on every 15s dashboard poll — none of this data changes as
    // often as the live dashboard/map does.
    const TAB_LOADERS = { pricing: loadPricingCenter, 'driver-settings': loadDriverSettingsTab, 'regional-pricing': loadRegions, promotions: loadPromotions, commission: loadCommissionReport, 'driver-comms': loadDriverCommsTab, reports: () => loadReport('daily'), integrations: loadIntegrationCenter, notifications: loadNotificationCenter, diagnostics: loadDiagnostics, 'chat-monitoring': loadChatMonitoring, waitlist: loadWaitlistCenter, premium: loadPremiumCenter, 'community-growth': loadCommunityGrowthTab, 'ride-requests': loadRideRequestsCenter, administrators: loadAdminsTab, 'mohtoh-network': loadNetworkTab };
    const loadedTabs = new Set();
    function switchTab(tabName) { document.querySelectorAll('.tab-pane').forEach(tab => tab.classList.remove('active')); document.querySelectorAll('.sidebar .nav-link').forEach(link => link.classList.remove('active')); document.getElementById(`tab-${tabName}`).classList.add('active'); document.querySelector(`.sidebar .nav-link[data-tab="${tabName}"]`).classList.add('active'); if (TAB_LOADERS[tabName] && !loadedTabs.has(tabName)) { loadedTabs.add(tabName); TAB_LOADERS[tabName](); } }
    // ---- Chat Monitoring (Task 5) — reuses toggleUserAccess()/sendAction() and #detailModal, no new admin page ----
    async function loadChatMonitoring() { const res = await fetch('../api/admin-api.php?action=list_reported_chats'); const data = await res.json(); if (data.status === 'success') renderReportedChats(data.data.reports || []); }
    function renderReportedChats(reports) { const body = document.getElementById('chat-reports-body'); if (!body) return; if (!reports.length) { body.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3">No reported messages.</td></tr>'; return; } body.innerHTML = reports.map(r => `<tr><td class="small text-muted">${r.reported_at ? new Date(r.reported_at).toLocaleString() : ''}</td><td style="max-width:260px;">${escapeHtml(r.message_type === 'location' ? '[Shared a location]' : (r.message || ''))}</td><td>${escapeHtml(r.sender_name || 'Unknown')}${r.sender_is_suspended ? ' <span class="badge bg-danger">Suspended</span>' : ''}</td><td class="small">${escapeHtml(r.reason || '—')}</td><td><span class="badge ${r.status === 'open' ? 'bg-warning text-dark' : (r.status === 'reviewed' ? 'bg-success' : 'bg-secondary')}">${escapeHtml(r.status)}</span></td><td>` +
        `<button class="btn btn-sm btn-outline-info me-1 mb-1" onclick="viewConversation(${JSON.stringify(r.conversation_id)})">View</button>` +
        (r.status === 'open' ? `<button class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="resolveChatReport('${r.report_public_id}','dismissed')">Dismiss</button><button class="btn btn-sm btn-outline-success me-1 mb-1" onclick="resolveChatReport('${r.report_public_id}','reviewed')">Mark Reviewed</button>` : '') +
        (r.sender_id ? `<button class="btn btn-sm btn-outline-danger mb-1" onclick="toggleUserAccess(${r.sender_id}, '${r.sender_is_suspended ? 'unsuspend_user' : 'suspend_user'}')">${r.sender_is_suspended ? 'Unsuspend Sender' : 'Suspend Sender'}</button>` : '') +
        `</td></tr>`).join(''); }
    async function resolveChatReport(reportPublicId, resolution) { const res = await sendAction({ action: 'resolve_chat_report', report_public_id: reportPublicId, resolution }); if (res.status === 'success') loadChatMonitoring(); else alert(res.message || 'Unable to update report'); }
    async function searchChatConversations() { const q = document.getElementById('chat-search-input').value.trim(); const body = document.getElementById('chat-search-results-body'); if (!q) { body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Search to see results.</td></tr>'; return; } body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Searching…</td></tr>'; const res = await fetch(`../api/admin-api.php?action=search_conversations&q=${encodeURIComponent(q)}`); const data = await res.json(); if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="5" class="text-danger text-center py-3">${escapeHtml(data.message || 'Search failed')}</td></tr>`; return; } const conversations = data.data.conversations || []; if (!conversations.length) { body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">No matching conversations.</td></tr>'; return; } body.innerHTML = conversations.map(c => `<tr><td class="text-capitalize">${escapeHtml(c.type)}</td><td>${escapeHtml(c.ride_public_id || '—')}</td><td><span class="badge ${c.status === 'active' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(c.status)}</span></td><td class="small text-muted">${c.created_at ? new Date(c.created_at).toLocaleDateString() : ''}</td><td><button class="btn btn-sm btn-outline-info" onclick="viewConversation(${c.conversation_id})">View</button></td></tr>`).join(''); }
    async function viewConversation(conversationId) { const modalEl = document.getElementById('detailModal'); const titleEl = document.getElementById('detailModalLabel'); const bodyEl = document.getElementById('detailModalBody'); const footerEl = document.getElementById('detailModalFooter'); titleEl.textContent = 'Conversation'; bodyEl.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'; footerEl.innerHTML = ''; new bootstrap.Modal(modalEl).show(); const res = await fetch(`../api/admin-api.php?action=view_conversation&conversation_id=${conversationId}`); const data = await res.json(); if (data.status !== 'success') { bodyEl.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message || 'Unable to load conversation.')}</div>`; return; } const { conversation, members, messages } = data.data; titleEl.textContent = conversation.type === 'ride' ? `Ride chat — ${escapeHtml(conversation.start_location || '')} → ${escapeHtml(conversation.destination || '')}` : `${conversation.type.charAt(0).toUpperCase() + conversation.type.slice(1)} chat`;
        const memberList = members.map(m => `<span class="badge bg-light text-dark me-1">${escapeHtml(m.fullname)} (${escapeHtml(m.role)})${m.is_suspended ? ' 🚫' : ''}</span>`).join(' ');
        const thread = messages.map(m => `<div class="mb-2 pb-2 border-bottom"><div class="small text-muted">${m.sender_id ? escapeHtml(m.sender_name || 'User') : 'MohToh (system)'} · ${new Date(m.created_at).toLocaleString()}${m.flagged_for_review ? ' <span class="badge bg-warning text-dark">Flagged</span>' : ''}</div><div>${escapeHtml(m.message_type === 'location' ? '[Shared a location]' : m.message)}</div></div>`).join('') || '<div class="text-muted">No messages yet.</div>';
        bodyEl.innerHTML = `<div class="mb-3">${memberList}</div><div style="max-height:50vh;overflow-y:auto;">${thread}</div>`;
        footerEl.innerHTML = members.map(m => `<button class="btn btn-sm btn-outline-${m.is_suspended ? 'success' : 'danger'} me-2 mb-1" onclick="toggleUserAccess(${m.internal_id}, '${m.is_suspended ? 'unsuspend_user' : 'suspend_user'}'); viewConversation(${conversationId});">${m.is_suspended ? 'Unsuspend' : 'Suspend'} ${escapeHtml(m.fullname)}</button>`).join(''); }
    // ---- Community Growth (MohToh Community Rewards admin tab) ----
    let cgCurrentPage = 1;
    async function loadCommunityGrowthTab() { await Promise.all([loadReferralSettings(), loadReferralAnalytics(), loadReferralsList(1)]); }
    async function loadReferralSettings() {
        const res = await fetch('../api/referral-admin.php');
        const data = await res.json();
        if (data.status !== 'success') return;
        const s = data.data.settings;
        document.getElementById('rs-enabled').value = String(s.referral_system_enabled) === '0' ? '0' : '1';
        document.getElementById('rs-max-rewards').value = s.referral_max_rewards_per_user;
        document.getElementById('rs-expiry-days').value = s.referral_reward_expiry_days;
        document.getElementById('rs-rider-reward').value = s.referral_rider_reward_amount;
        document.getElementById('rs-new-rider-reward').value = s.referral_new_rider_reward_amount;
        document.getElementById('rs-driver-reward').value = s.referral_driver_reward_amount;
        document.getElementById('rs-new-driver-reward').value = s.referral_new_driver_reward_amount;
    }
    async function handleReferralSettingsSubmit(e) {
        e.preventDefault();
        const alertBox = document.getElementById('referral-settings-alert');
        alertBox.classList.add('d-none');
        const payload = { settings: {
            referral_system_enabled: document.getElementById('rs-enabled').value,
            referral_max_rewards_per_user: document.getElementById('rs-max-rewards').value,
            referral_reward_expiry_days: document.getElementById('rs-expiry-days').value,
            referral_rider_reward_amount: document.getElementById('rs-rider-reward').value,
            referral_new_rider_reward_amount: document.getElementById('rs-new-rider-reward').value,
            referral_driver_reward_amount: document.getElementById('rs-driver-reward').value,
            referral_new_driver_reward_amount: document.getElementById('rs-new-driver-reward').value,
        } };
        const res = await postJson('../api/referral-admin.php', payload);
        alertBox.className = `alert ${res.status === 'success' ? 'alert-success' : 'alert-danger'}`;
        alertBox.textContent = res.message || (res.status === 'success' ? 'Saved.' : 'Failed to save.');
    }
    async function loadReferralAnalytics() {
        const res = await fetch('../api/admin-api.php?action=referral_analytics');
        const data = await res.json();
        if (data.status !== 'success') return;
        const d = data.data;
        document.getElementById('cg-total').textContent = d.total_referrals;
        document.getElementById('cg-successful').textContent = d.successful_referrals;
        document.getElementById('cg-driver').textContent = d.driver_referrals;
        document.getElementById('cg-rider').textContent = d.rider_referrals;
        document.getElementById('cg-cancelled').textContent = d.cancelled_referrals;
        document.getElementById('cg-rewards').textContent = '₦' + Number(d.rewards_issued).toLocaleString();
        const body = document.getElementById('cg-top-builders-body');
        const builders = d.top_builders || [];
        body.innerHTML = builders.length
            ? builders.map(b => `<tr><td>${escapeHtml(b.fullname)} <span class="text-muted small">${escapeHtml(b.public_user_id || '')}</span></td><td class="text-capitalize">${escapeHtml(b.user_type)}</td><td>${b.total_referrals}</td><td>${b.successful_referrals}</td><td>₦${Number(b.total_rewards).toLocaleString()}</td></tr>`).join('')
            : '<tr><td colspan="5" class="text-muted text-center py-3">No community builders yet.</td></tr>';
    }
    const CG_STATUS_BADGE = { REGISTERED: 'bg-secondary', VERIFIED: 'bg-info', QUALIFIED: 'bg-primary', REWARDED: 'bg-success', CANCELLED: 'bg-danger' };
    async function loadReferralsList(page) {
        cgCurrentPage = page;
        const params = new URLSearchParams({ action: 'list_referrals', page, limit: 20 });
        const dateFrom = document.getElementById('cg-filter-date-from').value;
        const dateTo = document.getElementById('cg-filter-date-to').value;
        const userType = document.getElementById('cg-filter-type').value;
        const status = document.getElementById('cg-filter-status').value;
        if (dateFrom) params.set('date_from', dateFrom);
        if (dateTo) params.set('date_to', dateTo);
        if (userType) params.set('user_type', userType);
        if (status) params.set('status', status);

        const body = document.getElementById('cg-referrals-body');
        body.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/admin-api.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="5" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }

        const referrals = data.data.referrals || [];
        body.innerHTML = referrals.length
            ? referrals.map(r => `<tr><td>${escapeHtml(r.referrer_name)} <span class="text-muted small">${escapeHtml(r.referrer_public_id || '')}</span></td><td>${escapeHtml(r.referred_name)} <span class="text-muted small">${escapeHtml(r.referred_public_id || '')}</span></td><td class="text-capitalize">${escapeHtml(r.referral_type)}</td><td><span class="badge ${CG_STATUS_BADGE[r.status] || 'bg-secondary'}">${escapeHtml(r.status)}</span></td><td class="small text-muted">${r.registered_at ? new Date(r.registered_at).toLocaleDateString() : ''}</td></tr>`).join('')
            : '<tr><td colspan="5" class="text-muted text-center py-3">No referrals match these filters.</td></tr>';

        const total = data.data.total || 0;
        const totalPages = Math.max(1, Math.ceil(total / 20));
        document.getElementById('cg-pagination-info').textContent = `Page ${page} of ${totalPages} (${total} total)`;
        document.getElementById('cg-prev-page').disabled = page <= 1;
        document.getElementById('cg-next-page').disabled = page >= totalPages;
    }
    // ---- MOHTOH NETWORK (admin tab, Phase 3) ----
    function debounce(fn, delay) { let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); }; }
    const netLoadedSubtabs = new Set();
    function switchNetworkSubtab(name) {
        document.querySelectorAll('#net-subtabs .nav-link').forEach(l => l.classList.remove('active'));
        const navLink = document.querySelector(`#net-subtabs .nav-link[data-netsub="${name}"]`);
        if (navLink) navLink.classList.add('active');
        document.querySelectorAll('.net-subtab').forEach(el => el.classList.add('d-none'));
        const pane = document.getElementById(`net-sub-${name}`);
        if (pane) pane.classList.remove('d-none');
        if (!netLoadedSubtabs.has(name) && NET_SUBTAB_LOADERS[name]) {
            netLoadedSubtabs.add(name);
            NET_SUBTAB_LOADERS[name]();
        }
    }
    const NET_SUBTAB_LOADERS = {
        overview: loadNetworkOverview, settings: loadNetworkSettings,
        partners: () => loadNetworkPartners(1), referrals: () => loadNetworkReferrals(1),
        rewards: () => loadNetworkRewards(1), withdrawals: () => loadNetworkWithdrawals(1), fraud: () => loadNetworkFraud(1),
    };
    async function loadNetworkTab() { await loadNetworkOverview(); netLoadedSubtabs.add('overview'); }
    function netAlert(res) { showTabAlert('net-alert', res); }
    function netCanManage(perm) { return !!(window.ADMIN_CTX && (ADMIN_CTX.is_super || ADMIN_CTX.permissions.includes(perm))); }

    async function loadNetworkOverview() {
        const res = await fetch('../api/network-admin.php?action=overview');
        const data = await res.json();
        if (data.status !== 'success') return;
        const { partners, referrals, rewards, withdrawals, open_fraud_flags } = data.data;
        document.getElementById('net-ov-partners').textContent = partners.total_partners || 0;
        document.getElementById('net-ov-pending').textContent = partners.pending_partners || 0;
        document.getElementById('net-ov-active-referrals').textContent = referrals.active_referrals || 0;
        document.getElementById('net-ov-converted').textContent = referrals.converted_referrals || 0;
        document.getElementById('net-ov-fraud').textContent = open_fraud_flags || 0;
        document.getElementById('net-ov-withdrawn').textContent = '₦' + Number(withdrawals.total_paid).toLocaleString();
        document.getElementById('net-ov-generated').textContent = '₦' + Number(rewards.total_generated).toLocaleString();
        document.getElementById('net-ov-rpending').textContent = '₦' + Number(rewards.total_pending).toLocaleString();
        document.getElementById('net-ov-ravailable').textContent = '₦' + Number(rewards.total_available).toLocaleString();
        document.getElementById('net-ov-rreversed').textContent = '₦' + Number(rewards.total_reversed).toLocaleString();
        document.getElementById('net-ov-commission').textContent = '₦' + Number(rewards.referral_generated_commission).toLocaleString();
        document.getElementById('net-ov-generated2').textContent = '₦' + Number(rewards.total_generated).toLocaleString();
        document.getElementById('net-ov-net').textContent = '₦' + Number(rewards.referral_generated_commission - rewards.total_generated).toLocaleString();
    }

    // Settings — one flat map of <input id> -> system_settings key, same
    // shape the Community Growth form above uses, just larger. Only
    // rendered for Super Admins (the programme/financial-control fields),
    // so every lookup here is null-guarded.
    const NET_SETTING_FIELDS = {
        'net-enabled': 'network_enabled', 'net-registrations-enabled': 'network_registrations_enabled',
        'net-links-enabled': 'network_links_enabled', 'net-rewards-enabled': 'network_rewards_enabled',
        'net-withdrawals-enabled': 'network_withdrawals_enabled', 'net-driver-referrals-enabled': 'network_driver_referrals_enabled',
        'net-passenger-referrals-enabled': 'network_passenger_referrals_enabled', 'net-external-partners-enabled': 'network_external_partners_enabled',
        'net-maintenance-mode': 'network_maintenance_mode', 'net-emergency-payout-suspended': 'network_emergency_payout_suspended',
        'net-partner-auto-approve': 'network_partner_auto_approve', 'net-launch-at': 'network_launch_at', 'net-closes-at': 'network_closes_at',
        'net-driver-reward-pct': 'network_driver_reward_pct', 'net-passenger-reward-pct': 'network_passenger_reward_pct',
        'net-combined-ceiling-pct': 'network_combined_ceiling_pct', 'net-earning-days': 'network_earning_days',
        'net-min-withdrawal': 'network_min_withdrawal', 'net-registration-bonus': 'network_registration_bonus',
        'net-reward-hold-days': 'network_reward_hold_days', 'net-total-budget': 'network_total_budget',
        'net-daily-budget': 'network_daily_budget', 'net-monthly-budget': 'network_monthly_budget',
        'net-max-combined-payout-per-txn': 'network_max_combined_payout_per_txn', 'net-max-withdrawal-per-day': 'network_max_withdrawal_per_day',
        'net-max-withdrawal-per-month': 'network_max_withdrawal_per_month',
        'net-fraud-velocity-max': 'network_fraud_velocity_max_per_day', 'net-fraud-same-pair-max': 'network_fraud_same_pair_max_rewards',
        'net-announcement': 'network_announcement',
        'net-terms-version': 'network_terms_version', 'net-terms-text': 'network_terms_text',
    };
    async function loadNetworkSettings() {
        const res = await fetch('../api/network-admin.php?action=settings');
        const data = await res.json();
        if (data.status !== 'success') return;
        const s = data.data.settings;
        Object.entries(NET_SETTING_FIELDS).forEach(([elId, key]) => { const el = document.getElementById(elId); if (el) el.value = s[key] ?? ''; });
        await loadNetworkCampaigns();
    }
    async function handleNetworkSettingsSubmit(e) {
        e.preventDefault();
        const settings = {};
        Object.entries(NET_SETTING_FIELDS).forEach(([elId, key]) => { const el = document.getElementById(elId); if (el) settings[key] = el.value; });
        const res = await postJsonCsrf('../api/network-admin.php?action=update_settings', { settings });
        netAlert(res);
    }
    async function loadNetworkCampaigns() {
        const res = await fetch('../api/network-admin.php?action=campaigns');
        const data = await res.json();
        const body = document.getElementById('net-campaigns-body');
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const campaigns = data.data.campaigns || [];
        body.innerHTML = campaigns.length ? campaigns.map(c => `<tr>
            <td>${escapeHtml(c.name)}</td><td>${c.driver_reward_pct ?? '—'}</td><td>${c.passenger_reward_pct ?? '—'}</td><td>${c.combined_ceiling_pct ?? '—'}</td>
            <td class="small">${c.starts_at ? new Date(c.starts_at).toLocaleDateString() : '—'} → ${c.ends_at ? new Date(c.ends_at).toLocaleDateString() : '—'}</td>
            <td><span class="badge ${c.status === 'active' ? 'bg-success' : (['ended', 'cancelled'].includes(c.status) ? 'bg-secondary' : 'bg-info')}">${escapeHtml(c.status)}</span></td>
            <td>${!['ended', 'cancelled'].includes(c.status) ? `<button class="btn btn-sm btn-outline-danger" onclick="endNetworkCampaign(${c.id})">End</button>` : ''}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-muted text-center py-3">No campaigns yet.</td></tr>';
    }
    async function createNetworkCampaign() {
        const name = prompt('Campaign name:');
        if (!name) return;
        const driverPct = prompt('Driver reward % for this campaign (blank = use the default setting):', '') || '';
        const passengerPct = prompt('Passenger reward % for this campaign (blank = use the default setting):', '') || '';
        const startsAt = prompt('Start (YYYY-MM-DD HH:MM):');
        const endsAt = prompt('End (YYYY-MM-DD HH:MM):');
        if (!startsAt || !endsAt) { alert('Start and end are required.'); return; }
        const res = await postJsonCsrf('../api/network-admin.php?action=create_campaign', { name, driver_reward_pct: driverPct, passenger_reward_pct: passengerPct, starts_at: startsAt, ends_at: endsAt });
        if (res.status === 'success') loadNetworkCampaigns(); else alert(res.message || 'Failed to create campaign');
    }
    async function endNetworkCampaign(id) {
        if (!confirm('End this campaign now?')) return;
        const res = await postJsonCsrf('../api/network-admin.php?action=end_campaign', { campaign_id: id });
        if (res.status === 'success') loadNetworkCampaigns(); else alert(res.message || 'Failed to end campaign');
    }

    // Partners
    const NET_PARTNER_BADGE = { pending: 'bg-warning text-dark', approved: 'bg-success', suspended: 'bg-danger', rejected: 'bg-secondary' };
    function netPartnerActionButtons(p) {
        if (p.status === 'pending') return `<button class="btn btn-sm btn-outline-success mb-1" onclick="approveNetworkPartner(${p.id})">Approve</button>`;
        if (p.status === 'approved') return `<button class="btn btn-sm btn-outline-danger mb-1" onclick="suspendNetworkPartner(${p.id})">Suspend</button>`;
        if (p.status === 'suspended') return `<button class="btn btn-sm btn-outline-success mb-1" onclick="reactivateNetworkPartner(${p.id})">Reactivate</button>`;
        return '';
    }
    async function loadNetworkPartners(page) {
        const params = new URLSearchParams({ action: 'partners', page, limit: 20 });
        const q = document.getElementById('net-p-q').value.trim();
        const status = document.getElementById('net-p-status').value;
        if (q) params.set('q', q);
        if (status) params.set('status', status);
        const body = document.getElementById('net-partners-body');
        body.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/network-admin.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const partners = data.data.partners || [];
        const canManage = netCanManage('network.partners.manage');
        body.innerHTML = partners.length ? partners.map(p => `<tr>
            <td>${escapeHtml(p.fullname)} <span class="text-muted small">${escapeHtml(p.public_user_id || '')}</span><br><span class="text-muted small text-capitalize">${escapeHtml(p.user_type)}</span></td>
            <td><code>${escapeHtml(p.referral_code)}</code></td>
            <td><span class="badge ${NET_PARTNER_BADGE[p.status] || 'bg-secondary'}">${escapeHtml(p.status)}</span>${p.status === 'suspended' && p.suspension_reason ? `<div class="small text-muted">${escapeHtml(p.suspension_reason)}</div>` : ''}</td>
            <td>${p.total_referrals}</td>
            <td>₦${Number(p.lifetime_rewards).toLocaleString()}</td>
            <td class="small text-muted">${p.joined_at ? new Date(p.joined_at).toLocaleDateString() : ''}</td>
            <td>${canManage ? netPartnerActionButtons(p) : ''}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-muted text-center py-3">No partners match these filters.</td></tr>';
        renderPagination('net-partners-pagination', data.pagination, loadNetworkPartners);
    }
    async function approveNetworkPartner(id) { const res = await postJsonCsrf('../api/network-admin.php?action=approve_partner', { partner_id: id }); if (res.status === 'success') loadNetworkPartners(1); else alert(res.message || 'Failed'); }
    async function suspendNetworkPartner(id) { const reason = prompt('Reason for suspension (required, shown to the partner):'); if (!reason) return; const res = await postJsonCsrf('../api/network-admin.php?action=suspend_partner', { partner_id: id, reason }); if (res.status === 'success') loadNetworkPartners(1); else alert(res.message || 'Failed'); }
    async function reactivateNetworkPartner(id) { const res = await postJsonCsrf('../api/network-admin.php?action=reactivate_partner', { partner_id: id }); if (res.status === 'success') loadNetworkPartners(1); else alert(res.message || 'Failed'); }

    // Referrals
    const NET_REFERRAL_BADGE = { registered: 'bg-secondary', verified: 'bg-info', active: 'bg-success', expired: 'bg-dark', cancelled: 'bg-danger', legacy_migrated: 'bg-light text-dark' };
    async function loadNetworkReferrals(page) {
        const params = new URLSearchParams({ action: 'referrals', page, limit: 20 });
        const q = document.getElementById('net-r-q').value.trim();
        const category = document.getElementById('net-r-category').value;
        const status = document.getElementById('net-r-status').value;
        if (q) params.set('q', q);
        if (category) params.set('category', category);
        if (status) params.set('status', status);
        const body = document.getElementById('net-referrals-body');
        body.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/network-admin.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="8" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const referrals = data.data.referrals || [];
        const canManage = netCanManage('network.partners.manage');
        body.innerHTML = referrals.length ? referrals.map(r => `<tr>
            <td>${escapeHtml(r.referrer_name)} <span class="text-muted small">${escapeHtml(r.referrer_public_id || '')}</span></td>
            <td>${escapeHtml(r.referred_name)} <span class="text-muted small">${escapeHtml(r.referred_public_id || '')}</span></td>
            <td class="text-capitalize">${escapeHtml(r.referred_category)}</td>
            <td><span class="badge ${NET_REFERRAL_BADGE[r.status] || 'bg-secondary'}">${escapeHtml(r.status.replace('_', ' '))}</span></td>
            <td class="small">${r.earning_starts_at ? (new Date(r.earning_starts_at).toLocaleDateString() + ' → ' + new Date(r.earning_expires_at).toLocaleDateString()) : '—'}</td>
            <td>${r.qualifying_ride_count}</td>
            <td>₦${Number(r.total_rewards_generated).toLocaleString()}</td>
            <td>${canManage && !r.attribution_locked_at ? `<button class="btn btn-sm btn-outline-secondary mb-1" onclick="correctNetworkAttribution(${r.id})">Reassign</button>` : ''}</td>
        </tr>`).join('') : '<tr><td colspan="8" class="text-muted text-center py-3">No referrals match these filters.</td></tr>';
        renderPagination('net-referrals-pagination', data.pagination, loadNetworkReferrals);
    }
    async function correctNetworkAttribution(referralId) {
        const newReferrerUserId = prompt('New referrer — their internal user ID (see the Partners/Passengers/Drivers tabs):');
        if (!newReferrerUserId) return;
        const reason = prompt('Reason for this correction (required, audited):');
        if (!reason) return;
        const res = await postJsonCsrf('../api/network-admin.php?action=correct_attribution', { network_referral_id: referralId, new_referrer_user_id: newReferrerUserId, reason });
        if (res.status === 'success') loadNetworkReferrals(1); else alert(res.message || 'Failed');
    }

    // Rewards
    const NET_REWARD_BADGE = { pending: 'bg-warning text-dark', available: 'bg-success', withdrawn: 'bg-primary', reversed: 'bg-danger', suspended: 'bg-secondary' };
    async function loadNetworkRewards(page) {
        const params = new URLSearchParams({ action: 'reward_ledger', page, limit: 20 });
        const status = document.getElementById('net-rw-status').value;
        if (status) params.set('status', status);
        const body = document.getElementById('net-rewards-body');
        body.innerHTML = '<tr><td colspan="8" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/network-admin.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="8" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const rows = data.data.ledger || [];
        const canManage = netCanManage('network.finance.manage');
        body.innerHTML = rows.length ? rows.map(r => `<tr>
            <td>${escapeHtml(r.beneficiary_name)} <span class="text-muted small">${escapeHtml(r.beneficiary_public_id || '')}</span></td>
            <td class="small">${r.beneficiary_role === 'driver_referrer' ? 'Driver referrer' : 'Passenger referrer'}</td>
            <td>₦${Number(r.commission_base).toLocaleString()}</td>
            <td>${r.reward_pct}%</td>
            <td>₦${Number(r.reward_amount).toLocaleString()}</td>
            <td><span class="badge ${NET_REWARD_BADGE[r.status] || 'bg-secondary'}">${escapeHtml(r.status)}</span>${r.reversal_reason ? `<div class="small text-muted">${escapeHtml(r.reversal_reason)}</div>` : ''}</td>
            <td class="small text-muted">${r.created_at ? new Date(r.created_at).toLocaleDateString() : ''}</td>
            <td>${canManage && !['reversed', 'withdrawn'].includes(r.status) ? `<button class="btn btn-sm btn-outline-danger mb-1" onclick="reverseNetworkReward(${r.id})">Reverse</button>` : ''}</td>
        </tr>`).join('') : '<tr><td colspan="8" class="text-muted text-center py-3">No rewards match these filters.</td></tr>';
        renderPagination('net-rewards-pagination', data.pagination, loadNetworkRewards);
    }
    async function reverseNetworkReward(ledgerId) {
        const reason = prompt('Reason for reversing this reward (required, audited):');
        if (!reason) return;
        const res = await postJsonCsrf('../api/network-admin.php?action=reverse_reward', { ledger_id: ledgerId, reason });
        if (res.status === 'success') loadNetworkRewards(1); else alert(res.message || 'Failed');
    }

    // Withdrawals
    const NET_WITHDRAWAL_BADGE = { pending: 'bg-warning text-dark', under_review: 'bg-info', approved: 'bg-info', processing: 'bg-info', paid: 'bg-success', rejected: 'bg-danger', failed: 'bg-danger', reversed: 'bg-secondary' };
    function netWithdrawalActionButtons(w) {
        if (['pending', 'under_review'].includes(w.status)) {
            return `<button class="btn btn-sm btn-outline-success me-1 mb-1" onclick="decideNetworkWithdrawal(${w.id},'approve')">Approve</button><button class="btn btn-sm btn-outline-danger mb-1" onclick="decideNetworkWithdrawal(${w.id},'reject')">Reject</button>`;
        }
        if (['approved', 'processing'].includes(w.status)) {
            return `<button class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="decideNetworkWithdrawal(${w.id},'paid')">Mark Paid</button><button class="btn btn-sm btn-outline-danger mb-1" onclick="decideNetworkWithdrawal(${w.id},'failed')">Mark Failed</button>`;
        }
        return '';
    }
    async function loadNetworkWithdrawals(page) {
        const params = new URLSearchParams({ action: 'withdrawals', page, limit: 20 });
        const status = document.getElementById('net-w-status').value;
        if (status) params.set('status', status);
        const body = document.getElementById('net-withdrawals-body');
        body.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/network-admin.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const rows = data.data.withdrawals || [];
        const canManage = netCanManage('network.finance.manage');
        body.innerHTML = rows.length ? rows.map(w => `<tr>
            <td>${escapeHtml(w.user_name)} <span class="text-muted small">${escapeHtml(w.public_user_id || '')}</span></td>
            <td>₦${Number(w.amount).toLocaleString()}</td>
            <td class="small">${escapeHtml(w.bank_name)} ••${escapeHtml(w.account_number_last4)}<br>${escapeHtml(w.account_name)}</td>
            <td><span class="badge ${NET_WITHDRAWAL_BADGE[w.status] || 'bg-secondary'}">${escapeHtml(w.status.replace('_', ' '))}</span></td>
            <td class="small text-muted">${w.requested_at ? new Date(w.requested_at).toLocaleString() : ''}</td>
            <td class="small">${escapeHtml(w.payment_reference || w.rejection_reason || '—')}</td>
            <td>${canManage ? netWithdrawalActionButtons(w) : ''}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-muted text-center py-3">No withdrawals match these filters.</td></tr>';
        renderPagination('net-withdrawals-pagination', data.pagination, loadNetworkWithdrawals);
    }
    async function decideNetworkWithdrawal(id, decision) {
        let note = null;
        if (decision === 'paid') { note = prompt('Payment reference (required — proof the transfer was made):'); if (!note) return; }
        else if (decision === 'reject' || decision === 'failed') { note = prompt('Reason (required, audited):'); if (!note) return; }
        const res = await postJsonCsrf('../api/network-admin.php?action=decide_withdrawal', { withdrawal_id: id, decision, note });
        if (res.status === 'success') loadNetworkWithdrawals(1); else alert(res.message || 'Failed');
    }

    // Fraud
    const NET_FRAUD_BADGE = { open: 'bg-danger', reviewing: 'bg-warning text-dark', resolved: 'bg-success', dismissed: 'bg-secondary' };
    function netFraudActionButtons(id) {
        return `<button class="btn btn-sm btn-outline-success me-1 mb-1" onclick="resolveNetworkFraudFlag(${id},'resolved')">Resolve</button><button class="btn btn-sm btn-outline-secondary mb-1" onclick="resolveNetworkFraudFlag(${id},'dismissed')">Dismiss</button>`;
    }
    async function loadNetworkFraud(page) {
        const params = new URLSearchParams({ action: 'fraud_flags', page, limit: 20 });
        const status = document.getElementById('net-f-status').value;
        if (status) params.set('status', status);
        const body = document.getElementById('net-fraud-body');
        body.innerHTML = '<tr><td colspan="7" class="text-muted text-center py-3">Loading…</td></tr>';
        const res = await fetch(`../api/network-admin.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') { body.innerHTML = `<tr><td colspan="7" class="text-danger text-center py-3">${escapeHtml(data.message || 'Failed to load')}</td></tr>`; return; }
        const rows = data.data.flags || [];
        const canManage = netCanManage('network.finance.manage');
        body.innerHTML = rows.length ? rows.map(f => `<tr>
            <td>${escapeHtml(f.user_name)} <span class="text-muted small">${escapeHtml(f.public_user_id || '')}</span></td>
            <td class="small text-capitalize">${escapeHtml((f.flag_type || '').replace(/_/g, ' '))}</td>
            <td><span class="badge bg-${f.severity === 'high' ? 'danger' : (f.severity === 'medium' ? 'warning text-dark' : 'secondary')}">${escapeHtml(f.severity)}</span></td>
            <td class="small">${escapeHtml(f.details || '—')}</td>
            <td><span class="badge ${NET_FRAUD_BADGE[f.status] || 'bg-secondary'}">${escapeHtml(f.status)}</span>${f.resolution_notes ? `<div class="small text-muted">${escapeHtml(f.resolution_notes)}</div>` : ''}</td>
            <td class="small text-muted">${f.created_at ? new Date(f.created_at).toLocaleDateString() : ''}</td>
            <td>${canManage && ['open', 'reviewing'].includes(f.status) ? netFraudActionButtons(f.id) : ''}</td>
        </tr>`).join('') : '<tr><td colspan="7" class="text-muted text-center py-3">No fraud flags.</td></tr>';
        renderPagination('net-fraud-pagination', data.pagination, loadNetworkFraud);
    }
    async function resolveNetworkFraudFlag(id, status) {
        const notes = prompt('Resolution notes:') || '';
        const res = await postJsonCsrf('../api/network-admin.php?action=resolve_fraud_flag', { flag_id: id, status, notes });
        if (res.status === 'success') loadNetworkFraud(1); else alert(res.message || 'Failed');
    }

    // ---- CSV export (spec 13: "downloadable reports") — generic,
    // reused across every Network list rather than one-off per table.
    function downloadCsv(filename, headers, rows) {
        // CSV/formula-injection guard (OWASP): a cell whose value starts
        // with =, +, -, @ (or a tab/CR) is interpreted as a formula by
        // Excel/Sheets when the file is opened — a malicious bank name,
        // account name, or partner fullname could otherwise execute code
        // or exfiltrate data on whoever opens the export. Prefixing with
        // a single quote forces text interpretation without changing the
        // visible value for any legitimate (non-formula-looking) input.
        const neutralizeFormula = (v) => /^[=+\-@\t\r]/.test(v) ? "'" + v : v;
        const esc = (v) => '"' + neutralizeFormula(String(v ?? '')).replace(/"/g, '""') + '"';
        const csv = [headers.map(esc).join(',')].concat(rows.map(r => r.map(esc).join(','))).join('\r\n');
        const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = filename;
        link.click();
        URL.revokeObjectURL(link.href);
    }
    async function exportNetworkPartnersCsv() {
        const params = new URLSearchParams({ action: 'partners', page: 1, limit: 1000 });
        const q = document.getElementById('net-p-q').value.trim(); if (q) params.set('q', q);
        const status = document.getElementById('net-p-status').value; if (status) params.set('status', status);
        const res = await fetch(`../api/network-admin.php?${params}`); const data = await res.json();
        if (data.status !== 'success') { alert(data.message || 'Export failed'); return; }
        downloadCsv('mohtoh-network-partners.csv', ['Name', 'Public ID', 'Type', 'Code', 'Status', 'Total Referrals', 'Lifetime Rewards', 'Joined'],
            (data.data.partners || []).map(p => [p.fullname, p.public_user_id, p.user_type, p.referral_code, p.status, p.total_referrals, p.lifetime_rewards, p.joined_at]));
    }
    async function exportNetworkReferralsCsv() {
        const params = new URLSearchParams({ action: 'referrals', page: 1, limit: 1000 });
        const q = document.getElementById('net-r-q').value.trim(); if (q) params.set('q', q);
        const category = document.getElementById('net-r-category').value; if (category) params.set('category', category);
        const status = document.getElementById('net-r-status').value; if (status) params.set('status', status);
        const res = await fetch(`../api/network-admin.php?${params}`); const data = await res.json();
        if (data.status !== 'success') { alert(data.message || 'Export failed'); return; }
        downloadCsv('mohtoh-network-referrals.csv', ['Referrer', 'Referred', 'Category', 'Status', 'Registered', 'Earning Starts', 'Earning Expires', 'Qualifying Rides', 'Commission Generated', 'Rewards Generated'],
            (data.data.referrals || []).map(r => [r.referrer_name, r.referred_name, r.referred_category, r.status, r.registered_at, r.earning_starts_at, r.earning_expires_at, r.qualifying_ride_count, r.total_commission_generated, r.total_rewards_generated]));
    }
    async function exportNetworkRewardsCsv() {
        const params = new URLSearchParams({ action: 'reward_ledger', page: 1, limit: 1000 });
        const status = document.getElementById('net-rw-status').value; if (status) params.set('status', status);
        const res = await fetch(`../api/network-admin.php?${params}`); const data = await res.json();
        if (data.status !== 'success') { alert(data.message || 'Export failed'); return; }
        downloadCsv('mohtoh-network-rewards.csv', ['Beneficiary', 'Role', 'Commission Base', 'Pct', 'Reward Amount', 'Status', 'Created'],
            (data.data.ledger || []).map(r => [r.beneficiary_name, r.beneficiary_role, r.commission_base, r.reward_pct, r.reward_amount, r.status, r.created_at]));
    }
    async function exportNetworkWithdrawalsCsv() {
        const params = new URLSearchParams({ action: 'withdrawals', page: 1, limit: 1000 });
        const status = document.getElementById('net-w-status').value; if (status) params.set('status', status);
        const res = await fetch(`../api/network-admin.php?${params}`); const data = await res.json();
        if (data.status !== 'success') { alert(data.message || 'Export failed'); return; }
        downloadCsv('mohtoh-network-withdrawals.csv', ['Partner', 'Amount', 'Bank', 'Account Last4', 'Status', 'Requested', 'Reference'],
            (data.data.withdrawals || []).map(w => [w.user_name, w.amount, w.bank_name, w.account_number_last4, w.status, w.requested_at, w.payment_reference]));
    }

    // Load the Google Maps JS API once, using the browser key served by the
    // session-guarded api/maps-key.php (which reads it from the Integration
    // Center). No key is hardcoded here. Resolves to google.maps, or rejects
    // if the key is absent / the network fails (the map card stays empty).
    let __adminMapsPromise = null;
    function loadGoogleMaps() {
        if (__adminMapsPromise) return __adminMapsPromise;
        __adminMapsPromise = (async () => {
            if (window.google && window.google.maps && window.google.maps.Map) return window.google.maps;
            let key;
            try {
                const res = await fetch('../api/maps-key.php');
                const json = await res.json();
                key = json && json.key;
            } catch (_) { /* fall through */ }
            if (!key) throw new Error('no-maps-key');
            await new Promise((resolve, reject) => {
                const cb = '__adminMapsReady' + Date.now();
                window[cb] = () => { resolve(); delete window[cb]; };
                const s = document.createElement('script');
                s.src = 'https://maps.googleapis.com/maps/api/js?key=' + encodeURIComponent(key) + '&libraries=marker&callback=' + cb;
                s.async = true;
                s.onerror = () => { reject(new Error('maps-load-fail')); delete window[cb]; };
                document.head.appendChild(s);
            });
            return window.google.maps;
        })();
        __adminMapsPromise.catch(() => { __adminMapsPromise = null; });
        return __adminMapsPromise;
    }
    async function initMap() {
        let g;
        try { g = await loadGoogleMaps(); }
        catch (err) { console.warn('Admin map unavailable:', err && err.message); return; }
        map = new g.Map(document.getElementById('admin-map'), {
            center: { lat: 6.5244, lng: 3.3792 }, zoom: 10,
            mapId: 'DEMO_MAP_ID', streetViewControl: false, gestureHandling: 'greedy'
        });
        // A dashboard refresh may have loaded online users before the map was
        // ready — draw whatever we have now.
        if (dashboardData.online_users) renderMap(dashboardData.online_users);
    }
    async function loadDashboardData() { try { const res = await fetch('../api/admin-api.php?action=get_dashboard_data'); const data = await res.json(); if (data.status !== 'success') throw new Error(data.message || 'Failed'); dashboardData = data.data || {}; renderDashboard(); } catch (err) { console.error(err); } }
    function renderDashboard() { const stats = dashboardData.stats || {}; const reports = dashboardData.reports || {}; const statEls = { users: document.getElementById('stat-users'), drivers: document.getElementById('stat-drivers'), passengers: document.getElementById('stat-passengers'), activeRides: document.getElementById('stat-active-rides'), mohtohRides: document.getElementById('stat-mohtoh-rides'), pendingVehicles: document.getElementById('stat-pending-vehicles') }; if (statEls.users) statEls.users.textContent = stats.total_users || 0; if (statEls.drivers) statEls.drivers.textContent = stats.total_drivers || 0; if (statEls.passengers) statEls.passengers.textContent = stats.total_riders || 0; if (statEls.activeRides) statEls.activeRides.textContent = stats.active_rides || 0; if (statEls.mohtohRides) statEls.mohtohRides.textContent = stats.mohtoh_rides_total || 0; if (statEls.pendingVehicles) statEls.pendingVehicles.textContent = stats.pending_vehicle_reviews || 0; const pendingLocationsEl = document.getElementById('stat-pending-locations'); if (pendingLocationsEl) pendingLocationsEl.textContent = stats.pending_locations || 0; const reportEls = { activeMohtoh: document.getElementById('report-active-mohtoh'), completedMohtoh: document.getElementById('report-completed-mohtoh'), confirmedBookings: document.getElementById('report-confirmed-bookings'), cancelledBookings: document.getElementById('report-cancelled-bookings') }; if (reportEls.activeMohtoh) reportEls.activeMohtoh.textContent = reports.active_mohtoh_rides || 0; if (reportEls.completedMohtoh) reportEls.completedMohtoh.textContent = reports.completed_mohtoh_rides || 0; if (reportEls.confirmedBookings) reportEls.confirmedBookings.textContent = reports.bookings_confirmed || 0; if (reportEls.cancelledBookings) reportEls.cancelledBookings.textContent = reports.bookings_cancelled || 0; renderRecentUsers(Array.isArray(dashboardData.users) ? dashboardData.users : []); renderRecentRides(Array.isArray(dashboardData.rides) ? dashboardData.rides : []); renderDrivers(Array.isArray(dashboardData.drivers) ? dashboardData.drivers : []); renderVehicles(Array.isArray(dashboardData.vehicles) ? dashboardData.vehicles : []); renderLocations(Array.isArray(dashboardData.locations) ? dashboardData.locations : []); renderPassengers(Array.isArray(dashboardData.passengers) ? dashboardData.passengers : []); renderRides(Array.isArray(dashboardData.mohtoh_rides) ? dashboardData.mohtoh_rides : []); renderBookings(Array.isArray(dashboardData.bookings) ? dashboardData.bookings : []); renderMap(Array.isArray(dashboardData.online_users) ? dashboardData.online_users : []); }
    function renderRecentUsers(users){ const body=document.getElementById('recent-users-body'); if(!body) return; if(!users.length){body.innerHTML='<tr><td colspan="3" class="text-muted">No users yet.</td></tr>';return;} body.innerHTML=users.slice(0,6).map(user=>`<tr><td>${escapeHtml(user.fullname||'Unknown')}</td><td>${escapeHtml(user.user_type||'')}</td><td>${user.created_at ? new Date(user.created_at).toLocaleDateString() : 'N/A'}</td></tr>`).join(''); }
    function renderRecentRides(rides){ const body=document.getElementById('recent-rides-body'); if(!body) return; if(!rides.length){body.innerHTML='<tr><td colspan="3" class="text-muted">No rides yet.</td></tr>';return;} body.innerHTML=rides.slice(0,6).map(ride=>`<tr><td>#${ride.id}</td><td>${escapeHtml(ride.destination||'N/A')}</td><td><span class="badge bg-secondary">${escapeHtml(ride.status||'unknown')}</span></td></tr>`).join(''); }
    function isLockedNow(user){ return !!(user.lockout_until && new Date(user.lockout_until.replace(' ', 'T')) > new Date()); }
    function verificationCell(user){ const emailOk=!!Number(user.email_verified); const phoneOk=!!Number(user.phone_verified); const locked=isLockedNow(user); return `<div class="d-flex flex-column gap-1"><span class="badge ${emailOk?'bg-success':'bg-secondary'}"><i class="fas fa-envelope me-1"></i>${emailOk?'Email verified':'Email unverified'}</span><span class="badge ${phoneOk?'bg-success':'bg-secondary'}"><i class="fas fa-phone me-1"></i>${phoneOk?'Phone verified':'Phone unverified'}</span>${locked?'<span class="badge bg-danger"><i class="fas fa-lock me-1"></i>Locked</span>':''}</div>`; }
    function verificationActions(user){ const buttons=[]; if(!Number(user.email_verified)) buttons.push(`<button class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="resendVerification(${user.id}, 'email')">Resend Email</button>`); if(!Number(user.phone_verified)) buttons.push(`<button class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="resendVerification(${user.id}, 'phone')">Resend OTP</button>`); if(isLockedNow(user)) buttons.push(`<button class="btn btn-sm btn-outline-dark me-1 mb-1" onclick="unlockLoginLockout(${user.id})">Unlock</button>`); return buttons.join(''); }
    function renderDrivers(drivers){ const body=document.getElementById('drivers-body'); if(!body) return; if(!drivers.length){body.innerHTML='<tr><td colspan="7" class="text-muted">No drivers available.</td></tr>';document.getElementById('drivers-pagination').innerHTML='';return;} paginateClientSide('drivers', drivers, (pageDrivers) => { body.innerHTML=pageDrivers.map(driver=>`<tr><td class="small text-muted">${escapeHtml(driver.user_id||'N/A')}</td><td>${escapeHtml(driver.fullname||'Unknown')}</td><td>${escapeHtml(driver.email||'N/A')}</td><td>${parseFloat(driver.rating||0).toFixed(2)}</td><td>${verificationCell(driver)}</td><td>${driver.is_suspended?'<span class="badge bg-danger">Suspended</span>':(driver.is_verified?'<span class="badge bg-success">Verified</span>':'<span class="badge bg-warning text-dark">Pending</span>')} ${driver.driver_tier==='premium'?'<span class="badge bg-warning text-dark">Premium</span>':''}</td><td><button class="btn btn-sm btn-outline-info me-2 mb-1" onclick="openUserModal('driver', ${driver.id}, ${driver.is_suspended?1:0}, ${driver.is_verified?1:0})">View</button>${driver.is_verified?'':`<button class="btn btn-sm btn-outline-primary me-2 mb-1" onclick="approveDriver(${driver.id})">Approve</button>`}<button class="btn btn-sm btn-outline-${driver.is_suspended?'success':'danger'} me-2 mb-1" onclick="toggleUserAccess(${driver.id}, '${driver.is_suspended?'unsuspend_user':'suspend_user'}')">${driver.is_suspended?'Unsuspend':'Suspend'}</button><button class="btn btn-sm btn-outline-warning me-2 mb-1" onclick="setDriverTier(${driver.id}, '${driver.driver_tier==='premium'?'standard':'premium'}')">${driver.driver_tier==='premium'?'Make Standard':'Make Premium'}</button>${verificationActions(driver)}</td></tr>`).join(''); }); }
    function renderLocations(locations){ const body=document.getElementById('locations-body'); if(!body) return; if(!locations.length){body.innerHTML='<tr><td colspan="6" class="text-muted">No suggested locations yet.</td></tr>';return;} body.innerHTML=locations.map(loc=>`<tr><td>${escapeHtml(loc.name||'Unnamed')}</td><td class="text-capitalize">${escapeHtml((loc.type||'').replace('_',' '))}</td><td class="small text-muted">${escapeHtml(loc.address||`${loc.latitude}, ${loc.longitude}`)}</td><td>${escapeHtml(loc.created_by_name||'Unknown')} <span class="badge bg-light text-dark text-capitalize">${escapeHtml(loc.created_by_type||'')}</span></td><td><span class="badge ${loc.status==='approved'?'bg-success':loc.status==='rejected'?'bg-danger':'bg-warning text-dark'}">${escapeHtml(loc.status||'pending')}</span></td><td>${loc.status==='pending'?`<button class="btn btn-sm btn-outline-success me-2" onclick="approveLocation(${loc.id})">Approve</button><button class="btn btn-sm btn-outline-danger" onclick="rejectLocation(${loc.id})">Reject</button>`:'<span class="text-muted small">Reviewed</span>'}</td></tr>`).join(''); }
    async function approveLocation(locationId){ const res=await sendAction({action:'approve_location',location_id:locationId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to approve location'); }
    async function rejectLocation(locationId){ const res=await sendAction({action:'reject_location',location_id:locationId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to reject location'); }
    function renderPassengers(passengers){ const body=document.getElementById('passengers-body'); if(!body) return; if(!passengers.length){body.innerHTML='<tr><td colspan="7" class="text-muted">No passengers available.</td></tr>';document.getElementById('passengers-pagination').innerHTML='';return;} paginateClientSide('passengers', passengers, (pagePassengers) => { body.innerHTML=pagePassengers.map(passenger=>`<tr><td class="small text-muted">${escapeHtml(passenger.user_id||'N/A')}</td><td>${escapeHtml(passenger.fullname||'Unknown')}</td><td>${escapeHtml(passenger.email||'N/A')}</td><td>${parseFloat(passenger.rating||0).toFixed(2)}</td><td>${verificationCell(passenger)}</td><td>${passenger.is_suspended?'<span class="badge bg-danger">Suspended</span>':'<span class="badge bg-success">Active</span>'}</td><td><button class="btn btn-sm btn-outline-info me-2 mb-1" onclick="openUserModal('passenger', ${passenger.id}, ${passenger.is_suspended?1:0}, 0)">View</button><button class="btn btn-sm btn-outline-${passenger.is_suspended?'success':'danger'} me-2 mb-1" onclick="toggleUserAccess(${passenger.id}, '${passenger.is_suspended?'unsuspend_user':'suspend_user'}')">${passenger.is_suspended?'Unsuspend':'Suspend'}</button>${verificationActions(passenger)}</td></tr>`).join(''); }); }
    function renderVehicles(vehicles){ const body=document.getElementById('vehicles-body'); if(!body) return; if(!vehicles.length){body.innerHTML='<tr><td colspan="7" class="text-muted">No vehicles pending review.</td></tr>';document.getElementById('vehicles-pagination').innerHTML='';return;} paginateClientSide('vehicles', vehicles, (pageVehicles) => { body.innerHTML=pageVehicles.map(vehicle=>`<tr><td>${escapeHtml(vehicle.driver_name||'Unknown')}</td><td>${escapeHtml(vehicle.vehicle_name||'N/A')}</td><td>${escapeHtml(vehicle.vehicle_type_label||vehicle.vehicle_type||'N/A')}</td><td><span class="badge ${vehicle.status==='approved'?'bg-success':vehicle.status==='rejected'?'bg-danger':'bg-warning text-dark'}">${escapeHtml(vehicle.status||'pending')}</span></td><td>${vehicle.photo_count||0}</td><td>${vehicle.document_count||0}</td><td><button class="btn btn-sm btn-outline-info me-2" onclick="openVehicleModal(${vehicle.id}, '${vehicle.status||'pending'}')">View</button>${vehicle.status==='approved'?'':`<button class="btn btn-sm btn-outline-success me-2" onclick="approveVehicle(${vehicle.id})">Approve</button><button class="btn btn-sm btn-outline-danger" onclick="rejectVehicle(${vehicle.id})">Reject</button>`}<button class="btn btn-sm btn-outline-danger me-2" onclick="deleteVehicle(${vehicle.id})"><i class="fas fa-trash"></i></button></td></tr>`).join(''); }); }
    function renderRides(rides){ const body=document.getElementById('rides-body'); if(!body) return; if(!rides.length){body.innerHTML='<tr><td colspan="6" class="text-muted">No ride sessions yet.</td></tr>';document.getElementById('rides-pagination').innerHTML='';return;} paginateClientSide('rides', rides, (pageRides) => { body.innerHTML=pageRides.map(ride=>`<tr><td>${escapeHtml(ride.destination||'N/A')}</td><td>${escapeHtml(ride.driver_name||'N/A')}</td><td>${ride.departure_date||'N/A'}</td><td>${ride.available_seats||0}/${ride.total_seats||0}</td><td><span class="badge bg-secondary">${escapeHtml(ride.status||'unknown')}</span></td><td><button class="btn btn-sm btn-outline-warning me-2" onclick="cancelRide(${ride.id})">Cancel</button><button class="btn btn-sm btn-outline-success me-2" onclick="completeRide(${ride.id})">Complete</button><button class="btn btn-sm btn-outline-danger" onclick="removeRide(${ride.id})">Remove</button></td></tr>`).join(''); }); }
    function renderBookings(bookings){ const body=document.getElementById('bookings-body'); if(!body) return; if(!bookings.length){body.innerHTML='<tr><td colspan="5" class="text-muted">No bookings yet.</td></tr>';document.getElementById('bookings-pagination').innerHTML='';return;} paginateClientSide('bookings', bookings, (pageBookings) => { body.innerHTML=pageBookings.map(booking=>`<tr><td>#${booking.ride_public_id || booking.ride_share_id}</td><td>${escapeHtml(booking.rider_name||'N/A')}</td><td>${booking.seats_booked||1}</td><td><span class="badge bg-secondary">${escapeHtml(booking.status||'unknown')}</span></td><td><button class="btn btn-sm btn-outline-danger" onclick="cancelBooking(${booking.id})">Cancel</button></td></tr>`).join(''); }); }
    function renderMap(users){ if (!map || !(window.google && google.maps)) return; const g=google.maps; adminMarkers.forEach(m=>{ if('map' in m) m.map=null; else if(m.setMap) m.setMap(null); }); adminMarkers=[]; const bounds=new g.LatLngBounds(); let count=0; const info=new g.InfoWindow(); users.forEach(user=>{ if(user.latitude && user.longitude){ const pos={lat:parseFloat(user.latitude),lng:parseFloat(user.longitude)}; const color=user.user_type==='driver'?'#f59e0b':'#2563eb'; let marker; if(g.marker && typeof g.marker.AdvancedMarkerElement==='function'){ const dot=document.createElement('div'); dot.style.cssText=`background:${color};width:14px;height:14px;border-radius:50%;border:2px solid white;box-shadow:0 0 0 1px rgba(0,0,0,.15);`; marker=new g.marker.AdvancedMarkerElement({map,position:pos,content:dot,title:user.fullname||'User'}); } else { marker=new g.Marker({map,position:pos,title:user.fullname||'User'}); } const html=`<b>${escapeHtml(user.fullname||'User')}</b><br>${escapeHtml(user.user_type||'')}`; g.event.addListener(marker,'click',()=>{ info.setContent(html); info.open(map,marker); }); adminMarkers.push(marker); bounds.extend(pos); count++; } }); if(count>1) map.fitBounds(bounds,48); else if(count===1){ map.setCenter(bounds.getCenter()); map.setZoom(13); } }
    function getUploadUrl(path){ if(!path) return ''; if(/^(https?:)?\/\//.test(path) || path.startsWith('/') || path.startsWith('./') || path.startsWith('../')) return path; return `../uploads/${path}`; }
    async function openUserModal(userType, userId, isSuspended, isVerified){ const modalEl=document.getElementById('detailModal'); const titleEl=document.getElementById('detailModalLabel'); const bodyEl=document.getElementById('detailModalBody'); const footerEl=document.getElementById('detailModalFooter'); if(!modalEl||!bodyEl||!footerEl||!titleEl) return; titleEl.textContent = userType === 'driver' ? 'Driver Details' : 'Passenger Details'; bodyEl.innerHTML='<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'; footerEl.innerHTML=''; const res=await fetch(`../api/admin-api.php?action=user_profile_reviews&user_id=${userId}`); const data=await res.json(); if(data.status !== 'success'){ bodyEl.innerHTML=`<div class="alert alert-danger">${escapeHtml(data.message||'Unable to load details.')}</div>`; new bootstrap.Modal(modalEl).show(); return; } const profile=data.data.profile||{}; const stats=data.data.stats||{}; const wallet=data.data.wallet_summary; const actionButtons=[]; if(userType === 'driver' && !isVerified){ actionButtons.push(`<button class="btn btn-sm btn-outline-primary me-2" onclick="approveDriver(${userId}); openUserModal('${userType}', ${userId}, ${isSuspended}, 1);">Approve</button>`); } actionButtons.push(`<button class="btn btn-sm btn-outline-${isSuspended ? 'success' : 'danger'}" onclick="toggleUserAccess(${userId}, '${isSuspended ? 'unsuspend_user' : 'suspend_user'}'); openUserModal('${userType}', ${userId}, ${isSuspended ? 0 : 1}, ${isVerified});">${isSuspended ? 'Unsuspend' : 'Suspend'}</button>`); const walletBlock = wallet ? `<div class="col-12"><div class="border rounded p-3"><div class="text-muted small mb-2">Wallet — Commission Reservations</div><div class="row g-2 text-center mb-2"><div class="col-3"><div class="small text-muted">Total</div><div class="fw-bold">₦${Number(wallet.total_balance).toLocaleString()}</div></div><div class="col-3"><div class="small text-muted">Available</div><div class="fw-bold text-success">₦${Number(wallet.available_balance).toLocaleString()}</div></div><div class="col-3"><div class="small text-muted">Reserved</div><div class="fw-bold text-warning">₦${Number(wallet.reserved_balance).toLocaleString()}</div></div><div class="col-3"><div class="small text-muted">Commission Collected</div><div class="fw-bold">₦${Number(wallet.commission_collected).toLocaleString()}</div></div></div><div class="small text-muted mb-1">Commission Pending: ₦${Number(wallet.commission_pending).toLocaleString()}</div>${(wallet.active_reservations||[]).length ? `<table class="table table-sm mb-0 mt-2"><thead><tr><th>Ride</th><th>Reserved</th><th>Used</th><th>Outstanding</th></tr></thead><tbody>${wallet.active_reservations.map(r=>`<tr><td>#${r.ride_share_id}</td><td>₦${Number(r.reserved_amount).toLocaleString()}</td><td>₦${Number(r.used_amount).toLocaleString()}</td><td>₦${Number(r.reserved_amount - r.used_amount - r.released_amount).toLocaleString()}</td></tr>`).join('')}</tbody></table>` : '<div class="small text-muted">No active reservations.</div>'}</div></div>` : '';
const rawAvatar = profile.profile_image || ''; const hasAvatar = rawAvatar && rawAvatar !== 'default.png'; const avatarFallback = 'https://ui-avatars.com/api/?name=' + encodeURIComponent(profile.fullname || 'User').replace(/'/g, '%27') + '&background=0F8B8D&color=fff'; const avatarThumb = hasAvatar ? ('../img.php?f=' + encodeURIComponent(rawAvatar) + '&w=160') : avatarFallback; window.__profilePreview = hasAvatar ? { url: '../uploads/' + rawAvatar, title: (profile.fullname || 'User') + ' — profile picture' } : null; const avatarImg = `<img src="${avatarThumb}" alt="Profile picture" class="rounded-circle border flex-shrink-0" style="width:64px;height:64px;object-fit:cover;${hasAvatar ? 'cursor:pointer;' : ''}" ${hasAvatar ? 'onclick="openProfilePreview()"' : ''} onerror="this.onerror=null;this.src='${avatarFallback}'">`; bodyEl.innerHTML=`<div class="row g-3"><div class="col-12"><div class="border rounded p-3 d-flex align-items-center gap-3">${avatarImg}<div><h6 class="fw-bold mb-1">${escapeHtml(profile.fullname||'User')}</h6><div class="small text-muted">${escapeHtml(profile.user_type||userType)} • ${escapeHtml(profile.user_id||'N/A')}</div></div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Email</div><div class="fw-semibold">${escapeHtml(profile.email||'N/A')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Phone</div><div class="fw-semibold">${escapeHtml(profile.phone||'N/A')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Rating</div><div class="fw-semibold">${parseFloat(profile.rating||0).toFixed(2)}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Completed rides</div><div class="fw-semibold">${profile.completed_rides || 0}</div></div></div>${walletBlock}<div class="col-12"><div class="border rounded p-3"><div class="text-muted small">Review summary</div><div class="fw-semibold">${stats.total_reviews || 0} review(s) • ${parseFloat(stats.average_rating || 0).toFixed(2)} avg</div></div></div></div>`; footerEl.innerHTML=actionButtons.join(''); new bootstrap.Modal(modalEl).show(); }
    async function openVehicleModal(vehicleId, currentStatus){ const modalEl=document.getElementById('detailModal'); const titleEl=document.getElementById('detailModalLabel'); const bodyEl=document.getElementById('detailModalBody'); const footerEl=document.getElementById('detailModalFooter'); if(!modalEl||!bodyEl||!footerEl||!titleEl) return; titleEl.textContent='Vehicle Details'; bodyEl.innerHTML='<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'; footerEl.innerHTML=''; const res=await fetch(`../api/admin-api.php?action=vehicle_details&vehicle_id=${vehicleId}`); const data=await res.json(); if(data.status !== 'success'){ bodyEl.innerHTML=`<div class="alert alert-danger">${escapeHtml(data.message||'Unable to load vehicle details.')}</div>`; new bootstrap.Modal(modalEl).show(); return; } const vehicle=data.data.vehicle||{}; const photos=Array.isArray(data.data.photos)?data.data.photos:[]; const documents=Array.isArray(data.data.documents)?data.data.documents:[]; const actionButtons=[]; if(currentStatus !== 'approved'){ actionButtons.push(`<button class="btn btn-sm btn-outline-success me-2" onclick="approveVehicle(${vehicleId}); openVehicleModal(${vehicleId}, 'approved');">Approve</button>`); actionButtons.push(`<button class="btn btn-sm btn-outline-danger" onclick="rejectVehicle(${vehicleId}); openVehicleModal(${vehicleId}, 'rejected');">Reject</button>`); } else { actionButtons.push(`<button class="btn btn-sm btn-outline-dark" onclick="suspendVehicle(${vehicleId}); openVehicleModal(${vehicleId}, 'rejected');">Suspend</button>`); } const photoGallery = photos.map((photo, idx)=>({ url: '../uploads/' + (photo.photo_path || ''), title: 'Vehicle image ' + (idx+1) + ' of ' + photos.length })); const photoMarkup = photos.length ? `<div class="mt-3"><div class="small text-muted mb-2">Vehicle images</div><div class="row g-2">${photos.map((photo, idx)=>{ const rawPath = photo.photo_path || ''; const thumbUrl = '../img.php?f=' + encodeURIComponent(rawPath) + '&w=400'; return `<div class="col-6 col-md-4"><div class="border rounded p-2"><img src="${thumbUrl}" loading="lazy" alt="Vehicle image" class="img-fluid rounded" style="max-height:120px;width:100%;object-fit:cover;cursor:pointer;" onclick="openVehiclePhotoGallery(${idx})"><div class="small text-muted mt-2">${escapeHtml(photo.created_at || 'Image')}</div></div></div>`; }).join('')}</div></div>` : ''; window.__vehiclePhotoGallery = photoGallery; const docGallery = []; const documentMarkup = documents.length ? `<div class="mt-3"><div class="small text-muted mb-2">Documents / licence</div><div class="row g-2">${documents.map(doc=>{ const rawDocPath = doc.document_path || ''; const docPath = escapeHtml(rawDocPath); const docUrl = `../uploads/${docPath}`; const thumbUrl = '../img.php?f=' + encodeURIComponent(rawDocPath) + '&w=400'; const isImage=/\.(png|jpe?g|gif|webp|bmp)$/i.test(rawDocPath); let media; if(isImage){ const gi = docGallery.length; docGallery.push({ url: '../uploads/' + rawDocPath, title: (doc.document_name || 'Document') }); media = `<img src="${thumbUrl}" loading="lazy" alt="Document" class="img-fluid rounded" style="max-height:120px;width:100%;object-fit:cover;cursor:pointer;" onclick="openVehicleDocGallery(${gi})">`; } else { media = `<a href="${docUrl}" target="_blank" class="btn btn-outline-secondary btn-sm w-100">Open file</a>`; } return `<div class="col-6 col-md-4"><div class="border rounded p-2">${media}<div class="small fw-semibold mt-2">${escapeHtml(doc.document_type_label || doc.document_name || 'Document')}</div><div class="small text-muted">${escapeHtml(doc.document_name || '')} <span class="badge ${doc.status==='approved'?'bg-success':doc.status==='rejected'?'bg-danger':'bg-warning text-dark'}">${escapeHtml(doc.status || 'pending')}</span></div>${doc.status==='rejected' && doc.rejection_reason ? `<div class="small text-danger">${escapeHtml(doc.rejection_reason)}</div>` : ''}</div></div>`; }).join('')}</div></div>` : ''; window.__vehicleDocGallery = docGallery; bodyEl.innerHTML=`<div class="row g-3"><div class="col-12"><div class="border rounded p-3"><h6 class="fw-bold mb-2">${escapeHtml(vehicle.vehicle_name || vehicle.make || 'Vehicle')}</h6><div class="small text-muted">${escapeHtml(vehicle.driver_name || 'Unknown driver')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Vehicle Type</div><div class="fw-semibold">${escapeHtml(data.data.vehicle_type_label || vehicle.vehicle_type || 'N/A')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Plate</div><div class="fw-semibold">${escapeHtml(vehicle.plate_number || 'N/A')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Status</div><div class="fw-semibold">${escapeHtml(vehicle.status || 'pending')}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Photos</div><div class="fw-semibold">${vehicle.photo_count || 0}</div></div></div><div class="col-md-6"><div class="border rounded p-3"><div class="text-muted small">Documents</div><div class="fw-semibold">${vehicle.document_count || 0}</div></div></div><div class="col-12"><div class="border rounded p-3"><div class="text-muted small">Remarks</div><div class="fw-semibold">${escapeHtml(vehicle.review_remarks || 'No remarks yet')}</div></div></div>${photoMarkup ? `<div class="col-12">${photoMarkup}</div>` : ''}${documentMarkup ? `<div class="col-12">${documentMarkup}</div>` : ''}</div>`; footerEl.innerHTML=actionButtons.join(''); new bootstrap.Modal(modalEl).show(); }
    function openVehiclePhotoGallery(startIndex){ const items=Array.isArray(window.__vehiclePhotoGallery)?window.__vehiclePhotoGallery:[]; if(!items.length) return; if(window.MeritImageViewer && window.MeritImageViewer.openGallery){ window.MeritImageViewer.openGallery(items, startIndex||0); return; } const item=items[startIndex||0]||items[0]; openImagePreview(item.url, item.title); }
    function openVehicleDocGallery(startIndex){ const items=Array.isArray(window.__vehicleDocGallery)?window.__vehicleDocGallery:[]; if(!items.length) return; if(window.MeritImageViewer && window.MeritImageViewer.openGallery){ window.MeritImageViewer.openGallery(items, startIndex||0); return; } const item=items[startIndex||0]||items[0]; openImagePreview(item.url, item.title); }
    function openProfilePreview(){ const p=window.__profilePreview; if(p && p.url) openImagePreview(p.url, p.title); }
    function openImagePreview(url, title){ if(!url) return; if(window.MeritImageViewer && window.MeritImageViewer.open){ window.MeritImageViewer.open(url, title||'Preview'); return; } const modalEl=document.getElementById('imagePreviewModal'); const titleEl=document.getElementById('imagePreviewModalLabel'); const bodyEl=document.getElementById('imagePreviewBody'); if(!modalEl||!titleEl||!bodyEl) return; titleEl.textContent=title||'Preview'; bodyEl.innerHTML=`<img src="${escapeHtml(url)}" alt="Preview" class="img-fluid" style="max-height:70vh;object-fit:contain;">`; new bootstrap.Modal(modalEl).show(); }
    async function approveDriver(userId){ const res=await sendAction({action:'approve_driver',user_id:userId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to approve driver'); }
    async function approveVehicle(vehicleId){ const res=await sendAction({action:'approve_vehicle',vehicle_id:vehicleId,remarks:'Approved from admin console'}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to approve vehicle'); }
    async function suspendVehicle(vehicleId){ const reason=prompt('Enter the reason for suspending this vehicle:'); if(reason===null) return; if(!reason.trim()){ alert('A suspension reason is required.'); return; } const res=await sendAction({action:'suspend_vehicle',vehicle_id:vehicleId,remarks:reason.trim()}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to suspend vehicle'); }
    async function rejectVehicle(vehicleId){ const reason=prompt('Enter the reason for rejecting this vehicle (the driver will see this):'); if(reason===null) return; if(!reason.trim()){ alert('A rejection reason is required.'); return; } const res=await sendAction({action:'reject_vehicle',vehicle_id:vehicleId,remarks:reason.trim()}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to reject vehicle'); }
    async function deleteVehicle(vehicleId){ if(!confirm('Permanently delete this vehicle record? This cannot be undone.')) return; const res=await sendAction({action:'delete_vehicle',vehicle_id:vehicleId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to delete vehicle'); }
    async function toggleUserAccess(userId, action){ const res=await sendAction({action,user_id:userId,reason:'Administrative action'}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to update user access'); }
    async function setDriverTier(userId, tier){ const res=await sendAction({action:'set_driver_tier',user_id:userId,driver_tier:tier}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to update driver tier'); }
    async function resendVerification(userId, channel){ const res=await sendAction({action:'resend_verification',user_id:userId,channel}); if(res.status==='success'){ alert(res.message||'Verification resent.'); loadDashboardData(); } else alert(res.message||'Unable to resend verification'); }
    async function unlockLoginLockout(userId){ const res=await sendAction({action:'unlock_login_lockout',user_id:userId}); if(res.status==='success'){ loadDashboardData(); } else alert(res.message||'Unable to unlock account'); }
    async function cancelRide(rideId){ const res=await sendAction({action:'cancel_ride_share',ride_id:rideId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to cancel ride'); }
    async function completeRide(rideId){ const res=await sendAction({action:'complete_ride_share',ride_id:rideId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to complete ride'); }
    async function removeRide(rideId){ const res=await sendAction({action:'remove_ride_share',ride_id:rideId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to remove ride'); }
    async function cancelBooking(bookingId){ const res=await sendAction({action:'cancel_booking',booking_id:bookingId}); if(res.status==='success') loadDashboardData(); else alert(res.message||'Unable to cancel booking'); }
    async function sendAction(payload){ const res=await fetch('../api/admin-api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); return res.json(); }
    // ---- Shared helper: POST JSON to any endpoint (sendAction above is admin-api.php-specific) ----
    async function postJson(url, payload){ const res=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); return res.json(); }
    // ---- CSRF-protected POST, for api/network-admin.php only ----
    // Same contract as postJson(), but every call carries the session's
    // CSRF token (read from the <meta name="csrf-token"> tag this page
    // renders via csrf_token()) as a `csrf_token` body field — same
    // convention assets/js/profile.js already uses against
    // api/social-accounts.php, so require_csrf() needs no new code path.
    // Scoped to MOHTOH NETWORK's admin actions only — the generic
    // postJson() above is left untouched so no other admin tab's
    // request contract changes.
    function csrfToken() { return document.querySelector('meta[name="csrf-token"]')?.content || ''; }
    async function postJsonCsrf(url, payload) {
        return postJson(url, { ...payload, csrf_token: csrfToken() });
    }
    function showTabAlert(elId, res){ const el=document.getElementById(elId); if(!el) return; el.className='alert '+(res.status==='success'?'alert-success':'alert-danger'); el.textContent=res.message||(res.status==='success'?'Saved':'Failed'); el.classList.remove('d-none'); }

    // ---- Part 3: Universal Pagination — reusable renderer for every
    // admin list (users/drivers/vehicles/rides/bookings/payments/wallet/
    // notifications/waitlist/premium/audit logs). Every list JSON
    // response carries {current_page, per_page, total_records,
    // total_pages} in its `pagination` key (see includes/functions.php's
    // paginate_meta()); this turns that into "Showing 1-20 of 300" +
    // Previous/page-number/Next controls, identically everywhere.
    function renderPagination(containerId, pagination, onPageChange) {
        const el = document.getElementById(containerId);
        if (!el || !pagination) { if (el) el.innerHTML = ''; return; }
        const { current_page, per_page, total_records, total_pages } = pagination;
        if (total_records === 0) { el.innerHTML = ''; return; }

        const start = (current_page - 1) * per_page + 1;
        const end = Math.min(current_page * per_page, total_records);

        let pageNumbers = [];
        const windowSize = 2;
        for (let p = Math.max(1, current_page - windowSize); p <= Math.min(total_pages, current_page + windowSize); p++) pageNumbers.push(p);

        el.innerHTML = `
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                <span class="text-muted small">Showing ${start}-${end} of ${total_records}</span>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item ${current_page <= 1 ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${current_page - 1}">Previous</a></li>
                        ${pageNumbers.map(p => `<li class="page-item ${p === current_page ? 'active' : ''}"><a class="page-link" href="#" data-page="${p}">${p}</a></li>`).join('')}
                        <li class="page-item ${current_page >= total_pages ? 'disabled' : ''}"><a class="page-link" href="#" data-page="${current_page + 1}">Next</a></li>
                    </ul>
                </nav>
            </div>`;

        el.querySelectorAll('[data-page]').forEach(link => link.addEventListener('click', e => {
            e.preventDefault();
            const targetPage = parseInt(link.dataset.page, 10);
            if (targetPage >= 1 && targetPage <= total_pages && targetPage !== current_page) onPageChange(targetPage);
        }));
    }

    // Client-side pagination for the tables still fed by
    // get_dashboard_data's bulk (now Part-4-capped-at-100) arrays —
    // Drivers/Vehicles/Passengers/Ride Sessions/Bookings tabs. Slices
    // the already-fetched array 20-per-page and drives the same
    // renderPagination() component used everywhere else, rather than
    // rendering all rows unpaginated in one long scroll.
    const CLIENT_PAGE_STATE = {};
    function paginateClientSide(key, fullArray, renderRowsFn) {
        const pageSize = 20;
        const state = CLIENT_PAGE_STATE[key] || { page: 1 };
        CLIENT_PAGE_STATE[key] = state;
        const totalRecords = fullArray.length;
        const totalPages = Math.max(1, Math.ceil(totalRecords / pageSize));
        if (state.page > totalPages) state.page = 1;
        const start = (state.page - 1) * pageSize;
        renderRowsFn(fullArray.slice(start, start + pageSize));
        renderPagination(`${key}-pagination`, { current_page: state.page, per_page: pageSize, total_records: totalRecords, total_pages: totalPages }, (p) => {
            state.page = p;
            paginateClientSide(key, fullArray, renderRowsFn);
        });
    }

    // ---- Pricing Management (Section 1) ----
    const PRICING_KEYS = ['base_fare','price_per_km','price_per_minute','minimum_fare','maximum_fare','booking_fee','platform_service_fee','commission_percentage','rider_service_charge','cancellation_fee','waiting_charge','price_band_percent','night_fare_multiplier','night_start_hour','night_end_hour','peak_hour_multiplier','peak_start_hour','peak_end_hour','holiday_multiplier','holiday_dates','private_pickup_base_fare','private_pickup_price_per_km','private_booking_fee'];
    // Private Pickup's per-vehicle-class rate cards. Held apart from
    // PRICING_KEYS because these are the only fields where BLANK is a
    // meaningful value ("inherit the shared fallback"): they are submitted
    // even when empty, so clearing one actually restores inheritance instead
    // of silently leaving the old override in place.
    const PRIVATE_PICKUP_CLASS_KEYS = ['keke','car'].flatMap(cls => [
        `private_pickup_${cls}_base_fare`,
        `private_pickup_${cls}_price_per_km`,
        `private_pickup_${cls}_minimum_fare`,
        `private_pickup_${cls}_maximum_fare`,
        `private_pickup_${cls}_commission_percentage`,
    ]);
    // Mirrors the exact defaults each get_pricing_setting()/get_*() call
    // in includes/functions.php falls back to when a key has never been
    // set — Reset To Default fills the form with these, it does not
    // silently save; the admin still reviews and clicks Save Changes.
    // The per-class Private Pickup fields reset to blank, i.e. back to
    // inheriting the shared Private Pickup rate rather than to a guessed price.
    const PRICING_DEFAULTS = { base_fare:0, price_per_km:1.20, price_per_minute:0, minimum_fare:5.00, maximum_fare:0, booking_fee:0, platform_service_fee:0, commission_percentage:8.00, rider_service_charge:0, cancellation_fee:0, waiting_charge:0, price_band_percent:12.5, night_fare_multiplier:1.0, night_start_hour:22, night_end_hour:6, peak_hour_multiplier:1.0, peak_start_hour:7, peak_end_hour:9, holiday_multiplier:1.0, holiday_dates:'', pricing_mode:'hybrid', private_pickup_base_fare:300, private_pickup_price_per_km:120, private_booking_fee:100, ...Object.fromEntries(PRIVATE_PICKUP_CLASS_KEYS.map(k => [k, ''])) };
    async function loadPricingCenter(){ const res=await fetch('../api/pricing-admin.php'); const data=await res.json(); if(data.status!=='success') return; const settings=data.data.settings||{}; PRICING_KEYS.concat(PRIVATE_PICKUP_CLASS_KEYS).forEach(key=>{ const el=document.getElementById('pc-'+key); if(el) el.value = settings[key] ?? ''; }); const mode=settings.pricing_mode || 'hybrid'; const modeRadio=document.querySelector(`input[name="pc-pricing_mode"][value="${mode}"]`); if(modeRadio) modeRadio.checked = true; const container=document.getElementById('pc-star-multipliers'); if(container){ container.innerHTML=(data.data.star_multipliers||[]).map(m=>`<div class="col-md-2"><label class="form-label small">${m.star_rating}★</label><input type="number" step="0.01" class="form-control form-control-sm star-mult-input" data-star="${m.star_rating}" value="${m.price_per_km}"></div>`).join(''); } }
    async function handlePricingCenterSubmit(event){ event.preventDefault(); const settings={}; PRICING_KEYS.forEach(key=>{ const el=document.getElementById('pc-'+key); if(el && el.value !== '') settings[key]=el.value; }); PRIVATE_PICKUP_CLASS_KEYS.forEach(key=>{ const el=document.getElementById('pc-'+key); if(el) settings[key]=el.value; }); const modeChecked=document.querySelector('input[name="pc-pricing_mode"]:checked'); if(modeChecked) settings.pricing_mode = modeChecked.value; const res=await postJson('../api/pricing-admin.php', {settings}); showTabAlert('pricing-center-alert', res); }
    document.getElementById('pc-reset-defaults')?.addEventListener('click', () => {
        if (!confirm('Fill the form with MohToh\'s default pricing values? This does not save until you click Save Changes.')) return;
        PRICING_KEYS.concat(PRIVATE_PICKUP_CLASS_KEYS).forEach(key => { const el = document.getElementById('pc-' + key); if (el && key in PRICING_DEFAULTS) el.value = PRICING_DEFAULTS[key]; });
        const modeRadio = document.querySelector(`input[name="pc-pricing_mode"][value="${PRICING_DEFAULTS.pricing_mode}"]`);
        if (modeRadio) modeRadio.checked = true;
    });
    document.getElementById('pc-preview-btn')?.addEventListener('click', async () => {
        const distance = document.getElementById('pv-distance')?.value || 0;
        const seats = document.getElementById('pv-seats')?.value || 1;
        const time = document.getElementById('pv-time')?.value || '';
        const params = new URLSearchParams({ action: 'preview', distance_km: distance, seats: seats });
        if (time) params.set('departure_time', time);
        const res = await fetch('../api/pricing-admin.php?' + params.toString());
        const data = await res.json();
        if (data.status !== 'success') { alert(data.message || 'Could not calculate a preview.'); return; }
        document.getElementById('pv-recommended').textContent = '₦' + Number(data.data.recommended_price).toLocaleString();
        document.getElementById('pv-min').textContent = '₦' + Number(data.data.min_price).toLocaleString();
        document.getElementById('pv-max').textContent = '₦' + Number(data.data.max_price).toLocaleString();
        document.getElementById('pv-result')?.classList.remove('d-none');
    });
    async function saveStarMultipliers(){ const star_multipliers={}; document.querySelectorAll('.star-mult-input').forEach(el=>{ if(el.value) star_multipliers[el.dataset.star]=el.value; }); const res=await postJson('../api/pricing-admin.php', {star_multipliers}); alert(res.message||(res.status==='success'?'Saved':'Failed')); }

    // ---- Driver Settings (Section 2) ----
    const DRIVER_SETTING_KEYS = ['min_wallet_balance','max_active_rides_per_driver','pricing_mode'];
    async function loadDriverSettingsTab(){ const res=await fetch('../api/pricing-admin.php'); const data=await res.json(); if(data.status==='success'){ const settings=data.data.settings||{}; DRIVER_SETTING_KEYS.forEach(key=>{ const el=document.getElementById('ds-'+key); if(el) el.value = settings[key] ?? ''; }); const reqDocs=document.getElementById('ds-require_vehicle_documents'); if(reqDocs) reqDocs.checked = String(settings.require_vehicle_documents) === '1'; } await loadDriverOptions('db-driver-select'); await loadBonusHistory(); }
    async function handleDriverSettingsSubmit(event){ event.preventDefault(); const settings={}; DRIVER_SETTING_KEYS.forEach(key=>{ const el=document.getElementById('ds-'+key); if(el && el.value !== '') settings[key]=el.value; }); settings.require_vehicle_documents = document.getElementById('ds-require_vehicle_documents').checked ? '1' : '0'; const res=await postJson('../api/pricing-admin.php', {settings}); showTabAlert('driver-settings-alert', res); }
    async function loadDriverOptions(selectId){ const select=document.getElementById(selectId); if(!select) return; const res=await fetch('../api/driver-comms.php?action=list_drivers'); const data=await res.json(); const drivers=data.status==='success'?(data.data.drivers||[]):[]; select.innerHTML=drivers.map(d=>`<option value="${d.id}">${escapeHtml(d.fullname)} (${escapeHtml(d.user_id||'')})</option>`).join(''); }
    async function saveCustomCommission(){ const driverId=document.getElementById('db-driver-select').value; const value=document.getElementById('db-custom-commission').value; const res=await postJson('../api/driver-settings.php', {action:'set_custom_commission', driver_id:driverId, custom_commission_percentage:value}); showTabAlert('driver-bonus-alert', res); }
    async function grantBonus(){ const driverId=document.getElementById('db-driver-select').value; const amount=document.getElementById('db-bonus-amount').value; const type=document.getElementById('db-bonus-type').value; const reason=document.getElementById('db-bonus-reason').value; const res=await postJson('../api/driver-settings.php', {action:'grant_bonus', driver_id:driverId, amount, type, reason}); showTabAlert('driver-bonus-alert', res); if(res.status==='success'){ document.getElementById('db-bonus-amount').value=''; document.getElementById('db-bonus-reason').value=''; loadBonusHistory(); } }
    async function loadBonusHistory(){ const body=document.getElementById('bonus-history-body'); if(!body) return; const res=await fetch('../api/driver-settings.php?action=list_bonuses'); const data=await res.json(); const bonuses=data.status==='success'?(data.data.bonuses||[]):[]; body.innerHTML = bonuses.length ? bonuses.map(b=>`<tr><td>${escapeHtml(b.driver_name)} <span class="text-muted small">(${escapeHtml(b.driver_public_id||'')})</span></td><td>${escapeHtml(b.type)}</td><td>₦${Number(b.amount).toLocaleString()}</td><td>${escapeHtml(b.reason||'')}</td><td>${new Date(b.created_at).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="5" class="text-muted">No bonuses granted yet.</td></tr>'; }

    // ---- Regional Pricing (Section 5) ----
    async function loadRegions(){ const body=document.getElementById('regions-body'); if(!body) return; const res=await fetch('../api/regions.php'); const data=await res.json(); const regions=data.status==='success'?(data.data.regions||[]):[]; body.innerHTML = regions.length ? regions.map(r=>`<tr><td>${escapeHtml(r.name)}</td><td>${r.radius_km}</td><td>${r.price_per_km ?? '—'}</td><td>${r.base_fare ?? '—'}</td><td>${r.commission_percentage ?? '—'}</td><td>${r.is_active==1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'}</td><td><button class="btn btn-sm btn-outline-warning me-2" onclick="toggleRegionActive(${r.id}, ${r.is_active})">${r.is_active==1?'Deactivate':'Activate'}</button><button class="btn btn-sm btn-outline-danger" onclick="deleteRegion(${r.id})">Delete</button></td></tr>`).join('') : '<tr><td colspan="7" class="text-muted">No regions configured yet.</td></tr>'; }
    async function createRegion(){ const payload={ action:'create', name:document.getElementById('rg-name').value, center_lat:document.getElementById('rg-center_lat').value, center_lng:document.getElementById('rg-center_lng').value, radius_km:document.getElementById('rg-radius_km').value, price_per_km:document.getElementById('rg-price_per_km').value }; const res=await postJson('../api/regions.php', payload); showTabAlert('region-alert', res); if(res.status==='success'){ ['rg-name','rg-center_lat','rg-center_lng','rg-radius_km','rg-price_per_km'].forEach(id=>document.getElementById(id).value=''); loadRegions(); } }
    async function toggleRegionActive(id, current){ const res=await postJson('../api/regions.php', {action:'update', id, is_active: current==1?0:1}); if(res.status==='success') loadRegions(); else alert(res.message); }
    async function deleteRegion(id){ if(!confirm('Delete this region?')) return; const res=await postJson('../api/regions.php', {action:'delete', id}); if(res.status==='success') loadRegions(); else alert(res.message); }

    // ---- Promotions (Section 6) ----
    async function loadPromotions(){ const body=document.getElementById('promos-body'); if(!body) return; const res=await fetch('../api/promotions.php'); const data=await res.json(); const promos=data.status==='success'?(data.data.promo_codes||[]):[]; body.innerHTML = promos.length ? promos.map(p=>`<tr><td>${escapeHtml(p.code)}</td><td>${escapeHtml(p.type)}</td><td>${p.type==='percentage'?p.value+'%':'₦'+p.value}</td><td>${escapeHtml(p.applies_to)}</td><td>${p.times_used}${p.max_uses?' / '+p.max_uses:''}</td><td>${p.is_active==1?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'}</td><td><button class="btn btn-sm btn-outline-warning me-2" onclick="togglePromoActive(${p.id})">${p.is_active==1?'Deactivate':'Activate'}</button><button class="btn btn-sm btn-outline-danger" onclick="deletePromo(${p.id})">Delete</button></td></tr>`).join('') : '<tr><td colspan="7" class="text-muted">No promo codes yet.</td></tr>'; }
    async function createPromo(){ const payload={ action:'create', code:document.getElementById('pr-code').value, type:document.getElementById('pr-type').value, value:document.getElementById('pr-value').value, applies_to:document.getElementById('pr-applies_to').value, per_user_limit:document.getElementById('pr-per_user_limit').value }; const res=await postJson('../api/promotions.php', payload); showTabAlert('promo-alert', res); if(res.status==='success'){ ['pr-code','pr-value'].forEach(id=>document.getElementById(id).value=''); loadPromotions(); } }
    async function togglePromoActive(id){ const res=await postJson('../api/promotions.php', {action:'toggle_active', id}); if(res.status==='success') loadPromotions(); else alert(res.message); }
    async function deletePromo(id){ if(!confirm('Delete this promo code?')) return; const res=await postJson('../api/promotions.php', {action:'delete', id}); if(res.status==='success') loadPromotions(); else alert(res.message); }

    // ---- Commission (Section 7) ----
    async function loadCommissionReport(){ const body=document.getElementById('commission-body'); if(!body) return; const res=await fetch('../api/admin-reports.php?report=driver_earnings'); const data=await res.json(); const drivers=data.status==='success'?(data.data.drivers||[]):[]; body.innerHTML = drivers.length ? drivers.map(d=>`<tr><td>${escapeHtml(d.fullname)} <span class="text-muted small">(${escapeHtml(d.driver_public_id||'')})</span></td><td>${d.completed_trips}</td><td>₦${Number(d.total_earnings).toLocaleString()}</td><td>₦${Number(d.total_commission_generated).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No completed trips yet.</td></tr>'; }

    // ---- Driver Communication (Section 8) ----
    async function loadDriverCommsTab(){ await loadDriverOptions('dc-driver-select'); }
    async function sendTargetedMessage(){ const payload={ action:'send_targeted', driver_id:document.getElementById('dc-driver-select').value, type:document.getElementById('dc-type').value, message:document.getElementById('dc-message').value }; const res=await postJson('../api/driver-comms.php', payload); showTabAlert('comms-alert', res); if(res.status==='success') document.getElementById('dc-message').value=''; }

    // ---- Reports (Section 9) ----
    let revenueReportCache = null;
    async function loadReport(reportName){
        const head=document.getElementById('report-table-head'); const body=document.getElementById('report-table-body');
        if(['daily','weekly','monthly'].includes(reportName)){
            if(!revenueReportCache){ const res=await fetch('../api/admin-reports.php?report=revenue'); const data=await res.json(); if(data.status==='success') revenueReportCache=data.data; }
            if(revenueReportCache){ const t=revenueReportCache.totals||{}; document.getElementById('rep-total-revenue').textContent='₦'+Number(t.total_revenue||0).toLocaleString(); document.getElementById('rep-total-commission').textContent='₦'+Number(t.total_commission||0).toLocaleString(); document.getElementById('rep-total-driver-earnings').textContent='₦'+Number(t.total_driver_earnings||0).toLocaleString(); document.getElementById('rep-total-trips').textContent=t.total_trips||0; }
            const rows=(revenueReportCache && revenueReportCache[reportName]) || [];
            head.innerHTML='<tr><th>Period</th><th>Revenue</th><th>Commission</th><th>Driver Earnings</th></tr>';
            body.innerHTML = rows.length ? rows.map(r=>`<tr><td>${r.period}${r.week_start?' (from '+r.week_start+')':''}</td><td>₦${Number(r.revenue).toLocaleString()}</td><td>₦${Number(r.commission).toLocaleString()}</td><td>₦${Number(r.driver_earnings).toLocaleString()}</td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No data for this period.</td></tr>';
            return;
        }
        const res=await fetch('../api/admin-reports.php?report='+reportName); const data=await res.json();
        if(data.status!=='success'){ head.innerHTML=''; body.innerHTML=`<tr><td class="text-danger">${escapeHtml(data.message||'Failed to load report')}</td></tr>`; return; }
        if(reportName==='most_active_drivers'){ const rows=data.data.drivers||[]; head.innerHTML='<tr><th>Driver</th><th>Rating</th><th>Rides Created</th><th>Completed Trips</th></tr>'; body.innerHTML = rows.length ? rows.map(d=>`<tr><td>${escapeHtml(d.fullname)} <span class="text-muted small">(${escapeHtml(d.driver_public_id||'')})</span></td><td>${parseFloat(d.rating||0).toFixed(2)}</td><td>${d.rides_created}</td><td>${d.completed_trips}</td></tr>`).join('') : '<tr><td colspan="4" class="text-muted">No drivers yet.</td></tr>'; }
        else if(reportName==='most_active_routes'){ const rows=data.data.routes||[]; head.innerHTML='<tr><th>Route</th><th>Ride Count</th></tr>'; body.innerHTML = rows.length ? rows.map(r=>`<tr><td>${escapeHtml(r.start_location)} → ${escapeHtml(r.destination)}</td><td>${r.ride_count}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">No rides yet.</td></tr>'; }
        else if(reportName==='peak_hours'){ const rows=data.data.hours||[]; head.innerHTML='<tr><th>Hour of Day</th><th>Ride Count</th></tr>'; body.innerHTML = rows.length ? rows.map(r=>`<tr><td>${String(r.hour).padStart(2,'0')}:00</td><td>${r.ride_count}</td></tr>`).join('') : '<tr><td colspan="2" class="text-muted">No rides yet.</td></tr>'; }
        else if(reportName==='ride_statistics'){ const byStatus=data.data.ride_shares_by_status||[]; const byBooking=data.data.bookings_by_status||[]; head.innerHTML='<tr><th>Metric</th><th>Status</th><th>Count</th></tr>'; body.innerHTML = [...byStatus.map(s=>`<tr><td>Ride sessions</td><td>${escapeHtml(s.status)}</td><td>${s.count}</td></tr>`), ...byBooking.map(s=>`<tr><td>Bookings</td><td>${escapeHtml(s.status)}</td><td>${s.count}</td></tr>`)].join('') || '<tr><td colspan="3" class="text-muted">No data yet.</td></tr>'; }
    }
    // ---- Service Integration Center (Email + SMS/OTP delivery) ----
    const EMAIL_TEXT_KEYS = ['mode','host','port','username','encryption','from_email','from_name','reply_to'];
    const SMS_TEXT_KEYS = ['mode','termii_sender_id','twilio_account_sid','twilio_from_number','twilio_verify_sid','vonage_api_key','vonage_brand_name','africastalking_username','africastalking_sender_id'];
    const SMS_SECRET_KEYS = ['termii_api_key','twilio_auth_token','vonage_api_secret','africastalking_api_key'];
    const MAPS_SECRET_KEYS = ['api_key'];
    const MAPS_TOGGLE_KEYS = ['enable_places','enable_directions','enable_geocoding'];
    const PAYMENT_TEXT_KEYS = ['public_key'];
    const PAYMENT_SECRET_KEYS = ['secret_key','webhook_secret'];
    const PAYMENT_TOGGLE_KEYS = ['enabled','sandbox_mode'];
    function toggleEmailProviderFields(){ const mode=document.getElementById('ei-mode').value; document.getElementById('ei-smtp-fields').style.display = mode === 'smtp' ? '' : 'none'; }
    function toggleSmsProviderFields(){ const mode=document.getElementById('si-mode').value; document.querySelectorAll('.si-provider-fields').forEach(el => el.style.display = 'none'); const active=document.getElementById('si-fields-'+mode); if(active) active.style.display=''; }
    async function loadIntegrationCenter(){
        const res = await fetch('../api/integration-center.php');
        const data = await res.json();
        if (data.status !== 'success') return;
        const email = data.data.email || {}; const sms = data.data.sms || {}; const otp = data.data.otp || {}; const policy = data.data.policy || {};
        EMAIL_TEXT_KEYS.forEach(key => { const el=document.getElementById('ei-'+key); if(el) el.value = email[key] ?? ''; });
        const pwStatus = document.getElementById('ei-password-status'); const pw = email.password || {};
        if (pwStatus) pwStatus.textContent = pw.configured ? `(saved: ${pw.masked})` : '(not set)';
        SMS_TEXT_KEYS.forEach(key => { const el=document.getElementById('si-'+key); if(el) el.value = sms[key] ?? ''; });
        SMS_SECRET_KEYS.forEach(key => { const status=document.getElementById('si-'+key+'-status'); const field=sms[key] || {}; if(status) status.textContent = field.configured ? `(saved: ${field.masked})` : '(not set)'; });
        OTP_KEYS.forEach(key => { const el=document.getElementById('oi-'+key); if(el) el.value = otp[key] ?? ''; });
        POLICY_KEYS.forEach(key => { const el=document.getElementById('pi-'+key); if(el) el.checked = !!policy[key]; });
        const verification = data.data.verification || {};
        VERIFICATION_KEYS.forEach(key => { const el=document.getElementById('vi-'+key); if(el) el.checked = !!verification[key]; });
        const maps = data.data.maps || {};
        MAPS_SECRET_KEYS.forEach(key => { const status=document.getElementById('mi-'+key+'-status'); const field=maps[key] || {}; if(status) status.textContent = field.configured ? `(saved: ${field.masked})` : '(not set)'; });
        MAPS_TOGGLE_KEYS.forEach(key => { const el=document.getElementById('mi-'+key); if(el) el.checked = !!maps[key]; });
        const payment = data.data.payment || {};
        PAYMENT_TEXT_KEYS.forEach(key => { const el=document.getElementById('pay-'+key); if(el) el.value = payment[key] ?? ''; });
        PAYMENT_SECRET_KEYS.forEach(key => { const status=document.getElementById('pay-'+key+'-status'); const field=payment[key] || {}; if(status) status.textContent = field.configured ? (field.needsReentry ? '(saved key unreadable — re-enter)' : `(saved: ${field.masked})`) : '(not set)'; });
        PAYMENT_TOGGLE_KEYS.forEach(key => { const el=document.getElementById('pay-'+key); if(el) el.checked = !!payment[key]; });
        const payStatusBadge = document.getElementById('pay-provider-status');
        if (payStatusBadge) { const provName = (payment.provider || 'paystack'); payStatusBadge.textContent = `${provName.charAt(0).toUpperCase()+provName.slice(1)} · ${payment.enabled ? 'Enabled' : 'Disabled'} · ${payment.mode === 'test' ? 'TEST' : 'LIVE'}`; payStatusBadge.className = 'badge align-middle ms-1 ' + (payment.enabled ? 'bg-success' : 'bg-secondary'); }
        OAUTH_CARDS.forEach(cfg => fillOAuthCard(cfg, data.data[cfg.dataKey]));
        toggleEmailProviderFields();
        toggleSmsProviderFields();
    }
    // Google Maps — single API key + three enable toggles, stored in the
    // integrations table's google_maps configuration JSON. A blank key field
    // leaves the saved key unchanged (enforced server-side).
    async function handleMapsIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        MAPS_SECRET_KEYS.forEach(key => { settings[key] = document.getElementById('mi-'+key).value; }); // blank = leave unchanged, enforced server-side
        MAPS_TOGGLE_KEYS.forEach(key => { settings[key] = document.getElementById('mi-'+key).checked; });
        const res = await postJson('../api/integration-center.php', { action: 'save_maps', settings });
        showTabAlert('maps-integration-alert', res);
        if (res.status === 'success') { MAPS_SECRET_KEYS.forEach(key => { document.getElementById('mi-'+key).value = ''; }); loadIntegrationCenter(); }
    }
    async function testMaps(){
        const btn = document.getElementById('mi-test-btn'); btn.disabled = true; btn.textContent = 'Testing…';
        const res = await postJson('../api/integration-center.php', { action: 'test_maps' });
        btn.disabled = false; btn.textContent = 'Test Google Maps';
        showTabAlert('mi-test-alert', res);
    }
    // Payment (Paystack) — public key stored in the clear, secret + webhook
    // secret sent only when changed (blank = keep, enforced server-side), and
    // the Enabled toggle maps to the integrations row status the backend
    // payment flow honours. Secret inputs are cleared after a successful save.
    async function handlePaymentIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        PAYMENT_TEXT_KEYS.forEach(key => { settings[key] = document.getElementById('pay-'+key).value; });
        PAYMENT_SECRET_KEYS.forEach(key => { settings[key] = document.getElementById('pay-'+key).value; }); // blank = leave unchanged, enforced server-side
        PAYMENT_TOGGLE_KEYS.forEach(key => { settings[key] = document.getElementById('pay-'+key).checked; });
        const res = await postJson('../api/integration-center.php', { action: 'save_payment', settings });
        showTabAlert('payment-integration-alert', res);
        if (res.status === 'success') { PAYMENT_SECRET_KEYS.forEach(key => { document.getElementById('pay-'+key).value = ''; }); loadIntegrationCenter(); }
    }
    async function testPayment(){
        const btn = document.getElementById('pay-test-btn'); btn.disabled = true; btn.textContent = 'Testing…';
        const res = await postJson('../api/integration-center.php', { action: 'test_payment' });
        btn.disabled = false; btn.textContent = 'Test Connection';
        showTabAlert('pay-test-alert', res);
    }
    // Social Login (Google / Facebook OAuth). Each provider card shares one shape:
    // an enable switch (controls frontend button AND backend flow), a public
    // client/app id, an encrypted secret (blank = keep the saved value), and the
    // fixed redirect URI to register with the provider. The secret is only ever
    // shown as a mask and is cleared from the input after a successful save.
    const OAUTH_CARDS = [
        { prefix: 'goog', dataKey: 'google_oauth',   form: 'google-oauth-form',   save: 'save_google_oauth',   test: 'test_google_oauth',   label: 'Google',   badge: 'google-oauth-status' },
        { prefix: 'fb',   dataKey: 'facebook_oauth', form: 'facebook-oauth-form', save: 'save_facebook_oauth', test: 'test_facebook_oauth', label: 'Facebook', badge: 'facebook-oauth-status' },
    ];
    function fillOAuthCard(cfg, d){
        if (!d) return;
        const en = document.getElementById(cfg.prefix + '-enabled'); if (en) en.checked = !!d.enabled;
        const cid = document.getElementById(cfg.prefix + '-client_id'); if (cid) cid.value = d.client_id ?? '';
        const secStatus = document.getElementById(cfg.prefix + '-client_secret-status'); const sec = d.client_secret || {};
        if (secStatus) secStatus.textContent = sec.configured ? (sec.needsReentry ? '(saved secret unreadable — re-enter)' : `(saved: ${sec.masked})`) : '(not set)';
        const ruri = document.getElementById(cfg.prefix + '-redirect_uri'); if (ruri) ruri.value = d.redirect_uri ?? '';
        const rdef = document.getElementById(cfg.prefix + '-redirect_uri-default'); if (rdef) rdef.textContent = d.default_redirect_uri ?? '';
        const badge = document.getElementById(cfg.badge);
        if (badge) {
            let txt, cls;
            if (d.usable) { txt = cfg.label + ' · Enabled'; cls = 'bg-success'; }
            else if (d.enabled && !d.configured) { txt = cfg.label + ' · Enabled · needs credentials'; cls = 'bg-warning text-dark'; }
            else { txt = cfg.label + ' · Disabled'; cls = 'bg-secondary'; }
            badge.textContent = txt; badge.className = 'badge align-middle ms-1 ' + cls;
        }
    }
    async function handleOAuthSubmit(cfg, event){
        event.preventDefault();
        const settings = {
            enabled: document.getElementById(cfg.prefix + '-enabled').checked,
            client_id: document.getElementById(cfg.prefix + '-client_id').value,
            client_secret: document.getElementById(cfg.prefix + '-client_secret').value, // blank = leave unchanged, enforced server-side
            redirect_uri: document.getElementById(cfg.prefix + '-redirect_uri').value,
        };
        const res = await postJson('../api/integration-center.php', { action: cfg.save, settings });
        showTabAlert(cfg.form.replace('-form', '-alert'), res);
        if (res.status === 'success') { document.getElementById(cfg.prefix + '-client_secret').value = ''; loadIntegrationCenter(); }
    }
    async function testOAuth(cfg){
        const btn = document.getElementById(cfg.prefix + '-test-btn'); const orig = btn.textContent; btn.disabled = true; btn.textContent = 'Testing…';
        const res = await postJson('../api/integration-center.php', { action: cfg.test });
        btn.disabled = false; btn.textContent = orig;
        showTabAlert(cfg.prefix + '-test-alert', res);
    }
    async function handleEmailIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        EMAIL_TEXT_KEYS.forEach(key => { settings[key] = document.getElementById('ei-'+key).value; });
        settings.password = document.getElementById('ei-password').value; // blank = leave unchanged, enforced server-side
        const res = await postJson('../api/integration-center.php', { action: 'save_email', settings });
        showTabAlert('email-integration-alert', res);
        if (res.status === 'success') { document.getElementById('ei-password').value = ''; loadIntegrationCenter(); }
    }
    async function handleSmsIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        SMS_TEXT_KEYS.forEach(key => { settings[key] = document.getElementById('si-'+key).value; });
        SMS_SECRET_KEYS.forEach(key => { settings[key] = document.getElementById('si-'+key).value; }); // blank = leave unchanged, enforced server-side
        const res = await postJson('../api/integration-center.php', { action: 'save_sms', settings });
        showTabAlert('sms-integration-alert', res);
        if (res.status === 'success') { SMS_SECRET_KEYS.forEach(key => { document.getElementById('si-'+key).value = ''; }); loadIntegrationCenter(); }
    }
    async function sendTestEmail(){
        const to = document.getElementById('ei-test-to').value.trim();
        if (!to) { alert('Enter an email address to send the test to.'); return; }
        const btn = document.getElementById('ei-test-btn'); btn.disabled = true; btn.textContent = 'Sending…';
        const res = await postJson('../api/integration-center.php', { action: 'test_email', to });
        btn.disabled = false; btn.textContent = 'Send Test Email';
        showTabAlert('ei-test-alert', res);
    }
    async function sendTestSms(){
        const to = document.getElementById('si-test-to').value.trim();
        if (!to) { alert('Enter a phone number to send the test to.'); return; }
        const btn = document.getElementById('si-test-btn'); btn.disabled = true; btn.textContent = 'Sending…';
        const res = await postJson('../api/integration-center.php', { action: 'test_sms', to });
        btn.disabled = false; btn.textContent = 'Send Test SMS';
        showTabAlert('si-test-alert', res);
    }
    const OTP_KEYS = ['otp_length','otp_ttl_minutes','otp_max_attempts','otp_max_resends','email_verification_ttl_minutes','email_resend_cooldown_seconds','reset_token_ttl_minutes'];
    const POLICY_KEYS = ['require_email_to_login','require_phone_to_login'];
    const VERIFICATION_KEYS = ['email_verification_enabled','phone_verification_enabled'];
    async function handleOtpIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        OTP_KEYS.forEach(key => { settings[key] = document.getElementById('oi-'+key).value; });
        const res = await postJson('../api/integration-center.php', { action: 'save_otp', settings });
        showTabAlert('otp-integration-alert', res);
    }
    async function handlePolicyIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        POLICY_KEYS.forEach(key => { settings[key] = document.getElementById('pi-'+key).checked; });
        const res = await postJson('../api/integration-center.php', { action: 'save_policy', settings });
        showTabAlert('policy-integration-alert', res);
    }
    async function handleVerificationIntegrationSubmit(event){
        event.preventDefault();
        const settings = {};
        VERIFICATION_KEYS.forEach(key => { settings[key] = document.getElementById('vi-'+key).checked; });
        const res = await postJson('../api/integration-center.php', { action: 'save_verification', settings });
        showTabAlert('verification-integration-alert', res);
    }
    async function loadDiagnostics(){
        const body = document.getElementById('diagnostics-body');
        body.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">Checking…</td></tr>';
        try {
            const res = await fetch('../api/integration-center.php?action=diagnostics');
            const data = await res.json();
            if (data.status !== 'success' || !data.data.length) { body.innerHTML = '<tr><td colspan="4" class="text-muted text-center py-3">No diagnostics available.</td></tr>'; return; }
            const badge = { healthy: 'bg-success', warning: 'bg-warning text-dark', offline: 'bg-danger' };
            body.innerHTML = data.data.map(row => `<tr><td class="fw-semibold">${escapeHtml(row.channel)}</td><td><span class="badge ${badge[row.status]||'bg-secondary'} text-capitalize">${escapeHtml(row.status)}</span></td><td class="small">${escapeHtml(row.detail)}</td><td class="small text-muted">${row.last_test ? new Date(row.last_test).toLocaleString() : 'Never'}</td></tr>`).join('');
        } catch (err) {
            body.innerHTML = '<tr><td colspan="4" class="text-danger text-center py-3">Failed to load diagnostics.</td></tr>';
        }
        loadAuditLog();
    }

    let auditPage = 1;
    async function loadAuditLog(page) {
        auditPage = page || auditPage || 1;
        const params = new URLSearchParams({
            action: 'get_audit_logs', page: auditPage, limit: 20,
            entity_type: document.getElementById('audit-filter-entity').value,
            action_filter: document.getElementById('audit-filter-action').value,
        });
        const res = await fetch(`../api/admin-api.php?${params}`);
        const data = await res.json();
        const body = document.getElementById('audit-body');
        if (data.status !== 'success') { body.innerHTML = '<tr><td colspan="6" class="text-danger text-center py-3">Failed to load audit log.</td></tr>'; return; }
        const rows = data.data.logs || [];
        body.innerHTML = rows.length ? rows.map(a => `
            <tr>
                <td>${escapeHtml(a.admin_name || 'Unknown')}</td>
                <td><span class="badge bg-light text-dark text-capitalize">${escapeHtml(a.action)}</span></td>
                <td>${escapeHtml(a.entity_type)} #${a.entity_id}</td>
                <td class="small">${a.old_status ? escapeHtml(a.old_status) + ' → ' : ''}${a.new_status ? escapeHtml(a.new_status) : ''}</td>
                <td class="small text-muted">${a.reason ? escapeHtml(a.reason) : '—'}</td>
                <td class="small text-muted">${new Date(a.created_at).toLocaleString()}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center py-3">No audit log entries match these filters.</td></tr>';
        renderPagination('audit-pagination', data.pagination, loadAuditLog);
    }
    document.getElementById('audit-filter-btn').addEventListener('click', () => loadAuditLog(1));

    // ---------------------------------------------------------------
    // Notification Management (Admin Notification Center)
    // ---------------------------------------------------------------
    const NC_CATEGORIES = ['ride','booking','trip','wallet','payment','verification','vehicle','admin','promotion','system','support','premium','gps'];
    const NC_CHANNELS = [['email','Email'],['sms','SMS'],['in_app','In-App'],['browser','Browser']];
    let ncSelectedUsers = [];
    let ncPage = 1;
    const NC_LIMIT = 20;

    function showTabAlertEl(el, res) {
        if (!el) return;
        el.classList.remove('d-none', 'alert-success', 'alert-danger');
        el.classList.add(res.status === 'success' ? 'alert-success' : 'alert-danger');
        el.textContent = res.message || (res.status === 'success' ? 'Saved.' : 'Something went wrong.');
    }

    document.getElementById('announcement-target').addEventListener('change', (e) => {
        const wrap = document.getElementById('announcement-user-picker-wrap');
        const needsPicker = e.target.value === 'single' || e.target.value === 'multiple';
        wrap.classList.toggle('d-none', !needsPicker);
        if (!needsPicker) { ncSelectedUsers = []; renderNcUserChips(); }
    });

    function renderNcUserChips() {
        const el = document.getElementById('announcement-user-chips');
        el.innerHTML = ncSelectedUsers.map(u => `<span class="badge bg-secondary">${u.fullname} <a href="#" class="text-white text-decoration-none ms-1" data-remove-user="${u.id}">&times;</a></span>`).join('');
    }
    document.getElementById('announcement-user-chips').addEventListener('click', (e) => {
        const removeId = e.target.closest('[data-remove-user]')?.dataset.removeUser;
        if (!removeId) return;
        e.preventDefault();
        ncSelectedUsers = ncSelectedUsers.filter(u => String(u.id) !== removeId);
        renderNcUserChips();
    });

    let ncUserSearchTimer = null;
    document.getElementById('announcement-user-search').addEventListener('input', (e) => {
        clearTimeout(ncUserSearchTimer);
        const q = e.target.value.trim();
        const resultsEl = document.getElementById('announcement-user-results');
        if (q.length < 2) { resultsEl.innerHTML = ''; return; }
        ncUserSearchTimer = setTimeout(async () => {
            const res = await fetch(`../api/admin-notifications.php?action=search_users&q=${encodeURIComponent(q)}`);
            const data = await res.json();
            const users = (data.data && data.data.users) || [];
            resultsEl.innerHTML = users.map(u => `<a href="#" class="list-group-item list-group-item-action py-1" data-pick-user='${JSON.stringify(u)}'>${u.fullname} <span class="text-muted small">${u.email} · ${u.user_type}</span></a>`).join('') || '<div class="text-muted small px-2 py-1">No matches</div>';
        }, 250);
    });
    document.getElementById('announcement-user-results').addEventListener('click', (e) => {
        const link = e.target.closest('[data-pick-user]');
        if (!link) return;
        e.preventDefault();
        const user = JSON.parse(link.dataset.pickUser);
        const target = document.getElementById('announcement-target').value;
        if (target === 'single') ncSelectedUsers = [user];
        else if (!ncSelectedUsers.find(u => u.id === user.id)) ncSelectedUsers.push(user);
        renderNcUserChips();
        document.getElementById('announcement-user-search').value = '';
        document.getElementById('announcement-user-results').innerHTML = '';
    });

    async function handleAnnouncementSubmit(event) {
        event.preventDefault();
        const target = document.getElementById('announcement-target').value;
        const priority = document.getElementById('announcement-priority').value;
        // Priority Management System: critical broadcasts reach every
        // recipient regardless of their notification preferences (same
        // rule create_notification() enforces server-side) — a plain
        // confirm() gate is the simplest way to make an admin pause
        // before firing one to a wide audience.
        if (priority === 'critical' && !confirm('Are you sure you want to send a critical notification? It will bypass every recipient\'s notification preferences and cannot be undone.')) {
            return;
        }
        const payload = {
            action: 'send_announcement',
            target_type: target,
            title: document.getElementById('announcement-title').value,
            message: document.getElementById('announcement-message').value,
            priority,
        };
        const schedule = document.getElementById('announcement-schedule').value;
        if (schedule) payload.scheduled_at = schedule.replace('T', ' ') + ':00';
        if (target === 'single') payload.user_id = ncSelectedUsers[0]?.id;
        if (target === 'multiple') payload.user_ids = ncSelectedUsers.map(u => u.id);

        const res = await sendAction(payload);
        showTabAlertEl(document.getElementById('announcement-alert'), res);
        if (res.status === 'success') {
            document.getElementById('announcement-form').reset();
            ncSelectedUsers = [];
            renderNcUserChips();
            document.getElementById('announcement-user-picker-wrap').classList.add('d-none');
            loadNcBroadcasts();
        }
    }

    async function loadNcTable(page) {
        ncPage = page || ncPage || 1;
        const params = new URLSearchParams({
            action: 'list', page: ncPage, limit: NC_LIMIT,
            search: document.getElementById('nc-search').value,
            notification_type: document.getElementById('nc-filter-type').value,
            priority: document.getElementById('nc-filter-priority').value,
            user_type: document.getElementById('nc-filter-usertype').value,
        });
        const res = await fetch(`../api/admin-notifications.php?${params}`);
        const data = await res.json();
        const rows = (data.data && data.data.notifications) || [];
        const tbody = document.getElementById('nc-table-body');
        tbody.innerHTML = rows.length ? rows.map(n => `
            <tr>
                <td>${n.user_name || '—'}<br><span class="text-muted small">${n.user_type || ''}</span></td>
                <td>${n.title ? `<strong>${n.title}</strong><br>` : ''}<span class="small">${(n.message || '').slice(0, 80)}</span></td>
                <td><span class="badge bg-light text-dark">${n.notification_type}</span></td>
                <td><span class="badge ${n.priority === 'critical' ? 'bg-danger' : n.priority === 'high' ? 'bg-warning text-dark' : n.priority === 'low' ? 'bg-secondary-subtle text-secondary' : 'bg-light text-dark'}">${n.priority}</span></td>
                <td>${n.is_read ? '<i class="fas fa-check text-success"></i>' : '<span class="text-muted">unread</span>'}</td>
                <td class="small text-muted">${new Date(n.created_at).toLocaleString()}</td>
                <td class="text-end">
                    <button class="btn btn-sm btn-outline-secondary" type="button" data-resend="${n.public_id}" title="Resend"><i class="fas fa-rotate-right"></i></button>
                    <button class="btn btn-sm btn-outline-danger" type="button" data-delete-notif="${n.public_id}" title="Delete"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`).join('') : '<tr><td colspan="7" class="text-muted text-center py-3">No notifications match these filters.</td></tr>';
        renderPagination('nc-pagination', data.pagination, loadNcTable);
    }
    document.getElementById('nc-filter-btn').addEventListener('click', () => loadNcTable(1));
    document.getElementById('nc-table-body').addEventListener('click', async (e) => {
        const resendId = e.target.closest('[data-resend]')?.dataset.resend;
        const deleteId = e.target.closest('[data-delete-notif]')?.dataset.deleteNotif;
        if (resendId) {
            const res = await postJson('../api/admin-notifications.php', { action: 'resend', public_id: resendId });
            alert(res.message || (res.status === 'success' ? 'Resent.' : 'Could not resend.'));
        } else if (deleteId) {
            if (!confirm('Delete this notification?')) return;
            await postJson('../api/admin-notifications.php', { action: 'delete', public_id: deleteId });
            loadNcTable();
        }
    });

    async function loadNcBroadcasts() {
        const res = await fetch('../api/admin-notifications.php?action=get_broadcasts');
        const data = await res.json();
        const rows = (data.data && data.data.broadcasts) || [];
        document.getElementById('nc-broadcast-body').innerHTML = rows.length ? rows.map(b => `
            <tr>
                <td>${b.admin_name || '—'}</td>
                <td><span class="badge bg-light text-dark">${b.target_type}</span></td>
                <td class="small">${b.title ? `<strong>${b.title}</strong><br>` : ''}${(b.message || '').slice(0, 80)}</td>
                <td>${b.recipient_count}</td>
                <td><span class="badge ${b.status === 'sent' ? 'bg-success' : b.status === 'scheduled' ? 'bg-info text-dark' : 'bg-danger'}">${b.status}</span></td>
                <td class="small text-muted">${new Date(b.created_at).toLocaleString()}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center py-3">No broadcasts sent yet.</td></tr>';
    }

    async function loadNcSettings() {
        const res = await fetch('../api/admin-notifications.php?action=get_settings');
        const data = await res.json();
        const settings = (data.data && data.data.settings) || {};

        document.getElementById('nc-category-toggles').innerHTML = NC_CATEGORIES.map(cat => `
            <div class="col-md-3 col-6 form-check">
                <input class="form-check-input" type="checkbox" id="nc-cat-${cat}" ${settings[`notify_category_${cat}_enabled`] == '1' ? 'checked' : ''}>
                <label class="form-check-label text-capitalize" for="nc-cat-${cat}">${cat}</label>
            </div>`).join('');
        document.getElementById('nc-channel-toggles').innerHTML = NC_CHANNELS.map(([key, label]) => `
            <div class="col-md-3 col-6 form-check">
                <input class="form-check-input" type="checkbox" id="nc-chan-${key}" ${settings[`notify_channel_${key}_enabled`] == '1' ? 'checked' : ''}>
                <label class="form-check-label" for="nc-chan-${key}">${label}</label>
            </div>`).join('');

        document.getElementById('nc-notification_retention_days').value = settings.notification_retention_days ?? 90;
        document.getElementById('nc-notification_polling_interval_seconds').value = settings.notification_polling_interval_seconds ?? 30;
        document.getElementById('nc-notification_max_per_user').value = settings.notification_max_per_user ?? 500;
        document.getElementById('nc-notification_auto_delete_old').checked = settings.notification_auto_delete_old == '1';
    }

    document.getElementById('nc-settings-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const settings = {};
        NC_CATEGORIES.forEach(cat => { settings[`notify_category_${cat}_enabled`] = document.getElementById(`nc-cat-${cat}`).checked ? 1 : 0; });
        NC_CHANNELS.forEach(([key]) => { settings[`notify_channel_${key}_enabled`] = document.getElementById(`nc-chan-${key}`).checked ? 1 : 0; });
        settings.notification_retention_days = document.getElementById('nc-notification_retention_days').value;
        settings.notification_polling_interval_seconds = document.getElementById('nc-notification_polling_interval_seconds').value;
        settings.notification_max_per_user = document.getElementById('nc-notification_max_per_user').value;
        settings.notification_auto_delete_old = document.getElementById('nc-notification_auto_delete_old').checked ? 1 : 0;

        const res = await postJson('../api/admin-notifications.php', { action: 'update_settings', settings });
        showTabAlertEl(document.getElementById('nc-settings-alert'), res);
    });

    function loadNotificationCenter() {
        loadNcTable();
        loadNcBroadcasts();
        loadNcSettings();
    }

    // ---------------------------------------------------------------
    // Ride Queue Management (SYSTEM 2 — full-ride seat queue; distinct
    // from Ride Request Management/SYSTEM 1 below)
    // ---------------------------------------------------------------
    let wlPage = 1;
    async function loadWaitlistCenter(page) {
        wlPage = page || wlPage || 1;
        const params = new URLSearchParams({
            action: 'admin_overview', page: wlPage, limit: 20,
            route: document.getElementById('wl-filter-route').value,
            date: document.getElementById('wl-filter-date').value,
            status: document.getElementById('wl-filter-status').value,
        });
        const res = await fetch(`../api/ride-queue.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') return;

        const { entries, popular_routes, conversion_rate, total_entries, converted_count } = data.data;

        document.getElementById('wl-conversion-rate').textContent = `${conversion_rate}%`;
        document.getElementById('wl-total-entries').textContent = total_entries;
        document.getElementById('wl-converted-count').textContent = converted_count;

        document.getElementById('wl-routes-body').innerHTML = popular_routes.length
            ? popular_routes.map(r => `<tr><td>${escapeHtml(r.start_location)} → ${escapeHtml(r.destination)}</td><td>${r.queue_count}</td></tr>`).join('')
            : '<tr><td colspan="2" class="text-muted text-center py-3">No ride-queue demand recorded yet.</td></tr>';

        const statusBadge = { WAITING: 'bg-secondary', NOTIFIED: 'bg-info text-dark', RESERVED: 'bg-primary', CONVERTED: 'bg-success', EXPIRED: 'bg-warning text-dark', CANCELLED: 'bg-light text-dark' };
        document.getElementById('wl-entries-body').innerHTML = entries.length ? entries.map(e => `
            <tr>
                <td>${escapeHtml(e.user_name)}<br><span class="text-muted small">${escapeHtml(e.user_email)}</span></td>
                <td>${escapeHtml(e.start_location)} → ${escapeHtml(e.destination)}</td>
                <td>${e.departure_date}</td>
                <td>${e.seats_requested}</td>
                <td><span class="badge ${statusBadge[e.status] || 'bg-light text-dark'}">${e.status}</span></td>
                <td class="small text-muted">${new Date(e.joined_at).toLocaleString()}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center py-3">No ride-queue entries match these filters.</td></tr>';
        renderPagination('wl-entries-pagination', data.pagination, loadWaitlistCenter);
    }
    document.getElementById('wl-filter-btn').addEventListener('click', () => loadWaitlistCenter(1));

    // ---------------------------------------------------------------
    // Ride Request Management (SYSTEM 1 — passenger demand; distinct
    // from Ride Queue Management above, which is SYSTEM 2)
    // ---------------------------------------------------------------
    let rrPage = 1;
    async function loadRideRequestsList(page) {
        rrPage = page || rrPage || 1;
        const params = new URLSearchParams({
            action: 'admin_list', page: rrPage, limit: 20,
            route: document.getElementById('rr-filter-route').value,
            date: document.getElementById('rr-filter-date').value,
            status: document.getElementById('rr-filter-status').value,
        });
        const res = await fetch(`../api/ride-requests.php?${params}`);
        const data = await res.json();
        if (data.status !== 'success') return;

        const statusBadge = { OPEN: 'bg-warning text-dark', MATCHED: 'bg-primary', FULFILLED: 'bg-success', EXPIRED: 'bg-light text-dark', CANCELLED: 'bg-danger' };
        const rows = data.data.requests || [];
        document.getElementById('rr-entries-body').innerHTML = rows.length ? rows.map(r => `
            <tr>
                <td>${escapeHtml(r.user_name)}<br><span class="text-muted small">${escapeHtml(r.user_email)}</span></td>
                <td>${escapeHtml(r.pickup_location)} → ${escapeHtml(r.destination)}</td>
                <td>${r.travel_date}${r.travel_time ? ' ' + r.travel_time.slice(0, 5) : ''}</td>
                <td>${r.passenger_count}</td>
                <td><span class="badge ${statusBadge[r.status] || 'bg-light text-dark'}">${r.status}</span></td>
                <td class="small text-muted">${new Date(r.created_at).toLocaleString()}</td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center py-3">No ride requests match these filters.</td></tr>';
        renderPagination('rr-entries-pagination', data.pagination, loadRideRequestsList);
    }
    document.getElementById('rr-filter-btn').addEventListener('click', () => loadRideRequestsList(1));

    async function loadRideRequestsStats() {
        const res = await fetch('../api/ride-requests.php?action=statistics');
        const data = await res.json();
        if (data.status !== 'success') return;

        const counts = data.data.status_counts;
        document.getElementById('rr-open-count').textContent = counts.open;
        document.getElementById('rr-matched-count').textContent = counts.matched;
        document.getElementById('rr-fulfilled-count').textContent = counts.fulfilled;
        document.getElementById('rr-expired-count').textContent = counts.expired;
        document.getElementById('rr-cancelled-count').textContent = counts.cancelled;
        document.getElementById('rr-total-count').textContent = counts.total;

        const popularRoutes = data.data.popular_routes || [];
        document.getElementById('rr-popular-routes-body').innerHTML = popularRoutes.length
            ? popularRoutes.map(r => `<tr><td>${escapeHtml(r.pickup_location)} → ${escapeHtml(r.destination)}</td><td>${r.request_count}</td></tr>`).join('')
            : '<tr><td colspan="2" class="text-muted text-center py-3">No requests recorded yet.</td></tr>';

        const unserved = data.data.unserved_demand || [];
        document.getElementById('rr-unserved-body').innerHTML = unserved.length
            ? unserved.map(r => `<tr><td>${escapeHtml(r.pickup_location)} → ${escapeHtml(r.destination)}</td><td>${r.travel_date}</td><td>${r.request_count}</td></tr>`).join('')
            : '<tr><td colspan="3" class="text-muted text-center py-3">No unserved demand right now.</td></tr>';
    }

    function loadRideRequestsCenter() {
        loadRideRequestsStats();
        loadRideRequestsList(1);
    }

    // ---------------------------------------------------------------
    // Premium Features (Part 2 admin side + Part 5 approval lifecycle)
    // ---------------------------------------------------------------
    let pfRequestsPage = 1;

    async function loadPremiumCenter() {
        await loadPfFeatures();
        await loadPfRequests(1);
    }

    async function loadPfFeatures() {
        const res = await fetch('../api/premium.php?action=features');
        const data = await res.json();
        if (data.status !== 'success') return;
        document.getElementById('pf-features-body').innerHTML = data.data.features.length ? data.data.features.map(f => `
            <tr>
                <td>${escapeHtml(f.feature_name)}<br><span class="text-muted small">${escapeHtml(f.description || '')}</span></td>
                <td>₦${Number(f.price).toLocaleString()}</td>
                <td>${f.duration_days} days</td>
                <td><span class="badge ${f.status === 'active' ? 'bg-success' : 'bg-secondary'}">${f.status}</span></td>
                <td class="text-end"><button class="btn btn-sm btn-outline-secondary" type="button" data-edit-feature='${JSON.stringify(f)}'>Edit</button></td>
            </tr>`).join('') : '<tr><td colspan="5" class="text-muted text-center py-3">No premium features yet.</td></tr>';
    }

    document.getElementById('pf-new-feature-btn').addEventListener('click', () => {
        document.getElementById('pf-feature-form').classList.remove('d-none');
        document.getElementById('pf-feature-id').value = '';
        document.getElementById('pf-feature-form').reset();
    });
    document.getElementById('pf-features-body').addEventListener('click', e => {
        const btn = e.target.closest('[data-edit-feature]');
        if (!btn) return;
        const f = JSON.parse(btn.dataset.editFeature);
        document.getElementById('pf-feature-form').classList.remove('d-none');
        document.getElementById('pf-feature-id').value = f.id;
        document.getElementById('pf-feature-name').value = f.feature_name;
        document.getElementById('pf-feature-price').value = f.price;
        document.getElementById('pf-feature-duration').value = f.duration_days;
        document.getElementById('pf-feature-description').value = f.description || '';
        document.getElementById('pf-feature-status').value = f.status;
    });
    document.getElementById('pf-feature-form').addEventListener('submit', async e => {
        e.preventDefault();
        const res = await postJson('../api/premium.php', {
            action: 'save_feature',
            id: document.getElementById('pf-feature-id').value,
            feature_name: document.getElementById('pf-feature-name').value,
            price: document.getElementById('pf-feature-price').value,
            duration_days: document.getElementById('pf-feature-duration').value,
            description: document.getElementById('pf-feature-description').value,
            status: document.getElementById('pf-feature-status').value,
        });
        showTabAlert('pf-feature-alert', res);
        if (res.status === 'success') { document.getElementById('pf-feature-form').classList.add('d-none'); loadPfFeatures(); }
    });

    async function loadPfRequests(page) {
        pfRequestsPage = page;
        const params = new URLSearchParams({ page, limit: 20, status: document.getElementById('pf-filter-status').value });
        const res = await fetch(`../api/premium.php?action=admin_list&${params}`);
        const data = await res.json();
        if (data.status !== 'success') return;

        const statusBadge = { pending: 'bg-secondary', approved: 'bg-success', rejected: 'bg-danger', disabled: 'bg-warning text-dark' };
        document.getElementById('pf-requests-body').innerHTML = data.data.requests.length ? data.data.requests.map(r => `
            <tr>
                <td>${escapeHtml(r.driver_name)}<br><span class="text-muted small">${escapeHtml(r.driver_email)}</span></td>
                <td>${escapeHtml(r.feature_name)}<br><span class="text-muted small">₦${Number(r.price).toLocaleString()}</span></td>
                <td><span class="badge ${statusBadge[r.status] || 'bg-light text-dark'}">${r.status}</span></td>
                <td class="small text-muted">${new Date(r.created_at).toLocaleDateString()}</td>
                <td class="small text-muted">${r.expires_at ? new Date(r.expires_at).toLocaleDateString() : '—'}</td>
                <td class="text-end text-nowrap">
                    ${r.status === 'pending' ? `<button class="btn btn-sm btn-success" data-pf-approve="${r.public_id}">Approve</button> <button class="btn btn-sm btn-outline-danger" data-pf-reject="${r.public_id}">Reject</button>` : ''}
                    ${r.status === 'approved' ? `<button class="btn btn-sm btn-outline-warning" data-pf-disable="${r.public_id}">Disable</button>` : ''}
                    <button class="btn btn-sm btn-outline-danger" data-pf-delete="${r.public_id}"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`).join('') : '<tr><td colspan="6" class="text-muted text-center py-3">No premium requests match this filter.</td></tr>';

        renderPagination('pf-requests-pagination', data.pagination, loadPfRequests);
    }
    document.getElementById('pf-filter-btn').addEventListener('click', () => loadPfRequests(1));
    document.getElementById('pf-requests-body').addEventListener('click', async e => {
        const approveBtn = e.target.closest('[data-pf-approve]');
        const rejectBtn = e.target.closest('[data-pf-reject]');
        const disableBtn = e.target.closest('[data-pf-disable]');
        const deleteBtn = e.target.closest('[data-pf-delete]');

        if (approveBtn) {
            const res = await postJson('../api/premium.php', { action: 'approve', request_public_id: approveBtn.dataset.pfApprove });
            if (res.status === 'success') loadPfRequests(pfRequestsPage); else alert(res.message);
        } else if (rejectBtn) {
            const reason = prompt('Reason for rejecting this premium request (required):');
            if (reason === null) return;
            if (reason.trim() === '') { alert('A reason is required.'); return; }
            const res = await postJson('../api/premium.php', { action: 'reject', request_public_id: rejectBtn.dataset.pfReject, reason });
            if (res.status === 'success') loadPfRequests(pfRequestsPage); else alert(res.message);
        } else if (disableBtn) {
            if (!confirm('Disable this driver\'s access to this premium feature?')) return;
            const res = await postJson('../api/premium.php', { action: 'disable', request_public_id: disableBtn.dataset.pfDisable });
            if (res.status === 'success') loadPfRequests(pfRequestsPage); else alert(res.message);
        } else if (deleteBtn) {
            if (!confirm('Permanently delete this premium request? This cannot be undone.')) return;
            const res = await postJson('../api/premium.php', { action: 'delete_request', request_public_id: deleteBtn.dataset.pfDelete });
            if (res.status === 'success') loadPfRequests(pfRequestsPage); else alert(res.message);
        }
    });
    // ==================================================================
    // Administrators (Super-Admin-only) — create/manage Subadmins, change
    // their permissions, suspend/reactivate, and view their audit activity.
    // Every call hits api/admin-management.php, which re-verifies Super Admin
    // on EVERY request; hiding this tab from non-supers is cosmetic only. The
    // permission catalog is fetched from the server (single source of truth)
    // and rendered as grouped checkboxes — money/config writes are never in
    // that list, so this UI cannot grant a subadmin a money-moving action.
    // Action buttons pass ONLY numeric ids inline; names are looked up from
    // adminsById, so no user-controlled text ever enters an onclick string.
    // ==================================================================
    let adminCatalog = null;        // {Group: {perm.key: label}} — cached after first fetch
    let adminsById = {};            // id -> row from the last list load
    let _adminMgmtModalInst = null;
    function adminMgmtModal(){ if(!_adminMgmtModalInst){ const el=document.getElementById('adminMgmtModal'); if(!el) return null; _adminMgmtModalInst=new bootstrap.Modal(el); } const b=document.getElementById('adminMgmtSaveBtn'); if(b){ b.style.display=''; b.disabled=false; } return _adminMgmtModalInst; }
    function showAdminsAlert(kind, text){ const el=document.getElementById('admins-alert'); if(!el) return; el.className='alert alert-'+(kind==='success'?'success':'danger'); el.textContent=text||''; el.classList.remove('d-none'); setTimeout(()=>el.classList.add('d-none'), 4500); }
    async function ensureAdminCatalog(){ if(adminCatalog) return; const res=await fetch('../api/admin-management.php?action=catalog'); const data=await res.json(); if(data.status==='success') adminCatalog=data.data.catalog||{}; }
    function permissionCheckboxesHtml(selected){ const sel=new Set(selected||[]); return Object.entries(adminCatalog||{}).map(([group,perms])=>{ const items=Object.entries(perms).map(([key,label])=>`<div class="form-check"><input class="form-check-input adm-perm" type="checkbox" value="${escapeHtml(key)}" id="perm-${escapeHtml(key)}"${sel.has(key)?' checked':''}><label class="form-check-label" for="perm-${escapeHtml(key)}">${escapeHtml(label)} <span class="text-muted small">(${escapeHtml(key)})</span></label></div>`).join(''); return `<div class="mb-3"><div class="fw-semibold small text-uppercase text-muted mb-1">${escapeHtml(group)}</div>${items}</div>`; }).join(''); }
    function collectSelectedPermissions(){ return Array.from(document.querySelectorAll('#adminMgmtModalBody .adm-perm:checked')).map(cb=>cb.value); }

    async function loadAdminsTab(){ const body=document.getElementById('admins-body'); if(!body) return; body.innerHTML='<tr><td colspan="6" class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></td></tr>'; const res=await fetch('../api/admin-management.php?action=list'); const data=await res.json(); if(data.status!=='success'){ body.innerHTML=`<tr><td colspan="6" class="text-danger text-center py-3">${escapeHtml(data.message||'Unable to load administrators.')}</td></tr>`; return; } renderAdminsTable(data.data.admins||[]); }
    function renderAdminsTable(admins){ const body=document.getElementById('admins-body'); if(!body) return; adminsById={}; admins.forEach(a=>{ adminsById[a.id]=a; }); if(!admins.length){ body.innerHTML='<tr><td colspan="6" class="text-muted text-center py-3">No administrators found.</td></tr>'; return; } body.innerHTML=admins.map(a=>{ const isSuper=String(a.is_super_admin)==='1'||a.is_super_admin===true; const suspended=a.admin_status==='suspended'; const ut=a.user_type||''; const multiRole= ut==='driver' ? 'Driver' : (ut==='rider' ? 'Rider' : ''); let roleBadge=isSuper?'<span class="badge bg-primary">Super Admin</span>':'<span class="badge bg-secondary">Subadmin</span>'; if(!isSuper && multiRole){ roleBadge+=` <span class="badge bg-info text-dark">+ ${multiRole}</span>`; } const statusBadge=suspended?'<span class="badge bg-danger">Suspended</span>':'<span class="badge bg-success">Active</span>'; let actions=`<button class="btn btn-sm btn-outline-info me-1 mb-1" onclick="openAdminActivity(${a.id})">Activity</button>`; if(!isSuper){ actions+=`<button class="btn btn-sm btn-outline-secondary me-1 mb-1" onclick="openEditSubadmin(${a.id})">Edit</button>`; actions+=`<button class="btn btn-sm btn-outline-primary me-1 mb-1" onclick="openChangePermissions(${a.id})">Permissions</button>`; if(multiRole){ actions+=`<button class="btn btn-sm btn-outline-warning me-1 mb-1" onclick="demoteAdmin(${a.id})">Remove Role</button>`; } actions+= suspended ? `<button class="btn btn-sm btn-outline-success mb-1" onclick="setAdminSuspension(${a.id},false)">Reactivate</button>` : `<button class="btn btn-sm btn-outline-danger mb-1" onclick="setAdminSuspension(${a.id},true)">Suspend</button>`; } else if(!a.is_self){ actions+= suspended ? `<button class="btn btn-sm btn-outline-success mb-1" onclick="setAdminSuspension(${a.id},false)">Reactivate</button>` : `<button class="btn btn-sm btn-outline-danger mb-1" onclick="setAdminSuspension(${a.id},true)">Suspend</button>`; } const perms=isSuper?'All access':((a.permission_count||0)+' granted'); return `<tr><td>${escapeHtml(a.fullname||'')}${a.is_self?' <span class="badge bg-light text-dark border">You</span>':''}</td><td class="small text-muted">${escapeHtml(a.email||'')}<br>${escapeHtml(a.phone||'')}</td><td>${roleBadge}</td><td>${statusBadge}</td><td class="small">${perms}</td><td class="text-nowrap">${actions}</td></tr>`; }).join(''); }

    async function adminMgmtSubmit(payload, successMsg){ const msg=document.getElementById('adminMgmtModalMsg'); const btn=document.getElementById('adminMgmtSaveBtn'); if(btn) btn.disabled=true; if(msg){ msg.className='me-auto small text-muted'; msg.textContent='Saving…'; } const res=await postJson('../api/admin-management.php', payload); if(btn) btn.disabled=false; if(res.status==='success'){ const m=adminMgmtModal(); if(m) m.hide(); showAdminsAlert('success', res.message||successMsg); loadAdminsTab(); } else if(msg){ msg.className='me-auto small text-danger'; msg.textContent=res.message||'Action failed.'; } }

    async function openCreateSubadmin(){ await ensureAdminCatalog(); document.getElementById('adminMgmtModalTitle').textContent='Create Subadmin'; const msg=document.getElementById('adminMgmtModalMsg'); if(msg) msg.textContent=''; document.getElementById('adminMgmtModalBody').innerHTML=`<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label">Full name</label><input type="text" class="form-control" id="adm-fullname"></div><div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" id="adm-email"></div><div class="col-md-6"><label class="form-label">Phone</label><input type="text" class="form-control" id="adm-phone"></div><div class="col-md-6"><label class="form-label">Password</label><input type="password" class="form-control" id="adm-password"></div></div><div class="fw-semibold mb-1">Permissions</div><p class="text-muted small mb-2">Grant only what this Subadmin needs. Money and configuration writes stay Super-Admin-only and never appear here.</p>${permissionCheckboxesHtml([])}`; const btn=document.getElementById('adminMgmtSaveBtn'); btn.textContent='Create Subadmin'; btn.onclick=()=>adminMgmtSubmit({ action:'create_subadmin', fullname:document.getElementById('adm-fullname').value.trim(), email:document.getElementById('adm-email').value.trim(), phone:document.getElementById('adm-phone').value.trim(), password:document.getElementById('adm-password').value, permissions:collectSelectedPermissions() }, 'Subadmin created.'); const m=adminMgmtModal(); if(m) m.show(); }

    async function openEditSubadmin(userId){ const res=await fetch(`../api/admin-management.php?action=get_admin&user_id=${encodeURIComponent(userId)}`); const data=await res.json(); if(data.status!=='success'){ showAdminsAlert('error', data.message||'Could not load administrator.'); return; } const a=data.data.admin; document.getElementById('adminMgmtModalTitle').textContent='Edit Subadmin'; const msg=document.getElementById('adminMgmtModalMsg'); if(msg) msg.textContent=''; document.getElementById('adminMgmtModalBody').innerHTML=`<div class="row g-3"><div class="col-md-6"><label class="form-label">Full name</label><input type="text" class="form-control" id="adm-fullname" value="${escapeHtml(a.fullname||'')}"></div><div class="col-md-6"><label class="form-label">Email</label><input type="email" class="form-control" id="adm-email" value="${escapeHtml(a.email||'')}"></div><div class="col-md-6"><label class="form-label">Phone</label><input type="text" class="form-control" id="adm-phone" value="${escapeHtml(a.phone||'')}"></div><div class="col-md-6"><label class="form-label">New password <span class="text-muted small">(leave blank to keep)</span></label><input type="password" class="form-control" id="adm-password"></div></div>`; const btn=document.getElementById('adminMgmtSaveBtn'); btn.textContent='Save Changes'; btn.onclick=()=>adminMgmtSubmit({ action:'edit_subadmin', user_id:userId, fullname:document.getElementById('adm-fullname').value.trim(), email:document.getElementById('adm-email').value.trim(), phone:document.getElementById('adm-phone').value.trim(), password:document.getElementById('adm-password').value }, 'Subadmin updated.'); const m=adminMgmtModal(); if(m) m.show(); }

    async function openChangePermissions(userId){ await ensureAdminCatalog(); const res=await fetch(`../api/admin-management.php?action=get_admin&user_id=${encodeURIComponent(userId)}`); const data=await res.json(); if(data.status!=='success'){ showAdminsAlert('error', data.message||'Could not load administrator.'); return; } document.getElementById('adminMgmtModalTitle').textContent='Change Permissions'; const msg=document.getElementById('adminMgmtModalMsg'); if(msg) msg.textContent=''; document.getElementById('adminMgmtModalBody').innerHTML=`<p class="text-muted small mb-2">Editing permissions for <strong>${escapeHtml(data.data.admin.fullname||'')}</strong>. Changes take effect on their next request — no re-login required.</p>${permissionCheckboxesHtml(data.data.permissions||[])}`; const btn=document.getElementById('adminMgmtSaveBtn'); btn.textContent='Save Permissions'; btn.onclick=()=>adminMgmtSubmit({ action:'set_permissions', user_id:userId, permissions:collectSelectedPermissions() }, 'Permissions updated.'); const m=adminMgmtModal(); if(m) m.show(); }

    async function setAdminSuspension(userId, suspend){ if(!confirm(suspend?"Suspend this admin's access? Their rider/driver login (if any) is unaffected, and all records are preserved.":'Reactivate this admin?')) return; const res=await postJson('../api/admin-management.php', { action: suspend?'suspend_admin':'reactivate_admin', user_id:userId }); if(res.status==='success'){ showAdminsAlert('success', res.message||(suspend?'Admin suspended.':'Admin reactivated.')); loadAdminsTab(); } else showAdminsAlert('error', res.message||'Action failed.'); }

    // ---- Add Existing User → Subadmin (multi-role promotion) --------------
    // Promotes an EXISTING rider/driver by searching for their real account
    // and calling promote_admin — it NEVER inserts a user, so an existing
    // driver's email is promoted instead of raising "Email already registered".
    // The shared footer Save button is hidden until a promotable account is
    // found (adminMgmtModal() re-shows/enables it for every other modal).
    let _promoteFound = null; // last search result that is eligible to promote
    async function openPromoteExisting(){
        await ensureAdminCatalog();
        _promoteFound = null;
        document.getElementById('adminMgmtModalTitle').textContent='Add Existing User as Subadmin';
        const msg=document.getElementById('adminMgmtModalMsg'); if(msg) msg.textContent='';
        document.getElementById('adminMgmtModalBody').innerHTML=
            `<p class="text-muted small mb-2">Search for an existing Rider or Driver by email, phone, or user ID. Their account is promoted in place — no new account is created and their existing role stays unchanged.</p>`+
            `<div class="input-group mb-3"><input type="text" class="form-control" id="promote-search-input" placeholder="Email, phone number, or user ID" autocomplete="off">`+
            `<button class="btn btn-outline-primary" type="button" onclick="searchExistingUser()"><i class="fas fa-search me-1"></i>Search</button></div>`+
            `<div id="promote-result"></div>`;
        const m=adminMgmtModal();
        const btn=document.getElementById('adminMgmtSaveBtn'); if(btn){ btn.style.display='none'; btn.textContent='Promote to Subadmin'; }
        const inp=document.getElementById('promote-search-input');
        if(inp) inp.addEventListener('keydown', e=>{ if(e.key==='Enter'){ e.preventDefault(); searchExistingUser(); } });
        if(m) m.show();
        if(inp) setTimeout(()=>inp.focus(), 350);
    }

    async function searchExistingUser(){
        const inp=document.getElementById('promote-search-input'); const out=document.getElementById('promote-result'); const btn=document.getElementById('adminMgmtSaveBtn');
        if(!inp||!out) return;
        const q=inp.value.trim();
        if(!q){ out.innerHTML='<div class="alert alert-warning mb-0">Enter an email, phone number, or user ID.</div>'; return; }
        _promoteFound=null; if(btn) btn.style.display='none';
        out.innerHTML='<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';
        const res=await fetch(`../api/admin-management.php?action=search_user&q=${encodeURIComponent(q)}`);
        const data=await res.json();
        if(data.status!=='success'){ out.innerHTML=`<div class="alert alert-danger mb-0">${escapeHtml(data.message||'No account found.')}</div>`; return; }
        renderPromoteResult(data.data);
    }

    // Renders the found account (no secrets — the server sends only profile +
    // driver/vehicle/verification context) and decides the available action:
    // already an admin → manage permissions; a rider/driver → promote; else a
    // blocking notice.
    function renderPromoteResult(d){
        const out=document.getElementById('promote-result'); const btn=document.getElementById('adminMgmtSaveBtn'); if(!out) return;
        const u=d.user||{}; const v=d.vehicle||null;
        const yes='<span class="badge bg-success">Yes</span>', no='<span class="badge bg-secondary">No</span>';
        const rows=[];
        rows.push(['Name', escapeHtml(u.fullname||'—')]);
        rows.push(['Email', escapeHtml(u.email||'—')+' '+(u.email_verified?yes:no)]);
        rows.push(['Phone', escapeHtml(u.phone||'—')+' '+(u.phone_verified?yes:no)]);
        rows.push(['User ID', escapeHtml(u.public_id||('#'+u.id))]);
        rows.push(['Account type', escapeHtml((u.user_type||'').charAt(0).toUpperCase()+(u.user_type||'').slice(1))]);
        rows.push(['Admin status', u.is_admin ? (u.is_super_admin?'<span class="badge bg-primary">Super Admin</span>':'<span class="badge bg-secondary">Subadmin</span>')+' ('+escapeHtml(u.admin_status||'active')+')' : '<span class="text-muted">Not an admin</span>']);
        rows.push(['Account suspended', u.is_suspended?'<span class="badge bg-danger">Yes</span>':no]);
        if(u.user_type==='driver'){
            rows.push(['Driver verified', u.is_verified?yes:no]);
            rows.push(['Driver tier', escapeHtml(u.driver_tier||'standard')]);
            if(v){
                rows.push(['Vehicle', escapeHtml(((v.make||'')+' '+(v.model||'')+' '+(v.year||'')).trim()||'—')+(v.vehicle_type_label?' <span class="badge bg-light text-dark border">'+escapeHtml(v.vehicle_type_label)+'</span>':'')]);
                rows.push(['Plate', escapeHtml(v.plate_number||'—')]);
                rows.push(['Vehicle verification', escapeHtml(v.verification_status||'—')]);
            } else {
                rows.push(['Vehicle', '<span class="text-muted">No vehicle on file</span>']);
            }
        }
        const table='<div class="card border-0 bg-light mb-3"><div class="card-body p-3"><table class="table table-sm mb-0"><tbody>'+
            rows.map(r=>`<tr><th class="text-muted fw-normal small" style="width:42%">${r[0]}</th><td class="small">${r[1]}</td></tr>`).join('')+
            '</tbody></table></div></div>';

        if(d.already_admin){
            out.innerHTML=table+
                `<div class="alert alert-info mb-2">This user is already an administrator. You can manage their permissions instead.</div>`+
                (u.is_super_admin?'':`<button class="btn btn-outline-primary btn-sm" type="button" onclick="openChangePermissions(${u.id})"><i class="fas fa-key me-1"></i>Manage Permissions</button>`);
            if(btn) btn.style.display='none'; _promoteFound=null; return;
        }
        if(!d.eligible){
            out.innerHTML=table+`<div class="alert alert-warning mb-0">Only Rider or Driver accounts can be promoted to Subadmin.</div>`;
            if(btn) btn.style.display='none'; _promoteFound=null; return;
        }
        _promoteFound=u;
        out.innerHTML=table+
            (u.is_suspended?`<div class="alert alert-warning py-2">Note: this account is currently suspended as a ${escapeHtml(u.user_type)}. Promoting adds the admin hat but does not change that.</div>`:'')+
            `<div class="fw-semibold mb-1">Permissions</div>`+
            `<p class="text-muted small mb-2">Grant only what this Subadmin needs. They always keep the <code>dashboard.view</code> baseline. Money and configuration writes stay Super-Admin-only and never appear here.</p>`+
            permissionCheckboxesHtml([]);
        if(btn){ btn.style.display=''; btn.textContent='Promote to Subadmin'; btn.onclick=()=>adminMgmtSubmit({ action:'promote_admin', user_id:_promoteFound.id, permissions:collectSelectedPermissions() }, 'Account promoted to Subadmin.'); }
    }

    // Remove the admin capability from a promoted rider/driver. The server
    // guards self/super/dedicated-admin targets and leaves everything that
    // makes them a rider/driver untouched.
    async function demoteAdmin(userId){
        const who=adminsById[userId]||{};
        if(!confirm(`Remove the admin role from ${who.fullname||'this user'}? Their ${who.user_type||'rider/driver'} account, vehicles, rides, wallet and history stay completely intact — only the admin capability is removed.`)) return;
        const res=await postJson('../api/admin-management.php', { action:'demote_admin', user_id:userId });
        if(res.status==='success'){ showAdminsAlert('success', res.message||'Admin role removed.'); loadAdminsTab(); }
        else showAdminsAlert('error', res.message||'Action failed.');
    }

    async function openAdminActivity(userId){ const who=adminsById[userId]||{}; const titleEl=document.getElementById('detailModalLabel'); const bodyEl=document.getElementById('detailModalBody'); const footerEl=document.getElementById('detailModalFooter'); titleEl.textContent='Activity — '+(who.fullname||''); bodyEl.innerHTML='<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>'; footerEl.innerHTML=''; new bootstrap.Modal(document.getElementById('detailModal')).show(); const res=await fetch(`../api/admin-management.php?action=get_activity&user_id=${encodeURIComponent(userId)}&page=1`); const data=await res.json(); if(data.status!=='success'){ bodyEl.innerHTML=`<div class="alert alert-danger mb-0">${escapeHtml(data.message||'Unable to load activity.')}</div>`; return; } const rows=data.data.activity||[]; bodyEl.innerHTML = rows.length ? `<div class="table-responsive"><table class="table table-sm align-middle mb-0"><thead><tr><th>When</th><th>Action</th><th>Target</th><th>Old → New</th><th>Reason</th></tr></thead><tbody>${rows.map(r=>`<tr><td class="small text-muted text-nowrap">${escapeHtml(r.created_at||'')}</td><td class="small">${escapeHtml(r.action||'')}</td><td class="small">${escapeHtml(((r.entity_type||'')+' '+(r.entity_id||'')).trim())}</td><td class="small">${escapeHtml((r.old_status||'—')+' → '+(r.new_status||'—'))}</td><td class="small">${escapeHtml(r.reason||'')}</td></tr>`).join('')}</tbody></table></div>` : '<div class="text-muted text-center py-3">No recorded activity yet.</div>'; }

    // Topbar multi-role switch — only rendered for an admin who ALSO holds a
    // rider/driver hat. Posts to the same api/switch-mode.php the rider/driver
    // app uses; that endpoint re-checks capability server-side, so this is not
    // a trust boundary — it only rewrites the session's current mode.
    async function adminSwitchMode(mode){ const res=await postJson('../api/switch-mode.php', { action:'switch', mode }); if(res.status==='success'){ window.location.href=(res.data&&res.data.redirect)?res.data.redirect:(mode==='driver'?'../driver/dashboard.php':'../rider/dashboard.php'); } else alert(res.message||'Could not switch mode.'); }

    async function logout(){ const formData=new FormData(); formData.append('action','logout'); const res=await fetch('../api/auth.php',{method:'POST',body:formData}); const data=await res.json(); if(data.status==='success') window.location.href=data.data.redirect||'../index.php'; }
    function escapeHtml(value){ return String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char])); }
</script>
</body>
</html>
