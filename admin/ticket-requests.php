<?php
/**
 * admin/ticket-requests.php — passengers' "change my date" and "cancel a
 * seat" REQUESTS (P6, 15 Nov 2026).
 *
 * A passenger asks from their ticket page (ticket/view.php ->
 * api/ticket-request.php); the request lands here and rings the bell.
 * Owner's standing rule: date changes, cancellations and refunds are
 * settled BY HAND. This page therefore changes nothing on the ticket. Staff
 * call or WhatsApp the passenger, do the change with the existing tools
 * (the booking page, Change Date / Reschedule, the refund desk), and then
 * close the request here: "Done" with what was done, or "Reject" with why.
 * Both are POST + CSRF and written to the audit log.
 *
 * Who: the refund-desk permission (refunds.view) opens the list - the same
 * office roles as admin/refunds.php, never a field agent. Closing a request
 * needs a role that may change tickets (bookings.edit) or settle refunds
 * (refunds.process); support may read and call.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('refunds.view');

require_once INCLUDE_PATH . '/ticketrequests.php';

$base   = '';   // root-relative: the panel must stay on the request host
$flash  = null;
$me     = (int) $admin['id'];
$canAct = Auth::can('bookings.edit') || Auth::can('refunds.process');
$ready  = TicketRequests::ready();
/* Counter desk isolation (setting counter_desk_isolation, 7 Oct 2026): a
   counter clerk sees and closes only their own desk's tickets here too, as
   on the booking register, the booking page and payments. Null = everyone. */
$deskScope = Auth::deskScopeCode();
if ($deskScope !== null && !CounterDesk::stampColumn()) {
    $deskScope = null;
}
$postStr = static fn(string $key): string => is_scalar($_POST[$key] ?? null) ? (string) $_POST[$key] : '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. · सत्र सकियो, फेरि प्रयास गर्नुहोस्।'];
    } else {
        try {
            if (!$canAct) {
                throw new RuntimeException('Your role may read these requests but not close them. · तपाईंको भूमिकाले हेर्न मात्र मिल्छ।');
            }
            $reqId = (int) $postStr('id');
            if ($deskScope !== null && $ready) {
                $reqBk = (int) Database::scalar('SELECT booking_id FROM ticket_requests WHERE id = :i', ['i' => $reqId], 0);
                if ($reqBk > 0 && !CounterDesk::bookingVisible($reqBk)) {
                    throw new RuntimeException('That request is for another desk’s ticket. · यो अनुरोध अर्को काउन्टरको टिकटको हो।');
                }
            }
            $act    = $postStr('action');
            $status = $act === 'done' ? 'done' : ($act === 'reject' ? 'rejected' : '');
            $row    = TicketRequests::decide($reqId, $status, $postStr('staff_note'), $me);
            $flash  = ['ok', ($status === 'done' ? 'Marked done · भयो' : 'Rejected · अस्वीकार गरियो') . ' — ' . (string) $row['pnr'] . ' (#' . (int) $row['id'] . ')'];
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$tabs = [
    'open'     => 'खुला · Open',
    'done'     => 'भयो · Done',
    'rejected' => 'अस्वीकार · Rejected',
    'all'      => 'सबै · All',
];
$tabQ   = is_string($_GET['status'] ?? null) ? (string) $_GET['status'] : '';
$tab    = isset($tabs[$tabQ]) ? $tabQ : 'open';
$counts = TicketRequests::counts($deskScope);
$rows   = TicketRequests::listFor($tab, 200, $deskScope);
$csrf   = Security::e(Security::csrfToken());
$k      = CSRF_TOKEN_NAME;

/** "3 h ago" from a timestamp; '' for none. */
$age = static function (?string $at): string {
    if ($at === null || $at === '') {
        return '';
    }
    $s = max(0, time() - (int) strtotime($at));
    return match (true) {
        $s < 3600  => max(1, intdiv($s, 60)) . ' min',
        $s < 86400 => intdiv($s, 3600) . ' h',
        default    => intdiv($s, 86400) . ' d',
    };
};

admin_header('Ticket Requests', 'ticket-requests');
?>

<style>
  .trq{--tr-ok:#0a6b3b;--tr-okbg:#d7f4e3;--tr-bad:#8a1f1f;--tr-badbg:#f7dcdc;--tr-warn:#7a4300;--tr-warnbg:#fff1dc;--tr-info:#1d4fa3;--tr-infobg:#e6eefb}
  :root[data-theme="dark"] .trq{--tr-ok:#8fe0b4;--tr-okbg:#12321f;--tr-bad:#ffb4b4;--tr-badbg:#3a1616;--tr-warn:#ffd9a8;--tr-warnbg:#3b2a12;--tr-info:#b9d0ff;--tr-infobg:#16264a}
  .trq .tr-tabs{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 12px}
  .trq .tr-tabs .btn{min-height:44px;display:inline-flex;align-items:center}
  .trq .tr-help{font-size:13.5px;line-height:1.6;max-width:820px;margin:0 0 4px;color:var(--ink)}
  .trq .tr-card{border:1px solid var(--line);border-left:5px solid var(--tr-info);border-radius:14px;background:var(--card);color:var(--ink);padding:14px 16px;margin:0 0 12px}
  .trq .tr-card.cancel_seats{border-left-color:var(--tr-warn)}
  .trq .tr-card.closed{opacity:.92;border-left-color:var(--line)}
  .trq .tr-card h3{margin:0 0 6px;font-size:15.5px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  .trq .tr-card h3 a{font-family:ui-monospace,Menlo,Consolas,monospace;overflow-wrap:anywhere}
  .trq .tr-card p{margin:0 0 8px;font-size:13.5px;line-height:1.55;overflow-wrap:anywhere}
  .trq .tr-tag{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:999px;background:var(--tr-infobg);color:var(--tr-info);white-space:nowrap}
  .trq .cancel_seats .tr-tag{background:var(--tr-warnbg);color:var(--tr-warn)}
  .trq .tr-age{margin-left:auto;font-size:12px;color:var(--mut);white-space:nowrap}
  .trq .tr-pill{font-size:11.5px;font-weight:800;padding:3px 10px;border-radius:999px;white-space:nowrap}
  .trq .tr-pill.open{background:var(--tr-infobg);color:var(--tr-info)}
  .trq .tr-pill.done{background:var(--tr-okbg);color:var(--tr-ok)}
  .trq .tr-pill.rejected{background:var(--tr-badbg);color:var(--tr-bad)}
  .trq .tr-warnline{display:inline-block;background:var(--tr-badbg);color:var(--tr-bad);border-radius:8px;padding:2px 8px;font-weight:700;font-size:12.5px}
  .trq .tr-quote{display:block;border-left:3px solid var(--line);padding:2px 0 2px 10px;margin:6px 0 0;color:var(--ink)}
  .trq .tr-act{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 8px}
  .trq .tr-act .btn,.trq .tr-form .btn{min-height:44px;display:inline-flex;align-items:center;gap:6px}
  .trq .tr-form{display:flex;gap:8px;flex-wrap:wrap;align-items:stretch}
  .trq .tr-form input[type=text]{flex:1 1 220px;min-width:0;min-height:44px;padding:9px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:14px}
  .trq .tr-form .ok{background:var(--tr-ok);border-color:var(--tr-ok);color:#fff}
  :root[data-theme="dark"] .trq .tr-form .ok{color:#0b1220}
  .trq .tr-form .bad{background:var(--card);border:1px solid var(--tr-bad);color:var(--tr-bad)}
  .trq .tr-closed{font-size:12.5px;color:var(--mut)}
  @media (max-width:520px){ .trq .tr-card{padding:12px} .trq .tr-age{margin-left:0} .trq .tr-form .btn{flex:1 1 40%;justify-content:center} }
</style>

<div class="trq">
<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<div class="panel">
  <h2>यात्रुका अनुरोध · Passenger requests</h2>
  <p class="tr-help">Passengers ask from their own ticket page for <b>another date</b>, <b>another seat</b> or to <b>cancel seats</b>. These are only <b>requests — nothing on the ticket has changed</b>. Call or WhatsApp the passenger, do the change with the booking tools (booking page, Change Date, Refunds desk) under the refund rules, then press <b>Done</b> with what you did, or <b>Reject</b> with why. The note stays in the office (the passenger does not see it).</p>
  <p class="tr-help" style="color:var(--mut)">यी अनुरोध मात्र हुन् — टिकट आफैं बदलिएको छैन। यात्रुलाई फोन / WhatsApp गर्नुहोस्, बुकिङ पेजबाट परिवर्तन गर्नुहोस्, अनि के गरियो लेखेर “Done” वा किन मिलेन लेखेर “Reject” थिच्नुहोस्।</p>
  <?php if (!$ready): ?>
    <p class="flash bad" style="margin-top:10px">Not set up yet: run <code>database/upgrade-2026-11-15-ticket-requests.sql</code>. Until then the ticket page shows no request buttons.</p>
  <?php elseif (!TicketRequests::enabled()): ?>
    <p class="tr-help" style="margin-top:8px"><b>The ticket-page buttons are switched off</b> (setting <code>ticket_requests_on</code>). Requests already made still show below.</p>
  <?php endif; ?>
</div>

<div class="tr-tabs" role="tablist">
  <?php foreach ($tabs as $tk => $tl): ?>
    <a class="btn <?= $tk === $tab ? '' : 'ghost' ?>" role="tab" aria-selected="<?= $tk === $tab ? 'true' : 'false' ?>" href="?status=<?= Security::e($tk) ?>"><?= Security::e($tl) ?> (<?= (int) ($counts[$tk] ?? 0) ?>)</a>
  <?php endforeach; ?>
</div>

<div class="panel">
  <h2><?= Security::e($tabs[$tab]) ?> · <?= count($rows) ?></h2>
  <?php if ($rows === []): ?>
    <p class="muted"><?= $tab === 'open' ? 'Nothing waiting. · कुनै अनुरोध बाँकी छैन।' : 'Nothing here yet.' ?></p>
  <?php endif; ?>

  <?php foreach ($rows as $r):
    $id      = (int) $r['id'];
    $kind    = (string) $r['kind'];
    $kl      = TicketRequests::KINDS[$kind] ?? ['', $kind];
    $open    = (string) $r['status'] === 'open';
    $pnr     = (string) $r['pnr'];
    $mode    = (string) ($r['booking_mode'] ?? '');
    $wa      = TicketRequests::waDigits((string) $r['contact_phone'], (string) ($r['contact_country_code'] ?? ''));
    $tel     = $wa !== '' ? '+' . $wa : preg_replace('/\D+/', '', (string) $r['contact_phone']);
    $seatCsv = (string) ($r['seats'] ?? '');
    $nSeats  = $seatCsv === '' ? 0 : count(explode(',', $seatCsv));
    $total   = (int) ($r['seat_total'] ?? 0);
    $bStatus = (string) ($r['booking_status'] ?? '');
  ?>
    <div class="tr-card <?= Security::e($kind) ?><?= $open ? '' : ' closed' ?>" id="tr-<?= $id ?>">
      <h3>
        <span class="tr-tag"><?= match ($kind) { 'change_date' => '📅', 'change_seat' => '🔁', default => '🪑' } ?> <?= Security::e($kl[0] . ' · ' . $kl[1]) ?></span>
        <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($pnr) ?>"><?= Security::e($pnr) ?></a>
        <span class="tr-pill <?= Security::e((string) $r['status']) ?>"><?= Security::e((string) $r['status']) ?></span>
        <span class="tr-age" title="<?= Security::e((string) $r['created_at']) ?>">⏱ <?= Security::e($age((string) $r['created_at'])) ?> ago · #<?= $id ?></span>
      </h3>
      <p>
        <?php if (!empty($r['route']) || !empty($r['travel_date'])): ?>
          अहिले · Now: <b><?= Security::e(formatDate((string) ($r['travel_date'] ?? ''), 'D, j M Y')) ?></b><?= !empty($r['route']) ? ' · ' . Security::e((string) $r['route']) : '' ?><br>
        <?php endif; ?>
        <?php if ($kind === 'change_date'): ?>
          चाहिएको मिति · Wants: <b><?= Security::e(formatDate((string) ($r['wanted_date'] ?? ''), 'D, j M Y')) ?></b>
        <?php elseif ($kind === 'change_seat'): /* 16 Nov 2026 (P8-3e): labels as printed; check the seat map before moving */ ?>
          चाहिएको सिट · Wants seat: <b><?= Security::e(str_replace(',', ', ', (string) ($r['wanted_seats'] ?? ''))) ?></b>
          · अहिले · now: <?= Security::e(TicketRequests::seatLabels(implode(',', TicketRequests::seatsOf((int) $r['booking_id'])), $mode)) ?>
        <?php else: ?>
          रद्द गर्ने सिट · Cancel: <b><?= Security::e(TicketRequests::seatLabels($seatCsv, $mode)) ?></b>
          <?= $total > 0 ? ' (' . ($nSeats >= $total ? 'whole ticket · पूरा टिकट' : $nSeats . ' of ' . $total . ' seats') . ')' : '' ?>
        <?php endif; ?>
        <?php if ($bStatus !== '' && $bStatus !== 'confirmed'): ?>
          <br><span class="tr-warnline">Ticket is now <?= Security::e($bStatus) ?></span>
        <?php endif; ?>
        <?php if (trim((string) ($r['note'] ?? '')) !== ''): ?>
          <span class="tr-quote">“<?= Security::e((string) $r['note']) ?>”</span>
        <?php endif; ?>
      </p>
      <div class="tr-act">
        <?php if ($tel !== ''): ?><a class="btn ghost" href="tel:<?= Security::e($tel) ?>">📞 <?= Security::e((string) $r['contact_phone']) ?></a><?php endif; ?>
        <?php if ($wa !== ''): ?><a class="btn ghost" href="<?= Security::e(whatsappLink($wa, 'Namaste, S Hari office here about your request for ticket ' . $pnr . '.')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
        <a class="btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($pnr) ?>">🎫 Open ticket</a>
        <?php if ($open && $kind === 'change_date'): ?><a class="btn ghost" href="<?= $base ?>/admin/reschedule.php?pnr=<?= urlencode($pnr) ?>">📅 Change date</a><?php endif; ?>
      </div>
      <?php if ($open && $canAct): ?>
        <form method="post" class="tr-form" action="?status=<?= Security::e($tab) ?>#tr-<?= $id ?>">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php /* Enter in the note must not submit: the first button (Done) would close a request the staff meant to Reject. */ ?>
          <input type="text" name="staff_note" maxlength="255" aria-label="Staff note" enterkeyhint="done" onkeydown="if(event.key==='Enter'){event.preventDefault();}" placeholder="के गरियो / किन मिलेन? · What was done / why not (office only)">
          <button type="submit" class="btn ok" name="action" value="done">✓ Done · भयो</button>
          <button type="submit" class="btn bad" name="action" value="reject" onclick="var n=this.form.staff_note;if(n.value.trim().length<3){n.focus();alert('Write why it is not possible. · किन मिलेन लेख्नुहोस्।');return false;}return true;">✕ Reject</button>
        </form>
      <?php elseif ($open): ?>
        <p class="tr-closed">Your role may read and call; a manager, counter or accounts closes it.</p>
      <?php else: ?>
        <p class="tr-closed"><?= (string) $r['status'] === 'done' ? '✓ Done' : '✕ Rejected' ?>
          <?= !empty($r['handled_name']) || !empty($r['handled_user']) ? ' by ' . Security::e((string) ($r['handled_name'] ?: $r['handled_user'])) : '' ?>
          <?= !empty($r['handled_at']) ? ' · ' . Security::e($age((string) $r['handled_at'])) . ' ago' : '' ?>
          <?= trim((string) ($r['staff_note'] ?? '')) !== '' ? ' — ' . Security::e((string) $r['staff_note']) : '' ?></p>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
</div>

<?php admin_footer(); ?>
