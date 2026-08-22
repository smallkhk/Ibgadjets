# cPanel or VPS?

Short answer: **start on cPanel.** It runs this system properly today, and
the code is identical on both, so it is not a decision you get locked into.

But you asked what a VPS buys you, and the honest answer is: one thing
that genuinely matters, and a handful that do not.

---

## The one real difference: WireGuard

Everything awkward about the current design traces back to a single fact —
Starlink puts you behind CGNAT, so nothing on the internet can open a
connection *into* your router. That is why the router polls the site every
60 seconds instead of the site simply telling the router what to do.

A VPS can run a WireGuard server. The Mikrotik dials **out** to it, which
CGNAT allows, and once that tunnel is up it stays up. Now the server has a
route into the router, and the shape of the system changes:

| | cPanel (polling) | VPS (tunnel) |
|---|---|---|
| Payment approved → customer online | up to 60s | instant |
| Suspend a customer | up to 60s | instant |
| Live session data | last poll, ≤60s stale | live |
| A second location later | second poller, works fine | one server, many routers |

Read that table honestly, though. **Your slowest step is a human
confirming a bank transfer.** If approving a payment takes you four
minutes, the 60 seconds after it is noise. The polling design is not a
compromise you are suffering — for one router with manual approvals, it is
genuinely the right shape.

### Remote router access does NOT need a VPS

An earlier draft of this document listed "Winbox from anywhere" as a
reason to buy a VPS. That was wrong, and it mattered, so here is the
correction.

MikroTik ship **Back To Home**, a free WireGuard service built into
RouterOS 7.12+. It is designed for exactly this situation: when the router
has no public IP, the connection is made through MikroTik's relay servers,
so CGNAT is not an obstacle. Traffic stays end-to-end encrypted — the
relay never holds the keys. It needs an ARM, ARM64 or TILE device, and the
hAP ac² is ARM, so it qualifies.

Set-up is in `docs/deploy.md`. It costs nothing and removes the only line
in that table that would genuinely have bitten you.

## Things people think a VPS gives you here, that it does not

- **FreeRADIUS.** Real, and better than hotspot users if you ever run
  several sites. For one router it is a lot of moving parts to replace
  something already working.
- **Speed.** Your bottleneck is Starlink and a 2.4GHz radio in a concrete
  compound. The web server is not remotely near the limit.
- **Cron granularity.** cPanel does one-minute cron. Enough.
- **Reliability.** Managed shared hosting is *more* reliable than a VPS
  you administer yourself, unless you enjoy patching Linux at 1am.

## Things a VPS costs you

- ~$5–10/month against ~$3, which the business covers easily.
- Security updates, firewall, TLS renewal, backups — all yours now. On
  cPanel, Hostinger does that.
- One more thing that can be down at 11pm.

---

## Recommendation

**Ship on cPanel.** Get customers, get money moving, learn what actually
breaks.

**Move to a VPS when one of these becomes true:**

1. You add a second dish or a second compound.
2. You automate payments (OPay or a virtual-account provider) and the
   60-second gap becomes the slowest step instead of the fastest.

Note what is *not* on that list any more: getting into the router from
somewhere else. Back To Home covers that for free.

The migration is not painful. It is the same PHP, the same MySQL dump, the
same schema. `router-sync.php` keeps working over the tunnel exactly as it
does over the internet — you would just gain the option of pushing instead
of waiting to be asked.
