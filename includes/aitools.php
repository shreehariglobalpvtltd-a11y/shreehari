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
        ];
        if ($digits === '') {
            return $out;
        }

        try {
            /* Staff numbers are stored as typed (with or without +91), so the
               match is on the normalised tail the whole app agrees on. */
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

    /**
     * The role of a WEBSITE / APP visitor, decided by their signed-in
     * SESSION alone (24 Sep 2026 — the assistant with hands, on the site).
     *
     * The same three roles as whoIs(), reached the other way round: on
     * WhatsApp the phone number proves who is writing, on the website the
     * password (staff) or the OTP (customer) already did. Nothing in the
     * message can change it — a guest who types "I am the admin" is still
     * a guest, with the guest's tools.
     *
     *   staff session, office role  -> admin  (the whole company)
     *   staff session, counter role -> staff  (their own book only,
     *                                          Auth::bookingScopeAdminId())
     *   customer session (OTP)      -> customer, scoped to their number
     *   nobody signed in            -> customer with NO number: may ask,
     *                                  may get a quote, can read no booking
     *
     * `stageKey` is the identity the quote-then-confirm staging and the
     * conversation memory hang off. On WhatsApp that is the phone number;
     * here a guest has none, so the PHP session id stands in for it (hashed,
     * never stored raw).
     *
     * @return array<string,mixed> the same shape whoIs() returns, plus
     *         channel, userId and stageKey
     */
    public static function whoIsWeb(): array
    {
        $out = [
            'role'         => 'customer',
            'admin'        => null,
            'adminId'      => 0,
            'scopeAdminId' => null,
            'name'         => '',
            'phone'        => '',
            'channel'      => 'web',
            'userId'       => 0,
            'stageKey'     => '',
        ];

        try {
            $admin = Auth::admin();
            if (is_array($admin) && (int) ($admin['id'] ?? 0) > 0 && (int) ($admin['is_active'] ?? 1) === 1) {
                $role = (string) ($admin['role'] ?? '');
                // Same door as WhatsApp: only these four roles hold the
                // assistant's staff / office powers. Support, scanner and the
                // like keep the customer tools plus their own sign-in name.
                if (in_array($role, ['agent', 'counter', 'manager', 'superadmin'], true)) {
                    $scoped = in_array($role, ['agent', 'counter'], true);
                    $out['admin']        = $admin;
                    $out['adminId']      = (int) $admin['id'];
                    $out['name']         = (string) ($admin['full_name'] ?? ($admin['username'] ?? ''));
                    $out['phone']        = normalisePhone((string) ($admin['phone'] ?? ''));
                    $out['role']         = $scoped ? 'staff' : 'admin';
                    $out['scopeAdminId'] = $scoped ? (int) $admin['id'] : null;
                    $out['stageKey']     = 'web-staff-' . (int) $admin['id'];
                    return $out;
                }
                $out['name'] = (string) ($admin['full_name'] ?? '');
            }

            $user = Auth::user();
            if (is_array($user) && (int) ($user['id'] ?? 0) > 0) {
                $out['userId'] = (int) $user['id'];
                $out['phone']  = normalisePhone((string) ($user['phone'] ?? ''));
                $out['name']   = (string) ($user['full_name'] ?? ($user['name'] ?? $out['name']));
                $out['stageKey'] = $out['phone'] !== '' ? 'web-' . $out['phone'] : 'web-user-' . (int) $user['id'];
                return $out;
            }
        } catch (Throwable $e) {
            Logger::exception($e, 'ai');
        }

        // A guest: keyed on the session so a quote asked in one message can
        // still be recognised in the next, and forgotten with the session.
        $sid = session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
        $out['stageKey'] = 'web-guest-' . substr(hash('sha256', $sid . '|' . (defined('APP_KEY') ? APP_KEY : '')), 0, 24);

        return $out;
    }

    /**
     * May this sender be SOLD a ticket by the assistant? Two independent
     * switches, one per channel, both shipped OFF: wa_agent_sell for the
     * WhatsApp number, ai_web_sell for the website and the app.
     */
    private static function maySell(array $ctx): bool
    {
        return ((string) ($ctx['channel'] ?? 'whatsapp')) === 'web'
            ? Settings::getBool('ai_web_sell', false)
            : Settings::getBool('wa_agent_sell', false);
    }

    /** The identity a staged quote / conversation hangs off (phone, or the web key). */
    private static function stageKey(array $ctx): string
    {
        $key = trim((string) ($ctx['stageKey'] ?? ''));
        return $key !== '' ? $key : (string) ($ctx['phone'] ?? '');
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
        $web     = ((string) ($ctx['channel'] ?? 'whatsapp')) === 'web';
        $mayCut  = self::maySell($ctx);
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
            'QUOTE a ticket without selling it: reads live availability and returns the exact bus, date, pickup, berth(s), seats left and total fare. ALWAYS call this before issue_ticket, and read the total back to the passenger so they can say ho/yes.',
            [
                'seats'     => ['integer', 'How many berths (1–6)'],
                'date'      => ['string', "Travel date as YYYY-MM-DD. Leave empty for the next catchable bus."],
                'direction' => ['string', "toNepal = Gujarat→Rupaidiha ('jane'), toIndia = Rupaidiha→Gujarat ('aaune'). Empty = decide from the pickup."],
                'boarding'  => ['string', 'Pickup town or stop as the passenger said it, e.g. Surat, Vadodara, Mehsana, S Hari Parking'],
                'gender'    => ['string', 'Male, Female or Other — needed for a shared cabin berth'],
            ], ['seats']);

        /* A guest on the website has no number to sell to: the quote still
           works, the sale is the booking screen's. Signed-in customers and
           staff sell through the same switch-gated tools as WhatsApp. */
        if ($mayCut && !($web && $role === 'customer' && (string) ($ctx['phone'] ?? '') === '')) {
            $t[] = self::spec('issue_ticket',
                'ISSUE the ticket that plan_ticket just quoted, after the passenger has clearly said yes (ho / hunxa / ok / thik cha / book it). The ticket PNG goes to their WhatsApp by itself. Only call this when the passenger agreed to the total you read out. '
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
                . 'For a GROUP — 4, 5, a whole family on one chalan — quote the seat count with plan_ticket, then pass every traveller in names[] here. One booking, one PNR, every berth printed with its own name. Ask for the names in ONE message, not one at a time.',
                [
                    'name'    => ['string', "The lead passenger's full name — the booking is in this name"],
                    'names'   => ['array',  'Every traveller in the party, in the order given. Same count as the berths quoted.',
                                   ['type' => 'object', 'properties' => [
                                       'name'   => ['type' => 'string', 'description' => "The traveller's full name"],
                                       'gender' => ['type' => 'string', 'description' => 'Male, Female or Other'],
                                   ], 'required' => ['name']]],
                    'phone'   => ['string', "The passenger's 10-digit mobile — the ticket goes there"],
                    'gender'  => ['string', 'Male, Female or Other — the lead passenger\'s own'],
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

        /* 24 Sep 2026 (owner: "AI le sabai kura ko answer deos — reputation
           ko pani"). Every role may leave a rating or a complaint; it lands
           in the same Ratings / Enquiries screens the office already reads. */
        $t[] = self::spec('record_feedback',
            'RECORD a rating, a compliment or a complaint about the journey or the company so the office sees it. Use when the person says they want to rate, review, praise or complain — after you have asked for a 1–5 star rating and their words. A low rating (1–2) is also filed as a complaint for the office to call back. Never invent a rating.',
            [
                'rating'  => ['integer', '1 (worst) to 5 (best)'],
                'comment' => ['string', "The person's own words, short"],
                'pnr'     => ['string', 'The PNR the feedback is about, if they gave one'],
                'name'    => ['string', 'Their name, if known'],
                'phone'   => ['string', 'A callback number — staff only, for a passenger they are speaking for'],
            ], ['rating']);

        if ($staff) {
            $t[] = self::spec('agent_day',
                "One seller's own day: tickets sold, seats, money by method, commission earned and wallet balance. Defaults to the staff member who is writing, and to today.",
                ['date' => ['string', 'YYYY-MM-DD, default today']]);

            $t[] = self::spec('agent_passengers',
                'The passengers travelling on a date, grouped by pickup, with seat and phone — a counter agent sees only the ones they sold themselves.',
                ['date' => ['string', 'YYYY-MM-DD, default today']]);

            /* 24 Sep 2026 — REPORTS AND GRAPHS (owner: "report, graph, real
               time data, kati ticket bikri bhayo"). Read-only, drawn from
               the same tables as Admin → Analytics, normalised to INR the
               same way. Every report returns a `chart` block the website
               draws live (Chart.js) and WhatsApp receives as a PNG. A
               counter agent's report is always their own sales only. */
            $t[] = self::spec('sales_report',
                'SALES REPORT with a chart: tickets sold, seats, revenue (₹), refunds and cancellations for a period — today, yesterday, this week, this month, the last 7 or 30 days, or a custom range — broken down by day, route, payment method, pickup, cabin mode or (office only) selling agent. Use for "kati bikri bhayo", "yo hapta ko report", "graph dekhau", "which route earns most", comparisons and trends. A counter agent gets their own sales only.',
                [
                    'period'   => ['string', 'today | yesterday | week | month | last7 | last30 | custom (then give from/to)'],
                    'from'     => ['string', 'YYYY-MM-DD, with period=custom'],
                    'to'       => ['string', 'YYYY-MM-DD, with period=custom'],
                    'group_by' => ['string', 'day (default) | route | method | pickup | mode | agent'],
                ]);

            $t[] = self::spec('occupancy_report',
                'HOW FULL each departure is for the coming days: seats sold against capacity per bus, with a chart. Use for "bholi ko bus kati bhariyo", "kun din seat khali cha", "occupancy", planning an extra bus.',
                ['days' => ['integer', 'How many days ahead, 1–14 (default 7)']]);
        }

        if ($role === 'admin') {
            $t[] = self::spec('site_visitors',
                'WEBSITE AND APP TRAFFIC, live: how many people are on the site right now, visits and page views per day, searches, empty searches, checkout drop-offs, quick-ticket opens, app installs, and tickets per 100 visits — with a chart. Use for "kati customer le visit gare", "aaja website ma kati manche", conversion, "kaha bata manche harauchan".',
                ['days' => ['integer', 'How many days back, 1–90 (default 7)']]);

            $t[] = self::spec('agent_leaderboard',
                'TOP SELLING AGENTS for a period: tickets, seats and revenue per agent, ranked, with a chart. Office only.',
                ['period' => ['string', 'today | yesterday | week | month | last7 | last30 (default month)']]);
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
                'office_day'       => self::officeDay($args, $ctx),
                'office_search'    => self::officeSearch($args, $ctx),
                'office_alerts'    => self::officeAlerts($args, $ctx),
                'office_confirm'   => self::officeConfirm($args, $ctx),
                'record_feedback'  => self::recordFeedback($args, $ctx),
                'sales_report'     => self::salesReport($args, $ctx),
                'occupancy_report' => self::occupancyReport($args, $ctx),
                'site_visitors'    => self::siteVisitors($args, $ctx),
                'agent_leaderboard' => self::salesReport(['period' => (string) ($args['period'] ?? 'month'), 'group_by' => 'agent'], $ctx),
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
        $web = ((string) ($ctx['channel'] ?? 'whatsapp')) === 'web';
        if (!self::maySell($ctx)) {
            return self::no($web
                ? 'Selling from the website chat is switched off. Send them to the booking screen (#/) with the date, pickup and seats you quoted, or give the office number.'
                : 'Selling on WhatsApp is switched off. Say the desk will confirm the booking and give the office number.');
        }
        if ($web && (string) ($ctx['phone'] ?? '') === '') {
            return self::no('This visitor is not signed in, so there is no mobile number to put the ticket on. Ask them to sign in at #/my with their mobile number, or book at #/.');
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

        $seatCount = (int) ($staged['opts']['seats'] ?? 1);
        $party     = self::partyNames($args['names'] ?? null, $seatCount);

        $input = [
            'name'      => $name,
            'phone'     => (string) $ctx['phone'],     // always the sender's own number
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
        if (!self::maySell($ctx)) {
            return self::no(((string) ($ctx['channel'] ?? 'whatsapp')) === 'web'
                ? 'Selling from the chat is switched off for this company — use ⚡ Quick Ticket in the panel.'
                : 'Selling on WhatsApp is switched off for this company.');
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
                ['s' => 'wa_ticket_fix', 'k' => self::stageKey($ctx)]);
            Database::insert('kv_store', [
                'kscope' => 'wa_ticket_fix', 'kkey' => self::stageKey($ctx),
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
                ['s' => 'wa_ticket_fix', 'k' => self::stageKey($ctx)]
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
                ['s' => 'wa_ticket_fix', 'k' => self::stageKey($ctx)]);
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

    /* =================================================================
     *  Tools — reputation, reports and graphs (24 Sep 2026)
     *
     *  Owner ask: "image, report, graph, real-time data — kati customer
     *  le visit gare, kati ticket bikri bhayo — teen wotai role, WhatsApp
     *  ra app dubai bata; company reputation ma dhyan."
     *
     *  Everything below READS. The figures come from the same tables and
     *  the same INR peg as Admin → Analytics, so a number the assistant
     *  says and a number on the Analytics screen never disagree. Each
     *  report carries a `chart` block: {type, title, labels, series[],
     *  format}. The website draws it live; WhatsApp gets it as a PNG
     *  (includes/aichart.php). A counter agent's report is scoped to
     *  sold_by_admin_id exactly as every admin page is.
     * ================================================================= */

    /**
     * A rating, a compliment or a complaint — into the `feedback` table the
     * Ratings screen reads, and (when it is a complaint) into the Enquiries
     * inbox the office watches all day. The assistant never grades anyone
     * itself: a rating is what the person said.
     */
    private static function recordFeedback(array $args, array $ctx): array
    {
        $rating = (int) ($args['rating'] ?? 0);
        if ($rating < 1 || $rating > 5) {
            return self::no('Ask for a rating from 1 to 5 first.');
        }
        $comment = Security::clean((string) ($args['comment'] ?? ''), 1000);
        $name    = Security::clean((string) ($args['name'] ?? ($ctx['name'] ?? '')), 120);
        $pnr     = strtoupper(trim((string) ($args['pnr'] ?? '')));
        $role    = (string) ($ctx['role'] ?? 'customer');
        $staff   = $role === 'staff' || $role === 'admin';

        $phone = (string) ($ctx['phone'] ?? '');
        if ($staff) {
            $given = normalisePhone((string) ($args['phone'] ?? ''));
            if ($given !== '') {
                $phone = $given;
            }
        }

        $bookingId = null;
        if ($pnr !== '' && Security::isValidPnr($pnr)) {
            $detail = BookingService::detail($pnr);
            if ($detail !== null && self::mayRead($detail, $ctx)) {
                $bookingId = (int) $detail['id'];
                if ($phone === '') {
                    $phone = normalisePhone((string) ($detail['contact_phone'] ?? ''));
                }
                if ($name === '') {
                    $name = (string) ($detail['passengers'][0]['full_name'] ?? '');
                }
            }
        }

        $id = (int) Database::insert('feedback', [
            'booking_id' => $bookingId,
            'user_phone' => $phone !== '' ? $phone : null,
            'name'       => $name !== '' ? $name : null,
            'rating'     => $rating,
            'comment'    => $comment !== '' ? mb_substr($comment, 0, 1000) : null,
            'is_public'  => 0,
        ]);

        // A poor rating is a complaint the office must call back on, so it
        // also lands in the Enquiries inbox — one queue, not a second one.
        $complaintRef = '';
        if ($rating <= 2 && $phone !== '' && Security::isValidPhone($phone)) {
            try {
                $complaintRef = 'SHG-C-' . strtoupper(base_convert((string) time(), 10, 36));
                Database::insert('enquiries', [
                    'name'   => $name !== '' ? $name : 'Sahayak guest',
                    'phone'  => $phone,
                    'note'   => mb_substr('[' . $complaintRef . '] rating ' . $rating . '/5'
                                . ($pnr !== '' ? ' · ' . $pnr : '') . ' · ' . $comment, 0, 250),
                    'source' => 'complaint',
                    'status' => 'new',
                ]);
            } catch (Throwable $e) {
                Logger::exception($e, 'ai');           // the rating itself is saved
                $complaintRef = '';
            }
        }

        return [
            'ok'   => true,
            'say'  => 'Saved. Thank them warmly in their own language. '
                    . ($rating <= 2
                        ? 'This is a complaint: apologise in one line, say the office will call back' . ($complaintRef !== '' ? ' (reference ' . $complaintRef . ')' : '') . ', and give the office number.'
                        : ($rating >= 4 ? 'Invite them, in one line, to share the experience with family or on our Facebook page — only if they seem happy.' : 'Ask, in one line, what would have made it a 5.')),
            'data' => ['feedbackId' => $id, 'rating' => $rating, 'pnr' => $pnr, 'complaintRef' => $complaintRef, 'bookingId' => $bookingId],
            'media' => null,
        ];
    }

    /**
     * Resolve "today / week / last30 / custom" into an inclusive date pair.
     *
     * @return array{0: string, 1: string, 2: string} from, to, label
     */
    private static function periodRange(string $period, string $from = '', string $to = ''): array
    {
        $today = todayISO();
        $period = strtolower(trim($period));
        if ($period === 'custom' || ($period === '' && ($from !== '' || $to !== ''))) {
            $f = self::cleanDate($from) ?: addDaysISO($today, -6);
            $t = self::cleanDate($to) ?: $today;
            if ($f > $t) {
                [$f, $t] = [$t, $f];
            }
            // Never more than a year in one report — a chart with 400 bars says nothing.
            if (strtotime($t) - strtotime($f) > 366 * 86400) {
                $f = addDaysISO($t, -365);
            }
            return [$f, $t, $f . ' to ' . $t];
        }
        return match ($period) {
            'yesterday' => [addDaysISO($today, -1), addDaysISO($today, -1), 'yesterday'],
            'week', 'this_week'  => [date('Y-m-d', strtotime('monday this week')), $today, 'this week'],
            'month', 'this_month' => [date('Y-m-01'), $today, 'this month'],
            'last7', '7d', 'last_7_days', 'last 7 days'   => [addDaysISO($today, -6), $today, 'last 7 days'],
            'last30', '30d', 'last_30_days', 'last 30 days' => [addDaysISO($today, -29), $today, 'last 30 days'],
            default     => [$today, $today, 'today'],
        };
    }

    /** SQL: a booking's total in INR whatever currency it was sold in. */
    private static function revInrSql(string $alias = 'b'): string
    {
        $peg = defined('NPR_PER_INR') ? (float) NPR_PER_INR : 1.6;
        return "SUM(CASE WHEN {$alias}.currency = 'NPR' THEN {$alias}.total_amount / {$peg} ELSE {$alias}.total_amount END)";
    }

    private static function salesReport(array $args, array $ctx): array
    {
        $role  = (string) ($ctx['role'] ?? 'customer');
        if ($role !== 'staff' && $role !== 'admin') {
            return self::no('Only staff and the office may ask for a sales report.');
        }
        $scope = $ctx['scopeAdminId'] ?? null;             // a counter agent: own sales only
        $group = strtolower(trim((string) ($args['group_by'] ?? 'day')));
        if (!in_array($group, ['day', 'route', 'method', 'pickup', 'mode', 'agent'], true)) {
            $group = 'day';
        }
        if ($group === 'agent' && $role !== 'admin') {
            return self::no('A per-agent breakdown is for the office. This seller may see their own figures only — ask for group_by day.');
        }

        [$from, $to, $label] = self::periodRange((string) ($args['period'] ?? 'today'),
            (string) ($args['from'] ?? ''), (string) ($args['to'] ?? ''));
        $p = ['d0' => $from . ' 00:00:00', 'd1' => $to . ' 23:59:59'];
        $scopeSql = '';
        if ($scope !== null) {
            $scopeSql = ' AND b.sold_by_admin_id = :scope';
            $p['scope'] = (int) $scope;
        }
        $paid = "b.status IN ('confirmed','completed')";
        $soldAt = 'COALESCE(b.confirmed_at, b.created_at)';

        /* ---- the totals ------------------------------------------------ */
        $tot = Database::fetch(
            "SELECT COUNT(*) AS tickets, COALESCE(" . self::revInrSql() . ",0) AS revenue,
                    COALESCE(SUM(b.refund_amount),0) AS refunds
               FROM bookings b
              WHERE {$paid} AND {$soldAt} BETWEEN :d0 AND :d1{$scopeSql}",
            $p
        ) ?? [];
        $seats = (int) Database::scalar(
            "SELECT COUNT(*) FROM booking_seats s JOIN bookings b ON b.id = s.booking_id
              WHERE {$paid} AND s.released_at IS NULL AND {$soldAt} BETWEEN :d0 AND :d1{$scopeSql}",
            $p, 0
        );
        $cancelled = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings b WHERE b.status = 'cancelled' AND b.cancelled_at BETWEEN :d0 AND :d1{$scopeSql}",
            $p, 0
        );
        $pending = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings b WHERE b.status = 'pending' AND b.created_at BETWEEN :d0 AND :d1{$scopeSql}",
            $p, 0
        );
        $refunded = (float) Database::scalar(
            "SELECT COALESCE(SUM(b.refund_amount),0) FROM bookings b
              WHERE b.status = 'cancelled' AND b.cancelled_at BETWEEN :d0 AND :d1{$scopeSql}",
            $p, 0
        );

        /* ---- the breakdown ---------------------------------------------- */
        $rev = self::revInrSql();
        [$keySql, $joinSql, $keyLabel] = match ($group) {
            'route'  => ["CONCAT(r.from_city, ' → ', r.to_city)",
                         " JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
                           JOIN schedules s ON s.id = bl.schedule_id JOIN routes r ON r.id = s.route_id", 'Route'],
            'method' => ["COALESCE(NULLIF((SELECT p2.method FROM payments p2 WHERE p2.booking_id = b.id ORDER BY p2.id DESC LIMIT 1),''),'unknown')", '', 'Payment method'],
            'pickup' => ["COALESCE(NULLIF(bl.boarding_stop,''),'—')",
                         " LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'", 'Pickup'],
            'mode'   => ["COALESCE(NULLIF(b.booking_mode,''),'seater')", '', 'Cabin'],
            'agent'  => ["COALESCE(NULLIF(a.full_name,''), CASE WHEN b.sold_by_admin_id IS NULL THEN 'Online / office' ELSE CONCAT('Staff #', b.sold_by_admin_id) END)",
                         ' LEFT JOIN admins a ON a.id = b.sold_by_admin_id', 'Agent'],
            default  => ["DATE({$soldAt})", '', 'Day'],
        };
        $rows = Database::fetchAll(
            "SELECT {$keySql} AS k, COUNT(DISTINCT b.id) AS tickets, COALESCE({$rev},0) AS revenue
               FROM bookings b{$joinSql}
              WHERE {$paid} AND {$soldAt} BETWEEN :d0 AND :d1{$scopeSql}
              GROUP BY k ORDER BY " . ($group === 'day' ? 'k' : 'revenue DESC') . ' LIMIT 40',
            $p
        );

        $labels = [];
        $tickets = [];
        $revenue = [];
        $list = [];
        if ($group === 'day') {
            // Every day in the range, zero-filled, so a quiet day is a gap the eye can see.
            $byDay = [];
            foreach ($rows as $r) {
                $byDay[(string) $r['k']] = $r;
            }
            for ($d = $from; $d <= $to; $d = addDaysISO($d, 1)) {
                $r = $byDay[$d] ?? ['tickets' => 0, 'revenue' => 0];
                $labels[]  = date('d M', strtotime($d));
                $tickets[] = (int) $r['tickets'];
                $revenue[] = round((float) $r['revenue']);
                $list[] = ['day' => $d, 'tickets' => (int) $r['tickets'], 'revenue' => round((float) $r['revenue'])];
                if (count($labels) >= 366) { break; }
            }
        } else {
            foreach ($rows as $r) {
                $labels[]  = (string) $r['k'];
                $tickets[] = (int) $r['tickets'];
                $revenue[] = round((float) $r['revenue']);
                $list[] = [strtolower($keyLabel) => (string) $r['k'], 'tickets' => (int) $r['tickets'], 'revenue' => round((float) $r['revenue'])];
            }
        }

        $revenueTotal = (float) ($tot['revenue'] ?? 0);
        $ticketsTotal = (int) ($tot['tickets'] ?? 0);
        $who = $scope !== null ? (string) ($ctx['name'] ?? 'this seller') . "'s own" : 'the company\'s';

        $chart = [
            'type'   => $group === 'day' && count($labels) > 12 ? 'line' : 'bar',
            'title'  => 'Sales · ' . $label . ($scope !== null ? ' · ' . (string) ($ctx['name'] ?? '') : '') . ' · by ' . strtolower($keyLabel),
            'labels' => $labels,
            'series' => [
                ['name' => 'Revenue ₹', 'data' => $revenue, 'format' => 'money'],
                ['name' => 'Tickets',   'data' => $tickets, 'format' => 'count', 'axis' => 'y2'],
            ],
            'format' => 'money',
        ];

        return [
            'ok'   => true,
            'say'  => 'These are ' . $who . ' live sales figures for ' . $label . ' (INR, NPR converted at the company peg). '
                    . 'Give the totals first in one line, then the two or three biggest items, then one sentence of meaning. '
                    . 'A chart is attached — say so, do not read every bar aloud.',
            'data' => [
                'period'        => $label,
                'from'          => $from,
                'to'            => $to,
                'scope'         => $scope !== null ? 'own sales only' : 'whole company',
                'tickets'       => $ticketsTotal,
                'seats'         => $seats,
                'revenue'       => round($revenueTotal),
                'revenueLabel'  => inr($revenueTotal),
                'avgPerTicket'  => $ticketsTotal > 0 ? round($revenueTotal / $ticketsTotal) : 0,
                'cancelled'     => $cancelled,
                'refunded'      => round($refunded),
                'pendingPayment' => $pending,
                'groupBy'       => strtolower($keyLabel),
                'rows'          => array_slice($list, 0, 40),
                'chart'         => $chart,
            ],
            'media' => null,
        ];
    }

    private static function occupancyReport(array $args, array $ctx): array
    {
        $role = (string) ($ctx['role'] ?? 'customer');
        if ($role !== 'staff' && $role !== 'admin') {
            return self::no('Only staff and the office may ask for occupancy.');
        }
        $days = max(1, min(14, (int) ($args['days'] ?? 7)));
        $from = todayISO();
        $to   = addDaysISO($from, $days - 1);

        $deps = Database::fetchAll(
            "SELECT s.id, s.route_id, s.travel_date, s.status, s.is_blocked, r.from_city, r.to_city,
                    COALESCE(s.dep_time_override, r.dep_time) AS dep_time,
                    COALESCE(bus.total_seats, rb.total_seats, 0) AS bus_seats,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS sold
               FROM schedules s
               JOIN routes r ON r.id = s.route_id
               LEFT JOIN buses bus ON bus.id = s.bus_id
               LEFT JOIN buses rb  ON rb.id  = r.bus_id
              WHERE s.travel_date BETWEEN :d0 AND :d1
              ORDER BY s.travel_date, dep_time",
            ['d0' => $from, 'd1' => $to]
        );

        /* Schedules are materialised on first demand (Seats::schedule), so
           a day nobody has searched yet has no row — but the daily bus still
           runs. Fill those days from the active routes, WITHOUT creating
           rows: a report must never leave a footprint in the register. */
        $have = [];
        foreach ($deps as $d) {
            $have[(int) $d['route_id'] . '|' . (string) $d['travel_date']] = true;
        }
        try {
            $routes = Database::fetchAll(
                'SELECT r.id, r.from_city, r.to_city, r.dep_time, r.coach_type, COALESCE(rb.total_seats, 0) AS bus_seats
                   FROM routes r LEFT JOIN buses rb ON rb.id = r.bus_id
                  WHERE r.is_active = 1 ORDER BY r.sort_order, r.dep_time'
            );
            for ($d = $from; $d <= $to; $d = addDaysISO($d, 1)) {
                foreach ($routes as $r) {
                    if (isset($have[(int) $r['id'] . '|' . $d])) {
                        continue;
                    }
                    $cap = 0;
                    try {
                        $cap = count(Seats::seatIds((string) $r['coach_type'], 'sharing'));
                    } catch (Throwable $e) {
                        $cap = (int) $r['bus_seats'];
                    }
                    $deps[] = [
                        'id' => 0, 'route_id' => (int) $r['id'], 'travel_date' => $d, 'status' => 'scheduled', 'is_blocked' => 0,
                        'from_city' => (string) $r['from_city'], 'to_city' => (string) $r['to_city'],
                        'dep_time' => (string) $r['dep_time'], 'bus_seats' => $cap, 'sold' => 0,
                    ];
                }
            }
            usort($deps, static fn(array $a, array $b): int =>
                [(string) $a['travel_date'], (string) $a['dep_time']] <=> [(string) $b['travel_date'], (string) $b['dep_time']]);
        } catch (Throwable $e) {
            // the materialised rows alone still make an honest report
        }

        $labels = [];
        $pct    = [];
        $rows   = [];
        foreach ($deps as $d) {
            $cap = 0;
            try {
                $cap = (int) $d['id'] > 0 ? count(Seats::seatIdsForSchedule((int) $d['id'])) : 0;
            } catch (Throwable $e) {
                $cap = 0;
            }
            if ($cap <= 0) {
                $cap = (int) $d['bus_seats'];
            }
            $sold = (int) $d['sold'];
            $fill = $cap > 0 ? (int) round($sold * 100 / $cap) : null;
            $day  = formatDate((string) $d['travel_date'], 'D j M');
            $rows[] = [
                'date'     => (string) $d['travel_date'],
                'dateLabel' => $day,
                'route'    => (string) $d['from_city'] . ' → ' . (string) $d['to_city'],
                'depTime'  => substr((string) $d['dep_time'], 0, 5),
                'sold'     => $sold,
                'capacity' => $cap,
                'free'     => max(0, $cap - $sold),
                'fillPct'  => $fill,
                'status'   => (string) ($d['status'] ?? ''),
                'blocked'  => (int) ($d['is_blocked'] ?? 0) === 1,
            ];
            // The chart shows the first four weeks of bars; the rows carry
            // every departure in the window (at most 14 days x the routes).
            if (count($labels) < 28) {
                $labels[] = $day . ' ' . substr((string) $d['dep_time'], 0, 5);
                $pct[]    = $fill ?? 0;
            }
            if (count($rows) >= 80) { break; }
        }

        $chart = [
            'type'   => 'bar',
            'title'  => 'Bus occupancy · next ' . $days . ' day' . ($days > 1 ? 's' : '') . ' (% of seats sold)',
            'labels' => $labels,
            'series' => [['name' => 'Sold %', 'data' => $pct, 'format' => 'percent']],
            'format' => 'percent',
            'max'    => 100,
        ];

        return [
            'ok'   => true,
            'say'  => $rows === []
                ? 'No departures are scheduled in that window.'
                : 'Seats sold against capacity per departure, live. Name the fullest and the emptiest bus, and any bus over 85% as "almost full". A chart is attached.',
            'data' => ['from' => $from, 'to' => $to, 'departures' => $rows, 'chart' => $chart],
            'media' => null,
        ];
    }

    /**
     * The product beacon (api/events.php) read back as traffic. It stores
     * behaviour, never people: a visit is a rotating per-visit key, so
     * "visitors" here means visits, and nothing here can be joined to a
     * phone number or a name.
     */
    private static function siteVisitors(array $args, array $ctx): array
    {
        if ((string) ($ctx['role'] ?? '') !== 'admin') {
            return self::no('Website traffic is for the office only.');
        }
        $days = max(1, min(90, (int) ($args['days'] ?? 7)));
        $from = addDaysISO(todayISO(), -($days - 1));

        try {
            $now = (int) Database::scalar(
                'SELECT COUNT(DISTINCT session_key) FROM app_events WHERE created_at >= (NOW() - INTERVAL 5 MINUTE)', [], 0
            );
            $rows = Database::fetchAll(
                "SELECT DATE(created_at) AS d,
                        COUNT(DISTINCT session_key)                        AS visits,
                        SUM(name = 'view')                                 AS views,
                        SUM(name = 'search')                               AS searches,
                        SUM(name = 'search_empty')                         AS empty_searches,
                        SUM(name = 'seat_open')                            AS seat_opens,
                        SUM(name = 'checkout_drop')                        AS checkout_drops,
                        SUM(name = 'quick_ticket_open')                    AS quick_ticket_opens,
                        SUM(name = 'install_prompt')                       AS installs,
                        SUM(name = 'offline_hit')                          AS offline_hits,
                        SUM(name = 'error_boundary')                       AS errors
                   FROM app_events
                  WHERE created_at >= :d0
                  GROUP BY DATE(created_at) ORDER BY d",
                ['d0' => $from . ' 00:00:00']
            );
        } catch (Throwable $e) {
            return self::no('Visitor tracking is not installed on this server yet (the app_events table is missing).');
        }

        $byDay = [];
        foreach ($rows as $r) {
            $byDay[(string) $r['d']] = $r;
        }
        $labels = [];
        $visits = [];
        $views  = [];
        $sum = ['visits' => 0, 'views' => 0, 'searches' => 0, 'empty_searches' => 0, 'seat_opens' => 0,
                'checkout_drops' => 0, 'quick_ticket_opens' => 0, 'installs' => 0, 'offline_hits' => 0, 'errors' => 0];
        $today = todayISO();
        for ($d = $from; $d <= $today; $d = addDaysISO($d, 1)) {
            $r = $byDay[$d] ?? [];
            $labels[] = date('d M', strtotime($d));
            $visits[] = (int) ($r['visits'] ?? 0);
            $views[]  = (int) ($r['views'] ?? 0);
            foreach ($sum as $k => $_) {
                $sum[$k] += (int) ($r[$k] ?? 0);
            }
        }

        // What people looked at most, from the redacted props of view events.
        $topViews = [];
        try {
            foreach (Database::fetchAll(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(props, '$.view')) AS v, COUNT(*) AS n
                   FROM app_events
                  WHERE name = 'view' AND created_at >= :d0 AND props IS NOT NULL
                  GROUP BY v ORDER BY n DESC LIMIT 8",
                ['d0' => $from . ' 00:00:00']
            ) as $r) {
                if ((string) ($r['v'] ?? '') !== '' && (string) $r['v'] !== 'null') {
                    $topViews[] = ['view' => (string) $r['v'], 'views' => (int) $r['n']];
                }
            }
        } catch (Throwable $e) {
            $topViews = [];                       // an older MySQL without JSON functions
        }

        // Conversion: tickets that were actually sold in the same window.
        $tickets = (int) Database::scalar(
            "SELECT COUNT(*) FROM bookings WHERE status IN ('confirmed','completed') AND created_at >= :d0",
            ['d0' => $from . ' 00:00:00'], 0
        );
        $conversion = $sum['visits'] > 0 ? round($tickets * 100 / $sum['visits'], 1) : null;

        $chart = [
            'type'   => $days > 12 ? 'line' : 'bar',
            'title'  => 'Website & app visits · last ' . $days . ' day' . ($days > 1 ? 's' : ''),
            'labels' => $labels,
            'series' => [
                ['name' => 'Visits',     'data' => $visits, 'format' => 'count'],
                ['name' => 'Page views', 'data' => $views,  'format' => 'count'],
            ],
            'format' => 'count',
        ];

        return [
            'ok'   => true,
            'say'  => 'Live traffic from the product beacon (visits are anonymous per-visit keys, never people). '
                    . 'Say how many are on the site RIGHT NOW, the visits for the period, and the one funnel fact that matters '
                    . '(empty searches or checkout drop-offs). A chart is attached.',
            'data' => [
                'onSiteNow'      => $now,
                'days'           => $days,
                'from'           => $from,
                'to'             => $today,
                'totals'         => $sum + ['ticketsSold' => $tickets],
                'ticketsPer100Visits' => $conversion,
                'topViews'       => $topViews,
                'chart'          => $chart,
            ],
            'media' => null,
        ];
    }

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
                'kscope = :s AND kkey = :k', ['s' => 'wa_stage', 'k' => self::stageKey($ctx)]);
            if ($done === 0) {
                Database::insertIgnore('kv_store', [
                    'kscope' => 'wa_stage', 'kkey' => self::stageKey($ctx),
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
                ['s' => 'wa_stage', 'k' => self::stageKey($ctx)]
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
            // A website visitor may have no number; the log then carries the
            // (hashed) session key so a burst from one guest is still visible.
            $who = (string) ($ctx['phone'] ?? '');
            if ($who === '') {
                $who = mb_substr(self::stageKey($ctx), 0, 20);
            }
            Database::insert('ai_agent_calls', [
                'created_at' => date('Y-m-d H:i:s'),
                'phone'      => $who,
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
