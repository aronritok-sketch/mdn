<?php
/**
 * Átirányítások (301) – a régi címek ne vesszenek el (Google-helyezés, megosztott linkek), kézi munka nélkül.
 *
 *  1. Kézi lista (Eszközök → Átirányítások): régi útvonal → új cím; tömeges betöltés „régi;új” sorokkal.
 *  2. Automatikus: ha egy cím nem létezik (404), az utolsó szakasza (slug) alapján: termék → kategória →
 *     címke → cikk / oldal → régi WooCommerce oldalnevek (shop, cart…) → ugyanazzal kezdődő egyetlen
 *     termék. Ha egy termékcímre semmi sem illik, a keresés (302) a régi név szavaival.
 *  3. Kategória törlése vagy átnevezése előtt a régi cím magától bekerül a listába (törléskor oda mutat,
 *     ahová a termékei többsége került; különben a szülőre).
 *  4. 404 napló: ami így sem lett meg (útvonal, találatszám, honnan jött) – egy kattintással célt kap.
 *     A robotok zaját (.php, wp-login, .env, képek…) nem naplózza, és a napló magától karcsúsodik.
 */

defined('ABSPATH') || exit;

const MANDALA_REDIRECTS_OPTION = 'mandala_redirects';
const MANDALA_404_DB_VERSION = '1';

function mandala_404_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_404';
}
add_action('init', function () {
    if (get_option('mandala_404_db') === MANDALA_404_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mandala_404_table() . " (
        path varchar(191) NOT NULL,
        hits int unsigned NOT NULL default 0,
        referer varchar(255) NOT NULL default '',
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        PRIMARY KEY  (path),
        KEY last_seen (last_seen)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('mandala_404_db', MANDALA_404_DB_VERSION);
});

/** Egységes kulcs: domain és lekérdezés nélkül, dekódolva, kisbetűvel, perjelek nélkül a széleken. */
function mandala_redirect_key(string $url): string
{
    $path = (string) wp_parse_url($url, PHP_URL_PATH);
    $home = trim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
    $path = trim(mb_strtolower(rawurldecode($path)), '/');
    if ($home !== '' && str_starts_with($path, $home . '/')) {
        $path = substr($path, strlen($home) + 1);
    }
    return $path;
}

/** A kézi lista: kulcs => ['to' => cím, 'type' => manual|term|auto, 'added' => idő]. */
function mandala_redirects(): array
{
    $list = get_option(MANDALA_REDIRECTS_OPTION, []);
    return is_array($list) ? $list : [];
}

function mandala_redirect_add(string $from, string $to, string $type = 'manual'): bool
{
    $key = mandala_redirect_key($from);
    $to = trim($to);
    if ($key === '' || $to === '') {
        return false;
    }
    if (!preg_match('#^https?://#i', $to)) {
        $to = home_url('/' . ltrim($to, '/'));
    }
    // Önmagára mutató / körbe vezető átirányítás nem kerülhet be.
    if (mandala_redirect_key($to) === $key && wp_parse_url($to, PHP_URL_HOST) === wp_parse_url(home_url(), PHP_URL_HOST)) {
        return false;
    }
    $list = mandala_redirects();
    $to_key = mandala_redirect_key($to);
    // Az új cél él: ha korábban őt irányítottuk el, az a bejegyzés elavult (pl. visszanevezett kategória).
    unset($list[$to_key]);
    // Láncok összevonása: ami eddig a most elirányított címre mutatott, az egyből az új célra mutasson.
    foreach ($list as $from => $r) {
        if (mandala_redirect_key($r['to']) === $key) {
            $list[$from]['to'] = esc_url_raw($to);
        }
    }
    $list[$key] = ['to' => esc_url_raw($to), 'type' => $type, 'added' => time()];
    update_option(MANDALA_REDIRECTS_OPTION, $list, false);
    global $wpdb;
    $wpdb->delete(mandala_404_table(), ['path' => $key]);
    return true;
}

function mandala_redirect_remove(string $from): void
{
    $list = mandala_redirects();
    unset($list[mandala_redirect_key($from)]);
    update_option(MANDALA_REDIRECTS_OPTION, $list, false);
}

/** Robotzaj: ezeket nem keressük és nem naplózzuk. */
function mandala_404_is_noise(string $key): bool
{
    return $key === ''
        || (bool) preg_match('#(^|/)(wp-admin|wp-includes|wp-content|wp-login|xmlrpc|\.well-known|cgi-bin|vendor|node_modules|\.git)(/|$|\.)#', $key)
        || (bool) preg_match('#\.(php\d?|env|ini|log|sql|bak|old|zip|tar|gz|rar|7z|xml|json|txt|js|css|map|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|eot|mp[34]|pdf|asp|aspx|jsp|cgi|yml|yaml|conf|config|lock)$#', $key);
}

/**
 * Hová vigyük a nem létező címet: [cél, http kód] vagy null.
 * Sorrend: kézi lista → slug alapján termék / kategória / címke / cikk / oldal → régi WooCommerce oldalnevek
 * → egyetlen, ugyanazzal a névvel kezdődő termék → (termékcímnél) keresés a régi név szavaival.
 */
function mandala_redirect_resolve(string $url): ?array
{
    $key = mandala_redirect_key($url);
    $list = mandala_redirects();
    if (isset($list[$key])) {
        return [$list[$key]['to'], 301];
    }
    if (mandala_404_is_noise($key)) {
        return null;
    }
    $segments = array_values(array_filter(explode('/', $key), 'strlen'));
    $last = (string) end($segments);
    $slug = sanitize_title(preg_replace('#\.(html?|php)$#', '', $last));
    if ($slug === '') {
        return null;
    }
    // Kategória rövid címe (/kategoria/x/ a /kategoria/szulo/x/ helyett): a lista utolsó szakasza szerint.
    foreach ($list as $from => $r) {
        if ($r['type'] === 'term' && basename($from) === $slug && dirname($from) !== '.' && explode('/', $from)[0] === ($segments[0] ?? '')) {
            return [$r['to'], 301];
        }
    }
    $found = mandala_redirect_by_slug($slug);
    if (!$found && preg_match('#^(.+?)-\d+$#', $slug, $m)) {
        $found = mandala_redirect_by_slug($m[1]); // régi „-2” végű másolatok
    }
    if (!$found) {
        $pages = apply_filters('mandala_redirect_legacy_pages', [
            'shop' => 'shop', 'bolt' => 'shop', 'webshop' => 'shop', 'aruhaz' => 'shop', 'termekek' => 'shop', 'products' => 'shop',
            'cart' => 'cart', 'kosar' => 'cart', 'checkout' => 'checkout', 'penztar' => 'checkout', 'fizetes' => 'checkout',
            'my-account' => 'myaccount', 'fiokom' => 'myaccount', 'fiok' => 'myaccount', 'account' => 'myaccount',
        ]);
        if (count($segments) === 1 && isset($pages[$slug]) && function_exists('wc_get_page_permalink')) {
            $found = wc_get_page_permalink($pages[$slug]);
        }
    }
    if (!$found && count($segments) === 1) {
        // A régi bolt jogi és tájékoztató oldalainak címei → az itteni megfelelő oldal (ha közzé van téve)
        $info = apply_filters('mandala_redirect_legacy_info', [
            'altalanos-szerzodesi-feltetelek' => 'woocommerce_terms_page_id', 'aszf' => 'woocommerce_terms_page_id', 'terms' => 'woocommerce_terms_page_id',
            'panaszkezeles' => 'woocommerce_terms_page_id',
            'adatvedelmi-tajekoztato' => 'wp_page_for_privacy_policy', 'adatkezelesi-tajekoztato' => 'wp_page_for_privacy_policy', 'privacy-policy' => 'wp_page_for_privacy_policy',
            'adatvedelem' => 'wp_page_for_privacy_policy', 'cookie-suti-szabalyzat' => 'wp_page_for_privacy_policy', 'cookie-szabalyzat' => 'wp_page_for_privacy_policy',
            'contact-us' => 'mandala_page_kapcsolat', 'elerhetosegeink' => 'mandala_page_kapcsolat', 'elerhetoseg' => 'mandala_page_kapcsolat', 'contact' => 'mandala_page_kapcsolat',
            'shipping-information' => 'mandala_page_informaciok', 'szallitas' => 'mandala_page_informaciok', 'szallitasi-informaciok' => 'mandala_page_informaciok', 'faq' => 'mandala_page_informaciok', 'gyik' => 'mandala_page_informaciok',
            'hangtal-bemutato-idopontfoglalas' => 'mandala_page_hangtal-valaszto', 'rolunk' => 'mandala_page_rolunk', 'about-us' => 'mandala_page_rolunk',
            'blog' => 'page_for_posts', 'hirek' => 'page_for_posts',
        ]);
        $pid = isset($info[$slug]) ? (int) get_option($info[$slug]) : 0;
        if ($pid && get_post_status($pid) === 'publish') {
            $found = get_permalink($pid);
        }
    }
    if (!$found && count($segments) >= 2 && in_array($segments[0], ['product-category', 'kategoria', 'termekkategoria'], true) && taxonomy_exists('product_cat')) {
        // Összevont / átnevezett kategória (pl. „kapucnis-felsok” → „kapucnis-puloverek”): a régi név első szava
        // szerint, ha pontosan egy mostani kategória kezdődik vele; különben a régi cím legközelebbi létező szülője.
        global $wpdb;
        $first = explode('-', $slug)[0];
        if (strlen($first) >= 5 && !get_term_by('slug', $slug, 'product_cat') && !get_term_by('slug', preg_replace('/-(ruhazat-es-kiegeszitok|szakralis-targyak|lakberendezes|ajandektargyak)(-\\d+)?$/', '', $slug), 'product_cat')) {
            $ids = $wpdb->get_col($wpdb->prepare("SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id AND tt.taxonomy = 'product_cat' WHERE t.slug LIKE %s LIMIT 2", $wpdb->esc_like($first) . '%'));
            if (count($ids) === 1 && !is_wp_error($link = get_term_link((int) $ids[0], 'product_cat'))) {
                $found = $link;
            }
        }
    }
    if (!$found && count($segments) >= 2 && in_array($segments[0], ['product-category', 'kategoria', 'termekkategoria'], true) && taxonomy_exists('product_cat')) {
        // Régi kategóriacím: a régi bolt a szülő nevét a slug végére tette (karkotok-ruhazat-es-kiegeszitok) –
        // előbb ennek levágásával, aztán a legközelebbi létező szülőkategóriára
        for ($i = count($segments) - 1; $i >= 1 && !$found; $i--) {
            $try = sanitize_title($segments[$i]);
            $cands = [$try];
            if ($i > 1) {
                $cands[] = preg_replace('/-' . preg_quote(sanitize_title($segments[$i - 1]), '/') . '(-\d+)?$/', '', $try);
            }
            $cands[] = preg_replace('/-(ruhazat-es-kiegeszitok|szakralis-targyak|lakberendezes|ajandektargyak)(-\d+)?$/', '', $try);
            foreach (array_unique($cands) as $c) {
                $term = get_term_by('slug', $c, 'product_cat');
                if ($term && !is_wp_error($link = get_term_link($term))) {
                    $found = $link;
                    break;
                }
            }
        }
    }
    if (!$found && strlen($slug) >= 8) {
        // Egyetlen termék, amelynek a címe ezzel kezdődik (pl. rövidült vagy bővült slug).
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_name LIKE %s LIMIT 2", $wpdb->esc_like($slug) . '%'));
        if (count($ids) === 1) {
            $found = get_permalink((int) $ids[0]);
        }
    }
    if ($found) {
        return [$found, 301];
    }
    // Termékre utaló régi cím: a keresés a régi név szavaival (ideiglenes, 302) – jobb, mint egy üres 404.
    $product_bases = apply_filters('mandala_redirect_product_bases', ['termek', 'product', 'products', 'termekek', 'shop', 'aruhaz', 'product-category', 'kategoria', 'termekkategoria']);
    if (count($segments) >= 2 && array_intersect($segments, $product_bases)) {
        $words = trim(preg_replace('/\b\d+\b/', '', str_replace('-', ' ', $slug)));
        if (mb_strlen($words) >= 4) {
            return [add_query_arg(['s' => $words, 'post_type' => 'product'], home_url('/')), 302];
        }
    }
    return null;
}

function mandala_redirect_by_slug(string $slug): string
{
    foreach ([['product'], ['post', 'page']] as $types) {
        $ids = get_posts(['name' => $slug, 'post_type' => $types, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => false]);
        if ($ids) {
            return (string) get_permalink((int) $ids[0]);
        }
        if ($types === ['product']) {
            foreach (['product_cat', 'product_tag'] as $tax) {
                $term = get_term_by('slug', $slug, $tax);
                if ($term instanceof WP_Term) {
                    $link = get_term_link($term);
                    return is_wp_error($link) ? '' : (string) $link;
                }
            }
        }
    }
    return '';
}

add_action('template_redirect', function () {
    if (!is_404() || is_admin()) {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? ''); // phpcs:ignore
    $hit = mandala_redirect_resolve($uri);
    if ($hit) {
        [$to, $code] = $hit;
        // A régi cím lekérdezési paraméterei (pl. utm_) mennek tovább.
        $query = (string) wp_parse_url($uri, PHP_URL_QUERY);
        if ($query !== '' && $code === 301) {
            parse_str($query, $args);
            $to = add_query_arg(array_map('rawurlencode', $args), $to);
        }
        if (mandala_redirect_key($to) !== mandala_redirect_key($uri)) {
            wp_redirect($to, $code, 'Mandala');
            exit;
        }
    }
    mandala_404_log($uri);
}, 1);

function mandala_404_log(string $uri): void
{
    $key = mandala_redirect_key($uri);
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''); // phpcs:ignore
    if (mandala_404_is_noise($key) || $ua === '' || mb_strlen($key) > 190) {
        return;
    }
    global $wpdb;
    $ref = substr(esc_url_raw(wp_get_raw_referer() ?: ''), 0, 250);
    $now = current_time('mysql', true);
    $wpdb->query($wpdb->prepare('INSERT INTO ' . mandala_404_table() . ' (path, hits, referer, first_seen, last_seen) VALUES (%s, 1, %s, %s, %s) ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referer = IF(VALUES(referer) = \'\', referer, VALUES(referer))', $key, $ref, $now, $now)); // phpcs:ignore
}

// Karbantartás: 90 napnál régebbi és egyszeri tételek törlése, legfeljebb 2000 sor.
mandala_recurring('mandala_404_cleanup', DAY_IN_SECONDS, fn() => time() + 2 * HOUR_IN_SECONDS);
add_action('mandala_404_cleanup', function () {
    global $wpdb;
    $t = mandala_404_table();
    $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE last_seen < %s OR (hits = 1 AND last_seen < %s)", gmdate('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS))); // phpcs:ignore
    $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}"); // phpcs:ignore
    if ($n > 2000) {
        $cut = (int) $wpdb->get_var("SELECT hits FROM {$t} ORDER BY hits DESC LIMIT 1 OFFSET 1999"); // phpcs:ignore
        $wpdb->query($wpdb->prepare("DELETE FROM {$t} WHERE hits < %d", max(1, $cut))); // phpcs:ignore
    }
});

/* ---------- Kategória törlése / átnevezése: a régi cím magától átirányít ---------- */

add_action('pre_delete_term', function ($term_id, $taxonomy) {
    if ($taxonomy !== 'product_cat') {
        return;
    }
    $term = get_term((int) $term_id, 'product_cat');
    $link = $term instanceof WP_Term ? get_term_link($term) : '';
    if (!$term instanceof WP_Term || is_wp_error($link)) {
        return;
    }
    // Ahová a termékei többsége került (a törlendő kategórián kívül).
    $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => 300, 'fields' => 'ids', 'tax_query' => [['taxonomy' => 'product_cat', 'terms' => (int) $term_id]]]);
    $votes = [];
    foreach ($ids as $pid) {
        foreach (wp_get_post_terms($pid, 'product_cat', ['fields' => 'ids']) as $other) {
            if ((int) $other !== (int) $term_id && (int) $other !== (int) get_option('default_product_cat')) {
                $votes[(int) $other] = ($votes[(int) $other] ?? 0) + 1;
            }
        }
    }
    arsort($votes);
    $target = $votes ? get_term_link((int) array_key_first($votes), 'product_cat') : ($term->parent ? get_term_link((int) $term->parent, 'product_cat') : (function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/')));
    if (!is_wp_error($target)) {
        mandala_redirect_add((string) $link, (string) $target, 'term');
    }
}, 10, 2);

add_action('edit_terms', function ($term_id, $taxonomy) {
    if (in_array($taxonomy, ['product_cat', 'product_tag'], true)) {
        $link = get_term_link((int) $term_id, $taxonomy);
        $GLOBALS['mandala_term_old_link'][(int) $term_id] = is_wp_error($link) ? '' : (string) $link;
    }
}, 10, 2);
add_action('edited_term', function ($term_id, $tt_id, $taxonomy) {
    $old = $GLOBALS['mandala_term_old_link'][(int) $term_id] ?? '';
    if ($old === '') {
        return;
    }
    $new = get_term_link((int) $term_id, $taxonomy);
    if (!is_wp_error($new) && mandala_redirect_key((string) $new) !== mandala_redirect_key($old)) {
        mandala_redirect_add($old, (string) $new, 'term');
    }
}, 10, 3);

/* ---------- Admin: Eszközök → Átirányítások ---------- */

add_action('admin_menu', function () {
    add_management_page('Átirányítások', 'Átirányítások', 'manage_woocommerce', 'mandala-redirects', 'mandala_redirects_admin');
});

function mandala_redirects_admin(): void
{
    global $wpdb;
    $notice = '';
    if (!empty($_POST['mandala_redirects_do']) && check_admin_referer('mandala_redirects')) {
        $do = sanitize_key($_POST['mandala_redirects_do']);
        if ($do === 'bulk') {
            $n = 0;
            foreach (preg_split('/\R/', (string) wp_unslash($_POST['bulk'] ?? '')) as $line) {
                $cols = array_map('trim', str_getcsv(trim($line), str_contains($line, ';') ? ';' : ','));
                if (count($cols) >= 2 && $cols[0] !== '' && !str_starts_with($cols[0], '#')) {
                    $n += (int) mandala_redirect_add(sanitize_text_field($cols[0]), sanitize_text_field($cols[1]));
                }
            }
            $notice = sprintf('%d átirányítás mentve.', $n);
        } elseif ($do === 'target') {
            $n = 0;
            foreach ((array) ($_POST['target'] ?? []) as $path => $to) {
                $to = sanitize_text_field(wp_unslash($to));
                if ($to !== '') {
                    $n += (int) mandala_redirect_add(sanitize_text_field(wp_unslash($path)), $to);
                }
            }
            $notice = sprintf('%d átirányítás mentve.', $n);
        } elseif ($do === 'remove') {
            mandala_redirect_remove(sanitize_text_field(wp_unslash($_POST['path'] ?? '')));
            $notice = 'Átirányítás törölve.';
        } elseif ($do === 'dismiss') {
            $wpdb->delete(mandala_404_table(), ['path' => sanitize_text_field(wp_unslash($_POST['path'] ?? ''))]);
            $notice = 'Kivéve a naplóból.';
        }
    }
    $list = mandala_redirects();
    $rows = $wpdb->get_results('SELECT * FROM ' . mandala_404_table() . ' ORDER BY hits DESC, last_seen DESC LIMIT 100'); // phpcs:ignore
    echo '<div class="wrap"><h1>Átirányítások</h1>';
    if ($notice) {
        echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
    }
    echo '<p style="max-width:760px">A régi címek magától az új helyükre visznek (termék, kategória, cikk név alapján). Ide csak az kerül, amit a rendszer nem talált meg – a leggyakoribbakhoz érdemes célt adni (pl. <code>/kategoria/fustolok/</code> vagy teljes cím).</p>';
    echo '<h2>Nem található címek (404)</h2>';
    if (!$rows) {
        echo '<p>Nincs – minden régi cím célba ér.</p>';
    } else {
        echo '<form method="post">';
        wp_nonce_field('mandala_redirects');
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Régi cím</th><th>Találat</th><th>Utoljára</th><th>Honnan</th><th>Új cím</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr><td><code>/' . esc_html($r->path) . '</code></td><td>' . (int) $r->hits . '</td><td>' . esc_html(get_date_from_gmt($r->last_seen, 'Y.m.d. H:i')) . '</td><td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' . esc_html((string) $r->referer) . '</td>'
                . '<td><input type="text" name="target[' . esc_attr($r->path) . ']" placeholder="/kategoria/…" style="width:100%"></td>'
                . '<td><button class="button-link" name="mandala_redirects_do" value="dismiss" onclick="this.form.path.value=' . esc_attr(wp_json_encode($r->path)) . '">Elrejt</button></td></tr>';
        }
        echo '</tbody></table><input type="hidden" name="path" value=""><p><button class="button button-primary" name="mandala_redirects_do" value="target">Célok mentése</button></p></form>';
    }
    echo '<h2>Átirányítások (' . count($list) . ')</h2><form method="post">';
    wp_nonce_field('mandala_redirects');
    echo '<p><label for="mr-bulk">Tömeges betöltés – soronként <code>régi cím;új cím</code> (pl. a régi bolt linklistájából):</label><br><textarea id="mr-bulk" name="bulk" rows="4" class="large-text code" placeholder="/regi-kategoria/fustolo/;/kategoria/fustolok/"></textarea></p>'
        . '<p><button class="button" name="mandala_redirects_do" value="bulk">Betöltés</button></p></form>';
    if ($list) {
        $types = ['manual' => 'kézi', 'term' => 'kategória változás', 'auto' => 'automatikus'];
        echo '<form method="post">';
        wp_nonce_field('mandala_redirects');
        echo '<input type="hidden" name="path" value=""><table class="widefat striped" style="max-width:1100px"><thead><tr><th>Régi cím</th><th>Új cím</th><th>Forrás</th><th></th></tr></thead><tbody>';
        foreach (array_slice(array_reverse($list, true), 0, 300, true) as $from => $r) {
            echo '<tr><td><code>/' . esc_html($from) . '</code></td><td><a href="' . esc_url($r['to']) . '" target="_blank">' . esc_html(mandala_redirect_key($r['to']) ?: '/') . '</a></td><td>' . esc_html($types[$r['type']] ?? $r['type']) . '</td>'
                . '<td><button class="button-link button-link-delete" name="mandala_redirects_do" value="remove" onclick="this.form.path.value=' . esc_attr(wp_json_encode($from)) . '">Törlés</button></td></tr>';
        }
        echo '</tbody></table></form>';
    }
    echo '</div>';
}
