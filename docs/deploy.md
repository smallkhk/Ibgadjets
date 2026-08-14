# Deploying IB Gadgets Telecom

Two halves: the site on Hostinger, the router in the compound. Do the site
first — the router script needs a live endpoint to talk to.

---

## 1. The site (Hostinger cPanel)

### Files

Your home directory should end up looking like this:

```
/home/USER/
├── private/            <- NOT web reachable
│   ├── config.php      <- you create this
│   ├── bootstrap.php
│   ├── lib/
│   ├── make-admin.php
│   └── uploads/        <- receipts land here
└── public_html/        <- document root
    ├── index.html
    ├── dashboard.html
    ├── admin.html
    ├── assets/
    ├── api/
    └── cron/
```

Upload `private/` and `public_html/` from this repo to exactly those spots.
`private/` must sit **beside** `public_html`, not inside it.

```bash
mkdir -p ~/private/uploads
chmod 750 ~/private/uploads
```

### Database

cPanel → MySQL Databases. Create a database and a user, give the user all
privileges. Then in phpMyAdmin (or Terminal):

```bash
mysql -u USER -p DBNAME < db/schema.sql
mysql -u USER -p DBNAME < db/seed.sql
```

### Configuration

```bash
cp private/config.example.php private/config.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"   # your sync key
```

Edit `private/config.php`: database credentials, `site_url`, and paste the
sync key you just generated. Keep that key — the router needs the same
string.

### First admin

```bash
php ~/private/make-admin.php you@example.com 'a-strong-password' owner
```

Then sign in at `https://yourdomain/admin.html` and fill in
**Settings** — bank name, account name, account number, support numbers.
Those are what customers see at checkout, and the seed leaves them as
placeholders on purpose.

### Cron

cPanel → Cron Jobs, hourly:

```
/usr/local/bin/php /home/USER/public_html/cron/expire.php
```

### Check it

```bash
curl -s https://yourdomain/api/plans.php | head -c 300
```

You should get JSON with ten plans. If you get an HTML error page, check
`private/config.php` exists and the database credentials are right.

---

## 2. The router (Mikrotik hAP ac²)

### Wipe and configure

```
/system reset-configuration no-defaults=yes skip-backup=yes
```

Reconnect with Winbox (MAC-connect — there is no IP yet), then upload
`router/setup.rsc` to Files and:

```
/import setup.rsc
```

Before importing, edit these in the file:

- `dst-host=ibgadgets.ng` — your real domain, in all four walled-garden lines
- `ssid=` — if you want a different network name
- `/ip service set ssh port=` and the winbox address range

### The captive portal page

Upload `router/hotspot/login.html` to the router's `hotspot` directory,
replacing the stock one. It is fully self-contained — no CDN fonts, no
external images — because a customer who has not paid yet cannot reach the
internet to load them.

Check that `md5.js` is still in that folder; RouterOS ships it and the
CHAP login needs it.

Edit the "Create account & buy data" link in that file to your real domain
if you changed it.

### The sync script

Edit `router/router-sync.rsc`:

- `ibgUrl` — `https://yourdomain/api/router-sync.php`
- `ibgKey` — the sync key from `private/config.php`, exactly

Upload, then:

```
/import router-sync.rsc
```

That defines the script. The scheduler entry was already created by
`setup.rsc`, so it starts running within a minute.

### Watch the first cycle

On the router:

```
/log print where message~"ibg-sync"
/system script run ibg-sync
```

On the site: **Admin → Overview → Router conversation**. You should see
`report` rows appearing every minute. If you see `auth_fail`, the two keys
do not match.

---

## 3. End-to-end test

1. Sign up on the site with a real phone number.
2. Buy the cheapest bundle, choose bank transfer.
3. In Admin → Payments, approve it without actually transferring anything.
4. Wait 60 seconds. On the router: `/ip hotspot user print` — the account
   should be there with the right `limit-bytes-total` and `shared-users`.
5. Connect a phone to the WiFi, log in with that phone number.
6. Disconnect and reconnect. It should come back online **without** the
   login page. That is the mac-cookie working.
7. Check the dashboard shows data climbing.

---

## Things that go wrong

**Customers can reach the site but pages look broken.** The walled garden
allows your domain but not the fonts CDN. That is fine and expected — the
site is styled by `assets/css/app.css`, served from your own domain, and
falls back to system fonts. Do not add Google to the walled garden; it is
free internet for anyone who knows.

**Router says `auth_fail` in the sync log.** The key in
`router-sync.rsc` does not match `private/config.php`. Watch for trailing
spaces when pasting.

**Receipts upload but admins see a broken image.** `private/uploads` is
not writable, or `upload_dir` in config points somewhere else. It must be
an absolute path.

**Everyone got free data after a router reset.** They should not have —
the sync endpoint sends *remaining* data, not the plan allowance, exactly
so a wipe restores balances rather than refilling them. If it happened,
check that the router's usage POSTs were actually landing before the
reset (Admin → Overview → Router conversation).
