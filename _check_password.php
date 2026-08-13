<?php
require 'includes/db.php';
require 'includes/integration-settings.php';
require 'includes/secrets.php';
$val = get_integration_setting($pdo, 'integration_email_password');
echo "Value: " . $val . "\n";
?>
