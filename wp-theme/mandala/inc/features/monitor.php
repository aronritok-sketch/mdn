<?php
/**
 * Őrszem – óránként ellenőrzi a boltot, és CSAK baj esetén ír levelet (ugyanarról 6 óránként legfeljebb
 * egyszer; ha rendbe jött, azt is megírja). Így nem a vásárlótól tudjátok meg, hogy valami leállt.
 *
 * Ellenőrzések (mandala_health_checks()):
 *  - háttérfeladatok: állnak-e (lejárt, de el nem indult feladat), hibás feladatok az elmúlt órában;
 *  - PHP végzetes hibák az elmúlt órában (a téma rögzíti, amikor előfordul);
 *  - rendelések: ha máskor ilyenkor 3 óra alatt átlag ≥ 2 rendelés jön, most pedig egy sem → gyanús;
 *  - sikertelen fizetések: sok 1 órán belül (kártyatesztelés vagy fizetési hiba);
 *  - levélküldés hibái (SMTP) az elmúlt órában;
 *  - feedek frissessége, a termékindex lemaradása, szabad lemezhely, SSL tanúsítvány lejárata;
 *  - a főoldal betöltése a szerverről (kétszer egymás után sikertelen → riasztás).
 * Külső figyelő (pl. UptimeRobot, ingyenes, 5 percenként): a Mandala levelek → Beállítások alatt látható
 * titkos cím 200-at ad, ha minden rendben, 503-at, ha nem – akkor is jelez, ha maga a WordPress áll le.
 * Címzett és be/ki: Mandala levelek → Beállítások. Állapot: Vezérlőpult → „Mandala őrszem” doboz.
 */

defined('ABSPATH') || exit;

function mandala_monitor_settings(): array
{
    return wp_parse_args((array) get_option('mandala_monitor', []), ['enabled' => 'yes', 'to' => '']);
}

/* ---------- Végzetes PHP hibák rögzítése (csak ha előfordul – normál kérésben költsége nincs) ---------- */

register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true) || !function_exists('update_option')) {
        return;
    }
    $list = array_slice((array) get_option('mandala_fatals', []), -19);
    $list[] = ['t' => time(), 'msg' => mb_substr((string) $e['message'], 0, 300), 'at' => basename((string) $e['file']) . ':' . (int) $e['line'], 'url' => mb_substr((string) ($_SERVER['REQUEST_URI'] ?? 'cli'), 0, 150)]; // phpcs:ignore
    update_option('mandala_fatals', $list, false);
});

/* ---------- Ellenőrzések ---------- */

/** Rendelések időpontjai (GMT unix) egy időpont óta – HPOS-szal és a régi tárolással is. */
function mandala_order_times(int $since): array
{
    global $wpdb;
    $skip = "'wc-failed','wc-cancelled','trash','auto-draft','wc-checkout-draft','draft'";
    $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $sql = $hpos
        ? $wpdb->prepare("SELECT date_created_gmt FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND status NOT IN ($skip) AND date_created_gmt > %s", gmdate('Y-m-d H:i:s', $since)) // phpcs:ignore
        : $wpdb->prepare("SELECT post_date_gmt FROM {$wpdb->posts} WHERE post_type = 'shop_order' AND post_status NOT IN ($skip) AND post_date_gmt > %s", gmdate('Y-m-d H:i:s', $since)); // phpcs:ignore
    return array_map(fn($d) => (int) strtotime($d . ' UTC'), $wpdb->get_col($sql)); // phpcs:ignore
}

/**
 * Az összes ellenőrzés: [kulcs => ['label', 'status' => ok|warn|fail, 'msg']].
 * $deep: a lassabb ellenőrzések is (oldalbetöltés, SSL) – az óránkénti futás és a varázsló kéri.
 */
function mandala_health_checks(bool $deep = true): array
{
    global $wpdb;
    $out = [];
    $add = function (string $key, string $label, string $status, string $msg) use (&$out) {
        $out[$key] = ['label' => $label, 'status' => $status, 'msg' => $msg];
    };
    $now = time();
    $as = $wpdb->prefix . 'actionscheduler_actions';
    $has_as = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $as));

    // Háttérfeladatok: a legrégebbi lejárt, de el nem indult feladat.
    if ($has_as) {
        $oldest = (string) $wpdb->get_var("SELECT MIN(scheduled_date_gmt) FROM {$as} WHERE status = 'pending' AND scheduled_date_gmt > '2000-01-01' AND scheduled_date_gmt < '" . gmdate('Y-m-d H:i:s', $now - 30 * MINUTE_IN_SECONDS) . "'"); // phpcs:ignore
        $late = $oldest ? (int) floor(($now - strtotime($oldest . ' UTC')) / 60) : 0;
        $add('cron', 'Háttérfeladatok', $late ? 'fail' : 'ok', $late ? sprintf('%d perce állnak (levelek, készlet a keresőben, feedek). Ellenőrizd a tárhely cronját (wp-cron.php percenként).', $late) : 'Rendben futnak.');
        // Csak a saját és a rendelést / fizetést / levelet / számlát érintő feladatok (a WooCommerce belső,
        // hálózatfüggő feladatai – pl. távoli értesítések – ne riasszanak).
        $groups = $wpdb->prefix . 'actionscheduler_groups';
        $failed = $wpdb->get_results($wpdb->prepare("SELECT a.hook, COUNT(*) AS n FROM {$as} a LEFT JOIN {$groups} g ON g.group_id = a.group_id WHERE a.status = 'failed' AND a.last_attempt_gmt > %s AND (g.slug = %s OR a.hook LIKE '%%mail%%' OR a.hook LIKE '%%order%%' OR a.hook LIKE '%%payment%%' OR a.hook LIKE '%%invoice%%' OR a.hook LIKE '%%szamla%%') AND a.hook NOT LIKE 'woocommerce_admin%%' GROUP BY a.hook ORDER BY n DESC LIMIT 3", gmdate('Y-m-d H:i:s', $now - HOUR_IN_SECONDS), MANDALA_AS_GROUP)); // phpcs:ignore
        $add('as_failed', 'Hibás háttérfeladatok', $failed ? 'warn' : 'ok', $failed ? 'Az elmúlt órában: ' . implode(', ', array_map(fn($r) => $r->hook . ' ×' . $r->n, $failed)) . ' (WooCommerce → Állapot → Ütemezett műveletek).' : 'Nincs.');
    }

    // Végzetes PHP hibák.
    $fatals = array_filter((array) get_option('mandala_fatals', []), fn($f) => ($f['t'] ?? 0) > $now - HOUR_IN_SECONDS);
    $last = end($fatals);
    $add('fatal', 'PHP hibák', $fatals ? 'fail' : 'ok', $fatals ? sprintf('%d végzetes hiba az elmúlt órában, utolsó: %s (%s, %s)', count($fatals), $last['msg'], $last['at'], $last['url']) : 'Nincs.');

    // Rendelések: most 3 óra alatt vs. az elmúlt 28 nap ugyanezen idősávja.
    if (function_exists('wc_get_orders')) {
        $times = mandala_order_times($now - 28 * DAY_IN_SECONDS - 3 * HOUR_IN_SECONDS);
        $recent = count(array_filter($times, fn($t) => $t > $now - 3 * HOUR_IN_SECONDS));
        $same = 0;
        foreach ($times as $t) {
            $ago = ($now - $t) % DAY_IN_SECONDS;
            if ($now - $t > DAY_IN_SECONDS && $ago < 3 * HOUR_IN_SECONDS) {
                $same++;
            }
        }
        $expected = $same / 28;
        $bad = $recent === 0 && $expected >= 2;
        $add('orders', 'Rendelések', $bad ? 'fail' : 'ok', $bad ? sprintf('3 órája egy rendelés sem jött, pedig ilyenkor átlag %.1f szokott. Próbáld ki a pénztárt (kártya és utánvét).', $expected) : sprintf('Az elmúlt 3 órában %d (ilyenkor átlag %.1f).', $recent, $expected));

        $failed_pay = count(wc_get_orders(['status' => 'failed', 'date_modified' => '>' . ($now - HOUR_IN_SECONDS), 'limit' => 50, 'return' => 'ids', 'type' => 'shop_order']));
        $blocked = (int) (get_transient('mandala_fraud_blocked_hour') ?: 0);
        $add('payments', 'Fizetések', $failed_pay >= 5 || $blocked >= 20 ? 'fail' : 'ok', $failed_pay >= 5 || $blocked >= 20 ? sprintf('%d sikertelen fizetés és %d blokkolt próbálkozás 1 órán belül – kártyatesztelő támadás vagy fizetési hiba lehet (Teya felület).', $failed_pay, $blocked) : sprintf('Sikertelen: %d, blokkolt: %d az elmúlt órában.', $failed_pay, $blocked));
    }

    // Levélküldés.
    if (function_exists('mandala_mail_table') && get_option('mandala_mail_db')) {
        $mail_failed = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . mandala_mail_table() . " WHERE status = 'failed' AND created > %s", gmdate('Y-m-d H:i:s', $now - HOUR_IN_SECONDS))); // phpcs:ignore
        $add('mail', 'Levélküldés', $mail_failed ? 'fail' : 'ok', $mail_failed ? sprintf('%d levél nem ment ki az elmúlt órában (rendelés-visszaigazolás is lehet köztük). Ellenőrizd a WP Mail SMTP beállítást.', $mail_failed) : 'Rendben.');
    }

    // Feedek, termékindex.
    $feeds = (array) get_option('mandala_feeds_built', []);
    if (!empty($feeds['time'])) {
        $h = (int) floor(($now - (int) $feeds['time']) / HOUR_IN_SECONDS);
        $add('feeds', 'Termékfeedek', $h > 26 ? 'warn' : 'ok', $h > 26 ? sprintf('%d órája nem frissültek (Árukereső, Google…).', $h) : sprintf('%d órája frissültek.', $h));
    }
    $dirty = (string) $wpdb->get_var("SELECT MIN(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_index_dirty'"); // phpcs:ignore
    $lag = $dirty !== '' ? (int) floor(($now - (int) ((int) $dirty / 1000)) / 60) : 0;
    $add('index', 'Kereső / szűrő adatai', $lag > 30 ? 'warn' : 'ok', $lag > 30 ? sprintf('%d perce várnak frissítésre (készlet, ár a keresőben).', $lag) : 'Frissek.');

    // Lemezhely.
    $up = wp_upload_dir(null, false);
    $free = function_exists('disk_free_space') ? @disk_free_space($up['basedir']) : false; // phpcs:ignore
    if ($free !== false) {
        $gb = $free / 1073741824;
        $add('disk', 'Szabad tárhely', $gb < 0.2 ? 'fail' : ($gb < 1 ? 'warn' : 'ok'), sprintf('%.1f GB szabad.', $gb));
    }

    if ($deep) {
        // A főoldal a szerverről (két egymás utáni hiba kell a riasztáshoz – egy kiesés lehet pillanatnyi).
        $t = microtime(true);
        $r = wp_remote_get(home_url('/'), ['timeout' => 20, 'redirection' => 3, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache'], 'user-agent' => 'Mandala-Orszem']);
        $ms = (int) round((microtime(true) - $t) * 1000);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $ok = $code === 200;
        $streak = $ok ? 0 : (int) get_option('mandala_monitor_http_streak', 0) + 1;
        update_option('mandala_monitor_http_streak', $streak, false);
        $add('home', 'Főoldal', $ok ? ($ms > 5000 ? 'warn' : 'ok') : ($streak >= 2 ? 'fail' : 'ok'), $ok ? sprintf('Betölt (%d ms).', $ms) : sprintf('Nem tölt be: %s (%d. alkalommal).', is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . $code, $streak));

        // SSL tanúsítvány lejárata.
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        if (is_ssl() || str_starts_with(home_url(), 'https://')) {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $sock = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx); // phpcs:ignore
            $cert = $sock ? (stream_context_get_params($sock)['options']['ssl']['peer_certificate'] ?? null) : null;
            $info = $cert ? openssl_x509_parse($cert) : null;
            if ($info && !empty($info['validTo_time_t'])) {
                $days = (int) floor(($info['validTo_time_t'] - $now) / DAY_IN_SECONDS);
                $add('ssl', 'SSL tanúsítvány', $days < 7 ? 'fail' : ($days < 14 ? 'warn' : 'ok'), sprintf('%d nap múlva jár le.', $days));
            }
        }
    }
    return apply_filters('mandala_health_checks', $out, $deep);
}

/**
 * Élesítés utáni önellenőrzés (a telepítő varázsló gombja): az őrszem ellenőrzései + oldalbetöltések,
 * oldal-gyorsítótár, tömörítés, HTTPS átirányítás, oldaltérkép, feed. [kulcs => label, status, msg].
 */
function mandala_selfcheck(): array
{
    $out = mandala_health_checks(true);
    $get = function (string $url, array $args = []) {
        $t = microtime(true);
        $r = wp_remote_get($url, $args + ['timeout' => 25, 'redirection' => 0, 'sslverify' => false, 'user-agent' => 'Mandala-Onellenorzes']);
        return [$r, (int) round((microtime(true) - $t) * 1000)];
    };
    $add = function (string $key, string $label, string $status, string $msg) use (&$out) {
        $out[$key] = ['label' => $label, 'status' => $status, 'msg' => $msg];
    };

    // Oldalak: várt HTTP kód és idő.
    $product = function_exists('wc_get_products') ? (wc_get_products(['status' => 'publish', 'limit' => 1, 'return' => 'ids', 'orderby' => 'rand']) ?: [0])[0] : 0;
    $cat = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true, 'number' => 1, 'orderby' => 'count', 'order' => 'DESC']);
    $pages = array_filter([
        'Főoldal' => [home_url('/'), [200]],
        'Kínálat' => function_exists('wc_get_page_permalink') ? [wc_get_page_permalink('shop'), [200]] : null,
        'Kategória' => $cat && !is_wp_error($cat) ? [get_term_link($cat[0]), [200]] : null,
        'Termékoldal' => $product ? [get_permalink($product), [200, 301]] : null,
        'Kosár' => function_exists('wc_get_cart_url') ? [wc_get_cart_url(), [200]] : null,
        'Pénztár' => function_exists('wc_get_checkout_url') ? [wc_get_checkout_url(), [200, 302]] : null,
        'Keresés' => [add_query_arg(['s' => 'hangtal', 'post_type' => 'product'], home_url('/')), [200]],
        'Nem létező oldal' => [home_url('/mandala-onellenorzes-' . wp_generate_password(6, false) . '/'), [404]],
    ]);
    $slow = [];
    $bad = [];
    foreach ($pages as $label => [$url, $codes]) {
        [$r, $ms] = $get((string) $url);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        if (!in_array($code, $codes, true)) {
            $bad[] = $label . ': ' . (is_wp_error($r) ? $r->get_error_message() : 'HTTP ' . $code);
        } elseif ($ms > 3000) {
            $slow[] = $label . ' ' . round($ms / 1000, 1) . ' mp';
        }
    }
    $add('pages', 'Oldalak betöltése', $bad ? 'fail' : ($slow ? 'warn' : 'ok'), $bad ? implode('; ', $bad) : ($slow ? 'Lassú: ' . implode(', ', $slow) . ' (oldal-gyorsítótár?)' : count($pages) . ' oldal rendben betölt.'));

    // Oldal-gyorsítótár: a második kérés már a tárból jön-e.
    $get(home_url('/'));
    [$r] = $get(home_url('/'));
    $h = is_wp_error($r) ? [] : array_change_key_case((array) wp_remote_retrieve_headers($r)->getAll());
    $body = is_wp_error($r) ? '' : (string) wp_remote_retrieve_body($r);
    $hit = preg_match('/hit/i', (string) ($h['x-litespeed-cache'] ?? $h['x-cache'] ?? $h['cf-cache-status'] ?? $h['x-proxy-cache'] ?? $h['x-cache-status'] ?? '')) || preg_match('/(Cached page generated|WP Super Cache|Performance optimized by W3 Total Cache|This website is like a Rocket)/i', $body);
    $plugin = function_exists('mandala_cache_plugin') ? mandala_cache_plugin() : '';
    $add('page_cache', 'Oldal-gyorsítótár', $hit ? 'ok' : 'warn', $hit ? 'Működik – a vendégek oldalai gyorsítótárból mennek.' : ($plugin ? $plugin . ' be van kapcsolva, de a főoldal nem gyorsítótárból jött (a második lekérésre sem) – ellenőrizd a beállítását.' : 'Nincs oldal-gyorsítótár. Nagy forgalomhoz kell: LiteSpeed Cache (LiteSpeed tárhelyen) vagy WP Super Cache.'));

    // Tömörítés: HTML és a kereső termékindexe (JSON).
    $gz = [];
    $index = function_exists('mandala_products_url') ? mandala_products_url() : '';
    foreach (array_filter(['főoldal' => home_url('/'), 'termékindex (JSON)' => $index]) as $label => $url) {
        [$r] = $get($url, ['headers' => ['Accept-Encoding' => 'gzip, br'], 'decompress' => false]);
        $enc = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'content-encoding');
        if (!preg_match('/gzip|br|deflate|zstd/i', $enc)) {
            $gz[] = $label;
        }
    }
    $add('compress', 'Tömörítés (gzip)', $gz ? 'warn' : 'ok', $gz ? 'Tömörítés nélkül megy: ' . implode(', ', $gz) . '. A tárhelyen kapcsold be a gzip / brotli tömörítést (az application/json típusra is).' : 'Bekapcsolva.');

    // HTTPS: a http:// cím átirányít-e.
    if (str_starts_with(home_url(), 'https://')) {
        [$r] = $get(set_url_scheme(home_url('/'), 'http'));
        $loc = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'location');
        $ok = !is_wp_error($r) && in_array((int) wp_remote_retrieve_response_code($r), [301, 302, 307, 308], true) && str_starts_with($loc, 'https://');
        $add('https', 'HTTPS átirányítás', $ok ? 'ok' : 'warn', $ok ? 'A http:// cím a https://-re visz.' : 'A http:// cím nem irányít át a https://-re (tárhely beállítás).');
    } else {
        $add('https', 'HTTPS', 'fail', 'A bolt címe nem https:// – kártyás fizetéshez és a Google-höz kötelező.');
    }

    // Oldaltérkép és robots.txt.
    $sitemap = defined('WPSEO_VERSION') ? home_url('/sitemap_index.xml') : home_url('/wp-sitemap.xml');
    [$r] = $get($sitemap, ['redirection' => 2]);
    $sm_ok = !is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200 && str_contains((string) wp_remote_retrieve_body($r), '<sitemap');
    [$r] = $get(home_url('/robots.txt'));
    $rb = is_wp_error($r) ? '' : (string) wp_remote_retrieve_body($r);
    $public = get_option('blog_public') !== '0';
    $add('seo', 'Keresőmotorok', $sm_ok && $public && !preg_match('#^Disallow:\s*/\s*$#m', $rb) ? 'ok' : 'fail',
        !$public ? 'A keresőmotorok indexelése ki van kapcsolva (Beállítások → Olvasás).' : (!$sm_ok ? 'Az oldaltérkép nem érhető el: ' . $sitemap : (preg_match('#^Disallow:\s*/\s*$#m', $rb) ? 'A robots.txt mindent tilt.' : 'Oldaltérkép és robots.txt rendben.')));

    // Google feed: érvényes XML-e.
    if (function_exists('mandala_feed_url')) {
        [$r] = $get(mandala_feed_url('google'), ['redirection' => 2]);
        libxml_use_internal_errors(true);
        $xml = is_wp_error($r) ? false : simplexml_load_string((string) wp_remote_retrieve_body($r));
        $items = $xml ? count($xml->xpath('//item')) : 0;
        $add('feed_xml', 'Google feed', $items ? 'ok' : 'warn', $items ? $items . ' termék, érvényes XML.' : 'A feed üres vagy nem érvényes XML (WooCommerce → Mandala feedek).');
    }

    // Levélküldés: SMTP bővítmény.
    $smtp = mandala_plugin_active_any(['wp-mail-smtp/wp_mail_smtp.php', 'wp-mail-smtp-pro/wp_mail_smtp.php', 'fluent-smtp/fluent-smtp.php', 'post-smtp/postman-smtp.php']);
    $add('smtp', 'Levélküldés (SMTP)', $smtp ? 'ok' : 'warn', $smtp ? 'SMTP bővítmény bekapcsolva.' : 'Nincs SMTP bővítmény – a rendelési levelek spambe kerülhetnek. WP Mail SMTP ajánlott.');
    return $out;
}
function mandala_plugin_active_any(array $files): bool
{
    $active = (array) get_option('active_plugins', []);
    return (bool) array_intersect($files, $active);
}

/* ---------- Óránkénti futás és riasztás ---------- */

mandala_recurring('mandala_monitor', HOUR_IN_SECONDS, fn() => time() + 5 * MINUTE_IN_SECONDS);
add_action('mandala_monitor', 'mandala_monitor_run');

/** Visszaad: a kiküldött riasztás kulcsai (üres, ha nem ment levél). */
function mandala_monitor_run(): array
{
    $checks = mandala_health_checks(true);
    $state = (array) get_option('mandala_monitor_state', []);
    $alerted = (array) ($state['alerted'] ?? []);
    $now = time();
    $new = [];
    $resolved = [];
    foreach ($checks as $key => $c) {
        if ($c['status'] === 'ok') {
            if (isset($alerted[$key])) {
                $resolved[$key] = $c;
                unset($alerted[$key]);
            }
            continue;
        }
        if (($alerted[$key] ?? 0) < $now - 6 * HOUR_IN_SECONDS) {
            $new[$key] = $c;
            $alerted[$key] = $now;
        }
    }
    update_option('mandala_monitor_state', ['at' => $now, 'checks' => $checks, 'alerted' => $alerted], false);
    $s = mandala_monitor_settings();
    if ($s['enabled'] !== 'yes' || (!$new && !$resolved)) {
        return [];
    }
    $to = $s['to'] ?: (string) (mandala_config('contact', [])['email'] ?? get_option('admin_email'));
    $row = fn($c, $color) => '<tr><td style="padding:6px 12px 6px 0;vertical-align:top"><strong style="color:' . $color . '">' . esc_html($c['label']) . '</strong></td><td style="padding:6px 0">' . esc_html($c['msg']) . '</td></tr>';
    $body = '';
    if ($new) {
        $body .= '<p>Az óránkénti ellenőrzés hibát talált:</p><table role="presentation" style="border-collapse:collapse">' . implode('', array_map(fn($c) => $row($c, $c['status'] === 'fail' ? '#B42318' : '#B54708'), $new)) . '</table>';
    }
    if ($resolved) {
        $body .= '<p style="margin-top:20px">Rendbe jött:</p><table role="presentation" style="border-collapse:collapse">' . implode('', array_map(fn($c) => $row($c, '#067647'), $resolved)) . '</table>';
    }
    $body .= '<p style="margin-top:20px;color:#6E6357;font-size:13px">Ugyanerről legfeljebb 6 óránként jön újra levél. Állapot: WordPress Vezérlőpult → Mandala őrszem.</p>';
    $subject = $new ? sprintf('[%s] Figyelem: %s', get_bloginfo('name'), implode(', ', array_map(fn($c) => $c['label'], $new))) : sprintf('[%s] Rendbe jött: %s', get_bloginfo('name'), implode(', ', array_map(fn($c) => $c['label'], $resolved)));
    mandala_send_mail($to, $subject, $new ? 'Őrszem: hiba' : 'Őrszem: rendben', $body, false, ['type' => 'belso']);
    return array_keys($new + $resolved);
}

/* ---------- Külső figyelő: titkos állapot-cím ---------- */

function mandala_health_url(): string
{
    return add_query_arg('mandala_health', substr(hash_hmac('sha256', 'health', wp_salt('auth')), 0, 20), home_url('/'));
}
add_action('init', function () {
    $key = sanitize_key($_GET['mandala_health'] ?? ''); // phpcs:ignore
    if ($key === '') {
        return;
    }
    if (!hash_equals(substr(hash_hmac('sha256', 'health', wp_salt('auth')), 0, 20), $key)) {
        status_header(404);
        exit;
    }
    mandala_nocache('health');
    // Gyors ellenőrzések (oldalbetöltés nélkül) + az óránkénti futás maga is fut-e.
    $checks = mandala_health_checks(false);
    $state = (array) get_option('mandala_monitor_state', []);
    if (!empty($state['at']) && $state['at'] < time() - 3 * HOUR_IN_SECONDS) {
        $checks['monitor'] = ['label' => 'Őrszem', 'status' => 'fail', 'msg' => 'Az óránkénti ellenőrzés 3 órája nem futott (cron).'];
    }
    $bad = array_filter($checks, fn($c) => $c['status'] === 'fail');
    status_header($bad ? 503 : 200);
    header('Content-Type: application/json; charset=utf-8');
    echo wp_json_encode(['status' => $bad ? 'hiba' : 'ok', 'problems' => array_values(array_map(fn($c) => $c['label'] . ': ' . $c['msg'], $bad))], JSON_UNESCAPED_UNICODE);
    exit;
}, 1);

/* ---------- Vezérlőpult doboz ---------- */

add_action('wp_dashboard_setup', function () {
    if (!current_user_can('manage_woocommerce')) {
        return;
    }
    wp_add_dashboard_widget('mandala_monitor', 'Mandala őrszem', function () {
        $state = (array) get_option('mandala_monitor_state', []);
        if (isset($_GET['mandala_monitor_now']) && check_admin_referer('mandala_monitor_now')) { // phpcs:ignore
            mandala_monitor_run();
            $state = (array) get_option('mandala_monitor_state', []);
        }
        $icons = ['ok' => '<span style="color:#067647">✓</span>', 'warn' => '<span style="color:#B54708">!</span>', 'fail' => '<span style="color:#B42318">✗</span>'];
        if (empty($state['checks'])) {
            echo '<p>Még nem futott (óránként fut).</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ($state['checks'] as $c) {
                echo '<tr><td style="width:1.5em">' . $icons[$c['status']] . '</td><td><strong>' . esc_html($c['label']) . '</strong><br><span class="description">' . esc_html($c['msg']) . '</span></td></tr>'; // phpcs:ignore
            }
            echo '</tbody></table><p class="description">Utolsó ellenőrzés: ' . esc_html(wp_date('Y.m.d. H:i', (int) $state['at'])) . '</p>';
        }
        echo '<p><a class="button" href="' . esc_url(wp_nonce_url(add_query_arg('mandala_monitor_now', 1, admin_url('index.php')), 'mandala_monitor_now')) . '">Ellenőrzés most</a></p>';
    });
});

/* ---------- Beállítás a levélközpontban ---------- */

add_action('mandala_mail_settings_fields', function () {
    $s = mandala_monitor_settings();
    echo '<tr><th scope="row">Őrszem (riasztás)</th><td><label><input type="checkbox" name="mandala_monitor[enabled]" value="1"' . checked($s['enabled'], 'yes', false) . '> óránkénti ellenőrzés, levél csak ha baj van</label> '
        . '<input type="email" name="mandala_monitor[to]" value="' . esc_attr($s['to']) . '" placeholder="' . esc_attr((string) (mandala_config('contact', [])['email'] ?? '')) . '" class="regular-text" aria-label="Címzett">'
        . '<p class="description">Külső figyelőhöz (pl. UptimeRobot, ingyenes, 5 percenként – akkor is jelez, ha a WordPress áll le): <code>' . esc_html(mandala_health_url()) . '</code></p></td></tr>';
});
add_action('mandala_mail_settings_save', function () {
    $in = (array) wp_unslash($_POST['mandala_monitor'] ?? []);
    update_option('mandala_monitor', ['enabled' => empty($in['enabled']) ? 'no' : 'yes', 'to' => sanitize_email($in['to'] ?? '')], false);
});
