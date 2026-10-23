<?php
/**
 * A Partner CRM összekötő átvitele (wp-theme/mandala/inc/features/crm-connect.php) – WordPress nélkül, kis
 * helyettesítőkkel. Futtatás:  php tests/crm-connect-transport.php
 *
 * Ellenőrzi: protokoll v2 (Z = base64url(gzdeflate(BODY, 9)) oda-vissza, a CRM-mel azonos kibontás és aláírás – a CRM
 * hpv_pt_verify() algoritmusa ide másolva), a GET mérete (Z ≤ 5500) és a mohó csomagolás, a túl nagy készlet-esemény
 * szétvágása (full_start / full_end marad), a régi POST tartalék (túl nagy egyedi esemény, régi CRM), a nyugták csak
 * sikeres válasz után törlődnek, a mérgezett esemény 3 futás után félre kerül (a leállt CRM-nél nem), a hibaszöveg
 * (ki válaszolt), a zár, a készlet 40-es adagokban és a teljes küldéstől függetlenül, a teljes küldés hibatűrése,
 * a tartalék ütemező (oldalbetöltés vége), az őrszem.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) {
    if (error_reporting() & $no) {
        throw new ErrorException($str, 0, $no, $file, $line);
    }
    return false;
});

define('ABSPATH', __DIR__ . '/');
const MINUTE_IN_SECONDS = 60;
const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;
const ARRAY_A = 'ARRAY_A';
const MANDALA_AS_GROUP = 'mandala';

/* ---------- Helyettesítők ---------- */

$GLOBALS['opts'] = [];
$GLOBALS['hooks'] = [];
$GLOBALS['http_log'] = [];
$GLOBALS['http'] = null; // fn(string $method, string $url, array $args): array|WP_Error
$GLOBALS['is_admin'] = false;
$GLOBALS['users'] = [];
$GLOBALS['index'] = [];

class WP_Error
{
    public $code;
    public $message;
    public $data;
    public function __construct($code = '', $message = '', $data = '')
    {
        [$this->code, $this->message, $this->data] = [$code, $message, $data];
    }
    public function get_error_message()
    {
        return $this->message;
    }
    public function get_error_data()
    {
        return $this->data;
    }
}
function is_wp_error($x)
{
    return $x instanceof WP_Error;
}
function get_option($k, $def = false)
{
    return array_key_exists($k, $GLOBALS['opts']) ? $GLOBALS['opts'][$k] : $def;
}
function update_option($k, $v, $autoload = null)
{
    // Mint a valódi: szerializálva tárol (az ujjlenyomatnak oda-vissza ugyanannak kell lennie).
    $GLOBALS['opts'][$k] = unserialize(serialize($v));
    return true;
}
function delete_option($k)
{
    unset($GLOBALS['opts'][$k]);
    return true;
}
function wp_cache_delete($k, $g = '')
{
    return true;
}
function wp_json_encode($d, $flags = 0)
{
    return json_encode($d, $flags);
}
function add_action($tag, $cb, $prio = 10, $args = 1)
{
    $GLOBALS['hooks'][$tag][] = $cb;
}
function add_filter($tag, $cb, $prio = 10, $args = 1)
{
    $GLOBALS['hooks'][$tag][] = $cb;
}
function apply_filters($tag, $value, ...$args)
{
    return $tag === 'mandala_crm_pause_ms' ? 0 : $value;
}
function mandala_recurring($hook, $interval, $first)
{
}
function esc_url_raw($u)
{
    return $u;
}
function esc_sql($s)
{
    return addslashes($s);
}
function wp_date($f, $ts = null)
{
    return date($f, $ts ?? time());
}
function wp_timezone()
{
    return new DateTimeZone('Europe/Budapest');
}
function wp_doing_ajax()
{
    return false;
}
function wp_doing_cron()
{
    return false;
}
function is_admin()
{
    return $GLOBALS['is_admin'];
}
function get_users($a)
{
    if (!empty($GLOBALS['on_get_users'])) {
        ($GLOBALS['on_get_users'])();
    }
    return array_slice($GLOBALS['users'], (int) $a['offset'], (int) $a['number']);
}
function get_userdata($uid)
{
    if ($uid === 2) {
        throw new RuntimeException('hibás fiókadat');
    }
    return (object) ['ID' => $uid, 'user_email' => "u$uid@example.com", 'user_registered' => '2026-01-01 10:00:00', 'roles' => ['wholesale_customer']];
}
function get_user_meta($uid, $k, $single = false)
{
    return '';
}
function mandala_is_wholesale_user($uid)
{
    return true;
}
function mandala_is_unsubscribed($email)
{
    return false;
}
function mandala_crm_terms_of($uid)
{
    return [];
}
function mandala_product_index()
{
    return $GLOBALS['index'];
}
function count_users()
{
    return ['avail_roles' => ['wholesale_customer' => count($GLOBALS['users'])]];
}
function home_url($p = '')
{
    return 'https://mandala.test' . $p;
}
function wp_parse_url($u, $c = -1)
{
    return parse_url($u, $c);
}
function wp_get_theme()
{
    return new class {
        public function get($k)
        {
            return '1.1.test';
        }
    };
}
function wp_remote_get($url, $args = [])
{
    $GLOBALS['http_log'][] = ['GET', $url, $args];
    return ($GLOBALS['http'])('GET', $url, $args);
}
function wp_remote_post($url, $args = [])
{
    $GLOBALS['http_log'][] = ['POST', $url, $args];
    return ($GLOBALS['http'])('POST', $url, $args);
}
function wp_remote_retrieve_response_code($r)
{
    return $r['response']['code'];
}
function wp_remote_retrieve_body($r)
{
    return $r['body'];
}
function wp_remote_retrieve_header($r, $h)
{
    return $r['headers'][$h] ?? '';
}

/** Az options tábla a zárhoz: INSERT IGNORE / UPDATE … WHERE lejárt / DELETE. */
class FakeWpdb
{
    public $options = 'wp_options';
    public $prefix = 'wp_';
    public $postmeta = 'wp_postmeta';
    public function prepare($sql, ...$args)
    {
        return [$sql, $args];
    }
    public function query($q)
    {
        [$sql, $a] = $q;
        if (strpos($sql, 'INSERT IGNORE') === 0) {
            if (array_key_exists($a[0], $GLOBALS['opts'])) {
                return 0;
            }
            $GLOBALS['opts'][$a[0]] = $a[1];
            return 1;
        }
        if (strpos($sql, 'UPDATE') === 0) {
            if (array_key_exists($a[1], $GLOBALS['opts']) && (int) $GLOBALS['opts'][$a[1]] < $a[2]) {
                $GLOBALS['opts'][$a[1]] = $a[0];
                return 1;
            }
            return 0;
        }
        throw new RuntimeException('váratlan lekérdezés: ' . $sql);
    }
    public function delete($table, $where)
    {
        unset($GLOBALS['opts'][$where['option_name']]);
        return 1;
    }
    public function get_results($q, $output = null)
    {
        return [];
    }
    public function get_var($q)
    {
        return null;
    }
}
$GLOBALS['wpdb'] = new FakeWpdb();

require __DIR__ . '/../wp-theme/mandala/inc/features/crm-connect.php';

/* ---------- A CRM oldala (hlprv helloprovision-partner, core.php + a v2 GET kibontása) ---------- */

const SECRET = 'teszt-titok-0123456789abcdefghij';
const CRM_URL = 'https://crm.test/wp-json/ucrm/v1/partner/connect';

function crm_sign(string $secret, string $ts, string $nonce, string $body): string
{
    return hash_hmac('sha256', "hpv-partner-v1\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}
function crm_verify(string $secret, string $ts, string $nonce, string $sig, string $body, int $now): string
{
    if (strlen($secret) < 24) {
        return 'not_paired';
    }
    if (!preg_match('/^\d{9,11}$/', $ts) || !preg_match('/^[a-f0-9]{32}$/', $nonce) || !preg_match('/^[a-f0-9]{64}$/', $sig)) {
        return 'bad_request';
    }
    if (abs($now - (int) $ts) > 300) {
        return 'expired';
    }
    return hash_equals(crm_sign($secret, $ts, $nonce, $body), $sig) ? '' : 'bad_signature';
}
/** A CRM v2 fogadása: [hibakód, BODY]. */
function crm_receive(string $url): array
{
    $q = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    $z = (string) ($q['hpvz'] ?? '');
    if ($z === '' || strlen($z) > 16000) {
        return ['too_big', null];
    }
    $raw = base64_decode(strtr($z, '-_', '+/') . str_repeat('=', (4 - strlen($z) % 4) % 4));
    $body = $raw === false ? false : @gzinflate($raw, 1048576);
    if (!is_string($body)) {
        return ['bad_body', null];
    }
    return [crm_verify(SECRET, (string) ($q['t'] ?? ''), (string) ($q['n'] ?? ''), (string) ($q['s'] ?? ''), $body, time()), $body];
}
/** A kérés csomagja (GET: a lekérdezésből, POST: a törzs). */
function req_body(array $req): ?array
{
    if ($req[0] === 'GET') {
        return json_decode((string) crm_receive($req[1])[1], true);
    }
    return json_decode((string) $req[2]['body'], true);
}
function json_ok(array $data = [], int $code = 200): array
{
    return ['response' => ['code' => $code], 'headers' => ['content-type' => 'application/json'], 'body' => json_encode($data + ['ok' => true, 'accepted' => 0, 'acked' => 0, 'errors' => [], 'commands' => []])];
}
/** A „jó” CRM: aláírást ellenőriz, és a beérkezett eseményeket gyűjti. */
function good_crm(array $commands = []): callable
{
    return function ($method, $url, $args) use (&$commands) {
        if ($method === 'GET') {
            [$err, $body] = crm_receive($url);
        } else {
            $h = $args['headers'];
            $body = (string) $args['body'];
            $err = crm_verify(SECRET, $h['X-HPV-Partner-Ts'], $h['X-HPV-Partner-Nonce'], $h['X-HPV-Partner-Sig'], $body, time());
        }
        if ($err !== '') {
            return json_ok(['code' => 'hpv_pt_' . $err, 'message' => 'nem hiteles (' . $err . ')'], 401);
        }
        $in = json_decode($body, true);
        foreach ($in['events'] as $e) {
            $GLOBALS['received'][] = $e;
        }
        $cmds = $commands;
        $commands = [];
        return json_ok(['accepted' => count($in['events']), 'commands' => $cmds, 'transport' => strtolower($method)]);
    };
}

$fails = 0;
$count = 0;
function ok($cond, string $label, string $extra = ''): void
{
    global $fails, $count;
    $count++;
    echo ($cond ? '✓ ' : '✗ ') . $label . ($extra !== '' ? ' – ' . $extra : '') . "\n";
    if (!$cond) {
        $fails++;
    }
}
function reset_all(): void
{
    // A napi teljes készletlista most nem esedékes (külön teszt nézi).
    $GLOBALS['opts'] = ['mandala_crm' => ['url' => CRM_URL, 'secret' => SECRET], 'mandala_crm_stock_full_at' => time()];
    $GLOBALS['http_log'] = [];
    $GLOBALS['received'] = [];
    $GLOBALS['mandala_crm_hint'] = 0;
    $GLOBALS['users'] = [];
    $GLOBALS['index'] = [];
    $GLOBALS['is_admin'] = false;
    $GLOBALS['on_get_users'] = null;
}
/** Kevéssé tömöríthető szöveg (hexa) – $seed-del mindig ugyanaz. */
function noise(int $n, string $seed = ''): string
{
    if ($seed === '') {
        return substr(bin2hex(random_bytes((int) ceil($n / 2))), 0, $n);
    }
    $out = '';
    for ($k = 0; strlen($out) < $n; $k++) {
        $out .= hash('sha256', $seed . ':' . $k);
    }
    return substr($out, 0, $n);
}
function activity(int $i): array
{
    return ['type' => 'activity', 'data' => ['user_id' => $i, 'kind' => 'login', 'at' => '2026-10-23T10:00:00+02:00', 'meta' => ['x' => noise(60, "a$i")]]];
}
function stock_item(int $i): array
{
    return ['sku' => 'MND-' . $i, 'product_id' => $i, 'name' => 'Tibeti hangtál ' . noise(30, "n$i"), 'category' => 'Hangtálak', 'stock' => $i % 7, 'in_stock' => true, 'wholesale' => 29000, 'retail' => 37340, 'sold90' => 3, 'url' => 'https://mandala.hu/termek/' . noise(24, "u$i") . '/', 'image' => 'https://mandala.hu/wp-content/uploads/' . noise(20, "i$i") . '.webp', 'incoming' => ''];
}
function sync_runs(int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        mandala_crm_sync();
    }
}

/* ---------- 1. Z oda-vissza ---------- */

reset_all();
$good = true;
foreach (['{"events":[],"acks":[]}', '{"x":"Árvíztűrő tükörfúrógép – „idézet”"}', '', noise(5000), str_repeat('a', 100000), random_bytes(3000)] as $b) {
    $z = mandala_crm_z_encode($b);
    [, $back] = [null, mandala_crm_z_decode($z)];
    $crm = gzinflate(base64_decode(strtr($z, '-_', '+/') . str_repeat('=', (4 - strlen($z) % 4) % 4)));
    $good = $good && $back === $b && $crm === $b && preg_match('/^[A-Za-z0-9_-]*$/', $z) && strpos($z, '=') === false;
}
ok($good, 'Z: base64url(gzdeflate(BODY, 9)) oda-vissza (a CRM kibontásával is), csak URL-biztos karakter, kitöltés nélkül');
ok(mandala_crm_z_decode('nem+base64') === null && mandala_crm_z_decode(rtrim(strtr(base64_encode('nem deflate'), '+/', '-_'), '=')) === null, 'Z: hibás bemenet → null');
ok(mandala_crm_get_url(CRM_URL, 'ZZ', '1790000000', str_repeat('ab', 16), 'ff') === CRM_URL . '?hpvz=ZZ&t=1790000000&n=' . str_repeat('ab', 16) . '&s=ff'
    && mandala_crm_get_url('https://crm.test/?rest_route=/ucrm/v1/partner/connect', 'ZZ', '1', 'n', 's') === 'https://crm.test/?rest_route=/ucrm/v1/partner/connect&hpvz=ZZ&t=1&n=n&s=s', 'GET cím: ? vagy & (a ?rest_route= alakú CRM-címnél)');

/* ---------- 2. Méret és mohó csomagolás ---------- */

$queue = array_map('activity', range(1, 400));
$p = mandala_crm_pack($queue, [], 300);
$next = strlen(mandala_crm_z_encode(mandala_crm_body(array_slice($queue, 0, $p['n'] + 1), [])));
ok($p['n'] > 5 && $p['n'] < 300 && strlen($p['z']) <= MANDALA_CRM_Z_MAX && $next > MANDALA_CRM_Z_MAX, 'csomagolás: mohón a határig (n esemény belefér, n+1 már nem)', "n={$p['n']}, |Z|=" . strlen($p['z']) . ", n+1: $next");
$h1 = mandala_crm_pack($queue, [], 300, MANDALA_CRM_Z_MAX, $p['n']);
$h2 = mandala_crm_pack($queue, [], 300, MANDALA_CRM_Z_MAX, 250);
ok($h1['n'] === $p['n'] && $h2['n'] === $p['n'], 'csomagolás: a kiinduló becslés (hint) nem változtat az eredményen');
$dec = json_decode(mandala_crm_z_decode($p['z']), true);
ok(count($dec['events']) === $p['n'] && $dec['events'][0] === $queue[0] && $dec['events'][$p['n'] - 1] === $queue[$p['n'] - 1] && $dec['acks'] === [], 'csomagolás: a sor eleje, sorrendben, { events, acks }');
$small = mandala_crm_pack(array_slice($queue, 0, 3), [], 300);
ok($small['n'] === 3 && mandala_crm_pack($queue, [], 2)['n'] === 2, 'csomagolás: kis sor egyben; a $max felső korlát');

reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = $queue;
$GLOBALS['http'] = good_crm();
mandala_crm_flush();
$urls = array_filter($GLOBALS['http_log'], fn($r) => $r[0] === 'GET');
$maxz = max(array_map(function ($r) {
    parse_str(parse_url($r[1], PHP_URL_QUERY), $q);
    return strlen($q['hpvz']);
}, $urls));
$maxurl = max(array_map(fn($r) => strlen($r[1]), $urls));
ok(count($urls) === count($GLOBALS['http_log']) && count($GLOBALS['received']) === 400 && $GLOBALS['received'] === $queue && !mandala_crm_queue(), 'küldés: 400 esemény GET-tel, sorrendben, a sor kiürült', count($urls) . ' kérés');
ok($maxz <= MANDALA_CRM_Z_MAX && $maxurl < 7500, 'küldés: minden kérésben |Z| ≤ 5500, a teljes cím < 7500 karakter', "max |Z|=$maxz, max cím=$maxurl");
ok(($GLOBALS['http_log'][0][2]['timeout'] ?? 0) === 20 && mandala_crm_state()['transport'] === 'get' && mandala_crm_state()['sent'] === 400, 'küldés: 20 mp időkorlát, átvitel: get, elküldött: 400');

/* ---------- 3. Készlet szétvágása ---------- */

$items = array_map('stock_item', range(1, 400));
$big = ['type' => 'stock', 'data' => ['items' => $items, 'full_start' => true, 'full_end' => true]];
$parts = mandala_crm_split_stock($big);
$fit = array_filter($parts, fn($e) => strlen(mandala_crm_z_encode(mandala_crm_body([$e], []))) <= MANDALA_CRM_Z_MAX);
$flat = array_merge(...array_map(fn($e) => $e['data']['items'], $parts));
$starts = array_map(fn($e) => $e['data']['full_start'], $parts);
$ends = array_map(fn($e) => $e['data']['full_end'], $parts);
ok(count($parts) > 1 && count($fit) === count($parts) && $flat === $items, 'készlet: a túl nagy esemény darabokra (mindegyik befér), a tételek sorrendben, hiánytalanul', count($parts) . ' darab');
ok($starts[0] === true && !in_array(true, array_slice($starts, 1), true) && end($ends) === true && !in_array(true, array_slice($ends, 0, -1), true), 'készlet: full_start csak az elsőn, full_end csak az utolsón');
$plain = mandala_crm_split_stock(['type' => 'stock', 'data' => ['items' => $items]]);
ok(!array_key_exists('full_start', $plain[0]['data']) && !array_key_exists('full_end', end($plain)['data']), 'készlet: változás-eseménynél nincs jelölő');

reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1), $big, activity(2)];
$GLOBALS['http'] = good_crm();
mandala_crm_flush();
$got = array_values(array_filter($GLOBALS['received'], fn($e) => $e['type'] === 'stock'));
ok(array_merge(...array_map(fn($e) => $e['data']['items'], $got)) === $items && $got[0]['data']['full_start'] && end($got)['data']['full_end'] && !mandala_crm_queue()
    && !array_filter($GLOBALS['http_log'], fn($r) => $r[0] === 'POST') && $GLOBALS['received'][0] === activity(1) && end($GLOBALS['received']) === activity(2),
    'készlet: a sorban álló régi 400 tételes esemény a helyén szétvágva, GET-tel átment (jelölők a szélein)');

/* ---------- 4. Aláírás: a CRM algoritmusa ---------- */

ok(mandala_crm_sign(str_repeat('k', 32), '1790000000', str_repeat('ab', 16), json_encode(['events' => []])) === 'ebac29b0efba0c5f64d7374931c3035cbc653cfb999dc49387d32db7d157959e', 'aláírás: a közös tesztvektor (hpv-partner-v1)');
$ts = (string) time();
$nonce = bin2hex(random_bytes(16));
$body = mandala_crm_body([activity(5)], []);
ok(crm_verify(SECRET, $ts, $nonce, mandala_crm_sign(SECRET, $ts, $nonce, $body), $body, time()) === '', 'aláírás: a CRM hpv_pt_verify() algoritmusa elfogadja');
reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(7)];
$GLOBALS['http'] = good_crm();
mandala_crm_post();
$u = $GLOBALS['http_log'][0][1];
[$err, $b] = crm_receive($u);
parse_str(parse_url($u, PHP_URL_QUERY), $qq);
$tampered = preg_replace('/hpvz=(.)/', 'hpvz=' . ($qq['hpvz'][0] === 'A' ? 'B' : 'A'), $u);
ok($err === '' && json_decode($b, true)['events'][0] === activity(7) && preg_match('/^[a-f0-9]{32}$/', $qq['n']) && preg_match('/^\d{10}$/', $qq['t']), 'aláírás: a GET kérést a CRM kibontja és hitelesnek fogadja (t, n, s)');
ok(crm_receive($tampered)[0] !== '', 'aláírás: a megváltoztatott csomag nem hiteles');

// A valódi CRM-kód ellen is, ha megvan a hlprv repó: HLPRV=/útvonal/hlprv php tests/crm-connect-transport.php
$core = rtrim((string) getenv('HLPRV'), '/') . '/plugins/helloprovision-partner/includes/core.php';
if (getenv('HLPRV') && is_readable($core)) {
    require_once $core;
    $dec = hpv_pt_connect_decode_get($qq['hpvz']);
    ok($dec['error'] === '' && $dec['body'] === $b && hpv_pt_verify(SECRET, $qq['t'], $qq['n'], $qq['s'], $dec['body'], time()) === ''
        && hpv_pt_sign(SECRET, $ts, $nonce, $body) === mandala_crm_sign(SECRET, $ts, $nonce, $body),
        'aláírás: a hlprv core.php (hpv_pt_connect_decode_get + hpv_pt_verify) elfogadja a GET kérést');
    parse_str((string) parse_url($tampered, PHP_URL_QUERY), $tq);
    $dz = hpv_pt_connect_decode_get((string) $tq['hpvz']);
    ok($dz['error'] !== '' || hpv_pt_verify(SECRET, $qq['t'], $qq['n'], $qq['s'], $dz['body'], time()) !== '', 'aláírás: a hlprv core.php a megváltoztatott csomagot elutasítja');
} else {
    echo "– a hlprv core.php elleni ellenőrzés kimarad (HLPRV=/útvonal/hlprv)\n";
}

/* ---------- 5. Mérgezett esemény ---------- */

reset_all();
$poison = ['type' => 'order', 'data' => ['id' => 666, 'note' => 'MERGEZETT']];
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1), $poison, activity(2), activity(3)];
$GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
$crm = good_crm();
$GLOBALS['http'] = function ($m, $url, $a) use ($crm) {
    $in = req_body([$m, $url, $a]);
    foreach ($in['events'] as $e) {
        if (($e['data']['note'] ?? '') === 'MERGEZETT') {
            return ['response' => ['code' => 500], 'headers' => ['server' => 'nginx'], 'body' => '<html><body><h1>500 Internal Server Error</h1></body></html>'];
        }
    }
    return $crm($m, $url, $a);
};
sync_runs(1);
$f1 = get_option('mandala_crm_head_fail');
ok(count($GLOBALS['received']) === 1 && mandala_crm_queue()[0] === $poison && ($f1['runs'] ?? 0) === 1 && strpos(mandala_crm_state()['error'], 'HTTP 500 (nginx): 500 Internal Server Error') !== false, 'mérgezett: az 1. futás a jó eseményt átviszi, a hibásnál megáll (1/3)', mandala_crm_state()['error']);
sync_runs(1);
ok((get_option('mandala_crm_head_fail')['runs'] ?? 0) === 2 && !get_option(MANDALA_CRM_DEAD), 'mérgezett: 2/3 – még a sorban');
sync_runs(1);
$dead = get_option(MANDALA_CRM_DEAD, []);
ok(count($dead) === 1 && $dead[0]['event'] === $poison && strpos($dead[0]['reason'], 'HTTP 500') !== false && $dead[0]['at'] > 0 && !get_option('mandala_crm_head_fail'), 'mérgezett: a 3. futás után félretéve (okkal, idővel)');
ok(array_column(array_column(array_filter($GLOBALS['received'], fn($e) => $e['type'] === 'activity'), 'data'), 'user_id') === [1, 2, 3] && !array_filter(mandala_crm_queue(), fn($e) => $e['type'] !== 'hello'), 'mérgezett: a sor ugyanabban a futásban megy tovább');
$GLOBALS['http'] = good_crm();
ok(mandala_crm_requeue_dead() === 1 && end($GLOBALS['opts'][MANDALA_CRM_QUEUE]) === $poison && !get_option(MANDALA_CRM_DEAD), 'mérgezett: „Vissza a sorba” – a sor végére');
for ($i = 0; $i < 60; $i++) {
    $GLOBALS['opts']['mandala_crm_dead'][] = ['at' => $i, 'reason' => 'x', 'event' => activity($i)];
}
mandala_crm_bury(activity(999), 'teszt');
ok(count(get_option(MANDALA_CRM_DEAD)) === 50 && end($GLOBALS['opts'][MANDALA_CRM_DEAD])['event'] === activity(999), 'mérgezett: a félretettekből az utolsó 50 marad');

// Leállt CRM / tűzfal: nem az esemény hibája – nem kerül félre.
foreach (['kapcsolat' => new WP_Error('http_request_failed', 'cURL error 7: Failed to connect to crm.test port 443'), 'nginx 403' => ['response' => ['code' => 403], 'headers' => ['server' => 'nginx'], 'body' => "<html>\r\n<head><title>403 Forbidden</title></head>\r\n<body>\r\n<center><h1>403 Forbidden</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>"]] as $label => $resp) {
    reset_all();
    $GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1), activity(2)];
    $GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
    $GLOBALS['http'] = fn() => $resp;
    sync_runs(4);
    $st = mandala_crm_state();
    ok(!get_option(MANDALA_CRM_DEAD) && !get_option('mandala_crm_head_fail') && count(mandala_crm_queue()) === 2 && !empty($st['run_at']) && !empty($st['run_done']), "leállt CRM ($label): 4 futás után sem tesz félre semmit, a sor megmarad", $st['error']);
    if ($label === 'nginx 403') {
        ok($st['error'] === 'HTTP 403 (nginx): 403 Forbidden 403 Forbidden nginx', 'hibaszöveg: kód, a válaszoló szerver, a válasz eleje címkék nélkül', $st['error']);
        // egy futásban: kérés + gyors újrapróba + üres próba
        ok(count($GLOBALS['http_log']) === 12, 'leállt CRM: futásonként 3 kérés (kérés, újrapróba, üres próba), aztán megáll', (string) count($GLOBALS['http_log']));
    }
}

/* ---------- 6. Nyugták ---------- */

reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1)];
$GLOBALS['opts']['mandala_crm_acks'] = [['id' => 7, 'ok' => true, 'result' => ['user_id' => 3]]];
$GLOBALS['http'] = fn() => ['response' => ['code' => 502], 'headers' => [], 'body' => 'Bad Gateway'];
mandala_crm_flush();
ok(get_option('mandala_crm_acks')[0]['id'] === 7 && req_body($GLOBALS['http_log'][0])['acks'][0]['id'] === 7 && count(mandala_crm_queue()) === 1, 'nyugták: hibánál maradnak (az első kérés vitte őket)');
$GLOBALS['http_log'] = [];
$GLOBALS['http'] = good_crm([['id' => 9, 'type' => 'ismeretlen', 'data' => []]]);
mandala_crm_flush();
$r1 = req_body($GLOBALS['http_log'][0]);
$r2 = isset($GLOBALS['http_log'][1]) ? req_body($GLOBALS['http_log'][1]) : ['acks' => []];
ok(array_column($r1['acks'], 'id') === [7] && count($r1['events']) === 1 && array_column($r2['acks'], 'id') === [9] && $r2['acks'][0]['ok'] === false && get_option('mandala_crm_acks') === [], 'nyugták: siker után törölve; a válasz parancsának nyugtája a következő kérésben, aztán az is törölve');

/* ---------- 7. Régi POST: túl nagy egyedi esemény, régi CRM ---------- */

reset_all();
$huge = ['type' => 'order', 'data' => ['id' => 1, 'items' => array_map(fn($i) => ['sku' => noise(12), 'name' => noise(80), 'qty' => 1], range(1, 300))]];
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [$huge, activity(2)];
$GLOBALS['http'] = good_crm();
mandala_crm_flush();
$methods = array_column($GLOBALS['http_log'], 0);
ok($methods[0] === 'POST' && $GLOBALS['received'][0] === $huge && $GLOBALS['received'][1] === activity(2) && in_array('GET', $methods, true) && !mandala_crm_queue(), 'túl nagy egyedi esemény: egyszer a régi POST-tal (aláírva), utána GET-tel tovább', implode(',', $methods));
reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [$huge, activity(2)];
$GLOBALS['http'] = fn($m) => $m === 'POST' ? ['response' => ['code' => 403], 'headers' => ['server' => 'nginx'], 'body' => '<h1>403 Forbidden</h1>'] : json_ok();
mandala_crm_flush();
ok(array_column($GLOBALS['http_log'], 0) === ['POST'] && (get_option('mandala_crm_head_fail')['runs'] ?? 0) === 1 && strpos(mandala_crm_state()['error'], 'túl nagy esemény (order), POST: HTTP 403') !== false, 'túl nagy egyedi esemény: ha a POST sem megy, az esemény hibája (1/3), újrapróba nélkül', mandala_crm_state()['error']);
reset_all();
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1), activity(2)];
$crm = good_crm();
$GLOBALS['http'] = fn($m, $u, $a) => $m === 'GET' ? json_ok(['code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.', 'data' => ['status' => 404]], 404) : $crm($m, $u, $a);
mandala_crm_flush();
ok(array_column($GLOBALS['http_log'], 0) === ['GET', 'POST'] && count($GLOBALS['received']) === 2 && mandala_crm_state()['transport'] === 'post' && !mandala_crm_queue(), 'régi CRM (a GET-re rest_no_route): ugyanaz a csomag POST-tal');

/* ---------- 8. Zár ---------- */

reset_all();
ok(mandala_crm_lock() && !mandala_crm_lock() && mandala_crm_sync() === false, 'zár: egyszerre egy futás (a második nem indul)');
$GLOBALS['opts'][MANDALA_CRM_LOCK] = (string) (time() - 5);
ok(mandala_crm_lock(), 'zár: a lejárt zár átvehető (megszakadt futás után)');
mandala_crm_unlock();
ok(!array_key_exists(MANDALA_CRM_LOCK, $GLOBALS['opts']) && mandala_crm_lock(), 'zár: feloldás után újra megszerezhető');
mandala_crm_unlock();

/* ---------- 9. Készlet: 40-es adagok, a teljes küldéstől függetlenül ---------- */

reset_all();
$GLOBALS['index'] = array_map(fn($i) => ['id' => $i, 'sku' => 'MND-' . $i, 'name' => 'Termék ' . $i, 'catLabel' => 'Hangtálak', 'price' => 1000 + $i, 'url' => '', 'img' => '', 'stock' => 'in'], range(1, 100));
$GLOBALS['users'] = range(1, 30);
$GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
mandala_crm_backfill_start('all');
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = array_map('activity', range(1, MANDALA_CRM_BACKFILL_QUEUE)); // hosszú sor: a teljes küldés vár
$GLOBALS['http'] = fn() => new WP_Error('x', 'nincs kapcsolat');
mandala_crm_sync();
$stock = array_values(array_filter(mandala_crm_queue(), fn($e) => $e['type'] === 'stock'));
ok(array_map(fn($e) => count($e['data']['items']), $stock) === [40, 40, 20] && $stock[0]['data']['full_start'] && !$stock[1]['data']['full_start'] && $stock[2]['data']['full_end'] && !$stock[0]['data']['full_end'], 'készlet: a teljes lista 40-es adagokban (eleje / vége jelölve)');
ok(get_option('mandala_crm_backfill')['cursor'] === 0, 'készlet: a teljes küldés még fut (a hosszú sor miatt vár), a készlet mégis sorba került');
$GLOBALS['index'][4]['price'] = 9999;
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [];
mandala_crm_queue_stock(false);
$ch = mandala_crm_queue();
ok(count($ch) === 1 && count($ch[0]['data']['items']) === 1 && $ch[0]['data']['items'][0]['sku'] === 'MND-5' && !isset($ch[0]['data']['full_start']), 'készlet: utána csak a változás');

/* ---------- 10. Teljes küldés: hibatűrés ---------- */

reset_all();
$GLOBALS['users'] = [1, 2, 3];
mandala_crm_backfill_start('customers');
$errs = mandala_crm_backfill_step();
$cust = array_column(array_column(mandala_crm_queue(), 'data'), 'user_id');
ok($cust === [1, 3] && count($errs) === 1 && strpos($errs[0], 'fiók #2: hibás fiókadat') === 0 && !get_option('mandala_crm_backfill') && !empty(mandala_crm_state()['backfill_done']), 'teljes küldés: a hibás fiók kimarad (hibaüzenettel), a többi megy, a küldés befejeződik');
reset_all();
$GLOBALS['users'] = [1, 3, 4, 5];
update_option('mandala_crm_backfill', ['what' => 'customers', 'cursor' => 1, 'current' => 3, 'started' => time()]);
$errs = mandala_crm_backfill_step();
$cust = array_column(array_column(mandala_crm_queue(), 'data'), 'user_id');
ok($cust === [4, 5] && strpos($errs[0], 'fiók #3: a feldolgozása megszakadt') === 0, 'teljes küldés: a fiók, amelynél az előző futás megszakadt, kimarad (nem akad el rajta)');
reset_all();
$GLOBALS['users'] = range(10, 109);
mandala_crm_backfill_start('customers');
mandala_crm_backfill_step(40);
ok(get_option('mandala_crm_backfill')['cursor'] === 40 && count(mandala_crm_queue()) === 40, 'teljes küldés: fiókonként halad (40 / adag)');
reset_all();
$GLOBALS['users'] = [1, 2, 3];
$GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
mandala_crm_backfill_start('customers');
$GLOBALS['http'] = good_crm();
mandala_crm_sync();
$st = mandala_crm_state();
ok(strpos($st['run_error'] ?? '', 'teljes küldés: fiók #2') === 0 && $st['run_done'] >= $st['run_at'] && $st['run_step'] === '' && count($GLOBALS['received']) === 2, 'futás: run_at, run_done, a lépés hibája a run_error-ban, a küldés lefutott', $st['run_error'] ?? '');

// A már sorban álló események a teljes küldés előtt mennek (ha az végzetes hibával állna le, a küldés akkor se akad el).
reset_all();
$GLOBALS['users'] = [1, 3];
$GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1), activity(2)];
mandala_crm_backfill_start('customers');
$GLOBALS['http'] = good_crm();
$seen = null;
$GLOBALS['on_get_users'] = function () use (&$seen) {
    $seen = $seen ?? count($GLOBALS['received']);
};
mandala_crm_sync();
ok($seen === 2 && count($GLOBALS['received']) === 4 && !mandala_crm_queue(), 'futás: előbb a sorban álló események mennek, aztán a teljes küldés adagja, végül az is', "a teljes küldés előtt: $seen");

// Egy futás legfeljebb 80 kérés (a két küldés együtt).
reset_all();
$GLOBALS['opts']['mandala_crm_state'] = ['hello_at' => time()];
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = array_map(fn($i) => ['type' => 'activity', 'data' => ['user_id' => $i, 'meta' => noise(6000, "big$i")]], range(1, 120));
$GLOBALS['http'] = good_crm();
mandala_crm_sync(false, 60.0);
ok(count($GLOBALS['http_log']) === 80 && count(mandala_crm_queue()) === 40 && !array_filter($GLOBALS['http_log'], fn($r) => $r[0] !== 'GET'), 'futás: legfeljebb 80 kérés (a két küldés együtt)', count($GLOBALS['http_log']) . ' kérés, maradt ' . count(mandala_crm_queue()));

// Végzetes PHP-hiba a futás közben (időkorlát, memória): a lépés és a hiba az állapotba, a zár fel.
reset_all();
mandala_crm_lock();
$GLOBALS['mandala_crm_running'] = true;
mandala_crm_state(['run_step' => 'készlet']);
mandala_crm_sync_died();
$st = mandala_crm_state();
ok(strpos($st['run_error'], 'készlet: ') === 0 && !empty($st['run_error_at']) && mandala_crm_lock(), 'futás: végzetes leállásnál a lépés a run_error-ban, a zár felszabadul', $st['run_error']);
mandala_crm_unlock();
$GLOBALS['mandala_crm_running'] = false;

/* ---------- 11. Tartalék ütemező (oldalbetöltés vége) ---------- */

reset_all();
$GLOBALS['http'] = good_crm();
$GLOBALS['opts']['mandala_crm_state'] = ['run_at' => time() - 3600, 'hello_at' => time()];
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(1)];
mandala_crm_maybe_run();
ok(mandala_crm_state()['run_at'] < time() - 3000 && count($GLOBALS['received']) === 0, 'tartalék ütemező: a válasz nem zárható le előre (CLI) → látogatói oldalon nem fut');
$GLOBALS['is_admin'] = true;
mandala_crm_maybe_run();
ok(mandala_crm_state()['run_at'] >= time() - 5 && mandala_crm_state()['run_via'] === 'oldalbetöltés' && count($GLOBALS['received']) === 1, 'tartalék ütemező: adminban lefut (rövid kerettel), ha 9 percnél régebben futott');
$GLOBALS['opts'][MANDALA_CRM_QUEUE] = [activity(2)];
mandala_crm_maybe_run();
ok(count($GLOBALS['received']) === 1, 'tartalék ütemező: 9 percen belül nem fut újra');
ok(in_array('mandala_crm_maybe_run', $GLOBALS['hooks']['shutdown'] ?? [], true), 'tartalék ütemező: a shutdown horogra kötve');

/* ---------- 12. Őrszem ---------- */

$health = function () {
    $out = [];
    foreach ($GLOBALS['hooks']['mandala_health_checks'] as $cb) {
        $out = $cb($out);
    }
    return $out['crm'];
};
reset_all();
$GLOBALS['opts']['mandala_crm_state'] = ['run_at' => time() - 60, 'ok_at' => time() - 60];
$h = $health();
ok($h['status'] === 'ok' && $h['ok'] === true, 'őrszem: friss futás és kapcsolat → ok', $h['msg']);
$GLOBALS['opts']['mandala_crm_state'] = ['run_at' => time() - 45 * 60, 'ok_at' => time() - 45 * 60];
$h = $health();
ok($h['status'] === 'warn' && strpos($h['msg'], '45 perce nem futott') !== false, 'őrszem: 30 percnél régebbi futás → figyelmeztetés', $h['msg']);
$GLOBALS['opts']['mandala_crm_state'] = ['run_at' => time() - 60, 'ok_at' => time() - 7200, 'error' => 'HTTP 403 (nginx): 403 Forbidden'];
$GLOBALS['opts'][MANDALA_CRM_DEAD] = [['at' => time(), 'reason' => 'x', 'event' => activity(1)]];
$h = $health();
ok($h['status'] === 'warn' && strpos($h['msg'], 'HTTP 403') !== false && strpos($h['msg'], '1 félretett esemény') !== false, 'őrszem: régi kapcsolat (a hibával) és félretett esemény → figyelmeztetés', $h['msg']);
$GLOBALS['opts'][MANDALA_CRM_DEAD] = [];
$GLOBALS['opts']['mandala_crm_state'] = ['run_at' => time() - 600, 'run_done' => time() - 1200, 'run_step' => 'készlet', 'run_error' => 'készlet: PHP-hiba: Allowed memory size exhausted', 'ok_at' => time() - 60];
$h = $health();
ok($h['status'] === 'warn' && strpos($h['msg'], 'nem fejeződött be (készlet): készlet: PHP-hiba') !== false, 'őrszem: a félbeszakadt futás (lépés, hiba) → figyelmeztetés', $h['msg']);

/* ---------- 13. Hibaszöveg ---------- */

ok(mandala_crm_http_error(400, '<html><head><title>400 Bad Request</title></head><body><center><h1>400 Bad Request</h1></center><hr><center>nginx</center></body></html>', null, 'ELIN.hu') === 'HTTP 400 (ELIN.hu): 400 Bad Request 400 Bad Request nginx'
    && mandala_crm_http_error(401, '', ['code' => 'hpv_pt_bad_signature', 'message' => 'Az összekötő kérése nem hiteles (bad_signature).'], '') === 'HTTP 401: Az összekötő kérése nem hiteles (bad_signature). [hpv_pt_bad_signature]'
    && mandala_crm_http_error(200, 'Rendben', null) === 'HTTP 200, nem JSON: Rendben'
    && mb_strlen(mandala_crm_snippet(str_repeat('<p>hosszú szöveg</p>', 50))) === 120, 'hibaszöveg: HTML címkék nélkül, a WordPress-hiba üzenete kóddal, ~120 karakter');

echo "\n" . ($fails ? "$fails hiba / $count ellenőrzés" : "Mind a $count ellenőrzés rendben.") . "\n";
exit($fails ? 1 : 0);
