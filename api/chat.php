<?php
// api/chat.php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Send a message
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    require_csrf($input);

    $receiver_id = (int)($input['receiver_id'] ?? 0);
    $message = sanitize_input($input['message'] ?? '');

    if ($receiver_id <= 0 || empty($message)) {
        json_response('error', 'Recipient and message content are required');
    }

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
    if ($stmt->execute([$user_id, $receiver_id, $message])) {
        json_response('success', 'Message sent successfully', [
            'id' => $pdo->lastInsertId(),
            'sender_id' => $user_id,
            'receiver_id' => $receiver_id,
            'message' => $message,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    } else {
        json_response('error', 'Failed to send message');
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Fetch conversation messages
    $other_user_id = (int)($_GET['other_user_id'] ?? 0);

    if ($other_user_id <= 0) {
        json_response('error', 'Invalid chat recipient');
    }

    // Fetch conversation order by time
    $stmt = $pdo->prepare("
        SELECT id, sender_id, receiver_id, message, created_at 
        FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) 
           OR (sender_id = ? AND receiver_id = ?) 
        ORDER BY created_at ASC
    ");
    $stmt->execute([$user_id, $other_user_id, $other_user_id, $user_id]);
    $messages = $stmt->fetchAll();

    json_response('success', 'Messages loaded', $messages);
} else {
    json_response('error', 'Invalid request method');
}
?>
