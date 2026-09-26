<?php
/**
 * Akadálymentesség (EAA / WCAG 2.1 AA) – futásidejű javítások, amelyek a blokkok mentett
 * markupját nem érintik (így a szerkesztőben nem lesz „érvénytelen blokk”):
 *  - az iu/form mezők címkéje és mezője összekapcsolva (label for → id); a hibaüzenet és a
 *    súgó aria-describedby-vel kötődik (validate.js);
 *  - görgethető táblázat-dobozok billentyűzettel elérhetők (tabindex, role=region).
 * Az akadálymentességi nyilatkozat oldal: /akadalymentesseg/ (setup/content/akadalymentesseg.html).
 */

defined('ABSPATH') || exit;

add_filter('render_block', function ($html, $block) {
    static $n = 0;
    $name = (string) ($block['blockName'] ?? '');
    if (!str_starts_with($name, 'iu/form-') || $name === 'iu/form-accept' || !str_contains($html, '<label')) {
        return $html;
    }
    // Már összekapcsolt vagy a mezőt magába foglaló címke: nincs teendő.
    if (preg_match('/<label[^>]*\bfor=/', $html) || preg_match('/<label[^>]*>(?:(?!<\/label>).)*<(input|select|textarea)\b/s', $html)) {
        return $html;
    }
    if (!preg_match('/<(input|select|textarea)\b([^>]*)>/', $html, $m) || str_contains($m[2], 'type="hidden"')) {
        return $html;
    }
    $id = '';
    if (preg_match('/\bid="([^"]+)"/', $m[2], $idm)) {
        $id = $idm[1];
    } else {
        $field = preg_match('/\bname="([^"]+)"/', $m[2], $nm) ? sanitize_key($nm[1]) : 'mezo';
        $id = 'mf-' . $field . '-' . (++$n);
        $html = preg_replace('/<(input|select|textarea)\b/', '<$1 id="' . esc_attr($id) . '"', $html, 1);
    }
    return preg_replace('/<label\b/', '<label for="' . esc_attr($id) . '"', $html, 1);
}, 20, 2);

/** Görgethető táblázatok (tartalomban): fókuszálható régió. */
add_filter('the_content', function ($content) {
    return str_replace('<div class="table-wrap">', '<div class="table-wrap" tabindex="0" role="region" aria-label="' . esc_attr__('Táblázat (vízszintesen görgethető)', 'mandala') . '">', $content);
}, 20);
