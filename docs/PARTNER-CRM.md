# Mandala Partner CRM – a webshop oldala

A viszonteladó partnereket a csapat a **Mandala Partner CRM**-ben kezeli: a HelloProVision CRM-jének egy külön,
a Mandalára szabott példánya (javasolt cím: `partner.mandala.hu`). A CRM leírása, a szakaszok, az import és a
telepítés: `aronritok-sketch/hlprv` → `docs/partner/README.md`. Ez a fájl a webshop (a `mandala` téma) oldalát írja le.

## Mit küld a webshop a CRM-nek

| Esemény | Mikor | Honnan |
|---|---|---|
| Viszonteladói jelentkezés | a „Viszonteladóknak” oldal űrlapja (cégnév, adószám, tevékenység, település, weboldal, kapcsolattartó, várható rendelés, érdeklődés) | `iu_form_submit_viszontelado` – azonnal (háttérfeladat) |
| Viszonteladói fiók | regisztráció, szerepváltás (`wholesale_customer`), cím- és adatváltozás | `user_register`, `set_user_role`, `add_user_role`, `profile_update` |
| Rendelés | viszonteladó rendelése: létrejötte és minden állapotváltozása, tételekkel (cikkszám, név, db, nettó, kategória) | `woocommerce_checkout_order_processed`, `woocommerce_order_status_changed`; a rendelés `_mandala_wholesale` jelölőt kap |
| Aktivitás | belépés, árlista CSV, termékfotók ZIP, gyorsrendelő, új érkezések feliratkozás | `wp_login`, `mandala_b2b_activity` (b2b.php) |
| Készlet | változás 10 percenként (ujjlenyomat; a JUTA közvetlen DB-írása után is), teljes lista naponta | `wc_product_meta_lookup`, nagyker ár, `_mandala_incoming`, 90 napos eladás |

A CRM válaszában jöhet parancs: **approve** (a CRM-ben jóváhagyott jelentkező viszonteladói szerepet kap; ha nincs
fiókja, a WooCommerce nyit egyet, és elküldi a fiók-levelet – **ehhez a levélküldésnek működnie kell**, lásd a nyitott
levél-hibát) és **resync** (teljes újraküldés). Az eredményt a következő kérésben nyugtázza.

## Beállítás

1. A CRM-ben: Partnerek → Beállítások → **Összekötő kód** → másolás.
2. A webshopban: **WooCommerce → Partner CRM** → a kód beillesztése → Mentés és kapcsolódás.
   (Vagy a wp-config.php-ban: `MANDALA_CRM_URL` = a CRM `…/wp-json/hpv/v1/partner/connect` címe, `MANDALA_CRM_SECRET`.)
3. Az első kapcsolódás után a webshop 10 percenként adagonként (40 viszonteladó a rendeléseivel) átküld mindent,
   a végén a teljes készletet. Az állapot (utolsó kapcsolat, sor, hiba) ugyanott, és az őrszemben („Partner CRM
   összekötő”).

A téma 1.1.202610121823-tól van benne. Összekötés nélkül a modul semmit nem gyűjt és nem küld. A titok csak az admin felületen / wp-config.php-ban van, a
repóban soha.

## Teszt

`tests/wp-features.mjs` → „Partner CRM összekötő”: közös aláírási tesztvektor (a CRM tesztjében is), https-kötelező kód,
összekötés nélkül nincs gyűjtés, aláírt kérés (a JS-ben újraszámolt HMAC), viszonteladói rendelés tételekkel, belépés,
árlista, jelentkezés (spam-csapda nélkül), teljes küldés (fiókok, készlet eleje / vége, nagyker ár), jóváhagyás → új
fiók a cégadatokkal, nyugta, admin oldal, őrszem.
