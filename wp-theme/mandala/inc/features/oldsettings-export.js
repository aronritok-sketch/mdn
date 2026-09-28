// Régi bolt BEÁLLÍTÁSAINAK exportja a böngészőből (fizetési módok, szállítás, adók, WooCommerce- és
// levélbeállítások, bővítménylista) – az új boltban: WooCommerce → Régi bolt beállításai.
//
// Használat: lépj be a RÉGI bolt adminjába, nyisd meg a fejlesztői konzolt (Chrome: Cmd+Option+J /
// Ctrl+Shift+J), másold be ezt a teljes szöveget, Enter (Chrome először kérheti: allow pasting).
// Semmit nem módosít a régi boltban, csak olvas. A letöltött fájlban a fizetési szolgáltatók kulcsai is
// benne vannak: az átvétel után töröld a gépedről.
(async () => {
  const box = document.createElement('div');
  box.style.cssText = 'position:fixed;z-index:999999;top:40px;right:20px;background:#1d2327;color:#fff;padding:14px 18px;border-radius:8px;font:14px/1.4 system-ui;max-width:380px;box-shadow:0 4px 16px rgba(0,0,0,.3)';
  document.body.appendChild(box);
  const log = (msg) => { box.textContent = msg; console.log('[Mandala beállítás-export]', msg); };
  try {
    log('Belépés ellenőrzése…');
    const root = (document.querySelector('link[rel="https://api.w.org/"]')?.href || `${location.origin}/wp-json/`).replace(/\/$/, '');
    const ajax = new URL(window.ajaxurl || '/wp-admin/admin-ajax.php', location.origin);
    ajax.searchParams.set('action', 'rest-nonce');
    const nonce = (await (await fetch(ajax, { credentials: 'same-origin' })).text()).trim();
    if (!/^[a-f0-9]{10}$/.test(nonce)) throw new Error('Nem vagy belépve adminként (vagy a REST API tiltva van). Lépj be a wp-adminba, és ott futtasd.');
    const get = async (path, params = {}, base = 'wc/v3', tries = 3) => {
      const url = new URL(`${root}/${base}/${path}`);
      Object.entries(params).forEach(([k, v]) => url.searchParams.set(k, v));
      for (let attempt = 1; ; attempt++) {
        const r = await fetch(url, { credentials: 'same-origin', headers: { 'X-WP-Nonce': nonce } });
        if (r.ok) return { data: await r.json(), pages: Number(r.headers.get('X-WP-TotalPages') || 1) };
        if (attempt >= tries || r.status === 403 || r.status === 404) throw new Error(`${path}: HTTP ${r.status}`);
        await new Promise((res) => setTimeout(res, 1200 * attempt));
      }
    };
    const out = { v: 1, source: location.origin, created: new Date().toISOString(), wc: {}, gateways: [], zones: [], taxes: [], tax_classes: [], wp: null, plugins: null, errors: [] };
    const settings = (list) => Object.fromEntries(Object.entries(list || {}).map(([k, s]) => [k, s && typeof s === 'object' && 'value' in s ? s.value : s]));

    log('WooCommerce-beállítások…');
    const groups = (await get('settings')).data.map((g) => g.id).filter((id) => id !== 'integration' && !id.startsWith('checkout'));
    for (const g of groups) {
      try { out.wc[g] = (await get(`settings/${g}`)).data.map((s) => ({ id: s.id, value: s.value, type: s.type, label: s.label })); }
      catch (e) { out.errors.push(String(e.message)); }
    }

    log('Fizetési módok…');
    out.gateways = (await get('payment_gateways')).data.map((g) => ({ id: g.id, title: g.title, description: g.description, enabled: g.enabled, order: g.order, method_title: g.method_title, settings: settings(g.settings) }));

    log('Szállítási zónák…');
    for (const z of (await get('shipping/zones')).data) {
      const locations = z.id === 0 ? [] : (await get(`shipping/zones/${z.id}/locations`)).data.map((l) => ({ code: l.code, type: l.type }));
      const methods = (await get(`shipping/zones/${z.id}/methods`)).data.map((m) => ({ method_id: m.method_id, title: m.title, enabled: m.enabled, order: m.order, settings: settings(m.settings) }));
      out.zones.push({ id: z.id, name: z.name, order: z.order, locations, methods });
    }

    log('Adók…');
    try {
      out.tax_classes = (await get('taxes/classes')).data.map((c) => ({ slug: c.slug, name: c.name }));
      for (let page = 1, pages = 1; page <= pages; page++) {
        const r = await get('taxes', { per_page: 100, page });
        pages = r.pages;
        out.taxes.push(...r.data.map((t) => ({ country: t.country, state: t.state, postcodes: t.postcodes, cities: t.cities, rate: t.rate, name: t.name, priority: t.priority, compound: t.compound, shipping: t.shipping, order: t.order, class: t.class })));
      }
    } catch (e) { out.errors.push(String(e.message)); }

    log('Webhely-beállítások és bővítmények…');
    try { out.wp = (await get('settings', {}, 'wp/v2')).data; } catch (e) { out.errors.push(String(e.message)); }
    try { out.plugins = (await get('plugins', { context: 'edit' }, 'wp/v2')).data.map((p) => ({ plugin: p.plugin, name: p.name, status: p.status, version: p.version })); } catch (e) { out.errors.push(String(e.message)); }

    const blob = new Blob([JSON.stringify(out, null, 1)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'mandala-regi-beallitasok.json';
    document.body.appendChild(a);
    a.click();
    log(`Kész: ${Object.keys(out.wc).length} beállításcsoport, ${out.gateways.length} fizetési mód, ${out.zones.length} szállítási zóna, ${out.taxes.length} adókulcs. A fájl letöltődött (mandala-regi-beallitasok.json) – töltsd fel az új boltban: WooCommerce → Régi bolt beállításai.`);
    window.__mandalaSettingsExport = out;
  } catch (e) {
    log('Hiba: ' + e.message);
  }
})();
