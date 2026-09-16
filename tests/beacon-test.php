<?php
/**
 * =====================================================================
 *  beacon-test.php — the product beacon collects behaviour, not people.
 *
 *  api/events.php is the one endpoint in this system that is open to the
 *  public, called on every view change, and writes a row each time. That
 *  combination is exactly how a well-meaning analytics endpoint turns into
 *  an unreviewable log of customers' phone numbers and a free way for a
 *  stranger to fill the disk. So the guarantees are tested over real HTTP,
 *  where they actually have to hold:
 *
 *    A. only allow-listed EVENT NAMES are stored,
 *    B. only allow-listed PROP KEYS survive, as short scalars,
 *    C. a phone number, an email or a PNR is REDACTED even inside an
 *       allow-listed key — a search box is where people type their own
 *       number,
 *    D. the endpoint always answers 204 and never leaks a reason,
 *    E. GET does nothing,
 *    F. the setting can switch the whole thing off.
 *
 *    1. dev server on :8899
 *    2. php -c .claude/php-dev.ini tests/beacon-test.php
 *
 *  Deletes every row it writes.
 * =====================================================================
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (!function_exists('curl_init')) {
    echo "curl extension not loaded — re-run with -d extension=php_curl.dll\n";
    exit(1);
}

/* Defaults to the documented dev server, but overridable: several
   worktrees share this machine and only one of them can hold :8899.
       SHG_TEST_BASE=http://localhost:8898 php … tests/beacon-test.php */
define('BASE', getenv('SHG_TEST_BASE') ?: 'http://localhost:8899');
/** A visit id nothing real will collide with, so cleanup is exact. */
const VID = 'beac04beac04beac04beac04beac0401';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

/** POST a beacon payload; returns the HTTP status. */
function post(array $payload): int
{
    $ch = curl_init(BASE . '/api/events.php');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/** The newest row this suite wrote, if any. */
function lastRow(): ?array
{
    return Database::fetch(
        'SELECT * FROM app_events WHERE session_key = :s ORDER BY id DESC LIMIT 1',
        ['s' => VID]
    );
}

function wipe(): void
{
    Database::run('DELETE FROM app_events WHERE session_key = :s', ['s' => VID]);
}

/* The beacon budget is per-IP and generous (240/min); a previous suite in
   the same battery run can still have spent part of it. */
Database::run("DELETE FROM rate_limits WHERE bucket = 'beacon'");
wipe();

echo "\n=== The product beacon ===\n\n";

/* -----------------------------------------------------------------
 *  A. Only real events are stored.
 * --------------------------------------------------------------- */
echo "-- A. the event allow-list --\n";

$code = post(['name' => 'view', 'sid' => VID, 'path' => '#/results', 'props' => ['from' => 'home', 'to' => 'results']]);
check('a known event answers 204', $code === 204, "http={$code}");
$row = lastRow();
check('…and is stored', $row !== null && (string) $row['name'] === 'view');
check('…with its path',  $row !== null && (string) $row['path'] === '#/results');

$code = post(['name' => 'definitely_not_an_event', 'sid' => VID, 'props' => ['to' => 'x']]);
check('an unknown event still answers 204 (no probing signal)', $code === 204, "http={$code}");
check('…but stores nothing',
      (int) Database::scalar("SELECT COUNT(*) FROM app_events WHERE session_key = :s AND name = 'definitely_not_an_event'",
            ['s' => VID], 0) === 0);

/* -----------------------------------------------------------------
 *  B. Only allow-listed props survive, and only as scalars.
 * --------------------------------------------------------------- */
echo "\n-- B. the prop allow-list --\n";
wipe();
post([
    'name'  => 'search',
    'sid'   => VID,
    'props' => [
        'direction'  => 'toNepal',
        'date_offset'=> 3,
        'ok'         => true,
        'passenger'  => 'Ram Bahadur',            // not allow-listed
        'notes'      => ['deep' => 'structure'],  // nested payloads are dropped
    ],
]);
$row   = lastRow();
$props = json_decode((string) ($row['props'] ?? '{}'), true) ?: [];

check('an allow-listed string survives',  ($props['direction'] ?? '') === 'toNepal');
check('an allow-listed number survives',  ($props['date_offset'] ?? null) === 3);
check('an allow-listed boolean survives', ($props['ok'] ?? null) === true);
check('a NON allow-listed key is dropped', !array_key_exists('passenger', $props), json_encode($props));
check('a nested payload is dropped',       !array_key_exists('notes', $props), json_encode($props));

/* -----------------------------------------------------------------
 *  C. Redaction happens even inside an allow-listed key.
 *
 *  This is the one that matters. 'reason' is allow-listed and free-ish
 *  text, and a customer typing their own mobile into the search box is
 *  not hypothetical — it is the most common thing that happens to a
 *  search box on a booking site.
 * --------------------------------------------------------------- */
echo "\n-- C. redaction --\n";
wipe();
post(['name' => 'search_empty', 'sid' => VID,
      'props' => ['reason' => 'called 9812345678 already']]);
$props = json_decode((string) (lastRow()['props'] ?? '{}'), true) ?: [];
check('a phone number inside an allowed prop is redacted',
      strpos((string) ($props['reason'] ?? ''), '9812345678') === false, json_encode($props));

wipe();
post(['name' => 'search_empty', 'sid' => VID,
      'props' => ['reason' => 'mailed ram@example.com']]);
$props = json_decode((string) (lastRow()['props'] ?? '{}'), true) ?: [];
check('an email address is redacted',
      strpos((string) ($props['reason'] ?? ''), 'ram@example.com') === false, json_encode($props));

wipe();
post(['name' => 'view', 'sid' => VID, 'path' => '#/ticket/SHG-2026-00042',
      'props' => ['to' => 'status']]);
$row = lastRow();
check('a PNR in the path is redacted',
      strpos((string) ($row['path'] ?? ''), '00042') === false, (string) ($row['path'] ?? ''));

/* -----------------------------------------------------------------
 *  D. The visit key is a visit, not a person.
 * --------------------------------------------------------------- */
echo "\n-- D. the visit key --\n";
wipe();
post(['name' => 'view', 'sid' => 'not-hex-and-far-too-long-to-be-a-visit-id!!', 'props' => ['to' => 'home']]);
$row = Database::fetch("SELECT * FROM app_events WHERE name = 'view' ORDER BY id DESC LIMIT 1");
check('a junk visit id is normalised to 32 hex chars, not stored raw',
      $row !== null && preg_match('/^[a-f0-9]{32}$/', (string) $row['session_key']) === 1,
      (string) ($row['session_key'] ?? '(null)'));
Database::run('DELETE FROM app_events WHERE id = :i', ['i' => (int) $row['id']]);

check('an anonymous beacon has no user_id', $row !== null && $row['user_id'] === null);

/* -----------------------------------------------------------------
 *  E. GET does nothing, and oversized bodies are refused.
 * --------------------------------------------------------------- */
echo "\n-- E. the shape of the door --\n";
wipe();
$ch = curl_init(BASE . '/api/events.php?name=view');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);
$getCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
check('GET answers 204 and writes nothing', $getCode === 204 && lastRow() === null, "http={$getCode}");

$code = post(['name' => 'view', 'sid' => VID, 'props' => ['reason' => str_repeat('x', 6000)]]);
check('an oversized body answers 204', $code === 204, "http={$code}");
check('…and writes nothing', lastRow() === null);

/* -----------------------------------------------------------------
 *  F. It can be switched off entirely.
 * --------------------------------------------------------------- */
echo "\n-- F. the off switch --\n";
wipe();
$was = Settings::get('events_beacon_on', null);
Settings::set('events_beacon_on', false, 'bool', 'general', false);
Settings::flush();

$code = post(['name' => 'view', 'sid' => VID, 'props' => ['to' => 'home']]);
check('with the beacon off it still answers 204', $code === 204, "http={$code}");
check('…and stores nothing at all', lastRow() === null);

if ($was === null) {
    Database::delete('settings', 'skey = :k', ['k' => 'events_beacon_on']);
} else {
    Settings::set('events_beacon_on', $was, 'bool', 'general', false);
}
Settings::flush();

wipe();
Database::run("DELETE FROM rate_limits WHERE bucket = 'beacon'");

echo "\n" . ($FAIL === 0
    ? "\033[32m  {$PASS} passed, 0 failed\033[0m\n\n"
    : "\033[31m  {$PASS} passed, {$FAIL} failed\033[0m\n\n");
exit($FAIL === 0 ? 0 : 1);
