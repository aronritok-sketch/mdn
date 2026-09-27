<?php
/**
 * Tanuló ajánló – kézi párosítás nélkül.
 *
 *  - Éjszakánként a rendelésekből (2 év, feldolgozás alatt / teljesítve) kiszámolja, mit vesznek
 *    együtt (pl. füstölő + füstölőtartó). Termékenként a 12 legerősebb pár marad meg.
 *  - Termékoldal: „Gyakran együtt vásárolják” (kosárba tétellel), a „Hasonló darabok” karusszel és a
 *    kosár „Tedd teljessé” sora is ebből dolgozik; kevés adatnál a WooCommerce kapcsolódó termékei.
 *  - Kosár és minikosár: ha kevés hiányzik az ingyenes szállításhoz, 3 odaillő, olcsó termék
 *    („Ezzel ingyenes a szállítás”) – előnyben a kosárral együtt vett és a fogyóeszköz.
 *  - A levelek (vásárlás utáni ajánló) is ezt használják: mandala_reco_for().
 */

defined('ABSPATH') || exit;

/** A párosítások újraszámolása. Visszaad: a feldolgozott rendelések száma. */
function mandala_reco_build(): int
{
    $pairs = [];
    $n = 0;
    $page = 1;
    do {
        $orders = wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . (time() - 730 * DAY_IN_SECONDS), 'limit' => 200, 'paged' => $page++, 'type' => 'shop_order']);
        foreach ($orders as $order) {
            $ids = [];
            foreach ($order->get_items() as $item) {
                $ids[(int) $item->get_product_id()] = true;
            }
            $ids = array_keys($ids);
            $n++;
            if (count($ids) < 2 || count($ids) > 30) {
                continue;
            }
            foreach ($ids as $a) {
                foreach ($ids as $b) {
                    if ($a !== $b) {
                        $pairs[$a][$b] = ($pairs[$a][$b] ?? 0) + 1;
                    }
                }
            }
        }
    } while (count($orders) === 200);
    foreach ($pairs as $a => $list) {
        arsort($list);
        $pairs[$a] = array_slice($list, 0, 12, true);
    }
    update_option('mandala_reco', $pairs, false);
    update_option('mandala_reco_built', ['time' => time(), 'orders' => $n, 'products' => count($pairs)], false);
    return $n;
}

add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_reco_build', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(strtotime('tomorrow 03:00', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS), DAY_IN_SECONDS, 'mandala_reco_build', [], MANDALA_AS_GROUP);
    }
}, 30);
add_action('mandala_reco_build', 'mandala_reco_build');

/** Megvásárolható, látható, raktáron lévő termékek az indexből (id => sor). */
function mandala_reco_pool(): array
{
    static $pool = null;
    if ($pool === null) {
        $pool = [];
        foreach (mandala_product_index() as $row) {
            if (!empty($row['buyable']) && in_array($row['stock'] ?? '', ['in', 'low'], true) && empty($row['voucher'])) {
                $pool[(int) $row['id']] = $row;
            }
        }
    }
    return $pool;
}

/** Együtt vásárolt pontszámok egy vagy több termékhez. */
function mandala_reco_scores(array $ids): array
{
    $pairs = (array) get_option('mandala_reco', []);
    $scores = [];
    foreach ($ids as $id) {
        foreach ((array) ($pairs[(int) $id] ?? []) as $other => $count) {
            $scores[(int) $other] = ($scores[(int) $other] ?? 0) + (int) $count;
        }
    }
    return $scores;
}

/**
 * Ajánlás a megadott termékekhez: előbb az együtt vásároltak, aztán a WooCommerce kapcsolódó
 * termékei (azonos kategória / címke). Csak megvásárolható, raktáron lévő termék.
 */
function mandala_reco_for(array $ids, int $n = 8, array $exclude = []): array
{
    $pool = mandala_reco_pool();
    $exclude = array_map('intval', array_merge($ids, $exclude));
    $scores = mandala_reco_scores($ids);
    arsort($scores);
    $out = [];
    foreach (array_keys($scores) as $id) {
        if (isset($pool[$id]) && !in_array($id, $exclude, true)) {
            $out[] = $id;
        }
    }
    if (count($out) < $n) {
        foreach ($ids as $id) {
            foreach (wc_get_related_products((int) $id, $n * 2, $exclude) as $rel) {
                if (isset($pool[(int) $rel]) && !in_array((int) $rel, $out, true)) {
                    $out[] = (int) $rel;
                }
            }
        }
    }
    return array_slice($out, 0, $n);
}

/* ---------- A meglévő termékválogatásokba ---------- */

add_filter('mandala_product_selection', function ($custom, $mode, $limit) {
    if ($custom !== null) {
        return $custom;
    }
    if ($mode === 'related' && is_singular('product')) {
        return mandala_reco_for([get_queried_object_id()], (int) $limit);
    }
    if ($mode === 'cart' && function_exists('WC') && WC()->cart) {
        $in = array_values(array_unique(array_map(fn($i) => (int) $i['product_id'], WC()->cart->get_cart())));
        return $in ? mandala_reco_for($in, (int) $limit) : null;
    }
    return null;
}, 5, 3);

/* ---------- Termékoldal: „Gyakran együtt vásárolják” ---------- */

add_action('mandala_summary_after_cart', function (WC_Product $product) {
    $scores = mandala_reco_scores([$product->get_id()]);
    $pool = mandala_reco_pool();
    arsort($scores);
    // Csak valódi együttes vásárlásból (legalább 2 rendelés) – kitalált párosítást nem mutatunk.
    $ids = array_slice(array_keys(array_filter($scores, fn($c, $id) => $c >= 2 && isset($pool[$id]), ARRAY_FILTER_USE_BOTH)), 0, 3);
    if (!$ids) {
        return;
    }
    echo '<div class="bought-together"><p class="eyebrow">' . esc_html__('Gyakran együtt vásárolják', 'mandala') . '</p>' . mandala_reco_list($ids, true) . '</div>'; // phpcs:ignore
}, 15);

/** Kis terméklista kosárba tétel gombbal. $ajax: a minikosárban és a termékoldalon AJAX-szal. */
function mandala_reco_list(array $ids, bool $ajax): string
{
    $pool = mandala_reco_pool();
    $out = '<ul class="reco-list">';
    foreach ($ids as $id) {
        $row = $pool[$id] ?? null;
        $p = wc_get_product($id);
        if (!$row || !$p) {
            continue;
        }
        $add = $ajax
            ? '<a href="' . esc_url($p->add_to_cart_url()) . '" data-quantity="1" data-product_id="' . (int) $id . '" class="iu-button iu-button-small iu-button-outline add_to_cart_button ajax_add_to_cart" aria-label="' . esc_attr(sprintf(__('Kosárba: %s', 'mandala'), $p->get_name())) . '" rel="nofollow">' . esc_html__('Kosárba', 'mandala') . '</a>'
            : '<a href="' . esc_url(add_query_arg('add-to-cart', $id, wc_get_cart_url())) . '" class="iu-button iu-button-small iu-button-outline" aria-label="' . esc_attr(sprintf(__('Kosárba: %s', 'mandala'), $p->get_name())) . '" rel="nofollow">' . esc_html__('Kosárba', 'mandala') . '</a>';
        $out .= '<li><a class="reco-item" href="' . esc_url($row['url']) . '"><span class="thumb">' . mandala_product_image($p, 'thumbnail') . '</span><span class="reco-name">' . esc_html($p->get_name()) . '<small>' . esc_html(mandala_fmt((float) $row['price'])) . '</small></span></a>' . $add . '</li>';
    }
    return $out . '</ul>';
}

/* ---------- Kosár: „Ezzel ingyenes a szállítás” ---------- */

/** Olcsó, odaillő termékek a hiányzó összeghez. */
function mandala_gap_suggestions(float $gap, array $in_cart, int $n = 3): array
{
    $pool = mandala_reco_pool();
    $scores = mandala_reco_scores($in_cart);
    $max = max($gap + 3000, $gap * 1.5);
    $cands = [];
    foreach ($pool as $id => $row) {
        $price = (float) $row['price'];
        if (in_array($id, $in_cart, true) || $price < $gap || $price > $max) {
            continue;
        }
        $consumable = in_array($row['sub'] ?? '', (array) apply_filters('mandala_consumable_cats', ['fustolok', 'illoolajok', 'teak']), true);
        $cands[$id] = ($scores[$id] ?? 0) * 10 + ($consumable ? 3 : 0) - ($price - $gap) / max(1, $gap);
    }
    arsort($cands);
    return array_slice(array_keys($cands), 0, $n);
}

add_action('mandala_after_ship_meter', function ($where, $gap) {
    if (!function_exists('WC') || !WC()->cart || $gap <= 0) {
        return;
    }
    $in = array_values(array_unique(array_map(fn($i) => (int) $i['product_id'], WC()->cart->get_cart())));
    $ids = mandala_gap_suggestions((float) $gap, $in);
    if ($ids) {
        echo '<div class="gap-suggest"><p class="eyebrow">' . esc_html__('Ezzel ingyenes a szállítás', 'mandala') . '</p>' . mandala_reco_list($ids, $where === 'drawer') . '</div>'; // phpcs:ignore
    }
}, 10, 2);
