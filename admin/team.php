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
if (!in_array($tab, ['members', 'approvals', 'earnings', 'withdrawals', 'cards', 'announcements', 'archived'], true)) {
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
                            "INSERT INTO drivers (full_name, phone, licence_no, is_active, created_at)
                             VALUES (:n, :p, :l, 1, NOW())
                             ON DUPLICATE KEY UPDATE is_active = 1, full_name = :n2",
                            ['n' => $app['full_name'], 'p' => normalisePhone((string)$app['phone']), 'l' => (string)($app['license_number'] ?? ''), 'n2' => $app['full_name']]
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

                    // Auto-provision admin account if not already linked
                    $linkedAdminId = (int) ($app['admin_id'] ?? 0);
                    $tempPin = null;
                    $uName = null;
                    if ($linkedAdminId <= 0) {
                        $phone = preg_replace('/\D/', '', (string) $app['phone']);
                        if (strlen($phone) >= 10) {
                            $uName = substr($phone, -10);
                            $baseU = $uName;
                            for ($i = 2; Database::exists('SELECT 1 FROM admins WHERE username = :u LIMIT 1', ['u' => $uName]); $i++) {
                                $uName = $baseU . '_' . $i;
                            }
                            $tempPin = (string) random_int(100000, 999999);
                            $newAdminId = (int) Database::insert('admins', [
                                'username'       => $uName,
                                'password_hash'  => Security::hashPassword($tempPin),
                                'full_name'      => (string) ($app['name'] ?? 'Agent'),
                                'email'          => null,
                                'phone'          => (string) $app['phone'],
                                'role'           => 'agent',
                                'is_active'      => 1,
                                'must_change_pw' => 1,
                            ]);
                            if ($newAdminId > 0) {
                                $linkedAdminId = $newAdminId;
                                $kind = ((string)($app['type'] ?? 'ind') === 'org') ? AgentWallet::KIND_ORG : AgentWallet::KIND_PERSON;
                                $code = AgentWallet::nextFreeAgentCode($kind);
                                if ($code !== null) {
                                    AgentWallet::setAgentCode($newAdminId, $code, $adminId);
                                }
                                $parentId = !empty($app['parent_agent_id']) ? (int) $app['parent_agent_id'] : null;
                                Database::run(
                                    "INSERT INTO admin_profiles (admin_id, display_phone, whatsapp, counter_name, agent_kind, parent_agent_id)
                                     VALUES (:aid, :p, :wa, :cn, :k, :pid)
                                     ON DUPLICATE KEY UPDATE display_phone = :p2, whatsapp = :wa2, counter_name = :cn2, agent_kind = :k2, parent_agent_id = :pid2",
                                    [
                                        'aid' => $newAdminId, 'p' => (string) $app['phone'], 'wa' => (string) $app['phone'],
                                        'cn' => (string) ($app['area'] ?? ''), 'k' => $kind, 'pid' => $parentId,
                                        'p2' => (string) $app['phone'], 'wa2' => (string) $app['phone'],
                                        'cn2' => (string) ($app['area'] ?? ''), 'k2' => $kind, 'pid2' => $parentId,
                                    ]
                                );
                            }
                        }
                    }

                    AgentApplications::update($id, [
                        'status'      => 'approved',
                        'reviewed_by' => $adminId,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'admin_id'    => $linkedAdminId ?: null,
                    ], $adminId);
                    $ref = AgentApplications::refFor($id);
                }

                // Auto-dispatch Cards via WhatsApp Cloud API
                $deliveryRes = CardDelivery::send($ref, false);

                $waPhone = preg_replace('/\D/', '', (string) ($app['phone'] ?? ''));
                if (strlen($waPhone) === 10) { $waPhone = '91' . $waPhone; }
                $loginMsg = "Namaste " . ($app['name'] ?? $app['full_name'] ?? '') . "! Swagatam to S Hari Global Pvt Ltd.\n"
                          . "Your Portal is now APPROVED & ACTIVATED.\n"
                          . "Login: https://www.shreehariglobal.in/admin/login.php\n"
                          . ($uName ? "Username: " . $uName . "\n" : "")
                          . ($tempPin ? "Temporary PIN: " . $tempPin . "\n" : "")
                          . "Official Ref: " . $ref . "\n"
                          . "ID Card & Agreement: https://www.shreehariglobal.in/verify-id.php?c=" . $ref . "\n"
                          . "Shubha yatra!";
                $waBtn = '<a href="https://wa.me/' . $waPhone . '?text=' . rawurlencode($loginMsg) . '" target="_blank" class="btn ok" style="padding:4px 10px;font-size:12px;margin-left:8px;text-decoration:none">📲 Send Login &amp; ID on WhatsApp</a>';

                Logger::audit('team.approved', $type, $ref, null, ['admin' => $admin['username'] ?? ''], 'Application approved; login activated; cards generated');
                $flash = ['ok', 'Application ' . $ref . ' approved! Cards generated and WhatsApp dispatch initiated: ' . $deliveryRes['detail'] . ' ' . $waBtn];
            } catch (Throwable $e) {
                Logger::error('Approve failed: ' . $e->getMessage());
                $flash = ['bad', 'Approval failed: ' . $e->getMessage()];
            }
        }

        // 1B. REQUEST CORRECTION
        elseif ($action === 'request_correction') {
            $type    = (string) ($_POST['app_type'] ?? 'agent');
            $id      = (int) ($_POST['app_id'] ?? 0);
            $fields  = implode(', ', (array) ($_POST['correction_fields'] ?? []));
            $notes   = trim(Security::clean((string) ($_POST['correction_notes'] ?? ''), 500));

            if ($notes === '' && $fields === '') {
                $flash = ['bad', 'Please specify which fields or documents need correction.'];
            } else {
                try {
                    if ($type === 'staff') {
                        Database::run(
                            "UPDATE staff_applications SET status = 'reviewing', correction_fields = :cf, correction_notes = :cn WHERE id = :id",
                            ['cf' => $fields, 'cn' => $notes, 'id' => $id]
                        );
                        $ref   = StaffApps::refFor($id);
                        $phone = (string) Database::scalar("SELECT phone FROM staff_applications WHERE id = :id", ['id' => $id]);
                        $name  = (string) Database::scalar("SELECT full_name FROM staff_applications WHERE id = :id", ['id' => $id]);
                    } else {
                        Database::run(
                            "UPDATE agent_applications SET status = 'reviewing', kyc_status = 'rejected', correction_fields = :cf, correction_notes = :cn WHERE id = :id",
                            ['cf' => $fields, 'cn' => $notes, 'id' => $id]
                        );
                        $ref   = AgentApplications::refFor($id);
                        $phone = (string) Database::scalar("SELECT phone FROM agent_applications WHERE id = :id", ['id' => $id]);
                        $name  = (string) Database::scalar("SELECT name FROM agent_applications WHERE id = :id", ['id' => $id]);
                    }
                    $digits = preg_replace('/\D/', '', $phone);
                    if (strlen($digits) === 10) { $digits = '91' . $digits; }
                    $trackerUrl = 'https://www.shreehariglobal.in/agreements/apply.html?track=' . urlencode($ref);
                    $waMsg = "Namaste " . $name . "! S Hari Global Pvt Ltd Application (" . $ref . ") Update:\n"
                           . "Please re-upload / correct the following details: " . ($fields ?: $notes) . ".\n"
                           . ($notes ? "Notes: " . $notes . "\n" : "")
                           . "Direct Status & Re-upload Link: " . $trackerUrl . "\n"
                           . "Dhanyabad!";
                    $waLink = "https://wa.me/" . $digits . "?text=" . rawurlencode($waMsg);
                    Logger::audit('team.correction_requested', $type, $ref, null, ['fields' => $fields, 'notes' => $notes], 'Correction requested');
                    $flash = ['ok', 'Correction requested for ' . $ref . '. <a href="' . Security::e($waLink) . '" target="_blank" class="btn ok" style="padding:4px 10px;font-size:12px;margin-left:8px;text-decoration:none">💬 Send WhatsApp Instructions Now</a>'];
                } catch (Throwable $e) {
                    $flash = ['bad', 'Correction request failed: ' . $e->getMessage()];
                }
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
        // 8. DISABLE / DEACTIVATE MEMBER
        elseif ($action === 'disable_member') {
            $type   = (string) ($_POST['app_type'] ?? 'staff');
            $id     = (int) ($_POST['app_id'] ?? 0);
            $reason = trim(Security::clean((string) ($_POST['reason'] ?? 'Account disabled by administrator'), 300));

            try {
                if ($type === 'staff') {
                    $app = StaffApps::find($id);
                    if ($app === null) throw new RuntimeException('Staff member not found.');
                    Database::run("UPDATE staff_applications SET status = 'suspended', office_note = :r WHERE id = :id", [
                        'r' => $reason, 'id' => $id
                    ]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 0 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    if ((string)$app['role_applied'] === 'driver' || str_starts_with((string)$app['role_applied'], 'driver')) {
                        $driver = Database::fetch("SELECT id FROM drivers WHERE phone = :p", ['p' => normalisePhone((string)$app['phone'])]);
                        if ($driver !== null) {
                            $did = (int) $driver['id'];
                            Database::run("UPDATE drivers SET is_active = 0 WHERE id = :did", ['did' => $did]);
                            Database::run("UPDATE driver_devices SET revoked_at = NOW() WHERE driver_id = :did AND revoked_at IS NULL", ['did' => $did]);
                            Database::run("UPDATE schedules SET driver_id = NULL WHERE driver_id = :did AND travel_date >= CURDATE()", ['did' => $did]);
                        }
                    }
                    $ref = StaffApps::refFor($id);
                } else {
                    $app = AgentApplications::find($id);
                    if ($app === null) throw new RuntimeException('Agent/Partner not found.');
                    Database::run("UPDATE agent_applications SET status = 'rejected', note = :r WHERE id = :id", [
                        'r' => $reason, 'id' => $id
                    ]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 0 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    $ref = (string)$app['ref'] ?: AgentApplications::refFor($id);
                }
                Logger::audit('team.disabled', $type, $ref, null, ['reason' => $reason, 'admin' => $admin['username'] ?? ''], 'Account disabled; active sessions revoked; driver unassigned from future schedules');
                $flash = ['ok', "Member {$ref} disabled successfully. Active login sessions revoked, GPS submissions blocked, and upcoming driver trips unassigned."];
            } catch (Throwable $e) {
                $flash = ['bad', 'Disable failed: ' . $e->getMessage()];
            }
        }

        // 9. ARCHIVE DISABLED / INACTIVE MEMBER
        elseif ($action === 'archive_member') {
            $type   = (string) ($_POST['app_type'] ?? 'staff');
            $id     = (int) ($_POST['app_id'] ?? 0);
            $reason = trim(Security::clean((string) ($_POST['reason'] ?? 'Account archived'), 300));

            try {
                $archiveKey = $type . ':' . $id;
                $archives = Settings::getArray('team_manager_archive', []);
                $archives[$archiveKey] = [
                    'at'      => date('Y-m-d H:i:s'),
                    'by'      => (int) $admin['id'],
                    'by_name' => (string) ($admin['username'] ?? 'admin'),
                    'reason'  => $reason
                ];
                Settings::set('team_manager_archive', $archives, 'json', 'team', false);

                if ($type === 'staff') {
                    $app = StaffApps::find($id);
                    if ($app === null) throw new RuntimeException('Staff member not found.');
                    Database::run("UPDATE staff_applications SET status = 'exited', office_note = :r WHERE id = :id", [
                        'r' => $reason, 'id' => $id
                    ]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 0 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    if ((string)$app['role_applied'] === 'driver' || str_starts_with((string)$app['role_applied'], 'driver')) {
                        $driver = Database::fetch("SELECT id FROM drivers WHERE phone = :p", ['p' => normalisePhone((string)$app['phone'])]);
                        if ($driver !== null) {
                            $did = (int) $driver['id'];
                            Database::run("UPDATE drivers SET is_active = 0 WHERE id = :did", ['did' => $did]);
                            Database::run("UPDATE driver_devices SET revoked_at = NOW() WHERE driver_id = :did AND revoked_at IS NULL", ['did' => $did]);
                            Database::run("UPDATE schedules SET driver_id = NULL WHERE driver_id = :did AND travel_date >= CURDATE()", ['did' => $did]);
                        }
                    }
                    $ref = StaffApps::refFor($id);
                } else {
                    $app = AgentApplications::find($id);
                    if ($app === null) throw new RuntimeException('Agent/Partner not found.');
                    Database::run("UPDATE agent_applications SET status = 'rejected', note = :r WHERE id = :id", [
                        'r' => $reason, 'id' => $id
                    ]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 0 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    $ref = (string)$app['ref'] ?: AgentApplications::refFor($id);
                }
                Logger::audit('team.archived', $type, $ref, null, ['reason' => $reason, 'admin' => $admin['username'] ?? ''], 'Account moved to archive; business records preserved');
                $flash = ['ok', "Member {$ref} archived successfully. Historical bookings, GPS trip records, payroll, and commissions remain safely preserved."];
            } catch (Throwable $e) {
                $flash = ['bad', 'Archive failed: ' . $e->getMessage()];
            }
        }

        // 10. RESTORE ARCHIVED OR DISABLED MEMBER
        elseif ($action === 'restore_member') {
            $type = (string) ($_POST['app_type'] ?? 'staff');
            $id   = (int) ($_POST['app_id'] ?? 0);

            try {
                $archiveKey = $type . ':' . $id;
                $archives = Settings::getArray('team_manager_archive', []);
                if (isset($archives[$archiveKey])) {
                    unset($archives[$archiveKey]);
                    Settings::set('team_manager_archive', $archives, 'json', 'team', false);
                }

                if ($type === 'staff') {
                    $app = StaffApps::find($id);
                    if ($app === null) throw new RuntimeException('Staff member not found.');
                    Database::run("UPDATE staff_applications SET status = 'approved' WHERE id = :id", ['id' => $id]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 1 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    if ((string)$app['role_applied'] === 'driver' || str_starts_with((string)$app['role_applied'], 'driver')) {
                        $driver = Database::fetch("SELECT id FROM drivers WHERE phone = :p", ['p' => normalisePhone((string)$app['phone'])]);
                        if ($driver !== null) {
                            Database::run("UPDATE drivers SET is_active = 1 WHERE id = :did", ['did' => (int) $driver['id']]);
                        }
                    }
                    $ref = StaffApps::refFor($id);
                } else {
                    $app = AgentApplications::find($id);
                    if ($app === null) throw new RuntimeException('Agent/Partner not found.');
                    Database::run("UPDATE agent_applications SET status = 'approved' WHERE id = :id", ['id' => $id]);
                    $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                    if ($adminIdLinked > 0) {
                        Database::run("UPDATE admins SET is_active = 1 WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }
                    $ref = (string)$app['ref'] ?: AgentApplications::refFor($id);
                }
                Logger::audit('team.restored', $type, $ref, null, ['admin' => $admin['username'] ?? ''], 'Account restored to active standing');
                $flash = ['ok', "Member {$ref} restored successfully. Portal access, driver credentials, and authorizations re-activated."];
            } catch (Throwable $e) {
                $flash = ['bad', 'Restore failed: ' . $e->getMessage()];
            }
        }

        // 11. PERMANENT DELETION (ONLY AFTER ZERO-DEPENDENCY CHECK)
        elseif ($action === 'delete_member') {
            $type    = (string) ($_POST['app_type'] ?? 'staff');
            $id      = (int) ($_POST['app_id'] ?? 0);
            $confirm = trim((string) ($_POST['confirm_delete'] ?? ''));

            if (!Auth::isSuperadmin() && !Auth::can('staff.manage')) {
                throw new RuntimeException('Permission denied. Super-admin or staff management authorization required.');
            }

            if ($confirm !== 'DELETE') {
                $flash = ['bad', 'To prevent accidental deletion, you must type DELETE in all capitals.'];
            } else {
                try {
                    $deps = [];
                    $adminIdLinked = 0;
                    $phone = '';

                    if ($type === 'staff') {
                        $app = StaffApps::find($id);
                        if ($app === null) throw new RuntimeException('Staff member not found.');
                        $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                        $phone = normalisePhone((string)$app['phone']);
                        $ref = StaffApps::refFor($id);
                    } else {
                        $app = AgentApplications::find($id);
                        if ($app === null) throw new RuntimeException('Agent/Partner not found.');
                        $adminIdLinked = (int) ($app['admin_id'] ?? 0);
                        $phone = normalisePhone((string)$app['phone']);
                        $ref = (string)$app['ref'] ?: AgentApplications::refFor($id);
                    }

                    // Check Bookings
                    if ($adminIdLinked > 0) {
                        $bkCount = (int) Database::scalar("SELECT COUNT(*) FROM bookings WHERE sold_by_admin_id = :aid", ['aid' => $adminIdLinked], 0);
                        if ($bkCount > 0) { $deps[] = "{$bkCount} customer booking(s) sold"; }

                        // Check Wallet & Transactions
                        $txCount = (int) Database::scalar("SELECT COUNT(*) FROM agent_transactions WHERE agent_id = :aid", ['aid' => $adminIdLinked], 0);
                        if ($txCount > 0) { $deps[] = "{$txCount} wallet transaction(s)"; }

                        $wBal = (float) Database::scalar("SELECT balance FROM agent_wallets WHERE agent_id = :aid", ['aid' => $adminIdLinked], 0);
                        if (abs($wBal) > 0.01) { $deps[] = "wallet balance of ₹/रू " . number_format($wBal, 2); }

                        $poCount = (int) Database::scalar("SELECT COUNT(*) FROM agent_payout_requests WHERE agent_id = :aid", ['aid' => $adminIdLinked], 0);
                        if ($poCount > 0) { $deps[] = "{$poCount} payout withdrawal request(s)"; }
                    }

                    // Check Driver trips & GPS logs
                    if ($phone !== '') {
                        $driver = Database::fetch("SELECT id FROM drivers WHERE phone = :p", ['p' => $phone]);
                        if ($driver !== null) {
                            $did = (int) $driver['id'];
                            $tripCount = (int) Database::scalar("SELECT COUNT(*) FROM live_trips WHERE driver_id = :did", ['did' => $did], 0);
                            if ($tripCount > 0) { $deps[] = "{$tripCount} live GPS trip record(s)"; }

                            $schedCount = (int) Database::scalar("SELECT COUNT(*) FROM schedules WHERE driver_id = :did", ['did' => $did], 0);
                            if ($schedCount > 0) { $deps[] = "{$schedCount} assigned schedule(s)"; }
                        }
                    }

                    // Check Audit Logs
                    if ($adminIdLinked > 0) {
                        $logCount = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs WHERE user_id = :aid OR admin_id = :aid2", ['aid' => $adminIdLinked, 'aid2' => $adminIdLinked], 0);
                        if ($logCount > 0) { $deps[] = "{$logCount} official audit log entries"; }
                    }

                    // If dependencies exist, BLOCK deletion
                    if ($deps !== []) {
                        $depList = implode(', ', $deps);
                        throw new RuntimeException("Permanent deletion BLOCKED. This account cannot be cascade-deleted because of historical business records: {$depList}. Financial, tax, and transport compliance laws require preserving business records. Please ARCHIVE this account instead.");
                    }

                    // If NO business records exist (unlinked draft / test record):
                    if ($type === 'staff') {
                        Database::run("DELETE FROM staff_applications WHERE id = :id", ['id' => $id]);
                    } else {
                        Database::run("DELETE FROM agent_applications WHERE id = :id", ['id' => $id]);
                    }
                    if ($adminIdLinked > 0) {
                        Database::run("DELETE FROM admin_profiles WHERE admin_id = :aid", ['aid' => $adminIdLinked]);
                        Database::run("DELETE FROM admins WHERE id = :aid", ['aid' => $adminIdLinked]);
                    }

                    Logger::audit('team.deleted', $type, $ref, null, ['admin' => $admin['username'] ?? '', 'reason' => 'Zero dependencies, permanent deletion explicitly confirmed'], 'Account permanently deleted after dependency check');
                    $flash = ['ok', "Account {$ref} had zero business dependencies and was permanently deleted. Audit record created."];
                } catch (Throwable $e) {
                    $flash = ['bad', $e->getMessage()];
                }
            }
        }
    }
}

// =========================================================================
// DATA QUERIES PER TAB
// =========================================================================

// QUERY MEMBERS & ARCHIVED LIST
$allUnifiedMembers = [];
$archivedArchives  = Settings::getArray('team_manager_archive', []);

try {
    // 1. Staff & Drivers
    $stfRows = Database::fetchAll(
        "SELECT s.id, s.full_name as name, s.phone, s.phone_country, s.role_applied, s.status, s.created_at, s.admin_id, s.office_note,
                COALESCE(a.is_active, 1) as admin_active,
                (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = s.admin_id) as booking_count,
                (SELECT COUNT(*) FROM live_trips lt JOIN drivers d ON d.id = lt.driver_id WHERE d.phone = s.phone) as trip_count
         FROM staff_applications s
         LEFT JOIN admins a ON a.id = s.admin_id
         ORDER BY s.id DESC"
    );
    foreach ($stfRows as $r) {
        $sid = (int) $r['id'];
        $archiveKey = 'staff:' . $sid;
        $isArchived = isset($archivedArchives[$archiveKey]) || (string)$r['status'] === 'exited';
        $isAdminActive = (int)$r['admin_active'] === 1;
        $isSuspended = (string)$r['status'] === 'suspended' || (!$isAdminActive && !empty($r['admin_id']));

        if ($isArchived) {
            $computedStatus = 'archived';
        } elseif ($isSuspended) {
            $computedStatus = 'disabled';
        } elseif ((string)$r['status'] === 'approved') {
            $computedStatus = 'active';
        } else {
            $computedStatus = 'inactive';
        }

        $code = 'SHG-' . (str_starts_with((string)$r['role_applied'], 'driver') ? 'D' : 'S') . str_pad((string)$sid, 4, '0', STR_PAD_LEFT);
        $roleLabel = StaffApps::roleLabel((string)$r['role_applied']);
        $archiveMeta = $archivedArchives[$archiveKey] ?? null;

        $allUnifiedMembers[] = [
            'ref'             => StaffApps::refFor($sid),
            'code'            => $code,
            'name'            => (string)$r['name'],
            'role'            => (string)$r['role_applied'],
            'role_lbl'        => $roleLabel,
            'category'        => (string)$r['role_applied'] === 'driver' ? 'driver' : 'staff',
            'phone'           => (string)$r['phone'],
            'cc'              => (string)$r['phone_country'],
            'status'          => (string)$r['status'],
            'computed_status' => $computedStatus,
            'balance'         => 0.00,
            'joined'          => (string)$r['created_at'],
            'app_type'        => 'staff',
            'app_id'          => $sid,
            'admin_id'        => (int)($r['admin_id'] ?? 0),
            'archive_meta'    => $archiveMeta,
            'booking_count'   => (int)($r['booking_count'] ?? 0),
            'trip_count'      => (int)($r['trip_count'] ?? 0),
            'tx_count'        => 0,
            'note'            => (string)($r['office_note'] ?? '')
        ];
    }
} catch (Throwable $e) {}

try {
    // 2. Agents & Transit Partners
    $agRows = Database::fetchAll(
        "SELECT a.id, a.ref, a.type, a.name, a.phone, a.status, a.created_at, a.admin_id, a.note,
                COALESCE(w.balance, 0) as balance,
                COALESCE(adm.is_active, 1) as admin_active,
                (SELECT COUNT(*) FROM bookings b WHERE b.sold_by_admin_id = a.admin_id) as booking_count,
                (SELECT COUNT(*) FROM agent_transactions tx WHERE tx.agent_id = a.admin_id) as tx_count
         FROM agent_applications a
         LEFT JOIN agent_wallets w ON w.agent_id = a.admin_id
         LEFT JOIN admins adm ON adm.id = a.admin_id
         ORDER BY a.id DESC"
    );
    foreach ($agRows as $r) {
        $aid = (int) $r['id'];
        $archiveKey = 'agent:' . $aid;
        $isArchived = isset($archivedArchives[$archiveKey]);
        $isAdminActive = (int)$r['admin_active'] === 1;
        $isSuspended = (string)$r['status'] === 'rejected' || (!$isAdminActive && !empty($r['admin_id']));

        if ($isArchived) {
            $computedStatus = 'archived';
        } elseif ($isSuspended) {
            $computedStatus = 'disabled';
        } elseif ((string)$r['status'] === 'approved') {
            $computedStatus = 'active';
        } else {
            $computedStatus = 'inactive';
        }

        $isOrg = (string)$r['type'] === 'org';
        $code = 'SHG-' . ($isOrg ? 'P' : 'A') . str_pad((string)$aid, 4, '0', STR_PAD_LEFT);
        $archiveMeta = $archivedArchives[$archiveKey] ?? null;

        $allUnifiedMembers[] = [
            'ref'             => (string)$r['ref'] ?: AgentApplications::refFor($aid),
            'code'            => $code,
            'name'            => (string)$r['name'],
            'role'            => $isOrg ? 'partner' : 'agent',
            'role_lbl'        => $isOrg ? 'Transit Partner (Pvt. Ltd.)' : 'Booking Agent',
            'category'        => $isOrg ? 'partner' : 'agent',
            'phone'           => (string)$r['phone'],
            'cc'              => '91',
            'status'          => (string)$r['status'],
            'computed_status' => $computedStatus,
            'balance'         => (float)$r['balance'],
            'joined'          => (string)$r['created_at'],
            'app_type'        => 'agent',
            'app_id'          => $aid,
            'admin_id'        => (int)($r['admin_id'] ?? 0),
            'archive_meta'    => $archiveMeta,
            'booking_count'   => (int)($r['booking_count'] ?? 0),
            'trip_count'      => 0,
            'tx_count'        => (int)($r['tx_count'] ?? 0),
            'note'            => (string)($r['note'] ?? '')
        ];
    }
} catch (Throwable $e) {}

$archivedMembers = array_values(array_filter($allUnifiedMembers, static fn($m) => $m['computed_status'] === 'archived'));
$archivedCount   = count($archivedMembers);

$members = [];
if ($tab === 'members') {
    $searchRole   = (string) ($_GET['role'] ?? 'all');
    $searchStatus = (string) ($_GET['status'] ?? 'all');
    $query        = trim((string) ($_GET['q'] ?? ''));

    $members = $allUnifiedMembers;

    // Filter by role
    if ($searchRole !== 'all') {
        $members = array_values(array_filter($members, static fn($m) => $m['category'] === $searchRole));
    }

    // Filter by status
    if ($searchStatus === 'active') {
        $members = array_values(array_filter($members, static fn($m) => $m['computed_status'] === 'active'));
    } elseif ($searchStatus === 'inactive') {
        $members = array_values(array_filter($members, static fn($m) => $m['computed_status'] === 'inactive'));
    } elseif ($searchStatus === 'disabled') {
        $members = array_values(array_filter($members, static fn($m) => $m['computed_status'] === 'disabled'));
    } elseif ($searchStatus === 'archived') {
        $members = array_values(array_filter($members, static fn($m) => $m['computed_status'] === 'archived'));
    } else {
        // 'all' shows active, inactive, and disabled by default; archived live in their dedicated tab/filter
        $members = array_values(array_filter($members, static fn($m) => $m['computed_status'] !== 'archived'));
    }

    // Filter by text search
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
  <a class="team-tab <?= $tab === 'archived' ? 'active' : '' ?>" href="team.php?tab=archived" style="<?= $archivedCount > 0 ? 'color:#475569' : '' ?>">
    🗄️ Archived Accounts · अभिलेख <?= $archivedCount > 0 ? '<span class="badge-count" style="background:#64748b">' . $archivedCount . '</span>' : '' ?>
  </a>
</nav>

<!-- =====================================================================
     TAB 1: MEMBERS REGISTRY
     ===================================================================== -->
<?php if ($tab === 'members'): ?>
  <div class="filter-bar">
    <form method="get" action="team.php" style="display:flex;gap:8px;flex-wrap:wrap;flex:1">
      <input type="hidden" name="tab" value="members">
      <select name="role" onchange="this.form.submit()">
        <option value="all" <?= ($searchRole ?? 'all') === 'all' ? 'selected' : '' ?>>All Roles · सबै पद</option>
        <option value="agent" <?= ($searchRole ?? '') === 'agent' ? 'selected' : '' ?>>Agents · एजेन्ट</option>
        <option value="driver" <?= ($searchRole ?? '') === 'driver' ? 'selected' : '' ?>>Drivers · चालक</option>
        <option value="staff" <?= ($searchRole ?? '') === 'staff' ? 'selected' : '' ?>>Office Staff · कर्मचारी</option>
        <option value="partner" <?= ($searchRole ?? '') === 'partner' ? 'selected' : '' ?>>Transit Partners · साझेदार</option>
      </select>
      <select name="status" onchange="this.form.submit()">
        <option value="all" <?= ($searchStatus ?? 'all') === 'all' ? 'selected' : '' ?>>Status: Active &amp; Inactive · सक्रिय तथा अन्य</option>
        <option value="active" <?= ($searchStatus ?? '') === 'active' ? 'selected' : '' ?>>🟢 ACTIVE Only · सक्रिय मात्र</option>
        <option value="inactive" <?= ($searchStatus ?? '') === 'inactive' ? 'selected' : '' ?>>🟡 INACTIVE / In Review · समीक्षाधीन</option>
        <option value="disabled" <?= ($searchStatus ?? '') === 'disabled' ? 'selected' : '' ?>>🔴 DISABLED / Suspended · निष्क्रिय</option>
        <option value="archived" <?= ($searchStatus ?? '') === 'archived' ? 'selected' : '' ?>>🗄️ ARCHIVED · अभिलेख</option>
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
              <td>
                <?php if ($m['computed_status'] === 'active'): ?>
                  <span class="pill pill-active">🟢 Active</span>
                <?php elseif ($m['computed_status'] === 'disabled'): ?>
                  <span class="pill pill-suspended">🔴 Disabled</span>
                <?php elseif ($m['computed_status'] === 'archived'): ?>
                  <span class="pill" style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1">🗄️ Archived</span>
                <?php else: ?>
                  <span class="pill pill-pending">🟡 Inactive</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="action-grid">
                  <a href="id-card.php?ref=<?= urlencode($m['ref']) ?>" class="btn-sm btn-outline">🪪 Cards</a>
                  <a href="/verify-id.php?ref=<?= urlencode($m['ref']) ?>" target="_blank" class="btn-sm btn-outline">🛡️ Verify</a>
                  <?php if ($m['computed_status'] === 'active'): ?>
                    <button type="button" class="btn-sm btn-primary" onclick="openAdjustmentModal(<?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>')">₹ Adjust</button>
                    <button type="button" class="btn-sm btn-outline" style="color:#d93025;border-color:#fca5a5" onclick="openDisableModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>')">⛔ Disable</button>
                    <button type="button" class="btn-sm btn-outline" style="color:#64748b" onclick="openArchiveModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>')">🗄️ Archive</button>
                  <?php elseif ($m['computed_status'] === 'disabled'): ?>
                    <button type="button" class="btn-sm btn-success" onclick="openRestoreModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>')">🟢 Restore</button>
                    <button type="button" class="btn-sm btn-outline" style="color:#64748b" onclick="openArchiveModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>')">🗄️ Archive</button>
                    <button type="button" class="btn-sm btn-danger" onclick="openDeleteModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>', <?= (int)$m['booking_count'] ?>, <?= (int)$m['trip_count'] ?>, <?= (int)$m['tx_count'] ?>)">🗑️ Delete</button>
                  <?php elseif ($m['computed_status'] === 'archived'): ?>
                    <button type="button" class="btn-sm btn-success" onclick="openRestoreModal('<?= $m['app_type'] ?>', <?= (int)$m['app_id'] ?>, '<?= Security::e($m['name']) ?>', '<?= Security::e($m['ref']) ?>')">🟢 Restore</button>
                  <?php endif; ?>
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
              <button type="submit" class="btn-sm btn-success">✅ Approve &amp; Activate</button>
            </form>

            <button type="button" class="btn-sm btn-outline" style="background:#fff7ed;color:#c2410c;border-color:#fdba74" onclick="openCorrectionModal('<?= Security::e($p['type']) ?>', <?= (int)$p['id'] ?>, '<?= Security::e($p['name']) ?>', '<?= Security::e($p['ref']) ?>', '<?= Security::e($p['phone']) ?>')">⚡ Request Correction</button>
            <button type="button" class="btn-sm btn-outline" onclick="openPreviewModal('<?= Security::e($p['ref']) ?>', '<?= Security::e($p['name']) ?>', '<?= Security::e($p['role']) ?>')">🪪 ID &amp; Agreement Preview</button>
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
              <button type="button" class="btn-sm btn-outline" style="margin-top:6px" onclick="openDocLightbox('<?= Security::e($p['file_url']) ?>', '<?= Security::e(addslashes($p['name'])) ?> — <?= Security::e(ucfirst($p['id_type'])) ?> (<?= Security::e($p['id_no']) ?>)')">🔍 Inspect KYC Lightbox</button>
              <a href="<?= Security::e($p['file_url']) ?>" target="_blank" class="btn-sm btn-outline" style="margin-top:6px">📄 Open in New Tab</a>
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

<?php elseif ($tab === 'archived'): ?>
  <!-- TAB: ARCHIVED ACCOUNTS -->
  <div style="background:#fff;border-radius:12px;border:1px solid #e2e8f0;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05)">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;gap:12px">
      <div>
        <h2 style="margin:0 0 6px 0;font-size:18px;color:#0f172a;display:flex;align-items:center;gap:8px">
          <span>🗄️</span> Archived Accounts &amp; Preserved Records · अभिलेख खाताहरू
        </h2>
        <p style="margin:0;font-size:13px;color:#64748b">
          These staff, driver, and partner accounts are safely archived. All historical bookings, GPS trip records, payroll, KYC records, and ledger histories remain intact for financial audit compliance.
        </p>
      </div>
      <div>
        <span class="badge" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:6px 14px;font-size:13px;font-weight:600">
          <?= count($archivedMembers) ?> Preserved Accounts
        </span>
      </div>
    </div>

    <?php if (empty($archivedMembers)): ?>
      <div style="text-align:center;padding:48px 20px;background:#f8fafc;border-radius:10px;border:1px dashed #cbd5e1">
        <div style="font-size:36px;margin-bottom:10px">🗄️</div>
        <div style="font-size:15px;font-weight:600;color:#334155">No Archived Accounts</div>
        <p style="font-size:13px;color:#64748b;margin:6px auto 0;max-width:440px">
          Disabled or exited team members can be archived from the Team Members tab to keep active operations clean while preserving audit records.
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Member / Code</th>
              <th>Role &amp; Category</th>
              <th>Phone / Contact</th>
              <th>Archived Details</th>
              <th>Preserved History</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($archivedMembers as $am): ?>
              <tr>
                <td>
                  <div style="font-weight:600;color:#0f172a"><?= Security::e($am['name']) ?></div>
                  <span style="font-family:monospace;font-size:11px;color:#64748b"><?= Security::e($am['code']) ?> · <?= Security::e($am['ref']) ?></span>
                </td>
                <td>
                  <span class="pill pill-role-<?= Security::e($am['category']) ?>">
                    <?= Security::e($am['role_lbl']) ?>
                  </span>
                </td>
                <td>
                  <a href="tel:+<?= Security::e($am['cc'] . $am['phone']) ?>" style="font-weight:500;color:#0d3b66">
                    +<?= Security::e($am['cc']) ?> <?= Security::e($am['phone']) ?>
                  </a>
                </td>
                <td>
                  <?php if (!empty($am['archive_meta'])): ?>
                    <div style="font-size:12px;color:#334155;font-weight:500">
                      <?= Security::e((string)($am['archive_meta']['at'] ?? '')) ?>
                    </div>
                    <div style="font-size:11px;color:#64748b;font-style:italic">
                      By <?= Security::e((string)($am['archive_meta']['by'] ?? 'admin')) ?>: <?= Security::e((string)($am['archive_meta']['reason'] ?? '')) ?>
                    </div>
                  <?php else: ?>
                    <span style="font-size:12px;color:#64748b"><?= Security::e($am['note'] ?: 'Archived / Exited') ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <div style="display:flex;gap:4px;flex-wrap:wrap">
                    <?php if ($am['booking_count'] > 0): ?>
                      <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:11px">🎫 <?= $am['booking_count'] ?> Bookings</span>
                    <?php endif; ?>
                    <?php if ($am['trip_count'] > 0): ?>
                      <span class="badge" style="background:#fef3c7;color:#92400e;font-size:11px">🚌 <?= $am['trip_count'] ?> Trips</span>
                    <?php endif; ?>
                    <?php if ($am['tx_count'] > 0): ?>
                      <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px">💰 <?= $am['tx_count'] ?> Ledger Tx</span>
                    <?php endif; ?>
                    <?php if ($am['booking_count'] === 0 && $am['trip_count'] === 0 && $am['tx_count'] === 0): ?>
                      <span class="badge" style="background:#f1f5f9;color:#64748b;font-size:11px">0 Active Records</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="action-grid">
                    <button type="button" class="btn-sm btn-success" onclick="openRestoreModal('<?= $am['app_type'] ?>', <?= (int)$am['app_id'] ?>, '<?= Security::e($am['name']) ?>', '<?= Security::e($am['ref']) ?>')">
                      🟢 Restore
                    </button>
                    <?php if ($am['booking_count'] === 0 && $am['trip_count'] === 0 && $am['tx_count'] === 0): ?>
                      <button type="button" class="btn-sm btn-danger" onclick="openDeleteModal('<?= $am['app_type'] ?>', <?= (int)$am['app_id'] ?>, '<?= Security::e($am['name']) ?>', '<?= Security::e($am['ref']) ?>', 0, 0, 0)">
                        🗑️ Delete
                      </button>
                    <?php else: ?>
                      <span title="Preserved for regulatory audit trail" style="font-size:11px;color:#94a3b8;cursor:help;display:flex;align-items:center;gap:3px">
                        🔒 Audit Locked
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
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

<!-- 5. Request Correction Modal -->
<div class="modal-overlay" id="correctionModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box" style="max-width:560px">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px">
      <h3 style="margin:0;font-size:17px;color:#c2410c">⚡ Request KYC / Field Correction</h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('correctionModal').style.display='none'">&times;</button>
    </div>
    <form method="post" action="team.php?tab=approvals">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="request_correction">
      <input type="hidden" name="app_type" id="corrType">
      <input type="hidden" name="app_id" id="corrId">
      <p style="font-size:13.5px;color:#475569;margin-top:0">
        Applicant: <b id="corrName"></b> (<code id="corrRef"></code>) · Mobile: <b id="corrPhone"></b>
      </p>

      <div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:6px">Select Missing / Blurred Fields:</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12.5px;margin-bottom:12px;background:#f8fafc;padding:10px;border-radius:10px;border:1px solid #e2e8f0">
        <label><input type="checkbox" name="correction_fields[]" value="Clear Passport Photo"> Clear Passport Photo</label>
        <label><input type="checkbox" name="correction_fields[]" value="Clear Aadhaar / Nepal Nagrikta"> Aadhaar / Nagrikta</label>
        <label><input type="checkbox" name="correction_fields[]" value="Valid PAN Card Document"> PAN Card Document</label>
        <label><input type="checkbox" name="correction_fields[]" value="Valid Digital / Finger Signature"> Clear Signature</label>
        <label><input type="checkbox" name="correction_fields[]" value="Business / Counter Registration"> Shop Registration</label>
        <label><input type="checkbox" name="correction_fields[]" value="Valid WhatsApp Contact Number"> Active WhatsApp No</label>
      </div>

      <label class="f">
        <span>Specific Correction Instructions (sent directly on WhatsApp &amp; public tracker)</span>
        <textarea name="correction_notes" rows="3" placeholder="e.g. Please upload a clear photo of the back side of your citizenship certificate with visible date of birth."></textarea>
      </label>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('correctionModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm" style="background:#ea580c;color:#fff;border-color:#ea580c">Submit Correction &amp; WhatsApp</button>
      </div>
    </form>
  </div>
</div>

<!-- 6. One-Tap ID Card & Agreement Preview Modal -->
<div class="modal-overlay" id="teamIdPreviewModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box" style="max-width:760px;width:95%">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px">
      <div>
        <h3 style="margin:0;font-size:18px;color:#0d3b66" id="tPrevName">Official Credentials Preview</h3>
        <div style="font-size:12px;color:#64748b" id="tPrevRole">S Hari Global Pvt Ltd Official ID</div>
      </div>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('teamIdPreviewModal').style.display='none'">&times;</button>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;text-align:center">
      <div style="background:#f8fafc;padding:10px;border-radius:10px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12px;color:#0d3b66;margin-bottom:6px">🪪 ID Card (Front)</div>
        <img id="tImgFront" src="" alt="Front ID" style="max-width:100%;max-height:240px;border-radius:6px;box-shadow:0 3px 10px rgba(0,0,0,0.12)">
      </div>
      <div style="background:#f8fafc;padding:10px;border-radius:10px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12px;color:#0d3b66;margin-bottom:6px">🪪 ID Card (Back)</div>
        <img id="tImgBack" src="" alt="Back ID" style="max-width:100%;max-height:240px;border-radius:6px;box-shadow:0 3px 10px rgba(0,0,0,0.12)">
      </div>
      <div style="background:#f8fafc;padding:10px;border-radius:10px;border:1px solid #e2e8f0">
        <div style="font-weight:700;font-size:12px;color:#0d3b66;margin-bottom:6px">💼 Visiting Card</div>
        <img id="tImgVisiting" src="" alt="Visiting Card" style="max-width:100%;max-height:240px;border-radius:6px;box-shadow:0 3px 10px rgba(0,0,0,0.12)">
      </div>
    </div>

    <div style="margin-top:16px;padding:10px 14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px">
      <span style="font-size:12px;color:#166534">✓ Verified S Hari Global identity credential</span>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <a id="tBtnPdf" href="" target="_blank" class="btn-sm btn-primary">⬇ ID Card PDF</a>
        <a id="tBtnVisPdf" href="" target="_blank" class="btn-sm btn-outline">⬇ Visiting PDF</a>
      </div>
    </div>
  </div>
</div>


<!-- =====================================================================
     ACCOUNT MANAGEMENT MODALS: DISABLE, ARCHIVE, RESTORE, DELETE
     ===================================================================== -->
<!-- 8. Disable Member Modal -->
<div class="modal-overlay" id="disableModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;color:#b91c1c;display:flex;align-items:center;gap:6px">
        <span>⛔</span> Deactivate &amp; Disable Account · खाता निष्क्रिय
      </h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('disableModal').style.display='none'">✕</button>
    </div>
    <form method="post" action="team.php?tab=members">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="disable_member">
      <input type="hidden" name="app_type" id="disType">
      <input type="hidden" name="app_id" id="disId">
      <p style="font-size:13px;color:#334155;margin-top:0">
        Deactivating account for: <strong id="disName" style="color:#0f172a"></strong> (<span id="disRef" style="font-family:monospace"></span>)
      </p>
      <div style="background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#991b1b;line-height:1.5">
        <strong>⚠️ Automatic Security Actions:</strong>
        <ul style="margin:4px 0 0 16px;padding:0">
          <li>Active login sessions will be instantly terminated.</li>
          <li>Driver phone GPS submissions and app login tokens will be blocked.</li>
          <li>Upcoming trip schedule assignments will be unassigned automatically.</li>
          <li>All historical bookings, payments, and audit logs are permanently preserved.</li>
        </ul>
      </div>
      <label class="f">
        <span>Reason for Deactivation <em>*</em></span>
        <textarea name="reason" required rows="3" placeholder="Explain reason for disabling (e.g., Seasonal leave, contract completed, disciplinary action)..."></textarea>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('disableModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-danger" style="background:#dc2626">⛔ Confirm Deactivation</button>
      </div>
    </form>
  </div>
</div>

<!-- 9. Archive Member Modal -->
<div class="modal-overlay" id="archiveModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;color:#334155;display:flex;align-items:center;gap:6px">
        <span>🗄️</span> Archive Member Account · खाता अभिलेखीकरण
      </h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('archiveModal').style.display='none'">✕</button>
    </div>
    <form method="post" action="team.php?tab=members">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="archive_member">
      <input type="hidden" name="app_type" id="arcType">
      <input type="hidden" name="app_id" id="arcId">
      <p style="font-size:13px;color:#334155;margin-top:0">
        Archiving account for: <strong id="arcName" style="color:#0f172a"></strong> (<span id="arcRef" style="font-family:monospace"></span>)
      </p>
      <div style="background:#f8fafc;border:1px solid #e2e8f0;padding:12px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#475569;line-height:1.5">
        <strong>ℹ️ Archive Policy:</strong>
        <p style="margin:4px 0 0 0">
          This account will be hidden from the active roster and placed in the Archived Accounts section. Full historical bookings, commissions, GPS trip breadcrumbs, and KYC documents remain permanently accessible for compliance.
        </p>
      </div>
      <label class="f">
        <span>Archival Reason / Exit Notes <em>*</em></span>
        <textarea name="reason" required rows="3" placeholder="Explain archival reason (e.g., Exited company, retirement, business merger)..."></textarea>
      </label>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('archiveModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-primary" style="background:#475569">🗄️ Confirm Archival</button>
      </div>
    </form>
  </div>
</div>

<!-- 10. Restore Member Modal -->
<div class="modal-overlay" id="restoreModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;color:#166534;display:flex;align-items:center;gap:6px">
        <span>🟢</span> Restore &amp; Reactivate Account · खाता पुनः सक्रिय
      </h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('restoreModal').style.display='none'">✕</button>
    </div>
    <form method="post" action="team.php?tab=members">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="restore_member">
      <input type="hidden" name="app_type" id="resType">
      <input type="hidden" name="app_id" id="resId">
      <p style="font-size:13px;color:#334155;margin-top:0">
        Reactivate account for: <strong id="resName" style="color:#0f172a"></strong> (<span id="resRef" style="font-family:monospace"></span>)
      </p>
      <div style="background:#f0fdf4;border:1px solid #bbf7d0;padding:12px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#166534;line-height:1.5">
        <strong>✓ Restoration Effects:</strong>
        <p style="margin:4px 0 0 0">
          This member's portal access will be re-enabled. If driver, they will be marked active for schedule assignment.
        </p>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('restoreModal').style.display='none'">Cancel</button>
        <button type="submit" class="btn-sm btn-success">🟢 Reactivate Member</button>
      </div>
    </form>
  </div>
</div>

<!-- 11. Permanent Delete Modal (Strict Dependency Checks) -->
<div class="modal-overlay" id="deleteModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:12px;margin-bottom:16px">
      <h3 style="margin:0;font-size:16px;color:#dc2626;display:flex;align-items:center;gap:6px">
        <span>⚠️</span> Permanent Delete Check · स्थायी हटाउने परीक्षण
      </h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('deleteModal').style.display='none'">✕</button>
    </div>
    <form method="post" action="team.php?tab=members" id="delForm">
      <?= Security::csrfField() ?>
      <input type="hidden" name="action" value="delete_member">
      <input type="hidden" name="app_type" id="delType">
      <input type="hidden" name="app_id" id="delId">
      <p style="font-size:13px;color:#334155;margin-top:0">
        Account: <strong id="delName" style="color:#0f172a"></strong> (<span id="delRef" style="font-family:monospace"></span>)
      </p>

      <div id="delBlockedBlock" style="display:none;background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#991b1b;line-height:1.5">
        <strong>🚫 PERMANENT DELETION BLOCKED:</strong>
        <p id="delBlockedMsg" style="margin:4px 0 0 0"></p>
        <p style="margin:8px 0 0 0;font-weight:600">
          Under financial audit regulations, accounts with business records cannot be deleted. Please use <strong>Archive</strong> instead.
        </p>
      </div>

      <div id="delAllowedBlock" style="display:none">
        <div style="background:#fffbeb;border:1px solid #fef3c7;padding:12px;border-radius:8px;margin-bottom:16px;font-size:12px;color:#92400e;line-height:1.5">
          <strong>⚠️ Zero-Dependency Verified:</strong>
          <p style="margin:4px 0 0 0">
            This account has 0 tickets sold, 0 GPS trips driven, and 0 ledger transactions. Deletion will permanently erase this application record and KYC documents.
          </p>
        </div>
        <label class="f">
          <span>Type <strong style="color:#dc2626">DELETE</strong> to confirm permanent destruction <em>*</em></span>
          <input type="text" name="confirm_delete" id="delConfirmInput" required placeholder="DELETE" autocomplete="off" oninput="validateDelBtn()">
        </label>
      </div>

      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:16px">
        <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('deleteModal').style.display='none'">Cancel</button>
        <button type="submit" id="delSubmitBtn" class="btn-sm btn-danger" disabled style="opacity:0.5">Permanently Delete</button>
      </div>
    </form>
  </div>
</div>

<!-- 7. KYC Document Inspection Lightbox -->
<div class="modal-overlay" id="docLightboxModal" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal-box" style="max-width:850px;width:95%">
    <div style="display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin-bottom:14px">
      <h3 style="margin:0;font-size:16px;color:#0d3b66" id="lbTitle">KYC Document Inspection</h3>
      <button type="button" class="btn-sm btn-outline" onclick="document.getElementById('docLightboxModal').style.display='none'">&times;</button>
    </div>
    <div style="text-align:center;background:#0f172a;padding:16px;border-radius:10px;max-height:75vh;overflow:auto">
      <img id="lbImg" src="" alt="KYC Document" style="max-width:100%;max-height:68vh;border-radius:6px;transition:transform 0.2s">
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;flex-wrap:wrap;gap:8px">
      <div style="display:flex;gap:8px">
        <button type="button" class="btn-sm btn-outline" onclick="rotateLb(-90)">↺ Rotate Left</button>
        <button type="button" class="btn-sm btn-outline" onclick="rotateLb(90)">↻ Rotate Right</button>
      </div>
      <a id="lbDownload" href="" target="_blank" class="btn-sm btn-primary">⬇ Open Original File</a>
    </div>
  </div>
</div>

<script>
var curLbRot = 0;
function openDocLightbox(url, title) {
  curLbRot = 0;
  document.getElementById('lbTitle').textContent = title || 'KYC Document Inspection';
  var img = document.getElementById('lbImg');
  img.src = url;
  img.style.transform = 'rotate(0deg)';
  document.getElementById('lbDownload').href = url;
  document.getElementById('docLightboxModal').style.display = 'flex';
}
function rotateLb(deg) {
  curLbRot = (curLbRot + deg) % 360;
  document.getElementById('lbImg').style.transform = 'rotate(' + curLbRot + 'deg)';
}
function openCorrectionModal(type, id, name, ref, phone) {
  document.getElementById('corrType').value = type;
  document.getElementById('corrId').value = id;
  document.getElementById('corrName').textContent = name;
  document.getElementById('corrRef').textContent = ref;
  document.getElementById('corrPhone').textContent = phone;
  document.getElementById('correctionModal').style.display = 'flex';
}
function openPreviewModal(ref, name, role) {
  document.getElementById('tPrevName').textContent = name + ' (' + ref + ')';
  document.getElementById('tPrevRole').textContent = role + ' · S Hari Global Pvt Ltd';
  document.getElementById('tImgFront').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&side=front';
  document.getElementById('tImgBack').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&side=back';
  document.getElementById('tImgVisiting').src = 'id-card.php?ref=' + encodeURIComponent(ref) + '&visiting=1&side=front';
  document.getElementById('tBtnPdf').href = 'id-card.php?ref=' + encodeURIComponent(ref) + '&pdf=1';
  document.getElementById('tBtnVisPdf').href = 'id-card.php?ref=' + encodeURIComponent(ref) + '&visiting=1&pdf=1';
  document.getElementById('teamIdPreviewModal').style.display = 'flex';
}
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

function openDisableModal(type, id, name, ref) {
  document.getElementById('disType').value = type;
  document.getElementById('disId').value = id;
  document.getElementById('disName').textContent = name;
  document.getElementById('disRef').textContent = ref;
  document.getElementById('disableModal').style.display = 'flex';
}
function openArchiveModal(type, id, name, ref) {
  document.getElementById('arcType').value = type;
  document.getElementById('arcId').value = id;
  document.getElementById('arcName').textContent = name;
  document.getElementById('arcRef').textContent = ref;
  document.getElementById('archiveModal').style.display = 'flex';
}
function openRestoreModal(type, id, name, ref) {
  document.getElementById('resType').value = type;
  document.getElementById('resId').value = id;
  document.getElementById('resName').textContent = name;
  document.getElementById('resRef').textContent = ref;
  document.getElementById('restoreModal').style.display = 'flex';
}
function openDeleteModal(type, id, name, ref, bookingCount, tripCount, txCount) {
  document.getElementById('delType').value = type;
  document.getElementById('delId').value = id;
  document.getElementById('delName').textContent = name;
  document.getElementById('delRef').textContent = ref;
  
  var totalDeps = (bookingCount || 0) + (tripCount || 0) + (txCount || 0);
  var blockedBlock = document.getElementById('delBlockedBlock');
  var allowedBlock = document.getElementById('delAllowedBlock');
  var submitBtn = document.getElementById('delSubmitBtn');
  var confirmInput = document.getElementById('delConfirmInput');

  if (totalDeps > 0) {
    blockedBlock.style.display = 'block';
    allowedBlock.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';
    document.getElementById('delBlockedMsg').textContent = 
      'This account is linked to ' + bookingCount + ' booking(s), ' + tripCount + ' GPS trip(s), and ' + txCount + ' wallet transaction(s).';
  } else {
    blockedBlock.style.display = 'none';
    allowedBlock.style.display = 'block';
    confirmInput.value = '';
    submitBtn.disabled = true;
    submitBtn.style.opacity = '0.5';
  }
  document.getElementById('deleteModal').style.display = 'flex';
}
function validateDelBtn() {
  var val = document.getElementById('delConfirmInput').value.trim();
  var btn = document.getElementById('delSubmitBtn');
  if (val === 'DELETE') {
    btn.disabled = false;
    btn.style.opacity = '1';
  } else {
    btn.disabled = true;
    btn.style.opacity = '0.5';
  }
}
</script>

<?php admin_footer(); ?>
