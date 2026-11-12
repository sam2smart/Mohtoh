<?php
// auth/oauth/facebook/start.php
// Thin route: begins the Facebook Login authorization-code flow. All logic
// (enable gate, rate limit, single-use state, redirect) lives in
// includes/oauth.php.
session_start();
require_once __DIR__ . '/../../../includes/db.php';
require_once __DIR__ . '/../../../includes/oauth.php';

oauth_run_start($pdo, 'facebook');
