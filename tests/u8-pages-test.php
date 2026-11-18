<?php
/**
 * =====================================================================
 *  u8-pages-test.php — the u8 screens render (19 Nov 2026, unit u8):
 *  AI Control Center (night review card + history + staff brain / review
 *  controls), Map → Boarding (GPS suggestion + "Use this time" form that
 *  re-posts the existing stop update with the stop's own fare and
 *  landmark), AI Marketing (per-language controls).
 *
 *  Each page runs in a child PHP process with a superadmin session on
 *  shari_test (GET only — no setting is saved). One far-day ai_reviews
 *  row and one route_stop_learned row are added and removed again.
 *
 *    php -c .claude/php-dev.ini tests/u8-pages-test.php
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

if (($argv[1] ?? '') === '--run') {
    $page = (string) $argv[2];
    $get  = json_decode((string) base64_decode((string) ($argv[3] ?? ''), true), true) ?: [];
    $root = dirname(__DIR__);
    require_once $root . '/includes/bootstrap.php';
    if (DB_NAME !== 'shari_test') { fwrite(STDERR, "refusing\n"); exit(2); }
    $_SESSION = [];
    $_SESSION[ADMIN_SESSION_KEY] = ['id' => 1, 'username' => 'superadmin', 'full_name' => 'u8 test', 'role' => 'superadmin',
        'permissions' => [], 'last_seen' => time(), 'qk_ok' => true];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . $page;
    $_SERVER['REQUEST_URI'] = '/' . $page . ($get !== [] ? '?' . http_build_query($get) : '');
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_GET = $get;
    register_shutdown_function(static function (): void {
        $err = error_get_last();
        echo "\n@@META@@" . json_encode(['http' => http_response_code() ?: 200,
            'err' => $err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) ? $err['message'] : null]);
    });
    try {
        require $root . '/' . $page;
    } catch (Throwable $e) {
        http_response_code(500);
        echo "\nUNCAUGHT " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
    }
    exit(0);
}

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
if (DB_NAME !== 'shari_test') { echo "  SKIP  not shari_test\n"; exit(0); }

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  PASS  $l\n"; } else { $FAIL++; echo "  FAIL  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
function page(string $p, array $get = []): array {
    $cmd = '"' . PHP_BINARY . '" -c "' . php_ini_loaded_file() . '" "' . __FILE__ . '" --run ' . $p . ' ' . base64_encode(json_encode($get) ?: '{}');
    $out = (string) shell_exec($cmd . ' 2>&1');
    $meta = [];
    if (preg_match('/@@META@@(\{.*\})\s*$/s', $out, $m) === 1) { $meta = json_decode($m[1], true) ?: []; }
    return ['html' => $out, 'http' => (int) ($meta['http'] ?? 0), 'err' => $meta['err'] ?? null];
}

echo "\n=== u8 pages ===\n\n";
$day = '2099-01-15';
Database::run('DELETE FROM ai_reviews WHERE day = :d', ['d' => $day]);
Database::run("INSERT INTO ai_reviews (day, status, model, note, cost_usd, summary_ne, review, kb_saved, created_at) VALUES
    (:d, 'ok', 'claude-opus-5-5', '2 recommendations, 1 KB drafts', 0.4321, :s, :r, 1, NOW())", ['d' => $day,
    's' => "आज ठिक भयो।\nसमस्या: ZZU8 भुक्तानी।\nभोलि: जाँच।",
    'r' => json_encode(['summary_ne' => "आज ठिक भयो।\nसमस्या: ZZU8 भुक्तानी।\nभोलि: जाँच।", 'summary_en' => 'ZZU8 fine day',
        'went_wrong' => [['what_ne' => 'ZZU8 WhatsApp असफल', 'what_en' => 'WA failed', 'evidence' => '3 x 131047', 'severity' => 'high']],
        'teach_bot' => [], 'recommendations' => [['who' => 'agents', 'ne' => 'ZZU8 एजेन्टलाई सम्झाउनुहोस्', 'en' => 'Remind agents']],
        'anomalies' => [['what_ne' => 'ZZU8 अनौठो', 'what_en' => 'odd', 'evidence' => 'x']]], JSON_UNESCAPED_UNICODE)]);

$stop = Database::fetch("SELECT rs.id, rs.route_id, rs.fare, rs.landmark FROM route_stops rs JOIN routes r ON r.id = rs.route_id
    WHERE rs.is_active = 1 AND r.is_active = 1 ORDER BY rs.id LIMIT 1");
$hadLearned = $stop !== null && Database::fetch("SELECT 1 FROM route_stop_learned WHERE route_id = :r AND stop_kind = 'stop' AND stop_ref = :s",
    ['r' => (int) $stop['route_id'], 's' => (int) $stop['id']]) !== null;
if ($stop !== null && !$hadLearned) {
    Database::run("INSERT INTO route_stop_learned (route_id, stop_kind, stop_ref, samples, median_offset_min, p80_offset_min, median_dwell_min, suggested_time, day_offset)
        VALUES (:r, 'stop', :s, 4, 95, 110, 7, '04:35:00', 1)", ['r' => (int) $stop['route_id'], 's' => (int) $stop['id']]);
}

/* a learned halt (fix of 19 Nov: halts are shown read-only on Map → Boarding) */
$haltId = 0;
if ($stop !== null) {
    $haltId = (int) Database::insert('route_halts', ['route_id' => (int) $stop['route_id'], 'sort_order' => 99, 'name' => 'ZZTEST u8 Dhaba',
        'kind' => 'tea', 'clock_time' => '16:30:00', 'halt_min' => 15, 'km_from_origin' => 210.0, 'is_approx' => 1, 'is_active' => 0]);
    Database::run("INSERT INTO route_stop_learned (route_id, stop_kind, stop_ref, samples, median_offset_min, p80_offset_min, median_dwell_min, suggested_time, day_offset)
        VALUES (:r, 'halt', :h, 3, 225, 240, 18, '16:45:00', 0)", ['r' => (int) $stop['route_id'], 'h' => $haltId]);
}

try {
    $p = page('admin/ai-center.php');
    check('ai-center renders 200, no fatal', $p['http'] === 200 && $p['err'] === null && !str_contains($p['html'], 'UNCAUGHT'), substr($p['html'], -400));
    check('ai-center: last night\'s review card with the 3 Nepali lines', str_contains($p['html'], 'Last night') && str_contains($p['html'], 'समस्या: ZZU8 भुक्तानी'));
    check('ai-center: went wrong / recommendations / anomalies / history', str_contains($p['html'], 'ZZU8 WhatsApp असफल')
        && str_contains($p['html'], 'ZZU8 एजेन्टलाई') && str_contains($p['html'], 'ZZU8 अनौठो') && str_contains($p['html'], 'data-ac="review-history"'));
    check('ai-center: staff brain + review controls', str_contains($p['html'], 'name="ai_staff_provider"') && str_contains($p['html'], 'name="ai_review_on"')
        && str_contains($p['html'], 'name="ai_review_usd"') && str_contains($p['html'], 'name="ai_review_autolearn"'));

    $m = page('admin/map.php', ['tab' => 'boarding']);
    check('map boarding tab renders 200, no fatal', $m['http'] === 200 && $m['err'] === null && !str_contains($m['html'], 'UNCAUGHT'), substr($m['html'], -400));
    if ($stop !== null && !$hadLearned) {
        $sid = (int) $stop['id'];
        check('map: GPS suggestion shown for the stop', str_contains($m['html'], 'data-learned="' . $sid . '"') && str_contains($m['html'], 'GPS ≈ 04:35'));
        $fare = (float) ($stop['fare'] ?? 0) > 0 ? (string) (float) $stop['fare'] : '';
        check('map: "Use this time" re-posts update_stop with the stop\'s own fare',
            preg_match('/name="stop_id" value="' . $sid . '"><input type="hidden" name="stop_time" value="04:35">\s*<input type="hidden" name="fare" value="' . preg_quote($fare, '/') . '">/', $m['html']) === 1);
    }

    if ($haltId > 0) {
        check('map: learned halt shown read-only (GPS ≈ 16:45, 18 of 15 planned min)', str_contains($m['html'], 'data-learned-halt="' . $haltId . '"')
            && str_contains($m['html'], 'ZZTEST u8 Dhaba') && str_contains($m['html'], '16:45') && str_contains($m['html'], '15 planned'));
    }

    $a = page('admin/ai-marketing.php');
    check('ai-marketing renders 200 with the language controls', $a['http'] === 200 && $a['err'] === null
        && str_contains($a['html'], 'name="mk_auto_langs[]"') && str_contains($a['html'], 'value="en"'), substr($a['html'], -400));
} finally {
    Database::run('DELETE FROM ai_reviews WHERE day = :d', ['d' => $day]);
    if ($haltId > 0) {
        Database::run("DELETE FROM route_stop_learned WHERE stop_kind = 'halt' AND stop_ref = :h", ['h' => $haltId]);
        Database::run('DELETE FROM route_halts WHERE id = :h', ['h' => $haltId]);
    }
    if ($stop !== null && !$hadLearned) {
        Database::run("DELETE FROM route_stop_learned WHERE route_id = :r AND stop_kind = 'stop' AND stop_ref = :s", ['r' => (int) $stop['route_id'], 's' => (int) $stop['id']]);
    }
}
echo "\n  $PASS passed, $FAIL failed\n";
exit($FAIL === 0 ? 0 : 1);
