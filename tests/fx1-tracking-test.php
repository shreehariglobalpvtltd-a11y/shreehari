<?php
/**
 * =====================================================================
 *  fx1-tracking-test.php - live tracking on a weak network (26 Nov 2026).
 *
 *  What this suite pins (slice fx1 "S Hari Navigate / live tracking"):
 *
 *   1. A driver phone whose clock is wrong (driver web app, in the fake
 *      browser): the page measures its clock against the server's (nowMs
 *      in the answers) and sends every fix with its real time - by the
 *      SAME amount on every upload. It never makes a fix newer than it can
 *      be: on a phone with a right clock an old fix (a last-known place
 *      handed over first) goes out with its own old time, and on a phone
 *      with a wrong clock an old fix gets the OLDER of its two readings.
 *      A clock right to 2.5 min, a slow answer, the switch off: times as
 *      measured, as before.
 *   2. The driver door (api/driver.php), in-process with a synthetic clock
 *      AND over HTTP with the real one: times are taken as sent (a phone
 *      10 minutes behind is "position is 10 min old", the same fixes with
 *      their real times are live); me / ping answer nowMs; the switch
 *      driver_clock_fix_on = 0 takes the clock out of the answers and
 *      tells the app; driver_net_timeout_sec reaches the app (0..120).
 *   3. track.php: a wrong key can no longer tell a real PNR from an unknown
 *      one (same 404 page, byte for byte), a Nepal ticket opens in Nepali,
 *      the stale limit track_stale_sec is printed (0 = off, never under
 *      30 s) and api/whereis.php carries it.
 *   4. The pages' own JavaScript, run in Node with a small fake browser:
 *      - track.php: live -> no answer for 60 s -> "last seen ... not live"
 *        (no pulse, no LIVE chip, no GPS tags, no ETA) -> live again; one
 *        request at a time; a hung request is given up after 12 s; our own
 *        404 reloads the page once, a proxy's 404 page does not.
 *      - driver/: a hung upload is given up after the limit and tried again
 *        (before: every later upload waited behind it for ever), the upload
 *        shrinks after failures, timeout 0 = as before; a bus list that
 *        did not load is asked for again by itself.
 *      Skipped with a note when node is not installed.
 *   5. Source pins for the parts that need a real map (SHARI NAV "My bus",
 *      the office Live Map) + node --check of every script touched.
 *
 *  Own rows only: far date 2096-07-14, PNR SHG-FX1T-*, driver phone
 *  9000009926. The three settings it changes are put back exactly.
 *
 *    php -c .claude/php-dev.ini tests/fx1-tracking-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/etaalerts.php';
require_once INCLUDE_PATH . '/livetrips.php';
require_once INCLUDE_PATH . '/driverauth.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/whereis.php';
require_once INCLUDE_PATH . '/routegeo.php';

const FX_DATE  = '2096-07-14';
const FX_TAG   = 'SHG-FX1T-';
const FX_PHONE = '9000009926';
const FX_PORT  = 8972;

$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void { global $SKIP; $SKIP++; echo "  \033[33mSKIP\033[0m  $l\n"; }
function finish(): never {
    global $PASS, $FAIL, $SKIP;
    echo "\n  $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n";
    exit($FAIL === 0 ? 0 : 1);
}

/* ---------------------------------------------------------------------
   5a. Source pins
--------------------------------------------------------------------- */
echo "-- source --\n";
$src = static fn(string $f): string => (string) file_get_contents($ROOT . '/' . $f);
$track = $src('track.php'); $drv = $src('driver/index.php'); $api = $src('api/driver.php');
$nav = $src('assets/nav/nav-live.js'); $map = $src('admin/map.php'); $mig = $src('database/upgrade-2026-11-26-fx1-tracking.sql');

check('track.php reads track_stale_sec and prints it for the page', str_contains($track, "Settings::getInt('track_stale_sec', 60)") && str_contains($track, 'var STALE = <?= (int) $staleSec ?>'));
check('track.php: one request at a time with a time limit', str_contains($track, 'AbortController') && str_contains($track, 'if (document.hidden || busy) return;'));
foreach (['en', 'hi', 'ne'] as $l) {
    $blk = '';
    if (preg_match("/'" . $l . "' => \\[(.*?)'hk' => \\[/s", $track, $m)) { $blk = $m[1]; }
    check("track.php: 'offline', 'lastSeen' and 'istNote' exist in $l", str_contains($blk, "'offline' =>") && str_contains($blk, "'lastSeen' =>") && str_contains($blk, "'istNote' =>"));
}
check('track.php: the language follows the ticket only after the key is checked', str_contains($track, '$lang = $authed && $nepal ? \'ne\' : \'hi\';'));
check('track.php: Nepal time note on the page, not copied into the script', str_contains($track, "\$e(\$T['istNote'])") && str_contains($track, "'mapSub', 'istNote'])"));
check('track.php: the saved "reduced motion" stills the pulses', str_contains($track, "'shg:motion'") && str_contains($track, ':root[data-motion="reduced"] .pulse'));
check('driver app: a stuck request is given up (not sign-in / office link / start)', str_contains($drv, 'var TIMED = { me: 1, trip: 1, ping: 1, event: 1, end: 1 };') && str_contains($drv, 'ctl.abort()'));
check('driver app: the batch shrinks and grows', str_contains($drv, 'S.batch = Math.max(10, Math.floor(S.batch / 2))') && str_contains($drv, 'S.batch = Math.min(200, lim * 2)'));
check('driver app: the clock is measured from the answers; a fix is never re-stamped with its arrival moment just for being old',
    str_contains($drv, 'function clockSeen(serverMs, t0, t1)') && str_contains($drv, 'function fixTime(p, off)') && str_contains($drv, 'if (!off || p.r == null) return p.t;')
    && !str_contains($drv, 'sentAt') && !str_contains($drv, 't = p.r;'));
check('driver app: the floor of the clock fix is under the 5 min a fix stays live, the ceiling is 12 h',
    preg_match('/var CLK_MIN = (\d+), CLK_MAX = (\d+),/', $drv, $m) === 1 && (int) $m[1] === 150000 && (int) $m[1] < EtaAlerts::FIX_MAX_AGE_SEC * 1000 && (int) $m[2] === 43200000);
check('driver app: a bus list that did not load is asked for again (timer + "online")', str_contains($drv, 'meT = setTimeout(meAgain, 8000)') && str_contains($drv, 'else if (meT) { clearTimeout(meT); meAgain(); }'));
check('driver app: the screen lock is asked for again when the system drops it', str_contains($drv, 'if (!S.wake && now % 10000 < 1000) keepAwake();'));
check('api/driver.php reads both switches, answers its clock only while the fix is on, and takes the points as sent',
    str_contains($api, "Settings::getInt('driver_net_timeout_sec', 20)") && str_contains($api, "Settings::getBool('driver_clock_fix_on', true)")
    && str_contains($api, "(\$clockFix ? ['nowMs' => (int) round(microtime(true) * 1000)] : [])") && substr_count($api, '+ $appNet()') === 3
    && str_contains($api, 'LiveTrips::ingest($trip, array_values($points))') && !str_contains($api, 'sentAt'));
check('api/whereis.php adds staleSec', str_contains($src('api/whereis.php'), "'staleSec' =>"));
check('SHARI NAV "My bus": a not-live answer moves / greys / removes the coach, and the dead fixAge test is gone',
    str_contains($nav, 'function myBusMark(d, first)') && str_contains($nav, "mel.classList.toggle('nl-last', !live);") && str_contains($nav, 'if (!ll) { if (MB.m) { MB.m.remove(); MB.m = null; } return; }')
    && !str_contains($nav, 'd.fixAge != null && d.fixAge < 300') && !str_contains($nav, 'd.lastKnown && d.fixAge != null'));
check('SHARI NAV "My bus": an old answer is not kept live (staleSec from the server)', str_contains($nav, 'function myBusAge()') && str_contains($nav, "typeof d.staleSec === 'number'") && str_contains($nav, 'MB.stale * 1000'));
check('SHARI NAV "My bus": the three card buttons are 44 px tall', !str_contains($nav, 'min-height:36px') && substr_count($nav, 'style="min-height:44px;padding:0 12px;font-size:13px"') === 3);
check('navigate.html: the screen lock is asked for again while a drive is on', str_contains($src('navigate.html'), "setInterval(function () { if (!S.wake && document.visibilityState === 'visible' && S.nav && !S.nav.preview && !S.nav.arrived) wake(true); }, 10000);"));
check('office Live Map: polls have a time limit, and the screen says when it stops updating',
    str_contains($map, 'function getJ(url)') && str_contains($map, 'function staleCheck()') && str_contains($map, "fa.classList.add('lm-stale')") && str_contains($map, "Settings::getInt('track_stale_sec', 60)"));
check('office Live Map: no silent catch-all left on the three polls', substr_count($map, "fetch(A.fleetUrl") + substr_count($map, "fetch(A.statusUrl") + substr_count($map, "fetch(A.base + '/api/kv.php") === 0);
check('migration: three keys, INSERT IGNORE only',
    str_contains($mig, 'INSERT IGNORE INTO settings') && str_contains($mig, "'track_stale_sec'") && str_contains($mig, "'driver_net_timeout_sec'") && str_contains($mig, "'driver_clock_fix_on'")
    && preg_match('/^\s*(DROP|DELETE|ALTER|UPDATE|TRUNCATE|REPLACE)\b/mi', $mig) !== 1);
foreach (['track.php', 'driver/index.php', 'admin/map.php', 'assets/nav/nav-live.js'] as $f) {
    check("$f: no parcel wording added", stripos(preg_replace('/parcel_book_on/', '', $src($f)) ?? '', 'parcel') === false);
}

/* ---------------------------------------------------------------------
   Node: syntax of every script touched (5b) + the fake browser (4)
--------------------------------------------------------------------- */
$tmp   = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'shg-fx1t-' . getmypid();
$node  = null;
$nodeV = @shell_exec('node --version 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
if (is_string($nodeV) && preg_match('/^v(\d+)\./', trim($nodeV), $m) === 1 && (int) $m[1] >= 16) { $node = 'node'; }
@mkdir($tmp, 0700, true);
$rmTmp = static function () use ($tmp): void {
    foreach ((array) glob($tmp . DIRECTORY_SEPARATOR . '*') as $f) { @unlink((string) $f); }
    @rmdir($tmp);
};
$nodeRun = static function (array $args) use ($node): array {
    $cmd = array_merge([$node], $args);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return [1, '', 'could not start node']; }
    fclose($pipes[0]);
    $o = (string) stream_get_contents($pipes[1]); $e = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($p), $o, $e];
};
$syntax = static function (string $label, string $js) use ($node, $nodeRun, $tmp): void {
    if ($node === null) { skip("node --check $label (node is not installed)"); return; }
    $f = $tmp . DIRECTORY_SEPARATOR . preg_replace('/[^a-z0-9]+/i', '-', $label) . '.js';
    file_put_contents($f, $js);
    [$code, , $err] = $nodeRun(['--check', $f]);
    check("node --check $label", $code === 0, $code === 0 ? '' : substr(trim($err), 0, 300));
};
$inline = static function (string $html): string {
    return preg_match_all('#<script>(.*?)</script>#s', $html, $m) > 0 ? (string) end($m[1]) : '';
};
echo "-- script syntax --\n";
$syntax('assets/nav/nav-live.js', $nav);
/* navigate.html: its one big inline script (found by position - the file is too large for a lazy regex) */
$navHtml = $src('navigate.html'); $navJs = ''; $pos = 0;
while (($s0 = strpos($navHtml, '<script>', $pos)) !== false && ($s1 = strpos($navHtml, '</script>', $s0)) !== false) {
    if ($s1 - $s0 - 8 > strlen($navJs)) { $navJs = substr($navHtml, $s0 + 8, $s1 - $s0 - 8); }
    $pos = $s1 + 9;
}
check('navigate.html inline script found', strlen($navJs) > 100000);
$syntax('navigate.html inline script', $navJs);
$drvJs = $inline($drv);
check('driver/index.php has one static inline script (no PHP inside)', $drvJs !== '' && !str_contains($drvJs, '<?'));
$syntax('driver/index.php inline script', $drvJs);
$a = strpos($map, 'window.ADMAP = {'); $b = $a !== false ? strpos($map, '</script>', $a) : false;
$mapJs = $a !== false && $b !== false ? (string) preg_replace('/<\?(?:=|php).*?\?>\n?/s', "null\n", substr($map, $a, $b - $a)) : '';
check('admin/map.php radar script found', $mapJs !== '');
$syntax('admin/map.php radar script (PHP values blanked)', $mapJs);

/* The fake browser: a clock the test moves, timers it fires, a fetch it answers, and elements that only remember
   what was written to them. Enough to run the pages' real scripts and watch what they put on the screen. */
$HARNESS = <<<'FXJS'
'use strict';
const fs = require('fs');
const mode = process.argv[2], fileA = process.argv[3], fileB = process.argv[4];
const out = [];
const check = (name, ok, extra) => out.push({ name: name, ok: !!ok, extra: extra === undefined || extra === null ? '' : String(extra).slice(0, 300) });
const realImmediate = setImmediate;
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(r => realImmediate(r)); };
let now = 1795000000000;
const RD = Date;
globalThis.Date = class extends RD { constructor(...a) { if (a.length) super(...a); else super(now); } static now() { return now; } };
const def = (k, v) => Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true });
const tms = []; let tid = 0;
def('setTimeout', (fn, ms) => { tms.push({ id: ++tid, at: now + (ms || 0), fn: fn, every: 0 }); return tid; });
def('setInterval', (fn, ms) => { tms.push({ id: ++tid, at: now + ms, fn: fn, every: ms }); return tid; });
const clr = id => { const i = tms.findIndex(t => t.id === id); if (i >= 0) tms.splice(i, 1); };
def('clearTimeout', clr); def('clearInterval', clr);
async function advance(ms) {
  const end = now + ms;
  for (;;) {
    const due = tms.filter(t => t.at <= end).sort((a, b) => a.at - b.at || a.id - b.id)[0];
    if (!due) break;
    if (due.at > now) now = due.at;
    if (due.every) due.at += due.every; else tms.splice(tms.indexOf(due), 1);
    try { due.fn(); } catch (e) { check('a timer callback threw', false, e && e.stack); }
    await settle();
  }
  now = end; await settle();
}
function mkEl(id) {
  const cl = new Set();
  const e = { id: id, textContent: '', innerHTML: '', className: '', href: '', value: '', hidden: false, disabled: false, style: {}, dataset: {}, children: [], lastChild: null, _l: {},
    classList: { add: c => { cl.add(c); }, remove: c => { cl.delete(c); }, toggle: (c, f) => { if (f === undefined) f = !cl.has(c); if (f) cl.add(c); else cl.delete(c); return f; }, contains: c => cl.has(c) },
    addEventListener(t, f) { (e._l[t] = e._l[t] || []).push(f); }, setAttribute() {}, getAttribute() { return 'en'; }, removeAttribute() {},
    appendChild(c) { e.children.push(c); e.lastChild = c; return c; }, insertBefore(c) { e.children.push(c); return c; }, _q: {},
    querySelector(sel) { return e._q[sel] || (e._q[sel] = mkEl(sel)); }, querySelectorAll() { return []; },
    getBoundingClientRect() { return {}; }, focus() {}, click() {}, remove() {}, scrollIntoView() {} };
  return e;
}
const els = {}, qs = {};
process.on('unhandledRejection', () => {});   /* code outside this test's reach may reject: not what is being checked */
const doc = { hidden: false, visibilityState: 'visible', documentElement: mkEl('html'), head: mkEl('head'), body: mkEl('body'), _l: {},
  getElementById: id => els[id] || (els[id] = mkEl(id)), querySelector: sel => qs[sel] || (qs[sel] = mkEl(sel)), querySelectorAll: () => [], createElement: t => mkEl(t),
  createTextNode: t => ({ nodeType: 3, nodeValue: t }), addEventListener(t, f) { (doc._l[t] = doc._l[t] || []).push(f); } };
const mem = () => { const m = new Map(); return { getItem: k => (m.has(k) ? m.get(k) : null), setItem: (k, v) => { m.set(k, String(v)); }, removeItem: k => { m.delete(k); } }; };
const winL = {};
def('window', globalThis); def('document', doc); def('localStorage', mem()); def('sessionStorage', mem());
def('location', { search: '', hash: '', pathname: '/x', reloaded: 0, reload() { this.reloaded++; } });
def('history', { replaceState() {} });
def('addEventListener', (t, f) => { (winL[t] = winL[t] || []).push(f); });
def('matchMedia', () => ({ matches: false }));
const H = { calls: [], route: null, onFix: null };
def('navigator', { onLine: true, geolocation: { watchPosition(cb) { H.onFix = cb; return 1; }, clearWatch() {}, getCurrentPosition(ok) { ok({}); } } });
def('fetch', (url, opt) => {
  const c = { url: String(url), opt: opt || {}, body: null, at: now };
  try { if (opt && opt.body) c.body = JSON.parse(opt.body); } catch (e) {}
  H.calls.push(c);
  return H.route(c);
});
const json = (status, body) => Promise.resolve({ ok: status >= 200 && status < 300, status: status, json: () => Promise.resolve(body) });
const hang = c => new Promise((res, rej) => { const s = c.opt.signal; if (s) s.addEventListener('abort', () => rej(new Error('aborted'))); });
const run = f => (0, eval)(fs.readFileSync(f, 'utf8'));

/* ---- track.php: the page's inline script (rendered, English) + assets/js/track-live.js ---- */
async function track(staleOn) {
  let ans = null, how = 'ok';
  H.route = c => how === 'ok' ? json(200, { ok: true, data: ans })
    : how === 'gone' ? json(404, { ok: false, error: 'Not found.' })
    : how === 'proxy404' ? Promise.resolve({ ok: false, status: 404, json: () => Promise.reject(new Error('html')) })
    : how === 'hang' ? hang(c) : Promise.reject(new Error('offline'));
  run(fileA);
  const S = globalThis.SHG_TRACK;
  run(fileB);
  check('the page script and track-live.js run', !!S && typeof S.extra === 'function' && !!S.T && typeof S.T.offline === 'string');
  if (!S || !S.T) return;
  const T = S.T;
  ans = Object.assign({}, S.D, { bookingStatus: 'confirmed', state: 'on_route', tripStatus: 'departed', live: true, lat: 26.5, lng: 80.3, kmh: 62, fixAge: 12,
    etaMin: 25, km: 14.2, stop: 'Kanpur', pickupPassed: false, lastKnown: false, delayMin: 0, staleSec: 60,
    progress: { km: 500, pct: 40, source: 'gps' }, destEta: { name: 'Nepalgunj', min: 300, source: 'gps' },
    pace: { state: 'run', planPct: 42, planName: 'Etawah', planKm: 520, livePct: 40, liveName: 'Etawah', liveKm: 500, lateMin: 5, source: 'gps' },
    timeline: [{ kind: 'stop', name: 'Agra', time: '10:00', km: 300, passed: true }, { kind: 'stop', name: 'Kanpur', time: '14:00', km: 600, eta: 25, next: true, mine: 'board' }] });
  const E = id => els[id] || mkEl(id);
  const liveNow = () => E('hTitle').innerHTML.indexOf('class="pulse"') >= 0 && E('chips').innerHTML.indexOf('chip live') >= 0 && E('pTag').textContent === T.gps;

  await advance(15000);
  check('a live answer: pulse, LIVE chip, "Live GPS" bar, "Updated 12 s ago"', liveNow() && S.D.live === true && E('meta').textContent === T.updated.replace('%s', '12 s') && /GPS/.test(E('rail').innerHTML), E('meta').textContent);

  how = 'offline';
  await advance(30000);
  if (!staleOn) {
    await advance(300000);
    check('track_stale_sec = 0: the page keeps the last answer as before (switch off)', liveNow() && S.D.live === true, E('meta').textContent);
    return;
  }
  check('30 s without an answer: still inside the limit, and the age on the page keeps counting', liveNow() && S.D.live === true && E('meta').textContent === T.updated.replace('%s', '42 s'), E('meta').textContent);

  await advance(35000);
  const title = E('hTitle').innerHTML;
  check('65 s without an answer: NOT live - no pulse, no LIVE chip, no "Live GPS" bar', title.indexOf('pulse') < 0 && E('chips').innerHTML.indexOf('chip live') < 0 && E('pTag').textContent === '' && S.D.live === false && S.D.lastKnown === true, title);
  check('...the headline says where and when it was last seen, "not live"', /Last seen near Etawah at \d\d:\d\d \(1 min ago\)/.test(title) && title.indexOf('not live') > 0, title);
  check('...no ETA anywhere: tile, rail, clock strip', E('tS').textContent.charAt(0) !== '~' && !/GPS/.test(E('rail').innerHTML) && S.D.etaMin === null && S.D.destEta === null && E('bigS').textContent.indexOf(T.gps) < 0,
    E('tS').textContent + ' | ' + E('bigS').textContent);
  check('...the timetable lane stays, the live lane is replaced by "Live GPS is off"', E('pace').innerHTML.indexOf('Live GPS is off right now') > 0 && E('pace').innerHTML.indexOf('Bus is here') < 0);
  check('...the Maps button now says "last known place" and points at it', E('mapsTxt').textContent === T.mapsLast && E('mapsBtn').href.indexOf('26.5,80.3') > 0, E('mapsBtn').href);
  check('...and the last line says since when nothing new came', E('meta').textContent.indexOf('No new update since') === 0 && /\d\d:\d\d/.test(E('meta').textContent), E('meta').textContent);
  check('...the answer handed to the map is the last-known shape (grey coach)', S.D.lastLat === 26.5 && S.D.lastLng === 80.3 && S.D.lat === null && S.D.progress.source === 'none' && S.D.timeline.every(r => !('eta' in r)));

  await advance(120000);
  check('the "min ago" keeps growing while the phone is offline', /\(3 min ago\)/.test(E('hTitle').innerHTML), E('hTitle').innerHTML);

  how = 'ok';
  await advance(15000);
  /* the good answer came at the 15 s poll, 5 s before the end of this step: 12 s + 5 s */
  check('the next good answer puts LIVE back', liveNow() && S.D.live === true && E('meta').textContent === T.updated.replace('%s', '17 s'), E('meta').textContent);

  how = 'hang';
  const n0 = H.calls.length;
  await advance(15000);
  const hung = H.calls[H.calls.length - 1];
  check('a request on a dead link is started once', H.calls.length === n0 + 1 && !!hung.opt.signal && !hung.opt.signal.aborted);
  (doc._l.visibilitychange || []).forEach(f => f());
  await advance(5000);
  check('...and while it hangs no second request is piled on', H.calls.length === n0 + 1);
  await advance(25000);
  check('...it is given up after 12 s and the next round asks again', hung.opt.signal.aborted === true && H.calls.length >= n0 + 2);

  how = 'proxy404';
  await advance(30000);
  check('a 404 that is not our own JSON (proxy / deploy page) never reloads the page', location.reloaded === 0);
  how = 'gone';
  await advance(30000);
  check('our own 404 (link ran out, ticket gone) reloads the page once', location.reloaded === 1, String(location.reloaded));
  await advance(30000);
  check('...not again within a minute', location.reloaded === 1, String(location.reloaded));
}

/* ---- driver/index.php: the driver web app with a saved running trip ---- */
async function driver(nt) {
  localStorage.setItem('shgDrv:token', 'a'.repeat(48));
  localStorage.setItem('shgDrv:trip', JSON.stringify({ tripId: 7, scheduleId: 3, route: 'A - B', bus: 'T-1', dep: '10:00', date: '2096-07-14', since: now, startedTs: null }));
  let how = 'ok';
  const cfg = { netTimeoutSec: nt, clockFix: true };
  H.route = c => {
    const a = c.body ? c.body.action : (/action=(\w+)/.exec(c.url) || [])[1];
    if (a === 'me') return json(200, { ok: true, data: Object.assign({ driver: { name: 'T', phone: '', role: 'driver' }, trip: { tripId: 7, scheduleId: 3, lastSeq: 0, lastPingAt: null, startedTs: null }, trips: [], pingSec: 15, crew: {} }, cfg, { nowMs: now }) });
    if (a === 'trip') return json(200, { ok: true, data: { now: Math.floor(now / 1000) } });
    if (a === 'ping') {
      if (how === 'hang') return hang(c);
      if (how === 'off') return Promise.reject(new Error('offline'));
      const max = c.body.points.reduce((m, p) => Math.max(m, p.seq), 0);
      return json(200, { ok: true, data: Object.assign({ ack: max, stored: c.body.points.length, moved: true, pingSec: 15 }, cfg, { nowMs: now }) });
    }
    return json(200, { ok: true, data: {} });
  };
  run(fileA); await settle();
  check('the driver page boots, resumes the saved trip and starts the GPS watch', typeof H.onFix === 'function');
  if (typeof H.onFix !== 'function') return;
  const pings = () => H.calls.filter(c => c.body && c.body.action === 'ping');
  const last = () => pings()[pings().length - 1];
  let lat = 27.1;
  const fix = off => { lat += 0.001; const ts = now + (off || 0); H.onFix({ coords: { latitude: lat, longitude: 80.9, accuracy: 10, speed: 12, heading: 90 }, timestamp: ts }); return ts; };

  let ts = fix(0);
  await advance(16000);
  let p = last();
  check('a fix is uploaded with its own time', !!p && p.body.points.length === 1 && p.body.points[0].t === ts, p ? JSON.stringify(p.body.points) : 'no upload');
  if (!p) return;
  if (nt > 0) check('...with a give-up timer on the request, and nothing but the points', !!p.opt.signal && Object.keys(p.body).sort().join() === 'action,points,tripId');
  else check('driver_net_timeout_sec = 0: no give-up timer (as before)', !p.opt.signal);

  /* the reviewers' case: the phone's clock is right and the browser hands over an OLD fix (its last-known place) */
  await advance(15000);
  const arrived = now; ts = fix(-600000);
  await advance(16000);
  p = last();
  check('phone clock right + a fix that is 10 min old: it goes out with its own old time, never as "now"',
    p.body.points.length === 1 && p.body.points[0].t === ts && arrived - p.body.points[0].t === 600000, JSON.stringify(p.body.points));
  await advance(15000);
  ts = fix(-1200000);
  await advance(16000);
  p = last();
  check('...and one that is 20 min old too', p.body.points.length === 1 && p.body.points[0].t === ts, JSON.stringify(p.body.points));

  how = 'hang';
  await advance(15000); fix(0);
  const n1 = pings().length;
  await advance(16000);
  const hung = last();
  if (nt === 0) {
    await advance(120000);
    check('driver_net_timeout_sec = 0: a stuck upload is never given up - every later upload waits (the old behaviour)', pings().length === n1 + 1, String(pings().length - n1));
    return;
  }
  check('an upload on a dead link is started', pings().length === n1 + 1 && !hung.opt.signal.aborted);
  await advance(hung.at + nt * 1000 - 1000 - now);
  check('...while it hangs no second upload is piled on', pings().length === n1 + 1 && !hung.opt.signal.aborted);
  await advance(1000 + 16000);
  check('...it is given up after ' + nt + ' s and the next round uploads again (before: stuck for ever)', hung.opt.signal.aborted === true && pings().length >= n1 + 2, String(pings().length - n1));
  how = 'ok';
  await advance(nt * 1000 + 22000);
  check('...and the queue empties once the link is back', last().body.points.length >= 1 && E('rQueue').textContent === '0', E('rQueue').textContent);

  how = 'off';
  for (let i = 0; i < 14; i++) { fix(0); await advance(15000); }
  const n2 = pings().length;
  how = 'ok';
  await advance(16000);
  const after = pings().slice(n2).map(c => c.body.points.length);
  check('after failed uploads the first one that gets through is small (10 points), the rest follows at once', after.length >= 2 && after[0] === 10 && after[1] >= 4, after.join(','));
  const seqs = [].concat.apply([], pings().slice(n2).map(c => c.body.points.map(q => q.seq)));
  check('...oldest first, nothing skipped', seqs.every((s, i) => i === 0 || s === seqs[i - 1] + 1), seqs.join(','));
}
function E(id) { return els[id] || mkEl(id); }

/* ---- driver/index.php: a phone whose clock is wrong. skew = phone clock minus server clock (ms); the fake
        browser's clock is the PHONE's, so the server's "now" is now - skew. ---- */
async function driverClock(kind) {
  localStorage.setItem('shgDrv:token', 'a'.repeat(48));
  localStorage.setItem('shgDrv:trip', JSON.stringify({ tripId: 7, scheduleId: 3, route: 'A - B', bus: 'T-1', dep: '10:00', date: '2096-07-14', since: now, startedTs: null }));
  let skew = kind === 'near' ? -140000 : -600000, jit = 0, wait = 0, meWait = kind === 'slow' ? 12000 : 0;
  const cfg = { netTimeoutSec: 20, clockFix: kind !== 'off' };
  const ans = (data, ms) => {
    const mk = () => ({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, data: Object.assign(data, cfg, cfg.clockFix ? { nowMs: now - skew + jit } : {}) }) });
    return ms ? new Promise(res => setTimeout(() => res(mk()), ms)) : Promise.resolve(mk());
  };
  H.route = c => {
    const a = c.body ? c.body.action : (/action=(\w+)/.exec(c.url) || [])[1];
    if (a === 'me') return ans({ driver: { name: 'T', phone: '', role: 'driver' }, trip: { tripId: 7, scheduleId: 3, lastSeq: 0, lastPingAt: null, startedTs: null }, trips: [], pingSec: 15, crew: {} }, meWait);
    if (a === 'trip') return json(200, { ok: true, data: { now: Math.floor((now - skew) / 1000) } });
    if (a === 'ping') {
      const max = c.body.points.reduce((m, p) => Math.max(m, p.seq), 0), w = wait; wait = 0;
      return ans({ ack: max, stored: c.body.points.length, moved: true, pingSec: 15 }, w);
    }
    return json(200, { ok: true, data: {} });
  };
  run(fileA); await settle();
  if (meWait) await advance(meWait + 1000);
  check('the driver page boots and starts the GPS watch', typeof H.onFix === 'function');
  if (typeof H.onFix !== 'function') return;
  const pings = () => H.calls.filter(c => c.body && c.body.action === 'ping');
  let lat = 27.1;
  /* one fix whose time is `ts`, uploaded: [the time that went out, the phone clock when the fix arrived] */
  const send = async ts => {
    await advance(15000);
    const at = now; lat += 0.001;
    H.onFix({ coords: { latitude: lat, longitude: 80.9, accuracy: 10, speed: 12, heading: 90 }, timestamp: ts === undefined ? at : ts(at) });
    await advance(16000 + wait);
    const all = [].concat.apply([], pings().map(c => c.body.points)), p = all[all.length - 1];
    return [p ? p.t : null, at];
  };
  const srv = at => at - skew;   // the server's (real) time at phone-clock moment `at`
  let t, at;

  if (kind === 'off') {
    [t, at] = await send();
    check('driver_clock_fix_on = 0, phone 10 min behind: the time goes out as measured (as before)', t === at, String(t - at));
    [t, at] = await send(a => srv(a));
    check('...and a satellite time too', t === srv(at), String(t - srv(at)));
    return;
  }
  if (kind === 'near') {
    [t, at] = await send();
    check('phone 140 s behind (under the 150 s floor): the time goes out as measured', t === at, String(t - at));
    [t, at] = await send(a => srv(a));
    check('...and a fix that carries real (satellite) time keeps it - it was exact before and still is', t === srv(at), String(t - srv(at)));
    wait = 11000;
    await send();
    [t, at] = await send();
    check('...after an upload that took 11 s: still as measured (no jump forward that would freeze the coach for 2.5 min)', t === at, String(t - at));
    return;
  }
  if (kind === 'slow') {
    [t, at] = await send();
    check('phone 10 min behind, but the only answer so far took 12 s (too slow to read a clock from): the time goes out as measured', t === at, String(t - at));
    [t, at] = await send();
    check('...the quick answer to that upload gives the clock: the next fix goes out with its real time', t === srv(at), String(t - srv(at)));
    return;
  }

  /* kind 'wrong': 10 min behind, then 10 min ahead */
  for (const name of ['behind', 'AHEAD']) {
    if (name === 'AHEAD') { skew = 600000; await send(); }   // the upload whose answer shows the new clock
    [t, at] = await send();
    check('phone 10 min ' + name + ', fix stamped with the phone clock: it goes out with its real time (was: never live / pinned to "now")', t === srv(at), String(t - srv(at)));
    [t, at] = await send(a => srv(a));
    check('phone 10 min ' + name + ', fix stamped with real (satellite) time: sent unchanged', t === srv(at), String(t - srv(at)));
    [t, at] = await send(a => a - 1200000);
    check('phone 10 min ' + name + ', an OLD fix (20 min, phone clock): at least 20 min old on the server - never fresh', srv(at) - t >= 1200000 && srv(at) - t <= 1800000, String(srv(at) - t));
    [t, at] = await send(a => srv(a) - 1200000);
    check('phone 10 min ' + name + ', an OLD fix (20 min, satellite time): at least 20 min old on the server - never fresh', srv(at) - t >= 1200000 && srv(at) - t <= 1800000, String(srv(at) - t));
    [t, at] = await send(a => srv(a) + 3600000);
    check('phone 10 min ' + name + ', a fix time an hour after its own arrival: the arrival moment, not the future', t === srv(at), String(t - srv(at)));
  }
  skew = -600000; await send();
  jit = 4000;
  await send();
  [t, at] = await send();
  check('later readings of the clock differ by 4 s (network): every upload is still corrected by the SAME 10 min', t === at + 600000, String(t - at));
  jit = 0; skew = 0;
  await send();
  [t, at] = await send();
  check('the phone\'s clock was set right during the trip: the next answer shows it and times go out as measured', t === at, String(t - at));
  skew = 13 * 3600000;
  await send();
  [t, at] = await send();
  check('13 hours apart is not a clock: as measured', t === at, String(t - at));
  skew = -600000;
  await send();
  [t, at] = await send();
  check('(10 min behind again: corrected again)', t === srv(at), String(t - srv(at)));
  cfg.clockFix = false;
  await send();
  [t, at] = await send();
  check('the office turns driver_clock_fix_on off during the trip: from the next upload on the times go out as measured', t === at, String(t - at));
}

/* ---- driver/index.php: signed in, no trip - the bus list on a weak network ---- */
async function driverPick() {
  localStorage.setItem('shgDrv:token', 'a'.repeat(48));
  let how = 'hang';
  H.route = c => {
    const a = c.body ? c.body.action : (/action=(\w+)/.exec(c.url) || [])[1];
    if (a !== 'me') return json(200, { ok: true, data: {} });
    if (how === 'hang') return hang(c);
    if (how === 'off') return Promise.reject(new Error('offline'));
    return json(200, { ok: true, data: { driver: { name: 'T', phone: '', role: 'driver' }, trip: null, pingSec: 15, crew: {}, netTimeoutSec: 20, clockFix: true, nowMs: now,
      trips: [{ scheduleId: 3, route: 'A - B', date: '2096-07-14', dep: '10:00', bus: 'T-1', tripStatus: 'scheduled' }, { scheduleId: 4, route: 'B - A', date: '2096-07-14', dep: '11:00', bus: 'T-2', tripStatus: 'scheduled' }] } });
  };
  const mes = () => H.calls.filter(c => /action=me/.test(c.url));
  run(fileA); await settle();
  check('the page asks for the bus list', mes().length === 1);
  await advance(21000);
  check('a list request stuck for 20 s is given up and the screen says so', mes().length === 1 && mes()[0].opt.signal.aborted === true && E('ePick').textContent.indexOf('No internet') === 0 && E('vPick').hidden === false, E('ePick').textContent);
  await advance(9000);
  check('...and it is asked for again by itself (before: never, until the driver reloaded the page)', mes().length === 2, String(mes().length));
  how = 'ok';
  await advance(30000);
  check('...the list arrives on a later try: two buses on the screen, the "No internet" line is gone', mes().length === 3 && E('tripList').children.length === 2 && E('ePick').textContent === '', mes().length + ' / ' + E('tripList').children.length + ' / ' + E('ePick').textContent);
  await advance(120000);
  check('...and a list that loaded is not asked for again', mes().length === 3, String(mes().length));
}
async function driverPickOffline() {
  localStorage.setItem('shgDrv:token', 'a'.repeat(48));
  let how = 'off';
  H.route = c => {
    if (!/action=me/.test(c.url)) return json(200, { ok: true, data: {} });
    if (how === 'off') return Promise.reject(new Error('offline'));
    return json(200, { ok: true, data: { driver: { name: 'T', phone: '', role: 'driver' }, trip: null, pingSec: 15, crew: {}, netTimeoutSec: 20, clockFix: true, nowMs: now, trips: [] } });
  };
  const mes = () => H.calls.filter(c => /action=me/.test(c.url));
  run(fileA); await settle();
  navigator.onLine = false;
  await advance(60000);
  check('the phone itself says "offline": nothing is tried', mes().length === 1 && E('ePick').textContent.indexOf('No internet') === 0, String(mes().length));
  how = 'ok'; navigator.onLine = true;
  (winL.online || []).forEach(f => f());
  await settle();
  check('...back online: the list is asked for at once and the line is gone', mes().length === 2 && E('ePick').textContent === '', mes().length + ' / ' + E('ePick').textContent);
}

/* ---- admin/map.php: the office Live Map script (rendered), without the map library ---- */
async function map() {
  let how = 'ok';
  const list = [{ tripId: 5, live: true, ageSec: 12, route: 'A - B', routeCode: 'R1', bus: 'T-1', dep: '10:00', date: '14 Jul', pax: 3, kmh: 50, lat: 26.5, lng: 80.3, acc: 10, driver: 'D', driverPhone: '' }];
  const fleetCalls = () => H.calls.filter(c => c.url.indexOf('live-fleet') >= 0);
  H.route = c => {
    if (c.url.indexOf('live-fleet') >= 0) return how === 'ok' ? json(200, { ok: true, data: { trips: list } })
      : how === 'login' ? Promise.resolve({ ok: true, status: 200, json: () => Promise.reject(new Error('the sign-in page, not JSON')) })
      : how === 'hang' ? hang(c) : Promise.reject(new Error('offline'));
    if (c.url.indexOf('fleet-status') >= 0) return json(200, { ok: true, data: { counts: {}, sets: {} } });
    return Promise.reject(new Error('not in this test'));
  };
  run(fileA); await settle();
  const rows = () => E('lmFleet').innerHTML, age = E('lmFleetAge');
  check('the fleet list shows the bus live', rows().indexOf('🟢') >= 0 && rows().indexOf('🛰️ 12 s ago') >= 0 && age.textContent === '1 running', rows().slice(0, 200));
  how = 'login';
  if (mode === 'map0') {
    await advance(300000);
    check('track_stale_sec = 0: the screen keeps the last answer as before (switch off)', rows().indexOf('🟢') >= 0 && !age.classList.contains('lm-stale') && age.textContent === '1 running');
    return;
  }
  await advance(30000);
  check('30 s of failed polls: still inside the limit', rows().indexOf('🟢') >= 0 && !age.classList.contains('lm-stale'));
  await advance(35000);
  check('65 s after the sign-in ran out (polls answer a login page): no bus is shown live, the age counts on', rows().indexOf('🟢') < 0 && rows().indexOf('⏸ last seen 1 min ago') >= 0, rows().slice(0, 300));
  check('...and the screen says in words since when it is not updating', age.classList.contains('lm-stale') && /No connection: not updated since \d\d:\d\d IST/.test(age.textContent) && age.textContent.indexOf('जडान छैन') >= 0, age.textContent);
  how = 'ok';
  await advance(10000);
  check('the next good poll clears it', rows().indexOf('🟢') >= 0 && !age.classList.contains('lm-stale') && age.textContent === '1 running', age.textContent);
  how = 'hang';
  const n0 = fleetCalls().length;
  await advance(10000);
  const hung = fleetCalls()[fleetCalls().length - 1];
  check('a poll on a dead link is started once', fleetCalls().length === n0 + 1 && !!hung.opt.signal && !hung.opt.signal.aborted);
  await advance(4000);
  check('...not piled on while it hangs', fleetCalls().length === n0 + 1);
  await advance(12000);
  check('...given up after 10 s, and asked again', hung.opt.signal.aborted === true && fleetCalls().length >= n0 + 2);
}

/* ---- assets/nav/nav-live.js: "My bus" in SHARI NAV, opened from a track link (?pnr=&k=) ---- */
async function nav() {
  location.search = '?pnr=SHG-TEST-1&k=abc';
  try { delete globalThis.WebSocket; } catch (e) {}
  const markers = [];
  def('maplibregl', { Marker: class { constructor(o) { this.el = o.element; this.ll = null; this.removed = false; markers.push(this); }
      setLngLat(ll) { this.ll = ll; return this; } addTo() { return this; } getLngLat() { return { lng: this.ll[0], lat: this.ll[1] }; } getElement() { return this.el; } remove() { this.removed = true; } },
    LngLatBounds: class { extend() { return this; } } });
  const fakeMap = { on() {}, flyTo() {}, easeTo() {}, fitBounds() {}, getZoom() { return 10; }, getCenter() { return { lng: 80, lat: 26 }; } };
  const escH = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  globalThis.SHGNAV_X = { esc: escH, fetchT: (u, o) => fetch(u, o), S: { map: fakeMap, lang: 'en', saver: true, me: null, level: 0, depth: 0, nav: null }, toast() {}, hav() { return 0; }, closeModal() {} };
  const base = { pnr: 'SHG-TEST-1', bookingStatus: 'confirmed', state: 'on_route', bus: 'T-1', route: 'A - B', stop: 'Kanpur', staleSec: 60 };
  const live = Object.assign({}, base, { live: true, lat: 26.5, lng: 80.3, kmh: 50, fixAge: 10, etaMin: 25, km: 14, bearing: 90, lastKnown: false });
  let ans = live, how = 'ok';
  H.route = c => {
    if (c.url.indexOf('/api/whereis.php') === 0) return how === 'ok' ? json(200, { ok: true, data: Object.assign({}, ans) }) : Promise.reject(new Error('offline'));
    if (c.url.indexOf('nav-buses') >= 0) return json(200, { ok: true, data: { t: 1, buses: [], gps: [], fleet: [] } });
    if (c.url.indexOf('nav-live') >= 0) return json(200, { ok: true, data: { now: 1, ev: [] } });
    if (c.url.indexOf('nav-sync') >= 0) return json(200, { ok: true, data: { signedIn: false } });
    return Promise.reject(new Error('not in this test'));
  };
  run(fileA); await settle();
  const card = () => (qs['#nlSheet'] ? qs['#nlSheet'].innerHTML : '');
  const on = () => markers.filter(m => !m.removed);
  const mk = () => on()[on().length - 1];
  const label = () => { const m = mk(), s = m && m.el._q.span; return s ? s.textContent : ''; };
  check('live: the coach is on the map at its place, the card says LIVE', on().length === 1 && mk().ll[0] === 80.3 && mk().ll[1] === 26.5 && !mk().el.classList.contains('nl-last') && card().indexOf('nl-badge-live') > 0 && card().indexOf('25 min') > 0, card().slice(0, 300));
  if (on().length !== 1) return;

  ans = Object.assign({}, base, { live: false, lat: null, lng: null, fixAge: null, etaMin: null, km: null, lastKnown: true, lastLat: 26.6, lastLng: 80.4, lastAt: '14:05', lastAgeMin: 7, lastStop: 'Etawah' });
  await advance(15000);
  check('the server says "last seen" (GPS gap): the coach moves to that place and turns grey (before: it stayed at the old place, looking live)',
    on().length === 1 && mk().ll[0] === 80.4 && mk().ll[1] === 26.6 && mk().el.classList.contains('nl-last'), JSON.stringify(mk() && mk().ll));
  check('...its label carries the time it was seen', label().indexOf('14:05') > 0, label());
  check('...and the card says so: badge + "last known place · 7 min ago · 14:05 IST · Etawah" (this line never showed before)',
    card().indexOf('nl-badge-warn') > 0 && card().indexOf('last known place · 7 min ago · 14:05 IST · Etawah') > 0 && card().indexOf('nl-badge-live') < 0, card().slice(0, 400));

  ans = Object.assign({}, base, { state: 'arrived', live: false, lat: null, lng: null, lastKnown: false });
  await advance(15000);
  check('the trip is over and nothing is known: the coach leaves the map', on().length === 0 && card().indexOf('nl-badge-done') > 0);

  ans = live;
  await advance(15000);
  check('live again: a coach at the live place', on().length === 1 && mk().ll[0] === 80.3 && !mk().el.classList.contains('nl-last') && card().indexOf('nl-badge-live') > 0);
  if (on().length !== 1) return;
  how = 'offline';
  await advance(30000);
  check('30 s without an answer: still inside the limit', !mk().el.classList.contains('nl-last') && card().indexOf('nl-badge-live') > 0);
  await advance(40000);
  check('70 s without an answer (this phone is offline): the coach turns grey where it was, the card is not LIVE, no ETA',
    on().length === 1 && mk().ll[0] === 80.3 && mk().el.classList.contains('nl-last') && card().indexOf('nl-badge-live') < 0 && card().indexOf('last known place') > 0 && card().indexOf('25 min') < 0, card().slice(0, 400));

  how = 'ok'; ans = Object.assign({}, live, { staleSec: 0 });
  await advance(15000);
  how = 'offline';
  await advance(300000);
  check('staleSec 0 from the server (track_stale_sec = 0): the last answer is kept as before', on().length === 1 && !mk().el.classList.contains('nl-last') && card().indexOf('nl-badge-live') > 0);
}

(mode === 'track' ? track(true) : mode === 'track0' ? track(false) : mode === 'map' || mode === 'map0' ? map() : mode === 'nav' ? nav()
  : mode.indexOf('clock-') === 0 ? driverClock(mode.slice(6)) : mode === 'pick' ? driverPick() : mode === 'pick-offline' ? driverPickOffline() : driver(mode === 'driver0' ? 0 : 20))
  .catch(e => check('the fake browser run threw', false, e && e.stack))
  .then(() => { process.stdout.write(JSON.stringify(out)); });
FXJS;
$harness = $tmp . DIRECTORY_SEPARATOR . 'harness.js';
$browser = static function (string $title, string $mode, string $fileA, string $fileB = '') use ($node, $nodeRun, $harness): void {
    echo "-- $title --\n";
    if ($node === null) { skip("$title (node is not installed)"); return; }
    [$code, $o, $err] = $nodeRun([$harness, $mode, $fileA, $fileB]);
    $rows = json_decode($o, true);
    if (!is_array($rows) || $rows === []) { check("$title ran", false, substr(trim($err . ' ' . $o), 0, 400)); return; }
    foreach ($rows as $r) { check((string) $r['name'], (bool) $r['ok'], (bool) $r['ok'] ? '' : (string) $r['extra']); }
};
if ($node !== null) {
    file_put_contents($harness, $HARNESS);
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'driver.js', $drvJs);
}
$browser('driver web app on a weak network (fake browser)', 'driver', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app, driver_net_timeout_sec = 0 (fake browser)', 'driver0', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app on a phone whose clock is wrong (fake browser)', 'clock-wrong', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app, clock just under the floor + a slow upload (fake browser)', 'clock-near', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app, clock wrong but the first answer is slow (fake browser)', 'clock-slow', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app, driver_clock_fix_on = 0 (fake browser)', 'clock-off', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app: the bus list on a weak network (fake browser)', 'pick', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('driver web app: the bus list while the phone is offline (fake browser)', 'pick-offline', $tmp . DIRECTORY_SEPARATOR . 'driver.js');
$browser('SHARI NAV "My bus" (fake browser, no map library)', 'nav', $ROOT . '/assets/nav/nav-live.js');
/* the office Live Map script as PHP prints it (the page itself needs an admin sign-in): its template values are given here */
$renderMap = static function (int $stale) use ($map, $a, $b): string {
    $jsRoutes = []; $company = 'FX1T Co'; $phone = '+91 00000 00000'; $base = ''; $replay = null; $fleetBar = ['counts' => [], 'sets' => []];
    $seg = str_replace("\$lmStale = Settings::getInt('track_stale_sec', 60);", '$lmStale = ' . $stale . ';', substr($map, (int) $a, (int) $b - (int) $a), $n);
    if ($n !== 1) { return ''; }
    ob_start();
    eval('?>' . $seg);
    return (string) ob_get_clean();
};
if ($node !== null && $mapJs !== '') {
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'map.js', $renderMap(60));
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'map0.js', $renderMap(0));
    check('admin/map.php prints the stale limit (60, and 0 = off)', str_contains((string) file_get_contents($tmp . DIRECTORY_SEPARATOR . 'map.js'), "stale: 60\n};")
        && str_contains((string) file_get_contents($tmp . DIRECTORY_SEPARATOR . 'map0.js'), "stale: 0\n};"));
}
$browser('office Live Map when its own connection drops (fake browser, no map library)', 'map', $tmp . DIRECTORY_SEPARATOR . 'map.js');
$browser('office Live Map, track_stale_sec = 0 (fake browser)', 'map0', $tmp . DIRECTORY_SEPARATOR . 'map0.js');

/* ---------------------------------------------------------------------
   2 + 3. Database + HTTP
--------------------------------------------------------------------- */
if (!LiveTrips::available()) {
    check('live tracking tables exist (apply database/upgrade-2026-10-live-tracking.sql)', false);
    $rmTmp(); finish();
}
$route = null;
foreach (Database::fetchAll('SELECT * FROM routes WHERE is_active = 1 ORDER BY id') as $r) {
    RouteGeo::$enabled = false;
    $geoStops = array_values(array_filter(EtaAlerts::routeLine((int) $r['id'])['line'], static fn($s) => $s['type'] === 'boarding'));
    RouteGeo::$enabled = true;
    if (count($geoStops) >= 2) { $route = $r; break; }
}
if ($route === null) {
    skip('database part: needs a route with two boarding stops that have coordinates');
    $rmTmp(); finish();
}

$touched = ['track_stale_sec', 'driver_net_timeout_sec', 'driver_clock_fix_on'];
$saved   = [];
foreach ($touched as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($saved): void {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('INSERT INTO settings (skey, svalue, stype, sgroup, is_public) VALUES (:k, :v, :t, :g, :p)
                 ON DUPLICATE KEY UPDATE svalue = VALUES(svalue), stype = VALUES(stype)',
                ['k' => $k, 'v' => $row['svalue'], 't' => $row['stype'], 'g' => $row['sgroup'], 'p' => (int) $row['is_public']]);
        }
    }
    Settings::flush();
};
$cleanup = static function (): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE '" . FX_TAG . "%'") as $b) {
        Database::delete('booking_legs', 'booking_id = :b', ['b' => (int) $b['id']]);
        Database::delete('bookings', 'id = :b', ['b' => (int) $b['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :a', ['a' => FX_DATE]) as $s) {
        Database::delete('live_trips', 'schedule_id = :s', ['s' => (int) $s['id']]);   // trip_locations cascade
        Database::delete('schedules', 'id = :s', ['s' => (int) $s['id']]);
    }
    foreach (Database::fetchAll('SELECT id FROM drivers WHERE phone = :p', ['p' => FX_PHONE]) as $d) {
        Database::delete('driver_devices', 'driver_id = :d', ['d' => (int) $d['id']]);
        Database::delete('drivers', 'id = :d', ['d' => (int) $d['id']]);
    }
};
$setting = static function (string $k, string $v, string $type): void { Settings::set($k, $v, $type, 'gps'); Settings::flush(); };

$server = null;
$cleanup();
try {
    foreach ($touched as $k) { check("setting $k is in the test database (migration applied)", $saved[$k] !== null); }
    $setting('track_stale_sec', '60', 'int'); $setting('driver_net_timeout_sec', '20', 'int'); $setting('driver_clock_fix_on', '1', 'bool');

    echo "-- a phone with a wrong clock (in-process, synthetic clock) --\n";
    RouteGeo::$enabled = false;   // measured on the line of stops, like tests/live-trips-test.php
    $routeId = (int) $route['id'];
    $stops   = array_values(array_filter(EtaAlerts::routeLine($routeId)['line'], static fn($s) => $s['type'] === 'boarding'));
    [$first, $mid] = [$stops[0], $stops[1]];
    $sid = (int) Seats::schedule($routeId, FX_DATE)['id'];
    $did = Database::insert('drivers', ['full_name' => 'FX1T Test Driver', 'phone' => FX_PHONE, 'role' => 'driver', 'is_active' => 1]);
    DriverAuth::setPin($did, '4826');
    $login = DriverAuth::login(FX_PHONE, '4826', 'fx1 test phone');
    $tok   = (string) ($login['token'] ?? '');
    $me    = DriverAuth::current($tok);
    check('test driver signs in', $me !== null && $me['driverId'] === $did);
    $t0   = strtotime(FX_DATE . ' ' . ($mid['time'] ?? '17:00:00')) - 20 * 60;
    $trip = LiveTrips::start($did, $me['deviceId'], $sid, $t0);
    $tid  = (int) $trip['id'];
    $at   = static fn(float $f): array => ['lat' => $first['lat'] + $f * ($mid['lat'] - $first['lat']), 'lng' => $first['lng'] + $f * ($mid['lng'] - $first['lng'])];
    /* $real = the true second of the fix, $skew = how far the PHONE clock is from the truth (seconds, minus = behind) */
    $pt   = static fn(int $seq, int $real, int $skew, float $f): array => ['seq' => $seq, 't' => ($real + $skew) * 1000, 'kmh' => 40.0, 'brg' => 30, 'acc' => 12] + $at($f);

    $batch = [$pt(1, $t0 - 30, -600, 0.500), $pt(2, $t0 - 15, -600, 0.501), $pt(3, $t0, -600, 0.502)];
    LiveTrips::ingest(LiveTrips::find($tid), $batch, $t0);
    [$fix, $why] = LiveTrips::fixFor($sid, $t0);
    check('phone 10 min behind, times taken as sent: the coach is NOT live ("position is 10 min old")', $fix === null && str_contains($why, '10 min old'), $why);

    /* what the page sends now from that phone: the same fixes with their real times (skew 0) */
    $t1    = $t0 + 45;
    $batch = [$pt(4, $t0 + 15, 0, 0.503), $pt(5, $t0 + 30, 0, 0.504), $pt(6, $t1, 0, 0.505)];
    $r     = LiveTrips::ingest(LiveTrips::find($tid), $batch, $t1);
    [$fix, $why, $age] = LiveTrips::fixFor($sid, $t1);
    $row   = LiveTrips::find($tid);
    check('...the same phone sending real times (the page corrects its clock): live, age 0 s, at the newest fix', $fix !== null && $age === 0 && $r['moved'] && abs((float) $row['last_lat'] - $at(0.505)['lat']) < 1e-6, $why . ' age ' . var_export($age, true));
    check('...and the stored trail has the real times (15 s apart)', (int) Database::scalar(
        "SELECT TIMESTAMPDIFF(SECOND, MIN(recorded_at), MAX(recorded_at)) FROM trip_locations WHERE trip_id = :t AND seq IN (4, 5, 6)", ['t' => $tid]) === 30);

    /* the reviewers' case, server side: an old fix that arrives with its own old time never moves the coach or makes it live */
    $t2    = $t1 + 400;
    $r     = LiveTrips::ingest(LiveTrips::find($tid), [$pt(7, $t1 - 1200, 0, 0.490)], $t2);
    [$fix, $why] = LiveTrips::fixFor($sid, $t2);
    $row   = LiveTrips::find($tid);
    check('a 20-minute-old fix sent with its own time: the coach stays where it was and is not live', !$r['moved'] && $fix === null && abs((float) $row['last_lat'] - $at(0.505)['lat']) < 1e-6
        && (int) strtotime((string) $row['last_ping_at']) === $t1, $why . ' / ' . (string) $row['last_ping_at']);
    RouteGeo::$enabled = true;

    echo "-- HTTP: the driver door and the passenger page --\n";
    /* the HTTP part runs on the real clock: this trip starts again from nothing (its own rows only) */
    Database::delete('trip_locations', 'trip_id = :t', ['t' => $tid]);
    Database::update('live_trips', ['last_lat' => null, 'last_lng' => null, 'last_ping_at' => null, 'last_seq' => 0, 'last_bearing' => null, 'last_speed' => null, 'last_acc_m' => null], 'id = :i', ['i' => $tid]);

    $mkBooking = static function (string $cc, string $phone) use ($sid, $mid): string {
        $pnr = FX_TAG . strtoupper(substr(md5(uniqid('', true)), 0, 8));
        $bid = Database::insert('bookings', ['pnr' => $pnr, 'contact_phone' => $phone, 'contact_country_code' => $cc, 'total_amount' => 2000, 'status' => 'confirmed', 'source' => 'admin']);
        Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sid, 'travel_date' => FX_DATE, 'seat_count' => 1, 'boarding_stop' => $mid['name']]);
        return $pnr;
    };
    $pnrNp = $mkBooking('977', '9811100926');
    $pnrIn = $mkBooking('91', '9000009927');

    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $cmd  = [PHP_BINARY];
    if (($ini = php_ini_loaded_file()) !== false) { $cmd[] = '-c'; $cmd[] = $ini; }
    array_push($cmd, '-S', '127.0.0.1:' . FX_PORT, '-t', $ROOT);
    $server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
    $http = static function (string $path, array $headers = [], ?array $json = null): array {
        $ch = curl_init('http://127.0.0.1:' . FX_PORT . $path);
        $opt = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 25, CURLOPT_HTTPHEADER => $headers];
        if ($json !== null) {
            $opt[CURLOPT_POST] = true; $opt[CURLOPT_POSTFIELDS] = json_encode($json);
            $opt[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
        }
        curl_setopt_array($ch, $opt);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['code' => $code, 'body' => $body, 'json' => json_decode($body, true)];
    };
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $up = $http('/robots.txt')['code'] > 0; }
    if (!$up) {
        check('php -S started on 127.0.0.1:' . FX_PORT, false);
    } else {
        $hdr = ['X-Driver-Token: ' . $tok];
        $r = $http('/api/driver.php?action=me', $hdr);
        $d = $r['json']['data'] ?? [];
        $near = static fn(mixed $ms): bool => is_int($ms) && abs($ms - (int) round(microtime(true) * 1000)) < 5000;
        check('me: the old fields are all there, plus netTimeoutSec 20, clockFix true and the server clock (nowMs)', $r['code'] === 200 && isset($d['driver'], $d['trips'], $d['pingSec'], $d['crew']) && ($d['trip']['tripId'] ?? 0) === $tid
            && ($d['netTimeoutSec'] ?? null) === 20 && ($d['clockFix'] ?? null) === true && $near($d['nowMs'] ?? null), substr($r['body'], 0, 160));

        $nowR = time();
        $ping = static fn(array $points, array $more = []): array => $http('/api/driver.php', $hdr, ['action' => 'ping', 'tripId' => $tid, 'points' => $points] + $more);
        $r = $ping([$pt(1, $nowR - 30, -600, 0.500), $pt(2, $nowR - 15, -600, 0.501), $pt(3, $nowR, -600, 0.502)]);
        [$fix, $why] = LiveTrips::fixFor($sid);
        check('ping with the times of a phone 10 min behind (native app, older page): stored as sent, answer keeps ack / stored / moved / pingSec',
            $r['code'] === 200 && ($r['json']['data']['ack'] ?? 0) === 3 && ($r['json']['data']['stored'] ?? 0) === 3 && isset($r['json']['data']['moved'], $r['json']['data']['pingSec'])
            && $fix === null && str_contains($why, 'min old'), $why . ' ' . substr($r['body'], 0, 120));
        check('...the answer carries the two switches and the server clock for the app', ($r['json']['data']['netTimeoutSec'] ?? null) === 20 && ($r['json']['data']['clockFix'] ?? null) === true && $near($r['json']['data']['nowMs'] ?? null));

        $nowR = time();
        $r = $ping([$pt(4, $nowR - 20, 0, 0.503), $pt(5, $nowR - 10, 0, 0.504), $pt(6, $nowR, 0, 0.505)], ['sentAt' => ($nowR - 600) * 1000]);
        [$fix, $why, $age] = LiveTrips::fixFor($sid);
        check('ping with real times (what the page sends once it knows its clock): the coach is live; a field the door does not know is ignored',
            $r['code'] === 200 && ($r['json']['data']['moved'] ?? false) === true && $fix !== null && $age !== null && $age < 30, $why . ' age ' . var_export($age, true));

        $setting('driver_clock_fix_on', '0', 'bool');
        $r  = $ping([$pt(7, time(), 0, 0.506)]);
        $d0 = $http('/api/driver.php?action=me', $hdr)['json']['data'] ?? [];
        check('driver_clock_fix_on = 0: the app is told, and no server clock is in the answers (the app sends its own times, as before)', $r['code'] === 200
            && ($r['json']['data']['clockFix'] ?? null) === false && !array_key_exists('nowMs', $r['json']['data'] ?? ['nowMs' => 1]) && ($r['json']['data']['ack'] ?? 0) === 7
            && ($d0['clockFix'] ?? null) === false && !array_key_exists('nowMs', $d0) && isset($d0['driver']), substr($r['body'], 0, 160));
        $setting('driver_clock_fix_on', '1', 'bool');

        $setting('driver_net_timeout_sec', '500', 'int');
        $a = $http('/api/driver.php?action=me', $hdr)['json']['data']['netTimeoutSec'] ?? null;
        $setting('driver_net_timeout_sec', '0', 'int');
        $b = $http('/api/driver.php?action=me', $hdr)['json']['data']['netTimeoutSec'] ?? null;
        check('driver_net_timeout_sec: never above 120 s, 0 = off', $a === 120 && $b === 0, var_export($a, true) . ' / ' . var_export($b, true));
        $setting('driver_net_timeout_sec', '20', 'int');

        /* the passenger page */
        $kNp = Ticket::trackToken($pnrNp); $kIn = Ticket::trackToken($pnrIn);
        $bad  = $http('/track.php?pnr=' . urlencode($pnrNp) . '&k=wrongkey0000000000xx');
        $none = $http('/track.php?pnr=' . FX_TAG . 'NOSUCH00&k=wrongkey0000000000xx');
        check('wrong key on a real Nepal ticket: 404, the default language (not Nepali), no script', $bad['code'] === 404 && str_contains($bad['body'], '<html lang="hi">')
            && str_contains($bad['body'], '🔒') && !str_contains($bad['body'], 'whereis.php'), (string) $bad['code']);
        check('...byte for byte the page an unknown PNR gets (no way to tell which PNRs exist)',
            $none['code'] === 404 && str_replace($pnrNp, 'X', $bad['body']) === str_replace(FX_TAG . 'NOSUCH00', 'X', $none['body']));
        $ok = $http('/track.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp);
        check('right key, Nepal ticket (country code 977): 200 and Nepali first', $ok['code'] === 200 && str_contains($ok['body'], '<html lang="ne">') && str_contains($ok['body'], 'id="headCard"'), (string) $ok['code']);
        check('...the Nepal-time note is on the page', str_contains($ok['body'], 'नेपाली समय = IST + १५ मिनेट'));
        check('...the stale limit and the three new lines reach the script', str_contains($ok['body'], 'var STALE = 60,') && str_contains($ok['body'], 'लाइभ होइन।') && str_contains($ok['body'], '"lastSeen"')
            && !preg_match('/(Warning|Notice|Fatal error|Deprecated)<\/b>:|(Warning|Notice|Fatal error|Deprecated): /', $ok['body']));
        $in = $http('/track.php?pnr=' . urlencode($pnrIn) . '&k=' . $kIn);
        check('right key, India ticket: Hindi first as before', $in['code'] === 200 && str_contains($in['body'], '<html lang="hi">'));

        $en = $http('/track.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp . '&lang=en');
        $js = $inline(preg_replace('#<script[^>]*src=[^>]*></script>#', '', $en['body']) ?? '');
        check('the rendered page script is found', $en['code'] === 200 && str_contains($js, 'var STALE = 60'));
        $syntax('track.php inline script (rendered)', $js);

        $w = $http('/api/whereis.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp);
        check('api/whereis.php: the answer as before + staleSec 60', $w['code'] === 200 && ($w['json']['ok'] ?? false) === true && isset($w['json']['data']['state'], $w['json']['data']['timeline'])
            && ($w['json']['data']['staleSec'] ?? null) === 60, substr($w['body'], 0, 120));
        $w = $http('/api/whereis.php?pnr=' . urlencode($pnrNp) . '&k=wrongkey0000000000xx');
        check('api/whereis.php wrong key: our own 404 JSON (what the page reloads on)', $w['code'] === 404 && ($w['json']['ok'] ?? null) === false);

        $setting('track_stale_sec', '0', 'int');
        $en0 = $http('/track.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp . '&lang=en');
        $js0 = $inline(preg_replace('#<script[^>]*src=[^>]*></script>#', '', $en0['body']) ?? '');
        $w0  = $http('/api/whereis.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp)['json']['data']['staleSec'] ?? null;
        $setting('track_stale_sec', '10', 'int');
        $en10 = $http('/track.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp . '&lang=en');
        $w10  = $http('/api/whereis.php?pnr=' . urlencode($pnrNp) . '&k=' . $kNp)['json']['data']['staleSec'] ?? null;
        check('track_stale_sec = 0: printed as 0 (off) on the page and in the API', str_contains($js0, 'var STALE = 0,') && $w0 === 0, var_export($w0, true));
        check('track_stale_sec = 10: never under 30 s (the page asks every 15 s)', str_contains($en10['body'], 'var STALE = 30,') && $w10 === 30, var_export($w10, true));
        $setting('track_stale_sec', '60', 'int');

        if ($node !== null) {
            file_put_contents($tmp . DIRECTORY_SEPARATOR . 'track.js', $js);
            file_put_contents($tmp . DIRECTORY_SEPARATOR . 'track0.js', $js0);
        }
        $live = $ROOT . '/assets/js/track-live.js';
        $browser('track.php when the passenger\'s phone loses the network (fake browser)', 'track', $tmp . DIRECTORY_SEPARATOR . 'track.js', $live);
        $browser('track.php, track_stale_sec = 0 (fake browser)', 'track0', $tmp . DIRECTORY_SEPARATOR . 'track0.js', $live);
    }
} catch (Throwable $e) {
    check('database / HTTP part threw: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    RouteGeo::$enabled = true;
    try {
        $restore();
        $cleanup();
        $left = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE pnr LIKE '" . FX_TAG . "%'", [], 0)
              + (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE travel_date = :a', ['a' => FX_DATE], 0)
              + (int) Database::scalar('SELECT COUNT(*) FROM drivers WHERE phone = :p', ['p' => FX_PHONE], 0);
        check('nothing left behind', $left === 0);
        $same = true;
        foreach ($touched as $k) {
            $nowRow = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
            $same = $same && (($saved[$k]['svalue'] ?? null) === ($nowRow['svalue'] ?? null));
        }
        check('the three settings are back exactly as they were', $same);
    } catch (Throwable $e) {
        check('clean-up', false, $e->getMessage());
    }
    $rmTmp();
}
finish();
