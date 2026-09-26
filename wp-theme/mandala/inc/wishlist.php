<?php
/**
 * Kedvencek (wishlist plugin nélkül): a termékazonosítók a „mandala_wishlist” sütiben
 * élnek (a böngésző kezeli), így vendégként és belépve is működik, gyorsítótárazható.
 * Belépett vásárlónál a lista a felhasználói metába is mentődik (eszközök között).
 */

defined('ABSPATH') || exit;

const MANDALA_WISH_COOKIE = 'mandala_wishlist';

function mandala_wishlist_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $raw = isset($_COOKIE[MANDALA_WISH_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[MANDALA_WISH_COOKIE])) : '';
    $ids = array_values(array_unique(array_filter(array_map('absint', explode('.', $raw)))));
    if (is_user_logged_in()) {
        $saved = (array) get_user_meta(get_current_user_id(), 'mandala_wishlist', true);
        $ids = array_values(array_unique(array_filter(array_map('absint', array_merge($ids, $saved)))));
    }
    return $ids = array_slice($ids, 0, 100);
}

function mandala_wishlist_has(int $id): bool
{
    return in_array($id, mandala_wishlist_ids(), true);
}

/** Belépéskor a süti és a mentett lista összefésülése. */
add_action('wp_login', function ($login, $user) {
    $raw = isset($_COOKIE[MANDALA_WISH_COOKIE]) ? sanitize_text_field(wp_unslash($_COOKIE[MANDALA_WISH_COOKIE])) : '';
    $cookie = array_filter(array_map('absint', explode('.', $raw)));
    $saved = (array) get_user_meta($user->ID, 'mandala_wishlist', true);
    update_user_meta($user->ID, 'mandala_wishlist', array_values(array_unique(array_filter(array_map('absint', array_merge($saved, $cookie))))));
}, 10, 2);
