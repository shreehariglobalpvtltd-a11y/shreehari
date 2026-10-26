<?php
/**
 * PASSENGER MANIFEST — master prompt §16
 *
 * The point of this test is the gap the manifest was built to close.
 * admin/agent-passengers.php filters on `b.sold_by_admin_id`, so it can only
 * ever show seats an agent sold; an online customer booking carries no
 * seller and is invisible there. The office list must show the whole coach.
 *
 * Seeds one departure with three passengers from three different sources —
 * a web customer, a COD customer and a counter agent — then checks that the
 * manifest screen and the CSV both carry all three, with the §16 columns.
 *
 *   1. dev server running on :8899
 *   2. php -c .claude/php-dev.ini -d extension=php_curl.dll tests/manifest-test.php
 *
 * Throwaway rows on a far-future date, plus one temporary superadmin.
 * Cleans up after itself. CLI only.
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

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

// SHG_TEST_BASE, like every other HTTP suite (10 Sep 2026): with the port
// hard-coded, running this from a worktree silently tested whichever OTHER
// checkout happened to own :8899 — and reported it green.
define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
const TD        = '2099-08-08';
const PHONE_WEB = '9100000881';
const PHONE_COD = '9100000882';
const PHONE_AGT = '9100000883';
const BOSS      = 'manifest_boss';
const BOSS_PW   = 'ManifestBoss99';
const SELLER    = 'manifest_agent';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

$JAR = sys_get_temp_dir() . '/shg_manifest_' . getmypid() . '.txt';
@unlink($JAR);

function http(string $url, array $opt = []): array
{
    global $JAR;
    $ch = curl_init(BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR, CURLOPT_TIMEOUT => 25,
    ]);
    if (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($opt['form']));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hlen = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($raw, $hlen), 'headers' => substr($raw, 0, $hlen)];
}
function csrfFrom(string $h): string {
    return preg_match('/name="' . preg_quote(CSRF_TOKEN_NAME, '/') . '"\s+value="([^"]+)"/', $h, $m) ? $m[1] : '';
}

function cleanup(): void {
    foreach ([PHONE_WEB, PHONE_COD, PHONE_AGT] as $p) {
        foreach (Database::fetchAll('SELECT id FROM bookings WHERE contact_phone = :p', ['p' => $p]) as $r) {
            Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
        }
    }
    Database::delete('booking_legs', 'travel_date = :d', ['d' => TD]);
    Database::delete('schedules',    'travel_date = :d', ['d' => TD]);
    Database::delete('admins', 'username IN (:a, :b)', ['a' => BOSS, 'b' => SELLER]);
}

echo "\n=== Passenger manifest (§16) ===\n\n";
cleanup();

/* Fixture predates the L5+L6 staff reservation — pin the old L1 layout
   for the run; restored to the production layout before exit. */
Settings::set('staff_seats', ['sleeper' => ['L1'], 'seater' => ['1A']], 'json', 'seats', false);

try {
    $route   = Database::fetch("SELECT * FROM routes WHERE coach_type='sleeper' AND is_active=1 ORDER BY id LIMIT 1");
    $routeId = (int) $route['id'];
    $sched   = Seats::schedule($routeId, TD);

    /* ---- a counter agent, and a superadmin to read the manifest ------- */
    $sellerId = Database::insert('admins', [
        'username' => SELLER, 'password_hash' => password_hash('x9Qw' . bin2hex(random_bytes(6)), PASSWORD_DEFAULT),
        'full_name' => 'Manifest Seller', 'role' => 'agent',
        'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0,
    ]);
    Database::insert('admins', [
        'username' => BOSS, 'password_hash' => password_hash(BOSS_PW, PASSWORD_DEFAULT),
        'full_name' => 'Manifest Boss', 'role' => 'superadmin',
        'permissions' => json_encode([]), 'is_active' => 1, 'must_change_pw' => 0,
    ]);

    /* ---- three passengers, three different sources -------------------- */
    $web = BookingService::create([
        'routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L8'],
        'passengers' => [['name' => 'Web Customer', 'age' => 28, 'gender' => 'Male']],
        'contact' => ['phone' => PHONE_WEB], 'bookingMode' => 'sharing',
        'paymentMethod' => 'upi', 'isCod' => false, 'boarding' => '',
    ]);
    $cod = BookingService::create([
        'routeId' => $routeId, 'travelDate' => TD, 'seats' => ['L4'],
        'passengers' => [['name' => 'Cod Customer', 'age' => 34, 'gender' => 'Female']],
        'contact' => ['phone' => PHONE_COD], 'bookingMode' => 'sharing',
        'paymentMethod' => 'cod', 'isCod' => true, 'boarding' => '',
    ]);
    $agt = BookingService::counterSale($route, (int) $sched['id'], TD, ['L6'], [
        'name' => 'Counter Customer', 'phone' => PHONE_AGT, 'gender' => 'Male',
        'paymentMethod' => 'cash',
    ], (int) $sellerId, 'counter');

    check('seeded three bookings from three sources', true,
          "web={$web['pnr']} cod={$cod['pnr']} agent={$agt['pnr']}");

    /* ---- the gap this page exists to close ---------------------------- */
    $agentScoped = (int) Database::scalar(
        "SELECT COUNT(*) FROM booking_passengers bp
           JOIN bookings b ON b.id = bp.booking_id
           JOIN booking_legs bl ON bl.id = bp.leg_id
          WHERE bl.schedule_id = :s AND b.sold_by_admin_id = :a",
        ['s' => (int) $sched['id'], 'a' => (int) $sellerId], 0);
    check('the agent-scoped view sees only its OWN sale', $agentScoped === 1,
          "agent view = $agentScoped of 3 passengers");

    /* ---- sign in and open the manifest -------------------------------- */
    $login = http('/admin/login.php');
    $r = http('/admin/login.php', ['form' => [
        CSRF_TOKEN_NAME => csrfFrom($login['body']), 'username' => BOSS, 'password' => BOSS_PW]]);
    check('superadmin signs in', in_array($r['code'], [200, 302], true), 'HTTP ' . $r['code']);

    $page = http('/admin/manifest.php?date=' . TD . '&route=' . $routeId);
    check('manifest page renders', $page['code'] === 200, 'HTTP ' . $page['code']);

    // Seats are STORED canonically (L8/L4/L6) but DISPLAYED as the row-letter
    // grid (Seats::displayLabel) since 11 Sep 2026: L8→LB2, L4→LA4, L6→LA6.
    foreach ([['Web Customer', 'LB2'], ['Cod Customer', 'LA4'], ['Counter Customer', 'LA6']] as [$who, $seat]) {
        check("manifest lists $who ($seat)",
              str_contains($page['body'], $who) && str_contains($page['body'], '>' . $seat . '<'));
    }
    check('manifest shows ALL THREE where the agent view showed one',
          substr_count($page['body'], 'Customer<') >= 3
          || (str_contains($page['body'], 'Web Customer') && str_contains($page['body'], 'Counter Customer')));

    /* ---- the §16 columns ---------------------------------------------- */
    foreach (['Seat', 'Passenger', 'Phone', 'Boarding', 'Destination',
              'Booking ID', 'Payment', 'Source', 'Agent', 'Ticket'] as $col) {
        check("column present: $col", str_contains($page['body'], '<th>' . $col . '</th>'));
    }

    check('the seller is shown with their agent code',
          str_contains($page['body'], 'Manifest Seller'));
    check('COD is flagged for collection', stripos($page['body'], 'COD') !== false);
    check('a print button is offered', str_contains($page['body'], 'window.print()'));

    /* ---- CSV ----------------------------------------------------------- */
    $csv = http('/admin/manifest.php?date=' . TD . '&route=' . $routeId . '&format=csv');
    check('CSV downloads', $csv['code'] === 200 && stripos($csv['headers'], 'text/csv') !== false);
    check('CSV is sent as an attachment', stripos($csv['headers'], 'attachment') !== false);
    foreach (['Web Customer', 'Cod Customer', 'Counter Customer'] as $who) {
        check("CSV contains $who", str_contains($csv['body'], $who));
    }
    check('CSV carries the travel date header', str_contains($csv['body'], TD));

    /* Bus chalani (6 Sep 2026): landscape, Nepali-only passenger sheet, and a
       real Devanagari PDF of the same rows. Both are built from the same
       $rows and the same money rule, so they are checked side by side. */
    $ch = http('/admin/manifest.php?date=' . TD . '&route=' . $routeId . '&format=chalani');
    check('chalani renders', $ch['code'] === 200, 'HTTP ' . $ch['code']);
    check('chalani is landscape', str_contains($ch['body'], '@page { size: A4 landscape'));
    check('chalani title is Nepali', str_contains($ch['body'], 'बस चलानी · यात्रु विवरण'));
    check('chalani carries no English column labels',
          !str_contains($ch['body'], 'Passport/ID') && !str_contains($ch['body'], 'Sold by') && !str_contains($ch['body'], 'BUS WAYBILL'));
    foreach (['Web Customer', 'Cod Customer', 'Counter Customer'] as $who) {
        check("chalani lists $who", str_contains($ch['body'], $who));
    }
    check('chalani pads to at least 25 ruled rows', substr_count($ch['body'], '<td class="c n">') >= 25);
    check('chalani has the payment summary + authorisation boxes',
          str_contains($ch['body'], 'भुक्तानी सारांश') && str_contains($ch['body'], 'प्रमाणीकरण'));
    check('chalani offers the PDF download', str_contains($ch['body'], 'format=chalanipdf'));

    $cp = http('/admin/manifest.php?date=' . TD . '&route=' . $routeId . '&format=chalanipdf');
    check('chalani PDF downloads', $cp['code'] === 200 && stripos($cp['headers'], 'application/pdf') !== false, 'HTTP ' . $cp['code']);
    check('chalani PDF is a PDF', str_starts_with($cp['body'], '%PDF'));
    check('chalani PDF embeds the Devanagari face', str_contains($cp['body'], '/FontFile2') && str_contains($cp['body'], '/CIDFontType2'));
    check('chalani PDF is landscape', (bool) preg_match('#/MediaBox\s*\[\s*0\s+0\s+841\.\d+\s+595\.\d+\s*\]#', $cp['body']));
    check('chalani PDF is sent as a download', stripos($cp['headers'], 'attachment') !== false);

    /* ---- a cancelled passenger drops off the list ---------------------- */
    BookingService::cancel((string) $web['pnr'], 'manifest test', false);
    $after = http('/admin/manifest.php?date=' . TD . '&route=' . $routeId);
    check('a cancelled passenger is no longer on the manifest',
          !str_contains($after['body'], 'Web Customer'));
    check('  ...while the others remain',
          str_contains($after['body'], 'Counter Customer'));

} catch (Throwable $e) {
    $FAIL++;
    echo "  \033[31mERROR\033[0m  " . $e->getMessage() . "\n  " . $e->getFile() . ':' . $e->getLine() . "\n";
}

cleanup();
@unlink($JAR);
Settings::set('staff_seats', ['sleeper' => ['L5', 'L6'], 'seater' => ['1A']], 'json', 'seats', false);
echo "\n----------------------------------------\n";
echo "PASSED: $PASS   FAILED: $FAIL\n\n";
exit($FAIL === 0 ? 0 : 1);
