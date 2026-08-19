/* =====================================================================
   Homepage: plans, auth, checkout.
   ===================================================================== */

let PLANS = [];
let NOTES = {};
let SETTINGS = {};
let AUD = 'compound';
let ME = null;
let CHOSEN = null;
let ORDER = null;          // the pending transaction returned by purchase.php
let AUTH_MODE = 'signup';

/* ------------------------------------------------------------- boot */

document.addEventListener('DOMContentLoaded', async () => {
  document.getElementById('year').textContent = new Date().getFullYear();

  try {
    const me = await API.get('api/auth.php?action=me');
    API.setCsrf(me.csrf);
    ME = me.customer;
    paintNav();
  } catch (err) {
    console.warn('session check failed', err);
  }

  try {
    const data = await API.get('api/plans.php');
    PLANS = data.plans || [];
    NOTES = data.notes || {};
    SETTINGS = data.settings || {};
    paintFacts(data.live_count);
    paintSettings();
    renderPlans();
  } catch (err) {
    document.getElementById('planGrid').innerHTML =
      `<p class="empty">Could not load the bundles. ${esc(err.message)}</p>`;
  }
});

function paintNav() {
  const cta = document.getElementById('navCta');
  if (ME) {
    // No .nav-optional on "My account". On a phone this is the only way
    // into the dashboard, and the dashboard is where the WiFi password
    // lives — hiding it strands the customer completely.
    cta.innerHTML = `
      <a class="btn btn-ghost btn-sm" href="dashboard.html">My account</a>
      <button class="btn btn-primary btn-sm" data-action="scroll-plans">Buy data</button>`;
  } else {
    cta.innerHTML = `
      <button class="btn btn-ghost btn-sm nav-optional" data-action="auth-login">Log in</button>
      <button class="btn btn-primary btn-sm" data-action="auth-signup">Get connected</button>`;
  }

  // The burger menu repeats account access, so a narrow screen that has
  // shrunk the buttons still has a full-size tap target for it.
  const menu = document.getElementById('navMenuAccount');
  if (menu) {
    menu.innerHTML = ME
      ? `<a href="dashboard.html">My account</a>
         <a href="#" data-action="menu-logout">Log out</a>`
      : `<a href="#" data-action="menu-login">Log in</a>
         <a href="#" data-action="menu-signup">Create an account</a>`;
  }
}

function toggleMenu(open) {
  const menu = document.getElementById('navMenu');
  const burger = document.querySelector('.nav-burger');
  const next = open === undefined ? !menu.classList.contains('open') : open;
  menu.classList.toggle('open', next);
  if (burger) { burger.setAttribute('aria-expanded', String(next)); }
}

async function menuLogout() {
  toggleMenu(false);
  try { await API.post('api/auth.php?action=logout', {}); } catch (err) { /* leaving anyway */ }
  ME = null;
  paintNav();
  toast('Logged out');
}

function paintFacts(live) {
  document.getElementById('liveCount').textContent = live ?? '—';

  const speeds = PLANS.map(p => parseInt(p.speed, 10)).filter(Boolean);
  const prices = PLANS.map(p => p.price).filter(Boolean);
  document.getElementById('topSpeed').textContent = speeds.length ? Math.max(...speeds) + ' Mbps' : '—';
  document.getElementById('fromPrice').textContent = prices.length ? ngn(Math.min(...prices)) : '—';
  document.getElementById('footStatus').textContent = 'Dish up · ' + (live ?? 0) + ' online';
}

function paintSettings() {
  const wa = SETTINGS.support_whatsapp || '';
  document.getElementById('supPhone').textContent = SETTINGS.support_phone || '—';
  document.getElementById('supWa').textContent = wa || '—';
  if (wa) {
    document.getElementById('supWaBtn').href = 'https://wa.me/' + wa;
    document.getElementById('footWhatsapp').href = 'https://wa.me/' + wa;
  }
}

/* ------------------------------------------------------------ plans */

function setAud(a) {
  AUD = a;
  document.getElementById('tab-compound').classList.toggle('on', a === 'compound');
  document.getElementById('tab-visitor').classList.toggle('on', a === 'visitor');
  renderPlans();
}

function renderPlans() {
  document.getElementById('audNote').textContent = NOTES[AUD] || '';

  const list = PLANS.filter(p => p.aud === AUD);
  const grid = document.getElementById('planGrid');

  if (!list.length) {
    grid.innerHTML = '<p class="empty">No bundles here yet.</p>';
    return;
  }

  grid.innerHTML = list.map(p => `
    <article class="plan ${p.featured ? 'featured' : ''}">
      ${p.featured ? '<span class="plan-flag">Most bought</span>' : ''}
      <div class="plan-top">
        <div>
          <h3>${esc(p.name)}</h3>
          <p class="sub">${esc(p.sub)}</p>
        </div>
        <div class="bars" aria-hidden="true">${bars(p.tier)}</div>
      </div>
      <div class="price num">${ngn(p.price)}</div>
      <div class="per">per ${esc(p.valid)}</div>
      <ul>
        <li>${tickSvg}<span><strong>${esc(p.data)}</strong> of data</span></li>
        <li>${tickSvg}<span>Up to <strong>${esc(p.speed)}</strong></span></li>
        <li>${tickSvg}<span>Valid ${esc(p.valid)}</span></li>
        <li>${tickSvg}<span>${esc(p.devices)}</span></li>
      </ul>
      <button class="btn ${p.featured ? 'btn-primary' : 'btn-ghost'} btn-block" data-action="buy" data-plan="${p.id}">
        Buy ${esc(p.name)}
      </button>
    </article>
  `).join('');
}

function scrollToPlans() {
  document.getElementById('plans').scrollIntoView({ behavior: 'smooth' });
}

/* ------------------------------------------------------------- auth */

function openAuth(mode) {
  AUTH_MODE = mode;
  const signup = mode === 'signup';

  document.getElementById('authTitle').textContent = signup ? 'Create your account' : 'Welcome back';
  document.getElementById('authSub').textContent = signup ? 'Takes about a minute.' : 'Your phone number and password.';
  document.getElementById('authBtn').textContent = signup ? 'Create account' : 'Log in';
  document.getElementById('signupFields').classList.toggle('hide', !signup);
  document.getElementById('f-pass').autocomplete = signup ? 'new-password' : 'current-password';

  document.getElementById('authSwap').innerHTML = signup
    ? `Already have an account? <a href="#" data-action="auth-login" style="color:var(--beam)">Log in</a>`
    : `New here? <a href="#" data-action="auth-signup" style="color:var(--beam)">Create an account</a>`;

  // Only offered on the login screen. On the signup screen it is noise,
  // and worse, it invites people to "recover" an account they have not
  // created yet.
  document.getElementById('authForgot').innerHTML = signup
    ? ''
    : `<a href="#" data-action="forgot" style="color:var(--muted)">Forgot your password?</a>`;

  if (signup) { loadSecurityQuestions(); }
  toggleFlat();
  openOv('ov-auth');
  setTimeout(() => document.getElementById('f-phone').focus(), 80);
}

function toggleFlat() {
  const isCompound = document.getElementById('f-type').value === 'compound';
  document.getElementById('flatField').classList.toggle('hide', !isCompound);
}

async function submitAuth() {
  const btn = document.getElementById('authBtn');
  btn.disabled = true;

  const payload = {
    action: AUTH_MODE,
    phone: document.getElementById('f-phone').value.trim(),
    password: document.getElementById('f-pass').value,
  };
  if (AUTH_MODE === 'signup') {
    payload.full_name = document.getElementById('f-name').value.trim();
    payload.type = document.getElementById('f-type').value;
    payload.flat_no = document.getElementById('f-flat').value.trim();
    payload.security_question = document.getElementById('f-secq').value;
    payload.security_answer = document.getElementById('f-seca').value.trim();
  }

  try {
    const res = await API.post('api/auth.php?action=' + AUTH_MODE, payload);
    ME = res.customer;
    paintNav();
    closeAll();
    toast(AUTH_MODE === 'signup' ? 'Account created — welcome' : 'Logged in');

    // Came here from a plan button? Carry straight on to checkout.
    if (CHOSEN) { openCheckout(); }
  } catch (err) {
    toast(err.message, true);
  } finally {
    btn.disabled = false;
  }
}

/* ------------------------------------------------ password recovery */

let QUESTIONS_LOADED = false;

async function loadSecurityQuestions() {
  if (QUESTIONS_LOADED) { return; }
  try {
    const res = await API.get('api/auth.php?action=security_questions');
    const sel = document.getElementById('f-secq');
    sel.innerHTML = res.questions.map(q => `<option>${esc(q)}</option>`).join('');
    QUESTIONS_LOADED = true;
  } catch (err) {
    // Not fatal on its own, but signup will be rejected without a valid
    // question, so say so rather than letting them fill the form first.
    toast('Could not load the security questions — reload the page', true);
  }
}

function openForgot() {
  document.getElementById('resetStep1').classList.remove('hide');
  document.getElementById('resetStep2').classList.add('hide');
  document.getElementById('r-phone').value = document.getElementById('f-phone').value.trim();
  document.getElementById('r-answer').value = '';
  document.getElementById('r-pass').value = '';
  closeAll();
  openOv('ov-reset');
  setTimeout(() => document.getElementById('r-phone').focus(), 80);
}

async function resetLookup() {
  const phone = document.getElementById('r-phone').value.trim();
  try {
    const res = await API.post('api/auth.php?action=reset_question', { phone });
    document.getElementById('r-question').textContent = res.question;
    document.getElementById('resetStep1').classList.add('hide');
    document.getElementById('resetStep2').classList.remove('hide');
    document.getElementById('resetSub').textContent = 'Answer it and pick a new password.';
    setTimeout(() => document.getElementById('r-answer').focus(), 80);
  } catch (err) {
    toast(err.message, true);
  }
}

async function resetFinish() {
  try {
    const res = await API.post('api/auth.php?action=reset_password', {
      phone:    document.getElementById('r-phone').value.trim(),
      answer:   document.getElementById('r-answer').value.trim(),
      password: document.getElementById('r-pass').value,
    });
    // The server logs them in on success — they have just proved who
    // they are, so sending them back to a login form would be rude.
    ME = res.customer;
    paintNav();
    closeAll();
    toast('Password changed — you are logged in');
  } catch (err) {
    toast(err.message, true);
  }
}

/* --------------------------------------------------------- checkout */

function buy(planId) {
  CHOSEN = PLANS.find(p => p.id === planId);
  if (!CHOSEN) { return; }

  if (!ME) {
    openAuth('signup');
    toast('Create an account first — it takes a minute');
    return;
  }
  openCheckout();
}

function openCheckout() {
  ORDER = null;
  document.getElementById('buyStep1').classList.remove('hide');
  document.getElementById('buyStep2').classList.add('hide');
  document.getElementById('buySub').textContent = CHOSEN.name;
  document.getElementById('sumName').textContent = CHOSEN.name;
  document.getElementById('sumPrice').textContent = ngn(CHOSEN.price);
  document.getElementById('sumDetail').textContent =
    `${CHOSEN.data} · ${CHOSEN.speed} · ${CHOSEN.valid} · ${CHOSEN.devices}`;
  document.getElementById('sumTotal').textContent = ngn(CHOSEN.price);
  openOv('ov-buy');
}

document.addEventListener('click', (e) => {
  const opt = e.target.closest('.pay-opt');
  if (!opt) { return; }
  document.querySelectorAll('.pay-opt').forEach(o => o.classList.remove('on'));
  opt.classList.add('on');
  opt.querySelector('input').checked = true;
});

function chosenMethod() {
  const on = document.querySelector('.pay-opt.on');
  return on ? on.dataset.method : 'bank_transfer';
}

async function startPurchase() {
  const btn = document.getElementById('buyGo');
  btn.disabled = true;

  try {
    const res = await API.post('api/purchase.php', {
      plan_id: CHOSEN.id,
      method: chosenMethod(),
    });

    if (res.settled) {                       // wallet payment
      closeAll();
      toast(res.message);
      return;
    }

    ORDER = res;
    document.getElementById('buyStep1').classList.add('hide');
    document.getElementById('buyStep2').classList.remove('hide');

    // Two shapes of bank transfer. The automatic one has an account
    // generated for this single payment, so there is no reference to
    // quote and no receipt to upload — OPay tells us when it lands.
    const isBank = res.method === 'bank_transfer' || res.method === 'opay';
    const isAuto = res.automatic === true;

    document.getElementById('payBank').classList.toggle('hide', !isBank);
    document.getElementById('payUsdt').classList.toggle('hide', isBank);
    document.getElementById('proofBox').classList.toggle('hide', !isBank || isAuto);
    document.getElementById('autoNotice').classList.toggle('hide', !isAuto);
    document.getElementById('manualRef').classList.toggle('hide', isAuto);
    document.getElementById('bkExpiryRow').classList.toggle('hide', !res.expires_at);

    // Nobody confirms the automatic path — saying "once we confirm" there
    // would tell the customer to wait for something that never happens.
    document.getElementById('buyFoot').innerHTML = isAuto
      ? 'Your bundle goes live moments after the transfer lands. '
        + '<a href="dashboard.html" style="color:var(--beam)">Check your dashboard</a>.'
      : 'Once we confirm, your bundle goes live within a minute. '
        + '<a href="dashboard.html" style="color:var(--beam)">Check your dashboard</a>.';

    if (isBank) {
      document.getElementById('bkBank').textContent = res.bank.bank_name;
      document.getElementById('bkName').textContent = res.bank.account_name;
      document.getElementById('bkNo').textContent = res.bank.account_no;
      document.getElementById('bkAmt').textContent = res.amount_text;
      document.getElementById('bkRef').textContent = res.reference;
      if (res.expires_at) {
        document.getElementById('bkExpiry').textContent = res.expires_at;
      }
    } else {
      document.getElementById('usChain').textContent = res.usdt.chain;
      document.getElementById('usAmt').textContent = res.usdt.amount + ' USDT';
      document.getElementById('usAddr').textContent = res.usdt.address;
    }
  } catch (err) {
    toast(err.message, true);
  } finally {
    btn.disabled = false;
  }
}

function copyRef() {
  if (ORDER) { copyText(ORDER.reference, 'Reference copied'); }
}

function copyAccount() {
  if (ORDER?.bank) { copyText(ORDER.bank.account_no, 'Account number copied'); }
}

async function uploadProof() {
  const file = document.getElementById('f-proof').files[0];
  if (!file) { toast('Choose the receipt screenshot first', true); return; }
  if (!ORDER) { return; }

  const form = new FormData();
  form.append('reference', ORDER.reference);
  form.append('proof', file);

  try {
    const res = await API.upload('api/payments.php?action=proof', form);
    closeAll();
    toast(res.message);
  } catch (err) {
    toast(err.message, true);
  }
}

async function sentNoProof() {
  if (!ORDER) { return; }
  try {
    const res = await API.post('api/payments.php?action=sent', { reference: ORDER.reference });
    closeAll();
    toast(res.message);
  } catch (err) {
    toast(err.message, true);
  }
}

async function submitHash() {
  const hash = document.getElementById('f-hash').value.trim();
  if (!hash) { toast('Paste the transaction hash', true); return; }
  if (!ORDER) { return; }

  try {
    const res = await API.post('api/payments.php?action=usdt', {
      reference: ORDER.reference,
      tx_hash: hash,
    });
    closeAll();
    toast(res.message);
  } catch (err) {
    toast(err.message, true);
  }
}


/* ------------------------------------------------------- wiring ----
   The HTML declares intent with data-action / data-submit / data-change
   and this is where each name gets its behaviour. Nothing executable
   lives in the markup, which is what lets the CSP stay strict.
   ------------------------------------------------------------------ */

on('auth-login',     () => openAuth('login'));
on('auth-signup',    () => openAuth('signup'));
on('scroll-plans',   () => scrollToPlans());
on('open-support',   () => openOv('ov-support'));
on('close',          () => closeAll());
on('aud',            (el) => setAud(el.dataset.aud));
on('buy',            (el) => buy(Number(el.dataset.plan)));
on('start-purchase', () => startPurchase());
on('copy-ref',       () => copyRef());
on('copy-account',   () => copyAccount());
on('upload-proof',   () => uploadProof());
on('sent-no-proof',  () => sentNoProof());
on('submit-hash',    () => submitHash());
on('auth',           () => submitAuth());
on('toggle-flat',    () => toggleFlat());
on('forgot',         () => openForgot());
on('menu',           () => toggleMenu());
// The click delegate calls preventDefault, so these anchors would close
// the menu and go nowhere. Do the scroll here instead of relying on the
// browser's default jump.
on('menu-close',     (el) => {
  toggleMenu(false);
  const id = (el.getAttribute('href') || '').replace('#', '');
  const target = id && document.getElementById(id);
  if (target) { target.scrollIntoView({ behavior: 'smooth' }); }
});
on('menu-login',     () => { toggleMenu(false); openAuth('login'); });
on('menu-signup',    () => { toggleMenu(false); openAuth('signup'); });
on('menu-logout',    () => menuLogout());
on('reset-lookup',   () => resetLookup());
on('reset-finish',   () => resetFinish());
