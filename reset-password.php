<?php
// reset-password.php
// The email channel's landing page — the link inside
// includes/verification.php's mt_email_template_reset() points here
// with ?token=<raw token>. The link itself is the identity proof (no
// OTP entry needed for this channel); this page just collects the new
// password and posts it straight to api/password-reset.php's reset
// action alongside that token.
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/verification.php';

$token = trim((string)($_GET['token'] ?? ''));
$email = null;
$tokenValid = false;
$errorMessage = 'This reset link is invalid or has expired.';

// The email reset channel is itself an OTP-verification flow, so it
// honours the email verification switch: if the channel is disabled,
// an emailed link can no longer be used (no password change happens).
if ($token !== '' && is_email_verification_enabled($pdo)) {
    $stmt = $pdo->prepare("SELECT email, reset_expires, reset_used FROM users WHERE reset_token = ? AND reset_method = 'email'");
    $stmt->execute([hash_token($token)]);
    $row = $stmt->fetch();
    if ($row && !$row['reset_used'] && strtotime($row['reset_expires']) > time()) {
        $tokenValid = true;
        $email = $row['email'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>MohToh — Reset Password</title>
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
        .auth-header { padding: calc(var(--space-6) + var(--safe-top)) var(--space-5) var(--space-2); }
        .auth-header h1 { font-family: var(--font-display); font-weight: 700; font-size: var(--text-xl); }
        .auth-header p { color: var(--color-text-soft); margin-top: var(--space-2); font-size: var(--text-sm); }
        .step-panel { padding: 0 var(--space-5); }
        .step-panel.u-hidden { display: none; }
        .result-icon { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; margin: var(--space-6) auto var(--space-5); }
        .result-icon.is-success { background: var(--color-success-100); color: var(--color-success); }
        .result-icon.is-error { background: var(--color-danger-100); color: var(--color-danger); }
    </style>
</head>
<body>

<div class="app-shell screen">
    <div class="screen-scroll">
        <?php if (!$tokenValid): ?>
            <div class="step-panel anim-fade-in" style="text-align:center;padding-top:var(--space-8);">
                <div class="result-icon is-error"><i class="bi bi-x-lg"></i></div>
                <h1 style="font-family:var(--font-display);font-weight:700;">Link expired</h1>
                <p class="u-text-sm u-text-soft u-mt-2" style="margin-bottom:var(--space-8);"><?= htmlspecialchars($errorMessage) ?></p>
                <a href="forgot-password.php" class="btn btn-primary" style="width:auto;padding-inline:32px;">Request a new link</a>
            </div>
        <?php else: ?>
            <header class="auth-header anim-fade-in">
                <h1>Set a new password</h1>
                <p>Choose a new password for <?= htmlspecialchars($email) ?>.</p>
            </header>

            <section class="step-panel anim-slide-up" id="stepNewPassword">
                <div id="newPasswordAlert" class="alert-banner is-hidden"></div>
                <form id="newPasswordForm">
                    <input type="hidden" id="resetToken" value="<?= htmlspecialchars($token) ?>">
                    <input type="hidden" id="resetIdentifier" value="<?= htmlspecialchars($email) ?>">
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

            <section class="step-panel u-hidden anim-slide-up" id="stepSuccess" style="text-align:center;">
                <div class="result-icon is-success"><i class="bi bi-check-lg"></i></div>
                <h2 style="font-family:var(--font-display);font-weight:700;">Password reset!</h2>
                <p class="u-text-sm u-text-soft u-mt-2" style="margin-bottom:var(--space-8);">You can now log in with your new password.</p>
                <a href="index.php" class="btn btn-primary" style="width:auto;padding-inline:32px;">Back to Log In</a>
            </section>
        <?php endif; ?>
    </div>
</div>

<script src="assets/js/helper.js"></script>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/pwa-init.js"></script>
<script src="assets/js/reset-password.js"></script>
</body>
</html>
