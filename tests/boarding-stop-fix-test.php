<?php
/**
 * =====================================================================
 *  Boarding-stop fix — end-to-end truth test.
 *
 *  The 29 Aug 2026 fix chain (SHG-2026-00056):
 *
 *    1. api/search.php serializes route_stops rows into canonical
 *       single-string labels ("Name · Landmark @ HH:MM [lat,lng]").
 *       Before the fix it returned raw associative rows, and the
 *       <option> fill in 06-results.js rendered them as
 *       "[object Object]" — an empty <select> that posted "" for
 *       boarding.
 *
 *    2. api/search.php pre-computes boardingIdx / dropIdx: which
 *       option in the returned arrays matches the customer's SEARCHED
 *       town via Boarding::townKey. The client just applies that
 *       index — no client-side alias maps.
 *
 *    3. api/book.php forwards a new `originTown` hint, and
 *       BookingService::defaultBoardingStop() backfills the boarding
 *       column when the payload arrived empty (an old client, a
 *       route with no stop rows), preferring the hint's stop and
 *       falling back to the first still-open pickup.
 *
 *    4. admin/booking-view.php invalidates the cached ticket PDF on
 *       an edit_passenger action so a boarding-stop correction takes
 *       effect on the next download.
 *
 *  This suite proves each of those still holds against a dev server
 *  serving THIS checkout. CLI only: it writes bookings to whichever
 *  database includes/bootstrap.php resolves.
 *
 *  16 Nov 2026 (T3) — reworked so it can rejoin the battery:
 *    · it booked available[0] for a Male passenger; that seat (L1-L3,
 *      shown A1-A3) is now women-reserved, so book.php rightly said 409
 *      and three checks failed. The seats are now picked by the rules
 *      api/seats.php itself publishes: not in `female`, not in a unit
 *      locked female_only. A 409 on such a seat is a real mismatch
 *      between seats.php and book.php and fails loudly.
 *    · base URL: SHG_TEST_BASE, else the suite starts its OWN php -S on
 *      a free port serving this checkout (the battery passes no base and
 *      :8899 may be another lineage's server).
 *    · the date is near the end of the booking window, not today+3
 *      where everyday suites and people book; fixed test phones; every
 *      row it creates (bookings + the tables that do not cascade, the
 *      ticket PDF, a schedule it caused) is removed, before and after.
 *    · the PDF is rendered in-process (Ticket::pdfPath) instead of a
 *      shell_exec with a relative ini path; the dead POST to
 *      admin/login.php is gone; townKey is the real Boarding::townKey,
 *      not a mirror that could drift.
 *
 *  16 Nov 2026 (T3 review): the cleanup deleted `bookings` raw. That
 *  cascades booking_seats but runs no PHP, so on the kept (pre-generated)
 *  schedule it left (a) a 'male_only' gender lock on the two cabins each
 *  run used, with no passenger in them — schedule 18770 held eight — and
 *  (b) schedules.seats_booked at the last claimed count ("says 2, has 0").
 *  It also left the two user_profiles rows book.php upserts by phone and
 *  the "click-to-chat queued" app_logs lines. Now every booking goes back
 *  through Seats::releaseBooking (the app's own release path: recount +
 *  cabin-lock recompute), lock rows this run created are removed, and the
 *  profiles and log lines go too. Damage left by EARLIER runs is healed
 *  before booking, by the app's own rule (a gender lock on a cabin with no
 *  live seat is stale; seats_booked = live seats) and only on this suite's
 *  schedule (route × today+25). The PNR scan after the fix also found the
 *  booking's audit_logs, automation_log and office-bell (notifications)
 *  rows left behind; they go too. Checks after the cleanup catch each of
 *  those leftovers if it ever comes back.
 *
 *  Runs with:  php -c .claude/php-dev.ini -d extension=php_curl.dll \
 *                  tests/boarding-stop-fix-test.php
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticket.php';

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

/* SHG_TEST_BASE wins; without it the suite starts its own php -S on a free
   port (this checkout, this php + ini) and stops it on exit — the same
   helper as tests/manifest-test.php (battery Y7). */
function shg_test_base(): string
{
    $env = trim((string) getenv('SHG_TEST_BASE'));
    if ($env !== '') {
        return rtrim($env, '/');
    }
    $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($probe === false) {
        return 'http://localhost:8899';
    }
    $name = (string) stream_socket_get_name($probe, false);
    fclose($probe);
    $port = (int) substr($name, (int) strrpos($name, ':') + 1);
    $ini  = (string) php_ini_loaded_file();
    $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    $cmd  = array_merge([PHP_BINARY], $ini !== '' ? ['-c', $ini] : [], ['-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)]);
    $proc = @proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($proc)) {
        return 'http://localhost:8899';
    }
    register_shutdown_function(static function () use ($proc): void {
        @proc_terminate($proc);
        @proc_close($proc);
    });
    for ($i = 0; $i < 100; $i++) {   // up to ~10 s for the server to answer
        $c = @fsockopen('127.0.0.1', $port, $e1, $e2, 0.2);
        if ($c !== false) { fclose($c); break; }
        usleep(100000);
    }
    return 'http://127.0.0.1:' . $port;
}
define('BASE', shg_test_base());

/* Fixed throwaway contacts, so a crashed run's rows are still found. */
const BS_PHONE_1 = '9100000741';
const BS_PHONE_2 = '9100000742';
const BS_EMAIL   = 'bs-test@t.local';

$jar = sys_get_temp_dir() . '/shg_bs_' . getmypid() . '.cookies';
@unlink($jar);

function req(string $method, string $url, array $opt = []): array {
    global $jar;
    $ch = curl_init(BASE . $url);
    $headers = $opt['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 30,
    ]);
    if (isset($opt['json'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
        $headers[] = 'Content-Type: application/json';
    }
    if ($headers) curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string) $body];
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  \e[32mPASS\e[0m  $label" . ($detail ? " — $detail" : '') . "\n"; }
    else     { $fail++; echo "  \e[31mFAIL\e[0m  $label" . ($detail ? " — $detail" : '') . "\n"; }
}

/**
 * Seats a MALE passenger may take, by the rules api/seats.php publishes:
 * available, not women-reserved, not in a cabin locked to women. A seat in
 * an empty cabin comes first, so a second booking never shares one.
 *
 * @param array<string, mixed> $d  the data block of api/seats.php
 * @return list<string>
 */
function seatsForMale(array $d, array $exclude = []): array {
    $female   = array_map('strval', (array) ($d['female'] ?? []));
    $inUnit   = [];
    $noMale   = [];
    foreach ((array) ($d['units'] ?? []) as $u) {
        foreach ((array) ($u['seats'] ?? []) as $s) {
            $inUnit[(string) $s] = true;
            if ((string) ($u['lock'] ?? '') !== '' && (string) ($u['lock'] ?? '') !== 'male_only') {
                $noMale[(string) $s] = true;
            }
        }
    }
    $empty = []; $shared = [];
    foreach ((array) ($d['available'] ?? []) as $s) {
        $s = (string) $s;
        if (in_array($s, $female, true) || isset($noMale[$s]) || in_array($s, $exclude, true)) {
            continue;
        }
        if (isset($inUnit[$s])) { $shared[] = $s; } else { $empty[] = $s; }
    }
    return array_merge($empty, $shared);
}

/* What the run touched on its schedule, so the cleanup can put it back:
   schedule id => [unit_key => true] for each cabin a test seat sat in, and
   schedule id => [unit_key => gender_lock] as it stood before the first booking. */
$bsTouched     = [];
$bsLocksBefore = [];
$bsPnrs        = [];   // every PNR the cleanup removed, for the app_logs check

/** @return array<string, string> unit_key => gender_lock, every lock row on the schedule */
function bs_locks(int $sid): array {
    $out = [];
    foreach (Database::fetchAll('SELECT unit_key, gender_lock FROM schedule_unit_locks WHERE schedule_id = :s', ['s' => $sid]) as $r) {
        $out[(string) $r['unit_key']] = (string) $r['gender_lock'];
    }
    return $out;
}

/**
 * The cabins' members as named placeholders, for "is anybody seated in this cabin".
 *
 * @return array{0: string, 1: array<string, string>}
 */
function bs_members(string $unitKey, int $sid): array {
    $ph = []; $p = [];
    foreach (Seats::unitSeats($unitKey, Seats::coachForSchedule($sid)) as $i => $m) {
        $ph[] = ':m' . $i;
        $p['m' . $i] = $m;
    }
    return [implode(',', $ph), $p];
}

/**
 * Cabins of the schedule holding a gender lock while nobody is seated in them
 * — the app's own rule for a stale lock (Seats::releaseBooking recomputes an
 * emptied cabin to 'none'; tests/run-all.php clearStaleUnitLocks drops them).
 *
 * @return list<string>
 */
function bs_stale_locks(int $sid): array {
    $out = [];
    foreach (Database::fetchAll("SELECT unit_key FROM schedule_unit_locks WHERE schedule_id = :s AND gender_lock <> 'none'", ['s' => $sid]) as $r) {
        [$in, $p] = bs_members((string) $r['unit_key'], $sid);
        if ($in === '') {
            continue;
        }
        $live = (int) Database::scalar(
            'SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL AND seat_no IN (' . $in . ')',
            ['s' => $sid] + $p, 0);
        if ($live === 0) {
            $out[] = (string) $r['unit_key'];
        }
    }
    return $out;
}

/** [cached schedules.seats_booked, live seats by the same count Seats::claim/releaseBooking use]; null = no such schedule. */
function bs_seat_count(int $sid): ?array {
    $row = Database::fetch('SELECT seats_booked FROM schedules WHERE id = :s', ['s' => $sid]);
    if ($row === null) {
        return null;
    }
    $live = (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_seats WHERE schedule_id = :s AND released_at IS NULL AND seat_no NOT LIKE 'EX%'",
        ['s' => $sid], 0);
    return [(int) $row['seats_booked'], $live];
}

/**
 * Put the suite's schedule right by the app's own rules: drop a gender lock
 * from a cabin nobody sits in, and recount seats_booked. Heals what EARLIER
 * runs of this suite left (it booked through a raw delete until 16 Nov); at
 * the end it runs only AFTER the checks, so it never hides a leftover of
 * this run. Each delete re-tests the cabin in the same statement, so a
 * passenger seated meanwhile keeps their lock.
 */
function bs_heal_schedule(int $sid): void {
    foreach (bs_stale_locks($sid) as $unit) {
        [$in, $p] = bs_members($unit, $sid);
        try {
            Database::run(
                "DELETE FROM schedule_unit_locks
                  WHERE schedule_id = :s AND unit_key = :u AND gender_lock <> 'none'
                    AND NOT EXISTS (SELECT 1 FROM booking_seats
                                     WHERE schedule_id = :s2 AND released_at IS NULL AND seat_no IN (" . $in . '))',
                ['s' => $sid, 'u' => $unit, 's2' => $sid] + $p);
        } catch (Throwable $e) {}
    }
    try {
        Database::run(
            "UPDATE schedules
                SET seats_booked = (SELECT COUNT(*) FROM booking_seats
                                     WHERE schedule_id = :s1 AND released_at IS NULL AND seat_no NOT LIKE 'EX%')
              WHERE id = :s2",
            ['s1' => $sid, 's2' => $sid]);
    } catch (Throwable $e) {}
}

/**
 * Rows a booking leaves keyed on its PNR in tables with no foreign key to it:
 * the audit trail (booking.create, ticket.issue), the automation events
 * (booking.created / booking.approved, alert:online:<PNR>) and the office
 * bell ("New online customer · <PNR>"). Each as "<table> WHERE …" + params,
 * shared by the cleanup (DELETE FROM) and the check (SELECT COUNT(*) FROM).
 *
 * @return list<array{0: string, 1: array<string, string>}>
 */
function bs_pnr_rows(string $pnr): array {
    return [
        ["audit_logs WHERE entity_type = 'booking' AND entity_id = :p", ['p' => $pnr]],
        ['automation_log WHERE data LIKE :d OR dedupe_key LIKE :k', ['d' => '%"booking.pnr":"' . $pnr . '"%', 'k' => '%:' . $pnr]],
        ['notifications WHERE link LIKE :l OR link LIKE :l2 OR title LIKE :t',
            ['l' => '%pnr=' . $pnr, 'l2' => '%pnr=' . $pnr . '&%', 't' => '%· ' . $pnr]],
    ];
}

/** Everything this suite (or a crashed earlier run) created. */
function bs_cleanup(): void {
    global $bsTouched, $bsLocksBefore, $bsPnrs;
    $ids = []; $pnrs = [];
    foreach (Database::fetchAll(
        'SELECT id, pnr FROM bookings WHERE contact_phone IN (:a, :b) OR contact_email = :e',
        ['a' => BS_PHONE_1, 'b' => BS_PHONE_2, 'e' => BS_EMAIL]) as $r) {
        $ids[]  = (int) $r['id'];
        $pnrs[] = (string) $r['pnr'];
    }
    foreach ($ids as $id) {
        // the rendered ticket files (tickets cascades with the booking; its files do not)
        try {
            foreach (Database::fetchAll('SELECT pdf_path, png_path FROM tickets WHERE booking_id = :b', ['b' => $id]) as $t) {
                foreach (['pdf_path', 'png_path'] as $k) {
                    $f = basename((string) ($t[$k] ?? ''));
                    if ($f !== '' && defined('TICKET_PATH') && is_file(TICKET_PATH . '/' . $f)) { @unlink(TICKET_PATH . '/' . $f); }
                }
            }
        } catch (Throwable $e) {}
        // tables keyed on the booking without a cascading foreign key
        foreach (['message_logs', 'wa_outbox', 'wa_ticket_queue', 'delivery_log', 'msg_ledger', 'payment_events',
                  'push_log', 'ticket_links', 'booking_collections', 'booking_fines', 'booking_ad_source', 'agent_ledger',
                  'ticket_edit_audit', 'trip_eta_alerts', 'eta_alert_steps', 'trip_events', 'payment_reviews',
                  'brain_predictions', 'brain_drafts', 'offline_tickets', 'notifications'] as $t) {
            try { Database::run("DELETE FROM `$t` WHERE booking_id = :b", ['b' => $id]); } catch (Throwable $e) {}
        }
        try { Database::run("DELETE FROM audit_logs WHERE entity_type = 'booking' AND entity_id = :i", ['i' => (string) $id]); } catch (Throwable $e) {}
        /* Seats go back through the app's release path, not the cascade: it
           recounts schedules.seats_booked and recomputes each cabin's gender
           lock. The cascade alone runs no PHP and left both behind. */
        foreach (Database::fetchAll('SELECT schedule_id, seat_no FROM booking_seats WHERE booking_id = :b', ['b' => $id]) as $sr) {
            $bsTouched[(int) $sr['schedule_id']][Seats::unitKey((string) $sr['seat_no'])] = true;
        }
        try { Database::transaction(static fn(): int => Seats::releaseBooking($id)); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => $id]);   // legs, passengers, payments, tickets cascade
    }
    /* The recompute leaves an emptied cabin's row at 'none'. A row this run
       created (absent before the first booking) goes; one that was already
       there stays as the recompute set it. A 'none' row and no row mean the
       same to the seat map and to Seats::assertGenderAllowed. */
    foreach ($bsTouched as $sid => $units) {
        foreach (array_keys($units) as $unit) {
            if (isset($bsLocksBefore[$sid][$unit])) {
                continue;
            }
            try {
                Database::run("DELETE FROM schedule_unit_locks
                                WHERE schedule_id = :s AND unit_key = :u AND gender_lock = 'none' AND locked_by_booking IS NULL",
                    ['s' => $sid, 'u' => (string) $unit]);
            } catch (Throwable $e) {}
        }
    }
    foreach ($pnrs as $p) {
        foreach (['wa_outbox', 'wa_ticket_queue', 'delivery_log', 'msg_ledger', 'payment_events'] as $t) {
            try { Database::run("DELETE FROM `$t` WHERE pnr = :p", ['p' => $p]); } catch (Throwable $e) {}
        }
        // app_logs lines about this booking ({"pnr":"SHG-…"}: mail skipped, download refused, …)
        try { Database::run('DELETE FROM app_logs WHERE context LIKE :p', ['p' => '%"pnr":"' . $p . '"%']); } catch (Throwable $e) {}
        // booking.create / ticket.issue audit rows, the automation events and the office bell
        foreach (bs_pnr_rows($p) as [$sql, $params]) {
            try { Database::run('DELETE FROM ' . $sql, $params); } catch (Throwable $e) {}
        }
        $bsPnrs[] = $p;
        // ticket_<PNR>.pdf / .png and the render lock files beside them
        if ($p !== '' && preg_match('/^[A-Z0-9-]+$/', $p) === 1 && defined('TICKET_PATH')) {
            foreach (glob(TICKET_PATH . '/*' . $p . '*') ?: [] as $f) { @unlink($f); }
        }
    }
    foreach ([BS_PHONE_1, BS_PHONE_2] as $ph) {
        try { Database::run('DELETE FROM message_logs WHERE to_number IN (:a, :b, :c)', ['a' => $ph, 'b' => '91' . $ph, 'c' => '+91' . $ph]); } catch (Throwable $e) {}
    }
    // the customer-master rows book.php upserts by phone (never one a person signed in with)
    try { Database::run('DELETE FROM user_profiles WHERE phone IN (:a, :b) AND user_id IS NULL', ['a' => BS_PHONE_1, 'b' => BS_PHONE_2]); } catch (Throwable $e) {}
    // "WhatsApp click-to-chat queued" lines for the tickets (the phones are this suite's alone)
    try { Database::run('DELETE FROM app_logs WHERE context LIKE :a OR context LIKE :b',
        ['a' => '%' . BS_PHONE_1 . '%', 'b' => '%' . BS_PHONE_2 . '%']); } catch (Throwable $e) {}
}

bs_cleanup();
$bsTouched = [];   // from here on: only the cabins THIS run books
/* Two guest bookings per run; a battery that already booked from this IP
   inside five minutes must not turn them into a 429 (book_create: 12/300 s). */
try { Database::run("DELETE FROM rate_limits WHERE bucket = 'book_create' AND identifier IN ('127.0.0.1', '::1')"); } catch (Throwable $e) {}

/* Away from today+1..+3, where the everyday suites and people book, but
   always inside the booking window: today+25, pulled in when the window
   (booking_horizon_days, 30 by default) ends sooner. */
$win  = bookingWindow();
$date = max($win['from'], min(addDaysISO(todayISO(), 25), addDaysISO($win['to'], -2)));
$scheduleBefore = [];   // route id => schedule id that existed before this run
$bsSids         = [];   // the schedule(s) of the booked route on $date

try {
    echo "\n=== boot / CSRF (guest) ===   " . BASE . "   travel $date\n";
    $home = req('GET', '/', []);
    check('GET / returns 200', $home['code'] === 200, 'HTTP ' . $home['code']);
    preg_match('/window\.SHG_BOOT = (\{.*?\});/s', $home['body'], $m);
    $boot = $m ? json_decode($m[1], true) : null;
    $csrf = $boot['csrf'] ?? '';
    check('CSRF token issued', $csrf !== '');

    echo "\n=== 1. api/search.php returns canonical STRING stops ===\n";
    $active = Database::fetch('SELECT id, from_city, to_city FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1');
    check('active route present', $active !== null);
    $from = (string) ($active['from_city'] ?? '');
    $to   = (string) ($active['to_city'] ?? '');
    foreach (Database::fetchAll('SELECT id, route_id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $r) {
        $scheduleBefore[(int) $r['route_id']] = (int) $r['id'];
    }

    $s  = req('POST', '/api/search.php', ['json' => ['from' => $from, 'to' => $to, 'date' => $date]]);
    $sj = json_decode($s['body'], true);
    check('search ok', ($sj['ok'] ?? false) === true, 'HTTP ' . $s['code'] . ' ' . substr($s['body'], 0, 160));
    $route = $sj['data']['results'][0] ?? null;
    check('a bookable route came back', $route !== null);
    if ($route === null) {
        throw new RuntimeException('no route to book on ' . $date);
    }

    $boarding = $route['boarding'] ?? [];
    check('boarding is a non-empty array', is_array($boarding) && count($boarding) > 0, count($boarding) . ' stops');
    $allStrings = array_reduce($boarding, static fn($ok, $s) => $ok && is_string($s), true);
    check('every boarding entry is a STRING (not an object)', $allStrings);
    $noObjectObject = array_reduce($boarding, static fn($ok, $s) => $ok && strpos((string) $s, '[object') === false, true);
    check('no "[object Object]" leaked into the payload', $noObjectObject);

    $hasTime = array_reduce($boarding, static fn($ok, $s) => $ok || preg_match('/@\s*\d\d:\d\d/', (string) $s) === 1, false);
    check('at least one boarding string carries "@ HH:MM"', $hasTime);

    echo "\n=== 2. server-computed boardingIdx picks the searched town ===\n";
    check('boardingIdx field present', array_key_exists('boardingIdx', $route),
          var_export($route['boardingIdx'] ?? null, true));
    check('boardingIdx is an int', is_int($route['boardingIdx'] ?? null));
    $idx    = (int) ($route['boardingIdx'] ?? -1);
    $picked = ($idx >= 0 && $idx < count($boarding)) ? (string) $boarding[$idx] : '(nothing)';
    check('boardingIdx points at an existing option', $picked !== '(nothing)', "idx=$idx → $picked");
    // Searched by from_city, so the index must land on that town — by the app's own rule.
    $wantKey = Boarding::townKey($from);
    $gotKey  = Boarding::townKey($picked);
    check('the picked option matches the searched town by townKey',
          $wantKey === $gotKey || $wantKey === '' || $picked === '(nothing)',
          "want=$wantKey got=$gotKey");

    echo "\n=== 3. server backfills boarding_stop when payload is empty ===\n";
    /* The booked route's schedule on $date: heal what earlier runs left (stale
       cabin locks, a drifted seats_booked), then note the lock rows as they
       stand, so the cleanup can tell the rows this run creates apart. */
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d',
        ['r' => (int) $route['routeId'], 'd' => $date]) as $r) {
        $bsSids[] = (int) $r['id'];
    }
    foreach ($bsSids as $sid) {
        bs_heal_schedule($sid);
        $bsLocksBefore[$sid] = bs_locks($sid);
    }
    $av   = req('POST', '/api/seats.php', ['json' => ['routeId' => $route['routeId'], 'date' => $date]]);
    $avd  = (array) (json_decode($av['body'], true)['data'] ?? []);
    $male = seatsForMale($avd);
    $seat = $male[0] ?? null;
    check('a free seat a Male passenger may take exists (not women-reserved, not in a women-locked cabin)', $seat !== null,
          ($seat ?? '(none)') . '; women-reserved: ' . implode(',', (array) ($avd['female'] ?? [])));

    $pnr = '';
    if ($seat !== null) {
        $bk = req('POST', '/api/book.php', [
            'headers' => ['X-CSRF-Token: ' . $csrf],
            'json' => [
                'routeId' => $route['routeId'], 'travelDate' => $date, 'seats' => [$seat],
                'passengers' => [['seat' => $seat, 'name' => 'BS Test', 'age' => 30, 'gender' => 'Male']],
                'contact' => ['phone' => BS_PHONE_1, 'email' => BS_EMAIL, 'idType' => 'Voter ID', 'idNum' => 'BS' . getmypid()],
                'bookingMode' => 'sharing',
                // The bug: the buggy dropdown was posting '' here. Prove the
                // server no longer stores '' by posting '' explicitly.
                'boarding' => '',
                'drop' => 'Rupaidiha',
                // Client hint the fix wired in — should pick the searched town's stop.
                'originTown' => $from,
                'paymentMethod' => 'cod', 'isCod' => true,
            ],
        ]);
        $bj = json_decode($bk['body'], true);
        check('book.php accepted the empty-boarding request', ($bj['ok'] ?? false) === true,
              'HTTP ' . $bk['code'] . ' ' . substr($bk['body'], 0, 200));
        $pnr = (string) ($bj['data']['pnr'] ?? '');
    }
    check('a PNR was assigned', $pnr !== '', $pnr);

    if ($pnr !== '') {
        $r = Database::fetch(
            'SELECT b.pnr, l.boarding_stop, l.drop_stop
               FROM bookings b JOIN booking_legs l ON l.booking_id = b.id
              WHERE b.pnr = :p', ['p' => $pnr]);
        $stored = (string) ($r['boarding_stop'] ?? '');
        check('booking_legs.boarding_stop is NOT empty (fallback fired)',
              $stored !== '', 'stored=' . var_export($stored, true));
        // The stored value must match the originTown hint via townKey — the
        // fix's whole point is that a customer who searched one town never
        // gets a ticket that says another.
        if ($stored !== '') {
            check('stored boarding_stop matches originTown by townKey',
                  Boarding::townKey($stored) === Boarding::townKey($from),
                  "stored=$stored  origin=$from");
        }
    }

    echo "\n=== 4. explicit boarding string round-trips unchanged ===\n";
    // A second booking that DOES send a real boarding — must land in DB verbatim.
    $s2    = req('POST', '/api/seats.php', ['json' => ['routeId' => $route['routeId'], 'date' => $date]]);
    $s2d   = (array) (json_decode($s2['body'], true)['data'] ?? []);
    $seat2 = seatsForMale($s2d, $seat !== null ? [$seat] : [])[0] ?? null;
    check('a second free seat exists', $seat2 !== null && $seat2 !== $seat, (string) $seat2);

    if ($seat2 !== null && $seat2 !== $seat) {
        $wantStop = (string) $boarding[count($boarding) - 1];  // last (latest) stop — never the first town
        $bk2 = req('POST', '/api/book.php', [
            'headers' => ['X-CSRF-Token: ' . $csrf],
            'json' => [
                'routeId' => $route['routeId'], 'travelDate' => $date, 'seats' => [$seat2],
                // Same gender as pax #1 so the shared-cabin gender lock is
                // never the reason a booking gets refused mid-suite. This test
                // is about boarding_stop, not the gender rule.
                'passengers' => [['seat' => $seat2, 'name' => 'BS Test Two', 'age' => 26, 'gender' => 'Male']],
                'contact' => ['phone' => BS_PHONE_2, 'email' => BS_EMAIL, 'idType' => 'Voter ID', 'idNum' => 'BS2' . getmypid()],
                'bookingMode' => 'sharing',
                'boarding' => $wantStop,
                'drop' => 'Rupaidiha',
                'paymentMethod' => 'cod', 'isCod' => true,
            ],
        ]);
        $bj2 = json_decode($bk2['body'], true);
        check('book.php accepted a real-boarding request', ($bj2['ok'] ?? false) === true,
              'HTTP ' . $bk2['code'] . ' ' . substr($bk2['body'], 0, 200));
        $pnr2 = (string) ($bj2['data']['pnr'] ?? '');
        if ($pnr2 !== '') {
            $r = Database::fetch('SELECT l.boarding_stop FROM bookings b JOIN booking_legs l ON l.booking_id = b.id WHERE b.pnr = :p', ['p' => $pnr2]);
            check('DB stored the exact string we sent',
                  ($r['boarding_stop'] ?? null) === $wantStop,
                  'want=' . $wantStop . '  got=' . (string) ($r['boarding_stop'] ?? ''));
        }
    }

    echo "\n=== 5. ticket PDF renders through fine after the fix ===\n";
    if ($pnr !== '') {
        // Confirm the booking and render the PDF the download endpoint serves:
        // a file on disk proves the render path is intact for a boarding-stop-backfilled row.
        Database::run("UPDATE bookings SET status = 'confirmed', confirmed_at = NOW() WHERE pnr = :p", ['p' => $pnr]);
        Database::run("UPDATE payments p JOIN bookings b ON b.id = p.booking_id SET p.status = 'verified' WHERE b.pnr = :p", ['p' => $pnr]);
        $bid  = (int) Database::scalar('SELECT id FROM bookings WHERE pnr = :p', ['p' => $pnr], 0);
        $path = '';
        try {
            $path = (string) Ticket::pdfPath($bid, true);
        } catch (Throwable $e) {
            $path = get_class($e) . ': ' . $e->getMessage();
        }
        check('ticket PDF written', $path !== '' && is_file($path), $path);
        if (is_file($path)) {
            check('PDF is non-trivial (>= 5 KB)', filesize($path) >= 5000, filesize($path) . ' bytes');
        }
    } else {
        check('ticket PDF written', false, 'no booking to render (section 3 failed)');
    }
} catch (Throwable $e) {
    check('the suite ran to the end', false, get_class($e) . ': ' . $e->getMessage());
} finally {
    /* The gender locks this run's bookings took (shown in the cleanup check
       below, so a pass there is seen to have had something to clean). */
    $lockedByRun = [];
    foreach ($bsSids as $sid) {
        foreach (bs_locks($sid) as $unit => $lock) {
            if ($lock !== 'none' && ($bsLocksBefore[$sid][$unit] ?? 'none') !== $lock) {
                $lockedByRun[] = $unit . '=' . $lock;
            }
        }
    }
    bs_cleanup();
    @unlink($jar);
    $left = (int) Database::scalar('SELECT COUNT(*) FROM bookings WHERE contact_phone IN (:a, :b) OR contact_email = :e',
        ['a' => BS_PHONE_1, 'b' => BS_PHONE_2, 'e' => BS_EMAIL], 0);
    check('cleanup left no test booking behind', $left === 0, "left=$left");

    /* Each leftover the 16 Nov review found, checked BEFORE any heal so a
       regression in bs_cleanup() shows here; the heal afterwards keeps the
       shared database clean even when one of these fails. Checked before a
       schedule this run caused is dropped, so they run either way. */
    $sidsToCheck = array_values(array_unique(array_merge($bsSids, array_keys($bsTouched))));
    foreach ($sidsToCheck as $sid) {
        $count = bs_seat_count($sid);
        if ($count === null) {
            /* Gone, and its lock rows with it (ON DELETE CASCADE): another
               process deleted it meanwhile. Nothing can be left on it; said,
               not silently skipped. */
            echo "  NOTE  schedule #$sid was deleted by another process during the run — no lock row or seat count can be left on it\n";
            continue;
        }
        $stale = bs_stale_locks($sid);
        check("cleanup left no gender lock on an empty cabin of schedule #$sid", $stale === [],
            'locked during the run: ' . ($lockedByRun === [] ? 'none' : implode(', ', $lockedByRun))
            . '; stale now: ' . ($stale === [] ? 'none' : implode(', ', $stale)));
        $ownRows = array_values(array_diff(array_keys(bs_locks($sid)), array_keys($bsLocksBefore[$sid] ?? [])));
        $ownRows = array_values(array_intersect($ownRows, array_keys($bsTouched[$sid] ?? [])));
        check("  …and no lock row this run created on #$sid", $ownRows === [], $ownRows === [] ? '' : implode(', ', $ownRows));
        check("schedules.seats_booked on #$sid matches its live seats after cleanup", $count[0] === $count[1],
            'says ' . $count[0] . ', has ' . $count[1]);
        bs_heal_schedule($sid);
    }
    // A schedule row this run caused (search / seats create it lazily) goes too,
    // unless somebody else has booked on it meanwhile.
    foreach (Database::fetchAll('SELECT id, route_id FROM schedules WHERE travel_date = :d', ['d' => $date]) as $r) {
        if (isset($scheduleBefore[(int) $r['route_id']])) {
            continue;
        }
        $used = (int) Database::scalar('SELECT COUNT(*) FROM booking_legs WHERE schedule_id = :s', ['s' => (int) $r['id']], 0);
        if ($used === 0) {
            try { Database::delete('schedules', 'id = :i', ['i' => (int) $r['id']]); } catch (Throwable $e) {}
        }
    }
    $profiles = (int) Database::scalar('SELECT COUNT(*) FROM user_profiles WHERE phone IN (:a, :b)',
        ['a' => BS_PHONE_1, 'b' => BS_PHONE_2], 0);
    check('cleanup left no customer-master row (user_profiles) for the test phones', $profiles === 0, "left=$profiles");
    $logs = (int) Database::scalar('SELECT COUNT(*) FROM app_logs WHERE context LIKE :a OR context LIKE :b',
        ['a' => '%' . BS_PHONE_1 . '%', 'b' => '%' . BS_PHONE_2 . '%'], 0);
    foreach (array_unique($bsPnrs) as $p) {
        $logs += (int) Database::scalar('SELECT COUNT(*) FROM app_logs WHERE context LIKE :p', ['p' => '%"pnr":"' . $p . '"%'], 0);
    }
    check('cleanup left no app_logs line naming the test phones or PNRs', $logs === 0,
        "left=$logs; PNRs: " . (array_unique($bsPnrs) === [] ? 'none' : implode(', ', array_unique($bsPnrs))));
    $pnrLeft = [];
    foreach (array_unique($bsPnrs) as $p) {
        foreach (bs_pnr_rows($p) as [$sql, $params]) {
            $n = (int) Database::scalar('SELECT COUNT(*) FROM ' . $sql, $params, 0);
            if ($n > 0) {
                $pnrLeft[] = strtok($sql, ' ') . " $p: $n";
            }
        }
    }
    check('cleanup left no audit / automation / office-bell row for the run\'s PNRs', $pnrLeft === [],
        $pnrLeft === [] ? '' : implode('; ', $pnrLeft));
}

echo "\n---------- $pass passed / $fail failed ----------\n";
exit($fail === 0 ? 0 : 1);
