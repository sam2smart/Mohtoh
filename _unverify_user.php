<?php
require 'includes/db.php';
$stmt = $pdo->prepare('UPDATE users SET email_verified = 0, phone_verified = 0 WHERE id = 2');
$stmt->execute();
echo "Unverified user 2.\n";
?>
