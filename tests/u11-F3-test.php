<?php
/**
 * =====================================================================
 *  u11-F3-test.php — Master v2 unit F3-ui (16 Nov 2026)
 *
 *  1. Ticket PNR, light theme. The customer's ticket confirmation
 *     (SmartBook modal) showed an empty pill above the ticket picture:
 *     smartbook.css paints .sb-tk-pnr as gradient text (background-clip:
 *     text + color:transparent) and premium3d.css, loaded later, draws the
 *     pill with the `background` SHORTHAND - which resets background-clip,
 *     so the gradient became the pill and the letters stayed transparent.
 *     polish.css (the last stylesheet) gives the PNR the brand colour in the
 *     light theme; the dark theme keeps its own rules.
 *       - static: the stylesheet order of app.template.html; the fix rule;
 *         a scan of EVERY stylesheet the app loads, in order, for a
 *         gradient-text rule that a later `background:` shorthand on the
 *         same selector breaks - each one must be put right later for the
 *         light theme (today: exactly the PNR one, by polish.css §10);
 *       - with SHG_TEST_BASE + node + Edge/Chrome: the real app at 360 px,
 *         light and dark, a real booking ('Test Yatri' 9800000000, a future
 *         date) opened in the real modal: the light PNR is --sb-primary at
 *         4.5:1 or better on its pill, the dark PNR keeps its white + glow,
 *         and no text anywhere on the page is transparent without a
 *         gradient painting it. The booking is deleted afterwards.
 *  2. admin/wa-inbox.php at 360 px: the floating corner button (the AI
 *     settings bubble, or the "🤖 AI" link for staff without it) covered
 *     the right end of "Pause bot 2 h" when the page scrolled that row past
 *     it. The Pause / Resume row keeps the button's corner free.
 *       - static: the row's class + the two :has() rules; a tripwire on the
 *         bubble / link size the padding was measured against;
 *       - with the browser: a superadmin (bubble) and a support login (AI
 *         link), light and dark, plus the paused (Resume) state: each
 *         button scrolled onto the floating button's row overlaps it by
 *         0 px and every tap point inside it reaches the button.
 *  3. Easy Settings labels (includes/aiops.php): agent_followups_on says
 *     service follow-ups (never "गुनासो" - agents never see complaints);
 *     helpdesk_cancel_escalate_inr says it opens only when a refund is owed.
 *  4. The battery sees all this (static, no server):
 *       - no suite in tests/ still looks for the OLD wording of the two
 *         labels ("Agents see follow-ups on their own passengers", "Open a
 *         follow-up when a cancelled ticket is above") - u11-Y2 did, so it
 *         went 49/1 and turned run-all red after the label change;
 *       - every phrase u11-Y2's "the four new switches are on the page"
 *         check looks for is in an Easy Settings label (the same check,
 *         without the database or a page render);
 *       - tests/run-all.php runs this suite (CORE_SUITES or HTTP_SUITES):
 *         run-all names its suites one by one, so an unlisted file is never
 *         run; and every tests/u11-*-test.php is either run or declared in
 *         KNOWN_STALE.
 *
 *  shari_test only; every row and file it makes is removed.
 *
 *    php -c .claude/php-dev.ini tests/u11-F3-test.php
 *    SHG_TEST_BASE=http://localhost:9013 php -c .claude/php-dev.ini tests/u11-F3-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aiops.php';

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0; $SKIP = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function skip(string $l): void
{
    global $SKIP;
    $SKIP++;
    echo "  SKIP  $l\n";
}

/* ---------- CSS helpers (static part) ---------- */

/** a selector list split at its top-level commas (":is(a, b)" stays one) */
function splitSelectors(string $s): array
{
    $out = []; $depth = 0; $cur = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '(') { $depth++; } elseif ($c === ')') { $depth--; }
        if ($c === ',' && $depth === 0) { $out[] = trim($cur); $cur = ''; continue; }
        $cur .= $c;
    }
    $out[] = trim($cur);
    return array_values(array_filter($out, static fn(string $x): bool => $x !== ''));
}

/** every innermost rule of the files, in load order: [sel, decls (';' + lower-case, no spaces), where] */
function cssRules(string $dir, array $files): array
{
    $rules = [];
    foreach ($files as $f) {
        // comments out, their line breaks kept (so "file:line" points at the real line)
        $css = (string) preg_replace_callback('~/\*.*?\*/~s', static fn(array $c): string => str_repeat("\n", substr_count($c[0], "\n")),
            (string) @file_get_contents($dir . '/' . $f));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($mm as $r) {
            $sel = trim((string) preg_replace('/\s+/', ' ', $r[1][0]));
            if ($sel === '' || $sel[0] === '@') {
                continue;
            }
            $start = $r[1][1] + strlen($r[1][0]) - strlen(ltrim($r[1][0]));   // the selector's own first character
            $at    = $f . ':' . (substr_count(substr($css, 0, $start), "\n") + 1);
            $d  = ';' . strtolower((string) preg_replace('/\s+/', '', $r[2][0]));
            foreach (splitSelectors($sel) as $one) {
                $rules[] = ['sel' => $one, 'd' => $d, 'at' => $at];
            }
        }
    }
    return $rules;
}
function isGradientText(string $d): bool
{
    return preg_match('/background-clip:text/', $d) === 1
        && (preg_match('/;color:transparent/', $d) === 1 || preg_match('/-webkit-text-fill-color:transparent/', $d) === 1);
}
function setsVisibleText(string $d): bool
{
    return preg_match('/;color:(?!transparent)[^;]+/', $d) === 1 && preg_match('/-webkit-text-fill-color:transparent/', $d) !== 1;
}
/**
 * A gradient-text rule that a LATER rule on the same selector breaks with the
 * `background:` shorthand (it resets background-clip to border-box) without
 * re-stating background-clip:text or a visible colour. 'fix' = the later
 * light-theme rule ("<anything> <selector>", not a dark-only one) that gives
 * the text a visible colour again, or null.
 *
 * @return list<array{sel:string,grad:string,shorthand:string,fix:?string}>
 */
function gradientClashes(array $rules): array
{
    $out = [];
    $n   = count($rules);
    foreach ($rules as $i => $g) {
        if (!isGradientText($g['d'])) {
            continue;
        }
        for ($j = $i + 1; $j < $n; $j++) {
            $r = $rules[$j];
            if ($r['sel'] !== $g['sel'] || preg_match('/;background:/', $r['d']) !== 1
                || preg_match('/background-clip:text/', $r['d']) === 1 || setsVisibleText($r['d'])) {
                continue;
            }
            $fix = null;
            for ($k = $j + 1; $k < $n; $k++) {
                $s = $rules[$k]['sel'];
                if ($s !== $g['sel'] && !str_ends_with($s, ' ' . $g['sel'])) {
                    continue;
                }
                if (str_contains(str_replace(':not([data-theme="dark"])', '', $s), 'data-theme="dark"')) {
                    continue;   // a dark-only rule does not help the light theme
                }
                if (setsVisibleText($rules[$k]['d'])) {
                    $fix = $rules[$k]['at'] . ' ' . $s;
                }
            }
            $out[] = ['sel' => $g['sel'], 'grad' => $g['at'], 'shorthand' => $r['at'], 'fix' => $fix];
        }
    }
    return $out;
}

/* ---------- browser helpers (runtime part) ---------- */

/** Edge / Chrome (SHG_TEST_BROWSER overrides), or null */
function findBrowser(): ?string
{
    foreach ([(string) getenv('SHG_TEST_BROWSER'), 'C:\Program Files\Google\Chrome\Application\chrome.exe',
              'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe', 'C:\Program Files\Microsoft\Edge\Application\msedge.exe',
              '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/microsoft-edge'] as $p) {
        if ($p !== '' && is_file($p)) { return $p; }
    }
    return null;
}
/** node for the DevTools driver (SHG_TEST_NODE overrides), or null */
function findNode(): ?string
{
    foreach (array_filter([(string) getenv('SHG_TEST_NODE'), 'node']) as $cand) {
        $p = @proc_open([$cand, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) { continue; }
        $v = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($p) === 0 && preg_match('/^v(\d+)\./', trim($v), $m) === 1 && (int) $m[1] >= 22) {
            return $cand;   // 22+: fetch + WebSocket built in
        }
    }
    return null;
}
function rmTree(string $dir): void
{
    for ($try = 0; $try < 6 && is_dir($dir); $try++) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($dir);
        if (is_dir($dir)) { usleep(400000); }   // the browser may still hold its profile for a moment
    }
}

/* The DevTools driver: its own browser + profile (port 0, read back from
   DevToolsActivePort - never a fixed port another run could hold), a phone
   viewport, the cookies / storage it is given, then nav / wait / eval steps.
   Prints {results:[...]} - one entry per eval. */
const DRIVER = <<<'JS'
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
const cfg = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const prof = cfg.profile;
fs.mkdirSync(prof, { recursive: true });
const ch = spawn(cfg.browser, ['--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check', '--disable-extensions',
  '--hide-scrollbars', '--remote-debugging-port=0', '--user-data-dir=' + prof, 'about:blank'], { stdio: ['ignore', 'ignore', 'pipe'] });
ch.stderr.on('data', () => {});
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const out = { results: [] };
let ws = null, id = 0; const pending = new Map();
function send(method, params = {}) {
  return new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); ws.send(JSON.stringify({ id: i, method, params })); });
}
try {
  let port = 0, url = '';
  for (let i = 0; i < 120 && !url; i++) {
    try {
      if (!port) { port = parseInt(fs.readFileSync(path.join(prof, 'DevToolsActivePort'), 'utf8').split('\n')[0], 10) || 0; }
      if (port) { const pg = (await (await fetch('http://127.0.0.1:' + port + '/json/list')).json()).find((t) => t.type === 'page'); if (pg) { url = pg.webSocketDebuggerUrl; } }
    } catch (e) {}
    if (!url) { await sleep(250); }
  }
  if (!url) { throw new Error('the browser did not open its DevTools port'); }
  ws = new WebSocket(url);
  await new Promise((r, j) => { ws.addEventListener('open', r); ws.addEventListener('error', j); });
  ws.addEventListener('message', (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { const p = pending.get(d.id); pending.delete(d.id); d.error ? p.rej(new Error(JSON.stringify(d.error))) : p.res(d.result); }
  });
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await send('Network.setCacheDisabled', { cacheDisabled: true });
  await send('Network.setBypassServiceWorker', { bypass: true });
  await send('Emulation.setDeviceMetricsOverride', { width: cfg.width, height: cfg.height, deviceScaleFactor: 2, mobile: true });
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: cfg.scheme }, { name: 'prefers-reduced-motion', value: 'no-preference' }] });
  for (const c of (cfg.cookies || [])) { await send('Network.setCookie', c); }
  if (cfg.init) { await send('Page.addScriptToEvaluateOnNewDocument', { source: cfg.init }); }
  for (const s of cfg.steps) {
    if (s.nav) { await send('Page.navigate', { url: s.nav }); await sleep(s.after || 3000); }
    else if (s.eval) {
      const r = await send('Runtime.evaluate', { expression: s.eval, awaitPromise: true, returnByValue: true });
      out.results.push(r.exceptionDetails ? { error: (r.exceptionDetails.exception && r.exceptionDetails.exception.description) || r.exceptionDetails.text } : r.result.value);
      if (s.after) { await sleep(s.after); }
    }
  }
} catch (e) {
  out.error = String((e && e.message) || e);
} finally {
  try { if (ws) { ws.close(); } } catch (e) {}
  ch.kill();
  await sleep(600);
  process.stdout.write(JSON.stringify(out));
  process.exit(0);
}
JS;

/** run one browser session; null when the driver gave nothing back */
function browse(string $node, string $browser, array $cfg): ?array
{
    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_u11f3_' . getmypid() . '_' . bin2hex(random_bytes(3));
    @mkdir($tmp, 0777, true);
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'driver.mjs', DRIVER);
    $cfg['browser'] = $browser;
    $cfg['profile'] = $tmp . DIRECTORY_SEPARATOR . 'profile';
    file_put_contents($tmp . DIRECTORY_SEPARATOR . 'cfg.json', json_encode($cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $out  = $tmp . DIRECTORY_SEPARATOR . 'out.json';
    $proc = proc_open([$node, $tmp . DIRECTORY_SEPARATOR . 'driver.mjs', $tmp . DIRECTORY_SEPARATOR . 'cfg.json'],
        [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $tmp . DIRECTORY_SEPARATOR . 'err.txt', 'w']], $pipes);
    $res = null;
    if (is_resource($proc)) {
        fclose($pipes[0]);
        $until = time() + 120;
        while (proc_get_status($proc)['running'] && time() < $until) { usleep(200000); }
        if (proc_get_status($proc)['running']) { proc_terminate($proc); }
        proc_close($proc);
        $res = json_decode((string) @file_get_contents($out), true);
    }
    rmTree($tmp);
    return is_array($res) ? $res : null;
}

/** WCAG contrast of two "rgb(...)" colours */
function rgbOf(string $c): ?array
{
    return preg_match('/rgba?\(\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?\s*\)/', $c, $m) === 1
        ? [(float) $m[1], (float) $m[2], (float) $m[3], isset($m[4]) && $m[4] !== '' ? (float) $m[4] : 1.0] : null;
}
function contrast(array $a, array $b): float
{
    $lum = static function (array $c): float {
        $f = static function (float $v): float { $v /= 255; return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
        return 0.2126 * $f($c[0]) + 0.7152 * $f($c[1]) + 0.0722 * $f($c[2]);
    };
    $x = $lum($a); $y = $lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}
function over(array $top, array $under): array
{
    $a = $top[3];
    return [$top[0] * $a + $under[0] * (1 - $a), $top[1] * $a + $under[1] * (1 - $a), $top[2] * $a + $under[2] * (1 - $a), 1.0];
}

/* text that is transparent with no background-clip:text gradient painting it (itself or an ancestor) */
const AUDIT_JS = <<<'JS'
(function () {
  var T = 'rgba(0, 0, 0, 0)', out = [];
  function own(el) { for (var n = el.firstChild; n; n = n.nextSibling) { if (n.nodeType === 3 && n.textContent.trim()) { return n.textContent.trim(); } } return ''; }
  document.querySelectorAll('body *').forEach(function (el) {
    var t = own(el); if (!t || /^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE|OPTION)$/.test(el.tagName)) { return; }
    if (getComputedStyle(el).webkitTextFillColor !== T) { return; }
    for (var a = el, d = 0; a && a.nodeType === 1 && d < 8; a = a.parentElement, d++) {
      var ac = getComputedStyle(a);
      if ((ac.backgroundClip === 'text' || ac.webkitBackgroundClip === 'text') && ac.backgroundImage !== 'none') { return; }
    }
    out.push(el.tagName.toLowerCase() + (typeof el.className === 'string' && el.className ? '.' + el.className.trim().split(/\s+/).join('.') : '') + ' "' + t.slice(0, 30) + '"');
  });
  return out;
})()
JS;

/* the ticket in the real modal: the booking goes into this browser's bookings
   list, the hash opens it (ticketFromHash -> showTicket, the code a fresh
   booking runs), then the PNR pill is read once its picture has loaded */
const PNR_JS = <<<'JS'
(async function () {
  var sb = document.getElementById('smartBook');
  if (!sb || typeof DB === 'undefined') { return { err: 'no #smartBook / DB on the page' }; }
  DB.bookings.push({ id: '__PNR__', dlKey: '__KEY__', from: 'Surat', to: 'Rupaidiha', date: '__DATE__', seats: ['A1'], total: 2200, status: 'confirmed' });
  location.hash = '#/?ticket=__PNR__';
  var p = null, t0 = Date.now();
  while (Date.now() - t0 < 20000) {
    p = document.querySelector('#sbModal .sb-tk-pnr');
    if (p && document.querySelector('#sbModal .sb-tk-imgw.ok')) { break; }
    await new Promise(function (r) { setTimeout(r, 250); });
  }
  if (!p) { return { err: 'the ticket modal never opened' }; }
  await new Promise(function (r) { setTimeout(r, 1600); });   // the pop + sweep animations finish
  var cs = getComputedStyle(p), probe = document.createElement('i');
  probe.style.color = 'var(--sb-primary)'; sb.appendChild(probe);
  var brand = getComputedStyle(probe).color; probe.remove();
  var under = 'rgb(255, 255, 255)';
  for (var a = p.parentElement; a; a = a.parentElement) { var bc = getComputedStyle(a).backgroundColor; if (bc !== 'rgba(0, 0, 0, 0)') { under = bc; break; } }
  return { theme: document.documentElement.getAttribute('data-theme'), modal: !document.getElementById('sbModal').hidden,
    text: p.textContent, color: cs.color, fill: cs.webkitTextFillColor, clip: cs.backgroundClip, bgImg: cs.backgroundImage,
    shadow: cs.textShadow, brand: brand, under: under, ok: !!document.querySelector('#sbModal .sb-tk-imgw.ok') };
})()
JS;

/* the Pause / Resume row against the floating corner button: each button is
   scrolled onto the floating button's row, then its overlap and every tap
   point inside its rounded shape are measured */
const PAUSE_JS = <<<'JS'
(function () {
  var fab = document.querySelector('.shg-aib-fab') || document.getElementById('admAiGo');
  var btns = Array.prototype.slice.call(document.querySelectorAll('form.wi-pause button[name="hours"]'));
  var res = { fab: fab ? (fab.id || fab.className) : null, fabShown: !!(fab && getComputedStyle(fab).display !== 'none'), n: btns.length, btns: [],
    theme: document.documentElement.getAttribute('data-theme') };
  if (!fab || !btns.length) { return res; }
  btns.forEach(function (b) {
    var fb = fab.getBoundingClientRect(), gb = b.getBoundingClientRect();
    window.scrollBy(0, (gb.top + gb.height / 2) - (fb.top + fb.height / 2));
    fb = fab.getBoundingClientRect(); gb = b.getBoundingClientRect();
    var vh = document.documentElement.clientHeight;
    var ox = Math.max(0, Math.min(gb.right, fb.right) - Math.max(gb.left, fb.left)), oy = Math.max(0, Math.min(gb.bottom, fb.bottom) - Math.max(gb.top, fb.top));
    var rad = Math.min(parseFloat(getComputedStyle(b).borderTopLeftRadius) || 0, gb.height / 2, gb.width / 2), n = 0, miss = 0;
    for (var x = gb.left + 3; x < gb.right - 2; x += 5) { for (var y = gb.top + 3; y < gb.bottom - 2; y += 5) {
      if (y < 0 || y > vh) { continue; }
      if (rad >= 3) {
        var cx = Math.min(Math.max(x, gb.left + rad), gb.right - rad), cy = Math.min(Math.max(y, gb.top + rad), gb.bottom - rad);
        if ((x - cx) * (x - cx) + (y - cy) * (y - cy) > (rad - 2) * (rad - 2)) { continue; }
      }
      n++; var e = document.elementFromPoint(x, y); if (!e || !(e === b || b.contains(e))) { miss++; }
    } }
    res.btns.push({ t: b.textContent.trim(), overlap: Math.round(ox * oy), pts: n, miss: miss, onRow: Math.abs((gb.top + gb.bottom) / 2 - (fb.top + fb.bottom) / 2) < 2,
      box: [Math.round(gb.left), Math.round(gb.top), Math.round(gb.right), Math.round(gb.bottom)], fabBox: [Math.round(fb.left), Math.round(fb.top), Math.round(fb.right), Math.round(fb.bottom)] });
  });
  res.hscroll = document.documentElement.scrollWidth > document.documentElement.clientWidth + 1;
  return res;
})()
JS;

echo "\n=== u11-F3: ticket PNR (light), WhatsApp inbox Pause row vs the floating button, Easy Settings labels ===\n\n";

/* ================= 3. Easy Settings labels ================= */
$fl = (string) (AiOps::EASY_KEYS['agent_followups_on'][0] ?? '');
$cl = (string) (AiOps::EASY_KEYS['helpdesk_cancel_escalate_inr'][0] ?? '');
check('agent_followups_on: "service follow-ups", bilingual', str_contains($fl, 'एजेन्टलाई आफ्नो यात्रुको सेवा फलो-अप देखाउने · Agents see service follow-ups on their own passengers'), $fl);
check('…never "गुनासो" (complaint): agents see service tickets only', !str_contains($fl, 'गुनासो') && stripos($fl, 'complaint') === false);
check('helpdesk_cancel_escalate_inr: opens only when a refund is owed, bilingual',
    str_contains($cl, 'रिफन्ड बाँकी भएको यति ₹ भन्दा माथिको रद्द टिकटमा फलो-अप खोल्ने · Open a follow-up when a cancelled ticket at or above ₹ has a refund owed'), $cl);
check('…the two switches keep their type, aliases and limits',
    (AiOps::EASY_KEYS['agent_followups_on'][1] ?? '') === 'bool' && in_array('agent followup', AiOps::EASY_KEYS['agent_followups_on'][2] ?? [], true)
    && (AiOps::EASY_KEYS['helpdesk_cancel_escalate_inr'][1] ?? '') === 'int' && (AiOps::EASY_KEYS['helpdesk_cancel_escalate_inr'][3]['max'] ?? null) === 100000);
AiOps::registerEasyKeys();   // what easy-settings.php / the bubble do before a command
$ki = AiOps::keyInfo('helpdesk_cancel_escalate_inr');
$kf = AiOps::keyInfo('agent_followups_on');
check('…keyInfo() hands the same labels to the preview / apply line', ($ki['label'] ?? '') === $cl && ($kf['label'] ?? '') === $fl);

/* ================= 1. static: the PNR and every gradient-text rule ================= */
$tpl = (string) file_get_contents($ROOT . '/app.template.html');
preg_match_all('~<link rel="stylesheet" href="/assets/css/([a-z0-9_.-]+\.css)~', $tpl, $lm);
$order = $lm[1];
$pos   = array_flip($order);
check('app.template.html: polish.css is the LAST stylesheet', ($order[count($order) - 1] ?? '') === 'polish.css', implode(' ', $order));
check('…after smartbook.css and premium3d.css (it wins their clash)', isset($pos['smartbook.css'], $pos['premium3d.css'], $pos['polish.css'])
    && $pos['smartbook.css'] < $pos['premium3d.css'] && $pos['premium3d.css'] < $pos['polish.css']);

$polish = (string) file_get_contents($ROOT . '/assets/css/polish.css');
check('polish.css: the light-theme PNR is the brand colour, its fill follows',
    preg_match('/:root:not\(\[data-theme="dark"\]\) #smartBook \.sb-tk-pnr\{color:var\(--sb-primary,#1a44c8\);-webkit-text-fill-color:currentColor\}/', $polish) === 1);
$darkTouch = false;
foreach (cssRules($ROOT . '/assets/css', ['polish.css']) as $r) {
    if (str_contains($r['sel'], '.sb-tk-pnr') && str_contains(str_replace(':not([data-theme="dark"])', '', $r['sel']), 'data-theme="dark"')) { $darkTouch = true; }
}
check('…and polish.css has no dark-theme .sb-tk-pnr rule (the dark theme is untouched)', !$darkTouch);
check('polish.css keeps LF line endings', !str_contains($polish, "\r"));

$clashes = gradientClashes(cssRules($ROOT . '/assets/css', $order));
$pnr     = array_values(array_filter($clashes, static fn(array $c): bool => $c['sel'] === '#smartBook .sb-tk-pnr'));
check('scan finds the PNR clash (smartbook.css gradient text, premium3d.css background shorthand)', count($pnr) === 1
    && str_starts_with($pnr[0]['grad'], 'smartbook.css:') && str_starts_with($pnr[0]['shorthand'], 'premium3d.css:'),
    json_encode($pnr, JSON_UNESCAPED_SLASHES));
check('…put right for the light theme by polish.css', ($pnr[0]['fix'] ?? '') !== '' && str_starts_with((string) $pnr[0]['fix'], 'polish.css:'), (string) ($pnr[0]['fix'] ?? 'none'));
$open = array_values(array_filter($clashes, static fn(array $c): bool => $c['fix'] === null));
check('no other gradient text in the app\'s ' . count($order) . ' stylesheets is left transparent by a later background shorthand', $open === [],
    implode('; ', array_map(static fn(array $c): string => $c['sel'] . ' (' . $c['grad'] . ' vs ' . $c['shorthand'] . ')', $open)));

/* ================= 2. static: the Pause row ================= */
$inbox = (string) file_get_contents($ROOT . '/admin/wa-inbox.php');
check('wa-inbox.php: the Pause / Resume form is .wi-pause', str_contains($inbox, '<form method="post" class="wi-inline wi-pause">')
    && substr_count($inbox, 'wi-pause">') === 1);
check('…it keeps the bubble\'s corner free (64px) and the AI link\'s (80px)',
    str_contains($inbox, 'body:has(.shg-aib-fab) .wi-pause{padding-right:64px}') && str_contains($inbox, 'body:has(.adm-ai-go) .wi-pause{padding-right:80px}'));
check('wa-inbox.php keeps LF line endings', !str_contains($inbox, "\r"));
$bub = (string) file_get_contents($ROOT . '/assets/js/admin-ai-bubble.js');
check('tripwire: the bubble is still 52px, 14px / 16px from the right (what 64px was measured against)',
    str_contains($bub, '.shg-aib-fab{position:fixed;right:16px;') && str_contains($bub, 'width:52px;height:52px')
    && str_contains($bub, '.shg-aib-fab{bottom:max(calc(88px + env(safe-area-inset-bottom,0px)),var(--admin-fab-bottom,0px));right:14px}'));
$guard = (string) file_get_contents($ROOT . '/admin/_guard.php');
check('tripwire: the "🤖 AI" link is still 16px in, 14px side padding (what 80px was measured against)',
    str_contains($guard, '.adm-ai-go{position:fixed;right:16px;') && str_contains($guard, 'padding:0 14px;') && str_contains($guard, '>🤖 AI</a>'));

/* ================= 4. the battery: no stale label check, this suite is run ================= */
/* The wording the two labels had before F3. A suite that still looks for it
   fails against the right code (u11-Y2:209 did: 49/1, run-all red). */
$oldLabels = [
    'agent_followups_on'           => 'Agents see follow-ups on their own passengers',
    'helpdesk_cancel_escalate_inr' => 'Open a follow-up when a cancelled ticket is above',
];
check('the old label wording is gone from includes/aiops.php',
    !str_contains($fl, $oldLabels['agent_followups_on']) && !str_contains($cl, $oldLabels['helpdesk_cancel_escalate_inr']));
$stale = [];
foreach (array_merge(glob($ROOT . '/tests/*.php') ?: [], glob($ROOT . '/tests/*.js') ?: []) as $tf) {
    if (basename($tf) === basename(__FILE__)) {
        continue;   // this file names the old wording only to look for it
    }
    $src = (string) @file_get_contents($tf);
    foreach ($oldLabels as $k => $old) {
        $p = strpos($src, $old);
        if ($p !== false) {
            $stale[] = basename($tf) . ':' . (substr_count(substr($src, 0, $p), "\n") + 1) . ' "' . $old . '" (' . $k . ')';
        }
    }
}
check('no suite in tests/ still looks for the old wording of the two labels', $stale === [],
    $stale === [] ? '' : implode('; ', $stale) . ' - now "Agents see service follow-ups on their own passengers" / "Open a follow-up when a cancelled ticket at or above"');

/* u11-Y2's "the four new switches are on the page": the phrases it looks for
   on easy-settings.php, each checked against the Easy Settings labels. */
$easyLabels = array_map(static fn(array $v): string => (string) ($v[0] ?? ''), AiOps::EASY_KEYS);
$y2  = (string) @file_get_contents($ROOT . '/tests/u11-Y2-aioffers-test.php');
$y2a = strpos($y2, "'the four new switches are on the page'");
$y2e = $y2a === false ? false : strpos($y2, ');', $y2a);
preg_match_all("/str_contains\(\\\$h,\s*'([^']+)'\)/", $y2a === false || $y2e === false ? '' : substr($y2, $y2a, $y2e - $y2a), $ym);
$y2Phrases = $ym[1] ?? [];
$y2Missing = array_values(array_filter($y2Phrases, static function (string $ph) use ($easyLabels): bool {
    foreach ($easyLabels as $l) {
        if (str_contains($l, $ph)) { return false; }
    }
    return true;
}));
check('u11-Y2 "the four new switches are on the page": its 4 phrases are found (tripwire: the check moved)', count($y2Phrases) === 4, json_encode($y2Phrases, JSON_UNESCAPED_UNICODE));
check('…and each one is in an Easy Settings label (else that suite fails against the right code)', count($y2Phrases) === 4 && $y2Missing === [],
    $y2Missing === [] ? '' : 'not in any label: ' . json_encode($y2Missing, JSON_UNESCAPED_UNICODE) . ' (tests/u11-Y2-aioffers-test.php:' . ($y2a === false ? '?' : substr_count(substr($y2, 0, $y2a), "\n") + 1) . ')');

/* tests/run-all.php names its suites one by one (no glob): a file in none of
   its lists is never run. Comments are taken out before the keys are read. */
$ra = (string) @file_get_contents($ROOT . '/tests/run-all.php');
$raLists = [];
preg_match_all('/^const\s+(CORE_SUITES|KNOWN_STALE|NODE_SUITES|HTTP_SUITES)\s*=\s*\[(.*?)^\];/ms', $ra, $rm, PREG_SET_ORDER);
foreach ($rm as $blk) {
    $body = (string) preg_replace(['~/\*.*?\*/~s', '~^\s*//.*$~m'], '', $blk[2]);
    preg_match_all("/^\s*'([^']+)'\s*=>/m", $body, $km);
    $raLists[$blk[1]] = $km[1];
}
check('tests/run-all.php: its four suite lists are read (tripwire for the parser below)',
    isset($raLists['CORE_SUITES'], $raLists['HTTP_SUITES'], $raLists['KNOWN_STALE'])
    && in_array('agent-wallet-test.php', $raLists['CORE_SUITES'], true) && in_array('e2e-booking-test.php', $raLists['HTTP_SUITES'], true),
    implode(', ', array_map(static fn(string $k, array $v): string => $k . ' ' . count($v), array_keys($raLists), $raLists)));
$runs = array_merge($raLists['CORE_SUITES'] ?? [], $raLists['HTTP_SUITES'] ?? []);
check('tests/run-all.php runs this suite (CORE_SUITES or HTTP_SUITES)', in_array(basename(__FILE__), $runs, true),
    in_array(basename(__FILE__), $runs, true) ? '' : 'add \'' . basename(__FILE__) . '\' to CORE_SUITES - the battery passes no SHG_TEST_BASE, so the static checks are what it runs');
$unrun = [];
foreach (glob($ROOT . '/tests/u11-*-test.php') ?: [] as $tf) {
    if (!in_array(basename($tf), $runs, true) && !in_array(basename($tf), $raLists['KNOWN_STALE'] ?? [], true)) {
        $unrun[] = basename($tf);
    }
}
check('every tests/u11-*-test.php is run by run-all.php or declared in KNOWN_STALE', $unrun === [], $unrun === [] ? '' : 'never run: ' . implode(', ', $unrun));

/* ================= the browser part ================= */
$base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
$node = $base !== '' ? findNode() : null;
$brw  = $base !== '' ? findBrowser() : null;
if ($base === '' || !function_exists('curl_init') || $node === null || $brw === null) {
    skip('360 px browser part (needs SHG_TEST_BASE, curl, node 22+ and Edge / Chrome)'
        . ($base !== '' ? ' — curl ' . (function_exists('curl_init') ? 'ok' : 'missing') . ', node ' . ($node ?? 'missing') . ', browser ' . ($brw ?? 'missing') : ''));
} elseif (DB_NAME !== 'shari_test') {
    skip('browser part: only on the shari_test database');
} else {
    require_once INCLUDE_PATH . '/fare.php';
    require_once INCLUDE_PATH . '/seats.php';
    require_once INCLUDE_PATH . '/qr.php';
    require_once INCLUDE_PATH . '/pdf.php';
    require_once INCLUDE_PATH . '/ticket.php';
    require_once INCLUDE_PATH . '/agentwallet.php';
    require_once INCLUDE_PATH . '/booking.php';
    require_once INCLUDE_PATH . '/quickunlock.php';
    require_once INCLUDE_PATH . '/wainbox.php';

    $tag   = (string) random_int(10000, 99999);
    $num   = '9188' . $tag . '913';
    $users = ['boss' => 'u11_f3_boss_' . $tag, 'sup' => 'u11_f3_sup_' . $tag];
    $pw    = 'U11f3Ui!' . $tag;
    $made  = ['booking' => 0, 'pnr' => '', 'schedule' => 0];
    $jars  = [];
    $q = static function (string $sql, array $p = []): void { try { Database::run($sql, $p); } catch (Throwable $e) {} };
    $cleanup = static function () use (&$made, &$jars, $users, $num, $q): void {
        if ($made['booking'] > 0) {
            foreach (['delivery_log', 'message_logs', 'ticket_links', 'msg_ledger'] as $t) {   // the rows with no cascade
                $q("DELETE FROM `$t` WHERE booking_id = ?", [$made['booking']]);
            }
            $q('DELETE FROM bookings WHERE id = ? AND contact_phone = ?', [$made['booking'], '9800000000']);
            foreach (glob(TICKET_PATH . '/ticket_' . $made['pnr'] . '*') ?: [] as $f) { @unlink($f); }
            $made['booking'] = 0;
        }
        if ($made['schedule'] > 0) {   // only the schedule row this run created, and only while nothing sits on it
            $q('DELETE FROM schedules WHERE id = ? AND NOT EXISTS (SELECT 1 FROM booking_legs WHERE schedule_id = ?)', [$made['schedule'], $made['schedule']]);
            $made['schedule'] = 0;
        }
        foreach ($users as $u) {
            $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $u], 0);
            if ($id > 0) {
                $q('DELETE FROM admin_sessions WHERE admin_id = ?', [$id]);
                $q('DELETE FROM admins WHERE id = ?', [$id]);
            }
            $q('DELETE FROM admin_login_events WHERE username = ?', [$u]);
        }
        $q("DELETE FROM wa_inbound WHERE wamid LIKE ?", ['wamid.u11f3.%']);
        $q("DELETE FROM kv_store WHERE kscope IN ('wa_bot_pause', 'wa_inbound', 'wa_greet', 'wa_agent') AND kkey IN (?, ?)", [$num, substr($num, -10)]);
        $q("DELETE FROM kv_store WHERE kscope = 'wa_inbox_seen_t' AND kkey LIKE ?", ['%:' . $num . '%']);
        $q('DELETE FROM audit_logs WHERE entity_id IN (?, ?)', [$num, substr($num, -10)]);
        $q("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')");
        foreach ($jars as $j) { @unlink($j); }
    };
    register_shutdown_function($cleanup);

    try {
        /* ---------- 1. the ticket modal, light and dark ---------- */
        $route = Database::fetch("SELECT * FROM routes WHERE coach_type = 'sleeper' AND is_active = 1 ORDER BY id LIMIT 1");
        $td    = date('Y-m-d', strtotime('+' . random_int(12, 40) . ' days'));
        $res   = null;
        if ($route !== null) {
            $had = (int) Database::scalar('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d ORDER BY id LIMIT 1', ['r' => (int) $route['id'], 'd' => $td], 0);
            $sch = Seats::schedule((int) $route['id'], $td);
            $made['schedule'] = $had > 0 ? 0 : (int) ($sch['id'] ?? 0);
            foreach (['L9', 'L10', 'L11', 'L12', 'U9', 'U10', 'U11', 'U12', 'L7', 'L8', 'U7', 'U8'] as $seat) {
                try {
                    $res = BookingService::create([
                        'routeId' => (int) $route['id'], 'travelDate' => $td, 'seats' => [$seat],
                        'passengers' => [['name' => 'Test Yatri', 'age' => 30, 'gender' => 'Male']],
                        'contact' => ['phone' => '9800000000'], 'bookingMode' => 'sharing',
                        'paymentMethod' => 'cod', 'isCod' => true, 'boarding' => '',
                    ]);
                    break;
                } catch (Throwable $e) {
                    $res = null;   // that berth is taken on this date - the next one
                }
            }
        }
        $pnr = is_array($res) ? (string) ($res['pnr'] ?? '') : '';
        $bk  = $pnr !== '' ? Database::fetch('SELECT id, status FROM bookings WHERE pnr = :p', ['p' => $pnr]) : null;
        if ($bk !== null) {
            $made['booking'] = (int) $bk['id'];
            $made['pnr']     = $pnr;
        }
        check('a synthetic booking for the modal: Test Yatri 9800000000 on ' . $td, $bk !== null && $bk['status'] === 'confirmed', $pnr . ' ' . ($bk['status'] ?? 'none'));
        if ($bk !== null) {
            parse_str((string) parse_url(Ticket::imageUrl($pnr), PHP_URL_QUERY), $tq);
            $js = str_replace(['__PNR__', '__KEY__', '__DATE__'], [$pnr, (string) ($tq['k'] ?? ''), $td], PNR_JS);
            foreach (['light', 'dark'] as $th) {
                $o = browse($node, $brw, ['width' => 360, 'height' => 740, 'scheme' => $th,
                    'init' => "try{localStorage.setItem('shg:theme','$th');}catch(e){}",
                    'steps' => [['nav' => $base . '/', 'after' => 6000], ['eval' => $js], ['eval' => AUDIT_JS]]]);
                $p = $o['results'][0] ?? null;
                $a = $o['results'][1] ?? null;
                if (!is_array($p) || isset($p['err']) || isset($p['error'])) {
                    check("$th: the real ticket modal opens at 360 px", false, json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                    continue;
                }
                check("$th: the real ticket modal opens at 360 px with its picture loaded", ($p['theme'] ?? '') === $th && !empty($p['modal']) && !empty($p['ok'])
                    && str_contains((string) ($p['text'] ?? ''), $pnr), (string) ($p['text'] ?? ''));
                if ($th === 'light') {
                    check('light: the PNR is the brand colour (--sb-primary), not transparent',
                        ($p['color'] ?? '') === ($p['brand'] ?? '-') && ($p['fill'] ?? '') === ($p['brand'] ?? '-') && ($p['fill'] ?? '') !== 'rgba(0, 0, 0, 0)',
                        'color ' . ($p['color'] ?? '') . ', fill ' . ($p['fill'] ?? '') . ', brand ' . ($p['brand'] ?? ''));
                    $txt = rgbOf((string) ($p['fill'] ?? '')); $und = rgbOf((string) ($p['under'] ?? ''));
                    preg_match_all('/rgba?\([^)]*\)/', (string) ($p['bgImg'] ?? ''), $stops);
                    $worst = 99.0;
                    foreach (($stops[0] ?: ['rgba(0, 0, 0, 0)']) as $s) {
                        $st = rgbOf($s);
                        if ($txt !== null && $und !== null && $st !== null) { $worst = min($worst, contrast($txt, over($st, $und))); }
                    }
                    check('light: the PNR reads at 4.5:1 or better on every part of its pill', $worst >= 4.5 && $worst < 99.0, sprintf('%.2f:1', $worst));
                    check('light: the pill itself is still drawn (premium3d.css)', str_contains((string) ($p['bgImg'] ?? ''), 'linear-gradient') && ($p['clip'] ?? '') === 'border-box');
                } else {
                    check('dark: unchanged - white PNR with its blue glow (smartbook.css + liquid.css)',
                        ($p['color'] ?? '') === 'rgb(255, 255, 255)' && ($p['fill'] ?? '') === 'rgb(255, 255, 255)'
                        && str_contains((string) ($p['shadow'] ?? ''), 'rgba(111, 155, 255, 0.55)') && ($p['bgImg'] ?? '') === 'none',
                        'color ' . ($p['color'] ?? '') . ', shadow ' . ($p['shadow'] ?? '') . ', bg ' . ($p['bgImg'] ?? ''));
                }
                check("$th: no text on the page is transparent without a gradient painting it", is_array($a) && !isset($a['error']) && $a === [],
                    json_encode($a, JSON_UNESCAPED_UNICODE));
            }
        }
        $cleanup();   // the booking goes now; the inbox part makes its own rows
        check('the synthetic booking, its rows and its ticket files are gone',
            $pnr === '' || (!Database::exists('SELECT 1 FROM bookings WHERE pnr = :p', ['p' => $pnr]) && (glob(TICKET_PATH . '/ticket_' . $pnr . '*') ?: []) === []));

        /* ---------- 2. the inbox Pause row at 360 px ---------- */
        $ids = [];
        foreach (['boss' => 'superadmin', 'sup' => 'support'] as $k => $role) {
            $ids[$k] = (int) Database::insert('admins', ['username' => $users[$k], 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
                'full_name' => 'U11 F3 ' . $k, 'role' => $role, 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
            QuickUnlock::set($ids[$k], 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
        }
        foreach (['Namaste, Surat bata bus kati baje chha?', 'Bholi ko ticket chahiyo 2 jana', 'Rupaidiha samma kati bhada?', 'ok dhanyabad'] as $i => $t) {
            Database::run("INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, created_at)
                           VALUES (:w, :f, 'text', :p, 'done', NOW() - INTERVAL :m MINUTE)",
                ['w' => 'wamid.u11f3.' . $tag . '.' . $i, 'f' => $num, 'p' => json_encode(['text' => ['body' => $t]], JSON_UNESCAPED_UNICODE), 'm' => 10 - $i]);
        }
        $sid = [];
        foreach ($users as $k => $u) {
            $q("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')");
            $jars[$k] = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'shg_u11f3_' . $k . '_' . getmypid() . '.txt';
            $get = static function (string $url, ?array $form = null) use ($base, $jars, $k): string {
                $ch = curl_init($base . $url);
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
                    CURLOPT_COOKIEJAR => $jars[$k], CURLOPT_COOKIEFILE => $jars[$k]]);
                if ($form !== null) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
                $body = (string) curl_exec($ch);
                curl_close($ch);
                return $body;
            };
            $login = $get('/admin/login.php?classic=1&other=1');
            $tok   = preg_match('/<meta name="csrf-token" content="([^"]+)"/', $login, $m) === 1 ? html_entity_decode($m[1]) : '';
            $get('/admin/login.php', [CSRF_TOKEN_NAME => $tok, 'classic' => '1', 'username' => $u, 'password' => $pw]);
            $page = $get('/admin/wa-inbox.php?tab=inbox&p=' . $num);
            $cookie = '';
            foreach (file($jars[$k], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                $f = explode("\t", $line);
                if (count($f) >= 7 && $f[5] === SESSION_NAME) { $cookie = $f[6]; }
            }
            $sid[$k] = $cookie;
            check("$k: signs in and the open chat has the Pause row", $cookie !== '' && str_contains($page, 'class="wi-inline wi-pause"') && str_contains($page, 'Pause bot 2 h'));
            check($k === 'boss' ? 'boss: the page carries the AI settings bubble' : 'support: no bubble - the "🤖 AI" link instead',
                $k === 'boss' ? str_contains($page, 'admin-ai-bubble.js') : (str_contains($page, 'id="admAiGo"') && !str_contains($page, 'admin-ai-bubble.js')));
        }
        $run = static function (string $k, string $th, string $label) use ($node, $brw, $base, $num, $sid): void {
            $o = browse($node, $brw, ['width' => 360, 'height' => 740, 'scheme' => $th,
                'cookies' => [['name' => SESSION_NAME, 'value' => $sid[$k], 'url' => $base . '/']],
                'init' => "try{localStorage.setItem('shg_admin_theme','$th');}catch(e){}",
                'steps' => [['nav' => $base . '/admin/wa-inbox.php?tab=inbox&p=' . $num, 'after' => 4500], ['eval' => PAUSE_JS]]]);
            $r = $o['results'][0] ?? null;
            if (!is_array($r) || isset($r['error'])) {
                check("$label: the inbox renders in the browser", false, json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                return;
            }
            $want = $k === 'boss' ? 'shg-aib-fab' : 'admAiGo';
            check("$label: the floating button is on screen ($want), theme $th", ($r['fab'] ?? '') === $want && !empty($r['fabShown']) && ($r['theme'] ?? '') === $th
                && (int) ($r['n'] ?? 0) >= 1, json_encode($r, JSON_UNESCAPED_UNICODE));
            foreach ((array) ($r['btns'] ?? []) as $b) {
                check("$label: \"{$b['t']}\" on the floating button's row overlaps it by 0 px and every tap reaches it",
                    !empty($b['onRow']) && (int) $b['overlap'] === 0 && (int) $b['pts'] > 40 && (int) $b['miss'] === 0,
                    'button ' . json_encode($b['box']) . ' / floating ' . json_encode($b['fabBox']) . ', ' . $b['miss'] . ' of ' . $b['pts'] . ' taps missed');
            }
            check("$label: no sideways scroll at 360 px", empty($r['hscroll']));
        };
        $run('boss', 'light', 'bubble, light');
        $run('boss', 'dark', 'bubble, dark');
        $run('sup', 'light', 'AI link, light');
        WaInbox::pause($num, 2, $ids['boss']);   // the paused state: "Bot paused until …" + "Resume the bot"
        check('the thread is paused for the Resume check', WaInbox::botPaused($num));
        $run('boss', 'light', 'paused (Resume), bubble');
    } catch (Throwable $e) {
        check('no exception: ' . get_class($e) . ' ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        $cleanup();
    }
    check('cleanup: no test admin, inbox row, pause or booking is left',
        !Database::exists('SELECT 1 FROM admins WHERE username IN (:a, :b)', ['a' => $users['boss'], 'b' => $users['sup']])
        && !Database::exists("SELECT 1 FROM wa_inbound WHERE wamid LIKE 'wamid.u11f3.%'")
        && !WaInbox::botPaused($num)
        && ($made['pnr'] === '' || !Database::exists('SELECT 1 FROM bookings WHERE pnr = :p', ['p' => $made['pnr']])));
}

echo "\n  u11-F3-test: $PASS passed, $FAIL failed" . ($SKIP > 0 ? ", $SKIP skipped" : '') . "\n";
exit($FAIL > 0 ? 1 : 0);
