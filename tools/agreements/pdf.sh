#!/bin/bash
# pdf.sh — print every agreements/*-agreement-*.html to out/pdf/<name>-Colour.pdf and <name>-BW.pdf
# with headless Chrome, check the PDFs with check-pdf.py (4..5 pages, no font below 7.5 pt, nothing
# outside the margins, the signature page in one piece), then check the phone layout with
# phone-check.html (no horizontal scroll at 360 / 390 / 414 / 768 px, 16 px inputs).
# Exit code 1 when a check fails.
#
#   bash tools/agreements/pdf.sh                     # the site folder (after node build.js)
#   SITE=/other/dir bash tools/agreements/pdf.sh     # a preview build (node build.js --out=/other/dir)
#   JOBS=4 parallel Chrome processes;  CHROME=/path/to/chrome;  PNG=1 also renders out/png/*.png;
#   SKIP_PDF=1 runs only the phone check
cd "$(dirname "$0")" || exit 1
SITE="${SITE:-$PWD/../../agreements}"
CH="${CHROME:-/c/Program Files/Google/Chrome/Application/chrome.exe}"
OUTD="$PWD/out/pdf"
JOBS="${JOBS:-4}"
winpath() { if command -v cygpath >/dev/null 2>&1; then cygpath -m "$1"; else printf '%s' "$1"; fi; }
status=0

one() { # $1 = page name without .html, $2 = colour | bw
  local b="$1" t="$2" T PROF
  T=$([ "$t" = colour ] && echo Colour || echo BW)
  PROF=$(mktemp -d)
  "$CH" --headless=new --disable-gpu --no-first-run --no-default-browser-check --no-pdf-header-footer \
    --user-data-dir="$(winpath "$PROF")" --virtual-time-budget=15000 --run-all-compositor-stages-before-draw \
    --print-to-pdf="$(winpath "$OUTD/$b-$T.pdf")" "file:///$(winpath "$SITE")/$b.html?blank=1#$t" >/dev/null 2>&1
  rm -rf "$PROF"
  printf "%-36s %s\n" "$b-$T.pdf" "$(stat -c %s "$OUTD/$b-$T.pdf" 2>/dev/null || echo MISSING)"
}

if [ -z "$SKIP_PDF" ]; then
  mkdir -p "$OUTD"
  rm -f "$OUTD"/*.pdf
  n=0
  for f in "$SITE"/*-agreement-*.html; do
    b=$(basename "$f" .html)
    for t in colour bw; do
      one "$b" "$t" &
      n=$((n + 1))
      if [ $((n % JOBS)) -eq 0 ]; then wait; fi
    done
  done
  wait
  PY=$(command -v python || command -v python3)
  if [ -n "$PY" ]; then
    "$PY" -W ignore check-pdf.py ${PNG:+--png} "$OUTD"/*.pdf || status=1
  else
    echo "python not found — PDFs not checked"
  fi
fi

# Phone widths: every page at 360 / 390 / 414 / 768 px must have no horizontal scroll and 16 px inputs.
PROF=$(mktemp -d)
PHONE=$("$CH" --headless=new --disable-gpu --no-first-run --no-default-browser-check --allow-file-access-from-files \
  --user-data-dir="$(winpath "$PROF")" --virtual-time-budget=20000 \
  --dump-dom "file:///$(winpath "$PWD")/phone-check.html?site=file:///$(winpath "$SITE")/" 2>/dev/null \
  | sed -n '/<pre id="out">/,/<\/pre>/p' | sed 's/<[^>]*>//g')
rm -rf "$PROF"
echo "$PHONE"
if ! printf '%s' "$PHONE" | grep -q '^DONE'; then echo "phone check did not finish"; status=1; fi
if printf '%s' "$PHONE" | grep -q 'OVERFLOW\| SMALL'; then status=1; fi
exit $status
