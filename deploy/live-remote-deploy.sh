#!/usr/bin/env bash
# =====================================================================
#  deploy/live-remote-deploy.sh — runs ON THE VPS as root.
#
#  Moves the webroot's git forward to one exact commit from GitHub, the
#  way the house go-live scripts do, and refuses anything that could
#  lose work or disturb someone else:
#    - the webroot must still be on EXPECT_LIVE (nobody deployed since)
#    - no tracked file changed without a commit, no untracked file
#      touched in the last RECENT_MIN minutes (nobody mid-change)
#    - TARGET must contain the live commit (a fast-forward, so nothing
#      that is live today can be dropped)
#    - no new file may land on top of an untracked file already there
#  Then: file + database backup, the NEW migrations only (before the
#  code), fast-forward, ownership, php -l, php-fpm reload, health check.
#  A failed check after the code moved rolls the code back to the live
#  commit. Migrations are additive (IF NOT EXISTS / INSERT IGNORE) and
#  stay; the old code simply does not read them.
#
#  config/config.php, uploads/, tickets/, invoice/, qr/ are untracked and
#  are never touched. DRY=true stops after the checks and changes
#  nothing on the site (git fetch only adds objects to the repository).
#
#  Env: SITE TARGET EXPECT_LIVE FETCH_REF REPO_URL DRY
#       [LIVE_DB=shari] [SITE_URL=https://www.shreehariglobal.in/]
#       [BACKUP_DIR=/root/backups] [RECENT_MIN=15] [SWITCHES="k1 k2"]
# =====================================================================
set -euo pipefail

: "${SITE:?}" "${TARGET:?}" "${EXPECT_LIVE:?}" "${FETCH_REF:?}" "${REPO_URL:?}" "${DRY:?}"
LIVE_DB="${LIVE_DB:-shari}"
SITE_URL="${SITE_URL:-https://www.shreehariglobal.in/}"
BACKUP_DIR="${BACKUP_DIR:-/root/backups}"
RECENT_MIN="${RECENT_MIN:-15}"
SWITCHES="${SWITCHES:-}"
RUNTIME='^(uploads|tickets|qr|invoice|backup|backups|logs|cache|tmp|output)/'

say()  { printf '\n== %s ==\n' "$*"; }
ok()   { printf '   OK  %s\n' "$*"; }
die()  { printf '\nSTOP: %s\n' "$*"; exit 1; }

[[ "$TARGET" =~ ^[0-9a-f]{40}$ ]]      || die "TARGET must be a full commit id"
[[ "$EXPECT_LIVE" =~ ^[0-9a-f]{40}$ ]] || die "EXPECT_LIVE must be a full commit id"
[ "$DRY" = true ] || [ "$DRY" = false ] || die "DRY must be true or false"
[ "$DRY" = true ] || [ "$(id -u)" -eq 0 ] || die "a real deploy needs root (chown, php-fpm reload)"

cd "$SITE" || die "webroot $SITE not found"
G() { git -c safe.directory='*' "$@"; }
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

dirty="$(G --no-optional-locks status --porcelain --untracked-files=no)"
if [ -n "$dirty" ]; then
  printf '%s\n' "$dirty" | head -40
  die "tracked file(s) on the server changed without a commit (someone may be mid-change). Nothing was changed."
fi
ok "no uncommitted change to a tracked file"

untracked="$(G --no-optional-locks status --porcelain --untracked-files=normal | sed -n 's/^?? //p' | grep -vE "$RUNTIME" || true)"
recent=""
while IFS= read -r p; do
  [ -n "$p" ] || continue
  hit="$(find "$p" -xdev -type f -mmin "-$RECENT_MIN" -print -quit 2>/dev/null)"
  [ -n "$hit" ] && recent="$recent$p"$'\n'
done <<< "$untracked"
if [ -n "$recent" ]; then
  printf '%s' "$recent" | head -40
  die "untracked file(s) touched in the last $RECENT_MIN minutes (someone may be working). Nothing was changed."
fi
ok "nobody wrote new files in the last $RECENT_MIN minutes"

# ---------------------------------------------------------------- 2. target
say "2  fetch the target"
G fetch --no-tags -q "$REPO_URL" "$FETCH_REF" || die "could not fetch $FETCH_REF from $REPO_URL"
GOT="$(G rev-parse FETCH_HEAD)"
[ "$GOT" = "$TARGET" ] || die "$FETCH_REF is at $GOT on GitHub, not the requested $TARGET (it moved). Nothing was changed."
G merge-base --is-ancestor "$BEFORE" "$TARGET" \
  || die "the target does not contain the live commit; deploying it would drop live work. Nothing was changed."
ok "target $TARGET contains the live commit (fast-forward)"

changed="$(G diff --name-only --no-renames "$BEFORE" "$TARGET")"
added="$(G diff --name-only --no-renames --diff-filter=A "$BEFORE" "$TARGET")"
migs="$(G diff --name-only --no-renames --diff-filter=A "$BEFORE" "$TARGET" -- 'database/upgrade-*.sql' | sort)"
echo "   files changing : $(printf '%s' "$changed" | grep -c . || true)"
echo "   new migrations : $(printf '%s' "$migs" | grep -c . || true)"
printf '%s\n' "$migs" | grep . | sed 's/^/      /' || true

clobber=""
while IFS= read -r f; do
  [ -n "$f" ] || continue
  if [ -e "$f" ] || [ -L "$f" ]; then clobber="$clobber$f"$'\n'; fi
done <<< "$added"
if [ -n "$clobber" ]; then
  printf '%s' "$clobber" | head -40
  die "the target adds file(s) that already exist untracked on the server. Nothing was changed."
fi
ok "no new file lands on an untracked one"

if [ "$DRY" = true ]; then
  say "DRY RUN"
  echo "   Would: back up files + database, apply the migrations above, fast-forward"
  echo "   $BEFORE -> $TARGET, chown, php -l, reload php-fpm, check $SITE_URL."
  echo "   Nothing on the site was changed."
  exit 0
fi

# ---------------------------------------------------------------- 3. backup
say "3  backup"
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
TAR="$BACKUP_DIR/pre-git-deploy-$STAMP.tar.gz"
SQL="$BACKUP_DIR/pre-git-deploy-$STAMP.sql.gz"
tar -czf "$TAR" --exclude=./uploads --exclude=./tickets --exclude=./qr \
  --exclude=./logs --exclude=./invoice --exclude=./backup -C "$SITE" . \
  || die "file backup failed. Nothing was changed."
ok "files: $TAR ($(du -h "$TAR" | cut -f1))"
mysqldump --single-transaction --quick --routines "$LIVE_DB" | gzip > "$SQL" \
  || die "database backup failed. Nothing was changed."
[ "$(stat -c %s "$SQL")" -gt 20000 ] || die "database backup is suspiciously small ($SQL). Nothing was changed."
ok "database: $SQL ($(du -h "$SQL" | cut -f1))"

# ---------------------------------------------------------------- 4. migrations
say "4  migrations first (additive, before the code)"
sm_ok="$(mysql "$LIVE_DB" -sNe "SELECT COUNT(*) FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations' AND COLUMN_NAME = 'filename'")"
while IFS= read -r f; do
  [ -n "$f" ] || continue
  base="$(basename "$f")"
  tmp="$(mktemp)"
  G show "$TARGET:$f" > "$tmp"
  printf '   %s ... ' "$base"
  if ! err="$(mysql "$LIVE_DB" < "$tmp" 2>&1 >/dev/null)" || [ -n "$err" ]; then
    echo "FAILED"
    printf '%s\n' "$err" | head -5
    rm -f "$tmp"
    die "migration $base failed; the code was NOT moved and is still $BEFORE. Database backup: $SQL"
  fi
  echo "ok"
  if [ "${sm_ok:-0}" -gt 0 ]; then
    sha="$(sha1sum "$tmp" | cut -d' ' -f1)"
    mysql "$LIVE_DB" -e "INSERT IGNORE INTO schema_migrations (filename, applied_at, sha1) VALUES ('$base', NOW(), '$sha')"
  fi
  rm -f "$tmp"
done <<< "$migs"

# ---------------------------------------------------------------- 5. code
rollback() {
  echo
  echo "ROLLBACK: $1"
  if [ -n "$BRANCH" ]; then G reset -q --hard "$BEFORE"; else G checkout -q --detach "$BEFORE"; fi
  while IFS= read -r f; do [ -e "$f" ] && chown -h www-data:www-data "$f"; done <<< "$changed"
  systemctl reload php8.3-fpm 2>/dev/null || true
  echo "   code is back on $(G rev-parse --short HEAD); site answers $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$SITE_URL" || echo 000)"
  echo "   backups: $TAR  $SQL"
  exit 1
}

say "5  move the code: $BEFORE -> $TARGET"
if [ -n "$BRANCH" ]; then
  G merge --ff-only -q "$TARGET" || die "fast-forward refused; the code is still $BEFORE"
else
  G checkout -q --detach "$TARGET" || die "checkout refused; the code is still $BEFORE"
fi
[ "$(G rev-parse HEAD)" = "$TARGET" ] || rollback "HEAD is not the target after the move"
ok "webroot at $(G rev-parse --short HEAD)"

while IFS= read -r f; do
  [ -n "$f" ] && [ -e "$f" ] || continue
  chown -h www-data:www-data "$f"
  d="$(dirname "$f")"
  while [ "$d" != "." ] && [ "$d" != "/" ]; do chown -h www-data:www-data "$d"; d="$(dirname "$d")"; done
done <<< "$changed"
ok "changed files owned by www-data"

bad=""
while IFS= read -r f; do
  [[ "$f" == *.php ]] && [ -f "$f" ] || continue
  php -l "$f" >/dev/null 2>&1 || bad="$bad $f"
done <<< "$changed"
[ -z "$bad" ] || rollback "php syntax error in:$bad"
ok "php -l clean on every changed file"

systemctl reload php8.3-fpm 2>/dev/null && ok "php8.3-fpm reloaded" || echo "   WARN php8.3-fpm reload failed (non-fatal)"

# ---------------------------------------------------------------- 6. health
say "6  health"
healthy=0
for i in 1 2 3 4 5; do
  sleep 3
  code="$(curl -s -o /tmp/shg-health.html -w '%{http_code}' --max-time 20 "$SITE_URL" || echo 000)"
  if [ "$code" = 200 ] && ! grep -qiE 'Fatal error|Parse error|Uncaught ' /tmp/shg-health.html; then healthy=1; break; fi
  echo "   try $i: HTTP $code"
done
rm -f /tmp/shg-health.html
[ "$healthy" = 1 ] || rollback "the home page did not answer 200 cleanly"
ok "$SITE_URL answers 200"
for path in sw.js admin/login.php; do
  c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "${SITE_URL}${path}" || echo 000)"
  echo "   /$path -> $c"
done

if [ -n "$SWITCHES" ]; then
  say "7  new switches (they ship OFF)"
  for k in $SWITCHES; do
    v="$(mysql "$LIVE_DB" -sNe "SELECT svalue FROM settings WHERE skey = '$k'" || true)"
    if [ -z "$v" ] || [ "$v" = 0 ]; then echo "   $k = ${v:-(unset)}  OFF"; else echo "   $k = $v  ON (was already set; left as is)"; fi
  done
fi

echo
echo "DEPLOYED: $BEFORE -> $TARGET"
echo "Backups: $TAR  $SQL"
echo "Roll back by hand: cd $SITE && git reset --hard $BEFORE && systemctl reload php8.3-fpm"
