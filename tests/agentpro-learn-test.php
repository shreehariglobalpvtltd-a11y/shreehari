<?php
/**
 * =====================================================================
 *  agentpro-learn-test.php — the WhatsApp desk learns from examples and
 *  the VPS model practises (31 Oct 2026, idc-learn).
 *
 *  Against the TEST database:
 *    - ApLearn::norm() keeps no one's details (phone, PNR, amount, date,
 *      UTR, e-mail, link, codes, names after naam / names, the intent's own
 *      name / query) and is idempotent; skeleton() keeps no personal slot
 *    - record / fast: one confirmation is not enough, two are; the details
 *      come from the NEW message; a different certain reading replaces the
 *      old one; an office example is trusted at once and a doubt pauses it;
 *      question / other are never learnt; edit never takes the fast path
 *    - shots(): the closest examples, never the one being practised
 *    - practice() with a stub reader: scoring, misses, the run row, px_*
 *    - the desk: a typed command becomes a "parser" example (a sale without
 *      the passenger's name / phone); an AI reading (a small router in front
 *      of tools/mock-ollama.php) proposes a withdrawal, "ho" files it and
 *      teaches it ("confirmed"); after two the same words answer with the
 *      model server GONE — and still only after "ho"
 *    - practice() on the model (the router): a run row with the model name
 *    - "form": the 30-day personal link, else /me/form.php after sign-in
 *    - office "id <code | phone | name>": the card picture + visiting card;
 *      several matches → a list; an agent / an unlinked manager cannot
 *
 *    php tests/agentpro-learn-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/booking.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/agentpro-wa.php';
require_once INCLUDE_PATH . '/agentpro-ai.php';
require_once INCLUDE_PATH . '/agentpro-brain.php';
require_once INCLUDE_PATH . '/agentpro-learn.php';
require_once INCLUDE_PATH . '/agentpro-form.php';
require_once INCLUDE_PATH . '/agentpro-card.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 140) : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 500) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }
function wa(string $from, string $text): ?array { return ApWa::reply('+' . $from, $text, 'text'); }
function txt(?array $r): string { return (string) ($r['text'] ?? ''); }
function pendingOf(string $phone): ?array {
    $p = json_decode((string) Database::scalar('SELECT pending FROM ap_wa_state WHERE phone = :p', ['p' => $phone], ''), true);
    return is_array($p) ? $p : null;
}
function exRow(string $text): ?array {
    return Database::fetch('SELECT * FROM ap_wa_examples WHERE text_hash = :h', ['h' => sha1(ApLearn::norm($text))]);
}

if (!AgentPro::available()) {
    check('ap_* tables exist', false);
    exit(1);
}
ApLearn::reset();
if (!ApLearn::ready()) {
    check('ap_wa_examples + ap_ai_practice exist (apply database/upgrade-2026-10-31-idc-learn.sql)', false);
    exit(1);
}

/* ---- settings for the run (restored at the end) ---- */
$keep = [];
$set = static function (string $k, string $v, string $type = 'string') use (&$keep): void {
    if (!array_key_exists($k, $keep)) {
        $keep[$k] = Database::scalar('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k], null);
    }
    Settings::set($k, $v, $type, 'agentpro', false);
};
$PORT = 11563;
foreach (['whatsapp_driver' => ['click_to_chat', 'string'], 'ap_wa_on' => ['1', 'bool'], 'ap_wa_sell' => ['1', 'bool'], 'ap_wa_admin_on' => ['1', 'bool'],
          'ap_ai_on' => ['0', 'bool'], 'ap_ai_base' => ['http://127.0.0.1:' . $PORT, 'string'], 'ap_ai_model' => ['', 'string'], 'ap_ai_ack' => ['0', 'bool'],
          'ap_wa_ai_answers' => ['0', 'bool'], 'agent_kyc_required' => ['0', 'bool'], 'ap_ai_timeout' => ['30', 'int'], 'ap_learn_on' => ['1', 'bool'],
          'ap_learn_fast' => ['1', 'bool'], 'ap_learn_fast_min' => ['2', 'int'], 'ap_learn_shots' => ['6', 'int'], 'ap_wa_dir_agents' => ['0', 'bool']] as $k => [$v, $type]) {
    $set($k, $v, $type);
}
Settings::flush();
ApAi::resetHealth();

$tag = 'aplt' . strtr(bin2hex(random_bytes(3)), '0123456789', 'ghijklmnop');   // letters only: survives norm() as one word
$A   = '7000000601';   // the agent (registered mobile)
$MGR = '7000000602';   // an office manager (OTP-linked)
$MG2 = '7000000603';   // a manager whose number only matches admins.phone
$K1  = '7000000604';   // two agents with a shared name part
$K2  = '7000000605';
$phones = [$A, $MGR, $MG2, $K1, $K2];
$practiceFrom = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM ap_ai_practice', [], 0);
$cleanup = static function () use ($phones, $tag): void {
    foreach (Database::fetchAll("SELECT id FROM admins WHERE username LIKE 'aplt\\_%'") as $r) {
        $id = (int) $r['id'];
        try { AgentWallet::setAgentCode($id, null); } catch (Throwable $e) {}
        foreach (['agent_ledger' => 'agent_admin_id', 'agent_payout_requests' => 'agent_admin_id', 'ap_deposit_requests' => 'admin_id',
                  'ap_members' => 'admin_id', 'admin_profiles' => 'admin_id', 'ap_wa_links' => 'admin_id', 'ap_applications' => 'admin_id'] as $t => $c) {
            try { Database::delete($t, $c . ' = :i', ['i' => $id]); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :i', ['i' => $id]);
    }
    foreach ($phones as $p) {
        Database::delete('ap_wa_state', 'phone = :p', ['p' => $p]);
        Database::delete('ap_wa_links', 'phone = :p', ['p' => $p]);
        Database::delete('kv_store', "kkey = :p AND kscope IN ('wa_stage','wa_ticket_fix','wa_agent','wa_booking')", ['p' => $p]);
    }
    Database::delete('ap_jobs', "phone IN ('" . implode("','", $phones) . "')");
    Database::delete('ap_wa_examples', "text_norm LIKE 'aplt%' OR text_norm LIKE '% aplt%'");
    Database::run("DELETE FROM rate_limits WHERE bucket LIKE 'ap\\_%'");
};
$cleanup();

$mk = static function (string $user, string $name, string $phone, string $role): int {
    $id = Database::insert('admins', ['username' => $user, 'password_hash' => password_hash('x', PASSWORD_DEFAULT), 'full_name' => $name,
        'phone' => $phone, 'role' => $role, 'is_active' => 1, 'must_change_pw' => 0]);
    AgentWallet::saveProfile($id, ['counter_name' => 'Mehsana', 'country_code' => '91', 'agent_kind' => 'person']);
    return $id;
};
$agentId = $mk('aplt_agent', 'APLT Desk Agent', $A, 'agent');
AgentWallet::setAgentCode($agentId, AgentWallet::nextFreeAgentCode('person'));
$code = AgentWallet::agentCodeLabel($agentId);
$mgrId = $mk('aplt_mgr', 'APLT Office Manager', $MGR, 'manager');
$mg2Id = $mk('aplt_mg2', 'APLT Unlinked Manager', $MG2, 'manager');
$k1 = $mk('aplt_k1', 'APLT Kumarjung One', $K1, 'agent');
AgentWallet::setAgentCode($k1, AgentWallet::nextFreeAgentCode('person'));
$k2 = $mk('aplt_k2', 'APLT Kumarjung Two', $K2, 'agent');
AgentWallet::setAgentCode($k2, AgentWallet::nextFreeAgentCode('person'));
Database::run("INSERT INTO ap_wa_links (phone, admin_id, via, verified_at, revoked_at) VALUES (:p, :a, 'otp', NOW(), NULL)
               ON DUPLICATE KEY UPDATE admin_id = VALUES(admin_id), via = 'otp', revoked_at = NULL", ['p' => normalisePhone('+' . $MGR), 'a' => $mgrId]);

/* =================================================================== */
section('norm(): an example never holds one person\'s details');
$raw = 'naam: Hari Bahadur Shahi, phone 98123 45678 / 9812345678, SHG-2026-00123, agent SHG-0027, staff SHG-S-0004, AG-RAM27, '
     . 'Rs 5,000 + 2500, UTR 430987651234, 2026-11-02, 5 Nov, hari.shahi@example.com, https://x.example/p?q=1, Aadhaar 2345 6789 0124';
$n = ApLearn::norm($raw);
$leak = [];
foreach (['hari', 'bahadur', 'shahi', '98123', '45678', '9812345678', '00123', '0027', '0004', 'ram27', '5,000', '5000', '2500', '430987651234',
          '2026', 'nov', 'example', '2345', '0124'] as $bad) {
    if (str_contains($n, $bad)) {
        $leak[] = $bad;
    }
}
check('phones, PNR, codes, amounts, UTR, dates, e-mail, link, ID number and the name are all placeholders', $leak === [], $n . ' · leaked: ' . implode(',', $leak));
check('…with the placeholders a model can read', str_contains($n, '<NAME>') && str_contains($n, '<PHONE>') && str_contains($n, '<PNR>') && str_contains($n, '<CODE>')
    && str_contains($n, '<AMOUNT>') && str_contains($n, '<REF>') && str_contains($n, '<DATE>') && str_contains($n, '<EMAIL>') && str_contains($n, '<URL>'), $n);
check('lower case, Devanagari digits as ASCII, small numbers (seats) kept', ApLearn::norm('२ सिट भोलि मेहसाना ९८१२३४५६७८') === '2 सिट भोलि मेहसाना <PHONE>'
    && ApLearn::norm('Statement 30 DIN') === 'statement 30 din');
check('"names Ram, Sita ra Gita" → the whole list goes', ApLearn::norm('names Ram, Sita ra Gita 9812345678') === 'names <NAME> <PHONE>', ApLearn::norm('names Ram, Sita ra Gita 9812345678'));
check('the intent\'s own personal values go too (the person searched for)', ApLearn::norm('Ramesh ko number chahiyo', ['intent' => 'directory', 'query' => 'Ramesh']) === '<NAME> ko number chahiyo');
check('…and the passenger of a sale', !str_contains(ApLearn::norm('2 seat bholi Surat, Ram Thapa 9812345671', ['intent' => 'book', 'name' => 'Ram Thapa']), 'ram'));
$idem = true;
foreach ([$raw, 'SHG-2026-00123 ko naam Sita Thapa banaidinus', 'jama 1500 UTR430987651234', 'names Ram, Sita'] as $s) {
    $idem = $idem && ApLearn::norm(ApLearn::norm($s)) === ApLearn::norm($s);
}
check('idempotent: norm(norm(x)) = norm(x)', $idem);
$sk = ApLearn::skeleton(['intent' => 'edit', 'pnr' => 'SHG-2026-00123', 'field' => 'name', 'value' => 'Sita', 'name' => 'Ram', 'phone' => '9812345678',
                         'amount' => 500, 'date' => date('Y-m-d'), 'seats' => 2, 'direction' => 'toNepal', 'query' => 'x', 'answer' => 'y']);
check('skeleton() keeps the intent and the non-personal slots only', $sk === ['intent' => 'edit', 'field' => 'name', 'direction' => 'toNepal', 'seats' => 2], json_encode($sk));

/* =================================================================== */
section('record / fast / shots');
$t1 = "$tag mero paisa kahile aaucha hola";
ApLearn::record($t1, ['intent' => 'balance'], 'parser');
check('one example is not trusted yet (ok_hits 1 < 2): the model still reads it', ApLearn::fast($t1) === null && (int) (exRow($t1)['ok_hits'] ?? 0) === 1);
ApLearn::record($t1, ['intent' => 'balance'], 'parser');
$fastBefore = ApLearn::stats()['fastToday'];
$f = ApLearn::fast($t1);
check('confirmed twice → the fast path answers without the model', ($f['intent'] ?? '') === 'balance' && !empty($f['learned']), json_encode($f));
check('…and counts it for today', ApLearn::stats()['fastToday'] === $fastBefore + 1);

$t2 = "$tag sabai commission nikalidinus 5000";
ApLearn::record($t2, ['intent' => 'withdraw', 'amount' => 5000], 'confirmed');
ApLearn::record("$tag sabai commission nikalidinus 9000", ['intent' => 'withdraw', 'amount' => 9000], 'confirmed');
$f = ApLearn::fast("$tag sabai commission nikalidinus 7000");
check('another amount is the same example; the amount comes from THIS message', ($f['intent'] ?? '') === 'withdraw' && (float) ($f['amount'] ?? 0) === 7000.0, json_encode($f));
check('…and the stored example has no amount at all', !str_contains((string) exRow($t2)['text_norm'], '5000') && !str_contains((string) exRow($t2)['intent_json'], '5000'),
    (string) exRow($t2)['text_norm'] . ' ' . (string) exRow($t2)['intent_json']);
ApLearn::record($t1, ['intent' => 'statement'], 'parser');
check('a different certain reading replaces the old one and must earn trust again', (json_decode((string) exRow($t1)['intent_json'], true)['intent'] ?? '') === 'statement'
    && ApLearn::fast($t1) === null);

$t3 = "$tag company ko thegana ra phone";
ApLearn::record($t3, ['intent' => 'company'], 'office');
check('an office example is trusted at once', (ApLearn::fast($t3)['intent'] ?? '') === 'company' && exRow($t3)['source'] === 'office');
ApLearn::record($t3, ['intent' => 'bus'], 'confirmed');
check('…a member confirming something else keeps it, but the doubt pauses its fast path', (json_decode((string) exRow($t3)['intent_json'], true)['intent'] ?? '') === 'company'
    && ApLearn::fast($t3) === null);

ApLearn::record("$tag k cha khabar", ['intent' => 'question', 'answer' => 'x'], 'confirmed');
check('question / other are never learnt', exRow("$tag k cha khabar") === null);
ApLearn::record('3', ['intent' => 'balance'], 'parser');
check('nothing but digits / placeholders: nothing learnt', exRow('3') === null);

$t4 = "SHG-2026-00999 ko naam Sita Thapa banaidinus $tag";
ApLearn::record($t4, ['intent' => 'edit', 'pnr' => 'SHG-2026-00999', 'field' => 'name', 'value' => 'Sita Thapa'], 'office');
$row4 = exRow($t4);
check('an edit example: "<PNR> ko naam <NAME>", the new value nowhere', $row4 !== null && !str_contains((string) $row4['text_norm'], 'sita')
    && !str_contains((string) $row4['intent_json'], 'Sita') && str_contains((string) $row4['text_norm'], '<PNR>'), (string) ($row4['text_norm'] ?? ''));
check('…and a ticket change never takes the fast path (the new value is free text)', ApLearn::fast($t4) === null);

$sh = ApLearn::shots("$tag mero commission kahile aaucha", 6);
check('shots(): the closest examples, by shared words', $sh !== [] && str_contains($sh[0]['text'], $tag), json_encode(array_slice($sh, 0, 2), JSON_UNESCAPED_UNICODE));
$own = exRow($t2);
$skip = ApLearn::shots($t2, 12, (string) $own['text_hash']);
check('…never the example itself when it is being practised', array_filter($skip, static fn(array $s): bool => $s['text'] === $own['text_norm']) === []);
$st = ApLearn::shotsText("$tag mero commission kahile aaucha");
check('shotsText(): compact prompt lines with the placeholder legend', str_contains($st, '<PNR>') && str_contains($st, '→ {"intent"'), $st);
$up = ApBrain::userPrompt("$tag hello", 'ne', ['role' => 'agent', 'full_name' => 'X'], $st);
check('…in the changing part of the prompt, before the message (the cached system part stays the same)', strpos($up, 'confirmed before') < strpos($up, "Message:\n")
    && !str_contains(ApBrain::system(false), 'confirmed before') && in_array('form', ApBrain::INTENTS, true));

/* =================================================================== */
section('practice() — a stub stands in for the model');
$oracle = static function (string $text, string $shots, int $timeout) use (&$sawSelf): ?array {
    $r = Database::fetch('SELECT intent_json FROM ap_wa_examples WHERE text_hash = :h', ['h' => sha1($text)]);
    if (str_contains($shots, '- "' . $text . '" →')) {
        $sawSelf = true;
    }
    return $r !== null ? json_decode((string) $r['intent_json'], true) : null;
};
$sawSelf = false;
$p = ApLearn::practice(60, $oracle);
check('a reader that knows every answer scores 100 %', $p['ok'] && $p['n'] > 0 && $p['correct'] === $p['n'] && $p['pct'] === 100 && $p['misses'] === [], json_encode(array_diff_key($p, ['misses' => 1])));
check('…the example being read is never in its own few-shot lines', $sawSelf === false);
$run = Database::fetch('SELECT * FROM ap_ai_practice ORDER BY id DESC LIMIT 1');
check('…one ap_ai_practice row: model, n, correct, avg and median ms', $run !== null && $run['model'] === 'stub' && (int) $run['n'] === $p['n'] && (int) $run['correct'] === $p['n']);
$px = (int) exRow($t2)['px_n'];
$p = ApLearn::practice(60, static fn(string $t, string $s, int $to): ?array => ['intent' => 'other']);
check('a reader that always says "other" scores 0 % and names its misses', $p['ok'] && $p['correct'] === 0 && $p['misses'] !== [] && str_contains($p['notes'], 'missed: '),
    $p['notes']);
check('…each example remembers its practice (px_n / px_ok), so shots show the hard ones first', (int) exRow($t2)['px_n'] === $px + 1 && (int) exRow($t2)['px_ok'] >= 1);
$p = ApLearn::practice(60, static fn(string $t, string $s, int $to): ?array => null);
check('no answer from the reader counts as wrong, never as right', $p['ok'] && $p['correct'] === 0);
$p = ApLearn::practice(30);
check('the real model with no server answering: a quiet skip, no row', !$p['ok'] && str_contains($p['skipped'], 'not answering'), $p['skipped']);
check('inHours(): "1-5", "23-4" across midnight, lists', ApLearn::inHours('1-5', 3) && !ApLearn::inHours('1-5', 6) && ApLearn::inHours('23-4', 0)
    && ApLearn::inHours('23-4', 23) && !ApLearn::inHours('23-4', 12) && ApLearn::inHours('1-5,14', 14));

/* =================================================================== */
section('the desk keeps typed commands as examples');
AgentWallet::record($agentId, 'commission', 5000, ['note' => 'aplt test']);
$hits0 = (int) (exRow('balance')['hits'] ?? 0);
$r = wa($A, 'balance');
check('"balance" answers as before…', str_contains(txt($r), '🟢'), txt($r));
check('…and is a "parser" example now (or one more hit)', (int) (exRow('balance')['hits'] ?? 0) === $hits0 + 1
    && (json_decode((string) (exRow('balance')['intent_json'] ?? ''), true)['intent'] ?? '') === 'balance');
$sale = 'Ramkumar Thapamagar 9812345681, 2 seat bholi Surat';
// stored under the words WITHOUT the passenger (the sale's own name is taken out too): "<NAME> <PHONE> 2 seat bholi surat"
$saleHash = sha1('<NAME> <PHONE> 2 seat bholi surat');
$saleHits = (int) Database::scalar('SELECT hits FROM ap_wa_examples WHERE text_hash = :h', ['h' => $saleHash], 0);
$r = wa($A, $sale);
$sx = Database::fetch('SELECT * FROM ap_wa_examples WHERE text_hash = :h', ['h' => $saleHash]);
$leaks = (int) Database::scalar("SELECT COUNT(*) FROM ap_wa_examples WHERE text_norm LIKE '%ramkumar%' OR text_norm LIKE '%thapamagar%' OR text_norm LIKE '%9812345681%'", [], 0);
check('a one-line sale is a "book" example without the passenger\'s name or number', $sx !== null && (int) $sx['hits'] === $saleHits + 1
    && (json_decode((string) $sx['intent_json'], true)['intent'] ?? '') === 'book' && $leaks === 0,
    (string) ($sx['text_norm'] ?? 'no row') . ' · leaks ' . $leaks . ' · ' . mb_substr(txt($r), 0, 80));
wa($A, 'no');
wa($A, '3');
check('a menu digit is not an example ("3" means balance only on that menu)', exRow('3') === null);

/* =================================================================== */
section('new: "form" — my own filled form');
$r = wa($A, 'form');
check('no form on file: says so, points to /me/', str_contains(txt($r), appUrl('me/')) && !str_contains(txt($r), 'join-form.php'), txt($r));
$d = AgentPro::newDraft();
AgentPro::saveDraft($d['draft_key'], ['full_name' => 'APLT Desk Agent']);
Database::update('ap_applications', ['admin_id' => $agentId, 'status' => 'approved', 'submitted_at' => date('Y-m-d H:i:s')], 'draft_key = :k', ['k' => $d['draft_key']]);
$r = wa($A, 'mero form');
preg_match('#join-form\.php\?t=([0-9]+-[a-f0-9]{32})&auto=pdf#', txt($r), $fm);
check('sent within 30 days: the personal signed link (?t=…&auto=pdf) that really opens this form', isset($fm[1])
    && (int) (ApForm::byApplicantToken($fm[1])['admin_id'] ?? 0) === $agentId, txt($r));
Database::update('ap_applications', ['submitted_at' => date('Y-m-d H:i:s', strtotime('-40 days'))], 'draft_key = :k', ['k' => $d['draft_key']]);
$r = wa($A, 'फारम');
check('older than 30 days: /me/form.php?auto=pdf after signing in (no public link)', str_contains(txt($r), 'me/form.php?auto=pdf') && !str_contains(txt($r), 'join-form.php'), txt($r));
check('…audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_form' AND entity_id = :i", ['i' => (string) $agentId]));

/* =================================================================== */
section('new: office "id <code | phone | name>"');
$r = wa($MGR, 'id ' . $code);
check('by code: the member\'s ID card picture (signed), visiting card and one line', str_contains((string) ($r['media'] ?? ''), 'api/agentpro-card.php?m=' . $agentId . '&s=id-front')
    && str_contains(txt($r), 'APLT Desk Agent') && str_contains(txt($r), 's=vc-front') && str_contains(txt($r), $code), txt($r));
parse_str((string) parse_url((string) ($r['media'] ?? ''), PHP_URL_QUERY), $q);
check('…whose signature checks out', ApCard::checkSig((int) ($q['m'] ?? 0), (string) ($q['s'] ?? ''), (int) ($q['e'] ?? 0), (string) ($q['k'] ?? '')));
check('…audited', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'agentpro.wa_office_id' AND entity_id = :i", ['i' => (string) $agentId]));
$r = wa($MGR, 'id ' . $A);
check('by mobile', str_contains((string) ($r['media'] ?? ''), 'm=' . $agentId . '&'), txt($r));
$r = wa($MGR, 'ID ' . strtolower($code) . ' back');
check('"back" → the back of the card', str_contains((string) ($r['media'] ?? ''), 'm=' . $agentId . '&s=id-back'), (string) ($r['media'] ?? ''));
$r = wa($MGR, 'id aplt kumarjung');
check('several matches → a list with their codes, no picture', ($r['media'] ?? null) === null && str_contains(txt($r), 'APLT Kumarjung One') && str_contains(txt($r), 'APLT Kumarjung Two')
    && str_contains(txt($r), AgentWallet::agentCodeLabel($k1)), txt($r));
$r = wa($MGR, 'id aplt kumarjung two');
check('…every word narrows it to one', str_contains((string) ($r['media'] ?? ''), 'm=' . $k2 . '&'), txt($r));
check('nobody → a clear "not found" with the three ways to ask', str_contains(txt(wa($MGR, 'id zzqqxx nobody')), 'id SHG-0027'));
$r = wa($A, 'id ' . AgentWallet::agentCodeLabel($k1));
check('an agent cannot pull someone else\'s card', ($r['media'] ?? null) === null && str_contains(txt($r), '🔒'), txt($r));
$r = wa($MG2, 'id ' . $code);
check('a manager whose number is not OTP-linked cannot either', ($r['media'] ?? null) === null && str_contains(txt($r), '🔒'), txt($r));
$r = wa($A, 'id back');
check('"id back" is still the agent\'s own card back', str_contains((string) ($r['media'] ?? ''), 'm=' . $agentId . '&s=id-back'));
$r = wa($A, 'id card aayena hai');
check('"id card aayena …" from an agent is a sentence, not a lookup (no 🔒)', !str_contains(txt($r), '🔒'), txt($r));

/* =================================================================== */
section('an AI reading confirmed with "ho" becomes an example; then no model is needed');
$phpArgs = php_ini_loaded_file() ? [PHP_BINARY, '-c', (string) php_ini_loaded_file()] : [PHP_BINARY];
$devNull = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
$mockScript = getenv('AP_MOCK') ?: dirname(__DIR__) . '/tools/mock-ollama.php';
$hitsLog = sys_get_temp_dir() . '/aplt-router-' . getmypid() . '.log';
$router = sys_get_temp_dir() . '/aplt-router-' . getmypid() . '.php';
// A model that reads "withdraw" for this test's words (and is tools/mock-ollama.php for everything else)
file_put_contents($router, '<?php
$p = (string) parse_url((string) $_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (str_ends_with($p, "/api/chat")) {
    $in = json_decode((string) file_get_contents("php://input"), true) ?: [];
    $u = "";
    foreach ((array) ($in["messages"] ?? []) as $m) { if (($m["role"] ?? "") !== "system") { $u .= (string) ($m["content"] ?? ""); } }
    $msg = preg_match("/Message:\\s*\\n(.*)$/s", $u, $mm) === 1 ? $mm[1] : $u;
    if (str_contains($msg, ' . var_export($tag, true) . ') && str_contains($msg, "jhikera")) {
        file_put_contents(' . var_export($hitsLog, true) . ', "x", FILE_APPEND);
        header("Content-Type: application/json");
        echo json_encode(["model" => $in["model"] ?? "", "done" => true, "message" => ["role" => "assistant", "content" => json_encode(["intent" => "withdraw"])],
                          "prompt_eval_count" => 50, "eval_count" => 5]);
        return true;
    }
}
require ' . var_export($mockScript, true) . ';');
$upP = static fn(): bool => @file_get_contents('http://127.0.0.1:' . $PORT . '/api/version') !== false;
$srv = proc_open(array_merge($phpArgs, ['-S', '127.0.0.1:' . $PORT, $router]), [1 => ['file', $devNull, 'w'], 2 => ['file', $devNull, 'w']], $pp);
for ($i = 0; $i < 50 && !$upP(); $i++) { usleep(100000); }
$set('ap_ai_on', '1', 'bool');
Settings::flush();
ApAi::resetHealth();
check('the model (a router in front of the mock) answers', $upP() && ApAi::enabled());
// 31 Oct 2026: words the typed-command reader does not know ("mero commission … nikalera" is now read without the
// model, as "withdraw 2000"), so this still tests what the AI reads and the desk learns
$w = "$tag mero kamai bata 2000 jhikera pathaidinus";
$modelCalls = static fn(): int => strlen((string) @file_get_contents($hitsLog));
$round = static function (int $i) use ($A, $w, $agentId): array {
    $r = wa($A, $w);
    $p = pendingOf($A);
    $asked = str_contains(txt($r), '*ho*') && ($p['kind'] ?? '') === 'withdraw' && AgentWallet::openPayoutRequests($agentId) === [];
    $r2 = wa($A, 'ho');
    $filed = count(AgentWallet::openPayoutRequests($agentId)) === 1;
    Database::delete('agent_payout_requests', 'agent_admin_id = :a', ['a' => $agentId]);   // the office "handled" it: room for the next one
    return [$asked, $filed, $p, txt($r) . ' / ' . txt($r2)];
};
[$asked, $filed, $p1, $why] = $round(1);
check('round 1: the AI reads "withdraw" → it ASKS for "ho" first, nothing filed', $asked && $modelCalls() === 1, $why);
check('…the pending action carries the reading, normalised (no one\'s details)', ($p1['learn']['n'] ?? '') === ApLearn::norm($w) && ($p1['learn']['i']['intent'] ?? '') === 'withdraw');
check('…"ho" files the request — and the reading is now a "confirmed" example', $filed && (exRow($w)['source'] ?? '') === 'confirmed' && (int) (exRow($w)['ok_hits'] ?? 0) === 1, $why);
[$asked, $filed, , $why] = $round(2);
check('round 2: one confirmation is not enough — the model reads it again; "ho" → ok_hits 2', $asked && $filed && $modelCalls() === 2 && (int) exRow($w)['ok_hits'] === 2, $why);

// the practice run on the model itself (document-class: it would give way to WhatsApp)
$p = ApLearn::practice(60);
$pr = Database::fetch('SELECT * FROM ap_ai_practice ORDER BY id DESC LIMIT 1');
check('practice() on the VPS model: reads the examples, one run row named after the model',
    ($p['ok'] && $p['model'] === 'qwen3.5:4b' && $p['n'] > 0 && $pr !== null && $pr['model'] === 'qwen3.5:4b' && (int) exRow($w)['px_ok'] >= 1)
    || $p['skipped'] === 'WhatsApp is using the model', json_encode(array_diff_key($p, ['misses' => 1])));

proc_terminate($srv);
for ($i = 0; $i < 30 && $upP(); $i++) { usleep(100000); }
ApAi::resetHealth();
check('the model server is gone now', !ApAi::available(true));
$fast0 = ApLearn::stats()['fastToday'];
$calls0 = $modelCalls();                     // the practice run above also read these words on the model
$r = wa($A, "$tag mero kamai bata 1500 jhikera pathaidinus");
check('round 3: the same words (another amount) answer WITHOUT a model — the amount from THIS message — and still only ask for "ho"',
    str_contains(txt($r), '*ho*') && str_contains(txt($r), '1,500')
    && (pendingOf($A)['kind'] ?? '') === 'withdraw' && AgentWallet::openPayoutRequests($agentId) === [] && $modelCalls() === $calls0, txt($r));
check('…counted as a fast-path answer today', ApLearn::stats()['fastToday'] === $fast0 + 1);
check('"no" drops it: nothing filed', str_contains(txt(wa($A, 'no')), '👍') && AgentWallet::openPayoutRequests($agentId) === []);
$set('ap_learn_fast', '0', 'bool');
Settings::flush();
$r = wa($A, $w);
check('fast path switched off (ap_learn_fast = 0): the desk falls back to its menu', !str_contains(txt($r), '*ho*') && (pendingOf($A)['kind'] ?? '') !== 'withdraw', txt($r));
@unlink($router);
@unlink($hitsLog);

/* ---- tidy up ---- */
$cleanup();
Database::delete('ap_applications', 'draft_key = :k', ['k' => $d['draft_key']]);
Database::delete('ap_ai_practice', 'id > :i', ['i' => $practiceFrom]);
foreach ($keep as $k => $v) {
    if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); } else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); }
}
Settings::flush();
ApAi::resetHealth();

echo "\n----------------------------------------\nPASSED: $PASS   FAILED: $FAIL\n----------------------------------------\n";
exit($FAIL === 0 ? 0 : 1);
