<?php
// includes/diagnostics.php
// Admin -> System Settings -> Diagnostics. Each channel reports one of
// three honest states — 'healthy' (verified working), 'warning'
// (configured but not proven, or intentionally running in a non-live
// mode), 'offline' (configured but failing, or required and missing) —
// never a fake "OK". Nothing here sends a real message; test_email/
// test_sms in api/integration-center.php already do that and log to
// integration_logs, which this file reads back for "last test".

function _diag_last_log($pdo, string $channel, string $event) {
    $stmt = $pdo->prepare("SELECT status, message, created_at FROM integration_logs WHERE channel = ? AND event = ? ORDER BY created_at DESC LIMIT 1");
    $stmt->execute([$channel, $event]);
    return $stmt->fetch() ?: null;
}

function run_integration_diagnostics($pdo): array {
    $results = [];

    // ---- Database ----
    try {
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $results[] = ['channel' => 'Database', 'status' => 'healthy', 'detail' => "Connected ({$dbName}).", 'last_test' => date('Y-m-d H:i:s')];
    } catch (\Throwable $e) {
        $results[] = ['channel' => 'Database', 'status' => 'offline', 'detail' => 'Query failed: ' . $e->getMessage(), 'last_test' => date('Y-m-d H:i:s')];
    }

    // ---- Email (SMTP) ----
    $emailMode = get_integration_setting($pdo, 'integration_email_mode', EMAIL_PROVIDER_MODE);
    $lastEmailTest = _diag_last_log($pdo, 'email', 'test_send');
    if ($emailMode !== 'smtp') {
        $results[] = ['channel' => 'Email (SMTP)', 'status' => 'warning', 'detail' => 'Running in local log mode — verification emails are written to storage/dev-mail.log, not delivered to a real inbox.', 'last_test' => $lastEmailTest['created_at'] ?? null];
    } else {
        $host = get_integration_setting($pdo, 'integration_email_host', SMTP_HOST);
        $username = get_integration_setting($pdo, 'integration_email_username', SMTP_USERNAME);
        $port = (int) get_integration_setting($pdo, 'integration_email_port', SMTP_PORT);
        if ($host === '' || $username === '') {
            $results[] = ['channel' => 'Email (SMTP)', 'status' => 'offline', 'detail' => 'SMTP mode is selected but host/username are not configured.', 'last_test' => $lastEmailTest['created_at'] ?? null];
        } else {
            $socket = @stream_socket_client($host . ':' . $port, $errno, $errstr, 5);
            if ($socket) {
                fclose($socket);
                $status = $lastEmailTest && $lastEmailTest['status'] === 'success' ? 'healthy' : 'warning';
                $detail = $lastEmailTest
                    ? ($lastEmailTest['status'] === 'success' ? 'Host reachable; last test send succeeded.' : 'Host reachable, but the last test send failed: ' . $lastEmailTest['message'])
                    : "Host {$host}:{$port} is reachable, but no test email has been sent yet — use \"Send Test Email\" to confirm actual delivery.";
                $results[] = ['channel' => 'Email (SMTP)', 'status' => $status, 'detail' => $detail, 'last_test' => $lastEmailTest['created_at'] ?? null];
            } else {
                $results[] = ['channel' => 'Email (SMTP)', 'status' => 'offline', 'detail' => "Could not reach {$host}:{$port} ({$errstr}).", 'last_test' => $lastEmailTest['created_at'] ?? null];
            }
        }
    }

    // ---- SMS ----
    $smsMode = get_integration_setting($pdo, 'integration_sms_mode', SMS_PROVIDER_MODE);
    $lastSmsTest = _diag_last_log($pdo, 'sms', 'test_send');
    if ($smsMode === 'log') {
        $results[] = ['channel' => 'SMS / OTP', 'status' => 'warning', 'detail' => 'Running in local log mode — OTP codes are written to storage/dev-sms.log, not delivered to a real phone.', 'last_test' => $lastSmsTest['created_at'] ?? null];
    } else {
        $configured = [
            'termii' => get_integration_setting($pdo, 'integration_termii_api_key', TERMII_API_KEY),
            'twilio' => get_integration_setting($pdo, 'integration_twilio_account_sid', TWILIO_ACCOUNT_SID),
            'vonage' => get_integration_setting($pdo, 'integration_vonage_api_key', VONAGE_API_KEY),
            'africastalking' => get_integration_setting($pdo, 'integration_africastalking_api_key', AFRICASTALKING_API_KEY),
        ][$smsMode] ?? '';
        if ($configured === '') {
            $results[] = ['channel' => 'SMS / OTP', 'status' => 'offline', 'detail' => ucfirst($smsMode) . ' is selected but its API key is not configured.', 'last_test' => $lastSmsTest['created_at'] ?? null];
        } else {
            $status = $lastSmsTest && $lastSmsTest['status'] === 'success' ? 'healthy' : 'warning';
            $detail = $lastSmsTest
                ? ($lastSmsTest['status'] === 'success' ? 'Last test SMS sent successfully.' : 'Credentials present, but the last test send failed: ' . $lastSmsTest['message'])
                : ucfirst($smsMode) . ' credentials are present, but no test SMS has been sent yet — use "Send Test SMS" to confirm actual delivery.';
            $results[] = ['channel' => 'SMS / OTP', 'status' => $status, 'detail' => $detail, 'last_test' => $lastSmsTest['created_at'] ?? null];
        }
    }

    // ---- Paystack ----
    // Reflects the admin Enable/Disable switch (integrations row status) and the
    // last read-only "Test Connection" run from the Payment card. A disabled
    // provider is reported as offline for new charges — existing/in-flight
    // payments still verify (the disable gate is only on new-charge initiation).
    $lastPayTest = _diag_last_log($pdo, 'payment', 'test_connection');
    try {
        $paystack = paystack_config();
        if (!paystack_is_enabled()) {
            $results[] = ['channel' => 'Paystack', 'status' => 'offline', 'detail' => 'Disabled by admin in the Service Integration Center — no new charges are started. Existing/in-flight payments still verify.', 'last_test' => $lastPayTest['created_at'] ?? null];
        } else {
            $status = $paystack['mode'] === 'live' ? 'healthy' : 'warning';
            $detail = $paystack['mode'] === 'live' ? 'Enabled — live keys configured.' : 'Enabled — running in TEST/sandbox mode. Turn off Sandbox mode (with live keys) before accepting real payments.';
            if ($lastPayTest && $lastPayTest['status'] === 'success') {
                $detail .= ' Last test connection succeeded.';
            } elseif ($lastPayTest) {
                $status = 'offline';
                $detail .= ' Last test connection failed: ' . $lastPayTest['message'];
            } else {
                $detail .= ' No test connection run yet — use "Test Connection" in the Payment card to confirm the secret key.';
            }
            $results[] = ['channel' => 'Paystack', 'status' => $status, 'detail' => $detail, 'last_test' => $lastPayTest['created_at'] ?? null];
        }
    } catch (\Throwable $e) {
        $results[] = ['channel' => 'Paystack', 'status' => 'offline', 'detail' => $e->getMessage(), 'last_test' => $lastPayTest['created_at'] ?? null];
    }

    // ---- Webhook (Paystack) ----
    $results[] = [
        'channel' => 'Webhook (Paystack)',
        'status' => 'warning',
        'detail' => 'api/paystack-webhook.php verifies the X-Paystack-Signature header using the active secret key. Paystack cannot reach it while the app runs on app.mohtoh.com.ng — this only goes live once the site is on a public HTTPS domain and that URL is registered in the Paystack dashboard.',
        'last_test' => null,
    ];

    // ---- Maps (Google) ----
    require_once __DIR__ . '/google-maps.php';
    $lastMapsTest = _diag_last_log($pdo, 'maps', 'test_connection');
    if (!googleMapsConfigured($pdo)) {
        $results[] = ['channel' => 'Maps', 'status' => 'offline', 'detail' => 'No Google Maps key configured — save the browser/server keys in the Service Integration Center. Search falls back to Nominatim and trip distance to haversine meanwhile.', 'last_test' => $lastMapsTest['created_at'] ?? null];
    } else {
        $flags = googleMapsEnabledFlags($pdo);
        $on = array_keys(array_filter($flags));
        $detail = 'Google Maps active' . ($on ? ' — enabled: ' . implode(', ', $on) : '') . '.';
        $status = 'warning';
        if ($lastMapsTest && $lastMapsTest['status'] === 'success') {
            $status = 'healthy';
            $detail .= ' Last test connection succeeded.';
        } elseif ($lastMapsTest) {
            $status = 'offline';
            $detail .= ' Last test connection failed: ' . $lastMapsTest['message'];
        } else {
            $detail .= ' No test connection run yet — use "Test connection" in the Service Integration Center to confirm billing + enabled APIs.';
        }
        $results[] = ['channel' => 'Maps', 'status' => $status, 'detail' => $detail, 'last_test' => $lastMapsTest['created_at'] ?? null];
    }

    return $results;
}
