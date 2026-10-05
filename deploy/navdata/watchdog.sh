#!/usr/bin/env bash
# SHARI NAV build guard: the live website always comes first.
#
#   systemd-run --unit=shg-nav-watchdog --collect bash /srv/shg-nav/bin/watchdog.sh
#
# Every 30 s: time the home page (through nginx on this box) and read
# MemAvailable + free disk. Two bad checks in a row (home > 2 s, not 200,
# < 700 MB free RAM, or < 10 GB free disk - a full disk would stop
# MariaDB) -> FREEZE the build (systemd unit shg-nav-build and the docker
# container(s) named in $CTRS, i.e. docker pause). Three good checks -> thaw.
# A frozen build keeps its memory but uses no CPU or disk.
# Log: /srv/shg-nav/log/watchdog.log (one line per check).
set -u
LOG=/srv/shg-nav/log/watchdog.log
UNIT=${UNIT:-shg-nav-build}
CTRS=${CTRS:-shg-valhalla-full}
bad=0 good=0 paused=0

freeze() {
  systemctl is-active -q "$UNIT" && systemctl freeze "$UNIT" 2>/dev/null
  for c in $CTRS; do docker inspect -f '{{.State.Running}}' "$c" 2>/dev/null | grep -q true && docker pause "$c" >/dev/null 2>&1; done
}
thaw() {
  systemctl thaw "$UNIT" 2>/dev/null
  for c in $CTRS; do docker unpause "$c" >/dev/null 2>&1; done
}
trap 'thaw; exit 0' INT TERM

while :; do
  r=$(curl -so /dev/null -w '%{http_code} %{time_total}' --max-time 10 \
        --resolve www.shreehariglobal.in:443:127.0.0.1 https://www.shreehariglobal.in/ || echo "000 10")
  code=${r% *} t=${r#* }
  avail=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
  swap=$(awk '/SwapTotal/ {t=$2} /SwapFree/ {f=$2} END {print int((t-f)/1024)}' /proc/meminfo)
  load=$(cut -d' ' -f1 /proc/loadavg)
  disk=$(df --output=avail -BG / | tail -1 | tr -dc '0-9')
  if [ "$code" != 200 ] || awk -v t="$t" 'BEGIN{exit !(t > 2.0)}' || [ "$avail" -lt 700 ] || [ "$disk" -lt 10 ]; then
    bad=$((bad + 1)) good=0
  else
    good=$((good + 1)) bad=0
  fi
  state=run
  if [ $paused = 0 ] && [ $bad -ge 2 ]; then freeze; paused=1; state=FROZE
  elif [ $paused = 1 ] && [ $good -ge 3 ]; then thaw; paused=0; state=THAWED
  elif [ $paused = 1 ]; then state=frozen; fi
  echo "$(date '+%F %T') home=$code ${t}s avail=${avail}MB swap=${swap}MB disk=${disk}G load=$load $state" >> "$LOG"
  sleep 30
done
