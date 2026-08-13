<?php
require 'includes/db.php';
require 'includes/integration-settings.php';
$val = get_integration_setting($pdo, 'integration_email_mode');
echo "Mode: " . $val . "\n";
?>
