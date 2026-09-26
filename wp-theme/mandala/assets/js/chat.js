// AI tanácsadó (Claude): lebegő „Kérdezz tőlünk” gomb és beszélgetőpanel. A termékoldalon a
// „Kérdésem van erről a termékről” gomb a termék adataival indítja. A beszélgetés a munkamenet
// végéig megmarad oldalváltáskor is (sessionStorage), a szerver csak az azonosítót kapja vissza.
import { $, esc, icon } from './env.js';

const M = window.MANDALA || {};
const C = M.chat;
const KEY = 'mandala.chat';

if (C && M.rest) {
  const load = () => { try { return JSON.parse(sessionStorage.getItem(KEY)) || null; } catch { return null; } };
  const save = () => { try { sessionStorage.setItem(KEY, JSON.stringify(state)); } catch { /* privát mód */ } };
  let state = load() || { id: '', product: 0, productName: '', messages: [], open: false, rated: false };
  let busy = false;

  document.body.insertAdjacentHTML('beforeend', `
    <button type="button" class="chat-launcher" data-chat-open aria-expanded="false" aria-controls="mandala-chat">${icon('sparkle')}<span>Kérdezz tőlünk</span></button>
    <section class="chat-panel" id="mandala-chat" role="dialog" aria-modal="false" aria-labelledby="mandala-chat-title" hidden>
      <header class="chat-head">
        <div><h2 id="mandala-chat-title">Mandala tanácsadó</h2><p>AI asszisztens · termékek, szállítás, választás</p></div>
        <button type="button" class="icon-button" data-chat-reset aria-label="Új beszélgetés" title="Új beszélgetés">${icon('plus')}</button>
        <button type="button" class="icon-button" data-chat-close aria-label="Bezárás">${icon('close')}</button>
      </header>
      <div class="chat-log" role="log" aria-live="polite" aria-relevant="additions" tabindex="0" aria-label="Beszélgetés"></div>
      <form class="chat-form" novalidate>
        <label class="screen-reader-text" for="mandala-chat-input">Kérdésed</label>
        <textarea id="mandala-chat-input" rows="1" maxlength="800" placeholder="Írd ide a kérdésed…" autocomplete="off"></textarea>
        <button type="submit" class="iu-button chat-send" aria-label="Küldés">${icon('arrow')}</button>
      </form>
      <p class="chat-note">AI válaszol, tévedhet – a termékoldal adatai az irányadók. Ne írj személyes adatot.${M.chat.privacy ? ` <a href="${esc(C.privacy)}">Adatkezelés</a>` : ''}</p>
    </section>`);

  const panel = $('#mandala-chat');
  const launcher = $('.chat-launcher');
  const log = $('.chat-log', panel);
  const form = $('.chat-form', panel);
  const input = $('#mandala-chat-input');
  const host = location.host;

  // ---------- Szövegformázás: biztonságos, szűk Markdown-részhalmaz ----------
  function safeUrl(url) {
    try {
      const u = new URL(url, location.href);
      return (u.protocol === 'https:' || u.protocol === 'http:') && u.host === host ? u.href : '';
    } catch { return ''; }
  }
  function inline(text) {
    let s = esc(text);
    // [szöveg](url) – csak a bolt saját oldalaira; más hivatkozásból sima szöveg lesz.
    s = s.replace(/\[([^\]]+)\]\(([^)\s]+)\)/g, (m, label, url) => {
      const href = safeUrl(url.replace(/&amp;/g, '&'));
      return href ? `<a href="${esc(href)}">${label}</a>` : label;
    });
    s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/(^|[\s(])(mailto:)?([\w.+-]+@[\w-]+\.[\w.]+)/g, (m, pre, _, mail) => `${pre}<a href="mailto:${mail}">${mail}</a>`);
    return s;
  }
  function markdown(text) {
    const out = [];
    let list = null;
    const flush = () => { if (list) { out.push(`<${list.tag}>${list.items.map((i) => `<li>${inline(i)}</li>`).join('')}</${list.tag}>`); list = null; } };
    for (const raw of String(text).split('\n')) {
      const line = raw.trim();
      const ul = line.match(/^[-*•]\s+(.*)$/);
      const ol = line.match(/^\d+[.)]\s+(.*)$/);
      if (ul || ol) {
        const tag = ul ? 'ul' : 'ol';
        if (list && list.tag !== tag) flush();
        list = list || { tag, items: [] };
        list.items.push((ul || ol)[1]);
      } else if (!line) {
        flush();
      } else {
        flush();
        const h = line.match(/^#{1,4}\s+(.*)$/);
        out.push(h ? `<p><strong>${inline(h[1])}</strong></p>` : `<p>${inline(line)}</p>`);
      }
    }
    flush();
    return out.join('');
  }

  // ---------- Megjelenítés ----------
  const cardHtml = (p) => `<a class="chat-card" href="${esc(safeUrl(p.url))}">
      ${p.img ? `<img src="${esc(p.img)}" alt="" width="56" height="56" loading="lazy">` : `<span class="chat-card-ph">${icon('sparkle')}</span>`}
      <span><strong>${esc(p.name)}</strong><span>${esc(p.price)}${p.stock === 'out' ? ' · elfogyott' : p.stock === 'incoming' ? ' · előrendelhető' : ''}</span></span>
      ${icon('chevron-right', 'ico ico-s')}</a>`;
  const handoffHtml = (h) => `<div class="chat-handoff">
      ${h.contact ? `<a class="iu-button iu-button-outline" href="${esc(safeUrl(h.contact))}">${icon('mail', 'ico ico-s')} Írj nekünk</a>` : ''}
      ${h.phone ? `<a class="iu-button iu-button-link" href="tel:${esc(h.phone.replace(/[^\d+]/g, ''))}">${icon('phone', 'ico ico-s')} ${esc(h.phone)}</a>` : ''}</div>`;

  function messageHtml(m, i) {
    if (m.role === 'user') return `<div class="chat-msg chat-msg-user"><p>${esc(m.text)}</p></div>`;
    const last = i === state.messages.length - 1;
    return `<div class="chat-msg chat-msg-bot${m.error ? ' is-error' : ''}">
      ${markdown(m.text)}
      ${m.products?.length ? `<div class="chat-cards">${m.products.map(cardHtml).join('')}</div>` : ''}
      ${m.handoff ? handoffHtml(m.handoff) : ''}
      ${last && state.id && !m.error && !state.rated && state.messages.length > 2 ? `<div class="chat-rate"><span>Hasznos volt?</span>
        <button type="button" class="icon-button" data-chat-rate="1" aria-label="Igen, hasznos volt">👍</button>
        <button type="button" class="icon-button" data-chat-rate="-1" aria-label="Nem volt hasznos">👎</button></div>` : ''}
    </div>`;
  }

  function render() {
    const suggestions = state.product ? C.productSuggestions : C.suggestions;
    log.innerHTML = `<div class="chat-msg chat-msg-bot"><p>${esc(C.greeting)}</p>${state.product && state.productName ? `<p class="chat-context">${icon('info', 'ico ico-s')} Kérdés erről: <strong>${esc(state.productName)}</strong></p>` : ''}</div>
      ${state.messages.map(messageHtml).join('')}
      ${busy ? '<div class="chat-msg chat-msg-bot chat-typing" aria-label="A tanácsadó válaszol"><span></span><span></span><span></span></div>' : ''}
      ${!state.messages.length && !busy ? `<div class="chat-suggest">${suggestions.map((s) => `<button type="button" class="chip" data-chat-suggest>${esc(s)}</button>`).join('')}</div>` : ''}`;
    log.scrollTop = log.scrollHeight;
  }

  // ---------- Nyitás, zárás ----------
  function open(product) {
    if (product && Number(product) !== state.product) {
      // Másik termékről kérdez: új beszélgetés a termék adataival.
      state = { id: '', product: Number(product), productName: $('.product_title, h1')?.textContent.trim() || '', messages: [], open: true, rated: false };
    }
    state.open = true;
    save();
    panel.hidden = false;
    requestAnimationFrame(() => panel.classList.add('is-open'));
    launcher.setAttribute('aria-expanded', 'true');
    launcher.hidden = true;
    render();
    input.focus({ preventScroll: true });
  }
  function close() {
    state.open = false;
    save();
    panel.classList.remove('is-open');
    panel.hidden = true;
    launcher.hidden = false;
    launcher.setAttribute('aria-expanded', 'false');
    launcher.focus();
  }

  // ---------- Küldés ----------
  async function send(text) {
    text = text.trim().slice(0, 800);
    if (!text || busy) return;
    state.messages.push({ role: 'user', text });
    busy = true;
    save();
    render();
    input.value = '';
    autosize();
    try {
      const res = await fetch(`${M.rest}chat`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': M.nonce || '' },
        body: JSON.stringify({ message: text, conversation: state.id, product: state.product || 0, page: location.href }),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok && !data.reply) throw Object.assign(new Error(data.error || ''), { server: true });
      state.id = data.conversation || state.id;
      state.messages.push({ role: 'assistant', text: data.reply, products: data.products || [], handoff: data.handoff || null, error: !!data.error });
      window.dataLayer?.push({ event: 'mandala_chat', chat_products: (data.products || []).length, chat_handoff: !!data.handoff });
    } catch (e) {
      state.messages.push({ role: 'assistant', text: e.server && e.message ? e.message : 'Most nem érem el a tanácsadót. Próbáld újra később, vagy írj nekünk!', handoff: { contact: C.contact }, error: true });
    }
    busy = false;
    save();
    render();
    input.focus();
  }

  function autosize() {
    input.style.height = 'auto';
    input.style.height = `${Math.min(input.scrollHeight, 140)}px`;
  }

  // ---------- Események ----------
  document.addEventListener('click', (e) => {
    const opener = e.target.closest('[data-chat-open]');
    if (opener) { e.preventDefault(); open(opener.dataset.chatProduct); return; }
    if (!panel.contains(e.target)) return;
    if (e.target.closest('[data-chat-close]')) close();
    else if (e.target.closest('[data-chat-reset]')) {
      state = { id: '', product: state.product, productName: state.productName, messages: [], open: true, rated: false };
      save();
      render();
      input.focus();
    } else if (e.target.closest('[data-chat-suggest]')) send(e.target.closest('[data-chat-suggest]').textContent);
    else if (e.target.closest('[data-chat-rate]')) {
      const rating = Number(e.target.closest('[data-chat-rate]').dataset.chatRate);
      state.rated = true;
      save();
      fetch(`${M.rest}chat/rate`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': M.nonce || '' }, body: JSON.stringify({ conversation: state.id, rating }) }).catch(() => {});
      $('.chat-rate', log)?.replaceChildren(document.createTextNode(rating > 0 ? 'Köszönjük!' : 'Köszönjük – írj nekünk, ha segíthetünk másképp.'));
    }
  });
  form.addEventListener('submit', (e) => { e.preventDefault(); send(input.value); });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
  });
  input.addEventListener('input', autosize);
  panel.addEventListener('keydown', (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } });

  if (state.open) open();
}
