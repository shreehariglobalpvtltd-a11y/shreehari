<?php
/**
 * =====================================================================
 *  u3b-noreply-alert-test.php — Master v2 (16 Nov 2026), unit U3b,
 *  P6-10c: cron/wa-inbound.php tells the OFFICE when a WhatsApp message
 *  has had no reply for 10 minutes.
 *
 *    - switch wa_noreply_alert_on OFF (the default) → nothing at all
 *    - a message 10-60 min old with nothing sent back since → one bell
 *      alert (link to the chat) + one office WhatsApp
 *    - answered, too fresh (< 10 min), too old (> 60 min), a reaction, or
 *      the office's own number → no alert
 *    - bot paused + a reply that FAILED → alert, marked "(bot paused)"
 *    - a code in the customer's words never reaches the office message
 *    - once per number per hour: a second run alerts nobody again
 *
 *  Loads the cron's functions only (SHG_WAI_FUNCS_ONLY) — the replay does
 *  not run. The office WhatsApp goes to a recorder, never to a provider.
 *  Settings are overridden in this process only. Every row it makes is
 *  deleted at the end.
 *
 *    php -c .claude/php-dev.ini tests/u3b-noreply-alert-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('SHG_WAI_FUNCS_ONLY', true);
require dirname(__DIR__) . '/cron/wa-inbound.php';

if (!str_contains((string) DB_NAME, 'test')) { exit("Refusing to run: DB_NAME is not a test database.\n"); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what) use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what\n"; }
};

Settings::get('company_name');
$mem = static function (string $k, string $v, string $t = 'string'): void {
    $rp = new ReflectionProperty(Settings::class, 'cache');
    $c  = (array) $rp->getValue();
    $c[$k] = ['value' => $v, 'type' => $t, 'public' => false];
    $rp->setValue(null, $c);
};

$r8   = static fn(): string => (string) random_int(10000000, 99999999);
$N    = ['A' => '9198' . $r8(), 'B' => '9198' . $r8(), 'C' => '9198' . $r8(), 'D' => '9198' . $r8(),
         'E' => '9198' . $r8(), 'F' => '9198' . $r8(), 'G' => '9198' . $r8()];
$tag  = 'u3bnr' . random_int(1000, 9999);
$mem('company_whatsapp', $N['F']);                    // F is the office's own number
$mem('admin_whatsapp', $N['F']);

$others = (int) Database::scalar('SELECT COUNT(*) FROM wa_inbound WHERE created_at >= NOW() - INTERVAL 60 MINUTE', [], 0);
if ($others > 0) {
    echo "  note  $others other wa_inbound row(s) in the last hour — assertions only look at this test's numbers\n";
}

$put = static function (string $who, int $minAgo, string $type = 'text', string $text = 'namaste') use ($tag): void {
    $wamid = $tag . '-' . $who . '-' . $minAgo;
    $payload = json_encode(['id' => $wamid, 'from' => $who, 'type' => $type, $type => $type === 'text' ? ['body' => $text] : ['emoji' => '👍']]);
    Database::run(
        "INSERT INTO wa_inbound (wamid, from_digits, msg_type, payload, status, attempts, created_at)
         VALUES (:w, :f, :t, :p, 'done', 1, NOW() - INTERVAL " . $minAgo . ' MINUTE)',
        ['w' => $wamid, 'f' => $who, 't' => $type, 'p' => $payload]
    );
};
$log = static function (string $to, int $minAgo, string $status) use ($tag): void {
    Database::run(
        "INSERT INTO message_logs (channel, purpose, provider, to_number, body, status, created_at)
         VALUES ('whatsapp', 'bot_reply', 'test', :to, :b, :s, NOW() - INTERVAL " . $minAgo . ' MINUTE)',
        ['to' => $to, 'b' => $tag, 's' => $status]
    );
};
$office = [];
$rec = static function (string $to, string $text) use (&$office): bool {
    $office[] = ['to' => $to, 'text' => $text];
    return true;
};
$bell = static fn(string $num): array => Database::fetchAll(
    "SELECT * FROM notifications WHERE audience = 'admin' AND title LIKE :t ORDER BY id", ['t' => '%+' . $num . '%']);

try {
    $put($N['A'], 15, 'text', 'mero ticket kaha cha? otp 482913');
    $put($N['B'], 15);  $log(substr($N['B'], -10), 12, 'sent');          // answered (stored as the bare 10 digits)
    $put($N['C'], 5);                                                      // too fresh
    $put($N['D'], 90);                                                     // too old
    $put($N['E'], 15, 'reaction');                                         // a 👍 needs no answer
    $put($N['F'], 15);                                                     // the office itself
    $put($N['G'], 20);  $log($N['G'], 18, 'failed');                       // paused, and the one reply failed
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_bot_pause', :k, :v, 'u3b-test')
                   ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue)", ['k' => $N['G'], 'v' => (string) (time() + 3600)]);

    echo "\n1. Switch off (the default)\n";
    $mem('wa_noreply_alert_on', '0', 'bool');
    $r = wai_noreply_alerts($rec);
    $ok(($r['off'] ?? false) === true && $office === [] && $bell($N['A']) === [], 'nothing is checked, no bell, no WhatsApp');
    $ok((string) Database::scalar("SELECT svalue FROM settings WHERE skey = 'wa_noreply_alert_on'", [], '0') === '0', 'the stored switch is OFF');

    echo "\n2. Switch on\n";
    $mem('wa_noreply_alert_on', '1', 'bool');
    $r = wai_noreply_alerts($rec);
    $mine = static fn(string $k) => array_values(array_filter($office, static fn(array $o): bool => str_contains($o['text'], '+' . $N[$k])));
    $ok($bell($N['A']) !== [] && count($mine('A')) === 1, 'unanswered for 15 min → one bell alert and one office WhatsApp');
    $a = $bell($N['A'])[0] ?? [];
    $ok(($a['link'] ?? '') === '/admin/wa-inbox.php?tab=inbox&p=' . $N['A'], 'the bell opens that chat in the WhatsApp inbox');
    $ok(str_contains((string) ($a['body'] ?? ''), 'mero ticket kaha cha') && !str_contains((string) ($a['body'] ?? '') . ($mine('A')[0]['text'] ?? ''), '482913'), 'the customer\'s words are shown, the code in them is hidden');
    $ok(($mine('A')[0]['to'] ?? '') === Settings::officeWhatsApp(), 'the WhatsApp goes to the office number only');
    $ok($bell($N['B']) === [] && $mine('B') === [], 'answered → no alert');
    $ok($bell($N['C']) === [] && $bell($N['D']) === [], 'under 10 min or over 60 min → no alert');
    $ok($bell($N['E']) === [], 'a reaction → no alert');
    $ok($bell($N['F']) === [], 'the office\'s own number → no alert');
    $g = $bell($N['G'])[0] ?? [];
    $ok($g !== [] && str_contains((string) $g['title'], '(bot paused)') && count($mine('G')) === 1, 'bot paused and the reply failed → alert, marked paused');
    $ok((int) ($r['alerted'] ?? 0) >= 2 && (int) ($r['office_wa'] ?? 0) >= 2, 'the run reports what it did');

    echo "\n3. Once per number per hour\n";
    $before = count($office);
    wai_noreply_alerts($rec);
    $ok(count($office) === $before && count($bell($N['A'])) === 1 && count($bell($N['G'])) === 1, 'a second run in the same hour tells nobody again');

    echo "\n4. The cron wiring\n";
    $src = (string) file_get_contents(dirname(__DIR__) . '/cron/wa-inbound.php');
    $ok(substr_count($src, 'wai_noreply_safe()') >= 3, 'the check runs on every pass, also when the replay is switched off');
    $ok(str_contains($src, "Settings::getBool('wa_noreply_alert_on', false)"), 'the code default is OFF too');

    echo "\n5. Messenger words kept 30 days (fb_inbound)\n";
    if (Database::fetch("SHOW TABLES LIKE 'fb_inbound'") !== null) {
        $old = Database::insert('fb_inbound', ['psid' => '9902' . $r8(), 'direction' => 'in', 'kind' => 'message', 'body' => 'old words ' . $tag, 'status' => 'received']);
        $new = Database::insert('fb_inbound', ['psid' => '9902' . $r8(), 'direction' => 'in', 'kind' => 'message', 'body' => 'new words ' . $tag, 'status' => 'received']);
        Database::run('UPDATE fb_inbound SET created_at = NOW() - INTERVAL 31 DAY WHERE id = :i', ['i' => $old]);
        wai_fb_blank();
        $ok(Database::scalar('SELECT body FROM fb_inbound WHERE id = :i', ['i' => $old], 'x') === null
            && (string) Database::scalar('SELECT body FROM fb_inbound WHERE id = :i', ['i' => $new], '') === 'new words ' . $tag,
            'words older than 30 days are blanked, newer ones kept, the rows stay');
        Database::run('DELETE FROM fb_inbound WHERE id IN (:a, :b)', ['a' => $old, 'b' => $new]);
    }
} catch (Throwable $e) {
    $fail++;
    echo '  FAIL  exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
} finally {
    Database::run('DELETE FROM wa_inbound WHERE wamid LIKE :w', ['w' => $tag . '-%']);
    Database::run('DELETE FROM message_logs WHERE body = :b', ['b' => $tag]);
    foreach ($N as $num) {
        Database::run("DELETE FROM notifications WHERE audience = 'admin' AND title LIKE :t", ['t' => '%+' . $num . '%']);
        Database::run('DELETE FROM automation_log WHERE dedupe_key LIKE :k', ['k' => 'alert:wa_noreply:' . $num . ':%']);
        Database::run("DELETE FROM kv_store WHERE kscope = 'wa_bot_pause' AND kkey = :k", ['k' => $num]);
    }
}
$left = (int) Database::scalar('SELECT COUNT(*) FROM wa_inbound WHERE wamid LIKE :w', ['w' => $tag . '-%'], 0)
      + (int) Database::scalar("SELECT COUNT(*) FROM notifications WHERE title LIKE :t", ['t' => '%+' . $N['A'] . '%'], 0)
      + (int) Database::scalar('SELECT COUNT(*) FROM message_logs WHERE body = :b', ['b' => $tag], 0);
$ok($left === 0, 'every test row was removed');

echo "\n" . ($fail === 0 ? "ALL $pass PASSED" : "$fail FAILED, $pass passed") . "\n";
exit($fail === 0 ? 0 : 1);
