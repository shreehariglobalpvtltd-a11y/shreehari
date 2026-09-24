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
$tab = (string) ($_GET['tab'] ?? ($_POST['tab'] ?? 'overview'));
if (!isset($tabs[$tab])) {
    $tab = 'overview';
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
        $backTab = 'overview';
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
        'fixed'   => inr((float) $l['recover_value']) . ' per payout',
        'percent' => rtrim(rtrim(number_format((float) $l['recover_value'], 2), '0'), '.') . '% of each payout',
        default   => 'commission nets it in full',
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
              <option value="full">Full — commission nets it</option>
              <option value="fixed">Fixed ₹ per payout</option>
              <option value="percent">% of each payout</option>
            </select></div>
          <div class="field"><label>Recovery value</label><input class="inp" type="number" name="recover_value" step="0.01" min="0" disabled placeholder="—"></div>
        </div>
        <div class="field" style="margin-top:10px"><label>Note</label><input class="inp" type="text" name="note" maxlength="255" placeholder="what it is for"></div>
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
    if (!found) { key = 'overview'; }
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
