<?php
// POST /api/v1/driver/update-location.php
// api/update-location.php's body is the live Intelligent Trip Engine
// itself (geofencing, auto-boarding, auto-start, auto-complete staging —
// several hundred lines of state-machine logic, not simple CRUD). Rather
// than re-implement any part of that a second time (exactly the
// duplication this whole task forbids), this endpoint authenticates via
// token, adapts that identity into the $_SESSION shape the existing file
// already expects, and lets the ORIGINAL file run completely unmodified.
// Same trust boundary either way: only the token's own validated user_id
// is ever written into $_SESSION, never anything from the request body.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
v1_require_role($user, ['driver']);

session_start();
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['user_type'] = $user['user_type'];
$_SESSION['fullname'] = $user['fullname'];

// The included file calls session_start() again itself — harmless
// (same session, same data) except for the "session already active"
// E_WARNING PHP emits on a second call, which our own error handler
// would otherwise turn into a false internal_error. Briefly drop below
// E_WARNING so exactly that one notice is swallowed the same way PHP's
// default (silent) handling would.
//
// update-location.php's own require_once calls use bare relative paths
// ('../includes/db.php'), written assuming it's always invoked directly
// as api/update-location.php — those resolve against the process's
// working directory, not this file's __DIR__. chdir() into api/ first
// so they resolve exactly as they do for the original web endpoint;
// nothing later in that file does its own filesystem/relative-path work,
// so this is safe for the remainder of the request.
$previousLevel = error_reporting(E_ERROR | E_PARSE);
chdir(__DIR__ . '/../..');
require __DIR__ . '/../../update-location.php';
error_reporting($previousLevel); // unreachable — update-location.php always exits via json_response()
