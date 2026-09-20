<?php
/**
 * =====================================================================
 *  AiTools — the buttons the WhatsApp assistant is allowed to press
 *  (20 Sep 2026).
 *
 *  Owner ask, in Nepali: "the 10-second ticket bot should sit on my
 *  WhatsApp number; cut a customer's ticket in one or two messages;
 *  help an agent see their own data; help the office manage everything;
 *  answer every question in Nepali; and learn from MY data, not from
 *  somewhere else."
 *
 *  THE SHAPE OF THE ANSWER
 *  -----------------------
 *  A language model is good at reading "bhai 2 seat chahiyo bholi
 *  Mehsana bata" and bad at arithmetic, at seat rules and at telling the
 *  truth about a fare. So it is never given the register — it is given a
 *  small set of TOOLS, and every tool is a call into the code the desk
 *  already uses:
 *
 *      plan_ticket      -> QuickTicket::plan()          (live availability)
 *      issue_ticket     -> QuickTicket::sellCustomer()  (the app's own path)
 *      staff_sell       -> QuickTicket::sell()          (the counter's path)
 *      cancel_ticket    -> BookingService::cancel()     (refund slabs, audit)
 *      rename_passenger -> Ticket::reissue()            (CORRECTED · REV n)
 *      resend_ticket    -> Notify::resendTicketWhatsApp()
 *      agent_day        -> AgentWallet::summary()
 *
 *  So "the AI sells a ticket" is precisely as true as "the counter clerk
 *  sells a ticket": the same function, the same seat lock, the same
 *  cut-off, the same fare, the same audit row. No code here writes a
 *  seat, prices a fare, lifts a cap or invents a rule — the model only
 *  chooses WHICH button, and this file decides whether that hand is
 *  allowed to reach it.
 *
 *  THE FOUR GATES EVERY CALL PASSES
 *  --------------------------------
 *   1. ROLE      — resolved from the sender's own number against the
 *                  admins table, never from anything the message claims.
 *                  A customer cannot reach a staff tool by asking nicely.
 *   2. OWNERSHIP — a customer only ever touches bookings whose
 *                  contact_phone is the number they are writing from; a
 *                  counter agent only their own sales (the same scoping
 *                  rule admin pages honour). A PNR read off somebody
 *                  else's ticket yields nothing but a status line.
 *   3. SWITCH    — money moves only when the office has switched that
 *                  power on (wa_agent_sell, wa_agent_admin_write). Off
 *                  by default; the assistant then prepares the request
 *                  and says the desk will confirm, exactly as before.
 *   4. STAGING   — a sale or a cancellation must be QUOTED in one
 *                  message and CONFIRMED in the next. The quote is
 *                  pinned (date, pickup, party, total) and the sale is
 *                  refused if the fresh plan no longer matches it, so a
 *                  passenger can never be charged for something other
 *                  than what they read. That is the "1–2 messages": one
 *                  to ask, one to say ho.
 *
 *  Every call — allowed or refused — lands in `ai_agent_calls` with the
 *  number, the role, the tool, the outcome and the milliseconds.
 *  Switch wa_agent_on off and this file is never reached.
 * =====================================================================
 */

declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}

final class AiTools
{
    /** A staged quote (sale / cancel) is only good for this long. */
    private const STAGE_TTL = 900;              // 15 minutes

    /** A passenger may fix the name on one booking this many times. */
    private const RENAME_MAX = 2;

    /* =================================================================
     *  Who is writing to us
     * ================================================================= */

    /**
     * The role of a WhatsApp sender, decided by their NUMBER alone.
     *
     * Staff are matched against the admins table (active rows only), so
     * adding a manager to the assistant is adding their phone to their
     * staff record — there is no second list to forget. Everyone else is
     * a customer, including a number nobody recognises.
     *
     * @return array{role: string, admin: ?array<string,mixed>, adminId: int,
     *               scopeAdminId: ?int, name: string, phone: string}
     */
    public static function whoIs(string $phoneRaw): array
    {
        $digits = normalisePhone($phoneRaw);
        $out = [
            'role'         => 'customer',
            'admin'        => null,
            'adminId'      => 0,
            'scopeAdminId' => null,
            'name'         => '',
            'phone'        => $digits,
        ];
        if ($digits === '') {
            return $out;
        }

        try {
            /* Staff numbers are stored as typed (with or without +91), so the
               match is on the normalised tail the whole app agrees on. */
            $admin = null;
            foreach (Database::fetchAll(
                "SELECT id, full_name, phone, role FROM admins WHERE is_active = 1 AND phone IS NOT NULL AND phone <> ''"
            ) as $row) {
                if (normalisePhone((string) $row['phone']) === $digits) {
                    $admin = $row;
                    break;
                }
            }
            if ($admin !== null) {
                $role = (string) $admin['role'];
                $out['admin']   = $admin;
                $out['adminId'] = (int) $admin['id'];
                $out['name']    = (string) $admin['full_name'];
                // 'agent' is the counter agent: staff powers, but only over
                // their own book — the same obligation Auth::bookingScopeAdminId()
                // places on every admin page.
                $out['role']         = $role === 'agent' ? 'staff' : 'admin';
                $out['scopeAdminId'] = $role === 'agent' ? (int) $admin['id'] : null;
                return $out;
            }

            // A customer we have met before — the vault knows their name, so
            // the assistant can greet them and pre-fill a ticket.
            $name = (string) Database::scalar(
                'SELECT full_name FROM bookings WHERE contact_phone = :p
                   AND status IN (\'confirmed\',\'completed\') ORDER BY id DESC LIMIT 1',
                ['p' => $digits],
                ''
            );
            if ($name === '') {
                $name = (string) Database::scalar(
                    'SELECT p.full_name FROM booking_passengers p
                       JOIN bookings b ON b.id = p.booking_id
                      WHERE b.contact_phone = :p AND p.is_primary = 1
                      ORDER BY p.id DESC LIMIT 1',
                    ['p' => $digits],
                    ''
                );
            }
            $out['name'] = $name;
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }

        return $out;
    }

    /* =================================================================
     *  The catalogue handed to the model
     * ================================================================= */

    /**
     * The tools THIS sender may use, in Anthropic tool-spec shape (the
     * Gemini adapter reshapes the same array). Filtered by role and by
     * the office's switches, so a model can never be tempted by a button
     * that would be refused anyway — and a refusal it cannot see is a
     * refusal it cannot argue with.
     *
     * @param array<string,mixed> $ctx from whoIs()
     * @return array<int, array<string,mixed>>
     */
    public static function catalogue(array $ctx): array
    {
        $role    = (string) ($ctx['role'] ?? 'customer');
        $staff   = $role === 'staff' || $role === 'admin';
        $mayCut  = Settings::getBool('wa_agent_sell', false);
        $mayEdit = Settings::getBool('wa_agent_rewrite', true);
        $mayWrite = Settings::getBool('wa_agent_admin_write', false);

        $t = [];

        $t[] = self::spec('find_ticket',
            'Look up ONE booking by its PNR (SHG-…) and return its live status, bus, date, pickup, seats, passengers and amount. Use this whenever a PNR appears. For a customer it only answers about a booking made from their own number.',
            ['pnr' => ['string', 'The PNR, e.g. SHG-R1-12345-AB']], ['pnr']);

        $t[] = self::spec('my_tickets',
            'The last few bookings of one mobile number, newest first, with status and travel date. Call with no arguments for the number that is writing. Staff may pass any number.',
            ['phone' => ['string', 'Optional 10-digit number — staff only']]);

        $t[] = self::spec('plan_ticket',
            'QUOTE a ticket without selling it: reads live availability and returns the exact bus, date, pickup, berth(s), seats left and total fare. ALWAYS call this before issue_ticket, and read the total back to the passenger so they can say ho/yes.',
            [
                'seats'     => ['integer', 'How many berths (1–6)'],
                'date'      => ['string', "Travel date as YYYY-MM-DD. Leave empty for the next catchable bus."],
                'direction' => ['string', "toNepal = Gujarat→Rupaidiha ('jane'), toIndia = Rupaidiha→Gujarat ('aaune'). Empty = decide from the pickup."],
                'boarding'  => ['string', 'Pickup town or stop as the passenger said it, e.g. Surat, Vadodara, Mehsana, S Hari Parking'],
                'gender'    => ['string', 'Male, Female or Other — needed for a shared cabin berth'],
            ], ['seats']);

        if ($mayCut) {
            $t[] = self::spec('issue_ticket',
                'ISSUE the ticket that plan_ticket just quoted, after the passenger has clearly said yes (ho / hunxa / ok / thik cha / book it). The ticket PNG goes to their WhatsApp by itself. Only call this when the passenger agreed to the total you read out.',
                [
                    'name'    => ['string', "The passenger's full name"],
                    'gender'  => ['string', 'Male, Female or Other'],
                    'confirm' => ['boolean', 'Must be true — it records that the passenger said yes'],
                ], ['name', 'confirm']);
        }

        if ($staff && $mayCut) {
            $t[] = self::spec('staff_sell',
                'Sell a ticket AT THE DESK for a passenger who is not the sender: their name and number, the plan quoted by plan_ticket. The ticket goes to the passenger\'s WhatsApp and the commission is credited to the selling agent. Staff only.',
                [
                    'name'    => ['string', "The passenger's full name"],
                    'phone'   => ['string', "The passenger's 10-digit mobile — the ticket goes there"],
                    'gender'  => ['string', 'Male, Female or Other'],
                    'pay'     => ['string', 'cash, upi, esewa or bank — how the passenger paid'],
                    'confirm' => ['boolean', 'Must be true'],
                ], ['name', 'phone', 'confirm']);
        }

        $t[] = self::spec('refund_quote',
            'What a cancellation would refund right now on one booking, by the published slabs. Always call this before cancel_ticket and tell the passenger the amount.',
            ['pnr' => ['string', 'The PNR']], ['pnr']);

        $t[] = self::spec('cancel_ticket',
            'CANCEL a booking after refund_quote was read out and the passenger confirmed. The seats go back and the refund is registered.',
            [
                'pnr'     => ['string', 'The PNR'],
                'reason'  => ['string', 'Short reason in the passenger\'s own words'],
                'confirm' => ['boolean', 'Must be true'],
            ], ['pnr', 'confirm']);

        $t[] = self::spec('resend_ticket',
            'Send the ticket picture and print link again to the number on the booking. Use when a passenger says they lost it or did not receive it.',
            ['pnr' => ['string', 'The PNR']], ['pnr']);

        $t[] = self::spec('payment_info',
            'The payment QR and link for a booking that is not paid yet, plus how much is due.',
            ['pnr' => ['string', 'The PNR']], ['pnr']);

        if ($mayEdit) {
            $t[] = self::spec('rename_passenger',
                'Fix a WRONG NAME on a ticket (spelling, or the wrong family member) and re-send the corrected ticket. Only before departure, twice per booking. It never changes the date, seat, bus or fare.',
                [
                    'pnr'      => ['string', 'The PNR'],
                    'new_name' => ['string', 'The correct full name'],
                    'old_name' => ['string', 'The name to replace, when the booking has several passengers'],
                ], ['pnr', 'new_name']);
        }

        $t[] = self::spec('bus_eta',
            'Where the bus is right now and roughly how many minutes to each pickup still ahead of it. Answers "bus kaha pugyo?". Silent when the driver\'s phone is not live.',
            ['pnr' => ['string', 'Optional PNR, to answer for that passenger\'s own stop']]);

        if ($staff) {
            $t[] = self::spec('agent_day',
                "One seller's own day: tickets sold, seats, money by method, commission earned and wallet balance. Defaults to the staff member who is writing, and to today.",
                ['date' => ['string', 'YYYY-MM-DD, default today']]);

            $t[] = self::spec('agent_passengers',
                'The passengers travelling on a date, grouped by pickup, with seat and phone — a counter agent sees only the ones they sold themselves.',
                ['date' => ['string', 'YYYY-MM-DD, default today']]);
        }

        if ($role === 'admin') {
            $t[] = self::spec('office_day',
                'The whole company for one day: tickets, seats, revenue by payment method, refunds, how full each departure is, and how many payments are waiting to be verified.',
                ['date' => ['string', 'YYYY-MM-DD, default today']]);

            $t[] = self::spec('office_search',
                'Find bookings by PNR, mobile number or passenger name across the whole register.',
                ['q' => ['string', 'PNR, 10-digit number, or part of a name']], ['q']);

            $t[] = self::spec('office_alerts',
                'What needs the office today: open health incidents, failed WhatsApp deliveries, payments waiting, buses filling unusually fast, and the night audit\'s findings.',
                []);

            if ($mayWrite) {
                $t[] = self::spec('office_confirm',
                    'Verify the payment on a PENDING booking and confirm it — the same button as Admin → Payments. The ticket is issued and sent. Office only, and only after checking the proof.',
                    [
                        'pnr'     => ['string', 'The PNR'],
                        'note'    => ['string', 'Short note for the audit trail, e.g. "UTR checked"'],
                        'confirm' => ['boolean', 'Must be true'],
                    ], ['pnr', 'confirm']);
            }
        }

        return $t;
    }

    /** One tool spec in the Anthropic shape. */
    private static function spec(string $name, string $desc, array $props, array $required = []): array
    {
        $properties = [];
        foreach ($props as $key => [$type, $help]) {
            $properties[$key] = ['type' => $type, 'description' => $help];
        }

        return [
            'name'         => $name,
            'description'  => $desc,
            'input_schema' => [
                'type'       => 'object',
                'properties' => $properties === [] ? (object) [] : $properties,
                'required'   => $required,
            ],
        ];
    }

    /* =================================================================
     *  Run one tool
     * ================================================================= */

    /**
     * Execute a tool the model asked for.
     *
     * NEVER throws: a refusal is a result the model must read out to the
     * passenger ("that ticket is not on this number"), not an exception
     * that swallows the whole reply.
     *
     * @param array<string,mixed> $args  as the model supplied them
     * @param array<string,mixed> $ctx   from whoIs(), plus 'turn'
     * @return array{ok: bool, say: string, data: array<string,mixed>, media: ?string}
     */
    public static function run(string $name, array $args, array $ctx): array
    {
        $t0  = microtime(true);
        $out = ['ok' => false, 'say' => 'That could not be done.', 'data' => [], 'media' => null];

        try {
            // Gate 1 + 3: is this tool on this sender's list at all?
            $allowed = array_column(self::catalogue($ctx), 'name');
            if (!in_array($name, $allowed, true)) {
                $out['say'] = 'This assistant cannot do that. Tell the person to call the office for it.';
                self::log($name, $args, $ctx, false, 'not permitted for role ' . ($ctx['role'] ?? '?'), null, $t0);
                return $out;
            }

            $out = match ($name) {
                'find_ticket'      => self::findTicket($args, $ctx),
                'my_tickets'       => self::myTickets($args, $ctx),
                'plan_ticket'      => self::planTicket($args, $ctx),
                'issue_ticket'     => self::issueTicket($args, $ctx),
                'staff_sell'       => self::staffSell($args, $ctx),
                'refund_quote'     => self::refundQuote($args, $ctx),
                'cancel_ticket'    => self::cancelTicket($args, $ctx),
                'resend_ticket'    => self::resendTicket($args, $ctx),
                'payment_info'     => self::paymentInfo($args, $ctx),
                'rename_passenger' => self::renamePassenger($args, $ctx),
                'bus_eta'          => self::busEta($args, $ctx),
                'agent_day'        => self::agentDay($args, $ctx),
                'agent_passengers' => self::agentPassengers($args, $ctx),
                'office_day'       => self::officeDay($args, $ctx),
                'office_search'    => self::officeSearch($args, $ctx),
                'office_alerts'    => self::officeAlerts($args, $ctx),
                'office_confirm'   => self::officeConfirm($args, $ctx),
                default            => $out,
            };
        } catch (RuntimeException $e) {
            // A desk-safe refusal from the booking engine (bus full, cut-off
            // passed, date out of window) — exactly what the passenger needs
            // to hear, so it is handed to the model as the result.
            $out = ['ok' => false, 'say' => $e->getMessage(), 'data' => [], 'media' => null];
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
            $out = ['ok' => false, 'say' => 'That failed on our side. Give the office number.', 'data' => [], 'media' => null];
        }

        self::log($name, $args, $ctx, (bool) $out['ok'], (string) $out['say'],
            isset($out['data']['bookingId']) ? (int) $out['data']['bookingId'] : null, $t0);

        return $out;
    }

    /* =================================================================
     *  Tools — reading
     * ================================================================= */

    private static function findTicket(array $args, array $ctx): array
    {
        $pnr = strtoupper(trim((string) ($args['pnr'] ?? '')));
        if ($pnr === '' || !Security::isValidPnr($pnr)) {
            return self::no('That is not a valid PNR. Ask for the code that starts with SHG-.');
        }

        $detail = BookingService::detail($pnr);
        if ($detail === null) {
            return self::no('No booking exists with PNR ' . $pnr . '.');
        }

        $own = self::mayRead($detail, $ctx);
        if (!$own) {
            // Gate 2: a stranger's PNR leaks the status and nothing else —
            // the rule wabot.php has kept since day one.
            return [
                'ok'   => true,
                'say'  => 'Booking ' . $pnr . ' exists, status ' . strtoupper((string) $detail['status'])
                        . '. The full detail is only released to the mobile number on the booking, so do NOT reveal names, seats or amounts.',
                'data' => ['pnr' => $pnr, 'status' => (string) $detail['status'], 'restricted' => true],
                'media' => null,
            ];
        }

        $data  = self::bookingCard($detail);
        $media = ((string) $detail['status'] === 'confirmed') ? Ticket::imageUrl($pnr) : null;

        return [
            'ok'    => true,
            'say'   => 'Booking found. Read these facts back — never add to them.',
            'data'  => $data,
            'media' => $media,
        ];
    }

    private static function myTickets(array $args, array $ctx): array
    {
        $phone = normalisePhone((string) ($args['phone'] ?? ''));
        $isStaff = in_array((string) ($ctx['role'] ?? ''), ['staff', 'admin'], true);
        if ($phone === '' || !$isStaff) {
            $phone = (string) $ctx['phone'];          // customers: own number only
        }
        if ($phone === '') {
            return self::no('No mobile number to look up.');
        }

        $rows = Database::fetchAll(
            'SELECT b.pnr, b.status, b.total_amount, l.travel_date, l.boarding_stop,
                    r.from_city, r.to_city
               FROM bookings b
               LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = \'outbound\'
               LEFT JOIN schedules s ON s.id = l.schedule_id
               LEFT JOIN routes r ON r.id = s.route_id
              WHERE b.contact_phone = :p
              ORDER BY b.id DESC LIMIT 5',
            ['p' => $phone]
        );
        if ($rows === []) {
            return ['ok' => true, 'say' => 'This number has no booking with us yet.', 'data' => ['bookings' => []], 'media' => null];
        }

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'pnr'    => (string) $r['pnr'],
                'status' => (string) $r['status'],
                'date'   => (string) ($r['travel_date'] ?? ''),
                'route'  => trim((string) ($r['from_city'] ?? '') . ' → ' . (string) ($r['to_city'] ?? ''), ' →'),
                'pickup' => (string) ($r['boarding_stop'] ?? ''),
                'total'  => (float) $r['total_amount'],
            ];
        }

        return ['ok' => true, 'say' => 'Recent bookings on ' . $phone . '.', 'data' => ['bookings' => $list], 'media' => null];
    }

    private static function planTicket(array $args, array $ctx): array
    {
        $role     = (string) ($ctx['role'] ?? 'customer');
        $customer = $role === 'customer';

        $opts = [
            'seats'     => max(1, (int) ($args['seats'] ?? 1)),
            'date'      => self::cleanDate((string) ($args['date'] ?? '')),
            'direction' => in_array($args['direction'] ?? '', ['toNepal', 'toIndia'], true) ? (string) $args['direction'] : '',
            'boarding'  => Security::clean((string) ($args['boarding'] ?? ''), 80),
            'gender'    => in_array($args['gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $args['gender'] : null,
            'customer'  => $customer,
        ];

        $plan = QuickTicket::plan($opts);           // throws a desk-safe RuntimeException

        // Pin exactly what the passenger is about to be told. issue_ticket
        // refuses if the fresh plan no longer matches this, so the sale can
        // never be for a different day, pickup, party or price.
        self::stage($ctx, 'sale', [
            'expect'   => [
                'date'         => (string) $plan['date'],
                'direction'    => (string) $plan['direction'],
                'boardingCode' => (string) $plan['boardingCode'],
                'seats'        => (int) $plan['seatCount'],
                'total'        => (float) $plan['fare']['total'],
            ],
            'opts'     => ['seats' => (int) $plan['seatCount'], 'date' => (string) $plan['date'],
                           'direction' => (string) $plan['direction'], 'boarding' => (string) $plan['boarding'],
                           'gender' => $opts['gender']],
        ]);

        return [
            'ok'   => true,
            'say'  => 'This is a QUOTE, nothing is booked yet. Read the bus, date, pickup, berth and the TOTAL to the passenger and ask them to reply ho / yes to confirm.',
            'data' => [
                'date'         => (string) $plan['date'],
                'dateLabel'    => (string) $plan['dateLabel'],
                'route'        => $plan['from'] . ' → ' . $plan['to'],
                'direction'    => (string) $plan['direction'],
                'depTime'      => (string) $plan['depTime'],
                'pickup'       => (string) $plan['boardingName'],
                'pickupTime'   => (string) $plan['boardingTime'],
                'seatNumbers'  => $plan['seats'],
                'seatCount'    => (int) $plan['seatCount'],
                'seatsLeft'    => (int) $plan['seatsLeft'],
                'farePerSeat'  => (float) ($plan['fare']['perSeat'] ?? 0),
                'total'        => (float) $plan['fare']['total'],
                'totalLabel'   => inr((float) $plan['fare']['total']),
                'payOnBoard'   => Settings::getBool('allow_cod', true),
            ],
            'media' => null,
        ];
    }

    private static function refundQuote(array $args, array $ctx): array
    {
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if (!self::mayRead($detail, $ctx)) {
            return self::no('That booking is not on this number, so it cannot be cancelled from here.');
        }
        if (in_array((string) $detail['status'], ['cancelled', 'rejected'], true)) {
            return self::no('Booking ' . $pnr . ' is already cancelled.');
        }

        $leg    = $detail['legs'][0] ?? [];
        $paid   = Database::exists("SELECT 1 FROM payments WHERE booking_id = :b AND status = 'verified'", ['b' => (int) $detail['id']]);
        $refund = ($detail['status'] === 'confirmed' && $paid)
            ? Fare::refundFor((float) $detail['total_amount'], (string) ($leg['travel_date'] ?? ''), (string) ($leg['dep_time'] ?? '00:00:00'))
            : ['amount' => 0.0, 'percent' => 0.0, 'reason' => 'No money has been collected on this booking yet.'];

        // Stage the cancel: the passenger must hear the figure before the
        // seats go back.
        self::stage($ctx, 'cancel', ['pnr' => (string) $detail['pnr'], 'amount' => (float) $refund['amount']]);

        return [
            'ok'   => true,
            'say'  => 'Nothing is cancelled yet. Tell the passenger this refund figure and ask them to confirm.',
            'data' => [
                'pnr'       => (string) $detail['pnr'],
                'status'    => (string) $detail['status'],
                'paid'      => $paid,
                'total'     => (float) $detail['total_amount'],
                'refund'    => (float) $refund['amount'],
                'refundLabel' => inr((float) $refund['amount']),
                'percent'   => (float) $refund['percent'],
                'note'      => (string) ($refund['reason'] ?? ''),
                'bookingId' => (int) $detail['id'],
            ],
            'media' => null,
        ];
    }

    private static function paymentInfo(array $args, array $ctx): array
    {
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if (!self::mayRead($detail, $ctx)) {
            return self::no('That booking is not on this number.');
        }

        $due = (float) $detail['total_amount'];
        return [
            'ok'   => true,
            'say'  => (string) $detail['status'] === 'pending'
                ? 'Give the passenger this QR / link to pay. The ticket is issued once the payment is verified.'
                : 'This booking is not waiting for an online payment — say so rather than asking for money.',
            'data' => [
                'pnr'    => (string) $detail['pnr'],
                'status' => (string) $detail['status'],
                'due'    => $due,
                'dueLabel' => inr($due),
                'payLink' => appUrl('pay.php?pnr=' . urlencode((string) $detail['pnr'])),
                'upiId'  => Settings::getString('upi_id', ''),
                'esewaId' => Settings::getString('esewa_id', ''),
                'bookingId' => (int) $detail['id'],
            ],
            'media' => (string) $detail['status'] === 'pending' && Settings::getString('upi_id', '') !== '' && $due > 0
                ? appUrl('pay-image.php?pnr=' . urlencode((string) $detail['pnr']))
                : null,
        ];
    }

    private static function busEta(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/etaalerts.php';

        $routeId = 0;
        $myStop  = '';
        $pnr     = strtoupper(trim((string) ($args['pnr'] ?? '')));
        if ($pnr !== '') {
            $detail = BookingService::detail($pnr);
            if ($detail !== null && self::mayRead($detail, $ctx)) {
                $leg     = $detail['legs'][0] ?? [];
                $myStop  = (string) ($leg['boarding_stop'] ?? '');
                $routeId = (int) Database::scalar(
                    'SELECT s.route_id FROM booking_legs l JOIN schedules s ON s.id = l.schedule_id WHERE l.booking_id = :b LIMIT 1',
                    ['b' => (int) $detail['id']],
                    0
                );
            }
        }
        if ($routeId === 0) {
            $routeId = (int) Database::scalar('SELECT id FROM routes WHERE is_active = 1 ORDER BY sort_order, dep_time LIMIT 1', [], 0);
        }
        if ($routeId === 0) {
            return self::no('No active route to track.');
        }

        $stops = [];
        foreach (EtaAlerts::stopEtas($routeId) as $key => $s) {
            $stops[] = ['stop' => (string) $s['name'], 'minutes' => (int) (ceil(((int) $s['eta']) / 5) * 5), 'km' => $s['km']];
        }
        if ($stops === []) {
            return [
                'ok'   => true,
                'say'  => 'The bus position is not live right now (the driver\'s phone is off or the fix is stale). Say that honestly and give the scheduled pickup time instead — never guess a position.',
                'data' => ['live' => false, 'myStop' => $myStop],
                'media' => null,
            ];
        }

        return ['ok' => true, 'say' => 'Live bus position. Minutes are approximate.',
                'data' => ['live' => true, 'myStop' => $myStop, 'stops' => $stops], 'media' => null];
    }

    /* =================================================================
     *  Tools — selling
     * ================================================================= */

    private static function issueTicket(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_sell', false)) {
            return self::no('Selling on WhatsApp is switched off. Say the desk will confirm the booking and give the office number.');
        }
        if (($args['confirm'] ?? false) !== true) {
            return self::no('The passenger has not confirmed yet. Read the total back and wait for ho / yes.');
        }

        $staged = self::takeStage($ctx, 'sale');
        if ($staged === null) {
            return self::no('No quote is open. Call plan_ticket first and read the fare to the passenger.');
        }

        $name = Security::clean((string) ($args['name'] ?? ($ctx['name'] ?? '')), 120);
        if (mb_strlen($name) < 2) {
            return self::no("Ask the passenger's full name first.");
        }

        $input = [
            'name'      => $name,
            'phone'     => (string) $ctx['phone'],     // always the sender's own number
            'seats'     => (int) ($staged['opts']['seats'] ?? 1),
            'date'      => (string) ($staged['opts']['date'] ?? ''),
            'direction' => (string) ($staged['opts']['direction'] ?? ''),
            'boarding'  => (string) ($staged['opts']['boarding'] ?? ''),
            'gender'    => in_array($args['gender'] ?? '', ['Male', 'Female', 'Other'], true)
                ? (string) $args['gender']
                : ($staged['opts']['gender'] ?? null),
            'expect'    => $staged['expect'] ?? null,
        ];

        $res = QuickTicket::sellCustomer($input, null);

        return self::soldResult($res, 'The ticket is issued and the picture is on its way to this WhatsApp.');
    }

    private static function staffSell(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_sell', false)) {
            return self::no('Selling on WhatsApp is switched off for this company.');
        }
        if (($args['confirm'] ?? false) !== true) {
            return self::no('Not confirmed — read the plan back to the seller first.');
        }
        $admin = $ctx['admin'] ?? null;
        if (!is_array($admin) || (int) ($admin['id'] ?? 0) <= 0) {
            return self::no('Only signed-in staff numbers may sell for another passenger.');
        }

        $staged = self::takeStage($ctx, 'sale');
        if ($staged === null) {
            return self::no('No quote is open. Call plan_ticket first.');
        }

        $phone = normalisePhone((string) ($args['phone'] ?? ''));
        if ($phone === '' || !Security::isValidPhone($phone, true)) {
            return self::no("The passenger's mobile number is missing or not valid.");
        }

        $input = [
            'name'      => Security::clean((string) ($args['name'] ?? ''), 120),
            'phone'     => $phone,
            'seats'     => (int) ($staged['opts']['seats'] ?? 1),
            'date'      => (string) ($staged['opts']['date'] ?? ''),
            'direction' => (string) ($staged['opts']['direction'] ?? ''),
            'boarding'  => (string) ($staged['opts']['boarding'] ?? ''),
            'gender'    => in_array($args['gender'] ?? '', ['Male', 'Female', 'Other'], true)
                ? (string) $args['gender']
                : ($staged['opts']['gender'] ?? null),
            'pay'       => in_array($args['pay'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true) ? (string) $args['pay'] : 'cash',
            'note'      => 'WhatsApp desk sale',
        ];

        $res = QuickTicket::sell($input, $admin);

        return self::soldResult($res, 'Sold. The ticket has gone to the passenger\'s WhatsApp; the commission is on the seller\'s wallet.');
    }

    /** Shared shaping of a QuickTicket result. */
    private static function soldResult(array $res, string $say): array
    {
        $pnr = (string) ($res['pnr'] ?? '');

        return [
            'ok'   => true,
            'say'  => $say . ' Read back ONLY these facts.',
            'data' => [
                'pnr'        => $pnr,
                'status'     => (string) ($res['status'] ?? ''),
                'seats'      => $res['seats'] ?? [],
                'total'      => (float) ($res['total'] ?? 0),
                'totalLabel' => (string) ($res['totalLabel'] ?? ''),
                'date'       => (string) ($res['dateLabel'] ?? ''),
                'depTime'    => (string) ($res['depTime'] ?? ''),
                'pickup'     => (string) ($res['boardingName'] ?? ''),
                'pickupTime' => (string) ($res['boardingTime'] ?? ''),
                'route'      => (string) ($res['route'] ?? ''),
                'payOnBoard' => (bool) ($res['isCod'] ?? false),
                'bookingId'  => (int) ($res['bookingId'] ?? 0),
                'ms'         => (int) ($res['elapsedMs'] ?? 0),
            ],
            'media' => (string) ($res['status'] ?? '') === 'confirmed' && $pnr !== '' ? Ticket::imageUrl($pnr) : null,
        ];
    }

    /* =================================================================
     *  Tools — changing a ticket
     * ================================================================= */

    private static function cancelTicket(array $args, array $ctx): array
    {
        if (($args['confirm'] ?? false) !== true) {
            return self::no('Not confirmed by the passenger yet.');
        }
        $pnr = strtoupper(trim((string) ($args['pnr'] ?? '')));

        $staged = self::takeStage($ctx, 'cancel');
        if ($staged === null || strtoupper((string) ($staged['pnr'] ?? '')) !== $pnr) {
            return self::no('Call refund_quote for this exact PNR first and tell the passenger the refund amount.');
        }

        $detail = BookingService::detail($pnr);
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if (!self::mayWrite($detail, $ctx)) {
            return self::no('This booking belongs to another number, so it cannot be cancelled from here.');
        }

        $reason = Security::clean((string) ($args['reason'] ?? 'Cancelled on WhatsApp'), 200);
        $byCustomer = (string) ($ctx['role'] ?? 'customer') === 'customer';
        $res = BookingService::cancel($pnr, $reason, $byCustomer, 'whatsapp');

        return [
            'ok'   => true,
            'say'  => 'Cancelled. The seats are released.',
            'data' => [
                'pnr'    => $pnr,
                'refund' => (float) ($res['refund']['amount'] ?? 0),
                'refundLabel' => inr((float) ($res['refund']['amount'] ?? 0)),
                'note'   => (string) ($res['refund']['reason'] ?? ''),
                'bookingId' => (int) $detail['id'],
            ],
            'media' => null,
        ];
    }

    /**
     * The owner's "aayeko ticket rewrite ni garna milos": a wrong name is
     * the one thing a passenger genuinely cannot fix themselves, and at
     * the border a wrong name is not a small thing. So the name may be
     * corrected — and ONLY the name. The date, seat, bus and fare are
     * untouched, the ticket is re-minted (it prints CORRECTED · REV n) and
     * the fresh picture goes back to the passenger.
     */
    private static function renamePassenger(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_rewrite', true)) {
            return self::no('Name correction on WhatsApp is switched off — the desk does it.');
        }
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if (!self::mayWrite($detail, $ctx)) {
            return self::no('That booking is not on this number.');
        }
        if (!in_array((string) $detail['status'], ['pending', 'confirmed'], true)) {
            return self::no('Only a live booking can be corrected — this one is ' . strtoupper((string) $detail['status']) . '.');
        }

        // Before departure only: after the bus has gone the manifest is history.
        $leg  = $detail['legs'][0] ?? [];
        $when = trim((string) ($leg['travel_date'] ?? '') . ' ' . substr((string) ($leg['dep_time'] ?? '00:00:00'), 0, 8));
        if ($when !== '' && strtotime($when) !== false && strtotime($when) < time()) {
            return self::no('That bus has already departed, so the ticket cannot be changed. Give the office number.');
        }

        $new = Security::clean((string) ($args['new_name'] ?? ''), 120);
        if (mb_strlen($new) < 2 || preg_match('/\d/', $new) === 1) {
            return self::no('That does not look like a name. Ask the passenger to send the correct full name.');
        }

        $pax = $detail['passengers'] ?? [];
        if ($pax === []) {
            return self::no('This booking has no passenger row to correct.');
        }
        $target = $pax[0];
        $old    = Security::clean((string) ($args['old_name'] ?? ''), 120);
        if ($old !== '' && count($pax) > 1) {
            foreach ($pax as $p) {
                if (mb_strtolower(trim((string) $p['full_name'])) === mb_strtolower($old)) {
                    $target = $p;
                    break;
                }
            }
        } elseif (count($pax) > 1 && $old === '') {
            return self::no('This booking has ' . count($pax) . ' passengers — ask WHICH name is wrong before correcting.');
        }

        $was = (string) $target['full_name'];
        if (mb_strtolower(trim($was)) === mb_strtolower($new)) {
            return self::no('The name on the ticket is already ' . $was . '.');
        }

        // Twice per booking, so a ticket cannot be quietly passed from person
        // to person by renaming it all week.
        $bid  = (int) $detail['id'];
        $used = (int) Database::scalar(
            "SELECT COUNT(*) FROM ai_agent_calls WHERE tool = 'rename_passenger' AND ok = 1 AND booking_id = :b",
            ['b' => $bid],
            0
        );
        if ($used >= self::RENAME_MAX) {
            return self::no('This ticket has already been corrected ' . self::RENAME_MAX . ' times. Further changes are done at the office.');
        }

        Database::run(
            'UPDATE booking_passengers SET full_name = :n WHERE id = :id AND booking_id = :b',
            ['n' => $new, 'id' => (int) $target['id'], 'b' => $bid]
        );
        Ticket::reissue($bid);                       // new QR, cached PNG/PDF dropped, REV n
        Logger::audit('booking.rename_whatsapp', 'booking', $pnr,
            ['full_name' => $was], ['full_name' => $new],
            'Passenger name corrected from WhatsApp by ' . ($ctx['phone'] ?? '') . ' (' . ($ctx['role'] ?? '') . ')');

        $fresh  = BookingService::detail($pnr) ?? $detail;
        $sent   = Notify::ticketChanged($fresh, 'passenger', ['name' => $new]);

        return [
            'ok'   => true,
            'say'  => 'The name is corrected and the new ticket has been sent'
                    . (($sent['ok'] ?? false) ? '.' : ' — but the automatic send failed, so give the ticket link.'),
            'data' => [
                'pnr'       => $pnr,
                'was'       => $was,
                'now'       => $new,
                'seat'      => (string) ($target['seat_no'] ?? ''),
                'resent'    => (bool) ($sent['ok'] ?? false),
                'bookingId' => $bid,
            ],
            'media' => (string) $fresh['status'] === 'confirmed' ? Ticket::imageUrl($pnr) : null,
        ];
    }

    private static function resendTicket(array $args, array $ctx): array
    {
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if (!self::mayRead($detail, $ctx)) {
            return self::no('That booking is not on this number.');
        }
        if ((string) $detail['status'] !== 'confirmed') {
            return self::no('There is no ticket yet — the booking is ' . strtoupper((string) $detail['status']) . '.');
        }

        $res = Notify::resendTicketWhatsApp($detail);

        return [
            'ok'   => true,
            'say'  => ($res['ok'] ?? false)
                ? 'The ticket has been sent again to the number on the booking.'
                : 'The automatic send did not go through — give the passenger the ticket link instead.',
            'data' => ['pnr' => $pnr, 'sent' => (bool) ($res['ok'] ?? false), 'bookingId' => (int) $detail['id']],
            'media' => Ticket::imageUrl($pnr),
        ];
    }

    /* =================================================================
     *  Tools — an agent's own book
     * ================================================================= */

    private static function agentDay(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';

        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($adminId <= 0) {
            return self::no('Only a staff number can ask this.');
        }
        $date = self::cleanDate((string) ($args['date'] ?? '')) ?: todayISO();

        $row = Database::fetch(
            "SELECT COUNT(*) AS tickets,
                    COALESCE(SUM(b.total_amount),0) AS amount,
                    COALESCE(SUM(CASE WHEN p.method = 'cash' THEN b.total_amount ELSE 0 END),0) AS cash
               FROM bookings b
               LEFT JOIN payments p ON p.booking_id = b.id
              WHERE b.sold_by_admin_id = :a
                AND DATE(b.created_at) = :d
                AND b.status IN ('confirmed','completed')",
            ['a' => $adminId, 'd' => $date]
        ) ?? [];

        $seats = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_seats s JOIN bookings b ON b.id = s.booking_id
              WHERE b.sold_by_admin_id = :a AND DATE(b.created_at) = :d
                AND b.status IN ('confirmed','completed') AND s.released_at IS NULL",
            ['a' => $adminId, 'd' => $date],
            0
        );

        /* Two separate balances, never added together: commission is what the
           company owes the agent, cash is what the agent owes the company. */
        $bal = ['commission' => 0.0, 'cash' => 0.0];
        $earned = 0.0;
        try {
            $bal    = AgentWallet::balances($adminId) + $bal;
            $earned = (float) (AgentWallet::summary($adminId)['earnedMonth'] ?? 0);
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }

        return [
            'ok'   => true,
            'say'  => 'This seller\'s own day. Never quote another seller\'s numbers. '
                    . 'commissionDue is what the company owes them, cashDue is what they still have to hand over — keep the two apart.',
            'data' => [
                'date'          => $date,
                'seller'        => (string) ($ctx['name'] ?? ''),
                'tickets'       => (int) ($row['tickets'] ?? 0),
                'seats'         => $seats,
                'amount'        => (float) ($row['amount'] ?? 0),
                'amountLabel'   => inr((float) ($row['amount'] ?? 0)),
                'cashTakenToday' => (float) ($row['cash'] ?? 0),
                'commissionDue' => (float) $bal['commission'],
                'commissionDueLabel' => inr((float) $bal['commission']),
                'commissionThisMonth' => $earned,
                'cashDue'       => (float) $bal['cash'],
                'cashDueLabel'  => inr((float) $bal['cash']),
            ],
            'media' => null,
        ];
    }

    private static function agentPassengers(array $args, array $ctx): array
    {
        $date  = self::cleanDate((string) ($args['date'] ?? '')) ?: todayISO();
        $scope = $ctx['scopeAdminId'] ?? null;      // a counter agent sees only their own

        $sql = "SELECT b.pnr, b.contact_phone, l.boarding_stop, p.full_name, p.seat_no
                  FROM bookings b
                  JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                  JOIN booking_passengers p ON p.booking_id = b.id
                 WHERE l.travel_date = :d AND b.status IN ('confirmed','completed')";
        $params = ['d' => $date];
        if ($scope !== null) {
            $sql .= ' AND b.sold_by_admin_id = :a';
            $params['a'] = (int) $scope;
        }
        $sql .= ' ORDER BY l.boarding_stop, p.seat_no LIMIT 60';

        $rows = Database::fetchAll($sql, $params);
        $byStop = [];
        foreach ($rows as $r) {
            $stop = (string) ($r['boarding_stop'] ?? '—');
            $byStop[$stop][] = [
                'name'  => (string) $r['full_name'],
                'seat'  => (string) $r['seat_no'],
                'phone' => (string) $r['contact_phone'],
                'pnr'   => (string) $r['pnr'],
            ];
        }

        return [
            'ok'   => true,
            'say'  => $rows === []
                ? 'Nobody is travelling on that date in this seller\'s book.'
                : 'Passengers by pickup. Give names and seats; give a phone number only if asked.',
            'data' => ['date' => $date, 'count' => count($rows), 'byPickup' => $byStop],
            'media' => null,
        ];
    }

    /* =================================================================
     *  Tools — the office
     * ================================================================= */

    private static function officeDay(array $args, array $ctx): array
    {
        $date = self::cleanDate((string) ($args['date'] ?? '')) ?: todayISO();

        $sold = Database::fetch(
            "SELECT COUNT(*) AS tickets, COALESCE(SUM(total_amount),0) AS amount
               FROM bookings WHERE DATE(created_at) = :d AND status IN ('confirmed','completed')",
            ['d' => $date]
        ) ?? [];

        $byMethod = Database::fetchAll(
            "SELECT COALESCE(p.method,'unknown') AS method, COALESCE(SUM(b.total_amount),0) AS amount, COUNT(*) AS n
               FROM bookings b LEFT JOIN payments p ON p.booking_id = b.id
              WHERE DATE(b.created_at) = :d AND b.status IN ('confirmed','completed')
              GROUP BY COALESCE(p.method,'unknown')",
            ['d' => $date]
        );

        $pending = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings WHERE status = 'pending'", [], 0
        );
        $refunds = (float) Database::scalar(
            "SELECT COALESCE(SUM(refund_amount),0) FROM bookings WHERE DATE(cancelled_at) = :d",
            ['d' => $date],
            0
        );

        // How full each departure on that travel date is — the number the
        // office actually rings about.
        $departures = Database::fetchAll(
            "SELECT r.from_city, r.to_city, COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    COUNT(bs.id) AS sold
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN booking_seats bs ON bs.schedule_id = s.id AND bs.released_at IS NULL
              WHERE s.travel_date = :d
              GROUP BY s.id, r.from_city, r.to_city, dep_time
              ORDER BY dep_time",
            ['d' => $date]
        );

        $buses = [];
        foreach ($departures as $d) {
            $buses[] = [
                'route'   => (string) $d['from_city'] . ' → ' . (string) $d['to_city'],
                'depTime' => substr((string) $d['dep_time'], 0, 5),
                'sold'    => (int) $d['sold'],
            ];
        }

        $methods = [];
        foreach ($byMethod as $m) {
            $methods[(string) $m['method']] = ['amount' => (float) $m['amount'], 'tickets' => (int) $m['n']];
        }

        return [
            'ok'   => true,
            'say'  => 'The company\'s own day. These are live figures from the register.',
            'data' => [
                'date'          => $date,
                'ticketsSold'   => (int) ($sold['tickets'] ?? 0),
                'revenue'       => (float) ($sold['amount'] ?? 0),
                'revenueLabel'  => inr((float) ($sold['amount'] ?? 0)),
                'byMethod'      => $methods,
                'pendingPayments' => $pending,
                'refundsToday'  => $refunds,
                'departures'    => $buses,
            ],
            'media' => null,
        ];
    }

    private static function officeSearch(array $args, array $ctx): array
    {
        $q = Security::clean((string) ($args['q'] ?? ''), 60);
        if (mb_strlen($q) < 3) {
            return self::no('Give at least three characters to search for.');
        }

        /* One placeholder per occurrence: PDO runs with emulation off, where a
           named parameter may not be bound twice (HY093). */
        $digits = normalisePhone($q);
        $rows = Database::fetchAll(
            "SELECT b.pnr, b.status, b.contact_phone, b.total_amount, l.travel_date,
                    (SELECT p.full_name FROM booking_passengers p WHERE p.booking_id = b.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS pax
               FROM bookings b
               LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
              WHERE b.pnr LIKE :likePnr
                 OR (:digitsSet <> '' AND b.contact_phone = :digitsEq)
                 OR EXISTS (SELECT 1 FROM booking_passengers p2 WHERE p2.booking_id = b.id AND p2.full_name LIKE :likeName)
              ORDER BY b.id DESC LIMIT 10",
            ['likePnr' => '%' . $q . '%', 'likeName' => '%' . $q . '%', 'digitsSet' => $digits, 'digitsEq' => $digits]
        );

        $list = [];
        foreach ($rows as $r) {
            $list[] = [
                'pnr'    => (string) $r['pnr'],
                'name'   => (string) ($r['pax'] ?? ''),
                'phone'  => (string) $r['contact_phone'],
                'status' => (string) $r['status'],
                'date'   => (string) ($r['travel_date'] ?? ''),
                'total'  => (float) $r['total_amount'],
            ];
        }

        return [
            'ok'   => true,
            'say'  => $list === [] ? 'Nothing in the register matches that.' : 'Matching bookings.',
            'data' => ['query' => $q, 'results' => $list],
            'media' => null,
        ];
    }

    private static function officeAlerts(array $args, array $ctx): array
    {
        $out = [];

        try {
            $out['healthIncidents'] = Database::fetchAll(
                "SELECT severity, title, last_seen_at FROM health_incidents
                  WHERE status = 'open'
                  ORDER BY FIELD(severity, 'critical', 'warn', 'info'), last_seen_at DESC LIMIT 8"
            );
        } catch (Throwable $e) {
            $out['healthIncidents'] = [];          // table not migrated yet
        }

        $out['pendingPayments'] = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE status = 'pending'", [], 0);

        try {
            $out['failedMessages24h'] = (int) Database::scalar(
                "SELECT COUNT(*) FROM message_logs WHERE status = 'failed' AND created_at >= (NOW() - INTERVAL 1 DAY)",
                [],
                0
            );
        } catch (Throwable $e) {
            $out['failedMessages24h'] = 0;
        }

        try {
            require_once INCLUDE_PATH . '/ticketbrain.php';
            $brain = [];
            foreach (TicketBrain::openAlerts('', 6) as $a) {
                $brain[] = [
                    'kind'     => (string) ($a['kind'] ?? ''),
                    'severity' => (string) ($a['severity'] ?? 'info'),
                    'title'    => (string) ($a['title'] ?? ''),
                    'detail'   => mb_substr((string) ($a['body'] ?? ''), 0, 160),
                ];
            }
            $out['brainAlerts'] = $brain;
        } catch (Throwable $e) {
            $out['brainAlerts'] = [];
        }

        $out['departuresTomorrow'] = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_seats bs JOIN booking_legs l ON l.booking_id = bs.booking_id
              WHERE l.travel_date = :d AND bs.released_at IS NULL",
            ['d' => date('Y-m-d', strtotime('+1 day'))],
            0
        );

        return ['ok' => true, 'say' => 'What is open right now. Say the important ones in one or two lines.', 'data' => $out, 'media' => null];
    }

    private static function officeConfirm(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_admin_write', false)) {
            return self::no('Confirming a payment from WhatsApp is switched off — do it in Admin → Payments.');
        }
        if (($args['confirm'] ?? false) !== true) {
            return self::no('Not confirmed.');
        }
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return self::no('Only an office number may confirm a payment.');
        }

        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if ((string) $detail['status'] !== 'pending') {
            return self::no('Booking ' . $pnr . ' is ' . strtoupper((string) $detail['status']) . ', so there is nothing to confirm.');
        }

        $note = Security::clean((string) ($args['note'] ?? 'Confirmed on WhatsApp'), 200);
        BookingService::confirm((int) $detail['id'], (int) ($ctx['adminId'] ?? 0), $note);

        return [
            'ok'   => true,
            'say'  => 'Confirmed. The ticket has been issued and sent to the passenger.',
            'data' => ['pnr' => $pnr, 'bookingId' => (int) $detail['id']],
            'media' => null,
        ];
    }

    /* =================================================================
     *  Gates and plumbing
     * ================================================================= */

    /** May this sender READ the full detail of this booking? */
    private static function mayRead(array $detail, array $ctx): bool
    {
        $role = (string) ($ctx['role'] ?? 'customer');
        if ($role === 'admin') {
            return true;
        }
        if ($role === 'staff') {
            $scope = $ctx['scopeAdminId'] ?? null;
            return $scope === null || (int) ($detail['sold_by_admin_id'] ?? 0) === (int) $scope;
        }

        return (string) $ctx['phone'] !== ''
            && normalisePhone((string) ($detail['contact_phone'] ?? '')) === (string) $ctx['phone'];
    }

    /** May this sender CHANGE this booking? Same rule — stated separately on purpose. */
    private static function mayWrite(array $detail, array $ctx): bool
    {
        return self::mayRead($detail, $ctx);
    }

    /** A refusal the model must read out, not an error. */
    private static function no(string $why): array
    {
        return ['ok' => false, 'say' => $why, 'data' => [], 'media' => null];
    }

    private static function cleanDate(string $raw): string
    {
        $raw = trim($raw);
        return ($raw !== '' && Security::isValidDate($raw)) ? $raw : '';
    }

    /**
     * Park a quote for the NEXT message.
     *
     * The turn number is what makes "1–2 messages" honest: issue_ticket
     * refuses a quote raised in the same turn unless the office switched
     * wa_agent_oneshot on, so a passenger always reads a fare before a
     * seat is taken in their name.
     */
    private static function stage(array $ctx, string $kind, array $payload): void
    {
        $row = json_encode([
            'kind'    => $kind,
            'turn'    => (int) ($ctx['turn'] ?? 0),
            'at'      => time(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $done = Database::update('kv_store', ['kvalue' => $row, 'updated_by' => 'aiagent'],
                'kscope = :s AND kkey = :k', ['s' => 'wa_stage', 'k' => (string) $ctx['phone']]);
            if ($done === 0) {
                Database::insertIgnore('kv_store', [
                    'kscope' => 'wa_stage', 'kkey' => (string) $ctx['phone'],
                    'kvalue' => $row, 'updated_by' => 'aiagent',
                ]);
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
    }

    /** Take the staged quote of this kind, or null when there is none to use. */
    private static function takeStage(array $ctx, string $kind): ?array
    {
        try {
            $row = Database::fetch(
                'SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => 'wa_stage', 'k' => (string) $ctx['phone']]
            );
        } catch (Throwable $e) {
            return null;
        }
        if ($row === null) {
            return null;
        }

        $saved = json_decode((string) $row['kvalue'], true);
        if (!is_array($saved) || ($saved['kind'] ?? '') !== $kind) {
            return null;
        }
        if ((time() - (int) ($saved['at'] ?? 0)) > self::STAGE_TTL) {
            return null;                                     // the fare is stale
        }
        if ((int) ($saved['turn'] ?? 0) >= (int) ($ctx['turn'] ?? 0)
            && !Settings::getBool('wa_agent_oneshot', false)) {
            return null;                                     // quoted and sold in one breath
        }

        return is_array($saved['payload'] ?? null) ? $saved['payload'] : null;
    }

    /** Drop any staged quote — used when a conversation is reset. */
    public static function clearStage(string $phoneDigits): void
    {
        if ($phoneDigits === '') {
            return;
        }
        try {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k', ['s' => 'wa_stage', 'k' => $phoneDigits]);
        } catch (Throwable $e) {
            // a stale quote expires on its own
        }
    }

    /** The audit row. Never throws — a missing table must not cost a reply. */
    private static function log(string $tool, array $args, array $ctx, bool $ok, string $detail, ?int $bookingId, float $t0): void
    {
        try {
            // Structured arguments only: the passenger's own sentence stays in
            // message_logs, it is not copied into a second table.
            $safe = [];
            foreach ($args as $k => $v) {
                if (is_scalar($v)) {
                    $safe[(string) $k] = is_string($v) ? mb_substr($v, 0, 60) : $v;
                }
            }
            Database::insert('ai_agent_calls', [
                'created_at' => date('Y-m-d H:i:s'),
                'phone'      => (string) ($ctx['phone'] ?? ''),
                'role'       => in_array($ctx['role'] ?? '', ['customer', 'staff', 'admin'], true) ? (string) $ctx['role'] : 'customer',
                'channel'    => (string) ($ctx['channel'] ?? 'whatsapp'),
                'tool'       => mb_substr($tool, 0, 40),
                'args'       => mb_substr((string) json_encode($safe, JSON_UNESCAPED_UNICODE), 0, 500),
                'ok'         => $ok ? 1 : 0,
                'detail'     => mb_substr($detail, 0, 255),
                'booking_id' => $bookingId !== null && $bookingId > 0 ? $bookingId : null,
                'ms'         => (int) round((microtime(true) - $t0) * 1000),
            ]);
        } catch (Throwable $e) {
            Logger::warning('ai_agent_calls write failed: ' . $e->getMessage(), ['tool' => $tool], 'whatsapp');
        }
    }

    /** The facts of one booking, in the order a human says them. */
    private static function bookingCard(array $detail): array
    {
        $leg   = $detail['legs'][0] ?? [];
        $names = [];
        foreach ($detail['passengers'] ?? [] as $p) {
            $names[] = ['name' => (string) $p['full_name'], 'seat' => (string) $p['seat_no']];
        }

        return [
            'pnr'        => (string) $detail['pnr'],
            'status'     => (string) $detail['status'],
            'route'      => trim((string) ($leg['from_city'] ?? '') . ' → ' . (string) ($leg['to_city'] ?? ''), ' →'),
            'date'       => (string) ($leg['travel_date'] ?? ''),
            'dateLabel'  => formatDate((string) ($leg['travel_date'] ?? ''), 'D, j M Y'),
            'depTime'    => substr((string) ($leg['dep_time'] ?? ''), 0, 5),
            'pickup'     => (string) ($leg['boarding_stop'] ?? ''),
            'pickupTime' => substr((string) ($leg['boarding_time'] ?? ''), 0, 5),
            'seats'      => $detail['seats'] ?? [],
            'passengers' => $names,
            'total'      => (float) $detail['total_amount'],
            'totalLabel' => inr((float) $detail['total_amount']),
            'paid'       => (string) ($detail['payment']['status'] ?? '') === 'verified',
            'bookingId'  => (int) $detail['id'],
        ];
    }
}
