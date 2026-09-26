<?php
/** mandala/intents – „Mire van most szükséged?” szándék kártyák (pa_szandek szűrőre linkelnek). */

defined('ABSPATH') || exit;

/** Illusztráció <img> a generált SVG-kből. */
function mandala_art_img(string $art, string $tone = 'sand', string $alt = ''): string
{
    $file = "{$art}-{$tone}.svg";
    if (!file_exists(MANDALA_DIR . '/assets/art/' . $file)) {
        $file = 'bowl-sand.svg';
    }
    return '<img class="art" src="' . esc_url(MANDALA_URL . '/assets/art/' . $file) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async" width="400" height="400">';
}

mandala_add_block('mandala/intents', [
    'title' => 'Szándék kártyák',
    'template' => function ($attributes) {
        $tones = ['saffron', 'sage', 'maroon', 'sand'];
        $out = '<div class="' . mandala_classes($attributes, 'intent-grid') . '">';
        foreach (mandala_data('catalog')['intents'] ?? [] as $n => $intent) {
            $out .= '<a class="iu-card intent-card reveal" href="' . esc_url(mandala_shop_url(['szandek' => $intent['id']])) . '">'
                . '<div class="iu-card-image">' . mandala_art_img($intent['art'], $tones[$n % 4]) . '</div>'
                . '<div class="iu-card-body"><span class="num">0' . ($n + 1) . '</span><h3>' . esc_html($intent['label']) . '</h3><p>' . esc_html($intent['text']) . '</p>'
                . '<span class="go">' . esc_html__('Válogatás', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</span></div></a>';
        }
        return $out . '</div>';
    },
]);
