<?php
/**
 * Előfizetés (pl. havi füstölő): a fogyóeszközöknél a vásárló választhat „Egyszeri vásárlás” vagy
 * „Előfizetés havonta / kéthavonta, X% kedvezménnyel” között. Fizetős bővítmény nélkül:
 *
 *  - Az első (normál) rendelés kifizetése / teljesítése után létrejön az előfizetés (a rendelés
 *    előfizetéses tételeivel, címével, szállítási és fizetési módjával).
 *  - 3 nappal az esedékesség előtt emlékeztető: kihagyás, szüneteltetés, lemondás egy kattintással.
 *  - Esedékességkor magától készül az új rendelés (kedvezményes áron, a szállítás a szokásos szabályok
 *    szerint). Utánvétnél azonnal feldolgozható; átutalásnál / kártyánál fizetési linket küldünk.
 *  - Elfogyott termék kimarad (megjegyzéssel); ha semmi nem kapható, a kör kimarad és szólunk.
 *  - Kezelés: Fiókom → Előfizetéseim (belépve), a levelek aláírt linkjei (vendégként is), admin: WooCommerce → Előfizetések.
 *
 * Előfizethető: a beállított kategóriák termékei (alapból füstölők, illóolajok, teák), vagy a terméknél
 * külön bekapcsolva. Beállítás: WooCommerce → Előfizetések → Beállítások.
 */

defined('ABSPATH') || exit;

const MANDALA_SUB_CPT = 'mandala_sub';

function mandala_sub_settings(): array
{
    return wp_parse_args((array) get_option('mandala_subs', []), [
        'enabled' => 'yes', 'percent' => 10, 'intervals' => '1,2', 'cats' => 'fustolok,illoolajok,teak', 'remind_days' => 3,
    ]);
}
function mandala_sub_intervals(): array
{
    return array_values(array_filter(array_map('intval', explode(',', (string) mandala_sub_settings()['intervals'])), fn($m) => $m >= 1 && $m <= 6));
}
function mandala_sub_interval_label(int $m): string
{
    return $m === 1 ? __('havonta', 'mandala') : sprintf(__('%d havonta', 'mandala'), $m);
}
function mandala_sub_available(WC_Product $p): bool
{
    $s = mandala_sub_settings();
    if ($s['enabled'] !== 'yes' || !$p->is_type('simple') || (function_exists('mandala_is_voucher') && mandala_is_voucher($p))) {
        return false;
    }
    $flag = $p->get_meta('_mandala_sub');
    if ($flag === 'yes' || $flag === 'no') {
        return $flag === 'yes';
    }
    $cats = array_filter(array_map('trim', explode(',', (string) $s['cats'])));
    return $cats && has_term($cats, 'product_cat', $p->get_id());
}

add_action('init', function () {
    register_post_type(MANDALA_SUB_CPT, ['public' => false, 'show_ui' => false, 'label' => 'Előfizetések', 'supports' => ['title']]);
});

/* ---------- Termék szerkesztő: előfizethető? ---------- */

add_action('woocommerce_product_options_general_product_data', function () {
    woocommerce_wp_select(['id' => '_mandala_sub', 'label' => 'Előfizetés', 'options' => ['' => 'a kategória szerint', 'yes' => 'előfizethető', 'no' => 'nem előfizethető'],
        'desc_tip' => true, 'description' => 'Alapból a WooCommerce → Előfizetések beállításban megadott kategóriák termékei előfizethetők.']);
});
add_action('woocommerce_admin_process_product_object', function ($product) {
    if (isset($_POST['_mandala_sub'])) { // phpcs:ignore
        $v = sanitize_key(wp_unslash($_POST['_mandala_sub'])); // phpcs:ignore
        in_array($v, ['yes', 'no'], true) ? $product->update_meta_data('_mandala_sub', $v) : $product->delete_meta_data('_mandala_sub');
    }
});

/* ---------- Termékoldal: egyszeri / előfizetés ---------- */

add_filter('mandala_cart_form_native', fn($native, $product) => $native || mandala_sub_available($product), 10, 2);
add_action('mandala_cart_form_fields', function (WC_Product $product) {
    if (!mandala_sub_available($product)) {
        return;
    }
    $s = mandala_sub_settings();
    $price = (float) wc_get_price_to_display($product);
    $sub_price = round($price * (1 - (float) $s['percent'] / 100));
    echo '<fieldset class="sub-choice"><legend class="sr-only">' . esc_html__('Vásárlás módja', 'mandala') . '</legend>'
        . '<label class="sub-opt"><input type="radio" name="mandala_sub" value="0" checked><span><strong>' . esc_html__('Egyszeri vásárlás', 'mandala') . '</strong><small>' . esc_html(mandala_fmt($price)) . '</small></span></label>'
        . '<label class="sub-opt"><input type="radio" name="mandala_sub" value="' . (int) (mandala_sub_intervals()[0] ?? 1) . '"><span><strong>' . esc_html(sprintf(__('Előfizetés −%d%%', 'mandala'), (int) $s['percent'])) . '</strong><small>' . esc_html(mandala_fmt($sub_price)) . ' / ' . esc_html__('szállítás', 'mandala') . '</small></span></label>';
    if (count(mandala_sub_intervals()) > 1) {
        echo '<div class="sub-interval"><label for="mandala-sub-int">' . esc_html__('Milyen gyakran?', 'mandala') . '</label><select id="mandala-sub-int" name="mandala_sub_interval">';
        foreach (mandala_sub_intervals() as $m) {
            echo '<option value="' . $m . '">' . esc_html(mandala_sub_interval_label($m)) . '</option>';
        }
        echo '</select></div>';
    }
    echo '<p class="sub-note text-small">' . esc_html__('Bármikor kihagyhatod, szüneteltetheted vagy lemondhatod – minden szállítás előtt 3 nappal emlékeztetünk.', 'mandala') . '</p></fieldset>';
});
add_filter('woocommerce_add_cart_item_data', function ($data, $product_id) {
    $m = absint($_POST['mandala_sub'] ?? 0); // phpcs:ignore
    if ($m) {
        $int = absint($_POST['mandala_sub_interval'] ?? $m); // phpcs:ignore
        $p = wc_get_product($product_id);
        if ($p && mandala_sub_available($p) && in_array($int, mandala_sub_intervals(), true)) {
            $data['mandala_sub'] = $int;
        }
    }
    return $data;
}, 10, 2);
add_action('woocommerce_before_calculate_totals', function ($cart) {
    $pct = (float) mandala_sub_settings()['percent'];
    foreach ($cart->get_cart() as $item) {
        if (!empty($item['mandala_sub'])) {
            $item['data']->set_price(round((float) wc_get_product($item['product_id'])->get_price() * (1 - $pct / 100), 2));
        }
    }
}, 25);
add_filter('woocommerce_get_item_data', function ($data, $item) {
    if (!empty($item['mandala_sub'])) {
        $data[] = ['key' => __('Előfizetés', 'mandala'), 'value' => sprintf(__('%1$s, −%2$d%%', 'mandala'), mandala_sub_interval_label((int) $item['mandala_sub']), (int) mandala_sub_settings()['percent'])];
    }
    return $data;
}, 10, 2);
add_action('woocommerce_checkout_create_order_line_item', function ($line, $key, $values) {
    if (!empty($values['mandala_sub'])) {
        $line->add_meta_data('_mandala_sub', (int) $values['mandala_sub']);
        $line->add_meta_data(__('Előfizetés', 'mandala'), mandala_sub_interval_label((int) $values['mandala_sub']));
    }
}, 10, 3);

/* ---------- Az előfizetés létrejötte (első rendelés után) ---------- */

function mandala_sub_create_from_order(WC_Order $order): array
{
    if ($order->get_meta('_mandala_sub_created') || $order->get_meta('_mandala_sub_id')) {
        return [];
    }
    $groups = [];
    foreach ($order->get_items() as $item) {
        $m = (int) $item->get_meta('_mandala_sub');
        if ($m) {
            $groups[$m][] = [(int) $item->get_product_id(), (int) $item->get_quantity()];
        }
    }
    $made = [];
    foreach ($groups as $m => $items) {
        $id = wp_insert_post(['post_type' => MANDALA_SUB_CPT, 'post_status' => 'publish', 'post_title' => $order->get_billing_email() . ' – ' . mandala_sub_interval_label($m)]);
        $next = (new DateTime('today', wp_timezone()))->modify('+' . $m . ' month')->format('Y-m-d');
        foreach (['email' => strtolower($order->get_billing_email()), 'user' => (int) $order->get_customer_id(), 'items' => $items, 'interval' => $m, 'next' => $next,
                  'status' => 'active', 'order' => $order->get_id(), 'orders' => [$order->get_id()]] as $k => $v) {
            update_post_meta($id, '_' . $k, $v);
        }
        $made[] = $id;
        $order->add_order_note(sprintf('Előfizetés létrehozva (#%d, %s, következő: %s).', $id, mandala_sub_interval_label($m), $next));
    }
    if ($made) {
        $order->update_meta_data('_mandala_sub_created', implode(',', $made));
        $order->save();
    }
    return $made;
}
foreach (['woocommerce_order_status_processing', 'woocommerce_order_status_completed'] as $hook) {
    add_action($hook, function ($order_id) {
        $order = wc_get_order($order_id);
        if ($order) {
            mandala_sub_create_from_order($order);
        }
    }, 20);
}

function mandala_sub_meta(int $id, string $k)
{
    return get_post_meta($id, '_' . $k, true);
}
function mandala_sub_link(int $id, string $do): string
{
    return add_query_arg(['mandala_sub' => $id, 'do' => $do, 'k' => mandala_token('sub', (string) $id, $do)], home_url('/'));
}
/** A tételek szöveges listája. */
function mandala_sub_items_text(int $id): string
{
    return implode(', ', array_map(fn($it) => ($it[1] > 1 ? $it[1] . ' × ' : '') . get_the_title((int) $it[0]), (array) mandala_sub_meta($id, 'items')));
}

/* ---------- Napi futás: emlékeztető és megújítás ---------- */

mandala_recurring('mandala_sub_daily', DAY_IN_SECONDS, function () {
    $t = new DateTime('tomorrow 07:30', wp_timezone());
    return $t->getTimestamp();
});
add_action('mandala_sub_daily', 'mandala_sub_daily');

/** Visszaad: [emlékeztetők, új rendelések]. $today: 'Y-m-d' (tesztekhez). */
function mandala_sub_daily(string $today = ''): array
{
    $today = $today ?: (new DateTime('today', wp_timezone()))->format('Y-m-d');
    $remind_on = (new DateTime($today, wp_timezone()))->modify('+' . (int) mandala_sub_settings()['remind_days'] . ' day')->format('Y-m-d');
    $reminded = $renewed = 0;
    foreach (get_posts(['post_type' => MANDALA_SUB_CPT, 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_status', 'meta_value' => 'active']) as $id) {
        $next = (string) mandala_sub_meta($id, 'next');
        if ($next === $remind_on && mandala_sub_meta($id, 'reminded') !== $next) {
            update_post_meta($id, '_reminded', $next);
            $reminded += (int) mandala_mail('sub_upcoming', (string) mandala_sub_meta($id, 'email'), ['datum' => wp_date('Y. m. d.', strtotime($next)), 'termekek_szoveg' => mandala_sub_items_text($id)], mandala_sub_mail_links($id));
        }
        if ($next !== '' && $next <= $today) {
            $renewed += mandala_sub_renew($id) ? 1 : 0;
        }
    }
    return [$reminded, $renewed];
}
function mandala_sub_mail_links(int $id): array
{
    $btn = fn($do, $label) => '<a href="' . esc_url(mandala_sub_link($id, $do)) . '" style="display:inline-block;margin:4px 8px 4px 0;padding:10px 16px;border:1px solid #DDD3C3;border-radius:999px;color:#1C1916;text-decoration:none">' . esc_html($label) . '</a>';
    return ['kezeles' => '<p>' . $btn('skip', __('Ezt most kihagyom', 'mandala')) . $btn('pause', __('Szüneteltetem', 'mandala')) . $btn('cancel', __('Lemondom', 'mandala')) . '</p>'];
}

/** Egy kör: új rendelés az előfizetésből. */
function mandala_sub_renew(int $id): ?WC_Order
{
    $src = wc_get_order((int) mandala_sub_meta($id, 'order'));
    $m = max(1, (int) mandala_sub_meta($id, 'interval'));
    $next = (new DateTime((string) mandala_sub_meta($id, 'next'), wp_timezone()))->modify('+' . $m . ' month')->format('Y-m-d');
    update_post_meta($id, '_next', $next); // akkor is lépünk, ha most nem sikerül – nincs végtelen próbálkozás
    if (!$src) {
        return null;
    }
    $pct = (float) mandala_sub_settings()['percent'];
    $order = wc_create_order(['customer_id' => (int) mandala_sub_meta($id, 'user'), 'created_via' => 'mandala_sub']);
    $missing = [];
    $goods = 0.0;
    foreach ((array) mandala_sub_meta($id, 'items') as [$pid, $qty]) {
        $p = wc_get_product((int) $pid);
        if (!$p || $p->get_status() !== 'publish' || !$p->is_in_stock()) {
            $missing[] = get_the_title((int) $pid);
            continue;
        }
        $unit = round((float) $p->get_price() * (1 - $pct / 100), 2);
        // A kedvezményes ár nettója a tétel sorába (a WooCommerce ebből számolja az ÁFÁ-t és a végösszeget).
        $ex = wc_get_price_excluding_tax($p, ['qty' => (int) $qty, 'price' => $unit]);
        $line_id = $order->add_product($p, (int) $qty, ['subtotal' => $ex, 'total' => $ex]);
        wc_add_order_item_meta($line_id, __('Előfizetés', 'mandala'), mandala_sub_interval_label($m));
        $goods += $unit * (int) $qty;
    }
    if (!$order->get_items()) {
        $order->delete(true);
        mandala_mail('sub_skipped', (string) mandala_sub_meta($id, 'email'), ['datum' => wp_date('Y. m. d.', strtotime($next)), 'termekek_szoveg' => implode(', ', $missing)]);
        return null;
    }
    $order->set_address($src->get_address('billing'), 'billing');
    $order->set_address($src->get_address('shipping'), 'shipping');
    foreach ($src->get_shipping_methods() as $ship) {
        $new = new WC_Order_Item_Shipping();
        $new->set_method_id($ship->get_method_id());
        $new->set_instance_id($ship->get_instance_id());
        $new->set_method_title($ship->get_method_title());
        $free = (float) mandala_config('freeShippingFrom', 25000);
        $new->set_total($free > 0 && $goods >= $free ? 0 : (float) $ship->get_total());
        $order->add_item($new);
        break;
    }
    $order->set_payment_method($src->get_payment_method());
    $order->set_payment_method_title($src->get_payment_method_title());
    $order->update_meta_data('_mandala_sub_id', $id);
    if ($missing) {
        $order->add_order_note('Előfizetés: most nem kapható, kimaradt: ' . implode(', ', $missing));
    }
    $order->calculate_totals();
    $status = ['cod' => 'processing', 'bacs' => 'on-hold'][$src->get_payment_method()] ?? 'pending';
    $order->update_status($status, sprintf('Előfizetés #%d – automatikus rendelés.', $id));
    $orders = (array) mandala_sub_meta($id, 'orders');
    $orders[] = $order->get_id();
    update_post_meta($id, '_orders', $orders);
    $pay = $status === 'pending' ? mandala_mail_button($order->get_checkout_payment_url(), __('Kifizetem', 'mandala')) : '';
    mandala_mail('sub_renewal', (string) mandala_sub_meta($id, 'email'), ['rendeles' => $order->get_order_number(), 'osszeg' => wp_strip_all_tags(wc_price($order->get_total())), 'datum' => wp_date('Y. m. d.', strtotime($next)),
        'termekek_szoveg' => mandala_sub_items_text($id)], ['fizetes' => $pay] + mandala_sub_mail_links($id), $order->get_id());
    return $order;
}

/* ---------- Kezelés: aláírt link (vendég is) ---------- */

function mandala_sub_apply(int $id, string $do): string
{
    $m = max(1, (int) mandala_sub_meta($id, 'interval'));
    switch ($do) {
        case 'skip':
            $next = (new DateTime((string) mandala_sub_meta($id, 'next'), wp_timezone()))->modify('+' . $m . ' month')->format('Y-m-d');
            update_post_meta($id, '_next', $next);
            return sprintf(__('Rendben, ezt a kört kihagyjuk. A következő szállítás: %s.', 'mandala'), wp_date('Y. m. d.', strtotime($next)));
        case 'pause':
            update_post_meta($id, '_status', 'paused');
            return __('Az előfizetésed szünetel. Bármikor újraindíthatod.', 'mandala');
        case 'resume':
            update_post_meta($id, '_status', 'active');
            $next = (new DateTime('today', wp_timezone()))->modify('+' . $m . ' month')->format('Y-m-d');
            if ((string) mandala_sub_meta($id, 'next') < (new DateTime('today', wp_timezone()))->format('Y-m-d')) {
                update_post_meta($id, '_next', $next);
            }
            return sprintf(__('Újraindítottuk. A következő szállítás: %s.', 'mandala'), wp_date('Y. m. d.', strtotime((string) mandala_sub_meta($id, 'next'))));
        case 'cancel':
            update_post_meta($id, '_status', 'cancelled');
            return __('Az előfizetést lemondtuk. Köszönjük, hogy velünk voltál!', 'mandala');
    }
    return '';
}
add_action('template_redirect', function () {
    if (empty($_GET['mandala_sub']) || empty($_GET['do'])) { // phpcs:ignore
        return;
    }
    $id = absint($_GET['mandala_sub']); // phpcs:ignore
    $do = sanitize_key(wp_unslash($_GET['do'])); // phpcs:ignore
    $ok = get_post_type($id) === MANDALA_SUB_CPT && (hash_equals(mandala_token('sub', (string) $id, $do), (string) wp_unslash($_GET['k'] ?? '')) // phpcs:ignore
        || (is_user_logged_in() && strtolower(wp_get_current_user()->user_email) === mandala_sub_meta($id, 'email') && wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'mandala_sub_' . $id))); // phpcs:ignore
    if (!$ok) {
        wp_die(esc_html__('Érvénytelen vagy lejárt link.', 'mandala'), '', ['response' => 403]);
    }
    $msg = mandala_sub_apply($id, $do);
    if (function_exists('wc_add_notice') && WC()->session) {
        wc_add_notice($msg);
    }
    wp_safe_redirect(is_user_logged_in() ? wc_get_account_endpoint_url('elofizetesek') : add_query_arg('mandala_sub_msg', rawurlencode($msg), home_url('/')));
    exit;
});
add_action('wp_footer', function () {
    if (!empty($_GET['mandala_sub_msg'])) { // phpcs:ignore
        echo '<div class="toast is-visible" role="status">' . esc_html(sanitize_text_field(wp_unslash($_GET['mandala_sub_msg']))) . '</div>'; // phpcs:ignore
    }
});

/* ---------- Fiókom → Előfizetéseim ---------- */

add_filter('woocommerce_get_query_vars', fn($vars) => $vars + ['elofizetesek' => 'elofizetesek']);
add_action('init', function () {
    if (get_option('mandala_rewrite_subs') !== '1') {
        add_action('wp_loaded', fn() => flush_rewrite_rules(false));
        update_option('mandala_rewrite_subs', '1');
    }
}, 99);
function mandala_user_subs(string $email): array
{
    return get_posts(['post_type' => MANDALA_SUB_CPT, 'numberposts' => 50, 'fields' => 'ids', 'meta_key' => '_email', 'meta_value' => strtolower($email)]);
}
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!mandala_user_subs(wp_get_current_user()->user_email)) {
        return $items;
    }
    $new = [];
    foreach ($items as $k => $v) {
        $new[$k] = $v;
        if ($k === 'orders') {
            $new['elofizetesek'] = __('Előfizetéseim', 'mandala');
        }
    }
    return $new;
});
add_filter('woocommerce_endpoint_elofizetesek_title', fn() => __('Előfizetéseim', 'mandala'));
add_action('woocommerce_account_elofizetesek_endpoint', function () {
    $ids = mandala_user_subs(wp_get_current_user()->user_email);
    if (!$ids) {
        echo '<p>' . esc_html__('Nincs előfizetésed. A füstölők, illóolajok és teák oldalán választhatod az előfizetést kedvezménnyel.', 'mandala') . '</p>';
        return;
    }
    $labels = ['active' => __('aktív', 'mandala'), 'paused' => __('szünetel', 'mandala'), 'cancelled' => __('lemondva', 'mandala')];
    echo '<table class="shop_table sub-table"><thead><tr><th>' . esc_html__('Termékek', 'mandala') . '</th><th>' . esc_html__('Gyakoriság', 'mandala') . '</th><th>' . esc_html__('Következő', 'mandala') . '</th><th>' . esc_html__('Állapot', 'mandala') . '</th><th></th></tr></thead><tbody>';
    foreach ($ids as $id) {
        $st = (string) mandala_sub_meta($id, 'status');
        $a = fn($do, $label) => '<a class="iu-button iu-button-outline iu-button-small" href="' . esc_url(wp_nonce_url(add_query_arg(['mandala_sub' => $id, 'do' => $do], home_url('/')), 'mandala_sub_' . $id)) . '">' . esc_html($label) . '</a> ';
        $actions = $st === 'active' ? $a('skip', __('Kihagyom a következőt', 'mandala')) . $a('pause', __('Szüneteltetem', 'mandala')) . $a('cancel', __('Lemondom', 'mandala'))
            : ($st === 'paused' ? $a('resume', __('Újraindítom', 'mandala')) . $a('cancel', __('Lemondom', 'mandala')) : '');
        echo '<tr><td>' . esc_html(mandala_sub_items_text($id)) . '</td><td>' . esc_html(mandala_sub_interval_label((int) mandala_sub_meta($id, 'interval'))) . '</td><td>'
            . esc_html($st === 'active' ? wp_date('Y. m. d.', strtotime((string) mandala_sub_meta($id, 'next'))) : '–') . '</td><td>' . esc_html($labels[$st] ?? $st) . '</td><td class="sub-actions">' . $actions . '</td></tr>'; // phpcs:ignore
    }
    echo '</tbody></table>';
});

/* ---------- Admin: WooCommerce → Előfizetések ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Előfizetések', 'Előfizetések', 'manage_woocommerce', 'mandala-subs', function () {
        $s = mandala_sub_settings();
        if (!empty($_POST['mandala_subs']) && check_admin_referer('mandala_subs')) {
            $in = (array) wp_unslash($_POST['mandala_subs']);
            $s['enabled'] = empty($in['enabled']) ? 'no' : 'yes';
            $s['percent'] = max(0, min(50, (int) ($in['percent'] ?? 10)));
            $s['intervals'] = implode(',', array_filter(array_map('intval', explode(',', (string) ($in['intervals'] ?? '1,2'))), fn($m) => $m >= 1 && $m <= 6)) ?: '1';
            $s['cats'] = sanitize_text_field((string) ($in['cats'] ?? ''));
            $s['remind_days'] = max(1, min(14, (int) ($in['remind_days'] ?? 3)));
            update_option('mandala_subs', $s, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        if (!empty($_GET['sub_do']) && !empty($_GET['sub']) && check_admin_referer('mandala_sub_admin')) {
            echo '<div class="notice notice-success"><p>' . esc_html(mandala_sub_apply(absint($_GET['sub']), sanitize_key(wp_unslash($_GET['sub_do'])))) . '</p></div>';
        }
        $labels = ['active' => 'aktív', 'paused' => 'szünetel', 'cancelled' => 'lemondva'];
        $ids = get_posts(['post_type' => MANDALA_SUB_CPT, 'numberposts' => 200, 'fields' => 'ids']);
        $count = array_count_values(array_map(fn($id) => (string) mandala_sub_meta($id, 'status'), $ids));
        echo '<div class="wrap"><h1>Előfizetések</h1><p>Aktív: <strong>' . (int) ($count['active'] ?? 0) . '</strong> · szünetel: ' . (int) ($count['paused'] ?? 0) . ' · lemondva: ' . (int) ($count['cancelled'] ?? 0) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>#</th><th>Vásárló</th><th>Termékek</th><th>Gyakoriság</th><th>Következő</th><th>Állapot</th><th>Rendelések</th><th></th></tr></thead><tbody>';
        foreach ($ids as $id) {
            $st = (string) mandala_sub_meta($id, 'status');
            $act = fn($do, $l) => '<a href="' . esc_url(wp_nonce_url(add_query_arg(['sub' => $id, 'sub_do' => $do]), 'mandala_sub_admin')) . '">' . $l . '</a>';
            $orders = implode(', ', array_map(fn($o) => '<a href="' . esc_url(admin_url('post.php?post=' . (int) $o . '&action=edit')) . '">#' . (int) $o . '</a>', (array) mandala_sub_meta($id, 'orders')));
            echo '<tr><td>' . (int) $id . '</td><td>' . esc_html((string) mandala_sub_meta($id, 'email')) . '</td><td>' . esc_html(mandala_sub_items_text($id)) . '</td><td>' . esc_html(mandala_sub_interval_label((int) mandala_sub_meta($id, 'interval'))) . '</td>'
                . '<td>' . esc_html((string) mandala_sub_meta($id, 'next')) . '</td><td>' . esc_html($labels[$st] ?? $st) . '</td><td>' . $orders . '</td><td>'
                . ($st === 'active' ? $act('skip', 'kihagy') . ' · ' . $act('pause', 'szüneteltet') . ' · ' . $act('cancel', 'lemond') : ($st === 'paused' ? $act('resume', 'újraindít') . ' · ' . $act('cancel', 'lemond') : '')) . '</td></tr>';
        }
        if (!$ids) {
            echo '<tr><td colspan="8">Még nincs előfizetés.</td></tr>';
        }
        echo '</tbody></table><h2>Beállítások</h2><form method="post">';
        wp_nonce_field('mandala_subs');
        echo '<table class="form-table"><tr><th>Előfizetés</th><td><label><input type="checkbox" name="mandala_subs[enabled]" value="1"' . checked($s['enabled'], 'yes', false) . '> bekapcsolva</label></td></tr>'
            . '<tr><th>Kedvezmény</th><td><input type="number" name="mandala_subs[percent]" value="' . (int) $s['percent'] . '" min="0" max="50" style="width:70px"> %</td></tr>'
            . '<tr><th>Gyakoriság (hónap)</th><td><input type="text" name="mandala_subs[intervals]" value="' . esc_attr($s['intervals']) . '" style="width:120px"><p class="description">Vesszővel, pl. „1,2” = havonta vagy kéthavonta.</p></td></tr>'
            . '<tr><th>Előfizethető kategóriák</th><td><input type="text" name="mandala_subs[cats]" value="' . esc_attr($s['cats']) . '" class="regular-text"><p class="description">Kategória slugok vesszővel. Terméknél külön is be/ki kapcsolható (Termékadatok → Általános).</p></td></tr>'
            . '<tr><th>Emlékeztető</th><td><input type="number" name="mandala_subs[remind_days]" value="' . (int) $s['remind_days'] . '" min="1" max="14" style="width:70px"> nappal a szállítás előtt</td></tr></table>';
        submit_button('Mentés');
        echo '</form><p class="description">Hogyan működik: az első rendelés feldolgozása után jön létre. Esedékességkor új rendelés készül: utánvétnél „Feldolgozás alatt”, átutalásnál „Fizetésre vár”, kártyánál fizetési linket küldünk. A levelek szövege: Mandala levelek → Előfizetés.</p></div>';
    });
});

/* ---------- Levélsablonok ---------- */

add_filter('mandala_mail_types', function ($types) {
    $common = ['datum' => 'A következő szállítás napja', 'termekek_szoveg' => 'A termékek'];
    $types['sub_upcoming'] = [
        'label' => 'Előfizetés – emlékeztető', 'group' => 'Előfizetés', 'required' => true,
        'when' => 'Néhány nappal (Előfizetések → Beállítások) az esedékes szállítás előtt. Mindig megy – ebben vannak a kihagyás / szüneteltetés / lemondás linkek.',
        'vars' => $common, 'blocks' => ['kezeles' => 'Kihagyás / szüneteltetés / lemondás gombok'],
        'subject' => __('Hamarosan érkezik a következő csomagod', 'mandala'), 'heading' => __('Úton a következő csomag', 'mandala'),
        'body' => '<p>' . __('{datum}-án összekészítjük az előfizetésed következő csomagját: {termekek_szoveg}.', 'mandala') . '</p><p>' . __('Ha most nem kérnéd, egy kattintás:', 'mandala') . '</p>{kezeles}',
        'sample' => fn() => [['datum' => '2026. 10. 28.', 'termekek_szoveg' => 'Nag Champa füstölő 15 g'], mandala_sub_sample_links()],
    ];
    $types['sub_renewal'] = [
        'label' => 'Előfizetés – új rendelés', 'group' => 'Előfizetés', 'required' => true,
        'when' => 'Amikor az előfizetésből elkészül az új rendelés. Kártyás fizetésnél a fizetési linkkel.',
        'vars' => $common + ['rendeles' => 'Rendelésszám', 'osszeg' => 'Végösszeg'], 'blocks' => ['fizetes' => '„Kifizetem” gomb (ha kell)', 'kezeles' => 'Kezelés gombok'],
        'subject' => __('Előfizetés: elkészült a #{rendeles} rendelésed', 'mandala'), 'heading' => __('Itt a következő csomagod', 'mandala'),
        'body' => '<p>' . __('Elkészült az előfizetésed új rendelése (#{rendeles}, {osszeg}): {termekek_szoveg}.', 'mandala') . '</p>{fizetes}<p>' . __('A következő szállítás: {datum}.', 'mandala') . '</p>{kezeles}',
        'sample' => fn() => [['rendeles' => '1234', 'osszeg' => '5 390 Ft', 'datum' => '2026. 11. 28.', 'termekek_szoveg' => 'Nag Champa füstölő 15 g'], ['fizetes' => mandala_mail_button(home_url('/'), __('Kifizetem', 'mandala'))] + mandala_sub_sample_links()],
    ];
    $types['sub_skipped'] = [
        'label' => 'Előfizetés – most nem kapható', 'group' => 'Előfizetés', 'required' => true,
        'when' => 'Ha esedékességkor az előfizetés egyik terméke sem kapható: ez a kör kimarad.',
        'vars' => $common, 'blocks' => [],
        'subject' => __('Ez a kör most kimarad', 'mandala'), 'heading' => __('Most nem tudtuk összekészíteni', 'mandala'),
        'body' => '<p>' . __('Az előfizetésed termékei ({termekek_szoveg}) most nem kaphatók, ezért ez a kör kimarad – nem kell fizetned. A következő: {datum}.', 'mandala') . '</p>',
        'sample' => fn() => [['datum' => '2026. 11. 28.', 'termekek_szoveg' => 'Nag Champa füstölő 15 g'], []],
    ];
    return $types;
});
function mandala_sub_sample_links(): array
{
    $b = fn($l) => '<a href="#" style="display:inline-block;margin:4px 8px 4px 0;padding:10px 16px;border:1px solid #DDD3C3;border-radius:999px;color:#1C1916;text-decoration:none">' . esc_html($l) . '</a>';
    return ['kezeles' => '<p>' . $b(__('Ezt most kihagyom', 'mandala')) . $b(__('Szüneteltetem', 'mandala')) . $b(__('Lemondom', 'mandala')) . '</p>'];
}
