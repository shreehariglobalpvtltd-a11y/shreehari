<?php
/**
 * =====================================================================
 *  u8-W2-offline-test.php — P2-4e + P2-4g (16 Nov 2026): the offline
 *  call-back request on the SmartBook card and the "N queued" count on
 *  the offline pill. Switch offline_callback_on, default OFF.
 *
 *  Static part (no database):
 *    - 27-smartbook.js: the switch gate (customer face only), the
 *      call-back button replaces Book only while navigator.onLine is
 *      false, the request goes into the existing queue 'shg:cq' with
 *      source 'callback' and the note "Offline booking request: <from>
 *      -> <to> <date> x<seats>", the boot success / failure hooks; the
 *      words exist in en / hi / ne with the same keys and every
 *      language says it is NOT a ticket
 *    - 16-lazy.js: the flush (node, in a sandbox): one flush at a time,
 *      sent items leave the CURRENT queue (a request saved meanwhile is
 *      kept), 400 / 422 dropped, offline / 429 kept, a sent call-back
 *      shows one toast, every change fires 'shg:cq'
 *    - 13-admin-routes.js: the pill's count is 0 with the switch off,
 *      the queue length with it on, 0 on broken JSON (node)
 *    - the card's mobile check (node): India / Nepal rules as the card
 *    - 04-i18n.js netQueued / cbSent in en / hi / ne, index.php puts the
 *      switch in SHG_BOOT.settings, the migration is additive and
 *      apply-sql safe
 *
 *  Database part (shari_test only; every row it makes is deleted):
 *    - api/enquiry.php takes source 'callback' with date + seats + note
 *      (Enquiries row + a Help Desk number), a past or broken travel date
 *      on a call-back is kept as a request without a date (the note keeps
 *      it) instead of a 422 that would jam the phone's queue, a Nepal
 *      number is kept with 977; quick_booking with a past date is still
 *      422, a bad mobile is still 422, no CSRF is 419, GET is 405
 *
 *    php -c .claude/php-dev.ini tests/u8-W2-offline-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: one API request in its own PHP process ---------------- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']       = 'localhost';
    $_SERVER['REMOTE_ADDR']     = (string) ($cfg['ip'] ?? '203.0.113.80');
    $_SERVER['HTTP_USER_AGENT'] = 'u8-W2-offline-test';
    $_SERVER['REQUEST_URI']     = '/api/enquiry.php';
    $_SERVER['SCRIPT_NAME']     = '/api/enquiry.php';
    $_SERVER['REQUEST_METHOD']  = (string) ($cfg['method'] ?? 'POST');
    $_SERVER['CONTENT_TYPE']    = 'application/x-www-form-urlencoded';
    $_GET  = [];
    $_POST = (array) ($cfg['post'] ?? []);
    $_SESSION = [(string) $cfg['csrf_name'] => 'u8w2-session-token'];
    if (($cfg['csrf'] ?? true) !== false) {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'u8w2-session-token';
    }
    require dirname(__DIR__) . '/api/enquiry.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
require_once $ROOT . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/helpdesk.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL;
    echo "\n  $PASS passed, $FAIL failed\n";
    exit($FAIL === 0 ? 0 : 1);
}
function src(string $rel): string {
    return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/' . $rel));
}
/** Text between two markers (both must exist, in order). */
function between(string $s, string $a, string $b): string {
    $i = strpos($s, $a);
    if ($i === false) { return ''; }
    $j = strpos($s, $b, $i + strlen($a));
    return $j === false ? '' : substr($s, $i, $j - $i);
}
function child(array $cfg): array {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ['raw' => 'proc_open failed']; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $j = json_decode(trim($out), true);
    return is_array($j) ? $j : ['raw' => substr($out . $err, 0, 400)];
}
/** Run a node script; returns [label, ok, extra] rows, or null when node is missing. */
function nodeRun(string $js, array $args = []): ?array {
    $node = trim((string) @shell_exec(stripos(PHP_OS, 'WIN') === 0 ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
    $node = $node !== '' ? strtok($node, "\r\n") : '';
    if ($node === '' || $node === false) { return null; }
    $f = tempnam(sys_get_temp_dir(), 'u8w2') . '.js';
    file_put_contents($f, $js);
    $cmd = escapeshellarg($node) . ' ' . escapeshellarg($f);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $out = (string) shell_exec($cmd . ' 2>&1');
    @unlink($f);
    $rows = json_decode(trim($out), true);
    return is_array($rows) ? $rows : [['node script ran', false, substr($out, 0, 300)]];
}

echo "\n=== W2 offline call-back request (P2-4e + P2-4g) ===\n\n";

$sb   = src('assets/js/27-smartbook.js');
$lazy = src('assets/js/16-lazy.js');
$adm  = src('assets/js/13-admin-routes.js');
$i18n = src('assets/js/04-i18n.js');
$idx  = src('index.php');
$enq  = src('api/enquiry.php');
$mig  = src('database/upgrade-2026-11-16b-W2-offline.sql');

/* ---- 1. The card (static) ---------------------------------------------- */
echo "-- SmartBook card (27-smartbook.js)\n";
$cb = between($sb, '  /* ------------------------------------------------ Offline call-back request', '  window.SmartBook = {');
check('the call-back block exists', $cb !== '');
check('switch gate reads SHG_BOOT.settings.offline_callback_on', str_contains($cb, "((window.SHG_BOOT && window.SHG_BOOT.settings) || {}).offline_callback_on"));
check('customer face only (staff desk / DESK never)', str_contains($cb, 'if (STAFF || DESK) return false;'));
check('offline = navigator.onLine === false', str_contains($cb, 'navigator.onLine === false'));
check('cbSync / cbCount / online do nothing with the switch off',
    str_contains($cb, "function cbSync() {\n    if (!cbOn()) return;")
    && str_contains($cb, "function cbCount() {\n    if (!cbOn()) return;")
    && str_contains($cb, "window.addEventListener('online', function () {\n    if (!cbOn()) return;"));
check('Book buttons hidden only while offline (and back when online)', str_contains($cb, "['sbCash', 'sbOnline'].forEach(function (id) { var b = \$(id); if (b) b.style.display = off ? 'none' : ''; });"));
check('the queue is the existing complaint queue shg:cq', str_contains($cb, "var CQ = 'shg:cq'") && str_contains($lazy, "QKEY: 'shg:cq'"));
check("item source is 'callback'", str_contains($cb, "source: 'callback'"));
check('note = Offline booking request: <from> -> <to> <date> x<seats>',
    str_contains($cb, "var note = 'Offline booking request: ' + from + ' -> ' + to + ' ' + (date || 'any date') + ' x' + seats;"));
check('date / seats / name / mobile / route fields in the sheet',
    str_contains($cb, 'id="sbCbDate"') && str_contains($cb, 'id="sbCbSeats"') && str_contains($cb, 'id="sbCbName"')
    && str_contains($cb, 'id="sbCbPhone"') && str_contains($cb, 'id="sbCbDir"') && str_contains($cb, 'id="sbCbPt"'));
check('the card itself never posts (16-lazy.js flushes the queue)', !str_contains($cb, 'shgApi.post') && !str_contains($cb, 'fetch('));
check('no booking path is called from the call-back block', !preg_match('/\bbook\(|api\(\{ *action: *.book/', $cb));
check('boot success arms it, boot failure offers the panel',
    str_contains($sb, "cbBooted = true; cbSync();") && str_contains($sb, "cbFailed = true; cbSync();"));
check('a panel from a failed offline boot boots again once online', str_contains($cb, 'if (retry) { cbFailed = false; boot(); }'));
check('every user string is escaped into the sheet (no raw tr() in innerHTML)', !preg_match("/innerHTML = [^;]*\\+ tr\\(/", $cb));
check('an empty queue hides the "⏳ N waiting" line even where CSS sets display:block', str_contains($cb, "el.hidden = !n; el.style.display = n ? '' : 'none';"));

/* the words: same keys in en / hi / ne, and each says "not a ticket" */
$cw = between($sb, '  var CW = {', "\n  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in CW[l])");
check('CW words block found', $cw !== '');
$nodeCw = nodeRun(<<<'JS'
const fs = require('fs'), vm = require('vm');
const s = fs.readFileSync(process.argv[2], 'utf8').replace(/\r\n/g, '\n');
const a = s.indexOf('  var CW = {'), b = s.indexOf("\n  ['en', 'hi', 'ne'].forEach(function (l) { if (W[l]) { for (var k in CW[l])");
const out = [];
try {
  const ctx = {}; vm.createContext(ctx); vm.runInContext(s.slice(a, b).replace('var CW', 'globalThis.CW'), ctx);
  const CW = ctx.CW, en = Object.keys(CW.en).sort().join(','), langs = ['en', 'hi', 'ne'];
  out.push(['CW parses', true, Object.keys(CW.en).length + ' keys']);
  langs.forEach(l => out.push(['CW.' + l + ' has the same keys as en', Object.keys(CW[l]).sort().join(',') === en]));
  langs.forEach(l => out.push(['CW.' + l + ' has no empty string', Object.values(CW[l]).every(v => typeof v === 'string' && v.trim() !== '')]));
  const not = { en: /not a ticket/i, hi: /टिकट नहीं/, ne: /टिकट होइन/ };
  langs.forEach(l => ['cbBtnSub', 'cbNotT', 'cbOffSub', 'cbDoneSub', 'cbDup'].forEach(k =>
    out.push(['CW.' + l + '.' + k + ' says it is not a ticket', not[l].test(CW[l][k] || ''), CW[l][k]])));
  langs.forEach(l => out.push(['CW.' + l + '.cbDoneSub names the number ({p}) and cbWait the count ({n})', CW[l].cbDoneSub.includes('{p}') && CW[l].cbWait.includes('{n}')]));
} catch (e) { out.push(['CW parses', false, String(e)]); }
console.log(JSON.stringify(out));
JS, [$ROOT . '/assets/js/27-smartbook.js']);
if ($nodeCw === null) {
    echo "  \033[33mSKIP\033[0m  node not found — CW words not evaluated\n";
} else {
    foreach ($nodeCw as $r) { check((string) $r[0], (bool) $r[1], isset($r[2]) ? (string) $r[2] : ''); }
}

/* the card's mobile rules (same as phoneErr) */
$nodePh = nodeRun(<<<'JS'
const fs = require('fs'), vm = require('vm');
const s = fs.readFileSync(process.argv[2], 'utf8').replace(/\r\n/g, '\n');
const d = s.slice(s.indexOf('  function digits(s)'), s.indexOf('\n', s.indexOf('  function digits(s)')));
const p = s.slice(s.indexOf('  function cbPhone(cc, raw) {'), s.indexOf('\n  function cbStamp'));
const ctx = {}; vm.createContext(ctx); vm.runInContext(d + '\n' + p + '\nglobalThis.cbPhone = cbPhone;', ctx);
const cases = [['IN', '98765 43210', '9876543210'], ['IN', '+91 98765 43210', '9876543210'], ['IN', '09876543210', '9876543210'],
  ['IN', '5876543210', ''], ['IN', '9999999999', ''], ['IN', '98765', ''], ['NP', '9812345678', '9779812345678'],
  ['NP', '+977 981-234-5678', '9779812345678'], ['NP', '9612345678', '9779612345678'], ['NP', '9512345678', ''], ['NP', '9888888888', '9779888888888']];
console.log(JSON.stringify(cases.map(c => { const r = ctx.cbPhone(c[0], c[1]); return ['mobile ' + c[0] + ' "' + c[1] + '" -> "' + c[2] + '"', r === c[2], r]; })));
JS, [$ROOT . '/assets/js/27-smartbook.js']);
if ($nodePh !== null) { foreach ($nodePh as $r) { check((string) $r[0], (bool) $r[1], isset($r[2]) ? (string) $r[2] : ''); } }

/* ---- 2. The flush (16-lazy.js) ----------------------------------------- */
echo "\n-- Queue flush (16-lazy.js)\n";
check('flush: one at a time (a call mid-flush asks for one more pass)', str_contains($lazy, "flush() {\n      if (this.busy) { this.again = true; return; }"));
check('flush: drops only 400 / 422', str_contains($lazy, 'if (st === 400 || st === 422) { this.take(item); qd.shift(); next(); return; }'));
check('flush: exposed for the card', str_contains($lazy, 'window.shgCqFlush = function () { Complaint.flush(); };'));
check("flush: announces 'shg:cq'", str_contains($lazy, "window.dispatchEvent(new CustomEvent('shg:cq'))"));
$nodeFl = nodeRun(<<<'JS'
const fs = require('fs'), vm = require('vm');
const s = fs.readFileSync(process.argv[2], 'utf8').replace(/\r\n/g, '\n');
const a = s.indexOf('    busy: false,'), b = s.indexOf("\n  };\n  window.addEventListener('online', function () { Complaint.flush(); });");
const body = s.slice(a, b), out = [];
function mk(queue, answer) {
  const store = { 'shg:cq': JSON.stringify(queue) }, env = { events: 0, toasts: [], posts: [] };
  const ctx = {
    localStorage: { getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); } },
    window: { dispatchEvent: () => { env.events++; } }, CustomEvent: function (n) { this.type = n; },
    toast: m => env.toasts.push(m), t: k => (k === 'cbSent' ? 'SENT' : k),
    shgApi: { post: (path, item) => { env.posts.push([path, item]); return answer(item, env.posts.length); } }
  };
  vm.createContext(ctx); vm.runInContext('globalThis.C = { QKEY: "shg:cq",\n' + body + '\n};', ctx);
  return { C: ctx.C, env, store, q: () => JSON.parse(store['shg:cq']) };
}
const ok = d => Promise.resolve(d || {}), no = st => Promise.reject(Object.assign(new Error('x'), st ? { status: st } : {}));
const wait = () => new Promise(r => setTimeout(r, 30));
const A = { name: 'A', phone: '9876543210', source: 'callback', note: 'Offline booking request: Surat -> Rupaidiha 2026-12-01 x2' };
const B = { name: 'B', phone: '9876543211', source: 'complaint', note: '[SHG-C-1] late' };
const X = { name: 'X', phone: '9876543212', source: 'callback', note: 'Offline booking request: Vadodara -> Rupaidiha any date x1' };
(async () => {
  let t = mk([A, B], (it) => it.name === 'A' ? ok({ ticket: { ref: 'HD-0042' } }) : no(422));
  t.C.flush(); await wait();
  out.push(['sent + refused-for-good both leave the queue', t.q().length === 0, JSON.stringify(t.q())]);
  out.push(['each item posted once to /enquiry.php', t.env.posts.length === 2 && t.env.posts.every(p => p[0] === '/enquiry.php')]);
  out.push(['a sent call-back shows ONE toast with the help desk number', t.env.toasts.length === 1 && t.env.toasts[0] === 'SENT · HD-0042', JSON.stringify(t.env.toasts)]);
  out.push(["every change fires 'shg:cq'", t.env.events >= 2, String(t.env.events)]);
  out.push(['flush free again after it ends', t.C.busy === false]);

  t = mk([A], () => ok({})); t.C.flush(); t.C.flush(); t.C.flush(); await wait();
  out.push(['three flushes at once post the item once', t.env.posts.length === 1, String(t.env.posts.length)]);

  let release; t = mk([A], () => new Promise(r => { release = r; }));
  t.C.flush(); t.store['shg:cq'] = JSON.stringify([A, X]); release({}); await wait();
  out.push(['a request saved while a flush runs is kept (and then sent too)', t.env.posts.length === 1 ? JSON.stringify(t.q()) === JSON.stringify([X]) : t.q().length === 0, JSON.stringify(t.q())]);

  /* review (16 Nov): the card saves + calls flush while one is running -> sent by a second pass, not left until the next visit */
  let rel2; t = mk([A], (it) => it.name === 'A' ? new Promise(r => { rel2 = r; }) : ok({}));
  t.C.flush(); t.store['shg:cq'] = JSON.stringify([A, X]); t.C.flush(); rel2({}); await wait();
  out.push(['a flush asked for mid-flush runs once more: the new request is sent too', t.env.posts.length === 2 && t.env.posts[1][1].name === 'X' && t.q().length === 0 && t.C.busy === false, t.env.posts.length + ' ' + JSON.stringify(t.q())]);
  t = mk([A], () => no(0)); t.C.flush(); t.C.flush(); await wait();
  out.push(['offline: no extra pass loops (one post, item kept)', t.env.posts.length === 1 && t.q().length === 1, String(t.env.posts.length)]);

  t = mk([A, B], () => no(0)); t.C.flush(); await wait();
  out.push(['offline (no answer): nothing lost, stops after the first try', t.q().length === 2 && t.env.posts.length === 1]);
  t = mk([A, B], () => no(429)); t.C.flush(); await wait();
  out.push(['429 rate limit: kept for next time', t.q().length === 2 && t.C.busy === false]);
  t = mk([B], () => ok({ ticket: { ref: 'HD-9' } })); t.C.flush(); await wait();
  out.push(['a complaint flush shows no call-back toast', t.env.toasts.length === 0 && t.q().length === 0]);
  t = mk([], () => ok({})); t.C.flush(); await wait();
  out.push(['empty queue: no request', t.env.posts.length === 0]);
  console.log(JSON.stringify(out));
})();
JS, [$ROOT . '/assets/js/16-lazy.js']);
if ($nodeFl === null) {
    echo "  \033[33mSKIP\033[0m  node not found — flush not exercised\n";
} else {
    foreach ($nodeFl as $r) { check((string) $r[0], (bool) $r[1], isset($r[2]) ? (string) $r[2] : ''); }
}

/* ---- 3. The pill (13-admin-routes.js) ---------------------------------- */
echo "\n-- Offline pill count (13-admin-routes.js)\n";
check('pill listens to shg:cq and other tabs', str_contains($adm, "window.addEventListener('shg:cq', refresh);") && str_contains($adm, "e.key === 'shg:cq'"));
check("pill text keeps the old line and adds ' · N queued'", str_contains($adm, "el.textContent = '📵 Offline — cached data · अफलाइन'") && str_contains($adm, "tf('netQueued', { n: n })"));
$nodePl = nodeRun(<<<'JS'
const fs = require('fs'), vm = require('vm');
const s = fs.readFileSync(process.argv[2], 'utf8').replace(/\r\n/g, '\n');
const f = s.slice(s.indexOf('  function queued() {'), s.indexOf('\n  function refresh() {'));
function run(on, raw) {
  const ctx = { window: { SHG_BOOT: { settings: { offline_callback_on: on } } }, localStorage: { getItem: () => raw } };
  vm.createContext(ctx); vm.runInContext(f + '\nglobalThis.n = queued();', ctx); return ctx.n;
}
const two = JSON.stringify([{ a: 1 }, { b: 2 }]);
console.log(JSON.stringify([
  ['switch off: no count even with items waiting', run(false, two) === 0],
  ['switch on: counts the queue', run(true, two) === 2],
  ["switch on as '1' / 1 also counts", run('1', two) === 2 && run(1, two) === 2],
  ['broken JSON counts 0 (never throws)', run(true, '{oops') === 0],
  ['not an array counts 0', run(true, '{"a":1}') === 0],
  ['nothing saved counts 0', run(true, null) === 0]
]));
JS, [$ROOT . '/assets/js/13-admin-routes.js']);
if ($nodePl !== null) { foreach ($nodePl as $r) { check((string) $r[0], (bool) $r[1]); } }

/* ---- 4. i18n, boot payload, migration ---------------------------------- */
echo "\n-- i18n, boot payload, migration\n";
check('netQueued in en / hi / ne', substr_count($i18n, 'netQueued: ') === 3 && str_contains($i18n, "netQueued: '{n} queued'"));
check('cbSent in en / hi / ne, each says not a ticket',
    preg_match_all("/cbSent: '[^']*(not a ticket|टिकट नहीं|टिकट होइन)[^']*'/u", $i18n) === 3);
$chk = nodeRun(<<<'JS'
const { execFileSync } = require('child_process');
let ok = true, out = '';
try { out = execFileSync(process.execPath, [process.argv[2]], { encoding: 'utf8' }); } catch (e) { ok = false; out = String(e.stdout || e.message); }
console.log(JSON.stringify([['tests/i18n-check.js still parses 04-i18n.js and finds every key in en / hi / ne', ok, ok ? '' : out.slice(-200)]]));
JS, [$ROOT . '/tests/i18n-check.js']);
if ($chk !== null) { foreach ($chk as $r) { check((string) $r[0], (bool) $r[1], (string) ($r[2] ?? '')); } }
check('index.php puts the switch in SHG_BOOT.settings (default off)',
    str_contains($idx, "\$boot['settings']['offline_callback_on'] = Settings::getBool('offline_callback_on', false);"));
check('migration: INSERT IGNORE, default 0, bool, app group', str_contains($mig, 'INSERT IGNORE INTO settings') && str_contains($mig, "('offline_callback_on', '0', 'bool', 'app',"));
$stmt = preg_replace('/^\s*--.*$/m', '', $mig) ?? '';
check('migration: no ; inside the values (tests/apply-sql.php splits on ;)', count(array_filter(array_map('trim', explode(';', $stmt)))) === 2);
if (preg_match("/'app', '([^']*)'/", $mig, $m) === 1) { check('migration: label fits varchar(191)', mb_strlen($m[1]) <= 191, (string) mb_strlen($m[1])); }
check('enquiry.php: a call-back keeps a past date as no date (other sources still 422)',
    str_contains($enq, "if (Response::field('source', '') !== 'callback') {\n            Response::invalid(['travelDate' => 'Choose a valid travel date.']);\n        }\n        \$travelDate = '';"));

/* ---- 5. api/enquiry.php (database) ------------------------------------- */
echo "\n-- api/enquiry.php (database)\n";
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    echo "  \033[33mSKIP\033[0m  database part: DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$drv = Settings::getString('whatsapp_driver', 'click_to_chat');
if ($drv !== 'click_to_chat' || Settings::getBool('sms_enabled', false) || Settings::getString('aisensy_api_key', '') !== '') {
    echo "  \033[33mSKIP\033[0m  database part: this database could send a real WhatsApp / SMS (help desk ack) — refusing\n";
    finish();
}

$rnd   = static fn (): string => (string) random_int(10000000, 99999999);
$phIn  = '98' . $rnd();          // India 10 digits
$phIn2 = '97' . $rnd();
$phNp  = '97798' . $rnd();       // Nepal, 977 + 10 digits
$phBad = '12345';
$PHONES = [$phIn, $phIn2, $phNp];
$IPS = [];
$ipN = 0;
$ip = static function () use (&$IPS, &$ipN): string { $ipN++; $a = '203.0.113.' . (150 + $ipN); $IPS[] = $a; return $a; };
$startAudit = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$startMsg   = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
$startNote  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
$csrfName   = CSRF_TOKEN_NAME;

$cleanup = static function () use (&$PHONES, &$IPS, $startAudit, $startMsg, $startNote): void {
    foreach ($PHONES as $ph) {
        // the help desk keeps an Indian number as 91 + 10 digits
        $tix = Database::fetchAll("SELECT id, ticket_ref FROM support_tickets WHERE RIGHT(phone, 10) = :p AND name LIKE 'U8W2 %'", ['p' => substr($ph, -10)]);
        foreach ($tix as $t) {
            $like = '%' . $t['ticket_ref'] . '%';
            Database::delete('support_messages', 'ticket_id = :t', ['t' => (int) $t['id']]);
            Database::delete('audit_logs', 'id > :s AND entity_id = :r', ['s' => $startAudit, 'r' => (string) $t['ticket_ref']]);
            Database::delete('notifications', 'id > :s AND (body LIKE :r1 OR title LIKE :r2 OR link LIKE :r3)', ['s' => $startNote, 'r1' => $like, 'r2' => $like, 'r3' => $like]);
            Database::delete('support_tickets', 'id = :t', ['t' => (int) $t['id']]);
        }
        Database::delete('enquiries', 'phone = :p', ['p' => $ph]);
        Database::delete('message_logs', 'id > :s AND to_number LIKE :p', ['s' => $startMsg, 'p' => '%' . substr($ph, -10)]);
    }
    foreach ($IPS as $a) {
        Database::delete('rate_limits', "bucket = 'enquiry_create' AND identifier = :i", ['i' => $a]);
    }
};

$future = date('Y-m-d', strtotime(todayISO() . ' +3 days'));
$past   = date('Y-m-d', strtotime(todayISO() . ' -1 day'));
$post   = static fn (array $p, array $extra = []) => child(['post' => $p, 'ip' => $ip(), 'csrf_name' => $csrfName] + $extra);

try {
    // 1. the request a phone queued offline, sent when the network is back
    $note1 = 'Offline booking request: Surat -> Rupaidiha ' . $future . ' x2 · saved ' . todayISO() . ' 10:05';
    $r = $post(['name' => 'U8W2 Ram', 'phone' => $phIn, 'country' => 'IN', 'seats' => '2', 'source' => 'callback', 'lang' => 'ne', 'note' => $note1, 'travelDate' => $future]);
    check('callback with date + seats is accepted', ($r['ok'] ?? false) === true, json_encode($r['error'] ?? ($r['raw'] ?? '')) ?: '');
    $row = Database::fetch('SELECT * FROM enquiries WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => $phIn]);
    check('Enquiries row: source callback', ($row['source'] ?? '') === 'callback');
    check('Enquiries row: travel date + seats', ($row['travel_date'] ?? '') === $future && (int) ($row['seats'] ?? 0) === 2, ($row['travel_date'] ?? 'null') . ' x' . ($row['seats'] ?? '?'));
    check('Enquiries row: the note as sent', ($row['note'] ?? '') === $note1, (string) ($row['note'] ?? ''));
    check('Enquiries row: status new (the office follows up)', ($row['status'] ?? '') === 'new');
    check('the answer says the office will call', str_contains((string) ($r['message'] ?? ''), 'office will call'), (string) ($r['message'] ?? ''));
    if (HelpDesk::on()) {
        $tk = $r['data']['ticket'] ?? null;
        check('a Help Desk number comes back', is_array($tk) && preg_match('/^HD-/', (string) ($tk['ref'] ?? '')) === 1, (string) ($tk['ref'] ?? 'none'));
        $st = Database::fetch('SELECT * FROM support_tickets WHERE RIGHT(phone, 10) = :p ORDER BY id DESC LIMIT 1', ['p' => $phIn]);
        check('Help Desk ticket: callback, carries the request note', ($st['source'] ?? '') === 'callback' && str_contains((string) ($st['message'] ?? ''), 'Offline booking request: Surat -> Rupaidiha'), (string) ($st['message'] ?? ''));
    }

    // 2. sent after its travel day: kept, without a date (the note keeps it)
    $note2 = 'Offline booking request: Rupaidiha -> Ahmedabad ' . $past . ' x1 · saved ' . $past . ' 21:40';
    $r = $post(['name' => 'U8W2 Sita', 'phone' => $phIn2, 'seats' => '1', 'source' => 'callback', 'note' => $note2, 'travelDate' => $past]);
    check('callback with a PAST date is accepted (no 422 to jam the queue)', ($r['ok'] ?? false) === true, json_encode($r['error'] ?? ($r['raw'] ?? '')) ?: '');
    $row = Database::fetch('SELECT * FROM enquiries WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => $phIn2]);
    check('... stored with no travel date, the note keeps the date', $row !== null && $row['travel_date'] === null && str_contains((string) $row['note'], $past));

    $r = $post(['name' => 'U8W2 Sita', 'phone' => $phIn2, 'source' => 'callback', 'note' => 'Offline booking request: x', 'travelDate' => '2026-13-45']);
    check('callback with a broken date is accepted without a date', ($r['ok'] ?? false) === true && array_key_exists('travelDate', (array) ($r['data'] ?? [])) && $r['data']['travelDate'] === null);

    // 3. a Nepal number keeps its 977
    $r = $post(['name' => 'U8W2 Hari', 'phone' => $phNp, 'country' => 'NP', 'seats' => '3', 'source' => 'callback', 'note' => 'Offline booking request: Rupaidiha -> Surat ' . $future . ' x3', 'travelDate' => $future]);
    $row = Database::fetch('SELECT * FROM enquiries WHERE phone = :p ORDER BY id DESC LIMIT 1', ['p' => $phNp]);
    check('Nepal call-back stored with 977 + seats 3', ($r['ok'] ?? false) === true && $row !== null && (int) $row['seats'] === 3);

    // 4. nothing else got looser
    $before = (int) Database::scalar('SELECT COUNT(*) FROM enquiries WHERE phone = :p', ['p' => $phIn], 0);
    $r = $post(['name' => 'U8W2 Ram', 'phone' => $phIn, 'source' => 'quick_booking', 'travelDate' => $past]);
    check('quick_booking with a past date is still 422', ($r['ok'] ?? true) === false && (int) ($r['code'] ?? 0) === 422 && isset($r['fields']['travelDate']));
    $r = $post(['name' => 'U8W2 Bad', 'phone' => $phBad, 'source' => 'callback', 'travelDate' => $future]);
    check('callback with a bad mobile is still 422', ($r['ok'] ?? true) === false && isset($r['fields']['phone']));
    $r = $post(['name' => 'U8W2 Ram', 'phone' => $phIn, 'source' => 'callback'], ['csrf' => false]);
    check('callback without the CSRF token is 419', ($r['ok'] ?? true) === false && (int) ($r['code'] ?? 0) === 419);
    $r = $post([], ['method' => 'GET']);
    check('GET is 405', ($r['ok'] ?? true) === false && (int) ($r['code'] ?? 0) === 405);
    $after = (int) Database::scalar('SELECT COUNT(*) FROM enquiries WHERE phone = :p', ['p' => $phIn], 0);
    check('the refused requests wrote nothing', $after === $before, "$before -> $after");
    check('a bad mobile wrote nothing', (int) Database::scalar('SELECT COUNT(*) FROM enquiries WHERE phone = :p', ['p' => $phBad], 0) === 0
        || (int) Database::scalar("SELECT COUNT(*) FROM enquiries WHERE phone = :p AND name = 'U8W2 Bad'", ['p' => $phBad], 0) === 0);
} catch (Throwable $e) {
    check('database part ran without an exception', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    try { $cleanup(); } catch (Throwable $e) { check('cleanup ran without an exception', false, $e->getMessage()); }
    $left = 0;
    foreach ($PHONES as $ph) {
        $left += (int) Database::scalar('SELECT COUNT(*) FROM enquiries WHERE phone = :p', ['p' => $ph], 0)
               + (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE RIGHT(phone, 10) = :p AND name LIKE 'U8W2 %'", ['p' => substr($ph, -10)], 0);
    }
    check('cleanup: no test rows left', $left === 0, (string) $left);
}

finish();
