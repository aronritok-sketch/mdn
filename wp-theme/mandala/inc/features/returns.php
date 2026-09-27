<?php
/**
 * Online visszaküldés – a vásárló maga indítja, levélváltás nélkül.
 *
 *  - A követőoldalon (aláírt link / rendelésszám + e-mail) és a Fiókom → rendelés oldalon a teljesített
 *    rendelésnél „Visszaküldés indítása”: tételek és mennyiség, ok, kérés (visszatérítés / csere /
 *    utalvány), utánvétes rendelésnél bankszámlaszám a visszautaláshoz.
 *  - A vásárló azonnal visszaigazolást kap a teendőkkel (hova küldje, mit tegyen a csomagba); a bolt
 *    belső értesítést; a rendeléshez megjegyzés kerül. Az elállási határidő (alapból 14 nap) után a
 *    gomb nem jelenik meg.
 *  - WooCommerce → Visszaküldések: a kérések listája, „Megérkezett” / „Lezárva” jelöléssel (a
 *    visszatérítést a WooCommerce szokásos módján, a rendelésnél kell elindítani).
 */

defined('ABSPATH') || exit;

const MANDALA_RETURN_REASONS = [
    'meggondoltam' => 'Meggondoltam magam',
    'nem-olyan' => 'Nem olyan, mint vártam',
    'serult' => 'Sérülten érkezett',
    'mas' => 'Mást kaptam, mint amit rendeltem',
    'egyeb' => 'Egyéb',
];

function mandala_return_days(): int
{
    return (int) apply_filters('mandala_return_days', 14);
}

/** Indítható-e még visszaküldés (teljesített, határidőn belül, van fizikai tétel). */
function mandala_return_open(WC_Order $order): bool
{
    if ($order->get_status() !== 'completed' || !$order->get_date_completed()) {
        return false;
    }
    $since = (time() - $order->get_date_completed()->getTimestamp()) / DAY_IN_SECONDS;
    return $since <= mandala_return_days() + 3 && (!function_exists('mandala_order_needs_shipping') || mandala_order_needs_shipping($order));
}

function mandala_returns(WC_Order $order): array
{
    $v = $order->get_meta('_mandala_returns');
    return is_array($v) ? $v : [];
}

/** A visszaküldő űrlap (a követőoldal panelje alá). */
function mandala_return_form(WC_Order $order, string $key): string
{
    if (!mandala_return_open($order)) {
        return '';
    }
    $done = array_filter(mandala_returns($order), fn($r) => ($r['status'] ?? '') !== 'closed');
    $msg = sanitize_key($_GET['visszakuldes'] ?? '');
    if ($msg === 'ok' || $done) {
        return '<div class="woocommerce-message" role="status">' . mandala_icon('check') . '<span>' . esc_html__('A visszaküldési kérésed megkaptuk – a teendőket e-mailben elküldtük. Ha kérdésed van, válaszolj arra a levélre.', 'mandala') . '</span></div>';
    }
    $days_left = max(0, mandala_return_days() - (int) floor((time() - $order->get_date_completed()->getTimestamp()) / DAY_IN_SECONDS));
    $rows = '';
    foreach ($order->get_items() as $item_id => $item) {
        $qty = (int) $item->get_quantity();
        $rows .= '<tr><td><label><input type="checkbox" name="items[' . (int) $item_id . ']" value="1"> ' . esc_html($item->get_name()) . '</label></td><td>'
            . ($qty > 1 ? '<label class="screen-reader-text" for="rq-' . (int) $item_id . '">' . esc_html__('Mennyiség', 'mandala') . '</label><select id="rq-' . (int) $item_id . '" name="qty[' . (int) $item_id . ']">' . implode('', array_map(fn($n) => '<option value="' . $n . '"' . selected($n, $qty, false) . '>' . $n . ' db</option>', range(1, $qty))) . '</select>' : '1 db') . '</td></tr>';
    }
    $cod = $order->get_payment_method() === 'cod';
    ob_start(); ?>
<details class="panel return-panel"<?php echo $msg === 'hiba' ? ' open' : ''; ?>>
  <summary><strong><?php esc_html_e('Visszaküldés indítása', 'mandala'); ?></strong> <span class="text-muted text-small"><?php echo esc_html(sprintf(__('még %d napig', 'mandala'), $days_left)); ?></span></summary>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="return-form">
    <input type="hidden" name="action" value="mandala_return"><input type="hidden" name="rendeles" value="<?php echo (int) $order->get_id(); ?>"><input type="hidden" name="k" value="<?php echo esc_attr($key); ?>">
    <?php wp_nonce_field('mandala_return_' . $order->get_id()); ?>
    <?php if ($msg === 'hiba') : ?><p class="form-message is-error" role="alert"><?php echo mandala_icon('alert'); // phpcs:ignore ?><span><?php esc_html_e('Jelölj be legalább egy tételt, és válaszd ki az okot.', 'mandala'); ?></span></p><?php endif; ?>
    <fieldset><legend><?php esc_html_e('Mit küldesz vissza?', 'mandala'); ?></legend><table class="return-items"><tbody><?php echo $rows; // phpcs:ignore ?></tbody></table></fieldset>
    <div class="form-grid">
      <div class="iu-form-field"><label for="return-reason"><?php esc_html_e('Az ok', 'mandala'); ?></label><select id="return-reason" name="reason"><option value=""><?php esc_html_e('Válassz…', 'mandala'); ?></option><?php foreach (MANDALA_RETURN_REASONS as $k => $l) : ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html__($l, 'mandala'); // phpcs:ignore ?></option><?php endforeach; ?></select></div>
      <div class="iu-form-field"><label for="return-want"><?php esc_html_e('Mit szeretnél?', 'mandala'); ?></label><select id="return-want" name="want"><option value="refund"><?php esc_html_e('Visszatérítést', 'mandala'); ?></option><option value="exchange"><?php esc_html_e('Cserét', 'mandala'); ?></option><option value="voucher"><?php esc_html_e('Vásárlási utalványt', 'mandala'); ?></option></select></div>
    </div>
    <?php if ($cod) : ?><div class="iu-form-field"><label for="return-iban"><?php esc_html_e('Bankszámlaszám a visszautaláshoz', 'mandala'); ?></label><input type="text" id="return-iban" name="iban" autocomplete="off" placeholder="12345678-12345678-12345678"></div><?php endif; ?>
    <div class="iu-form-field"><label for="return-note"><?php esc_html_e('Megjegyzés (nem kötelező)', 'mandala'); ?></label><textarea id="return-note" name="note" rows="3" maxlength="1000"></textarea></div>
    <p class="field-hint"><?php esc_html_e('Sérült terméknél csatolj fotót a visszaigazoló levélre válaszolva. A visszaküldés költsége – ha nem mi hibáztunk – a vásárlót terheli.', 'mandala'); ?></p>
    <div><button type="submit" class="iu-button"><?php esc_html_e('Visszaküldés indítása', 'mandala'); ?></button></div>
  </form>
</details>
    <?php
    return (string) ob_get_clean();
}

/** A követőoldal és a Fiókom rendelés panelje után. */
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') !== 'mandala/order-tracking') {
        return $html;
    }
    $id = absint($_GET['rendeles'] ?? 0);
    $key = sanitize_text_field(wp_unslash($_GET['k'] ?? ''));
    $order = $id ? wc_get_order($id) : null;
    if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !hash_equals(mandala_token('track', (string) $order->get_id(), strtolower($order->get_billing_email())), $key)) {
        return $html;
    }
    return str_replace('<p class="track-more">', mandala_return_form($order, $key) . '<p class="track-more">', $html);
}, 20, 2);
add_action('woocommerce_view_order', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order) {
        echo mandala_return_form($order, mandala_token('track', (string) $order->get_id(), strtolower($order->get_billing_email()))); // phpcs:ignore
    }
}, 6);

/** Beküldés. */
function mandala_return_submit(): void
{
    $id = absint($_POST['rendeles'] ?? 0);
    $key = sanitize_text_field(wp_unslash($_POST['k'] ?? ''));
    $order = $id ? wc_get_order($id) : null;
    if (!$order instanceof WC_Order || !hash_equals(mandala_token('track', (string) $order->get_id(), strtolower($order->get_billing_email())), $key) || !wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), 'mandala_return_' . $id)) {
        wp_die(esc_html__('A link lejárt vagy hibás.', 'mandala'), '', ['response' => 403]);
    }
    $back = function_exists('mandala_tracking_url') ? mandala_tracking_url($order) : $order->get_view_order_url();
    $items = [];
    foreach ((array) ($_POST['items'] ?? []) as $item_id => $on) {
        $item = $order->get_item((int) $item_id);
        if ($item) {
            $items[] = ['name' => $item->get_name(), 'qty' => max(1, min((int) $item->get_quantity(), (int) ($_POST['qty'][$item_id] ?? $item->get_quantity())))];
        }
    }
    $reason = sanitize_key($_POST['reason'] ?? '');
    if (!$items || !isset(MANDALA_RETURN_REASONS[$reason]) || !mandala_return_open($order)) {
        wp_safe_redirect(add_query_arg('visszakuldes', 'hiba', $back));
        exit;
    }
    $want = ['refund' => 'visszatérítés', 'exchange' => 'csere', 'voucher' => 'vásárlási utalvány'][sanitize_key($_POST['want'] ?? 'refund')] ?? 'visszatérítés';
    $req = ['time' => time(), 'items' => $items, 'reason' => MANDALA_RETURN_REASONS[$reason], 'want' => $want,
        'iban' => preg_replace('/[^0-9A-Za-z -]/', '', (string) wp_unslash($_POST['iban'] ?? '')), 'note' => sanitize_textarea_field(wp_unslash($_POST['note'] ?? '')), 'status' => 'requested'];
    $all = mandala_returns($order);
    $all[] = $req;
    $order->update_meta_data('_mandala_returns', $all);
    $order->save_meta_data();
    $list = implode(', ', array_map(fn($i) => $i['name'] . ' × ' . $i['qty'], $items));
    $order->add_order_note('Visszaküldési kérés (vásárló): ' . $list . ' · ok: ' . $req['reason'] . ' · kérés: ' . $want . ($req['iban'] ? ' · számlaszám: ' . $req['iban'] : '') . ($req['note'] ? ' · „' . $req['note'] . '”' : ''));
    $c = (array) mandala_config('contact', []);
    $rows = '<ul>' . implode('', array_map(fn($i) => '<li>' . esc_html($i['name'] . ' × ' . $i['qty']) . '</li>', $items)) . '</ul>';
    mandala_mail('return_received', $order->get_billing_email(), mandala_mail_order_vars($order) + ['cim' => (string) ($c['address'] ?? ''), 'keres' => $want, 'hatarido' => wp_date('Y. m. d.', time() + 14 * DAY_IN_SECONDS)], ['tetelek' => $rows], $order->get_id());
    mandala_send_mail((string) ($c['email'] ?? get_option('admin_email')), sprintf('[%s] Visszaküldési kérés: #%s', get_bloginfo('name'), $order->get_order_number()), 'Visszaküldési kérés',
        '<p>' . esc_html(sprintf('#%s – %s %s (%s)', $order->get_order_number(), $order->get_billing_last_name(), $order->get_billing_first_name(), $order->get_billing_email())) . '</p>' . $rows
        . '<p>Ok: ' . esc_html($req['reason']) . '<br>Kérés: ' . esc_html($want) . ($req['iban'] ? '<br>Számlaszám: ' . esc_html($req['iban']) : '') . ($req['note'] ? '<br>Megjegyzés: ' . esc_html($req['note']) : '') . '</p>'
        . mandala_mail_button($order->get_edit_order_url(), 'Rendelés megnyitása'), false, ['type' => 'belso', 'order' => $order->get_id()]);
    wp_safe_redirect(add_query_arg('visszakuldes', 'ok', $back));
    exit;
}
add_action('admin_post_nopriv_mandala_return', 'mandala_return_submit');
add_action('admin_post_mandala_return', 'mandala_return_submit');

/* ---------- Levélsablon ---------- */

add_filter('mandala_mail_types', function ($types) {
    $types['return_received'] = [
        'label' => 'Visszaküldés – visszaigazolás', 'group' => 'Szállítás', 'required' => true,
        'when' => 'Amikor a vásárló a követőoldalon vagy a fiókjában elindítja a visszaküldést. Mindig megy – ebben vannak a teendők.',
        'vars' => ['keresztnev' => 'A vásárló keresztneve', 'rendeles' => 'Rendelésszám', 'cim' => 'A bemutatóterem címe', 'keres' => 'Mit kér (visszatérítés / csere / utalvány)', 'hatarido' => 'Eddig küldje vissza'],
        'blocks' => ['tetelek' => 'A visszaküldött tételek listája'],
        'subject' => __('Megkaptuk a visszaküldési kérésed (#{rendeles})', 'mandala'), 'heading' => __('Visszaküldés', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('Megkaptuk a kérésed ({keres}) ezekre a tételekre:', 'mandala') . '</p>{tetelek}<p><strong>' . __('Teendőid:', 'mandala') . '</strong></p><ol><li>' . __('Csomagold be a termékeket gondosan (ha van, az eredeti csomagolásban).', 'mandala') . '</li><li>' . __('Tegyél a csomagba egy cetlit a rendelésszámmal: #{rendeles}.', 'mandala') . '</li><li>' . __('Küldd el {hatarido}-ig erre a címre, vagy hozd be a bemutatótermünkbe: {cim}.', 'mandala') . '</li></ol><p>' . __('Amint megérkezik, 14 napon belül intézzük. Sérült terméknél válaszolj erre a levélre egy fotóval.', 'mandala') . '</p>',
        'sample' => fn() => [['keresztnev' => 'Anna', 'rendeles' => '1234', 'cim' => (string) (mandala_config('contact', [])['address'] ?? ''), 'keres' => 'visszatérítés', 'hatarido' => wp_date('Y. m. d.', time() + 14 * DAY_IN_SECONDS)], ['tetelek' => '<ul><li>Szantál kúpfüstölő × 1</li></ul>']],
    ];
    return $types;
});

/* ---------- Admin: WooCommerce → Visszaküldések ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Visszaküldések', 'Visszaküldések', 'manage_woocommerce', 'mandala-returns', function () {
        if (!empty($_POST['ret_order']) && check_admin_referer('mandala_returns')) {
            $order = wc_get_order(absint($_POST['ret_order']));
            $i = (int) ($_POST['ret_index'] ?? -1);
            $status = in_array($_POST['ret_status'] ?? '', ['received', 'closed'], true) ? $_POST['ret_status'] : '';
            if ($order && $status) {
                $all = mandala_returns($order);
                if (isset($all[$i])) {
                    $all[$i]['status'] = $status;
                    $order->update_meta_data('_mandala_returns', $all);
                    $order->save_meta_data();
                    $order->add_order_note('Visszaküldés: ' . ($status === 'received' ? 'megérkezett' : 'lezárva') . '.');
                }
            }
        }
        $orders = wc_get_orders(['limit' => 100, 'orderby' => 'date', 'order' => 'DESC', 'meta_key' => '_mandala_returns', 'meta_compare' => 'EXISTS', 'type' => 'shop_order']);
        $labels = ['requested' => '<span style="color:#b26200">kérés</span>', 'received' => '<span style="color:#2271b1">megérkezett</span>', 'closed' => '<span style="color:#008a20">lezárva</span>'];
        echo '<div class="wrap"><h1>Visszaküldések</h1><p>A vásárlók által online indított visszaküldések. A pénz visszautalását a rendelésnél, a WooCommerce „Visszatérítés” gombjával indítsd.</p><table class="widefat striped" style="max-width:1200px"><thead><tr><th>Kérés</th><th>Rendelés</th><th>Tételek</th><th>Ok / kérés</th><th>Állapot</th><th></th></tr></thead><tbody>';
        $n = 0;
        foreach ($orders as $order) {
            foreach (mandala_returns($order) as $i => $r) {
                $n++;
                echo '<tr><td>' . esc_html(wp_date('Y. m. d. H:i', (int) $r['time'])) . '</td><td><a href="' . esc_url($order->get_edit_order_url()) . '">#' . esc_html($order->get_order_number()) . '</a><br>' . esc_html($order->get_formatted_billing_full_name()) . '</td>'
                    . '<td>' . esc_html(implode(', ', array_map(fn($x) => $x['name'] . ' × ' . $x['qty'], (array) $r['items']))) . '</td><td>' . esc_html($r['reason'] . ' · ' . $r['want']) . ($r['iban'] ? '<br><code>' . esc_html($r['iban']) . '</code>' : '') . ($r['note'] ? '<br><em>' . esc_html($r['note']) . '</em>' : '') . '</td>'
                    . '<td>' . ($labels[$r['status']] ?? esc_html($r['status'])) . '</td><td><form method="post" style="display:flex;gap:4px">' . wp_nonce_field('mandala_returns', '_wpnonce', true, false) . '<input type="hidden" name="ret_order" value="' . (int) $order->get_id() . '"><input type="hidden" name="ret_index" value="' . (int) $i . '">'
                    . ($r['status'] === 'requested' ? '<button class="button" name="ret_status" value="received">Megérkezett</button>' : '') . ($r['status'] !== 'closed' ? '<button class="button" name="ret_status" value="closed">Lezárva</button>' : '') . '</form></td></tr>';
            }
        }
        if (!$n) {
            echo '<tr><td colspan="6">Még nincs visszaküldési kérés.</td></tr>';
        }
        echo '</tbody></table></div>';
    });
});
