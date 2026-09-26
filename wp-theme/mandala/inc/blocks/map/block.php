<?php
/**
 * mandala/map – a bemutatóterem térképe. Beágyazási kód (Google Térkép iframe URL) nélkül
 * stilizált helykitöltő; a külső térkép csak kattintásra töltődik (adatvédelem, sebesség).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/map', [
    'title' => 'Térkép',
    'attributes' => ['embed' => mandala_attr_def(''), 'label' => mandala_attr_def('Budapest')],
    'fields' => [['panel' => 'Térkép', 'fields' => [
        'embed' => ['type' => 'text', 'label' => 'Google Térkép beágyazási URL (https://www.google.com/maps/embed?…)'],
        'label' => ['type' => 'text', 'label' => 'Felirat'],
    ]]],
    'template' => function ($attributes) {
        $embed = (string) ($attributes['embed'] ?? '');
        $bg = '<svg class="map-bg" viewBox="0 0 400 300" aria-hidden="true" preserveAspectRatio="xMidYMid slice"><g fill="none" stroke="currentColor" stroke-width="2"><path d="M0 80h400M0 190h400M120 0v300M290 0v300M0 250 400 40"/><path d="M40 0c40 90 20 180 90 300M330 0c-30 120 20 200-40 300" stroke-width="10" stroke-opacity=".6"/></g></svg>';
        $text = $embed && str_starts_with($embed, 'https://www.google.com/maps/embed')
            ? '<button type="button" class="iu-button iu-button-outline" data-map-load="' . esc_url($embed) . '">' . esc_html__('Térkép betöltése (Google)', 'mandala') . '</button>'
            : '<span>' . esc_html__('A térkép a pontos cím megadása után jelenik meg.', 'mandala') . '</span>';
        return '<div class="map-placeholder">' . $bg . '<div><span class="pin" aria-hidden="true"><span></span></span><strong style="color:var(--c-ink)">' . esc_html($attributes['label'] ?? '') . '</strong>' . $text . '</div></div>';
    },
]);
