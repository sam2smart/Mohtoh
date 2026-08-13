<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/email-providers.php';

$to = 'ajayisamuel46@gmail.com'; // Use a different email address if possible
$html = '<div style="font-family:Arial,sans-serif;max-width:480px;margin:0 auto;padding:24px;">'
    . '<h2 style="color:#0F8B8D;">MohToh Integration Test</h2>'
    . '<p>This is a test email from the MohToh Service Integration Center. If you received this, your email delivery is configured correctly.</p>'
    . '</div>';
$result = get_email_provider($pdo)->send($to, 'MohToh Integration Test Email', $html);
print_r($result);
