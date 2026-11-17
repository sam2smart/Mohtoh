<?php
session_start();
require_once 'includes/seo.php';
if (isset($_SESSION['user_id'])) {
    header("Location: " . $_SESSION['user_type'] . "/dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php 
    mt_render_seo(
        'MohToh - Affordable Ride Sharing & Carpooling Platform',
        'Find drivers heading your way, share trips, and save on your daily commute with MohToh, the trusted ride sharing and carpooling platform in Nigeria.',
        mt_get_base_url() . '/'
    );
    ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <?php
    $base_prefix = (strpos($_SERVER['SCRIPT_NAME'], '/driver/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/rider/') !== false || strpos($_SERVER['SCRIPT_NAME'], '/admin/') !== false) ? '../' : '';
    ?>
    <link rel="manifest" href="<?= $base_prefix ?>manifest.json">
    <meta name="theme-color" content="#0F8B8D">
    <link rel="icon" href="<?= $base_prefix ?>assets/images/favicon-32.png">
    <link rel="apple-touch-icon" href="<?= $base_prefix ?>assets/icons/icon-192.png">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap assists layout/spacing utilities only; visual identity comes from assets/css -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/variables.css">
    <link rel="stylesheet" href="assets/css/reset.css">
    <link rel="stylesheet" href="assets/css/layout.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/mobile-shell.css">
    <link rel="stylesheet" href="assets/css/utilities.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="stylesheet" href="assets/css/responsive.css">

    <style>
        /* Screen-specific rules that don't belong in shared component files */
        .auth-hero { background: linear-gradient(165deg, var(--color-primary) 0%, var(--color-primary-700) 100%); color: #fff; padding: calc(var(--space-8) + var(--safe-top)) var(--space-5) var(--space-8); border-radius: 0 0 32px 32px; position: relative; overflow: hidden; }
        .auth-hero::after { content: ''; position: absolute; width: 260px; height: 260px; border-radius: 50%; background: rgba(255,255,255,0.08); top: -90px; right: -70px; }
        .auth-hero::before { content: ''; position: absolute; width: 160px; height: 160px; border-radius: 50%; background: var(--color-accent); opacity: 0.18; bottom: -60px; left: -40px; }
        .brand-mark { display: flex; align-items: center; gap: var(--space-2); font-family: var(--font-display); font-weight: 700; font-size: var(--text-lg); position: relative; z-index: 1; }
        .brand-mark .brand-dot { width: 34px; height: 34px; border-radius: 10px; background: var(--color-accent); display: flex; align-items: center; justify-content: center; color: var(--color-primary-700); font-size: 1.1rem; }
        .auth-hero h1 { font-family: var(--font-display); font-weight: 700; font-size: var(--text-2xl); line-height: 1.2; margin-top: var(--space-8); max-width: 15ch; position: relative; z-index: 1; }
        .auth-hero p { margin-top: var(--space-3); color: rgba(255,255,255,0.85); max-width: 30ch; position: relative; z-index: 1; }
        .route-graphic { display: flex; align-items: center; gap: var(--space-2); margin-top: var(--space-6); position: relative; z-index: 1; }
        .route-graphic .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--color-accent); flex: 0 0 auto; }
        .route-graphic .pin { color: var(--color-accent); font-size: 1rem; }
        .route-graphic .line { flex: 1; height: 2px; background: repeating-linear-gradient(90deg, rgba(255,255,255,0.55) 0 6px, transparent 6px 12px); }
        .cta-stack { padding: var(--space-6) var(--space-5) var(--space-8); display: flex; flex-direction: column; gap: var(--space-3); }
        .value-row { display: flex; gap: var(--space-4); padding: var(--space-2) 0 var(--space-2); }
        .value-item { flex: 1; text-align: center; }
        .value-item i { font-size: 1.3rem; color: var(--color-primary); }
        .value-item span { display: block; margin-top: var(--space-1); font-size: var(--text-xs); color: var(--color-text-soft); }
    </style>
</head>
<body>

<div class="app-shell screen">
    <div class="screen-scroll">

        <!-- ================= Brand hero ================= -->
        <header class="auth-hero anim-fade-in">
            <div class="brand-mark">
                <span class="brand-dot"><i class="bi bi-signpost-split-fill"></i></span>
                MohToh
            </div>
            <h1>Join a ride, not a taxi queue.</h1>
            <p>Find drivers already heading your way, or publish your own trip and fill your empty seats.</p>
            <div class="route-graphic">
                <span class="dot"></span>
                <span class="line"></span>
                <i class="bi bi-geo-alt-fill pin"></i>
            </div>
        </header>

        <!-- ================= Call to action ================= -->
        <section class="cta-stack container-pad anim-slide-up anim-delay-1">
            <button type="button" class="btn btn-primary" data-open-sheet="register">
                Get Started <i class="bi bi-arrow-right"></i>
            </button>
            <button type="button" class="btn btn-outline" data-open-sheet="login">
                I already have an account
            </button>
            <!-- Install PWA Button (Hidden by default, shown if installation supported) -->
            <button type="button" class="btn btn-accent u-hidden" id="pwaInstallBtn">
                <i class="bi bi-download"></i> Install MohToh App
            </button>

            <div class="value-row">
                <div class="value-item"><i class="bi bi-cash-coin"></i><span>Fixed, fair pricing</span></div>
                <div class="value-item"><i class="bi bi-patch-check"></i><span>Verified drivers</span></div>
                <div class="value-item"><i class="bi bi-clock-history"></i><span>Scheduled trips</span></div>
            </div>
        </section>
    </div>

    <!-- ================= Auth sheets ================= -->
    <div class="sheet-scrim" data-sheet-scrim></div>

    <div class="sheet" id="sheetLogin" role="dialog" aria-modal="true" aria-labelledby="sheetLoginTitle">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title" id="sheetLoginTitle">Welcome back</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="loginAlert" class="alert-banner is-hidden" role="alert"></div>

        <form id="loginForm" novalidate>
            <div class="field-group">
                <label class="field-label" for="loginEmail">Email address</label>
                <div class="field">
                    <i class="bi bi-envelope"></i>
                    <input type="email" id="loginEmail" name="email" placeholder="you@example.com" autocomplete="email" required>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="loginPassword">Password</label>
                <div class="field">
                    <i class="bi bi-lock"></i>
                    <input type="password" id="loginPassword" name="password" placeholder="••••••••" autocomplete="current-password" required>
                    <button type="button" class="field-toggle-visibility" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <div class="u-text-right u-mb-2">
                <a href="forgot-password.php" class="u-text-xs" style="color:var(--color-primary);font-weight:600;">Forgot password?</a>
            </div>
            <button type="submit" class="btn btn-primary u-mt-2">Log In</button>
        </form>

        <p class="auth-switch">New to MohToh? <button type="button" data-switch-sheet="register">Create an account</button></p>
    </div>

    <div class="sheet" id="sheetRegister" role="dialog" aria-modal="true" aria-labelledby="sheetRegisterTitle">
        <div class="sheet-handle"></div>
        <div class="sheet-header">
            <h2 class="sheet-title" id="sheetRegisterTitle">Create your account</h2>
            <button type="button" class="sheet-close" data-close-sheet aria-label="Close"><i class="bi bi-x-lg"></i></button>
        </div>

        <div id="registerAlert" class="alert-banner is-hidden" role="alert"></div>
        <div id="registerReferralBanner" class="alert-banner is-hidden" style="background:var(--color-primary-50);color:var(--color-primary);"></div>

        <form id="registerForm" novalidate>
            <input type="hidden" id="registerReferralCode" name="referral_code" value="">
            <div class="field-group">
                <label class="field-label" for="registerFullname">Full name</label>
                <div class="field">
                    <i class="bi bi-person"></i>
                    <input type="text" id="registerFullname" name="fullname" placeholder="Full name" autocomplete="name" required>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="registerPhone">Phone number</label>
                <div class="field">
                    <i class="bi bi-telephone"></i>
                    <input type="text" id="registerPhone" name="phone" placeholder="e.g. 08012345678" autocomplete="tel" inputmode="numeric" required>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="registerEmail">Email address</label>
                <div class="field">
                    <i class="bi bi-envelope"></i>
                    <input type="email" id="registerEmail" name="email" placeholder="you@example.com" autocomplete="email" required>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="registerPassword">Password</label>
                <div class="field">
                    <i class="bi bi-lock"></i>
                    <input type="password" id="registerPassword" name="password" placeholder="At least 6 characters" autocomplete="new-password" required>
                    <button type="button" class="field-toggle-visibility" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="registerConfirmPassword">Confirm password</label>
                <div class="field">
                    <i class="bi bi-lock-fill"></i>
                    <input type="password" id="registerConfirmPassword" name="confirm_password" placeholder="Re-enter your password" autocomplete="new-password" required>
                    <button type="button" class="field-toggle-visibility" aria-label="Show password"><i class="bi bi-eye"></i></button>
                </div>
            </div>
            <div class="field-group">
                <label class="field-label" for="registerUserType">I want to</label>
                <div class="field">
                    <i class="bi bi-signpost"></i>
                    <select id="registerUserType" name="user_type" required>
                        <option value="rider">Become a Rider</option>
                        <option value="driver">Become a Driver</option>
                        <option value="keke">Become a Keke Driver</option>
                        <option value="external_partner">Become a MOHTOH NETWORK Partner</option>
                    </select>
                </div>
                <p class="u-text-xs u-text-faint u-mt-1" id="registerUserTypeHint">Find and join rides published by drivers.</p>
            </div>

            <button type="submit" class="btn btn-primary u-mt-2">Create Account</button>
        </form>

        <p class="auth-switch">Already have an account? <button type="button" data-switch-sheet="login">Log in</button></p>
    </div>
</div>

<script src="assets/js/helper.js"></script>
<script src="assets/js/api-client.js"></script>
<script src="assets/js/state.js"></script>
<script src="assets/js/sound-manager.js"></script>
<script src="assets/js/mohtoh-bridge.js"></script>
<script src="assets/js/pwa-init.js"></script>
<script src="assets/js/auth.js"></script>
</body>
</html>
