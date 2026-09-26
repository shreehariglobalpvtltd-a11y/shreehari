<?php
/**
 * =====================================================================
 *  THE ADVANCE OFFER, FINISHED — seven small changes, one section each
 *
 *      php tests/vip-finish-test.php
 *
 *  Follow-up to docs/UPGRADE-2026-09-26-vip-advance.md §7 (26 Sep 2026):
 *
 *    1. bookings.advance_discount is printed as its own line — ticket PNG
 *       and PDF, the invoice, admin/booking-view.php, Quick Ticket, the
 *       agent's register and My Bookings.
 *    2. admin/pricing.php has a "what would this journey on this date
 *       cost" box that reads the same engine as the checkout.
 *    3. The offer can be narrowed to one route or one departure date.
 *    4. A fare change by WhatsApp tells the OTHER authorised number, and
 *       rules_change refuses a ninth attempt in ten minutes.
 *    5. advance_offer_max_inr may count per passenger.
 *    6. Gujarati carries the VIP sentences, the offer card and the four
 *       checkout lines.
 *    7. The offer card counts down, Nepali first, to the day it ends.
 *
 *  Settings are put back exactly as found, the test sale is deleted, and
 *  no WhatsApp leaves the machine: the driver is forced to click_to_chat
 *  for the duration and Notify's journal is read instead.
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
require_once INCLUDE_PATH . '/airules.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/aitools.php';

const VFIN_PHONE = '910000773';      // the test passenger's number prefix
const VFIN_A     = '917000771001';   // the office number that commands (mobile-shaped: normalisePhone keeps it)
const VFIN_B     = '917000771002';   // the other authorised office number

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

$ROOT   = dirname(__DIR__);
$ini    = $ROOT . '/.claude/php-dev.ini';
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

/* ---- remember everything we are about to move --------------------- */
$KEYS = [
    'advance_offer_on', 'advance_offer_hours', 'advance_offer_percent',
    'advance_offer_from', 'advance_offer_to', 'advance_offer_max_inr',
    'advance_offer_modes', 'advance_offer_title', 'advance_offer_text',
    'advance_offer_route', 'advance_offer_date', 'advance_offer_max_per',
    'wa_rules_control', 'wa_rules_numbers', 'whatsapp_driver',
];
$SAVED = [];
foreach ($KEYS as $k) {
    $SAVED[$k] = Settings::get($k, null);
}
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
/** The owner's offer, ON, company-wide, no ceiling. */
$offerOn = static function (): void {
    Settings::set('advance_offer_on', '1', 'bool', 'pricing', true);
    Settings::set('advance_offer_hours', 24, 'int', 'pricing', true);
    Settings::set('advance_offer_percent', 10, 'float', 'pricing', true);
    Settings::set('advance_offer_from', '', 'string', 'pricing', true);
    Settings::set('advance_offer_to', '', 'string', 'pricing', true);
    Settings::set('advance_offer_max_inr', 0, 'float', 'pricing', true);
    Settings::set('advance_offer_modes', 'all', 'string', 'pricing', true);
    Settings::set('advance_offer_title', 'Dashain offer', 'string', 'pricing', true);
    Settings::set('advance_offer_text', 'Book early, save.', 'string', 'pricing', true);
    Settings::set('advance_offer_route', 0, 'int', 'pricing', true);
    Settings::set('advance_offer_date', '', 'string', 'pricing', true);
    Settings::set('advance_offer_max_per', 'booking', 'string', 'pricing', true);
    Settings::flush();
};

echo "\n=== The advance offer, finished ===\n";

$w     = bookingWindow();
$D     = addDaysISO($w['from'], 21);
$route = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
$aid   = (int) Database::scalar(
    "SELECT id FROM admins WHERE is_active=1 ORDER BY (role='superadmin') DESC, id ASC LIMIT 1", [], 0
);
$rid   = $route !== null ? (int) $route['id'] : (int) Database::scalar('SELECT id FROM routes ORDER BY id LIMIT 1', [], 0);
$hasAdv = Database::fetch("SHOW COLUMNS FROM bookings LIKE 'advance_discount'") !== null;

$cleanup = static function () use ($D): void {
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE contact_phone LIKE '" . VFIN_PHONE . "%'") as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => $D]);
    foreach (Database::fetchAll('SELECT id FROM schedules WHERE travel_date = :d', ['d' => $D]) as $s) {
        Database::delete('seat_locks', 'schedule_id = :s', ['s' => (int) $s['id']]);
    }
    Database::delete('schedules', 'travel_date = :d', ['d' => $D]);
};

try {
    /* =================================================================
     *  1. THE OFFER AS ITS OWN LINE, EVERYWHERE
     * ================================================================= */
    echo "\n-- 1. the offer as its own line, everywhere -----------------------\n";
    if (!$hasAdv) {
        echo "  \033[33mSKIP\033[0m  bookings.advance_discount is not on this database (database/upgrade-2026-09-vip-advance.php) — the sale half is not run\n";
    } elseif ($route === null || $aid <= 0) {
        echo "  \033[33mSKIP\033[0m  no active sleeper route (or no admin) on this database — the sale half is not run\n";
    } else {
        $offerOn();
        $cleanup();
        $sched = Seats::schedule($rid, $D);
        $b     = BookingService::counterSale($route, (int) $sched['id'], $D, ['L30'], [
            'name'          => 'Advance Line Test',
            'phone'         => VFIN_PHONE . '0',
            'paymentMethod' => 'cash',
            'amount'        => 2000,
        ], $aid, 'counter');
        $bid = (int) $b['id'];
        /* The desk types its fare — counterSale() prices by hand and applies
           no offer; the website, the agent panel and QuickBot store the offer
           through priceBooking(). Write the column exactly as those paths do,
           so every READER below is exercised against a known figure. */
        Database::update('bookings', ['advance_discount' => 200.00, 'total_amount' => 1800.00], 'id = :i', ['i' => $bid]);
        $row = Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]);
        $pnr = (string) $row['pnr'];
        $adv = (float) $row['advance_discount'];
        check('the sale carries the offer in its own column, beside the total',
            abs($adv - 200.0) < 0.01 && abs((float) $row['total_amount'] - 1800.0) < 0.01,
            $pnr . ' offer ' . inr($adv) . ' total ' . inr((float) $row['total_amount']));

        try {
            $png = Ticket::pngPath($bid, true);
            check('the PNG ticket renders with the offer line', is_file($png) && filesize($png) > 8000,
                basename($png) . ' ' . (is_file($png) ? filesize($png) : 0) . 'b');
        } catch (Throwable $e) {
            check('the PNG ticket renders with the offer line', false, $e->getMessage());
        }
        try {
            $pdf = Ticket::pdfPath($bid, true);
            $raw = (string) file_get_contents($pdf);
            check('the PDF ticket names the offer on its fare line', str_contains($raw, 'Offer'), basename($pdf));
        } catch (Throwable $e) {
            check('the PDF ticket names the offer on its fare line', false, $e->getMessage());
        }
        try {
            $inv = Ticket::invoicePath($bid, true);
            $raw = (string) file_get_contents($inv);
            check('the invoice itemises "Advance booking offer"', str_contains($raw, 'Advance booking offer'), basename($inv));
        } catch (Throwable $e) {
            check('the invoice itemises "Advance booking offer"', false, $e->getMessage());
        }

        $html = $render('booking-view.php', ['pnr' => $pnr]);
        check('booking-view lists the offer as its own row',
            str_contains($html, 'Advance booking offer') && str_contains($html, inr($adv)));

        $html = $render('agent-sales.php', ['agent' => $aid, 'range' => 'month']);
        check('the agent register shows "offer −" under the amount',
            str_contains($html, 'offer −') && str_contains($html, inr($adv)));

        $pl = shg_customer_payload(BookingService::detail($pnr));
        check('My Bookings receives advanceDiscount', abs((float) ($pl['advanceDiscount'] ?? 0) - $adv) < 0.01);
        $js = (string) file_get_contents($ROOT . '/assets/js/08-signin.js');
        check('…and the My Bookings card paints it', str_contains($js, 'b.advanceDiscount') && str_contains($js, "t('aoRow')"));

        $qt = (string) file_get_contents($ROOT . '/admin/quick-ticket.php');
        check('Quick Ticket paints it on the plan card and on the result card',
            str_contains($qt, 'f.advanceDiscount') && str_contains($qt, 'd.advanceDiscount'));
        check('…and the desk\'s sale result carries it',
            str_contains((string) file_get_contents($ROOT . '/includes/quickticket.php'), "'advanceDiscount' => (float) (\$booking['advance_discount']"));
    }

    /* =================================================================
     *  2. "WHAT WOULD THIS JOURNEY ON THIS DATE COST"
     * ================================================================= */
    echo "\n-- 2. the pricing preview box -------------------------------------\n";
    if ($rid <= 0) {
        echo "  \033[33mSKIP\033[0m  no route on this database\n";
    } else {
        $offerOn();
        $html = $render('pricing.php', ['pv_route' => $rid, 'pv_date' => $D, 'pv_mode' => 'sharing', 'pv_seats' => 1]);
        check('the box works a journey out — the four lines',
            str_contains($html, 'id="pvResult"') && str_contains($html, 'Original fare')
            && str_contains($html, 'Discount amount') && str_contains($html, 'Final fare'));
        check('…and shows the advance offer for a date three weeks out',
            str_contains($html, 'Dashain offer') && str_contains($html, 'before departure'));
        $html2 = $render('pricing.php', ['pv_route' => $rid, 'pv_date' => todayISO(), 'pv_mode' => 'sharing', 'pv_seats' => 1]);
        check('…and says why a same-day journey gets none', str_contains($html2, 'No advance offer on this quote'));
        $html3 = $render('pricing.php');
        check('the page without a query shows no result and no error', str_contains($html3, 'id="pvBox"') && !str_contains($html3, 'id="pvResult"'));
    }

    /* =================================================================
     *  3. ONE ROUTE, OR ONE DEPARTURE DATE
     * ================================================================= */
    echo "\n-- 3. an offer narrowed to one route or one date --------------------\n";
    $in25 = date('Y-m-d H:i:s', time() + 25 * 3600);
    $ctx  = ['travelDate' => substr($in25, 0, 10), 'departureTime' => substr($in25, 11), 'bookingMode' => 'sharing'];
    $offerOn();
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctx);
    check('company-wide: a 2200 seat on any route loses 220', (int) $q['advanceDiscount'] === 220, (string) $q['advanceDiscount']);

    Settings::set('advance_offer_route', $rid + 100000, 'int', 'pricing', true);
    Settings::flush();
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctx);
    check('narrowed to another route: this route gets nothing', (int) $q['advanceDiscount'] === 0, (string) $q['advanceWhy']);

    Settings::set('advance_offer_route', $rid, 'int', 'pricing', true);
    Settings::flush();
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctx);
    check('narrowed to THIS route: 220 again', (int) $q['advanceDiscount'] === 220, (string) $q['advanceWhy']);
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', null, $ctx);
    check('a caller that does not name its route gets nothing from a one-route offer', (int) $q['advanceDiscount'] === 0);

    Settings::set('advance_offer_route', 0, 'int', 'pricing', true);
    Settings::set('advance_offer_date', substr($in25, 0, 10), 'string', 'pricing', true);
    Settings::flush();
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctx);
    check('one-date offer: the named departure date gets it', (int) $q['advanceDiscount'] === 220, (string) $q['advanceWhy']);
    $ctxOther = ['travelDate' => date('Y-m-d', time() + 5 * 86400), 'departureTime' => '19:00:00', 'bookingMode' => 'sharing'];
    $q = Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctxOther);
    check('…and another date gets nothing', (int) $q['advanceDiscount'] === 0, (string) $q['advanceWhy']);
    Settings::set('advance_offer_date', '', 'string', 'pricing', true);
    Settings::flush();

    $idx = (string) file_get_contents($ROOT . '/index.php');
    check('the boot payload names the narrowing for the offer card',
        str_contains($idx, "'route'   => \$advRoute") && str_contains($idx, "'date'    => (string) (\$advNow['date']"));
    $pr = (string) file_get_contents($ROOT . '/admin/pricing.php');
    check('the pricing screen has both fields', str_contains($pr, 'name="advance_offer_route"') && str_contains($pr, 'name="advance_offer_date"'));

    /* =================================================================
     *  4. A WHATSAPP CHANGE TELLS THE OTHER ADMIN; NINE TRIES IS TOO MANY
     * ================================================================= */
    echo "\n-- 4. the other authorised number hears; the ninth attempt is refused ---\n";
    Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify', false);
    Settings::set('wa_rules_control', '1', 'bool', 'whatsapp', false);
    Settings::set('wa_rules_numbers', VFIN_A . ',' . VFIN_B, 'string', 'whatsapp', false);
    Settings::flush();
    $asA = ['role' => 'admin', 'adminId' => $aid, 'name' => 'Office A', 'phone' => VFIN_A, 'channel' => 'whatsapp'];

    $before = count(Notify::whatsappJournal());
    $sp = AiRules::propose(['what' => 'setting', 'key' => 'advance_offer_percent', 'value' => 12]);
    $sa = !empty($sp['ok']) ? AiRules::apply($sp['change'], $asA) : ['ok' => false, 'say' => (string) ($sp['why'] ?? '')];
    $j  = array_slice(Notify::whatsappJournal(), $before);
    $toB = array_filter($j, static fn(array $e): bool => str_ends_with((string) $e['to'], substr(VFIN_B, -10)));
    $toA = array_filter($j, static fn(array $e): bool => str_ends_with((string) $e['to'], substr(VFIN_A, -10)));
    check('a change by WhatsApp is announced to the OTHER authorised number',
        !empty($sa['ok']) && count($toB) >= 1, (string) ($sa['say'] ?? '') . ' · journal ' . count($j));
    check('…and not echoed to the one who made it', count($toA) === 0);
    check('notifyOthers() reports one accepted attempt', AiRules::notifyOthers($asA, 'test line') === 1);

    /* the tool keys its bucket on normalisePhone() of the sender, not the raw digits */
    $rlKey = normalisePhone(VFIN_A);
    Database::query("DELETE FROM rate_limits WHERE bucket = 'rules_change' AND identifier = :i", ['i' => $rlKey]);
    $rm = new ReflectionMethod('AiTools', 'rulesChange');
    $rm->setAccessible(true);
    $args    = ['what' => 'setting', 'key' => 'advance_offer_percent', 'value' => 11];
    $okCount = 0;
    $lastSay = '';
    for ($i = 1; $i <= 9; $i++) {
        $r = $rm->invoke(null, $args, $asA);
        if ($i <= 8 && !empty($r['ok'])) {
            $okCount++;
        }
        $lastSay = (string) ($r['say'] ?? '');
    }
    check('eight previews in ten minutes are answered', $okCount === 8, "answered $okCount");
    check('the ninth is refused with a half-hour lockout', str_contains($lastSay, 'Too many fare-change attempts'), $lastSay);
    Database::query("DELETE FROM rate_limits WHERE bucket = 'rules_change' AND identifier = :i", ['i' => $rlKey]);
    try { AiTools::clearStage($rlKey); } catch (Throwable $e) {}

    /* =================================================================
     *  5. THE CEILING, PER BOOKING OR PER PASSENGER
     * ================================================================= */
    echo "\n-- 5. the ceiling counts per booking or per passenger ---------------\n";
    $offerOn();
    Settings::set('advance_offer_max_inr', 100, 'float', 'pricing', true);
    Settings::set('advance_offer_max_per', 'booking', 'string', 'pricing', true);
    Settings::flush();
    $q = Fare::quote(6600, 3, 0, 0, 0, '', '', $rid, $ctx);
    check('per booking: three seats share one ₹100 ceiling', (int) $q['advanceDiscount'] === 100, (string) $q['advanceDiscount']);
    Settings::set('advance_offer_max_per', 'passenger', 'string', 'pricing', true);
    Settings::flush();
    $q = Fare::quote(6600, 3, 0, 0, 0, '', '', $rid, $ctx);
    check('per passenger: three seats keep ₹300', (int) $q['advanceDiscount'] === 300, (string) $q['advanceDiscount']);
    check('a lone passenger is unchanged either way',
        (int) Fare::quote(2200, 1, 0, 0, 0, '', '', $rid, $ctx)['advanceDiscount'] === 100);
    check('the pricing screen offers the choice', str_contains($pr, 'name="advance_offer_max_per"'));
} finally {
    $restore();
    $cleanup();
}

/* =====================================================================
 *  6. GUJARATI
 * ================================================================= */
echo "\n-- 6. Gujarati carries the VIP block, the offer card and the four lines --\n";
$src = (string) file_get_contents($ROOT . '/assets/js/04-i18n.js');
$gu  = null;
if (preg_match('/^I18N\.gu = Object\.assign\(\{\}, I18N\.en, (\{.*\})\);\s*$/m', $src, $m)) {
    $gu = json_decode($m[1], true);
}
check('the Gujarati override is still one valid JSON object', is_array($gu));
foreach (['vipSub', 'vipNote', 'aoBook', 'aoRow', 'rowOrigFare', 'rowDiscPct', 'rowDiscAmt', 'rowFinalFare'] as $k) {
    check("gu carries $k, in Gujarati script",
        is_array($gu) && isset($gu[$k]) && preg_match('/\p{Gujarati}/u', (string) $gu[$k]) === 1,
        is_array($gu) ? mb_substr((string) ($gu[$k] ?? '(missing)'), 0, 30) : '');
}

/* =====================================================================
 *  7. THE COUNTDOWN
 * ================================================================= */
echo "\n-- 7. the offer card counts down, Nepali first ----------------------\n";
$vip = (string) file_get_contents($ROOT . '/assets/js/22-vip.js');
check('the card has a countdown slot', str_contains((string) file_get_contents($ROOT . '/app.template.html'), 'id="aoCount"'));
check('the countdown is written Nepali first',
    str_contains($vip, 'अफर सकिन') && str_contains($vip, 'बाँकी') && strpos($vip, 'अफर सकिन') < strpos($vip, "'left'"));
check('…to the end date the office set', str_contains($vip, 'paintCountdown(o.until)'));
check('…refreshed once a minute', str_contains($vip, 'setInterval(tick, 60000)'));
check('…with a style of its own', str_contains((string) file_get_contents($ROOT . '/assets/css/vip.css'), '.ao-count'));

echo "\n  $PASS passed, $FAIL failed\n\n";
exit($FAIL === 0 ? 0 : 1);
