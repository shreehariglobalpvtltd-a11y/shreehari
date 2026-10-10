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
        'pax'      => array_map(static fn(array $p): array => ['name' => $short((string) $p['full_name']), 'seat' => (string) $p['seat_no']], $pax),
        'img'      => in_array((string) $b['status'], ['confirmed', 'completed'], true) ? Ticket::imageUrl($pnr) : '',
        'pdf'      => in_array((string) $b['status'], ['confirmed', 'completed'], true) ? Ticket::downloadUrl($pnr) : '',
        'hi'       => 'https://wa.me/' . $metaNo . '?text=' . rawurlencode('Hi ' . $pnr),
        'agent'    => $agent !== null ? (string) $agent['name'] : '',
        'agentWa'  => $agentWa !== '' ? 'https://wa.me/' . $agentWa . '?text=' . rawurlencode('Namaste, ticket ' . $pnr) : '',
    ];
}

$officeWa = '';
$od = preg_replace('/\D+/', '', Settings::officeWhatsApp()) ?? '';
if ($od !== '') {
    $officeWa = 'https://wa.me/' . (strlen($od) === 10 ? '91' . $od : $od);
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
  <p class="hint" style="text-align:center">Carry a photo ID · reach the boarding point 30 minutes early.<br>फोटो परिचयपत्र लिनुहोस् · ३० मिनेट अगाडि पुग्नुहोस्।</p>
<?php endif; ?>
</div>
</body>
</html>
