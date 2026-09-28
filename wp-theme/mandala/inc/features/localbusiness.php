<?php
/**
 * Google Cégprofil / helyi keresés: a budapesti bemutatóterem adatai strukturáltan (schema.org Store)
 * a főoldalon és a kapcsolat oldalon – így a Google a cím, a nyitvatartás és a telefonszám mellé a boltot
 * is össze tudja kötni a Cégprofillal („hangtál bolt Budapest” jellegű keresések, térkép).
 * Az eseményeknél (hangfürdő, workshop) az Event adat már az esemény oldalán van (events.php).
 *
 * Beállítás: WooCommerce → Mandala bolt adatai → Bemutatóterem (Google).
 */

defined('ABSPATH') || exit;

function mandala_localbiz(): array
{
    return wp_parse_args((array) get_option('mandala_localbiz', []), [
        'street' => '', 'zip' => '', 'city' => 'Budapest', 'lat' => '', 'lng' => '', 'maps' => '', 'gbp' => '',
        'wk_open' => '10:00', 'wk_close' => '18:00', 'sa_open' => '10:00', 'sa_close' => '14:00', 'su_open' => '', 'su_close' => '',
    ]);
}

/** A schema.org Store adat (üres tömb, ha nincs megadva utca). */
function mandala_localbiz_schema(): array
{
    $b = mandala_localbiz();
    if ($b['street'] === '') {
        return [];
    }
    $c = (array) mandala_config('contact', []);
    $hours = [];
    foreach ([['wk', ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday']], ['sa', ['Saturday']], ['su', ['Sunday']]] as [$k, $days]) {
        if ($b[$k . '_open'] !== '' && $b[$k . '_close'] !== '') {
            $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $b[$k . '_open'], 'closes' => $b[$k . '_close']];
        }
    }
    $logo = file_exists(MANDALA_DIR . '/assets/logo/mandala-jel.svg') ? MANDALA_URL . '/assets/logo/mandala-jel.svg' : '';
    return array_filter([
        '@context' => 'https://schema.org', '@type' => 'Store', '@id' => home_url('/#store'), 'name' => get_bloginfo('name'), 'url' => home_url('/'),
        'image' => $logo, 'logo' => $logo, 'telephone' => $c['phone'] ?? '', 'email' => $c['email'] ?? '', 'priceRange' => '1 000 Ft – 150 000 Ft',
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $b['street'], 'postalCode' => $b['zip'], 'addressLocality' => $b['city'], 'addressCountry' => 'HU'],
        'geo' => $b['lat'] !== '' && $b['lng'] !== '' ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $b['lat'], 'longitude' => (float) $b['lng']] : null,
        'hasMap' => $b['maps'], 'openingHoursSpecification' => $hours,
        'sameAs' => array_values(array_filter([$c['facebook'] ?? '', ($c['instagram'] ?? '') !== '#' ? ($c['instagram'] ?? '') : '', $b['gbp']])),
    ]);
}
add_action('wp_head', function () {
    $contact_page = (int) get_option('mandala_page_kapcsolat');
    if (!(is_front_page() || ($contact_page && is_page($contact_page)))) {
        return;
    }
    $data = mandala_localbiz_schema();
    if ($data) {
        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>' . "\n";
    }
}, 30);

add_action('mandala_store_admin_after', function () {
    $b = mandala_localbiz();
    if (isset($_POST['mandala_localbiz']) && check_admin_referer('mandala_localbiz')) {
        $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_localbiz']));
        foreach (array_keys($b) as $k) {
            $v = trim((string) ($in[$k] ?? ''));
            if (str_ends_with($k, '_open') || str_ends_with($k, '_close')) {
                $v = preg_match('/^\\d{1,2}:\\d{2}$/', $v) ? str_pad($v, 5, '0', STR_PAD_LEFT) : '';
            } elseif (in_array($k, ['maps', 'gbp'], true)) {
                $v = esc_url_raw($v);
            } elseif (in_array($k, ['lat', 'lng'], true)) {
                $v = is_numeric(str_replace(',', '.', $v)) ? str_replace(',', '.', $v) : '';
            }
            $b[$k] = $v;
        }
        update_option('mandala_localbiz', $b, false);
        echo '<div class="notice notice-success"><p>Bemutatóterem adatai mentve.</p></div>';
    }
    $f = fn($k, $w = 'regular-text', $ph = '') => '<input type="text" name="mandala_localbiz[' . $k . ']" value="' . esc_attr($b[$k]) . '" class="' . $w . '" placeholder="' . esc_attr($ph) . '">';
    $t = fn($k) => '<input type="text" name="mandala_localbiz[' . $k . ']" value="' . esc_attr($b[$k]) . '" style="width:70px" placeholder="óó:pp">';
    echo '<h2 id="bemutatoterem">Bemutatóterem (Google)</h2><p>A Google a keresésben és a térképen ebből köti össze a boltot a Cégprofillal. Az utca kitöltésével kapcsol be.</p><form method="post">';
    wp_nonce_field('mandala_localbiz');
    echo '<table class="form-table"><tr><th>Cím</th><td>' . $f('zip', '', 'irányítószám') . ' ' . $f('city', '', 'város') . '<br>' . $f('street', 'large-text', 'utca, házszám') . '</td></tr>'
        . '<tr><th>Nyitvatartás</th><td>H–P ' . $t('wk_open') . '–' . $t('wk_close') . ' &nbsp; Szo ' . $t('sa_open') . '–' . $t('sa_close') . ' &nbsp; V ' . $t('su_open') . '–' . $t('su_close') . '<p class="description">Üresen = zárva.</p></td></tr>'
        . '<tr><th>Koordináták</th><td>' . $f('lat', '', 'szélesség, pl. 47.4979') . ' ' . $f('lng', '', 'hosszúság, pl. 19.0402') . '<p class="description">Google Térkép → jobb klikk a helyre → a számokra kattintva másolható.</p></td></tr>'
        . '<tr><th>Térkép link</th><td>' . $f('maps', 'large-text', 'https://maps.google.com/…') . '</td></tr>'
        . '<tr><th>Google Cégprofil link</th><td>' . $f('gbp', 'large-text', 'https://g.page/…') . '<p class="description">A Cégprofil „Megosztás” linkje.</p></td></tr></table>';
    submit_button('Bemutatóterem mentése');
    echo '</form>';
});
