<?php
/**
 * CSAK TESZTKÖRNYEZET (wp-content/mu-plugins/): levelek fájlba, SQLite-kompatibilitás,
 * GLS bővítmény helyettesítő szállítási módok, viszonteladói szerep.
 */

// Levelek a wp-content/mail.log fájlba (nincs sendmail).
add_filter('pre_wp_mail', function ($null, $atts) {
    file_put_contents(WP_CONTENT_DIR . '/mail.log', date('c') . ' TO: ' . (is_array($atts['to']) ? implode(',', $atts['to']) : $atts['to']) . "\nSUBJECT: {$atts['subject']}\n{$atts['message']}\n-----\n", FILE_APPEND);
    return true;
}, 10, 2);

// Az SQLite illesztő nem ismeri a WooCommerce készletfoglaló lekérdezését (LOCK IN SHARE MODE).
add_filter('woocommerce_hold_stock_for_checkout', '__return_false');

// GLS bővítmény helyettesítő: házhozszállítás és csomagpont (a valódi bővítmény azonosítói eltérhetnek).
add_action('woocommerce_shipping_init', function () {
    foreach (['gls_test_courier' => ['GLS futárszolgálat', 1990], 'gls_test_parcel_point' => ['GLS CsomagPont vagy csomagautomata', 1290]] as $id => [$title, $gross]) {
        eval('class ' . $id . ' extends WC_Shipping_Method {
            public function __construct($instance_id = 0) {
                $this->id = ' . var_export($id, true) . ';
                $this->instance_id = absint($instance_id);
                $this->method_title = ' . var_export($title, true) . ';
                $this->title = ' . var_export($title, true) . ';
                $this->supports = ["shipping-zones", "instance-settings"];
                $this->enabled = "yes";
            }
            public function calculate_shipping($package = []) {
                $this->add_rate(["id" => $this->get_rate_id(), "label" => $this->title, "cost" => round(' . $gross . ' / 1.27, 4), "taxes" => ""]);
            }
        }');
    }
});
add_filter('woocommerce_shipping_methods', function ($methods) {
    $methods['gls_test_courier'] = 'gls_test_courier';
    $methods['gls_test_parcel_point'] = 'gls_test_parcel_point';
    return $methods;
});
// A csomagpont-választó helye (a valódi GLS bővítmény térképe ide kerül).
add_action('woocommerce_after_shipping_rate', function ($rate) {
    if ($rate->get_method_id() === 'gls_test_parcel_point') {
        echo '<p class="gls-test-picker">GLS pontválasztó (bővítmény)</p>';
    }
});

// Viszonteladói szerep (élesben a Wholesale Prices bővítmény hozza létre).
add_action('init', function () {
    if (!wp_installing() && get_option('wp_user_roles') && !get_role('wholesale_customer')) {
        add_role('wholesale_customer', 'Wholesale Customer', ['read' => true]);
    }
});
