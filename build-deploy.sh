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

# And the smoke test, so it can be run ON the server straight after
# upload. It is a shell script above the document root, so it is not
# web reachable — but delete it once you are happy, along with db/.
mkdir -p "$STAGE/tests"
cp -r "$ROOT/tests/." "$STAGE/tests/"

# Repair tool, for the common case of unzipping into the document root.
cp "$ROOT/fix-layout.sh" "$STAGE/"

cat > "$STAGE/READ-ME-FIRST.txt" <<'TXT'
IB Gadgets Telecom — upload layout

  private/       ->  /home/USER/private/        (NOT inside public_html)
  public_html/   ->  /home/USER/public_html/
  db/            ->  anywhere; import it, then delete it from the server

IF YOU UNZIPPED THIS STRAIGHT INTO YOUR DOCUMENT ROOT, STOP AND RUN:
  bash fix-layout.sh            (shows what is wrong, changes nothing)
  bash fix-layout.sh --apply    (fixes it)
private/ holds your database password and must never sit under a URL.

Then:
  1. cp private/config.example.php private/config.php  and fill it in
  2. Import db/schema.sql then db/seed.sql
  3. php private/make-admin.php you@example.com 'password' owner
  4. chmod 750 private/uploads
  5. Log in at /admin.html and set your real bank details in Settings

Full instructions: docs/deploy.md in the repository.
TXT

STAGE_PUBLIC="$STAGE/public_html"
STAGE_PRIVATE="$STAGE/private"

( cd "$STAGE" && zip -qr "$OUT/ibgadgets-deploy.zip" . )

# ---- a variant shaped for a subdomain docroot ------------------------
#
# Hostinger gives a subdomain its own folder, e.g. ~/ibphone, and that
# folder IS the document root. The layout above does not fit: unzip it
# there and private/ lands under a public URL, while the site sits one
# level too deep in public_html/ where nothing serves it.
#
# So build a second zip that is extracted at the HOME directory instead,
# and drops each part exactly where it belongs in one step.
#
#   bash build-deploy.sh ibphone   ->  dist/ibgadgets-<name>.zip
#
if [ -n "${1:-}" ]; then
  DOCROOT="$1"
  SUB="$(mktemp -d)"

  mkdir -p "$SUB/$DOCROOT"
  cp -r "$STAGE_PUBLIC/." "$SUB/$DOCROOT/"
  cp -r "$STAGE_PRIVATE"  "$SUB/private"
  cp -r "$ROOT/db"        "$SUB/db"
  cp -r "$ROOT/tests"     "$SUB/tests"

  cat > "$SUB/READ-ME-FIRST.txt" <<TXT
IB Gadgets Telecom — extract this in your HOME directory, not in the
website folder.

  cd ~
  unzip -o ibgadgets-$DOCROOT.zip

That produces:

  ~/$DOCROOT/     the website itself (this is your document root)
  ~/private/      database password and sync key — ABOVE the web root
  ~/db/           the SQL to import
  ~/tests/        the smoke test

private/ sits outside $DOCROOT on purpose. It holds your database
password and the router sync key, and nothing in it should ever be
reachable by URL.

Then:
  1. cp private/config.example.php private/config.php   and fill it in
  2. Import db/schema.sql then db/seed.sql
  3. php private/make-admin.php you\@example.com 'a-long-password' owner
  4. chmod 750 private/uploads
  5. Log in at /admin.html and set your real bank details
  6. bash tests/e2e.sh   (see docs/deploy.md for the full command)

Delete db/ and tests/ once the test passes.
TXT

  ( cd "$SUB" && zip -qr "$OUT/ibgadgets-$DOCROOT.zip" . )
  rm -rf "$SUB"
fi

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
