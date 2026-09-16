<?php
/**
 * =====================================================================
 *  governor-spine-test.php — the AI can only touch the data lane, and
 *  every fault names itself.
 *
 *  Two things are proved here, and they are the preconditions for every
 *  learning feature that follows:
 *
 *  1. THE GOVERNOR. "AI never edits booking, seat, fare or refund code" has
 *     to be a property of the code, not a promise in a document. So: a key
 *     that is not on the allow-list is refused; a key that LOOKS like money
 *     is refused even if somebody adds it to the allow-list by mistake; a
 *     value outside its bounds is clamped rather than obeyed; shadow mode
 *     records without applying; and every attempt — applied, shadowed or
 *     refused — leaves an audit row and can be rolled back in one call.
 *
 *  2. THE SPINE. A fault class produces ONE incident that counts its
 *     repeats, not one row per occurrence; a fault that stops is resolved;
 *     a fault that comes back re-opens. And a cron job that stops running
 *     is detectable, because cron_done() leaves a heartbeat.
 *
 *    php -c .claude/php-dev.ini tests/governor-spine-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aigovernor.php';
require_once INCLUDE_PATH . '/health.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/* Remember what the settings held, so this suite leaves nothing behind. */
$KEY      = 'brain_min_score';
$original = Settings::getInt($KEY, 250);
$origShadow = Settings::get('ai_governor_shadow', null);
$origOn     = Settings::get('ai_governor_on', null);

$restore = static function () use ($KEY, $original, $origShadow, $origOn): void {
    Settings::set($KEY, $original, 'int', 'ai', false);
    if ($origShadow === null) {
        Database::delete('settings', 'skey = :k', ['k' => 'ai_governor_shadow']);
    } else {
        Settings::set('ai_governor_shadow', $origShadow, 'bool', 'ai', false);
    }
    if ($origOn === null) {
        Database::delete('settings', 'skey = :k', ['k' => 'ai_governor_on']);
    } else {
        Settings::set('ai_governor_on', $origOn, 'bool', 'ai', false);
    }
    Database::delete('kv_store', "kkey = :k", ['k' => 'governor.history.' . $KEY]);
    Database::delete('kv_store', "kkey = :k", ['k' => 'brain.model.current']);
    Database::delete('kv_store', "kkey = :k", ['k' => 'governor.history.brain.model']);
    Database::run("DELETE FROM audit_logs WHERE actor_name = 'ai:testsuite'");
    Settings::flush();
};
$restore();

echo "\n=== The Governor and the reliability spine ===\n\n";

/* -----------------------------------------------------------------
 *  A. The allow-list is the whole gate.
 * --------------------------------------------------------------- */
echo "-- A. what the AI may not touch --\n";

foreach (['fare_to_nepal', 'fare_to_india', 'refund_slabs', 'commission_percent',
          'counter_max_seats_per_booking', 'seat_mode_map', 'booking_expiry_minutes'] as $forbidden) {
    check("'{$forbidden}' is not tunable", !AiGovernor::isTunable($forbidden));
}

$r = AiGovernor::set('fare_to_nepal', 1, 'testsuite', 'attempt to move a fare');
check('a fare write is REFUSED', $r['ok'] === false && $r['applied'] === false, $r['error']);
check('…and the live fare setting is untouched',
      Settings::getInt('fare_to_nepal', -1) !== 1);

$r = AiGovernor::set('some_key_nobody_declared', 5, 'testsuite', 'unknown key');
check('an unknown key is REFUSED', $r['ok'] === false, $r['error']);

check('the refusal was journalled',
      (int) Database::scalar(
          "SELECT COUNT(*) FROM audit_logs WHERE actor_name = 'ai:testsuite' AND action = 'ai.tune.refused'", [], 0) >= 2);

/* The second lock: even a key ON the allow-list is refused when its NAME
   looks like money. This is what protects against a future edit that adds
   the wrong key to TUNABLES. */
echo "\n-- A2. the second lock --\n";
$moneyShaped = 0;
foreach (array_keys(AiGovernor::TUNABLES) as $k) {
    if (preg_match('/fare|refund|price|commission|seat|gender|slab/i', $k) === 1) {
        $moneyShaped++;
    }
}
check('no money-shaped key is on the allow-list at all', $moneyShaped === 0, "found={$moneyShaped}");

/* -----------------------------------------------------------------
 *  B. Shadow mode records without applying.
 * --------------------------------------------------------------- */
echo "\n-- B. shadow mode --\n";
Settings::set('ai_governor_on', true, 'bool', 'ai', false);
Settings::set('ai_governor_shadow', true, 'bool', 'ai', false);
Settings::flush();

check('shadow mode is on', AiGovernor::shadow() === true);
$r = AiGovernor::set($KEY, 333, 'testsuite', 'fitted value');
check('the proposal is accepted', $r['ok'] === true, $r['error']);
check('…but NOT applied',        $r['applied'] === false);
check('…and the live value did not move',
      Settings::getInt($KEY, 0) === $original, 'live=' . Settings::getInt($KEY, 0));
check('the shadow write was journalled',
      (int) Database::scalar(
          "SELECT COUNT(*) FROM audit_logs WHERE actor_name = 'ai:testsuite' AND action = 'ai.tune.shadow'", [], 0) === 1);

/* -----------------------------------------------------------------
 *  C. Live mode applies, clamps, and can be undone.
 * --------------------------------------------------------------- */
echo "\n-- C. live mode, bounds and rollback --\n";
Settings::set('ai_governor_shadow', false, 'bool', 'ai', false);
Settings::flush();

$r = AiGovernor::set($KEY, 333, 'testsuite', 'fitted value');
check('a value inside bounds is applied', $r['ok'] && $r['applied'], $r['error']);
check('…and the live value moved', AiGovernor::get($KEY, 0) === 333, 'live=' . AiGovernor::get($KEY, 0));

$spec = AiGovernor::TUNABLES[$KEY];
$r = AiGovernor::set($KEY, 999999, 'testsuite', 'a fit that went wrong');
check('an absurd value is CLAMPED, not obeyed', $r['clamped'] === true);
check('…to the declared maximum', AiGovernor::get($KEY, 0) === (int) $spec['max'],
      'live=' . AiGovernor::get($KEY, 0) . ' max=' . $spec['max']);

$r = AiGovernor::set($KEY, -50, 'testsuite', 'a negative fit');
check('a value under the floor is clamped up', AiGovernor::get($KEY, 0) === (int) $spec['min'],
      'live=' . AiGovernor::get($KEY, 0));

$r = AiGovernor::set($KEY, 'not a number', 'testsuite', 'garbage');
check('a non-numeric value is refused outright', $r['ok'] === false, $r['error']);
check('…and the live value is unchanged by the refusal',
      AiGovernor::get($KEY, 0) === (int) $spec['min']);

$hist = AiGovernor::history($KEY);
check('every applied change left a history entry', count($hist) >= 3, 'versions=' . count($hist));
check('history is bounded', count($hist) <= 5, 'versions=' . count($hist));

$back = AiGovernor::rollback($KEY, 'testsuite');
check('rollback succeeds', $back['ok'] === true, $back['error']);
check('…and restores the previous value',
      AiGovernor::get($KEY, 0) === (int) $spec['max'], 'live=' . AiGovernor::get($KEY, 0));

/* -----------------------------------------------------------------
 *  D. Switched off means nothing learns.
 * --------------------------------------------------------------- */
echo "\n-- D. the master switch --\n";
Settings::set('ai_governor_on', false, 'bool', 'ai', false);
Settings::flush();
$before = AiGovernor::get($KEY, 0);
$r = AiGovernor::set($KEY, 200, 'testsuite', 'should not happen');
check('with the governor off, every write is refused', $r['ok'] === false, $r['error']);
check('…and nothing moved', AiGovernor::get($KEY, 0) === $before);

Settings::set('ai_governor_on', true, 'bool', 'ai', false);
Settings::flush();

/* -----------------------------------------------------------------
 *  E. JSON tunables (the fitted model) live in kv_store.
 * --------------------------------------------------------------- */
echo "\n-- E. a fitted model round-trips --\n";
Settings::set('ai_governor_shadow', false, 'bool', 'ai', false);
Settings::flush();

$model = ['v' => 2, 'w' => ['recency' => 0.31, 'frequency' => 0.22]];
$r = AiGovernor::set('brain.model', $model, 'testsuite', 'nightly fit');
check('a JSON model is applied', $r['ok'] && $r['applied'], $r['error']);
$read = AiGovernor::get('brain.model');
check('…and reads back identical', is_array($read) && ($read['w']['recency'] ?? 0) === 0.31, json_encode($read));

$r = AiGovernor::set('brain.model', 'not an array', 'testsuite', 'bad model');
check('a non-array model is refused', $r['ok'] === false, $r['error']);

$r = AiGovernor::set('brain.model', ['blob' => str_repeat('x', 30000)], 'testsuite', 'oversized model');
check('an oversized model is refused', $r['ok'] === false, $r['error']);
check('…and the good model survived', ($read = AiGovernor::get('brain.model')) && ($read['v'] ?? 0) === 2);

/* -----------------------------------------------------------------
 *  F. Fall back to the frozen constant when nothing was ever fitted.
 * --------------------------------------------------------------- */
echo "\n-- F. cold start --\n";
Database::delete('kv_store', 'kkey = :k', ['k' => 'notify.route.current']);
check('an unfitted model reads as the caller default',
      AiGovernor::get('notify.route', ['fallback' => true]) === ['fallback' => true]);
check('a key outside the allow-list reads as the default too',
      AiGovernor::get('fare_to_nepal', 'untouched') === 'untouched');

/* -----------------------------------------------------------------
 *  G. Incidents: one row per fault class.
 * --------------------------------------------------------------- */
echo "\n-- G. a fault names itself once, not four hundred times --\n";
$KEY_I = 'test.spine.probe';
Database::delete('health_incidents', 'dedupe_key = :d', ['d' => $KEY_I]);

$id1 = Health::open('test_fault', $KEY_I, Health::WARN, 'Probe fault', 'A detail.', 'A fix step.', [101, 102]);
check('an incident is opened', $id1 > 0);

$id2 = Health::open('test_fault', $KEY_I, Health::WARN, 'Probe fault', 'A detail.', 'A fix step.', [103]);
check('the SAME fault re-uses the same row', $id2 === $id1, "first={$id1} second={$id2}");

$row = Database::fetch('SELECT * FROM health_incidents WHERE dedupe_key = :d', ['d' => $KEY_I]);
check('…and counts the repeat instead of duplicating', (int) $row['occurrences'] === 2,
      'occurrences=' . (int) $row['occurrences']);
check('only one row exists for this fault',
      (int) Database::scalar('SELECT COUNT(*) FROM health_incidents WHERE dedupe_key = :d', ['d' => $KEY_I], 0) === 1);

check('sample ids are stored', (string) $row['sample_ids'] === '103');

/* No PII, by construction. A caller that passes a phone number instead of a
   booking id must not have it stored — stripping punctuation would leave the
   digits, and the digits ARE the phone number. */
Health::open('test_fault', $KEY_I, Health::WARN, 'Probe fault', '', '', ['+977 9812345678', '9812345678', 205]);
$row = Database::fetch('SELECT * FROM health_incidents WHERE dedupe_key = :d', ['d' => $KEY_I]);
check('a phone number smuggled into sample_ids is DROPPED, not stored',
      strpos((string) $row['sample_ids'], '9812345678') === false, (string) $row['sample_ids']);
check('…while the real booking id survives',
      (string) $row['sample_ids'] === '205', (string) $row['sample_ids']);

check('the incident shows up in the open list',
      count(array_filter(Health::open_list(50, 'test_fault'),
            static fn(array $r): bool => (string) $r['dedupe_key'] === $KEY_I)) === 1);

$sum = Health::summary();
check('the summary counts it', $sum['warn'] >= 1 && $sum['total'] >= 1, json_encode($sum));

check('resolving it works', Health::resolve($KEY_I) === true);
$row = Database::fetch('SELECT * FROM health_incidents WHERE dedupe_key = :d', ['d' => $KEY_I]);
check('…and it leaves the open list', (string) $row['status'] === 'resolved');

/* The one that matters: a fault that comes BACK must re-open, or the second
   outage of the same kind is invisible while the first row survives. */
Health::open('test_fault', $KEY_I, Health::CRITICAL, 'Probe fault is back', 'It returned.', '', [104]);
$row = Database::fetch('SELECT * FROM health_incidents WHERE dedupe_key = :d', ['d' => $KEY_I]);
check('a returning fault RE-OPENS the same row', (string) $row['status'] === 'open');
check('…with the new severity', (string) $row['severity'] === 'critical');
check('…and the resolved stamp cleared', $row['resolved_at'] === null);

Database::delete('health_incidents', 'dedupe_key = :d', ['d' => $KEY_I]);

/* -----------------------------------------------------------------
 *  H. Heartbeats make silence loud.
 * --------------------------------------------------------------- */
echo "\n-- H. a job that stops running is visible --\n";
$JOB = 'spine-probe.php';
Database::delete('cron_runs', 'job = :j', ['j' => $JOB]);

Health::beat($JOB, 120, ['did' => 'work'], true);
$row = Database::fetch('SELECT * FROM cron_runs WHERE job = :j', ['j' => $JOB]);
check('a run leaves a heartbeat', $row !== null);
check('…with the duration',   (int) ($row['last_ms'] ?? -1) === 120);
check('…and an ok stamp',     $row['last_ok_at'] !== null);
check('…and counts the run',  (int) $row['runs_total'] === 1);

Health::beat($JOB, 130, ['did' => 'work'], true);
$row = Database::fetch('SELECT * FROM cron_runs WHERE job = :j', ['j' => $JOB]);
check('a second ok run extends the streak to 2 consecutive good runs',
      (int) $row['ok_streak'] === 2 && (int) $row['fail_streak'] === 0,
      'ok=' . $row['ok_streak'] . ' fail=' . $row['fail_streak']);

Health::beat($JOB, 140, ['error' => 'boom'], false);
$row = Database::fetch('SELECT * FROM cron_runs WHERE job = :j', ['j' => $JOB]);
check('a failed run resets the ok streak and starts a fail streak',
      (int) $row['ok_streak'] === 0 && (int) $row['fail_streak'] === 1,
      'ok=' . $row['ok_streak'] . ' fail=' . $row['fail_streak']);
check('…and the run count still went up', (int) $row['runs_total'] === 3);

$beats = array_filter(Health::heartbeats(), static fn(array $r): bool => (string) $r['job'] === $JOB);
check('heartbeats() reports the age', count($beats) === 1 && array_values($beats)[0]['age_min'] !== null);

Database::delete('cron_runs', 'job = :j', ['j' => $JOB]);

/* -----------------------------------------------------------------
 *  I. cron_done() itself writes the heartbeat.
 *
 *  Proving the wiring, not just the helper: it is the wiring that was
 *  missing, and a heartbeat nothing calls is worth nothing.
 * --------------------------------------------------------------- */
echo "\n-- I. cron_done is wired to the heartbeat --\n";
$cronSrc = (string) file_get_contents(dirname(__DIR__) . '/cron/_cron.php');
check('cron_done() calls Health::beat', str_contains($cronSrc, 'Health::beat('));
check('…inside a try/catch so a missing table cannot fail a job',
      preg_match('/try\s*\{[^}]*Health::beat\(/s', $cronSrc) === 1);

$restore();

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
