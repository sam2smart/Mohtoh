<?php
require 'includes/db.php';
require 'includes/integration-settings.php';
$cache = _integration_settings_cache($pdo);
print_r($cache);
?>
