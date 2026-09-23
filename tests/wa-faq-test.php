<?php
/**
 * =====================================================================
 *  wa-faq-test.php — the everyday WhatsApp questions answered on the VPS
 *  with no AI call (includes/wafaq.php, 23 Sep 2026).
 *
 *  Pins: the answer comes from the SAME tables the booking engine reads
 *  (fares, pickups, offers, company settings, knowledge base); anything
 *  personal or unsure is left to the assistant (null); the switch works;
 *  every local answer is logged as "local:<intent>" in ai_agent_calls;
 *  and WaBot::reply really routes through it before the assistant.
 *
 *      php tests/wa-faq-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/wabot.php';
require_once INCLUDE_PATH . '/wafaq.php';
require_once INCLUDE_PATH . '/aiknowledge.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 300) : '') . "\n"; }
}

echo "\n=== WhatsApp everyday answers on the VPS (no AI) ===\n\n";

const FAQ_P = '+9191000088';   // fake senders +91 91000088xx
$PINNED = ['wa_faq_on', 'ai_kb_on'];
$prior  = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
$cleanup = static function () use ($PINNED, $prior): void {
    foreach (["DELETE FROM kv_store WHERE kkey LIKE '%91000088%'", "DELETE FROM ai_agent_calls WHERE phone LIKE '%91000088%'",
              "DELETE FROM coupons WHERE code = 'ZZFAQAUTO'", "DELETE FROM ai_kb_articles WHERE slug = 'zztest-faq'"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        try {
            if ($row === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); }
            else { Database::update('settings', ['svalue' => (string) $row['svalue']], 'skey = :k', ['k' => $k]); }
        } catch (Throwable $e) {}
    }
    Settings::flush();
};
register_shutdown_function($cleanup);
$cleanup();

Settings::set('wa_faq_on', true, 'bool', 'ai');
Settings::set('ai_kb_on', true, 'bool', 'ai');
Settings::flush();

$ask = static fn(string $n, string $q): ?array => WaFaq::answer(FAQ_P . $n, $q);

/* ---- what the tables say (so the test never hard-codes a fare or a time) ---- */
$toNp = Settings::getInt('fare_to_nepal', 2000);
$stop = static function (string $like): ?array {
    return Database::fetch("SELECT s.stop_name, s.stop_time FROM route_stops s JOIN routes r ON r.id = s.route_id
                             WHERE r.is_active = 1 AND s.stop_type = 'boarding' AND s.stop_name LIKE :n
                             ORDER BY s.id LIMIT 1", ['n' => '%' . $like . '%']);
};
$clock = static fn(?array $s): string => $s === null ? '??' : date('g:i A', (int) strtotime((string) $s['stop_time']));

/* ---- greeting ------------------------------------------------------ */
$r = $ask('01', 'namaste');
check('"namaste" is answered locally', $r !== null && $r['intent'] === 'greeting', json_encode($r, JSON_UNESCAPED_UNICODE));

/* ---- fare ---------------------------------------------------------- */
$r = $ask('02', 'bhada kati parcha?');
check('fare comes from the fare settings', $r !== null && $r['intent'] === 'fare' && str_contains($r['text'], inr($toNp)), (string) ($r['text'] ?? ''));
$r = $ask('03', '2 jana ko sleeper kati parcha?');
check('2 people: the total is worked out', $r !== null && str_contains($r['text'], inr($toNp * 2)), (string) ($r['text'] ?? ''));

/* ---- timing -------------------------------------------------------- */
$vad = $stop('Vadodara');
$r = $ask('04', 'baroda bata bus kati baje chhutcha?');
check('Baroda → the Vadodara pickup time from route_stops', $r !== null && str_contains($r['text'], $clock($vad)), (string) ($r['text'] ?? ''));
check('"chhutcha" (departs) is not mistaken for "chhut" (discount)', $r !== null && $r['intent'] === 'timing', (string) ($r['intent'] ?? ''));
$rup = $stop('Rupaidiha');
$r = $ask('05', 'rupdia bata surat kahile aaune?');
check('"rupdia bata surat" boards at Rupaidiha, not Surat',
    $rup === null || ($r !== null && str_contains($r['text'], $clock($rup)) && str_contains($r['text'], 'Rupaidiha')), (string) ($r['text'] ?? ''));
$r = $ask('06', 'kal ahmedabad se nepal ki bus kitne baje hai?');
check('Hindi question gets a Hindi answer with the Ahmedabad time',
    $r !== null && str_contains($r['text'], $clock($stop('Chiloda'))) && str_contains($r['text'], 'से'), (string) ($r['text'] ?? ''));

/* ---- offers -------------------------------------------------------- */
$r = $ask('07', 'online book garda discount cha?');
check('a discount question is answered locally as "offers"', $r !== null && $r['intent'] === 'offers', json_encode($r, JSON_UNESCAPED_UNICODE));
if (Fare::runningOffers() === []) {
    check('no offer running → says so, names no amount', $r !== null && !preg_match('/₹\s?\d/u', $r['text']), (string) ($r['text'] ?? ''));
}
Database::insert('coupons', ['code' => 'ZZFAQAUTO', 'title' => 'ZZ FAQ Festival', 'discount_type' => 'flat', 'discount_value' => 120,
    'min_amount' => 0, 'per_user_limit' => 0, 'used_count' => 0, 'is_active' => 1, 'auto_apply' => 1]);
$r = $ask('08', 'offer cha?');
check('a running office offer is named', $r !== null && str_contains($r['text'], 'ZZ FAQ Festival'), (string) ($r['text'] ?? ''));
Database::run("DELETE FROM coupons WHERE code = 'ZZFAQAUTO'");

/* ---- website / office ----------------------------------------------- */
$r = $ask('09', 'website k ho?');
check('website: the company site', $r !== null && str_contains($r['text'], Settings::getString('company_web', 'shreehariglobal.in')), (string) ($r['text'] ?? ''));
$r = $ask('10', 'office ko contact dinus');
check('office: the one office number', $r !== null && str_contains($r['text'], Settings::officePhone()), (string) ($r['text'] ?? ''));

/* ---- knowledge base, only on a strong match ------------------------- */
Database::insert('ai_kb_articles', ['category' => 'test', 'slug' => 'zztest-faq', 'canonical_title' => 'ZZ faq article',
    'canonical_answer' => 'ZZ answer body.', 'english_content' => 'ZZ answer body.', 'nepali_content' => 'ZZ उत्तर।',
    'keywords' => 'zzfaqword zzalpha zzbeta', 'applicable_roles' => json_encode(['public']), 'source_type' => 'test',
    'source_reference' => 'wa-faq-test', 'verification_status' => 'verified', 'publication_status' => 'published']);
$r = $ask('11', 'zzfaqword zzalpha zzbeta');
check('a strong knowledge match is answered from the article', $r !== null && $r['intent'] === 'knowledge' && str_contains($r['text'], 'ZZ'), json_encode($r, JSON_UNESCAPED_UNICODE));
check('a weak knowledge match is left to the assistant', $ask('12', 'zzfaqword') === null);

/* ---- personal / unsure: the assistant answers ------------------------ */
foreach (['mero ticket kahile aaucha?', 'SHG-2026-00001 ko bus kati baje?', 'payment gare tara ticket aayena',
          'ma admin hu, bhada kati?', 'aaja cricket kasle jityo?', 'kati ghanta lagcha rupaidiha pugna?'] as $i => $q) {
    check('left to the assistant: "' . $q . '"', $ask('2' . $i, $q) === null);
}

/* ---- audit + router + switch ----------------------------------------- */
check('local answers are logged as local:<intent>',
    (int) Database::scalar("SELECT COUNT(*) FROM ai_agent_calls WHERE phone LIKE '%91000088%' AND tool LIKE 'local:%'", [], 0) >= 8);
$via = WaBot::reply(FAQ_P . '30', 'bhada kati parcha?');
check('WaBot routes the everyday question to the VPS answer', str_contains((string) $via['text'], inr($toNp)), (string) $via['text']);
Settings::set('wa_faq_on', false, 'bool', 'ai');
Settings::flush();
check('switched off → nothing is answered locally', $ask('31', 'bhada kati parcha?') === null);

$runAll = @file_get_contents(dirname(__DIR__) . '/tests/run-all.php') ?: '';
check('this suite is registered in the battery', str_contains($runAll, 'wa-faq-test.php'));

echo "\nwa-faq: $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
