<?php
/**
 * =====================================================================
 *  POST /api/wa-request.php — a booking or help request from the site,
 *  delivered to the office WhatsApp by the company's own API sender.
 *
 *  Owner, 19 Sep 2026: "name number format ready, one click ma admin
 *  9104801507 ma msg aaos api wala WhatsApp ko through bata; customer le
 *  booking request garyo, kei help magyo vane ni malai aaos". The old
 *  button opened the passenger's own WhatsApp with a pre-written text,
 *  so the request only existed if they pressed send there. This sends it
 *  from the server (Notify::whatsapp, the same sender the admin booking
 *  alerts use) to admin_whatsapp, with a tap-to-reply link to the
 *  passenger's number. The site's wa.me link stays as the fallback.
 *
 *  Request  { type: booking|help, name, phone, direction?, date?, point?,
 *             pax?, note? }
 *  Response { sent: bool }
 * =====================================================================
 */

require __DIR__ . '/_init.php';

try {
    Security::requireCsrf();

    $ip = Security::clientIp();
    if (!Security::rateLimit('wa_request', $ip, 5, 600)) {
        Response::error('Too many requests. Please wait a few minutes or call +91 91048 01507.', 429);
    }

    $type  = (string) Response::field('type', 'booking') === 'help' ? 'help' : 'booking';
    $name  = Security::clean((string) Response::field('name', ''), 60);
    $raw   = Security::clean((string) Response::field('phone', ''), 20);
    $phone = normalisePhone($raw);
    $note  = Security::clean((string) Response::field('note', ''), 300);

    $bad = [];
    if (mb_strlen($name) < 2) { $bad['name'] = 'Enter your name.'; }
    if (!preg_match('/^\d{8,12}$/', $phone)) { $bad['phone'] = 'Enter a valid mobile number.'; }
    if ($type === 'help' && $note === '') { $bad['note'] = 'Write what you need help with.'; }
    if ($bad !== []) {
        Response::invalid($bad);
    }
    if (!Security::rateLimit('wa_request_phone', $phone, 3, 1800)) {
        Response::error('We already have your request — the office will reply on WhatsApp shortly.', 429);
    }

    // +977 only when the passenger typed it; a bare 10-digit number is Indian.
    $isNepal = (bool) preg_match('/^\s*(\+|00)?977/', $raw);
    $intl    = ($isNepal ? '977' : '91') . $phone;

    $lines = [];
    if ($type === 'booking') {
        $dir   = (string) Response::field('direction', '') === 'back' ? 'Rupaidiha → Gujarat' : 'Gujarat → Rupaidiha';
        $date  = Security::clean((string) Response::field('date', ''), 10);
        $point = Security::clean((string) Response::field('point', ''), 80);
        $pax   = max(1, min(20, (int) Response::field('pax', 1)));
        $dateTxt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? date('d M Y (D)', (int) strtotime($date)) : '—';
        $lines[] = '🎫 वेबसाइटबाट टिकट अनुरोध · Booking request';
        $lines[] = '🧑 ' . $name;
        $lines[] = '📱 +' . $intl;
        $lines[] = '🚌 ' . $dir;
        $lines[] = '📅 ' . $dateTxt;
        $lines[] = '📍 ' . ($dir === 'Gujarat → Rupaidiha' ? 'Boarding' : 'Drop') . ': ' . ($point !== '' ? $point : '—');
        $lines[] = '👥 ' . $pax . ' passenger' . ($pax > 1 ? 's' : '');
    } else {
        $lines[] = '🆘 वेबसाइटबाट सहायता अनुरोध · Help request';
        $lines[] = '🧑 ' . $name;
        $lines[] = '📱 +' . $intl;
    }
    if ($note !== '') {
        $lines[] = '💬 ' . $note;
    }
    $lines[] = '↩️ जवाफ दिनुहोस् · Reply: https://wa.me/' . $intl;

    $admin = Settings::getString('admin_whatsapp', Settings::officePhone());
    $sent  = false;
    if ($admin !== '') {
        $r    = Notify::whatsapp($admin, implode("\n", $lines), null, 'IN', [], null, ['kind' => 'wa_request']);
        $sent = $r !== false;
    }

    Logger::audit('wa.request', 'lead', $intl, null, ['type' => $type, 'sent' => $sent], 'Website WhatsApp request');

    Response::success(['sent' => $sent], $sent
        ? 'Sent — the office will reply on WhatsApp shortly. / पठाइयो — कार्यालयले छिट्टै WhatsApp मा जवाफ दिनेछ।'
        : 'Saved, but WhatsApp is busy — please tap “Open WhatsApp”. / WhatsApp व्यस्त छ — “WhatsApp खोल्नुहोस्” थिच्नुहोस्।');
} catch (Throwable $e) {
    Response::serverError($e);
}
