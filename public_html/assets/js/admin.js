const TABS = [
  ['over',  'Overview',  loadOverview],
  ['rep',   'Reports',   loadReports],
  ['pay',   'Payments',  loadPayments],
  ['cust',  'Customers', loadCustomers],
  ['plans', 'Bundles',   loadPlans],
  ['live',  'Live',      loadLive],
  ['set',   'Settings',  loadSettings],
];
let PLANS = [];

document.addEventListener('DOMContentLoaded', async () => {
  try {
    const me = await API.get('api/admin.php?action=me');
    API.setCsrf(me.csrf);
    if (me.authenticated) { enter(me.admin); }
  } catch (err) {
    toast('Cannot reach the server', true);
  }
});

async function doLogin() {
  try {
    const res = await API.post('api/admin.php?action=login', {
      email: document.getElementById('a-email').value.trim(),
      password: document.getElementById('a-pass').value,
    });
    enter(res.admin);
  } catch (err) {
    toast(err.message, true);
  }
}

function enter(admin) {
  document.getElementById('gate').classList.add('hide');
  document.getElementById('app').classList.remove('hide');
  document.getElementById('admNav').innerHTML =
    `<span class="hint" style="margin-right:12px">${esc(admin.email)}</span>
     <button class="btn btn-ghost btn-sm" data-action="admin-logout">Sign out</button>`;

  document.getElementById('admTabs').innerHTML = TABS.map(([id, label], i) =>
    `<button class="tab ${i === 0 ? 'on' : ''}" id="tab-${id}" data-action="tab" data-tab="${id}">${label}</button>`
  ).join('');

  showTab('over');
}

function showTab(id) {
  TABS.forEach(([t]) => {
    document.getElementById('tab-' + t).classList.toggle('on', t === id);
    document.getElementById('v-' + t).classList.toggle('hide', t !== id);
  });
  const entry = TABS.find(([t]) => t === id);
  if (entry) { entry[2](); }
}

async function doLogout() {
  try { await API.post('api/admin.php?action=logout', {}); } catch (err) { /* going anyway */ }
  location.reload();
}

/* ---------------------------------------------------------- overview */

async function loadOverview() {
  try {
    const { stats } = await API.get('api/admin.php?action=overview');
    const cards = [
      ['Customers', stats.customers],
      ['Active bundles', stats.active_subs],
      ['Online now', stats.online_now],
      ['Waiting approval', stats.pending_payments],
      ['Today', ngn(stats.revenue_today)],
      ['This month', ngn(stats.revenue_month)],
      ['Not yet on router', stats.awaiting_sync],
      ['Sharing flags', stats.tether_flags],
    ];
    document.getElementById('statGrid').innerHTML = cards.map(([lbl, val]) =>
      `<div class="stat"><div class="lbl">${lbl}</div><div class="val num">${val}</div></div>`).join('');

    const { log } = await API.get('api/admin.php?action=sync_log');
    document.getElementById('logRows').innerHTML = log.length ? log.map(r => `
      <tr><td class="hint num">${esc(r.created_at)}</td>
          <td>${esc(r.action)}</td>
          <td class="hint">${esc(r.result)}</td></tr>`).join('')
      : `<tr><td colspan="3" class="empty">The router has not called in yet.</td></tr>`;
  } catch (err) { toast(err.message, true); }
}

/* ----------------------------------------------------------- reports */

async function loadReports() {
  const days = Number(document.getElementById('repDays').value) || 30;
  try {
    const r = await API.get('api/admin.php?action=reports&days=' + days);

    const cards = [
      ['Revenue', ngn(r.totals.revenue)],
      ['Today', ngn(r.totals.revenue_today)],
      ['Payments', r.totals.payments],
      ['Paying customers', r.totals.buyers],
      ['Data sold', r.totals.data_sold_gb + ' GB'],
      ['Rejected', r.totals.rejected],
    ];
    document.getElementById('repStats').innerHTML = cards.map(([lbl, val]) =>
      `<div class="stat"><div class="lbl">${lbl}</div><div class="val num">${val}</div></div>`).join('');

    // One helper for every table here: same shape, same empty state, so
    // a report with no data reads as "nothing yet" rather than as a bug.
    const fill = (id, rows, cols, empty) => {
      document.getElementById(id).innerHTML = rows.length
        ? rows.map(row => '<tr>' + cols.map(c => c(row)).join('') + '</tr>').join('')
        : `<tr><td colspan="${cols.length}" class="empty">${empty}</td></tr>`;
    };

    fill('repDaily', r.daily, [
      d => `<td class="num">${esc(d.day)}</td>`,
      d => `<td class="num">${d.payments}</td>`,
      d => `<td class="num">${ngn(d.revenue)}</td>`,
    ], 'No payments in this period.');

    fill('repPlans', r.by_plan, [
      p => `<td>${esc(p.plan)}</td>`,
      p => `<td class="num">${p.sold}</td>`,
      p => `<td class="num">${ngn(p.revenue)}</td>`,
    ], 'Nothing sold yet.');

    fill('repAudience', r.by_audience, [
      a => `<td>${a.type === 'compound' ? 'Compound' : 'Visitors'}</td>`,
      a => `<td class="num">${a.payments}</td>`,
      a => `<td class="num">${ngn(a.revenue)}</td>`,
    ], 'Nothing sold yet.');

    fill('repTop', r.top_customers, [
      c => `<td>${esc(c.full_name || c.phone)}<div class="hint num">${esc(c.phone)}${
              c.flat_no ? ' · Flat ' + esc(c.flat_no) : ''}</div></td>`,
      c => `<td class="num">${c.payments}</td>`,
      c => `<td class="num">${ngn(c.spent)}</td>`,
    ], 'Nobody has bought anything yet.');

    fill('repMethod', r.by_method, [
      m => `<td>${esc(m.method.replace(/_/g, ' '))}</td>`,
      m => `<td class="num">${m.payments}</td>`,
      m => `<td class="num">${ngn(m.revenue)}</td>`,
    ], 'No payments in this period.');
  } catch (err) { toast(err.message, true); }
}

/* ---------------------------------------------------------- payments */

async function loadPayments() {
  try {
    const { transactions } = await API.get('api/admin.php?action=transactions&status=pending');
    document.getElementById('payCount').textContent = transactions.length;

    document.getElementById('payRows').innerHTML = transactions.length ? transactions.map(t => `
      <tr>
        <td class="num">${esc(t.reference)}</td>
        <td>${esc(t.phone)}<div class="hint">${esc(t.full_name || '')} ${t.flat_no ? '· ' + esc(t.flat_no) : ''}</div></td>
        <td>${esc(t.plan_name || '—')}</td>
        <td class="num">${ngn(t.amount_naira)}</td>
        <td class="hint">${esc(String(t.method).replace('_', ' '))}</td>
        <td>${t.proof_path
              ? `<a class="copy" href="api/proof.php?id=${t.id}" target="_blank" rel="noopener">View</a>`
              : (t.tx_hash ? `<span class="pill dim">hash</span>` : `<span class="hint">none</span>`)}</td>
        <td class="hint num">${esc(t.created_at)}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-beam btn-sm mini" data-action="approve" data-id="${t.id}">Approve</button>
          <button class="btn btn-ghost btn-sm mini" data-action="reject" data-id="${t.id}">Reject</button>
        </td>
      </tr>`).join('')
      : `<tr><td colspan="8" class="empty">Nothing waiting. Good.</td></tr>`;

    const all = await API.get('api/admin.php?action=transactions');
    const pill = s => s === 'success' ? 'ok' : (s === 'pending' ? 'warn' : 'bad');
    document.getElementById('payAllRows').innerHTML = all.transactions.slice(0, 60).map(t => `
      <tr><td class="num">${esc(t.reference)}</td>
          <td>${esc(t.phone)}</td>
          <td class="num">${ngn(t.amount_naira)}</td>
          <td><span class="pill ${pill(t.status)}">${esc(t.status)}</span></td>
          <td class="hint num">${esc(t.created_at)}</td></tr>`).join('');
  } catch (err) { toast(err.message, true); }
}

async function approve(id) {
  if (!confirm('Confirm the money landed and switch this bundle on?')) { return; }
  try {
    const res = await API.post('api/admin.php?action=approve', { id });
    toast(res.message);
    loadPayments();
  } catch (err) { toast(err.message, true); }
}

async function reject(id) {
  const note = prompt('Why is this being rejected?', 'no matching transfer found');
  if (note === null) { return; }
  try {
    await API.post('api/admin.php?action=reject', { id, note });
    toast('Rejected');
    loadPayments();
  } catch (err) { toast(err.message, true); }
}

/* --------------------------------------------------------- customers */

async function loadCustomers() {
  const q = document.getElementById('custSearch').value.trim();
  try {
    const { customers } = await API.get('api/admin.php?action=customers&q=' + encodeURIComponent(q));
    document.getElementById('custRows').innerHTML = customers.length ? customers.map(c => `
      <tr>
        <td class="num">${esc(c.phone)}</td>
        <td>${esc(c.full_name || '—')}</td>
        <td class="hint">${esc(c.type)}${c.flat_no ? ' · ' + esc(c.flat_no) : ''}</td>
        <td>${esc(c.current_plan || '—')}</td>
        <td class="hint num">${esc(c.expires_at || '—')}</td>
        <td>
          <input class="mini" style="width:64px;padding:6px 8px;border-radius:8px;background:rgba(10,20,48,.7);border:1px solid var(--line-2);color:var(--cream)"
                 type="number" min="1" max="16" placeholder="plan"
                 value="${c.device_limit ?? ''}"
                 data-change="set-devices" data-id="${c.id}">
          <div class="hint" style="font-size:11px">${c.device_count} seen</div>
        </td>
        <td><span class="pill ${c.status === 'active' ? 'ok' : 'bad'}">${esc(c.status)}</span></td>
        <td>
          <button class="btn btn-ghost btn-sm mini" data-action="toggle-ban" data-id="${c.id}" data-status="${c.status}">
            ${c.status === 'active' ? 'Suspend' : 'Restore'}
          </button>
        </td>
      </tr>`).join('')
      : `<tr><td colspan="8" class="empty">No customers found.</td></tr>`;
  } catch (err) { toast(err.message, true); }
}

async function setDevices(id, value) {
  try {
    await API.post('api/admin.php?action=customer_update', { id, device_limit: value === '' ? null : value });
    toast(value === '' ? 'Back to the plan default' : `Allowed ${value} device${value > 1 ? 's' : ''}`);
    loadCustomers();
  } catch (err) { toast(err.message, true); }
}

async function toggleBan(id, status) {
  const next = status === 'active' ? 'suspended' : 'active';
  if (next === 'suspended' && !confirm('Suspend this customer? They drop offline within a minute.')) { return; }
  try {
    await API.post('api/admin.php?action=customer_update', { id, status: next });
    toast(next === 'suspended' ? 'Suspended' : 'Restored');
    loadCustomers();
  } catch (err) { toast(err.message, true); }
}

/* ------------------------------------------------------------- plans */

const gbText = mb => mb === null ? 'unlimited' : (mb >= 1024 ? (mb / 1024) + ' GB' : mb + ' MB');

async function loadPlans() {
  try {
    const res = await API.get('api/admin.php?action=plans');
    PLANS = res.plans;
    renderPlanRows();
  } catch (err) { toast(err.message, true); }
}

function cell(plan, field, type = 'text', width = '84px') {
  return `<input data-id="${plan.id}" data-f="${field}" type="${type}"
            value="${esc(plan[field] ?? '')}"
            style="width:${width};padding:7px 9px;border-radius:8px;background:rgba(10,20,48,.7);
                   border:1px solid var(--line-2);color:var(--cream);font-size:13px">`;
}

function renderPlanRows() {
  document.getElementById('planRows').innerHTML = PLANS.map(p => `
    <tr data-row="${p.id}">
      <td>${cell(p, 'name', 'text', '140px')}</td>
      <td>
        <select data-id="${p.id}" data-f="audience"
          style="padding:7px 9px;border-radius:8px;background:rgba(10,20,48,.7);border:1px solid var(--line-2);color:var(--cream);font-size:13px">
          <option value="compound" ${p.audience === 'compound' ? 'selected' : ''}>Compound</option>
          <option value="visitor"  ${p.audience === 'visitor'  ? 'selected' : ''}>Visitor</option>
        </select>
      </td>
      <td>${cell(p, 'price_naira', 'number')}</td>
      <td><input data-id="${p.id}" data-f="data_mb" value="${p.data_mb === null ? 'unlimited' : p.data_mb}"
             title="Megabytes, or the word unlimited. '5 GB' also works."
             style="width:92px;padding:7px 9px;border-radius:8px;background:rgba(10,20,48,.7);border:1px solid var(--line-2);color:var(--cream);font-size:13px"></td>
      <td>${cell(p, 'speed_down_mbps', 'number', '68px')}</td>
      <td>${cell(p, 'speed_up_mbps', 'number', '68px')}</td>
      <td>${cell(p, 'validity_hours', 'number', '72px')}</td>
      <td>${cell(p, 'max_devices', 'number', '62px')}</td>
      <td>${cell(p, 'tier', 'number', '54px')}</td>
      <td>
        <input type="checkbox" data-id="${p.id}" data-f="active" ${Number(p.active) ? 'checked' : ''}>
      </td>
      <td><button class="btn btn-beam btn-sm mini" data-action="save-plan" data-id="${p.id}">Save</button></td>
    </tr>`).join('');
}

function collectPlan(id) {
  const out = { id };
  document.querySelectorAll(`[data-id="${id}"]`).forEach(el => {
    out[el.dataset.f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
  });
  const original = PLANS.find(p => p.id === id) || {};
  out.subtitle = original.subtitle || '';
  out.featured = original.featured || 0;
  out.sort_order = original.sort_order || 0;
  return out;
}

async function savePlan(id) {
  try {
    await API.post('api/admin.php?action=plan_save', collectPlan(id));
    toast('Saved — anyone on this bundle gets the new limits within a minute');
    loadPlans();
  } catch (err) { toast(err.message, true); }
}

async function newPlan() {
  const name = prompt('Name for the new bundle?');
  if (!name) { return; }
  try {
    await API.post('api/admin.php?action=plan_save', {
      name, audience: 'compound', price_naira: 1000, data_mb: 1024,
      speed_down_mbps: 10, speed_up_mbps: 3, validity_hours: 24,
      max_devices: 1, tier: 2, active: 0,
    });
    toast('Created — it stays hidden until you tick Live');
    loadPlans();
  } catch (err) { toast(err.message, true); }
}

/* -------------------------------------------------------------- live */

async function loadLive() {
  try {
    const { sessions, tether_grace } = await API.get('api/admin.php?action=sessions');
    document.getElementById('liveRows').innerHTML = sessions.length ? sessions.map(s => {
      const sharing = Number(s.tethered_hits) > tether_grace;
      return `
      <tr>
        <td class="num">${esc(s.phone || s.username)}<div class="hint">${esc(s.full_name || '')}</div></td>
        <td class="num hint">${esc(s.mac || '—')}</td>
        <td class="num hint">${esc(s.ip || '—')}</td>
        <td class="num">${Number(s.used_mb).toFixed(0)} MB</td>
        <td class="hint num">${esc(s.last_seen)}</td>
        <td>${sharing
              ? `<span class="pill bad">sharing · ${s.tethered_hits}</span>`
              : (Number(s.tethered_hits) ? `<span class="pill warn">${s.tethered_hits}</span>` : '<span class="pill ok">clean</span>')}</td>
        <td></td>
      </tr>`;
    }).join('') : `<tr><td colspan="7" class="empty">Nobody online in the last 15 minutes.</td></tr>`;
  } catch (err) { toast(err.message, true); }
}

/* ---------------------------------------------------------- settings */

const SETTING_LABELS = {
  business_name: 'Business name',
  support_phone: 'Support phone',
  support_whatsapp: 'WhatsApp number (with 234)',
  bank_name: 'Bank name',
  bank_account_name: 'Account name',
  bank_account_no: 'Account number',
  bank_note: 'Note shown at checkout',
  ngn_per_usdt: 'Naira per USDT',
  wallet_bsc: 'USDT wallet — BSC',
  wallet_tron: 'USDT wallet — TRON',
  usdt_confirmations: 'USDT confirmations required',
  tether_policy: 'Sharing policy (flag / block)',
  tether_grace_hits: 'Sharing tolerance (minutes)',
  live_count_floor: 'Homepage "online now" floor',
  opay_enabled: 'OPay enabled (1 / 0)',
  opay_live: 'OPay production keys (1) or staging (0)',
  opay_merchant_id: 'OPay Merchant ID',
  opay_public_key: 'OPay public key',
  opay_secret_key: 'OPay secret key',
  opay_auto_threshold: 'Auto-account from ₦ (0 = all manual)',
};

// Explains the one setting that costs money if you get it wrong.
const SETTING_HINTS = {
  opay_auto_threshold:
    'Bundles at or above this price get a bank account generated per payment and ' +
    'activate by themselves — OPay charges a fee for that. Cheaper bundles stay on ' +
    'the free receipt-and-approve path. Set 0 to keep everything manual.',
  opay_secret_key: 'Write-only. Saved but never shown again.',
  opay_public_key: 'Write-only. Saved but never shown again.',
  tether_grace_hits:
    'Roughly minutes of detected hotspot sharing before the Live tab flags the customer.',
};

async function loadSettings() {
  try {
    const { settings } = await API.get('api/admin.php?action=settings');
    document.getElementById('setFields').innerHTML = Object.entries(SETTING_LABELS).map(([k, label]) => `
      <div class="field">
        <label for="set-${k}">${label}</label>
        <input id="set-${k}" data-k="${k}" value="${esc(settings[k] ?? '')}">
        ${SETTING_HINTS[k] ? `<div class="hint">${esc(SETTING_HINTS[k])}</div>` : ''}
      </div>`).join('');
  } catch (err) { toast(err.message, true); }
}

async function saveSettings() {
  const settings = {};
  document.querySelectorAll('#setFields [data-k]').forEach(el => { settings[el.dataset.k] = el.value; });
  try {
    await API.post('api/admin.php?action=setting_save', { settings });
    toast('Settings saved');
  } catch (err) { toast(err.message, true); }
}

/* ---- wiring -------------------------------------------------------
   No executable code in the markup: the CSP is script-src 'self', and
   an admin panel that approves payments is the last place to relax it.
   ------------------------------------------------------------------ */
on('admin-login',      () => doLogin());
on('admin-logout',     () => doLogout());
on('save-settings',    () => saveSettings());
on('new-plan',         () => newPlan());
on('search-customers', () => loadCustomers());
on('tab',              (el) => showTab(el.dataset.tab));
on('report-days',      () => loadReports());
on('approve',          (el) => approve(Number(el.dataset.id)));
on('reject',           (el) => reject(Number(el.dataset.id)));
on('save-plan',        (el) => savePlan(Number(el.dataset.id)));
on('toggle-ban',       (el) => toggleBan(Number(el.dataset.id), el.dataset.status));
on('set-devices',      (el) => setDevices(Number(el.dataset.id), el.value));
