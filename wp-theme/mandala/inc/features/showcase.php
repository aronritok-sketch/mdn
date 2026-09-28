<?php
/**
 * Bemutató mód: minden marketingeszköz egyszerre, valós termékekkel – hogy a tulajdonos lássa, milyen a bolt
 * „teljes gőzzel”. Fejlesztői / teszt oldalra (pl. dev), nem éles boltba.
 *
 * Bekapcsoláskor (Kivonat → Bemutató mód, vagy: wp mandala showcase on):
 *  - futó kampány visszaszámlálóval és automatikus kuponnal (felső sáv + főoldali banner);
 *  - akciós termékek, ajándék értékhatár felett, csomagkedvezmények, füstölő-előfizetés;
 *  - kilépési / üdvözlő ablak, születésnapi kupon, ajánlás; bemutató partner (kód: JOGA10);
 *  - értékelések fotóval (főoldali vásárlói fotók), két esemény jeggyel, egy érkező szállítmány;
 *  - a Kivonat és a heti levél demóadatokat mutat (átkapcsolható a valósra).
 * BIZTONSÁG: amíg be van kapcsolva, MINDEN kimenő levél a megadott (admin) címre megy, így a teszt oldalon
 * lévő valós vásárlói címekre semmi nem jut ki.
 *
 * Kikapcsoláskor minden visszaáll: a beállítások a mentett állapotra, a létrehozott elemek törlődnek,
 * az árak és a készletjelzők az eredetire.
 */

defined('ABSPATH') || exit;

const MANDALA_SHOWCASE_OPTION = 'mandala_showcase';
const MANDALA_SHOWCASE_BACKUP = ['mandala_growth', 'mandala_campaigns', 'mandala_bundles', 'mandala_subs'];

function mandala_showcase(): array
{
    return wp_parse_args((array) get_option(MANDALA_SHOWCASE_OPTION, []), ['on' => false, 'mailto' => '', 'data' => 'demo']);
}
function mandala_showcase_on(): bool
{
    return !empty(mandala_showcase()['on']);
}

/* ---------- Bekapcsolás ---------- */

/** Népszerű, raktáron lévő, képes, cikkszámos egyszerű termékek – gyökérkategóriánként vegyítve. */
function mandala_showcase_products(int $limit = 24): array
{
    $ids = wc_get_products(['status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock', 'limit' => 120, 'orderby' => 'popularity', 'order' => 'DESC', 'return' => 'ids']);
    $groups = [];
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        if (!$p || !$p->is_visible() || $p->get_sku() === '' || (float) $p->get_price() <= 0 || $p->is_on_sale() || (function_exists('mandala_is_voucher') && mandala_is_voucher($p))) {
            continue;
        }
        $cats = $p->get_category_ids();
        $root = $cats ? (int) (get_ancestors($cats[0], 'product_cat')[count(get_ancestors($cats[0], 'product_cat')) - 1] ?? $cats[0]) : 0;
        $groups[$root][] = $p;
    }
    foreach ($groups as &$g) { // a képes termékek elöl
        usort($g, fn($a, $b) => (int) !$a->get_image_id() <=> (int) !$b->get_image_id());
    }
    unset($g);
    $out = [];
    while (count($out) < $limit && $groups) {
        foreach ($groups as $k => &$g) {
            if (!$g) {
                unset($groups[$k]);
                continue;
            }
            $out[] = array_shift($g);
        }
        unset($g);
    }
    return array_slice($out, 0, $limit);
}

function mandala_showcase_enable(string $mailto = ''): array
{
    if (mandala_showcase_on()) {
        return ['Már be van kapcsolva.'];
    }
    $log = [];
    $state = ['on' => true, 'at' => time(), 'mailto' => sanitize_email($mailto ?: (string) get_option('admin_email')), 'data' => 'demo',
        'backup' => [], 'posts' => [], 'users' => [], 'sale' => [], 'incoming' => []];
    foreach (MANDALA_SHOWCASE_BACKUP as $opt) {
        $state['backup'][$opt] = get_option($opt, null);
    }
    // A levélátirányítás az első lépés: innentől semmi nem megy ki valós vásárlónak.
    update_option(MANDALA_SHOWCASE_OPTION, $state, false);

    $pool = mandala_showcase_products(30);
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');

    // Kampány automatikus kuponnal
    $code = 'CSENDESHET';
    if (!wc_get_coupon_id_by_code($code)) {
        $c = new WC_Coupon();
        $c->set_code($code);
        $c->set_discount_type('percent');
        $c->set_amount(10);
        $c->set_description('Bemutató kampány');
        $c->set_date_expires(time() + 8 * DAY_IN_SECONDS);
        $c->update_meta_data('_mandala_showcase', '1');
        $state['posts'][] = $c->save();
    }
    $tz = wp_timezone();
    $campaigns = mandala_campaigns();
    array_unshift($campaigns, ['name' => 'Csendes hét (bemutató)', 'enabled' => 'yes', 'showcase' => '1',
        'start' => (new DateTime('-1 hour', $tz))->format('Y-m-d H:i'), 'end' => (new DateTime('+7 days', $tz))->format('Y-m-d') . ' 23:59',
        'bar' => 'Csendes hét: 10% kedvezmény minden kosárra, vasárnap éjfélig', 'eyebrow' => 'Csendes hét – 7 napig', 'title' => 'Egy hét, ami csak rólad szól',
        'text' => 'Hangtálak, füstölők és meditációs kellékek 10% kedvezménnyel – a kedvezmény magától érvényesül a kosárban.',
        'button' => 'Válogatás', 'url' => add_query_arg('szandek', 'csend', $shop), 'coupon' => $code, 'auto' => 'yes']);
    update_option('mandala_campaigns', $campaigns, false);
    $log[] = 'Kampány: „Csendes hét” visszaszámlálóval, 10% automatikus kuponnal (' . $code . ').';

    // Akciós termékek (8 db, -15%)
    foreach (array_slice($pool, 0, 8) as $p) {
        $state['sale'][$p->get_id()] = [(string) $p->get_sale_price('edit'), $p->get_date_on_sale_to('edit') ? $p->get_date_on_sale_to('edit')->getTimestamp() : null];
        $p->set_sale_price((string) (round((float) $p->get_regular_price() * 0.85 / 10) * 10));
        $p->save();
    }
    $log[] = 'Akció: ' . count($state['sale']) . ' termék -15%.';

    // Ajándék értékhatár felett: a legolcsóbb raktáron lévő termék
    $cheap = [];
    foreach ([['fustolok'], []] as $cat) { // ajándéknak elsősorban füstölő
        $cheap = array_values(array_filter(wc_get_products(['status' => 'publish', 'type' => 'simple', 'stock_status' => 'instock', 'limit' => 40, 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC',
            'meta_query' => [['key' => '_price', 'value' => 300, 'compare' => '>=', 'type' => 'NUMERIC'], ['key' => '_sku', 'value' => '', 'compare' => '!=']]] + ($cat && get_term_by('slug', $cat[0], 'product_cat') ? ['category' => $cat] : [])),
            fn($p) => $p->is_visible() && !(function_exists('mandala_is_voucher') && mandala_is_voucher($p))));
        if ($cheap) {
            break;
        }
    }
    $g = mandala_growth_settings();
    $g = array_merge($g, ['popup' => 'yes', 'popup_mode' => 'both', 'popup_delay' => 20, 'referral' => 'yes', 'birthday' => 'yes']);
    if ($cheap) {
        $g = array_merge($g, ['gwp' => 'yes', 'gwp_threshold' => 20000, 'gwp_sku' => $cheap[0]->get_sku(), 'gwp_label' => 'ajándék: ' . $cheap[0]->get_name()]);
        $log[] = 'Ajándék 20 000 Ft felett: ' . $cheap[0]->get_name() . '.';
    }
    update_option('mandala_growth', $g, false);
    $log[] = 'Üdvözlő / kilépési ablak, születésnapi kupon, ajánlás: be.';

    // Csomagkedvezmények (3 db, 2-3 termék, -10%)
    $bundles = array_values(array_filter((array) get_option('mandala_bundles', []), 'is_array'));
    $names = ['Csend-csomag', 'Rituálé-csomag', 'Ajándék-csomag'];
    $rest = array_slice($pool, 8);
    for ($i = 0; $i < 3 && count($rest) >= 2; $i++) {
        $items = array_splice($rest, 0, $i === 1 ? 3 : 2);
        $bundles[] = ['name' => $names[$i], 'skus' => array_map(fn($p) => $p->get_sku(), $items), 'percent' => 10, 'enabled' => 'yes', 'showcase' => '1'];
    }
    update_option('mandala_bundles', $bundles, false);
    $log[] = 'Csomagkedvezmény: ' . count(array_filter($bundles, fn($b) => !empty($b['showcase']))) . ' csomag -10%.';

    // Előfizetés: a meglévő kategóriák közül
    $s = mandala_sub_settings();
    $cats = array_values(array_filter(array_map('trim', explode(',', (string) $s['cats'])), fn($slug) => (bool) get_term_by('slug', $slug, 'product_cat')));
    if (!$cats && $cheap && ($t = get_the_terms($cheap[0]->get_id(), 'product_cat'))) {
        $cats = [$t[0]->slug];
    }
    update_option('mandala_subs', array_merge($s, ['enabled' => 'yes', 'cats' => implode(',', $cats)]), false);
    $log[] = 'Előfizetés: ' . ($cats ? implode(', ', $cats) : 'nincs illő kategória') . '.';

    // Értékelések fotóval
    $authors = ['Kata B.', 'Márton L.', 'Eszter N.', 'Dóra K.', 'Gábor S.', 'Anna V.', 'Réka T.', 'Zsófi H.'];
    $texts = ['Gyönyörű darab, a hangja betölti a szobát. Gondosan csomagolták, két nap alatt megérkezett.', 'Ajándékba vettem, nagy sikere volt. A leírás pontos, a fotók nem szépítenek.',
        'Minden este ezzel zárom a napot. Kellemes, nem tolakodó illat.', 'Kedves, gyors ügyintézés, és kaptam egy kis kártyát is a csomagban.',
        'A tanácsadó segített választani, pont olyat kaptam, amilyet szerettem volna.', 'Második rendelésem, ugyanolyan elégedett vagyok. Csak ajánlani tudom.',
        'Szebb élőben, mint a képen. A minőség kiváló.', 'Jógaórán használjuk, a tanítványok is rákérdeztek, honnan van.'];
    foreach (array_slice($pool, 0, 8) as $i => $p) {
        $id = wp_insert_post(['post_type' => 'mandala_review', 'post_status' => 'publish', 'post_title' => $p->get_name() . ' – ' . $authors[$i], 'post_content' => $texts[$i],
            'post_date' => wp_date('Y-m-d H:i:s', time() - ($i + 1) * 2 * DAY_IN_SECONDS)]);
        if ($id && !is_wp_error($id)) {
            foreach (['_product' => function_exists('mandala_original_id') ? mandala_original_id($p->get_id()) : $p->get_id(), '_rating' => $i % 4 === 3 ? 4 : 5, '_author' => $authors[$i], '_verified' => '1', '_mandala_showcase' => '1'] as $k => $v) {
                update_post_meta($id, $k, $v);
            }
            if ($i < 5 && $p->get_image_id()) {
                update_post_meta($id, '_photos', [(int) $p->get_image_id()]);
                $photos = ($photos ?? 0) + 1;
            }
            $state['posts'][] = $id;
            $reviews = ($reviews ?? 0) + 1;
        }
    }
    $log[] = 'Értékelések: ' . ($reviews ?? 0) . ' db, ebből ' . ($photos ?? 0) . ' fotóval (főoldali vásárlói fotók).';

    // Események jeggyel
    if (post_type_exists('mandala_event') && function_exists('mandala_sync_event_ticket')) {
        foreach ([['Hangfürdő a bemutatóteremben', 9, '19:00', 90, 12, 6900, 'Egy óra elengedés tibeti és kovácsolt hangtálakkal, gongokkal. Matracot és takarót biztosítunk.'],
            ['Hangtál-workshop kezdőknek', 23, '10:00', 180, 8, 14900, 'Megtanulod megszólaltatni és tisztán tartani a hangtálat, és kiválasztod a hozzád illőt.']] as [$title, $days, $time, $min, $cap, $price, $ex]) {
            if (get_posts(['post_type' => 'mandala_event', 'title' => $title, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids'])) {
                continue; // már van ilyen esemény
            }
            $id = wp_insert_post(['post_type' => 'mandala_event', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $ex,
                'post_content' => "<!-- wp:paragraph -->\n<p>{$ex}</p>\n<!-- /wp:paragraph -->"]);
            if (!$id || is_wp_error($id)) {
                continue;
            }
            $start = (new DateTimeImmutable('now', $tz))->modify("+{$days} days")->setTime(...array_map('intval', explode(':', $time)));
            foreach (['_mandala_event_start' => $start->format('Y-m-d\TH:i'), '_mandala_event_end' => $start->modify("+{$min} minutes")->format('Y-m-d\TH:i'),
                '_mandala_event_place' => 'Mandala bemutatóterem', '_mandala_event_capacity' => (string) $cap, '_mandala_event_price' => (string) $price, '_mandala_showcase' => '1'] as $k => $v) {
                update_post_meta($id, $k, $v);
            }
            $ticket = mandala_sync_event_ticket($id);
            $state['posts'][] = $id;
            if ($ticket) {
                $ticket->update_meta_data('_mandala_showcase', '1');
                $ticket->save();
                $state['posts'][] = $ticket->get_id();
            }
        }
        $log[] = 'Események: hangfürdő és workshop, jeggyel.';
    }

    // Érkező szállítmány: egy elfogyott termék előrendelhető lesz
    $out = wc_get_products(['status' => 'publish', 'type' => 'simple', 'stock_status' => 'outofstock', 'limit' => 20, 'orderby' => 'popularity', 'return' => 'objects']);
    foreach ($out as $p) {
        if ($p->get_image_id() && !$p->get_meta('_mandala_incoming')) {
            $state['incoming'][$p->get_id()] = $p->get_backorders('edit');
            $p->set_backorders('notify');
            $p->update_meta_data('_mandala_incoming', wp_date('Y-m-d', strtotime('+21 days')));
            $p->update_meta_data('_mandala_incoming_from', 'Nepálból');
            $p->save();
            $log[] = 'Érkező szállítmány: ' . $p->get_name() . ' előrendelhető.';
            break;
        }
    }

    // Bemutató partner
    if (function_exists('mandala_partner_add')) {
        $email = 'partner.bemutato@example.com';
        $existed = (bool) get_user_by('email', $email);
        $uid = mandala_partner_add($email, 'JOGA10', 'Bemutató jógastúdió', 10, 10);
        if ($uid && !$existed) {
            $state['users'][] = $uid;
        }
        $log[] = 'Partnerprogram: bemutató partner, kód: JOGA10 (link: ?partner=JOGA10).';
    }

    update_option(MANDALA_SHOWCASE_OPTION, $state, false);
    mandala_showcase_flush();
    $log[] = 'Kivonat és heti levél: demóadatok. Minden levél ide megy: ' . $state['mailto'] . '.';
    return $log;
}

/* ---------- Kikapcsolás ---------- */

function mandala_showcase_disable(): array
{
    $state = mandala_showcase();
    if (empty($state['on'])) {
        return ['Nincs bekapcsolva.'];
    }
    foreach ((array) ($state['backup'] ?? []) as $opt => $val) {
        $val === null ? delete_option($opt) : update_option($opt, $val, false);
    }
    foreach ((array) ($state['sale'] ?? []) as $pid => [$sale, $to]) {
        if ($p = wc_get_product((int) $pid)) {
            $p->set_sale_price($sale);
            $p->set_date_on_sale_to($to);
            $p->save();
        }
    }
    foreach ((array) ($state['incoming'] ?? []) as $pid => $backorders) {
        if ($p = wc_get_product((int) $pid)) {
            $p->set_backorders($backorders ?: 'no');
            $p->delete_meta_data('_mandala_incoming');
            $p->delete_meta_data('_mandala_incoming_from');
            $p->save();
        }
    }
    $n = 0;
    foreach ((array) ($state['posts'] ?? []) as $id) {
        if (get_post_meta((int) $id, '_mandala_showcase', true) === '1' && wp_delete_post((int) $id, true)) {
            $n++;
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ((array) ($state['users'] ?? []) as $uid) {
        wp_delete_user((int) $uid);
    }
    delete_option(MANDALA_SHOWCASE_OPTION);
    mandala_showcase_flush();
    return ['Visszaállítva: beállítások, árak, készletjelzők; ' . $n . ' bemutató elem törölve.'];
}

function mandala_showcase_flush(): void
{
    if (function_exists('mandala_flush_index')) {
        mandala_flush_index();
    }
    wc_delete_product_transients();
    wp_cache_flush();
    do_action('litespeed_purge_all');
    if (function_exists('rocket_clean_domain')) {
        rocket_clean_domain();
    }
    if (function_exists('wp_cache_clear_cache')) {
        wp_cache_clear_cache();
    }
}

/* ---------- Biztonság: levelek átirányítása ---------- */

add_filter('wp_mail', function (array $args) {
    $s = mandala_showcase();
    if (empty($s['on'])) {
        return $args;
    }
    $to = implode(', ', (array) $args['to']);
    $args['to'] = $s['mailto'] ?: get_option('admin_email');
    $args['subject'] = '[BEMUTATÓ → ' . $to . '] ' . $args['subject'];
    return $args;
}, 999);

/* ---------- Kivonat: demóadatok ---------- */

add_filter('mandala_owner_data_pre', function ($pre, int $days, int $end) {
    $s = mandala_showcase();
    if (empty($s['on']) || ($s['data'] ?? 'demo') !== 'demo' || (is_admin() && ($_GET['adatok'] ?? '') === 'valos')) { // phpcs:ignore
        return $pre;
    }
    return mandala_showcase_owner_data($days, $end);
}, 10, 3);

/** Élethű, napról napra ugyanazt adó demóadatok (a bekapcsolás előtti időszak „régi bolt”, utána „új”). */
function mandala_showcase_owner_data(int $days, int $end): array
{
    $days = max(1, $days);
    $start = (int) strtotime(wp_date('Y-m-d', $end - ($days - 1) * DAY_IN_SECONDS) . ' 00:00:00 ' . wp_timezone_string());
    $day = function (int $ts, bool $cur) {
        mt_srand(crc32('mandala-' . wp_date('Y-m-d', $ts)));
        $v = (int) round((mt_rand(170, 260) + ((int) wp_date('N', $ts) >= 6 ? 60 : 0)) * ($cur ? 1.12 : 1.0));
        $o = (int) round($v * ($cur ? mt_rand(20, 25) : mt_rand(16, 20)) / 1000);
        $aov = ($cur ? 17800 : 16400) * mt_rand(88, 112) / 100;
        return ['v' => $v, 'o' => $o, 'r' => round($o * $aov)];
    };
    $cur = ['v' => 0, 'o' => 0, 'r' => 0];
    $prev = $cur;
    $daily = [];
    for ($d = $start; $d <= $end; $d += DAY_IN_SECONDS) {
        $x = $day($d, true);
        $daily[wp_date('Y-m-d', $d)] = ['revenue' => (float) $x['r'], 'orders' => $x['o'], 'visitors' => $x['v']];
        foreach ($x as $k => $n) {
            $cur[$k] += $n;
        }
    }
    for ($d = $start - $days * DAY_IN_SECONDS; $d < $start; $d += DAY_IN_SECONDS) {
        foreach ($day($d, false) as $k => $n) {
            $prev[$k] += $n;
        }
    }
    mt_srand();
    $aov = $cur['o'] ? $cur['r'] / $cur['o'] : 0;
    $share = fn(float $p, float $f = 1.0) => ['n' => (int) round($cur['o'] * $p), 'revenue' => round($cur['o'] * $p) * $aov * $f];
    $pair = fn(float $p, float $f = 1.0) => array_values($share($p, $f));
    $carts = (int) round($cur['v'] * 0.092);
    return [
        'days' => $days, 'from' => wp_date('Y-m-d', $start), 'to' => wp_date('Y-m-d', $end),
        'cur' => ['orders' => $cur['o'], 'revenue' => (float) $cur['r'], 'aov' => $aov, 'new' => (int) round($cur['o'] * 0.55), 'returning' => (int) round($cur['o'] * 0.37)],
        'prev' => ['orders' => $prev['o'], 'revenue' => (float) $prev['r'], 'aov' => $prev['o'] ? $prev['r'] / $prev['o'] : 0],
        'traffic' => ['visitors' => $cur['v'], 'views' => (int) round($cur['v'] * 3.3), 'product_views' => (int) round($cur['v'] * 1.7), 'carts' => $carts, 'add_to_cart' => (int) round($carts * 1.35),
            'checkouts' => max($cur['o'], (int) round($carts * 0.46)), 'conversion' => $cur['v'] ? $cur['o'] / $cur['v'] * 100 : 0,
            'prev_visitors' => $prev['v'], 'prev_conversion' => $prev['v'] ? $prev['o'] / $prev['v'] * 100 : 0],
        'sources' => ['campaign' => $share(0.14), 'welcome' => $share(0.07), 'partner' => $share(0.03), 'birthday' => $share(0.02, 1.1), 'referral' => $share(0.02)],
        'mail' => ['abandoned' => $share(0.05, 1.05), 'reorder' => $share(0.04, 0.6), 'browse' => $share(0.03), 'crosssell' => $share(0.02), 'price_drop' => $share(0.01)],
        'mails_sent' => $days * 55,
        'features' => ['bundle' => $pair(0.06, 1.4), 'gift' => $pair(0.18, 1.5), 'sub_first' => $pair(0.02, 0.3), 'sub_renewal' => $pair(0.04, 0.3), 'partner' => $pair(0.03)],
        'commission' => round($cur['o'] * 0.03) * $aov * 0.1,
        'subs' => ['active' => 34, 'mrr' => 153000.0],
        'chat' => ['conversations' => $days * 9, 'turns' => $days * 36, 'handoff' => (int) round($days * 0.4), 'cost_usd' => round($days * 9 * 0.012, 2)],
        'search' => ['searches' => (int) round($cur['v'] * 0.22), 'zero' => (int) round($cur['v'] * 0.22 * 0.03)],
        'reviews' => ['n' => (int) round($days * 0.6), 'avg' => 4.8], 'newsletter' => $days * 3,
        'speed' => ['ms' => 280, 'samples' => $days * 140], 'failed' => 0,
        'daily' => $daily, 'demo' => true,
    ];
}

/* ---------- Admin ---------- */

add_action('admin_menu', function () {
    add_submenu_page('mandala-owner', 'Bemutató mód', 'Bemutató mód', 'manage_woocommerce', 'mandala-showcase', 'mandala_showcase_page');
}, 20);

function mandala_showcase_page(): void
{
    $log = [];
    if (!empty($_POST['mandala_showcase']) && check_admin_referer('mandala_showcase')) {
        $log = $_POST['mandala_showcase'] === 'on' ? mandala_showcase_enable(sanitize_email(wp_unslash($_POST['mailto'] ?? ''))) : mandala_showcase_disable(); // phpcs:ignore
    }
    $s = mandala_showcase();
    echo '<div class="wrap"><h1>Bemutató mód</h1>';
    if ($log) {
        echo '<div class="notice notice-success"><ul style="list-style:disc;padding-left:20px">' . implode('', array_map(fn($l) => '<li>' . esc_html($l) . '</li>', $log)) . '</ul></div>';
    }
    echo '<p style="max-width:760px">Egy kattintással bekapcsol minden marketingeszközt a valós termékekkel: futó kampány visszaszámlálóval és kuponnal, akciós termékek, ajándék értékhatár felett, csomagkedvezmények, füstölő-előfizetés, kilépési ablak, értékelések fotóval, események, érkező szállítmány, bemutató partner. A Kivonat és a heti levél demóadatokat mutat. Kikapcsoláskor minden visszaáll az eredetire.</p>';
    echo '<p style="max-width:760px"><strong>Csak teszt / dev oldalon használd.</strong> Amíg be van kapcsolva, minden kimenő levél (a rendelési levelek is) az alábbi címre megy, így valós vásárló nem kap levelet.</p>';
    echo '<form method="post">';
    wp_nonce_field('mandala_showcase');
    if (empty($s['on'])) {
        echo '<p><label>Minden levél ide: <input type="email" name="mailto" class="regular-text" value="' . esc_attr((string) get_option('admin_email')) . '"></label></p>';
        echo '<p><button class="button button-primary button-hero" name="mandala_showcase" value="on">Bemutató mód bekapcsolása</button></p>';
    } else {
        echo '<p><strong style="color:#0a7c2f">Be van kapcsolva</strong> (' . esc_html(wp_date('Y. m. d. H:i', (int) $s['at'])) . ' óta). Levelek ide: ' . esc_html((string) $s['mailto']) . '</p>';
        echo '<p><a class="button" href="' . esc_url(home_url('/')) . '" target="_blank">Megnézem a boltot</a> <a class="button" href="' . esc_url(admin_url('admin.php?page=mandala-owner')) . '">Kivonat (demóadatok)</a></p>';
        echo '<p><button class="button button-hero" name="mandala_showcase" value="off" onclick="return confirm(\'Minden bemutató elem törlődik, a beállítások visszaállnak. Mehet?\')">Kikapcsolás és visszaállítás</button></p>';
    }
    echo '</form></div>';
}

add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (mandala_showcase_on() && current_user_can('manage_woocommerce')) {
        $bar->add_node(['id' => 'mandala-showcase', 'title' => '<span style="background:#E2B77A;color:#16120F;padding:2px 8px;border-radius:9px;font-weight:600">BEMUTATÓ MÓD</span>',
            'href' => admin_url('admin.php?page=mandala-showcase')]);
    }
}, 100);

add_action('admin_notices', function () {
    if (mandala_showcase_on() && current_user_can('manage_woocommerce') && ($_GET['page'] ?? '') !== 'mandala-showcase') { // phpcs:ignore
        echo '<div class="notice notice-warning"><p><strong>Bemutató mód be van kapcsolva</strong> – minden levél a(z) ' . esc_html((string) mandala_showcase()['mailto']) . ' címre megy. <a href="' . esc_url(admin_url('admin.php?page=mandala-showcase')) . '">Kikapcsolás</a></p></div>';
    }
});

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('mandala showcase', function ($args, $assoc) {
        $log = ($args[0] ?? '') === 'off' ? mandala_showcase_disable() : mandala_showcase_enable((string) ($assoc['mailto'] ?? ''));
        foreach ($log as $l) {
            WP_CLI::log($l);
        }
        WP_CLI::success('Kész.');
    });
}
