<?php
/**
 * Plugin Name: Mandala telepítő varázsló
 * Description: Lépésről lépésre végigvisz a Mandala webáruház beüzemelésén: környezet, bővítmények, téma, kulcsok, bolt adatai, telepítő, termékimport, szűrők (Claude), AI SEO, feedek, szállítás és fizetés, levelek, jogi oldalak, gyorsítás, próbák, élesítés. Minden lépés magától felismeri, kész-e.
 * Version: 1.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: Mandala
 * Text Domain: mandala-telepito
 * License: Proprietary
 */

defined('ABSPATH') || exit;

const MANDALA_WIZ_VERSION = '1.0.0';
const MANDALA_WIZ_OPTION = 'mandala_wizard';

/* ---------- Aktiváláskor a varázslóra visz ---------- */

register_activation_hook(__FILE__, fn() => set_transient('mandala_wizard_redirect', 1, 60));
add_action('admin_init', function () {
    if (get_transient('mandala_wizard_redirect') && current_user_can('manage_options') && !wp_doing_ajax() && empty($_GET['activate-multi'])) {
        delete_transient('mandala_wizard_redirect');
        wp_safe_redirect(admin_url('admin.php?page=mandala-varazslo'));
        exit;
    }
});

add_action('admin_menu', function () {
    add_menu_page('Mandala varázsló', 'Mandala varázsló', 'manage_options', 'mandala-varazslo', 'mandala_wiz_page', 'dashicons-yes-alt', 2);
});

function mandala_wiz_state(): array
{
    return wp_parse_args((array) get_option(MANDALA_WIZ_OPTION, []), ['checks' => [], 'notes' => []]);
}
function mandala_wiz_check(string $key): bool
{
    return !empty(mandala_wiz_state()['checks'][$key]);
}
function mandala_wiz_theme(): bool
{
    return get_stylesheet() === 'mandala' && function_exists('mandala_config');
}
function mandala_wiz_bundled_theme(): string
{
    return __DIR__ . '/theme/mandala-tema.zip';
}

/* ---------- Bővítmények ---------- */

/** [slug => [név, fájl, miért, kötelező, wp.org-ról telepíthető, keresőszó]] */
function mandala_wiz_plugins(): array
{
    return [
        'woocommerce' => ['WooCommerce', 'woocommerce/woocommerce.php', 'A webáruház alapja.', true, true, ''],
        'woocommerce-wholesale-prices' => ['WooCommerce Wholesale Prices', 'woocommerce-wholesale-prices/woocommerce-wholesale-prices.bootstrap.php', 'Viszonteladói szerep és nagyker ár.', true, true, ''],
        'integration-for-szamlazzhu-woocommerce' => ['Számlázz.hu integráció', '', 'Automatikus számla (NAV online számla).', true, true, 'Számlázz.hu'],
        'gls' => ['GLS szállítás', '', 'Futár, CsomagPont, automata, pontválasztó térkép. A GLS saját bővítménye.', true, false, 'GLS'],
        'teya' => ['Teya kártyás fizetés', '', 'Bankkártya, Apple Pay, Google Pay. A Teya-tól kapott bővítmény.', true, false, 'Teya'],
        'wp-mail-smtp' => ['WP Mail SMTP', 'wp-mail-smtp/wp_mail_smtp.php', 'Megbízható levélküldés (ne a spambe menjenek a levelek).', true, true, ''],
        'wordpress-seo' => ['Yoast SEO', 'wordpress-seo/wp-seo.php', 'Keresőoptimalizálás, oldaltérkép.', false, true, ''],
        'litespeed-cache' => ['LiteSpeed Cache', 'litespeed-cache/litespeed-cache.php', 'Oldal-gyorsítótár (LiteSpeed tárhelyen; más tárhelyen a tárhely ajánlott gyorsítótára).', false, true, ''],
    ];
}
function mandala_wiz_plugin_active(string $slug, array $p): bool
{
    if (function_exists('mandala_plugin_status')) {
        $map = ['integration-for-szamlazzhu-woocommerce' => 'szamlazz', 'gls' => 'gls', 'teya' => 'teya', 'woocommerce-wholesale-prices' => 'wholesale'];
        if (isset($map[$slug])) {
            return (bool) (mandala_plugin_status()[$map[$slug]]['active'] ?? false);
        }
    }
    if ($slug === 'wp-mail-smtp') {
        return mandala_wiz_smtp() !== '';
    }
    if ($slug === 'litespeed-cache' && function_exists('mandala_cache_plugin')) {
        return mandala_cache_plugin() !== '';
    }
    return $p[1] !== '' && is_plugin_active($p[1]);
}
function mandala_wiz_smtp(): string
{
    foreach (['wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP', 'fluent-smtp/fluent-smtp.php' => 'FluentSMTP', 'post-smtp/postman-smtp.php' => 'Post SMTP', 'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP', 'mailpoet/mailpoet.php' => 'MailPoet'] as $f => $n) {
        if (is_plugin_active($f)) {
            return $n;
        }
    }
    return '';
}

/* ---------- Lépések ---------- */

/**
 * Lépés: [csoport, cím, kötelező?, állapot (callable: [state, összefoglaló]), tartalom (callable)].
 * state: ok | todo | warn | info
 */
function mandala_wiz_steps(): array
{
    return [
        'start' => ['Alapok', 'Kezdés és mentés', true, fn() => mandala_wiz_check('backup') ? ['ok', 'Mentés visszaigazolva'] : ['todo', 'Először készíts mentést'], 'mandala_wiz_step_start'],
        'env' => ['Alapok', 'Környezet', true, 'mandala_wiz_status_env', 'mandala_wiz_step_env'],
        'plugins' => ['Alapok', 'Bővítmények', true, 'mandala_wiz_status_plugins', 'mandala_wiz_step_plugins'],
        'theme' => ['Alapok', 'Téma', true, 'mandala_wiz_status_theme', 'mandala_wiz_step_theme'],
        'keys' => ['Alapok', 'Kulcsok és levélküldés', true, 'mandala_wiz_status_keys', 'mandala_wiz_step_keys'],
        'store' => ['Bolt', 'Bolt adatai', true, 'mandala_wiz_status_store', 'mandala_wiz_step_store'],
        'setup' => ['Bolt', 'Mandala telepítő', true, 'mandala_wiz_status_setup', 'mandala_wiz_step_setup'],
        'products' => ['Bolt', 'Termékek importja', true, 'mandala_wiz_status_products', 'mandala_wiz_step_products'],
        'filters' => ['Bolt', 'Kategóriák és szűrők (Claude)', true, 'mandala_wiz_status_filters', 'mandala_wiz_step_filters'],
        'shipping' => ['Bolt', 'Szállítás, fizetés, számla', true, 'mandala_wiz_status_shipping', 'mandala_wiz_step_shipping'],
        'mails' => ['Bolt', 'Levelek és MailerLite', true, 'mandala_wiz_status_mails', 'mandala_wiz_step_mails'],
        'growth' => ['Önjáró rendszer', 'AI SEO, gyűjtőoldalak, ajánló, feedek', true, 'mandala_wiz_status_growth', 'mandala_wiz_step_growth'],
        'legal' => ['Élesítés', 'Jogi oldalak', true, 'mandala_wiz_status_legal', 'mandala_wiz_step_legal'],
        'speed' => ['Élesítés', 'Gyorsítás', false, 'mandala_wiz_status_speed', 'mandala_wiz_step_speed'],
        'tests' => ['Élesítés', 'Próbarendelések', true, 'mandala_wiz_status_tests', 'mandala_wiz_step_tests'],
        'live' => ['Élesítés', 'Élesítés', true, 'mandala_wiz_status_live', 'mandala_wiz_step_live'],
    ];
}

function mandala_wiz_status(string $key): array
{
    $step = mandala_wiz_steps()[$key];
    try {
        return (array) call_user_func($step[3]);
    } catch (Throwable $e) {
        return ['warn', 'Nem ellenőrizhető: ' . $e->getMessage()];
    }
}

/* ---------- Állapotok ---------- */

function mandala_wiz_env(): array
{
    global $wp_version;
    $mem = wp_convert_hr_to_bytes((string) (defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : ini_get('memory_limit')));
    $late = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['status' => 'pending', 'date' => gmdate('Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS), 'date_compare' => '<=', 'per_page' => 50], 'ids')) : 0;
    return [
        'php' => [version_compare(PHP_VERSION, '8.1', '>='), 'PHP ' . PHP_VERSION, 'Legalább 8.1 kell (a tárhely vezérlőpultján állítható).'],
        'wp' => [version_compare($wp_version, '6.5', '>='), 'WordPress ' . $wp_version, 'Frissítsd a WordPresst.'],
        'mem' => [$mem >= 256 * MB_IN_BYTES || $mem <= 0, 'Memória: ' . size_format($mem), 'wp-config.php: define(\'WP_MEMORY_LIMIT\', \'256M\'); (a csomagban lévő kiegészítésben benne van)'],
        'https' => [str_starts_with(home_url(), 'https://'), 'HTTPS: ' . (str_starts_with(home_url(), 'https://') ? 'igen' : 'nem'), 'A tárhelyen kapcsold be az SSL-t, és a Beállítások → Általános alatt a címeket írd https-re.'],
        'lang' => [get_locale() === 'hu_HU', 'Nyelv: ' . get_locale(), 'Magyar nyelv és fordítások – egy kattintás lent.'],
        'tz' => [wp_timezone_string() === 'Europe/Budapest', 'Időzóna: ' . wp_timezone_string(), 'Europe/Budapest – egy kattintás lent.'],
        'perma' => [(string) get_option('permalink_structure') !== '', 'Permalinkek: ' . ((string) get_option('permalink_structure') ?: 'alapértelmezett (?p=)'), 'Beállítások → Közvetlen hivatkozások → „Bejegyzés neve”.'],
        'cron' => [$late < 20, 'Háttérfeladatok: ' . ($late < 20 ? 'rendben futnak' : $late . ' késésben'), 'Valódi cron ajánlott (oldal-gyorsítótárral különösen): DISABLE_WP_CRON a wp-config-ba, és a tárhelyen percenként: wget -q -O - ' . site_url('wp-cron.php?doing_wp_cron') . ' >/dev/null'],
        'webp' => [function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/webp']), 'WebP képek: ' . (wp_image_editor_supports(['mime_type' => 'image/webp']) ? 'támogatott' : 'nem támogatott'), 'Nem kötelező; a tárhelytől kérhető Imagick / GD WebP támogatás.'],
    ];
}
function mandala_wiz_status_env(): array
{
    $bad = array_filter(mandala_wiz_env(), fn($c, $k) => !$c[0] && $k !== 'webp', ARRAY_FILTER_USE_BOTH);
    return $bad ? ['todo', count($bad) . ' teendő'] : ['ok', 'Minden rendben'];
}
function mandala_wiz_status_plugins(): array
{
    $missing = [];
    foreach (mandala_wiz_plugins() as $slug => $p) {
        if ($p[3] && !mandala_wiz_plugin_active($slug, $p)) {
            $missing[] = $p[0];
        }
    }
    return $missing ? ['todo', 'Hiányzik: ' . implode(', ', $missing)] : ['ok', 'A kötelezők aktívak'];
}
function mandala_wiz_status_theme(): array
{
    if (!wp_get_theme('iu_theme')->exists()) {
        return ['todo', 'Hiányzik az iu_theme szülőtéma'];
    }
    return mandala_wiz_theme() ? ['ok', 'A Mandala téma aktív'] : ['todo', 'A Mandala téma nincs bekapcsolva'];
}
function mandala_wiz_status_keys(): array
{
    if (!mandala_wiz_theme()) {
        return ['todo', 'Előbb a téma'];
    }
    $todo = [];
    if (!mandala_ai_ready()) {
        $todo[] = 'Anthropic';
    }
    if (function_exists('mandala_ml_ready') && !mandala_ml_ready()) {
        $todo[] = 'MailerLite';
    }
    if (!mandala_wiz_check('mail_ok')) {
        $todo[] = 'próbalevél';
    }
    return $todo ? ['todo', 'Hiányzik: ' . implode(', ', $todo)] : ['ok', 'Claude, MailerLite, levélküldés rendben'];
}
function mandala_wiz_status_store(): array
{
    if (!mandala_wiz_theme()) {
        return ['todo', 'Előbb a téma'];
    }
    $c = (array) mandala_config('contact', []);
    $bank = (array) get_option('woocommerce_bacs_accounts', []);
    $placeholder = (string) (mandala_config('bank', [])['account'] ?? '');
    $todo = [];
    if (get_option('mandala_contact') === false) {
        $todo[] = 'elérhetőség';
    }
    if (!$bank || empty($bank[0]['account_number']) || ($placeholder && $bank[0]['account_number'] === $placeholder)) {
        $todo[] = 'bankszámla';
    }
    return $todo ? ['todo', 'Hiányzik: ' . implode(', ', $todo)] : ['ok', ($c['email'] ?? '') . ' · ' . ($c['phone'] ?? '')];
}
function mandala_wiz_status_setup(): array
{
    if (!mandala_wiz_theme() || !class_exists('Mandala_Setup')) {
        return ['todo', 'Előbb a téma'];
    }
    $pending = Mandala_Setup::pending();
    return $pending ? ['todo', count($pending) . ' lépés vár'] : ['ok', 'Minden lépés lefutott'];
}
function mandala_wiz_status_products(): array
{
    $n = (int) (wp_count_posts('product')->publish ?? 0);
    return $n > 0 ? ['ok', $n . ' közzétett termék'] : ['todo', 'Még nincs termék'];
}
function mandala_wiz_status_filters(): array
{
    if (!mandala_wiz_theme() || !function_exists('mandala_ai_scope')) {
        return ['todo', 'Előbb a téma'];
    }
    $left = count(mandala_ai_scope());
    $running = array_filter(mandala_ai_runs(), fn($r) => ($r['status'] ?? '') === 'running');
    if ($running) {
        $r = reset($running);
        return ['info', sprintf('Fut: %d / %d', (int) $r['done'], (int) $r['total'])];
    }
    return $left === 0 ? ['ok', 'Minden termék besorolva'] : ['todo', $left . ' termék vár besorolásra'];
}
function mandala_wiz_status_shipping(): array
{
    if (!mandala_wiz_theme()) {
        return ['todo', 'Előbb a téma'];
    }
    $st = mandala_plugin_status();
    $todo = array_filter(['GLS' => $st['gls']['active'], 'Teya' => $st['teya']['active'], 'Számlázz.hu' => $st['szamlazz']['active']], fn($v) => !$v);
    return $todo ? ['todo', 'Hiányzik: ' . implode(', ', array_keys($todo))] : ['ok', 'GLS, Teya, Számlázz.hu aktív'];
}
function mandala_wiz_status_mails(): array
{
    if (!mandala_wiz_theme()) {
        return ['todo', 'Előbb a téma'];
    }
    $todo = [];
    if (mandala_wiz_smtp() === '') {
        $todo[] = 'SMTP';
    }
    if (!mandala_wiz_check('mails_reviewed')) {
        $todo[] = 'szövegek átnézése';
    }
    if (function_exists('mandala_ml_settings') && mandala_ml_ready() && !mandala_ml_settings()['group']) {
        $todo[] = 'MailerLite csoport';
    }
    return $todo ? ['todo', implode(', ', $todo)] : ['ok', 'SMTP: ' . mandala_wiz_smtp()];
}
function mandala_wiz_status_growth(): array
{
    if (!mandala_wiz_theme() || !function_exists('mandala_seo_counts')) {
        return ['todo', 'Előbb a téma'];
    }
    [$total, $done] = mandala_seo_counts();
    $feeds = (array) get_option('mandala_feeds_built', []);
    $parts = [sprintf('SEO %d/%d', $done, $total), $feeds ? 'feedek kész' : 'feedek még nem', count(array_filter(mandala_collections(), fn($c) => !empty($c['active']))) . ' gyűjtőoldal'];
    $ok = $feeds && get_option('mandala_reco_built') && ($total === 0 || $done > 0 || (function_exists('as_next_scheduled_action') && as_next_scheduled_action('mandala_seo_run')));
    return [$ok ? 'ok' : 'todo', implode(' · ', $parts)];
}
function mandala_wiz_legal_problems(): array
{
    $pages = ['woocommerce_terms_page_id' => 'ÁSZF', 'wp_page_for_privacy_policy' => 'Adatkezelési tájékoztató', 'mandala_page_impresszum' => 'Impresszum'];
    $out = [];
    foreach ($pages as $opt => $label) {
        $post = get_post((int) get_option($opt));
        if (!$post || $post->post_status !== 'publish') {
            $out[] = $label . ': nincs oldal';
        } elseif (preg_match('/\[[^\]]*(kitöltendő|pontosítandó)[^\]]*\]|\[(e-mail|telefon)\]/u', $post->post_content)) {
            $out[] = $label . ': kitöltendő részek maradtak';
        }
    }
    return $out;
}
function mandala_wiz_status_legal(): array
{
    $p = mandala_wiz_legal_problems();
    return $p ? ['todo', count($p) . ' teendő'] : ['ok', 'ÁSZF, adatkezelés, impresszum rendben'];
}
function mandala_wiz_status_speed(): array
{
    $cache = function_exists('mandala_cache_plugin') ? mandala_cache_plugin() : '';
    return $cache ? ['ok', 'Gyorsítótár: ' . $cache] : ['warn', 'Nincs oldal-gyorsítótár'];
}
function mandala_wiz_tests(): array
{
    return [
        'card' => 'Rendelés bankkártyával (Teya, éles vagy teszt módban) – a visszaigazoló levél megérkezett',
        'cod' => 'Rendelés utánvéttel, GLS CsomagPontra – a pontválasztó működik, az utánvét díja felszámolódik',
        'bacs' => 'Rendelés előre utalással – a köszönőoldalon és a levélben a helyes bankszámlaszám',
        'invoice' => 'Számla elkészült a Számlázz.hu-ban (adószámmal is, cégnek)',
        'tracking' => 'GLS címke / csomagszám → a rendelésnél megjelent, a vásárló „Feladtuk” levelet kapott',
        'mobile' => 'Mobilon: kereső, szűrő, kosár, pénztár végig',
        'newsletter' => 'Hírlevél-feliratkozás → megjelent a MailerLite csoportban',
        'chat' => 'AI tanácsadó: 5 valós kérdés (ajánlás, szállítás, rendelésállapot)',
    ];
}
function mandala_wiz_status_tests(): array
{
    $n = count(array_filter(array_keys(mandala_wiz_tests()), 'mandala_wiz_check'));
    $t = count(mandala_wiz_tests());
    return [$n === $t ? 'ok' : 'todo', $n . ' / ' . $t . ' próba kész'];
}
function mandala_wiz_status_live(): array
{
    return mandala_wiz_check('live') ? ['ok', 'Élesítve: ' . wp_date('Y. m. d.', (int) mandala_wiz_state()['checks']['live'])] : ['todo', 'Még nincs élesítve'];
}

/* ---------- Műveletek ---------- */

add_action('admin_post_mandala_wizard', function () {
    if (!current_user_can('manage_options') || !check_admin_referer('mandala_wizard')) {
        wp_die('', '', ['response' => 403]);
    }
    $do = sanitize_key($_POST['do'] ?? '');
    $step = sanitize_key($_POST['step'] ?? 'start');
    $msg = ['success', 'Kész.'];
    $state = mandala_wiz_state();
    try {
        switch ($do) {
            case 'check':
                $key = sanitize_key($_POST['key'] ?? '');
                if (!empty($_POST['value'])) {
                    $state['checks'][$key] = time();
                } else {
                    unset($state['checks'][$key]);
                }
                $msg = null;
                break;
            case 'lang':
                require_once ABSPATH . 'wp-admin/includes/translation-install.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                $lang = wp_download_language_pack('hu_HU');
                if ($lang) {
                    update_option('WPLANG', $lang);
                    wp_clean_update_cache();
                    wp_update_plugins();
                    wp_update_themes();
                    (new Language_Pack_Upgrader(new Automatic_Upgrader_Skin()))->bulk_upgrade();
                    $msg = ['success', 'A nyelv magyar, a fordítások (WordPress, WooCommerce, bővítmények) letöltve.'];
                } else {
                    $msg = ['error', 'A magyar nyelvi csomagot nem sikerült letölteni (fájlírási jog?). Beállítások → Általános → A honlap nyelve.'];
                }
                break;
            case 'tz':
                update_option('timezone_string', 'Europe/Budapest');
                update_option('date_format', 'Y. F j.');
                update_option('time_format', 'H:i');
                update_option('start_of_week', 1);
                $msg = ['success', 'Időzóna: Europe/Budapest, magyar dátumformátum.'];
                break;
            case 'perma':
                if ((string) get_option('permalink_structure') === '') {
                    update_option('permalink_structure', '/%postname%/');
                    flush_rewrite_rules();
                }
                $msg = ['success', 'Permalinkek: /bejegyzes-neve/.'];
                break;
            case 'install_plugin':
                $slug = sanitize_key($_POST['slug'] ?? '');
                $p = mandala_wiz_plugins()[$slug] ?? null;
                if (!$p || !$p[4]) {
                    throw new RuntimeException('Ez a bővítmény nem telepíthető innen.');
                }
                $msg = mandala_wiz_install_plugin($slug, $p[1]);
                break;
            case 'install_theme':
                require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
                require_once ABSPATH . 'wp-admin/includes/file.php';
                if (!wp_get_theme('mandala')->exists()) {
                    $ok = (new Theme_Upgrader(new Automatic_Upgrader_Skin()))->install(mandala_wiz_bundled_theme());
                    if (is_wp_error($ok) || !$ok) {
                        throw new RuntimeException('A téma telepítése nem sikerült' . (is_wp_error($ok) ? ': ' . $ok->get_error_message() : '') . '. Megjelenés → Témák → Új hozzáadása → Feltöltés: mandala-tema.zip.');
                    }
                }
                switch_theme('mandala');
                $msg = ['success', 'A Mandala téma telepítve és bekapcsolva. A telepítő az első admin-betöltéskor elindul (élő boltban megerősítést kér – lásd „Mandala telepítő” lépés).'];
                break;
            case 'keys':
                if (!defined('MANDALA_ANTHROPIC_API_KEY') && trim((string) ($_POST['anthropic'] ?? '')) !== '') {
                    $ai = (array) get_option('mandala_ai', []);
                    $ai['api_key'] = trim(sanitize_text_field(wp_unslash($_POST['anthropic'])));
                    update_option('mandala_ai', $ai, false);
                }
                if (!defined('MANDALA_MAILERLITE_TOKEN') && trim((string) ($_POST['mailerlite'] ?? '')) !== '') {
                    $ml = (array) get_option('mandala_mailerlite', []);
                    $ml['token'] = trim(sanitize_text_field(wp_unslash($_POST['mailerlite'])));
                    update_option('mandala_mailerlite', $ml, false);
                }
                $res = [];
                if (mandala_ai_ready()) {
                    $r = mandala_claude_request(['model' => MANDALA_CLAUDE_DEFAULT_MODEL, 'max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Szia']]], 30);
                    $res[] = is_wp_error($r) ? 'Claude: HIBA – ' . $r->get_error_message() : 'Claude: rendben';
                    $state['checks']['ai_ok'] = is_wp_error($r) ? 0 : time();
                }
                if (function_exists('mandala_ml_ready') && mandala_ml_ready()) {
                    $g = mandala_ml_request('GET', 'groups?limit=100');
                    if (!is_wp_error($g)) {
                        $ml = mandala_ml_settings();
                        $ml['groups'] = [];
                        foreach ((array) ($g['data'] ?? []) as $x) {
                            $ml['groups'][(string) $x['id']] = (string) $x['name'];
                        }
                        update_option('mandala_mailerlite', $ml, false);
                    }
                    $res[] = is_wp_error($g) ? 'MailerLite: HIBA – ' . $g->get_error_message() : 'MailerLite: rendben (' . count((array) ($g['data'] ?? [])) . ' csoport)';
                }
                $msg = [str_contains(implode(' ', $res), 'HIBA') ? 'error' : 'success', $res ? implode(' · ', $res) : 'Mentve.'];
                break;
            case 'test_mail':
                $to = sanitize_email(wp_unslash($_POST['to'] ?? '')) ?: wp_get_current_user()->user_email;
                $ok = function_exists('mandala_send_mail')
                    ? mandala_send_mail($to, '[' . get_bloginfo('name') . '] Próbalevél a telepítő varázslóból', 'Próbalevél', '<p>Ha ezt olvasod, a levélküldés működik. Nézd meg azt is, hogy nem a spam mappába érkezett-e.</p>', false, ['type' => 'teszt'])
                    : wp_mail($to, 'Próbalevél', 'Működik.');
                $msg = $ok ? ['success', 'Elküldve: ' . $to . '. Ha megérkezett (és nem a spambe), jelöld be lent.'] : ['error', 'A levél nem ment el – állítsd be az SMTP bővítményt.'];
                break;
            case 'run_setup':
                update_option('mandala_setup_confirmed', time(), false);
                $log = (new Mandala_Setup())->run();
                $msg = ['success', 'A telepítő lefutott. ' . implode(' ', array_slice(array_filter($log, fn($l) => str_starts_with($l, '!')), 0, 5))];
                break;
            case 'ai_dry':
                $ids = array_slice(mandala_ai_scope(), 0, 20);
                if (!$ids) {
                    throw new RuntimeException('Nincs besorolandó termék.');
                }
                mandala_ai_start('dry', $ids, 'Varázsló – próba (20)');
                $msg = ['success', 'A próbafuttatás elindult (20 termék, semmit nem ír át). Pár perc múlva az eredmény: Termékek → Új termékek → Claude migráció.'];
                break;
            case 'ai_apply':
                $ids = mandala_ai_scope();
                if (!$ids) {
                    throw new RuntimeException('Nincs besorolandó termék.');
                }
                mandala_ai_start('apply', $ids, 'Varázsló – teljes (' . count($ids) . ')');
                $msg = ['success', count($ids) . ' termék besorolása elindult a háttérben. Visszavonható: Claude migráció fül.'];
                break;
            case 'growth':
                $seo = (array) get_option('mandala_seo_ai', []);
                $seo['enabled'] = 'yes';
                update_option('mandala_seo_ai', $seo, false);
                mandala_seo_kick(5);
                mandala_reco_build();
                $c = mandala_collections_build();
                $f = mandala_feeds_build();
                $msg = ['success', sprintf('Ajánló kész, %d gyűjtőoldal, feedek: %s. Az AI SEO a háttérben fut (napi korláttal).', $c['active'], implode(', ', array_map(fn($k, $v) => $k . ' ' . $v, array_keys($f), $f)))];
                break;
            case 'webp':
                $n = mandala_webp_start();
                $msg = ['success', $n . ' kép átalakítása elindult a háttérben (15 képenként).'];
                break;
            case 'selfcheck':
                $results = mandala_selfcheck();
                $state['selfcheck'] = ['at' => time(), 'results' => $results];
                $fail = count(array_filter($results, fn($r) => $r['status'] === 'fail'));
                $warn = count(array_filter($results, fn($r) => $r['status'] === 'warn'));
                $msg = [$fail ? 'error' : ($warn ? 'warning' : 'success'), $fail || $warn ? sprintf('Önellenőrzés: %d hiba, %d figyelmeztetés – részletek lent.', $fail, $warn) : 'Önellenőrzés: minden rendben.'];
                break;
            case 'live':
                $state['checks']['live'] = time();
                $msg = ['success', 'Gratulálunk – a bolt élesítve!'];
                break;
        }
    } catch (Throwable $e) {
        $msg = ['error', $e->getMessage()];
    }
    update_option(MANDALA_WIZ_OPTION, $state, false);
    if ($msg) {
        set_transient('mandala_wiz_msg_' . get_current_user_id(), $msg, 120);
    }
    wp_safe_redirect(admin_url('admin.php?page=mandala-varazslo&step=' . $step));
    exit;
});

function mandala_wiz_install_plugin(string $slug, string $file): array
{
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    if (!$file || !file_exists(WP_PLUGIN_DIR . '/' . $file)) {
        $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
        if (is_wp_error($api) || !is_object($api) || empty($api->download_link)) {
            return ['error', 'Nem érem el a WordPress.org-ot' . (is_wp_error($api) ? ': ' . $api->get_error_message() : '') . '. Telepítsd kézzel: Bővítmények → Új hozzáadása.'];
        }
        $ok = (new Plugin_Upgrader(new Automatic_Upgrader_Skin()))->install($api->download_link);
        if (is_wp_error($ok) || !$ok) {
            return ['error', 'A telepítés nem sikerült (fájlírási jog?). Telepítsd kézzel: Bővítmények → Új hozzáadása → „' . $slug . '”.'];
        }
        if (!$file) {
            $installed = array_keys(get_plugins('/' . $slug));
            $file = $installed ? $slug . '/' . $installed[0] : '';
        }
    }
    $act = activate_plugin($file);
    return is_wp_error($act) ? ['error', 'Telepítve, de a bekapcsolás nem sikerült: ' . $act->get_error_message()] : ['success', 'Telepítve és bekapcsolva.'];
}

/* ---------- Felület ---------- */

function mandala_wiz_button(string $do, string $label, array $extra = [], string $class = 'button button-primary'): string
{
    $step = sanitize_key($_GET['step'] ?? 'start');
    $out = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wiz-inline">' . wp_nonce_field('mandala_wizard', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="mandala_wizard"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="step" value="' . esc_attr($step) . '">';
    foreach ($extra as $k => $v) {
        $out .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
    }
    return $out . '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
}
function mandala_wiz_checkbox(string $key, string $label): string
{
    $step = sanitize_key($_GET['step'] ?? 'start');
    $on = mandala_wiz_check($key);
    return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wiz-check">' . wp_nonce_field('mandala_wizard', '_wpnonce', true, false)
        . '<input type="hidden" name="action" value="mandala_wizard"><input type="hidden" name="do" value="check"><input type="hidden" name="step" value="' . esc_attr($step) . '"><input type="hidden" name="key" value="' . esc_attr($key) . '">'
        . '<label><input type="checkbox" name="value" value="1"' . checked($on, true, false) . ' onchange="this.form.submit()"> ' . esc_html($label) . '</label>'
        . ($on ? ' <span class="wiz-when">' . esc_html(wp_date('m. d. H:i', (int) mandala_wiz_state()['checks'][$key])) . '</span>' : '') . '<noscript><button class="button">Mentés</button></noscript></form>';
}
function mandala_wiz_link(string $url, string $label, bool $external = false): string
{
    return '<a class="button" href="' . esc_url($url) . '"' . ($external ? ' target="_blank" rel="noopener"' : '') . '>' . esc_html($label) . ($external ? ' ↗' : '') . '</a>';
}
function mandala_wiz_row(bool $ok, string $text, string $help = '', string $action = ''): string
{
    return '<li class="' . ($ok ? 'is-ok' : 'is-todo') . '"><span class="wiz-dot" aria-hidden="true">' . ($ok ? '✓' : '!') . '</span><div><strong>' . esc_html($text) . '</strong>' . (!$ok && $help ? '<p>' . esc_html($help) . '</p>' : '') . ($action && !$ok ? '<div class="wiz-actions">' . $action . '</div>' : '') . '</div>'
        . '<span class="screen-reader-text">' . ($ok ? 'kész' : 'teendő') . '</span></li>';
}
function mandala_wiz_need_theme(): bool
{
    if (mandala_wiz_theme()) {
        return false;
    }
    echo '<div class="notice notice-warning inline"><p>Ehhez a lépéshez a Mandala témának aktívnak kell lennie (lásd „Téma” lépés).</p></div>';
    return true;
}

function mandala_wiz_page(): void
{
    $steps = mandala_wiz_steps();
    $current = sanitize_key($_GET['step'] ?? '');
    $statuses = [];
    foreach (array_keys($steps) as $k) {
        $statuses[$k] = mandala_wiz_status($k);
    }
    if (!isset($steps[$current])) {
        // Az első nem kész lépés.
        $current = 'start';
        foreach ($statuses as $k => $s) {
            if ($s[0] !== 'ok' && $steps[$k][2]) {
                $current = $k;
                break;
            }
        }
    }
    $required = array_filter($steps, fn($s) => $s[2]);
    $done = count(array_filter(array_keys($required), fn($k) => $statuses[$k][0] === 'ok'));
    $pct = (int) round($done / max(1, count($required)) * 100);
    $msg = get_transient('mandala_wiz_msg_' . get_current_user_id());
    delete_transient('mandala_wiz_msg_' . get_current_user_id());
    ?>
<div class="wrap mandala-wiz">
  <h1>Mandala telepítő varázsló</h1>
  <p class="wiz-lead">Lépésről lépésre végigvisz a beüzemelésen. Minden lépés magától ellenőrzi, kész-e – nyugodtan megszakíthatod, ott folytatod, ahol abbahagytad.</p>
  <div class="wiz-progress" role="progressbar" aria-valuenow="<?php echo (int) $pct; ?>" aria-valuemin="0" aria-valuemax="100" aria-label="Haladás"><span style="width:<?php echo (int) $pct; ?>%"></span></div>
  <p class="wiz-progress-text"><?php echo (int) $done; ?> / <?php echo count($required); ?> kötelező lépés kész (<?php echo (int) $pct; ?>%)</p>
  <?php if ($msg) : ?><div class="notice notice-<?php echo $msg[0] === 'error' ? 'error' : 'success'; ?>"><p><?php echo esc_html($msg[1]); ?></p></div><?php endif; ?>
  <div class="wiz-layout">
    <nav class="wiz-nav" aria-label="Lépések"><ol>
      <?php $group = ''; $i = 0; foreach ($steps as $k => $s) : $i++; ?>
        <?php if ($s[0] !== $group) : $group = $s[0]; ?><li class="wiz-group"><?php echo esc_html($group); ?></li><?php endif; ?>
        <li class="wiz-step is-<?php echo esc_attr($statuses[$k][0]); ?><?php echo $k === $current ? ' is-current' : ''; ?>"><a href="<?php echo esc_url(admin_url('admin.php?page=mandala-varazslo&step=' . $k)); ?>"<?php echo $k === $current ? ' aria-current="step"' : ''; ?>>
          <span class="wiz-dot" aria-hidden="true"><?php echo $statuses[$k][0] === 'ok' ? '✓' : (int) $i; ?></span><span><strong><?php echo esc_html($s[1]); ?></strong><small><?php echo esc_html($statuses[$k][1]); ?><?php echo $s[2] ? '' : ' · nem kötelező'; ?></small></span></a></li>
      <?php endforeach; ?>
    </ol></nav>
    <section class="wiz-main" aria-labelledby="wiz-title">
      <h2 id="wiz-title"><?php echo esc_html($steps[$current][1]); ?></h2>
      <p class="wiz-status is-<?php echo esc_attr($statuses[$current][0]); ?>"><?php echo esc_html(['ok' => 'Kész', 'todo' => 'Teendő', 'warn' => 'Figyelem', 'info' => 'Folyamatban'][$statuses[$current][0]] ?? ''); ?>: <?php echo esc_html($statuses[$current][1]); ?></p>
      <?php call_user_func($steps[$current][4]); ?>
      <?php
      $keys = array_keys($steps);
      $pos = array_search($current, $keys, true);
      echo '<p class="wiz-nextprev">' . ($pos > 0 ? '<a class="button" href="' . esc_url(admin_url('admin.php?page=mandala-varazslo&step=' . $keys[$pos - 1])) . '">← ' . esc_html($steps[$keys[$pos - 1]][1]) . '</a>' : '')
          . ($pos < count($keys) - 1 ? ' <a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=mandala-varazslo&step=' . $keys[$pos + 1])) . '">' . esc_html($steps[$keys[$pos + 1]][1]) . ' →</a>' : '') . '</p>';
      ?>
    </section>
  </div>
</div>
<style>
.mandala-wiz .wiz-lead{max-width:760px;font-size:14px}
.wiz-progress{max-width:760px;height:10px;background:#dcdcde;border-radius:6px;overflow:hidden}.wiz-progress span{display:block;height:100%;background:#8A4512}
.wiz-progress-text{margin:6px 0 16px;color:#50575e}
.wiz-layout{display:grid;grid-template-columns:300px minmax(0,1fr);gap:24px;align-items:start;max-width:1280px}
.wiz-nav ol{list-style:none;margin:0;padding:0;background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden}
.wiz-group{padding:10px 14px 4px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8A4512;font-weight:600}
.wiz-step a{display:grid;grid-template-columns:28px 1fr;gap:10px;padding:8px 14px;text-decoration:none;color:#1d2327;align-items:start}
.wiz-step a:hover,.wiz-step.is-current a{background:#f6f1e8}
.wiz-step small{display:block;color:#646970;font-size:12px}
.wiz-dot{width:24px;height:24px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:600;background:#f0f0f1;color:#50575e}
.is-ok>a .wiz-dot,.wiz-rows .is-ok .wiz-dot{background:#00a32a;color:#fff}
.is-todo>a .wiz-dot,.wiz-rows .is-todo .wiz-dot{background:#dba617;color:#fff}
.is-warn>a .wiz-dot,.wiz-rows .is-warn .wiz-dot{background:#dba617;color:#fff}.wiz-rows .is-fail .wiz-dot{background:#d63638;color:#fff}.wiz-selfcheck p{margin:2px 0 0;color:#50575e}.is-info>a .wiz-dot{background:#2271b1;color:#fff}
.wiz-main{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:20px 24px}
.wiz-main h2{margin-top:0;font-size:20px}.wiz-main h3{margin:22px 0 8px}
.wiz-status{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:600}
.wiz-status.is-ok{background:#edfaef;color:#00690f}.wiz-status.is-todo,.wiz-status.is-warn{background:#fcf9e8;color:#8a6116}.wiz-status.is-info{background:#f0f6fc;color:#135e96}
.wiz-rows{list-style:none;margin:12px 0;padding:0;display:grid;gap:8px}
.wiz-rows li{display:grid;grid-template-columns:28px 1fr;gap:10px;padding:10px 12px;border:1px solid #f0f0f1;border-radius:6px}
.wiz-rows li p{margin:4px 0 0;color:#50575e}
.wiz-actions{margin-top:8px;display:flex;gap:6px;flex-wrap:wrap}
.wiz-inline{display:inline-block;margin:0 6px 6px 0}
.wiz-check{margin:6px 0}.wiz-check label{font-size:14px}.wiz-when{color:#646970;font-size:12px}
.wiz-nextprev{margin-top:24px;padding-top:16px;border-top:1px solid #f0f0f1;display:flex;justify-content:space-between;gap:8px}
.wiz-code{width:100%;font-family:monospace}
@media (max-width:960px){.wiz-layout{grid-template-columns:1fr}}
</style>
    <?php
}

/* ---------- Lépések tartalma ---------- */

function mandala_wiz_step_start(): void
{
    echo '<p>Mielőtt bármit átállítunk: <strong>készíts teljes mentést</strong> az éles boltról (fájlok és adatbázis) – a tárhely vezérlőpultján vagy az UpdraftPlus bővítménnyel. Ha lehet, először tesztszerveren (az éles bolt másolatán) menj végig a varázslón.</p>'
        . '<p>A varázsló semmit nem kapcsol át magától: minden lépésnél te indítod a műveletet, és a Mandala telepítő a kézzel módosított beállításokat nem írja felül.</p>';
    echo mandala_wiz_checkbox('backup', 'Elkészült a mentés (és tudom, hogyan kell visszaállítani)');
    echo mandala_wiz_checkbox('staging', 'Tesztszerveren / másolaton dolgozom (nem kötelező, de ajánlott)');
}

function mandala_wiz_step_env(): void
{
    echo '<ul class="wiz-rows">';
    foreach (mandala_wiz_env() as $k => [$ok, $text, $help]) {
        $action = ['lang' => mandala_wiz_button('lang', 'Magyar nyelv + fordítások'), 'tz' => mandala_wiz_button('tz', 'Budapest időzóna'), 'perma' => mandala_wiz_button('perma', 'Olvasható címek bekapcsolása')][$k] ?? '';
        echo mandala_wiz_row($ok, $text, $help, $action); // phpcs:ignore
    }
    echo '</ul><p class="description">A wp-config.php kiegészítés (memória, API-kulcsok, valódi cron) a telepítő csomagban van: wp-config-kiegeszites.php.</p>';
}

function mandala_wiz_step_plugins(): void
{
    echo '<ul class="wiz-rows">';
    foreach (mandala_wiz_plugins() as $slug => $p) {
        $active = mandala_wiz_plugin_active($slug, $p);
        $action = $p[4] ? mandala_wiz_button('install_plugin', 'Telepítés és bekapcsolás', ['slug' => $slug]) : mandala_wiz_link(admin_url('plugin-install.php?s=' . rawurlencode($p[5] ?: $p[0]) . '&tab=search&type=term'), 'Keresés a bővítmények között');
        if ($slug === 'teya' || $slug === 'gls') {
            $action .= ' ' . mandala_wiz_link(admin_url('plugin-install.php?tab=upload'), 'Zip feltöltése');
        }
        echo mandala_wiz_row($active, $p[0] . ($p[3] ? '' : ' (ajánlott)'), $p[2], $action); // phpcs:ignore
    }
    echo '</ul><p class="description">A WPML (többnyelvűség) nem kötelező. A GLS és a Teya bővítményét a szolgáltatótól kapjátok, ha nincs a WordPress.org-on.</p>';
}

function mandala_wiz_step_theme(): void
{
    $parent = wp_get_theme('iu_theme')->exists();
    $child = wp_get_theme('mandala')->exists();
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row($parent, 'iu_theme szülőtéma', 'Az iu_theme keretrendszert töltsd fel: Megjelenés → Témák → Új hozzáadása → Feltöltés (nem kell bekapcsolni).', mandala_wiz_link(admin_url('theme-install.php?upload'), 'Téma feltöltése'))
        . mandala_wiz_row(function_exists('iucb_add_block'), 'iu_custom_blocks (a keretrendszer blokkjai)', 'Az iu_theme csomagjában lévő mu-plugin – a tárhely wp-content/mu-plugins mappájába kell tenni. Nélküle a blokkok tartalék módban működnek.')
        . mandala_wiz_row(mandala_wiz_theme(), 'Mandala téma', $child ? 'Telepítve, de nincs bekapcsolva.' : 'A varázslóba csomagolt téma telepítése és bekapcsolása.', $parent ? mandala_wiz_button('install_theme', $child ? 'Bekapcsolás' : 'Telepítés és bekapcsolás') : '')
        . '</ul><p class="description">Élő boltban a téma bekapcsolásakor a telepítő nem fut le magától: a „Mandala telepítő” lépésben nézed át és indítod.</p>';
}

function mandala_wiz_step_keys(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $step = 'keys';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('mandala_wizard', '_wpnonce', true, false) . '<input type="hidden" name="action" value="mandala_wizard"><input type="hidden" name="do" value="keys"><input type="hidden" name="step" value="' . $step . '">'
        . '<table class="form-table"><tr><th scope="row"><label for="wiz-ai">Anthropic (Claude) API-kulcs</label></th><td>'
        . (defined('MANDALA_ANTHROPIC_API_KEY') ? '<code>wp-config.php-ban</code>' : '<input type="password" id="wiz-ai" name="anthropic" class="regular-text" autocomplete="off" placeholder="' . (mandala_ai_ready() ? '•••••• mentve' : 'sk-ant-…') . '">')
        . ' ' . (mandala_ai_ready() ? (mandala_wiz_check('ai_ok') ? '<span style="color:#00690f">✓ működik</span>' : '<span>megadva</span>') : '')
        . '<p class="description">console.anthropic.com → API Keys. Ezzel működik az AI tanácsadó, a szűrők besorolása, az AI SEO, a gyűjtőoldalak szövegei. Állíts be havi költségkorlátot a Console-ban.</p></td></tr>'
        . '<tr><th scope="row"><label for="wiz-ml">MailerLite API-kulcs</label></th><td>'
        . (defined('MANDALA_MAILERLITE_TOKEN') ? '<code>wp-config.php-ban</code>' : '<input type="password" id="wiz-ml" name="mailerlite" class="regular-text" autocomplete="off" placeholder="' . (function_exists('mandala_ml_ready') && mandala_ml_ready() ? '•••••• mentve' : 'MailerLite → Integrations → API') . '">')
        . '</td></tr></table><p><button class="button button-primary">Mentés és ellenőrzés</button></p></form>';
    echo '<h3>Levélküldés</h3><p>SMTP bővítmény: <strong>' . esc_html(mandala_wiz_smtp() ?: 'nincs') . '</strong>' . (mandala_wiz_smtp() ? '' : ' – a Bővítmények lépésben telepítheted (WP Mail SMTP), majd állítsd be a tárhely vagy a levélküldő szolgáltató SMTP adataival.') . '</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wiz-inline">' . wp_nonce_field('mandala_wizard', '_wpnonce', true, false) . '<input type="hidden" name="action" value="mandala_wizard"><input type="hidden" name="do" value="test_mail"><input type="hidden" name="step" value="keys">'
        . '<input type="email" name="to" value="' . esc_attr(wp_get_current_user()->user_email) . '" class="regular-text" aria-label="Címzett"> <button class="button">Próbalevél küldése</button></form>';
    echo mandala_wiz_checkbox('mail_ok', 'A próbalevél megérkezett, és nem a spam mappába');
}

function mandala_wiz_step_store(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $bank = (array) get_option('woocommerce_bacs_accounts', []);
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row(get_option('mandala_contact') !== false, 'Elérhetőség, nyitvatartás, ingyenes szállítás határa, utánvét díja', 'A téma mindenhol ezeket mutatja (fejléc, lábléc, levelek, AI tanácsadó).', mandala_wiz_link(admin_url('admin.php?page=mandala-store'), 'Mandala bolt adatai'))
        . mandala_wiz_row(!empty($bank[0]['account_number']) && $bank[0]['account_number'] !== (string) (mandala_config('bank', [])['account'] ?? ''), 'Bankszámlaszám az előre utaláshoz', 'A köszönőoldal és a visszaigazoló levél innen veszi.', mandala_wiz_link(admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs'), 'Előre utalás beállítása'))
        . '</ul>';
}

function mandala_wiz_step_setup(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $pending = Mandala_Setup::pending();
    echo '<p>A Mandala telepítő verziózott lépésekben beállítja: WooCommerce alapok (HUF, magyar formátumok, levelek arculata), ÁFA, szállítási zóna, fizetés, tulajdonságok (szűrők), kategóriafa, oldalak, menük. <strong>A kézzel módosított beállításokat és oldalakat nem írja felül</strong>, és az eredeti értékek visszaállíthatók (Megjelenés → Mandala telepítő).</p>';
    if ($pending) {
        echo '<p>Váró lépések: <strong>' . esc_html(implode(', ', $pending)) . '</strong></p>' . mandala_wiz_button('run_setup', 'Telepítő futtatása') . ' ' . mandala_wiz_link(admin_url('themes.php?page=mandala-setup'), 'Részletes áttekintés lépésenként');
    } else {
        echo '<p>Minden lépés lefutott. ' . mandala_wiz_link(admin_url('themes.php?page=mandala-setup'), 'Napló és visszaállítás') . '</p>';
    }
}

function mandala_wiz_step_products(): void
{
    $n = (int) (wp_count_posts('product')->publish ?? 0);
    $draft = (int) (wp_count_posts('product')->draft ?? 0);
    echo '<p>Közzétett termék: <strong>' . $n . '</strong>' . ($draft ? ' · piszkozat: ' . $draft : '') . '</p>';
    echo '<h3>Ha a termékek egy másik (régi) boltból jönnek</h3><ol><li>A régi boltban: Termékek → Exportálás (CSV, minden oszlop).</li><li>Itt: Termékek → Importálás, a CSV-t feltöltve („Meglévő termékek frissítése” cikkszám alapján).</li><li>A képeket a CSV címeiről tölti le – ehhez a régi boltnak elérhetőnek kell maradnia az import idejére.</li></ol>'
        . '<p>' . mandala_wiz_link(admin_url('edit.php?post_type=product&page=product_importer'), 'Termékimport') . ' ' . mandala_wiz_link(admin_url('edit.php?post_type=product'), 'Termékek') . '</p>'
        . '<h3>JUTA</h3><p>A JUTA-ból érkező új termékek piszkozatként, ellenőrzőlistával a Termékek → Új termékek sorba kerülnek; az ár- és készletfrissítés nem élesít semmit.</p>'
        . '<p>A JUTA „Akciós ár”-a a <strong>nagyker ár</strong> (Wholesale Prices mező) lesz, nem bolti akció – a bolti akciós árat a termékszerkesztőben állítjátok, a JUTA nem írja felül.</p>';
    if (function_exists('mandala_juta_migrate_sales') && mandala_juta_sale_is_wholesale() && ($juta = mandala_juta_migrate_sales(false)) && $juta['count']) {
        echo '<div class="notice notice-warning inline"><p><strong>' . (int) $juta['count'] . ' terméknél bolti akciós ár van, nagyker ár nincs</strong> – valószínűleg a korábbi JUTA-szinkron tette az akciós árba. Áthelyezés: ' . mandala_wiz_link(admin_url('edit.php?post_type=product&page=mandala-onboarding&tab=settings'), 'Új termékek → Beállítások') . '</p></div>';
    }
    if (mandala_wiz_theme()) {
        echo '<p>' . mandala_wiz_link(admin_url('edit.php?post_type=product&page=mandala-onboarding'), 'Új termékek sora') . '</p>';
        echo '<h3>Bemutató tartalom</h3><p>Ha tesztszerveren bemutató termékeket telepítettél, élesítés előtt töröld őket: Megjelenés → Mandala telepítő → „Bemutató tartalom törlése”.</p>';
    }
}

function mandala_wiz_step_filters(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $left = count(mandala_ai_scope());
    echo '<p>A Claude a meglévő termékeket az új kategóriafába sorolja, és kitölti a szűrőket (hang, Hz, súly, csakra, illat, anyag, eredet…) a termék nevéből, leírásából és régi adataiból. A biztos javaslat érvénybe lép, a bizonytalan az „Élő, ellenőrizendő” listára kerül. <strong>Minden futtatás visszavonható.</strong></p>'
        . '<p>Besorolásra vár: <strong>' . $left . '</strong> termék.</p>';
    if (!mandala_ai_ready()) {
        echo '<div class="notice notice-warning inline"><p>Előbb add meg az Anthropic API-kulcsot (Kulcsok lépés).</p></div>';
        return;
    }
    $runs = array_slice(array_reverse(mandala_ai_runs(), true), 0, 3, true);
    if ($runs) {
        echo '<ul class="wiz-rows">';
        foreach ($runs as $r) {
            echo mandala_wiz_row(($r['status'] ?? '') === 'done', sprintf('%s – %s: %d / %d (automatikus: %d, ellenőrizendő: %d)', $r['label'] ?? '', $r['mode'] === 'dry' ? 'próba' : 'éles', (int) $r['done'], (int) $r['total'], (int) $r['auto'], (int) $r['review']), ($r['status'] ?? '') === 'running' ? 'Fut a háttérben – frissítsd az oldalt.' : ''); // phpcs:ignore
        }
        echo '</ul>';
    }
    echo '<p>1. ' . mandala_wiz_button('ai_dry', 'Próbafuttatás 20 termékkel', [], 'button') . ' – semmit nem ír át; az eredményt nézd át.</p>'
        . '<p>2. ' . mandala_wiz_button('ai_apply', 'Teljes besorolás (' . $left . ' termék)') . ' – a háttérben fut, a becsült költség a Claude migráció fülön.</p>'
        . '<p>' . mandala_wiz_link(admin_url('edit.php?post_type=product&page=mandala-onboarding&tab=ai'), 'Claude migráció részletei') . ' ' . mandala_wiz_link(admin_url('edit.php?post_type=product&page=mandala-onboarding&tab=review'), 'Élő, ellenőrizendő') . '</p>';
}

function mandala_wiz_step_shipping(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $st = mandala_plugin_status();
    $cod = (array) get_option('woocommerce_cod_settings', []);
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row($st['gls']['active'], 'GLS: futár, CsomagPont, automata', 'A GLS bővítményben add meg a szerződéses adatokat, és a „Magyarország” (és ha kell, „Európai Unió”) zónában add hozzá a GLS módokat.', mandala_wiz_link(admin_url('admin.php?page=wc-settings&tab=shipping'), 'Szállítási zónák'))
        . mandala_wiz_row($st['teya']['active'], 'Teya kártyás fizetés', 'A Teya bővítményében add meg a kulcsokat; először teszt módban próbáld.', mandala_wiz_link(admin_url('admin.php?page=wc-settings&tab=checkout'), 'Fizetési módok'))
        . mandala_wiz_row(($cod['enabled'] ?? 'no') === 'yes', 'Utánvét', 'Kapcsold be az utánvétet (a díját a téma számolja a Mandala bolt adatai szerint).', mandala_wiz_link(admin_url('admin.php?page=wc-settings&tab=checkout&section=cod'), 'Utánvét'))
        . mandala_wiz_row($st['szamlazz']['active'], 'Számlázz.hu', 'Az Agent kulcs és a számla beállítások a bővítményben; az adószám a rendelésben: _billing_tax_number.')
        . mandala_wiz_row(true, 'Csomagkövetés', '')
        . '</ul><p>A GLS csomagszámot a téma a GLS bővítmény mezőjéből olvassa. Ha az első éles csomagnál nem jelenik meg a rendelés „Csomagkövetés és levelek” dobozában, a mező nevét add meg: ' . mandala_wiz_link(admin_url('admin.php?page=mandala-automations&tab=beallitasok'), 'Csomagszám mezők') . '</p>';
}

function mandala_wiz_step_mails(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    $ml = function_exists('mandala_ml_settings') ? mandala_ml_settings() : [];
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row(mandala_wiz_smtp() !== '', 'SMTP levélküldés', 'Kulcsok és levélküldés lépés.')
        . mandala_wiz_row(mandala_wiz_check('mails_reviewed'), 'A levelek szövegének átnézése', 'Minden levélnél előnézet és tesztlevél. A WooCommerce saját levelei (visszaigazolás, teljesítés) is ott szerkeszthetők.', mandala_wiz_link(admin_url('admin.php?page=mandala-automations'), 'Mandala levelek'))
        . mandala_wiz_row(!empty($ml['group']), 'MailerLite csoport', 'Válaszd ki a hírlevél csoportot, küldd át a meglévő listát, és kapcsold be a leiratkozás-visszajelzést.', mandala_wiz_link(admin_url('admin.php?page=mandala-automations&tab=mailerlite'), 'MailerLite beállítása'))
        . '</ul>';
    echo mandala_wiz_checkbox('mails_reviewed', 'Átnéztem a levelek szövegét (és küldtem magamnak tesztlevelet)');
    echo '<p class="description">Önjáró levelek: elhagyott kosár, használati útmutató, értékelés kérése, személyre szabott utánrendelés, vásárlás utáni ajánló, kedvenc akciós / fogyóban, feliratkozó kupon, ajánlási jutalom, feladtuk / átvehető, visszaküldés. Saját levél triggerrel: „+ Új saját levél”.</p>';
}

function mandala_wiz_step_growth(): void
{
    if (mandala_wiz_need_theme()) {
        return;
    }
    [$total, $done] = mandala_seo_counts();
    $reco = (array) get_option('mandala_reco_built', []);
    $feeds = (array) get_option('mandala_feeds_built', []);
    $colls = array_filter(mandala_collections(), fn($c) => !empty($c['active']));
    echo '<p>Egy gombbal elindítja az önjáró részeket; utána maguktól frissülnek (az ajánló és a gyűjtőoldalak naponta, a feedek óránként, az AI SEO az új és módosult termékekre).</p>';
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row($done > 0 || (function_exists('as_next_scheduled_action') && as_next_scheduled_action('mandala_seo_run')), sprintf('AI SEO: %d / %d termék (cím, meta leírás, kép alt, GYIK)', $done, $total), 'Napi korláttal fut a háttérben.', '')
        . mandala_wiz_row((bool) $reco, 'Tanuló ajánló' . ($reco ? sprintf(' – %d rendelésből', (int) $reco['orders']) : ''), '')
        . mandala_wiz_row(count($colls) > 0, count($colls) . ' gyűjtőoldal', 'Legalább 6 termékes válogatásokból; kevés termékkel kevés lesz.')
        . mandala_wiz_row((bool) $feeds, 'Termékfeedek', '')
        . '</ul>' . mandala_wiz_button('growth', 'Indítás / frissítés most');
    if (function_exists('MANDALA_FEEDS') || defined('MANDALA_FEEDS')) {
        echo '<h3>Feedek – egyszer kell bemásolni a szolgáltatónál</h3><table class="widefat striped"><tbody>';
        foreach (MANDALA_FEEDS as $name => $f) {
            echo '<tr><td style="width:220px"><strong>' . esc_html($f['label']) . '</strong><br><small>' . esc_html($f['where']) . '</small></td><td><input class="wiz-code" readonly value="' . esc_attr(mandala_feed_url($name)) . '" onclick="this.select()"></td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<h3>Kuponok és ajánlás</h3><p>Feliratkozó ablak első vásárlási kuponnal és ajánlási program – alapból bekapcsolva. ' . mandala_wiz_link(admin_url('admin.php?page=mandala-growth'), 'Mandala kuponok') . '</p>';
}

function mandala_wiz_step_legal(): void
{
    $p = mandala_wiz_legal_problems();
    echo '<p>A téma az ÁSZF, az adatkezelési tájékoztató és az impresszum vázát létrehozta – <strong>a cégadatokat és a kitöltendő részeket nektek kell pótolni, jogászi átnézéssel</strong>. Az adatkezelésbe kerüljön be: AI tanácsadó (Anthropic), MailerLite, GLS, Teya, Számlázz.hu, Google / Meta mérés.</p>';
    echo '<ul class="wiz-rows">';
    foreach (['woocommerce_terms_page_id' => 'ÁSZF', 'wp_page_for_privacy_policy' => 'Adatkezelési tájékoztató', 'mandala_page_impresszum' => 'Impresszum'] as $opt => $label) {
        $id = (int) get_option($opt);
        $bad = array_filter($p, fn($x) => str_starts_with($x, $label));
        echo mandala_wiz_row(!$bad, $label, $bad ? reset($bad) : '', $id ? mandala_wiz_link(admin_url('post.php?post=' . $id . '&action=edit'), 'Szerkesztés') : ''); // phpcs:ignore
    }
    echo '</ul>';
}

function mandala_wiz_step_speed(): void
{
    $cache = function_exists('mandala_cache_plugin') ? mandala_cache_plugin() : '';
    $webp = function_exists('mandala_webp_supported') && mandala_webp_supported();
    $state = (string) get_option('mandala_webp_state', '');
    echo '<ul class="wiz-rows">'
        . mandala_wiz_row($cache !== '', 'Oldal-gyorsítótár' . ($cache ? ': ' . $cache : ''), 'LiteSpeed tárhelyen a LiteSpeed Cache, máshol a tárhely ajánlott bővítménye. A kosár, a pénztár és a fiók oldalt ki kell zárni (a legtöbb bővítmény magától teszi).', mandala_wiz_link(admin_url('admin.php?page=mandala-varazslo&step=plugins'), 'Bővítmények'))
        . mandala_wiz_row($webp && $state === 'done', 'Képek WebP formátumban' . ($state === 'running' ? ' – fut' : ''), $webp ? 'Az új képek már WebP-ben készülnek; a meglévőket a háttérben átalakítja.' : 'A tárhely képkezelője nem tud WebP-t – nem kötelező.', $webp && $state !== 'running' ? mandala_wiz_button('webp', 'Meglévő képek átalakítása') : '')
        . '</ul><p class="description">Mérés élesítés után: pagespeed.web.dev – a termékoldal és a kínálat mobilon.</p>';
}

/** Az önellenőrzés eredménye (a téma mandala_selfcheck() függvényéből). */
function mandala_wiz_selfcheck_html(): string
{
    if (!function_exists('mandala_selfcheck')) {
        return '';
    }
    $sc = mandala_wiz_state()['selfcheck'] ?? null;
    $out = '<h3>Automatikus önellenőrzés</h3><p>Oldalak, pénztár, gyorsítótár, tömörítés, HTTPS, oldaltérkép, feed, levélküldés, háttérfeladatok, tárhely – egy kattintással (fél perc).</p>'
        . mandala_wiz_button('selfcheck', $sc ? 'Önellenőrzés újra' : 'Önellenőrzés futtatása', [], 'button button-primary');
    if (is_array($sc) && !empty($sc['results'])) {
        $icons = ['ok' => '✓', 'warn' => '!', 'fail' => '✗'];
        $out .= '<ul class="wiz-rows wiz-selfcheck">';
        foreach ($sc['results'] as $r) {
            $out .= '<li class="' . ($r['status'] === 'ok' ? 'is-ok' : ($r['status'] === 'warn' ? 'is-warn' : 'is-fail')) . '"><span class="wiz-dot" aria-hidden="true">' . $icons[$r['status']] . '</span><div><strong>' . esc_html($r['label']) . '</strong><p>' . esc_html($r['msg']) . '</p></div></li>';
        }
        $out .= '</ul><p class="description">Utolsó futás: ' . esc_html(wp_date('Y.m.d. H:i', (int) $sc['at'])) . '</p>';
    }
    return $out;
}

function mandala_wiz_step_tests(): void
{
    echo mandala_wiz_selfcheck_html(); // phpcs:ignore
    echo '<h3>Próbarendelések</h3><p>Élesítés előtt ezeket egyszer végig kell próbálni (kártyánál teszt módban vagy kis összeggel, utána visszatérítve):</p>';
    foreach (mandala_wiz_tests() as $key => $label) {
        echo mandala_wiz_checkbox($key, $label); // phpcs:ignore
    }
}

function mandala_wiz_step_live(): void
{
    $steps = mandala_wiz_steps();
    $open = [];
    foreach ($steps as $k => $s) {
        if ($s[2] && $k !== 'live' && mandala_wiz_status($k)[0] !== 'ok') {
            $open[] = $s[1];
        }
    }
    if ($open) {
        echo '<div class="notice notice-warning inline"><p>Még nyitott kötelező lépés: <strong>' . esc_html(implode(', ', $open)) . '</strong>.</p></div>';
    }
    echo '<ol><li>Teya és GLS éles módba.</li><li>Bemutató tartalom törölve (ha volt).</li><li>Beállítások → Olvasás: „A keresőmotorok indexelése” engedélyezve.</li><li>Google Search Console: oldaltérkép beküldése; Merchant Center: feed.</li><li>Az első napokban figyeld a Mandala levelek naplóját és a heti összefoglalót.</li>'
        . '<li>Külső figyelő (ingyenes, 5 percenként – akkor is jelez, ha az egész oldal leáll): <a href="https://uptimerobot.com" target="_blank" rel="noopener">UptimeRobot</a> → HTTP(s) figyelő ezzel a címmel: ' . (function_exists('mandala_health_url') ? '<code>' . esc_html(mandala_health_url()) . '</code>' : 'Mandala levelek → Beállítások → Őrszem') . '</li>'
        . '<li>Egy hét múlva: Eszközök → Átirányítások – a gyakori, meg nem talált régi címeknek adj célt.</li></ol>';
    echo mandala_wiz_button('live', $open ? 'Élesítés így is' : 'A bolt élesítve', [], 'button button-primary button-hero');
    if (get_option('blog_public') === '0') {
        echo '<div class="notice notice-error inline"><p>A keresőmotorok indexelése ki van kapcsolva (Beállítások → Olvasás).</p></div>';
    }
}
