<?php
/**
 * Önjáró növekedési eszközök – beállítás után emberi munka nélkül futnak.
 *
 *  - Vásárlás utáni ajánló: a teljesítés után X nappal 3 odaillő termék (a tanuló ajánlóból).
 *  - Kedvencek: ha egy belépett vásárló kedvence akciós lett vagy fogyóban van – egyszer szólunk.
 *  - Feliratkozó ablak: „Iratkozz fel, és kapsz X%-ot az első rendelésedre” – egyedi, egyszer
 *    használható, a címhez kötött kupon; a feliratkozó a hírlevél-listára (és a MailerLite-ba) kerül.
 *  - Ajánlási program: minden vásárlónak saját link; a barát X% kedvezményt kap az első rendelésére,
 *    az ajánló pedig a barát teljesített rendelése után egy kupont. Önmagának nem ajánlhat.
 *
 * A levelek a levélközpontban szerkeszthetők; a beállítások: WooCommerce → Mandala kuponok.
 */

defined('ABSPATH') || exit;

function mandala_growth_settings(): array
{
    return wp_parse_args((array) get_option('mandala_growth', []), [
        'popup' => 'yes', 'popup_percent' => 10, 'popup_days' => 14, 'popup_delay' => 25,
        'referral' => 'yes', 'ref_percent' => 10, 'ref_reward' => 2000, 'ref_reward_days' => 90,
    ]);
}

/** Egyszer használható, a címhez kötött kupon. */
function mandala_growth_coupon(string $prefix, string $type, float $amount, int $days, string $email = '', array $meta = []): WC_Coupon
{
    do {
        $code = $prefix . '-' . strtoupper(wp_generate_password(6, false, false));
    } while (wc_get_coupon_id_by_code($code));
    $c = new WC_Coupon();
    $c->set_code($code);
    $c->set_discount_type($type);
    $c->set_amount($amount);
    $c->set_date_expires(time() + $days * DAY_IN_SECONDS);
    $c->set_usage_limit(1);
    $c->set_individual_use(true);
    if ($email) {
        $c->set_email_restrictions([strtolower($email)]);
    }
    foreach ($meta as $k => $v) {
        $c->update_meta_data($k, $v);
    }
    $c->save();
    return $c;
}
function mandala_growth_coupon_box(WC_Coupon $c): string
{
    $value = $c->get_discount_type() === 'percent' ? (int) $c->get_amount() . '%' : mandala_fmt((float) $c->get_amount());
    $exp = $c->get_date_expires() ? wp_date('Y. m. d.', $c->get_date_expires()->getTimestamp()) : '';
    return '<table role="presentation" style="width:100%;border-collapse:collapse;background:#F6F1E8;border-radius:12px;margin:16px 0"><tr><td style="padding:20px;text-align:center">'
        . '<p style="margin:0;color:#6E6357;font-size:13px;letter-spacing:.08em;text-transform:uppercase">' . esc_html__('Kuponod', 'mandala') . ' · ' . esc_html($value) . '</p>'
        . '<p style="margin:8px 0;font-family:monospace;font-size:22px;letter-spacing:.12em;color:#1C1916">' . esc_html(strtoupper($c->get_code())) . '</p>'
        . '<p style="margin:0;color:#6E6357;font-size:13px">' . esc_html(sprintf(__('Beváltható %s-ig a pénztárban, a kuponkód mezőben.', 'mandala'), $exp)) . '</p></td></tr></table>';
}

/** „Csak első rendelésre” kupon: a pénztárban ellenőrizzük, volt-e már rendelése a címnek. */
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    $email = strtolower((string) ($data['billing_email'] ?? ''));
    if (!$email || !WC()->cart) {
        return;
    }
    foreach (WC()->cart->get_applied_coupons() as $code) {
        $c = new WC_Coupon($code);
        if ($c->get_meta('_mandala_first_order') !== 'yes') {
            continue;
        }
        $prev = wc_get_orders(['billing_email' => $email, 'status' => ['processing', 'completed', 'on-hold'], 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
        if ($prev) {
            $errors->add('mandala_first_order', sprintf(__('A(z) %s kupon csak az első rendelésre érvényes.', 'mandala'), strtoupper($code)));
        }
        $ref = (int) $c->get_meta('_mandala_referrer');
        if ($ref && ($u = get_userdata($ref)) && strtolower($u->user_email) === $email) {
            $errors->add('mandala_self_ref', __('A saját ajánló kódodat nem használhatod – oszd meg a barátaiddal.', 'mandala'));
        }
    }
}, 10, 2);

/* ---------- Vásárlás utáni ajánló ---------- */

add_action('woocommerce_order_status_completed', function ($order_id) {
    if (mandala_automation_on('crosssell')) {
        mandala_schedule((int) mandala_automation_settings()['crosssell_days'] * DAY_IN_SECONDS, 'mandala_mail_crosssell', [(int) $order_id]);
    }
}, 20);
add_action('mandala_mail_crosssell', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_automation_on('crosssell') || !function_exists('mandala_reco_for')) {
        return;
    }
    $bought = array_map(fn($i) => (int) $i->get_product_id(), array_values($order->get_items()));
    // A vásárló többi rendelésének termékei se kerüljenek az ajánlóba.
    foreach (wc_get_orders(['billing_email' => $order->get_billing_email(), 'limit' => 20, 'type' => 'shop_order']) as $o) {
        foreach ($o->get_items() as $i) {
            $bought[] = (int) $i->get_product_id();
        }
    }
    $ids = mandala_reco_for(array_slice(array_unique($bought), 0, 10), 3, $bought);
    if (count($ids) < 2) {
        return;
    }
    do_action('mandala_before_order_mail', $order);
    $rows = '';
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if ($p) {
            $rows .= mandala_mail_product_row($p, wp_kses_post(wc_price(wc_get_price_to_display($p))));
        }
    }
    mandala_mail('crosssell', $order->get_billing_email(), mandala_mail_order_vars($order), ['termekek' => $rows, 'gomb' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))], $order->get_id());
});

/* ---------- Kedvencek: akciós lett / fogyóban ---------- */

add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_wishlist_check', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'mandala_wishlist_check', [], MANDALA_AS_GROUP);
    }
}, 30);
add_action('mandala_wishlist_check', function () {
    if (!mandala_automation_on('wishlist')) {
        return;
    }
    $index = [];
    foreach (mandala_product_index() as $r) {
        $index[(int) $r['id']] = $r;
    }
    foreach (get_users(['meta_key' => 'mandala_wishlist', 'meta_compare' => 'EXISTS', 'number' => 2000, 'fields' => ['ID', 'user_email', 'display_name']]) as $u) {
        $ids = array_map('intval', (array) get_user_meta($u->ID, 'mandala_wishlist', true));
        $seen = (array) get_user_meta($u->ID, '_mandala_wish_seen', true);
        $sale = [];
        $low = [];
        foreach ($ids as $id) {
            $r = $index[$id] ?? null;
            if (!$r || empty($r['buyable'])) {
                continue;
            }
            if (!empty($r['compare']) && (float) ($seen[$id]['sale'] ?? 0) !== (float) $r['price']) {
                $sale[] = $id;
                $seen[$id]['sale'] = (float) $r['price'];
            } elseif (empty($r['compare'])) {
                unset($seen[$id]['sale']);
            }
            if (($r['stock'] ?? '') === 'low' && empty($seen[$id]['low'])) {
                $low[] = $id;
                $seen[$id]['low'] = 1;
            } elseif (($r['stock'] ?? '') === 'in') {
                unset($seen[$id]['low']);
            }
        }
        update_user_meta($u->ID, '_mandala_wish_seen', $seen);
        $user = get_userdata($u->ID);
        $vars = ['keresztnev' => $user->first_name ?: $u->display_name];
        $row = fn($id, $extra) => ($p = wc_get_product($id)) ? mandala_mail_product_row($p, $extra) : '';
        if ($sale) {
            $rows = implode('', array_map(fn($id) => $row($id, '<s>' . esc_html(mandala_fmt((float) $index[$id]['compare'])) . '</s> <strong style="color:#8A4512">' . esc_html(mandala_fmt((float) $index[$id]['price'])) . '</strong>'), $sale));
            mandala_mail('wishlist_sale', $u->user_email, $vars, ['termekek' => $rows, 'gomb' => mandala_mail_button(get_permalink((int) get_option('mandala_page_kedvencek')) ?: mandala_shop_url(), __('A kedvenceim', 'mandala'))]);
        }
        if ($low) {
            $rows = implode('', array_map(fn($id) => $row($id, esc_html__('Már csak néhány darab van raktáron.', 'mandala')), $low));
            mandala_mail('wishlist_low', $u->user_email, $vars, ['termekek' => $rows, 'gomb' => mandala_mail_button(get_permalink((int) get_option('mandala_page_kedvencek')) ?: mandala_shop_url(), __('A kedvenceim', 'mandala'))]);
        }
    }
});

/* ---------- Feliratkozó ablak első vásárlási kuponnal ---------- */

add_filter('mandala_js_data', function ($data) {
    $s = mandala_growth_settings();
    $customer = is_user_logged_in() && function_exists('wc_get_customer_order_count') && wc_get_customer_order_count(get_current_user_id()) > 0;
    if ($s['popup'] === 'yes' && !$customer) {
        $data['welcome'] = ['percent' => (int) $s['popup_percent'], 'delay' => (int) $s['popup_delay'], 'privacy' => get_privacy_policy_url()];
    }
    return $data;
});
add_action('wp_enqueue_scripts', function () {
    if (mandala_growth_settings()['popup'] === 'yes' && !(function_exists('is_checkout') && (is_checkout() || is_cart() || is_account_page()))) {
        wp_enqueue_script_module('mandala-growth', MANDALA_URL . '/assets/js/growth.js', [], MANDALA_VERSION);
    }
}, 40);

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/welcome', [
        'methods' => 'POST',
        'permission_callback' => function (WP_REST_Request $r) {
            $from = (string) ($r->get_header('origin') ?: $r->get_header('referer'));
            return wp_verify_nonce((string) $r->get_header('x_wp_nonce'), 'wp_rest') || ($from !== '' && wp_parse_url($from, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST));
        },
        'callback' => function (WP_REST_Request $r) {
            $s = mandala_growth_settings();
            $email = strtolower(sanitize_email((string) $r->get_param('email')));
            if ($s['popup'] !== 'yes') {
                return new WP_REST_Response(['error' => 'off'], 404);
            }
            if (!is_email($email)) {
                return new WP_REST_Response(['error' => __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala')], 400);
            }
            if (empty($r->get_param('accept'))) {
                return new WP_REST_Response(['error' => __('A feliratkozáshoz fogadd el az adatkezelési tájékoztatót.', 'mandala')], 400);
            }
            $ip = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
            $tries = (int) get_transient('mandala_welcome_' . $ip);
            if ($tries >= 5) {
                return new WP_REST_Response(['error' => __('Túl sok próbálkozás – próbáld újra később.', 'mandala')], 429);
            }
            set_transient('mandala_welcome_' . $ip, $tries + 1, HOUR_IN_SECONDS);
            $subs = (array) get_option('mandala_newsletter', []);
            $had_order = (bool) wc_get_orders(['billing_email' => $email, 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
            if (isset($subs[$email]) || $had_order) {
                return ['ok' => true, 'message' => __('Köszönjük! Ezzel a címmel már feliratkoztál vagy vásároltál nálunk – az első rendelési kupon új vásárlóknak szól, de a hírleveleinket megkapod.', 'mandala')];
            }
            $subs[$email] = ['date' => current_time('mysql'), 'source' => 'felugró ablak'];
            update_option('mandala_newsletter', $subs, false);
            $list = array_diff((array) get_option('mandala_unsubscribed', []), [$email]);
            update_option('mandala_unsubscribed', array_values($list), false);
            $coupon = mandala_growth_coupon('UDV', 'percent', (float) $s['popup_percent'], (int) $s['popup_days'], $email, ['_mandala_first_order' => 'yes', '_mandala_source' => 'welcome']);
            mandala_mail('welcome', $email, ['keresztnev' => __('Kedves Vásárlónk', 'mandala'), 'kupon' => strtoupper($coupon->get_code()), 'kupon_ertek' => (int) $s['popup_percent'] . '%'], ['kupon_doboz' => mandala_growth_coupon_box($coupon), 'gomb' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))]);
            do_action('mandala_newsletter_subscribed', $email, 'felugro');
            return ['ok' => true, 'code' => strtoupper($coupon->get_code()), 'message' => sprintf(__('Köszönjük! A kuponodat e-mailben is elküldtük: %s (%d%%, %d napig).', 'mandala'), strtoupper($coupon->get_code()), (int) $s['popup_percent'], (int) $s['popup_days'])];
        },
    ]);
});

/* ---------- Ajánlási program ---------- */

function mandala_ref_code(int $user_id): string
{
    $code = (string) get_user_meta($user_id, '_mandala_ref_code', true);
    if ($code === '') {
        do {
            $code = strtoupper(wp_generate_password(6, false, false));
        } while (get_users(['meta_key' => '_mandala_ref_code', 'meta_value' => $code, 'number' => 1, 'fields' => 'ID']));
        update_user_meta($user_id, '_mandala_ref_code', $code);
    }
    return $code;
}
function mandala_ref_link(int $user_id): string
{
    return add_query_arg('ajanlo', mandala_ref_code($user_id), home_url('/'));
}
/** A barát kuponja (ajánlónként egy, bárki használhatja egyszer, első rendelésre). */
function mandala_ref_coupon(int $user_id): string
{
    $code = 'BARAT-' . mandala_ref_code($user_id);
    $s = mandala_growth_settings();
    if (!wc_get_coupon_id_by_code($code)) {
        $c = new WC_Coupon();
        $c->set_code($code);
        $c->set_discount_type('percent');
        $c->set_amount((float) $s['ref_percent']);
        $c->set_usage_limit_per_user(1);
        $c->set_individual_use(true);
        $c->set_description('Ajánlási program – ajánló: #' . $user_id);
        $c->update_meta_data('_mandala_first_order', 'yes');
        $c->update_meta_data('_mandala_referrer', $user_id);
        $c->save();
    }
    return $code;
}

/** Ajánló link: süti 30 napra, a kupon a kosárban magától érvényesül. */
add_action('template_redirect', function () {
    if (empty($_GET['ajanlo']) || mandala_growth_settings()['referral'] !== 'yes') { // phpcs:ignore
        return;
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) wp_unslash($_GET['ajanlo']))); // phpcs:ignore
    $user = get_users(['meta_key' => '_mandala_ref_code', 'meta_value' => $code, 'number' => 1]);
    if ($user && (int) $user[0]->ID !== get_current_user_id()) {
        setcookie('mandala_ref', $code, ['expires' => time() + 30 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
        $_COOKIE['mandala_ref'] = $code;
    }
    wp_safe_redirect(remove_query_arg('ajanlo'));
    exit;
});
add_action('woocommerce_before_calculate_totals', function ($cart) {
    static $busy = false;
    $code = preg_replace('/[^A-Z0-9]/', '', (string) ($_COOKIE['mandala_ref'] ?? ''));
    if ($busy || $code === '' || is_admin() || mandala_growth_settings()['referral'] !== 'yes' || $cart->is_empty() || $cart->get_applied_coupons()) {
        return;
    }
    $user = get_users(['meta_key' => '_mandala_ref_code', 'meta_value' => $code, 'number' => 1]);
    if (!$user || (int) $user[0]->ID === get_current_user_id()) {
        return;
    }
    $busy = true;
    $coupon = mandala_ref_coupon((int) $user[0]->ID);
    if (!$cart->has_discount($coupon)) {
        $cart->apply_coupon($coupon);
    }
    $busy = false;
}, 5);

/** A barát teljesített első rendelése után az ajánló kupont kap. */
add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_mandala_ref_rewarded') || mandala_growth_settings()['referral'] !== 'yes') {
        return;
    }
    foreach ($order->get_coupon_codes() as $code) {
        $c = new WC_Coupon($code);
        $ref = (int) $c->get_meta('_mandala_referrer');
        $referrer = $ref ? get_userdata($ref) : null;
        if (!$referrer || strtolower($referrer->user_email) === strtolower($order->get_billing_email())) {
            continue;
        }
        $s = mandala_growth_settings();
        $reward = mandala_growth_coupon('KOSZI', 'fixed_cart', (float) $s['ref_reward'], (int) $s['ref_reward_days'], $referrer->user_email, ['_mandala_source' => 'referral']);
        $order->update_meta_data('_mandala_ref_rewarded', strtoupper($reward->get_code()));
        $order->save_meta_data();
        $count = (int) get_user_meta($ref, '_mandala_ref_count', true) + 1;
        update_user_meta($ref, '_mandala_ref_count', $count);
        mandala_mail('referral_reward', $referrer->user_email, ['keresztnev' => $referrer->first_name ?: $referrer->display_name, 'kupon' => strtoupper($reward->get_code()), 'kupon_ertek' => mandala_fmt((float) $s['ref_reward']), 'ajanlasok' => (string) $count],
            ['kupon_doboz' => mandala_growth_coupon_box($reward), 'ajanlo_link' => '<p style="text-align:center"><a href="' . esc_url(mandala_ref_link($ref)) . '" style="color:#8A4512">' . esc_html(mandala_ref_link($ref)) . '</a></p>']);
        break;
    }
}, 30);

/** Ajánló panel: Fiókom kezdőlap és köszönőoldal (belépett vásárló). */
function mandala_ref_panel(int $user_id): string
{
    $s = mandala_growth_settings();
    $link = mandala_ref_link($user_id);
    mandala_ref_coupon($user_id);
    $count = (int) get_user_meta($user_id, '_mandala_ref_count', true);
    return '<section class="panel ref-panel"><h2 style="font-size:var(--fs-h4)">' . esc_html__('Ajánld a barátaidnak', 'mandala') . '</h2>'
        . '<p>' . esc_html(sprintf(__('A barátod %1$d%% kedvezményt kap az első rendelésére, te pedig %2$s kupont, amikor megérkezik a csomagja.', 'mandala'), (int) $s['ref_percent'], mandala_fmt((float) $s['ref_reward']))) . '</p>'
        . '<div class="copy-row"><input type="text" readonly value="' . esc_attr($link) . '" aria-label="' . esc_attr__('Az ajánló linked', 'mandala') . '" id="ref-link"><button type="button" class="iu-button" data-copy="' . esc_attr($link) . '">' . esc_html__('Másolás', 'mandala') . '</button></div>'
        . ($count ? '<p class="text-muted text-small">' . esc_html(sprintf(_n('Eddig %d barátod vásárolt az ajánlásodra.', 'Eddig %d barátod vásárolt az ajánlásodra.', $count, 'mandala'), $count)) . '</p>' : '') . '</section>';
}
add_action('woocommerce_account_dashboard', function () {
    if (mandala_growth_settings()['referral'] === 'yes') {
        echo mandala_ref_panel(get_current_user_id()); // phpcs:ignore
    }
});
add_action('woocommerce_thankyou', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order && $order->get_customer_id() && mandala_growth_settings()['referral'] === 'yes') {
        echo mandala_ref_panel((int) $order->get_customer_id()); // phpcs:ignore
    }
}, 30);

/* ---------- Levélsablonok ---------- */

add_filter('mandala_mail_types', function ($types) {
    $hello = '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p>';
    $types['crosssell'] = [
        'label' => 'Vásárlás utáni ajánló', 'group' => 'Rendelés után', 'setting' => 'crosssell', 'delay' => ['crosssell_days', 'nap'], 'marketing' => true,
        'when' => fn($s) => sprintf('A teljesítés után %d nappal: 3 termék, amit mások a vásárolt termékek mellé vettek (a tanuló ajánlóból). Ha nincs elég jó ajánlat, nem megy.', $s['crosssell_days']),
        'vars' => ['keresztnev' => 'A vásárló keresztneve', 'rendeles' => 'Rendelésszám'], 'blocks' => ['termekek' => '3 ajánlott termék', 'gomb' => '„Irány a kínálat” gomb'],
        'subject' => __('Ehhez illik: válogattunk neked', 'mandala'), 'heading' => __('Ehhez illik', 'mandala'),
        'body' => $hello . '<p>' . __('Reméljük, örömöd leled abban, amit választottál. Akik ugyanezt vették, ezeket is szerették:', 'mandala') . '</p>{termekek}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna', 'rendeles' => '1234'], ['termekek' => mandala_mail_sample_rows(3, true), 'gomb' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))]],
    ];
    $types['wishlist_sale'] = [
        'label' => 'Kedvenc akciós lett', 'group' => 'Értesítések', 'setting' => 'wishlist', 'marketing' => true,
        'when' => 'Naponta ellenőrizve: ha egy belépett vásárló kedvence akciós lett (akciónként egyszer).',
        'vars' => ['keresztnev' => 'A vásárló keresztneve'], 'blocks' => ['termekek' => 'A kedvencek régi és új árral', 'gomb' => '„A kedvenceim” gomb'],
        'subject' => __('Akciós lett a kedvenced', 'mandala'), 'heading' => __('Jó hír a kedvenceidről', 'mandala'),
        'body' => $hello . '<p>' . __('Amit a kedvenceid közé tettél, most kedvezőbb áron kapható:', 'mandala') . '</p>{termekek}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna'], ['termekek' => mandala_mail_sample_rows(1, false, '<s>12 900 Ft</s> <strong style="color:#8A4512">9 900 Ft</strong>'), 'gomb' => mandala_mail_button(home_url('/'), __('A kedvenceim', 'mandala'))]],
    ];
    $types['wishlist_low'] = [
        'label' => 'Kedvenc fogyóban', 'group' => 'Értesítések', 'setting' => 'wishlist', 'marketing' => true,
        'when' => 'Naponta ellenőrizve: ha egy belépett vásárló kedvencéből már csak néhány darab van (egyszer).',
        'vars' => ['keresztnev' => 'A vásárló keresztneve'], 'blocks' => ['termekek' => 'A fogyóban lévő kedvencek', 'gomb' => '„A kedvenceim” gomb'],
        'subject' => __('Fogyóban van a kedvenced', 'mandala'), 'heading' => __('Már csak néhány darab', 'mandala'),
        'body' => $hello . '<p>' . __('A kedvenceid közül ezekből már csak néhány darab van raktáron – kézműves darabok, újra nem biztos, hogy érkezik.', 'mandala') . '</p>{termekek}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna'], ['termekek' => mandala_mail_sample_rows(1, false, esc_html__('Már csak néhány darab van raktáron.', 'mandala')), 'gomb' => mandala_mail_button(home_url('/'), __('A kedvenceim', 'mandala'))]],
    ];
    $types['welcome'] = [
        'label' => 'Üdvözlő kupon (feliratkozó ablak)', 'group' => 'Értesítések', 'required' => true,
        'when' => 'Amikor valaki a felugró ablakban feliratkozik (új címmel). Mindig megy – ebben van a kupon. Az ablak be/ki: WooCommerce → Mandala kuponok.',
        'vars' => ['kupon' => 'A kuponkód', 'kupon_ertek' => 'A kedvezmény (pl. 10%)'], 'blocks' => ['kupon_doboz' => 'Kupon doboz', 'gomb' => '„Irány a kínálat” gomb'],
        'subject' => __('Itt a {kupon_ertek} kedvezményed', 'mandala'), 'heading' => __('Üdv a Mandalánál', 'mandala'),
        'body' => '<p>' . __('Köszönjük, hogy feliratkoztál! Ritkán írunk: új érkezésekről Nepálból és Indiából, magazincikkekről és előfizetői kedvezményekről.', 'mandala') . '</p><p>' . __('Az első rendelésedre ígért kupon:', 'mandala') . '</p>{kupon_doboz}{gomb}',
        'sample' => function () {
            $box = '<table role="presentation" style="width:100%;background:#F6F1E8;border-radius:12px;margin:16px 0"><tr><td style="padding:20px;text-align:center"><p style="margin:0;font-family:monospace;font-size:22px">UDV-MINTA1</p></td></tr></table>';
            return [['kupon' => 'UDV-MINTA1', 'kupon_ertek' => '10%'], ['kupon_doboz' => $box, 'gomb' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))]];
        },
    ];
    $types['referral_reward'] = [
        'label' => 'Ajánlási jutalom', 'group' => 'Értesítések', 'required' => true,
        'when' => 'Amikor az ajánló linkjével érkezett barát első rendelése teljesül: az ajánló kupont kap. Mindig megy.',
        'vars' => ['keresztnev' => 'Az ajánló keresztneve', 'kupon' => 'A kuponkód', 'kupon_ertek' => 'A kupon értéke', 'ajanlasok' => 'Eddigi sikeres ajánlások'],
        'blocks' => ['kupon_doboz' => 'Kupon doboz', 'ajanlo_link' => 'Az ajánló saját linkje'],
        'subject' => __('Köszönjük az ajánlást – itt a kuponod', 'mandala'), 'heading' => __('Köszönjük, hogy ajánlottál', 'mandala'),
        'body' => $hello . '<p>' . __('Egy barátod az ajánlásodra vásárolt nálunk, és már meg is kapta a csomagját. Köszönetképp:', 'mandala') . '</p>{kupon_doboz}<p>' . __('A linked továbbra is él – oszd meg bátran:', 'mandala') . '</p>{ajanlo_link}',
        'sample' => fn() => [['keresztnev' => 'Anna', 'kupon' => 'KOSZI-MINTA1', 'kupon_ertek' => '2 000 Ft', 'ajanlasok' => '1'], ['kupon_doboz' => '<p style="font-family:monospace;font-size:22px;text-align:center">KOSZI-MINTA1</p>', 'ajanlo_link' => '<p style="text-align:center">' . esc_html(home_url('/?ajanlo=ABC123')) . '</p>']],
    ];
    return $types;
});

/* ---------- Admin: WooCommerce → Mandala kuponok ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala kuponok', 'Mandala kuponok', 'manage_woocommerce', 'mandala-growth', function () {
        $s = mandala_growth_settings();
        if (!empty($_POST['mandala_growth']) && check_admin_referer('mandala_growth')) {
            $in = (array) wp_unslash($_POST['mandala_growth']);
            foreach (['popup', 'referral'] as $k) {
                $s[$k] = empty($in[$k]) ? 'no' : 'yes';
            }
            foreach (['popup_percent' => [1, 50], 'popup_days' => [1, 365], 'popup_delay' => [0, 600], 'ref_percent' => [1, 50], 'ref_reward' => [100, 100000], 'ref_reward_days' => [7, 730]] as $k => [$min, $max]) {
                $s[$k] = max($min, min($max, (int) ($in[$k] ?? $s[$k])));
            }
            update_option('mandala_growth', $s, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        global $wpdb;
        $welcome = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'udv-%'");
        $rewards = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_coupon' AND post_title LIKE 'koszi-%'");
        $n = fn($k, $w = 70) => '<input type="number" name="mandala_growth[' . $k . ']" value="' . (int) $s[$k] . '" style="width:' . $w . 'px">';
        echo '<div class="wrap"><h1>Mandala kuponok</h1><p>Önjáró kuponos eszközök. Az üdvözlő és a jutalom levél szövege: Mandala levelek.</p><form method="post">';
        wp_nonce_field('mandala_growth');
        echo '<table class="form-table">'
            . '<tr><th scope="row">Feliratkozó ablak</th><td><label><input type="checkbox" name="mandala_growth[popup]" value="1"' . checked($s['popup'], 'yes', false) . '> bekapcsolva</label><p>' . $n('popup_percent') . ' % kedvezmény az első rendelésre, ' . $n('popup_days') . ' napig érvényes; az ablak ' . $n('popup_delay') . ' másodperc után (vagy amikor el akarná hagyni az oldalt) jelenik meg, 14 naponta legfeljebb egyszer.</p>'
            . '<p class="description">Nem jelenik meg: a kosárban, a pénztárban, a fiók oldalon, és annak, aki már vásárolt. Eddig kiadott üdvözlő kupon: ' . $welcome . '.</p></td></tr>'
            . '<tr><th scope="row">Ajánlási program</th><td><label><input type="checkbox" name="mandala_growth[referral]" value="1"' . checked($s['referral'], 'yes', false) . '> bekapcsolva</label><p>A barát ' . $n('ref_percent') . ' % kedvezményt kap az első rendelésére; az ajánló ' . $n('ref_reward', 90) . ' Ft kupont, ' . $n('ref_reward_days') . ' napig.</p>'
            . '<p class="description">A link a Fiókom kezdőlapján és a köszönőoldalon (belépett vásárlóknak). Saját magának nem ajánlhat; a jutalom a barát teljesített első rendelése után jár. Eddig kiadott jutalom: ' . $rewards . '.</p></td></tr>'
            . '</table>';
        submit_button('Mentés');
        echo '</form></div>';
    });
});
