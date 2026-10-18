<?php
/**
 * admin/agreements.php — Agreements & agents (27 Sep 2026).
 *
 * The owner asked for one place that shows who registered: the people and
 * organizations who applied for an agreement on /agreements/, AND the agents
 * and partners already working, each with their KYC and agreement status.
 *
 *   Top cards   new applications · KYC pending · agreement pending ·
 *               signed / approved · agents & partners
 *   Tab 1       Applications: search (name / phone / ref / area), status and
 *               type filters, the list, and one opened application in full
 *               (every field, the photo, witnesses) with its actions:
 *               status, KYC, agreement (+ signed date — the end date is
 *               signed + 11 months, worked out here), note, linked account.
 *   Tab 2       Existing agents & partners: every agent account with KYC,
 *               agreement status / end and the two public-list switches;
 *               the name opens Agent 360.
 *
 * Who: viewing needs customers.view (manager, support, counter staff and
 * the super-admin) — the list, the statuses, name, phone and area. The KYC
 * and bank details (ID / PAN / account numbers, date of birth, an
 * individual's home address, bank, IFSC, UPI) and the photo are shown in
 * full only to a super-admin or a manager, and every such opening is written
 * to the activity log; everyone else sees them masked (••••1234 / hidden).
 * Any change needs POST + CSRF + a super-admin or a manager. The photo is
 * printed as a data: URI into this page only — it is never a file on an
 * open URL.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentapplications.php';
$admin = admin_boot('customers.view');

$role    = (string) ($admin['role'] ?? '');
$canEdit = Auth::isSuperadmin() || $role === 'manager';
$canKyc  = $canEdit;   // who sees ID, bank and photo in full
$selfId  = (int) ($admin['id'] ?? 0);
$h       = static fn($v): string => Security::e((string) ($v ?? ''));
$tab     = (string) ($_GET['tab'] ?? 'apps') === 'agents' ? 'agents' : 'apps';
$flash   = null;
$ready   = AgentApplications::available();

/* ---------- actions (POST + CSRF + super-admin / manager) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        http_response_code(419);
        $flash = ['bad', 'This page was open too long — nothing was saved. Please try again.'];
    } elseif (!$canEdit) {
        http_response_code(403);
        $flash = ['bad', 'Only a super-admin or a manager can change agreements and KYC.'];
    } else {
        try {
            if ($act === 'app') {
                $id = (int) ($_POST['id'] ?? 0);
                $in = [
                    'status'              => (string) ($_POST['status'] ?? ''),
                    'kyc_status'          => (string) ($_POST['kyc_status'] ?? ''),
                    'agreement_status'    => (string) ($_POST['agreement_status'] ?? ''),
                    'agreement_signed_at' => (string) ($_POST['agreement_signed_at'] ?? ''),
                ];
                if (array_key_exists('note', $_POST)) {
                    $in['note'] = (string) $_POST['note'];
                }
                if (array_key_exists('admin_id', $_POST)) {
                    $in['admin_id'] = (int) $_POST['admin_id'];
                }
                $changed = AgentApplications::update($id, $in, $selfId);
                Response::redirect('admin/agreements.php?tab=apps&id=' . $id . '&saved=' . ($changed === [] ? '0' : '1') . '#detail');
            } elseif ($act === 'card' || $act === 'add') {
                // 19 Oct 2026: the website card + KYC / bank edit, and the office's own "add agent".
                $in = [
                    'name'      => (string) ($_POST['name'] ?? ''),
                    'phone'     => (string) ($_POST['phone'] ?? ''),
                    'area'      => (string) ($_POST['area'] ?? ''),
                    'email'     => (string) ($_POST['email'] ?? ''),
                    'web_addr'  => (string) ($_POST['web_addr'] ?? ''),
                    'public_ok' => isset($_POST['public_ok']) ? 1 : 0,
                    'web_photo' => isset($_POST['web_photo']) ? 1 : 0,
                    'web_live'  => isset($_POST['web_live']) ? 1 : 0,
                ];
                if ($canKyc) {
                    foreach (AgentApplications::OFFICE_KYC_FIELDS as $k) {
                        if (array_key_exists($k, $_POST)) {
                            $in[$k] = (string) $_POST[$k];
                        }
                    }
                }
                $up = AgentApplications::photoFromUpload(isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : null);
                if ($up !== null) {
                    $in['photo'] = $up;
                }
                if ($act === 'add') {
                    $in['type']       = (string) ($_POST['type'] ?? 'ind');
                    $in['kyc_status'] = (string) ($_POST['kyc_status'] ?? 'none');
                    $made = AgentApplications::adminCreate($in, $selfId, $canKyc);
                    Response::redirect('admin/agreements.php?tab=apps&id=' . $made['id'] . '&saved=1#detail');
                }
                $id = (int) ($_POST['id'] ?? 0);
                $changed = AgentApplications::saveCard($id, $in, $selfId, $canKyc);
                Response::redirect('admin/agreements.php?tab=apps&id=' . $id . '&saved=' . ($changed === [] ? '0' : '1') . '#web');
            } elseif ($act === 'golive') {
                $id = (int) ($_POST['id'] ?? 0);
                AgentApplications::update($id, ['status' => 'approved'], $selfId);
                AgentApplications::saveCard($id, ['web_live' => 1], $selfId, false);
                Response::redirect('admin/agreements.php?tab=apps&id=' . $id . '&saved=1#web');
            } elseif ($act === 'delete') {
                $ref = AgentApplications::remove((int) ($_POST['id'] ?? 0), $selfId);
                Response::redirect('admin/agreements.php?tab=apps&deleted=' . rawurlencode($ref));
            } elseif ($act === 'agent') {
                $aid = (int) ($_POST['admin_id'] ?? 0);
                AgentApplications::setAgentProfile($aid, [
                    'agreement_status'    => (string) ($_POST['agreement_status'] ?? ''),
                    'agreement_signed_at' => (string) ($_POST['agreement_signed_at'] ?? ''),
                    'public_listing'      => isset($_POST['public_listing']) ? 1 : 0,
                    'public_phone'        => isset($_POST['public_phone']) ? 1 : 0,
                ], $selfId);
                Response::redirect('admin/agreements.php?tab=agents&saved=1#ag' . $aid);
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (AgreementError $ex) {
            // Our own refusals only ("That is not an agent account.").
            // A database error is a RuntimeException too (PDOException) and
            // must never put SQL text on the page — it falls through below.
            $flash = ['bad', $ex->getMessage()];
        } catch (Throwable $ex) {
            Logger::exception($ex);
            $flash = ['bad', 'That could not be saved. Please try again.'];
        }
    }
}
if ($flash === null && isset($_GET['saved'])) {
    $flash = (string) $_GET['saved'] === '1' ? ['ok', 'Saved.'] : ['ok', 'Nothing to change.'];
}
if ($flash === null && isset($_GET['deleted'])) {
    $flash = ['ok', 'Removed ' . Security::clean((string) $_GET['deleted'], 20) . ' — it is off the website and the list.'];
}
$webReady = $ready && AgentApplications::webColumnsAvailable();

/* ---------- data ---------- */
$counts = AgentApplications::counts();
$agents = AgentApplications::agents();
$activeAgents = array_values(array_filter($agents, static fn($a) => (int) $a['is_active'] === 1));
$agPartners   = count(array_filter($activeAgents, static fn($a) => $a['kind'] === 'partner'));
$agKycWait    = count(array_filter($activeAgents, static fn($a) => in_array($a['kyc_status'], ['none', 'submitted'], true)));
$agAgrWait    = count(array_filter($activeAgents, static fn($a) => $a['agreement_status'] !== 'signed'));
$agSigned     = count(array_filter($activeAgents, static fn($a) => $a['agreement_status'] === 'signed'));

$q      = trim(Security::clean($_GET['q'] ?? '', 80));
$fStat  = (string) ($_GET['status'] ?? '');
$fType  = (string) ($_GET['type'] ?? '');
$apps   = $tab === 'apps' ? AgentApplications::list(['q' => $q, 'status' => $fStat, 'type' => $fType]) : [];
$openId = (int) ($_GET['id'] ?? 0);
$open   = $openId > 0 ? AgentApplications::find($openId) : null;

// Opening an application's KYC / bank details or photo IN FULL is written
// to the activity log: who looked at whose documents, and when.
if ($open !== null && $canKyc) {
    $kycShown = !empty($open['photo']);
    foreach (array_keys(is_array($open['fields'] ?? null) ? $open['fields'] : []) as $fn) {
        if (AgentApplications::isKycField((string) $fn, (string) $open['type'])) {
            $kycShown = true;
            break;
        }
    }
    if ($kycShown) {
        Logger::audit('agreement_app.view', 'agent_application',
            (string) ($open['ref'] ?: AgentApplications::refFor((int) $open['id'])), null, null, 'KYC / bank details opened');
    }
}

$keep = static function (array $extra = []) use ($q, $fStat, $fType): string {
    $p = array_filter(['tab' => 'apps', 'q' => $q, 'status' => $fStat, 'type' => $fType], static fn($v) => $v !== '');
    return 'agreements.php?' . http_build_query(array_merge($p, $extra));
};
$statusPill = static function (string $s): string {
    $cls = match ($s) {
        'new' => 'st-info', 'reviewing' => 'st-violet', 'kyc_pending', 'agreement_pending' => 'st-warn',
        'approved' => 'st-ok', 'rejected' => 'st-bad', default => 'st-muted',
    };
    return '<span class="pill ' . $cls . '">' . Security::e(AgentApplications::STATUS_LABELS[$s] ?? ucfirst($s)) . '</span>';
};
$kycPill = static function (string $s): string {
    $cls = ['verified' => 'st-ok', 'submitted' => 'st-warn', 'rejected' => 'st-bad'][$s] ?? 'st-muted';
    return '<span class="pill ' . $cls . '">' . Security::e(AgentApplications::KYC_LABELS[$s] ?? ucfirst($s)) . '</span>';
};
$agrPill = static function (string $s, ?string $end = null): string {
    $cls = ['signed' => 'st-ok', 'sent' => 'st-info', 'expired' => 'st-bad'][$s] ?? 'st-muted';
    $txt = AgentApplications::AGREEMENT_LABELS[$s] ?? ucfirst($s);
    if ($s === 'signed' && $end !== null && $end !== '' && $end < date('Y-m-d')) {
        $cls = 'st-bad';
        $txt = 'Ended';
    }
    return '<span class="pill ' . $cls . '">' . Security::e($txt) . '</span>'
        . ($end ? '<div class="muted text-xs">till ' . Security::e(date('d M Y', (int) strtotime($end))) . '</div>' : '');
};
$fmtDate = static fn(?string $d): string => $d ? date('d M Y', (int) strtotime($d)) : '—';
$csrfField = Security::csrfField();

admin_header('Agreements & agents', 'agents');
admin_page_head(
    'Who applied for an agreement, and where every agent and partner stands on KYC and agreement.',
    ['Agents' => '/admin/agents.php', 'Agreements & agents' => ''],
    '<a class="btn ghost sm" href="/agreements/" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-doc"/></svg>Agreement pages</a>'
    . '<a class="btn ghost sm" href="agents.php"><svg class="a-ic"><use href="#a-users"/></svg>Agents register</a>'
    . '<a class="btn ghost sm" href="staff.php#addStaff"><svg class="a-ic"><use href="#a-user-cog"/></svg>Agent logins</a>'
    . '<a class="btn sm" href="agreements.php?tab=apps&amp;add=1#add"><svg class="a-ic"><use href="#a-plus"/></svg>Add agent</a>'
);
if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . $h($flash[1]) . '</div>';
}
if (!$ready) {
    echo '<div class="flash bad">The applications table is not there yet. Run <code>database/upgrade-2026-09-27-agent-applications.sql</code> once.</div>';
}
?>
<style>
.agr-photo{width:132px;height:164px;object-fit:cover;border-radius:12px;border:1px solid var(--line);background:var(--soft)}
.agr-nophoto{width:132px;height:164px;border-radius:12px;border:1px dashed var(--line-2);display:flex;align-items:center;justify-content:center;text-align:center;font-size:12px;color:var(--mut);padding:8px}
.agr-head{display:flex;gap:18px;flex-wrap:wrap;align-items:flex-start}
.agr-head .kv-list{flex:1 1 280px}
.agr-sec{margin:18px 0 6px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}
.agr-act{border-top:1px solid var(--line);margin-top:18px;padding-top:16px}
.agr-end{font-size:12px;color:var(--mut);margin-top:4px}
.agr-row-open{background:var(--blue-50)}
.agr-check{display:inline-flex;align-items:center;gap:6px;font-size:13px;white-space:nowrap;min-height:44px;cursor:pointer}
.agr-check input{width:20px;height:20px}
.agr-mini{min-height:36px;padding:6px 8px;border:1px solid var(--line-2);border-radius:8px;background:var(--card);color:var(--ink);font:inherit;font-size:13px;max-width:100%}
.agr-masked{color:var(--mut);font-style:italic}
.agr-repeat{display:inline-block;margin-left:4px;font-size:11px;font-weight:700;color:var(--mut)}
.kv-list dd{min-width:0;overflow-wrap:anywhere}
.agr-add{padding:0}
.agr-add>summary{cursor:pointer;list-style:none;display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:14px 18px;min-height:48px}
.agr-add>summary::-webkit-details-marker{display:none}
.agr-card-prev{display:flex;gap:12px;align-items:center;max-width:420px;padding:10px 12px;border-radius:16px;color:#fff;background:linear-gradient(140deg,#0a2a8f,#1f4fe0 60%,#2f6bff)}
.agr-card-prev img,.agr-card-prev .agr-ini{flex:0 0 52px;width:52px;height:52px;border-radius:50%;object-fit:cover;border:2px solid #fff;background:#fff;display:grid;place-items:center;font-weight:900;color:#0a2a8f}
.agr-card-prev .muted{color:#dbe5ff}
@media(max-width:560px){.agr-photo,.agr-nophoto{width:108px;height:134px}.kv-list{grid-template-columns:1fr}.kv-list dt{margin-top:6px}.agr-mini{min-height:44px}}
</style>

<?php $n = static fn(int $c, string $one, string $many): string => $c . ' ' . ($c === 1 ? $one : $many); ?>
<div class="kpis">
  <?= admin_kpi('New applications', (string) $counts['new'], $counts['total'] . ' in all', 'doc', 'blue', null, $keep(['status' => 'new'])) ?>
  <?= admin_kpi('KYC pending', (string) ($counts['kyc_waiting'] + $agKycWait), $n($counts['kyc_waiting'], 'application', 'applications') . ' · ' . $n($agKycWait, 'agent', 'agents'), 'id-card', 'orange') ?>
  <?= admin_kpi('Not signed yet',(string) ($counts['agreement_waiting'] + $agAgrWait), $n($counts['agreement_waiting'], 'application', 'applications') . ' · ' . $n($agAgrWait, 'agent', 'agents'), 'handshake', 'violet') ?>
  <?= admin_kpi('Signed / approved', (string) ($counts['signed'] + $agSigned), $n($counts['signed'], 'application', 'applications') . ' · ' . $n($agSigned, 'agent', 'agents'), 'check-circle', 'green') ?>
  <?= admin_kpi('Agents & partners', (string) count($activeAgents), $n($agPartners, 'partner', 'partners') . ' · ' . $n(count($activeAgents) - $agPartners, 'agent', 'agents') . ' (active)', 'users', 'navy', null, 'agreements.php?tab=agents') ?>
</div>

<nav class="tabs" aria-label="Sections">
  <a href="agreements.php?tab=apps" class="<?= $tab === 'apps' ? 'on' : '' ?>">Applications (<?= (int) $counts['total'] ?>)</a>
  <a href="agreements.php?tab=agents" class="<?= $tab === 'agents' ? 'on' : '' ?>">Existing agents &amp; partners (<?= count($agents) ?>)</a>
</nav>

<?php if ($tab === 'apps'): ?>

<?php if ($open !== null):
    $f = is_array($open['fields'] ?? null) ? $open['fields'] : [];
    $isOrg = (string) $open['type'] === 'org';
    $phoneDigits = preg_replace('/\D/', '', (string) $open['phone']) ?? '';
    // India and Nepal mobiles are both 10 digits, so the country comes from
    // what the person typed (+91 / +977). Not typed = offer both.
    $country = resolvePhoneCountry('', (string) ($open['phone_raw'] ?? ''));
    $telHref = ($country !== '' ? '+' . countryDialCode($country) : '') . $phoneDigits;
    $waLinks = [];
    if ($phoneDigits !== '' && strlen($phoneDigits) === 10) {
        $codes = $country !== '' ? [countryDialCode($country) => 'WhatsApp'] : ['91' => 'WhatsApp (India +91)', '977' => 'WhatsApp (Nepal +977)'];
        foreach ($codes as $cc => $label) {
            $waLinks[] = ['https://wa.me/' . $cc . $phoneDigits, $label];
        }
    } elseif ($phoneDigits !== '') {
        $waLinks[] = ['https://wa.me/' . $phoneDigits, 'WhatsApp'];
    }
    $groups = [
        'Applicant'       => ['p_name', 'p_type', 'p_reg', 'p_tax', 'p_rep', 'p_rep_desig', 'p_rep_phone', 'p_id', 'p_dob', 'p_occ', 'p_idtype', 'p_idno', 'p_pan', 'p_phone', 'p_email', 'p_area', 'p_addr', 'p_addr2', 'p_office'],
        'Agreement'       => ['a_no', 'a_date', 'a_place', 'a_start', 'a_end', 'co_sig', 'co_desig', 'org_rate', 'org_pct', 'org_target', 'org_fixed', 'org_day', 'ind_target', 'ind_fixed', 'ind_pct', 'c73'],
        'Bank / payment'  => ['b_name', 'b_bank', 'b_acc', 'b_ifsc', 'b_upi'],
        'Witnesses'       => ['w1_name', 'w1_addr', 'w2_name', 'w2_addr'],
    ];
    $sameAgent = null;
    foreach ($agents as $ag) {
        if ($phoneDigits !== '' && normalisePhone((string) $ag['phone_any']) === $phoneDigits) { $sameAgent = (int) $ag['id']; break; }
    }
?>
<div class="panel" id="detail">
  <h2><svg class="a-ic"><use href="#a-id-card"/></svg><span class="mono"><?= $h($open['ref']) ?></span> · <?= $h($open['name']) ?> <span style="margin-left:auto"><?= $statusPill((string) $open['status']) ?></span></h2>
  <div class="panel-body" style="padding:16px 18px">
    <div class="agr-head">
      <?php if (!empty($open['photo']) && str_starts_with((string) $open['photo'], 'data:image/')): ?>
        <?php if ($canKyc): ?>
        <img class="agr-photo" src="<?= $h($open['photo']) ?>" alt="Photo of <?= $h($open['name']) ?>">
        <?php else: ?>
        <div class="agr-nophoto">Photo sent — shown to a manager only</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="agr-nophoto">No photo sent — take one at the office</div>
      <?php endif; ?>
      <dl class="kv-list">
        <dt>Kind</dt><dd><?= $isOrg ? '🏢 Organization partner' : '👤 Individual agent' ?></dd>
        <dt>Mobile</dt><dd class="mono"><a href="tel:<?= $h($telHref) ?>"><?= $h($open['phone']) ?></a><?php foreach ($waLinks as [$waUrl, $waLabel]): ?> · <a href="<?= $h($waUrl) ?>" target="_blank" rel="noopener"><?= $h($waLabel) ?></a><?php endforeach; ?><?php if (!empty($open['phone_raw']) && preg_replace('/\D/', '', (string) $open['phone_raw']) !== $phoneDigits): ?> <span class="muted text-xs">(typed: <?= $h($open['phone_raw']) ?>)</span><?php endif; ?></dd>
        <?php if (!empty($open['repeat_of_ref'])): ?><dt>Sent before</dt><dd>The same mobile sent <a href="<?= $h($keep(['id' => (int) $open['duplicate_of']])) ?>#detail" class="mono"><?= $h($open['repeat_of_ref']) ?></a> in the 24 hours before this one — compare the two (a corrected form, or somebody else using this number).</dd><?php endif; ?>
        <dt>Email</dt><dd><?= $h($open['email'] ?: '—') ?></dd>
        <dt>Work area</dt><dd><?= $h($open['area'] ?: '—') ?></dd>
        <?php /* 18 Oct 2026: the simple online form's document photo, finger signature and phone location */ ?>
        <?php if (!empty($open['geo']) && preg_match('/^(-?[\d.]+),(-?[\d.]+)(?:,(\d+))?$/', (string) $open['geo'], $gm) === 1): ?>
        <dt>Location</dt><dd><a href="https://www.google.com/maps?q=<?= $h($gm[1] . ',' . $gm[2]) ?>" target="_blank" rel="noopener">📍 <?= $h($gm[1] . ', ' . $gm[2]) ?></a><?= isset($gm[3]) && $gm[3] !== '' ? ' <span class="muted text-xs">(± ' . $h($gm[3]) . ' m, from the phone)</span>' : '' ?></dd>
        <?php endif; ?>
        <?php foreach (['doc_photo' => 'ID document', 'signature' => 'Signature'] as $imgKey => $imgLabel): ?>
          <?php if (!empty($open[$imgKey]) && str_starts_with((string) $open[$imgKey], 'data:image/')): ?>
          <dt><?= $h($imgLabel) ?></dt><dd>
            <?php if ($canKyc): ?>
            <a href="<?= $h($open[$imgKey]) ?>" target="_blank" rel="noopener" download="<?= $h($open['ref'] . '-' . $imgKey) ?>"><img src="<?= $h($open[$imgKey]) ?>" alt="<?= $h($imgLabel) ?> of <?= $h($open['name']) ?>" style="max-width:260px;max-height:<?= $imgKey === 'signature' ? '110' : '200' ?>px;border:1px solid var(--line);border-radius:10px;background:#fff;display:block"></a>
            <?php else: ?>
            <span class="muted">Sent — shown to a manager only</span>
            <?php endif; ?>
          </dd>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!empty($open['doc_verify'])): $dvPass = str_starts_with((string) $open['doc_verify'], 'PASS'); ?>
        <dt>Document check</dt><dd><span style="font-weight:700;color:<?= $dvPass ? 'var(--ok,#0f9d6b)' : 'var(--bad,#c62828)' ?>"><?= $dvPass ? '✅ Name + number match the card' : '⚠️ Not matched' ?></span> <span class="muted text-xs"><?= $h((string) $open['doc_verify']) ?></span></dd>
        <?php elseif (!empty($open['doc_photo'])): ?>
        <dt>Document check</dt><dd><span class="muted">Not checked — compare the name and number on the photo by hand</span></dd>
        <?php endif; ?>
        <dt>Sent on</dt><dd><?= $h(date('d M Y, H:i', (int) strtotime((string) $open['created_at']))) ?> · form in <?= $h(['ne' => 'Nepali', 'hi' => 'Hindi', 'en' => 'English'][(string) $open['lang']] ?? $open['lang']) ?></dd>
        <dt>Public list</dt><dd><?= (int) $open['public_ok'] === 1 ? 'Agreed — the full phone may be shown once approved' : 'Not agreed — the phone is shown masked (98•••••123) once approved' ?></dd>
        <dt>KYC · agreement</dt><dd><?= $kycPill((string) $open['kyc_status']) ?> <?= $agrPill((string) $open['agreement_status'], $open['agreement_end'] ?? null) ?></dd>
        <?php if (!empty($open['agreement_signed_at'])): ?><dt>Signed · ends</dt><dd><?= $h($fmtDate($open['agreement_signed_at'])) ?> → <?= $h($fmtDate($open['agreement_end'])) ?></dd><?php endif; ?>
        <dt>Agent account</dt><dd><?php if (!empty($open['admin_id'])): ?><a href="agent-360.php?agent=<?= (int) $open['admin_id'] ?>">Open Agent 360 (#<?= (int) $open['admin_id'] ?>)</a><?php else: ?><span class="muted">Not linked yet</span><?php endif; ?></dd>
        <?php if (!empty($open['note'])): ?><dt>Office note</dt><dd><?= $h($open['note']) ?></dd><?php endif; ?>
        <?php if (!empty($open['reviewed_at'])): ?><dt>Last change</dt><dd class="muted"><?= $h(date('d M Y, H:i', (int) strtotime((string) $open['reviewed_at']))) ?></dd><?php endif; ?>
      </dl>
    </div>

    <?php $masked = false;
    foreach ($groups as $gName => $names):
        $rows = array_values(array_filter($names, static fn($n) => isset($f[$n]) && (string) $f[$n] !== ''));
        if ($rows === []) { continue; } ?>
      <div class="agr-sec"><?= $h($gName) ?></div>
      <dl class="kv-list">
        <?php foreach ($rows as $n):
            $isMasked = !$canKyc && AgentApplications::isKycField($n, (string) $open['type']);
            $masked = $masked || $isMasked; ?>
          <dt><?= $h(AgentApplications::FIELD_LABELS[$n] ?? $n) ?></dt><dd<?= $isMasked ? ' class="agr-masked"' : '' ?>><?= nl2br($h(AgentApplications::officeValue($n, (string) $f[$n], (string) $open['type'], $canKyc))) ?></dd>
        <?php endforeach; ?>
      </dl>
    <?php endforeach; ?>
    <?php if ($masked): ?><p class="muted text-xs" style="margin:10px 0 0">ID, PAN, bank details, date of birth and home address are shown in full to a super-admin or a manager only.</p><?php endif; ?>

    <div class="agr-act">
    <?php if ($canEdit): ?>
      <form method="post" action="agreements.php?tab=apps&amp;id=<?= (int) $open['id'] ?>">
        <?= $csrfField ?>
        <input type="hidden" name="action" value="app"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
        <div class="form-grid">
          <div class="field"><label for="agrStatus">Status</label>
            <select id="agrStatus" name="status" class="inp">
              <?php foreach (AgentApplications::STATUS_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $open['status'] === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="agrKyc">KYC</label>
            <select id="agrKyc" name="kyc_status" class="inp">
              <?php foreach (AgentApplications::KYC_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $open['kyc_status'] === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
            </select>
            <span class="help">Verified = you saw the original ID and it matches.</span></div>
          <div class="field"><label for="agrAgr">Agreement</label>
            <select id="agrAgr" name="agreement_status" class="inp">
              <?php foreach (AgentApplications::AGREEMENT_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $open['agreement_status'] === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
            </select></div>
          <div class="field"><label for="agrSigned">Signed on</label>
            <input id="agrSigned" type="date" name="agreement_signed_at" class="inp" value="<?= $h($open['agreement_signed_at'] ?? '') ?>" max="<?= $h(date('Y-m-d', strtotime('+1 day'))) ?>">
            <span class="help">The end date is set to signed + 11 months. Blank with "Signed" = today.</span></div>
          <div class="field"><label for="agrLink">Agent account</label>
            <select id="agrLink" name="admin_id" class="inp">
              <option value="0">— not linked —</option>
              <?php foreach ($agents as $ag): ?>
                <option value="<?= (int) $ag['id'] ?>"<?= (int) ($open['admin_id'] ?? 0) === (int) $ag['id'] ? ' selected' : '' ?>><?= $h(($ag['full_name'] ?: $ag['username']) . ' · ' . ($ag['phone_any'] ?: 'no phone') . ((int) $ag['is_active'] === 1 ? '' : ' (inactive)') . ($sameAgent === (int) $ag['id'] ? ' ← same phone' : '')) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="help">Make the account on <a href="staff.php#addStaff">Staff &amp; Agents</a> first, then pick it here. The person's public-list choices carry over only to an account with the same mobile (← same phone).</span></div>
          <div class="field" style="grid-column:1/-1"><label for="agrNote">Office note</label>
            <input id="agrNote" name="note" class="inp" maxlength="255" value="<?= $h($open['note'] ?? '') ?>" placeholder="e.g. called on 28 Sep, coming Monday with the original citizenship"></div>
        </div>
        <div class="form-actions" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn" type="submit"><svg class="a-ic"><use href="#a-check"/></svg>Save</button>
          <a class="btn ghost" href="<?= $h($keep()) ?>">Close</a>
        </div>
      </form>
    <?php else: ?>
      <p class="muted" style="margin:0">Only a super-admin or a manager can change the status, KYC or agreement. <a href="<?= $h($keep()) ?>">Close</a></p>
    <?php endif; ?>
    </div>
  </div>
</div>

<?php /* 19 Oct 2026 (owner): the website card - name, photo, address, number, WhatsApp - and the KYC / bank edit */
  $isLive = AgentApplications::isLive($open);
  $waText = rawurlencode('Namaste ' . $open['name'] . ' ji, S Hari Global office bata. Tapaiko agent form (' . $open['ref'] . ') ko barema: ');
?>
<div class="panel" id="web">
  <h2><svg class="a-ic"><use href="#a-globe"/></svg>Website card &amp; KYC
    <span style="margin-left:auto"><?= $isLive ? '<span class="pill st-ok">🟢 LIVE on website</span>' : '<span class="pill st-muted">⚪ Not on website</span>' ?></span></h2>
  <div class="panel-body" style="padding:16px 18px">
    <div class="agr-card-prev">
      <?php if (!empty($open['photo']) && (int) ($open['web_photo'] ?? 0) === 1): ?><img src="<?= $h($open['photo']) ?>" alt=""><?php else: ?><span class="agr-ini"><?= $h(mb_strtoupper(mb_substr(trim((string) $open['name']), 0, 1, 'UTF-8'), 'UTF-8')) ?></span><?php endif; ?>
      <div style="min-width:0;flex:1"><b><?= $h($open['name']) ?></b>
        <div class="muted text-xs"><?= $h(trim(($open['area'] ?? '') . (!empty($open['web_addr']) ? ' · ' . $open['web_addr'] : ''), ' ·') ?: '—') ?></div>
        <div class="muted text-xs mono"><?= (int) $open['public_ok'] === 1 ? $h($open['phone']) . ' · WhatsApp + call buttons' : $h(AgentApplications::maskPhone((string) $open['phone'])) . ' · number hidden' ?></div></div>
    </div>
    <p class="muted text-xs" style="margin:8px 0 0"><?php if ($isLive): ?>This is how the card looks on the home page (“Our agents”) and on /agreements/.<?php elseif ((string) $open['status'] !== 'approved'): ?>Goes on the website once the status is <b>Approved</b> and “Live on website” is ticked.<?php else: ?>Approved, but “Live on website” is off — hidden from the public list.<?php endif; ?>
      <?php foreach ($waLinks as [$waUrl, $waLabel]): ?> · <a href="<?= $h($waUrl . '?text=' . $waText) ?>" target="_blank" rel="noopener">💬 <?= $h($waLabel) ?> message</a><?php endforeach; ?></p>

    <?php if ($canEdit): ?>
    <?php if (!$isLive): ?>
    <form method="post" action="agreements.php?tab=apps&amp;id=<?= (int) $open['id'] ?>" style="margin:12px 0 0">
      <?= $csrfField ?><input type="hidden" name="action" value="golive"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
      <button class="btn" type="submit" style="background:#0f9d6b;border-color:#0f9d6b">✅ Approve &amp; put live on website</button>
    </form>
    <?php endif; ?>
    <?php if (!$webReady): ?><div class="flash bad" style="margin-top:12px">Run <code>database/upgrade-2026-10-19-agent-website-card.sql</code> once for the photo, address and live switch.</div><?php endif; ?>
    <form method="post" enctype="multipart/form-data" action="agreements.php?tab=apps&amp;id=<?= (int) $open['id'] ?>" style="margin-top:14px">
      <?= $csrfField ?><input type="hidden" name="action" value="card"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
      <div class="agr-sec">On the website</div>
      <div class="form-grid">
        <div class="field"><label for="cName">Name</label><input id="cName" name="name" class="inp" maxlength="160" required value="<?= $h($open['name']) ?>"></div>
        <div class="field"><label for="cPhone">Mobile / WhatsApp</label><input id="cPhone" name="phone" class="inp" type="tel" maxlength="20" required value="<?= $h(($open['phone_raw'] ?? '') ?: $open['phone']) ?>"><span class="help">Type +977 or +91 in front so WhatsApp opens the right country.</span></div>
        <div class="field"><label for="cArea">Area / city</label><input id="cArea" name="area" class="inp" maxlength="160" value="<?= $h($open['area'] ?? '') ?>"></div>
        <div class="field"><label for="cAddr">Shop / office address (public)</label><input id="cAddr" name="web_addr" class="inp" maxlength="200" value="<?= $h($open['web_addr'] ?? '') ?>" placeholder="e.g. Bus Park Road, Nepalgunj-4"><span class="help">Shown on the website. Not the home address from KYC.</span></div>
        <div class="field"><label for="cEmail">Email</label><input id="cEmail" name="email" class="inp" type="email" maxlength="160" value="<?= $h($open['email'] ?? '') ?>"></div>
        <div class="field"><label for="cPhoto">New photo</label><input id="cPhoto" name="photo" class="inp" type="file" accept="image/*"><span class="help">Optional — replaces the photo. Shrunk to 640 px.</span></div>
        <div class="field" style="grid-column:1/-1;display:flex;gap:4px 18px;flex-wrap:wrap">
          <label class="agr-check"><input type="checkbox" name="web_live" value="1"<?= (int) ($open['web_live'] ?? 1) === 1 ? ' checked' : '' ?>> 🟢 Live on website</label>
          <label class="agr-check"><input type="checkbox" name="web_photo" value="1"<?= (int) ($open['web_photo'] ?? 0) === 1 ? ' checked' : '' ?>> 📷 Show photo</label>
          <label class="agr-check"><input type="checkbox" name="public_ok" value="1"<?= (int) $open['public_ok'] === 1 ? ' checked' : '' ?>> 📞 Show full number + WhatsApp</label>
        </div>
      </div>
      <?php if ($canKyc): $f = is_array($open['fields'] ?? null) ? $open['fields'] : []; ?>
      <div class="agr-sec">KYC &amp; bank account (office only — never on the website)</div>
      <div class="form-grid">
        <div class="field" style="grid-column:1/-1"><label for="kAddr">Permanent address</label><input id="kAddr" name="p_addr" class="inp" maxlength="300" value="<?= $h($f['p_addr'] ?? '') ?>"></div>
        <div class="field"><label for="kIdt">ID type</label><select id="kIdt" name="p_idtype" class="inp"><option value="">—</option><?php foreach (AgentApplications::ID_TYPES as $i => $l): ?><option value="<?= $i ?>"<?= isset($f['p_idtype']) && (string) $f['p_idtype'] === (string) $i ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="kIdn">ID number</label><input id="kIdn" name="p_idno" class="inp mono" maxlength="40" value="<?= $h($f['p_idno'] ?? '') ?>"></div>
        <div class="field"><label for="kPan">PAN</label><input id="kPan" name="p_pan" class="inp mono" maxlength="20" value="<?= $h($f['p_pan'] ?? '') ?>"></div>
        <div class="field"><label for="kBn">Account holder name</label><input id="kBn" name="b_name" class="inp" maxlength="80" value="<?= $h($f['b_name'] ?? '') ?>"></div>
        <div class="field"><label for="kBb">Bank &amp; branch</label><input id="kBb" name="b_bank" class="inp" maxlength="80" value="<?= $h($f['b_bank'] ?? '') ?>"></div>
        <div class="field"><label for="kBa">Account number</label><input id="kBa" name="b_acc" class="inp mono" maxlength="40" inputmode="numeric" value="<?= $h($f['b_acc'] ?? '') ?>"></div>
        <div class="field"><label for="kBi">IFSC / SWIFT</label><input id="kBi" name="b_ifsc" class="inp mono" maxlength="20" value="<?= $h($f['b_ifsc'] ?? '') ?>"></div>
        <div class="field"><label for="kBu">UPI / eSewa / Khalti</label><input id="kBu" name="b_upi" class="inp" maxlength="80" value="<?= $h($f['b_upi'] ?? '') ?>"></div>
      </div>
      <?php endif; ?>
      <div class="form-actions" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
        <button class="btn" type="submit"><svg class="a-ic"><use href="#a-check"/></svg>Save card<?= $canKyc ? ' &amp; KYC' : '' ?></button>
      </div>
    </form>
    <form method="post" action="agreements.php?tab=apps" style="margin-top:18px;border-top:1px solid var(--line);padding-top:14px" onsubmit="return confirm('Remove <?= $h($open['ref']) ?> (<?= $h(addslashes((string) $open['name'])) ?>) for good? It goes off the website and its KYC is deleted.')">
      <?= $csrfField ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $open['id'] ?>">
      <button class="btn ghost sm" type="submit" style="color:var(--bad,#c62828)">🗑 Remove this agent / application</button>
      <span class="muted text-xs">To only hide from the website, untick “Live on website” instead.</span>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php elseif ($openId > 0): ?>
  <div class="flash bad">That application was not found.</div>
<?php endif; ?>

<?php if ($canEdit && $ready): /* 19 Oct 2026: the office adds an agent by hand - straight to Approved */ ?>
<details class="panel agr-add" id="add"<?= $openId === 0 && isset($_GET['add']) ? ' open' : '' ?>>
  <summary><svg class="a-ic"><use href="#a-plus"/></svg><b>Add agent</b> <span class="muted text-xs">— came to the office or phoned in; goes live on the website at once</span></summary>
  <form method="post" enctype="multipart/form-data" action="agreements.php?tab=apps" style="padding:4px 18px 18px">
    <?= $csrfField ?><input type="hidden" name="action" value="add">
    <div class="form-grid">
      <div class="field"><label for="nName">Name *</label><input id="nName" name="name" class="inp" maxlength="160" required></div>
      <div class="field"><label for="nPhone">Mobile / WhatsApp *</label><input id="nPhone" name="phone" class="inp" type="tel" maxlength="20" required placeholder="+977 98XXXXXXXX"></div>
      <div class="field"><label for="nType">Kind</label><select id="nType" name="type" class="inp"><option value="ind">👤 Individual agent</option><option value="org">🏢 Organization partner</option></select></div>
      <div class="field"><label for="nArea">Area / city</label><input id="nArea" name="area" class="inp" maxlength="160" placeholder="e.g. Nepalgunj"></div>
      <div class="field"><label for="nAddr">Shop / office address (public)</label><input id="nAddr" name="web_addr" class="inp" maxlength="200"></div>
      <div class="field"><label for="nPhoto">Photo</label><input id="nPhoto" name="photo" class="inp" type="file" accept="image/*"></div>
      <?php if ($canKyc): ?>
      <div class="field" style="grid-column:1/-1"><label for="nPAddr">Permanent address (KYC)</label><input id="nPAddr" name="p_addr" class="inp" maxlength="300"></div>
      <div class="field"><label for="nIdt">ID type</label><select id="nIdt" name="p_idtype" class="inp"><option value="">—</option><?php foreach (AgentApplications::ID_TYPES as $i => $l): ?><option value="<?= $i ?>"><?= $h($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="nIdn">ID number</label><input id="nIdn" name="p_idno" class="inp mono" maxlength="40"></div>
      <div class="field"><label for="nBn">Account holder</label><input id="nBn" name="b_name" class="inp" maxlength="80"></div>
      <div class="field"><label for="nBb">Bank &amp; branch</label><input id="nBb" name="b_bank" class="inp" maxlength="80"></div>
      <div class="field"><label for="nBa">Account number</label><input id="nBa" name="b_acc" class="inp mono" maxlength="40" inputmode="numeric"></div>
      <div class="field"><label for="nBi">IFSC / SWIFT</label><input id="nBi" name="b_ifsc" class="inp mono" maxlength="20"></div>
      <div class="field"><label for="nBu">UPI / eSewa / Khalti</label><input id="nBu" name="b_upi" class="inp" maxlength="80"></div>
      <div class="field"><label for="nKyc">KYC</label><select id="nKyc" name="kyc_status" class="inp"><?php foreach (AgentApplications::KYC_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $v === 'none' ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?></select><span class="help">Verified = you saw the original ID.</span></div>
      <?php endif; ?>
      <div class="field" style="grid-column:1/-1;display:flex;gap:4px 18px;flex-wrap:wrap">
        <label class="agr-check"><input type="checkbox" name="web_live" value="1" checked> 🟢 Live on website</label>
        <label class="agr-check"><input type="checkbox" name="web_photo" value="1" checked> 📷 Show photo</label>
        <label class="agr-check"><input type="checkbox" name="public_ok" value="1" checked> 📞 Show full number + WhatsApp</label>
      </div>
    </div>
    <div class="form-actions" style="margin-top:12px"><button class="btn" type="submit"><svg class="a-ic"><use href="#a-plus"/></svg>Add agent</button></div>
  </form>
</details>
<?php endif; ?>

<form class="toolbar" method="get" action="agreements.php" role="search">
  <input type="hidden" name="tab" value="apps">
  <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search name / mobile / AGR number / area" aria-label="Search applications" style="flex:1 1 240px">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <option value="open"<?= $fStat === 'open' ? ' selected' : '' ?>>Still open (not approved / rejected)</option>
    <?php foreach (AgentApplications::STATUS_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $fStat === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
  </select>
  <select name="type" aria-label="Kind">
    <option value="">Partners and agents</option>
    <option value="org"<?= $fType === 'org' ? ' selected' : '' ?>>Organization partners</option>
    <option value="ind"<?= $fType === 'ind' ? ' selected' : '' ?>>Individual agents</option>
  </select>
  <button class="btn" type="submit"><svg class="a-ic"><use href="#a-search"/></svg>Search</button>
  <?php if ($q !== '' || $fStat !== '' || $fType !== ''): ?><a class="btn ghost" href="agreements.php?tab=apps">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-list"/></svg>Applications <span class="muted text-xs" style="font-weight:600;margin-left:auto"><?= count($apps) ?> shown</span></h2>
  <?php if ($apps === []): ?>
    <?= admin_empty($q !== '' || $fStat !== '' || $fType !== '' ? 'Nothing matches this search' : 'No applications yet', 'People apply from the agreement pages on the website (/agreements/).', '📝') ?>
  <?php else: ?>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Ref</th><th>Date</th><th>Kind</th><th>Name</th><th>Mobile</th><th>Area</th><th>Status</th><th>KYC</th><th>Agreement</th><th>Website</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($apps as $a): ?>
      <tr class="<?= (int) $a['id'] === $openId ? 'agr-row-open' : '' ?>">
        <td class="mono" style="white-space:nowrap"><b><?= $h($a['ref']) ?></b></td>
        <td style="white-space:nowrap"><?= $h(date('d M Y', (int) strtotime((string) $a['created_at']))) ?></td>
        <td style="white-space:nowrap"><?= (string) $a['type'] === 'org' ? '🏢 Partner' : '👤 Agent' ?></td>
        <td><b><?= $h($a['name']) ?></b><?= (int) $a['has_photo'] === 1 ? ' <span title="Photo sent">📷</span>' : '' ?><?php if (!empty($a['repeat_of_ref'])): ?><span class="agr-repeat" title="The same mobile applied in the 24 hours before">↺ again after <?= $h($a['repeat_of_ref']) ?></span><?php endif; ?><?php if (!empty($a['admin_name'])): ?><div class="muted text-xs">↳ <?= $h($a['admin_name']) ?></div><?php endif; ?></td>
        <td class="mono" style="white-space:nowrap"><?= $h($a['phone']) ?></td>
        <td><?= $h($a['area'] ?: '—') ?></td>
        <td><?= $statusPill((string) $a['status']) ?></td>
        <td><?= $kycPill((string) $a['kyc_status']) ?></td>
        <td><?= $agrPill((string) $a['agreement_status'], $a['agreement_end'] ?? null) ?></td>
        <td style="white-space:nowrap"><?= AgentApplications::isLive($a) ? '<span class="pill st-ok">🟢 Live</span>' : '<span class="muted">—</span>' ?></td>
        <td><a class="btn ghost sm" href="<?= $h($keep(['id' => (int) $a['id']])) ?>#detail"><svg class="a-ic"><use href="#a-eye"/></svg>Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php else: /* ---------------- tab 2: existing agents & partners ---------------- */ ?>

<p class="muted" style="margin:0 0 12px;font-size:13px">KYC documents are checked on each agent's <b>Agent 360</b> page (tap the name). Here: the agreement and what the public list may show. Nobody is on the public list until you tick <b>Listed</b> — tick it only for real agents and partners who agreed. The list shows only the name, kind, area and phone — the phone masked (98•••••123) unless "Full" is ticked under Phone shown. An account with no mobile number is never listed.</p>
<div class="panel">
  <h2><svg class="a-ic"><use href="#a-users"/></svg>Existing agents &amp; partners</h2>
  <?php if ($agents === []): ?>
    <?= admin_empty('No agent accounts yet', 'Add one on Staff & Agents.', '👥') ?>
  <?php else: ?>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Name</th><th>Mobile</th><th>KYC</th><th>Agreement</th><th>Signed on</th><th>Public list</th><th>Phone shown</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($agents as $ag):
        $fid = 'agf' . (int) $ag['id'];
        $inactive = (int) $ag['is_active'] !== 1; ?>
      <tr id="ag<?= (int) $ag['id'] ?>"<?= $inactive ? ' style="opacity:.7"' : '' ?>>
<?php $agName = (string) ($ag['full_name'] ?: $ag['username']);
              $cp = trim((string) ($ag['contact_person'] ?? ''));
              $sub = ($ag['kind'] === 'partner' ? '🏢 Partner' : '👤 Agent') . (!empty($ag['counter_name']) ? ' · ' . $ag['counter_name'] : ''); ?>
        <td style="min-width:150px"><a href="agent-360.php?agent=<?= (int) $ag['id'] ?>"><b><?= $h($agName) ?></b></a><?php if ($inactive): ?> <span class="pill st-muted">Inactive</span><?php endif; ?>
          <div class="muted text-xs"><?= $h($sub) ?></div>
          <?php if ($ag['kind'] === 'partner' && $cp !== '' && strcasecmp($cp, $agName) !== 0): ?><div class="muted text-xs">Contact: <?= $h($cp) ?></div><?php endif; ?></td>
        <td class="mono" style="white-space:nowrap"><?= $h($ag['phone_any'] ?: '—') ?></td>
        <td><?= $kycPill((string) $ag['kyc_status']) ?></td>
        <?php if ($canEdit): ?>
          <td><select class="agr-mini" name="agreement_status" form="<?= $fid ?>" aria-label="Agreement">
            <?php foreach (AgentApplications::AGREEMENT_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $ag['agreement_status'] === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
          </select><?php if (!empty($ag['agreement_end'])): ?><div class="agr-end">till <?= $h($fmtDate($ag['agreement_end'])) ?></div><?php endif; ?></td>
          <td><input class="agr-mini" type="date" name="agreement_signed_at" form="<?= $fid ?>" value="<?= $h($ag['agreement_signed_at'] ?? '') ?>" aria-label="Signed on"></td>
          <td><label class="agr-check"><input type="checkbox" name="public_listing" value="1" form="<?= $fid ?>"<?= (int) $ag['public_listing'] === 1 ? ' checked' : '' ?>> Listed</label></td>
          <td><label class="agr-check"><input type="checkbox" name="public_phone" value="1" form="<?= $fid ?>"<?= (int) $ag['public_phone'] === 1 ? ' checked' : '' ?>> Full</label></td>
          <td><form id="<?= $fid ?>" method="post" action="agreements.php?tab=agents" style="margin:0"><?= $csrfField ?><input type="hidden" name="action" value="agent"><input type="hidden" name="admin_id" value="<?= (int) $ag['id'] ?>"><button class="btn sm" type="submit">Save</button></form></td>
        <?php else: ?>
          <td><?= $agrPill((string) $ag['agreement_status'], $ag['agreement_end'] ?? null) ?></td>
          <td><?= $h($fmtDate($ag['agreement_signed_at'] ?? null)) ?></td>
          <td><?= (int) $ag['public_listing'] === 1 ? 'Yes' : 'No' ?></td>
          <td><?= (int) $ag['public_phone'] === 1 ? 'Full' : 'Masked' ?></td>
          <td></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php admin_footer(); ?>
