<?php
require 'includes/db.php';
$stmt = $pdo->query('UPDATE integration_settings SET setting_value = 0 WHERE setting_key IN ("integration_email_verification_enabled", "integration_phone_verification_enabled")');
echo "Disabled verification.\n";
?>
