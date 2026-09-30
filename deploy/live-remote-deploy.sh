#!/usr/bin/env bash
# =====================================================================
#  deploy/live-remote-deploy.sh — runs ON THE VPS as root.
#
#  Moves the webroot's git forward to one exact commit from GitHub, the
#  way the house go-live scripts do, and refuses anything that could
#  lose work or disturb someone else:
#    - one deploy at a time (flock on LOCK_FILE)
#    - the webroot must still be on EXPECT_LIVE (nobody deployed since)
#    - no tracked file changed without a commit, no untracked file
#      touched in the last RECENT_MIN minutes (nobody mid-change);
#      checked again right before the migrations and right before the move
#    - TARGET must contain the live commit (a fast-forward, so nothing
#      that is live today can be dropped)
#    - no new file may land on top of an untracked or ignored file
#    - a target that adds a PHP migration stops here (run those by hand)
#  Then: file + database backup, the NEW migrations only (before the
#  code; ones already recorded are skipped), fast-forward, ownership,
#  php -l, php-fpm reload, health check. A failed check after the code
#  moved, or a lost connection, rolls the code back to the live commit
#  with `git reset --keep` (never --hard: an edit made meanwhile stays).
#  Migrations are additive (IF NOT EXISTS / INSERT IGNORE) and stay.
#
#  Its output goes to a PUBLIC Actions log: file names and database
#  errors go to root-only files in BACKUP_DIR, the log gets counts and
#  the path of that file. config/config.php, uploads/, tickets/,
#  invoice/, qr/ are untracked and never touched. DRY=true stops after
#  the checks and changes nothing on the site.
#
#  Env: SITE TARGET EXPECT_LIVE FETCH_REF REPO_URL DRY
#       [LIVE_DB=shari] [SITE_URL=https://www.shreehariglobal.in/]
#       [BACKUP_DIR=/root/backups] [RECENT_MIN=15] [SWITCHES="k1 k2"]
#       [LOCK_FILE=/run/lock/shg-webroot-deploy.lock] [PHP_FPM=php8.3-fpm]
# =====================================================================
set -euo pipefail

# Everything lives in main(), so bash has read the whole script before
# the first command runs: a dropped ssh connection cannot cut it short.
main() {
: "${SITE:?}" "${TARGET:?}" "${EXPECT_LIVE:?}" "${FETCH_REF:?}" "${REPO_URL:?}" "${DRY:?}"
LIVE_DB="${LIVE_DB:-shari}"
SITE_URL="${SITE_URL:-https://www.shreehariglobal.in/}"
BACKUP_DIR="${BACKUP_DIR:-/root/backups}"
RECENT_MIN="${RECENT_MIN:-15}"
SWITCHES="${SWITCHES:-}"
LOCK_FILE="${LOCK_FILE:-/run/lock/shg-webroot-deploy.lock}"
PHP_FPM="${PHP_FPM:-php8.3-fpm}"
RUNTIME='(^|/)(uploads|tickets|qr|invoice|backup|backups|logs|cache|tmp|output)/'
LEDGER="$BACKUP_DIR/live-sync-migrations.txt"
STAMP="$(date +%Y%m%d-%H%M%S)"
TAR=""; SQL=""; APPLIED=()

[[ "$TARGET" =~ ^[0-9a-f]{40}$ ]]      || die "TARGET must be a full commit id"
[[ "$EXPECT_LIVE" =~ ^[0-9a-f]{40}$ ]] || die "EXPECT_LIVE must be a full commit id"
[ "$DRY" = true ] || [ "$DRY" = false ] || die "DRY must be true or false"
[ "$DRY" = true ] || [ "$(id -u)" -eq 0 ] || die "a real deploy needs root (chown, php-fpm reload)"

(umask 077; mkdir -p "$BACKUP_DIR") || die "cannot create $BACKUP_DIR"
if [ "$DRY" = false ]; then
  # Keep going if the ssh session drops; everything also lands in a log.
  trap '' HUP PIPE
  LOG="$BACKUP_DIR/live-deploy-$STAMP.log"
  (umask 077; : > "$LOG")
  exec > >(tee -p -a "$LOG") 2>&1
  echo "log: $LOG"
fi
trap 'exit 130' INT TERM

exec 9>"$LOCK_FILE" || die "cannot open the lock $LOCK_FILE"
flock -n 9 || die "another deploy holds $LOCK_FILE. Nothing was changed."

cd "$SITE" || die "webroot $SITE not found"
G rev-parse --git-dir >/dev/null 2>&1 || die "the webroot is not a git work tree"

# ---------------------------------------------------------------- 1. guards
say "1  is it safe to touch the site?"
BEFORE="$(G rev-parse HEAD)"
BRANCH="$(G symbolic-ref -q --short HEAD || true)"
echo "   live HEAD : $BEFORE (${BRANCH:-detached})"
echo "   expected  : $EXPECT_LIVE"
echo "   target    : $TARGET"
[ "$BEFORE" = "$EXPECT_LIVE" ] \
  || die "the live commit changed since the snapshot (someone deployed in the meantime). Nothing was changed."
ok "live commit is the one the target was built on"
guard "Nothing was changed."

# ---------------------------------------------------------------- 2. target
say "2  fetch the target"
G fetch --no-tags -q "$REPO_URL" "$FETCH_REF" || die "could not fetch $FETCH_REF. Nothing was changed."
GOT="$(G rev-parse FETCH_HEAD)"
[ "$GOT" = "$TARGET" ] || die "$FETCH_REF is at $GOT on GitHub, not the requested $TARGET (it moved). Nothing was changed."
G merge-base --is-ancestor "$BEFORE" "$TARGET" \
  || die "the target does not contain the live commit; deploying it would drop live work. Nothing was changed."
ok "target contains the live commit (fast-forward)"

mapfile -d '' CHANGED < <(G diff -z --name-only --no-renames "$BEFORE" "$TARGET")
mapfile -d '' ADDED   < <(G diff -z --name-only --no-renames --diff-filter=A "$BEFORE" "$TARGET")
mapfile -d '' MIGS    < <(G diff -z --name-only --no-renames --diff-filter=A "$BEFORE" "$TARGET" -- 'database/upgrade-*.sql' | sort -z)
mapfile -d '' PHPMIGS < <(G diff -z --name-only --no-renames --diff-filter=A "$BEFORE" "$TARGET" -- 'database/upgrade-*.php')
echo "   files changing : ${#CHANGED[@]}"
echo "   new migrations : ${#MIGS[@]}"

if [ "${#PHPMIGS[@]}" -gt 0 ]; then
  printf '      %s\n' "${PHPMIGS[@]##*/}"
  die "the target adds PHP migration(s); this script only runs .sql ones. Run them by hand or leave them out. Nothing was changed."
fi

SM="$(sm_state)"
echo "   schema_migrations: $SM"
TODO=()
local f base
for f in "${MIGS[@]}"; do
  base="${f##*/}"
  [[ "$base" =~ ^upgrade-[A-Za-z0-9._-]+\.sql$ ]] || die "unexpected migration name: $base"
  if already_applied "$base"; then
    echo "      $base  (already applied, skipped)"
  else
    echo "      $base"
    TODO+=("$f")
  fi
done

clobber=()
for f in "${ADDED[@]}"; do
  if [ -e "./$f" ] || [ -L "./$f" ]; then clobber+=("$f"); fi
done
if [ "${#clobber[@]}" -gt 0 ]; then
  names "clobber" "${clobber[@]}"
  die "the target adds ${#clobber[@]} file(s) that already exist untracked or ignored on the server. Nothing was changed."
fi
ok "no new file lands on an existing one"

if [ "$DRY" = true ]; then
  say "DRY RUN"
  echo "   Would: back up files + database, apply the ${#TODO[@]} migration(s) above,"
  echo "   fast-forward $BEFORE -> $TARGET, chown, php -l, reload $PHP_FPM, check $SITE_URL."
  echo "   Nothing on the site was changed."
  exit 0
fi

# ---------------------------------------------------------------- 3. backup
say "3  backup"
TAR="$BACKUP_DIR/pre-git-deploy-$STAMP.tar.gz"
SQL="$BACKUP_DIR/pre-git-deploy-$STAMP.sql.gz"
local rc=0
(umask 077; tar -czf "$TAR" --warning=no-file-changed \
   --exclude=./uploads --exclude=./tickets --exclude=./qr --exclude=./logs --exclude=./invoice \
   --exclude=./backup --exclude=./backups --exclude=./cache --exclude=./tmp -C "$SITE" .) || rc=$?
# tar says 1 when a file changed while it was read (a live site); only 2+ is a failure.
[ "$rc" -le 1 ] || die "file backup failed. Nothing was changed."
ok "files: $TAR ($(du -h "$TAR" | cut -f1))"
(umask 077; mysqldump --single-transaction --quick --routines "$LIVE_DB" | gzip > "$SQL") \
  || die "database backup failed. Nothing was changed."
[ "$(stat -c %s "$SQL")" -gt 20000 ] || die "database backup is suspiciously small ($SQL). Nothing was changed."
ok "database: $SQL ($(du -h "$SQL" | cut -f1))"

guard "Nothing was changed (backups: $TAR $SQL)."

# ---------------------------------------------------------------- 4. migrations
say "4  migrations first (additive, before the code)"
local tmp err code sha
for f in "${TODO[@]}"; do
  base="${f##*/}"
  tmp="$(mktemp)"
  G show "$TARGET:$f" > "$tmp"
  printf '   %s ... ' "$base"
  err="$BACKUP_DIR/live-deploy-$STAMP-$base.err"
  rc=0
  (umask 077; mysql "$LIVE_DB" < "$tmp" > /dev/null 2> "$err") || rc=$?
  # A warning line alone is fine; anything else on stderr is a failure.
  if [ "$rc" -ne 0 ] || grep -qv '^mysql: \[Warning\]' "$err"; then
    echo "FAILED"
    code="$(grep -oE '^ERROR [0-9]+ \([0-9A-Z]+\)' "$err" | head -1 || true)"
    rm -f "$tmp"
    die "migration $base failed (${code:-see $err}); full error in $err. The code was NOT moved and is still $BEFORE.${APPLIED[*]:+ Already applied in this run: ${APPLIED[*]}.} Database backup: $SQL"
  fi
  rm -f "$err"
  echo "ok"
  APPLIED+=("$base")
  sha="$(sha1sum "$tmp" | cut -d' ' -f1)"
  rm -f "$tmp"
  (umask 077; printf '%s %s %s\n' "$(date -u +%FT%TZ)" "$sha" "$base" >> "$LEDGER")
  if [ "$SM" = ready ]; then
    mysql "$LIVE_DB" -e "INSERT IGNORE INTO schema_migrations (filename, applied_at, sha1) VALUES ('$base', NOW(), '$sha')" \
      || die "applied $base but could not record it in schema_migrations (it is in $LEDGER). The code was NOT moved."
  fi
done
[ "${#TODO[@]}" -gt 0 ] || ok "no new migration"

# ---------------------------------------------------------------- 5. code
guard "The code was NOT moved.${APPLIED[*]:+ Migrations already applied: ${APPLIED[*]}.}"

say "5  move the code: $BEFORE -> $TARGET"
trap 'rollback "deploy interrupted"' EXIT
if ! G merge --ff-only -q "$TARGET"; then
  trap - EXIT
  [ "$(G rev-parse HEAD)" = "$BEFORE" ] \
    || die "fast-forward failed half way; HEAD is $(G rev-parse HEAD). Check by hand. Backups: $TAR $SQL"
  die "fast-forward refused; the code is still $BEFORE.${APPLIED[*]:+ Migrations already applied: ${APPLIED[*]}.}"
fi
local now; now="$(G rev-parse HEAD)"
if [ "$now" != "$TARGET" ]; then
  trap - EXIT
  die "after the move HEAD is $now, not the target; not rolling back (someone else may have moved it). Check by hand."
fi
ok "webroot at $(G rev-parse --short HEAD)"

own_changed
ok "changed files owned by www-data"

local bad=()
for f in "${CHANGED[@]}"; do
  [[ "$f" == *.php ]] && [ -f "./$f" ] || continue
  php -l "./$f" >/dev/null 2>&1 || bad+=("$f")
done
if [ "${#bad[@]}" -gt 0 ]; then
  names "php-errors" "${bad[@]}"
  rollback "php syntax error in ${#bad[@]} file(s)"
fi
ok "php -l clean on every changed file"

if systemctl reload "$PHP_FPM" 2>/dev/null; then ok "$PHP_FPM reloaded"; else echo "   WARN $PHP_FPM reload failed (non-fatal)"; fi

# ---------------------------------------------------------------- 6. health
say "6  health"
local healthy=0 page i
page="$(mktemp)"
for i in 1 2 3 4 5; do
  sleep 3
  code="$(curl -s -o "$page" -w '%{http_code}' --max-time 20 "$SITE_URL" || echo 000)"
  if [ "$code" = 200 ] && ! grep -qiE 'Fatal error|Parse error|Uncaught ' "$page"; then healthy=1; break; fi
  echo "   try $i: HTTP $code"
done
rm -f "$page"
[ "$healthy" = 1 ] || rollback "the home page did not answer 200 cleanly"
ok "$SITE_URL answers 200"
trap - EXIT
local path c
for path in sw.js admin/login.php; do
  c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${SITE_URL}${path}" || echo 000)"
  echo "   /$path -> $c"
done

if [ -n "$SWITCHES" ]; then
  say "7  new switches (they ship OFF)"
  local k v
  for k in $SWITCHES; do
    [[ "$k" =~ ^[a-z0-9_]+$ ]] || continue
    v="$(mysql "$LIVE_DB" -sNe "SELECT svalue FROM settings WHERE skey = '$k'" 2>/dev/null || true)"
    if [ -z "$v" ] || [ "$v" = 0 ]; then echo "   $k = ${v:-(unset)}  OFF"; else echo "   $k = $v  ON (was already set; left as is)"; fi
  done
fi

echo
echo "DEPLOYED: $BEFORE -> $TARGET"
echo "Backups: $TAR  $SQL"
echo "Roll back by hand: cd $SITE && git reset --keep $BEFORE && systemctl reload $PHP_FPM"
}

# ---------------------------------------------------------------- helpers
say()  { printf '\n== %s ==\n' "$*"; }
ok()   { printf '   OK  %s\n' "$*"; }
die()  { printf '\nSTOP: %s\n' "$*"; exit 1; }
G()    { git -c safe.directory='*' -c core.quotePath=false "$@"; }

# Full names go to a root-only file; the public log gets the count, the
# top-level folders and where the file is.
names() {
  local label="$1"; shift
  local file="$BACKUP_DIR/live-deploy-$STAMP-$label.txt" p
  (umask 077; printf '%s\n' "$@" > "$file")
  for p in "$@"; do
    case "$p" in */*) printf '%s/\n' "${p%%/*}" ;; *) echo "(top-level file)" ;; esac
  done | sort | uniq -c | sort -rn | head -12 | sed 's/^/     /'
  echo "   full list (root only): $file"
}

# Nobody mid-change: HEAD and branch unchanged, no tracked file edited,
# no untracked file outside the runtime folders touched in RECENT_MIN.
guard() {
  local outcome="$1" st e p hit
  local dirty=() recent=()
  [ "$(G rev-parse HEAD)" = "$BEFORE" ] || die "the live commit moved while this ran (someone deployed). $outcome"
  [ "$(G symbolic-ref -q --short HEAD || true)" = "$BRANCH" ] || die "the webroot switched branch while this ran. $outcome"
  st="$(mktemp)"
  G --no-optional-locks status --porcelain -z --no-renames --untracked-files=normal > "$st" \
    || { rm -f "$st"; die "git status failed. $outcome"; }
  while IFS= read -r -d '' e; do
    if [ "${e:0:3}" = '?? ' ]; then
      p="${e:3}"
      if [[ "$p" =~ $RUNTIME ]]; then continue; fi
      # "./" first: a name starting with "-" is never an option to find.
      # If find cannot tell, count the entry as recent (fail closed).
      if ! hit="$(find "./$p" -xdev -type f -mmin "-$RECENT_MIN" -print -quit 2>/dev/null)"; then
        recent+=("$p"); continue
      fi
      if [ -n "$hit" ]; then recent+=("$p"); fi
    else
      dirty+=("${e:3}")
    fi
  done < "$st"
  rm -f "$st"
  if [ "${#dirty[@]}" -gt 0 ]; then
    names "dirty" "${dirty[@]}"
    die "${#dirty[@]} tracked file(s) on the server changed without a commit (someone may be mid-change). $outcome"
  fi
  if [ "${#recent[@]}" -gt 0 ]; then
    names "recent" "${recent[@]}"
    die "${#recent[@]} untracked file(s) touched in the last $RECENT_MIN minutes (someone may be working). $outcome"
  fi
  ok "nobody is mid-change (no uncommitted edit, no new file in the last $RECENT_MIN minutes)"
}

# ready = schema_migrations exists with (filename, applied_at, sha1) and has
# rows. An empty one is left alone: the house deploy seeds it on first use
# and would skip that seeding if we wrote the first row.
sm_state() {
  local cols rows
  cols="$(mysql "$LIVE_DB" -sNe "SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'
              AND COLUMN_NAME IN ('filename','applied_at','sha1')" 2>/dev/null || echo 0)"
  if [ "${cols:-0}" != 3 ]; then echo none; return; fi
  rows="$(mysql "$LIVE_DB" -sNe "SELECT COUNT(*) FROM schema_migrations" 2>/dev/null || echo 0)"
  if [ "${rows:-0}" -gt 0 ]; then echo ready; else echo empty; fi
}

already_applied() {
  local base="$1" n
  if [ -f "$LEDGER" ] && awk -v b="$base" '$3 == b {f=1} END {exit !f}' "$LEDGER"; then return 0; fi
  [ "$SM" = ready ] || return 1
  n="$(mysql "$LIVE_DB" -sNe "SELECT COUNT(*) FROM schema_migrations WHERE filename = '$base'" 2>/dev/null || echo 0)"
  [ "${n:-0}" -gt 0 ]
}

own_changed() {
  local f d
  for f in "${CHANGED[@]}"; do
    [ -e "./$f" ] || [ -L "./$f" ] || continue
    chown -h www-data:www-data "./$f"
    d="$(dirname "./$f")"
    while [ "$d" != "." ] && [ "$d" != "/" ]; do chown -h www-data:www-data "$d"; d="$(dirname "$d")"; done
  done
}

rollback() {
  set +e
  trap - EXIT
  echo
  echo "ROLLBACK: $1"
  local now patch i
  now="$(G rev-parse HEAD)"
  if [ "$now" != "$TARGET" ]; then
    echo "   HEAD is $now, not the target: leaving it alone (someone else may have moved it). Check by hand."
    echo "   backups: $TAR  $SQL"
    exit 2
  fi
  # Anything edited since the move is kept aside before the reset.
  patch="$BACKUP_DIR/live-deploy-$STAMP-uncommitted.patch"
  (umask 077; G diff HEAD > "$patch")
  if [ -s "$patch" ]; then echo "   uncommitted edits saved to $patch"; else rm -f "$patch"; fi
  for i in 1 2 3 4 5; do
    G reset -q --keep "$BEFORE" && break
    [ -e "$(G rev-parse --git-path index.lock)" ] || break
    sleep 2
  done
  if [ "$(G rev-parse HEAD)" != "$BEFORE" ]; then
    echo "ROLLBACK FAILED: HEAD is still $(G rev-parse HEAD)."
    echo "   Restore by hand: cd $SITE && git reset --keep $BEFORE && systemctl reload $PHP_FPM"
    echo "   backups: $TAR  $SQL"
    exit 2
  fi
  own_changed
  systemctl reload "$PHP_FPM" 2>/dev/null
  echo "   code is back on $(G rev-parse --short HEAD); site answers $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL" || echo 000)"
  echo "   backups: $TAR  $SQL"
  exit 1
}

main "$@"
