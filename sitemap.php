<?php
// sitemap.php
require_once 'includes/db.php';
require_once 'includes/environment.php';

header('Content-Type: application/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$baseUrl = rtrim(mt_get_base_url(), '/');

// Static Pages
$staticPages = ['/', '/about.php', '/join.php'];
foreach ($staticPages as $page) {
    echo "<url><loc>" . $baseUrl . $page . "</loc><changefreq>weekly</changefreq></url>";
}

// Active Rides
$stmt = $pdo->query("SELECT public_id FROM ride_shares WHERE status = 'active'");
while ($ride = $stmt->fetch()) {
    echo "<url><loc>" . $baseUrl . "/ride.php?id=" . $ride['public_id'] . "</loc><changefreq>hourly</changefreq></url>";
}

echo '</urlset>';
?>
