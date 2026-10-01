<?php
/**
 * =====================================================================
 *  counter-desk-memory-test.php — U4 (1 Oct 2026): the counter / agent
 *  desk remembers ITS OWN setup and offers three ways on after a sale.
 *
 *  Owner: "Agent/counter bookings may select/search an agent once; retain
 *  agent … throughout checkout. Eliminate repeated entry. After counter
 *  booking, provide 'Book another ticket' while retaining the same
 *  date/route and allowing another bus/seat selection immediately."
 *  Owner rule (6 Sep 2026) still stands: the desk never inherits the
 *  PREVIOUS PASSENGER's name / phone.
 *
 *  What a PHP test can assert here is source + markup (the behaviour is
 *  browser-only: localStorage / sessionStorage / DOM):
 *    • 14-counter.js ships the three ticket-screen actions
 *      (#ctrSameBus, #ctrSameDate, #ctrSellAnother), persists the last
 *      sale in sessionStorage, keys the remembered agent on the staff id
 *      and the payment / boarding chips on QuickBot's own localStorage keys
 *    • admin/quick-ticket.php's #qtAgent reads/writes the SAME agent key
 *    • the passenger is never written to storage; forgetPassenger() runs
 *    • the seat-view confirm shortcut drives the app's own Continue and
 *      the one-screen confirm (never a second submit path)
 *    • app.template.html still carries every anchor the desk hooks into
 *    • node --check / php -l on the two files (node step skipped when the
 *      binary is not on PATH)
 *
 *  No database, no server:  php tests/counter-desk-memory-test.php
 *  The live HTTP contract (staff.id / staff.agents in the boot payload,
 *  QuickBot page keyed on the same id) is asserted in
 *  tests/counter-mode-http-test.php.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

$ROOT = dirname(__DIR__);
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function has(string $hay, string $needle): bool { return $needle !== '' && str_contains($hay, $needle); }

$jsPath = $ROOT . '/assets/js/14-counter.js';
$qtPath = $ROOT . '/admin/quick-ticket.php';
$js  = (string) file_get_contents($jsPath);
$qt  = (string) file_get_contents($qtPath);
$tpl = (string) file_get_contents($ROOT . '/app.template.html');
check('source files readable', $js !== '' && $qt !== '' && $tpl !== '');

echo "\n=== 1. Ticket screen: three staff actions (owner R3d) ===\n";
check('"Same bus · next ticket" (#ctrSameBus)', has($js, 'id="ctrSameBus"'));
check('"Same date · choose another bus" (#ctrSameDate)', has($js, 'id="ctrSameDate"'));
check('"Sell another" (#ctrSellAnother)', has($js, 'id="ctrSellAnother"'));
check('Same date leaves the board up: autoPick only when the same bus is wanted', preg_match('/function sameBus\(pick\)/', $js) === 1 && has($js, 'if (pick) autoPick(L.sid || 0, \'\', L.rid || \'\');'));
check('Same bus / Same date never let the board auto-enter a single coach', has($js, 'Flow.autoSkip = false;'));
check('Sell another re-runs the search through the form\'s own submit', has($js, 'function sellAnother()') && has($js, "form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))"));
check('Sell another falls back to home only when from/to/date are not set', has($js, "if (!fs.value || !ts.value || !di.value || fs.value === ts.value) return false;"));
check('last sale persisted per tab in sessionStorage (shg:ctr:last)', has($js, "KEY_LAST = 'shg:ctr:last'") && has($js, 'sessionStorage.setItem(KEY_LAST') && has($js, 'sessionStorage.getItem(KEY_LAST'));
check('… read back at start, so a reload keeps the buttons', preg_match('/function start\(\) \{.*?loadLast\(\);.*?bar\(\);/s', $js) === 1);
check('… seeded from the deep link (from/to/date/sid) too', has($js, "CTR.last = { from: fs.value, to: ts.value, date: di.value, sid: wantSid, rid: '' }; saveLast();"));

echo "\n=== 2. Desk memory — one agent key shared by both desks (owner R3b) ===\n";
check('counter mode keys the remembered agent on the staff id', has($js, "KEY_AGENT = 'shg_ctr_agent_' + (STAFF.id || 0)"));
check('QuickBot #qtAgent uses the SAME key, built from the same admin id', has($qt, "var AGENT_KEY = 'shg_ctr_agent_' + STAFF_ID;") && has($qt, 'var STAFF_ID = <?= (int) $staffId ?>;'));
check('QuickBot reads it on load and writes it on change', has($qt, 'localStorage.getItem(AGENT_KEY)') && has($qt, 'localStorage.setItem(AGENT_KEY, qtAg.value)') && has($qt, 'localStorage.removeItem(AGENT_KEY)'));
check('counter mode writes the pick on change (blank = forget)', has($js, 'lsSet(KEY_AGENT, sel.value);') && has($js, 'function lsSet(k, v) { try { if (v) localStorage.setItem(k, v); else localStorage.removeItem(k); }'));
check('remembered pick preselects the dropdown, own SHG code as fallback', has($js, "var want = lsGet(KEY_AGENT) || STAFF.code || '';") && has($js, "var selected = (want && a.code === want) ? ' selected' : '';"));
check('"Other (direct sale)" can be the remembered choice too', has($js, "(want === 'OTHER' ? ' selected' : '')"));
check('agent select stays mandatory (validator untouched): the key never bypasses #cAgentCode', has($js, "agentCode: agSel ? (agSel.value || '').trim() : ''"));
check('payment chip remembered under QuickBot\'s shg_qt_pay', has($js, "KEY_PAY = 'shg_qt_pay'") && has($js, 'lsSet(KEY_PAY, CTR.pay);') && has($qt, "localStorage.setItem('shg_qt_pay', v)"));
check('remembered chip is the lit one when the panel is built', has($js, "(CTR.pay === k ? ' on' : '')") && has($js, 'if (PAYS.indexOf(p0) >= 0) CTR.pay = p0;'));
check('boarding remembered under QuickBot\'s shg_qt_boarding', has($js, "KEY_BOARD = 'shg_qt_boarding'") && has($qt, "localStorage.getItem('shg_qt_boarding')"));
check('… re-applied after every renderSeats (which refills the selects)', has($js, 'window.renderSeats = function () { var r = rsv.apply(this, arguments); try { recallStops(); }'));
check('… "Other" is never remembered and a select on Other is left alone', has($js, "if (!v || v === '__other__') return;") && has($js, "if (!sel || !want || sel.value === '__other__') return;"));
check('QuickBot hint tells the clerk what the desk remembers', has($qt, 'Boarding, payment and agent choices are remembered on this desk.'));

echo "\n=== 3. Owner rule (6 Sep 2026): the PASSENGER is never remembered ===\n";
check('forgetPassenger() still runs the moment a sale completes', preg_match('/forgetPassenger\(\);\s+\/\/ this sale\'s passenger never rides into the next one/', $js) === 1);
$fnBody = static function (string $src, string $name): string {
    // the function's text up to its own closing brace (two-space indent); CRLF-safe
    return preg_match('/function ' . preg_quote($name, '/') . '\(.*?\R  \}\R/s', $src, $m) === 1 ? $m[0] : '';
};
check('Sell another / Same bus / Same date all forget the passenger', str_contains($fnBody($js, 'sellAnother'), 'forgetPassenger();') && str_contains($fnBody($js, 'sameBus'), 'forgetPassenger();'));
check('no storage write ever carries a passenger name / phone', preg_match('/(localStorage|sessionStorage)\.setItem\([^;]*(Quick|Name|Phone|cPhone|pax)/i', $js) === 0);
check('the last-sale record holds only the departure (from/to/date/sid/rid)', preg_match('/CTR\.last = \{\s*from:.*?to:.*?date:.*?sid:.*?rid:.*?\};/s', $js) === 1 && preg_match('/CTR\.last = \{[^}]*(name|phone)/i', $js) === 0);

echo "\n=== 4. Seat view: Confirm counter sale shortcut (owner R3c) ===\n";
check('#ctrSeatConfirm sits on the seat summary card', has($js, 'id="ctrSeatConfirm"') && has($js, 'scb.addEventListener(\'click\', seatConfirm);'));
check('it presses the app\'s own Continue (no second leg-builder)', has($js, 'cont.click();') && !has($js, 'Flow.legs[Flow.legIndex] ='));
check('it waits for THIS render before pressing the one-screen confirm', has($js, 'CTR.rcTick === tick') && has($js, 'CTR.rcTick++') && has($js, 'ctrConfirm();'));
$scBody = $fnBody($js, 'seatConfirm');
check('it never calls submitBooking / the API directly', $scBody !== '' && !str_contains($scBody, 'submitBooking') && !str_contains($scBody, 'shgApi') && str_contains($scBody, 'ctrConfirm();'));
check('round trip: the shortcut hands over to the return leg instead', has($js, "round = Flow.tripType === 'round' && Flow.legIndex === 0") && has($js, 'if (round) return;'));
check('disabled exactly when Continue is (seats picked, fare good)', has($js, "attributeFilter: ['disabled']") && has($js, 'scb.disabled = cont0.disabled;'));
check('checkout stays reachable: Continue → still opens it without confirming', has($js, 'Continue → = open the checkout to edit ID / age / per-seat names'));

echo "\n=== 5. Markup anchors the desk hooks into (app.template.html) ===\n";
foreach (['id="statusBody"', 'id="continueBtn"', 'id="searchForm"', 'id="boardingSel"', 'id="dropSel"', 'id="fromSel"', 'id="toSel"', 'id="dateInput"', 'id="retInput"', 'id="cAgentCode"', 'id="coStep1"', 'id="coContinueBtn"', 'id="submitBookingBtn"', 'id="payCard"', 'id="agreeTnc"'] as $anchor) {
    check('template has ' . $anchor, has($tpl, $anchor));
}
check('seat view: summary card with its flow-actions bar', preg_match('/id="view-seats".*?class="sum-card".*?class="flow-actions"/s', $tpl) === 1);
check('shell loads 14-counter.js', has($tpl, '/assets/js/14-counter.js'));
check('the app globals the desk wraps are plain function declarations (re-bindable)', preg_match('/^function renderSeats\(/m', (string) file_get_contents($ROOT . '/assets/js/06-results.js')) === 1
    && preg_match('/^function renderCheckout\(/m', (string) file_get_contents($ROOT . '/assets/js/07-checkout.js')) === 1
    && preg_match('/^function renderStatus\(/m', (string) file_get_contents($ROOT . '/assets/js/07-checkout.js')) === 1);

echo "\n=== 6. Syntax ===\n";
$out = []; $rc = 1;
@exec('node --version 2>&1', $out, $rc);
if ($rc === 0) {
    $out = []; @exec('node --check ' . escapeshellarg($jsPath) . ' 2>&1', $out, $rc);
    check('node --check assets/js/14-counter.js', $rc === 0, implode(' ', $out));
} else {
    echo "  SKIP  node not on PATH — node --check assets/js/14-counter.js not run here\n";
}
$out = []; @exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($qtPath) . ' 2>&1', $out, $rc);
check('php -l admin/quick-ticket.php', $rc === 0, implode(' ', $out));

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
