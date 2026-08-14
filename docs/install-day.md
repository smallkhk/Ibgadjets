# Install day

What to buy, how to wire it, what order to do things in, and what a
customer actually experiences afterwards.

---

## 1. Check this before you go to the shop

**Which Starlink kit do you have?** This decides whether you need to buy
one more thing.

- **Gen 3 / newer standard kit** — the router has Ethernet ports built in.
  Nothing extra needed.
- **Gen 2 (rectangular dish, white router with no visible LAN port)** —
  you need the **Starlink Ethernet Adapter**. It goes inline between the
  dish cable and the router. Without it there is no way to plug the
  Mikrotik in at all, and no software fixes that.

Go and look at the back of your Starlink router before you leave.

### Shopping list

| Item | Notes |
|---|---|
| Mikrotik hAP ac² (RBD52G-5HacD2HnD) | The model the whole build targets |
| Starlink Ethernet Adapter | **Only if Gen 2.** Check first |
| Cat5e or Cat6 cable | Starlink → Mikrotik `ether1`. Buy longer than you think |
| Inverter or UPS | Covers dish + router through NEPA outages |

The router ships with its own power adapter, so you do not need PoE gear.

---

## 2. Physical install

**Where to mount it.** Central to the compound, high up, out in the open.
2.4GHz carries through walls but every concrete column costs you. Do not
put it in a metal cabinet or behind the TV. If one end of the compound is
weak, that is a second access point later, not a bigger antenna now.

**Wiring:**

```
Dish → Starlink router → (Ethernet adapter if Gen 2) → Mikrotik ether1
                                                       Mikrotik ether2-5 → spare LAN
                                                       Mikrotik wlan1/wlan2 → customers
```

**Put the Starlink router into Bypass mode.** Starlink app → Settings →
Bypass mode. This turns off its WiFi and its NAT so it becomes a plain
pass-through, and the Mikrotik does all the routing. Skip this and you get
double NAT, which will not stop the hotspot working but makes everything
harder to reason about.

**Power.** The dish and the router both need to survive NEPA going off,
or bundles keep burning while nobody can browse. Size the inverter for the
dish first — it draws far more than the router.

---

## 3. Software, in this exact order

Do all of this **on site**, on the compound WiFi. Steps 1 and 6 in
particular are much harder afterwards.

### 1. Update RouterOS first

Back To Home needs **RouterOS 7.12 or later** and the router will not ship
with it. In Winbox: **System → Packages → Check For Updates → Download &
Install.** Let it reboot.

Do this before anything else — a firmware upgrade can reset configuration,
and you do not want that after you have imported everything.

### 2. Connect with Winbox

Download Winbox from mikrotik.com. On first boot the router has no usable
IP, so use the **Neighbors** tab and connect by **MAC address**. Default
user is `admin` with no password.

### 3. Wipe it

```
/system reset-configuration no-defaults=yes skip-backup=yes
```

It reboots with nothing on it. Reconnect by MAC again.

### 4. Import the setup

Edit `router/setup.rsc` first — put your real domain in the four
walled-garden lines. Then drag it into **Files** in Winbox and run:

```
/import setup.rsc
```

WiFi, bridge, DHCP, hotspot, walled garden and the anti-sharing rules all
come up in one go.

### 5. Upload the captive portal page

Drag `router/hotspot/login.html` into the router's **hotspot** folder,
replacing the stock file. Check `md5.js` is still sitting beside it —
RouterOS ships it and the login needs it.

### 6. Enable Back To Home

**IP → Cloud → Back To Home** → enable → add a user → scan the QR with
the *MikroTik Back To Home* app on your phone.

Then let Winbox through the tunnel, because `setup.rsc` locks it to the
hotspot subnet:

```
/interface wireguard print
/ip address print
/ip service set winbox address=10.5.50.0/24,<the BTH subnet>
```

**Test it before you leave: turn the compound WiFi off on your phone, go
on mobile data, and connect through the app.** That is the only test that
proves it works through CGNAT. If you skip it you will find out the hard
way, from somewhere else.

### 7. Import the sync script

Edit `router/router-sync.rsc` — your real URL and the sync key from
`private/config.php`, pasted exactly. Then:

```
/import router-sync.rsc
/system script run ibg-sync
/log print where message~"ibg-sync"
```

On the website: **Admin → Overview → Router conversation** should start
filling in every minute.

---

## 4. What a customer actually experiences

This is the part worth walking through slowly, because there is one step
people do not expect.

### Brand new person, first time

1. They join **IB Gadgets** from their WiFi list. It is open — no password.
2. Their phone spots the captive portal and pops open the login page by
   itself. Android and iOS both do this automatically.
3. They have no account, so they tap **Create account & buy data**.
4. That opens your website. **This works even though they have not paid** —
   the walled garden lets your domain through and nothing else.
5. They sign up: phone number, name, compound or visitor, flat number,
   password.
6. They pick a bundle and choose bank transfer. The site shows your
   account number and a reference code like `IB-4X9QM`.
7. **They switch to mobile data to make the transfer.** Their bank app
   cannot work over your WiFi — the walled garden only allows your site.
   See the note below.
8. They come back, upload the receipt screenshot on your site.
9. You get the payment in **Admin → Payments**, check the money landed,
   and press **Approve**.
10. Within 60 seconds the router has created their account.
11. **They go back to the captive portal and log in** with their phone
    number and password. This is the step people forget to mention.
12. They are online. The router stores a cookie against that phone.

### Every time after that

They walk in the gate and their phone connects. No login page, no typing,
nothing. That is the mac-cookie doing its job.

When the bundle runs out, browsing stops and the captive portal comes
back. They reach your site through the walled garden, buy again, you
approve, and they are straight back on — **without logging in again**,
because the cookie is deliberately left in place when an account expires.

### So: after signup, where do they go?

Signing up does not put them online. It creates an account on your
website, and they are still sitting on your website. The order is:

```
account  →  choose bundle  →  pay  →  you approve  →  log in at the
                                                     hotspot once  →  online
```

Only that last login moves them from "has an account" to "can browse", and
it happens once per device, ever.

### The bank app problem — read this one

An unpaid customer on your WiFi can reach **your site and nothing else**.
Their bank app will not load. That is correct behaviour, not a bug: every
domain you whitelist is free internet for anybody who knows about it.

Tell people plainly: **do the transfer on your own mobile data, then come
back.** Most people have some airtime data even when they are out of
bundle, and it is only needed once per purchase.

Do not be tempted to whitelist the banks. There are dozens of them, each
with several domains and API hosts, the list rots constantly, and every
entry is a hole. If you want to remove the friction properly, the answer
is automated payments (OPay or a virtual account provider), not a bigger
walled garden.

---

## 5. Test before you leave the compound

1. Approve a payment in the admin panel without transferring anything.
2. Wait a minute, then on the router: `/ip hotspot user print` — the
   account should be there with the right `limit-bytes-total`,
   `rate-limit` and `shared-users`.
3. Connect a phone, log in, confirm it browses.
4. **Disconnect and reconnect.** It should come back with no login page.
   If it asks again, mac-cookie is not working — check `login-by` on the
   hotspot profile.
5. Check the dashboard shows data climbing.
6. Suspend that customer in the admin panel and confirm they drop off
   within a minute.
7. Back To Home over mobile data, WiFi off.

If all seven pass, you are live.
