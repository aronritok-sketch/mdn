<?php
/**
 * Hangminta és egyedi darabok.
 *
 * - Hangminta: minden hangtálhoz feltölthető egy rövid felvétel (Mandala adatok → Hangminta).
 *   A termékoldalon lejátszó (Hz + hang), a kártyákon és a szűrőben „Hangmintával” jelzés.
 * - Egyedi darab: a kézzel készült tál egyetlen példány (max. 1 db, „Egyedi darab” jelvény) –
 *   a fotó és a felvétel pontosan erről a darabról szól.
 * - Testvérdarabok: az azonos „csoport” kulcsú egyedi darabok egymás között választhatók
 *   a termékoldalon (pl. több 7 fémes G hangtál, eltérő súllyal és hanggal).
 */

defined('ABSPATH') || exit;

add_filter('mandala_product_meta_fields', function ($fields) {
    return $fields + [
        '_mandala_audio' => ['Hangminta', 'audio', 'Rövid (10–20 mp) MP3/OGG/WAV felvétel erről a darabról. A termékoldalon és a kártyán lejátszható.'],
        '_mandala_unique' => ['Egyedi darab', 'checkbox', 'Egyetlen példány: legfeljebb 1 db rendelhető, „Egyedi darab” jelzéssel.'],
        '_mandala_group' => ['Testvérdarabok csoportja', 'text', 'Azonos kulcs (pl. 7fem-g) → a termékoldalon a csoport többi darabja is választható.'],
    ];
});

function mandala_audio_url(WC_Product $product): string
{
    return (string) $product->get_meta('_mandala_audio', true, 'edit');
}
function mandala_is_unique(WC_Product $product): bool
{
    return $product->get_meta('_mandala_unique', true, 'edit') === 'yes';
}

/** Egyedi darab: a WooCommerce „egyenként eladható” beállítása is bekapcsol (max. 1 db a kosárban). */
add_action('woocommerce_admin_process_product_object', function (WC_Product $product) {
    if (!empty($_POST['_mandala_unique'])) { // phpcs:ignore -- a WooCommerce ellenőrzi
        $product->set_sold_individually(true);
    }
}, 20);

add_filter('mandala_product_index_row', function ($row, WC_Product $product) {
    $row['audio'] = mandala_audio_url($product);
    $row['unique'] = mandala_is_unique($product);
    return $row;
}, 10, 2);

add_filter('mandala_card_badges', function ($badges, WC_Product $product) {
    return mandala_is_unique($product) && $product->is_in_stock() ? $badges . '<span class="badge badge-unique">' . esc_html__('Egyedi darab', 'mandala') . '</span>' : $badges;
}, 10, 2);

/** Kártya: lejátszás gomb a kép sarkában. */
add_filter('mandala_card_media_extra', function ($html, WC_Product $product) {
    $url = mandala_audio_url($product);
    if (!$url) {
        return $html;
    }
    return $html . '<button type="button" class="sound-btn" data-sound="' . esc_url($url) . '" aria-pressed="false" aria-label="' . esc_attr(sprintf(__('Hangminta lejátszása: %s', 'mandala'), $product->get_name())) . '">'
        . mandala_icon('play', 'ico ico-s ico-play') . mandala_icon('pause', 'ico ico-s ico-pause') . '</button>';
}, 10, 2);

/** Termékoldal: lejátszó az ár alatt. */
add_action('mandala_summary_after_price', function (WC_Product $product) {
    $url = mandala_audio_url($product);
    if (!$url) {
        return;
    }
    $hz = $product->get_meta('_mandala_hz', true, 'edit');
    $note = $product->get_attribute('pa_hang');
    $meta = implode(' · ', array_filter([$hz ? $hz . ' Hz' : '', $note ? sprintf(__('%s hang', 'mandala'), $note) : '']));
    ?>
<div class="sound-player" data-sound-player>
  <button type="button" class="sound-play" data-sound="<?php echo esc_url($url); ?>" aria-pressed="false" aria-label="<?php esc_attr_e('Hangminta lejátszása', 'mandala'); ?>"><?php echo mandala_icon('play', 'ico ico-play') . mandala_icon('pause', 'ico ico-pause'); // phpcs:ignore ?></button>
  <div class="sound-body">
    <p class="sound-title"><strong><?php echo esc_html(mandala_is_unique($product) ? __('Hallgasd meg ezt a darabot', 'mandala') : __('Hallgasd meg', 'mandala')); ?></strong><?php if ($meta) : ?><span><?php echo esc_html($meta); ?></span><?php endif; ?></p>
    <div class="sound-track"><input type="range" min="0" max="100" step="0.1" value="0" data-sound-seek aria-label="<?php esc_attr_e('Lejátszási pozíció', 'mandala'); ?>" disabled><span class="sound-time" data-sound-time>0:00</span></div>
  </div>
</div>
    <?php
});

/** Termékoldal: egyedi darab magyarázat + testvérdarabok választó. */
add_action('mandala_summary_after_stock', function (WC_Product $product) {
    if (mandala_is_unique($product)) {
        echo '<p class="unique-note">' . mandala_icon('sparkle', 'ico ico-s') . '<span>' . esc_html__('Egyedi darab: pontosan ezt a tálat kapod, amelyet a képen látsz és a felvételen hallasz.', 'mandala') . '</span></p>';
    }
    $group = (string) $product->get_meta('_mandala_group', true, 'edit');
    if (!$group) {
        return;
    }
    $ids = wc_get_products(['status' => 'publish', 'limit' => 12, 'return' => 'ids', 'meta_key' => '_mandala_group', 'meta_value' => $group, 'orderby' => 'menu_order', 'order' => 'ASC']);
    if (count($ids) < 2) {
        return;
    }
    echo '<div class="siblings"><p class="siblings-title">' . esc_html__('Válassz a testvérdarabok közül', 'mandala') . '</p><ul class="siblings-list">';
    foreach ($ids as $id) {
        $p = wc_get_product($id);
        $current = $id === $product->get_id();
        $hz = $p->get_meta('_mandala_hz', true, 'edit');
        $suly = $p->get_meta('_mandala_suly', true, 'edit');
        $facts = implode(' · ', array_filter([$hz ? $hz . ' Hz' : '', $suly ? $suly . ' g' : '', $p->get_attribute('pa_hang')]));
        $audio = mandala_audio_url($p);
        echo '<li class="' . ($current ? 'is-current' : '') . ($p->is_in_stock() ? '' : ' is-out') . '">'
            . '<a href="' . esc_url(get_permalink($id)) . '"' . ($current ? ' aria-current="true"' : '') . '><span class="thumb">' . mandala_product_image($p, 'thumbnail') . '</span>'
            . '<span><strong>' . esc_html($facts ?: $p->get_name()) . '</strong><small>' . ($p->is_in_stock() ? wp_kses_post(wc_price(wc_get_price_to_display($p))) : esc_html__('Elkelt', 'mandala')) . '</small></span></a>'
            . ($audio ? '<button type="button" class="sound-btn is-inline" data-sound="' . esc_url($audio) . '" aria-pressed="false" aria-label="' . esc_attr(sprintf(__('Hangminta: %s', 'mandala'), $facts)) . '">' . mandala_icon('play', 'ico ico-s ico-play') . mandala_icon('pause', 'ico ico-s ico-pause') . '</button>' : '')
            . '</li>';
    }
    echo '</ul></div>';
});

/* ---------- Bemutató: szintetizált tálhangok a mintatermékekhez ---------- */

/**
 * Éneklő tál hangja: alaphang + a tálakra jellemző inharmonikus felhangok (≈2,7×, 5,2×, 8,4×),
 * lebegéssel és exponenciális lecsengéssel. 16 bites mono WAV.
 */
function mandala_synth_bowl(float $hz, float $seconds = 6.0, int $rate = 22050): string
{
    $partials = [[1.0, 1.0, 0.32, 0.9], [2.71, 0.45, 0.55, 1.6], [5.18, 0.22, 0.9, 2.3], [8.41, 0.1, 1.4, 3.1]];
    $n = (int) ($seconds * $rate);
    $data = '';
    for ($i = 0; $i < $n; $i++) {
        $t = $i / $rate;
        $v = 0.0;
        foreach ($partials as [$ratio, $amp, $decay, $beat]) {
            $f = $hz * $ratio;
            // Két, kissé elhangolt összetevő → a tálakra jellemző lebegés.
            $v += $amp * exp(-$decay * $t) * (sin(2 * M_PI * $f * $t) + 0.6 * sin(2 * M_PI * ($f + $beat) * $t)) / 1.6;
        }
        $attack = min(1.0, $t / 0.012);
        $fade = $t > $seconds - 0.4 ? max(0.0, ($seconds - $t) / 0.4) : 1.0;
        $data .= pack('v', (int) round(max(-1, min(1, $v * 0.42 * $attack * $fade)) * 32767) & 0xFFFF);
    }
    return 'RIFF' . pack('V', 36 + strlen($data)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16) . 'data' . pack('V', strlen($data)) . $data;
}

add_action('mandala_demo_features', function () {
    $bowls = wc_get_products(['status' => 'publish', 'limit' => -1, 'category' => ['hangtalak'], 'meta_key' => '_mandala_demo', 'meta_value' => '1']);
    foreach ($bowls as $product) {
        $hz = (float) $product->get_meta('_mandala_hz');
        if ($hz <= 0 || mandala_audio_url($product)) {
            continue;
        }
        $upload = wp_upload_bits('mandala-hangminta-' . sanitize_file_name(strtolower($product->get_sku())) . '.wav', null, mandala_synth_bowl($hz));
        if (empty($upload['error'])) {
            $product->update_meta_data('_mandala_audio', $upload['url']);
        }
        // A kovácsolt tálak egyedi darabok; a két 7 fémes, mintás tál testvérdarab.
        if ($product->get_attribute('pa_keszites') === 'Kézzel kovácsolt' || str_contains($product->get_name(), 'kovácsolt')) {
            $product->update_meta_data('_mandala_unique', 'yes');
            $product->set_sold_individually(true);
        }
        if (str_contains($product->get_name(), 'mintás hangtál')) {
            $product->update_meta_data('_mandala_group', '7fem-mintas');
        }
        $product->save();
    }
});
