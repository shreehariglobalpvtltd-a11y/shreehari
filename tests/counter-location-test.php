<?php
/**
 * =====================================================================
 *  COUNTER LOCATIONS — the desk a ticket was cut at
 *
 *      php tests/counter-location-test.php
 *
 *  Owner ask (24 Sep 2026): "counter mode lai location haru ni add garna
 *  milos, like NPJ … company ko name ko tala location lekhne thau …
 *  ticket [ma] by name ra counter ko location hos, dekhine gari."
 *
 *  What this pins down, in the order a passenger meets it:
 *
 *    1. The LIST parses the way the settings box is documented —
 *       "CODE|Name" per line, blank lines skipped, lower case accepted,
 *       a pipe-less line usable, a duplicate code keeping the first
 *       spelling. The office edits this by hand, so a stray space or a
 *       blank line must not silently drop a desk.
 *    2. counterLabel() degrades instead of printing stray brackets. A
 *       desk with a name and no code, a code the list has never heard
 *       of, and nothing at all are all real states.
 *    3. issuedBy() reads the SELLER's own desk, and — the part that
 *       matters — an ONLINE sale gets NO location. Printing the head
 *       office on a ticket the customer issued for themselves would
 *       claim a counter cut it.
 *    4. The PNG and the PDF actually render with a desk set. A layout
 *       change that throws only when counter_code is non-empty would
 *       otherwise reach a passenger as a missing ticket.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
/* bootstrap.php brings up Settings and the database, not every class —
   Ticket and AgentWallet are required where they are used. */
require_once __DIR__ . '/../includes/ticket.php';
require_once __DIR__ . '/../includes/agentwallet.php';

$PASS = 0;
$FAIL = 0;

function check(string $label, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  {$label}" . ($extra !== '' ? " — {$extra}" : '') . "\n";
    }
}

echo "\n=== Counter locations ===\n\n";

/* ---- 1. the list -------------------------------------------------- */

$saved = Settings::getString('counter_locations', '');

Settings::set(
    'counter_locations',
    "NPJ|Nepalgunj — Bus Park\n\n  msa | Mehsana — Head Office  \nBirgunj\nNPJ|Nepalgunj SECOND SPELLING\n",
    'string',
    'company'
);
Settings::flush();
$locs = Settings::counterLocations();

check('a normal line parses', ($locs['NPJ'] ?? '') === 'Nepalgunj — Bus Park', $locs['NPJ'] ?? '(missing)');
check('a lower-case code is upper-cased, and both halves trimmed',
    ($locs['MSA'] ?? '') === 'Mehsana — Head Office', $locs['MSA'] ?? '(missing)');
check('a line with no pipe is its own code', ($locs['BIRGUNJ'] ?? '') === 'BIRGUNJ', $locs['BIRGUNJ'] ?? '(missing)');
check('a duplicate code keeps the FIRST spelling',
    ($locs['NPJ'] ?? '') === 'Nepalgunj — Bus Park');
check('blank lines are skipped, nothing else appears', count($locs) === 3, count($locs) . ' entries');

/* Order is the order in the box — the picker on Staff reads top to bottom. */
check('the order written is the order returned', array_keys($locs) === ['NPJ', 'MSA', 'BIRGUNJ'],
    implode(',', array_keys($locs)));

/* ---- 2. the printable label --------------------------------------- */

check('code alone resolves its name from the list',
    Settings::counterLabel('NPJ', '') === 'Nepalgunj — Bus Park (NPJ)', Settings::counterLabel('NPJ', ''));
check('name alone prints with no empty brackets',
    Settings::counterLabel('', 'Birgunj Office') === 'Birgunj Office', Settings::counterLabel('', 'Birgunj Office'));
check('a code the list has never heard of still prints',
    Settings::counterLabel('ZZZ', '') === 'ZZZ', Settings::counterLabel('ZZZ', ''));
check('nothing set prints nothing', Settings::counterLabel('', '') === '');
check('a lower-case code is upper-cased on the way out',
    Settings::counterLabel('npj', 'Nepalgunj') === 'Nepalgunj (NPJ)', Settings::counterLabel('npj', 'Nepalgunj'));

/* ---- 3. issuedBy() ------------------------------------------------ */

/* Rows shaped the way loadBooking() hands them over. */
$counterSale = [
    'sold_by_admin_id'   => 1,
    'agent_name'         => 'Bishnu Thapa',
    'agent_role'         => 'counter',
    'agent_counter'      => 'Nepalgunj — Bus Park',
    'agent_counter_code' => 'NPJ',
    'source'             => 'counter',
];
$by = Ticket::issuedBy($counterSale);
check('a counter sale carries its desk', $by['location'] === 'Nepalgunj — Bus Park (NPJ)', $by['location']);
check('… and the seller by name', $by['name'] === 'Bishnu Thapa', $by['name']);
check('… and the short code on its own', $by['locCode'] === 'NPJ', $by['locCode']);

$onlineSale = ['sold_by_admin_id' => 0, 'source' => 'web'];
$byOnline = Ticket::issuedBy($onlineSale);
check('an ONLINE sale claims no counter', $byOnline['location'] === '', '[' . $byOnline['location'] . ']');
check('… and is still labelled ONLINE', $byOnline['code'] === 'ONLINE', $byOnline['code']);

/* A desk that has not been given a location yet must not print a blank
   line where the town should be — company_city is the honest fallback. */
$noDesk = [
    'sold_by_admin_id' => 1, 'agent_name' => 'Office', 'agent_role' => 'counter',
    'agent_counter' => '', 'agent_counter_code' => '', 'source' => 'counter',
];
$byNoDesk = Ticket::issuedBy($noDesk);
check('a desk with no location falls back to the company city, never to a lie',
    $byNoDesk['location'] === trim(Settings::getString('company_city', '')),
    '[' . $byNoDesk['location'] . ']');

/* ---- 4. the ticket still renders ---------------------------------- */

$row = Database::fetch(
    "SELECT b.id FROM bookings b
       JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
      WHERE b.status IN ('confirmed','completed')
      ORDER BY b.id DESC LIMIT 1"
);

if ($row === null) {
    echo "  \033[33mSKIP\033[0m  no confirmed booking in this database to render\n";
} else {
    $bid = (int) $row['id'];
    /* Give whoever sold it a desk, render, then put the profile back the
       way it was — this database is shared with the rest of the battery. */
    $sold = (int) (Database::fetch('SELECT sold_by_admin_id FROM bookings WHERE id = :i', ['i' => $bid])['sold_by_admin_id'] ?? 0);
    $anyAdmin = $sold > 0 ? $sold : (int) (Database::fetch('SELECT id FROM admins ORDER BY id LIMIT 1')['id'] ?? 0);

    $before = $anyAdmin > 0
        ? Database::fetch('SELECT counter_name, counter_code FROM admin_profiles WHERE admin_id = :a', ['a' => $anyAdmin])
        : null;

    if ($anyAdmin > 0) {
        Database::run('UPDATE bookings SET sold_by_admin_id = :a WHERE id = :i', ['a' => $anyAdmin, 'i' => $bid]);
        AgentWallet::saveProfile($anyAdmin, ['counter_code' => 'NPJ', 'counter_name' => 'Nepalgunj — Bus Park']);
    }

    try {
        $png = Ticket::pngPath($bid, true);
        check('the PNG ticket renders with a desk set', is_file($png) && filesize($png) > 8000,
            basename($png) . ' ' . (is_file($png) ? filesize($png) : 0) . 'b');
    } catch (Throwable $e) {
        check('the PNG ticket renders with a desk set', false, $e->getMessage());
    }

    try {
        $pdf = Ticket::pdfPath($bid, true);
        check('the PDF ticket renders with a desk set', is_file($pdf) && filesize($pdf) > 4000,
            basename($pdf) . ' ' . (is_file($pdf) ? filesize($pdf) : 0) . 'b');
    } catch (Throwable $e) {
        check('the PDF ticket renders with a desk set', false, $e->getMessage());
    }

    /* And with NO desk — the branch that has to keep the old header. */
    if ($anyAdmin > 0) {
        AgentWallet::saveProfile($anyAdmin, ['counter_code' => '', 'counter_name' => '']);
        try {
            $png2 = Ticket::pngPath($bid, true);
            check('… and still renders with no desk at all', is_file($png2) && filesize($png2) > 8000);
        } catch (Throwable $e) {
            check('… and still renders with no desk at all', false, $e->getMessage());
        }
        /* restore */
        AgentWallet::saveProfile($anyAdmin, [
            'counter_code' => (string) ($before['counter_code'] ?? ''),
            'counter_name' => (string) ($before['counter_name'] ?? ''),
        ]);
        Database::run('UPDATE bookings SET sold_by_admin_id = :a WHERE id = :i', ['a' => $sold ?: null, 'i' => $bid]);
    }
}

/* ---- put the settings row back ------------------------------------ */
Settings::set('counter_locations', $saved, 'string', 'company');
Settings::flush();
check('the counter_locations row is restored', Settings::getString('counter_locations', '') === $saved);

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
