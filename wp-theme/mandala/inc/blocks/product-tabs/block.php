<?php
/**
 * mandala/product-tabs – Leírás / Használat és gondozás / Szállítás és visszaküldés / Kérdés.
 * WAI-ARIA fülek (nyilakkal navigálható). A kérdés űrlap iu/form a sablonban (#kerdes).
 */

defined('ABSPATH') || exit;

mandala_add_block('mandala/product-tabs', [
    'title' => 'Termék fülek',
    'category' => 'iu-woocommerce',
    'template' => function ($attributes) {
        $product = mandala_current_product();
        if (!$product) {
            return '';
        }
        $id = $product->get_id();
        $description = apply_filters('the_content', get_post_field('post_content', $id));
        $ritual = get_post_meta($id, '_mandala_ritual', true) ?: __('Száraz, pormentes helyen tárold; puha kendővel tisztítsd.', 'mandala');
        $free = (int) mandala_config('freeShippingFrom', 25000);
        $shipping = '<div class="table-wrap"><table><thead><tr><th>' . esc_html__('Szállítási mód', 'mandala') . '</th><th>' . esc_html__('Idő', 'mandala') . '</th><th>' . esc_html__('Díj', 'mandala') . '</th></tr></thead><tbody>';
        foreach (mandala_config('shipping', []) as $s) {
            $shipping .= '<tr><td>' . esc_html($s['label']) . '</td><td>' . esc_html($s['note']) . '</td><td>'
                . ($s['price'] ? esc_html(mandala_fmt($s['price'])) . ' <span class="text-muted">(' . esc_html(sprintf(__('%s felett ingyenes', 'mandala'), mandala_fmt($free))) . ')</span>' : esc_html__('Ingyenes', 'mandala'))
                . '</td></tr>';
        }
        $info = get_permalink((int) get_option('mandala_page_informaciok'));
        $shipping .= '</tbody></table></div><p>' . esc_html__('A terméket az átvételtől számított 14 napon belül indoklás nélkül visszaküldheted.', 'mandala')
            . ($info ? ' <a href="' . esc_url($info . '#visszakuldes') . '">' . esc_html__('A visszaküldés menete', 'mandala') . '</a>' : '') . '</p>';
        $tabs = [
            [__('Leírás', 'mandala'), $description ?: '<p>' . esc_html(wp_strip_all_tags($product->get_short_description())) . '</p>'],
            [__('Használat és gondozás', 'mandala'), wpautop(esc_html($ritual))],
            [__('Szállítás és visszaküldés', 'mandala'), $shipping],
            [__('Kérdésed van?', 'mandala'), '<p>' . esc_html__('Hangfelvételt, pontos méretet vagy további fotót is kérhetsz – egy munkanapon belül válaszolunk e-mailben.', 'mandala') . '</p><p><a class="iu-button" href="#kerdes">' . esc_html__('Kérdést írok', 'mandala') . '</a></p>'],
        ];
        $nav = '';
        $panels = '';
        foreach ($tabs as $i => [$title, $body]) {
            $nav .= '<button type="button" role="tab" id="tab-' . $i . '" aria-controls="panel-' . $i . '" aria-selected="' . ($i ? 'false' : 'true') . '" tabindex="' . ($i ? '-1' : '0') . '">' . esc_html($title) . '</button>';
            $panels .= '<div class="iu-tab entry-content" role="tabpanel" id="panel-' . $i . '" aria-labelledby="tab-' . $i . '" tabindex="0"' . ($i ? ' hidden' : '') . '>' . $body . '</div>';
        }
        return '<div class="iu-tabs mandala-tabs"><div class="iu-tabs-nav" role="tablist" aria-label="' . esc_attr__('Termékinformációk', 'mandala') . '">' . $nav . '</div>' . $panels . '</div>';
    },
]);
