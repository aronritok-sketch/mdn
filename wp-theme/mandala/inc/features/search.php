<?php
/**
 * Kereső: a keresőmotor (assets/js/search-engine.js) beállításai, keresési napló és statisztika,
 * szerveroldali tartalék a találati oldalhoz.
 *
 *  - Szinonimák: WooCommerce → Mandala kereső → Szinonimák (az alapértelmezett lista a motorból jön);
 *  - Napló: csak a keresett kifejezés, a találatok száma és a kattintás – személyes adat nélkül;
 *    saját tábla ({prefix}mandala_search_log), hogy sok keresésnél is gyors és pontos legyen;
 *  - Statisztika: legtöbbet keresett, nulla találatos keresések (egy kattintással szinonima),
 *    kattintási arány; a „Népszerű keresések” a kereső legördülőjében ebből automatikusan;
 *  - Szerveroldali keresés (a találati oldal első megjelenése, JS nélkül is): ragozás, szinonimák,
 *    cikkszám-részlet; a böngészőben a teljes motor (elírás, értelmezés) veszi át.
 */

defined('ABSPATH') || exit;

const MANDALA_SEARCH_DB_VERSION = '1';

function mandala_search_settings(): array
{
    return wp_parse_args((array) get_option('mandala_search', []), [
        'synonyms' => '',        // üres: az alapértelmezett lista
        'popular' => '',         // üres: automatikusan a statisztikából
        'log' => 'yes',
    ]);
}

/** Az alapértelmezett szinonimák – egyetlen forrás: a motor DEFAULT_SYNONYMS konstansa. */
function mandala_default_synonyms(): string
{
    static $text = null;
    if ($text === null) {
        $src = (string) @file_get_contents(MANDALA_DIR . '/assets/js/search-engine.js');
        $text = preg_match('/DEFAULT_SYNONYMS = `(.*?)`;/s', $src, $m) ? $m[1] : '';
    }
    return $text;
}
function mandala_synonyms_text(): string
{
    $custom = trim((string) mandala_search_settings()['synonyms']);
    return $custom !== '' ? $custom : mandala_default_synonyms();
}

/* ---------- Napló tábla ---------- */

function mandala_search_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_search_log';
}
add_action('init', function () {
    if (get_option('mandala_search_db') === MANDALA_SEARCH_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta('CREATE TABLE ' . mandala_search_table() . " (
        term varchar(100) NOT NULL,
        searches int unsigned NOT NULL default 0,
        zero int unsigned NOT NULL default 0,
        clicks int unsigned NOT NULL default 0,
        last_results int unsigned NOT NULL default 0,
        first_seen datetime NOT NULL,
        last_seen datetime NOT NULL,
        PRIMARY KEY  (term),
        KEY last_seen (last_seen)
    ) " . $wpdb->get_charset_collate() . ';');
    update_option('mandala_search_db', MANDALA_SEARCH_DB_VERSION);
});

/** Kifejezés normalizálása a naplóhoz (kisbetű, egy szóköz, legfeljebb 100 karakter). */
function mandala_search_term(string $q): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', mb_strtolower(wp_strip_all_tags($q)))), 0, 100);
}

function mandala_search_log(string $q, int $results, int $click = 0): void
{
    global $wpdb;
    $term = mandala_search_term($q);
    if (mb_strlen($term) < 2) {
        return;
    }
    $now = current_time('mysql');
    $table = mandala_search_table();
    if ($click) {
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (term, searches, zero, clicks, last_results, first_seen, last_seen) VALUES (%s, 1, 0, 1, %d, %s, %s) ON DUPLICATE KEY UPDATE clicks = clicks + 1, last_seen = VALUES(last_seen)", $term, $results, $now, $now));
    } else {
        $zero = $results === 0 ? 1 : 0;
        $wpdb->query($wpdb->prepare("INSERT INTO {$table} (term, searches, zero, clicks, last_results, first_seen, last_seen) VALUES (%s, 1, %d, 0, %d, %s, %s) ON DUPLICATE KEY UPDATE searches = searches + 1, zero = zero + %d, last_results = VALUES(last_results), last_seen = VALUES(last_seen)", $term, $zero, $results, $now, $now, $zero));
    }
    wp_cache_delete('popular', 'mandala_search');
}

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/search-log', [
        'methods' => 'POST',
        'permission_callback' => '__return_true',
        'callback' => function (WP_REST_Request $request) {
            if (mandala_search_settings()['log'] !== 'yes') {
                return ['ok' => false];
            }
            // Visszaélés ellen: címenként óránként legfeljebb 300 bejegyzés.
            $key = 'mandala_sl_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            $count = (int) get_transient($key);
            if ($count > 300) {
                return new WP_REST_Response(['ok' => false], 429);
            }
            set_transient($key, $count + 1, HOUR_IN_SECONDS);
            $data = $request->get_json_params() ?: $request->get_params();
            mandala_search_log((string) ($data['q'] ?? ''), max(0, (int) ($data['n'] ?? 0)), max(0, (int) ($data['click'] ?? 0)));
            return ['ok' => true];
        },
    ]);
});

/** Népszerű keresések a legördülőhöz: kézi lista, vagy az utolsó 60 nap legtöbbet keresett, találatos kifejezései. */
function mandala_popular_searches(int $limit = 6): array
{
    $custom = array_filter(array_map('trim', explode(',', (string) mandala_search_settings()['popular'])));
    if ($custom) {
        return array_slice($custom, 0, $limit);
    }
    $cached = wp_cache_get('popular', 'mandala_search');
    if (!is_array($cached)) {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS);
        $cached = (array) $wpdb->get_col($wpdb->prepare('SELECT term FROM ' . mandala_search_table() . ' WHERE last_results > 0 AND searches >= 3 AND last_seen > %s ORDER BY searches DESC LIMIT %d', $since, $limit));
        wp_cache_set('popular', $cached, 'mandala_search', HOUR_IN_SECONDS);
    }
    return $cached;
}

add_filter('mandala_js_data', function ($data) {
    $custom = trim((string) mandala_search_settings()['synonyms']);
    $data['searchConfig'] = ['synonyms' => $custom !== '' ? $custom : null, 'popular' => mandala_popular_searches()];
    return $data;
});

/* ---------- Szerveroldali keresés (tartalék, egyszerűsített motor) ---------- */

function mandala_search_norm(string $s): string
{
    return trim(preg_replace('/[^a-z0-9#]+/', ' ', strtolower(remove_accents($s))));
}
function mandala_search_stem(string $w): string
{
    static $suffixes = ['jaikat', 'jeiket', 'akkal', 'ekkel', 'okkal', 'okban', 'ekben', 'akban', 'okbol', 'ekbol', 'akbol', 'oknak', 'eknek', 'aknak', 'okhoz', 'ekhez', 'akhoz', 'okrol', 'ekrol', 'akrol', 'okra', 'ekre', 'akra', 'okat', 'eket', 'akat', 'jait', 'jeit', 'kent', 'ait', 'eit', 'ban', 'ben', 'bol', 'rol', 'tol', 'hoz', 'hez', 'nak', 'nek', 'val', 'vel', 'ert', 'ba', 'be', 'ra', 're', 'ig', 'at', 'et', 'ot', 'ok', 'ek', 'ak', 'ai', 'ei'];
    if (strlen($w) < 5 || preg_match('/\d/', $w)) {
        return $w;
    }
    foreach ($suffixes as $s) {
        if (str_ends_with($w, $s) && strlen($w) - strlen($s) >= 3) {
            return substr($w, 0, -strlen($s));
        }
    }
    return $w;
}

/** Termékek (index sorok) relevancia szerint: ragozás, összetett szavak, szinonimák, cikkszám. */
function mandala_search_products(string $q): array
{
    $words = array_values(array_filter(explode(' ', mandala_search_norm($q))));
    if (!$words) {
        return [];
    }
    // Szinonimák (a motorral azonos szabályok): a kifejezés szavai alternatívát kapnak.
    $norm_q = ' ' . implode(' ', $words) . ' ';
    // Szavanként alternatívák: ['t' => tő, 'rid' => szinonima szabály] vagy ['abs' => szabály] (elnyelt szó).
    $alts = array_map(fn($w) => [['t' => mandala_search_stem($w)]], $words);
    foreach (explode("\n", mandala_synonyms_text()) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$from, $to] = str_contains($line, '=>') ? array_map(fn($x) => array_filter(array_map('mandala_search_norm', explode(',', $x))), explode('=>', $line, 2)) : [array_filter(array_map('mandala_search_norm', explode(',', $line))), null];
        foreach ($from as $f) {
            if (!str_contains($norm_q, ' ' . $f . ' ')) {
                continue;
            }
            $targets = $to ?? array_diff($from, [$f]);
            $first = array_search(explode(' ', $f)[0], $words, true);
            if ($first === false) {
                continue;
            }
            $rid = $f . '@' . $first;
            foreach ($targets as $t) {
                $alts[$first][] = ['t' => array_map('mandala_search_stem', explode(' ', $t)), 'rid' => $rid];
            }
            foreach (array_slice(explode(' ', $f), 1) as $k => $extra) {
                if (isset($alts[$first + $k + 1])) {
                    $alts[$first + $k + 1][] = ['abs' => $rid];
                }
            }
        }
    }
    $match = function (string $stem, array $tokens): float {
        $best = 0.0;
        foreach ($tokens as $t) {
            if ($t === $stem) {
                return 1.0;
            }
            if (strlen($stem) >= 2 && str_starts_with($t, $stem)) {
                $best = max($best, 0.85 * (0.8 + 0.2 * strlen($stem) / strlen($t)));
            } elseif (strlen($t) >= 4 && str_starts_with($stem, $t)) {
                $best = max($best, 0.7);
            } elseif (strlen($stem) >= 3 && str_contains($t, $stem)) {
                $best = max($best, 0.5);
            }
        }
        return $best;
    };
    $tok = fn(string $s) => array_map('mandala_search_stem', array_filter(explode(' ', mandala_search_norm($s))));
    $out = [];
    foreach (mandala_product_index() as $row) {
        $fields = [
            10 => $tok($row['name']),
            6 => $tok(($row['catLabel'] ?? '') . ' ' . mandala_term_name($row['cat'] ?? '')),
            4 => $tok(implode(' ', array_map(fn($v) => is_array($v) ? implode(' ', $v) : (string) $v, array_merge(array_values((array) ($row['specs'] ?? [])), [$row['originLabel'] ?? '', $row['region'] ?? ''])))),
            2 => $tok((string) ($row['short'] ?? '')),
        ];
        $sku = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($row['sku'] ?? '')));
        $score = 0.0;
        $hit = [];
        foreach ($words as $i => $w) {
            $best = 0.0;
            foreach ($alts[$i] as $alt) {
                if (isset($alt['abs'])) {
                    // a többszavas szinonima további szava: csak ha a termék a szinonimára illeszkedett
                    if (isset($hit[$alt['abs']])) {
                        $best = max($best, 0.001);
                    }
                    continue;
                }
                $stems = (array) $alt['t'];
                foreach ($fields as $weight => $tokens) {
                    $q = min(array_map(fn($t) => $match($t, $tokens), $stems));
                    if ($q > 0 && isset($alt['rid'])) {
                        $hit[$alt['rid']] = true;
                    }
                    $best = max($best, $q * $weight * (isset($alt['rid']) ? 0.9 : 1));
                }
            }
            $raw = preg_replace('/[^a-z0-9]/', '', $w);
            if (strlen($raw) >= 3 && $sku && str_contains($sku, $raw)) {
                $best = max($best, $sku === $raw ? 50 : 14);
            }
            if ($best <= 0) {
                continue 2; // minden szónak illeszkednie kell
            }
            $score += $best;
        }
        if (($row['stock'] ?? '') === 'out') {
            $score *= 0.7;
        }
        $out[] = [$row, $score];
    }
    usort($out, fn($a, $b) => $b[1] <=> $a[1]);
    $top = $out[0][1] ?? 0;
    if ($top >= 10) {
        $out = array_filter($out, fn($x) => $x[1] >= $top * 0.15);
    }
    return array_values(array_map(fn($x) => $x[0], $out));
}

/* ---------- Admin: WooCommerce → Mandala kereső ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala kereső', 'Mandala kereső', 'manage_woocommerce', 'mandala-search', 'mandala_search_admin_page');
});

function mandala_search_admin_page(): void
{
    global $wpdb;
    $table = mandala_search_table();
    $tab = sanitize_key($_GET['tab'] ?? 'stats'); // phpcs:ignore
    $base = admin_url('admin.php?page=mandala-search');
    $s = mandala_search_settings();

    if (!empty($_POST['mandala_search_do']) && check_admin_referer('mandala_search')) {
        $do = sanitize_key($_POST['mandala_search_do']);
        if ($do === 'synonyms') {
            $text = sanitize_textarea_field(wp_unslash($_POST['synonyms'] ?? ''));
            $s['synonyms'] = trim($text) === trim(mandala_default_synonyms()) ? '' : $text;
        } elseif ($do === 'reset_synonyms') {
            $s['synonyms'] = '';
        } elseif ($do === 'add_rule') {
            $from = sanitize_text_field(wp_unslash($_POST['from'] ?? ''));
            $to = sanitize_text_field(wp_unslash($_POST['to'] ?? ''));
            if ($from !== '' && $to !== '') {
                $s['synonyms'] = rtrim(mandala_synonyms_text()) . "\n" . $from . ' => ' . $to;
                $wpdb->update($table, ['zero' => 0], ['term' => mandala_search_term($from)]);
            }
        } elseif ($do === 'settings') {
            $s['popular'] = sanitize_text_field(wp_unslash($_POST['popular'] ?? ''));
            $s['log'] = empty($_POST['log']) ? 'no' : 'yes';
        } elseif ($do === 'clear') {
            $wpdb->query("DELETE FROM {$table}"); // phpcs:ignore
        }
        update_option('mandala_search', $s, false);
        wp_cache_delete('popular', 'mandala_search');
        echo '<div class="notice notice-success"><p>Mentve.</p></div>';
    }

    $tabs = ['stats' => 'Legtöbbet keresett', 'zero' => 'Nincs találat', 'synonyms' => 'Szinonimák', 'settings' => 'Beállítások'];
    echo '<div class="wrap"><h1>Mandala kereső</h1><nav class="nav-tab-wrapper">';
    foreach ($tabs as $key => $label) {
        echo '<a class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', $key, $base)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
    $nonce = fn() => wp_nonce_field('mandala_search', '_wpnonce', true, false);
    $try = fn($term) => '<a href="' . esc_url(add_query_arg('s', rawurlencode($term), home_url('/'))) . '" target="_blank">kipróbálás</a>';

    if ($tab === 'stats') {
        $totals = $wpdb->get_row("SELECT COUNT(*) AS terms, COALESCE(SUM(searches),0) AS searches, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(zero),0) AS zero FROM {$table}"); // phpcs:ignore
        echo '<p>' . esc_html(sprintf('%s keresés, %s különböző kifejezés, %s kattintás egy találatra, %s nulla találatos keresés.', number_format_i18n((int) $totals->searches), number_format_i18n((int) $totals->terms), number_format_i18n((int) $totals->clicks), number_format_i18n((int) $totals->zero))) . ' <span class="description">Csak a kifejezést és a találatok számát tároljuk, személyes adatot nem.</span></p>';
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY searches DESC, last_seen DESC LIMIT 100"); // phpcs:ignore
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Kifejezés</th><th class="num">Keresés</th><th class="num">Találat (legutóbb)</th><th class="num">Kattintási arány</th><th>Utoljára</th><th></th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="6">Még nincs adat – a keresések a kereső használatával gyűlnek.</td></tr>';
        }
        foreach ($rows as $r) {
            $ctr = $r->searches ? round($r->clicks / $r->searches * 100) : 0;
            echo '<tr><td><strong>' . esc_html($r->term) . '</strong></td><td class="num">' . (int) $r->searches . '</td><td class="num">' . ((int) $r->last_results ?: '<span style="color:#b32d2e">0</span>') . '</td><td class="num">' . (int) $ctr . '%</td><td>' . esc_html(mysql2date('Y. m. d.', $r->last_seen)) . '</td><td>' . $try($r->term) . '</td></tr>'; // phpcs:ignore
        }
        echo '</tbody></table><p class="description">Alacsony kattintási arány: a vásárló talált valamit, de nem azt, amit keresett – érdemes megnézni a találatokat, és szinonimát vagy jobb terméknevet adni.</p>';
    } elseif ($tab === 'zero') {
        $rows = $wpdb->get_results("SELECT * FROM {$table} WHERE last_results = 0 AND zero > 0 ORDER BY zero DESC, last_seen DESC LIMIT 100"); // phpcs:ignore
        echo '<p>Ezekre a keresésekre nem volt találat. Ha van hozzá illő termék vagy kategória, adj meg egy szinonimát: a kereső ezután azt is megtalálja (pl. „tibeti tál” → „hangtál”). Ha nincs ilyen termék, lehet, hogy érdemes beszerezni.</p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Kifejezés</th><th class="num">Hányszor</th><th>Utoljára</th><th>Ezt keresse helyette</th></tr></thead><tbody>';
        if (!$rows) {
            echo '<tr><td colspan="4">Nincs nulla találatos keresés. 🎉</td></tr>';
        }
        foreach ($rows as $r) {
            echo '<tr><td><strong>' . esc_html($r->term) . '</strong> ' . $try($r->term) . '</td><td class="num">' . (int) $r->zero . '</td><td>' . esc_html(mysql2date('Y. m. d.', $r->last_seen)) . '</td><td><form method="post" style="display:flex;gap:6px">' . $nonce() // phpcs:ignore
                . '<input type="hidden" name="mandala_search_do" value="add_rule"><input type="hidden" name="from" value="' . esc_attr($r->term) . '"><label class="screen-reader-text" for="to-' . esc_attr(md5($r->term)) . '">Szinonima</label><input type="text" id="to-' . esc_attr(md5($r->term)) . '" name="to" placeholder="pl. hangtál" required><button class="button">Mentés</button></form></td></tr>';
        }
        echo '</tbody></table>';
    } elseif ($tab === 'synonyms') {
        echo '<form method="post">' . $nonce() . '<input type="hidden" name="mandala_search_do" value="synonyms">'; // phpcs:ignore
        echo '<p>Soronként egy szabály. <strong>Egyenértékű szavak</strong> vesszővel: <code>hangtál, tibeti tál, singing bowl</code> – bármelyikre keresve a többit is megtalálja. <strong>Egyirányú</strong>: <code>hangfürdő =&gt; hangtál</code> – a „hangfürdő” keresés a hangtálakat is hozza, fordítva nem. A <code>#</code>-tel kezdődő sor megjegyzés. A ragozást, az ékezeteket és a kisebb elírásokat a kereső magától kezeli, ezekhez nem kell szabály.</p>';
        echo '<textarea name="synonyms" rows="24" class="large-text code">' . esc_textarea(mandala_synonyms_text()) . '</textarea>';
        submit_button('Szinonimák mentése');
        echo '</form><form method="post">' . $nonce() . '<input type="hidden" name="mandala_search_do" value="reset_synonyms"><button class="button" onclick="return confirm(\'Visszaállítod az alapértelmezett szinonimákat?\')">Alapértelmezett lista visszaállítása</button></form>'; // phpcs:ignore
    } else {
        echo '<form method="post">' . $nonce() . '<input type="hidden" name="mandala_search_do" value="settings"><table class="form-table">' // phpcs:ignore
            . '<tr><th scope="row"><label for="ms-popular">Népszerű keresések</label></th><td><input type="text" id="ms-popular" name="popular" class="large-text" value="' . esc_attr($s['popular']) . '" placeholder="' . esc_attr(implode(', ', mandala_popular_searches()) ?: 'hangtál, tibeti füstölő, mala') . '"><p class="description">A kereső megnyitásakor ajánlott kifejezések, vesszővel. Üresen: automatikusan az utolsó 60 nap leggyakoribb, találatos keresései.</p></td></tr>'
            . '<tr><th scope="row">Keresési napló</th><td><label><input type="checkbox" name="log" value="1"' . checked($s['log'], 'yes', false) . '> bekapcsolva</label><p class="description">Csak a kifejezés, a találatok száma és a kattintás – személyes adat nélkül.</p></td></tr>'
            . '</table>';
        submit_button('Mentés');
        echo '</form><form method="post">' . $nonce() . '<input type="hidden" name="mandala_search_do" value="clear"><button class="button" onclick="return confirm(\'Törlöd a teljes keresési naplót?\')">Napló törlése</button></form>'; // phpcs:ignore
    }
    echo '</div>';
}
