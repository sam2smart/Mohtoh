<?php
// includes/chat.php
// Business logic for the MohToh Intelligent Chat System: conversation
// creation/lookup, membership, system messages, archiving, and the
// shared message-send path (rate limiting, archive/booking gating,
// abuse-heuristic flagging, notification fan-out). Mirrors
// includes/verification.php's role as the logic layer behind an API
// file (api/messages.php) that stays thin and only routes actions here.

const CHAT_RATE_LIMIT_MAX_MESSAGES = 20;
const CHAT_RATE_LIMIT_WINDOW_SECONDS = 60;

// Lookup-only — returns null if no ride conversation exists yet (e.g. a
// ride with zero bookings). Used by the trip-engine system-message hook
// so a never-booked ride's PUBLISHED/CANCELLED transitions don't create
// a ghost conversation with no one to read it; get_or_create_ride_conversation
// below is for the one place that SHOULD create one — a real booking.
function find_ride_conversation($pdo, $rideShareId) {
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE type = 'ride' AND ride_share_id = ? AND status != 'closed' LIMIT 1");
    $stmt->execute([$rideShareId]);
    $conversationId = $stmt->fetchColumn();
    return $conversationId ? (int)$conversationId : null;
}

// Ride group chat — one conversation per ride, created on first
// confirmed booking (not at ride creation, since no passengers exist
// yet) and reused for every subsequent booking on that ride.
function get_or_create_ride_conversation($pdo, $rideShareId) {
    $stmt = $pdo->prepare("SELECT id FROM conversations WHERE type = 'ride' AND ride_share_id = ? AND status != 'closed' LIMIT 1");
    $stmt->execute([$rideShareId]);
    $conversationId = $stmt->fetchColumn();

    if (!$conversationId) {
        $stmt = $pdo->prepare("SELECT driver_id FROM ride_shares WHERE id = ?");
        $stmt->execute([$rideShareId]);
        $ride = $stmt->fetch();
        if (!$ride) {
            return null;
        }
        $publicId = generate_unique_public_id($pdo, 'conversations', 'public_id', 'CONV');
        $stmt = $pdo->prepare("INSERT INTO conversations (public_id, type, ride_share_id, created_by, status) VALUES (?, 'ride', ?, ?, 'active')");
        $stmt->execute([$publicId, $rideShareId, $ride['driver_id']]);
        $conversationId = $pdo->lastInsertId();

        add_conversation_member($pdo, $conversationId, $ride['driver_id'], 'driver');
    }

    return (int)$conversationId;
}

function add_conversation_member($pdo, $conversationId, $userId, $role = 'member') {
    if (!$userId) {
        return;
    }
    $stmt = $pdo->prepare("
        INSERT INTO conversation_members (conversation_id, user_id, role, joined_at, is_active)
        VALUES (?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE is_active = 1, role = VALUES(role)
    ");
    $stmt->execute([$conversationId, $userId, $role]);
}

// Called right after a booking insert succeeds — ride_share_bookings
// rows default to status='confirmed' (bookings are auto-confirmed, no
// manual accept step exists; see api/rideshare.php's book action), so
// this can run unconditionally at that point.
function add_rider_to_ride_conversation($pdo, $rideShareId, $riderId) {
    $conversationId = get_or_create_ride_conversation($pdo, $rideShareId);
    if ($conversationId) {
        add_conversation_member($pdo, $conversationId, $riderId, 'passenger');
    }
    return $conversationId;
}

// Private driver-passenger chat — lazily created on first message
// attempt, gated on a confirmed booking existing between the two users
// on this specific ride (Phase 7's "no chat before booking" rule).
// Works regardless of which of $userA/$userB is the driver.
function get_or_create_private_conversation($pdo, $rideShareId, $userA, $userB) {
    $stmt = $pdo->prepare("
        SELECT rs.driver_id, b.rider_id
        FROM ride_shares rs
        JOIN ride_share_bookings b ON b.ride_share_id = rs.id AND b.status = 'confirmed'
        WHERE rs.id = ? AND (
            (rs.driver_id = ? AND b.rider_id = ?) OR (rs.driver_id = ? AND b.rider_id = ?)
        )
        LIMIT 1
    ");
    $stmt->execute([$rideShareId, $userA, $userB, $userB, $userA]);
    $link = $stmt->fetch();
    if (!$link) {
        return ['error' => 'A private chat is only available after a confirmed booking on this ride.'];
    }

    $driverId = (int)$link['driver_id'];
    $riderId = (int)$link['rider_id'];

    $stmt = $pdo->prepare("
        SELECT c.id FROM conversations c
        JOIN conversation_members m1 ON m1.conversation_id = c.id AND m1.user_id = ?
        JOIN conversation_members m2 ON m2.conversation_id = c.id AND m2.user_id = ?
        WHERE c.type = 'private' AND c.ride_share_id = ?
        LIMIT 1
    ");
    $stmt->execute([$driverId, $riderId, $rideShareId]);
    $conversationId = $stmt->fetchColumn();

    if (!$conversationId) {
        $publicId = generate_unique_public_id($pdo, 'conversations', 'public_id', 'CONV');
        $stmt = $pdo->prepare("INSERT INTO conversations (public_id, type, ride_share_id, created_by, status) VALUES (?, 'private', ?, ?, 'active')");
        $stmt->execute([$publicId, $rideShareId, $userA]);
        $conversationId = $pdo->lastInsertId();
        add_conversation_member($pdo, $conversationId, $driverId, 'driver');
        add_conversation_member($pdo, $conversationId, $riderId, 'passenger');
    }

    return ['conversation_id' => (int)$conversationId];
}

// MohToh Support chat — no booking required, only the requester is a
// member; admin/support staff view it via a membership-check bypass in
// the admin API, the same way a ticket queue works.
function create_support_conversation($pdo, $userId) {
    $publicId = generate_unique_public_id($pdo, 'conversations', 'public_id', 'CONV');
    $stmt = $pdo->prepare("INSERT INTO conversations (public_id, type, created_by, status) VALUES (?, 'support', ?, 'active')");
    $stmt->execute([$publicId, $userId]);
    $conversationId = $pdo->lastInsertId();
    add_conversation_member($pdo, $conversationId, $userId, 'member');
    return (int)$conversationId;
}

// Admin broadcast — one conversation per broadcast send, visible to
// everyone matching $audience without a conversation_members row per
// recipient (mirrors notification_broadcasts.target_type: 'all_drivers'
// | 'all_riders' | 'all' | 'single' | 'multiple').
function create_broadcast_conversation($pdo, $adminId, $audience, $text) {
    $publicId = generate_unique_public_id($pdo, 'conversations', 'public_id', 'CONV');
    $stmt = $pdo->prepare("INSERT INTO conversations (public_id, type, audience, created_by, status) VALUES (?, 'broadcast', ?, ?, 'active')");
    $stmt->execute([$publicId, $audience, $adminId]);
    $conversationId = $pdo->lastInsertId();

    $msgPublicId = generate_unique_public_id($pdo, 'messages', 'public_id', 'MSG');
    $stmt = $pdo->prepare("
        INSERT INTO messages (public_id, conversation_id, sender_id, message, message_type, created_at)
        VALUES (?, ?, ?, ?, 'text', NOW())
    ");
    $stmt->execute([$msgPublicId, $conversationId, $adminId, $text]);

    return (int)$conversationId;
}

function post_system_message($pdo, $conversationId, $text) {
    if (!$conversationId) {
        return null;
    }
    $publicId = generate_unique_public_id($pdo, 'messages', 'public_id', 'MSG');
    $stmt = $pdo->prepare("
        INSERT INTO messages (public_id, conversation_id, sender_id, receiver_id, message, message_type, created_at)
        VALUES (?, ?, NULL, NULL, ?, 'system', NOW())
    ");
    $stmt->execute([$publicId, $conversationId, $text]);
    return $pdo->lastInsertId();
}

// Called from the trip-engine dispatcher on TRIP_COMPLETED/CANCELLED —
// archived conversations stay readable but reject new sends (see
// send_message() below), satisfying Phase 2's "After trip: Archive
// automatically" without deleting any history.
function archive_ride_conversation($pdo, $rideShareId) {
    $pdo->prepare("
        UPDATE conversations SET status = 'archived', archived_at = NOW()
        WHERE type IN ('ride', 'private') AND ride_share_id = ? AND status = 'active'
    ")->execute([$rideShareId]);
}

// Deactivates (not deletes) a member — used when a rider cancels their
// booking, so their message history stays intact for everyone else and
// for admin moderation, but they stop counting toward unread/notify
// fan-out and (per send_message()'s membership check) can no longer post.
function remove_conversation_member($pdo, $conversationId, $userId) {
    $pdo->prepare("UPDATE conversation_members SET is_active = 0 WHERE conversation_id = ? AND user_id = ?")
        ->execute([$conversationId, $userId]);
}

function is_conversation_member($pdo, $conversationId, $userId) {
    $stmt = $pdo->prepare("SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND is_active = 1");
    $stmt->execute([$conversationId, $userId]);
    return (bool)$stmt->fetchColumn();
}

// A caller "can see" a broadcast conversation if their user_type
// matches its audience — no membership row needed. Used by
// api/messages.php wherever a plain is_conversation_member() check
// would otherwise reject every broadcast thread.
function can_view_broadcast($audience, $userType) {
    return $audience === 'all' || $audience === 'all_' . $userType . 's' || $audience === $userType;
}

// Very small heuristic — flags a message for admin review only, never
// blocks send. Not enforcement-grade (a determined user routes around
// any regex); this is a review-queue signal, not a filter, and is
// reported as such rather than oversold as real abuse prevention.
function chat_message_looks_suspicious($text) {
    if (preg_match('/\b(?:\+?\d[\d\s-]{8,}\d)\b/', $text)) {
        return true; // phone-number-shaped run of digits
    }
    if (preg_match('/\b\d{10,}\b/', $text)) {
        return true; // long digit run (account/card number shape)
    }
    if (preg_match('/\b(whatsapp|telegram|bank\s*transfer|acc(?:ount)?\s*(?:no|number)|zelle|cashapp|venmo)\b/i', $text)) {
        return true; // off-platform payment/contact steering keywords
    }
    return false;
}

// The one insert path every send goes through — api/messages.php's
// send action calls this directly, nothing else in the codebase writes
// to `messages` with a conversation_id.
function send_message($pdo, $senderId, $conversationId, $text, $messageType = 'text', $latitude = null, $longitude = null, $attachment = null) {
    $stmt = $pdo->prepare("SELECT type, status FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);
    $conversation = $stmt->fetch();
    if (!$conversation) {
        return ['error' => 'Conversation not found.'];
    }
    if ($conversation['type'] === 'broadcast') {
        return ['error' => 'Broadcast announcements are read-only.'];
    }
    if (!is_conversation_member($pdo, $conversationId, $senderId)) {
        return ['error' => 'You are not a participant in this conversation.'];
    }
    if ($conversation['status'] !== 'active') {
        return ['error' => 'This conversation has been archived and no longer accepts new messages.'];
    }
    if ($messageType === 'text' && trim((string)$text) === '') {
        return ['error' => 'Message cannot be empty.'];
    }

    // Rate limit via a recent-count query rather than new columns —
    // lightweight in the same spirit as the OTP resend cooldown in
    // includes/verification.php, sized for real conversation use vs spam.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM messages
        WHERE conversation_id = ? AND sender_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)
    ");
    $stmt->execute([$conversationId, $senderId, CHAT_RATE_LIMIT_WINDOW_SECONDS]);
    if ((int)$stmt->fetchColumn() >= CHAT_RATE_LIMIT_MAX_MESSAGES) {
        return ['error' => 'You are sending messages too quickly. Please slow down.'];
    }

    $flagged = ($messageType === 'text' && chat_message_looks_suspicious($text)) ? 1 : 0;

    $publicId = generate_unique_public_id($pdo, 'messages', 'public_id', 'MSG');
    $stmt = $pdo->prepare("
        INSERT INTO messages (public_id, conversation_id, sender_id, message, message_type, attachment, latitude, longitude, flagged_for_review, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$publicId, $conversationId, $senderId, $text, $messageType, $attachment, $latitude, $longitude, $flagged]);
    $messageId = $pdo->lastInsertId();

    // Sender's own last-read advances to the message they just sent —
    // otherwise they'd see their own message counted as unread.
    $pdo->prepare("UPDATE conversation_members SET last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?")
        ->execute([$messageId, $conversationId, $senderId]);

    notify_conversation_members($pdo, $conversationId, $senderId, $messageType === 'location' ? 'Shared a location' : $text);

    return ['message_id' => (int)$messageId, 'public_id' => $publicId, 'flagged_for_review' => (bool)$flagged];
}

// Fans out via the existing, working create_notification() — this is
// the only new thing chat adds to the Notification Engine (plus the
// 'chat' catalog entry in functions.php's notification_type_catalog()).
function notify_conversation_members($pdo, $conversationId, $senderId, $previewSource) {
    $stmt = $pdo->prepare("SELECT fullname FROM users WHERE id = ?");
    $stmt->execute([$senderId]);
    $senderName = $stmt->fetchColumn() ?: 'MohToh';

    $stmt = $pdo->prepare("
        SELECT cm.user_id, c.public_id AS conversation_public_id
        FROM conversation_members cm
        JOIN conversations c ON c.id = cm.conversation_id
        WHERE cm.conversation_id = ? AND cm.user_id != ? AND cm.is_active = 1
    ");
    $stmt->execute([$conversationId, $senderId]);
    $members = $stmt->fetchAll();

    $preview = mb_strlen($previewSource) > 80 ? mb_substr($previewSource, 0, 80) . '…' : $previewSource;
    foreach ($members as $member) {
        create_notification($pdo, $member['user_id'], [
            'title' => $senderName,
            'message' => $preview,
            'notification_type' => 'chat',
            'action' => 'open_chat',
            'action_id' => $conversationId,
            'related_table' => 'conversations',
            'related_public_id' => $member['conversation_public_id'],
            // No 'link' — chat is a sheet inside dashboard.php, not a
            // standalone page, so there's nowhere to navigate to; the
            // existing notification click handler simply marks it read.
        ]);
    }
}

function mark_conversation_read($pdo, $conversationId, $userId, $upToMessageId) {
    $pdo->prepare("
        UPDATE conversation_members SET last_read_message_id = ?
        WHERE conversation_id = ? AND user_id = ? AND (last_read_message_id IS NULL OR last_read_message_id < ?)
    ")->execute([$upToMessageId, $conversationId, $userId, $upToMessageId]);
}

// Powers the Messages nav badge — folded into api/notifications.php's
// existing get_unread response (chat_unread_count) rather than a
// second polling endpoint/timer.
function get_chat_unread_count($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM conversation_members cm
        JOIN messages m ON m.conversation_id = cm.conversation_id AND m.id > IFNULL(cm.last_read_message_id, 0)
        WHERE cm.user_id = ? AND cm.is_active = 1 AND (m.sender_id IS NULL OR m.sender_id != cm.user_id)
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}
