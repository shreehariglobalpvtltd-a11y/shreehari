<?php
/**
 * =====================================================================
 *  settings-json-test.php — 4 Sep 2026: stype=json rows survive the
 *  admin form. Before the fix, Settings::setMany() handed the textarea
 *  STRING to set(..., 'json'), which json_encode()d it again, so every
 *  Save wrapped the row in one more layer of escaping until getArray()
 *  gave up and returned the code default (that is what happened to
 *  cabin_pricing / refund_slabs / loyalty_tiers / travel_checklist on live).
 *
 *  Run: php -c .claude/php-dev.ini tests/settings-json-test.php
 *  Uses a throw-away key and deletes it afterwards.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok   {$l}\n"; }
    else     { $FAIL++; echo "  FAIL {$l}" . ($extra !== '' ? "  ({$extra})" : '') . "\n"; }
}
function rawRow(string $k): ?string {
    $r = Database::fetch('SELECT svalue FROM settings WHERE skey = :k', ['k' => $k]);
    return $r === null ? null : (string) $r['svalue'];
}

const KEY = '_test_json_round_trip';
Database::query('DELETE FROM settings WHERE skey = :k', ['k' => KEY]);

echo "A. array value stores clean JSON\n";
Settings::set(KEY, ['a' => 1, 'b' => ['x', 'y']], 'json', 'test', false);
check('raw row is the object itself', rawRow(KEY) === '{"a":1,"b":["x","y"]}', (string) rawRow(KEY));
check('getArray decodes it', Settings::getArray(KEY) === ['a' => 1, 'b' => ['x', 'y']]);

echo "B. the admin form posts a STRING for a json row (setMany path)\n";
Settings::flush();
Settings::setMany([KEY => '{"a": 2, "b": ["z"], "n": "नेपाली"}']);
Settings::flush();
check('stored once, not wrapped in quotes', rawRow(KEY) === '{"a":2,"b":["z"],"n":"नेपाली"}', (string) rawRow(KEY));
check('getArray still decodes after a form save', Settings::getArray(KEY)['b'] === ['z']);
check('unicode kept unescaped', (Settings::getArray(KEY)['n'] ?? '') === 'नेपाली');

echo "C. ten consecutive form saves add zero layers\n";
for ($i = 0; $i < 10; $i++) {
    Settings::flush();
    Settings::setMany([KEY => rawRow(KEY)]);
}
Settings::flush();
check('raw length unchanged after 10 saves', strlen((string) rawRow(KEY)) === strlen('{"a":2,"b":["z"],"n":"नेपाली"}'), 'len=' . strlen((string) rawRow(KEY)));
check('backslash count is zero', substr_count((string) rawRow(KEY), '\\') === 0);

echo "D. an already-corrupted row is repaired on the next save\n";
$corrupt = json_encode(json_encode(json_encode(['fixed' => true])));   // 3 layers deep
Database::query('UPDATE settings SET svalue = :v WHERE skey = :k', ['v' => $corrupt, 'k' => KEY]);
Settings::flush();
check('corrupted row reads as default before the save', Settings::getArray(KEY, ['dflt' => 1]) === ['dflt' => 1]);
Settings::setMany([KEY => (string) rawRow(KEY)]);   // the admin just pressed Save with the mangled text
Settings::flush();
check('row is peeled back to the object', rawRow(KEY) === '{"fixed":true}', (string) rawRow(KEY));
check('getArray reads it', Settings::getArray(KEY) === ['fixed' => true]);

echo "E. invalid JSON is refused and the old value survives\n";
$before = rawRow(KEY);
$threw = false;
try {
    Settings::flush();
    Settings::setMany([KEY => '{not json']);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
Settings::flush();
check('InvalidArgumentException thrown', $threw);
check('previous value untouched', rawRow(KEY) === $before);

echo "F. decodeJsonLayers helper edge cases\n";
check('plain string value stays a string', Settings::decodeJsonLayers('"abc"') === 'abc');
check('empty string becomes []', Settings::decodeJsonLayers('') === []);
check('number stays a number', Settings::decodeJsonLayers('42') === 42);

Database::query('DELETE FROM settings WHERE skey = :k', ['k' => KEY]);
echo "\n{$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
