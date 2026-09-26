<?php
/**
 * mandala/post-meta – cikk meta (kategória, dátum, olvasási idő) és a [mandala_post_card]
 * shortcode az iu/query sablonjához (magazin lista, kapcsolódó cikkek).
 */

defined('ABSPATH') || exit;

function mandala_reading_minutes(WP_Post $post): int
{
    $minutes = (int) get_post_meta($post->ID, '_mandala_minutes', true);
    return $minutes ?: max(1, (int) round(str_word_count(wp_strip_all_tags($post->post_content)) / 200));
}

function mandala_post_meta_html(WP_Post $post, bool $link = true): string
{
    $cats = get_the_category($post->ID);
    $cat = $cats ? $cats[0] : null;
    $label = $cat ? ($link ? '<a href="' . esc_url(get_category_link($cat)) . '" style="color:inherit">' . esc_html($cat->name) . '</a>' : '<span>' . esc_html($cat->name) . '</span>') : '<span>' . esc_html__('Magazin', 'mandala') . '</span>';
    return '<p class="post-meta">' . $label . '<time datetime="' . esc_attr(get_the_date('Y-m-d', $post)) . '">' . esc_html(get_the_date('Y. F j.', $post)) . '</time><span>'
        . esc_html(sprintf(__('%d perc olvasás', 'mandala'), mandala_reading_minutes($post))) . '</span></p>';
}

function mandala_post_card(WP_Post $post): string
{
    $thumb = has_post_thumbnail($post)
        ? get_the_post_thumbnail($post, 'medium_large', ['sizes' => '(max-width: 991px) 100vw, 33vw', 'loading' => 'lazy', 'alt' => ''])
        : mandala_art_img((string) get_post_meta($post->ID, '_mandala_art', true) ?: 'bowl', (string) get_post_meta($post->ID, '_mandala_tone', true) ?: 'sand');
    return '<article class="iu-query-item reveal"><a class="post-link" href="' . esc_url(get_permalink($post)) . '"><div class="post-thumb">' . $thumb . '</div>'
        . str_replace(['<a ', '</a>'], ['<span ', '</span>'], mandala_post_meta_html($post, false))
        . '<h3>' . esc_html(get_the_title($post)) . '</h3><p>' . esc_html(get_the_excerpt($post)) . '</p>'
        . '<span class="go">' . esc_html__('Tovább olvasok', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</span></a></article>';
}

add_shortcode('mandala_post_card', function () {
    $post = get_post();
    return $post ? mandala_post_card($post) : '';
});

mandala_add_block('mandala/post-meta', [
    'title' => 'Cikk meta (kategória, dátum, olvasási idő)',
    'template' => function ($attributes) {
        $post = get_post(get_queried_object_id());
        if (!$post) {
            return '';
        }
        $out = mandala_post_meta_html($post);
        if (has_excerpt($post)) {
            $out .= '<p class="lead">' . esc_html(get_the_excerpt($post)) . '</p>';
        }
        return $out;
    },
]);

/** mandala/post-hero – kiemelt kép vagy illusztráció 16:9 keretben. */
mandala_add_block('mandala/post-hero', [
    'title' => 'Cikk borítókép',
    'template' => function ($attributes) {
        $post = get_post(get_queried_object_id());
        if (!$post) {
            return '';
        }
        $img = has_post_thumbnail($post)
            ? get_the_post_thumbnail($post, 'full', ['sizes' => '1040px', 'fetchpriority' => 'high', 'style' => 'height:100%;object-fit:cover', 'alt' => ''])
            : mandala_art_img((string) get_post_meta($post->ID, '_mandala_art', true) ?: 'bowl', (string) get_post_meta($post->ID, '_mandala_tone', true) ?: 'sand');
        return '<div class="media-frame" style="aspect-ratio:16/9">' . $img . '</div>';
    },
]);
