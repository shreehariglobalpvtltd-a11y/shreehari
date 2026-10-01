<?php
/**
 * =====================================================================
 *  brain-bench.php — judge a local model on the REAL path, not a toy one.
 *
 *  WHY THIS EXISTS
 *  ---------------
 *  On 26 Sep 2026 a small model was benchmarked against a 150-token
 *  system prompt and looked superb: 4.2s answers, correct Devanagari,
 *  correct tool calls, never rambling. Put behind the actual assistant —
 *  a 2 600-token briefing and six tool schemas — the same model fell
 *  apart: it repeated one clause eleven times and said the capital of
 *  Nepal was Rupaidiha.
 *
 *  The short prompt was flattering it. A model that has to hold the
 *  company briefing AND the tool schemas AND the question has far less
 *  left over than a bench question suggests, and small models run out
 *  first. So the only benchmark worth anything runs the real prompt,
 *  the real tools and the real agent loop — which is what this does.
 *
 *  It goes through AiAgent::handleWeb(), so what it measures is exactly
 *  what a visitor would get.
 *
 *  SAFETY: run it on a TEST copy. It reads ai_local_url from that
 *  copy's settings, so point that at a candidate server on its own port
 *  and leave the live one alone.
 *
 *      # candidate on 8082, live untouched on 8081
 *      mysql shari_test -e "UPDATE settings SET svalue='http://127.0.0.1:8082' WHERE skey='ai_local_url'"
 *      php tests/brain-bench.php "gemma-3-4b"
 *
 *  Always exits 0 — it is a measurement, not a gate.
 * =====================================================================
 */

declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once INCLUDE_PATH . '/aitools.php';
require_once INCLUDE_PATH . '/aiagent.php';

$label = $argv[1] ?? 'model';

if (!Settings::getBool('ai_local_on', false)) {
    echo "ai_local_on is off — nothing to bench\n";
    exit(0);
}
$url = Settings::getString('ai_local_url', '');
echo "\n=== {$label} ===\n";
echo "server: {$url}\n\n";

$ctx = [
    'role' => 'customer', 'admin' => null, 'adminId' => 0, 'scopeAdminId' => null,
    'name' => '', 'phone' => '', 'channel' => 'web', 'userId' => 0, 'stageKey' => '',
];

/* Five questions that between them catch every failure seen so far:
   a fact it must take from a tool, an open question it can ramble on,
   one that needs a tool call, one in Hindi, and one it should refuse. */
$cases = [
    'fact'    => 'सुरतबाट रुपैडिहाको भाडा कति हो?',
    'open'    => 'नमस्ते, बस कहाँबाट कहाँ जान्छ?',
    'ticket'  => 'मेरो टिकट SHG-2026-00436 को अवस्था के छ?',
    'hindi'   => 'सूरत से नेपाल की बस कितने बजे चलती है?',
    'offtopic'=> 'नेपालको राजधानी कुन हो?',
];

$totals = [];
foreach ($cases as $name => $q) {
    AiAgent::forget('bench-' . $name);
    $t0  = microtime(true);
    $out = AiAgent::handleWeb($ctx, $q, 'ne');
    $secs = microtime(true) - $t0;
    $text = trim((string) ($out['text'] ?? ''));

    /* The ways a small model fails here, each measurable. */
    $deva  = preg_match_all('/[\x{0900}-\x{097F}]/u', $text);
    $latin = preg_match_all('/[A-Za-z]/u', $text);
    $flags = [];
    if ($text === '')              { $flags[] = 'EMPTY'; }
    if ($deva > 0 && $latin > $deva) { $flags[] = 'NOT-DEVANAGARI'; }
    /* PARROT — added 26 Sep after this bench scored garbage as "ok".
       A 1.7B handed a small briefing answered every question by saying
       the question back: asked "how much is the fare" it replied "how
       much is the fare". Short, Devanagari, no repetition, no cap — it
       passed every other check here and was completely useless. Any
       reply that is mostly the question is not an answer. */
    $norm = static fn(string $s): string => trim(preg_replace('/[\s\p{P}]+/u', ' ', $s) ?? $s);
    $qn   = $norm($q);
    $an   = $norm($text);
    if ($an !== '' && (str_contains($an, $qn) || similar_text($qn, $an) / max(1, mb_strlen($qn)) > 0.8)) {
        $flags[] = 'PARROT';
    }
    /* Repetition: the degeneration loop. Any 25-character run that
       appears three times or more is a model chewing its own tail. */
    if (mb_strlen($text) > 80) {
        $probe = mb_substr($text, 20, 25);
        if ($probe !== '' && mb_substr_count($text, $probe) >= 3) { $flags[] = 'REPEATS'; }
    }
    if (mb_strlen($text) > 700)    { $flags[] = 'TOO-LONG'; }

    $totals[] = $secs;
    printf("  %-9s %6.1fs  %-28s %s\n", $name, $secs,
        $flags === [] ? 'ok' : implode(' ', $flags),
        mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 110));
}

printf("\n  AVERAGE %.1fs over %d questions\n", array_sum($totals) / max(1, count($totals)), count($totals));
echo "\n  A usable model: no EMPTY, no REPEATS, Devanagari for Nepali,\n";
echo "  and an average a person will wait for.\n";
exit(0);
