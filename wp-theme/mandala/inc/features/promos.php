<?php
/**
 * Kampány-ajánlatok: a Kampányok (campaigns.php) bejegyzéseihez kötött szabályok, amelyek a kampány idejére
 * maguktól élnek, és utána maguktól leállnak (WooCommerce → Kampányok → „Ajánlat”).
 *
 *  - 1+1 („egyet fizetsz, kettőt viszel”) kategóriákra. A kosárban a jogosult darabok ár szerint csökkenő sorba
 *    állnak, és minden pár olcsóbbik darabja 0 Ft. Soronként, áthúzott árral, így az ÁFA is helyes.
 *  - Százalékos kedvezmény (alap + kategóriánkénti kivételek) magában az árban: termékoldal, kártya, kosár, feed.
 *    Kimarad a már akciós termék, az utalvány, a csomagolás, a jegy, és amelyik termék az elmúlt 30 napban olcsóbb
 *    volt. Ez az árcsökkentési szabály miatt kell: a korábbi ár a 30 napos legalacsonyabb ár (lásd az ár-naplót lent).
 *  - Ingyenes szállítás bármekkora rendelésnél.
 *  - Díszcsomagolás és kézzel írt kártya ajándékba (pénztári választás), a csomagolás-termék 0 Ft.
 *  - Ajándék értékhatár felett (a gwp.php szabálya a kampány idejére).
 *
 * Több egyszerre futó kampány szabályai összeadódnak. Például a Black Friday (ingyenes szállítás) a Black Week
 * (20%) alatt fut. A felső sávot és a bannert a lista első aktív kampánya adja (campaigns.php).
 * Viszonteladónak (nagyker ár) egyik szabály sem jár. Kuponnal (a kampány-kedvezményes tételekre) nem adódik össze.
 */

defined('ABSPATH') || exit;

/* ---------- Szabályok ---------- */

/** Egy kampány most fut-e ($now: időbélyeg, tesztekhez). */
function mandala_campaign_is_active(array $c, ?int $now = null): bool
{
    $now ??= time();
    if (($c['enabled'] ?? 'yes') !== 'yes') {
        return false;
    }
    $start = strtotime(($c['start'] ?? '') . ' ' . wp_timezone_string());
    $end = strtotime(($c['end'] ?? '') . ' ' . wp_timezone_string());
    return $start && $end && $now >= $start && $now < $end;
}

/** Egy kampány szabályai tisztítva (a Kampányok mentésekor és olvasáskor is ezen megy át). */
function mandala_promo_clean_rules($r): array
{
    $r = is_array($r) ? $r : [];
    $slugs = fn($v) => array_values(array_unique(array_filter(array_map('sanitize_title', is_array($v) ? $v : preg_split('/[\s,;]+/', (string) $v)))));
    $cats = [];
    foreach ((array) ($r['pct_cats'] ?? []) as $slug => $pct) {
        $slug = sanitize_title((string) $slug);
        if ($slug !== '') {
            $cats[$slug] = max(0, min(90, (float) $pct));
        }
    }
    $gwp = (array) ($r['gwp'] ?? []);
    return [
        'bogo' => $slugs($r['bogo'] ?? []),
        'pct' => max(0, min(90, (float) ($r['pct'] ?? 0))),
        'pct_cats' => $cats,
        'freeship' => ($r['freeship'] ?? 'no') === 'yes' || ($r['freeship'] ?? false) === true ? 'yes' : 'no',
        'wrap' => ($r['wrap'] ?? 'no') === 'yes' || ($r['wrap'] ?? false) === true ? 'yes' : 'no',
        'gwp' => ['min' => max(0, (int) ($gwp['min'] ?? 0)), 'sku' => sanitize_text_field((string) ($gwp['sku'] ?? '')), 'label' => sanitize_text_field((string) ($gwp['label'] ?? ''))],
    ];
}

/** Van-e a kampánynak érdemi ajánlata. */
function mandala_promo_has_rules(array $r): bool
{
    return $r['bogo'] || $r['pct'] > 0 || $r['pct_cats'] || $r['freeship'] === 'yes' || $r['wrap'] === 'yes' || ($r['gwp']['min'] > 0 && $r['gwp']['sku'] !== '');
}

/** Az adott pillanatban futó kampányok szabályai összevonva. Vásárlótól független; a viszonteladót lásd lent. */
function mandala_promo_rules(?int $now = null): array
{
    static $cache = [];
    $now ??= time();
    $key = intdiv($now, 60) . ':' . md5(wp_json_encode(get_option('mandala_campaigns', [])));
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $out = ['bogo' => [], 'pct' => 0.0, 'pct_cats' => [], 'freeship' => false, 'wrap' => false, 'gwp' => null, 'names' => [], 'start' => 0, 'end' => 0];
    foreach (mandala_campaigns() as $c) {
        if (empty($c['rules']) || !mandala_campaign_is_active($c, $now)) {
            continue;
        }
        $r = mandala_promo_clean_rules($c['rules']);
        if (!mandala_promo_has_rules($r)) {
            continue;
        }
        $out['names'][] = (string) $c['name'];
        $out['bogo'] = array_values(array_unique(array_merge($out['bogo'], $r['bogo'])));
        if ($out['pct'] <= 0 && !$out['pct_cats'] && ($r['pct'] > 0 || $r['pct_cats'])) {
            $out['pct'] = $r['pct'];
            $out['pct_cats'] = $r['pct_cats'];
            $out['start'] = (int) strtotime($c['start'] . ' ' . wp_timezone_string());
            $out['end'] = (int) strtotime($c['end'] . ' ' . wp_timezone_string());
        }
        $out['freeship'] = $out['freeship'] || $r['freeship'] === 'yes';
        $out['wrap'] = $out['wrap'] || $r['wrap'] === 'yes';
        if (!$out['gwp'] && $r['gwp']['min'] > 0 && $r['gwp']['sku'] !== '') {
            $out['gwp'] = $r['gwp'];
        }
    }
    return $cache[$key] = $out;
}

/** A vásárlóra érvényes szabályok: viszonteladónak (nagyker ár) semmi. */
function mandala_promo_user_rules(): array
{
    $r = mandala_promo_rules();
    if ((function_exists('mandala_is_wholesale_user') && mandala_is_wholesale_user()) || mandala_promo_off_context() || !apply_filters('mandala_promo_enabled', true)) {
        return ['bogo' => [], 'pct' => 0.0, 'pct_cats' => [], 'freeship' => false, 'wrap' => false, 'gwp' => null, 'names' => [], 'start' => 0, 'end' => 0];
    }
    return $r;
}

/**
 * Ahol a kampányár nem látszhat: az admin oldalai (különben egy beállítás-mentés tartóssá tenné, pl. az ingyenes
 * szállítás 1 Ft-os határát) és a REST API (a JUTA-import és a külső rendszerek a valódi árat lássák). Az admin-ajax
 * (rendelés kézi összeállítása) és a vásárlói wc-ajax (kosár, pénztár) kampányárral megy.
 */
function mandala_promo_off_context(): bool
{
    return (is_admin() && !wp_doing_ajax()) || (defined('REST_REQUEST') && REST_REQUEST);
}

/** Ujjlenyomat (gyorsítótárakhoz: feed, változat-árak). */
function mandala_promo_sig(): string
{
    $r = mandala_promo_rules();
    unset($r['names']);
    return md5(wp_json_encode($r));
}

/* ---------- Termékek ---------- */

/** A termék (változatnál a szülő) kategóriáinak slugjai az összes ősükkel. */
function mandala_promo_cat_slugs(int $product_id): array
{
    static $cache = [];
    if (isset($cache[$product_id])) {
        return $cache[$product_id];
    }
    $slugs = [];
    $terms = get_the_terms($product_id, 'product_cat');
    foreach ($terms && !is_wp_error($terms) ? $terms : [] as $t) {
        $slugs[] = $t->slug;
        foreach (get_ancestors($t->term_id, 'product_cat') as $a) {
            $term = get_term((int) $a, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $slugs[] = $term->slug;
            }
        }
    }
    return $cache[$product_id] = array_values(array_unique($slugs));
}

function mandala_promo_parent_id(WC_Product $p): int
{
    return $p->is_type('variation') ? (int) $p->get_parent_id() : (int) $p->get_id();
}

/** Kampány-kedvezmény egyáltalán járhat-e rá (utalvány, csomagolás, jegy, ingyenes termék nem). */
function mandala_promo_basic_ok(WC_Product $p): bool
{
    $parent = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : $p;
    if (!$parent || (function_exists('mandala_is_voucher') && mandala_is_voucher($parent))) {
        return false;
    }
    if ($parent->get_meta('_mandala_event_start', true, 'edit') || $parent->get_meta('_mandala_ticket_for', true, 'edit')) {
        return false;
    }
    return (float) $p->get_regular_price('edit') > 0;
}

function mandala_promo_is_giftbox(WC_Product $p): bool
{
    $parent = $p->is_type('variation') ? wc_get_product($p->get_parent_id()) : $p;
    return $parent && $parent->get_meta('_mandala_giftbox', true, 'edit') === 'yes';
}

/** A termék az 1+1 kategóriák valamelyikében van-e. */
function mandala_promo_in_bogo(WC_Product $p, ?array $rules = null): bool
{
    $rules ??= mandala_promo_user_rules();
    return $rules['bogo'] && array_intersect($rules['bogo'], mandala_promo_cat_slugs(mandala_promo_parent_id($p))) && mandala_promo_basic_ok($p) && !mandala_promo_is_giftbox($p);
}

/**
 * Százalékos kampány-kedvezményre jogosult-e a termék – a mostani árától függetlenül (az index is ezt viszi):
 * nem utalvány / csomagolás / jegy, és az elmúlt 30 napban nem volt olcsóbb, mint a mostani rendes ára.
 */
function mandala_promo_pct_ok(WC_Product $p, int $start = 0): bool
{
    if (!mandala_promo_basic_ok($p) || mandala_promo_is_giftbox($p)) {
        return false;
    }
    // A kampány kezdete előtti 30 nap számít: a kampány alatt a napló már a kampányárat is viszi.
    $low = mandala_price_low30($p, $start ?: time());
    return $low === null || $low >= (float) $p->get_regular_price('edit') - 0.5;
}

/** A termékre most járó százalék (0, ha semmi): kategória-kivétel → alap; az 1+1 kategóriák nem kapnak százalékot. */
function mandala_promo_pct(WC_Product $p, ?array $rules = null): float
{
    $rules ??= mandala_promo_user_rules();
    if ($rules['pct'] <= 0 && !$rules['pct_cats']) {
        return 0.0;
    }
    $slugs = mandala_promo_cat_slugs(mandala_promo_parent_id($p));
    if ($rules['bogo'] && array_intersect($rules['bogo'], $slugs)) {
        return 0.0;
    }
    $pct = $rules['pct'];
    $hits = array_intersect_key($rules['pct_cats'], array_flip($slugs));
    if ($hits) {
        $pct = min($hits); // több kivétel esetén az óvatosabb
    }
    if ($pct <= 0) {
        return 0.0;
    }
    // A már akciós termék a saját akciós árán marad (nem adódik össze).
    if ($p->is_on_sale('edit') && $p->get_sale_price('edit') !== '') {
        return 0.0;
    }
    return mandala_promo_pct_ok($p, (int) ($rules['start'] ?? 0)) ? $pct : 0.0;
}

/** A kampányár, ha a termékre most kampányár jár (különben null). Alapból a vásárló szerint (viszonteladónak null); a feed a bolti szabályokat adja át. */
function mandala_promo_price_for(WC_Product $p, ?array $rules = null): ?float
{
    $rules ??= mandala_promo_user_rules();
    $out = null;
    if ($rules['wrap'] && mandala_promo_is_giftbox($p)) {
        $out = 0.0;
    } else {
        $pct = mandala_promo_pct($p, $rules);
        if ($pct > 0) {
            $out = round((float) $p->get_regular_price('edit') * (100 - $pct) / 100, wc_get_price_decimals());
        }
    }
    return $out;
}

/* ---------- Ár a megjelenítésben és a kosárban ---------- */

function mandala_promo_filter_price($price, $product)
{
    if (!$product instanceof WC_Product || $price === '' || $price === null) {
        return $price;
    }
    // Ha valaki már beállította a kosársor árát (csomag, 1+1), az az erősebb – nem vonjuk le még egyszer.
    if (array_key_exists('price', $product->get_changes())) {
        return $price;
    }
    $promo = mandala_promo_price_for($product);
    return $promo !== null && $promo < (float) $price ? (string) $promo : $price;
}
function mandala_promo_filter_sale($sale, $product)
{
    if (!$product instanceof WC_Product || array_key_exists('price', $product->get_changes())) {
        return $sale;
    }
    $promo = mandala_promo_price_for($product);
    return $promo !== null ? (string) $promo : $sale;
}
foreach (['woocommerce_product_get_price', 'woocommerce_product_variation_get_price'] as $mandala_hook) {
    add_filter($mandala_hook, 'mandala_promo_filter_price', 50, 2);
}
foreach (['woocommerce_product_get_sale_price', 'woocommerce_product_variation_get_sale_price'] as $mandala_hook) {
    add_filter($mandala_hook, 'mandala_promo_filter_sale', 50, 2);
}
add_filter('woocommerce_variation_prices_price', fn($price, $variation) => mandala_promo_filter_price($price, $variation), 50, 2);
add_filter('woocommerce_variation_prices_sale_price', fn($sale, $variation) => mandala_promo_filter_sale($sale, $variation), 50, 2);
add_filter('woocommerce_get_variation_prices_hash', function ($hash) {
    $hash[] = mandala_promo_sig() . (mandala_is_wholesale_user() ? 'w' : '');
    return $hash;
});

/* ---------- 1+1 a kosárban ---------- */

/** A termék kampány előtti ára a kosárban (friss objektumból, akciós ár, ha van). */
function mandala_promo_base_price(int $id): float
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $p = wc_get_product($id);
        $cache[$id] = $p ? (float) $p->get_price() : 0.0;
    }
    return $cache[$id];
}

/**
 * Az 1+1 elosztása: [kosárkulcs => [darab, egységár]] → [kosárkulcs => ingyenes darab]. A darabok ár szerint
 * csökkenő sorba állnak, és minden pár második (olcsóbbik) darabja ingyenes. Tiszta függvény (teszt: wp-features).
 */
function mandala_promo_bogo_split(array $lines): array
{
    $units = [];
    foreach ($lines as $key => [$qty, $price]) {
        for ($i = 0; $i < (int) $qty; $i++) {
            $units[] = [$key, (float) $price];
        }
    }
    usort($units, fn($a, $b) => $b[1] <=> $a[1]);
    $free = [];
    foreach ($units as $i => [$key]) {
        if ($i % 2 === 1) {
            $free[$key] = ($free[$key] ?? 0) + 1;
        }
    }
    return $free;
}

add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    foreach (array_keys($cart->cart_contents) as $key) {
        unset($cart->cart_contents[$key]['mandala_bogo']);
    }
    $rules = mandala_promo_user_rules();
    if (!$rules['bogo']) {
        return;
    }
    $lines = [];
    foreach ($cart->get_cart() as $key => $item) {
        if (!empty($item['mandala_gwp']) || !mandala_promo_in_bogo($item['data'], $rules)) {
            continue;
        }
        $lines[$key] = [(int) $item['quantity'], mandala_promo_base_price((int) ($item['variation_id'] ?: $item['product_id']))];
    }
    $free = mandala_promo_bogo_split($lines);
    foreach ($lines as $key => [$qty, $base]) {
        $f = $free[$key] ?? 0;
        $cart->cart_contents[$key]['data']->set_price(round($base * ($qty - $f) / max(1, $qty), 2));
        $cart->cart_contents[$key]['mandala_bogo'] = ['free' => $f, 'base' => $base];
    }
}, 25);

add_filter('woocommerce_cart_item_price', function ($html, $item) {
    $b = $item['mandala_bogo'] ?? null;
    return $b && $b['free'] > 0 ? '<del>' . wc_price($b['base']) . '</del> ' . $html : $html;
}, 20, 2);
add_filter('woocommerce_get_item_data', function ($data, $item) {
    $b = $item['mandala_bogo'] ?? null;
    if ($b && $b['free'] > 0) {
        $data[] = ['key' => __('1+1', 'mandala'), 'value' => sprintf(_n('%d db ingyen', '%d db ingyen', $b['free'], 'mandala'), $b['free'])];
    }
    return $data;
}, 10, 2);
add_action('woocommerce_checkout_create_order_line_item', function ($line, $key, $values) {
    $b = $values['mandala_bogo'] ?? null;
    if ($b && $b['free'] > 0) {
        $line->add_meta_data(__('1+1 (egyet fizetsz, kettőt viszel)', 'mandala'), sprintf(__('%1$d db ingyen, egységár %2$s', 'mandala'), $b['free'], mandala_fmt($b['base'])));
    }
}, 10, 3);

/* ---------- Kupon: a kampány-kedvezményes tételre nem adódik össze ---------- */

add_filter('woocommerce_coupon_is_valid_for_product', function ($valid, $product, $coupon, $values) {
    if (!$valid || !$product instanceof WC_Product || ($coupon instanceof WC_Coupon && stripos($coupon->get_code(), 'mnd-') === 0)) {
        return $valid;
    }
    $rules = mandala_promo_user_rules();
    if (mandala_promo_in_bogo($product, $rules) || mandala_promo_pct($product, $rules) > 0) {
        return false;
    }
    return $valid;
}, 20, 4);
add_filter('woocommerce_coupon_error', function ($err, $code, $coupon) {
    if ((int) $code === WC_Coupon::E_WC_COUPON_NOT_APPLICABLE && mandala_promo_user_rules()['names']) {
        return __('A kampányban kedvezményes termékekre a kupon nem érvényes – a kettő nem adódik össze.', 'mandala');
    }
    return $err;
}, 10, 3);

/* ---------- Ingyenes szállítás, ajándék értékhatár felett ---------- */

add_filter('pre_option_mandala_freeShippingFrom', function ($pre) {
    return mandala_promo_user_rules()['freeship'] ? '1' : $pre; // 1 Ft: bármekkora rendelés (a 0 „kikapcsolva” lenne)
});
add_filter('mandala_growth_settings', function ($s) {
    $g = mandala_promo_user_rules()['gwp'];
    if ($g) {
        $s['gwp'] = 'yes';
        $s['gwp_threshold'] = $g['min'];
        $s['gwp_sku'] = $g['sku'];
        $s['gwp_label'] = $g['label'] ?: $s['gwp_label'];
    }
    return $s;
});

/* ---------- Díszcsomagolás és kártya ajándékba (pénztár) ---------- */

add_action('woocommerce_after_order_notes', function () {
    if (!mandala_promo_user_rules()['wrap']) {
        return;
    }
    $on = !empty($_POST['mandala_wrap']); // phpcs:ignore
    echo '<fieldset class="mandala-wrap form-row form-row-wide"><legend class="sr-only">' . esc_html__('Díszcsomagolás', 'mandala') . '</legend>'
        . '<label class="checkbox"><input type="checkbox" name="mandala_wrap" value="1"' . checked($on, true, false) . '> <strong>' . esc_html__('Díszcsomagolásban kérem, kézzel írt kártyával', 'mandala') . '</strong> <span class="gwp-tag">' . esc_html__('ajándék', 'mandala') . '</span></label>'
        . '<label for="mandala_wrap_msg" class="sr-only">' . esc_html__('A kártya szövege', 'mandala') . '</label>'
        . '<textarea id="mandala_wrap_msg" name="mandala_wrap_msg" rows="2" maxlength="200" placeholder="' . esc_attr__('A kártya szövege (nem kötelező) – kézzel írjuk rá', 'mandala') . '">' . esc_textarea(sanitize_textarea_field(wp_unslash($_POST['mandala_wrap_msg'] ?? ''))) . '</textarea></fieldset>'; // phpcs:ignore
}, 5);
add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    if (!mandala_promo_user_rules()['wrap'] || empty($_POST['mandala_wrap'])) { // phpcs:ignore -- a WooCommerce checkout nonce-ot ellenőrzött
        return;
    }
    $order->update_meta_data('_mandala_wrap', 'yes');
    $msg = mb_substr(sanitize_textarea_field(wp_unslash($_POST['mandala_wrap_msg'] ?? '')), 0, 200); // phpcs:ignore
    if ($msg !== '') {
        $order->update_meta_data('_mandala_wrap_msg', $msg);
    }
});
/** A díszcsomagolás kérése egy rendelésnél (null: nem kérte). */
function mandala_order_wrap(WC_Order $order): ?array
{
    return $order->get_meta('_mandala_wrap') === 'yes' ? ['msg' => (string) $order->get_meta('_mandala_wrap_msg')] : null;
}
function mandala_order_wrap_html(WC_Order $order, bool $plain = false): string
{
    $w = mandala_order_wrap($order);
    if (!$w) {
        return '';
    }
    $text = __('Díszcsomagolás, kézzel írt kártyával (ajándék)', 'mandala') . ($w['msg'] !== '' ? ' – ' . __('a kártya szövege:', 'mandala') . ' „' . $w['msg'] . '”' : '');
    return $plain ? $text . "\n" : '<p class="mandala-wrap-note"><strong>' . esc_html__('Díszcsomagolás', 'mandala') . ':</strong> ' . esc_html($w['msg'] !== '' ? __('kézzel írt kártyával', 'mandala') . ' – „' . $w['msg'] . '”' : __('kézzel írt kártyával', 'mandala')) . '</p>';
}
add_action('woocommerce_admin_order_data_after_shipping_address', fn($order) => print(mandala_order_wrap_html($order))); // phpcs:ignore
add_action('woocommerce_order_details_after_order_table', fn($order) => print(mandala_order_wrap_html($order))); // phpcs:ignore
add_action('woocommerce_email_order_meta', function ($order, $sent_to_admin, $plain) {
    echo $plain ? esc_html(mandala_order_wrap_html($order, true)) : mandala_order_wrap_html($order); // phpcs:ignore
}, 20, 3);

/* ---------- Kosár- és termékoldali jelzések ---------- */

add_action('mandala_cart_meters', function () {
    $cart = WC()->cart;
    $rules = mandala_promo_user_rules();
    if (!$cart || !$rules['names']) {
        return;
    }
    if ($rules['bogo']) {
        $n = 0;
        foreach ($cart->get_cart() as $item) {
            if (empty($item['mandala_gwp']) && mandala_promo_in_bogo($item['data'], $rules)) {
                $n += (int) $item['quantity'];
            }
        }
        if ($n % 2 === 1) {
            echo '<div class="ship-meter promo-meter"><p>' . mandala_icon('gift', 'ico ico-s') . ' ' . esc_html__('Tegyél mellé még egy füstölőt – az egyik ingyen van.', 'mandala') . '</p></div>'; // phpcs:ignore
        }
    }
    if ($rules['wrap']) {
        echo '<div class="ship-meter promo-meter is-done"><p>' . mandala_icon('gift', 'ico ico-s') . ' ' . esc_html__('Díszcsomagolás és kézzel írt kártya ajándékba – a pénztárban kérheted.', 'mandala') . '</p></div>'; // phpcs:ignore
    }
}, 5);
add_filter('mandala_card_badges', function ($badges, $product) {
    return $product instanceof WC_Product && mandala_promo_in_bogo($product) ? '<span class="badge badge-sale">1+1</span>' . $badges : $badges;
}, 10, 2);
add_action('mandala_summary_after_cart', function (WC_Product $product) {
    $rules = mandala_promo_user_rules();
    if (mandala_promo_in_bogo($product, $rules)) {
        echo '<p class="promo-note">' . mandala_icon('gift', 'ico ico-s') . ' <strong>' . esc_html__('Egyet fizetsz, kettőt viszel', 'mandala') . '</strong> – ' . esc_html__('két füstölőből az olcsóbbat mi álljuk, vegyes illatokkal is.', 'mandala') . '</p>'; // phpcs:ignore
    }
}, 5);

/* ---------- A böngésző indexe: a kampányszabályok időablakkal ---------- */

/** A futó vagy a legközelebbi százalékos kampány kezdete (0, ha nincs). */
function mandala_promo_next_pct_start(): int
{
    $best = 0;
    foreach (mandala_promo_windows(true) as $w) {
        if (($w['pct'] > 0 || (array) $w['cats']) && (!$best || $w['s'] < $best)) {
            $best = $w['s'];
        }
    }
    return $best;
}
add_filter('mandala_product_index_row', function ($row, $product) {
    static $start = null;
    $start ??= mandala_promo_next_pct_start();
    $row['pc'] = mandala_promo_cat_slugs((int) $product->get_id());
    $row['pok'] = mandala_promo_pct_ok($product, $start) ? 1 : 0;
    return $row;
}, 20, 2);
/** A közeli (60 napon belüli) kampányok ár-szabályai a böngészőnek – a kártya maga dönti el az időből, melyik él. */
function mandala_promo_windows(bool $all_users = false): array
{
    if (!$all_users && function_exists('mandala_is_wholesale_user') && mandala_is_wholesale_user()) {
        return [];
    }
    $out = [];
    $tz = wp_timezone_string();
    foreach (mandala_campaigns() as $c) {
        if (($c['enabled'] ?? 'yes') !== 'yes' || empty($c['rules'])) {
            continue;
        }
        $r = mandala_promo_clean_rules($c['rules']);
        $start = (int) strtotime($c['start'] . ' ' . $tz);
        $end = (int) strtotime($c['end'] . ' ' . $tz);
        if (!$r['bogo'] && $r['pct'] <= 0 && !$r['pct_cats'] || $end < time() || $start > time() + 60 * DAY_IN_SECONDS) {
            continue;
        }
        $out[] = ['s' => $start, 'e' => $end, 'bogo' => $r['bogo'], 'pct' => $r['pct'], 'cats' => (object) $r['pct_cats']];
    }
    return $out;
}
add_filter('mandala_js_data', function ($data) {
    $data['promo'] = mandala_promo_windows();
    return $data;
});

/* ---------- 30 napos ár-napló (árcsökkentési szabály) ---------- */

/**
 * Termékenként a napi legalacsonyabb tényleges ár (rendes vagy akciós, és a kampányár, ha volt) az utolsó 35 napra:
 * `_mandala_plog` = [ 'Y-m-d' => ár ]. Naponta hajnalban az egész kínálatra, és minden ár-mentéskor az adott termékre.
 * Az első futás az elmúlt 30 nap rendeléseiből (bolti vásárlók, kupon előtti egységár) pótolja a korábbi árakat.
 */
function mandala_price_log_today(): string
{
    return wp_date('Y-m-d');
}
/** A termék mostani tényleges ára a naplóhoz (kampány nélküli vásárló szemével). */
function mandala_price_log_value(WC_Product $p): float
{
    $regular = (float) $p->get_regular_price('edit');
    $price = $p->is_on_sale('edit') && $p->get_sale_price('edit') !== '' ? (float) $p->get_sale_price('edit') : $regular;
    $rules = mandala_promo_rules();
    if ($rules['pct'] > 0 || $rules['pct_cats']) {
        $pct = mandala_promo_pct($p, $rules);
        if ($pct > 0) {
            $price = min($price, round($regular * (100 - $pct) / 100, wc_get_price_decimals()));
        }
    }
    return $price;
}
function mandala_price_log_add(int $id, string $day, float $price): void
{
    if ($price <= 0) {
        return;
    }
    $log = get_post_meta($id, '_mandala_plog', true);
    $log = is_array($log) ? $log : [];
    if (isset($log[$day]) && (float) $log[$day] <= $price) {
        return;
    }
    $log[$day] = $price;
    $cut = wp_date('Y-m-d', time() - 35 * DAY_IN_SECONDS);
    $log = array_filter($log, fn($d) => $d >= $cut, ARRAY_FILTER_USE_KEY);
    ksort($log);
    update_post_meta($id, '_mandala_plog', $log);
}
/** A $before napja előtti 30 nap legalacsonyabb ára (az aznapi nélkül; null, ha nincs naplóbejegyzés). */
function mandala_price_low30(WC_Product $p, ?int $before = null): ?float
{
    $log = get_post_meta($p->get_id(), '_mandala_plog', true);
    if (!is_array($log) || !$log) {
        return null;
    }
    $before ??= time();
    $from = wp_date('Y-m-d', $before - 30 * DAY_IN_SECONDS);
    $to = wp_date('Y-m-d', $before);
    $vals = array_filter($log, fn($d) => $d >= $from && $d < $to, ARRAY_FILTER_USE_KEY);
    return $vals ? (float) min($vals) : null;
}
add_action('woocommerce_after_product_object_save', function ($p) {
    if ($p instanceof WC_Product && !$p->is_type('variable')) {
        mandala_price_log_add($p->get_id(), mandala_price_log_today(), mandala_price_log_value($p));
    }
});
mandala_recurring('mandala_price_log', DAY_IN_SECONDS, function () {
    $t = new DateTime('tomorrow 03:10', wp_timezone());
    return $t->getTimestamp();
});
add_action('mandala_price_log', 'mandala_price_log_run');
function mandala_price_log_run(): int
{
    if (!function_exists('wc_get_products')) {
        return 0;
    }
    if (!get_option('mandala_plog_backfill')) {
        mandala_price_log_backfill();
    }
    $day = mandala_price_log_today();
    $n = 0;
    $ids = wc_get_products(['status' => ['publish', 'private'], 'limit' => -1, 'return' => 'ids', 'type' => ['simple', 'variation', 'variable']]);
    foreach (array_chunk($ids, 200) as $chunk) {
        _prime_post_caches($chunk, true, true);
        foreach ($chunk as $id) {
            $p = wc_get_product($id);
            if (!$p) {
                continue;
            }
            foreach ($p->is_type('variable') ? array_filter(array_map('wc_get_product', $p->get_children())) : [$p] as $v) {
                mandala_price_log_add($v->get_id(), $day, mandala_price_log_value($v));
                $n++;
            }
        }
    }
    update_option('mandala_plog_last', ['time' => time(), 'n' => $n], false);
    return $n;
}
/** Egyszer: az elmúlt 30 nap bolti rendeléseiből a rendesnél alacsonyabb egységárak (kupon előtt, ajándék és csomag nélkül). */
function mandala_price_log_backfill(): int
{
    update_option('mandala_plog_backfill', time(), false);
    if (!function_exists('wc_get_orders')) {
        return 0;
    }
    $n = 0;
    $orders = wc_get_orders(['limit' => -1, 'date_created' => '>' . (time() - 30 * DAY_IN_SECONDS), 'status' => ['processing', 'completed', 'on-hold'], 'return' => 'objects']);
    foreach ($orders as $order) {
        $uid = (int) $order->get_customer_id();
        if ($uid && function_exists('mandala_is_wholesale_user') && mandala_is_wholesale_user($uid)) {
            continue; // nagyker ár – nem bolti ár
        }
        $day = $order->get_date_created() ? $order->get_date_created()->date_i18n('Y-m-d') : '';
        foreach ($day ? $order->get_items() : [] as $line) {
            if (!$line instanceof WC_Order_Item_Product || $line->get_quantity() <= 0) {
                continue;
            }
            $skip = false;
            foreach ($line->get_meta_data() as $m) {
                $skip = $skip || in_array($m->key, [__('Ajándék', 'mandala'), __('Csomagkedvezmény', 'mandala'), __('1+1 (egyet fizetsz, kettőt viszel)', 'mandala')], true);
            }
            $id = (int) ($line->get_variation_id() ?: $line->get_product_id());
            $unit = ((float) $line->get_subtotal() + (float) $line->get_subtotal_tax()) / $line->get_quantity();
            $p = $skip || !$id ? null : wc_get_product($id);
            if ($p && $unit > 0 && $unit < (float) $p->get_regular_price('edit') - 0.5) {
                mandala_price_log_add($id, $day, round($unit, 2));
                $n++;
            }
        }
    }
    update_option('mandala_plog_backfill', ['time' => time(), 'n' => $n], false);
    return $n;
}

/* ---------- A kampány határain: feed és index újraépítése ---------- */

/** A kampányok kezdete és vége után 1 perccel a feedek újraépülnek (a Google / Meta katalógus a kampányárat lássa). */
function mandala_promo_schedule_boundaries(): void
{
    if (!function_exists('as_schedule_single_action')) {
        return;
    }
    $tz = wp_timezone_string();
    foreach (mandala_campaigns() as $c) {
        if (empty($c['rules']) || ($c['enabled'] ?? 'yes') !== 'yes') {
            continue;
        }
        foreach (['start', 'end'] as $f) {
            $t = (int) strtotime($c[$f] . ' ' . $tz) + MINUTE_IN_SECONDS;
            if ($t > time() && !as_has_scheduled_action('mandala_promo_boundary', [$t], 'mandala')) {
                as_schedule_single_action($t, 'mandala_promo_boundary', [$t], 'mandala');
            }
        }
    }
}
add_action('update_option_mandala_campaigns', 'mandala_promo_schedule_boundaries');
add_action('add_option_mandala_campaigns', 'mandala_promo_schedule_boundaries');
add_action('mandala_promo_boundary', function () {
    if (function_exists('mandala_price_log_run')) {
        mandala_price_log_run(); // a kampányár is bekerül a mai napra
    }
    if (function_exists('mandala_flush_index')) {
        mandala_flush_index(); // a következő százalékos kampányhoz mért jogosultság (pok)
    }
    if (function_exists('mandala_feeds_build')) {
        mandala_feeds_build();
    }
});

/* ---------- Áttekintés az adminban (WooCommerce → Kampányok, lent) ---------- */

/** Hány termék kap százalékot egy kampány szabályaival, és miért marad ki a többi. */
function mandala_promo_overview(array $rules, int $start = 0): array
{
    $r = mandala_promo_clean_rules($rules);
    $merged = ['bogo' => $r['bogo'], 'pct' => $r['pct'], 'pct_cats' => $r['pct_cats'], 'start' => $start];
    $out = ['pct' => [], 'bogo' => 0, 'sale' => 0, 'low30' => 0, 'other' => 0, 'none' => 0, 'total' => 0];
    foreach (mandala_product_index() as $row) {
        $p = wc_get_product((int) $row['id']);
        if (!$p || !$p->is_type('simple')) {
            continue;
        }
        $out['total']++;
        if (mandala_promo_in_bogo($p, $merged)) {
            $out['bogo']++;
            continue;
        }
        $pct = mandala_promo_pct($p, $merged);
        if ($pct > 0) {
            $k = (string) (int) $pct;
            $out['pct'][$k] = ($out['pct'][$k] ?? 0) + 1;
        } elseif ($r['pct'] > 0 || $r['pct_cats']) {
            if ($p->is_on_sale('edit') && $p->get_sale_price('edit') !== '') {
                $out['sale']++;
            } elseif (mandala_promo_basic_ok($p) && !mandala_promo_is_giftbox($p) && !mandala_promo_pct_ok($p, $start)) {
                $out['low30']++;
            } elseif (!mandala_promo_basic_ok($p) || mandala_promo_is_giftbox($p)) {
                $out['other']++;
            } else {
                $out['none']++; // kivétel-kategória 0%-kal
            }
        }
    }
    return $out;
}
add_action('mandala_campaigns_admin_after', function (array $all) {
    $rows = '';
    foreach ($all as $c) {
        $r = mandala_promo_clean_rules($c['rules'] ?? []);
        if (!mandala_promo_has_rules($r)) {
            continue;
        }
        $o = ($r['pct'] > 0 || $r['pct_cats'] || $r['bogo']) ? mandala_promo_overview($r, (int) strtotime($c['start'] . ' ' . wp_timezone_string())) : null;
        $parts = [];
        if ($r['bogo']) {
            $parts[] = '1+1: ' . esc_html(implode(', ', $r['bogo'])) . ($o ? ' – ' . (int) $o['bogo'] . ' termék' : '');
        }
        if ($o && ($r['pct'] > 0 || $r['pct_cats'])) {
            $pcts = [];
            foreach ($o['pct'] as $k => $v) {
                $pcts[] = (int) $v . ' termék −' . esc_html($k) . '%';
            }
            $parts[] = 'Százalék: ' . implode(', ', $pcts) . '. Kimarad: ' . (int) $o['sale'] . ' már akciós, ' . (int) $o['low30'] . ' az elmúlt 30 napban olcsóbb volt, ' . (int) $o['other'] . ' utalvány / csomagolás / jegy';
        }
        if ($r['freeship'] === 'yes') {
            $parts[] = 'Ingyenes szállítás';
        }
        if ($r['wrap'] === 'yes') {
            $parts[] = 'Díszcsomagolás és kártya ajándékba';
        }
        if ($r['gwp']['min'] > 0 && $r['gwp']['sku'] !== '') {
            $g = function_exists('wc_get_product_id_by_sku') ? wc_get_product(wc_get_product_id_by_sku($r['gwp']['sku'])) : null;
            $parts[] = 'Ajándék ' . esc_html(mandala_num($r['gwp']['min'])) . ' Ft felett: ' . ($g ? esc_html($g->get_name()) . ' (' . ($g->is_in_stock() ? 'raktáron: ' . (int) $g->get_stock_quantity() : '<strong>elfogyott</strong>') . ')' : '<strong>nincs ilyen cikkszám: ' . esc_html($r['gwp']['sku']) . '</strong>');
        }
        $rows .= '<tr><td><strong>' . esc_html($c['name']) . '</strong><br><span class="description">' . esc_html($c['start'] . ' → ' . $c['end']) . '</span></td><td>' . implode('<br>', $parts) . '</td></tr>';
    }
    if ($rows === '') {
        return;
    }
    $log = (array) get_option('mandala_plog_last', []);
    $bf = get_option('mandala_plog_backfill');
    echo '<h2>Kampány-ajánlatok</h2><p class="description" style="max-width:900px">A kampány idejére maguktól élnek, viszonteladónak nem járnak, kuponnal nem adódnak össze. A százalék nem jár a már akciós termékre, és arra sem, amelyik az elmúlt 30 napban olcsóbb volt (árcsökkentési szabály). Ár-napló: ' . ($log ? esc_html(wp_date('Y-m-d H:i', (int) $log['time'])) . ', ' . (int) $log['n'] . ' termék' : 'még nem futott') . ($bf ? ' · a korábbi rendelésekből pótolva' : '') . '.</p>'
        . '<table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:240px">Kampány</th><th>Ajánlat</th></tr></thead><tbody>' . $rows . '</tbody></table>'; // phpcs:ignore
});
