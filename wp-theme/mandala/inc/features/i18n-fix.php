<?php
/**
 * A WooCommerce magyar fordításából hiányzó (újabb) szövegek pótlása.
 *
 * Csak akkor lép közbe, ha a szöveg fordítatlan maradt (a fordítás = az angol eredeti), és az oldal
 * nyelve magyar – egy WPML angol nyelvváltozatot nem érint. Ha a WooCommerce fordítása később
 * tartalmazza, az nyer.
 */

defined('ABSPATH') || exit;

function mandala_wc_missing_hu(): array
{
    return [
        // Akciós ár – képernyőolvasónak szóló rejtett szöveg (WooCommerce 8.x óta)
        'Original price was: %s.' => 'Eredeti ár: %s.',
        'Current price is: %s.' => 'Jelenlegi ár: %s.',
    ];
}

add_filter('gettext_woocommerce', function ($translation, $text) {
    if ($translation !== $text || !str_starts_with(determine_locale(), 'hu')) {
        return $translation;
    }
    return mandala_wc_missing_hu()[$text] ?? $translation;
}, 10, 2);
