#!/usr/bin/env bash
#
# Point the whole project at your domain.
#
#   bash set-domain.sh ibphone.eclipselivecam.online
#
# The domain is baked into six places, three of them the router's walled
# garden. A half-finished find-and-replace is the worst outcome here: the
# site would work, the router would look configured, and unpaid customers
# would silently be unable to reach the signup page — the one thing the
# walled garden exists to allow. So this does all six and verifies.

set -euo pipefail

NEW="${1:-}"
if [ -z "$NEW" ]; then
  echo "Usage: bash set-domain.sh yourdomain.com" >&2
  exit 1
fi

# Strip anything that is not the bare host.
NEW="${NEW#http://}"; NEW="${NEW#https://}"; NEW="${NEW%%/*}"

if ! printf '%s' "$NEW" | grep -qE '^[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9-]*[a-zA-Z0-9])?)+$'; then
  echo "That does not look like a hostname: $NEW" >&2
  exit 1
fi

ROOT="$(cd "$(dirname "$0")" && pwd)"

# Current value, read from the sync script so there is one source of truth.
OLD=$(grep -oE 'https://[^/]+/api/router-sync\.php' "$ROOT/router/router-sync.rsc" \
      | head -1 | sed -E 's#https://([^/]+)/.*#\1#')
OLD="${OLD:-ibgadgets.ng}"

if [ "$OLD" = "$NEW" ]; then
  echo "Already set to $NEW"
  exit 0
fi

echo "Rewriting  $OLD  ->  $NEW"

# The hotspot's own local name. Served by the router, never resolved on
# the internet — but keeping it under your domain avoids .local, which
# Apple devices treat as mDNS and handle badly.
OLD_WIFI="wifi.${OLD}"
NEW_WIFI="wifi.${NEW}"

files=(
  "router/setup.rsc"
  "router/router-sync.rsc"
  "router/hotspot/login.html"
  "private/config.example.php"
  "router/preview-login.php"
)

for f in "${files[@]}"; do
  [ -f "$ROOT/$f" ] || { echo "  missing: $f" >&2; exit 1; }
  sed -i "s|${OLD_WIFI}|${NEW_WIFI}|g; s|${OLD}|${NEW}|g" "$ROOT/$f"
  echo "  updated  $f"
done

# The captive portal link should go straight to HTTPS: .htaccess redirects
# HTTP anyway, and skipping the hop means one less thing to get stuck on
# for a customer who has no internet yet.
sed -i "s|href=\"http://${NEW}/|href=\"https://${NEW}/|" "$ROOT/router/hotspot/login.html"

echo
echo "Checking nothing was missed:"
if leftovers=$(grep -rn "$OLD" "$ROOT/router" "$ROOT/private" 2>/dev/null | grep -v "/preview/"); then
  echo "  !! still referencing $OLD:"
  echo "$leftovers" | sed 's/^/     /'
  exit 1
fi
echo "  ok  no references to $OLD remain"

echo
echo "Now pointing at $NEW:"
grep -rn "$NEW" "$ROOT/router" "$ROOT/private" | grep -v "/preview/" | sed 's/^/  /'

cat <<TXT

Next:
  1. php router/preview-login.php     regenerate the previews
  2. bash build-deploy.sh             repackage for upload
  3. Make sure private/config.php (yours, not the example) has
     site_url = https://${NEW}
TXT
