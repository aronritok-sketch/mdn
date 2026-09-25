import { initPage, $, refreshReveal } from '../app.js';
import { postCard } from '../blocks.js';
import { ARTICLES } from '../data.js';

initPage({ active: 'mag' });
$('[data-posts]').innerHTML = ARTICLES.map(postCard).join('');
refreshReveal();
