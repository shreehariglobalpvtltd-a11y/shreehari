<?php
/**
 * =====================================================================
 *  local-brain-test.php — does the assistant work with NO API key?
 *
 *  Owner ask, 25 Sep 2026: an assistant that runs on our own box, with
 *  no key and no internet. deploy/install-local-brain.sh puts llama.cpp
 *  and a 4B instruct model on the VPS as the systemd unit `shg-brain`;
 *  AiAgent reaches it through the provider row 'local'.
 *
 *  This proves the four things that can each silently break it:
 *
 *    1. the brain answers at all          (HTTP, on loopback)
 *    2. it is chosen FIRST                (buildLadder with no keys set)
 *    3. a whole turn comes back           (AiAgent::handleWeb, tools and all)
 *    4. it writes Nepali in DEVANAGARI    (measured: roman Nepali is poor,
 *                                          so the prompt forbids it)
 *
 *  and it PRINTS THE CLOCK, because on two CPU cores the difference
 *  between a good answer and an unusable one is seconds, not quality.
 *
 *  Run on a copy, never against live:
 *      ssh shari-vps 'cd /root/shg-test && php tests/local-brain-test.php'
 *
 *  SKIPS (exit 0) when ai_local_on is off or the server is not up, so a
 *  machine without the brain does not fail the suite.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';

$pass = 0;
$fail = 0;
$ok = static function (string $what, bool $good, string $detail = '') use (&$pass, &$fail): void {
    if ($good) { $pass++; echo "  PASS  {$what}"; }
    else       { $fail++; echo "  FAIL  {$what}"; }
    echo ($detail !== '' ? "  —  {$detail}" : ''), PHP_EOL;
};
$skip = static function (string $why): never {
    echo "\nSKIPPED: {$why}\n";
    exit(0);
};

echo "=== local brain ===\n\n";

if (!Settings::getBool('ai_local_on', false)) {
    $skip('ai_local_on is off (this is the shipped default)');
}

$base = rtrim(Settings::getString('ai_local_url', 'http://127.0.0.1:8081'), '/');

/* ---- 1. is it there ------------------------------------------------ */
$t0  = microtime(true);
$ch  = curl_init($base . '/health');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
$health = curl_exec($ch);
$code   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($code !== 200) {
    $skip("no brain on {$base} (HTTP {$code}) — systemctl status shg-brain");
}
$ok('health', str_contains((string) $health, 'ok'), sprintf('%.0f ms', (microtime(true) - $t0) * 1000));

/* ---- 2. is it the brain that gets picked --------------------------- */
$ladder = (function (): array {
    $r = new ReflectionMethod(AiAgent::class, 'buildLadder');
    $r->setAccessible(true);
    return (array) $r->invoke(null);
})();
$ok('local is on the ladder', in_array('local', $ladder, true), implode(' > ', $ladder) ?: '(empty)');
$ok('local is tried first', ($ladder[0] ?? '') === 'local', 'ai_local_first = '
    . (Settings::getBool('ai_local_first', true) ? '1' : '0'));

/* AiAgent::webEnabled() used to mean "a cloud key is set". With a keyless
   brain that question is wrong, and getting it wrong means the widget
   silently stays rule-based however well the model is running. */
$ok('webEnabled() with no cloud key', AiAgent::webEnabled(), 'the widget will call the agent');

/* ---- 3. a whole turn, tools and all -------------------------------- */
$ctx = [
    'role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null,
    'name' => '', 'phone' => '', 'channel' => 'web', 'userId' => 0, 'stageKey' => '',
];

$asks = [
    'fare'    => 'सुरतबाट रुपैडिहाको भाडा कति हो?',
    'chat'    => 'नमस्ते, तपाईंको कम्पनीको बारेमा छोटोमा भन्नुहोस् न।',
    'offtopic'=> 'नेपालको राजधानी कुन हो?',
];

foreach ($asks as $label => $q) {
    AiAgent::forget('test-local-' . $label);
    $t = microtime(true);
    $out = AiAgent::handleWeb($ctx, $q, 'ne');
    $secs = microtime(true) - $t;
    $text = trim((string) ($out['text'] ?? ''));

    $ok("turn [{$label}] answered", $text !== '', sprintf('%.1fs', $secs));
    if ($text === '') { continue; }

    /* Devanagari, not roman. This is the one quality rule the small model
       needs told — it writes good Nepali in Devanagari and poor Nepali in
       roman letters, and the question above is Devanagari, so the reply
       must be too. */
    $deva  = preg_match_all('/[\x{0900}-\x{097F}]/u', $text);
    $latin = preg_match_all('/[A-Za-z]/u', $text);
    $ok("turn [{$label}] in Devanagari", $deva > $latin,
        "{$deva} Devanagari vs {$latin} Latin characters");

    /* Two or three short lines is the house style; the prompt says so and
       ai_local_max_tokens enforces the ceiling. */
    $ok("turn [{$label}] stays short", mb_strlen($text) <= 900,
        mb_strlen($text) . ' characters');

    echo '        ', str_replace("\n", "\n        ", mb_substr($text, 0, 240)), "\n\n";
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
