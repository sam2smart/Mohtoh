<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT setting_key, setting_value FROM integration_settings WHERE setting_key = 'integration_email_mode'");
print_r($stmt->fetch());
?>
