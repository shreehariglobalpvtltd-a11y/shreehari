#!/bin/bash
# =====================================================================
#  nginx-add-deny-rules.sh — runs ON THE VPS (Deploy to VPS → nginx=apply).
#
#  Adds the 30 Sep 2026 deny rules (dated .bak copies, deploy/, *.sh,
#  *.conf) to the LIVE nginx config: right after EVERY server-level
#  `root <site>;` line in every enabled site file, so each server block that
#  serves the site folder (www, the .network staff door) gets them. A root
#  set inside a location block is left alone. The live files have certbot edits, so they are patched in
#  place, never replaced by deploy/nginx-shreehariglobal.in.conf.
#
#  Safe by construction:
#    - idempotent: a block inserted by an earlier run is removed first, so
#      each root line ends up with exactly one copy;
#    - every file is copied to /root/backups/nginx/ before it is touched;
#    - `nginx -t` must pass, otherwise every copy is put back and nothing
#      is reloaded;
#    - after the reload the site must still answer 200 from this server's
#      own nginx, otherwise every copy is put back and nginx reloaded again.
#
#  Usage: bash nginx-add-deny-rules.sh <site root, e.g. /var/www/x/public_html>
#  The env overrides (SITES, NGINX_TEST, NGINX_RELOAD, BACKUP_DIR, CHECK_URL,
#  CHECK_RESOLVE) exist so the script can be exercised against a local nginx.
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

# A SERVER-LEVEL `root <site>;` line: the innermost open block is `server`.
# A root inside a location (e.g. `location = /sw.js { root ...; }`) must not
# get the rules: nginx refuses a regex location inside an exact one, and a
# nested rule would not guard the rest of the server anyway (30 Sep 2026,
# Deploy to VPS #47). Braces are tracked per character with comments cut;
# the directive in front of each `{` names the block.
SERVER_ROOT_AWK='
    function scan(s,    i, c, w) {
        sub(/#.*/, "", s)
        for (i = 1; i <= length(s); i++) {
            c = substr(s, i, 1)
            if (c == "{")      { split(stmt, w); stack[++sp] = w[1]; stmt = "" }
            else if (c == "}") { if (sp > 0) sp--; stmt = "" }
            else if (c == ";") { stmt = "" }
            else               { stmt = stmt c }
        }
        stmt = stmt " "
    }
    {
        target = ($1 == "root" && ($2 == r ";" || $2 == r "/;") && sp > 0 && stack[sp] == "server")
        scan($0)
    }
'

# Every file (by real path, once) with a server-level `root <site>;`.
files=()
for f in $SITES; do
    [ -f "$f" ] || continue
    real="$(readlink -f "$f")"
    case " ${files[*]-} " in *" $real "*) continue ;; esac
    if awk -v r="$SITE_ROOT" "$SERVER_ROOT_AWK"' target { found = 1 } END { exit !found }' "$real"; then
        files+=("$real")
    fi
done
if [ "${#files[@]}" -eq 0 ]; then
    echo "::error::no nginx file has 'root $SITE_ROOT;' — nothing changed"
    exit 1
fi

rules="$(mktemp)"
cat > "$rules" <<'RULES'

    # SHG deny rules 2026-09-30: dated .bak copies (index.php.bak.20260828-...),
    # editor copies, the deploy/ folder and shell/conf files were served as text.
    location ~* \.(bak|old|orig|save|swp)([.~_-][^/]*)?$ { deny all; return 404; }
    location ~ ~$                                         { deny all; return 404; }
    location ~ ^/deploy/                                  { deny all; return 404; }
    location ~* \.(sh|bash|conf)$                         { deny all; return 404; }
RULES

stamp="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"
changed=()
backups=()

restore_all() {
    local i
    for i in "${!changed[@]}"; do
        cp -a "${backups[$i]}" "${changed[$i]}"
        echo "restored ${changed[$i]} from ${backups[$i]}"
    done
}

for file in "${files[@]}"; do
    new="$(mktemp)"
    # 1) drop any block an earlier run inserted (blank line + marker comment
    #    + 1 more comment line + 4 location lines);
    # 2) insert one fresh block after EVERY server-level `root <site>;`, so the
    #    rules sit ahead of every regex location in each server block
    #    (nginx uses the first regex match).
    awk -v m="$MARKER" '
        skip > 0 { skip--; next }
        /^[[:space:]]*$/ { if (held) print hl; hl = $0; held = 1; next }
        index($0, m) { held = 0; skip = 5; next }
        { if (held) { print hl; held = 0 } print }
        END { if (held) print hl }
    ' "$file" | awk -v r="$SITE_ROOT" -v rf="$rules" "$SERVER_ROOT_AWK"'
        { print }
        target {
            while ((getline l < rf) > 0) print l
            close(rf)
        }
    ' > "$new"

    if cmp -s "$new" "$file"; then
        echo "unchanged (rules already in place): $file"
        rm -f "$new"
        continue
    fi
    backup="$BACKUP_DIR/$(basename "$file").$stamp"
    cp -a "$file" "$backup"
    cat "$new" > "$file"          # keeps the file's owner, mode and inode
    rm -f "$new"
    changed+=("$file")
    backups+=("$backup")
    echo "patched: $file (backup $backup, $(grep -c "$MARKER" "$file") server block(s))"
done
rm -f "$rules"

# Where the rules now sit, for the log: server blocks, names, roots, markers.
for file in "${files[@]}"; do
    echo "--- $file"
    grep -nE '^[[:space:]]*(server[[:space:]]*\{|listen|server_name|root|include)|SHG deny rules' "$file" \
        | sed -e "s#$SITE_ROOT#<site>#g" || true
done

if [ "${#changed[@]}" -eq 0 ]; then
    echo "deny rules already present everywhere — nothing to do"
    exit 0
fi

if ! $NGINX_TEST; then
    restore_all
    echo "::error::nginx -t failed with the new rules — original files restored, nginx NOT reloaded"
    exit 1
fi

$NGINX_RELOAD
sleep 2
code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 ${CHECK_RESOLVE:+--resolve "$CHECK_RESOLVE"} "$CHECK_URL" || true)"
if [ "$code" != "200" ]; then
    restore_all
    $NGINX_TEST && $NGINX_RELOAD
    echo "::error::site answered $code after the reload — original nginx files restored"
    exit 1
fi
echo "deny rules in place, nginx reloaded, site answers 200"
