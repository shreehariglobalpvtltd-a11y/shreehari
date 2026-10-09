<?php
/**
 * admin/scan.php — verify a ticket at boarding (the conductor's screen).
 *
 * Enter or scan a PNR; the page answers with ONE full-screen colour the
 * crew can read from arm's length — green VALID, amber CHECK (collect cash,
 * check photo ID, wrong date), red DO NOT BOARD — with a sound and a buzz,
 * then the details and the boarding button underneath.
 *
 * 9 Oct 2026 (owner: "ticket fake badi hun layo — yeslai manage garnu"):
 *   - Every lookup and every scanned QR is audited ('ticket.scan' with the
 *     outcome: valid | not_found | forged | unsigned | foreign | not_confirmed
 *     | cancelled | voided | already | wrong_date | wrong_bus | trip_cancelled).
 *   - A bad outcome rings the panel bell (AdminAlerts 'fake_scan', one per
 *     PNR / outcome / hour) and, with Settings fraud_scan_whatsapp on, the
 *     office WhatsApp.
 *   - Cash on boarding (payment cod_pending): amber "COLLECT ₹X CASH", and
 *     the crew ticks "cash collected" before boarding.
 *   - Travel date / bus / cancelled trip: a ticket for another day, another
 *     bus (pick "My bus" at the top) or a departure the office cancelled is
 *     flagged; boarding it anyway needs a written reason, audited as
 *     'ticket.board_override'.
 *   - The printed SECURITY CODE (Ticket::securityCode, on the PNG / PDF and
 *     verify page) and the passenger screen's LIVE CODE (changes every 30 s,
 *     TicketLive) are shown large, so the crew compares paper / screen with
 *     the truth. An edited screenshot keeps an old code.
 *   - The unsigned on-screen QR path needs the "photo ID checked" tick.
 *   - "Report to office" opens a verify thread in the office chat when that
 *     is available (StaffMsg), else rings the bell.
 * BookingService::markBoarded() keeps its exact semantics (first call
 * 'boarded', then 'already'); every check here sits in front of it.
 * Nothing on this page cancels, voids or refunds a booking.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/paymentrisk.php';
require_once INCLUDE_PATH . '/adminalerts.php';
$admin = admin_boot('tickets.scan');

$base   = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$pnr    = strtoupper(Security::clean($_GET['pnr'] ?? '', 40));
$tripId = (int) ($_POST['trip'] ?? $_GET['trip'] ?? 0);
$via    = 'lookup';
$qrState = null;            // signed | forged | unsigned | foreign (camera path only)
$flash  = null;             // [ok|bad|warn, text] for an action result
$boarded = null;            // markBoarded() result after a board POST
$audit  = false;            // write a 'ticket.scan' row for this request?
$me     = (string) (($admin['full_name'] ?? '') ?: ($admin['username'] ?? ('#' . (int) $admin['id'])));

/* ---- The live code a passenger's screen must show now (JSON, staff only) ---- */
if (isset($_GET['live'])) {
    $lp = strtoupper(Security::clean((string) $_GET['live'], 40));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!Security::isValidPnr($lp)) {
        http_response_code(404);
        echo '{"ok":false}';
        exit;
    }
    echo json_encode(['ok' => true, 'code' => TicketLive::code($lp), 'validFor' => TicketLive::validFor(), 'step' => TicketLive::STEP]);
    exit;
}

/** Outcome words for the bell, the audit and the big screen. */
const SCAN_WORDS = [
    'valid'          => ['VALID · मान्य · वैध', 'Ready to board · चढ्न दिनुहोस्'],
    'not_found'      => ['NOT FOUND · भेटिएन', 'No booking with this reference — treat the ticket as fake and check ID.'],
    'forged'         => ['FORGED QR · नक्कली QR', 'This QR claims to be ours but its signature does not match. Do not board.'],
    'foreign'        => ['NOT OUR TICKET · हाम्रो होइन', 'That code is not a S Hari Global ticket.'],
    'not_confirmed'  => ['NOT CONFIRMED · पक्का भएको छैन', 'Payment not verified — do not board. Ask the office.'],
    'cancelled'      => ['CANCELLED · रद्द', 'This ticket was cancelled. Do not board.'],
    'voided'         => ['VOIDED · अमान्य', 'This ticket was voided (replaced or corrected). Ask for the new ticket.'],
    'already'        => ['ALREADY BOARDED · पहिल्यै चढिसक्यो', 'This ticket has been used. Do NOT board again.'],
    'trip_cancelled' => ['TRIP CANCELLED · यात्रा रद्द', 'The office cancelled this departure.'],
    'wrong_bus'      => ['WRONG BUS · अर्को बस', 'This ticket is for a different bus / departure.'],
    'wrong_date'     => ['WRONG DATE · अर्को दिन', 'This ticket is for another travel date.'],
    'unsigned'       => ['CHECK PHOTO ID · परिचयपत्र हेर्नुहोस्', 'Unsigned on-screen QR — the booking is real; match the name with a photo ID.'],
    'cod'            => ['COLLECT CASH · नगद लिनुहोस्', 'Cash on boarding — collect it before the passenger boards.'],
];
const SCAN_BAD = ['not_found', 'forged', 'foreign', 'not_confirmed', 'cancelled', 'voided', 'already', 'trip_cancelled', 'wrong_bus', 'wrong_date'];

/**
 * Everything the gate must know about one booking, in priority order.
 *
 * @param array<string,mixed> $b BookingService::detail()
 * @return array{outcome:string, tone:string, canBoard:bool, needNote:bool, needId:bool, cash:float,
 *               leg:array<string,mixed>, flags:list<string>}
 */
function scan_check(array $b, int $tripId, ?string $qrState): array
{
    $legs = (array) ($b['legs'] ?? []);
    $rel  = $legs;
    if ($tripId > 0) {
        $rel = array_values(array_filter($legs, static fn(array $l): bool => (int) ($l['schedule_id'] ?? 0) === $tripId));
    }
    $today = date('Y-m-d');
    $yday  = date('Y-m-d', strtotime('-1 day'));
    $leg = null;
    foreach ($rel as $l) {
        if (in_array((string) ($l['travel_date'] ?? ''), [$today, $yday], true)) {
            $leg = $l;
            break;
        }
    }
    $dateOk = $leg !== null;
    $leg = $leg ?? ($rel[0] ?? ($legs[0] ?? []));

    $tk      = $b['ticket'] ?? null;
    $status  = (string) ($b['status'] ?? '');
    $pay     = $b['payment'] ?? null;
    $cash    = ($pay !== null && (string) ($pay['status'] ?? '') === 'cod_pending')
        ? (float) (($pay['amount'] ?? 0) ?: ($b['total_amount'] ?? 0)) : 0.0;

    $r = ['outcome' => 'valid', 'tone' => 'green', 'canBoard' => true, 'needNote' => false, 'needId' => false,
          'cash' => $cash, 'leg' => $leg, 'flags' => []];
    $hard = static function (string $o) use (&$r): array {
        $r['outcome'] = $o;
        $r['tone'] = 'red';
        $r['canBoard'] = false;
        $r['cash'] = 0.0;
        return $r;
    };

    if ($status === 'cancelled') {
        return $hard('cancelled');
    }
    if ($status !== 'confirmed') {
        return $hard('not_confirmed');
    }
    if ($tk === null || (int) ($tk['is_void'] ?? 0) === 1) {
        return $hard('voided');
    }
    if (!empty($tk['scanned_at']) || (int) ($tk['scan_count'] ?? 0) > 0) {
        return $hard('already');
    }

    // soft checks: the crew may override with a written reason
    if ((string) ($leg['schedule_status'] ?? '') === 'cancelled') {
        $r['flags'][] = 'trip_cancelled';
    }
    if ($tripId > 0 && $rel === []) {
        $r['flags'][] = 'wrong_bus';
    }
    if (!$dateOk && !in_array('wrong_bus', $r['flags'], true)) {
        $r['flags'][] = 'wrong_date';
    }
    if ($qrState === 'unsigned') {
        $r['flags'][] = 'unsigned';
        $r['needId'] = true;
    }
    if ($cash > 0) {
        $r['flags'][] = 'cod';
    }
    $r['needNote'] = array_intersect($r['flags'], ['trip_cancelled', 'wrong_bus', 'wrong_date']) !== [];

    if ($r['flags'] !== []) {
        $r['outcome'] = $r['flags'][0];
        $r['tone'] = array_intersect($r['flags'], ['trip_cancelled', 'wrong_bus']) !== [] ? 'red' : 'amber';
    }
    return $r;
}

/** Ring the bell (and optionally the office WhatsApp) for a bad scan. Never throws. */
function scan_alert(string $outcome, string $pnr, string $who, int $tripId): void
{
    if (!in_array($outcome, SCAN_BAD, true)) {
        return;
    }
    try {
        $title = '⛔ ' . (SCAN_WORDS[$outcome][0] ?? strtoupper($outcome)) . ($pnr !== '' ? ' · ' . $pnr : '');
        $link  = $pnr !== '' && Security::isValidPnr($pnr) && !in_array($outcome, ['not_found', 'foreign'], true)
            ? '/admin/booking-view.php?pnr=' . rawurlencode($pnr) : '/admin/scan.php';
        AdminAlerts::push('fake_scan', mb_substr($title, 0, 120), 'Scanned at the gate by ' . $who . ($tripId > 0 ? ' · trip #' . $tripId : '') . ' — ' . (SCAN_WORDS[$outcome][1] ?? ''),
            $link, '⛔', 'fakescan:' . ($pnr !== '' ? $pnr : 'x') . ':' . $outcome . ':' . date('YmdH'));
        if (Settings::getBool('fraud_scan_whatsapp', false)) {
            if (!class_exists('Notify')) {
                require_once INCLUDE_PATH . '/notify.php';
            }
            if (class_exists('EventBus') && !EventBus::claim('fakescanwa:' . $pnr . ':' . $outcome . ':' . date('YmdH'), 'ticket.scan')) {
                return;
            }
            Notify::adminNote('⛔ Gate scan: ' . (SCAN_WORDS[$outcome][0] ?? $outcome), [
                'PNR' => $pnr !== '' ? $pnr : '—', 'By' => $who, 'Time' => date('j M, g:i A'),
            ]);
        }
    } catch (Throwable $e) {
        Logger::warning('scan alert failed', ['e' => $e->getMessage()]);
    }
}

/* ---- Report to office -------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'report') {
    $pnr = strtoupper(Security::clean($_POST['pnr'] ?? '', 40));
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — reload the page and try again.'];
    } else {
        $note = Security::clean($_POST['note'] ?? '', 500);
        $sent = false;
        if (class_exists('StaffMsg') && StaffMsg::available() && method_exists('StaffMsg', 'reportSuspicious')) {
            $r = StaffMsg::reportSuspicious($pnr !== '' ? $pnr : 'UNKNOWN', 'Gate scan · ' . $me . ($note !== '' ? "\n" . $note : ''));
            $sent = !empty($r['ok']);
        }
        if (!$sent) {
            AdminAlerts::push('fake_scan', '🛡️ Suspicious ticket reported · ' . ($pnr !== '' ? $pnr : '—'),
                'From the gate by ' . $me . ($note !== '' ? ': ' . mb_substr($note, 0, 200) : ''),
                $pnr !== '' && Security::isValidPnr($pnr) ? '/admin/booking-view.php?pnr=' . rawurlencode($pnr) : '/admin/scan.php', '🛡️');
        }
        Logger::audit('ticket.scan_report', 'booking', $pnr !== '' ? $pnr : '-', null, ['trip' => $tripId], $note);
        $flash = ['ok', '🛡️ Reported to the office' . ($sent ? ' (office chat)' : '') . '. कार्यालयलाई जानकारी गरियो।'];
    }
}

/* ---- Mark boarded ------------------------------------------------
   The one write path for boarding. markBoarded() records the scan, stamps who
   boarded on the first pass, and refuses a second boarding — so a re-used QR
   screenshot is caught there rather than flashing green "VALID" again (H2).
   The gate checks (date, bus, cancelled trip, photo ID, cash) run first. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'board') {
    $pnr = strtoupper(Security::clean($_POST['pnr'] ?? '', 40));
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — reload the page and scan again.'];
    } else {
        $src  = (string) ($_POST['src'] ?? '');
        $note = Security::clean($_POST['override_note'] ?? '', 255);
        $bb   = Security::isValidPnr($pnr) ? BookingService::detail($pnr) : null;
        try {
            if ($bb === null) {
                throw new RuntimeException('Booking not found.');
            }
            $chk = scan_check($bb, $tripId, $src === 'unsigned' ? 'unsigned' : null);
            if (!$chk['canBoard'] && $chk['outcome'] !== 'already') {
                throw new RuntimeException((SCAN_WORDS[$chk['outcome']][0] ?? 'Cannot board') . ' — ' . (SCAN_WORDS[$chk['outcome']][1] ?? ''));
            }
            if ($chk['needNote'] && mb_strlen($note) < 4) {
                throw new RuntimeException('Write why you are boarding this ticket anyway (wrong date / bus / cancelled trip). / किन चढाउँदै हुनुहुन्छ, लेख्नुहोस्।');
            }
            if ($chk['needId'] && empty($_POST['id_checked'])) {
                throw new RuntimeException('Tick "Photo ID checked" first — this QR is not signed. / परिचयपत्र जाँचेर टिक गर्नुहोस्।');
            }
            if ($chk['cash'] > 0 && empty($_POST['cash_collected'])) {
                throw new RuntimeException('Collect ' . inr($chk['cash']) . ' cash and tick "Cash collected" first. / नगद लिएर टिक गर्नुहोस्।');
            }
            $boarded = BookingService::markBoarded($pnr, (int) $admin['id']);
            if ($boarded['state'] === 'boarded') {
                if ($chk['needNote']) {
                    Logger::audit('ticket.board_override', 'booking', $pnr, null,
                        ['flags' => $chk['flags'], 'trip' => $tripId], $note);
                }
                if ($chk['cash'] > 0) {
                    Logger::audit('ticket.board_cash', 'booking', $pnr, null,
                        ['cash' => $chk['cash']], 'Crew ticked cash collected at the gate (' . $me . ') — settle it in Payments → Cash Due');
                }
                if ($chk['needId']) {
                    Logger::audit('ticket.board_idcheck', 'booking', $pnr, null, ['src' => 'unsigned'], 'Photo ID checked at the gate by ' . $me);
                }
                $flash = ['ok', '✅ Boarded — passenger(s) checked in. Do not scan this ticket again.'];
            } else {
                scan_alert('already', $pnr, $me, $tripId);
                $flash = ['bad', '⛔ ALREADY BOARDED at ' . formatDate($boarded['scannedAt'], 'j M Y, g:i A')
                    . ' — this ticket has been used (scan #' . $boarded['scanCount'] . '). Do NOT board again.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- A scanned QR ------------------------------------------------
   POSTed, not put in the query string: the issued payload carries the
   passenger's phone number, and URLs end up in logs, history and referrers.
   Ticket::verifyQrPayload() takes the PNR from its known position AND checks
   the signature, so a doctored payload is refused instead of merely not found. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['qr'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — reload the page and scan again.'];
    } else {
        $raw = trim((string) $_POST['qr']);
        $via = 'qr';
        $audit = true;
        $verified = Ticket::verifyQrPayload($raw);
        if ($verified !== null) {
            $pnr = $verified;
            $qrState = 'signed';
        } elseif (str_starts_with($raw, 'SHG-TICKET|')) {
            // Right shape, wrong signature: altered, or from another system.
            $qrState = 'forged';
            $pp = explode('|', $raw);
            $pnr = (isset($pp[1]) && Security::isValidPnr((string) $pp[1])) ? strtoupper((string) $pp[1]) : '';
        } elseif (preg_match('/SHG-[A-Z0-9\-]{4,36}/i', $raw, $m)) {
            // The unsigned QR the app draws on screen — good enough to look
            // the booking up, but the crew must check a photo ID too.
            $pnr = strtoupper($m[0]);
            $qrState = 'unsigned';
        } else {
            $qrState = 'foreign';
            $pnr = '';
        }
    }
} elseif ($pnr !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    $audit = true;   // a typed / linked lookup
}

$b = ($pnr !== '' && Security::isValidPnr($pnr) && $qrState !== 'forged') ? BookingService::detail($pnr) : null;

/* ---- The verdict -------------------------------------------------- */
$chk = null;
$outcome = null;
if ($qrState === 'forged') {
    $outcome = 'forged';
} elseif ($qrState === 'foreign') {
    $outcome = 'foreign';
} elseif ($pnr !== '' && $b === null) {
    $outcome = 'not_found';
} elseif ($b !== null) {
    $chk = scan_check($b, $tripId, $qrState);
    $outcome = $chk['outcome'];
}
if ($boarded !== null) {
    $outcome = $boarded['state'] === 'boarded' ? 'boarded' : 'already';
}
$tone = match (true) {
    $outcome === null => '',
    $outcome === 'boarded' => 'green',
    $chk !== null && $boarded === null => $chk['tone'],
    default => in_array($outcome, ['valid'], true) ? 'green' : 'red',
};
if ($flash !== null && $boarded === null && $flash[0] === 'bad' && ($_POST['action'] ?? '') === 'board') {
    $tone = 'red';   // a refused board press: say it loudly
}

if ($audit && $outcome !== null) {
    Logger::audit('ticket.scan', 'booking', $pnr !== '' ? $pnr : '-', null, [
        'outcome' => $outcome, 'via' => $via, 'trip' => $tripId,
        'flags'   => $chk['flags'] ?? [],
    ], 'Gate scan by ' . $me);
    scan_alert($outcome, $pnr, $me, $tripId);
}

$secCode  = $b !== null ? TicketLive::securityCode((string) $b['pnr']) : null;
$liveCode = ($b !== null && TicketLive::on()) ? TicketLive::code((string) $b['pnr']) : null;
$liveLeft = TicketLive::validFor();

/* "My bus" picker: yesterday .. tomorrow. */
$trips = [];
try {
    $trips = Database::fetchAll(
        "SELECT s.id, s.travel_date, s.status, COALESCE(s.dep_time_override, r.dep_time) AS dep,
                r.from_city, r.to_city, bu.bus_name, bu.bus_number
           FROM schedules s
           JOIN routes r ON r.id = s.route_id
           LEFT JOIN buses bu ON bu.id = COALESCE(s.bus_id, r.bus_id)
          WHERE s.travel_date BETWEEN CURDATE() - INTERVAL 1 DAY AND CURDATE() + INTERVAL 1 DAY
          ORDER BY s.travel_date, dep LIMIT 40"
    );
} catch (Throwable $e) {
    $trips = [];
}

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

$words = static function (?string $o): array {
    if ($o === 'boarded') {
        return ['BOARDED · चढ्यो · सवार', 'Checked in. Do not scan this ticket again.'];
    }
    return SCAN_WORDS[$o ?? ''] ?? ['', ''];
};
[$headline, $subline] = $words($outcome);
if ($outcome === 'cod' && $chk !== null) {
    $headline = 'COLLECT ' . inr($chk['cash']) . ' CASH · नगद लिनुहोस्';
} elseif ($chk !== null && $chk['cash'] > 0 && $boarded === null) {
    // another flag leads (wrong date, photo ID ...) - the cash must still be said on the big screen
    $subline .= ' · 💵 COLLECT ' . inr($chk['cash']) . ' CASH · नगद लिनुहोस्';
}
$icon = ['green' => '✅', 'amber' => '⚠️', 'red' => '⛔'][$tone] ?? '';

admin_header('Scan Ticket', 'scan');
?>
<style>
.sc-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0 0 14px}
.sc-bar input[type=search]{flex:1 1 220px;min-width:0;min-height:48px;font:700 16px/1.2 var(--f-mono);text-transform:uppercase;padding:10px 12px;border-radius:12px;border:1.5px solid var(--line-control,var(--line));background:var(--surface,var(--card));color:var(--ink)}
.sc-bar .btn{min-height:48px;padding:10px 16px;font-size:15px}
.sc-trip{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 14px;font-size:13px;font-weight:700;color:var(--muted)}
.sc-trip select{flex:1 1 220px;min-width:0;min-height:44px;padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:var(--surface,var(--card));color:var(--ink);font-size:14px}
.sc-res{position:fixed;inset:0;z-index:80;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;padding:24px 18px calc(24px + env(safe-area-inset-bottom));text-align:center;color:#fff;cursor:pointer;animation:scIn .18s ease-out}
.sc-res[hidden]{display:none}
.sc-res.t-green{background:radial-gradient(120% 90% at 50% 0%,#24A866 0%,#0F6E3D 70%)}
.sc-res.t-amber{background:radial-gradient(120% 90% at 50% 0%,#E0A100 0%,#9A5B00 72%)}
.sc-res.t-red{background:radial-gradient(120% 90% at 50% 0%,#E0353F 0%,#8A1218 72%)}
.sc-res .ico{font-size:84px;line-height:1;filter:drop-shadow(0 6px 18px rgba(0,0,0,.35))}
.sc-res h2{margin:0;font:900 clamp(28px,8.5vw,46px)/1.08 var(--f-ui);letter-spacing:.01em;text-shadow:0 2px 10px rgba(0,0,0,.3);max-width:16ch}
.sc-res p{margin:0;font:700 17px/1.4 var(--f-ui);max-width:32ch;opacity:.95}
.sc-res .pnr{font:800 18px/1 var(--f-mono);letter-spacing:.06em;background:rgba(0,0,0,.22);padding:8px 14px;border-radius:12px}
.sc-res .codes{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:6px}
.sc-res .codes div{background:rgba(0,0,0,.24);border-radius:14px;padding:8px 14px;min-width:120px}
.sc-res .codes small{display:block;font:700 11px/1.2 var(--f-ui);letter-spacing:.08em;text-transform:uppercase;opacity:.85}
.sc-res .codes b{display:block;font:900 28px/1.1 var(--f-mono);letter-spacing:.14em}
.sc-res .tap{margin-top:14px;font:700 14px/1 var(--f-ui);background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.5);color:#fff;border-radius:999px;padding:12px 22px;min-height:48px}
@keyframes scIn{from{opacity:0;transform:scale(1.03)}to{opacity:1;transform:none}}
.sc-card{border-radius:18px;padding:14px 16px;margin:0 0 14px;border:1px solid var(--line);border-left:8px solid var(--sc);background:var(--card);box-shadow:var(--sh-1)}
.sc-card.t-green{--sc:var(--success);--sc-bg:var(--success-soft)}.sc-card.t-amber{--sc:var(--warning);--sc-bg:var(--warning-soft)}.sc-card.t-red{--sc:var(--danger);--sc-bg:var(--danger-soft)}
.sc-head{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sc-head h2{margin:0;font-size:20px;line-height:1.2;color:var(--sc);flex:1 1 100%;min-width:0}
.sc-head .pnr{font:800 15px/1 var(--f-mono)}
.sc-sub{margin:6px 0 0;font-size:14px;color:var(--ink)}
.sc-flags{display:flex;gap:6px;flex-wrap:wrap;margin:10px 0 0}
.sc-flag{font-size:12px;font-weight:800;padding:5px 10px;border-radius:999px;background:var(--sc-bg,var(--soft));color:var(--sc,var(--ink))}
.sc-flag.f-red{background:var(--danger-soft);color:var(--danger)}.sc-flag.f-amber{background:var(--warning-soft);color:var(--warning)}
.sc-cash{margin:12px 0 0;padding:14px;border-radius:14px;background:var(--warning-soft);color:var(--warning);font:900 22px/1.2 var(--f-ui);text-align:center;border:2px dashed var(--warning)}
.sc-cash small{display:block;font:700 12.5px/1.4 var(--f-ui);margin-top:4px}
.sc-codes{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0 0}
.sc-codes div{border-radius:14px;padding:10px 12px;background:var(--surface-sunken,var(--soft));border:1px solid var(--line)}
.sc-codes small{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
.sc-codes b{display:block;font:900 26px/1.15 var(--f-mono);letter-spacing:.14em;color:var(--ink)}
.sc-codes em{display:block;font-style:normal;font-size:11.5px;color:var(--muted);margin-top:2px}
.sc-form{display:flex;flex-direction:column;gap:10px;margin:14px 0 0}
.sc-tick{display:flex;gap:12px;align-items:flex-start;padding:12px;border-radius:14px;border:1.5px solid var(--line-strong,var(--line));background:var(--surface-sunken,var(--soft));font-size:15px;font-weight:700;cursor:pointer;line-height:1.35}
.sc-tick input{width:26px;height:26px;flex:0 0 auto;accent-color:var(--success);margin:0}
.sc-tick small{display:block;font-size:12px;font-weight:600;color:var(--muted)}
.sc-form textarea{min-height:64px;font:600 15px/1.35 var(--f-ui);padding:10px 12px;border-radius:12px;border:1.5px solid var(--line-control,var(--line));background:var(--surface,var(--card));color:var(--ink)}
.sc-board{min-height:58px;font:900 18px/1 var(--f-ui);border-radius:16px;border:0;color:#fff;cursor:pointer;background:linear-gradient(180deg,#1C8A55,#11703F);box-shadow:inset 0 1px 0 rgba(255,255,255,.3),0 3px 0 #0B5530,0 8px 16px -6px rgba(6,55,30,.5)}
.sc-board.amber{background:linear-gradient(180deg,#B45309,#92400E);box-shadow:inset 0 1px 0 rgba(255,255,255,.3),0 3px 0 #6B260F,0 8px 16px -6px rgba(124,45,18,.5)}
.sc-board[disabled]{opacity:.45;cursor:not-allowed}
.sc-board:active{transform:translateY(2px)}
.sc-report{margin:0 0 14px}
.sc-report summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:800;font-size:14px;color:var(--danger)}
.sc-report summary::-webkit-details-marker{display:none}
.sc-report form{display:flex;flex-direction:column;gap:8px;margin-top:10px}
.sc-report textarea{min-height:60px;padding:10px;border-radius:12px;border:1px solid var(--line);background:var(--surface,var(--card));color:var(--ink);font-size:14px}
#cam video{width:100%;max-width:440px;border-radius:16px;background:#000}
@media (prefers-reduced-motion:reduce){.sc-res{animation:none}}
html[data-motion="reduced"] .sc-res{animation:none}
</style>

<?php if ($flash !== null && $outcome === null): ?>
  <div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'bad' ?>" role="status"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<form class="sc-bar" method="get">
  <input type="search" name="pnr" placeholder="PNR · e.g. SHG-2026-00123" value="<?= Security::e($pnr) ?>" autocomplete="off" autocapitalize="characters" aria-label="Ticket PNR" <?= $outcome === null ? 'autofocus' : '' ?>>
  <?php if ($tripId > 0): ?><input type="hidden" name="trip" value="<?= $tripId ?>"><?php endif; ?>
  <button class="btn" type="submit">Verify · जाँच</button>
  <button class="btn ghost" type="button" id="camBtn">📷 Camera</button>
</form>

<form class="sc-trip" method="get" id="tripForm">
  <label for="tripSel">🚌 My bus · मेरो बस</label>
  <select name="trip" id="tripSel" onchange="this.form.submit()">
    <option value="0">Any bus — check the date only</option>
    <?php foreach ($trips as $tr): ?>
      <option value="<?= (int) $tr['id'] ?>" <?= $tripId === (int) $tr['id'] ? 'selected' : '' ?>><?= Security::e(date('D j M', strtotime((string) $tr['travel_date'])) . ' · ' . substr((string) $tr['dep'], 0, 5) . ' · ' . $tr['from_city'] . ' → ' . $tr['to_city'] . ($tr['bus_name'] ? ' · ' . $tr['bus_name'] : '') . ($tr['status'] === 'cancelled' ? ' · CANCELLED' : '')) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<form method="post" id="qrForm" style="display:none">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="qr" id="qrData">
  <input type="hidden" name="trip" value="<?= $tripId ?>">
</form>

<div id="cam" style="display:none;margin-bottom:16px"><video id="v" playsinline muted></video></div>

<?php if ($outcome !== null): ?>
  <!-- One full-screen answer, readable from arm's length. Tap to see details. -->
  <div class="sc-res t-<?= Security::e($tone) ?>" id="scRes" role="alertdialog" aria-labelledby="scResH" data-tone="<?= Security::e($tone) ?>">
    <div class="ico" aria-hidden="true"><?= $icon ?></div>
    <h2 id="scResH"><?= Security::e($flash !== null && $boarded === null && ($_POST['action'] ?? '') === 'board' ? 'NOT BOARDED · चढाइएन' : $headline) ?></h2>
    <p><?= Security::e($flash !== null && ($_POST['action'] ?? '') !== '' ? $flash[1] : $subline) ?></p>
    <?php if ($pnr !== ''): ?><div class="pnr"><?= Security::e($pnr) ?></div><?php endif; ?>
    <?php if ($b !== null && ($secCode !== null || $liveCode !== null) && $tone !== 'red'): ?>
      <div class="codes">
        <?php if ($secCode !== null): ?><div><small>Security code · सुरक्षा कोड</small><b><?= Security::e(substr($secCode, 0, 3) . ' ' . substr($secCode, 3)) ?></b></div><?php endif; ?>
        <?php if ($liveCode !== null): ?><div><small>Live code now · लाइभ कोड</small><b data-live-code><?= Security::e($liveCode) ?></b></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <button type="button" class="tap" id="scResClose">Details · विवरण ↓</button>
  </div>
<?php endif; ?>

<?php if ($outcome !== null && $b === null): ?>
  <div class="sc-card t-red">
    <div class="sc-head"><h2>⛔ <?= Security::e($headline) ?></h2><?php if ($pnr !== ''): ?><span class="pnr"><?= Security::e($pnr) ?></span><?php endif; ?></div>
    <p class="sc-sub"><?= Security::e($subline) ?> Do not board. Ask for a photo ID and call the office.</p>
  </div>
<?php elseif ($b !== null): ?>
  <?php
    $leg   = $chk['leg'] ?? ($b['legs'][0] ?? []);
    $tk    = $b['ticket'] ?? null;
    $flagW = ['trip_cancelled' => ['Trip cancelled', 'f-red'], 'wrong_bus' => ['Wrong bus', 'f-red'], 'wrong_date' => ['Travel date ' . formatDate($leg['travel_date'] ?? null), 'f-amber'],
              'unsigned' => ['Unsigned QR — check photo ID', 'f-amber'], 'cod' => ['Cash on boarding', 'f-amber']];
  ?>
  <div class="sc-card t-<?= Security::e($tone ?: 'green') ?>" id="scCard">
    <div class="sc-head">
      <h2><?= $icon ?> <?= Security::e($words($outcome)[0] ?: $headline) ?></h2>
      <span class="pnr"><?= Security::e((string) $b['pnr']) ?></span> <?= admin_pill((string) $b['status']) ?>
    </div>
    <p class="sc-sub"><?= Security::e($flash !== null ? $flash[1] : $subline) ?></p>
    <?php if ($outcome === 'already' && $tk !== null && !empty($tk['scanned_at'])): ?>
      <p class="sc-sub">First boarded <?= Security::e(formatDate((string) $tk['scanned_at'], 'j M Y, g:i A')) ?><?= !empty($tk['scanned_by_name']) ? ' by ' . Security::e((string) $tk['scanned_by_name']) : '' ?> · scanned <?= (int) $tk['scan_count'] ?> time<?= (int) $tk['scan_count'] === 1 ? '' : 's' ?>.</p>
    <?php endif; ?>
    <?php if ($chk !== null && $chk['flags'] !== []): ?>
      <div class="sc-flags"><?php foreach ($chk['flags'] as $f): ?><span class="sc-flag <?= $flagW[$f][1] ?? '' ?>"><?= Security::e($flagW[$f][0] ?? $f) ?></span><?php endforeach; ?></div>
    <?php endif; ?>
    <?php if ($chk !== null && $chk['cash'] > 0 && $boarded === null): ?>
      <div class="sc-cash">💵 COLLECT <?= Security::e(inr($chk['cash'])) ?> CASH<small>नगद लिनुहोस् · नकद लें — then tick "cash collected" below</small></div>
    <?php endif; ?>
    <?php if ($secCode !== null || $liveCode !== null): ?>
      <div class="sc-codes">
        <?php if ($secCode !== null): ?><div><small>Security code · सुरक्षा कोड</small><b><?= Security::e(substr($secCode, 0, 3) . ' ' . substr($secCode, 3)) ?></b><em>Must match the paper / downloaded ticket</em></div><?php endif; ?>
        <?php if ($liveCode !== null): ?><div><small>Live code now · लाइभ कोड</small><b data-live-code><?= Security::e($liveCode) ?></b><em>Passenger's screen must show this · <span data-live-left><?= (int) $liveLeft ?></span> s</em></div><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($chk !== null && $chk['canBoard'] && $boarded === null): ?>
      <form method="post" class="sc-form" id="boardForm">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="board">
        <input type="hidden" name="pnr" value="<?= Security::e((string) $b['pnr']) ?>">
        <input type="hidden" name="trip" value="<?= $tripId ?>">
        <?php if ($chk['needId']): ?><input type="hidden" name="src" value="unsigned">
          <label class="sc-tick"><input type="checkbox" name="id_checked" value="1" data-req><span>Photo ID checked — the name matches <small>परिचयपत्र जाँचें, नाम मिल्यो</small></span></label>
        <?php endif; ?>
        <?php if ($chk['cash'] > 0): ?>
          <label class="sc-tick"><input type="checkbox" name="cash_collected" value="1" data-req><span>Cash <?= Security::e(inr($chk['cash'])) ?> collected <small>नगद लिइयो — the office settles it in Payments → Cash Due</small></span></label>
        <?php endif; ?>
        <?php if ($chk['needNote']): ?>
          <label class="sc-tick" style="flex-direction:column;align-items:stretch;cursor:auto"><span>Board anyway? Write why <small>किन चढाउँदै हुनुहुन्छ? (saved in the audit log)</small></span>
            <textarea name="override_note" minlength="4" maxlength="250" data-req-text placeholder="e.g. Office told me on the phone to take him on today's bus"></textarea></label>
        <?php endif; ?>
        <button class="sc-board<?= $tone === 'green' ? '' : ' amber' ?>" type="submit" id="boardBtn">✅ Mark as boarded · चढ्यो</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h2>Journey · यात्रा</h2>
    <table>
      <tr><th>Route</th><td><?= Security::e(($leg['from_city'] ?? '—') . ' → ' . ($leg['to_city'] ?? '—')) ?></td></tr>
      <tr><th>Travel date</th><td><?= Security::e(formatDate($leg['travel_date'] ?? null)) ?><?= !empty($leg['dep_time']) ? ' · ' . Security::e(substr((string) $leg['dep_time'], 0, 5)) : '' ?></td></tr>
      <tr><th>Bus</th><td><?= Security::e(trim(($leg['bus_name'] ?? '') . ' ' . ($leg['bus_number'] ?? '')) ?: '—') ?></td></tr>
      <tr><th>Boarding</th><td><?= Security::e((string) ($leg['boarding_stop'] ?? '—')) ?></td></tr>
      <tr><th>Seats</th><td class="mono"><?= Security::e(implode(', ', array_map(static fn($s) => Seats::displayLabel((string) $s), $b['seats'] ?? []))) ?></td></tr>
      <tr><th>Contact</th><td class="mono"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td></tr>
    </table>
  </div>
  <div class="panel">
    <h2>Passengers (<?= count($b['passengers']) ?>) · यात्रु</h2>
    <table>
      <thead><tr><th>Seat</th><th>Name</th><th>Age</th><th>Gender</th></tr></thead>
      <tbody>
      <?php foreach ($b['passengers'] as $p): ?>
        <tr><td class="mono"><?= Security::e(Seats::displayLabel((string) $p['seat_no'])) ?></td>
            <td><?= Security::e((string) $p['full_name']) ?></td>
            <td><?= $p['age'] !== null ? (int) $p['age'] : '—' ?></td>
            <td><?= Security::e((string) ($p['gender'] ?? '—')) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($outcome !== null && $outcome !== 'valid' && $outcome !== 'boarded'): ?>
<details class="sc-report"<?= in_array($outcome, SCAN_BAD, true) ? ' open' : '' ?>>
  <summary>🛡️ Report to office · कार्यालयलाई खबर</summary>
  <form method="post">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="report">
    <input type="hidden" name="pnr" value="<?= Security::e($pnr) ?>">
    <input type="hidden" name="trip" value="<?= $tripId ?>">
    <textarea name="note" maxlength="480" placeholder="What happened? e.g. passenger shows an edited screenshot, name does not match ID"></textarea>
    <button class="btn bad" type="submit">Send report</button>
  </form>
</details>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js" defer></script>
<script>
(function () {
  'use strict';
  var res = document.getElementById('scRes');
  var reduced = matchMedia('(prefers-reduced-motion: reduce)').matches || document.documentElement.getAttribute('data-motion') === 'reduced';

  /* sound + buzz for the verdict: green = two bright tones, amber = two
     mid beeps, red = a low buzz. Silent when the browser blocks audio. */
  function sound(tone) {
    try {
      var AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
      var ac = new AC(), t0 = ac.currentTime + 0.02;
      var plan = tone === 'green' ? [[880, 0, .12, 'sine'], [1320, .14, .18, 'sine']]
        : tone === 'amber' ? [[660, 0, .14, 'triangle'], [660, .22, .14, 'triangle']]
        : [[196, 0, .32, 'square'], [165, .38, .42, 'square']];
      plan.forEach(function (p) {
        var o = ac.createOscillator(), g = ac.createGain();
        o.type = p[3]; o.frequency.value = p[0];
        g.gain.setValueAtTime(0.0001, t0 + p[1]);
        g.gain.exponentialRampToValueAtTime(tone === 'red' ? 0.18 : 0.25, t0 + p[1] + 0.02);
        g.gain.exponentialRampToValueAtTime(0.0001, t0 + p[1] + p[2]);
        o.connect(g); g.connect(ac.destination); o.start(t0 + p[1]); o.stop(t0 + p[1] + p[2] + 0.05);
      });
      setTimeout(function () { try { ac.close(); } catch (e) {} }, 1500);
    } catch (e) {}
  }
  if (res) {
    var tone = res.getAttribute('data-tone');
    sound(tone);
    try { if (navigator.vibrate) navigator.vibrate(tone === 'green' ? 70 : tone === 'amber' ? [120, 80, 120] : [220, 90, 220, 90, 420]); } catch (e) {}
    var close = function () { res.hidden = true; var c = document.getElementById('scCard'); if (c) c.scrollIntoView({ block: 'start', behavior: reduced ? 'auto' : 'smooth' }); };
    res.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' || e.key === 'Enter') { if (!res.hidden) { e.preventDefault(); close(); } } });
    // a green verdict steps aside by itself after a moment; amber / red wait for a tap
    if (tone === 'green') setTimeout(function () { if (!res.hidden) close(); }, 2600);
  }

  /* the board button waits for the required ticks / reason */
  var bf = document.getElementById('boardForm');
  if (bf) {
    var btn = document.getElementById('boardBtn');
    var sync = function () {
      var ok = true;
      bf.querySelectorAll('[data-req]').forEach(function (c) { if (!c.checked) ok = false; });
      bf.querySelectorAll('[data-req-text]').forEach(function (t) { if (t.value.trim().length < 4) ok = false; });
      btn.disabled = !ok;
    };
    bf.addEventListener('input', sync); bf.addEventListener('change', sync); sync();
    bf.addEventListener('submit', function () { btn.disabled = true; btn.textContent = '…'; });
  }

  /* live code: count down, then ask the server for the next one */
  var liveEls = document.querySelectorAll('[data-live-code]'), leftEl = document.querySelector('[data-live-left]');
  var livePnr = <?= json_encode($b !== null && $liveCode !== null ? (string) $b['pnr'] : '') ?>;
  if (livePnr && liveEls.length) {
    var left = <?= (int) $liveLeft ?>;
    setInterval(function () {
      left -= 1;
      if (leftEl) leftEl.textContent = Math.max(0, left);
      if (left <= 0) {
        left = 30;
        fetch('?live=' + encodeURIComponent(livePnr), { credentials: 'same-origin', cache: 'no-store' })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (j && j.ok) { liveEls.forEach(function (el) { el.textContent = j.code; }); left = j.validFor || 30; } })
          .catch(function () { liveEls.forEach(function (el) { el.textContent = '····'; }); });
      }
    }, 1000);
  }

  /* camera: hand the whole payload to the server, which checks its signature */
  document.getElementById('camBtn').addEventListener('click', async function () {
    var wrap = document.getElementById('cam'), video = document.getElementById('v');
    if (wrap.style.display === 'none') {
      try {
        var stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
        video.srcObject = stream; await video.play(); wrap.style.display = '';
        var canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
        var tick = function () {
          if (wrap.style.display === 'none') { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
          if (video.readyState === video.HAVE_ENOUGH_DATA && window.jsQR) {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            var img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            var code = window.jsQR(img.data, img.width, img.height);
            if (code && code.data) {
              stream.getTracks().forEach(function (t) { t.stop(); });
              try { if (navigator.vibrate) navigator.vibrate(30); } catch (e) {}
              document.getElementById('qrData').value = code.data;
              document.getElementById('qrForm').submit();
              return;
            }
          }
          requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);
      } catch (e) { alert('Camera unavailable: ' + e.message); }
    } else {
      wrap.style.display = 'none';
      if (video.srcObject) { video.srcObject.getTracks().forEach(function (t) { t.stop(); }); }
    }
  });
})();
</script>
<?php
admin_footer();
