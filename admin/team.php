<?php
/**
 * admin/team.php — Team & ID Cards (Oct 2026, Agent Pro).
 *
 *   Approvals  every join application as one card: the selfie next to the
 *              ID photo, what the applicant typed next to what was read off
 *              the ID (and from where), the paper-form pages and the thumb
 *              print, and ONE button — ✓ Approve — that activates the login,
 *              issues the agent code and ID card, marks KYC verified and
 *              sends the welcome with the ID card on WhatsApp.
 *              Sub-views: waiting · drafts (the office can finish them) ·
 *              history (approved / rejected, searchable).
 *   Agents     every agent as a card: photo, code, branch, KYC, card status
 *   Staff      the same for office staff
 *   Money      withdrawals to pay and payments-in to match — one tap each,
 *              both atomic (row locks), a manual credit / debit through the
 *              real ledger (AgentWallet::record), and the history.
 *   Cards      print ID / visiting cards for many people at once (A4)
 *   Invites    a link for a new office staff member (role fixed by the link)
 *
 * 30 Oct 2026 (owner: "admin le sabai control garna paune"): the office
 * form (/join.php?office=1) is the one way the office adds a person;
 * KYC documents are shown only to a super-admin or a manager.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/teampurge.php';
$admin = admin_boot();
if (!(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
if (!AgentPro::available()) {
    admin_header('Team & ID Cards', 'team.php');
    echo admin_empty('Agent Pro is not installed yet', 'Run the installer: /admin/agentpro-install.php', '🧩');
    admin_footer();
    exit;
}
$me      = (int) $admin['id'];
$role    = (string) ($admin['role'] ?? '');
$isSuper = Auth::isSuperadmin();
$canKyc  = $isSuper || $role === 'manager';                 // KYC documents: super-admin / manager only
$canOffice = $isSuper || $role === 'manager' || Auth::can('staff.manage');   // K1 office form
$canPay  = $isSuper || Auth::can('commissions.pay');
$tab = (string) ($_GET['tab'] ?? 'approvals');
$tabs = ['approvals', 'agents', 'staff', 'money', 'cards', 'invites'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'approvals';
}
$view = (string) ($_GET['view'] ?? 'waiting');
if (!in_array($view, ['waiting', 'drafts', 'history'], true)) {
    $view = 'waiting';
}
$flash = null;
$newLink = null;
$e = static fn($v): string => Security::e((string) $v);
$officeUrl = '/join.php?office=1';

/* ---------------------------------------------------------------------
 *  Money, done here so each decision is ONE transaction with the rows
 *  locked (admins row first, then the request — the same order
 *  AgentWallet uses, so two office tabs can never pay or credit twice).
 * ------------------------------------------------------------------- */
$payWithdrawal = static function (int $reqId, int $by, string $ref, string $note): int {
    $ref = trim($ref);
    if (mb_strlen($ref) < 5) {
        throw new RuntimeException('Type the UPI / bank reference of the payment (at least 5 characters) · भुक्तानी रेफरेन्स लेख्नुहोस्।');
    }
    $req = Database::fetch('SELECT id, agent_admin_id, amount, status FROM agent_payout_requests WHERE id = :i', ['i' => $reqId]);
    if ($req === null || $req['status'] !== 'open') {
        throw new RuntimeException('That withdrawal request is not open any more.');
    }
    $aid = (int) $req['agent_admin_id'];
    $note = Security::clean($note, 200);
    $lid = Database::transaction(static function () use ($reqId, $aid, $by, $ref, $note): int {
        Database::fetch('SELECT id FROM admins WHERE id = :a FOR UPDATE', ['a' => $aid]);
        $row = Database::fetch('SELECT * FROM agent_payout_requests WHERE id = :i FOR UPDATE', ['i' => $reqId]);
        if ($row === null || $row['status'] !== 'open') {
            throw new RuntimeException('That withdrawal request was already ' . ($row['status'] ?? 'removed') . '.');
        }
        // request_id: the request's own amount is not counted as "reserved" against itself.
        $lid = AgentWallet::record($aid, 'payout', (float) $row['amount'], [
            'note' => 'Withdrawal request #' . $reqId . ($note !== '' ? ' · ' . $note : ''), 'ref' => $ref, 'by' => $by,
            'request_id' => $reqId,
        ]);
        AgentWallet::decidePayoutRequest($reqId, 'paid', $by, $note, $lid);
        return $lid;
    }, 1);
    AgentPro::tellMember($aid, '💸 ' . inr((float) $req['amount']) . ' पठाइयो / paid to you. Ref ' . AgentWallet::voucherFor($lid));
    return $lid;
};

$decideDeposit = static function (int $id, string $decision, int $by, string $note): void {
    if (!in_array($decision, ['confirmed', 'declined'], true)) {
        throw new RuntimeException('Confirm or decline.');
    }
    $note = Security::clean($note, 255);
    if ($decision === 'declined' && $note === '') {
        throw new RuntimeException('Tell the member why (for example: not found in the bank).');
    }
    $pre = Database::fetch('SELECT id, admin_id FROM ap_deposit_requests WHERE id = :i', ['i' => $id]);
    if ($pre === null) {
        throw new RuntimeException('That payment entry no longer exists.');
    }
    $aid = (int) $pre['admin_id'];
    $row = Database::transaction(static function () use ($id, $aid, $decision, $by, $note): array {
        Database::fetch('SELECT id FROM admins WHERE id = :a FOR UPDATE', ['a' => $aid]);
        $row = Database::fetch('SELECT * FROM ap_deposit_requests WHERE id = :i FOR UPDATE', ['i' => $id]);
        if ($row === null) {
            throw new RuntimeException('That payment entry no longer exists.');
        }
        if ($row['status'] !== 'open') {
            throw new RuntimeException('That payment was already ' . $row['status'] . ' — nothing was written twice.');
        }
        $amount = (float) $row['amount'];
        $ledgerId = null;
        if ($decision === 'confirmed') {
            $ref = (string) ($row['utr'] ?? '');
            if ($row['purpose'] === 'security') {
                AgentWallet::recordDeposit($aid, $amount, $by, 'Security deposit (' . $row['method'] . ')', $ref);
            } else {
                $held = AgentWallet::balances($aid)['cash'];
                if ($amount > $held + 0.009) {
                    throw new RuntimeException('This member holds only ' . inr($held) . ' of company cash. Record the rest as a security deposit, or decline.');
                }
                $ledgerId = AgentWallet::record($aid, 'cash_handover', $amount, [
                    'note' => 'Paid by ' . strtoupper((string) $row['method']) . ($note !== '' ? ' · ' . $note : ''),
                    'ref'  => $ref, 'by' => $by,
                ]);
            }
        }
        $n = Database::update('ap_deposit_requests', [
            'status' => $decision, 'decided_by' => $by ?: null, 'decided_at' => date('Y-m-d H:i:s'),
            'decided_note' => $note !== '' ? $note : null, 'ledger_id' => $ledgerId,
        ], "id = :i AND status = 'open'", ['i' => $id]);
        if ($n !== 1) {
            throw new RuntimeException('That payment was decided in another tab — nothing was written twice.');
        }
        Logger::audit('agentpro.deposit_' . $decision, 'admin', (string) $aid, ['status' => 'open'],
            ['request' => $id, 'amount' => $amount, 'ledger' => $ledgerId], 'Payment ' . $decision . ' by admin #' . $by);
        return $row;
    }, 1);
    AgentPro::tellMember($aid, $decision === 'confirmed'
        ? '✅ ' . inr((float) $row['amount']) . ' प्राप्त भयो, धन्यवाद। / received, thank you.' . (!empty($row['utr']) ? ' Ref ' . $row['utr'] : '')
        : '⚠️ ' . inr((float) $row['amount']) . ' भुक्तानी भेटिएन / not matched: ' . $note);
};

if (!empty($_GET['purged'])) {
    $flash = ['ok', '🗑 Deleted permanently: ' . Security::clean((string) $_GET['purged'], 40) . ' — a backup copy was saved first (backup/team-purge/).'];
}

if (Security::isPost()) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            switch ($act) {
                case 'approve':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can approve.'); }
                    // Only someone who can see the ID photos may mark KYC verified; anyone else leaves it "submitted".
                    $r = AgentPro::approve((int) $_POST['app'], $me, $canKyc ? null : false);
                    $m = AgentPro::member($r['admin_id']);
                    $flash = ['ok', '✓ Approved — ' . ($m['name'] ?? '') . ($r['code'] !== '' ? ' is ' . $r['code'] : '') . '. Login, ID card and welcome sent on WhatsApp.'
                        . ($canKyc ? '' : ' KYC still to check by a manager · KYC म्यानेजरले जाँच्न बाँकी.')
                        . (isset($r['password']) ? ' Temporary password: ' . $r['password'] : '')];
                    break;
                case 'reject':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can reject.'); }
                    AgentPro::reject((int) $_POST['app'], $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Rejected — the applicant was told why on WhatsApp.'];
                    break;
                case 'purge_app':
                    // Junk drafts and rejected forms only — an approved person is deleted from their member page.
                    $app = AgentPro::application((int) ($_POST['app'] ?? 0));
                    if ($app === null) { throw new RuntimeException('Application not found.'); }
                    if ($app['status'] === 'approved') { throw new RuntimeException('This person was approved — open their member page to archive or delete them.'); }
                    $res = TeamPurge::purge('ap', (int) $app['id'], $admin, (string) ($_POST['confirm_delete'] ?? ''), (string) ($_POST['purge_reason'] ?? ''));
                    $flash = ['ok', '🗑 Deleted permanently: ' . $res['ref'] . ' (' . $res['rows'] . ' rows, ' . $res['files'] . ' files). Backup: ' . $res['backup']];
                    break;
                case 'pay':
                    if (!$canPay) { throw new RuntimeException('Your role cannot pay out.'); }
                    $lid = $payWithdrawal((int) $_POST['req'], $me, (string) ($_POST['ref'] ?? ''), (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', '✓ Paid. Voucher ' . AgentWallet::voucherFor($lid) . ' — the member was told on WhatsApp.'];
                    break;
                case 'decline_wd':
                    if (!$canPay) { throw new RuntimeException('Your role cannot decide payouts.'); }
                    AgentPro::declineWithdrawal((int) $_POST['req'], $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Declined — the member was told why.'];
                    break;
                case 'confirm_dep':
                case 'decline_dep':
                    if (!$canPay) { throw new RuntimeException('Your role cannot confirm payments.'); }
                    $decideDeposit((int) $_POST['dep'], $act === 'confirm_dep' ? 'confirmed' : 'declined', $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', $act === 'confirm_dep' ? '✓ Confirmed and written to the wallet.' : 'Declined — the member was told why.'];
                    break;
                case 'adjust':
                    if (!$canPay) { throw new RuntimeException('Only a super-admin or someone who may pay out can credit or debit a wallet.'); }
                    $nonce = (string) ($_POST['nonce'] ?? '');
                    if ($nonce === '' || !hash_equals((string) ($_SESSION['tp_adj_nonce'] ?? ''), $nonce)) {
                        throw new RuntimeException('This form was already sent (or is old) — nothing was written twice. Check the history below.');
                    }
                    unset($_SESSION['tp_adj_nonce']);
                    $aid = (int) ($_POST['member'] ?? 0);
                    if ($aid === $me) { throw new RuntimeException('Nobody may credit or debit their own wallet · आफ्नै खातामा मिल्दैन।'); }
                    $who = Database::fetch('SELECT id, full_name, role FROM admins WHERE id = :i', ['i' => $aid]);
                    if ($who === null) { throw new RuntimeException('Choose the member.'); }
                    $acct = (string) ($_POST['account'] ?? '') === 'cash' ? 'cash' : 'commission';
                    $dir  = (string) ($_POST['direction'] ?? '') === 'debit' ? 'debit' : 'credit';
                    $amt  = round((float) ($_POST['amount'] ?? 0), 2);
                    if ($amt <= 0 || $amt > 500000) { throw new RuntimeException('Enter an amount between ₹1 and ₹5,00,000.'); }
                    $why  = Security::clean((string) ($_POST['reason'] ?? ''), 200);
                    if (mb_strlen($why) < 5) { throw new RuntimeException('Write the reason (at least 5 characters) — it is printed on the statement.'); }
                    $lid = AgentWallet::record($aid, 'adjustment', $amt, [
                        'account' => $acct, 'direction' => $dir, 'by' => $me,
                        'note' => 'Office ' . $dir . ' · ' . $why, 'ref' => Security::clean((string) ($_POST['ref'] ?? ''), 60),
                    ]);
                    Logger::audit('agentpro.wallet_adjust', 'admin', (string) $aid, null,
                        ['ledger' => $lid, 'account' => $acct, 'direction' => $dir, 'amount' => $amt], 'Manual ' . $dir . ' by admin #' . $me, $why);
                    $flash = ['ok', '✓ ' . ucfirst($dir) . ' ' . inr($amt) . ' (' . ($acct === 'cash' ? 'cash held' : 'commission due') . ') written for ' . $who['full_name'] . '.'];
                    break;
                case 'invite':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can invite.'); }
                    $newLink = AgentPro::createInvite((string) $_POST['role'], (string) ($_POST['designation'] ?? ''), $me, (int) ($_POST['days'] ?? 7), (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Invitation link ready — send it on WhatsApp.'];
                    break;
                case 'uninvite':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can do that.'); }
                    $iid = (int) $_POST['id'];
                    Database::update('ap_invites', ['expires_at' => date('Y-m-d H:i:s')], 'id = :i AND used_at IS NULL', ['i' => $iid]);
                    Logger::audit('agentpro.invite_cancelled', 'invite', (string) $iid, null, null, 'Invitation cancelled by admin #' . $me);
                    $flash = ['ok', 'Invitation cancelled.'];
                    break;
            }
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
    }
}

$counts = AgentPro::counts();
$roster = AgentPro::roster();
$archivedIds = TeamPurge::archivedIds();
// The latest application of every member (the "📄 Form" link on each card).
$appOf = [];
foreach (Database::fetchAll('SELECT id, admin_id FROM ap_applications WHERE admin_id IS NOT NULL ORDER BY id') as $r) {
    $appOf[(int) $r['admin_id']] = (int) $r['id'];
}
$draftCount = (int) Database::scalar("SELECT COUNT(*) FROM ap_applications WHERE status = 'draft'", [], 0);

admin_header('Team & ID Cards', 'team.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head('Join applications, ID cards, visiting cards and wallets — one place.', [],
    ($canOffice ? '<a class="btn ok sm tp-add" href="' . $e($officeUrl) . '">＋ नयाँ सदस्य · Add member (office form)</a>' : '')
    . '<a class="btn ghost sm" href="/join.php" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg> Join form</a>'
    . '<a class="btn soft sm" href="/admin/ai-local.php"><svg class="a-ic"><use href="#a-msg"/></svg> WhatsApp & AI</a>');
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
$tabLabel = ['approvals' => ['Approvals', $counts['applications']], 'agents' => ['Agents', 0], 'staff' => ['Staff', 0],
             'money' => ['Money', $counts['withdrawals'] + $counts['deposits']], 'cards' => ['Print cards', 0], 'invites' => ['Invites', 0]];
?>
<style>
.tp-add{min-height:48px;font-weight:800}
.tp-sub{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}
.tp-sub a{min-height:44px;display:inline-flex;align-items:center;gap:6px;padding:0 14px;border-radius:99px;border:1px solid var(--line);background:var(--card);color:var(--ink);font-weight:700;font-size:13px;text-decoration:none}
.tp-sub a.on{background:var(--blue,#1d4ed8);border-color:var(--blue,#1d4ed8);color:#fff}
.tp-sub .c{background:rgba(0,0,0,.12);border-radius:99px;padding:0 7px;font-size:11px}
.tp-paper{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.tp-paper a{display:block;width:84px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;position:relative;text-decoration:none}
.tp-paper img{width:100%;aspect-ratio:210/297;object-fit:cover;display:block}
.tp-paper span{display:block;font-size:10.5px;font-weight:700;text-align:center;padding:2px;color:#0f172a}
.tp-thumb img{background:#fff;object-fit:contain!important}
.tp-lock{display:flex;align-items:center;justify-content:center;text-align:center;font-size:12px;font-weight:700;color:var(--mut,#64748b);border:1px dashed var(--line);border-radius:12px;padding:10px;min-height:90px}
.tp-hist{display:flex;flex-direction:column;gap:8px}
.tp-hrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:10px 12px;border:1px solid var(--line);border-radius:14px;background:var(--card)}
.tp-hrow .who{flex:1;min-width:200px}
.tp-hrow .who small{display:block;color:var(--mut,#64748b)}
.tp-pw{position:relative}
.tp-pw .tp-formlink{position:absolute;top:8px;right:8px;min-height:36px;min-width:44px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:var(--soft,#eef2fb);color:var(--ink);font-size:12px;font-weight:700;text-decoration:none;z-index:2;padding:0 8px}
.tp-adj{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.tp-adj label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:700;color:var(--mut,#64748b)}
.tp-adj input,.tp-adj select{min-height:48px;padding:0 12px;border:1px solid var(--line-2,var(--line));border-radius:12px;background:var(--card);color:var(--ink);font:inherit}
.tp-adj .full{grid-column:1/-1}
@media(max-width:640px){.tp-adj{grid-template-columns:1fr}}
.tp-req form input[type=text]{min-height:44px}
</style>
<div class="ap-a">
<nav class="tp-tabs" aria-label="Team sections">
  <?php foreach ($tabLabel as $k => [$label, $n]): ?>
    <a href="?tab=<?= $e($k) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $e($label) ?><?= $n > 0 ? '<span class="c">' . (int) $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'approvals'): ?>
  <nav class="tp-sub" aria-label="Applications">
    <a href="?tab=approvals&amp;view=waiting" class="<?= $view === 'waiting' ? 'on' : '' ?>">⏳ Waiting · पर्खिरहेको<?= $counts['applications'] > 0 ? '<span class="c">' . (int) $counts['applications'] . '</span>' : '' ?></a>
    <a href="?tab=approvals&amp;view=drafts" class="<?= $view === 'drafts' ? 'on' : '' ?>">📝 Half-filled · अधुरो<?= $draftCount > 0 ? '<span class="c">' . $draftCount . '</span>' : '' ?></a>
    <a href="?tab=approvals&amp;view=history" class="<?= $view === 'history' ? 'on' : '' ?>">🗂 History · इतिहास</a>
    <?php if ($canOffice): ?><a href="<?= $e($officeUrl) ?>">＋ Add member (office form)</a><?php endif; ?>
  </nav>
<?php
    // Typed-DELETE panel for a junk draft / rejected form (super-admin).
    $purgeApp = (int) ($_GET['purge'] ?? 0);
    if ($purgeApp > 0 && $isSuper) {
        $pa = AgentPro::application($purgeApp);
        if ($pa !== null && $pa['status'] !== 'approved') {
            echo TeamPurge::panelHtml(TeamPurge::history('ap', $purgeApp), '?tab=approvals&view=' . $view,
                ['action' => 'purge_app', 'app' => $purgeApp], '', true, '?tab=approvals&view=' . $view);
        }
    }

    if ($view === 'waiting'):
    $apps = AgentPro::applications('submitted');
    if ($apps === []) {
        echo admin_empty('No applications waiting', 'New agents apply at /join.php — share the link on WhatsApp.', '✅',
            '<a class="btn wa sm" href="' . $e(whatsappLink('', 'Become an S Hari Global agent: ' . appUrl('join.php'))) . '" target="_blank" rel="noopener">Share the join link</a>');
    }
    foreach ($apps as $a):
        $read = jsonColumn($a['readout'] ?? null);
        $readF = [];
        $readS = [];
        foreach (['front', 'back', 'ai_front', 'ai_back', 'paper'] as $slot) {
            foreach ((array) ($read[$slot]['fields'] ?? []) as $k => $v) {
                if (!isset($readF[$k]) && is_scalar($v)) {
                    $readF[$k] = (string) $v;
                    $readS[$k] = (string) ($read[$slot]['sources'][$k] ?? (str_starts_with($slot, 'ai') ? 'ai' : ($slot === 'paper' ? 'paper' : 'ocr')));
                }
            }
        }
        $fact = static function (string $label, string $typed, string $key) use ($readF, $readS, $e): string {
            $r = $readF[$key] ?? '';
            $same = $r === '' || mb_strtolower(trim($r)) === mb_strtolower(trim($typed));
            return '<dt>' . $e($label) . '</dt><dd>' . $e($typed !== '' ? $typed : '—')
                . ($r !== '' ? '<span class="src ' . $e($readS[$key] ?? '') . '">' . ($same ? '✓ ' : '') . $e(strtoupper($readS[$key] ?? '')) . '</span>' : '')
                . (!$same ? '<div class="diff">ID says: ' . $e($r) . '</div>' : '') . '</dd>';
        };
        $docUrl = static fn(string $f, int $i = -1): string => '/admin/team-doc.php?app=' . (int) $a['id'] . '&amp;f=' . $f . ($i >= 0 ? '&amp;i=' . $i : '');
        $img = static fn(string $f, string $label, bool $doc = false, string $cls = ''): string =>
            '<a class="' . ($doc ? 'doc ' : '') . $cls . '" href="' . $docUrl($f) . '" target="_blank" rel="noopener"><img loading="lazy" alt="" src="' . $docUrl($f) . '"><span>' . $label . '</span></a>';
        $isOrg = $a['kind'] === 'agent' && $a['agent_kind'] === 'org';
        $extras = jsonColumn($a['extras'] ?? null);
        $paper = [];
        $other = [];
        foreach ($extras as $i => $x) {
            if (!is_array($x)) { continue; }
            if (stripos((string) ($x['label'] ?? ''), 'paper form') === 0) { $paper[$i] = $x; } else { $other[$i] = $x; }
        }
        $byOffice = (string) ($a['wa_verified_via'] ?? '') === 'office' || !empty($a['office_filled_by']);
        ?>
  <article class="tp-app">
    <div class="tp-app-h">
      <b><?= $e($isOrg ? $a['org_name'] : $a['full_name']) ?></b>
      <span class="ref"><?= $e($a['ref']) ?></span>
      <span class="tp-chip blue"><?= $e($a['kind'] === 'staff' ? ucfirst((string) $a['role_wanted']) . ' (staff)' : ($isOrg ? 'Agency' : 'Agent')) ?></span>
      <?php if (!empty($a['wa_verified_at'])): ?><span class="tp-chip ok">WhatsApp ✓ <?= $e($a['wa_verified_via']) ?></span><?php else: ?><span class="tp-chip warn">WhatsApp not verified</span><?php endif; ?>
      <?php if ($byOffice): ?><span class="tp-chip warn">🏢 Filled by the office</span><?php endif; ?>
      <?php if ($paper !== []): ?><span class="tp-chip blue">📝 Paper form</span><?php endif; ?>
      <span class="ph-sub" style="margin-left:auto"><?= $e(timeAgo((string) $a['submitted_at'])) ?></span>
    </div>
    <div class="tp-app-b">
      <div>
        <?php if ($canKyc): ?>
        <div class="tp-photos">
          <?= !empty($a['selfie']) ? $img('selfie', 'Selfie') : '' ?>
          <?= !empty($a['doc_front']) ? $img('front', 'ID front', true) : '' ?>
          <?= !empty($a['doc_back']) ? $img('back', 'ID back', true) : '' ?>
          <?= !empty($a['thumb']) ? $img('thumb', 'Thumb print', true, 'tp-thumb') : '' ?>
          <?php foreach ($other as $i => $x): ?>
            <a class="doc" href="<?= $docUrl('extra', (int) $i) ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--ink-2)">📎 <?= $e($x['label'] ?? 'Doc') ?></a>
          <?php endforeach; ?>
        </div>
        <?php if (empty($a['thumb'])): ?><small class="ph-sub">Thumb print: skipped · औंठाछाप छैन</small><?php endif; ?>
        <?php if ($paper !== []): ?>
          <div class="tp-paper" aria-label="Paper form pages">
            <?php foreach ($paper as $i => $x): ?>
              <a href="<?= $docUrl('extra', (int) $i) ?>" target="_blank" rel="noopener"><img loading="lazy" alt="" src="<?= $docUrl('extra', (int) $i) ?>"><span><?= $e($x['label'] ?? 'Paper form') ?></span></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php else: ?>
          <div class="tp-lock">🔒 Photos, ID documents and the paper form are shown to a super-admin or a manager only · KYC कागजात म्यानेजरलाई मात्र</div>
        <?php endif; ?>
      </div>
      <dl class="tp-facts">
        <?php if ($isOrg): ?><dt>Contact</dt><dd><?= $e($a['full_name']) ?></dd><?php endif; ?>
        <?= $fact('Name', (string) $a['full_name'], 'full_name') ?>
        <dt>Mobile</dt><dd class="ap-mono"><?= $e(($a['country'] === 'NP' ? '+977 ' : '+91 ') . $a['phone']) ?></dd>
        <?= $fact('Born', (string) ($a['dob'] ?? ''), 'dob') ?>
        <?= $fact('Gender', (string) ($a['gender'] ?? ''), 'gender') ?>
        <?= $fact('Guardian', (string) ($a['guardian'] ?? ''), 'guardian') ?>
        <dt>ID</dt><dd><?= $e((AgentPro::ID_TYPES[$a['id_type']] ?? $a['id_type']) . ' · ' . ($a['id_number_masked'] ?? '')) ?></dd>
        <dt>Address</dt><dd><?= $e(implode(', ', array_filter([$a['address'], $a['city'], $a['district'], $a['state'], $a['pincode']]))) ?></dd>
        <dt>Payout</dt><dd><?= $e($a['upi_id'] ?: ($a['bank_account_last4'] ? ($a['bank_name'] ?? '') . ' ••••' . $a['bank_account_last4'] : ($a['esewa_id'] ?: '—'))) ?></dd>
        <?php if (!empty($a['occupation'])): ?><dt>Work</dt><dd><?= $e($a['occupation']) ?></dd><?php endif; ?>
        <?php if (!empty($a['email'])): ?><dt>Email</dt><dd><?= $e($a['email']) ?></dd><?php endif; ?>
      </dl>
    </div>
    <div class="tp-actions">
      <?php if (Auth::can('staff.manage')): ?>
      <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="app" value="<?= (int) $a['id'] ?>">
        <button class="tp-approve" type="submit">✓ Approve</button></form>
      <?php if (!$canKyc): ?><small class="ph-sub" style="align-self:center">KYC still to check — a manager verifies the ID · KYC म्यानेजरले जाँच्छ</small><?php endif; ?>
      <?php endif; ?>
      <?php if ($canOffice): ?><a class="btn soft" href="<?= $e($officeUrl . '&d=' . rawurlencode((string) $a['draft_key'])) ?>">✏️ Finish / edit</a><?php endif; ?>
      <a class="btn ghost" href="/admin/team-form.php?app=<?= (int) $a['id'] ?>" target="_blank" rel="noopener">📄 Form</a>
      <?php if (Auth::can('staff.manage')): ?>
      <form method="post" style="display:flex;gap:6px;flex:1;min-width:240px;margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="app" value="<?= (int) $a['id'] ?>">
        <input type="text" name="note" placeholder="Why not / what to fix (sent on WhatsApp)" required style="flex:1;height:48px;padding:0 12px;border:1px solid var(--line-2);border-radius:12px;background:var(--card);color:var(--ink);font:inherit">
        <button class="btn ghost danger" type="submit">✕ Reject</button></form>
      <a class="btn wa" href="<?= $e(whatsappLink(($a['country'] === 'NP' ? '977' : '91') . $a['phone'], 'Namaste ' . $a['full_name'] . ', about your application ' . $a['ref'] . ' —')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
      <?php endif; ?>
    </div>
  </article>
    <?php endforeach; ?>

<?php elseif ($view === 'drafts'):
    $drafts = AgentPro::applications('draft', 200);
    ?>
  <p class="ph-sub" style="margin-top:0">Forms someone started and did not send yet (saved automatically). The office can open one and finish it. · अधुरो फारम — अफिसले पूरा गर्न सक्छ।</p>
  <?php if ($drafts === []) { echo admin_empty('No half-filled forms', 'Forms that people start and leave appear here.', '📝'); } ?>
  <div class="tp-hist">
    <?php foreach ($drafts as $d): $nm = trim((string) (($d['kind'] === 'agent' && $d['agent_kind'] === 'org' ? $d['org_name'] : $d['full_name']) ?? '')); ?>
      <div class="tp-hrow">
        <span class="who"><b><?= $e($nm !== '' ? $nm : '(no name yet)') ?></b> <span class="ap-mono" style="font-size:12px"><?= $e($d['ref']) ?></span>
          <small><?= $e(ucfirst((string) $d['kind'])) ?><?= !empty($d['phone']) ? ' · ' . $e($d['phone']) : '' ?> · last saved <?= $e(timeAgo((string) ($d['updated_at'] ?? $d['created_at']))) ?><?= !empty($d['wa_verified_at']) ? ' · WhatsApp ✓' : '' ?></small></span>
        <?php if ($canOffice): ?><a class="btn soft sm" href="<?= $e($officeUrl . '&d=' . rawurlencode((string) $d['draft_key'])) ?>">✏️ Finish / edit</a><?php endif; ?>
        <?php if ($isSuper): ?><a class="btn ghost danger sm" href="?tab=approvals&amp;view=drafts&amp;purge=<?= (int) $d['id'] ?>#purge">🗑</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

<?php else: /* history */
    $hq = trim(Security::clean((string) ($_GET['q'] ?? ''), 60));
    $hs = (string) ($_GET['st'] ?? '');
    $sts = in_array($hs, ['approved', 'rejected'], true) ? [$hs] : ['approved', 'rejected'];
    $p = [];
    $where = 'a.status ' . (count($sts) === 1 ? '= :s0' : 'IN (:s0, :s1)');
    foreach ($sts as $i => $s) { $p['s' . $i] = $s; }
    if ($hq !== '') {
        $where .= ' AND (a.ref LIKE :q1 OR a.full_name LIKE :q2 OR a.org_name LIKE :q3 OR a.phone LIKE :q4)';
        foreach (['q1', 'q2', 'q3', 'q4'] as $k) { $p[$k] = '%' . $hq . '%'; }
    }
    $hist = Database::fetchAll(
        "SELECT a.id, a.ref, a.status, a.kind, a.agent_kind, a.role_wanted, a.full_name, a.org_name, a.phone, a.admin_id, a.draft_key,
                a.submitted_at, a.decided_at, a.decision_note, d.full_name AS decided_name
           FROM ap_applications a LEFT JOIN admins d ON d.id = a.decided_by
          WHERE $where ORDER BY COALESCE(a.decided_at, a.submitted_at, a.created_at) DESC, a.id DESC LIMIT 200", $p);
    ?>
  <form method="get" class="tp-bar" role="search">
    <input type="hidden" name="tab" value="approvals"><input type="hidden" name="view" value="history">
    <input type="search" name="q" value="<?= $e($hq) ?>" placeholder="Search name, JN- ref, mobile…" aria-label="Search history" style="min-height:48px">
    <select name="st" aria-label="Status" style="min-height:48px"><option value="">Approved + rejected</option><option value="approved"<?= $hs === 'approved' ? ' selected' : '' ?>>Approved</option><option value="rejected"<?= $hs === 'rejected' ? ' selected' : '' ?>>Rejected</option></select>
    <button class="btn sm" type="submit" style="min-height:48px">Search</button>
  </form>
  <?php if ($hist === []) { echo admin_empty('Nothing found', $hq !== '' ? 'Try another name or number.' : 'Approved and rejected forms appear here.', '🗂'); } ?>
  <div class="tp-hist">
    <?php foreach ($hist as $h): $nm = (string) (($h['kind'] === 'agent' && $h['agent_kind'] === 'org' ? $h['org_name'] : $h['full_name']) ?? ''); ?>
      <div class="tp-hrow">
        <span class="tp-chip <?= $h['status'] === 'approved' ? 'ok' : 'bad' ?>"><?= $e($h['status'] === 'approved' ? '✓ Approved' : '✕ Rejected') ?></span>
        <span class="who"><b><?= $e($nm !== '' ? $nm : '—') ?></b> <span class="ap-mono" style="font-size:12px"><?= $e($h['ref']) ?></span>
          <small><?= $e($h['kind'] === 'staff' ? ucfirst((string) $h['role_wanted']) : 'Agent') ?> · <?= $e((string) $h['phone']) ?>
            · <?= $e($h['decided_at'] ? formatDate(substr((string) $h['decided_at'], 0, 10), 'j M Y') : '—') ?><?= $h['decided_name'] ? ' by ' . $e($h['decided_name']) : '' ?>
            <?= $h['decision_note'] ? ' · “' . $e($h['decision_note']) . '”' : '' ?></small></span>
        <a class="btn ghost sm" href="/admin/team-form.php?app=<?= (int) $h['id'] ?>" target="_blank" rel="noopener">📄 Form</a>
        <?php if ($h['status'] === 'approved' && !empty($h['admin_id'])): ?><a class="btn soft sm" href="/admin/team-member.php?id=<?= (int) $h['admin_id'] ?>">Member →</a><?php endif; ?>
        <?php if ($h['status'] === 'rejected' && $isSuper): ?><a class="btn ghost danger sm" href="?tab=approvals&amp;view=history&amp;purge=<?= (int) $h['id'] ?>#purge">🗑</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php elseif ($tab === 'agents' || $tab === 'staff'):
    $wantAgents = $tab === 'agents';
    $kycF = (string) ($_GET['kyc'] ?? '');
    $showArch = !empty($_GET['archived']);
    $mine = array_values(array_filter($roster, static fn(array $r): bool => $r['isAgent'] === $wantAgents));
    $nArch = count(array_filter($mine, static fn(array $r): bool => isset($archivedIds[$r['id']])));
    $people = array_values(array_filter($mine, static fn(array $r): bool =>
        ($kycF === '' || $r['kyc'] === $kycF) && ($showArch ? isset($archivedIds[$r['id']]) : !isset($archivedIds[$r['id']]))));
    ?>
  <div class="tp-bar">
    <input type="search" id="tpq" placeholder="Search name, code, branch…" aria-label="Search">
    <a class="btn ghost sm" href="?tab=<?= $e($tab) ?>">All (<?= count($mine) - $nArch ?>)</a>
    <?php if ($wantAgents): ?><a class="btn ghost sm" href="?tab=agents&amp;kyc=submitted">KYC to check</a><?php endif; ?>
    <?php if ($nArch > 0): ?><a class="btn ghost sm" href="?tab=<?= $e($tab) ?>&amp;archived=1">🗄 Archived (<?= $nArch ?>)</a><?php endif; ?>
    <?php if ($canOffice): ?><a class="btn ok sm" href="<?= $e($officeUrl) ?>">＋ Add <?= $wantAgents ? 'agent' : 'staff' ?></a><?php endif; ?>
  </div>
  <?php if ($people === []) { echo admin_empty('Nobody here yet', $wantAgents ? 'Share /join.php — approved agents appear here.' : 'Add staff with the office form, or invite them from the Invites tab.', '👥'); } ?>
  <div class="tp-people" id="tpList">
    <?php foreach ($people as $p):
        $chips = [];
        $chips[] = $p['active'] ? ($p['pendingRef'] !== '' ? ['warn', 'Applied ' . $p['pendingRef']] : ['ok', 'Active']) : ($p['pendingRef'] !== '' ? ['warn', 'Applied ' . $p['pendingRef']] : ['bad', 'Login off']);
        if (isset($archivedIds[$p['id']])) { $chips[] = ['bad', 'Archived']; }
        if ($p['suspended'] || $p['card'] === 'suspended') { $chips[] = ['bad', 'Suspended']; }
        $chips[] = [$p['kyc'] === 'verified' ? 'ok' : ($p['kyc'] === 'submitted' ? 'warn' : ''), 'KYC ' . $p['kyc']];
        if ($p['card'] === 'revoked') { $chips[] = ['bad', 'Card revoked']; }
        if ($p['isAgent']) { $chips[] = ['blue', $p['kind'] === 'org' ? 'Agency' : 'Person']; } else { $chips[] = ['blue', ucfirst($p['role'])]; }
        ?>
      <div class="tp-pw" data-q="<?= $e(mb_strtolower($p['name'] . ' ' . $p['idNo'] . ' ' . $p['branch'] . ' ' . $p['phone'])) ?>">
      <a class="tp-person" href="/admin/team-member.php?id=<?= (int) $p['id'] ?>">
        <span class="tp-ph"><?= $p['photoUrl'] !== '' ? '<img loading="lazy" alt="" src="' . $e($p['photoUrl']) . '">' : $e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
        <span style="min-width:0;flex:1;padding-right:44px"><b><?= $e($p['name']) ?></b><span class="code"><?= $e($p['idNo'] !== '' ? $p['idNo'] : '—') ?></span>
          <small> · <?= $e($p['designation']) ?><?= $p['branch'] !== '' ? ' · ' . $e($p['branch']) : '' ?></small>
          <span class="tp-chips"><?php foreach ($chips as [$c, $t]): ?><span class="tp-chip <?= $e($c) ?>"><?= $e($t) ?></span><?php endforeach; ?></span></span>
      </a>
      <?php if (isset($appOf[$p['id']])): ?><a class="tp-formlink" href="/admin/team-form.php?app=<?= (int) $appOf[$p['id']] ?>" target="_blank" rel="noopener" title="Filled form">📄 Form</a><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <script>(function(){var q=document.getElementById('tpq');if(!q)return;q.addEventListener('input',function(){var v=q.value.toLowerCase().trim();document.querySelectorAll('#tpList .tp-pw').forEach(function(a){a.style.display=!v||a.dataset.q.indexOf(v)>-1?'':'none';});});})();</script>

<?php elseif ($tab === 'money'):
    $wds = AgentWallet::openPayoutRequests();
    $deps = AgentPro::openDeposits();
    $mf = (int) ($_GET['m'] ?? 0);
    $_SESSION['tp_adj_nonce'] = bin2hex(random_bytes(12));
    $hp = $mf > 0 ? ['m' => $mf] : [];
    $hw = $mf > 0 ? ' AND r.agent_admin_id = :m' : '';
    $wdHist = Database::fetchAll("SELECT r.*, a.full_name, d.full_name AS by_name FROM agent_payout_requests r
        LEFT JOIN admins a ON a.id = r.agent_admin_id LEFT JOIN admins d ON d.id = r.decided_by
        WHERE r.status <> 'open'$hw ORDER BY COALESCE(r.decided_at, r.created_at) DESC, r.id DESC LIMIT 30", $hp);
    $depHist = Database::fetchAll("SELECT r.*, a.full_name, d.full_name AS by_name FROM ap_deposit_requests r
        LEFT JOIN admins a ON a.id = r.admin_id LEFT JOIN admins d ON d.id = r.decided_by
        WHERE r.status <> 'open'" . ($mf > 0 ? ' AND r.admin_id = :m' : '') . ' ORDER BY COALESCE(r.decided_at, r.created_at) DESC, r.id DESC LIMIT 30', $hp);
    $ledHist = Database::fetchAll("SELECT l.*, a.full_name, c.full_name AS by_name FROM agent_ledger l
        LEFT JOIN admins a ON a.id = l.agent_admin_id LEFT JOIN admins c ON c.id = l.created_by
        WHERE l.booking_id IS NULL" . ($mf > 0 ? ' AND l.agent_admin_id = :m' : '') . ' ORDER BY l.id DESC LIMIT 40', $hp);
    $mfName = $mf > 0 ? (string) Database::scalar('SELECT full_name FROM admins WHERE id = :i', ['i' => $mf], '') : '';
    ?>
  <div class="tp-money">
    <section class="panel"><h2><svg class="a-ic"><use href="#a-send"/></svg> Withdrawals to pay (<?= count($wds) ?>)</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
      <?php if ($wds === []) { echo admin_empty('Nothing to pay', 'Agents request from /me/ or by writing "withdraw 2000" on WhatsApp.', '💸'); } ?>
      <?php foreach ($wds as $w): $pf = AgentWallet::profile((int) $w['agent_admin_id']); $due = AgentWallet::balances((int) $w['agent_admin_id'])['commission']; ?>
        <div class="tp-req" style="flex-wrap:wrap">
          <span class="amt"><?= $e(inr((float) $w['amount'])) ?></span>
          <span class="who"><b><?= $e($w['agent_name']) ?> <span class="code ap-mono" style="font-size:12px"><?= $e(AgentWallet::agentCodeLabel((int) $w['agent_admin_id'])) ?></span></b>
            <small>To: <?= $e(trim(($pf['payout_method'] ?? '') . ' ' . ($pf['payout_account'] ?? '')) ?: 'no payout details') ?> · due <?= $e(inr($due)) ?> · <?= $e(timeAgo((string) $w['created_at'])) ?></small></span>
          <?php if ($canPay): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="req" value="<?= (int) $w['id'] ?>">
            <input type="text" name="ref" placeholder="UPI / bank ref (needed)" minlength="5" maxlength="60"><button class="btn ok sm" name="action" value="pay" type="submit" onclick="var r=this.form.ref;if(r.value.trim().length<5){r.focus();alert('Type the payment reference first (UPI / bank ref).');return false;}return confirm('Mark this withdrawal as PAID? It is written to the ledger at once.')">✓ Paid</button>
            <input type="text" name="note" placeholder="Reason if declining"><button class="btn ghost danger sm" name="action" value="decline_wd" type="submit">Decline</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></section>
    <section class="panel"><h2><svg class="a-ic"><use href="#a-rupee"/></svg> Payments in — match in the bank (<?= count($deps) ?>)</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
      <?php if ($deps === []) { echo admin_empty('Nothing to match', 'Members send "jama 1500 UTR …" on WhatsApp or use Pay company in /me/.', '💵'); } ?>
      <?php foreach ($deps as $d): $held = AgentWallet::balances((int) $d['admin_id'])['cash']; ?>
        <div class="tp-req" style="flex-wrap:wrap">
          <span class="amt"><?= $e(inr((float) $d['amount'])) ?></span>
          <span class="who"><b><?= $e($d['member_name']) ?></b>
            <small><?= $e(strtoupper((string) $d['method'])) ?> · <?= $d['utr'] ? 'UTR <span class="ap-mono">' . $e($d['utr']) . '</span> · ' : '' ?><?= $e($d['purpose'] === 'security' ? 'security deposit' : 'cash held ' . inr($held)) ?> · <?= $e(timeAgo((string) $d['created_at'])) ?>
            <?= $d['proof_path'] ? ' · <a href="/admin/team-doc.php?deposit=' . (int) $d['id'] . '" target="_blank" rel="noopener">screenshot</a>' : '' ?></small></span>
          <?php if ($canPay): ?>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="dep" value="<?= (int) $d['id'] ?>">
            <button class="btn ok sm" name="action" value="confirm_dep" type="submit">✓ Received</button>
            <input type="text" name="note" placeholder="Reason if not found"><button class="btn ghost danger sm" name="action" value="decline_dep" type="submit">Not found</button></form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div></section>
  </div>

  <?php if ($canPay): ?>
  <section class="panel" id="adjust"><h2><svg class="a-ic"><use href="#a-edit"/></svg> Manual credit / debit · हातैले जम्मा / कट्टा</h2><div class="panel-body">
    <p class="ph-sub" style="margin-top:0">Written to the real wallet ledger with your name and the reason (it shows on the member's statement). Use it for a correction, a bonus or a fine — never for a ticket sale.</p>
    <form method="post" class="tp-adj" onsubmit="var b=this.querySelector('button[type=submit]');if(b.disabled)return false;if(!confirm('Write this ' + this.direction.value + ' of ₹' + this.amount.value + ' to the wallet?'))return false;b.disabled=true;b.textContent='Saving…';return true;">
      <?= Security::csrfField() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="nonce" value="<?= $e($_SESSION['tp_adj_nonce']) ?>">
      <label class="full">Member · सदस्य<select name="member" required><option value="">— choose —</option>
        <?php foreach ($roster as $r): if ((int) $r['id'] === $me || $r['role'] === 'superadmin') { continue; } ?>
          <option value="<?= (int) $r['id'] ?>"<?= $mf === (int) $r['id'] ? ' selected' : '' ?>><?= $e($r['name'] . ($r['idNo'] !== '' ? ' · ' . $r['idNo'] : '') . ' · ' . $r['role']) ?></option>
        <?php endforeach; ?></select></label>
      <label>Account<select name="account"><option value="commission">Commission due (company owes the member)</option><option value="cash">Cash held (member owes the company)</option></select></label>
      <label>Direction<select name="direction"><option value="credit">＋ Credit (increase)</option><option value="debit">− Debit (decrease)</option></select></label>
      <label>Amount (₹)<input type="number" name="amount" min="1" max="500000" step="0.01" required inputmode="decimal"></label>
      <label>Reference (optional)<input type="text" name="ref" maxlength="60" placeholder="voucher / UPI ref"></label>
      <label class="full">Reason · कारण (printed on the statement)<input type="text" name="reason" minlength="5" maxlength="200" required placeholder="e.g. Bonus for Dashain sales / correction of 12 Oct entry"></label>
      <div class="full"><button class="btn" type="submit" style="min-height:48px">Write to the ledger</button></div>
    </form>
  </div></section>
  <?php endif; ?>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-clock"/></svg> History<?= $mfName !== '' ? ' — ' . $e($mfName) . ' <a class="btn link sm" href="?tab=money#hist">show everyone</a>' : '' ?></h2><div class="panel-body" id="hist" style="display:flex;flex-direction:column;gap:14px">
    <div><b>Wallet movements by the office (payouts, cash handed over, adjustments)</b>
      <div class="tp-hist" style="margin-top:6px">
      <?php if ($ledHist === []) { echo '<small class="ph-sub">Nothing yet.</small>'; } ?>
      <?php foreach ($ledHist as $l): ?>
        <div class="tp-hrow"><span class="amt ap-mono" style="min-width:90px;font-weight:800;color:<?= (float) $l['amount'] < 0 ? '#b91c1c' : '#15803d' ?>"><?= $e(((float) $l['amount'] < 0 ? '−' : '+') . inr(abs((float) $l['amount']))) ?></span>
          <span class="who"><b><a href="?tab=money&amp;m=<?= (int) $l['agent_admin_id'] ?>#hist"><?= $e($l['full_name'] ?? ('#' . $l['agent_admin_id'])) ?></a></b>
            <small><?= $e($l['entry_type']) ?> · <?= $e($l['account']) ?><?= $l['ref'] ? ' · ' . $e($l['ref']) : '' ?> · <?= $e($l['note']) ?> · <?= $e(timeAgo((string) $l['created_at'])) ?><?= $l['by_name'] ? ' · by ' . $e($l['by_name']) : '' ?></small></span></div>
      <?php endforeach; ?>
      </div></div>
    <div><b>Withdrawals decided</b>
      <div class="tp-hist" style="margin-top:6px">
      <?php if ($wdHist === []) { echo '<small class="ph-sub">Nothing yet.</small>'; } ?>
      <?php foreach ($wdHist as $w): ?>
        <div class="tp-hrow"><span class="tp-chip <?= $w['status'] === 'paid' ? 'ok' : 'bad' ?>"><?= $e(ucfirst((string) $w['status'])) ?></span><b class="ap-mono"><?= $e(inr((float) $w['amount'])) ?></b>
          <span class="who"><b><?= $e($w['full_name'] ?? '') ?></b><small><?= $e($w['decided_at'] ? timeAgo((string) $w['decided_at']) : '') ?><?= $w['by_name'] ? ' · by ' . $e($w['by_name']) : '' ?><?= $w['ledger_id'] ? ' · ' . $e(AgentWallet::voucherFor((int) $w['ledger_id'])) : '' ?><?= $w['decided_note'] ? ' · ' . $e($w['decided_note']) : '' ?></small></span></div>
      <?php endforeach; ?>
      </div></div>
    <div><b>Payments in decided</b>
      <div class="tp-hist" style="margin-top:6px">
      <?php if ($depHist === []) { echo '<small class="ph-sub">Nothing yet.</small>'; } ?>
      <?php foreach ($depHist as $d): ?>
        <div class="tp-hrow"><span class="tp-chip <?= $d['status'] === 'confirmed' ? 'ok' : 'bad' ?>"><?= $e(ucfirst((string) $d['status'])) ?></span><b class="ap-mono"><?= $e(inr((float) $d['amount'])) ?></b>
          <span class="who"><b><?= $e($d['full_name'] ?? '') ?></b><small><?= $e($d['purpose'] === 'security' ? 'security deposit' : 'cash handover') ?> · <?= $e(strtoupper((string) $d['method'])) ?><?= $d['utr'] ? ' · ' . $e($d['utr']) : '' ?> · <?= $e($d['decided_at'] ? timeAgo((string) $d['decided_at']) : '') ?><?= $d['by_name'] ? ' · by ' . $e($d['by_name']) : '' ?><?= $d['decided_note'] ? ' · ' . $e($d['decided_note']) : '' ?></small></span></div>
      <?php endforeach; ?>
      </div></div>
  </div></section>

<?php elseif ($tab === 'cards'):
    $active = array_values(array_filter($roster, static fn(array $r): bool => $r['active'] && !isset($archivedIds[$r['id']])));
    ?>
  <form method="get" action="/admin/team-print.php" target="_blank" class="panel">
    <h2><svg class="a-ic"><use href="#a-printer"/></svg> Print cards on A4 — true size, cut lines, backs lined up for two-sided printing</h2>
    <div class="panel-body">
      <div class="tp-bar">
        <button class="btn sm" type="submit" name="what" value="all">🖨 ID + visiting cards</button>
        <button class="btn ghost sm" type="submit" name="what" value="id">ID cards only</button>
        <button class="btn ghost sm" type="submit" name="what" value="vc">Visiting cards only</button>
        <label class="btn link sm" style="cursor:pointer"><input type="checkbox" onclick="document.querySelectorAll('.tp-pick').forEach(function(c){c.checked=this.checked}.bind(this))"> select all</label>
      </div>
      <div class="tp-people">
        <?php foreach ($active as $p): ?>
          <label class="tp-person" style="cursor:pointer"><input type="checkbox" class="tp-pick" name="ids[]" value="<?= (int) $p['id'] ?>" style="width:20px;height:20px">
            <span class="tp-ph"><?= $p['photoUrl'] !== '' ? '<img loading="lazy" alt="" src="' . $e($p['photoUrl']) . '">' : '?' ?></span>
            <span><b><?= $e($p['name']) ?></b><span class="code"><?= $e($p['idNo'] ?: '—') ?></span><small> · <?= $e($p['designation']) ?></small>
              <?= $p['photoUrl'] === '' ? '<span class="tp-chips"><span class="tp-chip warn">no photo yet</span></span>' : '' ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
  </form>

<?php elseif ($tab === 'invites'):
    $invites = Database::fetchAll('SELECT * FROM ap_invites WHERE used_at IS NULL AND expires_at > NOW() ORDER BY id DESC LIMIT 50');
    ?>
  <div class="tp-money">
    <section class="panel"><h2><svg class="a-ic"><use href="#a-plus"/></svg> Invite someone</h2><div class="panel-body">
      <p class="ph-sub" style="margin-top:0">Agents can simply use <a href="/join.php" target="_blank">/join.php</a>. Office staff need a link — the role is fixed by it.<?php if ($canOffice): ?> Or fill it for them now: <a href="<?= $e($officeUrl) ?>">office form</a>.<?php endif; ?></p>
      <?php if ($newLink !== null): ?>
        <div class="flash ok" style="word-break:break-all"><b><?= $e($newLink['link']) ?></b><br>
          <a class="btn wa sm" style="margin-top:8px" href="<?= $e(whatsappLink('', 'Join S Hari Global — fill this form (5 minutes): ' . $newLink['link'])) ?>" target="_blank" rel="noopener">💬 Send on WhatsApp</a></div>
      <?php endif; ?>
      <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="invite">
        <label>Role<select name="role"><?php foreach (AgentPro::INVITE_ROLES as $r): ?><option value="<?= $e($r) ?>"><?= $e(ucfirst($r)) ?></option><?php endforeach; ?></select></label>
        <label>Printed on the ID card<input name="designation" placeholder="e.g. Counter Executive, Surat"></label>
        <label>Valid for<select name="days"><option value="3">3 days</option><option value="7" selected>7 days</option><option value="30">30 days</option></select></label>
        <label>Note (for you)<input name="note" maxlength="160"></label>
        <div class="full"><button class="btn" type="submit">Create link</button></div>
      </form>
    </div></section>
    <section class="panel"><h2><svg class="a-ic"><use href="#a-clock"/></svg> Open invitations</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:8px">
      <?php if ($invites === []) { echo admin_empty('No open invitations'); } ?>
      <?php foreach ($invites as $iv): ?>
        <div class="tp-req"><span class="who"><b><?= $e(ucfirst((string) $iv['role'])) ?><?= $iv['designation'] ? ' · ' . $e($iv['designation']) : '' ?></b>
          <small>until <?= $e(formatDate(substr((string) $iv['expires_at'], 0, 10), 'j M')) ?><?= $iv['note'] ? ' · ' . $e($iv['note']) : '' ?></small></span>
          <form method="post"><?= Security::csrfField() ?><input type="hidden" name="id" value="<?= (int) $iv['id'] ?>">
            <button class="btn ghost sm" type="button" data-link="<?= $e(appUrl('join.php?invite=' . $iv['token'])) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.dataset.link);this.textContent='Copied'">Copy link</button>
            <button class="btn ghost danger sm" name="action" value="uninvite" type="submit">Cancel</button></form></div>
      <?php endforeach; ?>
    </div></section>
  </div>
<?php endif; ?>
</div>
<?php admin_footer();
