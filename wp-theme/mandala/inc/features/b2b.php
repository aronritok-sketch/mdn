<?php
/**
 * Viszonteladói felület (Fiókom → Viszonteladói felület) – csak viszonteladói szerepkörrel
 * (alapból a Wholesale Prices bővítmény „wholesale_customer” szerepe, lásd mandala_wholesale_roles).
 *  - gyorsrendelő táblázat: keresés, kategória, cikkszám, nagyker ár, készlet, mennyiség → kosárba egyszerre;
 *  - árlista letöltése (CSV, Excelben nyitható);
 *  - termékfotók letöltése kategóriánként (ZIP, cikkszám szerint elnevezve) a saját webshopjukhoz;
 *  - heti levél az új érkezésekről (feliratkozással).
 * Az árakat a kosárban a Wholesale Prices bővítmény számolja; a téma csak megjeleníti.
 */

defined('ABSPATH') || exit;

const MANDALA_B2B_ENDPOINT = 'nagyker';

add_filter('woocommerce_get_query_vars', fn($vars) => $vars + [MANDALA_B2B_ENDPOINT => MANDALA_B2B_ENDPOINT]);
add_action('init', function () {
    if (get_option('mandala_rewrite_b2b') !== '1') {
        add_action('wp_loaded', fn() => flush_rewrite_rules(false));
        update_option('mandala_rewrite_b2b', '1');
    }
}, 99);

add_filter('woocommerce_account_menu_items', function ($items) {
    if (!mandala_is_wholesale_user()) {
        return $items;
    }
    return ['dashboard' => $items['dashboard'] ?? __('Vezérlőpult', 'mandala'), MANDALA_B2B_ENDPOINT => __('Viszonteladói felület', 'mandala')] + $items;
});
add_filter('woocommerce_endpoint_' . MANDALA_B2B_ENDPOINT . '_title', fn() => __('Viszonteladói felület', 'mandala'));

/** Viszonteladó belépés után a felületre érkezik. */
add_filter('woocommerce_login_redirect', function ($redirect, $user) {
    $roles = (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']);
    return $user instanceof WP_User && array_intersect($roles, $user->roles) && str_contains($redirect, 'fiokom') ? wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT) : $redirect;
}, 10, 2);

add_action('woocommerce_account_' . MANDALA_B2B_ENDPOINT . '_endpoint', function () {
    if (!mandala_is_wholesale_user()) {
        echo '<p>' . esc_html__('Ez a felület a jóváhagyott viszonteladó partnereinknek szól.', 'mandala') . ' <a href="' . esc_url(mandala_url(['page' => 'viszonteladoknak'])) . '">' . esc_html__('Jelentkezés viszonteladónak', 'mandala') . '</a></p>';
        return;
    }
    $user = get_current_user_id();
    $cats = mandala_category_tree();
    $csv = wp_nonce_url(admin_url('admin-post.php?action=mandala_b2b_pricelist'), 'mandala_b2b');
    $subscribed = get_user_meta($user, '_mandala_b2b_arrivals', true) === 'yes';
    ?>
<div class="b2b-tools">
  <a class="iu-button iu-button-outline" href="<?php echo esc_url($csv); ?>"><?php echo mandala_icon('download', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Árlista (CSV)', 'mandala'); ?></a>
  <form class="b2b-images" method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="mandala_b2b_images"><?php wp_nonce_field('mandala_b2b', '_wpnonce', false); ?>
    <label class="sr-only" for="b2b-img-cat"><?php esc_html_e('Kategória a fotókhoz', 'mandala'); ?></label>
    <select id="b2b-img-cat" name="cat" class="input-text" required><?php foreach ($cats as $c) {
        echo '<option value="' . esc_attr($c['slug']) . '">' . esc_html($c['label']) . '</option>';
        foreach ($c['subs'] as [$slug, $label]) {
            echo '<option value="' . esc_attr($slug) . '">&nbsp;&nbsp;' . esc_html($label) . '</option>';
        }
    } ?></select>
    <button class="iu-button iu-button-outline" type="submit"><?php echo mandala_icon('camera', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Fotók (ZIP)', 'mandala'); ?></button>
  </form>
  <form method="post" class="b2b-arrivals">
    <?php wp_nonce_field('mandala_b2b_arrivals', '_b2bnonce'); ?>
    <label class="check"><input type="checkbox" name="mandala_b2b_arrivals" value="1"<?php checked($subscribed); ?> onchange="this.form.submit()"> <span><?php esc_html_e('Heti levél az új érkezésekről', 'mandala'); ?></span></label>
    <noscript><button class="iu-button iu-button-link" type="submit"><?php esc_html_e('Mentés', 'mandala'); ?></button></noscript>
  </form>
</div>

<div class="b2b-order" data-b2b>
  <div class="b2b-filters">
    <div class="iu-form-field"><label for="b2b-q"><?php esc_html_e('Keresés név vagy cikkszám szerint', 'mandala'); ?></label><input type="search" id="b2b-q" class="input-text" data-b2b-q autocomplete="off" placeholder="<?php esc_attr_e('pl. MND-HT vagy mala', 'mandala'); ?>"></div>
    <div class="iu-form-field"><label for="b2b-cat"><?php esc_html_e('Kategória', 'mandala'); ?></label><select id="b2b-cat" class="input-text" data-b2b-cat><option value=""><?php esc_html_e('Mind', 'mandala'); ?></option><?php foreach ($cats as $c) {
        echo '<option value="' . esc_attr($c['slug']) . '">' . esc_html($c['label']) . '</option>';
    } ?></select></div>
    <label class="check"><input type="checkbox" data-b2b-stock checked> <span><?php esc_html_e('Csak raktáron lévő', 'mandala'); ?></span></label>
  </div>
  <p class="text-small text-muted" aria-live="polite" data-b2b-count><?php esc_html_e('Termékek betöltése…', 'mandala'); ?></p>
  <div class="b2b-table-wrap">
    <table class="shop_table b2b-table">
      <caption class="sr-only"><?php esc_html_e('Gyorsrendelés: add meg a mennyiségeket, majd tedd a kosárba egyszerre', 'mandala'); ?></caption>
      <thead><tr><th scope="col"><?php esc_html_e('Termék', 'mandala'); ?></th><th scope="col"><?php esc_html_e('Cikkszám', 'mandala'); ?></th><th scope="col" class="num"><?php esc_html_e('Bolti ár', 'mandala'); ?></th><th scope="col" class="num"><?php esc_html_e('Nagyker ár', 'mandala'); ?></th><th scope="col" class="num"><?php esc_html_e('Készlet', 'mandala'); ?></th><th scope="col"><?php esc_html_e('Mennyiség', 'mandala'); ?></th></tr></thead>
      <tbody data-b2b-rows></tbody>
    </table>
  </div>
  <div class="b2b-bar" aria-live="polite"><div><span class="text-muted text-small"><?php esc_html_e('Kiválasztva', 'mandala'); ?></span> <strong data-b2b-sum>0 db · 0 Ft</strong></div>
    <button type="button" class="iu-button" data-b2b-add disabled><?php echo mandala_icon('bag', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Mind a kosárba', 'mandala'); ?></button>
    <p class="form-message" role="status" data-b2b-msg></p></div>
</div>
    <?php
    wp_enqueue_script_module('mandala-b2b', MANDALA_URL . '/assets/js/b2b.js', [], MANDALA_VERSION);
});

/* Feliratkozás mentése */
add_action('template_redirect', function () {
    if (isset($_POST['_b2bnonce']) && is_user_logged_in() && wp_verify_nonce(sanitize_key($_POST['_b2bnonce']), 'mandala_b2b_arrivals') && mandala_is_wholesale_user()) {
        update_user_meta(get_current_user_id(), '_mandala_b2b_arrivals', empty($_POST['mandala_b2b_arrivals']) ? 'no' : 'yes');
        wc_add_notice(empty($_POST['mandala_b2b_arrivals']) ? __('Leiratkoztál az új érkezések leveléről.', 'mandala') : __('Feliratkoztál: hetente írunk az új érkezésekről.', 'mandala'));
        wp_safe_redirect(wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT));
        exit;
    }
});

/* ---------- Tömeges kosárba (wc-ajax=mandala_bulk_add) ---------- */

add_action('wc_ajax_mandala_bulk_add', function () {
    check_ajax_referer('mandala-cart', 'security');
    if (!mandala_is_wholesale_user()) {
        wp_send_json(['ok' => false, 'error' => __('Ez a funkció viszonteladóknak szól.', 'mandala')], 403);
    }
    $items = json_decode(wp_unslash($_POST['items'] ?? '[]'), true); // phpcs:ignore
    $added = 0;
    $failed = [];
    foreach (array_slice(is_array($items) ? $items : [], 0, 200) as $row) {
        [$id, $qty] = [absint($row[0] ?? 0), absint($row[1] ?? 0)];
        if (!$id || !$qty) {
            continue;
        }
        if (WC()->cart->add_to_cart($id, $qty)) {
            $added += $qty;
        } else {
            $failed[] = get_the_title($id);
        }
    }
    wc_clear_notices();
    wp_send_json(['ok' => $added > 0, 'added' => $added, 'failed' => $failed, 'cart' => wc_get_cart_url(), 'count' => WC()->cart->get_cart_contents_count()]);
});

/* ---------- Árlista (CSV) ---------- */

add_action('admin_post_mandala_b2b_pricelist', function () {
    if (!mandala_is_wholesale_user() && !current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Nincs jogosultság.', 'mandala'), '', ['response' => 403]);
    }
    check_admin_referer('mandala_b2b');
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=mandala-arlista-' . wp_date('Y-m-d') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: az Excel így ismeri fel az ékezeteket
    fputcsv($out, ['Cikkszám', 'Termék', 'Kategória', 'Alkategória', 'Bolti ár (bruttó, Ft)', 'Nagyker ár (Ft)', 'Készlet', 'Eredet', 'Termékoldal', 'Kép'], ';', '"', '');
    foreach (mandala_product_index() as $row) {
        $product = wc_get_product($row['id']);
        if (!$product || !empty($row['voucher'])) {
            continue;
        }
        $wholesale = mandala_wholesale_price($product);
        $image = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'full') : '';
        fputcsv($out, [$row['sku'], $row['name'], $row['catLabel'] ?? $row['cat'], $row['sub'] ? mandala_term_name($row['sub']) : '', (int) $row['price'], $wholesale !== null ? (int) $wholesale : '', $product->managing_stock() ? (int) $product->get_stock_quantity() : ($product->is_in_stock() ? 'van' : 'nincs'), $row['originLabel'] ?? '', $row['url'], $image], ';', '"', '');
    }
    exit;
});

/* ---------- Termékfotók (ZIP, kategóriánként) ---------- */

add_action('admin_post_mandala_b2b_images', function () {
    if (!mandala_is_wholesale_user() && !current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Nincs jogosultság.', 'mandala'), '', ['response' => 403]);
    }
    check_admin_referer('mandala_b2b');
    $cat = sanitize_title(wp_unslash($_GET['cat'] ?? ''));
    if (!$cat || !class_exists('ZipArchive')) {
        wp_die(esc_html__('Válassz kategóriát.', 'mandala'), '', ['response' => 400]);
    }
    $files = [];
    foreach (mandala_product_index() as $row) {
        if (($row['cat'] === $cat || $row['sub'] === $cat) && empty($row['voucher'])) {
            $product = wc_get_product($row['id']);
            $ids = $product ? array_filter(array_merge([$product->get_image_id()], $product->get_gallery_image_ids())) : [];
            foreach (array_values($ids) as $i => $att) {
                $path = get_attached_file($att);
                if ($path && is_readable($path)) {
                    $files[sanitize_file_name(($row['sku'] ?: $row['slug']) . ($i ? '-' . ($i + 1) : '') . '.' . pathinfo($path, PATHINFO_EXTENSION))] = $path;
                }
            }
        }
    }
    if (!$files) {
        wp_die(esc_html__('Ebben a kategóriában még nincs feltöltött termékfotó.', 'mandala') . ' <a href="' . esc_url(wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT)) . '">' . esc_html__('Vissza', 'mandala') . '</a>', '', ['response' => 404]);
    }
    $tmp = wp_tempnam('mandala-fotok');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    foreach ($files as $name => $path) {
        $zip->addFile($path, $name);
    }
    $zip->close();
    nocache_headers();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename=mandala-fotok-' . $cat . '.zip');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    wp_delete_file($tmp);
    exit;
});

/* ---------- Heti levél az új érkezésekről ---------- */

add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_b2b_weekly', [], MANDALA_AS_GROUP)) {
        $next = (new DateTimeImmutable('next monday 08:00', wp_timezone()))->getTimestamp();
        as_schedule_recurring_action($next, WEEK_IN_SECONDS, 'mandala_b2b_weekly', [], MANDALA_AS_GROUP);
    }
}, 30);

add_action('mandala_b2b_weekly', function () {
    $since = gmdate('Y-m-d H:i:s', time() - WEEK_IN_SECONDS);
    $ids = wc_get_products(['status' => 'publish', 'visibility' => 'catalog', 'limit' => 30, 'return' => 'ids', 'date_created' => '>' . strtotime($since), 'orderby' => 'date', 'order' => 'DESC']);
    $ids = array_values(array_filter($ids, fn($id) => !mandala_is_voucher(wc_get_product($id))));
    if (!$ids) {
        return;
    }
    $users = get_users(['role__in' => (array) apply_filters('mandala_wholesale_roles', ['wholesale_customer']), 'meta_key' => '_mandala_b2b_arrivals', 'meta_value' => 'yes']);
    foreach ($users as $user) {
        wp_set_current_user($user->ID); // a nagyker ár a címzett szerint
        $rows = '';
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            $wholesale = mandala_wholesale_price($product);
            $rows .= mandala_mail_product_row($product, esc_html(($product->get_sku() ? $product->get_sku() . ' · ' : '') . ($wholesale !== null ? sprintf(__('nagyker: %s', 'mandala'), mandala_fmt($wholesale)) : mandala_fmt((float) $product->get_price()))));
        }
        $body = '<p>' . sprintf(esc_html__('Kedves %s!', 'mandala'), esc_html($user->first_name ?: $user->display_name)) . '</p><p>'
            . esc_html(sprintf(_n('Ezen a héten %d új termék érkezett.', 'Ezen a héten %d új termék érkezett.', count($ids), 'mandala'), count($ids))) . '</p>'
            . $rows . mandala_mail_button(wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT), __('Gyorsrendelés', 'mandala'))
            . '<p style="font-size:12px;color:#6E6357">' . esc_html__('A levelet a viszonteladói felületen kapcsoltad be; ugyanott ki is kapcsolhatod.', 'mandala') . '</p>';
        mandala_send_mail($user->user_email, __('Új érkezések a Mandalánál', 'mandala'), __('Új érkezések', 'mandala'), $body);
    }
    wp_set_current_user(0);
});

/* ---------- A viszonteladói oldalon: belépett partnernek link a felületre ---------- */

add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') === 'iu/form' && ($block['attrs']['formId'] ?? $block['attrs']['id'] ?? '') === 'viszontelado' && mandala_is_wholesale_user()) {
        return '<div class="panel b2b-welcome"><h3>' . esc_html__('Már partnerünk vagy', 'mandala') . '</h3><p>' . esc_html__('A nagyker árakat, a gyorsrendelőt, az árlistát és a termékfotókat a viszonteladói felületen találod.', 'mandala') . '</p><a class="iu-button" href="' . esc_url(wc_get_account_endpoint_url(MANDALA_B2B_ENDPOINT)) . '">' . esc_html__('Viszonteladói felület', 'mandala') . '</a></div>';
    }
    return $html;
}, 10, 2);
