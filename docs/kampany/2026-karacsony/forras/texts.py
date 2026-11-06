# Mandala karácsony 2026 – a kampány minden szövege egy helyen (a PDF és a szovegek.md ebből készül).
# Hossz-ellenőrzés: Google RSA cím ≤ 30, leírás ≤ 90; Meta cím ≤ 40 (javasolt), leírás ≤ 30.

PHASES = [
    {
        'key': 'fust', 'name': 'Füstölő-hét', 'dates': 'november 13. (péntek) – november 22. (vasárnap)',
        'short': 'nov. 13–22.', 'offer': 'Egyet fizetsz, kettőt viszel – minden füstölőre',
        'rule': 'A kosárban a füstölőket párba rakjuk: minden párból az olcsóbb 0 Ft (vegyes illatokkal is). A Füstölők kategória mind a 449 terméke benne van: pálcikás, kúp, backflow és gyanta.',
        'excl': 'Más kuponnal nem adódik össze.',
        'goal': 'Olcsó belépő új vásárlóknak, hírlevél-feliratkozók, és a Black Week remarketing-közönségének felépítése: aki most megnéz egy terméket, azt a Black Weekben újra elérjük.',
    },
    {
        'key': 'bw', 'name': 'Black Week', 'dates': 'november 23. (hétfő) – november 30. (hétfő) éjfél',
        'short': 'nov. 23–30.', 'offer': 'Szinte mindenre 20%, a hangtálakra 10%',
        'rule': 'A kedvezmény magától levonódik a kosárban, kód nélkül. A füstölőkre a Black Week alatt is marad az egyet fizetsz, kettőt viszel (a kettő nem adódik össze). Black Friday napján (nov. 27.) a szállítás bármekkora rendelésnél ingyenes.',
        'excl': 'Nem jár az ajándékutalványra és a már akciós termékekre.',
        'goal': 'A hónap bevételi csúcsa. A kosárérték emelése: a nagyobb kedvezmény itt van.',
    },
    {
        'key': 'mikulas', 'name': 'Mikulás', 'dates': 'december 1–6. · Mikulásra december 3-án (csütörtök) 12 óráig kell rendelni',
        'short': 'dec. 1–6.', 'offer': 'Díszcsomagolás és kézzel írt kártya ajándékba',
        'rule': 'A díszcsomagolás (pl. nepáli lokta papír) a kosárban 0 Ft. 15 000 Ft felett egy füstölőt mi teszünk a csomagba (a kosár mutatja, mennyi hiányzik még).',
        'excl': 'Nincs árengedmény (lásd: árszabály).',
        'goal': 'Apró ajándékok, első ajándékvásárlók; a kosárérték 15 000 Ft fölé húzása.',
    },
    {
        'key': 'karacsony', 'name': 'Karácsony', 'dates': 'december 7. – december 18. (péntek) 12:00 · utána december 24-ig az utolsó pillanat',
        'short': 'dec. 7–24.', 'offer': 'Ajándék, ami jelent is valamit',
        'rule': 'Ajándékválasztó, összeállított ajándékcsomagok és ajándékutalvány. A díszcsomagolás és a kártya továbbra is ajándék, 15 000 Ft felett ajándék füstölő, 25 000 Ft felett ingyenes szállítás (marad). December 18. után: ajándékutalvány e-mailben, és személyes átvétel a bemutatóteremben.',
        'excl': 'Nincs árengedmény.',
        'goal': 'A karácsonyi ajándékvásárlás. A határidő („december 18-ig odaér”) a legerősebb üzenet.',
    },
]

TOPBAR = [
    ('Füstölő-hét', 'Egyet fizetsz, kettőt viszel – minden füstölőre, november 22-ig'),
    ('Black Week', 'Black Week: szinte mindenre 20%, a hangtálakra 10% – november 30-ig'),
    ('Black Friday (nov. 27.)', 'Ma a szállítás ingyenes, bármekkora rendelésnél – éjfélig'),
    ('Mikulás', 'Mikulásra december 3-án 12 óráig rendelj – a díszcsomagolás ajándék'),
    ('Karácsony', 'Karácsonyra december 18-án 12 óráig rendelj – díszcsomagolás és kártya ajándékba'),
    ('Utolsó napok (dec. 19–24.)', 'Elfogyott az idő? Ajándékutalvány e-mailben, nyomtatható is'),
]

BANNER = [  # főoldali kampánybanner: felirat, cím, szöveg, gomb
    ('Füstölő-hét – november 22-ig', 'Egyet fizetsz, kettőt viszel', 'Minden füstölőre: két csomagból az olcsóbbat mi álljuk. Nag Champa, Palo Santo, fehér zsálya, szantálfa – több mint 400 illat.', 'Füstölők'),
    ('Black Week – november 30-ig', 'Szinte mindenre 20%', 'A hangtálakra 10%, a füstölőkre marad az egyet fizetsz, kettőt viszel. Kézzel válogatott darabok Nepálból és Indiából.', 'Black Week ajánlatok'),
    ('Mikulás – rendelj december 3-án 12 óráig', 'Ajándék a csizmába', 'Díszcsomagolásban, kézzel írt kártyával – ez most nem kerül semmibe. 15 000 Ft felett egy füstölőt is teszünk mellé.', 'Apró ajándékok'),
    ('Karácsony – rendelj december 18-án 12 óráig', 'Ajándék, ami jelent is valamit', 'Ajándékválasztó, összeállított csomagok és utalvány – díszcsomagolással, kézzel írt kártyával.', 'Ajándékötletek'),
]

CART = [
    ('1+1, páratlan számú füstölő a kosárban', 'Tegyél mellé még egy füstölőt – az egyik ingyen van.'),
    ('15 000 Ft alatt (decemberben)', 'Még {x} Ft, és egy füstölőt mi teszünk a csomagba.'),
    ('25 000 Ft alatt', 'Még {x} Ft az ingyenes szállításig.'),
]

META = {
    'fust': {
        'primary': ['Egyet fizetsz, kettőt viszel – minden füstölőre. Két csomagból az olcsóbbat mi álljuk, november 22-ig.',
                    'Nag Champa, Palo Santo, fehér zsálya, szantálfa… Több mint 400 illat közül választhatsz, és minden második csomag a miénk.',
                    'Esti gyertyafény, egy szál füstölő, csend. Most kettőt viszel egy áráért – a hét végéig.'],
        'headline': ['1+1 füstölő – november 22-ig', 'Egyet fizetsz, kettőt viszel'],
        'desc': ['Mandala · Budapest'],
    },
    'bw': {
        'primary': ['Black Week a Mandalában: szinte mindenre 20%, a hangtálakra 10%. Kézzel válogatott darabok Nepálból és Indiából.',
                    'Ha karácsonyra keresel valami különlegeset, most érdemes: november 30-ig szinte mindenre 20% kedvezmény.',
                    'Hangtál, mala, szobor, füstölőtartó – a Black Week alatt 20%-kal olcsóbban. A füstölőkre marad az egyet fizetsz, kettőt viszel.'],
        'headline': ['Black Week: −20% szinte mindenre', 'Hangtálak most −10%'],
        'desc': ['November 30-ig'],
    },
    'bf': {
        'primary': ['Csak ma: bármekkora rendelésnél ingyenes a szállítás – a Black Week kedvezményei mellé. Éjfélig.'],
        'headline': ['Ma ingyenes a szállítás'],
        'desc': ['Black Friday · éjfélig'],
    },
    'mikulas': {
        'primary': ['Mikulásra december 3-án 12 óráig rendelj: díszcsomagolásban, kézzel írt kártyával küldjük – ez most nem kerül semmibe.',
                    'Apró ajándék, ami jelent is valamit: mala karkötő, füstölő, kis szobor. 15 000 Ft felett egy füstölőt mi teszünk mellé.'],
        'headline': ['Ajándék a csizmába', 'Díszcsomagolás ajándékba'],
        'desc': ['Rendelj dec. 3., 12 óráig'],
    },
    'karacsony': {
        'primary': ['Ajándék, ami jelent is valamit. Füstölő, mala, hangtál, réz kulacs – díszcsomagolásban, kézzel írt kártyával.',
                    'Ami december 18-án 12 óráig beérkezik, karácsonyig odaér. A díszcsomagolás ajándék, 15 000 Ft felett egy füstölő is.'],
        'headline': ['Karácsonyra december 18-ig', 'Ajándék, ami jelent is valamit'],
        'desc': ['Díszcsomagolás ajándékba'],
    },
    'utalvany': {
        'primary': ['Elfogyott az idő? Az ajándékutalvány e-mailben érkezik, nyomtatható is – és ő választ, több mint 3 000 termék közül.'],
        'headline': ['Ajándékutalvány e-mailben'],
        'desc': ['Részletekben is beváltható'],
    },
}

GOOGLE_HEAD = [
    'Egyet fizetsz, kettőt viszel', '1+1 füstölő a Mandalában', 'Több mint 400 féle füstölő', 'Black Week: −20%',
    'Hangtálak most −10%', 'Karácsonyi ajándék Nepálból', 'Díszcsomagolás ajándékba', 'Rendelj december 18-ig',
    'Kézzel válogatott darabok', 'Mala, hangtál, füstölő', 'Ajándékutalvány e-mailben', 'Bemutatóterem a IX. kerületben',
]
GOOGLE_DESC = [
    'Egyet fizetsz, kettőt viszel minden füstölőre november 22-ig. Nag Champa, Palo Santo…',
    'Black Week november 30-ig: szinte mindenre 20% kedvezmény, a hangtálakra 10%.',
    'Karácsonyi ajándék, ami jelent is valamit: díszcsomagolásban, kézzel írt kártyával.',
    'Ami december 18-án 12 óráig beérkezik, karácsonyig odaér. 25 000 Ft-tól ingyen szállítunk.',
]
GOOGLE_PROMO = [
    ('Füstölő-hét', 'Akció bővítmény: „Vegyél egyet, kapsz egyet” – Füstölők, nov. 13–22.; Merchant Center akció ugyanígy (a Shopping-hirdetésen „Akció” címke).'),
    ('Black Week', 'Akció bővítmény: „20% kedvezmény” – alkalom: Black Friday, nov. 23–30.; Merchant Center akció a hangtálakra 10%-kal külön.'),
    ('Mikulás / Karácsony', 'Nincs százalékos akció; a hirdetésszövegben a határidő visszaszámlálóval (dec. 3. 12:00, dec. 18. 12:00).'),
]

EMAILS = [  # dátum, típus, A tárgy, B tárgy, előfejléc, tartalom
    ('nov. 13. (péntek) reggel', 'alap', 'Egyet fizetsz, kettőt viszel – minden füstölőre', 'Füstölő-hét: kettőt viszel egy áráért',
     'Nag Champa, Palo Santo, fehér zsálya és még több mint 400 illat. Vasárnap éjfélig.',
     'Nyitókép (Füstölő-hét); 4 illat egy-egy mondattal (mire, mikor); hogyan működik az 1+1 (két csomagból az olcsóbb ingyenes); gomb: Füstölők.'),
    ('nov. 20. (péntek)', 'választható', 'Vasárnap éjfélig tart az 1+1', 'Hétfőtől Black Week – előtte még egy hétvége',
     'Az egyet fizetsz, kettőt viszel vasárnap véget ér. Hétfőn indul a Black Week.',
     'Emlékeztető az 1+1 végére; előzetes a Black Weekről (dátum, mérték), hogy a feliratkozók várják.'),
    ('nov. 23. (hétfő) reggel', 'alap', 'Black Week: szinte mindenre 20%', 'Indul a Black Week – hétfő éjfélig',
     'A hangtálakra 10%, a füstölőkre marad az egyet fizetsz, kettőt viszel.',
     'Nyitókép (Black Week); 6 válogatott termék kategóriánként (hangtál, mala, szobor, füstölőtartó, textil, illóolaj); „karácsonyra is gondolj” blokk; gomb: Black Week ajánlatok.'),
    ('nov. 27. (péntek) reggel', 'alap', 'Ma a szállítás ránk jön', 'Black Friday: ma bármekkora rendelés szállítás nélkül',
     'Csak ma éjfélig, a Black Week kedvezményei mellé.',
     'Rövid levél, egy kép (Black Friday), egy mondat, egy gomb.'),
    ('nov. 30. (hétfő) délután', 'alap', 'Ma éjfélig tart a Black Week', 'Utolsó nap: szinte mindenre 20%',
     'Éjfél után visszaállnak az árak.',
     'A Black Week legnépszerűbb termékei (a hét adataiból); gomb.'),
    ('dec. 2. (szerda)', 'alap', 'Mikulásra csütörtök délig rendelj', 'Ajándék a csizmába, díszcsomagolásban',
     'Kézzel írt kártyával küldjük – ez most nem kerül semmibe.',
     '5 apró ajándék 5 000 Ft alatt (valódi termékek); díszcsomagolás képe; határidő kiemelve.'),
    ('dec. 9. (szerda)', 'alap', 'Ajándék, ami jelent is valamit', 'Karácsonyi ajándékötletek a Mandalától',
     'Ajándékválasztó, összeállított csomagok és utalvány – 15 000 Ft felett egy füstölő ajándék.',
     '„Kinek mit?” – 4 címzett (anyának, barátnak, jógázónak, aki mindent megkapott már), mindegyikhez 2–3 termék; ajándékválasztó; utalvány.'),
    ('dec. 16. (szerda)', 'alap', 'Karácsonyra péntek délig rendelj', 'Még két nap, és odaér karácsonyra',
     'Ami december 18-án 12 óráig beérkezik, karácsonyig nálad van.',
     'Határidő nagyban; a legnépszerűbb ajándékok; utalvány mint biztos megoldás.'),
    ('dec. 20. (vasárnap)', 'választható', 'Ha már nincs idő: ajándékutalvány', 'Az utolsó pillanatban is van jó ajándék',
     'E-mailben érkezik, nyomtatható is – ő választ, több mint 3 000 termék közül.',
     'Utalvány-kép; hogyan működik (összeg, címzett, üzenet); bemutatóterem: személyes átvétel.'),
]

SOCIAL = [
    ('Füstölő-hét', 'Melyik illat mire jó? – lapozós poszt', 'Nag Champa az elcsendesedéshez, Palo Santo a lakás frissítéséhez, fehér zsálya az új kezdethez, szantálfa az esti nyugalomhoz. Most kettőt viszel egy áráért – vasárnap éjfélig.'),
    ('Füstölő-hét', 'Story-sor (3 kocka)', '1. „Egyet fizetsz…” 2. „…kettőt viszel.” 3. kosárba tett két füstölő, a második 0 Ft – „Ennyi.”'),
    ('Black Week', 'Így szólaltasd meg a hangtálad – 30 mp-es videó', 'Ütő, kör a peremen, türelem. A hangtálakra most 10% kedvezmény, minden másra 20% – november 30-ig.'),
    ('Mikulás', '5 apró ajándék 5 000 Ft alatt', 'Rózsakvarc mala karkötő (4 970 Ft), szantálfa karmala (3 830 Ft), Palo Santo illóolaj (1 200 Ft), lótuszvirág füstölőtartó S (890 Ft), 6 cm-es Lakshmi szobor (1 490 Ft). Díszcsomagolásban, kézzel írt kártyával – ajándékba.'),
    ('Karácsony', 'A csapat ajánlja – valódi fotó a bemutatóteremből', 'Mindenki a kedvencét fogja a kezében, egy mondattal: „Ezt adnám anyukámnak, mert…”'),
]

TASKS = [
    ('Az ajánlatok jóváhagyása (mértékek, kizárások, dátumok)', 'Mandala', 'nov. 9.'),
    ('Füstölő-készlet: a népszerű illatokból legyen elég (az 1+1 alatt akár kétszeres fogyás)', 'Mandala', 'nov. 10.'),
    ('Szállítási határidők egyeztetése a raktárral és a GLS-szel (dec. 3. és dec. 18., 12:00)', 'Mandala', 'nov. 10.'),
    ('Hírlevél: hány levél mehet (a feliratkozó-doboz havi kettőt ígér)', 'Mandala', 'nov. 10.'),
    ('Bemutatóterem: érvényesek-e ott is az ajánlatok; plakát kinyomtatása, kihelyezése', 'Mandala', 'nov. 12.'),
    ('Meta: a vásárlás-mérés rendbetétele (Events Manager), hogy a Meta vásárlásra tudjon optimalizálni', 'Áron', 'nov. 12.'),
    ('Webshop: 1+1 szabály, felső sáv, főoldali banner, jelvények, kosár-üzenetek', 'HelloProVision', 'nov. 12.'),
    ('Webshop: Black Week kedvezmény (20% / 10%), Black Friday ingyenes szállítás', 'HelloProVision', 'nov. 20.'),
    ('Webshop: ingyenes díszcsomagolás, 15 000 Ft-os ajándék füstölő, ajándékutalvány és -csomag élesítése', 'HelloProVision', 'nov. 30.'),
    ('Google: Merchant Center akciók, akció-bővítmény, új eszközcsoport és hirdetéscsoportok', 'HelloProVision', 'nov. 12. és nov. 20.'),
    ('Meta: új kreatívok fázisonként a meglévő kampányokba', 'HelloProVision', 'nov. 12., 20., 30., dec. 6.'),
    ('Hírlevelek összeállítása MailerLite-ban, próbalevél, időzítés', 'HelloProVision', 'két nappal minden küldés előtt'),
    ('Heti riport: rendelés, bevétel, kosárérték, ROAS, feliratkozók', 'HelloProVision', 'hétfőnként'),
]


def check():
    errs = []
    for h in GOOGLE_HEAD:
        if len(h) > 30:
            errs.append(f'Google cím > 30: {h} ({len(h)})')
    for d in GOOGLE_DESC:
        if len(d) > 90:
            errs.append(f'Google leírás > 90: {d} ({len(d)})')
    for k, m in META.items():
        for h in m['headline']:
            if len(h) > 40:
                errs.append(f'Meta cím > 40: {h}')
        for d in m['desc']:
            if len(d) > 30:
                errs.append(f'Meta leírás > 30: {d}')
    for e in EMAILS:
        for subj in (e[2], e[3]):
            if len(subj) > 60 or '!' in subj:
                errs.append(f'Tárgy: {subj}')
    return errs


if __name__ == '__main__':
    print(check() or 'minden hossz rendben')
    print([len(h) for h in GOOGLE_HEAD])
    print([len(d) for d in GOOGLE_DESC])
