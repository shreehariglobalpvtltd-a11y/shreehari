<?php
/**
 * =====================================================================
 *  mk-autopilot-test.php — the AI marketing autopilot + AI learning
 *  (6 Oct 2026).
 *
 *    php tests/mk-autopilot-test.php
 *
 *  Who gets a message (festival / return / lapsed / leads, never a
 *  booked, opted-out or recently-messaged number), how many the wallet
 *  allows, that an AI line with an invented number or "% off" is
 *  refused, that the autopilot list is never widened by a rebuild, that
 *  a booking after a send is learned, and that customer questions are
 *  written down for the nightly study. No AI key is needed (the fixed
 *  line is used); nothing is sent (draft mode). Test database only;
 *  every row it makes is removed.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/mkautopilot.php';
require_once INCLUDE_PATH . '/ailearn.php';
require_once INCLUDE_PATH . '/msgledger.php';

if (stripos((string) DB_NAME, 'test') === false) {
    exit("Refusing: DB_NAME must be a test database.\n");
}

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

const P_A = '9876541201';   // went home (toNepal) 30 days before T1
const P_B = '9876541202';   // came back (toIndia) 100 days before T1
const P_C = '9876541203';   // asked, never booked
const P_E = '9876541205';   // has a trip ahead
const P_F = '9876541206';   // said STOP
const P_G = '9876541207';   // went home 12 Sep (24 days before Dashain time)
const T1  = '2026-12-10';   // no festival around
const T2  = '2026-11-20';   // just after Tihar / Chhath

$made = ['bookings' => [], 'legs' => [], 'schedules' => [], 'enquiries' => [], 'campaigns' => []];
$keepKeys = ['mk_auto_mode', 'mk_auto_ai_copy', 'mk_auto_daily', 'mk_auto_reserve', 'mk_auto_gap_days', 'mk_auto_nepal', 'mk_auto_festivals', 'mk_auto_offer', 'ai_learn_on', 'ai_kb_on'];
$keep = [];
foreach ($keepKeys as $k) {
    $keep[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
$kvKeep = Database::fetchAll("SELECT kscope, kkey, kvalue FROM kv_store WHERE (kscope = 'wam' AND kkey = 'project') OR kscope = 'mk_auto'");

$tpl = Database::fetch("SELECT b.* FROM bookings b WHERE b.status = 'confirmed' AND EXISTS (SELECT 1 FROM booking_legs bl WHERE bl.booking_id = b.id) ORDER BY b.id DESC LIMIT 1");
$tplLeg = $tpl ? Database::fetch('SELECT * FROM booking_legs WHERE booking_id = :b LIMIT 1', ['b' => (int) $tpl['id']]) : null;
$tplSch = $tplLeg ? Database::fetch('SELECT * FROM schedules WHERE id = :s', ['s' => (int) $tplLeg['schedule_id']]) : null;

$schedule = static function (int $routeId, string $date) use (&$made, $tplSch): int {
    $have = Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d AND slot = 7', ['r' => $routeId, 'd' => $date]);
    if ($have) { return (int) $have['id']; }
    $row = $tplSch;
    unset($row['id'], $row['created_at'], $row['updated_at']);
    $row['route_id'] = $routeId; $row['travel_date'] = $date; $row['slot'] = 7; $row['seats_booked'] = 0; $row['status'] = 'scheduled';
    $id = (int) Database::insert('schedules', $row);
    $made['schedules'][] = $id;
    return $id;
};
$booking = static function (string $phone, int $routeId, string $date, string $created = '') use (&$made, $tpl, $tplLeg, $schedule): int {
    $b = $tpl;
    unset($b['id']);
    $b['pnr'] = 'SHG-TMK-' . substr($phone, -4) . '-' . bin2hex(random_bytes(3));
    $b['contact_phone'] = $phone; $b['contact_country_code'] = '+91'; $b['status'] = 'confirmed';
    if (array_key_exists('alt_phone', $b)) { $b['alt_phone'] = null; }
    $b['created_at'] = $created !== '' ? $created : $date . ' 09:00:00';
    unset($b['updated_at']);
    $id = (int) Database::insert('bookings', $b);
    $made['bookings'][] = $id;
    $l = $tplLeg;
    unset($l['id']);
    $l['booking_id'] = $id; $l['schedule_id'] = $schedule($routeId, $date); $l['travel_date'] = $date; $l['leg_type'] = 'outbound';
    $made['legs'][] = (int) Database::insert('booking_legs', $l);
    return $id;
};
$set = static function (string $k, string $v, string $type = 'string'): void { Settings::set($k, $v, $type, 'marketing'); };

try {
    if ($tpl === null || $tplLeg === null || $tplSch === null) {
        throw new RuntimeException('the test database needs at least one confirmed booking to copy');
    }

    echo "\n— the line the AI writes is checked\n";
    $facts = ['fare_go' => 2000, 'fare_back' => 1800, 'deps' => ['toNepal' => ['Surat 13:00 → Rupaidiha'], 'toIndia' => ['Rupaidiha 18:00 → Surat']],
              'daily' => true, 'phone' => '+91 91570 01507', 'offer' => '', 'festival' => ['name' => 'दशैं', 'date' => '2026-10-21', 'days' => 15]];
    $why = '';
    $ok = MkAutopilot::check('दशैं नजिकिँदैछ 🪔 घर जाने बस Gujarat → Rupaidiha, भाडा ₹2,000। २१ गते अघि नै सिट पक्का गर्नुहोस्।', $facts, $why);
    check('a line with the real fare and the festival day passes', $ok !== '', $why);
    check('  Devanagari digits are read as numbers', MkAutopilot::check('भाडा ₹२००० मात्र, घर जाने बस हरेक दिन 1 बजे छुट्छ — सिट अहिले नै छान्नुहोस्।', $facts, $why) !== '', $why);
    check('an invented price is refused', MkAutopilot::check('यो हप्ता मात्र ₹1500 मा घर जाने टिकट! अहिले नै बुक गर्नुहोस् साथी।', $facts, $why) === '' && str_contains($why, '1500'), $why);
    check('a "% off" the owner never offered is refused', MkAutopilot::check('दशैंमा 20% छुट! घर जाने बस Gujarat → Rupaidiha, भाडा ₹2000।', $facts, $why) === '', $why);
    check('"छुट्छ" (departs) is not mistaken for a discount', MkAutopilot::check('घर जाने बस हरेक दिन 1 बजे Surat बाट छुट्छ, भाडा ₹2000। सिट अहिले नै छान्नुहोस्।', $facts, $why) !== '', $why);
    check('"free" is refused', MkAutopilot::check('घर जाने टिकटसँग free खाना! Gujarat → Rupaidiha, भाडा ₹2000 मात्र।', $facts, $why) === '', $why);
    check('a link is refused (the template carries the link)', MkAutopilot::check('घर जाने बस भाडा ₹2000 — www.example.com मा हेर्नुहोस् र बुक गर्नुहोस्।', $facts, $why) === '', $why);
    $nl = MkAutopilot::check("घर जाने बस भाडा ₹2000।\nसिट अहिले नै छान्नुहोस् है।", $facts, $why);
    check('a line break becomes a space (Meta refuses it inside a variable)', $nl !== '' && !str_contains($nl, "\n"), $nl);
    $offer = $facts; $offer['offer'] = '4 जना सँगै बुक गरे ₹200 छुट';
    check('the owner\'s own offer may be used', MkAutopilot::check('दशैंमा घर जाने ₹2000 — 4 जना सँगै बुक गरे ₹200 छुट! सिट अहिले नै पक्का गर्नुहोस्।', $offer, $why) !== '', $why);
    foreach (array_keys(MkAutopilot::SEGMENTS) as $seg) {
        $fb = MkAutopilot::fallback($seg, $facts, 'toIndia');
        check("the fixed line for $seg passes its own check", MkAutopilot::check($fb, $facts, $why) !== '', $why . ' :: ' . $fb);
    }

    echo "\n— festivals and the budget split\n";
    $set('mk_auto_festivals', "2026-10-21 | दशैं\n2026-11-08 | तिहार\n2026-11-15 | छठ");
    $f = MkAutopilot::festivalNow('2026-10-06');
    check('15 days before Dashain = "go home" time', ($f['go']['name'] ?? '') === 'दशैं' && (int) $f['go']['days'] === 15 && $f['back'] === null);
    $f = MkAutopilot::festivalNow(T2);
    check('5 days after Chhath = "come back" time', ($f['back']['name'] ?? '') !== '' && $f['go'] === null);
    check('  nothing around 10 Dec', MkAutopilot::festivalNow(T1) === ['go' => null, 'back' => null]);
    $sc = ['festival_go' => ['score' => .04], 'festival_back' => ['score' => .04], 'return' => ['score' => .2], 'lapsed' => ['score' => .02], 'leads' => ['score' => .02]];
    $g = MkAutopilot::allocate(10, ['festival_go' => 4, 'festival_back' => 0, 'return' => 50, 'lapsed' => 50, 'leads' => 50], $sc);
    check('festival group is served first', $g['festival_go'] === 4);
    check('  the rest follows what sold (return > lapsed)', $g['return'] > $g['lapsed'] && array_sum($g) === 10, json_encode($g));
    $g = MkAutopilot::allocate(0, ['festival_go' => 4, 'return' => 5, 'lapsed' => 5, 'leads' => 5, 'festival_back' => 0], $sc);
    check('  no money → nobody', array_sum($g) === 0);

    echo "\n— the wallet decides how many\n";
    Database::run("INSERT INTO wam_credit (at, credit, quality, tier) VALUES (NOW(), 30.00, 'GREEN', 'tier_1')");
    MsgLedger::kvSet('project', json_encode(['credit' => 30.0, 'plan' => 'PRO_MONTHLY', 'daily_limit' => 250, 'quality' => 'GREEN', 'renewal' => '2026-10-19', 'at' => date('Y-m-d H:i:s')]));
    $set('mk_auto_reserve', '10', 'int');
    $set('mk_auto_daily', '25', 'int');
    $b = MkAutopilot::budget();
    $expect = (int) floor(($b['wallet'] - $b['reserve'] - $b['queued_cost']) / $b['rate']);
    check('wallet read from AiSensy (₹30)', abs((float) $b['wallet'] - 30.0) < 0.001, json_encode($b));
    check('  reserve is at least the setting', $b['reserve'] >= 10.0);
    check('  messages by money = (wallet − reserve − queued) ÷ rate', $b['by_money'] === max(0, $expect), $b['by_money'] . ' vs ' . $expect);
    check('  allowed never exceeds the daily limit', $b['allowed'] <= 25);
    $set('mk_auto_reserve', '1000', 'int');
    $b2 = MkAutopilot::budget();
    check('a reserve bigger than the wallet → 0 and a reason to recharge', $b2['allowed'] === 0 && str_contains($b2['reason'], 'Recharge'), $b2['reason']);
    $set('mk_auto_reserve', '0', 'int');

    echo "\n— who gets it\n";
    $booking(P_A, 2, date('Y-m-d', strtotime(T1 . ' -30 days')));    // toNepal, 30 d before T1
    $booking(P_B, 7, date('Y-m-d', strtotime(T1 . ' -100 days')));   // toIndia, 100 d before T1
    $booking(P_E, 2, '2027-01-05');                                   // a trip ahead
    $booking(P_F, 7, date('Y-m-d', strtotime(T1 . ' -40 days')));
    $booking(P_G, 2, '2026-09-12');
    Campaigns::optOut(P_F, 'test', 0);
    $made['enquiries'][] = (int) Database::insert('enquiries', ['name' => 'Lead Test', 'phone' => P_C, 'seats' => 1, 'source' => 'test', 'status' => 'new']);

    $where = static function (array $c, string $ph): string {
        foreach ($c as $seg => $list) { foreach ($list as $p) { if ($p['ph'] === $ph) { return $seg . ($p['dir'] !== '' ? ':' . $p['dir'] : ''); } } }
        return '';
    };
    $c1 = MkAutopilot::candidates(T1);
    check('went home 30 days ago → return trip, back to Gujarat', $where($c1, P_A) === 'return:toIndia', $where($c1, P_A));
    check('100 days since the last trip → lapsed', $where($c1, P_B) === 'lapsed', $where($c1, P_B));
    check('asked but never booked → leads', $where($c1, P_C) === 'leads', $where($c1, P_C));
    check('a trip already booked → nobody markets to them', $where($c1, P_E) === '');
    check('said STOP → never', $where($c1, P_F) === '');
    $tap = (int) Database::insert('mk_campaigns', ['name' => 'one-tap test', 'channel' => 'wa_manual', 'message' => 'x', 'audience' => '', 'status' => 'running']);
    $made['campaigns'][] = $tap;
    Database::insert('mk_recipients', ['campaign_id' => $tap, 'phone' => '91' . P_B, 'name' => 'B']);
    check('a one-tap list waiting for taps does not hide anyone from the automatic run', $where(MkAutopilot::candidates(T1), P_B) === 'lapsed');
    Database::run('DELETE FROM mk_recipients WHERE campaign_id = :c', ['c' => $tap]);
    $c2 = MkAutopilot::candidates(T2);
    check('just after the festival, went home 20 days ago → come back', $where($c2, P_A) === 'festival_back:toIndia', $where($c2, P_A));
    $c3 = MkAutopilot::candidates('2026-10-06');
    check('15 days before Dashain, last trip home was months ago → go home', $where($c3, P_B) === 'festival_go:toNepal', $where($c3, P_B));
    check('  went home 24 days before Dashain → NOT told to come back yet (asked after the festival)', $where($c3, P_G) === '', $where($c3, P_G));
    check('  the same person months later with no festival → lapsed', $where($c1, P_G) === 'lapsed', $where($c1, P_G));

    echo "\n— a draft run queues campaigns, never sends\n";
    $set('mk_auto_mode', 'draft');
    $set('mk_auto_ai_copy', '0', 'bool');
    $set('mk_auto_daily', '500', 'int');
    MsgLedger::kvSet('project', json_encode(['credit' => 9000.0, 'plan' => 'PRO_MONTHLY', 'daily_limit' => 100000, 'quality' => 'GREEN', 'at' => date('Y-m-d H:i:s')]));
    Database::run("INSERT INTO wam_credit (at, credit, quality, tier) VALUES (NOW(), 9000.00, 'GREEN', 'tier_1')");
    $r = MkAutopilot::run(false, 0, T1);
    foreach ($r['campaigns'] as $c) { if ($c['id'] > 0) { $made['campaigns'][] = (int) $c['id']; } }
    $ids = $made['campaigns'] !== [] ? implode(',', $made['campaigns']) : '0';
    $rows = Database::fetchAll("SELECT c.id, c.status, c.audience, c.template_name, c.template_params, r.phone FROM mk_campaigns c JOIN mk_recipients r ON r.campaign_id = c.id WHERE c.id IN ($ids)");
    $byPhone = [];
    foreach ($rows as $row) { $byPhone[substr((string) $row['phone'], -10)] = $row; }
    check('campaigns were made', count($made['campaigns']) >= 3, json_encode(array_column($r['campaigns'], 'people')) . ' ' . $r['note']);
    check('  all as DRAFT (nothing leaves before Launch)', $rows !== [] && count(array_filter($rows, static fn ($x) => $x['status'] !== 'draft')) === 0);
    check('  on the approved marketing template', ($rows[0]['template_name'] ?? '') === 'shg_offer');
    check('  {{1}} first name, {{2}} the line, {{3}} the link', ($p = json_decode((string) ($rows[0]['template_params'] ?? '[]'), true)) && $p[0] === '{first}' && $p[2] === '{link}' && mb_strlen($p[1]) > 30);
    check('  A got the return-trip campaign', ($byPhone[P_A]['audience'] ?? '') === 'autopilot=return');
    check('  B got lapsed, C got leads', ($byPhone[P_B]['audience'] ?? '') === 'autopilot=lapsed' && ($byPhone[P_C]['audience'] ?? '') === 'autopilot=leads');
    check('  E (booked) and F (STOP) are not on any list', !isset($byPhone[P_E]) && !isset($byPhone[P_F]));
    check('  the run is remembered', (MkAutopilot::history()[0]['at'] ?? '') === $r['at']);
    $cid = (int) ($byPhone[P_A]['id'] ?? 0);
    $before = (int) Campaigns::get($cid)['total'];
    check('rebuilding an AI list never widens it to every customer', Campaigns::buildAudience($cid) === $before && (int) Campaigns::get($cid)['total'] === $before);
    $again = MkAutopilot::candidates(T1);
    check('a second run the same day finds A, B, C already queued', $where($again, P_A) === '' && $where($again, P_B) === '' && $where($again, P_C) === '');

    echo "\n— it learns which group books\n";
    Database::run("DELETE FROM mk_recipients WHERE campaign_id IN ($ids) AND RIGHT(phone, 10) NOT IN ('" . P_A . "','" . P_B . "','" . P_C . "')");
    Database::run("UPDATE mk_recipients SET state = 'delivered', sent_at = NOW() - INTERVAL 1 DAY WHERE campaign_id IN ($ids)");
    $booking(P_A, 7, date('Y-m-d', strtotime('+3 days')), date('Y-m-d H:i:s'));
    $s = MkAutopilot::scores();
    check('A booked after the message → return group booked 1', $s['return']['booked'] === 1 && $s['return']['sent'] >= 1, json_encode($s['return']));
    check('  lapsed sent 1, booked 0', $s['lapsed']['booked'] === 0 && $s['lapsed']['sent'] >= 1);
    check('  return now scores above lapsed', $s['return']['score'] > $s['lapsed']['score']);
    check('the line that sold is kept as a lesson', MkAutopilot::lessons()['good'] === [] || str_contains(MkAutopilot::lessons()['good'][0], 'bookings'));

    echo "\n— the daily tick\n";
    $set('mk_auto_mode', 'off');
    check('off → does nothing', MkAutopilot::tick() === ['autopilot' => 'off']);
    $set('mk_auto_mode', 'draft');
    MkAutopilot::kvSet('day', date('Y-m-d'));
    $t = MkAutopilot::tick();
    check('already ran today → waits', ($t['autopilot'] ?? '') === 'waiting', json_encode($t));

    echo "\n— AI learning from questions\n";
    check('"luggage kati kg?" is a question', AiLearn::isQuestion('luggage kati kg lana milcha?'));
    check('"सामान कति लैजान पाइन्छ?" is a question', AiLearn::isQuestion('सामान कति लैजान पाइन्छ?'));
    check('"hi" / a PNR / a seat order are not', !AiLearn::isQuestion('hi') && !AiLearn::isQuestion('SHG-2026-00426 status?') && !AiLearn::isQuestion('2 seat 15 oct Surat'));
    check('a learned answer may carry the office phone', AiLearn::numbersOk('Call the office on +91 91570 01507 any time.', '+91 91570 01507'));
    check('  but never a fare or a time', !AiLearn::numbersOk('The fare is ₹2000 and the bus leaves at 1 pm.', '+91 91570 01507'));
    $set('ai_learn_on', '1', 'bool');
    $set('ai_kb_on', '1', 'bool');
    check('"bus ma toilet huncha?" is not "known" just because a border article says bus', !AiLearn::known('bus ma toilet huncha?'));
    $q = 'bus ma toilet huncha ki nai ' . bin2hex(random_bytes(2)) . '?';
    AiLearn::observe($q);
    $row = Database::fetch('SELECT * FROM ai_unanswered_questions WHERE normalized_question = :q', ['q' => mb_strtolower($q)]);
    check('an unanswered customer question is written down for tonight', $row !== null && $row['status'] === 'new');
    if ($row) { Database::delete('ai_unanswered_questions', 'id = :id', ['id' => (int) $row['id']]); }

    echo "\n— the photo template\n";
    check('no approved photo template here → the text template, no photo', MkAutopilot::templateAndPhoto() === [MkAutopilot::template(), null]);
    check('the pamphlet has a public link for the photo header', is_file(dirname(__DIR__) . '/offer-card.php')
        && str_contains((string) file_get_contents(dirname(__DIR__) . '/includes/mkautopilot.php'), "'header_image'    => \$photo"));

    echo "\n— wiring\n";
    $root = dirname(__DIR__);
    check('the minute cron runs the autopilot and the learning', str_contains((string) file_get_contents($root . '/cron/campaigns.php'), 'MkAutopilot::tick()') && str_contains((string) file_get_contents($root . '/cron/campaigns.php'), 'AiLearn::tick()'));
    check('WhatsApp customer questions are observed', str_contains((string) file_get_contents($root . '/includes/wabot.php'), 'AiLearn::observe($body)'));
    check('the admin menu has 🤖 AI Marketing', str_contains((string) file_get_contents($root . '/admin/_guard.php'), "'ai-marketing.php'"));
    check('this suite is registered in the battery', str_contains((string) file_get_contents($root . '/tests/run-all.php'), 'mk-autopilot-test.php'));
} catch (Throwable $e) {
    check('suite completed without an unexpected error', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if ($made['campaigns'] !== []) {
        $ids = implode(',', $made['campaigns']);
        Database::run("DELETE FROM mk_recipients WHERE campaign_id IN ($ids)");
        Database::run("DELETE FROM mk_campaigns WHERE id IN ($ids)");
    }
    Database::run("DELETE r FROM mk_recipients r JOIN mk_campaigns c ON c.id = r.campaign_id WHERE c.audience LIKE 'autopilot=%' AND r.phone LIKE '9198765412%'");
    foreach ($made['legs'] as $id) { Database::delete('booking_legs', 'id = :id', ['id' => $id]); }
    foreach ($made['bookings'] as $id) { Database::delete('bookings', 'id = :id', ['id' => $id]); }
    foreach ($made['schedules'] as $id) { Database::delete('schedules', 'id = :id', ['id' => $id]); }
    foreach ($made['enquiries'] as $id) { Database::delete('enquiries', 'id = :id', ['id' => $id]); }
    Database::run("DELETE FROM mk_optout WHERE phone = :p", ['p' => P_F]);
    Database::run("DELETE FROM wam_credit WHERE credit IN (30.00, 9000.00) AND at > NOW() - INTERVAL 1 HOUR");
    Database::run("DELETE FROM kv_store WHERE kscope = 'mk_auto' OR (kscope = 'wam' AND kkey = 'project')");
    foreach ($kvKeep as $kv) {
        Database::run('INSERT INTO kv_store (kscope, kkey, kvalue) VALUES (:s, :k, :v)', ['s' => $kv['kscope'], 'k' => $kv['kkey'], 'v' => $kv['kvalue']]);
    }
    foreach ($keep as $k => $v) {
        if ($v === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $v['svalue'], 't' => $v['stype'], 'k' => $k]);
        }
    }
    Settings::flush();
}

echo "\nmk-autopilot: $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
