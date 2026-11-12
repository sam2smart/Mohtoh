<?php
// auth/oauth/facebook/callback.php
// Thin route: Facebook redirects here with ?code & ?state (or ?error). All
// validation (state consume, TLS code exchange, debug_token app check,
// appsecret_proof profile fetch, account resolve/link/create, session
// establishment) lives in includes/oauth.php. This fixed path is the ONLY
// Facebook redirect URI the app accepts.
session_start();
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/oauth.php';

oauth_run_callback($pdo, 'facebook');
