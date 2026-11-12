<?php
// join.php
// MohToh shareable link landing point — handles two distinct payloads:
//
// 1. Referral invites: ?ref=CODE  (original behaviour, unchanged)
//    Redirects to index.php with ?ref= preserved so auth.js can prefill
//    the referral_code field and show "Invited by X".
//
// 2. Join Ride links: ?ride=PUBLIC_ID  (new)
//    A driver shares this after creating a ride. Anyone who opens it is
//    sent to the rider dashboard with ?open_ride=PUBLIC_ID appended so
//    search.js can auto-open the ride-detail sheet for that specific ride.
//    Non-logged-in users are sent to the login page; the ride param is
//    forwarded via ?next= so auth.js can redirect back after login.
//
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$ref  = trim((string)($_GET['ref']  ?? ''));
$ride = trim((string)($_GET['ride'] ?? ''));

// Ride-link landing
if ($ride !== '') {
    // Basic sanity — public IDs are always alphanumeric+dash, 8-16 chars.
    if (!preg_match('/^[A-Z0-9\-]{4,32}$/i', $ride)) {
        // Invalid token — just fall through to home
        header('Location: index.php');
        exit;
    }

    // Store in session so auth.js / dashboard can pick it up even after
    // the login redirect clears the query string.
    $_SESSION['pending_ride_link'] = $ride;

    $isLoggedIn = isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
    if ($isLoggedIn && $_SESSION['user_type'] === 'rider') {
        header('Location: rider/dashboard.php?open_ride=' . urlencode($ride));
    } elseif ($isLoggedIn && $_SESSION['user_type'] === 'driver') {
        // Drivers opening a ride link — send to rider dashboard after
        // a clear note that this link is for passengers.
        header('Location: rider/dashboard.php?open_ride=' . urlencode($ride));
    } else {
        // Not logged in — send to login, preserve the ride ID so auth.js
        // can redirect to the right place after login.
        header('Location: index.php?open_ride=' . urlencode($ride) . ($ref !== '' ? '&ref=' . urlencode($ref) : ''));
    }
    exit;
}

// Referral-only landing (original behaviour, unchanged)
$target = 'index.php' . ($ref !== '' ? '?ref=' . urlencode($ref) : '');
header('Location: ' . $target);
exit;

