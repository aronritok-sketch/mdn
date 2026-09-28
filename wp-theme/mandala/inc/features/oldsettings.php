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
    $plan = ['groups' => [], 'gateways' => [], 'zones' => [], 'taxes' => 0, 'tax_note' => '', 'wp' => [], 'plugins_missing' => []];
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
            if (($p['status'] ?? '') === 'active' && !in_array(dirname((string) $p['plugin']), $here, true)) {
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
            $methods[] = ['method_id' => $m->id, 'title' => $m->get_title(), 'enabled' => $m->is_enabled(), 'order' => (int) $m->method_order, 'settings' => (array) get_option($m->get_instance_option_key(), [])];
        }
        $out[] = ['id' => $zone->get_id(), 'name' => $zone->get_zone_name(), 'order' => $zone->get_zone_order(),
            'locations' => array_map(fn($l) => ['code' => $l->code, 'type' => $l->type], $zone->get_zone_locations()), 'methods' => $methods];
    }
    return $out;
}
/** A zónák teljes cseréje a megadottakra. Visszaad: [hiányzó szállítási módok]. */
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
            $wpdb->update($wpdb->prefix . 'woocommerce_shipping_zone_methods', ['is_enabled' => empty($m['enabled']) ? 0 : 1, 'method_order' => (int) ($m['order'] ?? 0)], ['instance_id' => $iid]);
        }
    }
    WC_Cache_Helper::get_transient_version('shipping', true);
    return array_values(array_unique($missing));
}

/* ---------- Átvétel és visszavonás ---------- */

/** $parts: a kiválasztott csoportok + 'gateways', 'zones', 'taxes', 'wp'. Visszaad: napló (sorok). */
function mandala_oldset_apply(array $d, array $parts): array
{
    $log = [];
    $backup = ['time' => time(), 'options' => [], 'zones' => null, 'tax_rates' => [], 'tax_classes' => []];
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
            $settings = array_merge((array) get_option($opt, []), (array) $g['settings'], ['enabled' => empty($g['enabled']) ? 'no' : 'yes']);
            if (($g['title'] ?? '') !== '') {
                $settings['title'] = $g['title'];
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
    // Szállítási zónák
    if (in_array('zones', $parts, true)) {
        $backup['zones'] = mandala_oldset_zones_export();
        $missing = mandala_oldset_zones_import((array) $d['zones']);
        $log[] = count((array) $d['zones']) . ' szállítási zóna átvéve (a korábbiak helyett).';
        if ($missing) {
            $log[] = 'Szállítási módok, amelyek bővítménye itt nincs telepítve (kimaradtak): ' . implode(', ', $missing) . '.';
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
        mandala_oldset_zones_import($b['zones']);
    }
    foreach ((array) ($b['tax_rates'] ?? []) as $id) {
        WC_Tax::_delete_tax_rate((int) $id);
    }
    foreach ((array) ($b['tax_classes'] ?? []) as $slug) {
        WC_Tax::delete_tax_class_by('slug', $slug);
    }
    delete_option(MANDALA_OLDSET_BACKUP);
    return ['Visszaállítva az átvétel előtti állapot (' . count((array) ($b['options'] ?? [])) . ' beállítás' . (is_array($b['zones'] ?? null) ? ', szállítási zónák' : '') . ').'];
}

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
        echo '<h3><label><input type="checkbox" name="parts[]" value="gateways" checked> Fizetési módok</label></h3><ul style="list-style:disc;padding-left:20px">';
        foreach ($plan['gateways'] as $g) {
            echo '<li>' . esc_html($g['title']) . ' – ' . ($g['enabled'] ? 'bekapcsolva' : 'kikapcsolva') . ($g['available'] ? '' : ' · <strong style="color:#d63638">a bővítménye itt nincs telepítve</strong>') . '</li>';
        }
        echo '</ul><h3><label><input type="checkbox" name="parts[]" value="zones" checked> Szállítási zónák (a mostaniak helyett)</label></h3><ul style="list-style:disc;padding-left:20px">';
        foreach ($plan['zones'] as $z) {
            echo '<li><strong>' . esc_html($z['name']) . '</strong>' . ($z['locations'] ? ' (' . (int) $z['locations'] . ' terület)' : '') . ': ' . esc_html(implode(', ', array_map(fn($m) => $m['title'] . ($m['enabled'] ? '' : ' [ki]') . ($m['available'] ? '' : ' [bővítmény hiányzik]'), $z['methods'])) ?: '–') . '</li>';
        }
        echo '</ul>';
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
