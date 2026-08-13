<?php
require_once __DIR__ . '/includes/db.php';
echo "=== integrations table: all rows ===\n";
foreach ($pdo->query("SELECT id, provider, category, display_name, status, is_default, priority, LEFT(configuration,120) AS cfg FROM integrations ORDER BY category, priority") as $r) {
    echo sprintf("[%s] %-22s cat=%-14s status=%-9s def=%s :: %s\n",
        $r['id'], $r['provider'], $r['category'], $r['status'], $r['is_default'], $r['cfg']);
}
echo "\n=== integration_settings: all setting_key rows ===\n";
foreach ($pdo->query("SELECT setting_key, is_secret, (setting_value IS NULL) AS is_null FROM integration_settings ORDER BY setting_key") as $r) {
    echo sprintf("  %-48s secret=%s null=%s\n", $r['setting_key'], $r['is_secret'], $r['is_null']);
}
echo "\n=== users: verification-related columns present? ===\n";
foreach ($pdo->query("SHOW COLUMNS FROM users") as $c) {
    if (preg_match('/verif|otp|email|phone/i', $c['Field'])) echo "  {$c['Field']} ({$c['Type']})\n";
}
