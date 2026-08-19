# Router files

| File | Goes where |
|---|---|
| `setup.rsc` | Import once on a reset router |
| `router-sync.rsc` | Import after setup; the 60s poller |
| `hotspot/login.html` | Upload to the router's `hotspot` folder |
| `preview-login.php` | Stays here. A tool, not a deliverable |
| `preview/` | Generated. Never upload these |

## Previewing the captive portal

`hotspot/login.html` is a **RouterOS template, not a web page**. It holds
variables the Mikrotik fills in as it serves the page:

```
$(if error) … $(endif)   the error box, shown only when a login failed
$(error)                 the message itself
$(link-login-only)       where the form posts
$(chap-id)               CHAP challenge, so the password never goes in clear
```

Open that file straight off disk and a browser prints those as literal
text, because a browser is not a Mikrotik. **That is correct, not a
fault.** It renders properly the moment the router serves it.

To look at the design without a router:

```bash
php router/preview-login.php
```

That writes three files into `router/preview/`:

| File | State |
|---|---|
| `login-normal.html` | what a customer normally sees |
| `login-error.html` | after a rejected login |
| `login-nochap.html` | with CHAP off, to check the plain-post fallback |

Open any in a browser. They carry an orange banner so nobody mistakes
them for the real template. The script exits non-zero if it meets a
router variable it does not know how to resolve, so a new one cannot
silently reach a customer as literal text.

**Upload `hotspot/login.html` to the Mikrotik. Never upload the previews**
— their variables are already substituted, so the error box would never
appear and the login form would post nowhere.
