<?php
/**
 * Heti tulajdonosi kivonat levél a boltnak (a KPI-k: owner.php) – hétfő reggel, admin felület nézegetése nélkül.
 *
 * Forgalom (az előző héthez képest), top termékek, mi fogy ki az eladási ütem alapján (utánrendelési
 * javaslattal), eredménytelen keresések, miről kérdezték az AI tanácsadót, visszaküldések, jóváhagyásra
 * váró értékelések és új termékek, hibás háttérfeladatok. Ha van API-kulcs, a Claude 3–4 pontban
 * összefoglalja, mire érdemes figyelni. Címzett és be/ki: Mandala levelek → Beállítások.
 */

defined('ABSPATH') || exit;

function mandala_report_settings(): array
{
    return wp_parse_args((array) get_option('mandala_report', []), ['enabled' => 'yes', 'to' => '', 'ai' => 'yes']);
}

mandala_recurring('mandala_weekly_report', WEEK_IN_SECONDS, fn() => strtotime('next monday 07:30', current_time('timestamp')) - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
add_action('mandala_weekly_report', function () {
    if (mandala_report_settings()['enabled'] === 'yes') {
        mandala_report_send();
    }
});

/** Rendelések tételei közvetlenül a tétel-táblákból (HPOS-szal és a régi tárolással is ugyanazok). */
function mandala_order_lines(array $order_ids): array
{
    global $wpdb;
    $lines = [];
    foreach (array_chunk(array_map('intval', $order_ids), 1000) as $chunk) {
        $in = implode(',', $chunk);
        $rows = $wpdb->get_results("SELECT oi.order_id, oi.order_item_name AS name,
                MAX(CASE WHEN m.meta_key = '_product_id' THEN m.meta_value END) AS product_id,
                MAX(CASE WHEN m.meta_key = '_variation_id' THEN m.meta_value END) AS variation_id,
                MAX(CASE WHEN m.meta_key = '_qty' THEN m.meta_value END) AS qty
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta m ON m.order_item_id = oi.order_item_id AND m.meta_key IN ('_product_id', '_variation_id', '_qty')
            WHERE oi.order_item_type = 'line_item' AND oi.order_id IN ($in)
            GROUP BY oi.order_item_id", ARRAY_A); // phpcs:ignore
        foreach ($rows as $r) {
            $lines[] = ['order_id' => (int) $r['order_id'], 'name' => (string) $r['name'], 'product_id' => (int) $r['product_id'], 'variation_id' => (int) $r['variation_id'], 'qty' => (int) $r['qty']];
        }
    }
    return $lines;
}

/** E-mail címenként az első rendelés időpontja (egy lekérdezés; HPOS és régi tárolás). */
function mandala_first_order_dates(array $emails): array
{
    global $wpdb;
    if (!$emails) {
        return [];
    }
    $in = implode(',', array_map(fn($e) => $wpdb->prepare('%s', $e), $emails));
    $hpos = class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    $sql = $hpos
        ? "SELECT LOWER(billing_email) AS email, MIN(date_created_gmt) AS first FROM {$wpdb->prefix}wc_orders WHERE type = 'shop_order' AND billing_email IN ($in) GROUP BY LOWER(billing_email)"
        : "SELECT LOWER(pm.meta_value) AS email, MIN(p.post_date_gmt) AS first FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_billing_email' WHERE p.post_type = 'shop_order' AND pm.meta_value IN ($in) GROUP BY LOWER(pm.meta_value)";
    $out = [];
    foreach ($wpdb->get_results($sql) as $r) { // phpcs:ignore
        $out[$r->email] = strtotime($r->first . ' UTC');
    }
    return $out;
}

/** A riport adatai (időszak: az utolsó 7 nap, összevetve az előzővel). */
function mandala_report_data(): array
{
    global $wpdb;
    $now = time();
    $sum = function (int $from, int $to) {
        $orders = wc_get_orders(['status' => ['processing', 'completed', 'on-hold'], 'date_created' => $from . '...' . $to, 'limit' => -1, 'type' => 'shop_order']);
        $rev = array_sum(array_map(fn($o) => (float) $o->get_total() - (float) $o->get_total_refunded(), $orders));
        return ['orders' => $orders, 'n' => count($orders), 'revenue' => $rev, 'aov' => $orders ? $rev / count($orders) : 0];
    };
    $cur = $sum($now - 7 * DAY_IN_SECONDS, $now);
    $prev = $sum($now - 14 * DAY_IN_SECONDS, $now - 7 * DAY_IN_SECONDS);
    // Tételek és új vásárlók közvetlen SQL-lel (rendelésenkénti lekérdezés helyett – élesben több
    // tízezer rendelésnél a régi megoldás percekig terhelte volna az adatbázist).
    $cur_ids = array_map(fn($o) => $o->get_id(), $cur['orders']);
    $top = [];
    foreach (mandala_order_lines($cur_ids) as $line) {
        $top[$line['name']] = ($top[$line['name']] ?? 0) + $line['qty'];
    }
    arsort($top);
    $emails = array_unique(array_filter(array_map(fn($o) => strtolower($o->get_billing_email()), $cur['orders'])));
    $first = mandala_first_order_dates($emails);
    $new_customers = count(array_filter($emails, fn($e) => ($first[$e] ?? 0) >= $now - 7 * DAY_IN_SECONDS));
    // Eladási ütem (30 nap) → hány napra elég a készlet.
    $sold = [];
    $ids30 = wc_get_orders(['status' => ['processing', 'completed'], 'date_created' => '>' . ($now - 30 * DAY_IN_SECONDS), 'limit' => -1, 'return' => 'ids', 'type' => 'shop_order']);
    foreach (mandala_order_lines($ids30) as $line) {
        $pid = $line['variation_id'] ?: $line['product_id'];
        $sold[$pid] = ($sold[$pid] ?? 0) + $line['qty'];
    }
    $runout = [];
    foreach ($sold as $pid => $qty) {
        $p = wc_get_product($pid);
        if (!$p || !$p->managing_stock()) {
            continue;
        }
        $stock = (int) $p->get_stock_quantity();
        $per_day = $qty / 30;
        $days = $per_day > 0 ? $stock / $per_day : 999;
        if ($days <= 21) {
            $runout[] = ['name' => $p->get_name(), 'sku' => $p->get_sku(), 'stock' => $stock, 'days' => (int) floor($days), 'suggest' => (int) ceil($per_day * 60)];
        }
    }
    usort($runout, fn($a, $b) => $a['days'] <=> $b['days']);
    $zero = [];
    if (function_exists('mandala_search_table')) {
        $zero = $wpdb->get_results($wpdb->prepare('SELECT term, searches FROM ' . mandala_search_table() . ' WHERE last_results = 0 AND last_seen > %s ORDER BY searches DESC LIMIT 6', gmdate('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS)), ARRAY_A) ?: [];
    }
    $questions = [];
    if (function_exists('mandala_chat_table') && get_option('mandala_chat_db')) {
        foreach ($wpdb->get_col($wpdb->prepare('SELECT messages FROM ' . mandala_chat_table() . ' WHERE started > %s ORDER BY started DESC LIMIT 60', gmdate('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS))) as $json) {
            $m = json_decode((string) $json, true);
            if (!empty($m[0]['text'])) {
                $questions[] = mb_substr($m[0]['text'], 0, 140);
            }
        }
    }
    $returns = 0;
    foreach (wc_get_orders(['limit' => 50, 'meta_key' => '_mandala_returns', 'meta_compare' => 'EXISTS', 'type' => 'shop_order']) as $o) {
        foreach (is_array($v = $o->get_meta('_mandala_returns')) ? $v : [] as $r) {
            $returns += ($r['status'] ?? '') === 'requested' ? 1 : 0;
        }
    }
    $failed = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['group' => MANDALA_AS_GROUP, 'status' => ActionScheduler_Store::STATUS_FAILED, 'date' => gmdate('Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS), 'date_compare' => '>=', 'per_page' => 100], 'ids')) : 0;
    return [
        'cur' => ['n' => $cur['n'], 'revenue' => $cur['revenue'], 'aov' => $cur['aov']], 'prev' => ['n' => $prev['n'], 'revenue' => $prev['revenue'], 'aov' => $prev['aov']],
        'new_customers' => $new_customers, 'top' => array_slice($top, 0, 5, true), 'runout' => array_slice($runout, 0, 10), 'zero' => $zero, 'questions' => array_slice($questions, 0, 12),
        'returns' => $returns, 'reviews' => (int) wp_count_posts('mandala_review')->pending, 'drafts' => (int) wp_count_posts('product')->draft, 'failed' => $failed,
    ];
}

function mandala_report_html(array $d, string $ai = ''): string
{
    $pct = fn($a, $b) => $b > 0 ? sprintf('%+d%%', round(($a - $b) / $b * 100)) : '–';
    $cell = 'style="padding:8px 12px;border-bottom:1px solid #EEE7DB"';
    $h = '<table role="presentation" style="width:100%;border-collapse:collapse;margin:0 0 16px">'
        . '<tr><td ' . $cell . '>Rendelés</td><td ' . $cell . ' align="right"><strong>' . (int) $d['cur']['n'] . '</strong> <span style="color:#6E6357">(' . $pct($d['cur']['n'], $d['prev']['n']) . ')</span></td></tr>'
        . '<tr><td ' . $cell . '>Bevétel</td><td ' . $cell . ' align="right"><strong>' . esc_html(mandala_fmt($d['cur']['revenue'])) . '</strong> <span style="color:#6E6357">(' . $pct($d['cur']['revenue'], $d['prev']['revenue']) . ')</span></td></tr>'
        . '<tr><td ' . $cell . '>Átlagos kosár</td><td ' . $cell . ' align="right">' . esc_html(mandala_fmt($d['cur']['aov'])) . '</td></tr>'
        . '<tr><td ' . $cell . '>Új vásárló</td><td ' . $cell . ' align="right">' . (int) $d['new_customers'] . '</td></tr></table>';
    if ($ai !== '') {
        $h .= '<h2 style="font-size:17px;margin:24px 0 8px">Mire figyelj</h2><div style="background:#F6F1E8;border-radius:10px;padding:12px 16px">' . wpautop(esc_html($ai)) . '</div>';
    }
    $list = function (string $title, array $items) {
        return $items ? '<h2 style="font-size:17px;margin:24px 0 8px">' . esc_html($title) . '</h2><ul style="margin:0;padding-left:20px">' . implode('', array_map(fn($i) => '<li>' . $i . '</li>', $items)) . '</ul>' : '';
    };
    $h .= $list('Legtöbbet eladott', array_map(fn($n, $q) => esc_html($n) . ' – ' . (int) $q . ' db', array_keys($d['top']), $d['top']));
    $h .= $list('Hamarosan kifogy (utánrendelési javaslat 60 napra)', array_map(fn($r) => esc_html($r['name']) . ($r['sku'] ? ' <span style="color:#6E6357">' . esc_html($r['sku']) . '</span>' : '') . ' – ' . (int) $r['stock'] . ' db, kb. ' . (int) $r['days'] . ' napra elég → <strong>' . (int) $r['suggest'] . ' db</strong>', $d['runout']));
    $h .= $list('Keresték, de nem találták', array_map(fn($z) => '„' . esc_html($z['term']) . '” – ' . (int) $z['searches'] . '×', $d['zero']));
    $h .= $list('Ezt kérdezték az AI tanácsadótól', array_map('esc_html', $d['questions']));
    $todo = array_filter([
        $d['returns'] ? (int) $d['returns'] . ' visszaküldési kérés vár (WooCommerce → Visszaküldések)' : '',
        $d['reviews'] ? (int) $d['reviews'] . ' értékelés vár jóváhagyásra' : '',
        $d['drafts'] ? (int) $d['drafts'] . ' termék piszkozatban (Termékek → Új termékek)' : '',
        $d['failed'] ? '<strong style="color:#B42318">' . (int) $d['failed'] . ' háttérfeladat hibára futott</strong> (WooCommerce → Állapot → Ütemezett műveletek)' : '',
    ]);
    $h .= $list('Teendők', array_map(fn($t) => $t, $todo));
    return $h . mandala_mail_button(admin_url('admin.php?page=wc-admin'), 'Részletek az adminban');
}

/** Rövid AI összefoglaló a számokból (ha van kulcs). */
function mandala_report_ai(array $d): string
{
    if (mandala_report_settings()['ai'] !== 'yes' || !function_exists('mandala_ai_ready') || !mandala_ai_ready()) {
        return '';
    }
    $data = mandala_claude_request([
        'model' => MANDALA_CLAUDE_DEFAULT_MODEL,
        'max_tokens' => 2000,
        'output_config' => ['effort' => 'low'],
        'messages' => [['role' => 'user', 'content' => "Egy kézműves spirituális webáruház (hangtálak, füstölők, ajándékok) heti adatai JSON-ban. Írj 3–4 rövid, konkrét magyar mondatot a tulajdonosnak: mi a legfontosabb változás, mit rendeljen utána, mire keresnek eredménytelenül (érdemes lehet beszerezni vagy szinonimát felvenni), és egy teendőt. Csak az adatokból dolgozz, ne találj ki számot. Felsorolásjelek nélkül, sima szövegként.\n\n" . wp_json_encode($d, JSON_UNESCAPED_UNICODE)]],
    ], 90);
    return is_wp_error($data) ? '' : mandala_claude_text($data);
}

function mandala_report_send(string $to = ''): bool
{
    $s = mandala_report_settings();
    $to = $to ?: ($s['to'] ?: (string) (mandala_config('contact', [])['email'] ?? get_option('admin_email')));
    $d = mandala_report_data();
    $html = mandala_report_html($d, mandala_report_ai($d));
    if (function_exists('mandala_owner_email_html')) { // a tulajdonosi kivonat (bevétel, konverzió, források, sebesség) kerül a levél elejére
        $html = mandala_owner_email_html(mandala_owner_data(7)) . $html;
    }
    return mandala_send_mail($to, sprintf('[%s] Heti tulajdonosi kivonat – %s', get_bloginfo('name'), wp_date('Y. m. d.')), 'Heti tulajdonosi kivonat', $html, false, ['type' => 'belso']);
}

/* ---------- Beállítás a levélközpontban ---------- */

add_action('mandala_mail_settings_fields', function () {
    $s = mandala_report_settings();
    echo '<tr><th scope="row">Heti tulajdonosi kivonat</th><td><label><input type="checkbox" name="mandala_report[enabled]" value="1"' . checked($s['enabled'], 'yes', false) . '> hétfő reggel levélben</label> '
        . '<input type="email" name="mandala_report[to]" value="' . esc_attr($s['to']) . '" placeholder="' . esc_attr((string) (mandala_config('contact', [])['email'] ?? '')) . '" class="regular-text" aria-label="Címzett">'
        . '<p><label><input type="checkbox" name="mandala_report[ai]" value="1"' . checked($s['ai'], 'yes', false) . '> AI összefoglaló („Mire figyelj”)</label> · <label><input type="checkbox" name="mandala_report[now]" value="1"> küldés most (próba)</label></p>'
        . '<p class="description">Bevétel az előző héthez képest, látogató → kosár → rendelés konverzió, mi hozta a bevételt (levelek, kuponok, csomagok, előfizetés, partnerek), AI tanácsadó, sebesség – a részletek: Kivonat menü. Utána: top termékek, mi fogy ki (utánrendelési javaslattal), eredménytelen keresések, AI kérdések, teendők.</p></td></tr>';
});
add_action('mandala_mail_settings_save', function () {
    $in = (array) wp_unslash($_POST['mandala_report'] ?? []);
    update_option('mandala_report', ['enabled' => empty($in['enabled']) ? 'no' : 'yes', 'to' => sanitize_email($in['to'] ?? ''), 'ai' => empty($in['ai']) ? 'no' : 'yes'], false);
    if (!empty($in['now'])) {
        mandala_report_send();
    }
});
