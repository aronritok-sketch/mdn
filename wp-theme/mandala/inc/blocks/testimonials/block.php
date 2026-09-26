<?php
/** mandala/testimonials – vásárlói vélemények (3 oszlop). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/testimonials', [
    'title' => 'Vélemények',
    'attributes' => ['items' => mandala_attr_def('')],
    'fields' => [['panel' => 'Vélemények', 'fields' => [
        'items' => ['type' => 'textarea', 'label' => 'Soronként: Név | Vélemény (üresen a mintaadatok)'],
    ]]],
    'template' => function ($attributes) {
        $items = [];
        foreach (array_filter(array_map('trim', explode("\n", (string) ($attributes['items'] ?? '')))) as $line) {
            [$name, $text] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');
            $items[] = ['name' => $name, 'text' => $text];
        }
        $items = $items ?: (mandala_data('catalog')['testimonials'] ?? []);
        $out = '<div class="' . mandala_classes($attributes, 'quote-grid') . '">';
        foreach ($items as $q) {
            $initials = implode('', array_map(fn($w) => mb_substr($w, 0, 1), array_slice(explode(' ', $q['name']), 0, 2)));
            $out .= '<figure class="quote reveal"><blockquote>' . esc_html($q['text']) . '</blockquote><figcaption><span class="avatar" aria-hidden="true">'
                . esc_html($initials) . '</span><span><strong>' . esc_html($q['name']) . '</strong>' . esc_html__('Vásárló', 'mandala') . '</span></figcaption></figure>';
        }
        return $out . '</div>';
    },
]);
