#!/usr/bin/env bash
#
# Deploy a branch to the live site, by hand, from the VPS itself.
#
# Same steps as .github/workflows/deploy.yml, for when the GitHub secrets
# are not set up yet. Run it ON THE VPS, as root.
#
#   bash manual-deploy.sh              # dry run — shows every step, changes nothing
#   bash manual-deploy.sh --go         # really deploy
#   bash manual-deploy.sh --go other/branch
#
# It clones the branch side by side, carries over config.php and the runtime
# trees, applies pending migrations, swaps the directories in one move, and
# puts the old one back if the site stops answering 200.
#
# NOTE: the swapped-in tree is a normal clone of GitHub, so public_html/.git
# will point at GitHub rather than /root/shg-site.git. Keep old_html_backup
# until you are happy with that.

set -euo pipefail

SITE=/var/www/shreehariglobal.in/public_html
REPO=https://github.com/shreehariglobalpvtltd-a11y/shreehari.git
DB=shari

DRY=true
[ "${1:-}" = "--go" ] && { DRY=false; shift; }
BRANCH="${1:-claude/shreehari-global-upgrade-myl45u}"

SITE_PARENT="$(dirname "$SITE")"
NEW_DIR="$SITE_PARENT/new_html_deploy"
OLD_DIR="$SITE_PARENT/old_html_backup"
BACKUP_DIR=/root/backups
STAMP="$(date +%Y%m%d-%H%M%S)"

say()  { printf '\n\033[1m=== %s\033[0m\n' "$*"; }
step() { printf '  %s\n' "$*"; }
run()  { if [ "$DRY" = true ]; then printf '  [DRY] %s\n' "$*"; else eval "$@"; fi; }

# ---------------------------------------------------------------- preflight
say "Preflight"
[ "$(id -u)" -eq 0 ]      || { echo "Run as root."; exit 1; }
[ -d "$SITE" ]            || { echo "Not found: $SITE"; exit 1; }
[ -f "$SITE/config/config.php" ] || { echo "Missing $SITE/config/config.php — refusing."; exit 1; }
command -v php   >/dev/null || { echo "php not found";   exit 1; }
command -v git   >/dev/null || { echo "git not found";   exit 1; }
mysql "$DB" -e 'SELECT 1' >/dev/null 2>&1 || { echo "Cannot reach database '$DB'."; exit 1; }
step "root, site, config.php, php, git, database — all OK"
step "branch : $BRANCH"
step "mode   : $([ "$DRY" = true ] && echo 'DRY RUN (nothing will change)' || echo 'LIVE DEPLOY')"
if [ -d "$SITE/.git" ]; then
  step "live now: $(git -C "$SITE" -c safe.directory="$SITE" log -1 --format='%h %s' 2>/dev/null || echo unknown)"
fi

# ------------------------------------------------------------------ backup
say "Backup"
step "files -> $BACKUP_DIR/pre-deploy-$STAMP.tar.gz"
run "mkdir -p '$BACKUP_DIR'"
run "tar -czf '$BACKUP_DIR/pre-deploy-$STAMP.tar.gz' \
      --exclude=./uploads --exclude=./tickets --exclude=./qr \
      --exclude=./invoice --exclude=./logs -C '$SITE' . 2>/dev/null || true"
step "database -> $BACKUP_DIR/pre-deploy-$STAMP.sql.gz"
run "mysqldump --single-transaction '$DB' | gzip > '$BACKUP_DIR/pre-deploy-$STAMP.sql.gz'"

# ------------------------------------------------------------------- clone
say "Clone $BRANCH"
run "rm -rf '$NEW_DIR'"
run "git clone --quiet --branch '$BRANCH' --depth 30 '$REPO' '$NEW_DIR'"
[ "$DRY" = true ] || step "cloned: $(git -C "$NEW_DIR" log -1 --format='%h %s')"

# -------------------------------------------------------------- php syntax
say "PHP syntax check"
if [ "$DRY" = true ]; then
  step "[DRY] would run php -l over every .php file in the clone"
else
  bad=0
  while IFS= read -r -d '' f; do
    php -l "$f" >/dev/null 2>&1 || { echo "  FAIL: $f"; bad=1; }
  done < <(find "$NEW_DIR" -name '*.php' -not -path '*/vendor/*' -print0)
  [ "$bad" -eq 0 ] || { echo "PHP errors in the clone — nothing swapped."; exit 1; }
  step "all files parse"
fi

# ------------------------------------------------- config + runtime trees
say "Carry over config.php and the runtime trees"
step "config/config.php"
run "cp '$SITE/config/config.php' '$NEW_DIR/config/config.php'"
for d in uploads tickets qr invoice backup; do
  [ -d "$SITE/$d" ] || continue
  step "$d/"
  run "mkdir -p '$NEW_DIR/$d' && cp -a '$SITE/$d/.' '$NEW_DIR/$d/'"
done

# -------------------------------------------------------------- migrations
say "Migrations"
if [ "$DRY" = true ]; then
  step "[DRY] would apply any database/upgrade-*.sql not in schema_migrations"
else
  found=0
  for f in "$NEW_DIR"/database/upgrade-*.sql; do
    [ -f "$f" ] || continue
    found=1
    base="$(basename "$f")"
    n=$(mysql "$DB" -sNe "SELECT COUNT(*) FROM schema_migrations WHERE migration='$base'" 2>/dev/null || echo 0)
    if [ "${n:-0}" -eq 0 ]; then
      step "applying $base"
      mysql "$DB" < "$f"
      mysql "$DB" -e "INSERT IGNORE INTO schema_migrations (migration) VALUES ('$base')" 2>/dev/null || true
    else
      step "skip $base (already applied)"
    fi
  done
  [ "$found" -eq 1 ] || step "no migration files in this branch"
fi

# -------------------------------------------------------------------- swap
say "Swap"
run "chown -R www-data:www-data '$NEW_DIR'"
run "chmod 640 '$NEW_DIR/config/config.php'"
run "rm -rf '$OLD_DIR'"
step "$SITE -> $OLD_DIR, then $NEW_DIR -> $SITE"
run "mv '$SITE' '$OLD_DIR'"
run "mv '$NEW_DIR' '$SITE'"

# ------------------------------------------------------------------ reload
say "Reload"
if [ "$DRY" = true ]; then
  step "[DRY] would run nginx -t, then reload php8.3-fpm and nginx"
else
  nginx -t >/dev/null 2>&1 && step "nginx config OK" || step "WARN: nginx -t failed — not reloading nginx"
  systemctl reload php8.3-fpm && step "php8.3-fpm reloaded" || step "WARN: php-fpm reload failed"
  nginx -t >/dev/null 2>&1 && { systemctl reload nginx && step "nginx reloaded"; } || true
fi

# ------------------------------------------------------------ health check
say "Health check"
if [ "$DRY" = true ]; then
  step "[DRY] would check https://www.shreehariglobal.in/ answers 200"
  printf '\n\033[1mDRY RUN COMPLETE — nothing was changed.\033[0m\n'
  printf 'Happy with the above? Run it for real:\n\n  bash %s --go %s\n\n' "$0" "$BRANCH"
  exit 0
fi

sleep 3
CODE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://www.shreehariglobal.in/ || echo 000)
step "site answers: $CODE"
if [ "$CODE" != "200" ]; then
  printf '\n\033[1mROLLBACK — site answered %s\033[0m\n' "$CODE"
  mv "$SITE" "$SITE_PARENT/failed_$STAMP"
  mv "$OLD_DIR" "$SITE"
  systemctl reload php8.3-fpm 2>/dev/null || true
  systemctl reload nginx 2>/dev/null || true
  CODE2=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://www.shreehariglobal.in/ || echo 000)
  echo "  restored the previous version — site now answers: $CODE2"
  echo "  the failed deploy is at $SITE_PARENT/failed_$STAMP"
  exit 1
fi

printf '\n\033[1mDeployed.\033[0m  %s is live.\n' "$BRANCH"
echo "  previous version : $OLD_DIR"
echo "  backups          : $BACKUP_DIR/pre-deploy-$STAMP.{tar.gz,sql.gz}"
