<?php
require_once 'includes/db.php';
require_once 'includes/integration-settings.php';

$stmt = $pdo->query('SELECT setting_key, setting_value, is_secret FROM integration_settings');
foreach ($stmt->fetchAll() as $row) {
    echo $row['setting_key'] . ': ' . ($row['is_secret'] ? '***' : $row['setting_value']) . "\n";
}
