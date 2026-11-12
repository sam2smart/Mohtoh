<?php
// api/social-accounts.php
// Connected-accounts management for the logged-in user: list linked social
// providers, start a link (returned as a URL the browser navigates to — OAuth
// needs a full-page redirect), and unlink a provider.
//
// Security:
//   * Requires an authenticated session (same is_logged_in() gate as the other
//     api/*.php endpoints — returns {status:'error', message:'Unauthorized'}).
//   * State-changing actions (unlink) require the app CSRF token AND are rate
//     limited per user. OAuth `state` protects the redirect flow; it is NOT a
//     substitute for CSRF on this authenticated JSON endpoint.
//   * Unlink is refused when it would remove the user's ONLY way back in
//     (no password set and no other provider) — enforced server-side.
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    json_response('error', 'Unauthorized');
}

$userId = (int) $_SESSION['user_id'];
$userType = $_SESSION['user_type'] ?? 'rider';

// Accept a JSON body (api-client.js apiPost) or a form post; fall back to GET
// for the read-only list.
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
$action = $input['action'] ?? ($_GET['action'] ?? 'list');

$base = rtrim(mt_get_base_url(), '/');

// Builds the current connected-accounts view for the user.
$buildList = function () use ($pdo, $userId, $base): array {
    $linked = [];
    foreach (oauth_list_user_identities($pdo, $userId) as $row) {
        $linked[$row['provider']] = $row;
    }
    $stmt = $pdo->prepare("SELECT password_is_set FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $hasPassword = ((int) $stmt->fetchColumn() === 1);

    $providers = [];
    foreach (OAUTH_PROVIDERS as $p) {
        $connected = isset($linked[$p]);
        $canUnlink = false;
        if ($connected) {
            // Mirror oauth_user_can_unlink()'s rule for the UI: allowed if a
            // password exists OR another provider remains.
            $others = 0;
            foreach ($linked as $lp => $_) {
                if ($lp !== $p) {
                    $others++;
                }
            }
            $canUnlink = ($hasPassword || $others >= 1);
        }
        $providers[] = [
            'provider' => $p,
            'label' => oauth_provider_label($p),
            'available' => oauth_provider_usable($pdo, $p),
            'connected' => $connected,
            'email' => $connected ? ($linked[$p]['provider_email'] ?? null) : null,
            'connected_at' => $connected ? ($linked[$p]['created_at'] ?? null) : null,
            'can_unlink' => $canUnlink,
            // Full-page navigation target to begin linking (mode=link).
            'link_url' => $base . '/auth/oauth/' . $p . '/start.php?mode=link',
        ];
    }
    return ['providers' => $providers, 'password_is_set' => $hasPassword];
};

if ($action === 'list') {
    json_response('success', 'OK', $buildList());
} elseif ($action === 'unlink') {
    require_csrf($input); // exits with a friendly error if the token is bad

    if (!oauth_rate_limit($pdo, 'oauth_unlink', 'u' . $userId, 10, 600)) {
        json_response('error', 'Too many attempts. Please wait a few minutes and try again.');
    }

    $provider = strtolower(trim((string) ($input['provider'] ?? '')));
    if (!oauth_is_valid_provider($provider)) {
        json_response('error', 'Unknown provider.');
    }

    // Must actually be linked to this user.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM oauth_identities WHERE user_id = ? AND provider = ?");
    $stmt->execute([$userId, $provider]);
    if ((int) $stmt->fetchColumn() === 0) {
        json_response('error', 'That account is not connected.');
    }

    $guard = oauth_user_can_unlink($pdo, $userId, $provider);
    if (empty($guard['ok'])) {
        json_response('error', $guard['error']);
    }

    if (oauth_unlink($pdo, $userId, $provider)) {
        oauth_log('unlink', 'success', oauth_provider_label($provider) . ' disconnected', ['user_id' => $userId, 'provider' => $provider]);
        json_response('success', oauth_provider_label($provider) . ' disconnected.', $buildList());
    }
    json_response('error', 'We could not disconnect that account. Please try again.');
} else {
    json_response('error', 'Invalid action');
}
