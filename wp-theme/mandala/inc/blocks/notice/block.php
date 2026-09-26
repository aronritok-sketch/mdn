<?php
/** mandala/notice – közlemény sáv a fejléc felett (ingyenes szállítás, import, visszaküldés). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/notice', [
    'title' => 'Közlemény sáv',
    'attributes' => [
        'items' => mandala_attr_def("Közvetlen import Nepálból és Indiából\n14 napos visszaküldés"),
    ],
    'fields' => [['panel' => 'Szöveg', 'fields' => [
        'items' => ['type' => 'textarea', 'label' => 'További elemek (soronként egy, mobilon rejtve)'],
    ]]],
    'template' => function ($attributes) {
        if (function_exists('is_checkout') && is_checkout() && !is_order_received_page()) {
            return '';
        }
        $free = (int) mandala_config('freeShippingFrom', 25000);
        $out = '<p class="mandala-notice"><span>' . mandala_icon('truck', 'ico ico-s') . '</span><span>'
            . sprintf(esc_html__('Ingyenes szállítás %s felett', 'mandala'), '<strong>' . esc_html(mandala_fmt($free)) . '</strong>') . '</span>';
        foreach (array_filter(array_map('trim', explode("\n", (string) ($attributes['items'] ?? '')))) as $item) {
            $out .= '<span class="sep hide-mobile">·</span><span class="hide-mobile">' . esc_html($item) . '</span>';
        }
        return $out . '</p>';
    },
]);
