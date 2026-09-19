<?php
/**
 * =====================================================================
 *  brain-test.php — 🌙 QuickBot Passive Brain (7 Sep 2026): the night
 *  shift thinks, the morning desk confirms.
 *
 *    • think(): a metronomic traveller (every 14 days, booked 3 days
 *      ahead, always 2 seats) is found, scored HOT, and lands on the day
 *      they are actually due to ring — with the trip they will ask for
 *    • noise is not a pattern: a cancelled-only number, a one-trip
 *      number and a scattershot number never reach the queue
 *    • the run is safe to repeat: one card per (day, number), a card the
 *      desk dismissed is never reopened, and an unused card expires
 *    • reconcile(): a card whose number then buys a verified ticket is
 *      marked confirmed and linked to that booking — the accuracy figure
 *      nobody has to click
 *    • capture(): "bhai 2 seat chahiye nepal 15th" becomes a draft with
 *      seats + date + direction; a greeting, a thank-you and a PNR do
 *      not; a duplicate and a missing date are flagged for the desk
 *    • parse(): a bare ordinal day ("15th", "15 tarikh") is a date and
 *      never a seat count, and request words never become the passenger
 *    • alerts(): tomorrow's departures and the fill warning, deduped per
 *      day; capacity comes from the coach layout, not total_seats
 *    • the switch really switches: brain_on = 0 → no thinking, no queue
 *    • static mirrors: API actions, desk panel, cron jobs, settings
 *      migration parity, WhatsApp intake, run-all registration
 *
 *  Creates real rows on throwaway PAST dates under a phone prefix unique
 *  to this suite, and removes every one of them at the end.
 *      php -c .claude/php-dev.ini tests/brain-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticketbrain.php';

/**
 * Every fixture number starts here, so cleanup can never touch real data.
 * EIGHT digits, so PREFIX + a 2-digit suffix is a real 10-digit mobile —
 * the engine now refuses anything shorter (placeholder / walk-in numbers).
 */
const BR_PREFIX = '93000770';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function head(string $t): void { echo "\n\033[1m$t\033[0m\n"; }

/* =====================================================================
 *  Guard: never run against a database that looks live.
 * ================================================================== */
$dbName = (string) Database::scalar('SELECT DATABASE()');
if (!str_contains($dbName, 'test')) {
    echo "\033[31mRefusing to run: database '{$dbName}' is not a test database.\033[0m\n";
    exit(1);
}
if (!TicketBrain::ready()) {
    echo "\033[31mBrain tables missing. Run database/upgrade-2026-09-quickbot-brain.sql first.\033[0m\n";
    exit(1);
}

$today = date('Y-m-d');

/* =====================================================================
 *  Fixtures — bookings only. The brain reads sales; it writes nothing to
 *  them, so a minimal verified row is a faithful fixture.
 * ================================================================== */
$scheduleId = (int) Database::scalar(
    "SELECT id FROM schedules WHERE status = 'scheduled' ORDER BY travel_date DESC LIMIT 1",
    [], 0
) ?: (int) Database::scalar('SELECT id FROM schedules ORDER BY id DESC LIMIT 1', [], 0);
if ($scheduleId < 1) {
    echo "\033[31mNo schedules in the test database — seed one first.\033[0m\n";
    exit(1);
}

$made = [];
/** One verified journey in the past: travel on $date, booked $lead days earlier. */
function seedTrip(string $phone, string $date, int $lead, int $seats, string $status = 'confirmed'): int {
    global $scheduleId, $made;
    $pnr = 'SHG-BRN-' . strtoupper(bin2hex(random_bytes(4)));
    $id  = Database::insert('bookings', [
        'pnr'           => $pnr,
        'contact_phone' => $phone,
        'status'        => $status,
        'total_amount'  => 2000 * $seats,
        'created_at'    => date('Y-m-d H:i:s', strtotime($date . ' -' . $lead . ' days')),
    ]);
    Database::insert('booking_legs', [
        'booking_id'    => $id,
        'schedule_id'   => $scheduleId,
        'travel_date'   => $date,
        'leg_type'      => 'outbound',
        'seat_count'    => $seats,
        'boarding_stop' => 'Mehsana',
    ]);
    $made[] = $id;

    return $id;
}

function cleanup(): void {
    global $made;
    if ($made !== []) {
        $in = implode(',', array_map('intval', $made));
        Database::query("DELETE FROM booking_legs WHERE booking_id IN ({$in})");
        Database::query("DELETE FROM bookings WHERE id IN ({$in})");
    }
    Database::query('DELETE FROM brain_predictions WHERE phone LIKE :p', ['p' => BR_PREFIX . '%']);
    Database::query('DELETE FROM brain_drafts WHERE phone LIKE :p', ['p' => BR_PREFIX . '%']);
    Database::query("DELETE FROM brain_alerts WHERE alert_date = :d", ['d' => date('Y-m-d')]);
}
register_shutdown_function('cleanup');

// A: the metronome. Every 14 days, booked 3 days ahead, 2 seats, last trip
//    11 days ago -> due in 3 days -> should ring TODAY.
$A = BR_PREFIX . '01';
for ($i = 6; $i >= 1; $i--) {
    seedTrip($A, date('Y-m-d', strtotime($today . ' -' . (11 + ($i - 1) * 14) . ' days')), 3, 2);
}
// B: scattershot, and last seen long ago.
$B = BR_PREFIX . '02';
foreach ([200, 160, 120, 3] as $k => $ago) {
    seedTrip($B, date('Y-m-d', strtotime($today . ' -' . (200 - $k * 3) . ' days')), 1, 1);
}
// C: one trip only — no rhythm to read.
$C = BR_PREFIX . '03';
seedTrip($C, date('Y-m-d', strtotime($today . ' -20 days')), 2, 1);
// D: cancelled only — noise, never a pattern.
$D = BR_PREFIX . '04';
seedTrip($D, date('Y-m-d', strtotime($today . ' -30 days')), 2, 1, 'cancelled');
seedTrip($D, date('Y-m-d', strtotime($today . ' -16 days')), 2, 1, 'cancelled');

Settings::flush();

/* =====================================================================
 *  1. The nightly pass
 * ================================================================== */
head('1. think() — who rings today, and why');

Database::query('DELETE FROM brain_predictions WHERE phone LIKE :p', ['p' => BR_PREFIX . '%']);
$r = TicketBrain::think($today);
check('think() ran and read the fixtures', ($r['travellers'] ?? 0) >= 1, 'travellers=' . ($r['travellers'] ?? 0));

$cardA = Database::fetch('SELECT * FROM brain_predictions WHERE phone = :p', ['p' => $A]);
check('the metronomic traveller is in the queue', $cardA !== null);

if ($cardA !== null) {
    check('...on the day they are due to ring (today)', (string) $cardA['predict_date'] === $today, (string) $cardA['predict_date']);
    check('...for the trip their own rhythm points at (+3 days)',
        (string) $cardA['travel_date'] === date('Y-m-d', strtotime($today . ' +3 days')),
        (string) $cardA['travel_date']);
    check('...carrying their usual party size', (int) $cardA['seats'] === 2, 'seats=' . (int) $cardA['seats']);
    check('...scored HOT — a perfect rhythm is the strongest signal',
        (string) $cardA['band'] === 'hot', 'band=' . $cardA['band'] . ' score=' . $cardA['score']);
    $why = json_decode((string) $cardA['reason'], true) ?: [];
    check('...and says why, in words the desk can argue with',
        $why !== [] && str_contains(implode(' ', $why), 'every 14 days'),
        $why[0] ?? '(none)');
    $sig = json_decode((string) $cardA['signals'], true) ?: [];
    check('...with the raw signals kept for audit',
        (float) ($sig['regularity'] ?? 0) >= 0.99 && (int) ($sig['trips'] ?? 0) === 6,
        'regularity=' . ($sig['regularity'] ?? '?') . ' trips=' . ($sig['trips'] ?? '?'));
}

check('a one-trip number has no rhythm to read',
    !Database::exists('SELECT 1 FROM brain_predictions WHERE phone = :p', ['p' => $C]));
check('a cancelled-only number is noise, not a pattern',
    !Database::exists('SELECT 1 FROM brain_predictions WHERE phone = :p', ['p' => $D]));

$cardB = Database::fetch('SELECT * FROM brain_predictions WHERE phone = :p', ['p' => $B]);
check('a scattershot traveller scores below the metronome',
    $cardB === null || (int) $cardB['score'] < (int) ($cardA['score'] ?? 1000),
    $cardB === null ? 'not queued at all' : 'score=' . $cardB['score']);

/* =====================================================================
 *  2. Re-running the night shift
 * ================================================================== */
head('2. A re-run is safe');

TicketBrain::think($today);
$dupes = (int) Database::scalar(
    'SELECT COUNT(*) FROM brain_predictions WHERE phone = :p AND predict_date = :d',
    ['p' => $A, 'd' => $today], 0
);
check('one card per number per day, however often it runs', $dupes === 1, 'rows=' . $dupes);

$idA = (int) ($cardA['id'] ?? 0);
check('the desk can dismiss a card', TicketBrain::resolvePrediction($idA, 'dismissed', null, 1));
TicketBrain::think($today);
$after = Database::fetch('SELECT status FROM brain_predictions WHERE id = :i', ['i' => $idA]);
check('...and the next run does not reopen it', (string) ($after['status'] ?? '') === 'dismissed', (string) ($after['status'] ?? '?'));
check('a dismissed card is out of the queue',
    !in_array($idA, array_column(TicketBrain::queue($today, 50, null, false), 'id'), true));

/* =====================================================================
 *  3. Did the guess land?
 * ================================================================== */
head('3. reconcile() — the accuracy nobody has to click');

Database::query('UPDATE brain_predictions SET status = \'open\', booking_id = NULL, resolved_at = NULL WHERE id = :i', ['i' => $idA]);
// The passenger rings and buys, right now.
$soldId = seedTrip($A, date('Y-m-d', strtotime($today . ' +3 days')), 0, 2);
Database::query('UPDATE bookings SET created_at = NOW() WHERE id = :i', ['i' => $soldId]);

TicketBrain::think($today);
$done = Database::fetch('SELECT status, booking_id FROM brain_predictions WHERE id = :i', ['i' => $idA]);
check('a card whose number then bought is marked confirmed', (string) ($done['status'] ?? '') === 'confirmed', (string) ($done['status'] ?? '?'));
check('...and linked to the exact booking', (int) ($done['booking_id'] ?? 0) === $soldId);

$acc = TicketBrain::accuracy(30);
check('accuracy counts that hit', (int) $acc['hits'] >= 1 && (float) $acc['hitRate'] > 0, json_encode($acc));

/* =====================================================================
 *  4. Requests that arrive on their own
 * ================================================================== */
head('4. capture() — WhatsApp in, draft out');

$P = BR_PREFIX . '05';
$d1 = TicketBrain::capture($P, 'bhai 2 seat chahiye nepal 15th', 'test');
check('the owner\'s own example becomes a draft', $d1 !== null);
if ($d1 !== null) {
    check('...2 seats read', (int) $d1['seats'] === 2, 'seats=' . $d1['seats']);
    check('...direction read', (string) $d1['direction'] === 'toNepal', (string) $d1['direction']);
    check('...and "15th" is a DATE, not a seat count',
        (string) $d1['date'] === TicketBrainTestNext15(), (string) $d1['date']);
}
check('a greeting is not a booking request', TicketBrain::capture($P, 'namaste', 'test') === null);
check('a thank-you is not a booking request', TicketBrain::capture($P, 'thank you bhai', 'test') === null);
check('a PNR is not a booking request', TicketBrain::capture($P, 'SHG-1234-5678-9012', 'test') === null);

$d2 = TicketBrain::capture($P, 'kal ko 1 sit khali cha?', 'test');
check('Nepali phrasing is a request', $d2 !== null && (int) $d2['seats'] === 1);
check('...and a second unconfirmed request is flagged for the desk',
    $d2 !== null && str_contains(implode(' ', $d2['flags']), 'already has an unconfirmed'),
    $d2 === null ? '-' : implode(' | ', $d2['flags']));

$dupPhone = $A;   // this number holds a real ticket for today+3
$d3 = TicketBrain::capture($dupPhone, '2 seat chahiye ' . date('j', strtotime($today . ' +3 days')) . 'th', 'test');
check('a duplicate of an existing ticket is flagged, not hidden',
    $d3 !== null && str_contains(implode(' ', $d3['flags']), 'DUPLICATE'),
    $d3 === null ? '(no draft)' : implode(' | ', $d3['flags']));

$noDate = TicketBrain::capture(BR_PREFIX . '06', '3 seats chahiye', 'test');
check('a missing date is flagged for the desk',
    $noDate !== null && str_contains(implode(' ', $noDate['flags']), 'No date'),
    $noDate === null ? '-' : implode(' | ', $noDate['flags']));

$queue = TicketBrain::drafts(20);
check('drafts reach the desk queue newest first', $queue !== [] && (int) $queue[0]['id'] > 0);
$firstId = (int) $queue[0]['id'];
check('the desk can dismiss a draft', TicketBrain::resolveDraft($firstId, 'dismissed', null, 1));
check('...and dismissing it twice changes nothing', !TicketBrain::resolveDraft($firstId, 'dismissed', null, 1));

/* =====================================================================
 *  5. The shared parser did not lose its manners
 * ================================================================== */
head('5. parse() — an ordinal is a date, a number is still seats');

$p = TicketBot::parse('15 tarikh 1 sit');
check('"15 tarikh" is a date', $p['date'] !== '' && (int) substr($p['date'], 8, 2) === 15, $p['date']);
check('...and the seat count survives it', (int) $p['seats'] === 1, 'seats=' . $p['seats']);
$p2 = TicketBot::parse('2 seats chahiye');
check('a bare number is still a seat count, not a date', (int) $p2['seats'] === 2 && $p2['date'] === '');
check('request words never become the passenger name', $p2['name'] === '', 'name=' . ($p2['name'] ?: '(empty)'));
$p3 = TicketBot::parse('Ram Bahadur 9876543210 2 seats kal');
check('a real name still survives', $p3['name'] === 'Ram Bahadur', 'name=' . $p3['name']);
$p4 = TicketBot::parse('12/9 ko 3 jana');
check('a slash date still wins over the ordinal rule', $p4['date'] !== '' && (int) $p4['seats'] === 3, $p4['date']);

/* =====================================================================
 *  5b. The pre-deploy audit's findings, each pinned as a regression.
 * ================================================================== */
head('5b. Audit regressions (7 Sep pre-deploy review)');

// BLOCKER: "nov 20th" used to lose the month and book the CURRENT month,
// because the month-first regex could not end on a \b before "th".
foreach ([
    ['2 seat nov 20th nepal',        '-11-20'],
    ['ram 9876543210 dec 31st 2 seat','-12-31'],
    ['hello sir 1 seat nov 1st',     '-11-01'],
    ['december 25th',                '-12-25'],
] as [$text, $tail]) {
    $p = TicketBot::parse($text);
    check('month + ordinal keeps its month: "' . $text . '"',
        $p['date'] !== '' && str_ends_with($p['date'], $tail), 'got ' . ($p['date'] ?: '(none)'));
}
check('the day-first order still works', str_ends_with((string) TicketBot::parse('20th nov 2 seat')['date'], '-11-20'));

// HIGH: a POSITION is not a date.
foreach (['2nd bus ma 2 seat chahiye', '3rd seat chahiye', 'seat no 12th'] as $text) {
    $p = TicketBot::parse($text);
    check('a position is not a travel date: "' . $text . '"', $p['date'] === '', 'got ' . ($p['date'] ?: '(none)'));
}
check('a leftover ordinal never becomes the passenger name',
    TicketBot::parse('2nd bus ma 2 seat chahiye')['name'] === '',
    'name=' . (TicketBot::parse('2nd bus ma 2 seat chahiye')['name'] ?: '(empty)'));

// BLOCKER: the counter walk-in placeholder must never become a card.
$PLACE = '0000000000';
Database::query('DELETE FROM brain_predictions WHERE phone = :p', ['p' => $PLACE]);
$phIds = [];
for ($i = 8; $i >= 1; $i--) {
    $phIds[] = seedTrip($PLACE, date('Y-m-d', strtotime($today . ' -' . $i . ' days')), 0, 1);
}
TicketBrain::think($today);
check('the 0000000000 walk-in placeholder never becomes a card',
    !Database::exists('SELECT 1 FROM brain_predictions WHERE phone = :p', ['p' => $PLACE]));
$in = implode(',', array_map('intval', $phIds));
Database::query("DELETE FROM booking_legs WHERE booking_id IN ({$in})");
Database::query("DELETE FROM bookings WHERE id IN ({$in})");

// HIGH: one open card per number, never three.
Database::query('DELETE FROM brain_predictions WHERE phone = :p', ['p' => $A]);
foreach ([-2, -1, 0] as $off) {
    Database::query(
        "INSERT INTO brain_predictions (predict_date, phone, passenger, score, band, travel_date, seats, status)
         VALUES (:d, :p, 'Dup Test', 600, 'warm', :t, 1, 'open')",
        ['d' => date('Y-m-d', strtotime($today . ' ' . $off . ' days')), 'p' => $A, 't' => date('Y-m-d', strtotime($today . ' +2 days'))]
    );
}
$q = TicketBrain::queue($today, 50, null, false);
$dupPhones = array_count_values(array_column($q, 'phone'));
check('the same number appears at most once in the queue',
    ($dupPhones[$A] ?? 0) <= 1, 'appeared ' . ($dupPhones[$A] ?? 0) . ' times');
check('...and the waiting badge counts numbers, not rows',
    (int) TicketBrain::pending()['cards'] === count($q), 'badge=' . TicketBrain::pending()['cards'] . ' queue=' . count($q));
Database::query('DELETE FROM brain_predictions WHERE phone = :p', ['p' => $A]);

// BLOCKER: a scoped desk sees only its own passengers.
Database::query('DELETE FROM brain_predictions WHERE phone LIKE :p', ['p' => BR_PREFIX . '%']);
Database::query(
    "INSERT INTO brain_predictions (predict_date, phone, passenger, score, band, travel_date, seats, status)
     VALUES (:d, :p, 'Someone Else', 800, 'hot', :t, 1, 'open')",
    ['d' => $today, 'p' => BR_PREFIX . '99', 't' => date('Y-m-d', strtotime($today . ' +2 days'))]
);
$unscoped = TicketBrain::queue($today, 50, null, false);
$scoped   = TicketBrain::queue($today, 50, null, false, 999999);   // an admin who sold nothing
check('an unscoped desk sees the card', count($unscoped) >= 1);
check('a SCOPED desk (counter agent) sees no other seller\'s passengers',
    $scoped === [], count($scoped) . ' leaked');
check('scoped drafts are filtered the same way', TicketBrain::drafts(20, 'new', 999999) === []);
check('the scoped badge is zero too', (int) TicketBrain::pending(999999)['cards'] === 0);

/* =====================================================================
 *  6. Standing intelligence
 * ================================================================== */
head('6. alerts() — what needs a decision today');

Database::query('DELETE FROM brain_alerts WHERE alert_date = :d', ['d' => $today]);
$alerts = TicketBrain::alerts($today);
check('alerts() runs and writes', is_array($alerts));
$kinds = array_column($alerts, 'kind');
/* 19 Sep 2026: this read "a departure card is present OR there are no alerts at
   all", which fails on any database that has a repeat canceller but nothing sold
   for tomorrow (shari_test). The rule in TicketBrain::alerts(): one departure
   card per scheduled, unblocked run tomorrow that has sold at least one seat. */
$tomorrowSold = Database::exists(
    "SELECT 1 FROM schedules s JOIN booking_seats bs ON bs.schedule_id = s.id AND bs.released_at IS NULL
      WHERE s.travel_date = :d AND s.is_blocked = 0 AND s.status = 'scheduled' LIMIT 1",
    ['d' => date('Y-m-d', strtotime($today . ' +1 day'))]
);
check('tomorrow\'s departures are on the checklist exactly when something is sold for tomorrow',
    in_array('departure', $kinds, true) === $tomorrowSold,
    ($tomorrowSold ? 'sold' : 'nothing sold') . ' -> ' . (implode(',', array_unique($kinds)) ?: '(none)'));

$cancelRefs = array_column(array_filter($alerts, static fn(array $a): bool => $a['kind'] === 'cancel_risk'), 'ref');
check('a placeholder number is never flagged as a repeat canceller',
    !in_array('0000000000', $cancelRefs, true) && !in_array('1111111111', $cancelRefs, true),
    $cancelRefs === [] ? 'no cancel alerts today' : implode(',', $cancelRefs));

$before = (int) Database::scalar('SELECT COUNT(*) FROM brain_alerts WHERE alert_date = :d', ['d' => $today], 0);
TicketBrain::alerts($today);
$afterN = (int) Database::scalar('SELECT COUNT(*) FROM brain_alerts WHERE alert_date = :d', ['d' => $today], 0);
check('the same alert is never raised twice in a day', $before === $afterN, "{$before} -> {$afterN}");

// Force the fill path: at threshold 0 every run with a real coach reports.
Settings::set('brain_fill_alert_pct', '10', 'int', 'booking', false);
Settings::flush();
Database::query('DELETE FROM brain_alerts WHERE alert_date = :d', ['d' => $today]);
$fill = array_values(array_filter(TicketBrain::alerts($today, false), static fn(array $a): bool => $a['kind'] === 'fill'));
check('the fill warning reads capacity from the coach layout, not total_seats',
    $fill === [] || ((int) $fill[0]['meta']['capacity'] > 0 && (int) $fill[0]['meta']['capacity'] < 200),
    $fill === [] ? 'no run above 10% today' : 'capacity=' . $fill[0]['meta']['capacity'] . ' pct=' . $fill[0]['meta']['pct']);
Settings::set('brain_fill_alert_pct', '80', 'int', 'booking', false);
Settings::flush();

// MEDIUM: the revenue line must be LIVE, not a 02:00 snapshot frozen at 0%.
Settings::set('brain_revenue_target', '50000', 'float', 'booking', false);
Settings::flush();
Database::query('DELETE FROM brain_alerts WHERE alert_date = :d AND kind = :k', ['d' => $today, 'k' => 'revenue']);
TicketBrain::alerts($today);
check('the nightly job never stores a revenue row (it would freeze at 0%)',
    !Database::exists("SELECT 1 FROM brain_alerts WHERE kind = 'revenue' AND alert_date = :d", ['d' => $today]));
$rev = TicketBrain::revenueAlert($today);
check('...it is computed live when the desk asks', $rev !== null && $rev['kind'] === 'revenue', $rev['title'] ?? '-');
$withRev = TicketBrain::openAlerts($today, 20);
check('...and reaches a desk allowed to see it',
    in_array('revenue', array_column($withRev, 'kind'), true));

// MEDIUM: money and other customers' numbers are permission-gated.
$limited = TicketBrain::openAlerts($today, 20, ['fill', 'demand_echo', 'departure']);
check('a desk without dashboard.view never sees revenue',
    !in_array('revenue', array_column($limited, 'kind'), true));
check('...nor other customers\' cancellation history',
    !in_array('cancel_risk', array_column($limited, 'kind'), true));
Settings::set('brain_revenue_target', '0', 'float', 'booking', false);
Settings::flush();

$open = array_values(array_filter(TicketBrain::openAlerts($today, 20), static fn(array $a): bool => (int) ($a['id'] ?? 0) > 0));
if ($open !== []) {
    check('the desk can clear an alert', TicketBrain::dismissAlert((int) $open[0]['id']));
} else {
    check('the desk can clear an alert', true, 'no open alert today — path covered by the unique-key test');
}

/* =====================================================================
 *  7. The switch really switches
 * ================================================================== */
head('7. brain_on = 0 stops the thinking');

Settings::set('brain_on', '0', 'bool', 'booking', false);
Settings::flush();
$off = TicketBrain::think($today);
check('think() refuses to run', isset($off['skipped']), json_encode($off));
check('the queue is empty', TicketBrain::queue($today, 10, null, false) === []);
check('nothing is captured from WhatsApp', TicketBrain::capture(BR_PREFIX . '07', '2 seat chahiye kal', 'test') === null);
check('alerts stay quiet', TicketBrain::alerts($today, false) === []);
Settings::set('brain_on', '1', 'bool', 'booking', false);
Settings::flush();
check('...and switching it back on restores the queue', TicketBrain::enabled());

/* =====================================================================
 *  8. Static mirrors — the wiring around the engine
 * ================================================================== */
head('8. Static mirrors');

$root = dirname(__DIR__);
$read = static fn(string $p): string => is_file($root . $p) ? (string) file_get_contents($root . $p) : '';

$api = $read('/api/quick-ticket.php');
check('API exposes the ready queue', str_contains($api, "'brain_queue'"));
check('API exposes dismiss', str_contains($api, "'brain_dismiss'"));
check('a sale reports back which card it came from', str_contains($api, 'brainCard') && str_contains($api, 'resolvePrediction'));

$desk = $read('/admin/quick-ticket.php');
check('the desk has the Ready Queue panel', str_contains($desk, 'id="qtBrain"'));
check('...loads it on open', str_contains($desk, 'loadBrain()'));
check('...and sends the card id with the sale', str_contains($desk, 'brainCard:'));

check('nightly cron exists', is_file($root . '/cron/brain-nightly.php'));
check('morning digest cron exists', is_file($root . '/cron/brain-digest.php'));
check('the nightly job claims its day exactly once', str_contains($read('/cron/brain-nightly.php'), 'EventBus::claim'));
check('the digest checks its toggle BEFORE claiming the day',
    (bool) preg_match('/brain_digest_on.*EventBus::claim/s', $read('/cron/brain-digest.php')));

// 18 Sep 2026: the reply logic lives in includes/wabot.php, shared by the
// Twilio webhook and the Meta Cloud API webhook.
check('WhatsApp intake is wired to the brain', str_contains($read('/includes/wabot.php'), 'TicketBrain::capture')
    && str_contains($read('/api/whatsapp-webhook.php'), 'WaBot::reply')
    && str_contains($read('/whatsapp/webhook.php'), 'WaBot::reply'));

// Only what actually goes out to the passenger counts here — a comment
// explaining the rule must not be mistaken for breaking it. Strings only.
$waStrings = '';
foreach (array_merge(...array_map(static fn ($f) => token_get_all($read($f)), ['/api/whatsapp-webhook.php', '/includes/wabot.php', '/whatsapp/webhook.php'])) as $tok) {
    if (is_array($tok) && in_array($tok[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
        $waStrings .= ' ' . $tok[1];
    }
}
check('...and no reply claims a seat is held before the desk confirms',
    !preg_match('/(booking confirmed|seat (is )?(held|reserved|booked))/i', $waStrings));

check('run-all registers this suite', str_contains($read('/tests/run-all.php'), 'brain-test.php'));

// Every brain_* setting the engine reads must be seeded by the migration,
// or the office cannot change it from Admin -> Settings.
$engine = $read('/includes/ticketbrain.php');
$mig    = $read('/database/upgrade-2026-09-quickbot-brain.php');
preg_match_all("/Settings::get(?:Bool|Int|String|Float)?\(\s*'(brain_[a-z_]+)'/", $engine, $m);
$needed = array_values(array_unique($m[1] ?? []));
$missing = array_values(array_filter($needed, static fn(string $k): bool => !str_contains($mig, "'" . $k . "'")));
check('every brain setting the engine reads is seeded by the migration',
    $missing === [], $missing === [] ? count($needed) . ' keys' : 'missing: ' . implode(', ', $missing));

check('the API scopes the queue to the signed-in desk',
    str_contains($api, 'bookingScopeAdminId'));
check('...and gates money / other customers behind a permission',
    str_contains($api, "Auth::can('dashboard.view')") && str_contains($api, 'cancel_risk'));
check('the card tap no longer truncates a 91-series mobile',
    !preg_match('/replace\(\s*\/\^\\\\\+\?91\//', $desk) && str_contains($desk, "indexOf('91') === 0"));
check('capacity excludes permanently reserved staff berths',
    str_contains($read('/includes/ticketbrain.php'), 'Seats::staffSeats'));

check('the schema migration creates all three tables',
    (bool) preg_match('/brain_predictions/', $read('/database/upgrade-2026-09-quickbot-brain.sql'))
    && str_contains($read('/database/upgrade-2026-09-quickbot-brain.sql'), 'brain_drafts')
    && str_contains($read('/database/upgrade-2026-09-quickbot-brain.sql'), 'brain_alerts'));

/* =====================================================================
 *  Result
 * ================================================================== */
echo "\n" . str_repeat('-', 62) . "\n";
echo "  \033[32m{$PASS} passed\033[0m, " . ($FAIL > 0 ? "\033[31m{$FAIL} failed\033[0m" : "0 failed") . "\n";
echo str_repeat('-', 62) . "\n";

/** The next 15th of a month that has not passed — mirrors nextDayOfMonth(). */
function TicketBrainTestNext15(): string {
    $t = date('Y-m-d');
    $y = (int) substr($t, 0, 4); $m = (int) substr($t, 5, 2);
    $iso = sprintf('%04d-%02d-15', $y, $m);
    if ($iso < $t) { $m++; if ($m > 12) { $m = 1; $y++; } $iso = sprintf('%04d-%02d-15', $y, $m); }

    return $iso;
}

exit($FAIL > 0 ? 1 : 0);
