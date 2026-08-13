<?php
require 'includes/config.php';
require 'includes/db.php';
require 'includes/verification.php';
$result = send_email_verification($pdo, 2);
print_r($result);
?>
