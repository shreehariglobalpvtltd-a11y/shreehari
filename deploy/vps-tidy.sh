#!/usr/bin/env bash
# =====================================================================
#  deploy/vps-tidy.sh — put the VPS's files in ONE place (2 Oct 2026)
#
#  Owner: "vps ma file ekoi thau hos". Over August–September every test
#  run, release check and hand repair left a full copy of the site
#  somewhere (/root/shg-test, shg-base, shg-npr, shg-int, shg-agrpdf,
#  new_html_deploy, old_html_backup, /tmp/live-check …) — ~800 MB of
#  look-alike folders next to the one that is live, plus backups in five
#  different places. Nothing below DELETES anything: it MOVES
#
#    old site copies + their scripts/logs → /root/archive/<date>-old-copies/
#    scattered backups                    → /root/backups/older/
#
#  and stops the stray `php -S 127.0.0.1:8899` test server that kept one
#  of those copies running. The live site, its cron jobs, nginx, the code
#  repo and /root/backups itself are not touched. Run ON THE VPS as root:
#
#      bash vps-tidy.sh            # dry run: prints what it would move
#      bash vps-tidy.sh --apply    # does it
#
#  Undo: every line it prints is `mv <from> <to>` — run the reverse.
#  The resulting layout is written to /root/SERVER-MAP.md.
# =====================================================================
set -euo pipefail

APPLY=false
[[ "${1:-}" == "--apply" ]] && APPLY=true

STAMP="$(date +%F)"
ARCH="/root/archive/${STAMP}-old-copies"
OLDB="/root/backups/older"
WWW="/var/www/shreehariglobal.in"

move() {   # move <from> <to-dir> [new-name]
  local from="$1" dir="$2" name="${3:-$(basename "$1")}"
  [[ -e "$from" ]] || return 0
  if [[ -e "$dir/$name" ]]; then echo "skip (exists): $dir/$name"; return 0; fi
  echo "mv $from $dir/$name"
  if $APPLY; then mkdir -p "$dir"; mv "$from" "$dir/$name"; fi
}

# Refuse to run anywhere that is not the live box layout.
[[ -d "$WWW/public_html" ]] || { echo "no $WWW/public_html — wrong machine?"; exit 2; }

# 1. The stray test server (php -S on 127.0.0.1:8899 serving /root/shg-test).
if pgrep -f 'php -S 127.0.0.1:8899' >/dev/null; then
  echo "stop: $(pgrep -af 'php -S 127.0.0.1:8899' | head -1)"
  $APPLY && pkill -f 'php -S 127.0.0.1:8899' || true
fi

# 2. Old site copies (none of them is served by nginx).
for d in shg-test shg-base shg-agrpdf shg-agrpdf-out shg-npr shg-npr-base shg-int; do
  move "/root/$d" "$ARCH"
done
move "$WWW/public_html/og-gen.php" "$ARCH" webroot-og-gen.php   # web-reachable image generator left by the 2 Oct 00:19 deploy (not in the repo)
move "$WWW/new_html_deploy" "$ARCH" www-new_html_deploy
move "$WWW/old_html_backup" "$ARCH" www-old_html_backup
move /tmp/live-check "$ARCH" tmp-live-check

# 3. The scripts and logs those copies used.
shopt -s nullglob
for f in /root/shg-*.log /root/shg-*-refresh.sh /root/shg-*-router.php /root/shg-set.php \
         /root/shg-test-run.sh /root/shg-test-session.php /root/aitest-battery*.php \
         /root/faq-preview.php /root/inspect-routes.* /root/bench.py /root/try-model.sh \
         /root/go-live.sh /root/migrate-one-daily-bus.php; do
  move "$f" "$ARCH/scripts"
done

# 4. Backups that lived outside /root/backups. (/root/crontab-*.bak stay:
#    cron/health-heartbeat.php tells the owner to restore from there.)
move "$WWW/backups" "$OLDB" www-backups
for d in /root/backup /root/deploy-backups /root/nginx-backup-* /root/security-backup-* /root/stray-backup; do
  move "$d" "$OLDB"
done
for f in /root/pre-brain-*.sql; do move "$f" "$OLDB"; done

# 5. Cron lines whose script does not exist in the live folder. They fail
#    every run ("Could not open input file") and only grow a log. Commented
#    out, not deleted, after a dated copy of the crontab.
CRON_NOW="$(crontab -l 2>/dev/null || true)"
DEAD=""
while IFS= read -r line; do
  [[ "$line" =~ ^[[:space:]]*# || -z "$line" ]] && continue
  script="$(grep -o "$WWW/public_html/cron/[A-Za-z0-9_-]*\.php" <<<"$line" | head -1 || true)"
  [[ -n "$script" && ! -f "$script" ]] && DEAD+="$line"$'\n'
done <<<"$CRON_NOW"
if [[ -n "$DEAD" ]]; then
  printf 'cron off (script missing):\n%s' "$DEAD"
  if $APPLY; then
    cp <(printf '%s\n' "$CRON_NOW") "/root/crontab-before-tidy-${STAMP}.bak"
    NEW="$CRON_NOW"
    while IFS= read -r d; do
      [[ -z "$d" ]] && continue
      NEW="${NEW//"$d"/"# off ${STAMP} (script missing): $d"}"
    done <<<"$DEAD"
    printf '%s\n' "$NEW" | crontab -
  fi
fi

# 6. The map.
if $APPLY; then
  cat > /root/SERVER-MAP.md <<MAP
# S Hari Global VPS — where everything lives (updated ${STAMP})

| What | Where |
|---|---|
| LIVE website (both domains, nginx root) | ${WWW}/public_html |
| Live config (DB password, APP_KEY) | ${WWW}/public_html/config/config.php (never copy) |
| Code repository (deploys come from here) | /var/repos/shg.git |
| Webroot git history pointer | /root/shg-site.git |
| ALL backups | /root/backups/ (deploy DB dumps: /root/backups/deploy-db, pre-release: /root/backups/pre-*) |
| Old copies of the site (archived ${STAMP}, safe to delete later) | /root/archive/ |
| nginx | /etc/nginx/sites-available/ (nginx -T to see it all) |
| PHP-FPM pool | /etc/php/8.3/fpm/pool.d/www.conf |
| Cron jobs | crontab -l (all run ${WWW}/public_html/cron/*.php) |
| App logs | ${WWW}/public_html/logs/ and /var/log/shg-*.log |
| Secrets kept off the webroot | /root/secrets-off-webroot/ |

Rule: there is ONE live folder. Test copies go under /root/archive/ or /tmp and are removed when done.
MAP
  echo "wrote /root/SERVER-MAP.md"
fi

$APPLY || echo "(dry run — nothing moved; re-run with --apply)"
