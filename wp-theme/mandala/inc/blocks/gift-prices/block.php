<?php
/**
 * mandala/gift-prices – ajándék árkategóriák szerint: a leggyakoribb ajándékkeresési szándék
 * („mit vegyek X forintért?”) egy kattintással szűrt listára visz. Csak a nem üres sávok jelennek meg,
 * a darabszám élő (az indexből).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/gift-prices', [
    'title' => 'Ajándék árkategóriák',
    'template' => function ($attributes) {
        $bands = [[0, 5000], [0, 10000], [10000, 20000], [20000, 50000]];
        $rows = array_filter(function_exists('mandala_product_index') ? mandala_product_index() : [], fn($r) => ($r['stock'] ?? 'in') !== 'out');
        $gifts = array_filter($rows, fn($r) => in_array('ajandek', (array) ($r['intents'] ?? []), true));
        // Amíg kevés terméken van „ajándék” szándék (pl. a Claude migráció előtt), a teljes kínálatból számolunk.
        $by_intent = count($gifts) >= 12;
        $n = array_fill(0, count($bands), 0);
        foreach ($by_intent ? $gifts : $rows as $r) {
            foreach ($bands as $i => [$lo, $hi]) {
                $n[$i] += ($r['price'] >= $lo && $r['price'] <= $hi) ? 1 : 0;
            }
        }
        $out = '<ul class="' . mandala_classes($attributes, 'gift-prices') . '">';
        $shown = 0;
        foreach ($bands as $i => [$lo, $hi]) {
            if ($n[$i] < 3) {
                continue;
            }
            $label = $lo ? sprintf(__('%1$s – %2$s', 'mandala'), mandala_num($lo), mandala_fmt($hi)) : sprintf(__('%s alatt', 'mandala'), mandala_fmt($hi));
            $out .= '<li><a href="' . esc_url(mandala_shop_url(($by_intent ? ['szandek' => 'ajandek'] : []) + ['ar' => $lo . '-' . $hi])) . '"><strong>' . esc_html($label) . '</strong><small>'
                . esc_html(sprintf(__('%s ajándékötlet', 'mandala'), number_format_i18n($n[$i]))) . '</small>' . mandala_icon('arrow', 'ico ico-s') . '</a></li>';
            $shown++;
        }
        return $shown ? $out . '</ul>' : '';
    },
]);
