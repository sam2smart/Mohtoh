<?php
require 'includes/db.php';
$stmt = $pdo->query('SELECT id FROM users LIMIT 1');
print_r($stmt->fetch());
?>
