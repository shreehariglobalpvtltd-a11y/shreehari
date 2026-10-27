#!/usr/bin/env bash
#
# Mark the migrations the live database ALREADY carries as applied.
#
# The live schema was upgraded by hand long before schema_migrations existed,
# so the table is empty (or absent) while the database is 67 migrations in.
# A deploy therefore replays every upgrade-*.sql from the beginning, and an
# early file whose ENUM list is narrower than today's schema fails:
#
#   upgrade-2026-08-agent-role.sql
#   ALTER TABLE `admins` MODIFY `role` ENUM(... no 'counter' ...)
#   ERROR 1265: Data truncated for column 'role' at row 12
#
# ...because upgrade-2026-09-counter-role.sql added 'counter' afterwards and a
# real admin uses it. Replaying the older file would narrow the column back.
#
# What the live code shipped with is what the live database has, so every
# upgrade-*.sql sitting in the live document root is recorded as applied.
# Migrations that arrive only with the new code are NOT in that directory, so
# they stay pending and the deploy still applies them.
#
# Run it ON THE VPS, as root:
#
#   bash backfill-schema-migrations.sh          # dry run — lists, changes nothing
#   bash backfill-schema-migrations.sh --go     # write the rows

set -euo pipefail

SITE=/var/www/shreehariglobal.in/public_html
DB=shari

DRY=true
[ "${1:-}" = "--go" ] && DRY=false

[ "$(id -u)" -eq 0 ]  || { echo "Run as root."; exit 1; }
[ -d "$SITE/database" ] || { echo "Not found: $SITE/database"; exit 1; }
mysql "$DB" -e 'SELECT 1' >/dev/null 2>&1 || { echo "Cannot reach database '$DB'."; exit 1; }

# Same shape as deploy/vps-pull-deploy.sh, so both deploy paths agree.
mysql "$DB" -e "
  CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(190) NOT NULL PRIMARY KEY,
    applied_at DATETIME     NOT NULL,
    sha1       CHAR(40)     NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"

before=$(mysql "$DB" -sNe 'SELECT COUNT(*) FROM schema_migrations')
echo "schema_migrations currently holds $before row(s)."
echo

n=0
for f in "$SITE"/database/upgrade-*.sql; do
  [ -f "$f" ] || continue
  name="$(basename "$f")"
  sha="$(sha1sum "$f" | cut -d' ' -f1)"
  known=$(mysql "$DB" -sNe "SELECT COUNT(*) FROM schema_migrations WHERE filename='$name'")
  if [ "$known" -gt 0 ]; then continue; fi
  n=$((n+1))
  if [ "$DRY" = true ]; then
    echo "  [DRY] would mark applied: $name"
  else
    mysql "$DB" -e "INSERT IGNORE INTO schema_migrations (filename, applied_at, sha1)
                    VALUES ('$name', NOW(), '$sha')"
    echo "  marked applied: $name"
  fi
done

echo
if [ "$n" -eq 0 ]; then
  echo "Nothing to do — every migration in the live tree is already recorded."
elif [ "$DRY" = true ]; then
  echo "$n migration(s) would be marked as applied. Nothing was written."
  echo "Run it for real:  bash $0 --go"
else
  after=$(mysql "$DB" -sNe 'SELECT COUNT(*) FROM schema_migrations')
  echo "Done. schema_migrations now holds $after row(s)."
  echo "The deploy will now skip these and apply only the genuinely new files."
fi
