<?php
/**
 * Események (hangfürdő, workshop) jegyértékesítéssel.
 *
 * Az esemény saját bejegyzéstípus (dátum, helyszín, férőhely, jegyár). Mentéskor a téma
 * létrehozza / frissíti a hozzá tartozó rejtett, virtuális WooCommerce „jegy” terméket:
 * a férőhely a készlet, így a jegyvásárlás a megszokott kosár → pénztár úton megy, és a
 * számlázás (Számlázz.hu) is ugyanúgy működik.
 *
 * Sablonok: templates/single_mandala_event_content.html, archive_mandala_event_content.html
 */

defined('ABSPATH') || exit;

add_action('init', function () {
    register_post_type('mandala_event', [
        'labels' => [
            'name' => 'Események', 'singular_name' => 'Esemény', 'add_new_item' => 'Új esemény', 'edit_item' => 'Esemény szerkesztése',
            'all_items' => 'Összes esemény', 'menu_name' => 'Események',
        ],
        'public' => true,
        'has_archive' => 'esemenyek',
        'rewrite' => ['slug' => 'esemeny', 'with_front' => false],
        'menu_icon' => 'dashicons-calendar-alt',
        'menu_position' => 57,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'show_in_rest' => true,
    ]);
});

const MANDALA_EVENT_META = [
    '_mandala_event_start' => ['Kezdés', 'datetime-local'],
    '_mandala_event_end' => ['Befejezés', 'datetime-local'],
    '_mandala_event_place' => ['Helyszín', 'text'],
    '_mandala_event_capacity' => ['Férőhely (fő)', 'number'],
    '_mandala_event_price' => ['Jegyár (bruttó Ft)', 'number'],
];

add_action('add_meta_boxes', function () {
    add_meta_box('mandala_event', 'Esemény adatai', function ($post) {
        wp_nonce_field('mandala_event', 'mandala_event_nonce');
        foreach (MANDALA_EVENT_META as $key => [$label, $type]) {
            printf('<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="%3$s" class="widefat" id="%1$s" name="%1$s" value="%4$s"%5$s></p>',
                esc_attr($key), esc_html($label), esc_attr($type), esc_attr(get_post_meta($post->ID, $key, true)), $type === 'number' ? ' min="0" step="1"' : '');
        }
        $ticket = (int) get_post_meta($post->ID, '_mandala_event_product', true);
        if ($ticket && ($p = wc_get_product($ticket))) {
            printf('<p>Jegy termék: <a href="%s">#%d</a> – eladva: %d, szabad: %s</p>', esc_url(get_edit_post_link($ticket)), $ticket, (int) $p->get_total_sales(), esc_html((string) $p->get_stock_quantity()));
        } else {
            echo '<p class="description">Mentéskor a jegy termék magától létrejön (rejtett, virtuális, a férőhely a készlete).</p>';
        }
    }, 'mandala_event', 'side');
});

add_action('save_post_mandala_event', function ($post_id, $post) {
    if (wp_is_post_revision($post_id) || !isset($_POST['mandala_event_nonce']) || !wp_verify_nonce(sanitize_key($_POST['mandala_event_nonce']), 'mandala_event') || !current_user_can('edit_post', $post_id)) {
        return;
    }
    foreach (MANDALA_EVENT_META as $key => [, $type]) {
        $raw = sanitize_text_field(wp_unslash($_POST[$key] ?? ''));
        update_post_meta($post_id, $key, $type === 'number' ? ($raw === '' ? '' : (string) max(0, (int) $raw)) : $raw);
    }
    // Fordított eseménynél (WPML) nincs külön jegy: a helyek száma közös, az eredeti esemény jegyét árusítjuk.
    if (mandala_original_id($post_id, 'mandala_event') === $post_id) {
        mandala_sync_event_ticket($post_id);
    }
}, 10, 2);

/** A jegy termék létrehozása / frissítése az esemény adataiból. */
function mandala_sync_event_ticket(int $event_id): ?WC_Product
{
    $event = get_post($event_id);
    if (!$event || !function_exists('wc_get_product')) {
        return null;
    }
    $ticket_id = (int) get_post_meta($event_id, '_mandala_event_product', true);
    $product = $ticket_id ? wc_get_product($ticket_id) : null;
    if (!$product) {
        $product = new WC_Product_Simple();
    }
    $start = (string) get_post_meta($event_id, '_mandala_event_start', true);
    $capacity = (int) get_post_meta($event_id, '_mandala_event_capacity', true);
    $sold = $product->get_id() ? (int) $product->get_total_sales() : 0;
    $product->set_name(sprintf(__('Jegy: %1$s (%2$s)', 'mandala'), $event->post_title, mandala_event_date($start, 'Y. m. d. H:i')));
    $product->set_status($event->post_status === 'publish' ? 'publish' : 'private');
    $product->set_catalog_visibility('hidden');
    $product->set_virtual(true);
    $product->set_regular_price((string) (int) get_post_meta($event_id, '_mandala_event_price', true));
    $product->set_manage_stock(true);
    $product->set_stock_quantity(max(0, $capacity - $sold));
    $product->set_backorders('no');
    $product->set_short_description(get_the_excerpt($event));
    $product->update_meta_data('_mandala_ticket_for', (string) $event_id);
    if (get_post_meta($event_id, '_mandala_demo', true)) {
        $product->update_meta_data('_mandala_demo', '1');
    }
    $id = $product->save();
    update_post_meta($event_id, '_mandala_event_product', $id);
    return $product;
}

/** A (helyi idő szerint tárolt) időpont Unix időbélyege. */
function mandala_event_ts(string $value): int
{
    try {
        return $value ? (new DateTimeImmutable($value, wp_timezone()))->getTimestamp() : 0;
    } catch (Exception $e) {
        return 0;
    }
}

function mandala_event_date(string $value, string $format = 'Y. F j., l H:i'): string
{
    return $value ? wp_date($format, mandala_event_ts($value)) : '';
}

function mandala_event_ticket(int $event_id): ?WC_Product
{
    $p = wc_get_product((int) get_post_meta(mandala_original_id($event_id, 'mandala_event'), '_mandala_event_product', true));
    return $p ?: null;
}

/** A jegy termékoldala az eseményre irányít (a jegy maga rejtett). */
add_action('template_redirect', function () {
    if (is_singular('product') && ($event = (int) get_post_meta(get_queried_object_id(), '_mandala_ticket_for', true))) {
        wp_safe_redirect(get_permalink($event), 301);
        exit;
    }
});

/** Rendelés tételen: időpont és helyszín. */
add_action('woocommerce_checkout_create_order_line_item', function ($item, $key, $values) {
    $product = $values['data'] ?? null;
    $event = $product instanceof WC_Product ? (int) $product->get_meta('_mandala_ticket_for') : 0;
    if ($event) {
        $item->add_meta_data(__('Időpont', 'mandala'), mandala_event_date((string) get_post_meta($event, '_mandala_event_start', true)));
        $item->add_meta_data(__('Helyszín', 'mandala'), (string) get_post_meta($event, '_mandala_event_place', true));
    }
}, 10, 3);

/* ---------- Blokkok ---------- */

add_action('init', function () {
    if (!function_exists('mandala_add_block')) {
        return;
    }

    mandala_add_block('mandala/events', [
        'title' => 'Közelgő események',
        'attributes' => ['limit' => mandala_attr_def('6'), 'eyebrow' => mandala_attr_def(''), 'heading' => mandala_attr_def('')],
        'fields' => [['panel' => 'Beállítások', 'fields' => [
            'limit' => ['type' => 'text', 'label' => 'Darabszám'],
            'eyebrow' => ['type' => 'text', 'label' => 'Felirat (üresen nincs fejléc)'],
            'heading' => ['type' => 'text', 'label' => 'Cím'],
        ]]],
        'template' => function ($attributes) {
            $events = get_posts([
                'post_type' => 'mandala_event', 'numberposts' => max(1, (int) ($attributes['limit'] ?? 6)),
                'meta_key' => '_mandala_event_start', 'orderby' => 'meta_value', 'order' => 'ASC',
                'meta_query' => [['key' => '_mandala_event_start', 'value' => wp_date('Y-m-d\TH:i'), 'compare' => '>=']],
            ]);
            if (!$events) {
                return empty($attributes['heading']) ? '<p class="text-muted">' . esc_html__('Jelenleg nincs meghirdetett esemény – iratkozz fel a hírlevélre, és szólunk.', 'mandala') . '</p>' : '';
            }
            $out = '';
            if (!empty($attributes['heading'])) {
                $out .= '<div class="section-head"><div>' . ($attributes['eyebrow'] ? '<p class="eyebrow">' . esc_html($attributes['eyebrow']) . '</p>' : '') . '<h2>' . esc_html($attributes['heading']) . '</h2></div>'
                    . '<a class="iu-button iu-button-outline" href="' . esc_url(get_post_type_archive_link('mandala_event')) . '">' . esc_html__('Összes esemény', 'mandala') . '</a></div>';
            }
            $out .= '<ul class="event-list">';
            foreach ($events as $e) {
                $start = (string) get_post_meta($e->ID, '_mandala_event_start', true);
                $ticket = mandala_event_ticket($e->ID);
                $left = $ticket ? (int) $ticket->get_stock_quantity() : 0;
                $status = !$ticket || $left <= 0 ? __('Betelt – várólista', 'mandala') : ($left <= 3 ? sprintf(__('Utolsó %d hely', 'mandala'), $left) : sprintf(__('%d szabad hely', 'mandala'), $left));
                $out .= '<li class="event-card reveal"><a href="' . esc_url(get_permalink($e)) . '">'
                    . '<span class="event-date" aria-hidden="true"><strong>' . esc_html(wp_date('j', mandala_event_ts($start))) . '</strong>' . esc_html(wp_date('M', mandala_event_ts($start))) . '</span>'
                    . '<span class="event-body"><span class="post-meta"><time datetime="' . esc_attr($start) . '">' . esc_html(mandala_event_date($start)) . '</time><span>' . esc_html((string) get_post_meta($e->ID, '_mandala_event_place', true)) . '</span></span>'
                    . '<strong class="event-title">' . esc_html($e->post_title) . '</strong><span class="event-excerpt">' . esc_html(get_the_excerpt($e)) . '</span></span>'
                    . '<span class="event-side"><span class="num">' . ($ticket ? esc_html(mandala_fmt((float) $ticket->get_price())) : '') . '</span><small class="' . ($left <= 3 ? 'is-low' : '') . '">' . esc_html($status) . '</small>' . mandala_icon('arrow') . '</span></a></li>';
            }
            return $out . '</ul>';
        },
    ]);

    mandala_add_block('mandala/event-ticket', [
        'title' => 'Esemény: időpont és jegyvásárlás',
        'template' => function () {
            $event = get_post(get_queried_object_id());
            if (!$event || $event->post_type !== 'mandala_event') {
                return '';
            }
            wp_enqueue_script_module('mandala-product', MANDALA_URL . '/assets/js/product.js', ['mandala-site'], MANDALA_VERSION);
            $start = (string) get_post_meta($event->ID, '_mandala_event_start', true);
            $end = (string) get_post_meta($event->ID, '_mandala_event_end', true);
            $place = (string) get_post_meta($event->ID, '_mandala_event_place', true);
            $ticket = mandala_event_ticket($event->ID);
            $left = $ticket ? (int) $ticket->get_stock_quantity() : 0;
            $past = $start && mandala_event_ts($start) < time();
            ob_start(); ?>
<div class="event-ticket panel">
  <ul class="workshop-facts" style="padding:0;border:0;background:none">
    <li><?php echo mandala_icon('calendar'); // phpcs:ignore ?><span><strong><?php esc_html_e('Időpont', 'mandala'); ?></strong><?php echo esc_html(mandala_event_date($start) . ($end ? ' – ' . wp_date('H:i', mandala_event_ts($end)) : '')); ?></span></li>
    <?php if ($place) : ?><li><?php echo mandala_icon('pin'); // phpcs:ignore ?><span><strong><?php esc_html_e('Helyszín', 'mandala'); ?></strong><?php echo esc_html($place); ?></span></li><?php endif; ?>
    <?php if ($ticket) : ?><li><?php echo mandala_icon('ticket'); // phpcs:ignore ?><span><strong><?php esc_html_e('Jegy', 'mandala'); ?></strong><?php echo wp_kses_post(wc_price(wc_get_price_to_display($ticket))); ?> · <?php echo esc_html($left > 0 ? sprintf(__('%d szabad hely', 'mandala'), $left) : __('betelt', 'mandala')); ?></span></li><?php endif; ?>
  </ul>
  <?php if ($past) : ?>
    <p class="text-muted"><?php esc_html_e('Ez az esemény már lezajlott.', 'mandala'); ?> <a href="<?php echo esc_url(get_post_type_archive_link('mandala_event')); ?>"><?php esc_html_e('Közelgő események', 'mandala'); ?> →</a></p>
  <?php elseif ($ticket && $left > 0 && $ticket->is_purchasable()) : ?>
  <form class="cart" action="<?php echo esc_url(get_permalink($event)); ?>" method="post" data-cart-form data-product-id="<?php echo (int) $ticket->get_id(); ?>">
    <div class="quantity" data-qty><button type="button" data-step="-1" aria-label="<?php esc_attr_e('Eggyel kevesebb', 'mandala'); ?>"><?php echo mandala_icon('minus'); // phpcs:ignore ?></button>
      <input type="number" inputmode="numeric" name="quantity" min="1" max="<?php echo (int) min(10, $left); ?>" value="1" aria-label="<?php esc_attr_e('Jegyek száma', 'mandala'); ?>"><button type="button" data-step="1" aria-label="<?php esc_attr_e('Eggyel több', 'mandala'); ?>"><?php echo mandala_icon('plus'); // phpcs:ignore ?></button></div>
    <button type="submit" name="add-to-cart" value="<?php echo (int) $ticket->get_id(); ?>" class="iu-button iu-button-large single_add_to_cart_button"><?php echo mandala_icon('ticket'); // phpcs:ignore ?> <?php esc_html_e('Jegyet veszek', 'mandala'); ?></button>
  </form>
  <p class="field-hint"><?php esc_html_e('A jegyet e-mailben küldjük a visszaigazolással; a helyszínen elég a nevedet mondani.', 'mandala'); ?></p>
  <?php elseif ($ticket) : ?>
  <div class="product-notify"><strong><?php esc_html_e('Betelt – szólunk, ha felszabadul hely', 'mandala'); ?></strong>
    <form class="mandala-form" data-mandala-form="stock-notify" novalidate data-success="<?php esc_attr_e('Felírtunk a várólistára.', 'mandala'); ?>">
      <input type="hidden" name="product" value="<?php echo (int) $ticket->get_id(); ?>">
      <div class="nl-row"><div class="iu-form-field"><label class="sr-only" for="notify-email"><?php esc_html_e('E-mail-cím', 'mandala'); ?></label><input type="email" id="notify-email" name="email" autocomplete="email" placeholder="nev@pelda.hu" data-validate="<?php echo esc_attr("required|" . __('Add meg az e-mail-címed.', 'mandala') . "\nemail|" . __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala')); ?>"></div><button class="iu-button" type="submit"><?php esc_html_e('Várólistára', 'mandala'); ?></button></div>
      <div class="check-row"><label class="iu-form-accept" for="notify-accept"><input type="checkbox" id="notify-accept" name="accept" data-validate="required|<?php esc_attr_e('Az elküldéshez fogadd el az adatkezelési tájékoztatót.', 'mandala'); ?>"> <span><?php echo wp_kses_post(sprintf(__('Elfogadom az <a href="%s">adatkezelési tájékoztatót</a>.', 'mandala'), esc_url(get_privacy_policy_url()))); ?></span></label></div>
      <p class="form-message" role="status"></p>
    </form></div>
  <?php endif; ?>
</div>
            <?php
            $json = ['@context' => 'https://schema.org', '@type' => 'Event', 'name' => $event->post_title, 'startDate' => $start ? wp_date('c', mandala_event_ts($start)) : null,
                'endDate' => $end ? wp_date('c', mandala_event_ts($end)) : null, 'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'eventStatus' => 'https://schema.org/EventScheduled', 'location' => ['@type' => 'Place', 'name' => $place, 'address' => mandala_config('contact')['address'] ?? ''],
                'description' => get_the_excerpt($event), 'organizer' => ['@type' => 'Organization', 'name' => get_bloginfo('name'), 'url' => home_url('/')]];
            if ($ticket) {
                $json['offers'] = ['@type' => 'Offer', 'price' => (float) $ticket->get_price(), 'priceCurrency' => 'HUF', 'availability' => 'https://schema.org/' . ($left > 0 ? 'InStock' : 'SoldOut'), 'url' => get_permalink($event)];
            }
            echo '<script type="application/ld+json">' . wp_json_encode(array_filter($json)) . '</script>';
            return (string) ob_get_clean();
        },
    ]);
});

/* ---------- Bemutató események ---------- */

add_filter('mandala_demo_post_types', fn($types) => array_merge($types, ['mandala_event']));

add_action('mandala_demo_features', function () {
    $demo = [
        ['Hangfürdő a bemutatóteremben', '+10 19:00', 90, 12, 6900, 'Egy óra elengedés tibeti és kovácsolt hangtálakkal, gongokkal. Matracot és takarót biztosítunk.'],
        ['Hangtál-workshop kezdőknek', '+24 10:00', 180, 8, 14900, 'Megtanulod megszólaltatni és tisztán tartani a hangtálat, és kiválasztod a hozzád illőt. Kezdőknek.'],
    ];
    foreach ($demo as [$title, $when, $minutes, $capacity, $price, $excerpt]) {
        if (get_posts(['post_type' => 'mandala_event', 'title' => $title, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids'])) {
            continue;
        }
        $id = wp_insert_post(['post_type' => 'mandala_event', 'post_status' => 'publish', 'post_title' => $title, 'post_excerpt' => $excerpt,
            'post_content' => "<!-- wp:paragraph -->\n<p>{$excerpt}</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>[Bemutató esemény – a részletes program, a vezető bemutatása és a tudnivalók élesítés előtt kerülnek ide.]</p>\n<!-- /wp:paragraph -->"]);
        if (is_wp_error($id)) {
            continue;
        }
        [$rel, $time] = explode(' ', substr($when, 1), 2) + [1 => '19:00'];
        $start = (new DateTimeImmutable('now', wp_timezone()))->modify('+' . $rel . ' days')->setTime(...array_map('intval', explode(':', $time)));
        update_post_meta($id, '_mandala_event_start', $start->format('Y-m-d\TH:i'));
        update_post_meta($id, '_mandala_event_end', $start->modify('+' . $minutes . ' minutes')->format('Y-m-d\TH:i'));
        update_post_meta($id, '_mandala_event_place', 'Mandala bemutatóterem, Budapest');
        update_post_meta($id, '_mandala_event_capacity', (string) $capacity);
        update_post_meta($id, '_mandala_event_price', (string) $price);
        update_post_meta($id, '_mandala_demo', '1');
        mandala_sync_event_ticket($id);
    }
    flush_rewrite_rules(false);
});
