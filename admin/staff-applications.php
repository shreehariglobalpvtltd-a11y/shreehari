<?php
/**
 * admin/staff-applications.php — Staff applications (22 Oct 2026).
 *
 * The office side of /agreements/staff.html: drivers, conductors, helpers,
 * office, accountants, mechanics, cleaners and guards who applied online.
 *
 *   Top cards   pending · under review · approved (on the website) ·
 *               rejected / suspended · waiting more than 7 days
 *   List        pending first; filters by status, job and district; search
 *               by name / mobile / STF number. Each row: face, name, mobile
 *               (✓ = proven with the WhatsApp code), job, the document-check
 *               score (green pass / amber partial / red fail / grey not yet),
 *               date, and for an approved person the website switch.
 *   Drawer      ?id=… opens one application: the three big decisions
 *               (Approve · Request more info on WhatsApp · Reject with a
 *               reason), the documents side by side (ID front, ID back,
 *               selfie, licence, certificates, signature), every field, the
 *               document-check JSON, the agreement signatures and an office
 *               note. Suspend / exit / reopen sit under "More".
 *
 * Who: looking needs drivers.view (super-admin, manager, operations). The
 * documents, the full ID number and the bank details only for a super-admin
 * or a manager (as in Admin -> Agreements), and each opening is audited; the
 * files themselves stream through admin/staff-file.php on 15-minute signed
 * links. Decisions need staff.manage, POST + CSRF, and every one is written
 * to the audit trail and WhatsApped to the applicant (StaffApps::decide).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/teamportal.php';
$admin = admin_boot('drivers.view');

$role      = (string) ($admin['role'] ?? '');
$selfId    = (int) ($admin['id'] ?? 0);
$canKyc    = Auth::isSuperadmin() || $role === 'manager';
$canDecide = Auth::isSuperadmin() || Auth::can('staff.manage');
$h         = static fn($v): string => Security::e((string) ($v ?? ''));
$ready     = StaffApps::available();
$flash     = null;

$q     = trim(Security::clean($_GET['q'] ?? '', 80));
$fStat = (string) ($_GET['status'] ?? '');
$fRole = (string) ($_GET['role'] ?? '');
$fDist = trim(Security::clean($_GET['district'] ?? '', 80));
$keep  = static function (array $extra = []) use ($q, $fStat, $fRole, $fDist): string {
    $p = array_filter(['q' => $q, 'status' => $fStat, 'role' => $fRole, 'district' => $fDist], static fn($v) => $v !== '');
    $p = array_merge($p, $extra);
    return 'staff-applications.php' . ($p !== [] ? '?' . http_build_query($p) : '');
};

/* ---------- actions (POST + CSRF + staff.manage) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $ready) {
    $act = (string) ($_POST['action'] ?? '');
    $id  = (int) ($_POST['id'] ?? 0);
    if (!Security::verifyCsrf()) {
        http_response_code(419);
        $flash = ['bad', 'This page was open too long — nothing was saved. Please try again.'];
    } elseif (!$canDecide) {
        http_response_code(403);
        $flash = ['bad', 'Only a super-admin, a manager or operations can decide staff applications.'];
    } else {
        try {
            if ($act === 'decide') {
                $do = (string) ($_POST['do'] ?? '');
                $r  = StaffApps::decide($id, $do, $selfId, [
                    'reason'   => (string) ($_POST['reason'] ?? ''),
                    'template' => (string) ($_POST['template'] ?? ''),
                    'note'     => (string) ($_POST['note'] ?? ''),
                ]);
                // Not delivered by the API: the office gets a tap-to-send link on the next page.
                $_SESSION['staff_wa_link'] = $r['waLink'];
                Response::redirect('admin/' . $keep(['id' => $id, 'done' => $do, 'wa' => $r['wa'] ? 1 : 0]) . '#detail');
            } elseif ($act === 'web') {
                $order = isset($_POST['display_order']) && is_numeric($_POST['display_order']) ? (int) $_POST['display_order'] : null;
                StaffApps::setWebsite($id, isset($_POST['show']), $order, $selfId,
                    isset($_POST['base_location']) ? (string) $_POST['base_location'] : null);
                Response::redirect('admin/' . $keep(['id' => (int) ($_POST['back'] ?? 0) ?: null, 'done' => 'web']) . (!empty($_POST['back']) ? '#web' : '#row' . $id));
            } elseif ($act === 'recheck') {
                $st = StaffApps::recheck($id);
                Response::redirect('admin/' . $keep(['id' => $id, 'done' => 'recheck', 'dv' => $st]) . '#docs');
            } elseif ($act === 'note') {
                StaffApps::setNote($id, (string) ($_POST['office_note'] ?? ''));
                Response::redirect('admin/' . $keep(['id' => $id, 'done' => 'note']) . '#note');
            } elseif ($act === 'link') {
                // 22 Oct 2026: team portal - a ticket-selling account for this person ('' = unlink)
                TeamPortal::linkAccount($id, (string) ($_POST['account'] ?? ''), $selfId);
                Response::redirect('admin/' . $keep(['id' => $id, 'done' => 'link']) . '#login');
            } elseif ($act === 'pwclear') {
                TeamPortal::clearPassword($id, $selfId);
                Response::redirect('admin/' . $keep(['id' => $id, 'done' => 'pwclear']) . '#login');
            } else {
                $flash = ['bad', 'Unknown action.'];
            }
        } catch (StaffAppError | TeamPortalError $ex) {
            $flash = ['bad', $ex->getMessage()];
        } catch (Throwable $ex) {
            Logger::exception($ex);
            $flash = ['bad', 'That could not be saved. Please try again.'];
        }
    }
}
$waLink = is_string($_SESSION['staff_wa_link'] ?? null) && str_starts_with($_SESSION['staff_wa_link'], 'https://wa.me/') ? $_SESSION['staff_wa_link'] : null;
unset($_SESSION['staff_wa_link']);
if ($flash === null && isset($_GET['done'])) {
    $wa = (string) ($_GET['wa'] ?? '') === '1' ? ' WhatsApp sent to the applicant.'
        : ((string) ($_GET['wa'] ?? '') === '0' ? ' WhatsApp could not send it by itself — ' . ($waLink !== null ? 'tap “Send on WhatsApp” below, or call.' : 'please call the applicant.') : '');
    $flash = ['ok', match ((string) $_GET['done']) {
        'approve' => '✅ Approved.' . $wa, 'reject' => 'Rejected.' . $wa, 'suspend' => 'Suspended.' . $wa,
        'info'    => '📨 Request sent.' . $wa, 'review' => 'Marked under review.', 'exit' => 'Marked as exited — off the website.',
        'reopen'  => 'Reopened as pending.', 'web' => 'Website card saved.', 'note' => 'Note saved.',
        'link'    => 'Ticket-selling account saved.', 'pwclear' => 'Portal password cleared — they make a new one at /team/ with a WhatsApp code.',
        'recheck' => 'Documents read again: ' . Security::clean((string) ($_GET['dv'] ?? ''), 12) . '.',
        default   => 'Saved.',
    }];
}

/* ---------- data ---------- */
$counts = $ready ? StaffApps::counts() : array_fill_keys(array_merge(StaffApps::STATUSES, ['total', 'listed', 'stale']), 0);
$rows   = $ready ? StaffApps::list(['q' => $q, 'status' => $fStat, 'role' => $fRole, 'district' => $fDist]) : [];
$dists  = $ready ? StaffApps::districts() : [];
$openId = (int) ($_GET['id'] ?? 0);
$open   = $ready && $openId > 0 ? StaffApps::find($openId) : null;
if ($open !== null && $canKyc && !isset($_GET['done'])) {
    Logger::audit('staff_app.view', 'staff_application', (string) $open['ref'], null, null, 'Staff application opened (documents + KYC)');
}

$statusPill = static function (string $s) use ($h): string {
    $cls = match ($s) {
        'pending' => 'st-info', 'under_review' => 'st-violet', 'approved' => 'st-ok',
        'rejected' => 'st-bad', 'suspended' => 'st-warn', default => 'st-muted',
    };
    return '<span class="pill ' . $cls . '">' . $h(StaffApps::STATUS_LABELS[$s] ?? ucfirst($s)) . '</span>';
};
$docPill = static function (string $s) use ($h): string {
    [$cls, $txt] = match ($s) {
        'pass'    => ['st-ok', '🟢 Match'],
        'partial' => ['st-warn', '🟠 Partly'],
        'fail'    => ['st-bad', '🔴 No match'],
        default   => ['st-muted', '⚪ Not read'],
    };
    return '<span class="pill ' . $cls . '" title="Document check: name + number on the photo vs typed">' . $h($txt) . '</span>';
};
$initials = static fn(string $n): string => mb_strtoupper(mb_substr(trim($n), 0, 1, 'UTF-8'), 'UTF-8') ?: '?';
$mask = static fn(?string $v): string => $v === null || $v === '' ? '—' : '••••' . mb_substr($v, -4);
$fmtDate = static fn(?string $d): string => $d ? date('d M Y', (int) strtotime($d)) : '—';
$csrfField = Security::csrfField();

admin_header('Staff applications', 'staff-applications');
admin_page_head(
    'Drivers, conductors, helpers and office staff who applied online — check the documents, decide, and choose who shows on the website.',
    ['Drivers & Crew' => '/admin/drivers.php', 'Staff applications' => ''],
    '<a class="btn ghost sm" href="/agreements/staff.html" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-external"/></svg>Application form</a>'
    . '<a class="btn ghost sm" href="/agreements/staff-agreement.html" target="_blank" rel="noopener"><svg class="a-ic"><use href="#a-doc"/></svg>Staff agreement</a>'
    . '<a class="btn ghost sm" href="settings.php?q=staff_" ><svg class="a-ic"><use href="#a-cog"/></svg>Switches</a>'
);
if ($flash !== null) {
    echo '<div class="flash ' . $flash[0] . '">' . $h($flash[1])
        . ($waLink !== null ? ' <a class="btn sm" href="' . $h($waLink) . '" target="_blank" rel="noopener">💬 Send on WhatsApp</a>' : '') . '</div>';
}
if (!$ready) {
    echo '<div class="flash bad">The staff applications table is not there yet. Run <code>database/upgrade-2026-10-22-staff-applications.sql</code> once.</div>';
}
?>
<style>
.stf-av{width:40px;height:40px;border-radius:50%;object-fit:cover;display:grid;place-items:center;font-weight:800;color:#fff;background:linear-gradient(140deg,#2f6bff,#0a2a8f);flex:0 0 40px}
.stf-who{display:flex;gap:10px;align-items:center;min-width:180px}
.stf-row-open{background:var(--blue-50)}
.stf-ok{color:var(--ok,#0f9d6b);font-weight:800}
.stf-web{display:flex;gap:6px;align-items:center;margin:0}
.stf-web input[type=checkbox]{width:20px;height:20px}
.stf-shade{position:fixed;inset:0;background:rgba(6,14,40,.45);z-index:80}
.stf-drawer{position:fixed;top:0;right:0;bottom:0;width:min(760px,100%);background:var(--card);z-index:81;overflow-y:auto;box-shadow:-18px 0 40px -20px rgba(0,0,0,.5);border-left:1px solid var(--line)}
.stf-dh{position:sticky;top:0;z-index:2;background:var(--card);border-bottom:1px solid var(--line);padding:14px 18px;display:flex;gap:12px;align-items:center}
.stf-dh img,.stf-dh .stf-av{width:56px;height:56px;flex-basis:56px;font-size:20px}
.stf-dh h2{margin:0;font-size:18px;line-height:1.25}
.stf-x{margin-left:auto;min-width:44px;min-height:44px;display:grid;place-items:center;border-radius:12px;border:1px solid var(--line);text-decoration:none;font-size:20px;color:var(--ink)}
.stf-db{padding:14px 18px 40px}
.stf-sec{margin:20px 0 8px;font-size:12px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:var(--mut)}
/* the three decisions side by side; an opened one (info / reject / more) takes the whole row for its form */
.stf-big{display:flex;flex-wrap:wrap;gap:8px}
.stf-big > form,.stf-big > details{flex:1 1 160px;min-width:0}
.stf-big > details.stf-more{flex-basis:100%}
.stf-big > details[open]{flex-basis:100%;order:2}
.stf-big .btn{width:100%;min-height:52px;justify-content:center;font-size:15px}
.stf-big details>summary{list-style:none;cursor:pointer}
.stf-big details>summary::-webkit-details-marker{display:none}
.stf-drawer .btn.stf-go{background:#0f9d6b!important;background-image:none!important;border-color:#0f9d6b!important;color:#fff!important}
.stf-drawer .btn.stf-no{background:#c62828!important;background-image:none!important;border-color:#c62828!important;color:#fff!important}
.stf-pane{margin-top:8px;padding:12px;border:1px solid var(--line);border-radius:12px;background:var(--soft)}
.stf-pane textarea,.stf-pane select,.stf-pane input[type=text]{width:100%;min-height:44px;padding:8px 10px;border:1px solid var(--line-2);border-radius:10px;background:var(--card);color:var(--ink);font:inherit}
.stf-docs{display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,200px),1fr));gap:10px}
.stf-doc{border:1px solid var(--line);border-radius:12px;padding:8px;background:var(--soft);min-width:0}
.stf-doc b{display:block;font-size:12px;margin-bottom:6px}
.stf-doc img{display:block;width:100%;height:170px;object-fit:contain;background:#fff;border-radius:8px}
.stf-doc .stf-none{height:170px;display:grid;place-items:center;text-align:center;font-size:12px;color:var(--mut);border:1px dashed var(--line-2);border-radius:8px;padding:8px}
.stf-pdf{height:170px;display:grid;place-items:center;border-radius:8px;background:#fff;font-weight:800;text-decoration:none}
.stf-json{max-height:260px;overflow:auto;font-size:12px;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:10px;white-space:pre-wrap;word-break:break-word}
.kv-list dd{min-width:0;overflow-wrap:anywhere}
@media(max-width:620px){.stf-big{grid-template-columns:1fr}.stf-dh{padding:10px 12px}.stf-db{padding:12px}}
</style>

<div class="kpis">
  <?= admin_kpi('Pending', (string) $counts['pending'], $counts['total'] . ' in all', 'user-solo', 'blue', null, $keep(['status' => 'pending'])) ?>
  <?= admin_kpi('Under review', (string) $counts['under_review'], 'waiting for something', 'eye', 'violet', null, $keep(['status' => 'under_review'])) ?>
  <?= admin_kpi('Approved', (string) $counts['approved'], $counts['listed'] . ' on the website', 'check-circle', 'green', null, $keep(['status' => 'approved'])) ?>
  <?= admin_kpi('Rejected / suspended', (string) ($counts['rejected'] + $counts['suspended']), $counts['exited'] . ' exited', 'x-circle', 'orange') ?>
  <?= admin_kpi('Waiting > 7 days', (string) $counts['stale'], 'pending or under review', 'clock', $counts['stale'] > 0 ? 'orange' : 'navy') ?>
</div>

<form class="toolbar" method="get" action="staff-applications.php" role="search">
  <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search name / mobile / STF number" aria-label="Search staff applications" style="flex:1 1 220px">
  <select name="status" aria-label="Status">
    <option value="">All statuses</option>
    <?php foreach (StaffApps::STATUS_LABELS as $v => $l): ?><option value="<?= $h($v) ?>"<?= $fStat === $v ? ' selected' : '' ?>><?= $h($l) ?></option><?php endforeach; ?>
  </select>
  <select name="role" aria-label="Job">
    <option value="">All jobs</option>
    <?php foreach (StaffApps::ROLES as $r): ?><option value="<?= $h($r) ?>"<?= $fRole === $r ? ' selected' : '' ?>><?= $h(StaffApps::roleLabel($r)) ?></option><?php endforeach; ?>
  </select>
  <select name="district" aria-label="District">
    <option value="">All districts</option>
    <?php foreach ($dists as $d): ?><option value="<?= $h($d) ?>"<?= $fDist === $d ? ' selected' : '' ?>><?= $h($d) ?></option><?php endforeach; ?>
  </select>
  <button class="btn" type="submit"><svg class="a-ic"><use href="#a-search"/></svg>Search</button>
  <?php if ($q !== '' || $fStat !== '' || $fRole !== '' || $fDist !== ''): ?><a class="btn ghost" href="staff-applications.php">Clear</a><?php endif; ?>
</form>

<div class="panel">
  <h2><svg class="a-ic"><use href="#a-users"/></svg>Applications <span class="muted text-xs" style="font-weight:600;margin-left:auto"><?= count($rows) ?> shown</span></h2>
  <?php if ($rows === []): ?>
    <?= admin_empty($q !== '' || $fStat !== '' || $fRole !== '' || $fDist !== '' ? 'Nothing matches this search' : 'No staff applications yet', 'People apply on the website at /agreements/staff.html.', '🧑‍✈️') ?>
  <?php else: ?>
  <div class="tbl-scroll">
  <table>
    <thead><tr><th>Applicant</th><th>Mobile</th><th>Job</th><th>District</th><th>Documents</th><th>Status</th><th>Sent</th><th>Website</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): $aid = (int) $a['id']; ?>
      <tr id="row<?= $aid ?>" class="<?= $aid === $openId ? 'stf-row-open' : '' ?>">
        <td><div class="stf-who">
          <?php if ($canKyc && !empty($a['photo_path'])): ?><img class="stf-av" src="<?= $h(StaffApps::fileUrl($aid, 'photo', $selfId, true)) ?>" alt="" loading="lazy" width="40" height="40"><?php else: ?><span class="stf-av"><?= $h($initials((string) $a['full_name'])) ?></span><?php endif; ?>
          <div><b><?= $h($a['full_name']) ?></b><div class="muted text-xs mono"><?= $h($a['ref']) ?></div></div></div></td>
        <td class="mono" style="white-space:nowrap">+<?= $h(countryDialCode((string) $a['phone_country'])) ?> <?= $h($a['phone']) ?><?= (int) $a['phone_verified'] === 1 ? ' <span class="stf-ok" title="Proven with the WhatsApp code">✓</span>' : ' <span class="muted text-xs" title="WhatsApp code not entered">?</span>' ?></td>
        <td style="white-space:nowrap"><?= $h(StaffApps::roleLabel((string) $a['role_applied'])) ?></td>
        <td><?= $h($a['district'] ?: '—') ?><?php if (!empty($a['base_location'])): ?><div class="muted text-xs" title="Works at">🏢 <?= $h($a['base_location']) ?></div><?php endif; ?></td>
        <td><?= $docPill((string) $a['doc_verify_status']) ?></td>
        <td><?= $statusPill((string) $a['status']) ?></td>
        <td style="white-space:nowrap"><?= $h($fmtDate((string) $a['created_at'])) ?></td>
        <td style="white-space:nowrap"><?php if ((string) $a['status'] === 'approved'): ?>
          <?php if ($canDecide): ?><form class="stf-web" method="post" action="<?= $h($keep()) ?>"><?= $csrfField ?><input type="hidden" name="action" value="web"><input type="hidden" name="id" value="<?= $aid ?>"><input type="hidden" name="display_order" value="<?= (int) $a['display_order'] ?>">
            <label class="stf-web" title="Show on the website"><input type="checkbox" name="show" value="1"<?= (int) $a['show_on_website'] === 1 ? ' checked' : '' ?> onchange="this.form.submit()"> <?= (int) $a['show_on_website'] === 1 ? '🟢 On' : 'Off' ?></label></form>
          <?php else: ?><?= (int) $a['show_on_website'] === 1 ? '🟢 On' : 'Off' ?><?php endif; ?>
        <?php else: ?><span class="muted">—</span><?php endif; ?></td>
        <td><a class="btn ghost sm" href="<?= $h($keep(['id' => $aid])) ?>#detail"><svg class="a-ic"><use href="#a-eye"/></svg>Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php if ($open !== null):
    $oid  = (int) $open['id'];
    $st   = (string) $open['status'];
    $cc   = countryDialCode((string) $open['phone_country']) ?: '977';
    $refs = json_decode((string) ($open['references_json'] ?? ''), true);
    $dv   = json_decode((string) ($open['doc_verify_json'] ?? ''), true);
    $signs = StaffApps::signs($oid);
    $age  = !empty($open['dob']) ? (int) (new DateTimeImmutable((string) $open['dob']))->diff(new DateTimeImmutable('today'))->y : null;
    $licExpired = !empty($open['license_expiry']) && (string) $open['license_expiry'] < date('Y-m-d');
    $waMsg = rawurlencode('Namaste ' . $open['full_name'] . ' ji, S Hari Global office bata. Tapaiko staff application (' . $open['ref'] . ') ko barema: ');
    $docCard = static function (string $slot, string $label) use ($open, $oid, $selfId, $canKyc, $h): string {
        $col = StaffApps::slotColumn($slot);
        $rel = $col !== null ? (string) ($open[$col] ?? '') : '';
        $out = '<div class="stf-doc"><b>' . $h($label) . '</b>';
        if ($rel === '') {
            return $out . '<div class="stf-none">Not sent</div></div>';
        }
        if (!$canKyc) {
            return $out . '<div class="stf-none">Sent — shown to a manager only</div></div>';
        }
        $url = StaffApps::fileUrl($oid, $slot, $selfId);
        if (str_ends_with($rel, '.pdf')) {
            return $out . '<a class="stf-pdf" href="' . $h($url) . '" target="_blank" rel="noopener">📄 Open PDF</a></div>';
        }
        return $out . '<a href="' . $h($url) . '" target="_blank" rel="noopener"><img src="' . $h($url) . '" alt="' . $h($label) . '" loading="lazy"></a></div>';
    };
?>
<a class="stf-shade" href="<?= $h($keep()) ?>#row<?= $oid ?>" aria-label="Close"></a>
<aside class="stf-drawer" id="detail" role="dialog" aria-modal="true" aria-labelledby="stfTitle">
  <div class="stf-dh">
    <?php if ($canKyc && !empty($open['photo_path'])): ?><img class="stf-av" src="<?= $h(StaffApps::fileUrl($oid, 'photo', $selfId, true)) ?>" alt=""><?php else: ?><span class="stf-av"><?= $h($initials((string) $open['full_name'])) ?></span><?php endif; ?>
    <div style="min-width:0"><h2 id="stfTitle"><?= $h($open['full_name']) ?></h2>
      <div class="muted text-xs"><span class="mono"><?= $h($open['ref']) ?></span> · <?= $h(StaffApps::roleLabel((string) $open['role_applied'])) ?> · <?= $statusPill($st) ?> <?= $docPill((string) $open['doc_verify_status']) ?>
        · <a href="application-print.php?type=staff&amp;id=<?= $oid ?>" target="_blank" rel="noopener" title="The application as an A4 form with photo, company letterhead and seal — Print or Save as PDF">🖨 A4 / PDF</a></div></div>
    <a class="stf-x" href="<?= $h($keep()) ?>#row<?= $oid ?>" aria-label="Close">✕</a>
  </div>
  <div class="stf-db">
    <?php if ($flash !== null): ?><div class="flash <?= $h($flash[0]) ?>" style="margin:0 0 12px"><?= $h($flash[1]) ?>
      <?php if ($waLink !== null): ?> <a class="btn sm" href="<?= $h($waLink) ?>" target="_blank" rel="noopener">💬 Send on WhatsApp</a><?php endif; ?></div><?php endif; ?>
    <dl class="kv-list">
      <dt>Mobile</dt><dd class="mono"><a href="tel:+<?= $h($cc . $open['phone']) ?>">+<?= $h($cc) ?> <?= $h($open['phone']) ?></a>
        · <a href="https://wa.me/<?= $h($cc . $open['phone']) ?>?text=<?= $h($waMsg) ?>" target="_blank" rel="noopener">💬 WhatsApp</a>
        <?= (int) $open['phone_verified'] === 1 ? '<span class="stf-ok"> ✓ proven with the WhatsApp code</span>' : '<span class="muted text-xs"> — code not entered (WhatsApp did not deliver it); confirm by phone</span>' ?></dd>
      <?php if (!empty($open['whatsapp'])): ?><dt>WhatsApp</dt><dd class="mono"><?= $h($open['whatsapp']) ?></dd><?php endif; ?>
      <dt>Sent</dt><dd><?= $h(date('d M Y, H:i', (int) strtotime((string) $open['created_at']))) ?><?= !empty($open['info_requested_at']) ? ' · more info asked ' . $h($fmtDate((string) $open['info_requested_at'])) : '' ?></dd>
      <?php if (!empty($open['rejected_reason'])): ?><dt>Reason given</dt><dd><?= $h($open['rejected_reason']) ?></dd><?php endif; ?>
    </dl>

    <?php if ($canDecide): ?>
    <div class="stf-sec">Decision</div>
    <div class="stf-big">
      <?php if (in_array($st, ['pending', 'under_review', 'suspended', 'rejected'], true)): ?>
      <form method="post" action="<?= $h($keep(['id' => $oid])) ?>"><?= $csrfField ?><input type="hidden" name="action" value="decide"><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?= $oid ?>">
        <button class="btn stf-go" type="submit">✅ Approve</button></form>
      <?php endif; ?>
      <details>
        <summary class="btn ghost">📨 Request more info</summary>
        <form class="stf-pane" method="post" action="<?= $h($keep(['id' => $oid])) ?>"><?= $csrfField ?><input type="hidden" name="action" value="decide"><input type="hidden" name="do" value="info"><input type="hidden" name="id" value="<?= $oid ?>">
          <label class="text-xs muted" for="stfTpl">WhatsApp message</label>
          <select id="stfTpl" name="template" required><?php foreach (StaffApps::INFO_TEMPLATES as $k => [$lbl, $txt]): ?><option value="<?= $h($k) ?>" title="<?= $h($txt) ?>"><?= $h($lbl) ?></option><?php endforeach; ?></select>
          <label class="text-xs muted" for="stfNote" style="display:block;margin-top:8px">Extra line (optional)</label>
          <input type="text" id="stfNote" name="note" maxlength="200" placeholder="e.g. the back side is cut off">
          <button class="btn" type="submit" style="margin-top:8px">Send on WhatsApp</button>
        </form>
      </details>
      <?php if (in_array($st, ['pending', 'under_review'], true)): ?>
      <details>
        <summary class="btn stf-no">✖ Reject</summary>
        <form class="stf-pane" method="post" action="<?= $h($keep(['id' => $oid])) ?>"><?= $csrfField ?><input type="hidden" name="action" value="decide"><input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="<?= $oid ?>">
          <label class="text-xs muted" for="stfWhy">Reason — the applicant receives it on WhatsApp</label>
          <textarea id="stfWhy" name="reason" minlength="5" maxlength="300" required placeholder="e.g. Licence category does not cover a bus"></textarea>
          <button class="btn stf-no" type="submit" style="margin-top:8px">Reject with this reason</button>
        </form>
      </details>
      <?php endif; ?>
      <details class="stf-more">
        <summary class="btn ghost sm">More…</summary>
        <div class="stf-pane" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
          <?php foreach (['review' => ['👀 Mark under review', ['pending']], 'exit' => ['🚪 Mark as exited', ['approved', 'suspended']], 'reopen' => ['↺ Reopen as pending', ['rejected', 'exited']]] as $do => [$lbl, $from]): ?>
            <?php if (in_array($st, $from, true)): ?>
            <form method="post" action="<?= $h($keep(['id' => $oid])) ?>"><?= $csrfField ?><input type="hidden" name="action" value="decide"><input type="hidden" name="do" value="<?= $h($do) ?>"><input type="hidden" name="id" value="<?= $oid ?>"><button class="btn ghost sm" type="submit"><?= $h($lbl) ?></button></form>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($st === 'approved'): ?>
          <form method="post" action="<?= $h($keep(['id' => $oid])) ?>" style="flex:1 1 100%"><?= $csrfField ?><input type="hidden" name="action" value="decide"><input type="hidden" name="do" value="suspend"><input type="hidden" name="id" value="<?= $oid ?>">
            <input type="text" name="reason" minlength="5" maxlength="300" required placeholder="Suspend — reason (sent on WhatsApp)"><button class="btn ghost sm" type="submit" style="margin-top:6px;color:var(--bad,#c62828)">⏸ Suspend</button></form>
          <?php endif; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>

    <?php if ($canKyc && in_array($st, ['approved', 'suspended', 'exited'], true)): /* 22 Oct 2026: team portal ID card */ ?>
    <div class="stf-sec" id="idcard">ID card</div>
    <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><a class="btn sm" href="id-card.php?ref=<?= $h($open['ref']) ?>">🪪 Print ID card</a>
      <span class="muted text-xs">Photo, name, job, <?= $h($open['ref']) ?>, office and a QR the public can scan<?= $st !== 'approved' ? ' — the QR now says “not valid”' : '' ?>.</span></p>
    <?php endif; ?>

    <?php if (TeamPortal::ready() && in_array($st, ['approved', 'suspended'], true)): /* 22 Oct 2026: team portal login + CV */ ?>
    <div class="stf-sec" id="login">Team portal · login &amp; CV</div>
    <p class="muted text-xs" style="margin:0 0 8px"><?= $h(TeamPortal::loginLine($open)) ?> · signs in at <b>/team/</b> with mobile <?= $h($open['phone']) ?> + password.</p>
    <?php if ($canDecide): ?>
    <form class="stf-pane" method="post" action="<?= $h($keep(['id' => $oid])) ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= $csrfField ?><input type="hidden" name="action" value="link"><input type="hidden" name="id" value="<?= $oid ?>">
      <label class="stf-web">🎫 Sells tickets as <input name="account" maxlength="60" placeholder="agent / counter username or mobile" value="<?= $h(!empty($open['admin_id']) ? (string) Database::scalar('SELECT username FROM admins WHERE id = :i', ['i' => (int) $open['admin_id']], '') : '') ?>" style="width:230px;min-height:40px"></label>
      <button class="btn sm" type="submit">Save</button>
      <span class="muted text-xs" style="flex:1 1 100%">Empty = no selling. A linked account and this staff card open together at /team/ (one mobile, one password).</span>
    </form>
    <?php if (!empty($open['password_hash'])): ?>
    <form method="post" action="<?= $h($keep(['id' => $oid])) ?>" style="margin-top:6px" onsubmit="return confirm('Clear the portal password? They make a new one at /team/ with a WhatsApp code.')"><?= $csrfField ?><input type="hidden" name="action" value="pwclear"><input type="hidden" name="id" value="<?= $oid ?>">
      <button class="btn ghost sm" type="submit">Clear portal password</button></form>
    <?php endif; ?>
    <?php endif; ?>
    <details class="stf-pane" style="margin-top:8px"><summary><b>CV</b> — education &amp; experience</summary><div class="stf-cv"><?= TeamPortal::cvHtml(TeamPortal::cv($open, 'staff'), false) ?></div></details>
    <?php endif; ?>

    <?php if ($st === 'approved'): ?>
    <div class="stf-sec" id="web">Website card</div>
    <?php if ($canDecide): ?>
    <form class="stf-pane" method="post" action="<?= $h($keep()) ?>" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center"><?= $csrfField ?><input type="hidden" name="action" value="web"><input type="hidden" name="id" value="<?= $oid ?>"><input type="hidden" name="back" value="<?= $oid ?>">
      <label class="stf-web"><input type="checkbox" name="show" value="1"<?= (int) $open['show_on_website'] === 1 ? ' checked' : '' ?>> 🟢 Show in “Our team” on the website</label>
      <label class="stf-web">Order <input type="number" name="display_order" value="<?= (int) $open['display_order'] ?>" style="width:80px;min-height:40px" aria-label="Order (lower first)"></label>
      <?php if (StaffApps::locationReady()): ?>
      <label class="stf-web">🏢 Works at <input name="base_location" list="stfLocs" maxlength="60" value="<?= $h($open['base_location'] ?? '') ?>" placeholder="Nepalgunj / Surat …" style="width:170px;min-height:40px" aria-label="Work location"></label>
      <datalist id="stfLocs"><?php foreach (StaffApps::locations() as $lc): ?><option value="<?= $h($lc) ?>"><?php endforeach; ?></datalist>
      <?php endif; ?>
      <button class="btn sm" type="submit">Save</button>
      <span class="muted text-xs" style="flex:1 1 100%">Shows: <?= $h(implode(', ', StaffApps::publicFields())) ?> (Settings → staff_public_fields) + the work location, which groups “Our team” on the home page. Lower order first.</span>
    </form>
    <?php else: ?><p><?= (int) $open['show_on_website'] === 1 ? '🟢 On the website' : 'Not on the website' ?></p><?php endif; ?>
    <?php endif; ?>

    <div class="stf-sec" id="docs">Documents</div>
    <div class="stf-docs">
      <?= $docCard('id_front', 'ID front · ' . (StaffApps::ID_LABELS[(string) $open['id_type']] ?? $open['id_type'])) ?>
      <?= $docCard('id_back', 'ID back') ?>
      <?= $docCard('photo', 'Selfie (compare the face)') ?>
      <?php if ((string) $open['role_applied'] === 'driver' || !empty($open['license_photo_path'])): ?><?= $docCard('license', 'Driving licence') ?><?php endif; ?>
      <?php foreach (['qual_cert' => 'Certificate', 'marksheet' => 'Marksheet', 'exp_cert' => 'Experience letter', 'signature' => 'Signature'] as $slot => $lbl): ?>
        <?php if (!empty($open[StaffApps::slotColumn($slot)])): ?><?= $docCard($slot, $lbl) ?><?php endif; ?>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:10px">
      <?php if (is_array($dv)): foreach ($dv as $doc => $r): ?>
        <span class="text-xs"><b><?= $h($doc === 'id' ? 'ID' : 'Licence') ?>:</b> <?= $h((string) ($r['summary'] ?? (($r['status'] ?? '') . ' ' . ($r['note'] ?? '')))) ?></span>
      <?php endforeach; endif; ?>
      <?php if ($canDecide && StaffApps::docVerifyOn()): ?>
      <form method="post" action="<?= $h($keep(['id' => $oid])) ?>" style="margin-left:auto"><?= $csrfField ?><input type="hidden" name="action" value="recheck"><input type="hidden" name="id" value="<?= $oid ?>"><button class="btn ghost sm" type="submit">🔍 Read documents again</button></form>
      <?php endif; ?>
    </div>
    <?php if ($canKyc && is_array($dv)): ?>
    <details style="margin-top:8px"><summary class="text-xs muted" style="cursor:pointer">Document check — full result (JSON)</summary>
      <pre class="stf-json"><?= $h((string) json_encode($dv, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre></details>
    <?php endif; ?>

    <div class="stf-sec">Person</div>
    <dl class="kv-list">
      <dt>Date of birth</dt><dd><?= $h($fmtDate((string) ($open['dob'] ?? ''))) ?><?= $age !== null ? ' · ' . $age . ' years' : '' ?></dd>
      <dt>Gender</dt><dd><?= $h(ucfirst((string) ($open['gender'] ?? '—'))) ?></dd>
      <dt>Email</dt><dd><?= $h($open['email'] ?: '—') ?></dd>
      <dt>Permanent address</dt><dd><?= $canKyc ? $h($open['address_perm'] ?: '—') : '<span class="muted">manager only</span>' ?></dd>
      <dt>Current address</dt><dd><?= $canKyc ? $h($open['address_current'] ?: 'Same as permanent') : '<span class="muted">manager only</span>' ?></dd>
      <dt>District · province</dt><dd><?= $h(trim(($open['district'] ?? '') . ' · ' . ($open['province'] ?? ''), ' ·') ?: '—') ?> · <?= (string) $open['country'] === 'IN' ? '🇮🇳 India' : '🇳🇵 Nepal' ?></dd>
      <dt>ID</dt><dd><?= $h(StaffApps::ID_LABELS[(string) $open['id_type']] ?? $open['id_type']) ?> · <span class="mono"><?= $canKyc ? $h($open['id_number']) : $h($mask((string) $open['id_number'])) ?></span></dd>
    </dl>

    <div class="stf-sec">Job &amp; experience</div>
    <dl class="kv-list">
      <dt>Applied for</dt><dd><?= $h(StaffApps::roleLabel((string) $open['role_applied'])) ?> · <?= $h(StaffApps::roleLabel((string) $open['role_applied'], true)) ?></dd>
      <dt>Education</dt><dd><?= $h(StaffApps::QUAL_LABELS[(string) ($open['qualification'] ?? '')] ?? '—') ?></dd>
      <dt>Experience</dt><dd><?= (int) $open['experience_years'] ?> years<?= !empty($open['prev_employer']) ? ' · ' . $h($open['prev_employer']) : '' ?><?= !empty($open['prev_role']) ? ' (' . $h($open['prev_role']) . ')' : '' ?></dd>
      <?php if (!empty($open['license_number'])): ?>
      <dt>Driving licence</dt><dd class="mono"><?= $canKyc ? $h($open['license_number']) : $h($mask((string) $open['license_number'])) ?> · category <?= $h($open['license_category'] ?: '—') ?> · valid till <?= $h($fmtDate((string) ($open['license_expiry'] ?? ''))) ?><?= $licExpired ? ' <span class="pill st-bad">EXPIRED</span>' : '' ?></dd>
      <?php endif; ?>
    </dl>

    <div class="stf-sec">References &amp; emergency contact</div>
    <dl class="kv-list">
      <?php foreach (is_array($refs) ? $refs : [] as $i => $rf): ?>
      <dt>Reference <?= $i + 1 ?></dt><dd><?= $h($rf['name'] ?? '') ?><?= !empty($rf['relation']) ? ' (' . $h($rf['relation']) . ')' : '' ?> · <a class="mono" href="tel:<?= $h($rf['phone'] ?? '') ?>"><?= $h($rf['phone'] ?? '') ?></a></dd>
      <?php endforeach; ?>
      <dt>Emergency</dt><dd><?= $h($open['emergency_contact_name'] ?: '—') ?><?= !empty($open['emergency_relation']) ? ' (' . $h($open['emergency_relation']) . ')' : '' ?> · <a class="mono" href="tel:<?= $h($open['emergency_contact_phone'] ?? '') ?>"><?= $h($open['emergency_contact_phone'] ?? '') ?></a></dd>
    </dl>

    <div class="stf-sec">Bank (salary)</div>
    <dl class="kv-list">
      <?php if (empty($open['bank_account']) && empty($open['bank_name'])): ?><dt>Bank</dt><dd class="muted">Not given — take it at joining</dd>
      <?php else: ?>
      <dt>Holder</dt><dd><?= $canKyc ? $h($open['bank_holder'] ?: '—') : '<span class="muted">manager only</span>' ?></dd>
      <dt>Bank · branch</dt><dd><?= $h(trim(($open['bank_name'] ?? '') . ' · ' . ($open['bank_branch'] ?? ''), ' ·') ?: '—') ?></dd>
      <dt>Account</dt><dd class="mono"><?= $canKyc ? $h($open['bank_account'] ?: '—') : $h($mask((string) ($open['bank_account'] ?? ''))) ?></dd>
      <dt>IFSC / SWIFT</dt><dd class="mono"><?= $h($open['bank_ifsc_or_swift'] ?: '—') ?></dd>
      <?php endif; ?>
    </dl>

    <div class="stf-sec">Staff agreement</div>
    <?php if ($signs === []): ?><p class="muted text-xs">No signature on record.</p><?php else: ?>
    <div class="tbl-scroll"><table>
      <thead><tr><th>When</th><th>Event</th><th>Version</th><th>Name</th><th>Mobile</th><th>IP</th></tr></thead>
      <tbody><?php foreach ($signs as $s): ?>
        <tr><td style="white-space:nowrap"><?= $h(date('d M Y, H:i', (int) strtotime((string) $s['signed_at']))) ?></td><td><?= $h($s['event']) ?></td><td>v<?= $h($s['version']) ?></td>
          <td><?= $h($s['full_name']) ?></td><td class="mono"><?= $h($s['phone']) ?><?= (int) $s['phone_verified'] === 1 ? ' <span class="stf-ok">✓</span>' : '' ?></td><td class="mono text-xs"><?= $canKyc ? $h($s['ip_address']) : '—' ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>

    <?php if (!empty($open['biometric_face_hash']) || !empty($open['biometric_webauthn_cred'])): ?>
    <div class="stf-sec">Phase 2 (biometric)</div>
    <dl class="kv-list">
      <dt>Face frame</dt><dd class="mono text-xs"><?= !empty($open['biometric_face_hash']) ? $h(substr((string) $open['biometric_face_hash'], 0, 16)) . '…' : '—' ?></dd>
      <dt>Phone passkey</dt><dd><?= !empty($open['biometric_webauthn_cred']) ? '✓ registered' : '—' ?></dd>
    </dl>
    <?php endif; ?>

    <div class="stf-sec" id="note">Office note</div>
    <?php if ($canDecide): ?>
    <form method="post" action="<?= $h($keep(['id' => $oid])) ?>"><?= $csrfField ?><input type="hidden" name="action" value="note"><input type="hidden" name="id" value="<?= $oid ?>">
      <div class="stf-pane" style="margin:0"><textarea name="office_note" maxlength="500" rows="2" placeholder="Only the office sees this"><?= $h($open['office_note'] ?? '') ?></textarea>
      <button class="btn sm" type="submit" style="margin-top:6px">Save note</button></div></form>
    <?php else: ?><p><?= $h($open['office_note'] ?: '—') ?></p><?php endif; ?>
    <p class="muted text-xs" style="margin-top:16px">Sent from IP <?= $canKyc ? $h($open['ip_address'] ?: '—') : '—' ?>. Every decision is in the activity log and is WhatsApped to the applicant.</p>
  </div>
</aside>
<script>
document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { var x = document.querySelector('.stf-x'); if (x) location.href = x.href; } });
</script>
<?php endif; ?>
<?php admin_footer(); ?>
