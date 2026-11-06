<?php
/**
 * Termékfeedek az ár-összehasonlítóknak és a hirdetési katalógusoknak – önjáró, óránként frissül.
 *
 *  - Google Merchant Center (ingyenes megjelenés + Shopping): RSS 2.0, g: névtér, változatokkal.
 *  - Meta (Facebook / Instagram katalógus): ugyanaz a formátum, külön címen.
 *  - Árukereső, Árgép és Olcsóbbat: a saját XML formátumuk (a regisztrációnál a feed-ellenőrzőjükkel érdemes
 *    egyszer átnézni).
 *  - Régi címek: amit a régi bolt feed-bővítményei adtak (Árukereső for WooCommerce: /feed/arukereso,
 *    uploads/arukereso-xml/…, uploads/arukereso-<véletlen>/…; Product Feed PRO, CTX Feed), ugyanazon a címen a
 *    téma feedjét kapja – az ár-összehasonlítóknál nem kell semmit átírni. A kért régi címek naplója az adminban.
 *
 * A feedek a háttérben (Action Scheduler) készülnek a feltöltések közé (uploads/mandala-feeds), a
 * cím csak kiszolgálja a fájlt – 3000+ termékkel is gyors. Kimarad: utalvány, eseményjegy, rejtett /
 * nem közzétett termék; az összehasonlítókból az elfogyott is (beállítható).
 * Címek: WooCommerce → Mandala feedek (és a telepítő varázslóban).
 */

defined('ABSPATH') || exit;

const MANDALA_FEEDS = [
    'google' => ['label' => 'Google Merchant Center', 'where' => 'merchants.google.com → Termékek → Adatforrások → Hozzáadás → „Fájl URL-je”', 'type' => 'google'],
    'meta' => ['label' => 'Meta (Facebook / Instagram katalógus)', 'where' => 'Commerce Manager → Katalógus → Adatforrások → Adatfeed → ütemezett URL', 'type' => 'google'],
    'arukereso' => ['label' => 'Árukereső', 'where' => 'Árukereső partnerfelület → Termékfeed (XML) URL', 'type' => 'arukereso'],
    'argep' => ['label' => 'Árgép', 'where' => 'Árgép boltfelület → Terméklista (XML) URL', 'type' => 'argep'],
    'olcsobbat' => ['label' => 'Olcsóbbat', 'where' => 'Olcsóbbat partnerfelület → Termékfeed (XML) URL', 'type' => 'olcsobbat'],
    'pinterest' => ['label' => 'Pinterest katalógus', 'where' => 'business.pinterest.com → Katalógusok → Adatforrás hozzáadása → „Adatforrás URL-je” (RSS/XML)', 'type' => 'google'],
];

function mandala_feed_settings(): array
{
    return wp_parse_args((array) get_option('mandala_feeds', []), [
        'brand' => get_bloginfo('name'),
        'skip_out' => 'yes',          // az összehasonlítókból az elfogyott kimarad
        'delivery_time' => '1-2 munkanap',
    ]);
}

function mandala_feed_url(string $name): string
{
    return add_query_arg('mandala_feed', $name, home_url('/'));
}
function mandala_feed_dir(): string
{
    return trailingslashit(wp_upload_dir()['basedir']) . 'mandala-feeds';
}

/* ---------- Termékadatok ---------- */

/** Feedbe kerülő tételek (változók változatai külön). */
function mandala_feed_items(): array
{
    $items = [];
    $ids = wc_get_products(['status' => 'publish', 'visibility' => 'catalog', 'limit' => -1, 'return' => 'ids', 'type' => ['simple', 'variable']]);
    $free = (int) mandala_config('freeShippingFrom', 25000);
    $shipping = (array) mandala_config('shipping', []);
    $ship_price = (float) ($shipping[0]['price'] ?? 1990);
    foreach (array_values($ids) as $i => $id) {
        if ($i % 200 === 0) {
            // 200 termékenként egy-egy lekérdezéssel előtöltve: bejegyzés, meta, kifejezések, képek.
            $chunk = array_slice($ids, $i, 200);
            _prime_post_caches($chunk, true, true);
            $images = [];
            foreach ($chunk as $cid) {
                $images[] = (int) get_post_thumbnail_id($cid);
                array_push($images, ...array_map('intval', array_filter(explode(',', (string) get_post_meta($cid, '_product_image_gallery', true)))));
            }
            _prime_post_caches(array_filter($images), false, true);
        }
        $product = wc_get_product($id);
        if (!$product || $product->is_virtual() || $product->get_meta('_mandala_ticket_for') || (function_exists('mandala_is_voucher') && mandala_is_voucher($product))) {
            continue;
        }
        $variants = $product->is_type('variable') ? array_filter(array_map('wc_get_product', $product->get_children())) : [$product];
        [$cat, $sub] = mandala_product_cats($id);
        $cat_path = trim(mandala_term_name($cat) . ($sub ? ' > ' . mandala_term_name($sub) : ''), ' >');
        $images = array_filter(array_map(fn($a) => wp_get_attachment_image_url($a, 'full'), array_merge([$product->get_image_id()], $product->get_gallery_image_ids())));
        $desc = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($product->get_short_description() ?: $product->get_description())));
        $origin = mandala_attr($product, 'pa_eredet')[0] ?? '';
        foreach ($variants as $v) {
            $regular = (float) $v->get_regular_price('edit');
            $sale = $v->is_on_sale('edit') && $v->get_sale_price('edit') !== '' ? (float) $v->get_sale_price('edit') : 0.0;
            $price = $sale ?: $regular;
            if ($price <= 0) {
                continue;
            }
            $incoming = function_exists('mandala_incoming_date') ? mandala_incoming_date($product) : '';
            $status = $v->is_in_stock() ? 'in_stock' : ($incoming ? 'preorder' : 'out_of_stock');
            $items[] = [
                'id' => $v->get_sku() ?: 'MND-' . $v->get_id(),
                'group' => $product->is_type('variable') ? ($product->get_sku() ?: 'MND-' . $id) : '',
                'title' => mb_substr($v->get_name(), 0, 150),
                'description' => mb_substr($desc ?: $v->get_name(), 0, 4900),
                'link' => $v->get_permalink(),
                'images' => $images ?: [mandala_art_url($product)],
                'price' => $regular ?: $price,
                'sale' => $sale && $sale < $regular ? $sale : 0,
                'status' => $status,
                'incoming' => $incoming,
                'category' => $cat_path,
                'origin' => $origin,
                'weight' => (float) $v->get_weight(),
                'shipping' => $price >= $free && $free > 0 ? 0 : $ship_price,
                'ean' => (method_exists($v, 'get_global_unique_id') ? (string) $v->get_global_unique_id('edit') : '') ?: (string) $v->get_meta('_ean'),
            ];
        }
    }
    return (array) apply_filters('mandala_feed_items', $items);
}

/* ---------- Formátumok ---------- */

function mandala_feed_xml(string $type, array $items): string
{
    $s = mandala_feed_settings();
    $x = fn($v) => htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $fmt = fn($n) => number_format((float) $n, 0, '.', '');
    if ($type === 'google') {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0"><channel>'
            . '<title>' . $x(get_bloginfo('name')) . '</title><link>' . $x(home_url('/')) . '</link><description>' . $x(get_bloginfo('description')) . '</description>' . "\n";
        foreach ($items as $i) {
            $out .= '<item><g:id>' . $x($i['id']) . '</g:id><title>' . $x($i['title']) . '</title><description>' . $x($i['description']) . '</description>'
                . '<link>' . $x($i['link']) . '</link><g:image_link>' . $x($i['images'][0]) . '</g:image_link>';
            foreach (array_slice($i['images'], 1, 10) as $img) {
                $out .= '<g:additional_image_link>' . $x($img) . '</g:additional_image_link>';
            }
            $out .= '<g:availability>' . $i['status'] . '</g:availability>'
                . ($i['status'] === 'preorder' && $i['incoming'] ? '<g:availability_date>' . $x(gmdate('Y-m-d\TH:i:sP', strtotime($i['incoming']))) . '</g:availability_date>' : '')
                . '<g:price>' . $fmt($i['price']) . ' HUF</g:price>' . ($i['sale'] ? '<g:sale_price>' . $fmt($i['sale']) . ' HUF</g:sale_price>' : '')
                . '<g:brand>' . $x($s['brand']) . '</g:brand><g:condition>new</g:condition>'
                . ($i['ean'] ? '<g:gtin>' . $x($i['ean']) . '</g:gtin>' : '<g:identifier_exists>no</g:identifier_exists>')
                . ($i['category'] ? '<g:product_type>' . $x($i['category']) . '</g:product_type>' : '')
                . ($i['group'] ? '<g:item_group_id>' . $x($i['group']) . '</g:item_group_id>' : '')
                . ($i['weight'] > 0 ? '<g:shipping_weight>' . $fmt($i['weight']) . ' g</g:shipping_weight>' : '')
                . '<g:shipping><g:country>HU</g:country><g:service>GLS</g:service><g:price>' . $fmt($i['shipping']) . ' HUF</g:price></g:shipping>'
                . "</item>\n";
        }
        return $out . '</channel></rss>';
    }
    if ($type === 'arukereso') {
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<products>\n";
        foreach ($items as $i) {
            $price = $i['sale'] ?: $i['price'];
            $out .= '<product><identifier>' . $x($i['id']) . '</identifier><manufacturer>' . $x($s['brand']) . '</manufacturer><name>' . $x($i['title']) . '</name>'
                . '<category>' . $x($i['category']) . '</category><product_url>' . $x($i['link']) . '</product_url>'
                . '<price>' . $fmt($price) . '</price><net_price>' . $fmt($price / 1.27) . '</net_price>'
                . '<image_url>' . $x($i['images'][0]) . '</image_url>' . (isset($i['images'][1]) ? '<image_url_2>' . $x($i['images'][1]) . '</image_url_2>' : '')
                . '<description>' . $x($i['description']) . '</description>'
                . '<delivery_time>' . $x($i['status'] === 'preorder' ? 'előrendelés' : $s['delivery_time']) . '</delivery_time><delivery_cost>' . $fmt($i['shipping']) . '</delivery_cost>'
                . ($i['ean'] ? '<ean_code>' . $x($i['ean']) . '</ean_code>' : '') . "</product>\n";
        }
        return $out . '</products>';
    }
    if ($type === 'olcsobbat') {
        // A régi bolt bővítményének (Árukereső for WooCommerce) Olcsóbbat-formátuma.
        $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<catalog>\n";
        foreach ($items as $i) {
            $price = $i['sale'] ?: $i['price'];
            $out .= '<product><id>' . $x($i['id']) . '</id><name>' . $x($i['title']) . '</name><manufacturer>' . $x($s['brand']) . '</manufacturer>'
                . ($i['ean'] ? '<barcodes><barcode type="ean-13">' . $x($i['ean']) . '</barcode></barcodes>' : '')
                . '<itemid>' . $x($i['id']) . '</itemid><category>' . $x(str_replace(' > ', '/', $i['category'])) . '</category>'
                . '<grossprice>' . $fmt($price) . '</grossprice><netprice>' . $fmt($price / 1.27) . '</netprice>'
                . '<urlsite>' . $x($i['link']) . '</urlsite><urlpicture>' . $x($i['images'][0]) . '</urlpicture>'
                . '<describe>' . $x(mb_substr($i['description'], 0, 1000)) . '</describe>'
                . '<deliveryprice>' . ($i['shipping'] > 0 ? $fmt($i['shipping']) : 'ingyenes') . '</deliveryprice>'
                . '<deliverytime>' . $x($i['status'] === 'preorder' ? 'előrendelés' : $s['delivery_time']) . "</deliverytime></product>\n";
        }
        return $out . '</catalog>';
    }
    // Árgép
    $out = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<termeklista>\n";
    foreach ($items as $i) {
        $out .= '<termek><cikkszam>' . $x($i['id']) . '</cikkszam><nev>' . $x($i['title']) . '</nev><leiras>' . $x(mb_substr($i['description'], 0, 1000)) . '</leiras>'
            . '<ar>' . $fmt($i['sale'] ?: $i['price']) . '</ar><fotolink>' . $x($i['images'][0]) . '</fotolink><termeklink>' . $x($i['link']) . '</termeklink>'
            . '<ido>' . $x($i['status'] === 'preorder' ? 'előrendelés' : $s['delivery_time']) . '</ido><szallitas>' . $fmt($i['shipping']) . '</szallitas>'
            . '<gyarto>' . $x($s['brand']) . '</gyarto><kategoria>' . $x($i['category']) . "</kategoria></termek>\n";
    }
    return $out . '</termeklista>';
}

/** Minden feed újragenerálása (óránként a háttérben, vagy az admin gombjával). */
function mandala_feeds_build(): array
{
    $dir = mandala_feed_dir();
    wp_mkdir_p($dir);
    if (!file_exists($dir . '/index.html')) {
        file_put_contents($dir . '/index.html', '');
    }
    $items = mandala_feed_items();
    $prev = (array) get_option('mandala_feeds_built', []);
    if (!$items && array_sum((array) ($prev['counts'] ?? [])) > 0) {
        // 2026-11-06: egy túl korán futó kérés (a WooCommerce még nem töltődött be) üres feedet írt minden jó helyére.
        // Üres termékjegyzékkel a meglévő, jó feedeket nem írjuk felül.
        return (array) $prev['counts'];
    }
    $skip = mandala_feed_settings()['skip_out'] === 'yes';
    $stats = [];
    foreach (MANDALA_FEEDS as $name => $feed) {
        $list = $feed['type'] !== 'google' && $skip ? array_values(array_filter($items, fn($i) => $i['status'] !== 'out_of_stock')) : $items; // Árukereső, Árgép, Olcsóbbat
        // Atomi csere: a letöltő (Árukereső, Google…) soha nem kap félig írt fájlt.
        $tmp = $dir . '/' . $name . '.xml.' . wp_generate_password(6, false);
        if (file_put_contents($tmp, mandala_feed_xml($feed['type'], $list)) !== false) {
            rename($tmp, $dir . '/' . $name . '.xml');
        }
        $stats[$name] = count($list);
    }
    update_option('mandala_feeds_built', ['time' => time(), 'counts' => $stats, 'sig' => mandala_feeds_sig()], false);
    return $stats;
}

/** A feedek forrásának „ujjlenyomata”: a termékindex változata + a feed beállítások. */
function mandala_feeds_sig(): string
{
    return md5(mandala_index_hash() . wp_json_encode(mandala_feed_settings()) . mandala_config('freeShippingFrom', 25000));
}

mandala_recurring('mandala_feeds_build', HOUR_IN_SECONDS, fn() => time() + 10 * MINUTE_IN_SECONDS);
// Óránként – de csak ha változott valami (ár, készlet, új termék), különben naponta egyszer.
add_action('mandala_feeds_build', function () {
    $built = (array) get_option('mandala_feeds_built', []);
    $empty = array_diff_key(MANDALA_FEEDS, array_filter((array) ($built['counts'] ?? [])));
    if (!$empty && ($built['sig'] ?? '') === mandala_feeds_sig() && ($built['time'] ?? 0) > time() - DAY_IN_SECONDS) {
        return;
    }
    mandala_feeds_build();
});

/** Egy feed kiküldése (ha még nincs fájl, vagy 3 óránál régebbi, most készül). */
function mandala_feed_send(string $name): void
{
    $file = mandala_feed_dir() . '/' . $name . '.xml';
    $count = (int) (((array) get_option('mandala_feeds_built', []))['counts'][$name] ?? 0);
    if (!is_readable($file) || filemtime($file) < time() - 3 * HOUR_IN_SECONDS || $count === 0) {
        mandala_feeds_build();
    }
    nocache_headers();
    status_header(200);
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    readfile($file);
    exit;
}

/** Kiszolgálás: ?mandala_feed=google. */
add_action('template_redirect', function () {
    $name = sanitize_key($_GET['mandala_feed'] ?? '');
    if ($name !== '' && isset(MANDALA_FEEDS[$name])) {
        mandala_feed_send($name);
    }
}, 0);

/* ---------- Régi címek (a régi bolt feed-bővítményei) ---------- */

/**
 * Melyik feed tartozik egy régi feed-címhez ('' = nem feed-cím). Csak a régi bővítmények útvonalaira illik
 * (és csak akkor fut, ha a fájl nincs meg – a meglévőt a webszerver adja): a /feed/<név> végpont, a ?feed=<név>,
 * és a feltöltések alatti .xml, ha a mappa vagy a fájl neve elárulja a szolgáltatót. Ha nem (Product Feed PRO
 * véletlen fájlnév), a letöltő böngésző-azonosítója dönt. Tesztelhető.
 */
function mandala_feed_legacy_name(string $path, string $query, string $ua): string
{
    $path = strtolower(rawurldecode($path));
    if (preg_match('#^/feed/(arukereso|argep|olcsobbat)/?$#', $path, $m)) {
        return $m[1];
    }
    parse_str($query, $q);
    if (isset($q['feed']) && in_array(strtolower((string) $q['feed']), ['arukereso', 'argep', 'olcsobbat'], true)) {
        return strtolower((string) $q['feed']);
    }
    if (!preg_match('#^/wp-content/uploads/(.+)\.(xml|csv|txt)$#', $path, $m) || str_starts_with($m[1], 'mandala-feeds/')) {
        return '';
    }
    // A fájlnév, aztán a mappák nevéből (CTX Feed: woo-feed/<szolgáltató>/xml/<név>.xml, pl. woo-feed/facebook/xml/fb1.xml).
    $hints = [
        '#aruk|heureka#' => 'arukereso',
        '#argep|arg-ep#' => 'argep',
        '#olcso#' => 'olcsobbat',
        '#pinterest|(^|[^a-z])pin([^a-z]|$)#' => 'pinterest',
        '#facebook|instagram|(^|[^a-z])(fb|meta|ig)([^a-z]|$)#' => 'meta',
        '#google|merchant|gmc|shopping#' => 'google',
    ];
    foreach (array_reverse(explode('/', $m[1])) as $part) {
        foreach ($hints as $re => $feed) {
            if (preg_match($re, $part)) {
                return $feed;
            }
        }
    }
    if (!preg_match('#^(woo-feed|ctx-feed|webappick[^/]*|woo-product-feed-pro|product-feeds?|wpwoof-feed|wpallexport|feeds?)/#', $m[1])) {
        return '';
    }
    // Beszédes név nélkül (pl. Product Feed PRO véletlen fájlneve): a letöltő azonosítója dönt.
    $ua = strtolower($ua);
    foreach (['aruk' => 'arukereso', 'heureka' => 'arukereso', 'argep' => 'argep', 'olcsobbat' => 'olcsobbat', 'facebook' => 'meta', 'pinterest' => 'pinterest'] as $k => $feed) {
        if (str_contains($ua, $k)) {
            return $feed;
        }
    }

    return 'google';
}

/** A régi-cím napló egy sora (óránként egyszer írunk címenként, legfeljebb 40 cím). */
function mandala_feed_legacy_log(string $option, string $key, string $feed): void
{
    $log = (array) get_option($option, []);
    $row = (array) ($log[$key] ?? []);
    if (($row['last'] ?? 0) >= time() - HOUR_IN_SECONDS) {
        return;
    }
    $log[$key] = ['feed' => $feed, 'hits' => (int) ($row['hits'] ?? 0) + 1, 'last' => time(), 'ua' => mb_substr(sanitize_text_field((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 160)];
    uasort($log, fn($a, $b) => ($b['last'] ?? 0) <=> ($a['last'] ?? 0));
    update_option($option, array_slice($log, 0, 40, true), false);
}

// wp_loaded: az init végén (a WooCommerce termék-taxonómiái az init-en jönnek létre – korábban a termékek nem látszanak).
add_action('wp_loaded', function () {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $path = (string) parse_url($uri, PHP_URL_PATH);
    $home = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($home !== '' && str_starts_with($path, $home)) {
        $path = substr($path, strlen($home)) ?: '/';
    }
    if (!preg_match('#^/(feed/|wp-content/uploads/)#', $path) && !str_contains((string) parse_url($uri, PHP_URL_QUERY), 'feed=')) {
        return;
    }
    $query = (string) parse_url($uri, PHP_URL_QUERY);
    $name = mandala_feed_legacy_name($path, $query, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $key = substr($path . (str_starts_with($path, '/feed/') || $query === '' ? '' : '?' . $query), 0, 300);
    if ($name === '' || !isset(MANDALA_FEEDS[$name])) {
        // Feltöltések alatti, hiányzó .xml / .csv: nem ismertük fel – naplózzuk, hogy kiderüljön, ki mit kér.
        if (preg_match('#^/wp-content/uploads/.+\.(xml|csv)$#i', $path)) {
            mandala_feed_legacy_log('mandala_feeds_unknown', $key, '');
        }
        return;
    }
    mandala_feed_legacy_log('mandala_feeds_legacy', $key, $name);
    mandala_feed_send($name);
}, 5);

/* ---------- Admin: WooCommerce → Mandala feedek ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala feedek', 'Mandala feedek', 'manage_woocommerce', 'mandala-feeds', 'mandala_feeds_admin');
});

function mandala_feeds_admin(): void
{
    if (!empty($_POST['mandala_feeds']) && check_admin_referer('mandala_feeds')) {
        $in = (array) wp_unslash($_POST['mandala_feeds']);
        update_option('mandala_feeds', [
            'brand' => sanitize_text_field($in['brand'] ?? '') ?: get_bloginfo('name'),
            'skip_out' => empty($in['skip_out']) ? 'no' : 'yes',
            'delivery_time' => sanitize_text_field($in['delivery_time'] ?? '') ?: '1-2 munkanap',
        ], false);
        $stats = mandala_feeds_build();
        echo '<div class="notice notice-success"><p>Mentve, a feedek frissítve (' . esc_html(implode(', ', array_map(fn($k, $v) => MANDALA_FEEDS[$k]['label'] . ': ' . $v, array_keys($stats), $stats))) . ').</p></div>';
    }
    $s = mandala_feed_settings();
    $built = (array) get_option('mandala_feeds_built', []);
    echo '<div class="wrap"><h1>Mandala feedek</h1><p>A termékek automatikusan, óránként frissülő listája az ár-összehasonlítóknak és a hirdetési katalógusoknak. A címet egyszer kell bemásolni a szolgáltató felületére; utána magától frissül.</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Hová</th><th>Feed címe</th><th>Termékek</th><th>Hol kell megadni</th></tr></thead><tbody>';
    foreach (MANDALA_FEEDS as $name => $feed) {
        $url = mandala_feed_url($name);
        echo '<tr><td><strong>' . esc_html($feed['label']) . '</strong></td><td><input type="text" readonly value="' . esc_attr($url) . '" class="large-text code" onclick="this.select()"> <a href="' . esc_url($url) . '" target="_blank" rel="noopener">megnyitás ↗</a></td><td>' . (int) ($built['counts'][$name] ?? 0) . '</td><td class="description">' . esc_html($feed['where']) . '</td></tr>';
    }
    echo '</tbody></table><p class="description">Utolsó frissítés: ' . (!empty($built['time']) ? esc_html(wp_date('Y. m. d. H:i', (int) $built['time'])) : 'még nem készült') . '. Kimarad: utalvány, eseményjegy, rejtett termék.</p>';
    echo '<h2>Beállítások</h2><form method="post">';
    wp_nonce_field('mandala_feeds');
    echo '<table class="form-table"><tr><th scope="row"><label for="mf-brand">Márka</label></th><td><input type="text" id="mf-brand" name="mandala_feeds[brand]" value="' . esc_attr($s['brand']) . '" class="regular-text"><p class="description">Kézműves termékeknél a bolt neve (a Google elfogadja).</p></td></tr>'
        . '<tr><th scope="row"><label for="mf-time">Szállítási idő</label></th><td><input type="text" id="mf-time" name="mandala_feeds[delivery_time]" value="' . esc_attr($s['delivery_time']) . '" class="regular-text"></td></tr>'
        . '<tr><th scope="row">Elfogyott termékek</th><td><label><input type="checkbox" name="mandala_feeds[skip_out]" value="1"' . checked($s['skip_out'], 'yes', false) . '> az Árukeresőből, az Árgépből és az Olcsóbbatból kimaradnak</label><p class="description">A Google és a Meta feedben „nincs raktáron” jelzéssel szerepelnek (így nem kell újra jóváhagyatni őket).</p></td></tr></table>';
    submit_button('Mentés és feedek frissítése');
    echo '</form>';
    $legacy = (array) get_option('mandala_feeds_legacy', []);
    echo '<h2>Régi címek</h2><p class="description">A régi bolt feed-bővítményeinek címei (pl. /feed/arukereso, /wp-content/uploads/arukereso-xml/arukereso.xml) ugyanígy működnek: a téma feedjét adják, az ár-összehasonlítóknál nem kell átírni. Itt látszik, melyiket ki kérte (óránként frissül).</p>';
    if ($legacy) {
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Régi cím</th><th>Feed</th><th>Utoljára</th><th>Letöltő</th></tr></thead><tbody>';
        foreach ($legacy as $path => $r) {
            echo '<tr><td><code>' . esc_html($path) . '</code></td><td>' . esc_html(MANDALA_FEEDS[$r['feed']]['label'] ?? $r['feed']) . '</td><td>' . esc_html(wp_date('Y. m. d. H:i', (int) $r['last'])) . '</td><td class="description">' . esc_html($r['ua']) . '</td></tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p><em>Még senki nem kérte a régi címeket.</em></p>';
    }
    $unknown = (array) get_option('mandala_feeds_unknown', []);
    if ($unknown) {
        echo '<h3>Nem felismert feed-kérések</h3><p class="description">Hiányzó .xml / .csv a feltöltések alatt, amiről nem derül ki, melyik szolgáltatóé. Ha ez egy ár-összehasonlító régi címe, szólj: felvesszük.</p><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Cím</th><th>Utoljára</th><th>Letöltő</th></tr></thead><tbody>';
        foreach ($unknown as $path => $r) {
            echo '<tr><td><code>' . esc_html($path) . '</code></td><td>' . esc_html(wp_date('Y. m. d. H:i', (int) $r['last'])) . '</td><td class="description">' . esc_html($r['ua']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    do_action('mandala_feeds_admin_after');
    echo '</div>';
}
