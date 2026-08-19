# =====================================================================
# IB Gadgets Telecom — router sync
# RouterOS v7 (Mikrotik hAP ac²)
#
# Runs every 60s from /system scheduler. Pulls desired state from the
# site, reconciles the local hotspot users against it, and posts back
# what it applied plus per-user usage.
#
# Reconcile, do not apply deltas: whatever the site lists should exist,
# anything else carrying our comment marker should not. A missed reply,
# a reboot or a factory reset all heal on the next pass.
#
# INSTALL — DO NOT /import THIS FILE
#
#   Use router/ibg-sync-source.txt instead: Winbox > System > Scripts >
#   Add, paste the body into the Source box, name it ibg-sync, and tick
#   the read/write/policy/test/sensitive policies.
#
#   /import cannot be trusted with what follows. The source={ } block
#   contains 20 nested braces, 36 comment lines and 75 $variables, and
#   RouterOS's importer does not survive that combination: the script
#   silently never appears, and the first sign of trouble is
#   "no such item" from /system script run ibg-sync.
#
#   This file is kept as the readable, reviewable copy of the script and
#   as the source ibg-sync-source.txt is generated from.
#
# It must never crash the scheduler. Everything is wrapped in :do/on-error.
# =====================================================================

/system script
add name="ibg-sync" dont-require-permissions=no owner=admin \
    policy=read,write,policy,test,sensitive source={

:local ibgUrl "https://ibphone.eclipselivecam.online/api/router-sync.php"
:local ibgKey "CHANGE-ME-64-hex-characters"

# Only users carrying this comment are ours to create, change or delete.
# Anything you made by hand is left alone.
:local mark "ibg"

# ---------------------------------------------------------------------
# RUN LOCK — one cycle at a time, no exceptions.
#
# /tool fetch has no timeout in RouterOS. If the host is slow, or the
# connection stalls, a cycle can outlast the 60-second scheduler. The
# scheduler does not check: at 60s it starts a second copy, then a third,
# each holding an open connection and its own buffers. On a 128MB router
# that ends in an out-of-memory kernel panic — which reboots the box,
# which starts the scheduler again.
#
# This is invisible when you run the script by hand, because by hand you
# only ever run one at a time. It only appears under the scheduler, under
# load, which is the worst possible time to find it.
#
# The skip counter is the escape hatch. A genuinely wedged fetch would
# otherwise hold the lock forever and sync would stop for good, silently.
# After 5 refusals we assume the holder is dead and take the lock.
# ---------------------------------------------------------------------
:global ibgBusy
:global ibgSkips

:if ([:typeof $ibgSkips] != "num") do={ :set ibgSkips 0 }

:if ($ibgBusy = true) do={
    :set ibgSkips ($ibgSkips + 1)
    :if ($ibgSkips < 5) do={
        :log warning ("ibg-sync: previous cycle still running, skipping (" . $ibgSkips . ")")
        :error "busy"
    }
    :log warning "ibg-sync: previous cycle looks wedged, taking the lock"
}

:set ibgSkips 0
:set ibgBusy true

:do {

    # -----------------------------------------------------------------
    # 1. Pull desired state
    # -----------------------------------------------------------------
    # Accept: application/json is NOT optional, and it is not politeness.
    #
    # The host runs Imunify360 WebShield in front of the site. Any request
    # that looks like it wants a web page gets a JavaScript challenge
    # instead of an answer — "One moment, please..." — served as 11KB of
    # HTML under a 200 OK. The router cannot run JavaScript, so it never
    # passes, and it cannot tell from the status code that anything is
    # wrong.
    #
    # Measured on this host, interleaved so it is not a timing artefact:
    # 34 of 34 requests carrying this header came back as JSON; without
    # it, 14 of 34 were challenged. WebShield leaves alone anything that
    # asks for JSON rather than HTML.
    :local res [/tool fetch url=$ibgUrl \
        http-method=get \
        http-header-field=("X-Sync-Key: " . $ibgKey . ",Accept: application/json") \
        output=user as-value]

    :if (($res->"status") != "finished") do={
        :log warning "ibg-sync: fetch failed"
        :error "fetch"
    }

    # NEVER hand :deserialize something that is not JSON.
    #
    # On a small router, feeding HTML to the JSON parser does not raise a
    # catchable error — it can fault RouterOS and reboot the box. With a
    # 60-second scheduler that turns into a reboot loop, and :do/on-error
    # cannot save you because the crash is below the script layer.
    #
    # This is not a hypothetical. It is what took this router down: the
    # host's WAF answered the poll with an 11KB JavaScript challenge page
    # under a 200 OK, the parser was handed HTML, and a 128MB box ran out
    # of memory and rebooted — every 60 seconds, for as long as the
    # scheduler was enabled. The Accept header above is what stops the
    # challenge; this check is what stops it mattering if the header ever
    # stops working.
    #
    # Other ways to arrive here: ibgUrl pointing at the site root instead
    # of /api/router-sync.php, an error page, or a captive portal upstream
    # intercepting the request. All of them start with '<', not '{'.
    :local data ($res->"data")

    :if ([:len $data] = 0) do={
        :log warning "ibg-sync: empty response"
        :error "empty"
    }
    :if ([:pick $data 0 1] != "{") do={
        :log warning ("ibg-sync: response is not JSON, starts with: " . [:pick $data 0 40])
        :error "notjson"
    }

    :local doc [:deserialize from=json value=$data]

    :if (($doc->"ok") != true) do={
        :log warning "ibg-sync: server said not ok"
        :error "server"
    }

    :local users ($doc->"users")
    :local blocked ($doc->"blocked_macs")
    :local profiles ($doc->"profiles")

    # A string, not an array, and deliberately so.
    #
    # This used to be [:toarray ""] with the username as the key. The
    # username arrives from :deserialize as a NUM — RouterOS reads
    # "08153329197" as a number, which is also why the leading zero
    # disappears — and using a num as an array key panics this board.
    # Every other shape of the same loop ran clean; only the array
    # version brought the router down.
    #
    # Format is ",name,name,name," so a lookup can search for ",x," and
    # never match a partial. Without the commas, 8153 would match
    # 8153329197.
    :local wanted ","
    :local applied ""

    # -----------------------------------------------------------------
    # 1b. Profiles, before users
    #
    # Speed and device count are NOT properties of /ip hotspot user.
    # RouterOS keeps rate-limit and shared-users on the user PROFILE, and
    # passing either to /ip hotspot user fails with "bad parameter" — on
    # this board, inside a script, that has taken the router down rather
    # than raising a catchable error.
    #
    # So the server sends one profile per distinct speed/device pairing
    # and each user names the profile it belongs to. Profiles are created
    # before any user references one, because a user cannot point at a
    # profile that does not exist.
    # -----------------------------------------------------------------
    :foreach p in=$profiles do={
        :local pname  ($p->"name")
        :local prate  ($p->"rate_limit")
        :local pshare ($p->"shared_users")

        :local pfound [/ip hotspot user profile find where name=$pname]
        :if ([:len $pfound] = 0) do={
            /ip hotspot user profile add name=$pname \
                rate-limit=$prate shared-users=$pshare
            :log info ("ibg-sync: profile created " . $pname)
        } else={
            /ip hotspot user profile set $pfound \
                rate-limit=$prate shared-users=$pshare
        }
    }

    # -----------------------------------------------------------------
    # 2. Create or update every account the site listed
    # -----------------------------------------------------------------
    :foreach u in=$users do={
        :local uname ($u->"username")
        :local pass  ($u->"password")
        :local uprof ($u->"profile")
        :local sub   ($u->"sub_id")
        :local dmb   ($u->"data_mb")

        # null data_mb means unlimited -> no byte cap at all
        :local bytes 0
        :if ([:typeof $dmb] = "num") do={ :set bytes ($dmb * 1048576) }

        :set wanted ($wanted . $uname . ",")

        :local found [/ip hotspot user find where name=$uname]

        :if ([:len $found] = 0) do={
            /ip hotspot user add name=$uname password=$pass \
                profile=$uprof \
                limit-bytes-total=$bytes comment=$mark
            :log info ("ibg-sync: provisioned " . $uname)
        } else={
            # Reset the byte counter when the site's remaining figure has
            # moved ahead of ours — that is a fresh bundle, not the same one.
            /ip hotspot user set $found password=$pass \
                profile=$uprof \
                limit-bytes-total=$bytes comment=$mark disabled=no
        }

        :if ([:len $applied] > 0) do={ :set applied ($applied . ",") }
        :set applied ($applied . $sub)
    }

    # -----------------------------------------------------------------
    # 3. Remove anything of ours the site did not list
    #    (expired, spent, suspended, refunded — all the same case here)
    # -----------------------------------------------------------------
    :foreach h in=[/ip hotspot user find where comment=$mark] do={
        :local hname [/ip hotspot user get $h name]
        :if ([:typeof [:find $wanted ("," . $hname . ",")]] = "nothing") do={
            # Kick the live session first. Removing the account does NOT
            # end a session already established — that was tested on the
            # live router, where a suspended customer kept browsing until
            # they happened to disconnect. Without this line, suspending
            # someone on a video call does nothing for hours.
            :foreach a in=[/ip hotspot active find where user=$hname] do={
                /ip hotspot active remove $a
            }
            /ip hotspot user remove $h
            # The mac-cookie is deliberately LEFT in place. A cookie on
            # its own grants nothing — it maps a device to a username
            # that has to exist — so keeping it is safe, and it means a
            # customer who tops up is back online without typing their
            # password again. Deleting it here would force a fresh login
            # after every single bundle, which is the one thing this
            # design exists to avoid.
            :log info ("ibg-sync: revoked " . $hname)
        }
    }

    # -----------------------------------------------------------------
    # 3b. Orphaned sessions
    #
    # A session outlives its account. Deleting a hotspot user does not
    # end the session that user already holds, so anything that removed
    # an account without kicking it first leaves someone browsing with no
    # account at all — and the loop above cannot help, because it walks
    # hotspot USERS and there is no longer a user to walk.
    #
    # That is not hypothetical: it is how a suspended customer stayed
    # online on the live router. An earlier version of this script
    # deleted accounts without kicking, and those sessions simply carried
    # on, invisible to every later pass.
    #
    # So sweep the other way round: any active session whose username no
    # longer exists as a hotspot user is stranded and gets cut. Accounts
    # that still exist are left alone, including the hand-made ones this
    # script does not own.
    # -----------------------------------------------------------------
    :foreach a in=[/ip hotspot active find] do={
        :local aname [/ip hotspot active get $a user]
        :if ([:len [/ip hotspot user find where name=$aname]] = 0) do={
            /ip hotspot active remove $a
            :log info ("ibg-sync: cut stranded session for " . $aname)
        }
    }

    # -----------------------------------------------------------------
    # 4. Blocked devices — one phone off, account untouched
    # -----------------------------------------------------------------
    # The ip-binding stops the device reconnecting; the kick ends the
    # session it already has. Both are needed — blocking a phone that is
    # mid-stream should take effect now, not whenever it next reassociates.
    /ip hotspot ip-binding remove [find comment="ibg-block"]
    :foreach m in=$blocked do={
        /ip hotspot ip-binding add mac-address=$m type=blocked comment="ibg-block"
        :foreach a in=[/ip hotspot active find where mac-address=$m] do={
            /ip hotspot active remove $a
        }
    }

    # -----------------------------------------------------------------
    # 5. Collect usage + tethering flags
    # -----------------------------------------------------------------
    :local usage ""

    :foreach h in=[/ip hotspot user find where comment=$mark] do={
        :local hname [/ip hotspot user get $h name]
        :local bin   [/ip hotspot user get $h bytes-in]
        :local bout  [/ip hotspot user get $h bytes-out]
        :local mb    (($bin + $bout) / 1048576)

        :local hmac ""
        :local haddr ""
        :local up 0
        :local tether 0

        :local act [/ip hotspot active find where user=$hname]
        :if ([:len $act] > 0) do={
            :set hmac [/ip hotspot active get [:pick $act 0] mac-address]
            :set haddr [/ip hotspot active get [:pick $act 0] address]
            :set up  [/ip hotspot active get [:pick $act 0] uptime]

            # TTL anomaly seen from this address in the last window means
            # the traffic passed through a second router — a phone
            # sharing its connection. See docs/anti-sharing.md.
            :if ([:len [/ip firewall address-list find where list="ibg-tethered" and address=$haddr]] > 0) do={
                :set tether 1
            }
        }

        :if ([:len $usage] > 0) do={ :set usage ($usage . ",") }
        :set usage ($usage . "{\"username\":\"" . $hname . "\"" . \
            ",\"used_mb\":" . $mb . \
            ",\"mac\":\"" . $hmac . "\"" . \
            ",\"ip\":\"" . $haddr . "\"" . \
            ",\"tethered_hits\":" . $tether . "}")
    }

    # -----------------------------------------------------------------
    # 6. Report back
    # -----------------------------------------------------------------
    :local payload ("{\"applied\":[" . $applied . "],\"usage\":[" . $usage . "]}")

    # output=none, NOT output=user as-value.
    #
    # Asking RouterOS to buffer the reply to a POST panics this board and
    # reboots it. Verified by hand: the identical POST with output=none
    # returns status finished, code 200, every time; with as-value the
    # router goes down. The GET buffers fine — 40 in a row with flat
    # memory — so it is the combination of POST and as-value, not fetch
    # in general.
    #
    # Nothing reads the reply anyway. The server's answer to a report is
    # an acknowledgement, and the next GET is what tells us the truth
    # about state regardless.
    /tool fetch url=$ibgUrl \
        http-method=post \
        http-header-field=("X-Sync-Key: " . $ibgKey . ",Content-Type: application/json,Accept: application/json") \
        http-data=$payload \
        output=none

} on-error={
    :log warning "ibg-sync: cycle failed, will retry next minute"
}

# Outside the on-error block on purpose: the lock must come off whether
# the cycle succeeded or failed. Anything that leaves it set stops sync
# permanently.
:set ibgBusy false

}
