<?php
/**
 * mandala/menu – menühely szerinti lista (láblécben). Az iu/menu a menü term ID-ját
 * kéri, ami telepítésenként más; ez a blokk a helyet (location) kapja, így a
 * sablonfájl hordozható.
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/menu', [
    'title' => 'Menü (hely szerint)',
    'attributes' => [
        'location' => mandala_attr_def('mandala-footer-shop'),
        'label' => mandala_attr_def(''),
        'variant' => mandala_attr_def('list'),
    ],
    'fields' => [['panel' => 'Menü', 'fields' => [
        'location' => ['type' => 'select', 'label' => 'Menühely', 'options' => [
            ['label' => 'Lábléc: Kínálat', 'value' => 'mandala-footer-shop'],
            ['label' => 'Lábléc: Vásárlás', 'value' => 'mandala-footer-help'],
            ['label' => 'Jogi linkek', 'value' => 'mandala-legal'],
            ['label' => 'Fő menü', 'value' => 'mandala-primary'],
        ]],
        'label' => ['type' => 'text', 'label' => 'Akadálymentes név (aria-label)'],
        'variant' => ['type' => 'select', 'label' => 'Megjelenés', 'options' => [
            ['label' => 'Lista', 'value' => 'list'], ['label' => 'Sorban (nav)', 'value' => 'inline'],
        ]],
    ]]],
    'template' => function ($attributes) {
        $locations = get_nav_menu_locations();
        $menu = $locations[$attributes['location'] ?? ''] ?? 0;
        if (!$menu) {
            return '';
        }
        $items = array_filter(wp_get_nav_menu_items($menu) ?: [], fn($i) => !(int) $i->menu_item_parent);
        $links = array_map(function ($i) {
            $attrs = '';
            foreach ((array) $i->classes as $class) {
                // „data-cookie-settings” osztály: a sütibeállítások megnyitása.
                if ($class === 'cookie-settings') {
                    $attrs .= ' data-cookie-settings';
                }
            }
            return '<a href="' . esc_url($i->url) . '"' . $attrs . '>' . esc_html($i->title) . '</a>';
        }, $items);
        if (($attributes['variant'] ?? 'list') === 'inline') {
            return '<nav class="' . mandala_classes($attributes, 'mandala-menu-inline') . '" aria-label="' . esc_attr($attributes['label'] ?: __('Jogi információk', 'mandala')) . '">' . implode('', $links) . '</nav>';
        }
        return '<ul class="' . mandala_classes($attributes, 'mandala-menu') . '"' . ($attributes['label'] ? ' aria-label="' . esc_attr($attributes['label']) . '"' : '') . '><li>' . implode('</li><li>', $links) . '</li></ul>';
    },
]);
