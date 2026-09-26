<?php
/**
 * Levélközpont (WooCommerce → Mandala levelek): a téma minden automata levele egy helyen.
 *
 *  - Levelenként szerkeszthető tárgy, címsor és szöveg (vizuális szerkesztő), helyőrzőkkel
 *    ({keresztnev}, {rendeles}…) és tartalomblokkokkal ({termekek}, {gomb}…), amelyeket a rendszer tölt ki.
 *  - Be/ki kapcsolás és időzítés, élő előnézet mintaadatokkal, tesztlevél, alaphelyzet.
 *  - Napló: minden kiküldött levél (kinek, mikor, melyik, sikerült-e) – ügyfélszolgálathoz; 180 napig.
 *  - A WooCommerce saját rendszerlevelei (visszaigazolás, teljesítés…) listája, a beállításaikra linkelve.
 *  - Leiratkozás a marketing jellegű levelekből (List-Unsubscribe fejléccel), válaszcím a bolt címe.
 *
 * Új levél: mandala_mail_types() bejegyzés + mandala_mail('azonosito', $cimzett, $valtozok, $blokkok).
 */

defined('ABSPATH') || exit;

const MANDALA_MAIL_DB_VERSION = '1';

/** Az automatizmusok és a levelek általános beállításai (alapértékekkel). */
function mandala_automation_settings(): array
{
    return wp_parse_args((array) get_option('mandala_automations', []), [
        'abandoned' => 'yes', 'abandoned_hours' => 3,
        'care' => 'yes', 'care_days' => 2,
        'reorder' => 'yes', 'reorder_days' => 40, 'reorder_cats' => 'fustolok',
        'review' => 'yes', 'review_days' => 10,
        'moderator' => '',
        'signature' => "Szeretettel:\na Mandala csapata",
    ]);
}
function mandala_automation_on(string $key): bool
{
    return (mandala_automation_settings()[$key] ?? 'no') === 'yes';
}

/* ---------- Leiratkozás ---------- */

function mandala_token(string ...$parts): string
{
    return substr(hash_hmac('sha256', implode('|', $parts), wp_salt('auth')), 0, 32);
}
function mandala_unsubscribe_url(string $email): string
{
    return add_query_arg(['mandala_unsub' => rawurlencode(strtolower($email)), 'k' => mandala_token('unsub', strtolower($email))], home_url('/'));
}
function mandala_is_unsubscribed(string $email): bool
{
    return in_array(strtolower($email), (array) get_option('mandala_unsubscribed', []), true);
}
add_action('template_redirect', function () {
    if (empty($_GET['mandala_unsub']) || empty($_GET['k'])) {
        return;
    }
    $email = strtolower(sanitize_email(wp_unslash($_GET['mandala_unsub'])));
    if (!hash_equals(mandala_token('unsub', $email), sanitize_text_field(wp_unslash($_GET['k'])))) {
        wp_die(esc_html__('Érvénytelen leiratkozó link.', 'mandala'), '', ['response' => 400]);
    }
    $list = (array) get_option('mandala_unsubscribed', []);
    $list[] = $email;
    update_option('mandala_unsubscribed', array_values(array_unique($list)), false);
    $subs = (array) get_option('mandala_newsletter', []);
    unset($subs[$email]);
    update_option('mandala_newsletter', $subs, false);
    wp_die('<h1>' . esc_html__('Leiratkoztál', 'mandala') . '</h1><p>' . esc_html__('Több emlékeztetőt és hírlevelet nem küldünk erre a címre. A rendeléseidről szóló értesítéseket továbbra is megkapod.', 'mandala') . '</p><p><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Vissza a webáruházba', 'mandala') . '</a></p>', esc_html__('Leiratkozás', 'mandala'), ['response' => 200]);
});

/* ---------- Napló ---------- */

function mandala_mail_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_mail_log';
}
add_action('init', function () {
    if (get_option('mandala_mail_db') === MANDALA_MAIL_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mandala_mail_table() . " (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        created datetime NOT NULL,
        type varchar(40) NOT NULL default '',
        recipient varchar(190) NOT NULL default '',
        subject varchar(255) NOT NULL default '',
        status varchar(20) NOT NULL default '',
        order_id bigint unsigned NOT NULL default 0,
        PRIMARY KEY  (id),
        KEY created (created),
        KEY recipient (recipient),
        KEY order_id (order_id)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('mandala_mail_db', MANDALA_MAIL_DB_VERSION);
});
add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_mail_cleanup', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'mandala_mail_cleanup', [], MANDALA_AS_GROUP);
    }
}, 30);
add_action('mandala_mail_cleanup', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . mandala_mail_table() . ' WHERE created < %s', gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS)));
});

function mandala_mail_log(string $type, string $to, string $subject, string $status, int $order_id = 0): void
{
    global $wpdb;
    if (get_option('mandala_mail_db') !== MANDALA_MAIL_DB_VERSION) {
        return;
    }
    $wpdb->insert(mandala_mail_table(), ['created' => current_time('mysql', true), 'type' => mb_substr($type, 0, 40), 'recipient' => mb_substr(strtolower($to), 0, 190),
        'subject' => mb_substr($subject, 0, 255), 'status' => $status, 'order_id' => $order_id]);
}

/* ---------- Küldés ---------- */

/**
 * Levél küldése a WooCommerce sablonjában. $marketing = true esetén leiratkozott címre nem
 * megy, és a lábléc leiratkozó linket kap. $meta: ['type' => naplóazonosító, 'order' => rendelés id].
 */
function mandala_send_mail(string $to, string $subject, string $heading, string $body_html, bool $marketing = false, array $meta = []): bool
{
    $type = (string) ($meta['type'] ?? 'egyeb');
    $order_id = (int) ($meta['order'] ?? 0);
    if (!is_email($to)) {
        return false;
    }
    if ($marketing && mandala_is_unsubscribed($to)) {
        mandala_mail_log($type, $to, $subject, 'unsubscribed', $order_id);
        return false;
    }
    if ($marketing) {
        $body_html .= '<p style="font-size:12px;color:#6E6357;margin-top:32px">' . sprintf(
            esc_html__('Ezt a levelet a vásárlásod miatt kaptad. %s', 'mandala'),
            '<a href="' . esc_url(mandala_unsubscribe_url($to)) . '" style="color:#6E6357">' . esc_html__('Leiratkozás az emlékeztetőkről', 'mandala') . '</a>'
        ) . '</p>';
    }
    $mailer = function_exists('WC') ? WC()->mailer() : null;
    $message = $mailer ? $mailer->wrap_message($heading, $body_html) : '<h1>' . esc_html($heading) . '</h1>' . $body_html;
    if ($mailer) {
        $email = new WC_Email();
        $message = $email->style_inline($message);
    }
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $reply = (string) (mandala_config('contact', [])['email'] ?? '');
    if (is_email($reply) && strtolower($reply) !== strtolower($to)) {
        $headers[] = 'Reply-To: ' . $reply;
    }
    if ($marketing) {
        $headers[] = 'List-Unsubscribe: <' . mandala_unsubscribe_url($to) . '>';
    }
    $sent = (bool) wp_mail($to, $subject, $message, $headers);
    mandala_mail_log($type, $to, $subject, $sent ? 'sent' : 'failed', $order_id);
    return $sent;
}

/** Termék sor a levelekben (kép, név, ár, link). */
function mandala_mail_product_row(WC_Product $product, string $extra = '', string $url = ''): string
{
    $img = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : mandala_art_url($product);
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;margin:0 0 16px;border-collapse:collapse"><tr>'
        . '<td style="width:72px;padding:0 16px 0 0;vertical-align:top"><img src="' . esc_url($img) . '" width="72" height="72" alt="" style="border-radius:8px;display:block;background:#EEE7DB"></td>'
        . '<td style="vertical-align:top"><a href="' . esc_url($url ?: $product->get_permalink()) . '" style="color:#1C1916;font-weight:600;text-decoration:none">' . esc_html($product->get_name()) . '</a>'
        . ($extra ? '<div style="color:#6E6357;font-size:14px;margin-top:4px">' . $extra . '</div>' : '') . '</td></tr></table>';
}

function mandala_mail_button(string $url, string $label): string
{
    return '<p style="margin:24px 0"><a href="' . esc_url($url) . '" style="display:inline-block;background:#1C1916;color:#FFFFFF;padding:14px 28px;border-radius:999px;text-decoration:none;font-weight:600">' . esc_html($label) . '</a></p>';
}

/* ---------- Sablonok ---------- */

/**
 * A szerkeszthető levelek. Kulcsok: label, group, when (mikor megy – szöveg vagy callable a
 * beállításokkal), setting (az automatizmus kapcsolója, ha van), delay ([beállítás, egység]),
 * required (nem kapcsolható ki), marketing, vars / blocks (helyőrzők leírással), subject, heading,
 * body (alapszöveg), sample (mintaadat az előnézethez: [vars, blocks]).
 */
function mandala_mail_types(): array
{
    static $types = null;
    if ($types !== null) {
        return $types;
    }
    $hello = '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p>';
    $name = ['keresztnev' => 'A vásárló keresztneve'];
    $order = ['rendeles' => 'Rendelésszám'];
    $types = [
        'abandoned' => [
            'label' => 'Elhagyott kosár', 'group' => 'Emlékeztetők', 'setting' => 'abandoned', 'delay' => ['abandoned_hours', 'óra'], 'marketing' => true,
            'when' => fn($s) => sprintf('%d órával azután, hogy a vásárló a pénztárban megadta az e-mail-címét, de nem rendelt. Egyszer.', $s['abandoned_hours']),
            'vars' => ['keresztnev' => 'A vásárló keresztneve (ha megadta; különben „Vásárlónk”)'],
            'blocks' => ['termekek' => 'A kosárban maradt termékek', 'gomb' => '„Rendelés folytatása” gomb (visszaállítja a kosarat)'],
            'subject' => __('A kosarad vár rád', 'mandala'), 'heading' => __('Félretettük neked', 'mandala'),
            'body' => $hello . '<p>' . __('Úgy láttuk, a kosaradban maradt néhány darab. Félretettük neked – egy kattintással folytathatod a rendelést.', 'mandala') . '</p>{termekek}{gomb}<p>' . __('Kérdésed van a termékekről? Válaszolj erre a levélre, szívesen segítünk.', 'mandala') . '</p>',
            'sample' => fn() => [['keresztnev' => 'Anna'], ['termekek' => mandala_mail_sample_rows(2, true), 'gomb' => mandala_mail_button(wc_get_checkout_url(), __('Rendelés folytatása', 'mandala'))]],
        ],
        'care' => [
            'label' => 'Használati útmutató', 'group' => 'Rendelés után', 'setting' => 'care', 'delay' => ['care_days', 'nap'],
            'when' => fn($s) => sprintf('A rendelés teljesítése után %d nappal, ha a termékeknek van „Használat és gondozás” szövege.', $s['care_days']),
            'vars' => $name + $order, 'blocks' => ['termekek' => 'A termékek a használati és gondozási szövegükkel'],
            'subject' => __('Így használd és gondozd', 'mandala'), 'heading' => __('Használat és gondozás', 'mandala'),
            'body' => $hello . '<p>' . __('Reméljük, már megérkezett és jó helyre került, amit tőlünk választottál. Összegyűjtöttük, hogyan érdemes használni és gondozni, hogy sokáig örömöd legyen benne.', 'mandala') . '</p>{termekek}',
            'sample' => fn() => [['keresztnev' => 'Anna', 'rendeles' => '1234'], ['termekek' => mandala_mail_sample_rows(1, false, 'Ütögesd meg finoman a peremét, majd lassan körözz a fával. Puha, száraz ruhával töröld át.')]],
        ],
        'review' => [
            'label' => 'Értékelés kérése', 'group' => 'Rendelés után', 'setting' => 'review', 'delay' => ['review_days', 'nap'], 'marketing' => true,
            'when' => fn($s) => sprintf('A rendelés teljesítése után %d nappal; termékenként személyes, ellenőrzött értékelő link.', $s['review_days']),
            'vars' => $name + $order, 'blocks' => ['termekek' => 'A megvásárolt termékek „Értékelem →” linkkel'],
            'subject' => __('Milyen lett? Mondd el másoknak is', 'mandala'), 'heading' => __('Értékeld a vásárlásod', 'mandala'),
            'body' => $hello . '<p>' . __('Hogy tetszik, amit tőlünk választottál? Egy-két mondat és egy fotó sokat segít azoknak, akik még döntenek – és nekünk is, hogy jól válogassunk.', 'mandala') . '</p>{termekek}',
            'sample' => fn() => [['keresztnev' => 'Anna', 'rendeles' => '1234'], ['termekek' => mandala_mail_sample_rows(2, false, '<a href="#" style="color:#8A4512">' . esc_html__('Értékelem →', 'mandala') . '</a>')]],
        ],
        'reorder' => [
            'label' => 'Újrarendelés emlékeztető', 'group' => 'Emlékeztetők', 'setting' => 'reorder', 'delay' => ['reorder_days', 'nap'], 'marketing' => true,
            'when' => fn($s) => sprintf('A teljesítés után %d nappal, ha a rendelésben fogyóeszköz volt (%s).', $s['reorder_days'], $s['reorder_cats']),
            'vars' => $name + $order, 'blocks' => ['termekek' => 'A fogyóeszközök, egy kattintásos kosárba tétellel', 'gomb' => '„Új illatok” gomb (a kategóriára)'],
            'subject' => __('Fogytán a füstölő?', 'mandala'), 'heading' => __('Újrarendelés egy kattintással', 'mandala'),
            'body' => $hello . '<p>' . __('Talán már fogytán a füstölőd. Ha jólesett, egy kattintással újrarendelheted – vagy nézd meg az új illatokat.', 'mandala') . '</p>{termekek}{gomb}',
            'sample' => fn() => [['keresztnev' => 'Anna', 'rendeles' => '1234'], ['termekek' => mandala_mail_sample_rows(1, true), 'gomb' => mandala_mail_button(mandala_shop_url(), __('Új illatok', 'mandala'))]],
        ],
        'stock_back' => [
            'label' => 'Újra raktáron', 'group' => 'Értesítések', 'when' => 'Amikor a termék újra raktárra kerül, azoknak, akik a termékoldalon feliratkoztak rá. Egyszer.',
            'vars' => ['termek' => 'A termék neve'], 'blocks' => ['termek_sor' => 'A termék képpel és árral', 'gomb' => '„Megnézem” gomb'],
            'subject' => __('Újra raktáron: {termek}', 'mandala'), 'heading' => __('Újra elérhető', 'mandala'),
            'body' => '<p>' . __('Kedves Vásárlónk!', 'mandala') . '</p><p>' . __('Jó hírünk van: a(z) {termek} újra elérhető webáruházunkban.', 'mandala') . '</p>{termek_sor}{gomb}<p>' . __('A készlet korlátozott, ezért érdemes hamar lecsapni rá.', 'mandala') . '</p>',
            'sample' => function () {
                $p = mandala_mail_sample_products(1)[0] ?? null;
                return [['termek' => $p ? $p->get_name() : 'Hangtál'], ['termek_sor' => mandala_mail_sample_rows(1, true), 'gomb' => mandala_mail_button($p ? $p->get_permalink() : home_url('/'), __('Megnézem', 'mandala'))]];
            },
        ],
        'voucher_buyer' => [
            'label' => 'Ajándékutalvány – vásárlónak', 'group' => 'Ajándék', 'required' => true,
            'when' => 'Az utalvány rendelésének teljesítésekor (vagy a kártyás fizetés után). Mindig megy – ez maga az utalvány.',
            'vars' => $name + ['cimzett' => 'A címzett e-mail-címe (ha megadta)'],
            'blocks' => ['cimzett_info' => 'Mondat arról, hogy a címzett is megkapta (ha van címzett)', 'utalvany' => 'Az utalvány kártya (összeg, kód, érvényesség)', 'uzenet' => 'A vásárló üzenete', 'gombok' => '„Irány a kínálat” gomb és a nyomtatható változat linkje'],
            'subject' => __('Az ajándékutalványod', 'mandala'), 'heading' => __('Itt az ajándékutalvány', 'mandala'),
            'body' => $hello . '<p>' . __('Köszönjük! Itt az utalvány – továbbküldheted vagy kinyomtathatod.', 'mandala') . '</p>{cimzett_info}{utalvany}{uzenet}{gombok}',
            'sample' => fn() => [['keresztnev' => 'Anna', 'cimzett' => 'kata@example.com'], mandala_mail_sample_voucher() + ['cimzett_info' => '<p>' . esc_html(sprintf(__('Az utalványt elküldtük %s részére is.', 'mandala'), 'kata@example.com')) . '</p>']],
        ],
        'voucher_recipient' => [
            'label' => 'Ajándékutalvány – címzettnek', 'group' => 'Ajándék', 'required' => true,
            'when' => 'Ha a vásárló megadta a megajándékozott e-mail-címét: vele együtt, a vásárló levelével egy időben.',
            'vars' => ['cimzett_nev' => 'A megajándékozott neve (különben „Címzett”)', 'kuldo' => 'A vásárló neve', 'kuldo_keresztnev' => 'A vásárló keresztneve'],
            'blocks' => ['utalvany' => 'Az utalvány kártya', 'uzenet' => 'A vásárló üzenete', 'gombok' => 'Gomb és nyomtatható változat'],
            'subject' => __('Ajándékot kaptál {kuldo}-tól', 'mandala'), 'heading' => __('Ajándékot kaptál', 'mandala'),
            'body' => '<p>' . __('Kedves {cimzett_nev}!', 'mandala') . '</p><p>' . __('{kuldo_keresztnev} ajándékutalványt küldött neked a Mandala webáruházba.', 'mandala') . '</p>{utalvany}{uzenet}{gombok}',
            'sample' => fn() => [['cimzett_nev' => 'Kata', 'kuldo' => 'Kovács Anna', 'kuldo_keresztnev' => 'Anna'], mandala_mail_sample_voucher()],
        ],
        'b2b_weekly' => [
            'label' => 'Heti új érkezések (viszonteladó)', 'group' => 'Viszonteladó',
            'when' => 'Hetente, ha volt új termék – a viszonteladóknak, akik a felületükön bekapcsolták. Nagyker árral.',
            'vars' => $name + ['db' => 'Az új termékek száma'], 'blocks' => ['termekek' => 'Az új termékek cikkszámmal és nagyker árral', 'gomb' => '„Gyorsrendelés” gomb'],
            'subject' => __('Új érkezések a Mandalánál', 'mandala'), 'heading' => __('Új érkezések', 'mandala'),
            'body' => $hello . '<p>' . __('Ezen a héten {db} új termék érkezett.', 'mandala') . '</p>{termekek}{gomb}<p style="font-size:12px;color:#6E6357">' . __('A levelet a viszonteladói felületen kapcsoltad be; ugyanott ki is kapcsolhatod.', 'mandala') . '</p>',
            'sample' => fn() => [['keresztnev' => 'Péter', 'db' => '3'], ['termekek' => mandala_mail_sample_rows(3, true), 'gomb' => mandala_mail_button(home_url('/'), __('Gyorsrendelés', 'mandala'))]],
        ],
    ];
    $types = (array) apply_filters('mandala_mail_types', $types);
    return $types;
}

/** Közös helyőrzők minden levélben. */
function mandala_mail_common_vars(): array
{
    $c = (array) mandala_config('contact', []);
    return ['bolt' => get_bloginfo('name'), 'bolt_url' => home_url('/'), 'bolt_email' => (string) ($c['email'] ?? ''), 'bolt_telefon' => (string) ($c['phone'] ?? ''), 'nyitvatartas' => (string) ($c['hours'] ?? '')];
}
const MANDALA_MAIL_COMMON_VARS = ['bolt' => 'A bolt neve', 'bolt_url' => 'A bolt címe', 'bolt_email' => 'A bolt e-mail-címe', 'bolt_telefon' => 'A bolt telefonszáma', 'nyitvatartas' => 'Nyitvatartás'];

function mandala_mail_enabled(string $type): bool
{
    $def = mandala_mail_types()[$type] ?? null;
    if (!$def) {
        return false;
    }
    if (!empty($def['required'])) {
        return true;
    }
    if (!empty($def['setting'])) {
        return mandala_automation_on($def['setting']);
    }
    return (((array) get_option('mandala_mail_templates', []))[$type]['enabled'] ?? ($def['default_on'] ?? 'yes')) === 'yes';
}

/** A levél szövegei: a mentett (és WPML-fordított) változat, különben az alapszöveg. */
function mandala_mail_template(string $type): array
{
    $def = mandala_mail_types()[$type];
    $saved = (array) (((array) get_option('mandala_mail_templates', []))[$type] ?? []);
    $out = ['custom' => false];
    foreach (['subject', 'heading', 'body'] as $field) {
        if (isset($saved[$field]) && trim((string) $saved[$field]) !== '') {
            $out[$field] = (string) apply_filters('wpml_translate_single_string', $saved[$field], 'mandala-mail', $type . ':' . $field);
            $out['custom'] = true;
        } else {
            $out[$field] = $def[$field];
        }
    }
    return $out;
}

/** Helyőrzők cseréje. A változók szövegként (HTML-ben escape-elve), a blokkok HTML-ként kerülnek be. */
function mandala_mail_fill(string $text, array $vars, array $blocks = [], bool $html = true): string
{
    foreach ($blocks as $key => $value) {
        // A szerkesztő a különálló sorban lévő blokkot bekezdésbe teszi – a blokk maga blokkszintű.
        $text = preg_replace('#<p>\s*\{' . preg_quote($key, '#') . '\}\s*</p>#u', str_replace(['\\', '$'], ['\\\\', '\$'], (string) $value), $text);
        $text = str_replace('{' . $key . '}', (string) $value, $text);
    }
    foreach ($vars as $key => $value) {
        $text = str_replace('{' . $key . '}', $html ? esc_html((string) $value) : (string) $value, $text);
    }
    // Ismeretlen (pl. elgépelt vagy ebben a levélben nem elérhető) helyőrző ne maradjon a levélben.
    return (string) preg_replace('/\{[a-z_]{2,30}\}/', '', $text);
}

/** A levél összeállítása: [tárgy, címsor, törzs HTML]. $tpl: szerkesztés közbeni (még nem mentett) szövegek. */
function mandala_mail_render(string $type, array $vars, array $blocks, ?array $tpl = null): array
{
    $tpl = $tpl ?: mandala_mail_template($type);
    $vars += mandala_mail_common_vars();
    $subject = trim(wp_strip_all_tags(mandala_mail_fill((string) $tpl['subject'], $vars, [], false)));
    $heading = trim(wp_strip_all_tags(mandala_mail_fill((string) $tpl['heading'], $vars, [], false)));
    $body = mandala_mail_fill(wpautop((string) $tpl['body']), $vars, $blocks);
    $signature = trim((string) mandala_automation_settings()['signature']);
    if ($signature !== '') {
        $body .= '<p style="margin-top:24px">' . nl2br(esc_html(mandala_mail_fill($signature, $vars, [], false))) . '</p>';
    }
    return [$subject, $heading, $body];
}

/**
 * Szerkeszthető levél küldése. $vars: helyőrzők (szöveg), $blocks: tartalomblokkok (HTML),
 * $order_id: a naplóhoz. Kikapcsolt levélnél nem küld (false).
 */
function mandala_mail(string $type, string $to, array $vars = [], array $blocks = [], int $order_id = 0): bool
{
    $def = mandala_mail_types()[$type] ?? null;
    if (!$def || !mandala_mail_enabled($type)) {
        return false;
    }
    [$subject, $heading, $body] = mandala_mail_render($type, $vars, $blocks);
    return mandala_send_mail($to, $subject, $heading, $body, !empty($def['marketing']), ['type' => $type, 'order' => $order_id]);
}

/* ---------- Mintaadatok az előnézethez ---------- */

function mandala_mail_sample_products(int $n): array
{
    $ids = function_exists('wc_get_products') ? wc_get_products(['status' => 'publish', 'limit' => $n, 'return' => 'ids', 'orderby' => 'date', 'order' => 'DESC', 'type' => ['simple', 'variable']]) : [];
    return array_values(array_filter(array_map('wc_get_product', $ids)));
}
function mandala_mail_sample_rows(int $n, bool $price, string $extra = ''): string
{
    $out = '';
    foreach (mandala_mail_sample_products($n) as $p) {
        $out .= mandala_mail_product_row($p, $extra ?: ($price ? wp_kses_post(wc_price((float) $p->get_price())) : ''));
    }
    return $out;
}
function mandala_mail_sample_voucher(): array
{
    $card = '<table role="presentation" style="width:100%;border-collapse:collapse;background:#F6F1E8;border-radius:12px;margin:16px 0"><tr><td style="padding:24px;text-align:center">'
        . '<p style="margin:0;color:#6E6357;font-size:13px;letter-spacing:.08em;text-transform:uppercase">' . esc_html__('Ajándékutalvány', 'mandala') . '</p>'
        . '<p style="margin:8px 0;font-size:32px;font-weight:600;color:#1C1916">10 000 Ft</p><p style="margin:0;font-family:monospace;font-size:20px;letter-spacing:.12em;color:#1C1916">MND-MINTA-KOD1</p></td></tr></table>';
    return ['utalvany' => $card, 'uzenet' => '<blockquote style="margin:16px 0;padding:0 16px;border-left:3px solid #B5651D;font-style:italic">Válassz magadnak valami szépet!</blockquote>',
        'gombok' => mandala_mail_button(mandala_shop_url(), __('Irány a kínálat', 'mandala'))];
}

/* ---------- Admin: WooCommerce → Mandala levelek ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala levelek', 'Mandala levelek', 'manage_woocommerce', 'mandala-automations', 'mandala_mail_admin_page');
});

/** Előnézet (a szerkesztő iframe-jébe): mentett vagy a szerkesztőből küldött szöveggel, mintaadatokkal. */
add_action('admin_post_mandala_mail_preview', function () {
    if (!current_user_can('manage_woocommerce')) {
        wp_die('', '', ['response' => 403]);
    }
    $type = sanitize_key($_REQUEST['type'] ?? '');
    $def = mandala_mail_types()[$type] ?? null;
    if (!$def) {
        wp_die('Ismeretlen levél.');
    }
    $tpl = null;
    if (!empty($_POST['mail'])) {
        check_admin_referer('mandala_mail_edit');
        $tpl = mandala_mail_posted_template();
    } else {
        check_admin_referer('mandala_mail_preview');
    }
    [$vars, $blocks] = ($def['sample'])();
    [$subject, $heading, $body] = mandala_mail_render($type, $vars, $blocks, $tpl);
    $mailer = WC()->mailer();
    $html = (new WC_Email())->style_inline($mailer->wrap_message($heading, $body));
    header('Content-Type: text/html; charset=UTF-8');
    echo '<div style="font:13px/1.4 -apple-system,sans-serif;padding:10px 14px;background:#f0f0f1;border-bottom:1px solid #dcdcde"><strong>Tárgy:</strong> ' . esc_html($subject) . '</div>' . $html; // phpcs:ignore
    exit;
});

function mandala_mail_posted_template(): array
{
    $in = (array) wp_unslash($_POST['mail'] ?? []);
    return [
        'subject' => sanitize_text_field($in['subject'] ?? ''),
        'heading' => sanitize_text_field($in['heading'] ?? ''),
        'body' => wp_kses_post((string) ($in['body'] ?? '')),
    ];
}

function mandala_mail_admin_page(): void
{
    $tab = sanitize_key($_GET['tab'] ?? 'levelek');
    $type = sanitize_key($_GET['type'] ?? '');
    $base = admin_url('admin.php?page=mandala-automations');
    echo '<div class="wrap mandala-mail"><h1>Mandala levelek</h1>';
    if (!($type && isset(mandala_mail_types()[$type]))) {
        echo '<nav class="nav-tab-wrapper">';
        foreach (['levelek' => 'Automata levelek', 'naplo' => 'Napló', 'beallitasok' => 'Beállítások'] as $k => $label) {
            echo '<a class="nav-tab' . ($tab === $k ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', $k, $base)) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }
    if ($type && isset(mandala_mail_types()[$type])) {
        mandala_mail_admin_edit($type, $base);
    } elseif ($tab === 'naplo') {
        mandala_mail_admin_log($base);
    } elseif ($tab === 'beallitasok') {
        mandala_mail_admin_settings();
    } else {
        mandala_mail_admin_list($base);
    }
    echo '</div>';
}

function mandala_mail_admin_list(string $base): void
{
    global $wpdb;
    $s = mandala_automation_settings();
    $counts = [];
    if (get_option('mandala_mail_db') === MANDALA_MAIL_DB_VERSION) {
        foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT type, COUNT(*) AS n FROM ' . mandala_mail_table() . " WHERE status = 'sent' AND created > %s GROUP BY type", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))) as $r) {
            $counts[$r->type] = (int) $r->n;
        }
    }
    $groups = [];
    foreach (mandala_mail_types() as $key => $def) {
        $groups[$def['group']][$key] = $def;
    }
    echo '<p>A téma saját levelei. A szövegük, tárgyuk és időzítésük itt szerkeszthető; a termékeket, gombokat és kódokat a rendszer tölti ki.</p>';
    foreach ($groups as $group => $defs) {
        echo '<h2>' . esc_html($group) . '</h2><table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:24%">Levél</th><th>Mikor megy</th><th style="width:22%">Tárgy</th><th style="width:9%">Állapot</th><th style="width:8%">30 nap</th><th style="width:8%"></th></tr></thead><tbody>';
        foreach ($defs as $key => $def) {
            $tpl = mandala_mail_template($key);
            $when = is_callable($def['when']) ? ($def['when'])($s) : $def['when'];
            $state = !empty($def['required']) ? '<span style="color:#2271b1">mindig</span>' : (mandala_mail_enabled($key) ? '<span style="color:#008a20">● be</span>' : '<span style="color:#8c8f94">○ ki</span>');
            echo '<tr><td><strong><a href="' . esc_url(add_query_arg('type', $key, $base)) . '">' . esc_html($def['label']) . '</a></strong>' . ($tpl['custom'] ? '<br><span class="description">saját szöveg</span>' : '') . (!empty($def['marketing']) ? '<br><span class="description">leiratkozható</span>' : '') . '</td>'
                . '<td>' . esc_html($when) . '</td><td>' . esc_html($tpl['subject']) . '</td><td>' . $state . '</td><td>' . (int) ($counts[$key] ?? 0) . '</td>'
                . '<td><a class="button" href="' . esc_url(add_query_arg('type', $key, $base)) . '">Szerkesztés</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    // A WooCommerce saját levelei (visszaigazolás, teljesítés…): a WooCommerce beállításainál szerkeszthetők.
    if (function_exists('WC')) {
        echo '<h2>WooCommerce rendszerlevelek</h2><p class="description">Rendelés-visszaigazolás, teljesítés, számla, jelszó… Ezeket a WooCommerce levélbeállításainál lehet szerkeszteni; a csomagkövetés linkjét a téma automatikusan beleteszi.</p><table class="widefat striped" style="max-width:1100px"><thead><tr><th style="width:30%">Levél</th><th>Kinek</th><th style="width:9%">Állapot</th><th style="width:8%"></th></tr></thead><tbody>';
        foreach (WC()->mailer()->get_emails() as $email) {
            echo '<tr><td>' . esc_html($email->get_title()) . '</td><td>' . esc_html($email->is_customer_email() ? 'vásárló' : ($email->get_recipient() ?: 'bolt')) . '</td><td>' . ($email->is_enabled() ? '<span style="color:#008a20">● be</span>' : '<span style="color:#8c8f94">○ ki</span>') . '</td>'
                . '<td><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=email&section=' . strtolower(get_class($email)))) . '">Beállítás</a></td></tr>';
        }
        echo '</tbody></table>';
    }
    $pending = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['group' => 'mandala', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500], 'ids')) : 0;
    echo '<p>Ütemezett feladatok (levelek, ellenőrzések): <strong>' . (int) $pending . '</strong> · <a href="' . esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler&s=mandala')) . '">Részletek</a></p>';
}

function mandala_mail_admin_edit(string $type, string $base): void
{
    $def = mandala_mail_types()[$type];
    $all = (array) get_option('mandala_mail_templates', []);
    $notice = '';
    if (!empty($_POST['mail']) && check_admin_referer('mandala_mail_edit')) {
        $posted = mandala_mail_posted_template();
        if (!empty($_POST['mail_test'])) {
            $to = sanitize_email(wp_unslash($_POST['mail_test_to'] ?? ''));
            [$vars, $blocks] = ($def['sample'])();
            [$subject, $heading, $body] = mandala_mail_render($type, $vars, $blocks, $posted);
            $ok = is_email($to) && mandala_send_mail($to, '[Teszt] ' . $subject, $heading, $body, false, ['type' => 'teszt']);
            $notice = $ok ? '<div class="notice notice-success"><p>Tesztlevél elküldve: ' . esc_html($to) . ' (a szerkesztett, még nem mentett szöveggel, mintaadatokkal).</p></div>' : '<div class="notice notice-error"><p>A tesztlevelet nem sikerült elküldeni – érvényes e-mail-cím? (Levélküldés beállítása: SMTP bővítmény.)</p></div>';
        } elseif (!empty($_POST['mail_reset'])) {
            unset($all[$type]['subject'], $all[$type]['heading'], $all[$type]['body']);
            update_option('mandala_mail_templates', $all, false);
            $notice = '<div class="notice notice-success"><p>Visszaállítva az alapszövegre.</p></div>';
        } else {
            $saved = (array) ($all[$type] ?? []);
            foreach (['subject', 'heading', 'body'] as $field) {
                $default = $def[$field];
                // Az alapszöveggel azonos mező nem „saját” – így a téma frissített alapszövege érvényesül.
                $same = $field === 'body' ? trim(preg_replace('/\s+/', ' ', wpautop($posted[$field]))) === trim(preg_replace('/\s+/', ' ', wpautop($default))) : $posted[$field] === $default;
                if ($posted[$field] === '' || $same) {
                    unset($saved[$field]);
                } else {
                    $saved[$field] = $posted[$field];
                    do_action('wpml_register_single_string', 'mandala-mail', $type . ':' . $field, $posted[$field]);
                }
            }
            if (empty($def['required']) && empty($def['setting'])) {
                $saved['enabled'] = empty($_POST['mail_enabled']) ? 'no' : 'yes';
            }
            $all[$type] = $saved;
            update_option('mandala_mail_templates', $all, false);
            if (!empty($def['setting'])) {
                $auto = (array) get_option('mandala_automations', []);
                $auto[$def['setting']] = empty($_POST['mail_enabled']) ? 'no' : 'yes';
                if (!empty($def['delay'])) {
                    $auto[$def['delay'][0]] = max(1, (int) ($_POST['mail_delay'] ?? 1));
                }
                update_option('mandala_automations', $auto + mandala_automation_settings(), false);
            }
            $notice = '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
    }
    $tpl = mandala_mail_template($type);
    $s = mandala_automation_settings();
    $when = is_callable($def['when']) ? ($def['when'])($s) : $def['when'];
    echo '<p><a href="' . esc_url($base) . '">← Minden levél</a></p><h2>' . esc_html($def['label']) . '</h2>' . $notice . '<p class="description">' . esc_html($when) . '</p>'; // phpcs:ignore
    echo '<form method="post" id="mandala-mail-form"><div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:24px;align-items:start;max-width:1400px">';
    wp_nonce_field('mandala_mail_edit');
    echo '<div><table class="form-table" role="presentation">';
    if (empty($def['required'])) {
        echo '<tr><th scope="row">Küldés</th><td><label><input type="checkbox" name="mail_enabled" value="1"' . checked(mandala_mail_enabled($type), true, false) . '> bekapcsolva</label>';
        if (!empty($def['delay'])) {
            echo ' &nbsp; <label><input type="number" min="1" name="mail_delay" value="' . (int) $s[$def['delay'][0]] . '" style="width:70px"> ' . esc_html($def['delay'][1]) . ' múlva</label>';
        }
        echo '</td></tr>';
    }
    echo '<tr><th scope="row"><label for="mail-subject">Tárgy</label></th><td><input type="text" id="mail-subject" name="mail[subject]" class="large-text" value="' . esc_attr($tpl['subject']) . '" data-mail-field></td></tr>'
        . '<tr><th scope="row"><label for="mail-heading">Címsor</label></th><td><input type="text" id="mail-heading" name="mail[heading]" class="large-text" value="' . esc_attr($tpl['heading']) . '" data-mail-field><p class="description">A levél fejlécében, nagy betűvel.</p></td></tr>'
        . '</table>';
    wp_editor($tpl['body'], 'mandala_mail_body', [
        'textarea_name' => 'mail[body]', 'media_buttons' => false, 'textarea_rows' => 16, 'teeny' => false,
        'tinymce' => ['toolbar1' => 'formatselect,bold,italic,bullist,numlist,link,unlink,undo,redo', 'toolbar2' => '', 'block_formats' => 'Bekezdés=p;Címsor=h2;Alcím=h3'],
        'quicktags' => ['buttons' => 'strong,em,link,ul,ol,li'],
    ]);
    $chips = function (array $list, string $title, string $hint) {
        $out = '<p style="margin:16px 0 6px"><strong>' . esc_html($title) . '</strong> <span class="description">' . esc_html($hint) . '</span></p><p style="display:flex;flex-wrap:wrap;gap:6px;margin:0">';
        foreach ($list as $key => $desc) {
            $out .= '<button type="button" class="button button-small" data-insert="{' . esc_attr($key) . '}" title="' . esc_attr($desc) . '"><code>{' . esc_html($key) . '}</code></button>';
        }
        return $out . '</p>';
    };
    echo $chips($def['vars'] + MANDALA_MAIL_COMMON_VARS, 'Helyőrzők', 'kattintásra a kurzorhoz kerül (tárgyba, címsorba is); a rendszer tölti ki'); // phpcs:ignore
    if (!empty($def['blocks'])) {
        echo $chips($def['blocks'], 'Tartalomblokkok', 'saját sorba tedd; ha kiveszed, az a rész kimarad a levélből'); // phpcs:ignore
    }
    $missing = array_filter(array_keys($def['blocks'] ?? []), fn($b) => !str_contains($tpl['body'], '{' . $b . '}'));
    if ($missing && $tpl['custom']) {
        echo '<div class="notice notice-warning inline"><p>A szövegből hiányzik: ' . esc_html(implode(', ', array_map(fn($b) => '{' . $b . '}', $missing))) . ' – ez a rész nem kerül a levélbe.</p></div>';
    }
    echo '<p class="description" style="margin-top:12px">Aláírás és válaszcím: Beállítások fül. ' . (!empty($def['marketing']) ? 'A levél alján leiratkozó link is lesz.' : '') . '</p>';
    echo '<p class="submit" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">'
        . '<button type="submit" class="button button-primary" name="mail_save" value="1">Mentés</button>'
        . '<button type="submit" class="button" formaction="' . esc_url(admin_url('admin-post.php?action=mandala_mail_preview&type=' . $type)) . '" formtarget="mandala-mail-preview">Előnézet frissítése</button>'
        . ($tpl['custom'] ? '<button type="submit" class="button button-link-delete" name="mail_reset" value="1" onclick="return confirm(\'Visszaállítod az alapszöveget?\')">Alaphelyzet</button>' : '')
        . '</p><p style="display:flex;gap:8px;align-items:center"><label for="mail-test-to">Tesztlevél ide:</label> <input type="email" id="mail-test-to" name="mail_test_to" value="' . esc_attr(wp_get_current_user()->user_email) . '" class="regular-text"> <button type="submit" class="button" name="mail_test" value="1">Küldés</button></p>';
    echo '</div><div style="position:sticky;top:40px"><p style="margin:0 0 6px"><strong>Előnézet</strong> <span class="description">mintaadatokkal</span></p><iframe name="mandala-mail-preview" title="A levél előnézete" src="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_mail_preview&type=' . $type), 'mandala_mail_preview')) . '" style="width:100%;height:760px;border:1px solid #dcdcde;border-radius:4px;background:#fff"></iframe></div></div></form>';
    ?>
<script>
(() => {
  let last = null;
  document.querySelectorAll('[data-mail-field]').forEach((el) => el.addEventListener('focus', () => { last = el; }));
  const editor = () => window.tinymce && tinymce.get('mandala_mail_body');
  document.getElementById('mandala_mail_body')?.addEventListener('focus', () => { last = null; });
  document.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('mousedown', (e) => e.preventDefault()));
  document.querySelectorAll('[data-insert]').forEach((b) => b.addEventListener('click', () => {
    const text = b.dataset.insert;
    if (last) {
      const [s, e] = [last.selectionStart ?? last.value.length, last.selectionEnd ?? last.value.length];
      last.value = last.value.slice(0, s) + text + last.value.slice(e);
      last.focus();
      last.setSelectionRange(s + text.length, s + text.length);
      return;
    }
    const ed = editor();
    if (ed && !ed.isHidden()) { ed.focus(); ed.execCommand('mceInsertContent', false, text); return; }
    const ta = document.getElementById('mandala_mail_body');
    const [s, e] = [ta.selectionStart, ta.selectionEnd];
    ta.value = ta.value.slice(0, s) + text + ta.value.slice(e);
  }));
})();
</script>
    <?php
}

function mandala_mail_admin_log(string $base): void
{
    global $wpdb;
    $table = mandala_mail_table();
    $type = sanitize_key($_GET['mtype'] ?? '');
    $q = sanitize_text_field(wp_unslash($_GET['q'] ?? ''));
    $paged = max(1, (int) ($_GET['paged'] ?? 1));
    $where = '1=1';
    $args = [];
    if ($type) {
        $where .= ' AND type = %s';
        $args[] = $type;
    }
    if ($q !== '') {
        $where .= ' AND (recipient LIKE %s OR subject LIKE %s OR order_id = %d)';
        $like = '%' . $wpdb->esc_like(strtolower($q)) . '%';
        array_push($args, $like, '%' . $wpdb->esc_like($q) . '%', (int) $q);
    }
    $sql = fn($select, $tail = '') => $args ? $wpdb->prepare("SELECT {$select} FROM {$table} WHERE {$where} {$tail}", ...$args) : "SELECT {$select} FROM {$table} WHERE {$where} {$tail}"; // phpcs:ignore
    $total = (int) $wpdb->get_var($sql('COUNT(*)'));
    $rows = $wpdb->get_results($sql('*', 'ORDER BY id DESC LIMIT 50 OFFSET ' . (($paged - 1) * 50)));
    $types = mandala_mail_types();
    $label = fn($t) => $types[$t]['label'] ?? ['teszt' => 'Tesztlevél', 'szallitas' => 'Szállítás', 'belso' => 'Belső értesítő', 'egyeb' => 'Egyéb'][$t] ?? $t;
    echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="page" value="mandala-automations"><input type="hidden" name="tab" value="naplo">'
        . '<select name="mtype"><option value="">Minden levél</option>';
    foreach ($types as $key => $def) {
        echo '<option value="' . esc_attr($key) . '"' . selected($type, $key, false) . '>' . esc_html($def['label']) . '</option>';
    }
    echo '</select><input type="search" name="q" value="' . esc_attr($q) . '" placeholder="E-mail, tárgy vagy rendelésszám" class="regular-text"><button class="button">Szűrés</button></form>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Mikor</th><th>Levél</th><th>Címzett</th><th>Tárgy</th><th>Rendelés</th><th>Állapot</th></tr></thead><tbody>';
    if (!$rows) {
        echo '<tr><td colspan="6">Nincs találat. (A napló 180 napig őrzi a leveleket.)</td></tr>';
    }
    $states = ['sent' => '<span style="color:#008a20">elküldve</span>', 'failed' => '<span style="color:#d63638">hiba</span>', 'unsubscribed' => '<span style="color:#8c8f94">leiratkozott – nem ment</span>'];
    foreach ($rows as $r) {
        echo '<tr><td>' . esc_html(get_date_from_gmt($r->created, 'Y. m. d. H:i')) . '</td><td>' . esc_html($label($r->type)) . '</td><td>' . esc_html($r->recipient) . '</td><td>' . esc_html($r->subject) . '</td>'
            . '<td>' . ($r->order_id ? '<a href="' . esc_url(admin_url('post.php?post=' . (int) $r->order_id . '&action=edit')) . '">#' . (int) $r->order_id . '</a>' : '–') . '</td><td>' . ($states[$r->status] ?? esc_html($r->status)) . '</td></tr>'; // phpcs:ignore
    }
    echo '</tbody></table>';
    if ($total > 50) {
        echo '<p>' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => (int) ceil($total / 50)]) . '</p>'; // phpcs:ignore
    }
}

function mandala_mail_admin_settings(): void
{
    if (isset($_POST['mandala_automations']) && check_admin_referer('mandala_automations')) {
        $in = (array) wp_unslash($_POST['mandala_automations']);
        $s = mandala_automation_settings();
        $s['reorder_cats'] = sanitize_text_field($in['reorder_cats'] ?? $s['reorder_cats']);
        $s['moderator'] = sanitize_email($in['moderator'] ?? '');
        $s['signature'] = sanitize_textarea_field($in['signature'] ?? '');
        update_option('mandala_automations', $s, false);
        do_action('mandala_mail_settings_save');
        echo '<div class="notice notice-success"><p>Mentve.</p></div>';
    }
    $s = mandala_automation_settings();
    $reply = (string) (mandala_config('contact', [])['email'] ?? '');
    echo '<form method="post">';
    wp_nonce_field('mandala_automations');
    echo '<table class="form-table">'
        . '<tr><th scope="row"><label for="ma-signature">Aláírás</label></th><td><textarea id="ma-signature" name="mandala_automations[signature]" rows="3" class="regular-text">' . esc_textarea($s['signature']) . '</textarea><p class="description">Minden levél végére (a helyőrzők itt is működnek, pl. {bolt_telefon}). Üresen nincs aláírás.</p></td></tr>'
        . '<tr><th scope="row">Válaszcím</th><td><code>' . esc_html($reply ?: '–') . '</code><p class="description">A vásárló válasza ide megy (WooCommerce → Mandala bolt adatai → e-mail). A feladó nevét és címét a WooCommerce → Beállítások → E-mailek adja; a levélküldéshez SMTP bővítmény ajánlott.</p></td></tr>'
        . '<tr><th scope="row"><label for="ma-cats">Fogyóeszköz kategóriák</label></th><td><input type="text" id="ma-cats" class="regular-text" name="mandala_automations[reorder_cats]" value="' . esc_attr($s['reorder_cats']) . '"><p class="description">Az újrarendelés emlékeztetőhöz: kategória slugok vesszővel.</p></td></tr>'
        . '<tr><th scope="row"><label for="ma-mod">Értékelések moderátora</label></th><td><input type="email" id="ma-mod" class="regular-text" name="mandala_automations[moderator]" value="' . esc_attr($s['moderator']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '"><p class="description">Új értékelésről ide megy értesítés.</p></td></tr>'
        . '<tr><th scope="row">Leiratkozottak</th><td>' . count((array) get_option('mandala_unsubscribed', [])) . ' cím <p class="description">Nekik emlékeztetőt, értékelés kérést nem küldünk; a rendelésükről szóló leveleket megkapják.</p></td></tr>'
        ;
    do_action('mandala_mail_settings_fields');
    echo '</table>';
    submit_button('Mentés');
    echo '</form>';
}
