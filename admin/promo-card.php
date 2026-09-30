<?php
/**
 * admin/promo-card.php — a shareable "Private cabin" promo card (23 Sep 2026,
 * UI/UX v3 brief §6): one PNG the office can drop into WhatsApp / social.
 *
 *   ?img=1        the PNG itself (inline)      ?img=1&dl=1   download
 *   (no args)     preview + Download + a WhatsApp share text
 *
 * Prices come from Fare::pricing() (the live cabin_pricing setting), so the
 * card can never quote a fare the checkout does not charge. Drawn with GD +
 * the Devanagari face already used by the tickets. Permission: dashboard.view.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$pricing = Fare::pricing();
$single  = (int) ($pricing['private']['single_1pax']['offline'] ?? 3800);
$double  = (int) ($pricing['private']['double_2pax']['offline'] ?? 7600);
$phone   = Settings::getString('company_phone', '+91 91048 01507');
$site    = preg_replace('#^https?://#', '', rtrim(APP_URL, '/')) ?: 'shreehariglobal.in';
$lang    = ($_GET['lang'] ?? 'en') === 'ne' ? 'ne' : (($_GET['lang'] ?? '') === 'hi' ? 'hi' : 'en');

require_once INCLUDE_PATH . '/promocard.php';

if (isset($_GET['img'])) {
    $png = PromoCard::png($lang, $single, $double, $phone, $site, Ticket::logoFile());
    header('Content-Type: image/png');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="shg-private-cabin-' . $lang . '.png"');
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . strlen($png));
    echo $png;
    exit;
}

$share = PromoCard::shareText($single, $double, $phone, $site);

admin_header('Promo card', 'promo-card');
admin_page_head('A shareable picture for WhatsApp status, groups and social — private cabin, live prices.', []);
?>
<div class="panel">
  <h2>👑 Private cabin promo card</h2>
  <div class="panel-body" style="display:grid;grid-template-columns:minmax(260px,420px) 1fr;gap:22px;align-items:start">
    <div><img src="<?= $base ?? '' ?>/admin/promo-card.php?img=1&amp;lang=<?= $lang ?>" alt="Promo card" style="width:100%;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)"></div>
    <div>
      <p class="muted">Language:
        <?php foreach (['en' => 'English', 'hi' => 'हिन्दी', 'ne' => 'नेपाली'] as $l => $lbl): ?>
          <a class="chip<?= $l === $lang ? ' on' : '' ?>" href="?lang=<?= $l ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </p>
      <p style="margin-top:14px"><a class="btn" href="?img=1&amp;dl=1&amp;lang=<?= $lang ?>">⬇️ Download PNG (1080×1350)</a>
         <a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($share) ?>">💬 Share text on WhatsApp</a></p>
      <p class="muted text-xs" style="margin-top:12px">Download the picture, then attach it to the WhatsApp message (status, group or a customer). Prices are read live from Settings → cabin pricing.</p>
      <pre style="margin-top:12px;white-space:pre-wrap;background:var(--head);padding:12px;border-radius:10px;font-size:13px"><?= Security::e($share) ?></pre>
    </div>
  </div>
</div>
<?php admin_footer(); ?>
