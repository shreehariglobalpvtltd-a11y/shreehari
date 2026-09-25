<?php
/**
 * ai-memory-test.php — memory that follows the person (24 Sep 2026).
 *
 *   A. The switch: with ai_memory_on off nothing is written and the brief
 *      is ''. The key is a hash — the number never appears — and 9198…
 *      and 98… are the same person.
 *   B. remember() + recall() + touchProfile(): trips count up, the brief
 *      reads like a register note and carries no digits of the phone.
 *   C. The register writes the memory: booking.created / .cancelled events
 *      become episodes without any caller code.
 *   D. forget() erases the person; a person keeps at most 120 episodes.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/aimemory.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($d !== '' ? " — $d" : '') . "\n"; }
}

const PHONE = '919870007711';
$was = Settings::getBool('ai_memory_on', false);
$restore = static function () use ($was): void { Settings::set('ai_memory_on', $was ? '1' : '0', 'bool', 'ai'); Settings::flush(); };

echo "-- A. the switch and the key --\n";
Settings::set('ai_memory_on', '0', 'bool', 'ai'); Settings::flush();
AiMemory::forget(PHONE);
check('off: remember() writes nothing', AiMemory::remember(PHONE, 'note', 'should not land') === false);
check('off: the brief is empty', AiMemory::brief(PHONE) === '');
$k1 = AiMemory::key('+91 98700 07711'); $k2 = AiMemory::key('919870007711'); $k3 = AiMemory::key('9870007711');
check('the key is a 64-char hash', strlen($k2) === 64 && ctype_xdigit($k2));
check('country code is not identity', $k1 === $k2 && $k2 === $k3, substr($k1, 0, 8) . ' / ' . substr($k3, 0, 8));
check('the key never contains the number', !str_contains($k2, '9870007711'));
check('an empty number has no key', AiMemory::key('') === '' && AiMemory::key('abc') === '');
check('a different number is a different person', AiMemory::key('9870007712') !== $k2);

echo "\n-- B. remember, recall, profile --\n";
Settings::set('ai_memory_on', '1', 'bool', 'ai'); Settings::flush();
check('on: remember() writes', AiMemory::remember(PHONE, 'booking', 'Booked 2 seats Surat → Rupaidiha for 3 Dec 2099 from Kamrej', 12345, 4, 'web'));
check('blank summary is refused', AiMemory::remember(PHONE, 'note', '   ') === false);
AiMemory::remember(PHONE, 'note', 'Asked about luggage', null, 1);
$ep = AiMemory::recall(PHONE);
check('recall returns the episodes, important first', count($ep) === 2 && $ep[0]['kind'] === 'booking', json_encode(array_column($ep, 'kind')));
AiMemory::touchProfile(PHONE, ['display_name' => 'Ram Bahadur', 'usual_pickup' => 'Kamrej', 'usual_direction' => 'toNepal', 'trip_date' => '2099-12-03', 'language' => 'ne']);
AiMemory::touchProfile(PHONE, ['trip_date' => '2099-12-20']);
$p = AiMemory::profile(PHONE);
check('the profile counts trips', (int) ($p['trips'] ?? 0) === 2, (string) ($p['trips'] ?? 'none'));
check('…and keeps name, pickup, direction, language', ($p['display_name'] ?? '') === 'Ram Bahadur' && ($p['usual_pickup'] ?? '') === 'Kamrej' && ($p['usual_direction'] ?? '') === 'toNepal' && ($p['language'] ?? '') === 'ne');
check('the last trip date moves forward', ($p['last_trip_date'] ?? '') === '2099-12-20', (string) ($p['last_trip_date'] ?? ''));
$brief = AiMemory::brief(PHONE);
check('the brief names the person and the trips', str_contains($brief, 'name Ram Bahadur') && str_contains($brief, '2 trip(s)') && str_contains($brief, 'towards Nepal'));
check('…quotes the episodes', str_contains($brief, 'Booked 2 seats') && str_contains($brief, 'Asked about luggage'));
check('…never contains the phone', !str_contains($brief, '9870007711'));
check('…and tells the assistant to trust the person over the memory', str_contains($brief, 'trust what they say now'));
check('a stranger has no brief', AiMemory::brief('9870009999') === '');

echo "\n-- C. the register writes the memory --\n";
AiMemory::forget(PHONE);
EventBus::emit('booking.created', ['booking' => ['id' => 987654, 'pnr' => 'SHGTEST1', 'contact_phone' => PHONE, 'from_city' => 'Surat', 'to_city' => 'Rupaidiha', 'source' => 'web']]);
$ep = AiMemory::recall(PHONE);
check('booking.created becomes a booking episode', count($ep) === 1 && $ep[0]['kind'] === 'booking' && str_contains((string) $ep[0]['summary'], 'SHGTEST1'), (string) ($ep[0]['summary'] ?? 'none'));
$p = AiMemory::profile(PHONE);
check('…and the profile learns the direction', ($p['usual_direction'] ?? '') === 'toNepal', (string) ($p['usual_direction'] ?? ''));
EventBus::emit('booking.cancelled', ['booking' => ['id' => 987654, 'pnr' => 'SHGTEST1', 'contact_phone' => PHONE], 'refund' => ['amount' => 1500], 'reason' => 'change of plan']);
$ep = AiMemory::recall(PHONE);
$cancel = array_values(array_filter($ep, static fn(array $e): bool => $e['kind'] === 'cancel'));
check('booking.cancelled becomes a cancel episode with the refund', $cancel !== [] && str_contains((string) $cancel[0]['summary'], '1,500') && str_contains((string) $cancel[0]['summary'], 'change of plan'), (string) ($cancel[0]['summary'] ?? 'none'));
EventBus::emit('booking.approved', ['booking' => ['id' => 987654, 'pnr' => 'SHGTEST1', 'contact_phone' => '']]);
check('an event without a phone is ignored, not an error', count(AiMemory::recall(PHONE)) === 2);
Settings::set('ai_memory_on', '0', 'bool', 'ai'); Settings::flush();
EventBus::emit('booking.approved', ['booking' => ['id' => 987654, 'pnr' => 'SHGTEST1', 'contact_phone' => PHONE]]);
check('off: the listener stays quiet', count(AiMemory::recall(PHONE)) === 2);
Settings::set('ai_memory_on', '1', 'bool', 'ai'); Settings::flush();

echo "\n-- D. forget and the cap --\n";
$n = AiMemory::forget(PHONE);
check('forget() removes every row for the person', $n >= 3 && AiMemory::profile(PHONE) === null && AiMemory::recall(PHONE) === [], "$n rows");
for ($i = 0; $i < 125; $i++) { AiMemory::remember(PHONE, 'note', 'episode ' . $i, null, $i < 5 ? 5 : 1); }
$cnt = (int) Database::scalar('SELECT COUNT(*) FROM ai_memory_episodes WHERE owner_key = :k', ['k' => AiMemory::key(PHONE)], 0);
check('a person keeps at most 120 episodes', $cnt === 120, "$cnt");
$kept = (int) Database::scalar('SELECT COUNT(*) FROM ai_memory_episodes WHERE owner_key = :k AND importance = 5', ['k' => AiMemory::key(PHONE)], 0);
check('…the important ones survive the trim', $kept === 5, "$kept");
AiMemory::forget(PHONE);
$restore();

echo "\n$PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
