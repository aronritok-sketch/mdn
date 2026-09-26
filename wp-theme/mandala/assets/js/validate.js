// Mezőnkénti azonnali validáció (a prototípus ui.js validációja): data-validate =
// soronként „szabály|hibaüzenet”, mint az iu/form validate attribútuma.
import { $, $$, esc, icon } from './env.js';

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
/** Magyar adószám: 8-1-2 számjegy, a törzsszám 8. jegye ellenőrző (9,7,3,1,9,7,3 súlyok). */
export function validTaxNumber(v) {
  const m = String(v).replace(/\s/g, '').match(/^(\d{8})-?([1-5])-?(\d{2})$/);
  if (!m) return false;
  const d = m[1].split('').map(Number);
  const sum = [9, 7, 3, 1, 9, 7, 3].reduce((s, w, i) => s + w * d[i], 0);
  return (10 - (sum % 10)) % 10 === d[7];
}
export const normPhone = (v) => {
  const digits = String(v).replace(/[^\d+]/g, '');
  if (/^\+36\d{8,9}$/.test(digits)) return digits;
  if (/^06\d{8,9}$/.test(digits)) return `+36${digits.slice(2)}`;
  return null;
};
const RULES = {
  required: (v, el) => (el.type === 'checkbox' ? el.checked : v.trim() !== ''),
  email: (v) => !v || EMAIL_RE.test(v.trim()),
  phone: (v) => !v || normPhone(v) !== null,
  zip: (v) => !v || /^[1-9]\d{3}$/.test(v.trim()),
  taxno: (v) => !v || validTaxNumber(v),
  min8: (v) => !v || v.length >= 8,
};
const TYPO_DOMAINS = { 'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gmail.hu': 'gmail.com', 'gamil.com': 'gmail.com', 'gmail.co': 'gmail.com', 'freemal.hu': 'freemail.hu', 'fremail.hu': 'freemail.hu', 'citromal.hu': 'citromail.hu', 'hotmial.com': 'hotmail.com', 'yahho.com': 'yahoo.com', 'outlok.com': 'outlook.com' };

export function fieldError(el) {
  for (const line of (el.dataset.validate || '').split('\n').map((l) => l.trim()).filter(Boolean)) {
    const [rule, msg] = line.split('|');
    const fn = RULES[rule];
    if (fn && !fn(el.value || '', el)) return msg || 'Hibás érték.';
  }
  return '';
}

export function showFieldState(el, msg) {
  const wrap = el.closest('.form-row, .iu-form-field, .iu-form-accept, .check-row') || el.parentElement;
  const errId = `${el.id || el.name}-error`;
  let err = document.getElementById(errId);
  if (msg) {
    if (!err) {
      err = document.createElement('p');
      err.className = 'field-error';
      err.id = errId;
      (el.closest('.woocommerce-input-wrapper') || wrap).append(err);
    }
    err.innerHTML = `${icon('alert', 'ico ico-s')}<span>${esc(msg)}</span>`;
    el.setAttribute('aria-invalid', 'true');
    el.setAttribute('aria-describedby', [errId, el.dataset.hint].filter(Boolean).join(' '));
    wrap?.classList.add('woocommerce-invalid', 'is-invalid');
    wrap?.classList.remove('woocommerce-validated');
  } else {
    err?.remove();
    el.removeAttribute('aria-invalid');
    if (el.dataset.hint) el.setAttribute('aria-describedby', el.dataset.hint); else el.removeAttribute('aria-describedby');
    wrap?.classList.remove('woocommerce-invalid', 'is-invalid');
    if (el.value && el.type !== 'checkbox') wrap?.classList.add('woocommerce-validated');
  }
}

function suggestEmail(el) {
  const m = el.value.trim().match(/^([^@\s]+)@([^@\s]+)$/);
  let box = el.parentElement.querySelector('.field-suggest');
  const fix = m && TYPO_DOMAINS[m[2].toLowerCase()];
  if (!fix) { box?.remove(); return; }
  if (!box) { box = document.createElement('p'); box.className = 'field-suggest'; el.after(box); }
  const full = `${m[1]}@${fix}`;
  box.innerHTML = `Erre gondoltál: <button type="button" class="iu-button iu-button-link">${esc(full)}</button>?`;
  box.querySelector('button').onclick = () => { el.value = full; el.dispatchEvent(new Event('input', { bubbles: true })); box.remove(); showFieldState(el, fieldError(el)); el.focus(); };
}

function format(el) {
  if (el.dataset.format === 'phone') {
    const n = normPhone(el.value);
    if (n) el.value = n.replace(/^\+36(\d)(\d)(\d{3})(\d{3,4})$/, (_, a, b, c, d) => (a === '1' ? `+36 1 ${b}${c.slice(0, 2)} ${c.slice(2)}${d}` : `+36 ${a}${b} ${c} ${d}`));
  } else if (el.dataset.format === 'taxno') {
    const d = el.value.replace(/\D/g, '');
    if (d.length === 11) el.value = `${d.slice(0, 8)}-${d[8]}-${d.slice(9)}`;
  } else return;
  el.dispatchEvent(new Event('input', { bubbles: true }));
}

/** Kilépéskor ellenőriz, hibás mezőnél gépelés közben is frissít. */
export function bindValidation(form) {
  form.addEventListener('focusout', (e) => {
    const el = e.target;
    if (!el.matches?.('[data-validate]')) return;
    format(el);
    if (el.type === 'email') suggestEmail(el);
    if (el.value || el.getAttribute('aria-invalid')) showFieldState(el, fieldError(el));
  });
  form.addEventListener('input', (e) => { const el = e.target; if (el.matches?.('[data-validate][aria-invalid]')) showFieldState(el, fieldError(el)); });
  form.addEventListener('change', (e) => { const el = e.target; if (el.matches?.('input[type="checkbox"][data-validate]')) showFieldState(el, fieldError(el)); });
}

/** A látható mezők hibái sorrendben ({ el, label, msg }). */
export function validateForm(root) {
  const errors = [];
  $$('[data-validate]', root).forEach((el) => {
    if (el.closest('[hidden]') || el.disabled || el.offsetParent === null) { showFieldState(el, ''); return; }
    const msg = fieldError(el);
    showFieldState(el, msg);
    if (msg) {
      const label = el.dataset.label || $(`label[for="${el.id}"]`)?.textContent.replace(/\*|\(nem kötelező\)/g, '').trim() || el.name;
      errors.push({ el, label, msg });
    }
  });
  return errors;
}
