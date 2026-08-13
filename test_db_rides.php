<?php
require 'includes/db.php';
$stmt = $pdo->query("SELECT * FROM ride_shares WHERE status = 'active' AND departure_date >= CURDATE() LIMIT 5");
$rides = $stmt->fetchAll();
print_r($rides);
?>
