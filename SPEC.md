# IB Gadgets Telecom — Build Spec

Community WiFi portal. One Starlink dish, shared to a compound and to
outside customers who buy data bundles.

> **Revision 2.** The architecture is unchanged. What changed is the sync
> protocol (desired state instead of deltas), the payment path (bank
> transfer and receipt first, OPay later), how devices are remembered
> (mac-cookie), and the addition of anti-sharing enforcement. Everything
> that changed from revision 1 is marked **[r2]** with the reason.

---

## ARCHITECTURE (decided — do not redesign)

```
Hostinger cPanel (PHP + MySQL)     Mikrotik hAP ac² (RBD52G-5HacD2HnD)
├── Public site + plans            ├── Hotspot (captive portal)
├── Signup / login                 ├── Hotspot users (quota/time/speed)
├── Payment + ledger               ├── Walled garden (unpaid users reach site)
├── Customer dashboard             ├── TTL rules (catch hotspot sharing)
├── Admin panel                    └── Scheduler script → polls site every 60s
└── api/router-sync.php  ←─────────────────┘
```

**Key principle:** the router enforces everything locally. Once the
account exists with its limits, the router counts data, caps speed, and
cuts the session off by itself. The site never reaches into the router —
the router reaches out.

**Why:** Starlink uses CGNAT, so nothing can connect *into* the router.
All traffic is router → site.

### Explicitly rejected

- EC2 / VPS — not needed today. See `docs/vps-vs-cpanel.md` for exactly
  what a VPS would buy and when to move. **[r2]**
- Local PC in the compound — not needed
- Standalone RADIUS server — shared hosting can't run it
- Direct RouterOS API calls from the server — CGNAT blocks it
- Flutterwave / Paystack — gateway fees on every sale, and the manual
  path costs nothing. OPay stays the eventual automated option. **[r2]**

---

## STACK

- PHP 8.x, MySQL — Hostinger cPanel
- Vanilla JS frontend, no build step
- Sessions for auth, bcrypt for passwords
- Cron via cPanel for the housekeeping sweep

---

## THE SYNC PROTOCOL **[r2]**

This is the heart of the system and the biggest change from revision 1.

Revision 1 sent **deltas**: "provision these, revoke those", tracked by a
`sync_state` machine. That is only correct if every confirmation arrives.
It won't — routers reboot mid-cycle, fetches time out, boxes get
reflashed. When a confirmation is lost, the site believes accounts are
live that the router has never heard of, and nothing ever notices.

Revision 2 sends **desired state**:

```
GET  → "here is every account that should be able to browse right now"
POST → "here is what I applied, and what everyone has used"
```

The router reconciles: create what is missing, update what drifted, delete
anything carrying our marker that is not on the list. Idempotent by
construction. A lost reply, a reboot, a factory reset — all heal on the
next poll.

`sync_state` still exists, but as a **log of what happened**, not as the
thing driving correctness.

### GET response

```json
{
  "ok": true,
  "poll_seconds": 60,
  "tether_policy": "flag",
  "users": [
    {
      "username": "08031112222",
      "password": "k9x2mq",
      "sub_id": 412,
      "data_mb": 3286,
      "rate_limit": "30M/8M",
      "shared_users": 2,
      "seconds_left": 61200
    }
  ],
  "blocked_macs": ["AA:BB:CC:DD:EE:FF"]
}
```

**`data_mb` is what is LEFT, not the plan allowance.** `data_mb -
data_used_mb`, computed per request. This is the line that makes a router
wipe survivable: re-provisioning from scratch restores balances instead of
handing everyone a fresh bundle. `null` means unlimited.

There is no `revoke` array. Not being listed *is* the revoke — expired,
spent, suspended and refunded are all the same case.

### POST body

```json
{
  "applied": [412, 413],
  "usage": [
    {"username":"08031112222","used_mb":1834,"mac":"AA:BB:…","ip":"10.5.50.7","tethered_hits":1}
  ]
}
```

Usage is stored with `GREATEST(data_used_mb, reported)` — the site's
figure only ever climbs within a subscription, so a router reset zeroing
its counters cannot erase what someone already spent.

**Auth:** `X-Sync-Key` header, constant-time compare, rate limited. No
cookie, so CSRF does not apply.

---

## HOTSPOT USERS, NOT USER MANAGER **[r2]**

Revision 1 specified User Manager. We use `/ip hotspot user` instead,
which natively provides everything needed:

| Need | Field |
|---|---|
| Data cap | `limit-bytes-total` |
| Speed cap | `rate-limit` (`"30M/8M"`) |
| Simultaneous devices | `shared-users` |
| Wall-clock expiry | the server drops them off the list |

User Manager v7 is considerably more fiddly to script and adds a layer
that buys nothing for a single router.

Note the last row: hotspot `limit-uptime` counts *session* time, not
calendar time, so it is the wrong tool for "valid 24 hours". The server
owns expiry — when `expires_at` passes, the account stops being listed
and the router deletes it on the next pass. Expiry therefore works even
if the cron never runs.

---

## DEVICE MEMORY: MAC-COOKIE **[r2]**

The site cannot see MAC addresses — they do not survive past the first
router hop, and Hostinger is many hops away. So the router remembers
devices by itself.

`login-by=mac-cookie,http-chap` on the hotspot profile:

1. New device connects → captive portal → phone number and password, once
2. Router stores a cookie bound to that device's MAC
3. Every reconnect after that is silent

**The site authorises the person; the router remembers the device.** The
site never sends a MAC because it never needs one. Real MACs come back
*from* the router in the usage report, which is how the admin panel shows
devices and how a single phone gets blocked without touching the account.

`/ip hotspot ip-binding type=bypassed` was considered and rejected: a
bypassed device skips the hotspot entirely, so nothing counts its data or
caps its speed. It would be unlimited free internet for anyone approved
once.

---

## ANTI-SHARING **[r2]**

Four layers, documented in full in `docs/anti-sharing.md`:

1. **mac-cookie** — logging in is effortless, so nobody needs to pass
   their password around
2. **`shared-users`** — hard ceiling on simultaneous devices, set per
   customer by the admin
3. **TTL inspection** — packets arriving at TTL 63/127/254 came through a
   second router, i.e. a phone acting as a hotspot. No directly connected
   device produces those values. Flagged by default, blockable.
4. **Economics** — on metered bundles sharing burns the sharer's own
   data. It only really costs you on unlimited plans.

---

## DATABASE

Full schema in `db/schema.sql`, seed in `db/seed.sql`. Beyond revision 1:

| Addition | Why **[r2]** |
|---|---|
| `customers.device_limit` | admin override of the plan's device count |
| `devices` table | the MAC roster; block one phone, not the account |
| `sessions` table | `admin?action=sessions` had no table behind it |
| `transactions.proof_path` | uploaded bank receipts |
| `transactions.reference` UNIQUE | it goes in the transfer narration |
| `transactions.tx_hash` UNIQUE | stops ten people pasting one valid hash |
| `method` gains `bank_transfer` | the day-one payment path |
| `subscriptions.device_limit` | snapshot at purchase time |
| `plans.um_profile`, `sort_order` | router profile name, display order |
| `rate_limits` table | brute-force protection with no Redis available |
| indexes on the hot paths | `sync_state`, `(customer_id, status)`, queue |

---

## FILES

```
private/                      ← outside the document root
├── config.php                db creds, sync key, upload path (gitignored)
├── bootstrap.php
├── lib/{db,http,security,settings,billing}.php
├── make-admin.php
└── uploads/                  receipts, never web reachable

public_html/
├── index.html                public site
├── dashboard.html            customer account
├── admin.html                admin panel
├── assets/{css,js,img}/
├── api/
│   ├── auth.php              signup|login|logout|me
│   ├── plans.php             pricing grid + public settings
│   ├── purchase.php          start a purchase, issue the reference
│   ├── payments.php          proof|usdt|status|opay_callback
│   ├── dashboard.php         current bundle, usage, devices, history
│   ├── admin.php             everything the panel needs
│   ├── proof.php             serve a receipt to an admin only
│   └── router-sync.php       ← the router calls this
└── cron/expire.php

router/
├── setup.rsc                 one-time: bridge, hotspot, walled garden, TTL rules
├── router-sync.rsc           the 60s poller
└── hotspot/login.html        captive portal, fully offline

db/{schema,seed}.sql
docs/{deploy,anti-sharing,vps-vs-cpanel}.md
```

---

## PAYMENTS **[r2]**

**Bank transfer with a receipt is the primary method**, not a stopgap.
A manual approval costs a minute of admin time; a gateway costs a
percentage of every sale forever.

1. Customer picks a plan → gets the account number and a reference like
   `IB-4X9QM`
2. Transfers, putting the reference in the narration
3. Uploads the receipt screenshot
4. Admin checks the money landed → **Approve**
5. Router picks it up within 60 seconds

USDT (BSC BEP-20, TRON TRC-20) works the same way with a pasted tx hash.
OPay is stubbed behind `opay_enabled` — the signature check goes in
`payments.php` when the merchant account is approved.

Every method ends at `activate_subscription()`. One road in, one thing to
test.

**Receipt uploads are the security-critical surface.** Files land outside
`public_html`, named randomly, type decided by magic bytes and never by
the filename, and served back only through `api/proof.php` behind an admin
session.

---

## ADMIN PANEL **[r2]**

- **Bundles** — edit price, data, download cap, upload cap, validity
  hours, device count, tier, and live/hidden, per plan. Saving re-applies
  the limits to everyone currently on that bundle.
- **Customers** — search, suspend, restore, and set a **per-customer
  device allowance** that overrides the plan. Blank follows the plan.
- **Payments** — the approval queue, with the receipt one click away.
- **Live** — who is online, what they have used, and the sharing flag.
- **Settings** — bank details, support numbers, USDT wallets and rate,
  sharing policy. The sync key is deliberately not editable over HTTP.

---

## RULES

- **Frontend rebuilt at the owner's request** (r1 said do not redesign).
  Design tokens, palette and typography are carried over unchanged; the
  homepage is now image-led, with placeholder art in `assets/img/` meant
  to be swapped for real photos of the actual dish and compound. **[r2]**
- Device count comes from `max_devices`, overridable per customer. The
  r1 rule "one account = one device" is dropped — it contradicted both
  the plans on sale and the schema. **[r2]**
- The r1 rule "cap advertised speeds at 30 Mbps" is dropped. A plan's
  speed is a rate-limit ceiling, not a promise; setting `50M/10M` costs
  nothing when the radio cannot deliver it. Add a parent queue on the
  uplink if one customer starts starving the compound. **[r2]**
- Secrets in `private/config.php`, outside the document root, gitignored.
- Prepared statements only. Escape on output. Rate-limit auth and sync.
- Nigerian phone number is the login identifier, not email.
- All money in Naira, stored DECIMAL, never float.

---

## BUILD ORDER

1. ~~Schema + config + db, seed the plans~~ ✅
2. ~~`auth.php` — signup and login~~ ✅
3. ~~`plans.php` + `dashboard.php`~~ ✅
4. ~~`purchase.php` + `payments.php` + admin approve~~ ✅
5. ~~`router-sync.php` + the RouterOS scripts~~ ✅
6. ~~`admin.php` — customers, device allowance, plan editing~~ ✅
7. ~~`cron/expire.php`~~ ✅
8. **Deploy and test against the real router** — `docs/deploy.md`
9. OPay when the merchant account is approved, then on-chain USDT
   verification
