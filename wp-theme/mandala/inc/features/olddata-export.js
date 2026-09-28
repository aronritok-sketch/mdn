// Régi bolt ADATAINAK exportja a böngészőből: vásárlók, rendelések (jegyzetekkel, visszatérítésekkel),
// kuponok, termékértékelések, blogbejegyzések, a hiányzó oldalak és a YITH ajándékkártyák – az új boltban:
// WooCommerce → Régi bolt adatai.
//
// Használat: lépj be a RÉGI bolt adminjába, nyisd meg a fejlesztői konzolt (Chrome: Cmd+Option+J /
// Ctrl+Shift+J), másold be ezt a teljes szöveget, Enter (Chrome először kérheti: allow pasting).
// Semmit nem módosít a régi boltban, csak olvas. Pár percig tart (a rendelések jegyzetei rendelésenként
// külön kérés). A letöltött fájlban a vásárlók személyes adatai vannak: az átvétel után töröld a gépedről.
(async () => {
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;z-index:999999;top:40px;right:20px;background:#1d2327;color:#fff;padding:14px 18px;border-radius:8px;font:14px/1.4 system-ui;max-width:380px;box-shadow:0 4px 16px rgba(0,0,0,.3)';
  document.body.appendChild(box);
  const log = (msg) => { box.textContent = msg; console.log('[Mandala adat-export]', msg); };
  try {
    log('Belépés ellenőrzése…');
    const root = (document.querySelector('link[rel="https://api.w.org/"]')?.href || `${location.origin}/wp-json/`).replace(/\/$/, '');
    const ajax = new URL(window.ajaxurl || '/wp-admin/admin-ajax.php', location.origin);
    const adminBase = ajax.href.replace(/admin-ajax\.php.*$/, '');
    ajax.searchParams.set('action', 'rest-nonce');
    const nonce = (await (await fetch(ajax, { credentials: 'same-origin' })).text()).trim();
    if (!/^[a-f0-9]{10}$/.test(nonce)) throw new Error('Nem vagy belépve adminként (vagy a REST API tiltva van). Lépj be a wp-adminba, és ott futtasd.');
    const get = async (path, params = {}, base = 'wc/v3', tries = 4) => {
      const url = new URL(`${root}/${base}/${path}`);
      Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
      for (let attempt = 1; ; attempt++) {
        const r = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
        if (r.ok) return { data: await r.json(), pages: Number(r.headers.get('X-WP-TotalPages') || 1) };
        if (attempt >= tries || r.status === 403 || r.status === 404 || r.status === 400) throw new Error(`${path}: HTTP ${r.status}`);
        await new Promise((res) => setTimeout(res, 1500 * attempt));
      }
    };
    const all = async (path, params = {}, base = 'wc/v3', label = '') => {
      const out = [];
      for (let page = 1, pages = 1; page <= pages; page++) {
        const r = await get(path, { per_page: 100, page, ...params }, base);
        pages = r.pages;
        out.push(...r.data);
        if (label) log(`${label}: ${out.length}…`);
      }
      return out;
    };
    // Párhuzamos lekérés (a rendelések jegyzetei): egyszerre legfeljebb n kérés
    const pool = async (items, n, fn) => {
      let i = 0;
      await Promise.all(Array.from({ length: n }, async () => { while (i < items.length) { const k = i++; await fn(items[k], k); } }));
    };
    const strip = (o) => { delete o._links; return o; };
    const out = { v: 1, kind: 'mandala-old-data', source: location.origin, created: new Date().toISOString(),
      customers: [], orders: [], coupons: [], reviews: [], posts: [], post_categories: [], post_tags: [], pages: [], gift_cards: null, products: {}, product_cats: {}, errors: [] };

    out.customers = (await all('customers', { role: 'all', orderby: 'id', order: 'asc' }, 'wc/v3', 'Vásárlók')).map(strip);

    const orders = await all('orders', { status: 'any', orderby: 'id', order: 'asc' }, 'wc/v3', 'Rendelések');
    let done = 0;
    await pool(orders, 6, async (o) => {
      strip(o);
      try { o.notes = (await get(`orders/${o.id}/notes`, { type: 'any' })).data.map((n) => ({ id: n.id, date_created_gmt: n.date_created_gmt, note: n.note, customer_note: n.customer_note, author: n.author })); }
      catch (e) { o.notes = []; out.errors.push(String(e.message)); }
      if ((o.refunds || []).length) {
        try { o.refund_details = (await get(`orders/${o.id}/refunds`)).data.map((r) => ({ id: r.id, date_created_gmt: r.date_created_gmt, amount: r.amount, reason: r.reason, refunded_payment: r.refunded_payment })); }
        catch (e) { out.errors.push(String(e.message)); }
      }
      if (++done % 25 === 0) log(`Rendelések jegyzetei: ${done} / ${orders.length}…`);
    });
    out.orders = orders;

    log('Kuponok, értékelések…');
    try { out.coupons = (await all('coupons')).map(strip); } catch (e) { out.errors.push(String(e.message)); }
    try { out.reviews = (await all('products/reviews', { status: 'all' })).map(strip); } catch (e) { out.errors.push(String(e.message)); }
    // A kuponok és értékelések termékeire cikkszám + slug (az új boltban más az azonosító)
    const ids = new Set();
    out.coupons.forEach((c) => [...(c.product_ids || []), ...(c.excluded_product_ids || [])].forEach((id) => ids.add(id)));
    out.reviews.forEach((r) => ids.add(r.product_id));
    const idList = [...ids].filter(Boolean);
    for (let i = 0; i < idList.length; i += 100) {
      try {
        const r = await get('products', { include: idList.slice(i, i + 100).join(','), per_page: 100, status: 'any', _fields: 'id,sku,slug,name' });
        r.data.forEach((p) => { out.products[p.id] = { sku: p.sku, slug: p.slug, name: p.name }; });
      } catch (e) { out.errors.push(String(e.message)); }
    }
    try { (await all('products/categories', { _fields: 'id,slug' })).forEach((c) => { out.product_cats[c.id] = c.slug; }); } catch (e) { out.errors.push(String(e.message)); }

    log('Blog és oldalak…');
    try {
      out.post_categories = (await all('categories', { context: 'edit' }, 'wp/v2')).map((c) => ({ id: c.id, name: c.name, slug: c.slug, parent: c.parent, description: c.description }));
      out.post_tags = (await all('tags', { context: 'edit' }, 'wp/v2')).map((c) => ({ id: c.id, name: c.name, slug: c.slug }));
      const media = {};
      const posts = await all('posts', { status: 'publish,draft,future,private', context: 'edit' }, 'wp/v2');
      const mids = [...new Set(posts.map((p) => p.featured_media).filter(Boolean))];
      for (let i = 0; i < mids.length; i += 100) {
        (await get('media', { include: mids.slice(i, i + 100).join(','), per_page: 100, _fields: 'id,source_url,alt_text' }, 'wp/v2')).data.forEach((m) => { media[m.id] = m; });
      }
      out.posts = posts.map((p) => ({ id: p.id, date_gmt: p.date_gmt, modified_gmt: p.modified_gmt, slug: p.slug, status: p.status, link: p.link, sticky: p.sticky,
        title: p.title.raw, content: p.content.raw, excerpt: p.excerpt.raw, categories: p.categories, tags: p.tags,
        featured: media[p.featured_media] ? { url: media[p.featured_media].source_url, alt: media[p.featured_media].alt_text } : null,
        seo: p.yoast_head_json ? { title: p.yoast_head_json.title || '', description: p.yoast_head_json.description || '' } : null }));
      out.pages = (await all('pages', { status: 'publish', _fields: 'id,slug,title,content,link,parent,modified_gmt' }, 'wp/v2'))
        .map((p) => ({ id: p.id, slug: p.slug, title: p.title.rendered, html: p.content.rendered, link: p.link, parent: p.parent }));
    } catch (e) { out.errors.push(String(e.message)); }

    // YITH ajándékkártyák: a REST-ben nincsenek, a WordPress saját exportja (Eszközök → Exportálás) viszont
    // minden adatukat kiadja – ugyanazzal a belépéssel, csak olvasva.
    log('Ajándékkártyák…');
    try {
      const r = await fetch(`${adminBase}export.php?download=true&content=gift_card`, { credentials: 'same-origin' });
      const xml = new DOMParser().parseFromString(await r.text(), 'text/xml');
      const txt = (el, tag) => el.getElementsByTagName(tag)[0]?.textContent ?? '';
      const items = [...xml.getElementsByTagName('item')].filter((it) => txt(it, 'wp:post_type') === 'gift_card');
      out.gift_cards = items.map((it) => ({ id: Number(txt(it, 'wp:post_id')), code: txt(it, 'title'), status: txt(it, 'wp:status'), date_gmt: txt(it, 'wp:post_date_gmt'),
        meta: Object.fromEntries([...it.getElementsByTagName('wp:postmeta')].map((m) => [txt(m, 'wp:meta_key'), txt(m, 'wp:meta_value')])) }));
    } catch (e) { out.errors.push('Ajándékkártyák: ' + e.message); }

    const blob = new Blob([JSON.stringify(out)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'mandala-regi-adatok.json';
    document.body.appendChild(a);
    a.click();
    log(`Kész: ${out.customers.length} felhasználó, ${out.orders.length} rendelés, ${out.coupons.length} kupon, ${out.reviews.length} értékelés, ${out.posts.length} blogbejegyzés, ${out.gift_cards ? out.gift_cards.length : 0} ajándékkártya`
      + (out.errors.length ? ` (${out.errors.length} hiba, lásd konzol)` : '') + '. A fájl letöltődött (mandala-regi-adatok.json) – töltsd fel az új boltban: WooCommerce → Régi bolt adatai.');
    if (out.errors.length) console.warn('[Mandala adat-export] hibák:', out.errors);
    window.__mandalaDataExport = out;
  } catch (e) {
    log('Hiba: ' + e.message);
  }
})();
