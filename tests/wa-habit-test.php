<?php
/**
 * wa-habit-test.php — the bot learns repeated work (includes/wahabit.php, 9 Oct 2026).
 *     php tests/wa-habit-test.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/waroute.php';
require_once INCLUDE_PATH . '/walocal.php';
require_once INCLUDE_PATH . '/wabot.php';
require_once INCLUDE_PATH . '/wahabit.php';
if (stripos((string) DB_NAME, 'test') === false && !str_starts_with((string) DB_NAME, 'lt_')) { exit("Refusing: DB_NAME must be a test database.\n"); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — " . mb_substr($extra, 0, 300) : '') . "\n"; }
}
const WH_BOSS = '9400011191';
$intl = '91' . WH_BOSS;
$keep = [];
foreach (['wa_habits_on' => '1', 'wa_local_brain_on' => '1', 'wa_report_chart_on' => '0', 'wa_agent_on' => '0', 'wa_login_on' => '0', 'wa_staff_lang' => 'ne'] as $k => $v) {
    $keep[$k] = Database::exists('SELECT 1 FROM settings WHERE skey = :k', ['k' => $k]) ? Settings::getString($k, '') : null;
    Settings::set($k, $v, 'string', 'whatsapp', false);
}
Settings::flush();
$bossId = (int) Database::scalar("SELECT id FROM admins WHERE username = 'wa-habit-boss'", [], 0);
$cleanup = static function () use ($keep, $bossId, $intl): void {
    foreach ($keep as $k => $v) {
        try { if ($v === null) { Database::delete('settings', 'skey = :k', ['k' => $k]); } else { Database::update('settings', ['svalue' => $v], 'skey = :k', ['k' => $k]); } } catch (Throwable $e) {}
    }
    Settings::flush();
    foreach (["DELETE FROM wa_habits WHERE phone = '$intl'", "DELETE FROM kv_store WHERE kkey = '$intl'", "DELETE FROM ai_agent_calls WHERE phone = '" . WH_BOSS . "'",
              "DELETE FROM message_logs WHERE to_number = '$intl'", "DELETE FROM admins WHERE username = 'wa-habit-boss'", "DELETE FROM rate_limits WHERE identifier LIKE '%" . WH_BOSS . "%'"] as $sql) {
        try { Database::run($sql); } catch (Throwable $e) {}
    }
    WaRoute::$transport = null;
};
register_shutdown_function($cleanup);
$cleanup();
if ($bossId === 0) {
    $bossId = (int) Database::insert('admins', ['username' => 'wa-habit-boss', 'password_hash' => password_hash('Wh@123456', PASSWORD_BCRYPT),
        'full_name' => 'Habit Boss', 'role' => 'superadmin', 'phone' => WH_BOSS, 'is_active' => 1, 'must_change_pw' => 0]);
}
Settings::set('wa_habits_on', '1', 'string', 'whatsapp', false); Settings::set('wa_local_brain_on', '1', 'string', 'whatsapp', false); Settings::flush();

$calls = [];
WaRoute::$transport = static function (string $to, string $text, ?string $image) use (&$calls): array {
    $calls[] = ['to' => $to, 'text' => $text];
    return ['success' => true, 'message_id' => 'wamid.HABIT' . count($calls), 'error' => ''];
};
$ask = static fn(string $q): array => WaBot::reply('+91' . WH_BOSS, $q);

echo "\n=== the bot learns repeated work ===\n\n";
try {
    check('table + switch ready', WaHabit::ready());
    $r = $ask('hisab');
    check('day 1: the answer, no offer yet', str_contains($r['text'], '📊') && !str_contains($r['text'], 'आफैं पठाइदिऊँ'), $r['text']);
    $row = Database::fetch('SELECT * FROM wa_habits WHERE phone = :p', ['p' => $intl]);
    check('… noted once (days_seen 1, status seen)', $row !== null && (int) $row['days_seen'] === 1 && $row['status'] === 'seen', json_encode($row));
    $r = $ask('hisab');
    check('the same day again: still counts as one day', !str_contains($r['text'], 'आफैं पठाइदिऊँ') && (int) Database::scalar('SELECT days_seen FROM wa_habits WHERE phone = :p', ['p' => $intl], 0) === 1);
    // Yesterday and the day before, same hour.
    Database::run('UPDATE wa_habits SET days_seen = 2, last_day = :d WHERE phone = :p', ['d' => date('Y-m-d', strtotime('-1 day')), 'p' => $intl]);
    $r = $ask('aaja ko hisab');
    check('day 3 at the same hour: the answer + the offer with ho / hoina buttons', str_contains($r['text'], '📊') && str_contains($r['text'], 'आफैं पठाइदिऊँ')
        && in_array('shg:habit ho', array_column((array) ($r['ui']['buttons'] ?? []), 'id'), true), $r['text']);
    check('… status offered', Database::scalar('SELECT status FROM wa_habits WHERE phone = :p', ['p' => $intl], '') === 'offered');
    $r = $ask('timetable');
    check('another question meanwhile: answered normally, offer stays open', str_contains($r['text'], '🕒') && (string) Database::scalar("SELECT kvalue FROM kv_store WHERE kscope = 'wa_habit' AND kkey = :k", ['k' => $intl], '') !== '');
    $r = $ask('ho');
    check('"ho" turns it on', str_contains($r['text'], '✅') && Database::scalar('SELECT status FROM wa_habits WHERE phone = :p', ['p' => $intl], '') === 'on', $r['text']);
    $r = $ask('ho');
    check('a second "ho" with nothing open is not ours', !str_contains($r['text'], 'habit band'), $r['text']);
    $r = $ask('mero habit');
    check('"mero habit" lists it', str_contains($r['text'], '🗓') && str_contains($r['text'], 'ON'), $r['text']);

    echo "\n— the daily send (cron)\n";
    $hour = (int) Database::scalar('SELECT hour FROM wa_habits WHERE phone = :p', ['p' => $intl], 0);
    $at = mktime($hour, 5, 0);
    Database::run("DELETE FROM kv_store WHERE kscope = 'wa_inbound' AND kkey = :k", ['k' => $intl]);
    $calls = [];
    $out = WaHabit::run($at);
    check('window closed: due but skipped, nothing paid', $out['due'] === 1 && $out['sent'] === 0 && $calls === [], json_encode($out));
    Database::run("INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('wa_inbound', :k, :v, 'test') ON DUPLICATE KEY UPDATE kvalue = :v2",
        ['k' => $intl, 'v' => (string) time(), 'v2' => (string) time()]);
    $out = WaHabit::run($at);
    check('window open: sent free on Meta with the daily head', $out['sent'] === 1 && count($calls) === 1 && str_contains($calls[0]['text'], '🗓') && str_contains($calls[0]['text'], '📊'), json_encode($out) . ' ' . json_encode($calls, JSON_UNESCAPED_UNICODE));
    $out = WaHabit::run($at + 600);
    check('not twice the same day', $out['due'] === 0 && count($calls) === 1, json_encode($out));
    $out = WaHabit::run(mktime(($hour + 5) % 24, 0, 0, (int) date('n'), (int) date('j') + 1));
    check('another hour: nothing due', $out['due'] === 0);

    $r = $ask('habit band');
    check('"habit band" stops everything', str_contains($r['text'], '✅') && Database::scalar('SELECT status FROM wa_habits WHERE phone = :p', ['p' => $intl], '') === 'off', $r['text']);
    Settings::set('wa_habits_on', '0', 'string', 'whatsapp', false); Settings::flush();
    $r = $ask('mero habit');
    check('switch off: the command is not claimed', !str_contains($r['text'], '🗓'), $r['text']);
} catch (Throwable $e) {
    check('no exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
}
echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL > 0 ? 1 : 0);
