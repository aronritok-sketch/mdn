<?php
/**
 * Régi bolt beállításainak átvétele (WooCommerce → Régi bolt beállításai).
 *
 * 1. A régi bolt adminjában a böngészőkonzolba másolt szkript (oldsettings-export.js) a bolt saját REST
 *    API-jából kiolvassa a beállításokat, és letölt egy JSON fájlt. A régi boltban semmit nem módosít.
 * 2. Itt a fájl feltöltése után előnézet: csoportonként mi változik; a kiválasztott részek átvétele.
 *
 * Átvehető: általános / termék / adó / szállítási / fiók / levél WooCommerce-beállítások, fizetési módok
 * (csak az itt is telepített fizetési bővítményeké – a többit listázza), szállítási zónák díjakkal,
 * adókulcsok, webhely neve, időzóna, dátumformátum. Nem veszi át: oldalazonosítókat (itt a téma oldalai
 * vannak), a levelek színeit / fejlécképét (új dizájn), a speciális fül végpontjait (alapból nincs bejelölve).
 * Visszavonható: átvétel előtt a módosított beállításokról és a szállítási zónákról mentés készül.
 */

defined('ABSPATH') || exit;

const MANDALA_OLDSET_PENDING = 'mandala_oldset_pending';
const MANDALA_OLDSET_BACKUP = 'mandala_oldset_backup';

/** Beolvasás és alapellenőrzés. */
function mandala_oldset_parse(string $json)
{
    $d = json_decode($json, true);
    if (is_array($d) && (int) ($d['v'] ?? 0) === 1 && !empty($d['legal']) && !isset($d['gateways'])) { // csak a jogi oldalak
        return $d + ['wc' => [], 'gateways' => [], 'zones' => []];
    }
    if (!is_array($d) || (int) ($d['v'] ?? 0) !== 1 || !isset($d['wc'], $d['gateways'], $d['zones'])) {
        return new WP_Error('mandala_oldset', 'Ez nem a régi bolt beállítás-exportja (mandala-regi-beallitasok.json).');
    }
    return $d;
}

/** Egy WooCommerce-beállítás option neve és értéke – vagy null, ha nem vesszük át. */
function mandala_oldset_target(string $group, array $s): ?array
{
    $id = (string) ($s['id'] ?? '');
    if ($id === '' || preg_match('/_page_id$/', $id) || $id === 'woocommerce_placeholder_image') {
        return null; // oldalak és médiatár-azonosítók: itt a téma saját oldalai / képei vannak
    }
    if (str_starts_with($group, 'email_')) { // egy-egy levél beállítása: woocommerce_{levél}_settings[kulcs]
        return ['woocommerce_' . substr($group, 6) . '_settings', $id];
    }
    if (!str_starts_with($id, 'woocommerce_')) {
        return null;
    }
    if ($group === 'email' && preg_match('/(_color|header_image|email_font|logo_image|header_alignment)/', $id)) {
        return null; // a levelek megjelenése az új dizájné
    }
    return [$id, null];
}

/** Az aktuális érték egy célhoz. */
function mandala_oldset_current(array $t)
{
    [$opt, $key] = $t;
    $v = get_option($opt, null);
    return $key === null ? $v : (is_array($v) ? ($v[$key] ?? null) : null);
}

/** Csoportcímkék (a nem ismert levélcsoportok a levél nevével). */
function mandala_oldset_group_label(string $g): string
{
    $labels = ['general' => 'Általános', 'products' => 'Termékek', 'tax' => 'Adó', 'shipping' => 'Szállítás (általános)', 'account' => 'Fiókok és adatvédelem',
        'email' => 'Levelek (feladó, lábléc)', 'advanced' => 'Speciális (végpontok, API)'];
    if (isset($labels[$g])) {
        return $labels[$g];
    }
    return str_starts_with($g, 'email_') ? 'Levél: ' . str_replace('_', ' ', substr($g, 6)) : $g;
}

/** Előnézet: mi változna. */
function mandala_oldset_plan(array $d): array
{
    $plan = ['groups' => [], 'gateways' => [], 'zones' => [], 'taxes' => 0, 'tax_note' => '', 'wp' => [], 'plugins_missing' => [], 'legal' => []];
    foreach ((array) $d['wc'] as $group => $list) {
        $rows = [];
        foreach ((array) $list as $s) {
            $t = is_array($s) ? mandala_oldset_target((string) $group, $s) : null;
            if (!$t) {
                continue;
            }
            $cur = mandala_oldset_current($t);
            if (wp_json_encode($cur) !== wp_json_encode($s['value'] ?? null)) {
                $rows[] = ['label' => (string) ($s['label'] ?? $s['id']), 'id' => (string) $s['id'], 'old' => $cur, 'new' => $s['value'] ?? null];
            }
        }
        if ($rows) {
            $plan['groups'][$group] = $rows;
        }
    }
    $available = function_exists('WC') ? WC()->payment_gateways()->payment_gateways() : [];
    foreach ((array) $d['gateways'] as $g) {
        $plan['gateways'][] = ['id' => $g['id'], 'title' => (string) ($g['title'] ?: $g['method_title']), 'enabled' => !empty($g['enabled']), 'available' => isset($available[$g['id']])];
    }
    $methods = function_exists('WC') ? WC()->shipping()->get_shipping_methods() : [];
    foreach ((array) $d['zones'] as $z) {
        $plan['zones'][] = ['name' => (string) $z['name'], 'locations' => count((array) $z['locations']),
            'methods' => array_map(fn($m) => ['title' => (string) $m['title'], 'id' => (string) $m['method_id'], 'available' => isset($methods[$m['method_id']]), 'enabled' => !empty($m['enabled'])], (array) $z['methods'])];
    }
    foreach (mandala_oldset_legal_targets((array) ($d['legal'] ?? [])) as $role => $t) {
        $plan['legal'][] = ['title' => $t['title'], 'chars' => mb_strlen(wp_strip_all_tags((string) $t['src']['html'])), 'target' => $t['id'] ? 'a mostani „' . get_the_title($t['id']) . '” oldal helyére' : 'új oldal'];
    }
    $plan['taxes'] = count((array) ($d['taxes'] ?? []));
    global $wpdb;
    if ($plan['taxes'] && (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates")) {
        $plan['tax_note'] = 'Ebben a boltban már vannak adókulcsok – az adókulcsokat nem írjuk felül (kézzel: WooCommerce → Beállítások → Adó).';
    }
    foreach (['title' => 'blogname', 'description' => 'blogdescription', 'timezone' => 'timezone_string', 'date_format' => 'date_format', 'time_format' => 'time_format', 'start_of_week' => 'start_of_week'] as $k => $opt) {
        if (isset($d['wp'][$k]) && (string) $d['wp'][$k] !== (string) get_option($opt)) {
            $plan['wp'][$opt] = ['old' => get_option($opt), 'new' => $d['wp'][$k]];
        }
    }
    if (!empty($d['plugins'])) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $here = array_map(fn($f) => dirname($f), array_keys(get_plugins()));
        foreach ((array) $d['plugins'] as $p) {
            // A költöztető segéd csak a régi boltba kell – itt nem hiányzik
            if (($p['status'] ?? '') === 'active' && !in_array(dirname((string) $p['plugin']), $here, true) && dirname((string) $p['plugin']) !== 'mandala-koltozes') {
                $plan['plugins_missing'][] = (string) $p['name'];
            }
        }
    }
    return $plan;
}

/* ---------- Szállítási zónák mentése / betöltése (az átvételhez és a visszavonáshoz) ---------- */

function mandala_oldset_zones_export(): array
{
    global $wpdb;
    $out = [];
    $zones = WC_Shipping_Zones::get_zones();
    $zones[] = ['id' => 0, 'zone_name' => '', 'zone_order' => 0];
    foreach ($zones as $zd) {
        $zone = new WC_Shipping_Zone((int) ($zd['id'] ?? $zd['zone_id'] ?? 0));
        $methods = [];
        foreach ($zone->get_shipping_methods(false) as $m) {
            $methods[] = ['method_id' => $m->id, 'instance_id' => (int) $m->instance_id, 'title' => $m->get_title(), 'enabled' => $m->is_enabled(), 'order' => (int) $m->method_order, 'settings' => (array) get_option($m->get_instance_option_key(), [])];
        }
        $out[] = ['id' => $zone->get_id(), 'name' => $zone->get_zone_name(), 'order' => $zone->get_zone_order(),
            'locations' => array_map(fn($l) => ['code' => $l->code, 'type' => $l->type], $zone->get_zone_locations()), 'methods' => $methods];
    }
    return $out;
}
/** Szállítási módokra hivatkozó listák (pl. utánvét: „flat_rate:10”) átírása a régi példányazonosítóról az újra. */
function mandala_oldset_remap(array $settings, array $map): array
{
    foreach ($settings as $k => $v) {
        if (is_array($v) && $map) {
            $settings[$k] = array_values(array_map(fn($x) => is_string($x) && isset($map[$x]) ? $map[$x] : $x, $v));
        }
    }
    return $settings;
}
/**
 * A zónák teljes cseréje a megadottakra. Visszaad: ['missing' => hiányzó szállítási módok,
 * 'map' => régi „módazonosító:példány” → új] – a fizetési módok (pl. utánvét „csak ezeknél a szállítási
 * módoknál”) így a régi példányazonosítók helyett az újakra mutatnak.
 */
function mandala_oldset_zones_import(array $zones): array
{
    global $wpdb;
    foreach (WC_Shipping_Zones::get_zones() as $z) {
        WC_Shipping_Zones::delete_zone((int) $z['id']);
    }
    $rest = new WC_Shipping_Zone(0);
    foreach ($rest->get_shipping_methods(false) as $m) {
        $rest->delete_shipping_method($m->instance_id);
    }
    $available = WC()->shipping()->get_shipping_methods();
    $missing = [];
    $map = [];
    foreach ($zones as $z) {
        $zone = (int) $z['id'] === 0 ? new WC_Shipping_Zone(0) : new WC_Shipping_Zone();
        if ((int) $z['id'] !== 0) {
            $zone->set_zone_name((string) $z['name']);
            $zone->set_zone_order((int) ($z['order'] ?? 0));
            $zone->set_locations(array_map(fn($l) => ['code' => (string) $l['code'], 'type' => (string) $l['type']], (array) $z['locations']));
            $zone->save();
        }
        foreach ((array) $z['methods'] as $m) {
            if (!isset($available[$m['method_id']])) {
                $missing[] = $m['title'] . ' (' . $m['method_id'] . ')';
                continue;
            }
            $iid = $zone->add_shipping_method((string) $m['method_id']);
            if (!$iid) {
                continue;
            }
            update_option('woocommerce_' . $m['method_id'] . '_' . $iid . '_settings', (array) $m['settings']);
            if (!empty($m['instance_id'])) {
                $map[$m['method_id'] . ':' . (int) $m['instance_id']] = $m['method_id'] . ':' . $iid;
            }
            $wpdb->update($wpdb->prefix . 'woocommerce_shipping_zone_methods', ['is_enabled' => empty($m['enabled']) ? 0 : 1, 'method_order' => (int) ($m['order'] ?? 0)], ['instance_id' => $iid]);
        }
    }
    WC_Cache_Helper::get_transient_version('shipping', true);
    return ['missing' => array_values(array_unique($missing)), 'map' => $map];
}

/* ---------- Átvétel és visszavonás ---------- */

/** $parts: a kiválasztott csoportok + 'gateways', 'zones', 'taxes', 'legal', 'wp'. Visszaad: napló (sorok). */
function mandala_oldset_apply(array $d, array $parts): array
{
    $log = [];
    $backup = ['time' => time(), 'options' => [], 'zones' => null, 'tax_rates' => [], 'tax_classes' => [], 'posts' => [], 'created_posts' => [], 'menu_items' => []];
    $set = function (string $opt, $value, ?string $key = null) use (&$backup) {
        if (!array_key_exists($opt, $backup['options'])) {
            $backup['options'][$opt] = get_option($opt, null);
        }
        if ($key === null) {
            update_option($opt, $value);
        } else {
            $cur = (array) get_option($opt, []);
            $cur[$key] = $value;
            update_option($opt, $cur);
        }
    };
    // WooCommerce-beállítások
    $n = 0;
    foreach ((array) $d['wc'] as $group => $list) {
        if (!in_array($group, $parts, true)) {
            continue;
        }
        foreach ((array) $list as $s) {
            $t = is_array($s) ? mandala_oldset_target((string) $group, $s) : null;
            if ($t && wp_json_encode(mandala_oldset_current($t)) !== wp_json_encode($s['value'] ?? null)) {
                $set($t[0], $s['value'] ?? null, $t[1]);
                $n++;
            }
        }
    }
    if ($n) {
        $log[] = $n . ' WooCommerce-beállítás átvéve.';
    }
    $map = [];
    // Szállítási zónák
    if (in_array('zones', $parts, true) && !empty($d['zones'])) {
        $backup['zones'] = mandala_oldset_zones_export();
        $res = mandala_oldset_zones_import((array) $d['zones']);
        $map = $res['map'];
        $missing = $res['missing'];
        $log[] = count((array) $d['zones']) . ' szállítási zóna átvéve (a korábbiak helyett).';
        if ($missing) {
            $log[] = 'Szállítási módok, amelyek bővítménye itt nincs telepítve (kimaradtak): ' . implode(', ', $missing) . '.';
        }
        if (function_exists('mandala_shipping_zone_warnings')) { // a régi bolt zónahibái is átjönnek – szóljunk róluk
            foreach (mandala_shipping_zone_warnings() as $w) {
                $log[] = 'FIGYELEM: ' . $w;
            }
        }
    }
    // Fizetési módok
    if (in_array('gateways', $parts, true)) {
        $available = WC()->payment_gateways()->payment_gateways();
        $order = (array) get_option('woocommerce_gateway_order', []);
        $done = [];
        $missing = [];
        foreach ((array) $d['gateways'] as $g) {
            if (!isset($available[$g['id']])) {
                if (!empty($g['enabled'])) {
                    $missing[] = (string) ($g['title'] ?: $g['method_title']);
                }
                continue;
            }
            $opt = 'woocommerce_' . $g['id'] . '_settings';
            $imported = mandala_oldset_remap((array) $g['settings'], $map);
            // A régi bolt pénztár / kosár / fiók címei (pl. a Teya visszatérési címei) → az itteni oldalak
            foreach ($imported as $k => $v) {
                if (is_string($v) && !empty($d['source']) && preg_match('#^https?://(?:www\.)?' . preg_quote(preg_replace('#^https?://(?:www\.)?#i', '', untrailingslashit((string) $d['source'])), '#') . '(/[^?\s]*)?(\?.*)?$#i', $v, $um)
                    && function_exists('mandala_od_legacy_url') && ($local = mandala_od_legacy_url(($um[1] ?? '/') . ($um[2] ?? '')))) {
                    $imported[$k] = $local;
                }
            }
            $settings = array_merge((array) get_option($opt, []), $imported, ['enabled' => empty($g['enabled']) ? 'no' : 'yes']);
            if (($g['title'] ?? '') !== '') {
                $settings['title'] = $g['title'];
            }
            // A régi bolt a díjat a címben mutatta („Utánvétes fizetés (390 Ft)”, külön díjbővítménnyel) –
            // itt a téma számolja fel: a díj a Mandala bolt adataiba kerül, a címből kikerül (különben kétszer látszana).
            if ($g['id'] === 'cod' && preg_match('/\s*\(\s*(\d[\d\s.]*)\s*Ft\s*\)/u', (string) $settings['title'], $fm) && function_exists('mandala_config')) {
                $fee = (int) preg_replace('/\D/', '', $fm[1]);
                $payment = (array) mandala_config('payment', []);
                foreach ($payment as &$p) {
                    if (($p['id'] ?? '') === 'cod') {
                        $p['fee'] = $fee;
                    }
                }
                unset($p);
                $set('mandala_payment', $payment);
                $settings['title'] = trim(str_replace($fm[0], '', (string) $settings['title']));
                $log[] = 'Utánvét díja: ' . $fee . ' Ft (a régi bolt címéből; a téma számolja fel, a cím: „' . $settings['title'] . '”).';
            }
            if (isset($g['description'])) {
                $settings['description'] = $g['description'];
            }
            $set($opt, $settings);
            $order[$g['id']] = (int) ($g['order'] ?? 0);
            $done[] = ($g['title'] ?: $g['id']) . (empty($g['enabled']) ? ' (kikapcsolva)' : '');
        }
        if ($done) {
            $set('woocommerce_gateway_order', $order);
            $log[] = 'Fizetési módok: ' . implode(', ', $done) . '.';
        }
        if ($missing) {
            $log[] = 'NEM vehető át, mert a bővítménye itt nincs telepítve: ' . implode(', ', $missing) . ' – telepítsd, és futtasd újra az átvételt.';
        }
    }
    // Adók – csak ha még nincs itt adókulcs
    if (in_array('taxes', $parts, true) && !empty($d['taxes'])) {
        global $wpdb;
        if ((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates")) {
            $log[] = 'Adókulcsok: ebben a boltban már vannak, nem írtuk felül.';
        } else {
            $existing = array_map(fn($c) => (string) $c->slug, WC_Tax::get_tax_rate_classes());
            foreach ((array) ($d['tax_classes'] ?? []) as $c) {
                if (($c['slug'] ?? '') !== 'standard' && !in_array($c['slug'], $existing, true)) {
                    $r = WC_Tax::create_tax_class((string) $c['name'], (string) $c['slug']);
                    if (!is_wp_error($r)) {
                        $backup['tax_classes'][] = (string) $c['slug'];
                    }
                }
            }
            foreach ((array) $d['taxes'] as $t) {
                $id = WC_Tax::_insert_tax_rate(['tax_rate_country' => (string) $t['country'], 'tax_rate_state' => (string) $t['state'], 'tax_rate' => (string) $t['rate'],
                    'tax_rate_name' => (string) $t['name'], 'tax_rate_priority' => (int) $t['priority'], 'tax_rate_compound' => !empty($t['compound']) ? 1 : 0,
                    'tax_rate_shipping' => !empty($t['shipping']) ? 1 : 0, 'tax_rate_order' => (int) $t['order'], 'tax_rate_class' => $t['class'] === 'standard' ? '' : (string) $t['class']]);
                if (!empty($t['postcodes'])) {
                    WC_Tax::_update_tax_rate_postcodes($id, implode(';', (array) $t['postcodes']));
                }
                if (!empty($t['cities'])) {
                    WC_Tax::_update_tax_rate_cities($id, implode(';', (array) $t['cities']));
                }
                $backup['tax_rates'][] = $id;
            }
            $log[] = count($backup['tax_rates']) . ' adókulcs átvéve.';
        }
    }
    // Jogi oldalak (ÁSZF, adatvédelem, bankkártyás fizetés) a régi bolt szövegével
    if (in_array('legal', $parts, true) && !empty($d['legal'])) {
        $done = [];
        foreach (mandala_oldset_legal_targets((array) $d['legal']) as $role => $t) {
            $content = mandala_oldset_clean_html((string) $t['src']['html']);
            if ($content === '') {
                continue;
            }
            if ($t['id']) {
                $post = get_post($t['id']);
                $backup['posts'][$t['id']] = ['post_title' => $post->post_title, 'post_content' => $post->post_content, 'post_status' => $post->post_status];
                $upd = ['ID' => $t['id'], 'post_title' => $t['title'], 'post_content' => $content, 'post_status' => 'publish'];
                if ($post->post_status !== 'publish' && $t['slug'] !== '' && !get_page_by_path($t['slug'])) {
                    $upd['post_name'] = $t['slug']; // a vázlat (pl. a WordPress alap-adatvédelmi oldala) a régi címet kapja
                    $backup['posts'][$t['id']]['post_name'] = $post->post_name;
                }
                wp_update_post($upd);
                $id = $t['id'];
            } else {
                $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $t['title'], 'post_name' => $t['slug'], 'post_content' => $content]);
                if (!$id || is_wp_error($id)) {
                    continue;
                }
                $backup['created_posts'][] = $id;
            }
            if ($t['option'] && (int) get_option($t['option']) !== (int) $id) {
                $set($t['option'], (int) $id);
            }
            if ($role === 'card' && ($item = mandala_oldset_menu_add((int) $id))) {
                $backup['menu_items'][] = $item;
            }
            $done[] = $t['title'];
        }
        if ($done) {
            $log[] = 'Jogi oldalak a régi bolt szövegével: ' . implode(', ', $done) . '.';
        }
    }
    // Webhely neve, időzóna, dátum
    if (in_array('wp', $parts, true)) {
        foreach (mandala_oldset_plan($d)['wp'] as $opt => $ch) {
            $set($opt, $ch['new']);
        }
        $log[] = 'Webhely-beállítások (név, időzóna, dátumformátum) átvéve.';
    }
    update_option(MANDALA_OLDSET_BACKUP, $backup, false);
    delete_option(MANDALA_OLDSET_PENDING); // a feltöltött fájl (benne a fizetési kulcsok) nem marad az adatbázisban
    return $log ?: ['Nem volt átvehető változás.'];
}

function mandala_oldset_undo(): array
{
    $b = (array) get_option(MANDALA_OLDSET_BACKUP, []);
    if (!$b) {
        return ['Nincs visszavonható átvétel.'];
    }
    foreach ((array) ($b['options'] ?? []) as $opt => $val) {
        $val === null ? delete_option($opt) : update_option($opt, $val);
    }
    if (is_array($b['zones'] ?? null)) {
        $map = mandala_oldset_zones_import($b['zones'])['map'];
        foreach (array_keys((array) ($b['options'] ?? [])) as $opt) { // a visszaállított zónák új példányazonosítót kapnak
            if (preg_match('/^woocommerce_\w+_settings$/', $opt) && is_array($val = get_option($opt))) {
                update_option($opt, mandala_oldset_remap($val, $map));
            }
        }
    }
    foreach ((array) ($b['tax_rates'] ?? []) as $id) {
        WC_Tax::_delete_tax_rate((int) $id);
    }
    foreach ((array) ($b['tax_classes'] ?? []) as $slug) {
        WC_Tax::delete_tax_class_by('slug', $slug);
    }
    foreach ((array) ($b['posts'] ?? []) as $id => $p) {
        wp_update_post(['ID' => (int) $id] + (array) $p);
    }
    foreach ((array) ($b['menu_items'] ?? []) as $item) {
        wp_delete_post((int) $item, true);
    }
    foreach ((array) ($b['created_posts'] ?? []) as $id) {
        wp_delete_post((int) $id, true);
    }
    delete_option(MANDALA_OLDSET_BACKUP);
    return ['Visszaállítva az átvétel előtti állapot (' . count((array) ($b['options'] ?? [])) . ' beállítás' . (is_array($b['zones'] ?? null) ? ', szállítási zónák' : '') . ').'];
}

/* ---------- Jogi oldalak ---------- */

/** Hova kerül a régi bolt ÁSZF-je, adatvédelmi tájékoztatója és bankkártyás fizetési oldala. */
function mandala_oldset_legal_targets(array $legal): array
{
    $out = [];
    foreach (['terms' => 'woocommerce_terms_page_id', 'privacy' => 'wp_page_for_privacy_policy', 'card' => ''] as $role => $opt) {
        if (empty($legal[$role]['html'])) {
            continue;
        }
        $src = $legal[$role];
        $id = $opt ? (int) get_option($opt) : 0;
        if (!$id || !get_post($id) || get_post_status($id) === 'trash') {
            $existing = get_page_by_path((string) $src['slug']);
            $id = $existing ? (int) $existing->ID : 0;
        }
        $out[$role] = ['id' => $id, 'option' => $opt, 'title' => wp_strip_all_tags(html_entity_decode((string) $src['title'])), 'slug' => sanitize_title((string) $src['slug']), 'src' => $src];
    }
    return $out;
}
/** Oldalépítő (Elementor) jelölések nélkül, tiszta HTML. */
function mandala_oldset_clean_html(string $html): string
{
    // Fogyasztóbarát (fogyasztobarat.hu) ÁSZF/adatvédelmi beágyazás: rövidkóddá alakul, a téma ugyanúgy tölti be
    $html = preg_replace_callback('#<script[^>]*>(?:(?!</script>).)*fogyasztobarat\.hu(?:(?!</script>).)*</script>#is', function ($m) {
        preg_match('/"data-id",\s*"([A-Za-z0-9]+)"/', $m[0], $id);
        preg_match('/"data-type",\s*"([a-z_-]+)"/', $m[0], $type);
        return $id ? "\n[mandala_fogyasztobarat ref=\"{$id[1]}\" type=\"" . ($type[1] ?? 'aszf') . "\"]\n" : '';
    }, $html);
    $html = preg_replace('#<(script|style|noscript|svg)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace('#</?(div|section|span|article|header|footer|figure|main|font)\b[^>]*>#i', '', $html);
    $html = preg_replace('#\s(class|style|id|data-[\w-]+|aria-[\w-]+|role)="[^"]*"#i', '', $html);
    $html = wp_kses_post($html);
    $html = preg_replace('#<p>\s*(&nbsp;)?\s*</p>#i', '', $html);
    $html = preg_replace('/^[ \t]+|[ \t]+$/m', '', $html);
    return trim(preg_replace("/\n\s*\n+/", "\n\n", $html));
}
/** A bankkártyás fizetési oldal a lábléc „Jogi linkek” menüjébe (ha még nincs benne). */
function mandala_oldset_menu_add(int $page_id): int
{
    $menu = (int) (get_nav_menu_locations()['mandala-legal'] ?? 0);
    if (!$menu) {
        return 0;
    }
    foreach ((array) wp_get_nav_menu_items($menu) as $item) {
        if ((int) $item->object_id === $page_id) {
            return 0;
        }
    }
    $id = wp_update_nav_menu_item($menu, 0, ['menu-item-object-id' => $page_id, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
    return is_wp_error($id) ? 0 : (int) $id;
}

/** A régi bolt Fogyasztóbarát-beágyazása (jogi szöveg a fogyasztobarat.hu-ról) – ugyanaz a betöltő, mint a régi oldalon. */
add_shortcode('mandala_fogyasztobarat', function ($atts) {
    $a = shortcode_atts(["ref" => "", "type" => "aszf"], $atts);
    $id = preg_replace('/[^A-Za-z0-9]/', '', (string) $a["ref"]);
    $type = preg_replace('/[^a-z_-]/', '', (string) $a['type']);
    if ($id === '') {
        return '';
    }
    return '<script id="barat_script">var st=document.createElement("script");st.src="//admin.fogyasztobarat.hu/e-api.js";st.type="text/javascript";'
        . 'st.setAttribute("data-id",' . wp_json_encode($id) . ');st.setAttribute("id","fbarat-embed");st.setAttribute("data-type",' . wp_json_encode($type) . ');'
        . 'var s=document.getElementById("barat_script");s.parentNode.insertBefore(st,s);</script>';
});

/* ---------- Admin ---------- */

add_action('admin_menu', function () {
    add_submenu_page('woocommerce', 'Régi bolt beállításai', 'Régi bolt beállításai', 'manage_woocommerce', 'mandala-oldsettings', 'mandala_oldset_page');
}, 60);

function mandala_oldset_show($v): string
{
    if (is_bool($v)) {
        return $v ? 'igen' : 'nem';
    }
    if (is_array($v)) {
        $v = implode(', ', array_map(fn($x) => is_scalar($x) ? (string) $x : wp_json_encode($x), $v));
    }
    $v = (string) ($v ?? '');
    return $v === '' ? '–' : (mb_strlen($v) > 70 ? mb_substr($v, 0, 70) . '…' : $v);
}

function mandala_oldset_page(): void
{
    $log = [];
    if (!empty($_POST['mandala_oldset']) && check_admin_referer('mandala_oldset')) {
        $action = sanitize_key($_POST['mandala_oldset']);
        if ($action === 'upload' && !empty($_FILES['file']['tmp_name']) && (int) $_FILES['file']['size'] < 8 * MB_IN_BYTES) {
            $d = mandala_oldset_parse((string) file_get_contents($_FILES['file']['tmp_name'])); // phpcs:ignore
            if (is_wp_error($d)) {
                $log[] = $d->get_error_message();
            } else {
                update_option(MANDALA_OLDSET_PENDING, $d, false);
            }
        } elseif ($action === 'apply' && ($d = get_option(MANDALA_OLDSET_PENDING))) {
            $log = mandala_oldset_apply((array) $d, array_map('sanitize_key', (array) wp_unslash($_POST['parts'] ?? [])));
        } elseif ($action === 'cancel') {
            delete_option(MANDALA_OLDSET_PENDING);
        } elseif ($action === 'undo') {
            $log = mandala_oldset_undo();
        }
    }
    echo '<div class="wrap"><h1>Régi bolt beállításai</h1>';
    if ($log) {
        echo '<div class="notice notice-info"><ul style="list-style:disc;padding-left:20px">' . implode('', array_map(fn($l) => '<li>' . esc_html($l) . '</li>', $log)) . '</ul></div>';
    }
    $d = get_option(MANDALA_OLDSET_PENDING);
    if (!$d) {
        $script = (string) file_get_contents(__DIR__ . '/oldsettings-export.js');
        echo '<p style="max-width:780px">A régi bolt fizetési módjai, szállítási díjai, adói, levél- és fiókbeállításai két lépésben vehetők át. A régi boltban semmi nem változik.</p>'
            . '<h2>1. Export a régi boltból</h2><ol style="max-width:780px"><li>Lépj be a <strong>régi</strong> bolt adminjába.</li><li>Nyisd meg a böngésző fejlesztői konzolját (Chrome: <code>Ctrl+Shift+J</code>, Macen <code>Cmd+Option+J</code>).</li>'
            . '<li>Másold be az alábbi szöveget, és nyomj Entert (ha a Chrome kéri, előbb írd be: <code>allow pasting</code>).</li><li>Letöltődik a <code>mandala-regi-beallitasok.json</code> fájl.</li></ol>'
            . '<p><textarea readonly rows="6" class="large-text code" onclick="this.select()" id="mandala-oldset-script">' . esc_textarea($script) . '</textarea></p>'
            . '<p><button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById(\'mandala-oldset-script\').value);this.textContent=\'Kimásolva\'">Szöveg másolása</button></p>'
            . '<h2>2. Feltöltés ide</h2><form method="post" enctype="multipart/form-data">';
        wp_nonce_field('mandala_oldset');
        echo '<input type="file" name="file" accept=".json,application/json" required> <button class="button button-primary" name="mandala_oldset" value="upload">Feltöltés és előnézet</button></form>';
    } else {
        $plan = mandala_oldset_plan((array) $d);
        echo '<p>Forrás: <strong>' . esc_html((string) ($d['source'] ?? '')) . '</strong> · export: ' . esc_html(wp_date('Y. m. d. H:i', strtotime((string) ($d['created'] ?? 'now')))) . '. Jelöld be, mit veszel át.</p><form method="post">';
        wp_nonce_field('mandala_oldset');
        foreach ($plan['groups'] as $g => $rows) {
            $on = $g !== 'advanced';
            echo '<h3><label><input type="checkbox" name="parts[]" value="' . esc_attr($g) . '"' . checked($on, true, false) . '> ' . esc_html(mandala_oldset_group_label($g)) . ' – ' . count($rows) . ' változás</label></h3>'
                . '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Beállítás</th><th>Most</th><th>A régi boltban</th></tr></thead><tbody>';
            foreach ($rows as $r) {
                echo '<tr><td>' . esc_html($r['label']) . '</td><td>' . esc_html(mandala_oldset_show($r['old'])) . '</td><td><strong>' . esc_html(mandala_oldset_show($r['new'])) . '</strong></td></tr>';
            }
            echo '</tbody></table>';
        }
        if ($plan['gateways']) {
            echo '<h3><label><input type="checkbox" name="parts[]" value="gateways" checked> Fizetési módok</label></h3><ul style="list-style:disc;padding-left:20px">';
            foreach ($plan['gateways'] as $g) {
                echo '<li>' . esc_html($g['title']) . ' – ' . ($g['enabled'] ? 'bekapcsolva' : 'kikapcsolva') . ($g['available'] ? '' : ' · <strong style="color:#d63638">a bővítménye itt nincs telepítve</strong>') . '</li>';
            }
            echo '</ul>';
        }
        if ($plan['zones']) { // üres zónalista (pl. csak jogi oldalakat tartalmazó fájl) soha nem törli a mostani zónákat
            echo '<h3><label><input type="checkbox" name="parts[]" value="zones" checked> Szállítási zónák (a mostaniak helyett)</label></h3><ul style="list-style:disc;padding-left:20px">';
            foreach ($plan['zones'] as $z) {
                echo '<li><strong>' . esc_html($z['name']) . '</strong>' . ($z['locations'] ? ' (' . (int) $z['locations'] . ' terület)' : '') . ': ' . esc_html(implode(', ', array_map(fn($m) => $m['title'] . ($m['enabled'] ? '' : ' [ki]') . ($m['available'] ? '' : ' [bővítmény hiányzik]'), $z['methods'])) ?: '–') . '</li>';
            }
            echo '</ul>';
        }
        if ($plan['legal']) {
            echo '<h3><label><input type="checkbox" name="parts[]" value="legal" checked> Jogi oldalak a régi bolt szövegével</label></h3><ul style="list-style:disc;padding-left:20px">';
            foreach ($plan['legal'] as $l) {
                echo '<li><strong>' . esc_html($l['title']) . '</strong> (' . number_format_i18n($l['chars']) . ' karakter) – ' . esc_html($l['target']) . '</li>';
            }
            echo '</ul><p class="description">Az oldalépítő (Elementor) jelölései nélkül, tiszta szövegként kerülnek át. A bankkártyás fizetési tájékoztató (a Teya előírja) a lábléc jogi linkjei közé is bekerül.</p>';
        }
        if ($plan['taxes']) {
            echo '<h3><label><input type="checkbox" name="parts[]" value="taxes"' . checked($plan['tax_note'] === '', true, false) . '> Adókulcsok (' . (int) $plan['taxes'] . ')</label></h3>' . ($plan['tax_note'] ? '<p>' . esc_html($plan['tax_note']) . '</p>' : '');
        }
        if ($plan['wp']) {
            echo '<h3><label><input type="checkbox" name="parts[]" value="wp" checked> Webhely neve, időzóna, dátumformátum</label></h3><ul style="list-style:disc;padding-left:20px">';
            foreach ($plan['wp'] as $opt => $ch) {
                echo '<li>' . esc_html($opt) . ': ' . esc_html(mandala_oldset_show($ch['old'])) . ' → <strong>' . esc_html(mandala_oldset_show($ch['new'])) . '</strong></li>';
            }
            echo '</ul>';
        }
        if ($plan['plugins_missing']) {
            echo '<div class="notice notice-warning inline"><p><strong>A régi boltban bekapcsolt, itt hiányzó bővítmények:</strong> ' . esc_html(implode(', ', $plan['plugins_missing'])) . '. Ami ezek közül fizetési / szállítási mód vagy számlázó, azt telepítsd, mielőtt átveszed (a beállításaik csak így jönnek át).</p></div>';
        }
        echo '<p style="margin-top:20px"><button class="button button-primary button-hero" name="mandala_oldset" value="apply">A kijelöltek átvétele</button> <button class="button" name="mandala_oldset" value="cancel">Mégse</button></p>'
            . '<p class="description">Átvétel előtt mentés készül: az átvétel egy kattintással visszavonható. A feltöltött fájlt (benne a fizetési kulcsokkal) átvétel után töröld a gépedről.</p></form>';
    }
    if (get_option(MANDALA_OLDSET_BACKUP)) {
        echo '<hr><form method="post">';
        wp_nonce_field('mandala_oldset');
        echo '<p>Legutóbbi átvétel: ' . esc_html(wp_date('Y. m. d. H:i', (int) get_option(MANDALA_OLDSET_BACKUP)['time'])) . ' <button class="button" name="mandala_oldset" value="undo" onclick="return confirm(\'Visszaállítod az átvétel előtti beállításokat?\')">Átvétel visszavonása</button></p></form>';
    }
    echo '</div>';
}
