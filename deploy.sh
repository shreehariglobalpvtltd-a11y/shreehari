#!/usr/bin/env bash
# ============================================================
# SHG — AUTO DEPLOY to Hostinger VPS
# Usage:
#   bash deploy.sh              → full deploy
#   bash deploy.sh --dry-run    → preview only (no changes)
#   bash deploy.sh --git        → only files changed in git (fast)
#   bash deploy.sh --file path  → deploy a single file
# ============================================================
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CONFIG="${SCRIPT_DIR}/.deploy.env"
LOG="${SCRIPT_DIR}/logs/deploy.log"
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

# ── Load config ─────────────────────────────────────────────
if [[ ! -f "$CONFIG" ]]; then
  echo "❌  .deploy.env not found."
  echo "    Run:  bash setup-deploy.sh"
  exit 1
fi
source "$CONFIG"

# ── Defaults ────────────────────────────────────────────────
VPS_USER="${VPS_USER:-root}"
VPS_HOST="${VPS_HOST:-93.127.167.249}"
VPS_PATH="${VPS_PATH:-/home/u123456789/public_html}"
SSH_KEY="${SSH_KEY:-$HOME/.ssh/shg_vps_ed25519}"
SITE_URL="${SITE_URL:-https://shreehariglobal.in}"
SSH_KEY="${SSH_KEY/#\~/$HOME}"  # expand ~ properly

# ── Args ────────────────────────────────────────────────────
MODE="full"
SINGLE_FILE=""
DO_BACKUP=false
MIGRATE_FILE=""
for arg in "$@"; do
  case "$arg" in
    --dry-run) MODE="dry"    ;;
    --git)     MODE="git"    ;;
    --file)    MODE="file"   ;;
    --backup)  DO_BACKUP=true ;;
    --migrate) MODE="migrate";;
    *)
      if [[ "$MODE" == "file" && -z "$SINGLE_FILE" ]]; then
        SINGLE_FILE="$arg"
      elif [[ "$MODE" == "migrate" && -z "$MIGRATE_FILE" ]]; then
        MIGRATE_FILE="$arg"
      fi
      ;;
  esac
done

# ── SSH options ──────────────────────────────────────────────
SSH_OPTS="-i ${SSH_KEY} -o StrictHostKeyChecking=no -o ConnectTimeout=10"

# ── Verify SSH key exists ────────────────────────────────────
if [[ ! -f "$SSH_KEY" ]]; then
  echo "❌  SSH key not found: $SSH_KEY"
  echo "    Run:  bash setup-deploy.sh"
  exit 1
fi

# ── Header ──────────────────────────────────────────────────
echo ""
echo "╔══════════════════════════════════════════════════════╗"
echo "║   SHG Deploy → ${VPS_USER}@${VPS_HOST}"
echo "║   Mode: ${MODE} | $(date '+%H:%M:%S')"
echo "╚══════════════════════════════════════════════════════╝"
[[ "$MODE" == "dry" ]] && echo "   ⚠️  DRY RUN — no changes will be made on server"
echo ""

# ── Files to always exclude ──────────────────────────────────
EXCLUDES=(
  --exclude='.git/'
  --exclude='.deploy.env'
  --exclude='*.env'
  --exclude='config/config.local.php'
  --exclude='config/config.local.php.bak'
  # database/set-twilio-credentials.sql holds the live Twilio auth token that
  # ALSO signs inbound webhook requests. It is gitignored, but a `rsync ./`
  # from a laptop with a working copy would ship it to /database on the VPS
  # — where any Apache misconfig could serve it. Explicit exclude closes that.
  --exclude='database/set-twilio-credentials.sql'
  --exclude='database/set-*-credentials.sql'
  --exclude='uploads/'
  --exclude='tickets/'
  --exclude='invoice/'
  --exclude='backup/'
  --exclude='logs/'
  --exclude='*.tmp'
  --exclude='*.log'
  --exclude='.DS_Store'
  --exclude='Thumbs.db'
  --exclude='node_modules/'
  --exclude='*.md'
  --exclude='tests/'
  --exclude='SHG-*.zip'
  --exclude='SHG-*.pdf'
  --exclude='SHG-*.txt'
  --exclude='SHG-*.zip'
  --exclude='AUDIT-*.md'
  --exclude='CLAUDE-*.md'
  --exclude='DEPLOY*.md'
  --exclude='LIVE-*.md'
  --exclude='NAYA-*.md'
  --exclude='MASTER-*.md'
  --exclude='*-PROMPT-*.md'
  --exclude='*-NOTES-*.txt'
  --exclude='setup-deploy.sh'
  --exclude='install.php'
)

# ── Build rsync command ──────────────────────────────────────
RSYNC=(rsync -avz --checksum --human-readable)
[[ "$MODE" == "dry" ]] && RSYNC+=(--dry-run)
RSYNC+=("${EXCLUDES[@]}")
RSYNC+=(-e "ssh ${SSH_OPTS}")

REMOTE="${VPS_USER}@${VPS_HOST}:${VPS_PATH}/"

# ── Pre-deploy: remote DB backup (--backup or --migrate) ─────
if [[ "$DO_BACKUP" == true || -n "$MIGRATE_FILE" ]]; then
  echo "💾  Taking remote DB backup..."
  BACKUP_NAME="shg-backup-$(date '+%Y%m%d-%H%M%S').sql.gz"
  BACKUP_PATH="/var/www/shreehariglobal.in/backups"
  # Read DB creds from the VPS bootstrap
  ssh ${SSH_OPTS} "${VPS_USER}@${VPS_HOST}" bash -c "'
    mkdir -p ${BACKUP_PATH}
    # Grab creds from the live config.php (the only DB password source)
    DB_HOST=\$(grep -oP \"(?<=DB_HOST.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
    DB_NAME=\$(grep -oP \"(?<=DB_NAME.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
    DB_USER=\$(grep -oP \"(?<=DB_USER.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
    DB_PASS=\$(grep -oP \"(?<=DB_PASS.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
    mysqldump -h \"\${DB_HOST:-localhost}\" -u \"\${DB_USER}\" -p\"\${DB_PASS}\" \"\${DB_NAME}\" --single-transaction --routines --triggers 2>/dev/null | gzip > ${BACKUP_PATH}/${BACKUP_NAME}
    SIZE=\$(du -sh ${BACKUP_PATH}/${BACKUP_NAME} | cut -f1)
    echo \"✅  Backup saved: ${BACKUP_PATH}/${BACKUP_NAME} (\${SIZE})\"
    # Keep only last 10 backups
    ls -1t ${BACKUP_PATH}/shg-backup-*.sql.gz 2>/dev/null | tail -n +11 | xargs rm -f 2>/dev/null
  '"
  echo ""
fi

# ── Deploy based on mode ─────────────────────────────────────
case "$MODE" in

  # ── Single file ────────────────────────────────────────────
  file)
    if [[ -z "$SINGLE_FILE" ]]; then
      echo "❌  Specify a file: bash deploy.sh --file path/to/file.php"
      exit 1
    fi
    echo "📄  Deploying single file: $SINGLE_FILE"
    REL="${SINGLE_FILE#${SCRIPT_DIR}/}"
    REMOTE_DIR="${VPS_PATH}/$(dirname "$REL")"
    ssh ${SSH_OPTS} "${VPS_USER}@${VPS_HOST}" "mkdir -p '${REMOTE_DIR}'"
    scp -i "${SSH_KEY}" -o StrictHostKeyChecking=no \
      "${SCRIPT_DIR}/${REL}" \
      "${VPS_USER}@${VPS_HOST}:${REMOTE_DIR}/"
    echo "✅  Uploaded: $REL"
    ;;

  # ── Git-changed files only ─────────────────────────────────
  git)
    echo "🔍  Finding git-changed files..."
    # Files changed vs origin/main (or last 10 commits as fallback)
    CHANGED=$(git -C "$SCRIPT_DIR" diff --name-only origin/main HEAD 2>/dev/null \
              || git -C "$SCRIPT_DIR" diff --name-only HEAD~10 HEAD 2>/dev/null \
              || true)
    if [[ -z "$CHANGED" ]]; then
      echo "   No changed files found vs origin/main."
      echo "   Falling back to full deploy..."
      MODE="full"
    else
      echo "   Changed files:"
      echo "$CHANGED" | sed 's/^/   • /'
      echo ""
      # Write to temp file-list for rsync
      TMPLIST=$(mktemp /tmp/shg-deploy-XXXX.txt)
      echo "$CHANGED" > "$TMPLIST"
      "${RSYNC[@]}" --files-from="$TMPLIST" "${SCRIPT_DIR}/" "$REMOTE"
      rm -f "$TMPLIST"
      echo ""
      echo "✅  Git-changed files deployed."
    fi
    [[ "$MODE" == "git" ]] && break
    ;& # fall through to full if mode was reset

  # ── Full sync ─────────────────────────────────────────────
  full|dry)
    echo "📦  Syncing full site..."
    "${RSYNC[@]}" "${SCRIPT_DIR}/" "$REMOTE"
    echo ""
    [[ "$MODE" == "dry" ]] && echo "✅  Dry-run complete. Add --dry-run to preview, or run without it to deploy." && exit 0
    echo "✅  Full sync complete."
    ;;

  # ── Run a migration SQL on the VPS ────────────────────────
  migrate)
    if [[ -z "$MIGRATE_FILE" ]]; then
      echo "❌  Specify a SQL file: bash deploy.sh --migrate database/upgrade-xxx.sql"
      exit 1
    fi
    if [[ ! -f "${SCRIPT_DIR}/${MIGRATE_FILE}" ]]; then
      echo "❌  File not found locally: ${MIGRATE_FILE}"
      exit 1
    fi
    echo "🗄️  Running migration: ${MIGRATE_FILE}"
    # Upload the SQL file to a temp location
    scp -i "${SSH_KEY}" -o StrictHostKeyChecking=no \
      "${SCRIPT_DIR}/${MIGRATE_FILE}" \
      "${VPS_USER}@${VPS_HOST}:/tmp/shg-migrate.sql"
    # Execute it
    ssh ${SSH_OPTS} "${VPS_USER}@${VPS_HOST}" bash -c "'
      DB_HOST=\$(grep -oP \"(?<=DB_HOST.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
      DB_NAME=\$(grep -oP \"(?<=DB_NAME.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
      DB_USER=\$(grep -oP \"(?<=DB_USER.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
      DB_PASS=\$(grep -oP \"(?<=DB_PASS.{0,20})['\\\"]\\K[^'\\\"]+\" /var/www/shreehariglobal.in/public_html/config/config.php | head -1)
      mysql -h \"\${DB_HOST:-localhost}\" -u \"\${DB_USER}\" -p\"\${DB_PASS}\" \"\${DB_NAME}\" < /tmp/shg-migrate.sql 2>&1
      RC=\$?
      rm -f /tmp/shg-migrate.sql
      if [ \$RC -eq 0 ]; then
        echo \"✅  Migration applied successfully.\"
      else
        echo \"❌  Migration failed (exit \$RC) — check output above.\"
        exit 1
      fi
    '"
    ;;
esac

# ── Post-deploy: verify site is up ───────────────────────────
echo ""
echo "🔍  Verifying site..."
HTTP=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "${SITE_URL}/" 2>/dev/null || echo "ERR")
if [[ "$HTTP" == "200" ]]; then
  echo "✅  Site UP: ${SITE_URL} → HTTP ${HTTP}"
else
  echo "⚠️   Site returned HTTP ${HTTP} — check server logs!"
fi

# ── Log the deploy ────────────────────────────────────────────
mkdir -p "$(dirname "$LOG")"
echo "${TIMESTAMP} | mode=${MODE} | http=${HTTP} | branch=$(git -C "$SCRIPT_DIR" branch --show-current 2>/dev/null || echo '?')" >> "$LOG"

echo ""
echo "═══════════════════════════════════════════════════════"
echo "  Deploy log: logs/deploy.log"
echo "  Branch:     $(git -C "$SCRIPT_DIR" branch --show-current 2>/dev/null || echo '?')"
echo "  Remote:     ${VPS_USER}@${VPS_HOST}:${VPS_PATH}"
echo "═══════════════════════════════════════════════════════"
echo ""
