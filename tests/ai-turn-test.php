<?php
/** Offline regression tests: no DB, model calls, tickets or messages. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }
define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/aiturn.php';
$checks = 0;
function check(bool $condition, string $label): void {
    global $checks; $checks++;
    if (!$condition) { throw new RuntimeException($label); }
    echo "PASS $label\n";
}
function calls(array $inputs): array {
    $calls = [];
    foreach ($inputs as $i => $args) { $calls[] = ['id' => 'call' . $i, 'name' => 'issue_ticket', 'input' => $args]; }
    return ['text' => '', 'calls' => $calls, 'blocks' => []];
}
$executed = 0; $round = 0;
$answer = AiTurn::run(static function () use (&$round) { return $round++ === 0 ? calls([['pnr'=>'A'],['pnr'=>'B'],['pnr'=>'C']]) : null; },
    static function ($name, $args) use (&$executed) { $executed++; return ['ok'=>true,'say'=>'Ticket created','data'=>['pnr'=>$args['pnr']],'media'=>'ticket.png']; },
    '', [], [['name'=>'issue_ticket']], 2, microtime(true)+5);
check($executed === 2, 'actual calls, including calls in one batch, respect budget');
check(str_contains($answer['text'], 'pnr: A') && str_contains($answer['text'], 'pnr: B'), 'provider failure preserves both completed results');
check(str_contains($answer['text'], 'अझै गरिएको छैन'), 'unexecuted actions are explicitly reported');
check($answer['media'] === 'ticket.png', 'ticket attachment survives provider failure');

$executed = 0; $round = 0;
$answer = AiTurn::run(static function () use (&$round) { return $round++ < 2 ? calls([['a'=>1,'b'=>2],['b'=>2,'a'=>1]]) : ['text'=>'Done','calls'=>[]]; },
    static function () use (&$executed) { $executed++; return ['ok'=>true,'say'=>'Done','data'=>[],'media'=>null]; }, '', [], [['name'=>'issue_ticket']], 4, microtime(true)+5);
check($executed === 1, 'identical action repeated within and between rounds executes once');

$executed = 0;
$answer = AiTurn::run(static fn()=>calls([[]]), static function () use (&$executed) { $executed++; }, '', [], [], 6, microtime(true)-1);
check($executed === 0 && $answer === null, 'expired deadline prevents model and action calls');

$executed = 0; $round = 0;
$answer = AiTurn::run(static function () use (&$round) {
    if ($round++ === 0) { return calls([[]]); }
    throw new RuntimeException('private transport detail');
}, static function () use (&$executed) { $executed++; return ['ok'=>true,'say'=>'Booking confirmed','data'=>['pnr'=>'SHG-2026-1','internal_secret'=>'hidden'],'media'=>null]; }, '', [], [['name'=>'issue_ticket']], 3, microtime(true)+5);
check($executed === 1 && str_contains($answer['text'],'SHG-2026-1'), 'exception after action still returns the completed booking');
check(!str_contains($answer['text'],'hidden') && !str_contains($answer['text'],'private transport'), 'fallback excludes arbitrary data and internal exceptions');

$round=0; $executed=0;
$answer = AiTurn::run(static function () use (&$round) { return $round++ < 2 ? calls([[]]) : null; },
    static function () use (&$executed) { $executed++; throw new RuntimeException('uncertain mutation'); }, '', [], [['name'=>'issue_ticket']], 3, microtime(true)+5);
check($executed === 1 && str_contains($answer['text'],'जाँच'), 'uncertain mutation is never retried during the turn');

// 23 Sep 2026: a provider failure after READ-only tools hands the reply back to the
// caller (null) instead of a raw list of English tool notes.
$round = 0; $executed = 0;
$answer = AiTurn::run(static function () use (&$round) {
    return $round++ === 0 ? ['text' => '', 'calls' => [['id' => 'r1', 'name' => 'my_tickets', 'input' => []]], 'blocks' => []] : null;
}, static function () use (&$executed) { $executed++; return ['ok'=>true,'say'=>'This number has no booking with us yet.','data'=>['bookings'=>[]],'media'=>null]; },
    '', [], [['name'=>'my_tickets']], 3, microtime(true)+5);
check($executed === 1 && $answer === null, 'provider failure after read-only tools returns null, not tool notes');

$round = 0;
$answer = AiTurn::run(static function () use (&$round) {
    return $round++ === 0 ? ['text' => '', 'calls' => [['id' => 'r1', 'name' => 'knowledge_lookup', 'input' => ['query'=>'x']],
                                                         ['id' => 'w1', 'name' => 'cancel_ticket', 'input' => ['pnr'=>'SHG-X']]], 'blocks' => []] : null;
}, static fn($n, $a) => ['ok'=>true,'say'=>'done','data'=>['pnr'=>$a['pnr'] ?? ''],'media'=>null],
    '', [], [['name'=>'knowledge_lookup'],['name'=>'cancel_ticket']], 3, microtime(true)+5);
check($answer !== null && str_contains($answer['text'], 'SHG-X'), 'a write in the same turn is still reported after a provider failure');
echo "$checks checks passed\n";
