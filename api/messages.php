<?php
// api/messages.php
// User-facing endpoint for the MohToh Intelligent Chat System. Thin by
// design — every conversation-creation, membership, archive, rate-limit,
// abuse-heuristic, and notification-fan-out rule lives in
// includes/chat.php; this file only authenticates, validates input
// shape, and routes to it. Mirrors api/notifications.php's action-
// routing convention exactly (GET action=, POST action= + JSON body).
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/chat.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? '';

// Shared row shape for a conversation-list entry — used by
// list_conversations for every type (ride/private/support/broadcast).
function mt_conversation_summary($pdo, $conversationRow, $userId) {
    $conversationId = (int)$conversationRow['id'];

    $stmt = $pdo->prepare("
        SELECT m.message, m.message_type, m.created_at, m.sender_id, u.fullname AS sender_name
        FROM messages m
        LEFT JOIN users u ON u.id = m.sender_id
        WHERE m.conversation_id = ?
        ORDER BY m.id DESC LIMIT 1
    ");
    $stmt->execute([$conversationId]);
    $lastMessage = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT last_read_message_id FROM conversation_members WHERE conversation_id = ? AND user_id = ?");
    $stmt->execute([$conversationId, $userId]);
    $lastRead = $stmt->fetchColumn();
    $lastRead = $lastRead === false ? null : $lastRead;

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages
        WHERE conversation_id = ? AND id > IFNULL(?, 0) AND (sender_id IS NULL OR sender_id != ?)
    ");
    $stmt->execute([$conversationId, $lastRead, $userId]);
    $unreadCount = (int)$stmt->fetchColumn();

    $title = null;
    $subtitle = null;
    if ($conversationRow['type'] === 'ride') {
        $stmt = $pdo->prepare("SELECT pickup_location, destination FROM ride_shares WHERE id = ?");
        $stmt->execute([$conversationRow['ride_share_id']]);
        $ride = $stmt->fetch();
        $title = $ride ? ($ride['pickup_location'] . ' → ' . $ride['destination']) : 'Ride chat';
        $subtitle = 'Ride group chat';
    } elseif ($conversationRow['type'] === 'private') {
        $stmt = $pdo->prepare("
            SELECT u.fullname FROM conversation_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id = ? AND cm.user_id != ? LIMIT 1
        ");
        $stmt->execute([$conversationId, $userId]);
        $title = $stmt->fetchColumn() ?: 'Private chat';
        $subtitle = 'Private chat';
    } elseif ($conversationRow['type'] === 'support') {
        $title = 'MohToh Support';
        $subtitle = 'Support chat';
    } elseif ($conversationRow['type'] === 'broadcast') {
        $title = 'MohToh Announcements';
        $subtitle = 'Broadcast';
    }

    return [
        'conversation_id' => $conversationId,
        'public_id' => $conversationRow['public_id'],
        'type' => $conversationRow['type'],
        'status' => $conversationRow['status'],
        'ride_share_id' => $conversationRow['ride_share_id'],
        'title' => $title,
        'subtitle' => $subtitle,
        'last_message' => $lastMessage ? [
            'text' => $lastMessage['message_type'] === 'location' ? 'Shared a location' : $lastMessage['message'],
            'sender_name' => $lastMessage['sender_id'] === null ? 'MohToh' : ($lastMessage['sender_name'] ?? 'User'),
            'is_system' => $lastMessage['sender_id'] === null,
            'created_at' => $lastMessage['created_at'],
        ] : null,
        'unread_count' => $unreadCount,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list_conversations';

    if ($action === 'list_conversations') {
        // Ride/private/support: via real membership rows.
        $stmt = $pdo->prepare("
            SELECT c.id, c.public_id, c.type, c.status, c.ride_share_id
            FROM conversations c
            JOIN conversation_members cm ON cm.conversation_id = c.id
            WHERE cm.user_id = ? AND cm.is_active = 1 AND c.type IN ('ride', 'private', 'support')
        ");
        $stmt->execute([$user_id]);
        $rows = $stmt->fetchAll();

        // Broadcast: audience match, no membership row required.
        $stmt = $pdo->prepare("SELECT id, public_id, type, status, ride_share_id, audience FROM conversations WHERE type = 'broadcast' ORDER BY created_at DESC LIMIT 20");
        $stmt->execute();
        foreach ($stmt->fetchAll() as $broadcast) {
            if (can_view_broadcast($broadcast['audience'], $user_type)) {
                $rows[] = $broadcast;
            }
        }

        $summaries = array_map(fn($row) => mt_conversation_summary($pdo, $row, $user_id), $rows);
        usort($summaries, function ($a, $b) {
            $aTime = $a['last_message']['created_at'] ?? '1970-01-01';
            $bTime = $b['last_message']['created_at'] ?? '1970-01-01';
            return strcmp($bTime, $aTime);
        });

        json_response('success', 'Conversations fetched', ['conversations' => $summaries]);
    }

    if ($action === 'get_messages') {
        $conversationId = (int)($_GET['conversation_id'] ?? 0);
        $afterId = (int)($_GET['after_id'] ?? 0);
        if ($conversationId <= 0) {
            json_response('error', 'conversation_id is required');
        }

        $stmt = $pdo->prepare("SELECT type, audience, status FROM conversations WHERE id = ?");
        $stmt->execute([$conversationId]);
        $conversation = $stmt->fetch();
        if (!$conversation) {
            json_response('error', 'Conversation not found');
        }

        $isAdmin = $user_type === 'admin';
        $canView = $isAdmin
            || is_conversation_member($pdo, $conversationId, $user_id)
            || ($conversation['type'] === 'broadcast' && can_view_broadcast($conversation['audience'], $user_type));
        if (!$canView) {
            json_response('error', 'You do not have access to this conversation');
        }

        $stmt = $pdo->prepare("
            SELECT m.public_id, m.sender_id, u.fullname AS sender_name, m.message, m.message_type,
                   m.attachment, m.latitude, m.longitude, m.flagged_for_review, m.created_at, m.id
            FROM messages m
            LEFT JOIN users u ON u.id = m.sender_id
            WHERE m.conversation_id = ? AND m.id > ?
            ORDER BY m.id ASC
            LIMIT 200
        ");
        $stmt->execute([$conversationId, $afterId]);
        $messages = $stmt->fetchAll();

        json_response('success', 'Messages fetched', [
            'conversation_status' => $conversation['status'],
            'messages' => array_map(function ($m) use ($user_id) {
                return [
                    'id' => (int)$m['id'],
                    'public_id' => $m['public_id'],
                    'sender_id' => $m['sender_id'] !== null ? (int)$m['sender_id'] : null,
                    'sender_name' => $m['sender_id'] === null ? 'MohToh' : ($m['sender_name'] ?? 'User'),
                    'is_own' => $m['sender_id'] !== null && (int)$m['sender_id'] === (int)$user_id,
                    'is_system' => $m['sender_id'] === null,
                    'message' => $m['message'],
                    'message_type' => $m['message_type'],
                    'attachment' => $m['attachment'],
                    'latitude' => $m['latitude'] !== null ? (float)$m['latitude'] : null,
                    'longitude' => $m['longitude'] !== null ? (float)$m['longitude'] : null,
                    'created_at' => $m['created_at'],
                ];
            }, $messages),
        ]);
    }

    if ($action === 'unread_count') {
        json_response('success', 'Unread count fetched', ['unread_count' => get_chat_unread_count($pdo, $user_id)]);
    }

    json_response('error', 'Invalid action');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $action = $input['action'] ?? '';

    if ($action === 'send') {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $messageType = $input['message_type'] ?? 'text';
        if (!in_array($messageType, ['text', 'image', 'location', 'system', 'trip_event'], true)) {
            $messageType = 'text';
        }
        // Sanitize at the API boundary: trim, collapse a runaway length.
        // XSS protection lives at render time in assets/js/messages.js
        // (textContent, never innerHTML) — this is abuse/storage hygiene,
        // not the only line of defense.
        $text = trim((string)($input['message'] ?? ''));
        if (mb_strlen($text) > 2000) {
            $text = mb_substr($text, 0, 2000);
        }
        $latitude = isset($input['latitude']) ? (float)$input['latitude'] : null;
        $longitude = isset($input['longitude']) ? (float)$input['longitude'] : null;
        $attachment = isset($input['attachment']) ? substr((string)$input['attachment'], 0, 255) : null;

        if ($conversationId <= 0) {
            json_response('error', 'conversation_id is required');
        }
        if ($messageType === 'location' && ($latitude === null || $longitude === null)) {
            json_response('error', 'latitude and longitude are required for a location message');
        }

        $result = send_message($pdo, $user_id, $conversationId, $text, $messageType, $latitude, $longitude, $attachment);
        if (isset($result['error'])) {
            json_response('error', $result['error']);
        }
        json_response('success', 'Message sent', $result);
    }

    if ($action === 'start_private') {
        $rideShareId = (int)($input['ride_share_id'] ?? 0);
        $otherUserId = (int)($input['other_user_id'] ?? 0);
        if ($rideShareId <= 0 || $otherUserId <= 0) {
            json_response('error', 'ride_share_id and other_user_id are required');
        }
        $result = get_or_create_private_conversation($pdo, $rideShareId, $user_id, $otherUserId);
        if (isset($result['error'])) {
            json_response('error', $result['error']);
        }
        json_response('success', 'Private conversation ready', $result);
    }

    if ($action === 'start_support') {
        $conversationId = create_support_conversation($pdo, $user_id);
        json_response('success', 'Support conversation created', ['conversation_id' => $conversationId]);
    }

    if ($action === 'mark_read') {
        $conversationId = (int)($input['conversation_id'] ?? 0);
        $upToMessageId = (int)($input['up_to_message_id'] ?? 0);
        if ($conversationId <= 0 || $upToMessageId <= 0) {
            json_response('error', 'conversation_id and up_to_message_id are required');
        }
        mark_conversation_read($pdo, $conversationId, $user_id, $upToMessageId);
        json_response('success', 'Marked as read');
    }

    if ($action === 'report') {
        $messagePublicId = trim((string)($input['message_public_id'] ?? ''));
        $reason = trim((string)($input['reason'] ?? ''));
        if ($messagePublicId === '') {
            json_response('error', 'message_public_id is required');
        }
        $stmt = $pdo->prepare("SELECT id, conversation_id FROM messages WHERE public_id = ?");
        $stmt->execute([$messagePublicId]);
        $message = $stmt->fetch();
        if (!$message) {
            json_response('error', 'Message not found');
        }
        if (!is_conversation_member($pdo, $message['conversation_id'], $user_id)) {
            json_response('error', 'You do not have access to this conversation');
        }
        $publicId = generate_unique_public_id($pdo, 'message_reports', 'public_id', 'MRPT');
        $stmt = $pdo->prepare("
            INSERT INTO message_reports (public_id, message_id, conversation_id, reported_by, reason, status, created_at)
            VALUES (?, ?, ?, ?, ?, 'open', NOW())
        ");
        $stmt->execute([$publicId, $message['id'], $message['conversation_id'], $user_id, $reason !== '' ? $reason : null]);
        json_response('success', 'Message reported. Our team will review it.');
    }

    json_response('error', 'Invalid action');
}

json_response('error', 'Invalid request method');
