<?php
require_once 'bootstrap.php';
require_once 'includes/verification.php';

echo "Requesting password reset for johndoe@example.com...\n";
$result = request_password_reset($pdo, 'johndoe@example.com');
print_r($result);
