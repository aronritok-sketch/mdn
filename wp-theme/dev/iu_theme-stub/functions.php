<?php
/**
 * iu_theme TESZT HELYETTESÍTŐ – csak helyi fejlesztéshez és automata teszthez.
 * A viselkedés a skill referenciáját (iu_theme_documentation.md) követi, egyszerűsítve.
 */

defined('ABSPATH') || exit;

add_action('after_setup_theme', function () {
    add_theme_support('post-thumbnails');
    add_theme_support('menus');
    add_theme_support('title-tag');
});

add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('iu-theme', get_template_directory_uri() . '/iu.css', [], '0.1');
    wp_enqueue_style('iu-child-style', get_stylesheet_uri(), ['iu-theme'], wp_get_theme()->get('Version'));
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_enqueue_script('iu-stub', get_template_directory_uri() . '/stub.js', [], '0.1', true);
}, 10);

/* ---------- Sablonmotor (templates.php) ---------- */

function iu_query_types(): array
{
    if (is_front_page()) {
        return ['front_page', 'single_page'];
    }
    if (is_home()) {
        return ['blog_page'];
    }
    if (is_404()) {
        return ['404'];
    }
    if (is_search()) {
        return ['search'];
    }
    if (is_category()) {
        return ['tax_category'];
    }
    if (is_tag()) {
        return ['tax_post_tag'];
    }
    if (is_singular()) {
        return ['single_' . get_post_type()];
    }
    if (is_tax()) {
        return ['tax_' . get_queried_object()->taxonomy];
    }
    if (is_archive()) {
        $pt = get_query_var('post_type') ?: 'post';
        return ['archive_' . (is_array($pt) ? reset($pt) : $pt)];
    }
    return [];
}

function iu_template(string $position): string
{
    foreach (array_merge(iu_query_types(), ['global']) as $type) {
        foreach ([get_stylesheet_directory(), get_template_directory()] as $dir) {
            $file = "{$dir}/templates/{$type}_{$position}.html";
            if (is_readable($file)) {
                return (string) file_get_contents($file);
            }
        }
    }
    return '';
}

function iu_render(string $markup): string
{
    return do_shortcode(do_blocks($markup));
}

/* ---------- Saját blokkok (iu_custom_blocks mu-plugin) ---------- */

function iucb_add_block(string $name, array $args): void
{
    $attributes = $args['attributes'] ?? [];
    $attributes['className'] = $attributes['className'] ?? ['type' => 'string', 'default' => ''];
    register_block_type($name, [
        'title' => $args['title'] ?? $name,
        'category' => $args['category'] ?? 'widgets',
        'attributes' => $attributes,
        'render_callback' => function ($attrs, $content) use ($args, $attributes) {
            foreach ($attributes as $k => $def) {
                if (!array_key_exists($k, $attrs) && array_key_exists('default', $def)) {
                    $attrs[$k] = $def['default'];
                }
            }
            return do_shortcode(($args['template'])($attrs, $content ? [$content] : []));
        },
    ]);
}

/* ---------- Dinamikus iu blokkok ---------- */

function iu_context_title(): string
{
    if (is_search()) {
        return 'Keresés erre: ' . get_search_query();
    }
    if (is_home()) {
        return get_the_title((int) get_option('page_for_posts'));
    }
    if (is_category() || is_tax() || is_tag()) {
        return single_term_title('', false);
    }
    if (is_404()) {
        return '404';
    }
    return get_the_title(get_queried_object_id());
}

add_action('init', function () {
    $dyn = function ($name, $cb, $attrs = []) {
        register_block_type($name, ['attributes' => $attrs + ['className' => ['type' => 'string', 'default' => '']], 'render_callback' => $cb]);
    };
    $dyn('iu/title', fn($a) => sprintf('<%1$s class="iu-title iu-heading">%2$s</%1$s>', tag_escape($a['heading'] ?? 'h2'), esc_html(iu_context_title())), ['heading' => ['type' => 'string', 'default' => 'h2']]);
    $dyn('iu/content', function () {
        $post = get_post(get_queried_object_id());
        if (!$post || !is_singular()) {
            return '';
        }
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        remove_filter('the_content', 'wpautop');
        $out = apply_filters('the_content', $post->post_content);
        add_filter('the_content', 'wpautop');
        return $out;
    });
    $dyn('iu/breadcrumbs', function () {
        $items = [['Kezdőlap', home_url('/')]];
        if (is_singular('post') || is_category()) {
            $items[] = ['Magazin', get_permalink((int) get_option('page_for_posts'))];
        }
        if (is_singular('product') && ($terms = get_the_terms(get_queried_object_id(), 'product_cat'))) {
            $term = end($terms);
            foreach (array_reverse(get_ancestors($term->term_id, 'product_cat')) as $a) {
                $items[] = [get_term($a)->name, get_term_link($a)];
            }
            $items[] = [$term->name, get_term_link($term)];
        }
        $items[] = [is_search() ? 'Keresés' : iu_context_title(), ''];
        $out = '<nav class="iu-breadcrumbs" aria-label="Morzsamenü"><ol itemscope itemtype="https://schema.org/BreadcrumbList">';
        foreach ($items as $i => [$label, $url]) {
            $out .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">' . ($url ? '<a itemprop="item" href="' . esc_url($url) . '"><span itemprop="name">' . esc_html($label) . '</span></a>' : '<span itemprop="name" aria-current="page">' . esc_html($label) . '</span>') . '<meta itemprop="position" content="' . ($i + 1) . '"></li>';
        }
        return $out . '</ol></nav>';
    }, ['separator' => ['type' => 'string', 'default' => '/']]);
    $dyn('iu/search', fn($a) => '<form class="search-form" role="search" action="' . esc_url(home_url('/')) . '" style="margin:var(--space-5) 0;max-width:720px"><label class="sr-only" for="iu-s">Keresés</label><input id="iu-s" name="s" type="search" value="' . esc_attr(get_search_query()) . '" placeholder="' . esc_attr($a['placeholder'] ?? 'Keresés...') . '"><button class="iu-button" type="submit">Keresés</button></form>', ['placeholder' => ['type' => 'string', 'default' => 'Keresés...']]);
    $dyn('iu/query', function ($a) {
        global $wp_query;
        $query = !empty($a['main_query']) ? $wp_query : new WP_Query(json_decode('{' . ($a['params'] ?? '') . '}', true) ?: []);
        $out = '<div class="iu-query iu-query-col-' . esc_attr($a['columns'] ?? '3') . '">';
        while ($query->have_posts()) {
            $query->the_post();
            $out .= do_shortcode($a['template'] ?? '');
        }
        $out .= '</div>';
        if (!empty($a['paging'])) {
            $out .= '<nav class="pagination">' . paginate_links(['total' => $query->max_num_pages]) . '</nav>';
        }
        if (empty($a['main_query'])) {
            wp_reset_postdata();
        }
        return $out;
    }, ['main_query' => ['type' => 'boolean', 'default' => true], 'params' => ['type' => 'string', 'default' => ''], 'template' => ['type' => 'string', 'default' => ''], 'columns' => ['type' => 'string', 'default' => '3'], 'paging' => ['type' => 'boolean', 'default' => false]]);
    $dyn('iu/terms', function ($a) {
        $terms = get_terms(['taxonomy' => $a['taxonomy'] ?? 'category', 'hide_empty' => true]);
        $current = get_queried_object_id();
        $out = '<div class="iu-terms" role="group">';
        if (!empty($a['all_text'])) {
            $out .= '<a class="' . (is_home() ? 'iu-terms-active' : '') . '" href="' . esc_url(get_permalink((int) get_option('page_for_posts'))) . '">' . esc_html($a['all_text']) . '</a>';
        }
        foreach (is_wp_error($terms) ? [] : $terms as $t) {
            $out .= '<a class="' . ($t->term_id === $current ? 'iu-terms-active' : '') . '" href="' . esc_url(get_term_link($t)) . '">' . esc_html($t->name) . '</a>';
        }
        return $out . '</div>';
    }, ['taxonomy' => ['type' => 'string', 'default' => 'category'], 'all_text' => ['type' => 'string', 'default' => 'Összes'], 'buttons' => ['type' => 'boolean', 'default' => false]]);
    $dyn('iu/post-navigation', function () {
        $prev = get_previous_post();
        $next = get_next_post();
        return '<nav class="iu-post-navigation">' . ($prev ? '<a href="' . esc_url(get_permalink($prev)) . '">‹ ' . esc_html(get_the_title($prev)) . '</a>' : '<span></span>') . ($next ? '<a href="' . esc_url(get_permalink($next)) . '">' . esc_html(get_the_title($next)) . ' ›</a>' : '') . '</nav>';
    }, ['params' => ['type' => 'string', 'default' => ''], 'previous' => ['type' => 'string', 'default' => ''], 'next' => ['type' => 'string', 'default' => '']]);
});

/* ---------- Shortcode-ok ---------- */

add_shortcode('iu_site_url', fn() => trailingslashit(home_url()));
add_shortcode('iu_year', fn() => wp_date('Y'));
add_shortcode('iu_post_title', fn() => esc_html(get_the_title()));
add_shortcode('iu_post_link', fn() => esc_url(get_permalink()));
add_shortcode('iu_post_excerpt', fn() => esc_html(get_the_excerpt()));

/* ---------- Űrlapok (forms.php) ---------- */

function iu_find_form(array $blocks, string $id): ?array
{
    foreach ($blocks as $b) {
        if (($b['blockName'] ?? '') === 'iu/form' && ($b['attrs']['formId'] ?? '') === $id) {
            return $b;
        }
        if (!empty($b['innerBlocks']) && ($f = iu_find_form($b['innerBlocks'], $id))) {
            return $f;
        }
    }
    return null;
}

add_action('template_redirect', function () {
    if (empty($_POST['iu_form_id'])) {
        return;
    }
    $id = sanitize_key(wp_unslash($_POST['iu_form_id']));
    $sources = [iu_template('header'), iu_template('content'), iu_template('footer')];
    if (is_singular()) {
        $sources[] = get_post(get_queried_object_id())->post_content;
    }
    $form = null;
    foreach ($sources as $markup) {
        if ($form = iu_find_form(parse_blocks($markup), $id)) {
            break;
        }
    }
    if (!$form) {
        wp_send_json(['errors' => ['form' => 'Űrlap nem található.'], 'error' => 'Űrlap nem található.']);
    }
    $errors = [];
    $walk = function ($blocks) use (&$walk, &$errors) {
        foreach ($blocks as $b) {
            $name = $b['attrs']['name'] ?? '';
            foreach (array_filter(explode("\n", $b['attrs']['validate'] ?? '')) as $line) {
                [$rule, $msg] = array_pad(explode('|', $line, 2), 2, 'Hibás érték.');
                $v = trim((string) wp_unslash($_POST[$name] ?? ''));
                if (($rule === 'required' && $v === '') || ($rule === 'email' && $v !== '' && !is_email($v))) {
                    $errors[$name] = $msg;
                    break;
                }
            }
            $walk($b['innerBlocks'] ?? []);
        }
    };
    $walk($form['innerBlocks']);
    if ($errors) {
        wp_send_json(['errors' => $errors]);
    }
    wp_send_json(apply_filters("iu_form_submit_{$id}", ['success' => 1], $form));
}, 1);
