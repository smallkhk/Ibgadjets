/* =====================================================================
   Shared API client + small UI helpers.
   Used by index.html, dashboard.html and admin.html.
   ===================================================================== */

const API = (() => {
  let csrf = '';

  async function call(path, { method = 'GET', body = null, form = null } = {}) {
    const opts = {
      method,
      credentials: 'same-origin',
      headers: {},
    };

    if (method !== 'GET') {
      opts.headers['X-CSRF-Token'] = csrf;
    }

    if (form) {
      opts.body = form;                       // FormData sets its own boundary
    } else if (body) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }

    let res, data;
    try {
      res = await fetch(path, opts);
    } catch (networkError) {
      throw new Error('No connection to the server. Check your network.');
    }

    try {
      data = await res.json();
    } catch (parseError) {
      throw new Error('The server sent something unexpected (' + res.status + ').');
    }

    // The server rotates the CSRF token whenever it regenerates the
    // session. Pick the new one up so the next call is not rejected.
    if (data && typeof data.csrf === "string") { csrf = data.csrf; }

    if (!res.ok || data.ok === false) {
      const err = new Error(data.error || 'Something went wrong');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  return {
    setCsrf: (t) => { csrf = t || ''; },
    getCsrf: () => csrf,
    get:  (p)      => call(p),
    post: (p, b)   => call(p, { method: 'POST', body: b }),
    upload: (p, f) => call(p, { method: 'POST', form: f }),
  };
})();

/* ---------------------------------------------------------------- UI */

function toast(msg, bad = false) {
  const t = document.getElementById('toast');
  if (!t) { return; }
  t.textContent = msg;
  t.classList.toggle('bad', !!bad);
  t.classList.add('show');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.classList.remove('show'), 4200);
}

function openOv(id) {
  closeAll();
  const el = document.getElementById(id);
  if (el) {
    el.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
}

function closeAll() {
  document.querySelectorAll('.overlay').forEach(o => o.classList.remove('open'));
  document.body.style.overflow = '';
}

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') { closeAll(); }
});

/* ------------------------------------------------------------- format */

const ngn = n => '₦' + Number(n || 0).toLocaleString('en-NG');

const tickSvg = '<svg viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2.5 8.5l3.5 3.5 7.5-8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

function bars(tier) {
  let s = '';
  for (let i = 1; i <= 4; i++) {
    s += `<i class="${i <= tier ? 'on' : ''}" style="height:${6 + i * 4}px"></i>`;
  }
  return s;
}

/** Escape anything that came from the database before it touches innerHTML. */
function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

/** 93600 -> "1 day 2 hrs" */
function humanLeft(seconds) {
  if (seconds == null) { return '—'; }
  if (seconds <= 0) { return 'Expired'; }
  const d = Math.floor(seconds / 86400);
  const h = Math.floor((seconds % 86400) / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  if (d > 0) { return `${d} day${d > 1 ? 's' : ''} ${h} hr${h === 1 ? '' : 's'}`; }
  if (h > 0) { return `${h} hr${h > 1 ? 's' : ''} ${m} min`; }
  return `${m} min`;
}

function copyText(text, okMsg = 'Copied') {
  navigator.clipboard?.writeText(text)
    .then(() => toast(okMsg))
    .catch(() => toast('Copy it by hand: ' + text, true));
}


/* =====================================================================
   Event delegation.

   The Content-Security-Policy is script-src 'self' — no 'unsafe-inline'
   — so an onclick="" attribute or an inline <script> block is refused by
   the browser. That is deliberate on a site that approves payments: it
   is the control that stops injected markup from executing.

   The cost is that handlers cannot live in the HTML. Elements declare
   what they want instead:

     <button data-action="buy" data-plan="3">
     <form data-submit="auth">
     <select data-change="toggle-flat">

   and the page registers behaviour by name with on('buy', fn).
   ===================================================================== */

const Actions = {};

function on(name, fn) { Actions[name] = fn; }

function delegate(evt, attr, preventDefault) {
  document.addEventListener(evt, (e) => {
    const el = e.target.closest('[' + attr + ']');
    if (!el) { return; }
    const fn = Actions[el.getAttribute(attr)];
    if (!fn) { return; }
    if (preventDefault) { e.preventDefault(); }
    fn(el, e);
  });
}

delegate('click',  'data-action', true);
delegate('submit', 'data-submit', true);
delegate('change', 'data-change', false);

// Clicking an overlay's backdrop closes it; clicking the panel does not.
document.addEventListener('click', (e) => {
  if (e.target.classList && e.target.classList.contains('overlay')) { closeAll(); }
});
