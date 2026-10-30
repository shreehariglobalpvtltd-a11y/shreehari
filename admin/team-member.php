<?php
/**
 * admin/team-member.php?id=<admin id> — one member: their ID card and
 * visiting card (live), what the cards print, photo, payout details,
 * WhatsApp numbers linked to their code, fingerprint sign-ins, and the
 * card controls (re-issue = every old printed card scans REVOKED, send the
 * cards on WhatsApp).
 *
 * 30 Oct 2026 (owner: "admin le sabai control garna paune"):
 *   - the filled digital form (PDF) and the card PDF, for the office
 *   - agent code: set a number or take the next free one (super / manager);
 *     staff number for office staff
 *   - Suspend = card suspended AND login off (WhatsApp desk + /me/ stop);
 *     Archive / Restore; Delete permanently (super-admin, typed DELETE,
 *     refused when there is money or ticket history — archive instead)
 *   - nobody changes their own card status, validity, code or account here;
 *     on their own record a member may edit only what the visiting card
 *     prints, payout details and their own sign-ins
 *   - KYC documents only for a super-admin or a manager
 *
 * 30 Oct 2026, agentpro2:
 *   - a super-admin may set what THEIR OWN card prints (name, designation,
 *     department, photo) — the owner's ID card; code / status / validity of
 *     their own record still only by someone else
 *   - bus crew (driver / conductor): the driver-app account — link / make it,
 *     a new PIN on WhatsApp (shown once here too), mark a staff member as crew
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentpro-card.php';
require_once INCLUDE_PATH . '/teampurge.php';
require_once INCLUDE_PATH . '/agentpro-web.php';
$admin = admin_boot();
$id = (int) ($_GET['id'] ?? 0);
$me = (int) $admin['id'];
$self = $id === $me;
if (!$self && !(Auth::can('staff.manage') || Auth::can('commissions.view'))) {
    http_response_code(403);
    exit('You do not have permission to view this page.');
}
$m = AgentPro::available() ? AgentPro::member($id) : null;
if ($m === null) {
    http_response_code(404);
    exit('No such member.');
}
$role      = (string) ($admin['role'] ?? '');
$isSuper   = Auth::isSuperadmin();
$isMgr     = $role === 'manager';
$targetSuper = $m['role'] === 'superadmin';
$canManage = !$self && Auth::can('staff.manage');                     // the office edits someone else
$canCode   = !$self && ($isSuper || $isMgr) && !$targetSuper;          // agent code / staff number
$canKyc    = $isSuper || $isMgr;                                       // KYC documents
$canStatus = !$self && !$targetSuper && (TeamPurge::mayManage($admin) || Auth::can('staff.manage'));
$canArch   = !$self && !$targetSuper && TeamPurge::mayManage($admin);
$canOwn    = $canManage || $self;                                      // visiting card text, payout, own sign-ins
// The office edits what prints on someone else's ID card - a super-admin's card only by a super-admin
// (as admin/team.php card_for_login).
$canOffice = $canManage && (!$targetSuper || $isSuper);
$canPrint  = $canOffice || ($self && $isSuper);                        // name / designation / photo on the card (the owner's own ID too)
$crew      = (string) ($m['crewRole'] ?? '');
$archived  = TeamPurge::archived($id);
// 31 Oct 2026 (owner: "purai details — code, name, number, photo, document — admin le change garna milos"):
// the full office form for this member (AgentPro::mayEditApproved is the same rule).
$canEditAll = ($isSuper || $isMgr) && (!$targetSuper || $isSuper);
$e = static fn($v): string => Security::e((string) $v);
$flash = null;

/** The member's filled form as a link they can open on the phone: their own 30-day link, else /me/ (signed in). */
$memberFormLink = static function () use ($id): string {
    require_once INCLUDE_PATH . '/agentpro-form.php';
    $app = ApForm::applicationFor($id);
    if ($app !== null && ApForm::applicantMayOpen($app) && ($u = ApForm::applicantUrl($app)) !== '') {
        return appUrl(ltrim($u, '/') . '&auto=pdf');
    }
    return appUrl('me/form.php?auto=pdf');
};

/** Fields only the office may change (they print on the ID card or decide its validity). */
$officeCardFields = ['designation', 'department', 'dob', 'gender', 'guardian', 'card_valid_until'];
$printCardFields  = ['designation', 'department', 'dob', 'gender', 'guardian'];   // a super-admin on their own card: not the validity
$ownCardFields    = ['vc_whatsapp', 'vc_phone', 'vc_email', 'vc_tagline', 'vc_greeting', 'vc_theme', 'blood_group', 'emergency_name', 'emergency_phone', 'lang'];

if (Security::isPost()) {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        try {
            $need = static function (bool $ok, string $why = 'Your role cannot change this here.'): void {
                if (!$ok) { throw new RuntimeException($why); }
            };
            switch ($act) {
                case 'card':
                    $need($canOwn);
                    $allowed = $canOffice ? array_merge($officeCardFields, $ownCardFields)
                        : ($canPrint ? array_merge($printCardFields, $ownCardFields) : $ownCardFields);
                    if ($canPrint && isset($_POST['full_name']) && trim((string) $_POST['full_name']) !== '') {
                        AgentPro::makeCardFor($id, ['full_name' => (string) $_POST['full_name']], $me);
                    }
                    AgentPro::saveCard($id, array_intersect_key($_POST, array_flip($allowed)), $me);
                    $flash = ['ok', 'Saved — both cards show it now.'];
                    break;
                case 'bank':
                    $need($canOwn);
                    AgentPro::saveBank($id, $_POST, $me);
                    $flash = ['ok', 'Payout details saved.'];
                    break;
                case 'photo':
                    $need($canPrint, 'The ID card photo is changed by the office.');
                    if (empty($_FILES['photo'])) { throw new RuntimeException('Choose a photo.'); }
                    AgentPro::setPhoto($id, $_FILES['photo'], $me);
                    $flash = ['ok', 'Photo changed — the ID card uses it now.'];
                    break;
                case 'reissue':
                    $need($canOffice, 'Only the office can re-issue a card (never their own; a super-admin\'s only by a super-admin).');
                    AgentPro::reissueCard($id, $me);
                    $flash = ['ok', 'Card re-issued. Old printed cards now scan as REVOKED — print the new one.'];
                    break;
                case 'suspend':
                case 'activate':
                    $need($canStatus, 'Your role cannot suspend or activate this member (nobody changes their own status; a super-admin is never suspended here).');
                    TeamPurge::suspend($id, $act === 'suspend', $admin, (string) ($_POST['reason'] ?? ''));
                    $flash = ['ok', $act === 'suspend' ? 'Suspended — the card reads SUSPENDED and the login (website, /me/, WhatsApp desk) is off.' : 'Active again — card and login are back on.'];
                    break;
                case 'archive':
                case 'restore':
                    $need($canArch, 'Only a super-admin or a manager can archive or restore (never their own account).');
                    TeamPurge::archive($id, $act === 'restore', $admin, (string) ($_POST['reason'] ?? ''));
                    $flash = ['ok', $act === 'archive' ? '🗄 Archived — login off, card suspended, off the website. Every record is kept.' : '↩ Restored — the login, card and website listing are back as they were.'];
                    break;
                case 'purge':
                    $need($isSuper && !$self, 'Only a super-admin can delete permanently (never their own login).');
                    $res = TeamPurge::purge('admin', $id, $admin, (string) ($_POST['confirm_delete'] ?? ''), (string) ($_POST['purge_reason'] ?? ''));
                    Response::redirect('admin/team.php?tab=' . ($m['isAgent'] ? 'agents' : 'staff') . '&purged=' . rawurlencode($m['name'] . ' (' . $res['ref'] . ')'));
                    break;
                case 'purge_all':
                    // 31 Oct 2026 (owner: "agent lai permanently delete — sab kura admin ko haat ma"): with the history
                    // (tickets to the office, or old tickets deleted with the customers kept) — TeamPurge::purgeWithHistory.
                    $need($isSuper && !$self, 'Only a super-admin can delete permanently (never their own login).');
                    @set_time_limit(300);
                    $res = TeamPurge::purgeWithHistory('admin', $id, $admin, (string) ($_POST['confirm'] ?? ''), (string) ($_POST['reason'] ?? ''), (string) ($_POST['tickets'] ?? 'office'));
                    $_SESSION['tp_purge_result'] = TeamPurge::resultText($res);
                    Response::redirect('admin/team.php?tab=' . ($m['isAgent'] ? 'agents' : 'staff') . '&purged=' . rawurlencode($m['name'] . ' (' . $res['ref'] . ')'));
                    break;
                case 'edit_form':
                    // 31 Oct 2026: every detail (name, mobile, address, ID, photo, documents, signature, bank) in the
                    // same office form, saved live — an older member without a form gets an office record first.
                    $need($canEditAll, 'A super-admin or a manager edits every detail (a super-admin\'s only by a super-admin).');
                    $rec = AgentPro::officeRecordFor($id, $me);
                    Response::redirect('join.php?office=1&d=' . rawurlencode((string) $rec['draft_key']));
                    break;
                case 'add_doc':
                    // 31 Oct 2026 (owner: "document herne, upload garne option chhaina"): straight onto the member's form
                    $need($canKyc && (!$targetSuper || $isSuper), 'Documents are added by a super-admin or a manager.');
                    if (empty($_FILES['doc'])) { throw new RuntimeException('Choose a photo or PDF.'); }
                    AgentPro::memberAddDoc($id, $_FILES['doc'], (string) ($_POST['label'] ?? ''), $me);
                    $flash = ['ok', '📎 Document added to the form.'];
                    break;
                case 'web_show':
                    // 31 Oct 2026 (owner: "form bharera verified admin le dinchha — live home page ma photo dekhiyos")
                    $need($canOffice || ($self && $isSuper), 'The office decides who shows on the website.');
                    $want = (string) ($_POST['web'] ?? 'auto');
                    ApWeb::set($id, $want === '1' ? 1 : ($want === '0' ? 0 : null), $me);
                    $flash = ['ok', '🌐 ' . ApWeb::status($id)['reason']];
                    break;
                case 'send_form':
                    $need($canOwn);
                    require_once INCLUDE_PATH . '/notify.php';
                    $ok = Notify::whatsapp((string) $m['phone'], '📄 ' . $m['name'] . ' · ' . ($m['idNo'] ?: '') . "\nभरिएको फारम (PDF) · Filled form:\n" . $memberFormLink(),
                        null, $m['countryCode'] === '977' ? 'NP' : 'IN');
                    Logger::audit('agentpro.form_sent', 'admin', (string) $id, null, ['sent' => $ok !== false], 'Form link sent on WhatsApp by admin #' . $me);
                    $flash = $ok !== false ? ['ok', 'Form link sent on WhatsApp.'] : ['bad', 'WhatsApp could not send right now — see Message Log.'];
                    break;
                case 'set_code':
                    $need($canCode && $m['isAgent'], 'Only a super-admin or a manager sets agent codes (never their own).');
                    $want = trim((string) ($_POST['code'] ?? ''));
                    if (isset($_POST['auto']) || $want === '') {
                        $n = AgentWallet::nextFreeAgentCode(AgentWallet::agentKindFor($id));
                        if ($n === null) { throw new RuntimeException('Every agent number is taken.'); }
                    } else {
                        // "27", "0027", "SHG-0027", "shg 27" → 27 (setAgentCode checks the range and that nobody else holds it)
                        if (preg_match('/^(?:SHG)?\D*(\d{1,4})$/i', $want, $mm) !== 1) { throw new RuntimeException('Type a number like 27 or SHG-0027.'); }
                        $n = (int) $mm[1];
                    }
                    AgentWallet::setAgentCode($id, $n, $me);
                    $flash = ['ok', 'Agent code is now ' . AgentWallet::agentCodeLabel($id) . '. Re-print the cards (the old card still verifies until you re-issue).'];
                    break;
                case 'set_staff_no':
                    $need($canCode && !$m['isAgent'], 'Only a super-admin or a manager sets staff numbers (never their own).');
                    $want = (int) preg_replace('/\D/', '', (string) ($_POST['staff_no'] ?? ''));
                    if ($want < 1 || $want > 99999) { throw new RuntimeException('Type a staff number between 1 and 99999.'); }
                    if (Database::exists('SELECT 1 FROM ap_members WHERE staff_no = :n AND admin_id <> :a', ['n' => $want, 'a' => $id])) {
                        throw new RuntimeException('SHG-S-' . str_pad((string) $want, 4, '0', STR_PAD_LEFT) . ' is already used by someone else.');
                    }
                    $old = (int) Database::scalar('SELECT staff_no FROM ap_members WHERE admin_id = :a', ['a' => $id], 0);
                    try {
                        Database::update('ap_members', ['staff_no' => $want], 'admin_id = :a', ['a' => $id]);
                    } catch (Throwable $ex) {
                        throw new RuntimeException('That staff number was just taken — choose another.');
                    }
                    Logger::audit('agentpro.staff_no_set', 'admin', (string) $id, ['staff_no' => $old ?: null], ['staff_no' => $want], 'Staff number set by admin #' . $me);
                    $flash = ['ok', 'Staff number is now SHG-S-' . str_pad((string) $want, 4, '0', STR_PAD_LEFT) . '.'];
                    break;
                case 'send':
                case 'send_all':
                    $need($canOwn);
                    require_once INCLUDE_PATH . '/notify.php';
                    $sides = $act === 'send_all' ? ApCard::SIDES
                        : [in_array($_POST['side'] ?? '', ApCard::SIDES, true) ? (string) $_POST['side'] : 'id-front'];
                    $label = ['id-front' => 'ID card · front', 'id-back' => 'ID card · back', 'vc-front' => 'Visiting card · front', 'vc-back' => 'Visiting card · back'];
                    $okN = 0;
                    foreach ($sides as $i => $side) {
                        $text = $i === 0 ? '🪪 ' . $m['name'] . ' · ' . ($m['idNo'] ?: '') . "\n" . $label[$side] . "\n" . appUrl('me/')
                            . ($act === 'send_all' ? "\n📄 Form (PDF): " . $memberFormLink() : '') : $label[$side];
                        $ok = Notify::whatsapp((string) $m['phone'], $text, ApCard::signedUrl($id, $side, 86400 * 3), $m['countryCode'] === '977' ? 'NP' : 'IN');
                        if ($ok !== false) { $okN++; }
                    }
                    Logger::audit('agentpro.cards_sent', 'admin', (string) $id, null, ['sides' => $sides, 'sent' => $okN], 'Cards sent on WhatsApp by admin #' . $me);
                    $flash = $okN === count($sides) ? ['ok', 'Sent on WhatsApp (' . $okN . ' image' . ($okN > 1 ? 's' : '') . ').']
                        : ['bad', 'WhatsApp sent ' . $okN . ' of ' . count($sides) . ' — see Message Log.'];
                    break;
                case 'crew_set':
                    $need($canManage && !$m['isAgent'] && !$targetSuper, 'Only the office marks a staff member as bus crew.');
                    if (!AgentPro::memberHas('crew_role')) { throw new RuntimeException('Run database/upgrade-2026-10-30-agentpro2.sql first.'); }
                    $want = (string) ($_POST['crew'] ?? '');
                    AgentPro::setCrewRole($id, in_array($want, AgentPro::CREW_ROLES, true) ? $want : '', $me);
                    $flash = ['ok', $want !== '' ? '🚌 Marked as ' . $want . '. Now make the driver-app account below.' : 'No longer bus crew (the driver-app account is kept — switch it off in Drivers).'];
                    break;
                case 'driver_link':
                case 'driver_pin':
                    $need($canManage, 'Only the office gives the driver app.');
                    $res = AgentPro::linkDriver($id, $me, true, $act === 'driver_pin');
                    $sent = $res['pin'] !== null && AgentPro::sendDriverPin($id, $res['pin']);
                    $flash = ['ok', '🚌 Driver app ' . ($res['created'] ? 'account made' : 'ready')
                        . ($res['pin'] !== null ? ' — PIN ' . $res['pin'] . ($sent ? ' (sent on WhatsApp)' : ' (WhatsApp did not send — tell them)') . '. Phones signed in before are signed out.' : ' — their PIN stays as it was.')];
                    break;
                case 'welcome':
                    $need($canManage);
                    $flash = AgentPro::sendWelcome($id) ? ['ok', 'Welcome message with the ID card sent.'] : ['bad', 'WhatsApp could not send right now.'];
                    break;
                case 'unlink':
                    $need($canOwn);
                    Database::run('UPDATE ap_wa_links SET revoked_at = NOW() WHERE phone = :p AND admin_id = :a', ['p' => (string) $_POST['phone'], 'a' => $id]);
                    Logger::audit('agentpro.wa_unlinked', 'admin', (string) $id, null, ['wa' => (string) $_POST['phone']], 'WhatsApp link removed by admin #' . $me);
                    $flash = ['ok', 'That WhatsApp number can no longer act for this member.'];
                    break;
                case 'pk_remove':
                    $need($canOwn);
                    Database::delete('ap_passkeys', 'id = :i AND admin_id = :a', ['i' => (int) $_POST['pk'], 'a' => $id]);
                    Logger::audit('agentpro.passkey_removed', 'admin', (string) $id, null, ['passkey' => (int) $_POST['pk']], 'Passkey removed by admin #' . $me);
                    $flash = ['ok', 'Fingerprint sign-in removed.'];
                    break;
            }
        } catch (RuntimeException $ex) {
            $flash = ['bad', $ex->getMessage()];
        }
        $m = AgentPro::member($id) ?? $m;
        $crew = (string) ($m['crewRole'] ?? '');
        $archived = TeamPurge::archived($id);
    }
}

$cards = [];
foreach (ApCard::SIDES as $s) {
    $cards[$s] = '/api/agentpro-card.php?m=' . $id . '&s=' . $s . '&v=' . $m['photoVer'] . '&t=' . time();
}
$links = Database::fetchAll('SELECT * FROM ap_wa_links WHERE admin_id = :a AND revoked_at IS NULL ORDER BY verified_at DESC', ['a' => $id]);
$pks = Database::fetchAll('SELECT id, label, created_at, last_used_at FROM ap_passkeys WHERE admin_id = :a ORDER BY id', ['a' => $id]);
$bal = AgentWallet::balances($id);
$fullAcct = ($isSuper || Auth::can('commissions.pay')) ? AgentPro::bankAccount($id) : '';
$statusChip = ['valid' => 'ok', 'suspended' => 'warn', 'expired' => 'warn', 'revoked' => 'bad', 'inactive' => 'bad'][$m['status']] ?? '';
$app = Database::fetch('SELECT * FROM ap_applications WHERE admin_id = :a ORDER BY id DESC LIMIT 1', ['a' => $id]);
$recent = AgentWallet::entries($id, 8);
$formUrl = '/admin/team-form.php?admin=' . $id;
$cardPdf = '/api/agentpro-card.php?m=' . $id . '&pdf=1';
$code = $m['isAgent'] ? AgentWallet::agentCodeFor($id) : null;
$staffNo = !$m['isAgent'] ? (int) Database::scalar('SELECT staff_no FROM ap_members WHERE admin_id = :a', ['a' => $id], 0) : 0;
$purgeOpen = isset($_GET['purge']) && $isSuper && !$self;

admin_header($m['name'], 'team.php');
echo '<link rel="stylesheet" href="/assets/agentpro/admin.css?v=' . (int) @filemtime(ASSET_PATH . '/agentpro/admin.css') . '">';
admin_page_head($m['designation'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : ''), ['Team & ID Cards' => '/admin/team.php?tab=' . ($m['isAgent'] ? 'agents' : 'staff'), $m['name'] => ''],
    ($canEditAll ? '<form method="post" style="display:inline;margin:0">' . Security::csrfField() . '<input type="hidden" name="action" value="edit_form">'
        . '<button class="btn sm" type="submit" title="Name, mobile, address, ID, photo, documents, signature, bank — saved live">✏️ Edit all details · सबै विवरण</button></form>' : '')
    . '<a class="btn soft sm" href="' . $e($formUrl) . '" target="_blank" rel="noopener">📄 Filled form</a>'
    . '<a class="btn ghost sm" href="' . $e($formUrl . '&auto=pdf') . '" target="_blank" rel="noopener">⬇ Form PDF</a>'
    . '<a class="btn ghost sm" href="' . $e($cardPdf) . '" target="_blank" rel="noopener">⬇ Cards PDF</a>'
    . ($m['isAgent'] ? '<a class="btn soft sm" href="/admin/agent-360.php?agent=' . $id . '">Agent 360</a>' : '')
    . '<a class="btn wa sm" href="' . $e(whatsappLink($m['wa'], 'Namaste ' . $m['name'] . ',')) . '" target="_blank" rel="noopener">💬 WhatsApp</a>');
if ($flash === null && !empty($_GET['made'])) {
    $flash = ['ok', '🪪 ID card ready — ' . $m['name'] . ($m['idNo'] !== '' ? ' · ' . $m['idNo'] : '') . '. Print it, or send it on WhatsApp.'];
}
if ($flash === null && !empty($_GET['saved'])) {
    $flash = ['ok', '✓ Saved — the account, ID card, visiting card and form show the new details now · परिवर्तन लागू भयो।'];
}
if ($flash !== null) {
    echo '<div class="flash ' . $e($flash[0]) . '">' . $e($flash[1]) . '</div>';
}
// 31 Oct 2026 (owner: "dhyan rakha, double chha"): the same mobile on another login is usually the same
// person twice — show it, with a link, so the office keeps one (an agent ID + a staff ID is allowed).
$twins = $m['phone'] !== '' ? AgentPro::phoneOwners($m['phone'], $id) : [];
if ($twins !== []) {
    echo '<div class="flash warn">⚠️ This mobile is also on: ';
    foreach ($twins as $i => $t) {
        echo ($i > 0 ? ', ' : '') . '<a href="/admin/team-member.php?id=' . (int) $t['id'] . '"><b>' . $e($t['full_name']) . '</b> #' . (int) $t['id'] . ' (' . $e($t['role']) . ((int) $t['is_active'] === 1 ? '' : ', off') . ')</a>';
    }
    echo ' — if it is the same person twice, keep one and delete the other · एउटै मान्छे दुई पटक भए एउटा मेटाउनुहोस्।</div>';
}
if ($archived !== null) {
    echo '<div class="flash bad">🗄 Archived ' . $e(isset($archived['at']) ? formatDate(substr((string) $archived['at'], 0, 10), 'j M Y') : '')
        . (!empty($archived['reason']) ? ' — ' . $e($archived['reason']) : '') . '. Login off, card suspended, off the website; every record is kept.</div>';
}
?>
<style>
.mm-acts{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.mm-acts .btn,.mm-acts button{min-height:44px}
.mm-inline{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0}
.mm-inline input{min-height:48px;padding:0 12px;border:1px solid var(--line-2,var(--line));border-radius:12px;background:var(--card);color:var(--ink);font:inherit;max-width:180px}
.mm-kv{display:grid;grid-template-columns:auto 1fr;gap:4px 12px;font-size:13.5px;margin:0 0 10px}
.mm-kv dt{color:var(--mut,#64748b);font-weight:700}
.mm-kv dd{margin:0}
.mm-docs{display:flex;gap:8px;flex-wrap:wrap}
.mm-docs a{display:block;width:92px;border:1px solid var(--line);border-radius:10px;overflow:hidden;background:#fff;text-decoration:none}
.mm-docs img{width:100%;height:92px;object-fit:cover;display:block}
.mm-docs span{display:block;font-size:10.5px;font-weight:700;text-align:center;padding:2px;color:#0f172a}
.mm-led{display:flex;flex-direction:column;gap:6px;margin-top:10px}
.mm-led div{display:flex;gap:8px;font-size:12.5px;border-bottom:1px dashed var(--line);padding-bottom:4px}
.mm-led b{min-width:84px;font-family:ui-monospace,monospace}
.mm-danger{border:1px solid #fecaca}
</style>
<div class="ap-a">
<?php if ($purgeOpen):
    $h = TeamPurge::history('admin', $id);
    $archBtn = $canArch && $archived === null
        ? '<form method="post" style="margin:0">' . Security::csrfField() . '<input type="hidden" name="action" value="archive"><input type="hidden" name="reason" value="History kept — archived instead of delete"><button class="btn sm" type="submit" style="min-height:48px">🗄 Archive instead</button></form>'
        : '';
    echo TeamPurge::panelHtml($h, '/admin/team-member.php?id=' . $id, ['action' => 'purge'], $archBtn, $isSuper, '/admin/team-member.php?id=' . $id);
endif; ?>
<div class="mp-top">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-id-card"/></svg> ID card <span class="tp-chip <?= $e($statusChip) ?>" style="margin-left:auto"><?= $e(strtoupper($m['status'])) ?></span></h2>
    <div class="panel-body">
      <div class="mp-cards"><a href="<?= $e($cards['id-front']) ?>" target="_blank"><img alt="ID front" src="<?= $e($cards['id-front']) ?>"></a>
        <a href="<?= $e($cards['id-back']) ?>" target="_blank"><img alt="ID back" src="<?= $e($cards['id-back']) ?>"></a></div>
      <p class="ph-sub">Issued <?= $e($m['cardIssued'] !== '' ? formatDate(substr($m['cardIssued'], 0, 10), 'j M Y') : '—') ?> · valid till <?= $e($m['validUntil'] !== '' ? formatDate($m['validUntil'], 'j M Y') : '—') ?></p>
      <div class="mm-acts">
        <a class="btn ghost sm" href="/admin/team-print.php?ids[]=<?= $id ?>&amp;what=all" target="_blank" rel="noopener">🖨 Print</a>
        <a class="btn ghost sm" href="<?= $e($cardPdf) ?>" target="_blank" rel="noopener">⬇ PDF</a>
        <a class="btn ghost sm" href="/id.php?c=<?= $e(rawurlencode($m['cardToken'])) ?>" target="_blank" rel="noopener">Verify page</a>
        <?php if ($canOwn): ?>
        <form method="post" style="display:inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="send_all"><button class="btn wa sm">📲 Send all cards on WhatsApp</button></form>
        <?php endif; ?>
        <?php if ($canOffice): ?>
        <form method="post" style="display:inline" onsubmit="return confirm('Re-issue? Every card printed so far will scan as REVOKED.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="reissue"><button class="btn ghost sm">Re-issue</button></form>
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

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-shield"/></svg> ID number &amp; account · नम्बर र खाता</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:12px">
    <dl class="mm-kv">
      <dt><?= $m['isAgent'] ? 'Agent code' : 'Staff no.' ?></dt><dd class="ap-mono"><b><?= $e($m['idNo'] !== '' ? $m['idNo'] : '— not issued') ?></b></dd>
      <dt>Login</dt><dd><?= $e($m['username']) ?> · <?= $m['active'] ? '<span class="tp-chip ok">on</span>' : '<span class="tp-chip bad">off</span>' ?><?= $m['lastLogin'] !== '' ? ' · last ' . $e(timeAgo($m['lastLogin'])) : '' ?></dd>
      <dt>Card</dt><dd><?= $e($m['cardStatus']) ?></dd>
      <dt>KYC</dt><dd><?= $e($m['kycStatus']) ?><?= $m['idType'] !== '' ? ' · ' . $e($m['idType']) . ' ' . $e($m['idMasked']) : '' ?></dd>
      <?php $web = ApWeb::status($id); ?>
      <dt>Website</dt><dd><span class="tp-chip <?= $web['shown'] ? 'ok' : 'warn' ?>"><?= $web['shown'] ? '🌐 on the home page' : 'not shown' ?></span> <small class="ph-sub"><?= $e($web['reason']) ?></small></dd>
    </dl>
    <?php if ($canOffice || ($self && $isSuper)): ?>
      <form method="post" class="mm-inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="web_show">
        <select name="web" style="min-height:48px;border-radius:12px;padding:0 10px" aria-label="Home page"<?= $web['ready'] ? '' : ' disabled' ?>>
          <option value="auto"<?= $web['mode'] === 'auto' ? ' selected' : '' ?>>🌐 Automatic (approved + photo) · स्वतः</option>
          <option value="1"<?= $web['mode'] === 'show' ? ' selected' : '' ?>>Always show · सधैं देखाउने</option>
          <option value="0"<?= $web['mode'] === 'hide' ? ' selected' : '' ?>>Hide · लुकाउने</option>
        </select>
        <button class="btn ghost sm" type="submit" style="min-height:48px"<?= $web['ready'] ? '' : ' disabled' ?>>Save</button></form>
    <?php endif; ?>
    <?php if ($canCode && $m['isAgent']): ?>
      <form method="post" class="mm-inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="set_code">
        <input name="code" inputmode="numeric" placeholder="<?= $e($code !== null ? (string) $code : 'e.g. 27') ?>" aria-label="Agent number">
        <button class="btn sm" type="submit" style="min-height:48px">Set code</button>
        <button class="btn soft sm" type="submit" name="auto" value="1" style="min-height:48px">⚡ Next free number</button></form>
    <?php elseif ($canCode && !$m['isAgent']): ?>
      <form method="post" class="mm-inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="set_staff_no">
        <span class="ap-mono">SHG-S-</span><input name="staff_no" inputmode="numeric" value="<?= $staffNo > 0 ? $e(str_pad((string) $staffNo, 4, '0', STR_PAD_LEFT)) : '' ?>" aria-label="Staff number">
        <button class="btn sm" type="submit" style="min-height:48px">Save staff no.</button></form>
    <?php endif; ?>
    <?php if ($canStatus || $canArch || ($isSuper && !$self && !$targetSuper)): ?>
    <div class="mm-acts">
      <?php if ($canStatus && $archived === null): ?>
        <?php if ($m['active'] && $m['cardStatus'] !== 'suspended'): ?>
          <form method="post" class="mm-inline" onsubmit="return confirm('Suspend? The card reads SUSPENDED and the login, /me/ and WhatsApp desk stop.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="suspend">
            <input name="reason" maxlength="200" placeholder="Reason (optional)"><button class="btn ghost danger sm">⏸ Suspend (card + login)</button></form>
        <?php else: ?>
          <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="activate"><button class="btn ok sm">▶ Activate (card + login)</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($canArch): ?>
        <?php if ($archived === null): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Archive this member? Login off, card suspended, off the website. Records are kept and it can be restored.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="archive"><button class="btn ghost sm">🗄 Archive</button></form>
        <?php else: ?>
          <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="restore"><button class="btn ok sm">↩ Restore</button></form>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($isSuper && !$self && !$targetSuper): ?>
        <a class="btn ghost danger sm" href="/admin/team-member.php?id=<?= $id ?>&amp;purge=1#purge">🗑 Delete permanently…</a>
      <?php endif; ?>
    </div>
    <?php elseif ($self): ?>
      <small class="ph-sub">This is your own record — the office changes your code, card status and validity.</small>
    <?php endif; ?>
  </div></section>

  <section class="panel"><h2><svg class="a-ic"><use href="#a-doc"/></svg> Application &amp; documents</h2><div class="panel-body">
    <?php if ($app === null): ?>
      <p class="ph-sub" style="margin:0 0 10px">No join form on file for this member (added before AgentPro).<?= $canEditAll ? ' “✏️ Edit all details” makes one from the account — then add the photo, ID, signature and print it.' : '' ?></p>
      <?php if ($canEditAll): ?>
      <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="edit_form"><button class="btn sm" type="submit">✏️ Make the form &amp; edit all details</button></form>
      <?php endif; ?>
    <?php else: ?>
      <dl class="mm-kv">
        <dt>Form</dt><dd><b class="ap-mono"><?= $e($app['ref']) ?></b> · <?= $e($app['status']) ?><?= !empty($app['submitted_at']) ? ' · sent ' . $e(formatDate(substr((string) $app['submitted_at'], 0, 10), 'j M Y')) : '' ?></dd>
        <dt>WhatsApp</dt><dd><?= !empty($app['wa_verified_at']) ? '✓ ' . $e($app['wa_verified_via']) : 'not verified' ?></dd>
      </dl>
      <div class="mm-acts" style="margin-bottom:10px">
        <a class="btn soft sm" href="/admin/team-form.php?app=<?= (int) $app['id'] ?>" target="_blank" rel="noopener">📄 Open the filled form</a>
        <a class="btn ghost sm" href="/admin/team-form.php?app=<?= (int) $app['id'] ?>&amp;auto=pdf" target="_blank" rel="noopener">⬇ PDF</a>
        <?php if (in_array($app['status'], ['draft', 'submitted'], true) && ($isSuper || $isMgr || Auth::can('staff.manage'))): ?>
          <a class="btn ghost sm" href="/join.php?office=1&amp;d=<?= $e(rawurlencode((string) $app['draft_key'])) ?>">✏️ Finish / edit</a>
        <?php elseif ($app['status'] === 'approved' && $canEditAll): ?>
          <form method="post" style="display:inline;margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="edit_form"><button class="btn sm" type="submit">✏️ Edit all details</button></form>
        <?php endif; ?>
        <?php if ($canOwn && $app['status'] === 'approved'): ?>
          <form method="post" style="display:inline;margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="send_form"><button class="btn wa sm" type="submit">📲 Send form on WhatsApp</button></form>
        <?php endif; ?>
      </div>
      <?php if ($canKyc):
          $docs = [];
          foreach (['selfie' => 'Selfie', 'front' => 'ID front', 'back' => 'ID back', 'thumb' => 'Thumb print'] as $f => $label) {
              $col = ['front' => 'doc_front', 'back' => 'doc_back'][$f] ?? $f;
              if (!empty($app[$col])) { $docs[] = ['/admin/team-doc.php?app=' . (int) $app['id'] . '&f=' . $f, $label]; }
          }
          foreach (jsonColumn($app['extras'] ?? null) as $i => $x) {
              if (is_array($x) && !empty($x['path'])) { $docs[] = ['/admin/team-doc.php?app=' . (int) $app['id'] . '&f=extra&i=' . (int) $i, (string) ($x['label'] ?? 'Document')]; }
          }
          ?>
        <div class="mm-docs"><?php foreach ($docs as [$u, $l]): ?><a href="<?= $e($u) ?>" target="_blank" rel="noopener"><img loading="lazy" alt="" src="<?= $e($u) ?>"><span><?= $e($l) ?></span></a><?php endforeach; ?></div>
        <?php if ($docs === []) { echo '<small class="ph-sub">No photos or documents on the form.</small>'; } ?>
        <?php if (!$targetSuper || $isSuper): ?>
        <form method="post" enctype="multipart/form-data" class="mm-inline" style="margin-top:10px"><?= Security::csrfField() ?><input type="hidden" name="action" value="add_doc">
          <select name="label" style="min-height:48px;border-radius:12px;padding:0 10px" aria-label="Document"><?php foreach (AgentPro::MEMBER_DOC_LABELS as $l): ?><option><?= $e($l) ?></option><?php endforeach; ?></select>
          <input type="file" name="doc" accept="image/*,application/pdf" required style="max-width:220px">
          <button class="btn sm" type="submit" style="min-height:48px">📎 Add document</button></form>
        <?php endif; ?>
      <?php else: ?>
        <small class="ph-sub">🔒 ID documents are shown to a super-admin or a manager only.</small>
      <?php endif; ?>
    <?php endif; ?>
  </div></section>
</div>

<?php if ($canOwn): ?>
<section class="panel"><h2><svg class="a-ic"><use href="#a-edit"/></svg> What the cards print</h2><div class="panel-body">
  <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="card">
    <?php if ($canPrint): ?>
    <label class="full">Name on the card<input name="full_name" maxlength="120" value="<?= $e($m['name']) ?>"></label>
    <label>Designation (under the name)<input name="designation" maxlength="80" value="<?= $e($m['designation']) ?>"></label>
    <label>Department<input name="department" maxlength="80" value="<?= $e($m['department']) ?>"></label>
    <?php endif; ?>
    <label>WhatsApp number on the visiting card<input name="vc_whatsapp" value="<?= $e($m['waDisplay']) ?>" placeholder="+91 98765 43210"></label>
    <label>Second phone<input name="vc_phone" value="<?= $e($m['call'] !== $m['wa'] ? $m['callDisplay'] : '') ?>"></label>
    <label>Email on the card<input name="vc_email" type="email" value="<?= $e($m['email']) ?>"></label>
    <label>Style<select name="vc_theme"><?php foreach (['royal' => 'Royal (navy & gold)', 'gold' => 'Gold (ivory)', 'night' => 'Night (black & gold)'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['theme'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="full">Line under the company name<input name="vc_tagline" maxlength="120" value="<?= $e($m['tagline']) ?>"></label>
    <label class="full">WhatsApp message ready when the QR is scanned<input name="vc_greeting" maxlength="160" value="<?= $e($m['greeting']) ?>"></label>
    <?php if ($canPrint): ?>
    <label>Date of birth<input type="date" name="dob" value="<?= $e($m['dob']) ?>"></label>
    <label>Gender<select name="gender"><option value=""></option><?php foreach (['Male', 'Female', 'Other'] as $g): ?><option<?= $m['gender'] === $g ? ' selected' : '' ?>><?= $g ?></option><?php endforeach; ?></select></label>
    <label>Father / husband<input name="guardian" value="<?= $e($m['guardian']) ?>"></label>
    <?php endif; ?>
    <?php if ($canOffice): ?>
    <label>Card valid till<input type="date" name="card_valid_until" value="<?= $e($m['validUntil']) ?>"></label>
    <?php endif; ?>
    <label>Blood group<select name="blood_group"><option value=""></option><?php foreach (AgentPro::BLOOD_GROUPS as $b): ?><option<?= $m['bloodGroup'] === $b ? ' selected' : '' ?>><?= $b ?></option><?php endforeach; ?></select></label>
    <label>Emergency contact name<input name="emergency_name" value="<?= $e($m['emergencyName']) ?>"></label>
    <label>Emergency phone<input name="emergency_phone" value="<?= $e($m['emergencyPhone']) ?>"></label>
    <label>WhatsApp language<select name="lang"><?php foreach (['ne' => 'नेपाली', 'hi' => 'हिंदी', 'en' => 'English'] as $k => $l): ?><option value="<?= $k ?>"<?= $m['lang'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></label>
    <div class="full"><button class="btn" type="submit">Save</button><?php if (!$canPrint): ?> <small class="ph-sub">Name, designation, date of birth and card validity are changed by the office.</small><?php elseif (!$canOffice): ?> <small class="ph-sub">Card validity is changed by another office user.</small><?php endif; ?></div>
  </form>
</div></section>
<?php endif; ?>

<?php if (!$m['isAgent'] && !$targetSuper && ($crew !== '' || ($canManage && $m['role'] === AgentPro::CREW_LOGIN_ROLE))):
    $drv = $crew !== '' ? AgentPro::driverRow($id) : null;
    $dev = null;
    if ($drv !== null) {
        try {
            $dev = Database::fetch('SELECT COUNT(*) AS n, MAX(last_seen) AS seen FROM driver_devices WHERE driver_id = :d AND revoked_at IS NULL', ['d' => $drv['id']]);
        } catch (Throwable $ex) {
            $dev = null;
        }
    }
    ?>
<section class="panel"><h2>🚌 Bus crew &amp; driver app · बस लोकेसन</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
  <dl class="mm-kv">
    <dt>Crew role</dt><dd><?= $e($crew !== '' ? ucfirst($crew) : '— not bus crew') ?></dd>
    <?php if ($crew !== ''): ?>
    <dt>Driver app</dt><dd><?php if ($drv === null): ?><span class="tp-chip warn">no account yet</span><?php else: ?>
      <span class="tp-chip <?= $drv['is_active'] === 1 ? 'ok' : 'bad' ?>"><?= $drv['is_active'] === 1 ? 'on' : 'switched off' ?></span>
      · <?= $drv['has_pin'] ? 'PIN set' : 'no PIN' ?> · <?= $e($drv['role']) ?> · <span class="ap-mono"><?= $e($drv['phone']) ?></span>
      <?php if ($dev !== null): ?> · <?= (int) $dev['n'] ?> phone(s)<?= $dev['seen'] ? ', last seen ' . $e(timeAgo((string) $dev['seen'])) : '' ?><?php endif; ?>
      <?php endif; ?></dd>
    <?php endif; ?>
  </dl>
  <?php if ($canManage): ?>
  <div class="mm-acts">
    <?php if (AgentPro::memberHas('crew_role')): ?>
    <form method="post" class="mm-inline"><?= Security::csrfField() ?><input type="hidden" name="action" value="crew_set">
      <select name="crew" style="min-height:48px;border-radius:12px;padding:0 10px"><option value="">Not bus crew</option>
        <?php foreach (AgentPro::CREW_ROLES as $c): ?><option value="<?= $e($c) ?>"<?= $crew === $c ? ' selected' : '' ?>><?= $e(ucfirst($c)) ?></option><?php endforeach; ?></select>
      <button class="btn ghost sm" type="submit" style="min-height:48px">Save</button></form>
    <?php endif; ?>
    <?php if ($crew !== ''): ?>
      <?php if ($drv === null || $drv['is_active'] !== 1 || !$drv['has_pin']): ?>
      <form method="post" style="margin:0"><?= Security::csrfField() ?><input type="hidden" name="action" value="driver_link"><button class="btn ok sm" type="submit" style="min-height:48px">🚌 Give driver app (PIN on WhatsApp)</button></form>
      <?php endif; ?>
      <?php if ($drv !== null): ?>
      <form method="post" style="margin:0" onsubmit="return confirm('Make a new PIN? Every phone signed in with the old one is signed out.')"><?= Security::csrfField() ?><input type="hidden" name="action" value="driver_pin"><button class="btn ghost sm" type="submit" style="min-height:48px">🔑 New PIN → WhatsApp</button></form>
      <a class="btn ghost sm" href="/admin/drivers.php?q=<?= $e(rawurlencode($drv['phone'])) ?>" style="min-height:48px">Drivers list</a>
      <?php endif; ?>
      <a class="btn ghost sm" href="/admin/map.php" style="min-height:48px">🗺 Live map</a>
    <?php endif; ?>
  </div>
  <small class="ph-sub">They open /me/ → “🚌 बस लोकेसन सुरु” (signed in at once), or /driver/ with this mobile + PIN. Their phone's position goes to the live map, the passenger track page and the “bus is near” WhatsApp.</small>
  <?php endif; ?>
</div></section>
<?php endif; ?>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-camera"/></svg> Photo</h2><div class="panel-body" style="display:flex;gap:14px;align-items:center">
    <span class="tp-ph" style="width:90px;height:108px"><?= $m['photoUrl'] !== '' ? '<img alt="" src="' . $e($m['photoUrl']) . '">' : '?' ?></span>
    <?php if ($canPrint): ?><form method="post" enctype="multipart/form-data"><?= Security::csrfField() ?><input type="hidden" name="action" value="photo">
      <input type="file" name="photo" accept="image/*" required> <button class="btn sm" type="submit">Upload</button></form><?php endif; ?>
  </div></section>
  <section class="panel"><h2><svg class="a-ic"><use href="#a-wallet"/></svg> Wallet</h2><div class="panel-body">
    <div class="ai-hw"><div><small>Commission due</small><b><?= $e(inr($bal['commission'])) ?></b></div><div><small>Cash held</small><b><?= $e(inr($bal['cash'])) ?></b></div></div>
    <div class="mm-acts" style="margin-top:10px">
      <?php if ($m['isAgent']): ?><a class="btn soft sm" href="/admin/agent.php?agent=<?= $id ?>">Open the agent wallet</a><?php endif; ?>
      <?php if (Auth::can('commissions.view')): ?><a class="btn ghost sm" href="/admin/team.php?tab=money&amp;m=<?= $id ?>#hist">History</a><?php endif; ?>
      <?php if (!$self && ($isSuper || Auth::can('commissions.pay'))): ?><a class="btn ghost sm" href="/admin/team.php?tab=money&amp;m=<?= $id ?>#adjust">＋/− Credit or debit</a><?php endif; ?>
    </div>
    <?php if ($recent !== []): ?>
      <div class="mm-led"><?php foreach ($recent as $r): ?>
        <div><b style="color:<?= (float) $r['amount'] < 0 ? '#b91c1c' : '#15803d' ?>"><?= $e(((float) $r['amount'] < 0 ? '−' : '+') . inr(abs((float) $r['amount']))) ?></b><span><?= $e($r['entry_type']) ?> · <?= $e($r['account']) ?><?= !empty($r['pnr']) ? ' · ' . $e($r['pnr']) : '' ?><?= $r['note'] ? ' · ' . $e($r['note']) : '' ?> · <?= $e(timeAgo((string) $r['created_at'])) ?></span></div>
      <?php endforeach; ?></div>
    <?php endif; ?>
  </div></section>
</div>

<div class="tp-money">
  <section class="panel"><h2><svg class="a-ic"><use href="#a-banknote"/></svg> Payout details</h2><div class="panel-body">
    <?php if ($canOwn): ?>
    <form method="post" class="mp-form"><?= Security::csrfField() ?><input type="hidden" name="action" value="bank">
      <label>UPI ID<input name="upi_id" value="<?= $e($m['bank']['upi']) ?>"></label>
      <label>Account holder<input name="bank_holder" value="<?= $e($m['bank']['holder']) ?>"></label>
      <label>Account number<input name="bank_account" autocomplete="off" placeholder="<?= $e($fullAcct !== '' ? $fullAcct : ($m['bank']['last4'] ? '••••' . $m['bank']['last4'] : '')) ?>"></label>
      <label>IFSC<input name="bank_ifsc" value="<?= $e($m['bank']['ifsc']) ?>"></label>
      <label>Bank<input name="bank_name" value="<?= $e($m['bank']['name']) ?>"></label>
      <label>eSewa<input name="esewa_id" value="<?= $e($m['bank']['esewa']) ?>"></label>
      <div class="full"><button class="btn sm" type="submit">Save</button></div>
    </form>
    <?php else: ?><p><?= $e($m['bank']['upi'] ?: ($m['bank']['last4'] ? $m['bank']['name'] . ' ••••' . $m['bank']['last4'] : '—')) ?></p><?php endif; ?>
  </div></section>
  <section class="panel"><h2><svg class="a-ic"><use href="#a-whatsapp"/></svg> WhatsApp desk & sign-in</h2><div class="panel-body" style="display:flex;flex-direction:column;gap:8px">
    <small class="ph-sub" style="margin:0">Numbers that act for <?= $e($m['idNo'] ?: $m['name']) ?> on WhatsApp (linked with "LINK <?= $e($m['idNo']) ?>" + OTP). The registered mobile <?= $e($m['callDisplay']) ?> always works while the login is on.</small>
    <?php foreach ($links as $l): ?>
      <div class="tp-req"><span class="who"><b class="ap-mono">+<?= $e($l['phone']) ?></b><small>linked <?= $e(timeAgo((string) $l['verified_at'])) ?><?= $l['last_seen_at'] ? ' · last used ' . $e(timeAgo((string) $l['last_seen_at'])) : '' ?></small></span>
        <?php if ($canOwn): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="phone" value="<?= $e($l['phone']) ?>"><button class="btn ghost danger sm">Unlink</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php foreach ($pks as $p): ?>
      <div class="tp-req"><span class="who"><b>🔐 <?= $e($p['label'] ?: 'Passkey') ?></b><small>fingerprint / face sign-in · added <?= $e(timeAgo((string) $p['created_at'])) ?></small></span>
        <?php if ($canOwn): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="pk_remove"><input type="hidden" name="pk" value="<?= (int) $p['id'] ?>"><button class="btn ghost danger sm">Remove</button></form><?php endif; ?></div>
    <?php endforeach; ?>
    <?php if ($canManage): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="welcome"><button class="btn soft sm">Re-send welcome + ID card</button></form><?php endif; ?>
  </div></section>
</div>
</div>
<?php admin_footer();
