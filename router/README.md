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

That writes two files into `router/preview/` — the normal page and the
one shown after a wrong password. Open either in any browser. They carry
an orange banner so nobody mistakes them for the real template.

**Upload `hotspot/login.html` to the Mikrotik. Never upload the previews**
— their variables are already substituted, so the error box would never
appear and the login form would post nowhere.
