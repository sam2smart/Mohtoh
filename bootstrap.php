<?php
// bootstrap.php
// Single, standard backend loader — configuration, database, functions,
// authentication, and common helpers only. No HTML/CSS/JS belongs here.
//
// This does NOT replace any existing page's or endpoint's individual
// require_once calls — every file that currently loads includes/db.php,
// includes/functions.php, etc. directly keeps working exactly as before.
// This exists so a NEW page or endpoint has one correct, __DIR__-based
// entry point to start from instead of guessing a relative ../ depth.
//
// Usage from any file, any depth: require_once dirname(__DIR__, N) . '/bootstrap.php';
// (N = however many directories up from the app root — e.g. from
// rider/wallet.php that's dirname(__DIR__) . '/bootstrap.php'.)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/includes/db.php';           // $pdo
require_once __DIR__ . '/includes/functions.php';    // core helpers: is_logged_in(), require_login(), json_response(), wallet functions, etc.
require_once __DIR__ . '/includes/config.php';       // app/service configuration constants
require_once __DIR__ . '/includes/current_user.php'; // authentication: resolves the logged-in user's profile for pages/partials
require_once __DIR__ . '/includes/verification.php'; // authentication: OTP, email verification, password reset, login lockout
