<?php
/**
 * A kínálat szűrőinek adatleírása PHP-ban – egyetlen forrás az új termékek ellenőrzőlistájához
 * (onboarding.php) és a Claude-alapú kategorizáláshoz (ai-catalog.php). A szűrő felülete
 * (assets/js/facets.js) ugyanezeket a mezőket használja.
 *
 * Mező: kulcs => [
 *   'label'    => címke,
 *   'source'   => 'attr' (pa_* tulajdonság) | 'meta' (termék meta, szám),
 *   'key'      => a taxonómia / meta kulcs,
 *   'multiple' => több érték adható-e,
 *   'scope'    => ['cats' => [...], 'subs' => [...]] – üres: minden termékre,
 *   'required' => ['cats' => [...], 'subs' => [...]] | true | false – ahol kötelező kitölteni,
 *   'range'    => [min, max] számnál,
 *   'hint'     => útmutató a kitöltéshez (a Claude is ezt kapja),
 * ]
 */

defined('ABSPATH') || exit;

function mandala_filter_schema(): array
{
    return apply_filters('mandala_filter_schema', [
        'szandek' => ['label' => 'Szándék', 'source' => 'attr', 'key' => 'pa_szandek', 'multiple' => true, 'scope' => [], 'required' => true,
            'hint' => 'Milyen vásárlói szándékhoz illik (1–3 érték). Az „ajandek” akkor, ha jó ajándék.'],
        'eredet' => ['label' => 'Eredet', 'source' => 'attr', 'key' => 'pa_eredet', 'multiple' => false, 'scope' => [], 'required' => true,
            'hint' => 'Származási ország. Csak ha a név, leírás vagy a régi adatok alapján egyértelmű.'],
        'hang' => ['label' => 'Hang', 'source' => 'attr', 'key' => 'pa_hang', 'multiple' => false, 'scope' => ['subs' => ['hangtalak']], 'required' => ['subs' => ['hangtalak']],
            'hint' => 'A tál mért alaphangja (C…B, a #-es hangok slugja pl. c-sharp).'],
        'hz' => ['label' => 'Frekvencia (Hz)', 'source' => 'meta', 'key' => '_mandala_hz', 'multiple' => false, 'scope' => ['subs' => ['hangtalak']], 'required' => ['subs' => ['hangtalak']], 'range' => [40, 2000],
            'hint' => 'Mért alapfrekvencia Hz-ben, ha szerepel az adatokban (pl. „405 Hz”). Ne becsüld.'],
        'suly' => ['label' => 'Súly (g)', 'source' => 'meta', 'key' => '_mandala_suly', 'multiple' => false, 'scope' => ['subs' => ['hangtalak']], 'required' => ['subs' => ['hangtalak']], 'range' => [20, 15000],
            'hint' => 'A tál súlya grammban, ha szerepel (pl. „490 g”, „0,49 kg”). Ne becsüld.'],
        'csakra' => ['label' => 'Csakra', 'source' => 'attr', 'key' => 'pa_csakra', 'multiple' => true, 'scope' => ['subs' => ['hangtalak', 'mala-lancok', 'ekszerek']], 'required' => ['subs' => ['hangtalak']],
            'hint' => 'Csak ha az adatok említik (hangtálnál a hang alapján: C gyökér, D szakrális, E napfonat, F szív, G torok, A homlok, B korona).'],
        'keszites' => ['label' => 'Készítés', 'source' => 'attr', 'key' => 'pa_keszites', 'multiple' => false, 'scope' => ['subs' => ['hangtalak']], 'required' => ['subs' => ['hangtalak']],
            'hint' => 'Kézzel kovácsolt / öntött / gépi – csak ha egyértelmű.'],
        'illat' => ['label' => 'Illat', 'source' => 'attr', 'key' => 'pa_illat', 'multiple' => true, 'scope' => ['subs' => ['fustolok']], 'required' => ['subs' => ['fustolok']], 'hint' => 'A füstölő illata(i).'],
        'forma' => ['label' => 'Füstölő típusa', 'source' => 'attr', 'key' => 'pa_forma', 'multiple' => false, 'scope' => ['subs' => ['fustolok']], 'required' => ['subs' => ['fustolok']], 'hint' => 'Pálcika, kúp, backflow kúp, por, gyanta…'],
        'meret' => ['label' => 'Méret', 'source' => 'attr', 'key' => 'pa_meret', 'multiple' => true, 'scope' => ['cats' => ['ruhazat-es-kiegeszitok']], 'required' => ['cats' => ['ruhazat-es-kiegeszitok']], 'hint' => 'Ruházati méret(ek).'],
        'anyag' => ['label' => 'Anyag', 'source' => 'attr', 'key' => 'pa_anyag', 'multiple' => true, 'scope' => [], 'required' => false, 'hint' => 'Fő anyag(ok): réz, bronz, pamut, selyem, fa, féldrágakő…'],
        'szin' => ['label' => 'Szín', 'source' => 'attr', 'key' => 'pa_szin', 'multiple' => true, 'scope' => [], 'required' => ['cats' => ['ruhazat-es-kiegeszitok']], 'hint' => 'Fő szín(ek).'],
    ]);
}

/** Illeszkedik-e egy hatókör ([cats, subs] | true | false | []) a termék fő- és alkategóriájára. */
function mandala_scope_match($scope, string $cat, string $sub): bool
{
    if ($scope === true || $scope === []) {
        return true;
    }
    if (!$scope) {
        return false;
    }
    return in_array($cat, (array) ($scope['cats'] ?? []), true) || in_array($sub, (array) ($scope['subs'] ?? []), true);
}

/** Egy termék jelenlegi szűrőértékei a séma szerint: kulcs => érték(ek) (slug-ok / szám). */
function mandala_product_filter_values(WC_Product $product): array
{
    $out = [];
    foreach (mandala_filter_schema() as $key => $f) {
        if ($f['source'] === 'attr') {
            $out[$key] = mandala_attr($product, $f['key'], 'slug');
        } else {
            $v = $product->get_meta($f['key'], true, 'edit');
            $out[$key] = is_numeric($v) && (float) $v > 0 ? (float) $v : null;
        }
    }
    return $out;
}

/** Egy termék egy tulajdonságának beállítása kifejezés-slugok alapján (a hiányzó kifejezést nem hozza létre). */
function mandala_set_product_attr(WC_Product $product, string $taxonomy, array $slugs): void
{
    $attributes = $product->get_attributes('edit');
    $ids = [];
    foreach ($slugs as $slug) {
        $term = get_term_by('slug', sanitize_title($slug), $taxonomy);
        if ($term) {
            $ids[] = (int) $term->term_id;
        }
    }
    if (!$ids) {
        unset($attributes[$taxonomy]);
    } else {
        $attr = $attributes[$taxonomy] ?? new WC_Product_Attribute();
        $attr->set_id(wc_attribute_taxonomy_id_by_name($taxonomy));
        $attr->set_name($taxonomy);
        $attr->set_options($ids);
        $attr->set_visible(!in_array($taxonomy, ['pa_szandek', 'pa_eredet', 'pa_regio'], true));
        $attr->set_variation(false);
        $attributes[$taxonomy] = $attr;
    }
    $product->set_attributes($attributes);
}

/** Kategória-azonosítók fő- és alkategória slugból. */
function mandala_category_ids(string $cat, string $sub = ''): array
{
    $ids = [];
    foreach (array_filter([$cat, $sub]) as $slug) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term) {
            $ids[] = (int) $term->term_id;
        }
    }
    return $ids;
}
