<?php
/**
 * =====================================================================
 *  WHATSAPP TICKET BY "HI" — the free 24 h window queue
 *
 *      php tests/wa-hi-queue-test.php
 *
 *  Owner (4 Oct 2026): "Nepal India 2 tai number ma jane … customer lai
 *  nagaye kun counter bata katyo tyaslai gayeni hunchha … ek choti hi
 *  lekhne bas, 24 free msg."
 *
 *  Pins down:
 *    1. Numbers are full international digits: a Nepali 98… and an Indian
 *       98… with the same 10 digits are different people.
 *    2. A ticket is queued for the customer, the second number and the
 *       clerk who cut it (not the agent it was re-credited to).
 *    3. With no open window nothing is sent - the rows wait.
 *    4. "hi" from a queued number opens its window and sends its tickets.
 *    5. A PNR typed by a number NOT on that booking sends nothing (PNRs are
 *       sequential - no ticket to strangers).
 *    6. Old waits expire; the wiring (hook, webhook, cron, UI) is in place.
 *
 *  Sends go nowhere here: without an AiSensy key every send fails
 *  ("not configured"), which is exactly what proves a send was attempted.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waqueue.php';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== WhatsApp ticket by \"hi\" ===\n\n";

if (!WaQueue::ready()) {
    echo "  \033[33mSKIP\033[0m  database/upgrade-2026-10-04-wa-hi-queue.sql is not applied (or wa_hi_queue_on is off)\n\n";
    exit(0);
}

/* ---- 1. numbers ------------------------------------------------------ */
check('a 10-digit number with NP becomes +977', WaQueue::intl('9812345678', 'NP') === '9779812345678', WaQueue::intl('9812345678', 'NP'));
check('...with IN (or no hint) becomes +91', WaQueue::intl('9812345678', 'IN') === '919812345678' && WaQueue::intl('9812345678') === '919812345678');
check('...so the two are different numbers', WaQueue::intl('9812345678', 'NP') !== WaQueue::intl('9812345678', 'IN'));
check('a number already carrying 977 keeps it', WaQueue::intl('+977 981-2345678', 'IN') === '9779812345678');
check('rubbish is refused', WaQueue::intl('12345', 'IN') === '');
check('the "hi" link opens WhatsApp to the AiSensy number with the PNR typed',
    WaQueue::hiLink('SHG-2026-00001') === 'https://wa.me/' . WaQueue::hiNumber() . '?text=Hi%20SHG-2026-00001');

/* ---- fixtures -------------------------------------------------------- */
$tag   = 'WAHI' . substr(md5((string) microtime(true)), 0, 5);
$sched = (int) Database::scalar('SELECT id FROM schedules ORDER BY id DESC LIMIT 1', [], 0);
$clerk = (int) Database::scalar("SELECT id FROM admins WHERE is_active = 1 ORDER BY id LIMIT 1", [], 0);
$agent = (int) Database::scalar("SELECT id FROM admins WHERE is_active = 1 AND id <> :c ORDER BY id LIMIT 1", ['c' => $clerk], 0);
if ($sched <= 0 || $clerk <= 0 || $agent <= 0) {
    echo "  \033[33mSKIP\033[0m  needs a schedule and two staff accounts\n\n";
    exit(0);
}
$clerkPhone = '9800' . random_int(100000, 999999);
$profSaved  = Database::fetch('SELECT whatsapp, country_code FROM admin_profiles WHERE admin_id = :a', ['a' => $clerk]);
if ($profSaved === null) {
    Database::insert('admin_profiles', ['admin_id' => $clerk, 'whatsapp' => $clerkPhone, 'country_code' => '977']);
} else {
    Database::update('admin_profiles', ['whatsapp' => $clerkPhone, 'country_code' => '977'], 'admin_id = :a', ['a' => $clerk]);
}
$custPhone = '98' . random_int(10000000, 99999999);
$altPhone  = '9' . random_int(100000000, 999999999);
$pnr = 'SHG-2026-' . random_int(90000, 99999);
$bid = Database::insert('bookings', [
    'pnr' => $pnr, 'contact_phone' => $custPhone, 'contact_country_code' => '977',
    'alt_phone' => $altPhone, 'alt_country_code' => '91',
    'total_amount' => 2000, 'status' => 'confirmed', 'source' => 'counter', 'sold_by_admin_id' => $agent,
]);
Database::insert('booking_legs', ['booking_id' => $bid, 'schedule_id' => $sched, 'travel_date' => date('Y-m-d', strtotime('+3 days')), 'seat_count' => 1, 'leg_total' => 2000]);
Database::insert('payments', ['booking_id' => $bid, 'payment_ref' => 'PAY-' . $tag, 'method' => 'cash', 'mode' => 'offline', 'amount' => 2000,
    'status' => 'verified', 'verified_by' => $clerk, 'verified_at' => date('Y-m-d H:i:s')]);

$cust = '977' . $custPhone; $alt = '91' . $altPhone; $clerkIntl = '977' . $clerkPhone;
$stranger = '9779' . random_int(700000000, 799999999);
$wipeWindows = static fn() => Database::run('DELETE FROM wa_windows WHERE phone IN (:a, :b, :c, :d)', ['a' => $cust, 'b' => $alt, 'c' => $clerkIntl, 'd' => $stranger]);

try {
    $wipeWindows();
    $b = Database::fetch('SELECT * FROM bookings WHERE id = :b', ['b' => $bid]);

    /* ---- 2. who gets it -------------------------------------------- */
    $who = WaQueue::recipients($b);
    check('the customer is first, on the Nepal number they gave', ($who[0] ?? null) === [$cust, 'customer'], json_encode($who));
    check('...as a string, never an int key', is_string($who[0][0] ?? null));
    check('the second number (India) is on the list', WaQueue::roleOf($b, $alt) === 'alt');
    check('the copy goes to the CLERK who took the money, not the agent it was credited to',
        WaQueue::roleOf($b, $clerkIntl) === 'seller', json_encode($who));
    check('a stranger has no role on it', WaQueue::roleOf($b, $stranger) === '');

    /* ---- 3. no window: everything waits ------------------------------ */
    WaQueue::onConfirmed($b, $cust, '', '');
    $q = Database::fetchAll('SELECT phone, role, status, attempts FROM wa_ticket_queue WHERE booking_id = :b ORDER BY id', ['b' => $bid]);
    check('three rows are queued (customer, second number, clerk)', count($q) === 3, json_encode($q));
    $att = [];
    foreach ($q as $r) { $att[$r['role']] = (int) $r['attempts']; }
    check('the customer and the second number get ONE try now (a window we never saw may still be open)',
        ($att['customer'] ?? -1) === 1 && ($att['alt'] ?? -1) === 1, json_encode($att));
    check('...the clerk copy waits until the customer is known not to have it', ($att['seller'] ?? -1) === 0);
    WaQueue::onConfirmed($b, $cust, '', '');
    check('a re-fired confirmation adds no duplicate rows', (int) Database::scalar('SELECT COUNT(*) FROM wa_ticket_queue WHERE booking_id = :b', ['b' => $bid]) === 3);

    /* ---- 4. "hi" from the customer --------------------------------- */
    check('the customer window is closed before "hi"', !WaQueue::windowOpen($cust));
    WaQueue::onInbound($cust, 'hi');
    check('"hi" opens the customer\'s free 24 h window', WaQueue::windowOpen($cust));
    $row = Database::fetch('SELECT status, attempts, last_error FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $cust]);
    check('...and the waiting ticket was sent again at once', (int) $row['attempts'] === 2, json_encode($row));
    $other = Database::fetch('SELECT attempts FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $alt]);
    check('...without touching the other numbers, whose windows are still closed', (int) $other['attempts'] === 1);
    $sel = Database::fetch('SELECT attempts FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $clerkIntl]);
    WaQueue::onInbound($clerkIntl, 'hi');
    $sel2 = Database::fetch('SELECT attempts FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $clerkIntl]);
    check('the clerk saying "hi" receives the waiting copy', (int) $sel['attempts'] === 0 && (int) $sel2['attempts'] === 1, json_encode([$sel, $sel2]));

    /* ---- 5. a stranger typing the PNR -------------------------------- */
    $n = WaQueue::onInbound($stranger, 'hi ' . $pnr);
    check('a PNR typed by a number not on the booking queues nothing', $n === 0
        && !Database::exists('SELECT 1 FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $stranger]));

    /* ---- the second number asking with the PNR ----------------------- */
    WaQueue::onInbound($alt, 'Hi ' . strtolower($pnr));
    $altRow = Database::fetch('SELECT attempts FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $alt]);
    check('the second number saying "Hi <PNR>" gets its ticket sent', (int) $altRow['attempts'] >= 2, json_encode($altRow));

    /* ---- 6. expiry ----------------------------------------------------- */
    Database::run("UPDATE wa_ticket_queue SET status = 'pending', created_at = NOW() - INTERVAL 8 DAY WHERE booking_id = :b AND phone = :p", ['b' => $bid, 'p' => $clerkIntl]);
    WaQueue::checkStatuses(5);
    check('a wait older than 7 days expires', (string) Database::scalar('SELECT status FROM wa_ticket_queue WHERE booking_id = :b AND phone = :p', ['b' => $bid, 'p' => $clerkIntl]) === 'expired');
} catch (Throwable $e) {
    check('the queue ran without throwing', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    Database::delete('wa_ticket_queue', 'booking_id = :b', ['b' => $bid]);
    $wipeWindows();
    Database::delete('payments', 'booking_id = :b', ['b' => $bid]);
    Database::delete('booking_legs', 'booking_id = :b', ['b' => $bid]);
    Database::delete('bookings', 'id = :b', ['b' => $bid]);
    if ($profSaved === null) {
        Database::delete('admin_profiles', 'admin_id = :a', ['a' => $clerk]);
    } else {
        Database::update('admin_profiles', ['whatsapp' => $profSaved['whatsapp'], 'country_code' => $profSaved['country_code']], 'admin_id = :a', ['a' => $clerk]);
    }
    Database::run("DELETE FROM message_logs WHERE booking_id = :b", ['b' => $bid]);
}

/* ---- wiring --------------------------------------------------------- */
$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);
check('the confirmation hook hands an unsent ticket to the queue', str_contains($src('whatsapp/aisensy.php'), 'WaQueue::onConfirmed('));
check('the AiSensy webhook opens the window and flushes before the bot answers',
    strpos($src('whatsapp/aisensy-webhook.php'), 'WaQueue::onInbound(') < strpos($src('whatsapp/aisensy-webhook.php'), "require_once INCLUDE_PATH . '/wabot.php'"));
check('a cron checks real delivery and expires old waits', str_contains($src('cron/wa-ticket-queue.php'), 'WaQueue::checkStatuses('));
check('checkout asks for an optional second number', str_contains($src('app.template.html'), 'id="cAltPhone"') && str_contains($src('assets/js/07-checkout.js'), 'altPhone:'));
check('the customer ticket screen has the "hi" button', str_contains($src('assets/js/07-checkout.js'), 'id="tkWaHi"'));
check('the counter ticket screen shows the scan-and-say-hi QR', str_contains($src('assets/js/14-counter.js'), 'function waHiBox'));

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
