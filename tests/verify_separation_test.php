<?php
// verify_separation_test.php
// End-to-end verification-separation test harness (CLI).
// Runs against the LIVE merit_drives database. Each scenario sets the two
// independent channel switches, registers a throwaway user through the REAL
// api/auth.php (in an isolated subprocess so json_response()->exit() can't
// kill the orchestrator), then asserts:
//   * which channels actually sent (DB evidence: email_verification_token /
//     otp_code), and that the DISABLED channel generated nothing
//   * the redirect returned (verify-account.php vs {user_type}/dashboard.php)
//   * verify-account.php renders only the enabled channel's card
//   * login ignores a disabled channel
// Usage: php tests/verify_separation_test.php
// WARNING: writes throwaway rows to the LIVE DB (users + integration_settings
// + integration_change_logs + verification_events). Test users are deleted
// after each scenario; channel-switch rows persist (they ARE the admin
// panel's saved state). Production data is not otherwise touched.

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/integration-settings.php';

$failures = 0;
$passes = 0;
function check($label, $cond, $detail = '') {
    global $failures, $passes;
    if ($cond) { $passes++; echo "  [PASS]  $label\n"; }
    else { $failures++; echo "  [FAIL]  $label" . ($detail ? " -- $detail" : '') . "\n"; }
}

// Run one request in an isolated worker process. mode 'auth' hits
// api/auth.php; mode 'verify_page' renders verify-account.php. Returns
// ['json'=>decoded-body, 'session'=>worker session, 'location'=>redirect,
//  'body'=>raw output, 'error'=>set only on spawn failure].
function run_worker($channels, array $extra = []) {
    $cfg = array_merge([
        'channels' => $channels,
        'policy'   => ['email' => true, 'phone' => true],
        'mode'     => 'auth',
        'post'     => [],
        'session'  => [],
    ], $extra);
    $resultFile = sys_get_temp_dir() . '/mt_verify_' . bin2hex(random_bytes(6)) . '.json';
    $cfgFile    = sys_get_temp_dir() . '/mt_verify_cfg_' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($cfgFile, json_encode($cfg));
    $cmd = '"' . PHP_BINARY . '" "' . __DIR__ . '/_worker.php" "' . $cfgFile . '" "' . $resultFile . '"';
    exec($cmd . ' 2>&1', $outLines, $exitCode);
    @unlink($cfgFile);
    if (!file_exists($resultFile)) {
        return ['error' => "worker produced no result (exit $exitCode): " . implode(' | ', $outLines)];
    }
    $result = json_decode(file_get_contents($resultFile), true);
    @unlink($resultFile);
    $result['json'] = json_decode($result['body'] ?? '', true);
    return $result;
}

// The register/login redirect lives at data.redirect in the JSON envelope.
function redirect_of($r) { return $r['json']['data']['redirect'] ?? ''; }
function status_of($r)   { return $r['json']['status'] ?? ''; }

function register_via_worker($channels, $post) {
    return run_worker($channels, ['post' => $post]);
}
function render_verify_account($channels, $userId) {
    return run_worker($channels, ['mode' => 'verify_page', 'session' => ['user_id' => $userId, 'user_type' => 'rider']]);
}

function user_evidence($pdo, $userId) {
    $stmt = $pdo->prepare('SELECT email_verification_token, otp_code, email_verified, phone_verified FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}
function cleanup_user($pdo, $userId) {
    if ($userId) { $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]); }
}
// Pre-clean any prior test users so re-runs never hit "already registered".
function purge_test_users($pdo) {
    $pdo->exec("DELETE FROM users WHERE email REGEXP '^t[0-9]_sep@example.com$'");
}

purge_test_users($pdo);
echo "=== Verification Separation End-to-End Test ===\n";

// ------------------------------------------------------------------ S1
echo "\n[Scenario 1] Email ON + Phone ON\n";
$u = register_via_worker(['email' => true, 'phone' => true], [
    'action' => 'register', 'fullname' => 'Test One', 'phone' => '08011112222',
    'email' => 't1_sep@example.com', 'password' => 'Secret123',
    'confirm_password' => 'Secret123', 'user_type' => 'rider',
]);
check('registration succeeded', status_of($u) === 'success', $u['error'] ?? ($u['json']['message'] ?? ''));
if (status_of($u) === 'success') {
    $userId = (int)($u['session']['user_id'] ?? 0);
    check('redirects to verify-account.php', redirect_of($u) === 'verify-account.php', redirect_of($u));
    $ev = user_evidence($pdo, $userId);
    check('email token generated (email channel sent)', !empty($ev['email_verification_token']));
    check('phone OTP generated (phone channel sent)', !empty($ev['otp_code']));
    $r = render_verify_account(['email' => true, 'phone' => true], $userId);
    check('verify page shows BOTH cards', strpos($r['body'], 'id="emailStatusIcon"') !== false && strpos($r['body'], 'id="phoneStatusIcon"') !== false, $r['error'] ?? '');
    cleanup_user($pdo, $userId);
}

// ------------------------------------------------------------------ S2
echo "\n[Scenario 2] Email ON + Phone OFF\n";
$u = register_via_worker(['email' => true, 'phone' => false], [
    'action' => 'register', 'fullname' => 'Test Two', 'phone' => '08022223333',
    'email' => 't2_sep@example.com', 'password' => 'Secret123',
    'confirm_password' => 'Secret123', 'user_type' => 'driver',
]);
check('registration succeeded', status_of($u) === 'success', $u['error'] ?? ($u['json']['message'] ?? ''));
if (status_of($u) === 'success') {
    $userId = (int)($u['session']['user_id'] ?? 0);
    check('redirects to verify-account.php (email card needed)', redirect_of($u) === 'verify-account.php', redirect_of($u));
    $ev = user_evidence($pdo, $userId);
    check('email token generated (email channel sent)', !empty($ev['email_verification_token']));
    check('NO phone OTP generated (phone channel skipped)', empty($ev['otp_code']));
    $r = render_verify_account(['email' => true, 'phone' => false], $userId);
    check('verify page shows ONLY email card (no phone card)', strpos($r['body'], 'id="emailStatusIcon"') !== false && strpos($r['body'], 'id="phoneStatusIcon"') === false, $r['error'] ?? '');
    check('no "currently disabled" placeholder text', strpos($r['body'], 'currently disabled') === false);
    cleanup_user($pdo, $userId);
}

// ------------------------------------------------------------------ S3
echo "\n[Scenario 3] Email OFF + Phone ON\n";
$u = register_via_worker(['email' => false, 'phone' => true], [
    'action' => 'register', 'fullname' => 'Test Three', 'phone' => '08033334444',
    'email' => 't3_sep@example.com', 'password' => 'Secret123',
    'confirm_password' => 'Secret123', 'user_type' => 'rider',
]);
check('registration succeeded', status_of($u) === 'success', $u['error'] ?? ($u['json']['message'] ?? ''));
if (status_of($u) === 'success') {
    $userId = (int)($u['session']['user_id'] ?? 0);
    check('redirects to verify-account.php (phone card needed)', redirect_of($u) === 'verify-account.php', redirect_of($u));
    $ev = user_evidence($pdo, $userId);
    check('NO email token generated (email channel skipped)', empty($ev['email_verification_token']));
    check('phone OTP generated (phone channel sent)', !empty($ev['otp_code']));
    $r = render_verify_account(['email' => false, 'phone' => true], $userId);
    check('verify page shows ONLY phone card (no email card)', strpos($r['body'], 'id="phoneStatusIcon"') !== false && strpos($r['body'], 'id="emailStatusIcon"') === false, $r['error'] ?? '');
    check('no "currently disabled" placeholder text', strpos($r['body'], 'currently disabled') === false);
    cleanup_user($pdo, $userId);
}

// ------------------------------------------------------------------ S4
echo "\n[Scenario 4] Email OFF + Phone OFF\n";
$u = register_via_worker(['email' => false, 'phone' => false], [
    'action' => 'register', 'fullname' => 'Test Four', 'phone' => '08044445555',
    'email' => 't4_sep@example.com', 'password' => 'Secret123',
    'confirm_password' => 'Secret123', 'user_type' => 'driver',
]);
check('registration succeeded', status_of($u) === 'success', $u['error'] ?? ($u['json']['message'] ?? ''));
if (status_of($u) === 'success') {
    $userId = (int)($u['session']['user_id'] ?? 0);
    check('redirects STRAIGHT to dashboard (skips verify-account.php)', redirect_of($u) === 'driver/dashboard.php', redirect_of($u));
    $ev = user_evidence($pdo, $userId);
    check('NO email token generated (nothing sent)', empty($ev['email_verification_token']));
    check('NO phone OTP generated (nothing sent)', empty($ev['otp_code']));
    // Direct hit on verify-account.php (e.g. stale bookmark) must bounce to
    // dashboard. NOTE: the PHP CLI SAPI does not record header() calls, so
    // headers_list() is always empty here — instead we assert the redirect's
    // OBSERVABLE effect: the page exit()s before emitting any HTML, so the
    // "Verify your account" heading and both cards are absent from the body.
    $r = render_verify_account(['email' => false, 'phone' => false], $userId);
    $noCards = strpos($r['body'] ?? '', 'Verify your account') === false
        && strpos($r['body'] ?? '', 'id="emailStatusIcon"') === false
        && strpos($r['body'] ?? '', 'id="phoneStatusIcon"') === false;
    check('verify-account.php renders NO cards when both OFF (redirected out)', $noCards, 'body len=' . strlen($r['body'] ?? ''));
    cleanup_user($pdo, $userId);
}

// ------------------------------------------------------------------ Login gate
echo "\n[Login gate] Disabled channel ignored at login\n";
$u = register_via_worker(['email' => true, 'phone' => false], [
    'action' => 'register', 'fullname' => 'Test Five', 'phone' => '08055556666',
    'email' => 't5_sep@example.com', 'password' => 'Secret123',
    'confirm_password' => 'Secret123', 'user_type' => 'rider',
]);
if (status_of($u) === 'success') {
    $userId = (int)($u['session']['user_id'] ?? 0);
    // Simulate the emailed link click: email verified, phone LEFT unverified.
    $pdo->prepare('UPDATE users SET email_verified = 1, email_verified_at = ? WHERE id = ?')
        ->execute([date('Y-m-d H:i:s'), $userId]);
    $u2 = run_worker(['email' => true, 'phone' => false], ['post' => [
        'action' => 'login', 'email' => 't5_sep@example.com', 'password' => 'Secret123',
    ]]);
    check('login w/ phone unverified + phone channel OFF -> dashboard', status_of($u2) === 'success' && redirect_of($u2) === 'rider/dashboard.php', json_encode($u2['json'] ?? $u2));
    cleanup_user($pdo, $userId);
} else {
    check('login-gate setup registration succeeded', false, $u['error'] ?? ($u['json']['message'] ?? ''));
}

purge_test_users($pdo);
echo "\n=== Results: $passes passed, $failures failed ===\n";
exit($failures ? 1 : 0);
