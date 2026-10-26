<?php
/**
 * Automation layer — integration test.
 *
 * Proves the EventBus contract end to end: lifecycle events fire exactly
 * once per real transition (never on the idempotent noop paths), a
 * failing listener can never break a booking, exactly-once claims dedupe,
 * the .ics generator emits valid RFC 5545 content, agent code+mobile+OTP
 * login opens a correctly-scoped staff session, and the two new crons
 * (daily-summary / alerts) do their one-shot sends.
 *
 *   php -c .claude/php-dev.ini tests/automation-test.php
 *
 * Writes only throwaway rows (a 'testauto' agent, PNRs on a far-future
 * date) and cleans up after itself. CLI only. Needs
 * database/upgrade-2026-08-automation.sql applied.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/fare.php';
require_once INCLUDE_PATH . '/seats.php';
require_once INCLUDE_PATH . '/qr.php';
require_once INCLUDE_PATH . '/pdf.php';
require_once INCLUDE_PATH . '/ticket.php';
require_once INCLUDE_PATH . '/notify.php';
require_once INCLUDE_PATH . '/booking.php';

// Auth session bootstrap needs a live session even under CLI (see
// tests/admin-security-test.php for the whole story).
ob_start();

$PASS = 0; $FAIL = 0;
function check(string $l, bool $ok): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  \033[32mPASS\033[0m  $l\n"; }
    else     { $FAIL++; echo "  \033[31mFAIL\033[0m  $l\n"; }
}

const TD      = '2099-09-22';
const AGENT_U = 'testauto-agent';
const AGENT_P = '98111 22233';           // stored WITH spaces on purpose — login must normalise

/** Recorded emissions, in order. */
$EVENTS = [];
function countEvents(string $name): int {
    global $EVENTS;
    return count(array_filter($EVENTS, static fn($e) => $e['e'] === $name));
}

$agentId = 0; $sid = 0; $agentCode = 0;
$savedSeats = null;
$restoreSettings = [];

function cleanup(int $agentId, int $sid): void {
    global $savedSeats, $restoreSettings, $agentCode;
    foreach (Database::fetchAll("SELECT id FROM bookings WHERE pnr LIKE 'TESTAUTO-%' OR sold_by_admin_id = :a", ['a' => $agentId ?: -1]) as $r) {
        Database::delete('bookings', 'id = :i', ['i' => (int) $r['id']]);
    }
    if ($agentId > 0) {
        Database::delete('agent_ledger', 'agent_admin_id = :a', ['a' => $agentId]);
        try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $agentId]); } catch (Throwable $e) {}
        if ($agentCode > 0) {
            try { AgentWallet::setAgentCode($agentId, null, 0); } catch (Throwable $e) {}
        }
        Database::delete('admins', 'id = :a', ['a' => $agentId]);
    }
    if ($sid > 0) {
        if (is_array($savedSeats)) {
            Database::update('schedules', ['total_seats' => $savedSeats['total_seats']], 'id = :s', ['s' => $sid]);
        }
        try { Database::delete('schedule_unit_locks', 'schedule_id = :s', ['s' => $sid]); } catch (Throwable $e) {}
        Database::delete('schedules', 'id = :s AND travel_date = :d', ['s' => $sid, 'd' => TD]);
    }
    try {
        Database::delete('automation_log', "dedupe_key LIKE 'test-claim%' OR event LIKE 'test.%'");
        Database::delete('automation_log', "dedupe_key LIKE 'agent-summary:%'");
        Database::delete('automation_log', "dedupe_key LIKE 'admin-digest:%'");
        Database::delete('automation_log', "dedupe_key LIKE 'low-seat:%'");
    } catch (Throwable $e) {}
    Database::delete('otp_codes', "identifier LIKE 'agent:%'");
    Database::delete('rate_limits', "bucket IN ('agent_otp','agent_otp_verify') OR identifier LIKE 'agent:%'");
    foreach ($restoreSettings as $k => $v) {
        Settings::set($k, $v[0], $v[1], $v[2], (bool) $v[3]);
    }
    Settings::flush();
}

echo "\n=== Automation layer (EventBus / notify / OTP login / crons) ===\n\n";

try {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

    if (!Database::exists("SELECT 1 FROM information_schema.tables
                            WHERE table_schema = DATABASE() AND table_name = 'automation_log'")) {
        echo "  automation_log missing — run database/upgrade-2026-08-automation.sql first\n";
        exit(1);
    }

    // Keep the run offline and repeatable: no live SMS attempts, and no
    // stale claims/limits from a previous run today.
    $keep = static function (string $k, string $v, string $t, string $g) use (&$restoreSettings): void {
        $restoreSettings[$k] = [Settings::getString($k, $v), $t, $g, false];
        // restore uses original; the row keeps its stored group either way
    };
    $keep('sms_enabled', '0', 'bool', 'notify');
    Settings::set('sms_enabled', '0', 'bool', 'notify');
    Settings::flush();
    Database::delete('rate_limits', "bucket IN ('agent_otp','agent_otp_verify')");
    Database::delete('otp_codes', "identifier LIKE 'agent:%'");
    Database::delete('automation_log', "dedupe_key LIKE 'agent-summary:%' OR dedupe_key LIKE 'admin-digest:%'");

    /* ---- 0. EventBus primitives ------------------------------------ */
    $hit = 0;
    EventBus::on('test.simple', static function (array $d) use (&$hit): void { $hit += (int) ($d['n'] ?? 0); });
    EventBus::emit('test.simple', ['n' => 3]);
    check('a listener receives its payload', $hit === 3);

    EventBus::on('test.boom', static function (): void { throw new RuntimeException('boom'); });
    $after = false;
    EventBus::on('test.boom', static function () use (&$after): void { $after = true; });
    EventBus::emit('test.boom', []);
    check('a throwing listener is contained — emit() does not throw', true);
    check('  and later listeners still run', $after);
    check('  and the failure is journalled status=fail',
        Database::exists("SELECT 1 FROM automation_log WHERE event = 'test.boom' AND status = 'fail'"));

    $ck = 'test-claim-' . getmypid();
    check('claim(): first caller wins', EventBus::claim($ck, 'test.claim') === true);
    check('  second caller is refused', EventBus::claim($ck, 'test.claim') === false);
    check('  a different key is independent', EventBus::claim($ck . '-b', 'test.claim') === true);

    /* ---- 1. Calendar (.ics) ---------------------------------------- */
    $ics = Calendar::journeyEvent([
        'pnr' => 'TESTAUTO-ICS', 'route' => 'Surat → Rupaidiha',
        'date' => TD, 'depTime' => '13:00', 'seats' => 'L1, L2',
    ]);
    check('journeyEvent builds a VCALENDAR', is_string($ics) && str_contains($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'END:VCALENDAR'));
    check('  with the departure as floating local time', str_contains((string) $ics, 'DTSTART:20990922T130000'));
    check('  and RFC 5545 comma escaping in the description', str_contains((string) $ics, 'L1\, L2'));
    check('  no date → no event (never a broken attachment)',
        Calendar::journeyEvent(['pnr' => 'X', 'date' => '']) === null);

    /* ---- 2. Test fixtures: agent + schedule ------------------------- */
    $old = Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => AGENT_U]);
    if ($old !== null) { cleanup((int) $old['id'], 0); }

    $agentId = Database::insert('admins', [
        'username'      => AGENT_U,
        'password_hash' => password_hash('not-a-real-login', PASSWORD_BCRYPT),
        'full_name'     => 'Automation Test Agent',
        'phone'         => AGENT_P,
        'role'          => 'agent',
        'is_active'     => 1,
        'must_change_pw'=> 1,        // OTP login must not trip the forced-change gate
    ]);

    // A free SHG code for the login test.
    $agentCode = 0;
    for ($c = 990; $c >= 950; $c--) {
        if (AgentWallet::adminForAgentCode($c) === null) { $agentCode = $c; break; }
    }
    check('found a free agent code for the test', $agentCode > 0);
    AgentWallet::setAgentCode($agentId, $agentCode, 0);

    $route = Database::fetch("SELECT * FROM routes WHERE is_active = 1 ORDER BY id LIMIT 1");
    if ($route === null) { echo "no active route in this database\n"; cleanup($agentId, 0); exit(1); }
    $sch = Seats::schedule((int) $route['id'], TD);
    $sid = (int) $sch['id'];
    $savedSeats = Database::fetch('SELECT total_seats FROM schedules WHERE id = :s', ['s' => $sid]);

    $free = Seats::availability((int) $route['id'], TD)['available'] ?? [];
    if (count($free) < 3) { echo "not enough free seats\n"; cleanup($agentId, $sid); exit(1); }

    // Record every emission from here on.
    foreach (['booking.created', 'booking.approved', 'booking.rejected', 'booking.cancelled',
              'booking.cod_settled', 'booking.payment_uploaded', 'booking.boarded',
              'agent.registered', 'seats.low'] as $ev) {
        EventBus::on($ev, static function (array $d) use ($ev, &$EVENTS): void {
            $EVENTS[] = ['e' => $ev, 'd' => $d];
        });
    }

    /* ---- 3. Counter sale emits created + approved ------------------- */
    $B = BookingService::counterSale($route, $sid, TD, [$free[0]], [
        'name' => 'Event Passenger', 'phone' => '9800000031',
        'amount' => 2000.00, 'paymentMethod' => 'cash',
    ], $agentId, 'agent');

    check('counter sale emits booking.created', countEvents('booking.created') === 1);
    check('  and booking.approved (via counter)',
        countEvents('booking.approved') === 1
        && ($EVENTS[array_key_last($EVENTS)]['d']['via'] ?? '') === 'counter');
    check('  the payload is the booking row',
        (int) ($EVENTS[0]['d']['booking']['id'] ?? 0) === (int) $B['id']);
    check('  and the emission was journalled',
        Database::exists("SELECT 1 FROM automation_log WHERE event = 'booking.approved'"));

    /* ---- 4. reject → rejected once; noop re-reject stays silent ----- */
    BookingService::reject((int) $B['id'], 1, 'Test rejection');
    check('reject emits booking.rejected once', countEvents('booking.rejected') === 1);
    BookingService::reject((int) $B['id'], 1, 'Test rejection again');
    check('  re-rejecting (noop) emits nothing new', countEvents('booking.rejected') === 1);

    /* ---- 5. re-approve → approved; double-click stays silent -------- */
    BookingService::confirm((int) $B['id'], 1, 'Re-approved');
    check('confirm emits booking.approved (now 2 total)', countEvents('booking.approved') === 2);
    BookingService::confirm((int) $B['id'], 1, 'Double click');
    check('  double-confirm (noop) emits nothing new', countEvents('booking.approved') === 2);

    /* ---- 6. cancel → cancelled, refund in the payload --------------- */
    BookingService::cancel((string) $B['pnr'], 'Test cancel', false);
    check('cancel emits booking.cancelled with refund figures',
        countEvents('booking.cancelled') === 1
        && array_key_exists('refund', $EVENTS[array_key_last($EVENTS)]['d']));

    /* ---- 7. boarding: first scan emits, re-scan does not ------------ */
    $C = BookingService::counterSale($route, $sid, TD, [$free[1]], [
        'name' => 'Boarding Passenger', 'phone' => '9800000032',
        'amount' => 2000.00, 'paymentMethod' => 'upi',
    ], $agentId, 'agent');
    BookingService::markBoarded((string) $C['pnr'], 1);
    check('first gate scan emits booking.boarded', countEvents('booking.boarded') === 1);
    BookingService::markBoarded((string) $C['pnr'], 1);
    check('  a re-scan (already boarded) emits nothing new', countEvents('booking.boarded') === 1);

    /* ---- 8. COD settle: once, and only on the real transition ------- */
    $codId = Database::insert('bookings', [
        'pnr' => 'TESTAUTO-COD1', 'contact_phone' => '9800000033',
        'total_amount' => 1500.00, 'status' => 'confirmed', 'is_cod' => 1,
        'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'web',
        'sold_by_admin_id' => $agentId,
    ]);
    Database::insert('payments', [
        'booking_id' => $codId, 'payment_ref' => 'TESTAUTO-PAY1',
        'method' => 'cod', 'mode' => 'offline', 'amount' => 1500.00,
        'status' => 'cod_pending',
    ]);
    BookingService::settleCod($codId, 1, 'cash in hand');
    check('settleCod emits booking.cod_settled', countEvents('booking.cod_settled') === 1);
    BookingService::settleCod($codId, 1, 'double click');
    check('  settling again (noop) emits nothing new', countEvents('booking.cod_settled') === 1);

    /* ---- 8b. Re-approving a mistakenly-rejected COD keeps cash OWED -- */
    // A COD booking (cash NOT yet collected) is rejected, then re-approved via
    // confirm(). confirm() must leave the payment 'cod_pending', NOT 'verified'
    // — marking uncollected cash as paid drops it off the cash-owed queue and
    // pays commission on money nobody has. settleCod stays the only verify step.
    $codR = Database::insert('bookings', [
        'pnr' => 'TESTAUTO-CODR', 'contact_phone' => '9800000039',
        'total_amount' => 2000.00, 'status' => 'confirmed', 'is_cod' => 1,
        'confirmed_at' => date('Y-m-d H:i:s'), 'source' => 'web',
        'sold_by_admin_id' => $agentId,
    ]);
    Database::insert('payments', [
        'booking_id' => $codR, 'payment_ref' => 'TESTAUTO-PAYR',
        'method' => 'cod', 'mode' => 'offline', 'amount' => 2000.00, 'status' => 'cod_pending',
    ]);
    BookingService::reject($codR, 1, 'mistaken reject');
    BookingService::confirm($codR, 1, 're-approve');
    $codPay = Database::fetch('SELECT status FROM payments WHERE booking_id = :b ORDER BY id DESC LIMIT 1', ['b' => $codR]);
    check('re-approved COD payment stays cod_pending (cash still owed)', ($codPay['status'] ?? '') === 'cod_pending');
    check('  no commission accrued against uncollected cash',
        !Database::exists("SELECT 1 FROM agent_ledger WHERE booking_id = :b AND entry_type = 'commission'", ['b' => $codR]));

    /* ---- 9. payment proof: event fires, admin ping deduped hourly --- */
    $pendId = Database::insert('bookings', [
        'pnr' => 'TESTAUTO-PEND1', 'contact_phone' => '9800000034',
        'total_amount' => 2000.00, 'status' => 'pending',
        'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'source' => 'web',
    ]);
    Database::insert('payments', [
        'booking_id' => $pendId, 'payment_ref' => 'TESTAUTO-PAY2',
        'method' => 'upi', 'mode' => 'utr', 'amount' => 2000.00, 'status' => 'pending',
    ]);
    BookingService::submitPaymentProof('TESTAUTO-PEND1', ['utr' => 'UTRTEST99001']);
    check('proof submit emits booking.payment_uploaded', countEvents('booking.payment_uploaded') === 1);
    check('  and stops the expiry clock (owed a review)',
        Database::fetch('SELECT expires_at FROM bookings WHERE id = :i', ['i' => $pendId])['expires_at'] === null);
    BookingService::submitPaymentProof('TESTAUTO-PEND1', ['utr' => 'UTRTEST99001']);
    $claims = (int) Database::scalar(
        "SELECT COUNT(*) FROM automation_log WHERE dedupe_key LIKE :k",
        ['k' => 'proofalert:' . $pendId . ':%'], 0);
    check('  the UTR+screenshot double-submit claims ONE admin ping', $claims === 1);

    /* ---- 10. Agent code + mobile + OTP login ------------------------ */
    $r = Auth::agentLoginStart('SHG-' . $agentCode, '9811122233');
    check('agentLoginStart accepts code + mobile (normalised match)', $r['ok'] === true);

    $r = Auth::agentLoginStart('SHG-' . $agentCode, '9899999999');
    check('  a wrong mobile gets the one generic failure', $r['ok'] === false && str_contains((string) $r['error'], 'did not match'));
    $r = Auth::agentLoginStart('SHG-001-nope', AGENT_P);
    check('  an unknown code gets the same generic failure', $r['ok'] === false);

    // Mint a fresh code directly (Auth returns the plain code to its caller)
    // so the verify step can be driven — resend limits cleared first.
    Database::delete('rate_limits', "identifier LIKE 'agent:%'");
    Database::delete('otp_codes', "identifier LIKE 'agent:%'");
    $mint = Auth::issueOtp('agent:9811122233', 'login', 'whatsapp');
    check('OTP mints for the namespaced agent identifier', $mint['ok'] === true && $mint['code'] !== '');

    $r = Auth::agentLoginVerify((string) $agentCode, AGENT_P, '000000');
    check('  a wrong OTP is refused', $r['ok'] === false);
    $r = Auth::agentLoginVerify((string) $agentCode, AGENT_P, (string) $mint['code']);
    check('  the right OTP signs the agent in', $r['ok'] === true);

    $sess = $_SESSION[ADMIN_SESSION_KEY] ?? [];
    check('  session role is agent (never portal-decided)', ($sess['role'] ?? '') === 'agent');
    check('  data scope points at this agent', Auth::bookingScopeAdminId() === $agentId);
    check('  must_change_pw is bypassed for OTP sessions (no password deadlock)',
        ($sess['must_change_pw'] ?? true) === false && ($sess['via_otp'] ?? false) === true);
    unset($_SESSION[ADMIN_SESSION_KEY]);

    Database::update('admins', ['is_active' => 0], 'id = :i', ['i' => $agentId]);
    $r = Auth::agentLoginStart('SHG-' . $agentCode, AGENT_P);
    check('  a deactivated agent cannot start a login', $r['ok'] === false);
    Database::update('admins', ['is_active' => 1], 'id = :i', ['i' => $agentId]);

    /* ---- 11. Crons: alerts (low-seat + pending review) -------------- */
    // Both alerts go to admin WhatsApp — the cron now bails BEFORE claiming
    // unless that channel is deliverable (adminWhatsappReady), so arm it. In
    // this DB whatsapp_driver is click_to_chat, so whatsapp() only returns a
    // wa.me string — no real send happens.
    Settings::set('whatsapp_notify_admin', '1', 'bool', 'notify');
    Settings::set('admin_whatsapp', '919990001234', 'string', 'notify');
    // Low-seat now uses Seats::availability (sellable seats, excluding staff
    // berths + blocks), not total_seats − booked. Set the threshold above the
    // coach capacity so the near-term schedule reliably qualifies.
    Settings::set('low_seat_threshold', '999', 'int', 'automation');
    Settings::flush();

    // The alert window is CURDATE()..+2 days. Move our test schedule into a
    // FREE near-term slot if one exists (uq_schedule_route_date!), else borrow
    // the existing near-term schedule (leave capacity alone — availability is
    // seat-set based now, not total_seats).
    $nearSid = 0; $moved = false;
    for ($i = 0; $i <= 2; $i++) {
        $d = date('Y-m-d', strtotime('+' . $i . ' day'));
        if (Database::fetch('SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d',
                            ['r' => (int) $route['id'], 'd' => $d]) === null) {
            Database::update('schedules', ['travel_date' => $d, 'status' => 'scheduled'], 'id = :s', ['s' => $sid]);
            $nearSid = $sid; $moved = true;
            break;
        }
    }
    if (!$moved) {
        $borrow = Database::fetch(
            'SELECT id FROM schedules WHERE route_id = :r AND travel_date = :d LIMIT 1',
            ['r' => (int) $route['id'], 'd' => date('Y-m-d', strtotime('+1 day'))]
        );
        Database::update('schedules', ['status' => 'scheduled'], 'id = :s', ['s' => (int) $borrow['id']]);
        $nearSid = (int) $borrow['id'];
    }
    // pending proof waiting 45 min
    Database::query('UPDATE payments SET updated_at = DATE_SUB(NOW(), INTERVAL 45 MINUTE) WHERE payment_ref = :r', ['r' => 'TESTAUTO-PAY2']);

    $iniArg = escapeshellarg(dirname(__DIR__) . '/.claude/php-dev.ini');
    $cronOut = shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . $iniArg . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/alerts.php') . ' 2>&1') ?? '';
    $cronJson = json_decode(trim((string) strrchr(trim($cronOut), "\n") ?: $cronOut), true) ?? [];
    check('alerts cron runs clean', is_array($cronJson) && !isset($cronJson['error']));
    check('  low-seat alert claimed for the near-term schedule',
        Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => 'low-seat:' . $nearSid]));
    check('  stale payment proof claimed a pending-review reminder',
        Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => 'pending-alert:' . $pendId]));

    shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . $iniArg . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/alerts.php') . ' 2>&1');
    $again = (int) Database::scalar('SELECT COUNT(*) FROM automation_log WHERE dedupe_key = :k', ['k' => 'low-seat:' . $nearSid], 0);
    check('  a second cron pass re-sends nothing (claims hold)', $again === 1);

    // Channel-off proof: with admin WhatsApp off, the cron must skip BEFORE
    // claiming, so a fresh schedule's alert is NOT burned while it is down.
    Database::delete('automation_log', "dedupe_key LIKE 'low-seat:%'");
    Settings::set('whatsapp_notify_admin', '0', 'bool', 'notify');
    Settings::flush();
    shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . $iniArg . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/alerts.php') . ' 2>&1');
    check('  channel off → nothing claimed (claim not burned)',
        !Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => 'low-seat:' . $nearSid]));

    // put things back: schedule to its far-future date, settings to defaults,
    // and drop the test's low-seat claims.
    if ($moved) {
        Database::update('schedules', ['travel_date' => TD], 'id = :s', ['s' => $sid]);
    }
    Settings::set('whatsapp_notify_admin', '1', 'bool', 'notify');
    Settings::set('low_seat_threshold', '10', 'int', 'automation');
    Settings::flush();
    Database::delete('automation_log', "dedupe_key LIKE 'low-seat:%'");

    /* ---- 12. Crons: daily summary reports the COMPLETED previous day  */
    $yday = date('Y-m-d', strtotime('-1 day'));
    $sumOut = shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . $iniArg . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/daily-summary.php') . ' 2>&1') ?? '';
    check('daily-summary cron runs clean', str_contains((string) $sumOut, '"date"'));
    check('  digest claimed for yesterday (the closed day)',
        Database::exists('SELECT 1 FROM automation_log WHERE dedupe_key = :k', ['k' => 'agent-summary:' . $yday]));
    $sumOut2 = shell_exec(escapeshellarg(PHP_BINARY) . ' -c ' . $iniArg . ' ' . escapeshellarg(dirname(__DIR__) . '/cron/daily-summary.php') . ' 2>&1') ?? '';
    check('  a second run the same day skips (already ran)', str_contains((string) $sumOut2, 'already ran'));

    /* ---- 13. Email master switch ------------------------------------ */
    $restoreSettings['email_enabled'] = [Settings::getString('email_enabled', '1'), 'bool', 'automation', false];
    Settings::set('email_enabled', '0', 'bool', 'automation');
    Settings::flush();
    check('email_enabled=0 short-circuits email()', Notify::email('someone@example.com', 'x', '<p>x</p>') === false);

} catch (Throwable $e) {
    check('unexpected exception: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
} finally {
    cleanup($agentId, $sid);
}

echo "\n  ────────────────────────────────\n";
echo "  {$PASS} passed · {$FAIL} failed\n\n";
$buf = ob_get_clean();
echo $buf;
exit($FAIL === 0 ? 0 : 1);
