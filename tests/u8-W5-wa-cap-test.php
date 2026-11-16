<?php
/**
 * =====================================================================
 *  u8-W5-wa-cap-test.php — P6-11e (16 Nov 2026): the office's ceiling on
 *  ALL outbound WhatsApp a day (wa_global_daily_cap, 0 = none, default).
 *
 *  Pure part:
 *    - WaRoute::capBlockable(): only offer / campaign / marketing / promo /
 *      broadcast / good_morning; never a ticket, code, bot reply, office
 *      alert, trip update, money message or an unknown purpose
 *
 *  Database part (shari_test; rows deleted, settings restored):
 *    - every accepted WhatsApp counts (Notify::logOutbound 'sent' +1,
 *      'failed' +0); the figure is WaRoute::allToday()
 *    - cap 0: nothing is ever refused
 *    - at the cap: Notify::whatsapp() refuses a campaign message (false,
 *      'skipped' row with the reason, journal reason daily_cap, no send)
 *      but an office note / ticket-type purpose still goes down its path
 *    - 80 %: one bell (link to the WhatsApp Manager) + one office note per
 *      day, none below 80 %
 *    - admin/wa-manager.php: shows "All today" vs the cap and the field;
 *      bad CSRF and a bad number change nothing; a good save writes the
 *      cap and an audit row with old and new
 *
 *    php -c .claude/php-dev.ini tests/u8-W5-wa-cap-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

/* ---- child mode: admin/wa-manager.php in its own PHP process ---------- */
if (($argv[1] ?? '') === '--child') {
    $cfg = json_decode((string) base64_decode((string) ($argv[2] ?? '')), true) ?: [];
    $_SERVER['HTTP_HOST']      = 'localhost';
    $_SERVER['REMOTE_ADDR']    = '203.0.113.71';
    $_SERVER['SCRIPT_NAME']    = '/admin/wa-manager.php';
    $_SERVER['REQUEST_URI']    = '/admin/wa-manager.php';
    $post = (array) ($cfg['post'] ?? []);
    $_SERVER['REQUEST_METHOD'] = $post === [] ? 'GET' : 'POST';
    $_GET = [];
    define('SHG_APP', true);
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
    $a = Database::fetch('SELECT * FROM admins WHERE id = :i', ['i' => (int) ($cfg['admin'] ?? 0)]);
    if ($a === null) { fwrite(STDERR, "no admin\n"); exit(2); }
    $_SESSION[ADMIN_SESSION_KEY] = [
        'id' => (int) $a['id'], 'username' => (string) $a['username'], 'full_name' => (string) ($a['full_name'] ?? ''),
        'role' => (string) $a['role'], 'permissions' => jsonColumn($a['permissions'] ?? null), 'last_seen' => time(),
    ];
    if ($post !== []) {
        $_POST = $post;
        $_POST[CSRF_TOKEN_NAME]  = ($cfg['csrf'] ?? 'valid') === 'valid' ? Security::csrfToken() : 'not-the-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
    }
    require dirname(__DIR__) . '/admin/wa-manager.php';
    exit(0);
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';

$PASS = 0; $FAIL = 0;
function check(string $label, bool $ok, string $why = ''): void
{
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $label\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $label" . ($why !== '' ? ' — ' . $why : '') . "\n"; }
}
function child(array $cfg): string
{
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini ? ' -c ' . escapeshellarg($ini) : '') . ' ' . escapeshellarg(__FILE__)
         . ' --child ' . escapeshellarg(base64_encode((string) json_encode($cfg)));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) { return ''; }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return $out . $err;
}

echo "\n=== W5 · daily ceiling on all WhatsApp (P6-11e) ===\n\n";

/* ---------------- pure ---------------- */
foreach (['campaign_note', 'offer', 'new_customer_offer', 'marketing', 'promo', 'broadcast', 'good_morning'] as $p) {
    check('blockable: ' . $p, WaRoute::capBlockable($p) === true);
}
foreach (['ticket', 'ticket_resend', 'ticket_update', 'aisensy_ticket', 'seller_copy', 'otp', 'login', 'wa_verify', 'reset',
          'team_credentials', 'bot_reply', 'inbox_reply', 'admin_note', 'office_note', 'office_report', 'wa_fail_alert',
          'noreply_alert', 'support_alert', 'trip_breakdown', 'trip_reminder_24h', 'trip_sos', 'payment_rejected',
          'booking_cancelled', 'agent_payment_reminder', 'manual_message', 'message', 'test', '', 'offer_ticket', 'promo_code'] as $p) {
    check('never held: ' . ($p === '' ? '(no purpose)' : $p), WaRoute::capBlockable($p) === false);
}

/* ---------------- database ---------------- */
$TO        = '9800000098';
$startLog  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM message_logs', [], 0);
$startNote = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM notifications', [], 0);
$startAud  = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);
$claimKey  = 'alert:wa_cap80:' . date('Y-m-d');
$claimRow  = Database::fetch('SELECT * FROM automation_log WHERE dedupe_key = :k', ['k' => $claimKey]);
$keys      = ['wa_global_daily_cap', 'wa_booking_on', 'wa_book_combo_on', 'wa_agent_on', 'wa_route_on', 'wa_inbound_replay_on',
              'wa_policy', 'wa_paid_daily_limit', 'wa_meta_free_allowance'];
$orig      = [];
foreach ($keys as $k) {
    $orig[$k] = Database::fetch('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings WHERE skey = :k', ['k' => $k]);
}
$setCap = static function (int $n): void {
    Settings::set('wa_global_daily_cap', (string) $n, 'int', 'whatsapp');
    Settings::flush();
};
$alerts = [];
WaRoute::$capAlert = static function (string $head, array $facts) use (&$alerts): void { $alerts[] = [$head, $facts]; };

try {
    /* counting */
    $u0 = WaRoute::allToday();
    Notify::logOutbound('91' . $TO, 'W5 cap count sent', 'sent', ['provider' => 'u8-w5', 'purpose' => 'test']);
    check('an accepted WhatsApp counts +1 (Notify::logOutbound sent)', WaRoute::allToday() === $u0 + 1, (WaRoute::allToday() - $u0) . '');
    Notify::logOutbound('91' . $TO, 'W5 cap count failed', 'failed', ['provider' => 'u8-w5', 'purpose' => 'test']);
    check('a refused one does not count', WaRoute::allToday() === $u0 + 1);
    Notify::logOutbound('W5PSID000', 'W5 cap count messenger', 'sent', ['provider' => 'messenger', 'purpose' => 'bot_reply']);
    check('a Facebook Messenger reply (same log, provider messenger) is not a WhatsApp: not counted', WaRoute::allToday() === $u0 + 1);
    check('WaRoute::stats() carries allToday and globalCap', array_key_exists('allToday', WaRoute::stats()) && array_key_exists('globalCap', WaRoute::stats()));

    /* cap 0 = never refused */
    $setCap(0);
    check('cap 0: a campaign message is not refused', WaRoute::capRefusal('campaign_note') === '');

    /* below 80 %: no alert */
    Database::delete('automation_log', 'dedupe_key = :k', ['k' => $claimKey]);
    $setCap(WaRoute::allToday() * 10 + 100);
    WaRoute::noteSent();
    check('below 80 %: no bell, no office note', $alerts === []
        && !Database::exists('SELECT 1 FROM notifications WHERE id > :s AND link = :l', ['s' => $startNote, 'l' => '/admin/wa-manager.php']));

    /* at the cap */
    $setCap(WaRoute::allToday());
    $why = WaRoute::capRefusal('campaign_note');
    check('at the cap: a campaign message is refused with the reason', str_contains($why, 'wa_global_daily_cap'), $why);
    foreach (['ticket', 'otp', 'bot_reply', 'admin_note', 'trip_breakdown', 'agent_payment_reminder', 'manual_message'] as $p) {
        check('  at the cap ' . $p . ' still goes', WaRoute::capRefusal($p) === '');
    }
    $before = WaRoute::allToday();
    $res = Notify::whatsapp($TO, 'W5 cap offer', null, null, [], null, ['purpose' => 'campaign_note']);
    $j = Notify::whatsappJournal();
    $last = $j[count($j) - 1] ?? [];
    check('Notify::whatsapp refuses the campaign message (false)', $res === false);
    check('  journal says daily_cap', ($last['reason'] ?? '') === 'daily_cap', json_encode($last));
    $row = Database::fetch("SELECT status, error, purpose FROM message_logs WHERE id > :s AND body = 'W5 cap offer' ORDER BY id DESC LIMIT 1", ['s' => $startLog]);
    check('  one skipped row with the reason and the purpose', $row !== null && $row['status'] === 'skipped'
        && str_contains((string) $row['error'], 'daily WhatsApp cap') && $row['purpose'] === 'campaign_note', json_encode($row));
    check('  nothing was counted as sent', WaRoute::allToday() === $before);
    $res2 = Notify::whatsapp($TO, 'W5 cap office note', null, null, [], null, ['purpose' => 'admin_note']);
    $j = Notify::whatsappJournal();
    $last = $j[count($j) - 1] ?? [];
    check('an office note at the cap is NOT held back', $res2 !== false || ($last['reason'] ?? '') !== 'daily_cap', json_encode($last));
    check('  its row is not a cap refusal', !Database::exists("SELECT 1 FROM message_logs WHERE id > :s AND body = 'W5 cap office note' AND error LIKE '%daily WhatsApp cap%'", ['s' => $startLog]));

    /* 80 %: once a day */
    Database::delete('automation_log', 'dedupe_key = :k', ['k' => $claimKey]);
    $setCap(WaRoute::allToday() + 1);                      // the next send is >= 80 %
    WaRoute::noteSent();
    $bell = Database::fetch('SELECT * FROM notifications WHERE id > :s AND link = :l ORDER BY id DESC LIMIT 1', ['s' => $startNote, 'l' => '/admin/wa-manager.php']);
    check('at 80 %: one bell linking the WhatsApp Manager', $bell !== null && str_contains((string) $bell['title'], 'दैनिक सीमा'), json_encode($bell));
    check('  and one office note (Nepali + English, the figures)', count($alerts) === 1 && str_contains((string) $alerts[0][0], 'daily cap')
        && str_contains((string) ($alerts[0][1]['Today / आज'] ?? ''), '/ ' . WaRoute::globalCap()), json_encode($alerts));
    WaRoute::noteSent();
    check('  a second send the same day rings nothing more', count($alerts) === 1
        && (int) Database::scalar('SELECT COUNT(*) FROM notifications WHERE id > :s AND link = :l', ['s' => $startNote, 'l' => '/admin/wa-manager.php'], 0) === 1);

    /* the office page */
    $super = (int) Database::scalar("SELECT id FROM admins WHERE role = 'superadmin' AND is_active = 1 ORDER BY id LIMIT 1", [], 0);
    if ($super <= 0) {
        throw new RuntimeException('no superadmin to open the page');
    }
    $setCap(500);
    $page = child(['admin' => $super]);
    check('wa-manager shows today\'s count against the cap', str_contains($page, 'सबै आज · All today') && (bool) preg_match('~\d+ / 500 daily cap \(\d+%\)~', $page),
        substr(strip_tags($page), 0, 300));
    check('  the cap field is in the Switches form', str_contains($page, 'name="wa_global_daily_cap" value="500"'));
    check('  no PHP warning on the page', !preg_match('/(Warning|Fatal error|Notice):/', $page));

    $current = [];
    foreach (['wa_booking_on' => true, 'wa_book_combo_on' => true, 'wa_agent_on' => false, 'wa_route_on' => true, 'wa_inbound_replay_on' => true] as $k => $d) {
        if (Settings::getBool($k, $d)) { $current[$k] = '1'; }
    }
    $form = $current + ['action' => 'switches', 'wa_policy' => WaRoute::policy(), 'wa_paid_daily_limit' => Settings::getString('wa_paid_daily_limit', ''),
                        'wa_meta_free_allowance' => (string) Settings::getInt('wa_meta_free_allowance', 0)];
    child(['admin' => $super, 'post' => $form + ['wa_global_daily_cap' => '750'], 'csrf' => 'bad']);
    Settings::flush();
    check('bad CSRF: the cap is unchanged', WaRoute::globalCap() === 500);
    $o = child(['admin' => $super, 'post' => $form + ['wa_global_daily_cap' => '12abc']]);
    Settings::flush();
    check('a bad number is refused with a message, cap unchanged', WaRoute::globalCap() === 500 && str_contains($o, 'All WhatsApp per day'));
    child(['admin' => $super, 'post' => $form + ['wa_global_daily_cap' => '750']]);
    Settings::flush();
    check('a good save writes the cap', WaRoute::globalCap() === 750);
    $aud = Database::fetch("SELECT old_value, new_value FROM audit_logs WHERE id > :s AND action = 'wam.switches' ORDER BY id DESC LIMIT 1", ['s' => $startAud]);
    $old = json_decode((string) ($aud['old_value'] ?? ''), true) ?: [];
    $new = json_decode((string) ($aud['new_value'] ?? ''), true) ?: [];
    check('  audited with the old and the new cap', ($old['wa_global_daily_cap'] ?? '') === '500' && ($new['wa_global_daily_cap'] ?? '') === '750', json_encode($aud));
    check('  the other switches kept their values', ($new['wa_policy'] ?? '') === WaRoute::policy()
        && (string) ($new['wa_booking_on'] ?? '') === (isset($current['wa_booking_on']) ? '1' : '0'));
    $page = child(['admin' => $super, 'post' => $form]);       // an old form without the field
    Settings::flush();
    check('a form from before the field keeps the stored cap', WaRoute::globalCap() === 750);
} catch (Throwable $e) {
    check('test ran without an exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    WaRoute::$capAlert = null;
    Database::run("DELETE FROM message_logs WHERE id > :s AND (provider = 'u8-w5' OR body LIKE 'W5 cap %')", ['s' => $startLog]);
    Database::run('DELETE FROM notifications WHERE id > :s AND link = :l', ['s' => $startNote, 'l' => '/admin/wa-manager.php']);
    Database::run("DELETE FROM audit_logs WHERE id > :s AND action = 'wam.switches'", ['s' => $startAud]);
    Database::delete('automation_log', 'dedupe_key = :k', ['k' => $claimKey]);
    if ($claimRow !== null) {
        Database::insert('automation_log', $claimRow);
    }
    foreach ($orig as $k => $row) {
        Database::delete('settings', 'skey = :k', ['k' => $k]);
        if ($row !== null) {
            Database::insert('settings', $row);
        }
    }
    Settings::flush();
}

echo "\n  {$PASS} passed, {$FAIL} failed\n\n";
exit($FAIL === 0 ? 0 : 1);
