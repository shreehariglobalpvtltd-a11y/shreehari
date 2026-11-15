<?php
/**
 * =====================================================================
 *  u2b-customer-shell-test.php - 16 Nov 2026 (Master Upgrade v2.0, U2b)
 *
 *  The customer page shell pieces of this unit:
 *    C7     the phone menu's hidden "Install app" row (#pwaInstallMenu)
 *    C7     SHG_BOOT.notice_since (when the office last changed notice_text)
 *    C7     /?accent_preview=<day>: that day's accent in THIS tab for 5 min
 *    P5-5   a 1 s colour-only cross-fade when the accent changes after the
 *           page is up, never under reduced motion
 *    P5-8a  tri-state accent mode: a palette the visitor picked stays theirs
 *           (Fixed), owner-default visitors keep the weekly rotation, and the
 *           Appearance sheet has a Weekly / Fixed row
 *
 *  Nothing is written: the database is only READ (the notice_text row), and
 *  the head script is run in node's vm with a fake DOM, clock and storage
 *  (skipped, not failed, when node is not on PATH).
 *
 *    php tests/u2b-customer-shell-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $skip = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  PASS  $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);

$tpl = $read('app.template.html');
$ds  = $read('assets/css/ds.css');
$rd  = $read('assets/css/redesign.css');
$acc = $read('assets/js/03-accounts.js');
$idx = $read('index.php');

echo "\n1. C7 - the menu's Install app row\n";
$mm = '';
if (preg_match('~<div class="mobile-menu" id="mobileMenu">(.*?)\n</div>~s', $tpl, $m)) { $mm = $m[1]; }
$ok($mm !== '', 'the phone menu block is found');
$ok(substr_count($tpl, 'id="pwaInstallMenu"') === 1, 'exactly one #pwaInstallMenu on the page');
$ok(str_contains($mm, '<a href="#" id="pwaInstallMenu" hidden data-i18n="pwaInstallBtn">Install app</a>'), 'it sits in the phone menu, hidden, with the contract markup (C7)');
$ok((bool) preg_match('~\.mobile-menu a\[hidden\]\s*\{\s*display:\s*none\s*!important;\s*\}~', $rd), 'redesign.css: [hidden] beats the menu links\' display:flex !important');
$ok(str_contains($rd, '#pwaInstallMenu::before') && str_contains($rd, 'mask: url("data:image/svg+xml'), 'the row gets a download icon from CSS (i18n rewrites its text)');
$i18n = $read('assets/js/04-i18n.js');
$ok(substr_count($i18n, 'pwaInstallBtn:') === 3, 'its label exists in English, Hindi and Nepali');

echo "\n2. C7 - SHG_BOOT.notice_since\n";
$ok(str_contains($idx, "\$boot['notice_since'] = (int) Database::scalar(") && str_contains($idx, "UNIX_TIMESTAMP(updated_at) FROM settings WHERE skey = 'notice_text'"), 'index.php reads the notice_text row\'s updated_at as unix seconds');
$ok(str_contains($idx, "\$boot['notice_since'] = 0;"), 'a failed read ships 0, never a broken page');
$ok(strpos($idx, "\$boot['notice_since']") < strpos($idx, '$bootJson = Security::jsonForHtml($boot);'), 'it is set before SHG_BOOT is encoded');
$settingsSet = $read('includes/settings.php');
$ok((bool) preg_match('~ON DUPLICATE KEY UPDATE\s+svalue = VALUES\(svalue\),\s+stype\s+= VALUES\(stype\)\'~', $settingsSet), 'Settings::set touches svalue/stype only, so updated_at moves only when the text changes');

/* the real value, read-only, from the local database the page uses */
$dbOk = false;
try {
    require_once $root . '/includes/bootstrap.php';
    $want = (int) Database::scalar("SELECT UNIX_TIMESTAMP(updated_at) FROM settings WHERE skey = 'notice_text' LIMIT 1", [], 0);
    $dbOk = true;
} catch (Throwable $e) {
    $want = 0;
}
if ($dbOk) {
    $php = escapeshellarg(PHP_BINARY);
    $ini = php_ini_loaded_file();
    $cmd = $php . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg($root . '/index.php') . ' 2>&1';
    $page = (string) shell_exec($cmd);
    $got = preg_match('~"notice_since":(\d+)~', $page, $nm) ? (int) $nm[1] : -1;
    $ok($got === $want, "served page carries notice_since = $want (got $got)");
    $ok(substr_count($page, 'id="pwaInstallMenu"') === 1, 'served page carries the Install app row (survives the comment strip)');
} else {
    $skip++; echo "  SKIP  served page (no database)\n";
}

echo "\n3. P5-5 - accent cross-fade CSS\n";
$fadeCss = '';
if (preg_match('~@media \(prefers-reduced-motion: no-preference\) \{\s*(html\.accent-fade.*?)\}\s*\}~s', $ds, $fm)) { $fadeCss = $fm[1]; }
$ok($fadeCss !== '', 'ds.css: the fade rules live inside prefers-reduced-motion: no-preference');
$ok(substr_count($fadeCss, 'html.accent-fade:not([data-motion="reduced"])') === 4, 'every selector skips the in-app Reduced motion (html, *, ::before, ::after)');
$tv = preg_match('~transition:\s*([^;]+);~', $fadeCss, $tm) ? $tm[1] : '';
$props = array_map(static fn(string $p): string => (string) strtok(trim($p), ' '), explode(',', str_replace('!important', '', $tv)));
$ok($props !== [] && array_diff($props, ['background-color', 'color', 'border-color', 'outline-color', 'fill', 'stroke']) === [], 'colour properties only: ' . implode(', ', $props));
$ok(!preg_match('~\b(all|transform|opacity|animation)\b~', $tv) && str_contains($tv, '1s'), 'nothing moves or fades out, and it lasts 1 s');
/* the owner's 13 Nov accent table is byte-for-byte untouched */
preg_match_all('~:root:root\[data-theme="(?:light|dark)"\]\[data-accent="[a-z]+"\]\{[^}]*\}~', $ds, $am);
$ok(count($am[0]) === 14 && sha1(implode("\n", $am[0])) === 'd7f04fca59565f7471e44eb3a705db5e04918d25', "the owner's 14 accent rules are unchanged");

echo "\n4. P5-8a - tri-state accent + the sheet's Weekly / Fixed row\n";
$ok(str_contains($tpl, "if (a === 'fixed' || a === 'weekly') return a;") && str_contains($tpl, "return (p === 'shg' || (P && Object.prototype.hasOwnProperty.call(P, p))) ? 'fixed' : 'weekly';"), 'mode(): a saved choice wins, else a picked palette means Fixed, else Weekly');
$ok(str_contains($tpl, "if (window.shgAccent) window.shgAccent.apply();"), 'the palette script re-runs the accent once its whitelist exists');
$ok((bool) preg_match("~if \(save !== false\) \{ lsSet\('shg:palette', ok \? id : 'shg'\); if \(window\.shgAccent\) \{ try \{ window\.shgAccent\.set\('fixed'\); \}~", $acc), '03-accounts.js: picking a palette sets the accent to Fixed');
$ok(str_contains($acc, "opt('data-accent-set=\"weekly\"'") && str_contains($acc, "opt('data-accent-set=\"fixed\"'") && str_contains($acc, 'aria-labelledby="apGrpAcc"'), 'the Appearance sheet has its own Weekly / Fixed radiogroup');
$ok(str_contains($acc, "if (t.closest('[data-accent-set]')) { setTimeout(rove, 0);"), 'the sheet keeps its tab stop on the checked radio after a tap');
foreach (['accent', 'weekly', 'weeklyS', 'fixed', 'fixedS'] as $k) {
    $ok((bool) preg_match('~\b' . $k . ": \['[^']+', '[^']+', '[^']+'\]~u", $acc), "sheet label '$k' in Nepali, English and Hindi");
}
$ok(str_contains($tpl, 'data-accent-set="weekly"') && str_contains($tpl, 'data-accent-set="fixed"'), 'the phone menu still has its own Weekly / Fixed buttons');

echo "\n5. The head script, run (node vm, fake DOM / clock / storage)\n";
$head = '';
if (preg_match('~<head>.*?<script>(.*?)</script>~s', $tpl, $hm)) { $head = $hm[1]; }
$ok(str_contains($head, 'window.shgAccent') && str_contains($head, 'window.SHG_PAL_TC'), 'the first head script carries the accent and palette code');
exec('node --version 2>&1', $nv, $nc);
if ($nc !== 0) {
    $skip++; echo "  SKIP  node not on PATH\n";
} else {
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'u2b-shell-' . getmypid();
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
El.prototype.closest = function (sel) { const m = /^\[([a-z-]+)\]$/.exec(sel); return m && this.hasAttribute(m[1]) ? this : null; };
function load(o) {
  o = o || {};
  const now = { t: o.now || MON_NOON }, timers = [], L = { doc: {}, win: {} }, replaced = [];
  let tid = 0;
  const store = (m, bad) => ({ getItem: (k) => { if (bad) throw new Error('blocked'); return m.has(k) ? m.get(k) : null; },
    setItem: (k, v) => { if (bad) throw new Error('blocked'); m.set(k, String(v)); }, removeItem: (k) => m.delete(k) });
  const ls = new Map(Object.entries(o.ls || {})), ss = o.ssMap || new Map(Object.entries(o.ss || {}));
  const html = new El(o.html || {});
  const btns = [new El({ 'data-accent-set': 'weekly' }), new El({ 'data-accent-set': 'fixed' })];
  const loc = { search: o.search || '', pathname: '/', hash: o.hash || '' };
  const ctx = {
    __now: () => now.t,
    document: { documentElement: html, hidden: false, querySelector: () => null,
      querySelectorAll: (s) => (s === '[data-accent-set]' ? btns : []),
      addEventListener: (t, f) => (L.doc[t] = L.doc[t] || []).push(f) },
    localStorage: store(ls, o.lsBad), sessionStorage: store(ss, o.ssBad), location: loc,
    history: { state: null, replaceState: (st, t, u) => { replaced.push(u); const m = /^([^?#]*)(\?[^#]*)?(#.*)?$/.exec(u); loc.pathname = m[1]; loc.search = m[2] || ''; loc.hash = m[3] || ''; } },
    matchMedia: (q) => ({ matches: /reduce/.test(q) ? !!o.reduce : false }),
    setTimeout: (f, ms) => { const id = ++tid; timers.push({ id, at: now.t + ms, f }); return id; },
    clearTimeout: (id) => { const i = timers.findIndex((x) => x.id === id); if (i >= 0) timers.splice(i, 1); },
  };
  ctx.window = ctx;
  ctx.addEventListener = (t, f) => (L.win[t] = L.win[t] || []).push(f);
  vm.createContext(ctx);
  vm.runInContext('Date.now = function () { return __now(); };', ctx);
  vm.runInContext(src, ctx);
  return {
    html, ls, ss, btns, loc, replaced, ctx,
    acc: () => html.getAttribute('data-accent'),
    fading: () => html.cls.has('accent-fade'),
    advance(ms) { const end = now.t + ms; for (;;) { timers.sort((a, b) => a.at - b.at); const t = timers[0]; if (!t || t.at > end) break; timers.shift(); now.t = t.at; t.f(); } now.t = end; },
    click(el) { (L.doc.click || []).forEach((f) => f({ target: el })); },
    fire(where, type) { ((where === 'doc' ? L.doc : L.win)[type] || []).forEach((f) => f({})); },
  };
}
let p;
p = load();
ok(p.acc() === 'mon' && p.ctx.shgAccent.mode() === 'weekly', 'nothing saved: the weekly accent (Monday) - the owner-default visitor keeps the rotation');
ok(!p.fading(), 'no fade on the first paint');
p = load({ ls: { 'shg:palette': 'tulsi' } });
ok(p.acc() === null && p.ctx.shgAccent.mode() === 'fixed', 'a palette the visitor picked (tulsi): no accent painted over it');
ok(p.html.getAttribute('data-palette') === 'tulsi' && !p.fading(), '... the palette stays stamped, no fade at load');
p = load({ ls: { 'shg:palette': 'shg' } });
ok(p.acc() === null, "the brand look picked on purpose ('shg') is Fixed too");
p = load({ ls: { 'shg:palette': '"lotus"' } });
ok(p.acc() === null, 'an old JSON-quoted palette value still counts as a pick');
p = load({ ls: { 'shg:palette': '3' } });
ok(p.acc() === 'mon', "a retired day-palette value ('3') is not a pick: weekly");
p = load({ ls: { 'shg:palette': 'tulsi', 'shg:accent': 'weekly' } });
ok(p.acc() === 'mon', 'Weekly chosen on purpose beats a picked palette');
p = load({ ls: { 'shg:accent': 'fixed' } });
ok(p.acc() === null, 'Fixed chosen on purpose: no accent');
p = load({ search: '?counter=1' });
ok(p.acc() === null, 'the staff counter (?counter=1) keeps the brand colour');
p = load({ lsBad: true, ssBad: true });
ok(p.acc() === 'mon', 'storage blocked: weekly, no crash');

/* Weekly / Fixed taps + P5-5 fade */
p = load();
p.click(p.btns[1]);
ok(p.ls.get('shg:accent') === 'fixed' && p.acc() === null, 'tap Fixed: saved and the accent goes');
ok(p.btns[1].getAttribute('aria-checked') === 'true' && p.btns[0].getAttribute('aria-checked') === 'false', '... the radios follow');
ok(p.fading(), '... with the 1 s colour fade');
p.advance(1150);
ok(!p.fading(), '... which is gone after 1.1 s');
p.click(p.btns[0]);
ok(p.ls.get('shg:accent') === 'weekly' && p.acc() === 'mon' && p.fading(), 'tap Weekly: the day colour fades back in');
p = load({ ls: { 'shg:palette': 'tulsi' } });
p.ctx.shgAccent.set('weekly');
ok(p.acc() === 'mon', 'a palette visitor can turn the rotation back on');
p = load({ html: { 'data-motion': 'reduced' } });
p.click(p.btns[1]);
ok(p.acc() === null && !p.fading(), 'in-app Reduced motion: the colour changes at once, no fade');
p = load({ reduce: true });
p.click(p.btns[1]);
ok(p.acc() === null && !p.fading(), "the phone's reduce-motion setting: no fade");
p = load();
p.ctx.shgAccent.set('weekly');
ok(!p.fading(), 'no fade when nothing actually changes');

/* midnight + resume */
p = load({ now: Date.UTC(2026, 10, 16, 18, 29, 30) });   // Mon 23:59:30 IST
ok(p.acc() === 'mon', 'Monday 23:59:30 IST: Monday');
p.advance(32000);
ok(p.acc() === 'tue' && p.fading(), 'IST midnight passes: Tuesday, with the fade, no reload');
p = load({ now: Date.UTC(2026, 10, 16, 18, 29, 30) });
p.advance(1); p.ctx.__now = () => Date.UTC(2026, 10, 17, 3, 0);
p.fire('doc', 'visibilitychange');
ok(p.acc() === 'tue' && p.fading(), 'coming back to the app next morning: the new day fades in');

/* C7 accent preview */
p = load({ search: '?accent_preview=sat', hash: '#/my' });
ok(p.acc() === 'sat', '?accent_preview=sat on a Monday: Saturday is shown');
const pv = JSON.parse(p.ss.get('shg:accentPreview') || 'null');
ok(pv && pv.key === 'sat' && pv.until === MON_NOON + 300000, 'kept in sessionStorage for 5 minutes (this tab only)');
ok(!p.ls.has('shg:accent') && !p.ls.has('shg:palette'), 'nothing is saved for the visitor');
ok(p.replaced[0] === '/#/my' && p.loc.search === '', 'the address drops the parameter (a reload later does not restart it), the hash stays');
p.advance(299000);
ok(p.acc() === 'sat', 'still Saturday at 4:59');
p.advance(2000);
ok(p.acc() === 'mon' && p.fading(), 'after 5 minutes it fades back to the real day');
p = load({ search: '?accent_preview=sat', ls: { 'shg:accent': 'fixed' } });
ok(p.acc() === 'sat', 'the preview shows even where the visitor chose Fixed');
p = load({ search: '?accent_preview=sat&counter=1' });
ok(p.acc() === null, 'never on the staff counter');
p = load({ search: '?x=1&accent_preview=sat&y=2' });
ok(p.replaced[0] === '/?x=1&y=2' && p.acc() === 'sat', 'other parameters are kept');
p = load({ search: '?accent_preview=purple' });
ok(p.acc() === 'mon' && !p.ss.has('shg:accentPreview') && p.replaced[0] === '/', 'an unknown key is ignored (and dropped from the address)');
p = load({ search: '?accent_preview=sat' });
const ssMap = p.ss;
p = load({ ssMap, now: MON_NOON + 60000 });
ok(p.acc() === 'sat', 'a reload in the same tab within 5 minutes keeps the preview');
p = load({ now: MON_NOON + 60000 });
ok(p.acc() === 'mon', 'another tab is not affected');
p = load({ ss: { 'shg:accentPreview': JSON.stringify({ key: 'sat', until: MON_NOON + 36e6 }) } });
ok(p.acc() === 'mon', 'a hand-edited preview longer than 5 minutes is refused');
p = load({ ss: { 'shg:accentPreview': '{bad json' } });
ok(p.acc() === 'mon', 'a broken preview value is ignored');
process.stdout.write(JSON.stringify(out));
JS;
    file_put_contents($tmp . '-harness.js', $harness);
    $res = (string) shell_exec('node ' . escapeshellarg($tmp . '-harness.js') . ' ' . escapeshellarg($tmp . '-head.js') . ' 2>&1');
    @unlink($tmp . '-head.js');
    @unlink($tmp . '-harness.js');
    $rows = json_decode($res, true);
    $ok(is_array($rows), 'the harness ran' . (is_array($rows) ? '' : ': ' . substr($res, 0, 400)));
    foreach (is_array($rows) ? $rows : [] as [$c, $what]) {
        $ok((bool) $c, (string) $what);
    }
}

echo "\n" . ($fail === 0 ? "ALL PASSED: $pass" . ($skip ? " (skipped $skip)" : '') : "FAILED: $fail (passed $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
