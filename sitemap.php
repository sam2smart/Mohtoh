<?php
// sitemap.php
require_once 'includes/db.php';
require_once 'includes/environment.php';

header('Content-Type: application/xml');
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

$baseUrl = rtrim(mt_get_base_url(), '/');

// Static public pages only — only include pages that physically exist and are indexable
$staticPages = ['/'];
foreach ($staticPages as $page) {
    echo "<url><loc>" . htmlspecialchars($baseUrl . $page, ENT_XML1) . "</loc><changefreq>weekly</changefreq><priority>1.0</priority></url>";
}
// Note: /about.php and /ride.php do not exist in this codebase and are excluded.
// Note: /join.php is a redirect to index.php and is excluded.

echo '</urlset>';
?>
