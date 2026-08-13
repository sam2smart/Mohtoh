<?php
// api/call-management.php - Handle call initiation, state management, and termination

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $other_user_id = (int)($input['other_user_id'] ?? 0);

    if ($other_user_id <= 0) {
        json_response('error', 'Other user ID required');
    }

    if ($action === 'initiate') {
        // Rider or driver initiates a call
        $call_id = generate_unique_public_id($pdo, 'call_sessions', 'call_id', 'CALL');
        $initiated_by = $_SESSION['user_type'];

        try {
            // Store call session
            $stmt = $pdo->prepare("
                INSERT INTO call_sessions (call_id, initiator_id, recipient_id, status)
                VALUES (?, ?, ?, 'ringing')
            ");
            $stmt->execute([$call_id, $user_id, $other_user_id]);

            // Send signal to recipient
            $signal_msg = "__CALL_INIT__{$call_id}";
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $other_user_id, $signal_msg]);

            json_response('success', 'Call initiated', [
                'call_id' => $call_id,
                'status' => 'ringing'
            ]);
        } catch (Exception $e) {
            json_response('error', 'Failed to initiate call');
        }

    } elseif ($action === 'answer') {
        // Recipient answers the call
        $call_id = $input['call_id'] ?? '';

        if (empty($call_id)) {
            json_response('error', 'Call ID required');
        }

        try {
            // Update call session status
            $stmt = $pdo->prepare("
                UPDATE call_sessions SET status = 'active' 
                WHERE call_id = ? AND recipient_id = ?
            ");
            $stmt->execute([$call_id, $user_id]);

            // Send answer signal
            $signal_msg = "__CALL_ANSWER__{$call_id}";
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $other_user_id, $signal_msg]);

            json_response('success', 'Call answered', [
                'call_id' => $call_id,
                'status' => 'active'
            ]);
        } catch (Exception $e) {
            json_response('error', 'Failed to answer call');
        }

    } elseif ($action === 'reject') {
        // Recipient rejects the call
        $call_id = $input['call_id'] ?? '';

        if (empty($call_id)) {
            json_response('error', 'Call ID required');
        }

        try {
            // Update call session status
            $stmt = $pdo->prepare("
                UPDATE call_sessions SET status = 'rejected' 
                WHERE call_id = ? AND recipient_id = ?
            ");
            $stmt->execute([$call_id, $user_id]);

            // Send rejection signal
            $signal_msg = "__CALL_REJECTED__{$call_id}";
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $other_user_id, $signal_msg]);

            json_response('success', 'Call rejected');
        } catch (Exception $e) {
            json_response('error', 'Failed to reject call');
        }

    } elseif ($action === 'terminate') {
        // Either party terminates the active call
        $call_id = $input['call_id'] ?? '';

        if (empty($call_id)) {
            json_response('error', 'Call ID required');
        }

        try {
            // Fetch call session
            $stmt = $pdo->prepare("
                SELECT * FROM call_sessions WHERE call_id = ?
            ");
            $stmt->execute([$call_id]);
            $callSession = $stmt->fetch();

            if (!$callSession) {
                json_response('error', 'Call session not found');
            }

            // Update call session status to ended
            $stmt = $pdo->prepare("
                UPDATE call_sessions SET status = 'ended', ended_at = NOW()
                WHERE call_id = ?
            ");
            $stmt->execute([$call_id]);

            // Send hangup signal to other party
            $signal_msg = "__CALL_HANGUP__{$call_id}";
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, message)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$user_id, $other_user_id, $signal_msg]);

            json_response('success', 'Call terminated successfully', [
                'call_id' => $call_id,
                'status' => 'ended'
            ]);
        } catch (Exception $e) {
            json_response('error', 'Failed to terminate call');
        }

    } elseif ($action === 'get_status') {
        // Check current call status
        $call_id = $input['call_id'] ?? '';

        if (empty($call_id)) {
            json_response('error', 'Call ID required');
        }

        try {
            $stmt = $pdo->prepare("
                SELECT * FROM call_sessions WHERE call_id = ?
            ");
            $stmt->execute([$call_id]);
            $callSession = $stmt->fetch();

            if (!$callSession) {
                json_response('success', 'No active call', [
                    'status' => 'no_call'
                ]);
            }

            // Clean up old expired calls (older than 1 hour)
            $stmt = $pdo->prepare("
                DELETE FROM call_sessions 
                WHERE status IN ('rejected', 'ended') AND created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
            ");
            $stmt->execute();

            json_response('success', 'Call status retrieved', [
                'call_id' => $callSession['call_id'],
                'status' => $callSession['status'],
                'created_at' => $callSession['created_at']
            ]);
        } catch (Exception $e) {
            json_response('error', 'Failed to retrieve call status');
        }

    } else {
        json_response('error', 'Invalid action');
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Check for pending calls
    $other_user_id = (int)($_GET['other_user_id'] ?? 0);

    if ($other_user_id <= 0) {
        json_response('error', 'Other user ID required');
    }

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM call_sessions 
            WHERE recipient_id = ? AND status IN ('ringing', 'active')
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$user_id]);
        $callSession = $stmt->fetch();

        if (!$callSession) {
            json_response('success', 'No active call', [
                'has_pending_call' => false
            ]);
        }

        json_response('success', 'Pending call found', [
            'has_pending_call' => true,
            'call_id' => $callSession['call_id'],
            'initiator_id' => $callSession['initiator_id'],
            'status' => $callSession['status']
        ]);
    } catch (Exception $e) {
        json_response('error', 'Failed to check calls');
    }

} else {
    json_response('error', 'Invalid request method');
}
?>
