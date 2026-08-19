# =====================================================================
# IB Gadgets Telecom — one-time router setup
# Mikrotik hAP ac² (RBD52G-5HacD2HnD), RouterOS v7
#
# Run ONCE on a reset router:
#   /system reset-configuration no-defaults=yes skip-backup=yes
#   ... reconnect, upload this file, then:
#   /import setup.rsc
#
# Then edit and import router-sync.rsc, which creates the polling script.
#
# ASSUMPTIONS
#   ether1        = Starlink (DHCP client)
#   ether2..5     = LAN
#   wlan1 (2.4G)  = IB Gadgets
#   wlan2 (5G)    = IB Gadgets 5G
#   hotspot subnet 10.5.50.0/24
# =====================================================================

# ---------------------------------------------------------------------
# Bridge and interfaces
# ---------------------------------------------------------------------
/interface bridge
add name=bridge-hotspot protocol-mode=none comment="IB Gadgets clients"

/interface bridge port
add bridge=bridge-hotspot interface=ether2
add bridge=bridge-hotspot interface=ether3
add bridge=bridge-hotspot interface=ether4
add bridge=bridge-hotspot interface=ether5
add bridge=bridge-hotspot interface=wlan1
add bridge=bridge-hotspot interface=wlan2

/ip dhcp-client
add interface=ether1 disabled=no comment="Starlink uplink"

# ---------------------------------------------------------------------
# Wireless
# Both bands on one SSID so phones roam. Open network — the captive
# portal is what gates access, not a WiFi password.
# ---------------------------------------------------------------------
/interface wireless security-profiles
set [find default=yes] mode=none

/interface wireless
set wlan1 mode=ap-bridge band=2ghz-g/n channel-width=20mhz ssid="IB Gadgets" \
    disabled=no wireless-protocol=802.11 country=nigeria distance=indoors
set wlan2 mode=ap-bridge band=5ghz-a/n/ac channel-width=20/40/80mhz-eeeC ssid="IB Gadgets 5G" \
    disabled=no wireless-protocol=802.11 country=nigeria distance=indoors

# ---------------------------------------------------------------------
# Addressing
# ---------------------------------------------------------------------
/ip address
add address=10.5.50.1/24 interface=bridge-hotspot network=10.5.50.0

/ip pool
add name=hs-pool ranges=10.5.50.10-10.5.50.254

/ip dhcp-server
add address-pool=hs-pool interface=bridge-hotspot name=hs-dhcp disabled=no lease-time=1h
/ip dhcp-server network
add address=10.5.50.0/24 gateway=10.5.50.1 dns-server=10.5.50.1

/ip dns
set allow-remote-requests=yes servers=1.1.1.1,8.8.8.8

/ip firewall nat
add chain=srcnat out-interface=ether1 action=masquerade comment="ibg nat"

# ---------------------------------------------------------------------
# Hotspot
#
# login-by=mac-cookie,http-chap is the whole device-memory story: the
# customer types their phone number and password ONCE, the router
# remembers that device by MAC, and every reconnect after that is
# silent. The cookie lifetime is deliberately long — expiry is enforced
# by the account disappearing from the sync list, not by the cookie.
# ---------------------------------------------------------------------
/ip hotspot profile
add name=ibg-hs hotspot-address=10.5.50.1 dns-name=wifi.ibphone.eclipselivecam.online \
    html-directory=hotspot login-by=mac-cookie,http-chap \
    use-radius=no

/ip hotspot
add name=ibg address-pool=hs-pool interface=bridge-hotspot profile=ibg-hs \
    addresses-per-mac=2 idle-timeout=5m keepalive-timeout=2m disabled=no

# Long on purpose. The cookie is convenience, not access control — it
# maps a device to a username that must still exist on the router, and
# expiry is enforced by the account leaving the sync list. A short
# timeout would only mean loyal customers retyping their password.
/ip hotspot profile
set [find name=ibg-hs] mac-cookie-timeout=60d

# The stock trial account gives away free internet. Remove it.
/ip hotspot user profile
set [find name=default] shared-users=1 rate-limit=""
/ip hotspot user
remove [find name="default-trial"]

# ---------------------------------------------------------------------
# Walled garden
# Unpaid users must be able to reach the site to sign up and pay, and
# nothing else. Keep this list short — every entry is free internet.
# ---------------------------------------------------------------------
# Every entry here is free internet for anyone who discovers it, so the
# list is deliberately as short as it can be. The site now serves its own
# fonts, so no third-party host is needed to render a styled page.
/ip hotspot walled-garden
add dst-host=ibphone.eclipselivecam.online     comment="ibg site"
add dst-host="*.ibphone.eclipselivecam.online" comment="ibg site"

# OPay is only reachable once you actually switch OPay on. Until then
# these two are surface with no purpose — enable them at the same time
# you set opay_enabled=1.
add dst-host="*.opayweb.com"      comment="opay checkout" disabled=yes
add dst-host="*.opaycheckout.com" comment="opay checkout" disabled=yes

# The site's own IP, so HTTPS to it works before login. The dst-host
# entries above only cover plain HTTP, because the hotspot cannot read a
# hostname out of an encrypted request.
#
# Two things to know: RouterOS resolves this to an IP address, and on
# shared hosting that address is shared with other sites — so they are
# reachable too. And if the host ever moves you to a different IP, HTTPS
# stops working until the router re-resolves. If signup pages suddenly
# stop loading for unpaid customers, check this first.
/ip hotspot walled-garden ip
add dst-host=ibphone.eclipselivecam.online action=accept comment="ibg site"

# ---------------------------------------------------------------------
# ANTI-SHARING — TTL inspection
#
# Every IP packet carries a TTL that drops by one at each router hop.
# Phones and laptops emit a known starting value: 64 on Android, iOS,
# Linux and macOS, 128 on Windows. A device connected straight to our
# WiFi therefore arrives at 64 or 128.
#
# When someone logs in and then turns on their personal hotspot, the
# second device's packets pass THROUGH the phone, which routes them and
# decrements the TTL. Those arrive at 63 or 127 — a value no directly
# connected device produces. That mismatch is the tell.
#
# Default policy is to flag, not drop: the address list is reported to
# the site so the admin sees who is sharing. Enable the drop rule below
# to actually cut it off.
# ---------------------------------------------------------------------
/ip firewall mangle
add chain=prerouting in-interface=bridge-hotspot ttl=equal:63 \
    action=add-src-to-address-list address-list=ibg-tethered address-list-timeout=10m \
    comment="ibg anti-share: 64 minus one hop"
add chain=prerouting in-interface=bridge-hotspot ttl=equal:127 \
    action=add-src-to-address-list address-list=ibg-tethered address-list-timeout=10m \
    comment="ibg anti-share: 128 minus one hop (Windows)"
add chain=prerouting in-interface=bridge-hotspot ttl=equal:254 \
    action=add-src-to-address-list address-list=ibg-tethered address-list-timeout=10m \
    comment="ibg anti-share: 255 minus one hop"

# The marking rules below exist ONLY to feed the drop rule, and the drop
# rule ships disabled — so by default these two are pure cost. Packet
# marks switch off fasttrack, which means every connection on the
# compound gets fully tracked, and connection tracking is where RAM goes
# on a 128MB router. The address-list rules above do the flagging the
# site reads; they do not need these.
#
# Enable all three together, or none of them.
add chain=prerouting in-interface=bridge-hotspot ttl=equal:63 \
    action=mark-packet new-packet-mark=ibg-tether passthrough=yes disabled=yes \
    comment="ibg anti-share mark (enable with the drop rule)"
add chain=prerouting in-interface=bridge-hotspot ttl=equal:127 \
    action=mark-packet new-packet-mark=ibg-tether passthrough=yes disabled=yes \
    comment="ibg anti-share mark (enable with the drop rule)"

# Flip all three to disabled=no to enforce instead of merely flagging.
/ip firewall filter
add chain=forward packet-mark=ibg-tether action=drop disabled=yes \
    comment="ibg anti-share: drop shared traffic (enable to enforce)"

# ---------------------------------------------------------------------
# Management hardening
# ---------------------------------------------------------------------
# Winbox is restricted to the local subnet on purpose. To reach it from
# outside, enable Back To Home (IP > Cloud > Back To Home) and add that
# tunnel's subnet here — see docs/deploy.md. Never open Winbox to the
# internet: exposed Winbox ports have been mass-exploited before, and a
# compromised router means every customer's traffic.
/ip service
set telnet disabled=yes
set ftp disabled=yes
set www disabled=yes
set api disabled=yes
set api-ssl disabled=yes
set ssh port=22222
set winbox address=10.5.50.0/24

/ip firewall filter
add chain=input in-interface=ether1 action=drop comment="ibg: nothing from the internet side" \
    connection-state=new

/ip neighbor discovery-settings
set discover-interface-list=none

# ---------------------------------------------------------------------
# The 60s poll. router-sync.rsc must be imported first — it defines the
# script this scheduler runs.
# ---------------------------------------------------------------------
# Created DISABLED on purpose. If the script faults on its first run —
# a wrong URL, a key that got clipped in the paste — a live 60-second
# scheduler turns one fault into a reboot loop, and you cannot get into
# the router to stop it. Run it by hand once, see "Done sync." in the
# log, and only then enable this:
#   /system scheduler enable [find name="ibg-sync"]
/system scheduler
add name="ibg-sync" interval=60s on-event="/system script run ibg-sync" \
    policy=read,write,policy,test,sensitive comment="IB Gadgets site sync" \
    disabled=yes

/system clock
set time-zone-name=Africa/Lagos

/system note
set show-at-login=no

:log info "IB Gadgets setup complete. Upload login.html to /hotspot/ and import router-sync.rsc."
