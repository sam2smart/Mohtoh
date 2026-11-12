<?php
// img.php — public on-the-fly image thumbnail endpoint.
//
// Serves a resized, format-negotiated version of an existing file in uploads/,
// disk-cached under uploads/cache/. Falls back to the original bytes on any
// problem, so it is a safe drop-in for existing ../uploads/<name> URLs.
//
//   ../img.php?f=<basename>&w=<width>
//
// Only a bare basename inside uploads/ is ever served (path-traversal safe);
// width is snapped to a small whitelist. See includes/image_optimizer.php.
require_once __DIR__ . '/includes/image_optimizer.php';

mt_img_serve(
    (string)($_GET['f'] ?? ''),
    (int)($_GET['w'] ?? 400),
    (string)($_SERVER['HTTP_ACCEPT'] ?? '')
);
