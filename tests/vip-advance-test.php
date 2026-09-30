<?php
/**
 * =====================================================================
 *  VIP PRIVATE · THE FARE BOARD · THE ADVANCE-BOOKING OFFER
 *  · WHATSAPP RULE CONTROL
 *
 *      php tests/vip-advance-test.php
 *
 *  Owner ask (26 Sep 2026): a proper VIP private sleeper on the SAME seat
 *  inventory as the public sharing berth; a point-to-point fare board
 *  (2200 below Ahmedabad, 2000 from Ahmedabad, 1800 back to Ahmedabad,
 *  2200 back to Surat); a 10% discount for booking 24 hours ahead that also
 *  applies to VIP; all of it editable by the office without a deploy; and
 *  the ability to change it from an authorised WhatsApp number.
 *
 *  WHAT THIS PINS DOWN, in the order money moves through it:
 *
 *   1. THE BOARD. The owner's four rules resolve to the owner's four
 *      prices, from every spelling a pickup reaches the server as —
 *      "Ahmedabad", "S Hari Parking, Nana Chiloda @ 21:00 [23.1,72.6]"
 *      and the "shariparking" key Boarding::townKey() produces all price
 *      the same journey. First-match-wins ordering is what makes the
 *      @india catch-all safe, so that is asserted directly.
 *   2. ONE INVENTORY. A private cabin label and the sharing berths inside
 *      it are the same physical beds, in both directions, so a cabin sold
 *      in one mode cannot be free in the other. This is the invariant that
 *      stops the coach being oversold; cross-mode-seat-sync-test.php
 *      exercises it against the database, this pins the mapping itself.
 *   3. THE 24-HOUR BOUNDARY. Just inside earns the discount, just outside
 *      does not, and the measurement is against the DEPARTURE time, not
 *      midnight — the bug that would have refused the offer to somebody
 *      booking 33 hours before an evening bus.
 *   4. TEN PERCENT IS TEN PERCENT, on a sharing seat and on a VIP cabin,
 *      and quote() returns the four lines every screen must print.
 *   5. EDITABLE. Changing the hours, the percentage or a fare through
 *      Settings changes what the engine charges, with no deploy — and 0%
 *      switches the discount off without losing the configuration.
 *   6. THE WHATSAPP GATE. Three independent locks; a customer number, a
 *      manager who is not on the list, and the switch being off each fail
 *      on their own. A preview writes nothing, and a confirmation that
 *      does not match the preview writes nothing.
 *   7. THE AUDIT ROW. Every rule change lands in the audit log with the
 *      old value and the new one.
 *
 *  Every settings row this file touches is put back at the end.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/airules.php';

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

/* ---- remember everything we are about to move --------------------- */
$KEYS = [
    'fare_rules', 'fare_point_aliases', 'fare_to_nepal', 'fare_to_india',
    'advance_offer_on', 'advance_offer_hours', 'advance_offer_percent',
    'advance_offer_from', 'advance_offer_to', 'advance_offer_max_inr',
    'advance_offer_modes', 'advance_offer_title', 'advance_offer_text',
    'wa_rules_control', 'wa_rules_numbers',
];
$SAVED = [];
foreach ($KEYS as $k) {
    $SAVED[$k] = Settings::get($k, null);
}

/** Put a row back exactly as it was — including "there was no row". */
$restore = static function () use ($KEYS, $SAVED): void {
    foreach ($KEYS as $k) {
        if ($SAVED[$k] === null) {
            try { Database::query('DELETE FROM settings WHERE skey = :k', ['k' => $k]); } catch (Throwable $e) {}
        } else {
            $v = $SAVED[$k];
            Settings::set($k, $v, is_array($v) ? 'json' : 'string', 'pricing', true);
        }
    }
    Settings::flush();
};

/* A clean, known starting point: the owner's own board, offer off. */
$useOwnerBoard = static function (): void {
    Settings::set('fare_rules', Fare::FARE_RULES_DEFAULT, 'json', 'pricing', true);
    Settings::set('fare_point_aliases', [], 'json', 'pricing', true);
    Settings::set('fare_to_nepal', 2000, 'float', 'pricing', true);
    Settings::set('fare_to_india', 1800, 'float', 'pricing', true);
    Settings::set('advance_offer_on', '0', 'bool', 'pricing', true);
    Settings::set('advance_offer_hours', 24, 'int', 'pricing', true);
    Settings::set('advance_offer_percent', 10, 'float', 'pricing', true);
    Settings::set('advance_offer_from', '', 'string', 'pricing', true);
    Settings::set('advance_offer_to', '', 'string', 'pricing', true);
    Settings::set('advance_offer_max_inr', 0, 'float', 'pricing', true);
    Settings::set('advance_offer_modes', 'all', 'string', 'pricing', true);
    Settings::set('advance_offer_title', 'Dashain offer', 'string', 'pricing', true);
    Settings::set('advance_offer_text', 'Book early, save.', 'string', 'pricing', true);
    Settings::flush();
};

echo "\n=== VIP private · fare board · advance offer · WhatsApp control ===\n\n";

try {

/* =====================================================================
 *  1. THE BOARD
 * ================================================================= */
echo "-- 1. the fare board the owner dictated ---------------------------\n";

$useOwnerBoard();

$AMD = 'S Hari Parking, Nana Chiloda';

check('Surat -> Rupaidiha is 2200 (a pickup below Ahmedabad)',
    (int) Fare::pointFare('Surat', 'Rupaidiha') === 2200,
    (string) Fare::pointFare('Surat', 'Rupaidiha'));

check('Vadodara -> Rupaidiha is 2200',
    (int) Fare::pointFare('Vadodara', 'Rupaidiha') === 2200,
    (string) Fare::pointFare('Vadodara', 'Rupaidiha'));

check('Ahmedabad -> Rupaidiha is 2000',
    (int) Fare::pointFare('Ahmedabad', 'Rupaidiha') === 2000,
    (string) Fare::pointFare('Ahmedabad', 'Rupaidiha'));

check('... by its official name too',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === 2000,
    (string) Fare::pointFare($AMD, 'Rupaidiha'));

check('Rupaidiha -> Ahmedabad is 1800',
    (int) Fare::pointFare('Rupaidiha', 'Ahmedabad') === 1800,
    (string) Fare::pointFare('Rupaidiha', 'Ahmedabad'));

check('Rupaidiha -> Surat is 2200',
    (int) Fare::pointFare('Rupaidiha', 'Surat') === 2200,
    (string) Fare::pointFare('Rupaidiha', 'Surat'));

check('Rupaidiha -> Vadodara is 2200 (the catch-all covers the towns between)',
    (int) Fare::pointFare('Rupaidiha', 'Vadodara') === 2200,
    (string) Fare::pointFare('Rupaidiha', 'Vadodara'));

/* The spellings a pickup actually arrives as. */
echo "\n-- 1b. every spelling of a pickup prices the same journey ---------\n";

$spellings = [
    'Ahmedabad',
    'ahmedabad',
    'AHMEDABAD',
    'Nana Chiloda',
    'S Hari Parking, Nana Chiloda',
    'S Hari Parking, Nana Chiloda @ 21:00',
    'S Hari Parking, Nana Chiloda @ 21:00 [23.171,72.623]',
    'shariparking',                      // what Boarding::townKey() produces
];
$allSame = true;
foreach ($spellings as $s) {
    if ((int) Fare::pointFare($s, 'Rupaidiha') !== 2000) {
        $allSame = false;
        check('spelling "' . $s . '" prices at 2000', false, (string) Fare::pointFare($s, 'Rupaidiha'));
    }
}
check('all ' . count($spellings) . ' spellings of the Ahmedabad stop price at 2000', $allSame);

/* A stop the office has added to route_stops but not to main_points must
   inherit the board rather than slip out from under it — the strict reading
   of @india charged such a pickup ₹2,000, two hundred less than the Surat
   pickup beside it. */
check('a pickup that is not on the points list still pays the @india rule',
    (int) Fare::pointFare('Somewhere In Gujarat', 'Rupaidiha') === 2200,
    (string) Fare::pointFare('Somewhere In Gujarat', 'Rupaidiha'));
check('... and it is never cheaper than a listed pickup',
    Fare::pointFare('Somewhere In Gujarat', 'Rupaidiha') >= Fare::pointFare($AMD, 'Rupaidiha'));
check('a blank pickup matches no zone, so the directional fallback stands',
    (int) Fare::pointFare('', 'Rupaidiha') === 2000,
    (string) Fare::pointFare('', 'Rupaidiha'));

/* First-match-wins is the whole safety of an @india catch-all. */
echo "\n-- 1c. first match wins ------------------------------------------\n";

Settings::set('fare_rules', [
    ['from' => '@india', 'to' => 'Rupaidiha', 'amount' => 2200, 'note' => 'catch-all FIRST'],
    ['from' => $AMD,     'to' => 'Rupaidiha', 'amount' => 2000, 'note' => 'never reached'],
], 'json', 'pricing', true);
Settings::flush();
check('a catch-all placed ABOVE an exact rule swallows it (so order matters)',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === 2200,
    (string) Fare::pointFare($AMD, 'Rupaidiha'));

$useOwnerBoard();
check('... and with the exact rule first, Ahmedabad is 2000 again',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === 2000);

/* A broken row must not take the whole board down. */
Settings::set('fare_rules', [
    ['from' => '',       'to' => 'Rupaidiha', 'amount' => 999,  'note' => 'no from'],
    ['from' => 'Surat',  'to' => 'Rupaidiha', 'amount' => 0,    'note' => 'no money'],
    ['from' => 'Surat',  'to' => 'Rupaidiha', 'amount' => 2350, 'note' => 'the good one'],
], 'json', 'pricing', true);
Settings::flush();
check('a malformed rule is dropped and the good one still charges',
    (int) Fare::pointFare('Surat', 'Rupaidiha') === 2350,
    (string) Fare::pointFare('Surat', 'Rupaidiha'));
check('... and the board reports only the usable rules', count(Fare::fareRules()) === 1,
    count(Fare::fareRules()) . ' rules');

$useOwnerBoard();

/* journeyPoints: which end of the trip is the Gujarat one. */
echo "\n-- 1d. the passenger's OWN stop decides, not the route's ----------\n";

$outRoute = ['from_city' => 'Surat', 'to_city' => 'Rupaidiha'];
$inRoute  = ['from_city' => 'Rupaidiha', 'to_city' => 'Surat'];

$e1 = Fare::journeyPoints($outRoute, 'S Hari Parking, Nana Chiloda @ 21:00', '');
check('outbound: boarding at Nana Chiloda prices as Ahmedabad, not as Surat',
    $e1['from'] === $AMD && (int) Fare::pointFare($e1['from'], $e1['to']) === 2000,
    $e1['from'] . ' -> ' . $e1['to']);

$e2 = Fare::journeyPoints($outRoute, '', '');
check('outbound with no stop given falls back to the route origin',
    $e2['from'] === 'Surat', $e2['from']);

$e3 = Fare::journeyPoints($inRoute, '', 'S Hari Parking, Nana Chiloda @ 06:00');
check('return: the DROP is the Gujarat end, so 1800 to Ahmedabad',
    (int) Fare::pointFare($e3['from'], $e3['to']) === 1800,
    $e3['from'] . ' -> ' . $e3['to'] . ' = ' . Fare::pointFare($e3['from'], $e3['to']));

/* The board map the browser is handed. */
$map = Fare::fareBoardMap();
check('the browser board map carries the Ahmedabad pair at 2000',
    (int) ($map[Fare::pkey($AMD) . '|' . Fare::pkey('Rupaidiha')] ?? 0) === 2000);
check('... and a catch-all line for a pickup the page cannot name',
    isset($map['*|' . Fare::pkey('Rupaidiha')]));

/* =====================================================================
 *  2. ONE INVENTORY — VIP private and public sharing are the same beds
 * ================================================================= */
echo "\n-- 2. one coach, one seat list -----------------------------------\n";

$cabin = 'L3';
$beds  = Seats::physicalSeats($cabin, 'private', 'sleeper');
check('a private cabin occupies more than one physical bed', count($beds) >= 2, implode(',', $beds));

$backAll = true;
foreach ($beds as $bed) {
    if (Seats::physicalToMode($bed, 'private', 'sleeper') !== $cabin) {
        $backAll = false;
    }
}
check('every bed inside that cabin maps BACK to the cabin', $backAll,
    $cabin . ' = ' . implode(' + ', $beds));

check('a sharing berth is already physical (the canonical namespace)',
    Seats::physicalSeats('L7', 'sharing', 'sleeper') === ['L7']);

/* No bed may belong to two cabins — that is what would oversell. */
$claimed = [];
$twice   = [];
foreach (Seats::seatIds('sleeper', 'private') as $lbl) {
    foreach (Seats::physicalSeats($lbl, 'private', 'sleeper') as $bed) {
        if (isset($claimed[$bed])) { $twice[] = $bed; }
        $claimed[$bed] = $lbl;
    }
}
check('no physical bed is claimed by two private cabins', $twice === [],
    $twice === [] ? count($claimed) . ' beds, all unique' : implode(',', array_unique($twice)));

$sharingAll = Seats::seatIds('sleeper', 'sharing');
check('the private cabins cover exactly the sharing berths',
    count(array_diff($sharingAll, array_keys($claimed))) === 0
    && count(array_diff(array_keys($claimed), $sharingAll)) === 0,
    count($sharingAll) . ' sharing berths, ' . count($claimed) . ' beds under cabins');

/* The translation used by the seat map: beds busy in one mode close the
   other mode's label. */
$oneBed  = $beds[0];
$asPriv  = Seats::physicalSetToMode([$oneBed], 'private', 'sleeper');
check('ONE sharing berth sold closes the whole private cabin above it',
    $asPriv === [$cabin], implode(',', $asPriv));

$asShare = Seats::physicalSetToMode($beds, 'sharing', 'sleeper');
check('... and a private cabin sold closes every sharing berth inside it',
    count(array_diff($beds, $asShare)) === 0, implode(',', $asShare));

/* =====================================================================
 *  3. THE 24-HOUR BOUNDARY
 * ================================================================= */
echo "\n-- 3. the 24-hour boundary ---------------------------------------\n";

$useOwnerBoard();
Settings::set('advance_offer_on', '1', 'bool', 'pricing', true);
Settings::flush();

$offer = Fare::advanceOffer();
check('the offer reads as running today', $offer['live'] === true && (int) $offer['hours'] === 24);

/* A departure exactly 25 h away, and one exactly 23 h away. The clock is
   built from now, so this is stable whatever time the suite runs. */
$in25 = date('Y-m-d H:i:s', time() + 25 * 3600);
$in23 = date('Y-m-d H:i:s', time() + 23 * 3600);

$d25 = Fare::advanceDiscount(2200, substr($in25, 0, 10), substr($in25, 11), 'sharing');
$d23 = Fare::advanceDiscount(2200, substr($in23, 0, 10), substr($in23, 11), 'sharing');

check('25 hours before departure EARNS the discount', $d25['ok'] === true,
    'cut ' . $d25['amount'] . ' — ' . $d25['why']);
check('23 hours before departure does NOT', $d23['ok'] === false,
    $d23['why']);

/* The measurement must use the departure time, not midnight. A bus at
   19:00 tomorrow is ~33 h away when this runs in the morning; measured to
   midnight it is under 24 and the passenger would be wrongly refused. */
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$byClock  = Fare::advanceDiscount(2200, $tomorrow, '23:59:00', 'sharing');
$byNight  = Fare::advanceDiscount(2200, $tomorrow, '00:00:00', 'sharing');
check('the boundary is measured to the DEPARTURE, so a late bus tomorrow qualifies',
    $byClock['ok'] === true, 'hoursLeft ' . $byClock['hoursLeft']);
check('... while midnight on the same date does not (this is why the time is passed)',
    $byNight['ok'] === false, 'hoursLeft ' . $byNight['hoursLeft']);

check('a departure already past earns nothing',
    Fare::advanceDiscount(2200, date('Y-m-d', strtotime('-2 day')), '19:00:00', 'sharing')['ok'] === false);

/* =====================================================================
 *  4. TEN PERCENT, ON SHARING AND ON VIP
 * ================================================================= */
echo "\n-- 4. ten percent is ten percent ---------------------------------\n";

$ctx = ['travelDate' => substr($in25, 0, 10), 'departureTime' => substr($in25, 11), 'bookingMode' => 'sharing'];

$qShare = Fare::quote(2200, 1, 0, 0, 0, '', '', null, $ctx);
check('a 2200 sharing seat loses 220', (int) $qShare['advanceDiscount'] === 220,
    (string) $qShare['advanceDiscount']);
check('... and the payable is 1980', (int) $qShare['finalFare'] === 1980,
    (string) $qShare['finalFare']);

$ctxP  = ['travelDate' => substr($in25, 0, 10), 'departureTime' => substr($in25, 11), 'bookingMode' => 'private'];
$qVip  = Fare::quote(3800, 1, 0, 0, 0, '', '', null, $ctxP);
check('a 3800 VIP private cabin loses 380 — the offer reaches VIP too',
    (int) $qVip['advanceDiscount'] === 380, (string) $qVip['advanceDiscount']);
check('... and the VIP payable is 3420', (int) $qVip['finalFare'] === 3420,
    (string) $qVip['finalFare']);

/* The four lines every screen must print. */
check('the quote returns Original fare', (int) $qShare['originalFare'] === 2200);
check('the quote returns Discount amount', (int) $qShare['discountAmount'] === 220);
check('the quote returns Discount percent', abs((float) $qShare['discountPercent'] - 10.0) < 0.01,
    (string) $qShare['discountPercent']);
check('the quote returns Final fare', (int) $qShare['finalFare'] === 1980);
check('Original − Discount = Final, exactly',
    (int) $qShare['originalFare'] - (int) $qShare['discountAmount'] === (int) $qShare['finalFare']);

/* A quote with no date is the old behaviour: no advance discount. */
$qBare = Fare::quote(2200, 1, 0, 0, 0, '', '', null);
check('a caller that passes no travel date gets no advance discount (unchanged behaviour)',
    (int) $qBare['advanceDiscount'] === 0 && (int) $qBare['total'] === 2200);

/* The mode filter. */
Settings::set('advance_offer_modes', 'private', 'string', 'pricing', true);
Settings::flush();
check('an offer limited to private refuses a sharing booking',
    Fare::advanceDiscount(2200, substr($in25, 0, 10), substr($in25, 11), 'sharing')['ok'] === false);
check('... and still pays on a private one',
    Fare::advanceDiscount(3800, substr($in25, 0, 10), substr($in25, 11), 'private')['ok'] === true);
Settings::set('advance_offer_modes', 'all', 'string', 'pricing', true);

/* The ceiling. */
Settings::set('advance_offer_max_inr', 150, 'float', 'pricing', true);
Settings::flush();
check('the ₹ ceiling caps the discount',
    (int) Fare::advanceDiscount(2200, substr($in25, 0, 10), substr($in25, 11), 'sharing')['amount'] === 150);
Settings::set('advance_offer_max_inr', 0, 'float', 'pricing', true);
Settings::flush();

/* =====================================================================
 *  5. EDITABLE WITHOUT A DEPLOY
 * ================================================================= */
echo "\n-- 5. the office can change all of it -----------------------------\n";

Settings::set('advance_offer_hours', 48, 'int', 'pricing', true);
Settings::flush();
check('moving 24 to 48 hours refuses a 25-hour booking',
    Fare::advanceDiscount(2200, substr($in25, 0, 10), substr($in25, 11), 'sharing')['ok'] === false,
    'hours now ' . Fare::advanceOffer()['hours']);

Settings::set('advance_offer_hours', 24, 'int', 'pricing', true);
Settings::set('advance_offer_percent', 15, 'float', 'pricing', true);
Settings::flush();
check('moving 10% to 15% takes 330 off 2200',
    (int) Fare::advanceDiscount(2200, substr($in25, 0, 10), substr($in25, 11), 'sharing')['amount'] === 330);

Settings::set('advance_offer_percent', 0, 'float', 'pricing', true);
Settings::flush();
check('0% switches the discount off without losing the settings',
    Fare::advanceOffer()['live'] === false
    && (int) Fare::advanceOffer()['hours'] === 24,
    'hours kept at ' . Fare::advanceOffer()['hours']);

Settings::set('advance_offer_percent', 10, 'float', 'pricing', true);
Settings::set('advance_offer_from', date('Y-m-d', strtotime('+3 day')), 'string', 'pricing', true);
Settings::flush();
check('an offer whose window has not opened is ON but not running',
    Fare::advanceOffer()['on'] === true && Fare::advanceOffer()['live'] === false);
Settings::set('advance_offer_from', '', 'string', 'pricing', true);
Settings::set('advance_offer_to', date('Y-m-d', strtotime('-1 day')), 'string', 'pricing', true);
Settings::flush();
check('an offer whose window has closed stops paying',
    Fare::advanceOffer()['live'] === false);
Settings::set('advance_offer_to', '', 'string', 'pricing', true);
Settings::flush();

/* A fare change reaches the engine immediately. */
$rules = Fare::fareRules();
$rules[0]['amount'] = 2100;
Settings::set('fare_rules', $rules, 'json', 'pricing', true);
Settings::flush();
check('editing the board changes what the engine charges at once',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === 2100,
    (string) Fare::pointFare($AMD, 'Rupaidiha'));
$useOwnerBoard();

/* cabinFare, the function every panel prices through. */
$cf = Fare::cabinFare('single', 'sharing', 2, true, 'Rupaidiha', 4, 'Surat');
check('cabinFare prices 2 sharing passengers from Surat at 4400',
    (int) $cf['total'] === 4400, (string) $cf['total']);
$cfA = Fare::cabinFare('single', 'sharing', 2, true, 'Rupaidiha', 4, $AMD);
check('... and the same two from Ahmedabad at 4000',
    (int) $cfA['total'] === 4000, (string) $cfA['total']);
$cfNo = Fare::cabinFare('single', 'sharing', 1, true, 'Rupaidiha');
check('a caller that names no pickup keeps the old directional fare (2000)',
    (int) $cfNo['total'] === 2000, (string) $cfNo['total']);

/* =====================================================================
 *  6. THE WHATSAPP GATE
 * ================================================================= */
echo "\n-- 6. who may change a fare by message ---------------------------\n";

Settings::set('wa_rules_control', '0', 'bool', 'whatsapp', false);
Settings::set('wa_rules_numbers', '', 'string', 'whatsapp', false);
Settings::flush();

$asAdmin = ['role' => 'admin', 'adminId' => 1, 'name' => 'Office', 'phone' => '9726401507', 'channel' => 'whatsapp'];
$asCust  = ['role' => 'customer', 'adminId' => 0, 'name' => '', 'phone' => '9726401507', 'channel' => 'whatsapp'];
$asOther = ['role' => 'admin', 'adminId' => 2, 'name' => 'Other manager', 'phone' => '9999988888', 'channel' => 'whatsapp'];

check('with the switch OFF, even the office is refused',
    AiRules::mayCommand($asAdmin)['ok'] === false, AiRules::mayCommand($asAdmin)['why']);

Settings::set('wa_rules_control', '1', 'bool', 'whatsapp', false);
Settings::flush();
check('switch ON but no number listed is still a refusal',
    AiRules::mayCommand($asAdmin)['ok'] === false, AiRules::mayCommand($asAdmin)['why']);

Settings::set('wa_rules_numbers', '9726401507,9104801507', 'string', 'whatsapp', false);
Settings::flush();
check('the two authorised numbers are read', count(AiRules::allowedNumbers()) === 2,
    implode(' ', AiRules::allowedNumbers()));
check('an authorised OFFICE number may command', AiRules::mayCommand($asAdmin)['ok'] === true);
check('a CUSTOMER on the very same number may not',
    AiRules::mayCommand($asCust)['ok'] === false, AiRules::mayCommand($asCust)['why']);
check('a manager whose number is NOT listed may not',
    AiRules::mayCommand($asOther)['ok'] === false, AiRules::mayCommand($asOther)['why']);

/* What the assistant may read. */
$read = AiRules::read();
check('rules_read reports the whole board', count($read['board']) >= 2, count($read['board']) . ' pairs');
check('rules_read reports the advance offer', isset($read['advance']['hours'], $read['advance']['percent']));
check('the spoken summary names a real fare',
    str_contains(AiRules::summary(), 'Rupaidiha'));

/* A proposal describes and validates, and writes nothing. */
echo "\n-- 6b. a proposal is a description, never a permission -----------\n";

$before = (int) Fare::pointFare($AMD, 'Rupaidiha');
$p = AiRules::propose(['what' => 'fare', 'from' => 'Ahmedabad', 'to' => 'Rupaidiha', 'value' => 2100]);
check('a fare proposal is accepted and described', !empty($p['ok']) && str_contains((string) $p['say'], '2,100'),
    (string) ($p['say'] ?? $p['why'] ?? ''));
check('... and NOTHING was written by proposing it',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === $before, (string) Fare::pointFare($AMD, 'Rupaidiha'));

check('a fare of zero is refused',
    empty(AiRules::propose(['what' => 'fare', 'from' => 'Surat', 'to' => 'Rupaidiha', 'value' => 0])['ok']));
check('a town we do not sell is refused',
    empty(AiRules::propose(['what' => 'fare', 'from' => 'Mumbai', 'to' => 'Rupaidiha', 'value' => 2000])['ok']));
check('a settings key that is not on the whitelist is refused',
    empty(AiRules::propose(['what' => 'setting', 'key' => 'anthropic_api_key', 'value' => 'x'])['ok']),
    (string) (AiRules::propose(['what' => 'setting', 'key' => 'anthropic_api_key', 'value' => 'x'])['why'] ?? ''));
check('a percentage above 100 is refused',
    empty(AiRules::propose(['what' => 'setting', 'key' => 'advance_offer_percent', 'value' => 150])['ok']));
check('"band gara" is understood as OFF for the offer switch',
    !empty(AiRules::propose(['what' => 'setting', 'key' => 'advance_offer_on', 'value' => 'band'])['ok']));

/* And the write, with its audit row. */
echo "\n-- 7. the write, and the audit row -------------------------------\n";

$auditBefore = (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'pricing.set'", [], 0
);

$ok = AiRules::apply($p['change'], $asAdmin);
check('applying the confirmed proposal saves it', !empty($ok['ok']), (string) $ok['say']);
check('the engine now charges the new fare',
    (int) Fare::pointFare($AMD, 'Rupaidiha') === 2100,
    (string) Fare::pointFare($AMD, 'Rupaidiha'));

$auditAfter = (int) Database::scalar(
    "SELECT COUNT(*) FROM audit_logs WHERE action = 'pricing.set'", [], 0
);
check('an audit row was written', $auditAfter > $auditBefore, $auditBefore . ' -> ' . $auditAfter);

$row = Database::fetch(
    "SELECT entity_id, old_value, new_value, detail FROM audit_logs
      WHERE action = 'pricing.set' ORDER BY id DESC LIMIT 1"
);
check('the audit row names the setting it moved',
    $row !== null && (string) $row['entity_id'] === 'fare_rules', (string) ($row['entity_id'] ?? ''));
check('the audit row carries the OLD value and the NEW one',
    $row !== null && trim((string) $row['old_value']) !== '' && trim((string) $row['new_value']) !== ''
    && $row['old_value'] !== $row['new_value']);
check('the audit detail names who did it and through what',
    $row !== null && str_contains((string) $row['detail'], 'WhatsApp'),
    (string) ($row['detail'] ?? ''));

/* An unauthorised number cannot apply, even holding a valid change. */
check('the same change from an unlisted number is refused at apply() too',
    empty(AiRules::apply($p['change'], $asOther)['ok']));
check('... and from a customer',
    empty(AiRules::apply($p['change'], $asCust)['ok']));

/* A settings change through the same door. */
$sp = AiRules::propose(['what' => 'setting', 'key' => 'advance_offer_percent', 'value' => 12]);
check('a percentage proposal is described with both values',
    !empty($sp['ok']) && str_contains((string) $sp['say'], '12'), (string) ($sp['say'] ?? ''));
$sa = AiRules::apply($sp['change'], $asAdmin);
check('applying it changes the discount the engine grants',
    !empty($sa['ok']) && abs(Fare::advanceOffer()['percent'] - 12.0) < 0.01,
    (string) Fare::advanceOffer()['percent']);

/* =====================================================================
 *  8. THE FRONT END IS ACTUALLY WIRED
 *
 *  A "Book VIP" button that landed on the sharing seat map would be a lie,
 *  and an offer card with no data behind it would be a claim. These are
 *  static checks — cheap, and they catch a rename that silently unwires
 *  the block, which is exactly how this kind of thing rots.
 * ================================================================= */
echo "\n-- 8. the home page block is wired -------------------------------\n";

$root = dirname(__DIR__);
$tpl  = (string) @file_get_contents($root . '/app.template.html');
$vipJs = (string) @file_get_contents($root . '/assets/js/22-vip.js');
$res   = (string) @file_get_contents($root . '/assets/js/06-results.js');
$idx   = (string) @file_get_contents($root . '/index.php');

check('the VIP highlight is on the home page', str_contains($tpl, 'id="vipHighlight"'));
check('it is NOT hidden inside the collapsed "More" brochure',
    strpos($tpl, 'id="vipHighlight"') < strpos($tpl, 'id="homeExtra"'),
    'vip at ' . strpos($tpl, 'id="vipHighlight"') . ', homeExtra at ' . strpos($tpl, 'id="homeExtra"'));
check('the advance-offer card is on the home page and ships hidden',
    str_contains($tpl, 'id="advOffer"') && str_contains($tpl, 'id="advOffer" hidden'));
check('both VIP buttons exist',
    str_contains($tpl, 'id="vipBookBtn"') && str_contains($tpl, 'id="vipCheckBtn"'));
check('the buttons unfold and scroll to the search card',
    substr_count($tpl, 'data-bkmode="regular" data-scroll="search-anchor"') >= 2);
check('22-vip.js sets the PRIVATE intent', str_contains($vipJs, "SHG_VIP_INTENT = 'private'"));
check('the seat view honours that intent instead of defaulting to sharing',
    str_contains($res, 'SHG_VIP_INTENT'));
check('index.php ships the fare board and the offer to the browser',
    str_contains($idx, 'fareBoardMap()') && str_contains($idx, "'offer' =>"));
check('the four facility chips are the four the owner named',
    str_contains($tpl, '>AC Sleeper<') && str_contains($tpl, '>Mobile Charging<')
    && str_contains($tpl, '>Safe Travel<') && str_contains($tpl, '>Travel Comfort<'));
check('the stylesheet and the script are linked',
    str_contains($tpl, 'assets/css/vip.css?v=') && str_contains($tpl, 'assets/js/22-vip.js?v='));
check('the cabin animation only runs while it is on screen',
    str_contains($vipJs, 'IntersectionObserver'));
check('nothing animates for a visitor who asked for less motion',
    str_contains((string) @file_get_contents($root . '/assets/css/vip.css'), 'prefers-reduced-motion'));

/* The admin screen the owner edits all this from. */
$pricing = (string) @file_get_contents($root . '/admin/pricing.php');
check('the one pricing screen exists', $pricing !== '');
check('... it edits the board, the VIP cabins and the offer',
    str_contains($pricing, "value=\"board\"") && str_contains($pricing, 'vip_single')
    && str_contains($pricing, 'advance_offer_hours'));
check('... every save is audited', str_contains($pricing, "Logger::audit"));
check('... and it is in the admin menu',
    str_contains((string) @file_get_contents($root . '/admin/_guard.php'), "'pricing.php'"));

} catch (Throwable $e) {
    check('the suite ran to the end', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

/* ---- put every row back ------------------------------------------- */
$restore();
$still = true;
foreach ($KEYS as $k) {
    $now = Settings::get($k, null);
    $was = $SAVED[$k];
    if (is_array($was) || is_array($now)) {
        if (json_encode($now) !== json_encode($was)) { $still = false; }
    } elseif ((string) $now !== (string) $was) {
        $still = false;
    }
}
check('every settings row this suite touched is back as it was', $still);

echo "\n----------------------------------------\n";
echo "PASSED: {$PASS}   FAILED: {$FAIL}\n";
echo "----------------------------------------\n\n";

exit($FAIL === 0 ? 0 : 1);
