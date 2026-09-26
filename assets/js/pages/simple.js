// Egyszerű tartalmi oldalak (Eredetünk, 404): csak a közös elemek.
import { initPage } from '../ui.js';

initPage({ active: document.body.dataset.active || '' });
