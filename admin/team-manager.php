<?php
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/teammanager.php';
require_once INCLUDE_PATH . '/agentreport.php';
$admin = admin_boot('');
if (!TeamManager::allowed($admin)) { http_response_code(403); exit('Only a manager or superadmin can manage the team.'); }
header('Cache-Control: private, no-store');
$h = static fn($v): string => Security::e((string) ($v ?? ''));
$key = Security::clean((string) ($_GET['person'] ?? $_POST['person'] ?? ''), 40);
$flash = null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        http_response_code(419); $flash = ['bad', 'Page expired. Reload before saving.'];
    } else {
        try {
            $r = TeamManager::find($key);
            if ($r === null) { throw new RuntimeException('Choose a person first.'); }
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'archive' || $action === 'restore') {
                TeamManager::archive($key, $action === 'restore', $admin);
            } elseif ($r['archived']) {
                throw new RuntimeException('Restore this record before editing.');
            } elseif ($action === 'pay') {
                TeamManager::compensation((int) $r['admin_id'], $_POST, $admin);
            } elseif ($action === 'details' && $r['kind'] === 'agent') {
                $in = array_intersect_key($_POST, array_flip(array_merge(['name','phone','email','area','web_addr'], AgentApplications::OFFICE_KYC_FIELDS)));
                if (trim((string) ($in['email'] ?? '')) !== '' && Security::email((string) $in['email']) === '') { throw new RuntimeException('Enter a valid email address.'); }
                AgentApplications::saveCard((int) $r['id'], $in, (int) $admin['id'], true);
            } elseif ($action === 'visibility' && $r['kind'] === 'agent') {
                if ($r['status'] !== 'approved') { throw new RuntimeException('Review and approve the application before putting it live.'); }
                AgentApplications::saveCard((int) $r['id'], ['web_live' => isset($_POST['live']) ? 1 : 0], (int) $admin['id'], false);
            } else { throw new RuntimeException('Use the application page for this action.'); }
            Response::redirect('admin/team-manager.php?person=' . rawurlencode($key) . '&saved=1');
        } catch (RuntimeException $ex) { $flash = ['bad', $ex->getMessage()]; }
        catch (Throwable $ex) { Logger::error('Team manager save failed: ' . $ex->getMessage()); $flash = ['bad', 'Nothing could be saved. Please try again.']; }
    }
}
$rows = TeamManager::rows();
$archiveView = (string) ($_GET['view'] ?? '') === 'archive';
$q = mb_strtolower(Security::clean((string) ($_GET['q'] ?? ''), 80));
$kind = (string) ($_GET['kind'] ?? '');
$selected = null;
foreach ($rows as $r) { if ($r['key'] === $key) { $selected = $r; break; } }
$shown = array_values(array_filter($rows, static function ($r) use ($archiveView, $q, $kind) {
    return $r['archived'] === $archiveView && ($kind === '' || $kind === $r['label'])
        && ($q === '' || str_contains(mb_strtolower(implode(' ', [$r['name'],$r['phone'],$r['area'],$r['ref'],$r['code']])), $q));
}));
$csrf = Security::csrfField();
admin_header('Agent / Staff Management', 'team-manager');
admin_page_head('KYC · login · ID / visiting card · commission · salary — एउटै ठाउँबाट व्यवस्थापन।', [],
    '<a class="btn" href="agreements.php?tab=apps&amp;add=1#add">+ Add agent / partner</a> <a class="btn ghost" href="/agreements/staff.html" target="_blank" rel="noopener">+ Staff form</a>');
if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . $h($flash[1]) . '</div>'; }
elseif (isset($_GET['saved'])) { echo '<div class="flash ok">Saved · सुरक्षित भयो।</div>'; }
?>
<style>
.tm-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:12px 0}.tm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:12px}.tm-grid label{display:flex;flex-direction:column;gap:5px;font-weight:600}.tm-muted{color:var(--mut);font-size:13px}.tm-table{overflow:auto}.tm-table th,.tm-table td{padding:12px;text-align:left;vertical-align:middle;border-bottom:1px solid var(--line)}.tm-table table{width:100%;border-collapse:collapse}.tm-table small{display:block;color:var(--mut)}.tm-pad{padding:18px}.tm-state{font-weight:800;color:var(--ok,#087c59)}.tm-bad{color:var(--bad,#b42318)}.tm-panel{scroll-margin-top:80px}.tm-grid .inp{width:100%;box-sizing:border-box} .tm-wrap{overflow-wrap:anywhere}
</style>
<div class="kpis">
<?= admin_kpi('Current · हालको', (string) count(array_filter($rows, static fn($r) => !$r['archived'])), 'Agent · staff · partner', 'users', 'blue') ?>
<?= admin_kpi('Live on website', (string) count(array_filter($rows, static fn($r) => !$r['archived'] && $r['live'])), 'सार्वजनिक टोली', 'globe', 'green') ?>
<?= admin_kpi('KYC to review', (string) count(array_filter($rows, static fn($r) => !$r['archived'] && $r['kyc_status'] !== 'verified')), 'कागजात जाँच बाँकी', 'id-card', 'orange') ?>
<?= admin_kpi('Archived · पुराना', (string) count(array_filter($rows, static fn($r) => $r['archived'])), 'Restore available · हिसाब सुरक्षित', 'doc', 'navy') ?>
</div>
<nav class="tabs" aria-label="Team lists"><a href="team-manager.php" class="<?= !$archiveView ? 'on' : '' ?>">Current team · हालको टोली</a><a href="team-manager.php?view=archive" class="<?= $archiveView ? 'on' : '' ?>">Archived · पुराना</a></nav>
<form method="get" class="tm-actions" role="search">
  <input type="hidden" name="view" value="<?= $archiveView ? 'archive' : '' ?>">
  <input class="inp" name="q" aria-label="Search team" placeholder="Name / agent code / phone / city" value="<?= $h($q) ?>" style="max-width:360px">
  <select class="inp" name="kind" aria-label="Member type" style="max-width:180px"><option value="">All types</option><?php foreach (['Agent','Staff','Partner','Agent account'] as $v): ?><option<?= $kind === $v ? ' selected' : '' ?>><?= $h($v) ?></option><?php endforeach; ?></select>
  <button class="btn" type="submit">Search · खोज्नुहोस्</button>
</form>
<div class="panel tm-table"><table><thead><tr><th>Person · व्यक्ति</th><th>Mobile / place</th><th>KYC / status</th><th>Website</th><th>Manage</th></tr></thead><tbody>
<?php foreach ($shown as $r): ?>
<tr><td><b><?= $h($r['name']) ?></b><small><?= $h($r['label'] . ' · ' . ($r['code'] ?: $r['ref'])) ?></small><small><?= $h($r['ref']) ?></small></td>
<td><?= $h($r['phone_raw'] ?: $r['phone'] ?: '—') ?><small><?= $h($r['area'] ?: '—') ?></small></td>
<td><?= $h($r['kyc_status']) ?><small><?= $h($r['status']) ?></small></td>
<td><?= $r['archived'] ? 'Archived' : ($r['live'] ? '<span class="tm-state">● Live</span>' : 'Hidden') ?></td>
<td><a class="btn sm" href="team-manager.php?person=<?= rawurlencode($r['key']) ?>&amp;view=<?= $archiveView ? 'archive' : '' ?>#person">Manage · खोल्नुहोस्</a></td></tr>
<?php endforeach; ?>
<?php if ($shown === []): ?><tr><td colspan="5">No matching records · रेकर्ड भेटिएन।</td></tr><?php endif; ?>
</tbody></table></div>
<?php if ($selected !== null): $r = $selected; $aid = (int) ($r['admin_id'] ?? 0);
    $detailUrl = $r['kind'] === 'staff' ? 'staff-applications.php?id=' . (int) $r['id'] : ($r['kind'] === 'agent' ? 'agreements.php?tab=apps&id=' . (int) $r['id'] : 'agent-360.php?agent=' . $aid);
    $raw = (string) ($r['phone_raw'] ?: $r['phone']); $digits = preg_replace('/\D/', '', $raw) ?: '';
    if (strlen($digits) === 10) { $cc = countryDialCode(resolvePhoneCountry('', $raw)); $digits = $cc !== '' ? $cc . $digits : ''; }
    $account = $aid > 0 ? Database::fetch('SELECT username,email,is_active,role FROM admins WHERE id = :id', ['id' => $aid]) : null;
?>
<section class="panel tm-panel" id="person"><h2><?= $h($r['name']) ?> · <?= $h($r['ref']) ?></h2><div class="tm-pad">
<p class="tm-muted">Login: <?= $account ? $h($account['username']) . ((int) $account['is_active'] === 1 ? ' · Active' : ' · Off') : 'Not linked · आवेदनबाट login जोड्नुहोस्' ?> · KYC: <?= $h($r['kyc_status']) ?></p>
<?php if ($r['archived']): ?>
<p>Archived · वेबसाइट र नयाँ कामबाट हटाइएको। पुरानो KYC, ticket र ledger सुरक्षित छन्।</p>
<form method="post"><?= $csrf ?><input type="hidden" name="person" value="<?= $h($key) ?>"><button class="btn" name="action" value="restore">Restore · फिर्ता ल्याउनुहोस्</button></form>
<?php else: ?>
<div class="tm-actions">
<a class="btn" href="<?= $h($detailUrl) ?>#web">✏️ Full KYC / documents</a>
<a class="btn ghost" href="<?= $h($detailUrl) ?>#login">🔑 Login ID / password</a>
<?php if ($r['kind'] !== 'account' && $r['status'] === 'approved'): ?><a class="btn" href="id-card.php?ref=<?= $h($r['ref']) ?>">🪪 ID + Visiting card</a><a class="btn ghost" href="id-card.php?ref=<?= $h($r['ref']) ?>&amp;pdf=all">↓ Cards PDF</a><?php endif; ?>
<?php if ($aid > 0): ?><a class="btn ghost" href="agent-360.php?agent=<?= $aid ?>">Tickets / ledger</a><a class="btn ghost" href="agent.php?agent=<?= $aid ?>#settle">Salary / settlement</a><a class="btn ghost" href="staff-chat.php?agent=<?= $aid ?>">💬 Office chat</a><?php endif; ?>
<?php if ($digits !== ''): ?><a class="btn ghost" href="https://wa.me/<?= $h($digits) ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
<?php if (!empty($r['email'])): ?><a class="btn ghost" href="mailto:<?= $h(rawurlencode((string) $r['email'])) ?>">Email</a><?php endif; ?>
</div>
<p class="tm-muted">WhatsApp / Email बटनले message तयार गर्न खोल्छ। कार्ड download गरेर पठाउन सकिन्छ।</p>
<?php if ($r['kind'] === 'agent'): $app = AgentApplications::find((int) $r['id']); $fields = $app['fields'] ?? [];
    Logger::audit('team.kyc_view', 'agent_application', $r['ref'], null, null, 'Office opened KYC editor'); ?>
<details open><summary><b>✏️ Edit &amp; save details · विवरण सच्याउनुहोस्</b></summary>
<form method="post" class="tm-pad"><?= $csrf ?><input type="hidden" name="person" value="<?= $h($key) ?>"><input type="hidden" name="action" value="details">
<div class="tm-grid"><?php foreach (['name'=>'Full name · नाम','phone'=>'Mobile (+91 / +977)','area'=>'City / work area','email'=>'Email','web_addr'=>'Public office address'] as $n=>$label): ?><label><?= $h($label) ?><input class="inp" name="<?= $n ?>" value="<?= $h($n === 'phone' ? ($app['phone_raw'] ?: $app['phone']) : ($app[$n] ?? '')) ?>" maxlength="<?= $n === 'phone' ? '30' : '160' ?>"<?= $n === 'email' ? ' type="email"' : '' ?><?= in_array($n,['name','phone'],true) ? ' required' : '' ?>></label><?php endforeach; ?>
<?php foreach (AgentApplications::OFFICE_KYC_FIELDS as $n): ?><label><?= $h(AgentApplications::FIELD_LABELS[$n] ?? $n) ?>
<?php if ($n === 'p_idtype'): ?><select class="inp" name="<?= $n ?>"><option value="">Choose ID type</option><?php foreach (AgentApplications::ID_TYPES as $v=>$label): ?><option value="<?= $h($v) ?>"<?= (string) ($fields[$n] ?? '') === (string) $v ? ' selected' : '' ?>><?= $h(is_array($label) ? (reset($label) ?: $v) : $label) ?></option><?php endforeach; ?></select>
<?php else: ?><input class="inp" name="<?= $n ?>" value="<?= $h($fields[$n] ?? '') ?>" maxlength="<?= $n === 'p_addr' ? '500' : '80' ?>"><?php endif; ?></label><?php endforeach; ?></div>
<div class="tm-actions"><button class="btn" type="submit">Save details · सुरक्षित गर्नुहोस्</button><a class="btn ghost" href="<?= $h($detailUrl) ?>#web">Photo / more fields</a></div>
</form></details>
<form method="post" class="tm-actions"><?= $csrf ?><input type="hidden" name="person" value="<?= $h($key) ?>"><input type="hidden" name="action" value="visibility"><label><input type="checkbox" name="live" value="1"<?= $r['live'] ? ' checked' : '' ?>> Live on website · सार्वजनिक सूची</label><button class="btn ghost" type="submit">Save visibility</button></form>
<?php endif; ?>
<?php if ($account && $account['role'] === 'agent' && Auth::can('staff.manage')):
    $ov = AgentWallet::commissionOverrideFor($aid); $salary = AgentWallet::salaryFor($aid); $rate = in_array($ov['mode'], ['flat','booking'], true) ? (float) $ov['flat'] : AgentWallet::flatRateFor($aid);
    $mode = $salary > 0 ? ($rate > 0 ? 'both' : 'salary') : ($rate > 0 ? 'commission' : 'none'); ?>
<details open><summary><b>💰 Commission / salary · कमिसन / तलब</b></summary>
<p class="tm-muted">Current: <?= $h(AgentReport::rateCard($aid)['label']) ?> · Salary <?= $h(inr($salary)) ?>/month. New terms apply to future tickets. Salary is credited from Salary / settlement; saving terms does not pay money.</p>
<form method="post" class="tm-pad"><?= $csrf ?><input type="hidden" name="person" value="<?= $h($key) ?>"><input type="hidden" name="action" value="pay"><div class="tm-grid">
<label>Payment system<select class="inp" name="pay_mode"><?php foreach (['commission'=>'Commission only','salary'=>'Salary only','both'=>'Salary + commission','none'=>'No salary / commission'] as $v=>$label): ?><option value="<?= $v ?>"<?= $mode === $v ? ' selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select></label>
<label>Commission basis<select class="inp" name="basis"><option value="booking"<?= $ov['mode'] === 'booking' ? ' selected' : '' ?>>Per ticket / PNR · प्रति टिकट</option><option value="flat"<?= $ov['mode'] !== 'booking' ? ' selected' : '' ?>>Per passenger · प्रति यात्रु</option></select></label>
<label>Commission ₹ (0 / 100 / 200 / other)<input class="inp" name="rate" type="number" min="0" max="10000" step="0.01" required value="<?= $h($rate) ?>"></label>
<label>Monthly salary ₹<input class="inp" name="salary" type="number" min="0" max="10000000" step="0.01" required value="<?= $h($salary) ?>"></label>
<label>Reason · कारण<input class="inp" name="reason" minlength="5" maxlength="255" required placeholder="Agreed payment terms"></label>
</div><div class="tm-actions"><button class="btn" type="submit">Save commission / salary</button><a class="btn ghost" href="agent-360.php?agent=<?= $aid ?>&amp;tab=report">Change agent code / reports</a></div></form></details>
<?php endif; ?>
<form method="post" class="tm-actions"><?= $csrf ?><input type="hidden" name="person" value="<?= $h($key) ?>"><button class="btn ghost" type="submit" name="action" value="archive">Archive / remove from current team</button><span class="tm-muted">Recoverable · public card hidden; unused agent login disabled; ticket / ledger preserved.</span></form>
<?php endif; ?></div></section>
<?php endif; ?>
<?php admin_footer(); ?>
