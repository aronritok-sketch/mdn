<?php
/**
 * CSAK TESZTKÖRNYEZET (wp-content/mu-plugins/): a PixelYourSite és a Google for WooCommerce viselkedésének kicsinyített
 * mása a hozzájárulás-tesztekhez (tests/wp-resilience.mjs) – a fizetős PixelYourSite Pro nincs a teszt WordPressben.
 *
 * Ami a valódiból számít (a PixelYourSite forrása szerint, dist/scripts/public.js és class-events-manager.php):
 *  - a 'pys' szkript elé a pysOptions kerül (wp_localize_script), a gdpr.*_storage értékeit a
 *    pys_*_storage_mode szűrők állítják (szűrő nélkül „granted”);
 *  - a szkript induláskor a dataLayerPYS-be küldi a Consent Mode értékeit ('update', ha már van 'default');
 *  - Utils.loadPixels(): a Meta pixel csak ha !gdpr.facebook_disabled_by_api; betöltéskor PageView;
 *  - cookie.disabled_all_cookie: a saját sütijeit (pys_first_visit…) nem írja;
 *  - window.pys.Utils / window.pys.Facebook a külső kódnak.
 * A Google for WooCommerce a <head>-ben: dataLayerPYS + gtag() + régiós 'default' (denied).
 * Tesztbővítmény: hpvac_wc_paid() helyettesítő (a HelloProVision Ads hub hívásainak számolása).
 */

add_action('wp_head', function () {
    echo "<script>window.dataLayerPYS=window.dataLayerPYS||[];function gtag(){dataLayerPYS.push(arguments);}gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',region:['HU','AT','DE'],wait_for_update:500});gtag('js',new Date());</script>\n";
}, 5);

add_action('wp_enqueue_scripts', function () {
    $mode = fn($filter) => has_filter($filter) ? (apply_filters($filter, true) ? 'granted' : 'denied') : 'granted';
    $static = ['facebook' => ['init_event' => [['name' => 'PageView', 'pixelIds' => ['123'], 'eventID' => 'pv-1', 'params' => []]]]];
    $options = [
        'staticEvents' => $static,
        'facebook' => ['pixelIds' => ['123']],
        'ga' => ['trackingIds' => ['G-TESZT']],
        'google_consent_mode' => true,
        'GATags' => ['ga_datalayer_type' => 'default', 'ga_datalayer_name' => 'dataLayerPYS'],
        'gdpr' => [
            'all_disabled_by_api' => false, 'facebook_disabled_by_api' => false, 'analytics_disabled_by_api' => false, 'google_ads_disabled_by_api' => false,
            'tiktok_disabled_by_api' => false, 'pinterest_disabled_by_api' => false, 'bing_disabled_by_api' => false,
            'analytics_storage' => ['enabled' => true, 'value' => $mode('pys_analytics_storage_mode'), 'filter' => has_filter('pys_analytics_storage_mode')],
            'ad_storage' => ['enabled' => true, 'value' => $mode('pys_ad_storage_mode'), 'filter' => has_filter('pys_ad_storage_mode')],
            'ad_user_data' => ['enabled' => true, 'value' => $mode('pys_ad_user_data_mode'), 'filter' => has_filter('pys_ad_user_data_mode')],
            'ad_personalization' => ['enabled' => true, 'value' => $mode('pys_ad_personalization_mode'), 'filter' => has_filter('pys_ad_personalization_mode')],
        ],
        'cookie' => ['disabled_all_cookie' => false],
    ];
    wp_register_script('pys', false, ['jquery'], '1', true);
    wp_enqueue_script('pys');
    wp_localize_script('pys', 'pysOptions', $options);
    wp_add_inline_script('pys', <<<'JS'
(function (options) {
  var L = window.dataLayerPYS = window.dataLayerPYS || [];
  var push = function () { L.push(arguments); };
  var hasDefault = L.some(function (x) { return x && x[0] === 'consent' && x[1] === 'default'; });
  var g = options.gdpr;
  if (options.google_consent_mode) {
    push('consent', hasDefault ? 'update' : 'default', { analytics_storage: g.analytics_storage.value, ad_storage: g.ad_storage.value, ad_user_data: g.ad_user_data.value, ad_personalization: g.ad_personalization.value });
  }
  if (!options.cookie.disabled_all_cookie) document.cookie = 'pys_first_visit=1; path=/';
  var fbInit = false;
  var Facebook = { loadPixel: function () { if (fbInit) return; fbInit = true; window.__fbq = window.__fbq || []; window.fbq = function () { window.__fbq.push([].slice.call(arguments)); }; window.fbq('init', '123'); window.fbq('track', 'PageView'); } };
  var Utils = { loadPixels: function () { if (!g.all_disabled_by_api && !g.facebook_disabled_by_api) Facebook.loadPixel(); } };
  window.pys = { Facebook: Facebook, Utils: Utils };
  Utils.loadPixels();
})(window.pysOptions);
JS, 'after');
}, 20);

if (!function_exists('hpvac_wc_paid')) {
    function hpvac_wc_paid($order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_hpvac_sent')) {
            return;
        }
        $order->update_meta_data('_hpvac_sent', time());
        $order->save_meta_data();
        update_option('test_hpvac_calls', array_merge((array) get_option('test_hpvac_calls', []), [(int) $order_id]), false);
    }
    // Mint a valódi: fizetett / feldolgozás alatt / teljesített rendelésnél.
    add_action('woocommerce_payment_complete', 'hpvac_wc_paid', 20, 1);
    add_action('woocommerce_order_status_processing', 'hpvac_wc_paid', 20, 1);
    add_action('woocommerce_order_status_completed', 'hpvac_wc_paid', 20, 1);
}
