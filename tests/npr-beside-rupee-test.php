<?php
/**
 * =====================================================================
 *  NPR BESIDE THE RUPEE — the office reads back what the desk froze
 *
 *      php tests/npr-beside-rupee-test.php
 *
 *  Owner ask (26 Sep 2026, first of the counter follow-ups): a Nepal desk's
 *  sale already stores fx_currency / fx_rate / fx_total on the booking and
 *  local_currency / local_amount on the payment, but only counters.php and
 *  the ticket read them back. Show the NPR beside the rupee on booking-view,
 *  payments, accounting and the dashboard, and take the peg from Settings
 *  (npr_per_inr) instead of the literal 1.6 compiled into two pages.
 *  Never convert a stored figure — print the NPR frozen on that row, or
 *  nothing.
 *
 *  What this pins down:
 *
 *    1. CounterDesk::frozen() prints a frozen NPR figure and NOTHING for a
 *       rupee row. It never multiplies by today's peg.
 *    2. A sale cut at NPJ shows its frozen NPR on booking-view (quoted AND
 *       taken), on the payments list, in the day-book and on the dashboard
 *       tile — while every rupee figure is exactly what it was.
 *    3. Moving the peg in Settings moves the legacy-conversion note on
 *       Accounting and Analytics, and does NOT move the frozen figure.
 *    4. Neither page divides by the NPR_PER_INR constant any more.
 *
 *  The pages are rendered the way a browser would see them, through
 *  tests/render-admin.php in a child process, so a fatal on any of the
 *  four screens fails here rather than at the window.
 * =====================================================================
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

if (!defined('SHG_APP')) {
    define('SHG_APP', true);
}
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';

const NPRT_PHONE = '910000772';

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

/** Render an admin page as the first active admin would see it. */
$ini    = dirname(__DIR__) . '/.claude/php-dev.ini';
$render = static function (string $page, array $get = []) use ($ini): string {
    $cmd = escapeshellarg(PHP_BINARY)
         . (is_file($ini) ? ' -c ' . escapeshellarg($ini) : '')
         . ' ' . escapeshellarg(__DIR__ . '/render-admin.php')
         . ' ' . escapeshellarg($page);
    foreach ($get as $k => $v) {
        $cmd .= ' ' . escapeshellarg($k . '=' . $v);
    }
    return (string) shell_exec($cmd . ' 2>&1');
};

echo "\n=== NPR beside the rupee: the office reads back what the desk froze ===\n\n";

/* ---- 1. the helper never converts ---------------------------------- */
$npr3200 = CounterDesk::format(3200.0, 'NPR');
check('a frozen NPR row prints its NPR',
    CounterDesk::frozen(['fx_currency' => 'NPR', 'fx_total' => 3200]) === $npr3200, $npr3200);
check('a rupee row prints nothing (no peg is ever applied)',
    CounterDesk::frozen(['fx_currency' => null, 'fx_total' => null]) === ''
    && CounterDesk::frozen(['fx_currency' => 'INR', 'fx_total' => 2000]) === ''
    && CounterDesk::frozen(['fx_currency' => 'NPR', 'fx_total' => 0]) === ''
    && CounterDesk::frozen(null) === '');
check('the payment side reads its own columns',
    CounterDesk::frozen(['local_currency' => 'npr', 'local_amount' => '3200.00'], 'local_currency', 'local_amount') === $npr3200);
$cols = CounterDesk::frozenColumns();
check('frozenColumns() answers for both tables',
    is_bool($cols['bookings'] ?? null) && is_bool($cols['payments'] ?? null),
    'bookings=' . var_export($cols['bookings'], true) . ' payments=' . var_export($cols['payments'], true));

/* ---- 2. the two report pages no longer divide by a compiled-in peg ---- */
foreach (['accounting.php', 'analytics.php'] as $f) {
    $src = (string) file_get_contents(dirname(__DIR__) . '/admin/' . $f);
    check("$f divides by Settings npr_per_inr, not the NPR_PER_INR constant",
        preg_match('~/\s*("\s*\.\s*)?NPR_PER_INR~', $src) === 0
        && str_contains($src, "Settings::getFloat('npr_per_inr'"));
}
check('analytics no longer bakes the peg into a compile-time const',
    !str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/analytics.php'), 'const REV_INR'));

/* ---- 3. a real Nepal-desk sale, read back on every screen ------------ */
if (!CounterDesk::hasTable() || !$cols['bookings'] || !$cols['payments']) {
    echo "  \033[33mSKIP\033[0m  database/upgrade-2026-09-counter-desks.sql is not applied on this database — the render half is not run\n";
} else {
    $w     = bookingWindow();
    $D     = addDaysISO($w['from'], 23);
    $route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $aid   = (int) Database::scalar(
        "SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0
    );

    $cleanup = static function () use ($D): void {
        foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . NPRT_PHONE . "%'") as $r) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
        Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
        foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
            Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
        }
        Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
    };

    if ($route === null || $aid <= 0) {
        echo "  \033[33mSKIP\033[0m  no active sleeper route (or no admin) on this database — the render half is not run\n";
    } else {
        $rid       = (int) $route['id'];
        $profile   = Database::fetch('SELECT counter_code, counter_name FROM admin_profiles WHERE admin_id = :a', ['a' => $aid]);
        $hadRow    = $profile !== null;
        $savedCode = (string) ($profile['counter_code'] ?? '');
        $savedName = (string) ($profile['counter_name'] ?? '');
        $savedPeg  = Settings::getString('npr_per_inr', '1.6');

        $sitAt = static function (int $adminId, string $code, bool $hadRow): void {
            $name = (string) (CounterDesk::get($code)['name'] ?? $code);
            if ($hadRow) {
                Database::update('admin_profiles', ['counter_code' => $code, 'counter_name' => $name], 'admin_id = :a', ['a' => $adminId]);
            } else {
                Database::insert('admin_profiles', ['admin_id' => $adminId, 'counter_code' => $code, 'counter_name' => $name]);
            }
        };

        $cleanup();

        try {
            $sitAt($aid, 'NPJ', $hadRow);
            CounterDesk::flush();

            $sched = Seats::schedule($rid, $D);
            $b     = BookingService::counterSale($route, (int) $sched['id'], $D, ['L30'], [
                'name'          => 'NPR Readback Test',
                'phone'         => NPRT_PHONE . '0',
                'paymentMethod' => 'cash',
                'amount'        => 2000,
            ], $aid, 'counter');

            $row = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => (int) $b['id']]);
            $pay = Database::fetch('SELECT * FROM payments WHERE booking_id = :i ORDER BY id DESC LIMIT 1', ['i' => (int) $b['id']]);
            $pnr = (string) $row['pnr'];

            $inrText = inr((float) $row['total_amount']);                    // ₹2,000 — the book figure
            $nprText = CounterDesk::format((float) $row['fx_total'], 'NPR');  // what the desk froze
            check('the sale is confirmed today, in rupees, with its NPR frozen beside it',
                (string) $row['status'] === 'confirmed'
                && (string) $row['currency'] === 'INR'
                && (string) $row['fx_currency'] === 'NPR' && (float) $row['fx_total'] > 0
                && (string) $pay['local_currency'] === 'NPR' && (float) $pay['local_amount'] > 0,
                $pnr . ' ' . $inrText . ' / ' . $nprText);

            /* booking-view: quoted AND taken */
            $html = $render('booking-view.php', ['pnr' => $pnr]);
            check('booking-view still prints the rupee total', str_contains($html, $inrText));
            check('… the NPR quoted at the desk, as frozen', str_contains($html, 'Quoted at the desk') && str_contains($html, $nprText));
            check('… and the NPR the drawer took', str_contains($html, 'in the drawer') && substr_count($html, $nprText) >= 2,
                'occurrences=' . substr_count($html, $nprText));
            check('… naming the desk it was cut at', str_contains($html, 'Nepalgunj'));

            /* payments list */
            $html = $render('payments.php', ['tab' => 'all', 'q' => $pnr]);
            check('payments list: rupee row with its frozen NPR under it',
                str_contains($html, $pnr) && str_contains($html, $inrText) && str_contains($html, $nprText));
            check('payments KPIs: today’s NPR quoted beside the rupee', str_contains($html, 'quoted in NPR'));

            /* day-book */
            $html = $render('accounting.php');
            check('day-book: NPR quoted at a Nepal desk beside the rupee collected',
                str_contains($html, 'quoted in NPR') && str_contains($html, 'रू '));
            check('day-book names the peg from Settings', str_contains($html, 'Settings → npr_per_inr'));

            /* dashboard */
            $html = $render('index.php');
            check('dashboard tile: NPR quoted beside today’s rupee', str_contains($html, 'quoted in NPR') && str_contains($html, 'रू '));

            /* ---- 4. the peg moves the legacy note, never the frozen figure -- */
            Settings::set('npr_per_inr', '2.5', 'float');
            Settings::flush();
            $html = $render('analytics.php');
            check('analytics converts legacy NPR rows at the Settings peg, not a literal', str_contains($html, '1:2.5'));
            $html = $render('accounting.php');
            check('accounting quotes the same Settings peg', str_contains($html, '1:2.5'));
            $html    = $render('booking-view.php', ['pnr' => $pnr]);
            $wrong   = CounterDesk::format(round((float) $row['total_amount'] * 2.5), 'NPR');
            check('…and the frozen NPR on the sale did NOT move with the peg',
                str_contains($html, $nprText) && !str_contains($html, $wrong), $nprText . ' kept, ' . $wrong . ' absent');
        } finally {
            Settings::set('npr_per_inr', $savedPeg, 'float');
            Settings::flush();
            if ($hadRow) {
                Database::update('admin_profiles',
                    ['counter_code' => $savedCode !== '' ? $savedCode : null, 'counter_name' => $savedName],
                    'admin_id = :a', ['a' => $aid]);
            } else {
                Database::delete('admin_profiles', 'admin_id = :a', ['a' => $aid]);
            }
            CounterDesk::flush();
            $cleanup();
        }
    }
}

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
