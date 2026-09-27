// Régi bolt termékexportja a böngészőből – ha a WooCommerce saját exportja elakad (időtúllépés).
//
// Használat: lépj be a RÉGI bolt adminjába (pl. https://mandala.hu/wp-admin/), nyisd meg a fejlesztői
// konzolt (Chrome: Cmd+Option+J / Ctrl+Shift+J), másold be ezt a teljes fájlt, Enter. Chrome először kérheti,
// hogy írd be: allow pasting. A szkript 100-as adagokban kéri le a termékeket a bolt saját REST API-jából
// (a belépett admin jogával, jelszó nélkül), a változatokat is, és letölt egy CSV-t, ami az új boltban a
// Termékek → Importálás menüben betölthető (a képeket a régi bolt címéről tölti le az import).
// Semmit nem módosít a régi boltban, csak olvas.
(async () => {
  const log = (msg) => { box.textContent = msg; console.log('[Mandala export]', msg); };
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;z-index:999999;top:40px;right:20px;background:#1d2327;color:#fff;padding:14px 18px;border-radius:8px;font:14px/1.4 system-ui;max-width:360px;box-shadow:0 4px 16px rgba(0,0,0,.3)';
  document.body.appendChild(box);
  try {
    log('Belépés ellenőrzése…');
    const root = (document.querySelector('link[rel="https://api.w.org/"]')?.href || `${location.origin}/wp-json/`).replace(/\/$/, '');
    const ajax = new URL(window.ajaxurl || '/wp-admin/admin-ajax.php', location.origin);
    ajax.searchParams.set('action', 'rest-nonce');
    const nonce = (await (await fetch(ajax, { credentials: 'same-origin' })).text()).trim();
    if (!/^[a-f0-9]{10}$/.test(nonce)) throw new Error('Nem vagy belépve adminként (vagy a REST API tiltva van). Lépj be a wp-adminba, és ott futtasd.');
    const get = async (path, params = {}) => {
      const url = new URL(`${root}/wc/v3/${path}`);
      Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
      for (let attempt = 1; ; attempt++) {
        const r = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
        if (r.ok) return { data: await r.json(), pages: Number(r.headers.get('X-WP-TotalPages') || 1), total: Number(r.headers.get('X-WP-Total') || 0) };
        if (attempt >= 4) throw new Error(`${path}: HTTP ${r.status} ${(await r.text()).slice(0, 200)}`);
        await new Promise((res) => setTimeout(res, 2000 * attempt)); // lassú szerver: újrapróbálás
      }
    };

    // Kategóriák teljes útvonallal (Szülő > Gyerek).
    log('Kategóriák…');
    const cats = new Map();
    for (let page = 1, pages = 1; page <= pages; page++) {
      const r = await get('products/categories', { per_page: 100, page });
      pages = r.pages;
      r.data.forEach((c) => cats.set(c.id, c));
    }
    const catPath = (id) => { const parts = []; for (let c = cats.get(id), n = 0; c && n < 10; c = cats.get(c.parent), n++) parts.unshift(c.name.replace(/,/g, '\\,')); return parts.join(' > '); };

    // Termékek 100-as adagokban (minden állapot: közzétett, piszkozat, privát).
    const products = [];
    let total = 0;
    for (let page = 1, pages = 1; page <= pages; page++) {
      const r = await get('products', { per_page: 100, page, status: 'any', orderby: 'id', order: 'asc' });
      pages = r.pages; total = r.total;
      products.push(...r.data);
      log(`Termékek: ${products.length} / ${total}`);
    }
    // Változatok (változó termékeknél).
    const variable = products.filter((p) => p.type === 'variable');
    const variations = new Map();
    for (let i = 0; i < variable.length; i += 4) {
      await Promise.all(variable.slice(i, i + 4).map(async (p) => {
        const list = [];
        for (let page = 1, pages = 1; page <= pages; page++) {
          const r = await get(`products/${p.id}/variations`, { per_page: 100, page });
          pages = r.pages; list.push(...r.data);
        }
        variations.set(p.id, list);
      }));
      log(`Változatok: ${Math.min(i + 4, variable.length)} / ${variable.length} változó termék`);
    }

    // CSV a WooCommerce importer oszlopneveivel.
    const skuOf = new Map(products.map((p) => [p.id, p.sku || (p.type === 'variable' ? `REGI-${p.id}` : '')]));
    const maxAttr = Math.max(1, ...products.map((p) => p.attributes.length), ...[...variations.values()].flat().map((v) => v.attributes.length));
    const metaKeys = [...new Set(products.flatMap((p) => p.meta_data).map((m) => m.key).filter((k) => /wholesale/i.test(k) && !/^_/.test(k)))];
    const head = ['Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Visibility in catalog', 'Short description', 'Description', 'Date sale price starts', 'Date sale price ends',
      'Tax status', 'Tax class', 'In stock?', 'Stock', 'Backorders allowed?', 'Sold individually?', 'Weight (kg)', 'Length (cm)', 'Width (cm)', 'Height (cm)', 'Allow customer reviews?', 'Purchase note',
      'Sale price', 'Regular price', 'Categories', 'Tags', 'Shipping class', 'Images', 'Parent', 'Upsells', 'Cross-sells', 'Position'];
    for (let i = 1; i <= maxAttr; i++) head.push(`Attribute ${i} name`, `Attribute ${i} value(s)`, `Attribute ${i} visible`, `Attribute ${i} global`);
    metaKeys.forEach((k) => head.push(`Meta: ${k}`));
    const txt = (s) => String(s ?? '').replace(/\r?\n/g, '\\n');
    const cell = (v) => { const s = String(v ?? ''); return /[",\n;]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
    const stock = (p) => (p.stock_status === 'instock' ? 1 : p.stock_status === 'onbackorder' ? 'backorder' : 0);
    const published = (p) => ({ publish: 1, private: -1 }[p.status] ?? 0);
    const rows = [];
    const row = (p, extra) => {
      const r = {
        Type: p.type, SKU: p.sku, Name: p.name, Published: published(p), 'Is featured?': p.featured ? 1 : 0, 'Visibility in catalog': p.catalog_visibility || 'visible',
        'Short description': txt(p.short_description), Description: txt(p.description), 'Date sale price starts': (p.date_on_sale_from || '').slice(0, 10), 'Date sale price ends': (p.date_on_sale_to || '').slice(0, 10),
        'Tax status': p.tax_status, 'Tax class': p.tax_class, 'In stock?': stock(p), Stock: p.manage_stock ? p.stock_quantity ?? '' : '', 'Backorders allowed?': p.backorders === 'yes' ? 1 : p.backorders === 'notify' ? 'notify' : 0,
        'Sold individually?': p.sold_individually ? 1 : 0, 'Weight (kg)': p.weight, 'Length (cm)': p.dimensions?.length, 'Width (cm)': p.dimensions?.width, 'Height (cm)': p.dimensions?.height,
        'Allow customer reviews?': p.reviews_allowed ? 1 : 0, 'Purchase note': txt(p.purchase_note), 'Sale price': p.sale_price, 'Regular price': p.regular_price,
        Categories: (p.categories || []).map((c) => catPath(c.id) || c.name).join(', '), Tags: (p.tags || []).map((t) => t.name.replace(/,/g, '\\,')).join(', '), 'Shipping class': p.shipping_class,
        Images: (p.images || (p.image ? [p.image] : [])).map((i) => i.src).join(', '), Upsells: (p.upsell_ids || []).map((id) => skuOf.get(id)).filter(Boolean).join(','),
        'Cross-sells': (p.cross_sell_ids || []).map((id) => skuOf.get(id)).filter(Boolean).join(','), Position: p.menu_order || 0, ...extra,
      };
      (p.meta_data || []).forEach((m) => { if (metaKeys.includes(m.key)) r[`Meta: ${m.key}`] = m.value; });
      rows.push(r);
    };
    for (const p of products) {
      const attrs = {};
      p.attributes.forEach((a, i) => Object.assign(attrs, { [`Attribute ${i + 1} name`]: a.name, [`Attribute ${i + 1} value(s)`]: a.options.map((o) => String(o).replace(/,/g, '\\,')).join(', '), [`Attribute ${i + 1} visible`]: a.visible ? 1 : 0, [`Attribute ${i + 1} global`]: a.id ? 1 : 0 }));
      row({ ...p, sku: skuOf.get(p.id) }, attrs);
      for (const v of variations.get(p.id) || []) {
        const vattrs = {};
        v.attributes.forEach((a, i) => Object.assign(vattrs, { [`Attribute ${i + 1} name`]: a.name, [`Attribute ${i + 1} value(s)`]: a.option, [`Attribute ${i + 1} global`]: a.id ? 1 : 0 }));
        row({ ...v, type: 'variation', name: `${p.name} – ${v.attributes.map((a) => a.option).join(', ')}`, categories: [], tags: [], images: v.image ? [v.image] : [], status: v.status }, { ...vattrs, Parent: skuOf.get(p.id), Position: v.menu_order || 0 });
      }
    }
    const csv = '﻿' + [head.join(','), ...rows.map((r) => head.map((h) => cell(r[h])).join(','))].join('\n');
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    a.download = `mandala-termekek-${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a); a.click();
    log(`Kész: ${products.length} termék, ${[...variations.values()].flat().length} változat, ${cats.size} kategória. A CSV letöltődött (${a.download}).`);
    window.mandalaExport = { products: products.length, variations: [...variations.values()].flat().length, rows: rows.length, file: a.download };
  } catch (e) {
    log('HIBA: ' + e.message);
    window.mandalaExport = { error: e.message };
  }
})();
