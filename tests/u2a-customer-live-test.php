<?php
/**
 * =====================================================================
 *  u2a-customer-live-test.php — 16 Nov 2026, master upgrade v2.0 (U2a).
 *
 *  No database, nothing is written. It reads the customer-app files and
 *  proves the U2a items are wired the way the gap analysis asked:
 *    P2-1d  install offer: refusals counted in shg:installDismissN, quiet
 *           after 2; the iOS "Got it" is not a refusal
 *    P2-1h  the menu's #pwaInstallMenu: shown only while installable and
 *           not standalone, a tap opens the sheet even when quiet
 *    P2-2c  SmartBook ticket (customer only) carries the SHGPush card
 *    P2-3a  My Trips: silent 60 s refresh of <= 4 upcoming tickets
 *    P2-3b  SmartBook seat sheet: 30 s re-read while open + visible, gone
 *           berths dropped with a toast, tab return re-quotes an idle card
 *    P2-3e  the 14 px sync ring + its in-flight counter
 *    P4-1f  SmartBook near-me: nearme_on, nearme_max_km, suggest-only
 *    P4-2f  next-departure banner: the stop's real time, no 1:00 PM
 *    P5-6   notice banner from notice_text, 24 h, dismiss by text hash
 *
 *    php -c .claude/php-dev.ini tests/u2a-customer-live-test.php
 *  (node on PATH also syntax-checks the five JS files.)
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$root = dirname(__DIR__);
$pass = 0; $fail = 0; $skip = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};
$read = static fn(string $p): string => (string) file_get_contents($root . '/' . $p);
/** The body of a JS function `name(...) { ... }` (brace-matched), '' if absent. */
$fn = static function (string $src, string $head): string {
    $at = strpos($src, $head);
    if ($at === false) { return ''; }
    $open = strpos($src, '{', $at);
    if ($open === false) { return ''; }
    $depth = 0;
    for ($i = $open, $n = strlen($src); $i < $n; $i++) {
        if ($src[$i] === '{') { $depth++; }
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { return substr($src, $at, $i - $at + 1); } }
    }
    return '';
};

$pwa  = $read('assets/js/17-pwa.js');
$sb   = $read('assets/js/27-smartbook.js');
$rt   = $read('assets/js/05-router.js');
$res  = $read('assets/js/06-results.js');
$co   = $read('assets/js/07-checkout.js');
$i18n = $read('assets/js/04-i18n.js');
$css  = $read('assets/css/polish.css');
$api  = $read('api/search.php');
$tpl  = $read('app.template.html');

echo "\n0. The JS files parse\n";
$node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
if ($node === '') {
    $skip++; echo "  skip  node not on PATH - syntax not checked\n";
} else {
    foreach (['17-pwa.js', '27-smartbook.js', '05-router.js', '06-results.js', '07-checkout.js', '04-i18n.js'] as $f) {
        $out = []; $rc = 0;
        exec('node --check ' . escapeshellarg($root . '/assets/js/' . $f) . ' 2>&1', $out, $rc);
        $ok($rc === 0, "node --check $f" . ($rc === 0 ? '' : ' - ' . implode(' ', array_slice($out, 0, 3))));
    }
}

echo "\n1. P2-1d install offer: two refusals and it stops asking\n";
$ok(str_contains($pwa, "NKEY = 'shg:installDismissN'"), "refusal counter key is 'shg:installDismissN'");
$quiet = $fn($pwa, 'function quiet()');
$ok($quiet !== '' && str_contains($quiet, 'refusals() >= 2'), 'quiet() is true after 2 refusals');
$refused = $fn($pwa, 'function refused()');
$ok(str_contains($refused, 'lsSet(NKEY') && str_contains($refused, 'refusals() + 1') && str_contains($refused, 'lsSet(DKEY'), 'refused() counts +1 and keeps the 30-day timer');
$ok(str_contains($fn($pwa, 'function dismiss()'), 'refused()'), "'Not now' / tap outside = a refusal");
$gotIt = $fn($pwa, 'function gotIt()');
$ok($gotIt !== '' && !str_contains($gotIt, 'NKEY') && !str_contains($gotIt, 'refused('), "the iOS 'Got it' is NOT counted");
$ok(str_contains($pwa, "addEventListener('click', ios ? gotIt : dismiss)"), "the iOS sheet's button is wired to gotIt, the Android one to dismiss");
$ok(substr_count($pwa, "c.outcome !== 'accepted') refused()") === 2, 'both declined browser prompts (sheet + strip) count');
$ok(str_contains($pwa, "q('#pwaInviteNo').addEventListener('click', function () { refused();"), "the strip's 'Continue in browser' counts");
$ok((bool) preg_match("~'appinstalled'.*?lsDel\(NKEY\)~s", $pwa), 'installing clears the counter');
$ok(!preg_match('~lsSet\(DKEY, String\(Date\.now\(\)\)\); inviteGone~', $pwa), 'no refusal path still writes only the timer');

echo "\n2. P2-1h the menu's Install item\n";
$sync = $fn($pwa, 'function menuSync(');
$ok($sync !== '' && str_contains($sync, "q('#pwaInstallMenu')"), 'menuSync() finds #pwaInstallMenu');
$ok(str_contains($sync, 'isStandalone()') && str_contains($sync, 'deferred') && str_contains($sync, 'isIOSSafari') && str_contains($sync, 'onStaffDoor()'),
    'shown only while installable (held prompt / iOS Safari), not standalone, not the staff door');
$wire = $fn($pwa, 'function menuWire(');
$ok(str_contains($wire, 'e.preventDefault()') && str_contains($wire, 'api.show()'), 'a tap stays on the screen (href="#") and calls SHGInstall.show()');
$ok(str_contains($wire, "classList.remove('open')"), 'the tap folds the phone menu away');
$ok((bool) preg_match("~'beforeinstallprompt'.*?menuSync\(\)~s", $pwa), 'the item appears when the browser offers an install');
$ok((bool) preg_match("~'appinstalled'.*?menuSync\(true\)~s", $pwa), 'the item hides once installed');
$ok(str_contains($pwa, 'showAndroid(true)') && str_contains($pwa, 'showIOS(true)'), 'show() is "asked for": it opens even inside the quiet period');
$ok(str_contains($fn($pwa, 'function showAndroid('), '(!force && quiet())') && str_contains($fn($pwa, 'function showIOS('), '(!force && quiet())'), 'only the automatic offers obey quiet()');
if (str_contains($tpl, 'id="pwaInstallMenu"')) {
    $ok((bool) preg_match('~<a[^>]*id="pwaInstallMenu"[^>]*\bhidden\b[^>]*>|<a[^>]*\bhidden\b[^>]*id="pwaInstallMenu"~', $tpl), 'app.template.html: the menu item starts hidden');
    $ok((bool) preg_match('~id="pwaInstallMenu"[^>]*data-i18n="pwaInstallBtn"|data-i18n="pwaInstallBtn"[^>]*id="pwaInstallMenu"~', $tpl), 'app.template.html: labelled by pwaInstallBtn');
} else {
    $skip++; echo "  skip  app.template.html has no #pwaInstallMenu yet (U2b adds it; 17-pwa.js is a no-op without it)\n";
}

echo "\n3. P2-2c departure alerts on the SmartBook ticket\n";
$show = $fn($sb, 'function showTicket(');
$ok(str_contains($show, 'if (!STAFF) pushCard(host, r);'), 'showTicket() adds the card for a customer only (never a staff sale)');
$pc = $fn($sb, 'function pushCard(');
$ok(str_contains($pc, 'P.card(b)') && str_contains($pc, 'P.wire(b, box)'), 'the existing SHGPush card + wire, scoped to the ticket box');
$ok(!preg_match('~shgApi\.post|api\(\{~', $pc), 'no new request or message type from the card code itself');
$pw = $fn($pwa, 'wire: function (b, root)');
$ok($pw !== '' && str_contains($pw, "q('#pushCard', R)") && str_contains($pw, "q('#pushOffBtn', R)"), 'SHGPush.wire(b, root) looks only inside its own box');
// Review fix: the SmartBook copy sits earlier in the page, so the classic #/ticket view wires its own card.
$ok(str_contains($co, "window.SHGPush.wire(b, \$('#statusBody'))") && !str_contains($co, 'window.SHGPush.wire(b);'), 'the classic ticket view wires the card inside #statusBody, not the first #pushCard in the page');

echo "\n4. P2-3a My Trips refresh\n";
$mp = $fn($rt, 'const MyPoll = {');
$ok(str_contains($mp, 'EVERY_MS: 60000') && str_contains($mp, 'MAX: 4'), 'every 60 s, at most 4 tickets');
$ok(str_contains($mp, 'syncBookings(ids)') && str_contains($mp, 'renderMyBookings()'), 'reuses syncBookings and redraws only on a change');
$ok(str_contains($mp, "b.status === 'pending' || b.status === 'confirmed'") && str_contains($mp, 'dep(b) > now'), 'only upcoming, non-cancelled tickets');
$ok(str_contains($mp, 'document.hidden') && str_contains($mp, 'this.onMy()'), 'stops off #/my, asks nothing while hidden');
$ok(str_contains($fn($rt, 'function router()'), 'MyPoll.start()') && str_contains($fn($rt, 'function router()'), 'MyPoll.stop()'), 'router() starts it on #/my and stops it elsewhere');
$ok((bool) preg_match("~visibilitychange', \(\) => \{\s*if \(document\.hidden\) \{ MyPoll\.stop\(\)~", $rt), 'a hidden tab has no timer');

echo "\n5. P2-3b SmartBook seat sheet refresh\n";
$ok(str_contains($sb, 'SEAT_MS = 30000'), 'every 30 s');
$ok(str_contains($fn($sb, 'function openSheet('), 'seatPoll(need, m)') && str_contains($fn($sb, 'function closeSheet('), 'seatPollStop()'), 'starts with the sheet, stops when it closes');
$st = $fn($sb, 'function seatTick(');
$ok(str_contains($st, "action: 'seats'") && str_contains($st, 'document.hidden') && str_contains($st, 'drawMap(m, need)'), 're-reads the coach only while visible and redraws');
$ok(str_contains($st, "gw('tSeatGone'") && str_contains($st, 'pick = pick.filter'), 'a berth somebody took leaves the pick, with a toast');
$ok((bool) preg_match("~visibilitychange', function \(\) \{.*?seatTick\(\).*?overlayOpen\(\).*?requote\(0\)~s", $sb), 'tab return: open sheet asks at once; idle card re-quotes');
$ok(str_contains($sb, "S.busy || S.idem || qPend || overlayOpen()"), 'never while a sale / quote is on the way or a sheet is open');
// Review fix: a sale whose answer was lost keeps S.idem for the retry - a tab-return requote must not drop it.
$ok((bool) preg_match("~visibilitychange', function \(\) \{.*?if \(!S\.q \|\| S\.busy \|\| S\.idem \|\|~s", $sb), 'tab return never re-quotes over a kept sale key (no double sale on retry)');
$ok(str_contains($st, 'sig === seatSig') && str_contains($st, 'seatSig = sig'), 'the open map is redrawn only when the coach changed (keyboard focus kept)');

echo "\n6. P2-3e the sync ring\n";
$ring = $fn($rt, 'const SHGSync = {');
$ok(str_contains($ring, 'begin()') && str_contains($ring, 'end()') && str_contains($ring, "aria-hidden', 'true'"), 'an in-flight counter, hidden from screen readers');
$ok(str_contains($rt, 'window.SHGSync = SHGSync;'), 'exposed as window.SHGSync');
$sbk = $fn($co, 'async function syncBookings(');
$ok(str_contains($sbk, 'ring.begin()') && str_contains($sbk, 'finally') && str_contains($sbk, 'ring.end()'), 'syncBookings counts itself in and always out');
$ok(str_contains($fn($rt, '_tick() {'), 'SHGSync.begin()') || (bool) preg_match('~SHGSync\.begin\(\);\s*const ask = SeatSrv\.load~', $rt), 'the classic seat poll counts too');
$ok(str_contains($st, 'sync.begin()') && str_contains($st, 'sync.end()'), 'the SmartBook seat poll counts too');
$ok((bool) preg_match('~\.shg-sync\{[^}]*width:14px;height:14px~', $css) && str_contains($css, 'pointer-events:none'), 'polish.css: 14 px, takes no tap');
$ok((bool) preg_match('~prefers-reduced-motion:reduce\)\{\.shg-sync[^}]*animation:none~', $css) && str_contains($css, 'html[data-motion="reduced"] .shg-sync'), 'still under reduced motion (OS + site switch)');

echo "\n7. P4-1f SmartBook near-me\n";
$cfg = $fn($sb, 'function nearCfg(');
$ok(str_contains($cfg, 'nearme_on') && str_contains($cfg, 'nearme_max_km') && str_contains($cfg, '|| 150'), 'reads nearme_on + nearme_max_km (150 km default, like nearMeWire)');
$ns = $fn($sb, 'function nearSync(');
$ok(str_contains($ns, "\$('sbNear')") && str_contains($ns, 'nearCfg().on') && str_contains($ns, 'pointList().some(hasXY)'), 'the 📍 button hides when off / nothing measured');
$ok(str_contains($fn($sb, 'function fill('), 'nearSync()'), 'checked again on every direction change');
$nm = $fn($sb, 'function nearMe(');
$ok(str_contains($nm, 'best.km > cfg.maxKm') && str_contains($nm, "gw('nearMeNone'"), 'nothing selected beyond nearme_max_km');
$ok(str_contains($nm, 'unchecked.push') && str_contains($nm, "gw('nearMeSuggest'") && str_contains($nm, "'sb-near-use'"), 'any unmeasured point: suggest with a "Use this stop" tap');
$ok((bool) preg_match('~if \(!unchecked\.length\) \{ use\(\); return; \}~', $nm), 'selects by itself only when every point is measured');
$ok(str_contains($css, '#smartBook .sb-why .sb-near-use{') && str_contains($css, 'min-height:36px'), 'polish.css styles the Use key');

echo "\n8. P4-2f the next-departure time\n";
$ok(str_contains($api, "'nextAvailableTime' => \$nextTime") && str_contains($api, 'Boarding::timeForStop($nextRouteId, $from)'), 'api/search.php returns nextAvailableTime from Boarding::timeForStop');
$ok(str_contains($rt, 'nextTime:') && str_contains($rt, 'd.nextAvailableTime'), '05-router.js keeps it on the board snapshot');
$ok(str_contains($res, 'fmt12h(srvSnap.nextTime)') && str_contains($res, "tf('nextDepP', {") && str_contains($res, "tf('nextDepP0'"), '06-results.js shows it (and a no-time line for an older server)');
preg_match_all("~nextDepP: '([^']*)'~u", $i18n, $mP);
preg_match_all("~nextDepP0: '([^']*)'~u", $i18n, $m0);
$ok(count($mP[1]) === 3 && count(array_filter($mP[1], static fn($s) => str_contains($s, '{time}'))) === 3, 'nextDepP carries {time} in en / hi / ne');
$ok(count(array_filter($mP[1], static fn($s) => (bool) preg_match('~1:00|१:००~u', $s))) === 0, 'no hard-coded 1:00 PM left in any language');
$ok(count($m0[1]) === 3, 'nextDepP0 exists in en / hi / ne');

echo "\n9. P5-6 the notice banner\n";
$nn = $fn($rt, 'function noticeNow(');
$ok(str_contains($nn, 'B.settings && B.settings.notice_text') && str_contains($nn, 'S()') && str_contains($nn, 'noticeText'), 'notice_text first, the old local notice as fallback');
$ok(str_contains($nn, 'B.notice_since') && str_contains($nn, '86400'), 'hidden 24 h after notice_since');
$rn = $fn($rt, 'function renderNotice(');
$ok(str_contains($rn, 'localStorage.getItem(NOTICE_KEY) === h') && str_contains($rn, 'noticeHash(n.text)'), 'the ✕ is remembered per text (short hash) in localStorage');
$ok(!str_contains($rn, 'sessionStorage'), 'no longer per session');
$ok(str_contains($rn, '<svg class="notice-ico"') && !preg_match('~📢|🔔~u', $rn), 'a plain inline SVG info icon, no emoji');
$ok(str_contains($rn, 'txt.textContent = n.text'), 'the office text is set as text, never as HTML');
$ok(str_contains($css, '.notice-bar .notice-ico{'), 'polish.css styles the icon');
$ok((bool) preg_match("~localStorage\.setItem\(NOTICE_KEY, bar\.getAttribute\('data-nh'\)~", $rt), '✕ stores the hash of the text on screen');

echo "\n10. No new outbound message, no stamp touched\n";
$ok(!preg_match('~/api/(wa|whatsapp|notify|sms|send)~i', $sb . $rt), 'no new message endpoint called from the customer app');
$ok(!str_contains($pwa . $sb . $rt . $res . $co, 'ASSET_VER'), 'no ASSET_VER / stamp edits in these files');

echo "\n" . ($fail === 0 ? "ALL PASSED: $pass" . ($skip ? " (skipped $skip)" : '') : "FAILED: $fail (passed $pass, skipped $skip)") . "\n";
exit($fail === 0 ? 0 : 1);
