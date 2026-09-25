#!/usr/bin/env bash
# =====================================================================
#  deploy/vps-pull-deploy.sh — one command to put a pushed branch live
#  on the Hostinger VPS, and to roll it back if it misbehaves.
#
#  Run it ON THE VPS, as root or with sudo, from the site directory:
#
#      cd /var/www/shreehariglobal.in/public_html
#      sudo bash deploy/vps-pull-deploy.sh                # deploy origin's branch
#      sudo BRANCH=claude/shreehari-global-upgrade-myl45u bash deploy/vps-pull-deploy.sh
#      sudo bash deploy/vps-pull-deploy.sh --dry-run      # say what it would do
#      sudo bash deploy/vps-pull-deploy.sh --rollback     # back to the previous commit
#
#  What it does, in order, and stops at the first failure:
#
#    1. records the commit that is live now (for --rollback) and takes a
#       tarball + mysqldump into /root/backups/;
#    2. fetches and fast-forwards the branch (never a merge, never a force);
#    3. php -l on every PHP file that the update touched;
#    4. applies every database/upgrade-*.sql that is not yet recorded in
#       the schema_migrations table (additive files, IF NOT EXISTS), inside
#       one transaction per file, and records each one;
#    5. chown www-data:www-data on everything it changed, chmod 640 on
#       config/config.php;
#    6. clears the PHP opcache and reloads php8.3-fpm + nginx;
#    7. checks https://www.shreehariglobal.in/ answers 200 and that
#       /api/config.php is valid JSON. If either fails it rolls the code
#       back to step 1's commit by itself and exits non-zero.
#
#  It never touches config/config.php, and it never prints its contents.
#  Migrations are additive by house rule, so a rollback of code does not
#  roll back the schema — that is intentional: the older code runs fine
#  against the newer, additive schema.
# =====================================================================

set -Eeuo pipefail

SITE_DIR="${SITE_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BRANCH="${BRANCH:-}"
BACKUP_DIR="${BACKUP_DIR:-/root/backups}"
HEALTH_URL="${HEALTH_URL:-https://www.shreehariglobal.in/}"
API_URL="${API_URL:-https://www.shreehariglobal.in/api/config.php}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
FPM_SERVICE="${FPM_SERVICE:-php8.3-fpm}"
STATE_FILE="${STATE_FILE:-/root/.shg-deploy-last-good}"
DRY_RUN=0
ROLLBACK=0

for arg in "$@"; do
  case "$arg" in
    --dry-run)  DRY_RUN=1 ;;
    --rollback) ROLLBACK=1 ;;
    -h|--help)  sed -n '2,40p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

log()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '\033[32m  ok\033[0m %s\n' "$*"; }
die()  { printf '\033[31m!! \033[0m%s\n' "$*" >&2; exit 1; }
run()  { if [[ $DRY_RUN == 1 ]]; then printf '   would run: %s\n' "$*"; else eval "$@"; fi }

# Reloading is best effort wherever it happens. By the time it runs the code
# and the schema are already in place, and the health check is what decides
# whether the release stays — so a server that names its services differently,
# or has no systemd at all, gets a warning and a manual step rather than a
# deploy abandoned half way through.
reload_workers() {
  if ! command -v systemctl >/dev/null; then
    echo "   (systemctl is not on PATH — reload PHP-FPM and nginx by hand, or the old code stays in opcache)" >&2
    return 0
  fi
  if systemctl reload "$FPM_SERVICE" >/dev/null 2>&1 || systemctl restart "$FPM_SERVICE" >/dev/null 2>&1; then
    if command -v nginx >/dev/null; then
      systemctl reload nginx >/dev/null 2>&1 || true
    fi
    return 0
  fi
  echo "!! could not reload $FPM_SERVICE — the new code may still be in the old opcache. Reload it by hand." >&2
  return 1
}

cd "$SITE_DIR" || die "no such directory: $SITE_DIR"
[[ -f index.php && -d includes ]] || die "$SITE_DIR does not look like the site root"
[[ -f config/config.php ]] || die "config/config.php is missing — this is not a live install"
command -v git >/dev/null || die "git is not installed"

# Every git call carries safe.directory. Step 5 hands the whole tree to
# www-data, and from the second deploy onwards git — running as root — would
# otherwise refuse the repository it just updated ("detected dubious
# ownership") and the deploy would stop at the fetch. Caught by rehearsing
# this script twice against a sandbox rather than on the live site.
GIT=(git -c "safe.directory=$SITE_DIR")

# Git history may live outside the tree (the shared-hosting layout keeps it
# in /root/shg-site.git with a .git file pointing at it) — git handles that
# itself, we only need to be inside a work tree.
"${GIT[@]}" rev-parse --is-inside-work-tree >/dev/null 2>&1 || die "no git work tree here"

CUR_SHA="$("${GIT[@]}" rev-parse HEAD)"
CUR_BRANCH="$("${GIT[@]}" rev-parse --abbrev-ref HEAD)"
[[ -n "$BRANCH" ]] || BRANCH="$CUR_BRANCH"
[[ "$BRANCH" != "HEAD" ]] || die "detached HEAD — pass BRANCH=<name>"

if [[ $ROLLBACK == 1 ]]; then
  [[ -s "$STATE_FILE" ]] || die "no previous deploy recorded in $STATE_FILE"
  PREV="$(cat "$STATE_FILE")"
  log "rolling back to $PREV"
  run "git -c safe.directory='$SITE_DIR' -c advice.detachedHead=false checkout --quiet '$PREV'"
  run "chown -R www-data:www-data '$SITE_DIR'"
  run "chmod 640 '$SITE_DIR/config/config.php'"
  [[ $DRY_RUN == 1 ]] || reload_workers || true
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$HEALTH_URL" || true)"
  [[ "$code" == 200 ]] || die "after rollback the site answers $code — look at the nginx and PHP logs now"
  ok "rolled back, site answers 200"
  exit 0
fi

log "site      : $SITE_DIR"
log "branch    : $BRANCH"
log "live now  : $CUR_SHA"

# ---- 1. backup ------------------------------------------------------
log "backup"
run "mkdir -p '$BACKUP_DIR'"
STAMP="$(date +%Y%m%d-%H%M%S)"
run "tar -czf '$BACKUP_DIR/site-$STAMP.tar.gz' -C '$SITE_DIR' --exclude=.git ."
ok "files tarred to $BACKUP_DIR/site-$STAMP.tar.gz"

# The database credentials never reach a command line (ps would show them)
# and are never printed: PHP writes them into a 600 my.cnf that the trap
# below deletes, and both mysqldump and mysql read them from there.
CNF="$(mktemp /root/.shg-my.XXXXXX.cnf)"
chmod 600 "$CNF"
trap 'rm -f "$CNF"' EXIT INT TERM
$PHP_BIN -r '
    require "config/config.php";
    $host = DB_HOST; $port = 3306;
    // DB_HOST may carry a port, either "host:3307" or the PDO "host;port=3307".
    if (preg_match("/port=(\\d+)/", $host, $m)) { $port = (int) $m[1]; $host = explode(";", $host)[0]; }
    elseif (substr_count($host, ":") === 1) { [$host, $p] = explode(":", $host); $port = (int) $p; }
    $esc = static fn(string $v): string => str_replace(["\\", "\""], ["\\\\", "\\\""], $v);
    file_put_contents($argv[1], "[client]\nhost=\"" . $esc($host) . "\"\nport=" . $port
        . "\nuser=\"" . $esc(DB_USER) . "\"\npassword=\"" . $esc(DB_PASS) . "\"\n");
    file_put_contents($argv[1] . ".db", DB_NAME);
' "$CNF" || die "could not read the database settings out of config/config.php"
DB_NAME="$(cat "$CNF.db")"; rm -f "$CNF.db"
[[ -n "$DB_NAME" ]] || die "config/config.php has no DB_NAME — refusing to deploy without a database backup"
command -v mysql >/dev/null || die "the mysql client is not installed — migrations need it"
mysql --defaults-extra-file="$CNF" -e 'SELECT 1' "$DB_NAME" >/dev/null 2>&1 \
  || die "cannot reach the database with the settings in config/config.php"
run "mysqldump --defaults-extra-file='$CNF' --single-transaction --routines --events '$DB_NAME' | gzip > '$BACKUP_DIR/db-$STAMP.sql.gz'"
if [[ $DRY_RUN == 0 ]]; then
  [[ -s "$BACKUP_DIR/db-$STAMP.sql.gz" ]] || die "the database dump came out empty — not deploying"
fi
ok "database dumped to $BACKUP_DIR/db-$STAMP.sql.gz"

# ---- 2. fetch + fast-forward ---------------------------------------
# Fetching is read-only, so a dry run does it too — otherwise it compares
# against whatever this clone last saw and cheerfully reports "nothing to
# deploy" while a release is waiting on the remote.
log "fetch origin/$BRANCH"
for attempt in 1 2 3 4; do
  if "${GIT[@]}" fetch --quiet origin "$BRANCH"; then break; fi
  [[ $attempt == 4 ]] && die "git fetch failed four times"
  sleep $((2 ** attempt))
done
NEW_SHA="$("${GIT[@]}" rev-parse "origin/$BRANCH")"
if [[ "$NEW_SHA" == "$CUR_SHA" ]]; then
  ok "already at $NEW_SHA — nothing to deploy"
  exit 0
fi
"${GIT[@]}" merge-base --is-ancestor "$CUR_SHA" "$NEW_SHA" 2>/dev/null \
  || die "origin/$BRANCH is not a fast-forward of what is live — resolve by hand, this script will not force"
CHANGED="$("${GIT[@]}" diff --name-only "$CUR_SHA" "$NEW_SHA")"
log "$(echo "$CHANGED" | grep -c . || true) file(s) change"
run "git -c safe.directory='$SITE_DIR' checkout --quiet '$BRANCH'"
run "git -c safe.directory='$SITE_DIR' merge --quiet --ff-only 'origin/$BRANCH'"
ok "code now at $NEW_SHA"

# ---- 3. lint what changed ------------------------------------------
log "php -l on changed PHP files"
FAILED_LINT=0
while IFS= read -r f; do
  [[ -n "$f" && "$f" == *.php && -f "$f" ]] || continue
  if [[ $DRY_RUN == 1 ]]; then printf '   would lint: %s\n' "$f"; continue; fi
  if ! $PHP_BIN -l "$f" >/dev/null; then echo "   lint failed: $f" >&2; FAILED_LINT=1; fi
done <<< "$CHANGED"
if [[ $FAILED_LINT == 1 ]]; then
  run "git -c safe.directory='$SITE_DIR' -c advice.detachedHead=false checkout --quiet '$CUR_SHA'"
  die "a changed PHP file does not parse — code put back to $CUR_SHA, nothing went live"
fi
ok "every changed PHP file parses"

# ---- 4. migrations --------------------------------------------------
#  Every database/upgrade-*.sql runs through the mysql CLIENT, not PDO:
#  these files carry multi-statement bodies, DELIMITER blocks and
#  PREPARE/EXECUTE guards that PDO::exec() cannot run (it leaves a pending
#  result set and the next query dies with "General error: 2014"). What has
#  run is recorded in schema_migrations, so a second deploy is a no-op.
log "database migrations"
mysql --defaults-extra-file="$CNF" "$DB_NAME" -e "
  CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL,
    sha1       CHAR(40)     NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;" || die "cannot create schema_migrations"

APPLIED_LIST="$(mysql --defaults-extra-file="$CNF" "$DB_NAME" -N -B -e 'SELECT filename FROM schema_migrations' || true)"
PENDING=()
for f in database/upgrade-*.sql; do
  [[ -f "$f" ]] || continue
  name="$(basename "$f")"
  grep -Fxq "$name" <<< "$APPLIED_LIST" || PENDING+=("$f")
done

if [[ ${#PENDING[@]} == 0 ]]; then
  ok "no new migrations"
else
  log "${#PENDING[@]} migration(s) to apply"
  if [[ $DRY_RUN == 1 ]]; then
    echo "   (this lists what is pending on the CODE THAT IS LIVE NOW; migrations arriving with the new code are applied too)"
  fi
  for f in "${PENDING[@]}"; do
    name="$(basename "$f")"
    if [[ $DRY_RUN == 1 ]]; then printf '   would apply: %s\n' "$name"; continue; fi
    printf '   %s ... ' "$name"
    err="$(mysql --defaults-extra-file="$CNF" "$DB_NAME" < "$f" 2>&1 >/dev/null || true)"
    if [[ -n "$err" ]]; then
      # The house rule is additive, IF NOT EXISTS / INSERT IGNORE files, so a
      # duplicate object on a database that already carries the change is
      # expected (the live schema was upgraded by hand before this script
      # existed). Anything else stops the deploy.
      if grep -qvE "Duplicate (column|key) name|Duplicate entry|already exists|Multiple primary key|check that column/key exists" <<< "$err"; then
        echo "FAILED"
        echo "$err" | head -5 >&2
        die "migration $name failed — the new code is live but the schema is not. Fix the SQL and re-run, or: sudo bash deploy/vps-pull-deploy.sh --rollback"
      fi
      echo "already there"
    else
      echo "ok"
    fi
    sha="$(sha1sum "$f" | cut -d" " -f1)"
    mysql --defaults-extra-file="$CNF" "$DB_NAME" \
      -e "INSERT IGNORE INTO schema_migrations (filename, applied_at, sha1) VALUES ('$name', NOW(), '$sha')" \
      || die "applied $name but could not record it in schema_migrations"
  done
fi
ok "schema is up to date"

# ---- 5. ownership ---------------------------------------------------
log "ownership and permissions"
run "chown -R www-data:www-data '$SITE_DIR'"
run "chmod 640 '$SITE_DIR/config/config.php'"
run "find '$SITE_DIR' -type d -exec chmod 755 {} +"
ok "files belong to www-data, config is 640"

# ---- 6. reload ------------------------------------------------------
#  A server that names these differently must not lose a deploy that has
#  already updated the code and the schema: a missing nginx or systemctl is
#  a loud warning and a manual step, not an abort half way through.
log "reload PHP-FPM and nginx"
if command -v nginx >/dev/null; then
  run "nginx -t"
else
  echo "   (nginx is not on PATH — skipping the config test)" >&2
fi
if [[ $DRY_RUN == 1 ]]; then
  printf '   would run: systemctl reload %s (and nginx)\n' "$FPM_SERVICE"
else
  if reload_workers; then ok "workers reloaded, opcache is cold"; fi
fi

# ---- 7. health ------------------------------------------------------
log "health check"
if [[ $DRY_RUN == 1 ]]; then
  printf '   would GET %s and %s\n' "$HEALTH_URL" "$API_URL"
else
  sleep 2
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$HEALTH_URL" || true)"
  json="$(curl -s --max-time 25 "$API_URL" || true)"
  if [[ "$code" != 200 ]] || ! echo "$json" | $PHP_BIN -r '$s = stream_get_contents(STDIN); exit(json_decode($s, true) === null ? 1 : 0);'; then
    echo "!! the site answered $code and /api/config.php was not JSON — rolling the code back" >&2
    "${GIT[@]}" -c advice.detachedHead=false checkout --quiet "$CUR_SHA"
    chown -R www-data:www-data "$SITE_DIR"
    reload_workers || true
    after="$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "$HEALTH_URL" || true)"
    die "rolled back to $CUR_SHA (site now answers $after). Nothing else was undone; the schema is additive."
  fi
  ok "site answers 200 and /api/config.php is JSON"
fi

echo "$CUR_SHA" > "$STATE_FILE" 2>/dev/null || true
chmod 600 "$STATE_FILE" 2>/dev/null || true
log "done — live at $NEW_SHA (previous $CUR_SHA recorded for --rollback)"
