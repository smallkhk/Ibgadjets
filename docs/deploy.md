# Deploying IB Gadgets Telecom

Two halves: the site on Hostinger, the router in the compound. Do the site
first — the router script needs a live endpoint to talk to.

---

## The short version

```bash
bash build-deploy.sh          # -> dist/ibgadgets-deploy.zip + ibgadgets-router.zip
```

Then, in order:

| # | Do this | Where |
|---|---|---|
| 1 | Buy hosting + domain, point the domain at it | Hostinger |
| 2 | Upload `private/` and `public_html/` from the zip | File Manager |
| 3 | Create a MySQL database and user | cPanel → MySQL Databases |
| 4 | Import `db/schema.sql`, then `db/seed.sql` | phpMyAdmin |
| 5 | `cp private/config.example.php private/config.php`, fill it in | Terminal |
| 6 | Generate the sync key, paste it into config **and** keep a copy | Terminal |
| 7 | `php private/make-admin.php you@… 'password' owner` | Terminal |
| 8 | `chmod 750 private/uploads` | Terminal |
| 9 | Turn on free SSL for the domain | cPanel → SSL |
| 10 | Add the hourly cron for `expire.php` | cPanel → Cron Jobs |
| 11 | Log in at `/admin.html`, set your **real bank details** | Admin → Settings |
| 12 | Decide whether to switch the free trial on | Admin → Free trial |
| 13 | Run the smoke test (below) | Terminal or your laptop |

Step 11 is not optional decoration: the seed ships the bank details as
`Change me in Admin > Settings` / `0000000000`, and a customer who
follows those instructions sends your money to nobody.

Step 12 ships **off**. Nothing is given away until you turn it on.

Then the router — see part 2. Everything is explained in full underneath.

### Prove it works before you tell anyone

**This is the last step, not the first — the site has to be live first.**

Where to type it: **hPanel → Advanced → Terminal** (or SSH). You are then
sitting in `/home/USER`, which is where you uploaded everything.

```bash
cd ~
BASE=https://ibphone.eclipselivecam.online \
SYNC_KEY=$(php -r 'echo (require "private/config.php")["sync_key"];') \
ADMIN_EMAIL=youremail@example.com \
ADMIN_PASS='the password you gave make-admin.php' \
  bash tests/e2e.sh
```

Only two things to fill in: **your admin email** and **your admin
password** — the exact pair you passed to `make-admin.php`. The sync key
reads itself out of `private/config.php`, so there is nothing to copy or
paste wrong.

50 checks: signup, purchase, approval, receipt upload, the router seeing
the customer with the right limits, remaining-data arithmetic, usage
flowing back, a router reset not erasing spent data, a top-up not being
eaten by the previous bundle's usage, the device-allowance override,
suspension, forgotten-password recovery, the revenue reports and the free
trial. All 50 should pass.

If signup reports a rate limit, that is the limiter working — wait ten
minutes, or clear it:

```bash
mysql -u DBUSER -p DBNAME -e "DELETE FROM rate_limits WHERE bucket LIKE 'signup:%';"
```

**No Terminal on your plan?** Run it from any Mac or Linux machine, or
Windows with Git Bash — it only needs `bash`, `curl` and `php`, and it
talks to your site over HTTPS like any other client. Clone the repo,
then use the same command with `SYNC_KEY=` set to the value you put in
`private/config.php`.

**Tidy up after.** Once it passes, delete `tests/` and `db/` from the
server. Neither is web reachable, but nothing on a production box should
be there without a reason.

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
/system reset-configuration skip-backup=yes
```

Note there is **no** `no-defaults=yes`. `setup.rsc` builds on the factory
default configuration rather than replacing it, and reconnects on
`192.168.88.1`.

That is a deliberate change from how this started. The old version made
its own bridge on `10.5.50.0/24` and moved every port onto it, which
worked — until the router factory-reset itself after a kernel panic and
took the addressing, the pool and the DHCP server with it. The hotspot
server came back flagged `I` for invalid with nothing obvious to blame.
Defconf already supplies a bridge holding every LAN port and both radios,
an address, a pool and a working DHCP server, so building on top of it
means a reset leaves far less to put back — and what is left is exactly
what this file adds.

Reconnect with Winbox, then upload `router/setup.rsc` to Files and:

```
/import setup.rsc
```

Check it came up clean — no `I` flag on the hotspot server:

```
/ip hotspot print
/ip address print
```

An invalid hotspot server almost always means the bridge has no IP
address. No amount of hotspot configuration fixes that; fix the address
first.

The domain is already set to `ibphone.eclipselivecam.online` throughout —
`set-domain.sh` did that. Only change it if you switch domains, and use
that script rather than editing by hand, because the walled garden breaks
silently if one of the six references is missed.

Optionally edit before importing:

- `ssid=` — if you want a different network name
- `/ip service set ssh port=` and the winbox address range

### The captive portal page

Upload `router/hotspot/login.html` to the router's `hotspot` directory,
replacing the stock one. It is fully self-contained — no CDN fonts, no
external images — because a customer who has not paid yet cannot reach the
internet to load them.

Check that `md5.js` is still in that folder. RouterOS ships it, and the
CHAP block needs it — the page only enables CHAP when the router offers
it, so a missing md5.js means passwords cross the air in clear rather
than a visible error.

The "Create account & buy data" link already points at your domain.

### The sync script

Edit `router/router-sync.rsc` — **one line**:

- `ibgKey` — the sync key from `private/config.php`, pasted exactly

`ibgUrl` already points at
`https://ibphone.eclipselivecam.online/api/router-sync.php`.

**Do not `/import` it.** Paste `router/ibg-sync-source.txt` into
Winbox → System → Scripts → Add instead, named `ibg-sync`, with the
read/write/policy/test/sensitive policies ticked. RouterOS's importer
cannot be trusted with a `source={ }` block containing nested braces and
`$variables`: it fails silently and the script simply never appears.

The scheduler entry was already created by `setup.rsc`, so once the
script exists it starts running within a minute.

### Remote access to the router (free, do this before you leave site)

Starlink's CGNAT means nothing can dial into the router, so by default you
can only change its configuration while standing on the compound WiFi.
That is fine until the day it misbehaves and you are somewhere else.

MikroTik's **Back To Home** solves it for free. It is WireGuard, built into
RouterOS 7.12+, and when the router has no public IP the connection runs
through MikroTik's relay servers — CGNAT is not an obstacle. Traffic stays
end-to-end encrypted; the relay never sees the keys. It needs an ARM,
ARM64 or TILE device, and the hAP ac² is ARM.

In Winbox, while on site:

1. **IP → Cloud → Back To Home**, enable it
2. Add a user, which produces a QR code
3. Install the *MikroTik Back To Home* app on your phone, scan it
4. Test it on mobile data with the compound WiFi **off** — that is the
   only test that proves CGNAT is really being traversed

Then let Winbox through on the tunnel. `setup.rsc` deliberately restricts
Winbox to the hotspot subnet, which would lock out the tunnel too:

```
/interface wireguard print          # find the Back To Home interface
/ip address print                   # note the address it was given
/ip service set winbox address=192.168.88.0/24,<the BTH subnet>
```

**Do not instead open Winbox to the internet.** Exposed Winbox ports have
been mass-exploited more than once, and a compromised router means every
customer's traffic. The tunnel is the answer; a port forward is not.

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

**The router reboots every 60 seconds.** Disable the scheduler first —
match on what it runs, not its name, because the one on the live router
is called `ibg-loop`:

```
/system scheduler disable [find on-event~"ibg-sync"]
```

Then work through `router/diag/README.md`. Four separate RouterOS 7.24
faults were found this way on the live box, three of them firmware bugs
rather than script mistakes: `rate-limit` on `/ip hotspot user` (rejected
outright), `/ip hotspot active remove`, a POST with `output=user
as-value`, and an array keyed by a number. Every one of them panicked the
kernel rather than raising a catchable error, which is why `:do/on-error`
could not save it.

**Customers say the site is down, but it loads fine for you.** The host
runs Imunify360 WebShield, which answers requests that look like they
want a web page with a JavaScript challenge — 11 KB of HTML under a
`200 OK`. Browsers solve it invisibly. The router cannot, which is why
the sync script sends `Accept: application/json`; measured on this host,
34 of 34 requests carrying that header came back as JSON while 14 of 34
without it were challenged. If sync stops, check that header first, and
consider asking Hostinger to exclude `/api/router-sync.php` from
WebShield — the header works because of how WebShield currently scores
requests, and that can change without warning.

**A customer cannot attach their receipt.** They are almost certainly in
the WiFi sign-in window — Android's `CaptivePortalLogin`, iOS's Captive
Network Assistant — which is a stripped-down browser with no working file
picker. Nothing server-side fixes it. The checkout screen offers "I have
sent the money — no receipt" for exactly this, and the payment was
already waiting for approval anyway; the receipt is evidence, not a gate.
Tell them they can also open the site in Chrome or Safari.

**A top-up arrived already spent.** Fixed, but it needs both halves
deployed: the site must be current *and* the router must be running a
script that resets its byte counters when a subscription id changes.
RouterOS counters belong to the hotspot user and carry across bundles, so
with only one half in place a customer who used 4 GB and bought 5 GB more
gets a bundle with 1 GB on it.

---

## Updating with git (do this instead of re-uploading zips)

The repository is laid out as `public_html/` + `private/`, but a
Hostinger subdomain's document root is a folder like `~/ibphone` with
`private/` beside it, so `git pull` alone cannot update the site — the
files still have to be placed. `deploy-to.sh` does that placement and
nothing else.

### One-time setup

```bash
cd ~
git clone -b claude/spec-review-qzdzep https://github.com/smallkhk/Ibgadjets.git src
cd src
bash deploy-to.sh ~/ibphone --dry-run    # look first
bash deploy-to.sh ~/ibphone              # then apply
```

If the repository is private, git will ask for a password — GitHub no
longer accepts your account password there. Create a fine-grained
personal access token with read access to this repository
(GitHub → Settings → Developer settings → Personal access tokens) and
use it as the password, or clone with it inline:

```bash
git clone -b claude/spec-review-qzdzep \
  https://YOUR_GITHUB_USERNAME:YOUR_TOKEN@github.com/smallkhk/Ibgadjets.git src
```

### Every update after that

```bash
cd ~/src && git pull && bash deploy-to.sh ~/ibphone
```

Then hard-refresh the browser (Ctrl+Shift+R) or you will be looking at
cached JavaScript and think nothing changed.

### Database migrations — the step that is easy to miss

`deploy-to.sh` places files. It does not touch the database, on purpose:
a deploy script that quietly alters tables is a deploy script that can
lose data. When an update needs new columns they arrive as a numbered
file in `db/migrations/`, and you run it once, by hand.

```bash
mysql -u USER -p DBNAME < ~/src/db/migrations/004-free-trial.sql
```

| File | What it adds | Symptom if you skip it |
|---|---|---|
| `002-opay-bank-transfer.sql` | OPay order fields on transactions | Checkout fails on the automated path |
| `003-security-question.sql` | Password recovery columns | Signup rejects everyone: "Unknown column security_question" |
| `004-free-trial.sql` | `is_trial`, `trial_claimed_at`, the trial plan | The Free trial tab says no trial plan exists |

Run them in order, and only the ones your database has not had. They are
written for an EXISTING database — a brand new install gets everything
from `db/schema.sql` and `db/seed.sql`, and running a migration on top of
that stops at "Duplicate column name" without applying the rest of the
file.

If a page starts returning "Something went wrong" right after an update,
this is the first thing to check. Turn on `'debug' => true` in
`private/config.php` for a moment and the real SQL error will say which
column is missing.

### What it will never overwrite

- `private/config.php` — your database password and sync key
- `private/uploads/` — customers' bank receipts

Both are excluded deliberately, not by accident, and the script reports
that it left them intact on every run. Losing the first takes the site
down; losing the second loses your evidence that people paid.

---

## OPay automated collection (optional)

Two payment paths run side by side. Which one a customer gets depends on
the price of what they are buying.

- **At or above `opay_auto_threshold`** — OPay generates a bank account
  for that single order. The customer transfers, OPay tells us, the
  bundle activates itself. Costs a fee per transaction.
- **Below it** — your own account, a reference in the narration, a
  receipt, and an admin pressing Approve. Costs nothing.

Set the threshold in **Admin → Settings**. `0` keeps everything manual,
which is where you start.

### Turning it on

1. Get your Merchant ID, public key and secret key from the OPay merchant
   dashboard.
2. **Admin → Settings**: fill in `opay_merchant_id`, `opay_public_key`,
   `opay_secret_key`, set `opay_enabled` to `1`, and leave `opay_live` at
   `0` while you test against staging.
3. Set your callback URL in the OPay dashboard to
   `https://yourdomain/api/payments.php?action=opay_callback`
4. Set `opay_auto_threshold` to the price above which a fee is worth
   paying. ₦3,000 is a sensible starting point — it covers the visitor
   bundles and Household Month while leaving the ₦500 and ₦1,500 sales
   free to process.
5. Test end to end on staging, then set `opay_live` to `1`.

Secret and public keys are **write-only**: once saved the panel shows a
mask, and saving without touching the field keeps the stored value. They
never travel back to a browser.

### The reconciliation cron — do not skip this

Webhooks get lost. Add a second cron, every 5 minutes:

```
/usr/local/bin/php /home/USER/public_html/cron/opay-reconcile.php
```

It pulls the real status for any OPay payment still pending after a few
minutes and activates anything OPay calls SUCCESS. Without it, a lost
callback means a customer who paid and never got switched on — the worst
failure this system has, and the one they tell their neighbours about.

### Two different signatures

Worth knowing before you debug anything:

| Direction | Algorithm |
|---|---|
| Requests you send | HMAC-**SHA512** over the JSON body |
| Callbacks you receive | HMAC-**SHA3-512** over a rebuilt field string |

The callback signature arrives in a field named `sha512`, which is not
SHA-512. `tests/test-opay.php` pins this behaviour so nobody later
"corrects" it into silently rejecting every callback.
