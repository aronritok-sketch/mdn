<?php
/**
 * Mandala Partner CRM – összekötő (a webshop oldala). A partnerkezelő CRM a HelloProVision CRM-jén fut (külön
 * WordPress, helloprovision-partner bővítmény); ez a modul küldi neki a viszonteladói adatokat, és végrehajtja a CRM
 * parancsait. Mindig a webshop kezdeményez (kifelé induló, aláírt kérés 10 percenként) – a CRM a webshop tárhelyére
 * soha nem kezdeményez.
 *
 * Átvitel (protokoll v2): aláírt GET – a csomag tömörítve a lekérdezésben. Mindkét oldal az ELIN-en van, és az ELIN
 * tűzfala a szerverek közti POST-ot gyakran 403-mal tiltja (1 MB fölött mindig), a GET-et 7500 karakterig átengedi.
 *   GET {CRM-cím}?hpvz=<Z>&t=<ts>&n=<nonce>&s=<aláírás>, Z = base64url(gzdeflate(BODY, 9)), legfeljebb 5500 karakter;
 *   BODY = {"events":[…],"acks":[…]}, az aláírás a hpv-partner-v1 HMAC a BODY-n (mint a POST-nál volt).
 * A régi POST csak tartalék: egyetlen, a GET-be be nem férő esemény, vagy ha a CRM még nem ismeri a GET-et.
 *
 * Mit küld (sorban, egy kérésben több esemény):
 *  - application  a „Viszonteladóknak” oldal jelentkezése (iu/form „viszontelado”), a partnerlevél-hozzájárulással;
 *  - customer     viszonteladói fiók: regisztráció, szerepváltás, adatváltozás; marketing_consent (+ _at), terms;
 *  - order        viszonteladó rendelése (létrejötte és minden állapotváltozása, tételekkel, kategóriával; ajánlatból
 *                 jött rendelésnél offer_token, halasztott fizetésnél payment_days / payment_due);
 *  - activity     viszonteladó belépése, árlista- és fotóletöltés, gyorsrendelő, új érkezések levele be / ki
 *                 (arrivals / arrivals_off);
 *  - message      „Üzenet a Mandalának” a viszonteladói fiókból (crm-partner.php);
 *  - offer_open / offer_order  az ajánlat-link megnyitása, az ajánlatból lett rendelés (crm-partner.php);
 *  - web_stats    a webes forgalom havonta és csatornánként (egyéni / kereskedő) – naponta az aktuális és az előző hónap;
 *  - stock        készlet (cikkszám, név, kategória, darab, nagyker ár, 90 napos eladás, várható érkezés) – a változás
 *                 10 percenként (a JUTA közvetlen adatbázis-írása után is), a teljes lista naponta; eseményenként 40
 *                 tétel, a viszonteladók teljes küldésétől függetlenül;
 *  - hello        a webshop állapota (verzió, viszonteladók száma, szinkron).
 * Mit kap: approve (viszonteladói szerep; ha nincs fiók, nyit egyet, és a WooCommerce elküldi a belépési levelet),
 * terms (kedvezményszint, fizetési határidő, rendelés-tiltás – crm-partner.php), consent (partnerlevél-hozzájárulás a
 * CRM-ből: 'no' = leiratkozott, 'yes' = telefonon / személyesen kérte – crm-partner.php), resync (teljes újraküldés). Az
 * eredményt a következő kérésben nyugtázza. A parancsok a webshop saját, aláírt kérésére adott válaszban jönnek (a
 * beállított https CRM-címről) – kívülről nem lehet parancsot küldeni. Az ajánlat-link (?mandala_offer=…) a közös titokkal aláírt: crm-partner.php.
 *
 * Ütemezés: az Action Scheduler 10 perces feladata (mandala_crm_sync), és tartaléknak egy oldalbetöltés vége (ha a
 * WP-cron / Action Scheduler nem fut – az ELIN a loopback POST-ot is tilthatja). Egyszerre egy futás (zár).
 *
 * Beállítás: WooCommerce → Partner CRM → az összekötő kód a CRM-ből (Partnerek → Beállítások), vagy a wp-config.php-ban
 * MANDALA_CRM_URL + MANDALA_CRM_SECRET. Protokoll: hpv-partner-v1 (a CRM oldala: helloprovision-partner/includes/core.php,
 * connect.php).
 */

defined('ABSPATH') || exit;

const MANDALA_CRM_QUEUE = 'mandala_crm_queue';
const MANDALA_CRM_STATE = 'mandala_crm_state';
const MANDALA_CRM_DEAD = 'mandala_crm_dead';        // félretett (mérgezett) események, az utolsó 50
const MANDALA_CRM_LOCK = 'mandala_crm_lock';        // egyszerre egy futás
const MANDALA_CRM_MAX_QUEUE = 5000;
const MANDALA_CRM_Z_MAX = 5500;                     // a GET-be tett tömörített csomag (hpvz) legnagyobb hossza
const MANDALA_CRM_BODY_MAX = 1000000;               // a kibontott csomag – a CRM 1 MB-nál többet nem bont ki
const MANDALA_CRM_STOCK_CHUNK = 40;                 // készlet-tétel eseményenként
const MANDALA_CRM_BACKFILL_QUEUE = 600;             // a teljes küldés csak ennyi sorban álló esemény alatt tölt a sorba

/** A CRM címe és a közös titok: [url, secret] vagy null. */
function mandala_crm_config(): ?array
{
    if (defined('MANDALA_CRM_URL') && defined('MANDALA_CRM_SECRET') && MANDALA_CRM_URL && strlen((string) MANDALA_CRM_SECRET) >= 24) {
        return [(string) MANDALA_CRM_URL, (string) MANDALA_CRM_SECRET];
    }
    $o = get_option('mandala_crm');
    return is_array($o) && !empty($o['url']) && strlen((string) ($o['secret'] ?? '')) >= 24 ? [(string) $o['url'], (string) $o['secret']] : null;
}

/** Az összekötő kód (a CRM adja): base64url JSON { v, url, secret }. */
function mandala_crm_decode_code(string $code): ?array
{
    $raw = base64_decode(strtr(trim($code), '-_', '+/'), true);
    $d = $raw ? json_decode($raw, true) : null;
    if (!is_array($d) || empty($d['url']) || strlen((string) ($d['secret'] ?? '')) < 24 || !preg_match('#^https://#i', (string) $d['url'])) {
        return null;
    }
    return ['url' => esc_url_raw((string) $d['url']), 'secret' => (string) $d['secret']];
}

/** Aláírás – bájtra egyezik a CRM hpv_pt_sign() függvényével (tesztvektor: tests/wp-features.mjs, hlprv tests/partner.php). */
function mandala_crm_sign(string $secret, string $ts, string $nonce, string $body): string
{
    return hash_hmac('sha256', "hpv-partner-v1\n" . $ts . "\n" . $nonce . "\n" . hash('sha256', $body), $secret);
}

/* ---------- Protokoll v2: a csomag a GET-ben ---------- */

/** A csomag (BODY): a CRM ugyanezt a JSON-t kapja, mint a POST-nál – egyszerűsített (\/ és \u nélküli) alakban. */
function mandala_crm_body(array $events, array $acks): string
{
    return (string) wp_json_encode(['events' => array_values($events), 'acks' => array_values($acks)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/** Z = base64url (RFC 4648 §5, kitöltés nélkül) a nyers DEFLATE-ből (gzdeflate, 9). */
function mandala_crm_z_encode(string $body): string
{
    return rtrim(strtr(base64_encode((string) gzdeflate($body, 9)), '+/', '-_'), '=');
}

/** Z visszafejtése (a CRM is így bontja ki; a tesztek ezzel ellenőriznek). null, ha hibás vagy 1 MB-nál nagyobb. */
function mandala_crm_z_decode(string $z): ?string
{
    $raw = preg_match('/^[A-Za-z0-9_-]+$/', $z) ? base64_decode(strtr($z, '-_', '+/'), true) : false;
    $body = $raw === false ? false : @gzinflate($raw, 1048576); // a CRM határa: 1 MB
    return is_string($body) ? $body : null;
}

/** A kérés címe: a CRM-cím (lehet benne ?rest_route=…) + hpvz, t, n, s. A Z karakterei URL-biztosak. */
function mandala_crm_get_url(string $url, string $z, string $ts, string $nonce, string $sig): string
{
    return $url . (strpos($url, '?') === false ? '?' : '&') . 'hpvz=' . $z . '&t=' . $ts . '&n=' . $nonce . '&s=' . $sig;
}

/**
 * A legnagyobb k (0…$total), amelyre $try(k) nem null – a $hint-től (az előző kérés mérete) kettőzve, majd felezve
 * keres (a tömörített hossz az esemény-számmal közel monoton nő). Visszaad: [k, $try(k)].
 */
function mandala_crm_fit(int $total, callable $try, int $hint = 0): array
{
    $best = [0, $try(0)];
    if ($total < 1 || $best[1] === null) {
        return $best;
    }
    $lo = 0;
    $hi = $total + 1;
    $k = max(1, min($total, $hint));
    while (true) {
        $r = $try($k);
        if ($r === null) {
            $hi = $k;
            break;
        }
        $best = [$k, $r];
        $lo = $k;
        if ($k >= $total) {
            return $best;
        }
        $k = min($total, $k * 2);
    }
    while ($hi - $lo > 1) {
        $mid = intdiv($lo + $hi, 2);
        $r = $try($mid);
        if ($r === null) {
            $hi = $mid;
        } else {
            $lo = $mid;
            $best = [$mid, $r];
        }
    }
    return $best;
}

/**
 * A következő kérés csomagja: a nyugták (mind, ha beférnek) és a sor elejéből mohón annyi esemény (legfeljebb $max),
 * amennyi tömörítve belefér $limit karakterbe. Mérve tömörít.
 *
 * @return array{n:int, m:int, body:string, z:string} n = esemény, m = nyugta. Nem üres sornál n = 0 és m = 0: a sor
 *               eleje egymagában sem fér a GET-be.
 */
function mandala_crm_pack(array $queue, array $acks, int $max = 300, int $limit = MANDALA_CRM_Z_MAX, int $hint = 0): array
{
    $queue = array_values($queue);
    $acks = array_values($acks);
    $build = function (int $n, int $m) use ($queue, $acks, $limit): ?array {
        $body = mandala_crm_body(array_slice($queue, 0, $n), array_slice($acks, 0, $m));
        if ($body === '' || strlen($body) > MANDALA_CRM_BODY_MAX) {
            return null;
        }
        $z = mandala_crm_z_encode($body);
        return strlen($z) <= $limit ? ['body' => $body, 'z' => $z] : null;
    };
    [$m, $only_acks] = mandala_crm_fit(count($acks), fn($k) => $build(0, $k), count($acks));
    [$n, $pack] = mandala_crm_fit(min($max, count($queue)), fn($k) => $build($k, $m), $hint);
    return ['n' => $n, 'm' => $m] + ($pack ?? $only_acks ?? ['body' => mandala_crm_body([], []), 'z' => mandala_crm_z_encode(mandala_crm_body([], []))]);
}

/**
 * Túl nagy készlet-esemény szétvágása kisebbekre (felezve, amíg mindegyik darab belefér a GET-be; egyetlen tétel nem
 * vágható tovább). A többi kulcs marad; a teljes lista jelölői: full_start csak az elsőn, full_end csak az utolsón.
 */
function mandala_crm_split_stock(array $event, int $limit = MANDALA_CRM_Z_MAX): array
{
    $data = (array) ($event['data'] ?? []);
    $fits = fn(array $its) => strlen(mandala_crm_z_encode(mandala_crm_body([['type' => 'stock', 'data' => ['items' => $its, 'full_start' => true, 'full_end' => true] + $data]], []))) <= $limit;
    $parts = [];
    $walk = function (array $its) use (&$walk, &$parts, $fits) {
        if (count($its) <= 1 || $fits($its)) {
            $parts[] = $its;
            return;
        }
        $half = (int) ceil(count($its) / 2);
        $walk(array_slice($its, 0, $half));
        $walk(array_slice($its, $half));
    };
    $walk(array_values((array) ($data['items'] ?? [])));
    $last = count($parts) - 1;
    $out = [];
    foreach ($parts as $i => $its) {
        $d = ['items' => $its] + $data;
        if (array_key_exists('full_start', $data)) {
            $d['full_start'] = $i === 0 && !empty($data['full_start']);
        }
        if (array_key_exists('full_end', $data)) {
            $d['full_end'] = $i === $last && !empty($data['full_end']);
        }
        $out[] = ['type' => 'stock', 'data' => $d];
    }
    return $out;
}

/* ---------- Sor ---------- */

/** Egy esemény ujjlenyomata (a sor elejének azonosításához). */
function mandala_crm_sig($event): string
{
    return md5(serialize($event));
}

/** A sor frissen az adatbázisból (közben egy másik kérés is írhatott bele). */
function mandala_crm_queue(): array
{
    wp_cache_delete(MANDALA_CRM_QUEUE, 'options');
    $q = get_option(MANDALA_CRM_QUEUE, []);
    return is_array($q) ? array_values($q) : [];
}

/** A sor elejéről $count esemény le (helyükre $replace) – csak ha az eleje még az, amit elküldtünk ($head_sig). */
function mandala_crm_queue_splice(int $count, string $head_sig, array $replace = []): bool
{
    $q = mandala_crm_queue();
    if (!$q || mandala_crm_sig($q[0]) !== $head_sig) {
        return false;
    }
    array_splice($q, 0, $count, $replace);
    update_option(MANDALA_CRM_QUEUE, array_values($q), false);
    return true;
}

function mandala_crm_enqueue(string $type, array $data): void
{
    mandala_crm_enqueue_many([['type' => $type, 'data' => $data]]);
}

/** Több esemény egy írással (a teljes küldés és a készlet ne írja újra a sort eseményenként). */
function mandala_crm_enqueue_many(array $events): void
{
    if (!$events || !mandala_crm_config()) {
        return; // nincs összekötve: nem gyűjtünk
    }
    $q = array_merge(mandala_crm_queue(), array_values($events));
    if (count($q) > MANDALA_CRM_MAX_QUEUE) {
        $q = array_slice($q, -MANDALA_CRM_MAX_QUEUE);
    }
    update_option(MANDALA_CRM_QUEUE, $q, false);
}

/** Fontos esemény (jelentkezés, új viszonteladó): ne várjon 10 percet – egy háttérfeladat most küldi. */
function mandala_crm_soon(): void
{
    if (function_exists('as_enqueue_async_action') && function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_crm_sync', [true], MANDALA_AS_GROUP)) {
        as_enqueue_async_action('mandala_crm_sync', [true], MANDALA_AS_GROUP);
    }
}

function mandala_crm_state(array $merge = []): array
{
    $s = get_option(MANDALA_CRM_STATE, []);
    $s = is_array($s) ? $s : [];
    if ($merge) {
        $s = array_merge($s, $merge);
        update_option(MANDALA_CRM_STATE, $s, false);
    }
    return $s;
}

/* ---------- Átvitel ---------- */

/** A válasz eleje olvashatóan: címkék nélkül, egy sorban, ~120 karakter (a panelen látszódjon, KI válaszolt). */
function mandala_crm_snippet(string $raw, int $len = 120): string
{
    $t = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $raw);
    $t = html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim((string) preg_replace('/\s+/u', ' ', $t));
    return function_exists('mb_substr') ? mb_substr($t, 0, $len) : substr($t, 0, $len);
}

/** Hibaszöveg egy HTTP-válaszból: kód, a válaszoló szerver (Server fejléc) és a válasz eleje / a WordPress hibaüzenete. */
function mandala_crm_http_error(int $code, string $raw, ?array $data, string $server = ''): string
{
    $snip = $data && !empty($data['message']) ? (string) $data['message'] . (!empty($data['code']) ? ' [' . $data['code'] . ']' : '') : mandala_crm_snippet($raw);
    $head = 'HTTP ' . $code . ($server !== '' ? ' (' . $server . ')' : '') . ($code >= 200 && $code < 300 ? ', nem JSON' : '');
    return $snip !== '' ? $head . ': ' . $snip : $head;
}

/**
 * Egy aláírt kérés a CRM-nek: GET (protokoll v2, $z = a tömörített csomag) vagy a régi POST ($z = null).
 *
 * @return array{0:int, 1:?array, 2:string} [HTTP-kód (0 = nem jött válasz), a JSON-válasz, hibaszöveg ('' = rendben)]
 */
function mandala_crm_request(array $cfg, string $body, ?string $z): array
{
    $ts = (string) time();
    $nonce = bin2hex(random_bytes(16));
    $sig = mandala_crm_sign($cfg[1], $ts, $nonce, $body);
    if ($z !== null) {
        $res = wp_remote_get(mandala_crm_get_url($cfg[0], $z, $ts, $nonce, $sig), [
            'timeout' => 20,
            'headers' => ['Accept' => 'application/json', 'Cache-Control' => 'no-cache'],
        ]);
    } else {
        $res = wp_remote_post($cfg[0], [
            'timeout' => 25,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-HPV-Partner-Ts' => $ts,
                'X-HPV-Partner-Nonce' => $nonce,
                'X-HPV-Partner-Sig' => $sig,
            ],
            'body' => $body,
        ]);
    }
    if (is_wp_error($res)) {
        return [0, null, $res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    $raw = (string) wp_remote_retrieve_body($res);
    $data = json_decode($raw, true);
    $data = is_array($data) ? $data : null;
    if ($code >= 200 && $code < 300 && $data !== null) {
        return [$code, $data, ''];
    }
    $server = wp_remote_retrieve_header($res, 'server');
    return [$code, $data, mandala_crm_http_error($code, $raw, $data, is_array($server) ? (string) reset($server) : (string) $server)];
}

/**
 * Egy kérés a CRM-nek: a nyugták és a sor eleje – mohón annyi esemény (legfeljebb $max), amennyi tömörítve belefér a
 * GET-be ($max = 0: csak a nyugták, üres sornál „szívverés” – a parancsok a válaszban jönnek). Ha a sor eleje egymagában
 * sem fér bele: a többtételes készlet-esemény a sorban szétvágva; más esemény egyszer a régi POST-tal. Siker esetén le a
 * sorról, a nyugták törölve, a parancsok végrehajtva (a nyugtájuk a következő kérésben megy).
 *
 * @return array|WP_Error a CRM válasza; hibánál a WP_Error adata: ['n' => a kérés eseményei, 'code' => HTTP-kód,
 *                        'culprit' => true, ha biztosan a sor eleje a hibás (a POST-tal próbált túl nagy esemény)]
 */
function mandala_crm_post(int $max = 300)
{
    $cfg = mandala_crm_config();
    if (!$cfg) {
        return new WP_Error('mandala_crm', 'Nincs összekötve a CRM-mel.');
    }
    for ($round = 0; ; $round++) {
        $queue = mandala_crm_queue();
        $acks = array_values((array) get_option('mandala_crm_acks', []));
        $p = mandala_crm_pack($queue, $acks, max(0, $max), MANDALA_CRM_Z_MAX, (int) ($GLOBALS['mandala_crm_hint'] ?? 0));
        if ($p['n'] > 0 || $p['m'] > 0 || !$queue || $max < 1) {
            break;
        }
        // A sor eleje egymagában sem fér a GET-be.
        $head = $queue[0];
        if ($round > 3) {
            return new WP_Error('mandala_crm', 'A sor eleje nem csomagolható.', ['n' => 1, 'code' => 0]);
        }
        if (($head['type'] ?? '') === 'stock' && count((array) ($head['data']['items'] ?? [])) > 1) {
            mandala_crm_queue_splice(1, mandala_crm_sig($head), mandala_crm_split_stock($head));
            continue;
        }
        if (mandala_crm_body([$head], []) === '') {
            mandala_crm_bury($head, 'nem alakítható JSON-ná');
            continue;
        }
        return mandala_crm_post_single($cfg, $head);
    }
    $GLOBALS['mandala_crm_hint'] = $p['n'];
    $transport = 'get';
    [$code, $data, $err] = mandala_crm_request($cfg, $p['body'], $p['z']);
    if ($code === 404 && ($data['code'] ?? '') === 'rest_no_route') {
        // A CRM még a régi (csak POST-ot ismerő) változat: ugyanez a csomag POST-tal.
        $transport = 'post';
        [$code, $data, $err] = mandala_crm_request($cfg, $p['body'], null);
    }
    if ($err !== '') {
        mandala_crm_state(['error' => $err, 'error_at' => time()]);
        return new WP_Error('mandala_crm', $err, ['n' => $p['n'], 'code' => $code]);
    }
    mandala_crm_accepted($queue, $p['n'], $p['m'], (array) $data, $transport);
    return $data;
}

/** Egyetlen, a GET-be be nem férő esemény: egyszer a régi POST-tal (ha az sem megy, a sor elejének hibája). */
function mandala_crm_post_single(array $cfg, array $head)
{
    [$code, $data, $err] = mandala_crm_request($cfg, mandala_crm_body([$head], []), null);
    if ($err !== '') {
        $err = 'túl nagy esemény (' . (string) ($head['type'] ?? '?') . '), POST: ' . $err;
        mandala_crm_state(['error' => $err, 'error_at' => time()]);
        return new WP_Error('mandala_crm', $err, ['n' => 1, 'code' => $code, 'culprit' => true]);
    }
    mandala_crm_accepted([$head], 1, 0, (array) $data, 'post');
    return $data;
}

/** Sikeres kérés: az elküldött események le a sorról, az elküldött nyugták törölve, a parancsok végrehajtása. */
function mandala_crm_accepted(array $queue, int $n, int $m, array $data, string $transport): void
{
    if ($n > 0) {
        mandala_crm_queue_splice($n, mandala_crm_sig($queue[0]));
        delete_option('mandala_crm_head_fail'); // a sor eleje átment
    }
    if ($m > 0) {
        update_option('mandala_crm_acks', array_slice(array_values((array) get_option('mandala_crm_acks', [])), $m), false);
    }
    $done = [];
    foreach (array_slice((array) ($data['commands'] ?? []), 0, 50) as $cmd) {
        $ack = mandala_crm_run_command((array) $cmd);
        if (strlen((string) wp_json_encode($ack)) > 2000) {
            $ack['result'] = ['error' => 'az eredmény túl hosszú'];
        }
        $done[] = $ack;
    }
    if ($done) {
        update_option('mandala_crm_acks', array_merge(array_values((array) get_option('mandala_crm_acks', [])), $done), false);
    }
    $errors = (array) ($data['errors'] ?? []);
    $st = mandala_crm_state();
    mandala_crm_state(['ok_at' => time(), 'sent' => (int) ($st['sent'] ?? 0) + $n, 'transport' => $transport, 'error' => (string) ($errors[0] ?? '')]
        + ($errors ? ['error_at' => time()] : []));
}

/**
 * A sor kiküldése: időkorlátos ciklus (alapból 80 kérés vagy 15 mp, a kérések közt 150 ms), mindig legalább egy kérés
 * (a CRM parancsai a válaszban jönnek). Hibánál egy gyors újrapróba csak a sor elejével; ha az sem megy, egy üres kérés
 * dönti el, hogy a CRM elérhető-e – ha igen, a sor eleje a hibás (3 egymás utáni futás után félreteszi, és a sor megy
 * tovább). Különben a futás megáll, a hiba (ki válaszolt, mit) az állapotban.
 *
 * @param int|null $used ide kerül, hány kérést indított (az újrapróbák nélkül) – a futás kérés-keretéhez
 * @return bool nem volt hiba (az idő / a kérésszám lejárta nem hiba)
 */
function mandala_crm_flush(int $max_requests = 80, float $seconds = 15.0, ?int &$used = null): bool
{
    $t0 = microtime(true);
    $pause = max(0, (int) apply_filters('mandala_crm_pause_ms', 150)); // a kérések közti szünet
    $ok = true;
    $used = (int) $used;
    for ($i = 0; $i < max(1, $max_requests); $i++) {
        if ($i > 0) {
            if ((!mandala_crm_queue() && !get_option('mandala_crm_acks', [])) || microtime(true) - $t0 > $seconds) {
                break;
            }
            usleep($pause * 1000);
        }
        $used++;
        $r = mandala_crm_post();
        if (!is_wp_error($r)) {
            continue;
        }
        $d = (array) $r->get_error_data();
        if (empty($d['culprit'])) {
            usleep($pause * 2000);
            $r = mandala_crm_post(1); // gyors újrapróba – csak a sor elejével
            if (!is_wp_error($r)) {
                continue;
            }
            $d = (array) $r->get_error_data();
        }
        $ok = false;
        if ((!empty($d['culprit']) || ((int) ($d['n'] ?? 0) > 0 && !is_wp_error(mandala_crm_post(0)))) && mandala_crm_head_failed($r->get_error_message())) {
            continue; // a mérgezett esemény félretéve: a sor megy tovább
        }
        break;
    }
    return $ok;
}

/* ---------- Mérgezett esemény: ami 3 futáson át nem megy át, félre ---------- */

/**
 * A sor eleje ebben a futásban nem ment át, pedig a CRM elérhető: a 3. egymás utáni futás után félreteszi.
 *
 * @return bool félretette-e
 */
function mandala_crm_head_failed(string $msg): bool
{
    $q = mandala_crm_queue();
    if (!$q) {
        return false;
    }
    $sig = mandala_crm_sig($q[0]);
    $f = (array) get_option('mandala_crm_head_fail', []);
    $runs = ($f['sig'] ?? '') === $sig ? (int) ($f['runs'] ?? 0) + 1 : 1;
    if ($runs >= 3) {
        mandala_crm_bury($q[0], $msg);
        delete_option('mandala_crm_head_fail');
    } else {
        update_option('mandala_crm_head_fail', ['sig' => $sig, 'runs' => $runs, 'at' => time()], false);
    }
    mandala_crm_state(['error' => 'a sor eleje (' . (string) ($q[0]['type'] ?? '?') . ') nem megy át, ' . $runs . '/3' . ($runs >= 3 ? ' – félretéve' : '') . ': ' . $msg, 'error_at' => time()]);
    return $runs >= 3;
}

/** Egy esemény félre (le a sor elejéről, ha még ott van): az utolsó 50, okkal és idővel. */
function mandala_crm_bury(array $event, string $reason): void
{
    mandala_crm_queue_splice(1, mandala_crm_sig($event));
    $dead = (array) get_option(MANDALA_CRM_DEAD, []);
    $dead[] = ['at' => time(), 'reason' => function_exists('mb_substr') ? mb_substr($reason, 0, 300) : substr($reason, 0, 300), 'event' => $event];
    update_option(MANDALA_CRM_DEAD, array_slice($dead, -50), false);
}

/** A félretett események vissza a sor végére. Visszaad: hány. */
function mandala_crm_requeue_dead(): int
{
    $events = array_values(array_filter(array_map(fn($d) => is_array($d['event'] ?? null) ? $d['event'] : null, (array) get_option(MANDALA_CRM_DEAD, []))));
    delete_option(MANDALA_CRM_DEAD);
    delete_option('mandala_crm_head_fail');
    mandala_crm_enqueue_many($events);
    return count($events);
}

/* ---------- Zár: egyszerre egy futás ---------- */

/** A zár megszerzése $ttl másodpercre (atomikus: INSERT IGNORE, vagy a lejárt zár átvétele). */
function mandala_crm_lock(int $ttl = 300): bool
{
    global $wpdb;
    $until = (string) (time() + $ttl);
    $ok = (bool) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", MANDALA_CRM_LOCK, $until));
    if (!$ok) {
        $ok = (bool) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d", $until, MANDALA_CRM_LOCK, time()));
    }
    wp_cache_delete(MANDALA_CRM_LOCK, 'options');
    return $ok;
}

function mandala_crm_unlock(): void
{
    global $wpdb;
    $wpdb->delete($wpdb->options, ['option_name' => MANDALA_CRM_LOCK]);
    wp_cache_delete(MANDALA_CRM_LOCK, 'options');
}

/* ---------- Parancsok ---------- */

function mandala_crm_run_command(array $cmd): array
{
    $id = (int) ($cmd['id'] ?? 0);
    $d = (array) ($cmd['data'] ?? []);
    try {
        switch ((string) ($cmd['type'] ?? '')) {
            case 'approve':
                return ['id' => $id, 'ok' => true, 'result' => mandala_crm_approve($d)];
            case 'terms':
                return ['id' => $id, 'ok' => true, 'result' => mandala_crm_terms($d)];
            case 'consent':
                return ['id' => $id, 'ok' => true, 'result' => mandala_crm_consent($d)];
            case 'resync':
                if ((string) ($d['what'] ?? '') === 'web_stats') {
                    mandala_crm_web_stats_add(mandala_crm_last_months(13));
                } else {
                    mandala_crm_backfill_start((string) ($d['what'] ?? 'all'));
                }
                return ['id' => $id, 'ok' => true, 'result' => ['started' => true]];
        }
        return ['id' => $id, 'ok' => false, 'result' => ['error' => 'ismeretlen parancs']];
    } catch (Throwable $e) {
        return ['id' => $id, 'ok' => false, 'result' => ['error' => $e->getMessage()]];
    }
}

/**
 * Jóváhagyás: viszonteladói szerep. Meglévő fióknál a szerep cseréje (a sima vásárlói helyett; adminnál hozzáadás);
 * ha nincs fiók, a WooCommerce nyit egyet, és elküldi a fiók-levelet (jelszó-beállító linkkel).
 */
function mandala_crm_approve(array $d): array
{
    $email = sanitize_email((string) ($d['email'] ?? ''));
    $user = mandala_crm_find_user($d) ?: false;
    $role = (string) (((array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']))[0] ?? 'wholesale_customer');
    $created = false;
    if (!$user) {
        if (!is_email($email) || !function_exists('wc_create_new_customer')) {
            throw new RuntimeException('nincs érvényes e-mail-cím');
        }
        $uid = wc_create_new_customer($email, '', wp_generate_password(20), [
            'first_name' => sanitize_text_field((string) ($d['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($d['last_name'] ?? '')),
            'role' => $role,
        ]);
        if (is_wp_error($uid)) {
            throw new RuntimeException($uid->get_error_message());
        }
        $user = get_userdata($uid);
        $created = true;
    }
    if (!in_array($role, (array) $user->roles, true)) {
        // Hozzáadjuk a viszonteladói szerepet (a fiók többi szerepe – pl. bolti munkatárs – megmarad); csak a sima
        // „customer” szerep megy le, hogy ne legyen kétféle vásárló egyszerre.
        $user->add_role($role);
        if (in_array('customer', (array) $user->roles, true)) {
            $user->remove_role('customer');
        }
    }
    // Számlázási adatok, ha üresek (a CRM-ben javított adattal).
    $map = ['billing_company' => 'company', 'billing_phone' => 'phone', 'billing_postcode' => 'zip', 'billing_city' => 'city', 'billing_address_1' => 'street', 'billing_tax_number' => 'tax_number', 'billing_first_name' => 'first_name', 'billing_last_name' => 'last_name'];
    foreach ($map as $meta => $k) {
        if (!empty($d[$k]) && (string) get_user_meta($user->ID, $meta, true) === '') {
            update_user_meta($user->ID, $meta, sanitize_text_field((string) $d[$k]));
        }
    }
    if ((string) get_user_meta($user->ID, 'billing_email', true) === '') {
        update_user_meta($user->ID, 'billing_email', $user->user_email);
    }
    update_user_meta($user->ID, '_mandala_crm_approved', time());
    clean_user_cache($user->ID);
    return ['user_id' => (int) $user->ID, 'created' => $created];
}

/** A parancs fiókja: a webshop-azonosító, ha az e-mail egyezik (vagy nincs e-mail); különben az e-mail-cím szerint. */
function mandala_crm_find_user(array $d): ?WP_User
{
    $email = sanitize_email((string) ($d['email'] ?? ''));
    $user = !empty($d['user_id']) ? get_userdata((int) $d['user_id']) : false;
    if ((!$user || strtolower($user->user_email) !== strtolower($email)) && $email) {
        $user = get_user_by('email', $email) ?: false;
    }
    return $user instanceof WP_User ? $user : null;
}

/* ---------- Adatok ---------- */

/**
 * Időpont a CRM-nek: ISO 8601 zónával ('c', pl. 2026-10-16T09:40:00+02:00), hogy a CRM ne csúsztassa el (a régi, zóna
 * nélküli helyi időt a CRM UTC-nek vette). $v: unix idő, helyi 'Y-m-d H:i:s' (a webhely zónájában), vagy 'now'.
 */
function mandala_crm_time($v = 'now'): string
{
    if ($v === '' || $v === null || $v === 0 || $v === '0') {
        return '';
    }
    if (is_int($v) || (is_string($v) && ctype_digit($v))) {
        return wp_date('c', (int) $v);
    }
    try {
        return (new DateTimeImmutable((string) $v, wp_timezone()))->format('c');
    } catch (Exception $e) {
        return '';
    }
}

function mandala_crm_customer_data(int $uid): array
{
    $u = get_userdata($uid);
    if (!$u) {
        return [];
    }
    $m = fn($k) => (string) get_user_meta($uid, $k, true);
    return [
        'user_id' => $uid,
        // A párosítás a fiók e-mail-címével (user_email) – a számlázási e-mailt a vásárló maga írja át, az nem köt.
        'email' => $u->user_email,
        'billing_email' => $m('billing_email'),
        'first_name' => $m('billing_first_name') ?: $m('first_name'),
        'last_name' => $m('billing_last_name') ?: $m('last_name'),
        'company' => $m('billing_company'),
        'tax_number' => $m('billing_tax_number') ?: $m('wc_szamlazz_adoszam'),
        'phone' => $m('billing_phone'),
        'zip' => $m('billing_postcode'),
        'city' => $m('billing_city'),
        'street' => trim($m('billing_address_1') . ' ' . $m('billing_address_2')),
        'country' => $m('billing_country') ?: 'HU',
        'wholesale' => mandala_is_wholesale_user($uid),
        'registered' => $u->user_registered ? gmdate('c', (int) strtotime($u->user_registered . ' UTC')) : '',
        'last_active' => ($la = (int) $m('wc_last_active')) ? wp_date('c', $la) : '',
        // Partnerlevél-hozzájárulás (a jelentkezés pipája, a fiókban módosítható) és a CRM-től kapott feltételek.
        'marketing_consent' => $m('_mandala_partner_mail') === 'yes',
        'marketing_consent_at' => mandala_crm_time($m('_mandala_partner_mail_at')),
        'marketing_consent_source' => $m('_mandala_partner_mail_source'), // nagyker | jelentkezes | crm[:…] | leiratkozo-link | mailerlite
        'marketing_consent_text' => $m('_mandala_partner_mail_wording'),   // a pipa pontos szövege (a megadásnál)
        'b2b_arrivals' => $m('_mandala_b2b_arrivals') === 'yes',           // heti új érkezések levele
        'unsubscribed' => mandala_is_unsubscribed($u->user_email),         // minden reklámlevélről leiratkozott
        'terms' => mandala_crm_terms_of($uid),
    ];
}

function mandala_crm_order_data(WC_Order $order, bool $backfill = false): array
{
    $items = [];
    $net = 0.0;
    foreach ($order->get_items() as $item) {
        if (!$item instanceof WC_Order_Item_Product) {
            continue;
        }
        $product = $item->get_product();
        $cat = '';
        if ($product) {
            $terms = get_the_terms($product->get_parent_id() ?: $product->get_id(), 'product_cat');
            $cat = $terms && !is_wp_error($terms) ? $terms[0]->name : '';
        }
        $line = (float) $item->get_total();
        $net += $line;
        $items[] = ['sku' => $product ? (string) $product->get_sku() : '', 'name' => $item->get_name(), 'qty' => (float) $item->get_quantity(), 'net' => round($line, 2), 'category' => $cat];
    }
    $created = $order->get_date_created();
    return [
        'id' => $order->get_id(),
        'number' => (string) $order->get_order_number(),
        'user_id' => (int) $order->get_customer_id(),
        'email' => $order->get_billing_email(),
        'company' => $order->get_billing_company(),
        'name' => trim($order->get_billing_last_name() . ' ' . $order->get_billing_first_name()),
        'tax_number' => (string) $order->get_meta('_billing_tax_number'),
        'created' => $created ? $created->date('c') : '', // ISO zónával (a CRM a saját zónájába váltja)
        'status' => $order->get_status(),
        'net' => round($net, 2),
        'gross' => (float) $order->get_total(),
        'currency' => $order->get_currency(),
        'payment' => $order->get_payment_method_title(),
        'payment_method' => $order->get_payment_method(),
        'payment_days' => (int) $order->get_meta('_mandala_payment_days'),
        'payment_due' => (string) $order->get_meta('_mandala_payment_due'),
        'offer_token' => (string) $order->get_meta('_mandala_offer_token'),
        'items' => $items,
        'wholesale' => mandala_is_wholesale_user((int) $order->get_customer_id()),
        'backfill' => $backfill,
    ];
}

/** Viszonteladó rendelése-e (a vásárló szerepe, vagy a rendeléskor rögzített jelölő). */
function mandala_crm_is_wholesale_order(WC_Order $order): bool
{
    return mandala_is_wholesale_user((int) $order->get_customer_id()) || $order->get_meta('_mandala_wholesale') === 'yes';
}

/**
 * Készlet: a termékindex (név, kategória, cím) + friss készlet a wc_product_meta_lookup táblából (a JUTA közvetlenül
 * írja), nagyker ár, várható érkezés, 90 napos eladás – lekérdezésenként egyben, nem termékenként.
 *
 * @return array sku => sor
 */
function mandala_crm_stock_rows(): array
{
    global $wpdb;
    $lookup = [];
    foreach ($wpdb->get_results("SELECT product_id, sku, stock_quantity, stock_status FROM {$wpdb->prefix}wc_product_meta_lookup", ARRAY_A) as $r) {
        $lookup[(int) $r['product_id']] = $r;
    }
    $metakey = (string) apply_filters('mandala_wholesale_meta_key', 'wholesale_customer_wholesale_price');
    $wholesale = [];
    foreach ($wpdb->get_results($wpdb->prepare("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $metakey), ARRAY_A) as $r) {
        $wholesale[(int) $r['post_id']] = (float) $r['meta_value'];
    }
    $incoming = [];
    foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_mandala_incoming' AND meta_value >= '" . esc_sql(wp_date('Y-m-d')) . "'", ARRAY_A) as $r) {
        $incoming[(int) $r['post_id']] = (string) $r['meta_value'];
    }
    $sold = [];
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}wc_order_product_lookup'")) {
        $since = wp_date('Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT IF(variation_id > 0, variation_id, product_id) pid, SUM(product_qty) q FROM {$wpdb->prefix}wc_order_product_lookup WHERE date_created >= %s GROUP BY pid", $since), ARRAY_A) as $r) {
            $sold[(int) $r['pid']] = (int) $r['q'];
        }
    }
    $out = [];
    foreach (mandala_product_index() as $row) {
        $id = (int) $row['id'];
        $l = $lookup[$id] ?? null;
        $sku = (string) ($row['sku'] ?: ($l['sku'] ?? ''));
        if ($sku === '' || !empty($row['voucher'])) {
            continue;
        }
        $out[$sku] = [
            'sku' => $sku,
            'product_id' => $id,
            'name' => (string) $row['name'],
            'category' => (string) ($row['catLabel'] ?? ''),
            'stock' => $l && $l['stock_quantity'] !== null ? (int) $l['stock_quantity'] : null,
            'in_stock' => $l ? $l['stock_status'] !== 'outofstock' : ($row['stock'] ?? '') !== 'out',
            'wholesale' => $wholesale[$id] ?? null,
            'retail' => isset($row['price']) ? (float) $row['price'] : null,
            'sold90' => $sold[$id] ?? 0,
            'url' => (string) ($row['url'] ?? ''),
            'image' => (string) ($row['img'] ?? ''),
            'incoming' => isset($incoming[$id]) ? wp_date('Y. m. d.', strtotime($incoming[$id])) : '',
        ];
    }
    return $out;
}

/** Készlet a sorba: a változás (ujjlenyomat szerint), vagy a teljes lista – eseményenként 40 tétel, egy írással. */
function mandala_crm_queue_stock(bool $full = false): int
{
    $rows = mandala_crm_stock_rows();
    $old = get_option('mandala_crm_stock_fp', []);
    $old = is_array($old) ? $old : [];
    $fp = [];
    $changed = [];
    foreach ($rows as $sku => $r) {
        $fp[$sku] = substr(md5(wp_json_encode([$r['stock'], $r['in_stock'], $r['wholesale'], $r['retail'], $r['name'], $r['incoming'], $r['sold90']])), 0, 12);
        if ($full || ($old[$sku] ?? '') !== $fp[$sku]) {
            $changed[] = $r;
        }
    }
    // Ami eltűnt a boltból (kivezetett / törölt): elfogyottként.
    foreach (array_diff_key($old, $fp) as $sku => $x) {
        if (!$full) {
            $changed[] = ['sku' => (string) $sku, 'stock' => 0, 'in_stock' => false];
        }
    }
    $events = [];
    if ($full) {
        $chunks = array_chunk($changed, MANDALA_CRM_STOCK_CHUNK) ?: [[]];
        foreach ($chunks as $i => $chunk) {
            $events[] = ['type' => 'stock', 'data' => ['items' => $chunk, 'full_start' => $i === 0, 'full_end' => $i === count($chunks) - 1]];
        }
        update_option('mandala_crm_stock_full_at', time(), false);
    } else {
        foreach (array_chunk($changed, MANDALA_CRM_STOCK_CHUNK) as $chunk) {
            $events[] = ['type' => 'stock', 'data' => ['items' => $chunk]];
        }
    }
    mandala_crm_enqueue_many($events);
    update_option('mandala_crm_stock_fp', $fp, false);
    return count($changed);
}

/* ---------- Webes forgalom havonta (web_stats) ---------- */

/** Az utolsó $n hónap ('YYYY-MM'), a legrégebbitől az aktuálisig. */
function mandala_crm_last_months(int $n): array
{
    $first = new DateTimeImmutable(wp_date('Y-m-01'), wp_timezone());
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $out[] = $first->modify("-$i month")->format('Y-m');
    }
    return $out;
}

/**
 * Egy hónap webes forgalma csatornánként: minden rendelés (a teljesített és a feldolgozás alatti; a lemondott,
 * visszatérített, függő nem), a csatorna a vásárló viszonteladói szerepe / a rendelés jelölője szerint. A visszatérített
 * részösszegek levonva. net = a termékek és díjak nettója (szállítás és ÁFA nélkül), shipping_net = a szállítás nettója.
 * HPOS-kompatibilis (wc_get_orders), oldalanként 200 rendelés.
 *
 * @return array{egyeni: array, kereskedo: array}
 */
function mandala_crm_web_stats(string $month): array
{
    $tz = wp_timezone();
    $from = new DateTimeImmutable($month . '-01 00:00:00', $tz);
    $to = $from->modify('+1 month');
    $zero = ['orders' => 0, 'net' => 0.0, 'gross' => 0.0, 'items' => 0, 'shipping_net' => 0.0];
    $out = ['egyeni' => $zero, 'kereskedo' => $zero];
    $statuses = (array) apply_filters('mandala_crm_web_stats_statuses', ['completed', 'processing']);
    for ($page = 1; $page <= 500; $page++) {
        $orders = wc_get_orders(['type' => 'shop_order', 'status' => $statuses, 'date_created' => $from->getTimestamp() . '...' . ($to->getTimestamp() - 1), 'limit' => 200, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC']);
        foreach ($orders as $order) {
            if (!$order instanceof WC_Order || !apply_filters('mandala_crm_web_stats_include', true, $order)) {
                continue;
            }
            $ch = mandala_crm_is_wholesale_order($order) ? 'kereskedo' : 'egyeni';
            $gross = (float) $order->get_total() - (float) $order->get_total_refunded();
            $tax = (float) $order->get_total_tax() - (float) $order->get_total_tax_refunded();
            $ship = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();
            $out[$ch]['orders']++;
            $out[$ch]['gross'] += $gross;
            $out[$ch]['shipping_net'] += $ship;
            $out[$ch]['net'] += $gross - $tax - $ship;
            $out[$ch]['items'] += (int) $order->get_item_count() - (int) $order->get_item_count_refunded();
        }
        if (count($orders) < 200) {
            break;
        }
    }
    foreach ($out as $ch => $row) {
        foreach (['net', 'gross', 'shipping_net'] as $k) {
            $out[$ch][$k] = round($row[$k], 2);
        }
    }
    return $out;
}

/** Hónapok a számolandók közé (a 10 perces szinkron adagonként számolja és küldi). */
function mandala_crm_web_stats_add(array $months): void
{
    $todo = (array) get_option('mandala_crm_web_stats_todo', []);
    $todo = array_values(array_unique(array_merge($todo, array_filter($months, fn($m) => preg_match('/^\d{4}-\d{2}$/', (string) $m)))));
    update_option('mandala_crm_web_stats_todo', array_slice($todo, -24), false);
}

/** Legfeljebb $max hónap kiszámolása és sorba tétele (csatornánként egy web_stats esemény). */
function mandala_crm_web_stats_step(int $max = 2): int
{
    $todo = (array) get_option('mandala_crm_web_stats_todo', []);
    if (!$todo || !mandala_crm_config() || !function_exists('wc_get_orders')) {
        return 0;
    }
    $done = 0;
    while ($todo && $done < $max) {
        $month = (string) array_shift($todo);
        foreach (mandala_crm_web_stats($month) as $channel => $row) {
            mandala_crm_enqueue('web_stats', ['month' => $month, 'channel' => $channel] + $row + ['computed_at' => mandala_crm_time()]);
        }
        $done++;
        update_option('mandala_crm_web_stats_todo', $todo, false);
    }
    return $done;
}

// Naponta hajnalban: az előző és az aktuális hónap (a késve teljesülő / visszatérített rendelések miatt az előző is).
mandala_recurring('mandala_crm_web_stats_daily', DAY_IN_SECONDS, fn() => (new DateTimeImmutable('tomorrow 03:20', wp_timezone()))->getTimestamp());
add_action('mandala_crm_web_stats_daily', function () {
    if (mandala_crm_config()) {
        mandala_crm_web_stats_add(mandala_crm_last_months(2));
        mandala_crm_web_stats_step();
    }
});

/* ---------- Teljes (újra)küldés ---------- */

/** A teljes készlet a következő futásban megy (a viszonteladókra nem vár); a viszonteladók adagonként. */
function mandala_crm_backfill_start(string $what = 'all'): void
{
    $what = in_array($what, ['customers', 'orders', 'stock', 'all'], true) ? $what : 'all';
    if (in_array($what, ['stock', 'all'], true)) {
        update_option('mandala_crm_stock_full_at', 0, false);
    }
    if ($what !== 'stock') {
        update_option('mandala_crm_backfill', ['what' => $what, 'cursor' => 0, 'started' => time()], false);
    }
}

/**
 * Egy adag a teljes küldésből: viszonteladók (és a rendeléseik), fiókonként mentve, időkerettel ($seconds), és csak
 * amíg a sor nem túl hosszú (előbb fogyjon). Egy hibás fiók / rendelés nem állítja meg (kihagyva, a hiba a visszatérési
 * értékben); ha az előző adag egy fiók közben szakadt meg (időkorlát, memória), azt a fiókot átugorja.
 *
 * @return string[] a kihagyott fiókok / rendelések hibái
 */
function mandala_crm_backfill_step(int $users_per_step = 40, float $seconds = 6.0): array
{
    $b = get_option('mandala_crm_backfill');
    if (!is_array($b)) {
        return [];
    }
    $what = (string) ($b['what'] ?? 'all');
    $errors = [];
    if (!empty($b['current'])) {
        $errors[] = 'fiók #' . (int) $b['current'] . ': a feldolgozása megszakadt (időkorlát / memória?) – kihagyva';
        $b['skipped'] = array_slice(array_merge((array) ($b['skipped'] ?? []), [(int) $b['current']]), -20);
        $b['cursor'] = (int) $b['cursor'] + 1;
        $b['current'] = 0;
        update_option('mandala_crm_backfill', $b, false);
    }
    if ($what !== 'stock') {
        $queued = count(mandala_crm_queue());
        if ($queued >= MANDALA_CRM_BACKFILL_QUEUE) {
            return $errors;
        }
        $t0 = microtime(true);
        $ids = get_users(['role__in' => (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']), 'fields' => 'ID', 'orderby' => 'ID', 'order' => 'ASC', 'number' => $users_per_step, 'offset' => (int) $b['cursor']]);
        foreach ($ids as $uid) {
            $uid = (int) $uid;
            $b['current'] = $uid; // ha a futás itt szakad meg, a következő adag átugorja
            update_option('mandala_crm_backfill', $b, false);
            $events = [];
            try {
                if ($what !== 'orders' && ($c = mandala_crm_customer_data($uid))) {
                    $events[] = ['type' => 'customer', 'data' => $c];
                }
                if ($what !== 'customers' && function_exists('wc_get_orders')) {
                    foreach (wc_get_orders(['customer_id' => $uid, 'limit' => 300, 'orderby' => 'date', 'order' => 'ASC', 'type' => 'shop_order']) as $order) {
                        try {
                            $events[] = ['type' => 'order', 'data' => mandala_crm_order_data($order, true)];
                        } catch (Throwable $e) {
                            $errors[] = 'rendelés #' . (is_object($order) && method_exists($order, 'get_id') ? $order->get_id() : '?') . ': ' . $e->getMessage();
                        }
                    }
                }
            } catch (Throwable $e) {
                $errors[] = 'fiók #' . $uid . ': ' . $e->getMessage();
            }
            mandala_crm_enqueue_many($events);
            $queued += count($events);
            $b['cursor'] = (int) $b['cursor'] + 1;
            $b['current'] = 0;
            update_option('mandala_crm_backfill', $b, false);
            if (microtime(true) - $t0 > $seconds || $queued >= MANDALA_CRM_BACKFILL_QUEUE) {
                return $errors;
            }
        }
        if (count($ids) === $users_per_step) {
            return $errors;
        }
    } else {
        update_option('mandala_crm_stock_full_at', 0, false); // régi, 'stock' fajtájú teljes küldés: a készletet a szinkron viszi
    }
    if ($what === 'all') {
        mandala_crm_web_stats_add(mandala_crm_last_months(13)); // a webes forgalom egy évre visszamenőleg
    }
    delete_option('mandala_crm_backfill');
    mandala_crm_state(['backfill_done' => time()]);
    return $errors;
}

/* ---------- Ütemezés: 10 percenként (és azonnal, ha fontos esemény jött) ---------- */

mandala_recurring('mandala_crm_sync', 10 * MINUTE_IN_SECONDS, fn() => time() + 120);

add_action('mandala_crm_sync', function ($soon = false) {
    mandala_crm_sync((bool) $soon);
});

/**
 * Egy futás: előbb a már sorban álló események kiküldése (fél időkerettel – ha egy későbbi lépés végzetes hibával, pl.
 * időkorláttal vagy memóriahiánnyal állna le, a küldés akkor se akadjon el), aztán a teljes küldés adagja, a készlet (a
 * teljes küldéstől függetlenül), a webes forgalom, hello, végül a sor kiküldése a maradék időben ($seconds: a küldés
 * időkerete, összesen legfeljebb 80 kérés; 10 mp alatt könnyített futás – webes forgalom nélkül). Ha az első küldés
 * hibára futott, a második elmarad (a CRM épp nem fogad; a mérgezett esemény futásonként egyszer számít). Zárral
 * (egyszerre egy), minden lépés külön védve: egy hibás lépés nem állítja meg a küldést. Az állapotban: run_at (a futás
 * kezdete), run_step (épp melyik lépés – ha a futás megszakad, ez marad), run_done, run_error (a végzetes PHP-hiba is).
 * $soon: csak a küldés (fontos esemény után).
 *
 * @return bool lefutott-e (false: nincs összekötve, vagy épp fut egy másik)
 */
function mandala_crm_sync(bool $soon = false, float $seconds = 15.0, string $via = 'ütemező'): bool
{
    if (!mandala_crm_config() || !mandala_crm_lock()) {
        return false;
    }
    static $watch = false;
    if (!$watch) {
        $watch = true;
        register_shutdown_function('mandala_crm_sync_died');
    }
    $GLOBALS['mandala_crm_running'] = true;
    $t0 = microtime(true);
    $used = 0;
    $sent = true;
    $send = function (int $requests, float $secs) use (&$used, &$sent) {
        $sent = false;
        $sent = mandala_crm_flush($requests, $secs, $used);
    };
    $errors = [];
    $step = function (string $name, callable $fn) use (&$errors) {
        mandala_crm_state(['run_step' => $name]);
        try {
            $r = $fn();
            if (is_array($r)) {
                foreach (array_slice($r, 0, 5) as $msg) {
                    $errors[] = $name . ': ' . $msg;
                }
            }
        } catch (Throwable $e) {
            $errors[] = $name . ': ' . $e->getMessage();
        }
        if ($errors) {
            mandala_crm_state(['run_error' => implode('; ', array_slice($errors, -5)), 'run_error_at' => time()]);
        }
    };
    try {
        if ($soon) {
            mandala_crm_state(['soon_at' => time()]);
        } else {
            mandala_crm_state(['run_at' => time(), 'run_via' => $via, 'run_error' => '']);
            $light = $seconds < 10;
            if (mandala_crm_queue() || get_option('mandala_crm_acks', [])) {
                $step('küldés', fn() => $send(40, $seconds / 2));
            }
            $step('teljes küldés', fn() => mandala_crm_backfill_step(40, $light ? 2.0 : 6.0));
            $step('készlet', fn() => mandala_crm_queue_stock((int) get_option('mandala_crm_stock_full_at', 0) < time() - DAY_IN_SECONDS));
            if (!$light && !get_option('mandala_crm_backfill')) {
                $step('webes forgalom', fn() => mandala_crm_web_stats_step());
            }
            $step('hello', function () {
                if ((int) (mandala_crm_state()['hello_at'] ?? 0) < time() - HOUR_IN_SECONDS) {
                    mandala_crm_enqueue('hello', mandala_crm_hello());
                    mandala_crm_state(['hello_at' => time()]);
                }
            });
        }
        if ($sent) {
            $step('küldés', fn() => $send(max(1, 80 - $used), max(1.0, $seconds - (microtime(true) - $t0))));
        }
        mandala_crm_state(['run_step' => ''] + ($soon ? [] : ['run_done' => time()]));
    } finally {
        $GLOBALS['mandala_crm_running'] = false;
        mandala_crm_unlock();
    }
    return true;
}

/** Ha a futás végzetes PHP-hibával állt le (időkorlát, memória): a hiba a lépéssel az állapotba (run_error), a zár fel. */
function mandala_crm_sync_died(): void
{
    if (empty($GLOBALS['mandala_crm_running'])) {
        return;
    }
    $GLOBALS['mandala_crm_running'] = false;
    $e = error_get_last();
    $fatal = $e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
    $step = (string) (mandala_crm_state()['run_step'] ?? '');
    $msg = $fatal ? 'PHP-hiba: ' . (function_exists('mb_substr') ? mb_substr((string) $e['message'], 0, 200) : substr((string) $e['message'], 0, 200)) : 'a futás megszakadt';
    mandala_crm_state(['run_error' => ($step !== '' ? $step . ': ' : '') . $msg, 'run_error_at' => time()]);
    mandala_crm_unlock();
}

/**
 * Tartalék ütemező: ha az Action Scheduler / WP-cron nem fut (az ELIN a loopback POST-ot is tilthatja), egy admin- vagy
 * látogatói oldalbetöltés végén – a válasz elküldése után – lefut a szinkron, ha 9 percnél régebben futott. Ha a válasz
 * nem zárható le előre (nincs fastcgi_finish_request / litespeed_finish_request), csak adminban, 5 mp-es kerettel.
 * REST, AJAX, cron és WP-CLI kérésnél nem.
 */
add_action('shutdown', 'mandala_crm_maybe_run', 1000);
function mandala_crm_maybe_run(): void
{
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('WP_INSTALLING') && WP_INSTALLING)) {
        return;
    }
    if (!mandala_crm_config() || (int) (mandala_crm_state()['run_at'] ?? 0) > time() - 9 * MINUTE_IN_SECONDS) {
        return;
    }
    $finish = function_exists('fastcgi_finish_request') ? 'fastcgi_finish_request' : (function_exists('litespeed_finish_request') ? 'litespeed_finish_request' : '');
    if ($finish === '' && !is_admin()) {
        return;
    }
    if ($finish !== '') {
        ignore_user_abort(true);
        $finish();
        if (function_exists('set_time_limit')) {
            @set_time_limit(120); // phpcs:ignore WordPress.PHP.NoSilencedErrors
        }
    }
    mandala_crm_sync(false, $finish !== '' ? 15.0 : 5.0, 'oldalbetöltés');
}

function mandala_crm_hello(): array
{
    $count = count_users();
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    return [
        'site' => wp_parse_url(home_url(), PHP_URL_HOST),
        'version' => (string) wp_get_theme()->get('Version'),
        'wholesale_users' => array_sum(array_map(fn($r) => (int) ($count['avail_roles'][$r] ?? 0), $roles)),
        'queue' => count(mandala_crm_queue()),
        'dead' => count((array) get_option(MANDALA_CRM_DEAD, [])),
        'backfill' => get_option('mandala_crm_backfill') ? 'fut' : 'kész',
        'features' => 'approve,resync,terms,message,web_stats,consent,offer,consent_cmd,get', // amit ez a témaverzió tud (a CRM ebből láthatja)
    ];
}

/* ---------- Események a webshopból ---------- */

// „Viszonteladóknak” jelentkezés (a feldolgozó után, csak sikeres beküldésnél).
add_filter('iu_form_submit_viszontelado', function ($response) {
    $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification -- az iu_theme űrlapkezelője ellenőriz
    if (!is_array($response) || empty($response['success']) || !empty($response['errors']) || !empty($post['website'])) {
        return $response;
    }
    $f = fn($k) => sanitize_text_field((string) ($post[$k] ?? ''));
    $msg = array_filter([
        sanitize_textarea_field((string) ($post['message'] ?? '')),
        $f('forgalom') ? 'Várható havi rendelés: ' . $f('forgalom') : '',
        $f('erdeklodes') ? 'Érdeklődés: ' . $f('erdeklodes') : '',
    ]);
    mandala_crm_enqueue('application', [
        'email' => sanitize_email((string) ($post['email'] ?? '')),
        'name' => $f('nev'),
        'company' => $f('ceg'),
        'tax_number' => $f('adoszam'),
        'phone' => $f('telefon'),
        'city' => $f('telepules'),
        'website' => $f('weboldal'),
        'type' => $f('tevekenyseg'),
        'message' => implode("\n", $msg),
        'user_id' => get_current_user_id(),
        'marketing_consent' => !empty($post['partnerlevelek']), // „Kérek partnerleveleket” (crm-partner.php)
        'marketing_consent_at' => mandala_crm_time(),
    ]);
    mandala_crm_soon();
    return $response;
}, 20);

// Viszonteladói fiók: új, szerepváltás, adatváltozás.
add_action('user_register', function ($uid) {
    if (mandala_is_wholesale_user((int) $uid)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
        mandala_crm_soon();
    }
}, 30);
add_action('set_user_role', function ($uid, $role, $old = []) {
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    if (in_array($role, $roles, true) || array_intersect($roles, (array) $old)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
    }
}, 30, 3);
add_action('add_user_role', function ($uid, $role) {
    if (in_array($role, (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']), true)) {
        mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
    }
}, 30, 2);
foreach (['profile_update', 'woocommerce_customer_save_address'] as $mandala_hook) {
    add_action($mandala_hook, function ($uid) {
        static $seen = [];
        if (!isset($seen[$uid]) && mandala_is_wholesale_user((int) $uid)) {
            $seen[$uid] = true;
            mandala_crm_enqueue('customer', mandala_crm_customer_data((int) $uid));
        }
    }, 30);
}

// Viszonteladó rendelése: létrejötte és minden állapotváltozása.
add_action('woocommerce_checkout_order_processed', fn($order_id) => mandala_crm_order_event((int) $order_id), 30);
add_action('woocommerce_order_status_changed', fn($order_id) => mandala_crm_order_event((int) $order_id), 30);
function mandala_crm_order_event(int $order_id): void
{
    static $seen = [];
    $order = wc_get_order($order_id);
    if (!$order || isset($seen[$order_id]) || !mandala_crm_is_wholesale_order($order)) {
        return;
    }
    $seen[$order_id] = true;
    if ($order->get_meta('_mandala_wholesale') !== 'yes') {
        $order->update_meta_data('_mandala_wholesale', 'yes'); // a rendelés akkor is viszonteladói marad, ha a szerep később változik
        $order->save_meta_data();
    }
    mandala_crm_enqueue('order', mandala_crm_order_data($order));
}

// Viszonteladó a webshopban: belépés, árlista, fotók, gyorsrendelő, új érkezések levél.
add_action('wp_login', function ($login, $user) {
    if ($user instanceof WP_User && mandala_is_wholesale_user((int) $user->ID)) {
        mandala_crm_enqueue('activity', ['user_id' => (int) $user->ID, 'email' => $user->user_email, 'kind' => 'login', 'at' => mandala_crm_time()]);
    }
}, 30, 2);
add_action('mandala_b2b_activity', function ($kind, $meta = []) {
    $uid = get_current_user_id();
    if ($uid && mandala_is_wholesale_user($uid)) {
        mandala_crm_enqueue('activity', ['user_id' => $uid, 'kind' => (string) $kind, 'at' => mandala_crm_time(), 'meta' => (array) $meta]);
    }
}, 10, 2);

/* ---------- Admin: WooCommerce → Partner CRM ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Partner CRM', 'Partner CRM', 'manage_woocommerce', 'mandala-crm', 'mandala_crm_admin_page');
});

add_action('admin_post_mandala_crm_save', function () {
    if (!current_user_can('manage_woocommerce') || !check_admin_referer('mandala_crm')) {
        wp_die('Nincs jogosultság.');
    }
    $msg = '';
    // Próba / első kapcsolódás: a háttérfutással egyszerre nem (zár), rövid időkerettel.
    $try = function (int $requests, float $seconds): string {
        if (!mandala_crm_lock()) {
            return 'busy';
        }
        try {
            return mandala_crm_flush($requests, $seconds) ? 'ok' : 'error';
        } finally {
            mandala_crm_unlock();
        }
    };
    if (isset($_POST['disconnect'])) {
        delete_option('mandala_crm');
        delete_option(MANDALA_CRM_QUEUE);
        $msg = 'disconnected';
    } elseif (isset($_POST['code'])) {
        $d = mandala_crm_decode_code((string) wp_unslash($_POST['code']));
        if ($d) {
            $first = !mandala_crm_config();
            update_option('mandala_crm', $d, false);
            if ($first || !get_option('mandala_crm_stock_fp')) {
                mandala_crm_backfill_start('all'); // első összekötés: minden viszonteladó, rendelés és a készlet
            }
            mandala_crm_enqueue('hello', mandala_crm_hello());
            $r = $try(1, 5.0);
            $msg = $r === 'ok' ? 'saved' : $r;
        } else {
            $msg = 'badcode';
        }
    } elseif (isset($_POST['resync'])) {
        mandala_crm_backfill_start('all');
        $msg = 'resync';
    } elseif (isset($_POST['test'])) {
        mandala_crm_enqueue('hello', mandala_crm_hello());
        $r = $try(10, 8.0);
        $msg = $r === 'ok' ? 'tested' : $r;
    } elseif (isset($_POST['requeue'])) {
        $msg = mandala_crm_requeue_dead() ? 'requeued' : '';
    } elseif (isset($_POST['levels'])) {
        update_option('mandala_crm_levels', mandala_crm_parse_levels((string) wp_unslash($_POST['levels'])), false);
        $msg = 'levels';
    }
    wp_safe_redirect(add_query_arg(['page' => 'mandala-crm', 'msg' => $msg], admin_url('admin.php')));
    exit;
});

function mandala_crm_admin_page(): void
{
    $cfg = mandala_crm_config();
    $st = mandala_crm_state();
    $q = mandala_crm_queue();
    $b = get_option('mandala_crm_backfill');
    $dead = (array) get_option(MANDALA_CRM_DEAD, []);
    $msgs = [
        'saved' => ['success', 'Összekötve. A viszonteladók, a rendeléseik és a készlet a következő percekben átmennek a CRM-be (10 percenként egy adag).'],
        'tested' => ['success', 'A CRM válaszolt – a kapcsolat rendben.'],
        'resync' => ['info', 'A teljes újraküldés elindult (10 percenként egy adag; a készlet a következő futásban).'],
        'disconnected' => ['warning', 'Szétkapcsolva. A webshop nem küld többet a CRM-nek.'],
        'levels' => ['success', 'A kedvezményszintek mentve.'],
        'requeued' => ['success', 'A félretett események visszakerültek a sor végére.'],
        'busy' => ['info', 'Épp fut egy küldés a háttérben – nézd meg egy perc múlva.'],
        'badcode' => ['error', 'Ez nem érvényes összekötő kód. Másold ki újra a CRM-ből (Partnerek → Beállítások → Összekötő kód).'],
        'error' => ['error', 'A CRM most nem válaszolt: ' . ($st['error'] ?? '') . '. A webshop 10 percenként újrapróbálja.'],
    ];
    $m = $msgs[sanitize_key($_GET['msg'] ?? '')] ?? null; // phpcs:ignore WordPress.Security.NonceVerification
    $when = fn($t) => esc_html(wp_date('Y-m-d H:i', (int) $t));
    echo '<div class="wrap"><h1>Partner CRM</h1>';
    if ($m) {
        echo '<div class="notice notice-' . esc_attr($m[0]) . '"><p>' . esc_html($m[1]) . '</p></div>';
    }
    echo '<p>A viszonteladói jelentkezések, fiókok, rendelések, belépések, árlista-letöltések és a készlet a Mandala Partner CRM-be mennek; a CRM-ben jóváhagyott jelentkező itt kapja meg a viszonteladói szerepet. Mindig a webshop kapcsolódik a CRM-hez, 10 percenként.</p>';
    echo '<table class="widefat striped" style="max-width:760px"><tbody>';
    $row = fn($k, $v) => print('<tr><th style="width:220px">' . esc_html($k) . '</th><td>' . $v . '</td></tr>'); // phpcs:ignore
    $red = fn($s) => '<span style="color:#b3261e">' . esc_html((string) $s) . '</span>';
    $inline = fn($inner) => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">' . wp_nonce_field('mandala_crm', '_wpnonce', true, false) . '<input type="hidden" name="action" value="mandala_crm_save">' . $inner . '</form>';
    $row('Állapot', $cfg ? '<strong style="color:#14692f">összekötve</strong> – ' . esc_html(wp_parse_url($cfg[0], PHP_URL_HOST)) . (defined('MANDALA_CRM_URL') ? ' (wp-config.php)' : '') : '<strong>nincs összekötve</strong>');
    $row('Utolsó sikeres kapcsolat', !empty($st['ok_at']) ? $when($st['ok_at']) : '–');
    $run = '–';
    if (!empty($st['run_at'])) {
        $run = $when($st['run_at']) . ' (' . esc_html((string) ($st['run_via'] ?? '')) . ')';
        if ((int) ($st['run_done'] ?? 0) < (int) $st['run_at'] && (int) $st['run_at'] < time() - 5 * MINUTE_IN_SECONDS) {
            $run .= ' – ' . $red('nem fejeződött be' . (!empty($st['run_step']) ? ' (' . $st['run_step'] . ')' : '') . ': időkorlát vagy memória?');
        } elseif ((int) $st['run_at'] < time() - 30 * MINUTE_IN_SECONDS) {
            $run .= ' – ' . $red('fél órája nem futott (WP-cron / Action Scheduler?); egy admin oldal megnyitása elindítja');
        }
    }
    $row('Utolsó háttérfuttatás', $run);
    if (!empty($st['run_error'])) {
        $row('Háttérhiba', $red($st['run_error']) . (!empty($st['run_error_at']) ? ' (' . $when($st['run_error_at']) . ')' : ''));
    }
    $row('Átvitel', 'GET, tömörítve és aláírva – az ELIN tűzfala a szerverek közti POST-ot gyakran tiltja' . (($st['transport'] ?? '') === 'post' ? ' <em>(a legutóbbi kérés POST-tal ment: a CRM még a régi változat, vagy túl nagy esemény)</em>' : ''));
    $row('Elküldött esemény', esc_html((string) (int) ($st['sent'] ?? 0)));
    $row('Sorban áll', esc_html((string) count($q)));
    $row('Teljes küldés', $b ? 'folyamatban (' . (int) $b['cursor'] . ' viszonteladó kész' . (!empty($b['skipped']) ? ', kihagyva: #' . esc_html(implode(', #', array_map('intval', (array) $b['skipped']))) : '') . ')' : (!empty($st['backfill_done']) ? 'kész: ' . $when($st['backfill_done']) : '–'));
    $full_at = (int) get_option('mandala_crm_stock_full_at', 0);
    $row('Teljes készletlista', $full_at ? 'sorba téve: ' . $when($full_at) . ' (naponta újra)' : 'a következő futásban');
    $dead_cell = esc_html((string) count($dead));
    if ($dead) {
        $list = implode('', array_map(fn($d) => '<li>' . $when($d['at'] ?? 0) . ' – ' . esc_html((string) ($d['event']['type'] ?? '?')) . ': ' . esc_html((string) ($d['reason'] ?? '')) . '</li>', array_slice(array_reverse($dead), 0, 10)));
        $dead_cell .= ' ' . $inline('<button class="button button-small" name="requeue" value="1">Vissza a sorba</button>')
            . '<details style="margin-top:6px"><summary>Miért?</summary><ul style="margin:6px 0 0 18px;list-style:disc">' . $list . '</ul></details>';
    }
    $row('Félretett események', $dead_cell);
    if (!empty($st['error'])) {
        $row('Utolsó hiba', $red($st['error']) . (!empty($st['error_at']) ? ' (' . $when($st['error_at']) . ')' : ''));
    }
    echo '</tbody></table>';
    $form = fn($inner) => '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px">' . wp_nonce_field('mandala_crm', '_wpnonce', true, false) . '<input type="hidden" name="action" value="mandala_crm_save">' . $inner . '</form>';
    if (!defined('MANDALA_CRM_URL')) {
        echo $form('<h2>Összekötő kód</h2><p>A CRM-ben: Partnerek → Beállítások → Összekötő kód. Titkos – csak ide másold.</p><textarea name="code" rows="3" style="width:100%;max-width:760px" placeholder="' . ($cfg ? 'Új kód (csak ha cserélni kell)' : 'eyJ2Ijox…') . '"></textarea><p><button class="button button-primary">Mentés és kapcsolódás</button></p>'); // phpcs:ignore
    }
    if ($cfg) {
        echo $form('<p><button class="button" name="test" value="1">Kapcsolat próbája most</button> <button class="button" name="resync" value="1">Teljes újraküldés</button> ' . (!defined('MANDALA_CRM_URL') ? '<button class="button button-link-delete" name="disconnect" value="1" onclick="return confirm(\'Biztosan szétkapcsolod?\')">Szétkapcsolás</button>' : '') . '</p>'); // phpcs:ignore
    }
    // Kedvezményszintek: a CRM a szint nevét küldi (pl. „Kiemelt”); itt adható meg, hány % jár a nagyker árból.
    $levels = (array) get_option('mandala_crm_levels', []);
    $text = implode("\n", array_map(fn($k, $v) => $k . ' = ' . rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'), array_keys($levels), $levels));
    echo $form('<h2>Kedvezményszintek</h2><p>A CRM a partner kartonján beállított szint nevét küldi. Ha egy szinthez százalék is jár, írd ide soronként (pl. <code>Kiemelt = 5</code>): a viszonteladó a kosárban ennyivel olcsóbban kapja a nagyker árat. Üresen a szint csak a fiókjában látszik.</p><textarea name="levels" rows="4" style="width:100%;max-width:420px" placeholder="Alap nagyker = 0' . "\n" . 'Kiemelt = 5">' . esc_textarea($text) . '</textarea><p><button class="button">Mentés</button></p>'); // phpcs:ignore
    echo '</div>';
}

/** „Kiemelt = 5” soronként → ['Kiemelt' => 5.0] (0–50 %). */
function mandala_crm_parse_levels(string $text): array
{
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        if (preg_match('/^\s*(.+?)\s*[=:]\s*(\d+(?:[.,]\d+)?)\s*%?\s*$/u', $line, $m)) {
            $out[sanitize_text_field($m[1])] = max(0.0, min(50.0, (float) str_replace(',', '.', $m[2])));
        }
    }
    return $out;
}

/* ---------- Őrszem ---------- */

add_filter('mandala_health_checks', function ($out) {
    if (!mandala_crm_config()) {
        return $out;
    }
    $st = mandala_crm_state();
    $now = time();
    $ok_at = (int) ($st['ok_at'] ?? 0);
    $run_at = (int) ($st['run_at'] ?? 0);
    $dead = count((array) get_option(MANDALA_CRM_DEAD, []));
    $warn = [];
    if ($run_at < $now - 30 * MINUTE_IN_SECONDS) {
        $warn[] = $run_at ? sprintf('%d perce nem futott a háttérküldés (WP-cron / Action Scheduler?)', (int) floor(($now - $run_at) / 60)) : 'a háttérküldés még nem futott';
    } elseif ((int) ($st['run_done'] ?? 0) < $run_at && $run_at < $now - 5 * MINUTE_IN_SECONDS) {
        $warn[] = 'a legutóbbi háttérküldés nem fejeződött be' . (!empty($st['run_step']) ? ' (' . $st['run_step'] . ')' : '') . (!empty($st['run_error']) ? ': ' . $st['run_error'] : '');
    }
    if ($ok_at < $now - HOUR_IN_SECONDS) {
        $warn[] = ($ok_at ? 'utolsó sikeres kapcsolat: ' . wp_date('m-d H:i', $ok_at) : 'még nem kapcsolódott') . (!empty($st['error']) ? ' – ' . $st['error'] : '');
    }
    if ($dead) {
        $warn[] = $dead . ' félretett esemény (WooCommerce → Partner CRM)';
    }
    $status = $warn ? 'warn' : 'ok';
    $msg = $warn ? implode('; ', $warn) . '.' : 'Utolsó kapcsolat: ' . wp_date('H:i', $ok_at) . ', sorban: ' . count(mandala_crm_queue()) . '.';
    $out['crm'] = ['label' => 'Partner CRM összekötő', 'status' => $status, 'ok' => $status === 'ok', 'msg' => $msg];
    return $out;
});
