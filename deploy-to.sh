#!/usr/bin/env bash
#
# Copy a git checkout onto a live server.
#
#   bash deploy-to.sh ~/ibphone
#   bash deploy-to.sh ~/ibphone --dry-run     show what would change
#
# WHY THIS EXISTS
#
# The repository is laid out as public_html/ + private/, but a Hostinger
# subdomain's document root is a folder like ~/ibphone with private/
# beside it. So `git pull` alone cannot update the site — the files have
# to be placed. This does that, and nothing else.
#
# WHAT IT WILL NEVER TOUCH
#
#   private/config.php     your database password and sync key
#   private/uploads/       customers' bank receipts
#
# Both are excluded explicitly rather than by luck: losing the first
# takes the site down, and losing the second loses evidence of payment.

set -uo pipefail

DOC="${1:-}"
DRY=0
[ "${2:-}" = "--dry-run" ] && DRY=1

if [ -z "$DOC" ]; then
  echo "Usage: bash deploy-to.sh /path/to/docroot [--dry-run]" >&2
  echo "  e.g. bash deploy-to.sh ~/ibphone" >&2
  exit 1
fi

DOC="${DOC%/}"
SRC="$(cd "$(dirname "$0")" && pwd)"
PRIV="$(dirname "$DOC")/private"

if [ ! -d "$DOC" ]; then
  echo "No such directory: $DOC" >&2
  exit 1
fi
if [ ! -d "$SRC/public_html" ]; then
  echo "$SRC does not look like the repository (no public_html/)" >&2
  exit 1
fi

echo "from : $SRC"
echo "site : $DOC"
echo "priv : $PRIV"
[ "$DRY" = "1" ] && echo "MODE : dry run, nothing will be written"
echo

# ---------------------------------------------------------------------
# The website
# ---------------------------------------------------------------------
echo "Website files"
if command -v rsync >/dev/null 2>&1; then
  FLAGS=(-a --itemize-changes)
  [ "$DRY" = "1" ] && FLAGS+=(--dry-run)
  rsync "${FLAGS[@]}" "$SRC/public_html/" "$DOC/" | sed 's/^/  /' | head -40
else
  if [ "$DRY" = "1" ]; then
    echo "  (no rsync; would copy public_html/* into $DOC)"
  else
    cp -r "$SRC/public_html/." "$DOC/" && echo "  copied public_html -> $DOC"
  fi
fi

# ---------------------------------------------------------------------
# Cache busting
#
# .htaccess tells browsers to keep CSS and JS for seven days, which is
# right for customers on metered phone data and wrong every time we
# deploy: the HTML is fresh, the JavaScript behind it is a week old, and
# new buttons are simply absent. "Hard refresh" fixes it for whoever is
# told to do that and nobody else — including every customer.
#
# So each deploy stamps a version onto the asset URLs. The file content
# is identical; the URL is not, so browsers fetch it once and then cache
# it properly again until the next deploy.
#
# Idempotent: an existing ?v= is replaced rather than appended to.
# ---------------------------------------------------------------------
if [ "$DRY" = "1" ]; then
  echo
  echo "  would stamp a fresh ?v= on the asset links in each .html"
else
  STAMP="$(date +%Y%m%d%H%M%S)"
  echo
  echo "Cache busting (?v=$STAMP)"
  for f in "$DOC"/*.html; do
    [ -f "$f" ] || continue
    sed -i -E "s#(assets/(css|js)/[a-zA-Z0-9._-]+)(\?v=[0-9]+)?#\1?v=$STAMP#g" "$f"
    echo "  stamped  $(basename "$f")"
  done
fi

# ---------------------------------------------------------------------
# The library code, but never the secrets
# ---------------------------------------------------------------------
echo
echo "Private library (config.php and uploads/ are left alone)"
mkdir -p "$PRIV"
for item in lib bootstrap.php make-admin.php init-config.php config.example.php .htaccess; do
  [ -e "$SRC/private/$item" ] || continue
  if [ "$DRY" = "1" ]; then
    echo "  would update  private/$item"
  else
    cp -r "$SRC/private/$item" "$PRIV/" && echo "  updated  private/$item"
  fi
done

# Belt and braces: prove we did not clobber the two things that matter.
if [ "$DRY" = "0" ]; then
  [ -f "$PRIV/config.php" ] && echo "  intact   private/config.php" \
                            || echo "  NOTE     private/config.php does not exist — run init-config.php"
  [ -d "$PRIV/uploads" ]    && echo "  intact   private/uploads/ ($(find "$PRIV/uploads" -type f 2>/dev/null | wc -l) files)" \
                            || mkdir -p "$PRIV/uploads"
fi

# ---------------------------------------------------------------------
echo
if [ "$DRY" = "1" ]; then
  echo "Dry run only. Re-run without --dry-run to apply."
  exit 0
fi

echo "Done. Check the site:"
echo "  curl -s https://ibphone.eclipselivecam.online/api/plans.php | head -c 80"
echo
echo "If a page looks unchanged in your browser, hard-refresh it"
echo "(Ctrl+Shift+R). The old JavaScript will be cached otherwise."
