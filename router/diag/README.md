# Finding a reboot loop

The site has already been cleared. A GET to `/api/router-sync.php` with
the sync key returns 200, 254 bytes of valid JSON, `ok=true`. Nothing the
server sends can be blamed for this, so the fault is on the router.

## Step 0 — stop the loop before you debug it

A 60-second scheduler turns one fault into a reboot loop you cannot get
into the router to fix. Kill it first, every time, before running
anything here:

```
/system scheduler disable [find name="ibg-sync"]
/system scheduler print
```

`disabled=yes` on the ibg-sync line, or no line at all, and you are safe
to work.

## Step 1 — ask the router why it rebooted

```
/log print where topics~"system"
/system watchdog print
```

| What you see | What it means |
|---|---|
| `router rebooted`, nothing more | Power. Inverter, adapter, or a loose barrel jack. Not the script. |
| `watch-address` set to some IP | That is the loop. RouterOS reboots on purpose whenever it cannot ping that address, and one Starlink drop is enough. `/system watchdog set watchdog-timer=no watch-address=none`. |
| `out of memory condition was detected` | **This box's answer.** Go to step 2. |
| `kernel failure` with no OOM line | Something faulted. Bisect with tests 0–3. |

---

## Step 2 — out of memory

This is what the hAP ac² actually reported:

```
router was rebooted without proper shutdown, probably kernel failure
kernel failure in previous boot
out of memory condition was detected
```

The box has 128 MB of RAM and it ran out. Watchdog was clean, so nothing
asked for the reboot — the kernel fell over. There are two credible
causes and they are told apart by measurement, not by argument.

### Hypothesis A — the sync script leaks a little RAM per poll

RouterOS's HTTPS `/tool fetch` does not always give back everything it
takes. A few hundred KB per call is invisible on one run and fatal at
1,440 runs a day, which is exactly what a 60-second scheduler does. It
fits the timeline: the router was fine until the scheduler went live,
then started dying hours later.

**Test it.** `router/diag/test-4-memory.txt` runs the fetch 20 times and
logs free memory each pass — twenty minutes of scheduler life in about a
minute:

```
/system scheduler disable [find name="ibg-sync"]
... paste test-4-memory.txt as a script named ibg-test ...
/system script run ibg-test
/log print where message~"ibg-mem"
```

Read the first line against the last. Memory that dips and settles is
normal. Memory that falls on *every* pass is the leak, and the slope
tells you how long the router survives.

### Hypothesis B — connection tracking, nothing to do with the script

The anti-sharing rules in `setup.rsc` mark packets, and packet marks
switch off fasttrack. Every connection then gets tracked, and on a busy
compound — a few phones on video calls, one laptop torrenting — the
table grows until it does not fit. This one correlates with how many
people are online, not with the scheduler.

```
/ip firewall connection tracking print
/system resource print
```

`total-entries` climbing into the tens of thousands while `free-memory`
falls is hypothesis B. Cap it:

```
/ip firewall connection tracking set max-entries=8192 tcp-established-timeout=1h udp-timeout=10s
```

### Baseline, worth having either way

```
/system resource print
```

- `free-memory` — on an idle hAP ac² running hotspot and both radios,
  expect somewhere around 60–80 MB free. Much under 30 MB at idle and
  the box is over-committed before the script has done anything.
- `free-hdd-space` — 16 MB of flash total, and each auto-generated
  `supout.rif` eats 1–3 MB of it. `/file print`, and delete the ones you
  have already sent to MikroTik.

Free RAM back by dropping packages the build does not use — on this
router that is usually `ipv6`, `mpls`, `routing` and `ppp`:

```
/system package print
/system package disable ipv6,mpls,routing,ppp
/system reboot
```

### If it is the leak — what to change

Slow the poll down. The leak is per-fetch, so five-minute polls leak at a
fifth the rate, and the only thing it costs is how long a customer waits
after you approve their payment.

Both ends have to agree, and the router side is what actually controls
it:

```
/system scheduler set [find name="ibg-sync"] interval=5m
```

Then match `poll_seconds` in `public_html/api/router-sync.php` (line
~105) so the admin panel's "last seen" thresholds do not report a
healthy router as stale.

A nightly reboot is the blunt version — `/system scheduler add
name="nightly" start-time=04:00:00 interval=1d on-event="/system
reboot"`. It papers over the leak rather than fixing it, but it is
better than the router choosing its own moment during the day.

---

## Step 3 — the bisect

For a `kernel failure` with no OOM line. Each file adds one capability to
the one before. Paste each as a **new script named `ibg-test`** (Winbox →
System → Scripts → Add, policy `read,write,test,sensitive`), run it, read
the log:

```
/system script run ibg-test
/log print where message~"ibg-test"
```

| File | Adds | If the router dies here |
|---|---|---|
| `test-0-baseline.txt` | nothing — just logging | Not software. Power or hardware. |
| `test-1-fetch-only.txt` | `/tool fetch` over HTTPS | TLS or DNS. Check `/ip dns` resolves the domain. |
| `test-2-deserialize.txt` | `:deserialize from=json` | The JSON parser. |
| `test-3-provision.txt` | one `/ip hotspot user add` | Hotspot commands — usually a `profile=` that does not exist. |
| `test-4-memory.txt` | 20 fetches, logging free RAM | See hypothesis A above. |

Delete `ibg-test` when you are done — it holds the sync key in plain
text:

```
/system script remove [find name="ibg-test"]
```

## Step 4 — only then, re-enable the scheduler

Run it by hand first, watch it survive, enable the scheduler last:

```
/system script run ibg-sync
/log print where message~"ibg-sync"
/system scheduler enable [find name="ibg-sync"]
```

Then leave the router alone for a few hours and check
`/system resource print` again. `uptime` still climbing is the only proof
that matters.
