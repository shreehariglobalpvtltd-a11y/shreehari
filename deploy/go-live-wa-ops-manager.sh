#!/usr/bin/env bash
# =====================================================================
#  deploy/go-live-wa-ops-manager.sh — run ON THE VPS as root.
#
#  Takes the WhatsApp operations-manager branch live in the order the
#  docs prescribe, stopping at the first red step:
#    1. shg-test: fetch the branch, apply the migration, run the suites
#    2. live: database backup
#    3. live: fast-forward the webroot to the branch, apply the migration
#    4. ownership, php -l, health check, switches confirmed OFF
#    5. nginx deny rules for the two new private folders (+ reload)
#
#  Nothing is switched on. Usage:
#    bash deploy/go-live-wa-ops-manager.sh            # everything
#    bash deploy/go-live-wa-ops-manager.sh --test-only
# =====================================================================
set -euo pipefail

BRANCH="claude/hari-global-whatsapp-ops-c8iiof"
MIGRATION="database/upgrade-2026-09-24-wa-ops-manager.sql"
TEST_DIR="${SHG_TEST_DIR:-/root/shg-test}"
LIVE_DIR="${SHG_LIVE_DIR:-/var/www/shreehariglobal.in/public_html}"
LIVE_DB="${SHG_LIVE_DB:-shari}"
TEST_DB="${SHG_TEST_DB:-shari_test}"
NGINX_CONF="${SHG_NGINX_CONF:-/etc/nginx/sites-available/shreehariglobal.in}"
SITE="https://www.shreehariglobal.in/"

say()  { printf '\n\033[1;34m== %s ==\033[0m\n' "$*"; }
ok()   { printf '   \033[32mOK\033[0m  %s\n' "$*"; }
die()  { printf '\n\033[31mSTOP: %s\033[0m\n' "$*"; exit 1; }

[[ $(id -u) -eq 0 ]] || die "run as root on the VPS"
command -v php >/dev/null || die "php not found"
command -v mysql >/dev/null || die "mysql client not found"

fetch_branch() {   # $1 = repo dir
  cd "$1"
  if git remote get-url origin >/dev/null 2>&1; then
    git fetch origin "$BRANCH" || die "could not fetch $BRANCH from origin in $1"
    git checkout -q -B "$BRANCH" "origin/$BRANCH"
  else
    git rev-parse --verify "$BRANCH" >/dev/null 2>&1 || die "$1 has no origin remote and no local $BRANCH — push the branch to the VPS repo first (git push vps $BRANCH:$BRANCH)"
    git checkout -q "$BRANCH"
  fi
  ok "$1 at $(git rev-parse --short HEAD) ($BRANCH)"
}

# ---------------------------------------------------------------- 1. test
say "1/5  shg-test: fetch, migrate, test"
[[ -d "$TEST_DIR" ]] || die "$TEST_DIR not found (the test copy)"
fetch_branch "$TEST_DIR"
cd "$TEST_DIR"
mysql "$TEST_DB" < "$MIGRATION" >/dev/null && ok "migration applied to $TEST_DB"
mysql "$TEST_DB" < "$MIGRATION" | grep -q 'already present' && ok "migration is idempotent (second run: already present)"
php tests/company-docs-test.php    | tail -2
php tests/wa-ops-manager-test.php  | tail -2
php tests/wa-agent-test.php        | tail -2
php tests/run-all.php              | tail -12 || die "the battery is red — nothing was deployed"
ok "tests green on $TEST_DB"
[[ "${1:-}" == "--test-only" ]] && { echo; echo "test-only run finished; live untouched"; exit 0; }

# ---------------------------------------------------------------- 2. backup
say "2/5  live: database backup"
cd "$LIVE_DIR"
php cron/backup.php | tail -1
ok "backup written (see backup/ or the cron output above)"

# ---------------------------------------------------------------- 3. deploy
say "3/5  live: fast-forward the webroot to $BRANCH + migration"
git status --porcelain | grep -q . && die "the live webroot has uncommitted changes — commit them on the VPS first (git status)"
BEFORE=$(git rev-parse --short HEAD)
fetch_branch "$LIVE_DIR"
git merge-base --is-ancestor "$BEFORE" HEAD || die "the previous live commit $BEFORE is not an ancestor of the branch — merge vps/main into the branch first"
mysql "$LIVE_DB" < "$MIGRATION" >/dev/null && ok "migration applied to $LIVE_DB"

# ---------------------------------------------------------------- 4. verify
say "4/5  ownership, syntax, health, switches"
chown -R www-data:www-data includes admin cron whatsapp tests database docs deploy company-doc-share.php
ok "files owned by www-data"
git diff --name-only "$BEFORE" HEAD -- '*.php' | while read -r f; do [[ -f "$f" ]] && php -l "$f" >/dev/null || die "syntax error in $f"; done
ok "php -l clean on every changed file"
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$SITE")
[[ "$CODE" == "200" ]] || die "site answered HTTP $CODE after deploy — roll back with: git checkout $BEFORE"
ok "site answers 200"
ON=$(mysql -N "$LIVE_DB" -e "SELECT COUNT(*) FROM settings WHERE skey IN ('wa_ops_docs_on','wa_ops_handoff_on','wa_ops_stepup_on','wa_ops_media_on','wa_ops_voice_on') AND svalue='1'")
[[ "$ON" == "0" ]] || die "a wa_ops_* switch is ON — set them to 0 in Admin → Settings before continuing"
ok "all wa_ops_* switches are OFF"

# ---------------------------------------------------------------- 5. nginx
say "5/5  nginx: deny /uploads/company/ and /uploads/wa-inbound/"
if [[ -f "$NGINX_CONF" ]]; then
  if grep -q '/uploads/company/' "$NGINX_CONF"; then
    ok "rules already present"
  else
    cp "$NGINX_CONF" "$NGINX_CONF.bak.$(date +%Y%m%d-%H%M%S)"
    sed -i 's|\(\s*\)location ^~ /uploads/passengers/ { deny all; return 404; }|&\n\1location ^~ /uploads/company/    { deny all; return 404; }\n\1location ^~ /uploads/wa-inbound/ { deny all; return 404; }|' "$NGINX_CONF"
    grep -q '/uploads/wa-inbound/' "$NGINX_CONF" || die "could not insert the nginx rules — add the two lines from deploy/nginx-shreehariglobal.in.conf by hand"
    nginx -t && systemctl reload nginx && ok "nginx reloaded with the new deny rules"
  fi
else
  echo "   nginx conf not at $NGINX_CONF — add the two deny lines from deploy/nginx-shreehariglobal.in.conf by hand"
fi

echo
echo "DEPLOYED: live is at $(git rev-parse --short HEAD) ($BRANCH). Every new feature is OFF."
echo "Next: Admin → Settings → Company Documents (file + approve), then the switches one by one (docs/UPGRADE-2026-09-24-wa-ops-manager.md §4)."
