<?php
/**
 * Telepítő: az adatbázis-változások kódként, verziózott, egyszer futó lépésekben.
 *
 * - Automatikusan fut: egy adminisztrátor első admin-betöltésekor (admin_init), vagy
 *   WP-CLI-vel: `wp mandala setup` (lásd inc/cli.php).
 * - Minden lépés verziót kap; csak az új vagy megemelt verziójú lépések futnak le.
 * - Meglévő, szerkeszthető tartalmat (oldal, sablon) csak akkor ír felül, ha az a
 *   legutóbb általunk írt változattal egyezik (md5 a manifestben). Ha valaki azóta
 *   szerkesztette, nem nyúl hozzá, hanem figyelmeztet.
 * - A mintatermékek, cikkek és kuponok NEM települnek automatikusan (éles boltban a
 *   valódi kínálat van): Megjelenés → Mandala telepítő, vagy `wp mandala demo`.
 */

defined('ABSPATH') || exit;

final class Mandala_Setup
{
    /** Lépés => verzió. Új lépés vagy módosított lépés: verzió emelés. */
    public const STEPS = [
        'site' => 1,
        'woocommerce' => 2,
        'tax' => 1,
        'shipping' => 2,
        'payments' => 1,
        'attributes' => 1,
        'categories' => 1,
        'pages' => 5,
        'menus' => 1,
    ];
    public const DEMO_STEPS = ['demo_products' => 1, 'demo_posts' => 1, 'coupons' => 1, 'demo_features' => 1];

    private const OPTION = 'mandala_setup_steps';
    private const MANIFEST = 'mandala_setup_manifest';
    private const LOG = 'mandala_setup_log';

    /** @var string[] */
    private array $log = [];

    public static function pending(): array
    {
        $done = get_option(self::OPTION, []);
        $skipped = (array) get_option('mandala_setup_skipped', []);
        return array_keys(array_filter(self::STEPS, fn($v, $k) => ($done[$k] ?? 0) < $v && !in_array($k, $skipped, true), ARRAY_FILTER_USE_BOTH));
    }

    /** @return string[] napló */
    public function run(array $only = [], bool $force = false): array
    {
        $this->log = [];
        // A telepítő / bemutató termékei nem importból jönnek: nem kerülnek az „Új termékek” sorba.
        $GLOBALS['mandala_onboarding_skip'] = true;
        if ($only) {
            update_option('mandala_setup_skipped', array_values(array_diff((array) get_option('mandala_setup_skipped', []), $only)), false);
        }
        if (!class_exists('WooCommerce')) {
            $this->log[] = 'A WooCommerce nincs bekapcsolva: a bolti lépések kimaradnak.';
        }
        $done = get_option(self::OPTION, []);
        $steps = self::STEPS + self::DEMO_STEPS;
        foreach ($steps as $step => $version) {
            $demo = isset(self::DEMO_STEPS[$step]);
            if ($only ? !in_array($step, $only, true) : ($demo || (!$force && ($done[$step] ?? 0) >= $version))) {
                continue;
            }
            $shop = !in_array($step, ['site', 'pages', 'menus', 'demo_posts'], true);
            if ($shop && !class_exists('WooCommerce')) {
                continue;
            }
            try {
                $this->{'step_' . $step}();
                $done[$step] = $version;
                $this->log[] = "✓ {$step} (v{$version})";
            } catch (Throwable $e) {
                $this->log[] = "✗ {$step}: " . $e->getMessage();
            }
        }
        update_option(self::OPTION, $done, false);
        mandala_flush_index();
        // A WooCommerce URL-alapjai csak a következő kérésben regisztrálódnak újra: ott ürítünk.
        update_option('mandala_flush_rewrite', 1);
        update_option(self::LOG, ['date' => current_time('mysql'), 'log' => $this->log], false);
        return $this->log;
    }

    /**
     * Beállítás írása úgy, hogy
     *  - az eredeti érték mentésre kerül (Megjelenés → Mandala telepítő → visszaállítás),
     *  - egy későbbi újrafuttatás ne írja felül azt, amit azóta kézzel módosítottak
     *    (pl. `wp mandala eu-shipping` után az engedélyezett országokat).
     */
    private function set(string $key, $value): void
    {
        $norm = function ($v) use (&$norm) {
            return is_array($v) ? array_map($norm, $v) : (is_bool($v) ? ($v ? '1' : '') : (string) $v);
        };
        $written = (array) get_option('mandala_setup_written', []);
        $current = get_option($key, null);
        if (array_key_exists($key, $written) && $current !== null && $norm($current) !== $norm($written[$key])) {
            if ($norm($current) !== $norm($value)) {
                $this->log[] = "! {$key}: kézzel módosították – nem írtam felül.";
            }
            return;
        }
        $backup = (array) get_option('mandala_setup_backup', []);
        if (!array_key_exists($key, $backup)) {
            $backup[$key] = $current === null ? ['missing' => true] : ['value' => $current];
            update_option('mandala_setup_backup', $backup, false);
        }
        update_option($key, $value);
        $written[$key] = $value;
        update_option('mandala_setup_written', $written, false);
    }

    /** Az eredeti (a telepítő előtti) beállítások visszaállítása. */
    public static function restore_backup(): int
    {
        $backup = (array) get_option('mandala_setup_backup', []);
        foreach ($backup as $key => $row) {
            !empty($row['missing']) ? delete_option($key) : update_option($key, $row['value']);
        }
        delete_option('mandala_setup_backup');
        delete_option('mandala_setup_written');
        return count($backup);
    }

    /** Élő bolt (vannak termékek, és a telepítő még nem futott): csak megerősítés után fut. */
    public static function needs_confirmation(): bool
    {
        if (get_option(self::OPTION) || get_option('mandala_setup_confirmed') || !post_type_exists('product')) {
            return false;
        }
        return (bool) get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
    }

    /** Mit állít át az egyes lépés (a megerősítő oldalhoz). */
    public const STEP_INFO = [
        'site' => 'Időzóna (Budapest), dátumformátum, hét első napja; permalink csak ha nincs beállítva; magyar termék-URL-alap csak üres boltban.',
        'woocommerce' => 'Ország, pénznem és árformátum (Ft), súly/méret egység, vendégvásárlás, regisztráció, készletkezelés, pénztári mezők, levelek feladója és színei. FIGYELEM: az engedélyezett és a szállítási országot Magyarországra állítja.',
        'tax' => 'ÁFA: bruttó árak, 27%-os kulcs (ha még nincs), megjelenítés ÁFÁ-val.',
        'shipping' => 'Magyarország szállítási zóna (ha még nincs) személyes átvétellel; a GLS módokat a GLS bővítmény adja.',
        'payments' => 'Előre utalás és utánvét bekapcsolása, a csekk kikapcsolása.',
        'attributes' => 'A szűrők tulajdonságai (pa_szandek, pa_hang, pa_csakra…) – csak a hiányzók jönnek létre.',
        'categories' => 'Az új kategóriafa – csak a hiányzó kategóriák jönnek létre; a meglévőkhöz nem nyúl.',
        'pages' => 'Az új oldalak (kezdőlap, eredetünk, kapcsolat…); a kezdőlapot a Mandala kezdőlapra állítja. A kézzel szerkesztett oldalakat nem írja felül.',
        'menus' => 'Menük – csak ha az adott menühelyen még nincs menü.',
    ];

    /* ---------------------------------------------------------------- lépések */

    private function step_site(): void
    {
        $this->set('blogname', get_option('blogname') && get_option('blogname') !== 'My WordPress Website' && get_option('blogname') !== 'Saját WordPress honlap' ? get_option('blogname') : 'Mandala');
        if (!get_option('blogdescription') || get_option('blogdescription') === 'Just another WordPress site') {
            $this->set('blogdescription', 'Hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából');
        }
        $this->set('timezone_string', 'Europe/Budapest');
        $this->set('date_format', 'Y. F j.');
        $this->set('time_format', 'H:i');
        $this->set('start_of_week', 1);
        $structure = (string) get_option('permalink_structure');
        if (!$structure || ($structure === '/%year%/%monthnum%/%day%/%postname%/' && wp_count_posts('post')->publish <= 1)) {
            $this->set('permalink_structure', '/%postname%/');
        }
        $this->set('posts_per_page', 9);
        $sample = get_page_by_path('sample-page');
        if ($sample && (int) $sample->ID === 2) {
            wp_trash_post($sample->ID);
        }
        // Termék és kategória URL-ek (a sablonfájlok linkjei erre épülnek).
        // Magyar termék- és kategóriaalap – csak üres boltban, hogy egy élő bolt URL-jei (SEO) ne változzanak.
        $has_products = (bool) get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
        if (!$has_products) {
            $wc_permalinks = (array) get_option('woocommerce_permalinks', []);
            $wc_permalinks['product_base'] = '/termek';
            $wc_permalinks['category_base'] = 'kategoria';
            $wc_permalinks['tag_base'] = 'cimke';
            $wc_permalinks['attribute_base'] = '';
            $this->set('woocommerce_permalinks', $wc_permalinks);
        }
    }

    private function step_woocommerce(): void
    {
        $options = [
            'woocommerce_default_country' => 'HU',
            'woocommerce_allowed_countries' => 'specific',
            'woocommerce_specific_allowed_countries' => ['HU'],
            'woocommerce_ship_to_countries' => 'specific',
            'woocommerce_specific_ship_to_countries' => ['HU'],
            'woocommerce_default_customer_address' => 'base',
            'woocommerce_currency' => 'HUF',
            'woocommerce_currency_pos' => 'right_space',
            'woocommerce_price_thousand_sep' => ' ',
            'woocommerce_price_decimal_sep' => ',',
            'woocommerce_price_num_decimals' => 0,
            'woocommerce_weight_unit' => 'g',
            'woocommerce_dimension_unit' => 'cm',
            'woocommerce_enable_coupons' => 'yes',
            'woocommerce_calc_discounts_sequentially' => 'no',
            'woocommerce_enable_guest_checkout' => 'yes',
            'woocommerce_enable_checkout_login_reminder' => 'yes',
            'woocommerce_enable_signup_and_login_from_checkout' => 'yes',
            'woocommerce_enable_myaccount_registration' => 'yes',
            'woocommerce_registration_generate_password' => 'no',
            'woocommerce_manage_stock' => 'yes',
            'woocommerce_notify_low_stock_amount' => 2,
            'woocommerce_notify_no_stock_amount' => 0,
            'woocommerce_stock_format' => 'no_amount',
            'woocommerce_hide_out_of_stock_items' => 'no',
            'woocommerce_enable_ajax_add_to_cart' => 'yes',
            'woocommerce_cart_redirect_after_add' => 'no',
            'woocommerce_ship_to_destination' => 'billing',
            'woocommerce_checkout_company_field' => 'optional',
            'woocommerce_checkout_address_2_field' => 'optional',
            'woocommerce_checkout_phone_field' => 'required',
            'woocommerce_email_from_name' => get_bloginfo('name'),
            'woocommerce_email_from_address' => mandala_config('contact')['email'] ?? get_option('admin_email'),
            'woocommerce_catalog_columns' => 3,
            'woocommerce_catalog_rows' => 4,
            'woocommerce_coming_soon' => 'no',
            // Levelek: a Mandala arculata (automatizmusok és WooCommerce értesítők).
            'woocommerce_email_base_color' => '#A9581A',
            'woocommerce_email_background_color' => '#F7F4EE',
            'woocommerce_email_body_background_color' => '#FFFFFF',
            'woocommerce_email_text_color' => '#1C1916',
            'woocommerce_email_footer_text' => get_bloginfo('name') . ' – hangtálak, füstölők és szakrális tárgyak Nepálból és Indiából',
        ];
        foreach ($options as $key => $value) {
            $this->set($key, $value);
        }
        // A WooCommerce oldalak (kosár, pénztár, fiók, bolt) a „pages” lépésben kapják a tartalmukat.
    }

    private function step_tax(): void
    {
        // Magyar B2C: bruttó árak, 27% ÁFA.
        $this->set('woocommerce_calc_taxes', 'yes');
        $this->set('woocommerce_prices_include_tax', 'yes');
        $this->set('woocommerce_tax_based_on', 'base');
        $this->set('woocommerce_shipping_tax_class', '');
        $this->set('woocommerce_tax_round_at_subtotal', 'no');
        $this->set('woocommerce_tax_display_shop', 'incl');
        $this->set('woocommerce_tax_display_cart', 'incl');
        $this->set('woocommerce_price_display_suffix', '');
        $this->set('woocommerce_tax_total_display', 'single');
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare("SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_country = %s AND tax_rate_class = ''", 'HU'));
        if (!$exists) {
            WC_Tax::_insert_tax_rate(['tax_rate_country' => 'HU', 'tax_rate' => '27.0000', 'tax_rate_name' => 'ÁFA', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '']);
        }
    }

    private function step_shipping(): void
    {
        // Magyarország zóna + személyes átvétel. A GLS módokat (házhoz, CsomagPont, automata),
        // díjaikat és a pontválasztót a GLS bővítmény adja – azokat ott kell beállítani.
        $zone = null;
        foreach (WC_Shipping_Zones::get_zones() as $z) {
            foreach ($z['zone_locations'] as $loc) {
                if ($loc->code === 'HU' && $loc->type === 'country') {
                    $zone = new WC_Shipping_Zone($z['id']);
                }
            }
        }
        if (!$zone) {
            $zone = new WC_Shipping_Zone();
            $zone->set_zone_name('Magyarország');
            $zone->set_zone_order(0);
            $zone->add_location('HU', 'country');
            $zone->save();
        }
        $has_pickup = false;
        foreach ($zone->get_shipping_methods() as $method) {
            $has_pickup = $has_pickup || $method->id === 'local_pickup';
        }
        if (!$has_pickup) {
            $instance_id = $zone->add_shipping_method('local_pickup');
            $this->set("woocommerce_local_pickup_{$instance_id}_settings", ['title' => 'Személyes átvétel', 'tax_status' => 'none', 'cost' => '0']);
            // A pénztár az első módot választja ki: a GLS módok (bővítmény) kerüljenek elé.
            global $wpdb;
            $wpdb->update("{$wpdb->prefix}woocommerce_shipping_zone_methods", ['method_order' => 99], ['instance_id' => $instance_id]);
            WC_Cache_Helper::invalidate_cache_group('shipping_zones');
        }
        if (!mandala_plugin_status()['gls']['active']) {
            $this->log[] = '! A GLS bővítmény nincs aktív: telepítsd, és a Magyarország zónában add hozzá a GLS szállítási módokat.';
        }
    }

    private function step_payments(): void
    {
        $bacs = get_option('woocommerce_bacs_settings', []);
        $this->set('woocommerce_bacs_settings', array_merge($bacs, [
            'enabled' => 'yes',
            'title' => 'Előre utalás',
            'description' => 'A visszaigazolásban és a köszönő oldalon megadjuk a bankszámlaszámot; közleménynek a rendelésszámot írd. A csomagot a jóváírás után adjuk fel (általában 1 munkanap).',
            'instructions' => 'Közleménynek csak a rendelésszámot írd. A csomagot a jóváírás után adjuk fel.',
        ]));
        // Helykitöltő számlaszámot élő boltba nem írunk (a vásárló látná): csak a bemutató tartalom kapja.
        if (!get_option('woocommerce_bacs_accounts')) {
            $this->log[] = '! Add meg a bankszámlaszámot: WooCommerce → Beállítások → Fizetés → Előre utalás.';
        }
        $cod = get_option('woocommerce_cod_settings', []);
        $this->set('woocommerce_cod_settings', array_merge($cod, [
            'enabled' => 'yes',
            'title' => 'Utánvét',
            'description' => 'Fizetés készpénzzel vagy kártyával a futárnak / az automatánál.',
            'instructions' => 'A csomag átvételekor fizetsz.',
            'enable_for_methods' => [],
            'enable_for_virtual' => 'no',
        ]));
        $this->set('woocommerce_cheque_settings', array_merge(get_option('woocommerce_cheque_settings', []), ['enabled' => 'no']));
        $this->set('woocommerce_gateway_order', array_merge((array) get_option('woocommerce_gateway_order', []), ['bacs' => 10, 'cod' => 11]));
    }

    /** Szűrő attribútumok (docs/SZURO.md) és kifejezéseik. */
    private function step_attributes(): void
    {
        $catalog = mandala_data('catalog');
        $chakras = [['gyoker', 'Gyökér'], ['szakralis', 'Szakrális'], ['napfonat', 'Napfonat'], ['sziv', 'Szív'], ['torok', 'Torok'], ['homlok', 'Homlok'], ['korona', 'Korona'], ['het', 'Mind a hét']];
        $attributes = [
            'szandek' => ['Szándék', array_map(fn($i) => [$i['id'], $i['label']], $catalog['intents'] ?? [])],
            'eredet' => ['Eredet', [['nepal', 'Nepál'], ['india', 'India']]],
            'regio' => ['Műhely, régió', []],
            'hang' => ['Hang', array_map(fn($n) => [strtolower(str_replace('#', '-sharp', $n)), $n], ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'])],
            'csakra' => ['Csakra', $chakras],
            'keszites' => ['Készítés', [['kovacsolt', 'Kézzel kovácsolt'], ['ontott', 'Öntött'], ['gepi', 'Gépi']]],
            'illat' => ['Illat', []],
            'forma' => ['Füstölő típusa', []],
            'meret' => ['Méret', array_map(fn($s) => [sanitize_title($s), $s], ['XS', 'S', 'M', 'L', 'XL', 'Egy méret'])],
            'anyag' => ['Anyag', []],
            'szin' => ['Szín', []],
        ];
        foreach ($attributes as $slug => [$label, $terms]) {
            if (!wc_attribute_taxonomy_id_by_name('pa_' . $slug)) {
                wc_create_attribute(['name' => $label, 'slug' => $slug, 'type' => 'select', 'order_by' => 'menu_order', 'has_archives' => false]);
            }
            $taxonomy = 'pa_' . $slug;
            if (!taxonomy_exists($taxonomy)) {
                register_taxonomy($taxonomy, ['product'], ['hierarchical' => false, 'show_ui' => false, 'query_var' => true, 'rewrite' => false]);
            }
            foreach ($terms as $i => [$term_slug, $name]) {
                if (!term_exists($term_slug, $taxonomy)) {
                    $t = wp_insert_term($name, $taxonomy, ['slug' => $term_slug]);
                    if (!is_wp_error($t)) {
                        update_term_meta($t['term_id'], 'order', $i);
                    }
                }
            }
        }
        delete_transient('wc_attribute_taxonomies');
    }

    private function step_categories(): void
    {
        foreach (mandala_data('catalog')['categories'] ?? [] as $i => $cat) {
            $parent = $this->ensure_term($cat['slug'], $cat['label'], 'product_cat', 0, $cat['text'] ?? '');
            update_term_meta($parent, 'order', $i);
            foreach ($cat['subs'] as $j => [$slug, $label]) {
                $sub = $this->ensure_term($slug, $label, 'product_cat', $parent);
                update_term_meta($sub, 'order', $j);
            }
        }
        foreach ((mandala_data('articles')['categories'] ?? []) as $name) {
            $this->ensure_term(sanitize_title($name), $name, 'category');
        }
        delete_transient('mandala_cat_tree');
    }

    private function ensure_term(string $slug, string $name, string $taxonomy, int $parent = 0, string $description = ''): int
    {
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term) {
            return (int) $term->term_id;
        }
        $t = wp_insert_term($name, $taxonomy, ['slug' => $slug, 'parent' => $parent, 'description' => $description]);
        if (is_wp_error($t)) {
            throw new RuntimeException($t->get_error_message());
        }
        return (int) $t['term_id'];
    }

    /** Oldalak a setup/content/*.html tartalmakkal (md5 manifest). */
    private function step_pages(): void
    {
        $pages = [
            // slug => [cím, kivonat, WooCommerce/WP szerep]
            'kezdolap' => ['Kezdőlap', '', 'front'],
            'termekek' => ['Kínálat', 'Hangtálak, füstölők, szobrok, textilek és ajándékok – Nepál és India műhelyeiből.', 'woocommerce_shop_page_id'],
            'kosar' => ['Kosár', '', 'woocommerce_cart_page_id'],
            'penztar' => ['Pénztár', '', 'woocommerce_checkout_page_id'],
            'fiokom' => ['Fiókom', 'Lépj be a rendeléseid követéséhez, vagy hozz létre fiókot a gyorsabb vásárláshoz.', 'woocommerce_myaccount_page_id'],
            'kedvencek' => ['Kedvencek', 'A szívvel jelölt termékeid.', 'mandala_page_kedvencek'],
            'rolunk' => ['Eredetünk', 'Honnan érkeznek a Mandala tárgyai? Nepál és India kis műhelyeitől Budapestig – így válogatunk.', 'mandala_page_rolunk'],
            'viszonteladoknak' => ['Viszonteladóknak', 'Jógastúdióknak, ajándék- és lakberendezési üzleteknek, masszőröknek és hangterapeutáknak: közvetlen import, nagykereskedelmi áron.', 'mandala_page_viszonteladoknak'],
            'ertekeles' => ['Értékelés', 'Köszönjük, hogy megosztod a tapasztalatod.', 'mandala_page_ertekeles'],
            'ajandekcsomag' => ['Ajándékcsomag', 'Válassz néhány tárgyat, mi nepáli lokta papírba csomagoljuk, és kézzel megírjuk a kártyát.', 'mandala_page_ajandekcsomag'],
            'hangtal-valaszto' => ['Hangtál-választó', 'Öt kérdés, és megmutatjuk, melyik tálunk illik hozzád – a hangja, a súlya, a csakrája és a kereted alapján.', 'mandala_page_hangtal-valaszto'],
            'kapcsolat' => ['Kapcsolat', 'Kérdésed van egy termékről, vagy nem tudod, melyik hangtál illik hozzád? Általában egy munkanapon belül válaszolunk.', 'mandala_page_kapcsolat'],
            'informaciok' => ['Vásárlási információk', 'Szállítás, fizetés, visszaküldés – minden, amit a rendelésről tudni érdemes.', 'mandala_page_informaciok'],
            'aszf' => ['Általános Szerződési Feltételek', '', 'woocommerce_terms_page_id'],
            'adatkezelesi-tajekoztato' => ['Adatkezelési tájékoztató', '', 'wp_page_for_privacy_policy'],
            'impresszum' => ['Impresszum', '', 'mandala_page_impresszum'],
            'akadalymentesseg' => ['Akadálymentességi nyilatkozat', '', 'mandala_page_akadalymentesseg'],
            'magazin' => ['Magazin', '', 'page_for_posts'],
        ];
        $manifest = get_option(self::MANIFEST, []);
        foreach ($pages as $slug => [$title, $excerpt, $role]) {
            $file = MANDALA_DIR . '/setup/content/' . $slug . '.html';
            $content = is_readable($file) ? trim((string) file_get_contents($file)) : '';
            $existing = $this->find_page($slug, $role);
            if (!$existing) {
                $id = wp_insert_post(wp_slash([
                    'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug,
                    'post_content' => $content, 'post_excerpt' => $excerpt, 'comment_status' => 'closed',
                ]), true);
                if (is_wp_error($id)) {
                    throw new RuntimeException($id->get_error_message());
                }
                $manifest["page:{$slug}"] = md5($content);
            } else {
                $id = $existing->ID;
                $current = md5(trim($existing->post_content));
                $ours = $manifest["page:{$slug}"] ?? null;
                // Friss telepítés: a WordPress/WooCommerce angol alapoldalai (blokkos kosár és
                // pénztár) helyett a mieink – a skill szerint klasszikus pénztár kell.
                $defaults = ['Shop', 'Cart', 'Checkout', 'My account', 'Privacy Policy', 'Refund and Returns Policy'];
                if (!$ours && (in_array($existing->post_title, $defaults, true) || str_contains($existing->post_content, '<!-- wp:woocommerce/'))) {
                    wp_update_post(wp_slash(['ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_content' => $content, 'post_status' => 'publish']));
                    $manifest["page:{$slug}"] = md5($content);
                    $existing = get_post($id);
                    $current = $ours = $manifest["page:{$slug}"];
                }
                if ($content !== '' && ($current === $ours || trim($existing->post_content) === '') && $current !== md5($content)) {
                    wp_update_post(wp_slash(['ID' => $id, 'post_content' => $content]));
                    $manifest["page:{$slug}"] = md5($content);
                } elseif ($content !== '' && $ours && $current !== $ours && $current !== md5($content)) {
                    $this->log[] = "! A(z) „{$title}” oldalt kézzel szerkesztették: az új tartalmat nem írtam rá (setup/content/{$slug}.html).";
                }
                if ($excerpt && !$existing->post_excerpt) {
                    wp_update_post(['ID' => $id, 'post_excerpt' => $excerpt]);
                }
            }
            if ($role === 'front') {
                $this->set('show_on_front', 'page');
                $this->set('page_on_front', $id);
            } elseif ($role) {
                $this->set($role, $id);
            }
        }
        update_option(self::MANIFEST, $manifest, false);
    }

    private function find_page(string $slug, string $role): ?WP_Post
    {
        $by_role = $role && $role !== 'front' ? (int) get_option($role) : ($role === 'front' ? (int) get_option('page_on_front') : 0);
        if ($by_role && ($p = get_post($by_role)) && $p->post_status !== 'trash') {
            return $p;
        }
        $p = get_page_by_path($slug);
        return $p && $p->post_status !== 'trash' ? $p : null;
    }

    private function step_menus(): void
    {
        $page = fn($opt) => (int) get_option($opt);
        $shop = $page('woocommerce_shop_page_id');
        $menus = [
            'mandala-primary' => ['Fő menü', [
                ['Kínálat', 'page', $shop, ['mega']],
                ['Újdonságok', 'custom', add_query_arg('orderby', 'date', get_permalink($shop))],
                ['Eredetünk', 'page', $page('mandala_page_rolunk')],
                ['Magazin', 'page', $page('page_for_posts')],
                ['Viszonteladóknak', 'page', $page('mandala_page_viszonteladoknak')],
            ]],
            'mandala-footer-shop' => ['Lábléc – Kínálat', array_merge(
                array_map(fn($c) => [$c['label'], 'term', $c['slug']], mandala_data('catalog')['categories'] ?? []),
                [['Újdonságok', 'custom', add_query_arg('orderby', 'date', get_permalink($shop))], ['Akciók', 'custom', add_query_arg('allapot', 'akcios', get_permalink($shop))],
                 ['Ajándékcsomag és utalvány', 'page', $page('mandala_page_ajandekcsomag')]]
            )],
            'mandala-footer-help' => ['Lábléc – Vásárlás', [
                ['Műhelyeink', 'custom', post_type_exists('mandala_workshop') ? get_post_type_archive_link('mandala_workshop') : home_url('/muhelyek/')],
                ['Események, hangfürdők', 'custom', post_type_exists('mandala_event') ? get_post_type_archive_link('mandala_event') : home_url('/esemenyek/')],
                ['Hangtál-választó', 'page', $page('mandala_page_hangtal-valaszto')],
                ['Szállítás és átvétel', 'custom', get_permalink($page('mandala_page_informaciok')) . '#szallitas'],
                ['Fizetési módok', 'custom', get_permalink($page('mandala_page_informaciok')) . '#fizetes'],
                ['Visszaküldés, elállás', 'custom', get_permalink($page('mandala_page_informaciok')) . '#visszakuldes'],
                ['Fiókom és rendeléseim', 'page', $page('woocommerce_myaccount_page_id')],
                ['Viszonteladóknak', 'page', $page('mandala_page_viszonteladoknak')],
                ['Kapcsolat', 'page', $page('mandala_page_kapcsolat')],
            ]],
            'mandala-legal' => ['Jogi linkek', [
                ['ÁSZF', 'page', $page('woocommerce_terms_page_id')],
                ['Adatkezelés', 'page', $page('wp_page_for_privacy_policy')],
                ['Impresszum', 'page', $page('mandala_page_impresszum')],
                ['Akadálymentesség', 'page', $page('mandala_page_akadalymentesseg')],
                ['Sütibeállítások', 'custom', '#sutik', ['cookie-settings']],
            ]],
        ];
        $locations = get_theme_mod('nav_menu_locations', []);
        foreach ($menus as $location => [$name, $items]) {
            if (!empty($locations[$location]) && wp_get_nav_menu_object($locations[$location])) {
                continue; // már van menü ezen a helyen – nem írjuk felül
            }
            $menu = wp_get_nav_menu_object($name);
            $menu_id = $menu ? $menu->term_id : wp_create_nav_menu($name);
            if (is_wp_error($menu_id)) {
                throw new RuntimeException($menu_id->get_error_message());
            }
            if (!$menu || !wp_get_nav_menu_items($menu_id)) {
                foreach ($items as $i => $item) {
                    [$title, $type, $target] = $item;
                    $classes = implode(' ', $item[3] ?? []);
                    $args = ['menu-item-title' => $title, 'menu-item-status' => 'publish', 'menu-item-position' => $i + 1, 'menu-item-classes' => $classes];
                    if ($type === 'page' && $target) {
                        $args += ['menu-item-type' => 'post_type', 'menu-item-object' => 'page', 'menu-item-object-id' => $target];
                    } elseif ($type === 'term' && ($term = get_term_by('slug', $target, 'product_cat'))) {
                        $args += ['menu-item-type' => 'taxonomy', 'menu-item-object' => 'product_cat', 'menu-item-object-id' => $term->term_id];
                    } else {
                        $args += ['menu-item-type' => 'custom', 'menu-item-url' => (string) $target];
                    }
                    wp_update_nav_menu_item($menu_id, 0, $args);
                }
            }
            $locations[$location] = $menu_id;
        }
        set_theme_mod('nav_menu_locations', $locations);
    }

    /* ------------------------------------------------------- bemutató tartalom */

    private function step_demo_products(): void
    {
        // Bemutató bankszámla (helykitöltő), ha még nincs – csak a bemutatóhoz.
        if (!get_option('woocommerce_bacs_accounts')) {
            $bank = mandala_config('bank', []);
            update_option('woocommerce_bacs_accounts', [[
                'account_name' => $bank['holder'] ?? '', 'account_number' => $bank['account'] ?? '', 'bank_name' => $bank['name'] ?? '',
                'sort_code' => '', 'iban' => $bank['iban'] ?? '', 'bic' => $bank['swift'] ?? '',
            ]]);
        }
        foreach (mandala_data('products') as $p) {
            if (wc_get_product_id_by_sku($p['sku'] ?? '')) {
                continue;
            }
            $product = new WC_Product_Simple();
            $product->set_name($p['name']);
            $product->set_slug($p['slug']);
            $product->set_sku($p['sku'] ?? '');
            $product->set_status('publish');
            $product->set_catalog_visibility('visible');
            $product->set_short_description($p['short'] ?? '');
            $product->set_description($p['description'] ?? $p['short'] ?? '');
            if (!empty($p['compare']) && $p['compare'] > $p['price']) {
                $product->set_regular_price((string) $p['compare']);
                $product->set_sale_price((string) $p['price']);
            } else {
                $product->set_regular_price((string) $p['price']);
            }
            $product->set_manage_stock(true);
            $product->set_stock_quantity($p['stock'] === 'out' ? 0 : (int) ($p['stockQty'] ?? 12));
            $product->set_stock_status($p['stock'] === 'out' ? 'outofstock' : 'instock');
            $product->set_featured(!empty($p['featured']));
            $cats = array_filter([get_term_by('slug', $p['cat'], 'product_cat'), !empty($p['sub']) ? get_term_by('slug', $p['sub'], 'product_cat') : null]);
            $product->set_category_ids(array_map(fn($t) => $t->term_id, $cats));
            if (!empty($p['attrs']['suly'])) {
                $product->set_weight((string) $p['attrs']['suly']);
            }
            $product->set_attributes($this->demo_attributes($p));
            foreach (['_mandala_art' => $p['art'] ?? '', '_mandala_tone' => $p['tone'] ?? '', '_mandala_place' => $p['place'] ?? '', '_mandala_ritual' => $p['ritual'] ?? '',
                      '_mandala_hz' => $p['attrs']['hz'] ?? '', '_mandala_suly' => $p['attrs']['suly'] ?? '', '_mandala_demo' => '1'] as $k => $v) {
                if ($v !== '') {
                    $product->update_meta_data($k, (string) $v);
                }
            }
            $id = $product->save();
            if (!empty($p['isNew'])) {
                wp_set_object_terms($id, 'uj', 'product_tag', true);
            }
        }
    }

    private function demo_attributes(array $p): array
    {
        $a = $p['attrs'] ?? [];
        $values = [
            'szandek' => $p['intents'] ?? [],
            'eredet' => !empty($p['origin']) ? [$p['origin']] : [],
            'regio' => !empty($p['region']) ? [$p['region']] : [],
            'hang' => !empty($a['hang']) ? [$a['hang']] : [],
            'csakra' => $a['csakra'] ?? [],
            'keszites' => !empty($a['keszites']) ? [$a['keszites']] : [],
            'illat' => $a['illat'] ?? [],
            'forma' => !empty($a['forma']) ? [$a['forma']] : [],
            'meret' => $a['meret'] ?? [],
            'anyag' => $a['anyag'] ?? [],
            'szin' => $a['szin'] ?? [],
        ];
        $by_slug = ['szandek', 'eredet', 'csakra', 'keszites'];
        $out = [];
        $position = 0;
        foreach ($values as $slug => $list) {
            if (!$list) {
                continue;
            }
            $taxonomy = 'pa_' . $slug;
            $ids = [];
            foreach ($list as $value) {
                $term = in_array($slug, $by_slug, true) ? get_term_by('slug', $value, $taxonomy) : (get_term_by('name', $value, $taxonomy) ?: null);
                if (!$term) {
                    $t = wp_insert_term((string) $value, $taxonomy);
                    $term = is_wp_error($t) ? null : get_term($t['term_id'], $taxonomy);
                    if ($term && $slug === 'szin' && ($color = $this->color_of((string) $value))) {
                        update_term_meta($term->term_id, 'mandala_color', $color);
                    }
                }
                if ($term) {
                    $ids[] = (int) $term->term_id;
                }
            }
            $attr = new WC_Product_Attribute();
            $attr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
            $attr->set_name($taxonomy);
            $attr->set_options($ids);
            $attr->set_position($position++);
            // A szándék/eredet/régió szűrőadat; az adatlapon a többi látszik.
            $attr->set_visible(!in_array($slug, ['szandek', 'eredet', 'regio'], true));
            $attr->set_variation(false);
            $out[] = $attr;
        }
        return $out;
    }

    private function color_of(string $name): string
    {
        return [
            'arany' => '#C9A24A', 'ezüst' => '#BFC3C7', 'bronz' => '#9C6B30', 'réz' => '#B8693D', 'fehér' => '#FFFFFF', 'fekete' => '#1C1916', 'bordó' => '#6B2A2A',
            'terrakotta' => '#C2663F', 'indigó' => '#34506A', 'zöld' => '#4B5C43', 'természetes' => '#D9C9A8',
        ][mb_strtolower($name)] ?? '';
    }

    private function step_demo_posts(): void
    {
        // A WordPress „Hello world!” mintabejegyzése ne jelenjen meg a magazinban.
        $hello = get_page_by_path('hello-world', OBJECT, 'post');
        if ($hello && (int) $hello->ID === 1) {
            wp_trash_post($hello->ID);
        }
        foreach (mandala_data('articles')['articles'] ?? [] as $a) {
            if (get_page_by_path($a['slug'], OBJECT, 'post')) {
                continue;
            }
            $file = MANDALA_DIR . '/setup/content/posts/' . $a['slug'] . '.html';
            $cat = get_term_by('slug', sanitize_title($a['category'] ?? ''), 'category');
            $id = wp_insert_post(wp_slash([
                'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $a['title'], 'post_name' => $a['slug'],
                'post_excerpt' => $a['excerpt'] ?? '', 'post_date' => ($a['date'] ?? gmdate('Y-m-d')) . ' 09:00:00',
                'post_content' => is_readable($file) ? (string) file_get_contents($file) : '', 'post_category' => $cat ? [$cat->term_id] : [],
                'comment_status' => 'closed',
            ]), true);
            if (!is_wp_error($id)) {
                update_post_meta($id, '_mandala_art', $a['art'] ?? 'bowl');
                update_post_meta($id, '_mandala_tone', $a['tone'] ?? 'sand');
                update_post_meta($id, '_mandala_minutes', (int) ($a['minutes'] ?? 4));
                update_post_meta($id, '_mandala_demo', '1');
            }
        }
    }

    /** A funkciómodulok bemutató adatai (hangminták, műhelyek, események, előrendelés…). */
    private function step_demo_features(): void
    {
        do_action('mandala_demo_features');
    }

    private function step_coupons(): void
    {
        foreach (mandala_config('coupons', []) as $code => $c) {
            if (wc_get_coupon_id_by_code($code)) {
                continue;
            }
            $coupon = new WC_Coupon();
            $coupon->set_code($code);
            $coupon->set_description($c['label'] ?? '');
            $coupon->set_discount_type($c['type'] === 'percent' ? 'percent' : 'fixed_cart');
            $coupon->set_amount($c['amount']);
            if (!empty($c['min'])) {
                $coupon->set_minimum_amount($c['min']);
            }
            $coupon->update_meta_data('_mandala_demo', '1');
            $coupon->save();
        }
    }

    /** Bemutató termékek, cikkek és kuponok törlése (csak amit a telepítő hozott létre). */
    public static function delete_demo(): int
    {
        $n = 0;
        $accounts = (array) get_option('woocommerce_bacs_accounts', []);
        if (($accounts[0]['account_number'] ?? null) === (mandala_config('bank', [])['account'] ?? '')) {
            delete_option('woocommerce_bacs_accounts'); // a bemutató helykitöltő számlaszáma
        }
        foreach (apply_filters('mandala_demo_post_types', ['product', 'post', 'shop_coupon']) as $type) {
            foreach (get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_mandala_demo', 'meta_value' => '1', 'fields' => 'ids']) as $id) {
                wp_delete_post($id, true);
                $n++;
            }
        }
        $done = get_option(self::OPTION, []);
        foreach (array_keys(self::DEMO_STEPS) as $step) {
            unset($done[$step]);
        }
        update_option(self::OPTION, $done, false);
        mandala_flush_index();
        return $n;
    }
}

add_action('init', function () {
    if (get_option('mandala_flush_rewrite')) {
        delete_option('mandala_flush_rewrite');
        flush_rewrite_rules(false);
    }
}, 99);

/**
 * A bolt működéséhez szükséges bővítmények. Felismerés az aktív bővítmények és a regisztrált
 * szállítási/fizetési módok alapján (a pontos bővítménynév telepítésenként eltérhet).
 */
function mandala_plugin_status(): array
{
    $active = implode(' ', array_merge((array) get_option('active_plugins', []), array_keys((array) get_site_option('active_sitewide_plugins', []))));
    $has = fn(string $needle) => stripos($active, $needle) !== false;
    $shipping = function_exists('WC') && WC()->shipping() ? implode(' ', array_keys(WC()->shipping()->get_shipping_methods())) : '';
    $gateways = function_exists('WC') && WC()->payment_gateways() ? implode(' ', array_keys(WC()->payment_gateways()->payment_gateways())) : '';
    return [
        'gls' => ['name' => 'GLS szállítás', 'why' => 'Házhozszállítás, GLS CsomagPont és csomagautomata, díjak, térképes pontválasztó.', 'search' => 'GLS WooCommerce',
            'active' => $has('gls') || stripos($shipping, 'gls') !== false],
        'teya' => ['name' => 'Teya kártyás fizetés', 'why' => 'Bankkártya, Apple Pay, Google Pay – a Teya bővítménye vagy fizetőoldala.', 'search' => 'Teya',
            'active' => $has('teya') || stripos($gateways, 'teya') !== false],
        'szamlazz' => ['name' => 'Számlázz.hu', 'why' => 'Automatikus számla a rendelésekhez (NAV online számla).', 'search' => 'Számlázz.hu WooCommerce',
            'active' => $has('szamlazz')],
        'wholesale' => ['name' => 'WooCommerce Wholesale Prices', 'why' => 'Viszonteladói szerep és nagyker ár (a JUTA „Akciós ár”-a termékfelvételkor).', 'search' => 'Wholesale Prices',
            'active' => $has('wholesale') || (bool) get_role('wholesale_customer')],
    ];
}

/** Admin értesítés, ha egy szükséges bővítmény hiányzik. */
add_action('admin_notices', function () {
    if (!current_user_can('manage_options') || (get_current_screen()->id ?? '') === 'appearance_page_mandala-setup') {
        return;
    }
    if (Mandala_Setup::needs_confirmation()) {
        echo '<div class="notice notice-info"><p><strong>Mandala téma:</strong> a telepítő élő boltot talált, ezért nem futott le magától. <a href="' . esc_url(admin_url('themes.php?page=mandala-setup')) . '">Nézd át, mit állít be</a>, és indítsd el a szükséges lépéseket.</p></div>';
    }
    $missing = array_filter(mandala_plugin_status(), fn($p) => !$p['active']);
    if ($missing) {
        echo '<div class="notice notice-warning"><p><strong>Mandala:</strong> hiányzó bővítmény: ' . esc_html(implode(', ', array_column($missing, 'name')))
            . '. <a href="' . esc_url(admin_url('themes.php?page=mandala-setup')) . '">Részletek</a></p></div>';
    }
});

/** Automatikus futtatás adminisztrátornak (egyszer lépésenként; AJAX és cron alatt nem). */
add_action('admin_init', function () {
    if (wp_doing_ajax() || wp_doing_cron() || !current_user_can('manage_options') || !Mandala_Setup::pending() || Mandala_Setup::needs_confirmation()) {
        return;
    }
    (new Mandala_Setup())->run();
}, 20);

/** Megjelenés → Mandala telepítő: állapot, újrafuttatás, bemutató tartalom. */
add_action('admin_menu', function () {
    add_theme_page('Mandala telepítő', 'Mandala telepítő', 'manage_options', 'mandala-setup', function () {
        $log = get_option('mandala_setup_log', []);
        $done = get_option('mandala_setup_steps', []);
        echo '<div class="wrap"><h1>Mandala telepítő</h1>';
        if (!function_exists('iucb_add_block')) {
            echo '<div class="notice notice-warning"><p>Az <strong>iu_custom_blocks</strong> mu-plugin nem aktív: a saját blokkok tartalék módon, szerkesztői beállítások nélkül működnek.</p></div>';
        }
        echo '<h2>Szükséges bővítmények</h2><table class="widefat striped" style="max-width:720px;margin-bottom:24px"><tbody>';
        foreach (mandala_plugin_status() as $p) {
            echo '<tr><td><strong>' . esc_html($p['name']) . '</strong><br><span class="description">' . esc_html($p['why']) . '</span></td><td>'
                . ($p['active'] ? '✓ aktív' : '<a class="button" href="' . esc_url(admin_url('plugin-install.php?s=' . rawurlencode($p['search']) . '&tab=search&type=term')) . '">Keresés és telepítés</a>') . '</td></tr>';
        }
        echo '</tbody></table>';
        if (Mandala_Setup::needs_confirmation()) {
            echo '<div class="notice notice-warning inline" style="max-width:900px"><p><strong>Élő bolt:</strong> a webáruházban már vannak termékek, ezért a telepítő nem fut le magától. Nézd át, mit állít be, és csak azt futtasd, amire szükség van. Minden felülírt beállítás eredeti értékét elmenti – egy gombbal visszaállítható. Élesítés előtt tesztszerveren (a bolt másolatán) próbáld ki.</p></div>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mandala_setup"><input type="hidden" name="do" value="confirm">';
            wp_nonce_field('mandala_setup');
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th style="width:30px"></th><th>Lépés</th><th>Mit állít be</th></tr></thead><tbody>';
            foreach (Mandala_Setup::STEP_INFO as $step => $info) {
                echo '<tr><td><input type="checkbox" name="steps[]" value="' . esc_attr($step) . '" id="st-' . esc_attr($step) . '" checked></td><td><label for="st-' . esc_attr($step) . '"><strong>' . esc_html($step) . '</strong></label></td><td>' . esc_html($info) . '</td></tr>';
            }
            echo '</tbody></table><p><button class="button button-primary">A kijelölt lépések futtatása</button> <span class="description">A ki nem jelöltek kimaradnak; később egyenként futtathatók.</span></p></form></div>';
            return;
        }
        $skipped = (array) get_option('mandala_setup_skipped', []);
        echo '<h2>Telepítő lépések</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Lépés</th><th>Verzió</th><th>Állapot</th><th>Mit állít be</th></tr></thead><tbody>';
        foreach (Mandala_Setup::STEPS + Mandala_Setup::DEMO_STEPS as $step => $v) {
            $ok = ($done[$step] ?? 0) >= $v;
            $state = $ok ? '✓ kész' : (in_array($step, $skipped, true) ? 'kihagyva' : '–');
            $run = !$ok && !isset(Mandala_Setup::DEMO_STEPS[$step]) ? ' <button class="button button-small" form="mandala-step" name="step" value="' . esc_attr($step) . '">Futtatás</button>' : '';
            echo '<tr><td>' . esc_html($step) . (isset(Mandala_Setup::DEMO_STEPS[$step]) ? ' <em>(bemutató)</em>' : '') . '</td><td>' . (int) $v . '</td><td>' . esc_html($state) . $run . '</td><td class="description">' . esc_html(Mandala_Setup::STEP_INFO[$step] ?? '') . '</td></tr>';
        }
        echo '</tbody></table><form id="mandala-step" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mandala_setup"><input type="hidden" name="do" value="step">';
        wp_nonce_field('mandala_setup');
        echo '</form>';
        $backup = (array) get_option('mandala_setup_backup', []);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">';
        wp_nonce_field('mandala_setup');
        echo '<input type="hidden" name="action" value="mandala_setup">';
        echo '<button class="button button-primary" name="do" value="run">Függő lépések futtatása</button>';
        echo '<button class="button" name="do" value="force">Minden lépés újrafuttatása</button>';
        echo '<button class="button" name="do" value="demo">Bemutató termékek, cikkek, kuponok importálása</button>';
        echo '<button class="button" name="do" value="demo-delete" onclick="return confirm(\'Törlöd a bemutató tartalmat?\')">Bemutató tartalom törlése</button>';
        if ($backup) {
            echo '<button class="button" name="do" value="restore" onclick="return confirm(\'A telepítő által átírt ' . count($backup) . ' beállítás visszaáll az eredeti értékére. Folytatod?\')">Eredeti beállítások visszaállítása (' . count($backup) . ')</button>';
        }
        echo '</form>';
        if ($log) {
            echo '<h2>Utolsó futás: ' . esc_html($log['date']) . '</h2><pre style="background:#fff;padding:12px;max-width:720px;white-space:pre-wrap">' . esc_html(implode("\n", $log['log'])) . '</pre>';
        }
        echo '</div>';
    });
});
add_action('admin_post_mandala_setup', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('mandala_setup')) {
        wp_die('Nincs jogosultság.');
    }
    $do = sanitize_key($_POST['do'] ?? 'run');
    $setup = new Mandala_Setup();
    match ($do) {
        'confirm' => (function () use ($setup) {
            $steps = array_values(array_intersect(array_map('sanitize_key', (array) ($_POST['steps'] ?? [])), array_keys(Mandala_Setup::STEPS)));
            update_option('mandala_setup_confirmed', 1, false);
            update_option('mandala_setup_skipped', array_values(array_diff(array_keys(Mandala_Setup::STEPS), $steps)), false);
            $setup->run($steps ?: ['__none__']);
        })(),
        'step' => $setup->run([sanitize_key($_POST['step'] ?? '')]),
        'restore' => Mandala_Setup::restore_backup(),
        'force' => $setup->run([], true),
        'demo' => $setup->run(array_keys(Mandala_Setup::DEMO_STEPS)),
        'demo-delete' => Mandala_Setup::delete_demo(),
        default => $setup->run(),
    };
    wp_safe_redirect(admin_url('themes.php?page=mandala-setup'));
    exit;
});
