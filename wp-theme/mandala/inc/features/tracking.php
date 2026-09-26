<?php
/**
 * Csomagkövetés: a rendelés útja egy saját, márkás oldalon – nem csak egy GLS link.
 *
 *  - Idővonal: megrendelve → fizetve / utalásra vár → csomagoljuk (előrendelésnél: várható érkezés)
 *    → feladva (GLS csomagszám, élő követés a GLS oldalán) / átvehető a bemutatóteremben.
 *  - Hol látja a vásárló: „Csomagkövetés” oldal (rendelésszám + e-mail, vagy aláírt link a levelekből),
 *    Fiókom → rendelés, köszönőoldal, és minden WooCommerce vásárlói levélben egy követő doboz.
 *  - Csomagszám: a GLS bővítmény rendelés-metájából (a kulcsok a Mandala levelek → Beállítások alatt
 *    állíthatók), a WooCommerce Shipment Tracking bővítményből, vagy kézzel a rendelés oldalán.
 *  - „Feladtuk” levél, amint a csomagszám megjelenik (egyszer); személyes átvételnél „Átvehető” levél a
 *    rendelés műveletei közül. Mindkettő a levélközpontban szerkeszthető / kikapcsolható.
 */

defined('ABSPATH') || exit;

function mandala_tracking_settings(): array
{
    return wp_parse_args((array) get_option('mandala_tracking', []), [
        // Ismert GLS / szállítási bővítmények csomagszám mezői (soronként egy; a legelső létező nyer).
        'meta_keys' => "_mandala_tracking\n_gls_parcel_number\n_gls_parcel_numbers\n_gls_tracking_number\ngls_parcel_number\n_vp_woo_pont_parcel_number\n_tracking_number",
        'url' => 'https://gls-group.com/HU/hu/csomagkovetes?match={szam}',
        'carrier' => 'GLS',
    ]);
}

/**
 * A rendelés csomagszámai: [['number', 'url', 'carrier']]. Több csomag is lehet (vesszővel vagy tömbben).
 */
function mandala_order_tracking(WC_Order $order): array
{
    $s = mandala_tracking_settings();
    $numbers = [];
    $add = function ($value) use (&$numbers, &$add) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $add(is_array($v) ? ($v['tracking_number'] ?? $v['number'] ?? $v['parcel_number'] ?? '') : $v);
            }
            return;
        }
        foreach (preg_split('/[\s,;]+/', (string) $value) as $n) {
            $n = preg_replace('/[^A-Za-z0-9-]/', '', $n);
            if (strlen($n) >= 6) {
                $numbers[$n] = true;
            }
        }
    };
    foreach (preg_split('/\R/', (string) $s['meta_keys']) as $key) {
        $key = trim($key);
        if ($key !== '' && ($value = $order->get_meta($key)) !== '' && $value !== null) {
            $add(maybe_unserialize($value));
        }
    }
    // WooCommerce Shipment Tracking bővítmény
    $add((array) $order->get_meta('_wc_shipment_tracking_items'));
    $out = [];
    foreach (array_keys($numbers) as $n) {
        $out[] = ['number' => (string) $n, 'url' => str_replace('{szam}', rawurlencode((string) $n), (string) $s['url']), 'carrier' => (string) $s['carrier']];
    }
    return (array) apply_filters('mandala_order_tracking', $out, $order);
}

/** Aláírt követő link (levelekbe, vendég vásárlónak is). */
function mandala_tracking_url(WC_Order $order): string
{
    $page = (int) get_option('mandala_page_csomagkovetes');
    $base = $page ? (string) get_permalink($page) : (string) $order->get_view_order_url();
    return add_query_arg(['rendeles' => $order->get_id(), 'k' => mandala_token('track', (string) $order->get_id(), strtolower($order->get_billing_email()))], $base);
}

function mandala_order_shipping_kind(WC_Order $order): string
{
    foreach ($order->get_shipping_methods() as $m) {
        return mandala_shipping_kind((string) $m->get_method_id(), (string) $m->get_name());
    }
    return '';
}

/** Van-e a rendelésben feladandó (fizikai) termék – utalvány, jegy nélkül. */
function mandala_order_needs_shipping(WC_Order $order): bool
{
    foreach ($order->get_items() as $item) {
        $p = $item->get_product();
        if ($p && !$p->is_virtual() && !$p->get_meta('_mandala_ticket_for') && !(function_exists('mandala_is_voucher') && mandala_is_voucher($p))) {
            return true;
        }
    }
    return false;
}

/**
 * Az idővonal lépései: [['label', 'text', 'state' => done|current|todo, 'date']].
 */
function mandala_order_timeline(WC_Order $order): array
{
    $fmt = fn($d) => $d ? wp_date('Y. m. d. H:i', $d->getTimestamp()) : '';
    $status = $order->get_status();
    $kind = mandala_order_shipping_kind($order);
    $tracking = mandala_order_tracking($order);
    $shipped = (int) $order->get_meta('_mandala_shipped') ?: ($tracking ? 1 : 0);
    $ready = (int) $order->get_meta('_mandala_pickup_ready');
    $paid = (bool) $order->get_date_paid() || in_array($status, ['completed'], true);
    $cod = $order->get_payment_method() === 'cod';
    $steps = [['label' => __('Megrendelve', 'mandala'), 'text' => sprintf(__('Rendelésszám: %s', 'mandala'), $order->get_order_number()), 'state' => 'done', 'date' => $fmt($order->get_date_created())]];

    if ($cod) {
        $steps[] = ['label' => __('Fizetés átvételkor', 'mandala'), 'text' => $kind === 'pickup' ? __('Készpénzzel vagy kártyával a bemutatóteremben', 'mandala') : __('Utánvét: a futárnál vagy a GLS ponton', 'mandala'), 'state' => 'done', 'date' => ''];
    } else {
        $steps[] = $paid
            ? ['label' => __('Fizetve', 'mandala'), 'text' => $order->get_payment_method_title(), 'state' => 'done', 'date' => $fmt($order->get_date_paid())]
            : ['label' => $order->get_payment_method() === 'bacs' ? __('Várjuk az utalást', 'mandala') : __('Fizetésre vár', 'mandala'), 'text' => $order->get_payment_method() === 'bacs' ? __('A jóváírás után azonnal csomagolunk', 'mandala') : '', 'state' => 'current', 'date' => ''];
    }
    $moving = $shipped || $ready || $status === 'completed';
    $packing = ['label' => __('Csomagoljuk', 'mandala'), 'text' => __('Általában 1 munkanapon belül', 'mandala'), 'state' => $moving ? 'done' : (($paid || $cod) && in_array($status, ['processing', 'on-hold'], true) ? 'current' : 'todo'), 'date' => ''];
    // Előrendelt termék: a szállítmány érkezése után indul.
    if (!$moving && function_exists('mandala_incoming_date')) {
        foreach ($order->get_items() as $item) {
            $p = $item->get_product();
            if ($p && ($date = mandala_incoming_date($p))) {
                $packing['text'] = sprintf(__('Előrendelt termék (%s): a szállítmány érkezése után adjuk fel – várhatóan %s', 'mandala'), $p->get_name(), mandala_incoming_label($date, false));
                break;
            }
        }
    }
    $steps[] = $packing;

    if ($kind === 'pickup') {
        $c = (array) mandala_config('contact', []);
        $steps[] = ['label' => __('Átvehető a bemutatóteremben', 'mandala'), 'text' => trim(($c['address'] ?? '') . ' · ' . ($c['hours'] ?? ''), ' ·'), 'state' => $ready || $status === 'completed' ? 'done' : 'todo', 'date' => $ready > 1 ? wp_date('Y. m. d. H:i', $ready) : ''];
    } elseif (mandala_order_needs_shipping($order)) {
        $steps[] = ['label' => $kind === 'point' ? __('Feladtuk – a GLS pontra tart', 'mandala') : __('Feladtuk – úton hozzád', 'mandala'),
            'text' => $tracking ? sprintf(__('GLS csomagszám: %s', 'mandala'), implode(', ', array_column($tracking, 'number'))) : ($kind === 'point' ? __('Az átvételi értesítőt a GLS SMS-ben és e-mailben küldi', 'mandala') : __('A csomagszámot e-mailben küldjük', 'mandala')),
            'state' => $shipped || $status === 'completed' ? 'done' : 'todo', 'date' => $shipped > 1 ? wp_date('Y. m. d. H:i', $shipped) : ''];
    }
    if ($delivered = (int) $order->get_meta('_mandala_delivered')) {
        $steps[] = ['label' => $kind === 'pickup' ? __('Átvetted', 'mandala') : __('Kézbesítve', 'mandala'), 'text' => __('Jó elcsendesedést!', 'mandala'), 'state' => 'done', 'date' => wp_date('Y. m. d. H:i', $delivered)];
    }
    // Az utolsó kész lépés után az első hátralévő a „folyamatban”.
    $has_current = in_array('current', array_column($steps, 'state'), true);
    foreach ($steps as $i => $step) {
        if (!$has_current && $step['state'] === 'todo' && ($steps[$i - 1]['state'] ?? '') === 'done') {
            $steps[$i]['state'] = 'current';
            break;
        }
    }
    return (array) apply_filters('mandala_order_timeline', $steps, $order);
}

/** A követő panel HTML-je (követőoldal, Fiókom). */
function mandala_tracking_html(WC_Order $order, bool $full = true): string
{
    $status = $order->get_status();
    if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
        return '<div class="woocommerce-info" role="status">' . mandala_icon('info') . '<span>' . esc_html(sprintf(__('A rendelés állapota: %s.', 'mandala'), wc_get_order_status_name($status))) . ' ' . esc_html__('Kérdésed van? Írj nekünk, segítünk.', 'mandala') . '</span></div>';
    }
    $out = '<section class="panel track-panel" aria-labelledby="track-title-' . (int) $order->get_id() . '"><h2 id="track-title-' . (int) $order->get_id() . '" style="font-size:var(--fs-h4)">' . esc_html(sprintf(__('A rendelésed útja – #%s', 'mandala'), $order->get_order_number())) . '</h2><ol class="timeline">';
    $n = 0;
    foreach (mandala_order_timeline($order) as $step) {
        $n++;
        $dot = $step['state'] === 'done' ? mandala_icon('check', 'ico ico-s') : (string) $n;
        $out .= '<li class="is-' . esc_attr($step['state']) . '"' . ($step['state'] === 'current' ? ' aria-current="step"' : '') . '><span class="dot">' . $dot . '</span><span><strong>' . esc_html($step['label']) . '</strong>'
            . '<span>' . esc_html(trim($step['date'] . ($step['date'] && $step['text'] ? ' · ' : '') . $step['text'])) . '</span></span></li>';
    }
    $out .= '</ol>';
    foreach (mandala_order_tracking($order) as $t) {
        $out .= '<p><a class="iu-button iu-button-outline" href="' . esc_url($t['url']) . '" target="_blank" rel="noopener">' . mandala_icon('truck', 'ico ico-s') . ' ' . esc_html(sprintf(__('Élő követés a %1$s oldalán (%2$s)', 'mandala'), $t['carrier'], $t['number'])) . '<span class="screen-reader-text"> ' . esc_html__('(új lapon nyílik)', 'mandala') . '</span></a></p>';
    }
    if ($full) {
        $out .= '<h3 style="font-size:var(--fs-body);margin-top:var(--space-5)">' . esc_html__('A csomagban', 'mandala') . '</h3><ul class="track-items">';
        foreach ($order->get_items() as $item) {
            $out .= '<li>' . esc_html($item->get_name()) . ' <span class="text-muted">× ' . (int) $item->get_quantity() . '</span></li>';
        }
        $method = $order->get_shipping_method();
        $out .= '</ul>' . ($method ? '<p class="text-muted text-small">' . esc_html(sprintf(__('Szállítás: %s', 'mandala'), $method)) . '</p>' : '')
            . '<p class="text-small">' . wp_kses_post(sprintf(__('Kérdésed van a rendelésről? <a href="%s">Írj nekünk</a> – a rendelésszámmal gyorsabban segítünk.', 'mandala'), esc_url(mandala_url(['page' => 'kapcsolat'])))) . '</p>';
    }
    return $out . '</section>';
}

/* ---------- Követőoldal (mandala/order-tracking blokk) ---------- */

add_action('init', function () {
    mandala_add_block('mandala/order-tracking', [
        'title' => 'Csomagkövetés (rendelésszám + e-mail)',
        'template' => function () {
            $id = absint($_GET['rendeles'] ?? 0);
            $key = sanitize_text_field(wp_unslash($_GET['k'] ?? ''));
            $order = $id ? wc_get_order($id) : null;
            if ($order instanceof WC_Order && !($order instanceof WC_Order_Refund)) {
                $mine = is_user_logged_in() && (int) $order->get_customer_id() === get_current_user_id();
                if ($mine || ($key && hash_equals(mandala_token('track', (string) $order->get_id(), strtolower($order->get_billing_email())), $key))) {
                    return mandala_tracking_html($order) . '<p class="track-more"><a href="' . esc_url(get_permalink()) . '">' . esc_html__('Másik rendelés keresése', 'mandala') . '</a></p>';
                }
            }
            $error = sanitize_key($_GET['hiba'] ?? '');
            $msg = ['nincs' => __('Ezzel a rendelésszámmal és e-mail-címmel nem találtunk rendelést. Nézd meg a visszaigazoló levelet – ott van mindkettő.', 'mandala'), 'sok' => __('Túl sok próbálkozás. Kérlek, próbáld újra egy óra múlva, vagy írj nekünk.', 'mandala')][$error] ?? '';
            ob_start(); ?>
<form class="track-form panel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-validate-form novalidate>
  <input type="hidden" name="action" value="mandala_track">
  <?php wp_nonce_field('mandala_track'); ?>
  <?php if ($msg) : ?><p class="form-message is-error" role="alert"><?php echo mandala_icon('alert'); // phpcs:ignore ?><span><?php echo esc_html($msg); ?></span></p><?php endif; ?>
  <div class="form-grid">
    <div class="iu-form-field"><label for="track-order"><?php esc_html_e('Rendelésszám', 'mandala'); ?></label><input type="text" id="track-order" name="order" inputmode="numeric" autocomplete="off" placeholder="1234" data-validate="<?php echo esc_attr('required|' . __('Add meg a rendelésszámot.', 'mandala')); ?>"></div>
    <div class="iu-form-field"><label for="track-email"><?php esc_html_e('E-mail-cím', 'mandala'); ?></label><input type="email" id="track-email" name="email" autocomplete="email" placeholder="nev@pelda.hu" data-validate="<?php echo esc_attr('required|' . __('Add meg az e-mail-címed.', 'mandala') . "\nemail|" . __('Ez nem tűnik érvényes e-mail-címnek.', 'mandala')); ?>"></div>
  </div>
  <p class="field-hint"><?php esc_html_e('Mindkettő a visszaigazoló levélben van. Ha van fiókod, a Fiókom oldalon is látod a rendeléseidet.', 'mandala'); ?></p>
  <div><button type="submit" class="iu-button"><?php echo mandala_icon('truck', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Hol a csomagom?', 'mandala'); ?></button></div>
</form>
            <?php
            return (string) ob_get_clean();
        },
    ]);
});

/** Keresés rendelésszám + e-mail alapján → az aláírt követő linkre irányít. IP-nként óránként 10 próba. */
function mandala_track_lookup(): void
{
    $page = (int) get_option('mandala_page_csomagkovetes');
    $back = $page ? (string) get_permalink($page) : home_url('/');
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'mandala_track')) {
        wp_safe_redirect(add_query_arg('hiba', 'nincs', $back));
        exit;
    }
    $ip = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce'));
    $tries = (int) get_transient('mandala_track_' . $ip);
    if ($tries >= 10) {
        wp_safe_redirect(add_query_arg('hiba', 'sok', $back));
        exit;
    }
    set_transient('mandala_track_' . $ip, $tries + 1, HOUR_IN_SECONDS);
    $number = preg_replace('/[^0-9A-Za-z-]/', '', (string) wp_unslash($_POST['order'] ?? ''));
    $email = strtolower(sanitize_email(wp_unslash($_POST['email'] ?? '')));
    $order = null;
    if ($number !== '' && $email) {
        // A rendelésszám bővítménnyel eltérhet az azonosítótól (pl. sorszámozás): mindkettőt nézzük.
        $candidates = ctype_digit($number) ? [wc_get_order((int) $number)] : [];
        $ids = wc_get_orders(['billing_email' => $email, 'limit' => 50, 'return' => 'ids', 'type' => 'shop_order']);
        foreach ($ids as $oid) {
            $candidates[] = wc_get_order($oid);
        }
        foreach ($candidates as $o) {
            if ($o instanceof WC_Order && !($o instanceof WC_Order_Refund) && strtolower($o->get_billing_email()) === $email && (string) $o->get_order_number() === $number) {
                $order = $o;
                break;
            }
        }
    }
    wp_safe_redirect($order ? mandala_tracking_url($order) : add_query_arg('hiba', 'nincs', $back));
    exit;
}
add_action('admin_post_nopriv_mandala_track', 'mandala_track_lookup');
add_action('admin_post_mandala_track', 'mandala_track_lookup');

add_filter('wp_robots', function ($robots) {
    if (is_page((int) get_option('mandala_page_csomagkovetes'))) {
        $robots['noindex'] = true;
    }
    return $robots;
});

/* ---------- Fiókom, köszönőoldal, WooCommerce levelek ---------- */

add_action('woocommerce_view_order', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        echo mandala_tracking_html($order, false); // phpcs:ignore
    }
}, 5);

/** Követő doboz a WooCommerce vásárlói leveleiben (visszaigazolás, teljesítés, számla…). */
add_action('woocommerce_email_before_order_table', function ($order, $sent_to_admin, $plain_text = false) {
    if ($sent_to_admin || !$order instanceof WC_Order || $order instanceof WC_Order_Refund || in_array($order->get_status(), ['cancelled', 'refunded', 'failed'], true)) {
        return;
    }
    $tracking = mandala_order_tracking($order);
    $url = mandala_tracking_url($order);
    if ($plain_text) {
        echo "\n" . esc_html__('Rendelésed követése:', 'mandala') . ' ' . esc_url_raw($url) . "\n";
        foreach ($tracking as $t) {
            echo esc_html(sprintf(__('%1$s csomagszám: %2$s', 'mandala'), $t['carrier'], $t['number'])) . ' – ' . esc_url_raw($t['url']) . "\n";
        }
        echo "\n";
        return;
    }
    echo '<table role="presentation" style="width:100%;border-collapse:collapse;background:#F6F1E8;border-radius:12px;margin:0 0 24px"><tr><td style="padding:16px 20px">'
        . '<p style="margin:0 0 4px;font-weight:600;color:#1C1916">' . esc_html($tracking ? __('A csomagod úton van', 'mandala') : __('Kövesd a rendelésed', 'mandala')) . '</p>';
    foreach ($tracking as $t) {
        echo '<p style="margin:0 0 4px;color:#6E6357">' . esc_html(sprintf(__('%1$s csomagszám: %2$s', 'mandala'), $t['carrier'], $t['number'])) . '</p>';
    }
    echo '<p style="margin:8px 0 0"><a href="' . esc_url($url) . '" style="color:#8A4512;font-weight:600">' . esc_html__('Hol tart a rendelésem? →', 'mandala') . '</a></p></td></tr></table>';
}, 5, 3);

/* ---------- Feladás felismerése és „Feladtuk” levél ---------- */

function mandala_maybe_mark_shipped(WC_Order $order): void
{
    static $busy = false;
    if ($busy || $order instanceof WC_Order_Refund || $order->get_meta('_mandala_shipped') || !in_array($order->get_status(), ['processing', 'completed', 'on-hold'], true)) {
        return;
    }
    $tracking = mandala_order_tracking($order);
    if (!$tracking || mandala_order_shipping_kind($order) === 'pickup') {
        return;
    }
    $busy = true;
    $order->update_meta_data('_mandala_shipped', time());
    $order->save_meta_data();
    $order->add_order_note(sprintf('Feladva – %s csomagszám: %s. A vásárló értesítése ütemezve.', $tracking[0]['carrier'], implode(', ', array_column($tracking, 'number'))));
    $busy = false;
    // Kis késleltetés: a szállítási bővítmény még befejezheti a mentést (több csomagszám, címke).
    mandala_schedule(2 * MINUTE_IN_SECONDS, 'mandala_mail_shipped', [(int) $order->get_id()]);
    do_action('mandala_order_shipped', $order);
}
add_action('woocommerce_after_order_object_save', function ($order) {
    if ($order instanceof WC_Order) {
        mandala_maybe_mark_shipped($order);
    }
});
/** Régi (nem HPOS) tárolás: a bővítmény közvetlenül a post metába írja a csomagszámot. */
$mandala_tracking_meta_hook = function ($meta_id, $post_id, $key) {
    if (in_array($key, array_map('trim', preg_split('/\R/', (string) mandala_tracking_settings()['meta_keys'])), true) && in_array(get_post_type($post_id), ['shop_order', 'shop_order_placehold'], true)) {
        $order = wc_get_order($post_id);
        if ($order) {
            mandala_maybe_mark_shipped($order);
        }
    }
};
add_action('added_post_meta', $mandala_tracking_meta_hook, 10, 3);
add_action('updated_post_meta', $mandala_tracking_meta_hook, 10, 3);

/** Óránkénti ellenőrzés: ha a csomagszám a hookok megkerülésével került be (pl. közvetlen adatbázis-írás). */
add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_tracking_sweep', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'mandala_tracking_sweep', [], MANDALA_AS_GROUP);
    }
}, 30);
add_action('mandala_tracking_sweep', function () {
    foreach (wc_get_orders(['status' => ['processing', 'completed'], 'date_modified' => '>' . (time() - 3 * DAY_IN_SECONDS), 'limit' => 300, 'type' => 'shop_order']) as $order) {
        mandala_maybe_mark_shipped($order);
    }
});

add_action('mandala_mail_shipped', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_meta('_mandala_shipped_mail') || !($tracking = mandala_order_tracking($order))) {
        return;
    }
    do_action('mandala_before_order_mail', $order);
    $links = '';
    foreach ($tracking as $t) {
        $links .= '<p style="margin:0 0 4px;color:#6E6357">' . esc_html(sprintf(__('%1$s csomagszám: %2$s', 'mandala'), $t['carrier'], $t['number'])) . ' · <a href="' . esc_url($t['url']) . '" style="color:#8A4512">' . esc_html__('élő követés', 'mandala') . '</a></p>';
    }
    $kind = mandala_order_shipping_kind($order);
    $sent = mandala_mail('shipped', $order->get_billing_email(), mandala_mail_order_vars($order) + [
        'csomagszam' => implode(', ', array_column($tracking, 'number')),
        'atvetel' => $kind === 'point' ? __('A GLS pontra érkezésről a GLS SMS-ben és e-mailben értesít; az átvételhez a kódot tőlük kapod.', 'mandala') : __('A GLS futár a kézbesítés napján SMS-ben jelzi, mikorra érkezik.', 'mandala'),
    ], ['kovetes' => $links . mandala_mail_button(mandala_tracking_url($order), __('Hol tart a csomagom?', 'mandala')), 'termekek' => mandala_tracking_items_html($order)], $order->get_id());
    if ($sent) {
        $order->update_meta_data('_mandala_shipped_mail', time());
        $order->save_meta_data();
    }
});

function mandala_tracking_items_html(WC_Order $order): string
{
    $rows = '';
    foreach ($order->get_items() as $item) {
        $p = $item->get_product();
        if ($p && !$p->get_meta('_mandala_ticket_for') && !(function_exists('mandala_is_voucher') && mandala_is_voucher($p))) {
            $rows .= mandala_mail_product_row($p, esc_html((int) $item->get_quantity() . ' db'));
        }
    }
    return $rows;
}

/* ---------- Személyes átvétel: „Átvehető” értesítés (rendelés művelet) ---------- */

add_filter('woocommerce_order_actions', function ($actions, $order = null) {
    if ($order instanceof WC_Order && mandala_order_shipping_kind($order) === 'pickup') {
        $actions['mandala_pickup_ready'] = $order->get_meta('_mandala_pickup_ready') ? 'Átvehető – értesítés újraküldése' : 'Átvehető a bemutatóteremben – értesítés a vásárlónak';
    }
    return $actions;
}, 10, 2);
add_action('woocommerce_order_action_mandala_pickup_ready', 'mandala_pickup_ready');

function mandala_pickup_ready(WC_Order $order): void
{
    $order->update_meta_data('_mandala_pickup_ready', time());
    $order->save_meta_data();
    do_action('mandala_before_order_mail', $order);
    $c = (array) mandala_config('contact', []);
    $sent = mandala_mail('pickup_ready', $order->get_billing_email(), mandala_mail_order_vars($order) + ['cim' => (string) ($c['address'] ?? ''), 'fizetendo' => $order->get_payment_method() === 'cod' ? wp_strip_all_tags(wc_price($order->get_total())) : __('nincs – már kifizetted', 'mandala')],
        ['termekek' => mandala_tracking_items_html($order), 'gomb' => mandala_mail_button(mandala_tracking_url($order), __('A rendelésem', 'mandala'))], $order->get_id());
    $order->add_order_note($sent ? 'Átvehető – a vásárlót e-mailben értesítettük.' : 'Átvehető – a levél ki van kapcsolva vagy nem ment el (Mandala levelek).');
    do_action('mandala_order_pickup_ready', $order);
}

/* ---------- Levélsablonok (levélközpont) ---------- */

add_filter('mandala_mail_types', function ($types) {
    $hello = '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p>';
    $sample_order = ['keresztnev' => 'Anna', 'rendeles' => '1234'];
    $types['shipped'] = [
        'label' => 'Csomag feladva', 'group' => 'Szállítás',
        'when' => 'Amint a rendelésnél megjelenik a GLS csomagszám (a GLS bővítményből vagy kézzel). Egyszer; személyes átvételnél nem. Ha a GLS bővítmény is küld ilyet, az egyiket kapcsold ki.',
        'vars' => ['keresztnev' => 'A vásárló keresztneve', 'rendeles' => 'Rendelésszám', 'csomagszam' => 'GLS csomagszám(ok)', 'atvetel' => 'Tudnivaló az átvételről (futár / GLS pont szerint)'],
        'blocks' => ['kovetes' => 'Csomagszám élő követés linkkel + „Hol tart a csomagom?” gomb', 'termekek' => 'A csomag tartalma'],
        'subject' => __('Úton van a csomagod (#{rendeles})', 'mandala'), 'heading' => __('Feladtuk a csomagod', 'mandala'),
        'body' => $hello . '<p>' . __('Jó hír: a rendelésed becsomagoltuk és átadtuk a GLS-nek. {atvetel}', 'mandala') . '</p>{kovetes}{termekek}<p>' . __('Kérdésed van? Válaszolj erre a levélre.', 'mandala') . '</p>',
        'sample' => fn() => [$sample_order + ['csomagszam' => '12345678901', 'atvetel' => __('A GLS futár a kézbesítés napján SMS-ben jelzi, mikorra érkezik.', 'mandala')],
            ['kovetes' => '<p style="margin:0 0 4px;color:#6E6357">GLS csomagszám: 12345678901 · <a href="#" style="color:#8A4512">élő követés</a></p>' . mandala_mail_button(home_url('/'), __('Hol tart a csomagom?', 'mandala')), 'termekek' => mandala_mail_sample_rows(2, false)]],
    ];
    $types['pickup_ready'] = [
        'label' => 'Átvehető a bemutatóteremben', 'group' => 'Szállítás',
        'when' => 'Személyes átvételnél, amikor a rendelés oldalán a „Rendelés műveletek” közül az „Átvehető” lehetőséget választod.',
        'vars' => ['keresztnev' => 'A vásárló keresztneve', 'rendeles' => 'Rendelésszám', 'cim' => 'A bemutatóterem címe', 'fizetendo' => 'Átvételkor fizetendő összeg (utánvétnél)'],
        'blocks' => ['termekek' => 'A rendelés tartalma', 'gomb' => '„A rendelésem” gomb (követőoldal)'],
        'subject' => __('Átvehető a rendelésed (#{rendeles})', 'mandala'), 'heading' => __('Várunk a bemutatóteremben', 'mandala'),
        'body' => $hello . '<p>' . __('A rendelésed összekészítettük, átveheted a bemutatótermünkben.', 'mandala') . '</p><p><strong>' . __('Cím:', 'mandala') . '</strong> {cim}<br><strong>' . __('Nyitvatartás:', 'mandala') . '</strong> {nyitvatartas}<br><strong>' . __('Fizetendő átvételkor:', 'mandala') . '</strong> {fizetendo}</p>{termekek}{gomb}<p>' . __('Elég a nevedet vagy a rendelésszámot mondanod. Ha más venné át, válaszolj erre a levélre a nevével.', 'mandala') . '</p>',
        'sample' => fn() => [$sample_order + ['cim' => (string) (mandala_config('contact', [])['address'] ?? ''), 'fizetendo' => '12 900 Ft'], ['termekek' => mandala_mail_sample_rows(1, false), 'gomb' => mandala_mail_button(home_url('/'), __('A rendelésem', 'mandala'))]],
    ];
    return $types;
});

/* ---------- Admin: csomagszám és levelek a rendelés oldalán ---------- */

add_action('add_meta_boxes', function () {
    $screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'shop_order';
    add_meta_box('mandala-tracking', 'Csomagkövetés és levelek', 'mandala_tracking_metabox', $screen, 'side', 'default');
});

function mandala_tracking_metabox($post_or_order): void
{
    global $wpdb;
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
    if (!$order) {
        return;
    }
    $manual = (string) $order->get_meta('_mandala_tracking');
    $tracking = mandala_order_tracking($order);
    echo '<p><label for="mandala-tracking-input"><strong>GLS csomagszám</strong></label><br><input type="text" id="mandala-tracking-input" name="mandala_tracking" value="' . esc_attr($manual) . '" class="widefat" placeholder="' . esc_attr($tracking && !$manual ? 'a bővítményből: ' . $tracking[0]['number'] : 'pl. 12345678901') . '"></p>'
        . '<p class="description">Ha a GLS bővítmény nem tölti ki magától. Több csomag: vesszővel. Mentéskor a vásárló „Feladtuk” levelet kap (egyszer).</p>';
    foreach ($tracking as $t) {
        echo '<p><a href="' . esc_url($t['url']) . '" target="_blank" rel="noopener">' . esc_html($t['carrier'] . ' ' . $t['number']) . ' ↗</a></p>';
    }
    if ($shipped = (int) $order->get_meta('_mandala_shipped')) {
        echo '<p>Feladva: ' . esc_html(wp_date('Y. m. d. H:i', $shipped)) . ((int) $order->get_meta('_mandala_shipped_mail') ? ' · levél elküldve' : ' · levél ütemezve / kikapcsolva') . '</p>';
    }
    if ($ready = (int) $order->get_meta('_mandala_pickup_ready')) {
        echo '<p>Átvehetőnek jelölve: ' . esc_html(wp_date('Y. m. d. H:i', $ready)) . '</p>';
    }
    echo '<p><a href="' . esc_url(mandala_tracking_url($order)) . '" target="_blank" rel="noopener">A vásárló követőoldala ↗</a></p>';
    if (get_option('mandala_mail_db') === MANDALA_MAIL_DB_VERSION) {
        $rows = $wpdb->get_results($wpdb->prepare('SELECT created, type, subject, status FROM ' . mandala_mail_table() . ' WHERE order_id = %d ORDER BY id DESC LIMIT 10', $order->get_id()));
        if ($rows) {
            $types = mandala_mail_types();
            echo '<p><strong>Mandala levelek</strong></p><ul style="margin:0">';
            foreach ($rows as $r) {
                echo '<li>' . esc_html(get_date_from_gmt($r->created, 'm. d. H:i') . ' – ' . ($types[$r->type]['label'] ?? $r->subject)) . ($r->status !== 'sent' ? ' <em>(' . esc_html($r->status) . ')</em>' : '') . '</li>';
            }
            echo '</ul><p><a href="' . esc_url(admin_url('admin.php?page=mandala-automations&tab=naplo&q=' . $order->get_id())) . '">Napló →</a></p>';
        }
    }
}

add_action('woocommerce_process_shop_order_meta', function ($order_id) {
    if (!isset($_POST['mandala_tracking'])) { // phpcs:ignore -- a WooCommerce ellenőrzi a nonce-t
        return;
    }
    $order = wc_get_order($order_id);
    $value = trim(preg_replace('/[^A-Za-z0-9,\s-]/', '', (string) wp_unslash($_POST['mandala_tracking']))); // phpcs:ignore
    if ($order && $value !== (string) $order->get_meta('_mandala_tracking')) {
        $order->update_meta_data('_mandala_tracking', $value);
        $order->save();
    }
}, 50);

/* ---------- Beállítások (Mandala levelek → Beállítások) ---------- */

add_action('mandala_mail_settings_fields', function () {
    $s = mandala_tracking_settings();
    echo '<tr><th scope="row"><label for="mt-keys">Csomagszám mezők</label></th><td><textarea id="mt-keys" name="mandala_tracking[meta_keys]" rows="5" class="regular-text code">' . esc_textarea($s['meta_keys']) . '</textarea><p class="description">A GLS (vagy más szállítási) bővítmény rendelés-mezői, ahová a csomagszámot írja – soronként egy. Ha a bővítmény másikat használ, ide írd a nevét (a rendelés „Egyéni mezők” dobozában látszik).</p></td></tr>'
        . '<tr><th scope="row"><label for="mt-url">Követő link</label></th><td><input type="url" id="mt-url" name="mandala_tracking[url]" value="' . esc_attr($s['url']) . '" class="large-text code"><p class="description"><code>{szam}</code> helyére kerül a csomagszám.</p></td></tr>';
});
add_action('mandala_mail_settings_save', function () {
    $in = (array) wp_unslash($_POST['mandala_tracking'] ?? []);
    if ($in) {
        update_option('mandala_tracking', [
            'meta_keys' => implode("\n", array_filter(array_map(fn($k) => preg_replace('/[^A-Za-z0-9_-]/', '', $k), preg_split('/\R/', (string) ($in['meta_keys'] ?? ''))))),
            // Az esc_url_raw kiszedné a kapcsos zárójeleket: a helyőrző mentés idejére jelölőt kap.
            'url' => str_replace('MANDALASZAM', '{szam}', esc_url_raw(str_replace('{szam}', 'MANDALASZAM', (string) ($in['url'] ?? '')))) ?: mandala_tracking_settings()['url'],
            'carrier' => 'GLS',
        ], false);
    }
});
