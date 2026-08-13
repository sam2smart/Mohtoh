<?php
require_once 'bootstrap.php';
require_once 'includes/verification.php';

// User 2 exists
$userId = 2;
echo "Testing send_email_verification for user $userId...\n";
$result = send_email_verification($pdo, $userId);
print_r($result);
