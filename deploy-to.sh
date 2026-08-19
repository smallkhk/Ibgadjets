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
