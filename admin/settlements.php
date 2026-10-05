<?php
/**
 * admin/settlements.php — agent settlements (5 Oct 2026, owner's Point 7).
 *
 * One page for the whole settlement life: who is waiting to be settled (and
 * for how long), the register with filters and export, one settlement with
 * its ledger breakdown, the printable receipt, batch drafts / approvals, and
 * the earned / paid / due grid per agent per month.
 *
 *   draft -> approved -> paid -> confirmed by the agent        (or disputed)
 *
 * NOTHING HERE IS AUTOMATIC. The page suggests; a person with commissions.pay
 * decides. The agent ledger is written only by "Mark paid"
 * (AgentSettlement::markPaid). An agent sees only their own paid settlements
 * and can confirm one or raise a dispute.
 *
 * Who: office roles with commissions.view read; commissions.pay acts; an
 * agent's own session (bookings.view) is pinned to their own rows.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
require_once INCLUDE_PATH . '/agentsettlement.php';

$agentView = Auth::isCounterAgent();
$admin     = admin_boot($agentView ? 'bookings.view' : 'commissions.view');
$selfId    = (int) $admin['id'];
$canPay    = !$agentView && Auth::can('commissions.pay');
$e         = static fn($v): string => Security::e((string) $v);
$self      = '/admin/settlements.php';
$flash     = null;
$today     = todayISO();
$ready     = AgentSettlement::hasTable();
$dueDays   = max(1, Settings::getInt('alert_settlement_due_days', 15));   // the same number the alert uses (Admin -> Notifications)

$okNotes = [
    'created'  => 'Draft saved. Nothing has been paid yet — check the figures, then approve.',
    'saved'    => 'Draft updated from the ledger.',
    'approved' => 'Approved. Pay the agent, then press “Mark paid”.',
    'paid'     => 'Marked paid — the ledger is updated and the agent can confirm it.',
    'cancelled' => 'Settlement cancelled. No money had moved.',
    'confirmed' => 'Thank you — settlement confirmed.',
    'disputed'  => 'Dispute sent to the office.',
    'resolved'  => 'Dispute closed.',
];
if (isset($okNotes[$_GET['ok'] ?? ''])) {
    $flash = ['ok', $okNotes[(string) $_GET['ok']]];
}

/* ------------------------------------------------------------------ POST */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    $id  = (int) ($_POST['id'] ?? 0);
    try {
        if (!Security::verifyCsrf()) {
            throw new RuntimeException('Session expired — please try again. / फेरि प्रयास गर्नुहोस्।');
        }
        if (!$ready) {
            throw new RuntimeException('Settlements are not set up on this database yet.');
        }
        if ($agentView) {
            /* An agent may only answer their own paid settlement. */
            if ($act === 'agent_confirm') {
                AgentSettlement::agentRespond($id, $selfId, true, '');
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=confirmed');
            }
            if ($act === 'agent_dispute') {
                AgentSettlement::agentRespond($id, $selfId, false, (string) ($_POST['note'] ?? ''));
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=disputed');
            }
            throw new RuntimeException('That action is for the office.');
        }
        if (!$canPay) {
            throw new RuntimeException('You can view settlements; changing them needs the commission-payout permission.');
        }
        $form = [
            'deductions'    => AgentSettlement::cleanDeductions($_POST['ded_label'] ?? [], $_POST['ded_amount'] ?? []),
            'bonus'         => (float) ($_POST['bonus'] ?? 0),
            'bonus_note'    => (string) ($_POST['bonus_note'] ?? ''),
            'cash_offset'   => (float) ($_POST['cash_offset'] ?? 0),
            'cash_received' => (float) ($_POST['cash_received'] ?? 0),
            'notes'         => (string) ($_POST['notes'] ?? ''),
        ];
        switch ($act) {
            case 'create':
                $newId = AgentSettlement::createDraft((int) ($_POST['agent'] ?? 0), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''), $form, $selfId);
                Response::redirect('admin/settlements.php?id=' . $newId . '&ok=created');
                // no break
            case 'update':
                AgentSettlement::updateDraft($id, $form + ['from' => (string) ($_POST['from'] ?? ''), 'to' => (string) ($_POST['to'] ?? '')], $selfId);
                if (($_POST['then'] ?? '') === 'approve') {
                    AgentSettlement::approve($id, $selfId);
                    Response::redirect('admin/settlements.php?id=' . $id . '&ok=approved');
                }
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=saved');
                // no break
            case 'approve':
                AgentSettlement::approve($id, $selfId);
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=approved');
                // no break
            case 'cancel':
                AgentSettlement::cancel($id, $selfId, (string) ($_POST['why'] ?? ''));
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=cancelled');
                // no break
            case 'pay':
                AgentSettlement::markPaid($id, (string) ($_POST['mode'] ?? ''), (string) ($_POST['ref'] ?? ''), $selfId);
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=paid');
                // no break
            case 'resolve':
                AgentSettlement::resolveDispute($id, $selfId, (string) ($_POST['note'] ?? ''));
                Response::redirect('admin/settlements.php?id=' . $id . '&ok=resolved');
                // no break
            case 'batch_draft':
                /* One draft per ticked agent, each for its own default period. An
                   agent that cannot be drafted is reported, the rest still are. */
                $made = 0; $skipped = [];
                foreach ((array) ($_POST['agents'] ?? []) as $aid) {
                    $aid = (int) $aid;
                    try {
                        [$f, $t] = AgentSettlement::defaultPeriod($aid);
                        AgentSettlement::createDraft($aid, $f, $t, [], $selfId);
                        $made++;
                    } catch (Throwable $ex) {
                        $skipped[] = (AgentWallet::agentCodeLabel($aid) ?: '#' . $aid) . ': ' . $ex->getMessage();
                    }
                }
                $flash = [$made > 0 ? 'ok' : 'bad', $made . ' draft' . ($made === 1 ? '' : 's') . ' created — nothing paid yet.'
                    . ($skipped !== [] ? ' Skipped: ' . implode(' · ', $skipped) : '')];
                break;
            case 'batch_approve':
                $done = 0; $skipped = [];
                foreach ((array) ($_POST['ids'] ?? []) as $sid) {
                    try {
                        AgentSettlement::approve((int) $sid, $selfId);
                        $done++;
                    } catch (Throwable $ex) {
                        $skipped[] = '#' . (int) $sid . ': ' . $ex->getMessage();
                    }
                }
                $flash = [$done > 0 ? 'ok' : 'bad', $done . ' settlement' . ($done === 1 ? '' : 's') . ' approved.'
                    . ($skipped !== [] ? ' Skipped: ' . implode(' · ', $skipped) : '')];
                break;
            default:
                throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $ex) {
        $flash = ['bad', $ex->getMessage()];
    }
}

/* ------------------------------------------------------------------ helpers */
$statusPill = static function (string $st): string {
    $map = [
        'draft'     => ['st-warn', 'Draft'],
        'approved'  => ['st-info', 'Approved · to pay'],
        'paid'      => ['st-ok',   'Paid'],
        'confirmed' => ['st-ok',   'Confirmed by agent'],
        'disputed'  => ['st-bad',  'Disputed'],
        'cancelled' => ['st-bad',  'Cancelled'],
    ];
    [$cls, $label] = $map[$st] ?? ['st-info', ucfirst($st)];
    return '<span class="pill ' . $cls . ($st === 'disputed' ? ' fx-pulse' : '') . '">' . Security::e($label) . '</span>';
};
$agents = $agentView ? [] : Database::fetchAll(
    "SELECT id, full_name, username FROM admins WHERE role = 'agent' AND is_active = 1 ORDER BY full_name, username"
);

/* ------------------------------------------------------------------ export: commission grid */
if (!$agentView && isset($_GET['grid'], $_GET['format']) && in_array($_GET['format'], ['csv', 'xlsx'], true)) {
    if (!Auth::can('reports.export') && !Auth::isSuperadmin()) {
        admin_boot('reports.export');
    }
    $grid = AgentSettlement::commissionGrid((int) ($_GET['months'] ?? 6));
    $head = ['Agent', 'Code'];
    foreach ($grid['months'] as $m) {
        $head[] = date('M Y', (int) strtotime($m . '-01')) . ' earned';
        $head[] = date('M Y', (int) strtotime($m . '-01')) . ' paid';
    }
    array_push($head, 'Earned (window)', 'Paid (window)', 'Due now');
    $data = [];
    foreach ($grid['rows'] as $r) {
        $line = [$r['name'], $r['code']];
        foreach ($grid['months'] as $m) {
            $line[] = number_format((float) ($r['m'][$m]['earned'] ?? 0), 2, '.', '');
            $line[] = number_format((float) ($r['m'][$m]['paid'] ?? 0), 2, '.', '');
        }
        array_push($line, number_format($r['earned'], 2, '.', ''), number_format($r['paid'], 2, '.', ''), number_format($r['due'], 2, '.', ''));
        $data[] = $line;
    }
    Logger::audit('settlements.export', 'export', 'commission-grid', null, ['months' => count($grid['months'])], 'commission grid downloaded');
    $fn = 'SHG-commission-by-month-' . date('Y-m-d');
    if ($_GET['format'] === 'xlsx') {
        shg_export_xlsx($fn . '.xlsx', 'Commission', $head, $data, range(2, count($head) - 1));
    }
    shg_export_csv($fn . '.csv', $head, $data);
}

/* ------------------------------------------------------------------ export: register */
$filters = [
    'status' => Security::clean((string) ($_GET['status'] ?? ''), 12),
    'agent'  => Security::clean((string) ($_GET['agent'] ?? ''), 12),
    'from'   => Security::clean((string) ($_GET['from'] ?? ''), 10),
    'to'     => Security::clean((string) ($_GET['to'] ?? ''), 10),
];
if (!$agentView && isset($_GET['format']) && in_array($_GET['format'], ['csv', 'xlsx'], true) && !isset($_GET['grid'])) {
    if (!Auth::can('reports.export') && !Auth::isSuperadmin()) {
        admin_boot('reports.export');
    }
    $head = ['No.', 'Agent', 'Code', 'Period from', 'Period to', 'Commission earned', 'Reversals', 'Advances', 'Commission due',
             'Deductions', 'Bonus', 'Cash net-off', 'Cash received', 'Net paid', 'Mode', 'Reference', 'Status', 'Created', 'Paid at'];
    $data = [];
    foreach (AgentSettlement::listing($filters, null, 1000) as $r) {
        $data[] = [AgentSettlement::number($r), $r['full_name'] ?: $r['username'], AgentWallet::agentCodeLabel((int) $r['agent_admin_id']),
            $r['period_from'], $r['period_to'], $r['gross_commission'], $r['reversals'], $r['advance_adjustment'], $r['commission_due'],
            $r['deductions_total'], $r['bonus'], $r['cash_offset'], $r['cash_received'], $r['net_amount'],
            $r['payment_mode'], $r['payment_ref'], $r['status'], $r['created_at'], $r['paid_at']];
    }
    Logger::audit('settlements.export', 'export', 'register', null, ['rows' => count($data)], 'settlement register downloaded');
    $fn = 'SHG-settlements-' . date('Y-m-d');
    if ($_GET['format'] === 'xlsx') {
        shg_export_xlsx($fn . '.xlsx', 'Settlements', $head, $data, range(5, 13));
    }
    shg_export_csv($fn . '.csv', $head, $data);
}

/* ------------------------------------------------------------------ one settlement */
$viewId = (int) ($_GET['id'] ?? 0);
$row    = $viewId > 0 ? AgentSettlement::get($viewId) : null;
if ($row !== null && $agentView && ((int) $row['agent_admin_id'] !== $selfId || !in_array($row['status'], ['paid', 'confirmed', 'disputed'], true))) {
    $row = null;   // never another agent's, never an office draft
}
if ($viewId > 0 && $row === null && $flash === null) {
    $flash = ['bad', 'That settlement was not found.'];
}

/* The receipt: a clean page to print or save as PDF. */
if ($row !== null && isset($_GET['print'])) {
    $co   = Settings::company();
    $no   = AgentSettlement::number($row);
    $ded  = AgentSettlement::deductionsOf($row);
    $code = AgentWallet::agentCodeLabel((int) $row['agent_admin_id']);
    $logo = is_file(ROOT_PATH . '/assets/img/logo.png') ? '/assets/img/logo.png' : '';
    $line = static fn(string $k, float $v, bool $strong = false): string => '<tr' . ($strong ? ' class="t"' : '') . '><td>' . Security::e($k) . '</td><td>' . Security::e(($v < 0 ? '− ' : '') . inr(abs($v))) . '</td></tr>';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Settlement ' . $e($no) . '</title><style>'
       . '*{box-sizing:border-box}body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Devanagari",sans-serif;margin:0;background:#eef1f6;color:#16233c}'
       . '.sheet{max-width:720px;margin:18px auto;background:#fff;border-radius:14px;padding:26px 28px;box-shadow:0 10px 30px rgba(16,38,77,.14)}'
       . '.top{display:flex;gap:14px;align-items:center;border-bottom:3px solid #0C306C;padding-bottom:14px}.top img{width:74px;height:auto}'
       . '.top h1{margin:0;font-size:20px}.top p{margin:2px 0 0;font-size:12.5px;color:#55627a}'
       . '.ttl{display:flex;justify-content:space-between;align-items:flex-end;gap:10px;margin:16px 0 10px;flex-wrap:wrap}.ttl h2{margin:0;font-size:18px;letter-spacing:.04em;text-transform:uppercase}'
       . '.ttl b{font:700 15px ui-monospace,Consolas,monospace}.meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px 18px;font-size:13.5px;margin-bottom:14px}'
       . '.meta span{color:#55627a}table{width:100%;border-collapse:collapse;font-size:14px}td{padding:8px 4px;border-bottom:1px solid #e3e8f1}td:last-child{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}'
       . 'tr.t td{font-weight:800;border-top:2px solid #16233c;border-bottom:0}tr.net td{font-size:18px;font-weight:900;background:#f1f6ff}'
       . '.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:54px;font-size:12.5px;color:#55627a}.sign div{border-top:1px solid #16233c;padding-top:6px}'
       . '.foot{margin-top:18px;font-size:11.5px;color:#7a8499}.bar{max-width:720px;margin:14px auto;display:flex;gap:10px;padding:0 4px}'
       . '.bar button,.bar a{min-height:46px;padding:0 18px;border-radius:12px;border:0;font:700 14px inherit;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}'
       . '.bar button{background:#0C306C;color:#fff;box-shadow:0 4px 0 #071d44}.bar a{background:#fff;color:#16233c;border:1px solid #cfd9ea}'
       . '@media print{body{background:#fff}.bar{display:none}.sheet{box-shadow:none;margin:0;max-width:none;border-radius:0}}'
       . '</style></head><body>'
       . '<div class="bar"><button onclick="window.print()">🖨️ Print / Save PDF</button><a href="' . $e($self . '?id=' . (int) $row['id']) . '">← Back</a></div>'
       . '<div class="sheet"><div class="top">' . ($logo !== '' ? '<img src="' . $e($logo) . '" alt="">' : '')
       . '<div><h1>' . $e($co['legal'] ?? $co['name']) . '</h1><p>' . $e($co['address'] ?? '') . '</p><p>' . $e($co['phone'] ?? '') . (!empty($co['cin']) ? ' · CIN ' . $e($co['cin']) : '') . '</p></div></div>'
       . '<div class="ttl"><h2>Agent settlement' . (in_array($row['status'], ['paid', 'confirmed', 'disputed'], true) ? ' receipt' : ' — ' . $e(strtoupper((string) $row['status']))) . '</h2><b>' . $e($no) . '</b></div>'
       . '<div class="meta"><div><span>Agent</span><br><b>' . $e($row['full_name'] ?: $row['username']) . '</b>' . ($code !== '' ? ' · ' . $e($code) : '') . '</div>'
       . '<div><span>Period</span><br><b>' . $e(formatDate((string) $row['period_from'])) . ' – ' . $e(formatDate((string) $row['period_to'])) . '</b></div>'
       . '<div><span>Tickets · seats in the period</span><br><b>' . (int) $row['tickets'] . ' · ' . (int) $row['seats'] . '</b></div>'
       . '<div><span>Paid</span><br><b>' . ($row['paid_at'] ? $e(formatDate((string) $row['paid_at'], 'j M Y, g:i A')) : 'not yet') . '</b>'
       . ($row['payment_mode'] ? ' · ' . $e(AgentSettlement::MODES[(string) $row['payment_mode']] ?? $row['payment_mode']) : '') . ($row['payment_ref'] ? ' · ' . $e($row['payment_ref']) : '') . '</div></div>'
       . '<table>' . $line('Balance carried into the period', (float) $row['opening_balance'])
       . $line('Commission earned (' . (int) $row['tickets'] . ' tickets)', (float) $row['gross_commission'])
       . ((float) $row['reversals'] != 0.0 ? $line('Reversed — cancelled tickets', (float) $row['reversals']) : '')
       . ((float) $row['advance_adjustment'] != 0.0 ? $line('Advance / loan', (float) $row['advance_adjustment']) : '')
       . ((float) $row['other_adjustments'] != 0.0 ? $line('Other adjustments', (float) $row['other_adjustments']) : '')
       . ((float) $row['paid_in_period'] != 0.0 ? $line('Already paid in the period', (float) $row['paid_in_period']) : '')
       . $line('Commission due', (float) $row['commission_due'], true);
    foreach ($ded as $d) {
        echo $line('Deduction: ' . $d['label'], -$d['amount']);
    }
    if ((float) $row['bonus'] > 0) {
        echo $line('Bonus' . (trim((string) $row['bonus_note']) !== '' ? ': ' . $row['bonus_note'] : ''), (float) $row['bonus']);
    }
    if ((float) $row['cash_offset'] > 0) {
        echo $line('Net-off against ticket cash held by the agent', -(float) $row['cash_offset']);
    }
    echo '<tr class="net"><td>NET PAID TO THE AGENT</td><td>' . $e(inr((float) $row['net_amount'])) . '</td></tr>';
    if ((float) $row['cash_received'] > 0) {
        echo $line('Ticket cash received from the agent', (float) $row['cash_received']);
    }
    echo '</table>'
       . (trim((string) $row['notes']) !== '' ? '<p style="font-size:13px;margin:14px 0 0"><b>Note:</b> ' . $e($row['notes']) . '</p>' : '')
       . '<div class="sign"><div>For ' . $e($co['name']) . ' (paid by)</div><div>Agent — received with thanks' . ($row['confirmed_at'] ? '<br>Confirmed in the panel ' . $e(formatDate((string) $row['confirmed_at'], 'j M Y, g:i A')) : '') . '</div></div>'
       . '<p class="foot">Computer-generated from the agent ledger · ' . $e($no) . ' · printed ' . $e(date('j M Y, g:i A')) . '</p></div></body></html>';
    exit;
}

/* ------------------------------------------------------------------ page */
admin_header($agentView ? 'My settlements' : 'Agent settlements', 'settlements');
?>
<style>
.st-kpis{margin-bottom:14px}
.st-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
.st-tabs .chip{min-height:40px;padding:0 15px;text-decoration:none;font-size:13.5px}
.st-tabs .chip.on{background:var(--blue);color:#fff;border-color:var(--blue)}
.st-card{background:var(--card);border:1px solid var(--line);border-left:6px solid var(--blue);border-radius:16px;padding:16px 18px;margin-bottom:14px;box-shadow:0 4px 0 rgba(16,38,77,.08),0 12px 26px rgba(16,38,77,.10)}
.st-card.s-draft{border-left-color:#F0A020}.st-card.s-approved{border-left-color:#2F6FD0}.st-card.s-paid,.st-card.s-confirmed{border-left-color:#1FA35B}
.st-card.s-disputed,.st-card.s-cancelled{border-left-color:#DC3B3B}
.st-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
.st-head h2{margin:0;font-size:19px}.st-head .mono{font-size:13px;color:var(--mut)}
.st-flow{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:12px;font-weight:700;color:var(--mut);margin:0 0 14px}
.st-flow span{padding:5px 11px;border-radius:999px;background:var(--soft);border:1px solid var(--line)}
.st-flow span.on{background:#1FA35B;color:#fff;border-color:#1FA35B}.st-flow span.now{background:var(--blue);color:#fff;border-color:var(--blue)}
.st-flow i{font-style:normal;opacity:.5}
.st-lines{width:100%;border-collapse:collapse;font-size:14.5px}
.st-lines td{padding:9px 4px;border-bottom:1px solid var(--line)}
.st-lines td:last-child{text-align:right;font-variant-numeric:tabular-nums;font-weight:700;white-space:nowrap}
.st-lines tr.sum td{font-weight:800;border-top:2px solid var(--ink);border-bottom:0}
.st-lines tr.net td{font-size:19px;font-weight:900;color:var(--ink)}
.st-lines small{display:block;color:var(--mut);font-size:12px;font-weight:400}
.st-net{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;padding:14px 16px;border-radius:14px;color:#fff;
  background:linear-gradient(135deg,#0C306C,#1C4FA3);box-shadow:0 5px 0 #071d44,0 14px 28px rgba(12,48,108,.3)}
.st-net b{font-size:26px;font-variant-numeric:tabular-nums}.st-net span{font-size:13px;opacity:.85}
.st-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,210px),1fr));gap:10px 12px;margin-top:14px}
.st-form label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut)}
.st-form input,.st-form select,.st-form textarea{min-height:46px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--card);color:var(--ink);width:100%;box-sizing:border-box}
.st-ded{display:grid;grid-template-columns:minmax(0,1fr) 130px;gap:8px;margin-bottom:8px}
.st-ded input{min-height:46px;padding:9px 11px;border:1px solid var(--line);border-radius:10px;font-size:15px;background:var(--card);color:var(--ink);width:100%;box-sizing:border-box}
.st-acts{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;align-items:center}
.st-acts .btn{min-height:48px;padding:0 20px;font-size:15px}
.st-note{font-size:13px;color:var(--mut);margin:10px 0 0}
.st-wait{font-weight:800}.st-wait.late{color:#DC3B3B}
.st-dispute{margin-top:12px;padding:12px 14px;border-radius:12px;background:#FDECEC;border:1px solid #F3B4B4;color:#7A1F1F;font-size:14px}
:root[data-theme="dark"] .st-dispute{background:#3A1717;border-color:#7A2A2A;color:#FFC9C9}
.fx-pulse{animation:stPulse 1.6s ease-in-out infinite}
@keyframes stPulse{50%{box-shadow:0 0 0 6px rgba(220,59,59,.18)}}
@media (prefers-reduced-motion:reduce){.fx-pulse{animation:none}}
.st-grid td,.st-grid th{white-space:nowrap;text-align:right}.st-grid td:first-child,.st-grid th:first-child{text-align:left}
.st-grid .paid{color:var(--mut);font-size:12px;display:block}
</style>
<?php
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '" role="status">' . $e($flash[1]) . '</div>';
}
if (!$ready) {
    echo admin_empty('Settlements are not set up yet', 'The database table is missing. Run database/upgrade-2026-10-05-agent-finance.sql once, then reload.', '🧾');
    admin_footer();
    exit;
}

/* ================================================================== DETAIL */
if ($row !== null) {
    $st   = (string) $row['status'];
    $no   = AgentSettlement::number($row);
    $code = AgentWallet::agentCodeLabel((int) $row['agent_admin_id']);
    $ded  = AgentSettlement::deductionsOf($row);
    $editable = $canPay && $st === 'draft';
    /* A draft always shows the ledger as it is NOW, not as it was when drafted. */
    $live = null;
    if ($editable) {
        try {
            $live = AgentSettlement::compute((int) $row['agent_admin_id'], (string) $row['period_from'], (string) $row['period_to']);
        } catch (Throwable $ex) {
            $live = null;
        }
    }
    $v = static fn(string $col, string $key) => $live !== null ? (float) $live[$key] : (float) $row[$col];
    $due      = $v('commission_due', 'due');
    $cashHeld = $v('cash_held', 'cashHeld');
    $net      = $editable ? round($due - (float) $row['deductions_total'] + (float) $row['bonus'] - (float) $row['cash_offset'], 2) : (float) $row['net_amount'];
    $steps    = ['draft' => 'Draft', 'approved' => 'Approved', 'paid' => 'Paid', 'confirmed' => 'Agent confirmed'];
    $order    = array_keys($steps);
    $at       = array_search($st === 'disputed' ? 'paid' : $st, $order, true);
    admin_page_head('', [$agentView ? 'My settlements' : 'Settlements' => $self, $no => '']);
    ?>
    <div class="st-card s-<?= $e($st) ?>">
      <div class="st-head">
        <?= admin_avatar((string) ($row['full_name'] ?: $row['username'])) ?>
        <div><h2><?= $e($row['full_name'] ?: $row['username']) ?> <?= $code !== '' ? '<span class="mono">' . $e($code) . '</span>' : '' ?></h2>
          <span class="mono"><?= $e($no) ?> · <?= $e(formatDate((string) $row['period_from'])) ?> – <?= $e(formatDate((string) $row['period_to'])) ?></span></div>
        <span style="margin-left:auto"><?= $statusPill($st) ?></span>
      </div>
      <?php if ($st !== 'cancelled'): ?>
      <div class="st-flow" aria-label="Settlement progress">
        <?php foreach ($order as $i => $k): ?>
          <?= $i > 0 ? '<i>→</i>' : '' ?><span class="<?= $at !== false && $i < $at ? 'on' : ($at !== false && $i === $at ? ($st === 'confirmed' ? 'on' : 'now') : '') ?>"><?= $e($steps[$k]) ?></span>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <table class="st-lines no-card">
        <tr><td>Balance carried into the period<small>commission owed before <?= $e(formatDate((string) $row['period_from'], 'j M')) ?></small></td><td><?= $e(inr($v('opening_balance', 'opening'))) ?></td></tr>
        <tr><td>Commission earned<small><?= (int) ($live['tickets'] ?? $row['tickets']) ?> tickets · <?= (int) ($live['seats'] ?? $row['seats']) ?> seats in the period</small></td><td><?= $e(inr($v('gross_commission', 'gross'))) ?></td></tr>
        <?php foreach ([['reversals', 'reversals', 'Reversed — cancelled tickets'], ['advance_adjustment', 'advances', 'Advance / loan given (−) or repaid (+)'],
                        ['other_adjustments', 'adjustments', 'Other adjustments'], ['paid_in_period', 'paid', 'Already paid in the period']] as [$col, $key, $lbl]):
            $amt = $v($col, $key); if (abs($amt) < 0.005) { continue; } ?>
        <tr><td><?= $e($lbl) ?></td><td><?= $amt < 0 ? '− ' : '+ ' ?><?= $e(inr(abs($amt))) ?></td></tr>
        <?php endforeach; ?>
        <tr class="sum"><td>Commission due<small>what the company owes this agent<?= $editable ? ' today' : ' at approval' ?></small></td><td><?= $e(inr($due)) ?></td></tr>
        <?php if (!$editable): ?>
          <?php foreach ($ded as $d): ?><tr><td>Deduction: <?= $e($d['label']) ?></td><td>− <?= $e(inr($d['amount'])) ?></td></tr><?php endforeach; ?>
          <?php if ((float) $row['bonus'] > 0): ?><tr><td>Bonus<?= trim((string) $row['bonus_note']) !== '' ? ': ' . $e($row['bonus_note']) : '' ?></td><td>+ <?= $e(inr((float) $row['bonus'])) ?></td></tr><?php endif; ?>
          <?php if ((float) $row['cash_offset'] > 0): ?><tr><td>Net-off against ticket cash the agent holds</td><td>− <?= $e(inr((float) $row['cash_offset'])) ?></td></tr><?php endif; ?>
        <?php endif; ?>
      </table>

      <?php if ($editable): ?>
      <form method="post" id="stEdit">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
        <div class="st-form">
          <label>Period from<input type="date" name="from" value="<?= $e($row['period_from']) ?>" max="<?= $e($today) ?>" required></label>
          <label>Period to<input type="date" name="to" value="<?= $e($row['period_to']) ?>" max="<?= $e($today) ?>" required></label>
          <label>Bonus (₹)<input type="number" name="bonus" min="0" step="1" inputmode="decimal" value="<?= (float) $row['bonus'] > 0 ? $e((float) $row['bonus']) : '' ?>" placeholder="0" data-calc></label>
          <label>Bonus reason<input type="text" name="bonus_note" maxlength="160" value="<?= $e($row['bonus_note']) ?>" placeholder="e.g. monthly target reached"></label>
        </div>
        <h3 style="font-size:14px;margin:16px 0 8px">Deductions <span class="muted" style="font-weight:400">(each becomes its own ledger line when paid)</span></h3>
        <div id="stDed">
          <?php $dedRows = $ded !== [] ? $ded : [['label' => '', 'amount' => 0]]; foreach ($dedRows as $d): ?>
          <div class="st-ded"><input type="text" name="ded_label[]" maxlength="80" value="<?= $e($d['label']) ?>" placeholder="Reason — e.g. ticket book, damage">
            <input type="number" name="ded_amount[]" min="0" step="1" inputmode="decimal" value="<?= $d['amount'] > 0 ? $e($d['amount']) : '' ?>" placeholder="₹" data-calc data-ded></div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn ghost sm" id="stDedAdd">+ another deduction</button>
        <?php if ($cashHeld > 0.009): ?>
        <h3 style="font-size:14px;margin:16px 0 8px">Ticket cash this agent holds: <?= $e(inr($cashHeld)) ?></h3>
        <div class="st-form" style="margin-top:0">
          <label>Net-off against commission (₹)<input type="number" name="cash_offset" min="0" step="1" inputmode="decimal" max="<?= $e($cashHeld) ?>" value="<?= (float) $row['cash_offset'] > 0 ? $e((float) $row['cash_offset']) : '' ?>" placeholder="0" data-calc data-offset></label>
          <label>Cash the agent hands over now (₹)<input type="number" name="cash_received" min="0" step="1" inputmode="decimal" max="<?= $e($cashHeld) ?>" value="<?= (float) $row['cash_received'] > 0 ? $e((float) $row['cash_received']) : '' ?>" placeholder="0"></label>
        </div>
        <button type="button" class="btn ghost sm" id="stOffAll" style="margin-top:8px">Net off as much as possible</button>
        <?php endif; ?>
        <div class="st-form"><label style="grid-column:1/-1">Note (printed on the receipt)<textarea name="notes" rows="2" maxlength="500"><?= $e($row['notes']) ?></textarea></label></div>
        <div class="st-net"><div><span>Net to pay the agent</span><br><b id="stNet" data-due="<?= $e($due) ?>"><?= $e(inr($net)) ?></b></div>
          <span>Nothing is paid or written to the ledger until “Mark paid”.</span></div>
        <div class="st-acts">
          <button class="btn" type="submit">💾 Save draft</button>
          <button class="btn ok" type="submit" name="then" value="approve">✅ Save &amp; approve</button>
          <a class="btn ghost" href="<?= $e($self . '?id=' . (int) $row['id'] . '&print=1') ?>" target="_blank" rel="noopener">🖨️ Preview</a>
        </div>
      </form>
      <?php else: ?>
        <div class="st-net"><div><span><?= in_array($st, ['paid', 'confirmed', 'disputed'], true) ? 'Net paid to the agent' : 'Net to pay the agent' ?></span><br><b><?= $e(inr($net)) ?></b></div>
          <span><?php if ($row['paid_at']): ?>Paid <?= $e(formatDate((string) $row['paid_at'], 'j M Y, g:i A')) ?> · <?= $e(AgentSettlement::MODES[(string) $row['payment_mode']] ?? $row['payment_mode']) ?><?= $row['payment_ref'] ? ' · ' . $e($row['payment_ref']) : '' ?>
            <?php elseif ($st === 'approved'): ?>Approved <?= $e(formatDate((string) $row['approved_at'], 'j M, g:i A')) ?> — waiting to be paid<?php endif; ?></span></div>
        <?php if ((float) $row['cash_received'] > 0): ?><p class="st-note">Ticket cash received from the agent with this settlement: <b><?= $e(inr((float) $row['cash_received'])) ?></b></p><?php endif; ?>
        <?php if (trim((string) $row['notes']) !== ''): ?><p class="st-note"><b>Note:</b> <?= $e($row['notes']) ?></p><?php endif; ?>
      <?php endif; ?>

      <?php if ($st === 'disputed' || trim((string) $row['dispute_note']) !== ''): ?>
        <div class="st-dispute"><b><?= $st === 'disputed' ? '⚠️ The agent disputes this settlement' : 'Earlier dispute' ?></b>
          <?= $row['disputed_at'] ? ' · ' . $e(formatDate((string) $row['disputed_at'], 'j M, g:i A')) : '' ?><br><?= $e($row['dispute_note']) ?>
          <?php if (trim((string) $row['resolved_note']) !== '' && $st !== 'cancelled'): ?><br><b>Office:</b> <?= $e($row['resolved_note']) ?><?php endif; ?></div>
      <?php endif; ?>
      <?php if ($st === 'cancelled' && trim((string) $row['resolved_note']) !== ''): ?><p class="st-note"><b>Cancelled:</b> <?= $e($row['resolved_note']) ?></p><?php endif; ?>

      <?php if ($canPay && $st === 'approved'): ?>
      <form method="post" class="st-form" style="align-items:end" onsubmit="return confirm('Mark <?= $e($no) ?> as PAID? This writes the agent ledger.')">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="pay"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
        <label>Paid by<select name="mode" required>
          <?php foreach (AgentSettlement::MODES as $mk => $ml): if ($mk === 'adjust' && $net > 0.009) { continue; } if ($mk !== 'adjust' && $net <= 0.009) { continue; } ?>
          <option value="<?= $e($mk) ?>"><?= $e($ml) ?></option><?php endforeach; ?></select></label>
        <label>Reference (UPI / cheque / receipt no.)<input type="text" name="ref" maxlength="80" placeholder="optional"></label>
        <button class="btn ok" type="submit" style="min-height:48px">💸 Mark paid <?= $e(inr($net)) ?></button>
      </form>
      <?php endif; ?>

      <div class="st-acts">
        <?php if (!$editable): ?><a class="btn ghost" href="<?= $e($self . '?id=' . (int) $row['id'] . '&print=1') ?>" target="_blank" rel="noopener">🖨️ Receipt (print / PDF)</a><?php endif; ?>
        <?php if (!$agentView): ?><a class="btn ghost" href="/admin/agent-360.php?id=<?= (int) $row['agent_admin_id'] ?>">Agent 360</a>
          <a class="btn ghost" href="/admin/agent-statement.php?agent=<?= (int) $row['agent_admin_id'] ?>&amp;from=<?= $e($row['period_from']) ?>&amp;to=<?= $e($row['period_to']) ?>">Ledger statement</a><?php endif; ?>
        <?php if ($canPay && in_array($st, ['draft', 'approved'], true)): ?>
        <form method="post" data-no-guard style="margin-left:auto" onsubmit="var w=prompt('Why is this settlement cancelled?');if(!w)return false;this.why.value=w;">
          <?= Security::csrfField() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><input type="hidden" name="why">
          <button class="btn danger ghost" type="submit">Cancel settlement</button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($agentView && $st === 'paid'): ?>
      <div class="st-acts">
        <form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="agent_confirm"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <button class="btn ok" type="submit">✅ मैले पाएँ · I received it</button></form>
      </div>
      <?php endif; ?>
      <?php if ($agentView && in_array($st, ['paid', 'confirmed'], true)): ?>
      <details style="margin-top:12px"><summary class="muted" style="cursor:pointer;font-weight:700">⚠️ केही मिलेन? · Something is wrong — raise a dispute</summary>
        <form method="post" class="st-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="agent_dispute"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
          <label style="grid-column:1/-1">What is wrong?<textarea name="note" rows="2" maxlength="500" required placeholder="e.g. 3 tickets of 2 Oct are missing"></textarea></label>
          <button class="btn danger" type="submit">Send to the office</button></form>
      </details>
      <?php endif; ?>
      <?php if ($canPay && $st === 'disputed'): ?>
      <form method="post" class="st-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="resolve"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
        <label style="grid-column:1/-1">How was it resolved? (a money correction is a ledger adjustment on Agent 360)<textarea name="note" rows="2" maxlength="500" required></textarea></label>
        <button class="btn" type="submit">Close the dispute</button></form>
      <?php endif; ?>
    </div>
    <script>
    (function () {
      var net = document.getElementById('stNet'); if (!net) return;
      var due = parseFloat(net.getAttribute('data-due')) || 0, form = document.getElementById('stEdit');
      function inr(n) { return (n < 0 ? '−₹' : '₹') + Math.abs(n).toLocaleString('en-IN', { maximumFractionDigits: 2 }); }
      function payable() {
        var ded = 0; form.querySelectorAll('[data-ded]').forEach(function (i) { ded += parseFloat(i.value) || 0; });
        return due - ded + (parseFloat((form.bonus || {}).value) || 0);
      }
      function calc() {
        var off = form.cash_offset ? (parseFloat(form.cash_offset.value) || 0) : 0, n = payable() - off;
        net.textContent = inr(n); net.style.color = n < 0 ? '#FFB3B3' : '';
      }
      form.addEventListener('input', function (e) { if (e.target.hasAttribute('data-calc')) calc(); });
      var add = document.getElementById('stDedAdd'), box = document.getElementById('stDed');
      if (add) add.addEventListener('click', function () {
        if (box.children.length >= <?= AgentSettlement::MAX_DEDUCTIONS ?>) return;
        var d = box.children[0].cloneNode(true); d.querySelectorAll('input').forEach(function (i) { i.value = ''; }); box.appendChild(d); d.querySelector('input').focus();
      });
      var all = document.getElementById('stOffAll');
      if (all) all.addEventListener('click', function () {
        var max = parseFloat(form.cash_offset.getAttribute('max')) || 0;
        form.cash_offset.value = Math.max(0, Math.min(max, Math.floor(payable()))) || ''; calc();
      });
    })();
    </script>
    <?php
    admin_footer();
    exit;
}

/* ================================================================== AGENT'S OWN LIST */
if ($agentView) {
    $mine = AgentSettlement::listing([], $selfId, 200);
    admin_page_head('हरेक भुक्तानीको हिसाब — हेर्नुहोस्, पुष्टि गर्नुहोस् वा गल्ती भए भन्नुहोस्। · Every payout with its breakdown: confirm it, or tell the office what is wrong.');
    if ($mine === []) {
        echo admin_empty('No settlement yet', 'When the office settles your commission it appears here with the full breakdown.', '🧾');
    } else {
        echo '<div class="panel"><div class="tbl-scroll"><table class="card-table"><thead><tr><th>No.</th><th>Period</th><th>Commission due</th><th>Net paid</th><th>Paid on</th><th>Status</th><th></th></tr></thead><tbody>';
        foreach ($mine as $r) {
            echo '<tr><td class="mono"><a href="' . $e($self . '?id=' . (int) $r['id']) . '"><b>' . $e(AgentSettlement::number($r)) . '</b></a></td>'
               . '<td>' . $e(formatDate((string) $r['period_from'], 'j M')) . ' – ' . $e(formatDate((string) $r['period_to'])) . '</td>'
               . '<td>' . $e(inr((float) $r['commission_due'])) . '</td><td><b>' . $e(inr((float) $r['net_amount'])) . '</b></td>'
               . '<td>' . ($r['paid_at'] ? $e(formatDate((string) $r['paid_at'])) : '—') . '</td><td>' . $statusPill((string) $r['status']) . '</td>'
               . '<td><a class="btn ghost sm" href="' . $e($self . '?id=' . (int) $r['id']) . '">' . ($r['status'] === 'paid' ? 'Open &amp; confirm' : 'Open') . '</a></td></tr>';
        }
        echo '</tbody></table></div></div>';
    }
    admin_footer();
    exit;
}

/* ================================================================== OFFICE: tabs */
$tab     = in_array($_GET['tab'] ?? '', ['pending', 'register', 'grid'], true) ? (string) $_GET['tab'] : 'pending';
$pending = AgentSettlement::pending();
$late    = array_values(array_filter($pending, static fn(array $p): bool => $p['commission'] > 0.009 && $p['days'] > $dueDays && $p['open'] === null));
$sumDue  = array_sum(array_column($pending, 'commission'));
$sumCash = array_sum(array_column($pending, 'cash'));
$counts  = ['draft' => 0, 'approved' => 0, 'disputed' => 0];
foreach (Database::fetchAll("SELECT status, COUNT(*) n FROM agent_settlements WHERE status IN ('draft','approved','disputed') GROUP BY status") as $c) {
    $counts[(string) $c['status']] = (int) $c['n'];
}
$paidMonth = (float) Database::scalar(
    "SELECT COALESCE(SUM(net_amount), 0) FROM agent_settlements WHERE status IN ('paid','confirmed','disputed') AND paid_at >= :m", ['m' => substr($today, 0, 8) . '01 00:00:00'], 0
);

/* New-settlement form (?new=<agent id>) */
$newAgent = (int) ($_GET['new'] ?? 0);

admin_page_head('Who is waiting, what each agent is owed, and every settlement from draft to the agent’s confirmation. Nothing is paid automatically.',
    [], $canPay ? '<a class="btn" href="' . $e($self . '?new=-1') . '">+ New settlement</a>' : '');
?>
<div class="kpis st-kpis">
  <?= admin_kpi('Commission due', inr($sumDue), count(array_filter($pending, static fn(array $p): bool => $p['commission'] > 0.009)) . ' agents are owed', 'wallet', 'blue', null, $self . '?tab=pending') ?>
  <?= admin_kpi('Over ' . $dueDays . ' days', (string) count($late), $late !== [] ? 'longest: ' . $late[0]['name'] . ' · ' . $late[0]['days'] . ' d' : 'nobody is overdue', 'clock', $late !== [] ? 'red' : 'green', null, $self . '?tab=pending') ?>
  <?= admin_kpi('Approve / pay', $counts['draft'] . ' / ' . $counts['approved'], $counts['disputed'] > 0 ? $counts['disputed'] . ' disputed ⚠️' : 'drafts · approved', 'clipboard', $counts['disputed'] > 0 ? 'red' : 'orange', null, $self . '?tab=register&status=open') ?>
  <?= admin_kpi('Cash with agents', inr($sumCash), 'to collect or net off', 'coins', 'teal', null, '/admin/accounting.php') ?>
  <?= admin_kpi('Paid this month', inr($paidMonth), 'through settlements', 'banknote', 'green', null, $self . '?tab=register&status=paid') ?>
</div>
<?php if ($late !== []): ?>
<div class="flash warn" role="status">⏰ <b><?= count($late) ?> agent<?= count($late) === 1 ? ' has' : 's have' ?> commission unpaid for more than <?= $dueDays ?> days</b> —
  <?= $e(implode(', ', array_map(static fn(array $p): string => $p['name'] . ' (' . $p['days'] . ' d, ' . inr($p['commission']) . ')', array_slice($late, 0, 5)))) ?><?= count($late) > 5 ? ' …' : '' ?></div>
<?php endif; ?>

<?php if ($canPay && $newAgent !== 0):
    $pick = $newAgent > 0 ? $newAgent : 0;
    [$nf, $nt] = $pick > 0 ? AgentSettlement::defaultPeriod($pick) : [substr($today, 0, 8) . '01', $today];
    $preview = null;
    if ($pick > 0) { try { $preview = AgentSettlement::compute($pick, $nf, $nt); } catch (Throwable $ex) { $preview = null; } } ?>
<div class="st-card s-draft">
  <div class="st-head"><h2>New settlement</h2><span class="muted" style="font-size:13px">A draft — nothing is paid until you approve it and press “Mark paid”.</span></div>
  <form method="post" class="st-form" style="margin-top:0">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="create">
    <label>Agent<select name="agent" id="stNewAgent" required>
      <option value="">— एजेन्ट छान्नुहोस् / Select agent —</option>
      <?php foreach ($agents as $a): $ac = AgentWallet::agentCodeLabel((int) $a['id']); ?>
      <option value="<?= (int) $a['id'] ?>" data-name="<?= $e($a['full_name'] ?: $a['username']) ?>"<?= (int) $a['id'] === $pick ? ' selected' : '' ?>><?= $e(($ac !== '' ? $ac . ' · ' : '') . ($a['full_name'] ?: $a['username'])) ?></option>
      <?php endforeach; ?></select></label>
    <label>Period from<input type="date" name="from" value="<?= $e($nf) ?>" max="<?= $e($today) ?>" required></label>
    <label>Period to<input type="date" name="to" value="<?= $e($nt) ?>" max="<?= $e($today) ?>" required></label>
    <button class="btn ok" type="submit" style="min-height:46px;align-self:end">Create draft →</button>
  </form>
  <?php if ($preview !== null): ?>
  <p class="st-note">From the ledger for <?= $e(formatDate($nf, 'j M')) ?> – <?= $e(formatDate($nt)) ?>: earned <b><?= $e(inr($preview['gross'])) ?></b> on <?= (int) $preview['tickets'] ?> tickets ·
     due now <b><?= $e(inr($preview['due'])) ?></b> · ticket cash held <b><?= $e(inr($preview['cashHeld'])) ?></b>. Deductions, bonus and net-off are added on the next screen.</p>
  <?php endif; ?>
</div>
<script src="/assets/js/agent-picker.js?v=20261005p"></script>
<script>
if (window.shgAgentPicker) { shgAgentPicker(document.getElementById('stNewAgent')); }
document.getElementById('stNewAgent').addEventListener('change', function () { if (this.value) location.href = <?= json_encode($self . '?new=') ?> + encodeURIComponent(this.value); });
</script>
<?php endif; ?>

<nav class="st-tabs" aria-label="Settlement views">
  <a class="chip<?= $tab === 'pending' ? ' on' : '' ?>" href="<?= $e($self . '?tab=pending') ?>">⏳ Waiting to be settled (<?= count($pending) ?>)</a>
  <a class="chip<?= $tab === 'register' ? ' on' : '' ?>" href="<?= $e($self . '?tab=register') ?>">🧾 Settlement register</a>
  <a class="chip<?= $tab === 'grid' ? ' on' : '' ?>" href="<?= $e($self . '?tab=grid') ?>">📊 Commission by month</a>
</nav>

<?php if ($tab === 'pending'): ?>
<div class="panel">
  <h2>Waiting to be settled <span class="muted" style="font-weight:400;font-size:13px">· straight from the agent ledger, longest wait first</span></h2>
  <?php if ($pending === []): ?>
    <?= admin_empty('Everyone is settled', 'No agent is owed commission and none is holding ticket cash.', '🎉') ?>
  <?php else: ?>
  <form method="post" id="stBatch">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="batch_draft">
    <div class="tbl-scroll"><table class="card-table dt">
      <thead><tr><?php if ($canPay): ?><th class="bulk-col"><input type="checkbox" id="stAll" aria-label="Select every agent that can be drafted"></th><?php endif; ?>
        <th>Agent</th><th>Commission due</th><th>Ticket cash held</th><th>Net</th><th>Waiting</th><th>Last paid</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($pending as $p): $canDraft = $canPay && $p['open'] === null && $p['commission'] > 0.009; ?>
      <tr>
        <?php if ($canPay): ?><td class="bulk-col" data-label="Select"><?php if ($canDraft): ?><input type="checkbox" class="st-cb" name="agents[]" value="<?= (int) $p['id'] ?>" aria-label="Select <?= $e($p['name']) ?>"><?php endif; ?></td><?php endif; ?>
        <td data-label="Agent"><a href="/admin/agent-360.php?id=<?= (int) $p['id'] ?>"><b><?= $e($p['name']) ?></b></a><?= $p['code'] !== '' ? ' <span class="muted mono">' . $e($p['code']) . '</span>' : '' ?></td>
        <td data-label="Commission due"><b><?= $e(inr($p['commission'])) ?></b></td>
        <td data-label="Ticket cash held"><?= $p['cash'] > 0.009 ? $e(inr($p['cash'])) . ($p['cashDays'] > 0 ? ' <span class="muted">· ' . (int) $p['cashDays'] . ' d</span>' : '') : '—' ?></td>
        <td data-label="Net"><?= $p['net'] >= 0 ? '<span style="color:#1FA35B;font-weight:700">we owe ' . $e(inr($p['net'])) . '</span>' : '<span style="color:#DC3B3B;font-weight:700">agent owes ' . $e(inr(-$p['net'])) . '</span>' ?></td>
        <td data-label="Waiting"><?= $p['commission'] > 0.009 ? '<span class="st-wait' . ($p['days'] > $dueDays ? ' late' : '') . '">' . (int) $p['days'] . ' day' . ($p['days'] === 1 ? '' : 's') . '</span>' : '—' ?></td>
        <td data-label="Last paid"><?= $p['lastPaid'] !== '' ? $e(formatDate($p['lastPaid'])) : '<span class="muted">never</span>' ?></td>
        <td data-label=""><?php if ($p['open'] !== null): ?><a class="btn ghost sm" href="<?= $e($self . '?id=' . (int) $p['open']['id']) ?>">Open <?= $e($p['open']['status']) ?></a>
          <?php elseif ($canPay): ?><a class="btn sm" href="<?= $e($self . '?new=' . (int) $p['id']) ?>">Settle →</a><?php endif; ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php if ($canPay): ?>
    <div class="st-acts"><button class="btn" type="submit" id="stBatchGo" disabled>Create drafts for the selected agents</button>
      <span class="muted" style="font-size:12.5px">Batch mode makes one draft per agent (period = since their last settlement). You still approve and pay each one.</span></div>
    <?php endif; ?>
  </form>
  <script>
  (function () {
    var all = document.getElementById('stAll'), go = document.getElementById('stBatchGo'), cbs = document.querySelectorAll('.st-cb');
    function sync() { var n = document.querySelectorAll('.st-cb:checked').length; if (go) { go.disabled = n === 0; go.textContent = n ? 'Create ' + n + ' draft' + (n === 1 ? '' : 's') : 'Create drafts for the selected agents'; } }
    if (all) all.addEventListener('change', function () { cbs.forEach(function (c) { c.checked = all.checked; }); sync(); });
    cbs.forEach(function (c) { c.addEventListener('change', sync); });
  })();
  </script>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'register'):
    $list   = AgentSettlement::listing($filters, null, 500);
    $qsBase = array_filter($filters + ['tab' => 'register']);
    $monday = addDaysISO($today, -((int) date('N', (int) strtotime($today)) - 1));
    $m1     = substr($today, 0, 8) . '01';
    $lmEnd  = addDaysISO($m1, -1);
    $presets = ['Today' => [$today, $today], 'Yesterday' => [addDaysISO($today, -1), addDaysISO($today, -1)], 'This week' => [$monday, $today],
                'This month' => [$m1, $today], 'Last month' => [substr($lmEnd, 0, 8) . '01', $lmEnd]];
    $drafts = array_values(array_filter($list, static fn(array $r): bool => $r['status'] === 'draft')); ?>
<div class="panel">
  <form method="get" class="st-form" style="margin:0 0 6px">
    <input type="hidden" name="tab" value="register">
    <label>Status<select name="status">
      <option value="">All</option><option value="open"<?= $filters['status'] === 'open' ? ' selected' : '' ?>>Open (draft · approved · disputed)</option>
      <?php foreach (AgentSettlement::STATUSES as $s): ?><option value="<?= $e($s) ?>"<?= $filters['status'] === $s ? ' selected' : '' ?>><?= $e(ucfirst($s)) ?></option><?php endforeach; ?></select></label>
    <label>Agent<select name="agent"><option value="">All agents</option>
      <?php foreach ($agents as $a): ?><option value="<?= (int) $a['id'] ?>"<?= $filters['agent'] === (string) $a['id'] ? ' selected' : '' ?>><?= $e($a['full_name'] ?: $a['username']) ?></option><?php endforeach; ?></select></label>
    <label>Created from<input type="date" name="from" value="<?= $e($filters['from']) ?>"></label>
    <label>Created to<input type="date" name="to" value="<?= $e($filters['to']) ?>"></label>
    <button class="btn" type="submit" style="min-height:46px;align-self:end">Filter</button>
  </form>
  <div class="st-tabs" style="margin:8px 0 12px">
    <?php foreach ($presets as $lbl => [$pf, $pt]): $on = $filters['from'] === $pf && $filters['to'] === $pt; ?>
      <a class="chip<?= $on ? ' on' : '' ?>" style="min-height:34px;font-size:12.5px" href="<?= $e($self . '?' . http_build_query(array_filter(['tab' => 'register', 'status' => $filters['status'], 'agent' => $filters['agent'], 'from' => $pf, 'to' => $pt]))) ?>"><?= $e($lbl) ?></a>
    <?php endforeach; ?>
    <?php if (array_filter($filters)): ?><a class="chip" style="min-height:34px;font-size:12.5px" href="<?= $e($self . '?tab=register') ?>">✕ Clear</a><?php endif; ?>
    <span style="margin-left:auto;display:inline-flex;gap:6px">
      <a class="btn ghost sm" href="<?= $e($self . '?' . http_build_query($qsBase + ['format' => 'csv'])) ?>">⬇️ CSV</a>
      <a class="btn ghost sm" href="<?= $e($self . '?' . http_build_query($qsBase + ['format' => 'xlsx'])) ?>">⬇️ Excel</a>
    </span>
  </div>
  <?php if ($list === []): ?>
    <?= admin_empty('No settlement matches', 'Change the filters, or start one from “Waiting to be settled”.', '🧾') ?>
  <?php else: ?>
  <div class="tbl-scroll"><table class="card-table dt">
    <thead><tr><th>No.</th><th>Agent</th><th>Period</th><th>Earned</th><th>Due</th><th>Deductions</th><th>Net</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $r): ?>
    <tr>
      <td data-label="No." class="mono"><a href="<?= $e($self . '?id=' . (int) $r['id']) ?>"><b><?= $e(AgentSettlement::number($r)) ?></b></a></td>
      <td data-label="Agent"><?= $e($r['full_name'] ?: $r['username']) ?> <span class="muted mono"><?= $e(AgentWallet::agentCodeLabel((int) $r['agent_admin_id'])) ?></span></td>
      <td data-label="Period"><?= $e(formatDate((string) $r['period_from'], 'j M')) ?> – <?= $e(formatDate((string) $r['period_to'], 'j M Y')) ?></td>
      <td data-label="Earned"><?= $e(inr((float) $r['gross_commission'])) ?></td>
      <td data-label="Due"><?= $e(inr((float) $r['commission_due'])) ?></td>
      <td data-label="Deductions"><?= (float) $r['deductions_total'] > 0 ? '− ' . $e(inr((float) $r['deductions_total'])) : '—' ?></td>
      <td data-label="Net"><b><?= $e(inr((float) $r['net_amount'])) ?></b></td>
      <td data-label="Status"><?= $statusPill((string) $r['status']) ?></td>
      <td data-label="Created"><?= $e(formatDate((string) $r['created_at'])) ?></td>
      <td data-label=""><a class="btn ghost sm" href="<?= $e($self . '?id=' . (int) $r['id']) ?>">Open</a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($canPay && count($drafts) > 1): ?>
  <form method="post" class="st-acts" onsubmit="return confirm('Approve all <?= count($drafts) ?> drafts shown? Nothing is paid yet.')">
    <?= Security::csrfField() ?><input type="hidden" name="action" value="batch_approve">
    <?php foreach ($drafts as $d): ?><input type="hidden" name="ids[]" value="<?= (int) $d['id'] ?>"><?php endforeach; ?>
    <button class="btn" type="submit">✅ Approve all <?= count($drafts) ?> drafts shown</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php else: /* grid */
    $months = in_array((int) ($_GET['months'] ?? 6), [3, 6, 12], true) ? (int) $_GET['months'] : 6;
    $grid   = AgentSettlement::commissionGrid($months); ?>
<div class="panel">
  <h2 style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">Commission by month
    <span class="muted" style="font-weight:400;font-size:13px">earned (net of reversals) · <span style="opacity:.8">paid</span> · due = owed today</span>
    <span style="margin-left:auto;display:inline-flex;gap:6px;flex-wrap:wrap">
      <?php foreach ([3, 6, 12] as $mm): ?><a class="chip<?= $months === $mm ? ' on' : '' ?>" style="text-decoration:none" href="<?= $e($self . '?tab=grid&months=' . $mm) ?>"><?= $mm ?> months</a><?php endforeach; ?>
      <a class="btn ghost sm" href="<?= $e($self . '?grid=1&months=' . $months . '&format=csv') ?>">⬇️ CSV</a>
      <a class="btn ghost sm" href="<?= $e($self . '?grid=1&months=' . $months . '&format=xlsx') ?>">⬇️ Excel</a>
    </span></h2>
  <?php if ($grid['rows'] === []): ?>
    <?= admin_empty('No commission in these months', '', '📊') ?>
  <?php else: ?>
  <div class="tbl-scroll"><table class="st-grid no-card dt">
    <thead><tr><th>Agent</th><?php foreach ($grid['months'] as $m): ?><th><?= $e(date('M y', (int) strtotime($m . '-01'))) ?></th><?php endforeach; ?><th>Earned</th><th>Paid</th><th>Due now</th></tr></thead>
    <tbody>
    <?php $tE = 0.0; $tP = 0.0; $tD = 0.0; foreach ($grid['rows'] as $r): $tE += $r['earned']; $tP += $r['paid']; $tD += $r['due']; ?>
    <tr><td><a href="/admin/agent-360.php?id=<?= (int) $r['id'] ?>"><b><?= $e($r['name']) ?></b></a> <span class="muted mono"><?= $e($r['code']) ?></span></td>
      <?php foreach ($grid['months'] as $m): $c = $r['m'][$m] ?? null; ?>
      <td><?= $c !== null ? $e(inr($c['earned'])) . ($c['paid'] > 0 ? '<span class="paid">paid ' . $e(inr($c['paid'])) . '</span>' : '') : '<span class="muted">—</span>' ?></td>
      <?php endforeach; ?>
      <td><b><?= $e(inr($r['earned'])) ?></b></td><td><?= $e(inr($r['paid'])) ?></td>
      <td><b style="color:<?= $r['due'] > 0 ? '#DC7A00' : ($r['due'] < 0 ? '#DC3B3B' : 'inherit') ?>"><?= $e(inr($r['due'])) ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr><th>All agents</th><?php foreach ($grid['months'] as $m): ?><th></th><?php endforeach; ?><th><?= $e(inr($tE)) ?></th><th><?= $e(inr($tP)) ?></th><th><?= $e(inr($tD)) ?></th></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php admin_footer();
