<?php
/**
 * trust-layer-test.php — the trust layer (24 Sep 2026).
 *
 *   A. Three switches exist and ship OFF; SHG_BOOT.trust is empty while off. [HTTP]
 *   B. One ladder: Fare::refundSlabs() is what refundFor() enforces, highest
 *      threshold first, and Trust::ladder() is the same list as integers.
 *   C. Trust::numbers() is real: shape, non-negative, counted from the register.
 *   D. Switched on, the home page carries the numbers and the ladder, and the
 *      three hooks are in the markup.                                  [HTTP]
 *   E. The booking payload says where a refund is.
 *   F. Labels in three languages; the layer is wired in the JS.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/trust.php';
require_once INCLUDE_PATH . '/ticket.php';   // shg_customer_payload() leans on Ticket::

define('BASE', rtrim((string) (getenv('SHG_TEST_BASE') ?: 'http://localhost:8899'), '/'));
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}
function home(): array {
    $ch = curl_init(BASE . '/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    $b = (string) curl_exec($ch); $c = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return ['code' => $c, 'body' => $b];
}
function bootTrust(string $html): ?array {
    if (!preg_match('/window\.SHG_BOOT = (\{[^\n]*\});/', $html, $m)) { return null; }
    $j = json_decode($m[1], true);
    return is_array($j) ? (array) ($j['trust'] ?? []) : null;
}
$ROOT = dirname(__DIR__);
$was = [];
foreach (['trust_card_on', 'refund_ladder_on', 'women_layer_on'] as $k) { $was[$k] = Settings::getBool($k, false); }
$restore = static function () use ($was): void {
    foreach ($was as $k => $v) { Settings::set($k, $v ? '1' : '0', 'bool', 'trust', true); }
    Settings::flush();
};
if (function_exists('apcu_delete')) { apcu_delete('shg:trust:numbers'); }

echo "-- A. the switches --\n";
foreach (['trust_card_on', 'refund_ladder_on', 'women_layer_on', 'women_helpline'] as $k) {
    $row = Database::fetch('SELECT svalue, is_public FROM settings WHERE skey = :k', ['k' => $k]);
    check("$k exists and is public", $row !== null && (int) $row['is_public'] === 1);
}
foreach (['trust_card_on', 'refund_ladder_on', 'women_layer_on'] as $k) { Settings::set($k, '0', 'bool', 'trust', true); }
Settings::flush();
check('off: boot() carries nothing', Trust::boot() === []);

echo "\n-- B. one ladder --\n";
$slabs = Fare::refundSlabs();
$sorted = true;
for ($i = 1; $i < count($slabs); $i++) { if ((int) $slabs[$i]['minHrs'] > (int) $slabs[$i - 1]['minHrs']) { $sorted = false; } }
check('refundSlabs() is highest threshold first', $slabs !== [] && $sorted, json_encode(array_column($slabs, 'minHrs')));
check('…and ends at 0 hours', (int) end($slabs)['minHrs'] === 0);
$top = $slabs[0];
$far = date('Y-m-d', time() + ((int) $top['minHrs'] + 30) * 3600);
$r = Fare::refundFor(1000, $far, '23:59:00');
check('refundFor() at the top slab pays its percent', (int) $r['percent'] === (int) $top['pct'] && (int) $r['amount'] === (int) round(1000 * $top['pct'] / 100), json_encode($r));
$r = Fare::refundFor(1000, date('Y-m-d'), date('H:i:s', time() + 600));
check('…ten minutes before departure pays the bottom slab', (int) $r['percent'] === (int) end($slabs)['pct'], json_encode($r));
$ladder = Trust::ladder();
check('Trust::ladder() is the same list, as integers', count($ladder) === count($slabs) && $ladder[0] === ['minHrs' => (int) $slabs[0]['minHrs'], 'pct' => (int) $slabs[0]['pct']] && is_int($ladder[0]['pct']));

echo "\n-- C. real numbers --\n";
$n = Trust::numbers();
foreach (['trips', 'pax', 'ratingCount', 'womenSeats'] as $k) { check("numbers().$k is a non-negative int", is_int($n[$k]) && $n[$k] >= 0, (string) $n[$k]); }
check('numbers().rating is a float on /5', is_float($n['rating']) && $n['rating'] >= 0 && $n['rating'] <= 5, (string) $n['rating']);
check('trips never exceed passengers', $n['trips'] <= $n['pax'] || $n['pax'] === 0, $n['trips'] . ' / ' . $n['pax']);
$reg = (int) Database::scalar("SELECT COALESCE(SUM(bl.seat_count), 0) FROM booking_legs bl JOIN bookings b ON b.id = bl.booking_id WHERE b.status IN ('confirmed','completed') AND bl.travel_date BETWEEN :y AND CURDATE()", ['y' => date('Y') . '-01-01'], 0);
check('passengers are counted from the register, not typed', $n['pax'] === $reg, $n['pax'] . ' vs ' . $reg);

echo "\n-- D. the home page --\n";
$h = home();
if ($h['code'] !== 200) {
    echo "  SKIP  no server at " . BASE . "\n";
} else {
    $tr = bootTrust($h['body']);
    check('off: SHG_BOOT.trust is empty', $tr === []);
    foreach (['id="trustLive"', 'id="rfLadder"', 'id="womenLine"'] as $hook) { check("the markup carries $hook", str_contains($h['body'], $hook)); }
    foreach (['trust_card_on', 'refund_ladder_on', 'women_layer_on'] as $k) { Settings::set($k, '1', 'bool', 'trust', true); }
    Settings::flush();
    $h = home(); $tr = bootTrust($h['body']);
    check('on: SHG_BOOT.trust carries the numbers', is_array($tr) && isset($tr['numbers']['trips'], $tr['numbers']['pax'], $tr['numbers']['rating']), json_encode($tr['numbers'] ?? null));
    check('…and the ladder', is_array($tr) && isset($tr['ladder'][0]['minHrs'], $tr['ladder'][0]['pct']), json_encode($tr['ladder'] ?? null));
    check('…and the public switches read on', (bool) preg_match('/"trust_card_on":(true|1|"1")/', $h['body']) && (bool) preg_match('/"women_layer_on":(true|1|"1")/', $h['body']));
}

echo "\n-- E. the payload --\n";
$p = shg_customer_payload(['pnr' => 'SHG-T1', 'status' => 'cancelled', 'total_amount' => 3000, 'currency' => 'INR', 'is_cod' => 0, 'seats' => ['L1'], 'legs' => [], 'passengers' => [], 'refund_amount' => 2250, 'refund_status' => 'pending']);
check('a cancelled booking says how much and where', (float) $p['refundAmount'] === 2250.0 && $p['refundStatus'] === 'pending');
$p = shg_customer_payload(['pnr' => 'SHG-T2', 'status' => 'confirmed', 'total_amount' => 3000, 'currency' => 'INR', 'is_cod' => 0, 'seats' => ['L1'], 'legs' => [], 'passengers' => []]);
check('a live booking carries zeros, never a missing key', $p['refundAmount'] === 0.0 && $p['refundStatus'] === '');

echo "\n-- F. labels and wiring --\n";
$i18n = (string) file_get_contents($ROOT . '/assets/js/04-i18n.js');
foreach (['trTrips', 'trPax', 'trRating', 'trWomen', 'rfTitle', 'rfRow', 'rfNone', 'rfNow', 'rfStatus', 'rfStPending', 'rfStPaid', 'wlCount', 'wlNone', 'wlHelp'] as $k) {
    check("$k in en / hi / ne", substr_count($i18n, ' ' . $k . ': ') === 3, substr_count($i18n, ' ' . $k . ': ') . ' found');
}
$pwa = (string) file_get_contents($ROOT . '/assets/js/17-pwa.js');
check('the layer exposes card / ladder / women / refundLine', str_contains($pwa, 'window.SHG_TRUST = { card: card, ladder: ladder, women: women, refundLine: refundLine }'));
check('checkout draws the ladder', str_contains((string) file_get_contents($ROOT . '/assets/js/07-checkout.js'), 'SHG_TRUST.ladder(out.date, rOut.depTime)'));
check('the seat map draws the women line', str_contains((string) file_get_contents($ROOT . '/assets/js/06-results.js'), 'SHG_TRUST.women(r, ctx.date, femSet)'));
check('My Bookings draws the refund line', str_contains((string) file_get_contents($ROOT . '/assets/js/08-signin.js'), 'SHG_TRUST.refundLine(b)'));
check('the ladder JS never invents a slab: server list first, CONFIG fallback', str_contains($pwa, 'T.ladder && T.ladder.length') && str_contains($pwa, 'CONFIG.booking.refundSlabs'));

$restore();
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
