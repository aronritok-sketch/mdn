<?php
/**
 * Termékszövegek: egészségügyi állítások → szabálykövető megfogalmazás (Áron, 2026-11-16: „Írjuk át a Mandalában.”).
 * A Meta okt. óta korlátozott (védett adat) módban kezeli a Mandala pixeleit; a valószínű kiváltó a termékszövegek
 * gyógyhatás-ígérete („érzelmi gyógyulás”, „hangterápia”, „stresszoldó”, „segíti az alvást” …). A cserelista:
 * inc/data/health-rewrite-2611.json – [régi mondat, új mondat, termékek].
 *
 * Futtatás egyszer, a sitefix-listából (sitefix.php): előbb 5 próbaterméken, utána az összesen. Termékenként előbb
 * mentés (_mandala_hr_backup_2611: az eredeti leírás és rövid leírás), csak utána csere; visszaállítás:
 * mandala_hr_restore(). A csere a tárolt HTML-ben megy: a mondat szavai között szóköz, &nbsp;, sortörés vagy HTML-címke
 * is lehet, a gondolatjel és az idézőjel tárolt alakja is többféle (wptexturize).
 */

defined('ABSPATH') || exit;

const MANDALA_HR_BACKUP = '_mandala_hr_backup_2611';

/** A cserelista: ['test' => [id…], 'pairs' => [[régi, új, [id…]], …]]. */
function mandala_hr_data(): array
{
    static $data = null;
    if ($data === null) {
        $raw = (string) @file_get_contents(get_stylesheet_directory() . '/inc/data/health-rewrite-2611.json');
        $data = json_decode($raw, true);
        $data = is_array($data) ? $data : ['test' => [], 'pairs' => []];
    }
    return $data;
}

/**
 * Keresőminta egy megjelenített mondathoz a tárolt HTML-ben (tesztelhető): a szóköz helyén szóköz / &nbsp; / címke,
 * a gondolatjel és az idézőjelek tárolt alakjai, a karakterek között opcionális HTML-címke.
 */
function mandala_hr_pattern(string $old): string
{
    $map = [
        '–' => '(?:–|&#8211;|&ndash;|-)',
        '—' => '(?:—|&#8212;|&mdash;|--)',
        '„' => '(?:„|&#8222;|&bdquo;|")',
        '”' => '(?:”|&#8221;|&rdquo;|")',
        '“' => '(?:“|&#8220;|&ldquo;|")',
        '’' => "(?:’|&#8217;|&rsquo;|')",
        '…' => '(?:…|&#8230;|&hellip;|\.\.\.)',
        '&' => '(?:&|&amp;|&#038;)',
    ];
    $tag = '(?:<[^>]*>)*';
    $out = '';
    foreach (preg_split('//u', $old, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        if (preg_match('/\s/u', $ch)) {
            $piece = '(?:\s|&nbsp;|&#160;|\x{00A0}|<[^>]*>)+';
        } else {
            $piece = $map[$ch] ?? preg_quote($ch, '/');
        }
        $out .= ($out === '' ? '' : $tag) . $piece;
    }
    return '/' . $out . '/u';
}

/** Egy HTML-szövegben a párok cseréje; a találatban lévő címkék a csere után megmaradnak (tesztelhető). */
function mandala_hr_apply(string $html, array $pairs, int &$count): string
{
    foreach ($pairs as [$old, $new]) {
        $html = (string) preg_replace_callback(mandala_hr_pattern((string) $old), function ($m) use ($new, &$count) {
            $count++;
            preg_match_all('/<[^>]*>/', $m[0], $tags);
            // A mondaton belüli címkék (pl. egy záró </strong>) a mondat után maradnak, hogy a HTML ép maradjon.
            return mandala_hr_html((string) $new) . implode('', $tags[0]);
        }, $html);
    }
    return $html;
}

/** Az új szöveg úgy kerül a HTML-be, ahogy a többi szöveg: a & és a < > kódolva (idézőjel, gondolatjel marad). */
function mandala_hr_html(string $s): string
{
    return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
}

/**
 * Futtatás: $only = termékazonosítók (üres: a lista összes terméke). Mentés előtte, csere, gyorsítótár, index és feed.
 * Visszaad: rövid összefoglaló (a sitefix-naplóba).
 */
function mandala_hr_run(array $only = [], ?array $pairs_override = null): string
{
    $data = $pairs_override !== null ? ['pairs' => $pairs_override] : mandala_hr_data();
    if (!$data['pairs']) {
        return 'nincs cserelista';
    }
    $byId = [];
    foreach ($data['pairs'] as [$old, $new, $ids]) {
        foreach ((array) $ids as $id) {
            $byId[(int) $id][] = [$old, $new];
        }
    }
    if ($only) {
        $byId = array_intersect_key($byId, array_flip(array_map('intval', $only)));
    }
    $changed = 0;
    $hits = 0;
    $miss = [];
    foreach ($byId as $id => $pairs) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'product') {
            $miss[] = $id;
            continue;
        }
        if (!metadata_exists('post', $id, MANDALA_HR_BACKUP)) {
            update_post_meta($id, MANDALA_HR_BACKUP, wp_slash(['content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'time' => time()]));
        }
        $n = 0;
        $content = mandala_hr_apply((string) $post->post_content, $pairs, $n);
        $excerpt = mandala_hr_apply((string) $post->post_excerpt, $pairs, $n);
        if ($n === 0) {
            $miss[] = $id;
            continue;
        }
        $r = wp_update_post(['ID' => $id, 'post_content' => wp_slash($content), 'post_excerpt' => wp_slash($excerpt)], true);
        if (is_wp_error($r)) {
            $miss[] = $id;
            continue;
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($id);
        }
        $changed++;
        $hits += $n;
    }
    // A statikus termékindex (rövid leírás) és a hirdetési feedek az új szöveggel.
    if (function_exists('mandala_flush_index')) {
        mandala_flush_index();
    }
    // A feed csak akkor épül újra, ha változott az ujjlenyomata – a hosszú leírás nincs benne, ezért kényszerítjük.
    $built = get_option('mandala_feeds_built');
    if (is_array($built)) {
        $built['sig'] = '';
        update_option('mandala_feeds_built', $built, false);
    }
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('mandala_feeds_build', [], 'mandala');
    }
    update_option('mandala_hr_log_2611', ['time' => time(), 'only' => $only, 'changed' => $changed, 'hits' => $hits, 'miss' => $miss], false);
    return sprintf('%d termék, %d mondat átírva%s', $changed, $hits, $miss ? '; nem talált: ' . implode(', ', array_slice($miss, 0, 20)) : '');
}

/** Visszaállítás a mentésből (vészhelyzetre; a sitefix-listába kell felvenni, ha kell). */
function mandala_hr_restore(): string
{
    $n = 0;
    foreach (get_posts(['post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => MANDALA_HR_BACKUP]) as $id) {
        $b = get_post_meta($id, MANDALA_HR_BACKUP, true);
        if (is_array($b) && isset($b['content'])) {
            wp_update_post(['ID' => $id, 'post_content' => wp_slash((string) $b['content']), 'post_excerpt' => wp_slash((string) ($b['excerpt'] ?? ''))]);
            $n++;
        }
    }
    if (function_exists('mandala_flush_index')) {
        mandala_flush_index();
    }
    return $n . ' termék visszaállítva';
}
