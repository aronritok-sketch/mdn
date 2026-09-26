<?php
/** mandala/page-lead – az oldal kivonata bevezetőként a H1 alatt (Oldal → Kivonat). */

defined('ABSPATH') || exit;

add_action('init', fn() => add_post_type_support('page', 'excerpt'));

mandala_add_block('mandala/page-lead', [
    'title' => 'Oldal bevezető (kivonat)',
    'template' => function ($attributes) {
        $post = get_post(get_queried_object_id());
        return $post && has_excerpt($post) ? '<p class="lead page-lead">' . esc_html(get_the_excerpt($post)) . '</p>' : '';
    },
]);
