<?php
/**
 * Saját blokkok betöltése: inc/blocks/{név}/block.php (az iu_custom_blocks
 * mu-plugin iucb_add_block() függvényével, JS build nélkül).
 */

defined('ABSPATH') || exit;

/**
 * iucb_add_block() burkoló közös alapértékekkel. Ha az iu_custom_blocks nincs
 * telepítve, szerveroldali blokként regisztrál, hogy a frontend akkor se törjön el.
 */
function mandala_add_block(string $name, array $args): void
{
    $args += [
        'category' => 'mandala',
        'attributes' => [],
        'fields' => [],
    ];
    if (!isset($args['editJS'])) {
        // Szerkesztőben egyszerű címke: a legtöbb blokk élő adatból dolgozik (termékek, menü).
        $args['editJS'] = 'return wp.element.createElement("div", {className: "mandala-block-placeholder"}, ' . wp_json_encode('Mandala: ' . $args['title']) . ');';
    }
    $template = $args['template'];
    $args['template'] = function ($attributes, $children = []) use ($template, $name) {
        $attributes = is_array($attributes) ? $attributes : [];
        try {
            return (string) $template($attributes, $children);
        } catch (Throwable $e) {
            // Egy hibás blokk ne vigye el az egész oldalt.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                return '<!-- ' . esc_html($name . ': ' . $e->getMessage()) . ' -->';
            }
            error_log('[mandala] ' . $name . ': ' . $e->getMessage());
            return '';
        }
    };

    if (function_exists('iucb_add_block')) {
        iucb_add_block($name, $args);
        return;
    }
    $attributes = $args['attributes'];
    $attributes['className'] = $attributes['className'] ?? ['type' => 'string', 'default' => ''];
    register_block_type($name, [
        'title' => $args['title'],
        'category' => $args['category'],
        'attributes' => $attributes,
        'render_callback' => function ($attrs, $content) use ($args, $attributes) {
            foreach ($attributes as $key => $def) {
                if (!array_key_exists($key, $attrs) && array_key_exists('default', $def)) {
                    $attrs[$key] = $def['default'];
                }
            }
            return do_shortcode(($args['template'])($attrs, $content ? [$content] : []));
        },
    ]);
}

/** Szöveg attribútum rövidítés. */
function mandala_attr_def(string $default = '', string $type = 'string'): array
{
    return ['type' => $type, 'default' => $type === 'boolean' ? (bool) $default : $default];
}

/** A blokk-attribútum className értéke + saját osztályok. */
function mandala_classes(array $attributes, string ...$classes): string
{
    $classes[] = $attributes['className'] ?? '';
    return esc_attr(trim(implode(' ', array_filter($classes))));
}

add_filter('block_categories_all', function ($categories) {
    array_unshift($categories, ['slug' => 'mandala', 'title' => 'Mandala', 'icon' => null]);
    return $categories;
});

add_action('init', function () {
    foreach (glob(MANDALA_DIR . '/inc/blocks/*/block.php') ?: [] as $file) {
        require_once $file;
    }
}, 20);

/**
 * Opcionális szekció: a „mandala-optional” osztályú iu/section eltűnik, ha a benne lévő
 * dinamikus blokkok nem adtak tartalmat (pl. nincs érkező szállítmány vagy esemény).
 */
add_filter('render_block', function ($content, $block) {
    if (($block['blockName'] ?? '') === 'iu/section' && str_contains((string) ($block['attrs']['className'] ?? ''), 'mandala-optional')
        && trim(wp_strip_all_tags($content)) === '' && !preg_match('/<(img|svg|iframe|input|button)\b/i', $content)) {
        return '';
    }
    // Cikkek nélkül (új bolt, még nincs magazin) a „mandala-needs-posts” szekció nem jelenik meg.
    if (($block['blockName'] ?? '') === 'iu/section' && str_contains((string) ($block['attrs']['className'] ?? ''), 'mandala-needs-posts')
        && !(int) (wp_count_posts('post')->publish ?? 0)) {
        return '';
    }
    // Üres magazin: a kategóriaszűrő elmarad, a lista helyén rövid üzenet.
    $name = $block['blockName'] ?? '';
    $blog_list = ($name === 'iu/query' && !empty($block['attrs']['main_query'])) || ($name === 'iu/terms' && ($block['attrs']['taxonomy'] ?? '') === 'category');
    if ($blog_list && (is_home() || is_category()) && !have_posts()) {
        return $name === 'iu/query'
            ? '<p class="empty-note">' . esc_html__('Az első cikkeken dolgozunk – hamarosan itt olvashatod őket. Addig nézz körül a kínálatban!', 'mandala') . '</p>'
            : '';
    }
    return $content;
}, 10, 2);

/**
 * iu/search: a keretrendszer gombja dashicons ikont használ, ami a látogatói oldalon nem töltődik be
 * (üres fekete sáv lett belőle). Saját, címkézett űrlap ikonnal és felirattal.
 */
add_filter('render_block', function ($content, $block) {
    if (($block['blockName'] ?? '') !== 'iu/search' || is_admin()) {
        return $content;
    }
    static $n = 0;
    $id = 'inline-search-' . (++$n);
    $placeholder = (string) ($block['attrs']['placeholder'] ?? __('Mit keresel?', 'mandala'));
    return '<form class="search-inline" role="search" action="' . esc_url(home_url('/')) . '">'
        . '<label class="sr-only" for="' . $id . '">' . esc_html__('Keresés', 'mandala') . '</label>'
        . '<input id="' . $id . '" name="s" type="search" value="' . esc_attr(get_search_query()) . '" placeholder="' . esc_attr($placeholder) . '">'
        . '<button class="iu-button iu-button-default" type="submit">' . mandala_icon('search') . '<span>' . esc_html__('Keresés', 'mandala') . '</span></button></form>';
}, 10, 2);

/** Keresési találatoknál a morzsamenü ne a Magazint mutassa szülőként (a keretrendszer a blogoldalt teszi be). */
add_filter('render_block', function ($content, $block) {
    if (($block['blockName'] ?? '') !== 'iu/breadcrumbs' || !is_search()) {
        return $content;
    }
    return '<nav class="iu-breadcrumbs" aria-label="' . esc_attr__('Morzsamenü', 'mandala') . '"><div class="iu-breadcrumbs-wrap">'
        . '<span><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Kezdőlap', 'mandala') . '</a></span><span class="iu-breadcrumbs-separator">/</span>'
        . '<span class="iu-breadcrumbs-current">' . esc_html__('Keresés', 'mandala') . '</span></div></nav>';
}, 10, 2);

/** Szerkesztői jelölés a JS nélküli blokkokhoz. */
add_action('enqueue_block_editor_assets', function () {
    wp_add_inline_style('wp-edit-blocks', '.mandala-block-placeholder{padding:14px 16px;border:1px dashed #A9581A;border-radius:6px;background:#F7F4EE;color:#6E6357;font:500 13px/1.4 system-ui}');
});
