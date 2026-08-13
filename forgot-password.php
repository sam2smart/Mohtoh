<?php
// forgot-password.php
// Step 1 of the Forgot Password flow (identify by email or phone). No
// login required — api/password-reset.php's request/verify_otp/reset
// actions do the rest, driven by assets/js/forgot-password.js.
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Forgot Password</title>

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
        .auth-header { padding: calc(var(--space-6) + var(--safe-top)) var(--space-5) var(--space-2); }
        .auth-header h1 { font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); }
        .auth-header p { color: var(--color-text-soft); margin-top: var(--space-2); font-size: var(--text-sm); }
        .step-panel { padding: 0 var(--space-5); }
        .step-panel.u-hidden { display: none; }
        .result-icon { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; margin: var(--space-6) auto var(--space-5); background: var(--color-success-100); color: var(--color-success); }
        .otp-input { text-align: center; letter-spacing: 0.6em; font-size: var(--text-lg); font-family: var(--font-display); font-weight: 700; }
    </style>
</head>
<body>

<div class="app-shell screen">
    <div class="screen-scroll">
        <header class="auth-header anim-fade-in">
            <a href="index.php" class="icon-pill icon-pill--ghost" style="margin-bottom:var(--space-4);"><i class="bi bi-arrow-left"></i></a>
            <h1>Forgot your password?</h1>
            <p>Enter the email or phone number on your MohToh account and we'll help you get back in.</p>
        </header>

        <!-- ================= Step 1: identify ================= -->
        <section class="step-panel anim-slide-up" id="stepIdentify">
            <div id="identifyAlert" class="alert-banner is-hidden"></div>
            <p id="resetChannelHint" class="alert-banner is-hidden" style="background:var(--color-accent-100);color:#a67900;"></p>
            <form id="identifyForm">
                <div class="field-group">
                    <label class="field-label" for="identifierInput">Email or phone number</label>
                    <div class="field">
                        <i class="bi bi-person"></i>
                        <input type="text" id="identifierInput" placeholder="you@example.com or 08012345678" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary u-mt-2">Send reset instructions</button>
            </form>
        </section>

        <!-- ================= Step 2: OTP (phone channel only) ================= -->
        <section class="step-panel u-hidden anim-slide-up" id="stepOtp">
            <div id="otpAlert" class="alert-banner is-hidden"></div>
            <p class="u-text-sm u-text-soft u-mb-4">Enter the 6-digit code we texted you.</p>
            <div class="field-group">
                <div class="field"><input type="text" id="resetOtpInput" class="otp-input" inputmode="numeric" maxlength="6" placeholder="——————"></div>
            </div>
            <button type="button" class="btn btn-primary" id="verifyResetOtpBtn">Verify Code</button>
        </section>

        <!-- ================= Step 3: new password ================= -->
        <section class="step-panel u-hidden anim-slide-up" id="stepNewPassword">
            <div id="newPasswordAlert" class="alert-banner is-hidden"></div>
            <form id="newPasswordForm">
                <div class="field-group">
                    <label class="field-label" for="newPasswordInput">New password</label>
                    <div class="field"><i class="bi bi-lock"></i><input type="password" id="newPasswordInput" placeholder="At least 6 characters" required></div>
                </div>
                <div class="field-group">
                    <label class="field-label" for="confirmPasswordInput">Confirm new password</label>
                    <div class="field"><i class="bi bi-lock-fill"></i><input type="password" id="confirmPasswordInput" placeholder="Re-enter new password" required></div>
                </div>
                <button type="submit" class="btn btn-primary u-mt-2">Reset Password</button>
            </form>
        </section>

        <!-- ================= Step 4: success ================= -->
        <section class="step-panel u-hidden anim-slide-up" id="stepSuccess" style="text-align:center;">
            <div class="result-icon"><i class="bi bi-check-lg"></i></div>
            <h2 style="font-family:var(--font-display);font-weight:700;" id="successHeading">Check your inbox</h2>
            <p class="u-text-sm u-text-soft u-mt-2" id="successMessage" style="margin-bottom:var(--space-8);"></p>
            <a href="index.php" class="btn btn-primary" style="width:auto;padding-inline:32px;">Back to Log In</a>
        </section>
    </div>
</div>

<script src="assets/js/helper.js"></script>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/pwa-init.js"></script>
<script src="assets/js/forgot-password.js"></script>
</body>
</html>
