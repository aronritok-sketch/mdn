<?php
/**
 * Egyszeri tartalomjavítások az élő boltban – a téma frissítése után maguktól lefutnak (egyszer), mert az
 * adminhoz közvetlenül nem fértünk hozzá. Minden módosított oldalról a WordPress revíziót ment, így
 * Oldalak → (oldal) → Revíziók alatt visszaállítható. Az eredmény: Eszközök → Webhely állapota helyett a
 * „mandala_sitefix” beállításban (és az élesítési állapotban: readiness → sitefix).
 *
 * 2026-10 (vásárlói végigtesztelés után):
 * - bemutatóterem címe (Bakáts u. 6.) és nyitvatartása – kapcsolat oldal, személyes átvétel, Google adat,
 * - Impresszum / Akadálymentességi nyilatkozat: a helykitöltő mondat és a kitöltendő részek,
 * - ÁSZF: Teya (az OTP / SimplePay helyett), mai fizetési és szállítási díjak,
 * - „A bankkártyás fizetésről”: a CIB Bank helyett Teya,
 * - a WordPress mintaoldala („Ez egy minta oldal”) a lomtárba,
 * - Számlázz.hu: a meglévő céges rendelések adószáma a bővítmény által olvasott kulcsba is.
 */

defined('ABSPATH') || exit;

const MANDALA_SHOWROOM = ['street' => 'Bakáts u. 6.', 'zip' => '1093', 'city' => 'Budapest'];
const MANDALA_TEYA_PRIVACY = 'https://legal.teya.com/hungary/privacypolicy';

/** A bemutatóterem teljes címe egy sorban (a Mandala bolt adataiból, különben a fenti alapértelmezés). */
function mandala_showroom_address(): string
{
    $b = function_exists('mandala_localbiz') ? mandala_localbiz() : [];
    $street = ($b['street'] ?? '') ?: MANDALA_SHOWROOM['street'];
    $zip = ($b['zip'] ?? '') ?: MANDALA_SHOWROOM['zip'];
    $city = ($b['city'] ?? '') ?: MANDALA_SHOWROOM['city'];
    return trim("$zip $city, $street");
}

/** Egy oldal tartalmának cseréje (revízióval); visszaadja, történt-e változás. */
function mandala_sitefix_update(int $id, callable $fn): bool
{
    $post = get_post($id);
    if (!$post) {
        return false;
    }
    $new = (string) $fn((string) $post->post_content);
    if ($new === $post->post_content || trim($new) === '') {
        return false;
    }
    $r = wp_update_post(['ID' => $id, 'post_content' => wp_slash($new)], true);
    return !is_wp_error($r);
}

/** Közzétett oldal azonosítója a lehetséges címek közül. */
function mandala_sitefix_page(array $slugs): int
{
    foreach ($slugs as $slug) {
        $p = get_page_by_path($slug);
        if ($p && $p->post_status !== 'trash') {
            return (int) $p->ID;
        }
    }
    return 0;
}

/** A javítások listája: azonosító => függvény (visszaad egy rövid leírást, mi történt). */
function mandala_sitefix_list(): array
{
    return [
        'showroom-2026-10' => function () {
            $done = [];
            // Kapcsolati adatok: a helykitöltő cím helyett a valódi.
            $c = get_option('mandala_contact', null);
            if (is_array($c) && in_array(trim((string) ($c['address'] ?? '')), ['', 'Budapest', 'Budapest – bemutatóterem és átvevőhely'], true)) {
                $c['address'] = mandala_showroom_address();
                update_option('mandala_contact', $c);
                $done[] = 'cím a bolt adataiban';
            }
            // Google / térkép adat: utca, és a nyitvatartás (H 9–17, K–P 9–15, Szo–V zárva), ha még az alapértelmezett.
            $b = (array) get_option('mandala_localbiz', []);
            if (($b['street'] ?? '') === '') {
                $b = array_merge($b, MANDALA_SHOWROOM);
                $b['maps'] = ($b['maps'] ?? '') ?: 'https://maps.google.com/?q=' . rawurlencode('Mandala, ' . MANDALA_SHOWROOM['zip'] . ' Budapest, ' . MANDALA_SHOWROOM['street']);
                $done[] = 'bemutatóterem címe (Google)';
            }
            if (in_array(($b['wk_open'] ?? '10:00') . '-' . ($b['wk_close'] ?? '18:00'), ['10:00-18:00', '09:00-15:00', '09:00-17:00'], true) && empty($b['mo_open'])) {
                $b = array_merge($b, ['mo_open' => '09:00', 'mo_close' => '17:00', 'wk_open' => '09:00', 'wk_close' => '15:00', 'sa_open' => '', 'sa_close' => '', 'su_open' => '', 'su_close' => '']);
                $done[] = 'nyitvatartás (Google)';
            }
            update_option('mandala_localbiz', $b, false);
            return $done ? implode(', ', $done) : 'már rendben volt';
        },

        'impresszum-2026-10' => function () {
            $id = mandala_sitefix_page(['impresszum']);
            return $id && mandala_sitefix_update($id, fn($c) => preg_replace('~\s*<span class="text-muted">A szöveg helykitöltő[^<]*</span>~u', '', $c)) ? 'helykitöltő mondat törölve' : 'nem kellett';
        },

        'akadalymentesseg-2026-10' => function () {
            $id = mandala_sitefix_page(['akadalymentesseg', 'akadalymentessegi-nyilatkozat']);
            if (!$id) {
                return 'nincs ilyen oldal';
            }
            $contact = (array) mandala_config('contact', []);
            $email = (string) ($contact['email'] ?? 'info@mandala.hu');
            $phone = (string) ($contact['phone'] ?? '');
            $reach = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' . ($phone ? ' · <a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $phone)) . '">' . esc_html($phone) . '</a>' : '');
            return mandala_sitefix_update($id, fn($c) => strtr(preg_replace('~\s*<span class="text-muted">A szöveg helykitöltő[^<]*</span>~u', '', $c), [
                ' [A lista az éles ellenőrzés után pontosítandó.]' => '',
                '[e-mail] · [telefon]' => $reach,
                '[A hatóság neve és elérhetősége – kitöltendő a hatályos szabályozás szerint.]' => 'Az akadálymentességi követelmények (2022. évi XVII. törvény) betartását a piacfelügyeleti hatóság ellenőrzi; webáruházzal kapcsolatos fogyasztói panasszal a lakóhelyed szerinti vármegyei kormányhivatal fogyasztóvédelmi hatóságához – Budapesten a Budapest Főváros Kormányhivatalához – fordulhatsz (elérhetőségek: <a href="https://kormanyhivatalok.hu">kormanyhivatalok.hu</a>).',
                'a legutóbbi ellenőrzés dátuma: [kitöltendő].' => 'a legutóbbi ellenőrzés dátuma: 2026. október 2.',
            ])) ? 'kitöltve (elérhetőség, hatóság, dátum), helykitöltők törölve' : 'nem kellett';
        },

        'aszf-teya-2026-10' => function () {
            $id = mandala_sitefix_page(['aszf', 'altalanos-szerzodesi-feltetelek']);
            if (!$id) {
                return 'nincs ÁSZF oldal';
            }
            $card = esc_url(home_url('/a-bankkartyas-fizetesrol/'));
            $addr = esc_html(mandala_showroom_address());
            $p = fn($inner) => '<p>' . $inner . '</p>';
            return mandala_sitefix_update($id, function ($c) use ($card, $addr, $p) {
                $orig = $c;
                // Adattovábbítási nyilatkozat: OTP Mobil / SimplePay → Teya
                $c = preg_replace('~<p>(?:(?!</p>).)*OTP Mobil Kft(?:(?!</p>).)*</p>~su', $p('Tudomásul veszem, hogy az <strong>Asita Cult Kft.</strong> (1093 Budapest, Bakáts u. 6.) adatkezelő által a <strong>mandala.hu</strong> felhasználói adatbázisában tárolt alábbi személyes adataim átadásra kerülnek a <strong>Teya Iceland hf.</strong> (székhely: Katrínartún 4, 105 Reykjavík, Izland; cégjegyzékszám: 4406861259), mint adatfeldolgozó részére.'), $c);
                $c = preg_replace('~<p>(?:(?!</p>).)*SimplePay adatkezelési(?:(?!</p>).)*</p>~su', $p('Az adatfeldolgozó által végzett adatfeldolgozási tevékenység jellege és célja a Teya adatkezelési tájékoztatójában tekinthető meg: <a href="' . MANDALA_TEYA_PRIVACY . '" target="_blank" rel="noopener">legal.teya.com/hungary/privacypolicy</a>'), $c);
                // Tartalék, ha a bekezdések más formában vannak elmentve: mondatszintű csere.
                $c = strtr($c, [
                    'OTP Mobil Kft.' => 'Teya Iceland hf. (székhely: Katrínartún 4, 105 Reykjavík, Izland; cégjegyzékszám: 4406861259)',
                    'SimplePay adatkezelési tájékoztatóban' => 'Teya adatkezelési tájékoztatójában',
                    'http://simplepay.hu/vasarlo-aff' => MANDALA_TEYA_PRIVACY,
                    'https://simplepay.hu/vasarlo-aff' => MANDALA_TEYA_PRIVACY,
                    'simplepay.hu/vasarlo-aff' => 'legal.teya.com/hungary/privacypolicy',
                ]);
                // Fizetési módok
                $c = preg_replace('~<li>\s*<strong>Bankkártya</strong>\s*</li>~u', '<li><strong>Bankkártya:</strong> online, a Teya biztonságos fizetőoldalán (Visa, Mastercard, Maestro) – részletek: <a href="' . $card . '">A bankkártyás fizetésről</a>.</li>', $c);
                $c = preg_replace('~<li>\s*<strong>Utánvét:</strong>[^<]*</li>~u', '<li><strong>Utánvét:</strong> a vevő a futárnak vagy a GLS csomagponton, az áru átvételekor fizet készpénzzel vagy bankkártyával. Az utánvét díja 490 Ft.</li>', $c);
                $c = preg_replace('~<li>\s*<strong>Készpénzes fizetés nagykereskedésünkben:</strong>[^<]*</li>~u', '<li><strong>Fizetés helyszínen (készpénz vagy bankkártya):</strong> személyes átvétel esetén, a budapesti bemutatóteremben (' . $addr . ').</li>', $c);
                // Szállítási díjak: a mai díjak
                $c = preg_replace('~(<h3>\s*Szállítási költségek\s*</h3>\s*)<ul>.*?</ul>~su', '$1<ul>'
                    . '<li><strong>GLS házhozszállítás:</strong> 1 417 Ft</li>'
                    . '<li><strong>GLS csomagpont / csomagautomata:</strong> 1 417 Ft</li>'
                    . '<li><strong>MPL házhozszállítás:</strong> 1 567 Ft</li>'
                    . '<li><strong>Személyes átvétel</strong> a budapesti bemutatóteremben (' . $addr . '): ingyenes</li>'
                    . '<li><strong>Utánvét díja:</strong> 490 Ft (személyes átvételnél nincs)</li>'
                    . '<li><strong>Magyarországon</strong> 25 000 Ft feletti vásárlás esetén a szállítás ingyenes (az utánvét díja ekkor is fizetendő).</li>'
                    . '<li><strong>Viszonteladóknak:</strong> dobozonként 2900 Ft</li>'
                    . '<li><strong>Külföldi kiszállítás</strong> (Ausztria, Szlovénia, Szlovákia, Csehország, Románia, Horvátország): az országtól függően 5 000–7 087 Ft, 5 kg-os csomagig; külföldre 25 000 Ft felett is felszámításra kerül.</li>'
                    . '<li>A rendelésre vonatkozó pontos szállítási díjat a pénztár a megrendelés elküldése előtt mindig feltünteti.</li>'
                    . '</ul>', $c, 1);
                if ($c !== $orig && !str_contains($c, 'Módosítva: 2026. október 2.')) {
                    $c = $p('<em>Módosítva: 2026. október 2. – fizetési és szállítási feltételek, bankkártyás fizetés (Teya).</em>') . "\n" . $c;
                }
                return $c;
            }) ? 'Teya, fizetési módok és szállítási díjak frissítve' : 'nem kellett (vagy már frissítve)';
        },

        'bankkartya-teya-2026-10' => function () {
            $id = mandala_sitefix_page(['a-bankkartyas-fizetesrol', 'bankkartyas-fizetes']);
            return $id && mandala_sitefix_update($id, fn($c) => str_contains($c, 'CIB') || str_contains($c, 'SimplePay') || trim(wp_strip_all_tags($c)) === '' ? mandala_card_payment_html() : $c)
                ? 'a CIB-es szöveg helyett Teya' : 'nem kellett';
        },

        // Az ÁSZF ne ígérjen előre utalást, ha a banki átutalás a boltban ki van kapcsolva (bekapcsolva marad a sor).
        'aszf-utalas-2026-10' => function () {
            $bacs = (array) get_option('woocommerce_bacs_settings', []);
            if (($bacs['enabled'] ?? 'no') === 'yes') {
                return 'az átutalás be van kapcsolva – a sor marad';
            }
            $id = mandala_sitefix_page(['aszf', 'altalanos-szerzodesi-feltetelek']);
            return $id && mandala_sitefix_update($id, function ($c) {
                $c = preg_replace('~\s*<li>\s*<strong>\s*Előre utalás\s*:?\s*</strong>[^<]*</li>~u', '', $c);
                // A hozzá tartozó bekezdés (számlamásolat → átutalás → kiszállítás).
                return preg_replace('~\s*<p>(?:(?!</p>).)*számlamásolatot küldünk(?:(?!</p>).)*átutalás(?:(?!</p>).)*</p>~su', '', $c);
            }) ? 'az „Előre utalás” sor és bekezdése kivéve (az átutalás ki van kapcsolva)' : 'nem kellett';
        },

        // Régi viszonteladók: ha az átvételkor nem volt meg a „wholesale_customer” szerep, sima vásárlóként jöttek át
        // (a régi szerep a _mandala_old_role mezőben van) – így nem látják a nagyker árat. Visszaállítjuk.
        'viszontelado-szerep-2026-10' => function () {
            if (!get_role('wholesale_customer') && ($customer = get_role('customer'))) {
                add_role('wholesale_customer', 'Wholesale Customer', $customer->capabilities);
            }
            $n = 0;
            foreach (get_users(['meta_key' => '_mandala_old_role', 'fields' => 'all']) as $user) {
                $old = (string) get_user_meta($user->ID, '_mandala_old_role', true);
                if (!str_contains($old, 'wholesale') || array_diff((array) $user->roles, ['customer', 'subscriber'])) {
                    continue;
                }
                $user->set_role(get_role($old) ? $old : 'wholesale_customer');
                delete_user_meta($user->ID, '_mandala_old_role');
                $n++;
            }
            return $n ? $n . ' régi viszonteladó szerepe visszaállítva' : 'nem kellett';
        },

        // Számlázz.hu: az adószámot a bővítmény a `_billing_wc_szamlazz_adoszam` kulcsból olvassa, a pénztár eddig
        // csak a `_billing_tax_number` alá mentette – a számlákra nem került adószám. A meglévő céges rendeléseket
        // (és a vásárlók fiókját) pótoljuk, hogy a még ki nem állított számlák jók legyenek.
        'szamlazz-adoszam-2026-10' => function () {
            global $wpdb;
            $n = 0;
            // Az érintett rendelések azonosítói (HPOS-táblából vagy a postmeta-ból – a meta_query nem mindkettőn megy).
            $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
            $ids = $hpos
                ? $wpdb->get_col("SELECT order_id FROM {$wpdb->prefix}wc_orders_meta WHERE meta_key = '_billing_tax_number' AND meta_value <> '' ORDER BY order_id DESC LIMIT 2000")
                : $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_billing_tax_number' AND meta_value <> '' ORDER BY post_id DESC LIMIT 2000");
            foreach (array_map('intval', $ids) as $id) {
                $order = wc_get_order($id);
                if ($order instanceof WC_Order && mandala_sync_tax_number($order)) {
                    $order->save_meta_data(); // csak a meta – nem indít rendelés-eseményt (levél, JUTA)
                    $n++;
                }
            }
            $u = 0;
            foreach (get_users(['meta_key' => 'billing_tax_number', 'meta_compare' => '!=', 'meta_value' => '', 'fields' => 'ID']) as $uid) { // phpcs:ignore
                $tax = sanitize_text_field((string) get_user_meta($uid, 'billing_tax_number', true));
                if ($tax !== '' && (string) get_user_meta($uid, 'wc_szamlazz_adoszam', true) !== $tax) {
                    update_user_meta($uid, 'wc_szamlazz_adoszam', $tax);
                    $u++;
                }
            }
            return ($n || $u) ? "$n rendelés, $u vásárló adószáma bemásolva a Számlázz.hu kulcsába" : 'nem kellett';
        },

        'mintaoldal-2026-10' => function () {
            $n = 0;
            foreach (['ez-egy-minta-oldal', 'sample-page', 'minta-oldal'] as $slug) {
                $p = get_page_by_path($slug);
                if ($p && $p->post_status === 'publish' && preg_match('/pizzafutár|Velorex|XYZ Bigyó|mintaoldal|sample page/iu', $p->post_content . $p->post_title)) {
                    wp_trash_post($p->ID);
                    $n++;
                }
            }
            return $n ? 'mintaoldal a lomtárban' : 'nem volt';
        },
    ];
}

/** „A bankkártyás fizetésről” oldal szövege (Teya). */
function mandala_card_payment_html(): string
{
    $contact = (array) mandala_config('contact', []);
    $email = esc_html((string) ($contact['email'] ?? 'info@mandala.hu'));
    $phone = esc_html((string) ($contact['phone'] ?? ''));
    $blocks = [
        ['p', 'A webáruházban bankkártyával a <strong>Teya</strong> biztonságos online fizetőoldalán fizethetsz. A rendelés elküldése után a rendszer átirányít a Teya fizetőoldalára, ott adod meg a kártyaadataidat; a fizetés után visszakerülsz a webáruházba, ahol látod az eredményt, és e-mailben is visszaigazoljuk a rendelést.'],
        ['h2', 'Biztonság'],
        ['p', 'A kártyaadataidat kizárólag a Teya fizetőoldala kapja meg, titkosított (TLS) kapcsolaton. A webáruház – az Asita Cult Kft. – a kártyaadataidhoz nem fér hozzá, azokat nem látja és nem tárolja; a fizetés eredményéről a Teya értesíti a webáruházat. A kártyakibocsátó bankod a fizetést erős ügyfél-hitelesítéssel (3D Secure – például mobilbanki jóváhagyással vagy SMS-kóddal) is megerősítheti.'],
        ['h2', 'Elfogadott kártyák'],
        ['p', 'Visa (Visa Electron is), Mastercard és Maestro – ha a kártyádat a kibocsátó bank internetes vásárlásra engedélyezte.'],
        ['h2', 'Ha nem sikerül a fizetés'],
        ['p', 'Ellenőrizd a kártyaadatokat, hogy a kártyád engedélyezett-e internetes vásárlásra, és van-e rajta elég fedezet; szükség esetén keresd a kártyakibocsátó bankodat. Sikertelen fizetésnél a rendelés nem teljesül; ha az összeget a bankod zárolta, a zárolás a bank gyakorlata szerint feloldódik. A rendelést újra kifizetheted a visszaigazoló levélben vagy a fiókodban, vagy választhatsz más fizetési módot.'],
        ['p', 'Visszatérítéskor (például elállás esetén) az összeget arra a kártyára utaljuk vissza, amellyel fizettél; a jóváírás ideje a kártyakibocsátó banktól függ.'],
        ['p', 'Kérdésed van? Írj nekünk: <a href="mailto:' . $email . '">' . $email . '</a>' . ($phone ? ' · <a href="tel:' . esc_attr(preg_replace('/[^\d+]/', '', $phone)) . '">' . $phone . '</a>' : '') . '.'],
        ['h2', 'Adattovábbítási nyilatkozat'],
        ['p', 'Tudomásul veszem, hogy az <strong>Asita Cult Kft.</strong> (1093 Budapest, Bakáts u. 6.) adatkezelő által a <strong>mandala.hu</strong> felhasználói adatbázisában tárolt alábbi személyes adataim átadásra kerülnek a <strong>Teya Iceland hf.</strong> (székhely: Katrínartún 4, 105 Reykjavík, Izland; cégjegyzékszám: 4406861259), mint adatfeldolgozó részére. Az adatkezelő által továbbított adatok köre: név, e-mail-cím, telefonszám, számlázási cím, szállítási cím, a rendelés azonosítója és összege.'],
        ['p', 'Az adatfeldolgozó által végzett adatfeldolgozási tevékenység jellege és célja a Teya adatkezelési tájékoztatójában tekinthető meg: <a href="' . MANDALA_TEYA_PRIVACY . '" target="_blank" rel="noopener">legal.teya.com/hungary/privacypolicy</a>'],
    ];
    $out = '';
    foreach ($blocks as [$tag, $html]) {
        $out .= $tag === 'h2'
            ? "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">$html</h2>\n<!-- /wp:heading -->\n\n"
            : "<!-- wp:paragraph -->\n<p>$html</p>\n<!-- /wp:paragraph -->\n\n";
    }
    return $out;
}

/** Futtatás: minden még le nem futott javítás egyszer; az eredmény a „mandala_sitefix” beállításba kerül. */
function mandala_sitefix_run(): array
{
    $done = (array) get_option('mandala_sitefix', []);
    foreach (mandala_sitefix_list() as $key => $fn) {
        if (isset($done[$key])) {
            continue;
        }
        // Előbb jelöljük: hiba esetén se fusson újra minden kérésnél.
        $done[$key] = ['time' => time(), 'result' => 'fut…'];
        update_option('mandala_sitefix', $done, false);
        try {
            $done[$key]['result'] = (string) $fn();
        } catch (Throwable $e) {
            $done[$key]['result'] = 'hiba: ' . $e->getMessage();
        }
        update_option('mandala_sitefix', $done, false);
    }
    return $done;
}

add_action('init', function () {
    if (wp_installing() || (defined('WP_CLI') && WP_CLI) || get_transient('mandala_sitefix_lock')) {
        return;
    }
    $done = (array) get_option('mandala_sitefix', []);
    if (!array_diff_key(mandala_sitefix_list(), $done)) {
        return;
    }
    set_transient('mandala_sitefix_lock', 1, MINUTE_IN_SECONDS);
    mandala_sitefix_run();
}, 99);
