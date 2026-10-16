<?php
/**
 * admin/wa-pending.php — tickets WhatsApp did not deliver (20 Sep 2026).
 *
 * Meta refuses every business-initiated message while the WhatsApp Business
 * Account has no payment method (error 131042), so a passenger who has never
 * written to us cannot be reached by the API at all. That is a billing wall,
 * not a bug, and it can last days.
 *
 * Meanwhile the tickets still have to arrive. This page is the desk's list of
 * exactly who is waiting, each with a one-tap WhatsApp link that opens the
 * chat on the staff phone with the whole ticket message already typed — a
 * person pressing send, which Meta never blocks. "Handed over" writes the
 * same message_logs row the automatic send would have, so the passenger drops
 * off this list and the retry cron stops chasing them.
 *
 * The moment billing works, this page empties itself: the cron drains the
 * backlog and nothing new lands here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');

require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';

$canSend = Auth::isSuperadmin() || Auth::can('bookings.edit') || Auth::can('payments.verify');
$flash   = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canSend) {
        $flash = ['bad', 'You may not mark tickets as handed over.'];
    } else {
        $bid = (int) ($_POST['booking_id'] ?? 0);
        $b   = $bid > 0 ? Database::fetch('SELECT * FROM bookings WHERE id = :i', ['i' => $bid]) : null;
        if ($b === null) {
            $flash = ['bad', 'That booking no longer exists.'];
        } else {
            /* The same row the automatic send writes, so every screen that
               reads delivery state agrees — and the retry cron lets go. */
            Notify::logOutbound(
                (string) $b['contact_phone'],
                'Ticket ' . (string) $b['pnr'] . ' handed over on WhatsApp by the desk.',
                'sent',
                ['provider' => 'manual', 'bookingId' => (int) $b['id'], 'purpose' => 'ticket']
            );
            Logger::audit('wa.handover', 'booking', (string) $b['pnr'], null,
                ['to' => (string) $b['contact_phone']], 'ticket sent by hand from wa-pending');
            $flash = ['ok', 'Marked — ' . (string) $b['pnr'] . ' is off the list.'];
        }
    }
}

/* Confirmed, still travelling, and the newest WhatsApp attempt failed —
   the same shape cron/whatsapp-retry.php uses to decide who is owed. */
/* A counter agent sees only the tickets they sold (bookings.view reaches
   this page, and the register rule is "an agent never reads another
   agent's book"); the office sees everyone's. b.* rides along so the
   WhatsApp number can be built per row without a second SELECT. */
$scopeId  = Auth::bookingScopeAdminId();
$scopeSql = $scopeId !== null ? ' AND b.sold_by_admin_id = :scope' : '';
$rows = Database::fetchAll(
    "SELECT b.*,
            m.error AS last_error, m.created_at AS last_try,
            (SELECT MIN(bl.travel_date) FROM booking_legs bl WHERE bl.booking_id = b.id) AS travel_date,
            (SELECT COUNT(*) FROM message_logs t WHERE t.booking_id = b.id AND t.channel = 'whatsapp') AS tries
       FROM bookings b
       JOIN message_logs m
         ON m.id = (SELECT m2.id FROM message_logs m2
                     WHERE m2.booking_id = b.id AND m2.channel = 'whatsapp'
                     ORDER BY m2.id DESC LIMIT 1)
      WHERE b.status = 'confirmed'
        AND m.status = 'failed'
        AND EXISTS (SELECT 1 FROM booking_legs bl WHERE bl.booking_id = b.id AND bl.travel_date >= CURDATE())"
    . $scopeSql . "
      ORDER BY travel_date ASC, b.id DESC
      LIMIT 200",
    $scopeId !== null ? ['scope' => $scopeId] : []
);

/** The message the desk will send, already written. */
$composeFor = static function (array $r): string {
    $company = Settings::getString('company_name', APP_NAME);
    $pnr     = (string) $r['pnr'];

    $lines = ['🚌 ' . $company, 'तपाईंको टिकट तयार छ।', '', 'बुकिङ नं.: ' . $pnr];
    if (!empty($r['travel_date'])) {
        $lines[] = 'मिति: ' . formatDate((string) $r['travel_date'], 'D, j M Y');
    }
    $lines[] = 'जम्मा: ' . inr((float) $r['total_amount']);
    $lines[] = '';
    $lines[] = 'ई-टिकट: ' . Ticket::imageUrl($pnr);
    $lines[] = 'प्रिन्ट (PDF): ' . Ticket::downloadUrl($pnr);
    $lines[] = '';
    $lines[] = 'परिचयपत्र साथमा राख्नुहोस्, बस छुट्नु ३० मिनेट अगाडि पुग्नुहोस्। शुभ यात्रा!';

    return implode("\n", $lines);
};

$blocked = 0;
foreach ($rows as $r) {
    if (str_contains((string) $r['last_error'], '131042')) {
        $blocked++;
    }
}

admin_header('Tickets to hand over', 'wa-pending');
?>
<style>
  .wp-note{background:#fff8e6;border:1px solid #f0d89a;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:13px;line-height:1.6}
  .wp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:18px}
  .wp-card{background:#fff;border:1px solid #e5e9f0;border-radius:12px;padding:14px}
  .wp-card .hv{font-size:22px;font-weight:700}
  .wp-msg{font-size:11px;color:#66708a;white-space:pre-wrap;max-height:60px;overflow:hidden}
</style>

<?php if ($flash !== null): ?>
  <div class="flash <?= Security::e($flash[0]) ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<div class="wp-grid">
  <div class="wp-card"><div class="hv"><?= count($rows) ?></div><div class="muted">Passengers waiting</div></div>
  <div class="wp-card"><div class="hv"><?= $blocked ?></div><div class="muted">Blocked by Meta billing</div></div>
</div>

<div class="wp-note">
  <strong>Why these did not go by themselves.</strong>
  Meta delivers a business-initiated WhatsApp message only when the WhatsApp Business Account has a
  working payment method. Until that card is accepted, a passenger who has never written to us cannot
  be reached by the system — Meta answers <span class="mono">131042</span>.
  <br>
  <strong>What works right now:</strong> tap <em>Send on WhatsApp</em>. The chat opens on this device with
  the whole message already typed; you press send, and it arrives like any personal message. Then tap
  <em>Handed over</em> so the passenger drops off this list.
</div>

<table class="dt">
  <thead><tr>
    <th>PNR</th><th>Travel</th><th>Phone</th><th>Amount</th><th>Last attempt</th><th style="min-width:260px">Action</th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="6" class="muted" style="padding:28px;text-align:center">
      Nothing waiting — every confirmed ticket has reached its passenger.
    </td></tr>
  <?php else: foreach ($rows as $r):
      /* whatsappNumberFor() applies the booking's own country hint, so a
         Nepali number does not get a 91 glued to the front. */
      $intl   = Notify::usablePhone((string) $r['contact_phone']) !== ''
              ? Notify::whatsappNumberFor($r)
              : '';
      $msg    = $composeFor($r);
      $link   = $intl !== '' ? whatsappLink($intl, $msg) : '';
  ?>
    <tr>
      <td class="mono">
        <a href="booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= Security::e((string) $r['pnr']) ?></a>
        <div class="muted" style="font-size:11px"><?= (int) $r['tries'] ?> tries</div>
      </td>
      <td><?= Security::e(formatDate((string) $r['travel_date'], 'D, j M')) ?></td>
      <td class="mono"><?= Security::e((string) $r['contact_phone']) ?></td>
      <td><?= Security::e(inr((float) $r['total_amount'])) ?></td>
      <td style="font-size:11px" class="muted">
        <?= Security::e(timeAgo((string) $r['last_try'])) ?>
        <div><?= Security::e(mb_substr((string) $r['last_error'], 0, 60)) ?></div>
      </td>
      <td>
        <?php if ($link !== ''): ?>
          <a class="btn" href="<?= Security::e($link) ?>" target="_blank" rel="noopener">💬 Send on WhatsApp</a>
          <?php if ($canSend): ?>
            <form method="post" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="booking_id" value="<?= (int) $r['id'] ?>">
              <button class="btn ghost" type="submit">Handed over</button>
            </form>
          <?php endif; ?>
        <?php else: ?>
          <span class="muted">no usable phone</span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody>
</table>

<?php admin_footer(); ?>
