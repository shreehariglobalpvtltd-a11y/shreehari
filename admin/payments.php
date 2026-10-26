<?php
/**
 * admin/payments.php — Payment Verification Center.
 *
 * A tabbed, filterable dashboard for verifying incoming payments,
 * settling COD, reviewing approved/rejected/refunded bookings.
 *
 * POST actions (approve / reject / hold / bulk_approve / cod_settle) funnel
 * through BookingService + PaymentRisk so the business rules live in one
 * place. When the POST includes X-Requested-With: XMLHttpRequest the
 * response is JSON, otherwise a flash + full-page render.
 *
 * 9 Oct 2026 (owner: "ticket fake badi hun layo — approval system
 * advanced, easy, kam risk"): the Pending tab is a review queue.
 *   - "Proof received" (a UTR or a screenshot is in) is split from
 *     "Awaiting payment" (nothing sent yet), so the desk works only what
 *     can be checked.
 *   - Every row carries a PaymentRisk score with its reasons in words,
 *     sorted risky-first then oldest-first; ALL screenshots show inline and
 *     open in a lightbox.
 *   - Approve / Reject / Hold open one bottom sheet (no browser pop-ups):
 *     the amount the reviewer sees, the bank-statement tick (MEDIUM), a
 *     written reason (HIGH) and, with pay_two_step_on, a second person.
 *     Reject uses reason chips that become the customer's bilingual text;
 *     Hold asks the customer for a clearer proof on WhatsApp and changes
 *     nothing else.
 *   - Keyboard: J / K move, A approve, R reject, H hold, Esc closes; the
 *     next row is focused after every decision.
 *   - "Approve N low-risk" approves up to 25 checked LOW rows; the server
 *     re-scores and re-checks the desk of every one and answers per row.
 * Nothing on this page rejects, cancels or refunds by itself (owner rule):
 * a HIGH score only asks a person to look harder.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/paymentrisk.php';
$admin = admin_boot('payments.view');

$base  = '';
$flash = null;
$isAjax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');
$me    = ['id' => (int) ($admin['id'] ?? 0), 'role' => (string) ($admin['role'] ?? '')];

/* ---- Source badge helper ---------------------------------------- */
function source_badge(string $source): string
{
    $map = [
        'web'     => ['Online',  '#0a6b3b', '#d7f4e3'],
        'app'     => ['Online',  '#0a6b3b', '#d7f4e3'],
        'agent'   => ['Agent',   '#1c3b72', '#e2ecfb'],
        'counter' => ['Counter', '#7a4a00', '#ffe6c7'],
        'admin'   => ['Admin',   '#5a3fb0', '#efeaff'],
    ];
    [$label, $fg, $bg] = $map[$source] ?? [ucfirst($source ?: 'Online'), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . Security::e($label) . '</span>';
}

/** Method badge for payment type. */
function method_badge(string $method): string
{
    $map = [
        'upi'    => ['UPI',    '#1a5c2e', '#d7f4e3'],
        'esewa'  => ['eSewa',  '#3d7a1c', '#e5f7d6'],
        'cash'   => ['Cash',   '#7a4a00', '#ffe6c7'],
        'bank'   => ['Bank',   '#1c3b72', '#e2ecfb'],
        'wallet' => ['Wallet', '#5a3fb0', '#efeaff'],
        'cod'    => ['COD',    '#8a6d00', '#fff4d1'],
    ];
    [$label, $fg, $bg] = $map[strtolower($method)] ?? [strtoupper($method), '#333', '#eee'];
    return '<span class="pill" style="color:' . $fg . ';background:' . $bg . '">' . Security::e($label) . '</span>';
}

/* ---- Handle Approve / Reject / Hold / Bulk / COD Settle --------- */
$json = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $pnr = '';
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $action    = (string) ($_POST['action'] ?? '');
        $bookingId = (int) ($_POST['booking_id'] ?? 0);
        $note      = Security::clean($_POST['note'] ?? '', 255);

        try {
            if ($action === 'bulk_approve') {
                /* Up to 25 checked LOW-risk rows. Each one is re-scored and its
                   desk re-checked here (the page may be minutes old), then
                   approved on its own through the same gate (via 'bulk' = low
                   only). One row failing never stops the others. */
                Auth::requireAdmin('payments.verify');
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])))));
                $ids = array_slice($ids, 0, 25);
                if ($ids === []) {
                    throw new RuntimeException('Tick at least one low-risk payment first.');
                }
                $results = [];
                $okN = 0;
                foreach ($ids as $id) {
                    $row = ['id' => $id, 'pnr' => '', 'ok' => false, 'message' => ''];
                    try {
                        if (!CounterDesk::bookingVisible($id)) {
                            throw new RuntimeException('Belongs to another desk.');
                        }
                        $risk = PaymentRisk::score($id);
                        if ($risk === null) {
                            throw new RuntimeException('Booking not found.');
                        }
                        $row['pnr'] = (string) $risk['pnr'];
                        $st = (string) Database::scalar('SELECT status FROM bookings WHERE id = :i', ['i' => $id], '');
                        if ($st !== 'pending') {
                            throw new RuntimeException('Already ' . $st . '.');
                        }
                        if (!$risk['hasProof']) {
                            throw new RuntimeException('No proof yet — approve it on its own.');
                        }
                        if ($risk['level'] !== 'low') {
                            throw new RuntimeException('Now ' . strtoupper((string) $risk['level']) . ' risk (' . PaymentRisk::codesHuman($risk) . ') — approve it on its own.');
                        }
                        BookingService::approveReviewed($id, $me, ['via' => 'bulk', 'note' => 'Bulk approve (low risk)']);
                        $row['ok'] = true;
                        $row['message'] = 'Approved — ticket issued.';
                        $okN++;
                    } catch (Throwable $e) {
                        $row['message'] = $e->getMessage();
                    }
                    $results[] = $row;
                }
                $json['results']  = $results;
                $json['approved'] = $okN;
                $flash = [$okN > 0 ? 'ok' : 'bad', $okN . ' of ' . count($ids) . ' approved' . ($okN < count($ids) ? ' — the rest need a closer look.' : '.')];
            } else {
                if (!CounterDesk::bookingVisible($bookingId)) {   // desk isolation (7 Oct 2026)
                    throw new RuntimeException('This booking belongs to another desk.');
                }
                if ($action === 'approve') {
                    Auth::requireAdmin('payments.verify');
                    $res = BookingService::approveReviewed($bookingId, $me, [
                        'note'        => $note,
                        'bankChecked' => !empty($_POST['bank_checked']),
                        'amountSeen'  => $_POST['amount_seen'] ?? null,
                        'via'         => 'queue',
                    ]);
                    $pnr = (string) ($res['booking']['pnr'] ?? '');
                    $json['state'] = $res['state'];
                    $json['risk']  = ['score' => $res['risk']['score'], 'level' => $res['risk']['level']];
                    $flash = $res['state'] === 'awaiting_second'
                        ? ['ok', 'First approval recorded for ' . $pnr . ' — a super-admin, manager or accountant must give the second approval.']
                        : ['ok', 'Payment verified — ticket issued for ' . $pnr . '.'];
                } elseif ($action === 'reject') {
                    Auth::requireAdmin('payments.reject');
                    $code = Security::clean($_POST['reason_code'] ?? '', 40);
                    if ($code === 'other' && $note === '') {
                        throw new RuntimeException('Write the reason the customer should read.');
                    }
                    $reason = isset(PaymentRisk::REASONS[$code])
                        ? PaymentRisk::customerText($code, $note)
                        : ($note !== '' ? $note : 'Payment could not be verified.');
                    $b = BookingService::rejectReviewed($bookingId, (int) $admin['id'], $reason, ['reasonCode' => $code, 'note' => $note, 'via' => 'queue']);
                    $pnr = (string) ($b['pnr'] ?? '');
                    $flash = ['ok', 'Booking ' . $pnr . ' rejected and seats released. The customer was told why.'];
                } elseif ($action === 'hold') {
                    Auth::requireAdmin('payments.verify');
                    $h = PaymentRisk::hold($bookingId, $me, $note, 'queue');
                    $pnr = (string) ($h['risk']['pnr'] ?? '');
                    $json['link'] = $h['link'];
                    $json['sent'] = $h['sent'];
                    $flash = ['ok', $h['sent']
                        ? 'Asked the customer on WhatsApp for a clearer proof — ' . $pnr . ' stays pending.'
                        : 'Hold recorded for ' . $pnr . '. WhatsApp did not go out by itself — tap "Send on WhatsApp" to send it.'];
                } elseif ($action === 'cod_settle') {
                    Auth::requireAdmin('payments.verify');
                    $b = BookingService::settleCod($bookingId, (int) $admin['id'], $note);
                    $pnr = $b['pnr'] ?? '';
                    $flash = ['ok', 'Cash recorded for ' . $pnr . ' — that payment is settled.'];
                } else {
                    $flash = ['bad', 'Unknown action.'];
                }
            }
        } catch (PaymentRiskGate $g) {
            $flash = ['bad', $g->getMessage()];
            $json['need'] = $g->need;
            if ($g->risk !== []) {
                $json['risk'] = ['score' => $g->risk['score'] ?? 0, 'level' => $g->risk['level'] ?? ''];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }

    /* AJAX: return JSON and exit */
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'ok'      => $flash[0] === 'ok',
            'message' => $flash[1],
            'pnr'     => $pnr ?? '',
        ] + $json, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/* ---- Tab + filter params ---------------------------------------- */
$validTabs = ['all', 'pending', 'approved', 'rejected', 'cod', 'refunded'];
$tab       = Security::clean($_GET['tab'] ?? 'pending', 20);
if (!in_array($tab, $validTabs, true)) { $tab = 'pending'; }
$sub       = Security::clean($_GET['sub'] ?? 'proof', 10);
if (!in_array($sub, ['proof', 'await', 'all'], true)) { $sub = 'proof'; }

$fSearch   = Security::clean($_GET['q'] ?? '', 60);
$fRoute    = (int) ($_GET['route'] ?? 0);
$fAgent    = (int) ($_GET['agent'] ?? 0);
$fMethod   = Security::clean($_GET['method'] ?? '', 20);
$fSource   = Security::clean($_GET['src'] ?? '', 20);
$fDateFrom = Security::clean($_GET['from'] ?? '', 10);
$fDateTo   = Security::clean($_GET['to'] ?? '', 10);
$isDate    = static fn (string $d): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1;

/* Desk isolation (4 Oct 2026, ships OFF - Admin -> Counters & collection):
   a counter login sees only its own desk's payments. Since 9 Oct 2026 the
   summary cards and tab counts honour it too (they counted the whole
   company). Applied before the user's own filters so it cannot be widened
   by editing the query string. */
$deskScope = Auth::deskScopeCode();
$deskOn    = $deskScope !== null && CounterDesk::stampColumn();
$deskAnd   = $deskOn ? ' AND ' . CounterDesk::scopeClause('b') : '';
$deskP     = $deskOn ? ['deskScope' => $deskScope] : [];

/* ---- Summary cards (efficient separate queries) ----------------- */
/* 7 Oct 2026 (owner: "hunx"): the company's money for the day is a report.
   A counter clerk holds payments.view to verify their own customers, not
   reports.view - they see the counts, never the company-wide rupee totals. */
$showMoney = Auth::can('reports.view');
$pendingCount = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bookings b WHERE b.status='pending'" . $deskAnd, $deskP
)['c'];

$approvedRow = Database::fetch(
    "SELECT COUNT(*) AS c, COALESCE(SUM(b.total_amount),0) AS t
       FROM bookings b WHERE b.status='confirmed' AND b.confirmed_at >= CURDATE() AND b.confirmed_at < CURDATE() + INTERVAL 1 DAY" . $deskAnd, $deskP
);
$approvedCount = (int) $approvedRow['c'];
$approvedTotal = (float) $approvedRow['t'];

$rejectedCount = (int) Database::fetch(
    "SELECT COUNT(*) AS c FROM bookings b WHERE b.status='rejected' AND b.updated_at >= CURDATE() AND b.updated_at < CURDATE() + INTERVAL 1 DAY" . $deskAnd, $deskP
)['c'];

$codRow = Database::fetch(
    "SELECT COUNT(*) AS c, COALESCE(SUM(b.total_amount),0) AS t
       FROM bookings b
       JOIN payments p ON p.booking_id=b.id
            AND p.id=(SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id=b.id)
      WHERE b.is_cod=1 AND p.status='cod_pending'
        AND b.status NOT IN ('cancelled','rejected')" . $deskAnd, $deskP
);
$codCount = (int) $codRow['c'];
$codTotal = (float) $codRow['t'];

$collectionTotal = (float) Database::fetch(
    "SELECT COALESCE(SUM(b.total_amount),0) AS t
       FROM bookings b WHERE b.status='confirmed' AND b.confirmed_at >= CURDATE() AND b.confirmed_at < CURDATE() + INTERVAL 1 DAY" . $deskAnd, $deskP
)['t'];

/* Pending split: proof received (a UTR or a screenshot) vs awaiting payment. */
$proofExpr = "(TRIM(COALESCE(p.utr_number,'')) <> '' OR EXISTS (SELECT 1 FROM payment_screenshots ps0 WHERE ps0.booking_id = b.id))";
$splitRow = Database::fetch(
    "SELECT COALESCE(SUM({$proofExpr}), 0) AS proof, COUNT(*) AS n
       FROM bookings b
       JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
      WHERE b.status = 'pending'" . $deskAnd, $deskP
) ?? ['proof' => 0, 'n' => 0];
$proofCount = (int) $splitRow['proof'];
$awaitCount = max(0, (int) $splitRow['n'] - $proofCount);

/* ---- Filter dropdowns data -------------------------------------- */
$routeList = Database::fetchAll(
    "SELECT id, route_code, from_city, to_city FROM routes ORDER BY from_city, to_city"
);
$agentList = Database::fetchAll(
    "SELECT id, username, full_name FROM admins WHERE role='agent' ORDER BY full_name, username"
);
$agentCodes = Settings::getArray('agent_codes', []);

/* ---- Build main query ------------------------------------------- */
$where  = [];
$params = [];

/* Tab filter */
switch ($tab) {
    case 'pending':
        $where[] = "b.status = 'pending'";
        if ($sub === 'proof') { $where[] = $proofExpr; }
        elseif ($sub === 'await') { $where[] = 'NOT ' . $proofExpr; }
        break;
    case 'approved':
        $where[] = "b.status = 'confirmed'";
        break;
    case 'rejected':
        $where[] = "b.status = 'rejected'";
        break;
    case 'cod':
        $where[] = "b.is_cod = 1 AND p.status = 'cod_pending' AND b.status NOT IN ('cancelled','rejected')";
        break;
    case 'refunded':
        $where[] = "b.refund_status IN ('pending','processed')";
        break;
    // 'all' — no status filter
}

if ($deskOn) {
    $where[] = CounterDesk::scopeClause('b');
    $params['deskScope'] = $deskScope;
}

/* Search filter */
if ($fSearch !== '') {
    $where[] = sqlSearchClause([
        'b.pnr LIKE %s',
        'b.contact_phone LIKE %s',
        'p.utr_number LIKE %s',
        'EXISTS (SELECT 1 FROM booking_passengers bp2
                  WHERE bp2.booking_id = b.id AND bp2.full_name LIKE %s)',
    ], $fSearch, $params, 'sq');
}

/* Route filter */
if ($fRoute > 0) {
    $where[] = 'r.id = :fRoute';
    $params['fRoute'] = $fRoute;
}

/* Agent filter */
if ($fAgent > 0) {
    $where[] = 'b.sold_by_admin_id = :fAgent';
    $params['fAgent'] = $fAgent;
}

/* Payment method filter */
$validMethods = ['upi', 'esewa', 'cash', 'bank', 'wallet', 'cod'];
if (in_array($fMethod, $validMethods, true)) {
    $where[] = 'p.method = :fMethod';
    $params['fMethod'] = $fMethod;
}

/* Booking source filter */
$validSources = ['web', 'app', 'agent', 'counter', 'admin'];
if (in_array($fSource, $validSources, true)) {
    $where[] = 'b.source = :fSource';
    $params['fSource'] = $fSource;
}

/* Date range (travel date) */
if ($isDate($fDateFrom)) {
    $where[] = 'bl.travel_date >= :fDateFrom';
    $params['fDateFrom'] = $fDateFrom;
}
if ($isDate($fDateTo)) {
    $where[] = 'bl.travel_date <= :fDateTo';
    $params['fDateTo'] = $fDateTo;
}

$orderBy  = ($tab === 'pending') ? 'b.created_at ASC' : 'b.created_at DESC';
$claimCol = PaymentRisk::hasCol('payments', 'claimed_amount') ? 'p.claimed_amount' : 'NULL';

$sql = "SELECT b.id, b.pnr, b.status, b.total_amount, b.contact_phone, b.source,
               b.sold_by_admin_id, b.is_cod, b.created_at, b.booking_mode, b.refund_status,
               p.method, p.utr_number, p.payer_name, p.status AS pay_status,
               p.reject_reason, p.verified_at, {$claimCol} AS claimed_amount,
               r.from_city, r.to_city, r.route_code,
               bl.travel_date,
               bu.bus_name,
               (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = b.id) AS seat_count,
               (SELECT file_path FROM payment_screenshots ps WHERE ps.booking_id = b.id ORDER BY ps.id DESC LIMIT 1) AS shot,
               (SELECT GROUP_CONCAT(CONCAT(ps3.id, ':', ps3.mime_type) ORDER BY ps3.id DESC SEPARATOR ',')
                  FROM payment_screenshots ps3 WHERE ps3.booking_id = b.id) AS shot_list,
               /* 8 Oct 2026 (D8-06): the same UTR or the same screenshot
                  (sha256, stored since day one, never compared) on ANOTHER
                  booking. Shown as a red pill only — the desk decides; nothing
                  is blocked or rejected automatically. ix_payments_utr and
                  ix_shots_hash serve both lookups. */
               (SELECT GROUP_CONCAT(DISTINCT b2.pnr ORDER BY b2.pnr SEPARATOR ', ')
                  FROM payments p3 JOIN bookings b2 ON b2.id = p3.booking_id
                 WHERE p.utr_number REGEXP '[A-Za-z0-9]{6,}'   -- 'na' / 'cash' / '0' are not UTRs (same rule as booking-view)
                   AND p3.utr_number = p.utr_number
                   AND p3.booking_id <> b.id) AS utr_also_on,
               (SELECT GROUP_CONCAT(DISTINCT bx.pnr ORDER BY bx.pnr SEPARATOR ', ')
                  FROM payment_screenshots s1
                  JOIN payment_screenshots s2 ON s2.sha256 = s1.sha256 AND s2.booking_id <> s1.booking_id
                  JOIN bookings bx ON bx.id = s2.booking_id
                 WHERE s1.booking_id = b.id AND s1.sha256 IS NOT NULL AND s1.sha256 <> '') AS shot_also_on,
               seller.full_name AS seller_name
          FROM bookings b
          JOIN payments p ON p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id = b.id)
          LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
          LEFT JOIN schedules s ON s.id = bl.schedule_id
          LEFT JOIN routes r ON r.id = s.route_id
          LEFT JOIN buses bu ON bu.id = s.bus_id
          LEFT JOIN admins seller ON seller.id = b.sold_by_admin_id"
     . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
     . ' ORDER BY ' . $orderBy
     . ' LIMIT 200';

$rows = Database::fetchAll($sql, $params);

/* ---- Risk for the review queue ------------------------------------
   One grouped scoring pass for the page, then risky first, oldest first. */
$isQueue = $tab === 'pending';
$risk = [];
$firstApprovals = [];
$lastHolds = [];
if ($isQueue && $rows !== []) {
    try {
        $risk = PaymentRisk::scoreMany(array_column($rows, 'id'));
    } catch (Throwable $e) {
        Logger::warning('PaymentRisk::scoreMany failed on the queue', ['e' => $e->getMessage()]);
        $risk = [];
    }
    usort($rows, static function (array $a, array $b) use ($risk): int {
        $sa = (int) ($risk[(int) $a['id']]['score'] ?? 0);
        $sb = (int) ($risk[(int) $b['id']]['score'] ?? 0);
        return $sb <=> $sa ?: strcmp((string) $a['created_at'], (string) $b['created_at']);
    });
    if (PaymentRisk::available()) {
        $idsIn = implode(',', array_map('intval', array_column($rows, 'id')));
        try {
            foreach (Database::fetchAll(
                "SELECT r.booking_id, r.admin_id, r.created_at, a.full_name, a.username
                   FROM payment_reviews r LEFT JOIN admins a ON a.id = r.admin_id
                  WHERE r.booking_id IN ({$idsIn}) AND r.step = 'approve1'
                    AND r.created_at >= NOW() - INTERVAL 24 HOUR
                    AND NOT EXISTS (SELECT 1 FROM payment_reviews r2
                                     WHERE r2.booking_id = r.booking_id AND r2.id > r.id AND r2.step IN ('approve','reject'))
                  ORDER BY r.id"
            ) as $fa) {
                $firstApprovals[(int) $fa['booking_id']] = $fa;
            }
            foreach (Database::fetchAll(
                "SELECT booking_id, MAX(created_at) AS at FROM payment_reviews
                  WHERE booking_id IN ({$idsIn}) AND step = 'hold' GROUP BY booking_id"
            ) as $h) {
                $lastHolds[(int) $h['booking_id']] = (string) $h['at'];
            }
        } catch (Throwable $e) {
            // review history is a nicety on this screen
        }
    }
}
$levelCounts = ['high' => 0, 'medium' => 0, 'low' => 0];
$lowReady = 0;
foreach ($risk as $rk) {
    if (isset($levelCounts[$rk['level']])) {
        $levelCounts[$rk['level']]++;
    }
    if ($rk['level'] === 'low' && $rk['hasProof']) {
        $lowReady++;
    }
}
$twoStep = PaymentRisk::twoStepOn();

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/* ---- Build query string helper for tabs (preserves filters) ----- */
$filterQs = array_filter([
    'q'      => $fSearch,
    'route'  => $fRoute > 0 ? (string) $fRoute : '',
    'agent'  => $fAgent > 0 ? (string) $fAgent : '',
    'method' => $fMethod,
    'src'    => $fSource,
    'from'   => $isDate($fDateFrom) ? $fDateFrom : '',
    'to'     => $isDate($fDateTo)   ? $fDateTo   : '',
]);
$hasFilters = ($fSearch !== '' || $fRoute > 0 || $fAgent > 0 || $fMethod !== '' || $fSource !== '' || $isDate($fDateFrom) || $isDate($fDateTo));

function tabUrl(string $tabName, array $filterQs, string $sub = ''): string
{
    $qs = $filterQs;
    $qs['tab'] = $tabName;
    if ($sub !== '') { $qs['sub'] = $sub; }
    return '?'. http_build_query($qs);
}

/** "S HARI" agent code for a seller id, or ''. */
$agentCodeOf = static function (int $soldBy) use ($agentCodes): string {
    return ($soldBy > 0 && isset($agentCodes[(string) $soldBy]))
        ? 'SHG-' . str_pad((string) (int) $agentCodes[(string) $soldBy], 4, '0', STR_PAD_LEFT) : '';
};

admin_header('Verify Payments', 'payments');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<style>
/* ---- Payment page styles ---------------------------------------- */
.pay-tabs{display:flex;gap:0;margin-bottom:18px;background:var(--card);border:1px solid var(--line);border-radius:12px;overflow:hidden;flex-wrap:wrap}
.pay-tabs a{flex:1;min-width:100px;padding:12px 14px;text-align:center;font-size:13px;font-weight:700;color:var(--mut);text-decoration:none;border-right:1px solid var(--line);transition:background .15s,color .15s}
.pay-tabs a:last-child{border-right:0}
.pay-tabs a:hover{background:var(--hover);color:var(--ink)}
.pay-tabs a.active{background:var(--navy);color:#fff}
.pay-tabs .tab-count{display:block;font-size:18px;font-weight:800;margin-bottom:2px;line-height:1.2}
.pay-tabs .tab-label{font-size:11px;text-transform:uppercase;letter-spacing:.3px}

.pay-filter{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:end}
.pay-filter label{display:flex;flex-direction:column;gap:3px;font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}
.pay-filter input,.pay-filter select{padding:8px 10px;border:1px solid var(--line);border-radius:9px;font-size:13px;background:var(--card);color:var(--ink)}
.pay-filter input[type="search"]{min-width:180px}
.pay-filter-box{margin:0 0 16px}
.pay-filter-box > summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:var(--card);font-weight:700;font-size:13px;color:var(--ink)}
.pay-filter-box > summary::-webkit-details-marker{display:none}
.pay-filter-box[open] > summary{margin-bottom:12px}

.tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
@media(min-width:821px){.tbl-wrap table{min-width:900px}}

tr.row-processing{opacity:.45;pointer-events:none;transition:opacity .3s}
tr.row-done td{background:var(--hover)}

.note-inline{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.note-inline input{flex:1;min-width:80px;padding:6px 8px;border:1px solid var(--line);border-radius:8px;font-size:12px;background:var(--card);color:var(--ink)}

.agent-tag{font-size:11px;color:var(--mut);white-space:nowrap}
.agent-tag strong{color:var(--ink);font-weight:700}

/* ---- Review queue (9 Oct 2026) ----------------------------------- */
.pq-sub{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 12px}
.pq-sub a{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:8px 16px;border-radius:999px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-weight:700;font-size:13.5px;text-decoration:none}
.pq-sub a b{min-width:24px;text-align:center;background:var(--soft);border-radius:999px;padding:1px 8px;font-size:12px}
.pq-sub a.on{background:var(--royal);color:var(--on-royal);border-color:var(--royal)}
.pq-sub a.on b{background:rgba(255,255,255,.22);color:inherit}
.pq-sum{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 14px;padding:10px 12px;border-radius:16px;background:var(--card);border:1px solid var(--line);box-shadow:var(--dp-card,var(--sh-1))}
.pq-sum .pq-lv{display:inline-flex;align-items:center;gap:6px;font-weight:800;font-size:13px;padding:6px 12px;border-radius:999px}
.pq-sum .grow{flex:1}
.pq-sum .pq-keys{font-size:11.5px;color:var(--mut)}
.pq-sum kbd,.pq-btn kbd{font:700 10.5px/1 var(--f-mono);padding:2px 5px;border-radius:5px;border:1px solid currentColor;opacity:.75}
.lv-high{--lv:var(--danger);--lv-bg:var(--danger-soft)}
.lv-medium{--lv:var(--warning);--lv-bg:var(--warning-soft)}
.lv-low{--lv:var(--success);--lv-bg:var(--success-soft)}
.lv-skip{--lv:var(--muted);--lv-bg:var(--soft)}
.pq-lv,.pq-risk{color:var(--lv);background:var(--lv-bg)}

.pq-list{display:grid;gap:14px;margin-bottom:90px}
.pq-card{position:relative;background:var(--dp-face,var(--card));border:1px solid var(--dp-border,var(--line));border-left:6px solid var(--lv);border-radius:18px;box-shadow:var(--dp-card,var(--sh-2));padding:14px 14px 12px;transition:opacity .25s,transform .25s,max-height .35s;scroll-margin:90px 0 120px}
.pq-card.on{box-shadow:0 0 0 3px var(--royal),var(--dp-card,var(--sh-2))}
.pq-card.busy{opacity:.55;pointer-events:none}
.pq-card.done{opacity:0;transform:translateX(24px)}
.pq-top{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.pq-pick{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;margin:-4px 0 -4px -4px;cursor:pointer}
.pq-pick input{width:20px;height:20px;accent-color:var(--success)}
.pq-risk{display:inline-flex;align-items:baseline;gap:6px;font-weight:800;font-size:12.5px;letter-spacing:.04em;padding:5px 11px;border-radius:999px;border:1px solid color-mix(in srgb,var(--lv) 40%,transparent)}
.pq-risk small{font-size:11px;font-weight:700;opacity:.85}
.pq-pnr{font:800 15px/1.2 var(--f-mono);color:var(--ink)}
.pq-age{font-size:12px;color:var(--mut)}
.pq-badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:800;padding:4px 10px;border-radius:999px}
.pq-badge.bank{background:var(--success-soft);color:var(--success)}
.pq-badge.two{background:var(--royal-tint);color:var(--royal)}
.pq-badge.held{background:var(--warning-soft);color:var(--warning)}
.pq-top .grow{flex:1}
.pq-main{display:grid;grid-template-columns:minmax(0,auto) minmax(0,1fr);gap:6px 18px;align-items:start}
.pq-amt b{display:block;font-size:24px;font-weight:900;line-height:1.1;color:var(--ink);letter-spacing:-.01em}
.pq-amt small{display:block;font-size:12px;color:var(--mut);margin-top:2px}
.pq-pay{font-size:13px;color:var(--ink);min-width:0}
.pq-utr{display:inline-flex;align-items:center;gap:6px;font:700 13.5px/1.3 var(--f-mono);background:var(--soft);border:1px solid var(--line);border-radius:9px;padding:3px 4px 3px 9px;margin-top:4px;max-width:100%;overflow-wrap:anywhere}
.pq-copy{border:0;background:var(--card);color:var(--royal);border-radius:7px;min-width:34px;min-height:30px;font-size:13px;cursor:pointer}
.pq-claim{display:block;font-size:12px;margin-top:4px;color:var(--mut)}
.pq-claim.bad{color:var(--danger);font-weight:700}
.pq-trip{grid-column:1/-1;font-size:12.5px;color:var(--mut)}
.pq-reasons{list-style:none;margin:10px 0 0;padding:0;display:flex;flex-direction:column;gap:5px}
.pq-reasons li{display:flex;align-items:flex-start;gap:8px;font-size:12.5px;line-height:1.4;color:var(--ink)}
.pq-reasons li .w{flex:0 0 auto;min-width:38px;text-align:center;font:800 11px/1.6 var(--f-mono);border-radius:6px;padding:0 5px;color:var(--danger);background:var(--danger-soft)}
.pq-reasons li.w-mid .w{color:var(--warning);background:var(--warning-soft)}
.pq-reasons li.w-neg .w{color:var(--success);background:var(--success-soft)}
.pq-reasons li small{display:block;color:var(--mut);font-size:11.5px}
.pq-reasons li a{font-family:var(--f-mono);font-size:11.5px;text-decoration:underline}
.pq-reasons li.none{color:var(--success);font-weight:700}
.pq-shots{display:flex;gap:8px;overflow-x:auto;padding:10px 0 2px;-webkit-overflow-scrolling:touch}
.pq-shot{flex:0 0 auto;width:74px;height:110px;border-radius:10px;border:1px solid var(--line);background:var(--soft) center/cover no-repeat;overflow:hidden;cursor:zoom-in;padding:0;position:relative}
.pq-shot img{width:100%;height:100%;object-fit:cover;object-position:top;display:block}
.pq-shot.pdf{display:flex;align-items:center;justify-content:center;font:800 13px/1 var(--f-ui);color:var(--danger);cursor:pointer;text-decoration:none}
.pq-shot em{position:absolute;left:4px;bottom:4px;font:800 10px/1 var(--f-ui);font-style:normal;background:rgba(0,0,0,.6);color:#fff;border-radius:5px;padding:2px 5px}
.pq-noshot{font-size:12px;color:var(--mut);padding:10px 0 0}
.pq-actions{display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:8px;margin-top:12px}
.pq-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap;min-height:48px;padding:10px 12px;border-radius:14px;border:1px solid transparent;font:800 15px/1.1 var(--f-ui);color:#fff;cursor:pointer;text-decoration:none;touch-action:manipulation;transition:transform .12s ease-out,filter .12s,box-shadow .12s}
.pq-btn:active{transform:translateY(3px)}
.pq-btn:focus-visible{outline:3px solid var(--royal);outline-offset:2px}
.pq-btn.ok{background:linear-gradient(180deg,#1C8A55 0%,#11703F 100%);border-color:#3FA874;box-shadow:inset 0 1px 0 rgba(255,255,255,.35),0 2px 0 #0B5530,0 4px 0 #084526,0 8px 14px -5px rgba(6,55,30,.5)}
.pq-btn.bad{background:linear-gradient(180deg,#C83737 0%,#B42525 100%);border-color:#E06A6A;box-shadow:inset 0 1px 0 rgba(255,255,255,.3),0 2px 0 #8A1B1B,0 4px 0 #701515,0 8px 14px -5px rgba(90,16,16,.5)}
.pq-btn.hold{background:linear-gradient(180deg,#B45309 0%,#92400E 100%);border-color:#D97706;box-shadow:inset 0 1px 0 rgba(255,255,255,.3),0 2px 0 #7C2D12,0 4px 0 #6B260F,0 8px 14px -5px rgba(124,45,18,.5)}
.pq-btn.ghost{background:var(--card);color:var(--ink);border-color:var(--line-strong);box-shadow:0 2px 0 var(--line-strong)}
.pq-btn:hover{filter:brightness(1.06)}
.pq-btn[disabled]{opacity:.5;cursor:not-allowed}
.pq-btn kbd{border-color:rgba(255,255,255,.6)}
.pq-btn.ghost kbd{border-color:var(--line-strong)}
.pq-result{margin-top:10px;font-size:13px;font-weight:700;border-radius:10px;padding:9px 12px}
.pq-result.ok{background:var(--success-soft);color:var(--success)}
.pq-result.bad{background:var(--danger-soft);color:var(--danger)}
.pq-result a{color:inherit;text-decoration:underline}
.pq-empty{padding:34px 18px;text-align:center;color:var(--mut);font-size:14px}
.pq-empty b{display:block;font-size:20px;color:var(--ink);margin:6px 0}

/* bulk bar */
.pq-bulk{position:sticky;bottom:calc(var(--mnav-h, 0px) + 10px);z-index:20;display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:-76px 0 18px;padding:10px 12px;border-radius:18px;background:var(--card);border:1px solid var(--line-strong);box-shadow:var(--dp-sheet,var(--sh-3))}
.pq-bulk[hidden]{display:none}
.pq-bulk span{flex:1;font-weight:700;font-size:13.5px;color:var(--ink)}

/* bottom sheet */
.pq-back{position:fixed;inset:0;z-index:10000;background:var(--scrim)}
.pq-back[hidden],.pq-sheet[hidden],.pq-lb[hidden],.pq-sheet [hidden]{display:none !important}
.pq-sheet{position:fixed;z-index:10001;left:0;right:0;bottom:0;max-height:92vh;overflow:auto;margin:0 auto;max-width:640px;background:var(--card);color:var(--ink);border-radius:24px 24px 0 0;box-shadow:var(--dp-sheet,var(--sh-3));padding:8px 16px calc(16px + env(safe-area-inset-bottom));animation:pqUp .22s cubic-bezier(.2,.8,.2,1)}
@keyframes pqUp{from{transform:translateY(40px);opacity:.4}to{transform:none;opacity:1}}
.pq-grab{width:44px;height:5px;border-radius:3px;background:var(--line-strong);margin:4px auto 10px}
.pq-sh-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.pq-sh-head h3{margin:0;font-size:18px;flex:1;min-width:0}
.pq-x{border:0;background:var(--soft);color:var(--ink);width:44px;height:44px;border-radius:50%;font-size:20px;cursor:pointer}
.pq-sh-sum{display:flex;gap:10px 16px;flex-wrap:wrap;align-items:baseline;margin:8px 0 2px;font-size:13px;color:var(--mut)}
.pq-sh-sum b{font-size:22px;color:var(--ink)}
.pq-field{display:flex;flex-direction:column;gap:6px;margin:12px 0 0;font-size:13px;font-weight:700;color:var(--ink)}
.pq-field input,.pq-field textarea{font:600 16px/1.35 var(--f-ui);padding:11px 12px;border-radius:12px;border:1.5px solid var(--line-control,var(--line-strong));background:var(--surface-sunken,var(--card));color:var(--ink);width:100%}
.pq-field textarea{min-height:76px;resize:vertical}
.pq-field small{font-weight:600;color:var(--mut);font-size:11.5px}
.pq-tick{display:flex;gap:12px;align-items:flex-start;margin:12px 0 0;padding:12px;border-radius:14px;border:1.5px solid var(--line-strong);background:var(--surface-sunken,var(--soft));font-size:14px;font-weight:700;cursor:pointer;line-height:1.35}
.pq-tick input{width:24px;height:24px;flex:0 0 auto;accent-color:var(--success);margin-top:1px}
.pq-tick small{display:block;font-weight:600;color:var(--mut);font-size:12px;margin-top:2px}
.pq-tick.need,.pq-field.need textarea{border-color:var(--danger)}
.pq-gate{margin:12px 0 0;padding:10px 12px;border-radius:12px;font-size:13px;font-weight:700;background:var(--danger-soft);color:var(--danger)}
.pq-gate[hidden]{display:none}
.pq-info{margin:12px 0 0;padding:10px 12px;border-radius:12px;font-size:12.5px;background:var(--royal-tint);color:var(--ink);line-height:1.45}
.pq-chips{display:flex;flex-wrap:wrap;gap:8px;margin:12px 0 0;border:0;padding:0}
.pq-chips legend{font-size:13px;font-weight:700;margin-bottom:8px;padding:0}
.pq-chip{display:inline-flex;align-items:center;min-height:44px;padding:8px 14px;border-radius:999px;border:1.5px solid var(--line-strong);background:var(--card);font-weight:700;font-size:13.5px;cursor:pointer;color:var(--ink)}
.pq-chip input{position:absolute;opacity:0;pointer-events:none}
.pq-chip.on{background:var(--danger);border-color:var(--danger);color:var(--on-danger)}
.pq-chip:focus-within{outline:3px solid var(--royal);outline-offset:2px}
.pq-cust{margin:12px 0 0;padding:12px;border-radius:12px;background:var(--surface-sunken,var(--soft));border:1px dashed var(--line-strong);font-size:13px;line-height:1.5;white-space:pre-line}
.pq-cust small{display:block;font-size:11px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mut);margin-bottom:4px;white-space:normal}
.pq-go{width:100%;margin-top:16px;min-height:56px;font-size:17px}
.pq-bulk-list{max-height:30vh;overflow:auto;margin:10px 0 0;padding:0;list-style:none;font:600 13px/1.6 var(--f-mono)}
.pq-bulk-list li{display:flex;justify-content:space-between;gap:10px;border-bottom:1px dashed var(--line);padding:4px 0}
.pq-bulk-list li.ok{color:var(--success)}
.pq-bulk-list li.bad{color:var(--danger)}

/* lightbox */
.pq-lb{position:fixed;inset:0;z-index:10010;background:rgba(5,10,20,.92);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:12px}
.pq-lb img{max-width:100%;max-height:calc(100vh - 120px);object-fit:contain;border-radius:8px;background:#fff}
.pq-lb-bar{display:flex;gap:10px;align-items:center;margin-top:10px;color:#fff;font-size:13px;flex-wrap:wrap;justify-content:center}
.pq-lb-bar a{color:#fff;text-decoration:underline}
.pq-lb-btn{border:0;background:rgba(255,255,255,.16);color:#fff;min-width:48px;min-height:48px;border-radius:50%;font-size:22px;cursor:pointer}
.pq-lb-x{position:absolute;top:12px;right:12px}

@media(max-width:820px){
  .pay-tabs a{min-width:70px;padding:10px 8px}
  .pay-tabs .tab-count{font-size:15px}
  .pay-filter{flex-direction:column}
  .pay-filter label,.pay-filter input,.pay-filter select{width:100%}
  .pq-main{gap:4px 14px}
  .pq-amt b{font-size:22px}
  .pq-sub{flex-wrap:nowrap;overflow-x:auto;-webkit-overflow-scrolling:touch;padding-bottom:4px;margin-right:-16px;padding-right:16px}
  .pq-sub a{flex:0 0 auto;font-size:13px;padding:8px 13px}
  .pq-actions{grid-template-columns:repeat(3,minmax(0,1fr))}
  .pq-actions .ghost{grid-column:1/-1;min-height:44px}
  .pq-btn kbd,.pq-sum .pq-keys{display:none}
  /* phone: the queue is the job - the day's numbers shrink to one swipeable strip */
  .dash-hero.pq-compact{display:flex;overflow-x:auto;gap:8px;margin-bottom:12px;padding-bottom:4px;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .dash-hero.pq-compact::-webkit-scrollbar{display:none}
  .dash-hero.pq-compact .hcard{flex:0 0 auto;min-width:112px;padding:10px 12px !important;border-radius:14px}
  .dash-hero.pq-compact .hicon{display:none !important}
  .dash-hero.pq-compact .hk{font-size:10px;letter-spacing:.06em;margin:0}
  .dash-hero.pq-compact .hv{font-size:22px;line-height:1.15;margin:2px 0 0}
  .dash-hero.pq-compact .hsub{font-size:11px;margin:0}
}
@media(max-width:359px){.pq-main{grid-template-columns:minmax(0,1fr)}}
@media (prefers-reduced-motion: reduce){.pq-sheet{animation:none}.pq-card,.pq-btn{transition:none}}
html[data-motion="reduced"] .pq-sheet{animation:none}
html[data-motion="reduced"] .pq-card,html[data-motion="reduced"] .pq-btn{transition:none}
</style>

<!-- Summary Cards -->
<?php /* House style (.dash-hero/.hcard from _guard.php), not a third card
     system. payments.php was the only page in the panel with its own
     .sum-card/.sc-* language — and it is the screen staff look at most, so
     the odd one out was the most-seen one. data-stat hooks are unchanged:
     the AJAX approve/reject flow updates these counters in place. */ ?>
<div class="dash-hero<?= $isQueue ? ' pq-compact' : '' ?>" id="summaryCards">
  <div class="hcard hc-orange">
    <span class="hicon">⏳</span>
    <div class="hk">Pending Payments</div>
    <div class="hv" data-stat="pending"><?= $pendingCount ?></div>
    <div class="hsub"><?= $proofCount ?> with proof</div>
  </div>
  <div class="hcard hc-green">
    <span class="hicon">✅</span>
    <div class="hk">Approved Today</div>
    <div class="hv" data-stat="approved"><?= $approvedCount ?></div>
    <?php if ($showMoney): ?><div class="hsub"><?= Security::e(inr($approvedTotal)) ?></div><?php endif; ?>
  </div>
  <div class="hcard hc-red">
    <span class="hicon">✕</span>
    <div class="hk">Rejected Today</div>
    <div class="hv" data-stat="rejected"><?= $rejectedCount ?></div>
  </div>
  <div class="hcard hc-teal">
    <span class="hicon">💵</span>
    <div class="hk">Cash Due</div>
    <div class="hv" data-stat="cod"><?= $codCount ?></div>
    <?php if ($showMoney): ?><div class="hsub"><?= Security::e(inr($codTotal)) ?></div><?php endif; ?>
  </div>
  <?php if ($showMoney): ?>
  <div class="hcard hc-blue">
    <span class="hicon">📈</span>
    <div class="hk">Collection Today</div>
    <div class="hv"><?= Security::e(inr($collectionTotal)) ?></div>
  </div>
  <?php endif; ?>
</div>

<!-- Tabs -->
<div class="pay-tabs">
  <?php
  $tabDefs = [
      'all'      => ['All',       null],
      'pending'  => ['Pending',   $pendingCount],
      'approved' => ['Approved',  $approvedCount],
      'rejected' => ['Rejected',  $rejectedCount],
      'cod'      => ['Cash Due',  $codCount],
      'refunded' => ['Refunded',  null],
  ];
  foreach ($tabDefs as $tKey => [$tLabel, $tCount]): ?>
    <a href="<?= Security::e(tabUrl($tKey, $filterQs)) ?>" class="<?= $tab === $tKey ? 'active' : '' ?>">
      <?php if ($tCount !== null): ?><span class="tab-count"><?= (int) $tCount ?></span><?php endif; ?>
      <span class="tab-label"><?= Security::e($tLabel) ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php if ($isQueue): ?>
<nav class="pq-sub" aria-label="Pending payments">
  <a href="<?= Security::e(tabUrl('pending', $filterQs, 'proof')) ?>" class="<?= $sub === 'proof' ? 'on' : '' ?>" data-sub="proof">🧾 Proof received · प्रमाण आयो <b data-sub-count="proof"><?= $proofCount ?></b></a>
  <a href="<?= Security::e(tabUrl('pending', $filterQs, 'await')) ?>" class="<?= $sub === 'await' ? 'on' : '' ?>" data-sub="await">⌛ Awaiting payment · भुक्तानी बाँकी <b data-sub-count="await"><?= $awaitCount ?></b></a>
  <a href="<?= Security::e(tabUrl('pending', $filterQs, 'all')) ?>" class="<?= $sub === 'all' ? 'on' : '' ?>" data-sub="all">All pending <b><?= $pendingCount ?></b></a>
</nav>
<?php endif; ?>

<!-- Filter Bar -->
<details class="pay-filter-box"<?= $hasFilters || !$isQueue ? ' open' : '' ?>>
<summary>🔎 Search &amp; filters<?= $hasFilters ? ' · on' : '' ?></summary>
<form class="pay-filter" method="get">
  <input type="hidden" name="tab" value="<?= Security::e($tab) ?>">
  <?php if ($isQueue): ?><input type="hidden" name="sub" value="<?= Security::e($sub) ?>"><?php endif; ?>
  <label>
    Search
    <input type="search" name="q" placeholder="PNR / phone / name / UTR" value="<?= Security::e($fSearch) ?>">
  </label>
  <label>
    Travel Date From
    <input type="date" name="from" value="<?= Security::e($isDate($fDateFrom) ? $fDateFrom : '') ?>">
  </label>
  <label>
    Travel Date To
    <input type="date" name="to" value="<?= Security::e($isDate($fDateTo) ? $fDateTo : '') ?>">
  </label>
  <label>
    Route
    <select name="route">
      <option value="">All routes</option>
      <?php foreach ($routeList as $rt): ?>
        <option value="<?= (int) $rt['id'] ?>" <?= $fRoute === (int) $rt['id'] ? 'selected' : '' ?>><?= Security::e(($rt['from_city'] ?? '') . ' → ' . ($rt['to_city'] ?? '')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    Agent
    <select name="agent">
      <option value="">All agents</option>
      <?php foreach ($agentList as $ag):
          $aCode = $agentCodeOf((int) $ag['id']);
      ?>
        <option value="<?= (int) $ag['id'] ?>" <?= $fAgent === (int) $ag['id'] ? 'selected' : '' ?>><?= Security::e(($aCode !== '' ? $aCode . ' ' : '') . ($ag['full_name'] ?: $ag['username'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>
    Payment Method
    <select name="method">
      <option value="">All methods</option>
      <option value="upi"    <?= $fMethod === 'upi' ? 'selected' : '' ?>>UPI</option>
      <option value="esewa"  <?= $fMethod === 'esewa' ? 'selected' : '' ?>>eSewa</option>
      <option value="cash"   <?= $fMethod === 'cash' ? 'selected' : '' ?>>Cash</option>
      <option value="bank"   <?= $fMethod === 'bank' ? 'selected' : '' ?>>Bank</option>
      <option value="cod"    <?= $fMethod === 'cod' ? 'selected' : '' ?>>COD</option>
      <option value="wallet" <?= $fMethod === 'wallet' ? 'selected' : '' ?>>Wallet</option>
    </select>
  </label>
  <label>
    Source
    <select name="src">
      <option value="">All sources</option>
      <option value="web"     <?= $fSource === 'web' ? 'selected' : '' ?>>Online</option>
      <option value="agent"   <?= $fSource === 'agent' ? 'selected' : '' ?>>Agent</option>
      <option value="counter" <?= $fSource === 'counter' ? 'selected' : '' ?>>Counter</option>
      <option value="admin"   <?= $fSource === 'admin' ? 'selected' : '' ?>>Admin</option>
    </select>
  </label>
  <div style="display:flex;gap:8px;align-items:end;padding-bottom:1px">
    <button class="btn" type="submit">Apply</button>
    <?php if ($hasFilters): ?><a class="btn ghost" href="?tab=<?= Security::e($tab) ?>">Clear</a><?php endif; ?>
  </div>
</form>
</details>

<?php
  /* Who opened the QR / tapped the pay link, per booking — one query
     for the whole page rather than one per row. */
  require_once INCLUDE_PATH . '/payevents.php';
  $payTrail = PayEvents::summary(array_column($rows, 'id'));
?>

<?php if ($isQueue): /* ============ REVIEW QUEUE (cards) ============ */ ?>

<?php if ($rows !== []): ?>
<div class="pq-sum" id="pqSum">
  <span class="pq-lv lv-high">● <span data-lv-count="high"><?= $levelCounts['high'] ?></span> high</span>
  <span class="pq-lv lv-medium">● <span data-lv-count="medium"><?= $levelCounts['medium'] ?></span> medium</span>
  <span class="pq-lv lv-low">● <span data-lv-count="low"><?= $levelCounts['low'] ?></span> low</span>
  <span class="grow"></span>
  <span class="pq-keys"><kbd>J</kbd>/<kbd>K</kbd> move · <kbd>A</kbd> approve · <kbd>H</kbd> hold · <kbd>R</kbd> reject</span>
  <?php if ($lowReady > 0 && Auth::can('payments.verify')): ?>
    <button type="button" class="pq-btn ghost" id="pqPickLow" style="min-height:40px;font-size:13px">☑ Select <?= $lowReady ?> low-risk</button>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="pq-list" id="pqList">
<?php if ($rows === []): ?>
  <div class="panel pq-empty">
    <div style="font-size:34px">✅</div>
    <?php if ($sub === 'proof'): ?>
      <b>All caught up — no proof waiting.</b>
      Nothing to check right now. सबै जाँच भइसक्यो।
      <?php if ($awaitCount > 0): ?><div style="margin-top:10px"><a href="<?= Security::e(tabUrl('pending', $filterQs, 'await')) ?>"><?= $awaitCount ?> booking<?= $awaitCount === 1 ? '' : 's' ?> still awaiting payment →</a></div><?php endif; ?>
    <?php else: ?>
      <b>Nothing here.</b> No bookings match these filters.
    <?php endif; ?>
  </div>
<?php else: foreach ($rows as $q):
    $bId    = (int) $q['id'];
    $bPnr   = (string) $q['pnr'];
    $rk     = $risk[$bId] ?? ['score' => 0, 'level' => 'low', 'reasons' => [], 'hasProof' => ($q['utr_number'] ?? '') !== '' || !empty($q['shot']), 'bankSigned' => false, 'skip' => null];
    $level  = (string) $rk['level'];
    $total  = (float) $q['total_amount'];
    $soldBy = (int) ($q['sold_by_admin_id'] ?? 0);
    $sCode  = $agentCodeOf($soldBy);
    $shots  = [];
    foreach (array_filter(explode(',', (string) ($q['shot_list'] ?? ''))) as $pair) {
        [$sidS, $mimeS] = array_pad(explode(':', $pair, 2), 2, '');
        if ((int) $sidS > 0) { $shots[] = ['sid' => (int) $sidS, 'pdf' => !str_starts_with($mimeS, 'image/')]; }
    }
    $claimed = ($q['claimed_amount'] ?? null) !== null && (float) $q['claimed_amount'] > 0 ? (float) $q['claimed_amount'] : null;
    $first   = $firstApprovals[$bId] ?? null;
    $canApprove = Auth::can('payments.verify');
    $canReject  = Auth::can('payments.reject');
    $pickable   = $level === 'low' && !empty($rk['hasProof']) && $canApprove && $first === null;
    $levelText  = ['high' => 'HIGH', 'medium' => 'MEDIUM', 'low' => 'LOW', 'skip' => 'COD'][$level] ?? strtoupper($level);
    $card = [
        'id' => $bId, 'pnr' => $bPnr, 'total' => $total, 'totalFmt' => inr($total),
        'utr' => (string) ($q['utr_number'] ?? ''), 'method' => strtoupper((string) ($q['method'] ?? '')),
        'level' => $level, 'score' => (int) $rk['score'], 'reasons' => array_values($rk['reasons']),
        'shots' => $shots, 'hasProof' => !empty($rk['hasProof']), 'bankSigned' => !empty($rk['bankSigned']),
        'claimed' => $claimed, 'first' => $first !== null ? (string) (($first['full_name'] ?? '') ?: ($first['username'] ?? '')) : '',
        'phone' => maskPhone((string) $q['contact_phone']),
    ];
?>
  <article class="pq-card lv-<?= Security::e($level) ?>" tabindex="-1" data-id="<?= $bId ?>" data-pnr="<?= Security::e($bPnr) ?>" data-level="<?= Security::e($level) ?>"
           data-card="<?= Security::e(json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <div class="pq-top">
      <?php if ($pickable): ?><label class="pq-pick" title="Pick for bulk approve"><input type="checkbox" class="pq-chk" value="<?= $bId ?>" aria-label="Pick <?= Security::e($bPnr) ?> for bulk approve"></label><?php endif; ?>
      <span class="pq-risk lv-<?= Security::e($level) ?>" title="Risk score <?= (int) $rk['score'] ?>"><?= Security::e($levelText) ?> <small><?= (int) $rk['score'] ?></small></span>
      <a class="pq-pnr" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>"><?= Security::e($bPnr) ?></a>
      <span class="pq-age">· <?= Security::e(timeAgo((string) $q['created_at'])) ?></span>
      <span class="grow"></span>
      <?php if (!empty($rk['bankSigned'])): ?><span class="pq-badge bank">🏦 Bank-verified (ICICI)</span><?php endif; ?>
      <?php if ($first !== null): ?><span class="pq-badge two">👥 1st approval: <?= Security::e((string) (($first['full_name'] ?? '') ?: ($first['username'] ?? ''))) ?></span><?php endif; ?>
      <?php if (isset($lastHolds[$bId])): ?><span class="pq-badge held">⏸ Asked for clearer proof <?= Security::e(timeAgo($lastHolds[$bId])) ?></span><?php endif; ?>
    </div>
    <div class="pq-main">
      <div class="pq-amt">
        <b><?= Security::e(inr($total)) ?></b>
        <small><?= (int) $q['seat_count'] ?> seat<?= (int) $q['seat_count'] === 1 ? '' : 's' ?> · <?= Security::e(ucfirst((string) ($q['booking_mode'] ?: 'seat'))) ?><?= (int) $q['is_cod'] === 1 ? ' · COD' : '' ?></small>
      </div>
      <div class="pq-pay">
        <?= method_badge((string) ($q['method'] ?? '')) ?> <?= source_badge((string) ($q['source'] ?? 'web')) ?>
        <?php if (!empty($q['payer_name'])): ?><span class="muted" style="font-size:12px"> · <?= Security::e((string) $q['payer_name']) ?></span><?php endif; ?>
        <?php if ($soldBy > 0 && ($sCode !== '' || !empty($q['seller_name']))): ?><span class="agent-tag"> · <strong><?= Security::e($sCode) ?></strong> <?= Security::e((string) ($q['seller_name'] ?? '')) ?></span><?php endif; ?>
        <?php if (!empty($q['utr_number'])): ?>
          <div><span class="pq-utr"><span>UTR <?= Security::e((string) $q['utr_number']) ?></span><button type="button" class="pq-copy" data-copy="<?= Security::e((string) $q['utr_number']) ?>" aria-label="Copy UTR">⧉</button></span></div>
        <?php else: ?>
          <div class="muted" style="font-size:12px;margin-top:4px">No UTR typed</div>
        <?php endif; ?>
        <?php if ($claimed !== null): ?>
          <span class="pq-claim<?= abs($claimed - $total) > 1 ? ' bad' : '' ?>">Customer says paid <?= Security::e(inr($claimed)) ?><?= abs($claimed - $total) > 1 ? ' — fare is ' . Security::e(inr($total)) : ' ✓' ?></span>
        <?php endif; ?>
        <?php $tr = $payTrail[$bId] ?? null; if ($tr !== null && ($tr['qr'] > 0 || $tr['link'] > 0)): ?>
          <span class="pq-claim" style="color:var(--success)"><?php if ($tr['qr'] > 0): ?>QR opened <?= (int) $tr['qr'] ?>x<?php endif; ?><?php if ($tr['qr'] > 0 && $tr['link'] > 0): ?> · <?php endif; ?><?php if ($tr['link'] > 0): ?>pay link <?= (int) $tr['link'] ?>x<?php endif; ?> · <?= Security::e((string) ($tr['device'] ?? '')) ?></span>
        <?php endif; ?>
      </div>
      <div class="pq-trip">🚌 <?= Security::e(($q['from_city'] ?? '—') . ' → ' . ($q['to_city'] ?? '—')) ?> · <?= Security::e(formatDate($q['travel_date'] ?? null)) ?><?php if (!empty($q['bus_name'])): ?> · <?= Security::e((string) $q['bus_name']) ?><?php endif; ?> · 📱 <?= Security::e(maskPhone((string) $q['contact_phone'])) ?></div>
    </div>
    <ul class="pq-reasons">
      <?php $shown = 0; foreach ($rk['reasons'] as $rs): $shown++;
          $w = (int) $rs['weight']; $cls = $w < 0 ? 'w-neg' : ($w < 20 ? 'w-mid' : ''); ?>
        <li class="<?= $cls ?>"><span class="w"><?= $w > 0 ? '+' . $w : $w ?></span><span><?= Security::e((string) $rs['label']) ?> · <span lang="ne"><?= Security::e((string) $rs['labelNe']) ?></span>
          <?php if ((string) $rs['detail'] !== '' || !empty($rs['pnrs'])): ?><small><?= Security::e((string) $rs['detail']) ?>
            <?php foreach (array_slice((array) $rs['pnrs'], 0, 4) as $op): ?> <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $op) ?>"><?= Security::e((string) $op) ?></a><?php endforeach; ?></small><?php endif; ?>
        </span></li>
      <?php endforeach; ?>
      <?php if ($shown === 0): ?><li class="none">✓ No warning signs · कुनै शंका देखिएन</li><?php endif; ?>
    </ul>
    <?php if ($shots !== []): ?>
      <div class="pq-shots">
        <?php foreach ($shots as $i => $sh): ?>
          <?php if ($sh['pdf']): ?>
            <a class="pq-shot pdf" href="<?= $base ?>/admin/screenshot.php?id=<?= $bId ?>&amp;sid=<?= $sh['sid'] ?>" target="_blank" rel="noopener">PDF<em><?= $i + 1 ?>/<?= count($shots) ?></em></a>
          <?php else: ?>
            <button type="button" class="pq-shot" data-lb="<?= $i ?>" aria-label="Open proof <?= $i + 1 ?> of <?= count($shots) ?>"><img src="<?= $base ?>/admin/screenshot.php?id=<?= $bId ?>&amp;sid=<?= $sh['sid'] ?>&amp;w=240" alt="" loading="lazy" decoding="async" width="74" height="110"><?php if (count($shots) > 1): ?><em><?= $i + 1 ?>/<?= count($shots) ?></em><?php endif; ?></button>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="pq-noshot">No screenshot uploaded<?= empty($q['utr_number']) ? ' · no proof at all yet' : '' ?></div>
    <?php endif; ?>
    <div class="pq-actions">
      <?php if ($canApprove): ?><button type="button" class="pq-btn ok" data-act="approve"><?= $first !== null ? '✓ 2nd approval' : '✓ Approve' ?> <kbd>A</kbd></button><?php endif; ?>
      <?php if ($canApprove): ?><button type="button" class="pq-btn hold" data-act="hold">⏸ Hold <kbd>H</kbd></button><?php endif; ?>
      <?php if ($canReject): ?><button type="button" class="pq-btn bad" data-act="reject">✕ Reject <kbd>R</kbd></button><?php endif; ?>
      <a class="pq-btn ghost" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>">Open ↗</a>
    </div>
    <noscript>
      <form method="post" class="note-inline" style="margin-top:10px">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
        <input type="hidden" name="booking_id" value="<?= $bId ?>">
        <input type="text" name="note" placeholder="Note / reason">
        <label style="font-size:12px"><input type="checkbox" name="bank_checked" value="1"> I checked the bank statement</label>
        <?php if ($canApprove): ?><button class="btn ok" type="submit" name="action" value="approve">Approve</button><?php endif; ?>
        <?php if ($canReject): ?><button class="btn bad" type="submit" name="action" value="reject">Reject</button><?php endif; ?>
      </form>
    </noscript>
  </article>
<?php endforeach; endif; ?>
</div>

<div class="pq-bulk" id="pqBulk" hidden>
  <span id="pqBulkText">0 picked</span>
  <button type="button" class="pq-btn ghost" id="pqBulkClear" style="min-height:44px">Clear</button>
  <button type="button" class="pq-btn ok" id="pqBulkGo" style="min-height:44px">✓ Approve picked</button>
</div>

<!-- One bottom sheet for approve / reject / hold / bulk -->
<div class="pq-back" id="pqBack" hidden></div>
<section class="pq-sheet" id="pqSheet" role="dialog" aria-modal="true" aria-labelledby="pqTitle" hidden>
  <div class="pq-grab" aria-hidden="true"></div>
  <div class="pq-sh-head">
    <h3 id="pqTitle">Review</h3>
    <span class="pq-risk" id="pqRisk"></span>
    <button type="button" class="pq-x" id="pqClose" aria-label="Close">×</button>
  </div>
  <div class="pq-sh-sum" id="pqSum2"></div>
  <ul class="pq-reasons" id="pqReasons"></ul>
  <form id="pqForm" novalidate>
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="booking_id" id="pqId" value="">
    <input type="hidden" name="action" id="pqAction" value="">
    <div data-mode="approve">
      <label class="pq-field" for="pqAmount">Amount you see on the proof / bank statement (₹) <small>रकम जाँचेर लेख्नुहोस् · optional, less than the fare makes it HIGH risk</small>
        <input id="pqAmount" name="amount_seen" inputmode="decimal" autocomplete="off" placeholder="">
      </label>
      <label class="pq-tick" id="pqTickWrap"><input type="checkbox" name="bank_checked" value="1" id="pqTick"><span>I checked the bank statement — the money arrived with this UTR. <small id="pqTickHint">बैंक स्टेटमेन्टमा रकम आएको जाँचें।</small></span></label>
    </div>
    <fieldset class="pq-chips" data-mode="reject" id="pqChips">
      <legend>Why? The customer reads this · ग्राहकले यही पढ्छन्</legend>
      <?php foreach (PaymentRisk::REASONS as $code => [$chip, $en, $ne]): ?>
        <label class="pq-chip" data-en="<?= Security::e($en) ?>" data-ne="<?= Security::e($ne) ?>"><input type="radio" name="reason_code" value="<?= Security::e($code) ?>"><?= Security::e($chip) ?></label>
      <?php endforeach; ?>
    </fieldset>
    <div class="pq-cust" data-mode="reject hold" id="pqCust"></div>
    <label class="pq-field" id="pqNoteWrap" for="pqNote"><span id="pqNoteLbl">Note</span>
      <textarea id="pqNote" name="note" maxlength="240"></textarea>
    </label>
    <p class="pq-info" data-mode="approve" id="pqTwo" hidden>👥 HIGH risk with the two-person rule on: your approval is recorded and a super-admin, manager or accountant (not you) completes it within 24 h. Nothing changes for the customer until then.</p>
    <p class="pq-info" data-mode="reject">Seats are released and the customer is told the reason above. Nothing is refunded automatically — refunds stay with the owner.</p>
    <p class="pq-info" data-mode="hold">The booking stays <b>pending</b> and its seats stay held. Only a message goes to the customer.</p>
    <ul class="pq-bulk-list" data-mode="bulk" id="pqBulkList"></ul>
    <p class="pq-info" data-mode="bulk">Only LOW-risk payments with a proof are approved. Each one is checked again on the server; anything that changed is left for you.</p>
    <p class="pq-gate" id="pqGate" hidden role="alert"></p>
    <button type="submit" class="pq-btn ok pq-go" id="pqGo">Approve</button>
  </form>
</section>

<div class="pq-lb" id="pqLb" hidden role="dialog" aria-modal="true" aria-label="Payment proof">
  <button type="button" class="pq-lb-btn pq-lb-x" id="pqLbX" aria-label="Close">×</button>
  <img id="pqLbImg" alt="Payment proof">
  <div class="pq-lb-bar">
    <button type="button" class="pq-lb-btn" id="pqLbPrev" aria-label="Previous">‹</button>
    <span id="pqLbCount"></span>
    <a id="pqLbOpen" href="#" target="_blank" rel="noopener">Open full size ↗</a>
    <button type="button" class="pq-lb-btn" id="pqLbNext" aria-label="Next">›</button>
  </div>
</div>

<?php else: /* ============ OTHER TABS (table) ============ */ ?>

<!-- Results Table -->
<div class="panel">
  <h2><?= count($rows) ?> result<?= count($rows) === 1 ? '' : 's' ?><?= count($rows) === 200 ? ' (showing latest 200)' : '' ?></h2>
  <div class="tbl-wrap">
  <table>
    <thead><tr>
      <th>PNR</th>
      <th>Route / Date</th>
      <th>Seats</th>
      <th>Amount</th>
      <th>Payment</th>
      <th>Proof</th>
      <th>Source</th>
      <th>Agent</th>
      <th>Status</th>
      <th style="min-width:240px">Actions</th>
    </tr></thead>
    <tbody>
    <?php if ($rows === []): ?>
      <tr><td colspan="10" class="muted" style="padding:28px;text-align:center">
        No bookings match these filters.
      </td></tr>
    <?php else: foreach ($rows as $q):
        $bId       = (int) $q['id'];
        $bPnr      = (string) $q['pnr'];
        $bStatus   = (string) $q['status'];
        $payStatus = (string) ($q['pay_status'] ?? '');
        $isCod     = (int) $q['is_cod'] === 1;
        $soldBy    = (int) ($q['sold_by_admin_id'] ?? 0);
        $sellerName = (string) ($q['seller_name'] ?? '');
        $sellerCode = $agentCodeOf($soldBy);
        $canSettle  = ($isCod && $payStatus === 'cod_pending'
                       && !in_array($bStatus, ['cancelled', 'rejected'], true)
                       && Auth::can('payments.verify'));
        $isPendingRow = $bStatus === 'pending';
    ?>
      <tr data-pnr="<?= Security::e($bPnr) ?>" data-id="<?= $bId ?>">
        <!-- PNR -->
        <td class="mono">
          <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>"><?= Security::e($bPnr) ?></a>
          <div class="muted"><?= Security::e(timeAgo((string) $q['created_at'])) ?></div>
        </td>
        <!-- Route / Date / Bus -->
        <td>
          <?= Security::e(($q['from_city'] ?? '—') . ' → ' . ($q['to_city'] ?? '—')) ?>
          <div class="muted">
            <?= Security::e(formatDate($q['travel_date'] ?? null)) ?>
            <?php if (!empty($q['bus_name'])): ?> · <?= Security::e($q['bus_name']) ?><?php endif; ?>
          </div>
        </td>
        <!-- Seats + mode -->
        <td>
          <?= (int) $q['seat_count'] ?>
          <div class="muted" style="font-size:11px"><?= Security::e(ucfirst((string) ($q['booking_mode'] ?: 'seat'))) ?></div>
        </td>
        <!-- Amount -->
        <td>
          <strong><?= Security::e(inr((float) $q['total_amount'])) ?></strong>
          <?php if ($isCod): ?><div><span class="pill" style="color:#7a4a00;background:#ffe6c7;font-size:10px">COD</span></div><?php endif; ?>
        </td>
        <!-- Payment: method + UTR/payer -->
        <td>
          <?= method_badge((string) ($q['method'] ?? '')) ?>
          <?php if (!empty($q['utr_number'])): ?>
            <div class="mono" style="font-size:11px;margin-top:3px"><?= Security::e($q['utr_number']) ?></div>
          <?php endif; ?>
          <?php if (!empty($q['utr_also_on'])): ?>
            <div style="margin-top:4px"><span class="pill" style="color:#8a1f1f;background:#f7dcdc;font-size:10.5px;white-space:normal" title="The same UTR was submitted for another booking — check before verifying">⚠ UTR also on <?php $uo = array_values(array_filter(array_map('trim', explode(',', (string) $q['utr_also_on'])))); echo Security::e(implode(', ', array_slice($uo, 0, 4)) . (count($uo) > 4 ? ' +' . (count($uo) - 4) . ' more' : '')); ?></span></div>
          <?php endif; ?>
          <?php if (!empty($q['payer_name'])): ?>
            <div class="muted" style="font-size:11px"><?= Security::e($q['payer_name']) ?></div>
          <?php endif; ?>
          <?php
            /* QR / pay-link activity. Absence of it on an old pending booking
               is the useful signal: the passenger never even opened it. */
            $tr = $payTrail[$bId] ?? null;
            if ($tr !== null && ($tr['qr'] > 0 || $tr['link'] > 0)):
          ?>
            <div style="font-size:11px;margin-top:4px;color:var(--success)">
              <?php if ($tr['qr'] > 0): ?>QR <?= (int) $tr['qr'] ?>x<?php endif; ?>
              <?php if ($tr['qr'] > 0 && $tr['link'] > 0): ?> · <?php endif; ?>
              <?php if ($tr['link'] > 0): ?>pay link <?= (int) $tr['link'] ?>x<?php endif; ?>
              <span class="muted">· <?= Security::e((string) ($tr['device'] ?? '')) ?> · <?= Security::e(timeAgo($tr['last'])) ?></span>
            </div>
          <?php endif; ?>
        </td>
        <!-- Proof -->
        <td>
          <?php if (!empty($q['shot'])): ?>
            <a href="<?= $base ?>/admin/screenshot.php?id=<?= $bId ?>" target="_blank" style="font-size:12px">View</a>
          <?php else: ?><span class="muted" style="font-size:12px">none</span><?php endif; ?>
          <?php if (!empty($q['shot_also_on'])): ?>
            <div style="margin-top:4px"><span class="pill" style="color:#8a1f1f;background:#f7dcdc;font-size:10.5px;white-space:normal" title="The very same screenshot file was uploaded for another booking — check before verifying">⚠ Screenshot also on <?= Security::e($q['shot_also_on']) ?></span></div>
          <?php endif; ?>
        </td>
        <!-- Source -->
        <td><?= source_badge((string) ($q['source'] ?? 'web')) ?></td>
        <!-- Agent -->
        <td>
          <?php if ($soldBy > 0 && ($sellerCode !== '' || $sellerName !== '')): ?>
            <span class="agent-tag">
              <?php if ($sellerCode !== ''): ?><strong><?= Security::e($sellerCode) ?></strong><br><?php endif; ?>
              <?= Security::e($sellerName ?: '—') ?>
            </span>
          <?php else: ?>
            <span class="muted">—</span>
          <?php endif; ?>
        </td>
        <!-- Status -->
        <td>
          <?php
          $pillStatus = $payStatus;
          if ($bStatus === 'confirmed') { $pillStatus = 'confirmed'; }
          elseif ($bStatus === 'rejected') { $pillStatus = 'rejected'; }
          elseif ($bStatus === 'expired') { $pillStatus = 'expired'; }
          elseif ($payStatus === 'cod_pending') { $pillStatus = 'cod_pending'; }
          elseif ($bStatus === 'pending') { $pillStatus = 'pending'; }
          ?>
          <span class="row-pill"><?= admin_pill($pillStatus) ?></span>
          <?php if (!empty($q['reject_reason'])): ?>
            <div class="muted" style="font-size:11px;margin-top:3px" title="<?= Security::e($q['reject_reason']) ?>"><?= Security::e(mb_strimwidth($q['reject_reason'], 0, 40, '...')) ?></div>
          <?php endif; ?>
          <?php if (!empty($q['verified_at'])): ?>
            <div class="muted" style="font-size:10px"><?= Security::e(timeAgo((string) $q['verified_at'])) ?></div>
          <?php endif; ?>
        </td>
        <!-- Actions -->
        <td>
          <?php if ($canSettle): ?>
            <form method="post" class="note-inline pay-action-form" data-booking-id="<?= $bId ?>">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="booking_id" value="<?= $bId ?>">
              <input type="text" name="note" placeholder="Receipt / who collected" class="action-note">
              <button class="btn ok btn-pay-action" type="submit" name="action" value="cod_settle"
                      data-confirm="Record <?= Security::e(inr((float) $q['total_amount'])) ?> cash as collected for <?= Security::e($bPnr) ?>?">Cash Collected</button>
            </form>
          <?php elseif ($isPendingRow && Auth::can('payments.verify')): ?>
            <a class="btn ok" style="font-size:12px;padding:6px 12px" href="<?= Security::e(tabUrl('pending', [], 'all')) ?>&amp;q=<?= urlencode($bPnr) ?>">Review in queue →</a>
          <?php else: ?>
            <a class="btn ghost" style="font-size:12px;padding:6px 12px" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode($bPnr) ?>">View</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<script>
(function () {
  'use strict';

  /* ---- Highlight row if ?pnr= is in the URL ---------------------- */
  var params = new URLSearchParams(location.search);
  var pnrQ = params.get('pnr');
  if (pnrQ) {
    var hl = document.querySelector('[data-pnr="' + CSS.escape(pnrQ) + '"]');
    if (hl) { hl.classList.add('row-highlight', 'on'); hl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
  }

  function showFlash(msg, type) {
    var existing = document.querySelector('.flash');
    if (existing) existing.remove();
    var el = document.createElement('div');
    el.className = 'flash ' + type;
    el.setAttribute('role', 'status');
    el.textContent = msg;
    var h1 = document.querySelector('.wrap h1');
    if (h1) h1.insertAdjacentElement('afterend', el); else document.body.prepend(el);
    setTimeout(function () { el.remove(); }, 6000);
  }
  function post(fd) {
    return fetch(location.pathname, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd
    }).then(function (r) { return r.json(); });
  }
  function bump(sel, delta) {
    document.querySelectorAll(sel).forEach(function (el) {
      el.textContent = Math.max(0, (parseInt(el.textContent, 10) || 0) + delta);
    });
  }
  /* ---- Update summary card numbers after an action ---------------- */
  function updateSummaryAfterAction(action) {
    if (action === 'approve') { bump('[data-stat="pending"]', -1); bump('[data-stat="approved"]', 1); }
    else if (action === 'reject') { bump('[data-stat="pending"]', -1); bump('[data-stat="rejected"]', 1); }
    else if (action === 'cod_settle') { bump('[data-stat="cod"]', -1); }
    document.querySelectorAll('.pay-tabs a').forEach(function (a) {
      var countEl = a.querySelector('.tab-count'); if (!countEl) return;
      var href = a.getAttribute('href') || '';
      var n = parseInt(countEl.textContent, 10) || 0;
      if (action === 'approve') {
        if (href.indexOf('tab=pending') !== -1) countEl.textContent = Math.max(0, n - 1);
        if (href.indexOf('tab=approved') !== -1) countEl.textContent = n + 1;
      } else if (action === 'reject') {
        if (href.indexOf('tab=pending') !== -1) countEl.textContent = Math.max(0, n - 1);
        if (href.indexOf('tab=rejected') !== -1) countEl.textContent = n + 1;
      } else if (action === 'cod_settle') {
        if (href.indexOf('tab=cod') !== -1) countEl.textContent = Math.max(0, n - 1);
      }
    });
  }

  /* ---- COD cash collected (table tabs) ----------------------------- */
  document.querySelectorAll('.pay-action-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var btn = e.submitter; if (!btn) return;
      if (!confirm(btn.getAttribute('data-confirm') || 'Are you sure?')) { e.preventDefault(); return; }
      e.preventDefault();
      var row = form.closest('tr'); if (row) row.classList.add('row-processing');
      btn.disabled = true;
      var fd = new FormData(form); fd.set('action', btn.value);
      post(fd).then(function (j) {
        if (row) row.classList.remove('row-processing');
        if (j.ok) {
          showFlash(j.message, 'ok');
          if (row) {
            row.classList.add('row-done');
            var pillEl = row.querySelector('.row-pill');
            if (pillEl) pillEl.innerHTML = '<span class="pill st-ok">Verified</span>';
            var td = row.querySelector('td:last-child'), a = row.querySelector('td:first-child a');
            if (td) td.innerHTML = '<a class="btn ghost" style="font-size:12px;padding:6px 12px" href="' + (a ? a.href : '#') + '">View</a>';
          }
          updateSummaryAfterAction(btn.value);
        } else { showFlash(j.message || 'Something went wrong.', 'bad'); btn.disabled = false; }
      }).catch(function () {
        if (row) row.classList.remove('row-processing'); btn.disabled = false;
        showFlash('Network error — please try again.', 'bad');
      });
    });
  });

  /* ================= REVIEW QUEUE ================= */
  var list = document.getElementById('pqList');
  if (!list) return;
  var sheet = document.getElementById('pqSheet'), back = document.getElementById('pqBack');
  var form = document.getElementById('pqForm'), goBtn = document.getElementById('pqGo');
  var gate = document.getElementById('pqGate'), note = document.getElementById('pqNote');
  var tick = document.getElementById('pqTick'), amount = document.getElementById('pqAmount');
  var TWO_STEP = <?= $twoStep ? 'true' : 'false' ?>;
  var current = null, mode = '', lastFocus = null;
  var LV = { high: 'HIGH', medium: 'MEDIUM', low: 'LOW', skip: 'COD' };

  function cards() { return Array.prototype.slice.call(list.querySelectorAll('.pq-card:not(.done)')); }
  function dataOf(card) { try { return JSON.parse(card.getAttribute('data-card') || '{}'); } catch (e) { return {}; } }
  function focusCard(card, scroll) {
    cards().forEach(function (c) { c.classList.remove('on'); });
    if (!card) return;
    card.classList.add('on');
    try { card.focus({ preventScroll: true }); } catch (e) {}
    if (scroll !== false) card.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'nearest' });
  }
  function onCard() { return list.querySelector('.pq-card.on:not(.done)') || cards()[0] || null; }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function reasonsHtml(d) {
    if (!d.reasons || !d.reasons.length) return '<li class="none">✓ No warning signs · कुनै शंका देखिएन</li>';
    return d.reasons.map(function (r) {
      var w = +r.weight, cls = w < 0 ? 'w-neg' : (w < 20 ? 'w-mid' : '');
      return '<li class="' + cls + '"><span class="w">' + (w > 0 ? '+' + w : w) + '</span><span>' + esc(r.label) + ' · <span lang="ne">' + esc(r.labelNe) + '</span>'
        + ((r.detail || (r.pnrs && r.pnrs.length)) ? '<small>' + esc(r.detail) + (r.pnrs && r.pnrs.length ? ' <b style="font-family:var(--f-mono)">' + r.pnrs.slice(0, 4).map(esc).join(', ') + '</b>' : '') + '</small>' : '') + '</span></li>';
    }).join('');
  }
  function setMode(m) {
    mode = m;
    sheet.querySelectorAll('[data-mode]').forEach(function (el) {
      el.hidden = (' ' + el.getAttribute('data-mode') + ' ').indexOf(' ' + m + ' ') < 0;
    });
  }
  function openSheet(card, m) {
    if (!card) return;
    var d = dataOf(card);
    current = card; lastFocus = document.activeElement;
    focusCard(card, false);
    form.reset(); gate.hidden = true;
    document.getElementById('pqTickWrap').classList.remove('need');
    document.getElementById('pqNoteWrap').classList.remove('need');
    sheet.querySelectorAll('.pq-chip').forEach(function (c) { c.classList.remove('on'); });
    document.getElementById('pqId').value = d.id;
    document.getElementById('pqAction').value = m;
    var risk = document.getElementById('pqRisk');
    risk.className = 'pq-risk lv-' + d.level; risk.innerHTML = esc(LV[d.level] || d.level) + ' <small>' + (+d.score) + '</small>';
    document.getElementById('pqSum2').innerHTML = '<b>' + esc(d.totalFmt) + '</b><span>' + esc(d.method) + (d.utr ? ' · UTR <span style="font-family:var(--f-mono)">' + esc(d.utr) + '</span>' : ' · no UTR') + '</span><span>' + esc(d.phone || '') + '</span>';
    document.getElementById('pqReasons').innerHTML = reasonsHtml(d);
    setMode(m);
    var title = document.getElementById('pqTitle');
    var noteLbl = document.getElementById('pqNoteLbl');
    goBtn.disabled = false;
    if (m === 'approve') {
      title.textContent = (d.first ? '2nd approval · ' : 'Approve · ') + d.pnr;
      amount.placeholder = 'Fare ' + d.totalFmt;
      var needTick = d.level === 'medium' || d.level === 'high';
      document.getElementById('pqTickHint').textContent = needTick
        ? 'Required for ' + (LV[d.level]) + ' risk · ' + 'बैंक स्टेटमेन्ट जाँच अनिवार्य'
        : 'Optional for LOW risk · बैंक स्टेटमेन्टमा रकम आएको जाँचें।';
      noteLbl.textContent = d.level === 'high' ? 'Why are you approving it? (required for HIGH risk) · किन? लेख्नुहोस्' : 'Note (optional)';
      note.placeholder = d.level === 'high' ? 'e.g. Called the customer, saw ₹' + Math.round(d.total) + ' in SBI statement 09 Oct 11:42' : '';
      document.getElementById('pqTwo').hidden = !(TWO_STEP && d.level === 'high' && !d.first);
      goBtn.className = 'pq-btn ok pq-go';
      goBtn.textContent = d.first ? '✓ Give 2nd approval & issue ticket' : (TWO_STEP && d.level === 'high' ? '✓ Record 1st approval' : '✓ Approve & issue ticket');
    } else if (m === 'reject') {
      title.textContent = 'Reject · ' + d.pnr;
      noteLbl.textContent = 'Extra note for the customer (optional; required for "Other")';
      note.placeholder = '';
      goBtn.className = 'pq-btn bad pq-go'; goBtn.textContent = '✕ Reject & release seats';
      var pre = (d.reasons || []).some(function (r) { return r.code === 'DUP_UTR' || r.code === 'DUP_SHOT'; }) ? 'duplicate_proof'
        : ((d.reasons || []).some(function (r) { return r.code === 'EDITED_SHOT'; }) ? 'edited_screenshot' : '');
      if (pre) pickChip(pre);
      custPreview();
    } else if (m === 'hold') {
      title.textContent = 'Hold · ask for a clearer proof · ' + d.pnr;
      noteLbl.textContent = 'Add a line to the message (optional)';
      note.placeholder = 'e.g. The UTR is cut off in your screenshot';
      goBtn.className = 'pq-btn hold pq-go'; goBtn.textContent = '⏸ Send & keep waiting';
      custPreview();
    }
    back.hidden = false; sheet.hidden = false;
    document.body.style.overflow = 'hidden';
    setTimeout(function () {
      var first = m === 'approve' ? amount : (m === 'reject' ? (sheet.querySelector('.pq-chip.on input') || sheet.querySelector('.pq-chip input')) : note);
      try { first.focus({ preventScroll: true }); } catch (e) {}
    }, 30);
  }
  function closeSheet() {
    sheet.hidden = true; back.hidden = true; document.body.style.overflow = '';
    if (current && !current.classList.contains('done')) focusCard(current, false);
    current = null;
  }
  function pickChip(code) {
    sheet.querySelectorAll('.pq-chip').forEach(function (c) {
      var inp = c.querySelector('input'); var on = inp.value === code;
      inp.checked = on; c.classList.toggle('on', on);
    });
  }
  function custPreview() {
    var box = document.getElementById('pqCust'), d = current ? dataOf(current) : {};
    var extra = note.value.trim();
    if (mode === 'reject') {
      var on = sheet.querySelector('.pq-chip.on');
      if (!on) { box.innerHTML = '<small>Customer will read</small>Pick a reason above.'; return; }
      var code = on.querySelector('input').value;
      var txt = code === 'other' ? (extra || '…') : on.getAttribute('data-ne') + ' / ' + on.getAttribute('data-en') + (extra ? ' (' + extra + ')' : '');
      box.innerHTML = '<small>Customer will read · ग्राहकले पढ्ने</small>' + esc(txt);
    } else if (mode === 'hold') {
      box.innerHTML = '<small>WhatsApp to ' + esc(d.phone || 'the customer') + '</small>' + esc('🧾 ' + (d.pnr || '') + '\nतपाईंको भुक्तानी जाँच्न अझै प्रस्ट प्रमाण चाहियो: UTR, रकम र मिति देखिने पूरा स्क्रिनसट पठाउनुहोस्।\nWe need a clearer payment proof: please send the full screenshot showing the UTR, amount and date.' + (extra ? '\n' + extra : '') + '\nतपाईंको सिट सुरक्षित छ · Your seats stay held.');
    }
  }
  sheet.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'reason_code') { pickChip(e.target.value); custPreview(); }
    if (e.target === tick && tick.checked) { document.getElementById('pqTickWrap').classList.remove('need'); gate.hidden = true; }
  });
  note.addEventListener('input', function () { custPreview(); if (note.value.trim().length >= 4) document.getElementById('pqNoteWrap').classList.remove('need'); });
  document.getElementById('pqClose').addEventListener('click', closeSheet);
  back.addEventListener('click', closeSheet);

  function cardResult(card, ok, html) {
    var old = card.querySelector('.pq-result'); if (old) old.remove();
    var p = document.createElement('div'); p.className = 'pq-result ' + (ok ? 'ok' : 'bad'); p.setAttribute('role', 'status');
    p.innerHTML = html; card.appendChild(p);
  }
  function retire(card, action) {
    var lv = card.getAttribute('data-level');
    var next = cards()[cards().indexOf(card) + 1] || cards()[cards().indexOf(card) - 1] || null;
    card.classList.add('done');
    bump('[data-lv-count="' + lv + '"]', -1);
    bump('[data-sub-count="proof"]', -1);
    updateSummaryAfterAction(action);
    var chk = card.querySelector('.pq-chk'); if (chk) { chk.checked = false; }
    setTimeout(function () { card.style.display = 'none'; syncBulk(); if (next) focusCard(next); }, 260);
  }

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!current && mode !== 'bulk') return;
    if (mode === 'bulk') { runBulk(); return; }
    var d = dataOf(current);
    gate.hidden = true;
    /* the same checks the server makes, said before the round trip */
    if (mode === 'approve') {
      if ((d.level === 'medium' || d.level === 'high') && !tick.checked) {
        document.getElementById('pqTickWrap').classList.add('need');
        gate.textContent = 'Tick "I checked the bank statement" first — this is ' + LV[d.level] + ' risk.'; gate.hidden = false; tick.focus(); return;
      }
      if (d.level === 'high' && note.value.trim().length < 4) {
        document.getElementById('pqNoteWrap').classList.add('need');
        gate.textContent = 'HIGH risk: write what you checked.'; gate.hidden = false; note.focus(); return;
      }
    } else if (mode === 'reject') {
      var on = sheet.querySelector('.pq-chip.on');
      if (!on) { gate.textContent = 'Pick a reason — the customer reads it.'; gate.hidden = false; return; }
      if (on.querySelector('input').value === 'other' && !note.value.trim()) { gate.textContent = 'Write the reason for "Other".'; gate.hidden = false; note.focus(); return; }
    }
    var card = current, act = mode;
    goBtn.disabled = true; card.classList.add('busy');
    post(new FormData(form)).then(function (j) {
      card.classList.remove('busy'); goBtn.disabled = false;
      if (!j.ok) {
        if (j.need === 'bank') document.getElementById('pqTickWrap').classList.add('need');
        if (j.need === 'note') document.getElementById('pqNoteWrap').classList.add('need');
        gate.textContent = j.message || 'Could not save.'; gate.hidden = false;
        if (j.risk && j.risk.level && j.risk.level !== d.level) {
          d.level = j.risk.level; d.score = j.risk.score; card.setAttribute('data-card', JSON.stringify(d));
          var rEl = document.getElementById('pqRisk'); rEl.className = 'pq-risk lv-' + d.level; rEl.innerHTML = esc(LV[d.level] || d.level) + ' <small>' + d.score + '</small>';
        }
        return;
      }
      closeSheet();
      try { if (navigator.vibrate) navigator.vibrate(act === 'reject' ? [30, 40, 30] : 18); } catch (x) {}
      if (act === 'approve' && j.state === 'awaiting_second') {
        cardResult(card, true, '👥 ' + esc(j.message));
        var ab = card.querySelector('[data-act="approve"]'); if (ab) ab.disabled = true;
        var nx = cards()[cards().indexOf(card) + 1]; if (nx) focusCard(nx);
        return;
      }
      if (act === 'hold') {
        cardResult(card, true, '⏸ ' + esc(j.message) + (j.link && !j.sent ? ' <a href="' + esc(j.link) + '" target="_blank" rel="noopener">Send on WhatsApp ↗</a>' : ''));
        var nh = cards()[cards().indexOf(card) + 1]; if (nh) focusCard(nh);
        return;
      }
      showFlash(j.message, 'ok');
      retire(card, act);
    }).catch(function () {
      card.classList.remove('busy'); goBtn.disabled = false;
      gate.textContent = 'Network error — please try again.'; gate.hidden = false;
    });
  });

  /* ---- buttons on the cards --------------------------------------- */
  list.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target : null; if (!t) return;
    var act = t.closest('[data-act]'), shot = t.closest('[data-lb]'), copy = t.closest('[data-copy]');
    var card = t.closest('.pq-card');
    if (act && card) { openSheet(card, act.getAttribute('data-act')); return; }
    if (shot && card) { openLb(card, +shot.getAttribute('data-lb')); return; }
    if (copy) {
      var v = copy.getAttribute('data-copy');
      (navigator.clipboard ? navigator.clipboard.writeText(v) : Promise.reject()).then(function () { copy.textContent = '✓'; setTimeout(function () { copy.textContent = '⧉'; }, 1200); }).catch(function () {});
      return;
    }
    if (card && !t.closest('a,button,input,label')) focusCard(card, false);
  });

  /* ---- lightbox: every screenshot of the booking ------------------- */
  var lb = document.getElementById('pqLb'), lbImg = document.getElementById('pqLbImg'), lbShots = [], lbI = 0, lbId = 0;
  function lbShow() {
    var s = lbShots[lbI]; if (!s) return;
    var u = '/admin/screenshot.php?id=' + lbId + '&sid=' + s.sid;
    lbImg.src = u; document.getElementById('pqLbOpen').href = u;
    document.getElementById('pqLbCount').textContent = (lbI + 1) + ' / ' + lbShots.length;
    document.getElementById('pqLbPrev').hidden = document.getElementById('pqLbNext').hidden = lbShots.length < 2;
  }
  function openLb(card, i) {
    var d = dataOf(card); lbShots = (d.shots || []).filter(function (s) { return !s.pdf; }); lbId = d.id;
    var all = d.shots || []; var target = all[i];
    lbI = Math.max(0, lbShots.indexOf(lbShots.filter(function (s) { return target && s.sid === target.sid; })[0]));
    lb.hidden = false; lbShow(); document.getElementById('pqLbX').focus();
  }
  function closeLb() { lb.hidden = true; lbImg.removeAttribute('src'); }
  document.getElementById('pqLbX').addEventListener('click', closeLb);
  document.getElementById('pqLbPrev').addEventListener('click', function () { lbI = (lbI - 1 + lbShots.length) % lbShots.length; lbShow(); });
  document.getElementById('pqLbNext').addEventListener('click', function () { lbI = (lbI + 1) % lbShots.length; lbShow(); });
  lb.addEventListener('click', function (e) { if (e.target === lb) closeLb(); });

  /* ---- bulk approve (LOW risk only) -------------------------------- */
  var bulk = document.getElementById('pqBulk');
  function picked() { return Array.prototype.slice.call(list.querySelectorAll('.pq-chk:checked')).filter(function (c) { return !c.closest('.pq-card').classList.contains('done'); }); }
  function syncBulk() {
    var p = picked(), sum = 0;
    p.forEach(function (c) { sum += +(dataOf(c.closest('.pq-card')).total || 0); });
    bulk.hidden = p.length === 0;
    document.getElementById('pqBulkText').textContent = p.length + ' low-risk picked · ₹' + Math.round(sum).toLocaleString('en-IN') + (p.length > 25 ? ' (max 25 at once)' : '');
    document.getElementById('pqBulkGo').textContent = '✓ Approve ' + Math.min(25, p.length);
  }
  list.addEventListener('change', function (e) { if (e.target.classList.contains('pq-chk')) syncBulk(); });
  var pickLow = document.getElementById('pqPickLow');
  if (pickLow) pickLow.addEventListener('click', function () {
    var boxes = list.querySelectorAll('.pq-chk'), n = 0;
    boxes.forEach(function (c) { if (!c.closest('.pq-card').classList.contains('done') && n < 25) { c.checked = true; n++; } });
    syncBulk();
  });
  document.getElementById('pqBulkClear').addEventListener('click', function () { picked().forEach(function (c) { c.checked = false; }); syncBulk(); });
  document.getElementById('pqBulkGo').addEventListener('click', function () {
    var p = picked().slice(0, 25); if (!p.length) return;
    current = null; lastFocus = document.activeElement;
    form.reset(); gate.hidden = true; setMode('bulk');
    document.getElementById('pqAction').value = 'bulk_approve';
    document.getElementById('pqTitle').textContent = 'Approve ' + p.length + ' low-risk payment' + (p.length === 1 ? '' : 's');
    var rEl = document.getElementById('pqRisk'); rEl.className = 'pq-risk lv-low'; rEl.textContent = 'LOW';
    var sum = 0;
    document.getElementById('pqBulkList').innerHTML = p.map(function (c) { var d = dataOf(c.closest('.pq-card')); sum += +d.total; return '<li data-id="' + d.id + '"><span>' + esc(d.pnr) + '</span><span>' + esc(d.totalFmt) + '</span></li>'; }).join('');
    document.getElementById('pqSum2').innerHTML = '<b>₹' + Math.round(sum).toLocaleString('en-IN') + '</b><span>' + p.length + ' bookings</span>';
    document.getElementById('pqReasons').innerHTML = '';
    goBtn.className = 'pq-btn ok pq-go'; goBtn.textContent = '✓ Approve ' + p.length + ' & issue tickets'; goBtn.disabled = false;
    back.hidden = false; sheet.hidden = false; document.body.style.overflow = 'hidden';
  });
  function runBulk() {
    var p = picked().slice(0, 25); if (!p.length) { closeSheet(); return; }
    var fd = new FormData(); fd.set('<?= $k ?>', form.querySelector('[name="<?= $k ?>"]').value); fd.set('action', 'bulk_approve');
    p.forEach(function (c) { fd.append('ids[]', c.value); });
    goBtn.disabled = true; goBtn.textContent = 'Approving…';
    post(fd).then(function (j) {
      goBtn.disabled = false;
      var res = j.results || [];
      res.forEach(function (r) {
        var li = document.querySelector('#pqBulkList li[data-id="' + r.id + '"]');
        if (li) { li.className = r.ok ? 'ok' : 'bad'; li.lastChild.textContent = r.ok ? '✓ approved' : '✕ ' + r.message; }
        var card = list.querySelector('.pq-card[data-id="' + r.id + '"]');
        if (!card) return;
        if (r.ok) retire(card, 'approve'); else cardResult(card, false, '✕ ' + esc(r.message));
      });
      goBtn.textContent = 'Done — close'; mode = 'bulk-done';
      showFlash(j.message || 'Done.', j.ok ? 'ok' : 'bad');
      form.onsubmit = null;
    }).catch(function () { goBtn.disabled = false; gate.textContent = 'Network error — nothing was lost; try again.'; gate.hidden = false; });
  }
  goBtn.addEventListener('click', function (e) { if (mode === 'bulk-done') { e.preventDefault(); closeSheet(); syncBulk(); } });

  /* ---- keyboard: J / K / A / R / H / Esc --------------------------- */
  document.addEventListener('keydown', function (e) {
    if (e.ctrlKey || e.metaKey || e.altKey) {
      if (!sheet.hidden && e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : goBtn.click(); }
      return;
    }
    if (e.key === 'Escape') { if (!lb.hidden) { closeLb(); return; } if (!sheet.hidden) { closeSheet(); return; } }
    if (!lb.hidden) { if (e.key === 'ArrowRight') document.getElementById('pqLbNext').click(); if (e.key === 'ArrowLeft') document.getElementById('pqLbPrev').click(); return; }
    var tag = (e.target && e.target.tagName) || '';
    if (!sheet.hidden || /INPUT|TEXTAREA|SELECT/.test(tag)) return;
    var k = e.key.toLowerCase(), cs = cards(), cur = onCard(), i = cs.indexOf(cur);
    if (k === 'j') { e.preventDefault(); focusCard(cs[Math.min(cs.length - 1, i + 1)] || cs[0]); }
    else if (k === 'k') { e.preventDefault(); focusCard(cs[Math.max(0, i - 1)] || cs[0]); }
    else if ((k === 'a' || k === 'r' || k === 'h') && cur) {
      var map = { a: 'approve', r: 'reject', h: 'hold' }, btn = cur.querySelector('[data-act="' + map[k] + '"]');
      if (btn && !btn.disabled) { e.preventDefault(); openSheet(cur, map[k]); }
    }
  });
  if (!pnrQ) { var c0 = cards()[0]; if (c0) focusCard(c0, false); }
})();
</script>
<?php
admin_footer();
