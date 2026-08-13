<?php
// tests/_ic_worker.php  (internal helper for integration_center_test.php)
// Runs ONE api/integration-center.php request in an isolated CLI process so
// json_response()'s exit() can't terminate the orchestrator. Authenticates as
// a super-admin, forces provider modes to 'log' (no real external sends), and
// emits a single JSON blob { body } to the result file given in argv[2].
//
// argv[1] = JSON config file: { method:'GET'|'POST', payload:{...} }
// argv[2] = absolute path to write the result JSON to.

$cfg = json_decode(file_get_contents($argv[1]), true);
$resultFile = $argv[2];

error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED);

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/integration-settings.php';

const IC_ADMIN_ID = 10;

// Force log-mode providers so test_email never makes a real SMTP/API call.
set_integration_setting($GLOBALS['pdo'], IC_ADMIN_ID, 'integration_email_mode', 'log', false);

// Authenticate the request as a real super-admin. api/integration-center.php
// gates writes on require_super_admin($pdo), which checks the users.is_super_admin
// column for $_SESSION['user_id'] — so we must pick a row that actually has it,
// exactly as a logged-in super admin would in the browser.
$admin = $GLOBALS['pdo']->query("SELECT id FROM users WHERE user_type='admin' AND is_super_admin = 1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$adminId = $admin ? (int)$admin['id'] : IC_ADMIN_ID;

ini_set('session.use_cookies', '0');
session_id('ic' . getmypid());
@session_start();
$_SESSION = ['user_id' => $adminId, 'user_type' => 'admin', 'is_super_admin' => 1];

$method = strtoupper($cfg['method'] ?? 'GET');
$payload = $cfg['payload'] ?? [];

register_shutdown_function(function () use ($resultFile) {
    $body = ob_get_contents();
    if ($body !== false) { ob_end_clean(); }
    file_put_contents($resultFile, json_encode(['body' => $body]));
});
ob_start();

$_SERVER['REQUEST_METHOD'] = $method;
if ($method === 'POST') {
    $_POST = $payload;
    // api/integration-center.php reads JSON body first, falls back to $_POST.
    // Provide $_POST (php://input isn't writable in CLI); the fallback covers us.
}
$GLOBALS['__ic_payload'] = $payload;

// api/integration-center.php uses relative requires ('../includes/db.php'),
// which resolve against the CWD. Under the web server the CWD is api/; in CLI
// it is the project root. chdir into api/ so those paths resolve identically.
chdir(__DIR__ . '/../api');
include __DIR__ . '/../api/integration-center.php';
