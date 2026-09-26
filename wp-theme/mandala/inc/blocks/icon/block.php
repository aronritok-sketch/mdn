<?php
/** mandala/icon – ikon az egységes készletből (bizalmi sáv, listák). */

defined('ABSPATH') || exit;

mandala_add_block('mandala/icon', [
    'title' => 'Ikon',
    'attributes' => ['name' => mandala_attr_def('pin'), 'size' => mandala_attr_def('')],
    'fields' => [['panel' => 'Ikon', 'fields' => [
        'name' => ['type' => 'select', 'label' => 'Ikon', 'options' => array_map(fn($n) => ['label' => $n, 'value' => $n], array_keys(mandala_data('icons')))],
        'size' => ['type' => 'select', 'label' => 'Méret', 'options' => [['label' => 'Alap', 'value' => ''], ['label' => 'Nagy', 'value' => 'ico-l'], ['label' => 'Extra', 'value' => 'ico-xl']]],
    ]]],
    'template' => fn($attributes) => mandala_icon((string) ($attributes['name'] ?? 'pin'), trim('ico ' . ($attributes['size'] ?? '') . ' ' . ($attributes['className'] ?? ''))),
]);
