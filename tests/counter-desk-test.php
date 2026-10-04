<?php
/**
 * =====================================================================
 *  COUNTER DESKS — a window is a place, with its own money
 *
 *      php tests/counter-desk-test.php
 *
 *  Owner ask (26 Sep 2026): "sabai ko lagi alag alag add garna milos jati
 *  pani — sabko hisab kitab admin le herna milos, chalani ma ni chuttinu
 *  paryo" and, for Nepalgunj, "NPR ma bech, dubai record".
 *
 *  What this pins down:
 *
 *    1. A desk knows its country and its money. NPJ is Nepal and collects
 *       NPR; SRT is India and collects rupees. Rupaidiha is the INDIAN
 *       side of the border and must NOT be a Nepal desk — the one that is
 *       easy to get wrong on a map.
 *    2. The rate is frozen per desk: a desk may pin its own, otherwise the
 *       company peg answers, and a rupee desk is 1.0 no matter what anyone
 *       sets the peg to. (A peg leaking into an Indian desk would silently
 *       multiply every Gujarat fare by 1.6.)
 *    3. The drawer counts the notes that exist in that country. Nepal has a
 *       1000 note and no 200; India the reverse.
 *    4. A sale FREEZES the desk on the booking, and at a Nepal desk freezes
 *       what the customer was quoted and what the drawer took — while
 *       total_amount, payments.amount and currency stay the INR the whole
 *       ledger is written in. This is the heart of "dubai record".
 *    5. A desk nobody has assigned stamps NOTHING. An invented town on a
 *       ticket is worse than a blank one.
 *    6. The old settings row stays a true mirror of the table, and is never
 *       blanked — everything still reading it keeps working.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const CDSK_PHONE = '910000771';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

echo "\n=== Counter desks: place, country, money ===\n\n";

if (!CounterDesk::hasTable()) {
    echo "  \033[33mSKIP\033[0m  database/upgrade-2026-10-04-counter-desks.sql is not applied on this database\n\n";
    exit(0);
}

$savedPeg    = Settings::getString('npr_per_inr', '1.6');
$savedMirror = Settings::getString('counter_locations', '');

/* ---- 1. a desk knows where it is and what it takes ---------------- */

$npj = CounterDesk::get('NPJ');
$srt = CounterDesk::get('SRT');
check('NPJ is a Nepal desk that collects NPR',
    $npj !== null && $npj['country'] === 'NP' && $npj['currency'] === 'NPR',
    $npj === null ? 'missing' : $npj['country'] . '/' . $npj['currency']);
check('SRT is an India desk that collects rupees',
    $srt !== null && $srt['country'] === 'IN' && $srt['currency'] === 'INR',
    $srt === null ? 'missing' : $srt['country'] . '/' . $srt['currency']);
check('Rupaidiha is the INDIAN side of the border, not a Nepal desk',
    (CounterDesk::get('RPD')['country'] ?? '') === 'IN' && CounterDesk::currency('RPD') === 'INR');
check('Rajkot exists as its own desk', CounterDesk::get('RJT') !== null);
/* 4 Oct 2026: SIDE, MONEY and COMPANY are three separate questions. */
check('Rupaidiha SELLS the return run (side nepal) though it takes rupees',
    CounterDesk::side('RPD') === 'nepal' && Settings::isNepalDesk('RPD') && CounterDesk::currency('RPD') === 'INR');
check('...and its tickets are the India company\'s', CounterDesk::entity('RPD') === 'IN');
check('Nepalgunj issues the Nepal company\'s ticket', CounterDesk::entity('NPJ') === 'NP');
check('a Gujarat desk is the India side and the India company',
    CounterDesk::side('SRT') === 'india' && !Settings::isNepalDesk('SRT') && CounterDesk::entity('SRT') === 'IN');
check('an unknown / blank desk (an online sale) is the India company', CounterDesk::entity('') === 'IN' && CounterDesk::entity('ZZZ') === 'IN');
check('Settings::counterLocations() answers from the table', isset(Settings::counterLocations()['NPJ']));
check('Settings::counterLabel() still prints "Name (CODE)"', str_ends_with(Settings::counterLabel('NPJ'), '(NPJ)'));
check('the flag in front of a place follows its country',
    CounterDesk::flag('NPJ') === '🇳🇵' && CounterDesk::flag('SRT') === '🇮🇳');
check('an unknown code is not invented into a desk', CounterDesk::get('ZZZ') === null);

/* ---- 2. the rate ---------------------------------------------------- */

Settings::set('npr_per_inr', '1.6', 'float', 'company', true);
Settings::flush();
CounterDesk::flush();

$q = CounterDesk::convert(2199.0, 'NPJ');
check('₹2,199 at Nepalgunj is NPR 3,518 at the company peg',
    $q['currency'] === 'NPR' && abs($q['rate'] - 1.6) < 0.0001 && abs($q['amount'] - 3518.0) < 0.01,
    $q['currency'] . ' ' . $q['amount'] . ' @ ' . $q['rate']);
check('the NPR figure is a whole note, not paisa', fmod($q['amount'], 1.0) === 0.0);
check('the rupee it came from is kept beside it', abs($q['inr'] - 2199.0) < 0.001);

$srtQ = CounterDesk::convert(2199.0, 'SRT');
check('a rupee desk converts nothing and pegs nothing',
    $srtQ['currency'] === 'INR' && $srtQ['rate'] === 1.0 && abs($srtQ['amount'] - 2199.0) < 0.001);

Settings::set('npr_per_inr', '2.5', 'float', 'company', true);
Settings::flush();
CounterDesk::flush();
check('moving the company peg moves a Nepal desk', abs(CounterDesk::rate('NPJ') - 2.5) < 0.0001);
check('…and never touches an Indian desk', CounterDesk::rate('SRT') === 1.0);

Database::update('counter_locations', ['fx_rate' => 1.75], 'code = :c', ['c' => 'NPJ']);
CounterDesk::flush();
check('a desk that pins its own rate wins over the company peg',
    abs(CounterDesk::rate('NPJ') - 1.75) < 0.0001, 'rate=' . CounterDesk::rate('NPJ'));
Database::update('counter_locations', ['fx_rate' => null], 'code = :c', ['c' => 'NPJ']);
Settings::set('npr_per_inr', '1.6', 'float', 'company', true);
Settings::flush();
CounterDesk::flush();

/* ---- 3. the notes in the drawer ------------------------------------ */

$npjD = CounterDesk::denominations('NPJ');
$srtD = CounterDesk::denominations('SRT');
check('the Nepalgunj pad has the 1000 note Nepal actually circulates', in_array(1000, $npjD, true));
check('…and no 200, which Nepal does not have', !in_array(200, $npjD, true));
check('the Surat pad keeps the Indian 200 and has no 1000',
    in_array(200, $srtD, true) && !in_array(1000, $srtD, true));

/* ---- 4. a real sale freezes the desk and the money ----------------- */

$w     = bookingWindow();
$D     = addDaysISO($w['from'], 27);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$aid   = (int) Database::scalar(
    "SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0
);

$cleanup = static function () use ($D): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . CDSK_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};

if ($route === null || $aid <= 0) {
    echo "  \033[33mSKIP\033[0m  no active sleeper route (or no admin) on this database — the sale half is not run\n";
} else {
    $rid       = (int) $route['id'];
    $profile   = Database::fetch('SELECT counter_code, counter_name FROM admin_profiles WHERE admin_id = :a', ['a' => $aid]);
    $hadRow    = $profile !== null;
    $savedCode = (string) ($profile['counter_code'] ?? '');
    $savedName = (string) ($profile['counter_name'] ?? '');

    $sitAt = static function (int $adminId, string $code, bool $hadRow): void {
        $name = (string) (CounterDesk::get($code)['name'] ?? $code);
        if ($hadRow) {
            Database::update('admin_profiles', ['counter_code' => $code, 'counter_name' => $name], 'admin_id = :a', ['a' => $adminId]);
        } else {
            Database::insert('admin_profiles', ['admin_id' => $adminId, 'counter_code' => $code, 'counter_name' => $name]);
        }
    };

    $sell = static function (array $seats) use ($route, $rid, $D, $aid): array {
        $sched = Seats::schedule($rid, $D);
        return BookingService::counterSale($route, (int) $sched['id'], $D, $seats, [
            'name'          => 'Desk Test',
            'phone'         => CDSK_PHONE . '0',
            'paymentMethod' => 'cash',
            'amount'        => 2000,
        ], $aid, 'counter');
    };

    $cleanup();

    try {
        /* --- the Nepal window ------------------------------------- */
        $sitAt($aid, 'NPJ', $hadRow);
        CounterDesk::flush();
        check('a clerk signed in at Nepalgunj is read as NPJ', CounterDesk::codeForSale($aid) === 'NPJ');

        $b = $sell(['L30']);
        $row = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]);
        $pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :i', ['i' => (int) $b['id']]);

        check('the sale is stamped with the desk that cut it',
            (string) $row['counter_code'] === 'NPJ', 'counter_code=' . (string) $row['counter_code']);
        check('...and with the company that issued it, on the booking and the payment',
            (string) $row['entity'] === 'NP' && (string) $pay['entity'] === 'NP',
            'booking=' . (string) $row['entity'] . ' payment=' . (string) $pay['entity']);

        /* The ticket reads the FROZEN desk, not the credited seller's profile. */
        $iss = Ticket::issuedBy($row + ['agent_counter' => 'Surat', 'agent_counter_code' => 'SRT', 'agent_role' => 'counter']);
        check('the ticket names the desk frozen on the sale, not the seller profile',
            $iss['locCode'] === 'NPJ' && str_contains($iss['location'], '(NPJ)'), $iss['location']);
        check('the ticket prints the NPR frozen on the sale', abs(Ticket::frozenNpr($row) - 3200.0) < 0.01);

        $refusedUpi = false;
        try {
            $sched = Seats::schedule($rid, $D);
            BookingService::counterSale($route, (int) $sched['id'], $D, ['L33'], [
                'name' => 'Desk Test', 'phone' => CDSK_PHONE . '0', 'paymentMethod' => 'upi', 'amount' => 2000,
            ], $aid, 'counter');
        } catch (Throwable $e) {
            $refusedUpi = str_contains($e->getMessage(), 'does not take UPI');
        }
        check('the register itself refuses UPI at a desk not ticked for it', $refusedUpi);
        check('the books still say rupees — total_amount and currency are untouched',
            (string) $row['currency'] === 'INR' && abs((float) $row['total_amount'] - 2000.0) < 0.01,
            $row['currency'] . ' ' . $row['total_amount']);
        check('what the customer was quoted is frozen in NPR',
            (string) $row['fx_currency'] === 'NPR'
            && abs((float) $row['fx_rate'] - 1.6) < 0.0001
            && abs((float) $row['fx_total'] - 3200.0) < 0.01,
            $row['fx_currency'] . ' ' . $row['fx_total'] . ' @ ' . $row['fx_rate']);
        check('the payment row keeps the rupee the ledger sums',
            abs((float) $pay['amount'] - 2000.0) < 0.01 && (string) $pay['currency'] === 'INR');
        check('…and beside it the NPR the drawer actually took',
            (string) $pay['local_currency'] === 'NPR'
            && abs((float) $pay['local_amount'] - 3200.0) < 0.01
            && abs((float) $pay['fx_rate'] - 1.6) < 0.0001,
            $pay['local_currency'] . ' ' . $pay['local_amount']);

        /* --- the same clerk, moved to Surat ----------------------- */
        $sitAt($aid, 'SRT', true);
        CounterDesk::flush();
        $b2   = $sell(['L31']);
        $row2 = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b2['id']]);
        $pay2 = Database::fetch('SELECT * FROM payments WHERE booking_id = :i', ['i' => (int) $b2['id']]);
        check('a rupee desk stamps the place and no exchange at all',
            (string) $row2['counter_code'] === 'SRT' && $row2['fx_currency'] === null && $row2['fx_total'] === null);
        check('...and the India company', (string) $row2['entity'] === 'IN' && Ticket::frozenNpr($row2) === 0.0);
        check('…and its payment row carries no local money either', $pay2['local_currency'] === null);

        check('the Nepalgunj ticket did NOT move town when the clerk did',
            (string) (Database::fetch('SELECT counter_code FROM bookings WHERE id = :i', ['i' => (int) $b['id']])['counter_code'] ?? '') === 'NPJ');

        /* --- a clerk with no desk --------------------------------- */
        Database::update('admin_profiles', ['counter_code' => null, 'counter_name' => ''], 'admin_id = :a', ['a' => $aid]);
        CounterDesk::flush();
        check('a clerk with no desk resolves to nothing', CounterDesk::codeForSale($aid) === null);
        $b3   = $sell(['L32']);
        $row3 = Database::fetch('SELECT counter_code FROM bookings WHERE id = :i', ['i' => (int) $b3['id']]);
        check('…and their sale invents no town', $row3['counter_code'] === null);
    } catch (Throwable $e) {
        check('the sale half ran without throwing', false, $e->getMessage());
    } finally {
        $cleanup();
        if ($hadRow) {
            Database::update('admin_profiles',
                ['counter_code' => $savedCode !== '' ? $savedCode : null, 'counter_name' => $savedName],
                'admin_id = :a', ['a' => $aid]);
        } else {
            Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]);
        }
        CounterDesk::flush();
    }
}

/* ---- 5. the settings mirror ---------------------------------------- */

CounterDesk::save([
    'code' => 'ZTEST', 'name' => 'Test Desk — delete me', 'country' => 'NP',
    'currency' => 'NPR', 'is_active' => 1, 'sort_order' => 9000,
]);
check('a new desk can be added and reads back', (CounterDesk::get('ZTEST')['currency'] ?? '') === 'NPR');
check('the old settings row is kept in step',
    str_contains(Settings::getString('counter_locations', ''), 'ZTEST|Test Desk'));

CounterDesk::deactivate('ZTEST');
check('closing a desk takes it off the list offered to new staff',
    !isset(CounterDesk::options(true)['ZTEST']));
check('…but the desk itself is never deleted, so old tickets keep their place',
    CounterDesk::get('ZTEST') !== null);
check('the mirror is never left blank', trim(Settings::getString('counter_locations', '')) !== '');

Database::delete('counter_locations', 'code = :c', ['c' => 'ZTEST']);
CounterDesk::flush();
CounterDesk::syncSettingsMirror();

Settings::set('npr_per_inr', $savedPeg, 'float', 'company', true);
if (trim($savedMirror) !== '') {
    Settings::set('counter_locations', $savedMirror, 'string', 'company');
}
Settings::flush();
CounterDesk::flush();

/* ---- 5. the run a desk sells -------------------------------------- */

check('Nepalgunj opens on the RETURN run - its customers board in Rupaidiha and travel into India',
    CounterDesk::direction('NPJ') === 'toIndia', CounterDesk::direction('NPJ') ?: '(none)');
check('...and the counter search opens on Rupaidiha, the town they board at',
    CounterDesk::defaultFrom('NPJ') === 'Rupaidiha', CounterDesk::defaultFrom('NPJ') ?: '(none)');
/* "Nepalgunj ticket katne matra ho, chadne Rupaidiha nai ho" (owner, 26 Sep).
   If this ever goes red, somebody activated a run that BOARDS at Nepalgunj —
   which may be a real decision one day, but it must be a decision, not a
   side effect of opening a desk there. */
check('no live run boards at Nepalgunj - the desk sells, Rupaidiha boards',
    !Database::exists("SELECT 1 FROM routes WHERE is_active = 1 AND from_city LIKE :s",
        ['s' => '%Nepalgunj%']));
check('a Gujarat desk is left on Auto and sells both runs',
    CounterDesk::direction('SRT') === '' && CounterDesk::direction('MSA') === '');
check('an unknown desk has no direction of its own', CounterDesk::direction('ZZZ') === '');

/* ---- 5a. a desk with no bank account takes cash ------------------- */

check('Nepalgunj takes cash and eSewa only', CounterDesk::allowedMethods('NPJ') === ['cash', 'esewa'],
    implode(',', CounterDesk::allowedMethods('NPJ') ?? ['(all)']));
check('...so the register refuses UPI there', !CounterDesk::allowsMethod('NPJ', 'upi'));
check('...and takes cash', CounterDesk::allowsMethod('NPJ', 'cash'));
check('an Indian desk has no rule and takes everything',
    CounterDesk::allowedMethods('SRT') === null
    && CounterDesk::allowsMethod('SRT', 'upi') && CounterDesk::allowsMethod('SRT', 'cash'));

/* ---- 5b. the Nepalgunj drawer counts Nepali notes ------------------ */

require_once INCLUDE_PATH . '/countershift.php';

$npr = CounterShift::denominationsFor('NPR');
check('the pad offered for an NPR drawer is the Nepali one',
    in_array(1000, $npr, true) && !in_array(200, $npr, true));
check('...and INR keeps the Indian one',
    in_array(200, CounterShift::denominationsFor('INR'), true));
check('two 1000 notes count as 2000 in Nepal',
    abs(CounterShift::sumDenominations([1000 => 2], 'NPR') - 2000.0) < 0.001);

$refused = false;
try { CounterShift::sumDenominations([200 => 1], 'NPR'); }
catch (Throwable $e) { $refused = str_contains($e->getMessage(), '200'); }
check('a 200 note is refused on a Nepali pad - Nepal does not have one', $refused);
check('...and accepted on an Indian one',
    abs(CounterShift::sumDenominations([200 => 1], 'INR') - 200.0) < 0.001);

$aidS = (int) Database::scalar(
    "SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0
);
if ($aidS > 0 && Database::fetch("SHOW COLUMNS FROM counter_shifts LIKE 'counter_code'") !== null) {
    $wasRow  = Database::fetch('SELECT counter_code FROM admin_profiles WHERE admin_id = :a', ['a' => $aidS]);
    $openNow = CounterShift::current($aidS);
    if ($openNow !== null) {
        Database::update('counter_shifts', ['is_open' => null, 'closed_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $openNow['id']]);
    }
    if ($wasRow !== null) {
        Database::update('admin_profiles', ['counter_code' => 'NPJ', 'counter_name' => 'Nepalgunj - Bus Park'], 'admin_id = :a', ['a' => $aidS]);
    } else {
        Database::insert('admin_profiles', ['admin_id' => $aidS, 'counter_code' => 'NPJ', 'counter_name' => 'Nepalgunj - Bus Park']);
    }
    CounterDesk::flush();

    try {
        $sh = CounterShift::open($aidS, 500.0, 'desk test');
        check('a drawer opened at Nepalgunj is stamped with the desk',
            (string) ($sh['counter_code'] ?? '') === 'NPJ', 'code=' . (string) ($sh['counter_code'] ?? ''));
        check('...and counts NPR', CounterShift::currencyOf($sh) === 'NPR', 'cur=' . CounterShift::currencyOf($sh));
        check('the cashier is offered the 1000 note at that drawer',
            in_array(1000, CounterShift::denominationsFor(CounterShift::currencyOf($sh)), true));
        Database::delete('counter_shifts', 'id = :i', ['i' => (int) $sh['id']]);
    } catch (Throwable $e) {
        check('the Nepalgunj drawer opened without throwing', false, $e->getMessage());
    } finally {
        if ($wasRow !== null) {
            Database::update('admin_profiles',
                ['counter_code' => ($wasRow['counter_code'] ?? '') !== '' ? $wasRow['counter_code'] : null],
                'admin_id = :a', ['a' => $aidS]);
        } else {
            Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aidS]);
        }
        if ($openNow !== null) {
            Database::update('counter_shifts', ['is_open' => 1, 'closed_at' => null], 'id = :i', ['i' => (int) $openNow['id']]);
        }
        CounterDesk::flush();
    }
} else {
    echo "  \033[33mSKIP\033[0m  counter_shifts has no desk columns on this database\n";
}

/* ---- 6. the doors a window must not hold open ---------------------- */

$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

$perms        = (new ReflectionClass('Auth'))->getConstant('ROLE_PERMISSIONS');
$counterPerms = $perms['counter'] ?? [];
check('a counter window holds no company export', !in_array('reports.export', $counterPerms, true));
check('...no agent commission ledger', !in_array('commissions.view', $counterPerms, true));
check('...no coupon editing', !in_array('coupons.edit', $counterPerms, true));
check('...and no revenue dashboard', !in_array('dashboard.view', $counterPerms, true));

check('the company day-book asks for reports.view on top of payments.view',
    str_contains($src('admin/accounting.php'), "Auth::requireAdmin('reports.view')"));
check('...which a counter window does not hold', !in_array('reports.view', $counterPerms, true));
check('the passenger CSV refuses anyone unscoped without reports.export',
    str_contains($src('admin/export.php'), "Auth::bookingScopeAdminId() === null && !Auth::can('reports.export')"));
check('offers checks the permission its own refusal text promises',
    str_contains($src('admin/offers.php'), "Auth::can('coupons.edit')"));
check('every download leaves an audit row',
    str_contains($src('admin/export.php'), "Logger::audit('bookings.export'"));
check('a manager and an accountant can still export',
    in_array('reports.export', $perms['manager'] ?? [], true) && in_array('reports.export', $perms['accountant'] ?? [], true));
check('Counters & collection is gated on reports.view',
    str_contains($src('admin/counters.php'), "admin_boot('reports.view')"));
$sec = $src('includes/security.php');
check('proxy headers are believed only from TRUSTED_PROXIES',
    str_contains($sec, "defined('TRUSTED_PROXIES')") && str_contains($sec, 'in_array($remote, $trusted, true)'));
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.99';
check('...so a visitor cannot pick the IP the rate limit sees',
    defined('TRUSTED_PROXIES') || Security::clientIp() === '198.51.100.7', Security::clientIp());
unset($_SERVER['HTTP_X_FORWARDED_FOR']);
check('one secret-key rule: the push private key and the SMTP password are secrets',
    Settings::isSecretKey('push_vapid_private') && Settings::isSecretKey('smtp_pass')
    && Settings::isSecretKey('whatsapp_api_token') && Settings::isSecretKey('anthropic_api_key'));
check('...and public values are not', !Settings::isSecretKey('push_vapid_public')
    && !Settings::isSecretKey('whatsapp_api_version') && !Settings::isSecretKey('passenger_docs_on')
    && !Settings::isSecretKey('company_phone'));
check('the Settings form uses that rule', str_contains($src('admin/settings.php'), 'Settings::isSecretKey($key)'));
check('a temporary password is refused by the API too',
    str_contains($src('includes/auth.php'), 'Change your temporary password first.'));
check('a commission rate change in the Agent Panel needs a reason',
    str_contains($src('admin/agent.php'), 'Give a reason for the commission rate change.')
    && str_contains($src('admin/agent.php'), 'Give a reason for the tier rate change.'));
check('the app estimates NPR with the office rate, not a typed-in 1.6',
    str_contains($src('assets/js/02-config.js'), 'window.SHG_BOOT.npr') && str_contains($src('index.php'), "\$boot['npr']"));
check('desk isolation is wired into the register, payments and passengers',
    str_contains($src('includes/auth.php'), 'function deskScopeCode')
    && str_contains($src('admin/bookings.php'), 'Auth::deskScopeCode()')
    && str_contains($src('admin/payments.php'), 'Auth::deskScopeCode()')
    && str_contains($src('admin/passengers.php'), 'Auth::deskScopeCode()'));
check('...it ships OFF', Settings::getBool('counter_desk_isolation', false) === false);
check('...and the bus manifest stays shared', !str_contains($src('admin/manifest.php'), 'deskScopeCode'));
check('Accounting shows the by-company split read-only', str_contains($src('admin/accounting.php'), 'By company'));
check('the staff create form sets the desk in the same step',
    str_contains($src('admin/staff.php'), 'name="counter_code"'));
check('Counters & collection is on the office menu',
    str_contains($src('admin/_guard.php'), "'counters.php'"));
check('the register can be filtered by desk',
    str_contains($src('admin/bookings.php'), "ctrFlt"));

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");

exit($FAIL === 0 ? 0 : 1);
