// Termékoldal: galéria, AJAX kosárba tétel (a minikosár kinyílik), ragadós kosárba sáv.
import { $, $$ } from './env.js';
import { addToCart, openCart, toast } from 'mandala-site';

// Galéria: bélyegképre kattintva csere a fő képben.
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-view]');
  if (!b) return;
  const img = $('[data-gallery-main] img');
  if (img) { img.src = b.dataset.view; img.removeAttribute('srcset'); }
  $$('[data-view]').forEach((x) => x.setAttribute('aria-current', String(x === b)));
});

const form = $('form[data-cart-form]');
async function submitCart(qty) {
  const btn = $('.single_add_to_cart_button', form);
  btn.classList.add('is-loading'); btn.disabled = true;
  const data = await addToCart(form.dataset.productId, qty);
  btn.classList.remove('is-loading'); btn.disabled = false;
  if (data) openCart();
  else toast('Ebből a termékből most nem tudunk többet a kosárba tenni – a készlet korlátozott.');
}
form?.addEventListener('submit', (e) => {
  e.preventDefault();
  const input = $('input[name="quantity"]', form);
  const qty = Math.max(1, Math.min(Number(input.max) || 99, Number(input.value) || 1));
  submitCart(qty);
});
$('[data-add-sticky]')?.addEventListener('click', () => submitCart(1));

// Ragadós kosárba sáv, ha a gomb kigördült a képből.
const sticky = $('[data-sticky-atc]');
if (form && sticky && 'IntersectionObserver' in window) {
  new IntersectionObserver(([en]) => {
    const show = !en.isIntersecting && en.boundingClientRect.top < 0;
    sticky.classList.toggle('is-visible', show);
    sticky.setAttribute('aria-hidden', String(!show));
    $('[data-add-sticky]').tabIndex = show ? 0 : -1;
  }).observe(form);
}
