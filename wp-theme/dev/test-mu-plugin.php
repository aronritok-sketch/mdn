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
    if ($url !== 'https://api.anthropic.com/v1/messages' || $pre !== false) {
        return $pre;
    }
    $req = json_decode($args['body'], true);
    update_option('mandala_ai_mock_last', ['model' => $req['model'], 'tool_choice' => $req['tool_choice'] ?? null, 'cached' => !empty($req['system'][0]['cache_control']), 'key' => $args['headers']['x-api-key'] ?? '', 'tool' => $req['tools'][0]['name'] ?? '', 'fallbacks' => $req['fallbacks'] ?? null, 'beta' => $args['headers']['anthropic-beta'] ?? '', 'effort' => $req['output_config']['effort'] ?? null], false);
    if (($req['tools'][0]['name'] ?? '') === 'search_products') {
        return mandala_test_chat_mock($req);
    }
    if (empty($req['tools'])) {
        // Sima szöveges kérés (pl. heti összefoglaló).
        update_option('mandala_text_mock_prompt', $req['messages'][0]['content'], false);
        $body = ['id' => 'msg_text', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'], 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'AI összefoglaló: a forgalom stabil, a kifogyó termékeket érdemes utánrendelni.']], 'usage' => ['input_tokens' => 50, 'output_tokens' => 20]];
        return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
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

/**
 * Tanácsadó chat helyettesítő: első kör → eszközhívás (a kérdésből kiolvasott kereséssel vagy a
 * termékoldal azonosítójával), második kör → szöveges válasz az eszköz első termékére linkelve.
 * „REFUSE” a kérdésben → stop_reason: refusal. Az utolsó kérést elmenti (mandala_chat_mock).
 */
function mandala_test_chat_mock(array $req): array
{
    $last = end($req['messages']);
    $wrap = fn(array $body) => ['headers' => [], 'body' => wp_json_encode($body + ['id' => 'msg_chat', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'],
        'usage' => ['input_tokens' => 120, 'output_tokens' => 60, 'cache_read_input_tokens' => 1800, 'cache_creation_input_tokens' => 0]]), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    $log = ['rounds' => count($req['messages']), 'system' => $req['system'][0]['text'] ?? '', 'tools' => array_column($req['tools'], 'name'), 'max_tokens' => $req['max_tokens']];
    if (is_string($last['content'])) {
        $text = $last['content'];
        $log['question'] = $text;
        update_option('mandala_chat_mock', $log, false);
        if (str_contains($text, 'REFUSE')) {
            return $wrap(['stop_reason' => 'refusal', 'stop_details' => ['type' => 'refusal', 'category' => 'cyber'], 'content' => []]);
        }
        if (preg_match('/termékoldalon van: #(\d+)/u', $text, $m)) {
            $call = ['name' => 'get_product', 'input' => ['product_id' => (int) $m[1]]];
        } else {
            preg_match('/(\d[\d ]*)\s*Ft alatt/u', $text, $price);
            $call = ['name' => 'search_products', 'input' => array_filter(['query' => str_contains($text, 'hangtál') ? 'hangtál' : 'ajándék', 'max_price' => $price ? (int) str_replace(' ', '', $price[1]) : null, 'in_stock_only' => true])];
        }
        return $wrap(['stop_reason' => 'tool_use', 'content' => [
            ['type' => 'thinking', 'thinking' => 'Keresek.', 'signature' => 'sig_test'],
            ['type' => 'text', 'text' => 'Megnézem a kínálatot.'],
            ['type' => 'tool_use', 'id' => 'toolu_chat_1', 'name' => $call['name'], 'input' => $call['input']],
        ]]);
    }
    // Eszközeredmény: az előző asszisztens-üzenet változatlanul (gondolkodásblokkal) jött-e vissza.
    $prev = $req['messages'][count($req['messages']) - 2];
    $log['echo_ok'] = ($prev['content'][0]['type'] ?? '') === 'thinking' && ($prev['content'][0]['signature'] ?? '') === 'sig_test';
    $result = json_decode($last['content'][0]['content'] ?? '{}', true) ?: [];
    $log['tool_result'] = $result;
    update_option('mandala_chat_mock', $log, false);
    $p = $result['products'][0] ?? (isset($result['url']) ? $result : null);
    // A külső link csak a teszt első kérdésénél (a „külső link nem kattintható” ellenőrzéshez).
    $external = str_contains((string) wp_json_encode($req['messages'], JSON_UNESCAPED_UNICODE), 'kezdőnek 30 000') ? ' [Külső](https://example.com/x)' : '';
    $reply = $p ? "Ezt ajánlom:\n- [{$p['name']}]({$p['url']}) – {$p['price']}, **{$p['stock']}**\n\nHa kérdésed van, szólj!{$external}"
        : 'Sajnos nem találtam ilyet.';
    return $wrap(['stop_reason' => 'end_turn', 'content' => [['type' => 'text', 'text' => $reply]]]);
}

/**
 * MailerLite API helyettesítő: a kéréseket a mandala_ml_mock opcióba gyűjti (metódus, útvonal, törzs).
 * „bad” kulcs → 401; csoportok: 111 Hírlevél, 222 Vásárlók; webhook secret: „whsecret”.
 */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (!str_starts_with((string) $url, 'https://connect.mailerlite.com/api/')) {
        return $pre;
    }
    $path = substr($url, strlen('https://connect.mailerlite.com/api/'));
    $log = (array) get_option('mandala_ml_mock', []);
    $log[] = ['method' => $args['method'] ?? 'GET', 'path' => $path, 'body' => json_decode((string) ($args['body'] ?? ''), true)];
    update_option('mandala_ml_mock', $log, false);
    $out = fn($code, $body) => ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => $code, 'message' => 'X'], 'cookies' => [], 'filename' => null];
    if (($args['headers']['Authorization'] ?? '') === 'Bearer bad') {
        return $out(401, ['message' => 'Unauthenticated.']);
    }
    $method = $args['method'] ?? 'GET';
    if (str_starts_with($path, 'groups') && $method === 'GET') {
        return $out(200, ['data' => [['id' => '111', 'name' => 'Hírlevél', 'active_count' => 5], ['id' => '222', 'name' => 'Vásárlók', 'active_count' => 2]]]);
    }
    if ($path === 'groups') {
        return $out(201, ['data' => ['id' => '333', 'name' => 'Új']]);
    }
    if (str_starts_with($path, 'fields') && $method === 'GET') {
        return $out(200, ['data' => [['key' => 'name'], ['key' => 'last_name'], ['key' => 'mandala_forras']]]);
    }
    if ($path === 'fields') {
        return $out(201, ['data' => ['key' => 'x']]);
    }
    if ($path === 'webhooks') {
        return $out(201, ['data' => ['id' => 'wh1', 'secret' => 'whsecret']]);
    }
    if ($path === 'batch') {
        return $out(200, ['total' => 1, 'successful' => 1, 'failed' => 0, 'responses' => array_map(fn() => ['code' => 201, 'body' => ['data' => []]], json_decode($args['body'], true)['requests'] ?? [])]);
    }
    return $out(201, ['data' => ['id' => 's1']]);
}, 10, 3);

/** AI SEO helyettesítő (record_seo): termékekhez és gyűjtőoldalakhoz determinisztikus szöveg. */
add_filter('pre_http_request', function ($pre, $args, $url) {
    if ($url !== 'https://api.anthropic.com/v1/messages') {
        return $pre;
    }
    $req = json_decode($args['body'], true);
    if (($req['tools'][0]['name'] ?? '') !== 'record_seo') {
        return $pre;
    }
    $text = $req['messages'][0]['content'];
    $items = json_decode(substr($text, strpos($text, "ADATOK:\n") + 8), true) ?: [];
    $results = [];
    foreach ($items as $i) {
        $results[] = isset($i['name'])
            ? ['id' => $i['id'], 'seo_title' => $i['name'] . ' – kézműves darab', 'meta_description' => 'AI leírás: ' . $i['name'] . '. Nepáli és indiai kézműves darabok a Mandalánál.', 'image_alt' => 'Kép: ' . $i['name'],
               'faq' => [['q' => 'Miből készült a(z) ' . $i['name'] . '?', 'a' => 'A termékleírásban szereplő anyagból.'], ['q' => 'Ajándéknak jó?', 'a' => 'Igen, díszcsomagolással is kérheted.']]]
            : ['id' => $i['id'], 'title' => 'AI: ' . $i['working_title'], 'seo_title' => $i['working_title'] . ' – válogatás', 'meta_description' => 'Válogatás: ' . $i['working_title'], 'intro' => 'AI bevezető: ' . implode(', ', array_slice($i['examples'], 0, 2)) . '.'];
    }
    update_option('mandala_seo_mock_count', (int) get_option('mandala_seo_mock_count', 0) + 1, false);
    $body = ['id' => 'msg_seo', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'], 'stop_reason' => 'tool_use',
        'content' => [['type' => 'tool_use', 'id' => 'toolu_seo', 'name' => 'record_seo', 'input' => ['results' => $results]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 80, 'cache_read_input_tokens' => 900]];
    return ['headers' => [], 'body' => wp_json_encode($body), 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
}, 5, 3);

// Kis bemutató katalógusnál a gyűjtőoldalak alsó határa a tesztben állítható.
add_filter('mandala_collection_min', fn($min) => (int) get_option('mandala_test_collection_min', $min));
