<?php
/**
 * Claude-alapú kategorizálás (Anthropic Messages API).
 *
 * 1. Migráció: a meglévő (3000+) termék régi kategóriáiból, tulajdonságaiból, nevéből és leírásából
 *    javaslatot kér az ÚJ kategóriafára és szűrőadatokra (catalog-schema.php). Ha a Claude biztos
 *    (a megbízhatóság eléri a küszöböt, és a kategóriához kötelező szűrők megvannak), a javaslat
 *    automatikusan érvénybe lép; ha nem, a termék az „Új termékek → Élő, ellenőrizendő” sorba kerül a
 *    javaslattal előtöltve. Próbafuttatás (semmit nem ír), költségbecslés, napló, visszavonás.
 * 2. Új (JUTA) termékek: érkezéskor javaslatot kapnak; ha biztos, a kategória és a szűrők előtöltődnek,
 *    a kép és a leírás így is kézi munka (onboarding.php).
 *
 * A kérés strukturált kimenetet kér (tool use, a kategóriák és értékek felsorolt listából), a
 * kategóriafa leírását gyorsítótárazza (prompt caching). A kötegeket az Action Scheduler futtatja,
 * egymás után, 429/529 esetén visszalépéssel.
 * API-kulcs: wp-config.php → define('MANDALA_ANTHROPIC_API_KEY', '…'); vagy a beállításoknál.
 * Felület: Termékek → Új termékek → Claude migráció. WP-CLI: wp mandala ai-migrate, wp mandala ai-undo.
 */

defined('ABSPATH') || exit;

const MANDALA_AI_TOOL = 'record_classifications';

function mandala_ai_settings(): array
{
    return wp_parse_args((array) get_option('mandala_ai', []), [
        'api_key' => '',
        'model' => 'claude-opus-5-5',
        'threshold' => 0.85,
        'batch' => 8,
        'keep_old' => 'yes',     // a régi kategóriák megmaradnak (URL-ek, SEO) – külön lépésben bonthatók
        'create_terms' => 'yes', // nyitott listás szűrőknél (illat, anyag…) új érték létrehozható
        'auto_new' => 'yes',     // új JUTA termékekhez automatikus javaslat
        'price_in' => '',        // USD / millió bemeneti token – csak a becsléshez
        'price_out' => '',       // USD / millió kimeneti token
    ]);
}
function mandala_ai_key(): string
{
    return defined('MANDALA_ANTHROPIC_API_KEY') ? (string) MANDALA_ANTHROPIC_API_KEY : (string) mandala_ai_settings()['api_key'];
}
function mandala_ai_ready(): bool
{
    return mandala_ai_key() !== '';
}

/* ---------- A kategóriafa és a szűrők leírása (a Claude ebből választ) ---------- */

/** Nyitott listás szűrők: új érték is javasolható (a többi csak a meglévők közül). */
function mandala_ai_open_fields(): array
{
    return apply_filters('mandala_ai_open_fields', ['illat', 'forma', 'anyag', 'szin']);
}

function mandala_ai_taxonomy(): array
{
    $default = (int) get_option('default_product_cat');
    $cats = [];
    foreach (get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false]) as $main) {
        if ($main->term_id === $default) {
            continue;
        }
        $subs = get_terms(['taxonomy' => 'product_cat', 'parent' => $main->term_id, 'hide_empty' => false]);
        $cats[$main->slug] = ['name' => $main->name, 'description' => wp_strip_all_tags($main->description), 'subs' => array_combine(array_map(fn($t) => $t->slug, $subs), array_map(fn($t) => $t->name, $subs)) ?: []];
    }
    $fields = [];
    foreach (mandala_filter_schema() as $key => $f) {
        $values = [];
        if ($f['source'] === 'attr' && taxonomy_exists($f['key'])) {
            foreach (get_terms(['taxonomy' => $f['key'], 'hide_empty' => false]) as $t) {
                $values[$t->slug] = $t->name;
            }
        }
        $fields[$key] = $f + ['values' => $values, 'open' => in_array($key, mandala_ai_open_fields(), true)];
    }
    return ['categories' => $cats, 'fields' => $fields];
}

function mandala_ai_system_prompt(array $tax): string
{
    $lines = ['Egy magyar spirituális webáruház (Nepálból és Indiából importált hangtálak, füstölők, szakrális tárgyak, lakberendezés, ruházat, ajándékok) termékeit sorolod be az ÚJ kategóriafába, és kitöltöd a szűrőadatokat.',
        '',
        'Szabályok:',
        '- Csak a lent felsorolt kategóriák és értékek közül választhatsz (slug). Ha egy mező a termék adataiból nem állapítható meg biztosan, hagyd üresen, és írd a mező kulcsát az uncertain_fields listába. Ne találgass.',
        '- Számot (hz, suly) csak akkor adj, ha az adatokban szerepel (pl. „405 Hz”, „490 g”, „0,49 kg” → 490). Ne becsülj.',
        '- A régi kategória és tulajdonság erős jelzés, de a régi rendszer hibás is lehetett: a név és a leírás dönt.',
        '- confidence (0–1): 0.9 felett csak akkor, ha a fő- és alkategória egyértelmű ÉS minden, a kategóriájához kötelező szűrő adatokkal alátámasztott. Ha bármi bizonytalan, legyen 0.7 alatt.',
        '- note: egy rövid magyar mondat a döntés indokáról vagy arról, mit kell embernek ellenőriznie.',
        '- short_description_draft: csak ha a terméknek nincs rövid leírása – 1–2 tényszerű magyar mondat a megadott adatokból, túlzás és egészségügyi ígéret nélkül; különben üres.',
        '- Az eredményt kizárólag a ' . MANDALA_AI_TOOL . ' eszközzel add vissza, minden kapott termékre egy elemet.',
        '',
        'KATEGÓRIÁK (fő → al):'];
    foreach ($tax['categories'] as $slug => $c) {
        $lines[] = '- ' . $slug . ' (' . $c['name'] . ')' . ($c['description'] ? ': ' . $c['description'] : '');
        foreach ($c['subs'] as $sub => $name) {
            $lines[] = '    - ' . $sub . ' (' . $name . ')';
        }
    }
    $lines[] = '';
    $lines[] = 'SZŰRŐK:';
    foreach ($tax['fields'] as $key => $f) {
        $where = mandala_scope_match($f['scope'], '', '') ? 'minden terméknél' : 'csak: ' . implode(', ', array_merge((array) ($f['scope']['cats'] ?? []), (array) ($f['scope']['subs'] ?? [])));
        $req = $f['required'] === true ? 'kötelező mindenhol' : ($f['required'] ? 'kötelező: ' . implode(', ', array_merge((array) ($f['required']['cats'] ?? []), (array) ($f['required']['subs'] ?? []))) : 'nem kötelező');
        $vals = $f['values'] ? implode('; ', array_map(fn($s, $n) => $s . '=' . $n, array_keys($f['values']), $f['values'])) : ($f['source'] === 'meta' ? 'szám' . (isset($f['range']) ? ' (' . $f['range'][0] . '–' . $f['range'][1] . ')' : '') : '(még nincs érték)');
        $lines[] = '- ' . $key . ' – ' . $f['label'] . ' – ' . $where . ' – ' . $req . ($f['multiple'] ? ' – több érték is lehet' : ' – egy érték') . ($f['open'] ? ' – új érték is javasolható (magyar név)' : '') . '. Értékek: ' . $vals . '. ' . $f['hint'];
    }
    return implode("\n", $lines);
}

function mandala_ai_tool(array $tax): array
{
    $subs = [''];
    foreach ($tax['categories'] as $c) {
        $subs = array_merge($subs, array_keys($c['subs']));
    }
    $props = [];
    foreach ($tax['fields'] as $key => $f) {
        if ($f['source'] === 'meta') {
            $props[$key] = ['type' => ['number', 'null']];
        } else {
            $item = ['type' => 'string'];
            if (!$f['open'] && $f['values']) {
                $item['enum'] = array_keys($f['values']);
            }
            $props[$key] = ['type' => 'array', 'items' => $item];
        }
    }
    return [
        'name' => MANDALA_AI_TOOL,
        'description' => 'A termékek besorolása az új kategóriafába és a szűrőadatok.',
        'input_schema' => ['type' => 'object', 'required' => ['results'], 'properties' => ['results' => ['type' => 'array', 'items' => [
            'type' => 'object',
            'required' => ['product_id', 'category', 'subcategory', 'fields', 'confidence', 'uncertain_fields', 'note'],
            'properties' => [
                'product_id' => ['type' => 'integer'],
                'category' => ['type' => 'string', 'enum' => array_merge(array_keys($tax['categories']), [''])],
                'subcategory' => ['type' => 'string', 'enum' => array_values(array_unique($subs))],
                'fields' => ['type' => 'object', 'properties' => $props],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'uncertain_fields' => ['type' => 'array', 'items' => ['type' => 'string']],
                'note' => ['type' => 'string'],
                'short_description_draft' => ['type' => 'string'],
            ],
        ]]]],
    ];
}

/** A termék adatai a kéréshez (régi kategóriák és tulajdonságok is). */
function mandala_ai_product_payload(WC_Product $product): array
{
    $old_cats = [];
    foreach (get_the_terms($product->get_id(), 'product_cat') ?: [] as $term) {
        $path = array_reverse(array_map(fn($id) => get_term($id, 'product_cat')->name, get_ancestors($term->term_id, 'product_cat')));
        $old_cats[] = implode(' > ', array_merge($path, [$term->name]));
    }
    $attrs = [];
    foreach ($product->get_attributes('edit') as $attr) {
        $attrs[wc_attribute_label($attr->get_name())] = $attr->is_taxonomy() ? implode(', ', wc_get_product_terms($product->get_id(), $attr->get_name(), ['fields' => 'names'])) : implode(', ', $attr->get_options());
    }
    $desc = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($product->get_description('edit'))));
    return array_filter([
        'product_id' => $product->get_id(),
        'name' => $product->get_name('edit'),
        'sku' => $product->get_sku('edit'),
        'price_huf' => (float) $product->get_regular_price('edit'),
        'old_categories' => $old_cats,
        'old_attributes' => $attrs,
        'tags' => wp_get_post_terms($product->get_id(), 'product_tag', ['fields' => 'names']),
        'weight' => $product->get_weight('edit'),
        'short_description' => trim(wp_strip_all_tags($product->get_short_description('edit'))),
        'description' => mb_substr($desc, 0, 1500),
        'current_hz' => $product->get_meta('_mandala_hz', true, 'edit'),
        'current_weight_g' => $product->get_meta('_mandala_suly', true, 'edit'),
    ], fn($v) => $v !== '' && $v !== [] && $v !== null && $v !== 0.0);
}

/* ---------- API hívás ---------- */

/**
 * Egy köteg besorolása. Visszaad: ['results' => [id => javaslat], 'usage' => [...]] vagy WP_Error
 * (a 'retry_after' adatával, ha újrapróbálható).
 */
function mandala_ai_classify(array $products)
{
    if (!mandala_ai_ready()) {
        return new WP_Error('mandala_ai_key', 'Nincs beállítva Anthropic API-kulcs.');
    }
    $tax = mandala_ai_taxonomy();
    $s = mandala_ai_settings();
    $payload = array_map('mandala_ai_product_payload', $products);
    $body = [
        'model' => $s['model'],
        'max_tokens' => 1200 + 700 * count($products),
        'system' => [['type' => 'text', 'text' => mandala_ai_system_prompt($tax), 'cache_control' => ['type' => 'ephemeral']]],
        'tools' => [mandala_ai_tool($tax)],
        'tool_choice' => ['type' => 'tool', 'name' => MANDALA_AI_TOOL],
        'messages' => [['role' => 'user', 'content' => "Sorold be ezeket a termékeket:\n" . wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
    ];
    $response = wp_remote_post(apply_filters('mandala_ai_endpoint', 'https://api.anthropic.com/v1/messages'), [
        'timeout' => 120,
        'headers' => ['x-api-key' => mandala_ai_key(), 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'],
        'body' => wp_json_encode($body),
    ]);
    if (is_wp_error($response)) {
        return new WP_Error('mandala_ai_http', $response->get_error_message(), ['retry_after' => 60]);
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if ($code === 429 || $code === 529 || $code >= 500) {
        return new WP_Error('mandala_ai_busy', 'Az API túlterhelt vagy korlátozott (' . $code . ').', ['retry_after' => max(30, (int) wp_remote_retrieve_header($response, 'retry-after'))]);
    }
    if ($code !== 200 || !is_array($data)) {
        return new WP_Error('mandala_ai_api', 'API hiba (' . $code . '): ' . mb_substr((string) ($data['error']['message'] ?? wp_remote_retrieve_body($response)), 0, 300));
    }
    $input = null;
    foreach ((array) ($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'tool_use' && ($block['name'] ?? '') === MANDALA_AI_TOOL) {
            $input = $block['input'];
        }
    }
    if (!is_array($input['results'] ?? null)) {
        return new WP_Error('mandala_ai_format', 'A válasz nem tartalmazta a besorolást (stop_reason: ' . ($data['stop_reason'] ?? '?') . ').');
    }
    $by_id = [];
    foreach ($products as $p) {
        $by_id[$p->get_id()] = $p;
    }
    $results = [];
    foreach ($input['results'] as $r) {
        $id = (int) ($r['product_id'] ?? 0);
        if (isset($by_id[$id])) {
            $results[$id] = mandala_ai_normalize($r, $tax, $by_id[$id]);
        }
    }
    $u = (array) ($data['usage'] ?? []);
    return ['results' => $results, 'usage' => ['in' => (int) ($u['input_tokens'] ?? 0), 'out' => (int) ($u['output_tokens'] ?? 0), 'cache_read' => (int) ($u['cache_read_input_tokens'] ?? 0), 'cache_write' => (int) ($u['cache_creation_input_tokens'] ?? 0)], 'model' => (string) ($data['model'] ?? $s['model'])];
}

/** A válasz ellenőrzése a kategóriafa és a szűrők szerint, és a döntés (auto / review). */
function mandala_ai_normalize(array $r, array $tax, WC_Product $product): array
{
    $problems = [];
    $cat = (string) ($r['category'] ?? '');
    $sub = (string) ($r['subcategory'] ?? '');
    if (!isset($tax['categories'][$cat])) {
        $problems[] = 'kategória';
        $cat = $sub = '';
    } elseif ($sub !== '' && !isset($tax['categories'][$cat]['subs'][$sub])) {
        $problems[] = 'alkategória';
        $sub = '';
    } elseif ($sub === '' && $tax['categories'][$cat]['subs']) {
        $problems[] = 'alkategória';
    }
    $fields = [];
    $uncertain = array_map('strval', (array) ($r['uncertain_fields'] ?? []));
    foreach ($tax['fields'] as $key => $f) {
        $v = $r['fields'][$key] ?? null;
        if (!$cat || !mandala_scope_match($f['scope'], $cat, $sub)) {
            continue;
        }
        if ($f['source'] === 'meta') {
            $n = is_numeric($v) ? (float) $v : null;
            if ($n !== null && isset($f['range']) && ($n < $f['range'][0] || $n > $f['range'][1])) {
                $problems[] = $f['label'] . ' tartományon kívül';
                $n = null;
            }
            $fields[$key] = $n;
        } else {
            $vals = [];
            foreach ((array) $v as $val) {
                $val = trim((string) $val);
                if ($val === '') {
                    continue;
                }
                $slug = sanitize_title($val);
                if (isset($f['values'][$slug])) {
                    $vals[] = $slug;
                } elseif (($found = array_search(mb_strtolower($val), array_map('mb_strtolower', $f['values']), true)) !== false) {
                    $vals[] = $found;
                } elseif ($f['open']) {
                    $vals[] = 'new:' . mb_substr($val, 0, 40);
                } else {
                    $problems[] = $f['label'] . ': ismeretlen érték (' . $val . ')';
                }
            }
            $fields[$key] = $f['multiple'] ? array_values(array_unique($vals)) : array_slice($vals, 0, 1);
        }
    }
    $confidence = max(0, min(1, (float) ($r['confidence'] ?? 0)));
    $missing = [];
    foreach ($tax['fields'] as $key => $f) {
        if ($cat && mandala_scope_match($f['required'], $cat, $sub) && mandala_scope_match($f['scope'], $cat, $sub)) {
            $v = $fields[$key] ?? null;
            if ((is_array($v) && !$v) || $v === null || in_array($key, $uncertain, true)) {
                $missing[] = $f['label'];
            }
        }
    }
    $threshold = (float) mandala_ai_settings()['threshold'];
    $auto = $cat && !$problems && !$missing && $confidence >= $threshold;
    return [
        'cat' => $cat, 'sub' => $sub, 'fields' => $fields, 'confidence' => round($confidence, 2),
        'uncertain' => $uncertain, 'missing' => $missing, 'problems' => $problems,
        'note' => mb_substr(sanitize_text_field((string) ($r['note'] ?? '')), 0, 300),
        'short_draft' => $product->get_short_description('edit') ? '' : mb_substr(sanitize_textarea_field((string) ($r['short_description_draft'] ?? '')), 0, 400),
        'decision' => $auto ? 'auto' : 'review',
    ];
}

/* ---------- Alkalmazás és visszavonás ---------- */

function mandala_ai_snapshot(WC_Product $product): array
{
    $attrs = [];
    foreach ($product->get_attributes('edit') as $name => $a) {
        $attrs[$name] = ['id' => $a->get_id(), 'name' => $a->get_name(), 'options' => $a->get_options(), 'visible' => $a->get_visible(), 'variation' => $a->get_variation(), 'position' => $a->get_position()];
    }
    return ['cats' => $product->get_category_ids('edit'), 'attrs' => $attrs, 'hz' => $product->get_meta('_mandala_hz', true, 'edit'), 'suly' => $product->get_meta('_mandala_suly', true, 'edit'),
        'short' => $product->get_short_description('edit'), 'onboarding' => (string) $product->get_meta('_mandala_onboarding', true, 'edit')];
}

function mandala_ai_apply(WC_Product $product, array $sug, string $run = 'kézi'): void
{
    $s = mandala_ai_settings();
    $product->update_meta_data('_mandala_ai_undo', ['run' => $run, 'time' => time(), 'data' => mandala_ai_snapshot($product)]);
    $cats = mandala_category_ids($sug['cat'], $sug['sub']);
    if ($cats) {
        $default = (int) get_option('default_product_cat');
        $keep = $s['keep_old'] === 'yes' ? array_diff($product->get_category_ids('edit'), [$default]) : [];
        $product->set_category_ids(array_values(array_unique(array_merge($cats, $keep))));
    }
    $schema = mandala_filter_schema();
    foreach ($sug['fields'] as $key => $v) {
        $f = $schema[$key] ?? null;
        if (!$f) {
            continue;
        }
        if ($f['source'] === 'meta') {
            if ($v !== null) {
                $product->update_meta_data($f['key'], (string) $v);
            }
            continue;
        }
        $slugs = [];
        foreach ((array) $v as $val) {
            if (str_starts_with($val, 'new:')) {
                if ($s['create_terms'] !== 'yes' || !taxonomy_exists($f['key'])) {
                    continue;
                }
                $name = mb_strtoupper(mb_substr(substr($val, 4), 0, 1)) . mb_substr(substr($val, 4), 1);
                $term = term_exists(sanitize_title($name), $f['key']) ?: wp_insert_term($name, $f['key'], ['slug' => sanitize_title($name)]);
                if (is_wp_error($term)) {
                    continue;
                }
                $slugs[] = sanitize_title($name);
            } else {
                $slugs[] = $val;
            }
        }
        if ($slugs) {
            $existing = mandala_attr($product, $f['key'], 'slug');
            mandala_set_product_attr($product, $f['key'], $f['multiple'] ? array_values(array_unique(array_merge($existing, $slugs))) : $slugs);
        }
    }
    if (!empty($sug['short_draft']) && !$product->get_short_description('edit')) {
        $product->set_short_description($sug['short_draft']);
    }
    $product->update_meta_data('_mandala_ai_applied', ['run' => $run, 'time' => time()]);
    $GLOBALS['mandala_onboarding_skip'] = true;
    $product->save();
    unset($GLOBALS['mandala_onboarding_skip']);
}

function mandala_ai_undo(WC_Product $product): bool
{
    $undo = $product->get_meta('_mandala_ai_undo', true, 'edit');
    if (!is_array($undo) || empty($undo['data'])) {
        return false;
    }
    $d = $undo['data'];
    $product->set_category_ids((array) $d['cats']);
    $attrs = [];
    foreach ((array) $d['attrs'] as $name => $a) {
        $attr = new WC_Product_Attribute();
        $attr->set_id((int) $a['id']);
        $attr->set_name($a['name']);
        $attr->set_options($a['options']);
        $attr->set_visible($a['visible']);
        $attr->set_variation($a['variation']);
        $attr->set_position($a['position']);
        $attrs[$name] = $attr;
    }
    $product->set_attributes($attrs);
    foreach (['hz' => '_mandala_hz', 'suly' => '_mandala_suly'] as $k => $meta) {
        $d[$k] !== '' ? $product->update_meta_data($meta, $d[$k]) : $product->delete_meta_data($meta);
    }
    $product->set_short_description((string) $d['short']);
    $d['onboarding'] !== '' ? $product->update_meta_data('_mandala_onboarding', $d['onboarding']) : $product->delete_meta_data('_mandala_onboarding');
    $product->delete_meta_data('_mandala_ai_undo');
    $product->delete_meta_data('_mandala_ai_applied');
    $GLOBALS['mandala_onboarding_skip'] = true;
    $product->save();
    unset($GLOBALS['mandala_onboarding_skip']);
    return true;
}

/** Egy termék eredményének feldolgozása a futtatás módja szerint. */
function mandala_ai_handle_result(WC_Product $product, array $sug, string $run, string $mode): string
{
    $sug['run'] = $run;
    $sug['time'] = time();
    $product->update_meta_data('_mandala_ai', $sug);
    if ($mode === 'dry') {
        $product->save_meta_data();
        $dry = (array) get_option('mandala_ai_dry_' . $run, []);
        $dry[$product->get_id()] = $sug;
        update_option('mandala_ai_dry_' . $run, $dry, false);
        return $sug['decision'];
    }
    if ($sug['decision'] === 'auto') {
        mandala_ai_apply($product, $sug, $run);
        return 'auto';
    }
    // Bizonytalan: élő terméknél „ellenőrizendő”, új terméknél marad „új” – a javaslattal.
    $state = (string) $product->get_meta('_mandala_onboarding', true, 'edit');
    if ($state !== 'new') {
        $product->update_meta_data('_mandala_ai_undo', ['run' => $run, 'time' => time(), 'data' => mandala_ai_snapshot($product)]);
        $product->update_meta_data('_mandala_onboarding', 'review');
        if (!$product->get_meta('_mandala_onboarding_since', true, 'edit')) {
            $product->update_meta_data('_mandala_onboarding_since', time());
        }
    }
    $product->save_meta_data();
    wp_cache_delete('counts', 'mandala_onboarding');
    return 'review';
}

/* ---------- Futtatások (Action Scheduler) ---------- */

function mandala_ai_runs(): array
{
    return (array) get_option('mandala_ai_runs', []);
}
function mandala_ai_update_run(string $run, array $changes): array
{
    $runs = mandala_ai_runs();
    $runs[$run] = array_merge($runs[$run] ?? [], $changes);
    update_option('mandala_ai_runs', $runs, false);
    return $runs[$run];
}

/** A migráció köre: közzétett termékek (utalvány, jegy, csomagolás nélkül), amelyeket még nem dolgozott fel éles futtatás. */
function mandala_ai_scope(bool $include_done = false): array
{
    $args = ['post_type' => 'product', 'post_status' => ['publish', 'private'], 'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => ['relation' => 'AND',
            ['relation' => 'OR', ['key' => '_mandala_voucher', 'compare' => 'NOT EXISTS'], ['key' => '_mandala_voucher', 'value' => 'yes', 'compare' => '!=']],
            ['key' => '_mandala_ticket_for', 'compare' => 'NOT EXISTS'],
            ['relation' => 'OR', ['key' => '_mandala_giftbox', 'compare' => 'NOT EXISTS'], ['key' => '_mandala_giftbox', 'value' => 'yes', 'compare' => '!=']],
        ]];
    if (!$include_done) {
        $args['meta_query'][] = ['key' => '_mandala_ai_applied', 'compare' => 'NOT EXISTS'];
    }
    return array_map('intval', get_posts($args));
}

/** Futtatás indítása. $mode: dry (próbafuttatás, nem ír) | apply. */
function mandala_ai_start(string $mode, array $ids, string $label = '', bool $background = true): string
{
    $run = 'r' . gmdate('ymdHis') . wp_rand(10, 99);
    update_option('mandala_ai_queue_' . $run, array_values($ids), false);
    mandala_ai_update_run($run, ['mode' => $mode, 'label' => $label, 'status' => 'running', 'started' => time(), 'total' => count($ids), 'done' => 0, 'auto' => 0, 'review' => 0, 'errors' => 0,
        'in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0, 'model' => mandala_ai_settings()['model'], 'user' => get_current_user_id(), 'last_error' => '']);
    if ($background && function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('mandala_ai_process', [$run], MANDALA_AS_GROUP);
    }
    return $run;
}

/** Egy köteg feldolgozása; utána a következő köteg ütemezése (egymás után, nem párhuzamosan). */
function mandala_ai_process(string $run, bool $chain = true): array
{
    $info = mandala_ai_runs()[$run] ?? null;
    if (!$info || $info['status'] !== 'running') {
        return $info ?? [];
    }
    $queue = (array) get_option('mandala_ai_queue_' . $run, []);
    $batch = array_slice($queue, 0, max(1, (int) mandala_ai_settings()['batch']));
    if (!$batch) {
        delete_option('mandala_ai_queue_' . $run);
        mandala_flush_index();
        return mandala_ai_update_run($run, ['status' => 'done', 'finished' => time()]);
    }
    $products = array_values(array_filter(array_map('wc_get_product', $batch)));
    $result = $products ? mandala_ai_classify($products) : ['results' => [], 'usage' => []];
    if (is_wp_error($result)) {
        $tries = (int) ($info['tries'] ?? 0) + 1;
        $retry = $result->get_error_data()['retry_after'] ?? 0;
        if ($retry && $tries <= 5 && $chain && function_exists('as_schedule_single_action')) {
            mandala_ai_update_run($run, ['tries' => $tries, 'last_error' => $result->get_error_message()]);
            as_schedule_single_action(time() + $retry * $tries, 'mandala_ai_process', [$run], MANDALA_AS_GROUP);
            return mandala_ai_runs()[$run];
        }
        // Nem újrapróbálható (vagy elfogyott a próbálkozás): a köteg hibásként kimarad.
        update_option('mandala_ai_queue_' . $run, array_slice($queue, count($batch)), false);
        $info = mandala_ai_update_run($run, ['done' => $info['done'] + count($batch), 'errors' => $info['errors'] + count($batch), 'tries' => 0, 'last_error' => $result->get_error_message()]);
        if ($result->get_error_code() === 'mandala_ai_key') {
            return mandala_ai_update_run($run, ['status' => 'error']);
        }
    } else {
        $counts = ['auto' => 0, 'review' => 0];
        foreach ($products as $product) {
            $sug = $result['results'][$product->get_id()] ?? null;
            if ($sug) {
                $counts[mandala_ai_handle_result($product, $sug, $run, $info['mode'])]++;
            }
        }
        $missing = count($batch) - array_sum($counts);
        update_option('mandala_ai_queue_' . $run, array_slice($queue, count($batch)), false);
        $u = $result['usage'];
        $info = mandala_ai_update_run($run, ['done' => $info['done'] + count($batch), 'auto' => $info['auto'] + $counts['auto'], 'review' => $info['review'] + $counts['review'], 'errors' => $info['errors'] + $missing,
            'in' => $info['in'] + ($u['in'] ?? 0), 'out' => $info['out'] + ($u['out'] ?? 0), 'cache_read' => $info['cache_read'] + ($u['cache_read'] ?? 0), 'cache_write' => $info['cache_write'] + ($u['cache_write'] ?? 0), 'tries' => 0]);
    }
    if ($chain && function_exists('as_enqueue_async_action')) {
        as_enqueue_async_action('mandala_ai_process', [$run], MANDALA_AS_GROUP);
    }
    return $info;
}
add_action('mandala_ai_process', fn($run) => mandala_ai_process((string) $run));

/** Egy futtatás visszavonása: a futtatás által módosított termékek visszaállítása. */
function mandala_ai_undo_run(string $run): int
{
    $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_mandala_ai_undo', 'meta_value' => '"' . $run . '"', 'meta_compare' => 'LIKE']);
    $n = 0;
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if ($product && mandala_ai_undo($product)) {
            $n++;
        }
    }
    mandala_ai_update_run($run, ['status' => 'undone', 'undone' => time()]);
    wp_cache_delete('counts', 'mandala_onboarding');
    mandala_flush_index();
    return $n;
}

/** Becslés: a próbafuttatás tokenjei alapján a teljes körre. */
function mandala_ai_estimate(array $info, int $count): array
{
    $per = $info['done'] ? 1 / $info['done'] : 0;
    $in = (int) round(($info['in'] + $info['cache_write']) * $per * $count);
    $cached = (int) round($info['cache_read'] * $per * $count);
    $out = (int) round($info['out'] * $per * $count);
    $s = mandala_ai_settings();
    $usd = ($s['price_in'] !== '' && $s['price_out'] !== '') ? ($in * (float) $s['price_in'] + $cached * (float) $s['price_in'] * 0.1 + $out * (float) $s['price_out']) / 1e6 : null;
    return ['in' => $in, 'cached' => $cached, 'out' => $out, 'usd' => $usd, 'minutes' => (int) ceil($count / max(1, (int) $s['batch']) * 0.5)];
}

/* ---------- Új (JUTA) termékek: automatikus javaslat ---------- */

add_action('mandala_onboarding_new_product', function ($id) {
    if (mandala_ai_ready() && mandala_ai_settings()['auto_new'] === 'yes' && function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_ai_new_batch', [], MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + 2 * MINUTE_IN_SECONDS, 'mandala_ai_new_batch', [], MANDALA_AS_GROUP);
    }
});
add_action('mandala_ai_new_batch', function () {
    $ids = get_posts(['post_type' => 'product', 'post_status' => 'any', 'posts_per_page' => 40, 'fields' => 'ids',
        'meta_query' => [['key' => '_mandala_onboarding', 'value' => 'new'], ['key' => '_mandala_ai', 'compare' => 'NOT EXISTS']]]);
    if (!$ids) {
        return;
    }
    $run = mandala_ai_start('apply', $ids, 'Új termékek');
    mandala_ai_update_run($run, ['auto_new' => true]);
});

/* ---------- Termékszerkesztő: javaslat a dobozban ---------- */

function mandala_ai_suggestion_html(WC_Product $product, bool $actions = true, ?array $sug = null): string
{
    $sug ??= $product->get_meta('_mandala_ai', true, 'edit');
    if (!is_array($sug)) {
        return $actions && mandala_ai_ready() ? '<p><a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_ai_suggest&id=' . $product->get_id()), 'mandala_ai_' . $product->get_id())) . '">Claude javaslat kérése</a></p>' : '';
    }
    $schema = mandala_filter_schema();
    $vals = [];
    foreach ($sug['fields'] as $key => $v) {
        if ($v === null || $v === []) {
            continue;
        }
        $vals[] = ($schema[$key]['label'] ?? $key) . ': ' . (is_array($v) ? implode(', ', array_map(fn($x) => str_starts_with($x, 'new:') ? substr($x, 4) . ' (új)' : mandala_term_name($x, $schema[$key]['key']), $v)) : $v);
    }
    $out = '<div class="mandala-ai"><p><strong>Claude javaslat</strong> · ' . esc_html((int) round($sug['confidence'] * 100) . '%') . ($sug['decision'] === 'auto' ? ' · biztos' : ' · ellenőrizendő') . '</p>'
        . '<p>' . esc_html(($sug['cat'] ? mandala_term_name($sug['cat']) : '–') . ($sug['sub'] ? ' › ' . mandala_term_name($sug['sub']) : '')) . '</p>'
        . ($vals ? '<p class="description">' . esc_html(implode(' · ', $vals)) . '</p>' : '')
        . ($sug['missing'] ? '<p class="description">Bizonytalan: ' . esc_html(implode(', ', $sug['missing'])) . '</p>' : '')
        . ($sug['note'] ? '<p class="description"><em>' . esc_html($sug['note']) . '</em></p>' : '')
        . ($sug['short_draft'] ? '<p class="description">Rövid leírás vázlat: „' . esc_html($sug['short_draft']) . '”</p>' : '');
    if ($actions) {
        $out .= '<p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_ai_apply&id=' . $product->get_id()), 'mandala_ai_' . $product->get_id())) . '">Javaslat alkalmazása</a> '
            . (mandala_ai_ready() ? '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_ai_suggest&id=' . $product->get_id()), 'mandala_ai_' . $product->get_id())) . '">Új javaslat</a>' : '') . '</p>';
    }
    return $out . '</div>';
}
add_action('mandala_onboarding_metabox', fn(WC_Product $product) => print(mandala_ai_suggestion_html($product))); // phpcs:ignore

$mandala_ai_one = function (string $what) {
    $id = absint($_GET['id'] ?? 0);
    if (!current_user_can('edit_product', $id) || !check_admin_referer('mandala_ai_' . $id)) {
        wp_die('Nincs jogosultság.');
    }
    $product = wc_get_product($id);
    $msg = '';
    if ($what === 'suggest') {
        $result = mandala_ai_classify([$product]);
        if (is_wp_error($result)) {
            $msg = 'Claude: ' . $result->get_error_message();
        } elseif ($sug = $result['results'][$id] ?? null) {
            $sug['run'] = 'kézi';
            $sug['time'] = time();
            $product->update_meta_data('_mandala_ai', $sug);
            $product->save_meta_data();
            $msg = 'Új javaslat: ' . (int) round($sug['confidence'] * 100) . '%.';
        }
    } else {
        $sug = $product->get_meta('_mandala_ai', true, 'edit');
        if (is_array($sug)) {
            mandala_ai_apply($product, $sug, 'kézi');
            $msg = 'A javaslat alkalmazva – ellenőrizd a kategóriát és a tulajdonságokat.';
        }
    }
    if ($msg) {
        set_transient('mandala_onboarding_notice_' . get_current_user_id(), $msg, 60);
    }
    wp_safe_redirect(wp_get_referer() ?: get_edit_post_link($id, 'raw'));
    exit;
};
add_action('admin_post_mandala_ai_suggest', fn() => $mandala_ai_one('suggest'));
add_action('admin_post_mandala_ai_apply', fn() => $mandala_ai_one('apply'));

/* ---------- Az „Új termékek” oldal kiegészítései ---------- */

add_filter('mandala_onboarding_row_extra', function ($html, WC_Product $product) {
    $sug = $product->get_meta('_mandala_ai', true, 'edit');
    if (!is_array($sug)) {
        return $html;
    }
    return $html . '<div class="mandala-chips" style="margin-top:4px"><span class="mandala-chip ai" title="' . esc_attr($sug['note']) . '">Claude: ' . esc_html(($sug['sub'] ? mandala_term_name($sug['sub']) : ($sug['cat'] ? mandala_term_name($sug['cat']) : '?')) . ' · ' . (int) round($sug['confidence'] * 100) . '%') . '</span>'
        . ($sug['missing'] ? '<span class="mandala-chip">bizonytalan: ' . esc_html(implode(', ', $sug['missing'])) . '</span>' : '') . '</div>';
}, 10, 2);
add_filter('mandala_onboarding_bulk_options', fn($html) => $html . '<option value="ai_apply">Claude javaslat alkalmazása</option>');
add_action('mandala_onboarding_bulk_ai_apply', function ($id) {
    $product = wc_get_product($id);
    $sug = $product ? $product->get_meta('_mandala_ai', true, 'edit') : null;
    if (is_array($sug)) {
        mandala_ai_apply($product, $sug, 'kézi');
    }
});
add_filter('mandala_onboarding_tabs', fn($tabs) => $tabs + ['ai' => 'Claude migráció']);

add_action('mandala_onboarding_tab_ai', function (string $base) {
    $url = add_query_arg('tab', 'ai', $base);
    // Műveletek
    if (!empty($_POST['mandala_ai_do']) && check_admin_referer('mandala_ai')) {
        $do = sanitize_key($_POST['mandala_ai_do']);
        if ($do === 'settings') {
            $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_ai'] ?? []));
            $s = mandala_ai_settings();
            if (!empty($in['api_key']) && !str_starts_with($in['api_key'], '•')) {
                $s['api_key'] = $in['api_key'];
            }
            if (!empty($_POST['mandala_ai_forget_key'])) {
                $s['api_key'] = '';
            }
            $s['model'] = preg_replace('/[^a-z0-9.\-]/', '', strtolower($in['model'] ?? $s['model'])) ?: 'claude-opus-5-5';
            $s['threshold'] = min(1, max(0.5, (float) ($in['threshold'] ?? 0.85)));
            $s['batch'] = min(20, max(1, (int) ($in['batch'] ?? 8)));
            foreach (['keep_old', 'create_terms', 'auto_new'] as $k) {
                $s[$k] = empty($in[$k]) ? 'no' : 'yes';
            }
            $s['price_in'] = is_numeric($in['price_in'] ?? '') ? (string) (float) $in['price_in'] : '';
            $s['price_out'] = is_numeric($in['price_out'] ?? '') ? (string) (float) $in['price_out'] : '';
            update_option('mandala_ai', $s, false);
            echo '<div class="notice notice-success"><p>Mentve.</p></div>';
        } elseif ($do === 'dry' && mandala_ai_ready()) {
            $scope = mandala_ai_scope();
            shuffle($scope);
            mandala_ai_start('dry', array_slice($scope, 0, max(5, min(100, absint($_POST['sample'] ?? 20)))), 'Próbafuttatás');
        } elseif ($do === 'apply' && mandala_ai_ready()) {
            mandala_ai_start('apply', mandala_ai_scope(!empty($_POST['include_done'])), 'Migráció');
        } elseif ($do === 'stop') {
            mandala_ai_update_run(sanitize_key($_POST['run'] ?? ''), ['status' => 'stopped']);
        } elseif ($do === 'undo') {
            $n = mandala_ai_undo_run(sanitize_key($_POST['run'] ?? ''));
            echo '<div class="notice notice-success"><p>' . esc_html(sprintf('%d termék visszaállítva.', $n)) . '</p></div>';
        }
    }
    $s = mandala_ai_settings();
    $runs = mandala_ai_runs();
    $running = array_filter($runs, fn($r) => $r['status'] === 'running');
    if ($running) {
        echo '<meta http-equiv="refresh" content="10">';
    }
    $scope_count = count(mandala_ai_scope());
    echo '<p>A meglévő termékek régi kategóriáiból és tulajdonságaiból a Claude javaslatot ad az új kategóriafára és a szűrőkre. Ahol biztos (≥ ' . esc_html((string) round($s['threshold'] * 100)) . '%, és minden kötelező szűrő megvan), a javaslat érvénybe lép; ahol nem, a termék az „Élő, ellenőrizendő” fülre kerül, előtöltött javaslattal. Előbb futtass próbát: az semmit nem ír, és becslést ad a teljes futtatásra.</p>';
    echo '<p><strong>' . esc_html(sprintf('%d termék', $scope_count)) . '</strong> vár feldolgozásra (közzétett, még nem migrált; utalvány, jegy és csomagolás nélkül).</p>';
    if (!mandala_ai_ready()) {
        echo '<div class="notice notice-warning inline"><p>Adj meg Anthropic API-kulcsot lent, vagy a wp-config.php-ban: <code>define(\'MANDALA_ANTHROPIC_API_KEY\', \'…\');</code> (ajánlott).</p></div>';
    }
    echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:16px 0">';
    wp_nonce_field('mandala_ai');
    $dry_done = array_filter($runs, fn($r) => $r['mode'] === 'dry' && $r['status'] === 'done');
    echo '<label>Minta: <input type="number" name="sample" value="20" min="5" max="100" style="width:70px"> termék</label> <button class="button" name="mandala_ai_do" value="dry"' . disabled(!mandala_ai_ready() || $running, true, false) . '>Próbafuttatás</button> '
        . '<button class="button button-primary" name="mandala_ai_do" value="apply" onclick="return confirm(\'A teljes migráció indul: a biztos javaslatok érvénybe lépnek (futtatásonként visszavonható). Indulhat?\')"' . disabled(!mandala_ai_ready() || $running || !$dry_done, true, false) . '>Teljes migráció indítása</button>'
        . ($dry_done ? '' : ' <span class="description">(előbb próbafuttatás)</span>') . ' <label><input type="checkbox" name="include_done" value="1"> a már migráltakat is</label></form>';

    // Futtatások
    if ($runs) {
        echo '<h2>Futtatások</h2><table class="widefat striped"><thead><tr><th>Futtatás</th><th>Állapot</th><th>Kész</th><th>Automatikus</th><th>Ellenőrizendő</th><th>Hiba</th><th>Tokenek (be / gyors. / ki)</th><th></th></tr></thead><tbody>';
        foreach (array_reverse($runs, true) as $id => $r) {
            $status = ['running' => 'fut…', 'done' => 'kész', 'stopped' => 'leállítva', 'undone' => 'visszavonva', 'error' => 'hiba'][$r['status']] ?? $r['status'];
            echo '<tr><td>' . esc_html(($r['label'] ?: $r['mode']) . ' · ' . wp_date('m. d. H:i', $r['started']) . ' · ' . $r['model']) . '</td><td>' . esc_html($status) . ($r['last_error'] ? '<br><span class="description">' . esc_html($r['last_error']) . '</span>' : '') . '</td>'
                . '<td>' . (int) $r['done'] . ' / ' . (int) $r['total'] . '</td><td>' . (int) $r['auto'] . ($r['mode'] === 'dry' ? ' <span class="description">(nem írt)</span>' : '') . '</td><td>' . (int) $r['review'] . '</td><td>' . (int) $r['errors'] . '</td>'
                . '<td>' . esc_html(number_format_i18n($r['in'] + $r['cache_write']) . ' / ' . number_format_i18n($r['cache_read']) . ' / ' . number_format_i18n($r['out'])) . '</td><td><form method="post">';
            wp_nonce_field('mandala_ai');
            echo '<input type="hidden" name="run" value="' . esc_attr($id) . '">'
                . ($r['status'] === 'running' ? '<button class="button" name="mandala_ai_do" value="stop">Leállítás</button>' : '')
                . ($r['mode'] === 'apply' && in_array($r['status'], ['done', 'stopped', 'error'], true) ? '<button class="button" name="mandala_ai_do" value="undo" onclick="return confirm(\'A futtatás minden változtatása visszaáll. Biztos?\')">Visszavonás</button>' : '')
                . '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    // Az utolsó próbafuttatás eredménye és becslés
    $last_dry = null;
    foreach (array_reverse($runs, true) as $id => $r) {
        if ($r['mode'] === 'dry' && $r['done']) {
            $last_dry = $id;
            break;
        }
    }
    if ($last_dry) {
        $r = $runs[$last_dry];
        $e = mandala_ai_estimate($r, $scope_count);
        echo '<h2>Próbafuttatás eredménye</h2><p>' . esc_html(sprintf('%d termékből %d lenne automatikus (%d%%), %d ellenőrizendő.', $r['done'], $r['auto'], $r['done'] ? round($r['auto'] / $r['done'] * 100) : 0, $r['review']))
            . ' ' . esc_html(sprintf('Becslés a teljes körre (%d termék): ~%s bemeneti, ~%s gyorsítótárból olvasott, ~%s kimeneti token, kb. %d perc.', $scope_count, number_format_i18n($e['in']), number_format_i18n($e['cached']), number_format_i18n($e['out']), $e['minutes']))
            . ($e['usd'] !== null ? ' ' . esc_html(sprintf('Költség: kb. %s USD (a megadott egységárakkal).', number_format_i18n($e['usd'], 2))) : ' <span class="description">(Költséghez add meg lent az egységárakat.)</span>') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>Termék</th><th>Régi kategória</th><th>Javaslat</th><th>Megbízhatóság</th><th>Döntés</th></tr></thead><tbody>';
        foreach ((array) get_option('mandala_ai_dry_' . $last_dry, []) as $pid => $sug) {
            $product = wc_get_product($pid);
            if (!$product) {
                continue;
            }
            $old = implode(', ', wp_get_post_terms($pid, 'product_cat', ['fields' => 'names']));
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($pid)) . '">' . esc_html($product->get_name()) . '</a></td><td>' . esc_html($old) . '</td><td>' . mandala_ai_suggestion_html($product, false, $sug) . '</td><td>' . (int) round($sug['confidence'] * 100) . '%</td><td>' . ($sug['decision'] === 'auto' ? 'automatikus' : 'ellenőrizendő') . '</td></tr>'; // phpcs:ignore
        }
        echo '</tbody></table>';
    }

    // Beállítások
    $key_set = mandala_ai_key() !== '';
    echo '<h2>Beállítások</h2><form method="post">';
    wp_nonce_field('mandala_ai');
    echo '<input type="hidden" name="mandala_ai_do" value="settings"><table class="form-table">'
        . '<tr><th scope="row"><label for="ai-key">Anthropic API-kulcs</label></th><td>' . (defined('MANDALA_ANTHROPIC_API_KEY') ? '<p>A wp-config.php-ból (MANDALA_ANTHROPIC_API_KEY).</p>' : '<input type="password" id="ai-key" name="mandala_ai[api_key]" class="regular-text" autocomplete="off" value="' . ($key_set ? '••••••••' : '') . '">' . ($key_set ? ' <label><input type="checkbox" name="mandala_ai_forget_key" value="1"> kulcs törlése</label>' : '') . '<p class="description">Biztonságosabb a wp-config.php-ban megadni.</p>') . '</td></tr>'
        . '<tr><th scope="row"><label for="ai-model">Modell</label></th><td><input type="text" id="ai-model" name="mandala_ai[model]" value="' . esc_attr($s['model']) . '" list="ai-models" class="regular-text"><datalist id="ai-models"><option value="claude-opus-5-5"><option value="claude-sonnet-5"><option value="claude-haiku-4-5-20251001"></datalist><p class="description">Alapból a legpontosabb modell. Nagy tömegnél a próbafuttatással érdemes összevetni egy olcsóbbal.</p></td></tr>'
        . '<tr><th scope="row"><label for="ai-th">Automatikus, ha a megbízhatóság legalább</label></th><td><input type="number" step="0.01" min="0.5" max="1" id="ai-th" name="mandala_ai[threshold]" value="' . esc_attr((string) $s['threshold']) . '" style="width:80px"> <span class="description">(0,5–1; alatta a termék ellenőrizendő)</span></td></tr>'
        . '<tr><th scope="row"><label for="ai-batch">Termék / kérés</label></th><td><input type="number" min="1" max="20" id="ai-batch" name="mandala_ai[batch]" value="' . esc_attr((string) $s['batch']) . '" style="width:80px"></td></tr>'
        . '<tr><th scope="row">Régi kategóriák</th><td><label><input type="checkbox" name="mandala_ai[keep_old]" value="1"' . checked($s['keep_old'], 'yes', false) . '> megmaradnak a termék mellett (a régi URL-ek és a SEO miatt; élesítés után külön bonthatók, átirányítással)</label></td></tr>'
        . '<tr><th scope="row">Új szűrőértékek</th><td><label><input type="checkbox" name="mandala_ai[create_terms]" value="1"' . checked($s['create_terms'], 'yes', false) . '> nyitott listás szűrőknél (' . esc_html(implode(', ', mandala_ai_open_fields())) . ') új érték létrehozható</label></td></tr>'
        . '<tr><th scope="row">Új JUTA termékek</th><td><label><input type="checkbox" name="mandala_ai[auto_new]" value="1"' . checked($s['auto_new'], 'yes', false) . '> érkezéskor javaslat; ha biztos, a kategória és a szűrők előtöltődnek (élesíteni így is kézzel kell)</label></td></tr>'
        . '<tr><th scope="row">Egységárak a becsléshez</th><td><input type="text" name="mandala_ai[price_in]" value="' . esc_attr($s['price_in']) . '" style="width:80px" placeholder="be"> / <input type="text" name="mandala_ai[price_out]" value="' . esc_attr($s['price_out']) . '" style="width:80px" placeholder="ki"> USD / millió token <span class="description">(az aktuális árlista szerint; nem kötelező)</span></td></tr>'
        . '</table>';
    submit_button('Mentés');
    echo '</form>';
});

/* ---------- WP-CLI ---------- */

if (defined('WP_CLI') && WP_CLI) {
    /**
     * Claude-os migráció parancssorból (a háttérfeladatok nélkül, azonnal).
     *
     * ## OPTIONS
     * [--dry-run]      : próbafuttatás (nem ír)
     * [--limit=<n>]    : legfeljebb ennyi termék
     * [--ids=<ids>]    : csak ezek (vesszővel)
     * [--all]          : a már migráltakat is
     */
    WP_CLI::add_command('mandala ai-migrate', function ($args, $assoc) {
        $ids = !empty($assoc['ids']) ? array_map('absint', explode(',', $assoc['ids'])) : mandala_ai_scope(!empty($assoc['all']));
        if (!empty($assoc['limit'])) {
            $ids = array_slice($ids, 0, (int) $assoc['limit']);
        }
        $run = mandala_ai_start(!empty($assoc['dry-run']) ? 'dry' : 'apply', $ids, 'WP-CLI', false);
        do {
            $info = mandala_ai_process($run, false);
            WP_CLI::log(sprintf('%d / %d · automatikus %d · ellenőrizendő %d · hiba %d', $info['done'], $info['total'], $info['auto'], $info['review'], $info['errors']));
        } while (($info['status'] ?? '') === 'running' && $info['done'] < $info['total']);
        $info = mandala_ai_process($run, false); // lezárás
        if (($info['status'] ?? '') === 'error' || ($info['errors'] ?? 0)) {
            WP_CLI::warning('Hiba: ' . ($info['last_error'] ?? '?'));
        }
        if (($info['status'] ?? '') === 'error') {
            WP_CLI::error('Futtatás: ' . $run . ' – megszakadt.');
        }
        WP_CLI::success('Futtatás: ' . $run);
    });
    /** Egy futtatás visszavonása. ## OPTIONS <run> : a futtatás azonosítója */
    WP_CLI::add_command('mandala ai-undo', function ($args) {
        WP_CLI::success(mandala_ai_undo_run((string) $args[0]) . ' termék visszaállítva.');
    });
}
