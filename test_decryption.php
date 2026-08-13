<?php
require_once 'includes/db.php';
require_once 'includes/secrets.php';
require_once 'includes/integration-settings.php';

$stmt = $pdo->prepare('SELECT setting_value FROM integration_settings WHERE setting_key = ?');
$stmt->execute(['integration_email_password']);
$row = $stmt->fetch();

if ($row) {
    $encrypted = $row['setting_value'];
    $decrypted = decrypt_secret($encrypted);
    echo "Decrypted password: " . ($decrypted === null ? 'NULL (FAILED)' : 'SUCCESS') . "\n";
} else {
    echo "Password not found in integration_settings.\n";
}
