<?php
/**
 * =====================================================================
 *  cron/ai-warm.php — keep the local brain's briefing in its cache.
 *
 *  THE PROBLEM THIS EXISTS FOR
 *  ---------------------------
 *  llama.cpp reads a prompt on this box at about 12.7 tokens a second.
 *  The local briefing plus its six tool schemas is roughly 1 700 tokens,
 *  so the FIRST question after the model starts waits about two minutes
 *  before a single word appears. Every question after it is nearly free,
 *  because the server keeps that prefix in its KV cache and only reads
 *  what changed — the visitor's own sentence, thirty tokens, under three
 *  seconds.
 *
 *  So the cold read is not a per-question cost. It is a per-RESTART
 *  cost, and it should be paid by a cron job at 4 in the morning, not by
 *  a passenger at the counter.
 *
 *  WHAT IT DOES
 *  ------------
 *  Sends one tiny question through the exact prefix AiAgent will use —
 *  same briefing, same tool list, in the same order — so the cache it
 *  warms is the cache the real traffic will hit. A different prefix
 *  would warm the wrong thing and look like it worked.
 *
 *  It is also the health check: if the brain is down, this is where it
 *  shows up in the log, before a customer finds out.
 *
 *  Safe to run any time. Sends nothing to anybody, writes no booking
 *  row, and does nothing at all when ai_local_on is off.
 *
 *  Crontab (every 30 minutes; a restart is picked up within half an
 *  hour, and llama.cpp holds the prefix indefinitely otherwise):
 *      *\/30 * * * * www-data php /var/www/.../cron/ai-warm.php
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';

$started = microtime(true);
$quiet   = in_array('--quiet', $argv ?? [], true);
$say     = static function (string $line) use ($quiet): void {
    if (!$quiet) { echo $line, PHP_EOL; }
};

if (!Settings::getBool('ai_local_on', false)) {
    $say('local brain is off (ai_local_on = 0) — nothing to warm');
    exit(0);
}

$base = rtrim(Settings::getString('ai_local_url', 'http://127.0.0.1:8081'), '/');

/* ---- is it even up? ------------------------------------------------ */
$ch = curl_init($base . '/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$health = (string) curl_exec($ch);
$code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200 || !str_contains($health, 'ok')) {
    Logger::error('Local brain is not answering', ['url' => $base, 'http' => $code], 'ai');
    $say("BRAIN DOWN — {$base} answered HTTP {$code}. Try: systemctl status shg-brain");
    exit(1);
}

/* ---- warm the prefix the real traffic will use --------------------- */
/* A guest on the website is the overwhelmingly common case, and it is
   the prefix every other customer conversation shares. Warming a staff
   prefix instead would leave the one that matters cold. */
$ctx = [
    'role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null,
    'name' => '', 'phone' => '', 'channel' => 'web', 'userId' => 0, 'stageKey' => '',
];

/* GO THROUGH THE AGENT'S OWN CALL, do not rebuild the request here.

   The first version of this script hand-assembled the JSON the way
   askOpenAICompat() does. It looked identical and it was not: the real
   turn that followed it still had to re-read 549 tokens, because the
   cache only reuses a prefix that matches to the BYTE and something in
   the tool block came out different. A warm-up that warms a nearly
   identical prefix is worse than none — it burns two minutes of CPU and
   reports success while the customer still waits.

   Calling AiAgent::ask() through reflection removes the whole class of
   bug: whatever the agent sends, this sends, for ever, including any
   future change to how tools are serialised. */
$askM = new ReflectionMethod(AiAgent::class, 'ask');
$askM->setAccessible(true);
$promptM = new ReflectionMethod(AiAgent::class, 'localPrompt');
$promptM->setAccessible(true);
$toolsM = new ReflectionMethod(AiAgent::class, 'localTools');
$toolsM->setAccessible(true);

$system = (string) $promptM->invoke(null, $ctx);
$tools  = (array) $toolsM->invoke(null, $ctx);

/* One short question, so the only thing not cached for a real visitor is
   their own sentence. */
$history = [['role' => 'user', 'content' => 'ठिक छ?']];

$reply = null;
try {
    $reply = $askM->invoke(null, $system, $history, $tools, $ctx, false);
} catch (Throwable $e) {
    Logger::error('Local brain warm-up threw', ['err' => $e->getMessage()], 'ai');
    $say('warm-up FAILED: ' . $e->getMessage());
    exit(1);
}

$secs = microtime(true) - $started;

if ($reply === null) {
    Logger::error('Local brain warm-up got no answer', ['seconds' => round($secs, 1)], 'ai');
    $say(sprintf('warm-up FAILED after %.1fs — no answer (see journalctl -u shg-brain)', $secs));
    exit(1);
}

/* ask() returns the parsed reply, not the envelope, so the token counts
   come from the server's own slot log rather than from here. What this
   run proves is that the prefix is now resident. */
$cached = 0;
$total  = 0;

/* cached == total means the prefix was ALREADY warm and this run cost
   nothing — which is the steady state we want to see in the log. */
Logger::info('Local brain warmed', [
    'seconds' => round($secs, 1),
    'cold'    => $secs >= 10,
], 'ai');

/* Under ten seconds means the prefix was already resident and this run
   only paid for the little question; a cold read on this box is two
   minutes or more. The exact token split is in the server's slot log:
       journalctl -u shg-brain | grep 'prompt eval time' */
$say(sprintf(
    'warm in %.1fs%s',
    $secs,
    $secs < 10 ? ' (was already warm)' : ' (paid the cold read)'
));
exit(0);
