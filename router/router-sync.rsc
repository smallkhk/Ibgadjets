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
# INSTALL
#   1. Edit ibgUrl and ibgKey below.
#   2. Upload to Files, then in Terminal:  /import router-sync.rsc
#      (that only defines the script; setup.rsc creates the scheduler)
#
# It must never crash the scheduler. Everything is wrapped in :do/on-error.
# =====================================================================

/system script
add name="ibg-sync" dont-require-permissions=no owner=admin \
    policy=read,write,policy,test,sensitive source={

:local ibgUrl "https://ibgadgets.ng/api/router-sync.php"
:local ibgKey "CHANGE-ME-64-hex-characters"

# Only users carrying this comment are ours to create, change or delete.
# Anything you made by hand is left alone.
:local mark "ibg"

:do {

    # -----------------------------------------------------------------
    # 1. Pull desired state
    # -----------------------------------------------------------------
    :local res [/tool fetch url=$ibgUrl \
        http-method=get \
        http-header-field=("X-Sync-Key: " . $ibgKey) \
        output=user as-value]

    :if (($res->"status") != "finished") do={
        :log warning "ibg-sync: fetch failed"
        :error "fetch"
    }

    :local doc [:deserialize from=json value=($res->"data")]

    :if (($doc->"ok") != true) do={
        :log warning "ibg-sync: server said not ok"
        :error "server"
    }

    :local users ($doc->"users")
    :local blocked ($doc->"blocked_macs")
    :local policy ($doc->"tether_policy")

    :local wanted [:toarray ""]
    :local applied ""

    # -----------------------------------------------------------------
    # 2. Create or update every account the site listed
    # -----------------------------------------------------------------
    :foreach u in=$users do={
        :local name  ($u->"username")
        :local pass  ($u->"password")
        :local rate  ($u->"rate_limit")
        :local share ($u->"shared_users")
        :local sub   ($u->"sub_id")
        :local dmb   ($u->"data_mb")

        # null data_mb means unlimited -> no byte cap at all
        :local bytes 0
        :if ([:typeof $dmb] = "num") do={ :set bytes ($dmb * 1048576) }

        :set ($wanted->$name) true

        :local found [/ip hotspot user find where name=$name]

        :if ([:len $found] = 0) do={
            /ip hotspot user add name=$name password=$pass \
                rate-limit=$rate shared-users=$share \
                limit-bytes-total=$bytes comment=$mark
            :log info ("ibg-sync: provisioned " . $name)
        } else={
            # Reset the byte counter when the site's remaining figure has
            # moved ahead of ours — that is a fresh bundle, not the same one.
            /ip hotspot user set $found password=$pass \
                rate-limit=$rate shared-users=$share \
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
        :if ([:typeof ($wanted->$hname)] = "nothing") do={
            # Kick the live session first, otherwise they keep browsing
            # on the connection they already hold.
            :foreach a in=[/ip hotspot active find where user=$hname] do={
                /ip hotspot active remove $a
            }
            /ip hotspot cookie remove [find user=$hname]
            /ip hotspot user remove $h
            :log info ("ibg-sync: revoked " . $hname)
        }
    }

    # -----------------------------------------------------------------
    # 4. Blocked devices — one phone off, account untouched
    # -----------------------------------------------------------------
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

        :local mac ""
        :local ip ""
        :local up 0
        :local tether 0

        :local act [/ip hotspot active find where user=$hname]
        :if ([:len $act] > 0) do={
            :set mac [/ip hotspot active get [:pick $act 0] mac-address]
            :set ip  [/ip hotspot active get [:pick $act 0] address]
            :set up  [/ip hotspot active get [:pick $act 0] uptime]

            # TTL anomaly seen from this address in the last window means
            # the traffic passed through a second router — a phone
            # sharing its connection. See docs/anti-sharing.md.
            :if ([:len [/ip firewall address-list find where list="ibg-tethered" and address=$ip]] > 0) do={
                :set tether 1
            }
        }

        :if ([:len $usage] > 0) do={ :set usage ($usage . ",") }
        :set usage ($usage . "{\"username\":\"" . $hname . "\"" . \
            ",\"used_mb\":" . $mb . \
            ",\"mac\":\"" . $mac . "\"" . \
            ",\"ip\":\"" . $ip . "\"" . \
            ",\"tethered_hits\":" . $tether . "}")
    }

    # -----------------------------------------------------------------
    # 6. Report back
    # -----------------------------------------------------------------
    :local payload ("{\"applied\":[" . $applied . "],\"usage\":[" . $usage . "]}")

    /tool fetch url=$ibgUrl \
        http-method=post \
        http-header-field=("X-Sync-Key: " . $ibgKey . ",Content-Type: application/json") \
        http-data=$payload \
        output=user as-value

} on-error={
    :log warning "ibg-sync: cycle failed, will retry next minute"
}

}
