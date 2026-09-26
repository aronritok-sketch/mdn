<?php
/*
 * Mandala téma – sorok a wp-config.php-ba (a "That's all, stop editing!" / "Ennyi, ne szerkeszd tovább!"
 * sor ELÉ). Csak azt vedd át, amire szükség van.
 */

// Claude (Anthropic) API-kulcs: kategória-migráció, javaslat az új JUTA-termékekhez és az AI tanácsadó chat.
// Termékek → Új termékek → Claude migráció; WooCommerce → Mandala tanácsadó. Kulcs nélkül ezek nem aktívak.
define('MANDALA_ANTHROPIC_API_KEY', 'sk-ant-...');

// Memória a nagyobb háttérfeladatokhoz (migráció, árlista, fotócsomag).
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');

// Ütemezett feladatok valódi cronnal (ajánlott): a WordPress látogatásfüggő időzítője helyett
// a tárhely cron futtatja 5 percenként:
//   */5 * * * *  wget -q -O - https://mandala.hu/wp-cron.php?doing_wp_cron >/dev/null 2>&1
// Csak akkor kapcsold be, ha a cron feladat már be van állítva!
// define('DISABLE_WP_CRON', true);

// Élesen a hibák ne jelenjenek meg a látogatóknak (naplóba mehetnek).
define('WP_DEBUG', false);
define('WP_DEBUG_DISPLAY', false);
