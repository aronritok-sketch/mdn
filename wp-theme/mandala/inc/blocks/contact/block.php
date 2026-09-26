<?php
/** mandala/contact – elérhetőségek listája (e-mail, telefon, cím, nyitvatartás) és közösségi ikonok. */

defined('ABSPATH') || exit;

mandala_add_block('mandala/contact', [
    'title' => 'Elérhetőségek',
    'attributes' => ['variant' => mandala_attr_def('list')],
    'fields' => [['panel' => 'Megjelenés', 'fields' => [
        'variant' => ['type' => 'select', 'label' => 'Változat', 'options' => [
            ['label' => 'Lista', 'value' => 'list'], ['label' => 'Közösségi ikonok', 'value' => 'social'], ['label' => 'Kártyák', 'value' => 'cards'],
        ]],
    ]]],
    'template' => function ($attributes) {
        $c = mandala_config('contact', []);
        $variant = $attributes['variant'] ?? 'list';
        if ($variant === 'social') {
            $out = '<div class="iu-icon-group">';
            foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram'] as $key => $label) {
                if (!empty($c[$key]) && $c[$key] !== '#') {
                    $out .= '<a class="iu-icon iu-icon-inverted" href="' . esc_url($c[$key]) . '" target="_blank" rel="noopener" aria-label="' . esc_attr($label) . '">' . mandala_icon($key) . '</a>';
                }
            }
            return $out . '</div>';
        }
        $rows = [
            ['mail', '<a href="mailto:' . esc_attr($c['email'] ?? '') . '">' . esc_html($c['email'] ?? '') . '</a>', __('E-mail', 'mandala')],
            ['phone', '<a href="tel:' . esc_attr(preg_replace('/\s+/', '', $c['phone'] ?? '')) . '">' . esc_html($c['phone'] ?? '') . '</a>', __('Telefon', 'mandala')],
            ['pin', '<span>' . esc_html($c['address'] ?? '') . '</span>', __('Bemutatóterem', 'mandala')],
            ['clock', '<span>' . esc_html($c['hours'] ?? '') . '</span>', __('Nyitvatartás', 'mandala')],
        ];
        if ($variant === 'cards') {
            $out = '<div class="contact-cards">';
            foreach ($rows as [$icon, $value, $label]) {
                $out .= '<div class="contact-card">' . mandala_icon($icon) . '<div><h3>' . esc_html($label) . '</h3><p>' . $value . '</p></div></div>';
            }
            return $out . '</div>';
        }
        $out = '<ul class="contact-list">';
        foreach ($rows as [$icon, $value]) {
            $out .= '<li>' . mandala_icon($icon, 'ico ico-s') . $value . '</li>';
        }
        return $out . '</ul>';
    },
]);
