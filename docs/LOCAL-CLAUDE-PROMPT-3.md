# Prompt a helyi Claude-nak – 3. kör: miért nem mennek ki a levelek

> Előtte töltsd fel az új témát (mandala-tema.zip → Megjelenés → Témák → Feltöltés → Csere a feltöltöttre).
> A téma magától javítja: a régi kategóriacímek (pl. a Facebook-poszt „kapucnis felsők” linkje) a hasonló nevű
> mostani kategóriára visznek; a sima vásárlóként átjött régi viszonteladók visszakapják a viszonteladói szerepet.

---

Szia! A mandala.hu WordPress + WooCommerce adminjában kell utánanézned egy hibának. Be vagyok lépve, a böngésző
nyitva van. Magyarul dolgozz. Szabályok: rendelést, vásárlót, terméket ne törölj és ne módosíts; jelszavakhoz, API- és
SMTP-kulcsokhoz ne nyúlj, és ne írd ki őket; ha döntés kell, állj meg és kérdezz. A `/juta/` kezdetű címeket ne nyisd meg.

**A hiba:** a vásárlók nem kapnak levelet a bolttól – se rendelés-visszaigazolást, se regisztrációs levelet, se a
kézzel kiküldött „átvehető a csomagod” levelet. (A Teya a kártyás fizetésről küld, az nem a bolté.) Ugyanezért a
régi vásárlók és viszonteladók nem tudnak új jelszót kérni.

1. **A téma levélállapota.** WooCommerce → Mandala levelek (Levélközpont). A lap tetején van egy állapotdoboz:
   írd le szó szerint mind a sorát (pipa / felkiáltójel / X) – benne van, mivel küld a szerver, be van-e kapcsolva a
   „Bemutató mód”, és mi volt az utolsó küldési hiba. Alatta a levélnapló: írd le az utolsó 15 sor dátumát, típusát,
   címzettjét (csak a domaint, pl. @gmail.com) és állapotát (elküldve / hiba).

2. **Bemutató mód.** Ha az állapotdoboz szerint be van kapcsolva („minden levél ide megy: …”): Mandala varázsló →
   Bemutató mód → **Kikapcsolás**. (Ez a fejlesztéshez volt; bekapcsolva minden vásárlói levél egy belső címre megy.)

3. **WooCommerce levelek.** WooCommerce → Beállítások → E-mailek: írd le, melyik levél van bekapcsolva. Különösen:
   „Új rendelés”, „Feldolgozás alatt lévő rendelés”, „Teljesített rendelés”, „Új fiók”, „Jelszó visszaállítása”.
   Ha valamelyik vásárlói levél ki van kapcsolva, **kérdezz**, mielőtt bekapcsolod. Ugyanitt lent: „Feladó neve” és
   „Feladó e-mail-címe” – írd le.

4. **Levélküldő bővítmény.** Bővítmények: melyik SMTP-bővítmény aktív (WP Mail SMTP, FluentSMTP, Post SMTP…)? Nyisd
   meg a beállítását, és írd le (jelszó nélkül): a küldő mód (pl. „Egyéb SMTP”, „Alapértelmezett / PHP”), az SMTP
   kiszolgáló, port, titkosítás, a feladó címe. Utána **Eszközök → E-mail teszt** (WP Mail SMTP-ben „Email Test”):
   küldj egy tesztlevelet a(z) [SAJÁT CÍM] címre, és írd le szó szerint az eredményt / hibaüzenetet. Ha van
   „Email Log” / „Napló”, az utolsó 10 bejegyzés állapotát is.

5. **Próba a bolt saját levelével.** WooCommerce → Mandala levelek → (egy rendelési levél, pl. „Feldolgozás alatt
   lévő rendelés”) → „Tesztlevél küldése” a(z) [SAJÁT CÍM] címre. Írd le, mit ír ki, és megérkezett-e (spam mappa is).

6. **Viszonteladó, aki nem tud belépni.** Felhasználók → keresés: [A VISZONTELADÓ E-MAIL-CÍME]. Írd le a szerepét
   (pl. „Wholesale Customer” / „Vásárló”). Ha „Vásárló”, szólj (az új téma ezt visszaállítja, ha a régi boltban
   viszonteladó volt). **Ne** állíts be jelszót magadtól – ha a levelek nem mennek, a tulajdonos dönt, hogy kézzel ad-e
   neki ideiglenes jelszót (Felhasználók → szerkesztés → „Új jelszó beállítása”).

**Jelentés a végén** táblázatban (1–6), és egy mondat: szerinted mi a hiba oka (pl. „az SMTP hitelesítés hibás”, „a
PHP mail() küld, a levél nem megy ki”, „a vásárlói levelek ki vannak kapcsolva”, „bemutató mód”).

---

Nem a prompt része (neked): ha a 4. pontban a teszt is hibás, a levélküldést az ELIN-nel kell rendbe tenni (SMTP
fiók a bolt címéhez, SPF / DKIM a domainhez) – a téma ezen nem tud segíteni. Ha a teszt jó, de a vásárlói levelek nem
mennek, a 1–3. pont eredményéből látszik az ok – küldd el nekem.
