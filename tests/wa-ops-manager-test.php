<?php
/**
 * =====================================================================
 *  wa-ops-manager-test.php — the WhatsApp assistant as operations
 *  manager: human handoff, step-up gate, attachments, consent (24 Sep 2026).
 *
 *    • HANDOFF    switch → tool; a request becomes SUP-…, redacted,
 *                 idempotent on retry, attached to a booking only when
 *                 the sender owns it, honest about whether the office
 *                 was alerted; status readable by its owner only
 *    • STEP-UP    who needs it, who never does; the gate in front of
 *                 money tools writes an audit row and hands out ONE link
 *    • CATALOGUE  every new tool appears only with its switch, and only
 *                 for the roles that may press it
 *    • MEDIA      attachment metadata is sanitised; with the assistant
 *                 off the old fixed replies stand; a transcript waits
 *                 for its "ho" and dies otherwise
 *    • CONSENT    START OFFERS / STOP are recorded when marketing is on
 *
 *  Self-contained; cleans up after itself.
 *      php tests/wa-ops-manager-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aihandoff.php';
require_once INCLUDE_PATH . '/aiverify.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/wamedia.php';
require_once INCLUDE_PATH . '/wabot.php';

const OM_CUST  = '9100007201';
const OM_OTHER = '9100007202';
const OM_AGENT = '9100007203';
const OM_BOSS  = '9100007204';
const OM_LIKE  = '910000720';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function section(string $t): void { echo "\n== $t ==\n"; }

echo "\n=== WhatsApp operations manager — handoff, step-up, catalogue, media, consent ===\n\n";

/* ---- stand alone: tables + columns from the migration ------------------ */
$sqlFile = dirname(__DIR__) . '/database/upgrade-2026-09-24-wa-ops-manager.sql';
$sql = (string) file_get_contents($sqlFile);
foreach (array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', $sql) ?? ''))) as $stmt) {
    if (stripos($stmt, 'CREATE TABLE IF NOT EXISTS') === 0 || stripos($stmt, 'INSERT IGNORE INTO `settings`') === 0) {
        try { Database::run($stmt); } catch (Throwable $e) {}
    }
}
foreach (['source' => "VARCHAR(20) NOT NULL DEFAULT 'web'", 'sender_role' => 'VARCHAR(20) NULL', 'language' => 'VARCHAR(8) NULL',
          'dedupe_key' => 'CHAR(64) NULL', 'evidence_path' => 'VARCHAR(255) NULL', 'office_notified_at' => 'DATETIME NULL'] as $col => $def) {
    try {
        if (Database::fetch("SHOW COLUMNS FROM support_tickets LIKE '" . $col . "'") === null) {
            Database::run("ALTER TABLE support_tickets ADD COLUMN `" . $col . "` " . $def);
        }
    } catch (Throwable $e) {}
}
if (!AiHandoff::available(true)) { echo "  SKIP  support_tickets could not be extended\n"; exit(0); }

/* ---- settings pinned + restored -------------------------------------- */
$PINNED = ['wa_ops_handoff_on', 'wa_ops_handoff_notify', 'wa_ops_stepup_on', 'wa_ops_stepup_minutes', 'wa_ops_stepup_actions',
           'wa_ops_docs_on', 'wa_ops_media_on', 'wa_ops_voice_on', 'wa_marketing_on', 'whatsapp_driver', 'wa_agent_on',
           'anthropic_api_key', 'gemini_api_key', 'company_whatsapp', 'wa_local_first', 'whatsapp_bot_enabled'];
$prior = [];
foreach ($PINNED as $k) { $prior[$k] = Database::fetch('SELECT svalue, stype, sgroup, is_public FROM settings WHERE skey = :k', ['k' => $k]); }
$restore = static function () use ($PINNED, $prior): void {
    foreach ($PINNED as $k) {
        $row = $prior[$k];
        if ($row === null) { try { Database::delete('settings', 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
        else { try { Database::update('settings', ['svalue' => (string) $row['svalue'], 'stype' => (string) $row['stype'],
                'sgroup' => (string) $row['sgroup'], 'is_public' => (int) $row['is_public']], 'skey = :k', ['k' => $k]); } catch (Throwable $e) {} }
    }
    try { Settings::flush(); } catch (Throwable $e) {}
};

$mkStaff = static function (string $username, string $name, string $role, string $phone): int {
    $id = (int) Database::scalar('SELECT id FROM admins WHERE username = :u', ['u' => $username], 0);
    if ($id === 0) {
        return (int) Database::insert('admins', ['username' => $username, 'password_hash' => password_hash('Om@123456', PASSWORD_BCRYPT),
            'full_name' => $name, 'role' => $role, 'phone' => $phone, 'is_active' => 1, 'must_change_pw' => 0]);
    }
    Database::update('admins', ['role' => $role, 'phone' => $phone, 'is_active' => 1, 'full_name' => $name, 'must_change_pw' => 0, 'locked_until' => null], 'id = :i', ['i' => $id]);
    return $id;
};

$cleanup = static function () use ($restore): void {
    try {
        foreach (Database::fetchAll("SELECT id FROM support_tickets WHERE phone LIKE '" . OM_LIKE . "%'") as $t) {
            Database::delete('support_messages', 'ticket_id = :t', ['t' => (int) $t['id']]);
        }
        Database::delete('support_tickets', "phone LIKE '" . OM_LIKE . "%'", []);
    } catch (Throwable $e) {}
    try { Database::delete('bookings', "contact_phone LIKE '" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('wa_identity_links', "phone LIKE '" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('ai_agent_calls', "phone LIKE '" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn','wa_voice','wa_booking') AND kkey LIKE '" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('message_logs', "to_number LIKE '%" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('wa_marketing_consents', "phone LIKE '91" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    try { Database::delete('rate_limits', "identifier LIKE '%" . OM_LIKE . "%'", []); } catch (Throwable $e) {}
    // The step-up consumer and the share endpoint throttle by CLIENT IP, and
    // the CLI has one: a fourth standalone run inside ten minutes tripped it.
    try { Database::delete('rate_limits', "bucket IN ('wa_stepup_consume','wa_stepup_link','doc_share_fetch')", []); } catch (Throwable $e) {}
    try { Database::delete('admins', "username IN ('om-agent','om-boss')", []); } catch (Throwable $e) {}
    $restore();
};
register_shutdown_function($cleanup);
$cleanup();
$agentId = $mkStaff('om-agent', 'OM Agent', 'agent', OM_AGENT);
$bossId  = $mkStaff('om-boss',  'OM Boss',  'superadmin', OM_BOSS);

Settings::set('wa_agent_on', false, 'bool', 'ai');
Settings::set('anthropic_api_key', '', 'string', 'ai');
Settings::set('gemini_api_key', '', 'string', 'ai');
Settings::set('wa_ops_handoff_on', false, 'bool', 'ai');
Settings::set('wa_ops_handoff_notify', true, 'bool', 'ai');
Settings::set('wa_ops_stepup_on', false, 'bool', 'ai');
Settings::set('wa_ops_docs_on', false, 'bool', 'ai');
Settings::set('wa_ops_media_on', false, 'bool', 'ai');
Settings::set('wa_ops_voice_on', false, 'bool', 'ai');
Settings::set('wa_marketing_on', false, 'bool', 'ai');
Settings::set('whatsapp_driver', 'click_to_chat', 'string', 'notify');
Settings::set('company_whatsapp', '919104801507', 'string', 'company');
Settings::flush();

$mk = static function (string $phone, int $turn = 1, string $text = 'mero ticket ko paisa katiyo tara ticket aayena'): array {
    $ctx = AiTools::whoIs($phone);
    $ctx['channel'] = 'whatsapp'; $ctx['turn'] = $turn; $ctx['messageText'] = $text; $ctx['raw_text'] = $text; $ctx['attachment'] = [];
    return $ctx;
};

/* two bookings: one on the customer's number, one on somebody else's */
$myPnr = 'SHG-T7-' . strtoupper(substr(md5((string) mt_rand()), 0, 5)) . '-OM';
$otPnr = 'SHG-T7-' . strtoupper(substr(md5((string) mt_rand()), 0, 5)) . '-OX';
$myBooking = (int) Database::insert('bookings', ['pnr' => $myPnr, 'contact_phone' => OM_CUST, 'status' => 'confirmed', 'sold_by_admin_id' => $agentId]);
$otBooking = (int) Database::insert('bookings', ['pnr' => $otPnr, 'contact_phone' => OM_OTHER, 'status' => 'confirmed']);
check('booking fixtures created', $myBooking > 0 && $otBooking > 0);

/* ------------------------------------------------------------------------ */
section('handoff — the switch');
$cust = $mk(OM_CUST);
check('OFF: handoff tools are not in the catalogue', !in_array('handoff_to_staff', array_column(AiTools::catalogue($cust), 'name'), true));
$r = AiTools::run('handoff_to_staff', ['category' => 'complaint', 'summary' => 'x'], $cust);
check('OFF: naming the tool is refused', $r['ok'] === false);
Settings::set('wa_ops_handoff_on', true, 'bool', 'ai'); Settings::flush();
$names = array_column(AiTools::catalogue($cust), 'name');
check('ON: handoff_to_staff + handoff_status offered to a customer', in_array('handoff_to_staff', $names, true) && in_array('handoff_status', $names, true));
check('ON: and to staff and the office', in_array('handoff_to_staff', array_column(AiTools::catalogue($mk(OM_AGENT)), 'name'), true)
    && in_array('handoff_status', array_column(AiTools::catalogue($mk(OM_BOSS)), 'name'), true));

section('handoff — opening a request');
$h = AiTools::run('handoff_to_staff', [
    'category' => 'payment_dispute',
    'summary'  => 'Paisa katiyo tara ticket aayena. OTP 482913 pani aayo. Card 4111 1111 1111 1111. Refund chahiyo.',
    'pnr'      => $myPnr,
], $cust);
check('a request is opened with a SUP reference', $h['ok'] && preg_match('/^SUP-\d{6}-[A-Z0-9]{5}$/', (string) ($h['data']['ref'] ?? '')) === 1, (string) ($h['data']['ref'] ?? ''));
$ref = (string) $h['data']['ref'];
$row = Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => $ref]);
check('the row carries source, role, language, priority', $row !== null && $row['source'] === 'whatsapp_ai' && $row['sender_role'] === 'customer'
    && $row['language'] === 'ne' && $row['priority'] === 'high' && $row['status'] === 'open', json_encode([$row['source'] ?? null, $row['sender_role'] ?? null, $row['language'] ?? null]));
check('the OTP and the card number never reached the queue', !str_contains((string) $row['message'], '482913') && !str_contains((string) $row['message'], '4111'));
check('redact() keeps a Nepali number with its country code (13 digits is not a card)', str_contains(AiHandoff::redact('call 9779812345678 or 919104801507, card 4111111111111111 cvv 123'), '9779812345678')
    && str_contains(AiHandoff::redact('call 9779812345678'), '9779812345678') && !str_contains(AiHandoff::redact('card 4111111111111111'), '4111111111111111')
    && !str_contains(AiHandoff::redact('amex 378282246310005'), '378282246310005'));
check('  but the complaint did', str_contains((string) $row['message'], 'Refund chahiyo'));
check('own booking attached', (int) $row['booking_id'] === $myBooking && str_contains((string) $row['subject'], $myPnr));
check('a support_messages row holds the first message', Database::exists("SELECT 1 FROM support_messages WHERE ticket_id = :t AND sender_type = 'customer'", ['t' => (int) $row['id']]));
check('audited as support.handoff', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'support.handoff' AND entity_id = :r", ['r' => $ref]));
check('with click-to-chat the office was NOT alerted, and the tool says so', ($h['data']['office_notified'] ?? true) === false
    && str_contains($h['say'], 'NOT been alerted') && $row['office_notified_at'] === null);
check('the tool never promises a time', str_contains($h['say'], 'Never promise a response time'));
check('the tool call itself is in ai_agent_calls', Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'handoff_to_staff' AND ok = 1", ['p' => OM_CUST]));

$h2 = AiTools::run('handoff_to_staff', [
    'category' => 'payment_dispute',
    'summary'  => 'Paisa katiyo tara ticket aayena. OTP 482913 pani aayo. Card 4111 1111 1111 1111. Refund chahiyo.',
    'pnr'      => $myPnr,
], $mk(OM_CUST, 2));
check('the same request again returns the SAME reference', $h2['ok'] && ($h2['data']['ref'] ?? '') === $ref && ($h2['data']['duplicate'] ?? false) === true);
check('  and only one ticket exists', (int) Database::scalar("SELECT COUNT(*) FROM support_tickets WHERE phone = :p", ['p' => OM_CUST], 0) === 1);

$h3 = AiTools::run('handoff_to_staff', ['category' => 'booking_help', 'summary' => 'seat change chahiyo', 'pnr' => $otPnr], $cust);
$row3 = Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => (string) ($h3['data']['ref'] ?? '')]);
check("somebody else's PNR is NOT attached and not named", $row3 !== null && $row3['booking_id'] === null && !str_contains((string) $row3['subject'], $otPnr));
$h4 = AiTools::run('handoff_to_staff', ['category' => 'nonsense', 'summary' => 'bus late', 'urgent' => true], $mk(OM_AGENT));
$row4 = Database::fetch('SELECT * FROM support_tickets WHERE ticket_ref = :r', ['r' => (string) ($h4['data']['ref'] ?? '')]);
check('an unknown category becomes other; urgent lifts priority; staff role recorded', $row4 !== null && $row4['category'] === 'other' && $row4['priority'] === 'urgent' && $row4['sender_role'] === 'staff');
$h5 = AiTools::run('handoff_to_staff', ['category' => 'complaint', 'summary' => 'x'], $cust);
check('an empty summary is refused', !$h5['ok']);

section('handoff — status');
$st = AiTools::run('handoff_status', ['ref' => $ref], $mk(OM_CUST, 3));
check('the owner reads the status', $st['ok'] && ($st['data']['status'] ?? '') === 'open');
$st2 = AiTools::run('handoff_status', ['ref' => $ref], $mk(OM_OTHER));
check('another number cannot', !$st2['ok'] && str_contains($st2['say'], 'No request'));
$st3 = AiTools::run('handoff_status', ['ref' => $ref], $mk(OM_BOSS));
check('the office can', $st3['ok']);
Database::insert('support_messages', ['ticket_id' => (int) $row['id'], 'sender_type' => 'admin', 'sender_name' => 'Desk', 'message' => 'Refund of Rs 2000 approved, 3 working days.']);
Database::update('support_tickets', ['status' => 'in_progress'], 'id = :i', ['i' => (int) $row['id']]);
$st4 = AiTools::run('handoff_status', ['ref' => $ref], $mk(OM_CUST, 4));
check("the desk's reply and the new status are read back", ($st4['data']['status'] ?? '') === 'in_progress' && str_contains((string) ($st4['data']['staffReply'] ?? ''), 'approved'));
check('a malformed reference is refused', !AiTools::run('handoff_status', ['ref' => 'SUP-1'], $cust)['ok']);

/* ------------------------------------------------------------------------ */
section('step-up — who and when');
$agent = $mk(OM_AGENT); $boss = $mk(OM_BOSS);
check('OFF: nobody needs it', !AiVerify::needs('office_confirm', $boss) && !AiVerify::needs('agent_day', $agent));
Settings::set('wa_ops_stepup_on', true, 'bool', 'ai'); Settings::set('wa_ops_stepup_minutes', 30, 'int', 'ai');
Settings::set('wa_ops_stepup_actions', 'office_confirm, cancel_ticket, agent_day ,office_day', 'string', 'ai'); Settings::flush();
check('ON: the office needs it for office_confirm and office_day', AiVerify::needs('office_confirm', $boss) && AiVerify::needs('office_day', $boss));
check('ON: an agent needs it for agent_day', AiVerify::needs('agent_day', $agent));
check('ON: nobody needs it for a read like find_ticket', !AiVerify::needs('find_ticket', $boss));
check('ON: a customer never needs it, even for cancel_ticket', !AiVerify::needs('cancel_ticket', $cust));
check('the actions list is parsed with spaces and all', AiVerify::actions() === ['office_confirm', 'cancel_ticket', 'agent_day', 'office_day']);

$g = AiTools::run('agent_day', [], $agent);
check('agent_day from an unverified agent is gated with a link', !$g['ok'] && ($g['data']['needs_verification'] ?? false) && str_contains((string) ($g['data']['link'] ?? ''), 'admin/wa-verify.php?t='));
check('  the gate wrote its audit row', Database::exists("SELECT 1 FROM ai_agent_calls WHERE phone = :p AND tool = 'agent_day' AND ok = 0 AND detail = 'step-up verification required'", ['p' => OM_AGENT]));
check('  and the challenge audit line', Database::exists("SELECT 1 FROM audit_logs WHERE action = 'wa.identity.challenge' AND entity_id = :a", ['a' => (string) $agentId]));
$f = AiTools::run('find_ticket', ['pnr' => $myPnr], $agent);
check('a read from the same unverified agent is NOT gated', !isset($f['data']['needs_verification']));
$vi = AiTools::run('verify_identity', [], $mk(OM_AGENT, 2));
check('verify_identity while a link is live says: already sent', !empty($vi['data']['already_sent']));
preg_match('~t=([a-f0-9]{64})~', (string) ($g['data']['link'] ?? ''), $tm);
$agentRow = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => $agentId]);
$ok = AiVerify::consume((string) ($tm[1] ?? ''), $agentRow);
check('the agent opens the link signed in as themselves → verified', $ok['ok'] === true);
$vi2 = AiTools::run('verify_identity', [], $mk(OM_AGENT, 3));
check('verify_identity now says: already verified until …', $vi2['ok'] && ($vi2['data']['verified'] ?? false) === true);
$g2 = AiTools::run('agent_day', [], $mk(OM_AGENT, 4));
check('agent_day now passes the gate', !isset($g2['data']['needs_verification']));
Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $agentId]);
$ctxGone = $mk(OM_AGENT, 5);
check('a deactivated staff number falls back to customer and needs nothing', $ctxGone['role'] === 'customer' && !AiVerify::needs('agent_day', $ctxGone));
Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $agentId]);
AiVerify::revoke(OM_AGENT);
check('revoke() forgets the verification', !AiVerify::isFresh($mk(OM_AGENT, 6)));
check('consume() refuses a malformed token', AiVerify::consume('abc', $agentRow)['ok'] === false);

/* ------------------------------------------------------------------------ */
section('catalogue — switches and roles');
Settings::set('wa_marketing_on', false, 'bool', 'ai'); Settings::flush();
check('marketing tools are absent while wa_marketing_on is off', !in_array('marketing_preview', array_column(AiTools::catalogue($boss), 'name'), true));
Settings::set('wa_marketing_on', true, 'bool', 'ai'); Settings::flush();
$bossNames = array_column(AiTools::catalogue($mk(OM_BOSS)), 'name');
check('marketing tools appear for the office when on', in_array('marketing_draft', $bossNames, true) && in_array('marketing_send', $bossNames, true));
check('  and never for a customer or an agent', !in_array('marketing_draft', array_column(AiTools::catalogue($cust), 'name'), true)
    && !in_array('marketing_draft', array_column(AiTools::catalogue($mk(OM_AGENT)), 'name'), true));
$md = AiTools::run('marketing_status', [], $mk(OM_AGENT, 7));
check('an agent naming a marketing tool is refused', !$md['ok']);
$md2 = AiTools::run('marketing_draft', ['title' => 'zz', 'template_name' => 'BAD NAME'], $mk(OM_BOSS, 2));
check('the office gets WaMarketing\'s own validation, wrapped in the tool contract', !$md2['ok'] && str_contains($md2['say'], 'template_name'));
Settings::set('wa_marketing_on', false, 'bool', 'ai'); Settings::flush();

/* ------------------------------------------------------------------------ */
section('media — metadata, fixed replies, the waiting transcript');
$bad = WaMedia::describe('image', ['image' => ['id' => 'abc-123_X;drop', 'mime_type' => 'image/jpeg<script>', 'sha256' => 'q+w/e=!', 'caption' => 'proof', 'filename' => 'my ../file.jpg']]);
check('describe() drops a malformed id, mime and hash whole', $bad['id'] === '' && $bad['mime'] === '' && $bad['sha256'] === '' && !str_contains($bad['filename'], '..') && $bad['kind'] === 'image');
$desc = WaMedia::describe('image', ['image' => ['id' => 'wamid.HBgLOTE5MTA0ODAxNTA3FQIAEhgg', 'mime_type' => 'image/jpeg', 'sha256' => base64_encode(random_bytes(32)), 'caption' => 'proof']]);
check('  and keeps a well-formed one', $desc['id'] !== '' && $desc['mime'] === 'image/jpeg' && $desc['sha256'] !== '');
check('media and voice are OFF by default', !WaMedia::enabled() && !WaMedia::voiceEnabled());
Settings::set('whatsapp_bot_enabled', true, 'bool', 'notify'); Settings::set('wa_local_first', true, 'bool', 'ai'); Settings::flush();
$img = WaBot::reply('+91' . OM_CUST, '', 'image', $desc);
check('a captionless photo with media OFF gets the fixed payment-proof line', str_contains($img['text'], 'फोटो प्राप्त भयो'));
Settings::set('wa_ops_media_on', true, 'bool', 'ai'); Settings::flush();
$img2 = WaBot::reply('+91' . OM_CUST, '', 'image', $desc);
check('with media ON but no assistant key, the fixed line still stands (nothing breaks)', str_contains($img2['text'], 'फोटो प्राप्त भयो'));
$voice = WaBot::reply('+91' . OM_CUST, '', 'audio', ['kind' => 'audio', 'id' => 'v1', 'mime' => 'audio/ogg']);
check('a voice note with transcription OFF gets the ask-to-type line', str_contains($voice['text'], 'आवाज सुन्न सक्दिनँ'));
// a transcript waiting for "ho": the yes turns it into the message, anything else drops it
$fakePnr = 'SHG-ZZ-99999-QQ';
Database::insertIgnore('kv_store', ['kscope' => 'wa_voice', 'kkey' => OM_CUST, 'kvalue' => json_encode(['text' => $fakePnr, 'at' => time()]), 'updated_by' => 'test']);
$yes = WaBot::reply('+91' . OM_CUST, 'हो', 'text');
check('"हो" replays the waiting transcript as the message', str_contains($yes['text'], $fakePnr) && str_contains($yes['text'], 'भेटिएन'));
check('  and the transcript is consumed', Database::fetch("SELECT 1 FROM kv_store WHERE kscope = 'wa_voice' AND kkey = :k", ['k' => OM_CUST]) === null);
Database::insertIgnore('kv_store', ['kscope' => 'wa_voice', 'kkey' => OM_CUST, 'kvalue' => json_encode(['text' => $fakePnr, 'at' => time()]), 'updated_by' => 'test']);
$no = WaBot::reply('+91' . OM_CUST, 'SHG-ZZ-11111-AA', 'text');
check('any other message drops the transcript and is handled as itself', str_contains($no['text'], 'SHG-ZZ-11111-AA')
    && Database::fetch("SELECT 1 FROM kv_store WHERE kscope = 'wa_voice' AND kkey = :k", ['k' => OM_CUST]) === null);
Database::insertIgnore('kv_store', ['kscope' => 'wa_voice', 'kkey' => OM_CUST, 'kvalue' => json_encode(['text' => $fakePnr, 'at' => time() - 3600]), 'updated_by' => 'test']);
$stale = WaBot::reply('+91' . OM_CUST, 'ho', 'text');
check('a stale transcript is not replayed', !str_contains($stale['text'], $fakePnr));

section('consent — START OFFERS / STOP');
$hasConsent = Database::fetch("SHOW TABLES LIKE 'wa_marketing_consents'") !== null;
if ($hasConsent) {
    $c0 = WaBot::reply('+91' . OM_CUST, 'START OFFERS', 'text');
    check('with marketing OFF the words are not a consent record', !str_contains($c0['text'], 'सहमति'));
    Settings::set('wa_marketing_on', true, 'bool', 'ai'); Settings::flush();
    $c1 = WaBot::reply('+91' . OM_CUST, 'START OFFERS', 'text');
    check('with marketing ON, START OFFERS is recorded and acknowledged', str_contains($c1['text'], 'सहमति')
        && Database::exists("SELECT 1 FROM wa_marketing_consents WHERE phone = :p AND state = 'opted_in'", ['p' => '91' . OM_CUST]));
    $c2 = WaBot::reply('+91' . OM_CUST, 'STOP', 'text');
    check('STOP flips it to opted_out', Database::exists("SELECT 1 FROM wa_marketing_consents WHERE phone = :p AND state = 'opted_out'", ['p' => '91' . OM_CUST]));
    Settings::set('wa_marketing_on', false, 'bool', 'ai'); Settings::flush();
} else {
    echo "  SKIP  wa_marketing_consents not migrated\n";
}

section('the panel');
check('support-inbox.php exists and is in the nav', is_file(dirname(__DIR__) . '/admin/support-inbox.php')
    && str_contains((string) file_get_contents(dirname(__DIR__) . '/admin/_guard.php'), "'support-inbox.php'"));
check('the webhook hands attachment metadata to the bot', str_contains((string) file_get_contents(dirname(__DIR__) . '/whatsapp/webhook.php'), 'WaMedia::describe'));
check('the webhook tells the marketing engine about deliveries', str_contains((string) file_get_contents(dirname(__DIR__) . '/whatsapp/webhook.php'), 'WaMarketing::deliveryStatus'));
check('the migration seeds every new switch OFF', (int) Database::scalar("SELECT COUNT(*) FROM settings WHERE skey IN ('wa_ops_docs_on','wa_ops_handoff_on','wa_ops_stepup_on','wa_ops_media_on','wa_ops_voice_on')", [], 0) === 5
    && str_contains($sql, "('wa_ops_docs_on',        '0'") && str_contains($sql, "('wa_ops_handoff_on',     '0'") && str_contains($sql, "('wa_ops_stepup_on',      '0'"));
check('this suite is registered in the battery', str_contains((string) file_get_contents(__DIR__ . '/run-all.php'), 'wa-ops-manager-test.php'));

echo "\n----------------------------------------\n";
echo "  \033[" . ($FAIL === 0 ? '32' : '31') . "m{$PASS} passed\033[0m, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
