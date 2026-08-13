<?php
require 'includes/config.php';
require 'includes/db.php';
require 'includes/integration-settings.php';
echo 'EMAIL_PROVIDER_MODE constant: ' . (defined('EMAIL_PROVIDER_MODE') ? EMAIL_PROVIDER_MODE : 'undefined') . "\n";
echo 'DB Setting: ' . get_integration_setting($pdo, 'integration_email_mode', 'NOT_FOUND') . "\n";
?>
