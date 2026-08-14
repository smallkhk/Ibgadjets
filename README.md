# IB Gadgets Telecom

Community WiFi portal for a compound in Ibadan running off one Starlink
dish. Customers buy data bundles in Naira; a Mikrotik router enforces
every bundle locally.

- **`SPEC.md`** — the design, and why each decision went the way it did
- **`docs/deploy.md`** — getting it live on Hostinger and the router
- **`docs/anti-sharing.md`** — how one person is stopped from serving the whole compound
- **`docs/vps-vs-cpanel.md`** — what a VPS would buy you, and when to bother

---

## How it fits together

Starlink is behind CGNAT, so nothing on the internet can open a connection
*into* the router. Everything therefore runs the other way: the router
polls the site every 60 seconds, asks what should be true, and makes it
true locally.

```
Hostinger (PHP + MySQL)                    Mikrotik hAP ac²
  money, accounts, approvals    ←──poll──   enforcement: data, speed, devices
```

The site is the authority on **who has paid**. The router is the authority
on **who is browsing**. `api/router-sync.php` is the only thing that
connects them.

The sync is **state reconciliation, not deltas**: the GET returns every
account that should work right now, and the router creates, updates or
deletes to match. A dropped reply, a reboot or a factory reset all heal on
the next poll instead of leaving the two sides quietly disagreeing.

## Layout

```
private/          config, libraries, uploads — outside the document root
public_html/      the site, the API, the cron
router/           setup.rsc, router-sync.rsc, the captive portal page
db/               schema.sql, seed.sql
docs/             deployment and design notes
tests/            helper tests, no database needed
```

## Running the tests

```bash
php tests/test-helpers.php
```

Covers phone and MAC normalisation, data and validity formatting, the
RouterOS rate-limit string, and the device-allowance override rules. No
database required.

## Before you deploy

1. `cp private/config.example.php private/config.php` and fill it in
2. Generate a sync key: `php -r "echo bin2hex(random_bytes(32));"`
3. Put that same key in `router/router-sync.rsc`
4. Import the schema and seed
5. `php private/make-admin.php you@example.com 'password' owner`
6. Set your real bank details in **Admin → Settings** — the seed ships
   placeholders on purpose

Full walkthrough in `docs/deploy.md`.

## Payments

Bank transfer with a receipt is the primary method: the customer gets a
reference, transfers, uploads a screenshot, and an admin approves. USDT on
BSC and TRON work the same way with a pasted transaction hash. OPay is
stubbed behind a setting until the merchant account is approved.

Every method ends at the same `activate_subscription()` call, so there is
one activation path to trust.
