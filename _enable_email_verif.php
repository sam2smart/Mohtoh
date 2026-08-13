<?php
require 'includes/db.php';
$stmt = $pdo->query('UPDATE integration_settings SET setting_value = 1 WHERE setting_key = "integration_email_verification_enabled"');
echo "Enabled email verification.\n";
?>
