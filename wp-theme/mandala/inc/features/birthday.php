<?php
/**
 * Születésnapi kupon: a vásárló megadja a születésnapját (hónap + nap, év nélkül – kevesebb személyes
 * adat), és a napján reggel egyedi, a címéhez kötött kedvezménykupont kap. A leiratkozottak nem kapnak.
 *
 *  - Megadás: Fiókom → Fiókadatok, illetve a pénztárban (nem kötelező mező).
 *  - Tárolás: e-mail-cím → „HH-NN” (vendég vásárlónál is), a WordPress adatexport/-törlés eszközeivel kezelhető.
 *  - Február 29.: nem szökőévben február 28-án megy.
 * Beállítás: WooCommerce → Mandala kuponok; a levél szövege: Mandala levelek → Születésnapi kupon.
 */

defined('ABSPATH') || exit;

const MANDALA_BIRTHDAY_OPTION = 'mandala_birthdays';

function mandala_birthday_get(string $email): string
{
    return (string) (((array) get_option(MANDALA_BIRTHDAY_OPTION, []))[strtolower($email)] ?? '');
}
function mandala_birthday_set(string $email, string $md): void
{
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) {
        return;
    }
    $all = (array) get_option(MANDALA_BIRTHDAY_OPTION, []);
    if ($md === '') {
        unset($all[$email]);
    } else {
        $all[$email] = $md;
    }
    update_option(MANDALA_BIRTHDAY_OPTION, $all, false);
}
function mandala_birthday_count(): int
{
    return count((array) get_option(MANDALA_BIRTHDAY_OPTION, []));
}
/** „HH-NN” a beküldött hónap/nap mezőkből ('' ha nincs vagy érvénytelen). */
function mandala_birthday_from_post(string $prefix): string
{
    $m = absint($_POST[$prefix . '_m'] ?? 0); // phpcs:ignore
    $d = absint($_POST[$prefix . '_d'] ?? 0); // phpcs:ignore
    return $m && $d && checkdate($m, $d, 2024) ? sprintf('%02d-%02d', $m, $d) : '';
}

/** Hónap + nap választó. */
function mandala_birthday_fields(string $prefix, string $value, string $label): string
{
    [$m, $d] = $value ? array_map('intval', explode('-', $value)) : [0, 0];
    $months = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
    $mo = '<option value="">' . esc_html__('hónap', 'mandala') . '</option>';
    foreach ($months as $i => $name) {
        $mo .= '<option value="' . ($i + 1) . '"' . selected($m, $i + 1, false) . '>' . esc_html($name) . '</option>';
    }
    $do = '<option value="">' . esc_html__('nap', 'mandala') . '</option>';
    for ($i = 1; $i <= 31; $i++) {
        $do .= '<option value="' . $i . '"' . selected($d, $i, false) . '>' . $i . '.</option>';
    }
    return '<fieldset class="form-row form-row-wide birthday-field"><legend>' . esc_html($label) . ' <span class="optional">(' . esc_html__('nem kötelező', 'mandala') . ')</span></legend>'
        . '<div class="birthday-selects"><label class="sr-only" for="' . $prefix . '_m">' . esc_html__('Hónap', 'mandala') . '</label><select id="' . $prefix . '_m" name="' . $prefix . '_m">' . $mo . '</select>'
        . '<label class="sr-only" for="' . $prefix . '_d">' . esc_html__('Nap', 'mandala') . '</label><select id="' . $prefix . '_d" name="' . $prefix . '_d">' . $do . '</select></div>'
        . '<p class="description">' . esc_html__('Születésnapodon kedvezménykupont küldünk. Évet nem kérünk.', 'mandala') . '</p></fieldset>';
}

/* ---------- Fiókom → Fiókadatok ---------- */

add_action('woocommerce_edit_account_form', function () {
    if (mandala_growth_settings()['birthday'] !== 'yes') {
        return;
    }
    $u = wp_get_current_user();
    echo mandala_birthday_fields('mandala_bday', mandala_birthday_get($u->user_email), __('Születésnap', 'mandala')); // phpcs:ignore
});
add_action('woocommerce_save_account_details', function ($user_id) {
    if (!isset($_POST['mandala_bday_m'])) { // phpcs:ignore
        return;
    }
    $u = get_userdata($user_id);
    mandala_birthday_set($u->user_email, mandala_birthday_from_post('mandala_bday'));
});

/* ---------- Pénztár ---------- */

add_action('woocommerce_after_order_notes', function () {
    if (mandala_growth_settings()['birthday'] !== 'yes') {
        return;
    }
    $email = is_user_logged_in() ? wp_get_current_user()->user_email : '';
    if ($email && mandala_birthday_get($email)) {
        return; // már megadta
    }
    echo mandala_birthday_fields('mandala_bday', '', __('Mikor van a születésnapod?', 'mandala')); // phpcs:ignore
});
add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $md = mandala_birthday_from_post('mandala_bday');
    $order = wc_get_order($order_id);
    if ($md && $order && !mandala_birthday_get($order->get_billing_email())) {
        mandala_birthday_set($order->get_billing_email(), $md);
    }
});

/* ---------- Napi küldés (reggel) ---------- */

mandala_recurring('mandala_birthday_check', DAY_IN_SECONDS, function () {
    $t = new DateTime('tomorrow 08:00', wp_timezone());
    return $t->getTimestamp();
});
add_action('mandala_birthday_check', 'mandala_birthday_send');

/** Visszaad: az elküldött levelek száma. $today: „HH-NN” (tesztekhez). */
function mandala_birthday_send(string $today = ''): int
{
    $s = mandala_growth_settings();
    if ($s['birthday'] !== 'yes' || !function_exists('mandala_growth_coupon')) {
        return 0;
    }
    $now = new DateTime('now', wp_timezone());
    $today = $today ?: $now->format('m-d');
    $year = $now->format('Y');
    $days = [$today];
    if ($today === '02-28' && !checkdate(2, 29, (int) $year)) {
        $days[] = '02-29';
    }
    $sent_log = (array) get_option('mandala_birthday_sent', []);
    $sent = 0;
    foreach ((array) get_option(MANDALA_BIRTHDAY_OPTION, []) as $email => $md) {
        if (!in_array($md, $days, true) || ($sent_log[$email] ?? '') === $year || mandala_is_unsubscribed($email)) {
            continue;
        }
        $coupon = mandala_growth_coupon('SZULINAP', 'percent', (float) $s['birthday_percent'], (int) $s['birthday_days'], $email, ['_mandala_source' => 'birthday']);
        $user = get_user_by('email', $email);
        $last = $user ? null : wc_get_orders(['billing_email' => $email, 'limit' => 1, 'type' => 'shop_order']);
        $name = $user ? ($user->first_name ?: $user->display_name) : ($last ? $last[0]->get_billing_first_name() : '');
        if (mandala_mail('birthday', $email, ['keresztnev' => $name ?: __('Kedves Vásárlónk', 'mandala'), 'kupon' => strtoupper($coupon->get_code()), 'kupon_ertek' => (int) $s['birthday_percent'] . '%'],
            ['kupon_doboz' => mandala_growth_coupon_box($coupon), 'gomb' => mandala_mail_button(mandala_shop_url(), __('Ajándék magamnak', 'mandala'))])) {
            $sent++;
        }
        $sent_log[$email] = $year;
    }
    update_option('mandala_birthday_sent', $sent_log, false);
    return $sent;
}

/* ---------- Levélsablon ---------- */

add_filter('mandala_mail_types', function ($types) {
    $types['birthday'] = [
        'label' => 'Születésnapi kupon', 'group' => 'Értesítések', 'marketing' => true,
        'is_enabled' => fn() => mandala_growth_settings()['birthday'] === 'yes',
        'when' => 'A vásárló születésnapján reggel, ha megadta (Fiókom vagy pénztár). Évente egyszer; be/ki és a kedvezmény: WooCommerce → Mandala kuponok.',
        'vars' => ['keresztnev' => 'A keresztnév', 'kupon' => 'A kuponkód', 'kupon_ertek' => 'A kedvezmény (pl. 15%)'], 'blocks' => ['kupon_doboz' => 'Kupon doboz', 'gomb' => '„Ajándék magamnak” gomb'],
        'subject' => __('Boldog születésnapot, {keresztnev}!', 'mandala'), 'heading' => __('Boldog születésnapot!', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('Ma rólad szól a nap. Egy kis ajándékkal kívánunk sok csendes, szép pillanatot az új évedre:', 'mandala') . '</p>{kupon_doboz}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna', 'kupon' => 'SZULINAP-MINTA1', 'kupon_ertek' => '15%'], ['kupon_doboz' => '<p style="font-family:monospace;font-size:22px;text-align:center">SZULINAP-MINTA1</p>', 'gomb' => mandala_mail_button(mandala_shop_url(), __('Ajándék magamnak', 'mandala'))]],
    ];
    return $types;
});

/* ---------- Adatvédelem ---------- */

add_filter('wp_privacy_personal_data_erasers', function ($erasers) {
    $erasers['mandala-birthday'] = ['eraser_friendly_name' => 'Mandala – születésnap', 'callback' => function ($email) {
        $had = mandala_birthday_get($email) !== '';
        mandala_birthday_set($email, '');
        return ['items_removed' => $had, 'items_retained' => false, 'messages' => [], 'done' => true];
    }];
    return $erasers;
});
add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['mandala-birthday'] = ['exporter_friendly_name' => 'Mandala – születésnap', 'callback' => function ($email) {
        $md = mandala_birthday_get($email);
        return ['data' => $md ? [['group_id' => 'mandala-birthday', 'group_label' => 'Születésnap', 'item_id' => 'birthday', 'data' => [['name' => 'Születésnap (hónap-nap)', 'value' => $md]]]] : [], 'done' => true];
    }];
    return $exporters;
});
