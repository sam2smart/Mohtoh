<?php
// social-complete.php
// Completion step for a BRAND-NEW social signup. The OAuth callback has already
// validated the provider identity and stashed it server-side in
// $_SESSION['pending_social_signup'] — this page never trusts the browser for
// provider_user_id or the verified-email flag. It only collects the few things
// the provider can't give us: how the user wants to use MohToh (role), a phone
// number (required, unique), and — ONLY when the provider gave no verified
// email — an email address.
//
// The account is created by oauth_create_social_user(), which sets
// email_verified from the provider's trusted flag alone and then runs the
// SAME verification gate as normal registration.
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/oauth.php';

// Already logged in? Nothing to complete.
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $_SESSION['user_type'] . '/dashboard.php');
    exit;
}

// Allow the user to abandon the flow cleanly.
if (isset($_GET['cancel'])) {
    unset($_SESSION['pending_social_signup']);
    header('Location: index.php');
    exit;
}

$pending = $_SESSION['pending_social_signup'] ?? null;
$pendingValid = is_array($pending)
    && !empty($pending['provider'])
    && !empty($pending['provider_user_id'])
    && (int)($pending['expires'] ?? 0) >= time();

if (!$pendingValid) {
    unset($_SESSION['pending_social_signup']);
    oauth_flash('info', 'Your sign-in session expired. Please try again.', 'login');
    header('Location: index.php');
    exit;
}

$providerLabel = oauth_provider_label($pending['provider']);
// A provider-VERIFIED email is authoritative: shown read-only, never editable.
$emailIsTrusted = (($pending['email'] ?? '') !== '' && !empty($pending['email_verified']));
$prefillEmail = (string)($pending['email'] ?? '');
$roleHint = in_array(($pending['role_hint'] ?? ''), ['rider', 'driver', 'keke'], true) ? $pending['role_hint'] : 'rider';
$prefillName = (string)($pending['name'] ?? '');

$error = '';
$formRole = $roleHint;
$formPhone = '';
$formEmail = $prefillEmail;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // App CSRF token (state-changing request).
    if (!csrf_validate($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (!oauth_rate_limit($pdo, 'social_complete', oauth_client_ip(), 15, 600)) {
        $error = 'Too many attempts. Please wait a few minutes and try again.';
    } else {
        $formRole = strtolower(trim((string)($_POST['role'] ?? '')));
        $formPhone = trim((string)($_POST['phone'] ?? ''));
        // For a trusted provider email the form value is ignored entirely.
        $formEmail = $emailIsTrusted ? $prefillEmail : trim((string)($_POST['email'] ?? ''));

        if (!in_array($formRole, ['rider', 'driver', 'keke'], true)) {
            $error = 'Please choose how you want to use MohToh.';
        } else {
            $result = oauth_create_social_user($pdo, $pending, $formRole, $formPhone, $formEmail);
            if (!empty($result['ok'])) {
                unset($_SESSION['pending_social_signup']);
                oauth_redirect($result['redirect']); // establishes the session + exits
            } elseif (!empty($result['email_taken']) && $emailIsTrusted) {
                // The provider-verified email already belongs to an account —
                // hand off to ownership-proof linking via password login.
                $_SESSION['pending_social_link'] = [
                    'provider' => $pending['provider'],
                    'provider_user_id' => $pending['provider_user_id'],
                    'email' => $pending['email'],
                    'email_verified' => true,
                    'name' => $pending['name'] ?? '',
                    'expires' => time() + OAUTH_PENDING_TTL_SECONDS,
                ];
                unset($_SESSION['pending_social_signup']);
                oauth_flash('info', $result['error'], 'login');
                header('Location: index.php');
                exit;
            } else {
                $error = $result['error'] ?? 'We could not finish creating your account. Please try again.';
            }
        }
    }
}

$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Finish setting up your account · MohToh</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" href="assets/images/favicon-32.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/reset.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/mobile-shell.css">
    <link rel="stylesheet" href="assets/css/utilities.css">
    <style>
        .sc-wrap { max-width: 460px; margin: 0 auto; padding: calc(var(--space-8) + var(--safe-top)) var(--space-5) var(--space-8); }
        .sc-badge { display:inline-flex; align-items:center; gap:var(--space-2); background:var(--color-primary-50); color:var(--color-primary-700); font-weight:600; font-size:var(--text-xs); padding:6px 12px; border-radius:999px; }
        .sc-title { font-family:var(--font-display); font-weight:700; font-size:var(--text-2xl); margin-top:var(--space-4); }
        .sc-sub { color:var(--color-text-soft); margin-top:var(--space-2); }
        .sc-note { display:flex; align-items:center; gap:6px; color:var(--color-text-soft); font-size:var(--text-xs); margin-top:var(--space-1); }
        .sc-verified { color:var(--color-primary-700); }
        .field input[readonly] { background:var(--color-surface-muted, #f3f4f6); color:var(--color-text-soft); }
    </style>
</head>
<body>
<div class="app-shell screen">
    <div class="screen-scroll">
        <div class="sc-wrap anim-fade-in">
            <span class="sc-badge"><i class="bi bi-check2-circle"></i> Signed in with <?= htmlspecialchars($providerLabel) ?></span>
            <h1 class="sc-title">You're almost there<?= $prefillName !== '' ? ', ' . htmlspecialchars(explode(' ', $prefillName)[0]) : '' ?>.</h1>
            <p class="sc-sub">Just a couple more details to finish creating your MohToh account.</p>

            <?php if ($error !== ''): ?>
                <div class="alert-banner" role="alert" style="margin-top:var(--space-4);"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" action="social-complete.php" novalidate style="margin-top:var(--space-5);">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">

                <div class="field-group">
                    <label class="field-label" for="scRole">I want to</label>
                    <div class="field">
                        <i class="bi bi-signpost"></i>
                        <select id="scRole" name="role" required>
                            <option value="rider"  <?= $formRole === 'rider'  ? 'selected' : '' ?>>Become a Rider</option>
                            <option value="driver" <?= $formRole === 'driver' ? 'selected' : '' ?>>Become a Driver</option>
                            <option value="keke"   <?= $formRole === 'keke'   ? 'selected' : '' ?>>Become a Keke Driver</option>
                        </select>
                    </div>
                </div>

                <div class="field-group">
                    <label class="field-label" for="scPhone">Phone number</label>
                    <div class="field">
                        <i class="bi bi-telephone"></i>
                        <input type="text" id="scPhone" name="phone" placeholder="e.g. 08012345678" inputmode="numeric" autocomplete="tel" value="<?= htmlspecialchars($formPhone) ?>" required>
                    </div>
                    <p class="sc-note">We'll send a verification code to confirm it's you.</p>
                </div>

                <div class="field-group">
                    <label class="field-label" for="scEmail">Email address</label>
                    <div class="field">
                        <i class="bi bi-envelope"></i>
                        <input type="email" id="scEmail" name="email" placeholder="you@example.com" autocomplete="email"
                               value="<?= htmlspecialchars($formEmail) ?>"
                               <?= $emailIsTrusted ? 'readonly' : 'required' ?>>
                    </div>
                    <?php if ($emailIsTrusted): ?>
                        <p class="sc-note sc-verified"><i class="bi bi-patch-check-fill"></i> Verified by <?= htmlspecialchars($providerLabel) ?></p>
                    <?php else: ?>
                        <p class="sc-note">We'll send a verification link to confirm this address.</p>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary u-mt-3">Create my account</button>
            </form>

            <p class="auth-switch" style="margin-top:var(--space-5);">
                Not you? <a href="social-complete.php?cancel=1" style="color:var(--color-primary);font-weight:600;">Use a different account</a>
            </p>
        </div>
    </div>
</div>
</body>
</html>
