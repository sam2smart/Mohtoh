<?php
// api/waiting-pricing.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/WaitingPricingCalculator.php';

header('Content-Type: application/json');

if (!is_logged_in()) json_response('error', 'Unauthorized');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$calculator = new WaitingPricingCalculator($pdo);

if ($action === 'calculate_fee') {
    $duration = (int)($_GET['duration'] ?? 0);
    $fee = $calculator->calculateFee($duration);
    json_response('success', 'Fee calculated', ['fee' => $fee]);

} elseif ($action === 'get_status') {
    $trip_id = (int)($_GET['trip_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT * FROM waiting_events WHERE trip_id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$trip_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($event) {
        $fee = $calculator->calculateFee($event['duration_seconds']);
        json_response('success', 'Status active', [
            'waiting' => true,
            'duration' => $event['duration_seconds'],
            'fee' => $fee
        ]);
    } else {
        json_response('success', 'Status inactive', ['waiting' => false]);
    }
}
?>
