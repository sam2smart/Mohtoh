<?php
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/email-providers.php';

echo "Testing SmtpEmailProvider...\n";
$provider = new SmtpEmailProvider($pdo);
// Send a test email to a known address
$result = $provider->send('ajayisamuel46@gmail.com', 'Test Email', 'This is a test email from the system.');
print_r($result);
