// End-to-end: sign up, confirm, receive the digest, unsubscribe, as a resident would.
// Usage: bash tests/browser/email-setup.sh <dir>; php -S 127.0.0.1:8130 -t <dir>/public &
//        node tests/browser/email.test.js http://127.0.0.1:8130 <dir> [screenshot-dir]
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');

const [BASE, DIR] = process.argv.slice(2);
const SHOTS = process.argv[4] || fs.mkdtempSync(os.tmpdir() + '/nimby-email-');
let passes = 0, failures = 0;
const check = (ok, msg) => { if (ok) passes++; else { failures++; console.log('  FAIL: ' + msg); } };
const php = (...args) => execFileSync('php', args, { cwd: DIR, encoding: 'utf8' });
const mails = () => fs.existsSync(DIR + '/data/cache/mail.log')
  ? fs.readFileSync(DIR + '/data/cache/mail.log', 'utf8').trim().split('\n').filter(Boolean).map(l => JSON.parse(l)) : [];

(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(String(e)));
  await p.route('**/tile.openstreetmap.org/**', r => r.fulfill({ status: 204 }));

  console.log('- Sign-up closed');
  php('bin/signup.php', 'close');
  await p.goto(BASE + '/');
  await p.waitForTimeout(800);
  check(await p.$eval('#signup', e => e.hidden), 'form hidden while closed');
  const closed = await (await p.request.post(BASE + '/subscribe.php', { form: { email: 'x@example.com', consent: 'yes' }, headers: { Accept: 'application/json' } })).json();
  check(closed.code === 'closed', 'server refuses while closed even if the form is forced');

  console.log('- Sign-up open');
  php('bin/signup.php', 'open');
  await p.reload();
  await p.waitForSelector('#signup:not([hidden])', { timeout: 5000 });
  check(await p.$eval('#masthead-signup', e => !e.hidden), 'masthead link shown');
  await p.fill('#su-email', 'not an email');
  await p.click('.signup-button');
  check(/valid email/.test(await p.textContent('#signup-result')), 'bad address caught in the page');
  await p.fill('#su-email', 'Resident@Example.org');
  await p.click('.signup-button');
  check(/Tick the box/.test(await p.textContent('#signup-result')), 'consent required');
  await p.fill('#su-name', 'Pat Resident');
  await p.check('input[name="consent"]');
  await p.locator('#signup').scrollIntoViewIfNeeded();
  await p.screenshot({ path: SHOTS + '/signup-form.png', clip: await (await p.$('#signup')).boundingBox() });
  await p.click('.signup-button');
  await p.waitForFunction(() => /Check your inbox/.test(document.getElementById('signup-result').textContent));
  check(await p.$eval('.signup-button', e => e.hidden), 'form fields hidden after success');
  await p.screenshot({ path: SHOTS + '/signup-done.png', clip: await (await p.$('#signup')).boundingBox() });

  const confirmMail = mails().filter(m => m.subject === 'Confirm your NIMBY weekly email').pop();
  check(confirmMail && confirmMail.to === 'resident@example.org', 'confirmation email sent to the lower-cased address');
  const confirmUrl = confirmMail.text.match(/(http\S+confirm\.php\?token=[a-f0-9]+)/)[1];
  fs.writeFileSync(SHOTS + '/confirm-email.html', confirmMail.html);

  console.log('- Confirm');
  await p.goto(confirmUrl);
  check(/Confirm your email address/.test(await p.textContent('h1')), 'link opens a confirm page, not an instant confirmation');
  check(/0 active, 1 awaiting/.test(php('bin/signup.php', 'status')), 'still pending until the button is pressed');
  await p.screenshot({ path: SHOTS + '/confirm-page.png' });
  await p.click('.page-button');
  check(/You’re subscribed/.test(await p.textContent('h1')), 'subscribed');
  check(/1 active, 0 awaiting/.test(php('bin/signup.php', 'status')), 'now active');
  await p.goto(confirmUrl);
  await p.click('.page-button');
  check(/doesn’t work/.test(await p.textContent('h1')), 'link works only once');

  console.log('- Without JavaScript the form still works');
  const plain = await p.request.post(BASE + '/subscribe.php', { form: { email: 'second@example.org', consent: 'yes' } });
  check(plain.status() === 200 && /Check your inbox/.test(await plain.text()), 'plain form post gets a page');
  const second = mails().filter(m => m.to === 'second@example.org').pop();
  await p.goto(second.text.match(/(http\S+confirm\.php\?token=[a-f0-9]+)/)[1]);
  await p.click('.page-button');

  console.log('- Monday digest');
  fs.writeFileSync(DIR + '/data/cache/mail.log', '');
  const out = php('bin/send-digest.php');
  check(/5 new applications; 2 subscribers/.test(out) && /Sent 2/.test(out), 'digest sent to both (' + out.trim().replace(/\n/g, ' | ') + ')');
  check(!/resident@example\.org|second@example\.org/.test(out), 'no addresses in the output');
  const digest = mails().find(m => m.to === 'resident@example.org');
  check(digest && /^NIMBY weekly: 5 new planning applications in The Park$/.test(digest.subject), 'subject (' + (digest && digest.subject) + ')');
  check(/Hello Pat Resident/.test(digest.html), 'greeting uses the name given');
  fs.writeFileSync(SHOTS + '/digest-email.html', digest.html);
  const again = php('bin/send-digest.php');
  check(/No new applications/.test(again) && mails().length === 2, 'running it again sends nothing more');

  console.log('- Map link from the email');
  const mapLink = digest.html.match(/href="(http[^"]+\?app=[^"]+)"/)[1].replace(/&amp;/g, '&');
  await p.goto(mapLink);
  await p.waitForSelector('.leaflet-popup .card', { timeout: 10000 });
  check(true, '');

  console.log('- Unsubscribe from the email link');
  const unsub = digest.headers['List-Unsubscribe'].slice(1, -1);
  await p.goto(unsub);
  check(/resident@example\.org/.test(await p.textContent('main')), 'page names the address');
  check(/1|2 active/.test(php('bin/signup.php', 'status')) && /2 active/.test(php('bin/signup.php', 'status')), 'opening the link alone does not unsubscribe');
  await p.click('.page-button');
  check(/You’ve unsubscribed/.test(await p.textContent('h1')), 'unsubscribed after pressing the button');
  check(/1 active, 0 awaiting confirmation, 1 unsubscribed/.test(php('bin/signup.php', 'status')), 'counts updated');

  console.log('- One-click unsubscribe from an email program');
  const second2 = mails().find(m => m.to === 'second@example.org');
  const oneClick = await p.request.post(second2.headers['List-Unsubscribe'].slice(1, -1), { form: { 'List-Unsubscribe': 'One-Click' } });
  check(oneClick.status() === 200 && /Unsubscribed/.test(await oneClick.text()), 'RFC 8058 one-click POST works');
  check(/0 active, 0 awaiting confirmation, 2 unsubscribed/.test(php('bin/signup.php', 'status')), 'both now unsubscribed');
  const forged = await p.request.post(BASE + '/unsubscribe.php?s=1&t=00000000000000000000000000000000', { form: { 'List-Unsubscribe': 'One-Click' } });
  check(forged.status() === 404, 'forged link refused');

  console.log('- Privacy notice');
  await p.goto(BASE + '/privacy.php');
  check(/Nottingham Park Residents' Association \(NPRA\)/.test(await p.textContent('main')), 'names the organisation');
  check(/deleted after 7 days/.test(await p.textContent('main')) && /deleted after 30 days/.test(await p.textContent('main')), 'states the deletion periods the code applies');
  await p.screenshot({ path: SHOTS + '/privacy.png', fullPage: true });

  console.log('- The emails, rendered');
  for (const name of ['digest-email', 'confirm-email']) {
    const e = await browser.newPage({ viewport: { width: 700, height: 900 } });
    await e.setContent(fs.readFileSync(SHOTS + '/' + name + '.html', 'utf8'));
    await e.screenshot({ path: SHOTS + '/' + name + '.png', fullPage: true });
    await e.close();
  }
  const phone = await browser.newPage({ viewport: { width: 375, height: 800 } });
  await phone.setContent(fs.readFileSync(SHOTS + '/digest-email.html', 'utf8'));
  const wide = await phone.evaluate(() => document.documentElement.scrollWidth);
  check(wide <= 375, 'digest fits a phone screen (width ' + wide + ')');
  await phone.screenshot({ path: SHOTS + '/digest-email-phone.png', fullPage: false });

  check(errors.length === 0, 'no page errors: ' + errors.join(' | '));
  await browser.close();
  console.log(`\n${passes} checks passed, ${failures} failed.`);
  process.exit(failures ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
