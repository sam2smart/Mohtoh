<?php
// verify-account.php
// Authentication, Verification & Account Recovery — the mandatory stop
// between registration/login and the dashboard. Registration and login
// (api/auth.php) both redirect here instead of straight to
// {user_type}/dashboard.php; this page is the only place email/phone
// verification actually happens for a logged-in user.
session_start();
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/config.php';

// Not require_login(): that helper's redirect ("../index.php") assumes
// a page one folder deep (driver/*, rider/*) — this page lives at the
// app root alongside index.php, same as the other new verification/
// recovery pages, so it needs its own root-relative redirect.
if (!is_logged_in()) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/verification.php';

$stmt = $pdo->prepare('SELECT email, phone, email_verified, phone_verified, user_type, is_external_partner FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
if (!$user) {
    header('Location: index.php');
    exit;
}

// Independent channel switches. A disabled channel is treated as
// "not required" for navigation ONLY — the *_verified columns are never
// changed here. emailComplete/phoneComplete drive the redirect below and
// the JS mirror of it in assets/js/verify-account.js.
$emailChannelEnabled = is_email_verification_enabled($pdo);
$phoneChannelEnabled = is_phone_verification_enabled($pdo);
$emailComplete = $user['email_verified'] || !$emailChannelEnabled;
$phoneComplete = $user['phone_verified'] || !$phoneChannelEnabled;

// Nothing left to do on this screen (each channel is either verified or
// disabled) — send the user on. No DB write; email_verified /
// phone_verified stay exactly as they were.
if ($emailComplete && $phoneComplete) {
    header('Location: ' . (!empty($user['is_external_partner']) ? 'partner/dashboard.php' : $user['user_type'] . '/dashboard.php'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Verify Your Account</title>

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
        .verify-hero { text-align: center; padding: calc(var(--space-8) + var(--safe-top)) var(--space-5) var(--space-6); }
        .verify-hero .brand-dot { width: 56px; height: 56px; border-radius: 16px; background: var(--color-primary-100); color: var(--color-primary); display: flex; align-items: center; justify-content: center; font-size: 1.6rem; margin: 0 auto var(--space-4); }
        .verify-hero h1 { font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); }
        .verify-hero p { color: var(--color-text-soft); margin-top: var(--space-2); font-size: var(--text-sm); }
        .verify-card { padding: var(--space-5); margin: 0 var(--space-5) var(--space-4); }
        .verify-card__top { display: flex; align-items: center; gap: var(--space-3); }
        .verify-card__icon { width: 44px; height: 44px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; flex: 0 0 auto; }
        .verify-card__icon.is-pending { background: var(--color-accent-100); color: #a67900; }
        .verify-card__icon.is-done { background: var(--color-success-100); color: var(--color-success); }
        .verify-card__title { font-weight: 700; }
        .verify-card__value { font-size: var(--text-xs); color: var(--color-text-faint); }
        .otp-input { text-align: center; letter-spacing: 0.6em; font-size: var(--text-lg); font-family: var(--font-display); font-weight: 700; }
    </style>
</head>
<body>

<div class="app-shell screen">
    <div class="screen-scroll">
        <header class="verify-hero anim-fade-in">
            <div class="brand-dot"><i class="bi bi-shield-check"></i></div>
            <h1>Verify your account</h1>
            <p>Almost there — <?php
                if ($emailChannelEnabled && $phoneChannelEnabled) {
                    echo 'confirm your email and phone number to unlock ' . ($user['user_type'] === 'driver' ? 'ride creation' : 'ride booking') . '.';
                } elseif ($emailChannelEnabled) {
                    echo 'confirm your email to unlock ' . ($user['user_type'] === 'driver' ? 'ride creation' : 'ride booking') . '.';
                } else {
                    echo 'confirm your phone number to unlock ' . ($user['user_type'] === 'driver' ? 'ride creation' : 'ride booking') . '.';
                }
            ?></p>
        </header>

        <!-- ================= Email verification ================= -->
        <?php if ($emailChannelEnabled): ?>
        <section class="card verify-card anim-slide-up">
            <div class="verify-card__top">
                <div class="verify-card__icon <?= $user['email_verified'] ? 'is-done' : 'is-pending' ?>" id="emailStatusIcon">
                    <i class="bi <?= $user['email_verified'] ? 'bi-check-lg' : 'bi-envelope' ?>"></i>
                </div>
                <div style="flex:1;">
                    <div class="verify-card__title">Email verification</div>
                    <div class="verify-card__value"><?= htmlspecialchars($user['email']) ?></div>
                </div>
                <span class="status-pill <?= $user['email_verified'] ? 'approved' : 'pending' ?>" id="emailStatusPill"><?= $user['email_verified'] ? 'Verified' : 'Pending' ?></span>
            </div>
            <?php if (!$user['email_verified']): ?>
            <div id="emailAlert" class="alert-banner is-hidden u-mt-3"></div>
            <p class="u-text-xs u-text-soft u-mt-3">We've sent a verification link to your email. It expires in <?= (int) ot_email_verification_ttl_minutes($pdo) ?> minutes — check spam/junk if you don't see it.</p>
            <button type="button" class="btn btn-outline u-mt-2" id="resendEmailBtn">Resend Verification Email</button>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <!-- ================= Phone verification ================= -->
        <?php if ($phoneChannelEnabled): ?>
        <section class="card verify-card anim-slide-up">
            <div class="verify-card__top">
                <div class="verify-card__icon <?= $user['phone_verified'] ? 'is-done' : 'is-pending' ?>" id="phoneStatusIcon">
                    <i class="bi <?= $user['phone_verified'] ? 'bi-check-lg' : 'bi-telephone' ?>"></i>
                </div>
                <div style="flex:1;">
                    <div class="verify-card__title">Phone verification</div>
                    <div class="verify-card__value"><?= htmlspecialchars($user['phone']) ?></div>
                </div>
                <span class="status-pill <?= $user['phone_verified'] ? 'approved' : 'pending' ?>" id="phoneStatusPill"><?= $user['phone_verified'] ? 'Verified' : 'Pending' ?></span>
            </div>
            <?php if (!$user['phone_verified']): ?>
            <div id="otpAlert" class="alert-banner is-hidden u-mt-3"></div>
            <p class="u-text-xs u-text-soft u-mt-3">Enter the <?= (int) ot_otp_length($pdo) ?>-digit code sent to your phone. It expires in <?= (int) ot_ttl_minutes($pdo) ?> minutes.</p>
            <div class="field-group u-mt-3">
                <div class="field">
                    <input type="text" id="otpInput" class="otp-input" inputmode="numeric" maxlength="<?= (int) ot_otp_length($pdo) ?>" placeholder="<?= str_repeat('—', (int) ot_otp_length($pdo)) ?>" autocomplete="one-time-code">
                </div>
            </div>
            <button type="button" class="btn btn-primary" id="verifyOtpBtn">Verify Code</button>
            <button type="button" class="btn btn-outline u-mt-2" id="resendOtpBtn">Resend Code</button>
            <?php endif; ?>
        </section>
        <?php endif; ?>

        <div class="u-flex u-justify-between container-pad u-mt-2" style="margin-bottom:var(--space-8);">
            <button type="button" class="btn-text u-text-xs" id="refreshStatusBtn">Refresh status</button>
            <button type="button" class="btn-text u-text-xs" id="logoutBtn" style="color:var(--color-danger);">Log out</button>
        </div>
    </div>
</div>

<script src="assets/js/helper.js"></script>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/pwa-init.js"></script>
<script src="assets/js/verify-account.js"></script>
</body>
</html>
