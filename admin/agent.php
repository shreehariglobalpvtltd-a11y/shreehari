<?php
/**
 * admin/agent.php — the Agent Portal: profile, wallet and sales.
 *
 * Everything is scoped to the signed-in agent (bookings.sold_by_admin_id
 * and agent_ledger.agent_admin_id), so one agent never sees another's
 * money. A superadmin / manager may open any agent's board with
 * ?agent=<id> for supervision — that is the only way to view someone
 * else's figures, and the only place payouts and cash handovers can be
 * recorded.
 *
 * The commission shown here is READ FROM THE LEDGER, not recomputed from
 * a percentage. That is the whole point: a number recomputed on each page
 * load can be shown but never paid, and silently rewrites its own history
 * the day someone edits the rate. See includes/agentwallet.php.
 *
 * Gated on bookings.view, which ticketing agents hold (they deliberately
 * do NOT hold dashboard.view, so the finance dashboard stays out of reach).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
require_once INCLUDE_PATH . '/agenttargets.php';
// Office viewers need the commissions permission — an agent sees their own
// dashboard, but counter/support roles must not read other sellers' money.
if (!Auth::isCounterAgent()) { Auth::requireAdmin('commissions.view'); }

$isSupervisor = Auth::can('dashboard.view');       // superadmin / manager
$canSettle    = Auth::can('commissions.pay');      // may pay out / accept cash
$canManage    = Auth::can('staff.manage');         // may set the commission rate
$selfId       = (int) ($admin['id'] ?? 0);
$viewId       = $selfId;
$base         = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)
$flash        = null;

if ($isSupervisor && isset($_GET['agent']) && (int) $_GET['agent'] > 0) {
    $viewId = (int) $_GET['agent'];
}
// An agent's own-book scope wins even if a custom permission grants dashboard access.
$agentScopeId = Auth::bookingScopeAdminId();
if ($agentScopeId !== null) { $viewId = $agentScopeId; }

/* ---- Actions ------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            $action  = (string) ($_POST['action'] ?? '');
            $targetId = (int) ($_POST['agent_id'] ?? 0) ?: $viewId;

            if ($action === 'profile') {
                // An agent may keep their own contact and payout details
                // current; only staff.manage may touch the money settings,
                // or an agent could quietly raise their own commission.
                $own = $targetId === $selfId;
                if (!$own && !$canManage) {
                    throw new RuntimeException('You may only edit your own profile.');
                }

                $fields = [
                    'display_phone'  => $_POST['display_phone']  ?? '',
                    'whatsapp'       => $_POST['whatsapp']       ?? '',
                    'display_email'  => $_POST['display_email']  ?? '',
                    'counter_name'   => $_POST['counter_name']   ?? '',
                    'address'        => $_POST['address']        ?? '',
                    'payout_method'  => $_POST['payout_method']  ?? '',
                    'payout_account' => $_POST['payout_account'] ?? '',
                ];
                if ($canManage) {
                    // Agent kind (organization 1-20 / person 21+) and the
                    // contact person are office-set, like the ID document.
                    if (isset($_POST['agent_kind']))     { $fields['agent_kind']     = (string) $_POST['agent_kind']; }
                    if (isset($_POST['contact_person'])) { $fields['contact_person'] = (string) $_POST['contact_person']; }
                    $fields['commission_percent'] = $_POST['commission_percent'] ?? '';
                    $fields['cash_limit']         = $_POST['cash_limit'] ?? 0;
                    $fields['joined_on']          = $_POST['joined_on'] ?? '';
                    $fields['notes']              = $_POST['notes'] ?? '';
                    // Identity and selling limits are issued by the office. An
                    // agent editing their own ID number or raising their own
                    // daily cap would defeat the point of having either.
                    $fields['id_type']             = $_POST['id_type'] ?? '';
                    $fields['id_number']           = $_POST['id_number'] ?? '';
                    $fields['daily_booking_limit'] = $_POST['daily_booking_limit'] ?? 0;
                }

                // A photo is the one identity field an agent may set for
                // themselves — it is their own face on their own counter.
                if (isset($_FILES['photo']) && (int) ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    $fields['photo_path'] = AgentWallet::savePhoto($targetId, $_FILES['photo']);
                }

                AgentWallet::saveProfile($targetId, $fields);

                // Salary lives in a setting, not on admin_profiles, so it is
                // saved beside the profile rather than through it. Only when
                // the form actually carried the control — otherwise opening
                // this page from a form without it would wipe the salary.
                if ($canManage && isset($_POST['monthly_salary'])) {
                    AgentWallet::setSalary($targetId, (float) $_POST['monthly_salary'], $selfId);
                }
                if ($canManage && isset($_POST['deposit_required'])) {
                    AgentWallet::setDepositRequired($targetId, (float) $_POST['deposit_required'], $selfId);
                }
                // Commission tier (direct ₹200 / team-organisation ₹400 per
                // passenger) also lives in a setting rather than on
                // admin_profiles, so it saves beside the profile — and only when
                // the form really carried the control, or opening this page from
                // a form without it would silently demote a team agent.
                if ($canManage && isset($_POST['agent_type'])) {
                    AgentWallet::setAgentType($targetId, (string) $_POST['agent_type'], $selfId);
                }
                // The agent's printed number (1-1000). Same isset() guard, and
                // setAgentCode() throws on a duplicate — surfaced as a normal
                // form error rather than silently giving two agents one number.
                if ($canManage && isset($_POST['agent_code'])) {
                    $rawCode = trim((string) $_POST['agent_code']);
                    AgentWallet::setAgentCode($targetId, $rawCode === '' ? null : (int) $rawCode, $selfId);
                }
                // Per-agent commission override (their own flat ₹ per passenger,
                // or forced onto the percent engine). Lives in a settings map,
                // not on admin_profiles, so it saves beside the profile — same
                // isset() guard as the tier above, and setCommissionOverride()
                // validates the mode and the 0..10000 amount.
                if ($canManage && isset($_POST['commission_override_mode'])) {
                    $ovMode = trim((string) $_POST['commission_override_mode']);
                    $ovFlat = trim((string) ($_POST['commission_override_flat'] ?? ''));
                    AgentWallet::setCommissionOverride(
                        $targetId,
                        $ovMode === '' ? null : $ovMode,
                        ($ovMode === 'flat' && $ovFlat !== '') ? (float) $ovFlat : null,
                        $selfId
                    );
                }

                // Route permissions live in their own table, so they are saved
                // alongside rather than through saveProfile(). Only written when
                // the form actually carried the control, or opening the profile
                // from a form without it would silently clear the restriction.
                if ($canManage && isset($_POST['routes_present'])) {
                    AgentWallet::setRoutePermissions(
                        $targetId,
                        is_array($_POST['routes'] ?? null) ? $_POST['routes'] : [],
                        $selfId
                    );
                }

                $flash = ['ok', 'Profile saved.'];
                $viewId = $targetId;

            } elseif ($action === 'credentials') {
                /* Login credentials (4 Sep 2026) — the office owns the agent's
                   email, username and password, because that is now the whole
                   agent sign-in (no OTP, no WhatsApp in the loop). Gated on
                   staff.manage so an agent can never rename or re-mail their
                   own account; Auth::setStaffCredentials validates, enforces
                   uniqueness and writes the audit row. */
                if (!$canManage) {
                    throw new RuntimeException('Only a super-admin (or staff manager) can change login credentials.');
                }
                // 5 Sep 2026: staff.manage is held by managers too — a manager
                // must never be able to rewrite a super-admin's sign-in and
                // take the account over. Same wall as staff.php setcreds.
                $targetRole = (string) Database::scalar(
                    'SELECT role FROM admins WHERE id = :id', ['id' => $targetId], ''
                );
                if ($targetRole === 'superadmin' && $targetId !== $selfId) {
                    throw new RuntimeException('A super-admin\'s sign-in details cannot be changed from this panel.');
                }
                $fields = [
                    'username'    => (string) ($_POST['login_username'] ?? ''),
                    'email'       => (string) ($_POST['login_email'] ?? ''),
                    'password'    => (string) ($_POST['login_password'] ?? ''),
                    'must_change' => !empty($_POST['must_change']),
                ];
                $res = Auth::setStaffCredentials($targetId, $fields, $selfId);
                $viewId = $targetId;

                if ($res['changed'] === []) {
                    $flash = ['ok', 'Nothing to change — those are already the details on file.'];
                } else {
                    $pwSet = in_array('password', $res['changed'], true);
                    $note  = 'Login details saved: ' . implode(', ', array_map(
                        static fn(string $c): string => match ($c) {
                            'username'         => 'username',
                            'email'            => 'email',
                            'password'         => 'new password',
                            'must_change_on'   => 'must change at next login',
                            'must_change_off'  => 'forced change cleared',
                            default            => $c,
                        }, $res['changed'])) . '.';

                    // Tell the agent their new sign-in details, best effort —
                    // the password only ever travels to their own registered
                    // number, and never into the audit log or this page.
                    if (!empty($_POST['notify_agent'])) {
                        try {
                            $ag = Database::fetch('SELECT full_name, phone FROM admins WHERE id = :id', ['id' => $targetId]);
                            $to = normalisePhone((string) ($ag['phone'] ?? ''));
                            if ($to !== '') {
                                Notify::whatsapp($to,
                                    Settings::getString('company_name', APP_NAME) . " — agent login details\n"
                                    . 'Portal: ' . appUrl('admin/login.php?portal=agent') . "\n"
                                    . 'Email: ' . ($res['email'] !== '' ? $res['email'] : '(not set)') . "\n"
                                    . 'Username: ' . $res['username'] . "\n"
                                    . ($pwSet ? "Password: " . (string) $_POST['login_password'] . "\n" : '')
                                    . 'Sign in with all three. Never share this message.');
                                $note .= ' Sent to the agent on WhatsApp.';
                            } else {
                                $note .= ' No mobile number on file, so nothing was sent.';
                            }
                        } catch (Throwable $e) {
                            Logger::error('Agent credential notify failed', ['e' => $e->getMessage()]);
                            $note .= ' The WhatsApp message could not be sent.';
                        }
                    }
                    $flash = ['ok', $note];
                }

            } elseif ($action === 'payout' || $action === 'cash_handover') {
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to settle agent accounts.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must settle your own account.');
                }

                AgentWallet::record($targetId, $action, (float) ($_POST['amount'] ?? 0), [
                    'note' => $_POST['note'] ?? '',
                    'ref'  => $_POST['ref'] ?? '',
                    'by'   => $selfId,
                ]);

                $flash = ['ok', $action === 'payout'
                    ? 'Commission payout recorded.'
                    : 'Cash handover recorded.'];
                $viewId = $targetId;

            } elseif ($action === 'payout_request') {
                // Self-serve (3 Sep 2026): a counter agent asks the office to pay out
                // their commission balance. Nothing moves here — the request is an
                // audit row + a WhatsApp to the office; a supervisor still records
                // the actual payout (above), which is what clears the balance and
                // the request.
                if (!Auth::isCounterAgent() || $targetId !== $selfId) {
                    throw new RuntimeException('Only an agent can request their own payout.');
                }
                $bal    = AgentWallet::balances($selfId);
                $due    = (float) ($bal['commission'] ?? 0);
                $amount = round((float) ($_POST['amount'] ?? 0), 2);
                if ($amount <= 0) { $amount = $due; }
                if ($due <= 0 || $amount > $due + 0.009) {
                    throw new RuntimeException('You can request up to your commission due (' . inr($due) . ').');
                }
                $pendingReq = Database::fetch(
                    "SELECT id, created_at FROM audit_logs
                      WHERE action = 'agent.payout_request' AND entity_type = 'admin' AND entity_id = :a
                        AND created_at > COALESCE((SELECT MAX(created_at) FROM agent_ledger WHERE agent_admin_id = :b AND entry_type = 'payout'), '1970-01-01')
                      ORDER BY id DESC LIMIT 1",
                    ['a' => (string) $selfId, 'b' => $selfId]
                );
                if ($pendingReq !== null) {
                    throw new RuntimeException('Your payout request from ' . formatDate(substr((string) $pendingReq['created_at'], 0, 10)) . ' is still with the office.');
                }
                $reqNote = Security::clean((string) ($_POST['note'] ?? ''), 200);
                Logger::audit('agent.payout_request', 'admin', (string) $selfId, null,
                    ['amount' => $amount, 'due' => $due, 'note' => $reqNote], 'Agent requested a commission payout');
                try {
                    $office = Settings::getString('admin_whatsapp', Settings::officePhone());
                    if ($office !== '') {
                        Notify::whatsapp($office, "💸 Payout request\n" . (string) ($admin['full_name'] ?? ($admin['username'] ?? 'Agent'))
                            . ' (' . AgentWallet::agentCodeLabel($selfId) . ') asks for ' . inr($amount) . ' of ' . inr($due) . ' commission due.'
                            . ($reqNote !== '' ? "\nNote: " . $reqNote : '')
                            . "\nSettle: " . appUrl('admin/agent.php?agent=' . $selfId));
                    }
                } catch (Throwable $e) {
                    Logger::error('payout_request notify failed', ['e' => $e->getMessage()]);
                }
                $flash = ['ok', 'Payout request for ' . inr($amount) . ' sent to the office.'];

            } elseif ($action === 'set_deposit_required') {
                if (!$canManage) { throw new RuntimeException('You are not allowed to set deposits.'); }
                AgentWallet::setDepositRequired($targetId, (float) ($_POST['deposit_required'] ?? 0), $selfId);
                $flash = ['ok', 'Required deposit saved.'];
                $viewId = $targetId;

            } elseif ($action === 'record_deposit') {
                if (!$canSettle) { throw new RuntimeException('You are not allowed to record deposits.'); }
                if ($targetId === $selfId && !Auth::isSuperadmin()) { throw new RuntimeException('Someone else must record your own deposit.'); }
                $paid = AgentWallet::recordDeposit($targetId, (float) ($_POST['deposit_amount'] ?? 0), $selfId);
                $flash = ['ok', 'Deposit updated — company now holds ' . inr($paid) . ' from this agent.'];
                $viewId = $targetId;

            } elseif ($action === 'post_salary') {
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to post salaries.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must post your own salary.');
                }
                $paid = AgentWallet::postSalary($targetId, (string) ($_POST['salary_month'] ?? ''), $selfId);
                $flash = ['ok', 'Salary ' . inr($paid) . ' credited — pay it out from the commission balance below.'];
                $viewId = $targetId;

            } elseif ($action === 'advance') {
                // Advance against future commission (Point 7). Same settle-desk
                // right as a payout; nobody may advance to their own account.
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to settle agent accounts.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must record your own advance.');
                }
                $advKind = (($_POST['advance_kind'] ?? 'given') === 'repaid') ? 'repaid' : 'given';
                $advAmt  = abs((float) ($_POST['amount'] ?? 0));
                AgentWallet::recordAdvance($targetId, $advKind === 'repaid' ? -$advAmt : $advAmt, [
                    'note' => $_POST['note'] ?? '',
                    'ref'  => $_POST['ref'] ?? '',
                    'by'   => $selfId,
                ]);
                $flash = ['ok', $advKind === 'repaid'
                    ? 'Advance repayment recorded — commission balance updated.'
                    : 'Advance recorded — it will auto-deduct from this agent’s future commission until settled.'];
                $viewId = $targetId;

            } elseif ($action === 'tier_rates') {
                // The company-wide ₹200/₹400 tier amounts — every agent on the
                // company tier is paid from these, so staff.manage only.
                if (!$canManage) {
                    throw new RuntimeException('You are not allowed to change the tier rates.');
                }
                AgentWallet::setFlatRates(
                    (int) ($_POST['rate_direct'] ?? -1),
                    (int) ($_POST['rate_joint'] ?? -1),
                    $selfId
                );
                $flash = ['ok', 'Tier rates saved — direct ' . inr((float) (int) $_POST['rate_direct'])
                              . ' / team ' . inr((float) (int) $_POST['rate_joint']) . ' per passenger.'];
                $viewId = $targetId;

            } elseif ($action === 'add_subagent') {
                $subName  = trim(Security::clean($_POST['sub_name'] ?? '', 120));
                $subPhone = preg_replace('/\D/', '', (string) ($_POST['sub_phone'] ?? ''));
                $subPin   = trim((string) ($_POST['sub_pin'] ?? ''));
                if (mb_strlen($subName) < 2) {
                    throw new RuntimeException('Enter staff full name.');
                } elseif (strlen($subPhone) < 10) {
                    throw new RuntimeException('Enter valid 10-digit mobile number.');
                } elseif (mb_strlen($subPin) < 6) {
                    throw new RuntimeException('PIN / password must be at least 6 characters.');
                } else {
                    $subUser = substr($subPhone, -10);
                    $baseU   = $subUser;
                    for ($i = 2; Database::exists('SELECT 1 FROM admins WHERE username = :u LIMIT 1', ['u' => $subUser]); $i++) {
                        $subUser = $baseU . '_' . $i;
                    }
                    $newSubId = (int) Database::insert('admins', [
                        'username'       => $subUser,
                        'password_hash'  => Security::hashPassword($subPin),
                        'full_name'      => $subName,
                        'email'          => null,
                        'phone'          => '+91' . substr($subPhone, -10),
                        'role'           => 'agent',
                        'is_active'      => 1,
                        'must_change_pw' => 0
                    ]);
                    Database::run(
                        "INSERT INTO admin_profiles (admin_id, display_phone, whatsapp, counter_name, agent_kind, parent_agent_id)
                         VALUES (:aid, :p, :wa, :cn, 'person', :pid)
                         ON DUPLICATE KEY UPDATE display_phone = :p2, whatsapp = :wa2, counter_name = :cn2, parent_agent_id = :pid2",
                        [
                            'aid' => $newSubId, 'p' => $subPhone, 'wa' => $subPhone,
                            'cn'  => $profile['counter_name'] ?? 'Counter Staff', 'pid' => $viewId,
                            'p2'  => $subPhone, 'wa2' => $subPhone,
                            'cn2' => $profile['counter_name'] ?? 'Counter Staff', 'pid2' => $viewId
                        ]
                    );
                    $code = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_PERSON);
                    if ($code !== null) {
                        AgentWallet::setAgentCode($newSubId, $code, $selfId);
                    }
                    Logger::audit('subagent.create', 'admin', $subUser, null, ['parent_id' => $viewId], 'Created counter sub-agent');
                    $flash = ['ok', 'Counter staff "' . $subName . '" registered successfully! Login Username: ' . $subUser . ' (PIN: ' . $subPin . ')'];
                }

            } elseif ($action === 'subagent_handover') {
                $subId = (int) ($_POST['sub_agent_id'] ?? 0);
                $amt   = max(0.0, (float) ($_POST['amount'] ?? 0));
                $note  = trim(Security::clean($_POST['note'] ?? 'Cash collection handed over to master agent', 255));
                $isSub = Database::exists("SELECT 1 FROM admin_profiles WHERE admin_id = :sid AND parent_agent_id = :pid", ['sid' => $subId, 'pid' => $viewId]);
                if (!$isSub && !$isSupervisor) {
                    throw new RuntimeException('You can only record handover for your own registered counter staff.');
                }
                if ($amt <= 0) {
                    throw new RuntimeException('Enter a valid handover amount.');
                }
                $subName = (string) Database::scalar("SELECT full_name FROM admins WHERE id = :id", ['id' => $subId]);
                $ref = 'HO-' . date('YmdHis');
                // 1. Relieve sub-agent of cash held
                AgentWallet::record($subId, 'cash_handover', $amt, ['ref' => $ref, 'note' => $note, 'by' => $selfId]);
                // 2. Add cash to master agent's custody
                AgentWallet::record($viewId, 'cash_due', $amt, ['ref' => $ref, 'note' => 'Cash received from counter staff ' . $subName, 'by' => $selfId]);
                $flash = ['ok', 'Cash handover of ₹' . number_format($amt, 2) . ' from ' . $subName . ' recorded successfully.'];

            } elseif ($action === 'toggle_subagent') {
                $subId = (int) ($_POST['sub_agent_id'] ?? 0);
                $isSub = Database::exists("SELECT 1 FROM admin_profiles WHERE admin_id = :sid AND parent_agent_id = :pid", ['sid' => $subId, 'pid' => $viewId]);
                if (!$isSub && !$isSupervisor) {
                    throw new RuntimeException('You can only manage your own registered counter staff.');
                }
                $current = (int) Database::scalar("SELECT is_active FROM admins WHERE id = :id", ['id' => $subId]);
                $new = $current === 1 ? 0 : 1;
                Database::run("UPDATE admins SET is_active = :a WHERE id = :id", ['a' => $new, 'id' => $subId]);
                $flash = ['ok', 'Counter staff booking status updated to ' . ($new ? 'Active' : 'Paused') . '.'];

            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$viewing = Database::fetch('SELECT id, username, email, full_name, role, is_active, last_login_at, last_login_ip, must_change_pw, locked_until FROM admins WHERE id = :id', ['id' => $viewId]);
if ($viewing === null) { $viewing = $admin; $viewId = $selfId; }
$isOwn = $viewId === $selfId;

/* ---- CSV ledger statement (Point 6) -------------------------------
   A date-ranged, row-by-row export of THIS agent's wallet — the "mini bank
   statement" the office can pull for a month. $viewId is already scope-checked
   above (an agent can only reach their own; a supervisor the selected agent),
   so this discloses nothing the page itself wouldn't. Must stream before any
   HTML is emitted. */
if (($_GET['export'] ?? '') === 'ledger') {
    $exFrom = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01');
    $exTo   = Security::isValidDate((string) ($_GET['to'] ?? ''))   ? (string) $_GET['to']   : todayISO();
    if ($exTo < $exFrom) { [$exFrom, $exTo] = [$exTo, $exFrom]; }
    $rows = AgentWallet::entriesBetween($viewId, $exFrom, $exTo);

    $slug  = preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($viewing['username'] ?? ('agent' . $viewId)));
    $fname = 'ledger-' . trim((string) $slug, '-') . "-{$exFrom}_to_{$exTo}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads Devanagari/₹ correctly
    fputcsv($out, ['Date', 'Entry', 'Account', 'Booking / Ref', 'Note', 'Amount (signed)', 'Recorded by']);
    $running = 0.0;
    foreach ($rows as $r) {
        $running += (float) $r['amount'];
        fputcsv($out, [
            (string) $r['created_at'],
            (string) $r['entry_type'],
            (string) $r['account'],
            (string) (($r['pnr'] ?? '') !== '' ? $r['pnr'] : ($r['ref'] ?? '')),
            (string) ($r['note'] ?? ''),
            number_format((float) $r['amount'], 2, '.', ''),
            (string) ($r['by_name'] ?? ''),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', 'Net movement in window', number_format($running, 2, '.', ''), '']);
    fclose($out);
    exit;
}

$profile  = AgentWallet::profile($viewId);
$balances = AgentWallet::balances($viewId);

/* Open payout request (self-serve, 3 Sep 2026): the newest request logged
   after the last recorded payout. Shown to the agent as "waiting" and to a
   supervisor as a nudge above the settle panel. */
$payoutReq    = null;
$payoutReqAmt = 0.0;
try {
    $payoutReq = Database::fetch(
        "SELECT new_value, created_at FROM audit_logs
          WHERE action = 'agent.payout_request' AND entity_type = 'admin' AND entity_id = :a
            AND created_at > COALESCE((SELECT MAX(created_at) FROM agent_ledger WHERE agent_admin_id = :b AND entry_type = 'payout'), '1970-01-01')
          ORDER BY id DESC LIMIT 1",
        ['a' => (string) $viewId, 'b' => $viewId]
    );
    if ($payoutReq !== null) {
        $payoutReqAmt = (float) ((json_decode((string) $payoutReq['new_value'], true) ?: [])['amount'] ?? 0);
    }
} catch (Throwable $e) { $payoutReq = null; }

$summary  = AgentWallet::summary($viewId);
$pct      = AgentWallet::commissionPercentFor($viewId);
$salary     = AgentWallet::salaryFor($viewId);
$salaryPaid = AgentWallet::salaryPaidTotal($viewId);
$thisMonth  = date('Y-m');
$salaryDone = $salary > 0 && AgentWallet::salaryPosted($viewId, $thisMonth);
$deposit    = AgentWallet::depositInfo($viewId);
$advance    = AgentWallet::advanceSummary($viewId);
$ledger   = AgentWallet::entries($viewId, 25);
$papers   = AgentWallet::offlineTickets($viewId, 10);

/* ---- Date windows -------------------------------------------------- */
$today      = todayISO();
$todayEnd   = addDaysISO($today, 1);
$weekStart  = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');
$monthEnd   = date('Y-m-01', strtotime('+1 month'));

/** Scoped aggregate over this agent's sales. */
function agent_stat(string $expr, int $adminId, string $from, string $to, string $extra = ''): float
{
    return (float) Database::scalar(
        "SELECT COALESCE($expr, 0) FROM bookings
          WHERE sold_by_admin_id = :a AND created_at >= :f AND created_at < :t $extra",
        ['a' => $adminId, 'f' => $from, 't' => $to],
        0
    );
}

$stats = [
    'todayCount' => (int) agent_stat('COUNT(*)', $viewId, $today, $todayEnd),
    'todayMoney' => agent_stat('SUM(total_amount)', $viewId, $today, $todayEnd, "AND status = 'confirmed'"),
    'weekCount'  => (int) agent_stat('COUNT(*)', $viewId, $weekStart, $todayEnd),
    'monthCount' => (int) agent_stat('COUNT(*)', $viewId, $monthStart, $monthEnd),
    'monthMoney' => agent_stat('SUM(total_amount)', $viewId, $monthStart, $monthEnd, "AND status = 'confirmed'"),
];

/* 7-day bookings sparkline for THIS agent (admin/index.php $spark pattern —
   range predicates on created_at, never DATE() wrapping, so the index is used). */
$spark = [];
for ($i = 6; $i >= 0; $i--) {
    $d  = date('Y-m-d', strtotime("-{$i} days"));
    $d1 = date('Y-m-d', strtotime($d . ' +1 day'));
    $spark[] = [
        'day'  => date('D', strtotime($d)),
        'date' => date('d', strtotime($d)),
        'val'  => (int) agent_stat('COUNT(*)', $viewId, $d, $d1),
    ];
}
$sparkMax = max(1, max(array_column($spark, 'val')));

/* ---- Cockpit (29 Oct 2026, management upgrade Phase 1.1 / 1.2) -------
   The month's target (this agent's own, else the company default), where
   the agent stands among the active agents, the badges, and the net
   position. All read-only here: targets are set and bonuses posted from
   Agent 360, money is still settled in the Wallet tab below. */
$tgt         = AgentTargets::progress($viewId);
$rank        = AgentTargets::rankFor($viewId, $tgt['month']);
$badges      = AgentTargets::badges($viewId, $tgt);
$net         = AgentWallet::netPosition($viewId);
$commMonths  = array_reverse(AgentWallet::commissionByMonth($viewId, 6));
$overdueDays = AgentWallet::settlementOverdueDays($viewId);

$tabs = [
    'balance'    => '💰 Balance · ब्यालेन्स',
    'sales'      => '🎟️ Sales · बिक्री',
    'commission' => '🎯 Commission · कमिसन',
    'wallet'     => '📜 Wallet · वालेट',
    'profile'    => '👤 Profile · प्रोफाइल',
];
$tab = (string) ($_GET['tab'] ?? 'balance');
if (!isset($tabs[$tab])) { $tab = 'balance'; }
$tabUrl = static function (string $t, string $anchor = '') use ($isOwn, $viewId): string {
    return '/admin/agent.php?' . http_build_query(($isOwn ? [] : ['agent' => $viewId]) + ['tab' => $t]) . $anchor;
};
$officeWa = preg_replace('/\D/', '', Settings::getString('company_whatsapp', '') ?: Settings::getString('company_phone', '')) ?? '';
if (strlen($officeWa) === 10) { $officeWa = '91' . $officeWa; }

/* What this agent is ACTUALLY paid right now — override, tier or percent. */
$override = AgentWallet::commissionOverrideFor($viewId);
if ($override['mode'] === 'flat') {
    $payLabel = inr((float) $override['flat']) . ' / pax';
    $paySub   = 'agent override — flat per passenger';
} elseif ($override['mode'] === 'percent') {
    $payLabel = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
    $paySub   = 'agent override — % of fare';
} elseif (AgentWallet::flatMode()) {
    $payLabel = inr(AgentWallet::flatRateFor($viewId)) . ' / pax';
    $paySub   = (AgentWallet::agentTypeFor($viewId) === 'joint' ? 'team/organisation' : 'direct') . ' tier';
} else {
    $payLabel = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
    $paySub   = $profile['commission_percent'] === null ? 'company default rate' : 'set for this agent';
}

/* ---- Upcoming departures the agent can still sell into -------------- */
$upcoming = Database::fetchAll(
    "SELECT s.id AS schedule_id, s.travel_date, s.total_seats, s.route_id,
            r.route_code, r.from_city, r.to_city, r.dep_time, r.coach_type,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.schedule_id = s.id AND bs.released_at IS NULL) AS sold
       FROM schedules s
       JOIN routes r ON r.id = s.route_id
      WHERE s.travel_date >= :d AND s.status = 'scheduled'
      ORDER BY s.travel_date ASC, r.dep_time ASC
      LIMIT 8",
    ['d' => $today]
);
/* `sold` counts booking_seats ROWS, and a private cabin is one row over two
   berths, so this list showed an agent room a coach does not actually have.
   Restated in physical berths through the seat engine. */
$__sold = Seats::occupiedBedsFor(array_map(static fn(array $r): int => (int) $r['schedule_id'], $upcoming));
foreach ($upcoming as &$__u) {
    $__u['sold'] = $__sold[(int) $__u['schedule_id']] ?? (int) $__u['sold'];
}
unset($__u);

// A route-restricted counter agent only sees trips they may actually sell —
// otherwise the refusal arrives after the passenger details are typed.
if (Auth::isCounterAgent()) {
    $upcoming = array_values(array_filter($upcoming, static fn(array $u): bool => AgentWallet::maySellRoute($selfId, (int) $u['route_id'])));
}

/* ---- This agent's recent sales -------------------------------------- */
$recent = Database::fetchAll(
    "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at, b.source,
            r.from_city, r.to_city, bl.travel_date,
            (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
               FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats
       FROM bookings b
       LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
       LEFT JOIN schedules s ON s.id = bl.schedule_id
       LEFT JOIN routes r ON r.id = s.route_id
      WHERE b.sold_by_admin_id = :a
      ORDER BY b.id DESC LIMIT 15",
    ['a' => $viewId]
);

/* ---- Daily Quota & Sub-Agent Staff Hierarchy (2026 upgrade) -------- */
$dailyLimit = (int) ($profile['daily_booking_limit'] ?? 0);
$todayBookedSeats = (int) Database::scalar(
    "SELECT COUNT(bs.id)
       FROM bookings b
       JOIN booking_seats bs ON bs.booking_id = b.id AND bs.released_at IS NULL
      WHERE (b.sold_by_admin_id = :aid OR b.parent_agent_id = :aid2)
        AND DATE(b.created_at) = CURRENT_DATE()
        AND b.status IN ('confirmed','completed')",
    ['aid' => $viewId, 'aid2' => $viewId], 0
);
$quotaPercent = $dailyLimit > 0 ? min(100, (int) round(($todayBookedSeats / $dailyLimit) * 100)) : 0;

$mySubAgents = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.is_active, a.last_login_at,
            (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS sales,
            (SELECT COALESCE(SUM(b.total_amount), 0) FROM bookings b WHERE b.sold_by_admin_id = a.id AND b.status IN ('confirmed','completed')) AS revenue,
            (SELECT COALESCE(SUM(amount), 0) FROM agent_ledger l WHERE l.agent_admin_id = a.id AND l.account = 'cash') AS cash_held
       FROM admins a
       JOIN admin_profiles p ON p.admin_id = a.id
      WHERE p.parent_agent_id = :aid
      ORDER BY a.full_name, a.id",
    ['aid' => $viewId]
);

$subAgentBookings = [];
if ($mySubAgents !== []) {
    $subAgentBookings = Database::fetchAll(
        "SELECT b.pnr, b.status, b.total_amount, b.contact_phone, b.created_at,
                a.full_name AS booked_by_staff,
                r.from_city, r.to_city, bl.travel_date,
                (SELECT GROUP_CONCAT(bs.seat_no ORDER BY bs.seat_no SEPARATOR ' ')
                   FROM booking_seats bs WHERE bs.booking_id = b.id) AS seats
           FROM bookings b
           JOIN admins a ON a.id = b.sold_by_admin_id
           JOIN admin_profiles p ON p.admin_id = a.id
           LEFT JOIN booking_legs bl ON bl.booking_id = b.id AND bl.leg_type = 'outbound'
           LEFT JOIN schedules s ON s.id = bl.schedule_id
           LEFT JOIN routes r ON r.id = s.route_id
          WHERE p.parent_agent_id = :aid
          ORDER BY b.id DESC LIMIT 10",
        ['aid' => $viewId]
    );
}

$agentList = $isSupervisor
    ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' ORDER BY full_name, username")
    : [];

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/** Ledger row → [icon, label, colour]. $ref lets an advance adjustment
 *  (ref 'ADVANCE…') read as "Advance" instead of a generic adjustment. */
function ledger_look(string $type, string $ref = ''): array
{
    if ($type === 'adjustment' && stripos($ref, 'ADVANCE') === 0) {
        return ['💸', 'Advance', '#8a5300'];
    }
    if ($type === 'adjustment' && stripos($ref, 'BONUS') === 0) {
        return ['🎁', 'Target bonus', '#0a6b3b'];
    }
    return match ($type) {
        'commission'      => ['💰', 'Commission earned', '#0a6b3b'],
        'commission_void' => ['↩️', 'Commission reversed', '#b02a2a'],
        'payout'          => ['🏦', 'Paid to agent',      '#2E5FA8'],
        'cash_due'        => ['💵', 'Cash collected',     '#7a5200'],
        'cash_handover'   => ['📥', 'Cash handed over',   '#0a6b3b'],
        default           => ['✏️', 'Adjustment',         '#6b7688'],
    };
}

/** Ledger row → [timeline class, tag] for the Wallet tab (29 Oct 2026):
 *  green = earned, red = deducted, blue = settled, amber = cash the agent owes. */
function ledger_tag(string $type, float $amount, string $ref = ''): array
{
    if ($type === 'adjustment' && stripos($ref, 'ADVANCE') === 0) {
        return $amount < 0 ? ['t-owes', 'Advance'] : ['t-settled', 'Recovered'];
    }
    return match ($type) {
        'commission'                => ['t-earned', 'Earned'],
        'commission_void'           => ['t-deducted', 'Reversed'],
        'payout', 'cash_handover'   => ['t-settled', 'Settled'],
        'cash_due'                  => ['t-owes', 'Cash due'],
        default                     => $amount >= 0 ? ['t-earned', stripos($ref, 'BONUS') === 0 ? 'Bonus' : 'Credit'] : ['t-deducted', 'Deducted'],
    };
}

admin_header($isOwn ? 'My Agent Panel' : 'Agent · ' . (string) $viewing['full_name'], 'agent');
echo LiveSync::anchor('agent:' . $viewId, 'pill');

if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>';
}
?>
<?php if ($isSupervisor && $agentList !== []): ?>
<form method="get" class="toolbar">
  <label class="muted" style="font-size:13px">Viewing agent:</label>
  <select name="agent" onchange="this.form.submit()">
    <?php foreach ($agentList as $a): ?>
      <option value="<?= (int) $a['id'] ?>" <?= (int) $a['id'] === $viewId ? 'selected' : '' ?>>
        <?= Security::e($a['full_name'] ?: $a['username']) ?>
      </option>
    <?php endforeach; ?>
  </select>
  <span class="muted" style="font-size:12px">Supervisor view — agents only ever see their own figures.</span>
</form>
<?php endif; ?>

<style>
/* House style copied from admin/index.php — hero stat cards + panels + sparkline. */
.dash-hero{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
.hcard{border-radius:16px;padding:20px 22px;color:#fff;position:relative;overflow:hidden}
.hcard::after{content:'';position:absolute;right:-18px;top:-18px;width:80px;height:80px;border-radius:50%;background:rgba(255,255,255,.1)}
.hcard .hicon{font-size:28px;margin-bottom:8px;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,.15))}
.hcard .hk{font-size:12px;text-transform:uppercase;letter-spacing:.5px;opacity:.85}
.hcard .hv{font-size:30px;font-weight:800;margin:4px 0 2px;line-height:1.1}
.hcard .hsub{font-size:12px;opacity:.75}
.hc-blue{background:linear-gradient(135deg,#2E5FA8,#1a3d6e)}
.hc-green{background:linear-gradient(135deg,#0a8b4b,#065a30)}
.hc-orange{background:linear-gradient(135deg,#e67e22,#d35400)}
.hc-red{background:linear-gradient(135deg,#c0392b,#8e2320)}
.hc-navy{background:linear-gradient(135deg,#12264E,#0b1a36)}
.hc-teal{background:linear-gradient(135deg,#00897b,#00695c)}

.dash-panel{background:var(--card);border:1px solid var(--line);border-radius:16px;overflow:hidden;margin-bottom:24px}
.dash-panel .dp-head{padding:16px 20px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;font-size:15px;background:var(--head)}
.dash-panel .dp-body{padding:20px}

.spark-wrap{display:flex;align-items:flex-end;gap:6px;height:100px;padding:0 4px}
.spark-bar{flex:1;border-radius:6px 6px 0 0;background:linear-gradient(180deg,#2E5FA8,#1a3d6e);min-width:8px;position:relative;transition:height .3s}
.spark-bar:hover{filter:brightness(1.2)}
.spark-bar .spark-tip{display:none;position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:var(--navy);color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;white-space:nowrap;margin-bottom:4px;font-weight:700}
.spark-bar:hover .spark-tip{display:block}
/* Touch screens have no hover: keep each day's value visible above its bar. */
@media(pointer:coarse){.spark-bar .spark-tip{display:block;font-size:9px;padding:2px 4px;margin-bottom:2px}}
.spark-labels{display:flex;gap:6px;padding:8px 4px 0;font-size:11px;color:var(--mut);text-align:center}
.spark-labels span{flex:1;min-width:8px}

@media(max-width:900px){
  .dash-hero{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))}
}
/* Panel headings that carry a button: flex so the button wraps under the
   title on a phone instead of floating over it. */
.panel h2{display:flex;flex-wrap:wrap;align-items:center;gap:8px}

/* ---- Cockpit (29 Oct 2026): four big buttons, the net position, quick
   stats and one tab per job. Touch targets are 48px+ for the phone. ---- */
.ck-acts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px}
.ck-act{display:flex;align-items:center;gap:12px;min-height:76px;padding:14px 16px;border-radius:16px;background:var(--card);border:1px solid var(--line);border-left-width:5px;color:var(--ink);text-decoration:none;box-shadow:0 2px 10px rgba(18,38,78,.06);transition:transform .15s,box-shadow .15s}
.ck-act:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(18,38,78,.12)}
.ck-act .ci{font-size:28px;line-height:1;flex:none}
.ck-act b{display:block;font-size:15px;font-weight:800;line-height:1.25}
.ck-act small{display:block;font-size:12.5px;color:var(--mut);margin-top:3px}
.ck-act.a-cash{border-left-color:var(--orange)}.ck-act.a-stmt{border-left-color:var(--blue)}
.ck-act.a-tgt{border-left-color:#0a8b4b}.ck-act.a-book{border-left-color:var(--navy)}
.ck-act.hot small{color:#b02a2a;font-weight:700}
.ck-net{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px 18px;margin-bottom:16px}
.ck-net-head{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:flex-end;gap:10px;margin-bottom:10px}
.ck-net .k{font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:var(--mut);font-weight:700}
.ck-net .v{font-size:28px;font-weight:800;line-height:1.15;font-variant-numeric:tabular-nums}
.ck-net .s{font-size:13px;color:var(--mut)}
.ck-net.owes .v{color:#b02a2a}.ck-net.credit .v{color:#0a6b3b}.ck-net.square .v{color:var(--navy)}
.ck-net-legend{display:flex;flex-wrap:wrap;gap:14px;font-size:12.5px;color:var(--mut)}
.ck-net-legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:5px;vertical-align:-1px}
.ck-net-legend b{color:var(--ink)}
.ck-netbar{display:flex;height:14px;border-radius:99px;overflow:hidden;background:var(--line)}
.ck-netbar span{display:block;height:100%}
.ck-g{background:#0a8b4b}.ck-r{background:#c0392b}
.ck-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:6px}
.ck-stat{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px 14px;min-width:0}
.ck-stat .k{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--mut);font-weight:700}
.ck-stat .v{font-size:22px;font-weight:800;margin-top:2px;font-variant-numeric:tabular-nums}
.ck-stat .s{font-size:12px;color:var(--mut);margin-top:2px}
.ck-mini{height:6px;border-radius:99px;background:var(--line);overflow:hidden;margin-top:7px}
.ck-mini span{display:block;height:100%}
.ck-tabs{display:flex;gap:6px;overflow-x:auto;position:sticky;top:56px;z-index:20;background:var(--bg);padding:10px 0;margin-bottom:12px;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.ck-tabs::-webkit-scrollbar{display:none}
.ck-tabs a{flex:none;display:inline-flex;align-items:center;gap:6px;min-height:48px;padding:0 16px;border-radius:12px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-weight:700;font-size:14px;text-decoration:none}
.ck-tabs a.on{background:var(--navy);color:#fff;border-color:var(--navy)}
.ck-tabs a .n{display:inline-block;min-width:18px;padding:0 6px;border-radius:99px;background:var(--orange);color:#fff;font-size:11px;line-height:18px;text-align:center}
html[data-ck-tab="balance"] [data-pane]:not([data-pane~="balance"]),
html[data-ck-tab="sales"] [data-pane]:not([data-pane~="sales"]),
html[data-ck-tab="commission"] [data-pane]:not([data-pane~="commission"]),
html[data-ck-tab="wallet"] [data-pane]:not([data-pane~="wallet"]),
html[data-ck-tab="profile"] [data-pane]:not([data-pane~="profile"]){display:none !important}
.ck-badges{display:flex;flex-wrap:wrap;gap:8px}
.ck-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:999px;font-size:13px;font-weight:800;background:#fff4e0;color:#8a4b00;border:1px solid #f6d6a5}
.ck-badge.off{background:var(--head);color:var(--mut);border-color:var(--line);font-weight:600;filter:grayscale(1)}
.ck-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:12px 0}
.ck-kv div{background:var(--head);border:1px solid var(--line);border-radius:12px;padding:10px 12px}
.ck-kv span{display:block;font-size:11.5px;color:var(--mut);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.ck-kv b{font-size:18px;font-variant-numeric:tabular-nums}
.ck-bookf{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:12px;align-items:end}
.ck-bookf label{font-size:12.5px;font-weight:700;color:var(--ink)}
.ck-bookf input{display:block;width:100%;margin-top:5px;min-height:48px;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:16px;background:var(--card);color:var(--ink)}
.ck-bookf .btn{min-height:48px;font-size:15px;padding:0 20px}
.ck-tl{list-style:none;margin:0;padding:0 18px 14px;max-height:560px;overflow-y:auto}
.ck-tl li{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:11px 12px;margin-top:8px;border:1px solid var(--line);border-left:5px solid var(--line);border-radius:12px;background:var(--card)}
.ck-tl .tg{display:inline-block;padding:3px 9px;border-radius:99px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;white-space:nowrap}
.ck-tl .t-earned{border-left-color:#0a8b4b}.ck-tl .t-earned .tg{background:#d7f4e3;color:#0a6b3b}
.ck-tl .t-deducted{border-left-color:#c0392b}.ck-tl .t-deducted .tg{background:#fde2e1;color:#a12a22}
.ck-tl .t-settled{border-left-color:#2E5FA8}.ck-tl .t-settled .tg{background:#e1ecfb;color:#1f4f96}
.ck-tl .t-owes{border-left-color:#e67e22}.ck-tl .t-owes .tg{background:#fff1de;color:#8a4b00}
.ck-tl .what{font-weight:700;font-size:13.5px}
.ck-tl .sub{font-size:12px;color:var(--mut);margin-top:2px;overflow-wrap:anywhere}
.ck-tl .amt{text-align:right;font-weight:800;font-variant-numeric:tabular-nums;white-space:nowrap}
.ck-tl .amt small{display:block;font-weight:500;font-size:11.5px;color:var(--mut)}
@media(max-width:900px){
  .ck-acts,.ck-stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .ck-bookf{grid-template-columns:1fr}
}
@media(max-width:520px){
  .ck-act{min-height:84px;padding:12px;gap:9px}.ck-act .ci{font-size:24px}.ck-act b{font-size:14px}
  .ck-net .v{font-size:24px}
  .ck-tl li{grid-template-columns:minmax(0,1fr) auto}.ck-tl li .tgw{grid-column:1 / -1}
}
@media print{.ck-acts,.ck-tabs{display:none !important}}
</style>

<?php
/* ---- Cockpit header ---------------------------------------------------- */
$ckCash     = max(0.0, (float) $net['cash']);
$ckComm     = max(0.0, (float) $net['commission']);
$ckTot      = $ckCash + $ckComm;
$ckGreenW   = $ckTot > 0 ? round($ckComm * 100 / $ckTot, 1) : 0.0;
$ckRedW     = $ckTot > 0 ? round(100 - $ckGreenW, 1) : 0.0;
$ckNetCls   = $net['net'] > 0.009 ? 'owes' : ($net['net'] < -0.009 ? 'credit' : 'square');
$ckTgtAmt   = (float) $tgt['target']['amount'];
$ckCanBook  = $isOwn && Auth::isSellingStaff();
$ckCan360   = !$isOwn && Auth::can('customers.view');
$ckCashHref = $isOwn ? $tabUrl('wallet', '#cashBox') : $tabUrl('wallet', $canSettle ? '#settleBox' : '');
$ckStmtHref = '/admin/agent-statement.php' . ($isOwn ? '' : '?agent=' . $viewId);
$ckCashHot  = $overdueDays > 0 || ((float) $profile['cash_limit'] > 0 && $balances['cash'] > (float) $profile['cash_limit']);
?>
<!-- ============ COCKPIT: big buttons ============ -->
<nav class="ck-acts" aria-label="Quick actions">
  <a class="ck-act a-cash<?= $ckCashHot ? ' hot' : '' ?>" href="<?= Security::e($ckCashHref) ?>">
    <span class="ci">💵</span>
    <span><b><?= $isOwn ? 'आजको Cash जम्मा गर' : 'Accept cash · Cash लिनुहोस्' ?></b>
      <small><?= Security::e(inr($ckCash)) ?> हातमा<?= $overdueDays > 0 ? ' · ' . $overdueDays . ' दिन ढिलो' : '' ?></small></span>
  </a>
  <a class="ck-act a-stmt" href="<?= Security::e($ckStmtHref) ?>">
    <span class="ci">📜</span>
    <span><b>Statement हेर</b><small>महिनाको हिसाब · print / PDF</small></span>
  </a>
  <a class="ck-act a-tgt" href="<?= Security::e($tabUrl('commission', '#targetBox')) ?>">
    <span class="ci">🎯</span>
    <span><b>Target कति बाँकी</b>
      <small><?php if ($ckTgtAmt <= 0): ?>Target set छैन<?php elseif ($tgt['remaining'] <= 0): ?>✓ Target पूरा — <?= (int) $tgt['pct'] ?>%<?php else: ?><?= Security::e(inr($tgt['remaining'])) ?> बाँकी · <?= (int) $tgt['days_left'] ?> दिन<?php endif; ?></small></span>
  </a>
  <?php if ($ckCanBook): ?>
  <a class="ck-act a-book" href="<?= Security::e($tabUrl('sales', '#bookBox')) ?>">
    <span class="ci">🎟️</span>
    <span><b>Book for customer</b><small>Mobile हाल्नुस् → SmartBook</small></span>
  </a>
  <?php elseif ($ckCan360): ?>
  <a class="ck-act a-book" href="/admin/agent-360.php?agent=<?= $viewId ?>&amp;tab=targets">
    <span class="ci">🗂️</span>
    <span><b>Agent 360 · Targets</b><small>Target set / bonus post</small></span>
  </a>
  <?php else: ?>
  <a class="ck-act a-book" href="<?= Security::e($tabUrl('sales')) ?>">
    <span class="ci">🚌</span>
    <span><b>Sales · बिक्री</b><small>Departures &amp; recent tickets</small></span>
  </a>
  <?php endif; ?>
</nav>

<!-- ============ COCKPIT: net position ============ -->
<div class="ck-net <?= $ckNetCls ?>">
  <div class="ck-net-head">
    <div>
      <div class="k">Net position · नेट हिसाब</div>
      <div class="v"><?= Security::e(inr(abs((float) $net['net']))) ?></div>
      <div class="s"><?php if ($ckNetCls === 'owes'): ?><?= $isOwn ? 'तपाईंले company लाई बुझाउन बाँकी' : 'agent owes the company on balance' ?><?php elseif ($ckNetCls === 'credit'): ?><?= $isOwn ? 'Company ले तपाईंलाई दिन बाँकी' : 'company owes the agent on balance' ?><?php else: ?>हिसाब मिलेको छ ✓ settled<?php endif; ?>
        <span class="muted">(cash held − commission due)</span></div>
    </div>
    <div class="ck-net-legend">
      <span><i class="ck-g"></i>Commission due <b><?= Security::e(inr($ckComm)) ?></b></span>
      <span><i class="ck-r"></i>Cash held <b><?= Security::e(inr($ckCash)) ?></b></span>
    </div>
  </div>
  <div class="ck-netbar" role="img" aria-label="Commission due <?= Security::e(inr($ckComm)) ?>, cash held <?= Security::e(inr($ckCash)) ?>">
    <?php if ($ckTot > 0): ?><span class="ck-g" style="width:<?= $ckGreenW ?>%"></span><span class="ck-r" style="width:<?= $ckRedW ?>%"></span><?php endif; ?>
  </div>
</div>

<!-- ============ COCKPIT: quick stats ============ -->
<div class="ck-stats">
  <div class="ck-stat"><div class="k">Today · आज</div>
    <div class="v"><?= Security::e(inr($stats['todayMoney'])) ?></div>
    <div class="s"><?= (int) $stats['todayCount'] ?> ticket<?= (int) $stats['todayCount'] === 1 ? '' : 's' ?> sold</div></div>
  <div class="ck-stat"><div class="k">This month · यो महिना</div>
    <div class="v"><?= Security::e(inr($tgt['sales']['money'])) ?></div>
    <div class="s"><?= (int) $tgt['sales']['confirmed'] ?> confirmed · <?= Security::e(inr($summary['earnedMonth'])) ?> commission</div></div>
  <div class="ck-stat"><div class="k">Target · लक्ष्य</div>
    <?php if ($ckTgtAmt > 0):
      $ckTc = $tgt['pct'] >= 100 ? '#0a8b4b' : ($tgt['pct'] >= 60 ? '#d68910' : '#c0392b'); ?>
      <div class="v" style="color:<?= $ckTc ?>"><?= (int) $tgt['pct'] ?>%</div>
      <div class="s">of <?= Security::e(inr($ckTgtAmt)) ?></div>
      <div class="ck-mini"><span style="width:<?= min(100, (int) $tgt['pct']) ?>%;background:<?= $ckTc ?>"></span></div>
    <?php else: ?>
      <div class="v muted">—</div><div class="s">no target this month</div>
    <?php endif; ?></div>
  <div class="ck-stat"><div class="k">Ranking · स्थान</div>
    <?php if ($rank['rank'] > 0 && $tgt['sales']['money'] > 0): ?>
      <div class="v"><?= $rank['rank'] === 1 ? '🥇 ' : '' ?>#<?= (int) $rank['rank'] ?><small class="muted" style="font-size:13px"> / <?= (int) $rank['of'] ?></small></div>
      <div class="s"><?= $rank['gap'] > 0 ? '#' . (int) $rank['ahead'] . ' सँग ' . Security::e(inr($rank['gap'])) . ' को फरक' : 'सबैभन्दा अगाडि!' ?></div>
    <?php elseif ($rank['rank'] > 0): ?>
      <div class="v muted">—</div><div class="s">No sale yet this month · <?= (int) $rank['of'] ?> agents</div>
    <?php else: ?>
      <div class="v muted">—</div><div class="s">not ranked (inactive)</div>
    <?php endif; ?></div>
</div>

<!-- ============ COCKPIT: tabs ============ -->
<nav class="ck-tabs no-print" id="ckTabs" aria-label="Agent panel sections">
  <?php foreach ($tabs as $key => $label):
    $ckN = $key === 'commission' ? count($badges) : 0; ?>
    <a href="<?= Security::e($tabUrl($key)) ?>" data-tab="<?= $key ?>" class="<?= $key === $tab ? 'on' : '' ?>"><?= Security::e($label) ?><?= $ckN > 0 ? '<span class="n">' . $ckN . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<script>document.documentElement.setAttribute('data-ck-tab', <?= json_encode($tab) ?>);</script>

<!-- DAILY SEAT QUOTA INDICATOR -->
<div data-pane="balance" style="background:var(--card,#fff);border:1px solid var(--line,#e2e8f0);border-radius:14px;padding:14px 18px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;box-shadow:0 3px 10px rgba(0,0,0,0.04)">
  <div style="flex:1;min-width:240px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
      <span style="font-weight:700;font-size:14px;color:#0d3b66">
        ⚡ Today's Daily Booking Quota: <?= $dailyLimit > 0 ? $todayBookedSeats . ' / ' . $dailyLimit . ' seats booked' : $todayBookedSeats . ' seats booked (Unlimited Quota)' ?>
      </span>
      <span style="font-size:12px;font-weight:700;color:<?= $quotaPercent >= 100 ? '#b91c1c' : ($quotaPercent >= 80 ? '#d97706' : '#15803d') ?>">
        <?= $dailyLimit > 0 ? $quotaPercent . '% utilized' : '✓ Active Selling' ?>
      </span>
    </div>
    <?php if ($dailyLimit > 0): ?>
      <div style="width:100%;height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden">
        <div style="width:<?= $quotaPercent ?>%;height:100%;background:<?= $quotaPercent >= 100 ? '#ef4444' : ($quotaPercent >= 80 ? '#f59e0b' : '#22c55e') ?>;border-radius:4px;transition:width .3s"></div>
      </div>
    <?php endif; ?>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
    <span class="pill" style="background:#efeaff;color:#5a3fb0;font-weight:700">Agent Ref: <?= Security::e(AgentWallet::agentCodeLabel($viewId)) ?></span>
    <span class="pill" style="background:#e0f2fe;color:#0369a1;font-weight:700">Commission: <?= Security::e($payLabel) ?></span>
    <?php if (count($mySubAgents) > 0): ?>
      <span class="pill" style="background:#fef3c7;color:#92400e;font-weight:700">👥 <?= count($mySubAgents) ?> Counter Staff</span>
    <?php endif; ?>
    <?php if ($dailyLimit > 0): 
      $quotaMsg = "Namaste HQ! Requesting additional seat booking quota for Agent " . ($viewing['full_name'] ?: $viewing['username']) . " (Code: " . AgentWallet::agentCodeLabel($viewId) . "). Current limit: {$todayBookedSeats}/{$dailyLimit}. Please add +10 seats quota.";
      $quotaWa = "https://wa.me/919104801507?text=" . urlencode($quotaMsg);
    ?>
      <a href="<?= $quotaWa ?>" target="_blank" rel="noopener" class="btn" style="background:#f59e0b;color:#fff;font-size:12px;padding:4px 10px;text-decoration:none;border-radius:6px;font-weight:700">⚡ Request Extra Quota</a>
    <?php endif; ?>
  </div>
</div>

<!-- ============ BOOK FOR A CUSTOMER (29 Oct 2026) ============
     Replaces the old "express counter" form, whose route list, berth
     buttons and ₹2,200 fare were typed into this page and which sent
     fields QuickBot never read. SmartBook is the one booking door: the
     mobile fills the customer's name, pickup and party from their last
     trip, the seat map there is live, and the sale lands on this agent. -->
<?php if ($ckCanBook): ?>
<div class="dash-panel" id="bookBox" data-pane="sales" style="border:2px solid var(--navy)">
  <div class="dp-head" style="background:linear-gradient(135deg,#12264E,#2E5FA8);color:#fff;justify-content:space-between;flex-wrap:wrap">
    <span>🎟️ Book for a customer · ग्राहकको टिकट</span>
    <span style="font-size:12px;font-weight:600;opacity:.9">Code <?= Security::e(AgentWallet::agentCodeLabel($viewId)) ?> · <?= Security::e($payLabel) ?></span>
  </div>
  <div class="dp-body">
    <form method="get" action="/admin/smartbook.php" class="ck-bookf">
      <label>Customer mobile · मोबाइल
        <input type="tel" name="cphone" id="ckPhone" inputmode="tel" required minlength="10" maxlength="15" pattern="[0-9+ ]{10,15}" placeholder="98XXXXXXXX / 9XXXXXXXXX" autocomplete="off" enterkeyhint="go"></label>
      <label>Name · नाम <span class="muted" style="font-weight:400">(optional)</span>
        <input type="text" name="cname" maxlength="80" placeholder="Filled in for a returning customer" autocomplete="off"></label>
      <button class="btn ok" type="submit">Open SmartBook →</button>
    </form>
    <p class="muted" style="font-size:12.5px;margin:12px 0 0">A returning customer's name, pickup point and party fill in by themselves. Pick the seats on the live map, take cash or send the QR, and the ticket (PNG + PDF) is issued on your code — commission is credited the moment it confirms.</p>
  </div>
</div>
<?php endif; ?>

<!-- ============ WALLET ============ -->
<div class="dash-hero" data-pane="balance">
  <?php if (Auth::isSellingStaff()): ?>
  <!-- ⚡ Quick Ticket + 🤖 AI Ticket Bot (6 Sep 2026): the agent's fast lane —
       the same desk the office uses, sales attributed to this agent. -->
  <a class="hcard hc-orange" href="/admin/quick-ticket.php" style="text-decoration:none;display:block">
    <span class="hicon">⚡</span>
    <div class="hk">🤖 QuickBot Ticket</div>
    <div class="hv" style="font-size:22px">10-Second Booking</div>
    <div class="hsub">One line: name + mobile (+ seats · town · date)<br>auto route · seat · fare → Confirm → PNG + PDF + WhatsApp</div>
  </a>
  <?php endif; ?>
  <?php if ($salary > 0): ?>
  <div class="hcard hc-blue">
    <span class="hicon">📅</span>
    <div class="hk">Monthly salary</div>
    <div class="hv"><?= Security::e(inr($salary)) ?></div>
    <div class="hsub">
      credited so far <?= Security::e(inr($salaryPaid)) ?>
      <br><?= $salaryDone
            ? '✓ ' . Security::e($thisMonth) . ' posted'
            : Security::e($thisMonth) . ' not posted yet' ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($deposit['required'] > 0 || $deposit['paid'] > 0): ?>
  <div class="hcard <?= $deposit['met'] ? 'hc-teal' : 'hc-orange' ?>">
    <span class="hicon">🔐</span>
    <div class="hk">Security deposit</div>
    <div class="hv"><?= Security::e(inr($deposit['paid'])) ?></div>
    <div class="hsub">
      of <?= Security::e(inr($deposit['required'])) ?> required
      <?php if (!$deposit['met']): ?><br>⚠ <?= Security::e(inr($deposit['short'])) ?> short — collect before selling
      <?php else: ?><br>✓ fully deposited<?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($advance['given'] > 0 || $advance['outstanding'] > 0): ?>
  <div class="hcard <?= $advance['outstanding'] > 0 ? 'hc-orange' : 'hc-teal' ?>">
    <span class="hicon">💸</span>
    <div class="hk">Advance</div>
    <div class="hv"><?= Security::e(inr($advance['outstanding'])) ?></div>
    <div class="hsub">
      to recover from commission
      <br>given <?= Security::e(inr($advance['given'])) ?>
      <?= $advance['repaid'] > 0 ? ' · repaid ' . Security::e(inr($advance['repaid'])) : '' ?>
      <?php if ($advance['outstanding'] <= 0): ?><br>✓ fully settled<?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="hcard hc-green">
    <span class="hicon">💰</span>
    <div class="hk">Commission due</div>
    <div class="hv"><?= Security::e(inr($balances['commission'])) ?></div>
    <div class="hsub">
      earned <?= Security::e(inr($summary['earned'])) ?>
      <?= $summary['reversed'] > 0 ? ' · reversed ' . Security::e(inr($summary['reversed'])) : '' ?>
      · paid <?= Security::e(inr($summary['paidOut'])) ?>
      <?= $advance['given'] > 0 ? ' · advance ' . Security::e(inr($advance['given'])) : '' ?>
    </div>
  </div>
  <?php $overCash = (float) $profile['cash_limit'] > 0 && $balances['cash'] > (float) $profile['cash_limit']; ?>
  <div class="hcard <?= $overCash ? 'hc-red' : 'hc-navy' ?>">
    <span class="hicon">💵</span>
    <div class="hk">Cash in hand</div>
    <div class="hv"><?= Security::e(inr($balances['cash'])) ?></div>
    <div class="hsub">
      collected <?= Security::e(inr($summary['collected'])) ?> · handed over <?= Security::e(inr($summary['handedOver'])) ?>
      <?php if ($overCash): ?>
        <br>⚠ over the <?= Security::e(inr((float) $profile['cash_limit'])) ?> limit — deposit today
      <?php endif; ?>
    </div>
  </div>
</div>

<?php /* The four count cards that sat here (today / month / pay / week) now
         live in the cockpit's quick stats and the Commission tab. */ ?>
<?php if ($isOwn && Auth::isCounterAgent()): ?>
<div class="panel" id="payoutRequest" data-pane="balance wallet">
  <h2>💸 Request a payout</h2>
  <div style="padding:14px 18px">
    <?php if ($payoutReq !== null): ?>
      <p style="margin:0">⏳ Requested <strong><?= Security::e(inr($payoutReqAmt)) ?></strong> on <?= Security::e(formatDate(substr((string) $payoutReq['created_at'], 0, 10))) ?> — waiting for the office. It clears on its own once the payout is recorded.</p>
    <?php elseif ($balances['commission'] > 0): ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="payout_request">
        <input type="number" name="amount" min="1" max="<?= (int) floor($balances['commission']) ?>" step="1" value="<?= (int) floor($balances['commission']) ?>" style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:16px;width:140px">
        <input type="text" name="note" maxlength="200" placeholder="Note (optional)" style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;font-size:16px;flex:1 1 200px">
        <button class="btn ok" type="submit">Request payout</button>
        <span class="muted" style="font-size:12px;flex-basis:100%">Commission due <?= Security::e(inr($balances['commission'])) ?>. The office is notified on WhatsApp and pays to the account on your profile.</span>
      </form>
    <?php else: ?>
      <p class="muted" style="margin:0">Nothing due right now — commission appears here as your sales are confirmed.</p>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($payoutReq !== null && $canSettle): ?>
<div class="flash" data-pane="balance wallet" style="background:#fff3e0;color:#7a3e00">💸 Pending payout request: <strong><?= Security::e(inr($payoutReqAmt)) ?></strong> asked on <?= Security::e(formatDate(substr((string) $payoutReq['created_at'], 0, 10))) ?> — record the payout below to clear it.</div>
<?php endif; ?>

<!-- MY COUNTER STAFF (SUB-AGENTS) MANAGER -->
<div class="dash-panel" data-pane="sales" style="margin-top:20px;border:1px solid #cbd5e1">
  <div class="dp-head" style="justify-content:space-between;background:#f8fafc">
    <div style="display:flex;align-items:center;gap:8px">
      <span>👥</span>
      <span>My Counter Staff (Sub-Agents)</span>
      <span class="pill" style="background:#efeaff;color:#5a3fb0;font-size:11px"><?= count($mySubAgents) ?> Staff Members</span>
    </div>
    <button type="button" class="btn ghost" onclick="var el=document.getElementById('addSubagentBox');el.hidden=!el.hidden" style="font-size:12px;padding:4px 10px">＋ Register Counter Staff</button>
  </div>
  <div class="dp-body" style="padding:16px">
    
    <!-- REGISTER COUNTER STAFF FORM (COLLAPSIBLE) -->
    <div id="addSubagentBox" hidden style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:10px;padding:14px;margin-bottom:16px">
      <h4 style="margin:0 0 10px;font-size:13.5px;color:#0d3b66">Register New Shop / Counter Staff under Your Master Agent Account</h4>
      <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;align-items:flex-end">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="add_subagent">
        <label style="font-size:12px;font-weight:600">Staff Full Name<input type="text" name="sub_name" required placeholder="e.g. Ramesh Giri" style="width:100%;padding:7px;border-radius:6px;border:1px solid #cbd5e1;margin-top:3px"></label>
        <label style="font-size:12px;font-weight:600">Mobile (10 digits)<input type="tel" name="sub_phone" required placeholder="e.g. 9812345678" style="width:100%;padding:7px;border-radius:6px;border:1px solid #cbd5e1;margin-top:3px"></label>
        <label style="font-size:12px;font-weight:600">Login PIN / Password<input type="text" name="sub_pin" required placeholder="Min 6 characters" minlength="6" style="width:100%;padding:7px;border-radius:6px;border:1px solid #cbd5e1;margin-top:3px"></label>
        <button type="submit" class="btn ok" style="padding:8px 14px;font-size:12.5px;font-weight:700">✓ Save &amp; Generate Sub-Agent Code</button>
      </form>
    </div>

    <!-- SUB-AGENTS TABLE -->
    <?php if ($mySubAgents === []): ?>
      <p class="muted" style="margin:0;font-size:13px">No counter staff registered under this account yet. Click <b>＋ Register Counter Staff</b> to add shop staff who can sell tickets under your agency.</p>
    <?php else: ?>
      <div style="overflow-x:auto">
        <table class="dt" style="width:100%;font-size:12.5px;margin-bottom:14px">
          <thead><tr>
            <th>Staff Name</th><th>Login Username</th><th>Mobile</th><th>Tickets Sold</th><th>Revenue (₹)</th><th>Cash Held (₹)</th><th>Status</th><th>Counter Actions</th>
          </tr></thead>
          <tbody>
            <?php foreach ($mySubAgents as $ms): 
              $subDigits = preg_replace('/\D/', '', (string)$ms['phone']);
              if (strlen($subDigits) === 10) { $subDigits = '91' . $subDigits; }
              $subWaMsg = "Namaste " . $ms['full_name'] . "! Here are your S Hari Global counter booking credentials:\n"
                        . "• Portal: https://www.shreehariglobal.in/admin/login.php\n"
                        . "• Username: @" . $ms['username'] . "\n"
                        . "• Agency: " . ($viewing['full_name'] ?: $viewing['username']) . "\n"
                        . "• Agency Code: " . AgentWallet::agentCodeLabel($viewId) . "\n"
                        . "Keep your password secure.";
              $subWaLink = "https://wa.me/" . $subDigits . "?text=" . urlencode($subWaMsg);
            ?>
              <tr>
                <td><b><?= Security::e($ms['full_name']) ?></b></td>
                <td class="mono">@<?= Security::e($ms['username']) ?></td>
                <td class="mono"><?= Security::e($ms['phone']) ?></td>
                <td class="num"><b><?= (int)$ms['sales'] ?></b></td>
                <td class="num">₹<?= number_format((float)$ms['revenue']) ?></td>
                <td class="num"><b style="color:<?= (float)$ms['cash_held'] > 0 ? '#b02a2a' : '#0a6b3b' ?>">₹<?= number_format((float)$ms['cash_held']) ?></b></td>
                <td><?= (int)$ms['is_active'] === 1 ? '<span class="pill" style="background:#d7f4e3;color:#0a6b3b;font-size:10.5px">Active</span>' : '<span class="pill" style="background:#fee2e2;color:#991b1b;font-size:10.5px">Paused</span>' ?></td>
                <td>
                  <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                    <?php if ((float)$ms['cash_held'] > 0): ?>
                      <button type="button" class="btn" style="background:#0a8b4b;color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;font-weight:700" onclick="openSubHandoverModal(<?= (int)$ms['id'] ?>, '<?= Security::e(addslashes($ms['full_name'])) ?>', <?= (float)$ms['cash_held'] ?>)">📥 Handover Cash</button>
                    <?php endif; ?>
                    <a href="<?= $subWaLink ?>" target="_blank" rel="noopener" class="btn" style="background:#25d366;color:#fff;font-size:11px;padding:3px 8px;border-radius:6px;text-decoration:none;font-weight:700">📲 WA Login</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('<?= (int)$ms['is_active'] === 1 ? 'Pause booking access for ' : 'Activate booking access for ' ?><?= Security::e(addslashes($ms['full_name'])) ?>?')">
                      <?= Security::csrfField() ?>
                      <input type="hidden" name="action" value="toggle_subagent">
                      <input type="hidden" name="sub_agent_id" value="<?= (int)$ms['id'] ?>">
                      <button type="submit" class="btn ghost" style="font-size:11px;padding:3px 7px"><?= (int)$ms['is_active'] === 1 ? '⏸ Pause' : '▶ Resume' ?></button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- SUB-AGENT CASH HANDOVER MODAL -->
      <div class="id-preview-modal" id="subHandoverModal" hidden onclick="if(event.target===this)this.hidden=true">
        <div class="id-preview-box" style="max-width:440px">
          <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line);padding-bottom:10px;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;color:#0d3b66">📥 Record Counter Cash Handover</h3>
            <button type="button" class="btn ghost" onclick="document.getElementById('subHandoverModal').hidden=true">&times;</button>
          </div>
          <form method="post">
            <?= Security::csrfField() ?>
            <input type="hidden" name="action" value="subagent_handover">
            <input type="hidden" name="sub_agent_id" id="shSubId" value="">
            <div style="display:flex;flex-direction:column;gap:12px;font-size:13px">
              <div>Counter Staff: <b id="shSubName">—</b></div>
              <div>Current Cash Held: <b id="shSubHeld" style="color:#b02a2a">₹0</b></div>
              <label>Amount Handed Over (₹)
                <input type="number" step="1" min="1" name="amount" id="shSubAmt" required style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
              </label>
              <label>Handover Note
                <input type="text" name="note" value="Cash collected at counter handed over to agency" required style="width:100%;padding:8px;border:1px solid var(--line);border-radius:6px;margin-top:4px">
              </label>
              <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:6px">
                <button type="button" class="btn ghost" onclick="document.getElementById('subHandoverModal').hidden=true">Cancel</button>
                <button type="submit" class="btn ok">✓ Record Handover</button>
              </div>
            </div>
          </form>
        </div>
      </div>
      <script>
      function openSubHandoverModal(id, name, held) {
        document.getElementById('shSubId').value = id;
        document.getElementById('shSubName').textContent = name;
        document.getElementById('shSubHeld').textContent = '₹' + Number(held).toLocaleString('en-IN');
        document.getElementById('shSubAmt').value = held;
        document.getElementById('shSubAmt').max = held;
        document.getElementById('subHandoverModal').hidden = false;
      }
      </script>

      <?php if ($subAgentBookings !== []): ?>
        <h4 style="margin:12px 0 6px;font-size:13px;color:#0d3b66">Recent Tickets Sold by Your Counter Staff:</h4>
        <div style="overflow-x:auto">
          <table class="dt" style="width:100%;font-size:12px">
            <thead><tr>
              <th>PNR</th><th>Staff Member</th><th>Journey</th><th>Date</th><th>Seats</th><th>Amount</th><th>Status</th>
            </tr></thead>
            <tbody>
              <?php foreach ($subAgentBookings as $sb): ?>
                <tr>
                  <td class="mono"><b><a href="/admin/booking-view.php?pnr=<?= urlencode((string)$sb['pnr']) ?>"><?= Security::e((string)$sb['pnr']) ?></a></b></td>
                  <td><b><?= Security::e($sb['booked_by_staff']) ?></b></td>
                  <td><?= Security::e(($sb['from_city'] ?? '—') . ' → ' . ($sb['to_city'] ?? '—')) ?></td>
                  <td><?= Security::e(formatDate($sb['travel_date'] ?? null)) ?></td>
                  <td class="mono"><?= Security::e((string)($sb['seats'] ?? '—')) ?></td>
                  <td class="num">₹<?= number_format((float)$sb['total_amount']) ?></td>
                  <td><span class="pill" style="background:#d7f4e3;color:#0a6b3b;font-size:10px"><?= ucfirst((string)$sb['status']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  </div>
</div>

<div class="dash-panel" data-pane="sales">
  <div class="dp-head">📊 Bookings — Last 7 Days</div>
  <div class="dp-body">
    <div class="spark-wrap">
      <?php foreach ($spark as $s): $h = max(4, ($s['val'] / $sparkMax) * 100); ?>
      <div class="spark-bar" style="height:<?= $h ?>%">
        <div class="spark-tip"><?= (int) $s['val'] ?> booking<?= (int) $s['val'] === 1 ? '' : 's' ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="spark-labels">
      <?php foreach ($spark as $s): ?>
      <span><?= $s['day'] ?><br><?= $s['date'] ?></span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ============ COMMISSION TAB: target, badges, rank, months ============
     Read from AgentTargets (one definition of a sale, shared with the
     leaderboard) and the ledger. Nothing here moves money: the office
     sets targets and posts a bonus from Agent 360 → Targets. -->
<?php
$ckT    = $tgt['target'];
$ckBar  = $tgt['pct'] >= 100 ? '#0a8b4b' : ($tgt['pct'] >= 60 ? '#d68910' : '#c0392b');
$ckAll  = [
    ['icon' => '🏆', 'label' => 'Top Seller', 'why' => 'Highest confirmed sales of all agents this month'],
    ['icon' => '🎯', 'label' => '100% Target', 'why' => 'Reach this month\'s target'],
    ['icon' => '✨', 'label' => 'Zero Cancellation', 'why' => '5+ tickets this month and none cancelled'],
];
$ckHave = array_column($badges, 'label');
?>
<div class="panel" id="targetBox" data-pane="commission">
  <h2>🎯 <?= Security::e($tgt['label']) ?> target · लक्ष्य
    <?php if ($ckCan360): ?><a class="btn ghost" href="/admin/agent-360.php?agent=<?= $viewId ?>&amp;tab=targets" style="margin-left:auto;padding:6px 12px;font-size:12.5px">Set target / bonus →</a><?php endif; ?>
  </h2>
  <div style="padding:16px 18px">
    <?php if ((float) $ckT['amount'] > 0): ?>
      <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:8px;margin-bottom:8px">
        <span style="font-size:22px;font-weight:800"><?= Security::e(inr($tgt['sales']['money'])) ?> <span class="muted" style="font-size:14px;font-weight:600">of <?= Security::e(inr((float) $ckT['amount'])) ?></span></span>
        <strong style="font-size:20px;color:<?= $ckBar ?>"><?= (int) $tgt['pct'] ?>%</strong>
      </div>
      <div style="height:14px;border-radius:99px;background:var(--line);overflow:hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= min(100, (int) $tgt['pct']) ?>">
        <div style="width:<?= min(100, (int) $tgt['pct']) ?>%;height:100%;background:<?= $ckBar ?>;transition:width .4s"></div>
      </div>
      <div class="ck-kv">
        <div><span>Remaining · बाँकी</span><b><?= $tgt['remaining'] > 0 ? Security::e(inr($tgt['remaining'])) : '✓ पूरा' ?></b></div>
        <div><span>Days left · दिन</span><b><?= (int) $tgt['days_left'] ?></b></div>
        <div><span>Needed per day</span><b><?= $tgt['per_day'] > 0 ? Security::e(inr($tgt['per_day'])) : '—' ?></b></div>
        <?php if ((int) $ckT['bookings'] > 0): ?>
          <div><span>Tickets</span><b><?= (int) $tgt['sales']['confirmed'] ?> / <?= (int) $ckT['bookings'] ?></b></div>
        <?php endif; ?>
        <?php if ((float) $ckT['bonus_pct'] > 0): ?>
          <div><span>Bonus <?= Security::e(rtrim(rtrim(number_format((float) $ckT['bonus_pct'], 2), '0'), '.')) ?>% above target</span>
            <b style="color:<?= $tgt['bonus'] > 0 ? '#0a6b3b' : 'var(--mut)' ?>"><?= Security::e(inr($tgt['bonus'])) ?></b></div>
        <?php endif; ?>
      </div>
      <p class="muted" style="font-size:12px;margin:0">
        <?php if ($isOwn): ?>
          <?= $ckT['source'] === 'agent' ? 'Your own target, set by the office.' : 'Company target — the office has not set one just for you.' ?>
          Counts confirmed sales on your code, by booking date.
          <?php if ((float) $ckT['bonus_pct'] > 0): ?>The bonus is credited to your wallet by the office after the month ends.<?php endif; ?>
        <?php else: ?>
          <?= $ckT['source'] === 'agent' ? 'This agent\'s own target.' : 'Company default target (agent_target_monthly).' ?>
          Counts confirmed sales on this agent's code, by booking date.
          <?php if ((float) $ckT['bonus_pct'] > 0): ?>Post the bonus from Agent 360 → Targets once the month ends.<?php endif; ?>
        <?php endif; ?>
        <?= $ckT['note'] !== '' ? '· ' . Security::e($ckT['note']) : '' ?>
      </p>
    <?php else: ?>
      <p class="muted" style="margin:0">No target for <?= Security::e($tgt['label']) ?> yet. This month: <?= Security::e(inr($tgt['sales']['money'])) ?> from <?= (int) $tgt['sales']['confirmed'] ?> confirmed tickets.</p>
    <?php endif; ?>

    <h3 style="font-size:14px;margin:18px 0 8px">Badges · पदक</h3>
    <div class="ck-badges">
      <?php foreach ($ckAll as $b): $on = in_array($b['label'], $ckHave, true); ?>
        <span class="ck-badge<?= $on ? '' : ' off' ?>" title="<?= Security::e($b['why']) ?>"><?= $b['icon'] ?> <?= Security::e($b['label']) ?><?= $on ? ' ✓' : '' ?></span>
      <?php endforeach; ?>
    </div>

    <?php if ($rank['rank'] > 0): ?>
      <p style="margin:16px 0 0;padding:12px 14px;border-radius:12px;background:var(--head);border:1px solid var(--line);font-size:14px">
        <?php if ($rank['rank'] === 1 && $tgt['sales']['money'] > 0): ?>
          🥇 <?= $isOwn ? 'तपाईं' : 'This agent is' ?> <b>#1</b> of <?= (int) $rank['of'] ?> agents this month — <?= $isOwn ? 'सबैभन्दा अगाडि हुनुहुन्छ!' : 'ahead of everyone.' ?>
        <?php elseif ($rank['gap'] > 0): ?>
          📈 <?= $isOwn ? 'तपाईं' : 'This agent is' ?> <b>#<?= (int) $rank['rank'] ?></b> of <?= (int) $rank['of'] ?> — <b>#<?= (int) $rank['ahead'] ?></b> सँग <b><?= Security::e(inr($rank['gap'])) ?></b> को फरक छ।
        <?php else: ?>
          🚀 <?= (int) $rank['of'] ?> agents ranked by confirmed sales this month — the first sale puts <?= $isOwn ? 'you' : 'this agent' ?> on the board.
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>
</div>

<div class="panel" data-pane="commission">
  <h2>💰 Commission · कमिसन</h2>
  <div class="ck-kv" style="padding:0 18px">
    <div><span>Pay rate</span><b><?= Security::e($payLabel) ?></b><div class="muted" style="font-size:11.5px"><?= Security::e($paySub) ?></div></div>
    <div><span>Earned this month</span><b><?= Security::e(inr($summary['earnedMonth'])) ?></b></div>
    <div><span>Commission due</span><b><?= Security::e(inr($balances['commission'])) ?></b></div>
    <div><span>This week</span><b><?= (int) $stats['weekCount'] ?> tickets</b></div>
  </div>
  <div class="tbl-scroll" style="overflow-x:auto">
    <table>
      <thead><tr><th>Month</th><th style="text-align:right">Earned</th><th style="text-align:right">Reversed</th><th style="text-align:right">Paid out</th><th style="text-align:right">Salary / bonus / adv.</th><th style="text-align:right">Net</th></tr></thead>
      <tbody>
        <?php foreach ($commMonths as $m):
          $ckNet = (float) $m['net']; ?>
          <tr>
            <td><?= Security::e(date('M Y', (int) strtotime($m['month'] . '-01'))) ?></td>
            <td style="text-align:right"><?= Security::e(inr((float) $m['earned'])) ?></td>
            <td style="text-align:right"><?= (float) $m['reversed'] > 0 ? '−' . Security::e(inr((float) $m['reversed'])) : '<span class="muted">—</span>' ?></td>
            <td style="text-align:right"><?= (float) $m['paidOut'] > 0 ? '−' . Security::e(inr((float) $m['paidOut'])) : '<span class="muted">—</span>' ?></td>
            <td style="text-align:right"><?= (float) $m['adjustment'] != 0.0 ? ((float) $m['adjustment'] < 0 ? '−' : '+') . Security::e(inr(abs((float) $m['adjustment']))) : '<span class="muted">—</span>' ?></td>
            <td style="text-align:right;font-weight:800;color:<?= $ckNet < 0 ? '#b02a2a' : '#0a6b3b' ?>"><?= $ckNet < 0 ? '−' : '+' ?><?= Security::e(inr(abs($ckNet))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($isOwn): ?>
<!-- ============ WALLET TAB: hand over today's cash (agent's own view) ============
     The agent cannot record a handover themselves — the office records it
     when the cash arrives and the receipt number lands in the timeline
     below. This box says how much, how late, and opens WhatsApp to the office. -->
<div class="panel" id="cashBox" data-pane="wallet">
  <h2>💵 आजको Cash जम्मा गर · Hand over cash</h2>
  <div style="padding:14px 18px">
    <div class="ck-kv" style="margin-top:0">
      <div><span>Cash in hand · हातमा</span><b style="color:<?= $ckCashHot ? '#b02a2a' : 'var(--ink)' ?>"><?= Security::e(inr($ckCash)) ?></b></div>
      <?php if ((float) $profile['cash_limit'] > 0): ?>
        <div><span>Your cash limit</span><b><?= Security::e(inr((float) $profile['cash_limit'])) ?></b></div>
      <?php endif; ?>
      <div><span>Handed over so far</span><b><?= Security::e(inr($summary['handedOver'])) ?></b></div>
      <?php if ($overdueDays > 0): ?>
        <div><span>Overdue · ढिलो</span><b style="color:#b02a2a"><?= (int) $overdueDays ?> दिन</b></div>
      <?php endif; ?>
    </div>
    <?php if ($ckCash > 0.009): ?>
      <p style="margin:0 0 12px;font-size:14px">Office मा <b><?= Security::e(inr($ckCash)) ?></b> बुझाउनुहोस् (cash, bank वा UPI)। Office ले receive गरेपछि receipt नम्बर तलको timeline मा <span class="pill" style="background:#e1ecfb;color:#1f4f96">Settled</span> भएर देखिन्छ।</p>
      <?php if ($officeWa !== ''):
        $ckWaMsg = 'Namaste office, म ' . (string) ($viewing['full_name'] ?: $viewing['username']) . ' (' . AgentWallet::agentCodeLabel($viewId) . '). '
                 . 'आज ' . inr($ckCash) . ' cash जम्मा गर्न चाहन्छु। कसरी पठाऊँ — office मा आऊँ कि bank/UPI?'; ?>
        <a class="btn" href="https://wa.me/<?= Security::e($officeWa) ?>?text=<?= rawurlencode($ckWaMsg) ?>" target="_blank" rel="noopener" style="background:#25d366;color:#fff;min-height:48px;display:inline-flex;align-items:center;padding:0 18px;font-weight:700">📲 Office लाई WhatsApp गर्नुहोस्</a>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted" style="margin:0">✓ अहिले हातमा company को cash छैन — केही जम्मा गर्न बाँकी छैन।</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<?php if ($canSettle && !($viewId === $selfId && !Auth::isSuperadmin())): ?>
<div class="panel" id="settleBox" data-pane="wallet">
  <h2>🏦 Settle this agent</h2>
  <div style="padding:16px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:18px">
    <?php if ($deposit['required'] > 0 || $deposit['paid'] > 0): ?>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="record_deposit">
      <strong style="font-size:13px">🔐 Record security deposit</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        Company holds <?= Security::e(inr($deposit['paid'])) ?> of <?= Security::e(inr($deposit['required'])) ?>.
        <?php if (!$deposit['met']): ?><strong style="color:#b06a00"><?= Security::e(inr($deposit['short'])) ?> still to collect.</strong><?php endif; ?>
      </div>
      <div class="row-actions">
        <input type="number" name="deposit_amount" step="0.01"
               placeholder="Amount received" required style="width:150px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn ok" type="submit">Record deposit</button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:6px">Use a minus figure to refund a deposit when the agent leaves.</div>
    </form>
    <?php endif; ?>
    <?php if ($salary > 0): ?>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="post_salary">
      <strong style="font-size:13px">Post monthly salary</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        <?= Security::e(inr($salary)) ?> per month · credits the commission
        account, then pay it out below.
      </div>
      <div class="row-actions">
        <input type="month" name="salary_month" value="<?= Security::e($thisMonth) ?>"
               max="<?= Security::e($thisMonth) ?>" required
               style="width:150px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit" <?= $salaryDone ? 'disabled' : '' ?>>
          <?= $salaryDone ? '✓ ' . Security::e($thisMonth) . ' done' : 'Credit salary' ?>
        </button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:6px">
        Each month can be posted once — a repeat is refused, so a double
        click cannot pay twice.
      </div>
    </form>
    <?php endif; ?>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="payout">
      <strong style="font-size:13px">Pay commission to the agent</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px"><?= Security::e(inr($balances['commission'])) ?> currently owed</div>
      <div class="row-actions">
        <input type="number" name="amount" step="0.01" min="0.01" max="<?= Security::e(number_format($balances['commission'], 2, '.', '')) ?>"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="ref" maxlength="80" placeholder="UPI / voucher ref"
               style="flex:1;min-width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn ok" type="submit" <?= $balances['commission'] <= 0 ? 'disabled' : '' ?>>Record payout</button>
      </div>
    </form>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="cash_handover">
      <strong style="font-size:13px">Accept cash from the agent</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px"><?= Security::e(inr($balances['cash'])) ?> currently held</div>
      <div class="row-actions">
        <input type="number" name="amount" step="0.01" min="0.01" max="<?= Security::e(number_format($balances['cash'], 2, '.', '')) ?>"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="ref" maxlength="80" placeholder="Receipt no"
               style="flex:1;min-width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit" <?= $balances['cash'] <= 0 ? 'disabled' : '' ?>>Record handover</button>
      </div>
    </form>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="advance">
      <strong style="font-size:13px">💸 Advance against future commission</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        <?php if ($advance['outstanding'] > 0): ?>
          <?= Security::e(inr($advance['outstanding'])) ?> still to recover from this agent’s commission.
        <?php else: ?>
          Money paid ahead of earnings — it auto-deducts from future commission until settled.
        <?php endif; ?>
      </div>
      <div class="row-actions">
        <select name="advance_kind" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
          <option value="given">Advance given</option>
          <option value="repaid">Repayment / correction</option>
        </select>
        <input type="number" name="amount" step="0.01" min="0.01"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="ref" maxlength="60" placeholder="Voucher / note ref"
               style="flex:1;min-width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit">Record advance</button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:6px">
        Unlike a payout, an advance may exceed the commission owed today — it is fronted before it is earned.
      </div>
    </form>
  </div>
  <p class="muted" style="padding:0 18px 14px;font-size:12px;margin:0">
    Cancelling a booking takes its commission back automatically. Cash is never reversed automatically —
    whether the agent refunded the passenger from their drawer or still holds the money is something only
    the counter knows, so record it here.
  </p>
</div>
<?php endif; ?>

<div class="panel" id="walletBox" data-pane="wallet">
  <h2>📜 Wallet history · कारोबार
    <span class="muted" style="font-weight:400;font-size:12.5px">· newest first · last <?= count($ledger) ?></span></h2>
  <?php /* Date-ranged statement export (Point 6) — pull one agent's ledger for
           a chosen window as a printable page or a CSV "mini bank statement". */ ?>
  <form method="get" class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;align-items:end;padding:0 18px 6px">
    <?php if ($isSupervisor): ?><input type="hidden" name="agent" value="<?= $viewId ?>"><?php endif; ?>
    <div><label class="muted" style="font-size:11px;display:block">From</label>
      <input type="date" name="from" value="<?= Security::e(date('Y-m-01')) ?>" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px"></div>
    <div><label class="muted" style="font-size:11px;display:block">To</label>
      <input type="date" name="to" value="<?= Security::e(todayISO()) ?>" style="padding:7px 9px;border:1px solid var(--line);border-radius:8px"></div>
    <button class="btn ok" type="submit" name="export" value="ledger">⬇ Download CSV</button>
    <button class="btn" type="button" onclick="window.print()">🖨 Print</button>
  </form>
  <?php if ($ledger === []): ?>
    <p class="muted" style="padding:22px;text-align:center;margin:0">Nothing yet. Commission appears here the moment a sale is confirmed.</p>
  <?php else: ?>
  <ol class="ck-tl">
    <?php foreach ($ledger as $l):
      [$icon, $label, $colour] = ledger_look((string) $l['entry_type'], (string) ($l['ref'] ?? ''));
      $amt = (float) $l['amount'];
      [$tCls, $tTag] = ledger_tag((string) $l['entry_type'], $amt, (string) ($l['ref'] ?? ''));
    ?>
      <li class="<?= $tCls ?>">
        <span class="tgw"><span class="tg"><?= Security::e($tTag) ?></span></span>
        <div>
          <div class="what" style="color:<?= $colour ?>"><?= $icon ?> <?= Security::e($label) ?>
            <?php if (!empty($l['pnr'])): ?>
              · <a class="mono" href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $l['pnr']) ?>"><?= Security::e((string) $l['pnr']) ?></a>
            <?php elseif ((string) ($l['ref'] ?? '') !== ''): ?>
              <span class="mono muted" style="font-weight:600"> · <?= Security::e((string) $l['ref']) ?></span>
            <?php endif; ?></div>
          <div class="sub"><?= Security::e(ucfirst((string) $l['account'])) ?> account<?= $l['by_name'] ? ' · by ' . Security::e((string) $l['by_name']) : '' ?><?= (string) ($l['note'] ?? '') !== '' ? ' · ' . Security::e(truncate((string) $l['note'], 90)) : '' ?></div>
        </div>
        <div class="amt" style="color:<?= $amt >= 0 ? '#0a6b3b' : '#b02a2a' ?>"><?= $amt >= 0 ? '+' : '−' ?><?= Security::e(inr(abs($amt))) ?>
          <small title="<?= Security::e((string) $l['created_at']) ?>"><?= Security::e(timeAgo((string) $l['created_at'])) ?></small></div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</div>

<?php if ($canManage): ?>
<!-- ============ LOGIN CREDENTIALS (4 Sep 2026) ============
     Agents sign in with EMAIL + USERNAME + PASSWORD, all three set here by
     the office. Nothing on this page can read an existing password back —
     bcrypt hashes are one-way — so "reset" means "type a new one". Every
     change writes an agent.credentials audit row (old/new username and
     email; the password itself is never logged).                        -->
<div class="panel" id="loginCredentials" data-pane="profile">
  <h2>🔑 Login credentials
    <span class="muted" style="font-weight:400">· email + username + password</span>
  </h2>
  <?php
    $lockedUntil = (string) ($viewing['locked_until'] ?? '');
    $isLocked    = $lockedUntil !== '' && strtotime($lockedUntil) > time();
  ?>
  <div style="padding:0 18px 6px;display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--mut)">
    <span>Email on file: <b style="color:var(--ink)"><?= Security::e((string) ($viewing['email'] ?? '')) ?: '<span style="color:#b02a2a">none — they cannot sign in yet</span>' ?></b></span>
    <span>Username: <b class="mono" style="color:var(--ink)"><?= Security::e((string) $viewing['username']) ?></b></span>
    <span>Last sign-in: <b style="color:var(--ink)"><?= $viewing['last_login_at'] ? Security::e(timeAgo((string) $viewing['last_login_at'])) : 'never' ?></b></span>
    <?php if ((int) ($viewing['must_change_pw'] ?? 0) === 1): ?><span class="pill" style="background:#fff4d1;color:#8a6d00">Must change password at next login</span><?php endif; ?>
    <?php if ($isLocked): ?><span class="pill" style="background:#f7dcdc;color:#8a1f1f">Locked until <?= Security::e(formatDate($lockedUntil, 'j M, g:i A')) ?> — saving a new password unlocks it</span><?php endif; ?>
  </div>
  <form method="post" style="padding:10px 18px 18px" autocomplete="off"
        onsubmit="return confirm('Save these login details for <?= Security::e(addslashes((string) ($viewing['full_name'] ?: $viewing['username']))) ?>?')">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="agent_id" value="<?= $viewId ?>">
    <input type="hidden" name="action" value="credentials">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <label style="font-size:12px;color:var(--mut)">Email <span class="muted">· part of their sign-in</span>
        <input type="email" name="login_email" maxlength="191" value="<?= Security::e((string) ($viewing['email'] ?? '')) ?>"
               placeholder="agent@example.com" autocomplete="off"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Username
        <input type="text" name="login_username" maxlength="60" required value="<?= Security::e((string) $viewing['username']) ?>"
               autocapitalize="none" autocorrect="off" autocomplete="off"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
        <span class="muted" style="font-size:11px">3–60 characters: letters, numbers, . _ - @</span></label>
      <label style="font-size:12px;color:var(--mut)">New password <span class="muted">· leave blank to keep the current one</span>
        <input type="text" name="login_password" minlength="8" maxlength="72" placeholder="min 8 characters" autocomplete="new-password"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
        <span class="muted" style="font-size:11px">Shown as you type so you can read it out — it is never stored or logged in plain text.</span></label>
    </div>
    <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center;margin-top:14px">
      <label style="font-size:12.5px;display:flex;gap:7px;align-items:center">
        <input type="checkbox" name="must_change" value="1" <?= (int) ($viewing['must_change_pw'] ?? 0) === 1 ? 'checked' : '' ?> style="width:17px;height:17px">
        Make them choose their own password at next sign-in</label>
      <label style="font-size:12.5px;display:flex;gap:7px;align-items:center">
        <input type="checkbox" name="notify_agent" value="1" style="width:17px;height:17px">
        Send the details to their WhatsApp</label>
      <button class="btn" type="submit" style="margin-left:auto">💾 Save login details</button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ============ PROFILE ============ -->
<div class="panel" id="profileBox" data-pane="profile">
  <h2>👤 Profile — <?= Security::e((string) ($viewing['full_name'] ?: $viewing['username'])) ?>
    <span class="muted" style="font-weight:400">· <?= Security::e(ucfirst((string) $viewing['role'])) ?><?= (int) $viewing['is_active'] === 1 ? '' : ' · inactive' ?></span>
  </h2>
  <form method="post" enctype="multipart/form-data" style="padding:16px 18px">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="agent_id" value="<?= $viewId ?>">
    <input type="hidden" name="action" value="profile">

    <?php $photo = (string) ($profile['photo_path'] ?? ''); ?>
    <div style="display:flex;gap:16px;align-items:center;margin-bottom:16px;flex-wrap:wrap">
      <?php if ($photo !== ''): ?>
        <img src="<?= $base ?>/uploads/<?= Security::e($photo) ?>" alt="Agent photo" width="72" height="72"
             style="width:72px;height:72px;object-fit:cover;border-radius:50%;border:2px solid var(--line)">
      <?php else: ?>
        <div style="width:72px;height:72px;border-radius:50%;background:var(--head);border:2px dashed var(--line);
                    display:flex;align-items:center;justify-content:center;font-size:28px">👤</div>
      <?php endif; ?>
      <label style="font-size:12px;color:var(--mut)">Photo
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp"
               style="display:block;margin-top:4px;font-size:12px">
        <span class="muted" style="font-size:11px">JPG, PNG or WEBP<?= $photo !== '' ? ' · choosing a new one replaces the current photo' : '' ?></span>
      </label>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
      <?php if ($canManage): ?>
      <label style="font-size:12px;color:var(--mut)">Agent type <span class="muted">(serial band)</span>
        <select name="agent_kind"
                style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
          <option value="org"    <?= (string) $profile['agent_kind'] !== 'person' ? 'selected' : '' ?>>Organization — serial 1–20</option>
          <option value="person" <?= (string) $profile['agent_kind'] === 'person' ? 'selected' : '' ?>>Person — serial 21+</option>
        </select>
        <span class="muted" style="font-size:11px">Switching keeps the current serial, wallet and commission tier.</span></label>
      <label style="font-size:12px;color:var(--mut)">Contact person <span class="muted">(organizations)</span>
        <input type="text" name="contact_person" maxlength="120" value="<?= Security::e((string) $profile['contact_person']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <?php endif; ?>
      <label style="font-size:12px;color:var(--mut)">Mobile
        <input type="text" name="display_phone" maxlength="20" value="<?= Security::e((string) $profile['display_phone']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">WhatsApp <span class="muted">(blank = same as mobile)</span>
        <input type="text" name="whatsapp" maxlength="15" inputmode="numeric" value="<?= Security::e((string) $profile['whatsapp']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Email
        <input type="email" name="display_email" maxlength="191" value="<?= Security::e((string) $profile['display_email']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Counter / branch
        <input type="text" name="counter_name" maxlength="120" value="<?= Security::e((string) $profile['counter_name']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Address
        <input type="text" name="address" maxlength="255" value="<?= Security::e((string) $profile['address']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Payout method
        <input type="text" name="payout_method" maxlength="40" placeholder="upi / bank / cash" value="<?= Security::e((string) $profile['payout_method']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <label style="font-size:12px;color:var(--mut)">Payout account / UPI id
        <input type="text" name="payout_account" maxlength="120" value="<?= Security::e((string) $profile['payout_account']) ?>"
               style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>

      <?php if ($canManage):
        // $viewId, not $targetId — $targetId only exists after a POST, and the
        // error handler turns the undefined-variable warning into a 500 on GET.
        $agentType = AgentWallet::agentTypeFor($viewId);
        $rateDirect = Settings::getFloat('agent_flat_direct', 200.0);
        $rateJoint  = Settings::getFloat('agent_flat_joint', 400.0);
        $flatOn     = AgentWallet::flatMode();
      ?>
        <?php
          $myCode   = AgentWallet::agentCodeFor($viewId);
          $myKind   = (string) ($profile['agent_kind'] ?? AgentWallet::KIND_ORG);
          $freeCode = AgentWallet::nextFreeAgentCode($myKind);
        ?>
        <label style="font-size:12px;color:var(--mut)">Agent number <span class="muted">(<?= AgentWallet::kindLabel($myKind) ?> band <?= AgentWallet::codeBandLabel($myKind) ?> · prints on every ticket they sell)</span>
          <input type="number" name="agent_code" min="<?= AgentWallet::AGENT_CODE_MIN ?>" max="<?= AgentWallet::AGENT_CODE_MAX ?>" step="1"
                 value="<?= $myCode === null ? '' : (int) $myCode ?>"
                 placeholder="<?= $freeCode === null ? 'all 1000 numbers issued' : 'next free: ' . (int) $freeCode ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
          <span class="muted" style="font-size:11px">
            <?= $myCode !== null
                ? 'Prints as <b>' . Security::e(AgentWallet::agentCodeLabel($viewId)) . '</b> on their tickets.'
                : 'Blank = no number yet. Each number can belong to only one agent.' ?>
          </span></label>
        <label style="font-size:12px;color:var(--mut)">Commission tier <span class="muted">(per passenger)</span>
          <select name="agent_type"
                  style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
            <option value="direct" <?= $agentType === 'direct' ? 'selected' : '' ?>>Direct agent — <?= Security::e(inr($rateDirect)) ?> / passenger</option>
            <option value="joint"  <?= $agentType === 'joint'  ? 'selected' : '' ?>>Team / organisation — <?= Security::e(inr($rateJoint)) ?> / passenger</option>
          </select>
          <span class="muted" style="font-size:11px">
            <?= $flatOn
                ? 'Paid per passenger on every confirmed sale. Edit the two amounts under “Tier rates” beside.'
                : 'Currently INACTIVE — agent_commission_mode is set to "percent", so the Commission % below is what pays.' ?>
          </span></label>
        <label style="font-size:12px;color:var(--mut)">Commission override <span class="muted">(this agent only)</span>
          <div style="display:flex;gap:8px;margin-top:4px">
            <select name="commission_override_mode"
                    onchange="var f=document.getElementById('ovFlat');if(f){f.style.display=this.value==='flat'?'':'none'}"
                    style="flex:1;min-width:0;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
              <option value=""        <?= $override['mode'] === null      ? 'selected' : '' ?>>Company tier default</option>
              <option value="flat"    <?= $override['mode'] === 'flat'    ? 'selected' : '' ?>>Flat ₹ per passenger</option>
              <option value="percent" <?= $override['mode'] === 'percent' ? 'selected' : '' ?>>% of fare</option>
            </select>
            <input type="number" id="ovFlat" name="commission_override_flat" min="0" max="10000" step="1"
                   value="<?= $override['flat'] !== null ? Security::e(number_format((float) $override['flat'], 2, '.', '')) : '' ?>"
                   placeholder="₹ / pax"
                   style="width:100px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)<?= $override['mode'] === 'flat' ? '' : ';display:none' ?>">
          </div>
          <span class="muted" style="font-size:11px">
            <?php if ($override['mode'] === 'flat'): ?>
              Active — this agent is paid <b><?= Security::e(inr((float) $override['flat'])) ?> per passenger</b>, ignoring the tier.
            <?php elseif ($override['mode'] === 'percent'): ?>
              Active — this agent is paid <b>% of fare</b> (the Commission % below), even while the tier pays everyone else.
            <?php else: ?>
              None — this agent follows the company tier above. Flat 0–10000, percent uses the Commission % field.
            <?php endif; ?>
          </span></label>
        <div style="font-size:12px;color:var(--mut)">Tier rates <span class="muted">(company-wide ₹ per passenger)</span>
          <div style="display:flex;gap:6px;margin-top:4px;align-items:center">
            <input form="tierRatesForm" type="number" name="rate_direct" min="0" max="10000" step="1" required
                   value="<?= (int) round($rateDirect) ?>" title="Direct agent — ₹ per passenger"
                   style="width:86px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
            <input form="tierRatesForm" type="number" name="rate_joint" min="0" max="10000" step="1" required
                   value="<?= (int) round($rateJoint) ?>" title="Team / organisation — ₹ per passenger"
                   style="width:86px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
            <button form="tierRatesForm" class="btn" type="submit" style="padding:8px 12px">Save</button>
          </div>
          <span class="muted" style="font-size:11px">Direct ₹ / team ₹ — changes what <b>every</b> agent on the company tier earns.</span>
        </div>
        <label style="font-size:12px;color:var(--mut)">Commission % <span class="muted">(blank = company <?= rtrim(rtrim(number_format(Settings::getFloat('agent_commission_percent', 5.0), 2), '0'), '.') ?>%<?= $flatOn ? ' · only used if the tier above is switched off' : '' ?>)</span>
          <input type="number" name="commission_percent" step="0.01" min="0" max="100"
                 value="<?= $profile['commission_percent'] === null ? '' : Security::e((string) $profile['commission_percent']) ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Security deposit required ₹ <span class="muted">(0 = none)</span>
          <input type="number" name="deposit_required" step="1" min="0" max="10000000"
                 value="<?= $deposit['required'] > 0 ? Security::e(number_format($deposit['required'], 2, '.', '')) : '' ?>"
                 placeholder="0"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Monthly salary ₹ <span class="muted">(0 = commission only)</span>
          <input type="number" name="monthly_salary" step="1" min="0" max="10000000"
                 value="<?= $salary > 0 ? Security::e(number_format($salary, 2, '.', '')) : '' ?>"
                 placeholder="0"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Cash-in-hand limit <span class="muted">(0 = none)</span>
          <input type="number" name="cash_limit" step="1" min="0" value="<?= Security::e(number_format((float) $profile['cash_limit'], 2, '.', '')) ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Joined on
          <input type="date" name="joined_on" value="<?= Security::e((string) ($profile['joined_on'] ?? '')) ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Office notes
          <input type="text" name="notes" maxlength="500" value="<?= Security::e((string) $profile['notes']) ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">ID document
          <select name="id_type" style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)">
            <option value="">— none —</option>
            <?php foreach (AgentWallet::ID_TYPES as $t): ?>
              <option value="<?= Security::e($t) ?>"<?= (string) $profile['id_type'] === $t ? ' selected' : '' ?>><?= Security::e($t) ?></option>
            <?php endforeach; ?>
          </select></label>
        <label style="font-size:12px;color:var(--mut)">ID number
          <input type="text" name="id_number" maxlength="60" value="<?= Security::e((string) $profile['id_number']) ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
        <label style="font-size:12px;color:var(--mut)">Bookings per day <span class="muted">(0 = no limit)</span>
          <input type="number" name="daily_booking_limit" step="1" min="0" value="<?= (int) $profile['daily_booking_limit'] ?>"
                 style="width:100%;margin-top:4px;padding:9px 11px;border:1px solid var(--line);border-radius:9px;background:var(--card);color:var(--ink)"></label>
      <?php endif; ?>
    </div>

    <?php if ($canManage):
      $allRoutes  = Database::fetchAll('SELECT id, route_code, from_city, to_city FROM routes ORDER BY is_active DESC, sort_order, id');
      $allowedIds = AgentWallet::routePermissions($viewId);
    ?>
      <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--line)">
        <div style="font-size:12px;color:var(--mut);font-weight:700;margin-bottom:8px">
          Route permissions
          <span class="muted" style="font-weight:400">— tick none to allow every route</span>
        </div>
        <?php /* Marks that the control was on the page. Without it, saving a
                 form that never rendered these boxes would read "no routes
                 ticked" and silently wipe an existing restriction. */ ?>
        <input type="hidden" name="routes_present" value="1">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px">
          <?php foreach ($allRoutes as $r): ?>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px">
              <input type="checkbox" name="routes[]" value="<?= (int) $r['id'] ?>"
                     <?= in_array((int) $r['id'], $allowedIds, true) ? 'checked' : '' ?>>
              <span><?= Security::e((string) $r['from_city']) ?> → <?= Security::e((string) $r['to_city']) ?>
                <span class="muted mono" style="font-size:11px"><?= Security::e((string) $r['route_code']) ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="muted" style="font-size:11.5px;margin-top:8px">
          <?= $allowedIds === []
              ? 'Currently unrestricted — this agent may sell every route.'
              : 'Restricted to ' . count($allowedIds) . ' route' . (count($allowedIds) === 1 ? '' : 's') . '.' ?>
        </div>
      </div>
    <?php endif; ?>
    <div style="margin-top:14px"><button class="btn" type="submit">Save profile</button>
      <?php if (!$canManage): ?>
        <span class="muted" style="font-size:12px;margin-left:10px">Commission rate and cash limit are set by the office.</span>
      <?php endif; ?>
    </div>
  </form>
  <?php if ($canManage): ?>
  <?php /* The "Tier rates" inputs above live inside the profile <form>, so
           they post through this separate form via the HTML5 form="" attribute
           — forms cannot nest, and the tier amounts must never ride along on
           an unrelated profile save. */ ?>
  <form id="tierRatesForm" method="post">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="agent_id" value="<?= $viewId ?>">
    <input type="hidden" name="action" value="tier_rates">
  </form>
  <?php endif; ?>
</div>

<!-- ============ SELL ============ -->
<div data-pane="sales"><?php require __DIR__ . '/_agent-journeys.php'; ?></div>
<div class="panel" data-pane="sales">
  <h2>🚌 Upcoming departures — sell seats
    <?php if (AgentWallet::offlineTicketsEnabled()): ?>
      <a class="btn" href="<?= $base ?>/admin/agent-offline.php" style="margin-left:auto;padding:5px 11px;font-size:12px">📝 Enter a paper ticket</a>
    <?php endif; ?>
  </h2>
  <table>
    <thead><tr><th>Date / Time</th><th>Route</th><th>Sold</th><th>Fill</th><th></th></tr></thead>
    <tbody>
      <?php if ($upcoming === []): ?>
        <tr><td colspan="5" class="muted" style="padding:20px">No upcoming trips scheduled.</td></tr>
      <?php else: foreach ($upcoming as $u):
        $tot  = (int) $u['total_seats'];
        $sold = (int) $u['sold'];
        $fpct = $tot > 0 ? (int) round($sold * 100 / $tot) : 0;
        $barC = $fpct >= 90 ? '#b02a2a' : ($fpct >= 60 ? '#d68910' : '#0a6b3b');
      ?>
        <tr>
          <td><strong><?= Security::e(formatDate($u['travel_date'])) ?></strong>
            <div class="muted"><?= Security::e(formatTime($u['dep_time'])) ?></div></td>
          <td><?= Security::e($u['from_city'] . ' → ' . $u['to_city']) ?>
            <div class="muted mono"><?= Security::e($u['route_code']) ?> · <?= Security::e(ucfirst((string) $u['coach_type'])) ?></div></td>
          <td><strong><?= $sold ?></strong><span class="muted"> / <?= $tot ?></span></td>
          <td style="min-width:110px">
            <div style="height:6px;border-radius:99px;background:var(--line);overflow:hidden" title="<?= $fpct ?>% full">
              <div style="width:<?= $fpct ?>%;height:100%;background:<?= $barC ?>"></div>
            </div>
            <div class="muted" style="font-size:11px;margin-top:2px"><?= $fpct ?>% full</div>
          </td>
          <td><a class="btn" href="<?= $base ?>/index.php?<?= Security::e(http_build_query(['counter' => 1, 'from' => (string) $u['from_city'], 'to' => (string) $u['to_city'], 'date' => (string) $u['travel_date']])) ?>#/" style="padding:6px 12px" title="Opens the booking app in counter mode: pick seats on the map, fill passengers, take cash/UPI">Sell seats →</a></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($papers !== []): ?>
<div class="panel" data-pane="sales">
  <h2>📝 Paper tickets entered</h2>
  <table>
    <thead><tr><th>Paper no</th><th>Passenger</th><th>Journey</th><th>Amount</th><th>Commission</th><th>Booking</th></tr></thead>
    <tbody>
      <?php foreach ($papers as $p): ?>
        <tr>
          <td class="mono"><?= Security::e((string) $p['paper_ticket_no']) ?>
            <div class="muted" style="font-size:11px"><?= Security::e(timeAgo((string) $p['created_at'])) ?></div></td>
          <td><?= Security::e((string) $p['passenger_name']) ?>
            <div class="muted mono" style="font-size:11px"><?= Security::e(maskPhone((string) ($p['passenger_phone'] ?? ''))) ?></div></td>
          <td><?= Security::e(($p['from_city'] ?? '—') . ' → ' . ($p['to_city'] ?? '—')) ?>
            <div class="muted"><?= Security::e(formatDate((string) $p['travel_date'])) ?><?= $p['seat_text'] ? ' · ' . Security::e((string) $p['seat_text']) : '' ?></div></td>
          <td><?= Security::e(inr((float) $p['amount'])) ?>
            <div class="muted" style="font-size:11px"><?= Security::e(strtoupper((string) $p['payment_mode'])) ?></div></td>
          <td><?= Security::e(inr((float) $p['commission'])) ?></td>
          <td class="mono"><?php if (!empty($p['pnr'])): ?>
              <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $p['pnr']) ?>"><?= Security::e((string) $p['pnr']) ?></a>
            <?php else: ?><span class="muted">register only</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel" data-pane="sales balance">
  <h2><?= $isOwn ? 'My recent sales' : 'Recent sales' ?></h2>
  <table class="card-table">
    <thead><tr><th>PNR</th><th>Route / Date</th><th>Seats</th><th>Passenger phone</th><th>Amount</th><th>Status</th><th>When</th></tr></thead>
    <tbody>
      <?php if ($recent === []): ?>
        <tr><td colspan="7" class="muted" style="padding:20px">
          No sales recorded yet.<?= $isOwn ? ' Use “Sell seat” above to book a passenger at the counter.' : '' ?>
        </td></tr>
      <?php else: foreach ($recent as $b): ?>
        <tr>
          <td class="mono" data-label="PNR"><a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $b['pnr']) ?>"><?= Security::e((string) $b['pnr']) ?></a></td>
          <td data-label="Route / Date"><span><?= Security::e(($b['from_city'] ?? '—') . ' → ' . ($b['to_city'] ?? '—')) ?>
            <div class="muted"><?= $b['travel_date'] ? Security::e(formatDate($b['travel_date'])) : '' ?></div></span></td>
          <td class="mono" data-label="Seats"><?php
            $agSeats = (string) ($b['seats'] ?? '');
            echo $agSeats === ''
                ? '—'
                : Security::e(implode(' ', array_map(
                    static fn($s) => Seats::displayLabel((string) $s),
                    explode(' ', $agSeats)
                )));
          ?></td>
          <td class="mono" data-label="Passenger phone"><?= Security::e(maskPhone((string) $b['contact_phone'])) ?></td>
          <td data-label="Amount"><?= Security::e(inr((float) $b['total_amount'])) ?></td>
          <td data-label="Status"><?= admin_pill((string) $b['status']) ?></td>
          <td class="muted" data-label="When"><?= Security::e(timeAgo((string) $b['created_at'])) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<p class="muted" data-pane="commission" style="font-size:12.5px">
  Commission is <?= Security::e($payLabel) ?> (<?= Security::e($paySub) ?>) on confirmed sales you made, written to
  the wallet the moment a sale is confirmed and reversed if it is later cancelled. Sales made before the wallet
  was installed have no ledger entry, so they show under “recent sales” but not in the balance.
</p>

<script>
/* Cockpit tabs (29 Oct 2026). Every section is already in the page and is
   tagged data-pane; a tab only flips html[data-ck-tab] and rewrites ?tab=
   (replaceState). Forms here have no action attribute, so a POST from the
   Wallet or Profile tab comes back to that same tab. The big buttons link
   to ?tab=…#box and are handled here too, without a reload. */
(function () {
  var root  = document.documentElement;
  var links = document.querySelectorAll('#ckTabs a[data-tab]');
  var keys  = Array.prototype.map.call(links, function (a) { return a.getAttribute('data-tab'); });
  function show(key, push) {
    if (keys.indexOf(key) < 0) { key = keys[0]; }
    root.setAttribute('data-ck-tab', key);
    links.forEach(function (a) { a.classList.toggle('on', a.getAttribute('data-tab') === key); });
    if (push) {
      try { var u = new URL(location.href); u.searchParams.set('tab', key); u.hash = ''; history.replaceState(null, '', u.toString()); } catch (e) {}
    }
  }
  function reveal(id) {
    var el = id ? document.getElementById(id) : null;
    if (!el) { window.scrollTo({ top: 0, behavior: 'smooth' }); return; }
    var pane = el.closest('[data-pane]');
    if (pane && !pane.matches('[data-pane~="' + root.getAttribute('data-ck-tab') + '"]')) {
      show(pane.getAttribute('data-pane').split(/\s+/)[0], true);
    }
    try { el.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
    var f = el.querySelector('input[type="tel"],input[type="number"]');
    if (f) { setTimeout(function () { f.focus({ preventScroll: true }); }, 300); }
  }
  links.forEach(function (a) {
    a.addEventListener('click', function (ev) { ev.preventDefault(); show(a.getAttribute('data-tab'), true); });
  });
  document.querySelectorAll('.ck-act[href], #targetBox a[href]').forEach(function (a) {
    a.addEventListener('click', function (ev) {
      var u;
      try { u = new URL(a.href, location.href); } catch (e) { return; }
      var here = new URL(location.href);
      if (u.pathname !== here.pathname || (u.searchParams.get('agent') || '') !== (here.searchParams.get('agent') || '')) { return; }
      ev.preventDefault();
      show(u.searchParams.get('tab') || keys[0], true);
      reveal(u.hash.replace('#', ''));
    });
  });
  // An old bookmark to #payoutRequest / #loginCredentials still lands on its section.
  if (location.hash) { reveal(location.hash.slice(1)); }
})();
</script>
<?php
admin_footer();
