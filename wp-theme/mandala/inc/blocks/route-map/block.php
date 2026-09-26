<?php
/** mandala/route-map – útvonaltérkép Nepálból és Indiából Budapestig (SVG). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/route-map', [
    'title' => 'Útvonaltérkép',
    'template' => function ($attributes) {
        $dot = fn($x, $y, $big = false) => sprintf('<circle cx="%1$d" cy="%2$d" r="%3$d" fill="currentColor"/><circle cx="%1$d" cy="%2$d" r="%4$d" fill="none" stroke="currentColor" stroke-opacity=".35"/>', $x, $y, $big ? 6 : 4, $big ? 14 : 10);
        $label = fn($x, $y, $text, $anchor = 'start', $cls = '') => sprintf('<text x="%d" y="%d" text-anchor="%s"%s>%s</text>', $x, $y, $anchor, $cls ? ' class="' . $cls . '"' : '', esc_html($text));
        $grid = '';
        for ($i = 0; $i < 7; $i++) {
            $grid .= '<path d="M0 ' . (40 + $i * 48) . 'H540" stroke="currentColor" stroke-opacity=".08"/>';
        }
        for ($i = 0; $i < 10; $i++) {
            $grid .= '<path d="M' . (30 + $i * 56) . ' 0V340" stroke="currentColor" stroke-opacity=".08"/>';
        }
        return '<div class="' . mandala_classes($attributes, 'route-map') . '"><svg class="route" viewBox="0 0 540 340" role="img" aria-label="' . esc_attr__('Útvonal Nepálból és Indiából Budapestig', 'mandala') . '">' . $grid
            . '<path class="path" d="M482 176C440 70 260 20 70 78" fill="none" stroke="currentColor" stroke-width="1.5"/>'
            . '<path class="path" d="M392 204C330 124 200 70 70 78" fill="none" stroke="currentColor" stroke-width="1" stroke-opacity=".6"/>'
            . '<path d="M346 250L392 204M372 306L346 250" fill="none" stroke="currentColor" stroke-opacity=".35" stroke-dasharray="2 5"/>'
            . $dot(70, 78, true) . $label(88, 82, 'Budapest') . $label(88, 100, __('innen indul a csomagod', 'mandala'), 'start', 't-small')
            . $dot(482, 176, true) . $label(482, 212, 'Katmandu', 'middle') . $label(482, 228, __('hangtál, szobor', 'mandala'), 'middle', 't-small')
            . $dot(392, 204) . $label(378, 208, 'Moradabad', 'end') . $label(378, 224, __('réz', 'mandala'), 'end', 't-small')
            . $dot(346, 250) . $label(332, 254, 'Jaipur', 'end') . $label(332, 270, __('textil, ékszer', 'mandala'), 'end', 't-small')
            . $dot(372, 306) . $label(358, 310, 'Bengaluru', 'end') . $label(358, 326, __('füstölők', 'mandala'), 'end', 't-small')
            . '</svg></div>';
    },
]);

/** mandala/hero-mandala – díszítő mandala a hős szekcióban. */
mandala_add_block('mandala/hero-mandala', [
    'title' => 'Díszítő mandala',
    'template' => function ($attributes) {
        $svg = @file_get_contents(MANDALA_DIR . '/assets/art/mandala.svg') ?: '';
        return preg_replace('/<svg[^>]*?class="mandala"/', '<svg class="hero-mandala"', str_replace(' xmlns="http://www.w3.org/2000/svg"', '', $svg), 1);
    },
]);
