<?php
/**
 * SEO kiegészítések – a Yoast SEO mellé, ütközés nélkül.
 *  - GYIK (FAQPage) strukturált adat az oldalak iu/accordion blokkjaiból;
 *  - a szűrt / rendezett kínálat-URL-ek (?hang=G, ?orderby=…) noindex, follow – a kategória
 *    maga indexelhető, a végtelen szűrőkombináció nem;
 *  - a morzsa-navigáció BreadcrumbList adatát az iu/breadcrumb blokk adja: a Yoast saját
 *    Breadcrumb darabját kivesszük, hogy ne legyen kettő;
 *  - termék strukturált adat: származási ország (Google Merchant: countryOfOrigin).
 */

defined('ABSPATH') || exit;

/* ---------- GYIK ---------- */

$GLOBALS['mandala_faq'] = [];
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') === 'iu/accordion-item' && is_page()) {
        $q = wp_strip_all_tags((string) ($block['attrs']['title'] ?? ''));
        $a = trim(wp_strip_all_tags(preg_replace('/<div class="iu-accordion-item-head">.*?<\/div>/s', '', $html)));
        if ($q && $a) {
            $GLOBALS['mandala_faq'][] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
        }
    }
    return $html;
}, 10, 2);
add_action('wp_footer', function () {
    $faq = $GLOBALS['mandala_faq'] ?? [];
    // A Yoast saját GYIK blokkja már FAQPage-et ad – akkor nem duplikálunk.
    if (count($faq) < 2 || has_block('yoast/faq-block')) {
        return;
    }
    echo '<script type="application/ld+json">' . wp_json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_values($faq)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
}, 5);

/* ---------- Szűrt kínálat: noindex ---------- */

function mandala_is_filtered_listing(): bool
{
    if (is_admin() || !(function_exists('is_shop') && (is_shop() || is_product_taxonomy()))) {
        return false;
    }
    $ignore = ['paged', 'page', 'lang', 'gclid', 'fbclid', 'msclkid', 'srsltid'];
    foreach (array_keys($_GET) as $key) { // phpcs:ignore WordPress.Security.NonceVerification
        if (!in_array($key, $ignore, true) && !str_starts_with((string) $key, 'utm_')) {
            return true;
        }
    }
    return false;
}
add_filter('wp_robots', function ($robots) {
    if (mandala_is_filtered_listing()) {
        unset($robots['index'], $robots['max-image-preview']);
        $robots['noindex'] = true;
        $robots['follow'] = true;
    }
    return $robots;
});
add_filter('wpseo_robots', fn($robots) => mandala_is_filtered_listing() ? 'noindex, follow' : $robots);

/* ---------- Yoast: egy BreadcrumbList legyen ---------- */

add_filter('wpseo_schema_graph_pieces', function ($pieces) {
    return array_values(array_filter($pieces, fn($piece) => !is_a($piece, 'Yoast\WP\SEO\Generators\Schema\Breadcrumb')));
}, 20);
add_filter('wpseo_schema_webpage', function ($data) {
    unset($data['breadcrumb']);
    return $data;
});

/* ---------- Termék: származási ország ---------- */

add_filter('woocommerce_structured_data_product', function ($markup, $product) {
    $origin = $product instanceof WC_Product ? (mandala_attr($product, 'pa_eredet', 'slug')[0] ?? '') : '';
    $codes = ['nepal' => 'NP', 'india' => 'IN', 'tibet' => 'CN', 'indonezia' => 'ID', 'thaifold' => 'TH'];
    if (isset($codes[$origin])) {
        $markup['countryOfOrigin'] = ['@type' => 'Country', 'name' => $codes[$origin]];
    }
    return $markup;
}, 20, 2);
