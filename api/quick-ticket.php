<?php
/**
 * POST /api/quick-ticket.php — ⚡ Quick Ticket (6 Sep 2026) for everyone.
 *
 * ONE engine (includes/quickticket.php → BookingService::create), THREE
 * doors, told apart by the SESSION never the payload:
 *
 *   STAFF (Auth::isSellingStaff — admin / counter / agent)
 *   { action: "plan", direction?, date?, boarding?, seats?, gender? }
 *       → what the desk would sell RIGHT NOW (read-only).
 *   { action: "sell", name, phone, country?, gender?, seats?, direction?,
 *     date?, boarding?, pay?, discountType?, discountValue?, note?, bot? }
 *       → a CONFIRMED counter / agent sale, ticket issued, PNG rendered,
 *         WhatsApp attempted. `bot` = the prefill the AI Ticket Bot
 *         proposed, so the outcome loop can score it.
 *   { action: "bot", text?, name?, phone?, seats?, date?, direction?,
 *     boarding?, gender?, pay? }
 *       → the AI Ticket Bot's proposal (includes/ticketbot.php): passenger
 *         memory + learned patterns merged under the explicit fields, then
 *         the live plan for it. Read-only.
 *
 *   CUSTOMER (guest or signed-in passenger — no staff session)
 *   { action: "customer_plan", direction?, date?, boarding?, seats?, gender? }
 *       → the next catchable bus / pickup / seat / fare under the PUBLIC
 *         caps (read-only): the one-click card. seats <= 0 = "not chosen".
 *         Default pickup = quick_ticket_customer_boarding (S Hari Parking).
 *         A SIGNED-IN passenger's own verified history fills the blanks
 *         (usual pickup / direction / party) — looked up by the session's
 *         number only; the response's `personal` says what was applied.
 *   { action: "customer_sell", name, phone, country?, gender?, seats?,
 *     direction?, date?, boarding?, expect?, bot? }
 *       → the passenger's own booking through the same create() the
 *         checkout uses: CONFIRMED + ticket (PNG & PDF) when pay-at-counter
 *         is allowed, else PENDING with the payment targets. `expect` =
 *         {date, direction, boardingCode, seats, total} the card showed —
 *         a drifted plan is refused 409 fields{code:'plan_changed', plan}.
 *         `bot` = what the card pre-filled, scored by the outcome loop.
 *         The sale signs the passenger in (response `user`); `customer` =
 *         the /api/track.php payload so the app opens its ticket screen;
 *         `undoMin` = the free-undo window.
 *   { action: "customer_undo", pnr }
 *       → the owning session cancels a pay-on-boarding one-click ticket
 *         free inside quick_ticket_undo_min (QuickTicket::customerUndo).
 *
 * A staff session asking a customer_* action is refused: a desk sells
 * through "sell" so the sale is attributed and paid. A guest asking a
 * staff action is refused. "Not you?" in the app ends the passenger
 * session through api/otp.php {action:'logout'}.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/quickticket.php';
require_once INCLUDE_PATH . '/ticketbot.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $in     = Response::input();
    $action = (string) ($in['action'] ?? 'plan');
    $staff  = Auth::admin();

    $opts = [
        'direction' => Security::clean($in['direction'] ?? '', 10),
        'date'      => Security::clean($in['date'] ?? '', 10),
        'boarding'  => Security::clean($in['boarding'] ?? '', 191),
        'seats'     => (int) ($in['seats'] ?? 1),
        'gender'    => Security::clean($in['gender'] ?? '', 10),
        // "Same as last time" berths (SHG AI BRAIN Phase 2) — a hint the plan honours only when free.
        'prefer'    => array_values(array_filter(
            array_map(static fn($s): string => is_scalar($s) ? strtoupper(Security::clean((string) $s, 6)) : '', array_slice((array) ($in['prefer'] ?? []), 0, 6)),
            static fn(string $s): bool => $s !== '' && Security::isValidSeat($s)
        )),
    ];

    /* ------------------------------------------------------------------
     *  Customer door
     * ---------------------------------------------------------------- */
    if ($action === 'customer_plan' || $action === 'customer_sell' || $action === 'customer_undo') {
        if ($staff !== null && Auth::isSellingStaff()) {
            Response::forbidden('Staff sell through the Quick Ticket desk so the sale is attributed. / Staff ले desk बाट बेच्नुहोस्।');
        }
        if (!Settings::getBool('quick_ticket_customer_on', true)) {
            Response::error('Quick Ticket is available at our desks only right now. / Quick Ticket अहिले desk मा मात्र छ।', 503);
        }
        /* Public buckets, per IP. Every home view now plans itself and each
           chip re-plans, and many passengers share one carrier NAT address,
           so the plan bucket is generous; a sale or an undo is rare and
           bounded (a busy family phone still gets a dozen in five minutes). */
        Security::requireRateLimit('quick_ticket_plan', Security::clientIp(), 240, 300);
        if ($action !== 'customer_plan') {
            Security::requireRateLimit('quick_ticket_public', Security::clientIp(), 12, 300);
        }

        if ($action === 'customer_undo') {
            /* Free undo of a one-click ticket by the session that owns the
               number, inside quick_ticket_undo_min — see QuickTicket::customerUndo. */
            $pnr = Security::clean($in['pnr'] ?? '', 40);
            if (!Security::isValidPnr($pnr)) {
                Response::invalid(['pnr' => 'Enter a valid booking reference.']);
            }
            Response::success(QuickTicket::customerUndo($pnr, Auth::user()), 'Ticket undone — nothing owed. / टिकट रद्द भयो — केही तिर्नु पर्दैन।');
        }

        if ($action === 'customer_plan') {
            /* QuickBot (7 Sep 2026): ONE merge engine for passengers and desks
               — TicketBot::suggest(). The smart line ("Ram 9876543210, 2 seats,
               Mehsana tomorrow") is parsed, explicit chips win over it, a
               SIGNED-IN passenger's own verified history fills the blanks
               (looked up by the SESSION's number only — `sessionPhone`; a
               typed number never reveals another phone's habits; gender and
               the date are never taken from history), then the public plan
               (S Hari Parking default, cut-off parity, seat cap). `seats` <= 0
               from the app means "not chosen yet". */
            $me = Auth::user();
            $s  = TicketBot::suggest([
                'text'         => Security::clean($in['text'] ?? '', 300),
                'name'         => Security::clean($in['name'] ?? '', 120),
                'phone'        => Security::clean($in['phone'] ?? '', 20),
                'seats'        => (int) ($in['seats'] ?? 0),
                'date'         => $opts['date'],
                'direction'    => $opts['direction'],
                'boarding'     => $opts['boarding'],
                'gender'       => $opts['gender'],
                'agentCode'    => Security::clean($in['agentCode'] ?? '', 20),
                'sessionPhone' => $me !== null ? (string) ($me['phone'] ?? '') : '',
                'lang'         => Security::clean($in['lang'] ?? '', 2),   // the app's UI language — the question's fallback language
                'prefer'       => $opts['prefer'],
            ], null);
            if (!is_array($s['plan'] ?? null)) {
                Response::error((string) ($s['planError'] ?? 'No bus can be sold right now. / अहिले कुनै बस बिक्रीमा छैन।'), 409);
            }
            $plan = $s['plan'];
            // What the card shows and sends back: the parsed line, the
            // proposal (with sources / reasons), and the passenger's memory summary.
            $applied = [];
            foreach (['boarding', 'direction', 'seats'] as $f) {
                if (($s['fields'][$f]['source'] ?? '') === 'history') {
                    $applied[$f] = true;
                }
            }
            if (isset($applied['boarding']) && empty($plan['matchedDesk'])) {
                unset($applied['boarding']);   // the bus does not call there on this run — the plan chose the first pickup
            }
            $prof = $s['profile'] ?? null;
            $plan['personal'] = $prof !== null
                ? ['name' => (string) $prof['name'], 'trips' => (int) $prof['trips'], 'lastTrip' => (string) $prof['lastTrip'], 'applied' => $applied]
                : null;
            $plan['parsed']   = $s['parsed'];
            $plan['prefill']  = $s['prefill'];
            $plan['fields']   = $s['fields'];
            $plan['why']      = $s['reasons'];
            $plan['learning'] = (bool) $s['enabled'];
            // SHG AI BRAIN Phase 2: the ladder — one clarifying question, the auto-filled facts, "same as last time".
            $plan['lang']       = $s['lang'];
            $plan['ladder']     = $s['ladder'];
            $plan['missing']    = $s['missing'];
            $plan['ask']        = $s['ask'];
            $plan['sameAsLast'] = $s['sameAsLast'];
            Response::success($plan);
        }
        $input = $opts + [
            'name'       => $in['name'] ?? '',
            'phone'      => $in['phone'] ?? '',
            'country'    => Security::clean($in['country'] ?? '', 2),
            'passengers' => $in['passengers'] ?? null,
            // The facts the card showed — the sale is refused (409 plan_changed) if they drifted.
            'expect'     => is_array($in['expect'] ?? null) ? $in['expect'] : null,
            // Optional agent code (SHG-NNN); an unknown code is a direct sale, never a refusal.
            'agentCode'  => Security::clean($in['agentCode'] ?? '', 20),
        ];
        $result = QuickTicket::sellCustomer($input, Auth::user());

        /* Passengers teach the bot too: what the one-click card proposed
           (`bot` = the pre-filled pickup / direction / party size) versus
           what was actually booked. Never blocks the sale. */
        if (is_array($in['bot'] ?? null) && TicketBot::enabled()) {
            try {
                TicketBot::feedback($in['bot'], [
                    'boarding'  => (string) $result['boarding'],
                    'direction' => (string) $result['direction'],
                    'seats'     => count((array) $result['seats']),
                ]);
            } catch (Throwable $e) {
                Logger::warning('TicketBot feedback (customer) failed', ['e' => $e->getMessage()]);
            }
        }

        /* Sign the passenger in as the number the ticket was sold to — the
           same name + mobile login api/otp.php 'quick' performs — INSIDE this
           request, so the ticket screen's image / PDF fetch and My Bookings
           work the moment the app lands there. Doing it here rather than as
           a second request from the app also avoids racing the session
           regeneration a login performs against the app's other calls (the
           loser of that race ends up in an empty session). A suspended
           account or any hiccup never fails a sale that is already made. */
        if (Auth::user() === null && $staff === null) {
            try {
                $row = Database::fetch('SELECT is_blocked FROM users WHERE phone = :p LIMIT 1', ['p' => (string) $result['phone']]);
                if ($row === null || (int) ($row['is_blocked'] ?? 0) === 0) {
                    $u = Auth::loginUser((string) $result['phone'], (string) $result['name'], Security::clean($in['country'] ?? '', 2));
                    $result['user'] = [
                        'phone'  => (string) $u['phone'],
                        'name'   => (string) ($u['full_name'] ?? ''),
                        'role'   => (string) $u['role'],
                        'points' => (int) ($u['loyalty_points'] ?? 0),
                        'tier'   => (string) ($u['loyalty_tier'] ?? 'Silver'),
                        'lang'   => (string) ($u['preferred_lang'] ?? ''),
                    ];
                }
            } catch (Throwable $e) {
                Logger::warning('QuickTicket: sign-in after the sale failed', ['e' => $e->getMessage()]);
            }
        }
        Response::success($result, 'Ticket issued. / टिकट जारी भयो।');
    }

    /* ------------------------------------------------------------------
     *  Staff door
     * ---------------------------------------------------------------- */
    if ($staff === null) {
        Response::unauthorized('Sign in as staff to use Quick Ticket. / Quick Ticket का लागि staff login गर्नुहोस्।');
    }
    if (!Auth::isSellingStaff()) {
        Response::forbidden('Your role can view tickets but not sell. / तपाईंको role ले टिकट बेच्न सक्दैन।');
    }
    // A desk issues tickets all day from one IP; still bounded.
    Security::requireRateLimit('quick_ticket', Security::clientIp(), 180, 300);

    if ($action === 'plan') {
        Response::success(QuickTicket::plan($opts));
    }

    if ($action === 'bot') {
        // QuickBot is the desk's ONE plan call now; with ai_bot_on OFF it still
        // parses the line and plans, it just brings no memory or patterns
        // (the response's `enabled` says so).
        Response::success(TicketBot::suggest([
            'text'      => Security::clean($in['text'] ?? '', 300),
            'name'      => Security::clean($in['name'] ?? '', 120),
            'phone'     => Security::clean($in['phone'] ?? '', 20),
            'country'   => Security::clean($in['country'] ?? '', 2),
            'seats'     => (int) ($in['seats'] ?? 0),
            'date'      => $opts['date'],
            'direction' => $opts['direction'],
            'boarding'  => $opts['boarding'],
            'gender'    => $opts['gender'],
            'pay'       => Security::clean($in['pay'] ?? '', 10),
            'lang'      => Security::clean($in['lang'] ?? '', 2),
            'prefer'    => $opts['prefer'],
        ], $staff));
    }

    /* ------------------------------------------------------------------
     *  QuickBot Passive Brain (7 Sep 2026) — what the night shift left.
     *
     *  Read-only for the desk: the queue, the requests that came in on
     *  their own, and the standing alerts. Confirming a card is not a new
     *  kind of sale — the desk sends the card's prefill straight back
     *  through `sell` with brainCard/brainDraft attached, so there is one
     *  sale path and one set of rules.
     * ---------------------------------------------------------------- */
    if ($action === 'brain_queue') {
        require_once INCLUDE_PATH . '/ticketbrain.php';
        $date = Security::isValidDate($opts['date']) ? $opts['date'] : date('Y-m-d');

        /* A counter agent may only ever see the passengers they sold
           themselves (Auth::bookingScopeAdminId is "an obligation to filter,
           not a hint"). Without this, one external SHG-NNN login could pull
           the whole company's passenger list — name, mobile, route, travel
           habits — out of the ready queue in a single POST. */
        $scope = Auth::bookingScopeAdminId();

        /* Standing intelligence is filtered by permission, not just by scope:
           revenue-against-target is an office number, and cancel_risk lists
           other customers' mobiles. Roles without dashboard.view / a company
           -wide booking view still get their own operational alerts. */
        $kinds = ['fill', 'demand_echo', 'departure'];
        if (Auth::can('dashboard.view')) {
            $kinds[] = 'revenue';
        }
        if ($scope === null && Auth::can('bookings.view')) {
            $kinds[] = 'cancel_risk';
        }

        Response::success([
            'enabled'  => TicketBrain::enabled(),
            'ready'    => TicketBrain::ready(),
            'date'     => $date,
            // Cards carry a LIVE suggestion (bus, seat, fare) — never an
            // overnight snapshot of availability.
            'cards'    => TicketBrain::queue($date, (int) ($in['limit'] ?? 0), $staff, true, $scope),
            'drafts'   => TicketBrain::drafts(20, 'new', $scope),
            'alerts'   => TicketBrain::openAlerts($date, 12, $kinds),
            'pending'  => TicketBrain::pending($scope),
            'accuracy' => TicketBrain::accuracy(30),
        ]);
    }

    if ($action === 'brain_dismiss') {
        require_once INCLUDE_PATH . '/ticketbrain.php';
        $kind = Security::clean($in['kind'] ?? 'card', 10);
        $id   = (int) ($in['id'] ?? 0);
        if ($id < 1) {
            Response::invalid(['id' => 'Which card?']);
        }
        $adminId = (int) ($staff['id'] ?? 0);
        $ok = match ($kind) {
            'draft' => TicketBrain::resolveDraft($id, 'dismissed', null, $adminId),
            'alert' => TicketBrain::dismissAlert($id),
            default => TicketBrain::resolvePrediction($id, 'dismissed', null, $adminId),
        };
        Response::success(['ok' => $ok], $ok ? 'Cleared.' : 'Already handled.');
    }

    if ($action === 'sell') {
        $input = $opts + [
            'name'          => $in['name'] ?? '',
            'phone'         => $in['phone'] ?? '',
            'country'       => Security::clean($in['country'] ?? '', 2),
            'pay'           => Security::clean($in['pay'] ?? '', 10),
            'discountType'  => Security::clean($in['discountType'] ?? '', 10),
            'discountValue' => (float) ($in['discountValue'] ?? 0),
            'note'          => $in['note'] ?? '',
            'passengers'    => $in['passengers'] ?? null,
        ];
        $result = QuickTicket::sell($input, $staff);

        /* Outcome loop: what the bot proposed vs what was actually sold.
           Never blocks the sale — a failed write is logged and forgotten. */
        if (is_array($in['bot'] ?? null) && TicketBot::enabled()) {
            try {
                TicketBot::feedback($in['bot'], [
                    'name'      => (string) $result['name'],
                    'gender'    => $opts['gender'],
                    'boarding'  => (string) $result['boarding'],
                    'direction' => (string) $result['direction'],
                    'seats'     => count((array) $result['seats']),
                ]);
            } catch (Throwable $e) {
                Logger::warning('TicketBot feedback failed', ['e' => $e->getMessage()]);
            }
        }

        /* The card that predicted this sale is now spent. Marking it here —
           not in the nightly reconcile — links the exact booking and credits
           the desk that acted on it, which is what the accuracy figure is
           measured on. Never blocks the sale. */
        $brainCard  = (int) ($in['brainCard'] ?? 0);
        $brainDraft = (int) ($in['brainDraft'] ?? 0);
        if ($brainCard > 0 || $brainDraft > 0) {
            try {
                require_once INCLUDE_PATH . '/ticketbrain.php';
                $bookingId = ((int) ($result['bookingId'] ?? $result['id'] ?? 0)) ?: null;
                $adminId   = (int) ($staff['id'] ?? 0);
                if ($brainCard > 0) {
                    TicketBrain::resolvePrediction($brainCard, 'confirmed', $bookingId, $adminId);
                }
                if ($brainDraft > 0) {
                    TicketBrain::resolveDraft($brainDraft, 'confirmed', $bookingId, $adminId);
                }
            } catch (Throwable $e) {
                Logger::warning('TicketBrain resolve failed', ['e' => $e->getMessage()], 'brain');
            }
        }

        Response::success($result, 'Ticket issued. / टिकट जारी भयो।');
    }

    Response::invalid(['action' => 'Unknown action.']);
} catch (QuickTicketPlanChanged $e) {
    // The card is stale: hand the fresh plan back so the app re-renders it
    // and asks for one more tap instead of selling a bus never shown.
    Response::error($e->getMessage(), 409, ['code' => 'plan_changed', 'plan' => $e->plan]);
} catch (PDOException $e) {
    // A database failure is a RuntimeException too — it must be logged and
    // answered generically, never shown to a passenger as raw SQL.
    Response::serverError($e);
} catch (RuntimeException $e) {
    // Rule refusals carry a desk-safe / passenger-safe bilingual message.
    Response::error($e->getMessage(), 409);
} catch (Throwable $e) {
    Response::serverError($e);
}
