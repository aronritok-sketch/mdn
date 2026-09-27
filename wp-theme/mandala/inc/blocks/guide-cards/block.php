<?php
/**
 * mandala/guide-cards – „Segítünk választani”: a kérdőívek és az AI tanácsadó egy helyen.
 * A választássegítőn át érkező vásárlók többszörös arányban vásárolnak (termékajánló kvíz
 * benchmarkok), ezért a főoldal elején kap helyet. Csak a létező oldalak kártyái jelennek meg.
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/guide-cards', [
    'title' => 'Választássegítő kártyák',
    'template' => function ($attributes) {
        $cards = [
            ['hangtal-valaszto', 'wave', __('Hangtál-választó', 'mandala'), __('5 kérdés a hangról, a súlyról és a csakráról – és megmutatjuk, melyik tál illik hozzád.', 'mandala'), __('1 perc', 'mandala')],
            ['fustolo-valaszto', 'leaf', __('Füstölőválasztó', 'mandala'), __('Illat, alkalom, forma: 4 kérdés, és kiválogatjuk a hozzád illő füstölőket.', 'mandala'), __('1 perc', 'mandala')],
            ['ajandek-valaszto', 'gift', __('Ajándékválasztó', 'mandala'), __('Kinek szánod, mekkora ajándékot? 3 kérdés, kész válogatás árral.', 'mandala'), __('30 mp', 'mandala')],
        ];
        $out = '<div class="' . mandala_classes($attributes, 'guide-grid') . '">';
        $tones = ['saffron', 'sage', 'maroon'];
        foreach ($cards as $i => [$page, $icon, $title, $text, $time]) {
            $id = (int) get_option('mandala_page_' . $page);
            if (!$id || get_post_status($id) !== 'publish') {
                continue;
            }
            $out .= '<a class="guide-card reveal" href="' . esc_url(get_permalink($id)) . '"><span class="guide-ico">' . mandala_medal($tones[$i], 'guide-medal') . '<span>' . mandala_icon($icon) . '</span></span>'
                . '<span class="guide-body"><strong>' . esc_html($title) . '</strong><span>' . esc_html($text) . '</span></span>'
                . '<span class="guide-go"><small>' . esc_html($time) . '</small>' . mandala_icon('arrow', 'ico ico-s') . '</span></a>';
        }
        // Az AI tanácsadó (ha be van kapcsolva) – a chat felület nyílik meg, oldalváltás nélkül.
        if (function_exists('mandala_chat_ready') && mandala_chat_ready()) {
            $out .= '<button type="button" class="guide-card guide-card-chat reveal" data-chat-open><span class="guide-ico">' . mandala_medal('gold-night', 'guide-medal') . '<span>' . mandala_icon('sparkle') . '</span></span>'
                . '<span class="guide-body"><strong>' . esc_html__('Kérdezz tőlünk', 'mandala') . '</strong><span>' . esc_html__('Írd le, mit keresel – a tanácsadó a raktáron lévő termékekből ajánl, árral és linkkel.', 'mandala') . '</span></span>'
                . '<span class="guide-go"><small>' . esc_html__('azonnal', 'mandala') . '</small>' . mandala_icon('arrow', 'ico ico-s') . '</span></button>';
        }
        return $out . '</div>';
    },
]);
