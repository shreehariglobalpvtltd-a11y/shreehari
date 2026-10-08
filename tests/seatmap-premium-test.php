<?php
/**
 * =====================================================================
 *  seatmap-premium-test.php — the premium sleeper seat map (8 Oct 2026)
 *
 *    • Seats::lock() returns expiresIn (seconds) beside expiresAt, so the
 *      phone counts the hold down in its own clock, never in its own zone
 *    • the hold-expiry branch is reachable, releases once, and a lapse on
 *      the checkout goes back to the SAME bus's seat map (not home)
 *    • the poll drops a berth another visitor now HOLDS, in the passenger's
 *      language with display labels; out-of-service seats are not "held"
 *    • the fixed bar carries the countdown, outside the aria-live recap
 *    • a passenger set as "Man" is refused a women-only berth on tap (not
 *      at a desk sale); private cabins carry no gender cabin lock
 *    • a patient is seated front-first; a Double private cabin is priced
 *      as checkout prices it; near-me only suggests while stops lack GPS
 *    • the node half (tests/seatmap-premium-test.js) runs the shipped
 *      functions: hold clock under three time zones, labels, gender,
 *      patient scoring
 *
 *    php -c .claude/php-dev.ini tests/seatmap-premium-test.php
 *  Touches only its own seat_locks rows on a far-future schedule.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Premium sleeper seat map ===\n\n";
$root = dirname(__DIR__);

echo "-- 1. Seats::lock returns the hold length (D5-03) --\n";
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
if ($route === null) {
    echo "  \033[33mSKIP\033[0m  no active sleeper route in this database\n";
} else {
    $w = bookingWindow(); $D = addDaysISO($w['from'], 21);
    $had = (int) Database::scalar('SELECT COUNT(*) FROM schedules WHERE route_id = :r AND travel_date = :d', ['r' => (int) $route['id'], 'd' => $D], 0);
    $sid = (int) Seats::schedule((int) $route['id'], $D)['id'];
    $tok = 'fa-premium-test';
    try {
        $mins = Settings::getInt('seat_hold_minutes', SEAT_HOLD_MINUTES);
        $r = Seats::lock($sid, ['L20'], $tok, false, 'sharing');
        check('hold granted', $r['ok'] === true && $r['locked'] === ['L20'], json_encode($r));
        check('expiresIn = seat_hold_minutes x 60 seconds', ($r['expiresIn'] ?? null) === $mins * 60, (string) ($r['expiresIn'] ?? 'missing'));
        check('expiresAt is still sent (older clients)', is_string($r['expiresAt'] ?? null) && strtotime((string) $r['expiresAt']) !== false);
        $gap = strtotime((string) $r['expiresAt']) - time();
        check('expiresAt and expiresIn describe the same moment (±5 s)', abs($gap - (int) $r['expiresIn']) <= 5, $gap . ' vs ' . $r['expiresIn']);
        $db = (string) Database::scalar('SELECT expires_at FROM seat_locks WHERE schedule_id = :s AND seat_no = :n AND lock_token = :t', ['s' => $sid, 'n' => 'L20', 't' => $tok], '');
        check('the row the server wrote ends when expiresIn says', $db !== '' && abs(strtotime($db) - time() - (int) $r['expiresIn']) <= 5, $db);
        $f = Seats::lock($sid, ['L20'], 'fa-premium-other', false, 'sharing');
        check('a refused hold still answers (failed list, expiresIn present)', $f['ok'] === false && $f['failed'] === ['L20'] && isset($f['expiresIn']));
    } finally {
        Database::delete('seat_locks', 'schedule_id = :s AND lock_token IN (:a, :b)', ['s' => $sid, 'a' => $tok, 'b' => 'fa-premium-other']);
        if ($had === 0 && (int) Database::scalar('SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s', ['s' => $sid], 0) === 0
            && (int) Database::scalar('SELECT COUNT(*) FROM seat_locks WHERE schedule_id = :s', ['s' => $sid], 0) === 0) {
            Database::delete('schedules', 'id = :s', ['s' => $sid]);
        }
    }
    check('test locks cleaned up', (int) Database::scalar('SELECT COUNT(*) FROM seat_locks WHERE lock_token LIKE :t', ['t' => 'fa-premium%'], 1) === 0);
}

echo "-- 2. Hold integrity in the bundle (D5-01 / D5-M2 / D5-16 / D5-M4) --\n";
$r5  = (string) file_get_contents($root . '/assets/js/05-router.js');
$r6  = (string) file_get_contents($root . '/assets/js/06-results.js');
$tpl = (string) file_get_contents($root . '/app.template.html');
$css = (string) file_get_contents($root . '/assets/css/depth.css');
$i18 = (string) file_get_contents($root . '/assets/js/04-i18n.js');
$fn = static function (string $src, string $name): string {
    $a = strpos($src, "\nfunction $name(");
    if ($a === false) { return ''; }
    $b = strpos($src, "\n}", $a);
    return $b === false ? '' : substr($src, $a, $b - $a + 2);
};
check('the client adopts the hold through seatHoldExpiryMs (expiresIn, else IST)', str_contains($r5, 'const exp = seatHoldExpiryMs(d);') && str_contains($fn($r5, 'seatHoldExpiryMs'), "'+05:30'"));
check('myHoldExpiry no longer goes silent once the rows lapse', str_contains($fn($r5, 'myHoldExpiry'), 'holdExpiryPick(mine, SeatLock.serverExpiry, hasSel)'));
$lapse = $fn($r5, 'seatHoldLapsed');
check('the lapse clears the server expiry BEFORE releasing (runs once)', ($p1 = strpos($lapse, 'SeatLock.serverExpiry = 0;')) !== false && ($p2 = strpos($lapse, 'SeatLock.releaseAll();')) !== false && $p1 < $p2);
check('a lapse on the checkout returns to the same bus\'s seat map, not home', str_contains($lapse, "location.hash = Flow.route ? '#/seats' : '#/';") && str_contains($lapse, 'routeById(l0.routeId)'));
check('the countdown is cleared when nothing is held', str_contains($fn($r5, 'updateHoldPills'), "cd0.textContent = ''"));
check('the poll drops a berth another visitor HOLDS', str_contains($r5, "(snap.locked || []).indexOf(s) >= 0"));
check('poll + refusal toasts are i18n with display labels', str_contains($r5, "tf('tSeatGone', { s: seatLabelJoin(gone, coach, mode) })") && str_contains($r5, "tf('tSeatTaken', {") && !str_contains($r5, "' just booked by another customer.'"));
check('out-of-service seats are kept apart from holds', str_contains($r5, 'outOfService: d.blocked || []') && str_contains($r6, "const off = !rsv && oosSet.indexOf(id) >= 0;"));
check('the seat page keeps an active picker\'s hold alive (8 min, active user only)', str_contains($r6, 'window._shgSeatKeep = setInterval(') && str_contains($r6, '(Date.now() - LAST_ACTIVITY) > 5 * 60000'));

echo "-- 3. Countdown in the fixed bar, outside the live region (D5-02) --\n";
check('#seatHoldBar sits in the seat bar, aria-hidden', (bool) preg_match('~id="seatRecap" aria-live="polite"></div>\s*(?:<!--.*?-->\s*)*<div class="fa-hold" id="seatHoldBar" aria-hidden="true" hidden></div>~s', $tpl));
check('...amber under 5 min, red under 1', str_contains($r5, "bar.classList.toggle('warn', ms < 5 * 60000)") && str_contains($css, '.fa-hold.crit'));
check('...one polite toast at 5:00 / 1:00 instead of a ticking announcement', str_contains($r5, "tf('tHoldWarn', {"));

echo "-- 4. Safety rules on a tap (D5-M1 / D5-09 / D5-10) --\n";
check('a "Man" tap on a women-only berth is refused with the reason, not at a desk', str_contains($r6, "if (picking && !(typeof isCounterSale === 'function' && isCounterSale())) {") && str_contains($r6, "if (g && !seatFitsGender(btn, g)) {"));
check('private cabins carry no gender cabin lock', str_contains($r6, "const ul = (!bk && !hd && !rsv && !pvtMode)"));
check('a private berth outside the chosen size says why', str_contains($r6, "btn.getAttribute('aria-disabled') === 'true'") && str_contains($r6, "b.setAttribute('aria-disabled', 'true'); b.setAttribute('data-why'"));
check('private size pills above the map on phones', str_contains($tpl, 'id="tierQuick"') && str_contains($css, '#view-seats .tier-quick:not(.hide)'));

echo "-- 5. Seat AI, price, near-me, labels --\n";
check('patients are walked front-first regardless of seat_ai_row_order', str_contains($r6, '(patient ? s.ri * 3 : (cfg.rear ? (s.rn - 1 - s.ri) : s.ri) * 1.5)'));
check('a Double private cabin is priced from the chosen tier (as checkout)', str_contains($r6, "SHARING_TIERS[Flow.sharingTier === 'triple' ? 'double' : Flow.sharingTier]") && !str_contains($r6, 'seatLabels.some(s => /^D/i.test(s))'));
check('near-me only suggests while some stops have no coordinates', str_contains($fn($r6, 'nearMeWire'), "if (!unchecked.length) {") && str_contains($fn($r6, 'nearMeWire'), "tf('nearMeUnchecked'"));
check('results arrival is India time (IST), never NPT', !str_contains($r6, "' <small>NPT</small>'") && str_contains($r6, "' <small>IST</small>' + dayBadge"));
check('row headers built from i18n, no per-row door icon', str_contains($r6, "tf('rowLbl', { r: rowNo })") && !str_contains($r6, "'<span class=\"cab-no\">🚪 '"));
check('each floor is a named group for screen readers', substr_count($r6, '" role="group" aria-label="') >= 2);
check('the ♿ badge is a child element (the ♀ icon cannot hide it)', str_contains($r6, '<span class="da-badge" aria-hidden="true">♿</span>') && !str_contains($r6, "(anyone may book)"));
check('fewer seats than searched → a prompt, never a block', str_contains($r6, 'function seatPaxAsk(want)') && str_contains($tpl, 'id="seatPaxAsk"'));
check('legend "More signs" has its own key (lgMore is the login sheet\'s)', str_contains($tpl, 'data-i18n="lgSigns"'));
foreach (['tHoldWarn', 'tSeatTaken', 'tSeatOff', 'tWomenOnlySeat', 'tMenOnlySeat', 'tierNeedDouble', 'tierNeedSingle', 'tierCaption', 'paxFewer', 'paxAutoRest',
          'ariaSeat', 'ariaWomenOnly', 'ariaWomenCabin', 'ariaMenCabin', 'ariaEmerg', 'ariaOff', 'rowLbl', 'nearMeSuggest', 'nearMeUse', 'nearMeUnchecked', 'lgSigns'] as $k) {
    if (substr_count($i18, ' ' . $k . ':') + substr_count($i18, ',' . $k . ':') < 3) { check("i18n key $k in en / hi / ne", false); }
}
check('ne / hi hold pill reads "remaining", not "until" (D5-M3)', str_contains($i18, "holdMsg: 'सिट होल्ड — {t} बाँकी'") && str_contains($i18, "holdMsg: 'सीट होल्ड — {t} बाकी'") && !str_contains($i18, "{t} सम्म होल्डमा"));

echo "-- 6. Clarity in every theme (depth.css stays last) --\n";
$block = (string) strstr((string) strstr($css, 'PREMIUM SLEEPER SEAT MAP'), '/* reduced motion: depth stays', true);
check('the seat-map block sits before the reduced-motion rules', $block !== '' && strpos($css, 'PREMIUM SLEEPER SEAT MAP') < strpos($css, '/* reduced motion: depth stays'));
check('emergency berths hatched + 🚨', str_contains($block, '#view-seats #seatGrid .seat.reserved::after') && str_contains($block, "content: '\\1F6A8'"));
check('held = ⏳ like the legend', str_contains($block, "#view-seats #seatGrid .seat.hold::after { content: '\\23F3'"));
check('♀ / ♂ use the dark seat inks', str_contains($block, 'color: var(--seat-gents-ink) !important') && str_contains($block, 'color: var(--seat-ladies-ink) !important'));
check('private cabins gold-edged with fallbacks', str_contains($block, 'var(--accent-gold, #C9A227)') && str_contains($block, 'var(--accent-gold-soft, #FFFBEF)') && str_contains($block, 'var(--seat-private-ink, #7A5A00)'));
check('Seat AI button: still, AA gradient', str_contains($block, 'linear-gradient(90deg, #6D28D9, #BE185D)') && str_contains($block, 'animation: none !important'));
check('grid columns stay minmax(0,1fr)', str_contains($block, 'repeat(4, minmax(0, 1fr)) 16px repeat(2, minmax(0, 1fr))') && !preg_match('~repeat\(\d+,\s*1fr\)~', $block));
check('braces balance in depth.css', substr_count($css, '{') === substr_count($css, '}'));
preg_match_all('~<link rel="stylesheet" href="/assets/css/([a-z]+)\.css~', $tpl, $links);
check('depth.css is still the LAST stylesheet', end($links[1]) === 'depth', implode(' > ', $links[1]));

echo "-- 7. The shipped functions, run in node --\n";
$out = []; $code = 0;
exec('node --version 2>&1', $out, $code);
if ($code !== 0) {
    echo "  \033[33mSKIP\033[0m  node not on PATH — tests/seatmap-premium-test.js not run\n";
} else {
    $out = [];
    exec('node ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'seatmap-premium-test.js') . ' 2>&1', $out, $code);
    $last = '';
    foreach ($out as $line) { if (preg_match('~(\d+) passed, (\d+) failed~', $line)) { $last = trim($line); } if (str_contains($line, 'FAIL')) { echo '    ' . $line . "\n"; } }
    check('node: hold clock (3 zones), labels, gender, patient scoring', $code === 0 && $last !== '' && str_ends_with($last, ' 0 failed'), $last);
}

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
