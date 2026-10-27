// Browser tests for the NIMBY map page, against full-size test data.
// Usage: node map.test.js <base-url> <data-dir> [screenshot-dir]
// Serve public/ with the files from make-data.py in public/data first.
const { chromium } = require('playwright');
const zlib = require('zlib');
const fs = require('fs');
const os = require('os');

const BASE = process.argv[2];
const EXPECT = JSON.parse(fs.readFileSync(process.argv[3] + '/expected.json', 'utf8'));
const SHOTS = process.argv[4] || fs.mkdtempSync(os.tmpdir() + '/nimby-shots-');
const fmt = n => n.toLocaleString('en-GB');
let failures = 0, passes = 0;
const check = (ok, msg) => { if (ok) passes++; else { failures++; console.log('  FAIL: ' + msg); } };

// A plain 256px tile so screenshots show something map-like without internet access.
function tilePng() {
  const w = 256, h = 256, raw = Buffer.alloc((w * 3 + 1) * h);
  for (let y = 0; y < h; y++) {
    raw[y * (w * 3 + 1)] = 0;
    for (let x = 0; x < w; x++) {
      const o = y * (w * 3 + 1) + 1 + x * 3;
      const road = (x % 128 < 6) || (y % 96 < 5);
      const c = road ? [255, 255, 255] : [232, 236, 226];
      raw[o] = c[0]; raw[o + 1] = c[1]; raw[o + 2] = c[2];
    }
  }
  const crc = (buf) => { let c, t = []; for (let n = 0; n < 256; n++) { c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; } let r = 0xffffffff; for (const b of buf) r = t[(r ^ b) & 0xff] ^ (r >>> 8); return (r ^ 0xffffffff) >>> 0; };
  const chunk = (type, data) => { const len = Buffer.alloc(4); len.writeUInt32BE(data.length); const td = Buffer.concat([Buffer.from(type), data]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(w, 0); ihdr.writeUInt32BE(h, 4); ihdr[8] = 8; ihdr[9] = 2;
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]);
}
const TILE = tilePng();

async function page(browser, opts = {}) {
  const ctx = await browser.newContext({ viewport: opts.viewport || { width: 1440, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
  const p = await ctx.newPage();
  p.errors = [];
  p.on('pageerror', e => p.errors.push(String(e)));
  p.on('console', m => { if (m.type() === 'error') p.errors.push(m.text()); });
  p.offsite = [];
  await p.route('**/*', route => {
    const url = route.request().url();
    if (url.includes('tile.openstreetmap.org')) return route.fulfill({ body: TILE, contentType: 'image/png' });
    if (!url.startsWith(BASE)) { p.offsite.push(url); return route.abort(); }
    return route.continue();
  });
  return p;
}
const heading = p => p.$eval('#results-heading', e => e.textContent.trim());
const ready = p => p.waitForFunction(() => !/Loading/.test(document.getElementById('fresh').textContent) && document.querySelectorAll('.item').length > 0);

(async () => {
  const browser = await chromium.launch();

  console.log('- Desktop load and default view');
  let p = await page(browser);
  await p.goto(BASE + '/');
  await ready(p);
  await p.waitForTimeout(600);
  check(new RegExp('Council data checked ' + EXPECT.synced).test(await p.$eval('#fresh', e => e.textContent)), 'freshness line from meta.json (' + await p.$eval('#fresh', e => e.textContent) + ')');
  const h1 = await heading(p);
  check(h1 === fmt(EXPECT.last12) + ' applications', 'default view = last 12 months, all in view (' + h1 + ', expected ' + EXPECT.last12 + ')');
  check(await p.$$eval('.leaflet-marker-icon', els => els.length) > 0, 'markers or clusters drawn');
  check(await p.$('.cluster.has-pending') !== null || await p.$('.pin.cat-pending') !== null, 'pending items glow');
  check((await p.$eval('[data-count="pending"]', e => e.textContent)) !== '', 'status chips show counts');
  check(p.offsite.length === 0, 'no requests to other servers apart from map tiles: ' + p.offsite.join(', '));
  check(await p.$eval('.item-address', e => e.textContent.includes('Nottingham')) === false, 'list addresses tidied');
  await p.screenshot({ path: SHOTS + '/desktop.png' });

  console.log('- List click opens the popup and updates the address bar');
  const firstSlug = await p.$eval('.item', e => e.dataset.slug);
  await p.click('.item');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 8000 });
  check(new URL(p.url()).searchParams.get('app') === firstSlug, 'URL has ?app=' + firstSlug + ' (got ' + p.url() + ')');
  check(await p.$eval('.leaflet-popup .btn-council', e => e.getAttribute('href').startsWith('https://publicaccess.nottinghamcity.gov.uk/')), 'council link in popup');
  check(await p.$eval('.item[aria-current="true"]', e => e.dataset.slug) === firstSlug, 'list item marked current');
  await p.screenshot({ path: SHOTS + '/desktop-popup.png' });

  console.log('- Copy link');
  await p.click('.leaflet-popup .btn-copy');
  await p.waitForTimeout(200);
  check(await p.$eval('.leaflet-popup .btn-copy', e => e.textContent) === 'Link copied', 'button confirms copy');
  const clip = await p.evaluate(() => navigator.clipboard.readText());
  check(clip === BASE + '/?app=' + firstSlug, 'clipboard has share link (' + clip + ')');

  console.log('- Closing the popup clears the address bar');
  await p.click('.leaflet-popup-close-button');
  await p.waitForTimeout(200);
  check(!new URL(p.url()).searchParams.has('app'), 'URL cleared after close');

  console.log('- Filters');
  await p.click('.leaflet-control-zoom-out'); await p.click('.leaflet-control-zoom-out'); await p.waitForTimeout(500);
  await p.selectOption('#when', 'all');
  await p.waitForTimeout(400);
  check((await heading(p)).includes(fmt(EXPECT.total)), 'all time counts every application (' + await heading(p) + ')');
  check(/Show 100 more/.test(await p.$eval('#more', e => e.textContent)), 'long list is paged');
  await p.uncheck('#trees');
  await p.waitForTimeout(300);
  const noTrees = await heading(p);
  check(!noTrees.includes(fmt(EXPECT.total)), 'hiding tree works reduces the count (' + noTrees + ')');
  await p.check('#trees');
  await p.click('input[value="granted"]');
  await p.waitForTimeout(300);
  const noGranted = await heading(p);
  check(!noGranted.includes(fmt(EXPECT.total)) && await p.$eval('input[value="granted"]', e => !e.checked), 'unticking Granted reduces the count (' + noGranted + ')');
  await p.click('input[value="granted"]');
  await p.fill('#q', 'clumber road east cherry');
  await p.waitForTimeout(500);
  check(/^1 application$/.test(await heading(p)), 'search with several words (' + await heading(p) + ')');
  check(await p.$eval('#more', e => getComputedStyle(e).display) === 'none', '"Show more" is hidden when there is nothing more');
  await p.fill('#q', 'zzzz-nothing');
  await p.waitForTimeout(500);
  check(/Nothing matches/.test(await p.$eval('#list', e => e.textContent)), 'empty state explains what to do');
  await p.fill('#q', '');
  check(p.errors.length === 0, 'no console errors: ' + p.errors.join(' | '));
  await p.context().close();

  console.log('- Deep link to an old application outside the default filter');
  p = await page(browser);
  await p.goto(BASE + '/?app=99-00123-PFUL3');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 10000 });
  check(/99\/00123\/PFUL3/.test(await p.$eval('.leaflet-popup .card-type', e => e.textContent)), 'linked application opened');
  check(/continues in the case file/.test(await p.$eval('.leaflet-popup', e => e.textContent)), 'truncated proposal flagged');
  check(await p.$eval('#notice', e => e.hidden), 'no error notice');
  check(new URL(p.url()).searchParams.get('app') === '99-00123-PFUL3', 'URL kept');
  await p.context().close();

  console.log('- Deep link to the house with 12 applications (spiderfy)');
  p = await page(browser);
  await p.goto(BASE + '/?app=26-01848-PWC');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 10000 });
  check(/New this week/.test(await p.$eval('.leaflet-popup', e => e.textContent)), 'new application badged');
  check(/1 Clumber Road East, NG7 1BD/.test(await p.$eval('.card-address', e => e.textContent)), 'popup address tidied');
  await p.screenshot({ path: SHOTS + '/deeplink-stacked.png' });
  await p.context().close();

  console.log('- Unknown deep link');
  p = await page(browser);
  await p.goto(BASE + '/?app=12-34567-XYZ');
  await ready(p);
  await p.waitForTimeout(300);
  check(!(await p.$eval('#notice', e => e.hidden)) && /isn’t in the data/.test(await p.$eval('#notice', e => e.textContent)), 'notice shown for unknown reference');
  check(!new URL(p.url()).searchParams.has('app'), 'bad reference removed from URL');
  await p.context().close();

  console.log('- Hostile text is shown as text');
  p = await page(browser);
  await p.goto(BASE + '/?app=26-01900-PFUL3');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 10000 });
  await p.waitForTimeout(300);
  check(await p.evaluate(() => window.__xss === undefined), 'no script ran from council text');
  check(/<img src=x/.test(await p.$eval('.card-proposal', e => e.textContent)), 'markup shown literally');
  check(await p.$('.card-proposal img') === null, 'no element created from council text');
  await p.context().close();

  console.log('- Map hidden while the page loads, then shown (background tab, phone rotation)');
  p = await page(browser);
  // Hidden by the stylesheet itself, so the map is hidden before any page script runs.
  await p.route(BASE + '/assets/app.css', async route => {
    const response = await route.fetch();
    route.fulfill({ response, body: (await response.text()) + '\n.map-wrap { display: none; }' });
  });
  await p.goto(BASE + '/');
  await p.waitForFunction(() => /Council data checked/.test(document.getElementById('fresh').textContent));
  await p.waitForTimeout(300);
  check(await p.$eval('.map-wrap', e => getComputedStyle(e).display) === 'none', 'map really was hidden during load');
  await p.addStyleTag({ content: '.map-wrap { display: block !important; }' });
  await p.waitForTimeout(1200);
  const zooms = await p.$$eval('.leaflet-tile', ts => [...new Set(ts.map(t => Number((t.src.match(/\/(\d+)\/\d+\/\d+\.png/) || [])[1])))]);
  check(zooms.length > 0 && Math.max(...zooms) <= 17, 'map fits The Park once it becomes visible (tile zoom ' + zooms.join(',') + ')');
  check(!/^0 of/.test(await heading(p)), 'list shows applications after the map appears (' + await heading(p) + ')');
  await p.context().close();

  console.log('- Keyboard: Tab reaches a pin and Enter opens it');
  p = await page(browser);
  await p.goto(BASE + '/');
  await ready(p);
  await p.focus('.leaflet-marker-icon.pin');
  await p.keyboard.press('Enter');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 5000 }).then(() => check(true, ''), () => check(false, 'Enter on a pin opens its popup'));
  await p.context().close();

  console.log('- Phone layout');
  p = await page(browser, { viewport: { width: 390, height: 844 } });
  await p.goto(BASE + '/');
  await ready(p);
  await p.waitForTimeout(500);
  const sw = await p.evaluate(() => document.documentElement.scrollWidth);
  check(sw <= 390, 'no sideways scrolling on a phone (width ' + sw + ')');
  await p.screenshot({ path: SHOTS + '/phone.png', fullPage: false });
  await p.evaluate(() => window.scrollTo(0, document.querySelector('.panel').offsetTop - 10));
  await p.waitForTimeout(200);
  await p.screenshot({ path: SHOTS + '/phone-list.png' });
  await p.click('.item');
  await p.waitForSelector('.leaflet-popup .card', { timeout: 8000 });
  await p.waitForTimeout(700);
  const mapTop = await p.$eval('.map-wrap', e => e.getBoundingClientRect().top);
  check(Math.abs(mapTop) < 40, 'tapping a list item scrolls back up to the map (map top at ' + Math.round(mapTop) + ')');
  await p.screenshot({ path: SHOTS + '/phone-popup.png' });
  check(p.errors.length === 0, 'no console errors on phone: ' + p.errors.join(' | '));
  await p.context().close();

  await browser.close();
  console.log(`\n${passes} checks passed, ${failures} failed.`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
