<?php
/**
 * mandala/header – logó, fő menü megamenüvel, fejléc műveletek (kereső, nyelv,
 * fiók, kedvencek, kosár). Pénztárban egyszerűsített változat.
 */

defined('ABSPATH') || exit;

/** Fő menü elemei (legfelső szint) a „mandala-primary” helyről. */
function mandala_primary_items(): array
{
    $locations = get_nav_menu_locations();
    if (empty($locations['mandala-primary'])) {
        return [];
    }
    $items = wp_get_nav_menu_items($locations['mandala-primary']) ?: [];
    return array_values(array_filter($items, fn($i) => !(int) $i->menu_item_parent));
}

function mandala_menu_item_current($item): bool
{
    if ((int) $item->object_id === (int) get_queried_object_id() && $item->type !== 'custom') {
        return true;
    }
    $url = untrailingslashit(strtok((string) $item->url, '?'));
    $here = untrailingslashit(strtok(home_url(add_query_arg([])), '?'));
    return $url && $url === $here && $url !== untrailingslashit(home_url());
}

function mandala_mega_panel(): string
{
    $tree = mandala_category_tree();
    $intents = mandala_data('catalog')['intents'] ?? [];
    $shop = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/');
    $cols = count($tree) + 1;
    $width = 'calc((100% - ' . ($cols - 1) . ' * var(--gutter)) / ' . $cols . ')';
    ob_start(); ?>
<div class="mega-panel" id="mega-panel" hidden><div class="iu-row">
  <?php foreach ($tree as $cat) : ?>
  <div class="iu-column iu-column-1-4" style="width:<?php echo esc_attr($width); ?>">
    <a class="mega-title" href="<?php echo esc_url($cat['url']); ?>"><?php echo esc_html($cat['label']); ?></a>
    <ul class="mega-list"><?php foreach ($cat['subs'] as [$slug, $label, $url]) : ?><li><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a></li><?php endforeach; ?></ul>
  </div>
  <?php endforeach; ?>
  <div class="iu-column iu-column-1-4" style="width:<?php echo esc_attr($width); ?>">
    <span class="mega-title"><?php esc_html_e('Szándék szerint', 'mandala'); ?></span>
    <ul class="mega-list"><?php foreach ($intents as $intent) : ?><li><a href="<?php echo esc_url(add_query_arg('szandek', $intent['id'], $shop)); ?>"><?php echo esc_html($intent['label']); ?></a></li><?php endforeach; ?></ul>
    <?php $bowls = get_term_by('slug', 'hangtalak', 'product_cat'); if ($bowls) : ?>
    <a class="mega-feature" href="<?php echo esc_url(get_term_link($bowls)); ?>" style="margin-top:var(--space-5);height:auto"><?php echo mandala_picture('hangtalak-studio', '', ['sizes' => '260px']); // phpcs:ignore ?><span><strong><?php esc_html_e('Hangtál-kalauz', 'mandala'); ?></strong><?php esc_html_e('Hz, hang és csakra szerint', 'mandala'); ?></span></a>
    <?php endif; ?>
  </div>
</div></div>
    <?php
    return (string) ob_get_clean();
}

function mandala_logo(): string
{
    $svg = @file_get_contents(MANDALA_DIR . '/assets/art/logo-mark.svg') ?: '';
    $svg = preg_replace('/<svg /', '<svg class="logo-mark" aria-hidden="true" ', str_replace(' xmlns="http://www.w3.org/2000/svg"', '', $svg), 1);
    return '<a class="site-logo" href="' . esc_url(home_url('/')) . '" aria-label="' . esc_attr(get_bloginfo('name') . ' – ' . __('kezdőlap', 'mandala')) . '">' . $svg . '<span>' . esc_html(get_bloginfo('name')) . '</span></a>';
}

mandala_add_block('mandala/header', [
    'title' => 'Fejléc (logó, menü, műveletek)',
    'attributes' => [
        'languages' => mandala_attr_def('1', 'boolean'),
    ],
    'fields' => [['panel' => 'Beállítások', 'fields' => [
        'languages' => ['type' => 'toggle', 'label' => 'Nyelvváltó (HU/EN)'],
    ]]],
    'template' => function ($attributes) {
        $checkout = function_exists('is_checkout') && is_checkout() && !is_order_received_page();
        $icon = 'mandala_icon';
        if ($checkout) {
            ob_start(); ?>
<div class="iu-group iu-group-horizontal-space-between iu-group-vertical-center mandala-header is-checkout">
  <?php echo mandala_logo(); // phpcs:ignore ?>
  <span class="checkout-header-note"><?php echo $icon('lock', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Biztonságos pénztár', 'mandala'); ?></span>
  <a class="iu-button iu-button-link" href="<?php echo esc_url(wc_get_cart_url()); ?>"><?php echo $icon('arrow-left', 'ico ico-s'); // phpcs:ignore ?> <span class="hide-mobile"><?php esc_html_e('Vissza a kosárhoz', 'mandala'); ?></span><span class="d-none m-inline-flex"><?php esc_html_e('Kosár', 'mandala'); ?></span></a>
</div>
            <?php
            return (string) ob_get_clean();
        }
        $items = mandala_primary_items();
        $tree = mandala_category_tree();
        $account = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url();
        $wish_url = get_permalink((int) get_option('mandala_page_kedvencek'));
        $contact = mandala_config('contact', []);
        $cart_count = function_exists('WC') && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
        $wish_count = count(mandala_wishlist_ids());
        ob_start(); ?>
<div class="iu-group iu-group-horizontal-space-between iu-group-vertical-center mandala-header">
  <div class="iu-menu-block-wrapper">
    <button type="button" class="mobile-toggle icon-button" aria-expanded="false" aria-controls="main-menu" aria-label="<?php esc_attr_e('Menü megnyitása', 'mandala'); ?>"><?php echo $icon('menu'); // phpcs:ignore ?></button>
    <?php echo mandala_logo(); // phpcs:ignore ?>
  </div>
  <nav class="iu-menu-container" id="main-menu" aria-label="<?php esc_attr_e('Fő menü', 'mandala'); ?>">
    <button type="button" class="mobile-toggle iu-menu-close icon-button" aria-label="<?php esc_attr_e('Menü bezárása', 'mandala'); ?>"><?php echo $icon('close'); // phpcs:ignore ?></button>
    <ul class="iu-menu-block">
      <?php foreach ($items as $item) :
          $classes = array_filter((array) $item->classes);
          $current = mandala_menu_item_current($item);
          if (in_array('mega', $classes, true)) : ?>
        <li class="menu-item menu-item-has-children mega<?php echo mandala_is_shop_context() ? ' current-menu-item' : ''; ?>">
          <button type="button" class="mega-trigger" aria-expanded="false" aria-controls="mega-panel"><?php echo esc_html($item->title); ?> <?php echo $icon('chevron', 'ico ico-s'); // phpcs:ignore ?></button>
          <ul class="mobile-sub" id="mobile-sub" hidden>
            <?php foreach ($tree as $cat) : ?><li><a href="<?php echo esc_url($cat['url']); ?>"><?php echo esc_html($cat['label']); ?></a></li><?php endforeach; ?>
            <li><a href="<?php echo esc_url($item->url); ?>"><?php esc_html_e('Teljes kínálat', 'mandala'); ?></a></li>
          </ul>
        </li>
          <?php else : ?>
        <li class="menu-item<?php echo $current ? ' current-menu-item' : ''; ?>"><a href="<?php echo esc_url($item->url); ?>"<?php echo $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html($item->title); ?></a></li>
          <?php endif;
      endforeach; ?>
    </ul>
    <div class="mobile-menu-foot d-none m-block">
      <a class="iu-button iu-button-outline" href="<?php echo esc_url($account); ?>"><?php echo $icon('user', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Fiókom', 'mandala'); ?></a>
      <?php if ($wish_url) : ?><a class="iu-button iu-button-outline" href="<?php echo esc_url($wish_url); ?>"><?php echo $icon('heart', 'ico ico-s'); // phpcs:ignore ?> <?php esc_html_e('Kedvencek', 'mandala'); ?></a><?php endif; ?>
      <?php if (!empty($contact['phone'])) : ?><p><?php echo $icon('phone', 'ico ico-s'); // phpcs:ignore ?> <?php echo esc_html($contact['phone'] . ' · ' . ($contact['hours'] ?? '')); ?></p><?php endif; ?>
    </div>
  </nav>
  <div class="header-actions">
    <button type="button" class="icon-button" data-open-search aria-label="<?php esc_attr_e('Keresés (/)', 'mandala'); ?>"><?php echo $icon('search'); // phpcs:ignore ?></button>
    <?php if (mandala_bool($attributes['languages'] ?? true)) : ?>
    <div class="lang-switch hide-mobile" role="group" aria-label="<?php esc_attr_e('Nyelv', 'mandala'); ?>"><span aria-current="true">HU</span><a href="#" data-lang="en" lang="en">EN</a></div>
    <?php endif; ?>
    <a class="icon-button hide-mobile" href="<?php echo esc_url($account); ?>" aria-label="<?php esc_attr_e('Fiókom', 'mandala'); ?>"<?php echo function_exists('is_account_page') && is_account_page() ? ' aria-current="page"' : ''; ?>><?php echo $icon('user'); // phpcs:ignore ?></a>
    <?php if ($wish_url) : ?>
    <a class="icon-button hide-mobile" href="<?php echo esc_url($wish_url); ?>" aria-label="<?php esc_attr_e('Kedvencek', 'mandala'); ?>"><?php echo $icon('heart'); // phpcs:ignore ?><span class="count" data-wish-count<?php echo $wish_count ? '' : ' hidden'; ?>><?php echo (int) $wish_count; ?></span></a>
    <?php endif; ?>
    <?php if (function_exists('WC')) : ?>
    <a class="icon-button cart-toggle" href="<?php echo esc_url(wc_get_cart_url()); ?>" data-open-cart aria-label="<?php esc_attr_e('Kosár', 'mandala'); ?>"><?php echo $icon('bag'); // phpcs:ignore ?><?php echo mandala_cart_count_html($cart_count); // phpcs:ignore ?></a>
    <?php endif; ?>
  </div>
</div>
<?php echo mandala_mega_panel(); // phpcs:ignore ?>
        <?php
        return (string) ob_get_clean();
    },
]);

/** Kínálat-kontextus (kategória, bolt, termék) a menü kiemeléséhez. */
function mandala_is_shop_context(): bool
{
    return function_exists('is_woocommerce') && (is_shop() || is_product_taxonomy() || is_product());
}
