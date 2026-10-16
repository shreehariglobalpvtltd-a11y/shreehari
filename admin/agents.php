<?php
/**
 * admin/agents.php — Agents register (Excel-style, 5 Sep 2026; CRM columns
 * 5 Sep 2026 evening).
 *
 * One scrolling table of every agent account (admins.role = 'agent') the
 * way the office reads it: Serial · Type · Code · Name · Contact · WhatsApp
 * · Wallet · Commission · Sales · Status · Actions.
 *
 * Two kinds of agent (admin_profiles.agent_kind):
 *   Organization  — travel agency / counter, serial band 1-20, has a
 *                   contact person inside the organization
 *   Person        — an individual, serial band 21+
 * The kind can be switched at any time; the serial, the wallet ledger and
 * the commission tier are deliberately left alone when it is.
 *
 * Search / filter / sort in the browser; View expands the row; Edit /
 * activate / deactivate / switch kind post back here (super-admin or
 * staff.manage); Export streams CSV / .xlsx. Row actions link out to the
 * wallet, sales, commission and ticket pages that already exist.
 *
 * 15 Oct 2026 (owner, Agent Management): a one-line Quick add (name, mobile,
 * Org / Person, optional city and email) through AgentWallet::createAgent —
 * the same helper Staff & Approvals uses; agents are NEVER deleted any more,
 * only deactivated; and an agent holding more cash than their limit
 * (AgentWallet::effectiveCashLimit) gets a red "Over ₹X" pill, a KPI card and
 * an "Over cash limit" filter. Display only — nothing here moves money.
 *
 * Approvals, PIN / password resets and route permissions stay on
 * staff.php and agent.php — this page is the register, not a second copy
 * of those workflows.
 *
 * 17 Sep 2026: a KYC column (admin_profiles.kyc_status), the net position
 * (cash − commission, the one number the office asks for), a "Settlement
 * overdue" pill when agent_settlement_due_days is on, and the row's Wallet
 * button now opens the Agent 360 view (admin/agent-360.php).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require __DIR__ . '/_export.php';
$admin = admin_boot('customers.view');
// 5 Sep 2026: the register lists every agent's balance and commission —
// office viewers need commissions.view (counter/support roles do not hold it).
Auth::requireAdmin('commissions.view');
if (Auth::isCounterAgent()) {
    admin_header('Agents', 'agents');
    echo '<div class="flash bad">The agents register is an office page.</div>';
    admin_footer();
    exit;
}

$canManage = Auth::isSuperadmin() || Auth::can('staff.manage');
// 9 Oct 2026: Office chat link per agent (office side; hidden without the chat tables).
$agChat = class_exists('StaffMsg') && StaffMsg::canUse() && StaffMsg::isOffice();
$selfId    = (int) ($admin['id'] ?? 0);
$flash     = null;

/* ---------- actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a super-admin (or staff manager) can change agent records.'];
    } elseif ((string) ($_POST['action'] ?? '') === 'quick_add') {
        /* Quick add (15 Oct 2026, owner: "a new agent in under 30 seconds").
           Five boxes, one tap. Everything else — username = mobile, a random
           password until the office issues one, the next free SHG serial in
           the band, the welcome WhatsApp, the audit row — is
           AgentWallet::createAgent, the one creation path. */
        $qaPhone = preg_replace('/\D/', '', (string) ($_POST['qa_phone'] ?? '')) ?? '';
        try {
            $newId = AgentWallet::createAgent(
                (string) ($_POST['qa_name'] ?? ''),
                $qaPhone,
                (string) ($_POST['qa_kind'] ?? AgentWallet::KIND_ORG),
                (string) ($_POST['qa_city'] ?? ''),
                (string) ($_POST['qa_email'] ?? ''),
                $selfId
            );
            $qaCode = AgentWallet::agentCodeLabel($newId);
            $flash  = ['ok', 'Agent added' . ($qaCode !== '' ? ' — ' . $qaCode : '') . '. They can sign in now with their code + mobile + OTP; issue a password when you are ready.',
                       'agent.php?agent=' . $newId . '#loginCredentials', '🔑 Set their login / password'];
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    } else {
        $act = (string) ($_POST['action'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);
        $a   = Database::fetch("SELECT * FROM admins WHERE id = :id AND role = 'agent' LIMIT 1", ['id' => $id]);
        if ($a === null) {
            $flash = ['bad', 'Agent not found.'];
        } elseif ($act === 'edit') {
            $name  = trim(Security::clean($_POST['full_name'] ?? '', 120));
            $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''));
            $email = Security::email($_POST['email'] ?? '');
            $city  = trim(Security::clean($_POST['counter_name'] ?? '', 120));
            $addr  = trim(Security::clean($_POST['address'] ?? '', 255));
            $idT   = trim(Security::clean($_POST['id_type'] ?? '', 40));
            $idN   = trim(Security::clean($_POST['id_number'] ?? '', 60));
            $limit = max(0, (int) ($_POST['daily_booking_limit'] ?? 0));
            $cash  = max(0.0, (float) ($_POST['cash_limit'] ?? 0));
            $notes = trim(Security::clean($_POST['notes'] ?? '', 500));
            $kind  = AgentWallet::normaliseKind((string) ($_POST['agent_kind'] ?? AgentWallet::KIND_ORG));
            $cp    = trim(Security::clean($_POST['contact_person'] ?? '', 120));
            $wa    = AgentWallet::cleanPhone((string) ($_POST['whatsapp'] ?? ''));
            $tier  = strtolower(trim((string) ($_POST['agent_type'] ?? '')));
            $code  = trim((string) ($_POST['agent_code'] ?? ''));
            if (mb_strlen($name) < 2) {
                $flash = ['bad', 'Enter the agent\'s name.'];
            } elseif (strlen($phone) < 10 || strlen($phone) > 15) {
                $flash = ['bad', 'Enter a valid mobile number.'];
            } elseif (Database::exists('SELECT 1 FROM admins WHERE phone = :p AND id <> :id LIMIT 1', ['p' => $phone, 'id' => $id])) {
                $flash = ['bad', 'That mobile number belongs to another staff account.'];
            } elseif ($code !== '' && (!ctype_digit($code) || (int) $code < AgentWallet::AGENT_CODE_MIN || (int) $code > AgentWallet::AGENT_CODE_MAX)) {
                $flash = ['bad', 'The serial must be a number between ' . AgentWallet::AGENT_CODE_MIN . ' and ' . AgentWallet::AGENT_CODE_MAX . '.'];
            } else {
                $prevProfile = AgentWallet::profile($id);
                $before = [
                    'full_name' => $a['full_name'], 'phone' => $a['phone'], 'email' => $a['email'],
                    'agent_kind' => $prevProfile['agent_kind'], 'contact_person' => $prevProfile['contact_person'],
                    'whatsapp' => $prevProfile['whatsapp'], 'city' => $prevProfile['counter_name'], 'address' => $prevProfile['address'],
                    'daily_booking_limit' => $prevProfile['daily_booking_limit'], 'cash_limit' => $prevProfile['cash_limit'],
                    'tier' => AgentWallet::agentTypeFor($id), 'serial' => AgentWallet::agentCodeFor($id),
                ];
                Database::update('admins', ['full_name' => $name, 'phone' => $phone], 'id = :id', ['id' => $id]);
                Database::run(
                    'INSERT INTO admin_profiles (admin_id, display_phone, whatsapp, counter_name, agent_kind, contact_person, address, id_type, id_number, daily_booking_limit, cash_limit, notes)
                          VALUES (:aid, :dp, :wa, :cn, :kind, :cp, :addr, :it, :inum, :lim, :cash, :notes)
                     ON DUPLICATE KEY UPDATE display_phone = :dp2, whatsapp = :wa2, counter_name = :cn2, agent_kind = :kind2, contact_person = :cp2, address = :addr2,
                                             id_type = :it2, id_number = :inum2, daily_booking_limit = :lim2, cash_limit = :cash2, notes = :notes2',
                    ['aid' => $id, 'dp' => $phone, 'wa' => $wa ?: null, 'cn' => $city ?: null, 'kind' => $kind, 'cp' => $cp ?: null, 'addr' => $addr ?: null,
                     'it' => $idT ?: null, 'inum' => $idN ?: null, 'lim' => $limit, 'cash' => $cash, 'notes' => $notes ?: null,
                     'dp2' => $phone, 'wa2' => $wa ?: null, 'cn2' => $city ?: null, 'kind2' => $kind, 'cp2' => $cp ?: null, 'addr2' => $addr ?: null,
                     'it2' => $idT ?: null, 'inum2' => $idN ?: null, 'lim2' => $limit, 'cash2' => $cash, 'notes2' => $notes ?: null]
                );
                $notesOut = [];
                /* Sign-in details (4 Sep 2026): email is now half of the agent
                   login (email + username + password), so it goes through the
                   one writer that checks it is not already another account's,
                   alongside the optional username / password change on this
                   row. A duplicate email or username surfaces as a normal form
                   note instead of a raw SQL failure, and the rest of the edit
                   still saves. */
                $emailRaw = trim((string) ($_POST['email'] ?? ''));
                $userRaw  = trim((string) ($_POST['login_username'] ?? ''));
                $passRaw  = (string) ($_POST['login_password'] ?? '');
                if ($emailRaw !== '' && $email === '') {
                    $notesOut[] = 'Email not changed: "' . Security::e($emailRaw) . '" is not a valid address.';
                } else {
                    try {
                        $credIn = ['email' => $email];
                        if ($userRaw !== '') { $credIn['username'] = $userRaw; }
                        if ($passRaw !== '') { $credIn['password'] = $passRaw; }
                        $credRes = Auth::setStaffCredentials($id, $credIn, $selfId);
                        if (in_array('password', $credRes['changed'], true)) {
                            $notesOut[] = 'New password set.';
                        }
                    } catch (Throwable $e) {
                        $notesOut[] = 'Sign-in details not changed: ' . $e->getMessage();
                    }
                }
                if ($tier === 'direct' || $tier === 'joint') {
                    AgentWallet::setAgentType($id, $tier, $selfId);          // audited as agent.type_changed
                }
                if ($code !== '' && (int) $code !== (int) ($before['serial'] ?? 0)) {
                    try {
                        AgentWallet::setAgentCode($id, (int) $code, $selfId); // audited as agent.code_set
                        if (!AgentWallet::codeInBand((int) $code, $kind)) {
                            $notesOut[] = 'Serial ' . (int) $code . ' is outside the ' . AgentWallet::kindLabel($kind) . ' band (' . AgentWallet::codeBandLabel($kind) . ') — kept as typed.';
                        }
                    } catch (Throwable $e) {
                        $notesOut[] = 'Serial not changed: ' . $e->getMessage();
                    }
                }
                if ($before['agent_kind'] !== $kind) {
                    Logger::audit('agent.kind_changed', 'admin', (string) $id, ['agent_kind' => $before['agent_kind']], ['agent_kind' => $kind],
                        'Agent kind switched on the register by admin #' . $selfId . ' (serial, wallet and tier kept)');
                }
                Logger::audit('agent.edit', 'admin', (string) $a['username'], $before,
                    ['full_name' => $name, 'phone' => $phone, 'email' => $email, 'agent_kind' => $kind, 'contact_person' => $cp, 'whatsapp' => $wa,
                     'city' => $city, 'address' => $addr, 'daily_booking_limit' => $limit, 'cash_limit' => $cash, 'tier' => $tier ?: $before['tier'],
                     'serial' => $code !== '' ? (int) $code : $before['serial']],
                    'agents register edit');
                $flash = ['ok', 'Agent updated.' . ($notesOut !== [] ? ' ' . implode(' ', $notesOut) : '')];
            }
        } elseif ($act === 'kind') {
            // One-click switch Organization <-> Person. The serial stays put
            // (owner: "serial nabigrine"); only NEW serials follow the band.
            $to = AgentWallet::normaliseKind((string) ($_POST['agent_kind'] ?? ''));
            try {
                AgentWallet::setAgentKind($id, $to, $selfId);
                $serial = AgentWallet::agentCodeFor($id);
                $flash  = ['ok', ($a['full_name'] ?: $a['username']) . ' is now ' . ($to === AgentWallet::KIND_ORG ? 'an' : 'a') . ' ' . AgentWallet::kindLabel($to) . ' agent.'
                    . (($serial !== null && !AgentWallet::codeInBand($serial, $to))
                        ? ' Serial ' . $serial . ' kept as-is (the ' . AgentWallet::kindLabel($to) . ' band is ' . AgentWallet::codeBandLabel($to) . ' — change it under Edit if you want).'
                        : '')];
            } catch (Throwable $e) {
                $flash = ['bad', $e->getMessage()];
            }
        } elseif ($act === 'toggle') {
            if ((int) $a['id'] === $selfId) {
                $flash = ['bad', 'You cannot deactivate your own account.'];
            } else {
                $to = (int) $a['is_active'] === 1 ? 0 : 1;
                Database::update('admins', ['is_active' => $to], 'id = :id', ['id' => $id]);
                Logger::audit($to ? 'agent.activate' : 'agent.deactivate', 'admin', (string) $a['username'], null, null, 'agents register');
                $flash = ['ok', 'Agent ' . ($to ? 'activated' : 'deactivated') . '.'];
            }
        } elseif ($act === 'delete') {
            // 15 Oct 2026 (owner rule: never delete agents). The Delete button
            // is gone; a stale form that still posts it is refused.
            $flash = ['bad', 'Agents are never deleted — deactivate instead. Their code, tickets and wallet stay on record.'];
        }
    }
}

/* ---------- the register ---------- */
// admin_profiles.kyc_status only exists once upgrade-2026-09-agent-kyc.sql has
// run; selecting it blindly would 500 the whole register on an older database.
$kycSel = AgentWallet::kycAvailable() ? 'p.kyc_status, p.kyc_doc_path,' : "'none' AS kyc_status, '' AS kyc_doc_path,";
$rows = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.email, a.is_active, a.last_login_at, a.created_at,
            p.display_phone, p.whatsapp, p.counter_name, p.agent_kind, p.contact_person, p.address, p.id_type, p.id_number,
            p.cash_limit, p.daily_booking_limit, p.suspended_reason, p.suspended_at, p.joined_on, p.payout_method, p.payout_account, p.notes, p.photo_path, $kycSel
            (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS sales,
            (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.id) AS sales_all,
            (SELECT COUNT(*) FROM booking_passengers bp JOIN bookings b ON b.id = bp.booking_id
              WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS pax,
            (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS revenue,
            (SELECT MAX(b.created_at) FROM bookings b WHERE b.sold_by_admin_id = a.id) AS last_sale
       FROM admins a
       LEFT JOIN admin_profiles p ON p.admin_id = a.id
      WHERE a.role = 'agent'"
);
$flatOn     = AgentWallet::flatMode();
$rateDirect = Settings::getFloat('agent_flat_direct', 200.0);
$rateJoint  = Settings::getFloat('agent_flat_joint', 400.0);
// Wallet balances for the whole register in ONE grouped read (5 Sep 2026;
// was one AgentWallet::balances() query per agent). Same arithmetic as
// balances(): SUM(amount) per account, rounded to 2 dp.
$balMap = [];
try {
    foreach (Database::fetchAll(
        "SELECT agent_admin_id, account, COALESCE(SUM(amount), 0) AS bal FROM agent_ledger GROUP BY agent_admin_id, account"
    ) as $bl) {
        $balMap[(int) $bl['agent_admin_id']][(string) $bl['account']] = round((float) $bl['bal'], 2);
    }
} catch (Throwable $e) { $balMap = []; }
foreach ($rows as &$r) {
    $id           = (int) $r['id'];
    $r['serial']  = AgentWallet::agentCodeFor($id);
    $r['code']    = AgentWallet::agentCodeLabel($id);
    $r['kind']    = AgentWallet::normaliseKind((string) ($r['agent_kind'] ?? AgentWallet::KIND_ORG));
    $r['tier']    = AgentWallet::agentTypeFor($id);
    // 15 Oct 2026: the ledger's figures first — with the defaults on the left,
    // PHP's "+" kept the zeros and every row read "settled · due ₹0 · cash ₹0".
    $bal          = ($balMap[$id] ?? []) + ['commission' => 0.0, 'cash' => 0.0];
    $r['comm_due'] = (float) ($bal['commission'] ?? 0);
    $r['cash_bal'] = (float) ($bal['cash'] ?? 0);
    // Net position — same sign rule as AgentWallet::netPosition() (cash − commission,
    // positive = the agent owes the company on balance), taken from the grouped
    // read above so the register stays one query rather than one per agent.
    $r['net']     = round($r['cash_bal'] - $r['comm_due'], 2);
    $r['salary']  = AgentWallet::salaryFor($id);
    $r['advance'] = (float) (AgentWallet::advanceSummary($id)['outstanding'] ?? 0);
    $r['has_id']  = (string) ($r['kyc_doc_path'] ?? '') !== '';
    $r['has_agr'] = AgentWallet::agreementPath($id) !== '';
    $r['kyc']     = in_array((string) ($r['kyc_status'] ?? ''), AgentWallet::KYC_STATUSES, true) ? (string) $r['kyc_status'] : 'none';
    // Settlement overdue (agent_settlement_due_days, 0 = off). Only agents actually
    // holding cash are asked, so this costs nothing while the rule is off.
    $r['overdue'] = $r['cash_bal'] > 0.009 ? AgentWallet::settlementOverdueDays($id) : 0;
    // Over the cash limit (15 Oct 2026): own limit, else the company default,
    // else the cash alert's amount — the same agents the bell names.
    $r['cash_lim']  = AgentWallet::effectiveCashLimit((float) ($r['cash_limit'] ?? 0));
    $r['over_cash'] = $r['cash_lim'] > 0.009 && $r['cash_bal'] > $r['cash_lim'] + 0.009;
    // A rejected application (staff.php keeps it, switched off, with a
    // suspended_reason) is not "pending" any more.
    $r['status']  = (int) $r['is_active'] === 1 ? 'active'
        : ($r['code'] === '' && (int) $r['sales_all'] === 0 && trim((string) ($r['suspended_reason'] ?? '')) === '' ? 'pending' : 'inactive');
    $r['wa']      = (string) ($r['whatsapp'] ?: $r['phone'] ?: $r['display_phone'] ?: '');
    // What this agent is actually paid per passenger (override > tier > percent engine).
    $ov = AgentWallet::commissionOverrideFor($id);
    if (($ov['mode'] ?? null) === 'flat') {
        $r['rate'] = '₹' . number_format((float) ($ov['flat'] ?? 0)) . '/pax';
        $r['rate_note'] = 'agent override';
        $r['rate_sort'] = (float) ($ov['flat'] ?? 0);
    } elseif (($ov['mode'] ?? null) === 'percent' || !$flatOn) {
        $r['rate'] = rtrim(rtrim(number_format(AgentWallet::commissionPercentFor($id), 2), '0'), '.') . '%';
        $r['rate_note'] = ($ov['mode'] ?? null) === 'percent' ? 'agent override' : 'percent engine';
        $r['rate_sort'] = AgentWallet::commissionPercentFor($id);
    } else {
        $r['rate'] = '₹' . number_format($r['tier'] === 'joint' ? $rateJoint : $rateDirect) . '/pax';
        $r['rate_note'] = $r['tier'] === 'joint' ? 'Team / org tier' : 'Direct tier';
        $r['rate_sort'] = $r['tier'] === 'joint' ? $rateJoint : $rateDirect;
    }
}
unset($r);
// Serial order: numbered agents first (1, 2, 3...), then the un-numbered by id.
usort($rows, static function (array $x, array $y): int {
    $sx = $x['serial'] ?? PHP_INT_MAX; $sy = $y['serial'] ?? PHP_INT_MAX;
    if ($sx !== $sy) { return $sx <=> $sy; }
    return (int) $x['id'] <=> (int) $y['id'];
});

$header = ['Serial', 'Type', 'Agent code', 'Name', 'Contact person', 'Mobile', 'WhatsApp', 'City / counter', 'Address', 'Email', 'Document', 'KYC',
           'Commission rate', 'Commission due (Rs)', 'Cash held (Rs)', 'Net position (Rs)', 'Settlement overdue (days)', 'Sales', 'Revenue (Rs)', 'Daily limit', 'Status', 'Last sale', 'Last login', 'Joined'];
$flat   = static fn(array $r): array => [
    $r['serial'] === null ? '' : (int) $r['serial'], AgentWallet::kindLabel($r['kind']), (string) $r['code'],
    (string) ($r['full_name'] ?? ''), (string) ($r['contact_person'] ?? ''), (string) ($r['phone'] ?? ''), (string) $r['wa'],
    (string) ($r['counter_name'] ?? ''), (string) ($r['address'] ?? ''), (string) ($r['email'] ?? ''),
    trim((string) ($r['id_type'] ?? '') . ' ' . (string) ($r['id_number'] ?? '')), ucfirst((string) $r['kyc']),
    $r['rate'] . ' (' . $r['rate_note'] . ')', $r['comm_due'], $r['cash_bal'], $r['net'], (int) $r['overdue'],
    (int) $r['sales'], (float) $r['revenue'], (int) ($r['daily_booking_limit'] ?? 0),
    ucfirst((string) $r['status']), (string) ($r['last_sale'] ?? ''), (string) ($r['last_login_at'] ?? ''),
    (string) ($r['joined_on'] ?: substr((string) $r['created_at'], 0, 10)),
];
$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'xlsx') {
    Logger::audit('agent.export', 'admin', $export, null, ['rows' => count($rows)], 'agents register exported');
    $data = array_map($flat, $rows);
    if ($export === 'xlsx') { shg_export_xlsx('SHG-agents-' . date('Y-m-d') . '.xlsx', 'Agents', $header, $data, [13, 14, 15, 16, 17, 18, 19]); }
    shg_export_csv('SHG-agents-' . date('Y-m-d') . '.csv', $header, $data);
}

$active  = count(array_filter($rows, static fn($r) => $r['status'] === 'active'));
$pending = count(array_filter($rows, static fn($r) => $r['status'] === 'pending'));
$orgs    = count(array_filter($rows, static fn($r) => $r['kind'] === AgentWallet::KIND_ORG));
$persons = count($rows) - $orgs;
$dueSum  = array_sum(array_map(static fn($r) => $r['comm_due'], $rows));
$overdueN = count(array_filter($rows, static fn($r) => (int) $r['overdue'] > 0));
$overCashN = count(array_filter($rows, static fn($r) => $r['over_cash']));
$overCashSum = array_sum(array_map(static fn($r) => $r['over_cash'] ? $r['cash_bal'] : 0.0, $rows));
$kycVerified = count(array_filter($rows, static fn($r) => $r['kyc'] === 'verified'));
$kycWaiting  = count(array_filter($rows, static fn($r) => $r['kyc'] === 'submitted'));
$csrf    = Security::e(Security::csrfToken());
$k       = CSRF_TOKEN_NAME;
$e       = static fn($v): string => Security::e((string) ($v ?? ''));
$cities  = array_values(array_unique(array_filter(array_map(static fn($r) => (string) ($r['counter_name'] ?? ''), $rows))));
sort($cities);
$nextOrg    = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_ORG);
$nextPerson = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_PERSON);

admin_header('Agents', 'agents');
if ($flash !== null) {
    // [kind, text] or, after a Quick add, [kind, text, link, link label].
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1])
       . (isset($flash[2], $flash[3]) ? ' <a href="' . Security::e($flash[2]) . '"><b>' . Security::e($flash[3]) . ' →</b></a>' : '') . '</div>';
}
?>
<div class="cards">
  <div class="card"><div class="k">Agents</div><div class="v"><?= count($rows) ?></div><div class="muted" style="font-size:12px"><?= $orgs ?> organization · <?= $persons ?> person</div></div>
  <div class="card"><div class="k">Active</div><div class="v"><?= $active ?></div></div>
  <div class="card"><div class="k">Pending approval</div><div class="v"><?= $pending ?></div></div>
  <div class="card"><div class="k">Commission due</div><div class="v">₹<?= number_format($dueSum) ?></div></div>
  <div class="card"><div class="k">KYC verified</div><div class="v"><?= $kycVerified ?><small> / <?= count($rows) ?></small></div><div class="muted" style="font-size:12px"><?= $kycWaiting > 0 ? $kycWaiting . ' waiting for review' : 'none waiting' ?></div></div>
  <?php if ($overdueN > 0): ?><div class="card"><div class="k">Settlement overdue</div><div class="v" style="color:var(--danger)"><?= $overdueN ?></div><div class="muted" style="font-size:12px">holding cash past the due days</div></div><?php endif; ?>
  <?php if ($overCashN > 0): ?><div class="card"><div class="k">Over cash limit</div><div class="v" style="color:var(--danger)"><?= $overCashN ?></div><div class="muted" style="font-size:12px">holding ₹<?= number_format($overCashSum) ?> — collect or net it off</div></div><?php endif; ?>
</div>

<?php if ($canManage): ?>
<?php /* Quick add (15 Oct 2026): one line on a laptop, a short stack on a
         phone — name, mobile, Org / Person, then the two optional boxes.
         Everything else is filled in by AgentWallet::createAgent. */ ?>
<style>
.ag-qa{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(0,1fr) minmax(0,.7fr) auto;gap:8px;align-items:end;margin:0 0 12px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--card)}
.ag-qa .ag-qa-opt{grid-column:1/-1;display:none;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.ag-qa.more .ag-qa-opt{display:grid}
.ag-qa .ag-qa-tg{background:none;border:0;padding:0;color:var(--royal,#1a3fc4);font:700 12px/1 inherit;cursor:pointer;margin-left:6px}
.ag-qa label{display:flex;flex-direction:column;gap:3px;font-size:11.5px;font-weight:700;color:var(--mut);min-width:0}
.ag-qa input,.ag-qa select{width:100%;min-height:44px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink);font-size:15px;box-sizing:border-box;min-width:0}
.ag-qa .btn{min-height:44px;white-space:nowrap}
.ag-qa .ag-qa-h{grid-column:1/-1;font-size:13px;font-weight:800;color:var(--ink);margin:0}
@media (max-width:900px){.ag-qa{grid-template-columns:repeat(2,minmax(0,1fr))}.ag-qa .ag-qa-wide,.ag-qa .btn{grid-column:1/-1}.ag-qa .ag-qa-opt{grid-template-columns:minmax(0,1fr)}}
</style>
<form method="post" class="ag-qa" id="agQuickAdd" autocomplete="off">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="quick_add">
  <p class="ag-qa-h">⚡ Quick add agent — just name + mobile <span class="muted" style="font-weight:500">· login = their mobile, next free SHG code at once</span><button type="button" class="ag-qa-tg" onclick="this.closest('form').classList.toggle('more')">＋ city / email</button></p>
  <?php // A refused Quick add keeps what was typed, so the fix is one box, not five.
        $qaKeep = static fn(string $f): string => ($flash[0] ?? '') === 'bad' && ($_POST['action'] ?? '') === 'quick_add' && is_string($_POST[$f] ?? null) ? $e($_POST[$f]) : ''; ?>
  <label class="ag-qa-wide">Name / organization<input name="qa_name" required minlength="2" maxlength="120" placeholder="Ram Bahadur / Krishna Travels" value="<?= $qaKeep('qa_name') ?>"></label>
  <label>Mobile<input name="qa_phone" required inputmode="tel" minlength="10" maxlength="20" placeholder="98765 43210" value="<?= $qaKeep('qa_phone') ?>"></label>
  <label>Type<select name="qa_kind"><option value="org">🏢 Org</option><option value="person"<?= $qaKeep('qa_kind') === 'person' ? ' selected' : '' ?>>👤 Person</option></select></label>
  <button class="btn" type="submit">＋ Add agent</button>
  <div class="ag-qa-opt">
    <label>City <span style="font-weight:400">(optional)</span><input name="qa_city" list="counterLocList" maxlength="120" placeholder="Nepalgunj" value="<?= $qaKeep('qa_city') ?>"></label>
    <label>Email <span style="font-weight:400">(optional)</span><input name="qa_email" type="email" maxlength="191" placeholder="agent@example.com" value="<?= $qaKeep('qa_email') ?>"></label>
  </div>
</form>
<?php endif; ?>

<?php /* 16 Oct 2026 (owner: "dherai option xa, category wise milau, nachaine
         falda"): one search box + one row of category chips with counts. The
         six dropdowns, exports and links fold into "☰ More" - same filters,
         nothing removed, just out of the way until needed. */ ?>
<div id="agCtl">
<div class="dt-bar">
  <input class="dt-q" type="search" placeholder="🔍 Name or mobile (/ to jump here) — code, city, contact also work" aria-label="Search agents" autocomplete="off">
  <span class="dt-count"></span>
</div>
<div class="dt-chips" role="toolbar" aria-label="Agent categories">
  <button type="button" class="dt-chip" data-dt-chip="{}">All</button>
  <button type="button" class="dt-chip" data-dt-chip='{"status":"active"}'>✅ Active</button>
  <button type="button" class="dt-chip" data-dt-chip='{"status":"pending"}' data-dt-hide0>⏳ Pending</button>
  <button type="button" class="dt-chip" data-dt-chip='{"kind":"org"}'>🏢 Organization</button>
  <button type="button" class="dt-chip" data-dt-chip='{"kind":"person"}'>👤 Person</button>
  <button type="button" class="dt-chip warn" data-dt-chip='{"cash":"over"}' data-dt-hide0>💰 Over cash limit</button>
  <button type="button" class="dt-chip" data-dt-chip='{"kyc":"submitted"}' data-dt-hide0>🪪 KYC waiting</button>
  <button type="button" class="dt-chip" data-dt-chip='{"sold":"0","status":"active"}' data-dt-hide0>😴 No sales yet</button>
  <button type="button" class="dt-chip" data-dt-chip='{"status":"inactive"}' data-dt-hide0>⏸ Deactivated</button>
</div>
<details class="dt-bar dt-more">
  <summary class="btn ghost">☰ More filters &amp; tools</summary>
  <div class="dt-more-p">
  <select data-dt-filter="kind" aria-label="Type">
    <option value="">All types</option><option value="org">Organization (1–20)</option><option value="person">Person (21+)</option>
  </select>
  <select data-dt-filter="status" aria-label="Status">
    <option value="">All statuses</option><option value="active">Active</option><option value="inactive">Deactivated</option><option value="pending">Pending approval</option>
  </select>
  <?php if ($cities !== []): ?>
  <select data-dt-filter="city" aria-label="City"><option value="">All cities</option><?php foreach ($cities as $c): ?><option value="<?= $e($c) ?>"><?= $e($c) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <select data-dt-filter="sold" aria-label="Sales"><option value="">Any sales</option><option value="1">Has sales</option><option value="0">No sales yet</option></select>
  <select data-dt-filter="kyc" aria-label="KYC"><option value="">Any KYC</option><option value="verified">KYC verified</option><option value="submitted">KYC submitted</option><option value="rejected">KYC rejected</option><option value="none">No KYC</option></select>
  <select data-dt-filter="cash" aria-label="Cash held"><option value="">Any cash held</option><option value="over">Over cash limit</option></select>
  <a class="btn ghost" href="agents.php?export=csv">⬇ CSV</a>
  <a class="btn ghost" href="agents.php?export=xlsx">⬇ Excel</a>
  <a class="btn ghost" href="agreements.php" title="Applications from the website, KYC and agreement status of every agent and partner">🤝 Agreements &amp; KYC</a>
  <?php /* staff.php is super-admin only — anyone else adds through Quick add above. */ ?>
  <?php if (Auth::isSuperadmin()): ?><a class="btn ghost" href="staff.php#addStaff" title="The full form: username, password, desk, contact person">＋ Full new-agent form</a><?php endif; ?>
  <span class="muted" style="font-size:12px;flex-basis:100%">Serial bands: <b>Organization 1–20</b> (next free <?= $nextOrg === null ? 'none' : (int) $nextOrg ?>) · <b>Person 21+</b> (next free <?= $nextPerson === null ? 'none' : (int) $nextPerson ?>). Switching an agent's type keeps their serial, wallet and commission history.</span>
  </div>
</details>
</div>

<!-- The desks the office has defined, offered to every "City / counter" box
     on this page. One <datalist> for all of them — each row's edit panel
     points at it by id. -->
<datalist id="counterLocList">
<?php foreach (Settings::counterLocations() as $cLocCode => $cLocName): ?>
  <option value="<?= $e($cLocName) ?>"><?= $e($cLocCode) ?></option>
<?php endforeach; ?>
</datalist>

<?php /* 2 Oct 2026 (owner: "scroll garnu parchha, button haru kasto chha — refined
         banaidinus"): 16 columns and ten buttons per row made every row five
         lines tall and pushed the actions off the right edge. Now nine columns,
         one line per agent: who (code + type + name, and the status when it is
         not Active), contact, sales, commission, wallet / net, salary /
         advance, KYC · papers, and three controls — View, Edit and a ⋯ menu holding the rest. Same data,
         same filters, same sort; the detail and edit rows are unchanged. */ ?>
<style>
.ag-t td{vertical-align:middle;padding:8px 10px}
.ag-t td.ag-who{white-space:normal;min-width:200px;max-width:280px}
.ag-who .ag-code{display:inline-flex;align-items:center;gap:4px;font:800 11.5px/1 ui-monospace,Consolas,monospace;padding:4px 8px;border-radius:999px;color:#fff;vertical-align:middle}
.ag-code.co{background:#7c3aed}.ag-code.in{background:#C2410C}.ag-code.none{background:var(--soft);color:var(--mut);box-shadow:inset 0 0 0 1px var(--line)}
.ag-who .ag-kind{font-size:11px;font-weight:700;color:var(--mut);margin-left:6px;vertical-align:middle}
.ag-who .ag-nm{display:block;font-weight:700;margin-top:3px;color:inherit;text-decoration:none;line-height:1.25}
.ag-who .ag-nm:hover{text-decoration:underline}
.ag-who .ag-sub{display:block;font-size:11.5px;color:var(--mut);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ag-t .ag-2l b{display:block;line-height:1.25}
.ag-t .ag-2l small{display:block;font-size:11px;color:var(--mut);line-height:1.3}
.ag-t .ag-wa{display:inline-flex;align-items:center;gap:4px;font-size:12px;text-decoration:none}
.ag-papers .ag-docs{margin-top:3px;display:flex;gap:8px}
.ag-papers .ag-docs a,.ag-papers .ag-docs span{font-size:12px;text-decoration:none}
.ag-acts{display:inline-flex;align-items:center;gap:4px}
.ag-acts .btn{padding:5px 10px;font-size:12.5px;min-height:32px}
.ag-more{position:relative;display:inline-block}
.ag-more>summary{list-style:none;cursor:pointer;padding:5px 11px;font-size:15px;line-height:1;min-height:32px;display:inline-flex;align-items:center}
.ag-more>summary::-webkit-details-marker{display:none}
.ag-menu{position:fixed;z-index:70;min-width:230px;background:var(--card);border:1px solid var(--line);border-radius:12px;
  box-shadow:0 14px 40px rgba(0,0,0,.22);padding:6px;display:flex;flex-direction:column;gap:2px}
.ag-menu a,.ag-menu button{display:flex;align-items:center;gap:8px;width:100%;text-align:left;background:none;border:0;border-radius:8px;
  padding:7px 11px;font:600 13px/1.2 inherit;color:var(--ink);text-decoration:none;cursor:pointer;min-height:auto}
.ag-menu a:hover,.ag-menu button:hover{background:var(--hover)}
.ag-menu form{margin:0}
.ag-menu hr{border:0;border-top:1px solid var(--line);margin:4px 2px}
.ag-menu .danger{color:var(--danger)}.ag-menu .ok{color:var(--success)}
@media (pointer:coarse){.ag-acts .btn,.ag-more>summary{min-height:44px}.ag-menu a,.ag-menu button{min-height:44px}}
</style>
<div class="dt-wrap">
<table class="dt no-card ag-t" data-controls="agCtl">
  <thead><tr>
    <th data-type="num">#</th><th>Agent</th><th>Contact</th>
    <th data-type="num">Sales</th><th data-type="num">Commission</th><th data-type="num">Wallet · net</th>
    <th>KYC · papers</th><th data-nosort>Actions</th>
  </tr></thead>
  <tbody>
  <?php if ($rows === []): ?>
    <tr><td colspan="8" class="muted" style="padding:22px;white-space:normal">No agents yet — add one with Quick add above, or approve an application from the public agent sign-up form on Staff &amp; Approvals.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $i => $r):
    $rid = 'a' . (int) $r['id'];
    $doc = trim((string) ($r['id_type'] ?? '') . ' ' . (string) ($r['id_number'] ?? ''));
    $isOrg = $r['kind'] === AgentWallet::KIND_ORG;
    $search = strtolower(implode(' ', [$r['serial'] ?? '', $r['full_name'], $r['contact_person'] ?? '', $r['phone'], $r['wa'], $r['code'], $r['counter_name'] ?? '', $r['email'] ?? '', $r['username'], AgentWallet::kindLabel($r['kind'])]));
    $waDigits = preg_replace('/\D/', '', $r['wa']);
    if ($waDigits !== '' && strlen($waDigits) === 10) { $waDigits = '91' . $waDigits; }
  ?>
    <tr data-search="<?= $e($search) ?>" data-kind="<?= $e($r['kind']) ?>" data-status="<?= $e($r['status']) ?>" data-city="<?= $e($r['counter_name']) ?>" data-sold="<?= (int) $r['sales'] > 0 ? '1' : '0' ?>" data-kyc="<?= $e($r['kyc']) ?>" data-cash="<?= $r['over_cash'] ? 'over' : 'ok' ?>"<?= $r['status'] === 'inactive' ? ' style="opacity:.75"' : '' ?>>
      <td class="num" data-sort="<?= $r['serial'] === null ? 99999 : (int) $r['serial'] ?>"><?= $r['serial'] === null ? '<span class="muted">—</span>' : '<b>' . (int) $r['serial'] . '</b>' ?></td>
      <td class="ag-who" data-sort="<?= $e(strtolower((string) ($r['full_name'] ?: $r['username']))) ?>">
        <?php $codeBand = $r['code'] === '' ? 'none' : (AgentWallet::isCompanyCode($r['code']) ? 'co' : 'in'); ?>
        <span class="ag-code <?= $codeBand ?>" title="<?= $codeBand === 'co' ? 'Company band (SHG 1–' . (int) Settings::getInt('agent_company_max_code', 10) . ')' : ($codeBand === 'in' ? 'Individual agent' : 'No SHG code yet') ?>"><?= $r['code'] !== '' ? $e($r['code']) : 'no code' ?></span><span class="ag-kind"><?= $isOrg ? '🏢 Org' : '👤 Person' ?></span><?php if ($r['status'] === 'pending'): ?> <span class="pill" style="background:#fef3c7;color:#92400e;font-size:10.5px">Pending</span><?php elseif ($r['status'] !== 'active'): ?> <span class="pill" style="background:#f7dcdc;color:#8a1f1f;font-size:10.5px">Deactivated</span><?php endif; ?>
        <a class="ag-nm" href="/admin/agent-360.php?agent=<?= (int) $r['id'] ?>"><?= $e($r['full_name'] ?: $r['username']) ?></a>
        <?php $sub = array_values(array_filter([($isOrg && !empty($r['contact_person'])) ? '👤 ' . $r['contact_person'] : '', !empty($r['counter_name']) ? '📍 ' . $r['counter_name'] : ''])); ?>
        <?php if ($sub !== []): ?><span class="ag-sub" title="<?= $e(implode(' · ', $sub)) ?>"><?= $e(implode(' · ', $sub)) ?></span><?php endif; ?>
      </td>
      <td class="ag-2l"><b class="mono"><?= $e($r['phone'] ?: $r['display_phone'] ?: '—') ?></b>
        <?= $waDigits !== '' ? '<a class="ag-wa" href="https://wa.me/' . $e($waDigits) . '" target="_blank" rel="noopener" title="Open WhatsApp chat">💬 ' . $e($r['wa']) . '</a>' : '<small>no WhatsApp</small>' ?></td>
      <td class="num ag-2l" data-sort="<?= (int) $r['sales'] ?>"><b><?= (int) $r['sales'] ?></b><small>₹<?= number_format((float) $r['revenue']) ?> · <?= (int) $r['pax'] ?> pax</small></td>
      <td class="num ag-2l" data-sort="<?= (float) $r['rate_sort'] ?>"><b><?= $e($r['rate']) ?></b><small><?= $e($r['rate_note']) ?></small></td>
      <td class="num ag-2l" data-sort="<?= $r['net'] ?>">
        <?php if ($r['net'] > 0.009): ?><b style="color:#b06a00">₹<?= number_format($r['net']) ?> agent owes</b>
        <?php elseif ($r['net'] < -0.009): ?><b style="color:#2E5FA8">₹<?= number_format(abs($r['net'])) ?> company owes</b>
        <?php else: ?><b class="muted" style="font-weight:600">settled</b><?php endif; ?>
        <small>due ₹<?= number_format($r['comm_due']) ?> · cash ₹<?= number_format($r['cash_bal']) ?><?= (int) $r['overdue'] > 0 ? ' · <b style="display:inline;color:var(--danger)">overdue ' . (int) $r['overdue'] . 'd</b>' : '' ?></small>
        <?php if ($r['over_cash']): ?><span class="pill st-bad" style="font-size:10.5px;margin-top:3px" title="Holding ₹<?= number_format($r['cash_bal']) ?> against a ₹<?= number_format($r['cash_lim']) ?> cash limit">Over ₹<?= number_format($r['cash_lim']) ?></span><?php endif; ?>
      </td>
      <td class="ag-papers" data-sort="<?= $e($r['kyc']) ?>"><div>
        <?php if ($r['kyc'] === 'verified'): ?><span class="pill st-ok">KYC ✓</span><?php elseif ($r['kyc'] === 'submitted'): ?><span class="pill st-warn">KYC waiting</span><?php elseif ($r['kyc'] === 'rejected'): ?><span class="pill st-bad">KYC rejected</span><?php else: ?><span class="pill st-muted">No KYC</span><?php endif; ?></div>
        <div class="ag-docs"><?= $r['has_id'] ? '<a href="/admin/agent-kyc-file.php?agent=' . (int) $r['id'] . '&amp;slot=1" target="_blank" title="ID card on file">🪪✓</a>' : '<span class="muted" title="No ID card">🪪✗</span>' ?>
        <?= $r['has_agr'] ? '<a href="/admin/agent-kyc-file.php?agent=' . (int) $r['id'] . '&amp;slot=3" target="_blank" title="Signed agreement on file">📄✓</a>' : '<a class="muted" href="/admin/agent-360.php?agent=' . (int) $r['id'] . '&amp;tab=profile" title="No signed agreement — upload it">📄✗</a>' ?></div>
      </td>
      <td><span class="ag-acts">
        <button type="button" class="btn ghost" data-dt-toggle="<?= $rid ?>v" title="Everything about this agent">👁 View</button>
        <?php if ($agChat): ?><a class="btn ghost" href="staff-chat.php?agent=<?= (int) $r['id'] ?>" title="Office chat with this agent" aria-label="Message <?= $e($r['full_name'] ?: $r['username']) ?>"><svg class="a-ic"><use href="#a-msg"/></svg> Message</a><?php endif; ?>
        <details class="ag-more">
          <summary class="btn ghost" title="More actions" aria-label="More actions for <?= $e($r['full_name'] ?: $r['username']) ?>">⋯</summary>
          <div class="ag-menu" role="menu">
            <?php if ($canManage): ?><button type="button" data-ag-edit="<?= $rid ?>e" role="menuitem">✏️ Edit details</button><hr><?php endif; ?>
            <a href="agent-360.php?agent=<?= (int) $r['id'] ?>" role="menuitem">💼 Wallet &amp; 360 view</a>
            <a href="agent.php?agent=<?= (int) $r['id'] ?>" role="menuitem">🧭 Agent panel (as they see it)</a>
            <a href="agent-sales.php?agent=<?= (int) $r['id'] ?>" role="menuitem">📊 Sales</a>
            <a href="bookings.php?agent=<?= (int) $r['id'] ?>" role="menuitem">🎫 Every ticket sold</a>
            <a href="agent.php?agent=<?= (int) $r['id'] ?>#tierRatesForm" role="menuitem">💰 Commission rate</a>
            <?php if ($canManage): ?><a href="agent.php?agent=<?= (int) $r['id'] ?>#loginCredentials" role="menuitem">🔑 Login / password</a><?php endif; ?>
            <?php /* 17 Sep 2026: one-click WhatsApp — this month's statement, and a payment
                     reminder only while the row's net position says the agent owes. Both
                     render nothing when wa_admin_tools_enabled is off or the role may not send. */ ?>
            <?php $waS = admin_wa_button('agent_statement', ['agent' => (int) $r['id'], 'from' => date('Y-m-01'), 'to' => todayISO()], 'Statement on WhatsApp', ['class' => '', 'title' => 'This month\'s account statement to the agent on WhatsApp — opens a preview first']);
                  $waR = $r['net'] > 0.009 ? admin_wa_button('agent_payment_reminder', ['agent' => (int) $r['id']], 'Cash reminder on WhatsApp', ['class' => '', 'title' => 'Ask the agent to hand over the cash they hold — opens a preview first']) : ''; ?>
            <?php if ($waS !== '' || $waR !== ''): ?><hr><?= $waS ?><?= $waR ?><?php endif; ?>
            <?php if ($canManage && (int) $r['id'] !== $selfId): ?>
              <hr>
              <form method="post" onsubmit="return confirm('<?= $r['status'] === 'active' ? 'Deactivate this agent? They can no longer sign in or sell.' : 'Activate this agent?' ?>')"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="<?= $r['status'] === 'active' ? 'danger' : 'ok' ?>" name="action" value="toggle" role="menuitem"><?= $r['status'] === 'active' ? '⏸ Deactivate' : '▶ Activate' ?></button></form>
            <?php endif; ?>
          </div>
        </details>
      </span></td>
    </tr>
    <tr class="dt-x" id="<?= $rid ?>v" hidden><td colspan="8">
      <div class="dt-grid">
        <div class="kv"><label>Serial · type · code</label><b><?= $r['serial'] === null ? 'not assigned' : '#' . (int) $r['serial'] ?> · <?= $e(AgentWallet::kindLabel($r['kind'])) ?> · <?= $e($r['code'] ?: '—') ?></b></div>
        <div class="kv"><label>Name · login username</label><b><?= $e($r['full_name']) ?> <span class="muted mono">· <?= $e($r['username']) ?></span></b></div>
        <div class="kv"><label>Sign-in email</label><b><?= $r['email'] ? $e($r['email']) : '<span style="color:var(--danger)">not set — they cannot sign in yet</span>' ?></b></div>
        <?php if ($isOrg): ?><div class="kv"><label>Contact person</label><b><?= $e($r['contact_person'] ?: '—') ?></b></div><?php endif; ?>
        <div class="kv"><label>Mobile · WhatsApp · email</label><b><?= $e($r['phone']) ?> · <?= $e($r['wa'] ?: '—') ?><br><?= $e($r['email'] ?: '—') ?></b></div>
        <div class="kv"><label>City / counter · address</label><b><?= $e($r['counter_name'] ?: '—') ?><br><?= $e($r['address'] ?: '—') ?></b></div>
        <div class="kv"><label>Document · KYC</label><b><?= $doc !== '' ? $e($doc) : '—' ?> · <?= $e(ucfirst($r['kyc'])) ?></b></div>
        <div class="kv"><label>Commission</label><b><?= $e($r['rate']) ?> <span class="muted">(<?= $e($r['rate_note']) ?>)</span></b></div>
        <div class="kv"><label>Sales (confirmed / all) · revenue</label><b><?= (int) $r['sales'] ?> / <?= (int) $r['sales_all'] ?> · ₹<?= number_format((float) $r['revenue']) ?></b></div>
        <div class="kv"><label>Commission due · cash held · net</label><b>₹<?= number_format($r['comm_due']) ?> · ₹<?= number_format($r['cash_bal']) ?> · <?= $r['net'] > 0.009 ? 'agent owes ₹' . number_format($r['net']) : ($r['net'] < -0.009 ? 'company owes ₹' . number_format(abs($r['net'])) : 'settled') ?><?= (int) $r['overdue'] > 0 ? ' · overdue ' . (int) $r['overdue'] . 'd' : '' ?></b></div>
        <div class="kv"><label>Daily booking limit · cash limit</label><b><?= (int) ($r['daily_booking_limit'] ?? 0) ?: 'no limit' ?> · ₹<?= number_format((float) ($r['cash_limit'] ?? 0)) ?></b></div>
        <div class="kv"><label>Salary · advance</label><b><?= $r['salary'] > 0 ? '₹' . number_format($r['salary']) : '—' ?> · ₹<?= number_format($r['advance']) ?></b></div>
        <div class="kv"><label>Payout</label><b><?= $e(trim(($r['payout_method'] ?? '') . ' ' . ($r['payout_account'] ?? '')) ?: '—') ?></b></div>
        <div class="kv"><label>Joined · last login · last sale</label><b><?= $e($r['joined_on'] ?: substr((string) $r['created_at'], 0, 10)) ?> · <?= $e($r['last_login_at'] ?: '—') ?><br><?= $e($r['last_sale'] ?: '—') ?></b></div>
        <?php if (!empty($r['suspended_reason'])): ?><div class="kv"><label>Suspended</label><b><?= $e($r['suspended_reason']) ?> (<?= $e($r['suspended_at']) ?>)</b></div><?php endif; ?>
        <?php if (!empty($r['notes'])): ?><div class="kv"><label>Notes</label><b><?= $e($r['notes']) ?></b></div><?php endif; ?>
      </div>
      <div class="dt-acts" style="padding:4px 2px">
        <a class="btn ghost" href="agent-passengers.php?agent=<?= (int) $r['id'] ?>">🧑‍🤝‍🧑 Passengers</a>
        <a class="btn ghost" href="agent-offline.php?agent=<?= (int) $r['id'] ?>">🧾 Paper tickets</a>
        <?php if ($canManage): ?>
          <a class="btn ghost" href="staff.php#agent-<?= (int) $r['id'] ?>">🔑 PIN / routes</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Switch this agent to <?= $isOrg ? 'Person' : 'Organization' ?>? The serial, wallet and commission history stay exactly as they are.')"><input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="agent_kind" value="<?= $isOrg ? 'person' : 'org' ?>"><button class="btn ghost" name="action" value="kind">🔁 Make <?= $isOrg ? 'Person' : 'Organization' ?></button></form>
          <?php /* 15 Oct 2026 (owner rule): no Delete here — an agent is deactivated from the ⋯ menu, never removed. */ ?>
        <?php endif; ?>
      </div>
    </td></tr>
    <?php if ($canManage): ?>
    <tr class="dt-x" id="<?= $rid ?>e" hidden><td colspan="8">
      <form method="post">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="dt-grid">
          <label>Type
            <select name="agent_kind" onchange="var cp=this.closest('form').querySelector('[data-cp]');if(cp){cp.style.display=this.value==='org'?'':'none'}">
              <option value="org" <?= $isOrg ? 'selected' : '' ?>>Organization (serial 1–20)</option>
              <option value="person" <?= !$isOrg ? 'selected' : '' ?>>Person (serial 21+)</option>
            </select></label>
          <label>Serial (<?= AgentWallet::AGENT_CODE_MIN ?>–<?= AgentWallet::AGENT_CODE_MAX ?>)<input name="agent_code" type="number" min="<?= AgentWallet::AGENT_CODE_MIN ?>" max="<?= AgentWallet::AGENT_CODE_MAX ?>" step="1" value="<?= $r['serial'] === null ? '' : (int) $r['serial'] ?>" placeholder="next org <?= (int) $nextOrg ?> · person <?= (int) $nextPerson ?>"></label>
          <label><?= $isOrg ? 'Organization name' : 'Full name' ?><input name="full_name" value="<?= $e($r['full_name']) ?>" required maxlength="120"></label>
          <label data-cp <?= $isOrg ? '' : 'style="display:none"' ?>>Contact person<input name="contact_person" value="<?= $e($r['contact_person']) ?>" maxlength="120" placeholder="who answers the phone"></label>
          <label>Mobile<input name="phone" value="<?= $e($r['phone']) ?>" required inputmode="numeric" maxlength="15"></label>
          <label>WhatsApp <span class="muted">(blank = same as mobile)</span><input name="whatsapp" value="<?= $e($r['whatsapp']) ?>" inputmode="numeric" maxlength="15"></label>
          <label>Commission tier
            <select name="agent_type">
              <option value="direct" <?= $r['tier'] === 'direct' ? 'selected' : '' ?>>Direct — ₹<?= number_format($rateDirect) ?> / passenger</option>
              <option value="joint" <?= $r['tier'] === 'joint' ? 'selected' : '' ?>>Team / organisation — ₹<?= number_format($rateJoint) ?> / passenger</option>
            </select></label>
          <label>Email <span class="muted">· part of their sign-in</span><input name="email" type="email" value="<?= $e($r['email']) ?>" maxlength="191" autocomplete="off"></label>
          <label>Login username<input name="login_username" value="<?= $e($r['username']) ?>" maxlength="60" autocapitalize="none" autocorrect="off" autocomplete="off"></label>
          <label>New password <span class="muted">· blank = keep</span><input name="login_password" type="text" minlength="8" maxlength="72" placeholder="min 8 characters" autocomplete="new-password"></label>
          <!-- Suggestions only (24 Sep 2026): the desks defined in Admin →
               Settings → counter_locations, so this box and the counter
               picker on Staff speak the same vocabulary. Still free text —
               an agent can sit somewhere the list has never heard of. To
               also give them the SHORT code that prints on their tickets,
               use the Counter · location column on the Staff page. -->
          <label>City / counter<input name="counter_name" list="counterLocList" value="<?= $e($r['counter_name']) ?>" maxlength="120"></label>
          <label>Address<input name="address" value="<?= $e($r['address']) ?>" maxlength="255"></label>
          <label>Document type<input name="id_type" value="<?= $e($r['id_type']) ?>" maxlength="40" placeholder="Aadhaar / Citizenship / PAN"></label>
          <label>Document number<input name="id_number" value="<?= $e($r['id_number']) ?>" maxlength="60"></label>
          <label>Daily booking limit (0 = none)<input name="daily_booking_limit" type="number" min="0" value="<?= (int) ($r['daily_booking_limit'] ?? 0) ?>"></label>
          <label>Cash limit ₹<input name="cash_limit" type="number" min="0" step="1" value="<?= (int) ($r['cash_limit'] ?? 0) ?>"></label>
          <label>Notes<input name="notes" value="<?= $e($r['notes']) ?>" maxlength="500"></label>
        </div>
        <div class="dt-acts" style="padding:4px 2px"><button class="btn" type="submit">💾 Save</button><button class="btn ghost" type="button" data-dt-toggle="<?= $rid ?>e">Cancel</button>
          <span class="muted" style="font-size:11.5px;align-self:center">Changing the serial re-prints on future tickets only; a per-agent ₹/% override lives under 💰 Commission.</span></div>
      </form>
    </td></tr>
    <?php endif; ?>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<script>
/* The ⋯ menu (2 Oct 2026): a <details> whose panel is placed with position:fixed
   next to its button, so the table's own scroll box never clips it. One open
   at a time; a click elsewhere, Escape or a scroll closes it. */
(function(){
  var menus = document.querySelectorAll('.ag-more');
  function place(d){
    var s = d.querySelector('summary'), m = d.__menu; if (!s || !m) return;
    var r = s.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
    var w = m.offsetWidth, h = m.offsetHeight;
    // Below the button if it fits, else above, else beside it (left), never
    // over the button itself and never past the window edge.
    var left = Math.min(Math.max(8, r.right - w), vw - w - 8), top;
    if (r.bottom + 6 + h <= vh - 8)      { top = r.bottom + 6; }
    else if (r.top - 6 - h >= 8)         { top = r.top - 6 - h; }
    else { top = Math.min(Math.max(8, r.top + r.height / 2 - h / 2), vh - h - 8); left = Math.max(8, r.left - w - 6); }
    m.style.left = left + 'px'; m.style.top = top + 'px';
  }
  /* The panel moves to <body> while open: an ancestor of the table carries a
     transform, and position:fixed inside a transformed box is measured from
     that box, not the window - the menu opened far off to the right. */
  function park(d){ var m = d.__menu; if (m && m.parentNode !== d) d.appendChild(m); }
  function closeAll(except){ menus.forEach(function(d){ if (d !== except && d.open) { d.open = false; park(d); } }); }
  menus.forEach(function(d){
    d.__menu = d.querySelector('.ag-menu');
    d.addEventListener('toggle', function(){
      if (d.open) { closeAll(d); document.body.appendChild(d.__menu); place(d); } else { park(d); }
    });
    // Edit lives in the menu now; it opens the row's edit panel itself (the
    // table's own toggle handler cannot see a click inside <body>).
    var ed = d.__menu.querySelector('[data-ag-edit]');
    if (ed) ed.addEventListener('click', function(){
      var x = document.getElementById(ed.getAttribute('data-ag-edit')); closeAll(null); if (!x) return;
      var open = x.hidden, r = x.previousElementSibling;
      while (r && r.classList.contains('dt-x')) r = r.previousElementSibling;
      for (var n = r ? r.nextElementSibling : null; n && n.classList.contains('dt-x'); n = n.nextElementSibling) n.hidden = true;
      x.hidden = !open;
      if (open) { x.scrollIntoView({block: 'nearest'}); var f = x.querySelector('input:not([type=hidden]),select'); if (f) f.focus(); }
    });
  });
  document.addEventListener('click', function(e){ if (!e.target.closest('.ag-more') && !e.target.closest('.ag-menu')) closeAll(null); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeAll(null); });
  window.addEventListener('scroll', function(e){ if (!(e.target && e.target.closest && e.target.closest('.ag-menu'))) closeAll(null); }, true);
  window.addEventListener('resize', function(){ closeAll(null); });
})();
</script>
<?php admin_footer(); ?>
