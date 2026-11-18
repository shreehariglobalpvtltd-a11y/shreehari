<?php
/**
 * =====================================================================
 *  u8-contact-lang-test.php — each contact's language, and the marketing
 *  autopilot writing one line per language (19 Nov 2026, unit u8 / AI-2).
 *
 *  shari_test only. Test numbers 9190000800xx / 97790000800xx; every
 *  contact_lang row, campaign and recipient it makes is deleted at the
 *  end, and the backfill bookmark is put back. Settings are flipped in
 *  this process's cache only. No message is sent.
 *
 *    php -c .claude/php-dev.ini tests/u8-contact-lang-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/customerdirectory.php';
require_once INCLUDE_PATH . '/mkautopilot.php';

if (!defined('DB_NAME') || DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test — refusing to write\n"; exit(0); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function cacheSet(array $kv): void {
    Settings::get('company_name');
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    foreach ($kv as $k => [$v, $t]) { $c[$k] = ['value' => $v, 'type' => $t, 'public' => false]; }
    $rp->setValue(null, $c);
}

echo "\n=== u8 AI-2: contact language + autopilot split ===\n\n";
try { Database::scalar('SELECT 1 FROM contact_lang LIMIT 1'); }
catch (Throwable $e) { echo "  FAIL  contact_lang missing — apply database/upgrade-2026-11-19-u8-contact-lang.sql\n"; exit(1); }

$A = '919000080001'; $B = '919000080002'; $C = '9779000080003'; $Dn = '919000080004'; $E = '919000080005';
$all = [$A, $B, $C, $Dn, $E];
$clean = static fn() => Database::run("DELETE FROM contact_lang WHERE phone LIKE '91900008000%' OR phone LIKE '97790000800%'");
$clean();
/* the live-hook mark (contact_lang / hook_start_id) is shared state: saved here, put back at the end */
$hs0 = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'");
Database::run("DELETE FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'");
CustomerDirectory::resetHookMark();
cacheSet(['mk_default_lang' => ['ne', 'string'], 'mk_auto_langs' => ['ne,hi,en', 'string']]);

/* votes */
check('Nepali Devanagari → ne', CustomerDirectory::voteFor('भोलि सुरत जाने बस कति बजे छ?') === 'ne');
check('Hindi Devanagari → hi', CustomerDirectory::voteFor('मुझे कल सूरत जाना है, सीट मिलेगी?') === 'hi');
check('roman Nepali → ne', CustomerDirectory::voteFor('bholi ko ticket kati ho malai chahiyo') === 'ne');
check('roman Hindi → hi', CustomerDirectory::voteFor('mujhe kal ka ticket chahiye kitne ka hai') === 'hi');
check('English (3+ words) → en', CustomerDirectory::voteFor('I want two seats tomorrow please') === 'en');
check('"Hlo" / "ok" / a number / Gujarati → no vote',
    CustomerDirectory::voteFor('Hlo') === '' && CustomerDirectory::voteFor('ok') === '' && CustomerDirectory::voteFor('9800000000') === ''
    && CustomerDirectory::voteFor('મારે કાલે ટિકિટ જોઈએ છે') === '');

/* unknown / bad numbers */
check('unknown number → default ne', CustomerDirectory::lang($A) === 'ne');
check('bare 10-digit number is not stored', CustomerDirectory::noteLang('9000080001', 'मुझे कल सूरत जाना है') === ''
    && (int) Database::scalar("SELECT COUNT(*) FROM contact_lang WHERE phone = '9000080001'", [], 0) === 0);

/* chat majority */
CustomerDirectory::noteLang($B, 'मुझे कल सूरत जाना है, सीट मिलेगी?');
check('one Hindi line is not enough (needs 2) → default', CustomerDirectory::lang($B) === 'ne');
CustomerDirectory::noteLang('+' . $B, 'mujhe kal ka ticket chahiye kitne ka hai');
check('two Hindi lines → hi (a + prefix is the same number)', CustomerDirectory::lang($B) === 'hi');
$row = Database::fetch('SELECT * FROM contact_lang WHERE phone = :p', ['p' => $B]);
check('only codes and counters stored', $row !== null && (int) $row['hi_n'] === 2 && $row['lang'] === 'hi' && $row['source'] === 'chat' && count($row) === 7);
CustomerDirectory::noteLang($C, 'bholi ko ticket kati ho malai chahiyo');
CustomerDirectory::noteLang($C, 'I want two seats tomorrow please');
check('a tie between ne and en → ne', CustomerDirectory::lang($C) === 'ne');

/* chosen wins */
check('setLang chosen', CustomerDirectory::setLang($Dn, 'en'));
CustomerDirectory::noteLang($Dn, 'भोलि सुरत जाने बस कति बजे छ?');
CustomerDirectory::noteLang($Dn, 'bholi ko ticket kati ho malai chahiyo');
CustomerDirectory::noteLang($Dn, 'malai ekta seat chahiyo bholi');
check('a chosen language is never overwritten by chat', CustomerDirectory::lang($Dn) === 'en'
    && Database::scalar('SELECT source FROM contact_lang WHERE phone = :p', ['p' => $Dn]) === 'chosen');
check('setLang refuses a bad language or number', !CustomerDirectory::setLang($E, 'gu') && !CustomerDirectory::setLang('12345', 'ne'));

/* bulk + default */
$m = CustomerDirectory::langs([$A, $B, $Dn, '9800000000']);
check('langs() for a list', $m === [$A => 'ne', $B => 'hi', $Dn => 'en', '9800000000' => 'ne'], json_encode($m));
cacheSet(['mk_default_lang' => ['hi', 'string']]);
check('mk_default_lang changes the unknown default', CustomerDirectory::lang($A) === 'hi');
cacheSet(['mk_default_lang' => ['ne', 'string']]);

/* autopilot split */
$list = [['intl' => $A, 'ph' => substr($A, -10), 'name' => 'ZZTEST u8 A'], ['intl' => $B, 'ph' => substr($B, -10), 'name' => 'ZZTEST u8 B'],
         ['intl' => $Dn, 'ph' => substr($Dn, -10), 'name' => 'ZZTEST u8 D']];
$split = MkAutopilot::splitByLang($list);
check('autopilot splits a list by language', array_keys($split) === ['ne', 'hi', 'en'] && count($split['hi']) === 1 && $split['en'][0]['intl'] === $Dn,
    json_encode(array_map('count', $split)));
cacheSet(['mk_auto_langs' => ['ne', 'string']]);
$split = MkAutopilot::splitByLang($list);
check('a language not in mk_auto_langs folds into the default', array_keys($split) === ['ne'] && count($split['ne']) === 3);
cacheSet(['mk_auto_langs' => ['ne,hi,en', 'string']]);

$facts = ['fare_go' => 2200, 'fare_back' => 2500, 'fare_go_max' => 2200, 'fare_back_max' => 2500, 'offer_go' => 2200, 'offer_back' => 2500,
          'deps' => ['toNepal' => ['Surat 13:00 → Rupaidiha'], 'toIndia' => ['Rupaidiha 18:00 → Surat']], 'daily' => true,
          'phone' => '+91 91048 01507', 'offer' => '', 'festival' => []];
cacheSet(['mk_auto_ai_copy' => ['0', 'bool']]);
$ne = MkAutopilot::copy('lapsed', $facts, '', 'ne'); $hi = MkAutopilot::copy('lapsed', $facts, '', 'hi'); $en = MkAutopilot::copy('lapsed', $facts, '', 'en');
check('fixed lines exist in ne / hi / en', str_contains($ne['text'], 'धेरै दिन भयो') && str_contains($hi['text'], 'काफ़ी दिन') && str_contains($en['text'], 'We miss you'));
$why = '';
check('each fixed line passes the safety check', MkAutopilot::check($ne['text'], $facts, $why) !== '' && MkAutopilot::check($hi['text'], $facts, $why) !== ''
    && MkAutopilot::check($en['text'], $facts, $why) !== '', $why);
check('copy() with no language = the default language', MkAutopilot::copy('lapsed', $facts)['text'] === $ne['text']);

/* queue() writes the language; campaign is a DRAFT and is deleted again (nothing sent) */
$q = new ReflectionMethod(MkAutopilot::class, 'queue');
$cid = (int) $q->invoke(null, 'lapsed', '', $hi['text'], [['intl' => $B, 'ph' => substr($B, -10), 'name' => 'ZZTEST u8 B']], false, 0, 'hi');
$camp = Database::fetch('SELECT * FROM mk_campaigns WHERE id = :i', ['i' => $cid]);
check('queued campaign carries template_lang and the language in its name, as a draft',
    $camp !== null && ($camp['template_lang'] ?? '') === 'hi' && str_contains((string) $camp['name'], 'HI') && $camp['status'] === 'draft');
Database::run('DELETE FROM mk_recipients WHERE campaign_id = :i', ['i' => $cid]);
Database::run('DELETE FROM mk_campaigns WHERE id = :i', ['i' => $cid]);
Database::run("DELETE FROM audit_logs WHERE action = 'marketing.autopilot_queue' AND entity_id = :i", ['i' => (string) $cid]);

/* source guard: no sending path changed */
$src = (string) file_get_contents(INCLUDE_PATH . '/campaigns.php');
check('Campaigns::sendOne untouched by u8 (no mk_send_via branch)', !str_contains($src, 'mk_send_via'));

/* backfill (dry, read-only) runs */
$out = shell_exec('"' . PHP_BINARY . '" -c "' . php_ini_loaded_file() . '" "' . dirname(__DIR__) . '/tools/contact-lang-backfill.php" --dry --limit=50 2>&1');
$j = json_decode(trim((string) $out), true);
check('backfill --dry runs and reports', is_array($j) && $j['dry'] === true, (string) $out);

/* backfill for real on one test message, twice: counted once (bookmark), then everything put back */
$bm = Database::fetch("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'backfill_last_id'");
$wid = (int) Database::insert('wa_inbound', ['wamid' => 'u8test-' . bin2hex(random_bytes(4)), 'from_digits' => $E, 'msg_type' => 'text',
    'payload' => json_encode(['from' => $E, 'type' => 'text', 'text' => ['body' => 'मुझे कल सूरत जाना है, सीट मिलेगी?']], JSON_UNESCAPED_UNICODE),
    'status' => 'done', 'attempts' => 1]);
Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('contact_lang','backfill_last_id',:v,'test')
               ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => (string) ($wid - 1)]);
$cmd = '"' . PHP_BINARY . '" -c "' . php_ini_loaded_file() . '" "' . dirname(__DIR__) . '/tools/contact-lang-backfill.php" 2>&1';
Database::run("DELETE FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'");   // the earlier noteLang calls marked it
shell_exec($cmd); shell_exec($cmd);
check('backfill counts a message once (idempotent)', (int) Database::scalar('SELECT hi_n FROM contact_lang WHERE phone = :p', ['p' => $E], 0) === 1);

/* fix of 19 Nov: a line the live hook counted is never counted again by the backfill */
$wid2 = (int) Database::insert('wa_inbound', ['wamid' => 'u8test-' . bin2hex(random_bytes(4)), 'from_digits' => $E, 'msg_type' => 'text',
    'payload' => json_encode(['from' => $E, 'type' => 'text', 'text' => ['body' => 'mujhe kal ka ticket chahiye kitne ka hai']], JSON_UNESCAPED_UNICODE),
    'status' => 'done', 'attempts' => 1]);
CustomerDirectory::resetHookMark();
CustomerDirectory::noteLang($E, 'mujhe kal ka ticket chahiye kitne ka hai');          // the live hook counts it
$hs = (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'", [], 0);
check('the first live noteLang() records hook_start_id (newest wa_inbound id)', $hs >= $wid2, (string) $hs);
Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'", ['v' => (string) $wid2]);  // exact, other builders may write wa_inbound
CustomerDirectory::noteLang($E, 'I want two seats tomorrow please', 'backfill');
check('a backfill call does not move the hook mark', (int) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'", [], 0) === $wid2);
Database::run("UPDATE contact_lang SET en_n = 0 WHERE phone = :p", ['p' => $E]);
$o = json_decode(trim((string) shell_exec($cmd)), true);
check('backfill stops below hook_start_id: the hook\'s line is not counted twice',
    (int) Database::scalar('SELECT hi_n FROM contact_lang WHERE phone = :p', ['p' => $E], 0) === 2 && is_array($o) && (int) $o['hook_start_id'] === $wid2
    && (int) $o['to_id'] < $wid2, json_encode($o));
Database::run('DELETE FROM wa_inbound WHERE id IN (:i, :j)', ['i' => $wid, 'j' => $wid2]);
if ($bm === null) {
    Database::run("DELETE FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'backfill_last_id'");
} else {
    Database::run("UPDATE kv_store SET kvalue = :v WHERE kscope = 'contact_lang' AND kkey = 'backfill_last_id'", ['v' => (string) $bm['kvalue']]);
}

if ($hs0 === null) {
    Database::run("DELETE FROM kv_store WHERE kscope = 'contact_lang' AND kkey = 'hook_start_id'");
} else {
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('contact_lang','hook_start_id',:v,'test')
                   ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => (string) $hs0['kvalue']]);
}
$clean();
Settings::flush();
check('test rows removed', (int) Database::scalar("SELECT COUNT(*) FROM contact_lang WHERE phone LIKE '91900008000%' OR phone LIKE '97790000800%'", [], 0) === 0);
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
