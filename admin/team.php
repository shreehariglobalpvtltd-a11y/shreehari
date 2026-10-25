<?php
declare(strict_types=1);
require __DIR__.'/_guard.php';
require_once INCLUDE_PATH.'/teamregistry.php';
require_once INCLUDE_PATH.'/teamfinance.php';
require_once INCLUDE_PATH.'/carddelivery.php';
require_once INCLUDE_PATH.'/payroll.php';
$admin=admin_boot('staff.manage');
if (!TeamManager::allowed($admin)) { http_response_code(403); exit('Manager permission required.'); }
$adminId=(int)$admin['id'];$h=static fn($v):string=>Security::e((string)($v??''));
$tab=(string)($_GET['tab']??'members');
if(!in_array($tab,['members','approvals','earnings','withdrawals','cards','announcements','settings'],true)){$tab='members';}
$key=Security::clean((string)($_POST['person']??$_GET['person']??''),40);$flash=null;
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{
  if(!Security::verifyCsrf()){throw new RuntimeException('Page expired. Reload before submitting.');}
  if(!TeamRegistry::ready()){throw new RuntimeException('Unified team database upgrade required.');}
  $action=(string)($_POST['action']??'');$id=(int)($_POST['app_id']??0);$kind=($_POST['app_type']??'')==='staff'?'staff':'agent';
  if($action==='card_retry'){ $res=CardDelivery::send((string)($_POST['ref']??''),true);$flash=[$res['ok']?'ok':'bad',$res['detail']];
  }elseif($action==='card_config'){
   $template=trim((string)($_POST['template']??''));$lang=trim((string)($_POST['template_lang']??'en'));if(($template!==''&&!preg_match('/^[a-z0-9_]{1,128}$/',$template))||!preg_match('/^[a-z]{2,3}(?:_[A-Z]{2})?$/',$lang)){throw new RuntimeException('Use the approved Meta template name and language code.');}
   Settings::set('team_card_template',$template,'string','team',false);Settings::set('team_card_template_lang',$lang,'string','team',false);Logger::audit('team.card_template','team','configuration',null,['template'=>$template,'lang'=>$lang],'Document-header template selection');$flash=['ok','Template selection saved. Meta approval is required; rejected sends remain failed.'];
  }elseif($action==='approve_application'){
   $ref=TeamRegistry::approve($kind.':'.$id,$adminId);$send=CardDelivery::send($ref);
   $flash=['ok','Application approved. '.$send['detail'].' Activate securely at /team/ using verified WhatsApp recovery; existing passwords are preserved.'];
  }elseif($action==='reject_application'){
   $reason=Security::clean((string)($_POST['reason']??''),255);if(mb_strlen($reason)<5){throw new RuntimeException('Give a rejection reason.');}
   if($kind==='staff'){StaffApps::decide($id,'reject',$adminId,['reason'=>$reason]);}else{AgentApplications::update($id,['status'=>'rejected','note'=>$reason],$adminId);}
   $flash=['ok','Application rejected with an audit record.'];
  }elseif($action==='approve_request'){TeamFinance::approve((int)$_POST['request_id'],$adminId);$flash=['ok','Withdrawal approved; funds remain reserved. No payment recorded.'];
  }elseif($action==='approve_withdrawal'){TeamFinance::pay((int)$_POST['request_id'],(string)($_POST['utr_reference']??''),$adminId);$flash=['ok','Confirmed payment reference and payout ledger recorded.'];
  }elseif($action==='reject_withdrawal'){
   if(!Auth::can('commissions.pay')){throw new RuntimeException('Payout permission required.');}
   AgentWallet::decidePayoutRequest((int)$_POST['request_id'],'declined',$adminId,(string)($_POST['reject_reason']??''));$flash=['ok','Withdrawal rejected. Reservation released; ledger funds preserved.'];
  }elseif($action==='record_adjustment'){
   TeamFinance::adjustment((int)$_POST['member_id'],(float)$_POST['amount'],(string)$_POST['adj_type'],(string)$_POST['reason'],(string)($_POST['operation_token']??''),$adminId);$flash=['ok','Adjustment recorded in the existing ledger.'];
  }elseif($action==='post_announcement'){
   $title=Security::clean((string)$_POST['title'],160);$message=Security::clean((string)$_POST['body'],8000);$target=(string)$_POST['target_role'];$priority=(string)$_POST['priority'];
   if(!$title||!$message||!in_array($target,['all','agent','driver','office','partner'],true)||!in_array($priority,['normal','important','urgent'],true)){throw new RuntimeException('Check announcement fields.');}
   Database::insert('team_notices',['title'=>$title,'body'=>$message,'target'=>$target,'priority'=>$priority,'created_by'=>$adminId]);Logger::audit('team.announcement','team',$target,null,['title'=>$title],'Published to team portal');$flash=['ok','Announcement published in the team portal.'];
  }elseif($action==='positions'){
   $name=Security::clean((string)($_POST['position_label']??''),120);$slug=strtolower(trim((string)($_POST['position_key']??'')));$required=array_values(array_intersect((array)($_POST['requirements']??[]),['license_number','license_expiry','qualification','experience_years','emergency_contact_name']));
   if(!$name||!preg_match('/^[a-z][a-z0-9_]{1,29}$/',$slug)){throw new RuntimeException('Use a position key such as sales_assistant and a display name.');}
   $roles=Settings::getArray('team_staff_positions',[]);$roles[$slug]=['label'=>$name,'required'=>$required];Settings::set('team_staff_positions',$roles,'json','team',false);Logger::audit('team.position','team',$slug,null,$roles[$slug],'Staff position configured');$flash=['ok','Staff position and role requirements saved. The three main agreements are unchanged.'];
  }else{
   $r=TeamManager::find($key);if(!$r){throw new RuntimeException('Choose a person.');}
   if($action==='archive'||$action==='restore'){TeamManager::archive($key,$action==='restore',$admin);}
   elseif($r['archived']){throw new RuntimeException('Restore the record before editing.');}
   elseif($action==='profile'){TeamRegistry::save($key,$_POST,$adminId);}
   elseif($action==='code'){TeamRegistry::changeCode($key,(string)$_POST['code'],(string)$_POST['reason'],$adminId);}
   elseif($action==='pay'){TeamFinance::terms((int)$r['admin_id'],$_POST,$adminId);}
   elseif($action==='details'){
    $consent=Security::clean((string)($_POST['consent_proof']??''),255);if(mb_strlen($consent)<5){throw new RuntimeException('Record applicant consent before editing their KYC.');}
    if($r['kind']==='staff'){TeamPortal::officeEditStaff((int)$r['id'],$_POST,$adminId);}else{AgentApplications::saveCard((int)$r['id'],$_POST,$adminId,true);}
    if (!empty($_POST['import_id'])) {
        if (empty($_POST['import_reviewed'])) { throw new RuntimeException('Review the original before saving OCR fields.'); }
        Database::run('UPDATE team_reviews SET reviewed_at=NOW() WHERE id=:i AND person_key=:k',['i'=>(int)$_POST['import_id'],'k'=>$key]);
    }
    Database::run('DELETE FROM team_drafts WHERE person_key=:k AND admin_id=:a',['k'=>$key,'a'=>$adminId]);
    Logger::audit('team.assisted_edit','team',$key,null,['consent'=>$consent],'Application corrected on behalf of applicant');
   }else{throw new RuntimeException('Unknown action.');}
   $flash=['ok','Saved with audit history.'];
  }
 }catch(Throwable $e){Logger::warning('Team action: '.$e->getMessage());$flash=['bad',$e->getMessage()];}
 // Refreshing the resulting GET cannot repeat an approval, adjustment or send.
 $_SESSION['team_flash']=$flash;Response::redirect('admin/team.php?tab='.rawurlencode($tab).($key!==''?'&person='.rawurlencode($key):''));
}
$flash=$_SESSION['team_flash']??null;unset($_SESSION['team_flash']);
$rows=TeamManager::rows();$selected=null;$members=[];$pendingQueue=[];$payoutRequests=[];$earningsRules=[];
$query=Security::clean((string)($_GET['q']??''),80);$searchRole=(string)($_GET['role']??'all');$archiveView=($_GET['view']??'')==='archive';
foreach($rows as $r){
 if($r['key']===$key){$selected=$r;}
 if($r['kind']==='account'||(!TeamRegistry::digital($r)&&!$archiveView)||$r['archived']!==$archiveView){continue;}
 $isStaff=$r['kind']==='staff';$a=$isStaff?StaffApps::find((int)$r['id']):AgentApplications::find((int)$r['id']);
 $m=TeamRegistry::meta($r['key']);$category=$isStaff?(($a['role_applied']??'')==='driver'?'driver':'staff'):($a['type']==='org'?'partner':'agent');
 if(!in_array($a['status'],['approved','suspended'],true)||($a['kyc_status']??'')!=='verified'){
  if(!in_array($a['status'],['rejected','exited'],true)){$pendingQueue[]=['type'=>$r['kind'],'id'=>$r['id'],'ref'=>$r['ref'],'name'=>$r['name'],'role'=>$r['label'],'phone'=>$r['phone_raw'],'phone_verified'=>!empty($a['phone_verified']),'district'=>$r['area'],'id_type'=>$isStaff?($a['id_type']??''):($a['fields']['p_idtype']??''),'id_no'=>'Private document · open KYC review','created'=>$a['created_at'],'file_url'=>'','photo_url'=>'','key'=>$r['key']];}
  if(!in_array($a['status'],['approved','suspended'],true)){continue;}
 }
 if(($searchRole!=='all'&&$category!==$searchRole)||($query!==''&&stripos(implode(' ',[$r['name'],$r['phone'],$r['ref'],$m['code']??'',$m['route']??'']),$query)===false)){continue;}
 $aid=(int)($r['admin_id']??0);$balance=$aid?TeamFinance::available($aid):['available'=>0,'pending'=>0];
 $members[]=['ref'=>$r['ref'],'code'=>$m['code']??'Pending allocation','name'=>$r['name'],'role'=>$category,'role_lbl'=>($m['position_name']??'')?:($isStaff?StaffApps::roleLabel((string)$a['role_applied']):$r['label']),'category'=>$category,'phone'=>$r['phone_raw'],'cc'=>'','status'=>$a['status'],'balance'=>$balance['available'],'pending'=>$balance['pending'],'joined'=>$a['created_at'],'app_type'=>$r['kind'],'app_id'=>$r['id'],'admin_id'=>$aid,'key'=>$r['key'],'verified'=>!empty($a['phone_verified'])];
}
if($tab==='withdrawals'&&Auth::can('commissions.pay')){
 $payoutRequests=Database::fetchAll('SELECT r.*,a.full_name AS agent_name,a.phone AS agent_phone,r.agent_admin_id AS agent_id,x.payment_reference AS payment_ref,x.approved_at FROM agent_payout_requests r LEFT JOIN admins a ON a.id=r.agent_admin_id LEFT JOIN team_withdrawal_approvals x ON x.request_id=r.id ORDER BY r.id DESC LIMIT 100');
}
admin_header('Team Management & Approvals · टिम व्यवस्थापन', 'team.php');
?>
<link rel="stylesheet" href="/assets/css/admin.css?v=20261025">
<style>
.team-tabs{display:flex;gap:6px;border-bottom:2px solid var(--line,#e2e8f0);margin-bottom:20px;overflow-x:auto;padding-bottom:2px}
.team-tab{padding:10px 18px;font-size:14px;font-weight:800;text-decoration:none;color:var(--mut,#64748b);border-radius:12px 12px 0 0;white-space:nowrap;transition:all .2s}
.team-tab:hover{color:var(--primary,#0a2a8f);background:rgba(10,42,143,.04)}
.team-tab.active{color:#fff;background:linear-gradient(135deg,#0a2a8f,#1f4fe0)}
.badge-count{display:inline-block;padding:2px 7px;border-radius:999px;font-size:11px;background:#f07a1e;color:#fff;margin-left:5px}
.data-card{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 6px 18px -10px rgba(0,0,0,.08)}
.action-grid{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.btn-sm{min-height:36px;padding:0 12px;font-size:13px;font-weight:800;border-radius:10px;border:0;cursor:pointer;display:inline-flex;align-items:center;gap:4px;text-decoration:none}
.btn-primary{background:#0a2a8f;color:#fff}
.btn-success{background:#0f9d6b;color:#fff}
.btn-danger{background:#d93025;color:#fff}
.btn-outline{background:transparent;border:1.5px solid #cbd5e1;color:#334155}
.pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:800}
.pill-active{background:rgba(15,157,107,.12);color:#0f9d6b}
.pill-pending{background:rgba(240,122,30,.12);color:#b35505}
.pill-suspended{background:rgba(217,48,37,.12);color:#d93025}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.filter-bar select,.filter-bar input{min-height:40px;border:1.5px solid #cbd5e1;border-radius:10px;padding:0 12px;font:inherit}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px}
.modal-box{background:#fff;border-radius:18px;max-width:540px;width:100%;max-height:92vh;overflow-y:auto;padding:22px;box-shadow:0 20px 50px rgba(0,0,0,.4)}
</style>
<script src="/assets/js/photo-studio.js?v=20261025c"></script>

<?php if ($flash !== null): ?>
  <div class="data-card" style="border-left:5px solid <?= $flash[0] === 'ok' ? '#0f9d6b' : '#d93025' ?>;padding:12px 16px;font-weight:700">
    <?= Security::e($flash[1]) ?>
  </div>
<?php endif; ?>

<!-- NAVIGATION TABS -->
<nav class="team-tabs">
  <a class="team-tab" href="team.php?tab=settings">⚙️ Positions / services</a>
  <a class="team-tab <?= $tab === 'members' ? 'active' : '' ?>" href="team.php?tab=members">👥 Team Members · सदस्य सूची</a>
  <a class="team-tab <?= $tab === 'approvals' ? 'active' : '' ?>" href="team.php?tab=approvals">
    📋 Approvals Queue · अनुमोदन <?= count($pendingQueue) > 0 ? '<span class="badge-count">' . count($pendingQueue) . '</span>' : '' ?>
  </a>
  <a class="team-tab <?= $tab === 'earnings' ? 'active' : '' ?>" href="team.php?tab=earnings">💰 Flexible Earnings · कमिसन तथा तलब</a>
  <a class="team-tab <?= $tab === 'withdrawals' ? 'active' : '' ?>" href="team.php?tab=withdrawals">🏦 Wallet & Withdrawals · भुक्तानी</a>
  <a class="team-tab <?= $tab === 'cards' ? 'active' : '' ?>" href="team.php?tab=cards">🪪 ID Cards & WhatsApp · परिचयपत्र</a>
  <a class="team-tab <?= $tab === 'announcements' ? 'active' : '' ?>" href="team.php?tab=announcements">📢 Announcements · सूचना</a>
</nav>

<?php require __DIR__.'/_team-workbench.php'; ?>
<!-- =====================================================================
     TAB 1: MEMBERS REGISTRY
     ===================================================================== -->
<?php if ($tab === 'members'): ?>
  <div class="filter-bar">
    <form method="get" action="team.php" style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
      <input type="hidden" name="tab" value="members">
      <select name="role" onchange="this.form.submit()">
        <option value="all" <?= ($searchRole ?? '') === 'all' ? 'selected' : '' ?>>All Roles · सबै पद</option>
        <option value="agent" <?= ($searchRole ?? '') === 'agent' ? 'selected' : '' ?>>Agents · एजेन्ट</option>
        <option value="driver" <?= ($searchRole ?? '') === 'driver' ? 'selected' : '' ?>>Drivers · चालक</option>
        <option value="staff" <?= ($searchRole ?? '') === 'staff' ? 'selected' : '' ?>>Office Staff · कर्मचारी</option>
        <option value="partner" <?= ($searchRole ?? '') === 'partner' ? 'selected' : '' ?>>Transit Partners · साझेदार</option>
      </select>
      <input type="text" name="q" placeholder="Search by name, phone or code..." value="<?= Security::e($query ?? '') ?>" style="flex:1;min-width:200px">
      <button type="submit" class="btn-sm btn-primary">Search · खोज्नुहोस्</button>
    </form>
    <a href="team.php?view=archive" class="btn-sm btn-outline">Archived records</a><a href="/agreements/apply.html" target="_blank" class="btn-sm btn-success">+ Register New Member</a>
  </div>

  <div class="data-card" style="padding:0;overflow-x:auto">
    <table style="margin:0">
      <thead>
        <tr style="background:#f8fafc">
          <th>Staff Code</th>
          <th>Name & Role</th>
          <th>Phone / verification</th>
          <th>Available / reserved</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($members === []): ?>
          <tr><td colspan="6" style="text-align:center;padding:30px;color:#64748b">No team members found.</td></tr>
        <?php else: ?>
          <?php foreach ($members as $m): ?>
            <tr>
              <td><b class="mono" style="color:#0a2a8f"><?= Security::e($m['code']) ?></b><br><small style="color:#64748b"><?= Security::e($m['ref']) ?></small></td>
              <td><b><?= Security::e($m['name']) ?></b><br><span style="font-size:12px;color:#64748b"><?= Security::e($m['role_lbl']) ?></span></td>
              <td>
                <?php $recipient = CardDelivery::recipient($m['ref']); ?>
                <?php if ($recipient !== ''): ?>
                  <a href="https://wa.me/<?= Security::e($recipient) ?>" target="_blank" rel="noopener" style="text-decoration:none">💬 <?= Security::e($m['phone']) ?> ✓ Verified</a>
                <?php else: ?>
                  <span><?= Security::e($m['phone']) ?> · Verification required</span>
                <?php endif; ?>
              </td>
              <td><b>INR <?= number_format((float)$m['balance'], 2) ?></b><br><small>Reserved: <?= number_format((float)$m['pending'],2) ?></small></td>
              <td><span class="pill pill-<?= $m['status'] === 'approved' ? 'active' : 'suspended' ?>"><?= Security::e(ucfirst($m['status'])) ?> · <?= Security::e((string)(TeamManager::find($m['key'])['kyc_status']??'')) ?></span></td>
              <td>
                <div class="action-grid">
                  <a href="id-card.php?ref=<?= urlencode($m['ref']) ?>" class="btn-sm btn-outline">🪪 Cards</a>
                  <a class="btn-sm btn-primary" href="team.php?person=<?= rawurlencode($m['key']) ?>">Manage</a>
                  <a href="<?= Security::e(IdCard::verifyUrl($m['ref'])) ?>" target="_blank" class="btn-sm btn-outline">🛡️ Verify</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<!-- =====================================================================
     TAB 2: APPROVALS QUEUE
     ===================================================================== -->
<?php elseif ($tab === 'approvals'): ?>
  <div style="margin-bottom:14px;color:#475569;font-size:14px">
    Review pending applications for Agents, Drivers, Staff, and Transit Partners. Inspect KYC, run handwritten OCR auto-extract, check lookalikes, or complete on behalf with applicant consent.
  </div>

  <?php if ($pendingQueue === []): ?>
    <div class="data-card" style="text-align:center;padding:40px;color:#64748b">
      <h3>🎉 All Caught Up!</h3>
      <p>There are no pending applications awaiting approval.</p>
    </div>
  <?php else: ?>
    <?php foreach ($pendingQueue as $p): ?>
      <div class="data-card">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px">
          <div>
            <span class="pill pill-pending"><?= Security::e($p['role']) ?></span>
            <h3 style="margin:6px 0 2px;font-size:18px"><?= Security::e($p['name']) ?> (<?= Security::e($p['ref']) ?>)</h3>
            <p style="margin:0;font-size:13px;color:#64748b">
              Phone: <b><?= Security::e($p['phone']) ?></b> <?= $p['phone_verified'] ? '<span style="color:#0f9d6b">✓ WhatsApp Proven</span>' : '<span style="color:#b35505">⚠️ Unverified Phone</span>' ?> ·
              Location: <b><?= Security::e($p['district']) ?></b> · Applied: <?= Security::e($p['created']) ?>
            </p>
          </div>
          <div class="action-grid">
            <form method="post" action="team.php?tab=approvals" style="display:inline">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="approve_application">
              <input type="hidden" name="app_type" value="<?= Security::e($p['type']) ?>">
              <input type="hidden" name="app_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn-sm btn-success">✅ Approve & Issue Cards</button>
            </form>

            <button type="button" class="btn-sm btn-danger" onclick="openRejectModal('<?= Security::e($p['type']) ?>', <?= (int)$p['id'] ?>, 'this application')">❌ Reject</button>
            <a class="btn-sm btn-outline" href="team.php?person=<?= rawurlencode($p['key']) ?>">Review / complete KYC</a>
          </div>
        </div>

        <!-- Lookalikes duplicate warning -->
        <?= TeamDupes::html($p['type'], (int)$p['id']) ?>

        <!-- KYC Inspection -->
        <div style="display:flex;gap:14px;margin-top:14px;align-items:center;background:#f8fafc;padding:12px;border-radius:12px;flex-wrap:wrap">
          <?php if (!empty($p['photo_url'])): ?>
            <img src="<?= Security::e($p['photo_url']) ?>" alt="Photo" style="width:70px;height:70px;border-radius:10px;object-fit:cover;border:2px solid #cbd5e1">
          <?php endif; ?>
          <div style="flex:1">
            <b>ID Document:</b> <?= Security::e(ucfirst($p['id_type'])) ?> — <code><?= Security::e($p['id_no']) ?></code><br>
            <?php if (!empty($p['file_url'])): ?>
              <a href="<?= Security::e($p['file_url']) ?>" target="_blank" class="btn-sm btn-outline" style="margin-top:6px">📄 View KYC Document (Signed URL)</a>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

<!-- =====================================================================
     TAB 3: FLEXIBLE EARNINGS & SALARY
     ===================================================================== -->
<?php elseif ($tab === 'earnings'): ?>
<div class="data-card"><h3>Earnings, salary and loans</h3><p>Select a member → Manage to set dated percentage, per-passenger or per-booking terms and salary. Existing earnings are preserved.</p><div class="action-grid"><a class="btn-sm btn-primary" href="team.php">Select member</a><a class="btn-sm btn-outline" href="payroll.php">Staff payroll / salary slips</a><a class="btn-sm btn-outline" href="ledger-check.php">Ledger reconciliation</a><a class="btn-sm btn-outline" href="team.php?tab=settings">Staff positions / services</a></div></div>
<div class="data-card"><h3>Bonus / deduction</h3><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="record_adjustment"><input type="hidden" name="operation_token" value="<?= bin2hex(random_bytes(16)) ?>"><label>Linked account ID * <select name="member_id" required><option value="">Choose linked agent</option><?php foreach($members as $member): if(!$member['admin_id']||$member['app_type']!=='agent')continue; ?><option value="<?= (int)$member['admin_id'] ?>"><?= $h($member['code'].' · '.$member['name']) ?></option><?php endforeach; ?></select></label><label>Type <select name="adj_type"><option value="bonus">Bonus</option><option value="deduction">Deduction</option></select></label><label>Amount (INR) * <input name="amount" type="number" min="0.01" step="0.01" required></label><label>Reason * <input name="reason" minlength="5" required></label><button class="btn-sm btn-primary">Submit adjustment</button></form></div>
<!-- =====================================================================
     TAB 4: WALLET & WITHDRAWALS
     ===================================================================== -->
<?php elseif ($tab === 'withdrawals'): ?>
  <div class="data-card">
    <h3>🏦 Wallet Payout Requests</h3>
    <p style="font-size:13.5px;color:#64748b">
      When users request withdrawals, funds are reserved. Admins can approve and mark paid only after recording the payment confirmation / UTR reference number. Over-balance and duplicate requests are strictly prevented.
    </p>

    <table style="margin-top:14px">
      <thead>
        <tr style="background:#f8fafc">
          <th>Request ID</th>
          <th>Agent / Member</th>
          <th>Amount Requested</th>
          <th>Account Details</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if ($payoutRequests === []): ?>
          <tr><td colspan="6" style="text-align:center;padding:24px;color:#64748b">No payout requests found.</td></tr>
        <?php else: ?>
          <?php foreach ($payoutRequests as $w): ?>
            <tr>
              <td><b>#<?= (int)$w['id'] ?></b><br><small><?= Security::e($w['created_at']) ?></small></td>
              <td><b><?= Security::e($w['agent_name'] ?: 'Agent #' . $w['agent_id']) ?></b><br><small><?= Security::e($w['agent_phone']) ?></small></td>
              <td><b style="color:#0a2a8f;font-size:15px">₹/रू <?= number_format((float)$w['amount'], 2) ?></b></td>
              <td><?= Security::e($w['payment_method'] ?? 'Bank') ?>: <code><?= Security::e($w['account_details'] ?? 'On file') ?></code></td>
              <td><span class="pill pill-<?= $w['status'] === 'paid' ? 'active' : ($w['status'] === 'open' ? 'pending' : 'suspended') ?>"><?= Security::e(ucfirst($w['status'])) ?></span></td>
              <td>
                <?php if ($w['status'] === 'open'): ?>
                  <div class="action-grid">
                    <?php if (empty($w['approved_at'])): ?><form method="post"><?= Security::csrfField() ?><input type="hidden" name="action" value="approve_request"><input type="hidden" name="request_id" value="<?= (int)$w['id'] ?>"><button class="btn-sm btn-primary">Approve request</button></form><?php else: ?><span>Approved · unpaid</span><?php endif; ?>
                    <button type="button" class="btn-sm btn-success" onclick="openPayoutModal(<?= (int)$w['id'] ?>, '<?= number_format((float)$w['amount'], 2) ?>')">Mark Paid (UTR)</button>
                    <button type="button" class="btn-sm btn-danger" onclick="openRejectPayoutModal(<?= (int)$w['id'] ?>)">Reject</button>
                  </div>
                <?php else: ?>
                  <small>Ref: <code><?= Security::e($w['payment_ref'] ?? '—') ?></code></small>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

<!-- =====================================================================
     TAB 5: CARDS & WHATSAPP DELIVERY
     ===================================================================== -->
<?php elseif ($tab === 'cards'): ?>
  <div class="data-card">
    <h3>🪪 Branded Identity & Visiting Card Center</h3>
    <p style="font-size:13.5px;color:#64748b">
      Auto-generates high-resolution branded ID cards (CR80 54×85.6mm) and visiting cards (89×51mm) with photo, name, role, unique staff code, and dual QRs. Delivers directly to verified WhatsApp via Meta Cloud API.
    </p>

    <div style="display:flex;gap:10px;margin-top:14px">
      <input type="text" id="cardLookupRef" placeholder="Enter Staff Code or Ref (e.g. STF-000001, AGR-000001)" style="flex:1;min-height:44px;border:1.5px solid #cbd5e1;border-radius:10px;padding:0 12px">
      <button type="button" class="btn-sm btn-primary" onclick="lookupCard()">Open Digital Cards · खोल्नुहोस्</button>
    </div>
  </div>

<!-- =====================================================================
     TAB 6: ANNOUNCEMENTS
     ===================================================================== -->
<?php elseif ($tab === 'announcements'): ?>
  <div class="data-card">
    <h3>📢 Broadcast Announcement to Team</h3>
    <form method="post" action="team.php?tab=announcements" style="margin-top:14px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="post_announcement">

      <div class="row2">
        <label class="f">
          <span>Target Audience · कसलाई पठाउने? <em>*</em></span>
          <select name="target_role">
            <option value="all">👥 All Team Members (Agents, Drivers, Staff, Partners)</option>
            <option value="agent">🎫 Booking Agents Only</option>
            <option value="driver">🚌 Drivers & Crew Only</option>
            <option value="office">🏢 Office Staff Only</option>
            <option value="partner">🤝 Transit Partners Only</option>
          </select>
        </label>
        <label class="f">
          <span>Priority · प्राथमिकता <em>*</em></span>
          <select name="priority">
            <option value="normal">Normal Information · सामान्य</option>
            <option value="important">Important · महत्त्वपूर्ण</option>
            <option value="urgent">🚨 Urgent Safety / Schedule Alert</option>
          </select>
        </label>
      </div>

      <label class="f">
        <span>Title · शीर्षक <em>*</em></span>
        <input type="text" name="title" required placeholder="जस्तै: चाडपर्व बुकिङ तथा अतिरिक्त बस सञ्चालन सम्बन्धी">
      </label>

      <label class="f">
        <span>Message Content · सन्देश विवरण <em>*</em></span>
        <textarea name="body" required rows="4" placeholder="सूचनाको सम्पूर्ण विवरण लेख्नुहोस्..."></textarea>
      </label>

      <button type="submit" class="btn-sm btn-primary" style="min-height:46px;padding:0 24px">📢 Post Announcement · सूचना जारी गर्नुहोस्</button>
    </form>
  </div>
<?php endif; ?>

<!-- =====================================================================
     MODALS
     ===================================================================== -->
<!-- 1. Reject Modal -->
<div class="modal-overlay" id="rejectModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <h3>❌ Rejection Reason</h3>
    <form method="post" action="team.php?tab=approvals">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="reject_application">
      <input type="hidden" name="app_type" id="rejType">
      <input type="hidden" name="app_id" id="rejId">
      <p>Rejecting application for: <b id="rejName"></b></p>
      <label class="f">
        <span>Reason for rejection <em>*</em></span>
        <textarea name="reason" required rows="3" placeholder="Explain missing KYC documents or refusal reason..."></textarea>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('rejectModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-danger">Confirm Rejection</button>
      </div>
    </form>
  </div>
</div>

<!-- 2. On Behalf Edit Modal -->
<div class="modal-overlay" id="onBehalfModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <h3>✏️ Admin Edit on Behalf of Applicant</h3>
    <form method="post" action="team.php?tab=approvals">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="edit_on_behalf">
      <input type="hidden" name="app_type" id="obType">
      <input type="hidden" name="app_id" id="obId">
      <p>Applicant: <b id="obName"></b></p>
      <label class="f">
        <span>Applicant Consent Confirmation (Required for Audit Trail) <em>*</em></span>
        <input type="text" name="consent_proof" required placeholder="जस्तै: Verbal consent via phone / physical presence at desk">
      </label>
      <label class="f">
        <span>Full Name</span>
        <input type="text" name="full_name" id="obFullName">
      </label>
      <label class="f">
        <span>District / Area</span>
        <input type="text" name="district" id="obDistrict">
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('onBehalfModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-primary">Save Changes & Audit</button>
      </div>
    </form>
  </div>
</div>

<!-- 3. Payout UTR Modal -->
<div class="modal-overlay" id="payoutModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <h3>✅ Confirm Withdrawal Payout</h3>
    <form method="post" action="team.php?tab=withdrawals">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="approve_withdrawal">
      <input type="hidden" name="request_id" id="payoutReqId">
      <p>Amount: <b id="payoutAmt" style="color:#0a2a8f;font-size:18px"></b></p>
      <label class="f">
        <span>Bank UTR / Transaction ID / Cash Voucher No. <em>*</em></span>
        <input type="text" name="utr_reference" required placeholder="e.g. UTR1234567890 / eSewa Ref: 987654">
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('payoutModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-success">Mark Paid</button>
      </div>
    </form>
  </div>
</div>

<!-- 4. Reject Payout Modal -->
<div class="modal-overlay" id="rejectPayoutModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <h3>❌ Reject Withdrawal</h3>
    <form method="post" action="team.php?tab=withdrawals">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="reject_withdrawal">
      <input type="hidden" name="request_id" id="rejPayoutReqId">
      <label class="f">
        <span>Rejection reason (reservation will be released) <em>*</em></span>
        <textarea name="reject_reason" required rows="3" placeholder="e.g. Invalid bank account IFSC code"></textarea>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('rejectPayoutModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-danger">Reject & Release Reservation</button>
      </div>
    </form>
  </div>
</div>

<script>
function openRejectModal(type, id, name) {
  document.getElementById('rejType').value = type;
  document.getElementById('rejId').value = id;
  document.getElementById('rejName').textContent = name;
  document.getElementById('rejectModal').style.display = 'flex';
}
function openOnBehalfModal(type, id, name) {
  document.getElementById('obType').value = type;
  document.getElementById('obId').value = id;
  document.getElementById('obName').textContent = name;
  document.getElementById('onBehalfModal').style.display = 'flex';
}
function openPayoutModal(id, amt) {
  document.getElementById('payoutReqId').value = id;
  document.getElementById('payoutAmt').textContent = '₹/रू ' + amt;
  document.getElementById('payoutModal').style.display = 'flex';
}
function openRejectPayoutModal(id) {
  document.getElementById('rejPayoutReqId').value = id;
  document.getElementById('rejectPayoutModal').style.display = 'flex';
}
function lookupCard() {
  var ref = document.getElementById('cardLookupRef').value.trim();
  if (!ref) { alert('Please enter a card reference or staff code.'); return; }
  window.location.href = 'id-card.php?ref=' + encodeURIComponent(ref);
}
function openAdjustmentModal(id, name) {
  window.location.href = 'team.php?tab=earnings&member=' + id;
}
</script>

<script>document.querySelectorAll('form[method="post"]').forEach(form=>form.addEventListener('submit',event=>{const b=event.submitter;if(b){if(b.name){const h=document.createElement('input');h.type='hidden';h.name=b.name;h.value=b.value;form.append(h);}b.disabled=true;b.textContent='Saving…';}}));</script>
<?php admin_footer(); ?>
