<?php
/**
 * Új termékek jóváhagyási sora (Termékek → Új termékek).
 *
 * A JUTA-Soft automatikusan hozza a termékeket árral és készlettel. Ami importból érkezik (REST API,
 * WP-CLI, ütemezett feladat – nem a termékszerkesztőből), az PISZKOZAT lesz és a sorba kerül; élesre
 * csak akkor mehet, ha az ellenőrzőlista teljes: kategória, fő kép, leírás, ár, cikkszám és a
 * kategóriájához kötelező szűrőadatok (catalog-schema.php). A JUTA ár- és készletfrissítése nem
 * változtat az állapoton; a szerkesztőben „Közzététel” hiányos terméknél piszkozat marad.
 *
 * Állapot (_mandala_onboarding): new = új, még nem élő · review = élő, de ellenőrizendő (a Claude-os
 * migráció nem tudott dönteni) · done = kész.
 * Értesítés: összesítő levél az érkezés után (15 perc gyűjtés), napi emlékeztető, jelvény a menüben.
 * Biztonsági háló: óránként megkeresi a közvetlenül adatbázisba írt, jelöletlen új termékeket is.
 */

defined('ABSPATH') || exit;

function mandala_onboarding_settings(): array
{
    return wp_parse_args((array) get_option('mandala_onboarding', []), [
        'enabled' => 'yes',
        'recipients' => '',      // üres: az admin e-mail
        'min_desc' => 150,       // a leírás legalább ennyi karakter
        'daily' => 'yes',        // napi emlékeztető, ha van 2 napnál régebbi tétel
        'since' => 0,            // a sor bekapcsolásának ideje (a biztonsági háló ennél újabbakat néz)
    ]);
}
function mandala_onboarding_on(): bool
{
    return mandala_onboarding_settings()['enabled'] === 'yes';
}
add_action('init', function () {
    $s = (array) get_option('mandala_onboarding', []);
    if (empty($s['since'])) {
        $s['since'] = time();
        update_option('mandala_onboarding', $s, false);
    }
});

/**
 * A termékszerkesztőből jön-e a mentés (klasszikus szerkesztő, gyors / csoportos szerkesztés,
 * másolás, vagy az új blokkos termékszerkesztő: REST bejelentkezett felhasználó nonce-ával).
 * Az import (JUTA) API-kulccsal, nonce nélkül érkezik, az nem szerkesztő.
 */
function mandala_is_editor_save(): bool
{
    $action = sanitize_key($_REQUEST['action'] ?? ''); // phpcs:ignore WordPress.Security.NonceVerification
    $editor = is_admin() && current_user_can('edit_products') && (in_array($action, ['editpost', 'inline-save', 'edit', 'duplicate_product'], true) || isset($_REQUEST['bulk_edit'])); // phpcs:ignore
    if (!$editor && defined('REST_REQUEST') && REST_REQUEST && !empty($_SERVER['HTTP_X_WP_NONCE'])) {
        $editor = (bool) wp_verify_nonce(sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE'])), 'wp_rest') && current_user_can('edit_products');
    }
    return (bool) apply_filters('mandala_is_editor_save', $editor);
}

function mandala_onboarding_state(int $id): string
{
    return (string) get_post_meta($id, '_mandala_onboarding', true);
}

/* ---------- Érkezés és állapotvédelem ---------- */

add_filter('wp_insert_post_data', function ($data, $postarr, $unsanitized = [], $update = null) {
    if (($data['post_type'] ?? '') !== 'product' || !mandala_onboarding_on()) {
        return $data;
    }
    $id = (int) ($postarr['ID'] ?? 0);
    $public = in_array($data['post_status'], ['publish', 'future', 'private', 'pending'], true);
    if (!$id || $update === false) {
        // Új termék importból (akár közzétéve, akár piszkozatként érkezik): piszkozat, sorba kerül.
        if ($data['post_status'] !== 'auto-draft' && !mandala_is_editor_save() && empty($GLOBALS['mandala_onboarding_skip'])) {
            if ($public) {
                $data['post_status'] = 'draft';
            }
            $GLOBALS['mandala_onboarding_new'] = true;
        }
        return $data;
    }
    // Még nem élesített új termék: csak a szerkesztőből (utólagos ellenőrzéssel) vagy jóváhagyással lehet élő.
    if (mandala_onboarding_state($id) === 'new' && $public && ($GLOBALS['mandala_onboarding_approving'] ?? 0) !== $id && !mandala_is_editor_save()) {
        $data['post_status'] = get_post_status($id) ?: 'draft';
    }
    return $data;
}, 20, 4);

add_action('wp_insert_post', function ($post_id, $post, $update) {
    if ($post->post_type !== 'product') {
        return;
    }
    if (!empty($GLOBALS['mandala_onboarding_new'])) {
        unset($GLOBALS['mandala_onboarding_new']);
        mandala_onboarding_mark_new((int) $post_id);
    } elseif (!$update && !metadata_exists('post', $post_id, '_mandala_onboarding')) {
        // Szerkesztőből, a telepítőből vagy a témából (pl. eseményjegy) létrejött termék: kész.
        // Így az óránkénti ellenőrzés csak a WordPresst megkerülő (adatbázisba írt) termékeket fogja meg.
        update_post_meta($post_id, '_mandala_onboarding', 'done');
    }
}, 10, 3);

function mandala_onboarding_mark_new(int $id): void
{
    update_post_meta($id, '_mandala_onboarding', 'new');
    update_post_meta($id, '_mandala_onboarding_since', time());
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_onboarding_digest', [], MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + 15 * MINUTE_IN_SECONDS, 'mandala_onboarding_digest', [], MANDALA_AS_GROUP);
    }
    do_action('mandala_onboarding_new_product', $id); // pl. Claude-os javaslat (ai-catalog.php)
}

/** A szerkesztőben közzétett, de hiányos új termék piszkozat marad (a mentés végén, amikor már minden mező mentve van). */
add_action('save_post_product', function ($post_id, $post) {
    if (wp_is_post_revision($post_id) || !mandala_is_editor_save() || !in_array(mandala_onboarding_state($post_id), ['new', 'review'], true)) {
        return;
    }
    $product = wc_get_product($post_id);
    if (!$product) {
        return;
    }
    $missing = mandala_onboarding_missing($product);
    if (mandala_onboarding_state($post_id) === 'new' && in_array($post->post_status, ['publish', 'future'], true) && $missing) {
        remove_all_actions('save_post_product');
        $GLOBALS['mandala_onboarding_skip'] = true;
        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        set_transient('mandala_onboarding_notice_' . get_current_user_id(), sprintf('„%s” nem élesíthető, piszkozat maradt. Hiányzik: %s.', $post->post_title, implode(', ', $missing)), 60);
    } elseif (!$missing && ($post->post_status === 'publish' || mandala_onboarding_state($post_id) === 'review')) {
        mandala_onboarding_complete($post_id);
    }
}, 999, 2);

add_action('admin_notices', function () {
    $msg = get_transient('mandala_onboarding_notice_' . get_current_user_id());
    if ($msg) {
        delete_transient('mandala_onboarding_notice_' . get_current_user_id());
        echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html($msg) . '</p></div>';
    }
});

function mandala_onboarding_complete(int $id): void
{
    update_post_meta($id, '_mandala_onboarding', 'done');
    update_post_meta($id, '_mandala_onboarding_done', time());
    mandala_flush_index();
}

/** Jóváhagyás (sor / csoportos művelet): teljes termék közzététele. */
function mandala_onboarding_approve(int $id): array
{
    $product = wc_get_product($id);
    if (!$product) {
        return ['Nincs ilyen termék.'];
    }
    $missing = mandala_onboarding_missing($product);
    if ($missing) {
        return $missing;
    }
    if (mandala_onboarding_state($id) === 'new') {
        $GLOBALS['mandala_onboarding_approving'] = $id;
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
        unset($GLOBALS['mandala_onboarding_approving']);
    }
    mandala_onboarding_complete($id);
    return [];
}

/* ---------- Ellenőrzőlista ---------- */

/** [[kulcs, címke, rendben?, kötelező?, tipp]] */
function mandala_onboarding_checklist(WC_Product $product): array
{
    $s = mandala_onboarding_settings();
    [$cat, $sub] = mandala_product_cats($product->get_id());
    $default = get_term((int) get_option('default_product_cat'), 'product_cat');
    if ($default instanceof WP_Term && $cat === $default->slug) {
        $cat = ''; // a WooCommerce „Egyéb / Uncategorized” kategóriája nem számít
    }
    $has_subs = $cat && ($main = get_term_by('slug', $cat, 'product_cat')) && get_term_children($main->term_id, 'product_cat');
    $desc = trim(wp_strip_all_tags($product->get_description('edit')));
    $items = [
        ['cat', 'Kategória', $cat !== '' && (!$has_subs || $sub !== ''), true, $has_subs && !$sub ? 'Alkategóriát is válassz.' : 'Fő- és alkategória.'],
        ['image', 'Fő termékkép', (bool) $product->get_image_id(), true, 'Legalább 1000 px széles, világos háttér.'],
        ['desc', 'Leírás', mb_strlen($desc) >= (int) $s['min_desc'], true, sprintf('Legalább %d karakter (most: %d).', (int) $s['min_desc'], mb_strlen($desc))],
        ['price', 'Ár', (float) $product->get_regular_price('edit') > 0, true, 'A JUTA-ból érkezik.'],
        ['sku', 'Cikkszám', $product->get_sku('edit') !== '', true, 'A JUTA-ból érkezik.'],
        ['gallery', 'További képek', count($product->get_gallery_image_ids('edit')) > 0, false, 'Ajánlott: részletek, méretarány.'],
        ['short', 'Rövid leírás', mb_strlen(trim(wp_strip_all_tags($product->get_short_description('edit')))) >= 40, false, 'Ajánlott: 1–2 mondat a kártyára.'],
    ];
    $values = mandala_product_filter_values($product);
    foreach (mandala_filter_schema() as $key => $f) {
        if (!$cat || !mandala_scope_match($f['scope'], $cat, $sub)) {
            continue;
        }
        $required = mandala_scope_match($f['required'], $cat, $sub);
        $v = $values[$key] ?? null;
        $items[] = ['f_' . $key, 'Szűrő: ' . $f['label'], is_array($v) ? (bool) $v : $v !== null, $required, $f['hint']];
    }
    if ($sub === 'hangtalak') {
        $items[] = ['audio', 'Hangminta', (bool) $product->get_meta('_mandala_audio', true, 'edit'), false, 'Ajánlott: 10–20 mp felvétel.'];
    }
    return apply_filters('mandala_onboarding_checklist', $items, $product);
}

/** A hiányzó kötelező tételek címkéi. */
function mandala_onboarding_missing(WC_Product $product): array
{
    return array_values(array_map(fn($i) => $i[1], array_filter(mandala_onboarding_checklist($product), fn($i) => $i[3] && !$i[2])));
}

/* ---------- Számlálás, jelvény ---------- */

function mandala_onboarding_ids(string $state, int $limit = -1, int $offset = 0, string $search = ''): array
{
    $args = ['post_type' => 'product', 'post_status' => ['publish', 'draft', 'pending', 'private', 'future'], 'posts_per_page' => $limit, 'offset' => $offset, 'fields' => 'ids',
        'meta_key' => '_mandala_onboarding_since', 'orderby' => 'meta_value_num', 'order' => 'DESC',
        'meta_query' => [['key' => '_mandala_onboarding', 'value' => $state]], 'suppress_filters' => false];
    if ($search) {
        $args['s'] = $search;
    }
    return get_posts($args);
}
function mandala_onboarding_count(string $state = ''): int
{
    $cached = wp_cache_get('counts', 'mandala_onboarding');
    if (!is_array($cached)) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT meta_value AS s, COUNT(*) AS n FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_mandala_onboarding' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft') GROUP BY meta_value");
        $cached = [];
        foreach ($rows as $r) {
            $cached[$r->s] = (int) $r->n;
        }
        wp_cache_set('counts', $cached, 'mandala_onboarding', 60);
    }
    return $state ? ($cached[$state] ?? 0) : ($cached['new'] ?? 0) + ($cached['review'] ?? 0);
}

add_action('admin_menu', function () {
    $n = mandala_onboarding_count();
    add_submenu_page('edit.php?post_type=product', 'Új termékek', 'Új termékek' . ($n ? ' <span class="awaiting-mod count-' . $n . '"><span class="pending-count">' . number_format_i18n($n) . '</span></span>' : ''), 'edit_products', 'mandala-onboarding', 'mandala_onboarding_page', 1);
});
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (!current_user_can('edit_products') || !($n = mandala_onboarding_count('new'))) {
        return;
    }
    $bar->add_node(['id' => 'mandala-onboarding', 'title' => sprintf('Új termékek: %d', $n), 'href' => admin_url('edit.php?post_type=product&page=mandala-onboarding')]);
}, 80);

/* ---------- Levelek ---------- */

function mandala_onboarding_recipients(): array
{
    $list = array_filter(array_map('trim', explode(',', (string) mandala_onboarding_settings()['recipients'])), 'is_email');
    return $list ?: [get_option('admin_email')];
}

function mandala_onboarding_mail_rows(array $ids, int $max = 25): string
{
    $rows = '';
    foreach (array_slice($ids, 0, $max) as $id) {
        $product = wc_get_product($id);
        if (!$product) {
            continue;
        }
        $missing = mandala_onboarding_missing($product);
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #EEE7DB"><a href="' . esc_url(admin_url('post.php?post=' . (int) $id . '&action=edit')) . '" style="color:#1C1916;font-weight:600">' . esc_html($product->get_name()) . '</a><br><span style="color:#6E6357;font-size:13px">'
            . esc_html(($product->get_sku() ? $product->get_sku() . ' · ' : '') . mandala_fmt((float) $product->get_price()) . ' · ' . ($missing ? 'hiányzik: ' . implode(', ', $missing) : 'kész az élesítésre')) . '</span></td></tr>';
    }
    return '<table role="presentation" style="width:100%;border-collapse:collapse">' . $rows . '</table>' . (count($ids) > $max ? '<p>' . esc_html(sprintf('…és még %d termék.', count($ids) - $max)) . '</p>' : '');
}

add_action('mandala_onboarding_digest', function () {
    $last = (int) get_option('mandala_onboarding_last_digest', 0);
    $ids = array_values(array_filter(mandala_onboarding_ids('new'), fn($id) => (int) get_post_meta($id, '_mandala_onboarding_since', true) > $last));
    update_option('mandala_onboarding_last_digest', time(), false);
    if (!$ids) {
        return;
    }
    $body = '<p>' . esc_html(sprintf('%d új termék érkezett a JUTA-ból. Élesítés előtt kategória, kép, leírás és szűrőadatok kellenek.', count($ids))) . '</p>'
        . mandala_onboarding_mail_rows($ids) . mandala_mail_button(admin_url('edit.php?post_type=product&page=mandala-onboarding'), 'Új termékek megnyitása');
    foreach (mandala_onboarding_recipients() as $to) {
        mandala_send_mail($to, sprintf('[%s] %d új termék vár élesítésre', get_bloginfo('name'), count($ids)), 'Új termékek érkeztek', $body, false, ['type' => 'belso']);
    }
});

add_action('init', function () {
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_onboarding_daily', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action((new DateTimeImmutable('tomorrow 08:30', wp_timezone()))->getTimestamp(), DAY_IN_SECONDS, 'mandala_onboarding_daily', [], MANDALA_AS_GROUP);
    }
    if (function_exists('as_has_scheduled_action') && !as_has_scheduled_action('mandala_onboarding_sweep', [], MANDALA_AS_GROUP)) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'mandala_onboarding_sweep', [], MANDALA_AS_GROUP);
    }
}, 30);

add_action('mandala_onboarding_daily', function () {
    if (mandala_onboarding_settings()['daily'] !== 'yes') {
        return;
    }
    $old = array_values(array_filter(mandala_onboarding_ids('new'), fn($id) => (int) get_post_meta($id, '_mandala_onboarding_since', true) < time() - 2 * DAY_IN_SECONDS));
    $review = mandala_onboarding_count('review');
    if (!$old && !$review) {
        return;
    }
    $body = ($old ? '<p>' . esc_html(sprintf('%d új termék 2 napnál régebben vár élesítésre (addig nem vásárolható):', count($old))) . '</p>' . mandala_onboarding_mail_rows($old, 15) : '')
        . ($review ? '<p>' . esc_html(sprintf('%d élő termék kategóriáját / szűrőadatait kell ellenőrizni (a migráció nem tudott dönteni).', $review)) . '</p>' : '')
        . mandala_mail_button(admin_url('edit.php?post_type=product&page=mandala-onboarding'), 'Új termékek megnyitása');
    foreach (mandala_onboarding_recipients() as $to) {
        mandala_send_mail($to, sprintf('[%s] Emlékeztető: élesítésre váró termékek', get_bloginfo('name')), 'Élesítésre vár', $body, false, ['type' => 'belso']);
    }
});

/** Biztonsági háló: a hookokat megkerülve (közvetlenül adatbázisba) létrehozott új termékek. */
add_action('mandala_onboarding_sweep', function () {
    if (!mandala_onboarding_on()) {
        return;
    }
    global $wpdb;
    $since = gmdate('Y-m-d H:i:s', (int) mandala_onboarding_settings()['since']);
    $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_mandala_onboarding' WHERE p.post_type = 'product' AND p.post_status IN ('publish', 'draft', 'pending', 'private') AND p.post_date_gmt > %s AND m.meta_id IS NULL LIMIT 500", $since));
    foreach ($ids as $id) {
        $post = get_post((int) $id);
        // A szerkesztőben kézzel felvett (és így ellenőrzött) termék: kész.
        if ((int) $post->post_author && get_post_meta((int) $id, '_edit_last', true)) {
            update_post_meta((int) $id, '_mandala_onboarding', 'done');
            continue;
        }
        if ($post->post_status !== 'draft') {
            $GLOBALS['mandala_onboarding_skip'] = true;
            wp_update_post(['ID' => (int) $id, 'post_status' => 'draft']);
            unset($GLOBALS['mandala_onboarding_skip']);
        }
        mandala_onboarding_mark_new((int) $id);
    }
    if ($ids) {
        mandala_flush_index();
    }
});

/* ---------- Termékszerkesztő: ellenőrzőlista doboz ---------- */

add_action('add_meta_boxes_product', function (WP_Post $post) {
    if (!in_array(mandala_onboarding_state($post->ID), ['new', 'review'], true)) {
        return;
    }
    add_meta_box('mandala_onboarding', 'Élesítési ellenőrzőlista', function (WP_Post $post) {
        $product = wc_get_product($post->ID);
        echo mandala_onboarding_checklist_html($product); // phpcs:ignore
        do_action('mandala_onboarding_metabox', $product);
    }, 'product', 'side', 'high');
});

function mandala_onboarding_checklist_html(WC_Product $product): string
{
    $out = '<ul class="mandala-checklist">';
    foreach (mandala_onboarding_checklist($product) as [$key, $label, $ok, $required, $hint]) {
        $out .= '<li class="' . ($ok ? 'ok' : ($required ? 'missing' : 'optional')) . '" title="' . esc_attr($hint) . '"><span aria-hidden="true">' . ($ok ? '✓' : ($required ? '✗' : '○')) . '</span> ' . esc_html($label)
            . ($ok ? '' : ' <span class="screen-reader-text">' . ($required ? '(hiányzik, kötelező)' : '(ajánlott)') . '</span>') . '</li>';
    }
    return $out . '</ul>';
}

add_action('admin_head', function () {
    echo '<style>.mandala-checklist{margin:0;display:grid;gap:4px}.mandala-checklist li{margin:0;display:flex;gap:6px}.mandala-checklist .ok span:first-child{color:#2e7d32}.mandala-checklist .missing{color:#b32d2e;font-weight:600}.mandala-checklist .optional{color:#646970}.mandala-chips{display:flex;flex-wrap:wrap;gap:4px}.mandala-chip{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;background:#f0f0f1}.mandala-chip.missing{background:#fcf0f1;color:#8a2424}.mandala-chip.ai{background:#f0f6fc;color:#0a4b78}.mandala-ob-table td{vertical-align:top}.mandala-ob-thumb{width:48px;height:48px;object-fit:cover;border-radius:4px;background:#f0f0f1;display:block}</style>';
});

add_action('admin_post_mandala_ob_approve', function () {
    $id = absint($_GET['id'] ?? 0);
    if (!current_user_can('edit_product', $id) || !check_admin_referer('mandala_ob_approve_' . $id)) {
        wp_die('Nincs jogosultság.');
    }
    $missing = mandala_onboarding_approve($id);
    if ($missing) {
        set_transient('mandala_onboarding_notice_' . get_current_user_id(), sprintf('„%s” nem élesíthető. Hiányzik: %s.', get_the_title($id), implode(', ', $missing)), 60);
    }
    wp_safe_redirect(add_query_arg(['post_type' => 'product', 'page' => 'mandala-onboarding', 'tab' => sanitize_key($_GET['tab'] ?? 'new')], admin_url('edit.php')));
    exit;
});

/* ---------- Termékek lista: állapot oszlop ---------- */

add_filter('manage_edit-product_columns', fn($c) => $c + ['mandala_onboarding' => 'Élesítés']);
add_action('manage_product_posts_custom_column', function ($col, $id) {
    if ($col !== 'mandala_onboarding') {
        return;
    }
    $state = mandala_onboarding_state((int) $id);
    echo $state === 'new' ? '<span class="mandala-chip missing">új – élesítésre vár</span>' : ($state === 'review' ? '<span class="mandala-chip ai">ellenőrizendő</span>' : '');
}, 10, 2);

/* ---------- Az „Új termékek” oldal ---------- */

function mandala_onboarding_page(): void
{
    if (!current_user_can('edit_products')) {
        return;
    }
    $tab = sanitize_key($_GET['tab'] ?? 'new'); // phpcs:ignore
    $base = admin_url('edit.php?post_type=product&page=mandala-onboarding');

    // Műveletek
    if (!empty($_POST['mandala_ob_action']) && check_admin_referer('mandala_onboarding')) {
        $ids = array_map('absint', (array) ($_POST['ids'] ?? []));
        $action = sanitize_key($_POST['mandala_ob_action']);
        $ok = 0;
        $fail = [];
        foreach ($ids as $id) {
            if ($action === 'approve') {
                $missing = mandala_onboarding_approve($id);
                $missing ? $fail[] = get_the_title($id) . ' (' . implode(', ', $missing) . ')' : $ok++;
            } elseif ($action === 'done') {
                mandala_onboarding_complete($id);
                $ok++;
            } else {
                do_action('mandala_onboarding_bulk_' . $action, $id);
                $ok++;
            }
        }
        wp_cache_delete('counts', 'mandala_onboarding');
        echo '<div class="notice notice-' . ($fail ? 'warning' : 'success') . '"><p>' . esc_html(sprintf('%d termék kész.', $ok)) . ($fail ? ' ' . esc_html('Nem élesíthető: ' . implode('; ', $fail)) : '') . '</p></div>';
    }
    if ($tab === 'settings' && !empty($_POST['mandala_onboarding']) && check_admin_referer('mandala_onboarding_settings')) {
        $in = array_map('sanitize_text_field', (array) wp_unslash($_POST['mandala_onboarding']));
        $s = mandala_onboarding_settings();
        $s['enabled'] = empty($in['enabled']) ? 'no' : 'yes';
        $s['daily'] = empty($in['daily']) ? 'no' : 'yes';
        $s['recipients'] = implode(', ', array_filter(array_map('trim', explode(',', $in['recipients'] ?? '')), 'is_email'));
        $s['min_desc'] = max(0, (int) ($in['min_desc'] ?? 150));
        update_option('mandala_onboarding', $s, false);
        echo '<div class="notice notice-success"><p>Mentve.</p></div>';
    }

    $tabs = ['new' => 'Új, élesítésre vár (' . mandala_onboarding_count('new') . ')', 'review' => 'Élő, ellenőrizendő (' . mandala_onboarding_count('review') . ')'];
    $tabs = apply_filters('mandala_onboarding_tabs', $tabs) + ['settings' => 'Beállítások'];
    echo '<div class="wrap"><h1 class="wp-heading-inline">Új termékek</h1><nav class="nav-tab-wrapper">';
    foreach ($tabs as $key => $label) {
        echo '<a class="nav-tab' . ($tab === $key ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', $key, $base)) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';

    if ($tab === 'settings') {
        $s = mandala_onboarding_settings();
        echo '<form method="post">';
        wp_nonce_field('mandala_onboarding_settings');
        echo '<table class="form-table">'
            . '<tr><th scope="row">Jóváhagyási sor</th><td><label><input type="checkbox" name="mandala_onboarding[enabled]" value="1"' . checked($s['enabled'], 'yes', false) . '> importból érkező új termék piszkozat, élesítés az ellenőrzőlista után</label></td></tr>'
            . '<tr><th scope="row"><label for="ob-rec">Értesítendők</label></th><td><input type="text" class="regular-text" id="ob-rec" name="mandala_onboarding[recipients]" value="' . esc_attr($s['recipients']) . '" placeholder="' . esc_attr(get_option('admin_email')) . '"><p class="description">E-mail-címek vesszővel (a webért felelős munkatárs).</p></td></tr>'
            . '<tr><th scope="row">Napi emlékeztető</th><td><label><input type="checkbox" name="mandala_onboarding[daily]" value="1"' . checked($s['daily'], 'yes', false) . '> ha van 2 napnál régebben váró termék</label></td></tr>'
            . '<tr><th scope="row"><label for="ob-min">Leírás legalább</label></th><td><input type="number" min="0" id="ob-min" name="mandala_onboarding[min_desc]" value="' . esc_attr((string) $s['min_desc']) . '" style="width:90px"> karakter</td></tr>'
            . '</table>';
        submit_button('Mentés');
        echo '<h2>Kötelező szűrőadatok kategóriánként</h2><table class="widefat striped" style="max-width:900px"><thead><tr><th>Szűrő</th><th>Hol kötelező</th><th>Útmutató</th></tr></thead><tbody>';
        foreach (mandala_filter_schema() as $f) {
            $req = $f['required'] === true ? 'minden terméknél' : ($f['required'] ? implode(', ', array_merge((array) ($f['required']['cats'] ?? []), (array) ($f['required']['subs'] ?? []))) : '–');
            echo '<tr><td>' . esc_html($f['label']) . '</td><td>' . esc_html($req) . '</td><td>' . esc_html($f['hint']) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Módosítás kódból: <code>mandala_filter_schema</code> szűrő.</p></form></div>';
        return;
    }
    if (has_action('mandala_onboarding_tab_' . $tab)) {
        do_action('mandala_onboarding_tab_' . $tab, $base);
        echo '</div>';
        return;
    }

    $state = $tab === 'review' ? 'review' : 'new';
    $search = sanitize_text_field(wp_unslash($_GET['s'] ?? '')); // phpcs:ignore
    $paged = max(1, absint($_GET['paged'] ?? 1)); // phpcs:ignore
    $ids = mandala_onboarding_ids($state, 50, ($paged - 1) * 50, $search);
    $total = $search ? count(mandala_onboarding_ids($state, -1, 0, $search)) : mandala_onboarding_count($state);

    echo '<p>' . esc_html($state === 'new'
        ? 'Ezek a termékek importból érkeztek, és még nem láthatók a webshopban. A hiányzó adatokat a termék szerkesztésével pótold; ha minden kötelező tétel megvan, élesítheted.'
        : 'Ezek élő termékek, amelyeknél az automatikus kategorizálás nem tudott biztosan dönteni. Ellenőrizd a kategóriát és a szűrőadatokat, majd jelöld késznek.') . '</p>';
    echo '<form method="get"><input type="hidden" name="post_type" value="product"><input type="hidden" name="page" value="mandala-onboarding"><input type="hidden" name="tab" value="' . esc_attr($tab) . '"><p class="search-box"><label class="screen-reader-text" for="ob-s">Keresés</label><input type="search" id="ob-s" name="s" value="' . esc_attr($search) . '" placeholder="Név vagy cikkszám"><button class="button">Keresés</button></p></form>';
    echo '<form method="post">';
    wp_nonce_field('mandala_onboarding');
    echo '<div class="tablenav top"><div class="alignleft actions bulkactions"><label class="screen-reader-text" for="ob-action">Művelet</label><select name="mandala_ob_action" id="ob-action">'
        . ($state === 'new' ? '<option value="approve">Élesítés (ha teljes)</option>' : '<option value="done">Késznek jelöl</option>')
        . apply_filters('mandala_onboarding_bulk_options', '', $state)
        . '</select> <button class="button action">Alkalmaz</button></div><div class="tablenav-pages"><span class="displaying-num">' . esc_html(sprintf('%d termék', $total)) . '</span></div></div>';
    echo '<table class="wp-list-table widefat fixed striped mandala-ob-table"><thead><tr><td class="manage-column check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.mandala-ob-table tbody input[type=checkbox]\').forEach(c=>c.checked=this.checked)" aria-label="Mind"></td><th style="width:56px"></th><th>Termék</th><th style="width:110px">Érkezett</th><th>Hiányzik</th><th style="width:170px"></th></tr></thead><tbody>';
    if (!$ids) {
        echo '<tr><td colspan="6">' . esc_html($state === 'new' ? 'Nincs élesítésre váró új termék.' : 'Nincs ellenőrizendő termék.') . '</td></tr>';
    }
    foreach ($ids as $id) {
        $product = wc_get_product($id);
        if (!$product) {
            continue;
        }
        $missing = mandala_onboarding_missing($product);
        $optional = array_values(array_map(fn($i) => $i[1], array_filter(mandala_onboarding_checklist($product), fn($i) => !$i[3] && !$i[2])));
        $thumb = $product->get_image_id() ? wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') : '';
        [$cat, $sub] = mandala_product_cats($id);
        echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $id . '" aria-label="' . esc_attr($product->get_name()) . '"></th>'
            . '<td>' . ($thumb ? '<img class="mandala-ob-thumb" src="' . esc_url($thumb) . '" alt="">' : '<span class="mandala-ob-thumb"></span>') . '</td>'
            . '<td><strong><a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html($product->get_name()) . '</a></strong><br><span class="description">' . esc_html(implode(' · ', array_filter([$product->get_sku(), mandala_fmt((float) $product->get_price()), $product->managing_stock() ? $product->get_stock_quantity() . ' db' : '', $sub ? mandala_term_name($sub) : ($cat ? mandala_term_name($cat) : 'nincs kategória')]))) . '</span>'
            . apply_filters('mandala_onboarding_row_extra', '', $product) . '</td>'
            . '<td>' . esc_html(wp_date('Y. m. d. H:i', (int) get_post_meta($id, '_mandala_onboarding_since', true))) . '</td>'
            . '<td><div class="mandala-chips">' . implode('', array_map(fn($m) => '<span class="mandala-chip missing">' . esc_html($m) . '</span>', $missing)) . implode('', array_map(fn($m) => '<span class="mandala-chip">' . esc_html($m) . '</span>', $optional)) . ($missing || $optional ? '' : '<span class="mandala-chip ai">minden megvan</span>') . '</div></td>'
            . '<td><a class="button" href="' . esc_url(get_edit_post_link($id)) . '">Szerkesztés</a> '
            . (!$missing ? '<a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=mandala_ob_approve&id=' . $id . '&tab=' . $tab), 'mandala_ob_approve_' . $id)) . '">' . ($state === 'new' ? 'Élesítés' : 'Kész') . '</a>' : '') . '</td></tr>';
    }
    echo '</tbody></table></form>';
    $pages = (int) ceil($total / 50);
    if ($pages > 1) {
        echo '<p>' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $paged, 'total' => $pages]) . '</p>'; // phpcs:ignore
    }
    echo '</div>';
}
