<?php
/**
 * =====================================================================
 *  quick-unlock-test.php — PIN + pattern quick unlock (1 Nov 2026).
 *
 *  includes/quickunlock.php: what may be set (4-6 digit PIN, not trivial;
 *  a 3x3 pattern of 4+ distinct nodes), bcrypt storage, the match that
 *  "picks the ID" on a mobile, the per-account lockout, the ambiguous
 *  same-PIN-on-two-IDs refusal, and remove. Throwaway admins on the LOCAL
 *  shari_test database, deleted afterwards.
 *
 *     php -c .claude/php-dev.ini tests/quick-unlock-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/quickunlock.php';

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}

echo "\n=== Quick unlock — PIN + pattern ===\n\n";

if (!QuickUnlock::ready()) {
    echo "  SKIP  run database/upgrade-2026-11-01-quick-unlock.sql first\n";
    exit(1);
}
Settings::set('quick_unlock_on', true, 'bool', 'security', false);
Database::query("DELETE FROM rate_limits WHERE bucket = 'quick_unlock'");

$PFX = 'qu-test-';
Database::query("DELETE FROM admins WHERE username LIKE '{$PFX}%'");
$mk = static function (string $u, string $phone) use ($PFX): int {
    return (int) Database::insert('admins', [
        'username' => $PFX . $u, 'password_hash' => password_hash('Pw@12345', PASSWORD_BCRYPT),
        'full_name' => 'Quick Unlock ' . $u, 'role' => 'agent', 'phone' => $phone,
        'is_active' => 1, 'must_change_pw' => 0,
    ]);
};
$a = $mk('a', '9800000941');
$b = $mk('b', '9800000941');   // same mobile as a

/* ---- what may be set ---- */
check('PIN of 2 digits is refused',      QuickUnlock::pinProblem('12') !== null);
check('PIN 1111 is refused (too easy)',  QuickUnlock::pinProblem('1111') !== null);
check('PIN 1234 is refused (too easy)',  QuickUnlock::pinProblem('1234') !== null);
check('PIN with a letter is refused',    QuickUnlock::pinProblem('12a4') !== null);
check('PIN 4826 is accepted',            QuickUnlock::pinProblem('4826') === null);
check('pattern 0-4-8-5 is canonical',    QuickUnlock::canonicalPattern('0-4-8-5') === '0-4-8-5');
check('pattern reusing a dot is invalid', QuickUnlock::canonicalPattern('0-1-1-2') === null);
check('pattern with dot 9 is invalid',   QuickUnlock::canonicalPattern('0-9-1-2') === null);
check('pattern of 3 dots is refused',    QuickUnlock::patternProblem('0-1-2') !== null);

/* ---- set + stored as bcrypt ---- */
QuickUnlock::set($a, 'pin', '4826');
QuickUnlock::set($a, 'pattern', '0-4-8-5');
$row = Database::fetch('SELECT pin_hash, pattern_hash FROM admins WHERE id = :i', ['i' => $a]) ?? [];
check('PIN stored as a bcrypt hash, never plain', (string) $row['pin_hash'] !== '4826' && password_verify('4826', (string) $row['pin_hash']));
check('pattern stored as a bcrypt hash', password_verify('0-4-8-5', (string) $row['pattern_hash']));
check('status shows both set', QuickUnlock::status($a) === ['pin' => true, 'pattern' => true]);
$threw = false;
try { QuickUnlock::set($a, 'pin', '0000'); } catch (RuntimeException $e) { $threw = true; }
check('setting a trivial PIN throws', $threw);

/* ---- match ---- */
$m = QuickUnlock::match([$a], 'pin', '4826');
check('right PIN opens the account', $m['ok'] && (int) $m['admin']['id'] === $a);
$m = QuickUnlock::match([$a], 'pattern', '0-4-8-5');
check('right pattern opens the account', $m['ok'] && (int) $m['admin']['id'] === $a);
check('the same pattern drawn backwards does not', !QuickUnlock::match([$a], 'pattern', '5-8-4-0')['ok']);
$m = QuickUnlock::match([$a, $b], 'pin', '4826');
check('on a mobile the PIN picks the ID', $m['ok'] && (int) $m['admin']['id'] === $a);

/* ---- same PIN on two IDs of one mobile ---- */
QuickUnlock::set($b, 'pin', '4826');
$m = QuickUnlock::match([$a, $b], 'pin', '4826');
check('same PIN on two IDs of one mobile is refused (type the ID)', !$m['ok'] && str_contains((string) $m['error'], 'more than one ID'));
QuickUnlock::remove($b, 'pin');

/* ---- lockout ---- */
for ($i = 0; $i < QuickUnlock::MAX_FAILS; $i++) {
    QuickUnlock::match([$a], 'pin', '9173');
}
$lk = Database::fetch('SELECT unlock_failed, unlock_locked_until FROM admins WHERE id = :i', ['i' => $a]) ?? [];
check(QuickUnlock::MAX_FAILS . ' wrong tries lock quick unlock', !empty($lk['unlock_locked_until']), 'failed=' . $lk['unlock_failed']);
check('while locked even the right PIN is refused', !QuickUnlock::match([$a], 'pin', '4826')['ok']);
$pw = (string) Database::scalar('SELECT password_hash FROM admins WHERE id = :i', ['i' => $a], '');
check('the lockout never touches the password', password_verify('Pw@12345', $pw));
QuickUnlock::set($a, 'pin', '4826');   // a fresh set clears the lock
check('setting a new PIN clears the lock', QuickUnlock::match([$a], 'pin', '4826')['ok']);

/* ---- remove ---- */
QuickUnlock::remove($a, 'pin');
check('removed PIN no longer opens', !QuickUnlock::match([$a], 'pin', '4826')['ok']);
check('the pattern still does', QuickUnlock::match([$a], 'pattern', '0-4-8-5')['ok']);

/* ---- switched off ---- */
Settings::set('quick_unlock_on', false, 'bool', 'security', false);
check('switch off: nothing opens', !QuickUnlock::match([$a], 'pattern', '0-4-8-5')['ok']);
Settings::set('quick_unlock_on', true, 'bool', 'security', false);

/* ---- cleanup ---- */
Database::query("DELETE FROM admins WHERE username LIKE '{$PFX}%'");
Database::query("DELETE FROM rate_limits WHERE bucket = 'quick_unlock'");

echo "\n  " . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n\n";
exit($FAIL === 0 ? 0 : 1);
