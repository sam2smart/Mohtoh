<?php
require 'includes/db.php';
require 'includes/verification.php';
$result = send_email_verification($pdo, 1);
print_r($result);
?>
