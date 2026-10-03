<?php
/**
 * admin/agent-360.php?agent=<id>[&tab=…] — Agent 360 (17 Sep 2026).
 *
 * One supervisor screen per counter agent: who they are and what they are
 * paid, both wallet balances and the NET of the two, a mini bank statement
 * with opening / running / closing balances, every settlement with its
 * voucher and a printable receipt, the loans & advances register, the
 * security-deposit history, the payout requests waiting for a decision,
 * KYC, and the audit trail for this agent.
 *
 * WHY A SECOND PAGE and not more of admin/agent.php: agent.php is the
 * agent's OWN dashboard — the one tests/agent-isolation-test.php and
 * tests/counter-role-test.php pin — so it must keep rendering for a counter
 * agent's session, with an "is this my own money?" branch on every block.
 * This page is the office's view: it refuses a counter agent outright, so
 * the settle-desk forms can sit here without a single such branch.
 *
 * MONEY: every rupee moves through AgentWallet (record / issueLoan /
 * repayLoan / writeOffLoan / recordDeposit / decidePayoutRequest). This
 * file only validates the form, walls off self-settlement (nobody settles
 * their own account unless superadmin), audits through those calls, sets a
 * flash and redirects back to the same tab (redirect-after-post, so a
 * refresh can never repeat a payout).
 *
 * Gate: customers.view + commissions.view (exactly like agents.php), never
 * a counter agent. Money actions need commissions.pay; KYC and the required
 * deposit need staff.manage.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentreport.php';
$admin = admin_boot('customers.view');
Auth::requireAdmin('commissions.view');
if (Auth::isCounterAgent()) {
    http_response_code(403);
    admin_header('Agent 360', 'agents');
    echo '<div class="flash bad">Agent 360 is an office page — an agent sees their own figures on the Agent Panel.</div>';
    admin_footer();
    exit;
}

$selfId    = (int) ($admin['id'] ?? 0);
$canSettle = Auth::can('commissions.pay');      // may pay out / accept cash / issue loans / decide requests
$canManage = Auth::can('staff.manage');         // may verify KYC / set the required deposit
$base      = '';   // root-relative: the panel must stay on the request host (.in or the .network staff door)

$tabs = [
    'report'      => 'Report · रिपोर्ट',
    'overview'    => 'Overview',
    'statement'   => 'Statement',
    'settlements' => 'Settlements',
    'loans'       => 'Loans & advances',
    'deposits'    => 'Deposits',
    'requests'    => 'Payout requests',
    'profile'     => 'Profile & KYC',
    'activity'    => 'Activity',
];

$agentId = (int) ($_GET['agent'] ?? 0);
if ($agentId <= 0 && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $agentId = (int) ($_POST['agent_id'] ?? 0);
}
$tab = (string) ($_GET['tab'] ?? ($_POST['tab'] ?? 'report'));
if (!isset($tabs[$tab])) {
    $tab = 'report';
}

$agent = null;
if ($agentId > 0) {
    $agent = Database::fetch(
        "SELECT id, username, email, full_name, phone, role, is_active, last_login_at, created_at
           FROM admins WHERE id = :id AND role = 'agent' LIMIT 1",
        ['id' => $agentId]
    );
}
$profile = $agent !== null ? AgentWallet::profile($agentId) : [];

/* ---- Flash across the redirect ------------------------------------
   The page redirects after every POST (so a browser refresh can never
   re-post a payout), which means the message has to survive one hop.
   Kept in the session under its own key; read once and cleared. */
function a360_flash(string $kind, string $msg, string $waLink = ''): void
{
    $_SESSION['shg_a360_flash'] = [$kind, $msg, $waLink];
}

/** Back to this agent's page on the tab the form came from. */
function a360_go(int $agentId, string $tab): never
{
    Response::redirect('admin/agent-360.php?agent=' . $agentId . '&tab=' . rawurlencode($tab));
}

/**
 * The agent's WhatsApp number as wa.me / the notifier want it: digits with
 * the country code. Profile WhatsApp first, then the login mobile. A bare
 * 10-digit number cannot be placed by its digits (India and Nepal share the
 * format), so the same hints Notify uses apply: a typed +977/+91 prefix, a
 * Nepali citizenship card as the ID document, else the configured default.
 */
function a360_wa_digits(array $agent, array $profile): string
{
    $raw = (string) (($profile['whatsapp'] ?? '') ?: (($agent['phone'] ?? '') ?: ($profile['display_phone'] ?? '')));
    $d   = preg_replace('/\D/', '', $raw) ?? '';
    if ($d === '') {
        return '';
    }
    if (str_starts_with($d, '00')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 10) {
        $cc = countryDialCode(a360_country_hint($profile, $raw) ?? '');
        if ($cc === '') {
            $cc = preg_replace('/\D/', '', Settings::getString('whatsapp_default_country', '91')) ?: '91';
        }
        $d = $cc . $d;
    }
    return $d;
}

/** 'NP' | 'IN' | null — the country hint handed to Notify::whatsapp(). */
function a360_country_hint(array $profile, string $rawPhone): ?string
{
    $c = resolvePhoneCountry('', $rawPhone);
    if ($c === '' && (string) ($profile['id_type'] ?? '') === 'Citizenship') {
        $c = 'NP';
    }
    return $c !== '' ? $c : null;
}

/**
 * Tell the agent a settlement was recorded (agent_notify_settlement, default
 * on). Best effort: an outage never undoes the ledger row. Returns a wa.me
 * link when the WhatsApp driver is click-to-chat, so the flash can offer a
 * one-tap send; '' otherwise.
 */
function a360_notify_settlement(array $agent, array $profile, string $entryType, float $amount, int $ledgerId): string
{
    if (!Settings::getBool('agent_notify_settlement', true)) {
        return '';
    }
    try {
        $to = a360_wa_digits($agent, $profile);
        if ($to === '') {
            return '';
        }
        $company = Settings::getString('company_name', APP_NAME);
        $voucher = AgentWallet::voucherFor($ledgerId);
        $bal     = AgentWallet::balances((int) $agent['id']);
        $when    = formatDate(todayISO(), 'j M Y');
        if ($entryType === 'payout') {
            $text = $company . ': ' . inr($amount) . ' paid out to you on ' . $when . '.'
                  . ($voucher !== '' ? ' Voucher ' . $voucher . '.' : '')
                  . ' Commission balance now ' . inr($bal['commission']) . '.';
        } else {
            $text = $company . ': cash handover of ' . inr($amount) . ' received from you on ' . $when . '.'
                  . ($voucher !== '' ? ' Receipt ' . $voucher . '.' : '')
                  . ' Cash in hand now ' . inr($bal['cash']) . '.';
        }
        $res = Notify::whatsapp($to, $text, null, a360_country_hint($profile, (string) ($profile['whatsapp'] ?: ($agent['phone'] ?? ''))));
        return is_string($res) && str_starts_with($res, 'https://wa.me/') ? $res : '';
    } catch (Throwable $e) {
        Logger::error('agent-360 settlement notify failed', ['agent' => (int) ($agent['id'] ?? 0), 'e' => $e->getMessage()]);
        return '';
    }
}

/** A register row that belongs to THIS agent, or an exception — a posted id must never reach another agent's loan. */
function a360_own_loan(int $loanId, int $agentId): array
{
    $loan = $loanId > 0 ? AgentWallet::loan($loanId) : null;
    if ($loan === null || (int) $loan['agent_admin_id'] !== $agentId) {
        throw new RuntimeException('That loan is not on this agent\'s register.');
    }
    return $loan;
}

/** An OPEN payout request that belongs to this agent, or an exception. */
function a360_own_request(int $requestId, int $agentId): array
{
    foreach (AgentWallet::openPayoutRequests($agentId) as $r) {
        if ((int) $r['id'] === $requestId) {
            return $r;
        }
    }
    throw new RuntimeException('That payout request is not open for this agent.');
}

/* ---- Actions (redirect-after-post) -------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action   = (string) ($_POST['action'] ?? '');
    $targetId = (int) ($_POST['agent_id'] ?? 0);
    $backTab  = (string) ($_POST['tab'] ?? $tab);
    if (!isset($tabs[$backTab])) {
        $backTab = 'report';
    }

    if (!Security::verifyCsrf()) {
        a360_flash('bad', 'Session expired — please try again.');
        a360_go($targetId ?: $agentId, $backTab);
    }
    if ($agent === null || $targetId !== $agentId) {
        a360_flash('bad', 'Agent not found.');
        a360_go($agentId, 'overview');
    }

    try {
        $needPay = static function () use ($canSettle): void {
            if (!$canSettle) {
                throw new RuntimeException('You are not allowed to settle agent accounts.');
            }
        };
        $needManage = static function () use ($canManage): void {
            if (!$canManage) {
                throw new RuntimeException('Only a staff manager (or super-admin) can do that.');
            }
        };
        // The self-settlement wall, same as agent.php: nobody pays out,
        // accepts cash from, lends to or forgives their own account.
        $notSelf = static function () use ($targetId, $selfId): void {
            if ($targetId === $selfId && !Auth::isSuperadmin()) {
                throw new RuntimeException('Someone else must settle your own account.');
            }
        };

        if ($action === 'payout' || $action === 'cash_handover') {
            $needPay();
            $notSelf();
            $amount   = round(abs((float) ($_POST['amount'] ?? 0)), 2);
            $ledgerId = AgentWallet::record($targetId, $action, $amount, [
                'note' => $_POST['note'] ?? '',
                'ref'  => $_POST['ref'] ?? '',
                'by'   => $selfId,
            ]);
            $voucher = AgentWallet::voucherFor($ledgerId);
            // A payout that covers the agent's open request settles it — the
            // request is bookkeeping about this very payment, not more money.
            if ($action === 'payout') {
                foreach (AgentWallet::openPayoutRequests($targetId) as $oreq) {
                    if ($amount + 0.009 >= (float) $oreq['amount']) {
                        try {
                            AgentWallet::decidePayoutRequest((int) $oreq['id'], 'paid', $selfId, 'Settled by payout ' . $voucher, $ledgerId);
                        } catch (Throwable $e) {
                            Logger::warning('payout request not auto-settled: ' . $e->getMessage(), [], 'agent');
                        }
                    }
                    break;   // requestPayout() allows one open request at a time
                }
            }
            $wa = a360_notify_settlement($agent, $profile, $action, $amount, $ledgerId);
            a360_flash('ok', ($action === 'payout' ? 'Commission payout of ' : 'Cash handover of ') . inr($amount)
                . ' recorded' . ($voucher !== '' ? ' — voucher ' . $voucher : '') . '.', $wa);
            a360_go($targetId, 'settlements');

        } elseif ($action === 'loan_issue') {
            $needPay();
            $notSelf();
            $kind   = (string) ($_POST['kind'] ?? 'advance');
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $loanId = AgentWallet::issueLoan(
                $targetId,
                $kind,
                $amount,
                (string) ($_POST['recover_mode'] ?? 'full'),
                (float) ($_POST['recover_value'] ?? 0),
                (string) ($_POST['note'] ?? ''),
                $selfId
            );
            a360_flash('ok', ($kind === 'loan' ? 'Loan' : 'Advance') . ' #' . $loanId . ' of ' . inr($amount)
                . ' issued — it is debited on the commission account and recovers from future commission.');
            a360_go($targetId, 'loans');

        } elseif ($action === 'loan_repay') {
            $needPay();
            $notSelf();
            $loan   = a360_own_loan((int) ($_POST['loan_id'] ?? 0), $targetId);
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            AgentWallet::repayLoan((int) $loan['id'], $amount, (string) ($_POST['note'] ?? ''), $selfId);
            a360_flash('ok', 'Repayment of ' . inr($amount) . ' recorded on ' . AgentWallet::loanLabel($loan) . '.');
            a360_go($targetId, 'loans');

        } elseif ($action === 'loan_writeoff') {
            $needPay();
            $notSelf();
            $loan = a360_own_loan((int) ($_POST['loan_id'] ?? 0), $targetId);
            $note = Security::clean($_POST['note'] ?? '', 255);
            if (mb_strlen($note) < 3) {
                throw new RuntimeException('Say why the balance is being written off.');
            }
            AgentWallet::writeOffLoan((int) $loan['id'], $note, $selfId);
            a360_flash('ok', AgentWallet::loanLabel($loan) . ' written off — the outstanding ' . inr((float) $loan['outstanding']) . ' is forgiven.');
            a360_go($targetId, 'loans');

        } elseif ($action === 'record_deposit') {
            $needPay();
            $notSelf();
            $amount = round((float) ($_POST['amount'] ?? 0), 2);
            $paid   = AgentWallet::recordDeposit($targetId, $amount, $selfId, (string) ($_POST['note'] ?? ''), (string) ($_POST['ref'] ?? ''));
            a360_flash('ok', ($amount > 0 ? 'Deposit of ' . inr($amount) . ' received' : 'Deposit refund of ' . inr(abs($amount)) . ' recorded')
                . ' — the company now holds ' . inr($paid) . ' from this agent.');
            a360_go($targetId, 'deposits');

        } elseif ($action === 'set_deposit_required') {
            $needManage();
            $amount = round((float) ($_POST['deposit_required'] ?? 0), 2);
            AgentWallet::setDepositRequired($targetId, $amount, $selfId);
            a360_flash('ok', 'Required deposit set to ' . inr($amount) . '.');
            a360_go($targetId, 'deposits');

        } elseif ($action === 'request_paid') {
            $needPay();
            $notSelf();
            $req      = a360_own_request((int) ($_POST['request_id'] ?? 0), $targetId);
            $amount   = round((float) $req['amount'], 2);
            $ledgerId = AgentWallet::record($targetId, 'payout', $amount, [
                'note' => 'Payout request #' . (int) $req['id'] . ((string) ($req['note'] ?? '') !== '' ? ' — ' . $req['note'] : ''),
                'ref'  => $_POST['ref'] ?? '',
                'by'   => $selfId,
            ]);
            AgentWallet::decidePayoutRequest((int) $req['id'], 'paid', $selfId, '', $ledgerId);
            $voucher = AgentWallet::voucherFor($ledgerId);
            $wa      = a360_notify_settlement($agent, $profile, 'payout', $amount, $ledgerId);
            a360_flash('ok', 'Payout request #' . (int) $req['id'] . ' paid — ' . inr($amount)
                . ($voucher !== '' ? ', voucher ' . $voucher : '') . '.', $wa);
            a360_go($targetId, 'requests');

        } elseif ($action === 'request_decline') {
            $needPay();
            $notSelf();
            $req  = a360_own_request((int) ($_POST['request_id'] ?? 0), $targetId);
            $note = Security::clean($_POST['note'] ?? '', 255);
            if (mb_strlen($note) < 3) {
                throw new RuntimeException('Tell the agent why the request is declined.');
            }
            AgentWallet::decidePayoutRequest((int) $req['id'], 'declined', $selfId, $note);
            $wa = '';
            if (Settings::getBool('agent_notify_settlement', true)) {
                try {
                    $to = a360_wa_digits($agent, $profile);
                    if ($to !== '') {
                        $res = Notify::whatsapp($to,
                            Settings::getString('company_name', APP_NAME) . ': your payout request of ' . inr((float) $req['amount'])
                            . ' from ' . formatDate(substr((string) $req['created_at'], 0, 10), 'j M') . ' was declined — ' . $note
                            . '. Commission due stays ' . inr(AgentWallet::balances($targetId)['commission']) . '.',
                            null, a360_country_hint($profile, (string) ($profile['whatsapp'] ?: ($agent['phone'] ?? ''))));
                        $wa = is_string($res) && str_starts_with($res, 'https://wa.me/') ? $res : '';
                    }
                } catch (Throwable $e) {
                    Logger::error('agent-360 decline notify failed', ['e' => $e->getMessage()]);
                }
            }
            a360_flash('ok', 'Payout request #' . (int) $req['id'] . ' declined.', $wa);
            a360_go($targetId, 'requests');

        } elseif ($action === 'kyc_save') {
            $needManage();
            if (!AgentWallet::kycAvailable()) {
                throw new RuntimeException('KYC is not set up yet — run database/upgrade-2026-09-agent-kyc.sql first.');
            }
            $before   = AgentWallet::profile($targetId);
            $uploaded = [];
            foreach (['1' => 'kyc_doc1', '2' => 'kyc_doc2'] as $slot => $field) {
                if (isset($_FILES[$field]) && (int) ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                    AgentWallet::saveKycDoc($targetId, $_FILES[$field], $slot);
                    $uploaded[] = $slot;
                }
            }
            if (isset($_FILES['agreement_doc']) && (int) ($_FILES['agreement_doc']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                AgentWallet::saveAgreementDoc($targetId, $_FILES['agreement_doc']);
                $uploaded[] = 'agreement';
            }
            $status = strtolower(trim((string) ($_POST['kyc_status'] ?? '')));
            $note   = Security::clean($_POST['kyc_note'] ?? '', 255);
            $exp    = trim((string) ($_POST['id_expires_on'] ?? ''));
            // Only a status the office actually CHANGED on the form is applied —
            // an upload moves 'none'/'rejected' to 'submitted' by itself, and a
            // select still reading the old value must not undo that.
            $touched = $status !== '' && $status !== (string) ($before['kyc_status'] ?? 'none')
                    && in_array($status, AgentWallet::KYC_STATUSES, true);
            if ($touched && ($status === 'verified' || $status === 'rejected')) {
                if ($status === 'rejected' && mb_strlen($note) < 3) {
                    throw new RuntimeException('Write what the agent must fix before rejecting.');
                }
                AgentWallet::saveProfile($targetId, ['id_expires_on' => $exp]);
                AgentWallet::kycVerify($targetId, $status, $note, $selfId);   // stamps who / when, audits
            } else {
                $data = ['kyc_note' => $note, 'id_expires_on' => $exp];
                if ($touched) {
                    $data['kyc_status']      = $status;        // 'none' | 'submitted'
                    $data['kyc_verified_by'] = null;
                    $data['kyc_verified_at'] = null;
                }
                AgentWallet::saveProfile($targetId, $data);   // audited as agent.profile
                if ($touched) {
                    Logger::audit('agent.kyc_status', 'admin', (string) $targetId,
                        ['kyc_status' => (string) ($before['kyc_status'] ?? 'none')], ['kyc_status' => $status, 'note' => $note],
                        'KYC ' . $status . ' by admin #' . $selfId);
                }
            }
            a360_flash('ok', 'KYC saved' . ($uploaded !== [] ? ' — document ' . implode(' and ', $uploaded) . ' uploaded' : '') . '.');
            a360_go($targetId, 'profile');

        } elseif ($action === 'kyc_verify') {
            $needManage();
            $status = (string) ($_POST['kyc_status'] ?? '');
            if (!in_array($status, ['verified', 'rejected'], true)) {
                throw new RuntimeException('Choose verified or rejected.');
            }
            $note = Security::clean($_POST['kyc_note'] ?? '', 255);
            if ($status === 'rejected' && mb_strlen($note) < 3) {
                throw new RuntimeException('Write what the agent must fix before rejecting.');
            }
            AgentWallet::kycVerify($targetId, $status, $note, $selfId);
            a360_flash('ok', $status === 'verified' ? 'KYC marked verified.' : 'KYC rejected — the note is on the profile for the agent.');
            a360_go($targetId, 'profile');

        /* ---- Report tab controls (3 Oct 2026, owner: "commission panel
           mai ghat bad, edit, enable disable, admin bata agent code change").
           Same writers and the same rights as admin/agent.php and the
           register (agents.php) — this only puts them one click from the
           agent's figures. A rate change moves FUTURE sales only: every
           sold ticket keeps the commission written to its ledger row; a
           past figure is corrected with the signed adjustment below. */
        } elseif ($action === 'rate_set' || $action === 'rate_reset') {
            $needManage();
            /* Oct 2026 (owner brief §7): every rate change carries a reason, and
               the audit row keeps the EFFECTIVE rate before and after (not just
               the override), the user and the time. Sold tickets are never
               re-rated — the change applies from the next ticket. */
            $reason = Security::clean((string) ($_POST['rate_reason'] ?? ''), 255);
            if (mb_strlen($reason) < 5) {
                throw new RuntimeException('Write a short reason (at least 5 characters) for the rate change — it is kept in the history.');
            }
            $old = AgentReport::rateCard($targetId);
            if ($action === 'rate_reset') {
                AgentWallet::setCommissionOverride($targetId, null, null, $selfId);
            } else {
                $raw = trim((string) ($_POST['rate_flat'] ?? ''));
                if ($raw === '' || !is_numeric($raw)) {
                    throw new RuntimeException('Enter the commission in ₹ per passenger.');
                }
                AgentWallet::setCommissionOverride($targetId, 'flat', round((float) $raw, 2), $selfId);
            }
            $rc = AgentReport::rateCard($targetId);
            Logger::audit('agent.commission_rate', 'admin', (string) $targetId,
                ['rate' => $old['label'], 'mode' => $old['mode'], 'basis' => $old['sub'] ?? ''],
                ['rate' => $rc['label'], 'mode' => $rc['mode'], 'basis' => $rc['sub'] ?? ''],
                'Commission rate changed (applies from the next ticket)', $reason);
            a360_flash('ok', $action === 'rate_reset'
                ? 'Commission back on the company tier — ' . $rc['label'] . ' from the next ticket (was ' . $old['label'] . '). Sold tickets keep what they earned.'
                : 'Commission set to ' . $rc['label'] . ' (was ' . $old['label'] . ') — applies from the next ticket. Sold tickets keep what they earned.');
            a360_go($targetId, $backTab);

        } elseif ($action === 'comm_adjust') {
            // One signed correction on the commission account, tagged 'ADJ'
            // exactly like admin/agent.php's correction form.
            $needPay();
            $notSelf();
            $dir  = (($_POST['direction'] ?? 'credit') === 'debit') ? 'debit' : 'credit';
            $amt  = round(abs((float) ($_POST['amount'] ?? 0)), 2);
            $note = Security::clean((string) ($_POST['note'] ?? ''), 255);
            if (mb_strlen($note) < 5) {
                throw new RuntimeException('Write a short reason (at least 5 characters) for this commission change.');
            }
            $balBefore = AgentWallet::balances($targetId)['commission'];
            AgentWallet::record($targetId, 'adjustment', $amt, [
                'account'   => 'commission',
                'direction' => $dir,
                'note'      => $note,
                'ref'       => 'ADJ commission',
                'by'        => $selfId,
            ]);
            // Oct 2026: the reason and the balance before/after, in the history.
            Logger::audit('agent.commission_adjust', 'admin', (string) $targetId,
                ['commission_balance' => $balBefore],
                ['commission_balance' => AgentWallet::balances($targetId)['commission'], 'change' => ($dir === 'credit' ? $amt : -$amt)],
                'Commission ' . ($dir === 'credit' ? 'added' : 'deducted'), $note);
            a360_flash('ok', ($dir === 'credit' ? 'Added ' : 'Deducted ') . inr($amt) . ' ' . ($dir === 'credit' ? 'to' : 'from')
                . ' the commission balance. Balance now ' . inr(AgentWallet::balances($targetId)['commission']) . '.');
            a360_go($targetId, $backTab);

        } elseif ($action === 'code_set') {
            $needManage();
            $raw = trim((string) ($_POST['agent_code'] ?? ''));
            if ($raw !== '' && (!ctype_digit($raw) || (int) $raw < AgentWallet::AGENT_CODE_MIN || (int) $raw > AgentWallet::AGENT_CODE_MAX)) {
                throw new RuntimeException('The agent code must be a number between ' . AgentWallet::AGENT_CODE_MIN . ' and ' . AgentWallet::AGENT_CODE_MAX . '.');
            }
            $before = AgentWallet::agentCodeLabel($targetId);
            AgentWallet::setAgentCode($targetId, $raw === '' ? null : (int) $raw, $selfId);   // throws on a duplicate; audited as agent.code_set
            $after = AgentWallet::agentCodeLabel($targetId);
            a360_flash('ok', $after === '' ? 'Agent code removed.' : 'Agent code is now ' . $after . ($before !== '' && $before !== $after ? ' (was ' . $before . ')' : '') . '. Tickets and the agent panel show the new code.');
            a360_go($targetId, $backTab);

        } elseif ($action === 'toggle_active') {
            $needManage();
            if ($targetId === $selfId) {
                throw new RuntimeException('You cannot disable your own account.');
            }
            $to = (int) $agent['is_active'] === 1 ? 0 : 1;
            Database::update('admins', ['is_active' => $to], 'id = :id', ['id' => $targetId]);
            Logger::audit($to ? 'agent.activate' : 'agent.deactivate', 'admin', (string) $agent['username'], null, null, 'agent 360');
            a360_flash('ok', $to ? 'Agent enabled — they can sign in and sell again.' : 'Agent disabled — they can no longer sign in or sell. Their tickets, wallet and history stay.');
            a360_go($targetId, $backTab);

        } elseif ($action === 'edit_basic') {
            // Name, mobile, WhatsApp, counter — the four fields the office
            // fixes most; everything else stays on the register's Edit.
            $needManage();
            $nm    = trim(Security::clean($_POST['full_name'] ?? '', 120));
            $phone = preg_replace('/\D/', '', (string) ($_POST['phone'] ?? '')) ?? '';
            $wa    = AgentWallet::cleanPhone((string) ($_POST['whatsapp'] ?? ''));
            $city  = trim(Security::clean($_POST['counter_name'] ?? '', 120));
            if (mb_strlen($nm) < 2) {
                throw new RuntimeException('Enter the agent\'s name.');
            }
            if (strlen($phone) < 10 || strlen($phone) > 15) {
                throw new RuntimeException('Enter a valid mobile number.');
            }
            if (Database::exists('SELECT 1 FROM admins WHERE phone = :p AND id <> :id LIMIT 1', ['p' => $phone, 'id' => $targetId])) {
                throw new RuntimeException('That mobile number belongs to another staff account.');
            }
            $before = ['full_name' => $agent['full_name'], 'phone' => $agent['phone'],
                       'whatsapp' => $profile['whatsapp'] ?? null, 'city' => $profile['counter_name'] ?? null];
            Database::update('admins', ['full_name' => $nm, 'phone' => $phone], 'id = :id', ['id' => $targetId]);
            Database::run(
                'INSERT INTO admin_profiles (admin_id, display_phone, whatsapp, counter_name)
                      VALUES (:aid, :dp, :wa, :cn)
                 ON DUPLICATE KEY UPDATE display_phone = :dp2, whatsapp = :wa2, counter_name = :cn2',
                ['aid' => $targetId, 'dp' => $phone, 'wa' => $wa ?: null, 'cn' => $city ?: null,
                 'dp2' => $phone, 'wa2' => $wa ?: null, 'cn2' => $city ?: null]
            );
            Logger::audit('agent.edit', 'admin', (string) $agent['username'], $before,
                ['full_name' => $nm, 'phone' => $phone, 'whatsapp' => $wa, 'city' => $city], 'agent 360 quick edit');
            a360_flash('ok', 'Agent details saved.');
            a360_go($targetId, $backTab);

        } else {
            throw new RuntimeException('Unknown action.');
        }
    } catch (Throwable $e) {
        a360_flash('bad', $e->getMessage());
        a360_go($targetId, $backTab);
    }
}

$flash = $_SESSION['shg_a360_flash'] ?? null;
unset($_SESSION['shg_a360_flash']);

/* ---- No agent chosen: offer the roster ----------------------------- */
if ($agent === null) {
    admin_header('Agent 360', 'agents');
    admin_page_head('Pick an agent to open their 360 view.', ['Agents' => $base . '/admin/agents.php', 'Agent 360' => '']);
    if ($flash !== null) {
        echo '<div class="flash ' . Security::e((string) $flash[0]) . '">' . Security::e((string) $flash[1]) . '</div>';
    }
    if ($agentId > 0) {
        echo '<div class="flash bad">No agent with id ' . (int) $agentId . '.</div>';
    }
    $roster = Database::fetchAll("SELECT id, username, full_name, is_active FROM admins WHERE role = 'agent' ORDER BY is_active DESC, full_name, username");
    echo '<div class="panel"><h2><svg class="a-ic"><use href="#a-users"/></svg> Agents</h2><div class="panel-body">';
    if ($roster === []) {
        echo admin_empty('No agents yet', 'Add one on Staff & Approvals first.', '👤');
    } else {
        echo '<form method="get" class="row" style="margin-bottom:14px"><label class="muted text-sm" for="a360Pick">Open</label>'
           . '<select id="a360Pick" name="agent" class="inp" onchange="this.form.submit()"><option value="">— choose an agent —</option>';
        foreach ($roster as $r) {
            echo '<option value="' . (int) $r['id'] . '">' . Security::e((string) ($r['full_name'] ?: $r['username']))
               . ((int) $r['is_active'] === 1 ? '' : ' (inactive)') . '</option>';
        }
        echo '</select><button class="btn" type="submit">Open</button></form>';
        echo '<div class="chips">';
        foreach ($roster as $r) {
            $code = AgentWallet::agentCodeLabel((int) $r['id']);
            echo '<a class="chip" href="' . $base . '/admin/agent-360.php?agent=' . (int) $r['id'] . '">'
               . Security::e((string) ($r['full_name'] ?: $r['username'])) . ($code !== '' ? ' <span class="mono muted">' . Security::e($code) . '</span>' : '') . '</a>';
        }
        echo '</div>';
    }
    echo '</div></div>';
    admin_footer();
    exit;
}

/* ---- Statement window (GET) ---------------------------------------- */
$stmtFrom = Security::isValidDate((string) ($_GET['from'] ?? '')) ? (string) $_GET['from'] : date('Y-m-01');
$stmtTo   = Security::isValidDate((string) ($_GET['to'] ?? ''))   ? (string) $_GET['to']   : todayISO();
if ($stmtTo < $stmtFrom) {
    [$stmtFrom, $stmtTo] = [$stmtTo, $stmtFrom];
}
$stmtAcc = in_array((string) ($_GET['account'] ?? ''), ['commission', 'cash'], true) ? (string) $_GET['account'] : '';

/** Ledger row → [icon, label, pill class]. Reads the ref tags the wallet uses. */
function a360_ledger_look(string $type, string $ref = ''): array
{
    if ($type === 'adjustment') {
        if (preg_match('/^ADVANCE L(\d+)/i', $ref, $m)) {
            return ['loan', 'Advance/Loan #' . (int) $m[1], 'st-orange'];
        }
        if (stripos($ref, 'ADVANCE') === 0) {
            return ['loan', 'Advance', 'st-orange'];
        }
        if (stripos($ref, 'SALARY') === 0) {
            return ['banknote', 'Salary', 'st-info'];
        }
        if (stripos($ref, 'ADJ') === 0) {
            return ['edit', 'Correction', 'st-muted'];
        }
    }
    return match ($type) {
        'commission'      => ['coins',     'Commission earned',   'st-ok'],
        'commission_void' => ['refund',    'Commission reversed', 'st-bad'],
        'payout'          => ['banknote',  'Paid to agent',       'st-info'],
        'cash_due'        => ['rupee',     'Cash collected',      'st-orange'],
        'cash_handover'   => ['handshake', 'Cash handed over',    'st-ok'],
        default           => ['edit',      'Adjustment',          'st-muted'],
    };
}

/* ---- CSV statement (must stream before any HTML) -------------------- */
if (($_GET['export'] ?? '') === 'csv') {
    $st   = AgentWallet::statement($agentId, $stmtFrom, $stmtTo, $stmtAcc);
    $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', (string) ($agent['username'] ?? ('agent' . $agentId))), '-');
    $accLabel = $stmtAcc === '' ? 'both accounts' : $stmtAcc . ' account';
    // Hand-rolled rather than shg_export_csv(): that helper prefixes any cell
    // starting with '-' (formula-injection guard), which would mangle every
    // negative amount in a statement. Nothing here is user-typed but the note,
    // and fputcsv quotes it.
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statement-' . $slug . '-' . $stmtFrom . '_to_' . $stmtTo . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // UTF-8 BOM so Excel reads ₹ / Devanagari
    csv_put($out, ['Agent', (string) ($agent['full_name'] ?: $agent['username']), 'Code', AgentWallet::agentCodeLabel($agentId), 'Window', $stmtFrom . ' to ' . $stmtTo, 'Account', $accLabel]);
    csv_put($out, []);
    csv_put($out, ['Date', 'Entry', 'Account', 'Booking / Ref', 'Note', 'Amount (signed)', 'Balance after (that account)', 'Recorded by']);
    if ($stmtAcc === '') {
        csv_put($out, [$stmtFrom, 'Opening balance', 'commission', '', '', '', number_format(AgentWallet::openingBalance($agentId, $stmtFrom, 'commission'), 2, '.', ''), '']);
        csv_put($out, [$stmtFrom, 'Opening balance', 'cash', '', '', '', number_format(AgentWallet::openingBalance($agentId, $stmtFrom, 'cash'), 2, '.', ''), '']);
    } else {
        csv_put($out, [$stmtFrom, 'Opening balance', $stmtAcc, '', '', '', number_format((float) $st['opening'], 2, '.', ''), '']);
    }
    foreach ($st['rows'] as $r) {
        [, $label] = a360_ledger_look((string) $r['entry_type'], (string) ($r['ref'] ?? ''));
        $after = (string) $r['account'] === 'cash' ? (float) $r['running_cash'] : (float) $r['running_commission'];
        csv_put($out, [
            (string) $r['created_at'],
            $label,
            (string) $r['account'],
            (string) (($r['pnr'] ?? '') !== '' ? $r['pnr'] : ($r['ref'] ?? '')),
            (string) ($r['note'] ?? ''),
            number_format((float) $r['amount'], 2, '.', ''),
            number_format($after, 2, '.', ''),
            (string) ($r['by_name'] ?? ''),
        ]);
    }
    csv_put($out, []);
    $closeC = $st['rows'] !== [] ? (float) end($st['rows'])['running_commission'] : AgentWallet::openingBalance($agentId, $stmtFrom, 'commission');
    $closeK = $st['rows'] !== [] ? (float) end($st['rows'])['running_cash']       : AgentWallet::openingBalance($agentId, $stmtFrom, 'cash');
    if ($stmtAcc === '') {
        csv_put($out, [$stmtTo, 'Closing balance', 'commission', '', 'company owes agent', '', number_format($closeC, 2, '.', ''), '']);
        csv_put($out, [$stmtTo, 'Closing balance', 'cash', '', 'agent owes company', '', number_format($closeK, 2, '.', ''), '']);
        csv_put($out, [$stmtTo, 'Net position', 'cash - commission', '', 'positive = agent owes the company', '', number_format((float) $st['closing'], 2, '.', ''), '']);
    } else {
        csv_put($out, [$stmtTo, 'Closing balance', $stmtAcc, '', '', '', number_format((float) $st['closing'], 2, '.', ''), '']);
    }
    fclose($out);
    exit;
}

/* ---- Everything the tabs show --------------------------------------- */
$balances   = AgentWallet::balances($agentId);
$net        = AgentWallet::netPosition($agentId);
$summary    = AgentWallet::summary($agentId);
$advance    = AgentWallet::advanceSummary($agentId);
$deposit    = AgentWallet::depositInfo($agentId);
$depositTx  = AgentWallet::depositHistory($agentId);
AgentWallet::syncLoanRecovery($agentId);            // display figures only — never a money movement
$loans      = AgentWallet::loans($agentId);
$openLoans  = array_values(array_filter($loans, static fn(array $l): bool => (string) $l['status'] === 'open'));
$openReqs   = AgentWallet::openPayoutRequests($agentId);
$reqHist    = AgentWallet::payoutRequests($agentId, 50);
$months     = AgentWallet::commissionByMonth($agentId, 12);
$overdue    = AgentWallet::settlementOverdueDays($agentId);
$salary     = AgentWallet::salaryFor($agentId);
$salaryPaid = AgentWallet::salaryPaidTotal($agentId);
$code       = AgentWallet::agentCodeLabel($agentId);
$kind       = AgentWallet::normaliseKind((string) ($profile['agent_kind'] ?? AgentWallet::KIND_ORG));
$pct        = AgentWallet::commissionPercentFor($agentId);
$override   = AgentWallet::commissionOverrideFor($agentId);
$statement  = AgentWallet::statement($agentId, $stmtFrom, $stmtTo, $stmtAcc);
/* Report tab window (3 Oct 2026) — its own rfrom/rto so it never fights
   the Statement tab's from/to. */
$repWin = AgentReport::window(
    (string) ($_GET['range'] ?? 'month'),
    (string) ($_GET['rfrom'] ?? ''),
    (string) ($_GET['rto'] ?? ''),
    (string) ($_GET['basis'] ?? 'journey')
);
$report = AgentReport::build($agentId, $repWin);
$rate   = AgentReport::rateCard($agentId);
$settlements = array_values(array_filter(
    AgentWallet::entries($agentId, 500),
    static fn(array $r): bool => in_array((string) $r['entry_type'], ['payout', 'cash_handover'], true)
));
$name    = (string) ($agent['full_name'] ?: $agent['username']);
$photoUrl = '';
if ((string) ($profile['photo_path'] ?? '') !== '' && str_starts_with((string) $profile['photo_path'], 'agents/')) {
    $photoUrl = $base . '/uploads/' . (string) $profile['photo_path'];   // face photos are public by design; KYC files are not
}
$waDigits = a360_wa_digits($agent, $profile);

/* What this agent is ACTUALLY paid right now — override, tier or percent
   (same derivation as admin/agent.php). */
if ($override['mode'] === 'flat') {
    $payLabel = inr((float) $override['flat']) . ' / pax';
    $paySub   = 'agent override — flat per passenger';
} elseif ($override['mode'] === 'percent') {
    $payLabel = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
    $paySub   = 'agent override — % of fare';
} elseif (AgentWallet::flatMode()) {
    $payLabel = inr(AgentWallet::flatRateFor($agentId)) . ' / pax';
    $paySub   = (AgentWallet::agentTypeFor($agentId) === 'joint' ? 'team/organisation' : 'direct') . ' tier';
} else {
    $payLabel = rtrim(rtrim(number_format($pct, 2), '0'), '.') . '%';
    $paySub   = $profile['commission_percent'] === null ? 'company default rate' : 'set for this agent';
}

/* Audit trail for this agent. Wallet actions key the row by the numeric
   admins.id, the register (agents.php) by the username — read both. */
$activity = [];
try {
    $activity = Database::fetchAll(
        "SELECT id, actor_type, actor_name, action, entity_id, old_value, new_value, detail, created_at
           FROM audit_logs
          WHERE entity_type = 'admin' AND (entity_id = :a OR entity_id = :u)
          ORDER BY id DESC
          LIMIT 30",
        ['a' => (string) $agentId, 'u' => (string) $agent['username']]
    );
} catch (Throwable $e) {
    $activity = [];
}

$kycStatus   = (string) ($profile['kyc_status'] ?? 'none');
$kycVerifier = '';
if ((int) ($profile['kyc_verified_by'] ?? 0) > 0) {
    $kycVerifier = (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => (int) $profile['kyc_verified_by']], '');
}
$isActive     = (int) $agent['is_active'] === 1;
$isSuspended  = (string) ($profile['suspended_at'] ?? '') !== '';
$selfWalled   = $agentId === $selfId && !Auth::isSuperadmin();   // the settle forms hide for your own account
$showSettle   = $canSettle && !$selfWalled;
$openReqTotal = array_sum(array_map(static fn(array $r): float => (float) $r['amount'], $openReqs));
$loanOutstanding = array_sum(array_map(static fn(array $l): float => (float) $l['outstanding'], $openLoans));
$overCash     = (float) ($profile['cash_limit'] ?? 0) > 0 && $balances['cash'] > (float) $profile['cash_limit'] + 0.009;

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
$e    = static fn($v): string => Security::e((string) ($v ?? ''));

/** KYC status → pill. */
function a360_kyc_pill(string $status): string
{
    return match ($status) {
        'verified'  => '<span class="pill st-ok">KYC verified</span>',
        'submitted' => '<span class="pill st-warn">KYC submitted</span>',
        'rejected'  => '<span class="pill st-bad">KYC rejected</span>',
        default     => '<span class="pill st-muted">KYC none</span>',
    };
}

/** Signed money cell: +₹1,200 in green, −₹500 in red. */
function a360_amt(float $v): string
{
    $sign = $v < 0 ? '−' : '+';
    return '<span class="money fw7" style="color:' . ($v < 0 ? 'var(--bad)' : 'var(--ok)') . '">' . $sign . Security::e(inr(abs($v))) . '</span>';
}

/** Hidden inputs every form on this page carries. */
function a360_form_head(string $tab, string $action, int $agentId, string $csrf, string $k): string
{
    return '<input type="hidden" name="' . $k . '" value="' . $csrf . '">'
         . '<input type="hidden" name="agent_id" value="' . $agentId . '">'
         . '<input type="hidden" name="tab" value="' . Security::e($tab) . '">'
         . '<input type="hidden" name="action" value="' . Security::e($action) . '">';
}

/** Loan recovery rule as the office reads it. */
function a360_recover_label(array $l): string
{
    return match ((string) ($l['recover_mode'] ?? 'full')) {
        'fixed'   => 'plan ' . inr((float) $l['recover_value']) . ' per payout · nets from commission',
        'percent' => 'plan ' . rtrim(rtrim(number_format((float) $l['recover_value'], 2), '0'), '.') . '% of each payout · nets from commission',
        default   => 'commission nets it automatically',
    };
}

$pageUrl = $base . '/admin/agent-360.php?agent=' . $agentId;

admin_header('Agent 360 · ' . $name, 'agents');
/* 17 Sep 2026 — one-click WhatsApp (admin_wa_button → admin/api/wa-send.php):
   the statement for the window the Statement tab shows, the balance today,
   and a payment reminder only while the agent owes on balance. Each helper
   returns '' when wa_admin_tools_enabled is off or the role may not send
   that purpose, so the row simply disappears. */
$waHead = admin_wa_button('agent_statement', ['agent' => $agentId, 'from' => $stmtFrom, 'to' => $stmtTo], 'Statement', ['class' => 'btn ghost sm'])
        . admin_wa_button('agent_outstanding', ['agent' => $agentId], 'Outstanding balance', ['class' => 'btn ghost sm'])
        . ($net['net'] > 0.009 ? admin_wa_button('agent_payment_reminder', ['agent' => $agentId], 'Payment reminder', ['class' => 'btn ghost sm']) : '');
admin_page_head(
    'Profile, wallet, statement, settlements, loans, deposits, payout requests, KYC and activity — one place.',
    ['Agents' => $base . '/admin/agents.php', $name => ''],
    '<a class="btn ghost" href="' . $base . '/admin/agent.php?agent=' . $agentId . '"><svg class="a-ic"><use href="#a-wallet"/></svg> Open agent panel</a>'
    . '<a class="btn ghost" href="' . $base . '/admin/agents.php"><svg class="a-ic"><use href="#a-arrow-left"/></svg> Register</a>'
    . ($waHead !== '' ? '<span class="chips" style="gap:6px">' . $waHead . '</span>' : '')
);

if ($flash !== null) {
    $waLink = (string) ($flash[2] ?? '');
    echo '<div class="flash ' . $e($flash[0]) . '"><div>' . $e($flash[1])
       . ($waLink !== '' && str_starts_with($waLink, 'https://wa.me/')
            ? ' <a class="btn wa sm" style="margin-left:8px" href="' . $e($waLink) . '" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-whatsapp"/></svg> Send on WhatsApp</a>'
            : '')
       . '</div></div>';
}
?>
<style>
.a360-head{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start}
.a360-id{flex:1 1 320px;min-width:0;display:flex;gap:16px;align-items:flex-start}
.a360-id h2{margin:0;font-size:20px;font-weight:800;letter-spacing:-.01em;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.a360-id .sub{color:var(--mut);font-size:13px;margin-top:4px;display:flex;gap:6px 12px;flex-wrap:wrap}
.a360-pills{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
.a360-net{flex:0 1 260px;background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.a360-net .k{font-size:11.5px;color:var(--mut);text-transform:uppercase;letter-spacing:.08em;font-weight:700}
.a360-net .v{font-size:26px;font-weight:800;letter-spacing:-.02em;margin-top:4px;font-variant-numeric:tabular-nums}
.a360-net .s{font-size:12px;color:var(--mut);margin-top:3px}
.a360-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.a360-tab[hidden]{display:none}
.a360-forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:18px}
.a360-forms form{background:var(--soft);border:1px solid var(--line);border-radius:14px;padding:14px 16px}
.a360-forms form>strong{display:block;font-size:13.5px;margin-bottom:4px}
.a360-forms .hint{font-size:12px;color:var(--mut);margin:0 0 10px}
.a360-forms .inp{width:100%}
.a360-bal{display:flex;gap:6px 18px;flex-wrap:wrap;font-size:13px}
.tabs a .a360-cnt{display:inline-block;min-width:18px;padding:0 6px;margin-left:6px;border-radius:999px;background:var(--orange);color:#fff;font-size:11px;line-height:18px;text-align:center}
.a360-tl-diff{font-size:12px;color:var(--mut);margin-top:2px;word-break:break-word}
details.a360-rows>summary{cursor:pointer;font-size:12.5px;color:var(--blue);font-weight:700}
@media(max-width:640px){.a360-net{flex-basis:100%}}
@media print{.tabs,.page-head .ph-actions,.a360-forms,.a360-acts,.no-print{display:none !important}}
</style>

<!-- ============ HEADER CARD ============ -->
<div class="panel">
  <div class="panel-body a360-head">
    <div class="a360-id">
      <?= admin_avatar($name, $photoUrl, 'xl') ?>
      <div style="min-width:0;flex:1">
        <h2><?= $e($name) ?>
          <?php if ($code !== ''): ?><span class="pill st-violet nodot mono"><?= $e($code) ?></span><?php endif; ?>
        </h2>
        <div class="sub">
          <span><?= $e(AgentWallet::kindLabel($kind)) ?><?= $kind === AgentWallet::KIND_ORG && (string) ($profile['contact_person'] ?? '') !== '' ? ' · ' . $e($profile['contact_person']) : '' ?></span>
          <span title="<?= $e($paySub) ?>"><svg class="a-ic sm"><use href="#a-percent"/></svg> <?= $e($payLabel) ?> <span class="muted">(<?= $e($paySub) ?>)</span></span>
          <?php if ((string) ($profile['counter_name'] ?? '') !== ''): ?><span><svg class="a-ic sm"><use href="#a-map-pin"/></svg> <?= $e($profile['counter_name']) ?></span><?php endif; ?>
          <?php if ((string) ($agent['phone'] ?? '') !== ''): ?><span class="mono"><svg class="a-ic sm"><use href="#a-phone"/></svg> <?= $e($agent['phone']) ?></span><?php endif; ?>
          <span class="mono muted">@<?= $e($agent['username']) ?></span>
        </div>
        <div class="a360-pills">
          <?= $isSuspended ? admin_pill('suspended') : admin_pill($isActive ? 'active' : 'inactive') ?>
          <?= a360_kyc_pill($kycStatus) ?>
          <?php if ($overdue > 0): ?><span class="pill st-bad">Settlement overdue <?= (int) $overdue ?>d</span><?php endif; ?>
          <?php if ($overCash): ?><span class="pill st-bad">Over cash limit</span><?php endif; ?>
          <?php if ($openReqs !== []): ?><span class="pill st-orange"><?= count($openReqs) ?> payout request<?= count($openReqs) === 1 ? '' : 's' ?> open</span><?php endif; ?>
          <?php if ($openLoans !== []): ?><span class="pill st-warn"><?= $e(inr($loanOutstanding)) ?> on loan</span><?php endif; ?>
          <?php if ($salary > 0): ?><span class="pill st-info nodot">Salary <?= $e(inr($salary)) ?>/mo</span><?php endif; ?>
        </div>
        <div class="a360-acts no-print">
          <?php if ($waDigits !== ''): ?>
            <a class="btn wa sm" href="https://wa.me/<?= $e($waDigits) ?>" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-whatsapp"/></svg> WhatsApp</a>
          <?php endif; ?>
          <a class="btn ghost sm" href="<?= $base ?>/admin/agent.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-wallet"/></svg> Open agent panel</a>
          <a class="btn ghost sm" href="<?= $base ?>/admin/agent-sales.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-chart-up"/></svg> Sales</a>
          <a class="btn ghost sm" href="<?= $base ?>/admin/agent-passengers.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-id-card"/></svg> Passengers</a>
          <a class="btn ghost sm" href="<?= $base ?>/admin/agent-offline.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-notepad"/></svg> Paper tickets</a>
          <a class="btn ghost sm" href="<?= $base ?>/admin/bookings.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-ticket"/></svg> Tickets</a>
        </div>
      </div>
    </div>
    <div class="a360-net">
      <div class="k">Net position</div>
      <div class="v" style="color:<?= $net['net'] > 0.009 ? 'var(--orange-600)' : ($net['net'] < -0.009 ? 'var(--blue-600)' : 'var(--ok)') ?>"><?= $e(inr(abs($net['net']))) ?></div>
      <div class="s"><?php if ($net['net'] > 0.009): ?>agent owes the company on balance<?php elseif ($net['net'] < -0.009): ?>company owes the agent on balance<?php else: ?>settled — nothing owed either way<?php endif; ?></div>
      <div class="a360-bal" style="margin-top:10px">
        <span>Commission due <b class="money"><?= $e(inr($balances['commission'])) ?></b></span>
        <span>Cash held <b class="money"><?= $e(inr($balances['cash'])) ?></b></span>
      </div>
    </div>
  </div>
</div>

<!-- ============ TABS ============ -->
<div class="tabs no-print" id="a360Tabs">
  <?php foreach ($tabs as $key => $label):
    $cnt = $key === 'requests' ? count($openReqs) : ($key === 'loans' ? count($openLoans) : 0); ?>
    <a href="<?= $e($pageUrl . '&tab=' . $key) ?>" data-tab="<?= $e($key) ?>" class="<?= $key === $tab ? 'on' : '' ?>"><?= $e($label) ?><?= $cnt > 0 ? '<span class="a360-cnt">' . $cnt . '</span>' : '' ?></a>
  <?php endforeach; ?>
</div>

<!-- ============ REPORT (3 Oct 2026) ============
     The tab the agent's name opens on: what they sold and earned for any
     window, the controls the office reaches for most (commission rate ±,
     a signed commission correction, agent code, enable / disable, quick
     edit), and a one-tap image statement drawn in the browser (canvas —
     instant, and the browser shapes Nepali correctly). -->
<?php
$rt   = $report['totals'];
$rq   = static function (array $over = []) use ($pageUrl, $repWin): string {
    $p = ['tab' => 'report', 'range' => $repWin['preset'], 'basis' => $repWin['basis'] === 'sale' ? 'sale' : ''];
    if ($repWin['preset'] === 'custom') { $p['rfrom'] = $repWin['from']; $p['rto'] = $repWin['last']; }
    $p = array_merge($p, $over);
    return $pageUrl . '&' . http_build_query(array_filter($p, static fn($v) => $v !== '' && $v !== null));
};
$pdfUrl = $base . '/admin/agent-sales.php?' . http_build_query(['agent' => $agentId, 'range' => 'custom', 'from' => $repWin['from'], 'to' => $repWin['last'], 'basis' => $repWin['basis'], 'format' => 'pdf']);
$rateNum = (int) round($rate['flat']);
/* Everything the image statement draws — the same numbers the tab shows. */
$imgData = [
    'company' => Settings::getString('company_name', APP_NAME),
    'phone'   => Settings::getString('company_phone', ''),
    'agent'   => $name,
    'code'    => $code,
    'counter' => (string) ($profile['counter_name'] ?? ''),
    'period'  => $repWin['label'],
    'basis'   => $repWin['basis'] === 'sale' ? 'by sale date' : 'by journey date',
    'rate'    => $rate['label'],
    'made'    => date('j M Y, g:i A'),
    'totals'  => $rt,
    'balance' => ['commission' => $balances['commission'], 'cash' => $balances['cash'], 'net' => $net['net']],
    'days'    => array_slice($report['days'], 0, 31),
    'buses'   => array_slice($report['buses'], 0, 31),
    'tickets' => array_map(static fn(array $t): array => [
        'pnr' => $t['pnr'], 'd' => $t['travel'], 'p' => $t['passenger'], 's' => $t['seats'], 'n' => $t['pax'],
        'a' => $t['amount'], 'c' => $t['commission'], 'st' => $t['status'],
    ], array_slice($report['tickets'], 0, 60)),
    'ticketsAll' => count($report['tickets']),
    'file'    => 'statement-' . preg_replace('/[^a-z0-9]+/i', '-', ($code !== '' ? $code . '-' : '') . $name) . '-' . $repWin['from'] . '_' . $repWin['last'] . '.png',
];
?>
<section class="a360-tab" data-tab="report"<?= $tab === 'report' ? '' : ' hidden' ?>>
  <style>
  .rp-bar{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
  .rp-bar .chip-l{display:inline-flex;align-items:center;padding:7px 13px;border-radius:999px;border:1.5px solid var(--line);background:var(--card);color:inherit;font-size:13px;font-weight:700;text-decoration:none}
  .rp-bar .chip-l.on{background:var(--navy,#0f2a4a);border-color:var(--navy,#0f2a4a);color:#fff}
  .rp-bar form{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
  .rp-bar input[type=date]{padding:6px 8px;border:1.5px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:13px}
  .rp-basis{display:inline-flex;border:1.5px solid var(--line);border-radius:12px;overflow:hidden}
  .rp-basis a{padding:7px 12px;font-size:12.5px;font-weight:700;text-decoration:none;color:inherit;background:var(--card)}
  .rp-basis a+a{border-left:1.5px solid var(--line)}
  .rp-basis a.on{background:#0f766e;color:#fff}
  .rp-period{font-size:13px;color:var(--mut);margin:10px 0 0}
  .rp-period b{color:var(--ink)}
  .rp-acts{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
  .rp-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start}
  @media(max-width:1000px){.rp-grid{grid-template-columns:minmax(0,1fr)}}
  .rp-ctl{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;gap:14px}
  .rp-ctl h3{margin:0;font-size:15px;font-weight:800;display:flex;align-items:center;gap:8px}
  .rp-box{border:1px solid var(--line);border-radius:12px;padding:12px;background:var(--soft)}
  .rp-box>b{display:block;font-size:13px;margin-bottom:6px}
  .rp-box .hint{font-size:11.5px;color:var(--mut);margin:6px 0 0;line-height:1.45}
  .rp-rate{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
  .rp-rate .now{font-size:22px;font-weight:800;letter-spacing:-.02em;font-variant-numeric:tabular-nums}
  .rp-step{display:flex;gap:6px;align-items:center;margin-top:8px}
  .rp-step button.st{min-width:46px;padding:8px 0;border-radius:10px;border:1.5px solid var(--line);background:var(--card);color:var(--ink);font-weight:800;cursor:pointer}
  .rp-step button.st.dn{color:var(--bad)} .rp-step button.st.up{color:var(--ok)}
  .rp-step input{width:90px;text-align:center;font-weight:800;font-size:16px;padding:8px;border:1.5px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink)}
  .rp-row{display:flex;gap:6px;align-items:center;flex-wrap:wrap}
  .rp-row input,.rp-row select{padding:8px 10px;border:1.5px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:13px;min-width:0}
  .rp-edit{display:grid;gap:6px}
  .rp-edit input{width:100%;padding:8px 10px;border:1.5px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font-size:13px}
  .rp-tbl td,.rp-tbl th{white-space:nowrap}
  .rp-tbl tfoot td{font-weight:800;border-top:2px solid var(--line)}
  #rpImgModal{position:fixed;inset:0;z-index:9999;background:rgba(10,20,35,.72);display:flex;align-items:flex-start;justify-content:center;padding:18px;overflow:auto}
  #rpImgModal[hidden]{display:none}
  #rpImgModal .box{background:var(--card);border-radius:16px;padding:14px;max-width:760px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.35)}
  #rpImgModal img{width:100%;height:auto;border-radius:10px;border:1px solid var(--line);display:block;margin-top:10px}
  #rpImgModal .bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
  </style>

  <div class="panel">
    <div class="panel-body">
      <div class="rp-bar">
        <?php foreach (AgentReport::PRESETS as $pk => $pl): if ($pk === 'custom') { continue; } ?>
          <a class="chip-l<?= $repWin['preset'] === $pk ? ' on' : '' ?>" href="<?= $e($rq(['range' => $pk, 'rfrom' => null, 'rto' => null])) ?>"><?= $e($pl) ?></a>
        <?php endforeach; ?>
        <form method="get" action="<?= $e($base . '/admin/agent-360.php') ?>">
          <input type="hidden" name="agent" value="<?= $agentId ?>">
          <input type="hidden" name="tab" value="report">
          <input type="hidden" name="range" value="custom">
          <?php if ($repWin['basis'] === 'sale'): ?><input type="hidden" name="basis" value="sale"><?php endif; ?>
          <input type="date" name="rfrom" value="<?= $e($repWin['from']) ?>" aria-label="From date">
          <span class="muted">→</span>
          <input type="date" name="rto" value="<?= $e($repWin['last'] > todayISO() && $repWin['preset'] !== 'custom' ? $repWin['last'] : $repWin['last']) ?>" aria-label="To date">
          <button class="btn sm<?= $repWin['preset'] === 'custom' ? '' : ' ghost' ?>" type="submit">Show</button>
        </form>
        <span class="rp-basis" style="margin-left:auto">
          <a class="<?= $repWin['basis'] === 'journey' ? 'on' : '' ?>" href="<?= $e($rq(['basis' => null])) ?>" title="Count by the day the passenger travels">🚌 Journey date</a>
          <a class="<?= $repWin['basis'] === 'sale' ? 'on' : '' ?>" href="<?= $e($rq(['basis' => 'sale'])) ?>" title="Count by the day the ticket was cut">🧾 Sale date</a>
        </span>
      </div>
      <p class="rp-period"><b><?= $e($repWin['label']) ?></b> · <?= $repWin['basis'] === 'sale' ? 'tickets cut in this period' : 'passengers travelling in this period' ?> · current rate <b><?= $e($rate['label']) ?></b> <span class="muted">(<?= $e($rate['sub']) ?>)</span></p>
      <div class="rp-acts no-print">
        <button class="btn" type="button" id="rpImgBtn"><svg class="a-ic"><use href="#a-download"/></svg> 📷 Statement image</button>
        <a class="btn ghost" href="<?= $e($pdfUrl) ?>"><svg class="a-ic"><use href="#a-ledger"/></svg> PDF</a>
        <button class="btn ghost" type="button" onclick="window.print()">🖨 Print</button>
        <a class="btn ghost" href="<?= $e($base . '/admin/agent-sales.php?' . http_build_query(['agent' => $agentId, 'range' => 'custom', 'from' => $repWin['from'], 'to' => $repWin['last'], 'basis' => $repWin['basis']])) ?>"><svg class="a-ic"><use href="#a-chart-up"/></svg> Full sales book</a>
      </div>
    </div>
  </div>

  <div class="kpis">
    <?= admin_kpi('Passengers · जना', number_format((int) $rt['pax']), (int) $rt['tickets'] . ' ticket' . ((int) $rt['tickets'] === 1 ? '' : 's') . ((int) $rt['cancelled'] > 0 ? ' · ' . (int) $rt['cancelled'] . ' cancelled' : ''), 'users', 'navy') ?>
    <?= admin_kpi('Sales · बिक्री', inr((float) $rt['sale']), (int) $rt['confirmed'] . ' confirmed' . ((int) $rt['pending'] > 0 ? ' · ' . (int) $rt['pending'] . ' pending' : ''), 'rupee', 'blue') ?>
    <?= admin_kpi('Commission · कमिसन', inr((float) $rt['commission']), (int) $rt['pax'] > 0 ? 'avg ' . inr(round((float) $rt['commission'] / max(1, (int) $rt['pax']))) . ' / pax' : 'on these tickets', 'coins', 'green') ?>
    <?= admin_kpi('Company keeps', inr((float) $rt['company']), 'sales − commission', 'chart-up', 'violet') ?>
    <?php if (abs((float) $rt['adjust']) > 0.009): ?>
      <?= admin_kpi('Corrections', ((float) $rt['adjust'] < 0 ? '−' : '+') . inr(abs((float) $rt['adjust'])), 'manual ± in this period', 'ledger', 'orange') ?>
    <?php endif; ?>
    <?= admin_kpi('Commission due now', inr($balances['commission']), ((float) $rt['payout'] > 0 ? 'paid ' . inr((float) $rt['payout']) . ' in period · ' : '') . 'cash held ' . inr($balances['cash']), 'wallet', 'teal') ?>
  </div>

  <div class="rp-grid">
    <div style="min-width:0;display:flex;flex-direction:column;gap:18px">
      <?php if ($report['buses'] !== []): ?>
      <div class="panel" style="margin:0">
        <h2><svg class="a-ic"><use href="#a-bus"/></svg> Bus by bus <span class="muted" style="font-weight:500">· journey date</span></h2>
        <div class="tbl-scroll">
          <table class="rp-tbl">
            <thead><tr><th>Date</th><th>Route</th><th>Bus</th><th class="num">जना</th><th class="num">Sales</th><th class="num">Commission</th></tr></thead>
            <tbody>
            <?php foreach ($report['buses'] as $b): ?>
              <tr>
                <td><?= $e($b['d'] !== '' ? formatDate($b['d'], 'D j M') : '—') ?></td>
                <td><?= $e($b['route'] ?: '—') ?><?= (int) $b['slot'] > 1 ? ' <span class="pill st-info nodot">Bus ' . (int) $b['slot'] . '</span>' : '' ?></td>
                <td class="mono"><?= $e($b['bus'] ?: '—') ?></td>
                <td class="num fw7"><?= (int) $b['pax'] ?></td>
                <td class="num money"><?= $e(inr((float) $b['sale'])) ?></td>
                <td class="num money"><?= $e(inr((float) $b['commission'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

      <div class="panel" style="margin:0">
        <h2><svg class="a-ic"><use href="#a-calendar"/></svg> Day by day</h2>
        <div class="tbl-scroll">
          <table class="rp-tbl">
            <thead><tr><th>Date</th><th class="num">Tickets</th><th class="num">जना</th><th class="num">Sales</th><th class="num">Commission</th></tr></thead>
            <tbody>
            <?php if ($report['days'] === []): ?>
              <tr><td colspan="5"><?= admin_empty('Nothing in this period', 'Pick another range above, or switch Journey / Sale date.', '🗓️') ?></td></tr>
            <?php else: foreach ($report['days'] as $d): ?>
              <tr>
                <td><?= $e(formatDate($d['d'], 'D j M Y')) ?></td>
                <td class="num"><?= (int) $d['tickets'] ?></td>
                <td class="num fw7"><?= (int) $d['pax'] ?></td>
                <td class="num money"><?= $e(inr((float) $d['sale'])) ?></td>
                <td class="num money"><?= $e(inr((float) $d['commission'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if ($report['days'] !== []): ?>
            <tfoot><tr><td>Total</td><td class="num"><?= (int) $rt['tickets'] ?></td><td class="num"><?= (int) $rt['pax'] ?></td><td class="num money"><?= $e(inr((float) $rt['sale'])) ?></td><td class="num money"><?= $e(inr((float) $rt['commission'])) ?></td></tr></tfoot>
            <?php endif; ?>
          </table>
        </div>
      </div>

      <div class="panel" style="margin:0">
        <h2><svg class="a-ic"><use href="#a-ticket"/></svg> Tickets <span class="muted" style="font-weight:500">· <?= count($report['tickets']) ?><?= $report['truncated'] ? '+ (first 2000)' : '' ?></span></h2>
        <div class="tbl-scroll" style="max-height:520px;overflow:auto">
          <table class="rp-tbl">
            <thead><tr><th>PNR</th><th>Journey</th><th>Passenger</th><th>Seats</th><th class="num">जना</th><th class="num">Amount</th><th class="num">Commission</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($report['tickets'] === []): ?>
              <tr><td colspan="8"><?= admin_empty('No tickets in this period', '', '🎫') ?></td></tr>
            <?php else: foreach ($report['tickets'] as $t): ?>
              <tr>
                <td class="mono"><a href="<?= $e($base . '/admin/bookings.php?q=' . rawurlencode($t['pnr'])) ?>"><?= $e($t['pnr']) ?></a></td>
                <td><?= $e($t['travel'] !== '' ? formatDate($t['travel'], 'j M') : '—') ?> <span class="muted"><?= $e($t['route']) ?></span></td>
                <td><?= $e($t['passenger'] ?: '—') ?></td>
                <td class="mono"><?= $e($t['seats'] ?: '—') ?></td>
                <td class="num"><?= (int) $t['pax'] ?></td>
                <td class="num money"><?= $e(inr((float) $t['amount'])) ?></td>
                <td class="num money"><?= abs((float) $t['commission']) > 0.009 ? $e(inr((float) $t['commission'])) : '<span class="muted">—</span>' ?></td>
                <td><?= admin_pill($t['status']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ---- Commission & control ---- -->
    <aside class="rp-ctl no-print">
      <h3><svg class="a-ic"><use href="#a-percent"/></svg> Commission &amp; control</h3>

      <div class="rp-box">
        <b>Commission rate · कमिसन दर</b>
        <div class="rp-rate"><span class="now"><?= $e($rate['label']) ?></span> <span class="muted" style="font-size:12px">(<?= $e($rate['sub']) ?>)</span></div>
        <?php if ($canManage): ?>
          <form method="post" id="rpRateForm">
            <?= a360_form_head('report', 'rate_set', $agentId, $csrf, $k) ?>
            <div class="rp-step">
              <button class="st dn" type="button" data-step="-50" aria-label="Minus 50">−50</button>
              <button class="st dn" type="button" data-step="-10" aria-label="Minus 10">−10</button>
              <input type="number" name="rate_flat" id="rpRate" min="0" max="10000" step="1" value="<?= $rateNum ?>" aria-label="Rupees per passenger" required>
              <button class="st up" type="button" data-step="10" aria-label="Plus 10">+10</button>
              <button class="st up" type="button" data-step="50" aria-label="Plus 50">+50</button>
            </div>
            <label class="rp-reason" for="rpReason" style="display:block;margin-top:8px;font-size:12.5px;color:var(--mut)">Reason (kept in the history)</label>
            <input type="text" name="rate_reason" id="rpReason" maxlength="255" minlength="5" placeholder="e.g. new agreement from 1 Nov" style="width:100%" aria-describedby="rpReasonHint">
            <div class="rp-row" style="margin-top:8px">
              <button class="btn sm" type="submit" onclick="if((document.getElementById('rpReason').value||'').trim().length<5){alert('Write a short reason first (at least 5 characters).');document.getElementById('rpReason').focus();return false}return confirm('Set this agent\'s commission to ₹' + document.getElementById('rpRate').value + ' per passenger from the next ticket?')">Save ₹ / passenger</button>
              <?php if ($rate['mode'] !== 'tier'): ?>
                <button class="btn ghost sm" type="submit" name="action" value="rate_reset" formnovalidate onclick="if((document.getElementById('rpReason').value||'').trim().length<5){alert('Write a short reason first (at least 5 characters).');document.getElementById('rpReason').focus();return false}return confirm('Put this agent back on the company tier (<?= $e(inr($rate['tier'])) ?> / pax)?')">Use company tier (<?= $e(inr($rate['tier'])) ?>)</button>
              <?php endif; ?>
            </div>
          </form>
          <p class="hint" id="rpReasonHint">Applies from the <b>next ticket</b>. Tickets already sold keep the commission they earned — fix an old figure with “Add / deduct” below. Every change, with its reason, is listed on the <a href="/admin/agent-statement.php?agent=<?= (int) $agentId ?>">statement page</a>.</p>
        <?php else: ?>
          <p class="hint">Only a staff manager or super-admin can change the rate.</p>
        <?php endif; ?>
      </div>

      <?php if ($showSettle): ?>
      <div class="rp-box">
        <b>Add / deduct commission · थप / घटाउ</b>
        <form method="post">
          <?= a360_form_head('report', 'comm_adjust', $agentId, $csrf, $k) ?>
          <div class="rp-row">
            <select name="direction" aria-label="Add or deduct"><option value="credit">＋ Add</option><option value="debit">− Deduct</option></select>
            <input type="number" name="amount" min="1" step="1" placeholder="₹ amount" style="width:110px" required>
          </div>
          <div class="rp-row" style="margin-top:6px">
            <input type="text" name="note" maxlength="255" placeholder="Reason (bonus, short cash, wrong rate…)" style="flex:1" required minlength="5">
            <button class="btn sm" type="submit" onclick="return confirm('Record this commission change?')">Save</button>
          </div>
        </form>
        <p class="hint">Commission due now <b><?= $e(inr($balances['commission'])) ?></b>. Every change is on the statement and the audit trail.</p>
      </div>
      <?php endif; ?>

      <?php if ($canManage): ?>
      <div class="rp-box">
        <b>Agent code · एजेन्ट कोड</b>
        <form method="post" class="rp-row">
          <?= a360_form_head('report', 'code_set', $agentId, $csrf, $k) ?>
          <span class="pill st-violet nodot mono"><?= $e($code ?: 'none') ?></span>
          <input type="number" name="agent_code" min="<?= AgentWallet::AGENT_CODE_MIN ?>" max="<?= AgentWallet::AGENT_CODE_MAX ?>" step="1" value="<?= AgentWallet::agentCodeFor($agentId) ?? '' ?>" placeholder="number" style="width:100px">
          <button class="btn sm" type="submit" onclick="return confirm('Change this agent\'s code? New tickets and the panel show the new code.')">Change</button>
        </form>
        <p class="hint">Number <?= AgentWallet::AGENT_CODE_MIN ?>–<?= AgentWallet::AGENT_CODE_MAX ?>, must be unused. Blank = remove the code.</p>
      </div>

      <div class="rp-box">
        <b>Status · स्थिति</b>
        <form method="post" class="rp-row">
          <?= a360_form_head('report', 'toggle_active', $agentId, $csrf, $k) ?>
          <?= admin_pill($isActive ? 'active' : 'inactive') ?>
          <?php if ($agentId !== $selfId): ?>
            <button class="btn sm <?= $isActive ? 'danger' : 'ok' ?>" type="submit" onclick="return confirm('<?= $isActive ? 'Disable this agent? They can no longer sign in or sell.' : 'Enable this agent again?' ?>')"><?= $isActive ? '⏸ Disable' : '▶ Enable' ?></button>
          <?php endif; ?>
        </form>
      </div>

      <div class="rp-box">
        <details>
          <summary style="cursor:pointer;font-weight:800;font-size:13px">✏️ Edit details · विवरण</summary>
          <form method="post" class="rp-edit" style="margin-top:8px">
            <?= a360_form_head('report', 'edit_basic', $agentId, $csrf, $k) ?>
            <label class="muted" style="font-size:12px">Name<input name="full_name" maxlength="120" value="<?= $e($agent['full_name']) ?>" required></label>
            <label class="muted" style="font-size:12px">Mobile<input name="phone" inputmode="tel" maxlength="20" value="<?= $e($agent['phone']) ?>" required></label>
            <label class="muted" style="font-size:12px">WhatsApp<input name="whatsapp" inputmode="tel" maxlength="20" value="<?= $e($profile['whatsapp'] ?? '') ?>" placeholder="same as mobile"></label>
            <label class="muted" style="font-size:12px">City / counter<input name="counter_name" maxlength="120" value="<?= $e($profile['counter_name'] ?? '') ?>"></label>
            <div class="rp-row"><button class="btn sm" type="submit">Save details</button>
              <a class="btn ghost sm" href="<?= $e($base . '/admin/agents.php') ?>">More fields on the register</a></div>
          </form>
        </details>
      </div>
      <?php endif; ?>
    </aside>
  </div>

  <div id="rpImgModal" hidden role="dialog" aria-modal="true" aria-label="Statement image">
    <div class="box">
      <div class="bar">
        <b style="font-size:15px">📷 Statement image</b>
        <span class="muted" style="font-size:12px"><?= $e($repWin['label']) ?></span>
        <span style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn wa sm" type="button" id="rpImgShare"><svg class="a-ic"><use href="#a-whatsapp"/></svg> Share</button>
          <a class="btn sm" id="rpImgDl" download><svg class="a-ic"><use href="#a-download"/></svg> Download</a>
          <button class="btn ghost sm" type="button" id="rpImgClose">Close</button>
        </span>
      </div>
      <img id="rpImg" alt="Agent statement image">
    </div>
  </div>
  <script type="application/json" id="rpImgData"><?= json_encode($imgData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <script>
  (function () {
    /* −50/−10/+10/+50 steppers on the rate box. */
    var rate = document.getElementById('rpRate');
    document.querySelectorAll('#rpRateForm [data-step]').forEach(function (b) {
      b.addEventListener('click', function () {
        if (!rate) { return; }
        var v = Math.max(0, Math.min(10000, (parseInt(rate.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10)));
        rate.value = v;
      });
    });

    /* ---- Image statement: drawn on a canvas in the browser ---- */
    var D; try { D = JSON.parse(document.getElementById('rpImgData').textContent); } catch (e) { return; }
    var btn = document.getElementById('rpImgBtn'), modal = document.getElementById('rpImgModal');
    var img = document.getElementById('rpImg'), dl = document.getElementById('rpImgDl');
    var lastBlob = null;
    function inr(v) {
      v = Math.round(Number(v) || 0); var neg = v < 0; v = Math.abs(v);
      var s = String(v), last3 = s.slice(-3), rest = s.slice(0, -3);
      if (rest) { last3 = ',' + last3; rest = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ','); }
      return (neg ? '−' : '') + '₹' + rest + last3;
    }
    function fdate(iso) {
      if (!iso) { return '—'; }
      var p = iso.split('-'), m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
      return parseInt(p[2], 10) + ' ' + m[parseInt(p[1], 10) - 1];
    }
    function draw() {
      var W = 1080, P = 48, F = '"Noto Sans Devanagari","Nirmala UI","Segoe UI",Arial,sans-serif';
      var T = D.totals, days = D.days || [], buses = D.buses || [], tix = D.tickets || [];
      var rowH = 40;
      /* Generous height first; the unused tail is cut off at the end. */
      var H = 700
            + (buses.length ? 160 + (buses.length + 2) * rowH : 0)
            + (days.length ? 160 + (days.length + 3) * rowH : 0)
            + (tix.length ? 160 + (tix.length + 3) * rowH : 0)
            + 200;
      var c = document.createElement('canvas'); c.width = W; c.height = H;
      var g = c.getContext('2d');
      function txt(s, x, y, size, color, weight, align) {
        g.font = (weight || 400) + ' ' + size + 'px ' + F; g.fillStyle = color || '#0f172a';
        g.textAlign = align || 'left'; g.textBaseline = 'alphabetic'; g.fillText(String(s), x, y);
      }
      function fit(s, size, weight, maxW) {
        s = String(s || ''); g.font = (weight || 400) + ' ' + size + 'px ' + F;
        if (g.measureText(s).width <= maxW) { return s; }
        while (s.length > 1 && g.measureText(s + '…').width > maxW) { s = s.slice(0, -1); }
        return s + '…';
      }
      function rr(x, y, w, h, r, fill, stroke) {
        g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r);
        g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath();
        if (fill) { g.fillStyle = fill; g.fill(); } if (stroke) { g.strokeStyle = stroke; g.lineWidth = 2; g.stroke(); }
      }
      g.fillStyle = '#f4f6fb'; g.fillRect(0, 0, W, H);
      // Header band
      var grd = g.createLinearGradient(0, 0, W, 0); grd.addColorStop(0, '#0f2a4a'); grd.addColorStop(1, '#1e4f8a');
      g.fillStyle = grd; g.fillRect(0, 0, W, 210);
      txt(fit(D.company, 40, 800, W - 2 * P), P, 70, 40, '#ffffff', 800);
      txt('AGENT STATEMENT · एजेन्ट विवरण', P, 112, 24, '#ffd27a', 700);
      txt(fit(D.agent + (D.code ? '  ·  ' + D.code : ''), 34, 800, W - 2 * P), P, 165, 34, '#ffffff', 800);
      if (D.counter) { txt(fit(D.counter, 20, 400, 360), W - P, 165, 20, '#c9d8ee', 400, 'right'); }
      // Period strip
      var y = 240;
      rr(P, y, W - 2 * P, 64, 14, '#ffffff', '#dbe3ef');
      txt('📅 ' + D.period, P + 22, y + 41, 24, '#0f172a', 800);
      txt(D.basis + '  ·  rate ' + D.rate, W - P - 22, y + 41, 19, '#475569', 600, 'right');
      // KPI boxes
      y = 330;
      var k = [
        ['जना · Passengers', String(T.pax), '#0f2a4a'],
        ['बिक्री · Sales', inr(T.sale), '#1d4ed8'],
        ['कमिसन · Commission', inr(T.commission), '#047857']
      ];
      var bw = (W - 2 * P - 2 * 20) / 3;
      k.forEach(function (it, i) {
        var x = P + i * (bw + 20); rr(x, y, bw, 120, 16, '#ffffff', '#dbe3ef');
        txt(it[0], x + 20, y + 38, 19, '#64748b', 700);
        txt(fit(it[1], 40, 800, bw - 40), x + 20, y + 92, 40, it[2], 800);
      });
      y += 140;
      // Sub totals row
      rr(P, y, W - 2 * P, 120, 16, '#ffffff', '#dbe3ef');
      var sub = [
        ['Tickets', String(T.tickets) + (T.cancelled ? '  (' + T.cancelled + ' cancelled)' : '')],
        ['Company keeps', inr(T.company)],
        ['Commission due now', inr(D.balance.commission)],
        ['Cash held now', inr(D.balance.cash)]
      ];
      var sw = (W - 2 * P) / 4;
      sub.forEach(function (it, i) {
        var x = P + i * sw + 20;
        txt(it[0], x, y + 42, 17, '#64748b', 700);
        txt(fit(it[1], 26, 800, sw - 30), x, y + 86, 26, '#0f172a', 800);
      });
      y += 150;
      if (Math.abs(T.adjust) > 0.009 || T.payout > 0.009) {
        txt((Math.abs(T.adjust) > 0.009 ? 'Corrections in period: ' + (T.adjust < 0 ? '' : '+') + inr(T.adjust) + '    ' : '') + (T.payout > 0.009 ? 'Paid out in period: ' + inr(T.payout) : ''), P, y - 6, 19, '#92400e', 700);
        y += 20;
      }
      function table(title, cols, rows, foot) {
        txt(title, P, y + 30, 26, '#0f2a4a', 800); y += 50;
        rr(P, y, W - 2 * P, (rows.length + 1 + (foot ? 1 : 0)) * rowH + 8, 12, '#ffffff', '#dbe3ef');
        var x0 = P + 18, yy = y + 30;
        cols.forEach(function (cc) { txt(cc[0], cc[2] === 'right' ? x0 + cc[1] : x0 + cc[1], yy, 17, '#64748b', 800, cc[2] || 'left'); });
        yy += rowH;
        rows.forEach(function (r, ri) {
          if (ri % 2 === 0) { g.fillStyle = '#f8fafc'; g.fillRect(P + 2, yy - 28, W - 2 * P - 4, rowH); }
          cols.forEach(function (cc, ci) { txt(fit(r[ci], 19, ci === 0 ? 700 : 500, cc[3] || 300), x0 + cc[1], yy, 19, r.color && ci === cols.length - 1 ? r.color : '#0f172a', ci === 0 ? 700 : 500, cc[2] || 'left'); });
          yy += rowH;
        });
        if (foot) {
          g.fillStyle = '#e8eef8'; g.fillRect(P + 2, yy - 28, W - 2 * P - 4, rowH);
          cols.forEach(function (cc, ci) { txt(foot[ci] || '', x0 + cc[1], yy, 19, '#0f172a', 800, cc[2] || 'left'); });
          yy += rowH;
        }
        y = yy + 22;
      }
      var CW = W - 2 * P - 36;
      if (buses.length) {
        table('🚌 Bus by bus', [['Date', 0, 'left', 100], ['Route', 100, 'left', 330], ['Bus', 440, 'left', 170], ['जना', 690, 'right', 60], ['Sales', 830, 'right', 130], ['Commission', CW, 'right', 110]],
          buses.map(function (b) { return [fdate(b.d), b.route + (b.slot > 1 ? ' (Bus ' + b.slot + ')' : ''), b.bus || '—', String(b.pax), inr(b.sale), inr(b.commission)]; }), null);
      }
      if (days.length) {
        table('🗓 Day by day', [['Date', 0, 'left', 200], ['Tickets', CW - 480, 'right', 100], ['जना', CW - 330, 'right', 80], ['Sales', CW - 160, 'right', 150], ['Commission', CW, 'right', 150]],
          days.map(function (d) { return [fdate(d.d), String(d.tickets), String(d.pax), inr(d.sale), inr(d.commission)]; }),
          ['Total', String(T.tickets), String(T.pax), inr(T.sale), inr(T.commission)]);
      }
      if (tix.length) {
        var rows = tix.map(function (t) {
          var r = [t.pnr, fdate(t.d), t.p || '—', t.s || '—', inr(t.a), t.st === 'confirmed' || t.st === 'completed' ? inr(t.c) : t.st];
          if (!(t.st === 'confirmed' || t.st === 'completed')) { r.color = '#b91c1c'; }
          return r;
        });
        if (D.ticketsAll > tix.length) { rows.push(['+ ' + (D.ticketsAll - tix.length) + ' more', '', '', '', '', '']); }
        table('🎫 Tickets', [['PNR', 0, 'left', 200], ['Date', 205, 'left', 90], ['Passenger', 300, 'left', 250], ['Seats', 560, 'left', 120], ['Amount', CW - 150, 'right', 120], ['Comm.', CW, 'right', 120]], rows, null);
      }
      // Cut to the content, then the footer band on the final canvas.
      var bodyH = Math.min(H, y + 10), outH = bodyH + 70;
      var c2 = document.createElement('canvas'); c2.width = W; c2.height = outH; var g2 = c2.getContext('2d');
      g2.drawImage(c, 0, 0, W, bodyH, 0, 0, W, bodyH);
      c = c2; g = g2;
      g.fillStyle = '#0f2a4a'; g.fillRect(0, outH - 70, W, 70);
      txt('Generated ' + D.made + (D.phone ? '  ·  ' + D.phone : ''), P, outH - 28, 18, '#c9d8ee', 500);
      txt('Net: ' + (D.balance.net > 0.009 ? 'agent owes ' + inr(D.balance.net) : (D.balance.net < -0.009 ? 'company owes ' + inr(-D.balance.net) : 'settled')), W - P, outH - 28, 18, '#ffd27a', 700, 'right');
      return c;
    }
    function open() {
      var go = function () {
        var c = draw();
        c.toBlob(function (blob) {
          lastBlob = blob;
          var url = URL.createObjectURL(blob);
          img.src = url; dl.href = url; dl.setAttribute('download', D.file);
          modal.hidden = false;
        }, 'image/png');
      };
      if (document.fonts && document.fonts.load) {
        Promise.all([document.fonts.load('800 20px "Noto Sans Devanagari"'), document.fonts.load('400 20px "Noto Sans Devanagari"')]).then(go, go);
      } else { go(); }
    }
    if (btn) { btn.addEventListener('click', open); }
    document.getElementById('rpImgClose').addEventListener('click', function () { modal.hidden = true; });
    modal.addEventListener('click', function (ev) { if (ev.target === modal) { modal.hidden = true; } });
    document.getElementById('rpImgShare').addEventListener('click', function () {
      if (!lastBlob) { return; }
      var file = new File([lastBlob], D.file, { type: 'image/png' });
      if (navigator.canShare && navigator.canShare({ files: [file] })) {
        navigator.share({ files: [file], title: 'Agent statement', text: D.agent + ' · ' + D.period }).catch(function () {});
      } else {
        dl.click();
        alert('Image downloaded — attach it in WhatsApp. (Direct share works on phones.)');
      }
    });
  })();
  </script>
</section>

<!-- ============ OVERVIEW ============ -->
<section class="a360-tab" data-tab="overview"<?= $tab === 'overview' ? '' : ' hidden' ?>>
  <div class="kpis">
    <?= admin_kpi('Commission due', inr($balances['commission']), 'earned ' . inr($summary['earned']) . ' · paid ' . inr($summary['paidOut']), 'coins', 'green') ?>
    <?= admin_kpi('Cash in hand', inr($balances['cash']),
          'collected ' . inr($summary['collected']) . ' · handed over ' . inr($summary['handedOver']) . ($overdue > 0 ? ' · overdue ' . $overdue . 'd' : ''),
          'rupee', $overCash || $overdue > 0 ? 'red' : 'orange') ?>
    <?= admin_kpi('Net position', inr(abs($net['net'])),
          $net['net'] > 0.009 ? 'agent owes the company' : ($net['net'] < -0.009 ? 'company owes the agent' : 'settled'),
          'handshake', 'navy') ?>
    <?= admin_kpi('Earned this month', inr($summary['earnedMonth']), date('F Y'), 'chart-up', 'blue') ?>
    <?= admin_kpi('Lifetime earned', inr($summary['earned']), 'paid out ' . inr($summary['paidOut']) . ($summary['reversed'] > 0 ? ' · reversed ' . inr($summary['reversed']) : ''), 'ledger', 'violet') ?>
    <?= admin_kpi('Payout requests', (string) count($openReqs), $openReqs !== [] ? inr($openReqTotal) . ' waiting for a decision' : 'none open', 'send', $openReqs !== [] ? 'orange' : 'teal', null, $pageUrl . '&tab=requests') ?>
    <?php if ($openLoans !== [] || $advance['given'] > 0): ?>
      <?= admin_kpi('Loans & advances', inr($openLoans !== [] ? $loanOutstanding : $advance['outstanding']), $openLoans !== [] ? count($openLoans) . ' open · given ' . inr($advance['given']) : 'given ' . inr($advance['given']) . ' · repaid ' . inr($advance['repaid']), 'loan', 'orange', null, $pageUrl . '&tab=loans') ?>
    <?php endif; ?>
    <?php if ($salary > 0): ?>
      <?= admin_kpi('Monthly salary', inr($salary), 'credited so far ' . inr($salaryPaid) . (AgentWallet::salaryPosted($agentId, date('Y-m')) ? ' · ' . date('M') . ' posted' : ' · ' . date('M') . ' not posted'), 'banknote', 'blue') ?>
    <?php endif; ?>
    <?php if ($deposit['required'] > 0 || $deposit['paid'] > 0): ?>
      <?= admin_kpi('Security deposit', inr($deposit['paid']), 'of ' . inr($deposit['required']) . ' required' . ($deposit['met'] ? ' · met' : ' · ' . inr($deposit['short']) . ' short'), 'lock', $deposit['met'] ? 'teal' : 'orange', null, $pageUrl . '&tab=deposits') ?>
    <?php endif; ?>
  </div>

  <div class="dash-grid two-one">
    <div class="dash-panel">
      <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-chart"/></svg>Commission earned — last 12 months</div>
      <div class="dp-body">
        <?php $mMax = max(1.0, (float) max(array_column($months, 'earned') ?: [0])); ?>
        <div class="spark-wrap">
          <?php foreach ($months as $m): $h = max(4, ((float) $m['earned'] / $mMax) * 100); ?>
          <div class="spark-bar" style="height:<?= (int) $h ?>%">
            <div class="spark-tip"><?= $e(date('M Y', (int) strtotime($m['month'] . '-01'))) ?> · earned <?= $e(inr((float) $m['earned'])) ?><?= (float) $m['paidOut'] > 0 ? ' · paid ' . $e(inr((float) $m['paidOut'])) : '' ?><?= (float) $m['reversed'] > 0 ? ' · reversed ' . $e(inr((float) $m['reversed'])) : '' ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="spark-labels">
          <?php foreach ($months as $i => $m): $ts = (int) strtotime($m['month'] . '-01'); ?>
          <span><?= $e(date('M', $ts)) ?><?= ($i === 0 || date('n', $ts) === '1') ? '<br>' . $e(date('y', $ts)) : '' ?></span>
          <?php endforeach; ?>
        </div>
        <div class="tbl-scroll" style="margin-top:14px">
          <table>
            <thead><tr><th>Month</th><th class="num">Earned</th><th class="num">Reversed</th><th class="num">Paid out</th><th class="num">Salary / adv. / corr.</th><th class="num">Net</th></tr></thead>
            <tbody>
              <?php foreach (array_reverse($months) as $m): if ((float) $m['earned'] == 0.0 && (float) $m['paidOut'] == 0.0 && (float) $m['adjustment'] == 0.0 && (float) $m['reversed'] == 0.0) { continue; } ?>
              <tr>
                <td><?= $e(date('M Y', (int) strtotime($m['month'] . '-01'))) ?></td>
                <td class="num money"><?= $e(inr((float) $m['earned'])) ?></td>
                <td class="num money"><?= (float) $m['reversed'] > 0 ? '−' . $e(inr((float) $m['reversed'])) : '<span class="muted">—</span>' ?></td>
                <td class="num money"><?= (float) $m['paidOut'] > 0 ? '−' . $e(inr((float) $m['paidOut'])) : '<span class="muted">—</span>' ?></td>
                <td class="num money"><?= (float) $m['adjustment'] != 0.0 ? a360_amt((float) $m['adjustment']) : '<span class="muted">—</span>' ?></td>
                <td class="num money fw7"><?= a360_amt((float) $m['net']) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="dash-panel">
      <div class="dp-head"><svg class="a-ic" style="color:var(--blue)"><use href="#a-info"/></svg>At a glance</div>
      <div class="dp-body">
        <dl class="kv-list">
          <dt>Agent code</dt><dd class="mono"><?= $e($code ?: '— not issued') ?></dd>
          <dt>Kind</dt><dd><?= $e(AgentWallet::kindLabel($kind)) ?></dd>
          <dt>Commission</dt><dd><?= $e($payLabel) ?> <span class="muted">(<?= $e($paySub) ?>)</span></dd>
          <dt>Counter</dt><dd><?= $e($profile['counter_name'] ?: '—') ?></dd>
          <dt>Mobile</dt><dd class="mono"><?= $e($agent['phone'] ?: ($profile['display_phone'] ?: '—')) ?></dd>
          <dt>WhatsApp</dt><dd class="mono"><?= $e($profile['whatsapp'] ?: '— same as mobile') ?></dd>
          <dt>Cash limit</dt><dd><?= (float) ($profile['cash_limit'] ?? 0) > 0 ? $e(inr((float) $profile['cash_limit'])) : '<span class="muted">none</span>' ?></dd>
          <dt>Daily cap</dt><dd><?= (int) ($profile['daily_booking_limit'] ?? 0) > 0 ? (int) $profile['daily_booking_limit'] . ' bookings' : '<span class="muted">none</span>' ?></dd>
          <dt>Joined</dt><dd><?= $e($profile['joined_on'] ? formatDate((string) $profile['joined_on']) : formatDate(substr((string) $agent['created_at'], 0, 10))) ?></dd>
          <dt>Last sign-in</dt><dd><?= $agent['last_login_at'] ? $e(timeAgo((string) $agent['last_login_at'])) : '<span class="muted">never</span>' ?></dd>
          <dt>KYC</dt><dd><?= a360_kyc_pill($kycStatus) ?></dd>
          <dt>Payout to</dt><dd><?= $e(trim((string) ($profile['payout_method'] ?? '') . ' ' . (string) ($profile['payout_account'] ?? '')) ?: '—') ?></dd>
        </dl>
        <?php if ((string) ($profile['notes'] ?? '') !== ''): ?>
          <div class="note" style="margin-top:12px"><?= $e($profile['notes']) ?></div>
        <?php endif; ?>
        <?php if ($isSuspended): ?>
          <div class="flash bad" style="margin:12px 0 0">Suspended <?= $e(formatDate((string) $profile['suspended_at'])) ?><?= (string) ($profile['suspended_reason'] ?? '') !== '' ? ' — ' . $e($profile['suspended_reason']) : '' ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ STATEMENT ============ -->
<section class="a360-tab" data-tab="statement"<?= $tab === 'statement' ? '' : ' hidden' ?>>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-ledger"/></svg> Account statement
      <span class="muted" style="font-weight:500">· <?= $e(formatDate($stmtFrom, 'j M Y')) ?> → <?= $e(formatDate($stmtTo, 'j M Y')) ?></span>
    </h2>
    <div class="panel-body">
      <form method="get" class="form-actions no-print" style="margin:0 0 14px">
        <input type="hidden" name="agent" value="<?= $agentId ?>">
        <input type="hidden" name="tab" value="statement">
        <label class="text-sm muted">From <input class="inp" type="date" name="from" value="<?= $e($stmtFrom) ?>"></label>
        <label class="text-sm muted">To <input class="inp" type="date" name="to" value="<?= $e($stmtTo) ?>"></label>
        <label class="text-sm muted">Account
          <select class="inp" name="account">
            <option value="" <?= $stmtAcc === '' ? 'selected' : '' ?>>Both accounts</option>
            <option value="commission" <?= $stmtAcc === 'commission' ? 'selected' : '' ?>>Commission (company owes agent)</option>
            <option value="cash" <?= $stmtAcc === 'cash' ? 'selected' : '' ?>>Cash (agent owes company)</option>
          </select></label>
        <button class="btn" type="submit"><svg class="a-ic"><use href="#a-filter"/></svg> Show</button>
        <a class="btn ghost" href="<?= $e($pageUrl . '&tab=statement&export=csv&from=' . $stmtFrom . '&to=' . $stmtTo . '&account=' . $stmtAcc) ?>"><svg class="a-ic"><use href="#a-download"/></svg> Export CSV</a>
        <button class="btn ghost" type="button" onclick="window.print()"><svg class="a-ic"><use href="#a-printer"/></svg> Print</button>
        <?php /* 17 Sep 2026: the same window as the table, to the agent on WhatsApp (previewed first). */ ?>
        <?= admin_wa_button('agent_history', ['agent' => $agentId, 'from' => $stmtFrom, 'to' => $stmtTo], 'Booking history', ['class' => 'btn ghost']) ?>
        <?= admin_wa_button('agent_commission', ['agent' => $agentId, 'from' => $stmtFrom, 'to' => $stmtTo], 'Commission summary', ['class' => 'btn ghost']) ?>
      </form>
      <div class="tbl-scroll">
        <table>
          <thead><tr><th>When</th><th>Entry</th><th>Account</th><th>Booking / ref</th><th>Note</th><th class="num">Amount</th><th class="num">Balance after</th><th>By</th></tr></thead>
          <tbody>
            <tr style="background:var(--soft)">
              <td class="muted"><?= $e(formatDate($stmtFrom, 'j M Y')) ?></td>
              <td class="fw7" colspan="4">Opening balance</td>
              <td></td>
              <td class="num money fw7">
                <?php if ($stmtAcc === ''): ?>
                  commission <?= $e(inr(AgentWallet::openingBalance($agentId, $stmtFrom, 'commission'))) ?> · cash <?= $e(inr(AgentWallet::openingBalance($agentId, $stmtFrom, 'cash'))) ?>
                <?php else: ?>
                  <?= $e(inr((float) $statement['opening'])) ?>
                <?php endif; ?>
              </td>
              <td></td>
            </tr>
            <?php if ($statement['rows'] === []): ?>
              <tr><td colspan="8"><?= admin_empty('No movements in this window', 'Widen the dates, or pick the other account.', '📜') ?></td></tr>
            <?php else: foreach ($statement['rows'] as $r):
              [$icon, $label, $cls] = a360_ledger_look((string) $r['entry_type'], (string) ($r['ref'] ?? ''));
              $after = (string) $r['account'] === 'cash' ? (float) $r['running_cash'] : (float) $r['running_commission'];
              $isSettle = in_array((string) $r['entry_type'], ['payout', 'cash_handover'], true); ?>
              <tr>
                <td class="muted" style="white-space:nowrap"><?= $e(date('j M Y', (int) strtotime((string) $r['created_at']))) ?><div class="text-xs"><?= $e(date('H:i', (int) strtotime((string) $r['created_at']))) ?></div></td>
                <td><span class="pill <?= $e($cls) ?> nodot"><svg class="a-ic sm"><use href="#a-<?= $e($icon) ?>"/></svg> <?= $e($label) ?></span></td>
                <td class="muted"><?= $e(ucfirst((string) $r['account'])) ?></td>
                <td class="mono">
                  <?php if (!empty($r['pnr'])): ?>
                    <a href="<?= $base ?>/admin/booking-view.php?pnr=<?= urlencode((string) $r['pnr']) ?>"><?= $e($r['pnr']) ?></a>
                  <?php else: ?>
                    <span class="muted"><?= $e($r['ref'] ?: '—') ?></span>
                  <?php endif; ?>
                  <?php if ($isSettle): ?> <a class="text-xs" href="<?= $base ?>/admin/agent-receipt.php?id=<?= (int) $r['id'] ?>" target="_blank" title="Printable receipt"><svg class="a-ic sm"><use href="#a-receipt"/></svg></a><?php endif; ?>
                </td>
                <td class="muted" style="font-size:12px;max-width:260px"><?= $e(truncate((string) ($r['note'] ?? ''), 80)) ?></td>
                <td class="num"><?= a360_amt((float) $r['amount']) ?></td>
                <td class="num money"><?= $e(inr($after)) ?></td>
                <td class="muted text-xs"><?= $e($r['by_name'] ?: 'auto') ?></td>
              </tr>
            <?php endforeach; endif; ?>
            <tr style="background:var(--soft)">
              <td class="muted"><?= $e(formatDate($stmtTo, 'j M Y')) ?></td>
              <td class="fw7" colspan="4">Closing balance</td>
              <td></td>
              <td class="num money fw7">
                <?php if ($stmtAcc === ''): $last = $statement['rows'] !== [] ? end($statement['rows']) : null; ?>
                  commission <?= $e(inr($last !== null ? (float) $last['running_commission'] : AgentWallet::openingBalance($agentId, $stmtFrom, 'commission'))) ?>
                  · cash <?= $e(inr($last !== null ? (float) $last['running_cash'] : AgentWallet::openingBalance($agentId, $stmtFrom, 'cash'))) ?>
                  · net <?= $e(inr((float) $statement['closing'])) ?>
                <?php else: ?>
                  <?= $e(inr((float) $statement['closing'])) ?>
                <?php endif; ?>
              </td>
              <td></td>
            </tr>
          </tbody>
        </table>
      </div>
      <p class="muted text-xs" style="margin:12px 0 0">Balance after = that account's balance once the row is applied. Net = cash − commission (positive: the agent owes the company). Sales confirmed before the wallet was installed have no ledger rows.</p>
    </div>
  </div>
</section>

<!-- ============ SETTLEMENTS ============ -->
<section class="a360-tab" data-tab="settlements"<?= $tab === 'settlements' ? '' : ' hidden' ?>>
  <?php if ($showSettle): ?>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-handshake"/></svg> Settle this agent</h2>
    <div class="panel-body a360-forms">
      <form method="post" onsubmit="return confirm('Record this commission payout?')">
        <?= a360_form_head('settlements', 'payout', $agentId, $csrf, $k) ?>
        <strong>Pay commission to the agent</strong>
        <p class="hint"><?= $e(inr($balances['commission'])) ?> currently owed<?= $openReqs !== [] ? ' · a payout request for ' . $e(inr($openReqTotal)) . ' is open' : '' ?>.</p>
        <div class="form-grid">
          <div class="field"><label>Amount ₹</label><input class="inp" type="number" name="amount" step="0.01" min="0.01" max="<?= $e(number_format($balances['commission'], 2, '.', '')) ?>" required></div>
          <div class="field"><label>UPI / voucher ref</label><input class="inp" type="text" name="ref" maxlength="80" placeholder="blank = auto SV-number"></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255"></div>
        <div class="form-actions"><button class="btn ok" type="submit" <?= $balances['commission'] <= 0 ? 'disabled' : '' ?>><svg class="a-ic"><use href="#a-banknote"/></svg> Record payout</button></div>
      </form>
      <form method="post" onsubmit="return confirm('Record this cash handover?')">
        <?= a360_form_head('settlements', 'cash_handover', $agentId, $csrf, $k) ?>
        <strong>Accept cash from the agent</strong>
        <p class="hint"><?= $e(inr($balances['cash'])) ?> currently held<?= $overdue > 0 ? ' · overdue by ' . (int) $overdue . ' day' . ($overdue === 1 ? '' : 's') : '' ?>.</p>
        <div class="form-grid">
          <div class="field"><label>Amount ₹</label><input class="inp" type="number" name="amount" step="0.01" min="0.01" max="<?= $e(number_format($balances['cash'], 2, '.', '')) ?>" required></div>
          <div class="field"><label>Receipt no</label><input class="inp" type="text" name="ref" maxlength="80" placeholder="blank = auto SV-number"></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255"></div>
        <div class="form-actions"><button class="btn" type="submit" <?= $balances['cash'] <= 0 ? 'disabled' : '' ?>><svg class="a-ic"><use href="#a-handshake"/></svg> Record handover</button></div>
      </form>
    </div>
    <div class="panel-foot muted text-xs">The agent is told on WhatsApp when a settlement is recorded (Settings → agent_notify_settlement). Corrections and salary live on the Agent Panel's Settle block.</div>
  </div>
  <?php elseif ($selfWalled): ?>
    <div class="flash info">This is your own account — someone else must record its settlements.</div>
  <?php endif; ?>

  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-receipt"/></svg> Settlements <span class="muted" style="font-weight:500">· payouts and cash handovers, newest first</span></h2>
    <div class="tbl-scroll">
      <table>
        <thead><tr><th>When</th><th>Type</th><th>Voucher</th><th class="num">Amount</th><th>Note</th><th>Recorded by</th><th></th></tr></thead>
        <tbody>
          <?php if ($settlements === []): ?>
            <tr><td colspan="7"><?= admin_empty('No settlements yet', 'Payouts and cash handovers appear here with their voucher number.', '🧾') ?></td></tr>
          <?php else: foreach ($settlements as $s): [$icon, $label, $cls] = a360_ledger_look((string) $s['entry_type'], (string) ($s['ref'] ?? '')); ?>
            <tr>
              <td class="muted" style="white-space:nowrap"><?= $e(date('j M Y, H:i', (int) strtotime((string) $s['created_at']))) ?></td>
              <td><span class="pill <?= $e($cls) ?> nodot"><svg class="a-ic sm"><use href="#a-<?= $e($icon) ?>"/></svg> <?= $e($label) ?></span></td>
              <td class="mono"><?= $e($s['ref'] ?: '#' . (int) $s['id']) ?></td>
              <td class="num money fw7"><?= $e(inr(abs((float) $s['amount']))) ?></td>
              <td class="muted" style="font-size:12px"><?= $e(truncate((string) ($s['note'] ?? ''), 70)) ?></td>
              <td class="muted text-xs"><?= $e($s['by_name'] ?: 'auto') ?></td>
              <td><a class="btn ghost sm" href="<?= $base ?>/admin/agent-receipt.php?id=<?= (int) $s['id'] ?>" target="_blank"><svg class="a-ic"><use href="#a-printer"/></svg> Receipt</a>
                <?php if ((int) $s['id'] > 0): ?><?= admin_wa_button('agent_settlement_done', ['agent' => $agentId, 'ledger' => (int) $s['id']], '💬', ['class' => 'btn ghost sm', 'title' => 'Send this settlement receipt to the agent on WhatsApp — opens a preview first']) ?><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ============ LOANS & ADVANCES ============ -->
<section class="a360-tab" data-tab="loans"<?= $tab === 'loans' ? '' : ' hidden' ?>>
  <div class="kpis">
    <?= admin_kpi('Open on the register', inr($loanOutstanding), count($openLoans) . ' open item' . (count($openLoans) === 1 ? '' : 's'), 'loan', $openLoans !== [] ? 'orange' : 'teal') ?>
    <?= admin_kpi('Advance outstanding (ledger)', inr($advance['outstanding']), 'how far the commission balance is below zero', 'ledger', $advance['outstanding'] > 0 ? 'orange' : 'teal') ?>
    <?= admin_kpi('Given lifetime', inr($advance['given']), 'repaid in cash ' . inr($advance['repaid']), 'banknote', 'navy') ?>
  </div>
  <?php /* 17 Sep 2026: open advances / loans and what is still outstanding, to the agent on WhatsApp. */
        $waAdvance = admin_wa_button('agent_advance', ['agent' => $agentId], 'Advance / loan balance on WhatsApp', ['class' => 'btn ghost sm']);
        if ($waAdvance !== ''): ?>
  <div class="chips no-print" style="margin:0 0 16px"><?= $waAdvance ?></div>
  <?php endif; ?>

  <?php if ($showSettle): ?>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-loan"/></svg> Issue · repay · write off</h2>
    <div class="panel-body a360-forms">
      <form method="post" onsubmit="return confirm('Issue this advance / loan? It is debited on the commission account now.')">
        <?= a360_form_head('loans', 'loan_issue', $agentId, $csrf, $k) ?>
        <strong>Issue an advance or loan</strong>
        <p class="hint">Money fronted before it is earned — it may exceed the commission owed today and is netted off future commission.</p>
        <div class="form-grid">
          <div class="field"><label>Kind</label>
            <select class="inp" name="kind"><option value="advance">Advance (against next commission)</option><option value="loan">Loan (longer term)</option></select></div>
          <div class="field"><label>Amount ₹</label><input class="inp" type="number" name="amount" step="0.01" min="0.01" required></div>
          <div class="field"><label>Recovery</label>
            <select class="inp" name="recover_mode" onchange="var v=this.form.querySelector('[name=recover_value]');v.disabled=this.value==='full';v.placeholder=this.value==='percent'?'% of each payout':(this.value==='fixed'?'₹ per payout':'')">
              <option value="full">Automatic — commission nets it</option>
              <option value="fixed">Plan: fixed ₹ per payout (reminder)</option>
              <option value="percent">Plan: % of each payout (reminder)</option>
            </select></div>
          <div class="field"><label>Recovery value</label><input class="inp" type="number" name="recover_value" step="0.01" min="0" disabled placeholder="—"></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255" placeholder="what it is for"></div>
        <p class="muted" style="font-size:12px;margin:8px 0 0">How recovery works (3 Oct 2026): the amount is taken off the agent's commission balance at once, so every commission they earn repays it automatically and a payout is only possible once the balance is above zero. A "plan" of ₹ or % per payout is printed on the loan as the agreed instalment — use <b>Repay</b> for money they hand back in cash.</p>
        <div class="form-actions"><button class="btn" type="submit"><svg class="a-ic"><use href="#a-loan"/></svg> Issue</button></div>
      </form>
      <form method="post" onsubmit="return confirm('Record this cash repayment?')">
        <?= a360_form_head('loans', 'loan_repay', $agentId, $csrf, $k) ?>
        <strong>Record a repayment</strong>
        <p class="hint">Cash the agent returned against one item. Commission recovery needs no entry — the ledger nets it.</p>
        <div class="form-grid">
          <div class="field"><label>Item</label>
            <select class="inp" name="loan_id" required <?= $openLoans === [] ? 'disabled' : '' ?>>
              <?php if ($openLoans === []): ?><option value="">— nothing open —</option><?php endif; ?>
              <?php foreach ($openLoans as $l): ?>
                <option value="<?= (int) $l['id'] ?>"><?= $e(AgentWallet::loanLabel($l)) ?> · <?= $e(inr((float) $l['outstanding'])) ?> outstanding</option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Amount ₹</label><input class="inp" type="number" name="amount" step="0.01" min="0.01" required></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255"></div>
        <div class="form-actions"><button class="btn ok" type="submit" <?= $openLoans === [] ? 'disabled' : '' ?>><svg class="a-ic"><use href="#a-check"/></svg> Record repayment</button></div>
      </form>
      <form method="post" onsubmit="return confirm('Write off the outstanding balance? A matching credit is posted so it stops deducting from payouts.')">
        <?= a360_form_head('loans', 'loan_writeoff', $agentId, $csrf, $k) ?>
        <strong>Write off</strong>
        <p class="hint">Forgive what is still outstanding. This IS a money movement (a credit for the balance) and is audited.</p>
        <div class="form-grid">
          <div class="field"><label>Item</label>
            <select class="inp" name="loan_id" required <?= $openLoans === [] ? 'disabled' : '' ?>>
              <?php if ($openLoans === []): ?><option value="">— nothing open —</option><?php endif; ?>
              <?php foreach ($openLoans as $l): ?>
                <option value="<?= (int) $l['id'] ?>"><?= $e(AgentWallet::loanLabel($l)) ?> · <?= $e(inr((float) $l['outstanding'])) ?> outstanding</option>
              <?php endforeach; ?>
            </select></div>
          <div class="field"><label>Reason</label><input class="inp" type="text" name="note" maxlength="255" required minlength="3"></div>
        </div>
        <div class="form-actions"><button class="btn bad" type="submit" <?= $openLoans === [] ? 'disabled' : '' ?>><svg class="a-ic"><use href="#a-x-circle"/></svg> Write off</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-list"/></svg> Register</h2>
    <div class="tbl-scroll">
      <table>
        <thead><tr><th>Item</th><th>Issued</th><th class="num">Principal</th><th class="num">Recovered</th><th class="num">Outstanding</th><th>Status</th><th>Recovery</th><th>Note · by</th></tr></thead>
        <tbody>
          <?php if ($loans === []): ?>
            <tr><td colspan="8"><?= admin_empty('No advances or loans on the register', $advance['given'] > 0 ? 'Earlier advances (' . inr($advance['given']) . ' given) were recorded before the register existed and show on the statement as "Advance".' : 'Issue one above; the money is debited on the commission account and recovered from future commission.', '💸') ?></td></tr>
          <?php else: foreach ($loans as $l): $rows = AgentWallet::loanEntries((int) $l['id']); ?>
            <tr>
              <td><b><?= $e(AgentWallet::loanLabel($l)) ?></b><div class="muted text-xs"><?= $e(ucfirst((string) $l['kind'])) ?></div></td>
              <td class="muted" style="white-space:nowrap"><?= $e(formatDate((string) $l['issued_on'], 'j M Y')) ?></td>
              <td class="num money"><?= $e(inr((float) $l['principal'])) ?></td>
              <td class="num money"><?= $e(inr((float) $l['recovered'])) ?></td>
              <td class="num money fw7"><?= $e(inr((float) $l['outstanding'])) ?></td>
              <td><?= admin_pill((string) $l['status'] === 'open' ? 'due' : ((string) $l['status'] === 'settled' ? 'paid' : 'void'), (string) $l['status'] === 'written_off' ? 'Written off' : ucfirst((string) $l['status'])) ?><?= $l['settled_at'] ? '<div class="muted text-xs">' . $e(formatDate(substr((string) $l['settled_at'], 0, 10), 'j M Y')) . '</div>' : '' ?></td>
              <td class="text-sm"><?= $e(a360_recover_label($l)) ?></td>
              <td class="muted text-xs" style="max-width:240px"><?= $e($l['note'] ?: '—') ?><?= $l['by_name'] ? '<br>by ' . $e($l['by_name']) : '' ?>
                <?php if ($rows !== []): ?>
                  <details class="a360-rows" style="margin-top:4px"><summary><?= count($rows) ?> ledger row<?= count($rows) === 1 ? '' : 's' ?></summary>
                    <table style="margin-top:6px;font-size:12px">
                      <?php foreach ($rows as $lr): ?>
                        <tr><td class="muted" style="padding:4px 6px;white-space:nowrap"><?= $e(date('j M Y', (int) strtotime((string) $lr['created_at']))) ?></td>
                            <td style="padding:4px 6px"><?= $e(truncate((string) ($lr['note'] ?? ''), 60)) ?></td>
                            <td class="num" style="padding:4px 6px"><?= a360_amt((float) $lr['amount']) ?></td></tr>
                      <?php endforeach; ?>
                    </table>
                  </details>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <div class="panel-foot muted text-xs">Register figures are for reading: "recovered" = cash repaid + commission allocated to the item, oldest first, from the ledger. The ledger's own netting is what actually gets paid, so the two can legitimately differ when the agent had a positive balance before the item was issued.</div>
  </div>
</section>

<!-- ============ DEPOSITS ============ -->
<section class="a360-tab" data-tab="deposits"<?= $tab === 'deposits' ? '' : ' hidden' ?>>
  <div class="kpis">
    <?= admin_kpi('Deposit required', inr($deposit['required']), $deposit['required'] > 0 ? 'set by the office' : 'none set', 'lock', 'navy') ?>
    <?= admin_kpi('Deposit held', inr($deposit['paid']), $deposit['met'] ? ($deposit['required'] > 0 ? 'fully deposited' : 'nothing required') : inr($deposit['short']) . ' still to collect', 'shield', $deposit['met'] ? 'green' : 'orange') ?>
  </div>
  <?php if ($showSettle || $canManage): ?>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-lock"/></svg> Security deposit</h2>
    <div class="panel-body a360-forms">
      <?php if ($showSettle): ?>
      <form method="post" onsubmit="return confirm('Record this deposit movement?')">
        <?= a360_form_head('deposits', 'record_deposit', $agentId, $csrf, $k) ?>
        <strong>Record deposit received / refunded</strong>
        <p class="hint">A positive amount is money the agent lodged with the company; a minus figure refunds it when they leave. Kept apart from the wallet on purpose.</p>
        <div class="form-grid">
          <div class="field"><label>Amount ₹ (minus = refund)</label><input class="inp" type="number" name="amount" step="0.01" required></div>
          <div class="field"><label>Receipt / UPI ref</label><input class="inp" type="text" name="ref" maxlength="80"></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255"></div>
        <div class="form-actions"><button class="btn ok" type="submit"><svg class="a-ic"><use href="#a-lock"/></svg> Record</button></div>
      </form>
      <?php endif; ?>
      <?php if ($canManage): ?>
      <form method="post">
        <?= a360_form_head('deposits', 'set_deposit_required', $agentId, $csrf, $k) ?>
        <strong>Required deposit</strong>
        <p class="hint">The amount the company expects this agent to lodge before selling (0 = none).</p>
        <div class="field"><label>Required ₹</label><input class="inp" type="number" name="deposit_required" step="1" min="0" max="10000000" value="<?= $deposit['required'] > 0 ? $e(number_format($deposit['required'], 2, '.', '')) : '' ?>" placeholder="0"></div>
        <div class="form-actions"><button class="btn" type="submit">Save</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-history"/></svg> Deposit history</h2>
    <div class="tbl-scroll">
      <table>
        <thead><tr><th>When</th><th class="num">Amount</th><th>Ref</th><th>Note</th><th>Recorded by</th></tr></thead>
        <tbody>
          <?php if ($depositTx === []): ?>
            <tr><td colspan="5"><?= admin_empty('No dated deposit rows', $deposit['paid'] > 0 ? 'The ' . inr($deposit['paid']) . ' on file was recorded before the history table existed (or the migration has not run).' : 'Each deposit received or refunded is listed here with who took it.', '🔐') ?></td></tr>
          <?php else: foreach ($depositTx as $d): ?>
            <tr>
              <td class="muted" style="white-space:nowrap"><?= $e(date('j M Y, H:i', (int) strtotime((string) $d['created_at']))) ?></td>
              <td class="num"><?= a360_amt((float) $d['amount']) ?><div class="muted text-xs"><?= (float) $d['amount'] < 0 ? 'refunded' : 'received' ?></div></td>
              <td class="mono"><?= $e($d['ref'] ?: '—') ?></td>
              <td class="muted" style="font-size:12px"><?= $e($d['note'] ?: '—') ?></td>
              <td class="muted text-xs"><?= $e($d['by_name'] ?: '—') ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ============ PAYOUT REQUESTS ============ -->
<section class="a360-tab" data-tab="requests"<?= $tab === 'requests' ? '' : ' hidden' ?>>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-send"/></svg> Open requests <span class="muted" style="font-weight:500">· commission due <?= $e(inr($balances['commission'])) ?></span></h2>
    <div class="tbl-scroll">
      <table>
        <thead><tr><th>Requested</th><th class="num">Amount</th><th>Agent's note</th><th style="min-width:340px">Decision</th></tr></thead>
        <tbody>
          <?php if ($openReqs === []): ?>
            <tr><td colspan="4"><?= admin_empty('Nothing waiting', 'An agent asks for a payout from their own panel; it lands here until the office pays or declines it.', '💸') ?></td></tr>
          <?php else: foreach ($openReqs as $rq): ?>
            <tr>
              <td class="muted" style="white-space:nowrap"><?= $e(date('j M Y, H:i', (int) strtotime((string) $rq['created_at']))) ?><div class="text-xs">#<?= (int) $rq['id'] ?> · <?= $e(timeAgo((string) $rq['created_at'])) ?></div></td>
              <td class="num money fw7"><?= $e(inr((float) $rq['amount'])) ?><?= (float) $rq['amount'] > $balances['commission'] + 0.009 ? '<div class="text-xs" style="color:var(--bad)">more than the balance now due</div>' : '' ?></td>
              <td class="muted" style="font-size:12px"><?= $e($rq['note'] ?: '—') ?></td>
              <td>
                <?php if ($showSettle): ?>
                  <form method="post" class="row" style="gap:6px;margin-bottom:6px" onsubmit="return confirm('Pay <?= $e(inr((float) $rq['amount'])) ?> to this agent and close the request?')">
                    <?= a360_form_head('requests', 'request_paid', $agentId, $csrf, $k) ?>
                    <input type="hidden" name="request_id" value="<?= (int) $rq['id'] ?>">
                    <input class="inp" type="text" name="ref" maxlength="80" placeholder="UPI / voucher ref (optional)" style="flex:1;min-width:140px">
                    <button class="btn ok sm" type="submit" <?= (float) $rq['amount'] > $balances['commission'] + 0.009 ? 'disabled' : '' ?>><svg class="a-ic"><use href="#a-check-circle"/></svg> Mark paid</button>
                  </form>
                  <form method="post" class="row" style="gap:6px" onsubmit="return confirm('Decline this payout request?')">
                    <?= a360_form_head('requests', 'request_decline', $agentId, $csrf, $k) ?>
                    <input type="hidden" name="request_id" value="<?= (int) $rq['id'] ?>">
                    <input class="inp" type="text" name="note" maxlength="255" required minlength="3" placeholder="Reason the agent will read" style="flex:1;min-width:140px">
                    <button class="btn ghost danger sm" type="submit"><svg class="a-ic"><use href="#a-x-circle"/></svg> Decline</button>
                  </form>
                <?php elseif ($selfWalled): ?>
                  <span class="muted text-sm">Your own request — someone else decides it.</span>
                <?php else: ?>
                  <span class="muted text-sm">Needs the commissions.pay permission.</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-history"/></svg> Request history</h2>
    <div class="tbl-scroll">
      <table>
        <thead><tr><th>Requested</th><th class="num">Amount</th><th>Status</th><th>Decided</th><th>Office note</th><th></th></tr></thead>
        <tbody>
          <?php if ($reqHist === []): ?>
            <tr><td colspan="6"><?= admin_empty('No requests yet', 'Requests made before the register existed live in the activity log as "agent.payout_request".', '📜') ?></td></tr>
          <?php else: foreach ($reqHist as $rq): $rs = (string) $rq['status']; ?>
            <tr>
              <td class="muted" style="white-space:nowrap"><?= $e(date('j M Y', (int) strtotime((string) $rq['created_at']))) ?><div class="text-xs">#<?= (int) $rq['id'] ?><?= $rq['note'] ? ' · ' . $e(truncate((string) $rq['note'], 40)) : '' ?></div></td>
              <td class="num money"><?= $e(inr((float) $rq['amount'])) ?></td>
              <td><?= admin_pill($rs === 'open' ? 'pending' : ($rs === 'paid' ? 'paid' : 'rejected'), $rs === 'declined' ? 'Declined' : ($rs === 'open' ? 'Open' : 'Paid')) ?></td>
              <td class="muted text-xs"><?= $rq['decided_at'] ? $e(date('j M Y', (int) strtotime((string) $rq['decided_at']))) . ($rq['decided_by_name'] ? '<br>by ' . $e($rq['decided_by_name']) : '') : '—' ?></td>
              <td class="muted" style="font-size:12px"><?= $e($rq['decided_note'] ?: '—') ?></td>
              <td><?php if ($rs === 'paid' && (int) ($rq['ledger_id'] ?? 0) > 0): ?><a class="btn ghost sm" href="<?= $base ?>/admin/agent-receipt.php?id=<?= (int) $rq['ledger_id'] ?>" target="_blank"><svg class="a-ic"><use href="#a-receipt"/></svg> Receipt</a><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<!-- ============ PROFILE & KYC ============ -->
<section class="a360-tab" data-tab="profile"<?= $tab === 'profile' ? '' : ' hidden' ?>>
  <div class="dash-grid">
    <div class="panel" style="margin-bottom:0">
      <h2><svg class="a-ic"><use href="#a-user"/></svg> Profile
        <a class="btn ghost sm" style="margin-left:auto" href="<?= $base ?>/admin/agent.php?agent=<?= $agentId ?>"><svg class="a-ic"><use href="#a-edit"/></svg> Edit on Agent Panel</a>
      </h2>
      <div class="panel-body">
        <dl class="kv-list">
          <dt>Name</dt><dd><?= $e($agent['full_name'] ?: '—') ?></dd>
          <dt>Username</dt><dd class="mono"><?= $e($agent['username']) ?></dd>
          <dt>Sign-in email</dt><dd><?= $e($agent['email'] ?: '— not set') ?></dd>
          <dt>Mobile</dt><dd class="mono"><?= $e($agent['phone'] ?: '—') ?></dd>
          <dt>Display phone</dt><dd class="mono"><?= $e($profile['display_phone'] ?: '—') ?></dd>
          <dt>WhatsApp</dt><dd class="mono"><?= $e($profile['whatsapp'] ?: '—') ?></dd>
          <dt>Display email</dt><dd><?= $e($profile['display_email'] ?: '—') ?></dd>
          <dt>Kind</dt><dd><?= $e(AgentWallet::kindLabel($kind)) ?><?= $kind === AgentWallet::KIND_ORG && $profile['contact_person'] ? ' · contact ' . $e($profile['contact_person']) : '' ?></dd>
          <dt>Counter / branch</dt><dd><?= $e($profile['counter_name'] ?: '—') ?></dd>
          <dt>Address</dt><dd><?= $e($profile['address'] ?: '—') ?></dd>
          <dt>Payout</dt><dd><?= $e(trim((string) ($profile['payout_method'] ?? '') . ' ' . (string) ($profile['payout_account'] ?? '')) ?: '—') ?></dd>
          <dt>ID document</dt><dd><?= $e(trim((string) ($profile['id_type'] ?? '') . ' ' . (string) ($profile['id_number'] ?? '')) ?: '—') ?><?= $profile['id_expires_on'] ? ' <span class="muted">· expires ' . $e(formatDate((string) $profile['id_expires_on'], 'j M Y')) . '</span>' : '' ?></dd>
          <dt>Cash limit</dt><dd><?= (float) ($profile['cash_limit'] ?? 0) > 0 ? $e(inr((float) $profile['cash_limit'])) : '<span class="muted">none</span>' ?></dd>
          <dt>Daily cap</dt><dd><?= (int) ($profile['daily_booking_limit'] ?? 0) > 0 ? (int) $profile['daily_booking_limit'] : '<span class="muted">none</span>' ?></dd>
          <dt>Joined</dt><dd><?= $e($profile['joined_on'] ? formatDate((string) $profile['joined_on']) : '—') ?></dd>
          <dt>Account</dt><dd><?= $isSuspended ? admin_pill('suspended') : admin_pill($isActive ? 'active' : 'inactive') ?></dd>
          <dt>Last sign-in</dt><dd><?= $agent['last_login_at'] ? $e(formatDate((string) $agent['last_login_at'], 'j M Y, g:i A')) : '<span class="muted">never</span>' ?></dd>
          <dt>Office notes</dt><dd><?= $e($profile['notes'] ?: '—') ?></dd>
        </dl>
      </div>
    </div>

    <div class="panel" style="margin-bottom:0">
      <h2><svg class="a-ic"><use href="#a-id-card"/></svg> KYC <span style="margin-left:auto"><?= a360_kyc_pill($kycStatus) ?></span></h2>
      <div class="panel-body">
        <?php if (!AgentWallet::kycAvailable()): ?>
          <div class="flash warn" style="margin:0 0 12px">KYC is not set up on this database yet — ask the office to run <span class="mono">database/upgrade-2026-09-agent-kyc.sql</span> once. Until then only the ID type and number on the profile are kept.</div>
        <?php else: ?>
          <dl class="kv-list" style="margin-bottom:14px">
            <dt>Status</dt><dd><?= a360_kyc_pill($kycStatus) ?></dd>
            <?php if ($kycStatus === 'verified'): ?>
              <dt>Verified</dt><dd><?= $e($profile['kyc_verified_at'] ? formatDate((string) $profile['kyc_verified_at'], 'j M Y, g:i A') : '—') ?><?= $kycVerifier !== '' ? ' by ' . $e($kycVerifier) : '' ?></dd>
            <?php endif; ?>
            <dt>Note</dt><dd><?= $e($profile['kyc_note'] ?: '—') ?></dd>
            <dt>Expires</dt><dd><?= $profile['id_expires_on'] ? $e(formatDate((string) $profile['id_expires_on'], 'j M Y')) . ((string) $profile['id_expires_on'] < todayISO() ? ' <span class="pill st-bad">expired</span>' : '') : '<span class="muted">—</span>' ?></dd>
            <dt>Document 1</dt><dd><?= (string) ($profile['kyc_doc_path'] ?? '') !== '' ? '<a class="btn ghost sm" href="' . $base . '/admin/agent-kyc-file.php?agent=' . $agentId . '&amp;slot=1" target="_blank"><svg class="a-ic"><use href="#a-eye"/></svg> View ID document</a>' : '<span class="muted">not on file</span>' ?></dd>
            <dt>Agreement</dt><dd><?= AgentWallet::agreementPath($agentId) !== '' ? '<a class="btn ghost sm" href="' . $base . '/admin/agent-kyc-file.php?agent=' . $agentId . '&amp;slot=3" target="_blank"><svg class="a-ic"><use href="#a-eye"/></svg> View signed agreement</a>' : '<span class="muted">not on file</span>' ?></dd>
            <dt>Document 2</dt><dd><?= (string) ($profile['kyc_doc2_path'] ?? '') !== '' ? '<a class="btn ghost sm" href="' . $base . '/admin/agent-kyc-file.php?agent=' . $agentId . '&amp;slot=2" target="_blank"><svg class="a-ic"><use href="#a-eye"/></svg> View back / address proof</a>' : '<span class="muted">not on file</span>' ?></dd>
          </dl>
          <?php if ($canManage): ?>
            <form method="post" enctype="multipart/form-data" style="border-top:1px solid var(--line);padding-top:14px">
              <?= a360_form_head('profile', 'kyc_save', $agentId, $csrf, $k) ?>
              <div class="form-grid">
                <div class="field"><label>Status</label>
                  <select class="inp" name="kyc_status">
                    <?php foreach (AgentWallet::KYC_STATUSES as $ks): ?>
                      <option value="<?= $e($ks) ?>" <?= $ks === $kycStatus ? 'selected' : '' ?>><?= $e(ucfirst($ks)) ?></option>
                    <?php endforeach; ?>
                  </select></div>
                <div class="field"><label>ID expires on</label><input class="inp" type="date" name="id_expires_on" value="<?= $e($profile['id_expires_on'] ?? '') ?>"></div>
                <div class="field"><label>ID document (front)</label><input type="file" name="kyc_doc1" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                <div class="field"><label>Back side / address proof</label><input type="file" name="kyc_doc2" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
                <div class="field"><label>Signed agreement · सम्झौता</label><input type="file" name="agreement_doc" accept="image/jpeg,image/png,image/webp,application/pdf"></div>
              </div>
              <div class="field" style="margin-top:10px"><label>Verifier note</label><input class="inp" type="text" name="kyc_note" maxlength="255" value="<?= $e($profile['kyc_note'] ?? '') ?>" placeholder="why rejected / what to fix / what was checked"></div>
              <div class="form-actions">
                <button class="btn" type="submit"><svg class="a-ic"><use href="#a-upload"/></svg> Save KYC</button>
                <span class="muted text-xs">JPG, PNG, WEBP or PDF · a new file replaces the old one · files are only ever served through the gated viewer, never a public URL.</span>
              </div>
            </form>
            <div class="row" style="margin-top:12px;gap:8px">
              <?php if ($kycStatus !== 'verified'): ?>
                <form method="post" onsubmit="return confirm('Mark this agent\'s KYC as verified?')">
                  <?= a360_form_head('profile', 'kyc_verify', $agentId, $csrf, $k) ?>
                  <input type="hidden" name="kyc_status" value="verified">
                  <input type="hidden" name="kyc_note" value="<?= $e($profile['kyc_note'] ?? '') ?>">
                  <button class="btn ok sm" type="submit"><svg class="a-ic"><use href="#a-check-circle"/></svg> Mark verified</button>
                </form>
              <?php endif; ?>
              <?php if ($kycStatus !== 'rejected'): ?>
                <form method="post" class="row" style="gap:6px" onsubmit="return confirm('Reject this agent\'s KYC?')">
                  <?= a360_form_head('profile', 'kyc_verify', $agentId, $csrf, $k) ?>
                  <input type="hidden" name="kyc_status" value="rejected">
                  <input class="inp" type="text" name="kyc_note" maxlength="255" required minlength="3" placeholder="What must be fixed" style="min-width:200px">
                  <button class="btn ghost danger sm" type="submit"><svg class="a-ic"><use href="#a-x-circle"/></svg> Reject</button>
                </form>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p class="muted text-sm" style="margin:0">Uploading and verifying KYC needs the staff.manage permission.</p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- ============ ACTIVITY ============ -->
<section class="a360-tab" data-tab="activity"<?= $tab === 'activity' ? '' : ' hidden' ?>>
  <div class="panel">
    <h2><svg class="a-ic"><use href="#a-history"/></svg> Activity <span class="muted" style="font-weight:500">· last 30 audit rows for this agent</span>
      <a class="btn ghost sm" style="margin-left:auto" href="<?= $base ?>/admin/activity-log.php?q=<?= urlencode((string) $agent['username']) ?>"><svg class="a-ic"><use href="#a-external"/></svg> Full log</a>
    </h2>
    <div class="panel-body">
      <?php if ($activity === []): ?>
        <?= admin_empty('Nothing logged yet', 'Every profile, KYC and money action on this agent is written to the audit trail.', '🛡️') ?>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($activity as $a):
            $act  = (string) $a['action'];
            $tone = str_contains($act, 'void') || str_contains($act, 'declined') || str_contains($act, 'rejected') || str_contains($act, 'written_off') || str_contains($act, 'deactivate') ? 'bad'
                  : (str_contains($act, 'payout') || str_contains($act, 'handover') || str_contains($act, 'verified') || str_contains($act, 'commission') ? 'ok' : (str_contains($act, 'advance') || str_contains($act, 'loan') ? 'warn' : ''));
            $new  = jsonColumn($a['new_value'] ?? null);
            $bits = [];
            foreach ($new as $nk => $nv) {
                if (is_array($nv)) { $nv = json_encode($nv, JSON_UNESCAPED_UNICODE); }
                $bits[] = $nk . ': ' . truncate((string) $nv, 40);
                if (count($bits) >= 5) { break; }
            } ?>
            <li class="<?= $tone ?>">
              <div><b class="mono" style="font-size:12.5px"><?= $e($act) ?></b> <span class="muted text-xs">· <?= $e($a['actor_name'] ?: $a['actor_type']) ?></span></div>
              <?php if ((string) ($a['detail'] ?? '') !== ''): ?><div class="text-sm"><?= $e(truncate((string) $a['detail'], 140)) ?></div><?php endif; ?>
              <?php if ($bits !== []): ?><div class="a360-tl-diff"><?= $e(implode(' · ', $bits)) ?></div><?php endif; ?>
              <div class="tl-t"><?= $e(timeAgo((string) $a['created_at'])) ?> · <?= $e(date('j M Y, H:i', (int) strtotime((string) $a['created_at']))) ?></div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</section>

<script>
/* Client-side tab switch: every section is already in the page, so a tab
   click only shows/hides and rewrites ?tab= (replaceState) — the forms
   carry their own tab, so a POST still lands back on the right one. */
(function () {
  var links = document.querySelectorAll('#a360Tabs a[data-tab]');
  var secs  = document.querySelectorAll('.a360-tab');
  function show(key, push) {
    var found = false;
    secs.forEach(function (s) { if (s.getAttribute('data-tab') === key) { found = true; } });
    if (!found) { key = 'report'; }
    secs.forEach(function (s) { s.hidden = s.getAttribute('data-tab') !== key; });
    links.forEach(function (a) { a.classList.toggle('on', a.getAttribute('data-tab') === key); });
    if (push && window.history && history.replaceState) {
      try { var u = new URL(location.href); u.searchParams.set('tab', key); history.replaceState(null, '', u.toString()); } catch (e) {}
    }
  }
  links.forEach(function (a) {
    a.addEventListener('click', function (ev) { ev.preventDefault(); show(a.getAttribute('data-tab'), true); window.scrollTo({ top: 0, behavior: 'smooth' }); });
  });
})();
</script>
<?php
admin_footer();
