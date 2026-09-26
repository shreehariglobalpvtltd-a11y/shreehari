#!/usr/bin/env bash
# =====================================================================
#  deploy/live-gate.sh — may these server-only commits go to GitHub?
#
#  The repository is PUBLIC. Before commits that exist only on the VPS
#  are pushed, every path they ever touched and every new blob they carry
#  is checked for the things that must never be published:
#    - config/config.php, .env files, .deploy.env, SHG-ADMIN-CREDENTIALS.txt
#    - uploads/ (except .htaccess / index.html), tickets/, invoice/, backup/
#    - private keys, certificates, database dumps, archives
#    - secret-looking strings (Twilio, Anthropic, OpenAI, Google, Meta,
#      GitHub, AWS, Razorpay/Stripe, DB_PASS / APP_KEY, passwords)
#  A hit prints the path, the rule and a MASKED hint, never the value, and
#  the gate says BLOCK. Anything already on GitHub is not re-checked: it is
#  public already.
#
#      bash deploy/live-gate.sh <commit>        # exit 0 = PASS, 1 = BLOCK
# =====================================================================
set -uo pipefail

LIVE="${1:?usage: live-gate.sh <commit>}"
git rev-parse -q --verify "$LIVE^{commit}" >/dev/null || { echo "GATE=BLOCK (no such commit: $LIVE)"; exit 1; }

NOT=(--not --remotes=origin --tags)
block=0
note() { echo "  BLOCK  $*"; block=1; }

count="$(git rev-list --count "$LIVE" "${NOT[@]}")"
echo "=== Commits that exist only on the server: $count"

# ---------------------------------------------------------------- 1. paths
# Every path any new commit touched: an added-then-deleted secret is still
# in the history that would be pushed.
echo "=== Paths touched by those commits"
paths="$(git log --format= --name-only --no-renames "$LIVE" "${NOT[@]}" | grep . | sort -u)"
echo "  $(printf '%s' "$paths" | grep -c . || true) distinct path(s)"
while IFS= read -r p; do
  [ -n "$p" ] || continue
  case "$p" in
    uploads/*|*/uploads/*)
      [[ "$p" =~ (^|/)(\.htaccess|index\.html)$ ]] || note "runtime upload: $p" ;;
    tickets/*|invoice/*|backup/*|backups/*|logs/*|tmp/*|output/*|.claude/*)
      note "runtime / private folder: $p" ;;
    qr/*)
      [[ "$p" == qr/.htaccess || "$p" == qr/index.php ]] || note "generated QR: $p" ;;
  esac
  [[ "$p" =~ (^|/)config/config(\.local)?\.php$ ]]                  && note "live config: $p"
  [[ "$p" =~ (^|/)\.env(\.[^/]*)?$ && ! "$p" =~ \.example$ ]]       && note "env file: $p"
  [[ "$p" =~ (^|/)\.deploy\.env$ ]]                                 && note "deploy env: $p"
  [[ "$p" =~ (^|/)SHG-ADMIN-CREDENTIALS ]]                          && note "credentials file: $p"
  [[ "$p" =~ (^|/)\.htpasswd$ ]]                                    && note "htpasswd: $p"
  [[ "$p" =~ (^|/)id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$ ]]            && note "ssh key: $p"
  [[ "$p" =~ (^|/)(credentials|service-account)[^/]*\.json$ ]]      && note "credentials json: $p"
  [[ "$p" =~ \.(pem|key|p12|pfx|jks|keystore|ppk|kdbx)$ ]]          && note "key / certificate: $p"
  [[ "$p" =~ \.(sql\.gz|sql\.zip|dump|tar|tar\.gz|tgz|zip|7z|rar|bak)$ ]] && note "dump / archive / backup: $p"
  if [[ "$p" =~ \.sql$ ]]; then
    [[ "$p" =~ ^database/(schema|seed)\.sql$ || "$p" =~ ^database/upgrade-[^/]+\.sql$ ]] || note "sql outside the migrations: $p"
  fi
done <<< "$paths"

# ---------------------------------------------------------------- 2. blobs
echo "=== New blobs"
blobs="$(git rev-list --objects "$LIVE" "${NOT[@]}" \
  | git cat-file --batch-check='%(objecttype) %(objectname) %(objectsize) %(rest)' \
  | awk '$1=="blob"')"
nblobs="$(printf '%s' "$blobs" | grep -c . || true)"
bytes="$(printf '%s\n' "$blobs" | awk '{s+=$3} END {print s+0}')"
echo "  $nblobs blob(s), $((bytes / 1024)) KiB"
while read -r _ sha size path; do
  [ -n "${sha:-}" ] || continue
  if [ "$size" -gt 52428800 ]; then note "blob over 50 MB (GitHub refuses 100 MB): $path ($((size / 1048576)) MB)"; fi
  if [[ "${path:-}" =~ \.sql$ && "$size" -gt 2097152 ]]; then note "sql file over 2 MB looks like a dump: $path"; fi
done <<< "$blobs"

# ---------------------------------------------------------------- 3. content
# The pattern name, the path, the line number and a masked hint only.
echo "=== Secret patterns in new blobs"
PATS=(
  'twilio_sid|AC[0-9a-f]{32}'
  'twilio_api_key|SK[0-9a-f]{32}'
  'anthropic_key|sk-ant-[A-Za-z0-9_-]{20,}'
  'openai_key|sk-(proj-)?[A-Za-z0-9]{32,}'
  'google_key|AIza[0-9A-Za-z_-]{35}'
  'private_key|-----BEGIN [A-Z ]*PRIVATE KEY-----'
  'github_token|(gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{50,})'
  'meta_token|EAA[A-Za-z0-9]{80,}'
  'aws_key|AKIA[0-9A-Z]{16}'
  'slack_token|xox[baprs]-[A-Za-z0-9-]{10,}'
  'payment_live_key|((sk|rk)_live_[A-Za-z0-9]{16,}|rzp_live_[A-Za-z0-9]{10,})'
  "db_pass|define\\(\\s*['\"]DB_PASS['\"]\\s*,\\s*['\"][^'\"<]{4,}['\"]"
  "app_key|define\\(\\s*['\"]APP_KEY['\"]\\s*,\\s*['\"][A-Za-z0-9+/=:_-]{16,}['\"]"
  "password|['\"]?(pass(word)?|passwd|pwd|secret|auth_?token|api_?key)['\"]?\\s*(=>|=|:)\\s*['\"][^'\"[:space:]\$]{8,}['\"]"
)
PLACEHOLDER='(change|example|sample|placeholder|dummy|test|xxxx|your[_-]|\*\*\*|<|\{\{|getenv|env\()'
hits=0
while read -r _ sha size path; do
  [ -n "${sha:-}" ] || continue
  [ "$size" -le 5242880 ] || continue
  body="$(git cat-file -p "$sha" | tr -d '\000')"
  for spec in "${PATS[@]}"; do
    name="${spec%%|*}"; re="${spec#*|}"
    while IFS=: read -r ln match; do
      [ -n "${ln:-}" ] || continue
      if [ "$name" = password ] || [ "$name" = db_pass ] || [ "$name" = app_key ]; then
        # Values that are plainly placeholders or read from the environment
        # are not secrets. Say so, and do not block on them.
        if printf '%s' "$match" | grep -Eqi "$PLACEHOLDER"; then
          echo "  note   $name $path:$ln (placeholder or env lookup)"; continue
        fi
      fi
      # Only the assignment patterns have a key name worth showing; for the
      # token patterns the whole match IS the secret, so just its first 4.
      key=""
      case "$name" in password|db_pass|app_key)
        key="$(printf '%s' "$match" | grep -Eo "^[^=:>,]{0,40}" | head -1 | tr -d "\t '\"")" ;;
      esac
      note "$name $path:$ln  starts '${match:0:4}…' (${#match} chars)${key:+ key=${key:0:30}}  blob ${sha:0:12}"
      hits=$((hits + 1))
    done < <(printf '%s' "$body" | grep -Eon -- "$re" | head -5)
  done
done <<< "$blobs"
echo "  $hits hit(s)"

if [ "$block" -eq 0 ]; then
  echo "GATE=PASS"
  exit 0
fi
echo "GATE=BLOCK — nothing may be pushed until the lines above are dealt with"
exit 1
