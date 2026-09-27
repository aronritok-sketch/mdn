<?php
/**
 * Gyorsítás – egyszeri beállítás, utána magától.
 *
 *  - Új feltöltéseknél a kisebb képméretek WebP-ben készülnek (ha a tárhely képkezelője tudja):
 *    ugyanaz a minőség harmadannyi méretben.
 *  - Meglévő képek: a háttérben, 15 képenként újragenerálja a méreteket WebP-ben (a telepítő
 *    varázsló „Gyorsítás” lépéséből indítható).
 *  - Felesleges betöltések kikapcsolása: WordPress emoji szkript és stílus, oEmbed felfedezés.
 *  - Gyorsítótár-bővítmény ellenőrzése (a varázsló jelzi, ha nincs).
 */

defined('ABSPATH') || exit;

function mandala_webp_supported(): bool
{
    return function_exists('wp_image_editor_supports') && wp_image_editor_supports(['mime_type' => 'image/webp']);
}

add_filter('image_editor_output_format', function ($formats) {
    if (apply_filters('mandala_webp', true) && mandala_webp_supported()) {
        $formats['image/jpeg'] = 'image/webp';
        $formats['image/png'] = 'image/webp';
    }
    return $formats;
});

/* ---------- Meglévő képek WebP-re ---------- */

function mandala_webp_pending(int $limit): array
{
    global $wpdb;
    $after = (int) get_option('mandala_webp_cursor', 0);
    return array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png') AND ID > %d ORDER BY ID ASC LIMIT %d", $after, $limit)));
}
function mandala_webp_start(): int
{
    global $wpdb;
    update_option('mandala_webp_cursor', 0, false);
    update_option('mandala_webp_state', 'running', false);
    if (function_exists('as_schedule_single_action') && !as_next_scheduled_action('mandala_webp_batch', [], MANDALA_AS_GROUP)) {
        as_schedule_single_action(time() + 5, 'mandala_webp_batch', [], MANDALA_AS_GROUP);
    }
    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png')");
}
add_action('mandala_webp_batch', function () {
    if (get_option('mandala_webp_state') !== 'running' || !mandala_webp_supported()) {
        return;
    }
    require_once ABSPATH . 'wp-admin/includes/image.php';
    $ids = mandala_webp_pending(15);
    foreach ($ids as $id) {
        $file = get_attached_file($id);
        if ($file && file_exists($file)) {
            $old = wp_get_attachment_metadata($id);
            $meta = wp_generate_attachment_metadata($id, $file);
            if ($meta) {
                // A régi méretfájlok törlése (a WebP változatok a helyükre kerültek).
                foreach ((array) ($old['sizes'] ?? []) as $size) {
                    $path = path_join(dirname($file), $size['file']);
                    if (!in_array($size['file'], array_column((array) ($meta['sizes'] ?? []), 'file'), true) && file_exists($path)) {
                        wp_delete_file($path);
                    }
                }
                wp_update_attachment_metadata($id, $meta);
            }
        }
        update_option('mandala_webp_cursor', $id, false);
    }
    if ($ids) {
        as_schedule_single_action(time() + 10, 'mandala_webp_batch', [], MANDALA_AS_GROUP);
    } else {
        update_option('mandala_webp_state', 'done', false);
        if (function_exists('mandala_flush_index')) {
            mandala_flush_index();
        }
    }
});

/* ---------- Felesleges betöltések ---------- */

add_action('init', function () {
    if (!apply_filters('mandala_disable_emoji', true)) {
        return;
    }
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    add_filter('emoji_svg_url', '__return_false');
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
});

/** Van-e oldal-gyorsítótár bővítmény (a varázsló ellenőrzi). */
function mandala_cache_plugin(): string
{
    $known = ['litespeed-cache/litespeed-cache.php' => 'LiteSpeed Cache', 'wp-rocket/wp-rocket.php' => 'WP Rocket', 'w3-total-cache/w3-total-cache.php' => 'W3 Total Cache', 'wp-super-cache/wp-cache.php' => 'WP Super Cache', 'wp-fastest-cache/wpFastestCache.php' => 'WP Fastest Cache', 'sg-cachepress/sg-cachepress.php' => 'SiteGround Optimizer', 'breeze/breeze.php' => 'Breeze'];
    foreach ($known as $file => $name) {
        if (in_array($file, (array) get_option('active_plugins', []), true)) {
            return $name;
        }
    }
    return defined('WP_CACHE') && WP_CACHE ? 'WP_CACHE (tárhely)' : '';
}
