<?php
// includes/sms-providers.php
// SMS/OTP delivery, abstracted behind one interface so switching
// providers is a config change (includes/config.php's SMS_PROVIDER_MODE),
// never a code change. Preference order matches the brief: Termii first
// (Nigeria, generous free tier), then Twilio Verify, Vonage, Africa's
// Talking — all four are implemented; only one is active at a time.
//
// Every credential below is resolved through get_integration_setting()
// (Admin Dashboard -> System Settings -> Service Integration Center),
// falling back to the includes/config.php constant of the same name —
// see includes/integration-settings.php.
require_once __DIR__ . '/integration-settings.php';

interface SmsProvider {
    // Returns ['success' => bool, 'message' => string]. Never throws for
    // an ordinary delivery failure — callers treat a false 'success' as
    // "try again" the same as api/update-location.php treats a stale ping.
    public function send(string $e164Phone, string $message): array;
}

// A stored secret that can't be decrypted under the current key surfaces
// from get_integration_setting() as the INTEGRATION_SECRET_UNDECRYPTABLE
// sentinel (see includes/integration-settings.php). It is a non-empty
// string, so a bare `=== ''` credential check would sail past it and hand
// the literal sentinel to the provider API as the key/token. Each provider
// runs its decrypted secrets through this first and returns an actionable
// re-entry message instead — mirrors SmtpEmailProvider::send().
function sms_secret_unreadable(...$values): bool {
    if (!defined('INTEGRATION_SECRET_UNDECRYPTABLE')) {
        return false;
    }
    foreach ($values as $value) {
        if ($value === INTEGRATION_SECRET_UNDECRYPTABLE) {
            return true;
        }
    }
    return false;
}

// Default, zero-cost provider for local development. Never contacts a
// real network and never claims a message reached a real phone — it
// writes the OTP/message to a local log file so the flow is fully
// testable end to end before any real provider account exists. This is
// what "Do not implement fake verification" means in practice here: the
// OTP itself is genuinely random, hashed, time-limited and attempt-limited
// (see includes/verification.php) — only the delivery channel is a
// harmless stand-in until real keys are configured.
class LogSmsProvider implements SmsProvider {
    public function send(string $e164Phone, string $message): array {
        $line = sprintf("[%s] SMS to %s: %s\n", date('Y-m-d H:i:s'), $e164Phone, $message);
        $logDir = __DIR__ . '/../storage';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        @file_put_contents($logDir . '/dev-sms.log', $line, FILE_APPEND);
        return ['success' => true, 'message' => 'Logged locally (SMS_PROVIDER_MODE=log — no real SMS sent).'];
    }
}

// https://developer.termii.com/ — Nigeria-first, has a free developer
// tier. Uses Termii's generic /api/sms/send endpoint (works before any
// sender ID is approved by sending as a one-off message rather than
// requiring their OTP-specific /api/sms/otp/send flow).
class TermiiSmsProvider implements SmsProvider {
    private $pdo;
    public function __construct($pdo = null) { $this->pdo = $pdo; }
    private function cfg(string $key, $fallback) {
        return $this->pdo ? get_integration_setting($this->pdo, $key, $fallback) : $fallback;
    }
    public function send(string $e164Phone, string $message): array {
        $apiKey = $this->cfg('integration_termii_api_key', TERMII_API_KEY);
        $senderId = $this->cfg('integration_termii_sender_id', TERMII_SENDER_ID);
        if ($apiKey === '') {
            return ['success' => false, 'message' => 'Termii is selected but its API key is not configured.'];
        }
        if (sms_secret_unreadable($apiKey)) {
            return ['success' => false, 'message' => 'The saved Termii API key can no longer be read (the encryption key changed). Re-enter and save it in the Integration Center, then test again.'];
        }
        $payload = [
            'to' => ltrim($e164Phone, '+'),
            'from' => $senderId,
            'sms' => $message,
            'type' => 'plain',
            'channel' => 'generic',
            'api_key' => $apiKey,
        ];
        return mt_http_post_json('https://api.ng.termii.com/api/sms/send', $payload);
    }
}

// https://www.twilio.com/docs/verify — trial account has free credit.
//
// Sends via Twilio's Messages API, so the sender it puts on the wire must be a
// real Twilio-provisioned sender: an E.164 phone number in `From`, or a
// Messaging Service SID (MG...) in `MessagingServiceSid`. The Account SID
// (AC...) and the Verify Service SID (VA...) are NOT senders — passing either
// as `From` is what Twilio rejects with error 21212, Invalid From Number.
// Each credential therefore stays in its own field and is used for one thing:
// Account SID + Auth Token authenticate the request, the From Number is the
// caller ID, and the Verify Service SID is left alone (it belongs to the Verify
// API, which this provider does not call).
class TwilioSmsProvider implements SmsProvider {
    private $pdo;
    public function __construct($pdo = null) { $this->pdo = $pdo; }
    private function cfg(string $key, $fallback) {
        return $this->pdo ? get_integration_setting($this->pdo, $key, $fallback) : $fallback;
    }
    public function send(string $e164Phone, string $message): array {
        $sid = $this->cfg('integration_twilio_account_sid', TWILIO_ACCOUNT_SID);
        $token = $this->cfg('integration_twilio_auth_token', TWILIO_AUTH_TOKEN);
        $from = trim((string) $this->cfg('integration_twilio_from_number', TWILIO_FROM_NUMBER));
        if ($sid === '' || $token === '') {
            return ['success' => false, 'message' => 'Twilio is selected but credentials are not configured.'];
        }
        if (sms_secret_unreadable($token)) {
            return ['success' => false, 'message' => 'The saved Twilio auth token can no longer be read (the encryption key changed). Re-enter and save it in the Integration Center, then test again.'];
        }
        if ($from === '') {
            return ['success' => false, 'message' => 'Twilio From Number is not configured. Enter the Twilio-provisioned sender (an E.164 number such as +14155552671, or a Messaging Service SID) in the Integration Center under Twilio -> From Number.'];
        }
        // Guard the exact misconfiguration that produced error 21212: an
        // Account SID / Verify Service SID / API key SID pasted into the sender
        // field. Fail here with something actionable instead of letting Twilio
        // reject the send with "Invalid From Number (caller ID)".
        $prefix = strtoupper(substr($from, 0, 2));
        if (in_array($prefix, ['AC', 'VA', 'SK'], true)) {
            $labels = ['AC' => 'an Account SID', 'VA' => 'a Verify Service SID', 'SK' => 'an API Key SID'];
            return ['success' => false, 'message' => 'Twilio From Number is ' . $labels[$prefix] . ', which is not a valid SMS sender (Twilio error 21212). Enter the Twilio-provisioned phone number in E.164 format (e.g. +14155552671), or a Messaging Service SID (MG...), in the Integration Center under Twilio -> From Number.'];
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json';
        $payload = ['To' => $e164Phone, 'Body' => $message];
        // A Messaging Service is selected with MessagingServiceSid, never with
        // `From` — Twilio resolves the actual sender from the service's pool.
        if ($prefix === 'MG') {
            $payload['MessagingServiceSid'] = $from;
        } else {
            $payload['From'] = $from;
        }
        return mt_http_post_form($url, $payload, $sid, $token);
    }
}

// https://developer.vonage.com/en/verify/overview — trial credit on signup.
class VonageSmsProvider implements SmsProvider {
    private $pdo;
    public function __construct($pdo = null) { $this->pdo = $pdo; }
    private function cfg(string $key, $fallback) {
        return $this->pdo ? get_integration_setting($this->pdo, $key, $fallback) : $fallback;
    }
    public function send(string $e164Phone, string $message): array {
        $apiKey = $this->cfg('integration_vonage_api_key', VONAGE_API_KEY);
        $apiSecret = $this->cfg('integration_vonage_api_secret', VONAGE_API_SECRET);
        $brandName = $this->cfg('integration_vonage_brand_name', VONAGE_BRAND_NAME);
        if ($apiKey === '' || $apiSecret === '') {
            return ['success' => false, 'message' => 'Vonage is selected but credentials are not configured.'];
        }
        if (sms_secret_unreadable($apiSecret)) {
            return ['success' => false, 'message' => 'The saved Vonage API secret can no longer be read (the encryption key changed). Re-enter and save it in the Integration Center, then test again.'];
        }
        return mt_http_post_form('https://rest.nexmo.com/sms/json', [
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'to' => ltrim($e164Phone, '+'),
            'from' => $brandName,
            'text' => $message,
        ]);
    }
}

// https://africastalking.com/ — sandbox app + free simulator credits.
class AfricasTalkingSmsProvider implements SmsProvider {
    private $pdo;
    public function __construct($pdo = null) { $this->pdo = $pdo; }
    private function cfg(string $key, $fallback) {
        return $this->pdo ? get_integration_setting($this->pdo, $key, $fallback) : $fallback;
    }
    public function send(string $e164Phone, string $message): array {
        $username = $this->cfg('integration_africastalking_username', AFRICASTALKING_USERNAME);
        $apiKey = $this->cfg('integration_africastalking_api_key', AFRICASTALKING_API_KEY);
        $senderId = $this->cfg('integration_africastalking_sender_id', AFRICASTALKING_SENDER_ID);
        if ($username === '' || $apiKey === '') {
            return ['success' => false, 'message' => "Africa's Talking is selected but credentials are not configured."];
        }
        if (sms_secret_unreadable($apiKey)) {
            return ['success' => false, 'message' => "The saved Africa's Talking API key can no longer be read (the encryption key changed). Re-enter and save it in the Integration Center, then test again."];
        }
        $headers = ['apiKey: ' . $apiKey, 'Accept: application/json'];
        return mt_http_post_form('https://api.africastalking.com/version1/messaging', [
            'username' => $username,
            'to' => $e164Phone,
            'message' => $message,
            'from' => $senderId,
        ], null, null, $headers);
    }
}

function get_sms_provider($pdo = null): SmsProvider {
    $mode = $pdo ? get_integration_setting($pdo, 'integration_sms_mode', SMS_PROVIDER_MODE) : SMS_PROVIDER_MODE;
    switch ($mode) {
        case 'termii': return new TermiiSmsProvider($pdo);
        case 'twilio': return new TwilioSmsProvider($pdo);
        case 'vonage': return new VonageSmsProvider($pdo);
        case 'africastalking': return new AfricasTalkingSmsProvider($pdo);
        default: return new LogSmsProvider();
    }
}

// ---- Shared minimal HTTP helpers (no library dependency — no
// Composer/vendor directory exists in this project) ----
function mt_http_post_json(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        // 10s was too tight for this network's real-world latency to
        // Twilio/Termii/etc — observed connect+response time alone runs
        // ~8s, so a real send (slightly heavier than a bare connect) was
        // being aborted moments before it would have succeeded. 25s gives
        // real headroom without hanging the request indefinitely.
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'message' => "Network error contacting SMS provider: $error"];
    }
    return ['success' => $httpCode >= 200 && $httpCode < 300, 'message' => $raw];
}

function mt_http_post_form(string $url, array $fields, ?string $basicUser = null, ?string $basicPass = null, array $extraHeaders = []): array {
    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        // 10s was too tight for this network's real-world latency to
        // Twilio/Termii/etc — observed connect+response time alone runs
        // ~8s, so a real send (slightly heavier than a bare connect) was
        // being aborted moments before it would have succeeded. 25s gives
        // real headroom without hanging the request indefinitely.
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 25,
    ];
    if ($extraHeaders) {
        $options[CURLOPT_HTTPHEADER] = $extraHeaders;
    }
    if ($basicUser !== null) {
        $options[CURLOPT_USERPWD] = "$basicUser:$basicPass";
    }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
        return ['success' => false, 'message' => "Network error contacting provider: $error"];
    }
    return ['success' => $httpCode >= 200 && $httpCode < 300, 'message' => $raw];
}
