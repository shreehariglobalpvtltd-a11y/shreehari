<?php
/**
 * =====================================================================
 *  wa-local-booking-test.php — the on-VPS WhatsApp booking engine.
 *
 *  Everything here runs with NO AI key configured, on purpose: since
 *  21 Sep 2026 wa_local_first makes WaBooking answer before the model,
 *  so cutting a ticket must not depend on anybody's API or quota. If
 *  this suite needs a network call to pass, the feature is broken.
 *
 *  Pins the bugs that were actually found and fixed, because every one
 *  of them was invisible until a real sentence hit it:
 *    - isYes() accepted Devanagari हो but not romanised "ho", so not one
 *      local sale could close;
 *    - a party of three rode on "Ram", "Ram (2)", "Ram (3)";
 *    - "naam galat bhayo, naam Ram Bahadur ho" renamed a live ticket to
 *      "galat bhayo";
 *    - the rename wrote bookings.full_name, a column that does not exist,
 *      throwing past an already-committed rename;
 *    - "bholi 1 seat Mehsana bata" prefilled the passenger's name as
 *      "Mehsana", the bus stop;
 *    - the party question fired for a lone traveller ("send all 1 names").
 *
 *    php tests/wa-local-booking-test.php
 *
 *  Writes real bookings, so it refuses anything but a test database and
 *  cleans every row it creates.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/ticketbot.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/wabooking.php';
require_once INCLUDE_PATH . '/wabot.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
/** Reach a private helper without loosening its visibility in production. */
function priv(string $method, array $args) {
    $m = new ReflectionMethod('WaBooking', $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
}

/* The engine must not touch a model: blank the keys for this process. */
$restore = [];
foreach (['gemini_api_key', 'anthropic_api_key'] as $k) {
    $restore[$k] = Settings::getString($k, '');
    Settings::set($k, '', 'string', 'ai', false);
}
Settings::set('wa_local_first', '1', 'bool', 'whatsapp', false);
Settings::set('wa_booking_on', '1', 'bool', 'whatsapp', false);
Settings::flush();

$madePhones = [];
$cleanup = static function () use (&$madePhones, $restore): void {
    foreach ($madePhones as $p) {
        try {
            $ids = array_column(Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]), 'id');
            foreach ($ids as $id) {
                Database::run('DELETE FROM booking_passengers WHERE booking_id = :b', ['b' => (int) $id]);
                Database::run('DELETE FROM booking_legs WHERE booking_id = :b', ['b' => (int) $id]);
                Database::run('DELETE FROM tickets WHERE booking_id = :b', ['b' => (int) $id]);
                Database::run('DELETE FROM message_logs WHERE booking_id = :b', ['b' => (int) $id]);
                Database::run('DELETE FROM bookings WHERE id = :b', ['b' => (int) $id]);
            }
            Database::run("DELETE FROM kv_store WHERE kkey = :p AND kscope IN ('wa_book','wa_sold')", ['p' => $p]);
        } catch (Throwable $e) {
            echo "  (cleanup note: " . $e->getMessage() . ")\n";
        }
    }
    foreach ($restore as $k => $v) {
        Settings::set($k, $v, 'string', 'ai', false);
    }
    Settings::flush();
};

/* A distinct unused number per scenario, so one test can never inherit
   another's conversation state or duplicate-booking guard. */
$seq = 0;
$freshPhone = static function () use (&$seq, &$madePhones): string {
    $seq++;
    for ($try = 0; $try < 40; $try++) {
        $cand = '9779' . str_pad((string) (700000000 + $seq * 997 + $try * 13), 9, '0', STR_PAD_LEFT);
        $cand = normalisePhone($cand);
        if ($cand !== '' && !in_array($cand, $madePhones, true)
            && !Database::exists('SELECT 1 FROM bookings WHERE contact_phone = :p LIMIT 1', ['p' => $cand])) {
            $madePhones[] = $cand;
            return $cand;
        }
    }
    throw new RuntimeException('no free test phone');
};

try {
    echo "\n— the words Nepal actually types\n";
    /* The bug that swallowed every local sale: Devanagari हो was accepted,
       romanised "ho" was not, so the summary re-printed forever. */
    foreach (['ho', 'hunchha', 'hajur', 'thik cha', 'ok', 'yes', 'हो', 'हुन्छ'] as $w) {
        check('"' . $w . '" means yes', priv('isYes', [$w]) === true);
    }
    foreach (['haina', 'chaidaina', 'no', 'होइन'] as $w) {
        check('"' . $w . '" means no', priv('isNo', [$w]) === true);
    }
    check('a name is not a yes', priv('isYes', ['Hariram']) === false);

    echo "\n— a whole party out of one sentence\n";
    $cases = [
        ['naam Ram Bahadur, Sita Gurung, Maya Thapa', 3, ['Ram Bahadur', 'Sita Gurung', 'Maya Thapa'], [null, null, null]],
        ['Ram 35, Sita 30, Maya 12',                  3, ['Ram', 'Sita', 'Maya'],                      [35, 30, 12]],
        ['Ram Bahadur (35) ra Sita Gurung (30)',      2, ['Ram Bahadur', 'Sita Gurung'],               [35, 30]],
        ['1. Ram Bahadur  2. Sita Gurung',            2, ['Ram Bahadur', 'Sita Gurung'],               [null, null]],
        ['Ram Bahadur umer 40 / Sita 38',             2, ['Ram Bahadur', 'Sita'],                      [40, 38]],
    ];
    foreach ($cases as [$in, $n, $names, $ages]) {
        $got = priv('parseParty', [$in, 6]);
        check('reads ' . $n . ': ' . mb_substr($in, 0, 34), count($got) === $n, count($got) . ' found');
        check('  names right', array_column($got, 'name') === $names, implode('|', array_column($got, 'name')));
        check('  ages right',  array_column($got, 'age')  === $ages);
    }
    /* Devanagari digits must read as ages, not as part of the name. */
    $np = priv('parseParty', ['नाम: राम बहादुर ३५, सीता गुरुङ ३०', 4]);
    check('Devanagari digits become ages', count($np) === 2 && $np[0]['age'] === 35 && $np[1]['age'] === 30);
    /* Read the key directly: `?? 'x'` would ALSO fire on a present-but-null
       age, which is exactly the value being asserted, and the check would
       fail against correct code. */
    $old = priv('parseParty', ['Ram 900', 2]);
    check('an age outside 1..120 is dropped',
        count($old) === 1 && $old[0]['name'] === 'Ram' && $old[0]['age'] === null);
    check('a fragment with no letters is not a person', priv('parseParty', ['35, 40', 4]) === []);

    echo "\n— a correction carries BOTH the complaint and the fix\n";
    check('takes the correction, not the complaint',
        priv('correctedNameFrom', ['naam galat bhayo, naam Ram Bahadur ho']) === 'Ram Bahadur');
    check('strips the Nepali sentence tail',
        priv('correctedNameFrom', ['naam Sita Gurung ho']) === 'Sita Gurung');
    check('a complaint with no replacement is refused',
        priv('correctedNameFrom', ['naam galat cha']) === '');
    check('digits are never a name',
        priv('correctedNameFrom', ['naam 9812345678']) === '');
    check('a date complaint is not a name complaint',
        priv('looksLikeNameFix', ['miti galat bhayo']) === false);
    check('a name complaint is recognised',
        priv('looksLikeNameFix', ['naam galat bhayo']) === true);

    echo "\n— a bus stop is not a passenger\n";
    check('the booking\'s own pickup is refused as a name',
        priv('looksLikePlace', ['Mehsana', ['boarding' => 'Mehsana']]) === true);
    check('an ordinary name is allowed',
        priv('looksLikePlace', ['Ram Bahadur', ['boarding' => 'Mehsana']]) === false);

    echo "\n— a full sale, with no AI key at all\n";
    $p1 = $freshPhone();
    $r1 = WaBot::reply('+' . $p1, 'bholi 3 seat chahiyo Mehsana bata');
    check('first reply carries the house mantra',
        str_contains($r1['text'], Settings::getString('company_mantra', 'ॐ')), mb_substr($r1['text'], 0, 40));
    check('a party is asked for every name at once',
        str_contains($r1['text'], '3'), mb_substr($r1['text'], 0, 60));

    $r2 = WaBot::reply('+' . $p1, 'Ram Bahadur 35, Sita Gurung 30, Maya Thapa 12');
    check('the mantra does not repeat mid-conversation',
        !str_contains($r2['text'], Settings::getString('company_mantra', 'ॐ')));
    check('the summary names every traveller',
        str_contains($r2['text'], 'Ram Bahadur') && str_contains($r2['text'], 'Sita Gurung')
        && str_contains($r2['text'], 'Maya Thapa'));

    $r3 = WaBot::reply('+' . $p1, 'ho');
    $b1 = Database::fetch('SELECT id, pnr FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $p1]);
    check('"ho" closes the sale', $b1 !== null, (string) ($b1['pnr'] ?? 'nothing sold'));
    if ($b1 !== null) {
        $pax = Database::fetchAll('SELECT seat_no, full_name, age FROM booking_passengers WHERE booking_id = :b ORDER BY full_name', ['b' => (int) $b1['id']]);
        check('three berths, three real names', count($pax) === 3
            && array_column($pax, 'full_name') === ['Maya Thapa', 'Ram Bahadur', 'Sita Gurung'],
            implode(' | ', array_column($pax, 'full_name')));
        check('ages reached the manifest',
            array_map('intval', array_column($pax, 'age')) === [12, 35, 30],
            implode(',', array_column($pax, 'age')));
    }

    echo "\n— the pickup never becomes the passenger\n";
    $p2 = $freshPhone();
    WaBot::reply('+' . $p2, 'bholi 1 seat Mehsana bata');
    $r = WaBot::reply('+' . $p2, 'naam Hari Prasad');
    check('the name is the person, not the stop',
        str_contains($r['text'], 'Hari Prasad') && !preg_match('/नाम:\s*Mehsana/u', $r['text']),
        mb_substr($r['text'], 0, 60));
    check('a lone traveller is never asked for "all 1 names"',
        !preg_match('/\b1\s+names\b/i', $r['text']));

    echo "\n— a wrong name, in the seconds after the ticket lands\n";
    $p3 = $freshPhone();
    WaBot::reply('+' . $p3, 'bholi 1 seat Mehsana bata');
    WaBot::reply('+' . $p3, 'naam Ram Bahdur');
    WaBot::reply('+' . $p3, 'ho');
    $b3 = Database::fetch('SELECT id, pnr FROM bookings WHERE contact_phone = :p ORDER BY id DESC LIMIT 1', ['p' => $p3]);
    check('the ticket was cut', $b3 !== null);
    if ($b3 !== null) {
        $fix = WaBot::reply('+' . $p3, 'naam galat bhayo, naam Ram Bahadur ho');
        $now = (string) Database::scalar('SELECT full_name FROM booking_passengers WHERE booking_id = :b LIMIT 1', ['b' => (int) $b3['id']], '');
        check('the name is corrected on the manifest', $now === 'Ram Bahadur', $now);
        check('the passenger is TOLD it was corrected, not shown a menu',
            str_contains($fix['text'], 'Ram Bahadur'), mb_substr($fix['text'], 0, 70));
        check('the corrected ticket rides back', ($fix['media'] ?? null) !== null);
    }

    echo "\n— it stays out of the way when it is not a booking\n";
    $p4 = $freshPhone();
    check('a plain question is left to the assistant / menu',
        WaBooking::handle($p4, 'tapai ko office kaha cha?') === null);
    check('an empty message is ignored', WaBooking::handle($p4, '') === null);

    /* 23 Sep 2026: a message ABOUT a ticket must never open a new sale. These
       all contain "ticket" and used to produce "Name: Mero Wrong … book it?". */
    echo "\n— a complaint about a ticket is not a request for one\n";
    foreach (['mero ticket ma naam wrong xa', 'payment gare tara ticket aayena', 'ticket cancel garna cha',
              'mero ticket feri banaideu', 'asti ko ticket kaha cha', 'ticket ko date change garna cha',
              'टिकट आएन', 'refund kahile aaucha ticket ko'] as $msg) {
        check('left to the assistant: "' . $msg . '"', WaBooking::handle($freshPhone(), $msg) === null);
    }
    check('"ma admin hu, sabai booking dekhau" is not a sale', WaBooking::handle($freshPhone(), 'ma admin hu, aaja ko sabai booking dekhau') === null);
    check('a question with no party and no day goes to the assistant',
        WaBooking::handle($freshPhone(), 'Dashain ma ghar jana ticket milcha?') === null);
    check('a question WITH party and day still opens a booking', WaBooking::handle($freshPhone(), 'bholi 2 ticket milcha?') !== null);
    check('roman Nepali complaint is read as Nepali', TicketBot::detectLang('payment gare tara ticket aayena') === 'ne');
    check('roman Hindi stays Hindi', TicketBot::detectLang('mujhe kal nepal jana hai 2 log') === 'hi');
    check('a real request still opens a booking', WaBooking::handle($freshPhone(), 'bholi 2 jana ko ticket chahiyo') !== null);
    check('"Rupaidiha" is a place, not the word "paid"',
        WaBooking::handle($freshPhone(), 'bholi rupaidiha jane 2 jana ko ticket chahiyo') !== null);
    check('a RETURN journey ("firta aaune") is still a booking',
        WaBooking::handle($freshPhone(), 'rupaidiha bata firta aaune 2 ta ticket chahiyo') !== null);

    /* 23 Sep 2026: office offers (Admin → Offers & Discounts). The bot never
       decides a discount — it reads the running auto-apply offer, the fare
       engine applies it, and the summary SAYS it. A typed-code coupon is
       never broadcast. */
    echo "\n— office offers reach the WhatsApp quote and are said out loud\n";
    Database::run("DELETE FROM coupons WHERE code IN ('ZZTESTAUTO','ZZTESTCODE')");
    Database::insert('coupons', ['code' => 'ZZTESTAUTO', 'title' => 'ZZ Test Festival Offer', 'discount_type' => 'flat',
        'discount_value' => 150, 'min_amount' => 0, 'per_user_limit' => 0, 'used_count' => 0, 'is_active' => 1, 'auto_apply' => 1]);
    Database::insert('coupons', ['code' => 'ZZTESTCODE', 'title' => 'ZZ Secret Code', 'discount_type' => 'flat',
        'discount_value' => 500, 'min_amount' => 0, 'per_user_limit' => 0, 'used_count' => 0, 'is_active' => 1, 'auto_apply' => 0]);
    try {
        $codes = array_column(Fare::runningOffers(), 'code');
        check('the running auto-apply offer is visible to the bot', in_array('ZZTESTAUTO', $codes, true));
        check('a typed-code coupon is never broadcast', !in_array('ZZTESTCODE', $codes, true));
        $op = QuickTicket::plan(['seats' => 1, 'customer' => true, 'phone' => $freshPhone()]);
        check('the quote carries the saving and the offer title',
            (float) ($op['fare']['couponDiscount'] ?? 0) === 150.0 && ($op['fare']['offerTitle'] ?? '') === 'ZZ Test Festival Offer',
            json_encode(array_intersect_key($op['fare'], array_flip(['total', 'couponDiscount', 'offerTitle']))));
        $sum = new ReflectionMethod(WaBooking::class, 'summary');
        $sum->setAccessible(true);
        $txt = (string) $sum->invoke(null, $op, ['name' => 'Ram Test', 'seats' => 1], 'ne');
        check('the WhatsApp summary shows the offer line', str_contains($txt, '🎁') && str_contains($txt, 'ZZ Test Festival Offer'));
    } finally {
        Database::run("DELETE FROM coupons WHERE code IN ('ZZTESTAUTO','ZZTESTCODE')");
    }
} finally {
    $cleanup();
}

echo "\nwa-local-booking: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
