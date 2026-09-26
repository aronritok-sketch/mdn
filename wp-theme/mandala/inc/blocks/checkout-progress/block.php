<?php
/** mandala/checkout-progress – Kosár › Pénztár › Visszaigazolás lépésjelző (az oldal állapota szerint). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/checkout-progress', [
    'title' => 'Rendelés lépései',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        if (!function_exists('is_checkout')) {
            return '';
        }
        $step = is_order_received_page() ? 3 : (is_checkout() ? 2 : 1);
        $steps = [[__('Kosár', 'mandala'), wc_get_cart_url()], [__('Pénztár', 'mandala'), wc_get_checkout_url()], [__('Visszaigazolás', 'mandala'), '']];
        $out = '<ol class="checkout-progress" aria-label="' . esc_attr__('Rendelés lépései', 'mandala') . '">';
        foreach ($steps as $i => [$label, $url]) {
            $n = $i + 1;
            if ($n < $step) {
                $inner = '<span class="step-dot">✓</span>' . esc_html($label);
                $out .= '<li class="is-done">' . ($n === 1 && $step === 2 ? '<a href="' . esc_url($url) . '" style="display:flex;gap:8px;align-items:center;color:inherit">' . $inner . '</a>' : $inner) . '</li>';
            } elseif ($n === $step) {
                $out .= '<li class="is-current" aria-current="step"><span class="step-dot">' . $n . '</span>' . esc_html($label) . '</li>';
            } else {
                $out .= '<li><span class="step-dot">' . $n . '</span>' . esc_html($label) . '</li>';
            }
        }
        return $out . '</ol>';
    },
]);

/**
 * Pénztárban és a köszönő oldalon nincs oldalfej (morzsamenü + „Pénztár” H1): a pénztár
 * saját (rejtett) H1-et ad, a köszönő oldal H1-e a „Köszönjük, …!”. Így oldalanként egy H1 marad.
 */
add_filter('render_block', function ($content, $block) {
    if (($block['blockName'] ?? '') === 'iu/section' && str_contains((string) ($block['attrs']['className'] ?? ''), 'page-head')
        && function_exists('is_checkout') && is_checkout()) {
        return '';
    }
    return $content;
}, 10, 2);
