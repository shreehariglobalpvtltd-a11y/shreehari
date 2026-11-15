<?php
/**
 * ticket/view.php?code=XXXXXXXXXX — the short ticket link (10 Oct 2026).
 *
 * What an agent forwards from their own WhatsApp, and what the passenger
 * opens on a phone: the ticket picture, the trip facts, the PDF, and two
 * click-to-chat buttons — "get it on WhatsApp" (sends "Hi <PNR>" to our
 * Meta number, whose free 24 h window then carries the ticket PNG at no
 * cost) and the office / the passenger's agent.
 *
 * The code is the key (includes/ticketlink.php): unknown, malformed or
 * expired codes all get the same neutral page, so the link never confirms
 * that a PNR exists. Like verify-ticket.php it shows first name + last
 * initial only — the phone number and ID never appear here.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/ticketlink.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/ticketrequests.php';   // 15 Nov 2026 (P6): date-change / seat-cancel REQUESTS

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');

$e = static fn(?string $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$limited = !Security::rateLimit('ticket_short', Security::clientIp(), 40, 60);
$code    = Security::clean((string) ($_GET['code'] ?? ''), 16);
$b       = (!$limited && $code !== '') ? TicketLink::resolve($code) : null;

$company = Settings::getString('company_name', APP_NAME);
$office  = Settings::officePhone();
require_once INCLUDE_PATH . '/sitefacts.php';
$metaNo  = SiteFacts::chatDigits();

$facts = null;
if ($b !== null) {
    $bid = (int) $b['id'];
    $pnr = (string) $b['pnr'];
    $leg = Database::fetch(
        'SELECT bl.travel_date, bl.boarding_stop, bl.drop_stop, bl.boarding_time, COALESCE(s.dep_time_override, r.dep_time) AS dep_time, r.from_city, r.to_city
           FROM booking_legs bl
           JOIN schedules s ON s.id = bl.schedule_id
           JOIN routes    r ON r.id = s.route_id
          WHERE bl.booking_id = :b ORDER BY bl.id ASC LIMIT 1',
        ['b' => $bid]
    ) ?? [];
    $pax = Database::fetchAll('SELECT full_name, seat_no FROM booking_passengers WHERE booking_id = :b ORDER BY id', ['b' => $bid]);
    $short = static function (string $n): string {
        $parts = preg_split('/\s+/u', trim($n)) ?: [];
        if (count($parts) < 2) {
            return trim($n);
        }
        return $parts[0] . ' ' . mb_substr((string) end($parts), 0, 1) . '.';
    };
    $time = substr((string) (($leg['boarding_time'] ?? '') !== '' ? $leg['boarding_time'] : ($leg['dep_time'] ?? '')), 0, 5);

    /* The agent looking after this ticket (agent routing), if one accepted it -
       on the number Meta has seen them write from (India / Nepal share
       10-digit mobiles, so the country is never guessed here). */
    $agent = null;
    $agentWa = '';
    try {
        require_once INCLUDE_PATH . '/waagentroute.php';
        $agent = WaAgentRoute::acceptedAgent($bid);
        if ($agent !== null && WaAgentRoute::windowOpen($agent['intl'])) {
            $agentWa = $agent['intl'];
        }
    } catch (Throwable $ignored) {
        $agent = null;
    }

    $facts = [
        'pnr'      => $pnr,
        'status'   => (string) $b['status'],
        'route'    => trim((string) ($leg['from_city'] ?? '') . ' → ' . (string) ($leg['to_city'] ?? ''), ' →'),
        'date'     => (string) ($leg['travel_date'] ?? '') !== '' ? formatDate((string) $leg['travel_date'], 'D, j M Y') : '',
        'time'     => $time,
        // The stored stop carries its map point ("Surat - Departure @ 13:00 [21.17,72.83]"): words only here.
        'boarding' => trim((string) preg_replace('/\s*\[[-\d.,\s]+\]\s*$/', '', (string) ($leg['boarding_stop'] ?? ''))),
        'drop'     => trim((string) preg_replace('/\s*\[[-\d.,\s]+\]\s*$/', '', (string) ($leg['drop_stop'] ?? ''))),
        // 29 Oct 2026 audit: the same seat label the ticket prints (A1..F12), never the raw L/U id.
        'pax'      => array_map(static fn(array $p): array => ['name' => $short((string) $p['full_name']),
                          'seat' => Seats::displayLabel((string) $p['seat_no'], 'sleeper', (string) (($b['booking_mode'] ?? '') ?: 'sharing'))], $pax),
        'img'      => in_array((string) $b['status'], ['confirmed', 'completed'], true) ? Ticket::imageUrl($pnr) : '',
        'pdf'      => in_array((string) $b['status'], ['confirmed', 'completed'], true) ? Ticket::downloadUrl($pnr) : '',
        'hi'       => 'https://wa.me/' . $metaNo . '?text=' . rawurlencode('Hi ' . $pnr),
        'agent'    => $agent !== null ? (string) $agent['name'] : '',
        'agentWa'  => $agentWa !== '' ? 'https://wa.me/' . $agentWa . '?text=' . rawurlencode('Namaste, ticket ' . $pnr) : '',
    ];
}

/* 10 Oct 2026 (trust, owner: "government-grade documentation, ticket dekhi
   sabai kura"): the statutory lines under the ticket - the ISSUING company's
   legal name, CIN / PAN, GSTIN once the office has one, its office address
   (Ticket::companyOf, the letterhead the PNG prints) - and, for a real
   ticket, the IST issue time (+ NPT on a Nepal ticket), "computer-generated",
   the keyed verify link and the refund rules (Ticket::statutoryParts). Every
   value is a Settings row; a blank one prints nothing. */
$issuerCo = $b !== null ? Ticket::companyOf($b) : Settings::companyFor();
$coLine   = implode(' · ', array_filter([
    trim((string) ($issuerCo['legal'] ?? '')) !== '' ? (string) $issuerCo['legal'] : (string) ($issuerCo['name'] ?? ''),
    trim((string) ($issuerCo['regNo'] ?? '')) !== '' ? trim((string) ($issuerCo['regLabel'] ?? '') . ' ' . (string) $issuerCo['regNo']) : '',
    trim((string) ($issuerCo['gstin'] ?? '')) !== '' ? 'GSTIN ' . trim((string) $issuerCo['gstin']) : '',
], 'strlen'));
$coAddr   = trim((string) ($issuerCo['address'] ?? ''));
$statIssued = '';
$verifyUrl  = '';
if ($facts !== null) {
    $tkRow      = Database::fetch('SELECT issued_at FROM tickets WHERE booking_id = :b', ['b' => (int) $b['id']]);
    $statIssued = $tkRow !== null ? Ticket::statutoryParts($b, $tkRow, $issuerCo)[0] : '';
    $verifyUrl  = appUrl('verify-ticket.php') . '?pnr=' . urlencode($facts['pnr']) . '&k=' . Ticket::downloadToken($facts['pnr']);
}

$officeWa = '';
$od = preg_replace('/\D+/', '', Settings::officeWhatsApp()) ?? '';
if ($od !== '') {
    $officeWa = 'https://wa.me/' . (strlen($od) === 10 ? '91' . $od : $od);
}

/* 15 Nov 2026 (P6, owner's rule: date changes and cancellations are settled
   BY HAND): a confirmed ticket whose bus has not left may ASK the office for
   another date or to cancel seats. The two forms post to
   api/ticket-request.php with the ticket's signed key (never the PNR alone);
   that writes a request row and rings the office bell - the ticket itself is
   not touched. Earlier requests and their status are listed here. */
$rq = null;
if ($facts !== null && TicketRequests::ready()) {
    $rqList = TicketRequests::forBooking((int) $b['id']);
    $rqWhy  = TicketRequests::refusal($b);
    if ($rqWhy === null || $rqList !== []) {
        $rqMode  = (string) (($b['booking_mode'] ?? '') ?: 'sharing');
        $rqSeats = [];
        foreach ($pax as $p) {
            $sn = strtoupper(trim((string) $p['seat_no']));
            if ($sn !== '' && !isset($rqSeats[$sn])) {
                $rqSeats[$sn] = ['seat' => $sn, 'label' => Seats::displayLabel($sn, 'sleeper', $rqMode), 'name' => $short((string) $p['full_name'])];
            }
        }
        $rqNext = $rqWhy === null ? TicketRequests::nextDeparture((int) $b['id']) : null;
        $rq = [
            'list'   => $rqList,
            'why'    => $rqWhy !== null && $rqWhy['reason'] !== 'off' ? $rqWhy['message'] : '',
            'forms'  => $rqWhy === null,
            'seats'  => array_values($rqSeats),
            'mode'   => $rqMode,
            'win'    => TicketRequests::dateWindow(),
            'now'    => $rqNext !== null ? formatDate($rqNext['travel_date'], 'D, j M Y') : '',
            'key'    => Ticket::downloadToken($pnr),
            'csrf'   => Security::csrfToken(),
        ];
    }
}

$badge = match ($facts['status'] ?? '') {
    'confirmed', 'completed' => ['ok', '✅ CONFIRMED · पक्का'],
    'pending'                => ['wait', '⏳ PAYMENT PENDING · भुक्तानी बाँकी'],
    'cancelled', 'rejected'  => ['bad', '❌ CANCELLED · रद्द'],
    default                  => ['wait', strtoupper((string) ($facts['status'] ?? ''))],
};
http_response_code($limited ? 429 : ($facts === null ? 404 : 200));
?><!doctype html>
<html lang="ne">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0B5D3B">
<title><?= $facts !== null ? $e($facts['pnr']) . ' · ' : '' ?>Ticket · <?= $e($company) ?></title>
<style>
:root{--bg:#f4f6f9;--card:#fff;--ink:#0f172a;--mut:#5b6474;--line:#e3e7ee;--ok:#0B7A47;--okbg:#e6f6ee;--wait:#a15c00;--waitbg:#fff4e0;--bad:#b42318;--badbg:#fdecea;--wa:#128C4B;--brand:#0B5D3B}
@media (prefers-color-scheme:dark){:root{--bg:#0b1220;--card:#131d30;--ink:#e8eef8;--mut:#a7b3c6;--line:#24324a;--okbg:#0f2e22;--waitbg:#33260d;--badbg:#3a1614}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;padding:16px 16px calc(24px + env(safe-area-inset-bottom))}
.w{max-width:480px;margin:0 auto}
.top{display:flex;align-items:center;gap:10px;margin:2px 0 14px}
.top b{font-size:17px}.top small{display:block;color:var(--mut);font-size:12px}
.logo{width:38px;height:38px;border-radius:10px;background:var(--brand);color:#fff;display:grid;place-items:center;font-weight:800}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin:0 0 12px;box-shadow:0 2px 14px rgba(15,23,42,.05)}
.badge{display:inline-block;padding:4px 10px;border-radius:999px;font-weight:700;font-size:13px}
.badge.ok{background:var(--okbg);color:var(--ok)}.badge.wait{background:var(--waitbg);color:var(--wait)}.badge.bad{background:var(--badbg);color:var(--bad)}
.pnr{font:700 20px/1.2 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.5px;margin:10px 0 2px;word-break:break-all}
.row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-top:1px dashed var(--line)}
.row span{color:var(--mut)}.row b{text-align:right}
.tk{display:block;width:100%;height:auto;border-radius:12px;border:1px solid var(--line);background:#fff}
.btn{display:flex;align-items:center;justify-content:center;gap:8px;min-height:48px;padding:12px 14px;border-radius:12px;font-weight:700;text-decoration:none;margin:8px 0 0;border:1px solid var(--line);color:var(--ink);background:var(--card)}
.btn.wa{background:var(--wa);border-color:var(--wa);color:#fff}
.btn.pri{background:var(--brand);border-color:var(--brand);color:#fff}
.hint{color:var(--mut);font-size:12.5px;margin:6px 2px 0}
h1{font-size:19px;margin:4px 0 6px}
/* 15 Nov 2026 (P6): change-date / cancel-seat requests */
.rq-h{font-size:16px;margin:0 0 6px}
.rq-note{background:var(--waitbg);color:var(--ink);border-radius:12px;padding:10px 12px;font-size:13px;line-height:1.5;margin:0 0 4px}
.rq-note a{color:inherit;font-weight:700}
details.rq{margin:8px 0 0}
details.rq>summary{list-style:none;cursor:pointer;margin:0}
details.rq>summary::-webkit-details-marker{display:none}
details.rq[open]>summary{border-color:var(--brand)}
.rq form{border:1px solid var(--line);border-radius:12px;padding:12px;margin:8px 0 0}
.rq label.f{display:block;font-size:13px;color:var(--mut);margin:0 0 10px}
.rq input[type=date],.rq input[type=tel],.rq textarea{display:block;width:100%;min-height:46px;margin:4px 0 0;padding:10px 12px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:inherit;color-scheme:light dark}
.rq textarea{min-height:64px;resize:vertical}
.rq fieldset{border:0;margin:0 0 10px;padding:0;min-width:0}
.rq legend{font-size:13px;color:var(--mut);padding:0;margin:0 0 4px}
.rq label.ck{display:flex;align-items:center;gap:10px;min-height:44px;padding:6px 10px;border:1px solid var(--line);border-radius:10px;margin:0 0 6px;color:var(--ink);overflow-wrap:anywhere}
.rq label.ck input{width:22px;height:22px;flex:0 0 22px;accent-color:var(--brand)}
.rq label.ck.all{font-weight:700}
.rq button.btn{width:100%;font:inherit;font-weight:700;cursor:pointer}
.rq button.btn[disabled]{opacity:.6;cursor:wait}
.rq-msg{font-size:13.5px;margin:8px 2px 0;min-height:1em}
.rq-msg.ok{color:var(--ok)}.rq-msg.bad{color:var(--bad)}
@media (prefers-color-scheme:dark){.rq-msg.ok{color:#5fd39a}.rq-msg.bad{color:#ff9b93}
  /* the status pills: the page's --ok / --wait / --bad on their dark backgrounds read at ~2.5:1 */
  .rq-list .badge.ok{color:#5fd39a}.rq-list .badge.wait{color:#ffc56b}.rq-list .badge.bad{color:#ff9b93}}
.rq-list{list-style:none;margin:10px 0 0;padding:0}
.rq-list li{border-top:1px dashed var(--line);padding:9px 0;display:flex;flex-wrap:wrap;gap:4px 10px;align-items:center;justify-content:space-between}
.rq-list li b{overflow-wrap:anywhere}
.rq-list small{flex-basis:100%;color:var(--mut);font-size:12px}
</style>
</head>
<body>
<div class="w">
  <div class="top"><div class="logo" aria-hidden="true">SH</div><div><b><?= $e($company) ?></b><small>e-Ticket · ई-टिकट</small></div></div>
<?php if ($facts === null): ?>
  <div class="card">
    <h1><?= $limited ? 'Please wait a minute · कृपया एक मिनेट पर्खनुहोस्' : 'Link not available · लिंक उपलब्ध छैन' ?></h1>
    <p class="hint" style="font-size:14px">This ticket link is wrong or has expired. Please open the link from the message we sent you, or contact the office.<br>
    यो टिकट लिंक मिलेन वा म्याद सकियो। हामीले पठाएको सन्देशको लिंक खोल्नुहोस् वा कार्यालयमा सम्पर्क गर्नुहोस्।</p>
    <?php if ($officeWa !== ''): ?><a class="btn wa" href="<?= $e($officeWa) ?>" target="_blank" rel="noopener">💬 Office WhatsApp · कार्यालय</a><?php endif; ?>
    <?php if ($office !== ''): ?><a class="btn" href="tel:<?= $e(preg_replace('/[^\d+]/', '', $office)) ?>">📞 <?= $e($office) ?></a><?php endif; ?>
  </div>
<?php else: ?>
  <div class="card">
    <span class="badge <?= $e($badge[0]) ?>"><?= $e($badge[1]) ?></span>
    <div class="pnr"><?= $e($facts['pnr']) ?></div>
    <?php if ($facts['route'] !== ''): ?><div class="row"><span>Route · यात्रा</span><b><?= $e($facts['route']) ?></b></div><?php endif; ?>
    <?php if ($facts['date'] !== ''): ?><div class="row"><span>Date · मिति</span><b><?= $e($facts['date']) ?><?= $facts['time'] !== '' ? ' · ' . $e($facts['time']) : '' ?></b></div><?php endif; ?>
    <?php if ($facts['boarding'] !== ''): ?><div class="row"><span>Boarding · चढ्ने</span><b><?= $e($facts['boarding']) ?></b></div><?php endif; ?>
    <?php if ($facts['drop'] !== ''): ?><div class="row"><span>Drop · झर्ने</span><b><?= $e($facts['drop']) ?></b></div><?php endif; ?>
    <?php foreach ($facts['pax'] as $p): ?>
      <div class="row"><span>Passenger · यात्रु</span><b><?= $e($p['name']) ?><?= $p['seat'] !== '' ? ' · ' . $e($p['seat']) : '' ?></b></div>
    <?php endforeach; ?>
  </div>
  <?php if ($facts['img'] !== ''): ?>
  <div class="card" style="padding:10px">
    <a href="<?= $e($facts['img']) ?>" target="_blank" rel="noopener"><img class="tk" src="<?= $e($facts['img']) ?>" alt="Ticket <?= $e($facts['pnr']) ?>" loading="eager" decoding="async"></a>
    <p class="hint">Tap the ticket to open it full size and save it. · टिकट थिचेर ठूलो खोल्नुहोस् र सेभ गर्नुहोस्।</p>
  </div>
  <?php endif; ?>
  <div class="card">
    <a class="btn wa" href="<?= $e($facts['hi']) ?>" target="_blank" rel="noopener">💬 Get this ticket on WhatsApp · WhatsApp मा टिकट लिनुहोस्</a>
    <p class="hint">Opens WhatsApp with “Hi <?= $e($facts['pnr']) ?>” — press send and the ticket arrives in a few seconds, free. · पठाउनुहोस् थिच्नुहोस्, टिकट केही सेकेन्डमै आउँछ (निःशुल्क)।</p>
    <?php if ($facts['pdf'] !== ''): ?><a class="btn pri" href="<?= $e($facts['pdf']) ?>" target="_blank" rel="noopener">⬇️ Download PDF · PDF डाउनलोड</a><?php endif; ?>
    <?php if ($facts['agentWa'] !== ''): ?><a class="btn" href="<?= $e($facts['agentWa']) ?>" target="_blank" rel="noopener">🧑‍💼 Your agent · तपाईंको एजेन्ट: <?= $e($facts['agent']) ?></a><?php endif; ?>
    <?php if ($officeWa !== ''): ?><a class="btn" href="<?= $e($officeWa) ?>" target="_blank" rel="noopener">🏢 Office WhatsApp · कार्यालय</a><?php endif; ?>
    <?php if ($office !== ''): ?><a class="btn" href="tel:<?= $e(preg_replace('/[^\d+]/', '', $office)) ?>">📞 <?= $e($office) ?></a><?php endif; ?>
  </div>
  <?php if ($rq !== null): /* 15 Nov 2026 (P6): ask the office for another date / to cancel seats */ ?>
  <div class="card rq" id="treq" data-api="/api/ticket-request.php" data-pnr="<?= $e($facts['pnr']) ?>" data-k="<?= $e($rq['key']) ?>" data-csrf="<?= $e($rq['csrf']) ?>">
    <h2 class="rq-h">परिवर्तन चाहियो? · Need a change?</h2>
    <?php if ($rq['forms']): ?>
    <p class="rq-note">यो <b>अनुरोध</b> मात्र हो — टिकट अहिले नै परिवर्तन वा रद्द हुँदैन। कार्यालयले फोन वा WhatsApp गरेर कुरा मिलाउँछ। भाडा फरक वा पैसा फिर्ता <a href="/refund-policy" target="_blank" rel="noopener">फिर्ता नियम</a> अनुसार हुन्छ।<br>
    This is only a <b>request</b> — your ticket is not changed or cancelled yet. The office will call or WhatsApp you. Any fare difference or refund follows the published <a href="/refund-policy" target="_blank" rel="noopener">refund &amp; cancellation rules</a>.</p>

    <details class="rq">
      <summary class="btn">📅 मिति परिवर्तन माग्नुहोस् · Request date change</summary>
      <form data-kind="change_date" novalidate>
        <label class="f">नयाँ मिति · New date<?= $rq['now'] !== '' ? ' (अहिले · now: ' . $e($rq['now']) . ')' : '' ?>
          <input type="date" name="wanted_date" required min="<?= $e($rq['win']['from']) ?>" max="<?= $e($rq['win']['to']) ?>"></label>
        <label class="f">फोन (ऐच्छिक) · Phone to call (optional)
          <input type="tel" name="contact_phone" inputmode="tel" autocomplete="tel" maxlength="16" placeholder="खाली = टिकटको नम्बर · blank = ticket's number"></label>
        <label class="f">टिप्पणी (ऐच्छिक) · Note (optional)
          <textarea name="note" maxlength="255" rows="2" placeholder="जस्तै: कुनै पनि समय मिल्छ · e.g. any time is fine"></textarea></label>
        <button class="btn pri" type="submit">अनुरोध पठाउनुहोस् · Send request</button>
        <p class="rq-msg" role="status" aria-live="polite"></p>
      </form>
    </details>

    <?php if ($rq['seats'] !== []): ?>
    <details class="rq">
      <summary class="btn">🪑 सिट रद्द माग्नुहोस् · Request seat cancel</summary>
      <form data-kind="cancel_seats" novalidate>
        <fieldset>
          <legend>कुन सिट? · Which seats?</legend>
          <?php if (count($rq['seats']) > 1): ?>
          <label class="ck all"><input type="checkbox" name="whole" value="1"> पूरा टिकट · Whole ticket (<?= count($rq['seats']) ?>)</label>
          <?php endif; ?>
          <?php foreach ($rq['seats'] as $s): ?>
          <label class="ck"><input type="checkbox" name="seats[]" value="<?= $e($s['seat']) ?>"<?= count($rq['seats']) === 1 ? ' checked' : '' ?>> <?= $e($s['label'] !== '' ? $s['label'] : $s['seat']) ?> · <?= $e($s['name']) ?></label>
          <?php endforeach; ?>
        </fieldset>
        <label class="f">फोन (ऐच्छिक) · Phone to call (optional)
          <input type="tel" name="contact_phone" inputmode="tel" autocomplete="tel" maxlength="16" placeholder="खाली = टिकटको नम्बर · blank = ticket's number"></label>
        <label class="f">टिप्पणी (ऐच्छिक) · Note (optional)
          <textarea name="note" maxlength="255" rows="2" placeholder="जस्तै: एक जना जान सक्नुहुन्न · e.g. one person cannot travel"></textarea></label>
        <button class="btn pri" type="submit">अनुरोध पठाउनुहोस् · Send request</button>
        <p class="rq-msg" role="status" aria-live="polite"></p>
      </form>
    </details>
    <?php endif; ?>
    <noscript><p class="hint">यो फारमलाई JavaScript चाहिन्छ — कार्यालयमा फोन गर्नुहोस्। · This form needs JavaScript — please call the office.</p></noscript>
    <?php elseif ($rq['why'] !== ''): ?>
    <p class="hint" style="font-size:13.5px"><?= $e($rq['why']) ?></p>
    <?php endif; ?>

    <?php if ($rq['list'] !== []): ?>
    <h3 class="rq-h" style="font-size:14.5px;margin-top:14px">तपाईंका अनुरोध · Your requests</h3>
    <ul class="rq-list">
      <?php foreach ($rq['list'] as $r):
        $rk  = TicketRequests::KINDS[(string) $r['kind']] ?? ['', (string) $r['kind']];
        $rs  = TicketRequests::STATUS_CUSTOMER[(string) $r['status']] ?? ['', (string) $r['status']];
        $rb  = match ((string) $r['status']) { 'done' => 'ok', 'rejected' => 'bad', default => 'wait' };
        $rd  = (string) $r['kind'] === 'change_date'
            ? '→ ' . formatDate((string) ($r['wanted_date'] ?? ''), 'D, j M Y')
            : '— ' . (count(explode(',', (string) ($r['seats'] ?? ''))) === count($rq['seats']) && count($rq['seats']) > 1 ? 'पूरा टिकट · whole ticket' : TicketRequests::seatLabels((string) ($r['seats'] ?? ''), $rq['mode'])); ?>
      <li>
        <b><?= $e($rk[0] . ' · ' . $rk[1]) ?> <?= $e($rd) ?></b>
        <span class="badge <?= $e($rb) ?>"><?= $e(($rb === 'ok' ? '✅ ' : ($rb === 'bad' ? '✖ ' : '⏳ ')) . $rs[0] . ' · ' . $rs[1]) ?></span>
        <small>माग गरेको · asked <?= $e(formatDate((string) $r['created_at'], 'j M, H:i')) ?><?= (string) $r['status'] === 'open' ? ' · कार्यालयले सम्पर्क गर्छ · the office will contact you' : '' ?></small>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <?php if ($rq['forms']): ?>
  <script>
  (function () {
    var box = document.getElementById('treq');
    if (!box || !window.fetch || !window.FormData) { return; }
    var api = box.getAttribute('data-api'), pnr = box.getAttribute('data-pnr'),
        key = box.getAttribute('data-k'), csrf = box.getAttribute('data-csrf');
    var whole = box.querySelector('input[name="whole"]');
    if (whole) {
      var seatBoxes = box.querySelectorAll('input[name="seats[]"]');
      whole.addEventListener('change', function () {
        seatBoxes.forEach(function (c) { c.checked = whole.checked; });
      });
      seatBoxes.forEach(function (c) {
        c.addEventListener('change', function () {
          whole.checked = Array.prototype.every.call(seatBoxes, function (x) { return x.checked; });
        });
      });
    }
    box.querySelectorAll('form[data-kind]').forEach(function (f) {
      f.addEventListener('submit', function (ev) {
        ev.preventDefault();
        var kind = f.getAttribute('data-kind'), msg = f.querySelector('.rq-msg'), btn = f.querySelector('button[type="submit"]');
        var say = function (cls, text) { msg.className = 'rq-msg ' + cls; msg.textContent = text; };
        var fd = new FormData(f);
        if (kind === 'change_date' && !fd.get('wanted_date')) { say('bad', 'नयाँ मिति छान्नुहोस् · Choose the new date.'); return; }
        if (kind === 'cancel_seats' && !fd.get('whole') && fd.getAll('seats[]').length === 0) { say('bad', 'कम्तीमा एउटा सिट छान्नुहोस् · Tick at least one seat.'); return; }
        fd.append('kind', kind); fd.append('pnr', pnr); fd.append('k', key);
        btn.disabled = true; say('', 'पठाउँदै… · Sending…');
        fetch(api, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store',
                     headers: { 'X-CSRF-Token': csrf, 'Accept': 'application/json' } })
          .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
          .then(function (j) {
            if (j && j.ok) {
              say('ok', '✅ ' + (j.message || 'Request sent.'));
              f.querySelectorAll('input,textarea,button').forEach(function (el) { el.disabled = true; });
              setTimeout(function () { location.reload(); }, 2500);
            } else {
              btn.disabled = false;
              say('bad', (j && j.error) || 'पठाउन सकिएन — कार्यालयमा फोन गर्नुहोस्। · Could not send — please call the office.');
            }
          })
          .catch(function () { btn.disabled = false; say('bad', 'इन्टरनेट छैन जस्तो छ — फेरि प्रयास गर्नुहोस्। · No connection — please try again.'); });
      });
    });
  })();
  </script>
  <?php endif; ?>
  <?php endif; ?>
  <p class="hint" style="text-align:center">Carry a photo ID · reach the boarding point 30 minutes early.<br>फोटो परिचयपत्र लिनुहोस् · ३० मिनेट अगाडि पुग्नुहोस्।</p>
  <?php /* 14 Oct 2026 (review): English · Nepali like every other line on this page */ ?>
  <?php if ($statIssued !== ''): ?><p class="hint" style="text-align:center"><?= $e($statIssued) ?><br>कम्प्युटरबाट बनेको ई-टिकट, हस्ताक्षर चाहिँदैन।</p><?php endif; ?>
  <p class="hint" style="text-align:center"><a href="<?= $e($verifyUrl) ?>" rel="noopener">Check this ticket is genuine · टिकट जाँच</a> · <a href="/refund-policy">Refund &amp; cancellation rules · फिर्ता नियम</a></p>
<?php endif; ?>
  <?php /* 10 Oct 2026 (trust): who issued this document - legal name, CIN / PAN, GSTIN when set, office */ ?>
  <?php if ($coLine !== ''): ?><p class="hint" style="text-align:center;margin-top:14px"><?= $e($coLine) ?><?= $coAddr !== '' ? '<br>' . $e($coAddr) : '' ?></p><?php endif; ?>
</div>
</body>
</html>
