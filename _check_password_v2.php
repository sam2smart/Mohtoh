<?php
require 'includes/db.php';
require 'includes/integration-settings.php';
require 'includes/secrets.php';
$val = get_integration_setting($pdo, 'integration_email_password');
if ($val === INTEGRATION_SECRET_UNDECRYPTABLE) {
    echo "Password: UNDECRYPTABLE\n";
} else {
    echo "Password: OK\n";
}
?>
