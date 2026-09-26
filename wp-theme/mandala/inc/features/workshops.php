<?php
/**
 * Műhelyek: a partnerműhelyek története (Nepál, India), a termékek hozzájuk kötése,
 * műhely oldal a termékeivel, és rendelésenként nyomtatható kísérőkártya QR-kóddal.
 *
 * Sablonok: templates/single_mandala_workshop_content.html, archive_mandala_workshop_content.html
 */

defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('mandala_workshop', [
        'labels' => [
            'name' => 'Műhelyek', 'singular_name' => 'Műhely', 'add_new_item' => 'Új műhely', 'edit_item' => 'Műhely szerkesztése',
            'all_items' => 'Összes műhely', 'menu_name' => 'Műhelyek',
        ],
        'public' => true,
        'has_archive' => 'muhelyek',
        'rewrite' => ['slug' => 'muhely', 'with_front' => false],
        'menu_icon' => 'dashicons-admin-site-alt3',
        'menu_position' => 56,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'show_in_rest' => true,
    ]);
});

/** Műhely adatai: hely, mesterség, ország (eredet). */
const MANDALA_WORKSHOP_META = [
    '_mandala_place' => ['Hely', 'Pl. Patan, Katmandu-völgy'],
    '_mandala_craft' => ['Mesterség', 'Pl. fémöntés, hangtálkészítés'],
    '_mandala_country' => ['Ország (nepal / india)', 'nepal'],
    '_mandala_since' => ['Mióta dolgozunk együtt', 'Pl. 2014'],
];

add_action('add_meta_boxes', function () {
    add_meta_box('mandala_workshop', 'Műhely adatai', function ($post) {
        wp_nonce_field('mandala_workshop', 'mandala_workshop_nonce');
        foreach (MANDALA_WORKSHOP_META as $key => [$label, $placeholder]) {
            printf('<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="text" class="widefat" id="%1$s" name="%1$s" value="%3$s" placeholder="%4$s"></p>',
                esc_attr($key), esc_html($label), esc_attr(get_post_meta($post->ID, $key, true)), esc_attr($placeholder));
        }
        echo '<p class="description">A műhely története a szerkesztőben, a kivonat a kártyán és a kísérőkártyán jelenik meg.</p>';
    }, 'mandala_workshop', 'side');
});
add_action('save_post_mandala_workshop', function ($post_id) {
    if (!isset($_POST['mandala_workshop_nonce']) || !wp_verify_nonce(sanitize_key($_POST['mandala_workshop_nonce']), 'mandala_workshop') || !current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (array_keys(MANDALA_WORKSHOP_META) as $key) {
        update_post_meta($post_id, $key, sanitize_text_field(wp_unslash($_POST[$key] ?? '')));
    }
    mandala_flush_index();
});

/** Termék → műhely kapcsolat (Mandala adatok fül). */
add_filter('mandala_product_meta_fields', function ($fields) {
    $fields['_mandala_workshop'] = ['Műhely', 'select', 'A termék készítő műhelye – a termékoldal eredetkártyája a műhely történetére mutat.', function () {
        $options = ['' => '– nincs megadva –'];
        foreach (get_posts(['post_type' => 'mandala_workshop', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC']) as $w) {
            $options[(string) $w->ID] = $w->post_title;
        }
        return $options;
    }];
    return $fields;
});

function mandala_product_workshop(WC_Product $product): ?WP_Post
{
    $id = (int) $product->get_meta('_mandala_workshop', true, 'edit');
    $post = $id ? get_post($id) : null;
    return $post && $post->post_type === 'mandala_workshop' && $post->post_status === 'publish' ? $post : null;
}

add_filter('mandala_product_index_row', function ($row, WC_Product $product) {
    $w = mandala_product_workshop($product);
    $row['workshop'] = $w ? $w->post_title : '';
    return $row;
}, 10, 2);

/** Termékoldal: az eredetkártya a műhelyre mutat. */
add_filter('mandala_origin_card', function ($html, WC_Product $product) {
    $w = mandala_product_workshop($product);
    if (!$w) {
        return $html;
    }
    $place = get_post_meta($w->ID, '_mandala_place', true);
    return '<div class="origin-card">' . mandala_icon('pin') . '<p><strong>' . esc_html(sprintf(__('Műhely: %s', 'mandala'), $w->post_title)) . ($place ? ' · ' . esc_html($place) : '') . '</strong>'
        . esc_html(get_the_excerpt($w)) . ' <a href="' . esc_url(get_permalink($w)) . '">' . esc_html__('A műhely története', 'mandala') . ' →</a></p></div>';
}, 10, 2);

/* ---------- Blokkok ---------- */

add_action('init', function () {
    if (!function_exists('mandala_add_block')) {
        return;
    }
    mandala_add_block('mandala/workshops', [
        'title' => 'Műhelyek (rács)',
        'attributes' => ['limit' => mandala_attr_def('12'), 'country' => mandala_attr_def('')],
        'fields' => [['panel' => 'Beállítások', 'fields' => [
            'limit' => ['type' => 'text', 'label' => 'Darabszám'],
            'country' => ['type' => 'select', 'label' => 'Ország', 'options' => [['label' => 'Mind', 'value' => ''], ['label' => 'Nepál', 'value' => 'nepal'], ['label' => 'India', 'value' => 'india']]],
        ]]],
        'template' => function ($attributes) {
            $args = ['post_type' => 'mandala_workshop', 'numberposts' => max(1, (int) ($attributes['limit'] ?? 12)), 'orderby' => 'menu_order title', 'order' => 'ASC'];
            if (!empty($attributes['country'])) {
                $args['meta_key'] = '_mandala_country';
                $args['meta_value'] = sanitize_key($attributes['country']);
            }
            $posts = get_posts($args);
            if (!$posts) {
                return '';
            }
            $out = '<div class="workshop-grid">';
            foreach ($posts as $w) {
                $country = get_post_meta($w->ID, '_mandala_country', true);
                $count = count(get_posts(['post_type' => 'product', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_mandala_workshop', 'meta_value' => $w->ID]));
                $img = has_post_thumbnail($w) ? get_the_post_thumbnail($w, 'medium_large', ['loading' => 'lazy', 'alt' => '']) : mandala_art_img((string) get_post_meta($w->ID, '_mandala_art', true) ?: 'bowl', 'sand');
                $out .= '<a class="workshop-card reveal" href="' . esc_url(get_permalink($w)) . '"><div class="workshop-media">' . $img . '</div><div class="workshop-body">'
                    . '<p class="post-meta"><span class="origin origin-' . esc_attr($country) . '">' . esc_html(get_post_meta($w->ID, '_mandala_place', true)) . '</span></p>'
                    . '<h3>' . esc_html($w->post_title) . '</h3><p>' . esc_html(get_the_excerpt($w)) . '</p>'
                    . '<span class="go">' . esc_html(sprintf(_n('%d termék', '%d termék', $count, 'mandala'), $count)) . ' · ' . esc_html__('A műhely története', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</span></div></a>';
            }
            return $out . '</div>';
        },
    ]);

    mandala_add_block('mandala/workshop-facts', [
        'title' => 'Műhely adatai',
        'template' => function () {
            $w = get_post(get_queried_object_id());
            if (!$w || $w->post_type !== 'mandala_workshop') {
                return '';
            }
            $rows = [];
            foreach (['_mandala_place' => ['pin', 'Hely'], '_mandala_craft' => ['hand', 'Mesterség'], '_mandala_since' => ['calendar', 'Együtt dolgozunk']] as $key => [$icon, $label]) {
                if ($v = get_post_meta($w->ID, $key, true)) {
                    $rows[] = '<li>' . mandala_icon($icon) . '<span><strong>' . esc_html__($label, 'mandala') . '</strong>' . esc_html($key === '_mandala_since' ? sprintf(__('%s óta', 'mandala'), $v) : $v) . '</span></li>';
                }
            }
            return $rows ? '<ul class="workshop-facts">' . implode('', $rows) . '</ul>' : '';
        },
    ]);
});

/** mandala/products: „workshop” mód – az aktuális műhely termékei. */
add_filter('mandala_product_selection', function ($ids, $mode, $limit) {
    if ($mode !== 'workshop') {
        return $ids;
    }
    return wc_get_products(['status' => 'publish', 'limit' => $limit, 'return' => 'ids', 'meta_key' => '_mandala_workshop', 'meta_value' => get_queried_object_id()]);
}, 10, 3);

/* ---------- Kísérőkártya (nyomtatás a rendelés adminból) ---------- */

add_action('add_meta_boxes', function () {
    foreach (['shop_order', 'woocommerce_page_wc-orders'] as $screen) {
        add_meta_box('mandala_card', 'Kísérőkártya', function ($post_or_order) {
            $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
            if (!$order) {
                return;
            }
            $url = wp_nonce_url(admin_url('admin-post.php?action=mandala_packing_card&order=' . $order->get_id()), 'mandala_packing_card');
            echo '<p>A csomagba tehető kártya: köszönő sorok, a termékek műhelyeinek története QR-kóddal.</p><p><a class="button" target="_blank" href="' . esc_url($url) . '">Kísérőkártya nyomtatása</a></p>';
        }, $screen, 'side');
    }
});

add_action('admin_post_mandala_packing_card', function () {
    if (!current_user_can('edit_shop_orders') || !check_admin_referer('mandala_packing_card')) {
        wp_die('Nincs jogosultság.');
    }
    $order = wc_get_order(absint($_GET['order'] ?? 0));
    if (!$order) {
        wp_die('A rendelés nem található.');
    }
    $workshops = [];
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && ($w = mandala_product_workshop($product))) {
            $workshops[$w->ID] = $w;
        }
    }
    $qr = MANDALA_URL . '/assets/vendor/qrcode.js';
    ?><!doctype html>
<html lang="hu"><head><meta charset="utf-8"><title>Kísérőkártya – #<?php echo esc_html($order->get_order_number()); ?></title>
<style>
@page { size: A5 portrait; margin: 12mm; }
body { font: 11pt/1.5 Georgia, serif; color: #1C1916; margin: 0; }
.card { max-width: 148mm; margin: 0 auto; }
h1 { font-weight: 400; font-size: 22pt; margin: 0 0 4mm; }
.lead { color: #6E6357; margin: 0 0 8mm; }
.ws { display: grid; grid-template-columns: 1fr 26mm; gap: 6mm; padding: 5mm 0; border-top: 1px solid #DDD3C3; break-inside: avoid; }
.ws h2 { font-size: 13pt; margin: 0 0 1mm; font-weight: 600; }
.ws small { color: #6E6357; display: block; margin-bottom: 2mm; }
.ws p { margin: 0; font-size: 10pt; }
.qr svg, .qr img { width: 26mm; height: 26mm; }
.qr span { display: block; font: 7pt/1.2 system-ui; color: #6E6357; text-align: center; margin-top: 1mm; }
.foot { margin-top: 8mm; font: 9pt system-ui; color: #6E6357; }
.no-print { font: 12px system-ui; margin: 12px; }
@media print { .no-print { display: none; } }
</style></head><body>
<p class="no-print"><button onclick="print()">Nyomtatás</button></p>
<div class="card">
  <h1><?php echo esc_html(sprintf('Kedves %s!', $order->get_billing_first_name() ?: 'Vásárlónk')); ?></h1>
  <p class="lead">Köszönjük, hogy tőlünk választottál. A csomagodban lévő tárgyak kis műhelyekből érkeztek – ha kíváncsi vagy a történetükre, olvasd be a kódot.</p>
  <?php foreach ($workshops as $w) :
      $link = add_query_arg(['utm_source' => 'csomag', 'utm_medium' => 'kiserokartya'], get_permalink($w)); ?>
  <div class="ws">
    <div><h2><?php echo esc_html($w->post_title); ?></h2><small><?php echo esc_html(implode(' · ', array_filter([get_post_meta($w->ID, '_mandala_place', true), get_post_meta($w->ID, '_mandala_craft', true)]))); ?></small>
      <p><?php echo esc_html(get_the_excerpt($w)); ?></p></div>
    <div class="qr" data-qr="<?php echo esc_url($link); ?>"><span><?php echo esc_html(wp_parse_url(home_url(), PHP_URL_HOST)); ?></span></div>
  </div>
  <?php endforeach; ?>
  <?php if (!$workshops) : ?><p>Jó elcsendesedést kívánunk!</p><?php endif; ?>
  <p class="foot"><?php echo esc_html(get_bloginfo('name')); ?> · <?php echo esc_html(mandala_config('contact')['email'] ?? ''); ?> · #<?php echo esc_html($order->get_order_number()); ?></p>
</div>
<script src="<?php echo esc_url($qr); ?>"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
  var q = qrcode(0, 'M'); q.addData(el.dataset.qr); q.make();
  el.insertAdjacentHTML('afterbegin', q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }));
});
</script>
</body></html>
    <?php
    exit;
});

/* ---------- Bemutató műhelyek ---------- */

add_filter('mandala_demo_post_types', fn($types) => array_merge($types, ['mandala_workshop']));

add_action('mandala_demo_features', function () {
    // [cím, hely, mesterség, ország, rajz, egyező eredethely (a vessző előtti rész), alkategóriák (üres = mind), kivonat]
    $demo = [
        ['Patani fémöntő műhely', 'Patan, Katmandu-völgy', 'Fémöntés, hangtálkészítés', 'nepal', 'bowl', 'Patan', [], 'A Katmandu-völgy egyik legrégebbi kézműves városában a hangtálakat ma is viaszveszejtéses öntéssel és kézi kalapálással készítik.'],
        ['Tibeti füstölőkészítők', 'Katmandu', 'Kézzel sodort füstölő', 'nepal', 'incense', 'Katmandu', ['fustolok', 'mala-lancok', 'oltar-kiegeszitok'], 'Tibeti közösségi műhely, ahol a gyógynövényes, pálca nélküli füstölőket kézzel keverik és sodorják.'],
        ['Moradabadi rézművesek', 'Moradabad, Uttar Prades', 'Rézművesség', 'india', 'copper', 'Moradabad', [], 'A „réz városa” kis családi műhelyeiben készülnek szélcsengőink, kulacsaink és füstölőtartóink.'],
        ['Jaipuri blokknyomók', 'Jaipur, Rádzsasztán', 'Blokknyomás, ékszerkészítés', 'india', 'scarf', 'Jaipur', [], 'Faragott fadúcokkal, kézzel nyomott textilek és kézműves ékszerek Rádzsasztán fővárosából.'],
    ];
    foreach ($demo as $i => [$title, $place, $craft, $country, $art, $match, $subs, $excerpt]) {
        if (get_posts(['post_type' => 'mandala_workshop', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids'])) {
            continue;
        }
        $id = wp_insert_post([
            'post_type' => 'mandala_workshop', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt, 'menu_order' => $i,
            'post_content' => "<!-- wp:paragraph -->\n<p>{$excerpt}</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>[Bemutató szöveg – a műhely valódi története, a mester neve és fotói élesítés előtt kerülnek ide.]</p>\n<!-- /wp:paragraph -->",
        ]);
        if (is_wp_error($id)) {
            continue;
        }
        foreach (['_mandala_place' => $place, '_mandala_craft' => $craft, '_mandala_country' => $country, '_mandala_art' => $art, '_mandala_demo' => '1'] as $k => $v) {
            update_post_meta($id, $k, $v);
        }
        foreach (wc_get_products(['limit' => -1, 'meta_key' => '_mandala_demo', 'meta_value' => '1']) as $product) {
            [, $sub] = mandala_product_cats($product->get_id());
            if (!$product->get_meta('_mandala_workshop') && trim(strtok((string) $product->get_meta('_mandala_place'), ',')) === $match && (!$subs || in_array($sub, $subs, true))) {
                $product->update_meta_data('_mandala_workshop', (string) $id);
                $product->save();
            }
        }
    }
    flush_rewrite_rules(false);
});
