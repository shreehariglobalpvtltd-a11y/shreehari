<?php
/**
 * =====================================================================
 *  WaFlow — the booking FORM inside WhatsApp (15 Oct 2026)
 *
 *  Owner: "WhatsApp ma clickable form / template banaidinu - location,
 *  date, name ra number diyo, ticket WhatsApp mai aaune; fully automatic".
 *
 *  A WhatsApp Flow is Meta's native form: the customer taps "🎫 Book
 *  ticket", a sheet opens inside WhatsApp with
 *      Pickup (dropdown) · Date (calendar) · Seats · Name · Mobile ·
 *      other passengers' names · Cash on bus / UPI now
 *  and one Book button. The answers come back to the webhook as an
 *  `nfm_reply`; this file turns them into the same sale the chat makes
 *  (WaBooking::sellFromForm → QuickTicket::sellCustomer / ::sell), so
 *  every rule - seat cap, cut-off, gender lock, duplicate guard, the
 *  ticket PNG fan-out - is the one the rest of the app already has.
 *
 *  The form is STATIC (no Flow endpoint, no encryption keys): the stops,
 *  dates and seat counts travel with each message as the screen's data
 *  (flow_action = navigate), so a new stop or a price change needs no
 *  re-publish. The form itself is published once from
 *  admin/wa-form.php ("Create on Meta"), which stores the flow id in
 *  wa_flow_book_id. Until that id exists - or with wa_flow_on = 0 -
 *  nothing here runs and the chat questions answer as before.
 *
 *  Pressing Book in the form IS the confirmation: the customer chose
 *  every field and the button says "Book ticket". Nothing here marks a
 *  booking paid - Cash = confirmed pay-at-boarding exactly like the chat,
 *  UPI = the QR goes back.
 *
 *  19 Nov 2026 (WA-3, form v2):
 *    - The stored form must live in THIS WhatsApp account (META_WABA_ID).
 *      The old id was made under the previous account (WABA 2550012892113424)
 *      and could never be published here; publish() and the cron now see that
 *      (ownership()), drop it and make a fresh form in the current account.
 *      admin/wa-form.php has a "Re-create form" button (superadmin).
 *    - wa_flow_on is switched on only after Meta itself says the form is
 *      PUBLISHED in this account (goLive()), and enabled() stays off when the
 *      account changes later (wa_flow_waba no longer matches).
 *    - Only NAME and MOBILE must be typed: the mobile comes prefilled with the
 *      sender's own number and a 🇮🇳 +91 / 🇳🇵 +977 picker gives a typed
 *      10-digit mobile its code (the sender's own number, left as prefilled,
 *      keeps its own code whatever the picker says).
 *      Date is a list of the next 7 days; pickup, seats (1-6) and payment come
 *      preselected. A field that is wrong or missing is asked for again on its
 *      own (the form comes back filled in with what was sent, and the chat asks
 *      just that one question) instead of the order failing.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class WaFlow
{
    public const SCREEN = 'BOOK';

    /** Set by whatsapp/webhook.php: this reply goes out on the Meta number (the only channel that can carry a Flow). */
    public static bool $metaChannel = false;

    /** Test seam - null in production: fn(string $method, string $path, array $fields): array{http: int, json: array}. */
    public static ?Closure $graphStub = null;

    /** Seats one form can book (owner: 1-6). */
    public const MAX_SEATS = 6;

    /**
     * On, published IN THIS ACCOUNT, and the Meta credentials are there.
     * wa_flow_waba is written by goLive() when Meta confirmed PUBLISHED; a
     * form verified under another WhatsApp account never opens here.
     */
    public static function enabled(): bool
    {
        if (!Settings::getBool('wa_flow_on', false) || self::flowId() === '') {
            return false;
        }
        self::loadConfig();
        $waba = self::cfg('META_WABA_ID');
        return $waba === '' || Settings::getString('wa_flow_waba', '') === $waba;
    }

    /** whatsapp/config.php when it is there (it is not in a fresh checkout or a test). */
    private static function loadConfig(): void
    {
        if (!defined('META_ACCESS_TOKEN') && defined('ROOT_PATH') && is_file(ROOT_PATH . '/whatsapp/config.php')) {
            require_once ROOT_PATH . '/whatsapp/config.php';
        }
    }

    private static function cfg(string $name): string
    {
        return defined($name) ? trim((string) constant($name)) : '';
    }

    /** The Meta token and WABA id are both set. */
    public static function metaReady(): bool
    {
        self::loadConfig();
        return self::cfg('META_ACCESS_TOKEN') !== '' && self::cfg('META_WABA_ID') !== '';
    }

    public static function flowId(): string
    {
        return preg_replace('/\D/', '', Settings::getString('wa_flow_book_id', '')) ?? '';
    }

    /** May this reply carry the form? (Meta number, form published.) */
    public static function canSend(): bool
    {
        return self::$metaChannel && self::enabled();
    }

    /* ------------------------------------------------------------------
     *  The form (Flow JSON)
     * ---------------------------------------------------------------- */

    /**
     * The Flow JSON Meta stores (v2, 19 Nov 2026). Only NAME and MOBILE are
     * required; every other field carries a starting value from the screen's
     * data (init-value), except the travel day, which the customer always picks
     * (a wrong preselected day would sell a seat on a bus they never meant):
     * a form sent without it comes back asking for the day alone.
     *
     * @return array<string, mixed>
     */
    public static function flowJson(): array
    {
        $opt = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'description' => ['type' => 'string'],
        ]]];
        $ex  = static fn(string $id, string $t): array => ['id' => $id, 'title' => $t, 'description' => ''];
        $str = static fn(string $example): array => ['type' => 'string', '__example__' => $example];

        return [
            'version' => '6.0',
            'screens' => [[
                'id'       => self::SCREEN,
                'title'    => 'Book bus ticket',
                'terminal' => true,
                'success'  => true,
                'data'     => [
                    'intro'        => $str('Fill once, tap Book — the ticket comes here.'),
                    'name_init'    => $str('Ram Bahadur'),
                    'phone_init'   => $str('9812345678'),
                    'phone_hint'   => $str('Ticket goes to this WhatsApp'),
                    'country_opts' => $opt + ['__example__' => [$ex('IN', '🇮🇳 +91 India'), $ex('NP', '🇳🇵 +977 Nepal')]],
                    'country_init' => $str('NP'),
                    'date_opts'    => $opt + ['__example__' => [$ex('2026-11-19', 'आज · Today, 19 Nov')]],
                    'pickups'      => $opt + ['__example__' => [$ex('toNepal|Surat', '🇳🇵 Surat 13:00 → Nepal')]],
                    'pickup_init'  => $str('toNepal|Surat'),
                    'seat_opts'    => $opt + ['__example__' => [$ex('1', '1'), $ex('2', '2')]],
                    'seats_init'   => $str('1'),
                    'others_init'  => $str(''),
                    'pay_opts'     => $opt + ['__example__' => [$ex('cod', 'Cash on the bus')]],
                    'pay_init'     => $str('cod'),
                ],
                'layout' => [
                    'type'     => 'SingleColumnLayout',
                    'children' => [
                        ['type' => 'TextSubheading', 'text' => '${data.intro}'],
                        ['type' => 'TextInput', 'name' => 'name', 'label' => 'Name · नाम', 'input-type' => 'text', 'required' => true,
                         'init-value' => '${data.name_init}', 'helper-text' => 'Lead passenger, as on the ID'],
                        ['type' => 'TextInput', 'name' => 'phone', 'label' => 'Mobile · मोबाइल', 'input-type' => 'phone', 'required' => true,
                         'init-value' => '${data.phone_init}', 'helper-text' => '${data.phone_hint}'],
                        ['type' => 'RadioButtonsGroup', 'name' => 'country', 'label' => 'Mobile code · मोबाइल कोड', 'required' => false,
                         'data-source' => '${data.country_opts}', 'init-value' => '${data.country_init}'],
                        ['type' => 'Dropdown', 'name' => 'date', 'label' => 'Travel date · यात्रा मिति', 'required' => false,
                         'data-source' => '${data.date_opts}'],
                        ['type' => 'Dropdown', 'name' => 'pickup', 'label' => 'Pickup · चढ्ने ठाउँ', 'required' => false,
                         'data-source' => '${data.pickups}', 'init-value' => '${data.pickup_init}'],
                        ['type' => 'Dropdown', 'name' => 'seats', 'label' => 'Seats · सिट', 'required' => false,
                         'data-source' => '${data.seat_opts}', 'init-value' => '${data.seats_init}'],
                        ['type' => 'TextArea', 'name' => 'others', 'label' => 'Others · अरू नाम', 'required' => false,
                         'init-value' => '${data.others_init}', 'helper-text' => '2+ seats: other names, comma separated'],
                        ['type' => 'RadioButtonsGroup', 'name' => 'pay', 'label' => 'Payment · भुक्तानी', 'required' => false,
                         'data-source' => '${data.pay_opts}', 'init-value' => '${data.pay_init}'],
                        ['type' => 'Footer', 'label' => 'Book ticket · टिकट बुक', 'on-click-action' => [
                            'name'    => 'complete',
                            'payload' => [
                                'name'   => '${form.name}', 'phone' => '${form.phone}', 'country' => '${form.country}',
                                'date'   => '${form.date}', 'pickup' => '${form.pickup}', 'seats' => '${form.seats}',
                                'others' => '${form.others}', 'pay' => '${form.pay}',
                            ],
                        ]],
                    ],
                ],
            ]],
        ];
    }

    /**
     * The screen's data for one send: the stops both ways (the sender's likely
     * way first), the next 7 days, seats 1-6, the ways to pay and the starting
     * values. $prefill (what a form already sent) wins over every default, and
     * $error replaces the intro line so the customer sees what to fix.
     *
     * @param array<string, string> $prefill name, phone, country, date, pickup, seats, others, pay
     * @return array<string, mixed>
     */
    public static function screenData(string $lang, bool $forSeller, string $who = '', array $prefill = [], string $error = ''): array
    {
        require_once __DIR__ . '/smartbook.php';
        require_once __DIR__ . '/quickticket.php';

        $who      = preg_replace('/\D/', '', $who) ?? '';
        $senderNp = strlen($who) === 13 && str_starts_with($who, '977');
        $pickups  = self::pickupOptions($forSeller, $senderNp);

        $max = self::MAX_SEATS;
        try {
            $max = max(1, min(self::MAX_SEATS, BookingService::maxSeatsFor($forSeller)));
        } catch (Throwable $e) {
        }
        $seatOpts = [];
        for ($n = 1; $n <= $max; $n++) {
            $seatOpts[] = ['id' => (string) $n, 'title' => (string) $n, 'description' => ''];
        }

        $pay = [];
        $cod = Settings::getBool('allow_cod', true) || $forSeller;
        if ($cod) {
            $pay[] = ['id' => 'cod', 'title' => $forSeller ? '💵 Cash (desk)' : '💵 Cash on the bus · बसमा नगद', 'description' => ''];
        }
        if (!$forSeller && trim(Settings::getString('upi_id', '')) !== '') {
            $pay[] = ['id' => 'upi', 'title' => '📲 UPI now · अहिले UPI', 'description' => ''];
        }
        if ($pay === []) {
            $pay[] = ['id' => 'upi', 'title' => '📲 Pay online · अनलाइन', 'description' => ''];
        }

        $dates = self::dateOptions();

        /* Starting values. A seller's form is for somebody else: no name or
           number of the seller's own is ever put into it. */
        $last = (!$forSeller && $who !== '' && class_exists('WaBooking')) ? WaBooking::lastTripFor($who) : null;
        $ids  = static fn(array $list): array => array_map(static fn(array $o): string => (string) $o['id'], $list);

        $pickupInit = (string) ($prefill['pickup'] ?? '');
        if (!in_array($pickupInit, $ids($pickups), true)) {
            $pickupInit = self::defaultPickup($pickups, $last, $senderNp);
        }
        $seatsInit = (string) ($prefill['seats'] ?? '');
        if (!in_array($seatsInit, $ids($seatOpts), true)) {
            $seatsInit = '1';
        }
        $payInit = (string) ($prefill['pay'] ?? '');
        if (!in_array($payInit, $ids($pay), true)) {
            $payInit = (string) $pay[0]['id'];
        }
        $country = strtoupper((string) ($prefill['country'] ?? ''));
        if (!in_array($country, ['IN', 'NP'], true)) {
            $country = $senderNp ? 'NP' : 'IN';
        }
        $phoneInit = array_key_exists('phone', $prefill) ? (string) $prefill['phone'] : ($forSeller ? '' : self::localDigits($who));
        $nameInit  = array_key_exists('name', $prefill) ? (string) $prefill['name'] : (string) ($last['name'] ?? '');

        $intro = [
            'en' => 'Fill once, tap Book — the ticket comes here.',
            'hi' => 'एक बार भरें, Book दबाएँ — टिकट यहीं आएगा।',
            'ne' => 'एक पटक भर्नुहोस्, Book थिच्नुहोस् — टिकट यहीँ आउँछ।',
            'gu' => 'એક વાર ભરો, Book દબાવો — ટિકિટ અહીં આવશે.',
        ][$lang] ?? 'Fill once, tap Book — the ticket comes here.';
        if ($forSeller) {
            $intro = 'Desk order — the ticket goes to the customer\'s mobile.';
        }
        if (trim($error) !== '') {
            $intro = mb_substr('⚠️ ' . trim($error), 0, 80);
        }

        return [
            'intro'        => $intro,
            'name_init'    => mb_substr($nameInit, 0, 80),
            'phone_init'   => mb_substr($phoneInit, 0, 20),
            'phone_hint'   => $forSeller ? 'Customer mobile (ticket goes there)' : 'Ticket goes to this WhatsApp · टिकट यही नम्बरमा',
            'country_opts' => [
                ['id' => 'IN', 'title' => '🇮🇳 +91 India · भारत', 'description' => ''],
                ['id' => 'NP', 'title' => '🇳🇵 +977 Nepal · नेपाल', 'description' => ''],
            ],
            'country_init' => $country,
            'date_opts'    => $dates,
            'pickups'      => $pickups,
            'pickup_init'  => $pickupInit,
            'seat_opts'    => $seatOpts,
            'seats_init'   => $seatsInit,
            'others_init'  => mb_substr((string) ($prefill['others'] ?? ''), 0, 300),
            'pay_opts'     => $pay,
            'pay_init'     => $payInit,
        ];
    }

    /**
     * The pickup list, direction-aware: going to Nepal (route r2) every
     * boarding stop is its own row; coming back (r7) the bus boards at one
     * place, so that is one row and its drops are named under it. A Nepal
     * sender (+977) sees the way home to India first.
     *
     * @return list<array{id: string, title: string, description: string}>
     */
    private static function pickupOptions(bool $forSeller, bool $senderNp): array
    {
        $toNepal = [];
        $toIndia = [];
        foreach (SmartBook::directions() as $d) {
            $dir = (string) $d['direction'];
            if ($dir === 'toNepal') {
                foreach ((array) (SmartBook::points('toNepal', $forSeller ? 'counter' : 'customer')['list'] ?? []) as $p) {
                    $name = (string) $p['name'];
                    $toNepal[] = [
                        'id'          => mb_substr('toNepal|' . $name, 0, 80),
                        'title'       => mb_substr('🇳🇵 ' . (string) $p['short'] . ((string) $p['time'] !== '' ? ' ' . $p['time'] : ''), 0, 30),
                        'description' => mb_substr('→ ' . (string) $d['to'] . ((string) ($p['landmark'] ?? '') !== '' ? ' · ' . $p['landmark'] : ''), 0, 300),
                    ];
                }
            } elseif ($dir === 'toIndia') {
                $from  = (string) $d['from'];
                $drops = [];
                try {
                    foreach ((array) (SmartBook::points('toIndia', $forSeller ? 'counter' : 'customer')['list'] ?? []) as $p) {
                        $s = trim((string) ($p['short'] ?? $p['name'] ?? ''));
                        if ($s !== '') {
                            $drops[] = $s;
                        }
                    }
                } catch (Throwable $e) {
                    $drops = [];
                }
                $toIndia[] = [
                    'id'          => mb_substr('toIndia|' . $from, 0, 80),
                    'title'       => mb_substr('🇮🇳 ' . $from . ((string) $d['depTime'] !== '' ? ' ' . $d['depTime'] : '') . ' → Gujarat', 0, 30),
                    'description' => mb_substr($drops !== [] ? 'Drops · झर्ने: ' . implode(', ', array_unique($drops)) : $from . ' → ' . (string) $d['to'], 0, 300),
                ];
            }
        }
        return $senderNp ? array_merge($toIndia, $toNepal) : array_merge($toNepal, $toIndia);
    }

    /**
     * The next 7 days the booking window allows, today first.
     *
     * @return list<array{id: string, title: string, description: string}>
     */
    private static function dateOptions(): array
    {
        $window = bookingWindow();
        $from   = max((string) $window['from'], todayISO());
        $to     = (string) $window['to'];
        $near   = [0 => 'आज · Today', 1 => 'भोलि · Tomorrow'];
        $out    = [];
        for ($i = 0; $i < 7; $i++) {
            $iso = addDaysISO($from, $i);
            if ($to !== '' && $iso > $to) {
                break;
            }
            $dayIdx = (int) round((strtotime($iso) - strtotime(todayISO())) / 86400);
            $label  = formatDate($iso, 'D, j M');
            $out[]  = [
                'id'          => $iso,
                'title'       => mb_substr(isset($near[$dayIdx]) ? $near[$dayIdx] . ', ' . formatDate($iso, 'j M') : $label, 0, 30),
                'description' => '',
            ];
        }
        if ($out === []) {
            $out[] = ['id' => $from, 'title' => formatDate($from, 'D, j M'), 'description' => ''];
        }
        return $out;
    }

    /** The pickup a blank form books: this number's own last pickup, else the first stop of its likely way. */
    private static function defaultPickup(array $pickups, ?array $last, bool $senderNp): string
    {
        if ($pickups === []) {
            return '';
        }
        if (is_array($last)) {
            $dir   = (string) ($last['direction'] ?? '');
            $board = (string) ($last['boarding'] ?? '');
            foreach ($pickups as $p) {
                [$pd, $ps] = array_pad(explode('|', (string) $p['id'], 2), 2, '');
                if ($pd === $dir && ($dir === 'toIndia' || ($board !== '' && mb_strtolower($ps) === mb_strtolower($board)))) {
                    return (string) $p['id'];
                }
            }
        }
        return (string) $pickups[0]['id'];
    }

    /** 919876543210 / 9779812345678 → the 10-digit local number ('' when it is not one). */
    private static function localDigits(string $digits): string
    {
        $d = preg_replace('/\D/', '', $digits) ?? '';
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            return substr($d, 2);
        }
        if (strlen($d) === 13 && str_starts_with($d, '977')) {
            return substr($d, 3);
        }
        return strlen($d) === 10 ? $d : '';
    }

    /** A token that ties a submitted form to the number it was sent to. */
    public static function token(string $who): string
    {
        $who = preg_replace('/\D/', '', $who) ?? '';
        $ts  = (string) time();
        return 'shgf.' . $who . '.' . $ts . '.' . substr(hash_hmac('sha256', $who . '|' . $ts, self::key()), 0, 16);
    }

    /** Did WE send this form to this number (and not too long ago)? */
    public static function tokenOk(string $token, string $who): bool
    {
        $who = preg_replace('/\D/', '', $who) ?? '';
        if (preg_match('/^shgf\.(\d+)\.(\d+)\.([a-f0-9]{16})$/', $token, $m) !== 1) {
            return false;
        }
        if ($m[1] !== $who || (int) $m[2] < time() - 7 * 86400) {
            return false;
        }
        return hash_equals(substr(hash_hmac('sha256', $m[1] . '|' . $m[2], self::key()), 0, 16), $m[3]);
    }

    private static function key(): string
    {
        return (defined('APP_KEY') ? (string) APP_KEY : 'shg') . '|waflow';
    }

    /**
     * The interactive message that opens the form.
     *
     * $prefill / $error: the form comes back filled in with what was sent and
     * the one thing to fix on top (handleReply's data refresh).
     *
     * @param array<string, string> $prefill
     * @return array<string, mixed> a $ui for sendWhatsAppInteractive (kind = flow)
     */
    public static function ui(string $who, string $lang, bool $forSeller, string $body = '', array $prefill = [], string $error = ''): array
    {
        $cta = ['en' => '🎫 Book ticket', 'hi' => '🎫 टिकट बुक', 'ne' => '🎫 टिकट बुक', 'gu' => '🎫 ટિકિટ બુક'][$lang] ?? '🎫 Book ticket';
        if ($body === '') {
            $body = [
                'en' => "🎫 Book in one form: pickup, date, seats, name, mobile.\nTap the button below, fill it and press Book — your ticket comes right here.",
                'hi' => "🎫 एक फ़ॉर्म में बुकिंग: चढ़ने की जगह, तारीख, सीट, नाम, मोबाइल।\nनीचे बटन दबाएँ, भरें और Book दबाएँ — टिकट यहीं आएगा।",
                'ne' => "🎫 एउटै फारममा बुकिङ: चढ्ने ठाउँ, मिति, सिट, नाम, मोबाइल।\nतलको बटन थिच्नुहोस्, भर्नुहोस् र Book थिच्नुहोस् — टिकट यहीँ आउँछ।",
                'gu' => "🎫 એક ફોર્મમાં બુકિંગ: ચઢવાનું સ્થળ, તારીખ, સીટ, નામ, મોબાઇલ.\nનીચેનું બટન દબાવો, ભરો અને Book દબાવો — ટિકિટ અહીં આવશે.",
            ][$lang] ?? '';
        }
        return [
            'kind'   => 'flow',
            'body'   => $body,
            'footer' => ['en' => 'Or just type: Ram, Surat, tomorrow, 2', 'hi' => 'या लिखें: Ram, Surat, कल, 2', 'ne' => 'वा लेख्नुहोस्: Ram, Surat, भोलि, 2', 'gu' => 'અથવા લખો: Ram, Surat, કાલે, 2'][$lang] ?? '',
            'flow'   => [
                'id'     => self::flowId(),
                'token'  => self::token($who),
                'cta'    => $cta,
                'screen' => self::SCREEN,
                'data'   => self::screenData($lang, $forSeller, $who, $prefill, $error),
                'draft'  => Settings::getBool('wa_flow_draft', false),
            ],
        ];
    }

    /* ------------------------------------------------------------------
     *  The answer
     * ---------------------------------------------------------------- */

    /** Is this inbound interactive a submitted form? */
    public static function isReply(array $interactive): bool
    {
        return ($interactive['type'] ?? '') === 'nfm_reply' || isset($interactive['nfm_reply']);
    }

    /**
     * A submitted form → the sale. Returns the reply ({text, media, ui}).
     *
     * Every field is checked BEFORE anything is sold. A field that is wrong or
     * missing (a mobile that is not a mobile, a name that is a bus stop, no
     * travel day) is asked for again on its own: the chat asks just that one
     * question (WaBooking::formRetry keeps everything else that was right), and
     * on the Meta number the form comes back filled in with what was sent and
     * the problem written on top. Nothing is sold until it is right.
     *
     * @param array<string, mixed>      $interactive the webhook's message.interactive
     * @param array<string, mixed>|null $staff       AiTools::whoIs() row when the sender is staff
     * @return array{text: string, media: ?string, ui: ?array}
     */
    public static function handleReply(string $fromDigits, array $interactive, ?array $staff = null, string $senderCountry = ''): array
    {
        require_once __DIR__ . '/wabooking.php';
        $raw  = (string) ($interactive['nfm_reply']['response_json'] ?? '{}');
        $resp = json_decode($raw, true);
        $resp = is_array($resp) ? $resp : [];
        $open = WaBooking::openLang($fromDigits);
        $lang = in_array($open, ['en', 'ne', 'hi', 'gu'], true) ? $open : 'ne';

        $token = (string) ($resp['flow_token'] ?? '');
        if (!self::tokenOk($token, $fromDigits)) {
            return ['text' => 'This form is old — write "ticket" for a new one. / यो फारम पुरानो भयो — नयाँका लागि "ticket" लेख्नुहोस्।', 'media' => null, 'ui' => null];
        }
        // One form, one sale: a second submit of the same sheet is answered, not sold twice.
        if (!Security::rateLimit('wa_flow_tok', $token, 1, 7 * 86400)) {
            return ['text' => 'This form was already booked — your ticket is above. / यो फारमबाट टिकट बनिसक्यो — माथि हेर्नुहोस्।', 'media' => null, 'ui' => null];
        }

        $seller    = (is_array($staff) && (int) ($staff['adminId'] ?? 0) > 0 && is_array($staff['admin'] ?? null)) ? $staff['admin'] : null;
        $forSeller = $seller !== null;
        $read      = self::readForm($resp, $fromDigits, $forSeller, $senderCountry);
        $f         = $read['fields'];

        $slots = [
            'direction' => $f['dir'],
            'boarding'  => $f['dir'] === 'toNepal' ? $f['stop'] : '',
            'date'      => $f['date'],
            'seats'     => $f['seats'],
            'name'      => $f['name'],
            'party'     => [],
            'country'   => $f['country'],
            '_started'  => time(),
            '_turns'    => 1,
        ];
        foreach (['direction', 'boarding', 'date'] as $k) {
            if ($slots[$k] === '') {
                unset($slots[$k]);
            }
        }
        /* 19 Nov 2026 (review): the mobile typed in the form and the UPI choice
           ride along into the chat re-ask too - a missing day asked in the chat
           must never move the ticket back to the sender's number, nor turn a
           "pay by UPI now" into the office default. */
        $typedContact = (string) $f['contact'];
        if ($typedContact !== '') {
            if ($seller !== null) {
                $slots['custPhone'] = $typedContact;
            } elseif ($typedContact !== (preg_replace('/\D/', '', $fromDigits) ?? '')) {
                $slots['contact'] = $typedContact;
            }
        }
        if ($seller === null && $f['pay'] === 'upi') {
            $slots['_pay'] = 'upi';
        }
        // The lead first, then the others typed in the form - every traveller on their own berth.
        if ($f['seats'] > 1 && $f['others'] !== '' && $f['name'] !== '') {
            $slots['party'] = array_merge([['name' => $f['name'], 'age' => null]], WaBooking::partyFrom($f['others'], $f['seats'] - 1));
        }

        Logger::audit('wa.flow_submit', 'whatsapp', $fromDigits, null,
            ['dir' => $f['dir'], 'stop' => $f['stop'], 'date' => $f['date'], 'seats' => $f['seats'], 'country' => $f['country'],
             'pay' => $f['pay'] ?: 'cod', 'seller' => $seller !== null ? (int) $seller['id'] : null, 'bad' => $read['bad']],
            'WhatsApp booking form submitted');

        if ($read['bad'] !== '') {
            return self::askAgain($fromDigits, $slots, $read, $lang, $seller);
        }

        $reply = WaBooking::sellFromForm($fromDigits, $slots, $lang, $f['country'], $f['pay'], $seller, $f['contact']);
        /* Not sold (full bus, cut-off passed, a ticket already on that number…):
           the reason stays the answer, and the form comes back filled in so one
           change - another day or pickup - books it. */
        if (WaBooking::$lastSale === null && self::canSend() && empty($reply['ui'])) {
            $why = trim((string) strtok((string) $reply['text'], "\n"));
            $reply['ui'] = self::ui($fromDigits, $lang, $forSeller,
                trim((string) $reply['text']) . "\n\n" . self::fixLine($lang), $read['prefill'], $why);
        }
        return $reply;
    }

    /**
     * The submitted answers, cleaned, and the first one that cannot be used
     * ('' = all good). Order of asking: mobile, name, day, pickup.
     *
     * @param array<string, mixed> $resp
     * @return array{fields: array{name: string, contact: string, country: string, date: string, dir: string, stop: string, seats: int, others: string, pay: string}, bad: string, prefill: array<string, string>}
     */
    public static function readForm(array $resp, string $fromDigits, bool $forSeller, string $senderCountry = ''): array
    {
        $from    = preg_replace('/\D/', '', $fromDigits) ?? '';
        $country = strtoupper(trim((string) ($resp['country'] ?? '')));
        if (!in_array($country, ['IN', 'NP'], true)) {
            $sc      = strtoupper(trim($senderCountry));
            $country = in_array($sc, ['NP', '977'], true) ? 'NP'
                : (in_array($sc, ['IN', '91'], true) ? 'IN' : ((strlen($from) === 13 && str_starts_with($from, '977')) ? 'NP' : 'IN'));
        }
        $bad = [];

        // Mobile: 10 digits + the country picked, or the full +91 / +977 number (its own code wins).
        $typed   = trim((string) ($resp['phone'] ?? ''));
        $digits  = preg_replace('/\D/', '', class_exists('TicketBot') ? TicketBot::asciiDigits($typed) : $typed) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }
        $contact = '';
        $own     = self::localDigits($from);
        if ($typed === '' && !$forSeller) {
            $contact = $from;                                   // left blank: the sender's own WhatsApp
        } elseif (!$forSeller && strlen($digits) === 10 && $own !== '' && $digits === $own) {
            /* 19 Nov 2026 (review): the prefilled number left as it was is the
               sender's OWN WhatsApp, whatever the picker says - flipping the
               picker never turns it into another country's (a stranger's) number. */
            $contact = $from;
            $country = (strlen($from) === 13 && str_starts_with($from, '977')) ? 'NP' : 'IN';
        } elseif (strlen($digits) === 10) {
            $contact = ($country === 'NP' ? '977' : '91') . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $contact = $digits;
            $country = 'IN';
        } elseif (strlen($digits) === 13 && str_starts_with($digits, '977')) {
            $contact = $digits;
            $country = 'NP';
        }
        if ($contact === '' || !Security::isValidPhone(normalisePhone($contact))) {
            $bad[]   = 'phone';
            $contact = '';
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) ($resp['name'] ?? '')) ?? '');
        if ($name === '' || PersonName::clean($name) === '') {
            $bad[] = 'name';
        }

        $date = trim((string) ($resp['date'] ?? ''));
        if (preg_match('/^\d{10,13}$/', $date) === 1) {          // older clients send epoch millis
            $date = date('Y-m-d', (int) floor((int) $date / 1000));
        }
        $window = bookingWindow();
        if (!Security::isValidDate($date) || $date < todayISO() || ((string) $window['to'] !== '' && $date > (string) $window['to'])) {
            $bad[] = 'date';
            $date  = '';
        }

        $pickup = trim((string) ($resp['pickup'] ?? ''));
        [$dir, $stop] = array_pad(explode('|', $pickup, 2), 2, '');
        if (!in_array($dir, ['toNepal', 'toIndia'], true) || ($dir === 'toNepal' && trim($stop) === '')) {
            $bad[] = 'pickup';
            $dir   = '';
            $stop  = '';
        }

        $seats  = max(1, min(self::MAX_SEATS, (int) ($resp['seats'] ?? 1)));
        $others = trim((string) ($resp['others'] ?? ''));
        $pay    = (string) ($resp['pay'] ?? '') === 'upi' ? 'upi' : '';

        $prefill = [
            'name'    => in_array('name', $bad, true) ? '' : $name,
            'phone'   => in_array('phone', $bad, true) ? '' : self::localDigits($contact),
            'country' => $country,
            'pickup'  => $dir !== '' ? $pickup : '',
            'seats'   => (string) $seats,
            'others'  => $others,
            'pay'     => $pay !== '' ? $pay : 'cod',
        ];

        return [
            'fields'  => [
                'name' => in_array('name', $bad, true) ? '' : $name, 'contact' => $contact, 'country' => $country,
                'date' => $date, 'dir' => $dir, 'stop' => $stop, 'seats' => $seats, 'others' => $others, 'pay' => $pay,
            ],
            'bad'     => $bad[0] ?? '',
            'prefill' => $prefill,
        ];
    }

    /**
     * The one field that was wrong, asked again: the chat keeps every answer
     * that was right and waits for just this one (WaBooking::formRetry); on
     * the Meta number the filled-in form comes back as well.
     *
     * @param array{fields: array<string, mixed>, bad: string, prefill: array<string, string>} $read
     * @return array{text: string, media: ?string, ui: ?array}
     */
    private static function askAgain(string $fromDigits, array $slots, array $read, string $lang, ?array $seller): array
    {
        $field = $read['bad'];
        $why   = self::badLine($field, $lang);
        $reply = WaBooking::formRetry($fromDigits, $slots, $field === 'pickup' ? 'boarding' : ($field === 'phone' ? 'contact' : $field), $lang, $seller);
        $reply['text'] = $why . "\n" . (string) $reply['text'];
        if (self::canSend()) {
            $reply['ui'] = self::ui($fromDigits, $lang, $seller !== null, $why . "\n" . self::fixLine($lang), $read['prefill'], $why);
        }
        return $reply;
    }

    /** What was wrong with a field, in the customer's language (Nepali with English beside). */
    public static function badLine(string $field, string $lang): string
    {
        $t = [
            'phone' => [
                'ne' => '📱 मोबाइल नम्बर मिलेन (१० अंक, +977 वा +91) · Mobile number not clear',
                'hi' => '📱 मोबाइल नंबर सही नहीं (10 अंक, +91 या +977) · Mobile number not clear',
                'en' => '📱 The mobile number is not clear (10 digits, +91 or +977).',
                'gu' => '📱 મોબાઇલ નંબર સ્પષ્ટ નથી (10 અંક, +91 કે +977) · Mobile number not clear',
            ],
            'name' => [
                'ne' => '👤 यात्रुको नाम मिलेन · Passenger name not clear',
                'hi' => '👤 यात्री का नाम सही नहीं · Passenger name not clear',
                'en' => '👤 The passenger name is not clear.',
                'gu' => '👤 મુસાફરનું નામ સ્પષ્ટ નથી · Passenger name not clear',
            ],
            'date' => [
                'ne' => '📅 यात्रा मिति छानिएन · Travel date not chosen',
                'hi' => '📅 यात्रा की तारीख नहीं चुनी · Travel date not chosen',
                'en' => '📅 The travel date was not chosen.',
                'gu' => '📅 મુસાફરીની તારીખ પસંદ નથી · Travel date not chosen',
            ],
            'pickup' => [
                'ne' => '🚏 चढ्ने ठाउँ मिलेन · Pickup not clear',
                'hi' => '🚏 चढ़ने की जगह सही नहीं · Pickup not clear',
                'en' => '🚏 The pickup point is not clear.',
                'gu' => '🚏 ચઢવાનું સ્થળ સ્પષ્ટ નથી · Pickup not clear',
            ],
        ];
        return $t[$field][$lang] ?? ($t[$field]['ne'] ?? '');
    }

    private static function fixLine(string $lang): string
    {
        return [
            'ne' => 'तलको फारममा मिलाएर Book थिच्नुहोस्, वा यहीँ जवाफ लेख्नुहोस्। · Fix it in the form below, or just reply here.',
            'hi' => 'नीचे फ़ॉर्म में ठीक करके Book दबाएँ, या यहीं जवाब लिखें। · Fix it in the form below, or just reply here.',
            'en' => 'Fix it in the form below and press Book, or just reply here.',
            'gu' => 'નીચેના ફોર્મમાં સુધારી Book દબાવો, અથવા અહીં જવાબ લખો. · Fix it in the form below, or just reply here.',
        ][$lang] ?? 'Fix it in the form below, or just reply here.';
    }

    /* ------------------------------------------------------------------
     *  Publishing (admin/wa-form.php)
     * ---------------------------------------------------------------- */

    /**
     * Create the form on Meta and publish it. A published Flow can never be
     * edited, so every press makes a NEW flow and the new id replaces the
     * old one (the old form keeps working for messages already sent).
     *
     * 19 Nov 2026 (WA-3): first the stored form is checked against THIS
     * account (ownership()). One that lives in another WhatsApp account (the
     * old WABA) or that Meta has retired is dropped and a fresh one is made
     * here - Meta could never publish the old one for this number. $fresh =
     * the "Re-create form" button: drop whatever is stored and start over.
     * The form is switched on for customers only when Meta then reports it
     * PUBLISHED in this account (goLive()).
     *
     * @param array{state: string, status: string, error: string}|null $known ownership() already read by the caller
     * @return array{ok: bool, id: string, error: string, errors: list<string>, replaced: string, why: string}
     */
    public static function publish(bool $fresh = false, ?array $known = null): array
    {
        self::loadConfig();
        $out = ['ok' => false, 'id' => '', 'error' => '', 'errors' => [], 'replaced' => '', 'why' => ''];
        if (!self::metaReady()) {
            $out['error'] = 'The Meta token or WABA id is missing (Settings → WhatsApp: whatsapp_api_token, whatsapp_waba_id).';
            return $out;
        }
        $own = ['state' => 'none', 'status' => '', 'error' => ''];
        if (self::flowId() !== '') {
            $own = $fresh ? ['state' => 'recreate', 'status' => '', 'error' => ''] : ($known ?? self::ownership());
            if (in_array($own['state'], ['foreign', 'deprecated', 'recreate'], true)) {
                $out['replaced'] = self::flowId();
                $out['why']      = $own['state'];
                self::forget($own['state']);
                $own = ['state' => 'none', 'status' => '', 'error' => ''];
            } elseif ($own['state'] === 'unknown') {
                // Meta could not be asked which account the form is in: change nothing.
                $out['id']    = self::flowId();
                $out['error'] = 'Could not check the form on Meta: ' . ($own['error'] !== '' ? $own['error'] : 'no answer') . '. Nothing was changed - try again.';
                return $out;
            }
        }
        // Ours and already PUBLISHED, but not switched on yet (or confirmed under another account): just switch it on.
        if ($own['state'] === 'own' && $own['status'] === 'PUBLISHED'
            && (Settings::getBool('wa_flow_draft', false) || !self::enabled())) {
            if (self::goLive($own['status'])) {
                return ['ok' => true, 'id' => self::flowId(), 'error' => '', 'errors' => [], 'replaced' => '', 'why' => ''];
            }
        }
        // A draft made earlier in THIS account (Meta refused to publish it then): publish THAT one - no duplicate forms.
        if (self::flowId() !== '' && Settings::getBool('wa_flow_draft', false)) {
            $res = self::graph('POST', '/' . self::flowId() . '/publish', []);
            if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['json']['success'])) {
                $live = self::goLive();
                return ['ok' => $live, 'id' => self::flowId(),
                        'error' => $live ? '' : 'Meta accepted the publish, but does not show the form as PUBLISHED yet - it stays off; try again in a few minutes.',
                        'errors' => [], 'replaced' => $out['replaced'], 'why' => $out['why']];
            }
            $out['id']    = self::flowId();
            $out['error'] = self::metaError($res);
            // Why Meta refused: the flow's own validation_errors say exactly which element it rejects.
            $st = self::status();
            if (!empty($st['validation_errors'])) {
                $out['errors'] = $st['validation_errors'];
            }
            return $out;
        }
        $fields = [
            'name'       => 'SHG booking ' . date('Y-m-d H:i'),
            'categories' => json_encode(['APPOINTMENT_BOOKING']),
            'flow_json'  => json_encode(self::flowJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'publish'    => 'true',
        ];
        $res = self::graph('POST', '/' . rawurlencode(self::cfg('META_WABA_ID')) . '/flows', $fields);
        $id  = (string) ($res['json']['id'] ?? '');
        // Created but not published: Meta names the new flow inside the error ("Flow ID: 123…").
        if ($id === '' && preg_match('/Flow ID:\s*(\d{6,})/', self::metaError($res), $m) === 1) {
            $id = $m[1];
        }
        foreach ((array) ($res['json']['validation_errors'] ?? []) as $v) {
            $out['errors'][] = trim((string) ($v['error'] ?? '') . ': ' . (string) ($v['message'] ?? ''), ': ');
        }
        $out['id'] = $id;
        $published = $res['http'] >= 200 && $res['http'] < 300 && $id !== '' && $out['errors'] === [];
        if ($id !== '') {
            /* Kept either way, and always OFF for customers until goLive() hears
               PUBLISHED from Meta. A DRAFT stays a draft: "Publish" and the
               cron retry this same draft once Meta allows it. */
            Settings::set('wa_flow_book_id', $id, 'string', 'whatsapp');
            Settings::set('wa_flow_draft', '1', 'bool', 'whatsapp');
            Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
            Settings::set('wa_flow_template_status', '', 'string', 'whatsapp');
        }
        if (!$published) {
            $out['error'] = self::metaError($res) !== '' ? self::metaError($res) : 'Meta created the form as a draft but did not publish it.';
            return $out;
        }
        $out['ok'] = self::goLive();
        if (!$out['ok']) {
            $out['error'] = 'Meta made the form, but does not show it as PUBLISHED yet - it stays off; press Publish again in a few minutes.';
        }
        return $out;
    }

    /**
     * Which WhatsApp account the stored form lives in, asked of Meta (a GET,
     * free): the current account's own list of forms is the proof.
     *   own        - in this account (status = DRAFT / PUBLISHED / BLOCKED …)
     *   foreign    - not in this account's list (made under the old WABA, or deleted)
     *   deprecated - ours, but Meta retired it: a new one is needed
     *   unknown    - Meta could not be asked; nothing may be changed on this
     *   none       - no form stored
     *
     * @return array{state: string, status: string, error: string}
     */
    public static function ownership(): array
    {
        $id = self::flowId();
        if ($id === '') {
            return ['state' => 'none', 'status' => '', 'error' => ''];
        }
        if (!self::metaReady()) {
            return ['state' => 'unknown', 'status' => '', 'error' => 'Meta token or WABA id missing'];
        }
        $path  = '/' . rawurlencode(self::cfg('META_WABA_ID')) . '/flows?fields=id,name,status&limit=100';
        $after = '';
        for ($page = 0; $page < 5; $page++) {
            $res = self::graph('GET', $path . ($after !== '' ? '&after=' . rawurlencode($after) : ''), []);
            if ($res['http'] < 200 || $res['http'] >= 300 || !isset($res['json']['data']) || !is_array($res['json']['data'])) {
                return ['state' => 'unknown', 'status' => '', 'error' => self::metaError($res) !== '' ? self::metaError($res) : 'no answer from Meta'];
            }
            foreach ($res['json']['data'] as $f) {
                if ((string) ($f['id'] ?? '') === $id) {
                    $status = strtoupper((string) ($f['status'] ?? ''));
                    return ['state' => $status === 'DEPRECATED' ? 'deprecated' : 'own', 'status' => $status, 'error' => ''];
                }
            }
            $after = (string) ($res['json']['paging']['cursors']['after'] ?? '');
            if ($after === '' || empty($res['json']['paging']['next'])) {
                return ['state' => 'foreign', 'status' => '', 'error' => ''];
            }
        }
        return ['state' => 'unknown', 'status' => '', 'error' => 'too many forms to check'];
    }

    /**
     * Drop the stored form: it lives in another account, Meta retired it, or
     * the office asked for a fresh one. Customers fall back to the chat
     * questions at once; the old id is kept in wa_flow_dropped for the record.
     */
    public static function forget(string $why): void
    {
        $old = self::flowId();
        if ($old === '') {
            return;
        }
        Settings::set('wa_flow_book_id', '', 'string', 'whatsapp');
        Settings::set('wa_flow_draft', '0', 'bool', 'whatsapp');
        Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
        Settings::set('wa_flow_waba', '', 'string', 'whatsapp');
        Settings::set('wa_flow_template_status', '', 'string', 'whatsapp');   // a template points at ONE form
        Settings::set('wa_flow_dropped', $old . ' · ' . $why . ' · ' . date('Y-m-d H:i'), 'string', 'whatsapp');
        Logger::audit('wa.flow_dropped', 'settings', 'whatsapp', null,
            ['old' => $old, 'why' => $why, 'waba' => self::cfg('META_WABA_ID')],
            'WhatsApp booking form ' . $old . ' dropped (' . $why . ')');
    }

    /**
     * Switch the form on for customers - only when Meta itself says it is
     * PUBLISHED in this account. Otherwise it stays a draft and OFF.
     */
    private static function goLive(string $status = ''): bool
    {
        if ($status === '') {
            $st     = self::status();
            $status = strtoupper((string) ($st['status'] ?? ''));
        }
        if ($status !== 'PUBLISHED') {
            Settings::set('wa_flow_draft', '1', 'bool', 'whatsapp');
            Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
            return false;
        }
        Settings::set('wa_flow_draft', '0', 'bool', 'whatsapp');
        Settings::set('wa_flow_waba', self::cfg('META_WABA_ID'), 'string', 'whatsapp');
        Settings::set('wa_flow_on', '1', 'bool', 'whatsapp');
        return true;
    }

    private static function metaError(array $res): string
    {
        $e = (array) ($res['json']['error'] ?? []);
        return trim((string) ($e['error_user_msg'] ?? ($e['message'] ?? ($res['http'] >= 300 ? 'HTTP ' . $res['http'] : ''))));
    }

    /**
     * The template that carries the form button to a number whose 24 h window
     * is closed. 19 Nov 2026: the same name as WaTplMeta's 'shg_booking_form'
     * (includes/watplmeta.php), so the office's template page and this form
     * talk about one template. The old 'shg_book_form' only ever existed in
     * the previous WhatsApp account.
     */
    public const TEMPLATE = 'shg_booking_form';
    public const STARTER_TEMPLATE = 'shg_booking_start';

    /** includes/watplmeta.php (unit u1's template registry) when it is installed. */
    private static function tplMeta(): bool
    {
        if (!class_exists('WaTplMeta') && is_file(__DIR__ . '/watplmeta.php')) {
            require_once __DIR__ . '/watplmeta.php';
        }
        return class_exists('WaTplMeta');
    }

    /** May the form template be tried? WaTplMeta knows its real Meta status; without it, only once it was submitted. */
    public static function templateReady(): bool
    {
        if (self::tplMeta() && method_exists('WaTplMeta', 'ready')) {
            try {
                return (bool) WaTplMeta::ready(self::TEMPLATE);
            } catch (Throwable $e) {
                return false;
            }
        }
        return in_array(strtoupper(Settings::getString('wa_flow_template_status', '')), ['APPROVED', 'EXISTS', 'PENDING'], true);
    }

    /** UTILITY by default (wa_flow_tpl_category); MARKETING only when the office says so. */
    public static function templateCategory(): string
    {
        $c = strtoupper(trim(Settings::getString('wa_flow_tpl_category', 'UTILITY')));
        return in_array($c, ['UTILITY', 'MARKETING'], true) ? $c : 'UTILITY';
    }

    /**
     * The approved TEMPLATE that carries the form button (owner: "euta
     * template banaidinu"). A plain form message only goes inside the
     * customer's 24 h window; the template reaches anyone, any time. Meta
     * reviews it - usually minutes. Category: wa_flow_tpl_category (UTILITY).
     *
     * @return array{ok: bool, error: string}
     */
    public static function createTemplate(): array
    {
        self::loadConfig();
        if (self::flowId() === '' || !self::metaReady()) {
            return ['ok' => false, 'error' => 'Publish the form first (and the Meta token / WABA id must be set).'];
        }
        $co = (string) (Settings::company()['name'] ?? 'S Hari Global');
        $components = [
            ['type' => 'BODY', 'text' => '🎫 ' . $co . " bus ticket booking form: pickup, date, seats, name and mobile. Press Book and the ticket comes right here on WhatsApp.\n\nएउटै फारममा टिकट बुक गर्नुहोस् — चढ्ने ठाउँ, मिति, सिट, नाम र मोबाइल। Book थिचेपछि टिकट यहीँ WhatsApp मा आउँछ।"],
            ['type' => 'BUTTONS', 'buttons' => [[
                'type' => 'FLOW', 'text' => 'Book ticket', 'flow_id' => self::flowId(),
                'navigate_screen' => self::SCREEN, 'flow_action' => 'navigate',
            ]]],
        ];
        /* One wording for one template: when the template registry
           (WaTplMeta, admin/wa-templates.php) defines shg_booking_form, its
           body / footer / FLOW button are used, so whichever side submits it
           first, Meta sees the same template. */
        try {
            if (self::tplMeta() && method_exists('WaTplMeta', 'defs') && method_exists('WaTplMeta', 'payload')) {
                $defs = WaTplMeta::defs();
                if (isset($defs[self::TEMPLATE])) {
                    $p = WaTplMeta::payload(self::TEMPLATE, $defs[self::TEMPLATE], '', self::flowId());
                    if (is_array($p['components'] ?? null) && $p['components'] !== []) {
                        $components = $p['components'];
                    }
                }
            }
        } catch (Throwable $e) {
            // the wording above stands
        }
        $res = self::graph('POST', '/' . rawurlencode(self::cfg('META_WABA_ID')) . '/message_templates', [
            'name' => self::TEMPLATE, 'language' => 'en', 'category' => self::templateCategory(),
            'components' => json_encode($components, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        if ($res['http'] >= 200 && $res['http'] < 300 && isset($res['json']['id'])) {
            Settings::set('wa_flow_template_status', (string) ($res['json']['status'] ?? 'PENDING'), 'string', 'whatsapp');
            return ['ok' => true, 'error' => ''];
        }
        return ['ok' => false, 'error' => (string) ($res['json']['error']['error_user_msg'] ?? ($res['json']['error']['message'] ?? ('HTTP ' . $res['http'])))];
    }

    /**
     * Send the form to a number: the free in-window message first, the
     * template when the window is closed (Meta 131047) and the template exists.
     * $country ('IN' / 'NP'): a bare 10-digit number is +91 or +977 by it -
     * India and Nepal share 10-digit mobiles, so it is never guessed.
     *
     * @return array{success: bool, error: string, via: string}
     */
    public static function sendTo(string $phone, string $lang = 'ne', bool $forSeller = false, string $country = ''): array
    {
        require_once ROOT_PATH . '/whatsapp/api.php';
        $hint = in_array(strtoupper($country), ['NP', '977'], true) ? 'NP' : (in_array(strtoupper($country), ['IN', '91'], true) ? 'IN' : null);
        $to   = waCleanPhone($phone, $hint);
        if ($to === '') {
            return ['success' => false, 'error' => 'Not a mobile number.', 'via' => ''];
        }
        if (!self::enabled() || Settings::getBool('wa_flow_draft', false)) {
            return self::sendBookingStarter($to, $lang);
        }
        $ui = self::ui($to, $lang, $forSeller);
        $r  = sendWhatsAppInteractive($to, (string) $ui['body'], $ui, null, $hint);
        if ($r['success']) {
            return ['success' => true, 'error' => '', 'via' => 'form message (free, inside 24 h)'];
        }
        if (!self::templateReady()) {
            return ['success' => false, 'error' => 'Form: ' . $r['error'] . ' · Template ' . self::TEMPLATE . ' is not approved yet.', 'via' => ''];
        }
        require_once INCLUDE_PATH . '/waroute.php';
        $refusal = WaRoute::paidRefusal();
        if ($refusal !== '') {
            return ['success' => false, 'error' => 'Form: ' . $r['error'] . ' · ' . $refusal, 'via' => ''];
        }
        $t = waGraphPost($to, ['type' => 'template', 'template' => [
            'name' => self::TEMPLATE, 'language' => ['code' => 'en'],
            'components' => [[
                'type' => 'button', 'sub_type' => 'flow', 'index' => '0',
                'parameters' => [['type' => 'action', 'action' => [
                    'flow_token' => (string) $ui['flow']['token'],
                    'flow_action_data' => (object) $ui['flow']['data'],
                ]]],
            ]],
        ]], 'template:' . self::TEMPLATE);
        if ($t['success']) {
            WaRoute::count('meta_tpl');
            return ['success' => true, 'error' => '', 'via' => 'template ' . self::TEMPLATE . ' (paid, outside 24 h)'];
        }
        return ['success' => false, 'error' => 'Form: ' . $r['error'] . ' · Template: ' . $t['error'], 'via' => ''];
    }

    /** Send a chat booking invitation when the native form is unavailable. $to is already international (91… / 977…). */
    private static function sendBookingStarter(string $to, string $lang): array
    {
        require_once INCLUDE_PATH . '/waroute.php';
        require_once INCLUDE_PATH . '/wabooking.php';
        require_once INCLUDE_PATH . '/notify.php';

        $lang = in_array($lang, ['en', 'ne', 'hi', 'gu'], true) ? $lang : 'ne';
        $hint = str_starts_with($to, '977') ? 'NP' : 'IN';
        if (WaRoute::metaWindowOpen($to)) {
            $text = WaBooking::bookingPrompt($lang);
            $reply = sendWhatsAppInteractive($to, $text, [
                'kind' => 'buttons',
                'buttons' => [['id' => 'shg:ticket book ' . $lang, 'title' => 'Book ticket']],
            ], null, $hint);
            if ($reply['success']) {
                return ['success' => true, 'error' => '', 'via' => 'booking chat (open service window)'];
            }
            if ((int) ($reply['code'] ?? 0) !== 131047) {
                return ['success' => false, 'error' => (string) $reply['error'], 'via' => ''];
            }
        }

        $refusal = WaRoute::paidRefusal();
        if ($refusal !== '') {
            return ['success' => false, 'error' => $refusal, 'via' => ''];
        }
        $reply = waGraphPost($to, ['type' => 'template', 'template' => [
            'name' => self::STARTER_TEMPLATE, 'language' => ['code' => 'en'],
            'components' => [[
                'type' => 'button', 'sub_type' => 'quick_reply', 'index' => '0',
                'parameters' => [['type' => 'payload', 'payload' => 'shg:ticket book ' . $lang]],
            ]],
        ]], 'template:' . self::STARTER_TEMPLATE);
        if ($reply['success']) {
            WaRoute::count('meta_tpl');
        }
        Notify::logOutbound($to, 'Meta booking starter', $reply['success'] ? 'sent' : 'failed', [
            'provider' => 'cloud_api', 'purpose' => 'booking_start',
            'sid' => (string) $reply['message_id'], 'error' => $reply['success'] ? null : (string) $reply['error'],
        ]);
        return ['success' => (bool) $reply['success'], 'error' => (string) $reply['error'],
                'via' => $reply['success'] ? 'Meta booking starter template' : ''];
    }

    /** Status of the stored flow on Meta (PUBLISHED / DRAFT / DEPRECATED …). */
    public static function status(): array
    {
        self::loadConfig();
        if (self::flowId() === '' || self::cfg('META_ACCESS_TOKEN') === '') {
            return ['status' => '', 'error' => '', 'validation_errors' => []];
        }
        $res = self::graph('GET', '/' . self::flowId() . '?fields=id,name,status,validation_errors', []);
        $errs = [];
        foreach ((array) ($res['json']['validation_errors'] ?? []) as $v) {
            $errs[] = trim((string) ($v['error'] ?? '') . ': ' . (string) ($v['message'] ?? ''), ': ');
        }
        return [
            'status' => (string) ($res['json']['status'] ?? ''),
            'name'   => (string) ($res['json']['name'] ?? ''),
            'error'  => (string) ($res['json']['error']['message'] ?? ''),
            'validation_errors' => $errs,
        ];
    }

    /** @return array{http: int, json: array<string, mixed>} */
    private static function graph(string $method, string $path, array $fields): array
    {
        if (self::$graphStub !== null) {
            $r = (self::$graphStub)($method, $path, $fields);
            return ['http' => (int) ($r['http'] ?? 0), 'json' => is_array($r['json'] ?? null) ? $r['json'] : []];
        }
        self::loadConfig();
        $base = self::cfg('META_API_BASE') !== '' ? self::cfg('META_API_BASE') : 'https://graph.facebook.com/v21.0';
        $ch   = curl_init($base . $path);
        $opt  = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . self::cfg('META_ACCESS_TOKEN')],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 25,
        ];
        if ($method === 'POST') {
            $opt[CURLOPT_POST]       = true;
            $opt[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($ch, $opt);
        $body = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($body, true);
        return ['http' => $http, 'json' => is_array($json) ? $json : []];
    }
}
