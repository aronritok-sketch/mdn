// Feliratkozó ablak első vásárlási kuponnal. Legfeljebb 14 naponta egyszer, késleltetve vagy
// amikor a látogató el akarná hagyni az oldalt (asztali gépen). A sütisáv előtt nem jelenik meg.
const M = window.MANDALA || {};
const W = M.welcome;
const KEY = 'mandala.welcome';
const esc = (s = '') => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const seen = () => { try { return Number(localStorage.getItem(KEY) || 0) > Date.now() - 14 * 864e5; } catch { return true; } };
const mark = () => { try { localStorage.setItem(KEY, String(Date.now())); } catch { /* */ } };
const cookieDecided = () => { try { return localStorage.getItem('mandala.cookie.v1') !== null; } catch { return true; } };

if (W && M.rest && !seen()) {
  let shown = false;
  let last = null;
  const show = () => {
    if (shown || seen() || !cookieDecided() || document.body.classList.contains('is-locked')) return;
    shown = true;
    mark();
    last = document.activeElement;
    document.body.insertAdjacentHTML('beforeend', `
      <div class="welcome-layer" role="presentation">
        <div class="welcome" role="dialog" aria-modal="true" aria-labelledby="welcome-title">
          <button type="button" class="icon-button welcome-close" data-welcome-close aria-label="Bezárás">✕</button>
          <p class="eyebrow">Első rendelésedre</p>
          <h2 id="welcome-title">${W.percent}% kedvezmény</h2>
          <p>Iratkozz fel a hírlevelünkre – ritkán írunk, akkor is az új érkezésekről Nepálból és Indiából.</p>
          <form class="welcome-form" novalidate>
            <label class="screen-reader-text" for="welcome-email">E-mail-cím</label>
            <input type="email" id="welcome-email" name="email" autocomplete="email" placeholder="nev@pelda.hu" required>
            <label class="welcome-accept" for="welcome-accept"><input type="checkbox" id="welcome-accept" name="accept" required> <span>Elfogadom az ${W.privacy ? `<a href="${esc(W.privacy)}">adatkezelési tájékoztatót</a>` : 'adatkezelési tájékoztatót'}, bármikor leiratkozhatok.</span></label>
            <button type="submit" class="iu-button iu-button-block">Kérem a kupont</button>
            <p class="form-message" role="status" aria-live="polite"></p>
          </form>
        </div>
      </div>`);
    const layer = document.querySelector('.welcome-layer');
    const close = () => { layer.remove(); last?.focus?.(); document.removeEventListener('keydown', onKey); };
    const onKey = (e) => {
      if (e.key === 'Escape') close();
      if (e.key === 'Tab') {
        const f = [...layer.querySelectorAll('a[href], button, input')];
        if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
        else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
      }
    };
    document.addEventListener('keydown', onKey);
    layer.addEventListener('click', (e) => { if (e.target === layer || e.target.closest('[data-welcome-close]')) close(); });
    requestAnimationFrame(() => { layer.classList.add('is-open'); layer.querySelector('#welcome-email').focus(); });
    layer.querySelector('form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const form = e.target;
      const msg = form.querySelector('.form-message');
      const email = form.email.value.trim();
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { msg.textContent = 'Ez nem tűnik érvényes e-mail-címnek.'; msg.className = 'form-message is-error'; form.email.focus(); return; }
      if (!form.accept.checked) { msg.textContent = 'A feliratkozáshoz fogadd el az adatkezelési tájékoztatót.'; msg.className = 'form-message is-error'; return; }
      form.querySelector('button[type="submit"]').disabled = true;
      try {
        const res = await fetch(`${M.rest}welcome`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': M.nonce || '' }, body: JSON.stringify({ email, accept: 1 }) });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || '');
        form.innerHTML = `<p class="welcome-done">${esc(data.message)}</p>${data.code ? `<p class="welcome-code"><code>${esc(data.code)}</code> <button type="button" class="iu-button iu-button-outline" data-copy="${esc(data.code)}">Másolás</button></p>` : ''}<button type="button" class="iu-button iu-button-block" data-welcome-close>Vásárlás folytatása</button>`;
        window.dataLayer?.push({ event: 'generate_lead', lead_source: 'welcome_popup' });
      } catch (err) {
        msg.textContent = err.message || 'Most nem sikerült – próbáld újra később.';
        msg.className = 'form-message is-error';
        form.querySelector('button[type="submit"]').disabled = false;
      }
    });
  };
  setTimeout(show, Math.max(0, W.delay) * 1000);
  document.addEventListener('mouseout', (e) => { if (!e.relatedTarget && e.clientY <= 0 && matchMedia('(pointer: fine)').matches) show(); });
  window.mandalaWelcome = show; // tesztekhez
}
