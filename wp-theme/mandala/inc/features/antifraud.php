<?php
/**
 * Védelem a kártyatesztelő robotok ellen (lopott kártyák tömeges kipróbálása a pénztárban – a fizetési
 * szolgáltató ezért tilthat és díjat számolhat fel). Kézi munka nélkül:
 *
 *  1. Robotcsapda: a pénztárban rejtett mező (ember nem látja, a robot kitölti) és aláírt időbélyeg –
 *     űrlap nélküli, közvetlen beküldés vagy 2 mp-en belüli kitöltés nem megy át.
 *  2. Sikertelen kártyás fizetések: ugyanarról az e-mail-címről 3, ugyanarról az IP-ről 5 sikertelen után
 *     1 óráig nincs kártyás fizetés (utánvét, átutalás marad – a valódi vásárló így is rendelhet).
 *  3. Beküldési korlát: IP-nként 30 kártyás pénztár-beküldés / 10 perc. Csak a kártyás fizetést számolja:
 *     a mobilszolgáltatók sok ügyfelet egy közös IP-n engednek ki, egy kampány csúcsán se akadjon fenn senki.
 *  4. Ostrom mód: ha 1 órán belül a boltban 10+ sikertelen fizetés van, 1 órára szigorodik (1 sikertelen
 *     után tiltás, 10 beküldés / 10 perc), és azonnal levél megy (Mandala levelek → Beállítások → Őrszem).
 * A blokkolások száma az őrszemben és a Vezérlőpult dobozában látszik. Az IP-t csak kivonatolva tároljuk.
 */

defined('ABSPATH') || exit;

function mandala_fraud_settings(): array
{
    return (array) apply_filters('mandala_fraud_settings', [
        'min_seconds' => 2,          // az űrlap megnyitása és beküldése között legalább
        'fail_limit' => 3,           // sikertelen kártyás fizetés / óra / e-mail-cím
        'fail_limit_ip' => 5,        // … / IP (közös mobil IP-k miatt lazább)
        'attempts' => 30,            // kártyás pénztár-beküldés / 10 perc / IP
        'siege_failed' => 10,        // bolt szintű sikertelen fizetés / óra → ostrom mód
        'offline_gateways' => ['cod', 'bacs', 'cheque'], // ezek nem kártyás módok (nem tiltjuk)
    ]);
}

function mandala_fraud_ip(): string
{
    $ip = class_exists('WC_Geolocation') ? WC_Geolocation::get_ip_address() : (string) ($_SERVER['REMOTE_ADDR'] ?? ''); // phpcs:ignore
    return substr(hash_hmac('sha256', (string) $ip, wp_salt('nonce')), 0, 16);
}
function mandala_fraud_key(string $kind, string $value): string
{
    return 'mandala_fr_' . $kind . '_' . substr(md5(strtolower($value)), 0, 16);
}
/** Számláló növelése (az első növeléskor induló időablakkal). */
function mandala_fraud_bump(string $key, int $ttl): int
{
    $n = (int) get_transient($key) + 1;
    set_transient($key, $n, $ttl);
    return $n;
}
function mandala_fraud_siege(): bool
{
    return (bool) get_transient('mandala_fraud_siege');
}

function mandala_fraud_block(WP_Error $errors, string $reason, string $message): void
{
    $errors->add('mandala_fraud', $message);
    mandala_fraud_bump('mandala_fraud_blocked_hour', HOUR_IN_SECONDS);
    $log = array_slice((array) get_option('mandala_fraud_log', []), -49);
    $log[] = ['t' => time(), 'reason' => $reason, 'ip' => mandala_fraud_ip()];
    update_option('mandala_fraud_log', $log, false);
}

/* ---------- 1. Robotcsapda és időbélyeg a pénztár űrlapjában ---------- */

function mandala_fraud_token(int $time): string
{
    return $time . '.' . substr(hash_hmac('sha256', 'checkout|' . $time, wp_salt('nonce')), 0, 16);
}
add_action('woocommerce_checkout_before_customer_details', function () {
    echo '<div class="mandala-hp" aria-hidden="true"><label>Ezt a mezőt hagyd üresen<input type="text" name="mandala_hp" value="" tabindex="-1" autocomplete="off"></label></div>'
        . '<input type="hidden" name="mandala_ct" value="' . esc_attr(mandala_fraud_token(time())) . '">';
});

add_action('woocommerce_after_checkout_validation', function ($data, WP_Error $errors) {
    $s = mandala_fraud_settings();
    $siege = mandala_fraud_siege();
    $ip = mandala_fraud_ip();
    $generic = __('A rendelést most nem tudjuk elfogadni. Frissítsd az oldalt és próbáld újra, vagy írj nekünk.', 'mandala');

    if (!empty($_POST['mandala_hp'])) { // phpcs:ignore WordPress.Security.NonceVerification
        mandala_fraud_block($errors, 'robotcsapda', $generic);
        return;
    }
    $token = sanitize_text_field(wp_unslash($_POST['mandala_ct'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
    [$time] = explode('.', $token . '.');
    if (!$token || !hash_equals(mandala_fraud_token((int) $time), $token)) {
        mandala_fraud_block($errors, 'nincs űrlap', __('Az oldal lejárt – frissítsd, és küldd el újra a rendelést.', 'mandala'));
        return;
    }
    if (time() - (int) $time < (int) $s['min_seconds']) {
        mandala_fraud_block($errors, 'túl gyors', $generic);
        return;
    }

    // A többi csak a kártyás fizetésre vonatkozik (utánvéttel / átutalással nincs mit tesztelni).
    $method = (string) ($data['payment_method'] ?? '');
    if (in_array($method, (array) $s['offline_gateways'], true)) {
        return;
    }

    // 3. Beküldési korlát.
    $attempts = mandala_fraud_bump(mandala_fraud_key('try', $ip), 10 * MINUTE_IN_SECONDS);
    if ($attempts > ($siege ? 10 : (int) $s['attempts'])) {
        mandala_fraud_block($errors, 'túl sok beküldés', __('Túl sok kártyás próbálkozás rövid idő alatt. Válassz utánvétet vagy átutalást, vagy próbáld újra pár perc múlva.', 'mandala'));
        return;
    }

    // 2. Sikertelen kártyás fizetések után csak nem kártyás mód.
    $email = (string) ($data['billing_email'] ?? '');
    if ((int) get_transient(mandala_fraud_key('fail_ip', $ip)) >= ($siege ? 1 : (int) $s['fail_limit_ip']) || ($email && (int) get_transient(mandala_fraud_key('fail_mail', $email)) >= ($siege ? 1 : (int) $s['fail_limit']))) {
        mandala_fraud_block($errors, 'sikertelen fizetések', __('Több sikertelen kártyás fizetés után most nem fizethetsz kártyával. Válassz utánvétet vagy átutalást, vagy próbáld újra egy óra múlva.', 'mandala'));
    }
}, 5, 2);

/* ---------- 2. + 4. Sikertelen fizetések számlálása, ostrom mód ---------- */

add_action('woocommerce_order_status_failed', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || in_array($order->get_payment_method(), (array) mandala_fraud_settings()['offline_gateways'], true)) {
        return;
    }
    $ip = $order->get_customer_ip_address();
    mandala_fraud_bump(mandala_fraud_key('fail_ip', $ip ? substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 16) : mandala_fraud_ip()), HOUR_IN_SECONDS);
    if ($order->get_billing_email()) {
        mandala_fraud_bump(mandala_fraud_key('fail_mail', $order->get_billing_email()), HOUR_IN_SECONDS);
    }
    $total = mandala_fraud_bump('mandala_fraud_failed_hour', HOUR_IN_SECONDS);
    if ($total >= (int) mandala_fraud_settings()['siege_failed'] && !mandala_fraud_siege()) {
        set_transient('mandala_fraud_siege', time(), HOUR_IN_SECONDS);
        $to = function_exists('mandala_monitor_settings') ? (mandala_monitor_settings()['to'] ?: (string) (mandala_config('contact', [])['email'] ?? get_option('admin_email'))) : get_option('admin_email');
        mandala_send_mail($to, sprintf('[%s] Figyelem: sok sikertelen fizetés – szigorított pénztár 1 órára', get_bloginfo('name')), 'Kártyatesztelés gyanú',
            '<p>' . esc_html(sprintf('Az elmúlt órában %d sikertelen kártyás fizetés volt. A pénztár 1 órára szigorított módba lépett: 1 sikertelen fizetés után az adott vásárló csak utánvéttel vagy átutalással rendelhet, és IP-nként 10 percenként legfeljebb 10 kártyás beküldés mehet.', $total)) . '</p>'
            . '<p>' . esc_html('Érdemes megnézni a Teya felületén a tranzakciókat. Ha a támadás folytatódik, a Teya ügyfélszolgálata tud segíteni (pl. 3D Secure kötelezővé tétele).') . '</p>', false, ['type' => 'belso']);
    }
});

// A Vezérlőpult őrszem dobozában és az ellenőrzésekben.
add_filter('mandala_health_checks', function ($checks) {
    $day = count(array_filter((array) get_option('mandala_fraud_log', []), fn($l) => ($l['t'] ?? 0) > time() - DAY_IN_SECONDS));
    $checks['fraud'] = ['label' => 'Csalásvédelem', 'status' => mandala_fraud_siege() ? 'warn' : 'ok', 'msg' => (mandala_fraud_siege() ? 'Szigorított mód (sok sikertelen fizetés). ' : '') . sprintf('%d blokkolt pénztári próbálkozás az elmúlt 24 órában.', $day)];
    return $checks;
});
