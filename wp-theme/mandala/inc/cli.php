<?php
/**
 * WP-CLI parancsok. Semleges mappából futtasd (az iu_theme relatív require_once hibája
 * miatt ne a téma mappájából), pl.:  cd /tmp && wp --path=/var/www/html mandala setup
 */

defined('ABSPATH') || exit;

WP_CLI::add_command('mandala', new class {
    /**
     * A függő telepítő lépések futtatása.
     *
     * ## OPTIONS
     * [--step=<lépés>]  : csak ez(ek) a lépés(ek), vesszővel
     * [--force]          : minden lépés újrafuttatása
     * [--demo]           : bemutató termékek, cikkek és kuponok importálása is
     */
    public function setup($args, $assoc)
    {
        // CLI alatt nincs bejelentkezett felhasználó: a kses és a blokk-CSS szűrők elrontanák a blokk-JSON-t.
        $admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
        if ($admins) {
            wp_set_current_user((int) $admins[0]);
        }
        kses_remove_filters();
        $only = !empty($assoc['step']) ? array_map('trim', explode(',', $assoc['step'])) : [];
        $setup = new Mandala_Setup();
        $log = $setup->run($only, !empty($assoc['force']));
        if (!empty($assoc['demo'])) {
            $log = array_merge($log, $setup->run(array_keys(Mandala_Setup::DEMO_STEPS)));
        }
        foreach ($log as $line) {
            WP_CLI::log($line);
        }
        WP_CLI::success('Kész.');
    }

    /** Bemutató tartalom importálása. */
    public function demo($args, $assoc)
    {
        $this->setup([], ['step' => implode(',', array_keys(Mandala_Setup::DEMO_STEPS))]);
    }

    /** Bemutató tartalom törlése (csak amit a telepítő hozott létre). */
    public function demo_delete()
    {
        WP_CLI::success(Mandala_Setup::delete_demo() . ' elem törölve.');
    }

    /** Lépések állapota. */
    public function status()
    {
        $done = get_option('mandala_setup_steps', []);
        $rows = [];
        foreach (Mandala_Setup::STEPS + Mandala_Setup::DEMO_STEPS as $step => $v) {
            $rows[] = ['lépés' => $step, 'verzió' => $v, 'kész' => ($done[$step] ?? 0) >= $v ? 'igen' : 'nem'];
        }
        WP_CLI\Utils\format_items('table', $rows, ['lépés', 'verzió', 'kész']);
    }

    /** A termékindex (szűrő) gyorsítótárának ürítése és újraépítése. */
    public function reindex()
    {
        mandala_flush_index();
        WP_CLI::success(count(mandala_product_index()) . ' termék indexelve.');
    }
});
