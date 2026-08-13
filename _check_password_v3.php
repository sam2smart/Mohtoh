<?php
require 'includes/db.php';
require 'includes/integration-settings.php';
$val = get_integration_setting($pdo, 'integration_email_password');
echo "Password Value: " . $val . "\n";
echo "Password Value Length: " . strlen($val) . "\n";
?>
