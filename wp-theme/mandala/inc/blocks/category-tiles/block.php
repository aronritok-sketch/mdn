<?php
/**
 * mandala/category-tile – egy termékkategória képes csempéje élő darabszámmal.
 * Méret: tall (2-3 oszlop), half (1-3 oszlopban kettő), wide (teljes szélesség, illusztrációval).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/category-tile', [
    'title' => 'Kategória csempe',
    'attributes' => [
        'category' => mandala_attr_def('szakralis-targyak'),
        'size' => mandala_attr_def('tall'),
        'image' => mandala_attr_def(''),
        'imageID' => mandala_attr_def('0', 'number'),
        'alt' => mandala_attr_def(''),
        'heading' => mandala_attr_def(''),
        'text' => mandala_attr_def(''),
        'eyebrow' => mandala_attr_def(''),
        'position' => mandala_attr_def(''),
        'art' => mandala_attr_def(''),
    ],
    'fields' => [['panel' => 'Kategória', 'fields' => [
        'category' => ['type' => 'text', 'label' => 'Kategória slug'],
        'size' => ['type' => 'select', 'label' => 'Méret', 'options' => [
            ['label' => 'Magas', 'value' => 'tall'], ['label' => 'Fél', 'value' => 'half'], ['label' => 'Széles', 'value' => 'wide'],
        ]],
        'heading' => ['type' => 'text', 'label' => 'Cím (üresen a kategória neve)'],
        'text' => ['type' => 'textarea', 'label' => 'Szöveg'],
        'eyebrow' => ['type' => 'text', 'label' => 'Felirat a cím felett'],
        'image' => ['type' => 'text', 'label' => 'Téma kép neve'],
        'imageID' => ['type' => 'image', 'label' => 'Vagy kép a médiatárból'],
        'alt' => ['type' => 'text', 'label' => 'Kép alternatív szövege'],
        'position' => ['type' => 'text', 'label' => 'Kép fókusza (object-position)'],
        'art' => ['type' => 'text', 'label' => 'Illusztráció kép helyett (pl. gift-saffron)'],
    ]]],
    'template' => function ($attributes) {
        $term = get_term_by('slug', $attributes['category'] ?? '', 'product_cat');
        if (!$term) {
            return '';
        }
        $size = in_array($attributes['size'] ?? '', ['tall', 'half', 'wide'], true) ? $attributes['size'] : 'tall';
        $sizes = ['tall' => '(max-width: 991px) 100vw, 66vw', 'half' => '(max-width: 991px) 100vw, 33vw', 'wide' => '100vw'][$size];
        $media = '';
        if (!empty($attributes['art'])) {
            // Inline SVG: a széles csempén a CSS átlátszóvá teszi a rajz hátterét.
            $file = MANDALA_DIR . '/assets/art/' . sanitize_file_name($attributes['art']) . '.svg';
            $media = is_readable($file) ? preg_replace('/<svg /', '<svg aria-hidden="true" ', str_replace(' xmlns="http://www.w3.org/2000/svg"', '', (string) file_get_contents($file)), 1) : '';
            $media = preg_replace('/ role="img" aria-label="[^"]*"/', '', $media);
        } else {
            $media = mandala_picture((int) ($attributes['imageID'] ?? 0) ?: ($attributes['image'] ?? ''), (string) ($attributes['alt'] ?? ''), [
                'sizes' => $sizes, 'style' => ($attributes['position'] ?? '') ? 'object-position:' . $attributes['position'] : '',
            ]);
        }
        $count = (int) $term->count;
        foreach (get_term_children($term->term_id, 'product_cat') as $child) {
            $count += (int) get_term($child, 'product_cat')->count;
        }
        $heading = $attributes['heading'] ?: $term->name;
        $out = '<a class="' . mandala_classes($attributes, 'cat-tile', 'cat-tile-' . $size, 'reveal') . '" href="' . esc_url(get_term_link($term)) . '">' . $media
            . '<div class="cat-body"><div>'
            . ($attributes['eyebrow'] ? '<p class="eyebrow">' . esc_html($attributes['eyebrow']) . '</p>' : '')
            . '<h3>' . esc_html($heading) . '</h3>'
            . ($attributes['text'] ? '<p>' . esc_html($attributes['text']) . '</p>' : '')
            . '</div>' . ($size !== 'wide' ? '<span class="count">' . esc_html(sprintf(_n('%d termék', '%d termék', $count, 'mandala'), $count)) . '</span>' : '')
            . '</div></a>';
        return $out;
    },
]);
