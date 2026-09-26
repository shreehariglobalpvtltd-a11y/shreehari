#!/usr/bin/env bash
# =====================================================================
#  deploy/live-remote-state.sh — runs ON THE VPS, reads only.
#
#  Says what the webroot's git holds: the commit it is on, whether any
#  tracked file was changed without a commit, which untracked files sit
#  outside the runtime folders, and what changed in the last minutes.
#  git runs with --no-optional-locks, so not even the index stat cache
#  is rewritten. Prints file NAMES, never file contents.
#
#  Used by .github/workflows/live-sync.yml; also safe to run by hand:
#      SITE=/var/www/shreehariglobal.in/public_html bash deploy/live-remote-state.sh
# =====================================================================
set -uo pipefail

: "${SITE:?SITE (the webroot) is required}"
RECENT_MIN="${RECENT_MIN:-15}"
RUNTIME='^(uploads|tickets|qr|invoice|backup|backups|logs|cache|tmp|output)/'

cd "$SITE" 2>/dev/null || { echo "STATE_ERROR=webroot not found"; exit 1; }
G() { git -c safe.directory='*' --no-optional-locks "$@"; }

if ! G rev-parse --git-dir >/dev/null 2>&1; then
  echo "STATE_ERROR=the webroot is not a git work tree"
  exit 1
fi

echo "GITDIR=$(G rev-parse --absolute-git-dir)"
echo "HEAD=$(G rev-parse HEAD)"
echo "BRANCH=$(G symbolic-ref -q --short HEAD || echo DETACHED)"
echo "SHALLOW=$(G rev-parse --is-shallow-repository)"
echo "HEAD_TIME=$(G log -1 --format=%cI)"

dirty="$(G status --porcelain --untracked-files=no)"
echo "DIRTY_TRACKED=$(printf '%s' "$dirty" | grep -c . || true)"

untracked="$(G status --porcelain --untracked-files=normal | sed -n 's/^?? //p' | grep -vE "$RUNTIME" || true)"
echo "UNTRACKED=$(printf '%s' "$untracked" | grep -c . || true)"

# Untracked, not ignored, and touched in the last RECENT_MIN minutes: the
# sign of someone writing new files that are not committed yet.
recent=""
while IFS= read -r p; do
  [ -n "$p" ] || continue
  hit="$(find "$p" -xdev -type f -mmin "-$RECENT_MIN" -print -quit 2>/dev/null)"
  [ -n "$hit" ] && recent="$recent$p"$'\n'
done <<< "$untracked"
echo "RECENT_UNTRACKED=$(printf '%s' "$recent" | grep -c . || true)"

echo "--- tracked files changed but not committed (first 80):"
printf '%s\n' "$dirty" | grep . | head -80
echo "--- untracked files outside the runtime folders (first 80):"
printf '%s\n' "$untracked" | grep . | head -80
echo "--- of those, touched in the last $RECENT_MIN minutes:"
printf '%s' "$recent" | grep . | head -40
echo "--- end of state"
exit 0
