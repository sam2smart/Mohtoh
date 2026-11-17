<?php
// partner/dashboard.php
// MOHTOH NETWORK External Partner home (Phase 5 — see
// MOHTOH_EXTERNAL_PARTNER_PROPOSAL.md for the approved architecture).
// An external partner is an ordinary users row with user_type='rider'
// and is_external_partner=1 — never a new auth role, never a new
// user_type ENUM value. This page is their ENTIRE account home (no
// transport features), unlike a rider/driver for whom MOHTOH NETWORK is
// one sheet layered on top of the transport app.
//
// Deliberately thin: reuses partials/network-sheet.php (the exact same
// markup/IDs assets/js/network.js already drives for any rider/driver
// partner) rather than duplicating it, forced into an always-open,
// non-dismissible state instead of a slide-up overlay. No new backend
// endpoint — every action here goes through the same api/network.php
// any rider/driver partner already uses.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/config.php';

require_login();

if (empty($_SESSION['is_external_partner'])) {
    // Re-check the DB rather than trust a stale session flag from before
    // this feature existed.
    require_once __DIR__ . '/../includes/db.php';
    $stmt = $pdo->prepare("SELECT is_external_partner FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $_SESSION['is_external_partner'] = (bool) $stmt->fetchColumn();
}
if (!$_SESSION['is_external_partner']) {
    $roleHome = ['rider' => '../rider/dashboard.php', 'driver' => '../driver/dashboard.php', 'admin' => '../admin/dashboard.php'][$_SESSION['user_type']] ?? '../index.php';
    header('Location: ' . $roleHome);
    exit;
}

require_once __DIR__ . '/../includes/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <!-- App CSRF token for authenticated state-changing fetches (join/withdrawal requests). Not a secret; scoped to this session. -->
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES) ?>">
    <title>MohToh — MOHTOH NETWORK Partner</title>

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
    <style>
        /* This page IS the MOHTOH NETWORK sheet, always open, not a
           dismissible overlay layered on a transport app — no scrim, no
           close affordance, positioned as the page itself rather than a
           bottom sheet. */
        body { background: var(--color-bg); }
        #networkSheet { position: static; transform: none !important; max-width: var(--app-max-width); margin: 0 auto; max-height: none; border-radius: 0; min-height: 100vh; box-shadow: none; }
        #networkSheet .sheet-handle, #networkSheet .sheet-close { display: none; }
    </style>
</head>
<body data-user-type="rider">

<header class="app-floating-header layer-header container-pad" style="position: sticky; top: 0;">
    <span class="app-floating-header__brand"><span class="brand-dot-sm"><i class="bi bi-signpost-split-fill"></i></span>MohToh</span>
    <div class="app-floating-header__actions">
        <button type="button" class="icon-pill" id="partnerLogoutBtn" aria-label="Log out"><i class="bi bi-box-arrow-right"></i></button>
    </div>
</header>

<?php include __DIR__ . '/../partials/network-sheet.php'; ?>

<script src="../assets/js/helper.js"></script>
<script src="../assets/js/api-client.js"></script>
<script src="../assets/js/state.js"></script>
<script src="../assets/js/network.js"></script>
<script>
    // Force the reused sheet open and trigger assets/js/network.js's
    // existing "load fresh data" path — the same click-delegated handler
    // it already listens for, so no change to network.js is needed.
    document.addEventListener('DOMContentLoaded', () => {
        const sheet = document.getElementById('networkSheet');
        if (sheet) sheet.classList.add('is-open');
        const opener = document.createElement('button');
        opener.setAttribute('data-open-sheet-id', 'networkSheet');
        opener.style.display = 'none';
        document.body.appendChild(opener);
        opener.click();
    });
    document.getElementById('partnerLogoutBtn').addEventListener('click', async () => {
        const formData = new FormData();
        formData.append('action', 'logout');
        const res = await fetch('../api/auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') window.location.href = data.data.redirect || '../index.php';
    });
</script>
</body>
</html>
