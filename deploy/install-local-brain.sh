#!/usr/bin/env bash
# =====================================================================
#  install-local-brain.sh — SHG Sahayak's brain, on our own server.
#
#  Owner, 25 Sep 2026: "chatbot lai fully offline ni jati sakdo dherai
#  kaam garna sakne … VPS ma 8 GB RAM cha, tesma chalne khalko euta
#  model jasto bandiye … aafai independently".
#
#  What this installs
#  ------------------
#    /opt/shg-brain/bin      llama.cpp, the prebuilt CPU build (no
#                            compiler is installed on the live box)
#    /opt/shg-brain/models   Qwen3-4B-Instruct-2507, Q4_K_M, ~2.5 GB
#    shg-brain.service       systemd unit on 127.0.0.1:8081
#
#  Why this model
#  --------------
#  Measured on the live VPS (2 vCPU AMD EPYC 9354P Zen 4, 8 GB, AVX-512)
#  on 25-26 Sep 2026, with the f16 KV cache this unit sets:
#    prompt intake   22.2 tokens/sec
#    generation      4.2 tokens/sec at a 2600-token context
#    resident        ~3.4 GB at 8192 context
#    Nepali in Devanagari   good, grounded, right register
#    Nepali in roman script poor - AiAgent tells it to use Devanagari
#    tool calling     works (verified against a booking_lookup fixture)
#
#  Qwen3-1.7B was measured head to head and REJECTED, which is worth
#  recording so nobody repeats it: it is a hybrid reasoning model, so it
#  spends its tokens thinking before it answers. Every call burned the
#  whole reply budget on the think block and returned nothing usable -
#  two real turns, both null - even though it was genuinely faster
#  (60.5 tok/s prompt, 8.4 tok/s generation). Speed is no use if the
#  answer never arrives. If a smaller model is tried again it must be a
#  NON-thinking instruct build.
#
#  Why it cannot hurt the website
#  ------------------------------
#  The unit runs at CPUWeight=20 and Nice=10 while nginx, php-fpm and
#  mariadb sit at the default 100, so the site wins every contest for
#  the two cores. MemoryMax caps it at 5 GB, so a runaway model is
#  killed instead of the database. It binds to loopback and
#  IPAddressDeny=any, so it is not reachable from outside this machine.
#
#  Safe to re-run. It skips a download that is already complete.
#
#  Usage:  sudo bash deploy/install-local-brain.sh
# =====================================================================
set -euo pipefail

LLAMA_BUILD="${LLAMA_BUILD:-b11184}"
ROOT=/opt/shg-brain
MODEL_FILE="$ROOT/models/qwen3-4b-instruct-q4_k_m.gguf"
MODEL_URL="https://huggingface.co/unsloth/Qwen3-4B-Instruct-2507-GGUF/resolve/main/Qwen3-4B-Instruct-2507-Q4_K_M.gguf"
MODEL_BYTES=2497281120
UNIT=/etc/systemd/system/shg-brain.service

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31mFAILED: %s\033[0m\n' "$*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "run as root"

say "0/5  Checking this machine"
free -m | awk 'NR==2 {printf "     RAM: %s MB total, %s MB available\n", $2, $7}'
AVAIL_MB=$(free -m | awk 'NR==2 {print $7}')
[ "$AVAIL_MB" -ge 3500 ] || die "needs ~3.5 GB free RAM, found ${AVAIL_MB} MB"
DISK_GB=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
[ "$DISK_GB" -ge 6 ] || die "needs ~6 GB free disk, found ${DISK_GB} GB"
grep -qm1 avx2 /proc/cpuinfo || die "this CPU has no AVX2; the prebuilt binary will not run well"

say "1/5  Runtime library (libgomp1 — llama.cpp is built with OpenMP)"
if ! ldconfig -p | grep -q libgomp.so.1; then
  DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends libgomp1
else
  echo "     already present"
fi

say "2/5  llama.cpp $LLAMA_BUILD (prebuilt CPU build)"
mkdir -p "$ROOT/bin" "$ROOT/models" "$ROOT/log"
if [ ! -x "$ROOT/bin/llama-server" ]; then
  TAR=/tmp/llama-$LLAMA_BUILD.tar.gz
  curl -fsSL --max-time 600 -o "$TAR" \
    "https://github.com/ggml-org/llama.cpp/releases/download/$LLAMA_BUILD/llama-$LLAMA_BUILD-bin-ubuntu-x64.tar.gz"
  rm -rf "/tmp/llama-$LLAMA_BUILD"
  tar -xzf "$TAR" -C /tmp/
  cp -a "/tmp/llama-$LLAMA_BUILD/." "$ROOT/bin/"
  rm -rf "$TAR" "/tmp/llama-$LLAMA_BUILD"
  chmod +x "$ROOT/bin/llama-server"
else
  echo "     already installed"
fi
LD_LIBRARY_PATH="$ROOT/bin" "$ROOT/bin/llama-server" --version 2>&1 | grep -m1 version || die "binary will not run"

say "3/5  Model (Qwen3-4B-Instruct-2507 Q4_K_M, 2.5 GB)"
if [ -f "$MODEL_FILE" ] && [ "$(stat -c%s "$MODEL_FILE")" = "$MODEL_BYTES" ]; then
  echo "     already downloaded and the size matches"
else
  echo "     downloading — this takes a few minutes"
  curl -fL --max-time 3600 -o "$MODEL_FILE.part" "$MODEL_URL"
  GOT=$(stat -c%s "$MODEL_FILE.part")
  [ "$GOT" = "$MODEL_BYTES" ] || die "download is $GOT bytes, expected $MODEL_BYTES"
  mv "$MODEL_FILE.part" "$MODEL_FILE"
fi

say "4/5  systemd unit"
cat > "$UNIT" <<'UNITEOF'
[Unit]
# SHG Sahayak local brain — Qwen3-4B-Instruct-2507 on llama.cpp, CPU only.
# Installed by deploy/install-local-brain.sh; see that file for the why.
# Loopback only. Deliberately the loser in any fight for this box:
# CPUWeight/Nice put nginx, php-fpm and mariadb first, MemoryMax means a
# runaway model is killed instead of the database.
Description=SHG Sahayak local brain (llama.cpp)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
WorkingDirectory=/opt/shg-brain
Environment=LD_LIBRARY_PATH=/opt/shg-brain/bin
# f16 KV, not q8_0: measured on this box, q8_0 costs a dequantisation per
# token and made prompt intake 12.7 tok/s against f16's 22.2 - a 75%
# difference on the number that hurts most here. The extra memory is
# about 600 MB, which this box has.
ExecStart=/opt/shg-brain/bin/llama-server -m /opt/shg-brain/models/qwen3-4b-instruct-q4_k_m.gguf --host 127.0.0.1 --port 8081 -c 8192 -t 2 -np 1 --jinja --cache-type-k f16 --cache-type-v f16 --no-webui
Restart=always
RestartSec=5
Nice=10
CPUWeight=20
IOWeight=20
MemoryMax=5G
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/opt/shg-brain/log
IPAddressAllow=localhost
IPAddressDeny=any

[Install]
WantedBy=multi-user.target
UNITEOF
systemctl daemon-reload
systemctl enable --now shg-brain.service

say "5/5  Waiting for the model to load, then a real question"
for i in $(seq 1 60); do
  if curl -sf --max-time 3 http://127.0.0.1:8081/health >/dev/null 2>&1; then break; fi
  sleep 2
done
curl -sf --max-time 5 http://127.0.0.1:8081/health >/dev/null || die "the server did not come up — see /opt/shg-brain/log or journalctl -u shg-brain"

curl -sS --max-time 120 http://127.0.0.1:8081/v1/chat/completions \
  -H 'content-type: application/json' \
  --data-binary @- <<'JSON' | python3 -c 'import sys,json; d=json.load(sys.stdin); print("     reply:", d["choices"][0]["message"]["content"].strip()[:160]); print("     speed:", round((d.get("timings") or {}).get("predicted_per_second",0),1), "tokens/sec")'
{"model":"local","messages":[{"role":"system","content":"तपाईं S Hari Global को सहायक हुनुहुन्छ। सरल नेपालीमा छोटो जवाफ दिनुहोस्। भाडा रु २०००।"},{"role":"user","content":"सुरतबाट रुपैडिहाको भाडा कति हो?"}],"max_tokens":80,"temperature":0.3}
JSON

cat <<'DONE'

  The brain is installed and running.

  It is NOT wired to the website yet. To turn it on:

    php tests/apply-sql.php database/upgrade-2026-09-25-local-brain.sql
    (then set ai_local_on = 1 in Admin > Settings, or)
    UPDATE settings SET svalue='1' WHERE skey='ai_local_on';

  Useful:
    systemctl status shg-brain
    journalctl -u shg-brain -n 50 --no-pager
    curl -s http://127.0.0.1:8081/health
    systemctl stop shg-brain     # the assistant falls back to its rule engine

DONE
