<?php
require_once 'includes/db.php';
try {
    $stmt = $pdo->query("SELECT 1");
    echo "DB connection successful!\n";
} catch (Exception $e) {
    echo "DB connection failed: " . $e->getMessage() . "\n";
}
