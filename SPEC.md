# IB Gadgets Telecom — Build Spec

Community WiFi portal. One Starlink dish, shared to a compound and to outside customers who buy data bundles.

---

## ARCHITECTURE (decided — do not redesign)

```
Hostinger cPanel (PHP + MySQL)     Mikrotik hAP ac² (RBD52G-5HacD2HnD)
├── Public site + plans            ├── Hotspot (captive portal)
├── Signup / login                 ├── User Manager (enforces quota/time/speed)
├── Payment + ledger               ├── Walled garden (lets unpaid users reach site)
├── Customer dashboard             └── Scheduler script → polls site every 60s
├── Admin panel
└── api/router-sync.php  ←─────────────────┘
```

**Key principle:** the router enforces everything locally. Once a User Manager account exists with its limits, the router counts data, counts time, caps speed, and cuts the session off by itself. The site never has to reach into the router — the router reaches out.

**Why:** Starlink uses CGNAT, so nothing can connect *into* the router. All traffic is router → site.

### Explicitly rejected
- EC2 / VPS — not needed, Hostinger is enough
- Local PC in the compound — not needed
- Standalone RADIUS server — shared hosting can't run it; User Manager replaces it
- Direct RouterOS API calls from the server — CGNAT blocks it

---

## STACK

- PHP 8.x, MySQL — Hostinger cPanel
- Vanilla JS frontend (already built, see `ib-gadgets-portal.html`)
- Sessions for auth, bcrypt for passwords
- Cron via cPanel for expiry sweeps

---

## DATABASE

```sql
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  phone VARCHAR(20) UNIQUE NOT NULL,
  full_name VARCHAR(120),
  password_hash VARCHAR(255) NOT NULL,
  type ENUM('compound','visitor') DEFAULT 'compound',
  flat_no VARCHAR(20) NULL,
  wallet_naira DECIMAL(10,2) DEFAULT 0,
  status ENUM('active','suspended') DEFAULT 'active',
  router_username VARCHAR(60) UNIQUE,
  router_password VARCHAR(60),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE plans (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  subtitle VARCHAR(200),
  audience ENUM('compound','visitor') NOT NULL,
  price_naira DECIMAL(10,2) NOT NULL,
  data_mb INT NULL,              -- NULL = unlimited
  speed_down_mbps INT NOT NULL,
  speed_up_mbps INT NOT NULL,
  validity_hours INT NOT NULL,
  max_devices TINYINT DEFAULT 1,
  tier TINYINT DEFAULT 2,        -- 1-4, drives the signal-bar graphic
  featured TINYINT(1) DEFAULT 0,
  active TINYINT(1) DEFAULT 1
);

CREATE TABLE subscriptions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  plan_id INT NOT NULL,
  started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  data_used_mb DECIMAL(12,2) DEFAULT 0,
  status ENUM('active','expired','cancelled') DEFAULT 'active',
  sync_state ENUM('pending','synced','revoke_pending','revoked') DEFAULT 'pending',
  synced_at DATETIME NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (plan_id) REFERENCES plans(id)
);

CREATE TABLE transactions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  customer_id INT NOT NULL,
  plan_id INT NULL,
  amount_naira DECIMAL(10,2) NOT NULL,
  method ENUM('opay','usdt_bsc','usdt_tron','wallet','manual') NOT NULL,
  reference VARCHAR(120),
  tx_hash VARCHAR(160) NULL,
  status ENUM('pending','success','failed') DEFAULT 'pending',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(120) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','staff') DEFAULT 'staff'
);

CREATE TABLE settings (
  k VARCHAR(60) PRIMARY KEY,
  v TEXT
);

CREATE TABLE sync_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  action VARCHAR(40),
  payload TEXT,
  result VARCHAR(200),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

Seed `plans` from the PLANS array inside `ib-gadgets-portal.html` — it already has all ten bundles with correct pricing.

---

## PHP FILES TO BUILD

```
public_html/
├── index.html              (the built frontend — do not redesign)
├── config.php              db creds, secrets, constants
├── db.php                  PDO connection
├── api/
│   ├── auth.php            ?action=signup|login|logout|me
│   ├── plans.php           GET all active plans
│   ├── purchase.php        create subscription + transaction
│   ├── payments.php        ?action=submit|status|opay_callback
│   ├── dashboard.php       current sub, data used, expiry, history
│   ├── admin.php           ?action=customers|plans|transactions|sessions|settings|ban
│   └── router-sync.php     ← the router calls this. secret-key protected.
└── cron/
    └── expire.php          hourly: mark expired subs, flip to revoke_pending
```

---

## THE SYNC ENDPOINT (most important file)

`api/router-sync.php` — the only bridge between site and router.

**Auth:** router sends `X-Sync-Key: <long random secret>` header. Reject anything else with 403. Also rate-limit.

**GET** — router asks what changed:
```json
{
  "provision": [
    {
      "username": "08031112222",
      "password": "k9x2mq",
      "data_mb": 5000,
      "validity_hours": 24,
      "rate_limit": "20M/5M",
      "sub_id": 412
    }
  ],
  "revoke": [
    { "username": "08104445555", "sub_id": 388 }
  ]
}
```
- `provision` = subscriptions where `sync_state='pending'`
- `revoke` = subscriptions where `sync_state='revoke_pending'` (expired, refunded, or customer banned)
- `data_mb: null` means unlimited — omit the limit on the router side
- `rate_limit` format is RouterOS native: `"20M/5M"` is down/up

**POST** — router confirms what it did:
```json
{ "provisioned": [412], "revoked": [388] }
```
Flip those rows to `synced` / `revoked`, stamp `synced_at`, write to `sync_log`.

**POST usage** — router reports consumption so the dashboard is accurate:
```json
{ "usage": [ {"username":"08031112222","used_mb":1834} ] }
```
Update `subscriptions.data_used_mb`. This is display only — the router is the source of truth for cutoff.

---

## ROUTEROS SCRIPT

Write `router-sync.rsc` for RouterOS v7. Runs on `/system scheduler`, interval 60s.

It must:
1. `/tool fetch` the sync endpoint with the secret header, save to a local file
2. Parse the JSON (RouterOS 7 has `:deserialize from=json`)
3. For each `provision`: create a User Manager user with the limits, or update if it exists
4. For each `revoke`: disable the user and kick any active session
5. Collect current usage per active user
6. POST back the confirmation + usage payload
7. Log failures, never crash the scheduler

Also produce the one-time setup config: bridge, hotspot server, walled garden entries, the scheduler entry, and the User Manager profiles.

**Walled garden must whitelist:**
- `ibgadgets.ng` and `*.ibgadgets.ng` (or whatever the real domain is)
- OPay checkout domains
- Nothing else

---

## CAPTIVE LOGIN PAGE

`login.html` (provided separately) goes on the router at `/hotspot/login.html`, served locally.

It must work with **zero internet** — no CDN fonts, no external images, no external scripts. It posts to the router's own hotspot handler using the standard Mikrotik variables (`$(link-login-only)`, `$(chap-id)`, `$(chap-challenge)`, `$(error)`).

Its "Create account / Buy data" button links to the walled-garden domain, which is reachable without a plan.

---

## PAYMENTS

**Build the ledger first, stub the gateway.** OPay merchant approval takes time; the system must work without it.

- `transactions` table + admin "mark as paid" button = usable from day one
- OPay: init call, redirect, callback handler that verifies signature server-side, then activates the subscription
- USDT: BSC BEP-20 and TRON TRC-20. Customer pastes tx hash, server verifies on-chain (public RPC / TronScan), credits after 3 confirmations. Naira/USDT rate stored in `settings`.

Activation is the same code path for every method: payment confirmed → create subscription with `sync_state='pending'` → router picks it up within 60 seconds.

---

## RULES

- **Do not redesign the frontend.** `ib-gadgets-portal.html` is final. Wire it to real endpoints, split it into pages if needed, keep every visual decision.
- One account = one device. Enforce via `max_devices` on the User Manager profile.
- Cap advertised speeds at 30 Mbps — the 2.4GHz radio can't do better in practice.
- Secrets in `config.php`, never in client JS. Keep it out of git.
- Escape everything, prepared statements only, rate-limit auth and sync.
- Nigerian phone number is the login identifier, not email.
- All money in Naira. Store as DECIMAL, never float.

---

## BUILD ORDER

1. Schema + `config.php` + `db.php`, seed the plans
2. `auth.php` — signup and login working against the real form
3. `plans.php` + `dashboard.php` — frontend showing real data
4. `purchase.php` + admin "mark as paid" — full flow without a gateway
5. `router-sync.php` + the RouterOS script — test with a fake router calling curl
6. `admin.php` — customers, bans, plan editing
7. `cron/expire.php`
8. OPay, then USDT

Stop after each step and confirm it works before moving on.
