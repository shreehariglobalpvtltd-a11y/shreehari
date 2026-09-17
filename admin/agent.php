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
 *
 * 17 Sep 2026: payout requests go through AgentWallet::requestPayout (a
 * durable agent_payout_requests row plus the same audit row as before); the
 * settle desk gained a Correction form (signed 'adjustment', ref 'ADJ …')
 * and the loans & advances register (issueLoan / repayLoan) in place of the
 * plain advance form; every payout / handover row links its printable
 * receipt (agent-receipt.php); the agent is told on WhatsApp when a
 * settlement is recorded; and supervisors get a "Full 360 view" button to
 * admin/agent-360.php. The duplicated "Request a payout" block is gone.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('bookings.view');
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
// The Agent 360 page is gated exactly like the agents register (office
// roles with customers.view + commissions.view) — offer the button only to
// someone it will actually open for.
$canSee360 = !Auth::isCounterAgent() && Auth::can('commissions.view') && Auth::can('customers.view');

/**
 * The agent's payout request still waiting for the office, or null.
 *
 * 17 Sep 2026: the durable agent_payout_requests row is the answer when the
 * migration has run (AgentWallet::openPayoutRequests). On an older database
 * the audit trail still decides, exactly as before — the newest
 * agent.payout_request row after the last payout — now also ignoring one
 * the office has already logged a decision on (paid / declined).
 *
 * @return array{id:int, amount:float, created_at:string}|null
 */
function agent_pending_payout_request(int $agentId): ?array
{
    $open = AgentWallet::openPayoutRequests($agentId);
    if ($open !== []) {
        return ['id' => (int) $open[0]['id'], 'amount' => (float) $open[0]['amount'], 'created_at' => (string) $open[0]['created_at']];
    }
    try {
        $row = Database::fetch(
            "SELECT id, new_value, created_at FROM audit_logs
              WHERE action = 'agent.payout_request' AND entity_type = 'admin' AND entity_id = :a
                AND created_at > COALESCE((SELECT MAX(created_at) FROM agent_ledger WHERE agent_admin_id = :b AND entry_type = 'payout'), '1970-01-01')
                AND created_at > COALESCE((SELECT MAX(created_at) FROM audit_logs
                                            WHERE action IN ('agent.payout_request_paid', 'agent.payout_request_declined')
                                              AND entity_type = 'admin' AND entity_id = :c), '1970-01-01')
              ORDER BY id DESC LIMIT 1",
            ['a' => (string) $agentId, 'b' => $agentId, 'c' => (string) $agentId]
        );
    } catch (Throwable $e) {
        return null;
    }
    if ($row === null) {
        return null;
    }
    $nv = json_decode((string) $row['new_value'], true) ?: [];
    return ['id' => 0, 'amount' => (float) ($nv['amount'] ?? 0), 'created_at' => (string) $row['created_at']];
}

/**
 * Tell the agent a payout / cash handover was recorded (17 Sep 2026,
 * Settings agent_notify_settlement, default on). Best effort — an outage
 * never undoes the ledger row. Returns a short suffix for the flash.
 */
function agent_settlement_notify(int $agentId, string $entryType, float $amount, int $ledgerId): string
{
    if (!Settings::getBool('agent_notify_settlement', true)) {
        return '';
    }
    try {
        $ag  = Database::fetch('SELECT full_name, phone FROM admins WHERE id = :id', ['id' => $agentId]);
        $pr  = AgentWallet::profile($agentId);
        $raw = (string) (($pr['whatsapp'] ?? '') ?: (string) ($ag['phone'] ?? ''));
        $to  = preg_replace('/\D/', '', $raw) ?? '';
        if (str_starts_with($to, '00')) {
            $to = substr($to, 2);
        }
        if ($to === '') {
            return ' No mobile on file, so the agent was not messaged.';
        }
        // India and Nepal share 10-digit mobiles: a typed +977/+91 wins, a
        // Nepali citizenship card as the ID hints NP, else the configured default.
        $country = resolvePhoneCountry('', $raw);
        if ($country === '' && (string) ($pr['id_type'] ?? '') === 'Citizenship') {
            $country = 'NP';
        }
        if (strlen($to) === 10) {
            $cc = countryDialCode($country) ?: (preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91');
            $to = $cc . $to;
        }
        $bal     = AgentWallet::balances($agentId);
        $voucher = AgentWallet::voucherFor($ledgerId);
        $company = Settings::getString('company_name', APP_NAME);
        $when    = formatDate(todayISO(), 'j M Y');
        $text    = $entryType === 'payout'
            ? $company . ': ' . inr($amount) . ' paid out to you on ' . $when . '.'
              . ($voucher !== '' ? ' Voucher ' . $voucher . '.' : '') . ' Commission balance now ' . inr($bal['commission']) . '.'
            : $company . ': cash handover of ' . inr($amount) . ' received from you on ' . $when . '.'
              . ($voucher !== '' ? ' Receipt ' . $voucher . '.' : '') . ' Cash in hand now ' . inr($bal['cash']) . '.';
        $res = Notify::whatsapp($to, $text, null, $country !== '' ? $country : null);
        return $res === true ? ' Agent told on WhatsApp.' : '';
    } catch (Throwable $e) {
        Logger::error('agent settlement notify failed', ['agent' => $agentId, 'e' => $e->getMessage()]);
        return '';
    }
}

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

                $settleAmt = round(abs((float) ($_POST['amount'] ?? 0)), 2);
                $ledgerId  = AgentWallet::record($targetId, $action, $settleAmt, [
                    'note' => $_POST['note'] ?? '',
                    'ref'  => $_POST['ref'] ?? '',
                    'by'   => $selfId,
                ]);
                $voucher = AgentWallet::voucherFor($ledgerId);

                // 17 Sep 2026: a payout that covers the agent's open request
                // settles it — the request is bookkeeping about this very
                // payment, not more money. No-op on an un-migrated database.
                if ($action === 'payout') {
                    foreach (AgentWallet::openPayoutRequests($targetId) as $oreq) {
                        if ($settleAmt + 0.009 >= (float) $oreq['amount']) {
                            try {
                                AgentWallet::decidePayoutRequest((int) $oreq['id'], 'paid', $selfId, 'Settled by payout ' . $voucher, $ledgerId);
                            } catch (Throwable $e) {
                                Logger::warning('payout request not auto-settled: ' . $e->getMessage(), [], 'agent');
                            }
                        }
                        break;   // requestPayout() allows one open request at a time
                    }
                }

                $flash = ['ok', ($action === 'payout' ? 'Commission payout recorded.' : 'Cash handover recorded.')
                    . ($voucher !== '' ? ' Voucher ' . $voucher . '.' : '')
                    . agent_settlement_notify($targetId, $action, $settleAmt, $ledgerId)];
                $viewId = $targetId;

            } elseif ($action === 'payout_request') {
                // Self-serve (3 Sep 2026): a counter agent asks the office to pay out
                // their commission balance. Nothing moves here — since 17 Sep 2026
                // the request is a durable agent_payout_requests row (plus the same
                // audit row as before) and a WhatsApp to the office; a supervisor
                // still records the actual payout, which is what clears the balance
                // and the request (or declines it on the 360 view).
                if (!Auth::isCounterAgent() || $targetId !== $selfId) {
                    throw new RuntimeException('Only an agent can request their own payout.');
                }
                $due    = (float) (AgentWallet::balances($selfId)['commission'] ?? 0);
                $amount = round((float) ($_POST['amount'] ?? 0), 2);
                if ($amount <= 0) { $amount = $due; }
                // A database without the requests table still refuses a second
                // request while one is open — the audit trail decides there.
                $pendingReq = agent_pending_payout_request($selfId);
                if ($pendingReq !== null) {
                    throw new RuntimeException('Your payout request from ' . formatDate(substr((string) $pendingReq['created_at'], 0, 10)) . ' is still with the office.');
                }
                $reqNote = Security::clean((string) ($_POST['note'] ?? ''), 200);
                // Validates the amount against the balance and agent_payout_min,
                // writes the row and the ONE agent.payout_request audit row.
                AgentWallet::requestPayout($selfId, $amount, $reqNote);
                try {
                    $office = Settings::getString('admin_whatsapp', Settings::officePhone());
                    if ($office !== '') {
                        Notify::whatsapp($office, "💸 Payout request\n" . (string) ($admin['full_name'] ?? ($admin['username'] ?? 'Agent'))
                            . ' (' . AgentWallet::agentCodeLabel($selfId) . ') asks for ' . inr($amount) . ' of ' . inr($due) . ' commission due.'
                            . ($reqNote !== '' ? "\nNote: " . $reqNote : '')
                            . "\nSettle: " . appUrl('admin/agent-360.php?agent=' . $selfId . '&tab=requests'));
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

            } elseif ($action === 'loan_issue') {
                /* Loans & advances register (17 Sep 2026) in place of the plain
                   advance form. The MONEY is unchanged — the same recordAdvance()
                   debit on the commission account (Point 7), now tagged
                   'ADVANCE L<id>' — but each item carries its own principal,
                   recovery rule and dated repayments on agent_loans. Same
                   settle-desk right as a payout; nobody lends to their own account. */
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to settle agent accounts.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must record your own advance.');
                }
                $loanKind = (($_POST['kind'] ?? 'advance') === 'loan') ? 'loan' : 'advance';
                $loanAmt  = round(abs((float) ($_POST['amount'] ?? 0)), 2);
                $loanId   = AgentWallet::issueLoan(
                    $targetId,
                    $loanKind,
                    $loanAmt,
                    (string) ($_POST['recover_mode'] ?? 'full'),
                    (float) ($_POST['recover_value'] ?? 0),
                    (string) ($_POST['note'] ?? ''),
                    $selfId
                );
                $flash = ['ok', ucfirst($loanKind) . ' #' . $loanId . ' of ' . inr($loanAmt)
                    . ' issued — it auto-deducts from this agent’s future commission until settled.'];
                $viewId = $targetId;

            } elseif ($action === 'loan_repay') {
                // Cash the agent returned against ONE register item: the usual
                // recordAdvance() credit, and the item's recovered figure moves.
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to settle agent accounts.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must record your own repayment.');
                }
                $loanId = (int) ($_POST['loan_id'] ?? 0);
                $loan   = $loanId > 0 ? AgentWallet::loan($loanId) : null;
                // A posted id must never reach another agent's item.
                if ($loan === null || (int) $loan['agent_admin_id'] !== $targetId) {
                    throw new RuntimeException('That loan is not on this agent’s register.');
                }
                $repayAmt = round(abs((float) ($_POST['amount'] ?? 0)), 2);
                AgentWallet::repayLoan($loanId, $repayAmt, (string) ($_POST['note'] ?? ''), $selfId);
                $flash = ['ok', 'Repayment of ' . inr($repayAmt) . ' recorded on ' . AgentWallet::loanLabel($loan) . ' — commission balance updated.'];
                $viewId = $targetId;

            } elseif ($action === 'adjustment') {
                /* Generic correction (17 Sep 2026): the office fixing a wrong
                   cash figure, crediting a bonus, debiting a shortfall — one
                   signed 'adjustment' row on either account, tagged ref 'ADJ …'
                   so ledger_look() reads it as a Correction and the salary /
                   advance readers (ref LIKE 'SALARY %' / 'ADVANCE%') never
                   count it. Same settle-desk right and self wall as a payout;
                   the note is mandatory because a correction with no reason is
                   the one row nobody can explain a month later. */
                if (!$canSettle) {
                    throw new RuntimeException('You are not allowed to settle agent accounts.');
                }
                if ($targetId === $selfId && !Auth::isSuperadmin()) {
                    throw new RuntimeException('Someone else must correct your own account.');
                }
                $adjNote = Security::clean((string) ($_POST['note'] ?? ''), 255);
                if (mb_strlen($adjNote) < 5) {
                    throw new RuntimeException('Write a note of at least 5 characters saying what this correction is for.');
                }
                $adjAccount   = (($_POST['account'] ?? 'commission') === 'cash') ? 'cash' : 'commission';
                $adjDirection = (($_POST['direction'] ?? 'credit') === 'debit') ? 'debit' : 'credit';
                $adjRef       = Security::clean((string) ($_POST['ref'] ?? ''), 60);
                $adjAmt       = round(abs((float) ($_POST['amount'] ?? 0)), 2);
                AgentWallet::record($targetId, 'adjustment', $adjAmt, [
                    'account'   => $adjAccount,
                    'direction' => $adjDirection,
                    'note'      => $adjNote,
                    'ref'       => 'ADJ' . ($adjRef !== '' ? ' ' . $adjRef : ''),
                    'by'        => $selfId,
                ]);
                $flash = ['ok', 'Correction recorded: ' . $adjDirection . ' of ' . inr($adjAmt) . ' on the ' . $adjAccount . ' account.'];
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

/* Open payout request (self-serve, 3 Sep 2026; durable rows 17 Sep 2026):
   shown to the agent as "waiting" and to a supervisor as a nudge above the
   settle panel. See agent_pending_payout_request() for the two sources. */
$payoutReq    = agent_pending_payout_request($viewId);
$payoutReqAmt = $payoutReq !== null ? (float) $payoutReq['amount'] : 0.0;

$summary  = AgentWallet::summary($viewId);
$pct      = AgentWallet::commissionPercentFor($viewId);
$salary     = AgentWallet::salaryFor($viewId);
$salaryPaid = AgentWallet::salaryPaidTotal($viewId);
$thisMonth  = date('Y-m');
$salaryDone = $salary > 0 && AgentWallet::salaryPosted($viewId, $thisMonth);
$deposit    = AgentWallet::depositInfo($viewId);
$advance    = AgentWallet::advanceSummary($viewId);
// Loans & advances register (17 Sep 2026): recovered figures are refreshed
// from the ledger before reading — a display step, never a money movement.
AgentWallet::syncLoanRecovery($viewId);
$loans      = AgentWallet::loans($viewId);
$openLoans  = array_values(array_filter($loans, static fn(array $l): bool => (string) $l['status'] === 'open'));
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

$monthlyTarget = Settings::getFloat('agent_target_monthly', 0.0);
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

$agentList = $isSupervisor
    ? Database::fetchAll("SELECT id, username, full_name FROM admins WHERE role = 'agent' ORDER BY full_name, username")
    : [];

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/** Ledger row → [icon, label, colour]. $ref lets an advance adjustment
 *  (ref 'ADVANCE…') read as "Advance" instead of a generic adjustment. */
function ledger_look(string $type, string $ref = ''): array
{
    if ($type === 'adjustment') {
        // Ref tags (17 Sep 2026): 'ADVANCE L<n>' ties the row to its register
        // item, 'SALARY YYYY-MM' is a month's salary, 'ADJ …' is an office
        // correction — none of them is a plain adjustment to the reader.
        if (preg_match('/^ADVANCE L(\d+)/i', $ref, $m)) {
            return ['💸', 'Advance/Loan #' . (int) $m[1], '#8a5300'];
        }
        if (stripos($ref, 'ADVANCE') === 0) {
            return ['💸', 'Advance', '#8a5300'];
        }
        if (stripos($ref, 'SALARY') === 0) {
            return ['📅', 'Salary', '#2E5FA8'];
        }
        if (stripos($ref, 'ADJ') === 0) {
            return ['✏️', 'Correction', '#6b7688'];
        }
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

admin_header($isOwn ? 'My Agent Panel' : 'Agent · ' . (string) $viewing['full_name'], 'agent');
admin_page_head(
    $isOwn
        ? 'Your wallet, sales and profile — commission is read from the ledger the moment a sale is confirmed, never recomputed.'
        : 'Supervisor view of this agent\'s wallet, sales and profile.',
    $isOwn ? [] : ['Agents' => $base . '/admin/agents.php', (string) ($viewing['full_name'] ?: $viewing['username']) => ''],
    $canSee360
        ? '<a class="btn navy" href="' . $base . '/admin/agent-360.php?agent=' . $viewId . '"><svg class="a-ic"><use href="#a-users"/></svg> Full 360 view</a>'
        : ''
);

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
  <?php if ($canSee360): ?><a class="btn ghost sm" href="<?= $base ?>/admin/agent-360.php?agent=<?= $viewId ?>">🧭 Full 360 view</a><?php endif; ?>
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
</style>

<!-- ============ WALLET ============ -->
<div class="dash-hero">
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

<div class="kpis">
  <?= admin_kpi("Today's bookings", (string) $stats['todayCount'], inr($stats['todayMoney']) . ' collected', 'ticket', 'blue') ?>
  <?= admin_kpi('This month', $stats['monthCount'] . ' · ' . inr($stats['monthMoney']), inr($summary['earnedMonth']) . ' commission this month', 'calendar', 'green') ?>
  <?= admin_kpi('Commission pay', $payLabel, $paySub, 'percent', 'violet') ?>
  <?= admin_kpi('This week', (string) $stats['weekCount'], 'bookings sold', 'chart-up', 'teal') ?>
</div>

<?php if ($isOwn && Auth::isCounterAgent()): ?>
<div class="panel" id="payoutRequest">
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
<div class="flash warn">💸 Pending payout request: <strong><?= Security::e(inr($payoutReqAmt)) ?></strong> asked on <?= Security::e(formatDate(substr((string) $payoutReq['created_at'], 0, 10))) ?> — record the payout below to clear it<?php if ($canSee360): ?>, or <a href="<?= $base ?>/admin/agent-360.php?agent=<?= $viewId ?>&amp;tab=requests">decide it on the 360 view</a><?php endif; ?>.</div>
<?php endif; ?>

<div class="dash-panel">
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

<?php if ($monthlyTarget > 0):
  $tpct = (int) round(min(100, $stats['monthMoney'] * 100 / $monthlyTarget));
  $barC = $tpct >= 100 ? '#0a6b3b' : ($tpct >= 60 ? '#d68910' : '#b02a2a');
?>
<div class="panel">
  <h2>🎯 Monthly target</h2>
  <div style="padding:16px 18px">
    <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px">
      <span class="muted"><?= Security::e(inr($stats['monthMoney'])) ?> of <?= Security::e(inr($monthlyTarget)) ?></span>
      <strong><?= $tpct ?>%</strong>
    </div>
    <div style="height:9px;border-radius:99px;background:var(--line);overflow:hidden">
      <div style="width:<?= $tpct ?>%;height:100%;background:<?= $barC ?>"></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canSettle && !($viewId === $selfId && !Auth::isSuperadmin())): ?>
<div class="panel">
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
      <input type="hidden" name="action" value="loan_issue">
      <strong style="font-size:13px">💸 Issue advance / loan</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        <?php if ($advance['outstanding'] > 0): ?>
          <?= Security::e(inr($advance['outstanding'])) ?> still to recover from this agent’s commission.
        <?php else: ?>
          Money paid ahead of earnings — it auto-deducts from future commission until settled.
        <?php endif; ?>
      </div>
      <div class="row-actions">
        <select name="kind" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
          <option value="advance">Advance</option>
          <option value="loan">Loan</option>
        </select>
        <input type="number" name="amount" step="0.01" min="0.01"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <select name="recover_mode" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px"
                onchange="var v=this.form.querySelector('[name=recover_value]');v.disabled=this.value==='full';v.placeholder=this.value==='percent'?'%':'₹ / payout'">
          <option value="full">Recover: full</option>
          <option value="fixed">Recover: ₹ per payout</option>
          <option value="percent">Recover: % of payout</option>
        </select>
        <input type="number" name="recover_value" step="0.01" min="0" placeholder="—" disabled
               style="width:90px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="note" maxlength="255" placeholder="What it is for"
               style="flex:1;min-width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit">Issue</button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:6px">
        Unlike a payout, an advance may exceed the commission owed today — it is fronted before it is earned.
      </div>
    </form>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="loan_repay">
      <strong style="font-size:13px">↩️ Record repayment</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        Cash the agent returned against one item. Recovery from commission needs no entry — the ledger nets it.
      </div>
      <div class="row-actions">
        <select name="loan_id" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px;max-width:220px" <?= $openLoans === [] ? 'disabled' : '' ?>>
          <?php if ($openLoans === []): ?><option value="">— nothing open —</option><?php endif; ?>
          <?php foreach ($openLoans as $l): ?>
            <option value="<?= (int) $l['id'] ?>"><?= Security::e(AgentWallet::loanLabel($l)) ?> · <?= Security::e(inr((float) $l['outstanding'])) ?> due</option>
          <?php endforeach; ?>
        </select>
        <input type="number" name="amount" step="0.01" min="0.01"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="note" maxlength="255" placeholder="Note"
               style="flex:1;min-width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit" <?= $openLoans === [] ? 'disabled' : '' ?>>Record repayment</button>
      </div>
    </form>
    <form method="post">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="agent_id" value="<?= $viewId ?>">
      <input type="hidden" name="action" value="adjustment">
      <strong style="font-size:13px">✏️ Correction</strong>
      <div class="muted" style="font-size:12px;margin:4px 0 8px">
        Fix a wrong cash figure or credit a bonus: one signed row on either account, tagged ADJ, with a mandatory note.
      </div>
      <div class="row-actions">
        <select name="account" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
          <option value="commission">Commission account</option>
          <option value="cash">Cash account</option>
        </select>
        <select name="direction" style="padding:8px 10px;border:1px solid var(--line);border-radius:8px">
          <option value="credit">Credit (+)</option>
          <option value="debit">Debit (−)</option>
        </select>
        <input type="number" name="amount" step="0.01" min="0.01"
               placeholder="Amount" required style="width:110px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="ref" maxlength="60" placeholder="Ref (optional)"
               style="width:130px;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <input type="text" name="note" maxlength="255" minlength="5" required placeholder="Why — at least 5 characters"
               style="flex:1 1 100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px">
        <button class="btn" type="submit">Record correction</button>
      </div>
      <div class="muted" style="font-size:11px;margin-top:6px">
        Credit on commission = the company owes the agent more; debit on cash = the agent holds less for the company.
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

<?php if ($loans !== []): ?>
<div class="panel">
  <h2>💸 Loans &amp; advances register
    <?php if ($canSee360): ?><a class="btn ghost sm" style="margin-left:auto" href="<?= $base ?>/admin/agent-360.php?agent=<?= $viewId ?>&amp;tab=loans">Full register →</a><?php endif; ?>
  </h2>
  <table>
    <thead><tr><th>Item</th><th>Issued</th><th style="text-align:right">Principal</th><th style="text-align:right">Recovered</th><th style="text-align:right">Outstanding</th><th>Status</th><th>Recovery</th></tr></thead>
    <tbody>
    <?php foreach ($loans as $l): ?>
      <tr>
        <td><strong><?= Security::e(AgentWallet::loanLabel($l)) ?></strong><?= $l['note'] ? '<div class="muted" style="font-size:11px">' . Security::e(truncate((string) $l['note'], 50)) . '</div>' : '' ?></td>
        <td class="muted"><?= Security::e(formatDate((string) $l['issued_on'], 'j M Y')) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $l['principal'])) ?></td>
        <td style="text-align:right"><?= Security::e(inr((float) $l['recovered'])) ?></td>
        <td style="text-align:right;font-weight:800"><?= Security::e(inr((float) $l['outstanding'])) ?></td>
        <td><?= admin_pill((string) $l['status'] === 'open' ? 'due' : ((string) $l['status'] === 'settled' ? 'paid' : 'void'), (string) $l['status'] === 'written_off' ? 'Written off' : ucfirst((string) $l['status'])) ?></td>
        <td class="muted" style="font-size:12px"><?= (string) $l['recover_mode'] === 'fixed'
            ? Security::e(inr((float) $l['recover_value'])) . ' / payout'
            : ((string) $l['recover_mode'] === 'percent'
                ? Security::e(rtrim(rtrim(number_format((float) $l['recover_value'], 2), '0'), '.')) . '% / payout'
                : 'commission nets it') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted" style="padding:0 18px 12px;font-size:11.5px;margin:0">“Recovered” = cash repaid + commission allocated to the item from the ledger, oldest first. The ledger's own netting is what is paid; the register is for reading.</p>
</div>
<?php endif; ?>

<div class="panel">
  <h2>📜 Wallet history</h2>
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
  <table>
    <thead><tr><th>When</th><th>Entry</th><th>Booking</th><th>Note</th><th style="text-align:right">Amount</th></tr></thead>
    <tbody>
    <?php if ($ledger === []): ?>
      <tr><td colspan="5" class="muted" style="padding:22px;text-align:center">
        Nothing yet. Commission appears here the moment a sale is confirmed.
      </td></tr>
    <?php else: foreach ($ledger as $l):
      [$icon, $label, $colour] = ledger_look((string) $l['entry_type'], (string) ($l['ref'] ?? ''));
      $amt = (float) $l['amount'];
    ?>
      <tr>
        <td class="muted"><?= Security::e(timeAgo((string) $l['created_at'])) ?></td>
        <td><span style="color:<?= $colour ?>;font-weight:700"><?= $icon ?> <?= Security::e($label) ?></span>
          <div class="muted" style="font-size:11px"><?= Security::e(ucfirst((string) $l['account'])) ?> account<?= $l['by_name'] ? ' · by ' . Security::e((string) $l['by_name']) : '' ?></div></td>
        <td class="mono"><?php if (!empty($l['pnr'])): ?>
            <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $l['pnr']) ?>"><?= Security::e((string) $l['pnr']) ?></a>
          <?php else: ?><span class="muted"><?= Security::e((string) ($l['ref'] ?: '—')) ?></span><?php endif; ?>
          <?php /* Printable voucher / receipt for a settlement row (17 Sep 2026). */ ?>
          <?php if (in_array((string) $l['entry_type'], ['payout', 'cash_handover'], true)): ?>
            <a href="<?= $base ?>/admin/agent-receipt.php?id=<?= (int) $l['id'] ?>" target="_blank" title="Printable receipt" style="font-size:11px;white-space:nowrap">🧾 Receipt</a>
          <?php endif; ?></td>
        <td class="muted" style="font-size:12px"><?= Security::e(truncate((string) ($l['note'] ?? ''), 60)) ?></td>
        <td style="text-align:right;font-weight:800;color:<?= $amt >= 0 ? '#0a6b3b' : '#b02a2a' ?>">
          <?= $amt >= 0 ? '+' : '−' ?><?= Security::e(inr(abs($amt))) ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<!-- ============ LOGIN CREDENTIALS (4 Sep 2026) ============
     Agents sign in with EMAIL + USERNAME + PASSWORD, all three set here by
     the office. Nothing on this page can read an existing password back —
     bcrypt hashes are one-way — so "reset" means "type a new one". Every
     change writes an agent.credentials audit row (old/new username and
     email; the password itself is never logged).                        -->
<div class="panel" id="loginCredentials">
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
<div class="panel">
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
<div class="panel">
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
<div class="panel">
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

<div class="panel">
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

<p class="muted" style="font-size:12.5px">
  Commission is <?= Security::e($payLabel) ?> (<?= Security::e($paySub) ?>) on confirmed sales you made, written to
  the wallet the moment a sale is confirmed and reversed if it is later cancelled. Sales made before the wallet
  was installed have no ledger entry, so they show under “recent sales” but not in the balance.
</p>
<?php
admin_footer();
