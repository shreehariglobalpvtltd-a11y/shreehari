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

    /** On, published, and the Meta credentials are there. */
    public static function enabled(): bool
    {
        return Settings::getBool('wa_flow_on', true) && self::flowId() !== '';
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

    /** @return array<string, mixed> the Flow JSON Meta stores */
    public static function flowJson(): array
    {
        $opt = ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'id' => ['type' => 'string'], 'title' => ['type' => 'string'], 'description' => ['type' => 'string'],
        ]]];
        $ex  = static fn(string $id, string $t): array => ['id' => $id, 'title' => $t, 'description' => ''];

        return [
            'version' => '6.0',
            'screens' => [[
                'id'       => self::SCREEN,
                'title'    => 'Book bus ticket',
                'terminal' => true,
                'success'  => true,
                'data'     => [
                    'intro'    => ['type' => 'string', '__example__' => 'Gujarat ⇄ Rupaidiha · AC sleeper'],
                    'pickups'  => $opt + ['__example__' => [$ex('toNepal|Surat', '🇳🇵 Surat 13:00 → Nepal')]],
                    'seat_opts'=> $opt + ['__example__' => [$ex('1', '1'), $ex('2', '2')]],
                    'pay_opts' => $opt + ['__example__' => [$ex('cod', 'Cash on the bus')]],
                    'min_date' => ['type' => 'string', '__example__' => '2026-10-15'],
                    'max_date' => ['type' => 'string', '__example__' => '2026-11-30'],
                    'phone_hint' => ['type' => 'string', '__example__' => 'Blank = this WhatsApp number'],
                ],
                'layout' => [
                    'type'     => 'SingleColumnLayout',
                    'children' => [
                        ['type' => 'TextSubheading', 'text' => '${data.intro}'],
                        ['type' => 'Dropdown', 'name' => 'pickup', 'label' => 'Pickup · चढ्ने ठाउँ', 'required' => true, 'data-source' => '${data.pickups}'],
                        ['type' => 'DatePicker', 'name' => 'date', 'label' => 'Travel date · यात्रा मिति', 'required' => true,
                         'min-date' => '${data.min_date}', 'max-date' => '${data.max_date}'],
                        ['type' => 'Dropdown', 'name' => 'seats', 'label' => 'Seats · सिट', 'required' => true, 'data-source' => '${data.seat_opts}'],
                        ['type' => 'TextInput', 'name' => 'name', 'label' => 'Name · नाम', 'input-type' => 'text', 'required' => true,
                         'helper-text' => 'Lead passenger, as on the ID'],
                        ['type' => 'TextInput', 'name' => 'phone', 'label' => 'Mobile · मोबाइल', 'input-type' => 'phone', 'required' => false,
                         'helper-text' => '${data.phone_hint}'],
                        ['type' => 'TextArea', 'name' => 'others', 'label' => 'Others · अरू नाम', 'required' => false,
                         'helper-text' => '2+ seats: other names, comma separated'],
                        ['type' => 'RadioButtonsGroup', 'name' => 'pay', 'label' => 'Payment · भुक्तानी', 'required' => true, 'data-source' => '${data.pay_opts}'],
                        ['type' => 'Footer', 'label' => 'Book ticket · टिकट बुक', 'on-click-action' => [
                            'name'    => 'complete',
                            'payload' => [
                                'pickup' => '${form.pickup}', 'date' => '${form.date}', 'seats' => '${form.seats}',
                                'name'   => '${form.name}', 'phone' => '${form.phone}', 'others' => '${form.others}',
                                'pay'    => '${form.pay}',
                            ],
                        ]],
                    ],
                ],
            ]],
        ];
    }

    /**
     * The screen's data for one send: today's stops both ways, the date
     * window, the seat counts and the ways to pay.
     *
     * @return array<string, mixed>
     */
    public static function screenData(string $lang, bool $forSeller): array
    {
        require_once __DIR__ . '/smartbook.php';
        require_once __DIR__ . '/quickticket.php';

        $pickups = [];
        foreach (SmartBook::directions() as $d) {
            $dir = (string) $d['direction'];
            if ($dir === 'toNepal') {
                foreach ((array) (SmartBook::points('toNepal', $forSeller ? 'counter' : 'customer')['list'] ?? []) as $p) {
                    $name = (string) $p['name'];
                    $pickups[] = [
                        'id'          => mb_substr('toNepal|' . $name, 0, 80),
                        'title'       => mb_substr('🇳🇵 ' . (string) $p['short'] . ((string) $p['time'] !== '' ? ' ' . $p['time'] : ''), 0, 30),
                        'description' => mb_substr('→ ' . (string) $d['to'] . ((string) ($p['landmark'] ?? '') !== '' ? ' · ' . $p['landmark'] : ''), 0, 300),
                    ];
                }
            } elseif ($dir === 'toIndia') {
                $from = (string) $d['from'];
                $pickups[] = [
                    'id'          => mb_substr('toIndia|' . $from, 0, 80),
                    'title'       => mb_substr('🇮🇳 ' . $from . ((string) $d['depTime'] !== '' ? ' ' . $d['depTime'] : '') . ' → Gujarat', 0, 30),
                    'description' => mb_substr($from . ' → ' . (string) $d['to'], 0, 300),
                ];
            }
        }

        $max = 6;
        try {
            $max = max(1, min(10, BookingService::maxSeatsFor($forSeller)));
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

        // The same sales window the checkout enforces (bookingWindow), capped at 120 days for the calendar.
        $window  = bookingWindow();
        $minDate = max((string) $window['from'], todayISO());
        $maxDate = min((string) $window['to'], addDaysISO(todayISO(), 120));
        if ($maxDate < $minDate) {
            $maxDate = $minDate;
        }
        $intro   = [
            'en' => 'Fill once, tap Book — the ticket comes here.',
            'hi' => 'एक बार भरें, Book दबाएँ — टिकट यहीं आएगा।',
            'ne' => 'एक पटक भर्नुहोस्, Book थिच्नुहोस् — टिकट यहीँ आउँछ।',
            'gu' => 'એક વાર ભરો, Book દબાવો — ટિકિટ અહીં આવશે.',
        ][$lang] ?? 'Fill once, tap Book — the ticket comes here.';

        return [
            'intro'      => $forSeller ? 'Desk order — the ticket goes to the customer\'s mobile.' : $intro,
            'pickups'    => $pickups,
            'seat_opts'  => $seatOpts,
            'pay_opts'   => $pay,
            'min_date'   => $minDate,
            'max_date'   => $maxDate,
            'phone_hint' => $forSeller ? 'Customer mobile (ticket goes there)' : 'Blank = this WhatsApp number',
        ];
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
     * @return array<string, mixed> a $ui for sendWhatsAppInteractive (kind = flow)
     */
    public static function ui(string $who, string $lang, bool $forSeller, string $body = ''): array
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
                'data'   => self::screenData($lang, $forSeller),
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
        $lang = 'ne';

        $token = (string) ($resp['flow_token'] ?? '');
        if (!self::tokenOk($token, $fromDigits)) {
            return ['text' => 'This form is old — write "ticket" for a new one. / यो फारम पुरानो भयो — नयाँका लागि "ticket" लेख्नुहोस्।', 'media' => null, 'ui' => null];
        }
        // One form, one sale: a second submit of the same sheet is answered, not sold twice.
        if (!Security::rateLimit('wa_flow_tok', $token, 1, 7 * 86400)) {
            return ['text' => 'This form was already booked — your ticket is above. / यो फारमबाट टिकट बनिसक्यो — माथि हेर्नुहोस्।', 'media' => null, 'ui' => null];
        }

        [$dir, $stop] = array_pad(explode('|', (string) ($resp['pickup'] ?? ''), 2), 2, '');
        $dir   = in_array($dir, ['toNepal', 'toIndia'], true) ? $dir : '';
        $date  = (string) ($resp['date'] ?? '');
        if (preg_match('/^\d{10,13}$/', $date) === 1) {          // older clients send epoch millis
            $date = date('Y-m-d', (int) floor((int) $date / 1000));
        }
        $seats  = max(1, min(10, (int) ($resp['seats'] ?? 1)));
        $name   = trim((string) ($resp['name'] ?? ''));
        $phone  = preg_replace('/\D/', '', TicketBot::asciiDigits((string) ($resp['phone'] ?? ''))) ?? '';
        $others = trim((string) ($resp['others'] ?? ''));
        $pay    = (string) ($resp['pay'] ?? '') === 'upi' ? 'upi' : '';

        if ($dir === '' || !Security::isValidDate($date)) {
            return ['text' => 'Pickup or date was not clear — write "ticket" to open the form again. / चढ्ने ठाउँ वा मिति बुझिएन — फेरि "ticket" लेख्नुहोस्।', 'media' => null, 'ui' => null];
        }

        $slots = [
            'direction' => $dir,
            'boarding'  => $dir === 'toNepal' ? $stop : '',
            'date'      => $date,
            'seats'     => $seats,
            'name'      => $name,
            'party'     => [],
            '_started'  => time(),
            '_turns'    => 1,
        ];
        // The lead first, then the others typed in the form - every traveller on their own berth.
        if ($seats > 1 && $others !== '') {
            $slots['party'] = array_merge([['name' => $name, 'age' => null]], WaBooking::partyFrom($others, $seats - 1));
        }

        $seller = (is_array($staff) && (int) ($staff['adminId'] ?? 0) > 0 && is_array($staff['admin'] ?? null)) ? $staff['admin'] : null;
        Logger::audit('wa.flow_submit', 'whatsapp', $fromDigits, null,
            ['dir' => $dir, 'stop' => $stop, 'date' => $date, 'seats' => $seats, 'pay' => $pay ?: 'cod', 'seller' => $seller !== null ? (int) $seller['id'] : null],
            'WhatsApp booking form submitted');

        return WaBooking::sellFromForm($fromDigits, $slots, $lang, $senderCountry, $pay, $seller, $phone);
    }

    /* ------------------------------------------------------------------
     *  Publishing (admin/wa-form.php)
     * ---------------------------------------------------------------- */

    /**
     * Create the form on Meta and publish it. A published Flow can never be
     * edited, so every press makes a NEW flow and the new id replaces the
     * old one (the old form keeps working for messages already sent).
     *
     * @return array{ok: bool, id: string, error: string, errors: list<string>}
     */
    public static function publish(): array
    {
        require_once ROOT_PATH . '/whatsapp/config.php';
        $out = ['ok' => false, 'id' => '', 'error' => '', 'errors' => []];
        if (META_ACCESS_TOKEN === '' || META_WABA_ID === '') {
            $out['error'] = 'The Meta token or WABA id is missing (Settings → WhatsApp: whatsapp_api_token, whatsapp_waba_id).';
            return $out;
        }
        // A draft made earlier (Meta refused to publish it then): publish THAT one - no duplicate forms.
        if (self::flowId() !== '' && Settings::getBool('wa_flow_draft', false)) {
            $res = self::graph('POST', '/' . self::flowId() . '/publish', []);
            if ($res['http'] >= 200 && $res['http'] < 300 && !empty($res['json']['success'])) {
                Settings::set('wa_flow_draft', '0', 'bool', 'whatsapp');
                Settings::set('wa_flow_on', '1', 'bool', 'whatsapp');
                return ['ok' => true, 'id' => self::flowId(), 'error' => '', 'errors' => []];
            }
            $out['id']    = self::flowId();
            $out['error'] = self::metaError($res);
            return $out;
        }
        $fields = [
            'name'       => 'SHG booking ' . date('Y-m-d H:i'),
            'categories' => json_encode(['APPOINTMENT_BOOKING']),
            'flow_json'  => json_encode(self::flowJson(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'publish'    => 'true',
        ];
        $res = self::graph('POST', '/' . rawurlencode(META_WABA_ID) . '/flows', $fields);
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
            /* Kept either way. A DRAFT is stored switched OFF for customers - the
               admin page can still send it to a test number (mode = draft) and
               "Publish" retries this same draft once Meta allows it. */
            Settings::set('wa_flow_book_id', $id, 'string', 'whatsapp');
            Settings::set('wa_flow_draft', $published ? '0' : '1', 'bool', 'whatsapp');
            if (!$published) {
                Settings::set('wa_flow_on', '0', 'bool', 'whatsapp');
            }
        }
        if (!$published) {
            $out['error'] = self::metaError($res) !== '' ? self::metaError($res) : 'Meta created the form as a draft but did not publish it.';
            return $out;
        }
        Settings::set('wa_flow_on', '1', 'bool', 'whatsapp');
        $out['ok'] = true;
        return $out;
    }

    private static function metaError(array $res): string
    {
        $e = (array) ($res['json']['error'] ?? []);
        return trim((string) ($e['error_user_msg'] ?? ($e['message'] ?? ($res['http'] >= 300 ? 'HTTP ' . $res['http'] : ''))));
    }

    public const TEMPLATE = 'shg_book_form';

    /**
     * The approved TEMPLATE that carries the form button (owner: "euta
     * template banaidinu"). A plain form message only goes inside the
     * customer's 24 h window; the template reaches anyone, any time (Meta
     * bills it as marketing). Meta reviews it - usually minutes.
     *
     * @return array{ok: bool, error: string}
     */
    public static function createTemplate(): array
    {
        require_once ROOT_PATH . '/whatsapp/config.php';
        if (self::flowId() === '' || META_WABA_ID === '' || META_ACCESS_TOKEN === '') {
            return ['ok' => false, 'error' => 'Publish the form first (and the Meta token / WABA id must be set).'];
        }
        $co = (string) (Settings::company()['name'] ?? 'S Hari Global');
        $components = [
            ['type' => 'BODY', 'text' => '🎫 Book your ' . $co . " bus ticket in one form: pickup, date, seats, name and mobile. Press Book and the ticket comes right here on WhatsApp.\n\nएउटै फारममा टिकट बुक गर्नुहोस् — चढ्ने ठाउँ, मिति, सिट, नाम र मोबाइल। Book थिचेपछि टिकट यहीँ WhatsApp मा आउँछ।"],
            ['type' => 'BUTTONS', 'buttons' => [[
                'type' => 'FLOW', 'text' => 'Book ticket', 'flow_id' => self::flowId(),
                'navigate_screen' => self::SCREEN, 'flow_action' => 'navigate',
            ]]],
        ];
        $res = self::graph('POST', '/' . rawurlencode(META_WABA_ID) . '/message_templates', [
            'name' => self::TEMPLATE, 'language' => 'en', 'category' => 'MARKETING',
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
     *
     * @return array{success: bool, error: string, via: string}
     */
    public static function sendTo(string $phone, string $lang = 'ne', bool $forSeller = false): array
    {
        require_once ROOT_PATH . '/whatsapp/api.php';
        $to = waCleanPhone($phone);
        // A test send works while the form is switched off for customers (a draft included).
        if ($to === '' || self::flowId() === '') {
            return ['success' => false, 'error' => $to === '' ? 'Not a mobile number.' : 'The form is not on Meta yet - press Publish first.', 'via' => ''];
        }
        $ui = self::ui($to, $lang, $forSeller);
        $r  = sendWhatsAppInteractive($to, (string) $ui['body'], $ui);
        if ($r['success']) {
            return ['success' => true, 'error' => '', 'via' => 'form message (free, inside 24 h)'];
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
            return ['success' => true, 'error' => '', 'via' => 'template ' . self::TEMPLATE . ' (paid, outside 24 h)'];
        }
        return ['success' => false, 'error' => 'Form: ' . $r['error'] . ' · Template: ' . $t['error'], 'via' => ''];
    }

    /** Status of the stored flow on Meta (PUBLISHED / DRAFT / DEPRECATED …). */
    public static function status(): array
    {
        require_once ROOT_PATH . '/whatsapp/config.php';
        if (self::flowId() === '' || META_ACCESS_TOKEN === '') {
            return ['status' => '', 'error' => ''];
        }
        $res = self::graph('GET', '/' . self::flowId() . '?fields=id,name,status,validation_errors', []);
        return [
            'status' => (string) ($res['json']['status'] ?? ''),
            'name'   => (string) ($res['json']['name'] ?? ''),
            'error'  => (string) ($res['json']['error']['message'] ?? ''),
        ];
    }

    /** @return array{http: int, json: array<string, mixed>} */
    private static function graph(string $method, string $path, array $fields): array
    {
        $url = META_API_BASE . $path;
        $ch  = curl_init($method === 'GET' ? $url : $url);
        $opt = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . META_ACCESS_TOKEN],
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
