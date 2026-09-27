<?php
/** iu_theme TESZT HELYETTESÍTŐ – három zónás renderelés. */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="description" content="">
<?php wp_head(); ?>
</head>
<?php // Mint a valódi iu_theme: csak saját body osztályok (admin-bar, front-page), nincs wp_body_open. ?>
<body<?php $iu_body = array_filter([is_admin_bar_showing() ? 'admin-bar' : '', is_front_page() ? 'front-page' : '']); echo $iu_body ? ' class="' . esc_attr(implode(' ', $iu_body)) . '"' : ''; ?>>
<header><?php echo iu_render(iu_template('header')); // phpcs:ignore ?></header>
<main><?php
$content = iu_template('content');
if ($content) {
    echo iu_render($content); // phpcs:ignore
} else {
    while (have_posts()) {
        the_post();
        the_content();
    }
}
?></main>
<footer><?php echo iu_render(iu_template('footer')); // phpcs:ignore ?></footer>
<?php wp_footer(); ?>
</body>
</html>
