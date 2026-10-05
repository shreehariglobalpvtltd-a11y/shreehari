<?php
/**
 * admin/promo-card.php — shareable promo pictures.
 *
 *   1. 🪔 The running OFFER pamphlet (6 Oct 2026): drawn from the offer that
 *      is live in Admin → Offers + the live direction fares (includes/
 *      offercard.php). Download for Facebook / WhatsApp status, copy the
 *      caption, and email it to every customer who left an address.
 *        ?offer=1&img=1[&dl=1]&lang=ne|hi|en   the PNG
 *   2. 👑 The "Private cabin" card (23 Sep 2026, UI/UX v3 brief §6).
 *        ?img=1[&dl=1]&lang=en|hi|ne           the PNG
 *
 * Prices come from Fare::pricing() / Fare::dirFares(), so a card can never
 * quote a fare the checkout does not charge. Permission: dashboard.view;
 * emailing customers needs superadmin or marketing.send.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

$pricing = Fare::pricing();
$single  = (int) ($pricing['private']['single_1pax']['offline'] ?? 3800);
$double  = (int) ($pricing['private']['double_2pax']['offline'] ?? 7600);
$phone   = Settings::getString('company_phone', '+91 91048 01507');
$site    = preg_replace('#^https?://#', '', rtrim(APP_URL, '/')) ?: 'shreehariglobal.in';
$site    = preg_replace('#^www\.#', '', $site) ?: $site;
$lang    = ($_GET['lang'] ?? 'en') === 'ne' ? 'ne' : (($_GET['lang'] ?? '') === 'hi' ? 'hi' : 'en');

require_once INCLUDE_PATH . '/promocard.php';
require_once INCLUDE_PATH . '/offercard.php';
require_once INCLUDE_PATH . '/ticket.php';

$offer  = OfferCard::offer();
$olang  = in_array($_GET['olang'] ?? '', OfferCard::LANGS, true) ? (string) $_GET['olang'] : 'ne';
$mayAct = Auth::isSuperadmin() || Auth::can('marketing.send');

if (isset($_GET['img'])) {
    if (isset($_GET['offer'])) {
        if ($offer === null) {
            http_response_code(404);
            exit('No offer is running.');
        }
        $ol  = in_array($_GET['lang'] ?? '', OfferCard::LANGS, true) ? (string) $_GET['lang'] : 'ne';
        $png = OfferCard::png($ol, $offer, $phone, $site, Ticket::logoFile());
        $fn  = 'shg-offer-' . strtolower((string) $offer['code']) . '-' . $ol . '.png';
    } else {
        $png = PromoCard::png($lang, $single, $double, $phone, $site, Ticket::logoFile());
        $fn  = 'shg-private-cabin-' . $lang . '.png';
    }
    header('Content-Type: image/png');
    header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $fn . '"');
    header('Cache-Control: private, max-age=300');
    header('Content-Length: ' . strlen($png));
    echo $png;
    exit;
}

/** Every customer email we hold, once each (booking contact, app sign-in, office record). */
function pc_customer_emails(): array
{
    $out = [];
    foreach ([
        "SELECT DISTINCT LOWER(TRIM(contact_email)) e FROM bookings WHERE contact_email LIKE '%_@_%._%'",
        "SELECT DISTINCT LOWER(TRIM(email)) e FROM users WHERE email LIKE '%_@_%._%'",
        "SELECT DISTINCT LOWER(TRIM(email)) e FROM user_profiles WHERE email LIKE '%_@_%._%'",
    ] as $sql) {
        try {
            foreach (Database::fetchAll($sql) as $r) {
                $e = Security::email((string) $r['e']);
                if ($e !== '') {
                    $out[$e] = true;
                }
            }
        } catch (Throwable $e) {
            // a table without the column simply adds nobody
        }
    }
    $staff = [];
    try {
        foreach (Database::fetchAll("SELECT LOWER(email) e FROM admins WHERE email IS NOT NULL AND email <> ''") as $r) {
            $staff[(string) $r['e']] = true;
        }
    } catch (Throwable $e) {
    }
    return array_values(array_filter(array_keys($out), static fn (string $e): bool => !isset($staff[$e])));
}

/** The offer email: the caption as HTML + the pamphlet attached. */
function pc_offer_mail(array $offer, string $to, string $phone, string $site): bool
{
    $cap  = OfferCard::caption($offer, $phone, $site);
    $amt  = OfferCard::amount($offer);
    $subj = '🪔 ' . (OfferCard::festive($offer) ? 'दशैं–तिहार अफर' : 'विशेष अफर') . ': ' . $amt . ' छुट — S Hari Global';
    $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;border:3px solid #FFD166;border-radius:14px;overflow:hidden">'
          . '<div style="background:#7a1020;color:#FFD166;padding:18px 20px;font-size:22px;font-weight:bold">🪔 ' . Security::e(OfferCard::festive($offer) ? 'दशैं–तिहार अफर' : 'विशेष अफर') . ' — ' . Security::e($amt) . ' छुट</div>'
          . '<div style="padding:18px 20px;font-size:15px;line-height:1.6;color:#222">' . nl2br(Security::e($cap)) . '</div>'
          . '<div style="padding:0 20px 20px"><a href="https://' . Security::e($site) . '/?src=offer_mail" style="display:inline-block;background:#ff7a00;color:#fff;text-decoration:none;font-weight:bold;padding:12px 22px;border-radius:999px">अहिले बुक गर्नुहोस् · Book now</a></div>'
          . '<div style="padding:10px 20px;background:#fff3d6;font-size:12px;color:#555">S Hari Global Pvt Ltd · ' . Security::e($phone) . ' · यो इमेल नचाहिए reply मा STOP लेख्नुहोस्।</div></div>';
    $png  = OfferCard::png('ne', $offer, $phone, $site, Ticket::logoFile());
    return Notify::email($to, $subj, $html, $cap . "\n\nTo stop these emails, reply STOP.",
        [['name' => 'shg-offer-' . strtolower((string) $offer['code']) . '.png', 'mime' => 'image/png', 'data' => $png]]);
}

$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$mayAct) {
        $flash = ['bad', 'Only the owner or a manager (marketing.send) can email customers.'];
    } elseif ($offer === null) {
        $flash = ['bad', 'No offer is running — create one in Admin → Offers first.'];
    } elseif ($act === 'mail_test') {
        $to = Security::email((string) ($_POST['to'] ?? '')) ?: Security::email(Settings::getString('company_email', ''));
        $ok = $to !== '' && pc_offer_mail($offer, $to, $phone, $site);
        Logger::audit('offer.mail_test', 'coupon', (string) $offer['code'], null, ['to' => $to, 'ok' => $ok], 'by admin #' . (int) $admin['id']);
        $flash = $ok ? ['ok', 'Test email handed to the mail service for ' . $to . '. Check that inbox.'] : ['bad', 'The test email was NOT sent (check Notify / Brevo settings).'];
    } elseif ($act === 'mail_all') {
        $sent = $skip = $fail = 0;
        $doneKey = 'offer_mail:' . $offer['code'];
        $done = json_decode((string) (Database::scalar('SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k', ['s' => 'offer', 'k' => $doneKey]) ?? ''), true);
        $done = is_array($done) ? $done : [];
        foreach (pc_customer_emails() as $to) {
            if (isset($done[$to])) {
                $skip++;
                continue;
            }
            if (pc_offer_mail($offer, $to, $phone, $site)) {
                $sent++;
                $done[$to] = date('c');
            } else {
                $fail++;
            }
        }
        Database::run('INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES (:s, :k, :v, :u)
                       ON DUPLICATE KEY UPDATE kvalue = VALUES(kvalue), updated_by = VALUES(updated_by)',
            ['s' => 'offer', 'k' => $doneKey, 'v' => json_encode($done), 'u' => 'admin#' . (int) $admin['id']]);
        Logger::audit('offer.mail_all', 'coupon', (string) $offer['code'], null, ['sent' => $sent, 'skipped' => $skip, 'failed' => $fail], 'by admin #' . (int) $admin['id']);
        $flash = ['ok', 'Offer email: ' . $sent . ' sent, ' . $skip . ' already had it, ' . $fail . ' failed.'];
    }
}

$share   = PromoCard::shareText($single, $double, $phone, $site);
$caption = $offer !== null ? OfferCard::caption($offer, $phone, $site) : '';
$emails  = $offer !== null ? pc_customer_emails() : [];

admin_header('Promo card', 'promo-card');
admin_page_head('Shareable pictures for Facebook, WhatsApp status, groups and email — live offer and prices.', []);
?>
<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= Security::e($flash[1]) ?></div><?php endif; ?>

<div class="panel">
  <h2>🪔 Offer pamphlet</h2>
  <?php if ($offer === null): ?>
    <p class="muted">No offer is running today. Create or switch one on in <a href="/admin/offers.php">Admin → Offers</a> and the pamphlet appears here.</p>
  <?php else: ?>
  <div class="panel-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:22px;align-items:start">
    <div><img src="/admin/promo-card.php?offer=1&amp;img=1&amp;lang=<?= $olang ?>" alt="Offer pamphlet" style="width:100%;max-width:440px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)"></div>
    <div>
      <p><b><?= Security::e(Fare::offerLine($offer)) ?></b></p>
      <p class="muted">Language:
        <?php foreach (['ne' => 'नेपाली', 'hi' => 'हिन्दी', 'en' => 'English'] as $l => $lbl): ?>
          <a class="chip<?= $l === $olang ? ' on' : '' ?>" href="?olang=<?= $l ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </p>
      <p style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
        <a class="btn" href="?offer=1&amp;img=1&amp;dl=1&amp;lang=<?= $olang ?>">⬇️ Download PNG (1080×1350)</a>
        <a class="btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($caption) ?>">💬 WhatsApp text</a>
        <a class="btn ghost" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode('https://' . $site . '/?src=fb_offer') ?>">📘 Share on Facebook</a>
      </p>
      <p class="muted text-xs" style="margin-top:10px"><b>Facebook / Instagram / WhatsApp status:</b> download the picture, post it, and paste the caption below. The picture and caption change by themselves if the offer or the fares change.</p>
      <textarea readonly style="width:100%;min-height:210px;margin-top:8px;padding:10px;border-radius:10px;font:inherit;font-size:13px" onclick="this.select()"><?= Security::e($caption) ?></textarea>

      <h3 style="margin:16px 0 6px;font-size:15px">📧 Email the offer</h3>
      <p class="muted text-xs"><?= count($emails) ?> customer email address(es) on file (bookings, app sign-ins, office records; staff excluded). Each address gets this offer once.</p>
      <?php if ($mayAct): ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <?= Security::csrfField() ?><input type="hidden" name="action" value="mail_test">
        <input name="to" type="email" placeholder="<?= Security::e(Settings::getString('company_email', 'you@example.com')) ?>" style="min-width:220px">
        <button class="btn ghost" type="submit">Send me a test</button>
      </form>
      <form method="post" style="margin-top:8px" onsubmit="return confirm('Email the offer to <?= count($emails) ?> customer(s)?')">
        <?= Security::csrfField() ?><input type="hidden" name="action" value="mail_all">
        <button class="btn" type="submit" <?= $emails === [] ? 'disabled' : '' ?>>📧 Email all <?= count($emails) ?> customers</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <h2>👑 Private cabin promo card</h2>
  <div class="panel-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));gap:22px;align-items:start">
    <div><img src="/admin/promo-card.php?img=1&amp;lang=<?= $lang ?>" alt="Promo card" style="width:100%;max-width:420px;border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.25)"></div>
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
