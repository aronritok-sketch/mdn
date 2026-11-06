const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
const dir = __dirname;
(async () => {
  const jobs = JSON.parse(fs.readFileSync(path.join(dir, process.argv[2] || 'jobs.json'), 'utf8'));
  const b = await chromium.launch();
  for (const j of jobs) {
    const p = await b.newPage({ viewport: { width: j.w, height: j.h }, deviceScaleFactor: j.scale || 1 });
    await p.goto('file://' + path.join(dir, j.html), { waitUntil: 'load' });
    await p.evaluate(() => document.fonts.ready);
    await p.waitForTimeout(150);
    if (j.pdf) await p.pdf({ path: path.join(dir, j.pdf), preferCSSPageSize: true, printBackground: true });
    else await p.screenshot({ path: path.join(dir, j.png), type: j.png.endsWith('.jpg') ? 'jpeg' : 'png', quality: j.png.endsWith('.jpg') ? 90 : undefined });
    await p.close(); console.log('ok', j.png || j.pdf);
  }
  await b.close();
})();
