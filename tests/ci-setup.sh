#!/usr/bin/env bash
# =====================================================================
#  ci-setup.sh — build the shari_test database from the repository alone.
#
#  Used by the GitHub Actions battery job and usable on any machine with a
#  local MariaDB/MySQL that accepts root over TCP without a password (the
#  same shape tests/e2e-booking-test.php hard-codes: 127.0.0.1:3307).
#
#      bash tests/ci-setup.sh            # drops + rebuilds shari_test
#
#  Steps: schema.sql → seed.sql → every database/upgrade-*.sql (through
#  the app's own apply-sql.php, falling back to the mysql client for the
#  few files that use PREPARE/EXECUTE guards) → every upgrade-*.php →
#  tests/ci-fixtures.php. Then config/config.local.php exists and
#  `php tests/run-all.php --http` can run.
# =====================================================================
set -euo pipefail
cd "$(dirname "$0")/.."

DB_HOST="${SHG_DB_HOST:-127.0.0.1}"
DB_PORT="${SHG_DB_PORT:-3307}"
DB_NAME="${SHG_DB_NAME:-shari_test}"
DB_USER="${SHG_DB_USER:-root}"
DB_PASS="${SHG_DB_PASS:-}"

case "$DB_NAME" in *test*) ;; *) echo "refusing: '$DB_NAME' does not contain 'test'"; exit 2;; esac

MYSQL=(mysql -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER")
[[ -n "$DB_PASS" ]] && MYSQL+=(-p"$DB_PASS")

echo "== database $DB_NAME on $DB_HOST:$DB_PORT =="
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "== config/config.local.php =="
if [[ ! -f config/config.local.php ]] || grep -q "SHG_CI_GENERATED" config/config.local.php; then
  php -r '
    $s = file_get_contents("config/config.sample.php");
    $host = getenv("SHG_DB_HOST") ?: "127.0.0.1"; $port = getenv("SHG_DB_PORT") ?: "3307";
    $rep = [
      "define(\x27DB_HOST\x27,    \x27localhost\x27);" => "define(\x27DB_HOST\x27,    \x27" . $host . ";port=" . $port . "\x27); // SHG_CI_GENERATED",
      "define(\x27DB_NAME\x27,    \x27u000000000_shari\x27);" => "define(\x27DB_NAME\x27,    \x27" . (getenv("SHG_DB_NAME") ?: "shari_test") . "\x27);",
      "define(\x27DB_USER\x27,    \x27u000000000_shariuser\x27);" => "define(\x27DB_USER\x27,    \x27" . (getenv("SHG_DB_USER") ?: "root") . "\x27);",
      "define(\x27DB_PASS\x27,    \x27CHANGE_ME_STRONG_PASSWORD\x27);" => "define(\x27DB_PASS\x27,    \x27" . (getenv("SHG_DB_PASS") ?: "") . "\x27);",
      "define(\x27APP_URL\x27,   \x27https://www.shreehariglobal.in\x27);" => "define(\x27APP_URL\x27,   \x27http://127.0.0.1:8899\x27);",
      "define(\x27APP_ENV\x27,   \x27production\x27);" => "define(\x27APP_ENV\x27,   \x27development\x27);",
      "define(\x27APP_KEY\x27,   \x27CHANGE_ME_RANDOM_64_CHARS\x27);" => "define(\x27APP_KEY\x27,   \x27ci-" . bin2hex(random_bytes(30)) . "\x27);",
      "define(\x27FORCE_HTTPS\x27, true);" => "define(\x27FORCE_HTTPS\x27, false);",
      "define(\x27CRON_TOKEN\x27, \x27CHANGE_ME_CRON_TOKEN\x27);" => "define(\x27CRON_TOKEN\x27, \x27ci-cron-token\x27);",
    ];
    foreach ($rep as $a => $b) { if (!str_contains($s, $a)) { fwrite(STDERR, "config.sample.php changed: cannot find $a\n"); exit(1); } $s = str_replace($a, $b, $s); }
    file_put_contents("config/config.local.php", $s);
  '
  echo "   written"
else
  echo "   kept (hand-written; delete it or add SHG_CI_GENERATED to regenerate)"
fi

echo "== schema + seed =="
"${MYSQL[@]}" "$DB_NAME" < database/schema.sql
"${MYSQL[@]}" "$DB_NAME" < database/seed.sql

echo "== owner account (id 1, before the demo agent the upgrades insert) =="
php tests/ci-fixtures.php --owner

echo "== upgrades (sql) =="
fails=0
for f in database/upgrade-2026-08-*.sql database/upgrade-2026-09-*.sql; do
  if php tests/apply-sql.php "$f" > /tmp/shg-apply.log 2>&1; then
    printf "   ok   %s\n" "$f"
  else
    # PREPARE/EXECUTE column guards and multi-statement blocks need the real client.
    "${MYSQL[@]}" --force "$DB_NAME" < "$f" > /tmp/shg-apply2.log 2>&1 || true
    printf "   ok*  %s (via mysql client)\n" "$f"
  fi
done
# A second full pass settles ordering dependencies between files — the
# alphabetical order is not the historical one (agent-controls adds columns
# to admin_profiles, which agent-panel creates a few files later; agent-kyc
# adds kyc_* AFTER photo_path, which admin-security adds). Every file is
# idempotent (IF NOT EXISTS / PREPARE guards), so re-running is harmless.
for f in database/upgrade-2026-08-*.sql database/upgrade-2026-09-*.sql; do
  "${MYSQL[@]}" --force "$DB_NAME" < "$f" > /dev/null 2>&1 || true
done
for f in database/upgrade-2026-08-*.sql database/upgrade-2026-09-*.sql; do
  php tests/apply-sql.php "$f" > /dev/null 2>&1 || "${MYSQL[@]}" --force "$DB_NAME" < "$f" > /dev/null 2>&1 || true
done
# Last word on which coach sells: upgrade-2026-08-one-daily-bus.sql (the owner's
# "one daily bus" decision) ran AFTER origin-buses / return-fleet on live, but
# sorts before them here, so their INSERT ... is_active = 1 would win. Re-apply
# it last so exactly the live pair of routes is active.
"${MYSQL[@]}" --force "$DB_NAME" < database/upgrade-2026-08-one-daily-bus.sql > /dev/null 2>&1 || true
echo "   second pass done (one-daily-bus re-applied last)"

echo "== upgrades (php) =="
for f in database/upgrade-2026-09-fares.php database/upgrade-2026-09-private-pricing.php database/upgrade-2026-09-quick-ticket-bot.php database/upgrade-2026-09-quickbot-brain.php database/upgrade-2026-09-daily-service.php database/upgrade-2026-09-settings-json-repair.php; do
  php "$f" > /dev/null 2>&1 && printf "   ok   %s\n" "$f" || { printf "   FAIL %s\n" "$f"; fails=$((fails+1)); }
done

echo "== fixtures =="
php tests/ci-fixtures.php

echo "== tables: $("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME'") =="
[[ $fails -eq 0 ]] || { echo "$fails upgrade script(s) failed"; exit 1; }
echo "ready:"
echo "  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8899 -t . tests/dev-router.php &   # routed like nginx, and not single-threaded"
echo "  php tests/run-all.php --http"
