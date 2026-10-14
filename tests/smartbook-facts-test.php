<?php
/**
 * =====================================================================
 *  smartbook-facts-test.php — SmartBook v5 phase 2 (15 Oct 2026).
 *
 *  Owner's final decisions:
 *    D8  ONE cancellation policy everywhere: >= 48 h 75 %, 24-48 h 50 %,
 *        under 24 h no refund, money back in 5-7 working days. The 96 h /
 *        90 % version is gone.
 *    D9  ONE distance everywhere: 1,375 km.
 *    D10 Rajkot is a boarding point in every list (customer, agent,
 *        counter, admin, fallbacks, meta).
 *
 *  A. Fare::refundSlabs() / refundFor() — the live calculator follows D8
 *  B. Fare::refundPolicyLine() — the printed sentence in en / hi / ne / gu
 *  C. SiteFacts — policy.* + route.* in boot() and as {{f:…}} tokens
 *  D. RATCHET — no customer-facing file carries the old policy or another
 *     route total (96 h / 90 %, 6 h / 25 %, 1,300 / 1,688 / 1,635 km,
 *     7–21 / 7–10 / 14–21 / 3–5 days)
 *  E. Wiring — i18n / template / pages read the facts, not a copy
 *  F. Rajkot — the DB boarding list and every fallback list carry it
 *  G. HTTP (SHG_TEST_BASE): the served home page and /refund-policy show
 *     the D8 line and 1,375 km, and nothing from the old policy
 *
 *      php -c .claude/php-dev.ini tests/smartbook-facts-test.php
 *      SHG_TEST_BASE=http://localhost:8970 php -c .claude/php-dev.ini tests/smartbook-facts-test.php
 *
 *  Read-only: writes nothing to the database. exit(1) on any failure.
 * =====================================================================
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/sitefacts.php';

$ROOT = dirname(__DIR__);
const RESCHEDULE_RULE = 'where the request is made more than 96 hours before departure; a request made between 24 and 96 hours before departure';
$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok, string $extra = ''): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l" . ($extra !== '' ? " — $extra" : '') . "\n"; }
}
$src = static fn(string $rel): string => (string) @file_get_contents($ROOT . '/' . $rel);

/* ------------------------------------------------------------------ */
echo "\nA. The refund calculator follows D8\n";
$slabs = Fare::refundSlabs();
check('refundSlabs() = 48/75 · 24/50 · 0/0', array_map(static fn(array $s): array => [(int) $s['minHrs'], (int) $s['pct']], $slabs) === [[48, 75], [24, 50], [0, 0]], json_encode($slabs));
check('code default = the same three tiers', Fare::REFUND_SLABS_DEFAULT === [['minHrs' => 48, 'pct' => 75], ['minHrs' => 24, 'pct' => 50], ['minHrs' => 0, 'pct' => 0]]);
check('refundDays() = 5–7', Fare::refundDays() === '5–7', Fare::refundDays());
$at = static function (float $h): array {
    $ts = time() + (int) round($h * 3600);
    return Fare::refundFor(1000.0, date('Y-m-d', $ts), date('H:i:s', $ts));
};
foreach ([[120, 75], [72, 75], [48.2, 75], [47.5, 50], [30, 50], [24.2, 50], [23.5, 0], [8, 0], [2, 0]] as [$h, $want]) {
    $r = $at((float) $h);
    check("refundFor at {$h} h = {$want} %", (int) $r['percent'] === $want && (int) $r['amount'] === $want * 10, $r['percent'] . '% / ' . $r['amount']);
}

/* ------------------------------------------------------------------ */
echo "\nB. The printed policy sentence\n";
$want = [
    'en' => '≥48 hrs before departure: 75% refund · 24–48 hrs: 50% · under 24 hrs: no refund. Refunds reach the same account within 5–7 working days.',
    'hi' => 'प्रस्थान से ≥48 घंटे पहले: 75% रिफंड · 24–48 घंटे: 50% · 24 घंटे से कम: कोई रिफंड नहीं। रिफंड 5–7 कार्यदिवसों में उसी खाते में पहुंचता है।',
    'ne' => 'प्रस्थानभन्दा ≥४८ घण्टा अघि: ७५% फिर्ता · २४–४८ घण्टा: ५०% · २४ घण्टाभन्दा कम: फिर्ता छैन। फिर्ता ५–७ कार्य दिनभित्र उही खातामा आउँछ।',
];
foreach ($want as $l => $w) {
    check("refundPolicyLine('$l')", Fare::refundPolicyLine($l) === $w, Fare::refundPolicyLine($l));
}
check("refundPolicyLine('gu') has 48 / 75 / 50 and no 96", (bool) preg_match('/48.*75%.*50%/u', Fare::refundPolicyLine('gu')) && !str_contains(Fare::refundPolicyLine('gu'), '96'));
check('unknown language falls back to en', Fare::refundPolicyLine('xx') === $want['en']);

/* ------------------------------------------------------------------ */
echo "\nC. SiteFacts carries policy + route\n";
SiteFacts::flush();
$b = SiteFacts::boot();
check('boot().policy.line.en = the D8 line', ($b['policy']['line']['en'] ?? '') === $want['en']);
check('boot().policy.slabs = 3 tiers', count($b['policy']['slabs'] ?? []) === 3);
check('boot().policy.days = 5–7', ($b['policy']['days'] ?? '') === '5–7');
check('boot().route.km = 1375', ($b['route']['km'] ?? 0) === 1375);
check("boot().route.kmText = '1,375'", ($b['route']['kmText'] ?? '') === '1,375');
check('SiteFacts::routeKm() = 1375', SiteFacts::routeKm() === 1375);
$tok = SiteFacts::tokens();
check('{{f:policy.line.en}} token', ($tok['{{f:policy.line.en}}'] ?? '') === htmlspecialchars($want['en'], ENT_QUOTES, 'UTF-8'));
check('{{f:route.kmText}} token = 1,375', ($tok['{{f:route.kmText}}'] ?? '') === '1,375');

/* ------------------------------------------------------------------ */
echo "\nD. Ratchet — no other policy / distance in customer-facing files\n";
$files = ['app.template.html', 'index.php', 'refund-policy.php', 'travel-info.php', 'help.php', 'verify-ticket.php'];
foreach (glob($ROOT . '/assets/js/*.js') ?: [] as $f) {
    $rel = 'assets/js/' . basename($f);
    if (!in_array(basename($f), ['13-admin-routes.js', '14-counter.js'], true)) {   // staff-only screens
        $files[] = $rel;
    }
}
foreach (['includes/legal.php', 'includes/routepages.php', 'includes/walocal.php', 'includes/wafaq.php', 'includes/aiprompt.php',
          'includes/aichat.php', 'includes/notify.php', 'includes/ticket.php', 'includes/ticketbot.php', 'includes/publiclayout.php'] as $f) {
    $files[] = $f;
}
$banned = [
    'old 96 h tier'      => '/(?:≥|>=|more than|over)\s?96\s?(?:h\b|hrs|hours|घंटे|घण्टा|કલાક)|96\s?(?:h|hrs|hours)\s?(?:or more|\+)|48\s?[–-]\s?96\s?(?:h|hrs|hours|घंटे|घण्टा|કલાક)|९६ घण्टा/u',
    'old 90 % refund'    => '/90\s?%\s?(?:refund|रिफंड|फिर्ता|રિફંડ|is refunded)|९०% फिर्ता/u',
    'old 6 h / 25 % tier'=> '/6\s?[–-]\s?24\s?(?:h|hrs|hours|घंटे|घण्टा|કલાક)|under 6\s?h|&lt;6h|25\s?% is refunded/u',
    'other refund days'  => '/(?:7\s?[–-]\s?21|14\s?[–-]\s?21|7\s?[–-]\s?10|3\s?[–-]\s?5)\s?(?:working|कार्य|days|दिन)|[७][–-]२१|३–५ कार्य/u',
    'other route total'  => '/\b1,?(?:300|688|635|476|450|420|372)\s?km\b/u',
];
$hits = [];
foreach ($files as $rel) {
    $txt = $src($rel);
    if ($txt === '') { continue; }
    // The Terms' RESCHEDULE rule (free > 96 h, 10 % fee 24-96 h) is not the
    // refund policy - D8 left it alone; it is pinned separately below.
    $txt = str_replace(RESCHEDULE_RULE, '', $txt);
    foreach ($banned as $what => $re) {
        if (preg_match_all($re, $txt, $m) > 0) {
            // the offline tracking tables keep measured km to place markers - allowed only as data, never as "N km" text
            $hits[] = "$rel: $what (" . implode(', ', array_slice(array_unique($m[0]), 0, 3)) . ')';
        }
    }
}
check('no banned literal in ' . count($files) . ' customer-facing files', $hits === [], implode(' | ', array_slice($hits, 0, 8)));
check('terms-data: the only 96 h left is the reschedule rule', substr_count($src('assets/js/terms-data.js'), '96 hours') === 2 && str_contains($src('assets/js/terms-data.js'), RESCHEDULE_RULE));

/* ------------------------------------------------------------------ */
echo "\nE. Wiring — every surface reads the facts\n";
$tpl = $src('app.template.html');
check('template: both payPolicy fallbacks = {{f:policy.line.en}}', substr_count($tpl, '<span data-i18n="payPolicy">{{f:policy.line.en}}</span>') === 2);
check('template: FAQ + counter card print {{f:policy.line.en}}', substr_count($tpl, '{{f:policy.line.en}}') >= 4);
check('template: km via {{f:route.kmText}} (hero, route card, FAQ, rail end)', substr_count($tpl, '{{f:route.kmText}}') >= 4);
$i18n = $src('assets/js/04-i18n.js');
check('i18n: payPolicy = {policy} in en / hi / ne', substr_count($i18n, "payPolicy: '{policy}'") === 3);
check('i18n: refundLine uses {refundDays} in en / hi / ne', substr_count($i18n, '{refundDays}') === 3);
check('i18n: tf() fills fact vars', str_contains($i18n, 'fillFactVars(s)'));
$cfg = $src('assets/js/02-config.js');
check('02-config: fillFactVars knows policy / refundDays / km', (bool) preg_match('/FACT_VAR_RE = .*\|policy\|refundDays\|km\)/', $cfg));
check('02-config: refundInfo() uses liveRefundSlabs()', str_contains($cfg, "liveRefundSlabs().find(s => hrs >= s.minHrs)"));
check('02-config: offline refundSlabs = 3 tiers', (bool) preg_match('/refundSlabs: \[[^\]]*minHrs: 48, pct: 75[^\]]*minHrs: 24, pct: 50[^\]]*minHrs: 0,\s+pct: 0\s*\}\s*\]/s', $cfg) && !preg_match('/minHrs: 96/', $cfg));
check('07-checkout: coRefundSlabs() = liveRefundSlabs()', str_contains($src('assets/js/07-checkout.js'), 'return liveRefundSlabs().map('));
check('16-lazy: chat answer = factPolicyIn(lang)', substr_count($src('assets/js/16-lazy.js'), "factPolicyIn('") === 4);
check('22-routeline: prints displayKm, not the measured total', str_contains($src('assets/js/22-routeline.js'), 'kmf(m.displayKm)') && !str_contains($src('assets/js/22-routeline.js'), 'kmf(m.distanceKm)'));
check('api/timetable: ships displayKm = SiteFacts::routeKm()', str_contains($src('api/timetable.php'), "'displayKm'      => SiteFacts::routeKm()"));
foreach (['refund-policy.php' => 'Fare::refundSlabs()', 'includes/legal.php' => 'Fare::refundSlabs()', 'includes/routepages.php' => 'Fare::refundSlabs()',
          'includes/walocal.php' => 'Fare::refundSlabs()', 'includes/aiprompt.php' => 'Fare::refundSlabs()', 'includes/notify.php' => 'Fare::refundDays()'] as $f => $needle) {
    check("$f reads $needle", str_contains($src($f), $needle));
    check("$f has no typed refund_slabs default", !preg_match("/getArray\\('refund_slabs'/", $src($f)));
}
check('whereis: ships displayKm = SiteFacts::routeKm()', str_contains($src('includes/whereis.php'), "'displayKm'       => SiteFacts::routeKm()"));
check('track.php prints displayKm, not the measured totalKm', str_contains($src('track.php'), "number_format((int) \$status['displayKm'])") && !str_contains($src('track.php'), "number_format((int) \$status['totalKm'])"));
check('track-live.js: total = displayKm, no per-stop km text', str_contains($src('assets/js/track-live.js'), 'var tot = d.displayKm || d.totalKm') && !str_contains($src('assets/js/track-live.js'), "m.push(kmf(r.km) + ' km')"));
check('17-pwa route guide: total = factRouteKm(), no per-stop rgKm', str_contains($src('assets/js/17-pwa.js'), 'factRouteKm()') && !str_contains($src('assets/js/17-pwa.js'), "TF('rgKm'"));
check('terms: no "last 6 hours" wording', !str_contains($src('assets/js/terms-data.js'), 'last 6 hours'));
check('routepages: km = SiteFacts::routeKm()', str_contains($src('includes/routepages.php'), "'km'        => SiteFacts::routeKm()"));

/* ------------------------------------------------------------------ */
echo "\nF. Rajkot in every boarding list\n";
$db = Database::fetchAll("SELECT r.route_code, rs.stop_type, rs.is_active FROM route_stops rs JOIN routes r ON r.id = rs.route_id
                           WHERE rs.stop_name = 'Rajkot' AND r.is_active = 1");
$codes = array_map(static fn(array $r): string => $r['route_code'] . ':' . $r['stop_type'] . ':' . (int) $r['is_active'], $db);
check('DB: Rajkot boarding on r2 (ON)', in_array('r2:boarding:1', $codes, true), implode(', ', $codes));
check('DB: Rajkot drop on r7 (ON)', in_array('r7:drop:1', $codes, true));
$locs = [];
try { $locs = Boarding::locations()['india'] ?? []; } catch (Throwable $e) { $locs = []; }
check('Boarding::locations() india carries Rajkot', in_array('Rajkot', array_map('strval', $locs), true) || (bool) preg_grep('/^Rajkot/', array_map('strval', $locs)), implode(', ', array_map('strval', $locs)));
check('02-config: mainPoints.india fallback carries Rajkot', (bool) preg_match("/india: \\[[^\\]]*'Rajkot'[^\\]]*\\]/", $cfg));
check('02-config: offline seedRoutes carry Rajkot both ways', substr_count($cfg, "'Rajkot · Rajkot office pickup'") === 2);
check('fare.php: last-resort list carries Rajkot', str_contains($src('includes/fare.php'), "'Emli Bhupal', 'Rajkot', 'S Hari Parking, Nana Chiloda'"));
check('template: description + list + timetable mention Rajkot', substr_count($tpl, 'Rajkot') >= 5);

/* ------------------------------------------------------------------ */
echo "\nH. Owner follow-up (15 Oct): reschedule 96 h, Rajkot beside Ahmedabad, journey time from the timetable\n";
check("rescheduleLine('en')", Fare::rescheduleLine('en') === 'Reschedule once: free if asked 96+ hrs before departure · 24–96 hrs: 10% fee · not allowed under 24 hrs.', Fare::rescheduleLine('en'));
check("rescheduleLine('ne') in Devanagari digits", str_contains(Fare::rescheduleLine('ne'), '९६+') && str_contains(Fare::rescheduleLine('ne'), '१०%'));
check('boot().policy.reschedule.en', ($b['policy']['reschedule']['en'] ?? '') === Fare::rescheduleLine('en'));
check('template FAQ prints {{f:policy.reschedule.en}}', str_contains($tpl, '{{f:policy.reschedule.en}}'));
check('refund page prints Fare::rescheduleLine, no "24+ hours" free reschedule', str_contains($src('refund-policy.php'), "Fare::rescheduleLine('en')") && !str_contains($src('refund-policy.php'), '24+ hours before departure'));
check('journey hours come from the timetable', is_float($b['route']['hours'] ?? null) && ($b['route']['hoursText'] ?? '') === SiteFacts::hoursText(SiteFacts::journeyHours()), (string) ($b['route']['hoursText'] ?? ''));
check('hoursText(35.5) = 35½, (30.0) = 30', SiteFacts::hoursText(35.5) === '35½' && SiteFacts::hoursText(30.0) === '30');
check('template: no typed "about 30 hours" / "≈30h 30m"', !str_contains($tpl, 'about 30 hours') && !str_contains($tpl, '≈30h 30m') && substr_count($tpl, '{{f:route.hoursText}}') >= 3);
$ord = static function (string $code, string $type): array {
    return array_map(static fn(array $r): string => (string) $r['stop_name'], Database::fetchAll(
        "SELECT rs.stop_name FROM route_stops rs JOIN routes r ON r.id = rs.route_id
          WHERE r.route_code = :c AND rs.stop_type = :t AND rs.is_active = 1 ORDER BY rs.sort_order, rs.id", ['c' => $code, 't' => $type]));
};
$go = $ord('r2', 'boarding');
$ri = array_search('Rajkot', $go, true);
check('r2: Rajkot sits right before Ahmedabad (Nana Chiloda)', $ri !== false && isset($go[$ri + 1]) && str_contains((string) $go[$ri + 1], 'Nana Chiloda') && ($go[0] ?? '') === 'Surat', implode(' > ', $go));
$back = $ord('r7', 'drop');
$bi = array_search('Rajkot', $back, true);
check('r7: Rajkot right after Ahmedabad', $bi !== false && $bi > 0 && str_contains((string) $back[$bi - 1], 'Nana Chiloda'), implode(' > ', $back));

/* ------------------------------------------------------------------ */
$base = rtrim((string) getenv('SHG_TEST_BASE'), '/');
if ($base !== '') {
    echo "\nG. HTTP — $base\n";
    $get = static function (string $u): string {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
        return (string) @file_get_contents($u, false, $ctx);
    };
    $home = $get($base . '/');
    check('home: served', str_contains($home, 'SHG_BOOT'));
    check('home: D8 line in the markup', str_contains($home, htmlspecialchars($want['en'], ENT_QUOTES, 'UTF-8')));
    check('home: 1,375 km', str_contains($home, '1,375 km'));
    check('home: no {{f: left', !str_contains($home, '{{f:'));
    check('home: no 96 h / 90 % / 1,300 km', !preg_match('/≥96|(?<![-\w])96h\b|90% refund|1,300 km|1635 km/u', $home));
    check('home boot: facts.policy.line.ne', str_contains($home, '"policy"') && str_contains($home, json_encode($want['ne'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '~'));
    $rp = $get($base . '/refund-policy.php');
    $rpNoRes = str_replace(htmlspecialchars(Fare::rescheduleLine('en'), ENT_QUOTES, 'UTF-8'), '', $rp);   // the reschedule rule (96 h) is allowed
check('refund-policy: 48 hours row, no 96 h refund tier', str_contains($rp, '48') && !preg_match('/96\+|96 hours|90% refund/u', $rpNoRes));
    check('refund-policy: 5–7 working days, no 7–10 / 14–21', str_contains($rp, '5–7 working days') && !preg_match('/7–10|14–21|7-21/u', $rp));
    $tt = $get($base . '/api/timetable.php');
    check('api/timetable: displayKm 1375', str_contains($tt, '"displayKm":1375'));
}

echo "\n" . ($FAIL === 0 ? "\033[32mALL $PASS PASSED\033[0m" : "\033[31m$FAIL FAILED\033[0m, $PASS passed") . "\n";
exit($FAIL === 0 ? 0 : 1);
