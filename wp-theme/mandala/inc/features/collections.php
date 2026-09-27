<?php
/**
 * Automatikus gyűjtőoldalak a hosszú keresésekre („szantál füstölő”, „réz szobor”, „ajándék 5000 Ft
 * alatt”) – kézi munka nélkül.
 *
 *  - Naponta a termékadatokból (alkategória × illat / anyag / szín / forma / csakra / eredet, és
 *    ajándék ársávok) összegyűjti azokat a válogatásokat, amelyekben legalább 6 megvásárolható termék
 *    van – üres vagy silány oldal nem készül. Ha egy válogatás kiürül, az oldala a kategóriára irányít.
 *  - Címet, meta leírást és 2–3 mondatos bevezetőt a Claude ír (mandala_seo_claude), kötegekben;
 *    kulcs nélkül egyszerű cím, bevezető nélkül.
 *  - Oldal: /gyujtemeny/{slug}/ (a „Gyűjtemények” oldal alatt), termékrács, kapcsolódó válogatások,
 *    link a szűrhető kategóriára; CollectionPage + ItemList strukturált adat, saját oldaltérkép.
 *  - A kategóriaoldalak alján „Népszerű válogatások” linkek – így a Google is megtalálja őket.
 */

defined('ABSPATH') || exit;

const MANDALA_COLLECTION_MIN = 6;
const MANDALA_COLLECTION_ATTRS = [
    'illat' => ['label' => 'illat', 'pattern' => '%2$s illatú %1$s'],
    'anyag' => ['label' => 'anyag', 'pattern' => '%2$s %1$s'],
    'szin' => ['label' => 'szín', 'pattern' => '%2$s %1$s'],
    'forma' => ['label' => 'forma', 'pattern' => '%2$s %1$s'],
    'csakra' => ['label' => 'csakra', 'pattern' => '%1$s – %2$s csakra'],
    'eredet' => ['label' => 'eredet', 'pattern' => '%1$s – %2$s'],
];

function mandala_collections(): array
{
    return (array) get_option('mandala_collections', []);
}
function mandala_collection_url(string $slug): string
{
    $page = (int) get_option('mandala_page_gyujtemeny');
    return $page ? trailingslashit(get_permalink($page)) . $slug . '/' : home_url('/gyujtemeny/' . $slug . '/');
}

/* ---------- Válogatások kiszámolása ---------- */

/** Egy index-sor megfelel-e a válogatásnak. */
function mandala_collection_match(array $row, array $c): bool
{
    if (!empty($row['voucher'])) {
        return false;
    }
    if ($c['type'] === 'price') {
        return ($row['cat'] === 'ajandektargyak' || in_array('ajandek', (array) ($row['intents'] ?? []), true)) && (float) $row['price'] <= (float) $c['value'];
    }
    if (($row['sub'] ?: $row['cat']) !== $c['sub'] && $row['cat'] !== $c['sub']) {
        return false;
    }
    if ($c['attr'] === 'eredet') {
        return ($row['origin'] ?? '') === $c['value'];
    }
    $vals = (array) (((array) ($row['attrs'] ?? []))[$c['attr']] ?? []);
    return in_array($c['value'], array_map('sanitize_title', $vals), true);
}

/** Termékek egy válogatáshoz: raktáron lévők elöl. */
function mandala_collection_products(array $c, int $limit = 48): array
{
    $rows = array_values(array_filter(mandala_product_index(), fn($r) => mandala_collection_match($r, $c)));
    usort($rows, fn($a, $b) => (int) in_array($b['stock'] ?? '', ['in', 'low'], true) <=> (int) in_array($a['stock'] ?? '', ['in', 'low'], true));
    return array_slice($rows, 0, $limit);
}

/** A napi újraszámolás: új válogatások felvétele, a kiürültek inaktiválása. */
function mandala_collections_build(): array
{
    $found = [];
    $labels = [];
    foreach (mandala_product_index() as $row) {
        if (!empty($row['voucher']) || !in_array($row['stock'] ?? '', ['in', 'low', 'incoming'], true)) {
            continue;
        }
        $sub = $row['sub'] ?: $row['cat'];
        if (!$sub) {
            continue;
        }
        $attrs = (array) ($row['attrs'] ?? []);
        foreach (array_keys(MANDALA_COLLECTION_ATTRS) as $attr) {
            $vals = $attr === 'eredet' ? array_filter([$row['origin'] ?? '' => $row['originLabel'] ?? '']) : array_combine(array_map('sanitize_title', (array) ($attrs[$attr] ?? [])), (array) ($attrs[$attr] ?? []));
            foreach ((array) $vals as $vslug => $vname) {
                if ($vslug === '' || $vslug === 0) {
                    continue;
                }
                $key = $sub . '|' . $attr . '|' . $vslug;
                $found[$key] = ($found[$key] ?? 0) + 1;
                $labels[$key] = $attr === 'csakra' ? mandala_term_name((string) $vslug, 'pa_csakra') : (string) $vname;
            }
        }
        foreach ([5000, 10000, 20000] as $band) {
            if (($row['cat'] === 'ajandektargyak' || in_array('ajandek', (array) ($row['intents'] ?? []), true)) && (float) $row['price'] <= $band) {
                $found['ajandek|ar|' . $band] = ($found['ajandek|ar|' . $band] ?? 0) + 1;
            }
        }
    }
    $all = mandala_collections();
    $active = [];
    foreach ($found as $key => $count) {
        if ($count < (int) apply_filters('mandala_collection_min', MANDALA_COLLECTION_MIN)) {
            continue;
        }
        [$sub, $attr, $value] = explode('|', $key);
        if ($attr === 'ar') {
            $slug = 'ajandek-' . $value . '-ft-alatt';
            $c = ['type' => 'price', 'sub' => 'ajandektargyak', 'attr' => 'ar', 'value' => (string) $value, 'label' => sprintf('Ajándék %s alatt', mandala_fmt((float) $value))];
        } else {
            $slug = $sub . '-' . $value;
            $sub_label = mandala_term_name($sub);
            $c = ['type' => 'attr', 'sub' => $sub, 'attr' => $attr, 'value' => (string) $value,
                'label' => mb_strtoupper(mb_substr($l = sprintf(MANDALA_COLLECTION_ATTRS[$attr]['pattern'], $attr === 'csakra' || $attr === 'eredet' ? $sub_label : mb_strtolower($sub_label), $labels[$key] ?? $value), 0, 1)) . mb_substr($l, 1)];
        }
        $slug = sanitize_title($slug);
        $active[$slug] = true;
        $all[$slug] = array_merge($all[$slug] ?? [], $c, ['count' => $count, 'active' => true]);
    }
    foreach ($all as $slug => $c) {
        if (!isset($active[$slug])) {
            $all[$slug]['active'] = false;
        }
    }
    update_option('mandala_collections', $all, false);
    if (function_exists('mandala_seo_ready') && mandala_seo_ready() && array_filter($all, fn($c) => $c['active'] && empty($c['intro']))) {
        if (!as_next_scheduled_action('mandala_collections_ai', [], MANDALA_AS_GROUP)) {
            as_schedule_single_action(time() + 30, 'mandala_collections_ai', [], MANDALA_AS_GROUP);
        }
    }
    return ['active' => count($active), 'total' => count($all)];
}

mandala_recurring('mandala_collections_build', DAY_IN_SECONDS, fn() => time() + 20 * MINUTE_IN_SECONDS);
add_action('mandala_collections_build', 'mandala_collections_build');

/** AI szövegek, 10 válogatás / kérés, láncolva. */
add_action('mandala_collections_ai', function () {
    $all = mandala_collections();
    $todo = array_slice(array_filter($all, fn($c) => !empty($c['active']) && empty($c['intro'])), 0, 10, true);
    if (!$todo || !function_exists('mandala_seo_ready') || !mandala_seo_ready()) {
        return;
    }
    $items = [];
    foreach ($todo as $slug => $c) {
        $rows = mandala_collection_products($c, 10);
        $prices = array_map(fn($r) => (float) $r['price'], $rows);
        $items[] = ['id' => $slug, 'working_title' => $c['label'], 'category' => mandala_term_name($c['sub']), 'filter' => $c['type'] === 'price' ? 'ár legfeljebb ' . $c['value'] . ' Ft' : MANDALA_COLLECTION_ATTRS[$c['attr']]['label'] . ': ' . $c['value'],
            'product_count' => $c['count'], 'examples' => array_column($rows, 'name'), 'price_range_huf' => $prices ? [min($prices), max($prices)] : []];
    }
    $schema = ['type' => 'object', 'properties' => [
        'id' => ['type' => 'string'],
        'title' => ['type' => 'string', 'description' => 'Az oldal címe (H1): természetes magyar kifejezés, ahogy valaki keresne rá, legfeljebb 60 karakter.'],
        'seo_title' => ['type' => 'string', 'description' => 'Keresőbarát cím, legfeljebb 60 karakter, a bolt neve nélkül.'],
        'meta_description' => ['type' => 'string', 'description' => '120–155 karakter.'],
        'intro' => ['type' => 'string', 'description' => '2–3 mondatos bevezető a válogatáshoz, legfeljebb 350 karakter, a példatermékek alapján.'],
    ], 'required' => ['id', 'title', 'seo_title', 'meta_description', 'intro']];
    $results = mandala_seo_claude('Írj címet, meta leírást és rövid bevezetőt ezekhez a termékválogatás-oldalakhoz (gyűjtőoldalak a webáruházban).', $items, $schema);
    if (is_wp_error($results)) {
        as_schedule_single_action(time() + HOUR_IN_SECONDS, 'mandala_collections_ai', [], MANDALA_AS_GROUP);
        return;
    }
    foreach ($results as $slug => $r) {
        if (isset($all[$slug])) {
            $all[$slug]['title'] = mb_substr(sanitize_text_field($r['title'] ?? ''), 0, 80);
            $all[$slug]['seo_title'] = mb_substr(sanitize_text_field($r['seo_title'] ?? ''), 0, 70);
            $all[$slug]['desc'] = mb_substr(sanitize_text_field($r['meta_description'] ?? ''), 0, 170);
            $all[$slug]['intro'] = mb_substr(sanitize_textarea_field($r['intro'] ?? ''), 0, 400);
        }
    }
    update_option('mandala_collections', $all, false);
    if (array_filter($all, fn($c) => !empty($c['active']) && empty($c['intro']))) {
        as_schedule_single_action(time() + 15, 'mandala_collections_ai', [], MANDALA_AS_GROUP);
    }
});

/* ---------- Útvonal: /gyujtemeny/{slug}/ ---------- */

add_filter('query_vars', fn($vars) => array_merge($vars, ['mandala_collection', 'mandala_sitemap']));
add_action('init', function () {
    $page = (int) get_option('mandala_page_gyujtemeny');
    if (!$page) {
        return;
    }
    $base = trim((string) wp_make_link_relative(get_permalink($page)), '/');
    add_rewrite_rule('^' . preg_quote($base, '#') . '/([^/]+)/?$', 'index.php?page_id=' . $page . '&mandala_collection=$matches[1]', 'top');
    if (get_option('mandala_collections_rw') !== $page . '|' . $base) {
        flush_rewrite_rules(false);
        update_option('mandala_collections_rw', $page . '|' . $base, false);
    }
}, 20);

/** Az aktuális válogatás (ha a Gyűjtemények oldal alatt vagyunk). */
function mandala_current_collection(): ?array
{
    static $cache = false;
    if ($cache !== false) {
        return $cache;
    }
    $slug = sanitize_title((string) get_query_var('mandala_collection'));
    $c = $slug ? (mandala_collections()[$slug] ?? null) : null;
    $cache = $c ? $c + ['slug' => $slug] : null;
    return $cache;
}

/** Ismeretlen vagy kiürült válogatás: a kategóriára / a gyűjtemények oldalra irányít. */
add_action('template_redirect', function () {
    $slug = get_query_var('mandala_collection');
    if (!$slug) {
        return;
    }
    $c = mandala_current_collection();
    if (!$c || empty($c['active'])) {
        $to = $c && $c['type'] !== 'price' ? get_term_link((string) $c['sub'], 'product_cat') : get_permalink((int) get_option('mandala_page_gyujtemeny'));
        wp_safe_redirect(is_wp_error($to) ? home_url('/') : $to, 301);
        exit;
    }
});

add_filter('the_title', function ($title, $id = 0) {
    $c = mandala_current_collection();
    return $c && (int) $id === (int) get_option('mandala_page_gyujtemeny') && !is_admin() ? ($c['title'] ?? '' ?: $c['label']) : $title;
}, 10, 2);
add_filter('mandala_page_lead', function ($text, $post) {
    $c = mandala_current_collection();
    return $c && $post && (int) $post->ID === (int) get_option('mandala_page_gyujtemeny') ? (string) ($c['intro'] ?? '') : $text;
}, 10, 2);
add_filter('pre_get_document_title', function ($title) {
    $c = mandala_current_collection();
    return $c && !defined('WPSEO_VERSION') ? ($c['seo_title'] ?? '' ?: $c['label']) . ' – ' . get_bloginfo('name') : $title;
}, 20);
add_filter('wpseo_title', function ($title) {
    $c = mandala_current_collection();
    return $c ? ($c['seo_title'] ?? '' ?: $c['label']) . ' – ' . get_bloginfo('name') : $title;
});
foreach (['wpseo_metadesc', 'wpseo_opengraph_desc'] as $mandala_hook) {
    add_filter($mandala_hook, fn($d) => ($c = mandala_current_collection()) ? (string) ($c['desc'] ?? '' ?: $d) : $d);
}
foreach (['wpseo_canonical', 'wpseo_opengraph_url'] as $mandala_hook) {
    add_filter($mandala_hook, fn($u) => ($c = mandala_current_collection()) ? mandala_collection_url($c['slug']) : $u);
}
add_filter('get_canonical_url', fn($u) => ($c = mandala_current_collection()) ? mandala_collection_url($c['slug']) : $u);
add_action('wp_head', function () {
    $c = mandala_current_collection();
    if ($c && !defined('WPSEO_VERSION') && !empty($c['desc'])) {
        echo '<meta name="description" content="' . esc_attr($c['desc']) . '">' . "\n";
    }
}, 2);

/* ---------- Blokk: mandala/collection (a Gyűjtemények oldalon) ---------- */

add_action('init', function () {
    mandala_add_block('mandala/collection', [
        'title' => 'Gyűjtemény (automatikus válogatás)',
        'template' => function () {
            $c = mandala_current_collection();
            $all = array_filter(mandala_collections(), fn($x) => !empty($x['active']));
            if (!$c) {
                // Index: minden válogatás, kategóriánként.
                $groups = [];
                foreach ($all as $slug => $x) {
                    $groups[$x['type'] === 'price' ? 'Ajándék ár szerint' : mandala_term_name($x['sub'])][$slug] = $x;
                }
                ksort($groups);
                $out = '<div class="collection-index">';
                foreach ($groups as $label => $list) {
                    $out .= '<section><h2 style="font-size:var(--fs-h4)">' . esc_html($label) . '</h2><p class="collection-chips">';
                    foreach ($list as $slug => $x) {
                        $out .= '<a class="chip" href="' . esc_url(mandala_collection_url($slug)) . '">' . esc_html($x['title'] ?? '' ?: $x['label']) . ' <span class="text-muted">' . (int) $x['count'] . '</span></a>';
                    }
                    $out .= '</p></section>';
                }
                return $out . ($groups ? '' : '<p class="text-muted">' . esc_html__('A válogatások hamarosan elkészülnek.', 'mandala') . '</p>') . '</div>';
            }
            $rows = mandala_collection_products($c);
            $cards = mandala_cards(array_column($rows, 'id'));
            $related = array_filter($all, fn($x, $slug) => $slug !== $c['slug'] && $x['sub'] === $c['sub'], ARRAY_FILTER_USE_BOTH);
            uasort($related, fn($a, $b) => $b['count'] <=> $a['count']);
            $shop = $c['type'] === 'price' ? mandala_shop_url() : get_term_link((string) $c['sub'], 'product_cat');
            $out = '<ul class="products columns-4">' . $cards . '</ul>'
                . '<p class="load-more"><a class="iu-button iu-button-outline" href="' . esc_url(is_wp_error($shop) ? mandala_shop_url() : $shop) . '">' . esc_html__('Szűrés a teljes kínálatban', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</a></p>';
            if ($related) {
                $out .= '<h2 style="font-size:var(--fs-h4);margin-top:var(--space-7)">' . esc_html__('Kapcsolódó válogatások', 'mandala') . '</h2><p class="collection-chips">';
                foreach (array_slice($related, 0, 12, true) as $slug => $x) {
                    $out .= '<a class="chip" href="' . esc_url(mandala_collection_url($slug)) . '">' . esc_html($x['title'] ?? '' ?: $x['label']) . '</a>';
                }
                $out .= '</p>';
            }
            $ld = ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $c['title'] ?? '' ?: $c['label'], 'url' => mandala_collection_url($c['slug']),
                'mainEntity' => ['@type' => 'ItemList', 'itemListElement' => array_map(fn($r, $i) => ['@type' => 'ListItem', 'position' => $i + 1, 'url' => $r['url'], 'name' => $r['name']], array_slice($rows, 0, 20), array_keys(array_slice($rows, 0, 20)))]];
            return $out . '<script type="application/ld+json">' . wp_json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        },
    ]);
});

/* ---------- Belső linkek a kategóriaoldalakon ---------- */

add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') !== 'mandala/product-results' || !is_product_category()) {
        return $html;
    }
    $term = get_queried_object();
    $list = array_filter(mandala_collections(), fn($x) => !empty($x['active']) && $term && in_array($x['sub'], [$term->slug], true));
    if (!$list) {
        return $html;
    }
    uasort($list, fn($a, $b) => $b['count'] <=> $a['count']);
    $out = '<nav class="collection-links" aria-label="' . esc_attr__('Népszerű válogatások', 'mandala') . '"><p class="eyebrow">' . esc_html__('Népszerű válogatások', 'mandala') . '</p><p class="collection-chips">';
    foreach (array_slice($list, 0, 16, true) as $slug => $x) {
        $out .= '<a class="chip" href="' . esc_url(mandala_collection_url($slug)) . '">' . esc_html($x['title'] ?? '' ?: $x['label']) . '</a>';
    }
    return $html . $out . '</p></nav>';
}, 10, 2);

/* ---------- Oldaltérkép ---------- */

add_action('template_redirect', function () {
    if (($_GET['mandala_sitemap'] ?? '') !== 'gyujtemenyek') { // phpcs:ignore
        return;
    }
    header('Content-Type: application/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach (mandala_collections() as $slug => $c) {
        if (!empty($c['active'])) {
            echo '<url><loc>' . esc_url(mandala_collection_url($slug)) . '</loc><changefreq>weekly</changefreq></url>';
        }
    }
    echo '</urlset>';
    exit;
}, 0);
add_filter('robots_txt', fn($out) => $out . "\nSitemap: " . add_query_arg('mandala_sitemap', 'gyujtemenyek', home_url('/')) . "\n", 20);
add_filter('wpseo_sitemap_index', fn($xml) => $xml . '<sitemap><loc>' . esc_url(add_query_arg('mandala_sitemap', 'gyujtemenyek', home_url('/'))) . '</loc><lastmod>' . gmdate('c') . '</lastmod></sitemap>');

/* ---------- Admin: a Mandala SEO oldal alján ---------- */

add_action('mandala_seo_admin_after', function () {
    if (!empty($_POST['mandala_collections_rebuild']) && check_admin_referer('mandala_collections')) {
        $r = mandala_collections_build();
        echo '<div class="notice notice-success inline"><p>Frissítve: ' . (int) $r['active'] . ' aktív válogatás.</p></div>';
    }
    $all = mandala_collections();
    $active = array_filter($all, fn($c) => !empty($c['active']));
    $page = (int) get_option('mandala_page_gyujtemeny');
    echo '<h2>Gyűjtőoldalak</h2><p>' . count($active) . ' aktív válogatás (legalább ' . MANDALA_COLLECTION_MIN . ' termékkel), ebből ' . count(array_filter($active, fn($c) => !empty($c['intro']))) . ' AI bevezetővel. '
        . ($page ? '<a href="' . esc_url(get_permalink($page)) . '" target="_blank" rel="noopener">Gyűjtemények oldal ↗</a> · ' : '<strong>Nincs Gyűjtemények oldal – futtasd a Mandala telepítőt.</strong> ')
        . '<a href="' . esc_url(add_query_arg('mandala_sitemap', 'gyujtemenyek', home_url('/'))) . '" target="_blank" rel="noopener">oldaltérkép ↗</a></p>'
        . '<form method="post">' . wp_nonce_field('mandala_collections', '_wpnonce', true, false) . '<button class="button" name="mandala_collections_rebuild" value="1">Válogatások frissítése most</button></form>';
    if ($active) {
        echo '<table class="widefat striped" style="max-width:1100px;margin-top:12px"><thead><tr><th>Oldal</th><th>Termék</th><th>Bevezető</th></tr></thead><tbody>';
        foreach (array_slice($active, 0, 40, true) as $slug => $c) {
            echo '<tr><td><a href="' . esc_url(mandala_collection_url($slug)) . '" target="_blank" rel="noopener">' . esc_html($c['title'] ?? '' ?: $c['label']) . '</a></td><td>' . (int) $c['count'] . '</td><td>' . esc_html(mb_substr((string) ($c['intro'] ?? '–'), 0, 120)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
});
