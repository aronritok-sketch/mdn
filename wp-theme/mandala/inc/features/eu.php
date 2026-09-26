<?php
/**
 * Szállítás az Európai Unióba.
 *
 * Bekapcsolás:  wp mandala eu-shipping            (az EU-országok engedélyezése + „Európai Unió” zóna)
 * Kikapcsolás:  wp mandala eu-shipping --off      (vissza csak Magyarországra)
 * A zóna szállítási módjait és díjait a GLS bővítményben állítjátok be (mint belföldön).
 * ÁFA: az EU-s magánszemélyeknek történő távértékesítés 10 000 EUR éves értékhatár felett a
 * vevő országának ÁFÁ-jával megy (OSS) – a kulcsokat a WooCommerce → Adó alatt a könyvelővel
 * egyeztetve kell felvenni; a téma ezt nem találja ki.
 *
 * A pénztár országfüggően ellenőriz: a magyar irányítószám, telefonszám és adószám (CDV)
 * szabályai csak magyar címnél élnek; külföldi cégnél közösségi adószámot kérünk.
 */

defined('ABSPATH') || exit;

const MANDALA_EU = ['AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK'];

/** Szállítunk-e külföldre (a WooCommerce engedélyezett országai alapján). */
function mandala_ships_abroad(): bool
{
    return function_exists('WC') && WC()->countries && count(WC()->countries->get_shipping_countries()) > 1;
}

/** Közösségi (EU) adószám formátum: országkód + 2–12 karakter (Görögország: EL). */
function mandala_valid_vat_id(string $value, string $country = ''): bool
{
    $v = strtoupper(preg_replace('/[\s.\-]/', '', $value));
    if (!preg_match('/^(AT|BE|BG|CY|CZ|DE|DK|EE|EL|ES|FI|FR|HR|IE|IT|LT|LU|LV|MT|NL|PL|PT|RO|SE|SI|SK|XI)[0-9A-Z+*]{2,12}$/', $v, $m)) {
        return false;
    }
    return !$country || $m[1] === ($country === 'GR' ? 'EL' : $country);
}

/** Nemzetközi telefonszám: + országhívó és legalább 7 számjegy. */
function mandala_valid_intl_phone(string $value): bool
{
    $digits = preg_replace('/\D/', '', $value);
    return (bool) preg_match('/^\+[1-9]/', trim($value)) && strlen($digits) >= 7 && strlen($digits) <= 15;
}

add_filter('woocommerce_billing_fields', function ($fields) {
    if (isset($fields['billing_country']) && mandala_ships_abroad()) {
        $fields['billing_country']['description'] = '';
    }
    return $fields;
}, 25);

/* A pénztár ellenőrzése (shop.php) a magyar szabályokat csak HU címre alkalmazza; itt a külföldiek. */
add_filter('woocommerce_checkout_posted_data', function ($data) {
    if (($data['billing_country'] ?? 'HU') !== 'HU' && !empty($data['billing_tax_number'])) {
        $data['billing_tax_number'] = strtoupper(preg_replace('/[\s.\-]/', '', $data['billing_tax_number']));
    }
    return $data;
}, 20);

add_action('woocommerce_after_checkout_validation', function ($data, WP_Error $errors) {
    $country = $data['billing_country'] ?? 'HU';
    if ($country === 'HU') {
        return;
    }
    if (!empty($data['billing_phone']) && !mandala_valid_intl_phone($data['billing_phone'])) {
        $errors->add('billing_phone_validation', __('<strong>Telefonszám</strong>: nemzetközi formátumban add meg, pl. +43 660 1234567.', 'mandala'), ['id' => 'billing_phone']);
    }
    if (!empty($_POST['is_company']) && !empty($data['billing_tax_number']) && !mandala_valid_vat_id($data['billing_tax_number'], $country)) { // phpcs:ignore
        $errors->add('billing_tax_number_validation', __('<strong>Adószám</strong>: a cég közösségi adószámát add meg országkóddal, pl. ATU12345678.', 'mandala'), ['id' => 'billing_tax_number']);
    }
}, 20, 2);

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Szállítás az EU-ba: engedélyezett országok + „Európai Unió” szállítási zóna (a módokat a GLS bővítmény adja).
     *
     * ## OPTIONS
     * [--off] : vissza csak Magyarországra (a zóna megmarad, de nem használt)
     */
    WP_CLI::add_command('mandala eu-shipping', function ($args, $assoc) {
        $countries = empty($assoc['off']) ? array_merge(['HU'], MANDALA_EU) : ['HU'];
        update_option('woocommerce_allowed_countries', 'specific');
        update_option('woocommerce_specific_allowed_countries', $countries);
        update_option('woocommerce_ship_to_countries', 'specific');
        update_option('woocommerce_specific_ship_to_countries', $countries);
        if (empty($assoc['off'])) {
            $zone_id = 0;
            foreach (WC_Shipping_Zones::get_zones() as $z) {
                if ($z['zone_name'] === 'Európai Unió') {
                    $zone_id = (int) $z['id'];
                }
            }
            $zone = new WC_Shipping_Zone($zone_id ?: null);
            $zone->set_zone_name('Európai Unió');
            $zone->set_zone_order(1);
            $zone->set_locations(array_map(fn($c) => ['code' => $c, 'type' => 'country'], MANDALA_EU));
            $zone->save();
            WP_CLI::log('„Európai Unió” zóna: ' . count(MANDALA_EU) . ' ország. A szállítási módokat a GLS bővítményben add hozzá (WooCommerce → Beállítások → Szállítás).');
            WP_CLI::warning('ÁFA: OSS értékhatár felett a vevő országának kulcsai kellenek (WooCommerce → Beállítások → Adó) – egyeztessétek a könyvelővel.');
        }
        mandala_flush_index();
        WP_CLI::success(empty($assoc['off']) ? 'EU-s szállítás bekapcsolva.' : 'Csak belföld.');
    });
}
