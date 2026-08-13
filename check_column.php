<?php
require 'includes/db.php';
$stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'driver_tier'");
$result = $stmt->fetch();
print_r($result);
?>
