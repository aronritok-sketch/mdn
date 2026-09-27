<?php
/**
 * Választó kvízek (mandala/quiz): füstölőválasztó és ajándékválasztó – a hangtál-választó mintájára,
 * a termékadatokból (illat, forma, szándék, kategória, ár) pontoz a böngészőben, kézi karbantartás
 * nélkül: új termék magától bekerül, elfogyott kimarad.
 *
 * Oldalak: /fustolo-valaszto/, /ajandek-valaszto/ (a telepítő hozza létre); a füstölők és az
 * ajándéktárgyak kategóriaoldalán felül egy „Segítünk választani” link.
 */

defined('ABSPATH') || exit;

/**
 * Kvízek: scope (mely termékek közül), kérdések → válaszok → pontozási szabályok.
 * Szabály: ['f' => mező (cat | sub | intents | attr.illat | attr.forma | name), 'v' => érték (vagy lista), 'w' => súly];
 * 'price' => [min, max] ársáv (benne +3, fölötte -6). 'why' => indoklás a találat alá.
 */
function mandala_quizzes(): array
{
    return (array) apply_filters('mandala_quizzes', [
        'fustolo' => [
            'title' => __('Füstölőválasztó', 'mandala'),
            'scope' => ['sub' => ['fustolok']],
            'shop' => ['cat' => 'fustolok'],
            'questions' => [
                ['illat', __('Milyen illatokat szeretsz?', 'mandala'), [
                    ['fas', __('Fás, meleg', 'mandala'), __('Szantál, cédrus, agarfa', 'mandala'), [['f' => 'attr.illat', 'v' => 'fás', 'w' => 3]], 'Fás illat'],
                    ['viragos', __('Virágos', 'mandala'), __('Lótusz, jázmin, rózsa', 'mandala'), [['f' => 'attr.illat', 'v' => 'virágos', 'w' => 3]], 'Virágos illat'],
                    ['edes', __('Édes', 'mandala'), __('Vanília, borostyán, gyanták', 'mandala'), [['f' => 'attr.illat', 'v' => 'édes', 'w' => 3]], 'Édes illat'],
                    ['gyogy', __('Gyógynövényes, friss', 'mandala'), __('Zsálya, levendula, citromfű', 'mandala'), [['f' => 'attr.illat', 'v' => 'gyógynövényes', 'w' => 3]], 'Gyógynövényes illat'],
                    ['foldes', __('Földes, mély', 'mandala'), __('Pacsuli, vetiver, templomi', 'mandala'), [['f' => 'attr.illat', 'v' => 'földes', 'w' => 3]], 'Földes illat'],
                    ['', __('Nem tudom még', 'mandala'), __('Mutass kedvenceket', 'mandala'), [['f' => 'featured', 'v' => true, 'w' => 1]], ''],
                ]],
                ['alkalom', __('Mikor gyújtanád meg?', 'mandala'), [
                    ['csend', __('Meditációhoz, jógához', 'mandala'), __('Befelé figyelés, elcsendesülés', 'mandala'), [['f' => 'intents', 'v' => 'csend', 'w' => 2]], 'Meditációhoz'],
                    ['otthon', __('Otthon, a hangulatért', 'mandala'), __('Esti lazítás, vendégvárás', 'mandala'), [['f' => 'intents', 'v' => 'otthon', 'w' => 2]], 'Otthoni hangulathoz'],
                    ['ajandek', __('Ajándékba', 'mandala'), __('Szép csomagolás, biztos választás', 'mandala'), [['f' => 'intents', 'v' => 'ajandek', 'w' => 2]], 'Ajándéknak is'],
                ]],
                ['forma', __('Milyen formát szeretnél?', 'mandala'), [
                    ['palcas', __('Pálcás', 'mandala'), __('A klasszikus, hosszan ég', 'mandala'), [['f' => 'attr.forma', 'v' => 'pálcás', 'w' => 2]], 'Pálcás'],
                    ['kup', __('Kúp', 'mandala'), __('Rövidebb, intenzívebb illat', 'mandala'), [['f' => 'attr.forma', 'v' => 'kúp', 'w' => 2]], 'Kúp forma'],
                    ['backflow', __('Visszaáramló (backflow)', 'mandala'), __('Látványos „füstvízesés” – tartó kell hozzá', 'mandala'), [['f' => 'attr.forma', 'v' => 'backflow kúp', 'w' => 3]], 'Füstvízeséshez'],
                    ['nincs', __('Pálca nélküli', 'mandala'), __('Kevesebb hamu, tisztább égés', 'mandala'), [['f' => 'attr.forma', 'v' => 'pálca nélküli', 'w' => 2]], 'Pálca nélküli'],
                    ['', __('Nem számít', 'mandala'), '', [], ''],
                ]],
                ['keret', __('Mennyit szánnál rá?', 'mandala'), [
                    ['0-2000', __('2 000 Ft alatt', 'mandala'), '', [], '', [0, 2000]],
                    ['2000-5000', __('2–5 000 Ft', 'mandala'), '', [], '', [2000, 5000]],
                    ['5000-999999', __('5 000 Ft felett', 'mandala'), __('Díszdobozos, válogatás', 'mandala'), [], '', [5000, 999999]],
                    ['', __('Nem számít', 'mandala'), '', [], ''],
                ]],
            ],
            // Ha backflow kúpot választ: a tartót is ajánljuk.
            'companions' => [['when' => ['forma' => 'backflow'], 'sub' => 'fustolotartok', 'name' => 'backflow', 'label' => __('Ehhez kell: backflow tartó', 'mandala')]],
        ],
        'ajandek' => [
            'title' => __('Ajándékválasztó', 'mandala'),
            'scope' => ['exclude_voucher' => true],
            'shop' => [],
            'questions' => [
                ['kinek', __('Kinek szánod?', 'mandala'), [
                    ['medit', __('Aki meditál, jógázik', 'mandala'), __('Befelé figyelő, spirituális', 'mandala'), [['f' => 'intents', 'v' => 'csend', 'w' => 3], ['f' => 'sub', 'v' => ['mala-lancok', 'hangtalak', 'fustolok'], 'w' => 1]], 'Meditációhoz, jógához'],
                    ['otthon', __('Aki szépíti az otthonát', 'mandala'), __('Dekor, textil, szobor', 'mandala'), [['f' => 'intents', 'v' => 'otthon', 'w' => 3], ['f' => 'cat', 'v' => 'lakberendezes', 'w' => 1]], 'Az otthonába'],
                    ['stilus', __('Aki a különleges ruhát, ékszert szereti', 'mandala'), __('Kézműves, egyedi', 'mandala'), [['f' => 'intents', 'v' => 'onkifejezes', 'w' => 3], ['f' => 'cat', 'v' => 'ruhazat-es-kiegeszitok', 'w' => 1]], 'Egyedi stílushoz'],
                    ['tea', __('Aki szereti a teát, a lassú reggeleket', 'mandala'), __('Tea, bögre, rézkulacs', 'mandala'), [['f' => 'sub', 'v' => ['teak', 'bogrek', 'rez-kulacsok'], 'w' => 4]], 'Teázáshoz'],
                    ['', __('Nem tudom pontosan', 'mandala'), __('Valami biztosat', 'mandala'), [['f' => 'intents', 'v' => 'ajandek', 'w' => 2]], 'Biztos választás'],
                ]],
                ['keret', __('Mekkora ajándékot képzelsz el?', 'mandala'), [
                    ['0-5000', __('Kis figyelmesség', 'mandala'), __('5 000 Ft alatt', 'mandala'), [], '', [0, 5000]],
                    ['5000-15000', __('Közepes', 'mandala'), __('5–15 000 Ft', 'mandala'), [], '', [5000, 15000]],
                    ['15000-999999', __('Különleges', 'mandala'), __('15 000 Ft felett', 'mandala'), [], '', [15000, 999999]],
                ]],
                ['atadas', __('Hogyan adnád át?', 'mandala'), [
                    ['csomag', __('Szépen becsomagolva', 'mandala'), __('Lokta papír, kézzel írt kártya', 'mandala'), [['f' => 'intents', 'v' => 'ajandek', 'w' => 1]], ''],
                    ['utalvany', __('Inkább válasszon ő', 'mandala'), __('Ajándékutalvány', 'mandala'), [], ''],
                    ['', __('Mindegy', 'mandala'), '', [], ''],
                ]],
            ],
            'companions' => [],
        ],
    ]);
}

add_action('init', function () {
    mandala_add_block('mandala/quiz', [
        'title' => 'Választó kvíz (füstölő / ajándék)',
        'category' => 'iu-woocommerce',
        'attributes' => ['quiz' => mandala_attr_def('fustolo')],
        'fields' => [['panel' => 'Beállítások', 'fields' => ['quiz' => ['type' => 'select', 'label' => 'Kvíz', 'options' => [['fustolo', 'Füstölőválasztó'], ['ajandek', 'Ajándékválasztó']]]]]],
        'template' => function ($attributes) {
            $key = sanitize_key($attributes['quiz'] ?? 'fustolo');
            $quiz = mandala_quizzes()[$key] ?? null;
            if (!$quiz) {
                return '';
            }
            wp_enqueue_script_module('mandala-quiz', MANDALA_URL . '/assets/js/quiz.js', [], MANDALA_VERSION);
            $total = count($quiz['questions']);
            $rules = [];
            $out = '<form class="finder quiz" data-quiz="' . esc_attr($key) . '" novalidate><div class="finder-progress" aria-hidden="true"><span style="width:' . round(100 / ($total + 1)) . '%"></span></div>';
            foreach ($quiz['questions'] as $i => $q) {
                [$qkey, $title, $options] = $q;
                $out .= '<fieldset class="finder-step" data-step="' . $i . '"' . ($i ? ' hidden' : '') . '><legend><span class="finder-count">' . ($i + 1) . ' / ' . $total . '</span>' . esc_html($title) . '</legend><div class="finder-options' . (count($options) > 5 ? ' is-compact' : '') . '">';
                foreach ($options as $j => $o) {
                    $id = 'q-' . $key . '-' . $qkey . '-' . $j;
                    $rules[$qkey][$o[0]] = ['boost' => $o[3] ?? [], 'why' => $o[4] ?? '', 'price' => $o[5] ?? null];
                    $out .= '<label class="finder-option" for="' . esc_attr($id) . '"><input type="radio" id="' . esc_attr($id) . '" name="' . esc_attr($qkey) . '" value="' . esc_attr($o[0]) . '"><span><strong>' . esc_html($o[1]) . '</strong>' . (!empty($o[2]) ? '<small>' . esc_html($o[2]) . '</small>' : '') . '</span></label>';
                }
                $out .= '</div></fieldset>';
            }
            $links = ['gift' => get_permalink((int) get_option('mandala_page_ajandekcsomag')) ?: '', 'voucher' => function_exists('mandala_url') ? mandala_url(['sku' => 'MND-UTALVANY']) : '', 'shop' => $quiz['shop'] ? (is_wp_error($l = get_term_link((string) $quiz['shop']['cat'], 'product_cat')) ? mandala_shop_url() : $l) : mandala_shop_url()];
            $config = ['scope' => $quiz['scope'], 'rules' => $rules, 'companions' => $quiz['companions'], 'links' => $links];
            $out .= '<div class="finder-result" data-finder-result aria-live="polite" hidden></div><div class="finder-nav"><button type="button" class="iu-button iu-button-link" data-finder-back hidden>' . mandala_icon('arrow-left', 'ico ico-s') . ' ' . esc_html__('Vissza', 'mandala') . '</button>'
                . '<button type="button" class="iu-button" data-finder-next disabled>' . esc_html__('Tovább', 'mandala') . ' ' . mandala_icon('arrow', 'ico ico-s') . '</button></div>'
                . '<script type="application/json" data-quiz-config>' . wp_json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>'
                . '<noscript><p>' . esc_html__('Az ajánláshoz engedélyezd a JavaScriptet, vagy nézd meg a teljes kínálatot.', 'mandala') . '</p></noscript></form>';
            return $out;
        },
    ]);
});

/** „Segítünk választani” a füstölők és az ajándéktárgyak kategóriaoldalán. */
add_filter('render_block', function ($html, $block) {
    if (($block['blockName'] ?? '') !== 'mandala/product-results' || !is_product_category()) {
        return $html;
    }
    $term = get_queried_object();
    $map = ['fustolok' => ['mandala_page_fustolo-valaszto', __('Nem tudod, melyik illat illik hozzád? Négy kérdés, és ajánlunk.', 'mandala')], 'ajandektargyak' => ['mandala_page_ajandek-valaszto', __('Ajándékot keresel? Három kérdés, és segítünk választani.', 'mandala')]];
    if (!$term || !isset($map[$term->slug]) || !($page = (int) get_option($map[$term->slug][0]))) {
        return $html;
    }
    return '<p class="quiz-promo">' . mandala_icon('sparkle', 'ico ico-s') . ' <span>' . esc_html($map[$term->slug][1]) . '</span> <a class="iu-button iu-button-small iu-button-outline" href="' . esc_url(get_permalink($page)) . '">' . esc_html(get_the_title($page)) . '</a></p>' . $html;
}, 9, 2);
