<?php
/**
 * =====================================================================
 *  u7-ai-turns-test.php — P7-9a (16 Nov 2026): one ai_turns row per
 *  answered assistant turn, with NO message text.
 *
 *    • AiAgent::recordTurn(): owner = sha256 of the phone digits, tools
 *      de-duplicated, ok / ms / wamid kept, the reply's message_logs row
 *      found by its provider ref, an unknown role filed as customer
 *    • a missing table (migration not applied) writes nothing, throws nothing
 *    • WaBot::$lastRouter says which branch answered (a bare PNR = "pnr")
 *    • AiTools::$turnTools collects the tools a turn ran
 *    • static mirrors: the webhook writes the row right after the reply,
 *      AiAgent::handleWeb writes one per web turn, the table has no text column
 *
 *  Rows carry the marker router "u7test" / wamid "wamid.U7TEST…" and the
 *  test number 9100079xxx (no other suite uses it); all are deleted at the end.
 *      php -c .claude/php-dev.ini tests/u7-ai-turns-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';
require_once INCLUDE_PATH . '/wabot.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== ai_turns — one row per answered turn, no message text ===\n\n";

if (!AiAgent::turnsReady()) {
    echo "  SKIP  run database/upgrade-2026-11-16-ai-turns.sql first\n";
    exit(1);
}

$PHONE = '9100079001';
$TAG   = 'U7TEST' . bin2hex(random_bytes(4));
$WAMID = 'wamid.' . $TAG;
$SID   = 'U7SID' . bin2hex(random_bytes(6));
$root  = dirname(__DIR__);
$src   = static fn(string $rel): string => (string) @file_get_contents($root . '/' . $rel);

$cleanup = static function () use ($TAG, $SID): void {
    try { Database::delete('ai_turns', "router = 'u7test' OR wamid LIKE :w", ['w' => 'wamid.' . $TAG . '%']); } catch (Throwable $e) {}
    try { Database::delete('message_logs', 'provider_ref = :r', ['r' => $SID]); } catch (Throwable $e) {}
    try { Database::delete('kv_store', "kscope IN ('wa_stage','wa_agent','wa_turn') AND kkey LIKE '9100079%'", []); } catch (Throwable $e) {}
    // review: the WaBot::reply probe below leaves the desk-chat state row for its number
    try { Database::delete('ap_wa_state', "phone LIKE '9100079%'", []); } catch (Throwable $e) {}
};
$cleanup();
$turnsProp = new ReflectionProperty(AiAgent::class, 'turnsReady');

try {
    /* ---- 1. recordTurn ------------------------------------------------ */
    echo "== recordTurn() ==\n";
    $logId = (int) Database::insert('message_logs', [
        'channel' => 'whatsapp', 'provider' => 'cloud_api', 'to_number' => $PHONE, 'body' => 'u7 test reply',
        'status' => 'sent', 'provider_ref' => $SID, 'purpose' => 'bot_reply',
    ]);
    AiAgent::recordTurn([
        'channel' => 'whatsapp', 'role' => 'staff', 'owner' => $PHONE, 'router' => 'u7test',
        'tools' => ['plan_ticket', 'plan_ticket', 'find_ticket'], 'ok' => true, 'ms' => 123,
        'wamid' => $WAMID, 'replySid' => $SID,
    ]);
    $row = Database::fetch('SELECT * FROM ai_turns WHERE wamid = :w', ['w' => $WAMID]);
    check('a row is written for the turn', $row !== null);
    check('  the sender is only a sha256 of the digits, never the number',
        $row !== null && (string) $row['owner_key'] === hash('sha256', $PHONE) && !str_contains(implode('|', array_map('strval', $row)), $PHONE),
        (string) ($row['owner_key'] ?? ''));
    check('  router, role, channel as given', $row !== null && $row['router'] === 'u7test' && $row['role'] === 'staff' && $row['channel'] === 'whatsapp');
    check('  the tools, each once, in order', $row !== null && (string) $row['tools'] === 'plan_ticket,find_ticket', (string) ($row['tools'] ?? ''));
    check('  ok + whole-turn ms kept', $row !== null && (int) $row['ok'] === 1 && (int) $row['ms'] === 123);
    check('  the reply\'s message_logs row is linked by its provider ref', $row !== null && (int) $row['reply_log_id'] === $logId, (string) ($row['reply_log_id'] ?? 'null') . ' vs ' . $logId);
    check('  feedback starts empty', $row !== null && $row['feedback'] === null);

    AiAgent::recordTurn(['channel' => 'web', 'role' => 'hacker', 'owner' => 'web-sess-' . $TAG, 'router' => 'u7test', 'ok' => false, 'wamid' => $WAMID . '-2']);
    $row2 = Database::fetch('SELECT * FROM ai_turns WHERE wamid = :w', ['w' => $WAMID . '-2']);
    check('an unknown role is filed as customer; a web guest is keyed by a hash of the session key',
        $row2 !== null && $row2['role'] === 'customer' && (string) $row2['owner_key'] === hash('sha256', 'web-sess-' . $TAG) && (int) $row2['ok'] === 0 && $row2['reply_log_id'] === null);

    $cols = array_map(static fn(array $c): string => (string) $c['COLUMN_NAME'], Database::fetchAll(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_turns'"));
    check('the table has no column for message text', array_intersect($cols, ['body', 'text', 'message', 'content', 'reply', 'query', 'response']) === [], implode(',', $cols));

    /* ---- 2. a missing table writes nothing and never throws ------------ */
    echo "\n== migration not applied yet ==\n";
    $before = (int) Database::scalar("SELECT COUNT(*) FROM ai_turns WHERE router = 'u7test'", [], 0);
    $turnsProp->setValue(null, false);
    $threw = false;
    try {
        AiAgent::recordTurn(['owner' => $PHONE, 'router' => 'u7test', 'ok' => true, 'wamid' => $WAMID . '-3']);
    } catch (Throwable $e) {
        $threw = true;
    }
    $turnsProp->setValue(null, null);
    check('with no ai_turns table nothing is written and nothing throws',
        !$threw && (int) Database::scalar("SELECT COUNT(*) FROM ai_turns WHERE router = 'u7test'", [], 0) === $before);

    /* ---- 3. which branch answered -------------------------------------- */
    echo "\n== WaBot::\$lastRouter ==\n";
    $reply = WaBot::reply('+91' . '9100079002', 'SHG-2099-U7999');
    check('a bare PNR is answered by the PNR lookup (router "pnr")', WaBot::$lastRouter === 'pnr' && $reply['text'] !== '', WaBot::$lastRouter);
    check('  and the role defaults to customer', WaBot::$lastRole === 'customer', WaBot::$lastRole);

    AiTools::$turnTools = [];
    AiTools::logCall('u7_probe', [], ['phone' => '9100079003', 'role' => 'customer', 'channel' => 'whatsapp'], true, 'u7', null, microtime(true));
    check('AiTools::$turnTools collects the tools a turn ran', AiTools::$turnTools === ['u7_probe'], implode(',', AiTools::$turnTools));
    try { Database::delete('ai_agent_calls', "phone = '9100079003' AND tool = 'u7_probe'", []); } catch (Throwable $e) {}
    AiTools::$turnTools = [];

    /* ---- 4. static mirrors --------------------------------------------- */
    echo "\n== the wiring ==\n";
    $wh = $src('whatsapp/webhook.php');
    $at = strpos($wh, 'WaBot::reply(');
    check('the Meta webhook writes the row after the reply is sent and logged',
        $at !== false && strpos($wh, 'AiAgent::recordTurn(', $at) > strpos($wh, "'purpose'  => 'bot_reply'", $at)
        && str_contains($wh, 'WaBot::$lastRouter') && str_contains($wh, "'wamid'    => \$wamid"));
    check('  …timed from before WaBot::reply', str_contains($wh, '$turnT0 = microtime(true);'));
    $ag = $src('includes/aiagent.php');
    check('AiAgent::handleWeb writes one row per web / Messenger turn', str_contains($ag, "\$turnRow('agent', true)") && str_contains($ag, "\$turnRow('agent', false)") && str_contains($ag, "'messenger' : 'web'"));
    check('every write is guarded by the table check and a catch', str_contains($ag, 'if (!self::turnsReady())') && str_contains($ag, "ai_turns write failed"));
    $wb = $src('includes/wabot.php');
    check('  an agent-route answer is filed as staff, not customer', str_contains($wb, "if (\$earlyVia === 'agentroute') {") && str_contains($wb, "self::\$lastRole = 'staff';"));
    foreach (['apwa', 'waedit', 'walocal', 'wabooking', 'wafaq', 'agent', 'pnr'] as $r) {
        check("WaBot labels the \"$r\" branch", preg_match("/(?:lastRouter = |\\\$earlyVia = )'" . preg_quote($r, '/') . "'/", $wb) === 1);
    }
    $sql = $src('database/upgrade-2026-11-16-ai-turns.sql');
    check('the migration is additive (CREATE TABLE IF NOT EXISTS) with owner_key CHAR(64) and feedback up/down',
        str_contains($sql, 'CREATE TABLE IF NOT EXISTS `ai_turns`') && str_contains($sql, '`owner_key`    CHAR(64)') && str_contains($sql, "ENUM('up','down')")
        && !preg_match('/\b(DROP|TRUNCATE|DELETE)\b/i', $sql));
} catch (Throwable $e) {
    check('suite ran without an unexpected exception', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    $turnsProp->setValue(null, null);
    $cleanup();
}

echo "\n----------------------------------------\n";
echo "$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
