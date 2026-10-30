<?php
/**
 * admin/team-member.php?id=<admin id> — one member: their ID card and
 * visiting card (live), what the cards print, photo, payout details,
 * WhatsApp numbers linked to their code, fingerprint sign-ins, and the
 * card controls (re-issue = every old printed card scans REVOKED, suspend,
 * send the card on WhatsApp). Money and sales stay on the existing
 * Agent 360 / Agent Panel pages, linked from here.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
$admin = admin_boot();
$id = (int) ($_GET['id'] ?? 0);
$self = $id === (int) $admin['id'];
if (!$self && !(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
$canEdit = Auth::can('staff.manage') || $self;
$m = AgentPro::available() ? AgentPro::member($id) : null;
if ($m === null) {
    http_response_code(404);
    exit('No such member.');
}
$e = static fn($v): string => Security::e((string) $v);
$flash = null;

if (Security::isPost()) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Your role cannot change this member.'];
    } else {
        try {
            switch ((string) ($_POST['action'] ?? '')) {
                case 'card':
                    AgentPro::saveCard($id, array_intersect_key($_POST, array_flip(['designation', 'department', 'vc_whatsapp', 'vc_phone', 'vc_email',
                        'vc_tagline', 'vc_greeting', 'vc_theme', 'dob', 'gender', 'blood_group', 'guardian', 'emergency_name', 'emergency_phone', 'card_valid_until', 'lang'])), (int) $admin['id']);
                    $flash = ['ok', 'Saved — both cards show it now.'];
                    break;
                case 'bank':
                    AgentPro::saveBank($id, $_POST, (int) $admin['id']);
                    $flash = ['ok', 'Payout details saved.'];
                    break;
                case 'photo':
                    if (empty($_FILES['photo'])) { throw new RuntimeException('Choose a photo.'); }
                    AgentPro::setPhoto($id, $_FILES['photo'], (int) $admin['id']);
                    $flash = ['ok', 'Photo changed — the ID card uses it now.'];
                    break;
                case 'reissue':
                    AgentPro::reissueCard($id, (int) $admin['id']);
                    $flash = ['ok', 'Card re-issued. Old printed cards now scan as REVOKED — print the new one.'];
                    break;
                case 'status':
                    AgentPro::setCardStatus($id, (string) $_POST['status'], (int) $admin['id']);
                    $flash = ['ok', 'Card is now ' . $_POST['status'] . '.'];
                    break;
                case 'send':
                    require_once INCLUDE_PATH . '/notify.php';
                    $side = in_array($_POST['side'] ?? '', ApCard::SIDES, true) ? (string) $_POST['side'] : 'id-front';
                    $ok = Notify::whatsapp((string) $m['phone'], '🪪 ' . $m['name'] . ' · ' . ($m['idNo'] ?: '') . "\n" . appUrl('me/'),
                        ApCard::signedUrl($id, $side, 86400 * 3), $m['countryCode'] === '977' ? 'NP' : 'IN');
                    $flash = $ok !== false ? ['ok', 'Sent on WhatsApp.'] : ['bad', 'WhatsApp could not send right now (see Message Log).'];
                    break;
                case 'welcome':
                    $flash = AgentPro::sendWelcome($id) ? ['ok', 'Welcome message with the ID card sent.'] : ['bad', 'WhatsApp could not send right now.'];
                    break;
                case 'unlink':
                    Database::run('UPDATE ap_wa_links SET revoked_at = NOW() WHERE phone = :p AND admin_id = :a', ['p' => (string) $_POST['phone'], 'a' => $id]);
                    Logger::audit('agentpro.wa_unlinked', 'admin', (string) $id, null, ['wa' => (string) $_POST['phone']], 'WhatsApp link removed by admin #' . $admin['id']);
                    $flash = ['ok', 'That WhatsApp number can no longer act for this member.'];
                    break;
                case 'pk_remove':
                    Database::delete('ap_passkeys', 'id = :i AND admin_id = :a', ['i' => (int) $_POST['pk'], 'a' => $id]);
                    $flash = ['ok', 'Fingerprint sign-in removed.'];
                    break;
            }
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
        $m = AgentPro::member($id) ?? $m;
    }
}

$cards = [];
foreach (ApCard::SIDES as $s) {
    $cards[$s] = '/api/agentpro-card.php?m=' . $id . '&s=' . $s . '&v=' . $m['photoVer'] . '&t=' . time();
}
$links = Database::fetchAll('SELECT * FROM ap_wa_links WHERE admin_id = :a AND revoked_at IS NULL ORDER BY verified_at DESC', ['a' => $id]);
$pks = Database::fetchAll('SELECT id, label, created_at, last_used_at FROM ap_passkeys WHERE admin_id = :a ORDER BY id', ['a' => $id]);
$bal = AgentWallet::balances($id);
$fullAcct = Auth::can('commissions.pay') ? AgentPro::bankAccount($id) : '';
$statusChip = ['valid' => 'ok', 'suspended' => 'warn', 'expired' => 'warn', 'revoked' => 'bad', 'inactive' => 'bad'][$m['status']] ?? '';

admin_header($m['name'], 'team.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head($m['designation'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : ''), ['Team & ID Cards' => '/admin/team.php?tab=' . ($m['isAgent'] ? 'agents' : 'staff'), $m['name'] => ''],
    ($m['isAgent'] ? '<a class="btn soft sm" href="/admin/agent-360.php?agent=' . $id . '">Agent 360</a>' : '')
    . '<a class="btn wa sm" href="' . $e(whatsappLink($m['wa'], 'Namaste ' . $m['name'] . ',')) . '" target="_blank" rel="noopener">💬 WhatsApp</a>');
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
?>
<div class="ap-a">
<div class="mp-top">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-id-card"/></svg> ID card <span class="tp-chip <?= $e($statusChip) ?>" style="margin-left:auto"><?= $e(strtoupper($m['status'])) ?></span></h2>
    <div class="panel-body">
      <div class="mp-cards"><a href="<?= $e($cards['id-front']) ?>" target="_blank"><img alt="ID front" src="<?= $e($cards['id-front']) ?>"></a>
        <a href="<?= $e($cards['id-back']) ?>" target="_blank"><img alt="ID back" src="<?= $e($cards['id-back']) ?>"></a></div>
      <p class="ph-sub">Issued <?= $e($m['cardIssued'] !== '' ? formatDate(substr($m['cardIssued'], 0, 10), 'j M Y') : '—') ?> · valid till <?= $e($m['validUntil'] !== '' ? formatDate($m['validUntil'], 'j M Y') : '—') ?></p>
      <div class="tp-chips" style="gap:6px">
        <a class="btn ghost sm" href="/admin/team-print.php?ids[]=<?= $id ?>&amp;what=all" target="_blank" rel="noopener">🖨 Print</a>
        <a class="btn ghost sm" href="/id.php?c=<?= $e(rawurlencode($m['cardToken'])) ?>" target="_blank" rel="noopener">Verify page</a>
        <?php if ($canEdit): ?>
        <form method="post" style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="send"><input type="hidden" name="side" value="id-front"><button class="btn wa sm">Send on WhatsApp</button></form>
        <form method="post" style="display:inline" onsubmit="return confirm('Re-issue? Every card printed so far will scan as REVOKED.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="reissue"><button class="btn ghost sm">Re-issue</button></form>
        <form method="post" style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="status">
          <?php if ($m['cardStatus'] === 'active'): ?><button class="btn ghost danger sm" name="status" value="suspended">Suspend card</button>
          <?php else: ?><button class="btn ok sm" name="status" value="active">Activate card</button><?php endif; ?></form>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <section class="panel"><h2><svg class="a-ic"><use href="#a-card"/></svg> Visiting card</h2>
    <div class="panel-body">
      <div class="mp-vc"><img alt="Visiting card front" src="<?= $e($cards['vc-front']) ?>"><img alt="Visiting card back" src="<?= $e($cards['vc-back']) ?>"></div>
      <p class="ph-sub">QR opens WhatsApp <b class="ap-mono"><?= $e($m['waDisplay']) ?></b> · share link <a href="/c.php?u=<?= $e(rawurlencode($m['cardToken'])) ?>" target="_blank">/c.php</a></p>
    </div>
  </section>
</div>

<?php if ($canEdit): ?>
<section class="panel"><h2><svg class="a-ic"><use href="#a-edit"/></svg> What the cards print</h2><div class="panel-body">
  <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="card">
    <label>Designation (under the name)<input name="designation" maxlength="80" value="<?= $e($m['designation']) ?>"></label>
    <label>Department<input name="department" maxlength="80" value="<?= $e($m['department']) ?>"></label>
    <label>WhatsApp number on the visiting card<input name="vc_whatsapp" value="<?= $e($m['waDisplay']) ?>" placeholder="+91 98765 43210"></label>
    <label>Second phone<input name="vc_phone" value="<?= $e($m['call'] !== $m['wa'] ? $m['callDisplay'] : '') ?>"></label>
    <label>Email on the card<input name="vc_email" type="email" value="<?= $e($m['email']) ?>"></label>
    <label>Style<select name="vc_theme"><?php foreach (['royal' => 'Royal (navy & gold)', 'gold' => 'Gold (ivory)', 'night' => 'Night (black & gold)'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['theme'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="full">Line under the company name<input name="vc_tagline" maxlength="120" value="<?= $e($m['tagline']) ?>"></label>
    <label class="full">WhatsApp message ready when the QR is scanned<input name="vc_greeting" maxlength="160" value="<?= $e($m['greeting']) ?>"></label>
    <label>Date of birth<input type="date" name="dob" value="<?= $e($m['dob']) ?>"></label>
    <label>Gender<select name="gender"><option value=""></option><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option<?= $m['gender'] === $g ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></label>
    <label>Blood group<select name="blood_group"><option value=""></option><?php foreach (AgentPro::BLOOD_GROUPS as $b): ?><option<?= $m['bloodGroup'] === $b ? ' selected' : '' ?>><?= $b ?></option><?php endforeach; ?></select></label>
    <label>Father / husband<input name="guardian" value="<?= $e($m['guardian']) ?>"></label>
    <label>Emergency contact name<input name="emergency_name" value="<?= $e($m['emergencyName']) ?>"></label>
    <label>Emergency phone<input name="emergency_phone" value="<?= $e($m['emergencyPhone']) ?>"></label>
    <label>Card valid till<input type="date" name="card_valid_until" value="<?= $e($m['validUntil']) ?>"></label>
    <label>WhatsApp language<select name="lang"><?php foreach (['ne' => 'नेपाली', 'hi' => 'हिंदी', 'en' => 'English'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['lang'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <div class="full"><button class="btn" type="submit">Save</button></div>
  </form>
</div></section>
<?php endif; ?>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-camera"/></svg> Photo</h2><div class="panel-body" style="display:flex;gap:14px;align-items:center">
    <span class="tp-ph" style="width:90px;height:108px"><?= $m['photoUrl'] !== '' ? '<img alt="" src="' . $e($m['photoUrl']) . '">' : '?' ?></span>
    <?php if ($canEdit): ?><form method="post" enctype="multipart/form-data"><?= Security::csrfField() ?><input type="hidden" name="action" value="photo">
      <input type="file" name="photo" accept="image/*" required> <button class="btn sm" type="submit">Upload</button></form><?php endif; ?>
  </div></section>
  <section class="panel"><h2><svg class="a-ic"><use href="#a-wallet"/></svg> Wallet</h2><div class="panel-body">
    <div class="ai-hw"><div><small>Commission due</small><b><?= $e(inr($bal['commission'])) ?></b></div><div><small>Cash held</small><b><?= $e(inr($bal['cash'])) ?></b></div></div>
    <?php if ($m['isAgent']): ?><p><a class="btn soft sm" href="/admin/agent.php?agent=<?= $id ?>">Open the agent wallet</a></p><?php endif; ?>
  </div></section>
</div>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-banknote"/></svg> Payout details</h2><div class="panel-body">
    <?php if ($canEdit): ?>
    <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="bank">
      <label>UPI ID<input name="upi_id" value="<?= $e($m['bank']['upi']) ?>"></label>
      <label>Account holder<input name="bank_holder" value="<?= $e($m['bank']['holder']) ?>"></label>
      <label>Account number<input name="bank_account" placeholder="<?= $e($fullAcct !== '' ? $fullAcct : ($m['bank']['last4'] ? '••••' . $m['bank']['last4'] : '')) ?>"></label>
      <label>IFSC<input name="bank_ifsc" value="<?= $e($m['bank']['ifsc']) ?>"></label>
      <label>Bank<input name="bank_name" value="<?= $e($m['bank']['name']) ?>"></label>
      <label>eSewa<input name="esewa_id" value="<?= $e($m['bank']['esewa']) ?>"></label>
      <div class="full"><button class="btn sm" type="submit">Save</button></div>
    </form>
    <?php else: ?><p><?= $e($m['bank']['upi'] ?: '—') ?></p><?php endif; ?>
  </div></section>
  <section class="panel"><h2><svg class="a-ic"><use href="#a-whatsapp"/></svg> WhatsApp desk & sign-in</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:8px">
    <small class="ph-sub" style="margin:0">Numbers that act for <?= $e($m['idNo'] ?: $m['name']) ?> on WhatsApp (linked with "LINK <?= $e($m['idNo']) ?>" + OTP). The registered mobile <?= $e($m['callDisplay']) ?> always works.</small>
    <?php foreach ($links as $l): ?>
      <div class="tp-req"><span class="who"><b class="ap-mono">+<?= $e($l['phone']) ?></b><small>linked <?= $e(timeAgo((string) $l['verified_at'])) ?><?= $l['last_seen_at'] ? ' · last used ' . $e(timeAgo((string) $l['last_seen_at'])) : '' ?></small></span>
        <?php if ($canEdit): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="phone" value="<?= $e($l['phone']) ?>"><button class="btn ghost danger sm">Unlink</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php foreach ($pks as $p): ?>
      <div class="tp-req"><span class="who"><b>🔐 <?= $e($p['label'] ?: 'Passkey') ?></b><small>fingerprint / face sign-in · added <?= $e(timeAgo((string) $p['created_at'])) ?></small></span>
        <?php if ($canEdit): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="pk_remove"><input type="hidden" name="pk" value="<?= (int) $p['id'] ?>"><button class="btn ghost danger sm">Remove</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($canEdit): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="welcome"><button class="btn soft sm">Re-send welcome + ID card</button></form><?php endif; ?>
  </div></section>
</div>
</div>
<?php admin_footer();
