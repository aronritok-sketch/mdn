# Fejlesztői eszközök (nem része a témának)

## `canon.mjs` – blokk-markup kanonizálása

A skill szerint kötelező lépés: a generált sablonokat és oldaltartalmakat a **valódi iu_theme**
szerkesztőjében kell lementeni, amíg minden fájl `"invalid": []`. Használat a fájl fejlécében.
Utána `python3 tools/build-theme.py` (a blokk-markupot már nem írja felül) → új `dist/mandala-tema.zip`.

## `iu_theme-stub/` – TESZT HELYETTESÍTŐ

Az iu_theme forrása nem volt elérhető, ezért a helyi teszthez egy minimális utánzat készült a skill
referenciája alapján: három zónás sablonmotor (`templates/{type}_{position}.html`), `iucb_add_block()`,
dinamikus `iu/*` blokkok (title, content, breadcrumbs, query, terms, search, post-navigation),
shortcode-ok, `iu/form` feldolgozás az `iu_form_submit_{formId}` szűrővel. **Élesben nem használható.**

## Helyi WordPress + WooCommerce teszt

```bash
# WordPress (SQLite illesztővel), WooCommerce, WP-CLI; a témák symlinkkel:
ln -s $PWD/wp-theme/dev/iu_theme-stub  <wp>/wp-content/themes/iu_theme
ln -s $PWD/wp-theme/mandala            <wp>/wp-content/themes/mandala
wp plugin activate woocommerce && wp theme activate mandala && wp mandala setup --demo
BASE=http://localhost:8080 node tests/wp-e2e.mjs      # 67 ellenőrzés: szűrő, kosár, pénztár, rendelés, űrlapok, mobil
```

`test-mu-plugin.php` (a teszt WordPress `wp-content/mu-plugins/` mappájába): levelek fájlba, GLS bővítmény
helyettesítő szállítási módok (futár + csomagpont), `wholesale_customer` szerep, és SQLite alatt a WooCommerce
készletfoglalásának kikapcsolása (a `LOCK IN SHARE MODE` lekérdezés MySQL-es; élesben nem kell).
