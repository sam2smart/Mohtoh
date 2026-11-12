<?php
// api/fare-settings.php - Admin manage fare configuration

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/FareCalculator.php';

header('Content-Type: application/json');

require_once '../includes/admin_permissions.php';
// Access-Control: fare configuration is read-only here (writes are retired)
// -> pricing.view. Actual pricing writes live in api/pricing-admin.php (SUPER).
require_admin_permission($pdo, 'pricing.view');

$fareCalc = new FareCalculator($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get all fare settings
    try {
        $settings = $fareCalc->getAllSettings();
        
        // Get pricing configuration
        $stmt = $pdo->query("SELECT * FROM pricing_configuration ORDER BY star_rating ASC");
        $multipliers = $stmt->fetchAll();

        json_response('success', 'Fare settings retrieved', [
            'settings' => $settings,
            'multipliers' => $multipliers
        ]);
    } catch (Exception $e) {
        json_response('error', 'Failed to retrieve settings');
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Writes retired in favor of the consolidated, super-admin-gated,
    // audit-logged api/pricing-admin.php — this used to be a second,
    // disconnected writer of these same system_settings keys (and the
    // only one actually reaching live pricing via price_per_km/base_fare/
    // minimum_fare). GET above still works unchanged for any existing
    // reader.
    json_response('error', 'This endpoint no longer accepts writes. Use api/pricing-admin.php.');

} else {
    json_response('error', 'Invalid request method');
}
?>
