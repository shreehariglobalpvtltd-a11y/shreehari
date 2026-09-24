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

require_once __DIR__ . '/personname.php';

final class AiTools
{
    /** A staged quote (sale / cancel) is only good for this long. */
    private const STAGE_TTL = 900;              // 15 minutes

    /** A passenger may fix the name on one booking this many times. */
    private const RENAME_MAX = 2;

    /** Date / seat / pickup corrections allowed per booking from WhatsApp. */
    private const FIX_MAX = 3;

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
            /* 24 Sep 2026 — the sender's country, read off the +91 / +977 the
               webhook delivers. India and Nepal share 10-digit mobiles, so
               once the number is normalised nothing else can tell them apart,
               and a Nepali passenger's ticket used to be addressed to +91.
               Carried into every sale made on this number. */
            'country'      => resolvePhoneCountry('', $phoneRaw),
            'login'        => null,
        ];
        if ($digits === '') {
            return $out;
        }

        try {
            $admin = null;

            /* 24 Sep 2026 — a WhatsApp sign-in (includes/walogin.php) names
               the account for this number explicitly, so it wins over the
               staff-record match below. session() has already re-checked
               that the account is active, unlocked and initialised. */
            if (Settings::getBool('wa_login_on', false)) {
                require_once INCLUDE_PATH . '/walogin.php';
                $session = WaLogin::session($digits);
                if ($session !== null) {
                    $admin        = $session['admin'];
                    $out['login'] = [
                        'id'         => (int) $session['login']['id'],
                        'expires_at' => (string) $session['login']['expires_at'],
                        'method'     => (string) $session['login']['method'],
                    ];
                }
            }

            /* Staff numbers are stored as typed (with or without +91), so the
               match is on the normalised tail the whole app agrees on. */
            if ($admin === null) {
                $matches = [];
                foreach (Database::fetchAll(
                    "SELECT id, full_name, phone, role, is_active, must_change_pw, locked_until FROM admins WHERE phone IS NOT NULL AND phone <> ''"
                ) as $row) {
                    if (normalisePhone((string) $row['phone']) === $digits) {
                        $matches[] = $row;
                    }
                }
                // Shared, suspended or uninitialised staff accounts cannot confer
                // privileges through a channel that has no password challenge.
                $admin = count($matches) === 1 ? $matches[0] : null;
            }
            if ($admin !== null && ((int) ($admin['is_active'] ?? 0) !== 1
                || (int) ($admin['must_change_pw'] ?? 1) !== 0
                || (!empty($admin['locked_until']) && strtotime((string) $admin['locked_until']) > time())
                || !in_array((string) $admin['role'], ['agent', 'counter', 'manager', 'superadmin'], true))) {
                $admin = null;
            }
            if ($admin !== null) {
                $role = (string) $admin['role'];
                $out['admin']   = $admin;
                $out['adminId'] = (int) $admin['id'];
                $out['name']    = (string) $admin['full_name'];
                // 'agent' is the counter agent: staff powers, but only over
                // their own book — the same obligation Auth::bookingScopeAdminId()
                // places on every admin page.
                $scoped = in_array($role, ['agent', 'counter'], true);
                $out['role']         = $scoped ? 'staff' : 'admin';
                $out['scopeAdminId'] = $scoped ? (int) $admin['id'] : null;
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

        /* 22 Sep 2026 (owner: "AI lai advance banau, knowledge deu"). The
           STATIC company knowledge — policies, rules, FAQs, how-to — that
           has no register row and used to live only in the system prompt.
           Read-only, all roles, and only when the office has switched the
           knowledge base on. Live facts (fare, refund amount, seats) still
           come from the booking tools, never from here. */
        if (Settings::getBool('ai_kb_on', false)) {
            $t[] = self::spec('knowledge_lookup',
                'Search the company KNOWLEDGE BASE for a verified answer to a POLICY, RULE, PROCESS or FAQ question you were not briefed on — for example luggage allowance, the cancellation/refund PROCESS, accepted payment methods, boarding-point detail, offers, or the agent process. NOT for a live fare, a live refund amount, seat availability or a specific booking — use the booking tools for those. Call this BEFORE telling someone you do not have a company or policy answer. Returns the matching verified article(s), or nothing.',
                ['query' => ['string', "The person's question, in their own words"]], ['query']);
        }

        $t[] = self::spec('plan_ticket',
            'QUOTE a ticket without selling it: reads live availability and returns the exact bus, date, pickup, berth(s), seats left and total fare. ALWAYS call this before issue_ticket, and read the total back to the passenger so they can say ho/yes. '
            . 'When you already know the passenger\'s NAME (and, for a desk sale, their MOBILE), pass them here too: they are pinned to the quote, read back with the fare, and the sale is refused if they differ later — that is how a name or number can never be wrong on the ticket.',
            [
                'seats'     => ['integer', 'How many berths (1–6)'],
                'date'      => ['string', "Travel date as YYYY-MM-DD. Leave empty for the next catchable bus."],
                'direction' => ['string', "toNepal = Gujarat→Rupaidiha ('jane'), toIndia = Rupaidiha→Gujarat ('aaune'). Empty = decide from the pickup."],
                'boarding'  => ['string', 'Pickup town or stop as the passenger said it, e.g. Surat, Vadodara, Mehsana, S Hari Parking'],
                'gender'    => ['string', 'Male, Female or Other — needed for a shared cabin berth'],
                'name'      => ['string', 'The passenger\'s full name, exactly as they gave it, if already known'],
                'phone'     => ['string', 'Desk sale only: the passenger\'s mobile, with +977 when it is a Nepali number'],
            ], ['seats']);

        if ($mayCut) {
            $t[] = self::spec('issue_ticket',
                'ISSUE the ticket that plan_ticket just quoted, after the passenger has clearly said yes (ho / hunxa / ok / thik cha / book it). The ticket PNG goes to their WhatsApp by itself. Only call this when the passenger agreed to the total you read out. '
                . 'The name must be the one the quote pinned (or the one they gave with their yes); a different name is refused — quote again. '
                . 'For a PARTY of 2 or more, put every traveller in names[] in the order they were given — each berth is then printed with its own name. Leave names[] out for a single traveller.',
                [
                    'name'    => ['string', "The booking name — the person writing to you"],
                    'names'   => ['array',  'Every traveller in the party, in the order given. Same count as the berths quoted.',
                                   ['type' => 'object', 'properties' => [
                                       'name'   => ['type' => 'string', 'description' => "The traveller's full name"],
                                       'gender' => ['type' => 'string', 'description' => 'Male, Female or Other'],
                                   ], 'required' => ['name']]],
                    'gender'  => ['string', 'Male, Female or Other — the booking name\'s own'],
                    'confirm' => ['boolean', 'Must be true — it records that the passenger said yes'],
                ], ['name', 'confirm']);
        }

        if ($staff && $mayCut) {
            $t[] = self::spec('staff_sell',
                'Sell a ticket AT THE DESK for a passenger who is not the sender: their name and number, the plan quoted by plan_ticket. The ticket goes to the passenger\'s WhatsApp and the commission is credited to the selling agent. Staff only. '
                . 'Pass the SAME name and mobile the quote pinned — a changed name or number is refused, so read both back before the seller says ho. '
                . 'For a GROUP — 4, 5, a whole family on one chalan — quote the seat count with plan_ticket, then pass every traveller in names[] here. One booking, one PNR, every berth printed with its own name. Ask for the names in ONE message, not one at a time.',
                [
                    'name'    => ['string', "The lead passenger's full name — the booking is in this name"],
                    'names'   => ['array',  'Every traveller in the party, in the order given. Same count as the berths quoted.',
                                   ['type' => 'object', 'properties' => [
                                       'name'   => ['type' => 'string', 'description' => "The traveller's full name"],
                                       'gender' => ['type' => 'string', 'description' => 'Male, Female or Other'],
                                   ], 'required' => ['name']]],
                    'phone'   => ['string', "The passenger's 10-digit mobile — the ticket goes there. Keep +977 in front of a Nepali number"],
                    'country' => ['string', 'NP when the passenger\'s number is Nepali, IN when Indian. Decides where the WhatsApp ticket goes'],
                    'gender'  => ['string', 'Male, Female or Other — the lead passenger\'s own'],
                    'pay'     => ['string', 'cash, upi, esewa or bank — how the passenger paid'],
                    'confirm' => ['boolean', 'Must be true'],
                ], ['name', 'phone', 'confirm']);
        }

        /* 24 Sep 2026 (owner: "agent lai bulk ticket support garos"). A pasted
           LIST — one passenger per line, name and mobile — is read by code,
           quoted in one message and sold on the next "ho". The same two
           functions the deterministic path in wabot.php calls. */
        if ($staff && Settings::getBool('wa_bulk_on', false)) {
            $t[] = self::spec('bulk_quote',
                'QUOTE a LIST of passengers the seller pasted — one per line, "Name 9876543210 M 32", optionally with Date:/From:/Pay: header lines. Two lines with the same mobile are one booking (a family). Returns every booking\'s names, number, bus, date, pickup, seats and fare, plus any line that could not be read. NOTHING is sold. Read the summary back and ask the seller to reply ho. Tell them "FORMAT" gives the template.',
                ['text' => ['string', 'The list exactly as the seller sent it, line breaks included']], ['text']);
            $t[] = self::spec('bulk_issue',
                'SELL every booking that bulk_quote read back, only after the seller replied ho / yes in a LATER message. Each ticket goes to its own passenger\'s WhatsApp; the commission goes to the seller. Returns the PNRs and any booking that failed.',
                ['confirm' => ['boolean', 'Must be true — the seller said ho to the bulk quote']], ['confirm']);
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

        if ($mayEdit) {
            /* 21 Sep 2026 (owner: "customer 3 ta samma ticket katda bigreo
               bhane natural language ma customer bhaneko sachchyaera tei
               ticket lai system bata naya generate").
               rename_passenger only ever fixed a NAME. Everything else that
               is commonly wrong on a freshly-cut ticket — the day, the
               pickup, the berth, the number the ticket went to — had no
               path at all from WhatsApp: the passenger had to ring the
               office and the desk redid it by hand. This is that path, and
               it reuses exactly what admin/reschedule.php calls, so a
               WhatsApp correction and a desk correction are the same
               transaction with the same audit row. */
            $t[] = self::spec('quote_ticket_fix',
                'PREVIEW a correction to the outbound travel DATE or contact PHONE. Nothing is changed. '
                . 'Read back every old/new value, new seats and unchanged fare, then ask for yes / ho in the NEXT message. '
                . 'One correction preview is pending per sender; a new preview replaces the previous one. '
                . 'Pickup changes, specific berth requests, return legs and fare differences need the office. For names use rename_passenger.',
                [
                    'pnr'          => ['string', 'The PNR of the ticket to correct'],
                    'new_date'     => ['string', 'Corrected travel date YYYY-MM-DD — only if the DAY is wrong'],
                    'new_phone'    => ['string', 'Corrected mobile; include +91 or +977 when changing country'],
                    'reason'       => ['string', "Short reason in the passenger's own words"],
                ], ['pnr']);
            $t[] = self::spec('fix_ticket',
                'APPLY exactly the correction from quote_ticket_fix, only after yes / ho in a later message. '
                . 'Use the same PNR, date and phone as the preview; no changed values. The signed ticket is refreshed and sent automatically. '
                . 'If the booking or seats changed, request a new preview and another confirmation; never choose replacements silently.',
                [
                    'pnr'          => ['string', 'The PNR from quote_ticket_fix'],
                    'new_date'     => ['string', 'Exactly the corrected date from the preview, when present'],
                    'new_phone'    => ['string', 'Exactly the corrected phone from the preview, when present'],
                    'confirm'      => ['boolean', 'True only after the sender explicitly agrees in a later message'],
                ], ['pnr', 'confirm']);
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

            /* 24 Sep 2026 (owner: "agent le ticket edit, cancel, manage garna
               sakos; aafno commission herna sakos"). The seller's own register
               and wallet, so a correction or a cancellation starts from a PNR
               they can find without the office. */
            $t[] = self::spec('my_sales',
                'This seller\'s OWN recent sales — PNR, passenger, mobile, travel date, pickup, seats, amount, status — newest first, or every sale travelling on one date. Use it to find the PNR before rename_passenger, quote_ticket_fix, resend_ticket, refund_quote or cancel_ticket.',
                [
                    'date'  => ['string', 'YYYY-MM-DD travel date to list, or empty for the latest sales'],
                    'limit' => ['integer', 'How many to show, 1–20 (default 8)'],
                ]);

            $t[] = self::spec('my_wallet',
                'This seller\'s OWN account: commission due to them, commission earned this month and lifetime, cash they still owe the office, the last ledger entries, any open payout request, deposit, KYC state and daily limit. Answers "mero commission kati bhayo", "mero hisab", "mero paisa kahile aaucha".',
                []);

            if (Settings::getBool('wa_agent_payout', false) && (string) ($ctx['admin']['role'] ?? '') === 'agent') {
                $t[] = self::spec('request_payout',
                    'Ask the office to PAY OUT this agent\'s commission. Call once WITHOUT confirm to see the amount and the rule, read it back, and only after the agent says ho in the NEXT message call again with confirm true. Empty amount = the whole commission due.',
                    [
                        'amount'  => ['number', 'Amount to request, or 0 for all that is due'],
                        'note'    => ['string', 'Optional note for the office'],
                        'confirm' => ['boolean', 'True only after the agent agreed in a later message'],
                    ]);
            }
        }

        if ($role === 'admin') {
            /* 24 Sep 2026 (owner: "admin le sabai heros — agent, customer,
               jun Admin portal ma milcha"). The office's reading tools over
               people: one agent, all agents, one customer, the payout queue. */
            $t[] = self::spec('office_agent',
                'ONE agent or counter, found by agent code (SHG-0027), name or mobile: status, KYC, their day (any date), commission due, earned this month and lifetime, cash owed, open payout request and last sales. When several match, the list comes back — ask which.',
                [
                    'q'    => ['string', 'Agent code, part of the name, or mobile'],
                    'date' => ['string', 'YYYY-MM-DD for their day, default today'],
                ], ['q']);

            $t[] = self::spec('office_agents',
                'Every agent at a glance: code, name, active or not, today\'s tickets, commission due, cash owed. Answers "kun agent le aaja kati becheyo", "kasko cash baaki cha".',
                []);

            $t[] = self::spec('office_customer',
                'ONE customer by mobile: the name on their bookings, how many trips, money spent, upcoming travel and their last bookings with status. What Admin → Customers shows.',
                ['phone' => ['string', 'The customer\'s 10-digit mobile']], ['phone']);

            $t[] = self::spec('office_payout_requests',
                'Commission payout requests waiting for the office, oldest first, with the agent, amount, their note and the commission actually due.',
                []);
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

                /* 24 Sep 2026 — the office's other buttons, each in two steps:
                   the first call previews and pins, the office says ho, the
                   second call (next message, confirm true) presses it. */
                $t[] = self::spec('office_settle_cod',
                    'Record that the CASH on a pay-at-boarding booking was collected — Admin → Payments "cash received". First call without confirm previews the booking and amount; after ho in the next message, call again with confirm true.',
                    [
                        'pnr'     => ['string', 'The PNR'],
                        'note'    => ['string', 'Who collected it / where'],
                        'confirm' => ['boolean', 'True only after the office said ho to the preview'],
                    ], ['pnr']);

                $t[] = self::spec('office_reject',
                    'REJECT a PENDING booking whose payment proof is wrong or missing — the seats go back, the passenger is told the reason. Preview first (no confirm), then confirm true after ho in the next message.',
                    [
                        'pnr'     => ['string', 'The PNR'],
                        'reason'  => ['string', 'Why, in plain words — the passenger reads this'],
                        'confirm' => ['boolean', 'True only after the office said ho to the preview'],
                    ], ['pnr', 'reason']);

                $t[] = self::spec('office_agent_status',
                    'ACTIVATE or DEACTIVATE an agent / counter account (Admin → Staff toggle). A deactivated agent cannot sell or sign in anywhere. Preview first (no confirm), then confirm true after ho in the next message. Never for a superadmin or for yourself.',
                    [
                        'q'       => ['string', 'Agent code, name or mobile'],
                        'active'  => ['boolean', 'true = activate, false = deactivate'],
                        'reason'  => ['string', 'Why — goes in the audit trail'],
                        'confirm' => ['boolean', 'True only after the office said ho to the preview'],
                    ], ['q', 'active']);
            }
        }

        return $t;
    }

    /** One tool spec in the Anthropic shape. */
    private static function spec(string $name, string $desc, array $props, array $required = []): array
    {
        $properties = [];
        foreach ($props as $key => $def) {
            [$type, $help] = $def;
            $properties[$key] = ['type' => $type, 'description' => $help];
            /* 21 Sep 2026 — an ARRAY property must declare what it holds.
               Gemini rejects the whole tool list otherwise:
               "function_declarations[3].parameters.properties[names].items:
               missing field", which takes down every tool on the call, not
               just this one. A third element in the prop gives the item
               schema; without one, a list of strings is the safe default —
               partyNames() accepts both shapes anyway. */
            if ($type === 'array') {
                $properties[$key]['items'] = isset($def[2]) && is_array($def[2])
                    ? $def[2]
                    : ['type' => 'string'];
            }
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
                'knowledge_lookup' => self::knowledgeLookup($args, $ctx),
                'plan_ticket'      => self::planTicket($args, $ctx),
                'issue_ticket'     => self::issueTicket($args, $ctx),
                'staff_sell'       => self::staffSell($args, $ctx),
                'refund_quote'     => self::refundQuote($args, $ctx),
                'cancel_ticket'    => self::cancelTicket($args, $ctx),
                'resend_ticket'    => self::resendTicket($args, $ctx),
                'payment_info'     => self::paymentInfo($args, $ctx),
                'rename_passenger' => self::renamePassenger($args, $ctx),
                'quote_ticket_fix' => self::quoteTicketFix($args, $ctx),
                'fix_ticket'       => self::fixTicket($args, $ctx),
                'bus_eta'          => self::busEta($args, $ctx),
                'agent_day'        => self::agentDay($args, $ctx),
                'agent_passengers' => self::agentPassengers($args, $ctx),
                'my_sales'         => self::mySales($args, $ctx),
                'my_wallet'        => self::myWallet($args, $ctx),
                'request_payout'   => self::requestPayout($args, $ctx),
                'bulk_quote'       => self::bulkQuote($args, $ctx),
                'bulk_issue'       => self::bulkIssue($args, $ctx),
                'office_day'       => self::officeDay($args, $ctx),
                'office_search'    => self::officeSearch($args, $ctx),
                'office_alerts'    => self::officeAlerts($args, $ctx),
                'office_confirm'   => self::officeConfirm($args, $ctx),
                'office_agent'     => self::officeAgent($args, $ctx),
                'office_agents'    => self::officeAgents($args, $ctx),
                'office_customer'  => self::officeCustomer($args, $ctx),
                'office_payout_requests' => self::officePayoutRequests($args, $ctx),
                'office_settle_cod' => self::officeSettleCod($args, $ctx),
                'office_reject'    => self::officeReject($args, $ctx),
                'office_agent_status' => self::officeAgentStatus($args, $ctx),
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

        /* 24 Sep 2026 (owner: "naam ra mobile number ma mistake nahos").
           A name or a number given before the quote is PINNED with it and
           read back beside the fare, so the one "ho" confirms all three.
           issue_ticket / staff_sell refuse a different name or number: a
           change means a fresh quote, never a silent substitution. */
        $pin = ['name' => '', 'phone' => '', 'country' => ''];
        $nameNote = '';
        $rawName  = (string) ($args['name'] ?? '');
        if (trim($rawName) !== '') {
            $clean = PersonName::clean($rawName);
            if ($clean !== '') {
                $pin['name'] = $clean;
            } else {
                $nameNote = ' The name "' . Security::clean($rawName, 40) . '" was NOT accepted: ' . PersonName::why($rawName)
                          . '. Ask for the passenger\'s real full name before they confirm.';
            }
        }
        $rawPhone = trim((string) ($args['phone'] ?? ''));
        if ($rawPhone !== '' && !$customer) {
            $digits = normalisePhone($rawPhone);
            if (preg_match('/^[6-9]\d{9}$/', $digits) === 1) {
                $pin['phone']   = $digits;
                $pin['country'] = resolvePhoneCountry('', $rawPhone);
            } else {
                $nameNote .= ' The mobile "' . Security::clean($rawPhone, 20) . '" is not a valid 10-digit number — ask for it again before selling.';
            }
        }

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
            'pin'      => $pin,
        ]);

        return [
            'ok'   => true,
            'say'  => 'This is a QUOTE, nothing is booked yet. Read the bus, date, pickup, berth and the TOTAL to the passenger'
                    . ($pin['name'] !== '' ? ', and the NAME "' . $pin['name'] . '"' : '')
                    . ($pin['phone'] !== '' ? ' and the MOBILE ' . $pin['phone'] . ($pin['country'] === 'NP' ? ' (+977)' : '') : '')
                    . ', and ask them to reply ho / yes to confirm.' . $nameNote,
            'data' => [
                'pinnedName'   => $pin['name'],
                'pinnedPhone'  => $pin['phone'],
                'pinnedCountry' => $pin['country'],
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

    /**
     * Answer a static policy / FAQ / how-to question from the curated
     * knowledge base (22 Sep 2026). Read-only: no booking, no fare, no seat.
     * A miss is recorded (redacted) so the office learns what to write next,
     * and the model is told NOT to invent an answer.
     */
    private static function knowledgeLookup(array $args, array $ctx): array
    {
        if (!Settings::getBool('ai_kb_on', false)) {
            return self::no('The knowledge base is switched off.');
        }
        require_once INCLUDE_PATH . '/aiknowledge.php';

        $query = Security::clean((string) ($args['query'] ?? ''), 200);
        if (mb_strlen($query) < 2) {
            return self::no('Ask what the person actually wants to know.');
        }

        $role = (string) ($ctx['role'] ?? 'customer');
        $lang = self::replyLang((string) ($ctx['messageText'] ?? $query));
        $hits = AiKb::search($query, $role, 3);

        if ($hits === []) {
            AiKb::logUnknown($query, $lang);
            return [
                'ok'   => true,
                'say'  => 'The knowledge base has no verified answer for this. Do NOT invent one: say you will check with the office and give the office number' . (Settings::officePhone() !== '' ? ' ' . Settings::officePhone() : '') . ', or ask the office directly if this is a staff chat.',
                'data' => ['found' => false, 'query' => $query],
                'media' => null,
            ];
        }

        $articles = [];
        foreach ($hits as $a) {
            $articles[] = [
                'title'    => (string) $a['canonical_title'],
                'category' => (string) ($a['category'] ?? ''),
                'answer'   => mb_substr(AiKb::answer($a, $lang), 0, 1200),
            ];
        }

        return [
            'ok'   => true,
            'say'  => 'Answer ONLY from these verified articles, in the person\'s own language and your own natural words. Never add a fact, number, date, price or policy that is not written here. If they do not fully cover the question, say so and offer the office number.',
            'data' => ['found' => true, 'articles' => $articles],
            'media' => null,
        ];
    }

    /** Cheap reply-language pick (ne / hi / en) for a knowledge answer. */
    private static function replyLang(string $text): string
    {
        try {
            if (!class_exists('TicketBot')) {
                require_once INCLUDE_PATH . '/ticketbot.php';
            }
            $lang = TicketBot::detectLang($text);
            return in_array($lang, ['ne', 'hi', 'en'], true) ? $lang : 'en';
        } catch (Throwable $e) {
            return 'en';
        }
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

        $staged = self::peekStage($ctx, 'sale');
        if ($staged === null) {
            return self::no('No quote is open. Call plan_ticket first and read the fare to the passenger.');
        }

        $rawName = (string) ($args['name'] ?? '');
        $name    = PersonName::clean($rawName !== '' ? $rawName : (string) ($ctx['name'] ?? ''));
        if ($name === '') {
            return self::no($rawName !== ''
                ? 'The name "' . Security::clean($rawName, 40) . '" cannot go on a ticket: ' . PersonName::why($rawName) . '. Ask for the passenger\'s real full name.'
                : "Ask the passenger's full name first.");
        }
        $pinned = (string) ($staged['pin']['name'] ?? '');
        if ($pinned !== '' && !PersonName::same($pinned, $name)) {
            return self::no('The name changed since the quote — quoted "' . $pinned . '", now "' . $name
                . '". Call plan_ticket again with the right name so the passenger reads it before saying ho.');
        }
        self::takeStage($ctx, 'sale');                 // consumed only now — a refusal above keeps the quote

        $seatCount = (int) ($staged['opts']['seats'] ?? 1);
        $party     = self::partyNames($args['names'] ?? null, $seatCount);

        $input = [
            'name'      => $name,
            'phone'     => (string) $ctx['phone'],     // always the sender's own number
            // +977 or +91 as the webhook delivered it — never guessed from ten digits.
            'country'   => (string) ($ctx['country'] ?? ''),
            'seats'     => $seatCount,
            'date'      => (string) ($staged['opts']['date'] ?? ''),
            'direction' => (string) ($staged['opts']['direction'] ?? ''),
            'boarding'  => (string) ($staged['opts']['boarding'] ?? ''),
            'gender'    => in_array($args['gender'] ?? '', ['Male', 'Female', 'Other'], true)
                ? (string) $args['gender']
                : ($staged['opts']['gender'] ?? null),
            // 21 Sep 2026: a party of 4 used to print "Ram (2)", "Ram (3)" —
            // QuickTicket has always accepted real names here, the tool just
            // never offered the model anywhere to put them.
            'passengers' => $party,
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

        $staged = self::peekStage($ctx, 'sale');
        if ($staged === null) {
            return self::no('No quote is open. Call plan_ticket first.');
        }

        /* The mobile: ten real digits, and the country kept from the +977 /
           +91 the seller typed (24 Sep 2026). normalisePhone() strips the
           prefix, and before this the stripped number reached QuickTicket
           with no country — so every Nepali passenger sold from WhatsApp
           had their ticket addressed to +91. */
        $rawPhone = trim((string) ($args['phone'] ?? ''));
        $phone    = normalisePhone($rawPhone);
        if ($phone === '' || preg_match('/^[6-9]\d{9}$/', $phone) !== 1) {
            return self::no("The passenger's mobile number is missing or not a valid 10-digit number. Ask for it again — the ticket goes there.");
        }
        $country = strtoupper(Security::clean((string) ($args['country'] ?? ''), 2));
        if ($country !== 'NP' && $country !== 'IN') {
            $country = resolvePhoneCountry('', $rawPhone);
        }

        $rawName = (string) ($args['name'] ?? '');
        $name    = PersonName::clean($rawName);
        if ($name === '') {
            return self::no('The name "' . Security::clean($rawName, 40) . '" cannot go on a ticket: ' . PersonName::why($rawName) . '. Ask for the passenger\'s real full name.');
        }
        $pin = (array) ($staged['pin'] ?? []);
        if ((string) ($pin['name'] ?? '') !== '' && !PersonName::same((string) $pin['name'], $name)) {
            return self::no('The name changed since the quote — quoted "' . $pin['name'] . '", now "' . $name . '". Quote again with plan_ticket so the seller reads the right name.');
        }
        if ((string) ($pin['phone'] ?? '') !== '' && (string) $pin['phone'] !== $phone) {
            return self::no('The mobile changed since the quote — quoted ' . $pin['phone'] . ', now ' . $phone . '. Quote again with plan_ticket so the seller reads the right number.');
        }
        if ($country === '' && (string) ($pin['country'] ?? '') !== '') {
            $country = (string) $pin['country'];
        }
        self::takeStage($ctx, 'sale');                 // consumed only now

        $input = [
            'name'      => $name,
            'phone'     => $phone,
            'country'   => $country,
            'seats'     => (int) ($staged['opts']['seats'] ?? 1),
            'date'      => (string) ($staged['opts']['date'] ?? ''),
            'direction' => (string) ($staged['opts']['direction'] ?? ''),
            'boarding'  => (string) ($staged['opts']['boarding'] ?? ''),
            'gender'    => in_array($args['gender'] ?? '', ['Male', 'Female', 'Other'], true)
                ? (string) $args['gender']
                : ($staged['opts']['gender'] ?? null),
            'pay'       => in_array($args['pay'] ?? '', ['cash', 'upi', 'esewa', 'bank'], true) ? (string) $args['pay'] : 'cash',
            // A whole family on one chalan, each berth under its own name.
            'passengers' => self::partyNames($args['names'] ?? null, (int) ($staged['opts']['seats'] ?? 1)),
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
            'say'  => $say . ' Read back ONLY these facts — including the name and the mobile the ticket is on, so a mistake is caught now.',
            'data' => [
                'pnr'        => $pnr,
                'status'     => (string) ($res['status'] ?? ''),
                'name'       => (string) ($res['name'] ?? ''),
                'phone'      => (string) ($res['phone'] ?? ''),
                'ticketSentToWhatsApp' => (bool) ($res['whatsapp']['sent'] ?? false),
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

    /**
     * Correct a mis-cut ticket — date, pickup, berth or contact number —
     * and send the re-minted ticket (21 Sep 2026).
     *
     * This is deliberately NOT a new booking engine. A date or pickup change
     * is BookingService::rebookLeg(), the identical call admin/reschedule.php
     * makes, so the seat locks, the cut-off, the fare rules and the audit row
     * are the desk's. The only thing this adds is understanding a sentence
     * instead of a form.
     *
     * The fences, in order: the tool must be on this sender's list, the
     * booking must be theirs, it must still be live and un-departed, the
     * passenger must have agreed (confirm), and a booking may be corrected
     * FIX_MAX times before it belongs to a human. A correction that would
     * change the fare is refused outright and sent to the office — nobody
     * gets re-priced by a chatbot.
     */
    /** Preview only: bind the requested values, current booking and exact seats. */
    private static function quoteTicketFix(array $args, array $ctx): array
    {
        $detail = self::correctionBooking((string) ($args['pnr'] ?? ''), $ctx);
        $request = self::correctionRequest($args);
        $proposal = self::correctionProposal($detail, $request, $ctx);
        $payload = [
            'pnr' => (string) $detail['pnr'],
            'request' => $request,
            'source' => self::correctionFingerprint($detail),
            'proposal' => $proposal,
            'reason' => Security::clean((string) ($args['reason'] ?? 'Corrected from WhatsApp'), 200),
        ];
        // Independent of sale/refund staging; one pending correction per sender.
        $saved = json_encode([
            'turn' => (int) ($ctx['turn'] ?? 0), 'at' => time(), 'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        Database::transaction(static function () use ($saved, $ctx): void {
            Database::delete('kv_store', 'kscope = :s AND kkey = :k',
                ['s' => 'wa_ticket_fix', 'k' => (string) $ctx['phone']]);
            Database::insert('kv_store', [
                'kscope' => 'wa_ticket_fix', 'kkey' => (string) $ctx['phone'],
                'kvalue' => $saved, 'updated_by' => 'aiagent',
            ]);
        });
        return [
            'ok' => true,
            'say' => 'This is only a correction preview; nothing changed. Read every old/new value, the exact seats and unchanged fare, then ask for yes / ho in the next message. This replaces any earlier correction preview.',
            'data' => ['pnr' => $detail['pnr'], 'changed' => $proposal['changed'],
                'date' => $proposal['date'], 'pickup' => $proposal['pickup'],
                'seats' => $proposal['seats'], 'total' => $proposal['total'],
                'newPhone' => $proposal['phone'], 'countryCode' => $proposal['country']],
            'media' => null,
        ];
    }

    private static function fixTicket(array $args, array $ctx): array
    {
        if (($args['confirm'] ?? false) !== true || !self::correctionConfirmed($ctx)) {
            return self::no('Ask the sender to reply yes / ho to the correction preview in a new message. A tool confirmation flag alone is not consent.');
        }
        $pnr = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $request = self::correctionRequest($args);
        if (!class_exists('TripStatus')) {
            require_once INCLUDE_PATH . '/tripstatus.php';
        }
        $result = Database::transaction(static function () use ($pnr, $request, $ctx): array {
            // Lock and consume once in the same transaction as the correction.
            $row = Database::fetchForUpdate(
                'SELECT kvalue FROM kv_store WHERE kscope = :s AND kkey = :k',
                ['s' => 'wa_ticket_fix', 'k' => (string) $ctx['phone']]
            )[0] ?? null;
            $saved = $row !== null ? json_decode((string) $row['kvalue'], true) : null;
            if (!is_array($saved) || (int) ($saved['at'] ?? 0) < time() - self::STAGE_TTL
                || (int) ($saved['at'] ?? 0) > time()
                || (int) ($saved['turn'] ?? 0) >= (int) ($ctx['turn'] ?? 0)) {
                throw new RuntimeException('No earlier, unexpired correction preview. Call quote_ticket_fix and ask for confirmation in the next message.');
            }
            $staged = $saved['payload'] ?? [];
            if (($staged['pnr'] ?? '') !== $pnr || ($staged['request'] ?? null) !== $request) {
                throw new RuntimeException('Those values do not match the correction preview. Quote the new values and ask again.');
            }
            $locked = Database::fetchForUpdate('SELECT * FROM bookings WHERE pnr = :p', ['p' => $pnr])[0] ?? null;
            if ($locked === null) {
                throw new RuntimeException('No booking with that PNR.');
            }
            // Read status, ownership and source seats again while holding the booking lock.
            $detail = self::correctionBooking($pnr, $ctx);
            if (!hash_equals((string) ($staged['source'] ?? ''), self::correctionFingerprint($detail))) {
                throw new RuntimeException('The booking changed after the preview. Get a fresh correction preview and confirmation.');
            }
            $proposal = self::correctionProposal($detail, $request, $ctx, (array) ($staged['proposal']['seats'] ?? []));
            if ($proposal !== ($staged['proposal'] ?? null)) {
                throw new RuntimeException('The quoted trip, seats or fare changed. Get a fresh correction preview and confirmation; do not substitute seats.');
            }
            $bid = (int) $detail['id'];
            $changed = $proposal['changed'];
            $reason = (string) ($staged['reason'] ?? 'Corrected from WhatsApp');
            // All validation is complete before either contact or date changes.
            if (isset($changed['contact'])) {
                $update = ['contact_phone' => $proposal['phone']];
                if ($proposal['country'] !== '') {
                    $update['contact_country_code'] = $proposal['country'];
                }
                Database::update('bookings', $update, 'id = :id', ['id' => $bid]);
                Database::update('payments', ['payer_phone' => $proposal['phone']], 'booking_id = :b', ['b' => $bid]);
            }
            Database::delete('kv_store', 'kscope = :s AND kkey = :k',
                ['s' => 'wa_ticket_fix', 'k' => (string) $ctx['phone']]);
            Logger::audit('booking.fix_whatsapp', 'booking', $pnr,
                ['date' => self::outboundLeg($detail)['travel_date'], 'phone' => $detail['contact_phone']],
                ['changed' => $changed], 'WhatsApp correction by ' . $ctx['phone'] . ': ' . $reason);
            if (isset($changed['date'])) {
                // Owns the seat locks, gender rules, fare preservation and ticket reissue.
                BookingService::rebookLeg($bid, (int) $proposal['scheduleId'], $proposal['seats'],
                    $proposal['date'], (int) ($ctx['adminId'] ?? 0), $reason, 'outbound');
            } else {
                Ticket::reissue($bid);
            }
            return ['bookingId' => $bid, 'changed' => $changed];
        });
        $bid = (int) $result['bookingId'];
        $fresh = BookingService::detail($pnr);
        $sent = ['ok' => false];
        try {
            Notify::adminNote('✏️ टिकट सच्चियो (WhatsApp)', [
                'PNR' => $pnr, 'बदलियो' => implode(', ', array_keys($result['changed'])),
                'फोन' => (string) $ctx['phone'],
            ], $bid);
        } catch (Throwable $e) {
            Logger::warning('Admin note after fix_ticket failed: ' . $e->getMessage(), [], 'whatsapp');
        }
        if ($fresh !== null) {
            try {
                $sent = Notify::ticketChanged($fresh, isset($result['changed']['date']) ? 'reschedule' : 'contact', [
                    'oldDate' => $result['changed']['date']['was'] ?? '',
                    'newDate' => $result['changed']['date']['now'] ?? '',
                    'seats' => self::outboundLeg($fresh)['seats'] ?? [],
                ]);
            } catch (Throwable $e) {
                Logger::warning('Ticket send after fix_ticket failed: ' . $e->getMessage(), [], 'whatsapp');
            }
        }
        return [
            'ok' => true,
            'say' => 'The ticket correction is saved.' . (($sent['ok'] ?? false)
                ? ' The corrected ticket was sent to the booking contact.'
                : ' Automatic delivery failed; give the corrected ticket link or ask the office to resend.')
                . ' Read the corrected date, phone and seats back to the sender.',
            'data' => ['pnr' => $pnr, 'changed' => $result['changed'], 'bookingId' => $bid,
                'resent' => (bool) ($sent['ok'] ?? false),
                'ticket' => $fresh !== null ? self::bookingCard($fresh) : []],
            'media' => ($fresh['status'] ?? '') === 'confirmed' ? Ticket::imageUrl($pnr) : null,
        ];
    }

    /** Strict input validation: never silently discard an invalid date or extra change. */
    private static function correctionRequest(array $args): array
    {
        if (trim((string) ($args['new_boarding'] ?? '')) !== '' || !empty($args['new_seats'])
            || !empty($args['new_seat']) || !empty($args['new_name'])
            || (!empty($args['leg']) && $args['leg'] !== 'outbound')) {
            throw new RuntimeException('This tool corrects the outbound date and contact number only. The office handles pickup, return-leg and requested berth changes; use rename_passenger for names.');
        }
        $date = trim((string) ($args['new_date'] ?? ''));
        if ($date !== '' && !Security::isValidDate($date)) {
            throw new RuntimeException('Send the corrected date as a valid YYYY-MM-DD.');
        }
        $raw = trim((string) ($args['new_phone'] ?? ''));
        $phone = $raw === '' ? '' : normalisePhone($raw);
        if ($raw !== '' && (preg_match('/^[+\d\s().-]+$/', $raw) !== 1 || preg_match('/^[6-9]\d{9}$/D', $phone) !== 1)) {
            throw new RuntimeException('Send a valid 10-digit mobile number, optionally with +91 or +977.');
        }
        if ($date === '' && $phone === '') {
            throw new RuntimeException('Ask which date or contact number needs correcting.');
        }
        return ['date' => $date, 'phone' => $phone,
            'country' => $raw !== '' ? countryDialCode(resolvePhoneCountry('', $raw)) : ''];
    }

    private static function correctionConfirmed(array $ctx): bool
    {
        $text = mb_strtolower(trim((string) ($ctx['messageText'] ?? '')));
        $text = preg_replace('/[\s.!।]+$/u', '', $text) ?? '';
        return preg_match('/^(?:yes|yes please|confirm|confirmed|ok|okay|ho|hunxa|huncha|thik cha|thik chha|ho garidinu|हुन्छ|हो|ठिक छ|ठीक छ)(?:\s+shg-[a-z0-9-]+)?$/u', $text) === 1;
    }

    /** Recheck ownership and departure without relying on model assertions. */
    private static function correctionBooking(string $pnr, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_rewrite', true)) {
            throw new RuntimeException('Ticket correction on WhatsApp is switched off — contact the office.');
        }
        $detail = BookingService::detail(strtoupper(trim($pnr)));
        if ($detail === null || !self::mayWrite($detail, $ctx)) {
            throw new RuntimeException('That booking is not available on this number.');
        }
        if (!in_array((string) $detail['status'], ['pending', 'confirmed'], true)) {
            throw new RuntimeException('Only a pending or confirmed booking can be corrected.');
        }
        if (!empty($detail['ticket']['scanned_at']) || (int) ($detail['ticket']['scan_count'] ?? 0) > 0) {
            throw new RuntimeException('This ticket was already boarded; contact the office.');
        }
        $leg = self::outboundLeg($detail);
        $when = strtotime((string) ($leg['travel_date'] ?? '') . ' ' . (string) ($leg['dep_time'] ?? ''));
        if (empty($leg['dep_time']) || $when === false || $when <= time()
            || in_array((string) ($leg['schedule_status'] ?? ''), ['departed', 'running', 'completed', 'cancelled'], true)) {
            throw new RuntimeException('That bus has departed or is closed, so the ticket cannot be changed.');
        }
        $used = (int) Database::scalar(
            "SELECT COUNT(*) FROM ai_agent_calls WHERE tool = 'fix_ticket' AND ok = 1 AND booking_id = :b",
            ['b' => (int) $detail['id']], 0
        );
        if ($used >= self::FIX_MAX) {
            throw new RuntimeException('This ticket has already been corrected ' . self::FIX_MAX . ' times. Contact the office for more changes.');
        }
        return $detail;
    }

    private static function outboundLeg(array $detail): array
    {
        foreach ($detail['legs'] ?? [] as $leg) {
            if (($leg['leg_type'] ?? '') === 'outbound') {
                return $leg;
            }
        }
        throw new RuntimeException('This booking has no outbound journey to correct.');
    }

    private static function correctionFingerprint(array $detail): string
    {
        return hash('sha256', json_encode([
            'status' => $detail['status'], 'phone' => $detail['contact_phone'],
            'country' => $detail['contact_country_code'] ?? '', 'total' => $detail['total_amount'],
            'mode' => $detail['booking_mode'] ?? '', 'seller' => $detail['sold_by_admin_id'] ?? null,
            'legs' => $detail['legs'], 'passengers' => $detail['passengers'] ?? [],
            'ticket' => $detail['ticket'] ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private static function correctionProposal(array $detail, array $request, array $ctx, array $prefer = []): array
    {
        $leg = self::outboundLeg($detail);
        $oldDate = (string) $leg['travel_date'];
        $date = $request['date'] !== '' ? $request['date'] : $oldDate;
        $phone = $request['phone'] !== '' ? $request['phone'] : normalisePhone((string) $detail['contact_phone']);
        $country = $request['country'] !== '' ? $request['country'] : (string) ($detail['contact_country_code'] ?? '');
        $changed = [];
        if ($phone !== normalisePhone((string) $detail['contact_phone']) || $country !== (string) ($detail['contact_country_code'] ?? '')) {
            $changed['contact'] = ['was' => (string) $detail['contact_phone'], 'now' => $phone,
                'oldCountry' => (string) ($detail['contact_country_code'] ?? ''), 'newCountry' => $country];
        }
        $proposal = ['date' => $date, 'phone' => $phone, 'country' => $country,
            'pickup' => (string) ($leg['boarding_stop'] ?? ''), 'seats' => (array) ($leg['seats'] ?? []),
            'scheduleId' => (int) $leg['schedule_id'], 'total' => (float) $detail['total_amount']];
        if ($date !== $oldDate) {
            // QuickTicket can choose among routes: explicitly reject a different route,
            // coach mode, pickup or fare instead of silently accepting its fallback.
            $source = Database::fetch(
                'SELECT s.route_id, r.direction FROM schedules s JOIN routes r ON r.id = s.route_id WHERE s.id = :id',
                ['id' => (int) $leg['schedule_id']]
            );
            if ($source === null) {
                throw new RuntimeException('The original route is unavailable; contact the office.');
            }
            $passengers = array_values(array_filter($detail['passengers'] ?? [],
                static fn(array $p): bool => (int) ($p['leg_id'] ?? 0) === (int) $leg['id']));
            $genders = array_unique(array_column($passengers, 'gender'));
            $plan = QuickTicket::plan([
                'seats' => (int) $leg['seat_count'], 'date' => $date,
                'direction' => (string) $source['direction'],
                'boarding' => (string) $leg['boarding_stop'],
                'gender' => count($genders) === 1 ? (string) reset($genders) : '',
                'customer' => ($ctx['role'] ?? 'customer') === 'customer',
                'prefer' => $prefer,
            ]);
            $samePickup = !empty($plan['matchedDesk'])
                && trim((string) ($plan['boarding'] ?? '')) === trim((string) $leg['boarding_stop']);
            if ((int) $plan['routeId'] !== (int) $source['route_id']
                || (string) $plan['date'] !== $date || !$samePickup
                || (string) ($plan['bookingMode'] ?? '') !== (string) ($detail['booking_mode'] ?? '')
                || count($plan['seats']) !== (int) $leg['seat_count']) {
                throw new RuntimeException('That date needs a different route, pickup or seat arrangement. The office must handle this correction.');
            }
            if (abs((float) $plan['fare']['total'] - (float) $detail['total_amount']) >= 0.01) {
                throw new RuntimeException('That date has a different fare (' . inr((float) $detail['total_amount'])
                    . ' → ' . inr((float) $plan['fare']['total']) . '). The office must handle the price difference.');
            }
            $proposal['scheduleId'] = (int) $plan['scheduleId'];
            $proposal['seats'] = array_values($plan['seats']);
            $changed['date'] = ['was' => $oldDate, 'now' => $date];
            $changed['seats'] = ['was' => (array) ($leg['seats'] ?? []), 'now' => $proposal['seats']];
        }
        if ($changed === []) {
            throw new RuntimeException('The ticket already has those values; nothing needs changing.');
        }
        $proposal['changed'] = $changed;
        return $proposal;
    }
    /**
     * The model's names[] → the shape QuickTicket::requestFor() reads
     * (21 Sep 2026, owner: "counter agent mode lai bulk ticket 4-5 ota").
     *
     * Trimmed to the berths actually quoted, because the model is the one
     * counting and a party list longer than the seats would silently drop
     * a traveller — or, worse, put a name on a berth nobody bought. Short
     * lists are fine: requestFor() numbers the remainder after the buyer
     * exactly as the seat map does.
     *
     * Anything that is not a usable name is dropped rather than guessed at.
     *
     * @return list<array{name: string, gender: string|null}>
     */
    private static function partyNames(mixed $raw, int $seatCount): array
    {
        if (!is_array($raw) || $raw === [] || $seatCount < 1) {
            return [];
        }
        $out = [];
        foreach (array_values($raw) as $row) {
            if (count($out) >= $seatCount) {
                break;
            }
            // Accept both [{"name":…}] and a plain ["Ram","Sita"] — models
            // produce either, and a dropped family member is not worth a
            // schema argument.
            $name = is_array($row)
                ? Security::clean((string) ($row['name'] ?? ''), 120)
                : (is_string($row) ? Security::clean($row, 120) : '');
            if (mb_strlen($name) < 2 || preg_match('/\d/', $name) === 1) {
                continue;
            }
            $g = is_array($row) ? (string) ($row['gender'] ?? '') : '';
            $out[] = [
                'name'   => $name,
                'gender' => in_array($g, ['Male', 'Female', 'Other'], true) ? $g : null,
            ];
        }
        return $out;
    }

    /** Seat numbers on a booking, from whichever shape detail() returned. */
    private static function seatNos(array $detail): array
    {
        $out = [];
        foreach (($detail['passengers'] ?? []) as $p) {
            $s = trim((string) ($p['seat_no'] ?? ''));
            if ($s !== '') { $out[] = $s; }
        }
        return $out;
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
        $day  = self::sellerDay($adminId, $date);

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
                'tickets'       => $day['tickets'],
                'seats'         => $day['seats'],
                'cancelled'     => $day['cancelled'],
                'amount'        => $day['amount'],
                'amountLabel'   => $day['amountLabel'],
                'cashTakenToday' => $day['cashTaken'],
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
     *  Tools — a seller's own register and wallet (24 Sep 2026)
     * ================================================================= */

    /**
     * The seller's own recent sales, so a correction or a cancellation can
     * start from a PNR they find themselves. Scoped to sold_by_admin_id,
     * exactly like admin/bookings.php is for a counter agent.
     */
    private static function mySales(array $args, array $ctx): array
    {
        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($adminId <= 0) {
            return self::no('Only a staff number can ask this.');
        }
        $date  = self::cleanDate((string) ($args['date'] ?? ''));
        $limit = max(1, min(20, (int) ($args['limit'] ?? 8)));

        $sql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.contact_country_code, b.created_at,
                       l.travel_date, l.boarding_stop, l.seat_count,
                       (SELECT p.full_name FROM booking_passengers p WHERE p.booking_id = b.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS pax,
                       (SELECT COUNT(*) FROM payments pm WHERE pm.booking_id = b.id AND pm.status = 'verified') AS paid
                  FROM bookings b
                  LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
                 WHERE b.sold_by_admin_id = :a";
        $params = ['a' => $adminId];
        if ($date !== '') {
            $sql .= ' AND l.travel_date = :d';
            $params['d'] = $date;
        }
        $sql .= ' ORDER BY b.id DESC LIMIT ' . $limit;

        $list = [];
        foreach (Database::fetchAll($sql, $params) as $r) {
            $list[] = [
                'pnr'       => (string) $r['pnr'],
                'name'      => (string) ($r['pax'] ?? ''),
                'phone'     => (string) $r['contact_phone'],
                'country'   => (string) ($r['contact_country_code'] ?? ''),
                'date'      => (string) ($r['travel_date'] ?? ''),
                'dateLabel' => formatDate((string) ($r['travel_date'] ?? ''), 'D, j M'),
                'pickup'    => Boarding::stopDisplay((string) ($r['boarding_stop'] ?? ''))['name'],
                'seats'     => (int) ($r['seat_count'] ?? 0),
                'total'     => (float) $r['total_amount'],
                'totalLabel' => inr((float) $r['total_amount']),
                'status'    => (string) $r['status'],
                'paid'      => (int) ($r['paid'] ?? 0) > 0,
                'soldAt'    => substr((string) $r['created_at'], 0, 16),
            ];
        }

        return [
            'ok'   => true,
            'say'  => $list === []
                ? ($date !== '' ? 'This seller has no sale travelling on ' . $date . '.' : 'This seller has no sales yet.')
                : 'This seller\'s own sales, newest first. Quote the PNR when they want to change, resend or cancel one.',
            'data' => ['date' => $date, 'count' => count($list), 'sales' => $list],
            'media' => null,
        ];
    }

    /** The seller's own wallet — what the Agent Panel shows them. */
    private static function myWallet(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($adminId <= 0) {
            return self::no('Only a staff number can ask this.');
        }

        return [
            'ok'   => true,
            'say'  => 'This seller\'s own account. commissionDue is what the company owes them; cashDue is what they still owe the company — never add the two. '
                    . 'If cashDue is above cashLimit, or KYC is not verified, or a payout request is open, say so plainly like a manager would.',
            'data' => self::walletCard($adminId, (string) ($ctx['name'] ?? '')),
            'media' => null,
        ];
    }

    /**
     * One agent's account, the way the seller and the office both read it.
     *
     * @return array<string,mixed>
     */
    private static function walletCard(int $adminId, string $name = ''): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $bal     = ['commission' => 0.0, 'cash' => 0.0];
        $sum     = [];
        $profile = [];
        $entries = [];
        $open    = [];
        $deposit = [];
        try {
            $bal     = AgentWallet::balances($adminId) + $bal;
            $sum     = AgentWallet::summary($adminId);
            $profile = AgentWallet::profile($adminId);
            $entries = AgentWallet::entries($adminId, 6);
            $open    = AgentWallet::openPayoutRequests($adminId);
            $deposit = AgentWallet::depositInfo($adminId);
        } catch (Throwable $e) {
            Logger::exception($e, 'whatsapp');
        }
        $recent = [];
        foreach ($entries as $e) {
            $recent[] = [
                'when'   => substr((string) ($e['created_at'] ?? ''), 0, 10),
                'type'   => (string) ($e['entry_type'] ?? ''),
                'amount' => (float) ($e['amount'] ?? 0),
                'pnr'    => (string) ($e['pnr'] ?? ''),
                'note'   => mb_substr((string) ($e['note'] ?? ''), 0, 60),
            ];
        }
        $limit = 0;
        $today = 0;
        try {
            $limit = AgentWallet::dailyLimitFor($adminId);
            $today = AgentWallet::bookingsToday($adminId);
        } catch (Throwable $ignored) {
        }

        return [
            'seller'          => $name,
            'agentCode'       => AgentWallet::agentCodeLabel($adminId),
            'commissionDue'   => (float) $bal['commission'],
            'commissionDueLabel' => inr((float) $bal['commission']),
            'commissionThisMonth' => (float) ($sum['earnedMonth'] ?? 0),
            'commissionLifetime'  => (float) ($sum['earned'] ?? 0),
            'paidOutLifetime'     => (float) ($sum['paidOut'] ?? 0),
            'commissionRule'  => self::commissionRule($adminId),
            'cashDue'         => (float) $bal['cash'],
            'cashDueLabel'    => inr((float) $bal['cash']),
            'cashLimit'       => (float) ($profile['cash_limit'] ?? 0),
            'kyc'             => (string) ($profile['kyc_status'] ?? 'none'),
            'suspendedReason' => (string) ($profile['suspended_reason'] ?? ''),
            'dailyLimit'      => $limit,
            'bookingsToday'   => $today,
            'depositRequired' => (float) ($deposit['required'] ?? 0),
            'depositPaid'     => (float) ($deposit['paid'] ?? 0),
            'openPayoutRequest' => $open !== []
                ? ['amount' => (float) $open[0]['amount'], 'since' => substr((string) $open[0]['created_at'], 0, 10)]
                : null,
            'recentEntries'   => $recent,
        ];
    }

    /** "5% of every ticket" / "1 x ₹200 (direct agent)" — the rule, not a sum of nothing. */
    private static function commissionRule(int $adminId): string
    {
        try {
            $note = AgentWallet::commissionNoteFor($adminId, 1, 0.0);
            return str_contains($note, '% of')
                ? AgentWallet::commissionPercentFor($adminId) . '% of every ticket'
                : $note;
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * An agent asks for their commission (wa_agent_payout). Two steps: the
     * first call shows the figure and pins it, the agent says ho, the
     * second call (next message, confirm true) files the request — the
     * same AgentWallet::requestPayout row the panel writes.
     */
    private static function requestPayout(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        if (!Settings::getBool('wa_agent_payout', false)) {
            return self::no('Payout requests from WhatsApp are switched off — use the Agent Panel.');
        }
        $adminId = (int) ($ctx['adminId'] ?? 0);
        if ($adminId <= 0 || (string) ($ctx['admin']['role'] ?? '') !== 'agent') {
            return self::no('Only an agent account can request a payout.');
        }

        $due    = (float) (AgentWallet::balances($adminId)['commission'] ?? 0);
        $amount = round((float) ($args['amount'] ?? 0), 2);
        if ($amount <= 0) {
            $amount = $due;
        }
        $note = Security::clean((string) ($args['note'] ?? ''), 200);

        if (($args['confirm'] ?? false) !== true) {
            if ($due <= 0) {
                return self::no('No commission is due right now, so there is nothing to request.');
            }
            if ($amount > $due + 0.009) {
                return self::no('Only ' . inr($due) . ' is due — they cannot request more than that.');
            }
            $open = AgentWallet::openPayoutRequests($adminId);
            if ($open !== []) {
                return self::no('A payout request for ' . inr((float) $open[0]['amount']) . ' from '
                    . formatDate(substr((string) $open[0]['created_at'], 0, 10)) . ' is still with the office. Wait for that one.');
            }
            self::stage($ctx, 'payout', ['amount' => $amount, 'note' => $note]);
            return [
                'ok'   => true,
                'say'  => 'Nothing is filed yet. Read the amount back and ask the agent to reply ho; then call request_payout again with confirm true.',
                'data' => ['amount' => $amount, 'amountLabel' => inr($amount), 'due' => $due, 'dueLabel' => inr($due),
                           'minimum' => Settings::getFloat('agent_payout_min', 0.0)],
                'media' => null,
            ];
        }

        $staged = self::takeStage($ctx, 'payout');
        if ($staged === null) {
            return self::no('Preview the payout first (request_payout without confirm) and get a ho in the next message.');
        }
        $amount = (float) ($staged['amount'] ?? $amount);
        $id     = AgentWallet::requestPayout($adminId, $amount, (string) ($staged['note'] ?? $note));

        return [
            'ok'   => true,
            'say'  => 'The payout request is with the office. Say the office decides it and the money follows the usual way.',
            'data' => ['requestId' => $id, 'amount' => $amount, 'amountLabel' => inr($amount)],
            'media' => null,
        ];
    }

    /* =================================================================
     *  Tools — bulk (24 Sep 2026)
     * ================================================================= */

    private static function bulkQuote(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/wabulk.php';
        if (!WaBulk::enabled()) {
            return self::no('Bulk tickets on WhatsApp are switched off.');
        }
        $text = (string) ($args['text'] ?? '');
        if (trim($text) === '') {
            return self::no('Pass the list the seller sent.');
        }
        $res = WaBulk::quote($ctx, WaBulk::parse($text));

        return [
            'ok'   => (bool) $res['ok'],
            'say'  => $res['ok']
                ? 'This is a QUOTE, nothing is sold. Read every name, mobile, the bus and the fare back — the text below is already written for WhatsApp, you may send it as is — and ask for ho. Report the lines that failed.'
                : 'No booking could be read from the list. Read the problems back and offer the FORMAT template.',
            'data' => $res['data'] + ['reply' => $res['text']],
            'media' => null,
        ];
    }

    private static function bulkIssue(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/wabulk.php';
        if (!WaBulk::enabled()) {
            return self::no('Bulk tickets on WhatsApp are switched off.');
        }
        if (($args['confirm'] ?? false) !== true) {
            return self::no('The seller has not said ho to the bulk quote yet.');
        }
        $admin = $ctx['admin'] ?? null;
        if (!is_array($admin) || (int) ($admin['id'] ?? 0) <= 0) {
            return self::no('Only a staff number may sell for other passengers.');
        }
        $res = WaBulk::issue($ctx, $admin);

        return [
            'ok'   => (bool) $res['ok'],
            'say'  => $res['ok']
                ? 'Sold. Read back the PNRs and the failures exactly — the text below is ready for WhatsApp.'
                : (string) $res['text'],
            'data' => $res['data'] + ['reply' => $res['text']],
            'media' => null,
        ];
    }

    /* =================================================================
     *  Tools — the office over people (24 Sep 2026)
     * ================================================================= */

    /**
     * Find staff by agent code, name or mobile. Inactive accounts included:
     * the office needs to see them to switch them back on.
     *
     * @return list<array<string,mixed>>
     */
    private static function findStaff(string $q): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $q = Security::clean($q, 80);
        if ($q === '') {
            return [];
        }
        $cols = "id, username, full_name, phone, email, role, is_active, locked_until, last_login_at";
        if (preg_match('/^\s*(?:shg|agent)?[-\s]*0*(\d{1,4})\s*$/i', $q, $m) === 1) {
            $adminId = AgentWallet::adminForAgentCode((int) $m[1]);
            if ($adminId !== null) {
                $row = Database::fetch("SELECT $cols FROM admins WHERE id = :id", ['id' => $adminId]);
                return $row !== null ? [$row] : [];
            }
        }
        $digits = normalisePhone($q);
        if (strlen($digits) >= 8) {
            $out = [];
            foreach (Database::fetchAll("SELECT $cols FROM admins WHERE phone IS NOT NULL AND phone <> ''") as $row) {
                if (normalisePhone((string) $row['phone']) === $digits) {
                    $out[] = $row;
                }
            }
            if ($out !== []) {
                return $out;
            }
        }
        return Database::fetchAll(
            "SELECT $cols FROM admins
              WHERE role IN ('agent','counter','manager','superadmin','accountant','support')
                AND (full_name LIKE :n OR username LIKE :u)
              ORDER BY is_active DESC, full_name LIMIT 8",
            ['n' => '%' . $q . '%', 'u' => '%' . $q . '%']
        );
    }

    /** One seller's day — shared by agent_day and office_agent. */
    private static function sellerDay(int $adminId, string $date): array
    {
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
        $cancelled = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :a AND DATE(created_at) = :d AND status IN ('cancelled','rejected')",
            ['a' => $adminId, 'd' => $date],
            0
        );

        return [
            'date'        => $date,
            'tickets'     => (int) ($row['tickets'] ?? 0),
            'seats'       => $seats,
            'amount'      => (float) ($row['amount'] ?? 0),
            'amountLabel' => inr((float) ($row['amount'] ?? 0)),
            'cashTaken'   => (float) ($row['cash'] ?? 0),
            'cancelled'   => $cancelled,
        ];
    }

    private static function officeAgent(array $args, array $ctx): array
    {
        $q = (string) ($args['q'] ?? '');
        $hits = self::findStaff($q);
        if ($hits === []) {
            return self::no('No agent or staff member matches "' . Security::clean($q, 40) . '".');
        }
        if (count($hits) > 1) {
            $list = [];
            foreach ($hits as $h) {
                $list[] = ['name' => (string) $h['full_name'], 'code' => AgentWallet::agentCodeLabel((int) $h['id']),
                           'role' => (string) $h['role'], 'active' => (int) $h['is_active'] === 1];
            }
            return ['ok' => true, 'say' => 'Several match — ask which one.', 'data' => ['matches' => $list], 'media' => null];
        }
        $a    = $hits[0];
        $id   = (int) $a['id'];
        $date = self::cleanDate((string) ($args['date'] ?? '')) ?: todayISO();

        $sales = [];
        foreach (Database::fetchAll(
            "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, l.travel_date,
                    (SELECT p.full_name FROM booking_passengers p WHERE p.booking_id = b.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS pax
               FROM bookings b LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
              WHERE b.sold_by_admin_id = :a ORDER BY b.id DESC LIMIT 5",
            ['a' => $id]
        ) as $r) {
            $sales[] = ['pnr' => (string) $r['pnr'], 'name' => (string) ($r['pax'] ?? ''), 'phone' => (string) $r['contact_phone'],
                        'date' => (string) ($r['travel_date'] ?? ''), 'total' => (float) $r['total_amount'], 'status' => (string) $r['status']];
        }

        return [
            'ok'   => true,
            'say'  => 'This agent, from the register. Give the figures the office asked for; commissionDue is owed TO the agent, cashDue is owed BY the agent.',
            'data' => [
                'name'      => (string) $a['full_name'],
                'username'  => (string) $a['username'],
                'role'      => (string) $a['role'],
                'phone'     => (string) ($a['phone'] ?? ''),
                'active'    => (int) $a['is_active'] === 1,
                'locked'    => !empty($a['locked_until']) && strtotime((string) $a['locked_until']) > time(),
                'lastLogin' => (string) ($a['last_login_at'] ?? ''),
                'day'       => self::sellerDay($id, $date),
                'wallet'    => self::walletCard($id, (string) $a['full_name']),
                'lastSales' => $sales,
            ],
            'media' => null,
        ];
    }

    private static function officeAgents(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $today = todayISO();
        $list  = [];
        foreach (Database::fetchAll(
            "SELECT id, full_name, role, is_active FROM admins WHERE role IN ('agent','counter') ORDER BY is_active DESC, full_name LIMIT 40"
        ) as $a) {
            $id  = (int) $a['id'];
            $bal = ['commission' => 0.0, 'cash' => 0.0];
            try {
                $bal = AgentWallet::balances($id) + $bal;
            } catch (Throwable $ignored) {
            }
            $day = self::sellerDay($id, $today);
            $list[] = [
                'name'          => (string) $a['full_name'],
                'code'          => AgentWallet::agentCodeLabel($id),
                'role'          => (string) $a['role'],
                'active'        => (int) $a['is_active'] === 1,
                'ticketsToday'  => $day['tickets'],
                'amountToday'   => $day['amount'],
                'commissionDue' => (float) $bal['commission'],
                'cashDue'       => (float) $bal['cash'],
            ];
        }
        usort($list, static fn(array $x, array $y): int => [$y['active'], $y['ticketsToday'], $y['cashDue']] <=> [$x['active'], $x['ticketsToday'], $x['cashDue']]);

        return [
            'ok'   => true,
            'say'  => $list === [] ? 'No agent accounts yet.' : 'Every agent today. Name the ones that matter for the question; do not read the whole table unless asked.',
            'data' => ['date' => $today, 'count' => count($list), 'agents' => $list],
            'media' => null,
        ];
    }

    private static function officeCustomer(array $args, array $ctx): array
    {
        $phone = normalisePhone((string) ($args['phone'] ?? ''));
        if ($phone === '' || strlen($phone) < 8) {
            return self::no('Give the customer\'s 10-digit mobile.');
        }
        $tot = Database::fetch(
            "SELECT COUNT(*) AS trips,
                    COALESCE(SUM(CASE WHEN status IN ('confirmed','completed') THEN total_amount ELSE 0 END),0) AS spent,
                    SUM(status = 'cancelled') AS cancelled,
                    MIN(created_at) AS first_seen
               FROM bookings WHERE contact_phone = :p",
            ['p' => $phone]
        ) ?? [];
        if ((int) ($tot['trips'] ?? 0) === 0) {
            return ['ok' => true, 'say' => 'This number has never booked with us.', 'data' => ['phone' => $phone, 'trips' => 0], 'media' => null];
        }
        $rows = Database::fetchAll(
            "SELECT b.pnr, b.status, b.total_amount, b.contact_country_code, b.source, l.travel_date, l.boarding_stop,
                    r.from_city, r.to_city, l.seat_count,
                    (SELECT p.full_name FROM booking_passengers p WHERE p.booking_id = b.id ORDER BY p.is_primary DESC, p.id LIMIT 1) AS pax,
                    a.full_name AS seller
               FROM bookings b
               LEFT JOIN booking_legs l ON l.booking_id = b.id AND l.leg_type = 'outbound'
               LEFT JOIN schedules s ON s.id = l.schedule_id
               LEFT JOIN routes r ON r.id = s.route_id
               LEFT JOIN admins a ON a.id = b.sold_by_admin_id
              WHERE b.contact_phone = :p ORDER BY b.id DESC LIMIT 8",
            ['p' => $phone]
        );
        $list = [];
        $name = '';
        $upcoming = [];
        foreach ($rows as $r) {
            if ($name === '' && (string) ($r['pax'] ?? '') !== '') {
                $name = (string) $r['pax'];
            }
            $item = [
                'pnr'    => (string) $r['pnr'],
                'name'   => (string) ($r['pax'] ?? ''),
                'date'   => (string) ($r['travel_date'] ?? ''),
                'route'  => trim((string) ($r['from_city'] ?? '') . ' → ' . (string) ($r['to_city'] ?? ''), ' →'),
                'pickup' => Boarding::stopDisplay((string) ($r['boarding_stop'] ?? ''))['name'],
                'seats'  => (int) ($r['seat_count'] ?? 0),
                'total'  => (float) $r['total_amount'],
                'status' => (string) $r['status'],
                'source' => (string) $r['source'],
                'seller' => (string) ($r['seller'] ?? ''),
            ];
            $list[] = $item;
            if (in_array($item['status'], ['confirmed', 'pending'], true) && $item['date'] >= todayISO()) {
                $upcoming[] = $item['pnr'] . ' on ' . $item['date'];
            }
        }

        return [
            'ok'   => true,
            'say'  => 'This customer, from the register. Give what was asked; do not read out every booking unless asked.',
            'data' => [
                'phone'     => $phone,
                'country'   => (string) ($rows[0]['contact_country_code'] ?? ''),
                'name'      => $name,
                'trips'     => (int) ($tot['trips'] ?? 0),
                'cancelled' => (int) ($tot['cancelled'] ?? 0),
                'spent'     => (float) ($tot['spent'] ?? 0),
                'spentLabel' => inr((float) ($tot['spent'] ?? 0)),
                'firstSeen' => substr((string) ($tot['first_seen'] ?? ''), 0, 10),
                'upcoming'  => $upcoming,
                'bookings'  => $list,
            ],
            'media' => null,
        ];
    }

    private static function officePayoutRequests(array $args, array $ctx): array
    {
        require_once INCLUDE_PATH . '/agentwallet.php';
        $list = [];
        foreach (AgentWallet::openPayoutRequests(null) as $r) {
            $id = (int) $r['agent_admin_id'];
            $list[] = [
                'requestId' => (int) $r['id'],
                'agent'     => (string) ($r['agent_name'] ?? ''),
                'code'      => AgentWallet::agentCodeLabel($id),
                'amount'    => (float) $r['amount'],
                'amountLabel' => inr((float) $r['amount']),
                'note'      => (string) ($r['note'] ?? ''),
                'since'     => substr((string) $r['created_at'], 0, 10),
                'commissionDue' => (float) (AgentWallet::balances($id)['commission'] ?? 0),
            ];
        }

        return [
            'ok'   => true,
            'say'  => $list === [] ? 'No payout request is waiting.' : 'Open payout requests, oldest first. They are paid or declined in Admin → Agent Panel; say so.',
            'data' => ['count' => count($list), 'requests' => $list],
            'media' => null,
        ];
    }

    /**
     * The office's two-step write: preview + pin on the first call, press
     * the button on the second (next message, confirm true, same PNR).
     */
    private static function officeSettleCod(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_admin_write', false)) {
            return self::no('Office writes from WhatsApp are switched off — do it in Admin → Payments.');
        }
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return self::no('Only an office number may record cash.');
        }
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if ((int) ($detail['is_cod'] ?? 0) !== 1) {
            return self::no('Booking ' . $pnr . ' is not a pay-at-boarding booking.');
        }
        if ((string) ($detail['payment']['status'] ?? '') === 'verified') {
            return self::no('The cash on ' . $pnr . ' is already recorded as collected.');
        }
        $note = Security::clean((string) ($args['note'] ?? 'Cash recorded on WhatsApp'), 200);

        if (($args['confirm'] ?? false) !== true) {
            self::stage($ctx, 'office', ['action' => 'settle_cod', 'pnr' => $pnr, 'note' => $note]);
            return [
                'ok'   => true,
                'say'  => 'Nothing is recorded yet. Read the PNR, passenger and amount back and ask for ho; then call again with confirm true.',
                'data' => self::bookingCard($detail),
                'media' => null,
            ];
        }
        $staged = self::takeStage($ctx, 'office');
        if ($staged === null || ($staged['action'] ?? '') !== 'settle_cod' || ($staged['pnr'] ?? '') !== $pnr) {
            return self::no('Preview this exact PNR first (office_settle_cod without confirm) and get a ho in the next message.');
        }
        $res = BookingService::settleCod((int) $detail['id'], (int) ($ctx['adminId'] ?? 0), (string) ($staged['note'] ?? $note));

        return [
            'ok'   => true,
            'say'  => 'Cash recorded as collected on ' . $pnr . '.',
            'data' => ['pnr' => $pnr, 'bookingId' => (int) $detail['id'], 'total' => (float) $detail['total_amount'],
                       'changed' => (bool) ($res['changed'] ?? true)],
            'media' => null,
        ];
    }

    private static function officeReject(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_admin_write', false)) {
            return self::no('Office writes from WhatsApp are switched off — do it in Admin → Payments.');
        }
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return self::no('Only an office number may reject a booking.');
        }
        $pnr    = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $detail = $pnr !== '' ? BookingService::detail($pnr) : null;
        if ($detail === null) {
            return self::no('No booking with that PNR.');
        }
        if ((string) $detail['status'] !== 'pending') {
            return self::no('Booking ' . $pnr . ' is ' . strtoupper((string) $detail['status']) . ' — only a PENDING booking can be rejected. A confirmed one is cancelled with refund_quote + cancel_ticket.');
        }
        $reason = Security::clean((string) ($args['reason'] ?? ''), 200);
        if (mb_strlen($reason) < 3) {
            return self::no('Ask the office for the reason — the passenger reads it.');
        }

        if (($args['confirm'] ?? false) !== true) {
            self::stage($ctx, 'office', ['action' => 'reject', 'pnr' => $pnr, 'reason' => $reason]);
            return [
                'ok'   => true,
                'say'  => 'Nothing is rejected yet. Read the PNR, passenger, amount and the reason back and ask for ho; then call again with confirm true.',
                'data' => self::bookingCard($detail) + ['reason' => $reason],
                'media' => null,
            ];
        }
        $staged = self::takeStage($ctx, 'office');
        if ($staged === null || ($staged['action'] ?? '') !== 'reject' || ($staged['pnr'] ?? '') !== $pnr) {
            return self::no('Preview this exact PNR first (office_reject without confirm) and get a ho in the next message.');
        }
        BookingService::reject((int) $detail['id'], (int) ($ctx['adminId'] ?? 0), (string) ($staged['reason'] ?? $reason));

        return [
            'ok'   => true,
            'say'  => 'Rejected. The seats are released and the passenger has been told the reason.',
            'data' => ['pnr' => $pnr, 'bookingId' => (int) $detail['id']],
            'media' => null,
        ];
    }

    private static function officeAgentStatus(array $args, array $ctx): array
    {
        if (!Settings::getBool('wa_agent_admin_write', false)) {
            return self::no('Office writes from WhatsApp are switched off — do it in Admin → Staff.');
        }
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return self::no('Only an office number may change an account.');
        }
        $hits = self::findStaff((string) ($args['q'] ?? ''));
        if ($hits === []) {
            return self::no('No staff member matches that.');
        }
        if (count($hits) > 1) {
            $list = [];
            foreach ($hits as $h) {
                $list[] = ['name' => (string) $h['full_name'], 'code' => AgentWallet::agentCodeLabel((int) $h['id']), 'role' => (string) $h['role']];
            }
            return ['ok' => true, 'say' => 'Several match — ask which one before changing anything.', 'data' => ['matches' => $list], 'media' => null];
        }
        $a  = $hits[0];
        $id = (int) $a['id'];
        if ($id === (int) ($ctx['adminId'] ?? 0)) {
            return self::no('Nobody may switch off their own account from WhatsApp.');
        }
        if (in_array((string) $a['role'], ['superadmin', 'manager'], true)) {
            return self::no('Office accounts are changed in Admin → Staff, not from WhatsApp.');
        }
        $active = ($args['active'] ?? null) === true;
        if ((int) $a['is_active'] === ($active ? 1 : 0)) {
            return self::no((string) $a['full_name'] . ' is already ' . ($active ? 'active' : 'deactivated') . '.');
        }
        $reason = Security::clean((string) ($args['reason'] ?? ''), 200);

        if (($args['confirm'] ?? false) !== true) {
            self::stage($ctx, 'office', ['action' => 'agent_status', 'adminId' => $id, 'active' => $active, 'reason' => $reason]);
            return [
                'ok'   => true,
                'say'  => 'Nothing is changed yet. Say who and what will happen (' . ($active ? 'activate' : 'deactivate — they can no longer sell or sign in') . '), ask for ho, then call again with confirm true.',
                'data' => ['name' => (string) $a['full_name'], 'code' => AgentWallet::agentCodeLabel($id), 'role' => (string) $a['role'],
                           'activeNow' => (int) $a['is_active'] === 1, 'willBe' => $active],
                'media' => null,
            ];
        }
        $staged = self::takeStage($ctx, 'office');
        if ($staged === null || ($staged['action'] ?? '') !== 'agent_status' || (int) ($staged['adminId'] ?? 0) !== $id || (bool) ($staged['active'] ?? !$active) !== $active) {
            return self::no('Preview this exact change first (office_agent_status without confirm) and get a ho in the next message.');
        }
        Database::update('admins', ['is_active' => $active ? 1 : 0], 'id = :id', ['id' => $id]);
        if (!$active) {
            try {
                require_once INCLUDE_PATH . '/walogin.php';
                WaLogin::revokeAdmin($id, (int) ($ctx['adminId'] ?? 0));
            } catch (Throwable $ignored) {
            }
        }
        Logger::audit('staff.toggle', 'admin', (string) $a['username'], ['active' => (int) $a['is_active'] === 1], ['active' => $active],
            'staff ' . ($active ? 'activated' : 'deactivated') . ' from WhatsApp by admin #' . (int) ($ctx['adminId'] ?? 0)
            . ($staged['reason'] !== '' ? ': ' . $staged['reason'] : ''));

        return [
            'ok'   => true,
            'say'  => (string) $a['full_name'] . ' is now ' . ($active ? 'active' : 'deactivated') . '.',
            'data' => ['name' => (string) $a['full_name'], 'active' => $active],
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

    /**
     * Read the staged quote of this kind WITHOUT consuming it — so a refusal
     * for a wrong name or number can keep the quote for the corrected call.
     */
    private static function peekStage(array $ctx, string $kind): ?array
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

    /**
     * Take the staged quote of this kind, or null when there is none to use.
     *
     * 24 Sep 2026: CONSUMED on read. Until now the quote stayed parked after
     * the sale, so a seller's second "ho" in a later message — or a model
     * retry — could press staff_sell on the same quote twice; only the
     * customer path was saved from it, by QuickTicket's own repeat guard.
     * One quote, one sale.
     */
    private static function takeStage(array $ctx, string $kind): ?array
    {
        $payload = self::peekStage($ctx, $kind);
        if ($payload !== null) {
            self::clearStage((string) ($ctx['phone'] ?? ''));
        }
        return $payload;
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

    /** The audit row, for a caller outside this class (the bulk path). */
    public static function logCall(string $tool, array $args, array $ctx, bool $ok, string $detail, ?int $bookingId, float $t0): void
    {
        self::log($tool, $args, $ctx, $ok, $detail, $bookingId, $t0);
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
