<?php
/**
 * =====================================================================
 *  u9-X2-festival-test.php - 16 Nov 2026 (Master Upgrade v2.0, unit X2)
 *
 *  P5-3 / P5-7b / P5-7c - special dates (festival look), switch
 *  festival_theme_on (default OFF):
 *    1. static: lint, the owner's 13 Nov accent table untouched, the new
 *       ds.css block, the head script / index.php / router wiring, the
 *       migration (both rows OFF / empty, never public)
 *    2. the head accent script, run in node's vm (fake DOM / clock /
 *       storage): today's row paints its accent for Weekly visitors only,
 *       a picked palette / Fixed / the counter / a preview still win, the
 *       row ends at IST midnight, index.php's second apply() pass
 *    3. the notice strip code from 05-router.js, run in node's vm: the
 *       banner (Nepali, then English) only when no office notice shows,
 *       its own ✕ key, it leaves at the end of its last day
 *    4. shari_test: the served page (php index.php) gets ONLY today's row
 *       and only while the switch is on (shorter row wins, then the later
 *       start); admin/themes.php add / edit / delete / Override today /
 *       switch / remove past rows - CSRF, stale page, every validation rule,
 *       30-row cap, a support login cannot write, Logger::audit old / new.
 *       The two settings rows are put back as they were in a finally
 *       block; the test admins and their audit rows are deleted.
 *
 *    php -c .claude/php-dev.ini tests/u9-X2-festival-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: admin/themes.php in its own PHP process ----------------
   argv: --child <base64 json {admin, post?: {...}, csrf?: bool}>; post.ver
   '@auto' = the version of the list as stored now (what the page printed). */
if (($argv[1] ?? '') === '--child') {
    $cfg  = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $post = $cfg['post'] ?? null;
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.67';
    $_SERVER['REQUEST_METHOD'] = is_array($post) ? 'POST' : 'GET';
    $_SERVER['SCRIPT_NAME']    = '/admin/themes.php';
    $_SERVER['REQUEST_URI']    = '/admin/themes.php';
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if (is_array($post)) {
        $_POST = $post;
        if (($cfg['csrf'] ?? true) === true) {
            $_POST[CSRF_TOKEN_NAME] = Security::csrfToken();
        }
        if (($post['ver'] ?? null) === '@auto') {
            $_POST['ver'] = sha1((string) json_encode(Settings::getArray('festival_dates', [])));
        }
    }
    require dirname(__DIR__) . '/admin/themes.php';
    exit(0);
}

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function finish(): never {
    global $PASS, $FAIL, $SKIP;
    echo "\n" . ($FAIL === 0 ? "ALL PASSED: $PASS" . ($SKIP ? " (skipped $SKIP)" : '') : "FAILED: $FAIL (passed $PASS)") . "\n";
    exit($FAIL === 0 ? 0 : 1);
}
function run(string $args): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . $args;
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}
function child(array $cfg): string {
    return run(escapeshellarg(__FILE__) . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg))));
}
$read = static fn(string $p): string => (string) file_get_contents($ROOT . '/' . $p);

echo "\n=== X2 special dates (festival look) ===\n";

/* ---- 1. static ------------------------------------------------------------ */
echo "\n1. Static\n";
foreach (['admin/themes.php', 'index.php', 'tests/u6-staff-chrome-test.php'] as $f) {
    $o = run('-l ' . escapeshellarg($ROOT . '/' . $f));
    check("$f lints", str_contains($o, 'No syntax errors'), trim(substr($o, 0, 160)));
}
exec('node --version 2>&1', $nv, $nodeRc);
$haveNode = $nodeRc === 0;
if ($haveNode) {
    exec('node --check ' . escapeshellarg($ROOT . '/assets/js/05-router.js') . ' 2>&1', $nco, $ncr);
    check('assets/js/05-router.js passes node --check', $ncr === 0, implode(' ', $nco));
}
$tpl = $read('app.template.html');
$ds  = $read('assets/css/ds.css');
$idx = $read('index.php');
$rt  = $read('assets/js/05-router.js');
$th  = $read('admin/themes.php');
$mig = $read('database/upgrade-2026-11-16c-X2-festival.sql');
preg_match_all('~:root:root\[data-theme="(?:light|dark)"\]\[data-accent="[a-z]+"\]\{[^}]*\}~', $ds, $am);
check("the owner's 13 Nov accent table (14 rules) is byte-for-byte unchanged", count($am[0]) === 14 && sha1(implode("\n", $am[0])) === 'd7f04fca59565f7471e44eb3a705db5e04918d25');
check('ds.css: one new festival rule, the strip\'s left edge only', substr_count($ds, '.notice-bar.is-fest') === 1 && str_contains($ds, ':root:root .notice-bar.is-fest{border-left-color:var(--royal)}'));
check('ds.css: no new data-accent key (rows use the seven existing ones)', preg_match_all('~\[data-accent="([a-z]+)"\]~', $ds, $ak) === 14 && array_diff(array_unique($ak[1]), ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']) === []);
check('head script: fest() reads SHG_BOOT.festival + the switch, checks the IST date', str_contains($tpl, 'function fest(now)') && str_contains($tpl, 'B.settings.festival_theme_on') && str_contains($tpl, "toISOString().slice(0, 10)"));
check('head script: Fixed / palette / counter first, then the special date, then the weekday', str_contains($tpl, "want = pv ? pv.key : ((counter || mode() === 'fixed') ? '' : (fest() || day()));"));
check('index.php: switch + festival always in the feed, null / false by default', str_contains($idx, "\$boot['settings']['festival_theme_on'] = false;") && str_contains($idx, "\$boot['festival'] = null;"));
check('index.php: only while the switch is on', str_contains($idx, "if (Settings::getBool('festival_theme_on', false)) {"));
check('index.php: set before SHG_BOOT is encoded', strpos($idx, "\$boot['festival'] = \$shgBest;") < strpos($idx, '$bootJson = Security::jsonForHtml($boot);'));
check('index.php: a second apply() once SHG_BOOT exists', str_contains($idx, 'try { if (window.shgAccent && window.SHG_BOOT && window.SHG_BOOT.festival) window.shgAccent.apply(); } catch (e) {}')
    && strpos($idx, 'window.shgAccent.apply()') > strpos($idx, 'window.SHG_BOOT = {$bootJson};'));
check('router: the banner sits behind the office notice, its own ✕ key', str_contains($rt, "const FEST_KEY = 'shg:festDismissed';") && str_contains($rt, 'function festivalNotice()')
    && str_contains($rt, "if (bar.getAttribute('data-nk') === 'fest') localStorage.setItem(FEST_KEY"));
check('router: text set as text, never HTML', str_contains($rt, 'txt.textContent = n.text') && !preg_match('~innerHTML\s*=\s*[^;]*\bf\.(ne|en)\b~', $rt));
check('themes.php: writes need Auth::canManageSettings + CSRF + a fresh list', str_contains($th, '$canEdit = Auth::canManageSettings();') && str_contains($th, 'Security::verifyCsrf()') && str_contains($th, "hash_equals(\$spVer, (string) (\$_POST['ver'] ?? ''))"));
check('themes.php: every write is audited (add / edit / delete / today / switch / prune)', substr_count($th, "Logger::audit('theme.special_") === 5 && str_contains($th, "'theme.special_' . \$act"));
check('themes.php: nothing prefilled (no festival names or dates in the code)', !preg_match('~20\d\d-\d\d-\d\d~', $th) && !preg_match('~(Dashain|Tihar|Holi|Diwali|Chhath)\s*[\'"]\s*=>~i', $th));
check('migration: both rows OFF / empty, never public, INSERT IGNORE', str_contains($mig, "INSERT IGNORE INTO settings (skey, svalue, stype, sgroup, label, is_public) VALUES")
    && (bool) preg_match("~\('festival_theme_on', '0', 'bool', 'app', '[^\n]*', 0\)~", $mig) && (bool) preg_match("~\('festival_dates', '\[\]', 'json', 'app', '[^\n]*', 0\);~", $mig));

/* ---- 2. the head script, run ------------------------------------------- */
echo "\n2. The head accent script, run (node vm)\n";
$head = preg_match('~<head>.*?<script>(.*?)</script>~s', $tpl, $hm) ? $hm[1] : '';
$tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u9x2-' . getmypid();
if (!$haveNode) {
    $SKIP++; echo "  SKIP  node not on PATH\n";
} else {
    file_put_contents($tmp . '-head.js', $head);
    $harness = <<<'JS'
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
const MON_NOON = Date.UTC(2026, 10, 16, 6, 30);        // Mon 16 Nov 2026 12:00 IST
const out = [];
const ok = (c, what) => out.push([!!c, what]);
function El(a) { this.a = Object.assign({}, a || {}); this.cls = new Set(); this.style = {}; const s = this;
  this.classList = { add: (c) => s.cls.add(c), remove: (c) => s.cls.delete(c), contains: (c) => s.cls.has(c) }; }
El.prototype.getAttribute = function (k) { return Object.prototype.hasOwnProperty.call(this.a, k) ? this.a[k] : null; };
El.prototype.setAttribute = function (k, v) { this.a[k] = String(v); };
El.prototype.removeAttribute = function (k) { delete this.a[k]; };
El.prototype.hasAttribute = function (k) { return Object.prototype.hasOwnProperty.call(this.a, k); };
function load(o) {
  o = o || {};
  const now = { t: o.now || MON_NOON }, timers = [];
  let tid = 0;
  const store = (m) => ({ getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k) });
  const ls = new Map(Object.entries(o.ls || {})), ss = new Map(Object.entries(o.ss || {}));
  const html = new El({});
  const loc = { search: o.search || '', pathname: '/', hash: '' };
  const ctx = {
    __now: () => now.t,
    document: { documentElement: html, hidden: false, querySelector: () => null, querySelectorAll: () => [], addEventListener: () => {} },
    localStorage: store(ls), sessionStorage: store(ss), location: loc,
    history: { state: null, replaceState: (st, t, u) => { const m = /^([^?#]*)(\?[^#]*)?(#.*)?$/.exec(u); loc.search = m[2] || ''; } },
    matchMedia: () => ({ matches: false }),
    setTimeout: (f, ms) => { const id = ++tid; timers.push({ id, at: now.t + ms, f }); return id; },
    clearTimeout: (id) => { const i = timers.findIndex((x) => x.id === id); if (i >= 0) timers.splice(i, 1); },
  };
  ctx.window = ctx;
  ctx.addEventListener = () => {};
  if (o.boot !== undefined && !o.bootLater) ctx.SHG_BOOT = o.boot;
  vm.createContext(ctx);
  vm.runInContext('Date.now = function () { return __now(); };', ctx);
  vm.runInContext(src, ctx);
  return {
    ctx, html,
    acc: () => html.getAttribute('data-accent'),
    fading: () => html.cls.has('accent-fade'),
    advance(ms) { const end = now.t + ms; for (;;) { timers.sort((a, b) => a.at - b.at); const t = timers[0]; if (!t || t.at > end) break; timers.shift(); now.t = t.at; t.f(); } now.t = end; },
  };
}
const boot = (row, on) => ({ settings: { festival_theme_on: on === undefined ? true : on }, festival: row });
const SAT = { from: '2026-11-16', to: '2026-11-16', accent: 'sat', ne: 'शुभ दिन', en: 'Happy day' };
let p;
p = load();
ok(p.acc() === 'mon', 'no SHG_BOOT at all: Monday, exactly as before');
p = load({ boot: boot(null, false) });
ok(p.acc() === 'mon', 'switch OFF, no row: Monday');
p = load({ boot: boot(SAT, false) });
ok(p.acc() === 'mon', 'switch OFF even with a row in the feed: Monday (nothing changes)');
p = load({ boot: { festival: SAT } });
ok(p.acc() === 'mon', 'a row without the switch in settings: Monday');
p = load({ boot: boot(SAT) });
ok(p.acc() === 'sat' && !p.fading(), "switch ON, today's row = Saturday's colour: painted before first paint, no fade");
ok(p.ctx.shgAccent.fest() === 'sat', 'shgAccent.fest() reports it');
p = load({ boot: boot(SAT, '1') });
ok(p.acc() === 'sat', "switch as '1': ON");
p = load({ boot: boot(SAT, 0) });
ok(p.acc() === 'mon', 'switch as 0: OFF');
p = load({ boot: boot(SAT), ls: { 'shg:accent': 'fixed' } });
ok(p.acc() === null, 'a visitor who chose Fixed keeps the brand colour');
p = load({ boot: boot(SAT), ls: { 'shg:palette': 'tulsi' } });
ok(p.acc() === null && p.html.getAttribute('data-palette') === 'tulsi', "a visitor's own palette (tulsi) still wins");
p = load({ boot: boot(SAT), ls: { 'shg:palette': 'tulsi', 'shg:accent': 'weekly' } });
ok(p.acc() === 'sat', 'Weekly chosen on purpose: the special date shows');
p = load({ boot: boot(SAT), search: '?counter=1' });
ok(p.acc() === null, 'the staff counter keeps the brand colour');
p = load({ boot: boot(SAT), search: '?accent_preview=tue' });
ok(p.acc() === 'tue', "the office's 5-minute preview beats the special date");
p.advance(301000);
ok(p.acc() === 'sat', '... and after 5 minutes the special date is back (not the weekday)');
p = load({ boot: boot({ from: '2026-11-14', to: '2026-11-15', accent: 'sat' }) });
ok(p.acc() === 'mon', 'a saved page whose row ended yesterday: Monday');
p = load({ boot: boot({ from: '2026-11-17', to: '2026-11-18', accent: 'sat' }) });
ok(p.acc() === 'mon', 'a row that starts tomorrow: Monday');
p = load({ boot: boot({ from: '2026-11-16', to: '2026-11-16', accent: '' }) });
ok(p.acc() === 'mon', "accent '' (banner only): the weekday colour");
p = load({ boot: boot({ from: '2026-11-16', to: '2026-11-16', accent: 'purple' }) });
ok(p.acc() === 'mon', 'an unknown accent key is ignored');
p = load({ boot: boot({ from: 'x', to: 'y', accent: 'sat' }) });
ok(p.acc() === 'mon', 'broken dates are ignored');
p = load({ boot: boot(SAT), now: Date.UTC(2026, 10, 16, 18, 29, 30) });   // Mon 23:59:30 IST
ok(p.acc() === 'sat', 'Monday 23:59:30 IST: still the special colour');
p.advance(32000);
ok(p.acc() === 'tue' && p.fading(), 'IST midnight after its last day: Tuesday fades in, no reload');
p = load({ boot: boot({ from: '2026-11-16', to: '2026-11-18', accent: 'wed' }), now: Date.UTC(2026, 10, 16, 18, 29, 30) });
p.advance(32000);
ok(p.acc() === 'wed', 'a three-day row keeps its colour across midnight');
p = load({ boot: boot(SAT), bootLater: true });
ok(p.acc() === 'mon', 'SHG_BOOT arriving after the head script: nothing yet ...');
p.ctx.SHG_BOOT = boot(SAT);
p.ctx.shgAccent.apply();
ok(p.acc() === 'sat', "... index.php's second apply() pass paints it");
process.stdout.write(JSON.stringify(out));
JS;
    file_put_contents($tmp . '-harness.js', $harness);
    $res = (string) shell_exec('node ' . escapeshellarg($tmp . '-harness.js') . ' ' . escapeshellarg($tmp . '-head.js') . ' 2>&1');
    @unlink($tmp . '-head.js'); @unlink($tmp . '-harness.js');
    $rows = json_decode($res, true);
    check('the head harness ran', is_array($rows), is_array($rows) ? '' : substr($res, 0, 400));
    foreach (is_array($rows) ? $rows : [] as [$c, $what]) { check((string) $what, (bool) $c); }
}

/* ---- 3. the notice strip, run ------------------------------------------ */
echo "\n3. The notice strip (05-router.js), run (node vm)\n";
$a = strpos($rt, '/* Seasonal notice banner');
$b = strpos($rt, '/* FAQ accordion */');
$strip = ($a !== false && $b !== false && $b > $a) ? substr($rt, $a, $b - $a) : '';
check('the strip code is found', $strip !== '' && str_contains($strip, 'function renderNotice()'));
if (!$haveNode || $strip === '') {
    $SKIP++; echo "  SKIP  node not on PATH\n";
} else {
    file_put_contents($tmp . '-strip.js', $strip);
    $harness = <<<'JS'
const fs = require('fs'), vm = require('vm');
const src = fs.readFileSync(process.argv[2], 'utf8');
const MON_NOON = Date.UTC(2026, 10, 16, 6, 30);
const out = [];
const ok = (c, what) => out.push([!!c, what]);
function hash(s) { let h = 5381; for (let i = 0; i < s.length; i++) h = ((h << 5) + h + s.charCodeAt(i)) | 0; return (h >>> 0).toString(36); }
function load(o) {
  o = o || {};
  const now = { t: o.now || MON_NOON }, timers = [], clicks = [];
  let tid = 0, ico = false;
  const cls = new Set(['hide']), attr = {};
  const bar = { classList: { add: (c) => cls.add(c), remove: (c) => cls.delete(c), contains: (c) => cls.has(c), toggle: (c, f) => (f ? cls.add(c) : cls.delete(c)) },
    setAttribute: (k, v) => { attr[k] = String(v); }, getAttribute: (k) => (k in attr ? attr[k] : null), querySelector: () => (ico ? {} : null) };
  const txt = { textContent: '', insertAdjacentHTML: () => { ico = true; } };
  const ls = new Map(Object.entries(o.ls || {}));
  const ctx = {
    __now: () => now.t,
    $: (s) => (s === '#noticeBar' ? bar : s === '#noticeText' ? txt : null),
    S: () => o.kv || {},
    localStorage: { getItem: (k) => (ls.has(k) ? ls.get(k) : null), setItem: (k, v) => ls.set(k, String(v)) },
    document: { addEventListener: (t, f) => { if (t === 'click') clicks.push(f); } },
    setTimeout: (f, ms) => { const id = ++tid; timers.push({ id, at: now.t + ms, f }); return id; },
    clearTimeout: (id) => { const i = timers.findIndex((x) => x.id === id); if (i >= 0) timers.splice(i, 1); },
    SHG_BOOT: o.boot || {},
  };
  ctx.window = ctx;
  vm.createContext(ctx);
  vm.runInContext('Date.now = function () { return __now(); };', ctx);
  vm.runInContext(src, ctx);
  vm.runInContext('renderNotice();', ctx);
  return {
    ls, txt,
    shown: () => !cls.has('hide'), fest: () => cls.has('is-fest'), kind: () => attr['data-nk'],
    render: () => vm.runInContext('renderNotice();', ctx),
    close: () => clicks.forEach((f) => f({ target: { closest: (s) => (s === '#noticeClose' ? {} : null) } })),
    advance(ms) { const end = now.t + ms; for (;;) { timers.sort((a, b) => a.at - b.at); const t = timers[0]; if (!t || t.at > end) break; timers.shift(); now.t = t.at; t.f(); } now.t = end; },
  };
}
const ROW = { from: '2026-11-16', to: '2026-11-16', accent: 'sat', ne: 'शुभ दशैं', en: 'Happy Dashain' };
const fb = (row, on, extra) => Object.assign({ settings: Object.assign({ festival_theme_on: on === undefined ? true : on }, (extra || {}).settings || {}), festival: row }, (extra || {}).top || {});
const office = { settings: { notice_text: 'Office: bus 3 late' }, top: { notice_since: Math.floor(MON_NOON / 1000) - 3600 } };
let p;
p = load({ boot: fb(null, false) });
ok(!p.shown(), 'switch OFF, no office notice: the strip stays hidden (as before)');
p = load({ boot: fb(ROW, false) });
ok(!p.shown(), 'switch OFF with a row in the feed: hidden');
p = load({ boot: fb(ROW) });
ok(p.shown() && p.txt.textContent === 'शुभ दशैं · Happy Dashain', 'switch ON: the banner, Nepali first then English');
ok(p.fest() && p.kind() === 'fest', '... marked .is-fest (accent left edge), data-nk = fest');
p = load({ boot: fb(ROW, true, office) });
ok(p.shown() && p.txt.textContent === 'Office: bus 3 late' && !p.fest() && p.kind() === 'office', 'an office notice on screen comes first');
p = load({ boot: fb(ROW, true, office), ls: { 'shg:noticeDismissed': hash('Office: bus 3 late') } });
ok(p.shown() && p.fest(), 'the office notice closed on this phone: the banner shows');
p.close();
ok(!p.shown() && p.ls.get('shg:festDismissed') === hash('शुभ दशैं · Happy Dashain') && p.ls.get('shg:noticeDismissed') === hash('Office: bus 3 late'),
  "✕ on the banner: kept under its own key, the office notice's ✕ untouched");
p.render();
ok(!p.shown(), '... and neither comes back');
p = load({ boot: fb(ROW, true, office) });
p.close();
ok(p.ls.get('shg:noticeDismissed') === hash('Office: bus 3 late') && !p.ls.has('shg:festDismissed'), "✕ on the office notice keeps the office key (unchanged behaviour)");
p.render();
ok(p.shown() && p.fest(), '... then the banner takes the strip');
p = load({ boot: fb(ROW, true, { settings: office.settings, top: { notice_since: Math.floor(MON_NOON / 1000) - 90000 } }) });
ok(p.shown() && p.fest(), 'an office notice older than 24 h is gone: the banner shows');
p = load({ boot: fb(ROW), kv: { noticeOn: true, noticeText: 'Old local notice' } });
ok(p.shown() && p.txt.textContent === 'Old local notice' && !p.fest(), 'the old local notice also comes first');
p = load({ boot: fb(Object.assign({}, ROW, { ne: '' })) });
ok(p.shown() && p.txt.textContent === 'Happy Dashain', 'English only when there is no Nepali line');
p = load({ boot: fb(Object.assign({}, ROW, { ne: '', en: '' })) });
ok(!p.shown(), 'no banner lines (colour only): the strip stays hidden');
p = load({ boot: fb(Object.assign({}, ROW, { ne: '<img src=x onerror=alert(1)>' })) });
ok(p.txt.textContent.indexOf('<img src=x') === 0, 'markup in a banner is shown as text');
p = load({ boot: fb(Object.assign({}, ROW, { from: '2026-11-14', to: '2026-11-15' })) });
ok(!p.shown(), 'a saved page whose row ended yesterday: hidden');
p = load({ boot: fb(ROW), now: Date.UTC(2026, 10, 16, 18, 29) });   // Mon 23:59 IST
ok(p.shown(), 'Monday 23:59 IST: still showing');
p.advance(120000);
ok(!p.shown(), 'after IST midnight following its last day it leaves by itself');
process.stdout.write(JSON.stringify(out));
JS;
    file_put_contents($tmp . '-strip-harness.js', $harness);
    $res = (string) shell_exec('node ' . escapeshellarg($tmp . '-strip-harness.js') . ' ' . escapeshellarg($tmp . '-strip.js') . ' 2>&1');
    @unlink($tmp . '-strip.js'); @unlink($tmp . '-strip-harness.js');
    $rows = json_decode($res, true);
    check('the strip harness ran', is_array($rows), is_array($rows) ? '' : substr($res, 0, 400));
    foreach (is_array($rows) ? $rows : [] as [$c, $what]) { check((string) $what, (bool) $c); }
}

/* ---- 4. database part -------------------------------------------------- */
echo "\n4. Database part (shari_test)\n";
define('SHG_APP', true);
require_once $ROOT . '/includes/bootstrap.php';
if (!defined('DB_NAME') || DB_NAME !== 'shari_test') {
    $SKIP++; echo "  SKIP  DB_NAME is not shari_test — refusing to write\n";
    finish();
}
$KEYS = ['festival_dates', 'festival_theme_on'];
$orig = [];
foreach ($KEYS as $k) {
    $orig[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]);
}
$origCopy = $orig;
$tag  = strtolower(substr(bin2hex(random_bytes(4)), 0, 6));
$made = [];
$cleanup = static function () use (&$made, &$orig): void {
    foreach ($orig as $k => $row) {
        try {
            if ($row === null) {
                Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
            } else {
                Database::query('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]);
            }
        } catch (Throwable $e) { echo "  !! could not restore $k: " . $e->getMessage() . "\n"; }
    }
    $orig = [];
    foreach ($made as $aid) {
        try { Database::query("DELETE FROM audit_logs WHERE actor_type = 'admin' AND actor_id = :a", ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('kv_store', "kscope = 'nav_use' AND kkey = :k", ['k' => 'a' . $aid]); } catch (Throwable $e) {}
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]); } catch (Throwable $e) {}
        try { Database::delete('admins', 'id = :a', ['a' => $aid]); } catch (Throwable $e) { echo "  !! could not delete admin $aid: " . $e->getMessage() . "\n"; }
    }
    $made = [];
};
register_shutdown_function($cleanup);
$mk = static function (string $role) use ($tag, &$made): int {
    $id = (int) Database::insert('admins', ['username' => 'u9x2-' . $role . '-' . $tag, 'password_hash' => password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT),
        'full_name' => 'U9 X2 ' . ucfirst($role) . ' Test', 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    $made[] = $id;
    return $id;
};
/* the stored state, read straight from the table (this process' Settings cache would be stale) */
$dbRows = static function (): array {
    $v = Database::scalar("SELECT svalue FROM settings WHERE skey = 'festival_dates'", [], null);
    $j = is_string($v) ? json_decode($v, true) : null;
    return is_array($j) ? $j : [];
};
$dbOn = static fn(): bool => (string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'festival_theme_on'", [], '0') === '1';
$put = static function (array $rows, bool $on): void {
    Settings::set('festival_dates', $rows, 'json', 'app');
    Settings::set('festival_theme_on', $on, 'bool', 'app');
};
$served = static function () use ($ROOT): array {
    $page = run(escapeshellarg($ROOT . '/index.php'));
    $boot = preg_match('~window\.SHG_BOOT = (\{.*?\});\r?\n~', $page, $m) === 1 ? json_decode($m[1], true) : null;
    return [is_array($boot) ? $boot : null, $page];
};
$audit = static function (int $aid, string $action): array {
    return Database::fetchAll("SELECT old_value, new_value FROM audit_logs WHERE actor_type = 'admin' AND actor_id = :a AND action = :x ORDER BY id", ['a' => $aid, 'x' => $action]);
};
$flashOf = static fn(string $html): string => preg_match('~<div class="flash (ok|bad)"[^>]*>~', $html, $fm) === 1 ? $fm[1] : '';
$ist = new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata'));
$D = static fn(int $n): string => $ist->modify(($n >= 0 ? '+' : '') . $n . ' day')->format('Y-m-d');
$T = $D(0);
$row = static fn(string $f, string $t, string $name, string $acc, string $ne = '', string $en = ''): array
    => ['from' => $f, 'to' => $t, 'name' => $name, 'accent' => $acc, 'banner_ne' => $ne, 'banner_en' => $en];

try {
    $SUP = $mk('superadmin');
    $SPT = $mk('support');

    echo "\n-- the served page (php index.php)\n";
    $multi  = $row($D(-1), $D(2), 'MULTI-' . $tag, 'wed', 'बहु-' . $tag, 'MULTIBANNER-' . $tag);
    $oneDay = $row($T, $T, 'ONEDAY-' . $tag, 'sat', 'आज-' . $tag, 'TODAY-' . $tag);
    $future = $row($D(3), $D(5), 'FUTURE-' . $tag, 'thu', 'भविष्य-' . $tag, 'FUTUREBANNER-' . $tag);
    $put([$multi, $oneDay, $future], false);
    [$bt, $page] = $served();
    check('switch OFF: the page boots', $bt !== null, $bt === null ? substr($page, 0, 200) : '');
    check('switch OFF: festival = null, festival_theme_on = false', $bt !== null && array_key_exists('festival', $bt) && $bt['festival'] === null && ($bt['settings']['festival_theme_on'] ?? null) === false);
    check('switch OFF: no row name or banner anywhere on the page', !str_contains($page, $tag));
    check('festival_dates is never in the public settings', $bt !== null && !array_key_exists('festival_dates', (array) ($bt['settings'] ?? [])));

    $put([$multi, $oneDay, $future], true);
    [$bt, $page] = $served();
    $f = $bt['festival'] ?? null;
    check('switch ON: festival_theme_on = true', ($bt['settings']['festival_theme_on'] ?? null) === true);
    check("switch ON: today's one-day row wins over the longer row", is_array($f) && ($f['accent'] ?? '') === 'sat' && ($f['from'] ?? '') === $T && ($f['to'] ?? '') === $T, json_encode($f, JSON_UNESCAPED_UNICODE));
    check('... with only from / to / accent / ne / en', is_array($f) && array_keys($f) === ['from', 'to', 'accent', 'ne', 'en'] && $f['ne'] === 'आज-' . $tag && $f['en'] === 'TODAY-' . $tag);
    check('... and nothing else from the list (no other row, no names)', !str_contains($page, 'MULTI') && !str_contains($page, 'FUTURE') && !str_contains($page, 'ONEDAY-'));
    $put([$multi, $future], true);
    [$bt] = $served();
    check('only the longer row covers today: its accent', ($bt['festival']['accent'] ?? '') === 'wed' && ($bt['festival']['from'] ?? '') === $D(-1));
    $put([$future], true);
    [$bt] = $served();
    check('no row today: festival = null', $bt !== null && $bt['festival'] === null);
    $put([$row($D(-1), $T, 'A-' . $tag, 'tue'), $row($T, $D(1), 'B-' . $tag, 'mon')], true);
    [$bt] = $served();
    check('two rows of the same length: the later start wins', ($bt['festival']['accent'] ?? '') === 'mon');
    $put([$row($T, $T, 'C-' . $tag, 'fri'), $row($T, $T, 'D-' . $tag, 'thu')], true);
    [$bt] = $served();
    check('two identical spans and starts: the later row wins', ($bt['festival']['accent'] ?? '') === 'thu');
    Database::query("UPDATE settings SET svalue = :v WHERE skey = 'festival_dates'", ['v' => '[{"from":"2026-02-30","to":"x"},"junk",{"from":"' . $T . '","to":"' . $T . '","accent":"<x>","banner_ne":"' . $tag . '"}]']);
    [$bt, $page] = $served();
    check('a hand-broken list never breaks the page (bad dates, junk and an unknown colour are all skipped)', $bt !== null && array_key_exists('festival', $bt) && $bt['festival'] === null && !str_contains($page, $tag));
    /* review fix: index.php keeps only the rows admin/themes.php would list (typed by hand in Settings) */
    $broken = [
        ['from' => ['x'], 'to' => $T, 'name' => 'ARR-' . $tag, 'accent' => 'sun', 'banner_ne' => 'ARR-' . $tag],
        ['from' => $T, 'to' => $T, 'name' => 'ARRBAN-' . $tag, 'accent' => ['x'], 'banner_ne' => ['y'], 'banner_en' => ['z']],
        $row($T, $T, '', 'sat', 'NONAME-' . $tag),
        $row($T, $T, 'EMPTY-' . $tag, ''),
        $row($T, $T, 'BADACC-' . $tag, 'gold', 'BADACC-' . $tag),
        $row($D(-8), $D(8), 'LONG-' . $tag, 'sun', 'LONG-' . $tag),
    ];
    Database::query("UPDATE settings SET svalue = :v WHERE skey = 'festival_dates'", ['v' => json_encode($broken, JSON_UNESCAPED_UNICODE)]);
    [$bt, $page] = $served();
    check('rows the Themes page refuses (no name / nothing to show / unknown colour / over 15 days / array fields) never reach the page',
        $bt !== null && $bt['festival'] === null && !str_contains($page, $tag), json_encode($bt['festival'] ?? null, JSON_UNESCAPED_UNICODE));
    $broken[] = $row($D(-2), $D(2), 'GOOD-' . $tag, 'tue', 'GOOD-' . $tag, "Good\n  " . $tag);
    Database::query("UPDATE settings SET svalue = :v WHERE skey = 'festival_dates'", ['v' => json_encode($broken, JSON_UNESCAPED_UNICODE)]);
    [$bt, $page] = $served();
    check('... and they never hide the good row (the shorter refused ones would have won)', ($bt['festival']['accent'] ?? '') === 'tue' && ($bt['festival']['ne'] ?? '') === 'GOOD-' . $tag
        && ($bt['festival']['en'] ?? '') === 'Good ' . $tag && !str_contains($page, 'NONAME-') && !str_contains($page, 'BADACC-') && !str_contains($page, 'LONG-'), json_encode($bt['festival'] ?? null, JSON_UNESCAPED_UNICODE));
    $put([], false);

    echo "\n-- admin/themes.php writes (super-admin)\n";
    $html = child(['admin' => $SUP]);
    check('renders with the empty list and the switch OFF', str_contains($html, 'id="special"') && str_contains($html, 'No special dates yet') && str_contains($html, '<span class="sp-state">बन्द · OFF</span>')
        && preg_match('/(Warning|Notice|Deprecated|Fatal error):/', $html) !== 1);
    $good = ['act' => 'add', 'ver' => '@auto', 'from' => $T, 'to' => $D(2), 'name' => 'X2 <i>' . $tag . '</i>', 'accent' => 'tue', 'banner_ne' => "शुभ\nदशैं", 'banner_en' => 'Happy Dashain'];
    $html = child(['admin' => $SUP, 'post' => $good, 'csrf' => false]);
    check('no CSRF token: refused, nothing saved', $flashOf($html) === 'bad' && $dbRows() === []);
    $html = child(['admin' => $SUP, 'post' => ['ver' => 'stale'] + $good]);
    check('a stale page (list changed since it opened): refused, nothing saved', $flashOf($html) === 'bad' && str_contains($html, 'nothing was saved') && $dbRows() === []);
    $bad = [
        'To before From'            => ['from' => $D(3), 'to' => $D(1)],
        '16 days'                   => ['from' => $T, 'to' => $D(15)],
        'an unknown colour'         => ['accent' => 'purple'],
        'dates already over'        => ['from' => $D(-5), 'to' => $D(-1)],
        'more than two years ahead' => ['from' => $D(800), 'to' => $D(801)],
        'no name'                   => ['name' => ' '],
        'no colour and no banner'   => ['accent' => '', 'banner_ne' => '', 'banner_en' => ''],
        'an impossible date'        => ['from' => '2027-02-30', 'to' => '2027-03-01'],
        'a date in another format'  => ['from' => '16/11/2026'],
    ];
    foreach ($bad as $why => $over) {
        $html = child(['admin' => $SUP, 'post' => $over + $good]);
        check("refused: $why", $flashOf($html) === 'bad' && $dbRows() === []);
    }
    check('a refused add keeps what was typed in the open form', str_contains($html, '<details class="sp-add" open>') && str_contains($html, 'value="Happy Dashain"'));
    $html = child(['admin' => $SUP, 'post' => $good]);
    $rows = $dbRows();
    check('a valid add is saved', $flashOf($html) === 'ok' && count($rows) === 1, json_encode($rows, JSON_UNESCAPED_UNICODE));
    check('... cleaned: one line, exact keys', ($rows[0] ?? []) === ['from' => $T, 'to' => $D(2), 'name' => 'X2 <i>' . $tag . '</i>', 'accent' => 'tue', 'banner_ne' => 'शुभ दशैं', 'banner_en' => 'Happy Dashain']);
    check('... shown escaped, with its Preview link', str_contains($html, 'X2 &lt;i&gt;' . $tag . '&lt;/i&gt;') && !str_contains($html, 'X2 <i>' . $tag) && str_contains($html, 'href="/?accent_preview=tue" target="_blank" rel="noopener"'));
    check('... marked as today, the switch still OFF', str_contains($html, '<span class="sp-chip now">आज · Today</span>') && str_contains($html, 'स्विच बन्द · switch OFF'));
    $au = $audit($SUP, 'theme.special_add');
    check('... audited (new row, no old)', count($au) === 1 && $au[0]['old_value'] === null && str_contains((string) $au[0]['new_value'], '"accent":"tue"'));
    check('the switch is still OFF after an add', $dbOn() === false);

    $html = child(['admin' => $SUP, 'post' => ['act' => 'edit', 'i' => '0', 'name' => 'X2 edited ' . $tag, 'accent' => 'fri'] + $good]);
    $rows = $dbRows();
    check('edit: saved in place', $flashOf($html) === 'ok' && count($rows) === 1 && $rows[0]['accent'] === 'fri' && $rows[0]['name'] === 'X2 edited ' . $tag);
    $au = $audit($SUP, 'theme.special_edit');
    check('... audited old -> new', count($au) === 1 && str_contains((string) $au[0]['old_value'], '"accent":"tue"') && str_contains((string) $au[0]['new_value'], '"accent":"fri"'));
    $html = child(['admin' => $SUP, 'post' => ['act' => 'edit', 'i' => '7'] + $good]);
    check('edit of a row that is not there: refused', $flashOf($html) === 'bad' && count($dbRows()) === 1);

    $html = child(['admin' => $SUP, 'post' => ['act' => 'today', 'ver' => '@auto', 'accent' => 'sat']]);
    $rows = $dbRows();
    $one = array_values(array_filter($rows, static fn(array $r): bool => $r['from'] === $T && $r['to'] === $T));
    check('Override today: a one-day row for today', $flashOf($html) === 'ok' && count($rows) === 2 && count($one) === 1 && $one[0]['accent'] === 'sat');
    check('... it is the row that shows today', str_contains($html, '<span class="sp-chip won">') && preg_match('~Special day:</b> आजको रङ · Today&#039;s colour~u', $html) === 1);
    $html = child(['admin' => $SUP, 'post' => ['act' => 'today', 'ver' => '@auto', 'accent' => 'mon']]);
    $rows = $dbRows();
    $one = array_values(array_filter($rows, static fn(array $r): bool => $r['from'] === $T && $r['to'] === $T));
    check('... a second override changes that row, no new row', count($rows) === 2 && count($one) === 1 && $one[0]['accent'] === 'mon');
    $html = child(['admin' => $SUP, 'post' => ['act' => 'today', 'ver' => '@auto', 'accent' => '']]);
    check('... a colour is required', $flashOf($html) === 'bad' && count($dbRows()) === 2);
    $au = $audit($SUP, 'theme.special_today');
    check('... audited, the second with the old row', count($au) === 2 && $au[0]['old_value'] === null && str_contains((string) $au[1]['old_value'], '"accent":"sat"'));

    $html = child(['admin' => $SUP, 'post' => ['act' => 'switch', 'on' => '1']]);
    check('switch ON (no list version needed)', $flashOf($html) === 'ok' && $dbOn() === true && str_contains($html, '<span class="sp-state on">खुला · ON</span>'));
    [$bt] = $served();
    check("... and the served page now gets today's override", ($bt['festival']['accent'] ?? '') === 'mon' && ($bt['festival']['from'] ?? '') === $T);
    $html = child(['admin' => $SUP, 'post' => ['act' => 'switch', 'on' => '0']]);
    check('switch OFF again', $flashOf($html) === 'ok' && $dbOn() === false);
    $au = $audit($SUP, 'theme.special_switch');
    check('... both audited old -> new', count($au) === 2 && $au[0]['old_value'] === '{"on":false}' && $au[0]['new_value'] === '{"on":true}' && $au[1]['new_value'] === '{"on":false}');

    echo "\n-- a support login (dashboard.view, no settings.manage)\n";
    $html = child(['admin' => $SPT]);
    check('reads the list, no forms', str_contains($html, 'X2 edited ' . $tag) && stripos($html, '<form method="post"') === false);
    $before = $dbRows();
    $html = child(['admin' => $SPT, 'post' => $good]);
    check('a hand-made POST is refused, nothing saved', $flashOf($html) === 'bad' && $dbRows() === $before);
    $html = child(['admin' => $SPT, 'post' => ['act' => 'switch', 'on' => '1']]);
    check('... the switch too', $flashOf($html) === 'bad' && $dbOn() === false);

    echo "\n-- delete, remove past rows, the 30-row cap\n";
    $rows = $dbRows();
    $at = (int) array_key_first(array_filter($rows, static fn(array $r): bool => $r['from'] === $T && $r['to'] === $T));
    $html = child(['admin' => $SUP, 'post' => ['act' => 'delete', 'i' => (string) $at, 'ver' => '@auto']]);
    $rows = $dbRows();
    check('delete: the override row is gone, the other stays', $flashOf($html) === 'ok' && count($rows) === 1 && $rows[0]['name'] === 'X2 edited ' . $tag);
    $au = $audit($SUP, 'theme.special_delete');
    check('... audited with the old row', count($au) === 1 && str_contains((string) $au[0]['old_value'], '"accent":"mon"') && $au[0]['new_value'] === null);
    $put(array_merge($rows, [$row($D(-6), $D(-4), 'PAST-' . $tag, 'sun')]), false);
    $html = child(['admin' => $SUP]);
    check('a past row is shown as over, with no Edit, and a Remove past rows button', str_contains($html, 'बितिसक्यो · Over') && str_contains($html, 'value="prune"'));
    $html = child(['admin' => $SUP, 'post' => ['act' => 'prune', 'ver' => '@auto']]);
    $rows = $dbRows();
    check('remove past rows: only the past one goes', $flashOf($html) === 'ok' && count($rows) === 1 && $rows[0]['name'] === 'X2 edited ' . $tag);
    check('... audited', count($audit($SUP, 'theme.special_prune')) === 1);
    $thirty = [];
    for ($i = 0; $i < 30; $i++) { $thirty[] = $row($D(10 + $i), $D(10 + $i), 'R' . $i . '-' . $tag, 'mon'); }
    $put($thirty, false);
    $html = child(['admin' => $SUP]);
    check('30 rows: the add form is gone', !str_contains($html, 'value="add"') && str_contains($html, 'R29-' . $tag));
    $html = child(['admin' => $SUP, 'post' => $good]);
    check('... and a 31st row is refused', $flashOf($html) === 'bad' && count($dbRows()) === 30);
    $html = child(['admin' => $SUP, 'post' => ['act' => 'today', 'ver' => '@auto', 'accent' => 'sat']]);
    check('... Override today too (it would be a 31st row)', $flashOf($html) === 'bad' && count($dbRows()) === 30);
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @' . $e->getLine());
} finally {
    $cleanup();
}
$left = (int) Database::scalar('SELECT COUNT(*) FROM admins WHERE username LIKE :u', ['u' => 'u9x2-%-' . $tag], 0);
check('clean-up: no test admin left', $left === 0);
$now = [];
foreach ($KEYS as $k) { $now[$k] = Database::fetch('SELECT svalue, stype FROM settings WHERE skey = :k', ['k' => $k]); }
check('clean-up: both settings rows are back as they were', $now === $origCopy, json_encode($now, JSON_UNESCAPED_UNICODE));
finish();
