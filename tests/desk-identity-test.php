<?php
/**
 * =====================================================================
 *  DESK IDENTITY — whose ticket is this?
 *
 *      php tests/desk-identity-test.php
 *
 *  Two companies sell on this system (4 Oct 2026). What this pins down:
 *
 *    1. An online sale and every unstamped old row carry the INDIA
 *       letterhead, exactly as before.
 *    2. A Nepal sale keeps the India letterhead until the office has typed
 *       the Nepal company's name - nothing about that company is invented.
 *    3. Once it is typed, a Nepal sale prints the Nepal company: its name,
 *       PAN (not the India CIN), address, +977 WhatsApp, NPT beside IST -
 *       and the India number as the other side's help.
 *    4. The entity FROZEN on the booking wins over the desk's entity today.
 *    5. The help list has no hard-coded numbers and never repeats one.
 *    6. The renderers actually go through the one answer.
 *
 *  Settings are restored at the end; nothing here touches a booking.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';

$PASS = 0;
$FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void
{
    global $PASS, $FAIL;
    if ($ok) {
        $PASS++;
        echo "  \033[32mPASS\033[0m  $l\n";
    } else {
        $FAIL++;
        echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n";
    }
}

echo "\n=== Desk identity: whose ticket is this ===\n\n";

if (!CounterDesk::hasTable() || Database::fetch("SELECT 1 FROM settings WHERE skey = 'nepal_pan'") === null) {
    echo "  \033[33mSKIP\033[0m  upgrade-2026-10-04-counter-desks.sql / -two-companies.sql are not applied on this database\n\n";
    exit(0);
}

$keys  = ['nepal_company', 'nepal_company_ne', 'nepal_office', 'nepal_phone', 'nepal_pan', 'nepal_reg', 'nepal_whatsapp', 'nepal_logo'];
$saved = [];
foreach ($keys as $k) {
    $saved[$k] = Settings::getString($k, '');
}
$put = static function (array $pairs): void {
    foreach ($pairs as $k => $v) {
        Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $v, 'k' => $k]);
    }
    Settings::flush();
};

try {
    /* ---- 1 + 2: nothing typed yet ---------------------------------- */
    $put(array_fill_keys($keys, ''));
    $india = Settings::company();

    $online = Settings::companyFor(null, null);
    check('an online sale is the India company', $online['entity'] === 'IN' && $online['name'] === $india['name']);
    check('...with its CIN as the registration', $online['regLabel'] === 'CIN' && $online['regNo'] === $india['cin']);
    check('...on India time', $online['tz'] === 'IST');
    check('...and no Nepal help number is invented', $online['otherHelp'] === null);

    $npBlank = Settings::companyFor('NPJ', 'NP');
    check('a Nepal sale keeps the India letterhead while the Nepal name is blank',
        $npBlank['entity'] === 'IN' && $npBlank['name'] === $india['name'] && $npBlank['saleEntity'] === 'NP',
        $npBlank['entity'] . ' / ' . $npBlank['name']);
    check('...but still knows its desk', ($npBlank['desk']['code'] ?? '') === 'NPJ');

    /* ---- 3: the office types the Nepal company (placeholder values) -- */
    $put([
        'nepal_company'  => 'Test Nepal Co Pvt. Ltd.',
        'nepal_office'   => 'Test Chowk, Nepalgunj',
        'nepal_phone'    => '+977 81 000000',
        'nepal_pan'      => '000000000',
        'nepal_whatsapp' => '9800000000',
    ]);
    $np = Settings::companyFor('NPJ', 'NP');
    check('a Nepal sale now prints the Nepal company', $np['entity'] === 'NP' && $np['name'] === 'Test Nepal Co Pvt. Ltd.', $np['name']);
    check('...with its PAN, never the India CIN', $np['regLabel'] === 'PAN' && $np['regNo'] === '000000000' && $np['cin'] === '');
    check('...its own address and phone', $np['address'] === 'Test Chowk, Nepalgunj' && $np['phone'] === '+977 81 000000');
    check('...a bare Nepali mobile gets +977 for WhatsApp', $np['whatsapp'] === '9779800000000', $np['whatsapp']);
    check('...Nepal time beside India time', $np['tz'] === 'NPT'
        && Ticket::zonedTime('18:00', $np) === '6:00 PM IST (6:15 PM NPT)', Ticket::zonedTime('18:00', $np));
    check('...and India as the other side\'s help', ($np['otherHelp']['label'] ?? '') === 'India' && ($np['otherHelp']['phone'] ?? '') === $india['phone']);
    check('...no GSTIN borrowed from India', $np['gstin'] === '');

    $in = Settings::companyFor('SRT', 'IN');
    check('an India desk is untouched by the Nepal rows', $in['entity'] === 'IN' && $in['name'] === $india['name'] && $in['regLabel'] === 'CIN');
    check('...shows India time only', Ticket::zonedTime('18:00', $in) === '6:00 PM IST');
    check('...and now offers the Nepal help number', ($in['otherHelp']['label'] ?? '') === 'Nepal' && ($in['otherHelp']['phone'] ?? '') === '+977 81 000000');

    $put(['nepal_pan' => '', 'nepal_reg' => 'REG-TEST-1']);
    $np2 = Settings::companyFor('NPJ', 'NP');
    check('with no PAN the registration number prints instead', $np2['regLabel'] === 'Reg.' && $np2['regNo'] === 'REG-TEST-1');
    $put(['nepal_reg' => '', 'nepal_phone' => '']);
    $np3 = Settings::companyFor('NPJ', 'NP');
    check('with neither, no registration line is printed', $np3['regLabel'] === '' && $np3['regNo'] === '');
    check('a blank Nepal phone falls back to a real India number, never an empty line', $np3['phone'] === $india['phone']);
    $put(['nepal_phone' => '+977 81 000000', 'nepal_pan' => '000000000']);

    /* ---- 4: the stamp wins ------------------------------------------ */
    check('the entity frozen on the booking wins over the desk\'s entity today',
        Settings::companyFor('SRT', 'NP')['entity'] === 'NP' && Settings::companyFor('NPJ', 'IN')['entity'] === 'IN');
    check('with no stamp the desk\'s company answers', Settings::companyFor('NPJ', '')['entity'] === 'NP');
    check('Rupaidiha (India side of the border) is the India company', Settings::companyFor('RPD', '')['entity'] === 'IN');
    check('Ticket::companyOf() reads the booking row',
        Ticket::companyOf(['counter_code' => 'NPJ', 'entity' => 'NP'])['entity'] === 'NP'
        && Ticket::companyOf(['pnr' => 'X'])['entity'] === 'IN');
    $iss = Ticket::issuedBy(['source' => 'web', 'counter_code' => '', 'entity' => 'NP']);
    check('an online Nepal-company ticket is issued in that company\'s name', $iss['name'] === 'Test Nepal Co Pvt. Ltd.', $iss['name']);

    /* ---- 5: the help list -------------------------------------------- */
    $help = Settings::helpContacts($in, 4);
    $nums = array_map(static fn(array $r): string => preg_replace('/\D/', '', $r[1]) ?? '', $help);
    check('the help list holds at most four numbers', count($help) >= 1 && count($help) <= 4, (string) count($help));
    check('...never the same number twice', count($nums) === count(array_unique($nums)));
    check('...and includes the other side', in_array('97781000000', $nums, true), implode(',', $nums));

    $savedPhone = (string) (CounterDesk::get('NPJ')['phone'] ?? '');
    Database::update('counter_locations', ['phone' => '+977 81 111111'], 'code = :c', ['c' => 'NPJ']);
    CounterDesk::flush();
    $helpNp = Settings::helpContacts(Settings::companyFor('NPJ', 'NP'), 4);
    check('a desk with its own phone leads its company\'s list',
        ($helpNp[0][0] ?? '') === 'Nepalgunj' && ($helpNp[0][1] ?? '') === '+977 81 111111', json_encode($helpNp));
    check('...and the ticket\'s support line is the desk\'s own number',
        (Settings::companyFor('NPJ', 'NP')['desk']['phone'] ?? '') === '+977 81 111111');
    Database::update('counter_locations', ['phone' => $savedPhone !== '' ? $savedPhone : null], 'code = :c', ['c' => 'NPJ']);
    CounterDesk::flush();
} finally {
    $put($saved);
}

/* ---- 6: the renderers go through the one answer ---------------------- */
$root = dirname(__DIR__);
$src  = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);
$t    = $src('includes/ticket.php');
check('the PNG, the PDF and the invoice all ask companyOf()', substr_count($t, 'self::companyOf($booking)') >= 4);
check('no office number is typed into the server ticket', !preg_match('/\+91 9\d{4} \d{5}/', $t));
check('the invoice says TAX INVOICE only with a GSTIN', str_contains($t, "\$gstin !== '' ? 'TAX INVOICE' : 'INVOICE'"));
check('a cached invoice redraws after a layout change', str_contains($t, "!empty(\$ticket['invoice_path'])") && substr_count($t, 'PDF_LAYOUT_CHANGED') >= 3);
check('no office number is typed into the app\'s fallback ticket', !preg_match('/\+91 9\d{4} \d{5}/', $src('assets/js/11-pdf-ticket.js')));
check('the verify page names the issuing company', str_contains($src('verify-ticket.php'), 'Settings::companyFor('));
check('the WhatsApp ticket texts lead with the issuing company', substr_count($src('includes/notify.php'), 'Settings::companyFor(') >= 4);

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");

exit($FAIL === 0 ? 0 : 1);
