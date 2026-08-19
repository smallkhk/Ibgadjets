#!/usr/bin/env bash
#
# Repair a deployment that was unzipped straight into the document root.
#
#   bash fix-layout.sh              show what is wrong, change nothing
#   bash fix-layout.sh --apply      actually fix it
#
# THE PROBLEM
#
# The package expects private/ to sit BESIDE the document root, never
# inside it. Unzipping into the docroot puts private/config.php — your
# database password and sync key — underneath a public URL. private/
# ships with a .htaccess that denies everything, so it is usually still
# refused, but that is one config away from being the only thing between
# your credentials and the internet. It does not belong there.
#
# It also leaves the site's files one level too deep, under public_html/,
# where nothing serves them.
#
# WHAT IT DOES
#
#   ~/DOCROOT/private   ->  ~/private        (out of the web root)
#   ~/DOCROOT/db        ->  ~/db             (out of the web root)
#   ~/DOCROOT/public_html/*  ->  ~/DOCROOT/  (up to where they are served)
#   removes the zip, the extraction folder and READ-ME-FIRST.txt
#
# Nothing is deleted that holds your data: private/ and db/ are moved,
# not removed, and an existing ~/private is never overwritten.

set -uo pipefail

APPLY=0
[ "${1:-}" = "--apply" ] && APPLY=1

DOC="$(pwd)"
PARENT="$(dirname "$DOC")"

say()  { printf '%s\n' "$*"; }
step() { printf '\n\033[1m%s\033[0m\n' "$*"; }
run()  {
  if [ "$APPLY" = "1" ]; then
    if eval "$1"; then printf '  done   %s\n' "$1"; else printf '  FAILED %s\n' "$1"; fi
  else
    printf '  would %s\n' "$1"
  fi
}

step "Working in: $DOC"
say  "Assuming this is the document root for your site."
say  "If https://yourdomain/ does NOT serve files from here, stop now."

# ---------------------------------------------------------------------
if [ -f "$DOC/private/config.php" ] && [ -f "$PARENT/private/config.php" ]; then
  step "STOP"
  say "  Two config.php files exist and I cannot tell which one is live:"
  say "    $DOC/private/config.php"
  say "    $PARENT/private/config.php"
  say "  Delete the one you do not want, then run this again."
  exit 1
fi

step "1. Move private/ out of the web root"
if [ -d "$DOC/private" ]; then
  if [ -e "$PARENT/private" ]; then
    say "  ! $PARENT/private already exists — not overwriting."
    say "    Merge them by hand, keeping whichever config.php you filled in."
  else
    run "mv '$DOC/private' '$PARENT/private'"
  fi
else
  say "  already out of the web root (or not unpacked here)"
fi

# ---------------------------------------------------------------------
step "2. Move db/ out of the web root"
say  "  You still need it to import the schema; it just must not be public."
if [ -d "$DOC/db" ]; then
  if [ -e "$PARENT/db" ]; then
    say "  ! $PARENT/db already exists — leaving both alone."
  else
    run "mv '$DOC/db' '$PARENT/db'"
  fi
else
  say "  already out of the web root"
fi

# ---------------------------------------------------------------------
step "2b. Move tests/ out of the web root"
if [ -d "$DOC/tests" ]; then
  if [ -e "$PARENT/tests" ]; then
    say "  ! $PARENT/tests already exists — leaving both alone."
  else
    run "mv '$DOC/tests' '$PARENT/tests'"
  fi
else
  say "  already out of the web root"
fi

# ---------------------------------------------------------------------
step "3. Promote the site files up to the docroot"
NAME="$(basename "$DOC")"
if [ -d "$DOC/$NAME" ]; then
  # The home-directory package was extracted inside the docroot instead
  # of beside it, so the site sits in ~/NAME/NAME. Nothing serves that.
  say "  found $NAME/ inside $NAME/ — the zip went one level too deep"
  run "( shopt -s dotglob nullglob; mv '$DOC/$NAME'/* '$DOC'/ )"
  run "rmdir '$DOC/$NAME'"
fi
if [ -d "$DOC/public_html" ]; then
  say "  The docroot IS the public folder here, so public_html/ is one"
  say "  level too deep. Replacing the loose copies with its contents."
  for item in index.html admin.html dashboard.html api assets cron .htaccess; do
    if [ -e "$DOC/public_html/$item" ]; then
      run "rm -rf '$DOC/${item}'"
      run "mv '$DOC/public_html/$item' '$DOC/'"
    fi
  done
  run "rmdir '$DOC/public_html' 2>/dev/null || true"
else
  say "  no public_html/ here — nothing to promote"
fi

# ---------------------------------------------------------------------
step "4. Remove packaging leftovers"
for junk in ibgadgets-deploy.zip ibgadgets-deploy ibgadgets-router.zip READ-ME-FIRST.txt; do
  [ -e "$DOC/$junk" ] && run "rm -rf '$DOC/${junk}'"
done

# ---------------------------------------------------------------------
step "5. Check"
if [ "$APPLY" = "1" ]; then
  [ -f "$DOC/index.html" ]        && say "  ok    index.html is in the docroot" \
                                  || say "  MISSING index.html"
  [ -f "$DOC/.htaccess" ]         && say "  ok    .htaccess is in the docroot" \
                                  || say "  MISSING .htaccess — security headers and HTTPS redirect are off"
  [ -d "$DOC/api" ]               && say "  ok    api/ is in the docroot" \
                                  || say "  MISSING api/"
  [ -d "$PARENT/private" ]        && say "  ok    private/ is above the docroot" \
                                  || say "  MISSING private/ — the site cannot boot"
  [ ! -e "$DOC/private" ]         && say "  ok    no private/ inside the docroot" \
                                  || say "  STILL EXPOSED: private/ is inside the docroot"

  say ""
  say "Now confirm from outside. Both must be 403 or 404, never 200:"
  say "  curl -s -o /dev/null -w 'config: %{http_code}\\n' https://YOURDOMAIN/private/config.php"
  say "  curl -s -o /dev/null -w 'schema: %{http_code}\\n' https://YOURDOMAIN/db/schema.sql"
else
  say ""
  say "Nothing was changed. Re-run with --apply to do it:"
  say "  bash fix-layout.sh --apply"
fi
