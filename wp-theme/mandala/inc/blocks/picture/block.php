<?php
/**
 * mandala/picture – a témával szállított vagy médiatárbeli kép reszponzívan.
 * A hős háttérképéhez (hero-media) és keretes képekhez (media-frame).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/picture', [
    'title' => 'Kép (téma / médiatár)',
    'attributes' => [
        'image' => mandala_attr_def('hangtalak-gyertyafeny'),
        'imageID' => mandala_attr_def('0', 'number'),
        'alt' => mandala_attr_def(''),
        'sizes' => mandala_attr_def('100vw'),
        'frame' => mandala_attr_def('frame'),
        'eager' => mandala_attr_def('', 'boolean'),
        'position' => mandala_attr_def(''),
    ],
    'fields' => [['panel' => 'Kép', 'fields' => [
        'image' => ['type' => 'text', 'label' => 'Téma kép neve (assets/img)'],
        'imageID' => ['type' => 'image', 'label' => 'Vagy kép a médiatárból'],
        'alt' => ['type' => 'text', 'label' => 'Alternatív szöveg'],
        'frame' => ['type' => 'select', 'label' => 'Keret', 'options' => [
            ['label' => 'Keretes (media-frame)', 'value' => 'frame'], ['label' => 'Hős háttér', 'value' => 'hero'],
            ['label' => 'Oldalfej háttér', 'value' => 'page-hero'], ['label' => 'Nincs', 'value' => 'none'],
        ]],
        'position' => ['type' => 'text', 'label' => 'Kép fókusza (object-position, pl. 50% 20%)'],
        'eager' => ['type' => 'toggle', 'label' => 'Azonnal töltődjön (az oldal első képe)'],
    ]]],
    'template' => function ($attributes) {
        $img = mandala_picture((int) ($attributes['imageID'] ?? 0) ?: ($attributes['image'] ?? ''), (string) ($attributes['alt'] ?? ''), [
            'sizes' => $attributes['sizes'] ?? '100vw',
            'eager' => mandala_bool($attributes['eager'] ?? false),
            'style' => ($attributes['position'] ?? '') ? 'object-position:' . $attributes['position'] : '',
        ]);
        switch ($attributes['frame'] ?? 'frame') {
            case 'hero':
                return '<div class="' . mandala_classes($attributes, 'hero-media') . '">' . $img . '</div>';
            case 'page-hero':
                return '<div class="' . mandala_classes($attributes, 'page-hero-media') . '">' . $img . '</div>';
            case 'none':
                return $img;
            default:
                return '<div class="' . mandala_classes($attributes, 'media-frame') . '">' . $img . '</div>';
        }
    },
]);
