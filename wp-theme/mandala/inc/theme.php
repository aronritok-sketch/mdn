<?php
/**
 * Téma alapok: stílusok, szkriptek, menühelyek, fejléc-meta, globális rétegek
 * (kereső, minikosár, cookie sáv, értesítések).
 */

defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    load_child_theme_textdomain('mandala', MANDALA_DIR . '/languages');
    register_nav_menus([
        'mandala-primary' => __('Fő menü (fejléc)', 'mandala'),
        'mandala-footer-shop' => __('Lábléc: Kínálat', 'mandala'),
        'mandala-footer-help' => __('Lábléc: Vásárlás', 'mandala'),
        'mandala-legal' => __('Jogi linkek', 'mandala'),
    ]);
    add_theme_support('woocommerce', [
        'thumbnail_image_width' => 600,
        'single_image_width' => 1200,
        'product_grid' => ['default_columns' => 3, 'default_rows' => 4],
    ]);
    add_theme_support('title-tag');
    add_image_size('mandala-card', 600, 676, true);
});

/** Stílusok: az iu_theme a saját és a child style.css-t tölti; ide a tokenek és a bolt jönnek. */
add_action('wp_enqueue_scripts', function () {
    $v = MANDALA_VERSION;
    wp_enqueue_style('mandala-fonts', 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;1,400;1,500&family=Inter:wght@400;500;600&display=swap', [], null);
    wp_enqueue_style('mandala-vars', MANDALA_URL . '/vars.css', [], $v);
    // A child style.css-t az iu_theme is betölti (saját handle-lel); ha mégsem, itt pótoljuk.
    $child_loaded = false;
    foreach (wp_styles()->registered as $style) {
        if (is_string($style->src) && strtok($style->src, '?') === get_stylesheet_uri()) {
            $child_loaded = true;
            // A tokenek (vars.css) előbb töltődjenek, mint a stíluslap.
            $style->deps[] = 'mandala-vars';
        }
    }
    if (!$child_loaded) {
        wp_enqueue_style('mandala-style', get_stylesheet_uri(), ['mandala-vars'], $v);
    }
    if (function_exists('is_woocommerce')) {
        wp_enqueue_style('mandala-shop', MANDALA_URL . '/assets/css/shop.css', ['mandala-vars'], $v);
    }

    // Közös adat a szkripteknek (a prototípus data.js / store.js megfelelője).
    wp_register_script('mandala-data', false, [], $v, false);
    wp_enqueue_script('mandala-data');
    wp_add_inline_script('mandala-data', 'window.MANDALA = ' . wp_json_encode(mandala_js_data()) . ';', 'before');

    wp_enqueue_script_module('mandala-site', MANDALA_URL . '/assets/js/site.js', [], $v);
    if (function_exists('is_product') && is_product()) {
        wp_enqueue_script_module('mandala-product', MANDALA_URL . '/assets/js/product.js', ['mandala-site'], $v);
    }
    if (function_exists('is_checkout') && is_checkout()) {
        wp_enqueue_script_module('mandala-checkout', MANDALA_URL . '/assets/js/checkout.js', [], $v);
    }
    // A WooCommerce AJAX kosárba tétele és a fragmentek a teljes oldalon kellenek (fejléc kosár).
    if (function_exists('WC')) {
        wp_enqueue_script('wc-add-to-cart');
        wp_enqueue_script('wc-cart-fragments');
    }
}, 20);

/** A szkriptek által használt, nem érzékeny adatok. */
function mandala_js_data(): array
{
    $catalog = mandala_data('catalog');
    $data = [
        'home' => home_url('/'),
        'shop' => function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/'),
        'cart' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
        'checkout' => function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '',
        'wishlistPage' => get_permalink(get_option('mandala_page_kedvencek')) ?: '',
        'search' => home_url('/'),
        // Az iu_theme egyedi REST prefixet használ: az URL-t mindig rest_url() adja.
        'rest' => esc_url_raw(rest_url('mandala/v1/')),
        'wcAjax' => class_exists('WC_AJAX') ? WC_AJAX::get_endpoint('%%endpoint%%') : '',
        'nonce' => wp_create_nonce('wp_rest'),
        'art' => MANDALA_URL . '/assets/art/',
        'freeShippingFrom' => (int) mandala_config('freeShippingFrom', 25000),
        'contact' => mandala_config('contact', []),
        'intents' => $catalog['intents'] ?? [],
        'origins' => $catalog['origins'] ?? [],
        'categories' => mandala_category_tree(),
        'icons' => mandala_data('icons'),
        'siteName' => get_bloginfo('name'),
        'contactPage' => get_permalink((int) get_option('mandala_page_kapcsolat')) ?: '',
        'privacy' => get_privacy_policy_url(),
        'colors' => mandala_swatch_colors(),
    ];
    return apply_filters('mandala_js_data', $data);
}

/**
 * Kategóriafa a szűrőnek és a menünek: a WooCommerce product_cat fő és alkategóriái,
 * a prototípus CATEGORIES szerkezetében ({slug, label, text, subs: [[slug, label]]}).
 */
function mandala_category_tree(): array
{
    if (!taxonomy_exists('product_cat')) {
        return [];
    }
    $cached = get_transient('mandala_cat_tree');
    if (is_array($cached)) {
        return $cached;
    }
    $uncat = (int) get_option('default_product_cat');
    $tree = [];
    $mains = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'meta_value_num', 'meta_key' => 'order']);
    foreach (is_wp_error($mains) ? [] : $mains as $main) {
        if ($main->term_id === $uncat) {
            continue;
        }
        $subs = get_terms(['taxonomy' => 'product_cat', 'parent' => $main->term_id, 'hide_empty' => false, 'orderby' => 'meta_value_num', 'meta_key' => 'order']);
        $tree[] = [
            'slug' => $main->slug,
            'label' => $main->name,
            'text' => wp_strip_all_tags($main->description),
            'url' => get_term_link($main),
            'count' => (int) $main->count,
            'subs' => array_map(fn($s) => [$s->slug, $s->name, get_term_link($s)], is_wp_error($subs) ? [] : $subs),
        ];
    }
    set_transient('mandala_cat_tree', $tree, DAY_IN_SECONDS);
    return $tree;
}
foreach (['created_product_cat', 'edited_product_cat', 'delete_product_cat'] as $hook) {
    add_action($hook, fn() => delete_transient('mandala_cat_tree'));
}

/** A pa_szin kifejezések színkódjai a szűrő színmintáihoz (név → CSS szín). */
function mandala_swatch_colors(): array
{
    if (!taxonomy_exists('pa_szin')) {
        return [];
    }
    $colors = [];
    foreach (get_terms(['taxonomy' => 'pa_szin', 'hide_empty' => false]) ?: [] as $term) {
        if ($c = get_term_meta($term->term_id, 'mandala_color', true)) {
            $colors[$term->name] = $c;
        }
    }
    return $colors;
}

/** Fejléc meta: téma szín, favicon, előtöltés; a „has-js” osztály az első festés előtt. */
add_action('wp_head', function () {
    echo "<script>document.documentElement.classList.add('has-js')</script>\n";
    echo '<meta name="theme-color" content="#16120F">' . "\n";
    if (!has_site_icon()) {
        echo '<link rel="icon" href="' . esc_url(MANDALA_URL . '/assets/favicon.svg') . '" type="image/svg+xml">' . "\n";
    }
    echo '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 2);

/** Body osztályok: pénztár fejléc-változat, kosár állapot. */
add_filter('body_class', function ($classes) {
    if (function_exists('is_checkout') && is_checkout() && !is_order_received_page()) {
        $classes[] = 'is-checkout-layout';
    }
    return $classes;
});

/** Ugrás a tartalomra link az oldal elején. */
add_action('wp_body_open', function () {
    echo '<a class="skip-link" href="#main">' . esc_html__('Ugrás a tartalomra', 'mandala') . '</a>';
}, 1);

/** A <main> kapjon azonosítót a skip-linkhez (az iu_theme index.php-ja rendereli). */
add_action('wp_footer', function () {
    echo "<script>(function(){var m=document.querySelector('main');if(m&&!m.id){m.id='main';m.tabIndex=-1;}})();</script>\n";
}, 1);

/** Globális rétegek: kereső, minikosár, értesítések helye. A cookie sávot a site.js rajzolja ki. */
add_action('wp_footer', function () {
    ?>
<div class="search-layer" id="search" hidden>
  <div class="search-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Keresés', 'mandala'); ?>">
    <div class="iu-row"><div class="iu-column iu-column-1-1">
      <form class="search-form" action="<?php echo esc_url(home_url('/')); ?>" role="search">
        <?php echo mandala_icon('search', 'ico ico-l'); // phpcs:ignore ?>
        <label class="sr-only" for="search-input"><?php esc_html_e('Keresés termékek és cikkek között', 'mandala'); ?></label>
        <input id="search-input" name="s" type="search" placeholder="<?php esc_attr_e('Hangtál, füstölő, mala, cikkszám…', 'mandala'); ?>" autocomplete="off" role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list">
        <button type="button" class="icon-button" data-close aria-label="<?php esc_attr_e('Keresés bezárása', 'mandala'); ?>"><?php echo mandala_icon('close'); // phpcs:ignore ?></button>
      </form>
      <div id="search-results" class="search-suggest" aria-live="polite"></div>
    </div></div>
  </div>
</div>
<?php if (function_exists('WC') && !(function_exists('is_checkout') && is_checkout())) : ?>
<div class="drawer" id="minicart" hidden>
  <aside class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="minicart-title">
    <div class="drawer-head"><h2 id="minicart-title"><?php esc_html_e('Kosár', 'mandala'); ?> <span id="minicart-count" class="text-muted" style="font-size:1.25rem"></span></h2>
      <button type="button" class="icon-button" data-close aria-label="<?php esc_attr_e('Kosár bezárása', 'mandala'); ?>"><?php echo mandala_icon('close'); // phpcs:ignore ?></button></div>
    <div class="widget_shopping_cart_content"><?php mandala_minicart_content(); ?></div>
  </aside>
</div>
<?php endif; ?>
<div class="toasts" role="status" aria-live="polite"></div>
    <?php
}, 5);
