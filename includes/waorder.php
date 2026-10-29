<?php
/**
 * =====================================================================
 *  WaOrder — the WhatsApp order menu (5 Oct 2026)
 *
 *  Owner: "khana order gare jasto ticket order garne — Gujarat jane / aune,
 *  bhada yeti, confirm, payment option, ani ticket delivery; customer,
 *  agent, counter, admin sabai le rules anusar".
 *
 *  The selling itself is WaBooking (the conversation) on top of TicketBot
 *  and QuickTicket — none of that is repeated here. This file is only the
 *  front door a food-ordering chat has and ours did not:
 *
 *    - the MENU: three buttons under the greeting (and under the word
 *      "menu"), different for a passenger, a seller and the office;
 *    - MY TICKETS: the sender's own coming tickets, with the nearest one
 *      as a picture — a database read, answered without a model;
 *    - MY SALES: a seller's own count and cash for today.
 *
 *  Buttons are a convenience of the transport (Meta Cloud API). Every
 *  reply also carries plain text that says the same thing, so Twilio,
 *  Gupshup, AiSensy and an old phone lose nothing but the tap.
 *
 *  Switch: wa_order_menu_on (default on).
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaOrder
{
    /**
     * Answer a menu command, or null when the message is not one.
     *
     * @param array<string,mixed>|null $who AiTools::whoIs() when the caller resolved it
     * @return array{text: string, media: ?string, ui: ?array}|null
     */
    public static function handle(string $phoneDigits, string $text, ?array $who = null): ?array
    {
        $t = mb_strtolower(trim((string) preg_replace('/[\s.!?📋📊]+/u', ' ', $text)));
        if ($t === '' || mb_strlen($t) > 40 || $phoneDigits === '') {
            return null;
        }
        $role    = (string) ($who['role'] ?? 'customer');
        $isStaff = in_array($role, ['staff', 'admin'], true);

        // A button carries the language its menu was shown in.
        $lang = '';
        if (preg_match('/^(.*) (ne|hi|en|gu)$/', $t, $m) === 1
            && in_array($m[1], ['menu', 'mero ticket', 'mero bikri'], true)) {
            $t    = $m[1];
            $lang = $m[2];
        }

        if (in_array($t, ['menu', 'main menu', 'start', 'options', 'मेनु', 'मेनू', 'मेन्यु', 'મેનુ'], true)) {
            self::dropOpenOrder($phoneDigits);
            $lang = $lang !== '' ? $lang : self::langOf($text);
            return ['text' => self::menuText($role, $lang), 'media' => null, 'ui' => self::menuUi($role, $lang, self::menuHead($lang))];
        }

        if (in_array($t, ['mero ticket', 'mero tiket', 'my ticket', 'my tickets', 'mera ticket', 'meri ticket',
                          'मेरो टिकट', 'मेरा टिकट', 'मेरी टिकट', 'મારી ટિકિટ',
                          // 29 Oct 2026 ("मेरा सबै टिकट", the master brief's history ask)
                          'mero sabai ticket', 'mera sabhi ticket', 'all my tickets', 'मेरो सबै टिकट', 'मेरा सबै टिकट', 'मेरे सभी टिकट'], true)) {
            self::dropOpenOrder($phoneDigits);
            return self::myTickets($phoneDigits, $lang !== '' ? $lang : self::langOf($text));
        }

        if ($isStaff && (int) ($who['adminId'] ?? 0) > 0
            && in_array($t, ['mero bikri', 'my sales', 'aaja ko bikri', 'aajako bikri', 'मेरो बिक्री', 'आजको बिक्री'], true)
            && $role === 'staff') {
            return self::mySales($who, $lang !== '' ? $lang : self::langOf($text));
        }

        return null;
    }

    /**
     * The link that opens the order chat with "ticket" already typed - for
     * an offer message, a poster QR or the website. The number is the one
     * the bot answers on (wa_order_number).
     */
    public static function orderLink(): string
    {
        $n = preg_replace('/\D/', '', Settings::getString('wa_order_number', '918735881507')) ?? '';
        return $n !== '' ? 'https://wa.me/' . $n . '?text=ticket' : '';
    }

    /**
     * A seller's guided order: only when they tapped / typed the order
     * command, or already have an order open. A pasted bulk list always
     * belongs to WaBulk, even in the middle of an order.
     *
     * @param array<string,mixed> $who AiTools::whoIs() of a staff number
     * @return array{text: string, media: ?string, ui: ?array}|null
     */
    public static function sellerOrder(string $phoneDigits, string $text, string $senderCountry, array $who): ?array
    {
        if (WaBooking::orderCommand($text) === null && !WaBooking::isOpen($phoneDigits)) {
            return null;
        }
        if (is_file(INCLUDE_PATH . '/wabulk.php') && Settings::getBool('wa_bulk_on', false)) {
            require_once INCLUDE_PATH . '/wabulk.php';
            if (WaBulk::looksLikeBulk($text) || WaBulk::isFormatRequest($text)) {
                return null;
            }
        }
        return WaBooking::handle($phoneDigits, $text, $senderCountry, $who);
    }

    /**
     * The three buttons. Ids are "shg:<words>" — the webhook hands the words
     * to the bot exactly as if they had been typed.
     *
     * @return array<string,mixed>
     */
    public static function menuUi(string $role, string $lang, string $body): array
    {
        $lang = in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : 'ne';
        $t    = static fn (array $w): string => $w[$lang] ?? $w['en'];

        if ($role === 'admin') {
            $buttons = [
                ['id' => 'shg:ticket book ' . $lang, 'title' => $t(['en' => '🎫 Issue a ticket', 'hi' => '🎫 टिकट काटो', 'ne' => '🎫 टिकट काट्ने', 'gu' => '🎫 ટિકિટ કાપો'])],
                ['id' => 'shg:report', 'title' => $t(['en' => "📊 Today's report", 'hi' => '📊 आज की रिपोर्ट', 'ne' => '📊 आजको रिपोर्ट', 'gu' => '📊 આજનો રિપોર્ટ'])],
                ['id' => 'shg:khali seat', 'title' => $t(['en' => '💺 Free seats', 'hi' => '💺 खाली सीट', 'ne' => '💺 खाली सिट', 'gu' => '💺 ખાલી સીટ'])],
            ];
        } elseif ($role === 'staff') {
            $buttons = [
                ['id' => 'shg:ticket book ' . $lang, 'title' => $t(['en' => '🎫 Issue a ticket', 'hi' => '🎫 टिकट काटो', 'ne' => '🎫 टिकट काट्ने', 'gu' => '🎫 ટિકિટ કાપો'])],
                ['id' => 'shg:mero bikri ' . $lang, 'title' => $t(['en' => '📊 My sales today', 'hi' => '📊 आज की बिक्री', 'ne' => '📊 आजको बिक्री', 'gu' => '📊 આજનું વેચાણ'])],
                ['id' => 'shg:khali seat', 'title' => $t(['en' => '💺 Free seats', 'hi' => '💺 खाली सीट', 'ne' => '💺 खाली सिट', 'gu' => '💺 ખાલી સીટ'])],
            ];
        } else {
            $buttons = [
                ['id' => 'shg:ticket book ' . $lang, 'title' => $t(['en' => '🎫 Book a ticket', 'hi' => '🎫 टिकट बुक करें', 'ne' => '🎫 टिकट बुक गर्ने', 'gu' => '🎫 ટિકિટ બુક કરો'])],
                ['id' => 'shg:mero ticket ' . $lang, 'title' => $t(['en' => '📋 My ticket', 'hi' => '📋 मेरा टिकट', 'ne' => '📋 मेरो टिकट', 'gu' => '📋 મારી ટિકિટ'])],
                ['id' => 'shg:office', 'title' => $t(['en' => '📞 Office', 'hi' => '📞 ऑफिस', 'ne' => '📞 अफिस', 'gu' => '📞 ઓફિસ'])],
            ];
        }

        return ['kind' => 'buttons', 'body' => $body, 'buttons' => $buttons];
    }

    /** The same menu in words, for a phone or a transport with no buttons. */
    public static function menuText(string $role, string $lang): string
    {
        $lang = in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : 'ne';
        $staff = in_array($role, ['staff', 'admin'], true);
        $lines = [self::menuHead($lang), ''];
        if ($staff) {
            $lines[] = ['en' => '🎫 Issue a ticket — write: ticket', 'hi' => '🎫 टिकट काटना — लिखें: ticket',
                        'ne' => '🎫 टिकट काट्न — लेख्नुहोस्: ticket', 'gu' => '🎫 ટિકિટ કાપવા — લખો: ticket'][$lang];
            $lines[] = $role === 'admin'
                ? ['en' => "📊 Today's report — write: report", 'hi' => '📊 आज की रिपोर्ट — लिखें: report',
                   'ne' => '📊 आजको रिपोर्ट — लेख्नुहोस्: report', 'gu' => '📊 આજનો રિપોર્ટ — લખો: report'][$lang]
                : ['en' => '📊 My sales today — write: mero bikri', 'hi' => '📊 आज की बिक्री — लिखें: mero bikri',
                   'ne' => '📊 आजको बिक्री — लेख्नुहोस्: mero bikri', 'gu' => '📊 આજનું વેચાણ — લખો: mero bikri'][$lang];
            $lines[] = ['en' => '💺 Free seats — write: khali seat', 'hi' => '💺 खाली सीट — लिखें: khali seat',
                        'ne' => '💺 खाली सिट — लेख्नुहोस्: khali seat', 'gu' => '💺 ખાલી સીટ — લખો: khali seat'][$lang];
        } else {
            $lines[] = ['en' => '🎫 Book a ticket — write: ticket', 'hi' => '🎫 टिकट बुक करना — लिखें: ticket',
                        'ne' => '🎫 टिकट बुक गर्न — लेख्नुहोस्: ticket', 'gu' => '🎫 ટિકિટ બુક કરવા — લખો: ticket'][$lang];
            $lines[] = ['en' => '📋 My ticket — write: mero ticket', 'hi' => '📋 मेरा टिकट — लिखें: mero ticket',
                        'ne' => '📋 मेरो टिकट — लेख्नुहोस्: mero ticket', 'gu' => '📋 મારી ટિકિટ — લખો: mero ticket'][$lang];
            $lines[] = ['en' => '📞 Office — write: office', 'hi' => '📞 ऑफिस — लिखें: office',
                        'ne' => '📞 अफिस — लेख्नुहोस्: office', 'gu' => '📞 ઓફિસ — લખો: office'][$lang];
        }
        return implode("\n", $lines);
    }

    /** One line that tells the person the buttons below are the way in. */
    public static function menuHead(string $lang): string
    {
        return [
            'en' => 'What would you like to do? Tap a button below.',
            'hi' => 'क्या करना है? नीचे का बटन दबाइए।',
            'ne' => 'के गर्ने हो? तलको बटन थिच्नुहोस्।',
            'gu' => 'શું કરવું છે? નીચેનું બટન દબાવો.',
        ][in_array($lang, ['ne', 'hi', 'en', 'gu'], true) ? $lang : 'ne'];
    }

    /* ----------------------------------------------------------------- */

    /**
     * The sender's own coming tickets. Matched on the booking's contact
     * number only — the same rule the PNR reply uses — so nobody is shown
     * another number's ticket.
     */
    private static function myTickets(string $phoneDigits, string $lang): array
    {
        $rows = [];
        try {
            $rows = Database::fetchAll(
                "SELECT b.pnr, b.status, b.total_amount,
                        MIN(bl.travel_date) AS travel_date
                   FROM bookings b
                   JOIN booking_legs bl ON bl.booking_id = b.id
                  WHERE b.contact_phone = :p
                    AND b.status IN ('confirmed', 'pending')
                    AND bl.travel_date >= :d
                  GROUP BY b.id, b.pnr, b.status, b.total_amount
                  ORDER BY travel_date ASC, b.id DESC
                  LIMIT 4",
                ['p' => $phoneDigits, 'd' => todayISO()]
            );
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }

        $book = ['kind' => 'buttons', 'buttons' => [[
            'id'    => 'shg:ticket book ' . $lang,
            'title' => ['en' => '🎫 Book a ticket', 'hi' => '🎫 टिकट बुक करें', 'ne' => '🎫 टिकट बुक गर्ने', 'gu' => '🎫 ટિકિટ બુક કરો'][$lang] ?? '🎫 Book a ticket',
        ]]];

        if ($rows === []) {
            $text = [
                'en' => 'No coming ticket is booked on this number. Want to book one?',
                'hi' => 'इस नंबर पर आगे की कोई टिकट नहीं है। बुक करें?',
                'ne' => 'यो नम्बरमा आउँदो कुनै टिकट छैन। बुक गर्ने?',
                'gu' => 'આ નંબર પર આગળની કોઈ ટિકિટ નથી. બુક કરવી છે?',
            ][$lang] ?? 'No coming ticket is booked on this number.';
            return ['text' => $text . "\n(ticket)", 'media' => null, 'ui' => $book + ['body' => $text]];
        }

        $lines = [[
            'en' => '📋 Your coming tickets:', 'hi' => '📋 आपकी आने वाली टिकटें:',
            'ne' => '📋 तपाईंका आउँदा टिकट:', 'gu' => '📋 તમારી આવનારી ટિકિટો:',
        ][$lang] ?? '📋 Your coming tickets:'];
        $media = null;
        foreach ($rows as $r) {
            $paid    = (string) $r['status'] === 'confirmed';
            $lines[] = '• ' . (string) $r['pnr'] . ' · ' . formatDate((string) $r['travel_date'], 'D, j M')
                     . ' · ' . inr((float) $r['total_amount'])
                     . ' · ' . ($paid ? '✅' : '⏳ ' . (['en' => 'payment pending', 'hi' => 'भुगतान बाकी', 'ne' => 'भुक्तानी बाँकी', 'gu' => 'ચુકવણી બાકી'][$lang] ?? 'payment pending'));
            if ($media === null && $paid) {
                try {
                    $media = Ticket::imageUrl((string) $r['pnr']);
                } catch (Throwable $e) {
                    $media = null;
                }
            }
            if (!$paid) {
                $lines[] = '  ' . appUrl('pay.php?pnr=' . urlencode((string) $r['pnr']));
            }
        }
        $lines[] = '';
        $lines[] = [
            'en' => 'Send a booking number (SHG-…) to get that ticket again.',
            'hi' => 'कोई बुकिंग नंबर (SHG-…) भेजें, वही टिकट फिर मिलेगा।',
            'ne' => 'कुनै बुकिङ नम्बर (SHG-…) पठाउनुहोस्, त्यही टिकट फेरि आउँछ।',
            'gu' => 'કોઈ બુકિંગ નંબર (SHG-…) મોકલો, તે ટિકિટ ફરી મળશે.',
        ][$lang] ?? 'Send a booking number (SHG-…) to get that ticket again.';

        return ['text' => implode("\n", $lines), 'media' => $media, 'ui' => null];
    }

    /** A seller's own day: tickets, seats and the fare value they sold today. */
    private static function mySales(array $who, string $lang): array
    {
        $id = (int) ($who['adminId'] ?? 0);
        $n = 0; $seats = 0; $sum = 0.0;
        try {
            $row = Database::fetch(
                "SELECT COUNT(*) AS n, COALESCE(SUM(total_amount), 0) AS total,
                        COALESCE(SUM((SELECT COUNT(*) FROM booking_passengers p WHERE p.booking_id = b.id)), 0) AS seats
                   FROM bookings b
                  WHERE b.sold_by_admin_id = :a
                    AND b.status IN ('confirmed', 'completed', 'pending')
                    AND b.created_at >= :d0 AND b.created_at < :d1",
                ['a' => $id, 'd0' => todayISO() . ' 00:00:00', 'd1' => addDaysISO(todayISO(), 1) . ' 00:00:00']
            );
            $n     = (int) ($row['n'] ?? 0);
            $seats = (int) ($row['seats'] ?? 0);
            $sum   = (float) ($row['total'] ?? 0);
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
        $text = sprintf([
            'en' => "📊 Today (%s)\nTickets: %d · Seats: %d\nFare value: %s",
            'hi' => "📊 आज (%s)\nटिकट: %d · सीट: %d\nकुल किराया: %s",
            'ne' => "📊 आज (%s)\nटिकट: %d · सिट: %d\nजम्मा भाडा: %s",
            'gu' => "📊 આજે (%s)\nટિકિટ: %d · સીટ: %d\nકુલ ભાડું: %s",
        ][$lang] ?? "📊 Today (%s)\nTickets: %d · Seats: %d\nFare value: %s",
            formatDate(todayISO(), 'j M'), $n, $seats, inr($sum));

        return ['text' => $text, 'media' => null, 'ui' => [
            'kind' => 'buttons', 'body' => $text,
            'buttons' => [['id' => 'shg:ticket book ' . $lang,
                           'title' => ['en' => '🎫 Issue a ticket', 'hi' => '🎫 टिकट काटो', 'ne' => '🎫 टिकट काट्ने', 'gu' => '🎫 ટિકિટ કાપો'][$lang] ?? '🎫 Issue a ticket']],
        ]];
    }

    /** A menu tap means: whatever order was half-way is over. */
    private static function dropOpenOrder(string $phoneDigits): void
    {
        try {
            if (class_exists('WaBooking')) {
                WaBooking::clear($phoneDigits);
            }
        } catch (Throwable $e) {
            // best effort
        }
    }

    /** The desk speaks Nepali first: a bare ASCII command carries no language. */
    private static function langOf(string $text): string
    {
        $lang = class_exists('TicketBot') ? TicketBot::detectLang($text) : 'ne';
        return $lang === 'en' && preg_match('/\b(my|ticket[s]?|menu|start|options|sales)\b/i', $text) === 1
            && preg_match('/\b(my tickets?|my sales|main menu|options)\b/i', $text) !== 1
            ? 'ne' : $lang;
    }
}
