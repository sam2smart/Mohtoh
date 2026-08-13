<?php
// includes/api_tokens.php
// Mobile (Flutter) API token authentication — completely separate from,
// and coexisting with, the existing web session login ($_SESSION,
// api/auth.php). Same security shape as includes/verification.php's
// email/reset tokens: a random raw token is issued to the client once;
// only its SHA-256 hash is ever stored, so a stolen database dump can't
// be replayed as a bearer token.

const API_TOKEN_TTL_DAYS = 30;

function _api_token_hash(string $rawToken): string {
    return hash('sha256', $rawToken);
}

// Issues a fresh token for $userId and returns the RAW token (shown to
// the client exactly once — the same one-time-reveal contract every
// other secret in this app follows). Does not revoke the user's other
// devices' tokens; a user may be logged in on several devices at once.
function issue_api_token($pdo, $userId, ?string $deviceType = null, ?string $deviceName = null): string {
    $rawToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + API_TOKEN_TTL_DAYS * 86400);
    $stmt = $pdo->prepare("INSERT INTO user_tokens (user_id, token_hash, device_type, device_name, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, _api_token_hash($rawToken), $deviceType, $deviceName, $expiresAt]);
    return $rawToken;
}

// Validates a raw bearer token, touches last_used, and returns the full
// users row (same shape $_SESSION-based endpoints already assume) or
// null if missing/expired. Never throws — callers treat null exactly
// like "not logged in".
function validate_api_token($pdo, ?string $rawToken) {
    if (!$rawToken) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT ut.id AS token_id, ut.user_id, ut.expires_at, u.* FROM user_tokens ut JOIN users u ON u.id = ut.user_id WHERE ut.token_hash = ?");
    $stmt->execute([_api_token_hash($rawToken)]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    if ((int)$row['is_suspended']) {
        return null;
    }
    $pdo->prepare("UPDATE user_tokens SET last_used = ? WHERE id = ?")->execute([date('Y-m-d H:i:s'), $row['token_id']]);
    return $row;
}

// Logout — revokes exactly the token presented (not every device the
// user is logged in on).
function revoke_api_token($pdo, ?string $rawToken): bool {
    if (!$rawToken) {
        return false;
    }
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE token_hash = ?");
    $stmt->execute([_api_token_hash($rawToken)]);
    return $stmt->rowCount() > 0;
}

// Rotates a still-valid token: issues a new one and revokes the old one
// atomically, so a leaked-but-not-yet-expired token can't keep being
// refreshed after the legitimate client has moved on. Returns the new
// raw token, or null if $rawToken itself wasn't valid.
function refresh_api_token($pdo, ?string $rawToken): ?string {
    $user = validate_api_token($pdo, $rawToken);
    if (!$user) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT device_type, device_name FROM user_tokens WHERE token_hash = ?");
    $stmt->execute([_api_token_hash($rawToken)]);
    $device = $stmt->fetch();

    $newToken = issue_api_token($pdo, $user['id'], $device['device_type'] ?? null, $device['device_name'] ?? null);
    revoke_api_token($pdo, $rawToken);
    return $newToken;
}
