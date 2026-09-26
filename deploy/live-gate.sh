#!/usr/bin/env bash
# =====================================================================
#  deploy/live-gate.sh — may these server-only commits go to GitHub?
#
#  The repository is PUBLIC. Before commits that exist only on the VPS
#  are pushed, every path they touched (merge commits included), every
#  new blob's path and every new blob's content is checked for what must
#  never be published:
#    - config/*.php (except config.sample.php), .env files, .deploy.env,
#      SHG-ADMIN-CREDENTIALS, .htpasswd, ssh keys, certificates
#    - uploads/, tickets/, invoice/, backup/, logs/, tmp/ … at any depth
#    - database dumps, archives and compressed files (by name AND by
#      their first bytes), log files
#    - secret-looking strings: Twilio, Anthropic, OpenAI, Google, Meta,
#      GitHub, AWS, Slack, Razorpay/Stripe keys, private keys, bcrypt
#      hashes, define()/dotenv/array passwords and tokens, secrets set in
#      the settings table, customer rows in INSERT statements
#  A hit prints the rule, a MASKED path and line, and never the value.
#  Anything already on GitHub is not re-checked: it is public already.
#
#      bash deploy/live-gate.sh <commit>        # exit 0 = PASS, 1 = BLOCK
# =====================================================================
set -uo pipefail
shopt -s nocasematch

LIVE="${1:?usage: live-gate.sh <commit>}"
git rev-parse -q --verify "$LIVE^{commit}" >/dev/null || { echo "GATE=BLOCK (no such commit)"; exit 1; }

NOT=(--not --remotes=origin --tags)
MAX_BLOB=52428800            # GitHub refuses 100 MB; anything near it is not code
block=0
note() { echo "  BLOCK  $*"; block=1; }

# The log is public: show the first two folders and the extension, not
# the file name (an upload's name can carry a customer's name or phone).
mask() {
  local p="$1" dir base ext
  p="${p//[[:cntrl:]]/?}"
  dir="$(printf '%s' "$p" | awk -F/ 'NF>2 {print $1"/"$2"/"; next} NF==2 {print $1"/"}')"
  base="${p##*/}"
  ext=""; [[ "$base" == *.* ]] && ext=".${base##*.}"
  printf '%s…%s' "$dir" "${ext:0:12}"
}

count="$(git rev-list --count "$LIVE" "${NOT[@]}")"
echo "=== Commits that exist only on the server: $count"

# ---------------------------------------------------------------- 1. paths
check_path() {
  local p="$1"
  if [[ "$p" =~ [[:cntrl:]] || "$p" == *'"'* || "$p" == *'\'* ]]; then
    note "unusual path name (control character, quote or backslash): $(mask "$p")"; return
  fi
  if [[ "$p" =~ (^|/)uploads/ && ! "$p" =~ /(\.htaccess|index\.html)$ ]]; then
    note "runtime upload: $(mask "$p")"
  fi
  if [[ "$p" =~ (^|/)(tickets|invoice|backup|backups|logs|tmp|output|cache|\.claude)/ \
        && ! "$p" =~ /(\.gitkeep|\.htaccess|index\.html)$ ]]; then
    note "runtime / private folder: $(mask "$p")"
  fi
  if [[ "$p" =~ ^qr/ && ! "$p" =~ ^qr/(\.htaccess|index\.php)$ ]]; then
    note "generated QR: $(mask "$p")"
  fi
  if [[ "$p" =~ (^|/)config/[^/]+\.php$ && ! "$p" =~ (^|/)config/config\.sample\.php$ ]]; then
    note "config file: $p"
  fi
  if [[ "$p" =~ (^|/)\.?[^/]*\.env([.-][^/]*)?$ && ! "$p" =~ \.(example|sample)$ ]]; then
    note "env file: $(mask "$p")"
  fi
  [[ "$p" =~ (^|/)SHG-ADMIN-CREDENTIALS ]]                      && note "credentials file: $(mask "$p")"
  [[ "$p" =~ (^|/)\.htpasswd$ ]]                                && note "htpasswd: $(mask "$p")"
  [[ "$p" =~ (^|/)id_(rsa|dsa|ecdsa|ed25519)(\.pub)?$ ]]        && note "ssh key: $(mask "$p")"
  [[ "$p" =~ (^|/)(credentials|service-account)[^/]*\.json$ ]]  && note "credentials json: $(mask "$p")"
  [[ "$p" =~ \.(pem|key|p12|pfx|jks|keystore|ppk|kdbx)$ ]]      && note "key / certificate: $(mask "$p")"
  [[ "$p" =~ \.(sql\.[a-z0-9]+|dump|tar(\.[a-z0-9]+)?|tgz|tbz2?|txz|zip|7z|rar|gz|xz|bz2|zst|bak)$ ]] \
                                                                && note "dump / archive / backup: $(mask "$p")"
  [[ "$p" =~ \.log(\.[0-9]+)?$ ]]                               && note "log file: $(mask "$p")"
  if [[ "$p" =~ \.sql$ && ! "$p" =~ ^database/(schema|seed)\.sql$ && ! "$p" =~ ^database/upgrade-[^/]+\.sql$ ]]; then
    note "sql outside the migrations: $(mask "$p")"
  fi
  return 0
}

# Every path any new commit touched, NUL-separated (no quoting), with
# merge commits' own changes included: an added-then-deleted secret is
# still in the history that would be pushed.
echo "=== Paths touched by those commits"
npaths=0
while IFS= read -r -d '' p; do
  [ -n "$p" ] || continue
  npaths=$((npaths + 1))
  check_path "$p"
done < <(git -c core.quotePath=false log -z --diff-merges=combined --format= --name-only --no-renames \
           "$LIVE" "${NOT[@]}" | sort -zu)
echo "  $npaths distinct path(s)"

# ---------------------------------------------------------------- 2. blobs
echo "=== New blobs"
blobs="$(git -c core.quotePath=false rev-list --objects "$LIVE" "${NOT[@]}" \
  | git cat-file --batch-check='%(objecttype) %(objectname) %(objectsize) %(rest)' \
  | awk '$1=="blob"')"
nblobs="$(printf '%s' "$blobs" | grep -c . || true)"
bytes="$(printf '%s\n' "$blobs" | awk '{s+=$3} END {print s+0}')"
echo "  $nblobs blob(s), $((bytes / 1024)) KiB"

magic() { git cat-file blob "$1" | head -c 8 | od -An -tx1 | tr -d ' \n'; }
while read -r _ sha size path; do
  [ -n "${sha:-}" ] || continue
  # The path a blob was first found under catches anything the log above
  # could not name.
  [ -n "${path:-}" ] && check_path "$path"
  if [ "$size" -gt "$MAX_BLOB" ]; then note "blob over 50 MB: $(mask "${path:-?}") ($((size / 1048576)) MB)"; fi
  if [[ "${path:-}" =~ \.sql$ && "$size" -gt 2097152 ]]; then note "sql file over 2 MB looks like a dump: $(mask "$path")"; fi
  case "$(magic "$sha")" in
    1f8b*|504b0304*|fd377a585a00*|425a683[1-9]*|377abcaf271c*|526172211a07*|28b52ffd*)
      note "compressed / archive content (zip, gzip, xz, bzip2, 7z, rar, zstd; docx/xlsx are zip too): $(mask "${path:-?}")" ;;
  esac
done <<< "$blobs"

# ---------------------------------------------------------------- 3. content
# Token patterns: the whole match IS the secret, so only its first four
# characters and its length are shown.
TOKENS=(
  'twilio_sid|AC[0-9a-f]{32}'
  'twilio_api_key|SK[0-9a-f]{32}'
  'anthropic_key|sk-ant-[A-Za-z0-9_-]{20,}'
  'openai_key|sk-(proj-|svcacct-|admin-)?[A-Za-z0-9_-]{32,}'
  'google_key|AIza[0-9A-Za-z_-]{35}'
  'private_key|-----BEGIN [A-Z ]*PRIVATE KEY-----'
  'github_token|(gh[pousr]_[A-Za-z0-9]{36}|github_pat_[A-Za-z0-9_]{50,})'
  'meta_token|EAA[A-Za-z0-9]{80,}'
  'aws_key|AKIA[0-9A-Z]{16}'
  'slack_token|xox[baprs]-[A-Za-z0-9-]{10,}'
  'payment_live_key|((sk|rk)_live_[A-Za-z0-9]{16,}|rzp_live_[A-Za-z0-9]{10,})'
  'bcrypt_hash|\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}'
  'sql_dump|^(-- (MySQL|MariaDB) dump|-- Dump completed|LOCK TABLES|/\*!40[0-9]{3} SET)'
)
# Assignment patterns: a key and a value. The VALUE alone decides whether
# it is a placeholder; only the key name and the value's length are shown.
SECRET_NAME='[A-Z0-9_]*(PASS|PASSWORD|SECRET|AUTH_TOKEN|ACCESS_TOKEN|API_KEY|APP_KEY|CRON_TOKEN|WEBHOOK_TOKEN)[A-Z0-9_]*'
DEFINE_RE="define\\(\\s*['\"]${SECRET_NAME}['\"]\\s*,.*['\"][^'\"]+['\"]"
DOTENV_RE="^\\s*(export\\s+)?[A-Z][A-Z0-9_]*(PASS|PASSWORD|SECRET|TOKEN|API_KEY|APP_KEY)[A-Z0-9_]*\\s*=\\s*['\"]?[^[:space:]'\"#\$]{8,}"
PASSWORD_RE="['\"]?(pass(word)?|passwd|pwd|secret|auth_?token|api_?key)['\"]?\\s*(=>|=|:)\\s*['\"][^'\"[:space:]\$]{8,}['\"]"
SETTINGS_RE="(\\(\\s*'[a-z0-9_]*(token|secret|pass(word)?|api_?key|app_key|auth|sid)[a-z0-9_]*'\\s*,\\s*'[^']{8,}'|svalue\\s*=\\s*'[^']{8,}'[^;]*skey\\s*=\\s*'[a-z0-9_]*(token|secret|pass|key|sid|auth)[a-z0-9_]*')"
PII_RE="INSERT\\s+(IGNORE\\s+)?INTO\\s+\`?(users|bookings|passengers|payments|agents|wa_messages|wa_[a-z_]*log[a-z_]*)\`?"
PLACEHOLDER='(change[_-]?me[a-z0-9_-]*|your[_-][a-z0-9_-]*|x{4,}|\*{3,}|<[^>]*>|\{\{[^}]*\}\}|(example|sample|placeholder|dummy)[a-z0-9_-]*)'

is_placeholder() {   # $1 = the value only
  local v="$1"
  [ -z "$v" ] && return 0
  printf '%s' "$v" | grep -Eqix -- "$PLACEHOLDER"
}
cs_match() {         # case-SENSITIVE [[ =~ ]] ($1 value, $2 regex)
  local r
  shopt -u nocasematch; [[ "$1" =~ $2 ]]; r=$?; shopt -s nocasematch
  return $r
}
last_literal() {     # the last quoted literal on the line, quotes stripped
  printf '%s' "$1" | grep -oE "'[^']*'|\"[^\"]*\"" | tail -1 | sed -E "s/^['\"]//; s/['\"]$//"
}

# A line that is already, word for word, in the same file on GitHub adds
# nothing by being pushed again (a changed file is scanned whole).
mapfile -t BOUNDARY < <(git rev-list --boundary "$LIVE" "${NOT[@]}" | sed -n 's/^-//p')
hit() {   # hit <rule> <line number> <BLOCK message>
  local rule="$1" ln="$2" msg="$3" line b
  line="$(sed -n "${ln}p" "$tmp")"; line="${line%$'\r'}"
  if [ -n "${line//[[:space:]]/}" ]; then
    for b in "${BOUNDARY[@]}"; do
      if git grep -qF -e "$line" "$b" -- ":(literal)${path:-}" 2>/dev/null; then
        echo "  note   $rule $(where "$ln") (the same line is already public on GitHub)"; return 0
      fi
    done
  fi
  note "$msg"; hits=$((hits + 1))
}

echo "=== Secret patterns in new blobs"
TOKEN_ANY="$(for s in "${TOKENS[@]}"; do printf '%s|' "(${s#*|})"; done)"
TOKEN_ANY="${TOKEN_ANY%|}"
hits=0
tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
while read -r _ sha size path; do
  [ -n "${sha:-}" ] || continue
  [ "$size" -le "$MAX_BLOB" ] || continue     # already blocked above
  git cat-file blob "$sha" > "$tmp"
  where() { printf '%s:%s' "$(mask "${path:-?}")" "$1"; }

  if grep -aEq -- "$TOKEN_ANY" "$tmp"; then
    for spec in "${TOKENS[@]}"; do
      name="${spec%%|*}"; re="${spec#*|}"
      while IFS=: read -r ln match; do
        [ -n "${ln:-}" ] || continue
        hit "$name" "$ln" "$name $(where "$ln")  starts '${match:0:4}…' (${#match} chars)  blob ${sha:0:12}"
      done < <(grep -aEon -- "$re" "$tmp" | head -5)
    done
  fi

  # define('DB_PASS', '…') and getenv('X') ?: '…' fallbacks: case-sensitive
  # names, the last quoted literal is the value.
  while IFS=: read -r ln line; do
    [ -n "${ln:-}" ] || continue
    key="$(printf '%s' "$line" | grep -oE "define\\(\\s*['\"]${SECRET_NAME}['\"]" | grep -oE "$SECRET_NAME" | head -1)"
    [[ "$key" =~ (_NAME|SESSION_KEY)$ ]] && continue
    val="$(last_literal "$line")"
    cs_match "$val" '^[A-Z][A-Z0-9_]*$' && continue        # an env variable name
    cs_match "$val" '^[a-z][a-z0-9]*(_[a-z0-9]+)+$' && continue   # a settings key it reads
    is_placeholder "$val" && { echo "  note   define $key $(where "$ln") (placeholder)"; continue; }
    hit define "$ln" "define $(where "$ln")  key=$key  value ${#val} chars  blob ${sha:0:12}"
  done < <(grep -aEon -- "$DEFINE_RE" "$tmp" | head -5)

  # KEY=value lines in anything that is not PHP / JS / SQL (.env copies,
  # shell snippets, notes).
  if [[ ! "${path:-}" =~ \.(php|js|mjs|sql)$ ]]; then
    while IFS=: read -r ln line; do
      [ -n "${ln:-}" ] || continue
      key="$(printf '%s' "$line" | sed -E 's/^\s*(export\s+)?//; s/\s*=.*//')"
      val="$(printf '%s' "$line" | sed -E "s/^[^=]*=\\s*//; s/^['\"]//; s/['\"].*$//; s/\\s.*$//")"
      is_placeholder "$val" && { echo "  note   dotenv ${key:0:40} $(where "$ln") (placeholder)"; continue; }
      hit dotenv "$ln" "dotenv $(where "$ln")  key=${key:0:40}  value ${#val} chars  blob ${sha:0:12}"
    done < <(grep -aEon -- "$DOTENV_RE" "$tmp" | head -5)
  fi

  # 'password' => '…', pass: "…", api_key = '…' (any case).
  while IFS=: read -r ln match; do
    [ -n "${ln:-}" ] || continue
    val="$(last_literal "$match")"
    key="$(printf '%s' "$match" | grep -oiE "(pass(word)?|passwd|pwd|secret|auth_?token|api_?key)" | head -1)"
    is_placeholder "$val" && { echo "  note   password $(where "$ln") (placeholder)"; continue; }
    hit password "$ln" "password $(where "$ln")  key=$key  value ${#val} chars  blob ${sha:0:12}"
  done < <(grep -aEion -- "$PASSWORD_RE" "$tmp" | head -5)

  # Secrets written into the settings table by SQL.
  while IFS=: read -r ln match; do
    [ -n "${ln:-}" ] || continue
    mapfile -t lits < <(printf '%s' "$match" | grep -oE "'[^']*'" | tr -d "'")
    if [[ "$match" == \(* ]]; then key="${lits[0]:-}"; val="${lits[1]:-}"   # ('key', 'value' …
    else val="${lits[0]:-}"; key="${lits[${#lits[@]}-1]:-}"; fi              # svalue='…' … skey='key'
    cs_match "$key" '^[a-z0-9_]+$' || key="?"
    { cs_match "$val" '^[a-z][a-z0-9_]*$' || [[ "$val" =~ [[:space:]] ]]; } && continue   # a type / label, not a secret
    is_placeholder "$val" && { echo "  note   settings $(where "$ln") (placeholder)"; continue; }
    hit settings "$ln" "settings secret $(where "$ln")  key=${key:0:40}  value ${#val} chars  blob ${sha:0:12}"
  done < <(grep -aEion -- "$SETTINGS_RE" "$tmp" | head -5)

  # Customer rows: an INSERT into a customer table that carries a phone
  # number, an e-mail address or a password hash.
  while IFS=: read -r ln _; do
    [ -n "${ln:-}" ] || continue
    hit customer_data "$ln" "customer data (INSERT with phone / e-mail / password hash) $(where "$ln")  blob ${sha:0:12}"
  done < <(grep -aEin -- "$PII_RE" "$tmp" \
             | grep -aE "'(\\+?91)?[6-9][0-9]{9}'|'[^' @]+@[^' @]+\\.[a-z]{2,}'|\\\$2[aby]\\\$[0-9]{2}\\\$" | head -3)
done <<< "$blobs"
echo "  $hits hit(s)"

if [ "$block" -eq 0 ]; then
  echo "GATE=PASS"
  exit 0
fi
echo "GATE=BLOCK — nothing may be pushed until the lines above are dealt with"
exit 1
