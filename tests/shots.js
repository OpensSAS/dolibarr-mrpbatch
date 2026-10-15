const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const browser = await chromium.launch();
  const out = path.join(__dirname, 'shots');
  require('fs').mkdirSync(out, { recursive: true });
  const errors = [];
  async function page(file, w, h) {
    const ctx = await browser.newContext({ viewport: { width: w, height: h }, deviceScaleFactor: 1, hasTouch: w < 1300 });
    const p = await ctx.newPage();
    p.on('pageerror', e => errors.push(file + ' ' + w + ': ' + e.message));
    p.on('console', m => { if (m.type() === 'error') errors.push(file + ' console: ' + m.text()); });
    await p.goto('file://' + path.join(__dirname, file));
    await p.waitForTimeout(300);
    return p;
  }
  async function shot(p, name, full) { await p.waitForTimeout(250); await p.screenshot({ path: path.join(out, name + '.png'), fullPage: !!full }); }
  async function hash(p, hsh) { await p.evaluate(h => { location.hash = h; }, hsh); await p.waitForTimeout(350); }

  // Tablette portrait
  let p = await page('tablet.html', 820, 1180);
  await shot(p, 't1-prod-home');
  await hash(p, '#/production/mo/11'); await shot(p, 't2-mo');
  await p.keyboard.type('3760123450028', { delay: 4 }); await p.keyboard.press('Enter'); await p.waitForTimeout(300);
  await shot(p, 't3-consume-panel');
  await p.keyboard.press('Escape');
  await p.click('#pTabs button[data-t=produce]'); await p.click('.row[data-line="110"]'); await shot(p, 't4-produce-panel');
  await p.click('#pOk'); await p.waitForTimeout(200);
  await hash(p, '#/planning'); await shot(p, 't5-planning');
  await hash(p, '#/reception'); await shot(p, 't6-reception', true);
  await hash(p, '#/reception/order/31'); await p.click('.row[data-line="301"]'); await shot(p, 't7-receive-panel');
  await p.click('[data-conf="0"]'); await shot(p, 't7b-nonconform');
  await hash(p, '#/orders'); await shot(p, 't8-orders');
  await hash(p, '#/orders/order/43'); await shot(p, 't9-order');
  await p.click('.row[data-line="401"]'); await shot(p, 't10-pick');
  await p.keyboard.press('Escape');
  await p.click('#odStepper button[data-s=packed]'); await shot(p, 't11-pack-modal');
  console.log('calls', JSON.stringify(await p.evaluate(() => window.__calls.filter(c => c.indexOf('POST') > 0))));
  await p.close();

  // Tablette paysage
  p = await page('tablet.html', 1366, 1024);
  await hash(p, '#/production/mo/11'); await p.click('.row[data-line="102"]'); await shot(p, 'l1-mo-panel');
  await hash(p, '#/planning'); await shot(p, 'l2-planning');
  await p.click('.g-bar[data-mo="11"]'); await shot(p, 'l3-planning-details');
  await p.click('#mResched'); await shot(p, 'l4-reschedule');
  await p.keyboard.press('Escape');
  await hash(p, '#/orders'); await shot(p, 'l5-orders');
  await hash(p, '#/production');
  await p.click('#mbxWs'); await shot(p, 'l6-ws-sheet'); await p.click('[data-ws="1"]'); await p.waitForTimeout(300);
  await shot(p, 'l7-prod-ws');
  await p.close();

  // Téléphone
  p = await page('tablet.html', 400, 860);
  await shot(p, 'p1-prod-home');
  await hash(p, '#/planning'); await shot(p, 'p2-agenda');
  await hash(p, '#/orders'); await shot(p, 'p3-orders');
  await hash(p, '#/orders/order/43'); await p.click('.row[data-line="401"]'); await shot(p, 'p4-pick');
  await p.close();

  // Web (Dolibarr)
  p = await page('desk.html', 1440, 900);
  await shot(p, 'd1-prod-home');
  await hash(p, '#/production/mo/11'); await p.click('.row[data-line="102"]'); await shot(p, 'd2-mo-panel');
  await hash(p, '#/planning'); await shot(p, 'd3-planning');
  await p.click('#plIcs'); await shot(p, 'd3b-ics'); await p.keyboard.press('Escape');
  await hash(p, '#/reception'); await shot(p, 'd4-reception');
  await hash(p, '#/reception/free'); await p.fill('#rcQ', 'porc'); await p.waitForTimeout(500); await shot(p, 'd5-free');
  await hash(p, '#/orders'); await shot(p, 'd6-orders');
  await p.close();
  p = await page('desk.html', 1100, 800);
  await hash(p, '#/orders/order/43'); await shot(p, 'd7-order-1100');
  await p.close();

  await browser.close();
  console.log('ERRORS', JSON.stringify(errors, null, 1));
})();
