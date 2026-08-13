<?php
// includes/email-providers.php
// Email delivery, abstracted the same way as includes/sms-providers.php.
// SmtpEmailProvider uses PHPMailer (composer.json — installed via
// `composer require phpmailer/phpmailer`) rather than a hand-rolled
// socket client, for correct EHLO/AUTH/multi-recipient/encoding handling
// against real-world mail servers (Gmail, Outlook365, SES SMTP, etc).
//
// Every setting below is resolved through get_integration_setting()
// (Admin Dashboard -> System Settings -> Service Integration Center),
// falling back to the includes/config.php constant of the same name if
// nothing has been saved in the admin UI yet — see
// includes/integration-settings.php for why that guarantees no
// regression for installs that never touch the new UI.
require_once __DIR__ . '/integration-settings.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

interface EmailProvider {
    public function send(string $toEmail, string $subject, string $htmlBody): array;
}

// Safe local-dev default — see LogSmsProvider's docblock for the same
// reasoning. Writes the actual email (including the real verification
// link/token) to storage/dev-mail.log instead of contacting a mail
// server, so the whole flow is testable with zero setup and zero cost.
class LogEmailProvider implements EmailProvider {
    public function send(string $toEmail, string $subject, string $htmlBody): array {
        $logDir = __DIR__ . '/../storage';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        // Raw HTML is kept (not stripped) so the actual verification/reset
        // link's href is visible here — this log is the only way to
        // retrieve it while EMAIL_PROVIDER_MODE=log (no real mailbox).
        $line = sprintf("\n===== [%s] EMAIL to %s =====\nSubject: %s\n\n%s\n", date('Y-m-d H:i:s'), $toEmail, $subject, $htmlBody);
        @file_put_contents($logDir . '/dev-mail.log', $line, FILE_APPEND);
        return ['success' => true, 'message' => 'Logged locally (EMAIL_PROVIDER_MODE=log — no real email sent).'];
    }
}

// Works with any SMTP-compatible relay — plain SMTP, or the SMTP relay
// endpoints of SendGrid/Mailgun/Amazon SES/Brevo, all of which are just
// "host + port + username + password" against this same client. The
// admin UI's provider picker only pre-fills host/port for those; there
// is deliberately one SMTP client, not six near-identical ones.
class SmtpEmailProvider implements EmailProvider {
    private $pdo;
    public function __construct($pdo = null) {
        $this->pdo = $pdo;
    }

    private function cfg(string $key, $fallback) {
        return $this->pdo ? get_integration_setting($this->pdo, $key, $fallback) : $fallback;
    }

    public function send(string $toEmail, string $subject, string $htmlBody): array {
        $host = $this->cfg('integration_email_host', SMTP_HOST);
        $port = (int)$this->cfg('integration_email_port', SMTP_PORT);
        $username = $this->cfg('integration_email_username', SMTP_USERNAME);
        $password = $this->cfg('integration_email_password', SMTP_PASSWORD);
        $encryption = $this->cfg('integration_email_encryption', SMTP_ENCRYPTION);
        $fromEmail = $this->cfg('integration_email_from_email', SMTP_FROM_EMAIL);
        $fromName = $this->cfg('integration_email_from_name', SMTP_FROM_NAME);
        $replyTo = $this->cfg('integration_email_reply_to', $fromEmail);
error_log("Sending email. Host: $host, Username: $username, Port: $port, Encryption: $encryption, From: $fromEmail\n", 3, __DIR__ . '/../error_log.txt');

if ($host === '' || $username === '') {
    return ['success' => false, 'message' => 'SMTP is selected but host/username are not configured.'];
}

if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
    error_log("Invalid email address: $toEmail\n", 3, __DIR__ . '/../error_log.txt');
    return ['success' => false, 'message' => 'Invalid email address: ' . $toEmail];
}

// A stored secret that can't be decrypted under the current key
// surfaces as this sentinel. Never hand it to the SMTP server as a
// password — tell the admin to re-enter the credential instead, which
// re-encrypts it under the active key.
if (defined('INTEGRATION_SECRET_UNDECRYPTABLE')
    && ($password === INTEGRATION_SECRET_UNDECRYPTABLE || $username === INTEGRATION_SECRET_UNDECRYPTABLE)) {
    return ['success' => false, 'message' => 'The saved SMTP password can no longer be read (the encryption key changed). Re-enter and save the SMTP password in the Integration Center, then test again.'];
}

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $password;
    // ...

            // Gmail/Outlook/SES SMTP all speak STARTTLS on 587 or implicit
            // TLS on 465 — 'tls' here means STARTTLS (PHPMailer's
            // ENCRYPTION_STARTTLS), 'ssl' means implicit TLS from the
            // first byte (ENCRYPTION_SMTPS), matching how port 465 vs 587
            // actually behave. Gmail: use an App Password (not the account
            // password) — code once 2-Step Verification is on.
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }
            $mail->CharSet = 'UTF-8';
            $mail->Timeout = 15;

            $mail->setFrom($fromEmail, $fromName);
            if ($replyTo !== '') {
                $mail->addReplyTo($replyTo);
            }
            $mail->addAddress($toEmail);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags($htmlBody));

            $success = $mail->send();
            if (!$success) {
                 error_log("PHPMailer send() returned false. Error: " . $mail->ErrorInfo . "\n", 3, __DIR__ . '/../error_log.txt');
                 return ['success' => false, 'message' => 'SMTP error: ' . $mail->ErrorInfo];
            }
            return ['success' => true, 'message' => 'Email sent.'];
        } catch (PHPMailerException $e) {
            error_log("PHPMailer SMTP error: " . $mail->ErrorInfo . "\n", 3, __DIR__ . '/../error_log.txt');
            return ['success' => false, 'message' => 'SMTP error: ' . $mail->ErrorInfo];
        } catch (\Throwable $e) {
            error_log("PHPMailer throwable error: " . $e->getMessage() . "\n", 3, __DIR__ . '/../error_log.txt');
            return ['success' => false, 'message' => 'SMTP error: ' . $e->getMessage()];
        }
    }
}

function get_email_provider($pdo = null): EmailProvider {
    $mode = $pdo ? get_integration_setting($pdo, 'integration_email_mode', EMAIL_PROVIDER_MODE) : EMAIL_PROVIDER_MODE;
    error_log("Email provider mode: " . $mode);
    return $mode === 'smtp' ? new SmtpEmailProvider($pdo) : new LogEmailProvider();
}
