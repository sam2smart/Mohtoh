<?php
require_once 'includes/db.php';

$stmt = $pdo->query('SELECT user_id, type, action, meta, created_at FROM verification_events ORDER BY created_at DESC LIMIT 5');
foreach ($stmt->fetchAll() as $row) {
    echo "User: " . $row['user_id'] . ", Type: " . $row['type'] . ", Action: " . $row['action'] . ", Meta: " . $row['meta'] . ", Created: " . $row['created_at'] . "\n";
}
