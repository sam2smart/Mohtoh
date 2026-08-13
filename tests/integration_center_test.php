<?php
// tests/integration_center_test.php
// Verifies the Service Integration Center save -> DB -> reload round-trip
// and the Test Email path, through the REAL api/integration-center.php code
// (GET builds the payload the admin JS binds to; POST save_* writes the DB).
// Each request runs in an isolated subprocess (json_response()->exit()).
// Usage: php tests/integration_center_test.php

error_reporting(E_ALL);
ini_set('display_errors', '1');
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/integration-settings.php';

$failures = 0; $passes = 0;
function check($label, $cond, $detail = '') {
    global $failures, $passes;
    if ($cond) { $passes++; echo "  [PASS]  $label\n"; }
    else { $failures++; echo "  [FAIL]  $label" . ($detail ? " -- $detail" : ''). "\n"; }
}

// Run api/integration-center.php in an isolated process as a super-admin.
function ic_request($method, $payload = []) {
    $cfg = ['method' => $method, 'payload' => $payload];
    $resultFile = sys_get_temp_dir() . '/mt_ic_' . bin2hex(random_bytes(6)) . '.json';
    $cfgFile    = sys_get_temp_dir() . '/mt_ic_cfg_' . bin2hex(random_bytes(6)) . '.json';
    file_put_contents($cfgFile, json_encode($cfg));
    $cmd = '"' . PHP_BINARY . '" "' . __DIR__ . '/_ic_worker.php" "' . $cfgFile . '" "' . $resultFile . '"';
    exec($cmd . ' 2>&1', $o, $code);
    @unlink($cfgFile);
    if (!file_exists($resultFile)) return ['error' => "no result (exit $code): " . implode(' | ', $o)];
    $res = json_decode(file_get_contents($resultFile), true);
    @unlink($resultFile);
    $res['json'] = json_decode($res['body'] ?? '', true);
    return $res;
}

// The subprocess wrote to the DB and busted ITS OWN in-process cache; this
// orchestrator holds a separate static cache from its top-of-file snapshot.
// In production every request is a fresh process, so this staleness never
// occurs — but in this single long-lived orchestrator we must drop the cache
// before each fresh-from-DB read, exactly as set_integration_setting() does.
function db_read($pdo, $key, $fallback = null) {
    unset($GLOBALS['_integration_settings_cache']);
    return get_integration_setting($pdo, $key, $fallback);
}

echo "=== Service Integration Center round-trip test ===\n";

// Snapshot original values we will mutate, to restore at the end.
$origFromName = get_integration_setting($pdo, 'integration_email_from_name', 'MohToh');
$origReplyTo  = get_integration_setting($pdo, 'integration_email_reply_to', '');
$origEmailVer = get_integration_setting($pdo, 'integration_email_verification_enabled', '1');
$origPhoneVer = get_integration_setting($pdo, 'integration_phone_verification_enabled', '1');

// ---- GET loads current values (the admin JS binds these) ----
echo "\n[Load] GET returns the settings payload\n";
$get = ic_request('GET');
check('GET succeeds', ($get['json']['status'] ?? '') === 'success', $get['error'] ?? '');
$data = $get['json']['data'] ?? [];
check('email block present (Email Settings load)', isset($data['email']) && array_key_exists('from_name', $data['email']));
check('sms block present (Phone Settings load)', isset($data['sms']) && array_key_exists('mode', $data['sms']));
check('verification block uses NEW phone key', isset($data['verification']['phone_verification_enabled']));
check('verification block has NO old sms key', !isset($data['verification']['sms_verification_enabled']));
check('email password returned masked, never raw', is_array($data['email']['password'] ?? null) && array_key_exists('masked', $data['email']['password']));

// ---- POST save_email persists to DB, refresh shows it ----
echo "\n[Save Email] save_email writes DB and survives reload\n";
$newFromName = 'MohToh QA ' . substr(bin2hex(random_bytes(3)), 0, 6);
$save = ic_request('POST', ['action' => 'save_email', 'settings' => ['from_name' => $newFromName, 'reply_to' => 'qa@mohtoh.test']]);
check('save_email returns success', ($save['json']['status'] ?? '') === 'success', $save['error'] ?? ($save['json']['message'] ?? ''));
// Read straight from DB (cache-busted) to prove it actually persisted.
$dbVal = db_read($pdo, 'integration_email_from_name', null);
check('DB actually updated (not faked)', $dbVal === $newFromName, "db=$dbVal expected=$newFromName");
$get2 = ic_request('GET');
check('reload (GET) shows the saved from_name', ($get2['json']['data']['email']['from_name'] ?? '') === $newFromName);

// ---- POST save_verification round-trips both independent switches ----
echo "\n[Save Verification] both switches persist independently\n";
$sv = ic_request('POST', ['action' => 'save_verification', 'settings' => ['email_verification_enabled' => true, 'phone_verification_enabled' => false]]);
check('save_verification success', ($sv['json']['status'] ?? '') === 'success', $sv['error'] ?? '');
check('email switch persisted = 1', db_read($pdo, 'integration_email_verification_enabled', null) === '1');
check('phone switch persisted = 0 (independent)', db_read($pdo, 'integration_phone_verification_enabled', null) === '0');
check('NO write to old sms_verification key', db_read($pdo, 'integration_sms_verification_enabled', 'ABSENT') === 'ABSENT');
check('NO write to SMS provider mode (provider untouched)', db_read($pdo, 'integration_sms_mode', null) !== '0' && db_read($pdo, 'integration_sms_mode', null) !== '');

// ---- Test Email path (log provider = deterministic success, no real send) ----
echo "\n[Test Email] test_email reaches the provider and returns a result\n";
$te = ic_request('POST', ['action' => 'test_email', 'to' => 'qa@example.com']);
check('test_email returns a status', in_array($te['json']['status'] ?? '', ['success', 'error'], true), $te['error'] ?? '');
check('test_email rejects invalid address', (ic_request('POST', ['action' => 'test_email', 'to' => 'not-an-email'])['json']['status'] ?? '') === 'error');

// ---- Restore original values ----
$restore = ic_request('POST', ['action' => 'save_email', 'settings' => ['from_name' => $origFromName, 'reply_to' => $origReplyTo]]);
ic_request('POST', ['action' => 'save_verification', 'settings' => ['email_verification_enabled' => $origEmailVer === '1', 'phone_verification_enabled' => $origPhoneVer === '1']]);
echo "\n(restored from_name='$origFromName', verification switches to email=$origEmailVer/phone=$origPhoneVer)\n";

echo "\n=== Results: $passes passed, $failures failed ===\n";
exit($failures ? 1 : 0);
