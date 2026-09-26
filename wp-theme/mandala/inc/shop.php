<?php
/**
 * WooCommerce: klasszikus pénztár (5 lépés), magyar mezősorrend és adószám-ellenőrzés,
 * ingyenes szállítás, utánvét díja / „Fizetés átvételkor”, minikosár fragmentek,
 * köszönő oldal kiegészítések. Az ÁFA- és bolti beállításokat az inc/setup.php állítja.
 */

defined('ABSPATH') || exit;

if (!class_exists('WooCommerce')) {
    return;
}

/** A WooCommerce alap stíluslapjai helyett a téma assets/css/shop.css-e dolgozik. */
add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/* ---------- Sablonok: a child téma woocommerce/ mappája elsőbbséget kap ---------- */

// Az iu_woocommerce saját sablonfelülírásokat ad; ahol a child témában van fájl, az nyer.
add_filter('woocommerce_locate_template', function ($template, $name) {
    $child = MANDALA_DIR . '/woocommerce/' . $name;
    return file_exists($child) ? $child : $template;
}, 999, 2);
add_filter('wc_get_template', function ($template, $name) {
    $child = MANDALA_DIR . '/woocommerce/' . $name;
    return file_exists($child) ? $child : $template;
}, 999, 2);

/**
 * A WooCommerce a bolt, a kategória-, címke- és márkaarchívum, valamint a termékoldal
 * esetén saját PHP sablont töltene (fejléc/lábléc nélkül) – ezeket az iu_theme
 * index.php-jára irányítjuk, hogy a templates/*.html sablonrendszer rendereljen.
 */
add_filter('template_include', function ($template) {
    if (function_exists('is_woocommerce') && (is_shop() || is_product_taxonomy() || is_product() || is_tax('product_brand'))) {
        $index = get_template_directory() . '/index.php';
        return file_exists($index) ? $index : $template;
    }
    return $template;
}, 99);

/* ---------- Termékoldal: a sablon nem futtat loopot, a $product globált mi állítjuk ---------- */

add_action('wp', function () {
    if (is_singular('product')) {
        $GLOBALS['product'] = wc_get_product(get_queried_object_id());
    }
});

/* ---------- Ingyenes szállítás a küszöb felett (kedvezmény után, bruttó) ---------- */

function mandala_cart_goods_total(): float
{
    if (!WC()->cart) {
        return 0;
    }
    $cart = WC()->cart;
    return (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() - (float) $cart->get_discount_total() - (float) $cart->get_discount_tax();
}

add_filter('woocommerce_package_rates', function ($rates) {
    $free = mandala_cart_goods_total() >= (float) mandala_config('freeShippingFrom', 25000);
    foreach ($rates as $rate) {
        if ($free && $rate->get_method_id() !== 'local_pickup' && (float) $rate->get_cost() > 0) {
            // Az eredeti díj megmarad a meta adatban: a pénztár áthúzva mutatja.
            $rate->add_meta_data('mandala_regular', (float) $rate->get_cost() + array_sum($rate->get_taxes()));
            $rate->set_cost(0);
            $rate->set_taxes(array_map(fn() => 0, $rate->get_taxes()));
        }
    }
    return $rates;
}, 20);

/** A szállítási címke ne tartalmazza az árat (külön oszlopban mutatjuk). */
add_filter('woocommerce_cart_shipping_method_full_label', fn($label, $method) => $method->get_label(), 10, 2);

/* ---------- Utánvét: díj, kivéve személyes átvételnél („Fizetés átvételkor”) ---------- */

function mandala_chosen_shipping_id(): string
{
    $chosen = WC()->session ? (array) WC()->session->get('chosen_shipping_methods') : [];
    return (string) ($chosen[0] ?? '');
}
function mandala_is_pickup(): bool
{
    return str_starts_with(mandala_chosen_shipping_id(), 'local_pickup');
}

add_action('woocommerce_cart_calculate_fees', function (WC_Cart $cart) {
    if (is_admin() && !wp_doing_ajax()) {
        return;
    }
    $method = WC()->session ? WC()->session->get('chosen_payment_method') : '';
    if (isset($_POST['payment_method'])) { // phpcs:ignore -- a WooCommerce checkout AJAX ellenőrzi
        $method = sanitize_key(wp_unslash($_POST['payment_method'])); // phpcs:ignore
    }
    if ($method !== 'cod' || mandala_is_pickup()) {
        return;
    }
    $gross = (float) (mandala_config('payment')[2]['fee'] ?? 490);
    $rate = (float) mandala_config('vatRate', 27);
    // A díjat a WooCommerce nettóban várja: így a bruttó pontosan 490 Ft.
    $cart->add_fee(__('Utánvét díja', 'mandala'), $gross / (1 + $rate / 100), true);
});

add_filter('woocommerce_gateway_title', function ($title, $id) {
    if ($id === 'cod' && !is_admin() && WC()->session && mandala_is_pickup()) {
        return __('Fizetés átvételkor', 'mandala');
    }
    return $title;
}, 10, 2);
add_filter('woocommerce_gateway_description', function ($description, $id) {
    if ($id !== 'cod' || is_admin() || !WC()->session) {
        return $description;
    }
    if (mandala_is_pickup()) {
        return __('A bemutatóteremben készpénzzel vagy bankkártyával fizethetsz.', 'mandala');
    }
    $fee = mandala_fmt(mandala_config('payment')[2]['fee'] ?? 490);
    $foxpost = str_contains(mandala_chosen_shipping_id(), 'foxpost');
    return sprintf(__('Utánvét díja: %s.', 'mandala'), $fee) . ' ' . ($foxpost ? __('Az automatánál bankkártyával fizethetsz.', 'mandala') : __('A futárnál készpénzzel vagy bankkártyával fizethetsz.', 'mandala'));
}, 10, 2);

/* ---------- Pénztár mezők: magyar sorrend, elérhetőség elöl, cég + adószám ---------- */

/** Magyar adószám: 8-1-2 számjegy, a törzsszám 8. jegye ellenőrző (súlyok: 9,7,3,1,9,7,3). */
function mandala_valid_tax_number(string $value): bool
{
    if (!preg_match('/^(\d{8})-?([1-5])-?(\d{2})$/', preg_replace('/\s+/', '', $value), $m)) {
        return false;
    }
    $d = array_map('intval', str_split($m[1]));
    $sum = 0;
    foreach ([9, 7, 3, 1, 9, 7, 3] as $i => $w) {
        $sum += $w * $d[$i];
    }
    return (10 - $sum % 10) % 10 === $d[7];
}

/** +36 formátumra hozott magyar telefonszám, vagy null. */
function mandala_norm_phone(string $value): ?string
{
    $digits = preg_replace('/[^\d+]/', '', $value);
    if (preg_match('/^\+36\d{8,9}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^06(\d{8,9})$/', $digits, $m)) {
        return '+36' . $m[1];
    }
    return null;
}

/** Kliens oldali validáció („szabály|hibaüzenet” soronként, mint az iu/form). */
function mandala_validate_attr(string ...$lines): array
{
    return ['data-validate' => implode("\n", $lines)];
}

add_filter('woocommerce_billing_fields', function ($fields) {
    $req = fn($msg) => 'required|' . $msg;
    $set = function ($key, $args) use (&$fields) {
        if (isset($fields[$key])) {
            $fields[$key] = array_merge($fields[$key], $args);
        }
    };
    $set('billing_email', ['priority' => 5, 'label' => __('E-mail-cím', 'mandala'), 'class' => ['form-row-first'], 'description' => __('Ide küldjük a visszaigazolást és a csomagkövetést.', 'mandala'),
        'custom_attributes' => mandala_validate_attr($req(__('Add meg az e-mail-címed – ide küldjük a visszaigazolást.', 'mandala')), 'email|' . __('Ez nem tűnik érvényes e-mail-címnek (pl. nev@pelda.hu).', 'mandala'))]);
    $set('billing_phone', ['priority' => 6, 'label' => __('Telefonszám', 'mandala'), 'required' => true, 'class' => ['form-row-last'], 'placeholder' => '+36 30 123 4567',
        'custom_attributes' => mandala_validate_attr($req(__('Add meg a telefonszámod – a futár ezen ér el.', 'mandala')), 'phone|' . __('Magyar telefonszámot adj meg, pl. +36 30 123 4567.', 'mandala')) + ['data-format' => 'phone', 'inputmode' => 'tel']]);
    $set('billing_last_name', ['priority' => 10, 'class' => ['form-row-first'], 'custom_attributes' => mandala_validate_attr($req(__('Add meg a vezetékneved.', 'mandala')))]);
    $set('billing_first_name', ['priority' => 20, 'class' => ['form-row-last'], 'custom_attributes' => mandala_validate_attr($req(__('Add meg a keresztneved.', 'mandala')))]);
    $set('billing_company', ['priority' => 30, 'class' => ['form-row-wide', 'mandala-company-field'], 'label' => __('Cégnév', 'mandala'),
        'custom_attributes' => mandala_validate_attr($req(__('Add meg a cég nevét.', 'mandala')))]);
    $fields['billing_tax_number'] = [
        'label' => __('Adószám', 'mandala'), 'required' => false, 'priority' => 31, 'class' => ['form-row-wide', 'mandala-company-field'],
        'placeholder' => '12345676-2-41', 'description' => __('A számjegyeket is ellenőrizzük, hogy a számla biztosan jó legyen.', 'mandala'),
        'custom_attributes' => mandala_validate_attr($req(__('Céges számlához add meg az adószámot.', 'mandala')), 'taxno|' . __('Ez nem érvényes magyar adószám (8-1-2 számjegy).', 'mandala')) + ['data-format' => 'taxno', 'inputmode' => 'numeric'],
    ];
    $set('billing_country', ['priority' => 40, 'label' => __('Ország', 'mandala'), 'description' => __('Jelenleg belföldre szállítunk.', 'mandala')]);
    $set('billing_postcode', ['priority' => 60, 'class' => ['form-row-third', 'zip-row', 'address-field'], 'custom_attributes' => mandala_validate_attr($req(__('Add meg az irányítószámot.', 'mandala')), 'zip|' . __('Négyjegyű magyar irányítószámot adj meg.', 'mandala')) + ['inputmode' => 'numeric', 'maxlength' => '4']]);
    $set('billing_city', ['priority' => 70, 'class' => ['form-row-twothird', 'city-row', 'address-field'], 'custom_attributes' => mandala_validate_attr($req(__('Add meg a települést.', 'mandala')))]);
    $set('billing_address_1', ['priority' => 80, 'label' => __('Utca, házszám', 'mandala'), 'placeholder' => __('pl. Király utca 12.', 'mandala'), 'class' => ['form-row-twothird', 'address-field'], 'custom_attributes' => mandala_validate_attr($req(__('Add meg az utcát és a házszámot.', 'mandala')))]);
    $set('billing_address_2', ['priority' => 90, 'label' => __('Emelet, ajtó', 'mandala'), 'label_class' => [], 'placeholder' => '', 'class' => ['form-row-third', 'address-field']]);
    unset($fields['billing_state']);
    return $fields;
}, 20);

add_filter('woocommerce_shipping_fields', function ($fields) {
    $map = ['shipping_last_name' => [10, 'form-row-first'], 'shipping_first_name' => [20, 'form-row-last'], 'shipping_company' => [30, 'form-row-wide'],
            'shipping_postcode' => [60, 'form-row-third'], 'shipping_city' => [70, 'form-row-twothird'], 'shipping_address_1' => [80, 'form-row-twothird'], 'shipping_address_2' => [90, 'form-row-third']];
    foreach ($map as $key => [$priority, $class]) {
        if (isset($fields[$key])) {
            $fields[$key]['priority'] = $priority;
            $fields[$key]['class'] = [$class, 'address-field'];
        }
    }
    if (isset($fields['shipping_address_1'])) {
        $fields['shipping_address_1']['label'] = __('Utca, házszám', 'mandala');
    }
    if (isset($fields['shipping_address_2'])) {
        $fields['shipping_address_2']['label'] = __('Emelet, ajtó', 'mandala');
        $fields['shipping_address_2']['label_class'] = [];
    }
    unset($fields['shipping_state'], $fields['shipping_company']);
    return $fields;
}, 20);

/**
 * Magyar címkék nyelvi csomagtól függetlenül: a WooCommerce address-i18n.js a „default”
 * országbeállításból írja vissza a címkéket, ezért ott is magyarul adjuk meg őket.
 */
function mandala_address_labels(): array
{
    return [
        'first_name' => ['label' => __('Keresztnév', 'mandala')],
        'last_name' => ['label' => __('Vezetéknév', 'mandala')],
        'company' => ['label' => __('Cégnév', 'mandala')],
        'country' => ['label' => __('Ország', 'mandala')],
        'address_1' => ['label' => __('Utca, házszám', 'mandala'), 'placeholder' => __('pl. Király utca 12.', 'mandala')],
        'address_2' => ['label' => __('Emelet, ajtó', 'mandala'), 'label_class' => [], 'placeholder' => ''],
        'city' => ['label' => __('Település', 'mandala')],
        'state' => ['label' => __('Megye', 'mandala'), 'required' => false, 'hidden' => true],
        'postcode' => ['label' => __('Irányítószám', 'mandala')],
    ];
}
add_filter('woocommerce_get_country_locale_default', function ($locale) {
    foreach (mandala_address_labels() as $key => $args) {
        $locale[$key] = array_merge($locale[$key] ?? [], $args);
    }
    return $locale;
});
add_filter('woocommerce_default_address_fields', function ($fields) {
    foreach (mandala_address_labels() as $key => $args) {
        if (isset($fields[$key])) {
            $fields[$key] = array_merge($fields[$key], $args);
        }
    }
    return $fields;
});

/** Magyar címformátum (irányítószám a település előtt) a köszönő oldalon, e-mailekben, adminban. */
add_filter('woocommerce_localisation_address_formats', function ($formats) {
    $formats['HU'] = "{company}\n{last_name} {first_name}\n{postcode} {city}\n{address_1} {address_2}\n{country}";
    return $formats;
});

/** A WooCommerce országfüggő beállítása (HU) felülírná a sorrendet: igazítjuk. */
add_filter('woocommerce_get_country_locale', function ($locale) {
    $locale['HU'] = array_merge($locale['HU'] ?? [], [
        'last_name' => ['priority' => 10, 'class' => ['form-row-first']],
        'first_name' => ['priority' => 20, 'class' => ['form-row-last']],
        'postcode' => ['priority' => 60, 'class' => ['form-row-third', 'zip-row', 'address-field']],
        'city' => ['priority' => 70, 'class' => ['form-row-twothird', 'city-row', 'address-field']],
        'address_1' => ['priority' => 80, 'class' => ['form-row-twothird', 'address-field']],
        'address_2' => ['priority' => 90, 'class' => ['form-row-third', 'address-field'], 'label' => __('Emelet, ajtó', 'mandala'), 'label_class' => []],
        'state' => ['required' => false, 'hidden' => true],
    ]);
    return $locale;
});

add_filter('woocommerce_checkout_fields', function ($fields) {
    if (isset($fields['order']['order_comments'])) {
        $fields['order']['order_comments']['label'] = __('Megjegyzés a rendeléshez', 'mandala');
        $fields['order']['order_comments']['label_class'] = ['sr-only'];
        $fields['order']['order_comments']['placeholder'] = __('Pl. csengő nem működik; vagy a kártyára írandó üzenet', 'mandala');
        $fields['order']['order_comments']['custom_attributes'] = ['maxlength' => '500', 'rows' => '3'];
    }
    if (isset($fields['account']['account_password'])) {
        $fields['account']['account_password']['label'] = __('Jelszó', 'mandala');
        $fields['account']['account_password']['description'] = __('Legalább 8 karakter.', 'mandala');
        $fields['account']['account_password']['custom_attributes'] = mandala_validate_attr('required|' . __('Adj meg egy jelszót a fiókhoz.', 'mandala'), 'min8|' . __('Legalább 8 karakter legyen.', 'mandala'));
    }
    return $fields;
});

/** Cégként vásárlás: cégnév és adószám kötelező + ellenőrző számjegy; különben ürítjük. */
add_filter('woocommerce_checkout_posted_data', function ($data) {
    $company = !empty($_POST['is_company']); // phpcs:ignore -- a WooCommerce checkout nonce-ot ellenőrzött
    if (!$company) {
        $data['billing_company'] = '';
        $data['billing_tax_number'] = '';
    } elseif (!empty($data['billing_tax_number'])) {
        $digits = preg_replace('/\D/', '', $data['billing_tax_number']);
        if (strlen($digits) === 11) {
            $data['billing_tax_number'] = substr($digits, 0, 8) . '-' . $digits[8] . '-' . substr($digits, 9, 2);
        }
    }
    if (!empty($data['billing_phone']) && ($phone = mandala_norm_phone($data['billing_phone']))) {
        $data['billing_phone'] = $phone;
    }
    return $data;
});

add_action('woocommerce_after_checkout_validation', function ($data, WP_Error $errors) {
    if (!empty($_POST['is_company'])) { // phpcs:ignore
        if (empty($data['billing_company'])) {
            $errors->add('billing_company_required', __('<strong>Cégnév</strong>: add meg a cég nevét.', 'mandala'), ['id' => 'billing_company']);
        }
        if (empty($data['billing_tax_number'])) {
            $errors->add('billing_tax_number_required', __('<strong>Adószám</strong>: céges számlához add meg az adószámot.', 'mandala'), ['id' => 'billing_tax_number']);
        } elseif (!mandala_valid_tax_number($data['billing_tax_number'])) {
            $errors->add('billing_tax_number_validation', __('<strong>Adószám</strong>: ez nem érvényes magyar adószám (8-1-2 számjegy).', 'mandala'), ['id' => 'billing_tax_number']);
        }
    }
    if (!empty($data['billing_phone']) && !mandala_norm_phone($data['billing_phone'])) {
        $errors->add('billing_phone_validation', __('<strong>Telefonszám</strong>: magyar telefonszámot adj meg, pl. +36 30 123 4567.', 'mandala'), ['id' => 'billing_phone']);
    }
    if (!empty($data['billing_postcode']) && ($data['billing_country'] ?? 'HU') === 'HU' && !preg_match('/^[1-9]\d{3}$/', $data['billing_postcode'])) {
        $errors->add('billing_postcode_validation', __('<strong>Irányítószám</strong>: négyjegyű magyar irányítószámot adj meg.', 'mandala'), ['id' => 'billing_postcode']);
    }
}, 10, 2);

add_action('woocommerce_checkout_create_order', function (WC_Order $order) {
    $order->update_meta_data('_mandala_newsletter', empty($_POST['mandala_newsletter']) ? 'no' : 'yes'); // phpcs:ignore
    $order->update_meta_data('_mandala_is_company', empty($_POST['is_company']) ? 'no' : 'yes'); // phpcs:ignore
});

add_action('woocommerce_admin_order_data_after_billing_address', function (WC_Order $order) {
    if ($tax = $order->get_meta('_billing_tax_number')) {
        echo '<p><strong>' . esc_html__('Adószám', 'mandala') . ':</strong> ' . esc_html($tax) . '</p>';
    }
    echo '<p><strong>' . esc_html__('Hírlevél', 'mandala') . ':</strong> ' . esc_html($order->get_meta('_mandala_newsletter') === 'yes' ? __('feliratkozott', 'mandala') : __('nem', 'mandala')) . '</p>';
});
add_filter('woocommerce_order_formatted_billing_address', function ($address, WC_Order $order) {
    if ($tax = $order->get_meta('_billing_tax_number')) {
        $address['company'] = trim(($address['company'] ?? '') . ' (' . __('adószám', 'mandala') . ': ' . $tax . ')');
    }
    return $address;
}, 10, 2);

/* ---------- Szövegek ---------- */

add_filter('woocommerce_order_button_text', fn() => __('Fizetési kötelezettséggel járó megrendelés', 'mandala'));
add_filter('woocommerce_get_terms_and_conditions_checkbox_text', function () {
    return sprintf(
        __('Elolvastam és elfogadom az <a href="%1$s" target="_blank" rel="noopener">ÁSZF-et</a> és az <a href="%2$s" target="_blank" rel="noopener">adatkezelési tájékoztatót</a>, és tudomásul veszem a 14 napos elállási jogra vonatkozó tájékoztatást.', 'mandala'),
        esc_url(get_permalink(wc_terms_and_conditions_page_id()) ?: home_url('/')),
        esc_url(get_privacy_policy_url())
    );
});
add_filter('woocommerce_get_privacy_policy_text', function ($text, $type) {
    return $type === 'checkout' ? '' : $text; // a pénztárban az ÁSZF-jelölőnégyzet már tartalmazza
}, 10, 2);

/* ---------- Minikosár (fejléc fiók) és fragmentek ---------- */

function mandala_minicart_content(): void
{
    $cart = WC()->cart;
    if (!$cart) {
        return;
    }
    $icon = 'mandala_icon';
    if ($cart->is_empty()) {
        $picks = wc_get_products(['status' => 'publish', 'featured' => true, 'stock_status' => 'instock', 'limit' => 3]);
        echo '<div class="drawer-body"><div class="empty-state" style="margin-top:var(--space-5)">' . $icon('bag', 'ico ico-xl') . '<h3>' . esc_html__('A kosarad üres', 'mandala') . '</h3>';
        if ($picks) {
            echo '<p>' . esc_html__('Kezdd egy népszerű darabbal:', 'mandala') . '</p><ul class="search-hits" style="width:100%;text-align:left">';
            foreach ($picks as $p) {
                echo '<li><a href="' . esc_url($p->get_permalink()) . '"><span class="thumb">' . mandala_product_image($p, 'thumbnail') . '</span><span>' . esc_html($p->get_name()) . '<small>' . esc_html(mandala_fmt(wc_get_price_to_display($p))) . '</small></span>' . $icon('chevron-right') . '</a></li>'; // phpcs:ignore
            }
            echo '</ul>';
        }
        echo '</div></div>';
        return;
    }
    $threshold = (float) mandala_config('freeShippingFrom', 25000);
    $goods = mandala_cart_goods_total();
    $pct = min(100, $threshold ? $goods / $threshold * 100 : 100);
    $done = $goods >= $threshold;
    echo '<div class="drawer-body"><div class="ship-meter' . ($done ? ' is-done' : '') . '"><p>'
        . ($done ? $icon('check', 'ico ico-s') . ' ' . esc_html__('A szállítás ingyenes.', 'mandala') : sprintf(esc_html__('Még %s, és ingyen szállítunk.', 'mandala'), '<strong>' . esc_html(mandala_fmt($threshold - $goods)) . '</strong>')) // phpcs:ignore
        . '</p><div class="meter" role="progressbar" aria-label="' . esc_attr__('Ingyenes szállításig', 'mandala') . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . esc_attr((string) $pct) . '%"></span></div></div>';
    echo '<ul class="review-items woocommerce-mini-cart" style="border:0">';
    foreach ($cart->get_cart() as $key => $item) {
        $p = $item['data'];
        if (!$p || !$p->exists() || $item['quantity'] <= 0) {
            continue;
        }
        $max = $p->get_max_purchase_quantity();
        $max = $max > 0 ? min(99, $max) : 99;
        $url = $p->get_permalink($item);
        $name = $p->get_name();
        echo '<li class="woocommerce-mini-cart-item"><a class="thumb" href="' . esc_url($url) . '">' . mandala_product_image($p, 'thumbnail') . '</a>' // phpcs:ignore
            . '<div><a class="name" href="' . esc_url($url) . '" style="color:inherit;text-decoration:none">' . esc_html($name) . '</a><small>' . esc_html(mandala_fmt(wc_get_price_to_display($p))) . ' / ' . esc_html__('db', 'mandala') . '</small>'
            . '<div class="quantity quantity-s" data-cart-key="' . esc_attr($key) . '" style="margin-top:var(--space-2)">'
            . '<button type="button" data-step="-1" aria-label="' . esc_attr(sprintf(__('Eggyel kevesebb: %s', 'mandala'), $name)) . '">' . $icon('minus', 'ico ico-s') . '</button>'
            . '<input type="number" inputmode="numeric" min="1" max="' . (int) $max . '" value="' . (int) $item['quantity'] . '" aria-label="' . esc_attr(sprintf(__('Mennyiség: %s', 'mandala'), $name)) . '">'
            . '<button type="button" data-step="1"' . ($item['quantity'] >= $max ? ' disabled' : '') . ' aria-label="' . esc_attr(sprintf(__('Eggyel több: %s', 'mandala'), $name)) . '">' . $icon('plus', 'ico ico-s') . '</button></div></div>'
            . '<div style="display:grid;justify-items:end;gap:var(--space-2)"><strong class="num">' . wp_kses_post($cart->get_product_subtotal($p, $item['quantity'])) . '</strong>'
            . '<a href="' . esc_url(wc_get_cart_remove_url($key)) . '" class="remove remove_from_cart_button" aria-label="' . esc_attr(sprintf(__('Törlés: %s', 'mandala'), $name)) . '" data-product_id="' . (int) $p->get_id() . '" data-cart_item_key="' . esc_attr($key) . '" data-product_sku="' . esc_attr($p->get_sku()) . '">' . $icon('trash', 'ico ico-s') . '</a></div></li>'; // phpcs:ignore
    }
    echo '</ul></div><div class="drawer-foot"><table class="totals-table"><tbody><tr><th>' . esc_html__('Részösszeg', 'mandala') . '</th><td>' . wp_kses_post($cart->get_cart_subtotal()) . '</td></tr>';
    foreach ($cart->get_coupons() as $code => $coupon) {
        echo '<tr class="discount"><th>' . esc_html(sprintf(__('Kupon (%s)', 'mandala'), strtoupper($code))) . '</th><td>−' . wp_kses_post(wc_price($cart->get_coupon_discount_amount($code, false))) . '</td></tr>';
    }
    echo '</tbody></table><p class="text-muted text-small" style="margin:0">' . esc_html__('A szállítási díjat a pénztárban választod ki. Az árak az ÁFÁ-t tartalmazzák.', 'mandala') . '</p>'
        . '<a class="iu-button iu-button-large iu-button-block" href="' . esc_url(wc_get_checkout_url()) . '">' . $icon('lock', 'ico ico-s') . ' ' . esc_html__('Tovább a pénztárhoz', 'mandala') . '</a>' // phpcs:ignore
        . '<a class="iu-button iu-button-outline iu-button-block" href="' . esc_url(wc_get_cart_url()) . '">' . esc_html__('Kosár megtekintése', 'mandala') . '</a></div>';
}

add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
    $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $fragments['span[data-cart-count]'] = mandala_cart_count_html($count);
    $fragments['#minicart-count'] = '<span id="minicart-count" class="text-muted" style="font-size:1.25rem">' . ($count ? '(' . $count . ')' : '') . '</span>';
    ob_start();
    echo '<div class="widget_shopping_cart_content">';
    mandala_minicart_content();
    echo '</div>';
    $fragments['div.widget_shopping_cart_content'] = ob_get_clean();
    return $fragments;
});

/** Minikosár mennyiség-módosítás (wc-ajax=mandala_set_qty): frissített fragmentekkel válaszol. */
add_action('wc_ajax_mandala_set_qty', function () {
    check_ajax_referer('mandala-cart', 'security');
    $key = sanitize_text_field(wp_unslash($_POST['key'] ?? ''));
    $qty = max(0, (int) ($_POST['qty'] ?? 0));
    $item = WC()->cart->get_cart_item($key);
    if ($item) {
        $max = $item['data']->get_max_purchase_quantity();
        WC()->cart->set_quantity($key, $max > 0 ? min($qty, $max) : $qty, true);
    }
    WC_AJAX::get_refreshed_fragments();
});
add_filter('mandala_js_data', function ($data) {
    $data['cartNonce'] = wp_create_nonce('mandala-cart');
    return $data;
});

/* ---------- Pénztár: fragmentek a bal oldali szállítási blokkhoz ---------- */

/** Szállítási módok a pénztár 2. lépésében (a WooCommerce a jobb oldali összesítőben tenné). */
function mandala_checkout_shipping_html(): string
{
    $packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
    $chosen = (array) (WC()->session ? WC()->session->get('chosen_shipping_methods') : []);
    $contact = mandala_config('contact', []);
    $icons = ['flat_rate' => 'truck', 'foxpost' => 'locker', 'local_pickup' => 'store', 'free_shipping' => 'truck'];
    $notes = [];
    foreach (mandala_config('shipping', []) as $s) {
        $notes[strtok($s['wc'], ':')] = $s['note'];
    }
    ob_start();
    echo '<div id="mandala-shipping">';
    if (!WC()->cart->needs_shipping()) {
        echo '<p class="text-muted">' . esc_html__('A rendelés nem igényel szállítást.', 'mandala') . '</p></div>';
        return (string) ob_get_clean();
    }
    foreach ($packages as $i => $package) {
        $rates = $package['rates'] ?? [];
        if (!$rates) {
            echo '<p class="woocommerce-info">' . esc_html__('Ehhez a címhez jelenleg nincs elérhető szállítási mód. Írj nekünk, és megoldjuk.', 'mandala') . '</p>';
            continue;
        }
        $current = $chosen[$i] ?? array_key_first($rates);
        echo '<ul id="shipping_method" class="woocommerce-shipping-methods">';
        foreach ($rates as $rate) {
            $id = sanitize_title($rate->get_id());
            $method = $rate->get_method_id();
            // A Foxpost bővítmény nélkül fix díjas módként fut: a címke alapján ismerjük fel.
            foreach (mandala_config('shipping', []) as $cfg) {
                if ($cfg['label'] === $rate->get_label()) {
                    $method = $method === 'flat_rate' && str_starts_with($cfg['wc'], 'foxpost') ? 'foxpost' : $method;
                    $notes[$method] = $cfg['note'];
                }
            }
            $cost = (float) $rate->get_cost() + array_sum($rate->get_taxes());
            $base = $method === 'local_pickup' ? 0 : (float) ($rate->get_meta_data()['mandala_regular'] ?? 0);
            $price = $cost > 0 ? esc_html(mandala_fmt($cost)) : ($base > 0 ? '<s>' . esc_html(mandala_fmt($base)) . '</s>' : '') . '<span class="is-free">' . esc_html__('Ingyenes', 'mandala') . '</span>';
            echo '<li class="method" data-method="' . esc_attr($method) . '"><label for="shipping_method_' . (int) $i . '_' . esc_attr($id) . '">'
                . '<input type="radio" name="shipping_method[' . (int) $i . ']" data-index="' . (int) $i . '" id="shipping_method_' . (int) $i . '_' . esc_attr($id) . '" value="' . esc_attr($rate->get_id()) . '" class="shipping_method" ' . checked($rate->get_id(), $current, false) . '>'
                . '<span class="method-icon" aria-hidden="true">' . mandala_icon($icons[$method] ?? 'truck') . '</span>'
                . '<span class="method-text"><strong>' . esc_html($rate->get_label()) . '</strong><small>' . esc_html($notes[$method] ?? '') . '</small></span>'
                . '<span class="method-price' . ($cost > 0 ? '' : ' is-free') . '">' . $price . '</span></label>';
            if ($rate->get_id() === $current) {
                if ($method === 'local_pickup') {
                    echo '<div class="method-extra" style="padding:0 var(--space-5) var(--space-5)"><div class="pickup-point">' . mandala_icon('store', 'ico ico-l') . '<span><strong>' . esc_html__('Mandala bemutatóterem, Budapest', 'mandala') . '</strong>'
                        . esc_html(($contact['address'] ?? '') . ' · ' . ($contact['hours'] ?? '')) . '<br>' . esc_html__('E-mailben értesítünk, amikor átvehető (általában 1 munkanap).', 'mandala') . '</span><span></span></div></div>';
                }
                // A csomagpont-választót (Foxpost) a szállítási bővítmény teszi ide.
                ob_start();
                do_action('woocommerce_after_shipping_rate', $rate, $i);
                $extra = trim((string) ob_get_clean());
                if ($extra) {
                    echo '<div class="method-extra" style="padding:0 var(--space-5) var(--space-5)">' . $extra . '</div>'; // phpcs:ignore
                }
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
    return (string) ob_get_clean();
}

add_filter('woocommerce_update_order_review_fragments', function ($fragments) {
    $fragments['#mandala-shipping'] = mandala_checkout_shipping_html();
    $fragments['.mandala-summary-total'] = '<strong class="num mandala-summary-total">' . wp_kses_post(WC()->cart->get_total()) . '</strong>';
    $fragments['.mandala-place-total'] = '<span class="num mandala-place-total">' . wp_strip_all_tags(WC()->cart->get_total()) . '</span>';
    return $fragments;
});

/* ---------- Kosár oldal: ingyenes szállítás mérő ---------- */

add_action('woocommerce_before_cart_table', function () {
    $threshold = (float) mandala_config('freeShippingFrom', 25000);
    $goods = mandala_cart_goods_total();
    $pct = min(100, $goods / max(1, $threshold) * 100);
    echo '<div class="ship-meter' . ($goods >= $threshold ? ' is-done' : '') . '"><p>'
        . ($goods >= $threshold ? mandala_icon('check', 'ico ico-s') . ' ' . esc_html__('A szállítás ingyenes.', 'mandala') : sprintf(esc_html__('Még %s, és ingyen szállítunk.', 'mandala'), '<strong>' . esc_html(mandala_fmt($threshold - $goods)) . '</strong>')) // phpcs:ignore
        . '</p><div class="meter" role="progressbar" aria-label="' . esc_attr__('Ingyenes szállításig', 'mandala') . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $pct . '"><span style="width:' . esc_attr((string) $pct) . '%"></span></div></div>';
});

/* ---------- Alapértelmezett WooCommerce kimenetek, amelyeket a sablonjaink kiváltanak ---------- */

add_action('init', function () {
    // Kupon és belépés a pénztár felett: a kupon az összesítőben, a belépés linkként az 1. lépésben van.
    remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10);
    remove_action('woocommerce_before_checkout_form', 'woocommerce_checkout_login_form', 10);
    // A fizetési blokk a pénztár 4. lépésében van, nem az összesítőben.
    remove_action('woocommerce_checkout_order_review', 'woocommerce_checkout_payment', 20);
    // A köszönő oldal saját rendelés-összesítőt rajzol.
    remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);
    // Kosár oldal: a keresztértékesítést a mandala/products (Ehhez illik) blokk adja.
    remove_action('woocommerce_cart_collaterals', 'woocommerce_cross_sell_display');
});

/** Az Előre utalás saját banki táblázata helyett a thankyou.php másolható adatai jelennek meg. */
add_action('woocommerce_before_thankyou', function () {
    foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
        if ($gateway->id === 'bacs') {
            remove_action('woocommerce_thankyou_bacs', [$gateway, 'thankyou_page']);
        }
    }
});

/** Mennyiség léptető gombok a WooCommerce mennyiség mezője köré (kosár oldal). */
add_action('woocommerce_before_quantity_input_field', function () {
    echo '<button type="button" data-step="-1" aria-label="' . esc_attr__('Eggyel kevesebb', 'mandala') . '">' . mandala_icon('minus') . '</button>'; // phpcs:ignore
});
add_action('woocommerce_after_quantity_input_field', function () {
    echo '<button type="button" data-step="1" aria-label="' . esc_attr__('Eggyel több', 'mandala') . '">' . mandala_icon('plus') . '</button>'; // phpcs:ignore
});
