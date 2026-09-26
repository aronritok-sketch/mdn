<?php
/**
 * Saját értékelések fotóval (az iu_theme a hozzászólásokat – így a WooCommerce értékeléseket
 * is – letiltja). Csak ellenőrzött vásárló értékelhet: a rendelés után küldött személyes link
 * (rendelés + termék + titkos kulcs) nyitja meg az űrlapot. Az értékelés moderálás után jelenik
 * meg (Termékek → Értékelések).
 */

defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('mandala_review', [
        'labels' => ['name' => 'Értékelések', 'singular_name' => 'Értékelés', 'menu_name' => 'Értékelések', 'edit_item' => 'Értékelés', 'all_items' => 'Értékelések'],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'edit.php?post_type=product',
        'supports' => ['title', 'editor'],
        'capability_type' => 'product',
        'map_meta_cap' => true,
    ]);
});

function mandala_review_url(int $order_id, int $product_id): string
{
    $page = (int) get_option('mandala_page_ertekeles');
    return add_query_arg(['rv_order' => $order_id, 'rv_product' => $product_id, 'rv_key' => mandala_token('review', (string) $order_id, (string) $product_id)], $page ? get_permalink($page) : home_url('/ertekeles/'));
}

/** Egy termék jóváhagyott értékelései és összesítése (gyorsítótárazva). */
function mandala_review_stats(int $product_id): array
{
    $product_id = mandala_original_id($product_id);
    $cached = get_post_meta($product_id, '_mandala_review_stats', true);
    if (is_array($cached)) {
        return $cached;
    }
    $reviews = get_posts(['post_type' => 'mandala_review', 'post_status' => 'publish', 'numberposts' => -1, 'meta_key' => '_product', 'meta_value' => $product_id, 'fields' => 'ids']);
    $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    foreach ($reviews as $id) {
        $r = max(1, min(5, (int) get_post_meta($id, '_rating', true)));
        $dist[$r]++;
    }
    $count = array_sum($dist);
    $stats = ['count' => $count, 'avg' => $count ? round(array_sum(array_map(fn($k, $v) => $k * $v, array_keys($dist), $dist)) / $count, 1) : 0, 'dist' => $dist];
    update_post_meta($product_id, '_mandala_review_stats', $stats);
    return $stats;
}
function mandala_flush_review_stats(int $review_id): void
{
    $product = (int) get_post_meta($review_id, '_product', true);
    if ($product) {
        delete_post_meta($product, '_mandala_review_stats');
        mandala_flush_index();
    }
}
add_action('transition_post_status', function ($new, $old, $post) {
    if ($post->post_type === 'mandala_review' && $new !== $old) {
        mandala_flush_review_stats($post->ID);
    }
}, 10, 3);

function mandala_stars(float $rating, string $label = ''): string
{
    $pct = max(0, min(100, $rating / 5 * 100));
    return '<span class="stars" role="img" aria-label="' . esc_attr($label ?: sprintf(__('%s / 5 csillag', 'mandala'), number_format_i18n($rating, 1))) . '"><span class="stars-fill" style="width:' . esc_attr((string) $pct) . '%"></span></span>';
}

add_filter('mandala_product_index_row', function ($row, WC_Product $product) {
    $s = mandala_review_stats($product->get_id());
    $row['rating'] = $s['count'] ? $s['avg'] : null;
    $row['reviews'] = $s['count'];
    return $row;
}, 10, 2);

add_filter('mandala_card_badges', function ($badges, WC_Product $product) {
    $s = mandala_review_stats($product->get_id());
    return $s['count'] ? $badges . '<span class="badge badge-rating" aria-label="' . esc_attr(sprintf(__('Értékelés: %s / 5', 'mandala'), number_format_i18n($s['avg'], 1))) . '">★ ' . esc_html(number_format_i18n($s['avg'], 1)) . '</span>' : $badges;
}, 20, 2);

/** Termékoldal: csillagok az ár felett, link az értékelésekhez. */
add_action('mandala_summary_after_price', function (WC_Product $product) {
    $s = mandala_review_stats($product->get_id());
    if ($s['count']) {
        echo '<a class="rating-link" href="#ertekelesek">' . mandala_stars($s['avg']) . '<span>' . esc_html(number_format_i18n($s['avg'], 1)) . ' · ' . esc_html(sprintf(_n('%d értékelés', '%d értékelés', $s['count'], 'mandala'), $s['count'])) . '</span></a>'; // phpcs:ignore
    }
}, 5);

/** Strukturált adat: aggregateRating és értékelések a WooCommerce Product JSON-LD-ben. */
add_filter('woocommerce_structured_data_product', function ($markup, $product) {
    $s = mandala_review_stats($product->get_id());
    if ($s['count']) {
        $markup['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $s['avg'], 'reviewCount' => $s['count'], 'bestRating' => 5, 'worstRating' => 1];
        $markup['review'] = array_map(fn($r) => [
            '@type' => 'Review', 'reviewRating' => ['@type' => 'Rating', 'ratingValue' => (int) get_post_meta($r->ID, '_rating', true)],
            'author' => ['@type' => 'Person', 'name' => (string) get_post_meta($r->ID, '_author', true)], 'reviewBody' => wp_strip_all_tags($r->post_content),
            'datePublished' => get_the_date('c', $r),
        ], get_posts(['post_type' => 'mandala_review', 'numberposts' => 5, 'meta_key' => '_product', 'meta_value' => mandala_original_id($product->get_id())]));
    }
    return $markup;
}, 10, 2);

/* ---------- Értékelés kérése e-mailben ---------- */

add_action('mandala_mail_review', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_automation_on('review')) {
        return;
    }
    do_action('mandala_before_order_mail', $order);
    $rows = '';
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && !$product->get_meta('_mandala_ticket_for')) {
            $rows .= mandala_mail_product_row($product, '<a href="' . esc_url(mandala_review_url($order->get_id(), $item->get_product_id())) . '" style="color:#8A4512">' . esc_html__('Értékelem →', 'mandala') . '</a>', mandala_review_url($order->get_id(), $item->get_product_id()));
        }
    }
    if (!$rows) {
        return;
    }
    mandala_mail('review', $order->get_billing_email(), mandala_mail_order_vars($order), ['termekek' => $rows], $order->get_id());
});

/* ---------- Beküldés ---------- */

add_action('admin_post_nopriv_mandala_review', 'mandala_handle_review');
add_action('admin_post_mandala_review', 'mandala_handle_review');
function mandala_handle_review(): void
{
    $order_id = absint($_POST['rv_order'] ?? 0);
    $product_id = absint($_POST['rv_product'] ?? 0);
    $key = sanitize_text_field(wp_unslash($_POST['rv_key'] ?? ''));
    $back = add_query_arg(['rv_order' => $order_id, 'rv_product' => $product_id, 'rv_key' => $key], wp_get_referer() ?: home_url('/'));
    $order = wc_get_order($order_id);
    if (!$order || !hash_equals(mandala_token('review', (string) $order_id, (string) $product_id), $key) || !wp_verify_nonce(sanitize_key($_POST['_wpnonce'] ?? ''), 'mandala_review')) {
        wp_safe_redirect(add_query_arg('review', 'invalid', $back));
        exit;
    }
    $rating = absint($_POST['rating'] ?? 0);
    $text = sanitize_textarea_field(wp_unslash($_POST['text'] ?? ''));
    if ($rating < 1 || $rating > 5 || mb_strlen($text) < 10) {
        wp_safe_redirect(add_query_arg('review', 'missing', $back));
        exit;
    }
    $existing = get_posts(['post_type' => 'mandala_review', 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'meta_query' => [['key' => '_order', 'value' => $order_id], ['key' => '_product', 'value' => $product_id]]]);
    $name = trim($order->get_billing_first_name() . ' ' . mb_substr($order->get_billing_last_name(), 0, 1) . '.');
    $id = wp_insert_post(['ID' => $existing[0] ?? 0, 'post_type' => 'mandala_review', 'post_status' => 'pending', 'post_title' => get_the_title($product_id) . ' – ' . $name, 'post_content' => $text]);
    if (is_wp_error($id)) {
        wp_safe_redirect(add_query_arg('review', 'error', $back));
        exit;
    }
    update_post_meta($id, '_product', mandala_original_id($product_id));
    update_post_meta($id, '_order', $order_id);
    update_post_meta($id, '_rating', $rating);
    update_post_meta($id, '_author', $name);
    update_post_meta($id, '_verified', '1');
    // Fotók (legfeljebb 3 kép, egyenként 8 MB)
    if (!empty($_FILES['photos']['name'][0])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $photos = [];
        $files = $_FILES['photos']; // phpcs:ignore
        for ($i = 0; $i < min(3, count($files['name'])); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK || $files['size'][$i] > 8 * MB_IN_BYTES || !str_starts_with((string) wp_check_filetype($files['name'][$i])['type'], 'image/')) {
                continue;
            }
            $_FILES['mandala_photo'] = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => 0, 'size' => $files['size'][$i]];
            $att = media_handle_upload('mandala_photo', 0, [], ['test_form' => false, 'mimes' => ['jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'heic' => 'image/heic']]);
            if (!is_wp_error($att)) {
                $photos[] = $att;
            }
        }
        update_post_meta($id, '_photos', $photos);
    }
    do_action('mandala_review_submitted', (int) $id, (int) $order_id, (int) $product_id);
    $to = mandala_automation_settings()['moderator'] ?: get_option('admin_email');
    wp_mail($to, sprintf('[%s] Új értékelés: %s', get_bloginfo('name'), get_the_title($product_id)), sprintf("%d/5 – %s\n\n%s\n\nJóváhagyás: %s", $rating, $name, $text, admin_url('post.php?post=' . $id . '&action=edit')));
    wp_safe_redirect(add_query_arg('review', 'thanks', $back));
    exit;
}

/* ---------- Admin: oszlopok, gyors jóváhagyás ---------- */

add_filter('manage_mandala_review_posts_columns', fn($c) => ['cb' => $c['cb'], 'title' => 'Értékelés', 'rating' => 'Csillag', 'photos' => 'Fotó', 'status' => 'Állapot', 'date' => $c['date']]);
add_action('manage_mandala_review_posts_custom_column', function ($col, $id) {
    if ($col === 'rating') {
        echo esc_html(str_repeat('★', (int) get_post_meta($id, '_rating', true)));
    } elseif ($col === 'photos') {
        foreach ((array) get_post_meta($id, '_photos', true) as $att) {
            echo wp_get_attachment_image((int) $att, [48, 48]); // phpcs:ignore
        }
    } elseif ($col === 'status') {
        echo get_post_status($id) === 'publish' ? 'Megjelenik' : '<strong>Jóváhagyásra vár</strong>'; // phpcs:ignore
    }
}, 10, 2);
add_action('add_meta_boxes', function () {
    add_meta_box('mandala_review_meta', 'Értékelés adatai', function ($post) {
        $product = (int) get_post_meta($post->ID, '_product', true);
        echo '<p>Termék: <a href="' . esc_url(get_edit_post_link($product)) . '">' . esc_html(get_the_title($product)) . '</a><br>Rendelés: #' . (int) get_post_meta($post->ID, '_order', true)
            . '<br>Csillag: ' . esc_html(str_repeat('★', (int) get_post_meta($post->ID, '_rating', true))) . '<br>Szerző: ' . esc_html((string) get_post_meta($post->ID, '_author', true)) . ' (ellenőrzött vásárló)</p>';
        foreach ((array) get_post_meta($post->ID, '_photos', true) as $att) {
            echo wp_get_attachment_image((int) $att, 'thumbnail'); // phpcs:ignore
        }
        echo '<p class="description">Közzététel = megjelenik a termékoldalon.</p>';
    }, 'mandala_review', 'side');
});

/* ---------- Blokkok ---------- */

add_action('init', function () {
    if (!function_exists('mandala_add_block')) {
        return;
    }
    mandala_add_block('mandala/product-reviews', [
        'title' => 'Termék értékelései',
        'category' => 'iu-woocommerce',
        'template' => function () {
            $product_id = get_queried_object_id();
            if (get_post_type($product_id) !== 'product') {
                return '';
            }
            $s = mandala_review_stats($product_id);
            $out = '<div class="reviews" id="ertekelesek"><div class="reviews-head"><h2>' . esc_html__('Vásárlói értékelések', 'mandala') . '</h2>';
            if (!$s['count']) {
                return $out . '</div><p class="text-muted">' . esc_html__('Ehhez a termékhez még nincs értékelés. A vásárlás után e-mailben küldünk egy személyes linket, amellyel értékelheted.', 'mandala') . '</p></div>';
            }
            $out .= '<div class="reviews-summary">' . mandala_stars($s['avg']) . '<strong>' . esc_html(number_format_i18n($s['avg'], 1)) . '</strong><span class="text-muted">' . esc_html(sprintf(_n('%d értékelés alapján', '%d értékelés alapján', $s['count'], 'mandala'), $s['count'])) . '</span></div></div><ul class="review-dist" aria-label="' . esc_attr__('Értékelések megoszlása', 'mandala') . '">';
            foreach ($s['dist'] as $star => $n) {
                $out .= '<li><span>' . (int) $star . ' ★</span><span class="meter"><span style="width:' . ($s['count'] ? round($n / $s['count'] * 100) : 0) . '%"></span></span><span class="text-muted">' . (int) $n . '</span></li>';
            }
            $out .= '</ul><ul class="review-list">';
            foreach (get_posts(['post_type' => 'mandala_review', 'numberposts' => 20, 'meta_key' => '_product', 'meta_value' => mandala_original_id($product_id)]) as $r) {
                $photos = '';
                foreach ((array) get_post_meta($r->ID, '_photos', true) as $att) {
                    $photos .= '<a href="' . esc_url(wp_get_attachment_image_url((int) $att, 'large')) . '" target="_blank" rel="noopener">' . wp_get_attachment_image((int) $att, 'thumbnail', false, ['loading' => 'lazy', 'alt' => esc_attr__('Vásárlói fotó', 'mandala')]) . '</a>';
                }
                $out .= '<li class="review"><div class="review-top">' . mandala_stars((float) get_post_meta($r->ID, '_rating', true)) . '<strong>' . esc_html((string) get_post_meta($r->ID, '_author', true)) . '</strong>'
                    . '<span class="review-verified">' . mandala_icon('check', 'ico ico-s') . esc_html__('Ellenőrzött vásárlás', 'mandala') . '</span><time datetime="' . esc_attr(get_the_date('Y-m-d', $r)) . '">' . esc_html(get_the_date('Y. F j.', $r)) . '</time></div>'
                    . '<p>' . nl2br(esc_html(wp_strip_all_tags($r->post_content))) . '</p>' . ($photos ? '<div class="review-photos">' . $photos . '</div>' : '') . '</li>';
            }
            return $out . '</ul></div>';
        },
    ]);

    mandala_add_block('mandala/review-form', [
        'title' => 'Értékelő űrlap (személyes linkkel)',
        'template' => function () {
            $order_id = absint($_GET['rv_order'] ?? 0);
            $product_id = absint($_GET['rv_product'] ?? 0);
            $key = sanitize_text_field(wp_unslash($_GET['rv_key'] ?? ''));
            $state = sanitize_key($_GET['review'] ?? '');
            if ($state === 'thanks') {
                return '<div class="empty-state">' . mandala_icon('check-circle', 'ico ico-xl') . '<h2 style="font-size:var(--fs-h3)">' . esc_html__('Köszönjük az értékelést!', 'mandala') . '</h2><p>' . esc_html__('Rövid ellenőrzés után megjelenik a termékoldalon.', 'mandala') . '</p><a class="iu-button" href="' . esc_url(mandala_shop_url()) . '">' . esc_html__('Vissza a kínálathoz', 'mandala') . '</a></div>';
            }
            if (!$order_id || !$product_id || !hash_equals(mandala_token('review', (string) $order_id, (string) $product_id), $key) || !($product = wc_get_product($product_id))) {
                return '<div class="empty-state">' . mandala_icon('info', 'ico ico-xl') . '<h2 style="font-size:var(--fs-h3)">' . esc_html__('Értékelni a vásárlás után küldött e-mailben lévő linkkel tudsz', 'mandala') . '</h2><p>' . esc_html__('Így biztosítjuk, hogy minden értékelés valódi vásárlótól származik.', 'mandala') . '</p></div>';
            }
            $msg = ['missing' => __('Válassz csillagot, és írj legalább egy mondatot.', 'mandala'), 'invalid' => __('A link lejárt vagy hibás.', 'mandala'), 'error' => __('Most nem sikerült menteni, próbáld újra.', 'mandala')][$state] ?? '';
            ob_start(); ?>
<form class="review-form panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" data-validate-form novalidate>
  <?php echo mandala_mail_product_row($product); // phpcs:ignore ?>
  <?php if ($msg) : ?><p class="form-message is-error"><?php echo mandala_icon('alert'); // phpcs:ignore ?><span><?php echo esc_html($msg); ?></span></p><?php endif; ?>
  <input type="hidden" name="action" value="mandala_review"><input type="hidden" name="rv_order" value="<?php echo (int) $order_id; ?>"><input type="hidden" name="rv_product" value="<?php echo (int) $product_id; ?>"><input type="hidden" name="rv_key" value="<?php echo esc_attr($key); ?>">
  <?php wp_nonce_field('mandala_review'); ?>
  <fieldset class="star-input"><legend><?php esc_html_e('Hány csillagot adsz?', 'mandala'); ?></legend>
    <?php for ($i = 5; $i >= 1; $i--) : ?><input type="radio" id="star-<?php echo $i; ?>" name="rating" value="<?php echo $i; ?>"><label for="star-<?php echo $i; ?>" aria-label="<?php echo esc_attr(sprintf(__('%d csillag', 'mandala'), $i)); ?>">★</label><?php endfor; ?>
  </fieldset>
  <div class="iu-form-field"><label for="review-text"><?php esc_html_e('Milyen lett?', 'mandala'); ?></label><textarea id="review-text" name="text" rows="5" minlength="10" maxlength="2000" data-validate="<?php echo esc_attr('required|' . __('Írj legalább egy mondatot.', 'mandala')); ?>" placeholder="<?php esc_attr_e('Pl. a hangja, a minősége, mire használod…', 'mandala'); ?>"></textarea></div>
  <div class="iu-form-field"><label for="review-photos"><?php esc_html_e('Fotók (nem kötelező, legfeljebb 3)', 'mandala'); ?></label><input type="file" id="review-photos" name="photos[]" accept="image/*" multiple></div>
  <p class="field-hint"><?php echo esc_html(sprintf(__('Nyilvánosan így jelenik meg a neved: %s', 'mandala'), wc_get_order($order_id)->get_billing_first_name() . ' ' . mb_substr(wc_get_order($order_id)->get_billing_last_name(), 0, 1) . '.')); ?></p>
  <div><button type="submit" class="iu-button iu-button-large"><?php esc_html_e('Értékelés küldése', 'mandala'); ?></button></div>
</form>
            <?php
            return (string) ob_get_clean();
        },
    ]);
});

/** Az értékelő oldal ne kerüljön a keresőkbe. */
add_filter('wp_robots', function ($robots) {
    if (is_page((int) get_option('mandala_page_ertekeles'))) {
        $robots['noindex'] = true;
    }
    return $robots;
});
