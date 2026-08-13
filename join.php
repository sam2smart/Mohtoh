<?php
// join.php
// MohToh Community Rewards share-link landing point. Deliberately thin
// — this project has no .htaccess/URL-rewriting, so the shareable link
// is a plain query string (APP_BASE_URL/join.php?ref=CODE), not a
// fabricated /join/{code} pretty path. Redirects straight into the
// existing index.php registration flow with ?ref= preserved;
// assets/js/auth.js reads it from there to prefill the hidden
// referral_code field and show "Invited by X".
$ref = trim((string)($_GET['ref'] ?? ''));
$target = 'index.php' . ($ref !== '' ? '?ref=' . urlencode($ref) : '');
header('Location: ' . $target);
exit;
