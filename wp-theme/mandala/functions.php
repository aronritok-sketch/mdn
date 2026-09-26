<?php
/**
 * Mandala – iu_theme child téma.
 *
 * Minden projekt-specifikus kód itt van; az iu_theme keretrendszerhez és az
 * iu_* mu-pluginekhez nem nyúlunk (iu-theme skill, 3. szabály).
 * Betöltés mindig __DIR__-rel (a keretrendszer relatív require_once hibája miatt).
 */

defined('ABSPATH') || exit;

define('MANDALA_VERSION', '1.0.0');
define('MANDALA_DIR', __DIR__);
define('MANDALA_URL', get_stylesheet_directory_uri());

require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/theme.php';
require_once __DIR__ . '/inc/blocks.php';
require_once __DIR__ . '/inc/catalog.php';
require_once __DIR__ . '/inc/shop.php';
require_once __DIR__ . '/inc/forms.php';
require_once __DIR__ . '/inc/wishlist.php';
require_once __DIR__ . '/inc/rest.php';
require_once __DIR__ . '/inc/setup.php';

// Funkciómodulok: hangminta, műhelyek, előrendelés, események, értékelések, ajándék, hűségprogram…
foreach (glob(__DIR__ . '/inc/features/*.php') ?: [] as $mandala_feature) {
    require_once $mandala_feature;
}
unset($mandala_feature);

if (defined('WP_CLI') && WP_CLI) {
    require_once __DIR__ . '/inc/cli.php';
}
