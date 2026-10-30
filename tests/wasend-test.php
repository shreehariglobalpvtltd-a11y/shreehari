<?php
/**
 * =====================================================================
 *  wasend-test.php — the Cloud API fallback and the paced outbox
 *  (includes/wasend.php), with a FAKE HTTP hook: nothing leaves this
 *  machine.
 *
 *    - decide(): AiSensy first, then free inside the 24 h window, then the
 *      approved template, else wait / manual; the cap holds templates
 *    - transient() / backoff(); intl() for India / Nepal numbers
 *    - free text / picture / template payloads; message_logs rows with
 *      provider cloud_api for every attempt
 *    - dedupe: the same message is never sent twice; a delivered one is
 *      never retried
 *    - pacing: one at a time, the gap slept between sends, the daily cap
 *    - retries with backoff on a 5xx, a final failure on a 4xx, "unknown"
 *      on a 2xx without an id (only a person retries it), a closed window
 *      falls back to the template
 *    - webhook statuses (msg_ledger delivered / read) reach the outbox
 *    - Notify::whatsapp() with AiSensy missing -> Cloud API
 *    - contacts: save / update / search / delete
 *
 *    php tests/wasend-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/wasend.php';

set_exception_handler(static function (Throwable $e): void {
    echo "\n  \033[31mUNCAUGHT\033[0m " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\nFAILED: 1 (uncaught)\n";
    exit(1);
});
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 160) : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? ' — ' . mb_substr($extra, 0, 400) : '') . "\n"; }
}
function section(string $s): void { echo "-- $s --\n"; }

/* ---------------- the fakes ---------------- */
$T = (float) time();
$SLEPT = [];
$CALLS = [];
$NEXT = [];          // queued fake answers; empty = success
$N = 0;
WaSend::$clock   = static function () use (&$T): float { return $T; };
WaSend::$sleeper = static function (float $s) use (&$T, &$SLEPT): void { $SLEPT[] = $s; $T += $s; };
WaSend::$creds   = ['token' => 'FAKE-TOKEN-NEVER-PRINTED', 'phone_id' => '1256122924259996', 'base' => 'https://graph.example.test/v25.0'];
WaSend::$http    = static function (string $url, array $body) use (&$CALLS, &$NEXT, &$N): array {
    $CALLS[] = ['url' => $url, 'body' => $body];
    if ($NEXT !== []) {
        return array_shift($NEXT);
    }
    $N++;
    return ['http' => 200, 'body' => json_encode(['messages' => [['id' => 'wamid.WSTEST' . $N . '.' . mt_rand(1000, 9999)]]])];
};
$OPEN = [];          // numbers whose 24 h window is "open"
WaSend::$window  = static function (string $intl) use (&$OPEN): bool { return isset($OPEN[$intl]); };
WaSend::$aisensy = false;
// WaRoute's own Meta transport (the free route Notify tries first): recorded, so the test proves who sent.
$ROUTE = [];
WaRoute::$transport = static function (string $to, string $text, ?string $img) use (&$ROUTE): array {
    $ROUTE[] = $to;
    return ['success' => true, 'message_id' => 'wamid.ROUTE' . count($ROUTE), 'error' => ''];
};

/* ---------------- settings for the run (restored at the end) ---------------- */
$keep = [];
$set = static function (string $k, string $v, string $type = 'string') use (&$keep): void {
    if (!array_key_exists($k, $keep)) {
        $keep[$k] = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    }
    Settings::set($k, $v, $type, 'whatsapp', false);
    Settings::flush();
};
foreach (['whatsapp_driver' => 'aisensy', 'wa_policy' => 'meta_first', 'wa_cloud_fallback_on' => '1', 'wa_cloud_gap_sec' => '4',
          'wa_cloud_daily_cap' => '100', 'wa_cloud_tpl_message' => '', 'wa_cloud_tpl_lang' => 'en', 'wa_cloud_tpl_nvars' => '2',
          'whatsapp_template_name' => 'shg_ticket_confirmed_v3', 'whatsapp_template_lang' => 'en', 'wa_paid_daily_limit' => '',
          'wa_meta_templates_on' => '0'] as $k => $v) {
    $set($k, $v, in_array($k, ['wa_cloud_fallback_on', 'wa_meta_templates_on'], true) ? 'bool' : (in_array($k, ['wa_cloud_gap_sec', 'wa_cloud_daily_cap', 'wa_cloud_tpl_nvars'], true) ? 'int' : 'string'));
}

WaSend::ensure();
$kvKeep = Database::fetchAll("SELECT kkey, kvalue FROM kv_store WHERE kscope = 'wa_outbox'");
$P = '9170000005';   // test numbers: 91 70000005xx
$cleanup = static function () use ($P): void {
    Database::run("DELETE FROM wa_outbox WHERE to_number LIKE :p", ['p' => $P . '%']);
    Database::run("DELETE FROM wa_contacts WHERE phone LIKE :p", ['p' => $P . '%']);
    Database::run("DELETE FROM message_logs WHERE to_number LIKE :p", ['p' => $P . '%']);
    try { Database::run("DELETE FROM msg_ledger WHERE to_number LIKE :p OR provider_ref LIKE 'wamid.WSTEST%'", ['p' => $P . '%']); } catch (Throwable $e) {}
    try { Database::run("DELETE FROM wa_ticket_queue WHERE phone LIKE :p", ['p' => $P . '%']); } catch (Throwable $e) {}
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_outbox'");
};
$cleanup();
$reset = static function () use (&$CALLS, &$SLEPT, &$NEXT): void { $CALLS = []; $SLEPT = []; $NEXT = []; };
$row = static fn(int $id): ?array => Database::fetch('SELECT * FROM wa_outbox WHERE id = :i', ['i' => $id]);
$lastLog = static fn(string $to): ?array => Database::fetch("SELECT * FROM message_logs WHERE to_number = :t ORDER BY id DESC LIMIT 1", ['t' => $to]);

try {
    /* =========================================================== */
    section('decide() - the fallback decision');
    $d = WaSend::decide(['aisensy' => true, 'cloud' => true, 'window' => true]);
    check('AiSensy configured and not failed -> AiSensy first', $d['route'] === 'aisensy', $d['why']);
    $d = WaSend::decide(['aisensy' => true, 'aisensy_failed' => true, 'cloud' => true, 'window' => true]);
    check('AiSensy failed + window open -> Cloud API free', $d['route'] === 'cloud_free', $d['why']);
    $d = WaSend::decide(['aisensy' => false, 'cloud' => true, 'window' => true]);
    check('AiSensy key empty + window open -> Cloud API free', $d['route'] === 'cloud_free');
    $d = WaSend::decide(['aisensy' => false, 'cloud' => true, 'window' => false, 'template' => 'shg_ticket_confirmed_v3']);
    check('outside the window + template -> Cloud API template', $d['route'] === 'cloud_template', $d['why']);
    $d = WaSend::decide(['aisensy' => false, 'cloud' => true, 'window' => false, 'template' => '']);
    check('outside the window + no template -> wait (wa.me)', $d['route'] === 'wait', $d['why']);
    $d = WaSend::decide(['aisensy' => false, 'cloud' => true, 'window' => false, 'template' => 'x', 'paid_refusal' => 'limit reached']);
    check('daily paid cap -> the template is held (wait)', $d['route'] === 'wait' && str_contains($d['why'], 'limit'), $d['why']);
    $d = WaSend::decide(['aisensy' => false, 'cloud' => false, 'window' => true]);
    check('Cloud API not configured -> manual', $d['route'] === 'manual');
    $d = WaSend::decide(['aisensy' => false, 'fallback_on' => false, 'cloud' => true, 'window' => true]);
    check('fallback switched off -> manual', $d['route'] === 'manual');

    section('transient / backoff / numbers');
    check('HTTP 0 / 500 / 429 are transient', WaSend::transient(0, 0) && WaSend::transient(503, 0) && WaSend::transient(429, 0));
    check('Meta rate limits 130429 / 131056 are transient', WaSend::transient(400, 130429) && WaSend::transient(400, 131056));
    check('a bad number (131026) / bad template (132001) are final', !WaSend::transient(400, 131026) && !WaSend::transient(400, 132001));
    check('backoff 1, 5, 15, 60 min', WaSend::backoff(1) === 60 && WaSend::backoff(2) === 300 && WaSend::backoff(3) === 900 && WaSend::backoff(9) === 3600);
    check('India 10 digits -> 91…', WaSend::intl('98765 43210', 'IN') === '919876543210');
    check('Nepal 10 digits -> 977…', WaSend::intl('9812345678', 'NP') === '9779812345678');
    check('+977 kept whatever the hint', WaSend::intl('+977 9812345678', 'IN') === '9779812345678');
    check('junk -> empty', WaSend::intl('12345', 'IN') === '');
    check('configured: AiSensy no / Cloud yes (fakes)', !WaSend::aisensyConfigured() && WaSend::cloudConfigured());

    /* =========================================================== */
    section('inside the 24 h window: free text, logged as cloud_api');
    $reset();
    $to1 = $P . '01';
    $OPEN[$to1] = true;
    $r = WaSend::fallback($to1, "Namaste\nyour bus is at 9 PM", null, 'staff_note', ['label' => 'Note']);
    check('sent', $r['state'] === 'sent' && $r['message_id'] !== '', json_encode($r));
    check('one HTTP call, type text, to the bot number id', count($CALLS) === 1 && $CALLS[0]['body']['type'] === 'text'
        && str_contains($CALLS[0]['url'], '/1256122924259996/messages') && $CALLS[0]['body']['to'] === $to1, json_encode($CALLS[0]['body'] ?? []));
    $lg = $lastLog($to1);
    check('message_logs row: provider cloud_api, sent, wamid', $lg !== null && $lg['provider'] === 'cloud_api' && $lg['status'] === 'sent' && $lg['provider_ref'] === $r['message_id']);
    check('the token is in no log row', !str_contains((string) json_encode($lg), 'FAKE-TOKEN'));
    $again = WaSend::fallback($to1, "Namaste\nyour bus is at 9 PM", null, 'staff_note', ['label' => 'Note']);
    check('the same message again: not sent twice', $again['state'] === 'sent' && count($CALLS) === 1 && str_contains($again['how'], 'not sent twice'), json_encode($again));

    section('a picture inside the window');
    $reset();
    $T += 10;
    $r = WaSend::fallback($to1, 'Your ticket', 'https://www.example.test/ticket.php?pnr=SHG-2026-00001&img=1', 'ticket_resend', []);
    check('image payload with the caption', $r['state'] === 'sent' && ($CALLS[0]['body']['type'] ?? '') === 'image'
        && ($CALLS[0]['body']['image']['caption'] ?? '') === 'Your ticket', json_encode($CALLS[0]['body'] ?? []));

    /* =========================================================== */
    section('outside the window');
    $reset();
    $T += 10;
    $to2 = $P . '02';
    $r = WaSend::fallback($to2, 'Office note: meeting at 5', null, 'staff_note', []);
    check('no template -> waiting, nothing sent', $r['state'] === 'waiting' && $CALLS === [], json_encode($r));
    check('the waiting row explains why', str_contains((string) $row($r['id'])['error'], 'no approved template'));
    $vars = ['1' => 'SHG-2026-00009', '2' => 'Ahmedabad → Kathmandu', '3' => 'Fri, 31 Oct 2026', '4' => 'Narol · 9:00 PM',
             '5' => 'L1, L2 · 2 passengers', '6' => '₹4,000', '7' => 'https://www.example.test/ticket.php?pnr=SHG-2026-00009&img=1'];
    $T += 10;
    $r = WaSend::fallback($to2, 'Your ticket SHG-2026-00009', $vars['7'], 'ticket_update', ['tpl_vars' => $vars]);
    $b = $CALLS[0]['body'] ?? [];
    check('ticket outside the window -> the approved ticket template', $r['state'] === 'sent' && ($b['type'] ?? '') === 'template'
        && ($b['template']['name'] ?? '') === 'shg_ticket_confirmed_v3', json_encode($b));
    $comp = $b['template']['components'] ?? [];
    check('template: image header = the ticket PNG, 6 body variables in order',
        ($comp[0]['type'] ?? '') === 'header' && ($comp[0]['parameters'][0]['image']['link'] ?? '') === $vars['7']
        && count($comp[1]['parameters'] ?? []) === 6 && ($comp[1]['parameters'][0]['text'] ?? '') === 'SHG-2026-00009'
        && ($comp[1]['parameters'][5]['text'] ?? '') === '₹4,000', json_encode($comp));
    check('template logged as "Template … (Meta)" (counted as paid)', (string) $lastLog($to2)['body'] === 'Template shg_ticket_confirmed_v3 (Meta)');

    section('the generic template wakes a waiting message');
    $reset();
    $set('wa_cloud_tpl_message', 'shg_office_alert');
    $T += 10;
    [$woken] = WaSend::wake();
    check('wake(): the waiting note is queued again', $woken >= 1);
    $d = WaSend::drain(5, 50);
    $b = $CALLS[0]['body'] ?? [];
    check('sent on shg_office_alert with {{1}} label {{2}} one-line text', $d['sent'] === 1 && ($b['template']['name'] ?? '') === 'shg_office_alert'
        && ($b['template']['components'][0]['parameters'][1]['text'] ?? '') === 'Office note: meeting at 5', json_encode($b));
    $set('wa_cloud_tpl_message', '');

    section('daily paid cap holds the template');
    $reset();
    $set('wa_paid_daily_limit', '0');
    $T += 700;
    $r = WaSend::fallback($to2, 'Another ticket', $vars['7'], 'ticket_update', ['tpl_vars' => ['1' => 'SHG-2026-00010'] + $vars]);
    check('wa_paid_daily_limit 0 -> waiting, nothing sent', $r['state'] === 'waiting' && $CALLS === [], json_encode($r));
    $set('wa_paid_daily_limit', '');
    WaSend::cancel($r['id']);   // so the pacing count below sees only its own three

    /* =========================================================== */
    section('pacing: one at a time, the gap between, the daily cap');
    $reset();
    $T += 100;
    $ids = [];
    foreach (['03', '04', '05'] as $i => $s) {
        $OPEN[$P . $s] = true;
        $q = WaSend::queue(['to' => $P . $s, 'body' => 'Bulk ' . $s, 'purpose' => 'campaign_note', 'source' => 'test', 'dedupe' => 'bulk|' . $s]);
        $ids[] = $q['id'];
    }
    check('three queued, none sent yet', count(array_filter($ids)) === 3 && $CALLS === []);
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_outbox', 'last_at', :v, 't')
                   ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => sprintf('%.3f', $T)]);   // something just went
    $d = WaSend::drain(10, 50);
    check('drain sent all three', $d['sent'] === 3 && count($CALLS) === 3, json_encode($d));
    check('it slept the gap before each send (no burst)', count($SLEPT) === 3 && min($SLEPT) >= 3.99, json_encode($SLEPT));
    $times = array_map(static fn(int $id): string => (string) $row($id)['sent_at'], $ids);
    check('sent_at strictly apart', $times[0] < $times[1] && $times[1] < $times[2], implode(' / ', $times));

    $reset();
    $T += 100;
    $set('wa_cloud_daily_cap', (string) (WaSend::sentToday() + 1));
    $qa = WaSend::queue(['to' => $P . '03', 'body' => 'Cap A', 'purpose' => 'campaign_note', 'dedupe' => 'cap|a']);
    $qb = WaSend::queue(['to' => $P . '04', 'body' => 'Cap B', 'purpose' => 'campaign_note', 'dedupe' => 'cap|b']);
    $d = WaSend::drain(10, 50);
    check('daily cap: one goes, the other stays queued', $d['sent'] === 1 && count($CALLS) === 1 && $row($qb['id'])['state'] === 'queued', json_encode($d));
    $set('wa_cloud_daily_cap', '0');
    check('cap 0 = paused: mayGoNow() is false', !WaSend::mayGoNow('ticket'));
    $set('wa_cloud_daily_cap', '100');
    WaSend::cancel($qb['id']);

    section('CLI pacing inside fallback()');
    $reset();
    $T += 1;   // 1 s after the last send: 3 s of the 4 s gap remain
    $r = WaSend::fallback($P . '05', 'Paced one', null, 'campaign_note', []);
    check('a CLI loop sleeps the rest of the gap, then sends', $r['state'] === 'sent' && count($SLEPT) === 1 && abs($SLEPT[0] - 3.0) < 0.01, json_encode($SLEPT));

    /* =========================================================== */
    section('retries with backoff, final failures, unknown');
    $reset();
    $T += 100;
    $to6 = $P . '06';
    $OPEN[$to6] = true;
    $NEXT = [['http' => 503, 'body' => '{"error":{"message":"Service temporarily unavailable","code":131016}}']];
    $r = WaSend::fallback($to6, 'Retry me', null, 'campaign_note', []);
    $x = $row($r['id']);
    check('5xx -> queued again, attempt 1, next try in 60 s', $r['state'] === 'queued' && (int) $x['attempts'] === 1
        && strtotime((string) $x['next_at']) - (int) $T === 60, json_encode($x));
    check('the failed attempt is a cloud_api failed log row', ($lastLog($to6)['status'] ?? '') === 'failed' && ($lastLog($to6)['provider'] ?? '') === 'cloud_api');
    $d = WaSend::drain(5, 50);
    check('not before next_at', $d['sent'] === 0 && count($CALLS) === 1);
    $T += 61;
    $d = WaSend::drain(5, 50);
    check('after the backoff it goes', $d['sent'] === 1 && $row($r['id'])['state'] === 'sent', json_encode($d));

    $reset();
    $T += 10;
    $to7 = $P . '07';
    $OPEN[$to7] = true;
    $NEXT = [['http' => 400, 'body' => '{"error":{"message":"Message undeliverable","code":131026}}']];
    $r = WaSend::fallback($to7, 'Bad number', null, 'campaign_note', []);
    check('4xx (131026) -> failed at once, no retry', $r['state'] === 'failed' && str_contains($r['error'], '131026'), json_encode($r));
    $T += 4000;
    WaSend::drain(5, 50);
    check('drain does not touch a failed row', count($CALLS) === 1);

    $reset();
    $T += 10;
    $to8 = $P . '08';
    $OPEN[$to8] = true;
    $NEXT = [['http' => 200, 'body' => '{"messaging_product":"whatsapp"}']];
    $r = WaSend::fallback($to8, 'Ambiguous', null, 'campaign_note', []);
    check('2xx without an id -> unknown', $r['state'] === 'unknown', json_encode($r));
    $T += 4000;
    WaSend::drain(5, 50);
    check('unknown is never resent by the queue', count($CALLS) === 1 && $row($r['id'])['state'] === 'unknown');
    check('a person can retry it', WaSend::retry($r['id']) && $row($r['id'])['state'] === 'queued');
    WaSend::drain(5, 50);
    check('…and then it goes', $row($r['id'])['state'] === 'sent' && count($CALLS) === 2);

    section('a window that closed: the template next');
    $reset();
    $T += 10;
    $to9 = $P . '09';
    $OPEN[$to9] = true;
    $NEXT = [['http' => 400, 'body' => '{"error":{"message":"Re-engagement message","code":131047}}']];
    $r = WaSend::fallback($to9, 'Ticket after hours', $vars['7'], 'ticket_update', ['tpl_vars' => ['1' => 'SHG-2026-00011'] + $vars]);
    check('131047 -> queued again with no_free', $r['state'] === 'queued' && (int) $row($r['id'])['no_free'] === 1, json_encode($r));
    $T += 6;
    WaSend::drain(5, 50);
    check('…sent on the ticket template', $row($r['id'])['state'] === 'sent' && ($CALLS[1]['body']['type'] ?? '') === 'template');

    /* =========================================================== */
    section('webhook statuses reach the outbox; delivered is never resent');
    $reset();
    $T += 10;
    $to10 = $P . '10';
    $OPEN[$to10] = true;
    $r = WaSend::fallback($to10, 'Status please', null, 'campaign_note', []);
    $w = $r['message_id'];
    Database::insertIgnore('msg_ledger', ['provider' => 'cloud_api', 'channel' => 'whatsapp', 'to_number' => $to10, 'country' => 'IN',
        'label' => 'text', 'category' => 'service', 'status' => 'delivered', 'provider_ref' => $w, 'created_at' => date('Y-m-d H:i:s')]);
    Database::run("UPDATE msg_ledger SET status = 'delivered' WHERE provider_ref = :w", ['w' => $w]);
    WaSend::refresh();
    check('ledger delivered -> outbox delivered', $row($r['id'])['state'] === 'delivered');
    Database::run("UPDATE msg_ledger SET status = 'read' WHERE provider_ref = :w", ['w' => $w]);
    WaSend::refresh();
    check('ledger read -> outbox read', $row($r['id'])['state'] === 'read');
    check('Retry refuses a read / delivered message', !WaSend::retry($r['id']));
    Database::run("UPDATE wa_outbox SET state = 'failed' WHERE id = :i", ['i' => $r['id']]);   // even if a screen said failed…
    check('…and refuses it while the ledger says delivered / read', !WaSend::retry($r['id']));

    section('the webhook failure path (metaFailed)');
    $reset();
    $T += 10;
    $to11 = $P . '11';
    $OPEN[$to11] = true;
    $r = WaSend::fallback($to11, 'Ticket free', $vars['7'], 'ticket_update', ['tpl_vars' => ['1' => 'SHG-2026-00012'] + $vars]);
    unset($OPEN[$to11]);
    $st = WaSend::metaFailed($r['message_id'], 131047);
    check('async 131047 on our free send -> the template goes', $st === 'sent' && ($CALLS[1]['body']['type'] ?? '') === 'template', (string) $st);
    check('a wamid that is not ours is left alone', WaSend::metaFailed('wamid.NOT-OURS', 131047) === null);

    /* =========================================================== */
    section('Notify::whatsapp() with AiSensy missing');
    // Other test files share this database and flip whatsapp_driver: set it again right here.
    $set('whatsapp_driver', 'aisensy');
    $set('wa_cloud_fallback_on', '1', 'bool');
    $reset();
    $T += 10;
    $to12 = $P . '12';
    $OPEN[$to12] = true;
    $res = Notify::whatsapp('+' . $to12, 'Hello from the office', null, 'IN', [], null, ['purpose' => 'office_note', 'label' => 'Office']);
    $j = Notify::whatsappJournal();
    $last = end($j);
    check('driver aisensy, no key -> Cloud API sent it', $res === true && ($last['driver'] ?? '') === 'cloud_api' && ($last['reason'] ?? '') === 'cloud_fallback', json_encode($last));
    check('exactly one HTTP call on the Cloud API, none on the old route', count($CALLS) === 1 && $ROUTE === []);
    $logs = Database::fetchAll("SELECT status, provider FROM message_logs WHERE to_number = :t", ['t' => $to12]);
    check('one log row only (no extra "failed" click-to-chat row)', count($logs) === 1 && $logs[0]['provider'] === 'cloud_api' && $logs[0]['status'] === 'sent', json_encode($logs));

    $reset();
    $T += 10;
    $to13 = $P . '13';
    $res = Notify::whatsapp('+' . $to13, 'Outside the window', null, 'IN', [], null, ['purpose' => 'office_note']);
    $j = Notify::whatsappJournal();
    $last = end($j);
    check('no window, no template -> wa.me link, held in the outbox', is_string($res) && str_starts_with($res, 'https://wa.me/' . $to13)
        && ($last['reason'] ?? '') === 'cloud_queued' && $CALLS === [], json_encode($last));
    check('the outbox row waits', (string) Database::scalar("SELECT state FROM wa_outbox WHERE to_number = :t", ['t' => $to13], '') === 'waiting');

    $reset();
    $T += 10;
    $res = Notify::whatsapp('+' . $to13, 'Your ticket', $vars['7'], 'IN', ['1' => 'SHG-2026-00013'] + $vars, null, ['purpose' => 'ticket_update']);
    check('a ticket change outside the window -> the ticket template', $res === true && ($CALLS[0]['body']['template']['name'] ?? '') === 'shg_ticket_confirmed_v3');

    $reset();
    $set('wa_cloud_fallback_on', '0', 'bool');
    $res = Notify::whatsapp('+' . $P . '14', 'Fallback off', null, 'IN', [], null, ['purpose' => 'office_note']);
    check('fallback off -> the old click-to-chat behaviour, no HTTP', is_string($res) && $CALLS === []);
    $set('wa_cloud_fallback_on', '1', 'bool');

    $reset();
    $set('whatsapp_driver', 'click_to_chat');
    $res = Notify::whatsapp('+' . $P . '15', 'Click to chat driver', null, 'IN', [], null, ['purpose' => 'office_note']);
    check('driver click_to_chat stays manual (no API on purpose)', is_string($res) && $CALLS === []);
    $set('whatsapp_driver', 'aisensy');

    /* =========================================================== */
    section('a booking ticket by PNR (outbox "Send ticket")');
    $b = Database::fetch("SELECT id, pnr FROM bookings WHERE status IN ('confirmed','completed') ORDER BY id DESC LIMIT 1");
    if ($b === null) {
        check('a confirmed booking exists in the test DB (skipped)', true);
    } else {
        $reset();
        $T += 700;
        $to16 = $P . '16';
        $OPEN[$to16] = true;
        $r = WaSend::ticket((string) $b['pnr'], ['to' => $to16, 'admin_id' => 1, 'purpose' => 'ticket_resend']);
        check('ticket picture inside the window', $r['state'] === 'sent' && ($CALLS[0]['body']['type'] ?? '') === 'image'
            && str_contains((string) ($CALLS[0]['body']['image']['caption'] ?? ''), (string) $b['pnr']), json_encode($r));
        $r2 = WaSend::ticket((string) $b['pnr'], ['to' => $to16, 'admin_id' => 1, 'purpose' => 'ticket_resend']);
        check('a double click does not send it twice', count($CALLS) === 1 && str_contains($r2['how'], 'not sent twice'), json_encode($r2));

        $reset();
        $to17 = $P . '17';
        $OPEN[$to17] = true;
        Database::run("INSERT INTO wa_ticket_queue (booking_id, pnr, phone, role, status, via, wamid, attempts, sent_at)
                       VALUES (:b, :p, :ph, 'customer', 'sent', 'meta free', 'wamid.OTHER-SENDER', 1, NOW())",
            ['b' => (int) $b['id'], 'p' => (string) $b['pnr'], 'ph' => $to17]);
        $r = WaSend::ticket((string) $b['pnr'], ['to' => $to17, 'purpose' => 'ticket', 'source' => 'hi_queue']);
        check('a FIRST ticket another sender already delivered is not sent again', $r['state'] === 'sent' && $CALLS === [] && str_contains($r['how'], 'not sent twice'), json_encode($r));
    }
    check('an unknown PNR is refused', WaSend::ticket('SHG-1999-99999', ['to' => $P . '18'])['state'] === 'failed');

    /* =========================================================== */
    section('contacts');
    $c = WaSend::saveContact('Ram Bahadur', '98 1234 0000', 'NP', 'Kathmandu agent', 1);
    check('a Nepal 10-digit number needs its hint: 977…', $c['ok'] && (string) Database::scalar('SELECT phone FROM wa_contacts WHERE id = :i', ['i' => $c['id']], '') === '9779812340000');
    Database::run('DELETE FROM wa_contacts WHERE id = :i', ['i' => $c['id']]);
    $c = WaSend::saveContact('Sita Office', $P . '20', 'IN', '', 1);
    $c2 = WaSend::saveContact('Sita (renamed)', '+' . $P . '20', 'IN', 'desk', 1);
    check('saving the same number updates the name', $c['ok'] && $c2['ok'] && $c['id'] === $c2['id']
        && (string) Database::scalar('SELECT name FROM wa_contacts WHERE id = :i', ['i' => $c['id']], '') === 'Sita (renamed)');
    check('search by name', count(WaSend::contacts('renamed')) === 1);
    check('search by digits', count(WaSend::contacts(substr($P, 2) . '20')) === 1);
    check('a bad number is refused', !WaSend::saveContact('X', '123', 'IN')['ok']);
    check('a name is required', !WaSend::saveContact('', $P . '21', 'IN')['ok']);
    check('delete', WaSend::deleteContact($c['id']) && WaSend::contacts('renamed') === []);

    /* =========================================================== */
    section('one-time codes (no purpose given): at once in the window, else skipped, never kept');
    $set('wa_cloud_tpl_message', 'shg_office_alert');
    check('admin 2FA text is a secret', WaSend::secretText("S Hari एडमिन लगइन कोड: 482913\n5 मिनेट सम्म मान्य।"));
    check('join code / driver PIN / password are secrets', WaSend::secretText('your code: *582014*') && WaSend::secretText('🔑 PIN: 4821')
        && WaSend::secretText('🔑 Password: Xy7#pq9z'));
    check('an agent code / PIN-less text is not a secret', !WaSend::secretText('Agent code: AG-RAM27') && !WaSend::secretText('PIN बिना नै खोल्छ'));
    check('maskSecrets hides the digits', WaSend::maskSecrets('लगइन कोड: 482913') === 'लगइन कोड: ••••' && !str_contains(WaSend::maskSecrets('🔑 PIN: 4821'), '4821'));

    $reset();
    $T += 700;
    $to20 = $P . '30';
    $r = WaSend::fallback($to20, 'S Hari एडमिन लगइन कोड: 482913', null, 'message', []);
    check('no window -> skipped (SMS path), nothing sent, no row kept', $r['state'] === 'skipped' && $CALLS === []
        && (int) Database::scalar('SELECT COUNT(*) FROM wa_outbox WHERE to_number = :t', ['t' => $to20], 0) === 0, json_encode($r));

    $reset();
    $T += 1;   // inside the gap of a previous send: a code is never paced
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_outbox', 'last_at', :v, 't')
                   ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['v' => sprintf('%.3f', $T)]);
    $OPEN[$to20] = true;
    $r = WaSend::fallback($to20, 'S Hari एडमिन लगइन कोड: 771204', null, 'message', []);
    $x = $row($r['id']);
    check('window open -> sent at once, filed as otp', $r['state'] === 'sent' && count($CALLS) === 1 && ($x['purpose'] ?? '') === 'otp', json_encode($r));
    check('…its row keeps no code', $x !== null && !str_contains((string) $x['body'], '771204'));
    check('…its log row keeps no code', !str_contains((string) ($lastLog($to20)['body'] ?? ''), '771204'));

    $reset();
    $T += 700;
    $NEXT = [['http' => 503, 'body' => '{"error":{"message":"busy","code":131016}}']];
    $r = WaSend::fallback($to20, 'S Hari एडमिन लगइन कोड: 330918', null, 'message', []);
    $x = $row($r['id']);
    check('a transient refusal -> closed now (failed), not retried later', $r['state'] === 'failed' && ($x['state'] ?? '') === 'failed', json_encode($r));
    check('…and scrubbed', !str_contains((string) ($x['body'] ?? ''), '330918') && ($x['tpl_vars'] ?? null) === null);
    check('…a person cannot Retry it', !WaSend::retry($r['id']));
    check('the outbox page treats it as hidden', WaSend::isSecret((string) $x['purpose'], (string) $x['body']));

    $reset();
    $T += 700;
    $r = WaSend::fallback($to20, 'Driver app' . "\n" . '🔑 PIN: 5512', null, 'message', ['skip_free' => true]);
    check('a PIN when the free route was already tried -> skipped', $r['state'] === 'skipped' && $CALLS === []);
    $leftover = (int) Database::scalar("SELECT COUNT(*) FROM wa_outbox WHERE to_number = :t AND (body LIKE '%330918%' OR body LIKE '%771204%' OR body LIKE '%5512%')", ['t' => $to20], 0);
    check('no outbox row anywhere holds a code', $leftover === 0);
    unset($OPEN[$to20]);

    section('the generic template carries office alerts only');
    $reset();
    $T += 700;
    $to21 = $P . '31';
    $r = WaSend::fallback($to21, 'Thanks for writing, we will call you', null, 'bot_reply', []);
    check('bot_reply outside the window -> waits (not on the generic template)', $r['state'] === 'waiting' && $CALLS === [], json_encode($r));
    $T += 10;
    $r = WaSend::fallback($to21, 'Hello from the office desk', null, 'manual_message', []);
    check('manual_message outside the window -> waits', $r['state'] === 'waiting' && $CALLS === [], json_encode($r));
    $T += 10;
    $r = WaSend::fallback($to21, 'Payment received for SHG-2026-00031', null, 'payment_received', []);
    check('payment_received -> the generic template', $r['state'] === 'sent' && ($CALLS[0]['body']['template']['name'] ?? '') === 'shg_office_alert', json_encode($r));
    $set('wa_cloud_tpl_nvars', '1', 'int');
    check('nvars is always 2 (no bare "{{1}}" template)', WaSend::genericTemplate()['nvars'] === 2);
    $set('wa_cloud_tpl_nvars', '2', 'int');
    $set('wa_cloud_tpl_message', '');

    section('one open ticket row per booking + number (no burst on "hi")');
    $bk = Database::fetch("SELECT id, pnr FROM bookings ORDER BY id DESC LIMIT 1");
    if ($bk === null) {
        check('a booking exists in the test DB (skipped)', true);
    } else {
        $reset();
        $set('whatsapp_template_name', '');
        $to22 = $P . '32';
        $ids = [];
        foreach ([0, 700, 1400] as $dt) {   // three whatsapp-retry runs, 10+ minutes apart
            $T += $dt;
            $ids[] = WaSend::fallback($to22, 'Your ticket ' . $bk['pnr'] . ' v' . $dt, null, 'ticket_resend', ['bookingId' => (int) $bk['id']])['id'];
        }
        $n = (int) Database::scalar("SELECT COUNT(*) FROM wa_outbox WHERE to_number = :t AND state = 'waiting'", ['t' => $to22], 0);
        check('three resends while waiting -> ONE waiting row', $n === 1 && count(array_unique($ids)) === 1, json_encode($ids));
        check('…carrying the newest text', str_contains((string) $row($ids[0])['body'], 'v1400'));
        // a legacy duplicate (from before the fix) is cancelled by wake(); only the newest wakes
        Database::insertIgnore('wa_outbox', ['to_number' => $to22, 'body' => 'old copy', 'purpose' => 'ticket_resend', 'booking_id' => (int) $bk['id'],
            'state' => 'waiting', 'next_at' => date('Y-m-d H:i:s', (int) $T), 'created_at' => date('Y-m-d H:i:s', (int) $T), 'updated_at' => date('Y-m-d H:i:s', (int) $T)]);
        $OPEN[$to22] = true;
        WaSend::wake();
        $states = Database::fetchAll("SELECT id, state FROM wa_outbox WHERE to_number = :t ORDER BY id", ['t' => $to22]);
        $queued = array_values(array_filter($states, static fn($s): bool => $s['state'] === 'queued'));
        check('wake(): one copy queued, the older cancelled', count($queued) === 1 && count($states) === 2, json_encode($states));
        $set('whatsapp_template_name', 'shg_ticket_confirmed_v3');
        unset($OPEN[$to22]);
    }

    section('Notify: a waiting / queued outbox send still leaves the office a "failed" row');
    $set('whatsapp_driver', 'aisensy');
    $reset();
    $T += 700;
    $to23 = $P . '33';
    $res = Notify::whatsapp('+' . $to23, 'Your ticket is ready', null, 'IN', [], null, ['purpose' => 'office_note']);
    $lg = $lastLog($to23);
    check('waiting -> message_logs failed row naming the outbox', is_string($res) && ($lg['status'] ?? '') === 'failed'
        && str_contains((string) ($lg['error'] ?? ''), 'Cloud API outbox #') && str_contains((string) ($lg['error'] ?? ''), 'waiting'), json_encode($lg));
    $res = Notify::whatsapp('+' . $to23, 'S Hari को कोड: 918273 (5 मिनेट सम्म मान्य)।', null, 'IN');
    $lg = $lastLog($to23);
    check('an OTP outside the window -> no outbox row, the click-to-chat row has no code', is_string($res)
        && (int) Database::scalar("SELECT COUNT(*) FROM wa_outbox WHERE to_number = :t AND purpose = 'otp'", ['t' => $to23], 0) === 0
        && !str_contains((string) ($lg['body'] ?? ''), '918273'), json_encode($lg));

    section('wa.me hand-over');
    check('wa.me link carries the text', WaSend::waMe('919876543210', "a b\nc") === 'https://wa.me/919876543210?text=a%20b%0Ac');
} finally {
    $cleanup();
    foreach ($kvKeep as $k) {
        Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_outbox', :k, :v, 'restore')
                       ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => $k['kkey'], 'v' => $k['kvalue']]);
    }
    foreach ($keep as $k => $old) {
        if ($old === null) {
            Database::run('DELETE FROM settings WHERE skey = :k', ['k' => $k]);
        } else {
            Database::run('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $old['svalue'], 'k' => $k]);
        }
    }
    Settings::flush();
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL PASS\033[0m" : "\033[31mFAILED: $FAIL\033[0m") . " ($PASS passed)\n";
exit($FAIL === 0 ? 0 : 1);
