<?php
/**
 * Partnerprogram (jógatanárok, hangterapeuták, jógastúdiók): saját kód és link. A partner vásárlója
 * kedvezményt kap, a partner jutalékot a teljesített rendelések után. Az ügyfélajánló programtól
 * (growth.php) külön: ez szerződött partnereknek szól, pénzbeli jutalékkal.
 *
 *  - Link: /?partner=KOD → 60 napos süti, a kupon a kosárban magától érvényesül (ha nincs más kupon).
 *    A kód a pénztárban kézzel is beírható.
 *  - Jutalék: a teljesített rendelés termékeinek nettó értéke (kedvezmény után, szállítás és ÁFA nélkül) × %.
 *    Lemondott / visszatérített rendelésnél visszaíródik.
 *  - Partner: Fiókom → Partnerprogram (link, kód, kattintások, rendelések, jutalék, kifizetések).
 *  - Admin: WooCommerce → Partnerek (felvétel e-mail-címmel, arányok, kifizetés rögzítése).
 */

defined('ABSPATH') || exit;

function mandala_partner_settings(): array
{
    return wp_parse_args((array) get_option('mandala_partners_cfg', []), ['discount' => 10, 'commission' => 10, 'cookie_days' => 60]);
}
function mandala_is_partner(int $user_id): bool
{
    return get_user_meta($user_id, '_mandala_partner', true) === 'yes';
}
function mandala_partner_by_code(string $code): int
{
    $u = get_users(['meta_key' => '_mandala_partner_code', 'meta_value' => strtoupper($code), 'number' => 1, 'fields' => 'ID']);
    return $u && mandala_is_partner((int) $u[0]) ? (int) $u[0] : 0;
}
function mandala_partner_link(int $user_id): string
{
    return add_query_arg('partner', (string) get_user_meta($user_id, '_mandala_partner_code', true), home_url('/'));
}
function mandala_partner_rate(int $user_id, string $k): float
{
    $v = get_user_meta($user_id, '_mandala_partner_' . $k, true);
    return $v === '' ? (float) mandala_partner_settings()[$k] : (float) $v;
}

/** Partner felvétele / frissítése: kód, kupon, üdvözlő levél (csak először). */
function mandala_partner_add(string $email, string $code, string $name = '', ?float $discount = null, ?float $commission = null): int
{
    $email = strtolower(sanitize_email($email));
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if (!is_email($email) || strlen($code) < 3) {
        return 0;
    }
    $user = get_user_by('email', $email);
    if (!$user) {
        $id = wc_create_new_customer($email, '', '', ['first_name' => $name]);
        if (is_wp_error($id)) {
            return 0;
        }
        $user = get_userdata($id);
    }
    $other = mandala_partner_by_code($code);
    if ($other && $other !== $user->ID) {
        return 0; // foglalt kód
    }
    $new = !mandala_is_partner($user->ID);
    update_user_meta($user->ID, '_mandala_partner', 'yes');
    update_user_meta($user->ID, '_mandala_partner_code', $code);
    foreach (['discount' => $discount, 'commission' => $commission] as $k => $v) {
        $v === null ? delete_user_meta($user->ID, '_mandala_partner_' . $k) : update_user_meta($user->ID, '_mandala_partner_' . $k, $v);
    }
    $cid = wc_get_coupon_id_by_code($code);
    $c = new WC_Coupon($cid ?: 0);
    $c->set_code($code);
    $c->set_discount_type('percent');
    $c->set_amount(mandala_partner_rate($user->ID, 'discount'));
    $c->set_individual_use(true);
    $c->set_description('Partnerprogram: ' . $email);
    $c->update_meta_data('_mandala_partner', $user->ID);
    $c->save();
    if ($new) {
        mandala_mail('partner_welcome', $email, ['keresztnev' => $user->first_name ?: $user->display_name, 'kod' => $code, 'kedvezmeny' => (int) mandala_partner_rate($user->ID, 'discount') . '%', 'jutalek' => (int) mandala_partner_rate($user->ID, 'commission') . '%'],
            ['link' => '<p style="text-align:center;font-family:monospace;font-size:16px"><a href="' . esc_url(mandala_partner_link($user->ID)) . '" style="color:#8A4512">' . esc_html(mandala_partner_link($user->ID)) . '</a></p>', 'gomb' => mandala_mail_button(wc_get_account_endpoint_url('partnerprogram'), __('A partnerfelületem', 'mandala'))]);
    }
    return $user->ID;
}

/* ---------- Link: süti + kattintásszám, kupon a kosárban ---------- */

add_action('template_redirect', function () {
    if (empty($_GET['partner'])) { // phpcs:ignore
        return;
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['partner']))); // phpcs:ignore
    $pid = mandala_partner_by_code($code);
    if ($pid && $pid !== get_current_user_id()) {
        setcookie('mandala_partner', $code, ['expires' => time() + (int) mandala_partner_settings()['cookie_days'] * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['mandala_partner'] = $code;
        update_user_meta($pid, '_mandala_partner_clicks', (int) get_user_meta($pid, '_mandala_partner_clicks', true) + 1);
    }
    wp_safe_redirect(remove_query_arg('partner'));
    exit;
});
add_action('woocommerce_before_calculate_totals', function ($cart) {
    static $busy = false;
    $code = preg_replace('/[^A-Z0-9]/', '', (string) ($_COOKIE['mandala_partner'] ?? ''));
    if ($busy || $code === '' || is_admin() || $cart->is_empty() || $cart->get_applied_coupons() || !mandala_partner_by_code($code)) {
        return;
    }
    $busy = true;
    if (!$cart->has_discount($code)) {
        $cart->apply_coupon($code);
    }
    $busy = false;
}, 6);

/* ---------- Jutalék ---------- */

/** A rendelés partnere (a kuponja alapján). */
function mandala_order_partner(WC_Order $order): int
{
    foreach ($order->get_coupon_codes() as $code) {
        $pid = (int) (new WC_Coupon($code))->get_meta('_mandala_partner');
        if ($pid && mandala_is_partner($pid) && strtolower(get_userdata($pid)->user_email) !== strtolower($order->get_billing_email())) {
            return $pid;
        }
    }
    return 0;
}
add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_mandala_partner_commission') !== '') {
        return;
    }
    $pid = mandala_order_partner($order);
    if (!$pid) {
        return;
    }
    $net = 0.0;
    foreach ($order->get_items() as $item) {
        $net += (float) $item->get_total(); // kedvezmény után, ÁFA nélkül
    }
    $commission = round($net * mandala_partner_rate($pid, 'commission') / 100);
    $order->update_meta_data('_mandala_partner_id', $pid);
    $order->update_meta_data('_mandala_partner_commission', $commission);
    $order->save_meta_data();
    $order->add_order_note(sprintf('Partner jutalék: %s (%s)', mandala_fmt($commission), get_userdata($pid)->user_email));
    update_user_meta($pid, '_mandala_partner_earned', (float) get_user_meta($pid, '_mandala_partner_earned', true) + $commission);
    $u = get_userdata($pid);
    mandala_mail('partner_sale', $u->user_email, ['keresztnev' => $u->first_name ?: $u->display_name, 'jutalek_osszeg' => mandala_fmt($commission), 'egyenleg' => mandala_fmt(mandala_partner_balance($pid))]);
}, 40);
foreach (['woocommerce_order_status_refunded', 'woocommerce_order_status_cancelled'] as $hook) {
    add_action($hook, function ($order_id) {
        $order = wc_get_order($order_id);
        $pid = $order ? (int) $order->get_meta('_mandala_partner_id') : 0;
        $c = $order ? (float) $order->get_meta('_mandala_partner_commission') : 0;
        if ($pid && $c > 0 && !$order->get_meta('_mandala_partner_reversed')) {
            update_user_meta($pid, '_mandala_partner_earned', max(0, (float) get_user_meta($pid, '_mandala_partner_earned', true) - $c));
            $order->update_meta_data('_mandala_partner_reversed', 'yes');
            $order->save_meta_data();
            $order->add_order_note(sprintf('Partner jutalék visszaírva: %s', mandala_fmt($c)));
        }
    });
}
function mandala_partner_balance(int $pid): float
{
    return (float) get_user_meta($pid, '_mandala_partner_earned', true) - (float) get_user_meta($pid, '_mandala_partner_paid', true);
}
function mandala_partner_orders(int $pid, int $limit = 20): array
{
    return wc_get_orders(['limit' => $limit, 'meta_key' => '_mandala_partner_id', 'meta_value' => $pid, 'orderby' => 'date', 'order' => 'DESC']);
}

/* ---------- Fiókom → Partnerprogram ---------- */

add_filter('woocommerce_get_query_vars', fn($vars) => $vars + ['partnerprogram' => 'partnerprogram']);
add_action('init', function () {
    if (get_option('mandala_rewrite_partner') !== '1') {
        add_action('wp_loaded', fn() => flush_rewrite_rules(false));
        update_option('mandala_rewrite_partner', '1');
    }
}, 99);
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!mandala_is_partner(get_current_user_id())) {
        return $items;
    }
    return array_slice($items, 0, 1, true) + ['partnerprogram' => __('Partnerprogram', 'mandala')] + $items;
});
add_filter('woocommerce_endpoint_partnerprogram_title', fn() => __('Partnerprogram', 'mandala'));
add_action('woocommerce_account_partnerprogram_endpoint', function () {
    $pid = get_current_user_id();
    if (!mandala_is_partner($pid)) {
        echo '<p>' . esc_html__('A partnerprogram jógatanároknak, hangterapeutáknak és stúdióknak szól. Írj nekünk, ha csatlakoznál!', 'mandala') . '</p>';
        return;
    }
    $link = mandala_partner_link($pid);
    $code = (string) get_user_meta($pid, '_mandala_partner_code', true);
    $orders = mandala_partner_orders($pid);
    echo '<div class="partner-stats">'
        . '<div><span class="text-muted text-small">' . esc_html__('Kattintás a linkedre', 'mandala') . '</span><strong class="num">' . (int) get_user_meta($pid, '_mandala_partner_clicks', true) . '</strong></div>'
        . '<div><span class="text-muted text-small">' . esc_html__('Teljesített rendelés', 'mandala') . '</span><strong class="num">' . count(array_filter($orders, fn($o) => !$o->get_meta('_mandala_partner_reversed'))) . '</strong></div>'
        . '<div><span class="text-muted text-small">' . esc_html__('Eddigi jutalék', 'mandala') . '</span><strong class="num">' . esc_html(mandala_fmt((float) get_user_meta($pid, '_mandala_partner_earned', true))) . '</strong></div>'
        . '<div><span class="text-muted text-small">' . esc_html__('Kifizetésre vár', 'mandala') . '</span><strong class="num">' . esc_html(mandala_fmt(mandala_partner_balance($pid))) . '</strong></div></div>';
    echo '<section class="panel ref-panel"><h2 style="font-size:var(--fs-h4)">' . esc_html__('A linked és a kódod', 'mandala') . '</h2>'
        . '<p>' . esc_html(sprintf(__('Akik a linkeden érkeznek vagy a „%1$s” kódot beírják, %2$d%% kedvezményt kapnak; te a teljesített rendeléseik nettó értékének %3$d%%-át.', 'mandala'), $code, (int) mandala_partner_rate($pid, 'discount'), (int) mandala_partner_rate($pid, 'commission'))) . '</p>'
        . '<div class="copy-row"><input type="text" readonly value="' . esc_attr($link) . '" aria-label="' . esc_attr__('A partner linked', 'mandala') . '"><button type="button" class="iu-button" data-copy="' . esc_attr($link) . '">' . esc_html__('Másolás', 'mandala') . '</button></div></section>';
    if ($orders) {
        echo '<table class="shop_table"><caption class="sr-only">' . esc_html__('Rendelések a kódoddal', 'mandala') . '</caption><thead><tr><th>' . esc_html__('Dátum', 'mandala') . '</th><th class="num">' . esc_html__('Jutalék', 'mandala') . '</th><th>' . esc_html__('Állapot', 'mandala') . '</th></tr></thead><tbody>';
        foreach ($orders as $o) {
            echo '<tr><td>' . esc_html(wc_format_datetime($o->get_date_created(), 'Y. m. d.')) . '</td><td class="num">' . esc_html(mandala_fmt((float) $o->get_meta('_mandala_partner_commission'))) . '</td><td>' . esc_html($o->get_meta('_mandala_partner_reversed') ? __('visszaírva', 'mandala') : __('jóváírva', 'mandala')) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    $payouts = array_filter((array) get_user_meta($pid, '_mandala_partner_payouts', true));
    if ($payouts) {
        echo '<h3 style="font-size:var(--fs-h5)">' . esc_html__('Kifizetések', 'mandala') . '</h3><ul>';
        foreach ($payouts as [$date, $amount]) {
            echo '<li>' . esc_html(wp_date('Y. m. d.', strtotime($date))) . ' – ' . esc_html(mandala_fmt((float) $amount)) . '</li>';
        }
        echo '</ul>';
    }
});

/* ---------- Admin: WooCommerce → Partnerek ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Partnerek', 'Partnerek', 'manage_woocommerce', 'mandala-partners', function () {
        $cfg = mandala_partner_settings();
        if (!empty($_POST['partner_do']) && check_admin_referer('mandala_partners')) {
            $do = sanitize_key(wp_unslash($_POST['partner_do']));
            if ($do === 'add') {
                $d = ($_POST['discount'] ?? '') === '' ? null : (float) $_POST['discount'];
                $c = ($_POST['commission'] ?? '') === '' ? null : (float) $_POST['commission'];
                $id = mandala_partner_add((string) wp_unslash($_POST['email'] ?? ''), (string) wp_unslash($_POST['code'] ?? ''), sanitize_text_field(wp_unslash($_POST['name'] ?? '')), $d, $c);
                echo $id ? '<div class="notice notice-success"><p>Partner mentve.</p></div>' : '<div class="notice notice-error"><p>Nem sikerült: ellenőrizd az e-mail-címet, és hogy a kód (min. 3 karakter) nem foglalt-e.</p></div>';
            } elseif ($do === 'paid') {
                $pid = absint($_POST['pid'] ?? 0);
                $amount = max(0, (float) ($_POST['amount'] ?? 0));
                if ($pid && $amount > 0) {
                    update_user_meta($pid, '_mandala_partner_paid', (float) get_user_meta($pid, '_mandala_partner_paid', true) + $amount);
                    $log = array_filter((array) get_user_meta($pid, '_mandala_partner_payouts', true));
                    $log[] = [current_time('mysql'), $amount];
                    update_user_meta($pid, '_mandala_partner_payouts', $log);
                    echo '<div class="notice notice-success"><p>Kifizetés rögzítve.</p></div>';
                }
            } elseif ($do === 'remove') {
                $pid = absint($_POST['pid'] ?? 0);
                update_user_meta($pid, '_mandala_partner', 'no');
                $cid = wc_get_coupon_id_by_code((string) get_user_meta($pid, '_mandala_partner_code', true));
                if ($cid) {
                    wp_trash_post($cid);
                }
                echo '<div class="notice notice-success"><p>Partner kikapcsolva, a kódja megszűnt.</p></div>';
            } elseif ($do === 'cfg') {
                foreach (['discount' => [0, 50], 'commission' => [0, 50], 'cookie_days' => [1, 365]] as $k => [$min, $max]) {
                    $cfg[$k] = max($min, min($max, (int) ($_POST[$k] ?? $cfg[$k])));
                }
                update_option('mandala_partners_cfg', $cfg, false);
                echo '<div class="notice notice-success"><p>Mentve.</p></div>';
            }
        }
        $nonce = wp_nonce_field('mandala_partners', '_wpnonce', true, false);
        $partners = get_users(['meta_key' => '_mandala_partner', 'meta_value' => 'yes']);
        echo '<div class="wrap"><h1>Partnerek</h1><p>Szerződött partnerek (jógatanárok, hangterapeuták, stúdiók) saját kóddal és linkkel. A vásárló kedvezményt kap, a partner jutalékot a teljesített rendelések után.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Partner</th><th>Kód</th><th>Kedvezmény / jutalék</th><th>Kattintás</th><th>Rendelés</th><th>Jutalék összesen</th><th>Kifizetésre vár</th><th></th></tr></thead><tbody>';
        foreach ($partners as $u) {
            $orders = mandala_partner_orders($u->ID, 500);
            echo '<tr><td><strong>' . esc_html($u->display_name) . '</strong><br>' . esc_html($u->user_email) . '</td><td><code>' . esc_html((string) get_user_meta($u->ID, '_mandala_partner_code', true)) . '</code><br><small>' . esc_html(mandala_partner_link($u->ID)) . '</small></td>'
                . '<td>' . (int) mandala_partner_rate($u->ID, 'discount') . '% / ' . (int) mandala_partner_rate($u->ID, 'commission') . '%</td><td>' . (int) get_user_meta($u->ID, '_mandala_partner_clicks', true) . '</td><td>' . count($orders) . '</td>'
                . '<td>' . esc_html(mandala_fmt((float) get_user_meta($u->ID, '_mandala_partner_earned', true))) . '</td><td><strong>' . esc_html(mandala_fmt(mandala_partner_balance($u->ID))) . '</strong></td>'
                . '<td><form method="post" style="display:inline-flex;gap:4px">' . $nonce . '<input type="hidden" name="partner_do" value="paid"><input type="hidden" name="pid" value="' . (int) $u->ID . '"><input type="number" name="amount" value="' . (int) mandala_partner_balance($u->ID) . '" style="width:90px"><button class="button">Kifizetve</button></form> '
                . '<form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="partner_do" value="remove"><input type="hidden" name="pid" value="' . (int) $u->ID . '"><button class="button-link" onclick="return confirm(\'Kikapcsolod a partnert? A kódja megszűnik.\')">Kikapcsolás</button></form></td></tr>';
        }
        if (!$partners) {
            echo '<tr><td colspan="8">Még nincs partner.</td></tr>';
        }
        echo '</tbody></table><h2>Új partner / módosítás</h2><form method="post">' . $nonce . '<input type="hidden" name="partner_do" value="add"><table class="form-table">'
            . '<tr><th>E-mail-cím</th><td><input type="email" name="email" class="regular-text" required><p class="description">Ha még nincs fiókja, létrehozzuk (jelszóbeállító levelet kap).</p></td></tr>'
            . '<tr><th>Név</th><td><input type="text" name="name" class="regular-text"></td></tr>'
            . '<tr><th>Kód</th><td><input type="text" name="code" class="regular-text" placeholder="pl. JOGAANNA" required><p class="description">Betűk és számok; ezt írja be a vásárló, és ez lesz a linkben.</p></td></tr>'
            . '<tr><th>Egyedi arányok</th><td>kedvezmény <input type="number" name="discount" min="0" max="50" style="width:70px" placeholder="' . (int) $cfg['discount'] . '"> % · jutalék <input type="number" name="commission" min="0" max="50" style="width:70px" placeholder="' . (int) $cfg['commission'] . '"> % <span class="description">(üresen az alapértelmezés)</span></td></tr></table>';
        submit_button('Partner mentése');
        echo '</form><h2>Alapértelmezések</h2><form method="post">' . $nonce . '<input type="hidden" name="partner_do" value="cfg"><table class="form-table">'
            . '<tr><th>Vásárlói kedvezmény</th><td><input type="number" name="discount" value="' . (int) $cfg['discount'] . '" style="width:70px"> %</td></tr>'
            . '<tr><th>Partner jutalék</th><td><input type="number" name="commission" value="' . (int) $cfg['commission'] . '" style="width:70px"> % <span class="description">a termékek nettó értékéből</span></td></tr>'
            . '<tr><th>Süti élettartama</th><td><input type="number" name="cookie_days" value="' . (int) $cfg['cookie_days'] . '" style="width:70px"> nap</td></tr></table>';
        submit_button('Mentés');
        echo '</form></div>';
    });
});

/* ---------- Levélsablonok ---------- */

add_filter('mandala_mail_types', function ($types) {
    $types['partner_welcome'] = [
        'label' => 'Partner – üdvözlő', 'group' => 'Partnerprogram', 'required' => true,
        'when' => 'Amikor az adminban felveszel egy új partnert.',
        'vars' => ['keresztnev' => 'A partner neve', 'kod' => 'A partner kódja', 'kedvezmeny' => 'A vásárlói kedvezmény', 'jutalek' => 'A jutalék'], 'blocks' => ['link' => 'A partner linkje', 'gomb' => '„A partnerfelületem” gomb'],
        'subject' => __('Üdv a Mandala partnerprogramjában', 'mandala'), 'heading' => __('Örülünk, hogy velünk vagy', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('A kódod: <strong>{kod}</strong>. Akik ezt beírják, vagy a linkeden érkeznek, {kedvezmeny} kedvezményt kapnak, te pedig a teljesített rendeléseik után {jutalek} jutalékot.', 'mandala') . '</p>{link}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna', 'kod' => 'JOGAANNA', 'kedvezmeny' => '10%', 'jutalek' => '10%'], ['link' => '<p style="text-align:center">' . esc_html(home_url('/?partner=JOGAANNA')) . '</p>', 'gomb' => mandala_mail_button(home_url('/'), __('A partnerfelületem', 'mandala'))]],
    ];
    $types['partner_sale'] = [
        'label' => 'Partner – jutalék jóváírva', 'group' => 'Partnerprogram', 'default_on' => 'yes',
        'when' => 'Amikor a partner kódjával leadott rendelés teljesül, és jóváírjuk a jutalékot.',
        'vars' => ['keresztnev' => 'A partner neve', 'jutalek_osszeg' => 'A jutalék', 'egyenleg' => 'Kifizetésre váró egyenleg'], 'blocks' => [],
        'subject' => __('Jutalék jóváírva: {jutalek_osszeg}', 'mandala'), 'heading' => __('Újabb vásárlás a kódoddal', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('Egy vásárlód rendelése teljesült – {jutalek_osszeg} jutalékot írtunk jóvá. Kifizetésre váró egyenleged: {egyenleg}.', 'mandala') . '</p>',
        'sample' => fn() => [['keresztnev' => 'Anna', 'jutalek_osszeg' => '1 490 Ft', 'egyenleg' => '6 820 Ft'], []],
    ];
    return $types;
});
