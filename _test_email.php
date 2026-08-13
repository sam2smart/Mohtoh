<?php
require 'includes/db.php';
require 'includes/email-providers.php';
$provider = get_email_provider($pdo);
$result = $provider->send('t1_sep@example.com', 'Test Email', 'This is a test.');
print_r($result);
?>
