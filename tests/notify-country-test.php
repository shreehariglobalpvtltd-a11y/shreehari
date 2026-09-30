<?php
/**
 * =====================================================================
 *  notify-country-test.php — the ticket goes to the right COUNTRY.
 *
 *  India and Nepal share the 10-digit mobile format, so a bare
 *  contact_phone is a valid number in BOTH. The notifier used to prepend
 *  one default code (91 = India) to every bare number, which meant a
 *  Nepali customer's ticket — name, seat, PNR — was WhatsApped to whoever
 *  owns that number in India. This suite proves the country is now
 *  captured and honoured at every layer instead of guessed:
 *
 *    A. helpers: resolvePhoneCountry() / countryDialCode() read a picker
 *       value or a +91/+977 prefix, and never invent a country from bare
 *       digits.
 *    B. Notify::whatsappNumberFor() resolves in trust order —
 *       stamped-on-booking → Nepali-citizenship ID → the account's
 *       country_code → configured default — so a Nepali number reaches
 *       +977, an Indian one +91, and an unknown one the default.
 *    C. the ONLINE customer path (BookingService::create) stamps
 *       contact_country_code from the picker, a typed +977 prefix, or the
 *       signed-in account, and the stored row then resolves correctly.
 *    D. the COUNTER path (BookingService::counterSale) stamps it too.
 *
 *  Creates real bookings on a far-future date and cleans up after itself.
 *    php -c .claude/php-dev.ini tests/notify-country-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/boarding.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';

/* A phone prefix nothing real uses, so cleanup is exact. */
const NCT_PREFIX  = '9812340';        // + 3 digits = 10-digit test numbers
const NCT_ACC_NP  = '9812349001';     // a Nepali account (country_code 977)
const NCT_ACC_IN  = '9812349002';     // an Indian account (country_code 91)

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Ticket delivery goes to the right country ===\n\n";

/* -----------------------------------------------------------------
 *  A. The helpers decide the country, never inventing one.
 * --------------------------------------------------------------- */
echo "-- A. resolvePhoneCountry / countryDialCode --\n";
check("picker 'NP' wins",                 resolvePhoneCountry('NP', '9812345678') === 'NP');
check("picker 'IN' wins",                 resolvePhoneCountry('IN', '9812345678') === 'IN');
check('typed +977 prefix → NP',           resolvePhoneCountry('', '+9779812345678') === 'NP');
check('typed 00977 prefix → NP',          resolvePhoneCountry('', '009779812345678') === 'NP');
check('typed +91 prefix → IN',            resolvePhoneCountry('', '+919812345678') === 'IN');
check('bare 10-digit stays unknown',      resolvePhoneCountry('', '9812345678') === '');
check('a 10-digit number opening 977 is NOT a country code', resolvePhoneCountry('', '9771234567') === '');
check('empty everything → unknown',       resolvePhoneCountry('', '') === '');
check("countryDialCode NP → 977",         countryDialCode('NP') === '977');
check("countryDialCode IN → 91",          countryDialCode('IN') === '91');
check("countryDialCode '' → ''",          countryDialCode('') === '');
check('countryDialCode maps a stored 977 back? no, only NP/IN', countryDialCode('977') === '');

/* -----------------------------------------------------------------
 *  B. Notify::whatsappNumberFor() resolves in trust order.
 * --------------------------------------------------------------- */
echo "\n-- B. Notify::whatsappNumberFor resolution order --\n";
// A Nepali account so the account-lookup fallback has something to find.
Database::run('DELETE FROM users WHERE phone IN (:a,:b)', ['a' => NCT_ACC_NP, 'b' => NCT_ACC_IN]);
Database::insert('users', ['phone' => NCT_ACC_NP, 'full_name' => 'NP Acct', 'role' => 'customer', 'country_code' => '977', 'phone_verified' => 1]);
Database::insert('users', ['phone' => NCT_ACC_IN, 'full_name' => 'IN Acct', 'role' => 'customer', 'country_code' => '91',  'phone_verified' => 1]);

$def = preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91';
$R = fn(array $b): string => Notify::whatsappNumberFor($b);
check('stamped 977 on booking → +977',    $R(['contact_phone' => '9812345678', 'id_type' => 'Passport', 'contact_country_code' => '977']) === '9779812345678');
check('stamped 91 on booking → +91',      $R(['contact_phone' => '9812345678', 'id_type' => 'Passport', 'contact_country_code' => '91'])  === '919812345678');
check('Nepali citizenship ID → +977',     $R(['contact_phone' => '9812345678', 'id_type' => 'Nepali Citizenship Card']) === '9779812345678');
check('Nepali ACCOUNT + Passport ID → +977 (the bug fix)',
                                          $R(['contact_phone' => NCT_ACC_NP, 'id_type' => 'Passport']) === '977' . NCT_ACC_NP);
check('Indian ACCOUNT → +91 (no regression)',
                                          $R(['contact_phone' => NCT_ACC_IN, 'id_type' => 'Passport']) === '91' . NCT_ACC_IN);
check('no signal at all → configured default',
                                          $R(['contact_phone' => '9700000123', 'id_type' => 'Passport']) === $def . '9700000123');
check('stamped code OUTRANKS a conflicting Nepali ID',
                                          $R(['contact_phone' => '9812345678', 'id_type' => 'Nepali Citizenship Card', 'contact_country_code' => '91']) === '919812345678');
// The all-zeros walk-in placeholder is stopped by usablePhone() BEFORE any
// send (bookingConfirmed/resend/retry all gate on it), so it never reaches
// whatsappNumberFor — that is the guard that stops the 21211 burn.
check('placeholder 0000000000 is gated by usablePhone (never sent)',
                                          Notify::usablePhone('0000000000') === '' && Notify::usablePhone('9812345678') !== '');

/* -----------------------------------------------------------------
 *  Booking scaffolding for C and D.
 * --------------------------------------------------------------- */
$w = bookingWindow(); $D = addDaysISO($w['from'], 26);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1")
      ?? Database::fetch("SELECT * FROM routes WHERE is_active=1 ORDER BY id LIMIT 1");
if ($route === null) { echo "  SKIP  no active route\n"; }
$rid = (int) ($route['id'] ?? 0);

$cleanupBookings = function () use ($D): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . NCT_PREFIX . "%'") as $r) {
        try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => (int) $s['id']]); } catch (Throwable $e) {}
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};

/** The dial number the notifier would use for a stored booking, fetched fresh. */
$resolvedFor = static function (string $pnr): string {
    $row = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $pnr]);
    return $row === null ? '(no row)' : Notify::whatsappNumberFor($row);
};

if ($route !== null) {
    $cleanupBookings();
    $sid  = (int) Seats::schedule($rid, $D)['id'];
    $free = Seats::availability($rid, $D)['available'] ?? [];

    // Female passengers so no berth is refused by the women-only reservation
    // (front seats like L1 are women-reserved and reject a male — the seat trap
    // that trips other suites). The country logic is gender-agnostic.
    $req = function (string $seat, string $phone, array $contactExtra = []) use ($rid, $D): array {
        return [
            'routeId' => $rid, 'travelDate' => $D, 'seats' => [$seat],
            'passengers' => [['seat' => $seat, 'name' => 'Country Pax', 'age' => 30, 'gender' => 'Female']],
            'contact' => array_merge(['phone' => $phone], $contactExtra),
            'bookingMode' => 'sharing', 'paymentMethod' => 'upi', 'isCod' => false,
            'boarding' => '', 'referralCode' => '',
        ];
    };
    // Create a booking, turning any rejection into a reported failure instead of
    // an uncaught throw that the global handler would turn into a fatal.
    $mkCreate = function (array $request) : ?array {
        try { return BookingService::create($request); }
        catch (Throwable $e) { check('create() unexpectedly rejected: ' . $e->getMessage(), false); return null; }
    };

    echo "\n-- C. Online customer path (BookingService::create) stamps the country --\n";
    if (count($free) < 4) {
        check('enough free seats for the create() cases', false, 'have ' . count($free));
    } else {
        // 1. Explicit picker NP → 977 stored, resolves +977.
        $b = $mkCreate($req($free[0], NCT_PREFIX . '011', ['country' => 'NP']));
        if ($b) {
            $row = Database::fetch('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('picker NP stamps contact_country_code = 977', (string) ($row['contact_country_code'] ?? '') === '977');
            check('  → stored booking resolves to +977', $resolvedFor($b['pnr']) === '977' . NCT_PREFIX . '011');
        }

        // 2. Explicit picker IN → 91 stored.
        $b = $mkCreate($req($free[1], NCT_PREFIX . '012', ['country' => 'IN']));
        if ($b) {
            $row = Database::fetch('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('picker IN stamps contact_country_code = 91', (string) ($row['contact_country_code'] ?? '') === '91');
        }

        // 3. A typed +977 prefix (no picker) is inferred → 977.
        $b = $mkCreate($req($free[2], '+977' . NCT_PREFIX . '013'));
        if ($b) {
            $row = Database::fetch('SELECT contact_phone, contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('typed +977 is inferred → 977', (string) ($row['contact_country_code'] ?? '') === '977');
            check('  → contact_phone still stored as the bare 10-digit identity',
                  (string) ($row['contact_phone'] ?? '') === NCT_PREFIX . '013');
            check('  → stored booking resolves to +977', $resolvedFor($b['pnr']) === '977' . NCT_PREFIX . '013');
        }

        // 4. No country signal at all → NULL, resolves to the configured default.
        $b = $mkCreate($req($free[3], NCT_PREFIX . '014'));
        if ($b) {
            $row = Database::fetch('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('no signal → contact_country_code left NULL', $row['contact_country_code'] === null);
            check('  → falls back to the default country', $resolvedFor($b['pnr']) === $def . NCT_PREFIX . '014');
        }
    }

    echo "\n-- D. Counter path (BookingService::counterSale) stamps the country --\n";
    // A throwaway counter agent.
    $agentId = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => 'nct-agent'], 0);
    if ($agentId === 0) {
        $agentId = (int) Database::insert('admins', [
            'username' => 'nct-agent', 'password_hash' => password_hash('Nct@12345', PASSWORD_BCRYPT),
            'full_name' => 'Country Test Agent', 'role' => 'agent', 'is_active' => 1, 'must_change_pw' => 0,
        ]);
    } else {
        Database::update('admins', ['role' => 'agent', 'is_active' => 1], 'id = :i', ['i' => $agentId]);
    }
    $free2 = Seats::availability($rid, $D)['available'] ?? [];
    $mkCounter = function (string $seat, array $data) use ($route, $sid, $D, $agentId): ?array {
        try {
            return BookingService::counterSale($route, $sid, $D, [$seat],
                array_merge(['gender' => 'Female', 'amount' => 2000.0, 'paymentMethod' => 'cash'], $data),
                $agentId, 'agent');
        } catch (Throwable $e) { check('counterSale unexpectedly rejected: ' . $e->getMessage(), false); return null; }
    };
    $npPnr = '';
    if (count($free2) < 2) {
        check('enough free seats for the counterSale cases', false, 'have ' . count($free2));
    } else {
        $b = $mkCounter($free2[0], ['name' => 'Counter NP', 'phone' => NCT_PREFIX . '021', 'country' => 'NP']);
        if ($b) {
            $npPnr = (string) $b['pnr'];
            $row = Database::fetch('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('counter picker NP stamps 977', (string) ($row['contact_country_code'] ?? '') === '977');
            check('  → resolves to +977', $resolvedFor($b['pnr']) === '977' . NCT_PREFIX . '021');
        }

        $b = $mkCounter($free2[1], ['name' => 'Counter typed', 'phone' => '00977' . NCT_PREFIX . '022']);
        if ($b) {
            $row = Database::fetch('SELECT contact_country_code FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
            check('counter typed 00977 is inferred → 977', (string) ($row['contact_country_code'] ?? '') === '977');
        }
    }

    echo "\n-- E. Confirmation actually sends to +977 and records the outcome --\n";
    if ($npPnr !== '') {
        $full = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $npPnr]);
        $bid  = (int) ($full['id'] ?? 0);
        // Make sure the customer WhatsApp is on, and start from a clean slate.
        Settings::set('whatsapp_notify_customer', '1', 'bool', 'notify');
        Settings::flush();
        Database::run('DELETE FROM message_logs WHERE booking_id = :b', ['b' => $bid]);

        Notify::bookingConfirmed($full);   // fires exactly as booking.approved does

        // Locally the driver is click_to_chat, so the row lands as 'failed' with a
        // click-to-chat link — but the TO NUMBER is the whole point: it must be the
        // Nepali +977 number, not a +91 stranger. message_logs is also the proof
        // that a miss is now recorded (it used to sit at 0 rows) and recoverable.
        $waRow = Database::fetch(
            "SELECT to_number, status FROM message_logs
              WHERE booking_id = :b AND channel = 'whatsapp'
                AND (purpose IS NULL OR purpose = 'ticket')
              ORDER BY id DESC LIMIT 1",
            ['b' => $bid]
        );
        check('bookingConfirmed logged a WhatsApp attempt', $waRow !== null,
              $waRow ? ('status=' . $waRow['status']) : 'no row');
        check('  → and it is addressed to the +977 number, not +91',
              $waRow !== null && (string) $waRow['to_number'] === '977' . NCT_PREFIX . '021',
              $waRow ? ('to=' . $waRow['to_number']) : '');

        Database::run('DELETE FROM message_logs WHERE booking_id = :b', ['b' => $bid]);
    } else {
        check('a confirmed Nepali booking was available for the send check', false);
    }

    $cleanupBookings();
    if ($agentId > 0) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE sold_by_admin_id = :a', ['a' => $agentId]) as $r) {
            try { Database::delete('agent_ledger', 'booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
    }
}

/* Clean up the two test accounts. */
Database::run('DELETE FROM users WHERE phone IN (:a,:b)', ['a' => NCT_ACC_NP, 'b' => NCT_ACC_IN]);

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
