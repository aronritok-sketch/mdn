<?php
/**
 * MailerLite: a hírlevél-feliratkozók szinkronizálása (WooCommerce → Mandala levelek → MailerLite).
 *
 *  - Csak aki hozzájárult: a hírlevél űrlap és a pénztári hírlevél-jelölő feliratkozói kerülnek át
 *    (vásárló hozzájárulás nélkül nem). A választott csoportba, a nevével.
 *  - Vásárlási adatok mezőkben a szegmentáláshoz (ha be van kapcsolva): vásárlások száma, költés,
 *    utolsó rendelés, vásárolt kategóriák, viszonteladó-e, honnan iratkozott fel. Rendelés teljesítésekor
 *    frissül; vásárló / viszonteladó csoport is választható.
 *  - Leiratkozás mindkét irányban: a téma leiratkozó linkje → MailerLite „unsubscribed”; a MailerLite-ban
 *    leiratkozott → a téma sem küld neki emlékeztetőt (webhook, aláírás-ellenőrzéssel).
 *  - Meglévő lista egyszeri átküldése kötegekben (MailerLite batch API, 50 / kérés, percenként).
 *  - Minden hívás a háttérben (Action Scheduler), az űrlapot és a pénztárt nem lassítja; 429-nél újrapróbál.
 *
 * API-kulcs: wp-config.php → MANDALA_MAILERLITE_TOKEN, vagy az admin oldalon. MailerLite → Integrations → API.
 */

defined('ABSPATH') || exit;

const MANDALA_ML_FIELDS = [
    'mandala_vasarlasok' => 'number',
    'mandala_koltes' => 'number',
    'mandala_utolso_rendeles' => 'date',
    'mandala_kategoriak' => 'text',
    'mandala_viszontelado' => 'text',
    'mandala_forras' => 'text',
];

function mandala_ml_settings(): array
{
    return wp_parse_args((array) get_option('mandala_mailerlite', []), [
        'token' => '', 'group' => '', 'buyer_group' => '', 'b2b_group' => '', 'fields' => 'yes',
        'groups' => [], 'webhook_id' => '', 'webhook_secret' => '',
    ]);
}
function mandala_ml_token(): string
{
    return defined('MANDALA_MAILERLITE_TOKEN') ? (string) MANDALA_MAILERLITE_TOKEN : (string) mandala_ml_settings()['token'];
}
function mandala_ml_ready(): bool
{
    return mandala_ml_token() !== '';
}

/** MailerLite API hívás. Visszaad: dekódolt válasz vagy WP_Error (429: retry_after). */
function mandala_ml_request(string $method, string $path, ?array $body = null)
{
    $response = wp_remote_request(apply_filters('mandala_mailerlite_endpoint', 'https://connect.mailerlite.com/api/') . ltrim($path, '/'), [
        'method' => $method,
        'timeout' => 25,
        'headers' => ['Authorization' => 'Bearer ' . mandala_ml_token(), 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
        'body' => $body !== null ? wp_json_encode($body) : null,
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('mandala_ml_http', $response->get_error_message(), ['retry_after' => 120]);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code === 429 || $code >= 500) {
        return new WP_Error('mandala_ml_busy', 'MailerLite: túl sok kérés vagy szerverhiba (' . $code . ').', ['retry_after' => max(30, (int) wp_remote_retrieve_header($response, 'retry-after'))]);
    }
    if ($code >= 400) {
        $msg = (string) ($data['message'] ?? ('HTTP ' . $code));
        if (!empty($data['errors']) && is_array($data['errors'])) {
            $msg .= ' – ' . implode('; ', array_map(fn($e) => is_array($e) ? implode(', ', $e) : (string) $e, $data['errors']));
        }
        return new WP_Error('mandala_ml_api', 'MailerLite: ' . $msg, ['status' => $code]);
    }
    return is_array($data) ? $data : [];
}

function mandala_ml_log_error(string $message): void
{
    $log = (array) get_option('mandala_ml_log', []);
    array_unshift($log, ['time' => time(), 'message' => mb_substr($message, 0, 300)]);
    update_option('mandala_ml_log', array_slice($log, 0, 20), false);
}

/* ---------- Adatok ---------- */

function mandala_ml_is_subscriber(string $email): bool
{
    $email = strtolower($email);
    return isset(((array) get_option('mandala_newsletter', []))[$email]) && !mandala_is_unsubscribed($email);
}

/** A feliratkozó adatai a MailerLite-nak (upsert: a meglévőt frissíti, a csoportokat hozzáadja). */
function mandala_ml_payload(string $email, string $status = 'active'): array
{
    $email = strtolower($email);
    if ($status === 'unsubscribed') {
        return ['email' => $email, 'status' => 'unsubscribed'];
    }
    $s = mandala_ml_settings();
    $entry = (array) (((array) get_option('mandala_newsletter', []))[$email] ?? []);
    $fields = [];
    $groups = array_filter([(string) $s['group']]);
    $orders = function_exists('wc_get_orders') ? wc_get_orders(['billing_email' => $email, 'status' => ['processing', 'completed'], 'limit' => 200, 'orderby' => 'date', 'order' => 'DESC', 'type' => 'shop_order']) : [];
    $user = get_user_by('email', $email);
    if ($orders) {
        $last = $orders[0];
        $fields += ['name' => $last->get_billing_first_name(), 'last_name' => $last->get_billing_last_name(), 'city' => $last->get_billing_city(), 'country' => $last->get_billing_country()];
    } elseif ($user) {
        $fields += ['name' => $user->first_name, 'last_name' => $user->last_name];
    }
    $b2b = $user && function_exists('mandala_is_wholesale_user') && mandala_is_wholesale_user((int) $user->ID);
    if ($s['fields'] === 'yes') {
        $cats = [];
        foreach ($orders as $o) {
            foreach ($o->get_items() as $item) {
                foreach (wp_get_post_terms($item->get_product_id(), 'product_cat', ['fields' => 'names']) ?: [] as $name) {
                    $cats[$name] = true;
                }
            }
        }
        $fields += [
            'mandala_vasarlasok' => count($orders),
            'mandala_koltes' => (int) round(array_sum(array_map(fn($o) => (float) $o->get_total(), $orders))),
            'mandala_utolso_rendeles' => $orders && $orders[0]->get_date_created() ? $orders[0]->get_date_created()->date('Y-m-d') : null,
            'mandala_kategoriak' => mb_substr(implode(', ', array_keys($cats)), 0, 250),
            'mandala_viszontelado' => $b2b ? 'igen' : 'nem',
            'mandala_forras' => (string) ($entry['source'] ?? ''),
        ];
    }
    if ($orders && $s['buyer_group']) {
        $groups[] = (string) $s['buyer_group'];
    }
    if ($b2b && $s['b2b_group']) {
        $groups[] = (string) $s['b2b_group'];
    }
    $payload = ['email' => $email, 'fields' => (object) array_filter($fields, fn($v) => $v !== null && $v !== ''), 'groups' => array_values(array_unique($groups)), 'status' => 'active'];
    if (!empty($entry['date']) && ($t = strtotime((string) $entry['date']))) {
        $payload['subscribed_at'] = gmdate('Y-m-d H:i:s', $t - (int) (get_option('gmt_offset') * HOUR_IN_SECONDS));
        $payload['opted_in_at'] = $payload['subscribed_at'];
    }
    return (array) apply_filters('mandala_mailerlite_payload', $payload, $email);
}

/* ---------- Háttér szinkron ---------- */

function mandala_ml_queue(string $email, string $status = 'active'): void
{
    $email = strtolower(sanitize_email($email));
    if (!mandala_ml_ready() || !is_email($email) || !function_exists('as_schedule_single_action')) {
        return;
    }
    $args = [$email, $status];
    if (!as_next_scheduled_action('mandala_ml_sync', $args, MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + 5, 'mandala_ml_sync', $args, MANDALA_AS_GROUP);
    }
}

add_action('mandala_ml_sync', function ($email, $status = 'active') {
    if (!mandala_ml_ready()) {
        return;
    }
    // Közben leiratkozott / már nincs a listán: nem aktiváljuk újra.
    if ($status === 'active' && !mandala_ml_is_subscriber((string) $email)) {
        return;
    }
    $result = mandala_ml_request('POST', 'subscribers', mandala_ml_payload((string) $email, (string) $status));
    if (is_wp_error($result)) {
        if ($result->get_error_code() !== 'mandala_ml_api') {
            as_schedule_single_action(time() + (int) ($result->get_error_data()['retry_after'] ?? 120), 'mandala_ml_sync', [$email, $status], MANDALA_AS_GROUP);
        }
        mandala_ml_log_error($email . ': ' . $result->get_error_message());
    }
}, 10, 2);

add_action('mandala_newsletter_subscribed', fn($email) => mandala_ml_queue((string) $email));
add_action('mandala_unsubscribed', fn($email) => mandala_ml_queue((string) $email, 'unsubscribed'));
add_action('woocommerce_order_status_completed', function ($order_id) {
    $order = wc_get_order($order_id);
    if ($order && mandala_ml_ready() && mandala_ml_is_subscriber($order->get_billing_email())) {
        mandala_ml_queue($order->get_billing_email());
    }
});

/** A meglévő lista átküldése: 50-es kötegek, percenként egy (MailerLite: 120 kérés / perc). */
function mandala_ml_bulk_start(): int
{
    $subs = array_keys((array) get_option('mandala_newsletter', []));
    $unsub = (array) get_option('mandala_unsubscribed', []);
    $items = array_merge(array_map(fn($e) => [$e, in_array($e, $unsub, true) ? 'unsubscribed' : 'active'], $subs), array_map(fn($e) => [$e, 'unsubscribed'], array_diff($unsub, $subs)));
    foreach (array_chunk($items, 50) as $i => $chunk) {
        as_schedule_single_action(time() + 5 + $i * MINUTE_IN_SECONDS, 'mandala_ml_bulk', [$chunk], MANDALA_AS_GROUP);
    }
    return count($items);
}
add_action('mandala_ml_bulk', function ($chunk) {
    $requests = array_map(fn($item) => ['method' => 'POST', 'path' => 'api/subscribers', 'body' => mandala_ml_payload((string) $item[0], (string) $item[1])], (array) $chunk);
    $result = mandala_ml_request('POST', 'batch', ['requests' => $requests]);
    if (is_wp_error($result)) {
        if ($result->get_error_code() !== 'mandala_ml_api') {
            as_schedule_single_action(time() + (int) ($result->get_error_data()['retry_after'] ?? 120), 'mandala_ml_bulk', [$chunk], MANDALA_AS_GROUP);
        }
        mandala_ml_log_error('Kötegelt szinkron: ' . $result->get_error_message());
        return;
    }
    foreach ((array) ($result['responses'] ?? []) as $i => $r) {
        if ((int) ($r['code'] ?? 200) >= 400) {
            mandala_ml_log_error(($chunk[$i][0] ?? '?') . ': ' . wp_json_encode($r['body'] ?? $r, JSON_UNESCAPED_UNICODE));
        }
    }
});

/* ---------- Webhook: leiratkozás a MailerLite-ban → a téma sem küld ---------- */

add_action('rest_api_init', function () {
    register_rest_route('mandala/v1', '/mailerlite', [
        'methods' => 'POST',
        'permission_callback' => '__return_true', // aláírással ellenőrizve
        'callback' => function (WP_REST_Request $request) {
            $secret = (string) mandala_ml_settings()['webhook_secret'];
            $raw = (string) $request->get_body();
            $sig = (string) $request->get_header('signature');
            if ($secret === '' || $sig === '' || !hash_equals(hash_hmac('sha256', $raw, $secret), $sig)) {
                return new WP_REST_Response(['error' => 'invalid signature'], 401);
            }
            $data = json_decode($raw, true) ?: [];
            $items = isset($data['events']) && is_array($data['events']) ? $data['events'] : [$data];
            $n = 0;
            foreach ($items as $it) {
                $email = strtolower(sanitize_email((string) ($it['email'] ?? $it['subscriber']['email'] ?? $it['data']['subscriber']['email'] ?? $it['data']['email'] ?? '')));
                $type = (string) ($it['type'] ?? $it['event'] ?? $data['type'] ?? $data['event'] ?? '');
                $status = (string) ($it['status'] ?? $it['subscriber']['status'] ?? $it['data']['subscriber']['status'] ?? '');
                if (is_email($email) && (str_contains($type, 'unsubscribed') || $status === 'unsubscribed')) {
                    // Közvetlenül (nem a mandala_unsubscribed hookkal), hogy ne küldjük vissza a MailerLite-nak.
                    $list = (array) get_option('mandala_unsubscribed', []);
                    $list[] = $email;
                    update_option('mandala_unsubscribed', array_values(array_unique($list)), false);
                    $subs = (array) get_option('mandala_newsletter', []);
                    unset($subs[$email]);
                    update_option('mandala_newsletter', $subs, false);
                    $n++;
                }
            }
            return ['ok' => true, 'unsubscribed' => $n];
        },
    ]);
});

/* ---------- Admin: Mandala levelek → MailerLite ---------- */

function mandala_ml_admin(): void
{
    $s = mandala_ml_settings();
    $notice = '';
    if (!empty($_POST['ml']) && check_admin_referer('mandala_ml')) {
        $in = (array) wp_unslash($_POST['ml']);
        $action = sanitize_key($_POST['ml_action'] ?? 'save');
        if ($action === 'save') {
            if (!defined('MANDALA_MAILERLITE_TOKEN') && isset($in['token']) && trim((string) $in['token']) !== '') {
                $s['token'] = trim(sanitize_text_field($in['token']));
            }
            foreach (['group', 'buyer_group', 'b2b_group'] as $k) {
                $s[$k] = preg_replace('/[^0-9A-Za-z_-]/', '', (string) ($in[$k] ?? ''));
            }
            $s['fields'] = empty($in['fields']) ? 'no' : 'yes';
            update_option('mandala_mailerlite', $s, false);
            if (mandala_ml_ready()) {
                $groups = mandala_ml_request('GET', 'groups?limit=100&sort=name');
                if (is_wp_error($groups)) {
                    $notice = '<div class="notice notice-error"><p>' . esc_html($groups->get_error_message()) . '</p></div>';
                } else {
                    $s['groups'] = [];
                    foreach ((array) ($groups['data'] ?? []) as $g) {
                        $s['groups'][(string) $g['id']] = (string) $g['name'] . (isset($g['active_count']) ? ' (' . (int) $g['active_count'] . ')' : '');
                    }
                    $new = trim(sanitize_text_field($in['new_group'] ?? ''));
                    if ($new !== '') {
                        $created = mandala_ml_request('POST', 'groups', ['name' => $new]);
                        if (!is_wp_error($created) && !empty($created['data']['id'])) {
                            $s['groups'][(string) $created['data']['id']] = $new;
                            $s['group'] = $s['group'] ?: (string) $created['data']['id'];
                        }
                    }
                    $made = 0;
                    if ($s['fields'] === 'yes') {
                        $existing = mandala_ml_request('GET', 'fields?limit=100');
                        $keys = is_wp_error($existing) ? [] : array_column((array) ($existing['data'] ?? []), 'key');
                        foreach (MANDALA_ML_FIELDS as $key => $type) {
                            if (!in_array($key, $keys, true) && !is_wp_error(mandala_ml_request('POST', 'fields', ['name' => $key, 'type' => $type]))) {
                                $made++;
                            }
                        }
                    }
                    update_option('mandala_mailerlite', $s, false);
                    $notice = '<div class="notice notice-success"><p>Mentve – kapcsolódva a MailerLite-hoz, ' . count($s['groups']) . ' csoport.' . ($made ? ' Létrehozott mezők: ' . $made . '.' : '') . '</p></div>';
                }
            }
        } elseif ($action === 'bulk' && mandala_ml_ready()) {
            $n = mandala_ml_bulk_start();
            $notice = '<div class="notice notice-success"><p>' . (int) $n . ' cím szinkronizálása ütemezve (50-es kötegekben, percenként).</p></div>';
        } elseif ($action === 'webhook' && mandala_ml_ready()) {
            if ($s['webhook_id']) {
                mandala_ml_request('DELETE', 'webhooks/' . rawurlencode($s['webhook_id']));
            }
            $hook = mandala_ml_request('POST', 'webhooks', ['name' => get_bloginfo('name') . ' – leiratkozás', 'events' => ['subscriber.unsubscribed'], 'url' => rest_url('mandala/v1/mailerlite')]);
            if (is_wp_error($hook) || empty($hook['data']['id'])) {
                $notice = '<div class="notice notice-error"><p>A webhook létrehozása nem sikerült: ' . esc_html(is_wp_error($hook) ? $hook->get_error_message() : 'ismeretlen válasz') . '</p></div>';
            } else {
                $s['webhook_id'] = (string) $hook['data']['id'];
                $s['webhook_secret'] = (string) ($hook['data']['secret'] ?? '');
                update_option('mandala_mailerlite', $s, false);
                $notice = '<div class="notice notice-success"><p>Webhook bekapcsolva: a MailerLite-ban leiratkozók a témától sem kapnak emlékeztetőt.</p></div>';
            }
        }
        $s = mandala_ml_settings();
    }
    $subs = count((array) get_option('mandala_newsletter', []));
    $unsub = count((array) get_option('mandala_unsubscribed', []));
    $pending = function_exists('as_get_scheduled_actions') ? count(as_get_scheduled_actions(['hook' => 'mandala_ml_bulk', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500], 'ids')) + count(as_get_scheduled_actions(['hook' => 'mandala_ml_sync', 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 500], 'ids')) : 0;
    $groups = ['' => '– nincs –'] + (array) $s['groups'];
    $sel = fn($name, $value) => '<select name="ml[' . $name . ']" id="ml-' . $name . '">' . implode('', array_map(fn($k, $v) => '<option value="' . esc_attr((string) $k) . '"' . selected((string) $value, (string) $k, false) . '>' . esc_html($v) . '</option>', array_keys($groups), $groups)) . '</select>';
    echo $notice; // phpcs:ignore
    echo '<p>A hírlevél-feliratkozók (űrlap és pénztár) automatikusan a MailerLite-ba kerülnek; a leiratkozás mindkét irányban érvényes. Aki nem iratkozott fel, azt nem küldjük át.</p>';
    echo '<form method="post">';
    wp_nonce_field('mandala_ml');
    echo '<input type="hidden" name="ml[x]" value="1"><table class="form-table" role="presentation">'
        . '<tr><th scope="row"><label for="ml-token">API-kulcs</label></th><td>' . (defined('MANDALA_MAILERLITE_TOKEN') ? '<code>wp-config.php: MANDALA_MAILERLITE_TOKEN</code>' : '<input type="password" id="ml-token" name="ml[token]" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr($s['token'] ? '•••••••• mentve (üresen marad)' : 'MailerLite → Integrations → API') . '">')
        . '<p class="description">Állapot: ' . (mandala_ml_ready() ? ($s['groups'] ? '<strong style="color:#008a20">kapcsolódva</strong>' : 'kulcs megadva – mentsd a kapcsolat ellenőrzéséhez') : 'nincs kulcs') . '</p></td></tr>'
        . '<tr><th scope="row"><label for="ml-group">Hírlevél csoport</label></th><td>' . $sel('group', $s['group']) . ' <input type="text" name="ml[new_group]" placeholder="vagy új csoport neve" class="regular-text" aria-label="Új csoport neve"><p class="description">Ide kerül minden feliratkozó. A csoporthoz kötött MailerLite automatizmus (pl. üdvözlő sorozat) így indul.</p></td></tr>'
        . '<tr><th scope="row"><label for="ml-buyer_group">Vásárlók csoport</label></th><td>' . $sel('buyer_group', $s['buyer_group']) . '<p class="description">Nem kötelező: a feliratkozók közül, akik már vásároltak.</p></td></tr>'
        . '<tr><th scope="row"><label for="ml-b2b_group">Viszonteladók csoport</label></th><td>' . $sel('b2b_group', $s['b2b_group']) . '</td></tr>'
        . '<tr><th scope="row">Vásárlási adatok</th><td><label><input type="checkbox" name="ml[fields]" value="1"' . checked($s['fields'], 'yes', false) . '> mezőkbe a szegmentáláshoz</label><p class="description">' . esc_html(implode(', ', array_keys(MANDALA_ML_FIELDS))) . ' – mentéskor létrehozzuk őket a MailerLite-ban. Pl. szegmens: „hangtálat vett, de 90 napja nem rendelt”.</p></td></tr>'
        . '</table><p class="submit"><button type="submit" class="button button-primary" name="ml_action" value="save">Mentés és kapcsolat ellenőrzése</button></p>';
    if (mandala_ml_ready()) {
        echo '<h2>Szinkron</h2><p>Nálunk: <strong>' . (int) $subs . '</strong> feliratkozó, <strong>' . (int) $unsub . '</strong> leiratkozott. Várakozó szinkron feladat: ' . (int) $pending . '.</p>'
            . '<p><button type="submit" class="button" name="ml_action" value="bulk">Meglévő lista átküldése a MailerLite-ba</button> <span class="description">Egyszer, bekötéskor: a feliratkozók a csoportba, a leiratkozottak „unsubscribed” állapotba.</span></p>'
            . '<p><button type="submit" class="button" name="ml_action" value="webhook">' . ($s['webhook_id'] ? 'Leiratkozás-visszajelzés újra beállítása' : 'Leiratkozás-visszajelzés bekapcsolása (webhook)') . '</button> <span class="description">' . ($s['webhook_id'] ? 'Bekapcsolva.' : 'A MailerLite-ban leiratkozók a témától se kapjanak emlékeztetőt.') . '</span></p>';
    }
    echo '</form>';
    $log = (array) get_option('mandala_ml_log', []);
    if ($log) {
        echo '<h2>Legutóbbi hibák</h2><ul>';
        foreach ($log as $e) {
            echo '<li><code>' . esc_html(wp_date('m. d. H:i', (int) $e['time'])) . '</code> ' . esc_html($e['message']) . '</li>';
        }
        echo '</ul>';
    }
    echo '<p class="description">A MailerLite saját WooCommerce bővítménye (kosárelhagyás, termékajánló kampányok) mellette is használható; a kosárelhagyó levelet ilyenkor csak az egyikben kapcsold be.</p>';
}
