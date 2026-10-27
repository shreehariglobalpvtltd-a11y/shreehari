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
 * activate / deactivate / switch kind / delete post back here (super-admin
 * or staff.manage); Export streams CSV / .xlsx. Row actions link out to the
 * wallet, sales, commission and ticket pages that already exist.
 *
 * Approvals, PIN / password resets and route permissions stay on
 * staff.php and agent.php — this page is the register, not a second copy
 * of those workflows.
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
$selfId    = (int) ($admin['id'] ?? 0);
$flash     = null;

/* ---------- actions ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canManage) {
        $flash = ['bad', 'Only a super-admin (or staff manager) can change agent records.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        $id  = (int) ($_POST['id'] ?? 0);

        // --- BULK & GLOBAL CATEGORY ACTIONS ---
        if ($act === 'bulk_approve') {
            $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
            if ($ids !== []) {
                $ph = implode(',', $ids);
                Database::run("UPDATE admins SET is_active = 1 WHERE id IN ($ph) AND role = 'agent'");
                Logger::audit('agent.bulk_approve', 'admin', 'bulk', null, ['count' => count($ids)], 'Bulk activated agents');
                $flash = ['ok', count($ids) . ' agent(s) approved and activated.'];
            }
        } elseif ($act === 'bulk_pause') {
            $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), static fn($x) => $x !== $selfId);
            if ($ids !== []) {
                $ph = implode(',', $ids);
                Database::run("UPDATE admins SET is_active = 0 WHERE id IN ($ph) AND role = 'agent'");
                Logger::audit('agent.bulk_pause', 'admin', 'bulk', null, ['count' => count($ids)], 'Bulk paused agents');
                $flash = ['ok', count($ids) . ' agent(s) paused / deactivated.'];
            }
        } elseif ($act === 'bulk_quota') {
            $ids   = array_map('intval', (array) ($_POST['ids'] ?? []));
            $quota = max(0, (int) ($_POST['daily_quota'] ?? 0));
            if ($ids !== []) {
                $ph = implode(',', $ids);
                Database::run("UPDATE admin_profiles SET daily_booking_limit = :q WHERE admin_id IN ($ph)", ['q' => $quota]);
                Logger::audit('agent.bulk_quota', 'admin', 'bulk', null, ['count' => count($ids), 'quota' => $quota], 'Bulk updated daily booking quota');
                $flash = ['ok', 'Daily booking quota updated to ' . ($quota ?: 'unlimited') . ' for ' . count($ids) . ' agent(s).'];
            }
        } elseif ($act === 'record_settlement') {
            $agentId = (int) ($_POST['agent_id'] ?? 0);
            $amount  = max(0.0, (float) ($_POST['amount'] ?? 0));
            $accType = (string) ($_POST['account_type'] ?? 'commission');
            $note    = trim(Security::clean($_POST['settle_note'] ?? 'Settlement via unified panel', 255));
            if ($agentId > 0 && $amount > 0) {
                $ref = 'SETTL-' . date('YmdHis');
                if ($accType === 'cash') {
                    AgentWallet::recordCashReceived($agentId, $amount, $ref, $note, $selfId);
                } else {
                    AgentWallet::recordPayout($agentId, $amount, $ref, $note, $selfId);
                }
                $flash = ['ok', 'Settlement of ₹' . number_format($amount, 2) . ' recorded for ' . $accType . ' account.'];
            }
        } elseif ($act === 'save_branch') {
            $branchName = trim(Security::clean($_POST['branch_name'] ?? '', 120));
            $city       = trim(Security::clean($_POST['city'] ?? '', 120));
            $phone      = trim(Security::clean($_POST['phone'] ?? '', 40));
            $manager    = trim(Security::clean($_POST['manager_name'] ?? '', 120));
            $address    = trim(Security::clean($_POST['address'] ?? '', 255));
            $branches   = json_decode(Settings::getString('company_branches', '[]'), true) ?: [];
            $bId        = (string) ($_POST['branch_id'] ?? ('br_' . time()));
            $branches[$bId] = [
                'id' => $bId, 'name' => $branchName, 'city' => $city, 'phone' => $phone,
                'manager' => $manager, 'address' => $address, 'updated_at' => date('Y-m-d H:i:s')
            ];
            Settings::set('company_branches', json_encode($branches));
            $flash = ['ok', 'Branch "' . $branchName . '" saved successfully.'];
        } elseif ($act === 'save_pvt_ltd') {
            $legalName    = trim(Security::clean($_POST['legal_name'] ?? 'S Hari Global Pvt Ltd', 150));
            $cin          = trim(Security::clean($_POST['cin_number'] ?? '', 60));
            $gst          = trim(Security::clean($_POST['gst_number'] ?? '', 40));
            $pan          = trim(Security::clean($_POST['pan_number'] ?? '', 40));
            $upi          = trim(Security::clean($_POST['upi_id'] ?? '', 60));
            $bankName     = trim(Security::clean($_POST['bank_name'] ?? '', 100));
            $bankAcc      = trim(Security::clean($_POST['bank_acc'] ?? '', 50));
            $bankIfsc     = trim(Security::clean($_POST['bank_ifsc'] ?? '', 30));
            $announcement = trim(Security::clean($_POST['announcement'] ?? '', 500));
            $pvtData = [
                'legal_name' => $legalName, 'cin' => $cin, 'gst' => $gst, 'pan' => $pan,
                'upi' => $upi, 'bank_name' => $bankName, 'bank_acc' => $bankAcc, 'bank_ifsc' => $bankIfsc,
                'announcement' => $announcement, 'updated_at' => date('Y-m-d H:i:s')
            ];
            Settings::set('pvt_ltd_config', json_encode($pvtData));
            if ($announcement !== '') {
                Settings::set('company_announcement', $announcement);
            }
            $flash = ['ok', 'S Hari Global Pvt Ltd company & bank settings saved.'];
        } elseif ($act === 'duty_status') {
            $staffId = (int) ($_POST['staff_id'] ?? 0);
            $status  = (string) ($_POST['duty_status'] ?? 'on_duty');
            if (in_array($status, ['on_duty', 'off_duty', 'on_leave'], true) && $staffId > 0) {
                Database::run("INSERT INTO admin_profiles (admin_id, duty_status) VALUES (:aid, :st) ON DUPLICATE KEY UPDATE duty_status = :st2", [
                    'aid' => $staffId, 'st' => $status, 'st2' => $status
                ]);
                $flash = ['ok', 'Staff duty status updated to ' . $status . '.'];
            }
        } elseif ($act === 'link_parent_agent') {
            $subId    = (int) ($_POST['subagent_id'] ?? 0);
            $parentId = (int) ($_POST['parent_id'] ?? 0) ?: null;
            if ($subId > 0) {
                Database::run("INSERT INTO admin_profiles (admin_id, parent_agent_id) VALUES (:aid, :pid) ON DUPLICATE KEY UPDATE parent_agent_id = :pid2", [
                    'aid' => $subId, 'pid' => $parentId, 'pid2' => $parentId
                ]);
                $flash = ['ok', 'Sub-agent hierarchy link updated.'];
            }
        } else {
            // Single agent operations
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
            // Same rule as Staff & Agents: only an agent with no sales and no
            // wallet history can be removed; everyone else is deactivated.
            if (!Auth::isSuperadmin()) {
                $flash = ['bad', 'Only a super-admin can delete an agent.'];
            } elseif ((int) $a['id'] === $selfId) {
                $flash = ['bad', 'You cannot delete your own account.'];
            } else {
                $hasBookings = Database::exists('SELECT 1 FROM bookings WHERE sold_by_admin_id = :a LIMIT 1', ['a' => $id]);
                $hasLedger   = false;
                try { $hasLedger = Database::exists('SELECT 1 FROM agent_ledger WHERE agent_admin_id = :a LIMIT 1', ['a' => $id]); } catch (Throwable $e) {}
                if ($hasBookings || $hasLedger) {
                    $flash = ['bad', 'This agent has sales / wallet history — deleting would break those records. Deactivate instead.'];
                } else {
                    try { AgentWallet::setAgentCode($id, null, $selfId); } catch (Throwable $e) {}
                    try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
                    Database::run("DELETE FROM admins WHERE id = :id AND role = 'agent'", ['id' => $id]);
                    Logger::audit('agent.delete', 'admin', (string) $a['username'], null, null, 'agent deleted (no sales/wallet history)');
                    $flash = ['ok', 'Agent "' . ($a['full_name'] ?: $a['username']) . '" deleted.'];
                }
            }
        }
    }
}
}

/* ---------- the register ---------- */
$activeTier = (string) ($_GET['tier'] ?? 'master_agents');
if (!in_array($activeTier, ['pvt_ltd', 'staff', 'master_agents', 'sub_agents'], true)) {
    $activeTier = 'master_agents';
}
$parentFilter = (int) ($_GET['parent'] ?? 0);

$rows = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.email, a.is_active, a.last_login_at, a.created_at,
            p.display_phone, p.whatsapp, p.counter_name, p.agent_kind, p.contact_person, p.address, p.id_type, p.id_number,
            p.cash_limit, p.daily_booking_limit, p.suspended_reason, p.suspended_at, p.joined_on, p.payout_method, p.payout_account, p.notes, p.photo_path,
            p.parent_agent_id,
            (SELECT full_name FROM admins WHERE id = p.parent_agent_id) AS parent_agent_name,
            (SELECT COUNT(*) FROM admin_profiles sp WHERE sp.parent_agent_id = a.id) AS subagent_count,
            (SELECT ref FROM agent_applications ap WHERE ap.admin_id = a.id ORDER BY ap.id DESC LIMIT 1) AS app_ref,
            (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS sales,
            (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.id) AS sales_all,
            (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS revenue,
            (SELECT MAX(b.created_at) FROM bookings b WHERE b.sold_by_admin_id = a.id) AS last_sale
       FROM admins a
       LEFT JOIN admin_profiles p ON p.admin_id = a.id
      WHERE a.role = 'agent'"
);

$staffRows = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.email, a.role, a.is_active, a.created_at,
            p.display_phone, p.whatsapp, p.address, p.monthly_salary, p.duty_status, p.assigned_bus_id,
            b.bus_number AS assigned_bus,
            (SELECT ref FROM staff_applications sap WHERE sap.admin_id = a.id ORDER BY sap.id DESC LIMIT 1) AS app_ref
       FROM admins a
       LEFT JOIN admin_profiles p ON p.admin_id = a.id
       LEFT JOIN buses b ON b.id = p.assigned_bus_id
      WHERE a.role IN ('admin','counter','staff','driver','accountant')
      ORDER BY a.role, a.id"
);

$pvtLtd = json_decode(Settings::getString('pvt_ltd_config', ''), true) ?: [
    'legal_name' => 'S Hari Global Pvt Ltd',
    'cin' => 'U63040GJ2024PTC152341',
    'gst' => '24AAECS4912J1Z8',
    'pan' => 'AAECS4912J',
    'upi' => 'shreehariglobal@sbi',
    'bank_name' => 'State Bank of India',
    'bank_acc' => '41238947291',
    'bank_ifsc' => 'SBIN0001234',
    'announcement' => Settings::getString('company_announcement', 'Welcome to S Hari Global Pvt Ltd - Premium Gujarat <-> Nepal Direct Bus Services.')
];

$branches = json_decode(Settings::getString('company_branches', ''), true) ?: [
    'br_mehsana' => ['name' => 'Head Office Mehsana', 'city' => 'Mehsana', 'phone' => '+91 98250 12345', 'manager' => 'Head Office Admin', 'address' => 'Radhanpur Road, Mehsana Highway, Gujarat'],
    'br_ahmedabad' => ['name' => 'Ahmedabad Branch', 'city' => 'Ahmedabad', 'phone' => '+91 98251 23456', 'manager' => 'Ahmedabad Counter Lead', 'address' => 'Nana Chiloda Ring Road / Geeta Mandir, Ahmedabad'],
    'br_surat' => ['name' => 'Surat Branch', 'city' => 'Surat', 'phone' => '+91 98252 34567', 'manager' => 'Surat Counter Manager', 'address' => 'Kamrej Char Rasta / Sahara Darwaja, Surat'],
    'br_rajkot' => ['name' => 'Rajkot Branch', 'city' => 'Rajkot', 'phone' => '+91 98253 45678', 'manager' => 'Rajkot Office Lead', 'address' => 'Greenland Chokdi, Rajkot, Gujarat'],
    'br_nepalgunj' => ['name' => 'Nepalgunj / Border Branch', 'city' => 'Nepalgunj', 'phone' => '+977 98012 34567', 'manager' => 'Border Operations Head', 'address' => 'Jamunaha Border / B.P. Chowk, Nepalgunj, Banke, Nepal'],
];
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
    $bal          = ['commission' => 0.0, 'cash' => 0.0] + ($balMap[$id] ?? []);
    $r['comm_due'] = (float) ($bal['commission'] ?? 0);
    $r['cash_bal'] = (float) ($bal['cash'] ?? 0);
    $r['status']  = (int) $r['is_active'] === 1 ? 'active' : ($r['code'] === '' && (int) $r['sales_all'] === 0 ? 'pending' : 'inactive');
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

$header = ['Serial', 'Type', 'Agent code', 'Name', 'Contact person', 'Mobile', 'WhatsApp', 'City / counter', 'Address', 'Email', 'Document',
           'Commission rate', 'Commission due (Rs)', 'Cash held (Rs)', 'Sales', 'Revenue (Rs)', 'Daily limit', 'Status', 'Last sale', 'Last login', 'Joined'];
$flat   = static fn(array $r): array => [
    $r['serial'] === null ? '' : (int) $r['serial'], AgentWallet::kindLabel($r['kind']), (string) $r['code'],
    (string) ($r['full_name'] ?? ''), (string) ($r['contact_person'] ?? ''), (string) ($r['phone'] ?? ''), (string) $r['wa'],
    (string) ($r['counter_name'] ?? ''), (string) ($r['address'] ?? ''), (string) ($r['email'] ?? ''),
    trim((string) ($r['id_type'] ?? '') . ' ' . (string) ($r['id_number'] ?? '')),
    $r['rate'] . ' (' . $r['rate_note'] . ')', $r['comm_due'], $r['cash_bal'], (int) $r['sales'], (float) $r['revenue'], (int) ($r['daily_booking_limit'] ?? 0),
    ucfirst((string) $r['status']), (string) ($r['last_sale'] ?? ''), (string) ($r['last_login_at'] ?? ''),
    (string) ($r['joined_on'] ?: substr((string) $r['created_at'], 0, 10)),
];
$export = (string) ($_GET['export'] ?? '');
if ($export === 'csv' || $export === 'xlsx') {
    Logger::audit('agent.export', 'admin', $export, null, ['rows' => count($rows)], 'agents register exported');
    $data = array_map($flat, $rows);
    if ($export === 'xlsx') { shg_export_xlsx('SHG-agents-' . date('Y-m-d') . '.xlsx', 'Agents', $header, $data, [12, 13, 14, 15, 16]); }
    shg_export_csv('SHG-agents-' . date('Y-m-d') . '.csv', $header, $data);
}

$masterAgents = array_values(array_filter($rows, static fn($r) => empty($r['parent_agent_id'])));
$subAgents    = array_values(array_filter($rows, static function($r) use ($parentFilter) {
    if (empty($r['parent_agent_id'])) return false;
    if ($parentFilter > 0 && (int)$r['parent_agent_id'] !== $parentFilter) return false;
    return true;
}));

$tableRows = match ($activeTier) {
    'sub_agents' => $subAgents,
    default      => $masterAgents,
};

$active  = count(array_filter($tableRows, static fn($r) => $r['status'] === 'active'));
$pending = count(array_filter($tableRows, static fn($r) => $r['status'] === 'pending'));
$orgs    = count(array_filter($tableRows, static fn($r) => $r['kind'] === AgentWallet::KIND_ORG));
$persons = count($tableRows) - $orgs;
$dueSum  = array_sum(array_map(static fn($r) => $r['comm_due'], $tableRows));
$csrf    = Security::e(Security::csrfToken());
$k       = CSRF_TOKEN_NAME;
$e       = static fn($v): string => Security::e((string) ($v ?? ''));
$cities  = array_values(array_unique(array_filter(array_map(static fn($r) => (string) ($r['counter_name'] ?? ''), $rows))));
sort($cities);
$nextOrg    = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_ORG);
$nextPerson = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_PERSON);

admin_header('Agents & Organization Hub', 'agents');
?>
<style>
.tier-tabs { display:flex; gap:8px; margin-bottom:18px; overflow-x:auto; padding-bottom:6px; border-bottom:2px solid var(--line,#e2e8f0); }
.tier-tab { padding:10px 16px; border-radius:8px; font-weight:700; font-size:13.5px; text-decoration:none; display:inline-flex; align-items:center; gap:8px; border:1px solid transparent; transition:all .15s ease; white-space:nowrap; }
.tier-tab.active { background:linear-gradient(135deg,#0d3b66,#0077b6); color:#fff; box-shadow:0 3px 10px rgba(13,59,102,.25); }
.tier-tab:not(.active) { background:var(--card,#fff); color:var(--ink,#1e293b); border-color:var(--line,#e2e8f0); }
.tier-tab:not(.active):hover { background:var(--hover,#f1f5f9); }
.tier-badge { background:rgba(255,255,255,0.25); color:#fff; padding:2px 7px; border-radius:10px; font-size:11px; }
.tier-tab:not(.active) .tier-badge { background:#e2e8f0; color:#334155; }
.bulk-bar { position:fixed; left:50%; bottom:20px; transform:translateX(-50%); z-index:90; background:#0d3b66; color:#fff; padding:10px 18px; border-radius:14px; display:flex; gap:10px; align-items:center; box-shadow:0 12px 36px rgba(0,0,0,.35); font-size:13.5px; max-width:calc(100vw - 32px); flex-wrap:wrap; justify-content:center; }
.bulk-bar[hidden] { display:none; }
.bulk-bar .btn { padding:7px 12px; font-size:12.5px; font-weight:700; border-radius:6px; }
.bulk-bar .btn.ghost { background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.3); }
.bulk-bar .btn.danger { background:#e63946; color:#fff; border-color:#e63946; }
.bulk-bar .btn.ok { background:#2a9d8f; color:#fff; border-color:#2a9d8f; }
.id-preview-modal { position:fixed; inset:0; z-index:100; background:rgba(10,20,40,.65); backdrop-filter:blur(4px); display:flex; align-items:center; justify-content:center; padding:16px; }
.id-preview-modal[hidden] { display:none; }
.id-preview-box { background:var(--card,#fff); color:var(--ink,#1e293b); border-radius:18px; max-width:780px; width:100%; padding:24px; max-height:92vh; overflow:auto; box-shadow:0 24px 60px rgba(0,0,0,.4); }
</style>

<!-- 4-TIER HIERARCHY CATEGORY NAV -->
<div class="tier-tabs" role="tablist">
  <a href="?tier=master_agents" class="tier-tab <?= $activeTier === 'master_agents' ? 'active' : '' ?>">
    <span>⭐ 3. Master Agents</span><span class="tier-badge"><?= count($masterAgents) ?></span>
  </a>
  <a href="?tier=sub_agents" class="tier-tab <?= $activeTier === 'sub_agents' ? 'active' : '' ?>">
    <span>👥 4. Agent Staff (Sub-Agents)</span><span class="tier-badge"><?= count($subAgents) ?></span>
  </a>
  <a href="?tier=staff" class="tier-tab <?= $activeTier === 'staff' ? 'active' : '' ?>">
    <span>👔 2. Company Staff</span><span class="tier-badge"><?= count($staffRows) ?></span>
  </a>
  <a href="?tier=pvt_ltd" class="tier-tab <?= $activeTier === 'pvt_ltd' ? 'active' : '' ?>">
    <span>🏢 1. Pvt Ltd &amp; Branches</span><span class="tier-badge">5 Offices</span>
  </a>
</div>

<?php if ($flash !== null): ?>
  <div class="flash <?= $flash[0] ?>"><?= Security::e($flash[1]) ?></div>
<?php endif; ?>

<?php if ($activeTier === 'master_agents' || $activeTier === 'sub_agents'): ?>
  <!-- KPIS FOR ACTIVE AGENTS TIER -->
  <div class="cards">
    <div class="card"><div class="k"><?= $activeTier === 'sub_agents' ? 'Agent Staff (Sub-Agents)' : 'Master Agents' ?></div><div class="v"><?= count($tableRows) ?></div><div class="muted" style="font-size:12px"><?= $orgs ?> organization · <?= $persons ?> person</div></div>
    <div class="card"><div class="k">Active Selling</div><div class="v"><?= $active ?></div></div>
    <div class="card"><div class="k">Pending Approval</div><div class="v"><?= $pending ?></div></div>
    <div class="card"><div class="k">Commission Due</div><div class="v">₹<?= number_format($dueSum) ?></div></div>
  </div>

  <!-- SEARCH & FILTER TOOLBAR -->
  <div class="dt-bar" id="agCtl">
    <input class="dt-q" type="search" placeholder="Search serial / name / mobile / SHG code / contact / city" aria-label="Search agents">
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
    <a class="btn ghost" href="agents.php?tier=<?= $activeTier ?>&amp;export=csv">⬇ CSV</a>
    <a class="btn ghost" href="agents.php?tier=<?= $activeTier ?>&amp;export=xlsx">⬇ Excel</a>
    <?php if ($canManage): ?><a class="btn" href="staff.php#addStaff">＋ New <?= $activeTier === 'sub_agents' ? 'Sub-Agent' : 'Agent' ?></a><?php endif; ?>
    <span class="dt-count"></span>
  </div>
  <p class="muted" style="margin:-4px 0 10px;font-size:12px">
    <?php if ($activeTier === 'master_agents'): ?>
      <b>Master Agents:</b> Authorized booking distributors. Serial bands: <b>Org 1–20</b> (next free <?= $nextOrg ?? 'none' ?>) · <b>Person 21+</b> (next free <?= $nextPerson ?? 'none' ?>). Click 👥 Sub-Agents to inspect counter staff.
    <?php else: ?>
      <b>Agent Staff (Sub-Agents):</b> Registered counter staff operating under parent Master Agents. Every sale is tracked to both staff and parent agent.
    <?php endif; ?>
  </p>

  <!-- AGENT REGISTER TABLE -->
  <div class="dt-wrap">
  <table class="dt no-card" data-controls="agCtl">
    <thead><tr>
      <th style="width:34px;text-align:center"><input type="checkbox" id="bulkAllAgents" title="Select all" style="width:16px;height:16px;cursor:pointer"></th>
      <th data-type="num">#</th><th>Type</th><th>Code</th><th>Name / Affiliation</th><th>Contact</th><th>WhatsApp</th>
      <th data-type="num">Wallet ₹</th><th data-type="num">Commission</th><th data-type="num">Sales</th><th>Status</th><th data-nosort>Actions</th>
    </tr></thead>
    <tbody>
    <?php if ($tableRows === []): ?>
      <tr><td colspan="12" class="muted" style="padding:24px;text-align:center">No <?= $activeTier === 'sub_agents' ? 'sub-agents' : 'master agents' ?> found in this category.</td></tr>
    <?php endif; ?>
    <?php foreach ($tableRows as $i => $r):
      $rid = 'a' . (int) $r['id'];
      $doc = trim((string) ($r['id_type'] ?? '') . ' ' . (string) ($r['id_number'] ?? ''));
      $isOrg = $r['kind'] === AgentWallet::KIND_ORG;
      $search = strtolower(implode(' ', [$r['serial'] ?? '', $r['full_name'], $r['contact_person'] ?? '', $r['phone'], $r['wa'], $r['code'], $r['counter_name'] ?? '', $r['email'] ?? '', $r['username'], AgentWallet::kindLabel($r['kind']), $r['parent_agent_name'] ?? '']));
      $waDigits = preg_replace('/\D/', '', $r['wa']);
      if ($waDigits !== '' && strlen($waDigits) === 10) { $waDigits = '91' . $waDigits; }
      $appRef = (string) ($r['app_ref'] ?: ('AGR-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT)));
    ?>
      <tr data-search="<?= $e($search) ?>" data-kind="<?= $e($r['kind']) ?>" data-status="<?= $e($r['status']) ?>" data-city="<?= $e($r['counter_name']) ?>" data-sold="<?= (int) $r['sales'] > 0 ? '1' : '0' ?>"<?= $r['status'] === 'inactive' ? ' style="opacity:.75"' : '' ?>>
        <td style="text-align:center"><input type="checkbox" class="agent-cb" value="<?= (int) $r['id'] ?>" data-name="<?= $e($r['full_name'] ?: $r['username']) ?>" style="width:16px;height:16px;cursor:pointer"></td>
        <td class="num" data-sort="<?= $r['serial'] === null ? 99999 : (int) $r['serial'] ?>"><?= $r['serial'] === null ? '<span class="muted">—</span>' : '<b>' . (int) $r['serial'] . '</b>' ?></td>
        <td data-sort="<?= $e($r['kind']) ?>"><?= $isOrg
              ? '<span class="pill" style="background:#e2ecfb;color:#1c3b72">🏢 Org</span>'
              : '<span class="pill" style="background:#d7f4e3;color:#0a6b3b">👤 Person</span>' ?></td>
        <td class="mono"><?= $r['code'] !== '' ? '<span class="pill" style="background:#efeaff;color:#5a3fb0;font-weight:700">' . $e($r['code']) . '</span>' : '<span class="muted">—</span>' ?></td>
        <td>
          <b><?= $e($r['full_name'] ?: $r['username']) ?></b>
          <?php if (!empty($r['parent_agent_name'])): ?>
            <div style="font-size:11.5px;color:#0d3b66;font-weight:600">🏢 Master: <?= $e($r['parent_agent_name']) ?></div>
          <?php elseif ((int)($r['subagent_count'] ?? 0) > 0): ?>
            <div><a href="agents.php?tier=sub_agents&amp;parent=<?= (int)$r['id'] ?>" class="pill" style="background:#e0f2fe;color:#0369a1;font-size:11px;text-decoration:none">👥 <?= (int)$r['subagent_count'] ?> Sub-agents &rarr;</a></div>
          <?php endif; ?>
          <?php if ($isOrg && !empty($r['contact_person'])): ?><div class="muted" style="font-size:11.5px">👤 <?= $e($r['contact_person']) ?></div><?php endif; ?>
          <?php if (!empty($r['counter_name'])): ?><div class="muted" style="font-size:11.5px">📍 <?= $e($r['counter_name']) ?></div><?php endif; ?>
        </td>
        <td class="mono"><?= $e($r['phone'] ?: $r['display_phone'] ?: '—') ?></td>
        <td class="mono"><?= $waDigits !== '' ? '<a href="https://wa.me/' . $e($waDigits) . '" target="_blank" rel="noopener" title="Open WhatsApp chat">💬 ' . $e($r['wa']) . '</a>' : '<span class="muted">—</span>' ?></td>
        <td class="num" data-sort="<?= $r['comm_due'] ?>"><b>₹<?= number_format($r['comm_due']) ?></b><div class="muted" style="font-size:11px">cash ₹<?= number_format($r['cash_bal']) ?></div></td>
        <td class="num" data-sort="<?= (float) $r['rate_sort'] ?>"><b><?= $e($r['rate']) ?></b><div class="muted" style="font-size:11px"><?= $e($r['rate_note']) ?></div></td>
        <td class="num" data-sort="<?= (int) $r['sales'] ?>"><b><?= (int) $r['sales'] ?></b><div class="muted" style="font-size:11px">₹<?= number_format((float) $r['revenue']) ?></div></td>
        <td data-sort="<?= $e($r['status']) ?>"><?php if ($r['status'] === 'active'): ?><span class="pill" style="background:#d7f4e3;color:#0a6b3b">Active</span><?php elseif ($r['status'] === 'pending'): ?><span class="pill" style="background:#fef3c7;color:#92400e">Pending</span><?php else: ?><span class="pill" style="background:#f7dcdc;color:#8a1f1f">Deactivated</span><?php endif; ?></td>
        <td><span class="dt-acts">
          <button type="button" class="btn ghost" onclick="openIdModal('<?= $e($appRef) ?>','<?= $e($r['full_name'] ?: $r['username']) ?>','<?= $isOrg ? 'Master Agent (Partner)' : 'Authorized Booking Agent' ?>','<?= $e($r['code']) ?>','<?= $e($r['phone']) ?>','<?= $e($waDigits) ?>','<?= $e($r['joined_on'] ?: substr((string)$r['created_at'],0,10)) ?>')" title="One-Tap ID Card &amp; Agreement Preview">🪪 ID Card</button>
          <button type="button" class="btn ghost" data-dt-toggle="<?= $rid ?>v">👁 View</button>
          <?php if ($canManage): ?><button type="button" class="btn ghost" data-dt-toggle="<?= $rid ?>e">✏️ Edit</button><?php endif; ?>
          <a class="btn ghost" href="agent.php?agent=<?= (int) $r['id'] ?>" title="Wallet, ledger, payouts">💼 Wallet</a>
          <a class="btn ghost" href="agent-sales.php?agent=<?= (int) $r['id'] ?>">📊 Sales</a>
          <a class="btn ghost" href="bookings.php?agent=<?= (int) $r['id'] ?>" title="Tickets sold">🎫 Tickets</a>
          <?php if ($canManage && (int) $r['id'] !== $selfId): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('<?= $r['status'] === 'active' ? 'Deactivate this agent?' : 'Activate this agent?' ?>')">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn <?= $r['status'] === 'active' ? 'danger' : 'ok' ?>" name="action" value="toggle"><?= $r['status'] === 'active' ? '⏸ Hold' : '▶ Activate' ?></button>
            </form>
          <?php endif; ?>
        </span></td>
      </tr>

      <!-- EXPAND VIEW ROW -->
      <tr class="dt-x" id="<?= $rid ?>v" hidden><td colspan="12">
        <div class="dt-grid">
          <div class="kv"><label>Serial · Type · Code</label><b><?= $r['serial'] === null ? 'not assigned' : '#' . (int) $r['serial'] ?> · <?= $e(AgentWallet::kindLabel($r['kind'])) ?> · <?= $e($r['code'] ?: '—') ?></b></div>
          <div class="kv"><label>Name · Username</label><b><?= $e($r['full_name']) ?> <span class="muted mono">· <?= $e($r['username']) ?></span></b></div>
          <div class="kv"><label>Sign-in Email</label><b><?= $r['email'] ? $e($r['email']) : '<span style="color:#b02a2a">not set</span>' ?></b></div>
          <?php if (!empty($r['parent_agent_name'])): ?><div class="kv"><label>Parent Master Agent</label><b>🏢 <?= $e($r['parent_agent_name']) ?></b></div><?php endif; ?>
          <div class="kv"><label>Mobile · WhatsApp</label><b><?= $e($r['phone']) ?> · <?= $e($r['wa'] ?: '—') ?></b></div>
          <div class="kv"><label>City / Counter · Address</label><b><?= $e($r['counter_name'] ?: '—') ?><br><?= $e($r['address'] ?: '—') ?></b></div>
          <div class="kv"><label>Document</label><b><?= $doc !== '' ? $e($doc) : '—' ?></b></div>
          <div class="kv"><label>Commission Rate</label><b><?= $e($r['rate']) ?> <span class="muted">(<?= $e($r['rate_note']) ?>)</span></b></div>
          <div class="kv"><label>Sales &amp; Revenue</label><b><?= (int) $r['sales'] ?> / <?= (int) $r['sales_all'] ?> sold · ₹<?= number_format((float) $r['revenue']) ?></b></div>
          <div class="kv"><label>Wallet Balances</label><b>Commission Due: ₹<?= number_format($r['comm_due']) ?> · Cash Held: ₹<?= number_format($r['cash_bal']) ?></b></div>
          <div class="kv"><label>Daily Quota · Cash Limit</label><b><?= (int) ($r['daily_booking_limit'] ?? 0) ? (int)$r['daily_booking_limit'] . ' seats/day' : 'Unlimited seats' ?> · ₹<?= number_format((float) ($r['cash_limit'] ?? 0)) ?></b></div>
          <div class="kv"><label>Joined Date</label><b><?= $e($r['joined_on'] ?: substr((string) $r['created_at'], 0, 10)) ?></b></div>
        </div>
        <div class="dt-acts" style="padding:6px 2px">
          <button type="button" class="btn" onclick="openIdModal('<?= $e($appRef) ?>','<?= $e($r['full_name'] ?: $r['username']) ?>','<?= $isOrg ? 'Master Agent (Partner)' : 'Authorized Booking Agent' ?>','<?= $e($r['code']) ?>','<?= $e($r['phone']) ?>','<?= $e($waDigits) ?>','<?= $e($r['joined_on'] ?: substr((string)$r['created_at'],0,10)) ?>')">🪪 ID Card &amp; Agreement Preview</button>
          <button type="button" class="btn ghost" onclick="openSettleModal(<?= (int)$r['id'] ?>,'<?= $e($r['full_name'] ?: $r['username']) ?>',<?= (float)$r['comm_due'] ?>,<?= (float)$r['cash_bal'] ?>)">💵 Record Wallet Settlement</button>
          <a class="btn ghost" href="agent-passengers.php?agent=<?= (int) $r['id'] ?>">🧑‍🤝‍🧑 Passengers</a>
          <a class="btn ghost" href="agent-offline.php?agent=<?= (int) $r['id'] ?>">🧾 Paper tickets</a>
        </div>
      </td></tr>

      <!-- EXPAND EDIT ROW -->
      <?php if ($canManage): ?>
      <tr class="dt-x" id="<?= $rid ?>e" hidden><td colspan="12">
        <form method="post">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <div class="dt-grid">
            <label>Type
              <select name="agent_kind">
                <option value="org" <?= $isOrg ? 'selected' : '' ?>>Organization (serial 1–20)</option>
                <option value="person" <?= !$isOrg ? 'selected' : '' ?>>Person (serial 21+)</option>
              </select></label>
            <label>Serial (<?= AgentWallet::AGENT_CODE_MIN ?>–<?= AgentWallet::AGENT_CODE_MAX ?>)<input name="agent_code" type="number" min="<?= AgentWallet::AGENT_CODE_MIN ?>" max="<?= AgentWallet::AGENT_CODE_MAX ?>" step="1" value="<?= $r['serial'] === null ? '' : (int) $r['serial'] ?>"></label>
            <label><?= $isOrg ? 'Organization Name' : 'Full Name' ?><input name="full_name" value="<?= $e($r['full_name']) ?>" required maxlength="120"></label>
            <label>Contact Person<input name="contact_person" value="<?= $e($r['contact_person']) ?>" maxlength="120"></label>
            <label>Mobile<input name="phone" value="<?= $e($r['phone']) ?>" required inputmode="numeric" maxlength="15"></label>
            <label>WhatsApp<input name="whatsapp" value="<?= $e($r['whatsapp']) ?>" inputmode="numeric" maxlength="15"></label>
            <label>Commission Tier
              <select name="agent_type">
                <option value="direct" <?= $r['tier'] === 'direct' ? 'selected' : '' ?>>Direct — ₹<?= number_format($rateDirect) ?> / pax</option>
                <option value="joint" <?= $r['tier'] === 'joint' ? 'selected' : '' ?>>Team / Org — ₹<?= number_format($rateJoint) ?> / pax</option>
              </select></label>
            <label>Daily Booking Quota (Seats)<input name="daily_booking_limit" type="number" min="0" value="<?= (int) ($r['daily_booking_limit'] ?? 0) ?>" placeholder="0 = unlimited"></label>
            <label>Cash Limit ₹<input name="cash_limit" type="number" min="0" step="1" value="<?= (int) ($r['cash_limit'] ?? 0) ?>"></label>
            <label>Email (Sign-in)<input name="email" type="email" value="<?= $e($r['email']) ?>" maxlength="191"></label>
            <label>City / Counter<input name="counter_name" value="<?= $e($r['counter_name']) ?>" maxlength="120"></label>
            <label>Address<input name="address" value="<?= $e($r['address']) ?>" maxlength="255"></label>
            <label>Document (Type &amp; No)<input name="id_type" value="<?= $e($r['id_type']) ?>" placeholder="Aadhaar / Nagrikta / PAN" style="margin-bottom:4px"><input name="id_number" value="<?= $e($r['id_number']) ?>" placeholder="ID Number"></label>
            <label>Notes<input name="notes" value="<?= $e($r['notes']) ?>" maxlength="500"></label>
          </div>
          <div class="dt-acts" style="padding:6px 2px">
            <button class="btn" type="submit">💾 Save Changes</button>
            <button class="btn ghost" type="button" data-dt-toggle="<?= $rid ?>e">Cancel</button>
          </div>
        </form>
      </td></tr>
      <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <!-- BULK AGENT ACTION BAR -->
  <div class="bulk-bar" id="bulkAgentBar" hidden>
    <span><b id="bulkAgentN">0</b> agent(s) selected</span>
    <button type="button" class="btn ghost" id="bulkAgentClear">Clear</button>
    <button type="button" class="btn ok" id="bulkApproveBtn">✓ Approve Selected</button>
    <button type="button" class="btn danger" id="bulkPauseBtn">⏸ Pause Booking Access</button>
    <button type="button" class="btn ghost" id="bulkQuotaBtn">⚡ Update Daily Quota</button>
  </div>

<?php elseif ($activeTier === 'staff'): ?>
  <!-- CATEGORY 2: COMPANY STAFF MANAGEMENT -->
  <div class="cards">
    <div class="card"><div class="k">Total Staff</div><div class="v"><?= count($staffRows) ?></div><div class="muted" style="font-size:12px">Office, Drivers &amp; Bus Staff</div></div>
    <div class="card"><div class="k">On Duty Now</div><div class="v"><?= count(array_filter($staffRows, static fn($s) => ($s['duty_status'] ?? 'on_duty') === 'on_duty')) ?></div></div>
    <div class="card"><div class="k">Active Drivers</div><div class="v"><?= count(array_filter($staffRows, static fn($s) => $s['role'] === 'driver')) ?></div></div>
    <div class="card"><div class="k">Monthly Payroll</div><div class="v">₹<?= number_format(array_sum(array_map(static fn($s) => (float)($s['monthly_salary'] ?? 0), $staffRows))) ?></div></div>
  </div>

  <div class="dt-wrap" style="margin-top:16px">
  <table class="dt no-card">
    <thead><tr>
      <th>#</th><th>Staff Name</th><th>Role</th><th>Mobile / WhatsApp</th><th>Assigned Bus</th><th>Monthly Salary</th><th>Duty Status</th><th>Staff ID Card</th>
    </tr></thead>
    <tbody>
    <?php if ($staffRows === []): ?>
      <tr><td colspan="8" class="muted" style="padding:22px;text-align:center">No internal company staff accounts found.</td></tr>
    <?php endif; ?>
    <?php foreach ($staffRows as $st):
      $sRef = (string) ($st['app_ref'] ?: ('STF-' . str_pad((string) $st['id'], 6, '0', STR_PAD_LEFT)));
      $waDigits = preg_replace('/\D/', '', (string) ($st['whatsapp'] ?: $st['phone']));
      if ($waDigits !== '' && strlen($waDigits) === 10) { $waDigits = '91' . $waDigits; }
    ?>
      <tr>
        <td class="num"><b>#<?= (int)$st['id'] ?></b></td>
        <td><b><?= $e($st['full_name'] ?: $st['username']) ?></b><div class="muted" style="font-size:11px">@<?= $e($st['username']) ?></div></td>
        <td><span class="pill" style="background:#efeaff;color:#5a3fb0;text-transform:capitalize"><?= $e($st['role']) ?></span></td>
        <td class="mono"><?= $e($st['phone']) ?><?= $waDigits !== '' ? ' · <a href="https://wa.me/' . $e($waDigits) . '" target="_blank" rel="noopener">💬 WA</a>' : '' ?></td>
        <td><?= !empty($st['assigned_bus']) ? '<span class="pill" style="background:#e0f2fe;color:#0369a1">🚌 ' . $e($st['assigned_bus']) . '</span>' : '<span class="muted">Unassigned</span>' ?></td>
        <td class="num"><b>₹<?= number_format((float)($st['monthly_salary'] ?? 0)) ?></b></td>
        <td>
          <form method="post" style="display:inline">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="duty_status"><input type="hidden" name="staff_id" value="<?= (int)$st['id'] ?>">
            <select name="duty_status" onchange="this.form.submit()" style="padding:3px 6px;border-radius:6px;font-size:12px;font-weight:600;<?= ($st['duty_status'] ?? 'on_duty') === 'on_duty' ? 'background:#d7f4e3;color:#0a6b3b' : (($st['duty_status'] ?? '') === 'on_leave' ? 'background:#fee2e2;color:#991b1b' : 'background:#f1f5f9;color:#475569') ?>">
              <option value="on_duty" <?= ($st['duty_status'] ?? 'on_duty') === 'on_duty' ? 'selected' : '' ?>>🟢 On Duty</option>
              <option value="off_duty" <?= ($st['duty_status'] ?? '') === 'off_duty' ? 'selected' : '' ?>>⚪ Off Duty</option>
              <option value="on_leave" <?= ($st['duty_status'] ?? '') === 'on_leave' ? 'selected' : '' ?>>🔴 On Leave</option>
            </select>
          </form>
        </td>
        <td>
          <button type="button" class="btn ghost" onclick="openIdModal('<?= $e($sRef) ?>','<?= $e($st['full_name'] ?: $st['username']) ?>','<?= ucfirst($st['role']) ?> - S Hari Global','SHG-STF-<?= str_pad((string)$st['id'],4,'0',STR_PAD_LEFT) ?>','<?= $e($st['phone']) ?>','<?= $e($waDigits) ?>','<?= substr((string)$st['created_at'],0,10) ?>')">🪪 View Staff ID</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

<?php elseif ($activeTier === 'pvt_ltd'): ?>
  <!-- CATEGORY 1: PVT LTD & BRANCH CONTROL -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-top:10px">

    <!-- CORPORATE & BANK SETTINGS -->
    <div style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px">
      <h3 style="margin:0 0 12px;font-size:16px;color:#0d3b66;display:flex;align-items:center;gap:8px">🏢 Corporate Profile &amp; Banking</h3>
      <form method="post">
        <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="save_pvt_ltd">
        <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
          <label>Company Legal Name<input name="legal_name" value="<?= $e($pvtLtd['legal_name'] ?? 'S Hari Global Pvt Ltd') ?>" required></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <label>CIN Number<input name="cin_number" value="<?= $e($pvtLtd['cin'] ?? '') ?>"></label>
            <label>GST Number<input name="gst_number" value="<?= $e($pvtLtd['gst'] ?? '') ?>"></label>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
            <label>PAN Number<input name="pan_number" value="<?= $e($pvtLtd['pan'] ?? '') ?>"></label>
            <label>Company UPI ID<input name="upi_id" value="<?= $e($pvtLtd['upi'] ?? '') ?>"></label>
          </div>
          <label>Bank Name<input name="bank_name" value="<?= $e($pvtLtd['bank_name'] ?? 'State Bank of India') ?>"></label>
          <div style="display:grid;grid-template-columns:1.5fr 1fr;gap:8px">
            <label>Bank Account No.<input name="bank_acc" value="<?= $e($pvtLtd['bank_acc'] ?? '') ?>"></label>
            <label>IFSC Code<input name="bank_ifsc" value="<?= $e($pvtLtd['bank_ifsc'] ?? '') ?>"></label>
          </div>
          <label>Broadcast Notice / Official Policy Announcement
            <textarea name="announcement" rows="3" style="width:100%;padding:8px;border-radius:6px;border:1px solid var(--line)"><?= $e($pvtLtd['announcement'] ?? '') ?></textarea>
          </label>
          <button class="btn" type="submit" style="margin-top:6px">💾 Save Corporate Details</button>
        </div>
      </form>
    </div>

    <!-- 5 OFFICIAL BRANCH OFFICES -->
    <div style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:12px;padding:18px">
      <h3 style="margin:0 0 12px;font-size:16px;color:#0d3b66;display:flex;align-items:center;gap:8px">📍 Head Office &amp; 4 Branch Offices</h3>
      <div style="display:flex;flex-direction:column;gap:12px">
        <?php foreach ($branches as $bKey => $br): ?>
          <div style="padding:12px;background:var(--hover,#f8fafc);border:1px solid var(--line,#e2e8f0);border-radius:8px">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
              <b style="color:#0d3b66"><?= $e($br['name'] ?? $bKey) ?></b>
              <span class="pill" style="background:#e0f2fe;color:#0369a1;font-size:11px"><?= $e($br['city'] ?? '') ?></span>
            </div>
            <div style="font-size:12px;color:var(--mut);margin-bottom:4px">👤 Manager: <b><?= $e($br['manager'] ?? '—') ?></b> · 📞 <?= $e($br['phone'] ?? '—') ?></div>
            <div style="font-size:12px;color:#475569">🏢 <?= $e($br['address'] ?? '—') ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
<?php endif; ?>

<!-- ONE-TAP ID CARD & AGREEMENT PREVIEW MODAL -->
<div class="id-preview-modal" id="idModal" hidden onclick="if(event.target===this)closeIdModal()">
  <div class="id-preview-box">
    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:16px">
      <div>
        <h3 style="margin:0;font-size:18px;color:#0d3b66" id="mName">Official ID Card &amp; Agreement</h3>
        <div style="font-size:12px;color:var(--mut)" id="mRole">S Hari Global Pvt Ltd Official Credentials</div>
      </div>
      <button type="button" class="btn ghost" onclick="closeIdModal()" style="font-size:18px;padding:4px 10px">&times;</button>
    </div>

    <!-- PREVIEW CAROUSEL / GRID -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;text-align:center">
      <div style="background:#f8fafc;padding:12px;border-radius:12px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12.5px;color:#0d3b66;margin-bottom:8px">🪪 ID Card (Front)</div>
        <img id="mImgFront" src="" alt="Front ID" style="max-width:100%;max-height:280px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
      </div>
      <div style="background:#f8fafc;padding:12px;border-radius:12px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12.5px;color:#0d3b66;margin-bottom:8px">🪪 ID Card (Back)</div>
        <img id="mImgBack" src="" alt="Back ID" style="max-width:100%;max-height:280px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
      </div>
      <div style="background:#f8fafc;padding:12px;border-radius:12px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12.5px;color:#0d3b66;margin-bottom:8px">💼 Digital Visiting Card</div>
        <img id="mImgVisiting" src="" alt="Visiting Card" style="max-width:100%;max-height:280px;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,0.15)">
      </div>
    </div>

    <div style="margin-top:18px;padding:12px 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div style="font-size:12.5px;color:#166534">
        <b>✓ Verified Credentials:</b> Authentic S Hari Global Identity with official Verification QR &amp; signed agreement.
      </div>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a id="mBtnPdf" href="" target="_blank" class="btn" style="background:#0d3b66;color:#fff">⬇ Download ID Card PDF</a>
        <a id="mBtnVisPdf" href="" target="_blank" class="btn ghost">⬇ Visiting Card PDF</a>
        <a id="mBtnWa" href="" target="_blank" class="btn ok" style="background:#25d366;color:#fff">💬 Send on WhatsApp</a>
      </div>
    </div>
  </div>
</div>

<!-- WALLET SETTLEMENT MODAL -->
<div class="id-preview-modal" id="settleModal" hidden onclick="if(event.target===this)closeSettleModal()">
  <div class="id-preview-box" style="max-width:480px">
    <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:14px">
      <h3 style="margin:0;font-size:17px;color:#0d3b66">💵 Record Wallet Settlement</h3>
      <button type="button" class="btn ghost" onclick="closeSettleModal()">&times;</button>
    </div>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>"><input type="hidden" name="action" value="record_settlement"><input type="hidden" name="agent_id" id="settleAgentId" value="">
      <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
        <div>Agent: <b id="settleAgentName">—</b></div>
        <div>Current Commission Due: <b id="settleCommDue" style="color:#0a6b3b">₹0</b> · Cash Held: <b id="settleCashBal" style="color:#b02a2a">₹0</b></div>
        <label>Settlement Account
          <select name="account_type" style="width:100%;padding:8px;margin-top:4px;border-radius:6px;border:1px solid var(--line)">
            <option value="commission">Commission Payout (Deducts from commission due)</option>
            <option value="cash">Cash Collection Received (Reduces cash held by agent)</option>
          </select>
        </label>
        <label>Settlement Amount (₹)
          <input type="number" step="0.01" min="1" name="amount" required style="width:100%;padding:8px;margin-top:4px;border-radius:6px;border:1px solid var(--line)">
        </label>
        <label>Payment Mode / Reference / Note
          <input type="text" name="settle_note" placeholder="e.g. Bank Transfer UTR #... or Cash Received by Head Office" required style="width:100%;padding:8px;margin-top:4px;border-radius:6px;border:1px solid var(--line)">
        </label>
        <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px">
          <button type="button" class="btn ghost" onclick="closeSettleModal()">Cancel</button>
          <button type="submit" class="btn ok">✓ Record Settlement</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
function openIdModal(ref, name, role, code, phone, waDigits, date) {
  document.getElementById('mName').textContent = name + (code ? ' (' + code + ')' : '');
  document.getElementById('mRole').textContent = role + ' · Joined: ' + (date || 'Active');
  document.getElementById('mImgFront').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&side=front';
  document.getElementById('mImgBack').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&side=back';
  document.getElementById('mImgVisiting').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&visiting=1&side=front';
  document.getElementById('mBtnPdf').href = 'id-card.php?ref=' + encodeURIComponent(ref) + '&pdf=1';
  document.getElementById('mBtnVisPdf').href = 'id-card.php?ref=' + encodeURIComponent(ref) + '&visiting=1&pdf=1';
  var waMsg = "Namaste " + name + "! S Hari Global Pvt Ltd official ID Card & visiting credentials:\nID: " + code + "\nVerify URL: https://www.shreehariglobal.in/verify-id.php?c=" + ref;
  document.getElementById('mBtnWa').href = 'https://wa.me/' + (waDigits || phone) + '?text=' + encodeURIComponent(waMsg);
  document.getElementById('idModal').hidden = false;
}
function closeIdModal() { document.getElementById('idModal').hidden = true; }

function openSettleModal(id, name, comm, cash) {
  document.getElementById('settleAgentId').value = id;
  document.getElementById('settleAgentName').textContent = name;
  document.getElementById('settleCommDue').textContent = '₹' + Number(comm).toLocaleString('en-IN');
  document.getElementById('settleCashBal').textContent = '₹' + Number(cash).toLocaleString('en-IN');
  document.getElementById('settleModal').hidden = false;
}
function closeSettleModal() { document.getElementById('settleModal').hidden = true; }

// BULK CHECKBOX LOGIC
(function() {
  var allCb = document.getElementById('bulkAllAgents');
  var bar = document.getElementById('bulkAgentBar');
  var countEl = document.getElementById('bulkAgentN');
  if (!allCb || !bar) return;
  function getCbs() { return Array.prototype.slice.call(document.querySelectorAll('.agent-cb')); }
  function picked() { return getCbs().filter(function(c) { return c.checked; }); }
  function updateBar() {
    var p = picked();
    bar.hidden = p.length === 0;
    if (countEl) countEl.textContent = p.length;
  }
  allCb.onchange = function() {
    getCbs().forEach(function(c) { c.checked = allCb.checked; });
    updateBar();
  };
  document.addEventListener('change', function(e) {
    if (e.target.classList.contains('agent-cb')) updateBar();
  });
  document.getElementById('bulkAgentClear').onclick = function() {
    allCb.checked = false;
    getCbs().forEach(function(c) { c.checked = false; });
    updateBar();
  };
  document.getElementById('bulkApproveBtn').onclick = function() {
    var p = picked();
    if (!p.length || !confirm('Approve and activate ' + p.length + ' selected agent(s)?')) return;
    submitBulkForm('bulk_approve', p.map(function(c){return c.value;}));
  };
  document.getElementById('bulkPauseBtn').onclick = function() {
    var p = picked();
    if (!p.length || !confirm('Pause booking access for ' + p.length + ' selected agent(s)?')) return;
    submitBulkForm('bulk_pause', p.map(function(c){return c.value;}));
  };
  document.getElementById('bulkQuotaBtn').onclick = function() {
    var p = picked();
    if (!p.length) return;
    var q = prompt('Set daily booking limit (seats/day) for ' + p.length + ' selected agent(s):\n(Enter 0 for unlimited):', '20');
    if (q === null) return;
    submitBulkForm('bulk_quota', p.map(function(c){return c.value;}), { daily_quota: q });
  };
  function submitBulkForm(action, ids, extra) {
    var f = document.createElement('form');
    f.method = 'POST'; f.style.display = 'none';
    var k = document.createElement('input'); k.name = <?= json_encode($k) ?>; k.value = <?= json_encode($csrf) ?>; f.appendChild(k);
    var a = document.createElement('input'); a.name = 'action'; a.value = action; f.appendChild(a);
    ids.forEach(function(id) {
      var inp = document.createElement('input'); inp.name = 'ids[]'; inp.value = id; f.appendChild(inp);
    });
    if (extra) {
      for (var key in extra) {
        var x = document.createElement('input'); x.name = key; x.value = extra[key]; f.appendChild(x);
      }
    }
    document.body.appendChild(f);
    f.submit();
  }
})();
</script>
<?php admin_footer(); ?>
