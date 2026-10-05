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
    /* 5 Oct 2026 (owner: only the API WhatsApp button, and it must reach the
       admin as a ticket): every request is also a Help Desk ticket — it shows
       in Admin → Help Desk with its HD number even if WhatsApp is down — and
       the office alert goes through BOTH API senders: the approved AiSensy
       office template first (works outside the 24 h window), then the main
       WhatsApp driver. */
    $ticket = null;
    try {
        require_once INCLUDE_PATH . '/helpdesk.php';
        $hd = HelpDesk::open([
            'name' => $name, 'phone' => $intl, 'country' => $isNepal ? 'NP' : 'IN',
            'category' => $type === 'booking' ? 'booking_help' : '',
            'message' => implode("\n", $lines),
            'source' => 'web', 'lang' => (string) Response::field('lang', 'ne'), 'ack' => false,
        ]);
        if ($hd['ok']) {
            $ticket = (string) $hd['ref'];
            array_splice($lines, 1, 0, ['🎟️ ' . $ticket . (!empty($hd['token']) ? ' · #' . (int) $hd['token'] : '')]);
        }
    } catch (Throwable $e) {
        Logger::exception($e, 'helpdesk');
    }
    $lines[] = '↩️ जवाफ दिनुहोस् · Reply: https://wa.me/' . $intl;
    if ($ticket !== null) {
        $lines[] = '🛟 ' . appUrl('/admin/support-inbox.php?q=' . rawurlencode($ticket));
    }
    $text = implode("\n", $lines);

    $admin = Settings::getString('admin_whatsapp', Settings::officePhone());
    $sent  = false;
    $via   = '';
    if ($admin !== '') {
        try {
            require_once ROOT_PATH . '/whatsapp/aisensy.php';
            if (AiSensyAPI::enabled()) {
                $digits = preg_replace('/\D/', '', $admin) ?? '';
                $a = AiSensyAPI::sendStaffMessage([
                    'to' => strlen($digits) === 10 ? '91' . $digits : $digits,
                    'label' => ($type === 'booking' ? 'Booking request' : 'Help request') . ($ticket !== null ? ' ' . $ticket : ''),
                    'text' => $text,
                ], 'office');
                if (!empty($a['success'])) {
                    $sent = true;
                    $via = 'aisensy';
                }
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
        if (!$sent) {
            $r = Notify::whatsapp($admin, $text, null, 'IN', [], null, ['kind' => 'wa_request']);
            // A click-to-chat URL is a manual fallback, not an API delivery.
            $sent = $r === true;
            $via  = $sent ? 'whatsapp' : '';
        }
    }

    Logger::audit('wa.request', 'lead', $intl, null, ['type' => $type, 'sent' => $sent, 'via' => $via, 'ticket' => $ticket], 'Website WhatsApp request');

    $num = $ticket !== null ? ' Number: ' . $ticket . ' / नम्बर: ' . $ticket : '';
    Response::success(['sent' => $sent, 'ticket' => $ticket], ($sent || $ticket !== null)
        ? 'Sent — the office will reply on WhatsApp shortly. / पठाइयो — कार्यालयले छिट्टै WhatsApp मा जवाफ दिनेछ।' . $num
        : 'Could not send right now — please call the office. / अहिले पठाउन सकिएन — कार्यालयमा फोन गर्नुहोस्।');
} catch (Throwable $e) {
    Response::serverError($e);
}
