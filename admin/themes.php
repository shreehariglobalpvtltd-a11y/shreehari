<?php
/**
 * admin/themes.php — the weekly colours the customer app shows
 * (16 Nov 2026, Master Upgrade v2.0 P5-7a).
 *
 * The customer app paints one accent colour per weekday, Asia/Kolkata
 * (assets/css/ds.css "Weekly accent" blocks, stamped before paint by the
 * inline script in app.template.html: IST weekday -> sun..sat). The office
 * had no place to see them. This page shows:
 *   - the seven accents, light + dark, read straight from ds.css (so it can
 *     never drift from what customers get);
 *   - the next 7 IST dates with the accent each day gets, and any festival
 *     date from AI Marketing (MkAutopilot::festivals) on those days;
 *   - a Preview link per accent: /?accent_preview=<key> opens the customer
 *     app in that colour for 5 minutes, in that browser tab only
 *     (sessionStorage 'shg:accentPreview', app.template.html). Nothing is
 *     saved and no customer sees it.
 *
 * Read-only: no POST, no setting is written. Same gate as easy-settings.php.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$e = static fn($v): string => Security::e((string) $v);

/* The day names and colour names, Nepali first. The English colour name is
   read from the ds.css comment ("Mon blue, Tue teal, ..."), this is only the
   fallback and the Nepali word. */
$days = [
    'sun' => ['आइतबार', 'Sunday'],   'mon' => ['सोमबार', 'Monday'],   'tue' => ['मंगलबार', 'Tuesday'],
    'wed' => ['बुधबार', 'Wednesday'], 'thu' => ['बिहीबार', 'Thursday'], 'fri' => ['शुक्रबार', 'Friday'],
    'sat' => ['शनिबार', 'Saturday'],
];
$colourNe = [
    'blue' => 'नीलो', 'teal' => 'टिल (हरियो-नीलो)', 'violet' => 'बैजनी', 'green' => 'हरियो',
    'indigo' => 'गाढा नीलो', 'burnt orange' => 'गाढा सुन्तला', 'rose' => 'गुलाबी',
];
$colourEn = ['mon' => 'blue', 'tue' => 'teal', 'wed' => 'violet', 'thu' => 'green', 'fri' => 'indigo', 'sat' => 'burnt orange', 'sun' => 'rose'];

/* ---- the accents, from ds.css ---- */
$css = (string) @file_get_contents(dirname(__DIR__) . '/assets/css/ds.css');
if (preg_match('~Weekly accent[^\n]*\n\s*(Mon [^\n]+?)\.?\s*\n~', $css, $cm) === 1) {
    foreach (explode(',', $cm[1]) as $part) {
        if (preg_match('/^\s*(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\s+([a-z][a-z ]{1,30}?)\s*$/', $part, $pm) === 1) {
            $colourEn[strtolower($pm[1])] = $pm[2];
        }
    }
}
$hex = static fn(string $v): string => preg_match('/^#[0-9A-Fa-f]{3,8}$/', trim($v)) === 1 ? trim($v) : '';
$accents = [];   // key => ['light' => [var => #hex], 'dark' => [...]] in ds.css order
if (preg_match_all('~\[data-theme="(light|dark)"\]\[data-accent="([a-z]{3})"\]\{([^}]*)\}~', $css, $bm, PREG_SET_ORDER) > 0) {
    foreach ($bm as $b) {
        if (!isset($days[$b[2]])) {
            continue;
        }
        $vars = [];
        if (preg_match_all('/--([a-z-]+)\s*:\s*([^;]+)/', $b[3], $vm, PREG_SET_ORDER) > 0) {
            foreach ($vm as $v) {
                $vars[$v[1]] = $hex($v[2]);
            }
        }
        $accents[$b[2]][$b[1]] = $vars;
    }
}
$accents = array_filter($accents, static fn(array $a): bool => ($a['light']['royal'] ?? '') !== '' && ($a['dark']['royal'] ?? '') !== '');

/* ---- the next 7 days, India time (the template's rule: IST weekday) ---- */
$tz    = new DateTimeZone('Asia/Kolkata');
$now   = new DateTimeImmutable('now', $tz);
$today = $now->format('Y-m-d');
$week  = [];
for ($i = 0; $i < 7; $i++) {
    $d = $now->modify('+' . $i . ' day');
    $week[] = ['date' => $d->format('Y-m-d'), 'key' => strtolower($d->format('D')), 'label' => $d->format('D, j M')];
}

/* ---- festival dates (AI Marketing), if that module is installed ---- */
$festivals = [];
try {
    if (!class_exists('MkAutopilot') && is_file(INCLUDE_PATH . '/mkautopilot.php')) {
        require_once INCLUDE_PATH . '/mkautopilot.php';
    }
    if (class_exists('MkAutopilot') && method_exists('MkAutopilot', 'festivals')) {
        $festivals = MkAutopilot::festivals();
    }
} catch (Throwable $ex) {
    $festivals = [];
}
$festOn = [];
$festNext = [];
$horizon = $now->modify('+120 day')->format('Y-m-d');
foreach ($festivals as $f) {
    $fd = (string) ($f['date'] ?? '');
    $fn = (string) ($f['name'] ?? '');
    if ($fd === '' || $fn === '') {
        continue;
    }
    $festOn[$fd][] = $fn;
    if ($fd >= $today && $fd <= $horizon && count($festNext) < 4) {
        $fdt = DateTimeImmutable::createFromFormat('!Y-m-d', $fd, $tz);
        if ($fdt !== false) {
            $festNext[] = ['date' => $fd, 'name' => $fn, 'key' => strtolower($fdt->format('D')), 'label' => $fdt->format('D, j M Y')];
        }
    }
}

$todayKey = $week[0]['key'];
$dot = static fn(string $key) => '<i class="th-dot" style="background:' . Security::e($accents[$key]['light']['royal'] ?? '#12264E') . '" aria-hidden="true"></i>';
$nameOf = static function (string $key) use ($colourEn, $colourNe): string {
    $en = (string) ($colourEn[$key] ?? '');
    return trim(($colourNe[$en] ?? '') . ' · ' . ucfirst($en), ' ·');
};

admin_header('Colours & Themes · रङ र थिम', 'themes.php');
?>
<style>
.th-intro{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px 16px;margin:0 0 16px;font-size:14px;line-height:1.55}
.th-intro p{margin:0 0 8px}.th-intro p:last-child{margin:0}
.th-intro .en{color:var(--mut)}
.th-today{display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:15px;margin:0 0 16px}
.th-dot{display:inline-block;width:14px;height:14px;border-radius:50%;flex:0 0 auto;box-shadow:0 0 0 1px rgba(0,0,0,.12)}
.th-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:14px;margin:0 0 22px}
.th-card{background:var(--card);border:1px solid var(--line);border-radius:14px;overflow:hidden;min-width:0}
.th-card.on{box-shadow:0 0 0 2px var(--orange)}
.th-card header{display:flex;align-items:center;gap:8px;padding:10px 12px;border-bottom:1px solid var(--line)}
.th-card header b{font-size:14px}
.th-card header small{display:block;font-size:12px;color:var(--mut)}
.th-card header .tag{margin-left:auto;font-size:11px;font-weight:700;color:#fff;background:var(--orange);border-radius:99px;padding:2px 8px;white-space:nowrap}
.th-pair{display:grid;grid-template-columns:1fr 1fr}
.th-half{padding:12px 10px;min-width:0}
.th-half small{display:block;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin:0 0 8px;opacity:.75}
.th-btn{display:inline-block;padding:7px 12px;border-radius:9px;font-size:13px;font-weight:700;white-space:nowrap}
.th-chip{display:inline-block;margin-top:8px;padding:3px 9px;border-radius:99px;font-size:12px;font-weight:600}
.th-hex{display:block;margin-top:8px;font:12px ui-monospace,Menlo,Consolas,monospace;opacity:.8;overflow-wrap:anywhere}
.th-card footer{padding:10px 12px;border-top:1px solid var(--line);display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.th-card footer .btn{min-height:40px;display:inline-flex;align-items:center}
.th-days{list-style:none;margin:0;padding:0}
.th-days li{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 16px;border-bottom:1px solid var(--line);font-size:14px}
.th-days li:last-child{border-bottom:0}
.th-days .d{min-width:96px;font-weight:700;font-variant-numeric:tabular-nums}
.th-days .n{color:var(--mut);font-size:13px}
.th-days .fest{font-size:12px;font-weight:700;color:#7a4a00;background:#ffe6c7;border-radius:99px;padding:2px 9px}
.th-days .now{font-size:11px;font-weight:700;color:#fff;background:var(--navy);border-radius:99px;padding:2px 8px}
:root[data-theme="dark"] .th-days .fest{color:#ffd9a8;background:#3a2a12}
:root[data-theme="dark"] .th-days .now{background:#2E5FA8}
@media(max-width:420px){.th-days .d{min-width:0;flex-basis:100%}}
</style>

<div class="th-intro">
  <p>ग्राहक एपको मुख्य रङ हप्ताको दिनअनुसार आफैं फेरिन्छ (भारतीय समय, रातको १२ बजे)। लोगो, नेभी बार, सिटका रङ र सफल / चेतावनी रङ कहिल्यै फेरिँदैनन्।
    <span class="en">The customer app's accent colour changes by itself with the weekday (India time, at midnight). The logo, the navy bar, seat colours and success / warning colours never change.</span></p>
  <p>ग्राहकले "Fixed · एउटै रङ" वा आफ्नै रङ (palette) छानेको छ भने हप्ताको रङ देखिँदैन; काउन्टर मोडमा पनि देखिँदैन।
    <span class="en">A customer who picked "Fixed", or picked a colour palette of their own, does not get the weekly colour; counter mode never does either.</span></p>
  <p><b>Preview</b>: नयाँ ट्याबमा ग्राहक एप त्यो रङमा ५ मिनेट देखिन्छ — त्यही ट्याबमा मात्र। केही सेभ हुँदैन, कुनै ग्राहकले देख्दैन।
    <span class="en">Opens the customer app in a new tab in that colour for 5 minutes, in that tab only. Nothing is saved and no customer sees it.</span></p>
  <p class="en">यो पेज हेर्ने मात्र हो, यहाँबाट केही बदलिँदैन। · This page only shows the colours; it changes nothing.</p>
</div>

<?php if ($accents === []): ?>
  <div class="flash bad">रङ पढ्न सकिएन (assets/css/ds.css)। · The weekly colours could not be read from assets/css/ds.css.</div>
<?php else: ?>
  <p class="th-today"><?= $dot($todayKey) ?>
    <span><b>आज <?= $e($days[$todayKey][0] ?? '') ?></b> · Today <?= $e($days[$todayKey][1] ?? '') ?>: <?= $e($nameOf($todayKey)) ?></span></p>

  <div class="th-grid">
  <?php foreach ($accents as $key => $a): $l = $a['light']; $d = $a['dark']; ?>
    <section class="th-card<?= $key === $todayKey ? ' on' : '' ?>" aria-label="<?= $e(($days[$key][1] ?? $key) . ' ' . ($colourEn[$key] ?? '')) ?>">
      <header><?= $dot($key) ?><div><b><?= $e($days[$key][0] ?? '') ?> · <?= $e($days[$key][1] ?? $key) ?></b><small><?= $e($nameOf($key)) ?></small></div>
        <?php if ($key === $todayKey): ?><span class="tag">आज · Today</span><?php endif; ?></header>
      <div class="th-pair">
        <div class="th-half" style="background:<?= $e($l['royal-tint'] ?? '#FFFFFF') ?>;color:#1b2436">
          <small>Light · उज्यालो</small>
          <span class="th-btn" style="background:<?= $e($l['royal']) ?>;color:<?= $e($l['on-royal'] ?? '#FFFFFF') ?>">बुक · Book</span><br>
          <span class="th-chip" style="background:<?= $e($l['royal-soft'] ?? '') ?>;color:<?= $e($l['royal-strong'] ?? $l['royal']) ?>">छानिएको · Selected</span>
          <span class="th-hex"><?= $e($l['royal']) ?></span>
        </div>
        <div class="th-half" style="background:<?= $e($d['royal-tint'] ?? '#0B1220') ?>;color:#e8f0fb">
          <small>Dark · अँध्यारो</small>
          <span class="th-btn" style="background:<?= $e($d['royal']) ?>;color:<?= $e($d['on-royal'] ?? '#0B1220') ?>">बुक · Book</span><br>
          <span class="th-chip" style="background:<?= $e($d['royal-soft'] ?? '') ?>;color:<?= $e($d['royal-strong'] ?? $d['royal']) ?>">छानिएको · Selected</span>
          <span class="th-hex"><?= $e($d['royal']) ?></span>
        </div>
      </div>
      <footer>
        <a class="btn ghost" href="/?accent_preview=<?= $e($key) ?>" target="_blank" rel="noopener">Preview · हेर्नुहोस् ↗</a>
        <span class="muted" style="font-size:12px">५ मिनेट, यही ट्याब · 5 min, that tab</span>
      </footer>
    </section>
  <?php endforeach; ?>
  </div>

  <div class="panel">
    <h2>अर्को ७ दिन · Next 7 days (India time)</h2>
    <ul class="th-days">
    <?php foreach ($week as $i => $w): $k = $w['key']; ?>
      <li>
        <span class="d"><?= $e($w['label']) ?></span>
        <?= $dot($k) ?>
        <span><?= $e($days[$k][0] ?? '') ?> · <?= $e($nameOf($k)) ?></span>
        <?php if ($i === 0): ?><span class="now">आज · Today</span><?php endif; ?>
        <?php foreach ($festOn[$w['date']] ?? [] as $fn): ?><span class="fest"><?= $e($fn) ?></span><?php endforeach; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  </div>

  <div class="panel">
    <h2>चाडपर्व · Festival dates</h2>
    <?php if ($festNext === []): ?>
      <p class="muted" style="padding:12px 18px;margin:0">अर्को चार महिनामा कुनै चाड राखिएको छैन। · No festival date in the next four months.</p>
    <?php else: ?>
    <ul class="th-days">
      <?php foreach ($festNext as $f): ?>
      <li><span class="d"><?= $e($f['label']) ?></span><span class="fest"><?= $e($f['name']) ?></span>
        <span class="n"><?= $dot($f['key']) ?> <?= $e($days[$f['key']][0] ?? '') ?> · <?= $e($nameOf($f['key'])) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <p class="muted" style="font-size:12.5px;padding:10px 16px 14px;margin:0;border-top:1px solid var(--line)">
      चाडको दिन पनि हप्ताकै रङ रहन्छ। चाडका मिति AI Marketing मा मिलाइन्छ। · On a festival day the weekday colour stays. Festival dates are kept in AI Marketing<?php if (Auth::can('customers.view')): ?> (<a href="/admin/ai-marketing.php">AI Marketing</a>)<?php endif; ?>.
    </p>
  </div>
<?php endif; ?>
<?php admin_footer();
