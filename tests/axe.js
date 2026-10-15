const { chromium } = require('playwright');
const path = require('path'); const fs = require('fs');
const AXE = fs.readFileSync(path.join(__dirname, 'node_modules/axe-core/axe.min.js'), 'utf8');
(async () => {
  const b = await chromium.launch(); const agg = {};
  async function run(file, w, h, hash, theme, action) {
    const p = await (await b.newContext({ viewport: { width: w, height: h } })).newPage();
    await p.goto('file://' + path.join(__dirname, file)); await p.waitForTimeout(300);
    if (theme) await p.evaluate(t => document.getElementById('mbx').setAttribute('data-theme', t), theme);
    if (hash) { await p.evaluate(x => location.hash = x, hash); await p.waitForTimeout(400); }
    if (action) { await action(p); await p.waitForTimeout(400); }
    await p.waitForTimeout(1200);
    await p.addScriptTag({ content: AXE });
    const r = await p.evaluate(async () => (await axe.run(document.getElementById('mbx'), { runOnly: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] })).violations.map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length, ex: v.nodes.slice(0, 12).map(n => n.target.join(' ') + ' :: ' + (n.any[0] || n.all[0] || n.none[0] || {}).message) })));
    for (const v of r) { const k = v.id; agg[k] = agg[k] || { impact: v.impact, where: [], ex: [] }; v.ex.forEach(e => { const s = e.replace(/\(foreground[^)]*\)/, m => m.slice(0, 70)); if (agg[k].ex.indexOf(s) < 0) agg[k].ex.push(s); }); agg[k].where.push(`${file}${hash || ''}${theme ? '[' + theme + ']' : ''}:${v.n}`); }
    await p.close();
  }
  for (const th of [null, 'dark']) {
    for (const hh of ['#/production', '#/production/mo/11', '#/planning', '#/reception', '#/orders', '#/orders/order/43']) await run('tablet.html', 820, 1180, hh, th);
    await run('tablet.html', 820, 1180, '#/production/mo/11', th, p => p.click('.row[data-line="102"]'));
    await run('tablet.html', 820, 1180, '#/reception/order/31', th, p => p.click('.row[data-line="301"]'));
  }
  await run('desk.html', 1440, 900, '#/orders', null);
  console.log(JSON.stringify(agg, null, 1));
  await b.close();
})();
