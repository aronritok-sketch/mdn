<?php
/**
 * Mandala Partner CRM – a viszonteladó oldala (a crm-connect.php protokollja mellé).
 *
 *  - terms parancs (a CRM-ből): kedvezményszint (a százalékot a CRM küldheti, vagy WooCommerce → Partner CRM →
 *    Kedvezményszintek), fizetési határidő (halasztott átutalás a pénztárban, a Számlázz.hu-számla határideje ebből),
 *    rendelés-tiltás (a pénztárban csak előre utalás; a fiókban és a pénztárban rövid tájékoztató);
 *  - Fiókom → Üzenet a Mandalának → message esemény (a CRM-ben teendő a felelősnek, a válasz e-mailben megy);
 *  - partnerlevél-hozzájárulás: pipa a viszonteladói jelentkezésben, kapcsoló a viszonteladói felületen
 *    (a customer eseményben: marketing_consent + marketing_consent_at);
 *  - egy kattintásos rendelés ajánlatból: https://mandala.hu/?mandala_offer=<base64url(json)>.<hex HMAC-SHA256(json,
 *    az összekötő titka)>, json = {t: token, e: partner e-mail, i: [[cikkszám, db], …], x: lejárat (unix), p?: [[cikkszám,
 *    kedvezményes nettó egységár], …]} – a tételek a kosárba (nagyker áron, vagy a p ára, ha nem drágább), a rendelés
 *    `_mandala_offer_token`-t kap (a tétel `_mandala_offer_price`-t); offer_open / offer_order esemény, az order
 *    eseményben offer_token. Az ajánlati ár legfeljebb az ajánlott darabszámig jár (a többi darab a szokott áron), a
 *    lejárat a kosárban is számít, és a link csak akkor használható újra, ha belőle nincs sikeres (függő, várakozó,
 *    feldolgozás alatti, teljesített …) rendelés – lemondott / sikertelen / visszatérített után igen. A link a fiók
 *    e-mail-címéhez (user_email) kötött. Tesztvektor: docs/PARTNER-CRM.md és tests/wp-features.mjs.
 */

defined('ABSPATH') || exit;

const MANDALA_DEFERRED_GATEWAY = 'mandala_deferred';
const MANDALA_OFFER_COOKIE = 'mandala_offer';
const MANDALA_MSG_ENDPOINT = 'uzenet';

/* ---------- Feltételek (terms parancs) ---------- */

/**
 * A CRM terms parancsa: { email, user_id?, discount_level?, discount_pct?, payment_days?, block?: { on, reason? } }.
 * Csak a megadott kulcsok változnak (üres szint / 0 nap = törlés).
 */
function mandala_crm_terms(array $d): array
{
    $user = mandala_crm_find_user($d);
    if (!$user) {
        throw new RuntimeException('nincs ilyen fiók a webshopban');
    }
    $uid = (int) $user->ID;
    if (array_key_exists('discount_level', $d)) {
        $level = sanitize_text_field((string) $d['discount_level']);
        if ($level === '') {
            delete_user_meta($uid, '_mandala_discount_level');
        } else {
            update_user_meta($uid, '_mandala_discount_level', $level);
        }
    }
    if (array_key_exists('discount_pct', $d)) {
        if ($d['discount_pct'] === null || $d['discount_pct'] === '') {
            delete_user_meta($uid, '_mandala_discount_pct');
        } else {
            update_user_meta($uid, '_mandala_discount_pct', max(0.0, min(50.0, (float) $d['discount_pct'])));
        }
    }
    if (array_key_exists('payment_days', $d)) {
        $days = max(0, min(120, (int) $d['payment_days']));
        if ($days) {
            update_user_meta($uid, '_mandala_payment_days', $days);
        } else {
            delete_user_meta($uid, '_mandala_payment_days');
        }
    }
    if (isset($d['block']) && is_array($d['block'])) {
        if (!empty($d['block']['on'])) {
            update_user_meta($uid, '_mandala_order_block', ['on' => true, 'reason' => sanitize_text_field((string) ($d['block']['reason'] ?? '')), 'at' => current_time('mysql')]);
        } else {
            delete_user_meta($uid, '_mandala_order_block');
        }
    }
    update_user_meta($uid, '_mandala_crm_terms_at', current_time('mysql'));
    return ['user_id' => $uid] + mandala_crm_terms_of($uid);
}

function mandala_crm_terms_of(int $uid): array
{
    $block = $uid ? get_user_meta($uid, '_mandala_order_block', true) : '';
    return [
        'discount_level' => $uid ? (string) get_user_meta($uid, '_mandala_discount_level', true) : '',
        'discount_pct' => mandala_crm_discount_pct($uid),
        'payment_days' => mandala_crm_payment_days($uid),
        'blocked' => is_array($block) && !empty($block['on']),
        'block_reason' => is_array($block) ? (string) ($block['reason'] ?? '') : '',
    ];
}

function mandala_crm_payment_days(int $uid): int
{
    return $uid ? max(0, (int) get_user_meta($uid, '_mandala_payment_days', true)) : 0;
}

function mandala_crm_is_blocked(int $uid): bool
{
    $b = $uid ? get_user_meta($uid, '_mandala_order_block', true) : '';
    return is_array($b) && !empty($b['on']);
}

/**
 * A szint százaléka: a CRM-től kapott szám (aláírt terms parancs), vagy a webshop beállítása (WooCommerce → Partner CRM →
 * Kedvezményszintek). A szint NEVÉBŐL nem olvasunk százalékot (egy „50%” nevű szint ne adjon magától kedvezményt).
 */
function mandala_crm_discount_pct(int $uid): float
{
    if (!$uid) {
        return 0.0;
    }
    $own = get_user_meta($uid, '_mandala_discount_pct', true);
    if ($own !== '' && $own !== false) {
        return max(0.0, min(50.0, (float) $own));
    }
    $level = mb_strtolower(trim((string) get_user_meta($uid, '_mandala_discount_level', true)));
    if ($level === '') {
        return 0.0;
    }
    foreach ((array) get_option('mandala_crm_levels', []) as $name => $pct) {
        if (mb_strtolower(trim((string) $name)) === $level) {
            return max(0.0, min(50.0, (float) $pct));
        }
    }
    return 0.0;
}

/** „5”, „2,5” – a százalék magyarosan. */
function mandala_crm_pct_label(float $pct): string
{
    return str_replace('.', ',', rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.'));
}

/* ---------- Pénztár: rendelés-tiltás → csak előre fizetés ---------- */

/** Tiltott fióknál: csak az előre utalás; ha az ki van kapcsolva, ami szintén előre fizetés (kártya) – utánvét, helyszíni és halasztott fizetés nem. */
function mandala_crm_prepay_only(array $gateways): array
{
    $prepay = array_intersect_key($gateways, array_flip((array) apply_filters('mandala_crm_prepay_gateways', ['bacs'])));
    return $prepay ?: array_diff_key($gateways, array_flip((array) apply_filters('mandala_crm_deferred_gateways', ['cod', 'cheque', MANDALA_DEFERRED_GATEWAY])));
}

add_filter('woocommerce_available_payment_gateways', function ($gateways) {
    if ((is_admin() && !wp_doing_ajax()) || !is_array($gateways) || !mandala_crm_is_blocked(get_current_user_id())) {
        return $gateways;
    }
    return mandala_crm_prepay_only($gateways);
}, 30);

function mandala_crm_block_notice(bool $account): string
{
    $bacs = (array) get_option('woocommerce_bacs_settings', []);
    $how = ($bacs['enabled'] ?? 'no') === 'yes' ? __('előre utalással', 'mandala') : __('előre fizetéssel', 'mandala');
    $reason = mandala_crm_terms_of(get_current_user_id())['block_reason']; // a CRM beállításából, a partnernek szól
    $text = '<strong>' . esc_html(sprintf(__('Most %s tudsz rendelni.', 'mandala'), $how)) . '</strong> '
        . ($reason !== '' ? esc_html($reason) : esc_html__('Amint a nyitott számláid rendeződnek, újra választhatod a megszokott fizetési módot.', 'mandala'));
    if ($account) {
        $phone = (string) (mandala_config('contact', [])['phone'] ?? '');
        $text .= ' ' . sprintf(
            /* translators: 1: link az üzenet-oldalra, 2: telefonszám */
            esc_html__('Kérdésed van? %1$s, vagy hívj minket: %2$s.', 'mandala'),
            '<a href="' . esc_url(wc_get_account_endpoint_url(MANDALA_MSG_ENDPOINT)) . '">' . esc_html__('Írj nekünk', 'mandala') . '</a>',
            '<a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $phone)) . '">' . esc_html($phone) . '</a>'
        );
    }
    return '<div class="woocommerce-info crm-block-notice" role="status">' . mandala_icon('info') . '<span>' . $text . '</span></div>';
}

add_action('woocommerce_review_order_before_payment', function () {
    if (mandala_crm_is_blocked(get_current_user_id())) {
        echo mandala_crm_block_notice(false); // phpcs:ignore WordPress.Security.EscapeOutput
    }
});
add_action('woocommerce_account_content', function () {
    if (mandala_crm_is_blocked(get_current_user_id())) {
        echo mandala_crm_block_notice(true); // phpcs:ignore WordPress.Security.EscapeOutput
    }
}, 5);

/* ---------- Halasztott átutalás (fizetési határidős viszonteladóknak) ---------- */

if (class_exists('WC_Payment_Gateway') && !class_exists('Mandala_Gateway_Deferred')) {
    /**
     * Átutalás számla alapján: csak annak a viszonteladónak látszik, akinek a CRM fizetési határidőt adott, és nincs
     * rendelési tiltás alatt. A rendelés azonnal feldolgozás alá kerül (csomagolható); a határidő a rendelésben
     * (_mandala_payment_days, _mandala_payment_due) és a Számlázz.hu-számlán.
     */
    class Mandala_Gateway_Deferred extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = MANDALA_DEFERRED_GATEWAY;
            $this->has_fields = false;
            $this->method_title = 'Átutalás számla alapján (viszonteladók)';
            $this->method_description = 'Halasztott fizetés: csak annak a viszonteladónak látszik, akinek a Partner CRM-ben fizetési határidő van beállítva, és nincs rendelési tiltás alatt. A rendelés azonnal feldolgozás alá kerül; a Számlázz.hu-számla fizetési határideje a partneré.';
            $this->init_form_fields();
            $this->init_settings();
            $this->title = (string) $this->get_option('title');
            $this->description = (string) $this->get_option('description');
            $this->enabled = (string) $this->get_option('enabled');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => ['title' => 'Bekapcsolva', 'type' => 'checkbox', 'label' => 'Halasztott átutalás a fizetési határidős viszonteladóknak', 'default' => 'yes'],
                'title' => ['title' => 'Cím', 'type' => 'text', 'default' => 'Átutalás számla alapján'],
                'description' => ['title' => 'Leírás', 'type' => 'textarea', 'description' => 'A {nap} helyére a partner fizetési határideje kerül.', 'default' => 'A számlát e-mailben küldjük, {nap} napos fizetési határidővel – a csomagot nem kell előre kifizetned.'],
            ];
        }

        public function is_available()
        {
            $uid = get_current_user_id();
            return parent::is_available() && $uid && mandala_is_wholesale_user($uid) && mandala_crm_payment_days($uid) > 0 && !mandala_crm_is_blocked($uid);
        }

        public function get_description()
        {
            return str_replace('{nap}', (string) mandala_crm_payment_days(get_current_user_id()), (string) parent::get_description());
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $days = mandala_crm_payment_days((int) $order->get_customer_id());
            $due = wp_date('Y-m-d', time() + $days * DAY_IN_SECONDS);
            $order->update_meta_data('_mandala_payment_days', $days);
            $order->update_meta_data('_mandala_payment_due', $due);
            $order->update_status('processing', sprintf('Halasztott átutalás: %d napos fizetési határidő (%s).', $days, $due));
            if (WC()->cart) {
                WC()->cart->empty_cart();
            }
            return ['result' => 'success', 'redirect' => $this->get_return_url($order)];
        }
    }

    add_filter('woocommerce_payment_gateways', function ($gateways) {
        $gateways[] = 'Mandala_Gateway_Deferred';
        return $gateways;
    });
}

// Nem kártyás mód: a csalásvédelem (antifraud.php) kártyás tiltása ne vonatkozzon rá.
add_filter('mandala_fraud_settings', function ($s) {
    $s['offline_gateways'] = array_values(array_unique(array_merge((array) ($s['offline_gateways'] ?? []), [MANDALA_DEFERRED_GATEWAY])));
    return $s;
});

/**
 * Számlázz.hu: a halasztott átutalásos rendelés számláján a fizetési határidő a partneré (a számla kelte + N nap) – ha a
 * számlát kézzel, egyedi határidővel állítják ki, az marad.
 */
add_filter('wc_szamlazz_xml', function ($szamla, $order = null, $type = 'invoice', $options = []) {
    if (!$szamla instanceof SimpleXMLElement || !$order instanceof WC_Order || !in_array($type, ['invoice', 'proform', 'deposit'], true)
        || $order->get_payment_method() !== MANDALA_DEFERRED_GATEWAY || isset($_POST['deadline']) || isset($options['deadline']) || isset($options['deadline_date'])) { // phpcs:ignore WordPress.Security.NonceVerification
        return $szamla;
    }
    $days = (int) $order->get_meta('_mandala_payment_days');
    if ($days > 0 && isset($szamla->fejlec->fizetesiHataridoDatum)) {
        $base = (string) ($szamla->fejlec->keltDatum ?? '') ?: wp_date('Y-m-d');
        $szamla->fejlec->fizetesiHataridoDatum = (new DateTimeImmutable($base, wp_timezone()))->modify("+$days day")->format('Y-m-d');
    }
    return $szamla;
}, 10, 4);

// A rendelés oldalán (admin): határidő, kedvezményszint, ajánlat.
add_action('woocommerce_admin_order_data_after_billing_address', function (WC_Order $order) {
    $rows = [];
    if ($days = (int) $order->get_meta('_mandala_payment_days')) {
        $rows[] = sprintf('Fizetési határidő: %d nap (%s)', $days, (string) $order->get_meta('_mandala_payment_due'));
    }
    if ($level = (string) $order->get_meta('_mandala_discount_level')) {
        $rows[] = 'Kedvezményszint: ' . $level;
    }
    if ($token = (string) $order->get_meta('_mandala_offer_token')) {
        $rows[] = 'Ajánlatból (Partner CRM): ' . $token;
    }
    foreach ($rows as $r) {
        echo '<p>' . esc_html($r) . '</p>';
    }
});

/* ---------- Ár a kosárban: ajánlati ár, kedvezményszint ---------- */

/** Az ajánlati ár (CRM: nettó egységár) a bolt árformájában (nálunk bruttó: + a termék ÁFÁ-ja). */
function mandala_offer_shop_price(WC_Product $product, float $net): float
{
    if (!wc_prices_include_tax() || !wc_tax_enabled()) {
        return round($net, wc_get_price_decimals());
    }
    $rates = WC_Tax::get_base_tax_rates($product->get_tax_class('unfiltered'));
    return round($net + array_sum(WC_Tax::calc_tax($net, $rates, false)), wc_get_price_decimals());
}

/** Az ajánlati ár csak olcsóbbá teheti a tételt: ha a partner szokott (nagyker) ára ennél kisebb, az marad. */
function mandala_offer_unit_price(WC_Product $product, float $net, float $current): float
{
    $offer = mandala_offer_shop_price($product, $net);
    return $offer > 0 && ($current <= 0 || $offer <= $current) ? $offer : $current;
}

/**
 * Érvényes-e még a kosártétel ajánlati ára: nem járt le (x), és az ajánlatból még nincs sikeres rendelés. A régi
 * (frissítés előtt kosárba tett) tételnél nincs lejárat – az ajánlat-link ellenőrzése akkor megvolt.
 */
function mandala_offer_item_valid(array $item, int $uid): bool
{
    if (empty($item['mandala_offer'])) {
        return false;
    }
    if (!empty($item['mandala_offer_x']) && (int) $item['mandala_offer_x'] < time()) {
        return false;
    }
    return !$uid || !mandala_offer_used((string) $item['mandala_offer'], $uid);
}

/**
 * Az ajánlatból van-e már sikeres rendelés (egy kérésen belül gyorsítótárazva). Sikeres: minden állapot, ami nem
 * lemondott, sikertelen, visszatérített vagy piszkozat (függő, várakozó, feldolgozás alatt, teljesített, szállítva …).
 */
function mandala_offer_used(string $token, int $uid): ?WC_Order
{
    static $cache = [];
    $key = $uid . '|' . $token;
    if (!array_key_exists($key, $cache)) {
        $ok = array_values(array_diff(array_map(fn($s) => preg_replace('/^wc-/', '', $s), array_keys(wc_get_order_statuses())), ['cancelled', 'failed', 'refunded', 'checkout-draft', 'trash']));
        $found = $token !== '' ? wc_get_orders(['customer_id' => $uid, 'limit' => 3, 'meta_key' => '_mandala_offer_token', 'meta_value' => $token, 'status' => $ok]) : []; // phpcs:ignore WordPress.DB.SlowDBQuery
        // A fizetésre váró, újrapróbált rendelés (a WooCommerce ugyanazt használja újra) nem számít „már megrendeltnek”.
        $retry = function_exists('WC') && WC()->session ? (int) WC()->session->get('order_awaiting_payment') : 0;
        $found = array_values(array_filter($found, fn($o) => (int) $o->get_id() !== $retry));
        $cache[$key] = $found ? $found[0] : null;
    }
    return $cache[$key];
}

/**
 * A tétel egységára az ajánlattal: az ajánlati ár legfeljebb az ajánlott darabszámig jár, a többi darab a szokott
 * áron ($normal: nagyker ár a kedvezményszinttel) – a kettő súlyozott átlaga egy egységárban (a WooCommerce tételenként
 * egy árat ismer). Ajánlott darabszám nélkül (régi kosártétel) minden darab ajánlati áron.
 */
function mandala_offer_blend_price(float $offer, float $normal, int $qty, int $offered): float
{
    if ($offered <= 0 || $qty <= $offered) {
        return $offer;
    }
    return round(($offer * $offered + $normal * ($qty - $offered)) / $qty, wc_get_price_decimals());
}

/**
 * A nagyker ár (a Wholesale Prices bővítmény a 10-es prioritáson állítja be) után: az ajánlat tételén az ajánlati ár
 * (ha van, nem drágább a szokottnál, nem járt le, és az ajánlatból még nincs sikeres rendelés) legfeljebb az ajánlott
 * darabszámig, a többi darabon és a többi tételen a partner kedvezményszintje. Egy kérésen belül többször is lefuthat –
 * az eredeti árból számolunk (amit már beállítottunk, azt nem csökkentjük újra). A JUTA a tétel árát kapja, ezért nem
 * díjként (negatív tételként).
 */
add_action('woocommerce_before_calculate_totals', function ($cart) {
    static $base = [], $applied = [];
    if ((is_admin() && !wp_doing_ajax()) || !$cart instanceof WC_Cart) {
        return;
    }
    $uid = get_current_user_id();
    $level = $uid && mandala_is_wholesale_user($uid) ? mandala_crm_discount_pct($uid) : 0.0;
    foreach ($cart->get_cart() as $item) {
        $product = $item['data'] ?? null;
        if (!$product instanceof WC_Product) {
            continue;
        }
        $key = spl_object_id($product);
        $cur = (float) $product->get_price('edit');
        if (isset($applied[$key]) && abs($applied[$key] - $cur) < 0.0001) {
            $cur = $base[$key]; // már beállítottuk: az eredeti (nagyker) árból számolunk újra
        }
        $base[$key] = $cur;
        $normal = $level > 0 && $cur > 0 ? round($cur * (1 - $level / 100), wc_get_price_decimals()) : $cur;
        $price = $normal;
        if (isset($item['mandala_offer_price']) && (float) $item['mandala_offer_price'] > 0 && mandala_offer_item_valid($item, $uid)) {
            $unit = mandala_offer_unit_price($product, (float) $item['mandala_offer_price'], $normal);
            $price = mandala_offer_blend_price($unit, $normal, (int) ($item['quantity'] ?? 1), (int) ($item['mandala_offer_qty'] ?? 0));
        }
        if ($price !== $cur) {
            $product->set_price($price);
        }
        $applied[$key] = $price;
    }
}, 1000);

// A kosárban: ha az ajánlat lejárt, vagy már megrendelték, szólunk (a tétel a szokott áron marad).
add_action('woocommerce_check_cart_items', function () {
    if (!function_exists('WC') || !WC()->cart) {
        return;
    }
    $uid = get_current_user_id();
    $told = [];
    foreach (WC()->cart->get_cart() as $item) {
        $t = (string) ($item['mandala_offer'] ?? '');
        if ($t === '' || isset($told[$t]) || empty($item['mandala_offer_price']) || mandala_offer_item_valid($item, $uid)) {
            continue;
        }
        $told[$t] = true;
        wc_add_notice(!empty($item['mandala_offer_x']) && (int) $item['mandala_offer_x'] < time()
            ? sprintf(__('Az ajánlat %s-ig volt érvényes – a tételei a szokott áron maradtak a kosárban.', 'mandala'), wp_date('Y. m. d.', (int) $item['mandala_offer_x']))
            : __('Ezt az ajánlatot már megrendelted – a tételei a szokott áron maradtak a kosárban.', 'mandala'), 'notice');
    }
});

add_filter('woocommerce_get_item_data', function ($data, $item) {
    if (!empty($item['mandala_offer'])) {
        $valid = mandala_offer_item_valid((array) $item, get_current_user_id());
        $offered = (int) ($item['mandala_offer_qty'] ?? 0);
        $value = !empty($item['mandala_offer_price']) && $valid
            ? ($offered && (int) ($item['quantity'] ?? 0) > $offered ? sprintf(__('%d db ajánlati áron, a többi a szokott áron', 'mandala'), $offered) : __('ajánlati áron', 'mandala'))
            : __('nagyker áron', 'mandala');
        $data[] = ['key' => __('Ajánlatból', 'mandala'), 'value' => $value];
    }
    return $data;
}, 10, 2);

// A rendelésbe: az ajánlat azonosítója (rendelés + tétel), az ajánlati nettó egységár, a partner kedvezményszintje.
add_action('woocommerce_checkout_create_order_line_item', function ($line, $cart_key, $values, $order) {
    $uid = (int) $order->get_customer_id();
    if (!empty($values['mandala_offer']) && mandala_offer_item_valid((array) $values, $uid)) {
        $line->add_meta_data('_mandala_offer', (string) $values['mandala_offer'], true);
        $order->update_meta_data('_mandala_offer_token', (string) $values['mandala_offer']);
        if (!empty($values['mandala_offer_price'])) {
            $line->add_meta_data('_mandala_offer_price', (float) $values['mandala_offer_price'], true);
            return;
        }
    }
    $pct = $uid && mandala_is_wholesale_user($uid) ? mandala_crm_discount_pct($uid) : 0.0;
    if ($pct > 0) {
        $line->add_meta_data(__('Kedvezmény', 'mandala'), '−' . mandala_crm_pct_label($pct) . '%', true);
    }
}, 10, 4);
add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    $uid = (int) $order->get_customer_id();
    $level = $uid ? (string) get_user_meta($uid, '_mandala_discount_level', true) : '';
    if ($level !== '' && mandala_is_wholesale_user($uid)) {
        $order->update_meta_data('_mandala_discount_level', $level);
    }
});

/* ---------- Viszonteladói felület: feltételek, partnerlevél-hozzájárulás ---------- */

add_action('mandala_b2b_before', function ($uid) {
    $t = mandala_crm_terms_of((int) $uid);
    $parts = [];
    if ($t['discount_level'] !== '') {
        $parts[] = esc_html__('Kedvezményszint:', 'mandala') . ' <strong>' . esc_html($t['discount_level']) . '</strong>'
            . ($t['discount_pct'] > 0 ? ' ' . esc_html(sprintf(__('(−%s%% a nagyker árból)', 'mandala'), mandala_crm_pct_label($t['discount_pct']))) : '');
    }
    if ($t['payment_days'] > 0 && !$t['blocked']) {
        $parts[] = esc_html__('Fizetés:', 'mandala') . ' <strong>' . esc_html(sprintf(__('átutalás számla alapján, %d napos határidővel', 'mandala'), $t['payment_days'])) . '</strong>';
    }
    if ($parts) {
        echo '<p class="b2b-terms">' . implode(' · ', $parts) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
});

add_action('mandala_b2b_tools', function ($uid) {
    $on = get_user_meta((int) $uid, '_mandala_partner_mail', true) === 'yes'; ?>
  <form method="post" class="b2b-consent">
    <?php wp_nonce_field('mandala_partner_mail', '_pmnonce'); ?>
    <input type="hidden" name="mandala_partner_mail_form" value="1">
    <label class="check"><input type="checkbox" name="mandala_partner_mail" value="1"<?php checked($on); ?> onchange="this.form.submit()"> <span><?php esc_html_e('Kérek partnerleveleket (új érkezések, akciók)', 'mandala'); ?></span></label>
    <noscript><button class="iu-button iu-button-link" type="submit"><?php esc_html_e('Mentés', 'mandala'); ?></button></noscript>
  </form>
    <?php
});

/** Partnerlevél-hozzájárulás mentése; viszonteladónál a CRM is megkapja (customer esemény). */
function mandala_crm_set_consent(int $uid, bool $on, bool $notify = true): void
{
    $old = (string) get_user_meta($uid, '_mandala_partner_mail', true);
    update_user_meta($uid, '_mandala_partner_mail', $on ? 'yes' : 'no');
    if ($old !== ($on ? 'yes' : 'no') || !get_user_meta($uid, '_mandala_partner_mail_at', true)) {
        update_user_meta($uid, '_mandala_partner_mail_at', current_time('mysql'));
    }
    if ($notify && $old !== ($on ? 'yes' : 'no') && mandala_is_wholesale_user($uid)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data($uid));
    }
}

add_action('template_redirect', function () {
    if (!isset($_POST['_pmnonce']) || !is_user_logged_in() || !wp_verify_nonce(sanitize_key($_POST['_pmnonce']), 'mandala_partner_mail')) {
        return;
    }
    $on = !empty($_POST['mandala_partner_mail']);
    mandala_crm_set_consent(get_current_user_id(), $on);
    wc_add_notice($on ? __('Köszönjük! Az új érkezésekről és az akciókról partnerlevélben írunk.', 'mandala') : __('Rendben, nem küldünk több partnerlevelet.', 'mandala'));
    wp_safe_redirect(wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT));
    exit;
});

/* ---------- Partnerlevél-hozzájárulás a viszonteladói jelentkezésben ---------- */

// A pipa a „Viszonteladóknak” oldal űrlapjába (az adatkezelési hozzájárulás elé) – az oldal tartalmához nem kell nyúlni.
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') !== 'iu/form' || ($block['attrs']['formId'] ?? $block['attrs']['id'] ?? '') !== 'viszontelado' || str_contains((string) $html, 'name="partnerlevelek"')) {
        return $html;
    }
    $box = '<p class="form-optin"><label class="check"><input type="checkbox" name="partnerlevelek" value="1"> <span>' . esc_html__('Kérek partnerleveleket (új érkezések, akciók)', 'mandala') . '</span></label></p>';
    foreach (['<div class="iu-form-accept', '<div class="iu-form-submit', '</form>'] as $marker) {
        $pos = strpos((string) $html, $marker);
        if ($pos !== false) {
            return substr_replace((string) $html, $box, $pos, 0);
        }
    }
    return $html;
}, 15, 2);

// Beküldéskor: ha van fiókja, oda; ha még nincs, a fiók létrejöttekor (jóváhagyás) kerül rá.
add_filter('iu_form_submit_viszontelado', function ($response) {
    $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- az iu_theme űrlapkezelője fogadja
    if (!is_array($response) || empty($response['success']) || !empty($response['errors']) || !empty($post['website'])) {
        return $response;
    }
    $email = strtolower(sanitize_email((string) ($post['email'] ?? '')));
    if (!is_email($email)) {
        return $response;
    }
    $on = !empty($post['partnerlevelek']);
    $user = get_user_by('email', $email);
    if ($user) {
        mandala_crm_set_consent((int) $user->ID, $on);
    } else {
        $pending = (array) get_option('mandala_crm_pending_consent', []);
        $pending[$email] = ['on' => $on, 'at' => current_time('mysql')];
        update_option('mandala_crm_pending_consent', array_slice($pending, -300, null, true), false);
    }
    return $response;
}, 15);

add_action('user_register', function ($uid) {
    $u = get_userdata((int) $uid);
    $pending = (array) get_option('mandala_crm_pending_consent', []);
    $key = $u ? strtolower($u->user_email) : '';
    if ($key === '' || !isset($pending[$key])) {
        return;
    }
    update_user_meta((int) $uid, '_mandala_partner_mail', !empty($pending[$key]['on']) ? 'yes' : 'no');
    update_user_meta((int) $uid, '_mandala_partner_mail_at', (string) $pending[$key]['at']);
    unset($pending[$key]);
    update_option('mandala_crm_pending_consent', $pending, false);
}, 20); // a crm-connect.php customer eseménye (30) már ezzel megy

/* ---------- Fiókom → Üzenet a Mandalának ---------- */

add_filter('woocommerce_get_query_vars', fn($vars) => $vars + [MANDALA_MSG_ENDPOINT => MANDALA_MSG_ENDPOINT]);
add_action('init', function () {
    if (get_option('mandala_rewrite_uzenet') !== '1') {
        add_action('wp_loaded', fn() => flush_rewrite_rules(false));
        update_option('mandala_rewrite_uzenet', '1');
    }
}, 99);
add_filter('woocommerce_account_menu_items', function ($items) {
    if (!mandala_is_wholesale_user()) {
        return $items;
    }
    $out = [];
    foreach ($items as $k => $label) {
        if ($k === 'customer-logout' && !isset($out[MANDALA_MSG_ENDPOINT])) {
            $out[MANDALA_MSG_ENDPOINT] = __('Üzenet a Mandalának', 'mandala');
        }
        $out[$k] = $label;
        if ($k === MANDALA_B2B_ENDPOINT) {
            $out[MANDALA_MSG_ENDPOINT] = __('Üzenet a Mandalának', 'mandala');
        }
    }
    return $out + [MANDALA_MSG_ENDPOINT => __('Üzenet a Mandalának', 'mandala')];
}, 20);
add_filter('woocommerce_endpoint_' . MANDALA_MSG_ENDPOINT . '_title', fn() => __('Üzenet a Mandalának', 'mandala'));

/** A partner legutóbbi üzenetei (a fiókban és a ráta-korláthoz): [{at, subject}], a legújabb elöl. */
function mandala_crm_messages(int $uid): array
{
    $log = get_user_meta($uid, '_mandala_crm_messages', true);
    return is_array($log) ? array_values($log) : [];
}

/**
 * Üzenet a CRM-nek (message esemény; ha nincs összekötve, levél a bolt címére). Ráta-korlát: óránként 5, egymás után
 * 20 másodperc.
 *
 * @return true|WP_Error
 */
function mandala_crm_send_message(int $uid, string $subject, string $body, bool $spam = false)
{
    $u = get_userdata($uid);
    if (!$u) {
        return new WP_Error('mandala_msg', __('Lépj be a fiókodba, és küldd el újra.', 'mandala'));
    }
    $subject = mb_substr(trim(sanitize_text_field($subject)), 0, 150);
    $body = mb_substr(trim(sanitize_textarea_field($body)), 0, 5000);
    if ($spam) {
        return true; // a rejtett mezőt ember nem tölti ki – csendben elnyeljük
    }
    if (mb_strlen($body) < 2) {
        return new WP_Error('mandala_msg', __('Írd meg az üzeneted, és már küldjük is.', 'mandala'));
    }
    $log = mandala_crm_messages($uid);
    $hour = array_filter($log, fn($m) => (int) ($m['at'] ?? 0) > time() - HOUR_IN_SECONDS);
    if (count($hour) >= 5 || ($log && (int) ($log[0]['at'] ?? 0) > time() - 20)) {
        return new WP_Error('mandala_msg', sprintf(__('Most sok üzenet ment el egymás után – kérlek, próbáld újra egy kicsit később, vagy hívj minket: %s.', 'mandala'), (string) (mandala_config('contact', [])['phone'] ?? '')));
    }
    $m = fn($k) => (string) get_user_meta($uid, $k, true);
    $data = [
        'user_id' => $uid,
        'email' => $u->user_email, // a párosítás a fiók e-mail-címével
        'billing_email' => $m('billing_email'),
        'name' => trim(($m('billing_last_name') ?: $u->last_name) . ' ' . ($m('billing_first_name') ?: $u->first_name)) ?: $u->display_name,
        'company' => $m('billing_company'),
        'subject' => $subject !== '' ? $subject : __('Üzenet a viszonteladói fiókból', 'mandala'),
        'body' => $body,
        'at' => mandala_crm_time(),
    ];
    array_unshift($log, ['at' => time(), 'subject' => $data['subject']]);
    update_user_meta($uid, '_mandala_crm_messages', array_slice($log, 0, 10));
    if (mandala_crm_config()) {
        mandala_crm_enqueue('message', $data);
        mandala_crm_soon();
    } else {
        $to = (string) (mandala_config('contact', [])['email'] ?? get_option('admin_email'));
        wp_mail($to, '[' . get_bloginfo('name') . '] ' . sprintf('Üzenet viszonteladótól: %s', $data['subject']),
            ($data['company'] ? $data['company'] . ' – ' : '') . $data['name'] . ' <' . $data['email'] . ">\n\n" . $body,
            ['Reply-To: ' . $data['name'] . ' <' . $data['email'] . '>']);
    }
    do_action('mandala_crm_message', $data);
    return true;
}

add_action('template_redirect', function () {
    if (!isset($_POST['_crmmsg']) || !is_user_logged_in()) {
        return;
    }
    if (!wp_verify_nonce(sanitize_key($_POST['_crmmsg']), 'mandala_crm_message') || !mandala_is_wholesale_user()) {
        wc_add_notice(__('Az oldal közben lejárt – kérlek, küldd el újra az üzenetet.', 'mandala'), 'error');
        return;
    }
    $r = mandala_crm_send_message(get_current_user_id(), (string) wp_unslash($_POST['subject'] ?? ''), (string) wp_unslash($_POST['body'] ?? ''), !empty($_POST['website']));
    if (is_wp_error($r)) {
        wc_add_notice($r->get_error_message(), 'error'); // az oldal a beírt szöveggel jelenik meg újra
        return;
    }
    $u = wp_get_current_user();
    wc_add_notice(sprintf(__('Köszönjük, megkaptuk az üzenetedet! Hamarosan válaszolunk e-mailben (%s).', 'mandala'), (string) get_user_meta($u->ID, 'billing_email', true) ?: $u->user_email));
    wp_safe_redirect(wc_get_account_endpoint_url(MANDALA_MSG_ENDPOINT));
    exit;
});

add_action('woocommerce_account_' . MANDALA_MSG_ENDPOINT . '_endpoint', function () {
    if (!mandala_is_wholesale_user()) {
        echo '<p>' . esc_html__('Ez a felület a jóváhagyott viszonteladó partnereinknek szól.', 'mandala') . ' <a href="' . esc_url(mandala_url(['page' => 'viszonteladoknak'])) . '">' . esc_html__('Jelentkezés viszonteladónak', 'mandala') . '</a></p>';
        return;
    }
    $uid = get_current_user_id();
    $sent = mandala_crm_messages($uid);
    $failed = isset($_POST['_crmmsg']); // phpcs:ignore WordPress.Security.NonceVerification -- csak a beírt szöveg visszaírása
    $val = fn($k) => $failed ? (string) wp_unslash($_POST[$k] ?? '') : ''; // phpcs:ignore WordPress.Security.NonceVerification
    ?>
<section class="crm-message">
  <p><?php esc_html_e('Kérdésed van egy rendelésről, termékről vagy a feltételekről? Írd meg itt – a partnerkapcsolatért felelős kollégánk e-mailben válaszol, általában egy munkanapon belül.', 'mandala'); ?></p>
  <form method="post" class="crm-message-form panel">
    <?php wp_nonce_field('mandala_crm_message', '_crmmsg'); ?>
    <p class="sr-only" aria-hidden="true"><label><?php esc_html_e('Weboldal', 'mandala'); ?> <input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>
    <div class="iu-form-field"><label for="crm-msg-subject"><?php esc_html_e('Tárgy', 'mandala'); ?></label><input type="text" id="crm-msg-subject" class="input-text" name="subject" maxlength="150" value="<?php echo esc_attr($val('subject')); ?>" placeholder="<?php esc_attr_e('pl. utánrendelés, számla, szállítás', 'mandala'); ?>"></div>
    <div class="iu-form-field"><label for="crm-msg-body"><?php esc_html_e('Üzenet', 'mandala'); ?><abbr class="required" title="<?php esc_attr_e('kötelező', 'mandala'); ?>">*</abbr></label><textarea id="crm-msg-body" class="input-text" name="body" rows="6" maxlength="5000" required><?php echo esc_textarea($val('body')); ?></textarea></div>
    <div><button type="submit" class="iu-button"><?php echo mandala_icon('mail', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Üzenet küldése', 'mandala'); ?></button></div>
  </form>
    <?php if ($sent) : ?>
  <div class="crm-message-sent"><h2><?php esc_html_e('Legutóbbi üzeneteid', 'mandala'); ?></h2><ul>
        <?php foreach (array_slice($sent, 0, 5) as $s) : ?>
    <li><span class="text-muted"><?php echo esc_html(wp_date('Y. m. d. H:i', (int) ($s['at'] ?? 0))); ?></span> <?php echo esc_html((string) ($s['subject'] ?? '')); ?></li>
        <?php endforeach; ?>
  </ul></div>
    <?php endif; ?>
</section>
    <?php
});

/* ---------- Egy kattintásos rendelés ajánlatból (?mandala_offer=…) ---------- */

/** Az ajánlat-link aláírása: hex HMAC-SHA256 a JSON-szövegen, az összekötő titkával. */
function mandala_offer_sign(string $secret, string $json): string
{
    return hash_hmac('sha256', $json, $secret);
}

/** Az ajánlat-link paramétere: base64url(json) . '.' . aláírás (a CRM oldala ugyanígy készíti). */
function mandala_offer_param(string $secret, string $json): string
{
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '.' . mandala_offer_sign($secret, $json);
}

/**
 * Az ajánlat-link ellenőrzése (aláírás a base64url-dekódolt JSON bájtsorán, majd lejárat – x: az érvényesség utolsó
 * napja 23:59:59 helyi idő). A nem kötelező p: [[cikkszám, kedvezményes nettó egységár], …]. Siker: ['ok' => true, 't',
 * 'e', 'i' => [[sku, db, nettó ajánlati ár|null]], 'x']; hiba: ['ok' => false, 'error' => 'hibas' | 'lejart', 'x'].
 */
function mandala_offer_parse(string $param, string $secret, int $now): array
{
    if (strlen($secret) < 24 || !preg_match('/^([A-Za-z0-9_-]{8,12000})\.([A-Fa-f0-9]{64})$/', trim($param), $m)) {
        return ['ok' => false, 'error' => 'hibas', 'x' => 0];
    }
    $b64 = strtr($m[1], '-_', '+/');
    $json = base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4), true);
    if (!is_string($json) || !hash_equals(mandala_offer_sign($secret, $json), strtolower($m[2]))) {
        return ['ok' => false, 'error' => 'hibas', 'x' => 0];
    }
    $d = json_decode($json, true);
    $token = is_array($d) ? substr((string) preg_replace('/[^A-Za-z0-9_.:-]/', '', (string) ($d['t'] ?? '')), 0, 64) : '';
    if ($token === '' || !is_email((string) ($d['e'] ?? '')) || !is_array($d['i'] ?? null) || !is_numeric($d['x'] ?? null)) {
        return ['ok' => false, 'error' => 'hibas', 'x' => 0];
    }
    $prices = [];
    foreach (is_array($d['p'] ?? null) ? array_slice($d['p'], 0, 200) : [] as $row) {
        if (is_array($row) && trim((string) ($row[0] ?? '')) !== '' && is_numeric($row[1] ?? null) && (float) $row[1] > 0) {
            $prices[trim((string) $row[0])] = (float) $row[1];
        }
    }
    $items = [];
    foreach (array_slice($d['i'], 0, 200) as $row) {
        $sku = is_array($row) ? trim((string) ($row[0] ?? '')) : '';
        $qty = is_array($row) ? (int) ($row[1] ?? 0) : 0;
        if ($sku !== '' && $qty > 0) {
            $items[] = [$sku, min($qty, 9999), $prices[$sku] ?? null];
        }
    }
    if (!$items) {
        return ['ok' => false, 'error' => 'hibas', 'x' => 0];
    }
    if ((int) $d['x'] < $now) {
        return ['ok' => false, 'error' => 'lejart', 'x' => (int) $d['x']];
    }
    return ['ok' => true, 't' => $token, 'e' => strtolower(trim((string) $d['e'])), 'i' => $items, 'x' => (int) $d['x']];
}

/** „a***@pelda.hu” – a másik fiók címe ne látsszon egészében. */
function mandala_offer_mask(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($name, 0, 1) . '***@' . $domain;
}

/**
 * Az ajánlat tételei a belépett partner kosarába. Visszaad: ['status' => 'added' | 'in_cart' | 'ordered' | 'email' |
 * 'not_wholesale' | 'none', 'added' => db, 'partial' => [név → db], 'missing' => [cikkszám / név], 'order' => szám].
 */
function mandala_offer_apply(array $offer, WP_User $user): array
{
    $res = ['status' => 'none', 'added' => 0, 'partial' => [], 'missing' => [], 'order' => ''];
    // Csak a fiók e-mail-címe (user_email) – a számlázási e-mailt a vásárló maga írja át, az nem köt.
    if ($offer['e'] !== strtolower(trim((string) $user->user_email))) {
        return ['status' => 'email'] + $res;
    }
    if (!mandala_is_wholesale_user((int) $user->ID)) {
        return ['status' => 'not_wholesale'] + $res;
    }
    $cart = WC()->cart;
    foreach ($cart->get_cart() as $item) {
        if (($item['mandala_offer'] ?? '') === $offer['t']) {
            return ['status' => 'in_cart'] + $res;
        }
    }
    // Újra csak akkor, ha belőle nincs sikeres rendelés (a lemondott / sikertelen nem számít).
    $done = mandala_offer_used($offer['t'], (int) $user->ID);
    if ($done) {
        return ['status' => 'ordered', 'order' => (string) $done->get_order_number()] + $res;
    }
    foreach ($offer['i'] as [$sku, $qty, $price]) {
        $offered = $qty; // az ajánlott darabszám: eddig jár az ajánlati ár (a készlet miatt kevesebb is mehet a kosárba)
        $pid = wc_get_product_id_by_sku($sku);
        $product = $pid ? wc_get_product($pid) : null;
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
            $res['missing'][] = $product ? $product->get_name() : $sku;
            continue;
        }
        if ($product->managing_stock() && !$product->backorders_allowed()) {
            $in_cart = $cart->get_cart_item_quantities()[$product->get_stock_managed_by_id()] ?? 0;
            $avail = (int) $product->get_stock_quantity() - (int) $in_cart;
            if ($avail <= 0) {
                $res['missing'][] = $product->get_name();
                continue;
            }
            if ($qty > $avail) {
                $res['partial'][$product->get_name()] = $avail;
                $qty = $avail;
            }
        }
        $data = ['mandala_offer' => $offer['t'], 'mandala_offer_price' => $price, 'mandala_offer_qty' => $offered, 'mandala_offer_x' => (int) $offer['x']];
        if ($cart->add_to_cart($product->get_id(), $qty, 0, [], $data)) {
            $res['added'] += $qty;
        } else {
            $res['missing'][] = $product->get_name();
        }
    }
    $res['status'] = $res['added'] ? 'added' : 'none';
    return $res;
}

/** offer_open esemény a CRM-nek: tokenenként egyszer (az első érvényes megnyitáskor, belépve vagy belépés előtt). */
function mandala_offer_opened(array $offer, int $uid): void
{
    $seen = (array) get_option('mandala_crm_offer_seen', []);
    if (isset($seen[$offer['t']]) || !mandala_crm_config()) {
        return;
    }
    $seen[$offer['t']] = time();
    update_option('mandala_crm_offer_seen', array_slice($seen, -500, null, true), false);
    $u = $uid ? get_userdata($uid) : null;
    mandala_crm_enqueue('offer_open', ['token' => $offer['t'], 'at' => mandala_crm_time(), 'user_id' => $uid, 'email' => $u ? $u->user_email : $offer['e']]);
}

// offer_order: az ajánlatból lett rendelés (az order esemény offer_token mezője mellett, a CRM ajánlat-moduljának).
add_action('woocommerce_checkout_order_processed', function ($order_id) {
    $order = wc_get_order($order_id);
    $token = $order ? (string) $order->get_meta('_mandala_offer_token') : '';
    if ($token !== '' && !$order->get_meta('_mandala_offer_sent')) {
        $d = mandala_crm_order_data($order);
        mandala_crm_enqueue('offer_order', ['token' => $token, 'order_id' => $d['id'], 'number' => $d['number'], 'user_id' => $d['user_id'], 'email' => $d['email'], 'status' => $d['status'], 'net' => $d['net'], 'gross' => $d['gross'], 'at' => mandala_crm_time()]);
        $order->update_meta_data('_mandala_offer_sent', time());
        $order->save_meta_data();
        mandala_crm_soon();
    }
}, 31);

/** Vendégnek is megmaradjon az üzenet az átirányítás után (WooCommerce munkamenet-süti). */
function mandala_offer_notice(string $text, string $type = 'notice'): void
{
    if (WC()->session && !WC()->session->has_session()) {
        WC()->session->set_customer_session_cookie(true);
    }
    wc_add_notice($text, $type);
}

function mandala_offer_cookie(string $value, int $expires): void
{
    if (!headers_sent()) {
        setcookie(MANDALA_OFFER_COOKIE, $value, ['expires' => $expires, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    }
    $_COOKIE[MANDALA_OFFER_COOKIE] = $value;
}

add_action('template_redirect', function () {
    $param = sanitize_text_field(wp_unslash($_GET['mandala_offer'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification -- aláírt link
    if ($param === '' && is_user_logged_in() && !empty($_COOKIE[MANDALA_OFFER_COOKIE])) {
        $param = sanitize_text_field(wp_unslash($_COOKIE[MANDALA_OFFER_COOKIE])); // belépés után folytatjuk
    }
    if ($param === '' || !function_exists('WC') || !WC()->cart) {
        return;
    }
    nocache_headers();
    $cfg = mandala_crm_config();
    $offer = $cfg ? mandala_offer_parse($param, $cfg[1], time()) : ['ok' => false, 'error' => 'hibas', 'x' => 0];
    $cart_url = wc_get_cart_url();
    $phone = (string) (mandala_config('contact', [])['phone'] ?? '');
    if (!$offer['ok']) {
        mandala_offer_cookie('', time() - HOUR_IN_SECONDS);
        mandala_offer_notice($offer['error'] === 'lejart'
            ? sprintf(__('Ez az ajánlat %s-ig volt érvényes. Írj nekünk vagy hívj (%s), és szívesen küldünk frisset.', 'mandala'), wp_date('Y. m. d', $offer['x']), $phone)
            : sprintf(__('Ezt az ajánlat-linket nem tudtuk megnyitni – lehet, hogy nem teljes. Másold be újra a levélből, vagy hívj minket: %s.', 'mandala'), $phone), 'error');
        wp_safe_redirect($cart_url);
        exit;
    }
    mandala_offer_opened($offer, get_current_user_id());
    if (!is_user_logged_in()) {
        mandala_offer_cookie($param, min($offer['x'], time() + DAY_IN_SECONDS));
        wp_safe_redirect(add_query_arg('ajanlat', '1', wc_get_page_permalink('myaccount')));
        exit;
    }
    mandala_offer_cookie('', time() - HOUR_IN_SECONDS);
    $user = wp_get_current_user();
    $notices = wc_get_notices();
    $r = mandala_offer_apply($offer, $user);
    wc_set_notices($notices); // a WooCommerce saját „kosárba tettük” üzenetei helyett egy összefoglaló
    switch ($r['status']) {
        case 'email':
            mandala_offer_notice(sprintf(__('Ez az ajánlat a(z) %s fiókhoz szól. Lépj ki, lépj be azzal a fiókkal, és kattints újra a levélben kapott linkre – vagy írj nekünk, és segítünk.', 'mandala'), mandala_offer_mask($offer['e'])), 'error');
            break;
        case 'not_wholesale':
            mandala_offer_notice(sprintf(__('Ez az ajánlat viszonteladói fiókhoz szól, a tiéd még nem az. Hívj minket (%s), és elintézzük.', 'mandala'), $phone), 'error');
            break;
        case 'in_cart':
            mandala_offer_notice(__('Az ajánlat tételei már a kosaradban vannak.', 'mandala'));
            break;
        case 'ordered':
            mandala_offer_notice(sprintf(__('Ezt az ajánlatot már megrendelted (#%s) – köszönjük! Ha újra kérnéd, írj nekünk.', 'mandala'), $r['order']));
            break;
        default:
            if ($r['added']) {
                mandala_offer_notice(sprintf(_n('Az ajánlatból %d db termék a kosaradban van, az ajánlat áraival.', 'Az ajánlatból %d db termék a kosaradban van, az ajánlat áraival.', $r['added'], 'mandala'), $r['added']), 'success');
            }
            foreach ($r['partial'] as $name => $n) {
                mandala_offer_notice(sprintf(__('%1$s: most csak %2$d db van raktáron, ennyit tettünk a kosárba.', 'mandala'), $name, $n));
            }
            if ($r['missing']) {
                mandala_offer_notice(sprintf(__('Ezek most nem rendelhetők (elfogytak vagy kivezettük őket): %s. Kérdezz rá, és ajánlunk helyettük mást.', 'mandala'), implode(', ', $r['missing'])), $r['added'] ? 'notice' : 'error');
            }
    }
    wp_safe_redirect($cart_url);
    exit;
}, 4);

// A belépő oldalon: miért kell belépni.
add_action('woocommerce_before_customer_login_form', function () {
    if (!empty($_COOKIE[MANDALA_OFFER_COOKIE]) || isset($_GET['ajanlat'])) { // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="woocommerce-info" role="status">' . mandala_icon('bag') . '<span>' . esc_html__('Lépj be a viszonteladói fiókodba, és az ajánlat tételeit azonnal a kosaradba tesszük, az ajánlat áraival.', 'mandala') . '</span></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
});

/* ---------- Admin: a felhasználó adatlapján ---------- */

foreach (['show_user_profile', 'edit_user_profile'] as $mandala_hook) {
    add_action($mandala_hook, function ($user) {
        if (!current_user_can('manage_woocommerce') || !$user instanceof WP_User) {
            return;
        }
        $t = mandala_crm_terms_of((int) $user->ID);
        $consent = (string) get_user_meta($user->ID, '_mandala_partner_mail', true);
        echo '<h2>Partner CRM</h2><table class="form-table" role="presentation"><tbody>'
            . '<tr><th>Kedvezményszint</th><td>' . esc_html($t['discount_level'] ?: '–') . ($t['discount_pct'] > 0 ? ' (−' . esc_html(mandala_crm_pct_label($t['discount_pct'])) . '%)' : '') . '</td></tr>'
            . '<tr><th>Fizetési határidő</th><td>' . ($t['payment_days'] ? (int) $t['payment_days'] . ' nap (átutalás számla alapján)' : 'nincs – előre fizet') . '</td></tr>'
            . '<tr><th>Rendelés-tiltás</th><td>' . ($t['blocked'] ? '<strong style="color:#b3261e">csak előre fizetéssel rendelhet</strong>' . ($t['block_reason'] ? ' – ' . esc_html($t['block_reason']) : '') : 'nincs') . '</td></tr>'
            . '<tr><th>Partnerlevelek</th><td>' . ($consent === 'yes' ? 'kér' : ($consent === 'no' ? 'nem kér' : '–')) . ($consent ? ' (' . esc_html((string) get_user_meta($user->ID, '_mandala_partner_mail_at', true)) . ')' : '') . '</td></tr>'
            . '</tbody></table><p class="description">A feltételeket a Partner CRM állítja (a partner kartonján).</p>';
    });
}
unset($mandala_hook);
