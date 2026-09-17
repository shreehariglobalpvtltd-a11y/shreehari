<?php
/**
 * admin/seatmap.php — Seat Map editor (Part 2 · Feature C).
 *
 * Deep per-seat control for any bus/date: see every berth's status and who
 * holds it (customer / named agent / counter), take a broken berth out of
 * service, force-release a stuck hold, sell a walk-in seat at the counter,
 * and flag a seat female-preferred. Every mutation runs through the same
 * transactional seat core as online booking (so an override can never
 * double-book or break the gender rule) and is written to the audit log.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/tripstatus.php';   // TripStatus::editableFor() (5 Sep 2026: explicit, not via another include)
$admin = admin_boot('schedules.view');

$base  = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash = null;

/** Default female-preferred seed, matching Seats::femaleSeats(). */
function seatmap_female_default(): array
{
    return ['seater' => ['1A', '1B', '2A', '2B'], 'sleeper' => ['L1', 'L2', 'L3']];
}

/** Toggle a seat in the female-preferred setting for its coach type. */
function seatmap_toggle_female(string $coach, string $seat, bool $on): void
{
    $map  = Settings::getArray('female_seats', seatmap_female_default());
    $list = array_values(array_unique(array_map('strval', $map[$coach] ?? [])));

    if ($on) {
        if (!in_array($seat, $list, true)) {
            $list[] = $seat;
        }
    } else {
        $list = array_values(array_filter($list, static fn($s) => $s !== $seat));
    }

    $map[$coach] = $list;
    Settings::set('female_seats', $map, 'json', 'booking', true);
    Logger::audit('seat.female_pref', 'settings', 'female_seats', null, ['coach' => $coach, 'seat' => $seat, 'on' => $on], '');
}

/**
 * Sell one seat from the map. A thin adapter over BookingService::create()
 * with a seller context (3 Sep 2026) — the very same path the customer app
 * and counter mode use, so pricing, seat + gender rules, ticket and
 * commission can never drift between the three panels.
 */
function seatmap_counter_book(array $route, int $scheduleId, string $date, string $seat, array $post, int $adminId, string $source = 'counter'): string
{
    // Phase 6 — edit-window gate: refuse counter sales on non-bookable trips.
    // Counter gate is wider than the website's — a walk-in during the last
    // 30 min (Boarding) is the everyday "late booking" the desk needs.
    // Counter grace: staff/agents may still sell on a departed bus for 24h
    // after its scheduled departure (late/missed passenger). SOLD_OUT and
    // CANCELLED stay closed; superadmin bypasses everything.
    $gate = TripStatus::counterBookableWithin($scheduleId);
    if (!($gate['ok'] ?? false) && !Auth::isSuperadmin()) {
        throw new RuntimeException('This trip is ' . ($gate['label'] ?? 'closed') . ' — counter booking refused.');
    }

    $name = trim(Security::clean((string) ($post['pax_name'] ?? ''), 120));
    if ($name === '') {
        throw new RuntimeException('Enter the passenger name for the counter booking.');
    }
    $gender = in_array($post['pax_gender'] ?? '', ['Male', 'Female', 'Other'], true) ? (string) $post['pax_gender'] : null;

    $booking = BookingService::create([
        'routeId'       => (int) $route['id'],
        // The schedule the operator is LOOKING AT. Without it create() resolved
        // route+date back to slot 1, so on a date carrying an extra bus every
        // sale made from that extra bus's seat map — gated, flashed and drawn
        // as the extra bus — was written to the DAILY bus instead: wrong coach,
        // wrong manifest, and a berth sold on a bus the passenger never saw.
        // The customer path always forwarded it (api/book.php, QuickTicket).
        'scheduleId'    => $scheduleId,
        'travelDate'    => $date,
        'seats'         => [$seat],
        'passengers'    => [['seat' => $seat, 'name' => $name, 'age' => null, 'gender' => $gender]],
        'contact'       => ['phone' => (string) ($post['pax_phone'] ?? '')],
        'bookingMode'   => ((string) ($route['coach_type'] ?? '') === 'sleeper') ? 'sharing' : null,
        'boarding'      => '',
        'originTown'    => (string) ($route['from_city'] ?? ''),
        'paymentMethod' => 'cash',
        'isCod'         => false,
    ], [
        'adminId'       => $adminId,
        'source'        => $source,
        'paymentMethod' => 'cash',
        // Counter discount: flat ₹ or %, capped server-side (counter_max_discount_pct).
        'discountType'  => (string) ($post['discount_type'] ?? ''),
        'discountValue' => (float) ($post['discount_value'] ?? 0),
    ]);

    return (string) ($booking['pnr'] ?? '');
}

/* ---- Selection --------------------------------------------------- */
$routes = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city, coach_type, base_fare, is_active
       FROM routes ORDER BY is_active DESC, sort_order, id"
);

/* ?sid= (Bus Calendar, 5 Sep 2026): an extra bus on the same date is
   addressed by its own schedule id; route + date follow from that row.
   Without it the daily bus (slot 1) for route + date is shown, as before. */
$sidReq = (int) ($_REQUEST['sid'] ?? 0);
$sidRow = $sidReq > 0 ? Database::fetch('SELECT id, route_id, travel_date, slot FROM schedules WHERE id = :id', ['id' => $sidReq]) : null;
if ($sidRow === null) { $sidReq = 0; }
$routeId = $sidRow !== null ? (int) $sidRow['route_id'] : (int) ($_REQUEST['route'] ?? ($routes[0]['id'] ?? 0));
$date    = $sidRow !== null ? (string) $sidRow['travel_date'] : Security::clean($_REQUEST['date'] ?? todayISO(), 10);
if (!Security::isValidDate($date)) {
    $date = todayISO();
}

$route = null;
foreach ($routes as $r) {
    if ((int) $r['id'] === $routeId) { $route = $r; break; }
}

/* ---- Actions ----------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif ($route === null) {
        $flash = ['bad', 'Choose a route first.'];
    } else {
        try {
            Auth::requireAdmin('schedules.edit');
            $schedule   = Seats::scheduleFor($routeId, $date, $sidReq > 0 ? $sidReq : null);
            $scheduleId = (int) $schedule['id'];
            // The coach THIS departure runs — an extra bus may carry its own
            // layout (4 Sep 2026); the route's type for every ordinary row.
            $coach      = Seats::effectiveCoach($schedule, $route);
            $action     = (string) ($_POST['action'] ?? '');
            $seat       = strtoupper(Security::clean($_POST['seat'] ?? '', 10));
            // Display label for flash messages only; $seat itself stays canonical
            // for every Seats:: call below (the map is the sharing namespace).
            $seatLbl    = Seats::displayLabel($seat, $coach, 'sharing');

            if ($action === 'block') {
                Seats::blockSeat($scheduleId, $seat, (string) ($_POST['reason'] ?? ''), (int) $admin['id']);
                $flash = ['ok', 'Seat ' . $seatLbl . ' taken out of service.'];
            } elseif ($action === 'unblock') {
                Seats::unblockSeat($scheduleId, $seat, (int) $admin['id']);
                $flash = ['ok', 'Seat ' . $seatLbl . ' is back in service.'];
            } elseif ($action === 'release') {
                $n = Seats::adminReleaseHold($scheduleId, $seat, (int) $admin['id']);
                $flash = ['ok', $n ? ('Hold on ' . $seatLbl . ' released.') : ('No active hold on ' . $seatLbl . '.')];
            } elseif ($action === 'female') {
                // This writes the GLOBAL female_seats setting — it changes
                // every coach of this type on every future trip, not just the
                // one on screen. schedules.edit is authority over a trip, so
                // it is not enough here; a counter agent must not be able to
                // reshape company-wide seating policy from the seat map.
                Auth::requireAdmin('routes.edit');
                seatmap_toggle_female($coach, $seat, ($_POST['on'] ?? '1') === '1');
                $flash = ['ok', 'Female-preferred ' . (($_POST['on'] ?? '1') === '1' ? 'set on ' : 'cleared from ') . $seatLbl . '.'];
            } elseif ($action === 'counter') {
                $src   = (($admin['role'] ?? '') === 'agent') ? 'agent' : 'counter';
                $pnr   = seatmap_counter_book($route, $scheduleId, $date, $seat, $_POST, (int) $admin['id'], $src);
                $flash = ['ok', ucfirst($src) . ' booking ' . $pnr . ' created on seat ' . $seatLbl . '.'];
            } elseif ($action === 'transfer') {
                // Phase 6 — no transfers on completed/arrived trips.
                $tGate = TripStatus::editableFor($scheduleId);
                if (!($tGate['editable'] ?? false) && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Trip is ' . ($tGate['label'] ?? 'closed') . ' — seat transfers are locked.');
                }
                $to    = strtoupper(Security::clean($_POST['to_seat'] ?? '', 10));
                $res   = Seats::transferSeat($scheduleId, $seat, $to, $coach, (int) $admin['id']);
                $flash = ['ok', 'Moved ' . ($res['passenger'] ?: $res['pnr'])
                    . ' from ' . Seats::displayLabel((string) $res['from'], $coach, 'sharing')
                    . ' → ' . Seats::displayLabel((string) $res['to'], $coach, 'sharing')
                    . ' (' . $res['pnr'] . ').'];
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

/* ---- Load the map for display ------------------------------------ */
$coach       = $route ? (string) $route['coach_type'] : 'sleeper';
$scheduleId  = 0;
$map         = [];
$tripBookings = [];
if ($route !== null) {
    $schedule   = Seats::scheduleFor($routeId, $date, $sidReq > 0 ? $sidReq : null);
    $scheduleId = (int) $schedule['id'];
    $coach      = Seats::effectiveCoach($schedule, $route);   // this departure's own coach
    $map        = Seats::adminSeatMap($scheduleId, $coach);

    /* Booking list for this trip — same query shape as trip-dashboard.php
       so the two screens tell the same story. Scoped like the seat map:
       a superadmin sees every booking, an agent only their own sales. */
    try {
        $tripBookings = Database::fetchAll(
            "SELECT DISTINCT b.pnr, b.status, b.total_amount, b.source, b.sold_by_admin_id,
                    b.contact_phone, b.is_cod, b.created_at, b.booking_mode,
                    GROUP_CONCAT(DISTINCT bs.seat_no ORDER BY bs.seat_no SEPARATOR ', ') AS seats,
                    (SELECT full_name FROM booking_passengers bp
                      WHERE bp.booking_id = b.id AND bp.is_primary = 1 LIMIT 1) AS passenger_name,
                    p.method AS pay_method, p.status AS pay_status
               FROM booking_seats bs
               JOIN bookings b ON b.id = bs.booking_id
               LEFT JOIN payments p ON p.booking_id = b.id
                    AND p.id = (SELECT MAX(p3.id) FROM payments p3 WHERE p3.booking_id = b.id)
              WHERE bs.schedule_id = :sid2 AND bs.released_at IS NULL
              GROUP BY b.id
              ORDER BY b.created_at ASC",
            ['sid2' => $scheduleId]
        );
    } catch (Throwable $e) {
        $tripBookings = [];
    }

    // Same privacy split as the seat map: a regular agent only sees their
    // own sales, a superadmin (scope null) sees every row on the trip.
    $scopeId = Auth::bookingScopeAdminId();
    if ($scopeId !== null) {
        $tripBookings = array_values(array_filter(
            $tripBookings,
            static fn(array $b): bool => (int) ($b['sold_by_admin_id'] ?? 0) === $scopeId
        ));
    }
}
$femalePref = Settings::getArray('female_seats', seatmap_female_default())[$coach] ?? [];
$femalePref = array_map('strval', is_array($femalePref) ? $femalePref : []);

// SHG-### agent-code map for tooltips + the bookings-on-this-trip panel.
// Loaded once — every downstream use is an array lookup, never a query.
$agentCodes = Settings::getArray('agent_codes', []);

$counts = ['open' => 0, 'booked' => 0, 'held' => 0, 'blocked' => 0, 'staff' => 0];
foreach ($map as $s) { $counts[$s['status']] = ($counts[$s['status']] ?? 0) + 1; }
$openSeats   = array_values(array_filter(array_keys($map), static fn($s) => $map[$s]['status'] === 'open'));
$bookedSeats = array_values(array_filter(array_keys($map), static fn($s) => $map[$s]['status'] === 'booked'));

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$canEdit = Auth::can('schedules.edit');
// Female-preferred is company-wide seating policy, not a property of this
// trip — so it needs routes.edit, which a counter agent does not hold. Kept
// separate from $canEdit so the control is hidden rather than 403-ing.
$canPolicy = Auth::can('routes.edit');

/* ---- Visual map: one layout, both surfaces (unified 29 Aug 2026) --
 *
 * `Seats::layoutFor()` returns the same geometry the customer sees in
 * assets/js/06-results.js — decks[i].rows[j].{left,aisle,right} — so
 * admin and customer draw the SAME physical arrangement rather than the
 * old admin-only 2-column flat grid. Admin still overlays its own
 * decoration (data-pnr / agent code / block / release / transfer menu)
 * via seatmap_seat_div() below — that helper is untouched.
 *
 * 'sharing' is passed because the physical bus is a 72-berth coach; the
 * private mode is a customer preference that reserves pairs but sells
 * from the same L1..L36 / U1..U36 namespace. Admin sees the whole
 * coach at once; every berth a customer could book is on this map.
 */
$layout = Seats::layoutFor($coach, 'sharing');
$hasVisual = count($map) > 0 && !empty($layout['decks']);

/** Status → [label, bg, fg] pill colours. */
function seatmap_status_pill(string $status): string
{
    $map = [
        'open'    => ['Open',    '#e7f6ee', '#0a6b3b'],
        'booked'  => ['Booked',  '#e2ecfb', '#1c3b72'],
        'held'    => ['Held',    '#fff4d1', '#8a6d00'],
        'blocked' => ['Blocked', '#e7e7ea', '#333'],
        'staff'   => ['Emergency', '#fff176', '#5c4d00'],
    ];
    [$label, $bg, $fg] = $map[$status] ?? [ucfirst($status), '#eee', '#333'];
    return '<span class="pill" style="background:' . $bg . ';color:' . $fg . '">' . $label . '</span>';
}

/** Gender-lock → small badge, or '' for none. */
function seatmap_lock_badge(string $lock): string
{
    $m = [
        'female_only'   => ['#FCE7F1', '#B0165F', '♀ women-only'],
        'male_only'     => ['#E3EDFC', '#12408C', '♂ men-only'],
        'mixed_allowed' => ['#efeaff', '#5a3fb0', '👥 group'],
    ];
    if (!isset($m[$lock])) { return '<span class="muted">—</span>'; }
    [$bg, $fg, $t] = $m[$lock];
    return '<span class="pill" style="background:' . $bg . ';color:' . $fg . '">' . $t . '</span>';
}

/** Format the SHG-### code for one admin id, '' if none. */
function seatmap_agent_code(array $codes, ?int $adminId): string
{
    if ($adminId === null || $adminId <= 0 || !isset($codes[$adminId])) {
        return '';
    }
    return 'SHG-' . str_pad((string) $codes[$adminId], 4, '0', STR_PAD_LEFT);
}

/** Source badge for the bookings panel — mirrors trip-dashboard.php. */
function seatmap_source_badge(string $source): string
{
    $map = [
        'web'     => ['Online',  'td-src-online'],
        'app'     => ['App',     'td-src-online'],
        'agent'   => ['Agent',   'td-src-agent'],
        'counter' => ['Counter', 'td-src-counter'],
        'admin'   => ['Admin',   'td-src-admin'],
    ];
    [$label, $cls] = $map[$source] ?? [ucfirst($source), 'td-src-online'];
    return '<span class="pill ' . $cls . '">' . Security::e($label) . '</span>';
}

/** Render one seat cell for the visual map. */
function seatmap_seat_div(array $s, array $femalePref, array $agentCodes, string $coach = 'sleeper'): string
{
    $sid    = $s['seat'];
    // Passenger-facing row-letter grid id (LA1/UA1). The whole admin map is the
    // physical/sharing namespace, so 'sharing' is the right mode here. data-seat
    // below stays the canonical $sid — every action posts and matches on that.
    $disp   = Seats::displayLabel((string) $sid, $coach, 'sharing');
    $status = (string) $s['status'];
    $cls    = 'seat seat-' . $status;
    if (in_array($sid, $femalePref, true)) {
        $cls .= ' seat-female';
    }

    // Tooltip — respects same privacy as table (mine vs. not-mine)
    $tip = $disp;
    switch ($status) {
        case 'open':    $tip .= ' · Available'; break;
        case 'booked':
            if (!empty($s['mine'])) {
                // "L4 · Bishal Sharma · SHG-027 (Rakesh Agent)"  — passenger
                // then the seller. For agent sales we prefer the SHG-###
                // code and unwrap the agent name from the channel; for web /
                // app / counter we just print the channel label as-is.
                $tip .= ' · ' . ($s['passenger'] ?? 'Booked');
                $code = (string) ($s['agentCode'] ?? '');
                if ($code === '' && !empty($s['soldById'])) {
                    $code = seatmap_agent_code($agentCodes, (int) $s['soldById']);
                }
                $channel = (string) ($s['channel'] ?? '');
                if (strncmp($channel, 'Agent:', 6) === 0) {
                    $agentName = trim(substr($channel, 6));
                    if ($code !== '') {
                        $tip .= ' · ' . $code . ($agentName !== '' ? ' (' . $agentName . ')' : '');
                    } elseif ($agentName !== '') {
                        $tip .= ' · ' . $agentName;
                    }
                } elseif ($channel !== '') {
                    $tip .= ' · ' . $channel;
                } elseif ($code !== '') {
                    $tip .= ' · ' . $code;
                }
            } else {
                $tip .= ' · Sold';
                if (!empty($s['gender'])) $tip .= ' · ' . $s['gender'];
            }
            break;
        case 'held':
            $until = !empty($s['holdUntil']) ? formatTime(substr((string) $s['holdUntil'], 11, 5)) : '';
            $tip  .= ' · Held' . ($until ? ' until ' . $until : '');
            break;
        case 'blocked': $tip .= ' · Out of service'; break;
        case 'staff':   $tip .= ' · Staff reserved'; break;
        default:        $tip .= ' · ' . ucfirst($status);
    }

    // Short label inside the seat cell
    $info = '';
    switch ($status) {
        case 'booked':
            if (!empty($s['mine']) && !empty($s['passenger'])) {
                $info = explode(' ', trim((string) $s['passenger']))[0];
            }
            break;
        case 'held':    $info = '⏳'; break;
        case 'blocked': $info = '🚫'; break;
        case 'staff':   $info = '🛡️'; break;
    }

    // Pickup short code (MSN, STV…) — precomputed by Seats::adminSeatMap via
    // Boarding::stopDisplay, never re-derived here: STOP_CODES lives in
    // includes/boarding.php and its one JS mirror, and admin pages do not load
    // that bundle. stopHue is the stop's position along the route, -1 = none.
    $stopCode = (string) ($s['stop'] ?? '');
    $stopHue  = (int) ($s['stopHue'] ?? -1);
    if ($stopCode !== '') {
        $tip .= ' · ' . $stopCode . (($s['stopName'] ?? '') !== '' ? ' (' . $s['stopName'] . ')' : '')
              . (($s['stopTime'] ?? null) !== null ? ' @ ' . $s['stopTime'] : '');
        if ($stopHue >= 0) {
            $cls .= ' stop-c' . ($stopHue % 8);
        }
    }

    $html  = '<div class="' . Security::e($cls) . '"';
    $html .= ' data-seat="' . Security::e($sid) . '"';
    // Display label for the JS poller/popover; data-seat stays canonical.
    $html .= ' data-seat-label="' . Security::e($disp) . '"';
    $html .= ' data-status="' . Security::e($status) . '"';
    $html .= ' data-stop="' . Security::e($stopCode) . '"';
    // For the click-a-seat action menu: expose ownership + PNR, but ONLY for a
    // berth the viewer is allowed to see (their own sale, or superadmin). This
    // mirrors the table's privacy split — a counter agent never gets another
    // agent's PNR in the DOM, so data-mine=0 seats offer no passenger actions.
    if ($status === 'booked') {
        $mine = !empty($s['mine']);
        $html .= ' data-mine="' . ($mine ? '1' : '0') . '"';
        if ($mine && !empty($s['pnr'])) {
            $html .= ' data-pnr="' . Security::e((string) $s['pnr']) . '"';
        }
    }
    // role/tabindex/aria-label: the cells were click-only <div>s carrying a
    // title, so the map could not be reached or read without a mouse, and an
    // open berth vs someone else's booked berth differed by hue alone. The
    // label repeats the tooltip, which already names the status and the stop.
    $html .= ' role="button" tabindex="0"';
    $html .= ' aria-label="' . Security::e($tip) . '"';
    $html .= ' title="' . Security::e($tip) . '">';
    $html .= '<span class="seat-no">' . Security::e($disp) . '</span>';
    // The pickup code sits directly under the seat number, deliberately larger
    // and heavier than the passenger name below it: at a stop the desk reads
    // "who gets on here", and that is this code, not the name.
    if ($stopCode !== '') {
        $html .= '<span class="seat-stop">' . Security::e($stopCode) . '</span>';
    }
    if ($info !== '') {
        $html .= '<span class="seat-info">' . Security::e($info) . '</span>';
    }
    $html .= '</div>';
    return $html;
}

admin_header('Seat Map', 'seatmap');

?>
<style>
/* ── Visual seat map ────────────────────────────────────────────────── */
/* Grid columns match the number of decks (2 for sleeper, 1 for seater
   or any single-deck coach). auto-fit + minmax collapses to one column
   on narrow screens for free. */
.seatmap-visual{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:22px}
.deck{background:var(--card,#fff);border:1px solid var(--line,#e5e7eb);border-radius:14px;padding:16px}
.deck h3{margin:0 0 12px;font-size:15px;color:var(--ink,#1f2937)}
/* Row-driven grid — one .seat-row-adm per physical row on the coach,
   mirroring what the customer sees. left seats | aisle | right seats. */
.deck-grid{display:flex;flex-direction:column;gap:6px}
.seat-row-adm{display:flex;align-items:stretch;gap:4px}
.seat-row-adm .seat-group{display:flex;gap:4px;flex:1 1 auto;min-width:0}
.seat-row-adm .seat-group .seat{flex:1 1 0;min-width:0}
.seat-row-adm .seat-group.right{justify-content:flex-end}
.seat-row-adm .aisle-adm{flex:0 0 14px;text-align:center;color:var(--mut,#666);font-size:10px;
  display:flex;align-items:center;justify-content:center;user-select:none}
.seat{border:2px solid transparent;border-radius:8px;padding:8px 6px;text-align:center;cursor:pointer;
  position:relative;min-height:52px;display:flex;flex-direction:column;align-items:center;justify-content:center;
  transition:transform .1s,box-shadow .1s}
.seat:hover{transform:scale(1.05);box-shadow:0 2px 8px rgba(0,0,0,.15)}
.seat-no{font-weight:800;font-size:13px;font-family:ui-monospace,'Cascadia Code',Consolas,monospace}
.seat-info{font-size:10px;color:inherit;opacity:.8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}
.seat:focus-visible{outline:3px solid #2563eb;outline-offset:2px}

/* ---- Pickup point: the SECOND channel ------------------------------------
   Status keeps the fill colour (the map's first job is still availability);
   the pickup adds a left stripe + a code chip, so a berth carries both facts
   at once and neither is told by hue alone. --stop-c is set by the .stop-cN
   classes below, whose N is the stop's POSITION ALONG THE ROUTE, so no two
   stops on one bus share a colour and the map reads in travel order. */
.seat-stop{font-size:11px;font-weight:800;letter-spacing:.4px;line-height:1.35;
  font-family:ui-monospace,'Cascadia Code',Consolas,monospace;
  padding:0 5px;border-radius:999px;margin-top:1px;max-width:100%;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  background:var(--stop-c,transparent);color:var(--stop-ink,inherit)}
.seat[data-stop]:not([data-stop=""]){box-shadow:inset 4px 0 0 0 var(--stop-c,transparent)}
.seat[data-stop]:not([data-stop=""]).seat-female{box-shadow:inset 4px 0 0 0 var(--stop-c,transparent),inset 0 0 0 1px #f472b6}
/* Taller tile: seat id + code chip + name is three rows, and the code must not
   squeeze the name into an ellipsis on a ~50px-wide cell. */
.seat{min-height:64px}

/* Eight pickup hues, light. Chosen for hue separation AND for contrast against
   every status fill they sit on (they are chips + a stripe, never the fill). */
.stop-c0{--stop-c:#2563eb;--stop-ink:#fff}
.stop-c1{--stop-c:#7c3aed;--stop-ink:#fff}
.stop-c2{--stop-c:#0d9488;--stop-ink:#fff}
.stop-c3{--stop-c:#c2410c;--stop-ink:#fff}
.stop-c4{--stop-c:#be123c;--stop-ink:#fff}
.stop-c5{--stop-c:#4d7c0f;--stop-ink:#fff}
.stop-c6{--stop-c:#0369a1;--stop-ink:#fff}
.stop-c7{--stop-c:#a16207;--stop-ink:#fff}

/* Status colours (light) */
.seat-open{background:#e7f6ee;color:#0a6b3b;border-color:#a7d7b8}
.seat-booked{background:#fee2e2;color:#991b1b;border-color:#fca5a5}
.seat-held{background:#fef3c7;color:#92400e;border-color:#fcd34d}
.seat-blocked{background:#e5e7eb;color:#4b5563;border-color:#9ca3af}
.seat-staff{background:#fef9c3;color:#713f12;border-color:#fde047}
.seat-female{border-color:#f472b6!important;box-shadow:inset 0 0 0 1px #f472b6}

/* Status colours (dark — prefers-color-scheme) */
@media(prefers-color-scheme:dark){
  :root:not([data-theme="light"]) .seat-open{background:#064e3b;color:#6ee7b7;border-color:#065f46}
  :root:not([data-theme="light"]) .seat-booked{background:#7f1d1d;color:#fca5a5;border-color:#991b1b}
  :root:not([data-theme="light"]) .seat-held{background:#78350f;color:#fcd34d;border-color:#92400e}
  :root:not([data-theme="light"]) .seat-blocked{background:#1f2937;color:#9ca3af;border-color:#374151}
  :root:not([data-theme="light"]) .seat-staff{background:#422006;color:#fde047;border-color:#713f12}
  /* Pickup hues, dark — lightened so the chip reads on a dark status fill. */
  :root:not([data-theme="light"]) .stop-c0{--stop-c:#60a5fa;--stop-ink:#0b1220}
  :root:not([data-theme="light"]) .stop-c1{--stop-c:#c4b5fd;--stop-ink:#1b1035}
  :root:not([data-theme="light"]) .stop-c2{--stop-c:#5eead4;--stop-ink:#062723}
  :root:not([data-theme="light"]) .stop-c3{--stop-c:#fdba74;--stop-ink:#2b1206}
  :root:not([data-theme="light"]) .stop-c4{--stop-c:#fda4af;--stop-ink:#3b0715}
  :root:not([data-theme="light"]) .stop-c5{--stop-c:#bef264;--stop-ink:#1a2607}
  :root:not([data-theme="light"]) .stop-c6{--stop-c:#7dd3fc;--stop-ink:#04222f}
  :root:not([data-theme="light"]) .stop-c7{--stop-c:#fcd34d;--stop-ink:#2a1d03}
}
/* Status colours (dark — explicit toggle) */
[data-theme="dark"] .seat-open{background:#064e3b;color:#6ee7b7;border-color:#065f46}
[data-theme="dark"] .seat-booked{background:#7f1d1d;color:#fca5a5;border-color:#991b1b}
[data-theme="dark"] .seat-held{background:#78350f;color:#fcd34d;border-color:#92400e}
[data-theme="dark"] .seat-blocked{background:#1f2937;color:#9ca3af;border-color:#374151}
[data-theme="dark"] .seat-staff{background:#422006;color:#fde047;border-color:#713f12}
/* Pickup hues, dark — the live path: admin/_guard.php always stamps
   data-theme, so the media block above only fires if that inline script fails.
   Both copies must exist or dark mode is a silent half-fix. */
[data-theme="dark"] .stop-c0{--stop-c:#60a5fa;--stop-ink:#0b1220}
[data-theme="dark"] .stop-c1{--stop-c:#c4b5fd;--stop-ink:#1b1035}
[data-theme="dark"] .stop-c2{--stop-c:#5eead4;--stop-ink:#062723}
[data-theme="dark"] .stop-c3{--stop-c:#fdba74;--stop-ink:#2b1206}
[data-theme="dark"] .stop-c4{--stop-c:#fda4af;--stop-ink:#3b0715}
[data-theme="dark"] .stop-c5{--stop-c:#bef264;--stop-ink:#1a2607}
[data-theme="dark"] .stop-c6{--stop-c:#7dd3fc;--stop-ink:#04222f}
[data-theme="dark"] .stop-c7{--stop-c:#fcd34d;--stop-ink:#2a1d03}

/* Legend strip */
.seat-legend{display:flex;flex-wrap:wrap;gap:14px;margin:12px 0 22px;padding:0 4px}
.seat-legend span{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:var(--ink,#374151)}
.seat-legend i{display:inline-block;width:16px;height:16px;border-radius:4px;flex-shrink:0;
  border:2px solid transparent}
/* Pickup key: one chip per stop on this route, in the same route order (and so
   the same hue) the tiles use. */
.stop-legend{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:-10px 0 22px;padding:0 4px}
.stop-legend .sl-lab{font-size:12px;color:var(--mut,#6b7280);font-weight:600}
.stop-legend b{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:700;
  padding:2px 9px 2px 5px;border-radius:999px;border:1px solid var(--bd,#e5e7eb);color:var(--ink,#374151)}
.stop-legend b em{font-style:normal;font-family:ui-monospace,'Cascadia Code',Consolas,monospace;
  font-weight:800;font-size:11px;letter-spacing:.4px;padding:0 5px;border-radius:999px;
  background:var(--stop-c,transparent);color:var(--stop-ink,inherit)}

/* Table row highlight on click-to-scroll */
.seat-row-hl{background:#fff4d1!important;transition:background .4s}

/* Responsive: stack decks on narrow screens */
@media(max-width:820px){.seatmap-visual{grid-template-columns:1fr}}

/* ── Quick-actions tabs — one form visible at a time ─────────────── */
.qa-panel .qa-tabs{display:flex;flex-wrap:wrap;gap:6px;padding:12px 18px 0;border-bottom:1px solid var(--line,#e5e7eb)}
.qa-panel .qa-tab{background:transparent;border:1px solid transparent;border-bottom:none;
  border-radius:8px 8px 0 0;padding:8px 14px;font-weight:700;font-size:13px;
  color:var(--mut,#666);cursor:pointer;transition:background .12s,color .12s}
.qa-panel .qa-tab:hover{background:var(--head,#f5f5f7);color:var(--ink,#1f2937)}
.qa-panel .qa-tab[aria-selected="true"]{background:var(--card,#fff);border-color:var(--line,#e5e7eb);
  color:var(--ink,#1f2937);position:relative;top:1px}
.qa-panel .qa-tab-body{padding:16px 18px}
.qa-panel .qa-pane{display:flex;flex-direction:column;gap:8px;max-width:520px}

/* ── Bookings-on-this-trip panel ─────────────────────────────────── */
.sm-bookings{width:100%;border-collapse:collapse}
.sm-bookings th{font-size:11.5px;text-align:left;padding:8px 12px;border-bottom:2px solid var(--line,#e5e7eb);
                color:var(--mut,#666);text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.sm-bookings td{padding:10px 12px;border-bottom:1px solid var(--line,#e5e7eb);font-size:13px;vertical-align:middle}
.sm-bookings tr:hover td{background:var(--head,#f5f5f7)}
/* Source badges — mirror trip-dashboard.php so both screens read the same. */
.td-src-online{background:#e2ecfb;color:#1c3b72}
.td-src-agent{background:#fff4d1;color:#8a6d00}
.td-src-counter{background:#ffe6c7;color:#7a4a00}
.td-src-admin{background:#f0e6ff;color:#5a3fb0}
:root[data-theme="dark"] .td-src-online{background:#1c3b72;color:#b8d4fb}
:root[data-theme="dark"] .td-src-agent{background:#4a3d00;color:#ffe28a}
:root[data-theme="dark"] .td-src-counter{background:#4a3200;color:#ffcc8a}
:root[data-theme="dark"] .td-src-admin{background:#2e1f5e;color:#d4bfff}

/* ── Click-a-seat action menu (popover) ──────────────────────────── */
.seat[data-seat]{-webkit-tap-highlight-color:transparent}
#seatMenuBackdrop{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.18);display:none}
#seatMenuBackdrop.on{display:block}
.seat-menu{position:fixed;z-index:61;min-width:210px;max-width:88vw;background:var(--card,#fff);
  border:1px solid var(--line,#e5e7eb);border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,.22);
  padding:8px;display:none}
.seat-menu.on{display:block}
.seat-menu .sm-head{display:flex;align-items:center;gap:8px;padding:6px 10px 8px;border-bottom:1px solid var(--line,#e5e7eb);margin-bottom:6px}
.seat-menu .sm-seat{font-family:ui-monospace,Consolas,monospace;font-weight:800;font-size:15px}
.seat-menu .sm-badge{font-size:11px;font-weight:700;padding:2px 9px;border-radius:999px;margin-left:auto}
.seat-menu button.sm-item,.seat-menu a.sm-item{display:flex;align-items:center;gap:10px;width:100%;
  text-align:left;background:none;border:0;border-radius:9px;padding:10px 12px;font-size:14px;font-weight:600;
  color:var(--ink,#1f2937);cursor:pointer;text-decoration:none;line-height:1.2}
.seat-menu .sm-item:hover{background:var(--hover,#eef2fa)}
.seat-menu .sm-item.sm-danger{color:#b02a2a}
.seat-menu .sm-item .sm-ic{width:20px;text-align:center;flex-shrink:0}
.seat-menu .sm-note{padding:8px 12px;font-size:12.5px;color:var(--mut,#666)}
@media(pointer:coarse){.seat-menu .sm-item{min-height:44px}}
</style>
<?php

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<form method="get" class="toolbar">
  <label>Bus / route
    <select name="route" onchange="this.form.submit()">
      <?php foreach ($routes as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= (int) $r['id'] === $routeId ? 'selected' : '' ?>>
          <?= Security::e($r['route_code'] . ' · ' . $r['from_city'] . ' → ' . $r['to_city'] . ' (' . ucfirst((string) $r['coach_type']) . ')') ?>
          <?= (int) $r['is_active'] === 1 ? '' : ' — inactive' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Date <input type="date" name="date" value="<?= Security::e($date) ?>" onchange="this.form.submit()"></label>
  <button class="btn ghost" type="submit">Load</button>
  <?php if (Auth::bookingScopeAdminId() === null): ?>
  <a class="btn ghost" href="/admin/chalan.php?<?= Security::e(http_build_query(array_filter(['sid' => $sidReq > 0 ? $sidReq : null, 'date' => $date]))) ?>" title="Bus chalan — the seat picture and the Nepali waybill: preview, PDF / PNG, WhatsApp">📋 Bus Chalan</a>
  <?php endif; ?>
</form>

<?php if ($route === null): ?>
  <div class="panel"><h2>No route selected</h2></div>
<?php else: ?>

<div class="cards">
  <div class="card"><div class="k">Open</div><div class="v" id="count-open"><?= $counts['open'] ?></div></div>
  <div class="card"><div class="k">Booked</div><div class="v" id="count-booked"><?= $counts['booked'] ?></div></div>
  <div class="card"><div class="k">Held</div><div class="v" id="count-held"><?= $counts['held'] ?></div></div>
  <div class="card"><div class="k">Out of service</div><div class="v" id="count-blocked"><?= $counts['blocked'] ?></div></div>
  <?php if (($counts['staff'] ?? 0) > 0): ?>
  <div class="card"><div class="k">Staff reserved</div><div class="v" id="count-staff"><?= $counts['staff'] ?></div></div>
  <?php endif; ?>
</div>

<?php if ($hasVisual): ?>
<div class="seatmap-visual">
  <?php foreach ($layout['decks'] as $deck):
    // Per-deck seat-id range for the header (e.g. "L1–L36") — computed
    // from the layout's own seatIds rather than hard-coded, so a coach
    // with a non-72 layout labels itself honestly.
    $deckSeats = [];
    foreach ($deck['rows'] as $rw) {
        foreach (array_merge($rw['left'] ?? [], $rw['right'] ?? []) as $s) { $deckSeats[] = $s; }
    }
    $rangeLabel = $deckSeats !== []
        ? ' (' . Seats::displayLabel((string) $deckSeats[0], $coach, 'sharing') . '–' . Seats::displayLabel((string) end($deckSeats), $coach, 'sharing') . ')'
        : '';
    $deckIcon = $deck['key'] === 'L' ? '🔽 ' : ($deck['key'] === 'U' ? '🔼 ' : '💺 ');
  ?>
    <div class="deck">
      <h3><?= $deckIcon ?><?= Security::e((string) $deck['label']) ?><?= Security::e($rangeLabel) ?></h3>
      <div class="deck-grid">
        <?php foreach ($deck['rows'] as $row): ?>
          <div class="seat-row-adm">
            <div class="seat-group left">
              <?php foreach (($row['left'] ?? []) as $sid): if (isset($map[$sid])) echo seatmap_seat_div($map[$sid], $femalePref, $agentCodes, $coach); endforeach; ?>
            </div>
            <?php if (!empty($row['aisle'])): ?>
              <span class="aisle-adm" title="Aisle · <?= Security::e((string) ($row['label'] ?? '')) ?>">·</span>
            <?php endif; ?>
            <div class="seat-group right">
              <?php foreach (($row['right'] ?? []) as $sid): if (isset($map[$sid])) echo seatmap_seat_div($map[$sid], $femalePref, $agentCodes, $coach); endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<div class="seat-legend">
  <?php /* The swatches now reuse the CELL classes instead of repeating their
           light hex inline. That was a real divergence: the cells have dark
           rules and the legend did not, so in dark mode every seat turned dark
           green/red while the key stayed pastel and no longer matched the map
           it explains. Sharing the class means it can never drift again. The
           glyphs also separate Held from Emergency, which are near-identical
           yellows that the swatches alone could not distinguish. */ ?>
  <span><i class="seat-open"></i> Available</span>
  <span><i class="seat-booked"></i> Booked</span>
  <span><i class="seat-held"></i> ⏳ Held</span>
  <span><i class="seat-blocked"></i> 🚫 Blocked</span>
  <span><i class="seat-staff"></i> 🛡️ Emergency</span>
  <span><i class="seat-female"></i> Female pref.</span>
</div>
<?php
/* Pickup key. Built with the SAME walk Seats::adminSeatMap uses to assign
   stopHue — stopsFor() order, deduped by townKey, first occurrence wins — so
   the chip colour here is guaranteed to be the chip colour on the tiles. If
   the two ever drift, the legend starts lying, which is worse than no legend. */
$legendStops = [];
if ($routeId > 0) {
    foreach (Boarding::stopsFor($routeId) as $i => $st) {
        $key = Boarding::townKey((string) ($st['name'] ?? ''));
        if ($key === '' || isset($legendStops[$key])) {
            continue;
        }
        $d = Boarding::stopDisplay((string) ($st['name'] ?? ''));
        $legendStops[$key] = [
            'code' => $d['code'],
            'name' => $d['name'] !== '' ? $d['name'] : (string) ($st['name'] ?? ''),
            'hue'  => $i % 8,
        ];
    }
}
if ($legendStops !== []): ?>
<div class="stop-legend">
  <span class="sl-lab">Pickup:</span>
  <?php foreach ($legendStops as $ls): ?>
    <b class="stop-c<?= (int) $ls['hue'] ?>"><em><?= Security::e($ls['code']) ?></em><?= Security::e($ls['name']) ?></b>
  <?php endforeach; ?>
  <span class="sl-lab">&mdash; shown on each sold berth, with a matching left stripe</span>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="panel qa-panel">
  <h2>Quick actions · <?= Security::e($route['from_city'] . ' → ' . $route['to_city']) ?> · <?= Security::e(formatDate($date)) ?></h2>
  <?php /* Tabbed layout — 3 forms sit side-by-side but only one is visible
           at a time so the counter agent isn't looking at nine controls
           when they only need three. Default = Counter booking (most used).
           Fields, names and action values are unchanged. */ ?>
  <div class="qa-tabs" role="tablist">
    <button type="button" class="qa-tab"        data-qa-tab="counter"  role="tab" aria-selected="true">🎫 Counter booking</button>
    <button type="button" class="qa-tab"        data-qa-tab="block"    role="tab" aria-selected="false">🚫 Block seat</button>
    <button type="button" class="qa-tab"        data-qa-tab="transfer" role="tab" aria-selected="false">🔁 Transfer</button>
  </div>
  <div class="qa-tab-body">

    <form method="post" data-qa-pane="counter" class="qa-pane">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>">
      <a class="btn" style="margin-bottom:8px" href="/index.php?<?= Security::e(http_build_query(array_filter(['counter' => 1, 'from' => (string) ($route['from_city'] ?? ''), 'to' => (string) ($route['to_city'] ?? ''), 'date' => $date, 'sid' => $sidReq > 0 ? $sidReq : null]))) ?>#/" title="Several seats, passenger details, UPI/cash received — the same seat map customers use">🧾 Sell several seats in the app →</a>
      <b>🎫 Counter booking (walk-in / cash)</b>
      <select name="seat" id="counterSeatSel" required>
        <option value="">Choose an open seat…</option>
        <?php foreach ($openSeats as $s): ?><option value="<?= Security::e($s) ?>"><?= Security::e(Seats::displayLabel((string) $s, $coach, 'sharing')) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="pax_name" placeholder="Passenger name" maxlength="120" required>
      <div style="display:flex;gap:8px">
        <select name="pax_gender" style="flex:1"><option value="">Gender…</option><option>Male</option><option>Female</option><option>Other</option></select>
        <input type="text" name="pax_phone" placeholder="Phone (optional)" maxlength="15" style="flex:1">
      </div>
      <div style="display:flex;gap:8px" title="Discount off the base fare — capped by counter_max_discount_pct">
        <input type="number" name="discount_value" placeholder="Discount (optional)" min="0" step="1" style="flex:1">
        <select name="discount_type" style="flex:1"><option value="flat">₹ Fixed</option><option value="percent">% Percent</option></select>
      </div>
      <button class="btn ok" type="submit" name="action" value="counter" onclick="return confirm('Create a confirmed booking for this seat?')">Book seat</button>
      <span class="muted" style="font-size:12px">Runs the same availability + shared-cabin gender checks as online booking.</span>
    </form>

    <form method="post" data-qa-pane="block" class="qa-pane" hidden>
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>">
      <b>🚫 Take a seat out of service</b>
      <select name="seat" required>
        <option value="">Choose an open seat…</option>
        <?php foreach ($openSeats as $s): ?><option value="<?= Security::e($s) ?>"><?= Security::e(Seats::displayLabel((string) $s, $coach, 'sharing')) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="reason" placeholder="Reason (e.g. broken berth)" maxlength="191">
      <button class="btn bad" type="submit" name="action" value="block" onclick="return confirm('Take this seat out of service?')">Block seat</button>
    </form>

    <form method="post" data-qa-pane="transfer" class="qa-pane" hidden>
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>">
      <b>🔁 Transfer / reseat a passenger</b>
      <select name="seat" required>
        <option value="">From booked seat…</option>
        <?php foreach ($bookedSeats as $s): ?><option value="<?= Security::e($s) ?>"><?= Security::e(Seats::displayLabel((string) $s, $coach, 'sharing')) ?><?= !empty($map[$s]['passenger']) ? ' · ' . Security::e((string) $map[$s]['passenger']) : '' ?></option><?php endforeach; ?>
      </select>
      <select name="to_seat" required>
        <option value="">To open seat…</option>
        <?php foreach ($openSeats as $s): ?><option value="<?= Security::e($s) ?>"><?= Security::e(Seats::displayLabel((string) $s, $coach, 'sharing')) ?></option><?php endforeach; ?>
      </select>
      <button class="btn" type="submit" name="action" value="transfer" onclick="return confirm('Move this passenger to the new seat?')">Transfer seat</button>
      <span class="muted" style="font-size:12px">Same-trip reseat — no duplicate booking; the shared-cabin gender rule still applies.</span>
    </form>

  </div>
</div>
<?php endif; ?>

<?php if ($tripBookings !== []): ?>
<div class="panel">
  <h2>🎫 Bookings on this trip · <?= count($tripBookings) ?></h2>
  <div style="overflow-x:auto">
  <table class="sm-bookings">
    <thead><tr>
      <th>PNR</th><th>Passenger</th><th>Seats</th><th>Amount</th><th>Source</th><th>Agent</th><th>Status</th>
    </tr></thead>
    <tbody>
    <?php foreach ($tripBookings as $bk):
      $soldBy   = (int) ($bk['sold_by_admin_id'] ?? 0);
      $code     = seatmap_agent_code($agentCodes, $soldBy > 0 ? $soldBy : null);
      $bkSource = strtolower((string) ($bk['source'] ?? 'web'));
    ?>
      <tr>
        <td><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $bk['pnr']) ?>" class="mono"><?= Security::e((string) $bk['pnr']) ?></a></td>
        <td><?= Security::e((string) ($bk['passenger_name'] ?? '—')) ?></td>
        <td class="mono"><?php
          // Each booking's seats are stored in ITS OWN mode, so a private cabin
          // sold as "L3" relabels with the private 3-across grid, not sharing.
          $bkSeats = array_values(array_filter(array_map('trim', explode(',', (string) ($bk['seats'] ?? '')))));
          echo $bkSeats === []
              ? '—'
              : Security::e(Seats::displayLabels($bkSeats, $coach, (string) ($bk['booking_mode'] ?? 'sharing'), ', '));
        ?></td>
        <td><?= Security::e(inr((float) ($bk['total_amount'] ?? 0))) ?>
          <?php if ((int) ($bk['is_cod'] ?? 0) === 1): ?>
            <span class="pill" style="background:#ffe6c7;color:#7a4a00;font-size:10px;padding:1px 6px;margin-left:4px">COD</span>
          <?php endif; ?>
        </td>
        <td><?= seatmap_source_badge($bkSource) ?></td>
        <td class="mono"><?= $code !== '' ? Security::e($code) : '<span class="muted">—</span>' ?></td>
        <td><?= admin_pill((string) ($bk['status'] ?? 'pending')) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <h2>All seats · <?= count($map) ?> berths</h2>
  <table>
    <thead><tr>
      <th>Seat</th><th>Cabin</th><th>Status</th><th>Booked by</th><th>Agent</th><th>Cabin rule</th><th>Female&nbsp;pref</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($map as $s):
      $seat = $s['seat'];
      $isFem = in_array($seat, $femalePref, true);
    ?>
      <tr data-seat="<?= Security::e($seat) ?>">
        <td class="mono"><strong><?= Security::e(Seats::displayLabel((string) $seat, $coach, 'sharing')) ?></strong><div class="muted"><?= Security::e(Seats::label($seat)) ?></div></td>
        <td class="mono muted"><?= Security::e((string) $s['unit']) ?></td>
        <td><?= seatmap_status_pill((string) $s['status']) ?></td>
        <td>
          <?php if ($s['status'] === 'booked' && empty($s['mine'])): ?>
            <?php /* Sold by someone else — an agent sees that the berth is
                      gone, and its gender so the cabin rule still works, but
                      never the passenger or the reference. */ ?>
            <span class="muted">Sold<?php if (!empty($s['gender'])): ?> · <?= Security::e((string) $s['gender']) ?><?php endif; ?></span>
          <?php elseif ($s['status'] === 'booked'): ?>
            <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $s['pnr']) ?>" class="mono"><?= Security::e((string) $s['pnr']) ?></a>
            <div class="muted">
              <?= Security::e((string) ($s['passenger'] ?? '—')) ?>
              <?php if (!empty($s['gender'])): ?> · <?= Security::e((string) $s['gender']) ?><?php endif; ?>
              <?php if (!empty($s['channel'])): ?> · <?= Security::e((string) $s['channel']) ?><?php endif; ?>
            </div>
          <?php elseif ($s['status'] === 'held'): ?>
            <span class="muted">Held until <?= Security::e(formatTime(substr((string) $s['holdUntil'], 11, 5))) ?: Security::e((string) $s['holdUntil']) ?></span>
          <?php elseif ($s['status'] === 'blocked'): ?>
            <span class="muted">Out of service</span>
          <?php elseif ($s['status'] === 'staff'): ?>
            <span class="muted">🛡️ Staff / Emergency reserved<?= Auth::isSuperadmin() ? ' · Super-admin may override' : ' · locked' ?></span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($s['status'] === 'booked' && !empty($s['mine'])):
            $code = (string) ($s['agentCode'] ?? '');
            if ($code === '' && !empty($s['soldById'])) {
                $code = seatmap_agent_code($agentCodes, (int) $s['soldById']);
            }
            $channel = (string) ($s['channel'] ?? '');
            $agentName = '';
            if (strncmp($channel, 'Agent:', 6) === 0) {
                $agentName = trim(substr($channel, 6));
            }
          ?>
            <?php if ($code !== ''): ?>
              <span class="mono"><?= Security::e($code) ?></span>
              <?php if ($agentName !== ''): ?><div class="muted"><?= Security::e($agentName) ?></div><?php endif; ?>
            <?php elseif ($channel !== ''): ?>
              <span class="muted"><?= Security::e($channel) ?></span>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <td><?= seatmap_lock_badge((string) $s['genderLock']) ?></td>
        <td>
          <?php if ($canPolicy): ?>
          <form method="post" style="display:inline">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>">
            <input type="hidden" name="seat" value="<?= Security::e($seat) ?>">
            <input type="hidden" name="on" value="<?= $isFem ? '0' : '1' ?>">
            <button class="btn ghost" type="submit" name="action" value="female" style="padding:5px 10px"><?= $isFem ? '♀ on' : '♀ off' ?></button>
          </form>
          <?php else: ?><?= $isFem ? '♀' : '—' ?><?php endif; ?>
        </td>
        <td>
          <?php if ($canEdit): ?>
          <div class="row-actions">
            <?php if ($s['status'] === 'held'): ?>
              <form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>"><input type="hidden" name="seat" value="<?= Security::e($seat) ?>">
                <button class="btn ghost" type="submit" name="action" value="release" style="padding:5px 10px">Release hold</button></form>
            <?php elseif ($s['status'] === 'blocked'): ?>
              <form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>"><input type="hidden" name="seat" value="<?= Security::e($seat) ?>">
                <button class="btn ok" type="submit" name="action" value="unblock" style="padding:5px 10px">Unblock</button></form>
            <?php elseif ($s['status'] === 'open'): ?>
              <form method="post" style="display:inline"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="route" value="<?= $routeId ?>"><input type="hidden" name="date" value="<?= Security::e($date) ?>"><input type="hidden" name="seat" value="<?= Security::e($seat) ?>"><input type="hidden" name="reason" value="">
                <button class="btn ghost bad" type="submit" name="action" value="block" style="padding:5px 10px" onclick="return confirm('Block seat <?= Security::e(Seats::displayLabel((string) $seat, $coach, 'sharing')) ?>?')">Block</button></form>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </div>
          <?php else: ?><span class="muted">view only</span><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted">Tap any seat for its actions. Blocking, releasing holds and counter sales all go through the same transactional seat core as online booking and are written to the audit log. A blocked seat cannot be sold anywhere until it is unblocked.</p>

<!-- Click-a-seat action menu: a small popover built per seat. It only ever
     drives the existing quick-action forms / one-click endpoints above, so it
     adds no new backend path and inherits their CSRF + schedules.edit guards. -->
<div id="seatMenuBackdrop"></div>
<div class="seat-menu" id="seatMenu" role="menu" aria-label="Seat actions"></div>
<form method="post" id="seatActForm" style="display:none">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="route" value="<?= $routeId ?>">
  <input type="hidden" name="date" value="<?= Security::e($date) ?>">
  <input type="hidden" name="seat"   id="seatActSeat"   value="">
  <input type="hidden" name="reason" id="seatActReason" value="">
  <input type="hidden" name="action" id="seatActAction" value="">
</form>

<script>
(function(){
  /* ── Quick-actions tabs — show one form at a time, no dep ─────── */
  var qaTabs  = document.querySelectorAll('.qa-panel .qa-tab');
  var qaPanes = document.querySelectorAll('.qa-panel .qa-pane');
  qaTabs.forEach(function(tab){
    tab.addEventListener('click', function(){
      var name = this.getAttribute('data-qa-tab');
      qaTabs.forEach(function(t){ t.setAttribute('aria-selected', t === tab ? 'true' : 'false'); });
      qaPanes.forEach(function(p){
        if (p.getAttribute('data-qa-pane') === name) { p.hidden = false; }
        else                                          { p.hidden = true; }
      });
    });
  });

  /* ── Click-a-seat action menu ─────────────────────────────────────
     A popover built per seat. Every action here only drives the SAME
     quick-action forms / one-click endpoints already on the page, so it
     inherits their CSRF + schedules.edit guards and adds no new backend. */
  var SM_CAN_EDIT  = <?= $canEdit ? 'true' : 'false' ?>;
  var seatMenu     = document.getElementById('seatMenu');
  var seatBackdrop = document.getElementById('seatMenuBackdrop');
  var bvBase       = <?= json_encode($base . '/admin/booking-view.php?pnr=') ?>;
  // Deep link into counter mode carrying this exact departure (sid for an
  // extra bus) — buildSeatMenu appends &seat= for a one-click app booking.
  var ctrBase      = <?= json_encode('/index.php?' . http_build_query(array_filter(['counter' => 1, 'from' => (string) ($route['from_city'] ?? ''), 'to' => (string) ($route['to_city'] ?? ''), 'date' => $date, 'sid' => $sidReq > 0 ? $sidReq : null]))) ?>;

  function smEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
  function closeSeatMenu(){ if(seatMenu){ seatMenu.classList.remove('on'); seatMenu.innerHTML=''; } if(seatBackdrop){ seatBackdrop.classList.remove('on'); } }

  function prefillCounter(seat){
    var counterTab = document.querySelector('.qa-panel .qa-tab[data-qa-tab="counter"]');
    if(counterTab) counterTab.click();
    var sel = document.getElementById('counterSeatSel');
    if(sel){ sel.value = seat;
      var form = sel.closest('form'); form.scrollIntoView({behavior:'smooth',block:'center'});
      var nameInp = form.querySelector('input[name="pax_name"]'); if(nameInp) nameInp.focus(); else sel.focus();
    }
  }
  function prefillTransfer(seat){
    var tTab = document.querySelector('.qa-panel .qa-tab[data-qa-tab="transfer"]'); if(tTab) tTab.click();
    var pane = document.querySelector('.qa-panel .qa-pane[data-qa-pane="transfer"]');
    if(pane){
      var fromSel = pane.querySelector('select[name="seat"]'); if(fromSel) fromSel.value = seat;
      pane.scrollIntoView({behavior:'smooth',block:'center'});
      var toSel = pane.querySelector('select[name="to_seat"]'); if(toSel) toSel.focus();
    }
  }
  function submitSeatAction(action, seat, opts){
    opts = opts || {};
    var f = document.getElementById('seatActForm'); if(!f) return;
    if(opts.confirm && !confirm(opts.confirm)) return;
    var reason = '';
    if(action==='block'){ reason = prompt('Reason for taking seat '+(opts.label||seat)+' out of service? (optional)',''); if(reason===null) return; }
    document.getElementById('seatActSeat').value   = seat;
    document.getElementById('seatActReason').value = reason;
    document.getElementById('seatActAction').value = action;
    f.submit();
  }

  function smBtn(icon,label,fn,danger){
    var b=document.createElement('button'); b.type='button'; b.className='sm-item'+(danger?' sm-danger':'');
    b.innerHTML='<span class="sm-ic">'+icon+'</span><span>'+smEsc(label)+'</span>';
    b.addEventListener('click', function(){ closeSeatMenu(); fn(); });
    return b;
  }
  function smLink(icon,label,href,danger){
    var a=document.createElement('a'); a.className='sm-item'+(danger?' sm-danger':''); a.href=href;
    a.innerHTML='<span class="sm-ic">'+icon+'</span><span>'+smEsc(label)+'</span>';
    return a;
  }
  function smNote(text){ var d=document.createElement('div'); d.className='sm-note'; d.textContent=text; return d; }

  function buildSeatMenu(el){
    var seat=el.dataset.seat, status=el.dataset.status, mine=el.dataset.mine==='1', pnr=el.dataset.pnr||'';
    var seatLbl=el.dataset.seatLabel||seat;   // display id (LA1); seat stays canonical for every action/POST
    seatMenu.innerHTML='';
    var head=document.createElement('div'); head.className='sm-head';
    var bMap={open:['Open','#e7f6ee','#0a6b3b'],booked:['Booked','#fee2e2','#991b1b'],held:['Held','#fef3c7','#92400e'],blocked:['Out of service','#e5e7eb','#4b5563'],staff:['Emergency','#fef9c3','#713f12']};
    var bm=bMap[status]||[status,'#eee','#333'];
    head.innerHTML='<span class="sm-seat">'+smEsc(seatLbl)+'</span><span class="sm-badge" style="background:'+bm[1]+';color:'+bm[2]+'">'+smEsc(bm[0])+'</span>';
    seatMenu.appendChild(head);
    if(status==='open'){
      if(SM_CAN_EDIT){
        seatMenu.appendChild(smBtn('🎫','Book walk-in / cash',function(){prefillCounter(seat);}));
        seatMenu.appendChild(smLink('🧾','Book in app (multi-seat / UPI)',ctrBase+'&seat='+encodeURIComponent(seat)+'#/'));
        seatMenu.appendChild(smBtn('🚫','Take out of service',function(){submitSeatAction('block',seat,{label:seatLbl});},true));
      } else { seatMenu.appendChild(smNote('Available · view only')); }
    } else if(status==='booked'){
      if(mine && pnr){
        seatMenu.appendChild(smLink('🎟️','View / manage ticket',bvBase+encodeURIComponent(pnr)));
        if(SM_CAN_EDIT) seatMenu.appendChild(smBtn('🔁','Change seat',function(){prefillTransfer(seat);}));
        seatMenu.appendChild(smLink('✏️','Edit passenger',bvBase+encodeURIComponent(pnr)));
        seatMenu.appendChild(smLink('❌','Cancel booking',bvBase+encodeURIComponent(pnr),true));
      } else { seatMenu.appendChild(smNote('🔒 Sold by another agent')); }
    } else if(status==='held'){
      if(SM_CAN_EDIT) seatMenu.appendChild(smBtn('⏳','Release hold',function(){submitSeatAction('release',seat,{confirm:'Release the hold on '+seatLbl+'?'});}));
      else seatMenu.appendChild(smNote('Held · view only'));
    } else if(status==='blocked'){
      if(SM_CAN_EDIT) seatMenu.appendChild(smBtn('✅','Put back in service',function(){submitSeatAction('unblock',seat,{confirm:'Put '+seatLbl+' back in service?'});}));
      else seatMenu.appendChild(smNote('Out of service'));
    } else if(status==='staff'){
      seatMenu.appendChild(smNote('🛡️ Staff / emergency reserved'));
    }
  }
  function openSeatMenu(el){
    if(!seatMenu) return;
    buildSeatMenu(el);
    seatBackdrop.classList.add('on'); seatMenu.classList.add('on');
    var r=el.getBoundingClientRect(), mw=seatMenu.offsetWidth, mh=seatMenu.offsetHeight, vw=window.innerWidth, vh=window.innerHeight, left, top;
    if(vw<=560){ left=(vw-mw)/2; top=Math.max(10,(vh-mh)/2); }
    else{
      left=r.left; if(left+mw>vw-8) left=vw-mw-8; if(left<8) left=8;
      top=r.bottom+6; if(top+mh>vh-8) top=r.top-mh-6; if(top<8) top=8;
    }
    seatMenu.style.left=left+'px'; seatMenu.style.top=top+'px';
  }

  document.querySelectorAll('.seat[data-seat]').forEach(function(el){
    el.addEventListener('click', function(e){ e.stopPropagation(); openSeatMenu(this); });
  });
  if(seatBackdrop) seatBackdrop.addEventListener('click', closeSeatMenu);
  document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeSeatMenu(); });

  /* ── Auto-refresh every 15 s via lightweight poll ─────────────── */
  var _pollRoute = <?= (int) $routeId ?>;
  var _pollDate  = <?= json_encode($date, JSON_UNESCAPED_SLASHES) ?>;
  var _pollUrl   = <?= json_encode($base . '/admin/api/seatmap-poll.php') ?>;
  var _pollSid   = <?= (int) $sidReq ?>;   // extra bus on the same date (0 = daily bus)

  setInterval(function(){
    if (document.hidden) return;  // save bandwidth when tab is in background
    fetch(_pollUrl + '?route=' + _pollRoute + '&date=' + encodeURIComponent(_pollDate) + (_pollSid ? '&sid=' + _pollSid : ''), {credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data || !data.seats) return;

        /* Build a fresh tooltip that mirrors seatmap_seat_div() on the
           server side: "L4 · Bishal Sharma · SHG-027 (Rakesh Agent)" for
           our own bookings, "L4 · Sold · Female" for someone else's. */
        /* " · MSN (Mehsana) @ 21:00" — mirrors the same suffix the server
           appends in seatmap_seat_div(), so a tile's tooltip does not change
           wording the moment the first poll lands. */
        function stopSuffix(info) {
          if (!info.stop) return '';
          return ' · ' + info.stop
               + (info.stopName ? ' (' + info.stopName + ')' : '')
               + (info.stopTime ? ' @ ' + info.stopTime : '');
        }
        function rebuildTip(seat, info) {
          return baseTip(seat, info) + stopSuffix(info);
        }
        function baseTip(seat, info) {
          var tip = seat;
          if (info.status === 'open') {
            return tip + ' · Available';
          }
          if (info.status === 'booked') {
            if (info.passenger) {
              tip += ' · ' + info.passenger;
              var code = info.agentCode || '';
              var channel = info.channel || '';
              if (channel.indexOf('Agent:') === 0) {
                var agentName = channel.substring(6).trim();
                if (code) tip += ' · ' + code + (agentName ? ' (' + agentName + ')' : '');
                else if (agentName) tip += ' · ' + agentName;
              } else if (channel) {
                tip += ' · ' + channel;
              } else if (code) {
                tip += ' · ' + code;
              }
            } else {
              tip += ' · Sold';
              if (info.gender) tip += ' · ' + info.gender;
            }
            return tip;
          }
          if (info.status === 'held') {
            return tip + ' · Held' + (info.holdUntil ? ' until ' + info.holdUntil : '');
          }
          if (info.status === 'blocked') return tip + ' · Out of service';
          if (info.status === 'staff')   return tip + ' · Staff reserved';
          return tip + ' · ' + info.status;
        }

        /* Update visual seat cells */
        document.querySelectorAll('.seat[data-seat]').forEach(function(el){
          var info = data.seats[el.dataset.seat];
          if (!info) return;
          var wasFemale = el.classList.contains('seat-female');
          // The pickup class must be re-applied from the POLL, not preserved
          // from the old className: a seat that changed hands can change stop
          // (or lose one), and this assignment wipes every class each tick.
          var hue = (typeof info.stopHue === 'number') ? info.stopHue : -1;
          el.className = 'seat seat-' + info.status
                       + (wasFemale ? ' seat-female' : '')
                       + (info.stop && hue >= 0 ? ' stop-c' + (hue % 8) : '');
          el.dataset.status = info.status;
          el.dataset.stop   = info.stop || '';
          // Keep the click-popover honest for a seat that changed hands
          // mid-session: the poll ships pnr only for the viewer's OWN
          // bookings, so mine/pnr follow it (5 Sep 2026).
          if (info.status === 'booked') {
            el.dataset.mine = info.pnr ? '1' : '0';
            el.dataset.pnr  = info.pnr || '';
          } else {
            el.dataset.mine = '0';
            el.dataset.pnr  = '';
          }
          el.title = rebuildTip(el.dataset.seatLabel || el.dataset.seat, info);
          el.setAttribute('aria-label', el.title);

          /* Pickup chip. Created on demand: a berth sold since the last tick
             had no .seat-stop at first paint, and one that was released must
             lose the chip rather than keep a stale code. Inserted before
             .seat-info so the order stays id / code / name. */
          var stopEl = el.querySelector('.seat-stop');
          if (info.stop) {
            if (!stopEl) {
              stopEl = document.createElement('span');
              stopEl.className = 'seat-stop';
              var before = el.querySelector('.seat-info');
              if (before) el.insertBefore(stopEl, before); else el.appendChild(stopEl);
            }
            stopEl.textContent = info.stop;
          } else if (stopEl) {
            stopEl.remove();
          }

          /* Same create-on-demand rule as the chip above. This used to only
             ever UPDATE an existing .seat-info, so an open berth (which is
             painted without one) stayed blank after it was sold — the name
             appeared only on a full page reload. */
          var label = '';
          if (info.status === 'booked' && info.passenger) {
            label = info.passenger.split(' ')[0];
          } else if (info.status === 'held')    { label = '⏳'; }
          else if (info.status === 'blocked')   { label = '🚫'; }
          else if (info.status === 'staff')     { label = '🛡️'; }

          var infoEl = el.querySelector('.seat-info');
          if (label !== '') {
            if (!infoEl) {
              infoEl = document.createElement('span');
              infoEl.className = 'seat-info';
              el.appendChild(infoEl);
            }
            infoEl.textContent = label;
          } else if (infoEl) {
            infoEl.textContent = '';
          }
        });

        /* Update summary count cards */
        ['open','booked','held','blocked','staff'].forEach(function(k){
          var el = document.getElementById('count-' + k);
          if (el) el.textContent = data.counts[k] || 0;
        });
      })
      .catch(function(){ /* network error — silently skip this tick */ });
  }, 15000);
})();
</script>

<?php endif; ?>
<?php
admin_footer();
