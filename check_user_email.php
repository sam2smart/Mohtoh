<?php
require_once 'includes/db.php';
$stmt = $pdo->prepare('SELECT email FROM users WHERE id = 2');
$stmt->execute();
$row = $stmt->fetch();
print_r($row);
