<?php
/**
 * Tulajdonosi kivonat: egy oldalon minden, ami a bolt teljesítményéről számít, és ugyanez hétfőnként levélben.
 *
 *  - Forgalom és tölcsér saját, sütimentes méréssel: egyedi látogató (napi, visszafejthetetlen azonosító –
 *    IP-t nem tárolunk), oldal- és termékmegtekintés, kosárba tétel, pénztár, rendelés → konverziós arány.
 *    Hozzájárulás nem kell hozzá (nincs süti, nincs személyes adat); a GA4 ettől függetlenül fut.
 *  - Bevétel, rendelés, kosárérték, új / visszatérő vásárló, az előző időszakhoz képest.
 *  - Mi hozta a bevételt: kuponok forrás szerint (üdvözlő, születésnapi, ajánló, partner, kampány),
 *    automata levelek (rendelés a levél után 7 napon belül), csomag, ajándék, előfizetés, partnerek.
 *  - AI: tanácsadó beszélgetések, átadások, becsült költség; kereső: keresések, eredménytelenek.
 *  - Egészség: szerver válaszidő (mintavétel), hibás háttérfeladatok.
 *
 * Admin: bal menü → Kivonat. Heti levél: a heti összefoglaló (report.php) elején.
 */

defined('ABSPATH') || exit;

const MANDALA_STATS_DB_VERSION = '1';

function mandala_stats_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_stats';
}
function mandala_stats_uv_table(): string
{
    global $wpdb;
    return $wpdb->prefix . 'mandala_stats_uv';
}
add_action('init', function () {
    if (get_option('mandala_stats_db') === MANDALA_STATS_DB_VERSION) {
        return;
    }
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cs = $wpdb->get_charset_collate();
    dbDelta('CREATE TABLE ' . mandala_stats_table() . " (
        day date NOT NULL,
        metric varchar(32) NOT NULL,
        n bigint unsigned NOT NULL default 0,
        PRIMARY KEY  (day,metric)
    ) $cs;");
    dbDelta('CREATE TABLE ' . mandala_stats_uv_table() . " (
        day date NOT NULL,
        kind varchar(12) NOT NULL,
        h char(16) NOT NULL,
        PRIMARY KEY  (day,kind,h)
    ) $cs;");
    update_option('mandala_stats_db', MANDALA_STATS_DB_VERSION);
});

/** Napi számláló növelése. */
function mandala_stat(string $metric, int $by = 1, string $day = ''): void
{
    global $wpdb;
    $day = $day ?: wp_date('Y-m-d');
    $wpdb->query($wpdb->prepare('INSERT INTO ' . mandala_stats_table() . ' (day, metric, n) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE n = n + VALUES(n)', $day, $metric, $by)); // phpcs:ignore
}
/** Napi egyedi (visszafejthetetlen, naponta változó azonosító): igaz, ha ma először. */
function mandala_stat_unique(string $kind): bool
{
    global $wpdb;
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $day = wp_date('Y-m-d');
    $h = substr(hash_hmac('sha256', $ip . '|' . $ua, wp_salt('nonce') . $day), 0, 16);
    return (bool) $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . mandala_stats_uv_table() . ' (day, kind, h) VALUES (%s, %s, %s)', $day, $kind, $h)); // phpcs:ignore
}
function mandala_stat_is_bot(): bool
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|pingdom|uptime|curl|wget|python|monitor/', $ua);
}

/* ---------- Mérés ---------- */

// Oldal- és termékmegtekintés: a böngészőből (az oldal-gyorsítótár mellett is pontos), sendBeacon-nel.
add_action('wp_footer', function () {
    if (is_admin() || is_user_logged_in() && current_user_can('edit_posts')) {
        return; // a bolt munkatársai ne torzítsák
    }
    $type = function_exists('is_product') && is_product() ? 'product' : 'page';
    echo '<script>(function(){try{var u=' . wp_json_encode(rest_url('mandala/v1/hit')) . ',b=JSON.stringify({t:"' . $type . '"});navigator.sendBeacon?navigator.sendBeacon(u,new Blob([b],{type:"application/json"})):fetch(u,{method:"POST",body:b,keepalive:true,headers:{"Content-Type":"application/json"}});}catch(e){}})();</script>'; // phpcs:ignore
}, 99);
add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/hit', [
        'methods' => 'POST',
        'permission_callback' => '__return_true', // névtelen számláló, személyes adat nélkül
        'callback' => function (WP_REST_Request $r) {
            if (mandala_stat_is_bot()) {
                return ['ok' => false];
            }
            $t = $r->get_param('t') === 'product' ? 'product' : 'page';
            mandala_stat('views');
            if ($t === 'product') {
                mandala_stat('product_views');
            }
            if (mandala_stat_unique('visit')) {
                mandala_stat('visitors');
            }
            return ['ok' => true];
        },
    ]);
});
add_action('woocommerce_add_to_cart', function () {
    if (!mandala_stat_is_bot() && !(defined('WP_CLI') && WP_CLI)) {
        mandala_stat('add_to_cart');
        if (mandala_stat_unique('cart')) {
            mandala_stat('carts');
        }
    }
}, 50);
add_action('template_redirect', function () {
    if (function_exists('is_checkout') && is_checkout() && !is_order_received_page() && !mandala_stat_is_bot() && WC()->cart && !WC()->cart->is_empty() && mandala_stat_unique('checkout')) {
        mandala_stat('checkouts');
    }
});
// Szerver válaszidő: minden 10. látogatói kérés (admin, REST, AJAX, cron nélkül).
add_action('shutdown', function () {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI) || mt_rand(1, 10) !== 1 || empty($_SERVER['REQUEST_TIME_FLOAT'])) {
        return;
    }
    $ms = (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
    if ($ms > 0 && $ms < 60000) {
        mandala_stat('resp_ms', $ms);
        mandala_stat('resp_n');
    }
});
// Az egyedi-azonosítók 2 nap után törlődnek (csak a darabszám marad).
mandala_recurring('mandala_stats_cleanup', DAY_IN_SECONDS, fn() => time() + HOUR_IN_SECONDS);
add_action('mandala_stats_cleanup', function () {
    global $wpdb;
    $wpdb->query($wpdb->prepare('DELETE FROM ' . mandala_stats_uv_table() . ' WHERE day < %s', wp_date('Y-m-d', time() - 2 * DAY_IN_SECONDS))); // phpcs:ignore
});

/* ---------- Adatok ---------- */

/** Napi mérőszámok összege egy időszakra ('Y-m-d' mindkettő, zárt intervallum). */
function mandala_stats_sum(string $from, string $to): array
{
    global $wpdb;
    $out = [];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT metric, SUM(n) AS n FROM ' . mandala_stats_table() . ' WHERE day BETWEEN %s AND %s GROUP BY metric', $from, $to)) as $r) { // phpcs:ignore
        $out[$r->metric] = (int) $r->n;
    }
    return $out;
}
function mandala_stats_daily(string $from, string $to, string $metric): array
{
    global $wpdb;
    $out = [];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT day, n FROM ' . mandala_stats_table() . ' WHERE metric = %s AND day BETWEEN %s AND %s', $metric, $from, $to)) as $r) { // phpcs:ignore
        $out[$r->day] = (int) $r->n;
    }
    return $out;
}

/** Egy időszak rendelései (feldolgozott / teljesített / fizetésre vár). */
function mandala_owner_orders(int $from, int $to): array
{
    return wc_get_orders(['status' => ['processing', 'completed', 'on-hold'], 'date_created' => $from . '...' . $to, 'limit' => -1, 'type' => 'shop_order']);
}

/** A kivonat adatai. $days: időszak hossza napban, a mai nappal bezárólag; az összevetés az előtte lévő ugyanilyen időszak. */
function mandala_owner_data(int $days = 7, ?int $end = null): array
{
    global $wpdb;
    $days = max(1, $days);
    $end ??= time();
    $start = (int) strtotime(wp_date('Y-m-d', $end - ($days - 1) * DAY_IN_SECONDS) . ' 00:00:00 ' . wp_timezone_string());
    $pstart = $start - $days * DAY_IN_SECONDS;
    $orders = mandala_owner_orders($start, $end);
    $porders = mandala_owner_orders($pstart, $start - 1);
    $sales = function (array $list) {
        $rev = array_sum(array_map(fn($o) => (float) $o->get_total() - (float) $o->get_total_refunded(), $list));
        return ['orders' => count($list), 'revenue' => $rev, 'aov' => $list ? $rev / count($list) : 0];
    };
    $cur = $sales($orders);
    $prev = $sales($porders);
    // Új / visszatérő vásárló
    $emails = array_unique(array_filter(array_map(fn($o) => strtolower($o->get_billing_email()), $orders)));
    $first = function_exists('mandala_first_order_dates') ? mandala_first_order_dates($emails) : [];
    $new = count(array_filter($emails, fn($e) => ($first[$e] ?? 0) >= $start));
    // Forgalom és tölcsér
    $f = wp_date('Y-m-d', $start);
    $t = wp_date('Y-m-d', $end);
    $st = mandala_stats_sum($f, $t);
    $pst = mandala_stats_sum(wp_date('Y-m-d', $pstart), wp_date('Y-m-d', $start - 1));
    $visitors = (int) ($st['visitors'] ?? 0);
    // Mi hozta a bevételt
    $campaign_codes = array_map(fn($c) => wc_format_coupon_code((string) ($c['coupon'] ?? '')), function_exists('mandala_campaigns') ? mandala_campaigns() : []);
    $by_source = [];
    $add = function (string $k, float $rev) use (&$by_source) {
        $by_source[$k] = ['n' => ($by_source[$k]['n'] ?? 0) + 1, 'revenue' => ($by_source[$k]['revenue'] ?? 0) + $rev];
    };
    $feature = ['bundle' => [0, 0.0], 'gift' => [0, 0.0], 'sub_first' => [0, 0.0], 'sub_renewal' => [0, 0.0], 'partner' => [0, 0.0]];
    $commission = 0.0;
    foreach ($orders as $o) {
        $rev = (float) $o->get_total() - (float) $o->get_total_refunded();
        foreach ($o->get_coupon_codes() as $code) {
            $c = new WC_Coupon($code);
            $src = (string) $c->get_meta('_mandala_source');
            $k = $c->get_meta('_mandala_partner') ? 'partner' : ($src === 'welcome' ? 'welcome' : ($src === 'birthday' ? 'birthday' : ($src === 'referral' || $c->get_meta('_mandala_referrer') ? 'referral'
                : (in_array(wc_format_coupon_code($code), $campaign_codes, true) ? 'campaign' : 'other'))));
            $add($k, $rev);
        }
        $flags = ['bundle' => false, 'gift' => false, 'sub' => false];
        foreach ($o->get_items() as $item) {
            $flags['bundle'] = $flags['bundle'] || $item->get_meta('Csomagkedvezmény') !== '';
            $flags['gift'] = $flags['gift'] || $item->get_meta('Ajándék') !== '';
            $flags['sub'] = $flags['sub'] || (int) $item->get_meta('_mandala_sub') > 0;
        }
        foreach (['bundle', 'gift'] as $k) {
            if ($flags[$k]) {
                $feature[$k][0]++;
                $feature[$k][1] += $rev;
            }
        }
        if ($o->get_meta('_mandala_sub_id')) {
            $feature['sub_renewal'][0]++;
            $feature['sub_renewal'][1] += $rev;
        } elseif ($flags['sub']) {
            $feature['sub_first'][0]++;
            $feature['sub_first'][1] += $rev;
        }
        if ($o->get_meta('_mandala_partner_id') || (function_exists('mandala_order_partner') && mandala_order_partner($o))) {
            $feature['partner'][0]++;
            $feature['partner'][1] += $rev;
            $commission += (float) $o->get_meta('_mandala_partner_commission');
        }
    }
    // Automata levelek után 7 napon belüli rendelések (az utolsó kapott marketinglevél típusa szerint)
    $mail = [];
    if (function_exists('mandala_mail_table') && $emails) {
        $types = array_keys(array_filter(function_exists('mandala_mail_types') ? mandala_mail_types() : [], fn($d) => !empty($d['marketing']) || in_array($d['group'] ?? '', ['Emlékeztetők', 'Értesítések'], true)));
        $in = implode(',', array_map(fn($e) => $wpdb->prepare('%s', $e), $emails));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT recipient, type, created FROM ' . mandala_mail_table() . " WHERE recipient IN ($in) AND status = 'sent' AND created > %s ORDER BY created DESC", gmdate('Y-m-d H:i:s', $start - 7 * DAY_IN_SECONDS))); // phpcs:ignore
        foreach ($orders as $o) {
            $ts = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
            foreach ($rows as $r) {
                $sent = strtotime($r->created . ' UTC');
                if (strtolower($r->recipient) === strtolower($o->get_billing_email()) && in_array($r->type, $types, true) && $sent <= $ts && $sent > $ts - 7 * DAY_IN_SECONDS) {
                    $mail[$r->type] = ['n' => ($mail[$r->type]['n'] ?? 0) + 1, 'revenue' => ($mail[$r->type]['revenue'] ?? 0) + (float) $o->get_total()];
                    break;
                }
            }
        }
        uasort($mail, fn($a, $b) => $b['revenue'] <=> $a['revenue']);
    }
    $mails_sent = function_exists('mandala_mail_table') ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . mandala_mail_table() . " WHERE status = 'sent' AND created BETWEEN %s AND %s", gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end))) : 0; // phpcs:ignore
    // Előfizetések
    $subs_active = 0;
    $mrr = 0.0;
    if (defined('MANDALA_SUB_CPT')) {
        $pct = (float) (mandala_sub_settings()['percent'] ?? 0);
        foreach (get_posts(['post_type' => MANDALA_SUB_CPT, 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_status', 'meta_value' => 'active']) as $id) {
            $subs_active++;
            $m = max(1, (int) get_post_meta($id, '_interval', true));
            foreach ((array) get_post_meta($id, '_items', true) as [$pid, $qty]) {
                $p = wc_get_product((int) $pid);
                $mrr += $p ? (float) $p->get_price() * (1 - $pct / 100) * (int) $qty / $m : 0;
            }
        }
    }
    // AI tanácsadó
    $chat = ['conversations' => 0, 'turns' => 0, 'handoff' => 0, 'cost_usd' => 0.0];
    if (function_exists('mandala_chat_table') && get_option('mandala_chat_db')) {
        $r = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS c, SUM(turns) AS t, SUM(handoff) AS h, SUM(tokens_in) AS ti, SUM(tokens_out) AS tou, SUM(cache_read) AS cr FROM ' . mandala_chat_table() . ' WHERE started BETWEEN %s AND %s', gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end))); // phpcs:ignore
        // Sonnet 5 árak: 2 $ / MTok bemenet, 10 $ / MTok kimenet, gyorsítótár-olvasás 0,2 $ / MTok
        $chat = ['conversations' => (int) $r->c, 'turns' => (int) $r->t, 'handoff' => (int) $r->h, 'cost_usd' => round(((int) $r->ti * 2 + (int) $r->tou * 10 + (int) $r->cr * 0.2) / 1e6, 2)];
    }
    // Kereső
    $search = ['searches' => 0, 'zero' => 0];
    if (function_exists('mandala_search_table')) {
        $r = $wpdb->get_row($wpdb->prepare('SELECT SUM(searches) AS s, SUM(CASE WHEN last_results = 0 THEN searches ELSE 0 END) AS z FROM ' . mandala_search_table() . ' WHERE last_seen BETWEEN %s AND %s', gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-d H:i:s', $end))); // phpcs:ignore
        $search = ['searches' => (int) ($r->s ?? 0), 'zero' => (int) ($r->z ?? 0)];
    }
    // Értékelések, feliratkozók
    $reviews = get_posts(['post_type' => 'mandala_review', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'date_query' => [['after' => gmdate('Y-m-d H:i:s', $start), 'column' => 'post_date_gmt']]]);
    $ratings = array_map(fn($id) => (int) get_post_meta($id, '_rating', true), $reviews);
    $subs_nl = count(array_filter((array) get_option('mandala_newsletter', []), fn($s) => strtotime((string) ($s['date'] ?? '')) >= $start));
    $failed = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['group' => MANDALA_AS_GROUP, 'status' => ActionScheduler_Store::STATUS_FAILED, 'date' => gmdate('Y-m-d H:i:s', $start), 'date_compare' => '>=', 'per_page' => 100], 'ids')) : 0;
    // Napi sorok a grafikonhoz
    $daily = [];
    $vis = mandala_stats_daily($f, $t, 'visitors');
    for ($d = $start; $d <= $end; $d += DAY_IN_SECONDS) {
        $daily[wp_date('Y-m-d', $d)] = ['revenue' => 0.0, 'orders' => 0, 'visitors' => $vis[wp_date('Y-m-d', $d)] ?? 0];
    }
    foreach ($orders as $o) {
        $k = $o->get_date_created() ? wp_date('Y-m-d', $o->get_date_created()->getTimestamp()) : '';
        if (isset($daily[$k])) {
            $daily[$k]['revenue'] += (float) $o->get_total() - (float) $o->get_total_refunded();
            $daily[$k]['orders']++;
        }
    }
    return [
        'days' => $days, 'from' => $f, 'to' => $t,
        'cur' => $cur + ['new' => $new, 'returning' => max(0, count($emails) - $new)], 'prev' => $prev,
        'traffic' => ['visitors' => $visitors, 'views' => (int) ($st['views'] ?? 0), 'product_views' => (int) ($st['product_views'] ?? 0), 'carts' => (int) ($st['carts'] ?? 0), 'add_to_cart' => (int) ($st['add_to_cart'] ?? 0), 'checkouts' => (int) ($st['checkouts'] ?? 0),
            'conversion' => $visitors ? $cur['orders'] / $visitors * 100 : 0, 'prev_visitors' => (int) ($pst['visitors'] ?? 0), 'prev_conversion' => ($pst['visitors'] ?? 0) ? $prev['orders'] / $pst['visitors'] * 100 : 0],
        'sources' => $by_source, 'mail' => $mail, 'mails_sent' => $mails_sent, 'features' => $feature, 'commission' => $commission,
        'subs' => ['active' => $subs_active, 'mrr' => $mrr], 'chat' => $chat, 'search' => $search,
        'reviews' => ['n' => count($reviews), 'avg' => $ratings ? round(array_sum($ratings) / count($ratings), 1) : 0], 'newsletter' => $subs_nl,
        'speed' => ['ms' => ($st['resp_n'] ?? 0) ? (int) round($st['resp_ms'] / $st['resp_n']) : 0, 'samples' => (int) ($st['resp_n'] ?? 0)], 'failed' => $failed,
        'daily' => $daily,
    ];
}

/* ---------- Megjelenítés (admin és levél közös darabjai) ---------- */

function mandala_owner_delta(float $cur, float $prev): string
{
    if ($prev <= 0) {
        return $cur > 0 ? '<span class="d up">új</span>' : '<span class="d">–</span>';
    }
    $p = ($cur - $prev) / $prev * 100;
    return '<span class="d ' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '▲' : '▼') . ' ' . abs((int) round($p)) . '%</span>';
}
function mandala_owner_source_labels(): array
{
    return ['welcome' => 'Üdvözlő kupon (feliratkozó ablak)', 'birthday' => 'Születésnapi kupon', 'referral' => 'Ajánlási program', 'partner' => 'Partnerkód', 'campaign' => 'Kampánykupon', 'other' => 'Egyéb kupon'];
}

/** Levélbe illeszthető kivonat (inline stílusok). */
function mandala_owner_email_html(array $d): string
{
    $pct = fn($a, $b) => $b > 0 ? sprintf('%+d%%', round(($a - $b) / $b * 100)) : '–';
    $td = 'style="padding:10px 12px;border-bottom:1px solid #EEE7DB"';
    $kpi = fn($label, $value, $delta = '') => '<td style="width:33%;padding:12px;background:#F7F4EE;border-radius:10px;vertical-align:top"><div style="font-size:12px;color:#6E6357;text-transform:uppercase;letter-spacing:.06em">' . esc_html($label) . '</div><div style="font-size:22px;font-weight:600;color:#1C1916;margin-top:4px">' . $value . '</div>' . ($delta !== '' ? '<div style="font-size:13px;color:#6E6357">' . esc_html($delta) . ' az előző héthez</div>' : '') . '</td>';
    $tr = $d['traffic'];
    $h = '<table role="presentation" style="width:100%;border-collapse:separate;border-spacing:8px;margin:0 -8px 8px"><tr>'
        . $kpi('Bevétel', esc_html(mandala_fmt($d['cur']['revenue'])), $pct($d['cur']['revenue'], $d['prev']['revenue']))
        . $kpi('Rendelés', (string) (int) $d['cur']['orders'], $pct($d['cur']['orders'], $d['prev']['orders']))
        . $kpi('Átlagos kosár', esc_html(mandala_fmt($d['cur']['aov'])), $pct($d['cur']['aov'], $d['prev']['aov'])) . '</tr><tr>'
        . $kpi('Látogató', (string) number_format_i18n($tr['visitors']), $pct($tr['visitors'], $tr['prev_visitors']))
        . $kpi('Konverzió', esc_html(number_format_i18n($tr['conversion'], 2)) . '%', $tr['prev_conversion'] ? sprintf('%+.2f pont', $tr['conversion'] - $tr['prev_conversion']) : '')
        . $kpi('Új vásárló', (string) (int) $d['cur']['new']) . '</tr></table>';
    $h .= '<h2 style="font-size:17px;margin:20px 0 8px">Tölcsér</h2><table role="presentation" style="width:100%;border-collapse:collapse">';
    $steps = [['Látogató', $tr['visitors']], ['Terméket nézett', $tr['product_views']], ['Kosárba tett', $tr['carts']], ['Pénztárba lépett', $tr['checkouts']], ['Rendelt', $d['cur']['orders']]];
    $max = max(1, $tr['visitors']);
    foreach ($steps as [$l, $n]) {
        $h .= '<tr><td ' . $td . ' width="36%">' . esc_html($l) . '</td><td ' . $td . ' width="44%"><div style="background:#A9581A;height:10px;border-radius:5px;width:' . max(1, (int) round(min(1, $n / $max) * 100)) . '%"></div></td><td ' . $td . ' align="right">' . esc_html(number_format_i18n($n)) . '</td></tr>';
    }
    $h .= '</table>';
    $rows = [];
    foreach ($d['sources'] as $k => $v) {
        $rows[] = [mandala_owner_source_labels()[$k] ?? $k, $v['n'], $v['revenue']];
    }
    $types = function_exists('mandala_mail_types') ? mandala_mail_types() : [];
    foreach ($d['mail'] as $k => $v) {
        $rows[] = ['Levél: ' . ($types[$k]['label'] ?? $k), $v['n'], $v['revenue']];
    }
    foreach (['bundle' => 'Csomagkedvezmény', 'gift' => 'Ajándék értékhatár felett', 'sub_first' => 'Előfizetés – új', 'sub_renewal' => 'Előfizetés – megújítás', 'partner' => 'Partnereken át'] as $k => $l) {
        if ($d['features'][$k][0]) {
            $rows[] = [$l, $d['features'][$k][0], $d['features'][$k][1]];
        }
    }
    if ($rows) {
        usort($rows, fn($a, $b) => $b[2] <=> $a[2]);
        $h .= '<h2 style="font-size:17px;margin:20px 0 8px">Mi hozta a bevételt</h2><table role="presentation" style="width:100%;border-collapse:collapse">';
        foreach ($rows as [$l, $n, $rev]) {
            $h .= '<tr><td ' . $td . '>' . esc_html($l) . '</td><td ' . $td . ' align="right">' . (int) $n . ' rendelés</td><td ' . $td . ' align="right"><strong>' . esc_html(mandala_fmt($rev)) . '</strong></td></tr>';
        }
        $h .= '</table><p style="color:#6E6357;font-size:12px">Egy rendelés több sorban is szerepelhet (pl. kuponnal és levél után). Levél: a vásárló az utolsó 7 napban kapott tőlünk automata levelet.</p>';
    }
    $h .= '<h2 style="font-size:17px;margin:20px 0 8px">Röviden</h2><ul style="margin:0;padding-left:20px">'
        . '<li>Előfizetés: <strong>' . (int) $d['subs']['active'] . '</strong> aktív, havi ismétlődő bevétel kb. <strong>' . esc_html(mandala_fmt($d['subs']['mrr'])) . '</strong></li>'
        . '<li>AI tanácsadó: ' . (int) $d['chat']['conversations'] . ' beszélgetés, ' . (int) $d['chat']['turns'] . ' kérdés, költség kb. ' . esc_html(number_format_i18n($d['chat']['cost_usd'], 2)) . ' $</li>'
        . '<li>Kereső: ' . (int) $d['search']['searches'] . ' keresés, ebből ' . (int) $d['search']['zero'] . ' eredménytelen</li>'
        . '<li>Automata levél: ' . (int) $d['mails_sent'] . ' db · új feliratkozó: ' . (int) $d['newsletter'] . ' · új értékelés: ' . (int) $d['reviews']['n'] . ($d['reviews']['n'] ? ' (átlag ' . esc_html(number_format_i18n($d['reviews']['avg'], 1)) . ')' : '') . '</li>'
        . ($d['speed']['samples'] ? '<li>Oldalbetöltés (szerver): átlag ' . (int) $d['speed']['ms'] . ' ms</li>' : '')
        . ($d['commission'] ? '<li>Partner jutalék: ' . esc_html(mandala_fmt($d['commission'])) . '</li>' : '') . '</ul>';
    return $h . mandala_mail_button(admin_url('admin.php?page=mandala-owner'), 'Teljes kivonat az adminban');
}

/* ---------- Admin: Kivonat ---------- */

add_action('admin_menu', function () {
    add_menu_page('Tulajdonosi kivonat', 'Kivonat', 'manage_woocommerce', 'mandala-owner', 'mandala_owner_page', 'dashicons-chart-area', 3);
});
function mandala_owner_page(): void
{
    $days = (int) ($_GET['napok'] ?? 30); // phpcs:ignore
    $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;
    $d = mandala_owner_data($days);
    $tr = $d['traffic'];
    $card = fn($label, $value, $delta = '', $note = '') => '<div class="mo-card"><span class="mo-l">' . esc_html($label) . '</span><strong>' . $value . '</strong>' . $delta . ($note ? '<small>' . esc_html($note) . '</small>' : '') . '</div>';
    echo '<div class="wrap mandala-owner"><h1>Tulajdonosi kivonat</h1><p class="mo-period">';
    foreach ([7 => '7 nap', 30 => '30 nap', 90 => '90 nap', 365 => '1 év'] as $n => $l) {
        echo '<a class="button' . ($n === $days ? ' button-primary' : '') . '" href="' . esc_url(add_query_arg('napok', $n)) . '">' . esc_html($l) . '</a> ';
    }
    echo '<span class="description">' . esc_html($d['from'] . ' – ' . $d['to']) . ', összevetve az előző ' . $days . ' nappal.</span></p>';
    echo '<div class="mo-grid">'
        . $card('Bevétel', esc_html(mandala_fmt($d['cur']['revenue'])), mandala_owner_delta($d['cur']['revenue'], $d['prev']['revenue']))
        . $card('Rendelés', (string) (int) $d['cur']['orders'], mandala_owner_delta($d['cur']['orders'], $d['prev']['orders']))
        . $card('Átlagos kosárérték', esc_html(mandala_fmt($d['cur']['aov'])), mandala_owner_delta($d['cur']['aov'], $d['prev']['aov']))
        . $card('Látogató', esc_html(number_format_i18n($tr['visitors'])), mandala_owner_delta($tr['visitors'], $tr['prev_visitors']), 'egyedi, naponta')
        . $card('Konverzió', esc_html(number_format_i18n($tr['conversion'], 2)) . '%', mandala_owner_delta($tr['conversion'], $tr['prev_conversion']), 'rendelés / látogató')
        . $card('Új / visszatérő vásárló', (int) $d['cur']['new'] . ' / ' . (int) $d['cur']['returning'])
        . $card('Előfizetés (aktív)', (string) (int) $d['subs']['active'], '', 'havi kb. ' . mandala_fmt($d['subs']['mrr']))
        . $card('Oldalbetöltés (szerver)', $d['speed']['samples'] ? (int) $d['speed']['ms'] . ' ms' : '–', '', $d['speed']['samples'] ? $d['speed']['samples'] . ' mérés' : 'még nincs mérés')
        . '</div>';
    // Napi bevétel + látogató (SVG oszlop + vonal)
    $daily = $d['daily'];
    $maxr = max(1, max(array_column($daily, 'revenue') ?: [0]));
    $maxv = max(1, max(array_column($daily, 'visitors') ?: [0]));
    $n = count($daily);
    $w = 900;
    $hgt = 180;
    $bw = $w / max(1, $n);
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . ($hgt + 20) . '" class="mo-chart" role="img" aria-label="Napi bevétel és látogatók">';
    $pts = [];
    $i = 0;
    foreach ($daily as $day => $v) {
        $bh = $v['revenue'] / $maxr * $hgt;
        $svg .= '<rect x="' . round($i * $bw + $bw * .15, 1) . '" y="' . round($hgt - $bh, 1) . '" width="' . round($bw * .7, 1) . '" height="' . round($bh, 1) . '" rx="2" fill="#A9581A"><title>' . esc_html($day . ': ' . mandala_fmt($v['revenue']) . ', ' . $v['orders'] . ' rendelés, ' . $v['visitors'] . ' látogató') . '</title></rect>';
        $pts[] = round($i * $bw + $bw / 2, 1) . ',' . round($hgt - $v['visitors'] / $maxv * $hgt, 1);
        if ($n <= 31 || $i % (int) ceil($n / 12) === 0) {
            $svg .= '<text x="' . round($i * $bw + $bw / 2, 1) . '" y="' . ($hgt + 15) . '" text-anchor="middle" font-size="10" fill="#6E6357">' . esc_html(wp_date('m.d', strtotime($day))) . '</text>';
        }
        $i++;
    }
    $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#51613F" stroke-width="2"/></svg>';
    echo '<div class="mo-panel"><h2>Napi bevétel <span class="mo-key mo-key-r"></span> és látogatók <span class="mo-key mo-key-v"></span></h2>' . $svg . '</div>'; // phpcs:ignore
    // Tölcsér
    $steps = [['Látogató', $tr['visitors']], ['Terméket nézett (megtekintés)', $tr['product_views']], ['Kosárba tett (látogató)', $tr['carts']], ['Pénztárba lépett', $tr['checkouts']], ['Rendelt', $d['cur']['orders']]];
    echo '<div class="mo-cols"><div class="mo-panel"><h2>Vásárlási tölcsér</h2><table class="mo-funnel">';
    $prev_n = 0;
    foreach ($steps as $k => [$l, $v]) {
        $rate = $k && $prev_n ? ' <small>(' . round($v / $prev_n * 100) . '%)</small>' : '';
        echo '<tr><td>' . esc_html($l) . '</td><td><span class="mo-bar" style="width:' . max(1, (int) round(min(1, $v / max(1, $tr['visitors'])) * 100)) . '%"></span></td><td class="num">' . esc_html(number_format_i18n($v)) . $rate . '</td></tr>'; // phpcs:ignore
        $prev_n = $v;
    }
    echo '</table><p class="description">Saját, sütimentes mérés (a bolt munkatársai és a robotok nélkül). A százalék az előző lépéshez képest.</p></div>';
    // Mi hozta a bevételt
    $types = function_exists('mandala_mail_types') ? mandala_mail_types() : [];
    echo '<div class="mo-panel"><h2>Mi hozta a bevételt</h2><table class="widefat striped"><thead><tr><th>Forrás</th><th class="num">Rendelés</th><th class="num">Bevétel</th></tr></thead><tbody>';
    $rows = [];
    foreach ($d['sources'] as $k => $v) {
        $rows[] = [mandala_owner_source_labels()[$k] ?? $k, $v['n'], $v['revenue']];
    }
    foreach ($d['mail'] as $k => $v) {
        $rows[] = ['Levél: ' . ($types[$k]['label'] ?? $k), $v['n'], $v['revenue']];
    }
    foreach (['bundle' => 'Csomagkedvezmény', 'gift' => 'Ajándék értékhatár felett', 'sub_first' => 'Előfizetés – új', 'sub_renewal' => 'Előfizetés – megújítás', 'partner' => 'Partnereken át'] as $k => $l) {
        if ($d['features'][$k][0]) {
            $rows[] = [$l, $d['features'][$k][0], $d['features'][$k][1]];
        }
    }
    usort($rows, fn($a, $b) => $b[2] <=> $a[2]);
    foreach ($rows as [$l, $c, $rev]) {
        echo '<tr><td>' . esc_html($l) . '</td><td class="num">' . (int) $c . '</td><td class="num"><strong>' . esc_html(mandala_fmt($rev)) . '</strong></td></tr>';
    }
    if (!$rows) {
        echo '<tr><td colspan="3">Ebben az időszakban még nincs kuponnal, automata levél után vagy csomagban leadott rendelés.</td></tr>';
    }
    echo '</tbody></table><p class="description">Levél: a vásárló a rendelés előtti 7 napban kapott automata levelet. Egy rendelés több sorban is szerepelhet.</p></div></div>';
    // AI, kereső, közösség, egészség
    echo '<div class="mo-grid mo-grid-4">'
        . $card('AI tanácsadó', (int) $d['chat']['conversations'] . ' beszélgetés', '', (int) $d['chat']['turns'] . ' kérdés · ' . (int) $d['chat']['handoff'] . ' átadás emberhez · kb. ' . number_format_i18n($d['chat']['cost_usd'], 2) . ' $')
        . $card('Kereső', number_format_i18n($d['search']['searches']) . ' keresés', '', ($d['search']['searches'] ? round($d['search']['zero'] / $d['search']['searches'] * 100) : 0) . '% eredménytelen → Mandala kereső')
        . $card('Levelek', number_format_i18n($d['mails_sent']) . ' kiküldve', '', (int) $d['newsletter'] . ' új feliratkozó')
        . $card('Értékelések', (int) $d['reviews']['n'] . ' új', '', $d['reviews']['n'] ? 'átlag ' . number_format_i18n($d['reviews']['avg'], 1) . ' csillag' : '')
        . $card('Partner jutalék', esc_html(mandala_fmt($d['commission'])), '', (int) $d['features']['partner'][0] . ' rendelés')
        . $card('Háttérfeladatok', $d['failed'] ? '<span style="color:#b42318">' . (int) $d['failed'] . ' hiba</span>' : 'rendben', '', 'Őrszem: Vezérlőpult')
        . '</div>';
    echo '<p class="description">Hétfő reggel ugyanez az előző 7 napról levélben megy (címzett: Mandala levelek → Beállítások → Heti tulajdonosi kivonat).</p></div>';
    echo '<style>.mandala-owner .mo-period{display:flex;gap:6px;align-items:center;flex-wrap:wrap}.mo-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0}.mo-grid-4{grid-template-columns:repeat(3,minmax(0,1fr))}'
        . '.mo-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:14px 16px;display:flex;flex-direction:column;gap:4px}.mo-card .mo-l{font-size:12px;color:#646970;text-transform:uppercase;letter-spacing:.05em}.mo-card strong{font-size:22px;line-height:1.2}.mo-card small{color:#646970}'
        . '.d{font-size:12px;font-weight:600}.d.up{color:#2E6A3E}.d.down{color:#b42318}.mo-panel{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:16px;margin:12px 0}.mo-panel h2{margin-top:0;font-size:15px}'
        . '.mo-chart{width:100%;height:auto}.mo-key{display:inline-block;width:10px;height:10px;border-radius:2px;vertical-align:middle}.mo-key-r{background:#A9581A}.mo-key-v{background:#51613F}.mo-cols{display:grid;grid-template-columns:1fr 1fr;gap:12px}'
        . '.mo-funnel{width:100%;border-collapse:collapse}.mo-funnel td{padding:8px 6px;border-bottom:1px solid #f0f0f1}.mo-funnel td:first-child{width:34%}.mo-funnel td:nth-child(2){width:46%}.mo-bar{display:block;height:10px;border-radius:5px;background:#A9581A}.num{text-align:right;white-space:nowrap}'
        . '@media(max-width:1100px){.mo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.mo-cols{grid-template-columns:1fr}}</style>';
}
