# Stopping one person from serving the whole compound

The problem, stated plainly: Musa buys a 5GB bundle, logs in on his phone,
then turns on his personal hotspot. Now four people are browsing on one
bundle. From the router's point of view there is one MAC address, one
session, one customer.

There is no single switch that stops this. There are four layers, and
together they close it.

---

## Layer 1 — one login, bound to the device

`login-by=mac-cookie,http-chap` on the hotspot profile.

The customer types their phone number and password once. The router
stores a cookie against that device's MAC. Every reconnect after that is
silent — they walk in the gate and their phone is online.

This is comfort, not security, but it matters here: when logging in is
effortless, nobody has a reason to pass their password around.

## Layer 2 — a hard ceiling on simultaneous devices

`shared-users` on the hotspot user, set from `subscriptions.device_limit`
on every sync.

This is the number the admin controls. A 1-device bundle means the second
phone that tries to log in with those credentials is refused while the
first is online. Raise it per customer in **Admin → Customers → Devices**
and the router honours the new number within one poll.

This catches password-sharing. It does not catch tethering, because a
tethered device never talks to the router at all.

## Layer 3 — TTL inspection (this is the one that catches tethering)

Every IP packet carries a Time To Live counter. Each router that forwards
the packet subtracts one. Operating systems start it at a known value:

| System | Starting TTL |
|---|---|
| Android, iOS, Linux, macOS | 64 |
| Windows | 128 |
| Some network gear | 255 |

A phone connected straight to our WiFi arrives at the Mikrotik with TTL
**64**. A laptop connected straight to our WiFi arrives at **128**.

Now Musa turns on his hotspot. His friend's packets go *through Musa's
phone*, which routes them and decrements the counter. They arrive at
**63**. Or **127** if the friend is on Windows.

63 and 127 are values no directly connected device produces. That is the
tell, and it is not subtle — it is every single packet.

`router/setup.rsc` installs mangle rules that add the source address to
the `ibg-tethered` list whenever a packet arrives at 63, 127 or 254. Each
sync cycle the router checks whether the active user's IP is on that list
and reports `tethered_hits: 1` if so. The site accumulates that count, so
the number in **Admin → Live** is roughly *minutes spent sharing*.

### Flag or block

`tether_policy` in settings:

- **`flag`** (default) — record it, show it in the admin panel, let a
  human decide. Start here.
- **`block`** — enable the drop rule in `/ip firewall filter` and the
  tethered traffic simply stops. The tethering phone keeps working; only
  the devices behind it are cut off.

Flagging first is deliberate. A hard drop also breaks the honest edge
cases: someone running a travel router, a laptop with a VM in NAT mode, a
Chromecast behind a mesh puck. Watch the panel for a week, see who is
actually doing it, then decide.

### What it does not catch

A rooted Android with a TTL-fixing app can rewrite the counter to 64 and
walk straight through. That is a real hole, and there is no fix for it at
this layer. It is also a hole roughly nobody in a residential compound
will find, and if one person does, layer 4 catches the effect rather than
the method.

## Layer 4 — the economics

Worth saying out loud: **on metered bundles, sharing is self-limiting.**
If Musa serves four friends off his 5GB, his 5GB is gone in a morning and
he buys again. He is not stealing from you — he is buying more often.

The layers above matter most on **unlimited plans**, where one shared
connection really can serve a whole compound for ₦3,500. If you want one
control rather than four, that is the one: keep unlimited bundles at a
price that assumes they will be shared, or run `tether_policy=block` on
them specifically.

---

## Where to look

| Thing | File |
|---|---|
| Mangle rules, drop rule | `router/setup.rsc` |
| Detection + reporting | `router/router-sync.rsc` step 5 |
| Storing the count | `public_html/api/router-sync.php` → `receive_report()` |
| Device ceiling | `private/lib/billing.php` → `device_allowance()` |
| Admin view | `admin.html` → Live tab |
