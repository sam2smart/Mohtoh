<?php
// api/v1/_bootstrap.php
// Required by every api/v1/**/*.php endpoint, first line. Provides:
//   - the standardized success/error JSON envelope (Phase 3)
//   - a global error/exception trap so a PHP warning or uncaught
//     exception can NEVER leak as raw HTML/text ahead of (or instead
//     of) JSON — every response from anywhere under api/v1/ is valid
//     JSON, always (Phase 3's explicit requirement)
//   - v1_require_auth() — bearer-token authentication (Phase 4/11);
//     the ONLY way any v1 endpoint learns who the caller is. No v1
//     endpoint ever trusts a user_id passed in the request body.
//
// This file does not touch $_SESSION and does not affect the existing
// web session login in any way — token auth (mobile) and session auth
// (web) are two completely independent, coexisting mechanisms that
// both ultimately resolve to the same `users` row and the same
// business-layer functions in includes/*.php.

require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/google-maps.php'; // real trip distance/ETA (Distance Matrix, haversine fallback)
require_once __DIR__ . '/../../includes/verification.php'; // is_account_locked(), record_failed_login/successful_login(), mt_scalar_string(), normalize_phone_number() — needed by more than just auth/login.php, so loaded here once for every v1 endpoint
require_once __DIR__ . '/../../includes/api_tokens.php';

header('Content-Type: application/json');

// ---- Phase 3: nothing may ever leak out except the JSON envelope ----
// Suppress PHP's own error display (a stray notice/warning would
// otherwise be echoed as HTML text ahead of the JSON body, corrupting
// it for any client doing response.json()).
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false; // respect @-suppressed calls, same as PHP's default handler would
    }
    error_log("[api/v1] PHP error: {$message} in {$file}:{$line}");
    v1_error('Internal server error.', 'internal_error', 500);
    return true;
});

set_exception_handler(function ($exception) {
    error_log('[api/v1] Uncaught exception: ' . $exception->getMessage() . ' in ' . $exception->getFile() . ':' . $exception->getLine());
    v1_error('Internal server error.', 'internal_error', 500);
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[api/v1] Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['status' => 'error', 'message' => 'Internal server error.', 'code' => 'internal_error']);
    }
});

/** Phase 3 success envelope: {status:"success", message, data}. Optional pagination is merged in the same shape includes/functions.php's paginate_meta() already produces. */
function v1_success(string $message, $data = [], ?array $pagination = null): void {
    $response = ['status' => 'success', 'message' => $message, 'data' => $data];
    if ($pagination !== null) {
        $response['pagination'] = $pagination;
    }
    echo json_encode($response);
    exit;
}

/** Phase 3 error envelope: {status:"error", message, code}. $code is a short machine-readable slug (e.g. "unauthorized", "validation_error"), never a raw exception message or DB error string. */
function v1_error(string $message, string $code = 'error', int $httpStatus = 400): void {
    if (!headers_sent()) {
        http_response_code($httpStatus);
    }
    echo json_encode(['status' => 'error', 'message' => $message, 'code' => $code]);
    exit;
}

/** Extracts "Authorization: Bearer <token>" from any of the header shapes PHP/Apache expose it under, depending on server config. */
function v1_bearer_token(): ?string {
    $header = null;
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = $value;
                break;
            }
        }
    }
    if ($header === null) {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    }
    if (!$header || stripos($header, 'Bearer ') !== 0) {
        return null;
    }
    return trim(substr($header, 7));
}

/** Phase 11: "Always get logged-in user from token" — the ONLY entry point any v1 endpoint uses to learn who's calling. Ends the request with a 401 JSON error if the token is missing/invalid/expired. */
function v1_require_auth($pdo): array {
    $token = v1_bearer_token();
    $user = validate_api_token($pdo, $token);
    if (!$user) {
        v1_error('Invalid or expired session. Please log in again.', 'unauthorized', 401);
    }
    return $user;
}

/** Ends the request with a 403 unless $user's user_type is in $allowedRoles. */
function v1_require_role(array $user, array $allowedRoles): void {
    if (!in_array($user['user_type'], $allowedRoles, true)) {
        v1_error('You are not authorized to perform this action.', 'forbidden', 403);
    }
}

/** Parses a JSON request body into an array; empty/invalid body becomes []  rather than null, so callers can index into it without an isset() dance. */
function v1_input(): array {
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}
