<?php
/**
 * admin/team.php — Team & ID Cards (Oct 2026, Agent Pro).
 *
 *   Approvals  every join application as one card: the selfie next to the
 *              ID photo, what the applicant typed next to what was read off
 *              the ID (and from where), and ONE button — ✓ Approve — that
 *              activates the login, issues the agent code and ID card, marks
 *              KYC verified and sends the welcome with the ID card on WhatsApp.
 *   Agents     every agent as a card: photo, code, branch, KYC, card status
 *   Staff      the same for office staff
 *   Money      withdrawals to pay and payments-in to match — one tap each
 *   Cards      print ID / visiting cards for many people at once (A4)
 *   Invites    a link for a new office staff member (role fixed by the link)
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro.php';
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
$me = (int) $admin['id'];
$tab = (string) ($_GET['tab'] ?? 'approvals');
$tabs = ['approvals', 'agents', 'staff', 'money', 'cards', 'invites'];
if (!in_array($tab, $tabs, true)) {
    $tab = 'approvals';
}
$flash = null;
$newLink = null;

if (Security::isPost()) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');
        try {
            switch ($act) {
                case 'approve':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can approve.'); }
                    $r = AgentPro::approve((int) $_POST['app'], $me);
                    $m = AgentPro::member($r['admin_id']);
                    $flash = ['ok', '✓ Approved — ' . ($m['name'] ?? '') . ($r['code'] !== '' ? ' is ' . $r['code'] : '') . '. Login, ID card and welcome sent on WhatsApp.'
                        . (isset($r['password']) ? ' Temporary password: ' . $r['password'] : '')];
                    break;
                case 'reject':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can reject.'); }
                    AgentPro::reject((int) $_POST['app'], $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Rejected — the applicant was told why on WhatsApp.'];
                    break;
                case 'pay':
                    if (!Auth::can('commissions.pay')) { throw new RuntimeException('Your role cannot pay out.'); }
                    $lid = AgentPro::payWithdrawal((int) $_POST['req'], $me, (string) ($_POST['ref'] ?? ''), (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', '✓ Paid. Voucher ' . AgentWallet::voucherFor($lid) . ' — the member was told on WhatsApp.'];
                    break;
                case 'decline_wd':
                    if (!Auth::can('commissions.pay')) { throw new RuntimeException('Your role cannot decide payouts.'); }
                    AgentPro::declineWithdrawal((int) $_POST['req'], $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Declined — the member was told why.'];
                    break;
                case 'confirm_dep':
                case 'decline_dep':
                    if (!Auth::can('commissions.pay')) { throw new RuntimeException('Your role cannot confirm payments.'); }
                    AgentPro::decideDeposit((int) $_POST['dep'], $act === 'confirm_dep' ? 'confirmed' : 'declined', $me, (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', $act === 'confirm_dep' ? '✓ Confirmed and written to the wallet.' : 'Declined — the member was told why.'];
                    break;
                case 'invite':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can invite.'); }
                    $newLink = AgentPro::createInvite((string) $_POST['role'], (string) ($_POST['designation'] ?? ''), $me, (int) ($_POST['days'] ?? 7), (string) ($_POST['note'] ?? ''));
                    $flash = ['ok', 'Invitation link ready — send it on WhatsApp.'];
                    break;
                case 'uninvite':
                    if (!Auth::can('staff.manage')) { throw new RuntimeException('Only staff managers can do that.'); }
                    Database::update('ap_invites', ['expires_at' => date('Y-m-d H:i:s')], 'id = :i', ['i' => (int) $_POST['id']]);
                    $flash = ['ok', 'Invitation cancelled.'];
                    break;
            }
        } catch (RuntimeException $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$counts = AgentPro::counts();
$roster = AgentPro::roster();
$e = static fn($v): string => Security::e((string) $v);

admin_header('Team & ID Cards', 'team.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head('Join applications, ID cards, visiting cards and wallets — one place.', [],
    '<a class="btn ghost sm" href="/join.php" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg> Join form</a>'
    . '<a class="btn soft sm" href="/admin/ai-local.php"><svg class="a-ic"><use href="#a-msg"/></svg> WhatsApp & AI</a>');
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
$tabLabel = ['approvals' => ['Approvals', $counts['applications']], 'agents' => ['Agents', 0], 'staff' => ['Staff', 0],
             'money' => ['Money', $counts['withdrawals'] + $counts['deposits']], 'cards' => ['Print cards', 0], 'invites' => ['Invites', 0]];
?>
<div class="ap-a">
<nav class="tp-tabs" aria-label="Team sections">
  <?php foreach ($tabLabel as $k => [$label, $n]): ?>
    <a href="?tab=<?= $e($k) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= $e($label) ?><?= $n > 0 ? '<span class="c">' . (int) $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'approvals'):
    $apps = AgentPro::applications('submitted');
    if ($apps === []) {
        echo admin_empty('No applications waiting', 'New agents apply at /join.php — share the link on WhatsApp.', '✅',
            '<a class="btn wa sm" href="' . $e(whatsappLink('', 'Become an S Hari Global agent: ' . appUrl('join.php'))) . '" target="_blank" rel="noopener">Share the join link</a>');
    }
    foreach ($apps as $a):
        $read = jsonColumn($a['readout'] ?? null);
        $readF = [];
        $readS = [];
        foreach (['front', 'back', 'ai_front', 'ai_back'] as $slot) {
            foreach ((array) ($read[$slot]['fields'] ?? []) as $k => $v) {
                if (!isset($readF[$k])) {
                    $readF[$k] = (string) $v;
                    $readS[$k] = (string) ($read[$slot]['sources'][$k] ?? (str_starts_with($slot, 'ai') ? 'ai' : 'ocr'));
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
        $img = static fn(string $f, string $label, bool $doc = false): string =>
            '<a class="' . ($doc ? 'doc' : '') . '" href="/admin/team-doc.php?app=' . (int) $a['id'] . '&amp;f=' . $f . '" target="_blank" rel="noopener"><img loading="lazy" alt="" src="/admin/team-doc.php?app=' . (int) $a['id'] . '&amp;f=' . $f . '"><span>' . $label . '</span></a>';
        $isOrg = $a['kind'] === 'agent' && $a['agent_kind'] === 'org';
        ?>
  <article class="tp-app">
    <div class="tp-app-h">
      <b><?= $e($isOrg ? $a['org_name'] : $a['full_name']) ?></b>
      <span class="ref"><?= $e($a['ref']) ?></span>
      <span class="tp-chip blue"><?= $e($a['kind'] === 'staff' ? ucfirst((string) $a['role_wanted']) . ' (invite)' : ($isOrg ? 'Agency' : 'Agent')) ?></span>
      <?php if (!empty($a['wa_verified_at'])): ?><span class="tp-chip ok">WhatsApp ✓ <?= $e($a['wa_verified_via']) ?></span><?php endif; ?>
      <span class="ph-sub" style="margin-left:auto"><?= $e(timeAgo((string) $a['submitted_at'])) ?></span>
    </div>
    <div class="tp-app-b">
      <div>
        <div class="tp-photos">
          <?= !empty($a['selfie']) ? $img('selfie', 'Selfie') : '' ?>
          <?= !empty($a['doc_front']) ? $img('front', 'ID front', true) : '' ?>
          <?= !empty($a['doc_back']) ? $img('back', 'ID back', true) : '' ?>
          <?= !empty($a['thumb']) ? $img('thumb', 'Thumb', true) : '' ?>
          <?php foreach (jsonColumn($a['extras'] ?? null) as $i => $x): ?>
            <a class="doc" href="/admin/team-doc.php?app=<?= (int) $a['id'] ?>&amp;f=extra&amp;i=<?= (int) $i ?>" target="_blank" rel="noopener" style="display:flex;align-items:center;justify-content:center;font-weight:700;color:var(--ink-2)">📎 <?= $e($x['label'] ?? 'Doc') ?></a>
          <?php endforeach; ?>
        </div>
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
        <?php if (!empty($a['email'])): ?><dt>Email</dt><dd><?= $e($a['email']) ?></dd><?php endif; ?>
      </dl>
    </div>
    <?php if (Auth::can('staff.manage')): ?>
    <div class="tp-actions">
      <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="app" value="<?= (int) $a['id'] ?>">
        <button class="tp-approve" type="submit">✓ Approve</button></form>
      <form method="post" style="display:flex;gap:6px;flex:1;min-width:240px;margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="app" value="<?= (int) $a['id'] ?>">
        <input type="text" name="note" placeholder="Why not / what to fix (sent on WhatsApp)" required style="flex:1;height:48px;padding:0 12px;border:1px solid var(--line-2);border-radius:12px;background:var(--card);color:var(--ink);font:inherit">
        <button class="btn ghost danger" type="submit">✕ Reject</button></form>
      <a class="btn wa" href="<?= $e(whatsappLink(($a['country'] === 'NP' ? '977' : '91') . $a['phone'], 'Namaste ' . $a['full_name'] . ', about your application ' . $a['ref'] . ' —')) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
    </div>
    <?php endif; ?>
  </article>
    <?php endforeach; ?>

<?php elseif ($tab === 'agents' || $tab === 'staff'):
    $wantAgents = $tab === 'agents';
    $kycF = (string) ($_GET['kyc'] ?? '');
    $people = array_values(array_filter($roster, static fn(array $r): bool => $r['isAgent'] === $wantAgents && ($kycF === '' || $r['kyc'] === $kycF)));
    ?>
  <div class="tp-bar">
    <input type="search" id="tpq" placeholder="Search name, code, branch…" aria-label="Search">
    <?php if ($wantAgents): ?>
      <a class="btn ghost sm" href="?tab=agents">All (<?= count(array_filter($roster, static fn($r) => $r['isAgent'])) ?>)</a>
      <a class="btn ghost sm" href="?tab=agents&amp;kyc=submitted">KYC to check</a>
    <?php endif; ?>
  </div>
  <?php if ($people === []) { echo admin_empty('Nobody here yet', $wantAgents ? 'Share /join.php — approved agents appear here.' : 'Invite office staff from the Invites tab.', '👥'); } ?>
  <div class="tp-people" id="tpList">
    <?php foreach ($people as $p):
        $chips = [];
        $chips[] = $p['active'] ? ($p['pendingRef'] !== '' ? ['warn', 'Applied ' . $p['pendingRef']] : ['ok', 'Active']) : ($p['pendingRef'] !== '' ? ['warn', 'Applied ' . $p['pendingRef']] : ['bad', 'Inactive']);
        if ($p['suspended']) { $chips[] = ['bad', 'Suspended']; }
        $chips[] = [$p['kyc'] === 'verified' ? 'ok' : ($p['kyc'] === 'submitted' ? 'warn' : ''), 'KYC ' . $p['kyc']];
        if ($p['card'] === 'revoked') { $chips[] = ['bad', 'Card revoked']; }
        if ($p['isAgent']) { $chips[] = ['blue', $p['kind'] === 'org' ? 'Agency' : 'Person']; } else { $chips[] = ['blue', ucfirst($p['role'])]; }
        ?>
      <a class="tp-person" href="/admin/team-member.php?id=<?= (int) $p['id'] ?>" data-q="<?= $e(mb_strtolower($p['name'] . ' ' . $p['idNo'] . ' ' . $p['branch'] . ' ' . $p['phone'])) ?>">
        <span class="tp-ph"><?= $p['photoUrl'] !== '' ? '<img loading="lazy" alt="" src="' . $e($p['photoUrl']) . '">' : $e(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
        <span style="min-width:0;flex:1"><b><?= $e($p['name']) ?></b><span class="code"><?= $e($p['idNo'] !== '' ? $p['idNo'] : '—') ?></span>
          <small> · <?= $e($p['designation']) ?><?= $p['branch'] !== '' ? ' · ' . $e($p['branch']) : '' ?></small>
          <span class="tp-chips"><?php foreach ($chips as [$c, $t]): ?><span class="tp-chip <?= $e($c) ?>"><?= $e($t) ?></span><?php endforeach; ?></span></span>
      </a>
    <?php endforeach; ?>
  </div>
  <script>(function(){var q=document.getElementById('tpq');if(!q)return;q.addEventListener('input',function(){var v=q.value.toLowerCase().trim();document.querySelectorAll('#tpList .tp-person').forEach(function(a){a.style.display=!v||a.dataset.q.indexOf(v)>-1?'':'none';});});})();</script>

<?php elseif ($tab === 'money'):
    $wds = AgentWallet::openPayoutRequests();
    $deps = AgentPro::openDeposits();
    $canPay = Auth::can('commissions.pay');
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
            <input type="text" name="ref" placeholder="UPI / bank ref"><button class="btn ok sm" name="action" value="pay" type="submit">✓ Paid</button>
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

<?php elseif ($tab === 'cards'):
    $active = array_values(array_filter($roster, static fn(array $r): bool => $r['active']));
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
      <p class="ph-sub" style="margin-top:0">Agents can simply use <a href="/join.php" target="_blank">/join.php</a>. Office staff need a link — the role is fixed by it.</p>
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
            <button class="btn ghost sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= $e(appUrl('join.php?invite=' . $iv['token'])) ?>');this.textContent='Copied'">Copy link</button>
            <button class="btn ghost danger sm" name="action" value="uninvite" type="submit">Cancel</button></form></div>
      <?php endforeach; ?>
    </div></section>
  </div>
<?php endif; ?>
</div>
<?php admin_footer();
