let DASH = null;

document.addEventListener('DOMContentLoaded', async () => {
  try {
    const me = await API.get('api/auth.php?action=me');
    API.setCsrf(me.csrf);
    if (!me.authenticated) { return; }
  } catch (err) {
    toast('Could not reach the server', true);
    return;
  }

  document.getElementById('gate').classList.add('hide');
  document.getElementById('app').classList.remove('hide');
  await load();

  // The router reports in every 60s, so a slow refresh keeps usage honest
  // without hammering shared hosting.
  setInterval(load, 60000);
});

async function load() {
  try {
    DASH = await API.get('api/dashboard.php');
  } catch (err) {
    toast(err.message, true);
    return;
  }

  const c = DASH.customer;
  document.getElementById('hello').textContent = c.full_name ? `Hi ${c.full_name.split(' ')[0]}` : 'Your account';
  document.getElementById('whoami').textContent =
    `${c.phone} · ${c.type === 'compound' ? 'Compound' + (c.flat_no ? ', flat ' + c.flat_no : '') : 'Visitor'}`
    + (c.wallet > 0 ? ` · Wallet ${ngn(c.wallet)}` : '');

  document.getElementById('wifiUser').textContent = c.wifi_username || '—';
  document.getElementById('wifiPass').textContent = c.wifi_password || '—';

  paintCurrent(DASH.current);
  paintDevices(DASH.devices, DASH.device_count);
  paintHistory(DASH.history);
  paintSecurity();
}

function paintCurrent(cur) {
  const box = document.getElementById('currentBody');

  if (!cur) {
    box.innerHTML = `
      <p class="empty">No active bundle right now.</p>
      <a class="btn btn-primary btn-block" href="index.html#plans">Buy a bundle</a>`;
    return;
  }

  const pct = cur.percent_used ?? 0;
  const warn = pct > 80;

  box.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:baseline;gap:14px;flex-wrap:wrap">
      <div>
        <div style="font-family:var(--display);font-size:26px;font-weight:800">${esc(cur.plan_name)}</div>
        <div class="hint">${esc(cur.speed)} · ${cur.device_limit} device${cur.device_limit > 1 ? 's' : ''}</div>
      </div>
      <span class="pill ${cur.live_on_router ? 'ok' : 'warn'}">
        ${cur.live_on_router ? 'Live on the router' : 'Reaching the router…'}
      </span>
    </div>

    ${cur.unlimited ? `
      <div style="margin-top:20px">
        <div class="num" style="font-size:30px;font-weight:700">Unlimited data</div>
        <div class="hint">${esc(cur.data_used_text)} used so far</div>
      </div>`
    : `
      <div class="meter"><i class="${warn ? 'warn' : ''}" style="width:${Math.min(100, pct)}%"></i></div>
      <div style="display:flex;justify-content:space-between;font-size:14px">
        <span class="num">${esc(cur.data_left_text)} left</span>
        <span class="hint num">${esc(cur.data_used_text)} of ${esc(cur.data_total_text)}</span>
      </div>`}

    <div class="bank-card" style="margin-top:20px;margin-bottom:0">
      <div class="bank-row"><span class="k">Time left</span><span class="v num">${humanLeft(cur.seconds_left)}</span></div>
      <div class="bank-row"><span class="k">Expires</span><span class="v num">${esc(cur.expires_at)}</span></div>
    </div>

    <a class="btn btn-ghost btn-block" style="margin-top:16px" href="index.html#plans">Top up / change bundle</a>`;
}

function paintDevices(devices, count) {
  document.getElementById('devCount').textContent = count + ' seen';
  const rows = document.getElementById('devRows');

  if (!devices.length) {
    rows.innerHTML = `<tr><td colspan="3" class="empty">No device has connected yet. Join the IB Gadgets network and log in once.</td></tr>`;
    return;
  }

  rows.innerHTML = devices.map(d => `
    <tr>
      <td class="num">${esc(d.label || d.mac)}</td>
      <td class="hint">${esc(d.last_seen || '—')}</td>
      <td>${Number(d.blocked) ? '<span class="pill bad">Blocked</span>' : '<span class="pill ok">Allowed</span>'}</td>
    </tr>`).join('');
}

function paintHistory(history) {
  const rows = document.getElementById('txRows');

  if (!history.length) {
    rows.innerHTML = `<tr><td colspan="6" class="empty">Nothing yet.</td></tr>`;
    return;
  }

  const pill = s => s === 'success' ? 'ok' : (s === 'pending' ? 'warn' : 'bad');

  rows.innerHTML = history.map(t => `
    <tr>
      <td class="num">${esc(t.reference)}</td>
      <td>${esc(t.plan_name || '—')}</td>
      <td class="num">${ngn(t.amount_naira)}</td>
      <td class="hint">${esc(String(t.method).replace('_', ' '))}</td>
      <td><span class="pill ${pill(t.status)}">${esc(t.status)}</span></td>
      <td class="hint num">${esc(t.created_at)}</td>
    </tr>`).join('');
}

function copyWifi() {
  const c = DASH?.customer;
  if (c) { copyText(`Username: ${c.wifi_username}\nPassword: ${c.wifi_password}`, 'WiFi login copied'); }
}

async function logout() {
  try { await API.post('api/auth.php?action=logout', {}); } catch (err) { /* leaving anyway */ }
  location.href = 'index.html';
}

/* ------------------------------------------------- account security */

async function paintSecurity() {
  const c = DASH?.customer;
  if (!c) { return; }

  // Accounts made before recovery existed have no question. They are the
  // ones who most need the prompt, and the only ones who see it.
  const has = c.has_security_question;
  document.getElementById('secWarn').classList.toggle('hide', has);
  document.getElementById('secPassField').classList.toggle('hide', !has);
  document.getElementById('d-secpass').required = has;

  try {
    const res = await API.get('api/auth.php?action=security_questions');
    document.getElementById('d-secq').innerHTML =
      res.questions.map(q => `<option>${esc(q)}</option>`).join('');
  } catch (err) {
    toast('Could not load the security questions', true);
  }
}

async function saveSecurity() {
  const payload = {
    security_question: document.getElementById('d-secq').value,
    security_answer:   document.getElementById('d-seca').value.trim(),
  };
  const pass = document.getElementById('d-secpass').value;
  if (pass) { payload.current_password = pass; }

  try {
    await API.post('api/auth.php?action=set_security', payload);
    toast('Security question saved');
    document.getElementById('d-seca').value = '';
    document.getElementById('d-secpass').value = '';
    // It exists now, so the warning goes and the password field appears.
    DASH.customer.has_security_question = true;
    paintSecurity();
  } catch (err) {
    toast(err.message, true);
  }
}

async function changePassword() {
  try {
    await API.post('api/auth.php?action=change_password', {
      current_password: document.getElementById('d-oldpass').value,
      password:         document.getElementById('d-newpass').value,
    });
    document.getElementById('d-oldpass').value = '';
    document.getElementById('d-newpass').value = '';
    toast('Password changed');
  } catch (err) {
    toast(err.message, true);
  }
}

/* ---- wiring: no executable code in the markup, CSP is script-src 'self' */
on('logout',          () => logout());
on('copy-wifi',       () => copyWifi());
on('save-security',   () => saveSecurity());
on('change-password', () => changePassword());
