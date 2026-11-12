<?php
// verify-email.php
// The link inside the "Verify your MohToh account" email
// (includes/verification.php's mt_email_template_verify()) points here
// with ?token=<raw token>. No login required — this is very likely
// opened in a different browser/device/tab than the one the user
// registered from.
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/verification.php';

$token = trim((string)($_GET['token'] ?? ''));
$result = $token !== '' ? verify_email_token($pdo, $token) : ['success' => false, 'message' => 'Missing verification token.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Email Verification</title>
    <meta name="robots" content="noindex, nofollow">

    <?php
    $base_prefix = (strpos($_SERVER['SCRIPT_NAME'], '/driver/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/rider/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? '../' : '';
    ?>
    <link rel="manifest" href="<?= $base_prefix ?>manifest.json">
    <meta name="theme-color" content="#0F8B8D">
    <link rel="apple-touch-icon" href="<?= $base_prefix ?>assets/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/reset.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/utilities.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="stylesheet" href="assets/css/responsive.css">

    <style>
        .result-hero { text-align: center; padding: calc(var(--space-8) + var(--safe-top)) var(--space-5) var(--space-6); display: flex; flex-direction: column; align-items: center; min-height: 60vh; justify-content: center; }
        .result-hero .result-icon { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; margin-bottom: var(--space-5); }
        .result-icon.is-success { background: var(--color-success-100); color: var(--color-success); }
        .result-icon.is-error { background: var(--color-danger-100); color: var(--color-danger); }
        .result-hero h1 { font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); }
        .result-hero p { color: var(--color-text-soft); margin-top: var(--space-2); max-width: 30ch; }
    </style>
</head>
<body>

<div class="app-shell screen">
    <div class="screen-scroll">
        <div class="result-hero anim-fade-in">
            <div class="result-icon <?= $result['success'] ? 'is-success' : 'is-error' ?>">
                <i class="bi <?= $result['success'] ? 'bi-check-lg' : 'bi-x-lg' ?>"></i>
            </div>
            <h1><?= $result['success'] ? 'Email Verified' : 'Verification Failed' ?></h1>
            <p><?= htmlspecialchars($result['message']) ?></p>

            <?php if ($result['success']): ?>
                <a href="<?= is_logged_in() ? 'verify-account.php' : 'index.php' ?>" class="btn btn-primary u-mt-6" style="width:auto;padding-inline:32px;">Continue</a>
            <?php else: ?>
                <a href="<?= is_logged_in() ? 'verify-account.php' : 'index.php' ?>" class="btn btn-outline u-mt-6" style="width:auto;padding-inline:32px;">
                    <?= is_logged_in() ? 'Request a new link' : 'Back to MohToh' ?>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="assets/js/pwa-init.js"></script>
</body>
</html>
