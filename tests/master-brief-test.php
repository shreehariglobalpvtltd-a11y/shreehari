<?php
/**
 * S HARI AI master brief + the 29 Oct 2026 build (owner's "AI System Prompt
 * (Opus 5.5 Max)").
 *
 *   php -c .claude/php-dev.ini tests/master-brief-test.php
 *
 * Pins:
 *  - Claude request shape: Opus 5.5 gets adaptive thinking, the office's
 *    effort, room in max_tokens, the refusal fallback (header + body) and no
 *    temperature; an older model is sent exactly as before; a refusal is seen;
 *  - the master brief is '' while off, and lists ONLY the tools handed in;
 *  - the staging gate: a plain "no" is never consent, "ho, <name>" still is;
 *    a quote parked under a web session key is consumed by takeStage;
 *  - Aadhaar Verhoeff (server side);
 *  - WhatsApp groups: time reading, the pickup-time correction, the
 *    question / mention tests, decide() honouring every switch, quiet hours
 *    and the per-group list, inbound() keeping the row and replying through
 *    the transport — never anything private;
 *  - the payment screenshot matcher, the agent lessons, the inbox pause.
 *
 * Writes only throwaway settings / kv / log rows and restores them. CLI only.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aibrain.php';
require_once INCLUDE_PATH . '/aimaster.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/wagroup.php';
require_once INCLUDE_PATH . '/wapayproof.php';
require_once INCLUDE_PATH . '/waonboard.php';
require_once INCLUDE_PATH . '/wainbox.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

/* Settings this test touches: remembered, restored at the end. */
const MB_KEYS = ['ai_master_prompt_on', 'ai_master_prompt', 'ai_claude_effort', 'ai_claude_fallback', 'wa_group_reply_on',
    'wa_group_correct_on', 'wa_groups', 'wa_group_quiet_from', 'wa_group_quiet_to', 'wa_pay_ocr_on', 'wa_onboard_on'];
$saved = [];
foreach (MB_KEYS as $k) {
    $saved[$k] = Database::fetch('SELECT svalue, stype, sgroup FROM settings WHERE skey = :k', ['k' => $k]);
}
function mb_restore(array $saved): void {
    foreach ($saved as $k => $row) {
        if ($row === null) {
            Database::delete('settings', 'skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v, stype = :t WHERE skey = :k', ['v' => $row['svalue'], 't' => $row['stype'], 'k' => $k]);
        }
    }
}
$call = static function (string $class, string $method, ...$args) {
    $m = new ReflectionMethod($class, $method);
    $m->setAccessible(true);
    return $m->invoke(null, ...$args);
};
const MB_GROUP = 'test-group-120363mb';
const MB_PHONE = '919800099911';

try {
    /* ---- Claude request shape ------------------------------------------ */
    Settings::set('ai_claude_effort', 'low', 'string', 'ai');
    Settings::set('ai_claude_fallback', '1', 'bool', 'ai');
    [$p, $h] = AiBrain::claudeShape('claude-opus-5-5', ['model' => 'claude-opus-5-5', 'max_tokens' => 700, 'temperature' => 0.4, 'messages' => []], ['x-api-key: k']);
    check('Opus 5.5: adaptive thinking', ($p['thinking']['type'] ?? '') === 'adaptive');
    check('Opus 5.5: effort from the office (low)', ($p['output_config']['effort'] ?? '') === 'low');
    check('Opus 5.5: max_tokens raised to make room for thinking', (int) $p['max_tokens'] >= 2048);
    check('Opus 5.5: temperature stripped (a 400 on this model)', !isset($p['temperature']));
    check('Opus 5.5: refusal fallback in body', ($p['fallbacks'] ?? '') === 'default');
    check('Opus 5.5: fallback beta header', in_array('anthropic-beta: server-side-fallback-2026-07-01', $h, true));
    Settings::set('ai_claude_effort', 'max', 'string', 'ai');
    check('effort follows the setting (max)', (AiBrain::claudeShape('claude-opus-5-5', ['max_tokens' => 10], [])[0]['output_config']['effort'] ?? '') === 'max');
    Settings::set('ai_claude_effort', 'turbo', 'string', 'ai');
    check('an unknown effort falls back to low', AiBrain::claudeEffort() === 'low');
    Settings::set('ai_claude_fallback', '0', 'bool', 'ai');
    check('fallback off: no fallbacks field', !isset(AiBrain::claudeShape('claude-opus-5-5', ['max_tokens' => 10], [])[0]['fallbacks']));
    Settings::set('ai_claude_fallback', '1', 'bool', 'ai');
    [$p2, $h2] = AiBrain::claudeShape('claude-sonnet-5', ['max_tokens' => 2048], []);
    check('Sonnet 5: adaptive but no documented default fallback', ($p2['thinking']['type'] ?? '') === 'adaptive' && !isset($p2['fallbacks']) && $h2 === []);
    [$p3, $h3] = AiBrain::claudeShape('claude-haiku-4-5', ['max_tokens' => 700, 'temperature' => 0.3], []);
    check('Haiku 4.5: no thinking / effort added', !isset($p3['thinking']) && !isset($p3['output_config']) && (int) $p3['max_tokens'] === 700);
    check('Haiku 5.5 counts as adaptive', AiBrain::claudeAdaptive('claude-haiku-5-5'));
    check('default Claude model is Opus 5.5', AiBrain::CLAUDE_DEFAULT === 'claude-opus-5-5');
    check('a refusal is recognised', AiBrain::claudeRefusal(['stop_reason' => 'refusal', 'stop_details' => ['category' => 'cyber']]) === 'cyber');
    check('a normal answer is not a refusal', AiBrain::claudeRefusal(['stop_reason' => 'end_turn']) === null);

    /* ---- Master brief -------------------------------------------------- */
    Settings::set('ai_master_prompt_on', '0', 'bool', 'ai');
    check('brief is empty while off', AiMaster::brief(['role' => 'customer'], []) === '');
    check('assistant keeps its old name while off', AiMaster::assistantName() === 'SHG Sahayak');
    Settings::set('ai_master_prompt_on', '1', 'bool', 'ai');
    Settings::set('ai_master_prompt', '', 'string', 'ai');
    $cat = [['name' => 'find_ticket', 'description' => 'Look up ONE booking by its PNR. More text.'], ['name' => 'plan_ticket', 'description' => 'Quote a seat.']];
    $b = AiMaster::brief(['role' => 'staff'], $cat);
    check('brief on: S HARI AI + the four gates', str_contains($b, 'S HARI AI') && str_contains($b, 'FOUR SECURITY GATES') && str_contains($b, 'STAGING'));
    check('brief lists exactly the tools handed in', str_contains($b, '- find_ticket: Look up ONE booking by its PNR.') && str_contains($b, '- plan_ticket') && !str_contains($b, 'compare_faces'));
    check('brief carries the AGENT mode for staff', str_contains($b, 'MODE: AGENT'));
    check('no tools: says so', str_contains(AiMaster::brief(['role' => 'customer'], []), 'YOUR TOOLS: none'));
    check('{company} is filled in', !str_contains($b, '{company}') && !str_contains($b, '{office_phone}'));
    Settings::set('ai_master_prompt', 'Custom brief for {company}.', 'string', 'ai');
    check('the office text replaces the built-in one', str_starts_with(AiMaster::brief(['role' => 'admin'], []), 'Custom brief for ') && str_contains(AiMaster::brief(['role' => 'admin'], []), 'MODE: OFFICE'));
    check('assistant is S HARI AI while on', AiMaster::assistantName() === 'S HARI AI');
    require_once INCLUDE_PATH . '/aiprompt.php';
    check('the live briefing introduces S HARI AI', str_starts_with(ai_system_prompt(), 'You are S HARI AI'));
    Settings::set('ai_master_prompt_on', '0', 'bool', 'ai');

    /* ---- Staging gate -------------------------------------------------- */
    $no = static fn(string $t): bool => (bool) $call(AiTools::class, 'saidNo', ['messageText' => $t]);
    check('"hoina" is a no', $no('hoina'));
    check('"होइन" is a no', $no('होइन'));
    check('"cancel nagarnus" is a no', $no('cancel nagarnus'));
    check('"no" alone is a no', $no('No.'));
    check('"ho" is not a no', !$no('ho'));
    check('"ho, Ram Bahadur Thapa" is not a no', !$no('ho, Ram Bahadur Thapa'));
    check('"Biratnagar 2 seat" is not a no', !$no('Biratnagar 2 seat'));
    check('"mob no 9812345678" is not a no', !$no('mob no 9812345678'));
    check('"विराटनगर" is not a no', !$no('विराटनगर जाने'));

    $ctx = ['phone' => MB_PHONE, 'stageKey' => 'web:mbtest' . bin2hex(random_bytes(3)), 'turn' => 1];
    $call(AiTools::class, 'stage', $ctx, 'sale', ['opts' => ['seats' => 1]]);
    $ctx['turn'] = 2;
    check('a web quote is there to take', $call(AiTools::class, 'peekStage', $ctx, 'sale') !== null);
    $call(AiTools::class, 'takeStage', $ctx, 'sale');
    check('taking a web quote consumes it (no second sale)', $call(AiTools::class, 'peekStage', $ctx, 'sale') === null);

    /* ---- Aadhaar ------------------------------------------------------- */
    $base = '23412341234';
    $c = 0;
    $inv = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];
    $dT = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    $pT = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,6,8,7,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    foreach (array_reverse(str_split($base)) as $i => $ch) { $c = $dT[$c][$pT[($i + 1) % 8][(int) $ch]]; }
    $good = $base . $inv[$c];                                 // check digit generated independently
    $bad  = $base . (($inv[$c] + 1) % 10);
    check('Verhoeff accepts a generated Aadhaar', StaffApps::verhoeffOk($good));
    check('Verhoeff rejects a one-digit typo', !StaffApps::verhoeffOk($bad));
    check('idNumberOk(aadhaar) uses the checksum', StaffApps::idNumberOk('aadhaar', $good) && !StaffApps::idNumberOk('aadhaar', $bad));
    check('idNumberOk(aadhaar) still refuses a leading 1', !StaffApps::idNumberOk('aadhaar', '1' . substr($good, 1)));

    /* ---- WhatsApp groups ----------------------------------------------- */
    $t = WaGroup::timesIn('surat ko pickup 2 baje ho');
    check('"2 baje" reads as 2:00 or 14:00', count($t) === 1 && $t[0][1] === [120, 840]);
    check('"13:30" reads as 13:30', WaGroup::timesIn('13:30 ma')[0][1] === [810]);
    check('"9 pm" reads as 21:00', WaGroup::timesIn('9 pm')[0][1] === [1260]);
    check('Devanagari digits are read', WaGroup::correction('सुरत २ बजे', ['सुरत' => ['13:00']]) !== null);
    $stops = ['surat' => ['13:00'], 'kamrej' => ['13:30'], 'vapi' => ['10:00', '11:00']];
    $fix = WaGroup::correction('Surat ko pickup 2 baje ho', $stops);
    check('a wrong pickup time is corrected', $fix !== null && str_contains($fix, '13:00') && str_contains($fix, 'सच्याउँछु') && str_contains($fix, 'source'));
    check('the right time (1 baje = 13:00) is left alone', WaGroup::correction('Surat 1 baje', $stops) === null);
    check('the right time (13:00) is left alone', WaGroup::correction('surat 13:00', $stops) === null);
    check('two stops in one message: silence', WaGroup::correction('surat 2 baje, kamrej 3 baje', $stops) === null);
    check('a stop with two times: silence', WaGroup::correction('vapi 4 baje', $stops) === null);
    check('a question with no time: silence', WaGroup::correction('surat kati baje?', $stops) === null);
    check('"Surat-bata" style word boundary works', WaGroup::correction('Suratbata 2 baje', $stops) === null);
    check('"kati baje?" is a question', WaGroup::looksLikeQuestion('bholi surat kati baje'));
    check('"namaste sabai lai" is not a question', !WaGroup::looksLikeQuestion('namaste sabai lai'));
    check('"@S Hari AI" calls it', WaGroup::mentioned('@S Hari AI bholi seat?'));
    check('"AI बताउ" calls it', WaGroup::mentioned('AI बताउ भोलि कति सिट'));
    check('plain chat does not call it', !WaGroup::mentioned('khana khayau?'));

    Settings::set('wa_group_quiet_from', '22:00', 'string', 'whatsapp');
    Settings::set('wa_group_quiet_to', '06:00', 'string', 'whatsapp');
    check('23:30 is quiet', WaGroup::quietNow(strtotime('2026-10-29 23:30:00')));
    check('05:59 is quiet', WaGroup::quietNow(strtotime('2026-10-30 05:59:00')));
    check('12:00 is not quiet', !WaGroup::quietNow(strtotime('2026-10-30 12:00:00')));

    $noon = strtotime(date('Y-m-d') . ' 12:00:00');
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('wa_group_reply', 'wa_group_unasked')");
    Settings::set('wa_group_reply_on', '0', 'bool', 'whatsapp');
    Settings::set('wa_groups', [MB_GROUP => ['name' => 'Test office', 'reply' => true]], 'json', 'whatsapp');
    check('master switch off: silence', WaGroup::decide(MB_GROUP, '@S Hari AI hello?', $noon) === null);
    Settings::set('wa_group_reply_on', '1', 'bool', 'whatsapp');
    check('an unknown group: silence', WaGroup::decide('other-group-x', 'bholi kati seat?', $noon) === null);
    Settings::set('wa_groups', [MB_GROUP => ['name' => 'Test office', 'reply' => false]], 'json', 'whatsapp');
    check('a listen-only group: silence', WaGroup::decide(MB_GROUP, 'bholi kati seat?', $noon) === null);
    Settings::set('wa_groups', [MB_GROUP => ['name' => 'Test office', 'reply' => true]], 'json', 'whatsapp');
    check('quiet hours: silence even when called', WaGroup::decide(MB_GROUP, '@S Hari AI timetable?', strtotime(date('Y-m-d') . ' 23:00:00')) === null);
    check('greetings get silence', WaGroup::decide(MB_GROUP, 'good morning sabai lai', $noon) === null);
    $pub = WaGroup::decide(MB_GROUP, '@S Hari AI full timetable', $noon);
    check('a called public question gets a public answer', $pub !== null && str_starts_with($pub['why'], 'local:'));
    check('a PNR is never answered in a group', WaLocal::answerPublic('SHG-2026-00123 status') === null);
    check('a private question ("my ticket") is not a public intent', WaLocal::answerPublic('mero ticket pathau') === null);
    Settings::set('wa_group_correct_on', '1', 'bool', 'whatsapp');

    $sent = [];
    WaGroup::$transport = static function (string $gid, string $text) use (&$sent): array { $sent[] = [$gid, $text]; return ['success' => true]; };
    Database::run("DELETE FROM rate_limits WHERE bucket IN ('wa_group_reply', 'wa_group_unasked')");
    $wamid = 'wamid.mbtest.' . bin2hex(random_bytes(4));
    WaGroup::inbound(['group_id' => MB_GROUP, 'from' => MB_PHONE, 'id' => $wamid, 'type' => 'text'], '@S Hari AI full timetable');
    $row = Database::fetch('SELECT * FROM wa_group_log WHERE wamid = :w', ['w' => $wamid]);
    check('the group message is kept', $row !== null && (string) $row['group_id'] === MB_GROUP);
    if (!WaGroup::quietNow()) {
        check('the reply went to the GROUP id', $sent !== [] && $sent[0][0] === MB_GROUP);
        check('the row records the reply', $row !== null && (int) $row['replied'] === 1);
    }
    WaGroup::$transport = null;

    /* ---- Payment screenshot -------------------------------------------- */
    $w = [['id' => 1, 'pnr' => 'SHG-2026-00111', 'total' => 2000.0], ['id' => 2, 'pnr' => 'SHG-2026-00222', 'total' => 4000.0]];
    check('PNR in the caption decides', (WaPayProof::match($w, 'shg 2026 00222 paid', null)['id'] ?? 0) === 2);
    check('a PNR that is not waiting: no match', WaPayProof::match($w, 'SHG-2026-00999', 4000.0) === null);
    check('the amount singles one out', (WaPayProof::match($w, '', 4000.0)['id'] ?? 0) === 2);
    check('an amount that fits none: no match', WaPayProof::match($w, '', 1234.0) === null);
    check('two waiting, no amount: ask for the PNR', WaPayProof::match($w, '', null) === null);
    check('one waiting, no amount: that one', (WaPayProof::match([$w[0]], '', null)['id'] ?? 0) === 1);
    Settings::set('wa_pay_ocr_on', '0', 'bool', 'whatsapp');
    check('switch off: the old reply stands', WaPayProof::handle('9800099911', '', ['id' => 'm1', 'mime' => 'image/jpeg']) === null);
    Settings::set('wa_pay_ocr_on', '1', 'bool', 'whatsapp');
    WaPayProof::$fetcher = static fn(string $id): array => ['bytes' => 'x', 'mime' => 'image/jpeg'];
    WaPayProof::$reader  = static fn(string $b, string $m): array => ['is_payment' => true, 'success' => true, 'utr' => '512345678901', 'amount' => 2000.0];
    check('no booking waiting on this number: the old reply stands', WaPayProof::handle('9800099911', '', ['id' => 'm1', 'mime' => 'image/jpeg']) === null);
    WaPayProof::$fetcher = null;
    WaPayProof::$reader = null;

    /* ---- Agent lessons ------------------------------------------------- */
    $allSay = true;
    for ($d = 1; $d <= 7; $d++) {
        $allSay = $allSay && WaOnboard::lesson($d, 'Ram') !== '';
    }
    check('seven lessons, each with words', $allSay && WaOnboard::lesson(8) === '');
    check('lesson 1 teaches the real phrase', str_contains(WaOnboard::lesson(1), 'मेरो बिक्री') && str_contains(WaOnboard::lesson(1), 'hisab'));
    WaOnboard::ensure();
    $fake = 990000 + random_int(1, 9000);
    $made = date('Y-m-d 10:00:00', strtotime('-2 days'));
    check('day 3 of the account: lesson 1 is due first', WaOnboard::nextDue($fake, $made, time()) === 1);
    Database::insert('agent_lessons', ['admin_id' => $fake, 'day' => 1, 'sent_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
    check('then lesson 2', WaOnboard::nextDue($fake, $made, time()) === 2);
    Database::insert('agent_lessons', ['admin_id' => $fake, 'day' => 2, 'sent_at' => date('Y-m-d H:i:s')]);
    check('never two lessons on one day', WaOnboard::nextDue($fake, $made, time()) === null);
    check('nothing after day 14', WaOnboard::nextDue($fake, date('Y-m-d', strtotime('-20 days')), time()) === null);
    Database::delete('agent_lessons', 'admin_id = :a', ['a' => $fake]);

    /* ---- Inbox --------------------------------------------------------- */
    check('inbound text is read back', WaInbox::textOf('text', json_encode(['text' => ['body' => 'bholi seat?']])) === 'bholi seat?');
    check('a list pick is read back', WaInbox::textOf('interactive', json_encode(['interactive' => ['list_reply' => ['title' => 'Surat']]])) === 'Surat');
    WaInbox::pause(MB_PHONE, 2, 1);
    check('pausing the bot for a number', WaInbox::botPaused(MB_PHONE) && WaInbox::botPaused('9800099911'));
    WaInbox::pause(MB_PHONE, 0, 1);
    check('resuming it', !WaInbox::botPaused(MB_PHONE));
} catch (Throwable $e) {
    check('no exception: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    mb_restore($saved);
    try {
        Database::run("DELETE FROM wa_group_log WHERE group_id = :g", ['g' => MB_GROUP]);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_stage' AND kkey LIKE 'web:mbtest%'");
        Database::run("DELETE FROM audit_logs WHERE action = 'wa.bot_pause' AND entity_id = :p", ['p' => MB_PHONE]);
        Database::run("DELETE FROM rate_limits WHERE bucket IN ('wa_group_reply', 'wa_group_unasked', 'wa_pay_ocr')");
    } catch (Throwable $ignored) {
    }
}

echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
