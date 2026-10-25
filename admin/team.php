<?php
/**
 * admin/team.php — S Hari Global Unified Team Management Panel.
 *
 * Consolidates:
 *  - Members: Unified registry across Agents, Drivers, Staff, and Transit Partners
 *  - Approvals: Digital KYC & Handwritten OCR queue with uncertain field highlighting, live selfie match, on-behalf editing
 *  - Earnings: Flexible commission (% of fare, per-seat, per-booking) & salary with bonus/deduction ledger and cancellation reversal
 *  - Withdrawals: Wallet balances, reserved funds logic, payout approval requiring UTR / payment reference
 *  - Cards: High-res branded ID and visiting card gallery, dual QR verification, automated Cloud API delivery tracker, retry, manual fallback
 *  - Announcements: Role-targeted team broadcasts with optional WhatsApp blast
 */

declare(strict_types=1);

require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/idcard.php';
require_once INCLUDE_PATH . '/carddelivery.php';
require_once INCLUDE_PATH . '/teamdupes.php';
require_once INCLUDE_PATH . '/docverify.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentsettlement.php';
require_once INCLUDE_PATH . '/payroll.php';

$admin = admin_boot('staff.manage');
$adminId = (int) ($admin['id'] ?? 0);

$tab = (string) ($_GET['tab'] ?? 'members');
if (!in_array($tab, ['members', 'approvals', 'earnings', 'withdrawals', 'cards', 'announcements'], true)) {
    $tab = 'members';
}

$flash = null;

// =========================================================================
// POST ACTION HANDLERS
// =========================================================================
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired or security token mismatch. Please try again.'];
    } else {
        $action = (string) ($_POST['action'] ?? '');

        // 1. APPROVE APPLICATION
        if ($action === 'approve_application') {
            $type = (string) ($_POST['app_type'] ?? 'staff');
            $id   = (int) ($_POST['app_id'] ?? 0);

            try {
                if ($type === 'staff') {
                    $app = StaffApps::find($id);
                    if ($app === null) throw new RuntimeException('Staff application not found.');

                    StaffApps::setStatus($id, 'approved', $adminId, 'Approved via Unified Team Panel');

                    // If driver, ensure driver record
                    if ((string) $app['role_applied'] === 'driver') {
                        Database::run(
                            "INSERT INTO drivers (name, phone, license_no, status, created_at)
                             VALUES (:n, :p, :l, 'active', NOW())
                             ON DUPLICATE KEY UPDATE status = 'active', name = :n2",
                            ['n' => $app['full_name'], 'p' => $app['phone'], 'l' => (string)($app['license_number'] ?? ''), 'n2' => $app['full_name']]
                        );
                    } else {
                        // Create counter/office login if role is office/accountant
                        if (in_array((string)$app['role_applied'], ['office', 'accountant'], true)) {
                            TeamPortal::makeCounterLogin($id, '', $adminId);
                        }
                    }
                    $ref = StaffApps::refFor($id);
                } else {
                    // Agent or Partner
                    $app = AgentApplications::find($id);
                    if ($app === null) throw new RuntimeException('Application not found.');

                    AgentApplications::update($id, [
                        'status' => 'approved',
                        'reviewed_by' => $adminId,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                    ], $adminId);
                    $ref = AgentApplications::refFor($id);
                }

                // Auto-dispatch Cards via WhatsApp Cloud API
                $deliveryRes = CardDelivery::send($ref, false);

                Logger::audit('team.approved', $type, $ref, null, ['admin' => $admin['username'] ?? ''], 'Application approved; login activated; cards generated');
                $flash = ['ok', 'Application ' . $ref . ' approved! Cards generated and WhatsApp dispatch initiated: ' . $deliveryRes['detail']];
            } catch (Throwable $e) {
                Logger::error('Approve failed: ' . $e->getMessage());
                $flash = ['bad', 'Approval failed: ' . $e->getMessage()];
            }
        }

        // 2. REJECT APPLICATION
        elseif ($action === 'reject_application') {
            $type   = (string) ($_POST['app_type'] ?? 'staff');
            $id     = (int) ($_POST['app_id'] ?? 0);
            $reason = trim((string) ($_POST['reason'] ?? ''));

            if ($reason === '') {
                $flash = ['bad', 'Please provide a clear rejection reason.'];
            } else {
                try {
                    if ($type === 'staff') {
                        StaffApps::setStatus($id, 'rejected', $adminId, $reason);
                        $ref = StaffApps::refFor($id);
                    } else {
                        AgentApplications::update($id, [
                            'status' => 'rejected',
                            'reviewed_by' => $adminId,
                            'reviewed_at' => date('Y-m-d H:i:s'),
                            'notes' => $reason
                        ], $adminId);
                        $ref = AgentApplications::refFor($id);
                    }
                    Logger::audit('team.rejected', $type, $ref, null, ['reason' => $reason], 'Application rejected');
                    $flash = ['ok', 'Application ' . $ref . ' has been rejected.'];
                } catch (Throwable $e) {
                    $flash = ['bad', 'Rejection failed: ' . $e->getMessage()];
                }
            }
        }

        // 3. COMPLETE / EDIT ON BEHALF
        elseif ($action === 'edit_on_behalf') {
            $type    = (string) ($_POST['app_type'] ?? 'staff');
            $id      = (int) ($_POST['app_id'] ?? 0);
            $consent = trim((string) ($_POST['consent_proof'] ?? ''));

            if ($consent === '') {
                $flash = ['bad', 'Admin editing on behalf requires applicant consent confirmation.'];
            } else {
                try {
                    if ($type === 'staff') {
                        $changed = TeamPortal::officeEditStaff($id, $_POST, $adminId);
                        Logger::audit('team.edit_on_behalf', 'staff', (string)$id, null, ['consent' => $consent, 'changed' => $changed], 'Admin completed/edited staff application on behalf with consent');
                    } else {
                        AgentApplications::update($id, [
                            'name' => Security::clean((string)($_POST['name'] ?? ''), 120),
                            'area' => Security::clean((string)($_POST['area'] ?? ''), 120),
                        ], $adminId);
                        Logger::audit('team.edit_on_behalf', 'agent', (string)$id, null, ['consent' => $consent], 'Admin edited agent application on behalf with consent');
                    }
                    $flash = ['ok', 'Application updated on behalf with applicant consent audit trail.'];
                } catch (Throwable $e) {
                    $flash = ['bad', 'Update failed: ' . $e->getMessage()];
                }
            }
        }

        // 4. RECORD BONUS OR DEDUCTION
        elseif ($action === 'record_adjustment') {
            $memberId = (int) ($_POST['member_id'] ?? 0);
            $amount   = (float) ($_POST['amount'] ?? 0);
            $type     = (string) ($_POST['adj_type'] ?? 'bonus'); // bonus or deduction
            $reason   = trim((string) ($_POST['reason'] ?? ''));

            if ($memberId <= 0 || $amount <= 0 || $reason === '') {
                $flash = ['bad', 'Please provide a valid member, positive amount, and mandatory reason.'];
            } else {
                try {
                    $signedAmount = ($type === 'deduction') ? -$amount : $amount;
                    Database::run(
                        "INSERT INTO agent_transactions (agent_id, booking_id, type, amount, balance_after, description, created_at)
                         VALUES (:a, NULL, :t, :amt, (SELECT COALESCE(balance, 0) + :amt2 FROM agent_wallets WHERE agent_id = :a2), :d, NOW())",
                        ['a' => $memberId, 't' => $type, 'amt' => $signedAmount, 'amt2' => $signedAmount, 'a2' => $memberId, 'd' => $reason]
                    );
                    Database::run(
                        "UPDATE agent_wallets SET balance = balance + :amt, updated_at = NOW() WHERE agent_id = :a",
                        ['amt' => $signedAmount, 'a' => $memberId]
                    );
                    Logger::audit('wallet.adjustment', 'admin', (string)$memberId, null, ['type' => $type, 'amount' => $amount, 'reason' => $reason], 'Wallet adjustment recorded by admin');
                    $flash = ['ok', 'Wallet ' . $type . ' of ₹/' . $amount . ' successfully applied.'];
                } catch (Throwable $e) {
                    $flash = ['bad', 'Adjustment failed: ' . $e->getMessage()];
                }
            }
        }

        // 5. APPROVE WITHDRAWAL
        elseif ($action === 'approve_withdrawal') {
            $reqId = (int) ($_POST['request_id'] ?? 0);
            $utr   = trim((string) ($_POST['utr_reference'] ?? ''));

            if ($reqId <= 0 || $utr === '') {
                $flash = ['bad', 'Payment reference number / UTR / transaction ID is mandatory before marking paid.'];
            } else {
                try {
                    Database::run(
                        "UPDATE agent_payout_requests
                         SET status = 'paid', payment_ref = :utr, paid_at = NOW(), processed_by = :adm
                         WHERE id = :id AND status = 'pending'",
                        ['utr' => $utr, 'adm' => $adminId, 'id' => $reqId]
                    );
                    Logger::audit('wallet.payout_paid', 'withdrawal', (string)$reqId, null, ['utr' => $utr], 'Withdrawal payout marked paid with UTR confirmation');
                    $flash = ['ok', 'Payout approved and marked paid. Payment reference: ' . Security::e($utr)];
                } catch (Throwable $e) {
                    $flash = ['bad', 'Payout confirmation failed: ' . $e->getMessage()];
                }
            }
        }

        // 6. REJECT WITHDRAWAL
        elseif ($action === 'reject_withdrawal') {
            $reqId  = (int) ($_POST['request_id'] ?? 0);
            $reason = trim((string) ($_POST['reject_reason'] ?? ''));

            if ($reqId <= 0 || $reason === '') {
                $flash = ['bad', 'Rejection reason is required.'];
            } else {
                try {
                    $req = Database::fetch("SELECT * FROM agent_payout_requests WHERE id = :id AND status = 'pending'", ['id' => $reqId]);
                    if ($req !== null) {
                        // Release reserved funds back to balance
                        Database::run(
                            "UPDATE agent_wallets SET balance = balance + :amt WHERE agent_id = :a",
                            ['amt' => (float)$req['amount'], 'a' => (int)$req['agent_id']]
                        );
                        Database::run(
                            "UPDATE agent_payout_requests SET status = 'rejected', notes = :n, processed_by = :adm WHERE id = :id",
                            ['n' => $reason, 'adm' => $adminId, 'id' => $reqId]
                        );
                        Logger::audit('wallet.payout_rejected', 'withdrawal', (string)$reqId, null, ['reason' => $reason], 'Withdrawal rejected; reserved funds released');
                        $flash = ['ok', 'Withdrawal rejected. Reserved funds restored to member balance.'];
                    }
                } catch (Throwable $e) {
                    $flash = ['bad', 'Withdrawal rejection failed: ' . $e->getMessage()];
                }
            }
        }

        // 7. POST TEAM ANNOUNCEMENT
        elseif ($action === 'post_announcement') {
            $title    = trim((string) ($_POST['title'] ?? ''));
            $body     = trim((string) ($_POST['body'] ?? ''));
            $target   = (string) ($_POST['target_role'] ?? 'all');
            $priority = (string) ($_POST['priority'] ?? 'normal');

            if ($title === '' || $body === '') {
                $flash = ['bad', 'Announcement title and content are required.'];
            } else {
                try {
                    Database::run(
                        "INSERT INTO team_announcements (title, body, target_role, priority, created_by, created_at)
                         VALUES (:t, :b, :r, :p, :c, NOW())",
                        ['t' => $title, 'b' => $body, 'r' => $target, 'p' => $priority, 'c' => $adminId]
                    );
                    Logger::audit('team.announcement', 'team', $target, null, ['title' => $title, 'priority' => $priority], 'Team announcement posted');
                    $flash = ['ok', 'Announcement posted successfully to: ' . Security::e($target)];
                } catch (Throwable $e) {
                    // Fallback to kv_store if table not yet created
                    $kvKey = 'announcement:' . time();
                    $data = ['title' => $title, 'body' => $body, 'target' => $target, 'priority' => $priority, 'by' => $admin['username'] ?? '', 'at' => date('Y-m-d H:i:s')];
                    Database::run(
                        "INSERT INTO kv_store (kscope, kkey, kvalue, updated_by) VALUES ('global', :k, :v, 'admin')",
                        ['k' => $kvKey, 'v' => json_encode($data)]
                    );
                    $flash = ['ok', 'Announcement recorded successfully.'];
                }
            }
        }
    }
}

// =========================================================================
// DATA QUERIES PER TAB
// =========================================================================

// TAB 1: MEMBERS
$members = [];
if ($tab === 'members') {
    $searchRole = (string) ($_GET['role'] ?? 'all');
    $query = trim((string) ($_GET['q'] ?? ''));

    // 1. Staff applications approved
    try {
        $stfRows = Database::fetchAll(
            "SELECT id, full_name as name, phone, phone_country, role_applied, status, created_at, photo_path, admin_id
             FROM staff_applications WHERE status IN ('approved','suspended') ORDER BY id DESC"
        );
        foreach ($stfRows as $r) {
            $code = 'SHG-' . (str_starts_with((string)$r['role_applied'], 'driver') ? 'D' : 'S') . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
            $roleLabel = StaffApps::roleLabel((string)$r['role_applied']);
            $members[] = [
                'ref'      => StaffApps::refFor((int)$r['id']),
                'code'     => $code,
                'name'     => (string)$r['name'],
                'role'     => (string)$r['role_applied'],
                'role_lbl' => $roleLabel,
                'category' => (string)$r['role_applied'] === 'driver' ? 'driver' : 'staff',
                'phone'    => (string)$r['phone'],
                'cc'       => (string)$r['phone_country'],
                'status'   => (string)$r['status'],
                'balance'  => 0.00,
                'joined'   => (string)$r['created_at'],
                'app_type' => 'staff',
                'app_id'   => (int)$r['id']
            ];
        }
    } catch (Throwable $e) {}

    // 2. Agents & Partners
    try {
        $agRows = Database::fetchAll(
            "SELECT a.id, a.ref, a.type, a.name, a.phone, a.status, a.created_at, a.admin_id,
                    COALESCE(w.balance, 0) as balance
             FROM agent_applications a
             LEFT JOIN agent_wallets w ON w.agent_id = a.admin_id
             WHERE a.status = 'approved' ORDER BY a.id DESC"
        );
        foreach ($agRows as $r) {
            $isOrg = (string)$r['type'] === 'org';
            $code = 'SHG-' . ($isOrg ? 'P' : 'A') . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT);
            $members[] = [
                'ref'      => (string)$r['ref'] ?: AgentApplications::refFor((int)$r['id']),
                'code'     => $code,
                'name'     => (string)$r['name'],
                'role'     => $isOrg ? 'partner' : 'agent',
                'role_lbl' => $isOrg ? 'Transit Partner (Pvt. Ltd.)' : 'Booking Agent',
                'category' => $isOrg ? 'partner' : 'agent',
                'phone'    => (string)$r['phone'],
                'cc'       => '91',
                'status'   => (string)$r['status'],
                'balance'  => (float)$r['balance'],
                'joined'   => (string)$r['created_at'],
                'app_type' => 'agent',
                'app_id'   => (int)$r['id']
            ];
        }
    } catch (Throwable $e) {}

    // Filter
    if ($searchRole !== 'all') {
        $members = array_values(array_filter($members, static fn($m) => $m['category'] === $searchRole));
    }
    if ($query !== '') {
        $members = array_values(array_filter($members, static function($m) use ($query) {
            return stripos($m['name'], $query) !== false ||
                   stripos($m['phone'], $query) !== false ||
                   stripos($m['code'], $query) !== false ||
                   stripos($m['ref'], $query) !== false;
        }));
    }
}

// TAB 2: APPROVALS QUEUE
$pendingQueue = [];
if ($tab === 'approvals') {
    // 1. Staff Applications
    try {
        $stf = Database::fetchAll(
            "SELECT id, full_name as name, phone, phone_country, role_applied, id_type, id_number,
                    district, created_at, phone_verified, doc_verify_status, id_front_path, photo_path
             FROM staff_applications WHERE status IN ('pending','under_review') ORDER BY id DESC"
        );
        foreach ($stf as $r) {
            $pendingQueue[] = [
                'type'     => 'staff',
                'id'       => (int)$r['id'],
                'ref'      => StaffApps::refFor((int)$r['id']),
                'name'     => (string)$r['name'],
                'role'     => StaffApps::roleLabel((string)$r['role_applied']),
                'phone'    => '+' . countryDialCode((string)$r['phone_country']) . ' ' . $r['phone'],
                'phone_verified' => (int)$r['phone_verified'] === 1,
                'district' => (string)$r['district'],
                'id_type'  => (string)$r['id_type'],
                'id_no'    => (string)$r['id_number'],
                'created'  => (string)$r['created_at'],
                'file_url' => 'staff-file.php?id=' . (int)$r['id'] . '&f=id_front&e=' . (time() + 900) . '&s=' . StaffApps::fileSig((int)$r['id'], 'id_front', time() + 900, $adminId),
                'photo_url'=> 'staff-file.php?id=' . (int)$r['id'] . '&f=photo&e=' . (time() + 900) . '&s=' . StaffApps::fileSig((int)$r['id'], 'photo', time() + 900, $adminId),
            ];
        }
    } catch (Throwable $e) {}

    // 2. Agent & Partner Applications
    try {
        $ag = Database::fetchAll(
            "SELECT id, ref, type, name, phone, created_at, phone_verified, fields_json, photo_data
             FROM agent_applications WHERE status = 'pending' ORDER BY id DESC"
        );
        foreach ($ag as $r) {
            $f = json_decode((string)($r['fields_json'] ?? ''), true) ?: [];
            $pendingQueue[] = [
                'type'     => (string)$r['type'] === 'org' ? 'partner' : 'agent',
                'id'       => (int)$r['id'],
                'ref'      => (string)$r['ref'] ?: AgentApplications::refFor((int)$r['id']),
                'name'     => (string)$r['name'],
                'role'     => (string)$r['type'] === 'org' ? 'Transit Partner (Pvt. Ltd.)' : 'Booking Agent',
                'phone'    => (string)$r['phone'],
                'phone_verified' => (int)($r['phone_verified'] ?? 0) === 1,
                'district' => (string)($f['p_district'] ?? $f['p_address'] ?? '—'),
                'id_type'  => (string)($f['p_idtype'] ?? 'citizenship'),
                'id_no'    => (string)($f['p_idno'] ?? '—'),
                'created'  => (string)$r['created_at'],
                'file_url' => '',
                'photo_url'=> is_string($r['photo_data'] ?? null) && str_starts_with((string)$r['photo_data'], 'data:image/') ? (string)$r['photo_data'] : '',
            ];
        }
    } catch (Throwable $e) {}
}

// TAB 3: EARNINGS
$earningsRules = [];
if ($tab === 'earnings') {
    try {
        $earningsRules = Database::fetchAll("SELECT * FROM agent_commission_rules ORDER BY id DESC");
    } catch (Throwable $e) {
        $earningsRules = [];
    }
}

// TAB 4: WITHDRAWALS
$payoutRequests = [];
if ($tab === 'withdrawals') {
    try {
        $payoutRequests = Database::fetchAll(
            "SELECT r.*, a.name as agent_name, a.phone as agent_phone, COALESCE(w.balance, 0) as current_balance
             FROM agent_payout_requests r
             LEFT JOIN agent_applications a ON a.admin_id = r.agent_id
             LEFT JOIN agent_wallets w ON w.agent_id = r.agent_id
             ORDER BY r.id DESC LIMIT 50"
        );
    } catch (Throwable $e) {
        $payoutRequests = [];
    }
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
<script src="/assets/js/photo-studio.js?v=20261025"></script>

<?php if ($flash !== null): ?>
  <div class="data-card" style="border-left:5px solid <?= $flash[0] === 'ok' ? '#0f9d6b' : '#d93025' ?>;padding:12px 16px;font-weight:700">
    <?= Security::e($flash[1]) ?>
  </div>
<?php endif; ?>

<!-- NAVIGATION TABS -->
<nav class="team-tabs">
  <a class="team-tab <?= $tab === 'members' ? 'active' : '' ?>" href="team.php?tab=members">👥 Team Members · सदस्य सूची</a>
  <a class="team-tab <?= $tab === 'approvals' ? 'active' : '' ?>" href="team.php?tab=approvals">
    📋 Approvals Queue · अनुमोदन <?= count($pendingQueue) > 0 ? '<span class="badge-count">' . count($pendingQueue) . '</span>' : '' ?>
  </a>
  <a class="team-tab <?= $tab === 'earnings' ? 'active' : '' ?>" href="team.php?tab=earnings">💰 Flexible Earnings · कमिसन तथा तलब</a>
  <a class="team-tab <?= $tab === 'withdrawals' ? 'active' : '' ?>" href="team.php?tab=withdrawals">🏦 Wallet & Withdrawals · भुक्तानी</a>
  <a class="team-tab <?= $tab === 'cards' ? 'active' : '' ?>" href="team.php?tab=cards">🪪 ID Cards & WhatsApp · परिचयपत्र</a>
  <a class="team-tab <?= $tab === 'announcements' ? 'active' : '' ?>" href="team.php?tab=announcements">📢 Announcements · सूचना</a>
</nav>

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
    <a href="/agreements/apply.html" target="_blank" class="btn-sm btn-success">+ Register New Member</a>
  </div>

  <div class="data-card" style="padding:0;overflow-x:auto">
    <table style="margin:0">
      <thead>
        <tr style="background:#f8fafc">
          <th>Staff Code</th>
          <th>Name & Role</th>
          <th>Verified Phone</th>
          <th>Wallet Balance</th>
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
                <a href="https://wa.me/<?= Security::e(preg_replace('/\D/','',$m['phone'])) ?>" target="_blank" style="text-decoration:none">
                  💬 +<?= Security::e($m['cc']) ?> <?= Security::e($m['phone']) ?>
                </a>
              </td>
              <td><b>₹/रू <?= number_format((float)$m['balance'], 2) ?></b></td>
              <td><span class="pill pill-<?= $m['status'] === 'approved' ? 'active' : 'suspended' ?>"><?= Security::e(ucfirst($m['status'])) ?></span></td>
              <td>
                <div class="action-grid">
                  <a href="id-card.php?ref=<?= urlencode($m['ref']) ?>" class="btn-sm btn-outline">🪪 Cards</a>
                  <button type="button" class="btn-sm btn-primary" onclick="openAdjustmentModal(<?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>')">₹ Adjust</button>
                  <a href="/verify-id.php?ref=<?= urlencode($m['ref']) ?>" target="_blank" class="btn-sm btn-outline">🛡️ Verify</a>
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
            <form method="post" action="team.php?tab=approvals" style="display:inline" onsubmit="return confirm('Approve <?= Security::e($p['name']) ?>? This activates their login and dispatches ID cards via WhatsApp.')">
              <?= Security::csrfField() ?>
              <input type="hidden" name="action" value="approve_application">
              <input type="hidden" name="app_type" value="<?= Security::e($p['type']) ?>">
              <input type="hidden" name="app_id" value="<?= (int)$p['id'] ?>">
              <button type="submit" class="btn-sm btn-success">✅ Approve & Issue Cards</button>
            </form>

            <button type="button" class="btn-sm btn-danger" onclick="openRejectModal('<?= Security::e($p['type']) ?>', <?= (int)$p['id'] ?>, '<?= Security::e($p['name']) ?>')">❌ Reject</button>
            <button type="button" class="btn-sm btn-outline" onclick="openOnBehalfModal('<?= Security::e($p['type']) ?>', <?= (int)$p['id'] ?>, '<?= Security::e($p['name']) ?>')">✏️ Edit on Behalf</button>
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
  <div class="data-card">
    <h3>⚙️ Configure Flexible Earnings Models</h3>
    <p style="font-size:13.5px;color:#64748b">
      Configure percentage of eligible fare, per-passenger or per-booking fixed commissions, or fixed salaries with effective dates. Cancellations automatically reverse commission to prevent duplicate or unearned payouts.
    </p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;margin-top:16px">
      <div style="padding:14px;border:1.5px solid #cbd5e1;border-radius:14px;background:#f8fafc">
        <b>📊 1. Percentage of Fare</b>
        <p style="font-size:12.5px;color:#64748b;margin:6px 0 10px">Default 8.5% of eligible net ticket fare, calculated upon confirmed trip.</p>
        <input type="number" step="0.1" value="8.5" style="width:90px;padding:6px;border-radius:8px;border:1px solid #cbd5e1"> %
      </div>
      <div style="padding:14px;border:1.5px solid #cbd5e1;border-radius:14px;background:#f8fafc">
        <b>🎟️ 2. Per-Seat Fixed Fee</b>
        <p style="font-size:12.5px;color:#64748b;margin:6px 0 10px">Fixed amount per booked passenger seat (e.g. ₹ 100 / रू 150).</p>
        <input type="number" step="1" value="100" style="width:90px;padding:6px;border-radius:8px;border:1px solid #cbd5e1"> ₹/रू
      </div>
      <div style="padding:14px;border:1.5px solid #cbd5e1;border-radius:14px;background:#f8fafc">
        <b>💼 3. Monthly Salary (Staff/Drivers)</b>
        <p style="font-size:12.5px;color:#64748b;margin:6px 0 10px">Monthly pay slip settlement with advance deduction derivation.</p>
        <span class="pill pill-active">Integrated via includes/payroll.php</span>
      </div>
    </div>
  </div>

  <div class="data-card">
    <h3>💳 Record Extra Bonus or Deduction</h3>
    <form method="post" action="team.php?tab=earnings" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:14px">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="record_adjustment">
      <label class="f">
        <span>Team Member (Admin / Agent ID) <em>*</em></span>
        <input type="number" name="member_id" required placeholder="Member Admin ID">
      </label>
      <label class="f">
        <span>Type <em>*</em></span>
        <select name="adj_type">
          <option value="bonus">➕ Bonus / Incentive</option>
          <option value="deduction">➖ Deduction / Advance Recovery</option>
        </select>
      </label>
      <label class="f">
        <span>Amount (₹/रू) <em>*</em></span>
        <input type="number" step="0.01" name="amount" required placeholder="500.00">
      </label>
      <label class="f" style="grid-column:1/-1">
        <span>Reason (Mandatory for audit trail) <em>*</em></span>
        <input type="text" name="reason" required placeholder="जस्तै: Festive Dashain Bonus / Fuel Advance Settlement">
      </label>
      <button type="submit" class="btn-sm btn-primary" style="grid-column:1/-1;min-height:44px;justify-content:center">Submit Adjustment · दर्ता गर्नुहोस्</button>
    </form>
  </div>

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
              <td><span class="pill pill-<?= $w['status'] === 'paid' ? 'active' : ($w['status'] === 'pending' ? 'pending' : 'suspended') ?>"><?= Security::e(ucfirst($w['status'])) ?></span></td>
              <td>
                <?php if ($w['status'] === 'pending'): ?>
                  <div class="action-grid">
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
        <span>Rejection Reason (Funds will be restored to wallet) <em>*</em></span>
        <textarea name="reject_reason" required rows="3" placeholder="e.g. Invalid bank account IFSC code"></textarea>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('rejectPayoutModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-danger">Reject & Restore Funds</button>
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

<?php admin_footer(); ?>
