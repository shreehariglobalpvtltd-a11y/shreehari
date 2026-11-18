<?php
/**
 * =====================================================================
 *  i4-halt-use-time-test.php — 19 Nov 2026 (integrator i4):
 *  Admin → Routes → Halts shows a halt time learned from the GPS trail
 *  (route_stop_learned) and a "यो समय राख्नुहोस् · Use this time" button.
 *
 *    - the button is a re-post of the normal edit_halt form: the halt's
 *      own name / kind / stands / km / landmark / note / ≈ and only the
 *      clock + journey day changed;
 *    - the clock is worked from the route's departure time + the learned
 *      median offset (a retimed bus is not given a stale time);
 *    - posting it (CSRF + routes.edit, as before) changes only the time
 *      and writes the usual route_halt.edit audit row;
 *    - once the time matches, the button goes (the GPS line stays).
 *
 *  Pages render in a child PHP process (tests/render-admin.php, first
 *  active admin). One route_stop_learned row is added; the halt, the
 *  learned row and the audit row are put back / removed at the end.
 *
 *    php -c .claude/php-dev.ini tests/i4-halt-use-time-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/stoptiming.php';
if (stripos((string) DB_NAME, 'test') === false) { fwrite(STDERR, "Refusing: not a test database.\n"); exit(2); }

$pass = 0; $fail = 0;
$ok = static function (bool $c, string $what, string $detail = '') use (&$pass, &$fail): void {
    if ($c) { $pass++; echo "  ok    $what\n"; } else { $fail++; echo "  FAIL  $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
};
$render = static function (array $args): string {
    $ini = php_ini_loaded_file();
    $cmd = escapeshellarg(PHP_BINARY) . ($ini !== false ? ' -c ' . escapeshellarg($ini) : '')
         . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'render-admin.php') . ' routes.php';
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg($a); }
    $out = []; $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return implode("\n", $out);
};

try {
    $hasTables = (int) Database::scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('route_halts', 'route_stop_learned')", [], 0) === 2;
} catch (Throwable $e) { $hasTables = false; }
if (!$hasTables) { echo "SKIP: route_halts / route_stop_learned not installed on this test DB\n0 passed, 0 failed\n"; exit(0); }

$halt = Database::fetch('SELECT h.*, r.dep_time FROM route_halts h JOIN routes r ON r.id = h.route_id WHERE h.is_active = 1 AND h.offset_min IS NOT NULL ORDER BY h.id LIMIT 1');
if ($halt === null) { echo "SKIP: no active timed halt on this test DB\n0 passed, 0 failed\n"; exit(0); }
$hid = (int) $halt['id']; $rid = (int) $halt['route_id'];
$origHalt    = $halt; unset($origHalt['dep_time']);
$origLearned = Database::fetch("SELECT * FROM route_stop_learned WHERE route_id = :r AND stop_kind = 'halt' AND stop_ref = :h", ['r' => $rid, 'h' => $hid]);
$auditFrom   = (int) Database::scalar('SELECT COALESCE(MAX(id), 0) FROM audit_logs', [], 0);

/* a learned offset 15 min later than the timetable */
$learnOff = (int) $halt['offset_min'] + 15;
[$sugT, $sugDay] = StopTiming::suggest((string) $halt['dep_time'], $learnOff);
$sug = substr($sugT, 0, 5);

try {
    Database::run("DELETE FROM route_stop_learned WHERE route_id = :r AND stop_kind = 'halt' AND stop_ref = :h", ['r' => $rid, 'h' => $hid]);
    Database::run(
        "INSERT INTO route_stop_learned (route_id, stop_kind, stop_ref, samples, median_offset_min, p80_offset_min, median_dwell_min, suggested_time, day_offset, last_trip_at)
         VALUES (:r, 'halt', :h, 6, :m, :p, 22, '00:00:00', 0, NOW())",
        ['r' => $rid, 'h' => $hid, 'm' => $learnOff, 'p' => $learnOff + 10]);   // a stale stored clock on purpose

    echo "\n1. The halt shows the GPS time and the button\n";
    $html = $render([]);
    $ok(str_contains($html, 'data-learned-halt="' . $hid . '"'), 'the learned line is drawn for halt #' . $hid, substr(strip_tags($html), 0, 200));
    $ok(str_contains($html, 'GPS ≈ ' . $sug), "the clock is worked from today's departure + the median offset ($sug), not the stored 00:00");
    $form = '';
    if (preg_match_all('~<form method="post" style="margin:0" onsubmit="return confirm\([^"]*\)">(.*?)</form>~s', $html, $mm)) {
        foreach ($mm[1] as $f) {
            if (str_contains($f, 'name="halt_id" value="' . $hid . '"') && str_contains($f, 'value="edit_halt"')) { $form = $f; break; }
        }
    }
    $ok($form !== '' && str_contains($form, 'यो समय राख्नुहोस् · Use this time'), '"यो समय राख्नुहोस् · Use this time" is a form for this halt');
    $fields = [];
    if (preg_match_all('~<input type="hidden" name="([a-z_]+)" value="([^"]*)">~', $form, $fm, PREG_SET_ORDER)) {
        foreach ($fm as $f) { $fields[$f[1]] = html_entity_decode($f[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'); }
    }
    $ok(($fields['action'] ?? '') === 'edit_halt' && ($fields['clock_time'] ?? '') === $sug && (int) ($fields['day'] ?? -1) === $sugDay,
        'it re-posts edit_halt with the suggested clock + journey day', json_encode($fields, JSON_UNESCAPED_UNICODE));
    $ok(($fields['name'] ?? null) === (string) $halt['name'] && ($fields['kind'] ?? null) === (string) $halt['kind'] && (int) ($fields['halt_min'] ?? -1) === (int) $halt['halt_min'],
        "the halt's own name, kind and stand time travel with it");
    $ok(isset($fields[CSRF_TOKEN_NAME]) && $fields[CSRF_TOKEN_NAME] !== '', 'the form carries the CSRF token');

    echo "\n2. Posting it changes only the time, audited\n";
    $args = [];
    foreach ($fields as $k => $v) {
        if ($k !== CSRF_TOKEN_NAME) { $args[] = 'post:' . $k . '=' . $v; }
    }
    $render($args);
    $after = Database::fetch('SELECT * FROM route_halts WHERE id = :h', ['h' => $hid]);
    $ok($after !== null && (int) $after['offset_min'] === $learnOff, 'offset_min is now the learned one (' . $learnOff . ')', 'got ' . ($after['offset_min'] ?? 'null'));
    $same = true;
    foreach (['name', 'kind', 'halt_min', 'km_from_origin', 'landmark', 'note', 'is_approx', 'is_active', 'route_id', 'sort_order'] as $c) {
        if ((string) ($after[$c] ?? '') !== (string) ($origHalt[$c] ?? '')) { $same = false; echo "        changed: $c\n"; }
    }
    $ok($same, 'every other field of the halt is unchanged');
    $aud = Database::fetch("SELECT * FROM audit_logs WHERE id > :a AND action = 'route_halt.edit' AND entity_id = :r ORDER BY id DESC LIMIT 1", ['a' => $auditFrom, 'r' => (string) $rid]);
    $ok($aud !== null, 'the usual route_halt.edit audit row is written');

    echo "\n3. Once it matches, the button goes\n";
    $html2 = $render([]);
    $ok(str_contains($html2, 'data-learned-halt="' . $hid . '"'), 'the GPS line stays');
    $still = false;
    if (preg_match_all('~<form method="post" style="margin:0" onsubmit="return confirm\([^"]*\)">(.*?)</form>~s', $html2, $mm2)) {
        foreach ($mm2[1] as $f) { if (str_contains($f, 'name="halt_id" value="' . $hid . '"')) { $still = true; } }
    }
    $ok(!$still, 'no "Use this time" button when the timetable already says the learned time');
} finally {
    /* put everything back */
    $restore = $origHalt; unset($restore['id']);
    foreach (['created_at', 'updated_at'] as $c) { unset($restore[$c]); }
    try { Database::update('route_halts', $restore, 'id = :h', ['h' => $hid]); } catch (Throwable $e) { echo "  WARN  halt restore: " . $e->getMessage() . "\n"; }
    Database::run("DELETE FROM route_stop_learned WHERE route_id = :r AND stop_kind = 'halt' AND stop_ref = :h", ['r' => $rid, 'h' => $hid]);
    if ($origLearned !== null) { Database::insert('route_stop_learned', $origLearned); }
    Database::run("DELETE FROM audit_logs WHERE id > :a AND action = 'route_halt.edit' AND entity_id = :r", ['a' => $auditFrom, 'r' => (string) $rid]);
    Boarding::flush();
}
$back = Database::fetch('SELECT offset_min, clock_time FROM route_halts WHERE id = :h', ['h' => $hid]);
$ok($back !== null && (string) $back['offset_min'] === (string) $origHalt['offset_min'] && (string) $back['clock_time'] === (string) $origHalt['clock_time'], 'the halt is back as it was');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
