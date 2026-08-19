#!/usr/bin/env bash
#
# Packages exactly what goes onto the host, and nothing else.
#
#   bash build-deploy.sh
#   -> dist/ibgadgets-deploy.zip
#
# Deliberately excluded: private/config.php (your secrets), uploads
# (customer receipts), tests, docs, the router scripts and the git
# history. The router files are packaged separately because they go to
# the Mikrotik, not to Hostinger.

set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
OUT="$ROOT/dist"
STAGE="$(mktemp -d)"

rm -rf "$OUT"; mkdir -p "$OUT"

# ---- what goes on the web host --------------------------------------
mkdir -p "$STAGE/private" "$STAGE/public_html"

cp -r "$ROOT/public_html/." "$STAGE/public_html/"
cp -r "$ROOT/private/lib"            "$STAGE/private/"
cp    "$ROOT/private/bootstrap.php"  "$STAGE/private/"
cp    "$ROOT/private/config.example.php" "$STAGE/private/"
cp    "$ROOT/private/make-admin.php" "$STAGE/private/"
cp    "$ROOT/private/.htaccess"      "$STAGE/private/"

# Never ship secrets, receipts or preview leftovers.
rm -f  "$STAGE/private/config.php"
rm -rf "$STAGE/private/uploads"
find "$STAGE/public_html" -name '_preview-*' -delete
mkdir -p "$STAGE/private/uploads"
: > "$STAGE/private/uploads/.gitkeep"

# The SQL travels with it so the database can be built on the host.
mkdir -p "$STAGE/db"
cp -r "$ROOT/db/." "$STAGE/db/"

cat > "$STAGE/READ-ME-FIRST.txt" <<'TXT'
IB Gadgets Telecom — upload layout

  private/       ->  /home/USER/private/        (NOT inside public_html)
  public_html/   ->  /home/USER/public_html/
  db/            ->  anywhere; import it, then delete it from the server

Then:
  1. cp private/config.example.php private/config.php  and fill it in
  2. Import db/schema.sql then db/seed.sql
  3. php private/make-admin.php you@example.com 'password' owner
  4. chmod 750 private/uploads
  5. Log in at /admin.html and set your real bank details in Settings

Full instructions: docs/deploy.md in the repository.
TXT

( cd "$STAGE" && zip -qr "$OUT/ibgadgets-deploy.zip" . )

# ---- what goes on the router ----------------------------------------
# preview/ is deliberately excluded: those files have their conditionals
# already resolved, so uploading one would give a login form that posts
# nowhere and an error box that can never appear.
( cd "$ROOT" && zip -qr "$OUT/ibgadgets-router.zip" router -x "router/preview/*" )

rm -rf "$STAGE"

echo "Built:"
ls -lh "$OUT" | awk 'NR>1 {print "  " $9 "  " $5}'
echo
echo "Check nothing secret slipped in:"
if unzip -l "$OUT/ibgadgets-deploy.zip" | grep -qE 'config\.php$'; then
  echo "  !! config.php IS IN THE PACKAGE — stop and investigate"; exit 1
else
  echo "  ok  no config.php"
fi
if unzip -l "$OUT/ibgadgets-deploy.zip" | grep -qE 'uploads/.+'; then
  echo "  !! customer receipts are in the package — stop"; exit 1
else
  echo "  ok  no receipts"
fi
