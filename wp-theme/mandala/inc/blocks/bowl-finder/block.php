<?php
/**
 * mandala/bowl-finder – Hangtál-választó: néhány kérdés alapján a kínálatból ajánl
 * hangtálakat, indoklással (Hz, súly, csakra, készítés, keret), és a kínálat szűrőjére
 * mutató linkkel. A pontozás a böngészőben fut a termékindexből (assets/js/finder.js).
 */

defined('ABSPATH') || exit;

function mandala_finder_questions(): array
{
    return [
        ['cel', __('Mire használnád leginkább?', 'mandala'), [
            ['csend', __('Meditáció, elcsendesülés', 'mandala'), __('Napi gyakorláshoz, egyedül', 'mandala')],
            ['terapia', __('Hangfürdő, hangterápia', 'mandala'), __('Másokkal, csoportban vagy kezelésben', 'mandala')],
            ['otthon', __('Otthoni harmónia', 'mandala'), __('A tér hangolásához, dísznek is', 'mandala')],
            ['ajandek', __('Ajándékba', 'mandala'), __('Valakinek, aki fontos', 'mandala')],
        ]],
        ['tapasztalat', __('Mennyi tapasztalatod van a hangtálakkal?', 'mandala'), [
            ['kezdo', __('Most kezdem', 'mandala'), __('Könnyen megszólaltatható tál kell', 'mandala')],
            ['halado', __('Már használok', 'mandala'), __('Gazdagabb felhangokat keresek', 'mandala')],
            ['profi', __('Hivatásszerűen', 'mandala'), __('Kézzel kovácsolt, egyedi darab', 'mandala')],
        ]],
        ['hang', __('Milyen hangot szeretsz?', 'mandala'), [
            ['magas', __('Tiszta, csengő', 'mandala'), __('Magasabb hang, könnyebb tál', 'mandala')],
            ['kozep', __('Kiegyensúlyozott', 'mandala'), __('Középső hangfekvés', 'mandala')],
            ['mely', __('Mély, zengő', 'mandala'), __('Hosszan zengő, nehezebb tál', 'mandala')],
        ]],
        ['csakra', __('Van csakra, amelyre figyelnél?', 'mandala'), array_merge(
            [['', __('Nem számít', 'mandala'), __('A hang a fontos', 'mandala')]],
            array_map(fn($c) => [$c[0], $c[1], ''], [['gyoker', __('Gyökér', 'mandala')], ['szakralis', __('Szakrális', 'mandala')], ['napfonat', __('Napfonat', 'mandala')], ['sziv', __('Szív', 'mandala')], ['torok', __('Torok', 'mandala')], ['homlok', __('Homlok', 'mandala')], ['korona', __('Korona', 'mandala')]])
        )],
        ['keret', __('Mekkora kerettel számolsz?', 'mandala'), [
            ['0-15000', __('15 000 Ft alatt', 'mandala'), ''],
            ['15000-30000', __('15–30 000 Ft', 'mandala'), ''],
            ['30000-50000', __('30–50 000 Ft', 'mandala'), ''],
            ['50000-999999', __('50 000 Ft felett', 'mandala'), ''],
            ['', __('Nem számít', 'mandala'), ''],
        ]],
    ];
}

mandala_add_block('mandala/bowl-finder', [
    'title' => 'Hangtál-választó',
    'category' => 'iu-woocommerce',
    'attributes' => ['category' => mandala_attr_def('hangtalak')],
    'fields' => [['panel' => 'Beállítások', 'fields' => [
        'category' => ['type' => 'text', 'label' => 'Kategória slug (amelyből ajánl)'],
    ]]],
    'template' => function ($attributes) {
        wp_enqueue_script_module('mandala-finder', MANDALA_URL . '/assets/js/finder.js', [], MANDALA_VERSION);
        $questions = mandala_finder_questions();
        $total = count($questions);
        $out = '<form class="finder" data-finder data-category="' . esc_attr($attributes['category'] ?? 'hangtalak') . '" novalidate>'
            . '<div class="finder-progress" aria-hidden="true"><span style="width:' . round(100 / $total) . '%"></span></div>';
        foreach ($questions as $i => [$key, $title, $options]) {
            $out .= '<fieldset class="finder-step" data-step="' . $i . '"' . ($i ? ' hidden' : '') . '><legend><span class="finder-count">' . ($i + 1) . ' / ' . $total . '</span>' . esc_html($title) . '</legend><div class="finder-options' . (count($options) > 5 ? ' is-compact' : '') . '">';
            foreach ($options as $j => [$value, $label, $hint]) {
                $id = 'f-' . $key . '-' . $j;
                $out .= '<label class="finder-option" for="' . esc_attr($id) . '"><input type="radio" id="' . esc_attr($id) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"><span><strong>' . esc_html($label) . '</strong>' . ($hint ? '<small>' . esc_html($hint) . '</small>' : '') . '</span></label>';
            }
            $out .= '</div></fieldset>';
        }
        $out .= '<div class="finder-result" data-finder-result aria-live="polite" hidden></div><div class="finder-nav"><button type="button" class="iu-button iu-button-link" data-finder-back hidden>' . mandala_icon('arrow-left', 'ico ico-s') . ' ' . esc_html__('Vissza', 'mandala') . '</button>'
            . '<button type="button" class="iu-button" data-finder-next disabled>' . esc_html__('Tovább', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</button></div>'
            . '<noscript><p>' . esc_html__('Az ajánláshoz engedélyezd a JavaScriptet, vagy nézd meg a teljes hangtál-kínálatot.', 'mandala') . '</p></noscript></form>';
        return $out;
    },
]);
