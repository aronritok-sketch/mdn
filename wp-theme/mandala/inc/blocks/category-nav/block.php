<?php
/**
 * mandala/category-nav – a kínálat szélessége egy pillantásra: a legnépesebb alkategóriák
 * valódi termékfotóval és darabszámmal, a főkategóriák között felváltva.
 * (Baymard: ha a főoldal a termékfajták kis részét mutatja, a látogató félreérti, mit árul a bolt –
 * a termékfajták legalább 40%-a legyen látható.) Az indexből dolgozik, külön lekérdezés nélkül.
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/category-nav', [
    'title' => 'Kategóriasáv termékfotókkal',
    'attributes' => ['limit' => mandala_attr_def('10')],
    'fields' => [['panel' => 'Beállítások', 'fields' => ['limit' => ['type' => 'text', 'label' => 'Kategóriák száma']]]],
    'template' => function ($attributes) {
        if (!function_exists('mandala_product_index')) {
            return '';
        }
        $limit = max(4, min(16, (int) ($attributes['limit'] ?? 10)));
        // Alkategóriánként: darabszám és a legnépszerűbb, fotós, raktáron lévő termék képe.
        $stat = [];
        foreach (mandala_product_index() as $r) {
            if (empty($r['sub'])) {
                continue;
            }
            $s = &$stat[$r['sub']];
            $s['n'] = ($s['n'] ?? 0) + 1;
            // Fotó, ha van; különben a termék illusztrációja (a fotó mindig előnyt kap).
            $img = $r['img'] ?: ($r['art'] ?? '');
            $score = ($r['img'] ? 1000000 : 0) + (($r['stock'] ?? '') !== 'out' ? 100000 : 0) + (int) ($r['sales'] ?? 0);
            if ($img && $score > ($s['score'] ?? -1)) {
                $s['score'] = $score;
                $s['img'] = $img;
            }
            unset($s);
        }
        $lists = [];
        foreach (mandala_category_tree() as $main) {
            $subs = array_filter($main['subs'], fn($x) => !empty($stat[$x[0]]['img']) && $stat[$x[0]]['n'] >= 1);
            usort($subs, fn($a, $b) => $stat[$b[0]]['n'] <=> $stat[$a[0]]['n']);
            if ($subs) {
                $lists[] = array_values($subs);
            }
        }
        $picked = [];
        for ($i = 0; count($picked) < $limit && $lists; $i++) {
            foreach ($lists as $k => $list) {
                if (!isset($list[$i])) {
                    unset($lists[$k]);
                    continue;
                }
                $picked[] = $list[$i];
                if (count($picked) >= $limit) {
                    break;
                }
            }
        }
        if (count($picked) < 4) {
            return '';
        }
        $out = '<nav class="' . mandala_classes($attributes, 'cat-nav') . '" aria-label="' . esc_attr__('Népszerű kategóriák', 'mandala') . '"><ul>';
        foreach ($picked as [$slug, $label, $url]) {
            $out .= '<li><a href="' . esc_url($url) . '"><span class="cat-nav-img"><img src="' . esc_url($stat[$slug]['img']) . '" alt="" loading="lazy" decoding="async" width="160" height="160"></span>'
                . '<strong>' . esc_html($label) . '</strong><small>' . esc_html(sprintf(__('%s termék', 'mandala'), number_format_i18n($stat[$slug]['n']))) . '</small></a></li>';
        }
        return $out . '</ul></nav>';
    },
]);
