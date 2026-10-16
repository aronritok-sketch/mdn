<?php
/**
 * Hozzájárulások a reklámlevelekhez (Grt. 6. § (1) és (5), GDPR 7. cikk (1)) – egy helyen: hírlevél, partnerlevél,
 * heti új érkezések (viszonteladó).
 *
 *  - Napló ({prefix}mandala_consent): minden megadás és visszavonás külön sor – e-mail, név (ha ismert), időpont (UTC),
 *    forrás (hol adta), a jelölőnégyzet pontos szövege a környezetével és a szöveg változata (rövid lenyomat), IP-cím.
 *    Ez a bizonyíték. A jelenlegi állapot ott marad, ahol volt: hírlevél – mandala_newsletter opció (e-mail → dátum,
 *    forrás, név, a naplósor azonosítója), partnerlevél – _mandala_partner_mail (+ _at, _source, _wording), heti új
 *    érkezések – _mandala_b2b_arrivals (+ _at) felhasználói meta. Az opció nem töltődik be minden oldalon (autoload=no),
 *    a bizonyíték (IP, szöveg) a táblában van, így több ezer feliratkozónál sem nő nagyra.
 *  - Megőrzés: amíg a hozzájárulás él, és a visszavonás után 5 évig (Ptk. 6:22. § – addig kell tudni bizonyítani, hogy a
 *    kiküldött levelekhez volt hozzájárulás); utána a napi takarítás törli a cím sorait. A leiratkozottak listája
 *    (mandala_unsubscribed) marad, hogy újra ne kapjon levelet.
 *  - Új, kifejezett hozzájárulás (hírlevél-űrlap, pénztár, felugró ablak, Fiókom, regisztráció, viszonteladói felület,
 *    a CRM-ben rögzített telefonos hozzájárulás) leveszi a címet a leiratkozottak listájáról – a MailerLite-ban is újra
 *    aktív lesz. Vásárlás nem függ tőle: minden pipa külön, alapból üres.
 *  - Leiratkozás egy kattintással, belépés nélkül: mailer.php (?mandala_unsub=…, List-Unsubscribe + List-Unsubscribe-Post).
 *    Az általános leiratkozás minden reklámlevelet leállít (mandala_unsubscribe_all), a heti levélé csak azt.
 *  - Reklámlevél csak érvényes hozzájárulással megy (mandala_marketing_consent – a mandala_send_mail ellenőrzi): a
 *    vásárlás önmagában nem hozzájárulás (nincs „soft opt-in”), így az emlékeztetők (elhagyott kosár, újrarendelés,
 *    ajánló, kedvencek, értékelés kérése) is csak hírlevél-feliratkozónak mennek; a heti új érkezések levele a
 *    viszonteladónak, aki kérte; a születésnapi kupon annak, aki ezért megadta a születésnapját (napló: birthday).
 * Admin: Eszközök → Mandala űrlapok → CSV (feliratkozók a bizonyítékkal, partnerlevél-hozzájárulások, teljes napló).
 */

defined('ABSPATH') || exit;

const MANDALA_CONSENT_DB_VERSION = '1';
const MANDALA_CONSENT_KEEP_YEARS = 5;
const MANDALA_CONSENT_PURPOSES = [
    'newsletter' => 'Hírlevél',
    'partner_mail' => 'Partnerlevél',
    'b2b_arrivals' => 'Heti új érkezések (viszonteladó)',
    'birthday' => 'Születésnapi kupon',
    'all' => 'Minden reklámlevél (általános leiratkozás)',
];

/* ---------- Napló ---------- */

function mandala_consent_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_consent';
}
add_action('init', function () {
    if (get_option('mandala_consent_db') === MANDALA_CONSENT_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mandala_consent_table() . " (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        created datetime NOT NULL,
        email varchar(190) NOT NULL default '',
        user_id bigint unsigned NOT NULL default 0,
        purpose varchar(20) NOT NULL default '',
        granted tinyint(1) NOT NULL default 0,
        source varchar(40) NOT NULL default '',
        wording text NOT NULL,
        version varchar(16) NOT NULL default '',
        ip varchar(45) NOT NULL default '',
        name varchar(190) NOT NULL default '',
        PRIMARY KEY  (id),
        KEY email (email),
        KEY purpose (purpose,email),
        KEY created (created)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('mandala_consent_db', MANDALA_CONSENT_DB_VERSION);
}, 5);

/** A látogató IP-címe – csak a látogató saját kérésében (cronból, WP-CLI-ből, a CRM parancsából üres). */
function mandala_client_ip(): string
{
    if ((defined('WP_CLI') && WP_CLI) || wp_doing_cron()) {
        return '';
    }
    $ip = class_exists('WC_Geolocation') ? (string) WC_Geolocation::get_ip_address() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // phpcs:ignore
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
}

/** A hozzájárulás szövegének változata: a szöveg rövid lenyomata (más szöveg → más változat). */
function mandala_consent_version(string $wording): string
{
    return 'v' . substr(sha1($wording), 0, 8);
}

/**
 * A felületen látható szövegek (egy forrás: a felület is ezt írja ki, a napló is ezt rögzíti).
 * A popup_accept %s helyén az „adatkezelési tájékoztatót” link áll.
 */
function mandala_consent_text(string $key): string
{
    $texts = [
        'checkout' => __('Feliratkozom a hírlevélre (havonta kétszer, bármikor leiratkozhatok)', 'mandala'),
        'account' => __('Kérem a Mandala hírlevelét (új érkezések, cikkek, kedvezmények – havonta kétszer, bármikor leiratkozhatok)', 'mandala'),
        'popup_lead' => __('Iratkozz fel a hírlevelünkre – ritkán írunk, akkor is az új érkezésekről Nepálból és Indiából.', 'mandala'),
        'popup_accept' => __('Feliratkozom a hírlevélre, és elfogadom az %s. Bármikor leiratkozhatok.', 'mandala'),
        'popup_link' => __('adatkezelési tájékoztatót', 'mandala'),
        'popup_button' => __('Kérem a kupont', 'mandala'),
        'partner_mail' => __('Kérek partnerleveleket (új érkezések, akciók)', 'mandala'),
        'b2b_arrivals' => __('Heti levél az új érkezésekről', 'mandala'),
        'crm' => __('Telefonon vagy személyesen kérte a partnerleveleket (a Partner CRM-ben rögzítve)', 'mandala'),
        'birthday' => __('Születésnapodon kedvezménykupont küldünk. Évet nem kérünk.', 'mandala'),
    ];
    return (string) apply_filters('mandala_consent_text', $texts[$key] ?? '', $key);
}

/** A naplóba kerülő szöveg: hol, milyen bevezetővel, a jelölőnégyzet pontos szövege, a gomb. */
function mandala_consent_wording(string $place, string $label, string $button = '', string $lead = ''): string
{
    return $place . ($lead !== '' ? ': „' . $lead . '”' : '') . ' – ' . sprintf(__('jelölőnégyzet: „%s”', 'mandala'), $label)
        . ($button !== '' ? ', ' . sprintf(__('gomb: „%s”', 'mandala'), $button) : '');
}

/** A felugró ablak teljes szövege (a growth.js ugyanezt írja ki). */
function mandala_consent_popup_wording(): string
{
    return mandala_consent_wording(__('Felugró ablak', 'mandala'), sprintf(mandala_consent_text('popup_accept'), mandala_consent_text('popup_link')), mandala_consent_text('popup_button'), mandala_consent_text('popup_lead'));
}

/**
 * Egy hozzájárulás (vagy visszavonás) a naplóba. $x: wording, user_id, name, ip (alapból a látogatóé), at (UTC
 * 'Y-m-d H:i:s'). Visszaad: a sor azonosítója (0, ha nem sikerült).
 */
function mandala_consent_log(string $email, string $purpose, bool $granted, string $source, array $x = []): int
{
    global $wpdb;
    $email = strtolower(trim($email));
    if (!is_email($email) || get_option('mandala_consent_db') !== MANDALA_CONSENT_DB_VERSION) {
        return 0;
    }
    $wording = (string) ($x['wording'] ?? '');
    $wpdb->insert(mandala_consent_table(), [
        'created' => (string) ($x['at'] ?? current_time('mysql', true)),
        'email' => mb_substr($email, 0, 190),
        'user_id' => (int) ($x['user_id'] ?? 0),
        'purpose' => mb_substr($purpose, 0, 20),
        'granted' => $granted ? 1 : 0,
        'source' => mb_substr($source, 0, 40),
        'wording' => $wording,
        'version' => $wording !== '' ? mandala_consent_version($wording) : '',
        'ip' => array_key_exists('ip', $x) ? (string) $x['ip'] : mandala_client_ip(),
        'name' => mb_substr(trim((string) ($x['name'] ?? '')), 0, 190),
    ]);
    return (int) $wpdb->insert_id;
}

/**
 * Az érvényes (utolsó megadott) hozzájárulás sora célonként: email => sor. $emails: csak ezek (üres: mind).
 */
function mandala_consent_latest(string $purpose, array $emails = []): array
{
    global $wpdb;
    if (get_option('mandala_consent_db') !== MANDALA_CONSENT_DB_VERSION) {
        return [];
    }
    $t = mandala_consent_table();
    $where = $wpdb->prepare('purpose = %s AND granted = 1', $purpose);
    if ($emails) {
        $emails = array_values(array_unique(array_map('strtolower', $emails)));
        $where .= ' AND email IN (' . implode(',', array_fill(0, count($emails), '%s')) . ')';
        $where = $wpdb->prepare($where, ...$emails); // phpcs:ignore WordPress.DB.PreparedSQL
    }
    $out = [];
    foreach ((array) $wpdb->get_results("SELECT c.* FROM $t c INNER JOIN (SELECT MAX(id) id FROM $t WHERE $where GROUP BY email) m ON m.id = c.id", ARRAY_A) as $row) { // phpcs:ignore WordPress.DB.PreparedSQL
        $out[$row['email']] = $row;
    }
    return $out;
}

/** A fiók neve a naplóhoz (vezetéknév keresztnév, a cégnévvel, ha van). */
function mandala_consent_user_name(WP_User $u): string
{
    $m = fn($k) => trim((string) get_user_meta($u->ID, $k, true));
    $name = trim($m('billing_last_name') . ' ' . $m('billing_first_name')) ?: (trim($m('last_name') . ' ' . $m('first_name')) ?: (string) $u->display_name);
    return $m('billing_company') !== '' ? $name . ' (' . $m('billing_company') . ')' : $name;
}

/** Új, kifejezett hozzájárulás: a cím le a leiratkozottak listájáról. Visszaad: rajta volt-e. */
function mandala_consent_lift(string $email): bool
{
    $email = strtolower($email);
    $list = (array) get_option('mandala_unsubscribed', []);
    if (!in_array($email, $list, true)) {
        return false;
    }
    update_option('mandala_unsubscribed', array_values(array_diff($list, [$email])), false);
    return true;
}

/**
 * Mehet-e reklámlevél erre a címre (Grt. 6. § (1)): természetes személynek – az egyéni vállalkozónak is – csak
 * előzetes, kifejezett hozzájárulással; a vásárlás önmagában nem az (nincs „soft opt-in”). $purpose:
 *  - 'b2b'       a heti új érkezések levele: a viszonteladó a fiókjában kérte (_mandala_b2b_arrivals = yes);
 *  - 'birthday'  a születésnapi kupon: a születésnapját ezért adta meg (birthday.php, napló: birthday);
 *  - ''          minden más reklámlevél (emlékeztetők, ajánló, kedvencek, értékelés kérése, üdvözlő kupon, saját
 *                reklámlevél): a hírlevél-hozzájárulás.
 * A leiratkozott címre (mandala_unsubscribed) semmi nem megy. A partnerlevél-hozzájárulás (B2B, a CRM / MailerLite
 * küldi) külön cél: a webshop B2C reklámleveleihez nem elég. Szűrő: mandala_marketing_consent (pl. egy későbbi, saját
 * pipával kért cél).
 */
function mandala_marketing_consent(string $email, string $purpose = ''): bool
{
    $email = strtolower(trim($email));
    if (!is_email($email) || mandala_is_unsubscribed($email)) {
        return false;
    }
    if ($purpose === 'b2b') {
        $user = get_user_by('email', $email);
        $ok = $user && get_user_meta($user->ID, '_mandala_b2b_arrivals', true) === 'yes';
    } elseif ($purpose === 'birthday') {
        $ok = function_exists('mandala_birthday_get') && mandala_birthday_get($email) !== '';
    } else {
        $ok = mandala_newsletter_is_active($email);
    }
    return (bool) apply_filters('mandala_marketing_consent', $ok, $email, $purpose);
}

/* ---------- Hírlevél ---------- */

/** A forráskódok rövid neve a feliratkozók listájában (a MailerLite mandala_forras mezője is ez). */
function mandala_newsletter_source_label(string $source): string
{
    return ['weboldal' => 'weboldal', 'penztar' => 'pénztár', 'felugro' => 'felugró ablak', 'fiok' => 'fiók', 'regisztracio' => 'regisztráció'][$source] ?? $source;
}

function mandala_newsletter_is_active(string $email): bool
{
    $email = strtolower($email);
    return isset(((array) get_option('mandala_newsletter', []))[$email]) && !mandala_is_unsubscribed($email);
}

/**
 * Hírlevél-feliratkozás kifejezett hozzájárulással: napló (bizonyíték), a listára, le a leiratkozottak közül, és – ha
 * új (vagy újra feliratkozott) – a mandala_newsletter_subscribed hook (MailerLite, saját levelek, megnézett termékek).
 * $x: name, user_id, ip. Visszaad: új feliratkozás-e.
 */
function mandala_newsletter_subscribe(string $email, string $source, string $wording, array $x = []): bool
{
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) {
        return false;
    }
    $was = mandala_newsletter_is_active($email);
    $name = trim((string) ($x['name'] ?? ''));
    $id = mandala_consent_log($email, 'newsletter', true, $source, ['wording' => $wording] + $x);
    $subs = (array) get_option('mandala_newsletter', []);
    if (!$was) {
        $subs[$email] = ['date' => current_time('mysql'), 'source' => mandala_newsletter_source_label($source)] + array_filter(['name' => $name, 'consent' => $id]);
        update_option('mandala_newsletter', $subs, false);
    } elseif ($name !== '' && empty($subs[$email]['name'])) {
        $subs[$email]['name'] = $name;
        update_option('mandala_newsletter', $subs, false);
    }
    mandala_consent_lift($email);
    if (!$was) {
        do_action('mandala_newsletter_subscribed', $email, $source);
    }
    return !$was;
}

/** A hírlevél visszavonása (a többi reklámlevélre vonatkozó hozzájárulás marad). Visszaad: volt-e feliratkozva. */
function mandala_newsletter_withdraw(string $email, string $source, array $x = []): bool
{
    $email = strtolower($email);
    $subs = (array) get_option('mandala_newsletter', []);
    if (!isset($subs[$email])) {
        return false;
    }
    unset($subs[$email]);
    update_option('mandala_newsletter', $subs, false);
    mandala_consent_log($email, 'newsletter', false, $source, $x);
    do_action('mandala_newsletter_withdrawn', $email, $source);
    return true;
}

/**
 * Általános leiratkozás: minden reklámlevél leáll (a leiratkozottak listájára, le a hírlevélről, születésnapi kupon,
 * partnerlevél és heti új érkezések ki – a CRM is megkapja). A rendelésekről szóló levelek továbbra is mennek.
 * $source: leiratkozo-link | mailerlite | crm | admin. Visszaad: változott-e valami.
 */
function mandala_unsubscribe_all(string $email, string $source, array $x = []): bool
{
    $email = strtolower(sanitize_email($email));
    if (!is_email($email)) {
        return false;
    }
    $changed = false;
    $list = (array) get_option('mandala_unsubscribed', []);
    if (!in_array($email, $list, true)) {
        $list[] = $email;
        update_option('mandala_unsubscribed', array_values(array_unique($list)), false);
        $changed = true;
    }
    $subs = (array) get_option('mandala_newsletter', []);
    if (isset($subs[$email])) {
        unset($subs[$email]);
        update_option('mandala_newsletter', $subs, false);
        mandala_consent_log($email, 'newsletter', false, $source, $x);
        $changed = true;
    }
    $user = get_user_by('email', $email);
    // A születésnapi kupon is reklámlevél: a hozzájárulás (és vele a születésnap, amire már nincs cél) megszűnik.
    if (function_exists('mandala_birthday_get') && mandala_birthday_get($email) !== '') {
        mandala_birthday_set($email, '', $source, ['user_id' => $user ? (int) $user->ID : 0] + $x);
        $changed = true;
    }
    if ($user) {
        $partner = false;
        if (get_user_meta($user->ID, '_mandala_b2b_arrivals', true) === 'yes' && function_exists('mandala_b2b_arrivals_set')) {
            $partner = mandala_b2b_arrivals_set((int) $user->ID, false, $source, ['notify' => false] + $x) || $partner;
        }
        if (get_user_meta($user->ID, '_mandala_partner_mail', true) === 'yes' && function_exists('mandala_crm_set_consent')) {
            mandala_crm_set_consent((int) $user->ID, false, false, $source, '', $x);
            $partner = true;
        }
        // A CRM egy fiók-eseményben kapja: marketing_consent = false, b2b_arrivals = false.
        if ($partner && function_exists('mandala_crm_enqueue') && mandala_is_wholesale_user((int) $user->ID)) {
            mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $user->ID));
        }
        $changed = $changed || $partner;
    }
    if ($changed) {
        mandala_consent_log($email, 'all', false, $source, ['user_id' => $user ? (int) $user->ID : 0] + $x);
        do_action('mandala_unsubscribed', $email, $source);
    }
    return $changed;
}

/* ---------- Fiókom → Hírlevél (be / ki) és regisztráció ---------- */

/** A belépett vásárló hírlevél-állapota (a fiók e-mail-címe vagy a számlázási címe szerint). */
function mandala_account_newsletter_emails(int $uid): array
{
    $u = get_userdata($uid);
    return $u ? array_values(array_unique(array_filter(array_map('strtolower', [$u->user_email, (string) get_user_meta($uid, 'billing_email', true)]), 'is_email'))) : [];
}

add_action('woocommerce_account_dashboard', function () {
    $uid = get_current_user_id();
    $on = (bool) array_filter(mandala_account_newsletter_emails($uid), 'mandala_newsletter_is_active'); ?>
<section class="panel nl-account" id="hirlevel">
  <h2 style="font-size:var(--fs-h4)"><?php esc_html_e('Hírlevél', 'mandala'); ?></h2>
  <form method="post" class="nl-account-form">
    <?php wp_nonce_field('mandala_nl_account', '_nlnonce'); ?>
    <label class="check"><input type="checkbox" name="mandala_nl" value="1"<?php checked($on); ?> onchange="this.form.submit()"> <span><?php echo esc_html(mandala_consent_text('account')); ?></span></label>
    <noscript><button class="iu-button iu-button-link" type="submit"><?php esc_html_e('Mentés', 'mandala'); ?></button></noscript>
  </form>
  <p class="text-small text-muted"><?php esc_html_e('A hírlevél reklámot is tartalmaz (új termékek, kedvezmények). Itt vagy bármelyik levél alján egy kattintással leiratkozhatsz; a rendeléseidről szóló értesítéseket így is megkapod.', 'mandala'); ?></p>
</section>
    <?php
}, 20);

add_action('template_redirect', function () {
    if (!isset($_POST['_nlnonce']) || !is_user_logged_in() || !wp_verify_nonce(sanitize_key($_POST['_nlnonce']), 'mandala_nl_account')) {
        return;
    }
    $uid = get_current_user_id();
    $u = wp_get_current_user();
    if (!empty($_POST['mandala_nl'])) {
        mandala_newsletter_subscribe($u->user_email, 'fiok', mandala_consent_wording(__('Fiókom', 'mandala'), mandala_consent_text('account')), ['user_id' => $uid, 'name' => mandala_consent_user_name($u)]);
        wc_add_notice(__('Feliratkoztál – a következő hírlevelünket már megkapod.', 'mandala'));
    } else {
        foreach (mandala_account_newsletter_emails($uid) as $email) {
            mandala_newsletter_withdraw($email, 'fiok', ['user_id' => $uid]);
        }
        wc_add_notice(__('Leiratkoztál a hírlevélről. A rendeléseidről szóló értesítéseket továbbra is megkapod.', 'mandala'));
    }
    wp_safe_redirect(wc_get_account_endpoint_url('dashboard'));
    exit;
});

// Regisztráció (Fiókom): külön, alapból üres pipa – a fiók nem függ tőle.
add_action('woocommerce_register_form', function () {
    echo '<p class="form-row form-row-wide check-row"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" for="mandala_nl_reg"><input type="checkbox" class="woocommerce-form__input input-checkbox" name="mandala_nl_reg" id="mandala_nl_reg" value="1"' . checked(!empty($_POST['mandala_nl_reg']), true, false) . '> <span>' . esc_html(mandala_consent_text('account')) . '</span></label></p>'; // phpcs:ignore WordPress.Security.NonceVerification
});
add_action('woocommerce_created_customer', function ($customer_id) {
    // Csak a Fiókom regisztrációs űrlapjáról (a WooCommerce ellenőrizte a nonce-ot); a pénztárnak saját pipája van.
    if (empty($_POST['mandala_nl_reg']) || !isset($_POST['register'])) { // phpcs:ignore WordPress.Security.NonceVerification
        return;
    }
    $u = get_userdata((int) $customer_id);
    if ($u) {
        mandala_newsletter_subscribe($u->user_email, 'regisztracio', mandala_consent_wording(__('Regisztráció', 'mandala'), mandala_consent_text('account')), ['user_id' => (int) $customer_id, 'name' => mandala_consent_user_name($u)]);
    }
});

/* ---------- Megőrzés: a visszavont hozzájárulások 5 év után törlődnek ---------- */

mandala_recurring('mandala_consent_cleanup', DAY_IN_SECONDS, fn() => time() + 2 * HOUR_IN_SECONDS);
add_action('mandala_consent_cleanup', 'mandala_consent_cleanup');
function mandala_consent_cleanup(): int
{
    global $wpdb;
    if (get_option('mandala_consent_db') !== MANDALA_CONSENT_DB_VERSION) {
        return 0;
    }
    $t = mandala_consent_table();
    $cutoff = gmdate('Y-m-d H:i:s', time() - MANDALA_CONSENT_KEEP_YEARS * YEAR_IN_SECONDS);
    // Cím + cél, amelynek az utolsó sora visszavonás, és régebbi a határidőnél.
    $pairs = $wpdb->get_results($wpdb->prepare("SELECT c.email, c.purpose FROM $t c INNER JOIN (SELECT MAX(id) id FROM $t GROUP BY email, purpose) m ON m.id = c.id WHERE c.granted = 0 AND c.created < %s LIMIT 500", $cutoff), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
    $n = 0;
    foreach ((array) $pairs as $p) {
        $n += (int) $wpdb->delete($t, ['email' => $p['email'], 'purpose' => $p['purpose']]);
    }
    return $n;
}

/* ---------- Adatvédelem: a hozzájárulások az adatexportban ---------- */

add_filter('wp_privacy_personal_data_exporters', function ($exporters) {
    $exporters['mandala-consent'] = ['exporter_friendly_name' => 'Mandala – hozzájárulások', 'callback' => function ($email) {
        global $wpdb;
        $data = [];
        if (get_option('mandala_consent_db') === MANDALA_CONSENT_DB_VERSION) {
            foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . mandala_consent_table() . ' WHERE email = %s ORDER BY id', strtolower((string) $email)), ARRAY_A) as $r) { // phpcs:ignore WordPress.DB.PreparedSQL
                $data[] = ['group_id' => 'mandala-consent', 'group_label' => 'Hozzájárulások', 'item_id' => 'consent-' . $r['id'], 'data' => [
                    ['name' => 'Cél', 'value' => MANDALA_CONSENT_PURPOSES[$r['purpose']] ?? $r['purpose']],
                    ['name' => 'Állapot', 'value' => $r['granted'] ? 'megadta' : 'visszavonta'],
                    ['name' => 'Időpont (UTC)', 'value' => $r['created']],
                    ['name' => 'Forrás', 'value' => $r['source']],
                    ['name' => 'Szöveg', 'value' => $r['wording']],
                    ['name' => 'IP-cím', 'value' => $r['ip']],
                ]];
            }
        }
        return ['data' => $data, 'done' => true];
    }];
    return $exporters;
});

/* ---------- Admin: CSV-k (Eszközök → Mandala űrlapok) ---------- */

/**
 * Egy CSV-sor a látogatók által beírt szövegekkel (név, cég, a pipa szövege): a =, +, -, @ (és tab / CR) kezdetű mező
 * elé aposztróf kerül, hogy Excelben ne fusson le képletként (CSV-befecskendezés).
 */
function mandala_csv_row(array $row): array
{
    return array_map(function ($v) {
        $v = (string) $v;
        return $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
    }, $row);
}

function mandala_consent_csv_links(): string
{
    $url = fn($what) => esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_consent_csv&what=' . $what), 'mandala_csv'));
    return '<a class="button" href="' . $url('partner') . '">Partnerlevél-hozzájárulások (CSV)</a> '
        . '<a class="button" href="' . $url('log') . '">Teljes hozzájárulás-napló (CSV)</a>';
}

add_action('admin_post_mandala_consent_csv', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('mandala_csv')) {
        wp_die('Nincs jogosultság.');
    }
    global $wpdb;
    $what = sanitize_key($_GET['what'] ?? 'log');
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mandala-hozzajarulas-' . $what . '-' . wp_date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($what === 'partner') {
        // Viszonteladók és minden fiók, amelyen partnerlevél / heti levél állapot van.
        $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
        $ids = array_unique(array_merge(
            get_users(['role__in' => $roles, 'fields' => 'ID']),
            get_users(['meta_key' => '_mandala_partner_mail', 'fields' => 'ID']),
            get_users(['meta_key' => '_mandala_b2b_arrivals', 'fields' => 'ID'])
        ));
        $emails = array_map(fn($id) => strtolower((string) get_userdata((int) $id)->user_email), $ids);
        $proof = $emails ? mandala_consent_latest('partner_mail', $emails) : [];
        $proof_arr = $emails ? mandala_consent_latest('b2b_arrivals', $emails) : [];
        fputcsv($out, ['email', 'cég', 'név', 'partnerlevél', 'partnerlevél dátuma', 'forrás', 'hozzájárulás szövege', 'változat', 'IP', 'heti új érkezések', 'heti levél hozzájárulás dátuma (UTC)', 'heti levél IP', 'leiratkozott (minden reklámlevél)'], ';', '"', '');
        foreach ($ids as $id) {
            $u = get_userdata((int) $id);
            $e = strtolower((string) $u->user_email);
            $m = fn($k) => (string) get_user_meta((int) $id, $k, true);
            $p = $proof[$e] ?? [];
            $a = $proof_arr[$e] ?? [];
            fputcsv($out, mandala_csv_row([$e, $m('billing_company'), trim($m('billing_last_name') . ' ' . $m('billing_first_name')) ?: $u->display_name,
                $m('_mandala_partner_mail') === 'yes' ? 'kér' : ($m('_mandala_partner_mail') === 'no' ? 'nem kér' : ''), $m('_mandala_partner_mail_at'), $m('_mandala_partner_mail_source') ?: ($p['source'] ?? ''),
                $m('_mandala_partner_mail_wording') ?: ($p['wording'] ?? ''), $p['version'] ?? '', $p['ip'] ?? '',
                $m('_mandala_b2b_arrivals') === 'yes' ? 'kér' : ($m('_mandala_b2b_arrivals') === 'no' ? 'nem kér' : ''), $a['created'] ?? '', $a['ip'] ?? '',
                mandala_is_unsubscribed($e) ? 'igen' : 'nem']), ';', '"', '');
        }
    } else {
        fputcsv($out, ['id', 'időpont (UTC)', 'email', 'név', 'cél', 'állapot', 'forrás', 'hozzájárulás szövege', 'változat', 'IP', 'fiók'], ';', '"', '');
        $t = mandala_consent_table();
        for ($last = 0; ; ) {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t WHERE id > %d ORDER BY id LIMIT 2000", $last), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
            foreach ((array) $rows as $r) {
                fputcsv($out, mandala_csv_row([$r['id'], $r['created'], $r['email'], $r['name'], MANDALA_CONSENT_PURPOSES[$r['purpose']] ?? $r['purpose'], $r['granted'] ? 'megadta' : 'visszavonta', $r['source'], $r['wording'], $r['version'], $r['ip'], (int) $r['user_id'] ?: '']), ';', '"', '');
                $last = (int) $r['id'];
            }
            if (count((array) $rows) < 2000) {
                break;
            }
        }
    }
    exit;
});
