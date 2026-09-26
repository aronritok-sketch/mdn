// Blokk-markup kanonizálása a valódi szerkesztőben (iu-theme skill, „Ne írj kézzel végleges markupot”).
//
// A generátor (tools/build-theme.py) vázlatot ír; a statikus iu blokkok (section, row, column,
// group, button, form…) HTML-jének pontosan egyeznie kell a keretrendszer block.js save()
// kimenetével. Ez a szkript minden sablont és oldaltartalmat:
//   1. piszkozat iu_template bejegyzésbe tesz (WP-CLI),
//   2. megnyit a blokkszerkesztőben (Playwright, admin belépéssel),
//   3. az érvénytelen blokkokat wp.blocks.createBlock(név, attribútumok, belső blokkok)-kal újraépíti,
//   4. kivárja az iu_style számítását, elmenti, és a kanonikus markupot visszaírja a fájlba.
// Második futásra minden blokknak hibátlannak kell lennie ("invalid": []).
//
// Futtatás egy fejlesztői WordPressen, ahol a VALÓDI iu_theme és az iu_* mu-pluginek aktívak:
//   WP="wp --path=/var/www/html" URL=http://localhost:8080 USER=admin PASS=admin node wp-theme/dev/canon.mjs [fájl…]
// Fájl nélkül: wp-theme/mandala/templates/*.html és wp-theme/mandala/setup/content/**/*.html.
// A WP-CLI semleges mappából fut (az iu_theme relatív require_once hibája miatt).
import { execSync } from 'child_process';
import { readFileSync, writeFileSync, readdirSync, statSync, mkdtempSync } from 'fs';
import { join, resolve, dirname } from 'path';
import { tmpdir } from 'os';
import { createRequire } from 'module';
import { fileURLToPath } from 'url';

const require = createRequire(import.meta.url);
const { chromium } = require(process.env.PWPATH || 'playwright');
const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'mandala');
const WP = process.env.WP || 'wp';
const URL = (process.env.URL || 'http://localhost:8080').replace(/\/$/, '');
const neutral = mkdtempSync(join(tmpdir(), 'canon-'));
const wp = (args, input) => execSync(`${WP} ${args}`, { cwd: neutral, input, encoding: 'utf8' }).trim();

const walk = (dir) => readdirSync(dir).flatMap((f) => { const p = join(dir, f); return statSync(p).isDirectory() ? walk(p) : p.endsWith('.html') ? [p] : []; });
const files = process.argv.slice(2).length ? process.argv.slice(2).map((f) => resolve(f)) : [...walk(join(ROOT, 'templates')), ...walk(join(ROOT, 'setup/content'))];

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
await page.goto(`${URL}/wp-login.php`);
await page.fill('#user_login', process.env.USER || 'admin');
await page.fill('#user_pass', process.env.PASS || 'admin');
await page.click('#wp-submit');
await page.waitForURL(/wp-admin/);

let totalInvalid = 0;
for (const file of files) {
  const markup = readFileSync(file, 'utf8');
  // A mentés WP-CLI alól a kses miatt elrontaná a blokk-JSON-t: a tartalom STDIN-ről, a szkript belépett adminnal ment.
  const id = wp(`post create --post_type=iu_template --post_status=draft --post_title="canon ${file.split('/').pop()}" --porcelain -`, markup);
  await page.goto(`${URL}/wp-admin/post.php?post=${id}&action=edit`);
  await page.waitForFunction(() => window.wp?.data?.select('core/block-editor')?.getBlocks().length > 0, null, { timeout: 60000 });
  const invalid = await page.evaluate(async () => {
    const { select, dispatch } = wp.data;
    const bad = [];
    const rebuild = (b) => wp.blocks.createBlock(b.name, { ...b.attributes }, b.innerBlocks.map(rebuild));
    const visit = (blocks) => blocks.forEach((b) => {
      if (!b.isValid) { bad.push(b.name); dispatch('core/block-editor').replaceBlock(b.clientId, rebuild(b)); } else visit(b.innerBlocks);
    });
    // Az iu_template bejegyzés tartalma egy iu/template blokkban lehet (skill: alter_post_content).
    visit(select('core/block-editor').getBlocks());
    await new Promise((r) => setTimeout(r, 1500)); // iu_style számítása
    await dispatch('core/editor').savePost();
    await new Promise((r) => setTimeout(r, 1000));
    return bad;
  });
  const canon = wp(`post get ${id} --field=post_content`);
  const unwrapped = canon.replace(/^<!-- wp:iu\/template -->\n?([\s\S]*?)\n?<!-- \/wp:iu\/template -->$/, '$1');
  writeFileSync(file, `${unwrapped.trim()}\n`);
  wp(`post delete ${id} --force`);
  totalInvalid += invalid.length;
  console.log(`${invalid.length ? '↻' : '✓'} ${file.replace(`${ROOT}/`, '')}  ${JSON.stringify({ invalid })}`);
}
await browser.close();
console.log(totalInvalid ? `\n${totalInvalid} blokk újraépítve – futtasd újra, a második futásnak hibátlannak kell lennie.` : '\nMinden blokk kanonikus ("invalid": []).');
