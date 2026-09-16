<?php
/**
 * admin/_chalani-png-view.php — the chalani page picker.
 *
 * Included by admin/manifest.php's ?format=chalanipng branch (never
 * reachable on its own: it has no guard of its own because the including
 * page has already run one, and SHG_APP is checked below so a direct hit
 * cannot execute it).
 *
 * Owner ask, 10 Sep 2026: "page chutta chuttai download garna milos."
 * So every page of the chalani is drawn, shown at a size you can actually
 * read, and carries its OWN download button — the desk sends the driver
 * page 2 on WhatsApp without sending the whole bus.
 *
 * In scope from the includer: $pngPages, $pageCount, $pngError, $pngHref,
 * $trip, $rows, $totals, $date, $chalaniNo, $logoData, $pdfHref, $e.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

$co       = Settings::company();
$busNo    = trim((string) ($trip['bus_number'] ?? '')) ?: '-';
$routeTxt = trim((string) ($trip['from_city'] ?? '')) . ' → ' . trim((string) ($trip['to_city'] ?? ''));
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Chalani pages · <?= $e($date) ?> · <?= $e($busNo) ?></title>
<style>
  :root{
    --navy:#0b2a5b; --navy2:#12407f; --orange:#f07c1f; --ink:#15233b;
    --muted:#5b6b85; --line:#c9d3e2; --soft:#f3f6fb; --paper:#eef2f8; --card:#fff;
  }
  @media (prefers-color-scheme: dark){
    :root{ --ink:#e8edf6; --muted:#9fb0c8; --line:#2b3a52; --soft:#16202f; --paper:#0d1520; --card:#131d2b; }
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--paper);color:var(--ink);
       font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif}
  .wrap{max-width:1180px;margin:0 auto;padding:18px 16px 64px}
  header.top{background:var(--navy);color:#fff;border-radius:14px;padding:16px 20px;
             display:flex;gap:16px;align-items:center;flex-wrap:wrap}
  header.top img{height:52px;width:auto;background:#fff;border-radius:8px;padding:4px}
  header.top h1{margin:0;font-size:20px;letter-spacing:.3px}
  header.top .sub{color:#c7d6f0;font-size:13px;margin-top:2px}
  header.top .spacer{flex:1 1 auto}
  header.top .no{background:var(--navy2);border:1px solid #2b58a0;border-radius:10px;
                 padding:8px 14px;font-size:13px;color:#e8c15a;font-weight:700;white-space:nowrap}
  .bar{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0 6px;align-items:center}
  .btn{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);
       background:var(--card);color:var(--ink);border-radius:10px;padding:10px 14px;
       font-size:14px;font-weight:600;text-decoration:none;cursor:pointer;min-height:44px}
  .btn:hover{border-color:var(--navy2)}
  .btn.pri{background:var(--orange);border-color:var(--orange);color:#fff}
  .btn.nav{background:var(--navy);border-color:var(--navy);color:#fff}
  .hint{color:var(--muted);font-size:13px;margin:2px 0 18px}
  .page{background:var(--card);border:1px solid var(--line);border-radius:14px;
        overflow:hidden;margin-bottom:22px}
  .page .ph{display:flex;gap:12px;align-items:center;flex-wrap:wrap;
            padding:12px 16px;border-bottom:1px solid var(--line);background:var(--soft)}
  .page .ph b{font-size:15px}
  .page .ph .spacer{flex:1 1 auto}
  .page a.shot{display:block;background:#fff;line-height:0}
  .page img{width:100%;height:auto;display:block}
  .err{background:#fdecea;border:1px solid #f5c2bd;color:#8a1111;border-radius:10px;padding:12px 14px;margin:14px 0}
  @media (max-width:640px){ .page .ph{padding:10px 12px} .wrap{padding:12px 10px 48px} }
</style>
</head>
<body>
<div class="wrap">

  <header class="top">
    <?php if ($logoData !== ''): ?><img src="<?= $logoData ?>" alt=""><?php endif; ?>
    <div>
      <h1>Bus Chalani — page by page</h1>
      <div class="sub">
        <?= $e($routeTxt) ?> &nbsp;·&nbsp; <?= $e(formatDate($date, 'D, j M Y')) ?>
        &nbsp;·&nbsp; Bus <?= $e($busNo) ?> &nbsp;·&nbsp; <?= (int) count($rows) ?> passenger<?= count($rows) === 1 ? '' : 's' ?>
      </div>
    </div>
    <span class="spacer"></span>
    <span class="no"><?= $e($chalaniNo) ?></span>
  </header>

  <div class="bar">
    <a class="btn pri" href="#" id="dlAll">⬇️ Download all <?= (int) $pageCount ?> page<?= $pageCount === 1 ? '' : 's' ?></a>
    <a class="btn" href="<?= $e($pdfHref) ?>">📄 Nepali chalani (PDF)</a>
    <a class="btn nav" href="<?= $e($base) ?>/admin/manifest.php?<?= $e(http_build_query(['date' => $date, 'route' => (int) $routeId])) ?>">← Back to manifest</a>
  </div>
  <p class="hint">
    Each page is one picture, sized like an A4 landscape sheet — tap a page to open it full size, or use its own
    download button to send just that page on WhatsApp. The pictures redraw themselves whenever a seat, a name,
    a payment or the driver changes. Passenger names print in the script they were typed in; the column heads
    stay English for the border desk, and the signed Nepali sheet is the PDF.
  </p>

  <?php if ($pngError !== ''): ?>
    <div class="err">Some pages could not be drawn: <?= $e($pngError) ?></div>
  <?php endif; ?>

  <?php foreach ($pngPages as $i => $pg): $p = $i + 1; ?>
    <section class="page">
      <div class="ph">
        <b>Page <?= (int) $p ?> of <?= (int) $pageCount ?></b>
        <span class="spacer"></span>
        <a class="btn" target="_blank" rel="noopener" href="<?= $e($pngHref($p, false)) ?>">🔍 Open full size</a>
        <a class="btn pri dl" href="<?= $e($pngHref($p, true)) ?>">⬇️ Download page <?= (int) $p ?></a>
      </div>
      <a class="shot" target="_blank" rel="noopener" href="<?= $e($pngHref($p, false)) ?>">
        <img loading="lazy" decoding="async" alt="Chalani page <?= (int) $p ?>" src="<?= $e($pngHref($p, false)) ?>">
      </a>
    </section>
  <?php endforeach; ?>

</div>
<script>
/* "Download all" = click each page's own download link, spaced out. Browsers
   ask once before allowing several downloads from one page; a single click
   per link is what they expect to see, so no synthetic <a download> tricks. */
document.getElementById('dlAll').addEventListener('click', function (ev) {
  ev.preventDefault();
  var links = Array.prototype.slice.call(document.querySelectorAll('a.dl'));
  links.forEach(function (a, i) {
    setTimeout(function () {
      var f = document.createElement('iframe');
      f.style.display = 'none';
      f.src = a.getAttribute('href');
      document.body.appendChild(f);
      setTimeout(function () { f.remove(); }, 60000);
    }, i * 700);
  });
});
</script>
</body></html>
