<?php
/**
 * POST /api/smartbook.php — SmartBook v5 (15 Oct 2026): the ONE booking
 * engine behind every booking face (home card, QuickBot, Sahayak, agent,
 * counter, admin). See includes/smartbook.php.
 *
 * The MODE comes from the SESSION, never from the payload (D1 / D13):
 * a guest or a signed-in passenger is always "customer"; selling staff get
 * their own role's mode (an admin may open agent / counter mode). A
 * customer request carries no agent / counter / staff code and the
 * answers carry no commission, wallet or staff field.
 *
 *   { action: "boot", phone?, lat?, lng?, direction?, mode? }
 *       → directions, points per direction, smart defaults (+ why), the
 *         cash switch, the policy line, NPR rate. Read-only.
 *   { action: "quote", direction, date?, point, pax[{gender,band,name?}],
 *     type?, cabin?, berth?, seats?[], phone?, prefer?[] }
 *       → the live summary: bus, date, pickup + report time, auto berths
 *         + reasons, server price + NPR, suggestion / alternatives. Read-only.
 *   { action: "book", pay: "cash"|"online", idem, name, phone, country,
 *     expect{total,date}, …quote fields }
 *       → Cash: CONFIRMED pay-at-boarding ticket now (no OTP); a customer
 *         sends verify{address, idType, idNum, altPhone?} (cod_verify_on) or
 *         gets 422 fields{code:'verify', field}. Online: the berths are held
 *         smartbook_online_hold_min and `pay` carries the QR popup's data
 *         (payPage stays as the full-page fallback). `idem` makes a double
 *         tap / retry return the first booking.
 *         A moved price / date answers 409 fields{code:'changed', quote}.
 *   { action: "payinfo", pnr, k? } → the QR popup again for a pending booking
 *         this browser / owner may open.
 *   { action: "parse", text } → QuickBot: the free-text line read on the
 *         server (TicketBot::parse) into card fields. Read-only.
 */

declare(strict_types=1);
require_once __DIR__ . '/_init.php';
require_once INCLUDE_PATH . '/smartbook.php';

try {
    Security::requirePost();
    Security::requireCsrf();

    $in     = Response::input();
    $action = (string) ($in['action'] ?? 'quote');
    $mode   = SmartBook::sessionMode(Security::clean((string) ($in['mode'] ?? ''), 10));
    $ip     = Security::clientIp();

    Security::requireRateLimit('smartbook_read', $ip, 300, 300);

    if ($action === 'boot') {
        $dirs   = SmartBook::directions();
        $points = [];
        foreach ($dirs as $d) {
            $points[$d['direction']] = SmartBook::points($d['direction'], $mode);
        }
        $facts = SiteFacts::all();
        Response::success([
            'mode'       => $mode,
            'directions' => $dirs,
            'points'     => $points,
            'defaults'   => SmartBook::defaults([
                'phone'     => Security::clean((string) ($in['phone'] ?? ''), 20),
                'lat'       => $in['lat'] ?? null,
                'lng'       => $in['lng'] ?? null,
                'direction' => Security::clean((string) ($in['direction'] ?? ''), 10),
            ], $mode),
            'allowCod'   => Settings::getBool('allow_cod', true),
            'maxPax'     => BookingService::maxSeatsFor($mode !== 'customer'),
            'npr'        => (float) $facts['npr'],
            'policy'     => $facts['policy']['line'],
            'holdMin'    => max(5, Settings::getInt('smartbook_online_hold_min', 15)),
            // 16 Oct 2026: Cash (pay at boarding) asks a customer for verification details.
            'codVerify'  => $mode === 'customer' && Settings::getBool('cod_verify_on', true),
            'idTypes'    => (static function (): array { require_once INCLUDE_PATH . '/customerprofile.php'; return CustomerProfile::ID_TYPES; })(),
            'prefill'    => $mode === 'customer' ? SmartBook::verifyPrefill() : null,
            'qrSeconds'  => max(30, min(600, Settings::getInt('smartbook_qr_seconds', 60))),
        ]);
    }

    if ($action === 'payinfo') {
        // The Online popup re-opened for a booking still waiting for payment -
        // only for the browser / owner of the booking or its keyed link.
        require_once INCLUDE_PATH . '/payadapter.php';
        require_once INCLUDE_PATH . '/ticket.php';
        $pnr = strtoupper(Security::clean((string) ($in['pnr'] ?? ''), 40));
        $key = Security::clean((string) ($in['k'] ?? ''), 64);
        $st  = Security::isValidPnr($pnr) ? SmartPay::status($pnr, $key) : null;
        if ($st === null) {
            Response::forbidden('Not your booking.');
        }
        $b = BookingService::findByPnr($pnr);
        Response::success($st + ['pnr' => $pnr, 'pay' => $st['status'] === 'pending' && $b !== null ? SmartBook::payData($b) : null]);
    }

    if ($action === 'quote') {
        try {
            Response::success(SmartBook::quote($in, $mode));
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        }
    }

    if ($action === 'seats') {
        // The "Choose seats" sheet: the coach as the card draws it. Read-only,
        // the same availability the sale re-checks (staff / emergency berths
        // are already unavailable to the public).
        $sid = (int) ($in['scheduleId'] ?? 0);
        if ($sid <= 0) {
            Response::invalid(['scheduleId' => 'Missing bus.']);
        }
        try {
            $sched = Seats::scheduleById($sid);
        } catch (RuntimeException $e) {
            Response::notFound('That bus is not available.');
        }
        $route = Database::fetch('SELECT * FROM routes WHERE id = :id LIMIT 1', ['id' => (int) $sched['route_id']]);
        $coach = Seats::effectiveCoach($sched, $route);
        $bt    = $coach === 'sleeper' ? ((($in['type'] ?? '') === 'private') ? 'private' : 'sharing') : 'seater';
        $av    = Seats::availabilityForSchedule($sid, $bt);
        $labels = [];
        foreach ((array) ($av['all'] ?? []) as $s) {
            $labels[(string) $s] = Seats::displayLabel((string) $s, $coach, $bt === 'seater' ? 'sharing' : $bt);
        }
        Response::success([
            'type'      => $bt,
            'decks'     => $av['layout']['decks'] ?? [],
            'available' => array_values($av['available'] ?? []),
            'female'    => $bt === 'private' ? [] : array_values($av['female'] ?? []),
            'labels'    => $labels,
        ]);
    }

    if ($action === 'status') {
        // The QR page's poll — only for the browser / owner of the booking.
        require_once INCLUDE_PATH . '/payadapter.php';
        $pnr = Security::clean((string) ($in['pnr'] ?? ''), 40);
        $st  = Security::isValidPnr($pnr) ? SmartPay::status($pnr, Security::clean((string) ($in['k'] ?? ''), 64)) : null;
        if ($st === null) {
            Response::forbidden('Not your booking.');
        }
        Response::success($st);
    }

    if ($action === 'regulars') {
        // Counter / agent quick list: the top 20 mobiles of the last 180 days
        // (an agent sees only the customers of their own sales). Staff only.
        if ($mode === 'customer') {
            Response::forbidden();
        }
        $scope  = Auth::bookingScopeAdminId();
        $params = [];
        $where  = "b.status IN ('confirmed','completed') AND b.created_at > NOW() - INTERVAL 180 DAY AND b.contact_phone NOT LIKE '0000%'";
        if ($scope !== null) {
            $where .= ' AND b.sold_by_admin_id = :me';
            $params['me']  = $scope;
            $params['me2'] = $scope;
        }
        $rows = Database::fetchAll(
            "SELECT b.contact_phone AS phone, COUNT(*) AS trips, MAX(b.created_at) AS last_at,
                    (SELECT bp.full_name FROM bookings b2 JOIN booking_passengers bp ON bp.booking_id = b2.id AND bp.is_primary = 1
                      WHERE b2.contact_phone = b.contact_phone" . ($scope !== null ? ' AND b2.sold_by_admin_id = :me2' : '') . " ORDER BY b2.id DESC LIMIT 1) AS name
               FROM bookings b WHERE {$where}
           GROUP BY b.contact_phone ORDER BY trips DESC, last_at DESC LIMIT 20",
            $params
        );
        Response::success(array_map(static fn(array $r): array => [
            'phone' => (string) $r['phone'], 'name' => (string) preg_replace('/\s*\(\d+\)$/', '', (string) $r['name']), 'trips' => (int) $r['trips'],
        ], $rows));
    }

    if ($action === 'agents') {
        // The desk's agent picker (15 Oct 2026, owner: "Agent Code entry in
        // Agent Mode and especially Counter Mode, with agent selection/search").
        // Staff only; an AGENT session gets its own locked code and no list.
        if ($mode === 'customer') {
            Response::forbidden();
        }
        $admin = Auth::admin();
        $me    = (int) ($admin['id'] ?? 0);
        $own   = AgentWallet::isAgentRow($me) ? AgentWallet::agentCodeLabel($me) : '';
        Response::success([
            'own'      => $own,
            'ownName'  => $own !== '' ? (string) ($admin['full_name'] ?: $admin['username']) : '',
            'required' => $own === '' && Settings::getBool('smartbook_agent_required', true),
            'company'  => Settings::getString('company_agent_code', 'SHG-0001'),
            'agents'   => $own !== '' ? [] : AgentWallet::sellableAgents(),
        ]);
    }

    if ($action === 'parse') {
        require_once INCLUDE_PATH . '/smartbookbot.php';
        Security::requireRateLimit('smartbook_parse', $ip, 120, 300);
        $text = Security::clean((string) ($in['text'] ?? ''), 300);
        Response::success(SmartBookBot::fields($text, $mode));
    }

    /* 15 Oct 2026: the F / M guess for a typed name (NameGuess) - the card
       pre-sets the chip and marks it 🤖; one tap changes it. */
    if ($action === 'guess') {
        require_once INCLUDE_PATH . '/nameguess.php';
        $g = NameGuess::gender(Security::clean((string) ($in['name'] ?? ''), 120));
        Response::success([
            'gender'     => $g['confidence'] >= NameGuess::SHOW ? $g['gender'] : '',
            'confidence' => $g['confidence'],
            'why'        => $g['why'],
        ]);
    }

    if ($action === 'book') {
        Security::requireRateLimit($mode === 'customer' ? 'smartbook_book' : 'smartbook_book_staff', $ip, $mode === 'customer' ? 12 : 120, 300);
        $pay    = ($in['pay'] ?? '') === 'online' ? 'online' : 'cash';
        $seller = null;
        // D13: a customer's payload never carries a code; whatever it sent is dropped here.
        unset($in['referralCode']);
        if ($mode !== 'customer') {
            $admin = Auth::admin();
            if ($admin === null) {
                Response::unauthorized();
            }
            $me = (int) $admin['id'];
            if ($pay === 'cash' || $mode === 'counter' || $mode === 'admin') {
                // Money taken at the desk (cash, or UPI/eSewa seen on the desk's phone).
                $seller = [
                    'adminId'       => $me,
                    'source'        => $mode === 'admin' ? 'admin' : $mode,
                    'paymentMethod' => $pay === 'cash' ? 'cash' : (in_array($in['method'] ?? '', ['upi', 'esewa', 'bank'], true) ? (string) $in['method'] : 'upi'),
                    'note'          => Security::clean((string) ($in['note'] ?? ''), 255),
                ];
            }
            /* WHO the sale is credited to (15 Oct 2026, owner prompt: agent code at
               the desk, automatic commission, synchronised accounting):
                 - an AGENT session sells on its own code from the login, never typed;
                 - a counter / admin desk (or an admin opening Agent mode) PICKS the
                   agent (#sbAgent on admin/smartbook.php): a valid active code, or
                   OTHER = the company's own code on purpose. An unknown / suspended
                   code is refused, never silently credited to the company. When
                   smartbook_agent_required is on (default), the desk must choose.
               create() credits the agent after the sale (attributeDeskSale →
               reassignSeller): commission moves to the agent, the cash stays
               with the clerk who took it. */
            if (AgentWallet::isAgentRow($me)) {
                $in['referralCode'] = AgentWallet::agentCodeLabel($me);
            } else {
                $typed = Security::clean((string) ($in['agentCode'] ?? ''), 20);
                $code  = AgentWallet::deskAgentCode($typed);
                if ($code === null) {
                    Response::error('Unknown or inactive agent code: ' . strtoupper($typed) . ' — pick the agent from the list. / यो एजेन्ट कोड छैन वा बन्द छ — सूचीबाट छान्नुहोस्।', 422, ['field' => 'agent']);
                }
                if ($code === '' && Settings::getBool('smartbook_agent_required', true) && AgentWallet::sellableAgents() !== []) {
                    Response::error('Choose the agent this sale is credited to (or Other = company). / यो बिक्री कुन एजेन्टको हो छान्नुहोस् (वा Other = कम्पनी)।', 422, ['field' => 'agent']);
                }
                $in['referralCode'] = $code;
            }
        }
        $idem = Security::clean((string) ($in['idem'] ?? ''), 64);
        try {
            $out = SmartBook::book($in, $pay, $mode, $seller, $idem);
        } catch (SmartBookChanged $e) {
            Response::error($e->getMessage(), 409, ['code' => 'changed', 'quote' => $e->quote]);
        } catch (SmartBookVerifyNeeded $e) {
            Response::error($e->getMessage(), 422, ['code' => 'verify', 'field' => $e->field]);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        }
        if ($mode !== 'customer') {
            // The desk sees WHO got the sale and what it earned (accrued inside
            // create(), moved to the picked agent by attributeDeskSale). Read from
            // the database so a replayed answer says the same thing.
            $out['commission'] = (float) Database::scalar(
                "SELECT COALESCE(SUM(amount), 0) FROM agent_ledger WHERE booking_id = :b AND account = 'commission'",
                ['b' => (int) $out['bookingId']],
                0
            );
            $soldBy = (int) Database::scalar('SELECT sold_by_admin_id FROM bookings WHERE id = :b', ['b' => (int) $out['bookingId']], 0);
            $out['agent'] = null;
            if ($soldBy > 0 && AgentWallet::isAgentRow($soldBy)) {
                $out['agent'] = [
                    'code' => AgentWallet::agentCodeLabel($soldBy),
                    'name' => (string) Database::scalar("SELECT COALESCE(NULLIF(full_name, ''), username) FROM admins WHERE id = :i", ['i' => $soldBy], ''),
                    'co'   => AgentWallet::isCompanyCode(AgentWallet::agentCodeLabel($soldBy)),
                ];
            }
        }
        if ($out['status'] === 'confirmed' && empty($out['replayed'])) {
            /* 15 Oct 2026 (owner: "ticket ekdum kam time ma, same page mai"):
               the PNG is drawn right after this answer leaves - the response
               is flushed first (fastcgi_finish_request), then the ticket is
               rendered into its cache, so the card's <img> that follows a
               moment later is served from disk instead of waiting on GD. */
            $preBid = (int) $out['bookingId'];
            register_shutdown_function(static function () use ($preBid): void {
                try {
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        session_write_close();
                    }
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    } else {
                        while (ob_get_level() > 0) { ob_end_flush(); }
                        flush();
                    }
                    require_once INCLUDE_PATH . '/fare.php';
                    require_once INCLUDE_PATH . '/qr.php';
                    require_once INCLUDE_PATH . '/pdf.php';
                    require_once INCLUDE_PATH . '/ticket.php';
                    Ticket::pngPath($preBid);
                } catch (Throwable $e) {
                    Logger::warning('SmartBook ticket pre-render failed', ['booking' => $preBid, 'e' => $e->getMessage()]);
                }
            });
        }
        Response::success($out, $out['status'] === 'confirmed'
            ? 'Ticket confirmed. / टिकट पक्का भयो।'
            : 'Berths held — pay by QR to confirm. / बर्थ राखियो — QR बाट तिर्नुहोस्।');
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
