<?php
/**
 * Termékszövegek egészségügyi állításainak cseréje (inc/features/health-copy.php) – teszt WordPressben WooCommerce-szel.
 * Futtatás: wp --path=<wp> eval-file tests/wp-health-copy.php
 */

if (!function_exists('mandala_hr_apply')) {
    fwrite(STDERR, "A health-copy.php nincs betöltve.\n");
    exit(1);
}
$fail = 0;
$ok = function (bool $c, string $m) use (&$fail) {
    echo ($c ? '  ok  ' : '  HIBA ') . $m . "\n";
    if (!$c) {
        $fail++;
    }
};

echo "Minta és csere\n";
$pairs = [
    ['Ez a 7 fémes hangtál rezgéseivel gyengéden tisztítja és harmonizálja a szívcsakra energiáját, támogatva a szeretet áramlását és a mély érzelmi gyógyulást.', 'Ez a 7 fémes hangtál rezgéseivel a szívcsakrára hangolt meditációt kíséri.'],
    ['– Érzelmi gyógyulást keresőknek', '– Érzelmi egyensúlyt keresőknek'],
    ['Nyugtató, „burkoló” illata csökkenti a feszültséget, elősegíti a belső csendet.', 'Nyugtató, „burkoló” illata meghitt, csendes hangulatot teremt.'],
    ['Meditációhoz, aromaterápiához vagy egyszerű hangulatteremtéshez is jó kiegészítő.', 'Meditációhoz, illatrituáléhoz vagy egyszerű hangulatteremtéshez is jó kiegészítő.'],
    ['hozzájárulhat a megfelelő emésztőtűz (', 'kapcsolódik a tűz-elem ('],
];
$n = 0;
$html = "<p>Ez a 7 fémes hangtál rezgéseivel gyengéden tisztítja és harmonizálja a szívcsakra energiáját,\ntámogatva a szeretet áramlását és a mély érzelmi gyógyulást.</p>\n<ul><li>&#8211; Érzelmi gyógyulást keresőknek</li></ul>"
    . "<p><strong>Relaxáló:</strong><br>Nyugtató, &#8222;burkoló&#8221; illata csökkenti a feszültséget, elősegíti a belső&nbsp;csendet.</p>"
    . "<p><strong>Meditációhoz</strong>, aromaterápiához vagy egyszerű hangulatteremtéshez is jó kiegészítő.</p>"
    . "<li>hozzájárulhat a megfelelő emésztőtűz (<em>Agni</em>) fenntartásához,</li>";
$out = mandala_hr_apply($html, $pairs, $n);
$ok($n === 5, "minden mondat megtalálva (sortörés, &#8211;, &#8222;…&#8221;, &nbsp;, címke a mondatban): $n");
$ok(strpos($out, 'gyógyul') === false && strpos($out, 'aromaterápiához') === false, 'a régi szöveg eltűnt');
$ok(strpos($out, '<strong>Relaxáló:</strong>') !== false && strpos($out, '<em>Agni</em>') !== false, 'a környező HTML érintetlen');
$ok(substr_count($out, '<strong>') === substr_count($out, '</strong>'), 'a címkék párosak maradtak (a mondaton belüli </strong> a mondat után)');
$ok(strpos($out, 'kapcsolódik a tűz-elem (<em>Agni</em>) fenntartásához') !== false, 'részleges sor: a folytatás marad');
$n2 = 0;
$ok(mandala_hr_apply($out, $pairs, $n2) === $out && $n2 === 0, 'másodszor futtatva nem változtat');
$n3 = 0;
$nfd = "<p>Medita\u{301}cio\u{301}hoz, aromatera\u{301}pia\u{301}hoz vagy egyszeru\u{30B} hangulatteremte\u{301}shez is jo\u{301} kiege\u{301}szi\u{301}to\u{30B}.</p>";
$ok(strpos(mandala_hr_apply($nfd, $pairs, $n3), 'illatrituáléhoz') !== false && $n3 === 1, 'felbontott ékezetes (NFD) szöveg is egyezik');

echo "Futtatás termékre, mentés, visszaállítás\n";
$p = new WC_Product_Simple();
$p->set_name('Health-copy teszt hangtál');
$p->set_description($html);
$p->set_short_description('<p>&#8211; Érzelmi gyógyulást keresőknek</p>');
$p->save();
$id = $p->get_id();
$res = mandala_hr_run([$id], array_map(fn($x) => [$x[0], $x[1], [$id]], $pairs));
$post = get_post($id);
$ok(strpos($res, '1 termék') === 0, "összefoglaló: $res");
$ok(strpos($post->post_content, 'érzelmi gyógyulás') === false && strpos($post->post_excerpt, 'gyógyulást') === false, 'a leírás és a rövid leírás is átírva');
$b = get_post_meta($id, MANDALA_HR_BACKUP, true);
$ok(is_array($b) && strpos($b['content'], 'érzelmi gyógyulást') !== false, 'az eredeti mentve');
mandala_hr_restore();
$ok(strpos(get_post($id)->post_content, 'érzelmi gyógyulást') !== false, 'visszaállítás a mentésből');
wp_delete_post($id, true);

echo "A valódi cserelista\n";
$d = mandala_hr_data();
$ok(count($d['pairs']) >= 100 && count($d['test']) === 5, 'cserelista betöltve: ' . count($d['pairs']) . ' pár, ' . count($d['test']) . ' próbatermék');
$bad = 0;
foreach ($d['pairs'] as [$old, $new]) {
    if (@preg_match(mandala_hr_pattern($old), '') === false) {
        $bad++;
    }
    if (preg_match('/gyógyul|terápi|stressz|szorong|álmatlan|emésztés|keringés|immun|fejfáj|gyulladás|alvás/iu', $new)) {
        echo "    az új szövegben is van állítás: $new\n";
        $bad++;
    }
}
$ok($bad === 0, 'minden minta érvényes, és az új szövegek tiszták');

echo $fail ? "\n$fail HIBA\n" : "\nMinden ellenőrzés sikeres\n";
exit($fail ? 1 : 0);
