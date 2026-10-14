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
 *       → Cash: CONFIRMED pay-at-boarding ticket now (no OTP). Online: the
 *         berths are held smartbook_online_hold_min and payPage is the QR
 *         page. `idem` makes a double tap / retry return the first booking.
 *         A moved price / date answers 409 fields{code:'changed', quote}.
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
        ]);
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
        $sched = Seats::scheduleById($sid);
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
        $st  = Security::isValidPnr($pnr) ? SmartPay::status($pnr) : null;
        if ($st === null) {
            Response::forbidden('Not your booking.');
        }
        Response::success($st);
    }

    if ($action === 'parse') {
        require_once INCLUDE_PATH . '/smartbookbot.php';
        Security::requireRateLimit('smartbook_parse', $ip, 120, 300);
        $text = Security::clean((string) ($in['text'] ?? ''), 300);
        Response::success(SmartBookBot::fields($text, $mode));
    }

    if ($action === 'book') {
        Security::requireRateLimit($mode === 'customer' ? 'smartbook_book' : 'smartbook_book_staff', $ip, $mode === 'customer' ? 12 : 120, 300);
        $pay    = ($in['pay'] ?? '') === 'online' ? 'online' : 'cash';
        $seller = null;
        if ($mode !== 'customer') {
            $admin = Auth::admin();
            if ($admin === null) {
                Response::unauthorized();
            }
            if ($pay === 'cash' || $mode === 'counter' || $mode === 'admin') {
                // Money taken at the desk (cash, or UPI/eSewa seen on the desk's phone).
                $seller = [
                    'adminId'       => (int) $admin['id'],
                    'source'        => $mode === 'admin' ? 'admin' : $mode,
                    'paymentMethod' => $pay === 'cash' ? 'cash' : (in_array($in['method'] ?? '', ['upi', 'esewa', 'bank'], true) ? (string) $in['method'] : 'upi'),
                    'note'          => Security::clean((string) ($in['note'] ?? ''), 255),
                ];
            } else {
                // Agent + Online: the CUSTOMER pays by QR; the sale is credited to the
                // agent's own code from the login (never typed — D13).
                $in['referralCode'] = AgentWallet::agentCodeLabel((int) $admin['id']);
            }
        }
        $idem = Security::clean((string) ($in['idem'] ?? ''), 64);
        try {
            $out = SmartBook::book($in, $pay, $mode, $seller, $idem);
        } catch (SmartBookChanged $e) {
            Response::error($e->getMessage(), 409, ['code' => 'changed', 'quote' => $e->quote]);
        } catch (RuntimeException $e) {
            Response::error($e->getMessage(), 409);
        }
        Response::success($out, $out['status'] === 'confirmed'
            ? 'Ticket confirmed. / टिकट पक्का भयो।'
            : 'Berths held — pay by QR to confirm. / बर्थ राखियो — QR बाट तिर्नुहोस्।');
    }

    Response::error('Unknown action.', 400);
} catch (Throwable $e) {
    Response::serverError($e);
}
