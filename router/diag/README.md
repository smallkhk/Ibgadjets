# Finding a reboot loop

The site has already been cleared. A GET to
`/api/router-sync.php` with the sync key returns 200, 254 bytes of valid
JSON, `ok=true`. Nothing the server sends can be blamed for this, so the
fault is on the router.

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

Do this before the bisect. RouterOS records the reason, and the answer
decides whether the script is even involved.

```
/log print where topics~"system"
/system resource print
/file print
```

Read it like this:

| What you see | What it means |
|---|---|
| `router rebooted` with no other detail | Power was interrupted — inverter, adapter, or a loose barrel jack. Not the script. |
| `kernel failure`, or a file named `autosupout.rif` appears in `/file print` | RouterOS faulted. Something in the script took the box down. Continue to the bisect. |
| `system rebooted by ...` naming a user or watchdog | Something asked for it. Check `/system watchdog print` — `watch-address` set to an unreachable host reboots the router on purpose, every time it cannot ping. |

That last row catches people out. If `watch-address` is set and Starlink
drops for a minute, the router reboots itself. It looks exactly like a
script crash and has nothing to do with one:

```
/system watchdog print
/system watchdog set watchdog-timer=no watch-address=none
```

Also worth a glance while you are here — `free-memory` in
`/system resource print`. Under a few MB on an hAP ac², the box will
fault under load regardless of what the script does.

## Step 2 — the bisect

Only if step 1 pointed at a kernel failure. Each file adds one
capability to the one before it. Paste each as a **new script named
`ibg-test`** (Winbox → System → Scripts → Add, policy
`read,write,test,sensitive`), run it, then read the log:

```
/system script run ibg-test
/log print where message~"ibg-test"
```

| File | Adds | If the router dies here |
|---|---|---|
| `test-0-baseline.txt` | nothing — just logging | Not a software fault. Power or hardware. |
| `test-1-fetch-only.txt` | `/tool fetch` over HTTPS | TLS or DNS. Try `http://` and check `/ip dns` resolves the domain. |
| `test-2-deserialize.txt` | `:deserialize from=json` | The JSON parser. This is the known one on small routers. |
| `test-3-provision.txt` | one `/ip hotspot user add` | The hotspot commands — usually a `profile=` that does not exist. |

Whichever one reboots the box names the culprit. Report which number,
and what the log said just before it went down.

Delete `ibg-test` when you are finished — it holds the sync key in
plain text:

```
/system script remove [find name="ibg-test"]
```

## Step 3 — only then, re-enable the scheduler

Run `ibg-sync` by hand first, see it survive, and enable the scheduler
last:

```
/system script run ibg-sync
/log print where message~"ibg-sync"
/system scheduler enable [find name="ibg-sync"]
```
