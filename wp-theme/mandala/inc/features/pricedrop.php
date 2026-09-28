<?php
/**
 * Árcsökkenés-értesítő: ha egy feliratkozó által megnézett termék legalább 5%-kal olcsóbb lett
 * (akció vagy árváltozás), egy rövid levél a régi és az új árral. A „Kedvenc akciós lett” levél
 * a kedvencekre szól; ez a megnézett (de el nem mentett) termékekre.
 *
 * Adat: a „Megnézett termékek” azonosítás (browse.php) – csak feliratkozók, marketing süti hozzájárulással.
 * Termékenként és áranként egyszer; a vásárló legfeljebb 3 naponta kap ilyet; 30 napnál régebbi
 * megnézés nem számít; elfogyott termék kimarad; közben megvette → nem megy.
 * Be/ki és szöveg: Mandala levelek → „Olcsóbb lett, amit néztél”.
 */

defined('ABSPATH') || exit;

const MANDALA_PRICEDROP_MIN = 0.05; // legalább 5% csökkenés

mandala_recurring('mandala_pricedrop_sweep', DAY_IN_SECONDS, function () {
    $t = new DateTime('tomorrow 10:00', wp_timezone());
    return $t->getTimestamp();
});
add_action('mandala_pricedrop_sweep', 'mandala_pricedrop_sweep');

/** Visszaad: az elküldött levelek száma. */
function mandala_pricedrop_sweep(): int
{
    if (!mandala_automation_on('pricedrop') || !function_exists('mandala_browse_table')) {
        return 0;
    }
    global $wpdb;
    $table = mandala_browse_table();
    $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE last_view > %s", gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS))); // phpcs:ignore
    $sent = 0;
    foreach ($rows as $row) {
        if (mandala_is_unsubscribed($row->email)) {
            continue;
        }
        $last_mail = (int) get_transient('mandala_pd_' . md5($row->email));
        if ($last_mail) {
            continue; // 3 napon belül már kapott
        }
        $views = (array) json_decode((string) $row->views, true);
        $drops = [];
        $changed = false;
        foreach ($views as $i => $v) {
            [$pid, $at] = $v;
            $was = (float) ($v[2] ?? 0);
            $notified = (float) ($v[3] ?? 0);
            if ($was <= 0 || (int) $at < time() - 30 * DAY_IN_SECONDS) {
                continue;
            }
            $p = wc_get_product((int) $pid);
            if (!$p || $p->get_status() !== 'publish' || !$p->is_in_stock() || !$p->is_purchasable()) {
                continue;
            }
            $now = (float) wc_get_price_to_display($p);
            if ($now <= $was * (1 - MANDALA_PRICEDROP_MIN) && ($notified === 0.0 || $now < $notified)) {
                $bought = wc_get_orders(['billing_email' => $row->email, 'date_created' => '>' . (int) $at, 'limit' => 1, 'return' => 'ids', 'type' => 'shop_order']);
                if (!$bought) {
                    $drops[] = [$p, $was, $now];
                }
                $views[$i][3] = $now;
                $changed = true;
            }
        }
        if ($changed) {
            $wpdb->update($table, ['views' => wp_json_encode($views)], ['token' => $row->token]);
        }
        if (!$drops) {
            continue;
        }
        $drops = array_slice($drops, 0, 3);
        $rows_html = implode('', array_map(fn($d) => mandala_mail_product_row($d[0], '<s>' . esc_html(mandala_fmt($d[1])) . '</s> <strong style="color:#8A4512">' . esc_html(mandala_fmt($d[2])) . '</strong>'), $drops));
        $last = wc_get_orders(['billing_email' => $row->email, 'limit' => 1, 'type' => 'shop_order']);
        $name = $last ? $last[0]->get_billing_first_name() : '';
        if (mandala_mail('price_drop', $row->email, ['keresztnev' => $name ?: __('Kedves Vásárlónk', 'mandala')], ['termekek' => $rows_html, 'gomb' => mandala_mail_button($drops[0][0]->get_permalink(), __('Megnézem', 'mandala'))])) {
            $sent++;
            set_transient('mandala_pd_' . md5($row->email), time(), 3 * DAY_IN_SECONDS);
        }
    }
    return $sent;
}

add_filter('mandala_mail_types', function ($types) {
    $types['price_drop'] = [
        'label' => 'Olcsóbb lett, amit néztél', 'group' => 'Értesítések', 'setting' => 'pricedrop', 'marketing' => true,
        'when' => 'Naponta ellenőrizve: ha egy feliratkozó által az elmúlt 30 napban megnézett termék legalább 5%-kal olcsóbb lett (termékenként egyszer, legfeljebb 3 naponta egy levél).',
        'vars' => ['keresztnev' => 'A keresztnév (ha rendelt már)'], 'blocks' => ['termekek' => 'A termékek régi és új árral', 'gomb' => '„Megnézem” gomb'],
        'subject' => __('Olcsóbb lett, amit néztél', 'mandala'), 'heading' => __('Jó hír: lejjebb ment az ár', 'mandala'),
        'body' => '<p>' . __('Kedves {keresztnev}!', 'mandala') . '</p><p>' . __('Nemrég megnézted ezeket – most kedvezőbb áron kaphatók:', 'mandala') . '</p>{termekek}{gomb}',
        'sample' => fn() => [['keresztnev' => 'Anna'], ['termekek' => mandala_mail_sample_rows(1, false, '<s>24 900 Ft</s> <strong style="color:#8A4512">19 900 Ft</strong>'), 'gomb' => mandala_mail_button(home_url('/'), __('Megnézem', 'mandala'))]],
    ];
    return $types;
});
