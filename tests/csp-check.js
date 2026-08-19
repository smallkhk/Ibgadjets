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
  // PW_CHROME lets a machine whose Chromium is not the revision this
  // Playwright expects point at the one it has. Without it the run dies
  // with "Executable doesn't exist" and tells you to download a browser
  // you already have.
  const browser = await chromium.launch(
    process.env.PW_CHROME ? { executablePath: process.env.PW_CHROME } : {}
  );

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
  await page.waitForTimeout(600);

  // Log in through the form rather than seeding a session. The login
  // button is itself delegated through data-action, so if the CSP broke
  // event delegation this click would do nothing and everything after it
  // would fail — which is exactly the failure this file exists to catch.
  await page.fill('#a-email', process.env.ADMIN_EMAIL || 'owner@ibgadgets.ng');
  await page.fill('#a-pass',  process.env.ADMIN_PASS  || 'testpass1234');
  await page.locator("[data-submit='admin-login'] button[type='submit']").click();
  await page.waitForTimeout(1500);

  say('admin login works', await page.locator('#app').isVisible());
  say('tabs render', await page.locator('.adm-tabs .tab').count() === 7);
  await page.locator("[data-tab='plans']").click();
  await page.waitForTimeout(900);
  say('tab click loads content', await page.locator('#planRows tr').count() > 0);
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  console.log('dashboard.html');
  ({ page, violations, ctx } = await openPage());

  // Sign up through the real form rather than seeding a session. It is
  // the only way to prove the security-question fields work under the
  // CSP, and the dashboard needs a logged-in customer regardless.
  //
  // Note the signup limiter allows 5 per IP per 10 minutes, so running
  // this file in a tight loop will start failing here. That is the
  // limiter doing its job, not a regression:
  //   DELETE FROM rate_limits WHERE bucket LIKE 'signup:%';
  const phone = '0803' + String(Math.floor(1000000 + Math.random() * 8999999));
  await page.goto(B + '/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(600);
  await page.locator("[data-action='auth-signup']").first().click();
  await page.waitForTimeout(500);
  await page.fill('#f-phone', phone);
  await page.fill('#f-name', 'CSP Tester');
  await page.selectOption('#f-type', 'visitor');
  await page.fill('#f-pass', 'hunter2222');
  await page.fill('#f-seca', 'Ibadan');
  say('security question list loaded', await page.locator('#f-secq option').count() > 0);
  await page.locator('#authBtn').click();
  await page.waitForTimeout(1500);

  await page.goto(B + '/dashboard.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);
  say('bundle panel populated', (await page.locator('#currentBody').innerText()).length > 20);
  say('security panel offers questions', await page.locator('#d-secq option').count() > 0);
  // A brand new account has just set one at signup, so the warning must
  // NOT be showing. If it is, has_security_question is not making it
  // through the API to the page.
  say('no false "set a question" warning', await page.locator('#secWarn').isHidden());
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  // Recovery is the only way back into an account — no email, no SMS —
  // and it runs on two freshly added delegated forms. A CSP that broke
  // delegation would leave the buttons doing nothing at all, silently,
  // which is the exact failure this file was written for.
  console.log('password recovery');
  ({ page, violations, ctx } = await openPage());
  await page.goto(B + '/index.html', { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(600);
  await page.locator("[data-action='auth-login']").first().click();
  await page.waitForTimeout(400);
  await page.locator("[data-action='forgot']").click();
  await page.waitForTimeout(400);
  say('recovery modal opens', await page.locator('#ov-reset').isVisible());

  await page.fill('#r-phone', phone);
  await page.locator("[data-submit='reset-lookup'] button[type='submit']").click();
  await page.waitForTimeout(1200);
  say('question comes back', (await page.locator('#r-question').innerText()).length > 10);

  await page.fill('#r-answer', '  ibadan. ');
  await page.fill('#r-pass', 'changed12345');
  await page.locator("[data-submit='reset-finish'] button[type='submit']").click();
  await page.waitForTimeout(1500);
  say('reset completes and logs in', await page.locator('#ov-reset').isHidden());
  say('no CSP violations', violations.length === 0);
  violations.forEach((v) => console.log('        ' + v));
  await ctx.close();

  await browser.close();
  console.log(failures ? '\n' + failures + ' FAILED' : '\nall green');
  process.exit(failures ? 1 : 0);
})();
