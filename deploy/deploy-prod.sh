#!/usr/bin/env bash
# =====================================================================
#  deploy/deploy-prod.sh — the ONLY way code reaches production.
#
#  Run ON THE VPS, as root, ONLY after the owner has said yes:
#
#      bash deploy-prod.sh <base-branch> <new-ref> <release-name> [--dry-run]
#      bash deploy-prod.sh release-20261004-r2 desks-20261004 release-20261004-d1
#
#    base-branch   the release that is live right now
#    new-ref       the branch / commit being released (already pushed to
#                  /var/repos/shg.git)
#    release-name  the release-* branch this deploy creates
#
#  Files-only (the live webroot is not a git checkout). What it does, in
#  order, and stops at the first thing that is wrong:
#
#    1. DRIFT GUARD — for every file the release touches, md5(live) must
#       equal md5(base blob). If someone wrote to live in between (the
#       4 Oct 2026 outage: another session scp'd months-old files over
#       live), ABORT before anything is written.
#    2. Backup of every touched file + a database dump + ROLLBACK.txt in
#       /root/backups/pre-deploy-<release>-<timestamp>/.
#    3. php -l on every PHP file of the release, in a staging dir.
#    4. Write each file as <file>.deploytmp then mv (atomic), chown www-data.
#    5. Reload PHP-FPM, md5-verify live == release, curl home + admin login
#       (both must answer 200).
#
#  It never touches config/config.php, never runs a database migration
#  (apply those by hand, they are listed for you), never reloads nginx,
#  and never deploys docs/ or tests/. Files DELETED by the release are
#  listed, not removed — delete by hand after checking.
#
#  Do NOT use the old repo-root deploy.sh or the GitHub "Deploy to VPS"
#  workflow: both build from a stale tree and would revert live.
# =====================================================================
set -euo pipefail

BASE=${1:?base branch (what is live now)}
NEW=${2:?new ref to release}
REL=${3:?release branch name to create}
DRY=${4:-}

GIT="git --git-dir=/var/repos/shg.git"
LIVE=/var/www/shreehariglobal.in/public_html
DB=shari
TS=$(date +%Y%m%d-%H%M%S)
BK=/root/backups/pre-deploy-$REL-$TS

$GIT rev-parse --verify -q "$BASE^{commit}" >/dev/null || { echo "ABORT: unknown base $BASE"; exit 2; }
$GIT rev-parse --verify -q "$NEW^{commit}"  >/dev/null || { echo "ABORT: unknown ref $NEW"; exit 2; }
$GIT merge-base --is-ancestor "$BASE" "$NEW" || { echo "ABORT: $NEW is not built on $BASE"; exit 2; }
if [ -e "$LIVE/public_html" ]; then echo "ABORT: $LIVE holds a nested public_html (stale tree)"; exit 2; fi

# web files this release adds or changes (docs/ and tests/ never go live)
mapfile -t FILES < <($GIT diff --name-only --diff-filter=ACMR "$BASE" "$NEW" | grep -vE '^(docs|tests|\.github)/' | grep -vE '^config/config(\.local)?\.php$' || true)
mapfile -t GONE  < <($GIT diff --name-only --diff-filter=D "$BASE" "$NEW" | grep -vE '^(docs|tests|\.github)/' || true)
mapfile -t SQLS  < <(printf '%s\n' "${FILES[@]}" | grep -E '^database/' || true)
echo "release $REL: ${#FILES[@]} file(s) to write, ${#GONE[@]} deleted in git (left on live), ${#SQLS[@]} database file(s)"
[ "${#FILES[@]}" -gt 0 ] || { echo "nothing to deploy"; exit 0; }

# 1. drift guard
DRIFT=0
for f in "${FILES[@]}"; do
  if $GIT cat-file -e "$BASE:$f" 2>/dev/null; then
    if [ -e "$LIVE/$f" ]; then
      # Line endings are ignored (a Windows scp writes CRLF, git stores LF):
      # only a real content difference is someone else's change.
      a=$($GIT show "$BASE:$f" | tr -d '' | md5sum | cut -c1-32); b=$(tr -d '' < "$LIVE/$f" | md5sum | cut -c1-32)
      [ "$a" = "$b" ] || { echo "LIVE DRIFT: $f differs from $BASE"; DRIFT=1; }
    else
      echo "LIVE DRIFT: $f is in $BASE but missing on live"; DRIFT=1
    fi
  elif [ -e "$LIVE/$f" ]; then
    echo "LIVE DRIFT: $f is new in this release but already exists on live"; DRIFT=1
  fi
done
[ "$DRIFT" = 0 ] || { echo "ABORT: live is not the base this release was built on - nothing was written"; exit 3; }
echo "drift guard: live == $BASE for every touched file"

# 3 (early). lint in a staging dir before anything is backed up or written
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
$GIT archive "$NEW" "${FILES[@]}" | tar -x -C "$STAGE"
for f in "${FILES[@]}"; do
  case "$f" in *.php) php -l "$STAGE/$f" >/dev/null || { echo "ABORT: php -l failed on $f"; exit 4; } ;; esac
done
echo "php lint ok"

if [ "$DRY" = "--dry-run" ]; then
  printf '  %s\n' "${FILES[@]}"
  [ "${#SQLS[@]}" -eq 0 ] || { echo "database files to apply by hand:"; printf '  %s\n' "${SQLS[@]}"; }
  echo "dry run: nothing written"; exit 0
fi

# 2. backup
mkdir -p "$BK"; chmod 700 "$BK"
for f in "${FILES[@]}"; do
  if [ -e "$LIVE/$f" ]; then mkdir -p "$BK/$(dirname "$f")"; cp -a "$LIVE/$f" "$BK/$f"; else echo "$f" >> "$BK/NEW-FILES.txt"; fi
done
mysqldump --single-transaction --routines --triggers "$DB" | gzip > "$BK/db-$DB.sql.gz"
cat > "$BK/ROLLBACK.txt" <<TXT
Deploy $TS  $REL  ($BASE -> $($GIT rev-parse --short "$NEW")).
To roll back the code:
  cp -a $BK/<files> back into $LIVE (same relative paths)
  delete the files listed in NEW-FILES.txt (if present)
  chown www-data:www-data the restored files; systemctl reload php8.3-fpm
  curl the home page and /admin/login.php - both must answer 200
Database: db-$DB.sql.gz is the dump taken BEFORE this deploy. Migrations of
this release are additive; restoring the dump is only for a real emergency.
TXT

# 4. write
$GIT branch -f "$REL" "$NEW"
for f in "${FILES[@]}"; do
  mkdir -p "$LIVE/$(dirname "$f")"
  cp "$STAGE/$f" "$LIVE/$f.deploytmp" && mv "$LIVE/$f.deploytmp" "$LIVE/$f"
  chown www-data:www-data "$LIVE/$f"
done

# 5. reload + verify
systemctl reload php8.3-fpm
BAD=0
for f in "${FILES[@]}"; do
  a=$($GIT show "$NEW:$f" | md5sum | cut -c1-32); b=$(md5sum "$LIVE/$f" | cut -c1-32)
  [ "$a" = "$b" ] || { echo "MD5 MISMATCH $f"; BAD=1; }
done
H=$(curl -s -o /dev/null -w '%{http_code}' https://www.shreehariglobal.in/)
A=$(curl -s -o /dev/null -w '%{http_code}' https://shreehariglobal.network/admin/login.php)
echo "home -> $H   admin login -> $A   backup -> $BK"
[ "${#GONE[@]}" -eq 0 ] || { echo "deleted in git, still on live (remove by hand after checking):"; printf '  %s\n' "${GONE[@]}"; }
[ "${#SQLS[@]}" -eq 0 ] || { echo "database files in this release - apply by hand, in order:"; printf '  %s\n' "${SQLS[@]}"; }
if [ "$BAD" != 0 ] || [ "$H" != 200 ] || [ "$A" != 200 ]; then
  echo "DEPLOY NOT HEALTHY - see $BK/ROLLBACK.txt"; exit 5
fi
echo "deployed ${#FILES[@]} file(s) as $REL"
