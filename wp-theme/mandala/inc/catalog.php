<?php
/**
 * Termékadatok: a szűrő termékindexe (gyorsítótárazva) és a „Mandala adatok” mezők
 * a termék szerkesztőben (frekvencia, súly, eredethely, használat, illusztráció).
 */

defined('ABSPATH') || exit;

const MANDALA_INDEX_KEY = 'mandala_product_index_v1';

/** A teljes kínálat kompakt indexe a böngészőbeli szűrőhöz és a kereséshez. */
function mandala_product_index(): array
{
    $key = mandala_lang_key(MANDALA_INDEX_KEY);
    $cached = get_transient($key);
    if (is_array($cached)) {
        return $cached;
    }
    $index = [];
    if (function_exists('wc_get_products')) {
        $ids = wc_get_products(['status' => 'publish', 'visibility' => 'catalog', 'limit' => -1, 'return' => 'ids', 'orderby' => ['menu_order' => 'ASC', 'title' => 'ASC']]);
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $row = mandala_product_index_row($product);
            $row['url'] = get_permalink($id);
            $row['img'] = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') : '';
            $row['art'] = $row['img'] ? '' : mandala_art_url($product);
            $row['catLabel'] = mandala_term_name($row['sub'] ?: $row['cat']);
            $row['originLabel'] = mandala_attr($product, 'pa_eredet')[0] ?? '';
            $row['buyable'] = $product->is_type('simple') && $product->is_purchasable() && $product->is_in_stock();
            $row['addUrl'] = $row['buyable'] ? $product->add_to_cart_url() : '';
            // Funkciómodulok (hangminta, előrendelés, értékelés…) további mezői.
            $row = apply_filters('mandala_product_index_row', $row, $product);
            $index[] = $row;
        }
    }
    set_transient($key, $index, 12 * HOUR_IN_SECONDS);
    return $index;
}

function mandala_flush_index(): void
{
    // Minden nyelv változata (WPML nélkül csak az alap).
    $langs = array_keys((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0])) ?: [''];
    foreach ($langs as $lang) {
        delete_transient(mandala_lang_key(MANDALA_INDEX_KEY, (string) $lang));
        delete_transient(mandala_lang_key('mandala_cat_tree', (string) $lang));
    }
    delete_transient(MANDALA_INDEX_KEY);
    delete_transient('mandala_cat_tree');
}
// Termékmentés, készletváltozás (a JUTA-szinkron is ezen fut át), árváltozás, kategória.
foreach (['woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product',
          'woocommerce_product_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock',
          'edited_product_cat', 'edited_term', 'woocommerce_scheduled_sales'] as $hook) {
    add_action($hook, 'mandala_flush_index');
}

/* ---------- Admin: „Mandala adatok” fül a termék adatai között ---------- */

/** A „Mandala adatok” mezői: kulcs => [címke, típus, súgó]. Típus: text, number, textarea, checkbox, date, audio. */
function mandala_product_meta_fields(): array
{
    return apply_filters('mandala_product_meta_fields', [
        '_mandala_hz' => ['Frekvencia (Hz)', 'number', 'Hangtál mért alapfrekvenciája – a szűrő csúszkája ebből dolgozik.'],
        '_mandala_suly' => ['Súly (g)', 'number', 'Hangtál súlya grammban (szűrő).'],
        '_mandala_place' => ['Eredethely', 'text', 'Pl. „Patan, Katmandu-völgy” – a termékoldal eredetkártyáján jelenik meg.'],
        '_mandala_ritual' => ['Használat és gondozás', 'textarea', 'A termékoldal „Használat és gondozás” fülének szövege.'],
        '_mandala_art' => ['Illusztráció (fotó helyett)', 'text', 'bowl, incense, mala, buddha, chime, scarf, copper … – csak ha nincs termékkép.'],
        '_mandala_tone' => ['Illusztráció tónusa', 'text', 'sand, saffron, sage, maroon, sky'],
    ]);
}

add_filter('woocommerce_product_data_tabs', function ($tabs) {
    $tabs['mandala'] = ['label' => 'Mandala adatok', 'target' => 'mandala_product_data', 'class' => [], 'priority' => 65];
    return $tabs;
});

add_action('woocommerce_product_data_panels', function () {
    echo '<div id="mandala_product_data" class="panel woocommerce_options_panel"><div class="options_group">';
    foreach (mandala_product_meta_fields() as $key => $def) {
        [$label, $type, $desc] = $def;
        $args = ['id' => $key, 'label' => $label, 'description' => $desc, 'desc_tip' => true];
        if ($type === 'select') {
            woocommerce_wp_select($args + ['options' => is_callable($def[3] ?? null) ? ($def[3])() : (array) ($def[3] ?? [])]);
        } elseif ($type === 'textarea') {
            woocommerce_wp_textarea_input($args);
        } elseif ($type === 'checkbox') {
            woocommerce_wp_checkbox($args);
        } elseif ($type === 'audio') {
            woocommerce_wp_text_input($args + ['type' => 'url', 'placeholder' => 'https://…/hang.mp3']);
            echo '<p class="form-field"><label></label><button type="button" class="button mandala-media" data-target="' . esc_attr($key) . '">Hangfájl a médiatárból</button></p>';
        } elseif ($type === 'date') {
            woocommerce_wp_text_input($args + ['type' => 'date']);
        } else {
            woocommerce_wp_text_input($args + ['type' => $type, 'custom_attributes' => $type === 'number' ? ['step' => 'any', 'min' => '0'] : []]);
        }
    }
    echo '</div></div>';
});

add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    foreach (mandala_product_meta_fields() as $key => $def) {
        $type = $def[1];
        if ($type === 'checkbox') {
            $product->update_meta_data($key, empty($_POST[$key]) ? 'no' : 'yes'); // phpcs:ignore
            continue;
        }
        if (!isset($_POST[$key])) { // phpcs:ignore WordPress.Security.NonceVerification -- a WooCommerce ellenőrzi
            continue;
        }
        $raw = wp_unslash($_POST[$key]); // phpcs:ignore
        $value = match ($type) {
            'number' => $raw === '' ? '' : (string) (float) str_replace(',', '.', $raw),
            'textarea' => sanitize_textarea_field($raw),
            'audio' => esc_url_raw($raw),
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : '',
            'select' => sanitize_key($raw),
            default => sanitize_text_field($raw),
        };
        $product->update_meta_data($key, $value);
    }
});

/** Médiatár gomb a hangfájl mezőhöz. */
add_action('admin_footer-post.php', 'mandala_admin_media_js');
add_action('admin_footer-post-new.php', 'mandala_admin_media_js');
function mandala_admin_media_js(): void
{
    if (get_post_type() !== 'product') {
        return;
    }
    wp_enqueue_media();
    ?>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.mandala-media');
  if (!b || !window.wp || !wp.media) return;
  e.preventDefault();
  var frame = wp.media({ title: 'Hangfájl', library: { type: 'audio' }, multiple: false });
  frame.on('select', function () { document.getElementById(b.dataset.target).value = frame.state().get('selection').first().get('url'); });
  frame.open();
});
</script>
    <?php
}

/** A pa_szin kifejezések színkódja (a szűrő színmintái) – kifejezés meta: mandala_color. */
add_action('pa_szin_edit_form_fields', function ($term) {
    $color = get_term_meta($term->term_id, 'mandala_color', true);
    echo '<tr class="form-field"><th scope="row"><label for="mandala_color">Színkód</label></th><td><input name="mandala_color" id="mandala_color" type="text" value="' . esc_attr($color) . '" placeholder="#C9A24A"><p class="description">A szűrő színmintája (hex vagy CSS gradient).</p></td></tr>';
});
add_action('edited_pa_szin', function ($term_id) {
    if (isset($_POST['mandala_color'])) { // phpcs:ignore
        update_term_meta($term_id, 'mandala_color', sanitize_text_field(wp_unslash($_POST['mandala_color']))); // phpcs:ignore
        mandala_flush_index();
    }
});
