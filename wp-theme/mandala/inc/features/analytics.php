<?php
/**
 * Mérés: Google Consent Mode v2 alapállapot, GA4 események a saját felületekhez (assets/js/track.js),
 * és opcionálisan Meta Conversions API (szerveroldali Purchase esemény, csak marketing hozzájárulással).
 *
 * A GA4 / Google Ads / Meta pixel címkéi a GTM-ben (GTM4WP) vagy a PixelYourSite-ban futnak; a WooCommerce alap
 * eseményeit (termékoldal, pénztár lépései, vásárlás) ők adják. A téma csak azt méri, amit ők nem látnak, és a
 * PixelYourSite-ot a süti sáv választásához köti (Consent Mode, Meta pixel csak marketing-hozzájárulással).
 * Vásárlás a köszönőoldalon: dataLayer purchase, és a „Függőben” maradt, visszatért kártyás rendelés pótlása (lent).
 * Beállítás: WooCommerce → Mandala mérés.
 */

defined('ABSPATH') || exit;

function mandala_analytics_settings(): array
{
    return wp_parse_args((array) get_option('mandala_analytics', []), [
        'consent_default' => 'yes',   // Consent Mode v2 alapállapot (elutasítva) a GTM betöltése előtt
        'pys_consent' => 'yes',       // PixelYourSite a süti sáv választása szerint (Meta csak marketing-hozzájárulással)
        'track' => 'yes',             // saját felületek eseményei a dataLayer-be
        'capi' => 'no',
        'pixel_id' => '',
        'capi_token' => '',
        'capi_test_code' => '',
    ]);
}

/** Az adatrétegek, amelyekbe a hozzájárulás kerül: a GTM-é és a PixelYourSite / Google for WooCommerce gtag-jáé. */
function mandala_consent_layers(): array
{
    $layers = (array) apply_filters('mandala_consent_layers', ['dataLayer', 'dataLayerPYS']);
    return array_values(array_unique(array_filter($layers, fn($l) => is_string($l) && preg_match('/^[A-Za-z_$][\w$]*$/', $l))));
}

/**
 * A hozzájárulás alapállapota minden mérőkód előtt (wp_head legeleje): minden elutasítva, a korábban elmentett
 * választás (localStorage) azonnal visszaállítva – minden adatrétegbe (GTM: dataLayer; a PixelYourSite és a Google
 * for WooCommerce gtag-ja: dataLayerPYS), így a címkék már az első oldalbetöltésnél a helyes állapotot látják.
 *
 * window.mandalaConsent(prefs): a süti sáv választása (site.js) – frissítés minden rétegbe, a PixelYourSite
 * beállításai, és ha most engedte, a marketing pixelek betöltése.
 * window.mandalaPysPrep(pysOptions): a PixelYourSite indulása előtt (a 'pys' szkript elé fűzve, lent): a Meta és a
 * többi hirdetési pixel csak marketing-hozzájárulással töltődik, a Consent Mode értékei a vásárló választása szerint
 * mennek (a PixelYourSite alapból „granted”-et küldene – a Google mérése így a választás előtt is teljes volt).
 * A tiltó jelzők (facebook_disabled_by_api…) csak olvashatók, és mindig az aktuális választást adják: ha valaki a
 * PixelYourSite-ban bekapcsolja az AJAX-os GDPR-lekérést (ajax_enabled) vagy a dinamikus beállításokat, a szerver
 * válasza se kapcsolhassa vissza a Meta pixelt hozzájárulás nélkül.
 * Visszavonás („Csak a szükségesek” később): a már betöltött Meta pixel fbq('consent','revoke')-t kap – azon az oldalon
 * sem küld tovább.
 *
 * A Google címkéi (a PixelYourSite GA4 / Google Ads, a Google for WooCommerce AW- címkéje) a választás előtt is
 * betöltődnek, „denied” állapotban: sütit nem írnak, csak sütimentes jelzést küldenek (Google Consent Mode, „haladó”
 * mód, gcs=G100) – ez a Google szerint megengedett, és a konverziómodellezéshez kell. Ha a választás előtt egyáltalán
 * nem futhat Google címke, azt a PixelYourSite / Google for WooCommerce beállításában kell kérni („alap” mód).
 */
add_action('wp_head', function () {
    if (mandala_analytics_settings()['consent_default'] !== 'yes' || !apply_filters('mandala_consent_mode', true)) {
        return;
    }
    echo '<script>(function(w){var L=' . wp_json_encode(mandala_consent_layers()) . ',c=null,off=false;'
        . 'function push(cmd,v){L.forEach(function(n){w[n]=w[n]||[];(function(){w[n].push(arguments);})("consent",cmd,v);});}'
        . 'function vals(p){var m=p&&p.marketing?"granted":"denied";return{analytics_storage:p&&p.stats?"granted":"denied",ad_storage:m,ad_user_data:m,ad_personalization:m};}'
        . 'w.dataLayer=w.dataLayer||[];if(typeof w.gtag!=="function"){w.gtag=function(){w.dataLayer.push(arguments);};}'
        . 'push("default",{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied",functionality_storage:"granted",security_storage:"granted",wait_for_update:500});'
        . 'try{c=JSON.parse(localStorage.getItem("mandala.cookie.v1")||"null");}catch(e){}'
        . 'if(c){push("update",vals(c));}'
        . 'w.mandalaPysPrep=function(o){if(!o||!o.gdpr)return;var g=o.gdpr,st=!!(c&&c.stats),mk=!!(c&&c.marketing);'
        . 'function v(k,on){if(!g[k]||typeof g[k]!=="object"){g[k]={};}g[k].enabled=true;g[k].value=on?"granted":"denied";}'
        . 'v("analytics_storage",st);v("ad_storage",mk);v("ad_user_data",mk);v("ad_personalization",mk);'
        . '["facebook","tiktok","pinterest","bing","reddit","openai"].forEach(function(k){var n=k+"_disabled_by_api";try{Object.defineProperty(g,n,{configurable:true,enumerable:true,get:function(){return !(c&&c.marketing);},set:function(){}});}catch(e){g[n]=!mk;}});'
        . 'o.cookie=o.cookie||{};if(!st&&!mk&&!o.cookie.disabled_all_cookie){o.cookie.disabled_all_cookie=true;off=true;}else if((st||mk)&&off){o.cookie.disabled_all_cookie=false;off=false;}};'
        . 'w.mandalaConsent=function(p){c=p||{};push("update",vals(c));try{if(typeof w.fbq==="function"){w.fbq("consent",c.marketing?"grant":"revoke");}}catch(e){}var o=w.pysOptions;if(!o)return;w.mandalaPysPrep(o);'
        . 'if(c.marketing||c.stats){try{var P=w.pys||{};if(P.Utils&&P.Utils.loadPixels){P.Utils.loadPixels();}else if(c.marketing&&P.Facebook&&P.Facebook.loadPixel){P.Facebook.loadPixel();}}catch(e){}}};'
        . '})(window);</script>' . "\n";
}, 0);

/*
 * PixelYourSite: a beállításai (pysOptions) a választás szerint – a szkriptje elé fűzve, így a pixelek betöltése előtt
 * fut, az oldal-gyorsítótár mellett is (a HTML mindenkinek ugyanaz, a döntés a böngészőben születik).
 * Szerver oldalon a Consent Mode alapértéke „denied” (a PixelYourSite szűrői): ha a böngészős rész valamiért nem
 * futna, akkor se küldjön „granted”-et a vásárló választása előtt.
 */
function mandala_pys_consent_on(): bool
{
    $s = mandala_analytics_settings();
    return $s['consent_default'] === 'yes' && ($s['pys_consent'] ?? 'yes') === 'yes' && apply_filters('mandala_consent_mode', true);
}
add_action('init', function () {
    if (!mandala_pys_consent_on()) {
        return;
    }
    foreach (['pys_analytics_storage_mode', 'pys_ad_storage_mode', 'pys_ad_user_data_mode', 'pys_ad_personalization_mode'] as $filter) {
        add_filter($filter, '__return_false');
    }
});
function mandala_pys_glue(): void
{
    static $done = false;
    if ($done || !mandala_pys_consent_on()) {
        return;
    }
    foreach ((array) apply_filters('mandala_pys_handles', ['pys']) as $handle) {
        if (wp_script_is($handle, 'registered') && !wp_script_is($handle, 'done')) {
            wp_add_inline_script($handle, 'if(window.mandalaPysPrep){window.mandalaPysPrep(window.pysOptions);}', 'before');
            $done = true;
            return;
        }
    }
}
add_action('wp_enqueue_scripts', 'mandala_pys_glue', PHP_INT_MAX);
add_action('wp_print_footer_scripts', 'mandala_pys_glue', 1);

add_filter('mandala_js_data', function ($data) {
    $s = mandala_analytics_settings();
    $data['currency'] = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HUF';
    $data['track'] = ['viewItem' => !defined('GTM4WP_VERSION')];
    return $data;
});

add_action('wp_enqueue_scripts', function () {
    if (mandala_analytics_settings()['track'] === 'yes' && !is_admin()) {
        wp_enqueue_script_module('mandala-track', MANDALA_URL . '/assets/js/track.js', [], MANDALA_VERSION);
    }
}, 30);

/* ---------- Meta Conversions API ---------- */

/** A pénztárban a böngésző elküldi a marketing-hozzájárulást; a Meta sütiket (fbp, fbc) a kérésből olvassuk. */
add_action('woocommerce_after_order_notes', function () {
    echo '<input type="hidden" name="mandala_marketing_consent" id="mandala_marketing_consent" value="">';
    echo "<script>try{var c=JSON.parse(localStorage.getItem('mandala.cookie.v1')||'null');document.getElementById('mandala_marketing_consent').value=c&&c.marketing?'yes':'no';}catch(e){}</script>";
});
add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    $order->update_meta_data('_mandala_marketing_consent', ($_POST['mandala_marketing_consent'] ?? '') === 'yes' ? 'yes' : 'no'); // phpcs:ignore WordPress.Security.NonceVerification
    foreach (['_fbp', '_fbc'] as $cookie) {
        if (!empty($_COOKIE[$cookie])) {
            $order->update_meta_data('_mandala' . $cookie, sanitize_text_field(wp_unslash($_COOKIE[$cookie])));
        }
    }
});

function mandala_capi_ready(): bool
{
    $s = mandala_analytics_settings();
    return $s['capi'] === 'yes' && $s['pixel_id'] && $s['capi_token'];
}

/** Fizetés (kártya) vagy teljesítés (utánvét) után egyszer, háttérben. */
$mandala_capi_queue = function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_capi_ready() || $order->get_meta('_mandala_marketing_consent') !== 'yes' || $order->get_meta('_mandala_capi_queued')) {
        return;
    }
    $order->update_meta_data('_mandala_capi_queued', time());
    $order->save();
    if (function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('mandala_capi_purchase', [(int) $order_id], MANDALA_AS_GROUP);
    } else {
        do_action('mandala_capi_purchase', (int) $order_id);
    }
};
add_action('woocommerce_payment_complete', $mandala_capi_queue);
add_action('woocommerce_order_status_completed', $mandala_capi_queue);

/* ---------- Vásárlás a köszönőoldalon – a „Függőben” maradt kártyás rendelésnél is ---------- */

/*
 * Ha a vásárló a sikeres kártyás fizetés után visszatér, de a Teya értesítése (szerver-POST) nem jött meg – a tárhely
 * tűzfala (ELIN) visszatarthatja –, a rendelés „Függőben” marad: a fizetéshez kötött mérések (HelloProVision Ads hub,
 * Meta CAPI, a PixelYourSite Purchase-e) nem futnának le. Itt pótoljuk őket, rendelésenként egyszer:
 *  - hub: a saját zárával (_hpvac_sent) – a később megérkező fizetés már nem küldi újra;
 *  - Meta CAPI (a téma saját, ha be van kapcsolva): _mandala_capi_queued zár;
 *  - böngésző (lent, wp_footer): dataLayer purchase (GTM) minden nem hibás rendelésnél, ha a GTM4WP nem méri;
 *    a PixelYourSite helyett Meta Purchase és GA4 purchase (transaction_id), de csak ha a PixelYourSite maga nem küld
 *    vásárlást ezen az oldalon. A Meta eseményazonosítója ugyanaz, mint a szerveroldali párjáé, így a Meta összevonja
 *    őket: a téma Conversions API-jával „order_<rendelés>”, nélküle a HelloProVision Ads hubé, „wc-<rendelés>”.
 *    (A téma CAPI-ja és a hub Meta-küldése egyszerre ne legyen bekapcsolva – két szerveres vásárlás lenne.)
 * A visszatérés felismerése: elin.php (mandala_returned_unconfirmed_order, _mandala_card_returned).
 */
add_action('mandala_card_returned_unconfirmed', function (WC_Order $order) use ($mandala_capi_queue) {
    if (function_exists('hpvac_wc_paid')) {
        hpvac_wc_paid($order->get_id());
    }
    $mandala_capi_queue($order->get_id());
});

/** GA4 purchase adatai a rendelésből (dataLayer, gtag; a Meta Purchase is ebből készül). */
function mandala_purchase_payload(WC_Order $order): array
{
    $items = [];
    foreach ($order->get_items() as $line) {
        $product = $line->get_product();
        $qty = max(1, (int) $line->get_quantity());
        $items[] = [
            'item_id' => $product ? ($product->get_sku() ?: (string) $product->get_id()) : (string) $line->get_product_id(),
            'item_name' => $line->get_name(),
            'price' => round(((float) $line->get_total() + (float) $line->get_total_tax()) / $qty, 2),
            'quantity' => $qty,
            'index' => count($items),
        ];
    }
    return [
        'transaction_id' => (string) $order->get_order_number(),
        'value' => (float) $order->get_total(),
        'tax' => (float) $order->get_total_tax(),
        'shipping' => round((float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), 2),
        'currency' => $order->get_currency(),
        'coupon' => implode(',', $order->get_coupon_codes()),
        'items' => $items,
    ];
}

add_action('wp_footer', function () {
    if (!function_exists('is_order_received_page') || !is_order_received_page()) {
        return;
    }
    global $wp;
    $order = wc_get_order(absint($wp->query_vars['order-received'] ?? 0));
    $key = wc_clean(wp_unslash($_GET['key'] ?? '')); // phpcs:ignore
    if (!$order instanceof WC_Order || $key === '' || !hash_equals($order->get_order_key(), (string) $key) || $order->has_status(['failed', 'cancelled'])) {
        return;
    }
    $dl = mandala_analytics_settings()['track'] === 'yes' && !defined('GTM4WP_VERSION') && !$order->get_meta('_mandala_dl_purchase');
    $fill = $order->has_status('pending') && $order->get_meta('_mandala_card_returned') && !$order->get_meta('_mandala_px_fill');
    if (!$dl && !$fill) {
        return;
    }
    // Rendelésenként egyszer (a köszönőoldal újratöltése, a levélből visszanyitott oldal ne számoljon újra).
    $dl && $order->update_meta_data('_mandala_dl_purchase', time());
    $fill && $order->update_meta_data('_mandala_px_fill', time());
    $order->save_meta_data();
    // A Meta eseményazonosítója a szerveroldali páré szerint (a téma CAPI-ja: order_<id>; a hub: wc-<id>).
    $eid = (mandala_capi_ready() && $order->get_meta('_mandala_marketing_consent') === 'yes' ? 'order_' : 'wc-') . $order->get_id();
    $data = ['id' => $order->get_id(), 'eid' => $eid, 'dl' => $dl, 'fill' => (bool) $fill, 'p' => mandala_purchase_payload($order)];
    echo '<script>(function(w){var d=' . wp_json_encode($data) . ',p=d.p;'
        . 'function once(k){try{if(localStorage.getItem(k))return false;localStorage.setItem(k,"1");}catch(e){}return true;}'
        . 'if(d.dl&&once("mandala.purchase.dl."+d.id)){w.dataLayer=w.dataLayer||[];w.dataLayer.push({ecommerce:null});w.dataLayer.push({event:"purchase",ecommerce:p});}'
        . 'if(!d.fill)return;'
        . 'w.addEventListener("load",function(){setTimeout(function(){var o=w.pysOptions;if(!o)return;var se=o.staticEvents||{},has=false;'
        . 'Object.keys(se).forEach(function(px){var ev=se[px]||{};Object.keys(ev).forEach(function(k){if(/purchase/i.test(k))has=true;(Array.isArray(ev[k])?ev[k]:[]).forEach(function(e){if(e&&/^purchase$/i.test(e.name||""))has=true;});});});'
        . 'if(has||!once("mandala.purchase.px."+d.id))return;var c=null;try{c=JSON.parse(localStorage.getItem("mandala.cookie.v1")||"null");}catch(e){}'
        . 'if(c&&c.marketing&&typeof w.fbq==="function"){w.fbq("track","Purchase",{value:p.value,currency:p.currency,content_type:"product",content_ids:p.items.map(function(i){return i.item_id;}),num_items:p.items.reduce(function(s,i){return s+i.quantity;},0)},{eventID:d.eid});}'
        . 'if(o.ga&&o.ga.trackingIds&&o.ga.trackingIds.length){var t=o.GATags||{},n=t.ga_datalayer_type==="custom"&&t.ga_datalayer_name?t.ga_datalayer_name:(t.ga_datalayer_type&&t.ga_datalayer_type!=="default"?"dataLayer":"dataLayerPYS"),L=w[n]=w[n]||[],e={send_to:o.ga.trackingIds};Object.keys(p).forEach(function(k){e[k]=p[k];});(function(){L.push(arguments);})("event","purchase",e);}'
        . '},1500);});})(window);</script>' . "\n";
}, 30);

add_action('mandala_capi_purchase', function ($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || !mandala_capi_ready()) {
        return;
    }
    $s = mandala_analytics_settings();
    $hash = fn($v) => $v !== '' ? hash('sha256', $v) : null;
    $norm = fn($v) => strtolower(trim((string) $v));
    $user = array_filter([
        'em' => [$hash($norm($order->get_billing_email()))],
        'ph' => [$hash(preg_replace('/\D/', '', (string) $order->get_billing_phone()))],
        'fn' => [$hash($norm($order->get_billing_first_name()))],
        'ln' => [$hash($norm($order->get_billing_last_name()))],
        'ct' => [$hash(preg_replace('/\s+/', '', $norm($order->get_billing_city())))],
        'zp' => [$hash($norm($order->get_billing_postcode()))],
        'country' => [$hash($norm($order->get_billing_country()))],
        'external_id' => $order->get_customer_id() ? [$hash((string) $order->get_customer_id())] : null,
        'client_ip_address' => $order->get_customer_ip_address() ?: null,
        'client_user_agent' => $order->get_customer_user_agent() ?: null,
        'fbp' => $order->get_meta('_mandala_fbp') ?: null,
        'fbc' => $order->get_meta('_mandala_fbc') ?: null,
    ], fn($v) => $v !== null && $v !== [null]);
    $contents = [];
    foreach ($order->get_items() as $line) {
        $product = $line->get_product();
        $contents[] = ['id' => $product ? ($product->get_sku() ?: (string) $product->get_id()) : (string) $line->get_product_id(), 'quantity' => $line->get_quantity(), 'item_price' => round(((float) $line->get_total() + (float) $line->get_total_tax()) / max(1, $line->get_quantity()))];
    }
    $payload = ['data' => [[
        'event_name' => 'Purchase',
        'event_time' => $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time(),
        // Deduplikáció a böngészős pixellel: a GTM Meta címkéjében eventID = "order_" + transaction_id.
        'event_id' => 'order_' . $order->get_id(),
        'action_source' => 'website',
        'event_source_url' => $order->get_checkout_order_received_url(),
        'user_data' => $user,
        'custom_data' => ['currency' => $order->get_currency(), 'value' => (float) $order->get_total(), 'order_id' => (string) $order->get_order_number(), 'content_type' => 'product', 'contents' => $contents, 'num_items' => $order->get_item_count()],
    ]]];
    if ($s['capi_test_code']) {
        $payload['test_event_code'] = $s['capi_test_code'];
    }
    $endpoint = apply_filters('mandala_capi_endpoint', 'https://graph.facebook.com/v21.0/' . rawurlencode($s['pixel_id']) . '/events');
    $response = wp_remote_post(add_query_arg('access_token', rawurlencode($s['capi_token']), $endpoint), ['timeout' => 15, 'headers' => ['Content-Type' => 'application/json'], 'body' => wp_json_encode($payload)]);
    $code = is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_response_code($response);
    $log = (array) get_option('mandala_capi_log', []);
    array_unshift($log, ['time' => current_time('mysql'), 'order' => $order->get_id(), 'status' => $code, 'body' => is_wp_error($response) ? '' : mb_substr(wp_remote_retrieve_body($response), 0, 300)]);
    update_option('mandala_capi_log', array_slice($log, 0, 30), false);
    $order->add_order_note('Meta Conversions API (Purchase): ' . (is_int($code) && $code < 300 ? 'elküldve' : 'hiba – ' . $code));
});

/* ---------- Beállítások: WooCommerce → Mandala mérés ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala mérés', 'Mandala mérés', 'manage_woocommerce', 'mandala-analytics', function () {
        if (isset($_POST['mandala_analytics']) && check_admin_referer('mandala_analytics')) {
            $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_analytics']));
            $clean = [];
            foreach (mandala_analytics_settings() as $key => $value) {
                $clean[$key] = in_array($key, ['consent_default', 'pys_consent', 'track', 'capi'], true) ? (empty($in[$key]) ? 'no' : 'yes') : trim($in[$key] ?? '');
            }
            update_option('mandala_analytics', $clean, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        $s = mandala_analytics_settings();
        $box = fn($k, $label, $desc) => '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="mandala_analytics[' . $k . ']" value="1"' . checked($s[$k], 'yes', false) . '> bekapcsolva</label><p class="description">' . esc_html($desc) . '</p></td></tr>';
        $text = fn($k, $label, $desc, $type = 'text') => '<tr><th scope="row"><label for="ma-' . $k . '">' . esc_html($label) . '</label></th><td><input type="' . $type . '" class="regular-text" id="ma-' . $k . '" name="mandala_analytics[' . $k . ']" value="' . esc_attr($s[$k]) . '" autocomplete="off"><p class="description">' . esc_html($desc) . '</p></td></tr>';
        echo '<div class="wrap"><h1>Mandala mérés</h1><form method="post">';
        wp_nonce_field('mandala_analytics');
        echo '<table class="form-table">'
            . $box('consent_default', 'Consent Mode v2 alapállapot', 'A GTM előtt minden mérés „elutasítva”, a süti sáv választása frissíti. Kapcsold ki, ha külön süti-kezelő bővítmény állítja.')
            . $box('pys_consent', 'PixelYourSite a süti sáv szerint', 'A Meta (és a többi hirdetési) pixel csak marketing-hozzájárulással töltődik be; a Google Consent Mode értékei a vásárló választása szerint mennek (a PixelYourSite magától „engedélyezve” állapotot küldene). Kell hozzá a Consent Mode alapállapot is.')
            . $box('track', 'Saját felületek eseményei', 'GA4 események a dataLayer-be: szűrt lista (view_item_list, select_item), termékoldali, ajándékcsomag és viszonteladói kosárba tétel (add_to_cart), keresés, hangtál-választó. A WooCommerce alap eseményeit a GTM4WP adja.' . (defined('GTM4WP_VERSION') ? '' : ' (GTM4WP nem aktív: a termékoldal view_item eseményét is a téma küldi.)'))
            . $box('capi', 'Meta Conversions API', 'Szerveroldali Purchase esemény fizetés / teljesítés után – csak ha a vásárló a pénztárban marketing sütikhez hozzájárult. Deduplikáció: a GTM Meta címkéjében eventID = "order_" + transaction_id.')
            . $text('pixel_id', 'Pixel ID', '')
            . $text('capi_token', 'Hozzáférési token', 'Events Manager → Beállítások → Conversions API.', 'password')
            . $text('capi_test_code', 'Teszt esemény kód', 'Csak teszteléshez (Events Manager → Események tesztelése); élesben üresen.')
            . '</table>';
        submit_button('Mentés');
        $log = (array) get_option('mandala_capi_log', []);
        if (array_filter($log)) {
            echo '</form><h2>Utolsó Conversions API küldések</h2><table class="widefat striped"><thead><tr><th>Idő</th><th>Rendelés</th><th>Válasz</th></tr></thead><tbody>';
            foreach (array_filter($log) as $row) {
                echo '<tr><td>' . esc_html($row['time']) . '</td><td>#' . (int) $row['order'] . '</td><td>' . esc_html($row['status'] . ' ' . $row['body']) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        } else {
            echo '</form></div>';
        }
    });
});
