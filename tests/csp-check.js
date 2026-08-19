/**
 * Drive every page under the production Content-Security-Policy and fail
 * on any refusal or JavaScript error.
 *
 *   php -S 127.0.0.1:8840 -t public_html tests/csp-server.php &
 *   node tests/csp-check.js
 *
 * The CSP is script-src 'self' with no 'unsafe-inline', which means an
 * onclick="" attribute or an inline <script> block is silently refused by
 * the browser: buttons simply stop working, with nothing in the server
 * logs. This catches that before a customer does.
 */

const { chromium } = require('playwright');
const B = process.env.BASE || 'http://127.0.0.1:8840';
let failures = 0;

const say = (name, ok) => {
  if (!ok) { failures++; }
  console.log('  ' + (ok ? 'ok  ' : 'FAIL') + '  ' + name);
};

(async () => {
  const browser = await chromium.launch();

  const openPage = async () => {
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const page = await ctx.newPage();
    const violations = [];
    page.on('console', (m) => {
      if (/Content Security Policy|Refused to/i.test(m.text())) { violations.push(m.text().slice(0, 100)); }
    });
    page.on('pageerror', (e) => violations.push('JS: ' + e.message.slice(0, 90)));
    return { page, violations, ctx };
  };

  console.log('index.html');
  let { page, violations, ctx } = await openPage();
  await page.goto(B + '/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  say('plans render', await page.locator('.plan').count() > 0);
  await page.locator("[data-action='open-support']").first().click();
  await page.waitForTimeout(400);
  say('modal opens on click', await page.locator('#ov-support.open').count() === 1);
  await page.locator('#ov-support .x').click();
  await page.waitForTimeout(300);
  say('modal closes', await page.locator('#ov-support.open').count() === 0);
  await page.locator("button[data-aud='visitor']").click();
  await page.waitForTimeout(400);
  say('audience tab switches', await page.locator('.plan').count() > 0);
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  console.log('admin.html');
  ({ page, violations, ctx } = await openPage());
  await page.goto(B + '/admin.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  say('tabs render', await page.locator('.adm-tabs .tab').count() === 6);
  await page.locator("[data-tab='plans']").click();
  await page.waitForTimeout(900);
  say('tab click loads content', await page.locator('#planRows tr').count() > 0);
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  console.log('dashboard.html');
  ({ page, violations, ctx } = await openPage());
  await page.goto(B + '/dashboard.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  say('bundle panel populated', (await page.locator('#currentBody').innerText()).length > 20);
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  await browser.close();
  console.log(failures ? '\n' + failures + ' FAILED' : '\nall green');
  process.exit(failures ? 1 : 0);
})();
