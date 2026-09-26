<?php
/**
 * WooCommerce → Mandala bolt adatai: a téma setup/data/config.json alapértékeinek felülírása
 * adminból (a témafrissítés nem írja felül): elérhetőség, nyitvatartás, közösségi linkek,
 * ingyenes szállítás határa, utánvét díja. A banki adatok a WooCommerce → Fizetés → Előre utalás
 * alatt vannak (a köszönőoldal és a levelek onnan veszik).
 */

defined('ABSPATH') || exit;

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Mandala bolt adatai', 'Mandala bolt adatai', 'manage_woocommerce', 'mandala-store', function () {
        $defaults = mandala_data('config');
        if (isset($_POST['mandala_store']) && check_admin_referer('mandala_store')) {
            $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_store']));
            $contact = [];
            foreach (array_keys((array) ($defaults['contact'] ?? [])) as $key) {
                $value = trim($in['contact_' . $key] ?? '');
                $contact[$key] = in_array($key, ['facebook', 'instagram'], true) ? esc_url_raw($value) : ($key === 'email' ? sanitize_email($value) : $value);
            }
            update_option('mandala_contact', $contact);
            update_option('mandala_freeShippingFrom', max(0, (int) ($in['free'] ?? 0)));
            $payment = mandala_config('payment', []);
            foreach ($payment as &$p) {
                if (($p['id'] ?? '') === 'cod') {
                    $p['fee'] = max(0, (int) ($in['cod_fee'] ?? 0));
                }
            }
            unset($p);
            update_option('mandala_payment', $payment);
            delete_transient('mandala_cat_tree');
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        }
        $contact = (array) mandala_config('contact', []);
        $cod = 0;
        foreach ((array) mandala_config('payment', []) as $p) {
            if (($p['id'] ?? '') === 'cod') {
                $cod = (int) ($p['fee'] ?? 0);
            }
        }
        $labels = ['email' => 'E-mail', 'phone' => 'Telefon', 'address' => 'Cím / bemutatóterem', 'hours' => 'Nyitvatartás', 'facebook' => 'Facebook oldal', 'instagram' => 'Instagram oldal'];
        echo '<div class="wrap"><h1>Mandala bolt adatai</h1><p>A fejlécben, a láblécben, a kapcsolat oldalon, a pénztárban és a levelekben megjelenő adatok.</p><form method="post">';
        wp_nonce_field('mandala_store');
        echo '<table class="form-table">';
        foreach ($labels as $key => $label) {
            echo '<tr><th scope="row"><label for="ms-' . $key . '">' . esc_html($label) . '</label></th><td><input type="' . ($key === 'email' ? 'email' : 'text') . '" class="regular-text" id="ms-' . $key . '" name="mandala_store[contact_' . $key . ']" value="' . esc_attr((string) ($contact[$key] ?? '')) . '"></td></tr>';
        }
        echo '<tr><th scope="row"><label for="ms-free">Ingyenes szállítás ettől (Ft)</label></th><td><input type="number" min="0" step="500" id="ms-free" name="mandala_store[free]" value="' . esc_attr((string) mandala_config('freeShippingFrom', 25000)) . '" style="width:120px"><p class="description">0 = a téma nem ad ingyenes szállítást (ha a GLS bővítményben állítjátok be). A közlemény sáv és a kosár mérője is ebből dolgozik.</p></td></tr>'
            . '<tr><th scope="row"><label for="ms-cod">Utánvét díja (Ft, bruttó)</label></th><td><input type="number" min="0" step="10" id="ms-cod" name="mandala_store[cod_fee]" value="' . esc_attr((string) $cod) . '" style="width:120px"><p class="description">Személyes átvételnél nincs díj.</p></td></tr>'
            . '</table>';
        submit_button('Mentés');
        echo '</form><p class="description">Banki adatok (előre utaláshoz): <a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs')) . '">WooCommerce → Fizetés → Előre utalás</a>.</p></div>';
    });
});
