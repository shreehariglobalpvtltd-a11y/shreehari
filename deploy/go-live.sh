#!/usr/bin/env bash
# =====================================================================
#  go-live.sh — put a branch on the live site, safely, in one command.
#
#  Owner ask, 26 Sep 2026: "aaune Claude session ko lagi deploy garna
#  milne easily hune environment banayera rakhidinus."
#
#  WHY THIS EXISTS
#  ---------------
#  On 26 Sep a deploy nearly went wrong in a way nobody would have seen
#  coming: the live worktree was NOT on the branch everyone assumed. It
#  had been switched to a GitHub branch with its own lineage, 13 commits
#  the bare repo had never seen, including a security fix. A routine
#  "merge and push" would have deleted that fix from a live site.
#
#  So this script REFUSES to guess. It prints what is live, what is
#  about to replace it, and what would be lost — and stops unless you
#  say yes. It backs everything up first, every time.
#
#  USAGE
#    bash deploy/go-live.sh <branch-in-/root/shg-site.git>
#    bash deploy/go-live.sh deploy-local-brain
#    CONFIRM=yes bash deploy/go-live.sh <branch>   # skip the prompt
#
#  ROLLBACK — printed at the end of every run, and it is one command:
#    bash deploy/go-live.sh --rollback
# =====================================================================
set -euo pipefail

SITE=/var/www/shreehariglobal.in/public_html
BARE=/root/shg-site.git
BACKUPS=/root/backups
DOMAIN=https://www.shreehariglobal.in

c()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok() { printf '    \033[32m%s\033[0m\n' "$*"; }
no() { printf '    \033[31m%s\033[0m\n' "$*"; }
die(){ printf '\n\033[1;31mSTOPPED: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "run as root"

# ---------------------------------------------------------------- rollback
if [ "${1:-}" = "--rollback" ]; then
  D=$(cat "$BACKUPS/LATEST-pre-local-brain" 2>/dev/null || true)
  [ -n "$D" ] && [ -d "$D" ] || die "no backup recorded in $BACKUPS/LATEST-pre-local-brain"
  c "Rolling back to $D"
  head -2 "$D/live-commit.txt"
  read -r -p "    Restore code AND database from this backup? [yes/NO] " a
  [ "$a" = "yes" ] || die "not confirmed"
  tar -xzf "$D/public_html.tar.gz" -C /var/www/shreehariglobal.in
  gunzip -c "$D/shari.sql.gz" | mysql shari
  [ -f "$D/nginx.conf" ] && cp "$D/nginx.conf" /etc/nginx/sites-available/shreehariglobal.in && nginx -t && systemctl reload nginx
  chown -R www-data:www-data "$SITE"
  ok "restored"
  curl -s -o /dev/null -w "    home: %{http_code}\n" "$DOMAIN/"
  exit 0
fi

BRANCH="${1:-}"
[ -n "$BRANCH" ] || die "usage: go-live.sh <branch>   (or --rollback)"

# ------------------------------------------------------------- 1. look
c "1/7  What is live right now"
cd "$SITE"
LIVE_BRANCH=$(git branch --show-current || echo "(detached)")
LIVE_SHA=$(git rev-parse HEAD)
echo "    branch : $LIVE_BRANCH"
echo "    commit : $(git log --oneline -1)"
echo "    served : $(curl -s "$DOMAIN/" | grep -o 'v=2026[0-9a-z]*' | head -1 || echo '?')"

git remote | grep -q '^local$' || git remote add local "$BARE"
git fetch -q local "$BRANCH" || die "no branch '$BRANCH' in $BARE"
NEW_SHA=$(git rev-parse FETCH_HEAD)
echo "    new    : $(git log --oneline -1 FETCH_HEAD)"

[ "$LIVE_SHA" = "$NEW_SHA" ] && { ok "already live — nothing to do"; exit 0; }

# --------------------------------------------------- 2. what would be lost
c "2/7  What this would REMOVE from live"
LOST=$(git log --oneline "$NEW_SHA..$LIVE_SHA" 2>/dev/null || true)
if [ -z "$LOST" ]; then
  ok "nothing — the new branch contains everything live has"
else
  no "$(echo "$LOST" | wc -l) commit(s) on live are NOT in $BRANCH:"
  echo "$LOST" | sed 's/^/      /'
  no "Read that list. A deploy that drops a security fix looks exactly like this."
fi

c "3/7  What it adds"
git log --oneline "$LIVE_SHA..$NEW_SHA" 2>/dev/null | head -20 | sed 's/^/      /' || true

if [ "${CONFIRM:-}" != "yes" ]; then
  read -r -p "
    Deploy $BRANCH over $LIVE_BRANCH? [yes/NO] " a
  [ "$a" = "yes" ] || die "not confirmed"
fi

# -------------------------------------------------------- 4. back up first
c "4/7  Backing up (code, database, nginx, crontab)"
STAMP=$(date +%Y%m%d-%H%M%S)
D="$BACKUPS/pre-$BRANCH-$STAMP"
mkdir -p "$D"
{ echo "$LIVE_SHA"; echo "$LIVE_BRANCH"; } > "$D/live-commit.txt"
tar -czf "$D/public_html.tar.gz" -C /var/www/shreehariglobal.in public_html 2>/dev/null || true
mysqldump --single-transaction --routines --triggers shari | gzip > "$D/shari.sql.gz"
cp /etc/nginx/sites-available/shreehariglobal.in "$D/nginx.conf" 2>/dev/null || true
crontab -l > "$D/crontab.txt" 2>/dev/null || true
echo "$D" > "$BACKUPS/LATEST-pre-local-brain"
ok "$D  ($(du -sh "$D" | cut -f1))"

# ------------------------------------------------------------- 5. switch
c "5/7  Switching the worktree"
git status --porcelain | grep -v '^??' | while read -r _ f; do
  cp "$f" "$D/dirty-$(echo "$f" | tr / _)" 2>/dev/null || true
done
git checkout -- . 2>/dev/null || true
git checkout -q -B "release/$BRANCH-$STAMP" FETCH_HEAD
chown -R www-data:www-data "$SITE"
ok "on $(git branch --show-current) @ $(git rev-parse --short HEAD)"

# ----------------------------------------------------------- 6. migrations
c "6/7  Migrations in database/ newer than the last deploy"
echo "    (none are applied automatically — apply the ones this release needs)"
ls -t database/upgrade-*.sql 2>/dev/null | head -5 | sed 's/^/      php tests\/apply-sql.php /'

# --------------------------------------------------------------- 7. verify
c "7/7  Is it alive"
FAIL=0
for u in "/" "/admin/login.php" "/sw.js"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$DOMAIN$u")
  [ "$code" = "200" ] && ok "$u  $code" || { no "$u  $code"; FAIL=1; }
done
# the uploads tree must never answer 200 — this is the 25 Sep bug
for p in challan chalani passengers agents-kyc wa-inbound; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "$DOMAIN/uploads/$p/")
  [ "$code" = "200" ] && { no "/uploads/$p/ is PUBLIC ($code)"; FAIL=1; } || ok "/uploads/$p/ refused ($code)"
done
echo "    served stamp: $(curl -s "$DOMAIN/" | grep -o 'v=2026[0-9a-z]*' | head -1 || echo '?')"

cat <<EOF

  ------------------------------------------------------------------
  Backup:   $D
  Rollback: bash deploy/go-live.sh --rollback
  ------------------------------------------------------------------
EOF
[ "$FAIL" = "0" ] || die "something above is red — roll back or fix it now"
ok "done"
