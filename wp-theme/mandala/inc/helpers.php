<?php
/**
 * Közös segédfüggvények: konfiguráció, ikonok, termékadat, termékkártya.
 */

defined('ABSPATH') || exit;

/** Generált adatfájl a setup/data mappából (a prototípus data.js-éből készül). */
function mandala_data(string $name): array
{
    static $cache = [];
    if (!isset($cache[$name])) {
        $file = MANDALA_DIR . '/setup/data/' . $name . '.json';
        $cache[$name] = is_readable($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
    }
    return $cache[$name];
}

/** Beállítás: először a WP opció (admin felülírhatja), különben a config.json. */
function mandala_config(string $key, $default = null)
{
    $config = mandala_data('config');
    $value = $config[$key] ?? $default;
    $override = get_option('mandala_' . $key, null);
    return $override !== null && $override !== '' ? $override : $value;
}

/** SVG ikon az egységes készletből (24×24, 1.6 px vonal). */
function mandala_icon(string $name, string $class = 'ico'): string
{
    $paths = mandala_data('icons');
    if (empty($paths[$name])) {
        return '';
    }
    return '<svg class="' . esc_attr($class) . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">' . $paths[$name] . '</svg>';
}

/** Ezres tagolás szóközzel (3 940 Ft), ahogy a jelenlegi mandala.hu-n. */
function mandala_fmt($amount): string
{
    return mandala_num($amount) . ' Ft';
}

/** Egész szám magyar ezres tagolással (1 200). */
function mandala_num($n): string
{
    return number_format((float) $n, 0, ',', ' ');
}

/** Kategória címke: slug → név. */
function mandala_term_name(string $slug, string $taxonomy = 'product_cat'): string
{
    $term = get_term_by('slug', $slug, $taxonomy);
    return $term && !is_wp_error($term) ? $term->name : '';
}

/** Egy termék fő- és alkategóriája (slugok). */
function mandala_product_cats(int $product_id): array
{
    $terms = get_the_terms($product_id, 'product_cat');
    $main = $sub = '';
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $term) {
            $ancestors = get_ancestors($term->term_id, 'product_cat');
            if (!$ancestors) {
                $main = $main ?: $term->slug;
                continue;
            }
            $top = get_term((int) end($ancestors), 'product_cat');
            $main = $top->slug;
            // Az alkategória a fő kategória közvetlen gyermeke
            $direct = count($ancestors) === 1 ? $term : get_term((int) $ancestors[count($ancestors) - 2], 'product_cat');
            $sub = $sub ?: $direct->slug;
        }
    }
    return [$main, $sub];
}

/** Attribútum értékek (slug vagy név) egy termékhez. */
function mandala_attr(WC_Product $product, string $taxonomy, string $field = 'name'): array
{
    $terms = wc_get_product_terms($product->get_id(), $taxonomy, ['fields' => 'all']);
    return array_values(array_map(fn($t) => $field === 'slug' ? $t->slug : $t->name, $terms ?: []));
}

/** Készletállapot a szűrőhöz és a kártyához: in | low | out. */
function mandala_stock(WC_Product $product): array
{
    if (!$product->is_in_stock()) {
        return ['out', 0];
    }
    $qty = $product->managing_stock() ? (int) $product->get_stock_quantity() : 99;
    $low = (int) get_option('woocommerce_notify_low_stock_amount', 2);
    return [$qty > 0 && $qty <= max(3, $low) ? 'low' : 'in', $qty ?: 99];
}

/** Új-e a termék: „uj” címke vagy 60 napon belüli megjelenés. */
function mandala_is_new(WC_Product $product): bool
{
    if (has_term('uj', 'product_tag', $product->get_id())) {
        return true;
    }
    $created = $product->get_date_created();
    return $created && $created->getTimestamp() > time() - 60 * DAY_IN_SECONDS;
}

/** Illusztráció URL, ha a terméknek nincs képe (a prototípus SVG-i). */
function mandala_art_url(WC_Product $product): string
{
    $art = get_post_meta($product->get_id(), '_mandala_art', true);
    $tone = get_post_meta($product->get_id(), '_mandala_tone', true);
    if (!$art) {
        [, $sub] = mandala_product_cats($product->get_id());
        $map = mandala_config('artBySub', []);
        $art = $map[$sub] ?? 'bowl';
    }
    $tone = $tone ?: 'sand';
    $file = "assets/art/{$art}-{$tone}.svg";
    return file_exists(MANDALA_DIR . '/' . $file) ? MANDALA_URL . '/' . $file : MANDALA_URL . '/assets/art/bowl-sand.svg';
}

function mandala_product_image(WC_Product $product, string $size = 'woocommerce_thumbnail'): string
{
    if ($product->get_image_id()) {
        return $product->get_image($size, ['loading' => 'lazy', 'decoding' => 'async']);
    }
    return '<img class="art" src="' . esc_url(mandala_art_url($product)) . '" alt="' . esc_attr($product->get_name()) . '" loading="lazy" decoding="async" width="400" height="451">';
}

/**
 * Szűrőadat egy termékhez – ugyanaz a szerkezet, mint a prototípus termékobjektuma,
 * így a facets.js motor változtatás nélkül dolgozik vele.
 */
function mandala_product_index_row(WC_Product $product): array
{
    $id = $product->get_id();
    [$cat, $sub] = mandala_product_cats($id);
    [$stock, $qty] = mandala_stock($product);
    // „edit” kontextus: a nyers árak, a felhasználófüggő szűrők (pl. nagyker ár) nélkül –
    // az index közös gyorsítótárba kerül, nem tartalmazhat viszonteladói árat.
    $regular = (float) $product->get_regular_price('edit');
    $on_sale = $product->is_on_sale('edit') && $product->get_sale_price('edit') !== '';
    $price = $on_sale ? (float) $product->get_sale_price('edit') : $regular;
    $attrs = array_filter([
        'hang' => mandala_attr($product, 'pa_hang')[0] ?? null,
        'hz' => ($hz = get_post_meta($id, '_mandala_hz', true)) !== '' ? (float) $hz : null,
        'suly' => ($suly = get_post_meta($id, '_mandala_suly', true)) !== '' ? (float) $suly : null,
        'csakra' => mandala_attr($product, 'pa_csakra', 'slug') ?: null,
        'keszites' => mandala_attr($product, 'pa_keszites', 'slug')[0] ?? null,
        'illat' => mandala_attr($product, 'pa_illat') ?: null,
        'forma' => mandala_attr($product, 'pa_forma')[0] ?? null,
        'meret' => mandala_attr($product, 'pa_meret') ?: null,
        'anyag' => mandala_attr($product, 'pa_anyag') ?: null,
        'szin' => mandala_attr($product, 'pa_szin') ?: null,
    ], fn($v) => $v !== null);
    $specs = [];
    foreach ($product->get_attributes() as $attribute) {
        if ($attribute->get_visible()) {
            $specs[wc_attribute_label($attribute->get_name())] = $product->get_attribute($attribute->get_name());
        }
    }
    return [
        'id' => $id,
        'slug' => $product->get_slug(),
        'name' => $product->get_name(),
        'sku' => $product->get_sku(),
        'cat' => $cat,
        'sub' => $sub,
        'price' => $price,
        'compare' => $on_sale && $regular > $price ? $regular : null,
        'stock' => $stock,
        'stockQty' => $qty,
        'isNew' => mandala_is_new($product),
        'featured' => $product->is_featured(),
        'intents' => mandala_attr($product, 'pa_szandek', 'slug'),
        'origin' => mandala_attr($product, 'pa_eredet', 'slug')[0] ?? '',
        'region' => mandala_attr($product, 'pa_regio')[0] ?? '',
        'attrs' => (object) $attrs,
        'specs' => (object) $specs,
        'short' => wp_strip_all_tags($product->get_short_description()),
        'order' => (int) $product->get_menu_order(),
    ];
}

/**
 * Termékkártya – a „Loop Product” minta szerkezete (li.product), a prototípus
 * productCard() függvényével azonos markup.
 */
function mandala_card(WC_Product $product): string
{
    $id = $product->get_id();
    $url = get_permalink($id);
    $name = $product->get_name();
    [$stock] = mandala_stock($product);
    $out = $stock === 'out';
    [$cat, $sub] = mandala_product_cats($id);
    $origin = mandala_attr($product, 'pa_eredet', 'slug')[0] ?? '';
    $origin_label = mandala_attr($product, 'pa_eredet')[0] ?? '';
    $badges = '';
    if ($out) {
        $badges .= '<span class="badge badge-dark">' . esc_html__('Elfogyott', 'mandala') . '</span>';
    } elseif (mandala_is_wholesale_user() && mandala_wholesale_price($product) !== null) {
        $badges .= '<span class="badge badge-sale">' . esc_html__('Nagyker ár', 'mandala') . '</span>';
    } elseif ($product->is_on_sale() && (float) $product->get_regular_price() > 0) {
        $pct = round((1 - (float) $product->get_price() / (float) $product->get_regular_price()) * 100);
        $badges .= '<span class="badge badge-sale">−' . (int) $pct . '%</span>';
    }
    if (!$out && mandala_is_new($product)) {
        $badges .= '<span class="badge">' . esc_html__('Új', 'mandala') . '</span>';
    }
    $badges = (string) apply_filters('mandala_card_badges', $badges, $product);
    $in_wish = mandala_wishlist_has($id);
    $add_attrs = sprintf(
        'href="%s" data-quantity="1" data-product_id="%d" data-product_sku="%s" rel="nofollow"',
        esc_url($product->add_to_cart_url()),
        $id,
        esc_attr($product->get_sku())
    );
    ob_start(); ?>
<li class="product type-product <?php echo $out ? 'outofstock' : 'instock'; ?>" data-id="<?php echo (int) $id; ?>">
  <div class="product-card">
    <div class="loop-product-image">
      <a href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true"><?php echo mandala_product_image($product); // phpcs:ignore ?></a>
      <div class="product-badges"><?php echo $badges; // phpcs:ignore ?></div>
      <button type="button" class="wishlist-toggle" data-wish="<?php echo (int) $id; ?>" aria-pressed="<?php echo $in_wish ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr(sprintf(__('Kedvencekhez: %s', 'mandala'), $name)); ?>"><?php echo mandala_icon('heart'); // phpcs:ignore ?></button>
      <?php echo apply_filters('mandala_card_media_extra', '', $product); // phpcs:ignore ?>
      <?php if (!$out && $product->is_purchasable() && $product->is_type('simple')) : ?>
      <div class="loop-quick"><a <?php echo $add_attrs; // phpcs:ignore ?> class="iu-button add_to_cart_button ajax_add_to_cart"><?php echo mandala_icon('plus', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Kosárba', 'mandala'); ?></a></div>
      <?php endif; ?>
    </div>
    <div class="loop-product-meta"><span class="origin origin-<?php echo esc_attr($origin); ?>"><?php echo esc_html($origin_label); ?></span><span class="text-muted" style="font-size:var(--fs-xs)"><?php echo esc_html(mandala_term_name($sub ?: $cat)); ?></span></div>
    <h3 class="loop-product-title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($name); ?></a></h3>
    <div class="loop-product-foot">
      <div class="loop-product-pirce"><span class="price"><?php echo $product->get_price_html(); // phpcs:ignore ?></span></div>
      <div class="loop-product-button">
        <?php if ($out || !$product->is_purchasable() || !$product->is_type('simple')) : ?>
          <a class="icon-button" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr($out ? __('Elfogyott – részletek', 'mandala') : sprintf(__('Részletek: %s', 'mandala'), $name)); ?>"><?php echo mandala_icon($out ? 'close' : 'arrow'); // phpcs:ignore ?></a>
        <?php else : ?>
          <a <?php echo $add_attrs; // phpcs:ignore ?> class="icon-button add_to_cart_button ajax_add_to_cart" aria-label="<?php echo esc_attr(sprintf(__('Kosárba: %s', 'mandala'), $name)); ?>"><?php echo mandala_icon('bag'); // phpcs:ignore ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</li>
    <?php
    return (string) ob_get_clean();
}

/** Blokk-attribútumból logikai érték (az iucb mezők stringként is jöhetnek). */
function mandala_bool($value): bool
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

/**
 * A témával szállított kép (assets/img/{név}-{szélesség}.webp) reszponzív <img>-ként,
 * vagy médiatár-kép, ha számot kap.
 */
function mandala_picture($image, string $alt = '', array $opts = []): string
{
    $opts += ['sizes' => '100vw', 'eager' => false, 'class' => '', 'style' => ''];
    $load = $opts['eager'] ? 'fetchpriority="high"' : 'loading="lazy"';
    if (is_numeric($image) && (int) $image > 0) {
        return wp_get_attachment_image((int) $image, 'full', false, array_filter([
            'alt' => $alt, 'sizes' => $opts['sizes'], 'class' => $opts['class'], 'style' => $opts['style'],
            'loading' => $opts['eager'] ? false : 'lazy', 'fetchpriority' => $opts['eager'] ? 'high' : false,
        ]));
    }
    $files = glob(MANDALA_DIR . '/assets/img/' . sanitize_file_name((string) $image) . '-*.webp') ?: [];
    if (!$files) {
        return '';
    }
    $set = [];
    foreach ($files as $file) {
        if (preg_match('/-(\d+)\.webp$/', $file, $m)) {
            $set[(int) $m[1]] = MANDALA_URL . '/assets/img/' . basename($file);
        }
    }
    ksort($set);
    $srcset = implode(', ', array_map(fn($w, $url) => esc_url($url) . ' ' . $w . 'w', array_keys($set), $set));
    return sprintf(
        '<img src="%s" srcset="%s" sizes="%s" alt="%s"%s%s %s decoding="async">',
        esc_url(reset($set)),
        $srcset,
        esc_attr($opts['sizes']),
        esc_attr($alt),
        $opts['class'] ? ' class="' . esc_attr($opts['class']) . '"' : '',
        $opts['style'] ? ' style="' . esc_attr($opts['style']) . '"' : '',
        $load
    );
}

/** Kosár darabszám jelvény (a WooCommerce fragment ezt cseréli). */
function mandala_cart_count_html(int $count): string
{
    return '<span class="count" data-cart-count' . ($count ? '' : ' hidden') . '>' . $count . '</span>';
}

/** Rövid bolti URL-ek: kínálat szűrővel. */
function mandala_shop_url(array $args = []): string
{
    $base = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    return $args ? add_query_arg(array_map('rawurlencode', $args), $base) : $base;
}

/**
 * [mandala_url page=kapcsolat] / [mandala_url cat=hangtalak] / [mandala_url post=hangtal-valasztas] / [mandala_url sku=MND-UTALVANY]
 * Telepítésfüggetlen belső linkek a sablonokban és a tartalomban (a bolt, a kategória- és
 * termékalapok, az oldalcímek telepítésenként eltérhetnek – a meglévő URL-ek maradnak).
 */
function mandala_url(array $atts): string
{
    $atts = shortcode_atts(['page' => '', 'cat' => '', 'post' => '', 'sku' => ''], $atts, 'mandala_url');
    if ($atts['sku'] && function_exists('wc_get_product_id_by_sku')) {
        $id = wc_get_product_id_by_sku(sanitize_text_field($atts['sku']));
        return $id ? (string) get_permalink($id) : mandala_shop_url();
    }
    if ($atts['cat']) {
        $term = get_term_by('slug', sanitize_title($atts['cat']), 'product_cat');
        return $term ? (string) get_term_link($term) : mandala_shop_url();
    }
    if ($atts['post']) {
        $post = get_page_by_path(sanitize_title($atts['post']), OBJECT, 'post');
        return $post ? (string) get_permalink($post) : (string) get_permalink((int) get_option('page_for_posts'));
    }
    $roles = [
        'shop' => 'woocommerce_shop_page_id', 'cart' => 'woocommerce_cart_page_id', 'checkout' => 'woocommerce_checkout_page_id',
        'account' => 'woocommerce_myaccount_page_id', 'terms' => 'woocommerce_terms_page_id', 'privacy' => 'wp_page_for_privacy_policy',
        'magazin' => 'page_for_posts',
    ];
    $slug = sanitize_title($atts['page']);
    if ($slug === 'muhelyek' && post_type_exists('mandala_workshop')) {
        return (string) get_post_type_archive_link('mandala_workshop');
    }
    if ($slug === 'esemenyek' && post_type_exists('mandala_event')) {
        return (string) get_post_type_archive_link('mandala_event');
    }
    $id = isset($roles[$slug]) ? (int) get_option($roles[$slug]) : (int) get_option('mandala_page_' . $slug);
    if (!$id && $slug && ($page = get_page_by_path($slug))) {
        $id = $page->ID;
    }
    return $id ? (string) get_permalink($id) : home_url('/');
}
add_shortcode('mandala_url', fn($atts) => esc_url(mandala_url((array) $atts)));

/**
 * Szállítási mód típusa: 'pickup' (személyes átvétel), 'point' (GLS CsomagPont / automata vagy
 * más csomagpont), 'courier' (házhozszállítás). A GLS módokat a GLS bővítmény adja: az azonosító
 * és a címke alapján ismerjük fel, így a bővítmény belső neveitől független.
 */
function mandala_shipping_kind(string $method_id, string $label = ''): string
{
    if (str_starts_with($method_id, 'local_pickup') || str_starts_with($method_id, 'pickup_location')) {
        return 'pickup';
    }
    $hay = strtolower($method_id . ' ' . remove_accents($label));
    if (preg_match('/parcel|locker|shop|point|pont|automat|csomagpont/', $hay)) {
        return 'point';
    }
    return 'courier';
}

/* ---------- Viszonteladói (nagyker) árak ---------- */

/**
 * Viszonteladó-e a bejelentkezett vásárló. Alapértelmezés: a WooCommerce Wholesale Prices
 * bővítmény „wholesale_customer” szerepe (a mandala.hu-n ez fut).
 */
function mandala_is_wholesale_user(?int $user_id = null): bool
{
    $user = $user_id ? get_userdata($user_id) : (is_user_logged_in() ? wp_get_current_user() : null);
    if (!$user) {
        return false;
    }
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    return (bool) array_intersect($roles, (array) $user->roles);
}

/**
 * A termék viszonteladói ára (bruttó), ha van. A Wholesale Prices bővítmény mezőjéből
 * („wholesale_customer_wholesale_price”) – ide kerül termékfelvételkor a JUTA „Akciós ár”-a.
 */
function mandala_wholesale_price(WC_Product $product): ?float
{
    $key = (string) apply_filters('mandala_wholesale_meta_key', 'wholesale_customer_wholesale_price');
    $value = $product->get_meta($key, true, 'edit');
    return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
}
