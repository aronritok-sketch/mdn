<?php
/**
 * Csomagkedvezmény: „hangtál + ütő + párna együtt −10%”. Az adminban összeállított csomagok a csomag
 * minden termékének oldalán megjelennek („Csomagban olcsóbb”, egy gombbal mind a kosárba), és ha a kosárban
 * a csomag összes darabja benne van, a kedvezmény magától jár (soronként áthúzott árral – az ÁFA is helyes,
 * mert a termék ára csökken, nem külön levonás).
 *
 *  - Egy termék egyszerre csak egy csomag kedvezményét kapja (az admin lista sorrendjében).
 *  - Több teljes csomag esetén annyiszor jár, ahány teljes csomag van a kosárban.
 *  - Ha valamelyik termék elfogy, a csomag nem jelenik meg a termékoldalon.
 * Admin: WooCommerce → Mandala csomagok (a „gyakran együtt vásárolt” párokból javaslatot is ad).
 */

defined('ABSPATH') || exit;

function mandala_bundles(bool $only_active = true): array
{
    $all = array_values(array_filter((array) get_option('mandala_bundles', []), 'is_array'));
    return $only_active ? array_values(array_filter($all, fn($b) => ($b['enabled'] ?? 'yes') === 'yes')) : $all;
}
/** A csomag termékei (id-k) – üres, ha valamelyik cikkszám hiányzik. */
function mandala_bundle_ids(array $b): array
{
    $ids = [];
    foreach ((array) ($b['skus'] ?? []) as $sku) {
        $id = (int) wc_get_product_id_by_sku($sku);
        if (!$id) {
            return [];
        }
        $ids[] = $id;
    }
    return count($ids) >= 2 ? $ids : [];
}
/** A termék „rendes” (csomag nélküli) ára – a kosárban módosított ártól függetlenül. */
function mandala_bundle_base_price(int $id): float
{
    static $cache = [];
    return $cache[$id] ??= (float) wc_get_product($id)->get_price();
}

/* ---------- Termékoldal: „Csomagban olcsóbb” ---------- */

add_action('mandala_summary_after_cart', function (WC_Product $product) {
    foreach (mandala_bundles() as $i => $b) {
        $ids = mandala_bundle_ids($b);
        if (!in_array($product->get_id(), $ids, true)) {
            continue;
        }
        $products = array_map('wc_get_product', $ids);
        if (array_filter($products, fn($p) => !$p || $p->get_status() !== 'publish' || !$p->is_purchasable() || !$p->is_in_stock())) {
            continue;
        }
        $full = array_sum(array_map(fn($p) => (float) wc_get_price_to_display($p), $products));
        $pct = (float) $b['percent'];
        $out = '<div class="bundle-offer"><p class="eyebrow">' . esc_html__('Csomagban olcsóbb', 'mandala') . ' · −' . esc_html((string) (int) $pct) . '%</p>'
            . '<h3>' . esc_html($b['name']) . '</h3><ul class="bundle-items">';
        foreach ($products as $p) {
            $out .= '<li><a href="' . esc_url($p->get_permalink()) . '"><span class="thumb">' . mandala_product_image($p, 'thumbnail') . '</span><span>' . esc_html($p->get_name()) . '<small>' . esc_html(mandala_fmt(wc_get_price_to_display($p))) . '</small></span></a></li>';
        }
        $out .= '</ul><div class="bundle-total"><span><s>' . esc_html(mandala_fmt($full)) . '</s> <strong class="num">' . esc_html(mandala_fmt(round($full * (1 - $pct / 100)))) . '</strong></span>'
            . '<a class="iu-button" href="' . esc_url(add_query_arg('mandala_bundle', $i, wc_get_cart_url())) . '">' . mandala_icon('bag', 'ico ico-s') . ' ' . esc_html__('Mind a kosárba', 'mandala') . '</a></div></div>';
        echo $out; // phpcs:ignore
        return; // egy ajánlat termékenként
    }
}, 12);

/** „Mind a kosárba”: a csomag összes terméke a kosárba, majd a kosár oldal. */
add_action('wp_loaded', function () {
    if (!isset($_GET['mandala_bundle']) || !function_exists('WC') || !WC()->cart) { // phpcs:ignore
        return;
    }
    $b = mandala_bundles()[(int) $_GET['mandala_bundle']] ?? null; // phpcs:ignore
    $ids = $b ? mandala_bundle_ids($b) : [];
    foreach ($ids as $id) {
        WC()->cart->add_to_cart($id, 1);
    }
    if ($ids) {
        wc_add_notice(sprintf(__('A „%s” csomag a kosárban – a kedvezményt már levontuk.', 'mandala'), $b['name']));
    }
    wp_safe_redirect(remove_query_arg('mandala_bundle'));
    exit;
}, 30);

/* ---------- Kosár: a kedvezmény ---------- */

add_action('woocommerce_before_calculate_totals', function ($cart) {
    $bundles = mandala_bundles();
    if (!$bundles) {
        return;
    }
    // Termékenként a kosárban lévő (nem ajándék) darabok.
    $qty = [];
    foreach ($cart->get_cart() as $key => $item) {
        if (empty($item['mandala_gwp'])) {
            $qty[(int) $item['product_id']] = ($qty[(int) $item['product_id']] ?? 0) + (int) $item['quantity'];
        }
        unset($cart->cart_contents[$key]['mandala_bundle']);
    }
    $discounted = []; // termék => [kedvezményes darab, %, csomag neve]
    foreach ($bundles as $b) {
        $ids = mandala_bundle_ids($b);
        if (!$ids) {
            continue;
        }
        $sets = min(array_map(fn($id) => ($qty[$id] ?? 0) - ($discounted[$id][0] ?? 0), $ids));
        if ($sets <= 0) {
            continue;
        }
        foreach ($ids as $id) {
            if (isset($discounted[$id])) {
                $sets = 0; // egy termék csak egy csomagban
            }
        }
        foreach ($sets > 0 ? $ids : [] as $id) {
            $discounted[$id] = [$sets, (float) $b['percent'], (string) $b['name']];
        }
    }
    foreach ($cart->get_cart() as $key => $item) {
        $id = (int) $item['product_id'];
        if (!empty($item['mandala_gwp']) || empty($discounted[$id][0])) {
            continue;
        }
        [$units, $pct, $name] = $discounted[$id];
        $n = min($units, (int) $item['quantity']);
        $base = mandala_bundle_base_price($id);
        // Az átlagos egységár úgy, hogy $n darab kapja a kedvezményt.
        $item['data']->set_price(round($base * (1 - $pct / 100 * $n / max(1, (int) $item['quantity'])), 2));
        $cart->cart_contents[$key]['mandala_bundle'] = $name . ' (−' . (int) $pct . '%)';
        $discounted[$id][0] -= $n;
    }
}, 30);

add_filter('woocommerce_cart_item_price', function ($html, $item) {
    if (empty($item['mandala_bundle'])) {
        return $html;
    }
    $base = mandala_bundle_base_price((int) $item['product_id']);
    return '<del>' . wc_price($base) . '</del> ' . $html;
}, 20, 2);
add_filter('woocommerce_get_item_data', function ($data, $item) {
    if (!empty($item['mandala_bundle'])) {
        $data[] = ['key' => __('Csomagkedvezmény', 'mandala'), 'value' => $item['mandala_bundle']];
    }
    return $data;
}, 10, 2);
add_action('woocommerce_checkout_create_order_line_item', function ($line, $key, $values) {
    if (!empty($values['mandala_bundle'])) {
        $line->add_meta_data(__('Csomagkedvezmény', 'mandala'), $values['mandala_bundle']);
    }
}, 10, 3);

/* ---------- Admin: WooCommerce → Mandala csomagok ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala csomagok', 'Mandala csomagok', 'manage_woocommerce', 'mandala-bundles', 'mandala_bundles_admin');
});
function mandala_bundles_admin(): void
{
    $all = mandala_bundles(false);
    if (!empty($_POST['mandala_bundle_action']) && check_admin_referer('mandala_bundles')) {
        $act = sanitize_key(wp_unslash($_POST['mandala_bundle_action']));
        $i = isset($_POST['i']) ? (int) $_POST['i'] : -1;
        if ($act === 'save') {
            $skus = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) wp_unslash($_POST['skus'] ?? '')))));
            $b = ['name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')), 'skus' => array_map('sanitize_text_field', $skus),
                  'percent' => max(1, min(50, (int) ($_POST['percent'] ?? 10))), 'enabled' => empty($_POST['enabled']) ? 'no' : 'yes'];
            if ($b['name'] && count($b['skus']) >= 2) {
                if ($i >= 0 && isset($all[$i])) {
                    $all[$i] = $b;
                } else {
                    $all[] = $b;
                }
                echo '<div class="notice notice-success"><p>Mentve.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Adj nevet, és legalább két cikkszámot.</p></div>';
            }
        } elseif ($act === 'delete' && isset($all[$i])) {
            array_splice($all, $i, 1);
            echo '<div class="notice notice-success"><p>Törölve.</p></div>';
        }
        update_option('mandala_bundles', $all, false);
    }
    $edit = isset($_GET['edit']) ? ($all[(int) $_GET['edit']] ?? null) : null; // phpcs:ignore
    $pre = sanitize_text_field(wp_unslash($_GET['skus'] ?? '')); // phpcs:ignore
    echo '<div class="wrap"><h1>Mandala csomagok</h1><p>Termékcsomagok kedvezménnyel. A csomag a benne lévő termékek oldalán jelenik meg („Csomagban olcsóbb”), és a kosárban magától érvényesül, ha minden darab benne van.</p>';
    echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Név</th><th>Termékek</th><th>Kedvezmény</th><th>Állapot</th><th></th></tr></thead><tbody>';
    foreach ($all as $i => $b) {
        $names = [];
        foreach ($b['skus'] as $sku) {
            $id = wc_get_product_id_by_sku($sku);
            $names[] = $id ? '<a href="' . esc_url(get_permalink($id)) . '">' . esc_html(get_the_title($id)) . '</a>' : '<span style="color:#a3322a">' . esc_html($sku) . ' (nincs ilyen)</span>';
        }
        echo '<tr><td><strong>' . esc_html($b['name']) . '</strong></td><td>' . implode('<br>', $names) . '</td><td>−' . (int) $b['percent'] . '%</td><td>' . (($b['enabled'] ?? 'yes') === 'yes' ? 'aktív' : 'kikapcsolva') . '</td>'
            . '<td><a class="button" href="' . esc_url(add_query_arg('edit', $i)) . '">Szerkesztés</a> <form method="post" style="display:inline">' . wp_nonce_field('mandala_bundles', '_wpnonce', true, false)
            . '<input type="hidden" name="mandala_bundle_action" value="delete"><input type="hidden" name="i" value="' . $i . '"><button class="button-link" onclick="return confirm(\'Törlöd a csomagot?\')">Törlés</button></form></td></tr>';
    }
    if (!$all) {
        echo '<tr><td colspan="5">Még nincs csomag.</td></tr>';
    }
    echo '</tbody></table>';
    $b = $edit ?: ['name' => '', 'skus' => $pre ? explode(',', $pre) : [], 'percent' => 10, 'enabled' => 'yes'];
    echo '<h2>' . ($edit ? 'Csomag szerkesztése' : 'Új csomag') . '</h2><form method="post">';
    wp_nonce_field('mandala_bundles');
    echo '<input type="hidden" name="mandala_bundle_action" value="save"><input type="hidden" name="i" value="' . ($edit ? (int) $_GET['edit'] : -1) . '">' // phpcs:ignore
        . '<table class="form-table"><tr><th>Név</th><td><input type="text" name="name" class="regular-text" value="' . esc_attr($b['name']) . '" placeholder="pl. Kezdő hangtál szett"></td></tr>'
        . '<tr><th>Cikkszámok</th><td><input type="text" name="skus" class="large-text" value="' . esc_attr(implode(', ', $b['skus'])) . '" placeholder="MND-HT-0490, MND-UT-0003, MND-PA-0001"><p class="description">Vesszővel elválasztva, legalább kettő.</p></td></tr>'
        . '<tr><th>Kedvezmény</th><td><input type="number" name="percent" min="1" max="50" value="' . (int) $b['percent'] . '" style="width:70px"> %</td></tr>'
        . '<tr><th>Állapot</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($b['enabled'] ?? 'yes', 'yes', false) . '> aktív</label></td></tr></table>';
    submit_button($edit ? 'Mentés' : 'Csomag létrehozása');
    echo '</form>';
    // Javaslatok a valódi együttes vásárlásokból.
    if (function_exists('mandala_reco_scores')) {
        $pairs = [];
        foreach (wc_get_products(['status' => 'publish', 'limit' => 30, 'return' => 'ids', 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC']) as $pid) {
            $scores = mandala_reco_scores([$pid]);
            arsort($scores);
            foreach (array_slice($scores, 0, 1, true) as $other => $n) {
                $k = min($pid, $other) . '-' . max($pid, $other);
                if ($n >= 2 && !isset($pairs[$k])) {
                    $pairs[$k] = [$pid, (int) $other, (int) $n];
                }
            }
        }
        if ($pairs) {
            echo '<h2>Javaslat: gyakran együtt vásárolják</h2><ul>';
            foreach (array_slice($pairs, 0, 8) as [$a, $o, $n]) {
                $sa = wc_get_product($a)?->get_sku();
                $so = wc_get_product($o)?->get_sku();
                if ($sa && $so) {
                    echo '<li>' . esc_html(get_the_title($a)) . ' + ' . esc_html(get_the_title($o)) . ' <span class="description">(' . $n . ' közös rendelés)</span> – <a href="' . esc_url(add_query_arg('skus', $sa . ',' . $so, remove_query_arg('edit'))) . '">csomag ebből</a></li>';
                }
            }
            echo '</ul>';
        }
    }
    echo '</div>';
}
