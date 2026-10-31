<?php
/**
 * /routine.php — "कुन दिन कुन बस · Bus Routine" (1 Nov 2026).
 *
 * The public board of which bus leaves which day, both ways, for the next
 * two weeks, each card one tap from booking that day. It came back after the
 * idc10 release dropped the hand-deployed original; the rows come from
 * includes/routineboard.php, i.e. exactly what the site sells.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/routineboard.php';

Security::requireRateLimit('routine_page', Security::clientIp(), 60, 60);

$days    = 14;
$list    = RoutineBoard::departures($days);
$go      = array_values(array_filter($list, static fn(array $d): bool => $d['toNepal']));
$back    = array_values(array_filter($list, static fn(array $d): bool => !$d['toNepal']));
$on      = Settings::getBool('daily_service_on', true);
$note    = Settings::getString('daily_service_note', '');
$phone   = Settings::getString('company_phone', '');
$e       = static fn(string $s): string => Security::e($s);
$today   = todayISO();
$dayName = static function (string $iso) use ($today): string {
    $ts = strtotime($iso . ' 00:00:00');
    $lbl = date('D j M', $ts);
    if ($iso === $today) {
        return $lbl . ' · आज';
    }
    return $iso === addDaysISO($today, 1) ? $lbl . ' · भोलि' : $lbl;
};
$cards = static function (array $rows) use ($e, $dayName): string {
    if ($rows === []) {
        return '<p class="none">यो दुई हप्तामा बस छैन · No bus in the next two weeks.</p>';
    }
    $h = '';
    foreach ($rows as $d) {
        $h .= '<a class="card" href="' . $e($d['book']) . '">'
            . '<span class="day">' . $e($dayName($d['date'])) . '</span>'
            . '<span class="run">' . $e($d['from']) . ' ' . $e($d['dep']) . ' → ' . $e($d['to']) . '</span>'
            . ($d['bus'] !== '' ? '<span class="bus">🚌 ' . $e($d['bus']) . '</span>' : '')
            . '<span class="go">सिट बुक · Book →</span></a>';
    }
    return $h;
};
header('Cache-Control: public, max-age=300');
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>बस रुटिन · Bus Routine | S Hari Global</title>
<meta name="description" content="Which S Hari Global AC sleeper bus leaves which day — Gujarat to Rupaidiha (Nepal border) and back — for the next two weeks.">
<link rel="icon" href="/favicon.ico">
<style>
:root{--blue:#2E5FA8;--deep:#12264E;--orange:#F07C1F;--bg:#f4f7fc;--card:#fff;--ink:#1b2436;--mut:#5b6577;--line:#dfe5ef}
@media (prefers-color-scheme:dark){:root{--bg:#0d1424;--card:#16203a;--ink:#e7edf8;--mut:#a9b6cc;--line:#26324f}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI","Noto Sans Devanagari",sans-serif}
header{background:var(--deep);color:#fff;padding:14px 16px}
header .wrap{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}
.brand{color:#fff;text-decoration:none;font-weight:800;letter-spacing:.04em}
.brand small{display:block;font-weight:600;opacity:.75;font-size:11px;letter-spacing:.08em}
.call{color:#fff;text-decoration:none;font-weight:700;background:var(--orange);padding:8px 12px;border-radius:10px;min-height:44px;display:inline-flex;align-items:center}
.wrap{max-width:980px;margin:0 auto}
main{padding:18px 16px 40px}
h1{margin:4px 0 4px;font-size:24px}
.lead{color:var(--mut);margin:0 0 16px}
.off{background:#fff4d6;color:#7a5200;border-radius:12px;padding:10px 12px;margin:0 0 16px}
.cols{display:grid;grid-template-columns:1fr;gap:18px}
@media (min-width:760px){.cols{grid-template-columns:1fr 1fr}}
h2{font-size:17px;margin:0 0 10px;display:flex;gap:8px;align-items:center}
.card{display:grid;gap:2px;background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;margin:0 0 10px;color:inherit;text-decoration:none;min-height:44px}
.card:hover,.card:focus{border-color:var(--blue);outline:none;box-shadow:0 2px 10px rgba(46,95,168,.15)}
.day{font-weight:800}
.run{color:var(--mut)}
.bus{font-size:13px;color:var(--mut)}
.go{color:var(--orange);font-weight:800;margin-top:4px}
.none{color:var(--mut)}
footer{color:var(--mut);font-size:13px;text-align:center;padding:0 16px 24px}
footer a{color:var(--blue)}
</style>
</head>
<body>
<header><div class="wrap">
  <a class="brand" href="/">S HARI GLOBAL™<small>PRIVATE LIMITED · INDIA ⇄ NEPAL</small></a>
  <?php if ($phone !== ''): ?><a class="call" href="tel:+91<?= $e(preg_replace('/\D/', '', $phone) ?? '') ?>">📞 <?= $e($phone) ?></a><?php endif; ?>
</div></header>
<main class="wrap">
  <h1>🗓️ कुन दिन कुन बस · Bus Routine</h1>
  <p class="lead">आउँदो दुई हप्ताका बस — दिन थिचेर सिधै सिट बुक गर्नुहोस् · The next two weeks, both ways. Tap a day to book.</p>
  <?php if (!$on): ?><p class="off"><?= $e($note !== '' ? $note : 'दैनिक सेवा अहिले बन्द छ · The daily service is paused right now.') ?></p><?php endif; ?>
  <div class="cols">
    <section><h2>🇮🇳 → 🇳🇵 भारत → नेपाल <small>(गुजरातबाट)</small></h2><?= $cards($go) ?></section>
    <section><h2>🇳🇵 → 🇮🇳 नेपाल → भारत <small>(रुपैडिहाबाट)</small></h2><?= $cards($back) ?></section>
  </div>
</main>
<footer>S Hari Global Pvt. Ltd. · <a href="/">टिकट बुक · Book tickets</a> · <?= $e(date('j M, H:i')) ?></footer>
</body>
</html>
