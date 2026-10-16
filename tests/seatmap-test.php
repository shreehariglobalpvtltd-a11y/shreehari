<?php
/**
 * =====================================================================
 *  seatmap-test.php — the customer seat picture (includes/seatmappng.php,
 *  /seatmap-image.php, 23 Sep 2026).
 *
 *  Pins: the signed link opens only for the departure, highlight and time
 *  it was minted for; the image is a real PNG; the free count matches the
 *  crew challan's; the drawing code never reads a name, phone or PNR; and
 *  WaFaq answers "khali seat cha?" with the picture.
 *
 *      php tests/seatmap-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/seatmappng.php';
require_once INCLUDE_PATH . '/wafaq.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 240) : '') . "\n"; }
}

echo "\n=== Customer seat picture ===\n\n";

$PINNED = ['wa_faq_on', 'wa_seat_photo_on'];
$prior  = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]); }
register_shutdown_function(static function () use ($PINNED, $prior): void {
    foreach (["DELETE FROM kv_store WHERE kkey LIKE '%91000077%'", "DELETE FROM ai_agent_calls WHERE phone LIKE '%91000077%'"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    foreach ($PINNED as $k) {
        try {
            if ($prior[$k] === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => (string) $prior[$k]['svalue']], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
});

$plan = QuickTicket::plan(['seats' => 1, 'customer' => true]);
$sid  = (int) ($plan['scheduleId'] ?? 0);
check('a departure to draw', $sid > 0);

/* ---- the signed link ------------------------------------------------ */
$url = SeatMapPng::url($sid, ['L7', 'l8', 'bad seat!']);
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
check('the link points at /seatmap-image.php', str_contains($url, 'seatmap-image.php'));
check('the minted link verifies', SeatMapPng::verify((int) $q['s'], (string) $q['h'], (int) $q['e'], (string) $q['k']));
check('junk seat ids are dropped, the rest upper-cased', (string) $q['h'] === 'L7,L8', (string) $q['h']);
check('another departure is refused', !SeatMapPng::verify((int) $q['s'] + 1, (string) $q['h'], (int) $q['e'], (string) $q['k']));
check('another highlight is refused', !SeatMapPng::verify((int) $q['s'], 'L1', (int) $q['e'], (string) $q['k']));
check('a stretched expiry is refused', !SeatMapPng::verify((int) $q['s'], (string) $q['h'], (int) $q['e'] + 60, (string) $q['k']));
check('an expired link is refused', !SeatMapPng::verify($sid, '', time() - 5, 'x'));

/* ---- the picture ------------------------------------------------------ */
$png = SeatMapPng::png($sid, ['L7']);
check('a real PNG comes out', str_starts_with($png, "\x89PNG"));
$sz = getimagesizefromstring($png);
check('900 px wide, taller than the header', is_array($sz) && $sz[0] === 900 && $sz[1] > 300, json_encode($sz));

$sum = SeatMapPng::summary($sid);
require_once INCLUDE_PATH . '/challanpng.php';
$crew = ChallanPng::collect($sid, ChallanPng::schedule($sid));
check('the free count is the crew challan\'s own', $sum !== null && $sum['free'] === (int) $crew['totals']['empty']
    && $sum['free'] <= $sum['total'], json_encode($sum));

$src = (string) file_get_contents(INCLUDE_PATH . '/seatmappng.php');
check('the drawing never reads a passenger name, phone or PNR',
    !preg_match("/\\['(names|phone|pnr|sale)'\\]/", $src));

/* ---- WaFaq answers with the picture ---------------------------------- */
Settings::set('wa_faq_on', true, 'bool', 'ai');
Settings::set('wa_seat_photo_on', true, 'bool', 'ai');
Settings::flush();
$r = WaFaq::answer('+919100007701', 'khali seat cha?');
check('"khali seat cha?" → the seat intent with a picture', $r !== null && $r['intent'] === 'seats'
    && is_string($r['media']) && str_contains($r['media'], 'seatmap-image.php'), json_encode($r, JSON_UNESCAPED_UNICODE));
check('  and the text states the free count', $r !== null && $sum !== null && str_contains($r['text'], (string) $sum['free']), (string) ($r['text'] ?? ''));
Settings::set('wa_seat_photo_on', false, 'bool', 'ai');
Settings::flush();
$r2 = WaFaq::answer('+919100007702', 'khali seat cha?');
check('picture switched off → no seat picture is sent', $r2 === null || ($r2['media'] ?? null) === null, json_encode($r2, JSON_UNESCAPED_UNICODE));

$runAll = @file_get_contents(dirname(__DIR__) . '/tests/run-all.php') ?: '';
check('this suite is registered in the battery', str_contains($runAll, 'seatmap-test.php'));

echo "\nseatmap: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
