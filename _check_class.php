<?php
require 'includes/config.php';
require 'includes/db.php';
require 'includes/email-providers.php';
$provider = get_email_provider($pdo);
$rc = new ReflectionClass($provider);
echo "Class: " . $rc->getName() . "\n";
?>
