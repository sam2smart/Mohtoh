<?php
// includes/seo.php

require_once __DIR__ . '/environment.php';

/**
 * Generates SEO meta tags and structured data for public pages.
 */
function mt_render_seo($title, $description, $canonical = '', $isIndexable = true, $ogImage = '', $structuredData = []) {
    $baseUrl = rtrim(mt_get_base_url(), '/');
    $canonicalUrl = $canonical ? $canonical : ($baseUrl . $_SERVER['REQUEST_URI']);
    $robots = $isIndexable ? 'index, follow' : 'noindex, nofollow';
    $ogImage = $ogImage ? $ogImage : ($baseUrl . '/assets/images/og-default.jpg');

    echo "<title>" . htmlspecialchars($title) . " | MohToh</title>\n";
    echo "<meta name=\"description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<link rel=\"canonical\" href=\"" . htmlspecialchars($canonicalUrl) . "\">\n";
    echo "<meta name=\"robots\" content=\"" . $robots . "\">\n";

    // Social Meta
    echo "<meta property=\"og:title\" content=\"" . htmlspecialchars($title) . "\">\n";
    echo "<meta property=\"og:description\" content=\"" . htmlspecialchars($description) . "\">\n";
    echo "<meta property=\"og:url\" content=\"" . htmlspecialchars($canonicalUrl) . "\">\n";
    echo "<meta property=\"og:image\" content=\"" . htmlspecialchars($ogImage) . "\">\n";
    echo "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";

    // Structured Data
    if (!empty($structuredData)) {
        echo "<script type=\"application/ld+json\">\n" . json_encode($structuredData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
    }
}

function mt_get_structured_data_organization() {
    return [
        "@context" => "https://schema.org",
        "@type" => "Organization",
        "name" => "MohToh",
        "url" => mt_get_base_url(),
        "logo" => mt_get_base_url() . "/assets/images/logo.png"
    ];
}

function mt_render_breadcrumb($items) {
    $list = ["@context" => "https://schema.org", "@type" => "BreadcrumbList", "itemListElement" => []];
    foreach ($items as $index => $item) {
        $list["itemListElement"][] = [
            "@type" => "ListItem",
            "position" => $index + 1,
            "name" => $item['name'],
            "item" => $item['url']
        ];
    }
    echo "<script type=\"application/ld+json\">\n" . json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n</script>\n";
}
?>
