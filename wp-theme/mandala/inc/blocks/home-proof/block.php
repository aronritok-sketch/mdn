<?php
/**
 * mandala/home-proof – bizalmi sor a hős gombjai alatt: valós, élő számok (raktáron lévő termékek,
 * vásárlói értékelések átlaga). Az értékelés csak 5 vélemény felett jelenik meg: kevesebbnél
 * inkább árt, mint használ (Spiegel Research Center: az 5. vélemény után lassul a hatás).
 */

defined('ABSPATH') || exit;

/** Az összes jóváhagyott vásárlói értékelés átlaga és száma (12 órás gyorsítótár). */
function mandala_store_review_stats(): array
{
    $cached = get_transient('mandala_store_reviews');
    if (is_array($cached)) {
        return $cached;
    }
    global $wpdb;
    $row = $wpdb->get_row("SELECT COUNT(*) AS n, AVG(CAST(m.meta_value AS UNSIGNED)) AS avg FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_rating'
        WHERE p.post_type = 'mandala_review' AND p.post_status = 'publish'"); // phpcs:ignore
    $stats = ['count' => (int) ($row->n ?? 0), 'avg' => round((float) ($row->avg ?? 0), 1)];
    set_transient('mandala_store_reviews', $stats, 12 * HOUR_IN_SECONDS);
    return $stats;
}
add_action('transition_post_status', function ($new, $old, $post) {
    if ($post->post_type === 'mandala_review' && $new !== $old) {
        delete_transient('mandala_store_reviews');
    }
}, 10, 3);

mandala_add_block('mandala/home-proof', [
    'title' => 'Bizalmi sor (hős)',
    'template' => function ($attributes) {
        $instock = 0;
        foreach (function_exists('mandala_product_index') ? mandala_product_index() : [] as $r) {
            $instock += ($r['stock'] ?? 'in') !== 'out' ? 1 : 0;
        }
        $items = [];
        $reviews = mandala_store_review_stats();
        if ($reviews['count'] >= 5) {
            $items[] = '<span class="proof-rating">' . mandala_icon('star', 'ico ico-s') . '<strong>' . esc_html(number_format_i18n($reviews['avg'], 1)) . '</strong> '
                . esc_html(sprintf(_n('%s vásárlói értékelés', '%s vásárlói értékelés', $reviews['count'], 'mandala'), number_format_i18n($reviews['count']))) . '</span>';
        }
        if ($instock >= 20) {
            $items[] = '<span>' . mandala_icon('check', 'ico ico-s') . esc_html(sprintf(__('%s termék raktárról, 1–2 munkanap alatt feladva', 'mandala'), number_format_i18n($instock))) . '</span>';
        }
        $items[] = '<span>' . mandala_icon('check', 'ico ico-s') . esc_html__('14 napos visszaküldés, indoklás nélkül', 'mandala') . '</span>';
        return '<p class="' . mandala_classes($attributes, 'hero-proof') . '">' . implode('', $items) . '</p>';
    },
]);
