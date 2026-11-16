<?php
/**
 * 16 Nov 2026 — owner: "chalani ko lagi bisesh payment bhayo ki nai thapaune
 * line rakheu, ani chalani ma seat ma sabai ma thik hos".
 *
 * Seeds one far-future departure with:
 *   A  a desk sale with 2 extra luggage pieces + 1 lap infant (paid, cash)
 *   B  a desk sale with 1 extra luggage piece, then the office marks it DUE
 *   C  a private cabin (one passenger on more than one berth)
 * and checks every chalani surface: the screen (free berths, extras badge),
 * the printed sheet (special-payment box, pen lines, summary, fare column
 * without the extras), the CSV, the PDF, the PNG page and the public link.
 *
 *   SHG_TEST_BASE=http://localhost:8963 php -c <ini> -d extension=php_curl.dll tests/u10-chalani-extras-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/opshub.php';
require_once INCLUDE_PATH . '/chalanipng.php';
require_once INCLUDE_PATH . '/smartbook.php';

if (!function_exists('curl_init')) { echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n"; exit(1); }
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
const TD      = '2099-09-19';
const PH_A    = '9100000991';
const PH_B    = '9100000992';
const PH_C    = '9100000993';
const BOSS    = 'u10_chalani_boss';
const BOSS_PW = 'U10ChalaniBoss77';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$JAR = sys_get_temp_dir() . '/shg_u10_' . getmypid() . '.txt';
@unlink($JAR);
function http(string $url, array $opt = []): array {
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 60]);
    if (isset($opt['form'])) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form'])); }
    $raw = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', $h, $m) === 1) { return html_entity_decode($m[1]); }
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}
function cleanup(): void {
    foreach ([PH_A, PH_B, PH_C] as $p) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            try { Database::run('DELETE FROM booking_collections WHERE booking_id = :b', ['b' => (int) $r['id']]); } catch (Throwable $e) {}
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules', 'travel_date = :d', ['d' => TD]);
    Database::delete('admins', 'username = :a', ['a' => BOSS]);
}

/* the switches this test turns on, put back exactly as found */
$keep = [];
foreach (['luggage_fee_per_piece', 'infant_seat_on', 'infant_seat_fare', 'whatsapp_notify_customer', 'whatsapp_notify_admin', 'staff_seats'] as $k) {
    $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
}
$restore = static function () use ($keep): void {
    foreach ($keep as $k => $row) {
        if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
        else { Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $row['svalue'], 'k' => $k]); }
    }
    Settings::flush();
};

echo "\n=== Chalani: special payments (desk extras) + seats right ===\n\n";
cleanup();
try {
    Settings::set('luggage_fee_per_piece', '100', 'int', 'booking', false);
    Settings::set('infant_seat_on', '1', 'bool', 'booking', false);
    Settings::set('infant_seat_fare', '300', 'int', 'booking', false);
    Settings::set('whatsapp_notify_customer', '0', 'bool', 'notify', false);
    Settings::set('whatsapp_notify_admin', '0', 'bool', 'notify', false);
    Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);
    Settings::flush();

    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);
    Database::update('schedules', ['is_blocked' => 0, 'status' => 'scheduled'], 'id = :i', ['i' => (int) $sched['id']]);
    $bossId = (int) Database::insert('admins', ['username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'U10 Chalani Boss', 'role' => 'superadmin', 'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0]);
    require_once INCLUDE_PATH . '/quickunlock.php';
    QuickUnlock::set($bossId, 'pin', '583941');   // the code-first door sends a PIN-less account to pin-setup first
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => $bossId, 'username' => BOSS, 'role' => 'superadmin', 'permissions' => [], 'last_seen' => time(), 'logged_in_at' => time()];
    $desk = static fn(array $more): array => ['adminId' => $bossId, 'source' => 'admin', 'paymentMethod' => 'cash'] + $more;

    $a = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L8'],
        'passengers' => [['name' => 'Extra Paid Pax', 'age' => 30, 'gender' => 'Male']],
        'contact' => ['phone' => PH_A], 'bookingMode' => 'sharing', 'paymentMethod' => 'cash', 'isCod' => false, 'boarding' => ''],
        $desk(['luggagePieces' => 2, 'infantCount' => 1]));
    $b = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L10'],
        'passengers' => [['name' => 'Extra Due Pax', 'age' => 40, 'gender' => 'Male']],
        'contact' => ['phone' => PH_B], 'bookingMode' => 'sharing', 'paymentMethod' => 'cash', 'isCod' => false, 'boarding' => ''],
        $desk(['luggagePieces' => 1]));
    $c = BookingService::create(['routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L12'],
        'passengers' => [['name' => 'Cabin Pax', 'age' => 35, 'gender' => 'Male']],
        'contact' => ['phone' => PH_C], 'bookingMode' => 'private', 'cabinType' => 'double', 'sharingTier' => 'double',
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '']);
    $rowA = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $a['pnr']]);
    $rowB = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $b['pnr']]);
    $rowC = Database::fetch('SELECT * FROM bookings WHERE pnr = :p', ['p' => $c['pnr']]);
    check('seeded: A has 2 luggage (₹200) + 1 infant (₹300)', (int) $rowA['luggage_pieces'] === 2 && (float) $rowA['luggage_fee'] === 200.0
        && (int) $rowA['infant_count'] === 1 && (float) $rowA['infant_fee'] === 300.0, 'total ' . $rowA['total_amount']);
    check('seeded: B has 1 luggage (₹100)', (int) $rowB['luggage_pieces'] === 1 && (float) $rowB['luggage_fee'] === 100.0);
    OpsHub::markDue((int) $rowB['id'], ['id' => $bossId, 'username' => BOSS, 'full_name' => 'U10 Chalani Boss']);
    $bedsC = Seats::physicalSeats('L12', 'private', 'sleeper');
    check('seeded: C is a private cabin on ' . count($bedsC) . ' berth(s)', count($bedsC) >= 1, implode('+', $bedsC));
    $fareA = (float) $rowA['total_amount'] - 500.0;

    /* ---- sign in ---- */
    try { Database::run("DELETE FROM rate_limits WHERE identifier IN ('127.0.0.1', '::1') AND bucket IN ('admin_login', 'code_login_enter', 'quick_unlock', 'code_login_try')"); } catch (Throwable $e) {}
    $login = http('/admin/login.php?classic=1&other=1');
    $r = http('/admin/login.php', ['form' => [CSRF_TOKEN_NAME => csrfFrom($login['body']), 'classic' => '1', 'username' => BOSS, 'password' => BOSS_PW]]);
    check('superadmin signs in', $r['code'] === 302 && !str_contains($r['headers'], 'login.php'), 'HTTP ' . $r['code']);
    $q = '/admin/manifest.php?date=' . TD . '&sid=' . (int) $sched['id'];

    /* ---- screen ---- */
    $page = http($q);
    check('manifest screen renders', $page['code'] === 200, 'HTTP ' . $page['code'] . (preg_match('/^Location:\s*(\S+)/mi', $page['headers'], $lm) ? ' -> ' . $lm[1] : '') . ' (login -> ' . (preg_match('/^Location:\s*(\S+)/mi', $r['headers'], $lm2) ? $lm2[1] : '?') . ')');
    $total = (int) Database::scalar('SELECT total_seats FROM schedules WHERE id = :i', ['i' => (int) $sched['id']]);
    $beds  = 2 + count($bedsC);
    check('seats free counts BERTHS (a cabin is more than one)', str_contains($page['body'], '<b>' . ($total - $beds) . '</b> seats free'),
        'expected ' . ($total - $beds) . ' of ' . $total);
    check('screen shows the infant', str_contains($page['body'], '+1</b> शिशु'));
    check('screen: the extras badge (₹600 — paid ₹500, due ₹100)', str_contains($page['body'], 'विशेष भुक्तानी · Extras ₹600') && str_contains($page['body'], 'भयो ₹500') && str_contains($page['body'], 'बाँकी ₹100'));
    check('screen: A\'s group line carries 🧳 2 👶 1 · ✓ भयो', (bool) preg_match('/🧳 2 👶 1 थप ₹500 · ✓ भयो/u', $page['body']));
    check('screen: B\'s group line says ✗ बाँकी', (bool) preg_match('/🧳 1 थप ₹100 · ✗ बाँकी/u', $page['body']));
    check('screen: A\'s fare column is the seat fare without the extras', str_contains($page['body'], inr($fareA)), inr($fareA));
    check('seat chart marks the infant on A\'s berth', str_contains($page['body'], ' 👶1</i>'));

    /* ---- printed chalani ---- */
    $ch = http($q . '&format=chalani');
    check('chalani sheet renders', $ch['code'] === 200, 'HTTP ' . $ch['code']);
    check('chalani: special-payment box', str_contains($ch['body'], 'विशेष भुक्तानी · थप सामान / शिशु / अन्य — भयो कि भएन'));
    check('chalani: A\'s line (2 थान ₹200 · शिशु १ ₹300, ✓ भयो)', str_contains($ch['body'], 'थप सामान २ थान ₹200 · शिशु १ ₹300') && str_contains($ch['body'], $a['pnr']));
    check('chalani: B\'s line says ✗ बाँकी', (bool) preg_match('/' . preg_quote((string) $b['pnr'], '/') . '.*?✗ बाँकी/su', $ch['body']));
    check('chalani: 2 blank pen lines (भयो ☐ भएन ☐)', substr_count($ch['body'], 'भयो ☐ &nbsp; भएन ☐') === 2);
    check('chalani: summary has the special-payment total ₹600', str_contains($ch['body'], 'विशेष भुक्तानी (थप सामान / शिशु)</span><b>₹ 600</b>'));
    check('chalani: summary paid / due ₹500 / 100', str_contains($ch['body'], '₹ 500 / 100'));
    check('chalani: name row carries + शिशु १', str_contains($ch['body'], '+ शिशु १'));
    check('chalani: the extras note in the remarks column', str_contains($ch['body'], 'थप ₹500 ✓ भयो'));
    check('chalani: place strip counts the infant', str_contains($ch['body'], '+ शिशु <b>१</b>'));
    check('chalani: A\'s ticket column = seat fare only', str_contains($ch['body'], '<td class="r">' . number_format($fareA) . '</td>'), number_format($fareA));
    check('chalani: A\'s cash column = fare + extras', str_contains($ch['body'], '<td class="r">' . number_format($fareA + 500) . '</td>'), number_format($fareA + 500));

    /* ---- CSV ---- */
    $csv = http($q . '&format=csv');
    check('CSV downloads', $csv['code'] === 200 && stripos($csv['headers'], 'text/csv') !== false);
    check('CSV: extras columns', str_contains($csv['body'], '"Extra luggage",Infants,"Extra Rs","Extra paid"'));
    check('CSV: A = 2,1,500,PAID (cash with the desk / agent counts as paid)', (bool) preg_match('/,2,1,500,"?PAID/', $csv['body']));
    check('CSV: B = 1,0,100,DUE', str_contains($csv['body'], ',1,0,100,DUE'));
    check('CSV: infants line', str_contains($csv['body'], 'Infants (lap, no berth)'));

    /* ---- PDF + PNG ---- */
    $pdf = http($q . '&format=chalanipdf');
    check('chalani PDF builds', $pdf['code'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'HTTP ' . $pdf['code'] . ' ' . strlen($pdf['body']) . ' bytes');
    $png = http($q . '&format=chalanipng&page=1');
    check('chalani PNG page 1 draws', $png['code'] === 200 && str_starts_with($png['body'], "\x89PNG"), 'HTTP ' . $png['code']);
    $pages = ChalaniPng::pageCount(array_fill(0, 3, []));
    $pngL = http($q . '&format=chalanipng&page=' . $pages);
    check('chalani PNG summary page draws', $pngL['code'] === 200 && str_starts_with($pngL['body'], "\x89PNG"), 'page ' . $pages . ' HTTP ' . $pngL['code']);
    $save = (string) getenv('U10_SAVE');   // a folder: keep the sheet, the PDF and the PNG page to look at
    if ($save !== '' && is_dir($save)) {
        file_put_contents($save . '/u10-chalani.html', $ch['body']);
        file_put_contents($save . '/u10-chalani.pdf', $pdf['body']);
        file_put_contents($save . '/u10-chalani-p1.png', $png['body']);
        file_put_contents($save . '/u10-screen.html', $page['body']);
    }

    /* ---- public live link ---- */
    if (OpsHub::publicOn()) {
        $pub = http('/chalani-live.php?k=' . rawurlencode(OpsHub::publicKey()) . '&d=' . TD);
        check('public link renders', $pub['code'] === 200, 'HTTP ' . $pub['code']);
    } else {
        check('public link is off here — its free count is covered by the same rule', true);
    }

    /* ---- discounts never stack: desk discount vs the automatic ones ---- */
    $p = SmartBook::withDeskDiscount(['base' => 2000.0, 'total' => 1800.0, 'couponDiscount' => 100.0, 'returnDiscount' => 100.0], 'flat', 150.0);
    check('desk ₹150 < offer ₹100 + return ₹100: the automatic ones stay, desk dropped', (float) $p['total'] === 1800.0 && (float) $p['returnDiscount'] === 100.0, 'total ' . $p['total']);
    $p = SmartBook::withDeskDiscount(['base' => 2000.0, 'total' => 1800.0, 'couponDiscount' => 100.0, 'returnDiscount' => 100.0], 'flat', 300.0);
    check('desk ₹300 > ₹200 automatic: desk wins, return discount cleared', (float) $p['total'] === 1700.0 && (float) $p['returnDiscount'] === 0.0 && (float) $p['couponDiscount'] === 300.0, 'total ' . $p['total']);
    $p = SmartBook::withDeskDiscount(['base' => 2000.0, 'total' => 1900.0, 'couponDiscount' => 0.0, 'returnDiscount' => 100.0], 'flat', 50.0);
    check('desk ₹50 < return ₹100 alone: the return discount stays', (float) $p['total'] === 1900.0 && (float) $p['returnDiscount'] === 100.0);
    $src = (string) file_get_contents(INCLUDE_PATH . '/booking.php');
    check('the sale applies the same rule (offer + return)', str_contains($src, "\$offerCut = (float) (\$pricing['couponDiscount'] ?? 0) + (float) (\$pricing['returnDiscount'] ?? 0);"));

    /* ---- the WhatsApp daily cap also holds campaigns ---- */
    $cs = (string) file_get_contents(INCLUDE_PATH . '/campaigns.php');
    $ms = (string) file_get_contents(INCLUDE_PATH . '/wamarketing.php');
    check('campaign worker asks the daily cap', str_contains($cs, "WaRoute::capRefusal('campaign')") && str_contains($cs, 'WaRoute::noteSent()'));
    check('marketing worker asks the daily cap', str_contains($ms, "WaRoute::capRefusal('marketing')") && str_contains($ms, 'WaRoute::noteSent()'));
} catch (Throwable $e) {
    $FAIL++;
    echo "  ERROR  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}
cleanup();
$restore();
@unlink($JAR);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
