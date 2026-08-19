#!/usr/bin/env bash
#
# End-to-end smoke test: customer signs up, buys, admin approves, router
# provisions, usage flows back, admin changes the device limit, admin
# suspends, router drops them.
#
# Run it against a fresh install BEFORE you trust it with customers:
#
#   BASE=https://yourdomain SYNC_KEY=... ADMIN_EMAIL=... ADMIN_PASS=... \
#     bash tests/e2e.sh
#
# Defaults target a local php -S on 127.0.0.1:8824.

set -uo pipefail

BASE="${BASE:-http://127.0.0.1:8824}"
SYNC_KEY="${SYNC_KEY:-testsynckey0123456789abcdef0123456789abcdef0123456789abcdef012345}"
ADMIN_EMAIL="${ADMIN_EMAIL:-owner@ibgadgets.ng}"
ADMIN_PASS="${ADMIN_PASS:-testpass1234}"
# php rather than shuf: coreutils is not guaranteed on shared hosting,
# but PHP is — the whole project runs on it.
PHONE="${PHONE:-0803$(php -r 'echo random_int(1000000,9999999);')}"
# What the ROUTER calls this customer. Not the same string as the phone
# number: RouterOS's JSON parser reads a bare 08... as a number and eats
# the leading zero, so the wire name carries a prefix that cannot be
# parsed as one. Line 119 deliberately reports usage under the bare phone
# instead — old accounts predate the prefix, and the server has to keep
# matching them or their usage goes unbilled in silence.
ACCOUNT="ib${PHONE}"

CJ=$(mktemp); AJ=$(mktemp)
PASS=0; FAIL=0

say()  { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()   { PASS=$((PASS+1)); printf '  \033[32mok\033[0m   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  \033[31mFAIL\033[0m %s\n     %s\n' "$1" "${2:-}"; }
want() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1" "got [$2] want [$3]"; fi; }
has()  { if printf '%s' "$2" | grep -q "$3"; then ok "$1"; else bad "$1" "missing [$3] in ${2:0:200}"; fi; }

jq_get() { printf '%s' "$1" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $p=explode(".",$argv[1]); foreach($p as $k){ if(is_array($j)&&array_key_exists($k,$j)){$j=$j[$k];} elseif(is_array($j)&&ctype_digit($k)&&isset($j[(int)$k])){$j=$j[(int)$k];} else {echo ""; exit;} } echo is_bool($j)?($j?"true":"false"):(is_scalar($j)?$j:json_encode($j));' "$2"; }

# ---------------------------------------------------------------- customer
say "Customer signs up  ($PHONE)"
ME=$(curl -s -c "$CJ" "$BASE/api/auth.php?action=me")
CSRF=$(jq_get "$ME" csrf)
[ -n "$CSRF" ] && ok "got a CSRF token" || bad "got a CSRF token" "$ME"

SIGNUP=$(curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/api/auth.php?action=signup" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d "{\"phone\":\"$PHONE\",\"password\":\"hunter2222\",\"full_name\":\"E2E Tester\",\"type\":\"compound\",\"flat_no\":\"9Z\"}")
if printf '%s' "$SIGNUP" | grep -q 'Too many attempts'; then
  printf '\n\033[33mStopped: the signup rate limiter is holding this IP.\033[0m\n'
  printf 'That is the limiter working, not a fault — it allows 5 signups per IP\n'
  printf 'per 10 minutes. Wait it out, or clear it while testing:\n\n'
  printf "  mysql -u USER -p DBNAME -e \"DELETE FROM rate_limits WHERE bucket LIKE 'signup:%%';\"\n\n"
  rm -f "$CJ" "$AJ"; exit 2
fi
want "signup succeeded" "$(jq_get "$SIGNUP" ok)" "true"
want "phone normalised"  "$(jq_get "$SIGNUP" customer.phone)" "$PHONE"

say "CSRF is actually enforced"
NOCSRF=$(curl -s -b "$CJ" -X POST "$BASE/api/auth.php?action=logout" -H "Content-Type: application/json" -d '{}')
want "logout without a token is refused" "$(jq_get "$NOCSRF" ok)" "false"

# ---------------------------------------------------------------- purchase
say "Buys Heavy 5GB (below the OPay threshold, so manual)"
BUY=$(curl -s -b "$CJ" -X POST "$BASE/api/purchase.php" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d '{"plan_id":3,"method":"bank_transfer"}')
want "purchase created" "$(jq_get "$BUY" ok)" "true"
want "manual path chosen" "$(jq_get "$BUY" automatic)" "false"
REF=$(jq_get "$BUY" reference)
has "reference issued" "$REF" "IB-"

say "Router must NOT see an unpaid customer"
SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
if printf '%s' "$SYNC" | grep -q "$PHONE"; then bad "unpaid customer is absent" "found $PHONE"; else ok "unpaid customer is absent"; fi

say "Sync endpoint rejects a bad key"
BADKEY=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/api/router-sync.php" -H "X-Sync-Key: wrong")
want "wrong key gets 403" "$BADKEY" "403"

# ------------------------------------------------------------------- admin
say "Admin approves the payment"
AME=$(curl -s -c "$AJ" "$BASE/api/admin.php?action=me")
ACSRF=$(jq_get "$AME" csrf)
LOGIN=$(curl -s -b "$AJ" -c "$AJ" -X POST "$BASE/api/admin.php?action=login" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF" \
  -d "{\"email\":\"$ADMIN_EMAIL\",\"password\":\"$ADMIN_PASS\"}")
want "admin logged in" "$(jq_get "$LOGIN" ok)" "true"

TXID=$(curl -s -b "$AJ" "$BASE/api/admin.php?action=transactions&status=pending" \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["transactions"]??[]) as $t){ if($t["reference"]===$argv[1]){echo $t["id"];exit;} }' "$REF")
[ -n "$TXID" ] && ok "payment is in the approval queue" || bad "payment is in the approval queue" "no row for $REF"

APPROVE=$(curl -s -b "$AJ" -X POST "$BASE/api/admin.php?action=approve" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF" -d "{\"id\":$TXID}")
want "approved" "$(jq_get "$APPROVE" ok)" "true"

# ------------------------------------------------------------------ router
say "Router now sees them, with the right limits"
SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
IDX=$(printf '%s' "$SYNC" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["users"]??[]) as $i=>$u){ if($u["username"]===$argv[1]){echo $i;exit;} } echo "";' "$ACCOUNT")
[ -n "$IDX" ] && ok "customer is on the desired-state list" || bad "customer is on the desired-state list" "$SYNC"

want "full 5GB allowance"      "$(jq_get "$SYNC" "users.$IDX.data_mb")"      "5120"
want "speed cap 30M/8M"        "$(jq_get "$SYNC" "users.$IDX.rate_limit")"   "30M/8M"
want "one device"              "$(jq_get "$SYNC" "users.$IDX.shared_users")" "1"
SUBID=$(jq_get "$SYNC" "users.$IDX.sub_id")

say "Router reports back what it applied, plus usage"
REPORT=$(curl -s -X POST "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"applied\":[$SUBID],\"usage\":[{\"username\":\"$ACCOUNT\",\"used_mb\":1834,\"mac\":\"AA:BB:CC:DD:EE:01\",\"ip\":\"10.5.50.14\",\"tethered_hits\":1}]}")
want "report accepted" "$(jq_get "$REPORT" ok)" "true"
want "one subscription confirmed" "$(jq_get "$REPORT" synced)" "1"

say "Remaining data is what gets sent, not the full bundle"
SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
IDX=$(printf '%s' "$SYNC" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["users"]??[]) as $i=>$u){ if($u["username"]===$argv[1]){echo $i;exit;} } echo "";' "$ACCOUNT")
want "5120 - 1834 = 3286 left" "$(jq_get "$SYNC" "users.$IDX.data_mb")" "3286"

say "A router reset cannot erase spent data"
curl -s -o /dev/null -X POST "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY" \
  -H "Content-Type: application/json" \
  -d "{\"applied\":[],\"usage\":[{\"username\":\"$PHONE\",\"used_mb\":0,\"mac\":\"AA:BB:CC:DD:EE:01\"}]}"
SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
IDX=$(printf '%s' "$SYNC" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["users"]??[]) as $i=>$u){ if($u["username"]===$argv[1]){echo $i;exit;} } echo "";' "$ACCOUNT")
want "usage held at 1834, still 3286 left" "$(jq_get "$SYNC" "users.$IDX.data_mb")" "3286"

# --------------------------------------------------------------- dashboard
say "Customer dashboard reflects it"
DASH=$(curl -s -b "$CJ" "$BASE/api/dashboard.php")
want "plan shown"        "$(jq_get "$DASH" current.plan_name)"   "Heavy 5GB"
want "usage shown"       "$(jq_get "$DASH" current.data_used_mb)" "1834"
want "live on router"    "$(jq_get "$DASH" current.live_on_router)" "true"
want "device learned from the router" "$(jq_get "$DASH" device_count)" "1"

# ---------------------------------------------------- admin device override
say "Admin allows 3 devices on a 1-device bundle"
CUSTID=$(curl -s -b "$AJ" "$BASE/api/admin.php?action=customers&q=$PHONE" \
  | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo $j["customers"][0]["id"]??"";')
SETDEV=$(curl -s -b "$AJ" -X POST "$BASE/api/admin.php?action=customer_update" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF" \
  -d "{\"id\":$CUSTID,\"device_limit\":3}")
want "override saved" "$(jq_get "$SETDEV" ok)" "true"

SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
IDX=$(printf '%s' "$SYNC" | php -r '$j=json_decode(stream_get_contents(STDIN),true); foreach(($j["users"]??[]) as $i=>$u){ if($u["username"]===$argv[1]){echo $i;exit;} } echo "";' "$ACCOUNT")
want "router told 3 devices" "$(jq_get "$SYNC" "users.$IDX.shared_users")" "3"

# ------------------------------------------------------------- suspension
say "Admin suspends them"
curl -s -o /dev/null -b "$AJ" -X POST "$BASE/api/admin.php?action=customer_update" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF" \
  -d "{\"id\":$CUSTID,\"status\":\"suspended\"}"
SYNC=$(curl -s "$BASE/api/router-sync.php" -H "X-Sync-Key: $SYNC_KEY")
if printf '%s' "$SYNC" | grep -q "$PHONE"; then bad "suspended customer is dropped" "still listed"; else ok "suspended customer is dropped"; fi

curl -s -o /dev/null -b "$AJ" -X POST "$BASE/api/admin.php?action=customer_update" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $ACSRF" \
  -d "{\"id\":$CUSTID,\"status\":\"active\"}"

# ------------------------------------------------------------------- login
say "Login works, wrong password does not"
# -c matters: logout regenerates the session id, and the reply carries the
# token for the new session. Drop either and the next call is unauthorised.
LOGOUT=$(curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/api/auth.php?action=logout" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" -d '{}')
CSRF=$(jq_get "$LOGOUT" csrf)
want "logout returns a fresh token" "$([ -n "$CSRF" ] && echo yes)" "yes"

GOOD=$(curl -s -b "$CJ" -c "$CJ" -X POST "$BASE/api/auth.php?action=login" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d "{\"phone\":\"$PHONE\",\"password\":\"hunter2222\"}")
want "correct password logs in" "$(jq_get "$GOOD" ok)" "true"
WRONG=$(curl -s -X POST "$BASE/api/auth.php?action=login" \
  -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d "{\"phone\":\"$PHONE\",\"password\":\"wrongwrong\"}")
want "wrong password refused" "$(jq_get "$WRONG" ok)" "false"

# --------------------------------------------------------------------- end
printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
rm -f "$CJ" "$AJ"
[ "$FAIL" -eq 0 ]
