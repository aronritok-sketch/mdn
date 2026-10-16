<?php
/**
 * iu/form űrlapok feldolgozása (hirlevel, kapcsolat, termekkerdes, viszontelado).
 *
 * Keretrendszer-hibák kerülése (lásd iu-theme skill):
 *  - az iu/form e-mailje csak a „message” attribútum fix szövegét küldi, és a From fejléc
 *    sem áll be → az űrlapok „email” attribútuma üres, a levelet itt küldjük a kitöltött
 *    mezőkkel, válaszcímnek a kitöltő e-mailjével;
 *  - a kötelező iu/form-accept jelölőnégyzetet a szerver nem ellenőrzi → itt ellenőrizzük;
 *  - hiba esetén a válasz ['errors' => [...], 'error' => '…'] (az errors kulcs kötelező).
 */

defined('ABSPATH') || exit;

const MANDALA_FORMS = [
    'hirlevel' => ['subject' => 'Hírlevél feliratkozás', 'mail' => false],
    'kapcsolat' => ['subject' => 'Üzenet a weboldalról', 'mail' => true],
    'termekkerdes' => ['subject' => 'Kérdés egy termékről', 'mail' => true],
    'viszontelado' => ['subject' => 'Viszonteladói jelentkezés', 'mail' => true],
    'tanacsadas' => ['subject' => 'Tanácsadás / időpontkérés', 'mail' => true],
];

/** Az iu/form belső blokkjaiból: mezőnév → [címke, blokktípus]. */
function mandala_form_fields($form): array
{
    $fields = [];
    $walk = function ($blocks) use (&$walk, &$fields) {
        foreach ((array) $blocks as $block) {
            $name = $block['attrs']['name'] ?? '';
            if ($name && str_starts_with((string) ($block['blockName'] ?? ''), 'iu/form-')) {
                $fields[$name] = [wp_strip_all_tags($block['attrs']['label'] ?? $name), $block['blockName']];
            }
            if (!empty($block['innerBlocks'])) {
                $walk($block['innerBlocks']);
            }
        }
    };
    $walk(is_array($form) ? ($form['innerBlocks'] ?? []) : []);
    return $fields;
}

/**
 * Közös feldolgozó. A szűrő pontos paraméterlistája keretrendszer-verziónként eltérhet,
 * ezért az első paraméter a válasz, a második (ha van) az űrlap blokk.
 */
function mandala_handle_form(string $id, $response, $form = null)
{
    $config = MANDALA_FORMS[$id];
    $fields = mandala_form_fields($form);
    $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- az iu_theme űrlapkezelője fogadja
    $errors = [];

    // Adatkezelési hozzájárulás (iu/form-accept): a keretrendszer nem ellenőrzi.
    $accepts = array_keys(array_filter($fields, fn($f) => $f[1] === 'iu/form-accept')) ?: ['adatkezeles'];
    foreach ($accepts as $name) {
        if (empty($post[$name])) {
            $errors[$name] = __('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'mandala');
        }
    }
    $email = sanitize_email($post['email'] ?? '');
    if (!is_email($email)) {
        $errors['email'] = __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala');
    }
    if ($id === 'viszontelado' && !empty($post['adoszam']) && function_exists('mandala_valid_tax_number') && !mandala_valid_tax_number((string) $post['adoszam'])) {
        $errors['adoszam'] = __('Ez nem érvényes magyar adószám (8-1-2 számjegy).', 'mandala');
    }
    // Egyszerű spam-csapda: a rejtett „website” mezőt ember nem tölti ki.
    if (!empty($post['website'])) {
        return ['success' => 1];
    }
    if ($errors) {
        return ['errors' => $errors, 'error' => count($errors) === 1 ? __('Egy mezőt javítani kell.', 'mandala') : sprintf(__('%d mezőt javítani kell.', 'mandala'), count($errors))];
    }

    // Beküldés naplózása (adminban: Eszközök → Mandala űrlapok), hogy levélhiba esetén se vesszen el.
    $entry = ['form' => $id, 'date' => current_time('mysql'), 'email' => $email, 'fields' => []];
    foreach ($post as $key => $value) {
        if (in_array($key, ['iu_form_id', 'action', 'website'], true) || str_starts_with((string) $key, '_')) {
            continue;
        }
        $label = $fields[$key][0] ?? ucfirst(str_replace(['_', '-'], ' ', (string) $key));
        $entry['fields'][$label] = is_array($value) ? implode(', ', array_map('sanitize_text_field', $value)) : sanitize_textarea_field((string) $value);
    }
    $log = get_option('mandala_form_entries', []);
    array_unshift($log, $entry);
    update_option('mandala_form_entries', array_slice($log, 0, 500), false);

    if ($id === 'hirlevel') {
        // Egylépcsős feliratkozás (nincs megerősítő levél): a hozzájárulás bizonyítéka a naplóba – az űrlap pontos
        // szövegével (a jelölőnégyzet és a gomb felirata, ahogy a látogató látta), időponttal, IP-címmel (consent.php).
        $labels = array_values(array_map(fn($f) => $f[0], array_filter($fields, fn($f) => $f[1] === 'iu/form-accept')));
        $button = is_array($form) ? wp_strip_all_tags((string) ($form['attrs']['buttonLabel'] ?? '')) : '';
        $wording = mandala_consent_wording(__('Hírlevél-űrlap', 'mandala'), implode(' | ', $labels), $button);
        mandala_newsletter_subscribe($email, 'weboldal', $wording, ['name' => sanitize_text_field($post['name'] ?? $post['nev'] ?? '')]);
    }

    if ($config['mail']) {
        $to = apply_filters('mandala_form_recipient', mandala_config('contact')['email'] ?? get_option('admin_email'), $id);
        $body = '';
        foreach ($entry['fields'] as $label => $value) {
            $body .= $label . ': ' . $value . "\n";
        }
        $body .= "\n—\n" . home_url(wp_get_referer() ? wp_parse_url(wp_get_referer(), PHP_URL_PATH) : '/');
        $headers = ['Reply-To: ' . sanitize_text_field($post['name'] ?? $post['nev'] ?? '') . ' <' . $email . '>'];
        if (!wp_mail($to, '[' . get_bloginfo('name') . '] ' . $config['subject'], $body, $headers)) {
            return ['errors' => [], 'error' => __('Az üzenetet most nem sikerült elküldeni. Kérjük, próbáld újra, vagy írj e-mailt.', 'mandala')];
        }
    }
    return is_array($response) ? $response + ['success' => 1] : ['success' => 1];
}

foreach (array_keys(MANDALA_FORMS) as $form_id) {
    add_filter('iu_form_submit_' . $form_id, fn($response, $form = null) => mandala_handle_form($form_id, $response, $form), 10, 2);
}

/** Admin: beérkezett űrlapok és hírlevél-feliratkozók (CSV export). */
add_action('admin_menu', function () {
    add_management_page('Mandala űrlapok', 'Mandala űrlapok', 'manage_options', 'mandala-forms', function () {
        $entries = get_option('mandala_form_entries', []);
        $subs = get_option('mandala_newsletter', []);
        echo '<div class="wrap"><h1>Mandala űrlapok</h1><p>Az utolsó 500 beküldés. A levelek a beállított címre is mennek; ez a napló tartalék.</p>';
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_newsletter_csv'), 'mandala_csv')) . '">Hírlevél-feliratkozók letöltése (' . count($subs) . ', CSV)</a> ' . mandala_consent_csv_links() . '</p>'
            . '<p class="description">A hozzájárulások bizonyítéka (Grt. 6. § (5)): időpont, forrás, a jelölőnégyzet pontos szövege és változata, IP-cím, név (ha ismert). A visszavont hozzájárulás adatait 5 évig őrizzük, utána törlődnek.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Dátum</th><th>Űrlap</th><th>E-mail</th><th>Adatok</th></tr></thead><tbody>';
        foreach (array_slice($entries, 0, 100) as $e) {
            $data = implode('<br>', array_map(fn($k, $v) => '<strong>' . esc_html($k) . ':</strong> ' . esc_html($v), array_keys($e['fields']), $e['fields']));
            echo '<tr><td>' . esc_html($e['date']) . '</td><td>' . esc_html($e['form']) . '</td><td>' . esc_html($e['email']) . '</td><td>' . $data . '</td></tr>'; // phpcs:ignore
        }
        echo '</tbody></table></div>';
    });
});
add_action('admin_post_mandala_newsletter_csv', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('mandala_csv')) {
        wp_die('Nincs jogosultság.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mandala-hirlevel.csv');
    $out = fopen('php://output', 'w');
    // Az első három oszlop a régi (import-kompatibilis); utána a hozzájárulás bizonyítéka a naplóból (consent.php).
    $subs = (array) get_option('mandala_newsletter', []);
    $proof = mandala_consent_latest('newsletter');
    fputcsv($out, ['email', 'datum', 'forras', 'nev', 'hozzajarulas_idopont_utc', 'ip', 'hozzajarulas_szovege', 'valtozat'], ',', '"', '');
    foreach ($subs as $email => $row) {
        $p = $proof[$email] ?? [];
        fputcsv($out, [$email, $row['date'] ?? '', $row['source'] ?? '', ($row['name'] ?? '') ?: ($p['name'] ?? ''), $p['created'] ?? '', $p['ip'] ?? '', $p['wording'] ?? '', $p['version'] ?? ''], ',', '"', '');
    }
    exit;
});

/* ---------- Hírlevél-űrlap: a sikerüzenet ---------- */

/** Egylépcsős feliratkozás: nincs megerősítő levél, a sikerüzenet ezt mondja. */
function mandala_newsletter_success_text(): string
{
    return __('Feliratkoztál – a következő hírlevelünket már megkapod.', 'mandala');
}
// Az iu/form vázlat-markupjából (templates/, setup/content/ – kanonizálás előtt) hiányzik az űrlap azonosítója
// (iu_form_id) és a hibaüzenet helye; a valódi iu_theme szkriptje nélkülük nem tudja beküldeni az űrlapot (a szerver nem
// ismeri fel, a hibaüzenetnél TypeError). A kanonikus markupban megvannak – ott nem nyúlunk hozzá. A lábléc
// hírlevél-űrlapja: a sikerüzenet akkor is látszódjon, ha a sablon (vagy egy régi, adatbázisba mentett változata) nem
// tartalmazza, és ne ígérjen megerősítő levelet, amit nem küldünk.
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') !== 'iu/form') {
        return $html;
    }
    $id = (string) ($block['attrs']['formId'] ?? $block['attrs']['id'] ?? '');
    if ($id !== '' && !str_contains((string) $html, 'name="iu_form_id"')) {
        $html = (string) preg_replace_callback('~<form\b[^>]*>~', fn($m) => $m[0] . '<input type="hidden" name="iu_form_id" value="' . esc_attr($id) . '">', (string) $html, 1);
    }
    if (!str_contains((string) $html, 'iu-form-error-message')) {
        $html = (string) preg_replace('~</form>~', '<span class="iu-form-error-message" role="alert"></span></form>', (string) $html, 1);
    }
    if ($id !== 'hirlevel') {
        return $html;
    }
    $text = esc_html(mandala_newsletter_success_text());
    $count = 0;
    $html = (string) preg_replace_callback('~(<span class="iu-form-success-message"[^>]*>)(.*?)(</span>)~su', function ($m) use ($text) {
        return $m[1] . (trim(wp_strip_all_tags($m[2])) === '' || preg_match('/megerősít/u', $m[2]) ? $text : $m[2]) . $m[3];
    }, (string) $html, 1, $count);
    if (!$count) {
        $html = (string) preg_replace('~</form>~', '<span class="iu-form-success-message" role="status">' . $text . '</span></form>', $html, 1);
    }
    return $html;
}, 10, 2);
