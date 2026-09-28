<?php
/**
 * Szezonális kampányok (Black Friday, Karácsony, Valentin-nap, Nőnap, Anyák napja, Újév…): előre
 * beállítva, dátumra magától indulnak és állnak le.
 *
 * Aktív kampány alatt:
 *  - a felső sáv a kampány szövege, visszaszámlálóval és linkkel;
 *  - a főoldalon kampánybanner (mandala/campaign-banner blokk) a hős alatt;
 *  - opcionális kupon: automatikusan minden kosárra, vagy csak a kampány linkjével (?kupon=KOD).
 * A ?kupon=KOD link kampánytól függetlenül is működik (hírlevélben, hirdetésben): a kupon a kosárba kerül.
 *
 * Admin: Marketing → Kampányok (sablonok a jellemző dátumokkal és szövegekkel).
 */

defined('ABSPATH') || exit;

function mandala_campaigns(): array
{
    return array_values(array_filter((array) get_option('mandala_campaigns', []), 'is_array'));
}
/** Az éppen futó kampány (az első aktív a listában), vagy null. $now: időbélyeg (tesztekhez). */
function mandala_active_campaign(?int $now = null): ?array
{
    $now ??= time();
    foreach (mandala_campaigns() as $c) {
        if (($c['enabled'] ?? 'yes') !== 'yes') {
            continue;
        }
        $start = strtotime($c['start'] . ' ' . wp_timezone_string());
        $end = strtotime($c['end'] . ' ' . wp_timezone_string());
        if ($start && $end && $now >= $start && $now < $end) {
            return $c;
        }
    }
    return null;
}
function mandala_campaign_url(array $c): string
{
    $url = $c['url'] ?: mandala_shop_url();
    $url = str_starts_with($url, 'http') ? $url : home_url('/' . ltrim($url, '/'));
    return !empty($c['coupon']) && ($c['auto'] ?? 'no') !== 'yes' ? add_query_arg('kupon', $c['coupon'], $url) : $url;
}

/* ---------- Felső sáv ---------- */

add_filter('mandala_notice_override', function ($html) {
    $c = mandala_active_campaign();
    if (!$c || $c['bar'] === '') {
        return $html;
    }
    $end = strtotime($c['end'] . ' ' . wp_timezone_string());
    return '<p class="mandala-notice campaign-bar"><a href="' . esc_url(mandala_campaign_url($c)) . '">' . mandala_icon('sparkle', 'ico ico-s') . ' <strong>' . esc_html($c['bar']) . '</strong>'
        . '<span class="campaign-count" data-countdown="' . (int) $end . '"></span></a></p>' . mandala_campaign_countdown_js();
});

/** Kis visszaszámláló („még 2 nap 5 óra”) – egyszer kerül az oldalba. */
function mandala_campaign_countdown_js(): string
{
    static $done = false;
    if ($done) {
        return '';
    }
    $done = true;
    return '<script>(function(){function t(){document.querySelectorAll("[data-countdown]").forEach(function(e){var s=Math.max(0,e.dataset.countdown*1000-Date.now())/1000,d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60);'
        . 'e.textContent=s<=0?"":" · még "+(d?d+" nap "+h+" óra":h?h+" óra "+m+" perc":m+" perc");});}t();setInterval(t,30000);})();</script>';
}

/* ---------- Főoldali banner ---------- */

add_action('init', function () {
    if (!function_exists('mandala_add_block')) {
        return;
    }
    mandala_add_block('mandala/campaign-banner', [
        'title' => 'Kampánybanner (csak aktív kampány alatt)',
        'template' => function () {
            $c = mandala_active_campaign();
            if (!$c || $c['title'] === '') {
                return '';
            }
            $end = strtotime($c['end'] . ' ' . wp_timezone_string());
            return '<div class="campaign-banner"><div class="campaign-text">' . ($c['eyebrow'] ? '<p class="eyebrow">' . esc_html($c['eyebrow']) . '</p>' : '')
                . '<h2>' . esc_html($c['title']) . '</h2>' . ($c['text'] ? '<p>' . esc_html($c['text']) . '</p>' : '')
                . '<p class="campaign-count-big"><span data-countdown="' . (int) $end . '"></span></p></div>'
                . '<a class="iu-button iu-button-large" href="' . esc_url(mandala_campaign_url($c)) . '">' . esc_html($c['button'] ?: __('Megnézem', 'mandala')) . ' ' . mandala_icon('arrow', 'ico ico-s') . '</a></div>'
                . mandala_campaign_countdown_js();
        },
    ]);
}, 25);

/* ---------- Kupon: automatikusan vagy ?kupon=KOD linkkel ---------- */

add_action('wp_loaded', function () {
    if (empty($_GET['kupon']) || !function_exists('WC') || !WC()->session) { // phpcs:ignore
        return;
    }
    $code = wc_format_coupon_code(sanitize_text_field(wp_unslash($_GET['kupon']))); // phpcs:ignore
    if ($code && wc_get_coupon_id_by_code($code)) {
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true); // vendég: üres kosárnál is megmaradjon
        }
        WC()->session->set('mandala_link_coupon', $code);
        if (WC()->cart && !WC()->cart->is_empty() && !WC()->cart->has_discount($code)) {
            WC()->cart->apply_coupon($code);
        }
    }
}, 25);
add_action('woocommerce_before_calculate_totals', function ($cart) {
    static $busy = false;
    if ($busy || is_admin() || $cart->is_empty()) {
        return;
    }
    $c = mandala_active_campaign();
    $code = $c && !empty($c['coupon']) && ($c['auto'] ?? 'no') === 'yes' ? wc_format_coupon_code($c['coupon']) : (string) (WC()->session ? WC()->session->get('mandala_link_coupon') : '');
    if ($code === '' || $cart->has_discount($code) || !wc_get_coupon_id_by_code($code)) {
        return;
    }
    // Más (egyedi használatú) kupon mellé nem erőltetjük.
    foreach ($cart->get_applied_coupons() as $applied) {
        if ((new WC_Coupon($applied))->get_individual_use()) {
            return;
        }
    }
    $busy = true;
    add_filter('woocommerce_coupon_message', '__return_empty_string');
    $cart->apply_coupon($code);
    remove_filter('woocommerce_coupon_message', '__return_empty_string');
    $busy = false;
}, 7);

/* ---------- Sablonok ---------- */

/** A kampánysablonok a következő alkalom dátumaival. */
function mandala_campaign_presets(): array
{
    $tz = wp_timezone();
    $y = (int) (new DateTime('now', $tz))->format('Y');
    $next = function (callable $make) use ($y, $tz) {
        foreach ([$y, $y + 1] as $yy) {
            [$s, $e] = $make($yy);
            if (new DateTime($e, $tz) > new DateTime('now', $tz)) {
                return [$s, $e];
            }
        }
        return $make($y + 1);
    };
    $bf = $next(function ($yy) {
        $d = new DateTime("last friday of november $yy");
        return [$d->format('Y-m-d') . ' 00:00', (clone $d)->modify('+4 day')->format('Y-m-d') . ' 00:00']; // péntek → hétfő éjfél (Cyber Monday is)
    });
    $mom = $next(function ($yy) {
        $d = new DateTime("first sunday of may $yy");
        return [(clone $d)->modify('-14 day')->format('Y-m-d') . ' 00:00', $d->format('Y-m-d') . ' 00:00'];
    });
    $shop = mandala_shop_url();
    return [
        'blackfriday' => ['name' => 'Black Friday', 'start' => $bf[0], 'end' => $bf[1], 'bar' => 'Black Friday: akciós darabok, amíg a készlet tart', 'eyebrow' => 'Black Friday – hétfő éjfélig',
            'title' => 'Az év legnagyobb kedvezményei', 'text' => 'Hangtálak, füstölők, szobrok és textilek akciós áron – kézműves darabok, korlátozott készlet.', 'button' => 'Akciós darabok', 'url' => add_query_arg('allapot', 'akcios', $shop), 'coupon' => '', 'auto' => 'no'],
        'karacsony' => ['name' => 'Karácsony', 'start' => $next(fn($yy) => ["$yy-12-01 00:00", "$yy-12-19 12:00"])[0], 'end' => $next(fn($yy) => ["$yy-12-01 00:00", "$yy-12-19 12:00"])[1],
            'bar' => 'Karácsonyi rendelés: december 19-ig garantáltan megérkezik', 'eyebrow' => 'Karácsony', 'title' => 'Ajándék, ami jelent is valamit', 'text' => 'Ajándékválasztó, összeállított ajándékcsomagok és utalvány – díszcsomagolással, kézzel írt kártyával.',
            'button' => 'Ajándékötletek', 'url' => add_query_arg('szandek', 'ajandek', $shop), 'coupon' => '', 'auto' => 'no'],
        'valentin' => ['name' => 'Valentin-nap', 'start' => $next(fn($yy) => ["$yy-02-01 00:00", "$yy-02-14 00:00"])[0], 'end' => $next(fn($yy) => ["$yy-02-01 00:00", "$yy-02-14 00:00"])[1],
            'bar' => 'Valentin-nap: szívből jövő ajándékok', 'eyebrow' => 'Valentin-nap', 'title' => 'Figyelmes ajándék, nem csak február 14-re', 'text' => 'Rózsakvarc mala, illóolajok, közös hangfürdő-jegy – válogatás párban.', 'button' => 'Válogatás', 'url' => add_query_arg('szandek', 'ajandek', $shop), 'coupon' => '', 'auto' => 'no'],
        'nonap' => ['name' => 'Nőnap', 'start' => $next(fn($yy) => ["$yy-03-01 00:00", "$yy-03-08 23:59"])[0], 'end' => $next(fn($yy) => ["$yy-03-01 00:00", "$yy-03-08 23:59"])[1],
            'bar' => 'Nőnap: ajándék, ami megállít egy pillanatra', 'eyebrow' => 'Nőnap – március 8.', 'title' => 'Egy pillanat csak neki', 'text' => 'Füstölők, illóolajok, ékszerek és kendők – kézzel válogatva.', 'button' => 'Ajándékötletek', 'url' => add_query_arg('szandek', 'ajandek', $shop), 'coupon' => '', 'auto' => 'no'],
        'anyaknapja' => ['name' => 'Anyák napja', 'start' => $mom[0], 'end' => $mom[1], 'bar' => 'Anyák napja: rendelj időben, díszcsomagolással', 'eyebrow' => 'Anyák napja – május első vasárnapja',
            'title' => 'Köszönet, amit kézbe lehet venni', 'text' => 'Összeállított ajándékcsomagok és utalvány – kérésre kézzel írt kártyával.', 'button' => 'Ajándékcsomag', 'url' => mandala_url(['page' => 'ajandekcsomag']) ?: $shop, 'coupon' => '', 'auto' => 'no'],
        'ujev' => ['name' => 'Újév', 'start' => $next(fn($yy) => ["$yy-01-01 00:00", "$yy-01-15 00:00"])[0], 'end' => $next(fn($yy) => ["$yy-01-01 00:00", "$yy-01-15 00:00"])[1],
            'bar' => 'Új év, csendesebb kezdet – meditációs kellékek', 'eyebrow' => 'Újév', 'title' => 'Kezdd csendesebben az évet', 'text' => 'Hangtálak, meditációs párnák és füstölők a napi gyakorláshoz.', 'button' => 'Elcsendesülés', 'url' => add_query_arg('szandek', 'csend', $shop), 'coupon' => '', 'auto' => 'no'],
    ];
}

/* ---------- Admin: Marketing → Kampányok ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce-marketing', 'Kampányok', 'Kampányok', 'manage_woocommerce', 'mandala-campaigns', 'mandala_campaigns_admin');
}, 20);
function mandala_campaigns_admin(): void
{
    $all = mandala_campaigns();
    $fields = ['name', 'start', 'end', 'bar', 'eyebrow', 'title', 'text', 'button', 'url', 'coupon'];
    if (!empty($_POST['camp_do']) && check_admin_referer('mandala_campaigns')) {
        $do = sanitize_key(wp_unslash($_POST['camp_do']));
        $i = (int) ($_POST['i'] ?? -1);
        if ($do === 'save') {
            $c = [];
            foreach ($fields as $f) {
                $c[$f] = sanitize_text_field(wp_unslash($_POST[$f] ?? ''));
            }
            $c['start'] = str_replace('T', ' ', $c['start']);
            $c['end'] = str_replace('T', ' ', $c['end']);
            $c['coupon'] = wc_format_coupon_code($c['coupon']);
            $c['auto'] = empty($_POST['auto']) ? 'no' : 'yes';
            $c['enabled'] = empty($_POST['enabled']) ? 'no' : 'yes';
            if ($c['name'] && strtotime($c['start']) && strtotime($c['end']) > strtotime($c['start'])) {
                if ($i >= 0 && isset($all[$i])) {
                    $all[$i] = $c;
                } else {
                    $all[] = $c;
                }
                echo '<div class="notice notice-success"><p>Mentve.' . ($c['coupon'] && !wc_get_coupon_id_by_code($c['coupon']) ? ' <strong>Figyelem: a „' . esc_html($c['coupon']) . '” kupon még nem létezik – hozd létre a Marketing → Kuponok alatt.</strong>' : '') . '</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Adj nevet, és a vége legyen a kezdés után.</p></div>';
            }
        } elseif ($do === 'delete' && isset($all[$i])) {
            array_splice($all, $i, 1);
        }
        update_option('mandala_campaigns', $all, false);
    }
    $active = mandala_active_campaign();
    $presets = mandala_campaign_presets();
    $edit = isset($_GET['edit']) ? ($all[(int) $_GET['edit']] ?? null) : (isset($_GET['preset']) ? ($presets[sanitize_key(wp_unslash($_GET['preset']))] ?? null) : null); // phpcs:ignore
    $nonce = wp_nonce_field('mandala_campaigns', '_wpnonce', true, false);
    echo '<div class="wrap"><h1>Kampányok</h1><p>Dátumra magától induló és leálló kampányok: felső sáv visszaszámlálóval, főoldali banner, opcionális kupon. Most fut: <strong>' . esc_html($active['name'] ?? 'nincs') . '</strong>.</p>';
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><th>Név</th><th>Időszak</th><th>Felső sáv</th><th>Kupon</th><th>Állapot</th><th></th></tr></thead><tbody>';
    foreach ($all as $i => $c) {
        $st = ($c['enabled'] ?? 'yes') !== 'yes' ? 'kikapcsolva' : (strtotime($c['end']) < time() ? 'lezárult' : (strtotime($c['start']) > time() ? 'ütemezve' : 'fut'));
        echo '<tr><td><strong>' . esc_html($c['name']) . '</strong></td><td>' . esc_html($c['start']) . ' → ' . esc_html($c['end']) . '</td><td>' . esc_html($c['bar']) . '</td><td>' . esc_html($c['coupon'] ?: '–') . ($c['coupon'] ? ' (' . (($c['auto'] ?? 'no') === 'yes' ? 'automatikus' : 'linkkel') . ')' : '') . '</td><td>' . $st . '</td>'
            . '<td><a class="button" href="' . esc_url(add_query_arg('edit', $i, remove_query_arg('preset'))) . '">Szerkesztés</a> <form method="post" style="display:inline">' . $nonce . '<input type="hidden" name="camp_do" value="delete"><input type="hidden" name="i" value="' . $i . '"><button class="button-link" onclick="return confirm(\'Törlöd?\')">Törlés</button></form></td></tr>';
    }
    if (!$all) {
        echo '<tr><td colspan="6">Még nincs kampány – kezdd egy sablonnal lent.</td></tr>';
    }
    echo '</tbody></table><h2>Sablonok</h2><p>';
    foreach ($presets as $key => $p) {
        echo '<a class="button" href="' . esc_url(add_query_arg('preset', $key, remove_query_arg('edit'))) . '">' . esc_html($p['name']) . ' <span class="description">(' . esc_html(substr($p['start'], 0, 10)) . ')</span></a> ';
    }
    echo '</p>';
    $c = $edit ?: array_fill_keys($fields, '') + ['auto' => 'no', 'enabled' => 'yes'];
    $in = fn($f, $label, $type = 'text', $cls = 'regular-text') => '<tr><th>' . $label . '</th><td><input type="' . $type . '" name="' . $f . '" value="' . esc_attr($type === 'datetime-local' ? str_replace(' ', 'T', (string) $c[$f]) : (string) $c[$f]) . '" class="' . $cls . '"></td></tr>';
    echo '<h2>' . (isset($_GET['edit']) ? 'Kampány szerkesztése' : 'Új kampány') . '</h2><form method="post">' . $nonce // phpcs:ignore
        . '<input type="hidden" name="camp_do" value="save"><input type="hidden" name="i" value="' . (isset($_GET['edit']) ? (int) $_GET['edit'] : -1) . '"><table class="form-table">' // phpcs:ignore
        . $in('name', 'Név') . $in('start', 'Kezdés', 'datetime-local', '') . $in('end', 'Vége', 'datetime-local', '')
        . $in('bar', 'Felső sáv szövege', 'text', 'large-text') . $in('eyebrow', 'Banner felirat') . $in('title', 'Banner cím', 'text', 'large-text') . $in('text', 'Banner szöveg', 'text', 'large-text')
        . $in('button', 'Gomb felirata') . $in('url', 'Cél (link)', 'text', 'large-text')
        . '<tr><th>Kupon</th><td><input type="text" name="coupon" value="' . esc_attr((string) $c['coupon']) . '" style="width:180px"> <label><input type="checkbox" name="auto" value="1"' . checked($c['auto'] ?? 'no', 'yes', false) . '> minden kosárra automatikusan a kampány alatt</label><p class="description">Üresen nincs kupon. Ha nem automatikus, a sáv és a banner linkje viszi be (?kupon=KOD). A kupont a Marketing → Kuponok alatt hozd létre (érvényességgel).</p></td></tr>'
        . '<tr><th>Állapot</th><td><label><input type="checkbox" name="enabled" value="1"' . checked($c['enabled'] ?? 'yes', 'yes', false) . '> bekapcsolva</label></td></tr></table>';
    submit_button('Kampány mentése');
    echo '</form><p class="description">Tipp: a ?kupon=KOD link bármely oldalhoz hozzáfűzhető (hírlevél, Facebook-hirdetés) – a kupon magától a kosárba kerül.</p></div>';
}
