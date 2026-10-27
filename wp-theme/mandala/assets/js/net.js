// Hálózati tűrőképesség – az ELIN tárhely tűzfala IP-cím szerint elutasít POST kéréseket (nginx „403 Forbidden”
// oldal), a GET-eket mindig átengedi. Ez a modul:
//  - felismeri a tiltást (és megkülönbözteti a WordPress / WooCommerce saját 403-jától, ami JSON vagy „-1”);
//  - a kosárba tételt GET-tel pótolja: előbb AJAX-szal (wc-ajax=add_to_cart&…&mfb=1), végső esetben oldalbetöltéssel
//    (?add-to-cart=…&mfb=1 – a szerver utána tiszta címre irányít, így a frissítés nem tesz be újra semmit);
//    5xx / hálózati hibánál, és ha a GET ismétlésre sem jött válasz, előbb GET-tel megnézi a kosarat, hogy semmi ne
//    kerüljön be kétszer; ha a kosár sem kérdezhető le, nem próbálja újra (a vásárló megnézi a kosarát);
//  - a saját wc-ajax végpontokat (minikosár, ajándékcsomag, viszonteladói gyorsrendelő, pénztár e-mail) tiltáskor
//    GET-tel ismétli;
//  - személyes adat (utalvány címzettje, üzenet, e-mail) nem kerül a GET címbe (a tárhely naplója, a mérőkódok
//    oldalmegtekintése): egy rövid életű sütiben megy a kéréssel, a szerver egyszer olvassa (elin.php);
//  - egy kis GET jelzéssel megszámoltatja a tiltásokat (WooCommerce → Mandala állapot), oldalanként végpontonként
//    legfeljebb egyszer, személyes adat nélkül.
// Importkor nem nyúl a DOM-hoz – a tests/resilience.mjs Node-ban, csonkolt fetch-csel futtatja.

const M = () => globalThis.window?.MANDALA || {};
const here = () => globalThis.location?.href || 'http://localhost/';
export const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Az nginx saját 403-as oldala: nem JSON, és a „403 Forbidden” cím van benne (a WP / WC 403-ja JSON vagy „-1”). */
export function isElinBlock(status, contentType = '', body = '') {
  if (Number(status) !== 403 || /json/i.test(String(contentType || ''))) return false;
  const b = String(body || '');
  return b.length < 5000 && /403 Forbidden/i.test(b);
}

/** Hiba, ami után érdemes tartalékra váltani: tiltás, szerverhiba, hálózati hiba (0). */
export const isFailure = (status) => !status || Number(status) === 403 || Number(status) >= 500;

/** A wc-ajax végpont neve egy címből (pl. /?wc-ajax=add_to_cart → add_to_cart); REST-nél „rest”. */
export function endpointOf(url) {
  const s = String(url || '');
  const m = s.match(/[?&]wc-ajax=([a-z0-9_-]+)/i);
  if (m) return m[1].toLowerCase();
  if (/admin-ajax\.php/.test(s)) return 'admin_ajax';
  if (/\/wp-json\/|rest_route=|\/v1\//.test(s)) return 'rest';
  return '';
}

export const wcAjaxUrl = (endpoint) => String(M().wcAjax || '/?wc-ajax=%%endpoint%%').replace('%%endpoint%%', endpoint);

const entriesOf = (params) => {
  if (!params) return [];
  if (Array.isArray(params)) return params.filter((e) => Array.isArray(e) && e.length === 2); // [[kulcs, érték]…]
  if (typeof params.entries === 'function' && !(params instanceof Map)) return [...params.entries()];
  return Object.entries(params);
};

/** Paraméterek a cím lekérdezés-részébe (szöveg és szám; fájl nem). */
export function withQuery(url, params) {
  const u = new URL(url, here());
  for (const [k, v] of entriesOf(params)) {
    if (typeof v === 'string' || typeof v === 'number') u.searchParams.append(k, String(v));
  }
  return u.href;
}

/** A WooCommerce kosár-lenyomata a sütiből (üres kosárnál nincs süti → ''). */
export function cartHash(doc = globalThis.document) {
  const m = String(doc?.cookie || '').match(/(?:^|;\s*)woocommerce_cart_hash=([^;]*)/);
  return m ? decodeURIComponent(m[1]) : '';
}

// A kosárba tétel GET-es címéből és a visszairányítás után a címből eltávolítandó paraméterek.
const CART_PARAMS = ['add-to-cart', 'quantity', 'variation_id', 'product_id', 'mfb', 'mfs', 'mandala_added', 'mandala_qty'];
export const isCartParam = (k) => CART_PARAMS.includes(k) || k.startsWith('attribute_') || k.startsWith('quantity[') || /^mandala_(sub|voucher)/.test(k);

/* ---------- Személyes adat a GET kérés mellett: rövid életű sütiben, nem a címben ---------- */

// Ugyanez a lista a szerveren: elin.php, mandala_fb_private_key().
const PRIVATE_KEY = /^(mandala_voucher_(to_name|to_email|message)|message|email|name)$/;
const SKIP_KEYS = ['add-to-cart', 'quantity', 'product_id', 'security', '_wpnonce', 'mfb', 'mfs'];

/**
 * Az űrlap mezőit kettéosztja: ami mehet a címbe, és ami személyes adat (sütibe kerül). A süti a GET kérés jelével
 * (mfs) együtt érvényes, 10 percig; ha nem fér bele (~3,8 KB), a mezők a címben maradnak – a kosárba tétel fontosabb.
 * Visszaad: [[kulcs, érték]…] a címhez és a jel (vagy '').
 */
export function stashPrivate(fields, { skip = SKIP_KEYS, doc = globalThis.document } = {}) {
  const pub = []; const priv = {};
  for (const [k, v] of entriesOf(fields)) {
    if (typeof v !== 'string' || v === '' || skip.includes(k)) continue;
    if (PRIVATE_KEY.test(k)) priv[k] = v; else pub.push([k, v]);
  }
  if (!Object.keys(priv).length) return { pub, token: '' };
  const token = Math.random().toString(36).slice(2, 10) || 'x';
  const val = encodeURIComponent(JSON.stringify({ t: token, f: priv }));
  try {
    if (!doc || val.length > 3800) throw new Error('nem fér');
    const secure = globalThis.location?.protocol === 'https:' ? '; Secure' : '';
    doc.cookie = `mandala_fbf=${val}; Path=/; Max-Age=600; SameSite=Lax${secure}`;
    return { pub, token };
  } catch {
    return { pub: pub.concat(Object.entries(priv)), token: '' };
  }
}

/**
 * GET-es kosárba tétel (WooCommerce ?add-to-cart=) a jelenlegi oldalon: a termék, a mennyiség, és az űrlap minden
 * mezője (változat, előfizetés, utalvány adatai) + mfb=1 jel. A szerver a sikeres betétel után tiszta címre irányít.
 */
export function addToCartUrl({ id, qty = 1, fields = null, base = '' }) {
  const u = new URL(base || here(), here());
  u.hash = '';
  [...u.searchParams.keys()].filter(isCartParam).forEach((k) => u.searchParams.delete(k));
  const { pub, token } = stashPrivate(fields);
  pub.forEach(([k, v]) => u.searchParams.append(k, v));
  u.searchParams.set('add-to-cart', String(id));
  // Csoportos termék: a mennyiségek termékenként jönnek (quantity[ID]) – ilyenkor nincs egyetlen mennyiség.
  if (!pub.some(([k]) => k.startsWith('quantity['))) u.searchParams.set('quantity', String(Math.max(1, Number(qty) || 1)));
  u.searchParams.set('mfb', '1');
  if (token) u.searchParams.set('mfs', token);
  return u.href;
}

/** A kosár aktuális állapota GET-tel (fragmentek + lenyomat); a tűzfal a GET-et átengedi. */
export async function probeCart({ fetchImpl = globalThis.fetch } = {}) {
  try {
    const res = await fetchImpl(withQuery(wcAjaxUrl('get_refreshed_fragments'), { _: Date.now().toString(36) }), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!res || !res.ok) return null;
    const data = await res.json();
    return data && typeof data === 'object' && 'cart_hash' in data ? data : null;
  } catch {
    return null;
  }
}

/**
 * Ugyanaz a kosárba tétel GET-tel (wc-ajax=add_to_cart&…&mfb=1 – a szerver a GET-et a WooCommerce POST-kezelőjének
 * adja át, inc/features/elin.php). Visszaad: a WooCommerce válasza (fragmentek / {error, product_url}) vagy null.
 */
export async function getAddToCart({ id, qty = 1, fields = null, fetchImpl = globalThis.fetch }) {
  const { pub, token } = stashPrivate(fields, { skip: ['add-to-cart', 'quantity', 'product_id', 'mfb', 'mfs'] });
  const params = new URLSearchParams(pub);
  params.set('product_id', String(id));
  params.set('quantity', String(Math.max(1, Number(qty) || 1)));
  params.set('mfb', '1');
  if (token) params.set('mfs', token);
  params.set('_', Date.now().toString(36));
  try {
    const res = await fetchImpl(withQuery(wcAjaxUrl('add_to_cart'), params), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!res || !res.ok) return null;
    const data = await res.json();
    return data && typeof data === 'object' ? data : null;
  } catch {
    return null;
  }
}

/**
 * Sikertelen AJAX-os kosárba tétel után:
 *  - tiltás (403): a kérés el sem érte a WordPresst, biztosan nincs a kosárban;
 *  - 5xx / hálózati hiba: lehet, hogy mégis bekerült → GET-tel megnézzük a kosarat; ha a lenyomata változott,
 *    nem tesszük be újra (onAdded a friss fragmentekkel).
 * Ha nincs a kosárban: ugyanaz GET-tel AJAX-szal (retry). Ha arra nem jött válasz (lehet, hogy a szerver mégis
 * betette), oldalbetöltés előtt újra megnézzük a kosarat. Ha a kosár nem kérdezhető le (két próbával sem), nem
 * próbáljuk újra – ismeretlen, bekerült-e (onUnknown: a vásárló nézze meg a kosarát); dupla tétel így nem lesz.
 * fallbackUrl: a GET-es oldalbetöltés címe, vagy függvény, ami csak akkor készíti el (a személyes mezők sütije így
 * a navigálás előtt frissül). Visszaad: 'added' (bent van) | 'retried' (a GET betette) | 'navigate' | 'unknown'.
 */
export async function recoverAddToCart({ status = 0, blocked = false, before = '', retry = null, fallbackUrl, navigate, onAdded, onUnknown = null, probe = probeCart, wait = sleep }) {
  const check = async (delay) => {
    for (const ms of [delay, 1500]) {
      await wait(ms);
      const p = await probe();
      if (p) return p.cart_hash && p.cart_hash !== before ? { state: 'changed', p } : { state: 'same' };
    }
    return { state: 'unknown' };
  };
  const fallback = () => (typeof fallbackUrl === 'function' ? fallbackUrl() : fallbackUrl);
  const settle = (c) => {
    if (c.state === 'changed') { onAdded?.(c.p); return 'added'; }
    onUnknown?.();
    return 'unknown';
  };
  if (!blocked) {
    const c = await check(status ? 600 : 1500);
    if (c.state !== 'same') return settle(c);
  }
  const data = retry ? await retry() : null;
  if (data?.fragments && !data.error) {
    onAdded?.(data);
    return 'retried';
  }
  if (data?.error) {
    navigate(data.product_url || fallback());
    return 'navigate';
  }
  if (retry) {
    const c = await check(600);
    if (c.state !== 'same') return settle(c);
  }
  navigate(fallback());
  return 'navigate';
}

/**
 * A pénztár munkamenete GET-tel (elin.php: wc-ajax=mandala_order_state): létrejött-e a rendelés (placed, és a
 * visszaigazoló oldal címe), és a kosár lenyomata. null, ha nem kérdezhető le.
 */
export async function orderState({ fetchImpl = globalThis.fetch } = {}) {
  try {
    const res = await fetchImpl(withQuery(wcAjaxUrl('mandala_order_state'), { _: Date.now().toString(36) }), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
    if (!res || !res.ok) return null;
    const data = await res.json();
    return data && typeof data === 'object' && 'placed' in data ? data : null;
  } catch {
    return null;
  }
}

/* ---------- Jelzés a tiltásokról (GET – a sendBeacon POST lenne, azt is tiltaná a tűzfal) ---------- */

const ENDPOINTS = ['add_to_cart', 'product_form', 'update_order_review', 'checkout', 'get_refreshed_fragments', 'apply_coupon', 'remove_coupon', 'cart',
  'mandala_set_qty', 'mandala_bulk_add', 'mandala_gift_add', 'mandala_capture', 'rest', 'admin_ajax'];
let sent = new Set();
export const _resetBeacon = () => { sent = new Set(); };

/** Egy tiltás jelzése: oldalanként végpontonként egyszer, összesen legfeljebb 6-szor. */
export function reportBlocked(endpoint, { send } = {}) {
  const e = ENDPOINTS.includes(endpoint) ? endpoint : 'other';
  const rest = M().rest;
  if (!rest || sent.has(e) || sent.size >= 6) return false;
  sent.add(e);
  const url = withQuery(`${rest}blocked`, { e, f: sent.size === 1 ? 1 : 0, _: Date.now().toString(36) });
  try {
    if (send) send(url);
    else if (globalThis.fetch) globalThis.fetch(url, { keepalive: true, credentials: 'omit', cache: 'no-store' }).catch(() => {});
    else new globalThis.Image().src = url;
  } catch { /* a jelzés nem fontosabb a vásárlásnál */ }
  return true;
}

/**
 * POST egy wc-ajax végpontra (a téma sajátjai és a WooCommerce apply_coupon-ja); ha a tűzfal tiltja, ugyanaz GET-tel
 * (elin.php „GET → POST híd”: a kezelő ugyanúgy fut, a nonce ellenőrzés marad). A személyes mezők (e-mail, név,
 * üzenet) sütiben mennek, nem a címben; omit: a GET-ből kihagyandó mezők. Visszaad: Response vagy null (hálózati hiba).
 */
export async function wcPost(endpoint, body, { fetchImpl = globalThis.fetch, omit = [] } = {}) {
  const url = wcAjaxUrl(endpoint);
  let res = null;
  try { res = await fetchImpl(url, { method: 'POST', credentials: 'same-origin', body }); } catch { res = null; }
  if (res && res.status === 403) {
    const text = await res.clone().text().catch(() => '');
    if (isElinBlock(403, res.headers?.get?.('content-type') || '', text)) {
      reportBlocked(endpoint);
      const { pub, token } = stashPrivate(body, { skip: ['wc-ajax', 'mfb', 'mfs', ...omit] });
      const get = withQuery(withQuery(url, pub), token ? { mfb: 1, mfs: token } : { mfb: 1 });
      try { return await fetchImpl(get, { credentials: 'same-origin', cache: 'no-store' }); } catch { return null; }
    }
  }
  return res;
}

/* ---------- Pénztár: üzenet a vásárlónak ---------- */

const escHtml = (s = '') => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

/** Az elérhetőség a téma beállításaiból (Mandala bolt adatai), alapértékkel. */
export function contactOf(m = M()) {
  const c = m.contact || {};
  return { phone: c.phone || '+36 30 892 8385', email: c.email || 'info@mandala.hu' };
}

/** Elérhetőség linkekkel (telefon · e-mail). */
export function contactHtml(contact = contactOf()) {
  const tel = contact.phone.replace(/[^\d+]/g, '');
  return `<a href="tel:${escHtml(tel)}">${escHtml(contact.phone)}</a> · <a href="mailto:${escHtml(contact.email)}">${escHtml(contact.email)}</a>`;
}

/**
 * A pénztár hibaüzenete. kind: 'review' (szállítás / összesítő frissítése) | 'order' (rendelés leadása);
 * blocked: a tűzfal tiltotta (a kérés el sem érte a boltot); expired: a WordPress lejárt ellenőrző kódja (sokáig
 * nyitva hagyott oldal) – ilyenkor az újrapróbálás nem segít, frissíteni kell.
 */
export function checkoutTroubleHtml({ kind = 'review', blocked = false, expired = false, contact = contactOf() } = {}) {
  if (expired) {
    return `<span><strong>Ez az oldal már régóta nyitva van, ezért frissíteni kell.</strong> A kosarad megvan, semmi nem veszett el – frissítés után folytathatod.</span><button type="button" class="iu-button iu-button-small" data-net-retry="reload">Frissítem az oldalt</button>`;
  }
  const reach = `válts másik internetre (például wifi helyett mobilnetre), vagy szólj nekünk, és felvesszük a rendelésed: ${contactHtml(contact)}`;
  let lead;
  if (kind === 'order' && !blocked) {
    lead = `<strong>Nem kaptunk választ a pénztártól.</strong> Lehet, hogy a rendelésed mégis megérkezett – mielőtt újra elküldöd, nézd meg a postafiókod (a visszaigazolás pár percen belül jön). Ha nem jött levél, próbáld újra – ha akkor sem megy, ${reach}`;
  } else if (kind === 'order') {
    lead = `<strong>A rendelést most nem tudtuk elküldeni.</strong> Még nem jött létre, és nem vontunk le semmit. Próbáld újra – ha akkor sem megy, ${reach}`;
  } else {
    lead = `<strong>Most nem értük el a pénztárt.</strong> A kosarad megvan, semmi nem veszett el. Próbáld újra – ha akkor sem megy, ${reach}`;
  }
  return `<span>${lead}</span><button type="button" class="iu-button iu-button-small" data-net-retry="${kind}"${kind === 'order' && !blocked ? ' data-check="1"' : ''}>Újrapróbálom</button>`;
}

/** Üzenet, ha kiderült, hogy a válasz nélkül maradt rendelés mégis létrejött (ne adja le újra). */
export function orderPlacedHtml({ url = '' } = {}) {
  return url
    ? `<span><strong>A rendelésed megérkezett.</strong> Átviszünk a visszaigazolás oldalára – ne add le újra. Ha nem lép tovább, <a href="${escHtml(url)}">kattints ide</a>.</span>`
    : '<span><strong>A rendelésed megérkezett</strong> – ne add le újra. A visszaigazolást pár percen belül e-mailben is megkapod.</span>';
}
