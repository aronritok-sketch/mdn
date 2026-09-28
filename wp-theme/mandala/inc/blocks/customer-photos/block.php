<?php
/**
 * mandala/customer-photos – „Vásárlóink fotói”: a jóváhagyott értékelésekhez feltöltött képek rácsban,
 * a termékre linkelve (közösségi bizonyíték valódi otthonokból). Nincs Instagram-kapcsolat hozzá:
 * a vásárló az értékelő oldalon tölti fel, az admin hagyja jóvá. Fotó nélkül a blokk üres (a szekció eltűnik).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/customer-photos', [
    'title' => 'Vásárlóink fotói',
    'attributes' => ['limit' => mandala_attr_def('8'), 'eyebrow' => mandala_attr_def(''), 'heading' => mandala_attr_def(''), 'text' => mandala_attr_def('')],
    'fields' => [['panel' => 'Beállítások', 'fields' => ['limit' => ['type' => 'text', 'label' => 'Fotók száma'], 'eyebrow' => ['type' => 'text', 'label' => 'Felirat'], 'heading' => ['type' => 'text', 'label' => 'Cím'], 'text' => ['type' => 'text', 'label' => 'Bevezető']]]],
    'template' => function ($attributes) {
        if (!post_type_exists('mandala_review')) {
            return '';
        }
        $limit = max(4, min(16, (int) ($attributes['limit'] ?? 8)));
        $items = [];
        foreach (get_posts(['post_type' => 'mandala_review', 'post_status' => 'publish', 'numberposts' => 60, 'meta_key' => '_photos', 'meta_compare' => 'EXISTS']) as $r) {
            $pid = (int) get_post_meta($r->ID, '_product', true);
            foreach (array_filter((array) get_post_meta($r->ID, '_photos', true)) as $att) {
                if (count($items) < $limit && wp_attachment_is_image((int) $att) && get_post_status($pid) === 'publish') {
                    $items[] = [(int) $att, $pid, (int) get_post_meta($r->ID, '_rating', true), $r];
                }
            }
        }
        if (!$items) {
            return '';
        }
        // A fejléc a blokkal együtt jelenik meg: fotó nélkül a szekció teljesen eltűnik (mandala-optional).
        $out = !empty($attributes['heading']) ? '<div class="section-head reveal"><div class="section-head-text">' . (!empty($attributes['eyebrow']) ? '<p class="eyebrow">' . esc_html($attributes['eyebrow']) . '</p>' : '')
            . '<h2>' . esc_html($attributes['heading']) . '</h2>' . (!empty($attributes['text']) ? '<p>' . esc_html($attributes['text']) . '</p>' : '') . '</div></div>' : '';
        $out .= '<ul class="' . mandala_classes($attributes, 'ugc-grid') . '">';
        foreach ($items as [$att, $pid, $rating, $r]) {
            $out .= '<li><a href="' . esc_url(get_permalink($pid)) . '">' . wp_get_attachment_image($att, 'medium', false, ['loading' => 'lazy', 'alt' => sprintf(__('Vásárlói fotó: %s', 'mandala'), get_the_title($pid))])
                . '<span class="ugc-cap"><span class="ugc-stars" aria-label="' . esc_attr(sprintf(__('%d csillag', 'mandala'), $rating)) . '">' . str_repeat('★', max(0, min(5, $rating))) . '</span>' . esc_html(get_the_title($pid)) . '</span></a></li>';
        }
        return $out . '</ul>';
    },
]);
