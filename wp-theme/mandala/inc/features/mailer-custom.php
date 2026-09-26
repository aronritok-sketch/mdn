<?php
/**
 * Saját levelek a levélközpontban: az admin maga hoz létre levelet egy kiváltó eseménnyel (trigger),
 * késleltetéssel, feltételekkel és opcionálisan egyedi, egyszer használható kuponnal.
 *
 *  - Triggerek: új rendelés, fizetés beérkezett, bármely rendelés-állapot, csomag feladva, átvehető,
 *    X nap az utolsó rendelés óta (visszacsábító), fiók regisztráció, hírlevél feliratkozás,
 *    értékelés beküldése.
 *  - Feltételek (rendeléses triggereknél): kategória, cikkszám, minimum összeg, szállítási mód,
 *    fizetési mód, első / visszatérő vásárló, viszonteladó, belföld / külföld.
 *    Küldéskor újra ellenőrizzük (pl. közben visszamondott rendelésre nem megy).
 *  - Címzett: a vásárló, a bolt, vagy megadott címek. Rendelésenként (illetve címenként) egyszer.
 *  - Az ütemezés az Action Scheduleren fut (MANDALA_AS_GROUP), a küldés a közös mandala_mail()-lel
 *    (napló, aláírás, leiratkozás a marketing leveleknél).
 */

defined('ABSPATH') || exit;

function mandala_custom_triggers(): array
{
    $statuses = ['processing' => 'Feldolgozás alatt', 'completed' => 'Teljesítve', 'on-hold' => 'Várakozik (pl. utalásra)', 'cancelled' => 'Visszamondva', 'refunded' => 'Visszatérítve', 'failed' => 'Sikertelen fizetés'];
    $out = [
        'order_new' => ['label' => 'Új rendelés leadásakor', 'ctx' => 'order'],
        'order_paid' => ['label' => 'Fizetés beérkezésekor (kártya, jóváírt utalás)', 'ctx' => 'order'],
    ];
    foreach ($statuses as $key => $label) {
        $out['status_' . $key] = ['label' => 'Rendelés állapota: ' . $label, 'ctx' => 'order'];
    }
    return $out + [
        'shipped' => ['label' => 'Csomag feladva (megjelent a csomagszám)', 'ctx' => 'order'],
        'pickup_ready' => ['label' => 'Személyes átvétel: átvehetőnek jelölve', 'ctx' => 'order'],
        'winback' => ['label' => 'Ennyi nappal az utolsó rendelés után (visszacsábító)', 'ctx' => 'order', 'days' => true],
        'account_created' => ['label' => 'Új vásárlói fiók regisztrációjakor', 'ctx' => 'customer'],
        'newsletter' => ['label' => 'Hírlevél feliratkozáskor', 'ctx' => 'email'],
        'review_submitted' => ['label' => 'Értékelés beküldésekor', 'ctx' => 'review'],
    ];
}

function mandala_custom_mails(): array
{
    return (array) get_option('mandala_custom_mails', []);
}

function mandala_custom_mail_defaults(): array
{
    return [
        'name' => 'Új levél', 'enabled' => 'no', 'trigger' => 'status_completed', 'delay' => 0, 'unit' => 'day',
        'recipient' => 'customer', 'to' => '', 'marketing' => 'no',
        'cond' => ['cats' => '', 'skus' => '', 'min_total' => 0, 'shipping' => '', 'payment' => '', 'customer' => '', 'b2b' => '', 'country' => ''],
        'coupon' => ['type' => '', 'amount' => 10, 'days' => 30],
        'subject' => 'Köszönjük, {keresztnev}!', 'heading' => 'Köszönjük',
        'body' => '<p>Kedves {keresztnev}!</p><p>Ide írd a levél szövegét.</p>{gomb_bolt}',
    ];
}

function mandala_custom_mail(string $id): ?array
{
    $mail = mandala_custom_mails()[$id] ?? null;
    if (!$mail) {
        return null;
    }
    $mail = array_replace_recursive(mandala_custom_mail_defaults(), $mail);
    $mail['id'] = $id;
    return $mail;
}

/** Helyőrzők és blokkok a trigger környezete szerint. */
function mandala_custom_mail_placeholders(string $trigger, array $mail): array
{
    $ctx = mandala_custom_triggers()[$trigger]['ctx'] ?? 'order';
    $vars = ['keresztnev' => 'A vásárló keresztneve', 'email' => 'A vásárló e-mail-címe'];
    $blocks = ['gomb_bolt' => '„Irány a kínálat” gomb'];
    if ($ctx === 'order' || $ctx === 'review') {
        $vars += ['vezeteknev' => 'Vezetéknév', 'rendeles' => 'Rendelésszám', 'osszeg' => 'Végösszeg', 'datum' => 'A rendelés dátuma', 'fizetes_mod' => 'Fizetési mód', 'szallitas_mod' => 'Szállítási mód',
            'csomagszam' => 'GLS csomagszám (ha van)', 'kovetes_link' => 'A követőoldal címe (linkhez)', 'admin_link' => 'A rendelés az adminban (belső levélhez)'];
        $blocks += ['termekek' => 'A rendelt termékek', 'gomb_kovetes' => '„Hol tart a rendelésem?” gomb'];
    }
    if ($ctx === 'review') {
        $vars += ['termek' => 'Az értékelt termék', 'ertekeles' => 'A csillagok (pl. 5/5)'];
    }
    if (!empty($mail['coupon']['type'])) {
        $vars += ['kupon' => 'Az egyedi kuponkód', 'kupon_ertek' => 'A kedvezmény (pl. 10% vagy 2 000 Ft)', 'kupon_lejarat' => 'A kupon lejárata'];
        $blocks += ['kupon_doboz' => 'Kupon doboz (kód, érték, lejárat)'];
    }
    return [$vars, $blocks];
}

/* ---------- A levélközpont regiszterébe ---------- */

add_filter('mandala_mail_types', function ($types) {
    $triggers = mandala_custom_triggers();
    foreach (mandala_custom_mails() as $id => $raw) {
        $mail = mandala_custom_mail((string) $id);
        [$vars, $blocks] = mandala_custom_mail_placeholders($mail['trigger'], $mail);
        $types['custom_' . $id] = [
            'label' => $mail['name'], 'group' => 'Saját levelek', 'custom' => (string) $id,
            'marketing' => $mail['marketing'] === 'yes',
            'is_enabled' => fn() => (mandala_custom_mail((string) $id)['enabled'] ?? 'no') === 'yes',
            'when' => mandala_custom_mail_when($mail),
            'vars' => $vars, 'blocks' => $blocks,
            'subject' => $mail['subject'], 'heading' => $mail['heading'], 'body' => $mail['body'],
            'sample' => fn() => mandala_custom_mail_sample($mail),
        ];
    }
    return $types;
});

function mandala_custom_mail_when(array $mail): string
{
    $t = mandala_custom_triggers()[$mail['trigger']] ?? ['label' => $mail['trigger']];
    $units = ['minute' => 'perc', 'hour' => 'óra', 'day' => 'nap'];
    $when = !empty($t['days']) ? sprintf('%d nappal az utolsó rendelés után', max(1, (int) $mail['delay'])) : $t['label'] . ((int) $mail['delay'] ? sprintf(' + %d %s', $mail['delay'], $units[$mail['unit']] ?? 'nap') : ', azonnal');
    $cond = array_filter((array) $mail['cond']);
    $to = ['customer' => 'vásárlónak', 'admin' => 'a boltnak', 'custom' => (string) $mail['to']][$mail['recipient']] ?? '';
    return $when . ' → ' . $to . ($cond ? ' · ' . count($cond) . ' feltétel' : '') . (!empty($mail['coupon']['type']) ? ' · egyedi kupon' : '');
}

function mandala_custom_mail_sample(array $mail): array
{
    $vars = ['keresztnev' => 'Anna', 'vezeteknev' => 'Kovács', 'email' => 'anna@example.com', 'rendeles' => '1234', 'osszeg' => '24 900 Ft', 'datum' => wp_date('Y. m. d.'),
        'fizetes_mod' => 'Bankkártya', 'szallitas_mod' => 'GLS futárszolgálat', 'csomagszam' => '12345678901', 'kovetes_link' => home_url('/'), 'admin_link' => admin_url(), 'termek' => 'Tibeti hangtál', 'ertekeles' => '5/5'];
    $blocks = ['termekek' => mandala_mail_sample_rows(2, true), 'gomb_kovetes' => mandala_mail_button(home_url('/'), __('Hol tart a rendelésem?', 'mandala')), 'gomb_bolt' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))];
    if (!empty($mail['coupon']['type'])) {
        $coupon = ['code' => 'MND-MINTA1', 'value' => mandala_custom_coupon_value($mail), 'expires' => wp_date('Y. m. d.', time() + (int) $mail['coupon']['days'] * DAY_IN_SECONDS)];
        $vars += ['kupon' => $coupon['code'], 'kupon_ertek' => $coupon['value'], 'kupon_lejarat' => $coupon['expires']];
        $blocks['kupon_doboz'] = mandala_custom_coupon_box($coupon);
    }
    return [$vars, $blocks];
}

/* ---------- Kupon ---------- */

function mandala_custom_coupon_value(array $mail): string
{
    return $mail['coupon']['type'] === 'percent' ? (int) $mail['coupon']['amount'] . '%' : mandala_fmt((float) $mail['coupon']['amount']);
}
function mandala_custom_coupon_box(array $c): string
{
    return '<table role="presentation" style="width:100%;border-collapse:collapse;background:#F6F1E8;border-radius:12px;margin:16px 0"><tr><td style="padding:20px;text-align:center">'
        . '<p style="margin:0;color:#6E6357;font-size:13px;letter-spacing:.08em;text-transform:uppercase">' . esc_html__('Kuponod', 'mandala') . ' · ' . esc_html($c['value']) . '</p>'
        . '<p style="margin:8px 0;font-family:monospace;font-size:22px;letter-spacing:.12em;color:#1C1916">' . esc_html($c['code']) . '</p>'
        . '<p style="margin:0;color:#6E6357;font-size:13px">' . esc_html(sprintf(__('Beváltható %s-ig a pénztárban, a kuponkód mezőben. Egyszer használható.', 'mandala'), $c['expires'])) . '</p></td></tr></table>';
}
/** Egyedi, egyszer használható, a címzetthez kötött kupon. */
function mandala_custom_coupon_create(array $mail, string $email): ?array
{
    if (empty($mail['coupon']['type']) || !class_exists('WC_Coupon')) {
        return null;
    }
    do {
        $code = 'MND-' . strtoupper(wp_generate_password(6, false, false));
    } while (wc_get_coupon_id_by_code($code));
    $expires = time() + max(1, (int) $mail['coupon']['days']) * DAY_IN_SECONDS;
    $coupon = new WC_Coupon();
    $coupon->set_code($code);
    $coupon->set_discount_type($mail['coupon']['type'] === 'percent' ? 'percent' : 'fixed_cart');
    $coupon->set_amount((float) $mail['coupon']['amount']);
    $coupon->set_date_expires($expires);
    $coupon->set_usage_limit(1);
    $coupon->set_email_restrictions([strtolower($email)]);
    $coupon->set_description('Mandala levél: ' . $mail['name']);
    $coupon->save();
    return ['code' => $code, 'value' => mandala_custom_coupon_value($mail), 'expires' => wp_date('Y. m. d.', $expires)];
}

/* ---------- Triggerek ---------- */

function mandala_custom_fire(string $trigger, string $ctx, $ctx_id): void
{
    foreach (mandala_custom_mails() as $id => $raw) {
        $mail = mandala_custom_mail((string) $id);
        if ($mail['enabled'] !== 'yes' || $mail['trigger'] !== $trigger) {
            continue;
        }
        if ($ctx === 'order' && ($order = wc_get_order($ctx_id)) && !mandala_custom_conditions($mail, $order)) {
            continue;
        }
        $mult = ['minute' => MINUTE_IN_SECONDS, 'hour' => HOUR_IN_SECONDS, 'day' => DAY_IN_SECONDS][$mail['unit']] ?? DAY_IN_SECONDS;
        $delay = $trigger === 'winback' ? 0 : max(0, (int) $mail['delay']) * $mult;
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + max(30, $delay), 'mandala_custom_mail', [(string) $id, $ctx, (string) $ctx_id], MANDALA_AS_GROUP);
        }
    }
}

add_action('woocommerce_checkout_order_processed', fn($order_id) => mandala_custom_fire('order_new', 'order', (int) $order_id));
add_action('woocommerce_payment_complete', fn($order_id) => mandala_custom_fire('order_paid', 'order', (int) $order_id));
add_action('woocommerce_order_status_on-hold_to_processing', fn($order_id) => mandala_custom_fire('order_paid', 'order', (int) $order_id));
add_action('woocommerce_order_status_changed', fn($order_id, $from, $to) => mandala_custom_fire('status_' . $to, 'order', (int) $order_id), 10, 3);
add_action('mandala_order_shipped', fn($order) => mandala_custom_fire('shipped', 'order', $order->get_id()));
add_action('mandala_order_pickup_ready', fn($order) => mandala_custom_fire('pickup_ready', 'order', $order->get_id()));
add_action('woocommerce_created_customer', fn($customer_id) => mandala_custom_fire('account_created', 'customer', (int) $customer_id));
add_action('mandala_newsletter_subscribed', fn($email) => mandala_custom_fire('newsletter', 'email', strtolower((string) $email)));
add_action('mandala_review_submitted', fn($review_id) => mandala_custom_fire('review_submitted', 'review', (int) $review_id));

/** Visszacsábító: naponta, akiknek pontosan N napja volt az utolsó (teljesített) rendelése. */
add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_custom_mail_daily', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(strtotime('tomorrow 09:00', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS), DAY_IN_SECONDS, 'mandala_custom_mail_daily', [], MANDALA_AS_GROUP);
    }
}, 30);
add_action('mandala_custom_mail_daily', function () {
    foreach (mandala_custom_mails() as $id => $raw) {
        $mail = mandala_custom_mail((string) $id);
        if ($mail['enabled'] !== 'yes' || $mail['trigger'] !== 'winback') {
            continue;
        }
        $days = max(1, (int) $mail['delay']);
        $from = time() - ($days + 1) * DAY_IN_SECONDS;
        $to = time() - $days * DAY_IN_SECONDS;
        foreach (wc_get_orders(['status' => ['completed'], 'date_created' => $from . '...' . $to, 'limit' => 500, 'type' => 'shop_order']) as $order) {
            $newer = wc_get_orders(['billing_email' => $order->get_billing_email(), 'date_created' => '>' . $order->get_date_created()->getTimestamp(), 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
            if (!$newer && mandala_custom_conditions($mail, $order)) {
                as_schedule_single_action(time() + 60, 'mandala_custom_mail', [(string) $id, 'order', (string) $order->get_id()], MANDALA_AS_GROUP);
            }
        }
    }
});

/* ---------- Feltételek ---------- */

function mandala_custom_conditions(array $mail, WC_Order $order): bool
{
    $c = (array) $mail['cond'];
    $list = fn($s) => array_filter(array_map('trim', explode(',', strtolower((string) $s))));
    if ($cats = $list($c['cats'] ?? '')) {
        $hit = false;
        foreach ($order->get_items() as $item) {
            if (has_term($cats, 'product_cat', $item->get_product_id())) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    if ($skus = $list($c['skus'] ?? '')) {
        $hit = false;
        foreach ($order->get_items() as $item) {
            $p = $item->get_product();
            if ($p && in_array(strtolower($p->get_sku()), $skus, true)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            return false;
        }
    }
    if ((float) ($c['min_total'] ?? 0) > 0 && (float) $order->get_total() < (float) $c['min_total']) {
        return false;
    }
    if (!empty($c['shipping']) && function_exists('mandala_order_shipping_kind') && mandala_order_shipping_kind($order) !== $c['shipping']) {
        return false;
    }
    if (!empty($c['payment']) && $order->get_payment_method() !== $c['payment']) {
        return false;
    }
    if (!empty($c['country'])) {
        $hu = strtoupper($order->get_shipping_country() ?: $order->get_billing_country()) === 'HU';
        if (($c['country'] === 'hu') !== $hu) {
            return false;
        }
    }
    if (!empty($c['b2b']) && function_exists('mandala_is_wholesale_user')) {
        $is = $order->get_customer_id() && mandala_is_wholesale_user((int) $order->get_customer_id());
        if (($c['b2b'] === 'yes') !== $is) {
            return false;
        }
    }
    if (!empty($c['customer'])) {
        $earlier = wc_get_orders(['billing_email' => $order->get_billing_email(), 'status' => ['processing', 'completed', 'on-hold'], 'date_created' => '<' . $order->get_date_created()->getTimestamp(), 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order', 'exclude' => [$order->get_id()]]);
        if (($c['customer'] === 'first') === (bool) $earlier) {
            return false;
        }
    }
    return true;
}

/* ---------- Küldés ---------- */

add_action('mandala_custom_mail', function ($id, $ctx, $ctx_id) {
    global $wpdb;
    $mail = mandala_custom_mail((string) $id);
    if (!$mail || $mail['enabled'] !== 'yes') {
        return;
    }
    $type = 'custom_' . $id;
    $order = null;
    $vars = [];
    $blocks = ['gomb_bolt' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))];
    $email = '';
    if ($ctx === 'review') {
        $review = get_post((int) $ctx_id);
        $order = $review ? wc_get_order((int) get_post_meta($review->ID, '_order', true)) : null;
        $pid = $review ? (int) get_post_meta($review->ID, '_product', true) : 0;
        if ($pid && ($p = wc_get_product($pid))) {
            $vars += ['termek' => $p->get_name(), 'ertekeles' => (int) get_post_meta($review->ID, '_rating', true) . '/5'];
            $blocks['termek_sor'] = mandala_mail_product_row($p);
        }
    } elseif ($ctx === 'order') {
        $order = wc_get_order((int) $ctx_id);
        if (!$order) {
            return;
        }
        // Közben visszamondták / meghiúsult: nem küldjük (kivéve, ha épp erre szól a levél).
        $bad = ['cancelled', 'refunded', 'failed'];
        if (in_array($order->get_status(), $bad, true) && $mail['trigger'] !== 'status_' . $order->get_status()) {
            return;
        }
        if (!mandala_custom_conditions($mail, $order)) {
            return;
        }
    } elseif ($ctx === 'customer') {
        $user = get_userdata((int) $ctx_id);
        if (!$user) {
            return;
        }
        $email = $user->user_email;
        $vars += ['keresztnev' => $user->first_name ?: __('Vásárlónk', 'mandala')];
    } else {
        $email = sanitize_email((string) $ctx_id);
        $vars += ['keresztnev' => __('Vásárlónk', 'mandala')];
    }
    if ($order) {
        $tracking = function_exists('mandala_order_tracking') ? mandala_order_tracking($order) : [];
        $email = $order->get_billing_email();
        $vars += mandala_mail_order_vars($order) + [
            'vezeteknev' => $order->get_billing_last_name(), 'osszeg' => wp_strip_all_tags(wc_price($order->get_total())), 'datum' => $order->get_date_created() ? wp_date('Y. m. d.', $order->get_date_created()->getTimestamp()) : '',
            'fizetes_mod' => $order->get_payment_method_title(), 'szallitas_mod' => $order->get_shipping_method(), 'csomagszam' => implode(', ', array_column($tracking, 'number')),
            'kovetes_link' => function_exists('mandala_tracking_url') ? mandala_tracking_url($order) : $order->get_view_order_url(), 'admin_link' => $order->get_edit_order_url(),
        ];
        $blocks += ['termekek' => function_exists('mandala_tracking_items_html') ? mandala_tracking_items_html($order) : '', 'gomb_kovetes' => mandala_mail_button($vars['kovetes_link'], __('Hol tart a rendelésem?', 'mandala'))];
    }
    $vars += ['email' => $email];
    $recipients = $mail['recipient'] === 'admin' ? [(string) (mandala_config('contact', [])['email'] ?? get_option('admin_email'))]
        : ($mail['recipient'] === 'custom' ? array_filter(array_map('trim', explode(',', (string) $mail['to'])), 'is_email') : [$email]);
    $order_id = $order ? $order->get_id() : 0;
    foreach ($recipients as $to) {
        // Egyszer: rendelésenként, illetve (rendelés nélkül) címenként; a visszacsábító fél évente.
        $since = $mail['trigger'] === 'winback' ? gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS) : '1970-01-01 00:00:00';
        $dupe = $order_id && $mail['trigger'] !== 'winback'
            ? $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . mandala_mail_table() . " WHERE type = %s AND order_id = %d AND recipient = %s AND status = 'sent' LIMIT 1", $type, $order_id, strtolower($to)))
            : $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . mandala_mail_table() . " WHERE type = %s AND recipient = %s AND status = 'sent' AND created > %s LIMIT 1", $type, strtolower($to), $since));
        if ($dupe || !is_email($to)) {
            continue;
        }
        $v = $vars;
        $b = $blocks;
        if (!empty($mail['coupon']['type']) && $mail['recipient'] !== 'admin' && ($coupon = mandala_custom_coupon_create($mail, $to))) {
            $v += ['kupon' => $coupon['code'], 'kupon_ertek' => $coupon['value'], 'kupon_lejarat' => $coupon['expires']];
            $b['kupon_doboz'] = mandala_custom_coupon_box($coupon);
        }
        if ($order) {
            do_action('mandala_before_order_mail', $order);
        }
        mandala_mail($type, $to, $v, $b, $order_id);
    }
}, 10, 3);

/* ---------- Admin: létrehozás, törlés, a szerkesztő extra mezői ---------- */

add_action('admin_init', function () {
    if (($_GET['page'] ?? '') !== 'mandala-automations' || !current_user_can('manage_woocommerce')) {
        return;
    }
    $base = admin_url('admin.php?page=mandala-automations');
    if (($_GET['type'] ?? '') === 'new' && check_admin_referer('mandala_mail_new')) {
        $all = mandala_custom_mails();
        $id = strtolower(wp_generate_password(6, false, false));
        $all[$id] = mandala_custom_mail_defaults();
        update_option('mandala_custom_mails', $all, false);
        wp_safe_redirect(add_query_arg('type', 'custom_' . $id, $base));
        exit;
    }
    if (!empty($_POST['mail_delete']) && str_starts_with((string) ($_GET['type'] ?? ''), 'custom_') && check_admin_referer('mandala_mail_edit')) {
        $all = mandala_custom_mails();
        unset($all[substr(sanitize_key($_GET['type']), 7)]);
        update_option('mandala_custom_mails', $all, false);
        wp_safe_redirect(add_query_arg('deleted', 1, $base));
        exit;
    }
    if (!empty($_POST['mail_duplicate']) && str_starts_with((string) ($_GET['type'] ?? ''), 'custom_') && check_admin_referer('mandala_mail_edit')) {
        $all = mandala_custom_mails();
        $src = $all[substr(sanitize_key($_GET['type']), 7)] ?? null;
        if ($src) {
            $id = strtolower(wp_generate_password(6, false, false));
            $all[$id] = ['name' => $src['name'] . ' (másolat)', 'enabled' => 'no'] + $src;
            update_option('mandala_custom_mails', $all, false);
            wp_safe_redirect(add_query_arg('type', 'custom_' . $id, $base));
            exit;
        }
    }
});

/** A saját levél mentése a szerkesztőből (a szövegekkel együtt). */
function mandala_custom_mail_save(string $id, array $posted): void
{
    $all = mandala_custom_mails();
    $in = (array) wp_unslash($_POST['cm'] ?? []);
    $cond = (array) ($in['cond'] ?? []);
    $coupon = (array) ($in['coupon'] ?? []);
    $triggers = mandala_custom_triggers();
    $all[$id] = [
        'name' => sanitize_text_field($in['name'] ?? '') ?: 'Saját levél',
        'enabled' => empty($_POST['mail_enabled']) ? 'no' : 'yes',
        'trigger' => isset($triggers[$in['trigger'] ?? '']) ? $in['trigger'] : 'status_completed',
        'delay' => max(0, (int) ($in['delay'] ?? 0)),
        'unit' => in_array($in['unit'] ?? '', ['minute', 'hour', 'day'], true) ? $in['unit'] : 'day',
        'recipient' => in_array($in['recipient'] ?? '', ['customer', 'admin', 'custom'], true) ? $in['recipient'] : 'customer',
        'to' => implode(', ', array_filter(array_map('sanitize_email', explode(',', (string) ($in['to'] ?? ''))))),
        'marketing' => empty($in['marketing']) ? 'no' : 'yes',
        'cond' => [
            'cats' => sanitize_text_field($cond['cats'] ?? ''), 'skus' => sanitize_text_field($cond['skus'] ?? ''), 'min_total' => max(0, (int) ($cond['min_total'] ?? 0)),
            'shipping' => in_array($cond['shipping'] ?? '', ['courier', 'point', 'pickup'], true) ? $cond['shipping'] : '',
            'payment' => sanitize_key($cond['payment'] ?? ''),
            'customer' => in_array($cond['customer'] ?? '', ['first', 'returning'], true) ? $cond['customer'] : '',
            'b2b' => in_array($cond['b2b'] ?? '', ['yes', 'no'], true) ? $cond['b2b'] : '',
            'country' => in_array($cond['country'] ?? '', ['hu', 'abroad'], true) ? $cond['country'] : '',
        ],
        'coupon' => ['type' => in_array($coupon['type'] ?? '', ['percent', 'fixed_cart'], true) ? $coupon['type'] : '', 'amount' => max(1, (float) ($coupon['amount'] ?? 10)), 'days' => max(1, (int) ($coupon['days'] ?? 30))],
        'subject' => $posted['subject'] ?: 'Levél', 'heading' => $posted['heading'], 'body' => $posted['body'],
    ];
    update_option('mandala_custom_mails', $all, false);
    foreach (['subject', 'heading', 'body'] as $field) {
        do_action('wpml_register_single_string', 'mandala-mail', 'custom_' . $id . ':' . $field, $all[$id][$field]);
    }
}

/** A saját levél „Mikor és kinek” mezői a szerkesztő tetején. */
function mandala_custom_mail_fields(string $id): void
{
    $m = mandala_custom_mail($id);
    $sel = fn($name, $options, $value, $label = '') => '<select name="cm[' . $name . ']"' . ($label ? ' aria-label="' . esc_attr($label) . '"' : '') . '>' . implode('', array_map(fn($k, $v) => '<option value="' . esc_attr((string) $k) . '"' . selected((string) $value, (string) $k, false) . '>' . esc_html($v) . '</option>', array_keys($options), $options)) . '</select>';
    $triggers = array_map(fn($t) => $t['label'], mandala_custom_triggers());
    $gateways = ['' => 'bármely'];
    foreach (function_exists('WC') ? WC()->payment_gateways()->payment_gateways() : [] as $g) {
        if ($g->enabled === 'yes') {
            $gateways[$g->id] = $g->get_title();
        }
    }
    $c = $m['cond'];
    echo '<tr><th scope="row"><label for="cm-name">Név</label></th><td><input type="text" id="cm-name" name="cm[name]" value="' . esc_attr($m['name']) . '" class="regular-text"><p class="description">Csak az adminban látszik.</p></td></tr>'
        . '<tr><th scope="row"><label for="cm-trigger">Mikor menjen?</label></th><td>' . str_replace('<select ', '<select id="cm-trigger" ', $sel('trigger', $triggers, $m['trigger']))
        . '<p style="margin-top:8px"><label>Késleltetés: <input type="number" min="0" name="cm[delay]" value="' . (int) $m['delay'] . '" style="width:80px"></label> ' . $sel('unit', ['minute' => 'perc', 'hour' => 'óra', 'day' => 'nap'], $m['unit'], 'Egység')
        . '</p><p class="description">0 = azonnal (1 percen belül). A visszacsábítónál ez a napok száma az utolsó rendelés óta.</p></td></tr>'
        . '<tr><th scope="row">Kinek?</th><td>' . $sel('recipient', ['customer' => 'a vásárlónak', 'admin' => 'a boltnak (belső értesítés)', 'custom' => 'ezekre a címekre:'], $m['recipient'], 'Címzett')
        . ' <input type="text" name="cm[to]" value="' . esc_attr($m['to']) . '" class="regular-text" placeholder="raktar@mandala.hu, …" aria-label="Címek"' . ($m['recipient'] === 'custom' ? '' : ' hidden') . '>'
        . '<script>document.querySelector(\'select[name="cm[recipient]"]\').addEventListener("change", (e) => { document.querySelector(\'input[name="cm[to]"]\').hidden = e.target.value !== "custom"; });</script>'
        . '<p><label><input type="checkbox" name="cm[marketing]" value="1"' . checked($m['marketing'], 'yes', false) . '> marketing jellegű (leiratkozó link, leiratkozottnak nem megy)</label></p></td></tr>'
        . '<tr><th scope="row">Feltételek</th><td><div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 16px;max-width:640px">'
        . '<label>Kategória (slug, vesszővel)<br><input type="text" name="cm[cond][cats]" value="' . esc_attr($c['cats']) . '" class="widefat" placeholder="hangtalak"></label>'
        . '<label>Cikkszám (vesszővel)<br><input type="text" name="cm[cond][skus]" value="' . esc_attr($c['skus']) . '" class="widefat"></label>'
        . '<label>Minimum végösszeg (Ft)<br><input type="number" min="0" step="500" name="cm[cond][min_total]" value="' . (int) $c['min_total'] . '" class="widefat"></label>'
        . '<label>Szállítás<br>' . $sel('cond][shipping', ['' => 'bármely', 'courier' => 'GLS futár', 'point' => 'GLS pont / automata', 'pickup' => 'személyes átvétel'], $c['shipping']) . '</label>'
        . '<label>Fizetés<br>' . $sel('cond][payment', $gateways, $c['payment']) . '</label>'
        . '<label>Vásárló<br>' . $sel('cond][customer', ['' => 'bármely', 'first' => 'első rendelése', 'returning' => 'visszatérő'], $c['customer']) . '</label>'
        . '<label>Viszonteladó<br>' . $sel('cond][b2b', ['' => 'bármely', 'yes' => 'csak viszonteladó', 'no' => 'csak nem viszonteladó'], $c['b2b']) . '</label>'
        . '<label>Ország<br>' . $sel('cond][country', ['' => 'bármely', 'hu' => 'Magyarország', 'abroad' => 'külföld'], $c['country']) . '</label>'
        . '</div><p class="description">Rendeléshez kötött triggereknél; mind teljesüljön. Küldéskor újra ellenőrizzük (pl. visszamondott rendelésre nem megy).</p></td></tr>'
        . '<tr><th scope="row">Egyedi kupon</th><td>' . $sel('coupon][type', ['' => 'nincs', 'percent' => 'százalékos', 'fixed_cart' => 'fix összeg (Ft)'], $m['coupon']['type'], 'Kupon típusa')
        . ' <input type="number" min="1" name="cm[coupon][amount]" value="' . esc_attr((string) $m['coupon']['amount']) . '" style="width:90px" aria-label="Kedvezmény"> '
        . '<label>érvényes <input type="number" min="1" name="cm[coupon][days]" value="' . (int) $m['coupon']['days'] . '" style="width:70px"> napig</label>'
        . '<p class="description">Minden levélhez új, egyszer használható, a címzett e-mail-címéhez kötött kupon. A szövegbe: {kupon_doboz} vagy {kupon}. (Mentés után jelennek meg a helyőrzők között.)</p></td></tr>';
}
