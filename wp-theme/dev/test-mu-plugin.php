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

// Meta Conversions API helyettesítő végpont: a kérést elmentjük, a válasz „sikeres”.
add_filter('mandala_capi_endpoint', fn() => 'https://capi.test/events');
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (str_starts_with((string) $url, 'https://capi.test/')) {
        update_option('mandala_capi_mock', ['url' => $url, 'body' => $args['body']], false);
        return ['headers' => [], 'body' => '{"events_received":1}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
    return $pre;
}, 10, 3);

// Anthropic Messages API helyettesítő (a Claude-os kategorizálás tesztjéhez): determinisztikus
// kulcsszavas besorolás, a valódi válasz szerkezetében (tool_use + usage). A kérést elmenti.
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($url !== 'https://api.anthropic.com/v1/messages') {
        return $pre;
    }
    $req = json_decode($args['body'], true);
    update_option('mandala_ai_mock_last', ['model' => $req['model'], 'tool_choice' => $req['tool_choice'], 'cached' => !empty($req['system'][0]['cache_control']), 'key' => $args['headers']['x-api-key'] ?? '', 'tool' => $req['tools'][0]['name']], false);
    if (($args['headers']['x-api-key'] ?? '') === 'rate-limit') {
        return ['headers' => ['retry-after' => '1'], 'body' => '{"type":"error","error":{"type":"rate_limit_error","message":"rate"}}', 'response' => ['code' => 429, 'message' => 'Too Many'], 'cookies' => [], 'filename' => null];
    }
    $products = json_decode(substr($req['messages'][0]['content'], strpos($req['messages'][0]['content'], "\n") + 1), true);
    $notes = ['c' => 'gyoker', 'd' => 'szakralis', 'e' => 'napfonat', 'f' => 'sziv', 'g' => 'torok', 'a' => 'homlok', 'b' => 'korona'];
    $results = [];
    foreach ($products as $p) {
        $text = mb_strtolower($p['name'] . ' ' . implode(' ', $p['old_categories'] ?? []) . ' ' . ($p['description'] ?? ''));
        $r = ['product_id' => $p['product_id'], 'category' => '', 'subcategory' => '', 'fields' => [], 'confidence' => 0.3, 'uncertain_fields' => [], 'note' => 'Nem egyértelmű termék.'];
        if (str_contains($text, 'hangtál')) {
            preg_match('/(\d{2,4})\s*hz/u', $text, $hz);
            preg_match('/(\d{2,5})\s*g\b/u', $text, $g);
            preg_match('/[–-]\s*([a-g])(#?)(?![a-z])/u', $text, $n);
            $r = ['product_id' => $p['product_id'], 'category' => 'szakralis-targyak', 'subcategory' => 'hangtalak', 'fields' => [
                'szandek' => ['csend'], 'eredet' => str_contains($text, 'tibeti') || str_contains($text, 'nepál') ? ['nepal'] : [],
                'hang' => $n ? [$n[1] . ($n[2] ? '-sharp' : '')] : [], 'hz' => $hz ? (float) $hz[1] : null, 'suly' => $g ? (float) $g[1] : null,
                'csakra' => $n ? [$notes[$n[1]]] : [], 'keszites' => str_contains($text, 'kovácsolt') ? ['kovacsolt'] : (str_contains($text, 'öntött') ? ['ontott'] : []),
            ], 'confidence' => 0.4, 'uncertain_fields' => [], 'note' => 'Hangtál a név alapján.'];
            $complete = $hz && $g && $n && $r['fields']['keszites'] && $r['fields']['eredet'];
            $r['confidence'] = $complete ? 0.94 : 0.62;
            if (!$complete) {
                $r['uncertain_fields'] = array_keys(array_filter($r['fields'], fn($v) => $v === null || $v === []));
            }
            if (empty($p['short_description'])) {
                $r['short_description_draft'] = 'Nepáli hangtál tiszta, hosszan zengő hanggal.';
            }
        } elseif (str_contains($text, 'füstölő')) {
            $r = ['product_id' => $p['product_id'], 'category' => 'szakralis-targyak', 'subcategory' => 'fustolok', 'fields' => ['szandek' => ['csend'], 'eredet' => ['india'], 'forma' => ['Pálcika'], 'illat' => []],
                'confidence' => 0.58, 'uncertain_fields' => ['illat'], 'note' => 'Az illat nem derül ki az adatokból.'];
        }
        $results[] = $r;
    }
    $n = count($products);
    $body = ['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'], 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_test', 'name' => $req['tools'][0]['name'], 'input' => ['results' => $results]]],
        'usage' => ['input_tokens' => 300 * $n, 'output_tokens' => 180 * $n, 'cache_read_input_tokens' => 2800, 'cache_creation_input_tokens' => 0]];
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 10, 3);
