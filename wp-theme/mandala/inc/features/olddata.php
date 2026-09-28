<?php
/**
 * A régi bolt ADATAINAK átvétele (WooCommerce → Régi bolt adatai): vásárlók, rendelések jegyzetekkel és
 * visszatérítésekkel, kuponok, termékértékelések, blogbejegyzések képekkel, a hiányzó oldalak (tervezetként)
 * és a YITH ajándékkártyák (a téma ajándékutalványaiként).
 *
 * Menete: a régi bolt adminjában a böngészőkonzolba másolt szkript (olddata-export.js) letölt egy fájlt;
 * ezt az új boltban a böngésző olvassa be (a szerverre egyben nem kerül fel), és kis adagokban küldi át.
 * Így nincs feltöltési méretkorlát és időtúllépés, a fájl pedig nem marad a szerveren.
 *
 * Biztonsági szabályok az átvétel alatt: nem megy ki levél (egy vásárlónak sem), nem fut állapotváltás
 * (készlet, pontok, számla, Meta-esemény, automata levél), a rendelések a régi rendelésszámukat mutatják.
 * Újrafuttatható (a már átvett tételeket felismeri, a rendelések állapotát és a kártyák egyenlegét frissíti),
 * és minden átvett adat egy gombbal törölhető (a próbaátvételhez).
 *
 * A vásárlók jelszava a régi boltból nem hozható át (a WordPress nem adja ki): belépéskor a hibás jelszóra
 * a bolt elmagyarázza, hogy kérjenek újat (Elfelejtett jelszó).
 */

defined('ABSPATH') || exit;

const MANDALA_OD_OLD = '_mandala_old_id';     // a régi bolt azonosítója (minden átvett / megfeleltetett tételen)
const MANDALA_OD_FLAG = '_mandala_imported';  // az átvétel hozta létre (törölhető)
const MANDALA_OD_STAFF = ['administrator', 'shop_manager', 'editor', 'author', 'contributor'];

/* ---------- Régi rendelésszám, keresés, jelszó ---------- */

add_filter('woocommerce_order_number', function ($number, $order) {
    $old = $order instanceof WC_Order ? (string) $order->get_meta('_mandala_old_number') : '';
    return $old !== '' ? $old : $number;
}, 20, 2);
add_filter('woocommerce_shop_order_search_fields', fn($f) => array_merge((array) $f, ['_mandala_old_number']));
add_filter('woocommerce_order_table_search_query_meta_keys', fn($f) => array_merge((array) $f, ['_mandala_old_number']));

// Átköltöztetett vásárló hibás jelszóval: magyarázat + új jelszó kérése
add_filter('authenticate', function ($user, $username) {
    if (!is_wp_error($user) || $user->get_error_code() !== 'incorrect_password' || !$username) {
        return $user;
    }
    $u = is_email($username) ? get_user_by('email', $username) : get_user_by('login', $username);
    if ($u && get_user_meta($u->ID, '_mandala_needs_pw', true)) {
        $url = function_exists('wc_lostpassword_url') ? wc_lostpassword_url() : wp_lostpassword_url();
        return new WP_Error('incorrect_password', sprintf(__('Új webáruházba költöztünk: a fiókod és a korábbi rendeléseid megvannak, de a jelszót biztonsági okból nem hozhattuk át. <a href="%s">Kérj új jelszót</a> – egy perc az egész.', 'mandala'), esc_url($url)));
    }
    return $user;
}, 30, 2);
add_action('password_reset', fn($user) => delete_user_meta($user->ID, '_mandala_needs_pw'));
add_action('wp_login', function ($login, $user) {
    delete_user_meta($user->ID, '_mandala_needs_pw');
}, 10, 2);

/* ---------- Segédek ---------- */

function mandala_od_hpos(): bool
{
    return function_exists('mandala_owner_hpos') ? mandala_owner_hpos()
        : class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}
/** Régi azonosító → új, egy lekérdezéssel. $kind: order | refund | user | post (+ post_type) */
function mandala_od_map(string $kind, array $old_ids, string $post_type = ''): array
{
    global $wpdb;
    $old_ids = array_values(array_unique(array_filter(array_map('intval', $old_ids))));
    if (!$old_ids) {
        return [];
    }
    $in = implode(',', $old_ids);
    if ($kind === 'user') {
        $rows = $wpdb->get_results("SELECT user_id AS id, meta_value AS old FROM {$wpdb->usermeta} WHERE meta_key = '_mandala_old_user_id' AND meta_value IN ($in)"); // phpcs:ignore
    } elseif (in_array($kind, ['order', 'refund'], true) && mandala_od_hpos()) {
        $type = $kind === 'order' ? 'shop_order' : 'shop_order_refund';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.order_id AS id, m.meta_value AS old FROM {$wpdb->prefix}wc_orders_meta m JOIN {$wpdb->prefix}wc_orders o ON o.id = m.order_id WHERE o.type = %s AND m.meta_key = %s AND m.meta_value IN ($in)", $type, MANDALA_OD_OLD)); // phpcs:ignore
    } else {
        $type = $kind === 'order' ? 'shop_order' : ($kind === 'refund' ? 'shop_order_refund' : $post_type);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.post_id AS id, m.meta_value AS old FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s AND m.meta_key = %s AND m.meta_value IN ($in)", $type, MANDALA_OD_OLD)); // phpcs:ignore
    }
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r->old] = (int) $r->id;
    }
    return $map;
}
/** Termék a régi bolt cikkszáma, majd slugja alapján: [termék, variáció]. */
function mandala_od_product(array $ref): array
{
    static $cache = [];
    $key = ($ref['sku'] ?? '') . '|' . ($ref['slug'] ?? '');
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $id = !empty($ref['sku']) ? (int) wc_get_product_id_by_sku((string) $ref['sku']) : 0;
    if (!$id && !empty($ref['slug'])) {
        $p = get_page_by_path((string) $ref['slug'], OBJECT, 'product');
        $id = $p ? (int) $p->ID : 0;
    }
    if ($id && get_post_type($id) === 'product_variation') {
        return $cache[$key] = [(int) wp_get_post_parent_id($id), $id];
    }
    return $cache[$key] = [$id, 0];
}
function mandala_od_time($gmt): ?int
{
    return $gmt ? (strtotime($gmt . (preg_match('/Z|[+-]\d\d:?\d\d$/', $gmt) ? '' : ' UTC')) ?: null) : null;
}
function mandala_od_money($v): string
{
    return wc_format_decimal((string) ($v ?? 0));
}
/** Az átvétel alatt: nincs levél, nincs állapotváltási esemény (készlet, pontok, számla, automata levél). */
function mandala_od_quiet(): void
{
    add_filter('pre_wp_mail', '__return_false', 1);
    $statuses = array_merge(['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft'],
        array_map(fn($s) => substr($s, 3), array_keys(wc_get_order_statuses())));
    $statuses = array_unique($statuses);
    remove_all_actions('woocommerce_order_status_changed');
    remove_all_actions('woocommerce_payment_complete');
    foreach ($statuses as $a) {
        remove_all_actions('woocommerce_order_status_' . $a);
        foreach ($statuses as $b) {
            remove_all_actions('woocommerce_order_status_' . $a . '_to_' . $b);
        }
    }
    wc_set_time_limit(300);
    wp_raise_memory_limit('admin');
}

/* ---------- Átvétel: részenként ---------- */

function mandala_od_customers(array $items): array
{
    $res = ['created' => 0, 'matched' => 0, 'skipped' => 0, 'log' => []];
    $known = mandala_od_map('user', array_column($items, 'id'));
    $roles = wp_roles()->get_names();
    foreach ($items as $c) {
        $email = sanitize_email((string) ($c['email'] ?? ''));
        $role = (string) ($c['role'] ?? 'customer');
        if (isset($known[(int) $c['id']])) {
            $res['matched']++;
            continue;
        }
        if (!$email || in_array($role, MANDALA_OD_STAFF, true)) {
            $res['skipped']++;
            continue;
        }
        if ($user = get_user_by('email', $email)) {
            update_user_meta($user->ID, '_mandala_old_user_id', (int) $c['id']);
            $res['matched']++;
            continue;
        }
        $login = sanitize_user((string) ($c['username'] ?? ''), true) ?: $email;
        for ($i = 2, $base = $login; username_exists($login); $i++) {
            $login = $base . $i;
        }
        $id = wp_insert_user(['user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(32, true, true),
            'first_name' => (string) ($c['first_name'] ?? ''), 'last_name' => (string) ($c['last_name'] ?? ''),
            'display_name' => trim(($c['last_name'] ?? '') . ' ' . ($c['first_name'] ?? '')) ?: $login,
            'role' => isset($roles[$role]) ? $role : 'customer',
            'user_registered' => ($t = mandala_od_time($c['date_created_gmt'] ?? '')) ? gmdate('Y-m-d H:i:s', $t) : current_time('mysql', true)]);
        if (is_wp_error($id)) {
            $res['log'][] = $email . ': ' . $id->get_error_message();
            continue;
        }
        foreach (['billing', 'shipping'] as $type) {
            foreach ((array) ($c[$type] ?? []) as $k => $v) {
                if ((string) $v !== '') {
                    update_user_meta($id, $type . '_' . $k, (string) $v);
                }
            }
        }
        if (!isset($roles[$role])) {
            update_user_meta($id, '_mandala_old_role', $role);
        }
        if (!empty($c['is_paying_customer'])) {
            update_user_meta($id, 'paying_customer', 1);
        }
        update_user_meta($id, '_mandala_old_user_id', (int) $c['id']);
        update_user_meta($id, MANDALA_OD_FLAG, 1);
        update_user_meta($id, '_mandala_needs_pw', 1);
        $res['created']++;
    }
    return $res;
}

function mandala_od_orders(array $items): array
{
    global $wpdb;
    $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'log' => []];
    $known = mandala_od_map('order', array_column($items, 'id'));
    $users = mandala_od_map('user', array_column($items, 'customer_id'));
    $statuses = wc_get_order_statuses();
    $status_of = function (string $s) use ($statuses): string {
        $s = preg_replace('/^wc-/', '', $s);
        return isset($statuses['wc-' . $s]) ? $s : 'completed';
    };
    foreach ($items as $o) {
        try {
            $status = $status_of((string) ($o['status'] ?? 'completed'));
            if (isset($known[(int) $o['id']])) {
                // Újrafuttatás: állapot, dátumok, új jegyzetek
                $order = wc_get_order($known[(int) $o['id']]);
                if (!$order) {
                    continue;
                }
                $changed = false;
                if ($order->get_status() !== $status) {
                    $order->set_status($status, '(A régi boltban módosult.) ');
                    $changed = true;
                }
                foreach (['date_paid', 'date_completed'] as $d) {
                    if (($t = mandala_od_time($o[$d . '_gmt'] ?? '')) && !$order->{'get_' . $d}()) {
                        $order->{'set_' . $d}($t);
                        $changed = true;
                    }
                }
                if ($changed) {
                    $order->save();
                }
                $notes = mandala_od_notes($order->get_id(), (array) ($o['notes'] ?? []));
                $changed || $notes ? $res['updated']++ : $res['skipped']++;
                continue;
            }
            $order = new WC_Order();
            $props = ['currency' => $o['currency'] ?? get_woocommerce_currency(), 'prices_include_tax' => !empty($o['prices_include_tax']),
                'discount_total' => mandala_od_money($o['discount_total'] ?? 0), 'discount_tax' => mandala_od_money($o['discount_tax'] ?? 0),
                'shipping_total' => mandala_od_money($o['shipping_total'] ?? 0), 'shipping_tax' => mandala_od_money($o['shipping_tax'] ?? 0),
                'cart_tax' => mandala_od_money($o['cart_tax'] ?? 0), 'total' => mandala_od_money($o['total'] ?? 0),
                'customer_id' => $users[(int) ($o['customer_id'] ?? 0)] ?? 0, 'order_key' => (string) ($o['order_key'] ?? ''),
                'payment_method' => (string) ($o['payment_method'] ?? ''), 'payment_method_title' => (string) ($o['payment_method_title'] ?? ''),
                'transaction_id' => (string) ($o['transaction_id'] ?? ''), 'customer_ip_address' => (string) ($o['customer_ip_address'] ?? ''),
                'customer_user_agent' => (string) ($o['customer_user_agent'] ?? ''), 'created_via' => (string) ($o['created_via'] ?? 'checkout'),
                'customer_note' => (string) ($o['customer_note'] ?? ''), 'version' => (string) ($o['version'] ?? WC_VERSION)];
            foreach (['date_created', 'date_paid', 'date_completed'] as $d) {
                if ($t = mandala_od_time($o[$d . '_gmt'] ?? '')) {
                    $props[$d] = $t;
                }
            }
            foreach (['billing', 'shipping'] as $type) {
                foreach ((array) ($o[$type] ?? []) as $k => $v) {
                    $props[$type . '_' . $k] = (string) $v;
                }
            }
            if ($props['order_key'] && wc_get_order_id_by_order_key($props['order_key'])) {
                unset($props['order_key']);
            }
            $order->set_props($props);
            // Az állapot állapotváltás nélkül (se esemény, se „állapot módosult” jegyzet): közvetlenül az adatba
            $order->set_object_read(false);
            $order->set_status($status);
            $order->set_object_read(true);
            $taxes = fn($list, $sub = false) => array_filter([
                'total' => array_column(array_map(fn($t) => [(int) $t['id'], mandala_od_money($t['total'] ?? 0)], (array) $list), 1, 0),
                'subtotal' => $sub ? array_column(array_map(fn($t) => [(int) $t['id'], mandala_od_money($t['subtotal'] ?? 0)], (array) $list), 1, 0) : null,
            ], fn($v) => $v !== null);
            $meta = function (WC_Data $obj, array $list) {
                foreach ($list as $m) {
                    if (isset($m['key']) && !in_array($m['key'], ['_edit_lock', '_edit_last'], true)) {
                        $obj->add_meta_data((string) $m['key'], $m['value'] ?? '', false);
                    }
                }
            };
            foreach ((array) ($o['line_items'] ?? []) as $li) {
                [$pid, $vid] = mandala_od_product(['sku' => $li['sku'] ?? '']);
                $item = new WC_Order_Item_Product();
                $item->set_props(['name' => (string) $li['name'], 'quantity' => (float) $li['quantity'], 'tax_class' => (string) ($li['tax_class'] ?? ''),
                    'subtotal' => mandala_od_money($li['subtotal'] ?? 0), 'subtotal_tax' => mandala_od_money($li['subtotal_tax'] ?? 0),
                    'total' => mandala_od_money($li['total'] ?? 0), 'total_tax' => mandala_od_money($li['total_tax'] ?? 0), 'product_id' => $pid, 'variation_id' => $vid]);
                $item->set_taxes($taxes($li['taxes'] ?? [], true));
                $meta($item, (array) ($li['meta_data'] ?? []));
                if (!$pid && !empty($li['sku'])) {
                    $item->add_meta_data(__('Cikkszám', 'mandala'), (string) $li['sku'], true);
                }
                $order->add_item($item);
            }
            foreach ((array) ($o['shipping_lines'] ?? []) as $sl) {
                $item = new WC_Order_Item_Shipping();
                $item->set_props(['method_title' => (string) $sl['method_title'], 'method_id' => (string) $sl['method_id'], 'instance_id' => (string) ($sl['instance_id'] ?? ''),
                    'total' => mandala_od_money($sl['total'] ?? 0)]);
                $item->set_taxes($taxes($sl['taxes'] ?? []));
                $meta($item, (array) ($sl['meta_data'] ?? []));
                $order->add_item($item);
            }
            foreach ((array) ($o['fee_lines'] ?? []) as $fl) {
                $item = new WC_Order_Item_Fee();
                $item->set_props(['name' => (string) $fl['name'], 'tax_class' => (string) ($fl['tax_class'] ?? ''), 'tax_status' => (string) ($fl['tax_status'] ?? 'taxable'),
                    'total' => mandala_od_money($fl['total'] ?? 0)]);
                $item->set_taxes($taxes($fl['taxes'] ?? []));
                $meta($item, (array) ($fl['meta_data'] ?? []));
                $order->add_item($item);
            }
            foreach ((array) ($o['tax_lines'] ?? []) as $tl) {
                $item = new WC_Order_Item_Tax();
                $item->set_props(['rate_code' => (string) ($tl['rate_code'] ?? ''), 'rate_id' => (int) ($tl['rate_id'] ?? 0), 'label' => (string) ($tl['label'] ?? ''),
                    'compound' => !empty($tl['compound']), 'tax_total' => mandala_od_money($tl['tax_total'] ?? 0), 'shipping_tax_total' => mandala_od_money($tl['shipping_tax_total'] ?? 0),
                    'rate_percent' => (float) ($tl['rate_percent'] ?? 0)]);
                $order->add_item($item);
            }
            foreach ((array) ($o['coupon_lines'] ?? []) as $cl) {
                $item = new WC_Order_Item_Coupon();
                $item->set_props(['code' => (string) $cl['code'], 'discount' => mandala_od_money($cl['discount'] ?? 0), 'discount_tax' => mandala_od_money($cl['discount_tax'] ?? 0)]);
                $order->add_item($item);
            }
            $meta($order, (array) ($o['meta_data'] ?? []));
            $order->update_meta_data(MANDALA_OD_OLD, (int) $o['id']);
            $order->update_meta_data('_mandala_old_number', (string) ($o['number'] ?? $o['id']));
            $order->update_meta_data(MANDALA_OD_FLAG, 1);
            if ($status !== preg_replace('/^wc-/', '', (string) ($o['status'] ?? ''))) {
                $order->update_meta_data('_mandala_old_status', (string) $o['status']);
            }
            // A régi boltban már levonták a készletet, számolták az eladást és a kuponhasználatot
            $order->set_recorded_sales(true);
            $order->set_recorded_coupon_usage_counts(true);
            if (method_exists($order, 'set_order_stock_reduced')) {
                $order->set_order_stock_reduced(in_array($status, ['processing', 'completed', 'on-hold'], true));
            }
            $order->save();
            // Visszatérítések (összeg, ok, dátum)
            foreach ((array) ($o['refund_details'] ?? array_map(fn($r) => ['id' => $r['id'], 'amount' => abs((float) $r['total']), 'reason' => $r['reason'] ?? ''], (array) ($o['refunds'] ?? []))) as $r) {
                $refund = new WC_Order_Refund();
                $amount = abs((float) ($r['amount'] ?? 0));
                $refund->set_props(['parent_id' => $order->get_id(), 'amount' => mandala_od_money($amount), 'total' => mandala_od_money(-$amount), 'reason' => (string) ($r['reason'] ?? ''),
                    'currency' => $order->get_currency(), 'refunded_payment' => !empty($r['refunded_payment'])]);
                if ($t = mandala_od_time($r['date_created_gmt'] ?? '')) {
                    $refund->set_date_created($t);
                }
                $refund->update_meta_data(MANDALA_OD_OLD, (int) $r['id']);
                $refund->update_meta_data(MANDALA_OD_FLAG, 1);
                $refund->save();
            }
            mandala_od_notes($order->get_id(), (array) ($o['notes'] ?? []));
            $res['created']++;
        } catch (Throwable $e) {
            $res['log'][] = '#' . ($o['number'] ?? $o['id']) . ': ' . $e->getMessage();
        }
    }
    return $res;
}
/** Rendelésjegyzetek eredeti dátummal, levél nélkül; a már átvetteket kihagyja. */
function mandala_od_notes(int $order_id, array $notes): int
{
    global $wpdb;
    if (!$notes) {
        return 0;
    }
    $have = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT m.meta_value FROM {$wpdb->commentmeta} m JOIN {$wpdb->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_post_ID = %d AND m.meta_key = %s", $order_id, MANDALA_OD_OLD)));
    $n = 0;
    foreach ($notes as $note) {
        if (in_array((int) $note['id'], $have, true)) {
            continue;
        }
        $t = mandala_od_time($note['date_created_gmt'] ?? '') ?: time();
        $author = (string) ($note['author'] ?? '');
        wp_insert_comment(['comment_post_ID' => $order_id, 'comment_author' => $author === '' || $author === 'system' ? 'WooCommerce' : $author,
            'comment_author_email' => '', 'comment_content' => (string) $note['note'], 'comment_agent' => 'WooCommerce', 'comment_type' => 'order_note',
            'comment_approved' => 1, 'comment_date_gmt' => gmdate('Y-m-d H:i:s', $t), 'comment_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $t)),
            'comment_meta' => ['is_customer_note' => !empty($note['customer_note']) ? 1 : 0, MANDALA_OD_OLD => (int) $note['id']]]);
        $n++;
    }
    return $n;
}

function mandala_od_coupons(array $items): array
{
    $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'log' => []];
    foreach ($items as $c) {
        $code = wc_format_coupon_code((string) $c['code']);
        $existing = wc_get_coupon_id_by_code($code);
        if ($existing) {
            if (get_post_meta($existing, MANDALA_OD_FLAG, true)) { // újrafuttatás: használat frissítése
                $coupon = new WC_Coupon($existing);
                $coupon->set_usage_count((int) ($c['usage_count'] ?? 0));
                $coupon->set_used_by((array) ($c['used_by'] ?? []));
                $coupon->save();
                $res['updated']++;
            } else {
                $res['skipped']++;
            }
            continue;
        }
        $ids = fn($refs) => array_values(array_filter(array_map(fn($r) => ($p = mandala_od_product((array) $r)) ? ($p[1] ?: $p[0]) : 0, (array) $refs)));
        $cats = fn($slugs) => array_values(array_filter(array_map(fn($s) => ($t = get_term_by('slug', (string) $s, 'product_cat')) ? $t->term_id : 0, (array) $slugs)));
        $coupon = new WC_Coupon();
        $coupon->set_props(['code' => $code, 'amount' => mandala_od_money($c['amount'] ?? 0), 'discount_type' => (string) ($c['discount_type'] ?? 'fixed_cart'),
            'description' => (string) ($c['description'] ?? ''), 'date_expires' => mandala_od_time($c['date_expires_gmt'] ?? ''), 'individual_use' => !empty($c['individual_use']),
            'product_ids' => $ids($c['product_refs'] ?? []), 'excluded_product_ids' => $ids($c['excluded_product_refs'] ?? []),
            'usage_limit' => (int) ($c['usage_limit'] ?? 0), 'usage_limit_per_user' => (int) ($c['usage_limit_per_user'] ?? 0), 'limit_usage_to_x_items' => $c['limit_usage_to_x_items'] ?? null,
            'free_shipping' => !empty($c['free_shipping']), 'product_categories' => $cats($c['cat_slugs'] ?? []), 'excluded_product_categories' => $cats($c['excluded_cat_slugs'] ?? []),
            'exclude_sale_items' => !empty($c['exclude_sale_items']), 'minimum_amount' => mandala_od_money($c['minimum_amount'] ?? 0), 'maximum_amount' => mandala_od_money($c['maximum_amount'] ?? 0),
            'email_restrictions' => (array) ($c['email_restrictions'] ?? []), 'usage_count' => (int) ($c['usage_count'] ?? 0), 'used_by' => (array) ($c['used_by'] ?? []),
            'date_created' => mandala_od_time($c['date_created_gmt'] ?? '')]);
        if (((array) ($c['product_refs'] ?? [])) && !$coupon->get_product_ids()) {
            $res['log'][] = $code . ': a kupon termékei nem találhatók itt – termékkorlát nélkül nem vehető át, kihagyva.';
            continue;
        }
        $coupon->update_meta_data(MANDALA_OD_OLD, (int) $c['id']);
        $coupon->update_meta_data(MANDALA_OD_FLAG, 1);
        $coupon->save();
        $res['created']++;
    }
    return $res;
}

function mandala_od_reviews(array $items): array
{
    $res = ['created' => 0, 'skipped' => 0, 'log' => []];
    $known = mandala_od_map('post', array_column($items, 'id'), 'mandala_review');
    foreach ($items as $r) {
        if (isset($known[(int) $r['id']]) || !in_array($r['status'] ?? '', ['approved', 'hold'], true)) {
            $res['skipped']++;
            continue;
        }
        [$pid] = mandala_od_product((array) ($r['product_ref'] ?? []));
        if (!$pid) {
            $res['skipped']++;
            $res['log'][] = 'Értékelés (' . ($r['product_ref']['name'] ?? '?') . '): a termék itt nem található.';
            continue;
        }
        $pid = function_exists('mandala_original_id') ? mandala_original_id($pid) : $pid;
        $name = (string) ($r['reviewer'] ?? '');
        $parts = preg_split('/\s+/u', trim($name));
        $short = count($parts) > 1 ? $parts[0] . ' ' . mb_substr(end($parts), 0, 1) . '.' : $name;
        $t = mandala_od_time($r['date_created_gmt'] ?? '') ?: time();
        $id = wp_insert_post(['post_type' => 'mandala_review', 'post_status' => ($r['status'] ?? '') === 'approved' ? 'publish' : 'pending',
            'post_title' => get_the_title($pid) . ' – ' . $short, 'post_content' => trim(wp_strip_all_tags((string) ($r['review'] ?? ''))),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $t), 'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $t))], true);
        if (is_wp_error($id)) {
            $res['log'][] = $id->get_error_message();
            continue;
        }
        foreach (['_product' => $pid, '_order' => 0, '_rating' => max(1, min(5, (int) ($r['rating'] ?? 5))), '_author' => $short, '_verified' => !empty($r['verified']) ? '1' : '',
            MANDALA_OD_OLD => (int) $r['id'], MANDALA_OD_FLAG => 1] as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        delete_post_meta($pid, '_mandala_review_stats');
        $res['created']++;
    }
    return $res;
}

/** Blog: kategóriák és címkék (slug szerint), majd a bejegyzések képekkel. */
function mandala_od_terms(array $d): array
{
    $res = ['created' => 0, 'skipped' => 0, 'log' => []];
    foreach (['category' => (array) ($d['categories'] ?? []), 'post_tag' => (array) ($d['tags'] ?? [])] as $tax => $terms) {
        usort($terms, fn($a, $b) => (int) ($a['parent'] ?? 0) <=> (int) ($b['parent'] ?? 0));
        $slug_of = array_column($terms, 'slug', 'id');
        foreach ($terms as $t) {
            if (term_exists((string) $t['slug'], $tax)) {
                $res['skipped']++;
                continue;
            }
            $parent = !empty($t['parent']) && isset($slug_of[$t['parent']]) ? get_term_by('slug', $slug_of[$t['parent']], $tax) : null;
            $r = wp_insert_term(html_entity_decode((string) $t['name']), $tax, ['slug' => (string) $t['slug'], 'description' => (string) ($t['description'] ?? ''), 'parent' => $parent ? $parent->term_id : 0]);
            if (is_wp_error($r)) {
                $res['log'][] = $r->get_error_message();
                continue;
            }
            update_term_meta($r['term_id'], MANDALA_OD_FLAG, 1);
            $res['created']++;
        }
    }
    return $res;
}
/** Kép letöltése a régi boltból (egyszer; a már letöltöttet újrahasznosítja). */
function mandala_od_image(string $url, int $post_id, string $alt = ''): int
{
    global $wpdb;
    $url = html_entity_decode($url);
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_old_url' AND meta_value = %s LIMIT 1", $url));
    if ($id) {
        return $id;
    }
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $original = preg_replace('/-\d+x\d+(\.[a-z]{3,4})$/i', '$1', $url); // a méretezett változat helyett az eredeti
    foreach (array_unique([$original, $url]) as $try) {
        $id = media_sideload_image($try, $post_id, $alt ?: null, 'id');
        if (!is_wp_error($id)) {
            update_post_meta($id, '_mandala_old_url', $url);
            update_post_meta($id, MANDALA_OD_FLAG, 1);
            if ($alt) {
                update_post_meta($id, '_wp_attachment_image_alt', $alt);
            }
            return (int) $id;
        }
    }
    return 0;
}
/** A tartalom képeit áthozza, a hivatkozásokat átírja. Visszaad: [tartalom, hibás képek száma]. */
function mandala_od_content_images(string $html, int $post_id, string $source): array
{
    $host = preg_quote(preg_replace('#^https?://(?:www\.)?#i', '', untrailingslashit($source)), '#'); // gép + esetleg port
    preg_match_all('#https?://(?:www\.)?' . $host . '/wp-content/uploads/[^"\'\s)<>]+\.(?:jpe?g|png|gif|webp|avif)#i', $html, $m);
    $failed = 0;
    foreach (array_unique($m[0]) as $url) {
        $id = mandala_od_image($url, $post_id);
        if ($id && ($new = wp_get_attachment_url($id))) {
            $html = str_replace($url, $new, $html);
        } else {
            $failed++;
        }
    }
    // A régi médiaazonosítók itt mást jelentenének: a hivatkozások nélkül a WordPress a képet URL alapján mutatja
    $html = preg_replace('/\s(?:srcset|sizes)="[^"]*"/i', '', $html);
    $html = preg_replace('/\bwp-image-\d+\b/', '', $html);
    $html = preg_replace('/\sclass="\s*"/', '', $html);
    $html = preg_replace('/(<!-- wp:image \{[^}]*?)"id":\d+,?/', '$1', $html);
    return [$html, $failed];
}
function mandala_od_posts(array $items, string $source): array
{
    $res = ['created' => 0, 'skipped' => 0, 'log' => []];
    $known = mandala_od_map('post', array_column($items, 'id'), 'post');
    foreach ($items as $p) {
        if (isset($known[(int) $p['id']]) || get_page_by_path((string) $p['slug'], OBJECT, 'post')) {
            $res['skipped']++;
            continue;
        }
        $t = mandala_od_time($p['date_gmt'] ?? '') ?: time();
        $cats = array_values(array_filter(array_map(fn($s) => ($term = get_term_by('slug', (string) $s, 'category')) ? $term->term_id : 0, (array) ($p['category_slugs'] ?? []))));
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => in_array($p['status'] ?? '', ['publish', 'draft', 'future', 'private'], true) ? $p['status'] : 'draft',
            'post_title' => (string) $p['title'], 'post_name' => (string) $p['slug'], 'post_content' => '', 'post_excerpt' => (string) ($p['excerpt'] ?? ''),
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $t), 'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $t)), 'post_author' => get_current_user_id(),
            'post_category' => $cats ?: [(int) get_option('default_category')], 'tags_input' => (array) ($p['tag_names'] ?? []),
            'meta_input' => [MANDALA_OD_OLD => (int) $p['id'], MANDALA_OD_FLAG => 1, '_mandala_old_url' => (string) ($p['link'] ?? '')]], true);
        if (is_wp_error($id)) {
            $res['log'][] = $p['title'] . ': ' . $id->get_error_message();
            continue;
        }
        [$content, $failed] = mandala_od_content_images((string) $p['content'], $id, $source);
        wp_update_post(['ID' => $id, 'post_content' => $content]);
        if (!empty($p['featured']['url']) && ($img = mandala_od_image((string) $p['featured']['url'], $id, (string) ($p['featured']['alt'] ?? '')))) {
            set_post_thumbnail($id, $img);
        } elseif (!empty($p['featured']['url'])) {
            $failed++;
        }
        if (!empty($p['sticky'])) {
            stick_post($id);
        }
        if (!empty($p['seo']['title'])) {
            update_post_meta($id, '_yoast_wpseo_title', (string) $p['seo']['title']);
        }
        if (!empty($p['seo']['description'])) {
            update_post_meta($id, '_yoast_wpseo_metadesc', (string) $p['seo']['description']);
        }
        if ($failed) {
            $res['log'][] = '„' . $p['title'] . '”: ' . $failed . ' kép nem tölthető le a régi boltból (a régi címen maradt).';
        }
        $res['created']++;
    }
    return $res;
}
/** A régi bolt azon oldalai, amelyek itt nincsenek: tervezetként (átnézésre), tiszta szöveggel és képekkel. */
function mandala_od_pages(array $items, string $source): array
{
    $res = ['created' => 0, 'skipped' => 0, 'log' => []];
    $known = mandala_od_map('post', array_column($items, 'id'), 'page');
    foreach ($items as $p) {
        $html = function_exists('mandala_oldset_clean_html') ? mandala_oldset_clean_html((string) $p['html']) : wp_kses_post((string) $p['html']);
        if (isset($known[(int) $p['id']]) || get_page_by_path((string) $p['slug']) || mb_strlen(trim(wp_strip_all_tags($html))) < 40) {
            $res['skipped']++;
            continue;
        }
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'draft', 'post_title' => wp_strip_all_tags(html_entity_decode((string) $p['title'])), 'post_name' => (string) $p['slug'],
            'post_content' => '', 'meta_input' => [MANDALA_OD_OLD => (int) $p['id'], MANDALA_OD_FLAG => 1, '_mandala_old_url' => (string) ($p['link'] ?? '')]], true);
        if (is_wp_error($id)) {
            continue;
        }
        [$html] = mandala_od_content_images($html, $id, $source);
        wp_update_post(['ID' => $id, 'post_content' => $html]);
        $res['created']++;
    }
    return $res;
}

/** YITH ajándékkártya → a téma ajándékutalványa (kód, érték, egyenleg, lejárat). */
function mandala_od_card_fields(array $meta): array
{
    $pick = function (array $keys) use ($meta) {
        foreach ($keys as $k) {
            if (isset($meta[$k]) && $meta[$k] !== '') {
                return $meta[$k];
            }
        }
        return null;
    };
    $exp = $pick(['_ywgc_expiration', '_ywgc_expiration_date']);
    $exp = $exp && ctype_digit((string) $exp) ? (int) $exp : ($exp ? strtotime((string) $exp) : 0);
    return ['value' => (int) round((float) $pick(['_ywgc_amount_total', '_ywgc_amount', 'amount'])), 'balance' => $pick(['_ywgc_balance_total', '_ywgc_balance', 'balance']),
        'expires' => $exp > 0 ? wp_date('Y-m-d', $exp) : '', 'recipient' => (string) $pick(['_ywgc_recipient', 'recipient']), 'order' => (int) $pick(['_ywgc_order_id'])];
}
function mandala_od_giftcards(array $items): array
{
    $res = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'log' => []];
    if (!post_type_exists('mandala_voucher') || !function_exists('mandala_voucher_normalize')) {
        return $res + ['log' => ['A téma ajándékutalvány-modulja nem aktív.']];
    }
    $known = mandala_od_map('post', array_column($items, 'id'), 'mandala_voucher');
    $orders = mandala_od_map('order', array_filter(array_map(fn($c) => mandala_od_card_fields((array) $c['meta'])['order'], $items)));
    foreach ($items as $c) {
        $f = mandala_od_card_fields((array) ($c['meta'] ?? []));
        if ($f['balance'] === null) {
            $res['skipped']++;
            $res['log'][] = ($c['code'] ?? '?') . ': az egyenleg nem olvasható ki (mezők: ' . implode(', ', array_keys((array) $c['meta'])) . ').';
            continue;
        }
        $balance = (int) round((float) $f['balance']);
        if (isset($known[(int) $c['id']])) { // újrafuttatás: a régi boltban azóta beváltott egyenleg
            $id = $known[(int) $c['id']];
            if ((int) get_post_meta($id, '_balance', true) !== $balance) {
                $log = (array) get_post_meta($id, '_log', true);
                $log[] = [current_time('mysql'), $balance - (int) get_post_meta($id, '_balance', true), __('Egyenleg frissítve a régi boltból', 'mandala')];
                update_post_meta($id, '_balance', $balance);
                update_post_meta($id, '_log', $log);
                $res['updated']++;
            } else {
                $res['skipped']++;
            }
            continue;
        }
        $code = mandala_voucher_normalize((string) ($c['code'] ?? ''));
        if ($code === '' || $balance <= 0 || ($c['status'] ?? 'publish') !== 'publish' || mandala_voucher_find($code)) {
            $res['skipped']++;
            continue;
        }
        $t = mandala_od_time($c['date_gmt'] ?? '') ?: time();
        $id = wp_insert_post(['post_type' => 'mandala_voucher', 'post_status' => 'publish', 'post_title' => $code,
            'post_date_gmt' => gmdate('Y-m-d H:i:s', $t), 'post_date' => get_date_from_gmt(gmdate('Y-m-d H:i:s', $t))], true);
        if (is_wp_error($id)) {
            $res['log'][] = $code . ': ' . $id->get_error_message();
            continue;
        }
        foreach (['_value' => $f['value'] ?: $balance, '_balance' => $balance, '_expires' => $f['expires'], '_recipient' => $f['recipient'], '_order' => $orders[$f['order']] ?? 0,
            '_log' => [[current_time('mysql'), $balance, sprintf(__('Átvéve a régi boltból (YITH ajándékkártya, eredeti érték: %s Ft)', 'mandala'), $f['value'])]],
            MANDALA_OD_OLD => (int) $c['id'], MANDALA_OD_FLAG => 1] as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        $res['created']++;
    }
    return $res;
}

/** Zárás: az új rendelések azonosítója a régi rendelésszámok fölött kezdődjön (ne legyen két „#1234”). */
function mandala_od_finalize(array $d): array
{
    global $wpdb;
    $max = (int) ($d['max_number'] ?? 0);
    $log = [];
    if ($max > 0) {
        $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->posts));
        $current = (int) ($status->Auto_increment ?? 0);
        if ($current && $max + 1 > $current && $wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = " . ($max + 1)) !== false) { // phpcs:ignore
            $log[] = 'Az új rendelések száma ' . ($max + 1) . '-tól indul (a régi rendelésszámok fölött).';
        }
    }
    if (function_exists('wc_delete_shop_order_transients')) {
        wc_delete_shop_order_transients();
    }
    delete_transient('wc_count_comments');
    update_option('mandala_olddata_last', ['time' => time(), 'counts' => array_map('intval', (array) ($d['counts'] ?? []))], false);
    $log[] = 'A WooCommerce-statisztikákhoz: WooCommerce → Beállítások → Speciális → Funkciók / Analytics → „Előzmények importálása”.';
    return ['log' => $log];
}

/** Az átvett adatok törlése (adagonként; visszaad: hátralévő darab). */
function mandala_od_undo(): array
{
    global $wpdb;
    $left = 0;
    $n = 0;
    $orders = wc_get_orders(['limit' => 40, 'return' => 'ids', 'type' => 'shop_order', 'meta_key' => MANDALA_OD_FLAG, 'meta_value' => 1, 'status' => array_keys(wc_get_order_statuses())]);
    foreach ($orders as $id) {
        if ($o = wc_get_order($id)) {
            foreach ($o->get_refunds() as $r) {
                $r->delete(true);
            }
            foreach ($wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = 'order_note'", $id)) as $cid) {
                wp_delete_comment((int) $cid, true);
            }
            $o->delete(true);
            $n++;
        }
    }
    if (count($orders) === 40) {
        return ['deleted' => $n, 'left' => 1];
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $users = get_users(['meta_key' => MANDALA_OD_FLAG, 'meta_value' => 1, 'fields' => 'ID', 'number' => 200]);
    foreach ($users as $uid) {
        wp_delete_user((int) $uid);
        $n++;
    }
    if (count($users) === 200) {
        return ['deleted' => $n, 'left' => 1];
    }
    $posts = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_type IN ('shop_coupon','mandala_review','post','page','attachment','mandala_voucher') LIMIT 100", MANDALA_OD_FLAG));
    foreach ($posts as $id) {
        get_post_type($id) === 'attachment' ? wp_delete_attachment((int) $id, true) : wp_delete_post((int) $id, true);
        $n++;
    }
    foreach (['category', 'post_tag'] as $tax) {
        foreach (get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'meta_key' => MANDALA_OD_FLAG, 'meta_value' => 1, 'fields' => 'ids']) as $tid) {
            wp_delete_term((int) $tid, $tax);
            $n++;
        }
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", '_mandala_old_user_id'));
    if (count($posts) === 100) {
        $left = 1;
    } else {
        delete_option('mandala_olddata_last');
    }
    return ['deleted' => $n, 'left' => $left];
}

add_action('wp_ajax_mandala_olddata', function () {
    if (!current_user_can('manage_woocommerce') || !check_ajax_referer('mandala_olddata', 'nonce', false)) {
        wp_send_json_error('Nincs jogosultság (lépj be újra).', 403);
    }
    $part = sanitize_key(wp_unslash($_POST['part'] ?? ''));
    // base64: a tűzfalak (WAF) így nem akadnak fenn a rendelésekben / blogban lévő HTML-en
    $data = json_decode((string) base64_decode((string) wp_unslash($_POST['data'] ?? ''), true), true);
    $data = is_array($data) ? $data : [];
    $source = esc_url_raw((string) wp_unslash($_POST['source'] ?? ''));
    mandala_od_quiet();
    switch ($part) {
        case 'customers': $res = mandala_od_customers($data); break;
        case 'coupons': $res = mandala_od_coupons($data); break;
        case 'orders': $res = mandala_od_orders($data); break;
        case 'reviews': $res = mandala_od_reviews($data); break;
        case 'terms': $res = mandala_od_terms($data); break;
        case 'posts': $res = mandala_od_posts($data, $source); break;
        case 'pages': $res = mandala_od_pages($data, $source); break;
        case 'giftcards': $res = mandala_od_giftcards($data); break;
        case 'finalize': $res = mandala_od_finalize($data); break;
        case 'undo': $res = mandala_od_undo(); break;
        default: wp_send_json_error('Ismeretlen rész.', 400);
    }
    wp_send_json_success($res);
});

/* ---------- Admin ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Régi bolt adatai', 'Régi bolt adatai', 'manage_woocommerce', 'mandala-olddata', 'mandala_od_page');
}, 60);

function mandala_od_page(): void
{
    $script = (string) file_get_contents(__DIR__ . '/olddata-export.js');
    $last = get_option('mandala_olddata_last');
    ?>
    <div class="wrap" id="mandala-olddata">
        <h1>Régi bolt adatai</h1>
        <p style="max-width:780px">A régi webáruház <strong>vásárlói, rendelései</strong> (jegyzetekkel, visszatérítésekkel, a régi rendelésszámmal),
            <strong>kuponjai, termékértékelései, blogbejegyzései</strong> (képekkel), a hiányzó <strong>oldalai</strong> (tervezetként, átnézésre) és a
            <strong>YITH ajándékkártyák</strong> (egyenleggel, a bolt ajándékutalványaiként). Átvétel közben <strong>nem megy ki levél</strong>, és nem fut
            készletlevonás, pontjóváírás, számlázás vagy automata levél. Újrafuttatható: az élesítés napján futtasd le még egyszer, az addig
            beérkezett rendeléseket és a változásokat hozza át.</p>
        <?php if ($last) : ?>
            <div class="notice notice-info inline"><p>Utolsó átvétel: <?php echo esc_html(wp_date('Y-m-d H:i', (int) $last['time'])); ?> –
                <?php echo esc_html(implode(', ', array_map(fn($k, $v) => $k . ': ' . $v, array_keys((array) $last['counts']), (array) $last['counts']))); ?></p></div>
        <?php endif; ?>
        <h2>1. Export a régi boltból</h2>
        <ol style="max-width:780px">
            <li>Lépj be a <strong>régi</strong> bolt adminjába, nyisd meg a fejlesztői konzolt (Chrome: Cmd+Option+J / Ctrl+Shift+J).</li>
            <li>Másold be az alábbi szöveget, Enter (Chrome először kérheti: írd be, hogy <code>allow pasting</code>). Pár perc, a jobb felső sarokban látod, hol tart.</li>
            <li>Letöltődik a <code>mandala-regi-adatok.json</code> – a vásárlók személyes adatai vannak benne, az átvétel után töröld.</li>
        </ol>
        <p><button type="button" class="button" id="mod-copy">Szöveg másolása</button></p>
        <textarea readonly rows="6" style="width:100%;max-width:780px;font-family:monospace;font-size:12px" id="mod-script"><?php echo esc_textarea($script); ?></textarea>
        <h2>2. Átvétel</h2>
        <p><input type="file" id="mod-file" accept=".json,application/json"></p>
        <div id="mod-preview"></div>
        <p><button type="button" class="button button-primary" id="mod-start" hidden>Átvétel indítása</button></p>
        <div id="mod-progress" hidden style="max-width:780px"><progress id="mod-bar" max="100" value="0" style="width:100%;height:18px"></progress><p id="mod-status"></p></div>
        <ul id="mod-log" style="list-style:disc;padding-left:20px;max-width:780px"></ul>
        <h2>Átvett adatok törlése</h2>
        <p style="max-width:780px">Próbaátvétel után: törli az átvétellel létrehozott rendeléseket, vásárlókat, kuponokat, értékeléseket, bejegyzéseket, oldalakat,
            képeket és utalványokat. Ami már korábban is itt volt, marad.</p>
        <p><button type="button" class="button" id="mod-undo">Átvett adatok törlése</button></p>
    </div>
    <script>
    (() => {
      const nonce = <?php echo wp_json_encode(wp_create_nonce('mandala_olddata')); ?>;
      const $ = (id) => document.getElementById(id);
      const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
      const staff = <?php echo wp_json_encode(MANDALA_OD_STAFF); ?>;
      let data = null;
      $('mod-copy').onclick = () => { $('mod-script').select(); navigator.clipboard?.writeText($('mod-script').value); $('mod-copy').textContent = 'Kimásolva ✓'; };
      const b64 = (obj) => { const bytes = new TextEncoder().encode(JSON.stringify(obj)); let s = ''; for (let i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode(...bytes.subarray(i, i + 0x8000)); return btoa(s); };
      const call = async (part, payload, tries = 3) => {
        for (let attempt = 1; ; attempt++) {
          try {
            const body = new URLSearchParams({ action: 'mandala_olddata', nonce, part, source: data?.source || '', data: b64(payload) });
            const r = await fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body });
            const j = await r.json().catch(() => null);
            if (j && j.success) return j.data;
            throw new Error(j?.data || `HTTP ${r.status}`);
          } catch (e) {
            if (attempt >= tries) throw e;
            await new Promise((res) => setTimeout(res, 2000 * attempt));
          }
        }
      };
      const log = (msg) => { const li = document.createElement('li'); li.textContent = msg; $('mod-log').appendChild(li); };
      const card = (m) => { const k = (a) => a.find((x) => m[x] !== undefined && m[x] !== ''); const b = k(['_ywgc_balance_total', '_ywgc_balance', 'balance']); return b ? Number(m[b]) : null; };

      $('mod-file').onchange = async (ev) => {
        const f = ev.target.files[0];
        if (!f) return;
        try { data = JSON.parse(await f.text()); } catch (e) { data = null; }
        if (!data || data.kind !== 'mandala-old-data') { $('mod-preview').innerHTML = '<div class="notice notice-error inline"><p>Ez nem a régi bolt adatfájlja (mandala-regi-adatok.json).</p></div>'; $('mod-start').hidden = true; return; }
        const cust = data.customers.filter((c) => !staff.includes(c.role));
        const roles = {}; cust.forEach((c) => { roles[c.role] = (roles[c.role] || 0) + 1; });
        const st = {}; data.orders.forEach((o) => { st[o.status] = (st[o.status] || 0) + 1; });
        const dates = data.orders.map((o) => o.date_created_gmt).filter(Boolean).sort();
        const cards = (data.gift_cards || []).filter((c) => c.status === 'publish');
        const withBal = cards.filter((c) => (card(c.meta) || 0) > 0);
        const keys = [...new Set((data.gift_cards || []).flatMap((c) => Object.keys(c.meta)))].filter((k) => !k.startsWith('_edit')).slice(0, 12);
        const row = (part, label, n, extra, checked = true) => `<tr><td><label><input type="checkbox" data-part="${part}" ${checked && n ? 'checked' : ''} ${n ? '' : 'disabled'}> <strong>${label}</strong></label></td><td>${n}</td><td>${extra}</td></tr>`;
        $('mod-preview').innerHTML = `<p>Forrás: <strong>${esc(data.source)}</strong>, export: ${esc(new Date(data.created).toLocaleString('hu-HU'))}${data.errors.length ? ` · <span style="color:#b32d2e">${data.errors.length} hiba az exportban</span>` : ''}</p>
          <table class="widefat striped" style="max-width:900px"><thead><tr><th>Mit</th><th>Darab</th><th>Megjegyzés</th></tr></thead><tbody>
          ${row('customers', 'Vásárlók', cust.length, esc(Object.entries(roles).map(([k, v]) => `${k}: ${v}`).join(', ')) + ` · a munkatársak (${data.customers.length - cust.length}) kimaradnak · az e-mail alapján már itt lévők megmaradnak · jelszó nem jön át (belépéskor új jelszót kérnek)`)}
          ${row('orders', 'Rendelések', data.orders.length, esc(`${(dates[0] || '').slice(0, 10)} – ${(dates[dates.length - 1] || '').slice(0, 10)} · ` + Object.entries(st).map(([k, v]) => `${k}: ${v}`).join(', ')) + ' · jegyzetekkel, visszatérítésekkel, régi rendelésszámmal')}
          ${row('coupons', 'Kuponok', data.coupons.length, 'a már létező kódok maradnak')}
          ${row('reviews', 'Termékértékelések', data.reviews.length, 'a jóváhagyottak közzétéve, a függők moderálásra')}
          ${row('posts', 'Blogbejegyzések', data.posts.length, `${data.post_categories.length} kategória, képek letöltése a régi boltból`)}
          ${row('pages', 'Hiányzó oldalak', data.pages.length, 'csak ami itt nincs, TERVEZETKÉNT (átnézésre); a jogi oldalakat a „Régi bolt beállításai” hozza', true)}
          ${row('giftcards', 'Ajándékkártyák (YITH)', withBal.length, data.gift_cards === null ? 'az export nem érte el' : esc(`${cards.length} aktív, ebből ${withBal.length} egyenleggel · mezők: ${keys.join(', ')}`))}
          </tbody></table>`;
        $('mod-start').hidden = false;
      };

      $('mod-start').onclick = async () => {
        const parts = [...document.querySelectorAll('#mod-preview input[data-part]:checked')].map((i) => i.dataset.part);
        if (!parts.length) return;
        $('mod-start').disabled = true; $('mod-file').disabled = true; $('mod-progress').hidden = false; $('mod-log').innerHTML = '';
        const P = data.products || {}, C = data.product_cats || {};
        const ref = (id) => P[id] ? { sku: P[id].sku, slug: P[id].slug, name: P[id].name } : {};
        const catSlug = Object.fromEntries(data.post_categories.map((c) => [c.id, c.slug]));
        const tagName = Object.fromEntries(data.post_tags.map((t) => [t.id, t.name]));
        const jobs = [];
        const add = (part, label, items, size) => { for (let i = 0; i < items.length; i += size) jobs.push({ part, label, items: items.slice(i, i + size) }); };
        if (parts.includes('customers')) add('customers', 'Vásárlók', data.customers, 50);
        if (parts.includes('coupons')) add('coupons', 'Kuponok', data.coupons.map((c) => ({ ...c, product_refs: (c.product_ids || []).map(ref), excluded_product_refs: (c.excluded_product_ids || []).map(ref),
          cat_slugs: (c.product_categories || []).map((id) => C[id]), excluded_cat_slugs: (c.excluded_product_categories || []).map((id) => C[id]) })), 25);
        if (parts.includes('orders')) add('orders', 'Rendelések', data.orders, 20);
        if (parts.includes('reviews')) add('reviews', 'Értékelések', data.reviews.map((r) => ({ ...r, product_ref: ref(r.product_id) })), 25);
        if (parts.includes('posts')) {
          jobs.push({ part: 'terms', label: 'Blogkategóriák', items: { categories: data.post_categories, tags: data.post_tags } });
          add('posts', 'Blogbejegyzések', data.posts.map((p) => ({ ...p, category_slugs: (p.categories || []).map((id) => catSlug[id]).filter(Boolean), tag_names: (p.tags || []).map((id) => tagName[id]).filter(Boolean) })), 2);
        }
        if (parts.includes('pages')) add('pages', 'Oldalak', data.pages, 3);
        if (parts.includes('giftcards')) add('giftcards', 'Ajándékkártyák', data.gift_cards || [], 50);
        const nums = data.orders.map((o) => parseInt(o.number, 10)).filter((n) => n > 0);
        const total = {};
        let i = 0;
        try {
          for (const job of jobs) {
            $('mod-status').textContent = `${job.label}… (${++i} / ${jobs.length})`;
            const r = await call(job.part, job.items);
            const t = total[job.label] = total[job.label] || { created: 0, updated: 0, matched: 0, skipped: 0 };
            ['created', 'updated', 'matched', 'skipped'].forEach((k) => { t[k] += r[k] || 0; });
            (r.log || []).forEach(log);
            $('mod-bar').value = Math.round((i / jobs.length) * 100);
          }
          const counts = Object.fromEntries(Object.entries(total).map(([k, v]) => [k, v.created]));
          const fin = await call('finalize', { max_number: nums.length ? Math.max(...nums) : 0, counts });
          Object.entries(total).forEach(([k, v]) => log(`${k}: ${v.created} átvéve` + (v.updated ? `, ${v.updated} frissítve` : '') + (v.matched ? `, ${v.matched} már itt volt (összekapcsolva)` : '') + (v.skipped ? `, ${v.skipped} kihagyva` : '')));
          (fin.log || []).forEach(log);
          $('mod-status').innerHTML = '<strong>Kész.</strong> A letöltött fájlt töröld a gépedről (személyes adatok vannak benne).';
        } catch (e) {
          $('mod-status').innerHTML = `<strong style="color:#b32d2e">Megszakadt: ${esc(e.message)}</strong> – indítsd újra: a már átvett tételeket felismeri, onnan folytatja.`;
        }
        $('mod-start').disabled = false; $('mod-file').disabled = false;
      };

      $('mod-undo').onclick = async () => {
        if (!confirm('Biztosan törlöd az átvétellel létrehozott összes adatot (rendelések, vásárlók, kuponok, bejegyzések…)?')) return;
        $('mod-progress').hidden = false; $('mod-log').innerHTML = '';
        let n = 0;
        try {
          for (;;) { const r = await call('undo', {}); n += r.deleted; $('mod-status').textContent = `Törlés… ${n}`; if (!r.left) break; }
          $('mod-status').innerHTML = `<strong>Kész:</strong> ${n} tétel törölve.`;
        } catch (e) { $('mod-status').textContent = 'Hiba: ' + e.message; }
      };
    })();
    </script>
    <?php
}
