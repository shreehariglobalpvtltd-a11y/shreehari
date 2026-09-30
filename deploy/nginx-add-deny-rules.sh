#!/bin/bash
# =====================================================================
#  nginx-add-deny-rules.sh — runs ON THE VPS (Deploy to VPS → nginx=apply).
#
#  Adds the 30 Sep 2026 deny rules (dated .bak copies, deploy/, *.sh,
#  *.conf) to the LIVE nginx site file, right after its `root <site>;`
#  line. The live file has certbot edits, so it is patched in place, never
#  replaced by deploy/nginx-shreehariglobal.in.conf.
#
#  Safe by construction:
#    - idempotent: a file that already carries the marker is left alone;
#    - the file is copied to /root/backups/nginx/ first;
#    - `nginx -t` must pass, otherwise the copy is put back and nothing is
#      reloaded;
#    - after the reload the site must still answer 200 on localhost,
#      otherwise the copy is put back and nginx reloaded again.
#
#  Usage: bash nginx-add-deny-rules.sh <site root, e.g. /var/www/x/public_html>
#  The env overrides (SITES, NGINX_TEST, NGINX_RELOAD, BACKUP_DIR, CHECK_URL,
#  CHECK_RESOLVE)
#  exist so the script can be exercised against a local nginx.
# =====================================================================
set -euo pipefail

SITE_ROOT="${1:?site root path required}"
SITE_ROOT="${SITE_ROOT%/}"
SITES="${SITES:-/etc/nginx/sites-enabled/* /etc/nginx/conf.d/*.conf}"
NGINX_TEST="${NGINX_TEST:-nginx -t}"
NGINX_RELOAD="${NGINX_RELOAD:-systemctl reload nginx}"
BACKUP_DIR="${BACKUP_DIR:-/root/backups/nginx}"
CHECK_URL="${CHECK_URL:-https://www.shreehariglobal.in/}"
# Ask THIS server's nginx, not whatever the public DNS points at.
CHECK_RESOLVE="${CHECK_RESOLVE-www.shreehariglobal.in:443:127.0.0.1}"
MARKER='SHG deny rules 2026-09-30'

# The site file is the one whose `root` is the live folder.
file=''
for f in $SITES; do
    [ -f "$f" ] || continue
    if awk -v r="$SITE_ROOT" '$1 == "root" && ($2 == r ";" || $2 == r "/;") { found = 1 } END { exit !found }' "$f"; then
        file="$(readlink -f "$f")"
        break
    fi
done
if [ -z "$file" ]; then
    echo "::error::no nginx site file has 'root $SITE_ROOT;' — nothing changed"
    exit 1
fi
echo "nginx site file: $file"

if grep -q "$MARKER" "$file"; then
    echo "deny rules already present — nothing to do"
    exit 0
fi

mkdir -p "$BACKUP_DIR"
backup="$BACKUP_DIR/$(basename "$file").$(date +%Y%m%d-%H%M%S)"
cp -a "$file" "$backup"
echo "backup: $backup"

rules="$(mktemp)"
cat > "$rules" <<'RULES'

    # SHG deny rules 2026-09-30: dated .bak copies (index.php.bak.20260828-...),
    # editor copies, the deploy/ folder and shell/conf files were served as text.
    location ~* \.(bak|old|orig|save|swp)([.~_-][^/]*)?$ { deny all; return 404; }
    location ~ ~$                                         { deny all; return 404; }
    location ~ ^/deploy/                                  { deny all; return 404; }
    location ~* \.(sh|bash|conf)$                         { deny all; return 404; }
RULES

restore() {
    cp -a "$backup" "$file"
    echo "restored $file from $backup"
}

# Insert after the FIRST `root <site>;` line, so the rules sit ahead of every
# regex location in that server block (nginx uses the first regex match).
awk -v r="$SITE_ROOT" -v rf="$rules" '
    !done && $1 == "root" && ($2 == r ";" || $2 == r "/;") {
        print
        while ((getline l < rf) > 0) print l
        done = 1
        next
    }
    { print }
' "$backup" > "$file"
rm -f "$rules"

if ! $NGINX_TEST; then
    restore
    echo "::error::nginx -t failed with the new rules — original file restored, nginx NOT reloaded"
    exit 1
fi

$NGINX_RELOAD
sleep 2
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 ${CHECK_RESOLVE:+--resolve "$CHECK_RESOLVE"} "$CHECK_URL" || true)"
if [ "$code" != "200" ]; then
    restore
    $NGINX_TEST && $NGINX_RELOAD
    echo "::error::site answered $code after the reload — original nginx file restored"
    exit 1
fi
echo "deny rules added, nginx reloaded, site answers 200"
