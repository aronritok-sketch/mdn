<?php
/** mandala/footer-bottom – szerzői jog, jogi menü, fizetési módok. mandala/logo – márkajel. */

defined('ABSPATH') || exit;

mandala_add_block('mandala/logo', [
    'title' => 'Logó',
    'template' => fn() => mandala_logo(),
]);

mandala_add_block('mandala/footer-bottom', [
    'title' => 'Lábléc alsó sáv',
    'attributes' => ['marks' => mandala_attr_def('Barion, VISA, Mastercard, Apple Pay, Utalás')],
    'fields' => [['panel' => 'Beállítások', 'fields' => [
        'marks' => ['type' => 'text', 'label' => 'Fizetési módok (vesszővel)'],
    ]]],
    'template' => function ($attributes) {
        $checkout = function_exists('is_checkout') && is_checkout() && !is_order_received_page();
        $legal = do_blocks('<!-- wp:mandala/menu {"location":"mandala-legal","variant":"inline","label":"Jogi információk"} /-->');
        $out = '<div class="footer-bottom">';
        $out .= '<p>© ' . esc_html(wp_date('Y')) . ' ' . esc_html(get_bloginfo('name')) . '. '
            . ($checkout ? mandala_icon('lock', 'ico ico-s') . ' ' . esc_html__('Titkosított kapcsolat', 'mandala') : esc_html__('Minden jog fenntartva.', 'mandala')) . '</p>';
        $out .= $legal;
        if (!$checkout && !empty($attributes['marks'])) {
            $out .= '<div class="payment-marks" aria-label="' . esc_attr__('Elfogadott fizetési módok', 'mandala') . '">';
            foreach (array_filter(array_map('trim', explode(',', $attributes['marks']))) as $mark) {
                $out .= '<span>' . esc_html($mark) . '</span>';
            }
            $out .= '</div>';
        }
        return $out . '</div>';
    },
]);
