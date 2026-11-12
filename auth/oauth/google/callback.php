<?php
// auth/oauth/google/callback.php
// Thin route: Google redirects here with ?code & ?state. All validation (state
// consume, TLS code exchange, id_token verification, account resolve/link/
// create, session establishment) lives in includes/oauth.php. This fixed path
// is the ONLY Google redirect URI the app accepts.
session_start();
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/oauth.php';

oauth_run_callback($pdo, 'google');
