#!/usr/bin/env bash
# =====================================================================
#  deploy/live-remote-state.sh — runs ON THE VPS, reads only.
#
#  Says what the webroot's git holds: the commit it is on, whether any
#  tracked file was changed without a commit, how many untracked files
#  sit outside the runtime folders, and whether any of them changed in
#  the last minutes. git runs with --no-optional-locks, so not even the
#  index stat cache is rewritten.
#
#  Its output goes to a PUBLIC Actions log, so it prints counts and
#  top-level folder names only. SHOW_NAMES=1 lists the file names too;
#  use that only by hand on the server, never from the workflow.
#
#      SITE=/var/www/shreehariglobal.in/public_html bash deploy/live-remote-state.sh
# =====================================================================
set -uo pipefail

: "${SITE:?SITE (the webroot) is required}"
RECENT_MIN="${RECENT_MIN:-15}"
SHOW_NAMES="${SHOW_NAMES:-0}"
# Folders the site writes to at run time, at any depth.
RUNTIME='(^|/)(uploads|tickets|qr|invoice|backup|backups|logs|cache|tmp|output)/'

cd "$SITE" 2>/dev/null || { echo "STATE_ERROR=webroot not found"; exit 1; }
G() { git -c safe.directory='*' -c core.quotePath=false --no-optional-locks "$@"; }

if ! G rev-parse --git-dir >/dev/null 2>&1; then
  echo "STATE_ERROR=the webroot is not a git work tree"
  exit 1
fi

echo "GITDIR=$(G rev-parse --absolute-git-dir)"
echo "HEAD=$(G rev-parse HEAD)"
echo "BRANCH=$(G symbolic-ref -q --short HEAD || echo DETACHED)"
echo "SHALLOW=$(G rev-parse --is-shallow-repository)"
echo "HEAD_TIME=$(G log -1 --format=%cI)"

# NUL-separated, so names with spaces, quotes or Devanagari come through
# exactly as they are on disk.
st="$(mktemp)"
trap 'rm -f "$st"' EXIT
if ! G status --porcelain -z --no-renames --untracked-files=normal > "$st"; then
  echo "STATE_ERROR=git status failed"
  exit 1
fi

dirty=(); untracked=(); recent=()
while IFS= read -r -d '' e; do
  if [ "${e:0:3}" = '?? ' ]; then
    p="${e:3}"
    [[ "$p" =~ $RUNTIME ]] && continue
    untracked+=("$p")
    # "./" first, so a name that starts with "-" can never be read by
    # find as an option. If find cannot tell, count it as recent.
    if ! hit="$(find "./$p" -xdev -type f -mmin "-$RECENT_MIN" -print -quit 2>/dev/null)"; then
      recent+=("$p"); continue
    fi
    [ -n "$hit" ] && recent+=("$p")
  else
    dirty+=("${e:3}")
  fi
done < "$st"

echo "DIRTY_TRACKED=${#dirty[@]}"
echo "UNTRACKED=${#untracked[@]}"
echo "RECENT_UNTRACKED=${#recent[@]}"

# Top-level folder of each entry, counted. A file at the top level is not
# named: its name alone could say too much in a public log.
tops() {
  local p
  for p in "$@"; do
    case "$p" in */*) printf '%s/\n' "${p%%/*}" ;; *) echo "(top-level file)" ;; esac
  done | sort | uniq -c | sort -rn | head -20
}
list() {
  if [ "$SHOW_NAMES" = 1 ]; then printf '%s\n' "$@" | head -80; else tops "$@"; fi
}

echo "--- tracked files changed but not committed:"
[ "${#dirty[@]}" -eq 0 ] || list "${dirty[@]}"
echo "--- untracked files outside the runtime folders:"
[ "${#untracked[@]}" -eq 0 ] || list "${untracked[@]}"
echo "--- of those, touched in the last $RECENT_MIN minutes:"
[ "${#recent[@]}" -eq 0 ] || list "${recent[@]}"
echo "--- end of state"
exit 0
