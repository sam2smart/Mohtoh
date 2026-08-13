<?php
// tests/_worker.php  (internal helper for verify_separation_test.php)
// Runs ONE api/auth.php request in an isolated CLI process so that
// json_response()'s exit() can't terminate the orchestrator. Emits a
// single JSON blob to the result file given in argv[2].
//
// argv[1] = path to a JSON config file: { channels:{email,phone},
//           policy:{email,phone}, post:{...}, session:{...} }
// argv[2] = absolute path to write the result JSON to.

$cfg = json_decode(file_get_contents($argv[1]), true);
$resultFile = $argv[2];

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/integration-settings.php';
require __DIR__ . '/../includes/verification.php';

const WK_ADMIN_ID = 10;

// Apply the independent channel switches + login policy for this run.
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_email_verification_enabled', $cfg['channels']['email'] ? '1' : '0', false);
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_phone_verification_enabled', $cfg['channels']['phone'] ? '1' : '0', false);
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_require_email_to_login', ($cfg['policy']['email'] ?? true) ? '1' : '0', false);
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_require_phone_to_login', ($cfg['policy']['phone'] ?? true) ? '1' : '0', false);
// Force log-mode providers so no real HTTP calls are made during tests.
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_sms_mode', 'log', false);
set_integration_setting($GLOBALS['pdo'], WK_ADMIN_ID, 'integration_email_mode', 'log', false);

// Session bootstrap: a fixed id so $_SESSION we seed survives api/auth.php's
// own session_start() (a second session_start on an active session is a
// no-op notice, not a reset).
ini_set('session.use_cookies', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);
session_id('wk' . getmypid());
@session_start();
$_SESSION = $cfg['session'] ?? [];

$mode = $cfg['mode'] ?? 'auth';

register_shutdown_function(function () use ($resultFile) {
    $body = ob_get_contents();
    if ($body !== false) { ob_end_clean(); }
    $location = '';
    foreach (headers_list() as $h) {
        if (stripos($h, 'location:') === 0) { $location = trim(substr($h, 9)); }
    }
    file_put_contents($resultFile, json_encode([
        'body'     => $body,
        'session'  => $_SESSION,
        'location' => $location,
    ]));
});

ob_start();

if ($mode === 'verify_page') {
    // Render verify-account.php as a logged-in user in a clean process so
    // its header('Location') redirect (both-channels-off / all-complete)
    // registers and is captured above.
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/verify-account.php';
    include __DIR__ . '/../verify-account.php';
} else {
    $_POST = $cfg['post'] ?? [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    include __DIR__ . '/../api/auth.php';
}
