<?php
/**
 * admin/staff.php — Manage agents / staff (Super Admin only).
 *
 * Create ticketing agents and other staff, activate / deactivate accounts,
 * and reset passwords (new staff must change their password on first login).
 * Satisfies the master-prompt "Manage agents" / "Manage users" (staff)
 * Super-Admin requirements. Every action is CSRF-protected and audited.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin   = admin_boot('dashboard.view');
$isSuper = Auth::isSuperadmin();

$ROLES = ['agent', 'counter', 'manager', 'accountant', 'support', 'scanner', 'official', 'superadmin'];
$ROLE_DESC = [
    'agent'      => 'Sells seats & transfers passengers (Seat Map + Trips). No payments/routes/settings.',
    'counter'    => 'Company ticket window: search, book, edit, reschedule, cancel (slab refunds), reprint, verify payments. No revenue dashboard, agent ledgers or fleet/staff management.',
    'manager'    => 'Bookings, routes, schedules, customers, reports.',
    'accountant' => 'Payments, refunds, commissions, reports.',
    'support'    => 'Views bookings & customers, replies to messages, scans tickets.',
    'scanner'    => 'Scans tickets at boarding only.',
    'official'   => 'Verifies payments and replies to messages.',
    'superadmin' => 'Full access to everything.',
];

$flash = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } elseif (!$isSuper) {
        $flash = ['bad', 'Only a super-admin can manage staff.'];
    } else {
        $act = (string) ($_POST['action'] ?? '');

        if ($act === 'create') {
            $username = strtolower(Security::clean($_POST['username'] ?? '', 60));
            $fullName = Security::clean($_POST['full_name'] ?? '', 120);
            $role     = (string) ($_POST['role'] ?? '');
            $pw       = (string) ($_POST['password'] ?? '');
            // Agent phone — prints on every ticket they sell (BOOKED BY line),
            // so the passenger can reach the exact person who issued it.
            $phone    = Security::clean($_POST['phone'] ?? '', 20);
            // Agent CRM (5 Sep 2026): organization (serial 1-20) or person
            // (serial 21+), plus the contact person and WhatsApp number.
            $kind     = AgentWallet::normaliseKind((string) ($_POST['agent_kind'] ?? AgentWallet::KIND_ORG));
            $contact  = trim(Security::clean($_POST['contact_person'] ?? '', 120));
            $whatsapp = AgentWallet::cleanPhone((string) ($_POST['whatsapp'] ?? ''));
            // Email (4 Sep 2026): one third of the agent sign-in
            // (email + username + password), so it is captured at creation.
            $emailRaw = trim((string) ($_POST['email'] ?? ''));
            $email    = Security::email($emailRaw);

            if (!preg_match('/^[a-z0-9_.@\-]{3,60}$/', $username)) {
                $flash = ['bad', 'Username must be 3–60 chars: letters, numbers, . _ - @ only.'];
            } elseif ($fullName === '') {
                $flash = ['bad', 'Enter the staff member\'s full name.'];
            } elseif (!in_array($role, $ROLES, true)) {
                $flash = ['bad', 'Choose a valid role.'];
            } elseif (strlen($pw) < 8) {
                $flash = ['bad', 'The temporary password must be at least 8 characters.'];
            } elseif ($emailRaw !== '' && $email === '') {
                $flash = ['bad', 'Enter a valid email address (or leave it blank).'];
            } elseif ($role === 'agent' && $email === '') {
                $flash = ['bad', 'An agent needs an email — they sign in with email + username + password.'];
            } elseif ($email !== '' && Database::fetch('SELECT id FROM admins WHERE LOWER(email) = :e', ['e' => $email]) !== null) {
                $flash = ['bad', 'That email is already used by another staff account.'];
            } elseif (Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $username]) !== null) {
                $flash = ['bad', 'That username is already taken.'];
            } else {
                $newId = Database::insert('admins', [
                    'username'       => $username,
                    'password_hash'  => password_hash($pw, PASSWORD_DEFAULT),
                    'full_name'      => $fullName,
                    'email'          => $email !== '' ? $email : null,
                    'phone'          => $phone !== '' ? $phone : null,
                    'role'           => $role,
                    'is_active'      => 1,
                    // An agent's password is issued by the office and used as
                    // handed over; forcing a change would bounce them to a page
                    // built for office staff. Everyone else still must change it.
                    'must_change_pw' => $role === 'agent' ? 0 : 1,
                ]);
                Logger::audit('staff.create', 'admin', $username, null, ['role' => $role], 'new staff account');

                $codeNote = '';
                if ($role === 'agent') {
                    // Kind + contact + WhatsApp on the profile first, so the
                    // serial below can follow the right band.
                    try {
                        AgentWallet::saveProfile($newId, [
                            'agent_kind'     => $kind,
                            'contact_person' => $contact,
                            'whatsapp'       => $whatsapp,
                            'display_phone'  => preg_replace('/\D+/', '', $phone) ?: $phone,
                        ]);
                    } catch (Throwable $e) {
                        Logger::error('Agent profile save failed on create', ['e' => $e->getMessage()], 'automation');
                    }
                    // Make the agent login-ready in one step: issue the next
                    // free SHG code (organization 1-20, person 21+) so
                    // code+mobile+OTP works immediately.
                    // Best-effort — a full code map must not block the account.
                    try {
                        $code = AgentWallet::nextFreeAgentCode($kind);
                        if ($code !== null) {
                            AgentWallet::setAgentCode($newId, $code, (int) ($admin['id'] ?? 0));
                            $codeNote = ' ' . AgentWallet::kindLabel($kind) . ' agent code SHG-' . str_pad((string) $code, 4, '0', STR_PAD_LEFT) . ' assigned (serial ' . $code . ').';
                        }
                    } catch (Throwable $e) {
                        Logger::error('Auto agent-code failed', ['e' => $e->getMessage()], 'automation');
                    }
                    // Welcome WhatsApp with their code + agent-portal link.
                    EventBus::emit('agent.registered', [
                        'admin_id'  => $newId,
                        'full_name' => $fullName,
                        'phone'     => $phone,
                    ]);
                }

                $flash = ['ok', 'Staff account "' . $username . '" created.' . $codeNote
                              . ($role === 'agent'
                                  ? ' They sign in at the Agent Portal with ' . $email . ' + ' . $username
                                    . ' + the password you typed. Change any of the three later under Agents → Manage → Login credentials.'
                                  : ' They must change the password on first login.')];
            }
        } elseif ($act === 'toggle') {
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username, role, is_active FROM admins WHERE id = :id', ['id' => $id]);

            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } elseif ((int) $target['id'] === (int) ($admin['id'] ?? 0)) {
                $flash = ['bad', 'You cannot deactivate your own account.'];
            } else {
                $activate = (int) $target['is_active'] === 0;
                // Never deactivate the last active super-admin.
                if (!$activate && $target['role'] === 'superadmin') {
                    $activeSupers = (int) Database::scalar("SELECT COUNT(*) FROM admins WHERE role='superadmin' AND is_active=1", [], 0);
                    if ($activeSupers <= 1) {
                        $flash = ['bad', 'Cannot deactivate the last active super-admin.'];
                    }
                }
                if ($flash === null) {
                    Database::update('admins', ['is_active' => $activate ? 1 : 0], 'id = :id', ['id' => $id]);
                    Logger::audit('staff.toggle', 'admin', (string) $target['username'], null, ['active' => $activate], 'staff ' . ($activate ? 'activated' : 'deactivated'));
                    $flash = ['ok', 'Staff account ' . ($activate ? 'activated' : 'deactivated') . '.'];
                }
            }
        } elseif ($act === 'resetpw') {
            $id     = (int) ($_POST['id'] ?? 0);
            $pw     = (string) ($_POST['password'] ?? '');
            $target = Database::fetch('SELECT id, username, role FROM admins WHERE id = :id', ['id' => $id]);

            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } elseif ((string) ($target['role'] ?? '') === 'superadmin' && (int) $target['id'] !== (int) $admin['id']) {
                // Same wall as setcreds: no peer-superadmin takeover through
                // the quick reset either. Own password: Change Password page.
                $flash = ['bad', 'Another super-admin\'s password cannot be reset here.'];
            } elseif (strlen($pw) < 8) {
                $flash = ['bad', 'The new password must be at least 8 characters.'];
            } else {
                // Mirror the create rule: agents skip the forced change (that
                // gate bounces them to a page built for office staff); every
                // other role must change the temporary password on next login.
                $mustChange = (string) ($target['role'] ?? '') === 'agent' ? 0 : 1;
                Database::update('admins', [
                    'password_hash'  => password_hash($pw, PASSWORD_DEFAULT),
                    'must_change_pw' => $mustChange,
                ], 'id = :id', ['id' => $id]);
                Logger::audit('staff.resetpw', 'admin', (string) $target['username'], null, null, 'password reset by super-admin');
                $flash = ['ok', 'Password reset for "' . $target['username'] . '".'
                    . ($mustChange === 1 ? ' They must change it on next login.' : '')];
            }
        } elseif ($act === 'setcreds') {
            // Edit sign-in details (email / username / password) for ANY staff
            // account — 5 Sep 2026. Agents already had this behind their 🔑
            // Login panel; counters and office roles now get the same via the
            // one credential writer, Auth::setStaffCredentials (validation,
            // uniqueness, lock reset and the audit row all live there).
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username, role FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } elseif ((string) $target['role'] === 'superadmin' && (int) $target['id'] !== (int) $admin['id']) {
                $flash = ['bad', 'Another super-admin\'s sign-in details cannot be edited here.'];
            } else {
                try {
                    $in = [
                        'username' => (string) ($_POST['username'] ?? $target['username']),
                        'email'    => (string) ($_POST['email'] ?? ''),
                    ];
                    if ((string) ($_POST['password'] ?? '') !== '') {
                        $in['password'] = (string) $_POST['password'];
                    }
                    $res = Auth::setStaffCredentials($id, $in, (int) $admin['id']);
                    $flash = $res['changed'] === []
                        ? ['ok', 'No changes — "' . $res['username'] . '" already had those details.']
                        : ['ok', 'Sign-in details updated for "' . $res['username'] . '" (' . implode(', ', $res['changed']) . ').'];
                } catch (RuntimeException $e) {
                    $flash = ['bad', $e->getMessage()];
                }
            }
        } elseif ($act === 'setphone') {
            // Update an agent's contact number — the one printed on the tickets
            // they sell. Kept separate from password reset so the desk can fix a
            // number without touching credentials.
            $id     = (int) ($_POST['id'] ?? 0);
            $phone  = Security::clean($_POST['phone'] ?? '', 20);
            $target = Database::fetch('SELECT id, username FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } else {
                Database::update('admins', ['phone' => $phone !== '' ? $phone : null], 'id = :id', ['id' => $id]);
                Logger::audit('staff.setphone', 'admin', (string) $target['username'], null, null, 'contact number updated');
                $flash = ['ok', 'Contact number updated for "' . $target['username'] . '".'];
            }

        } elseif ($act === 'setcounter') {
            /* The desk this account sells from (24 Sep 2026, owner: "counter
               mode lai location haru ni add garna milos, like NPJ"). It is
               printed on every ticket they issue — under the company name in
               the header band and in the ISSUED BY chip — so a Nepalgunj
               walk-in can see which window sold it.

               One <select> writes both halves: the option value carries
               "CODE|Name", because a code with no name prints bare letters on
               a ticket and a name with no code has nothing short enough for
               the chip. An empty value clears both, which is how a desk that
               moved is un-assigned rather than left pointing at the old town. */
            $id     = (int) ($_POST['id'] ?? 0);
            $pick   = trim((string) ($_POST['counter'] ?? ''));
            $target = Database::fetch('SELECT id, username FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } else {
                [$cCode, $cName] = array_pad(explode('|', $pick, 2), 2, '');
                $cCode = trim((string) $cCode);
                $cName = trim((string) $cName);
                /* A code chosen from the list arrives with its name; a code
                   typed by hand may not, so the list fills the blank. */
                if ($cName === '' && $cCode !== '') {
                    $cName = Settings::counterLocations()[mb_strtoupper($cCode)] ?? '';
                }
                try {
                    AgentWallet::saveProfile($id, ['counter_code' => $cCode, 'counter_name' => $cName]);
                    $label = Settings::counterLabel($cCode, $cName);
                    Logger::audit('staff.setcounter', 'admin', (string) $target['username'], null,
                        ['counter' => $label], 'counter location updated');
                    $flash = ['ok', $label !== ''
                        ? 'Counter location for "' . $target['username'] . '" is now ' . $label . '. It prints on every ticket they issue.'
                        : 'Counter location cleared for "' . $target['username'] . '".'];
                } catch (Throwable $e) {
                    Logger::error('setcounter failed', ['e' => $e->getMessage()], 'automation');
                    $flash = ['bad', 'Could not save the counter location.'];
                }
            }

        } elseif ($act === 'reset_2fa') {
            // Superadmin rescue (Point 10) — turn OFF a staff member's two-factor
            // sign-in if they lost access to their registered mobile and are
            // stuck at the code step. The whole block is already superadmin-only.
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Staff account not found.'];
            } else {
                Auth::setAdmin2fa($id, false, (int) $admin['id']);
                $flash = ['ok', 'Two-factor sign-in turned OFF for "' . $target['username'] . '". They can re-enable it from Change Password after signing in.'];
            }

        } elseif ($act === 'setcode') {
            // Edit an agent's SHG serial (owner ask: codes editable as 001,
            // 002, 003…). Range 1-1000, uniqueness and the SHG-NNN padding are
            // all enforced inside AgentWallet::setAgentCode(); an empty value
            // clears it. Only agents carry a code.
            $id     = (int) ($_POST['id'] ?? 0);
            $raw    = trim((string) ($_POST['code'] ?? ''));
            $target = Database::fetch('SELECT id, username, role FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Account not found.'];
            } elseif ((string) $target['role'] !== 'agent') {
                $flash = ['bad', 'Only agents carry an SHG code.'];
            } else {
                try {
                    AgentWallet::setAgentCode($id, $raw === '' ? null : (int) $raw, (int) ($admin['id'] ?? 0));
                    $flash = ['ok', $raw === ''
                        ? 'Agent code cleared for "' . $target['username'] . '".'
                        : 'Agent code set to SHG-' . str_pad((string) (int) $raw, 4, '0', STR_PAD_LEFT)
                          . ' for "' . $target['username'] . '".'];
                } catch (Throwable $e) {
                    $msg = $e->getMessage();
                    // Name who already holds the number, so the office knows
                    // exactly which agent to free it from (or pick another).
                    if (stripos($msg, 'already used') !== false && $raw !== '') {
                        $holder = AgentWallet::adminForAgentCode((int) $raw);
                        if ($holder !== null) {
                            $hn = (string) Database::scalar(
                                "SELECT COALESCE(NULLIF(full_name,''), username) FROM admins WHERE id = :i",
                                ['i' => $holder], 'agent #' . $holder);
                            $msg = 'SHG-' . str_pad((string) (int) $raw, 4, '0', STR_PAD_LEFT)
                                 . ' is already used by ' . $hn . '. Free it there first, or pick another number.';
                        }
                    }
                    $flash = ['bad', $msg];
                }
            }

        } elseif ($act === 'resequence') {
            // Renumber all agents to a clean 1, 2, 3… (owner ask). Lowest
            // current code lands on SHG-0001.
            try {
                $r = AgentWallet::resequenceAgentCodes((int) ($admin['id'] ?? 0));
                $flash = ['ok', 'Renumbered ' . $r['count'] . ' agents to 1, 2, 3… — codes now run SHG-0001, SHG-0002, …'];
            } catch (Throwable $e) {
                $flash = ['bad', $e->getMessage()];
            }

        } elseif ($act === 'delete_agent') {
            // Full superadmin control (owner ask): delete an agent outright —
            // but ONLY when they have no sales or wallet history, or the delete
            // would orphan money records. Anyone with history must be
            // deactivated instead (kept for the audit trail).
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username, full_name, role FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Account not found.'];
            } elseif ((int) $target['id'] === (int) ($admin['id'] ?? 0)) {
                $flash = ['bad', 'You cannot delete your own account.'];
            } elseif ((string) $target['role'] === 'superadmin') {
                $flash = ['bad', 'A superadmin account cannot be deleted here.'];
            } else {
                $hasBookings = Database::exists('SELECT 1 FROM bookings WHERE sold_by_admin_id = :a LIMIT 1', ['a' => $id]);
                $hasLedger   = false;
                try { $hasLedger = Database::exists('SELECT 1 FROM agent_ledger WHERE agent_admin_id = :a LIMIT 1', ['a' => $id]); } catch (Throwable $e) {}
                if ($hasBookings || $hasLedger) {
                    $flash = ['bad', 'This agent has sales / wallet history — deleting them would break those records. Use Deactivate instead.'];
                } else {
                    try { AgentWallet::setAgentCode($id, null, (int) ($admin['id'] ?? 0)); } catch (Throwable $e) {}
                    try { Database::delete('admin_profiles', 'admin_id = :a', ['a' => $id]); } catch (Throwable $e) {}
                    Database::run("DELETE FROM admins WHERE id = :id AND role <> 'superadmin'", ['id' => $id]);
                    Logger::audit('staff.delete_agent', 'admin', (string) $target['username'], null, null, 'agent deleted (no sales/wallet history)');
                    $flash = ['ok', 'Agent "' . ($target['full_name'] ?: $target['username']) . '" deleted. Their number is now free to reuse.'];
                }
            }

        } elseif ($act === 'approve_agent') {
            // Approve a pending agent application (is_active=0, role='agent')
            // Activates the account, assigns an SHG code, and notifies via WA.
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username, full_name, phone, role, is_active FROM admins WHERE id = :id', ['id' => $id]);

            if ($target === null) {
                $flash = ['bad', 'Application not found.'];
            } elseif ((string) $target['role'] !== 'agent') {
                $flash = ['bad', 'This is not an agent account.'];
            } elseif ((int) $target['is_active'] === 1) {
                $flash = ['bad', 'Agent is already active.'];
            } else {
                Database::update('admins', ['is_active' => 1], 'id = :id', ['id' => $id]);

                $codeNote = '';
                try {
                    // The applicant said whether they are an organization or a
                    // person on the sign-up form; the serial follows that band.
                    $code = AgentWallet::nextFreeAgentCode(AgentWallet::agentKindFor((int) $target['id']));
                    if ($code !== null) {
                        AgentWallet::setAgentCode((int) $target['id'], $code, (int) ($admin['id'] ?? 0));
                        $codeNote = 'SHG-' . str_pad((string) $code, 4, '0', STR_PAD_LEFT);
                    }
                } catch (Throwable $e) {
                    Logger::error('Agent code assign failed on approve', ['e' => $e->getMessage()], 'automation');
                }

                // Notify the new agent via WhatsApp
                try {
                    EventBus::emit('agent.registered', [
                        'admin_id'   => (int) $target['id'],
                        'full_name'  => $target['full_name'],
                        'phone'      => $target['phone'],
                        'agent_code' => $codeNote,
                    ]);
                } catch (Throwable $e) { /* non-fatal */ }

                Logger::audit('staff.approve_agent', 'admin', (string) $target['username'], null, ['code' => $codeNote], 'agent application approved');
                $flash = ['ok', 'Agent "' . $target['full_name'] . '" approved!'
                              . ($codeNote ? ' Code: ' . $codeNote . '.' : '')
                              . ' WhatsApp notification sent.'];
            }

        } elseif ($act === 'reject_agent') {
            // Reject and delete a pending agent application.
            $id     = (int) ($_POST['id'] ?? 0);
            $target = Database::fetch('SELECT id, username, full_name, role, is_active FROM admins WHERE id = :id', ['id' => $id]);
            if ($target === null) {
                $flash = ['bad', 'Application not found.'];
            } elseif ((string) $target['role'] !== 'agent' || (int) $target['is_active'] !== 0) {
                $flash = ['bad', 'Can only reject a pending application (not an active account). Use deactivate for active accounts.'];
            } else {
                Database::run('DELETE FROM admins WHERE id = :id AND is_active = 0 AND role = \'agent\'', ['id' => $id]);
                Logger::audit('staff.reject_agent', 'admin', (string) $target['username'], null, null, 'agent application rejected and deleted');
                $flash = ['ok', 'Application from "' . $target['full_name'] . '" rejected and removed.'];
            }
        }
    }
}

admin_header('Staff & Approvals', 'staff');

if (!$isSuper) {
    echo '<div class="flash bad">Only a super-admin can manage staff and agents.</div>';
    admin_footer();
    exit;
}

if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }

// CSRF token + field name — defined here (before the first form on the page,
// i.e. the pending-applications Approve/Reject buttons) so every form below
// carries a valid token. Previously these were only set further down, so the
// pending panel emitted name="" value="" — which both fails verifyCsrf() and,
// under the production error handler (E_WARNING -> throw), 500s the whole page
// the moment any agent application is pending.
$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;
/* The desks the office has defined, for the counter picker on each row.
   Edited in Admin → Settings → counter_locations, one "CODE|Name" per
   line, so a new window (Birgunj, Butwal) is added without a deploy. */
$counterLocs = Settings::counterLocations();

/* ── Pending Agent Applications ─────────────────────────────────── */
$pending = Database::fetchAll(
    "SELECT a.id, a.username, a.full_name, a.phone, a.created_at,
            COALESCE(ap.counter_name,'—') AS counter_name,
            ap.address
       FROM admins a
       LEFT JOIN admin_profiles ap ON ap.admin_id = a.id
      WHERE a.role = 'agent' AND a.is_active = 0
      ORDER BY a.id DESC LIMIT 50"
);
if (count($pending) > 0): ?>
<div class="panel" style="border-left:4px solid #F07C1F">
  <h2 style="color:#B85A00">⏳ Pending Agent Applications (<?= count($pending) ?>)</h2>
  <p style="color:#6b7688;font-size:13px;margin:0 0 14px">
    These agents applied via the public signup form. Approve to activate their account and send them their SHG code, or reject to delete the application.
  </p>
  <table style="font-size:13.5px">
    <thead><tr>
      <th>Name</th><th>Phone</th><th>City</th><th>Applied</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($pending as $pa): ?>
    <tr>
      <td><b><?= Security::e($pa['full_name']) ?></b></td>
      <td><?= Security::e($pa['phone'] ?? '—') ?></td>
      <td><?= Security::e($pa['counter_name'] ?? '—') ?></td>
      <td style="white-space:nowrap"><?= formatDate($pa['created_at'] ?? null) ?></td>
      <td style="white-space:nowrap;display:flex;gap:6px;flex-wrap:wrap">
        <form method="post" style="display:inline">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="approve_agent">
          <input type="hidden" name="id" value="<?= (int) $pa['id'] ?>">
          <button type="submit" class="btn btn-ok btn-sm"
                  onclick="return confirm('Approve <?= Security::e(addslashes($pa['full_name'])) ?>? An agent code will be assigned and WhatsApp sent.')">
            ✅ Approve
          </button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="reject_agent">
          <input type="hidden" name="id" value="<?= (int) $pa['id'] ?>">
          <button type="submit" class="btn btn-warn btn-sm"
                  onclick="return confirm('Reject and delete this application from <?= Security::e(addslashes($pa['full_name'])) ?>?')">
            ❌ Reject
          </button>
        </form>
        <?php if ($pa['phone']): ?>
        <a href="https://wa.me/91<?= preg_replace('/\D/', '', $pa['phone']) ?>" target="_blank"
           class="btn btn-ghost btn-sm">💬 WhatsApp</a>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php
/* ---------------------------------------------------------------------
 *  Roster: searched and paged.
 *
 *  This used to be one unbounded "SELECT ... FROM admins" that rendered
 *  every account, each with its own reset-password and role form. That is
 *  fine for a dozen counter staff and unusable at the ~1000 agents the
 *  company is building towards: the page weight, and the browser's form
 *  count, grow without limit and the desk has no way to find one person.
 *  Now: a name/username/phone search, a role filter and a page window, so
 *  the query returns a bounded set no matter how large the roster gets.
 * ------------------------------------------------------------------- */
$PER_PAGE = 50;

$q        = trim((string) ($_GET['q'] ?? ''));
$roleF    = (string) ($_GET['role'] ?? '');
$page     = max(1, (int) ($_GET['p'] ?? 1));

$where  = [];
$params = [];

if ($q !== '') {
    // One box searches the three things the desk actually knows about a
    // person. Bound the term so a pathological input cannot build a huge LIKE.
    // Three SEPARATE placeholders bound to the same value: this connection does
    // not emulate prepares, so re-using one name across three LIKEs raises
    // "SQLSTATE[HY093] Invalid parameter number" and the search dies.
    $like           = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr($q, 0, 60)) . '%';
    $where[]        = '(username LIKE :q1 OR full_name LIKE :q2 OR phone LIKE :q3)';
    $params['q1']   = $like;
    $params['q2']   = $like;
    $params['q3']   = $like;
}
if ($roleF !== '' && in_array($roleF, $ROLES, true)) {
    $where[]        = 'role = :role';
    $params['role'] = $roleF;
}

$whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

// One settings read covers every row — the SHG code map lives in the
// 'agent_codes' settings JSON, not on the admins table, so it cannot be an
// SQL ORDER BY. Loaded here (before paging) because the roster is now
// ordered BY the SHG serial (owner ask: "agents in serial 1, 2, 3…").
$codeByAdmin = [];
try {
    foreach ((array) Settings::getArray('agent_codes', []) as $aid => $c) {
        $codeByAdmin[(int) $aid] = (int) $c;
    }
} catch (Throwable $e) {
    // roster still renders without codes
}

/* Every SHG number currently in use → who holds it, so the office can see
   what is reserved (and which numbers are still free) before renumbering.
   Covers ALL agents, not just this page — the codes live in one settings map. */
$reserved = [];   // code => ['code','name','active']
if ($codeByAdmin !== []) {
    $rids = implode(',', array_map('intval', array_keys($codeByAdmin)));
    $rn = [];
    foreach (Database::fetchAll("SELECT id, full_name, username, is_active FROM admins WHERE id IN ($rids)") as $ra) {
        $rn[(int) $ra['id']] = $ra;
    }
    foreach ($codeByAdmin as $aid => $c) {
        $ra = $rn[(int) $aid] ?? null;
        $reserved[(int) $c] = [
            'code'   => (int) $c,
            'name'   => $ra ? (string) ($ra['full_name'] ?: $ra['username']) : ('#' . (int) $aid),
            'active' => (int) ($ra['is_active'] ?? 0),
        ];
    }
    ksort($reserved);
}
$nextFree = AgentWallet::nextFreeAgentCode();

/* Order the whole matching set in PHP by SHG serial, then page over it —
   the code is not a column, so it cannot be ordered in SQL, and paging the
   raw id order would scatter the serial across pages. Superadmins stay
   pinned to the top and active accounts before inactive (unchanged), then
   agents fall in 1, 2, 3… order with un-numbered staff last. The id list is
   light even at the ~1000 agents the company is building towards. */
$idRows = Database::fetchAll('SELECT id, role, is_active FROM admins' . $whereSql, $params);
usort($idRows, static function (array $a, array $b) use ($codeByAdmin): int {
    $sa = $a['role'] === 'superadmin' ? 0 : 1;
    $sb = $b['role'] === 'superadmin' ? 0 : 1;
    if ($sa !== $sb) { return $sa <=> $sb; }
    $aa = (int) $a['is_active'];
    $ab = (int) $b['is_active'];
    if ($aa !== $ab) { return $ab <=> $aa; }               // active first
    $ca = $codeByAdmin[(int) $a['id']] ?? PHP_INT_MAX;
    $cb = $codeByAdmin[(int) $b['id']] ?? PHP_INT_MAX;
    if ($ca !== $cb) { return $ca <=> $cb; }                // SHG serial
    return (int) $a['id'] <=> (int) $b['id'];
});

$total  = count($idRows);
$pages  = max(1, (int) ceil($total / $PER_PAGE));
$page   = min($page, $pages);
$offset = ($page - 1) * $PER_PAGE;

$pageIds = array_map(static fn(array $r): int => (int) $r['id'], array_slice($idRows, $offset, $PER_PAGE));
$rows = [];
if ($pageIds !== []) {
    $in   = implode(',', $pageIds);   // ids are ints from the DB — safe to inline
    $byId = [];
    foreach (Database::fetchAll(
        'SELECT a.id, a.username, a.email, a.full_name, a.phone, a.role, a.is_active, a.must_change_pw,
                a.last_login_at, a.created_at,
                ap.counter_name, ap.counter_code
           FROM admins a
           LEFT JOIN admin_profiles ap ON ap.admin_id = a.id
          WHERE a.id IN (' . $in . ')'
    ) as $r) {
        $byId[(int) $r['id']] = $r;
    }
    // Re-apply the sorted order (SQL IN does not preserve it).
    foreach ($pageIds as $pid) {
        if (isset($byId[$pid])) { $rows[] = $byId[$pid]; }
    }
}
// $csrf / $k are defined near the top of the render (just after the flash),
// so both the pending-applications forms above and every form below share one
// valid token.
?>
<div class="panel" id="addStaff">
  <h2>➕ Add staff / agent</h2>
  <?php
    $nextOrgCode    = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_ORG);
    $nextPersonCode = AgentWallet::nextFreeAgentCode(AgentWallet::KIND_PERSON);
  ?>
  <form method="post" style="padding:16px 18px;display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
    <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="create">
    <label style="display:block">Username
      <input type="text" name="username" required maxlength="60" placeholder="agent2" autocomplete="off"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block">Full name <span class="muted" style="font-weight:400" data-agent-only>· or organization name</span>
      <input type="text" name="full_name" required maxlength="120" placeholder="Ram Bahadur / Krishna Travels"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block">Contact number <span class="muted" style="font-weight:400">· prints on their tickets</span>
      <input type="text" name="phone" maxlength="20" placeholder="+91 98765 43210" autocomplete="off"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block">Email <span class="muted" style="font-weight:400" data-agent-only>· required — agents sign in with email + username + password</span>
      <input type="email" name="email" maxlength="191" placeholder="agent@example.com" autocomplete="off"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block">Role
      <select name="role" id="roleSel" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
        <?php foreach ($ROLES as $r): ?>
          <option value="<?= $r ?>" <?= $r === 'agent' ? 'selected' : '' ?>><?= ucfirst($r) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label style="display:block" data-agent-only>Agent type <span class="muted" style="font-weight:400">· sets the serial band</span>
      <select name="agent_kind" id="agentKindSel" style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
        <option value="org">Organization — serial 1–20 (next <?= $nextOrgCode === null ? 'none free' : (int) $nextOrgCode ?>)</option>
        <option value="person">Person — serial 21+ (next <?= $nextPersonCode === null ? 'none free' : (int) $nextPersonCode ?>)</option>
      </select>
    </label>
    <label style="display:block" data-agent-only data-org-only>Contact person <span class="muted" style="font-weight:400">· inside the organization</span>
      <input type="text" name="contact_person" maxlength="120" placeholder="who answers the phone"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block" data-agent-only>WhatsApp <span class="muted" style="font-weight:400">· blank = same as contact number</span>
      <input type="text" name="whatsapp" maxlength="15" inputmode="numeric" placeholder="98765 43210" autocomplete="off"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="display:block">Temporary password
      <input type="text" name="password" required minlength="8" placeholder="min 8 chars" autocomplete="new-password"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <button class="btn" type="submit">Create account</button>
  </form>
  <p class="muted" id="roleHint" style="margin:0 18px 16px;font-size:12.5px"></p>
  <script>
  (function(){
    var role=document.getElementById('roleSel'), kind=document.getElementById('agentKindSel');
    if(!role||!kind) return;
    function sync(){
      var isAgent=role.value==='agent', isOrg=kind.value==='org';
      document.querySelectorAll('#addStaff [data-agent-only]').forEach(function(el){
        var show=isAgent && (!el.hasAttribute('data-org-only') || isOrg);
        el.style.display=show?'':'none';
      });
    }
    role.addEventListener('change',sync); kind.addEventListener('change',sync); sync();
  })();
  </script>
</div>

<?php if ($reserved !== []): ?>
<details class="panel resv-panel">
  <summary>
    🔢 Agent numbers in use (<?= count($reserved) ?>)<?php if ($nextFree !== null): ?> · next free: <b>SHG-<?= str_pad((string) $nextFree, 4, '0', STR_PAD_LEFT) ?></b><?php endif; ?>
    <span class="muted" style="font-weight:400;font-size:12px">— tap to see which numbers are taken</span>
  </summary>
  <div class="resv-wrap">
    <?php foreach ($reserved as $rv): ?>
      <span class="resv<?= $rv['active'] ? '' : ' resv-off' ?>" title="<?= Security::e($rv['name']) ?><?= $rv['active'] ? '' : ' (inactive)' ?>">
        <b>SHG-<?= str_pad((string) $rv['code'], 4, '0', STR_PAD_LEFT) ?></b>&nbsp;<?= Security::e($rv['name']) ?>
      </span>
    <?php endforeach; ?>
  </div>
  <div style="padding:0 18px 16px">
    <form method="post" style="display:inline"
          onsubmit="return confirm('Renumber ALL agents to 1, 2, 3…?\n\nThe agent with the lowest current number becomes SHG-0001, the next SHG-0002, and so on. This changes the code printed on FUTURE tickets — already-issued tickets keep their old number. Continue?')">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="resequence">
      <button class="btn" type="submit">↕️ Resequence all to 1, 2, 3…</button>
    </form>
    <span class="muted" style="font-size:12px;margin-left:8px">Cleans gaps and stale numbers; lowest current code becomes SHG-0001.</span>
  </div>
</details>
<?php endif; ?>

<div class="panel">
  <h2>Staff accounts (<?= (int) $total ?><?= $q !== '' || $roleF !== '' ? ' matching' : '' ?>)</h2>

  <form method="get" style="padding:0 18px 14px;display:flex;gap:10px;flex-wrap:wrap;align-items:end">
    <label style="flex:1;min-width:200px;font-size:12px;color:var(--mut)">Search <span class="muted">name, username or phone</span>
      <input type="search" name="q" value="<?= Security::e($q) ?>" placeholder="e.g. Ram, agent2, 98765"
             style="width:100%;padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px">
    </label>
    <label style="font-size:12px;color:var(--mut)">Role
      <select name="role" style="padding:9px 11px;border:1px solid var(--line);border-radius:8px;margin-top:5px;display:block">
        <option value="">All roles</option>
        <?php foreach ($ROLES as $r): ?>
          <option value="<?= Security::e($r) ?>" <?= $roleF === $r ? 'selected' : '' ?>><?= Security::e(ucfirst($r)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button class="btn" type="submit">Search</button>
    <?php if ($q !== '' || $roleF !== ''): ?>
      <a class="btn" href="staff.php" style="text-decoration:none">Clear</a>
    <?php endif; ?>
  </form>

  <!-- View toggle -->
  <div style="display:flex;gap:8px;padding:0 18px 14px;align-items:center">
    <button class="btn ghost view-toggle" data-view="cards" onclick="setView('cards')" title="Card view">🃏 Cards</button>
    <button class="btn ghost view-toggle active" data-view="table" onclick="setView('table')" title="Table view">📋 Table</button>
    <span class="muted" style="font-size:12px;margin-left:auto"><?= count($rows) ?> shown of <?= (int) $total ?></span>
  </div>

  <!-- ============================================================
       CARD VIEW — beautiful agent directory with WhatsApp links
       ============================================================ -->
  <div id="viewCards" class="agent-cards" style="display:none">
    <?php foreach ($rows as $s):
      $self = (int) $s['id'] === (int) ($admin['id'] ?? 0);
      $shg  = $codeByAdmin[(int) $s['id']] ?? null;
      $phone = trim((string) ($s['phone'] ?? ''));
      // WhatsApp link: strip spaces/dashes, ensure + prefix
      $waNum = preg_replace('/[\s\-\(\)]/', '', $phone);
      if ($waNum !== '' && $waNum[0] !== '+') { $waNum = '+' . $waNum; }
      $waLink = $waNum !== '' ? 'https://wa.me/' . ltrim($waNum, '+') : '';
      $initials = '';
      $nameParts = explode(' ', trim((string) $s['full_name']));
      foreach (array_slice($nameParts, 0, 2) as $np) { if ($np !== '') $initials .= mb_strtoupper(mb_substr($np, 0, 1)); }
      $isActive = (int) $s['is_active'] === 1;
      $isAgent  = (string) $s['role'] === 'agent';
    ?>
      <div class="acard<?= !$isActive ? ' acard-inactive' : '' ?>">
        <div class="acard-top">
          <div class="acard-avatar" style="background:<?= $isAgent ? 'linear-gradient(135deg,#2E5FA8,#1a3d6e)' : 'linear-gradient(135deg,#6b4e9e,#3d2c5c)' ?>">
            <?= Security::e($initials ?: '?') ?>
          </div>
          <div class="acard-info">
            <div class="acard-name"><?= Security::e((string) $s['full_name']) ?><?= $self ? ' <span class="muted" style="font-size:11px">(you)</span>' : '' ?></div>
            <div class="acard-meta">
              <?php if ($isAgent): ?>
                <form method="post" class="code-edit" title="Edit agent code — type a number and press 💾">
                  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
                  <input type="hidden" name="action" value="setcode">
                  <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <span class="code-pre">SHG-</span>
                  <input type="number" name="code" min="1" max="1000" value="<?= $shg !== null ? (int) $shg : '' ?>" placeholder="—" aria-label="Agent code">
                  <button type="submit" title="Save code">💾</button>
                </form>
              <?php elseif ($shg !== null): ?>
                <span class="acard-code">SHG-<?= str_pad((string) $shg, 4, '0', STR_PAD_LEFT) ?></span>
              <?php endif; ?>
              <span class="pill" style="background:<?= $isAgent ? '#e3f2fd' : '#f3e5f5' ?>;color:<?= $isAgent ? '#1565c0' : '#7b1fa2' ?>;font-size:11px"><?= Security::e(ucfirst((string) $s['role'])) ?></span>
              <?php if ($isActive): ?>
                <span class="pill" style="background:#d7f4e3;color:#0a6b3b;font-size:11px">Active</span>
              <?php else: ?>
                <span class="pill" style="background:#e7e7ea;color:#666;font-size:11px">Inactive</span>
              <?php endif; ?>
            </div>
            <div class="acard-user mono" style="font-size:11px;color:var(--mut);margin-top:2px">@<?= Security::e((string) $s['username']) ?></div>
          </div>
        </div>

        <?php if ($phone !== ''): ?>
        <div class="acard-contact">
          <span class="acard-phone mono">📱 <?= Security::e($phone) ?></span>
          <?php if ($waLink !== ''): ?>
            <a href="<?= Security::e($waLink) ?>" target="_blank" rel="noopener" class="acard-wa" title="WhatsApp <?= Security::e((string) $s['full_name']) ?>">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="#25D366"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.28-.1-.48-.15-.68.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.14-.14.3-.35.45-.53.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.68-1.63-.93-2.23-.24-.58-.49-.5-.68-.51h-.58c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.22 5.1 4.51.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.58-.08 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.28-.2-.58-.35zm-5.44 7.44h-.02a9.87 9.87 0 01-5.03-1.38l-.36-.22-3.74.98 1-3.65-.24-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89a9.83 9.83 0 017 2.9 9.83 9.83 0 012.9 7c0 5.45-4.44 9.89-9.9 9.89zm8.41-18.3A11.82 11.82 0 0012.04 0C5.46 0 .1 5.35.1 11.93a11.88 11.88 0 001.6 5.95L0 24l6.3-1.65a11.9 11.9 0 005.73 1.47h.01c6.58 0 11.94-5.35 11.94-11.93a11.86 11.86 0 00-3.54-8.47z"/></svg>
              WhatsApp
            </a>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="acard-contact"><span class="muted" style="font-size:12px">No phone number</span></div>
        <?php endif; ?>

        <div class="acard-footer">
          <span class="muted" style="font-size:11px">
            <?= $s['last_login_at'] ? 'Last login ' . Security::e(timeAgo((string) $s['last_login_at'])) : 'Never logged in' ?>
          </span>
          <div class="acard-actions">
            <?php if ($isAgent): ?>
              <a class="btn ghost" style="padding:4px 10px;font-size:12px" href="agent.php?agent=<?= (int) $s['id'] ?>" title="Full agent controls — commission, deposit, salary, routes, ID, photo">⚙️ Manage</a>
              <a class="btn ghost" style="padding:4px 10px;font-size:12px" href="agent-sales.php?agent=<?= (int) $s['id'] ?>">📊 Sales</a>
            <?php endif; ?>
            <?php if (!$self): ?>
            <form method="post" style="margin:0;display:inline">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn ghost" type="submit" style="padding:4px 10px;font-size:12px"><?= $isActive ? '🚫 Deactivate' : '✅ Activate' ?></button>
            </form>
            <?php if ((string) $s['role'] !== 'superadmin'): ?>
            <form method="post" style="margin:0;display:inline"
                  onsubmit="return confirm('Delete <?= Security::e(addslashes((string) ($s['full_name'] ?: $s['username']))) ?> permanently?\n\nThis only works if they have NO sales or wallet history — otherwise use Deactivate.')">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_agent">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <button class="btn ghost" type="submit" style="padding:4px 10px;font-size:12px;color:#b02a2a">🗑️ Delete</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ============================================================
       TABLE VIEW — compact list with WhatsApp links
       ============================================================ -->
  <div id="viewTable">
  <table>
    <thead><tr>
      <th>Name</th><th>Agent #</th>
      <th>Counter · location</th>
      <th>Contact · WhatsApp</th>
      <th>Role</th><th>Status</th><th>Last login</th><th>Actions</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $s):
      $self = (int) $s['id'] === (int) ($admin['id'] ?? 0);
      $shg  = $codeByAdmin[(int) $s['id']] ?? null;
      $phone = trim((string) ($s['phone'] ?? ''));
      $waNum = preg_replace('/[\s\-\(\)]/', '', $phone);
      if ($waNum !== '' && $waNum[0] !== '+') { $waNum = '+' . $waNum; }
      $waLink = $waNum !== '' ? 'https://wa.me/' . ltrim($waNum, '+') : '';
    ?>
      <tr<?= (int) $s['is_active'] === 0 ? ' style="opacity:.55"' : '' ?>>
        <td>
          <strong><?= Security::e((string) $s['full_name']) ?></strong><?= $self ? ' <span class="muted">(you)</span>' : '' ?>
          <div class="mono muted" style="font-size:11px">@<?= Security::e((string) $s['username']) ?></div>
          <?php if (trim((string) ($s['email'] ?? '')) !== ''): ?>
            <div class="mono muted" style="font-size:11px">✉️ <?= Security::e((string) $s['email']) ?></div>
          <?php endif; ?>
        </td>
        <td class="mono">
          <?php if ((string) $s['role'] === 'agent'): ?>
            <form method="post" class="code-edit" title="Edit agent code — type a number and press 💾">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="setcode">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <span class="code-pre">SHG-</span>
              <input type="number" name="code" min="1" max="1000" value="<?= $shg !== null ? (int) $shg : '' ?>" placeholder="—" aria-label="Agent code">
              <button type="submit" title="Save code">💾</button>
            </form>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <!-- The desk this account sells from. Printed on every ticket they
             issue, so it is edited here beside the agent code rather than
             buried two screens deep. Only the roles that actually sell get
             the control — a scanner or an accountant has no counter. -->
        <td>
          <?php
            $sCode = mb_strtoupper(trim((string) ($s['counter_code'] ?? '')));
            $sName = trim((string) ($s['counter_name'] ?? ''));
            $sells = in_array((string) $s['role'], ['counter', 'agent', 'manager', 'superadmin'], true);
          ?>
          <?php if ($sells): ?>
            <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="setcounter">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <select name="counter" aria-label="Counter location"
                      style="padding:4px 6px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:170px">
                <option value="">— none —</option>
                <?php
                  $seen = false;
                  foreach ($counterLocs as $cc => $cn):
                    $selected = $cc === $sCode;
                    $seen = $seen || $selected;
                ?>
                  <option value="<?= Security::e($cc . '|' . $cn) ?>"<?= $selected ? ' selected' : '' ?>>
                    <?= Security::e($cc . ' · ' . $cn) ?>
                  </option>
                <?php endforeach; ?>
                <?php /* A desk set before the list carried it must not be
                         silently re-pointed by opening this page. */ ?>
                <?php if (!$seen && ($sCode !== '' || $sName !== '')): ?>
                  <option value="<?= Security::e($sCode . '|' . $sName) ?>" selected>
                    <?= Security::e(trim($sCode . ' · ' . $sName, ' ·')) ?> (not in list)
                  </option>
                <?php endif; ?>
              </select>
              <button class="btn ghost" type="submit" style="padding:4px 8px" title="Save counter location">💾</button>
            </form>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
            <form method="post" style="margin:0;display:flex;gap:4px;align-items:center">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="setphone">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="text" name="phone" value="<?= Security::e($phone) ?>" placeholder="add number"
                     style="padding:4px 7px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:130px">
              <button class="btn ghost" type="submit" style="padding:4px 8px" title="Save">💾</button>
            </form>
            <?php if ($waLink !== ''): ?>
              <a href="<?= Security::e($waLink) ?>" target="_blank" rel="noopener" class="wa-btn" title="WhatsApp">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#25D366"><path d="M17.47 14.38c-.3-.15-1.76-.87-2.03-.97-.28-.1-.48-.15-.68.15-.2.3-.77.97-.95 1.17-.17.2-.35.22-.65.07-.3-.15-1.26-.46-2.4-1.48-.89-.79-1.49-1.77-1.66-2.07-.17-.3-.02-.46.13-.61.14-.14.3-.35.45-.53.15-.17.2-.3.3-.5.1-.2.05-.37-.03-.52-.07-.15-.68-1.63-.93-2.23-.24-.58-.49-.5-.68-.51h-.58c-.2 0-.52.07-.8.37-.27.3-1.04 1.02-1.04 2.48s1.07 2.88 1.22 3.08c.15.2 2.1 3.22 5.1 4.51.71.31 1.27.49 1.7.63.72.23 1.37.2 1.88.12.58-.08 1.76-.72 2.01-1.41.25-.7.25-1.29.17-1.41-.07-.13-.28-.2-.58-.35zm-5.44 7.44h-.02a9.87 9.87 0 01-5.03-1.38l-.36-.22-3.74.98 1-3.65-.24-.37a9.86 9.86 0 01-1.51-5.26c0-5.45 4.44-9.89 9.9-9.89a9.83 9.83 0 017 2.9 9.83 9.83 0 012.9 7c0 5.45-4.44 9.89-9.9 9.89zm8.41-18.3A11.82 11.82 0 0012.04 0C5.46 0 .1 5.35.1 11.93a11.88 11.88 0 001.6 5.95L0 24l6.3-1.65a11.9 11.9 0 005.73 1.47h.01c6.58 0 11.94-5.35 11.94-11.93a11.86 11.86 0 00-3.54-8.47z"/></svg>
              </a>
            <?php endif; ?>
          </div>
        </td>
        <td><span class="pill" style="background:#eef2fa;color:#33507f"><?= Security::e(ucfirst((string) $s['role'])) ?></span></td>
        <td>
          <?php if ((int) $s['is_active'] === 1): ?><span class="pill" style="background:#d7f4e3;color:#0a6b3b">Active</span>
          <?php else: ?><span class="pill" style="background:#e7e7ea;color:#333">Inactive</span><?php endif; ?>
        </td>
        <td class="muted" style="font-size:12px"><?= $s['last_login_at'] ? Security::e(timeAgo((string) $s['last_login_at'])) : 'never' ?></td>
        <td class="row-actions">
          <?php if (!$self): ?>
          <form method="post" style="margin:0">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn ghost" type="submit" style="padding:5px 10px"><?= (int) $s['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
          </form>
          <?php endif; ?>
          <?php if ($self || (string) $s['role'] !== 'superadmin'): ?>
          <form method="post" style="margin:0;display:flex;gap:5px;align-items:center" onsubmit="return confirm('Reset the password for <?= Security::e((string) $s['username']) ?>?')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="resetpw">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <input type="text" name="password" placeholder="new pw" minlength="8" required autocomplete="new-password"
                   style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:110px">
            <button class="btn ghost" type="submit" style="padding:5px 10px">Reset</button>
          </form>
          <?php endif; ?>
          <?php if (Auth::admin2faEnabled((int) $s['id'])): ?>
          <form method="post" style="margin:0" onsubmit="return confirm('Turn OFF two-factor sign-in for <?= Security::e((string) $s['username']) ?>? Use this only if they lost access to their registered mobile.')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="reset_2fa">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn ghost" type="submit" style="padding:5px 10px" title="Disable this account's two-factor sign-in">🔓 Reset 2FA</button>
          </form>
          <?php endif; ?>
          <?php if ((string) $s['role'] === 'agent'): ?>
          <a class="btn ghost" style="padding:5px 10px" href="agent.php?agent=<?= (int) $s['id'] ?>" title="Full agent controls">⚙️ Manage</a>
          <a class="btn ghost" style="padding:5px 10px" href="agent.php?agent=<?= (int) $s['id'] ?>#loginCredentials" title="View or reset email, username and password">🔑 Login</a>
          <?php elseif ((string) $s['role'] !== 'superadmin'): ?>
          <?php /* 5 Sep 2026: counters and office roles get the same sign-in
                   editor agents have — one writer (Auth::setStaffCredentials)
                   behind a new 'setcreds' action, inline so nothing about the
                   agent wallet page leaks onto a counter account. */ ?>
          <details style="display:inline-block">
            <summary class="btn ghost" style="padding:5px 10px;cursor:pointer;list-style:none">🔑 Sign-in</summary>
            <form method="post" style="margin:6px 0 0;display:flex;gap:5px;align-items:center;flex-wrap:wrap">
              <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="setcreds">
              <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
              <input type="text" name="username" value="<?= Security::e((string) $s['username']) ?>" required minlength="3" maxlength="60"
                     title="Username" style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:120px">
              <input type="email" name="email" value="<?= Security::e((string) ($s['email'] ?? '')) ?>" placeholder="email (optional)" maxlength="191"
                     style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:170px">
              <input type="text" name="password" placeholder="new pw (blank = keep)" minlength="8" autocomplete="new-password"
                     style="padding:5px 8px;border:1px solid var(--line);border-radius:7px;font-size:12px;max-width:140px">
              <button class="btn ghost" type="submit" style="padding:5px 10px">💾 Save</button>
            </form>
          </details>
          <?php endif; ?>
          <?php if (!$self && (string) $s['role'] !== 'superadmin'): ?>
          <form method="post" style="margin:0"
                onsubmit="return confirm('Delete <?= Security::e(addslashes((string) ($s['full_name'] ?: $s['username']))) ?> permanently?\n\nOnly works with NO sales/wallet history — otherwise use Deactivate.')">
            <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="delete_agent">
            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
            <button class="btn ghost" type="submit" style="padding:5px 10px;color:#b02a2a">🗑️ Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1):
    $qs = static function (int $p) use ($q, $roleF): string {
        return 'staff.php?' . http_build_query(array_filter([
            'q' => $q, 'role' => $roleF, 'p' => $p,
        ], static fn($v) => $v !== '' && $v !== null));
    };
  ?>
    <div style="display:flex;gap:8px;align-items:center;justify-content:center;padding:14px 18px;flex-wrap:wrap">
      <?php if ($page > 1): ?>
        <a class="btn" href="<?= Security::e($qs($page - 1)) ?>" style="text-decoration:none">← Previous</a>
      <?php endif; ?>
      <span class="muted" style="font-size:12.5px">
        Page <?= (int) $page ?> of <?= (int) $pages ?> · showing <?= count($rows) ?> of <?= (int) $total ?>
      </span>
      <?php if ($page < $pages): ?>
        <a class="btn" href="<?= Security::e($qs($page + 1)) ?>" style="text-decoration:none">Next →</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
/* Agent card grid */
.agent-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:16px;padding:0 18px 18px}
.acard{border:1px solid var(--line,#dcdcdc);border-radius:14px;padding:16px;background:var(--card,#fff);transition:box-shadow .2s,transform .15s}
.acard:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);transform:translateY(-1px)}
.acard-inactive{opacity:.55}
.acard-top{display:flex;gap:14px;align-items:flex-start;margin-bottom:12px}
.acard-avatar{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:18px;flex-shrink:0;letter-spacing:1px}
.acard-info{flex:1;min-width:0}
.acard-name{font-size:15px;font-weight:700;line-height:1.2;margin-bottom:4px}
.acard-meta{display:flex;gap:6px;flex-wrap:wrap;align-items:center}
.acard-code{background:linear-gradient(135deg,#1a3d6e,#2E5FA8);color:#fff;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:700;font-family:monospace;letter-spacing:.5px}
/* Inline editable agent code — a pill that holds a small number input so the
   office can renumber agents (001, 002, 003…) straight from the roster. */
.code-edit{display:inline-flex;align-items:center;gap:2px;background:linear-gradient(135deg,#1a3d6e,#2E5FA8);border-radius:20px;padding:2px 4px 2px 8px;margin:0}
.code-edit .code-pre{color:#fff;font-size:11px;font-weight:700;font-family:monospace;letter-spacing:.5px}
.code-edit input[type=number]{width:48px;border:0;border-radius:12px;padding:3px 4px;font-size:12px;font-weight:700;text-align:center;background:rgba(255,255,255,.92);color:#12264E;-moz-appearance:textfield}
.code-edit input[type=number]::-webkit-outer-spin-button,.code-edit input[type=number]::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.code-edit button{background:transparent;border:0;cursor:pointer;font-size:13px;padding:0 3px;line-height:1;min-height:0}
.code-edit button:hover{transform:scale(1.15)}
/* Reserved-numbers reference — every SHG code in use, so the office sees
   what is taken (and the gaps that are free) before renumbering. */
.resv-panel>summary{padding:14px 18px;cursor:pointer;font-weight:700;font-size:14px;list-style:none}
.resv-panel>summary::-webkit-details-marker{display:none}
.resv-panel>summary::before{content:'▸ ';color:var(--mut)}
.resv-panel[open]>summary::before{content:'▾ '}
.resv-wrap{padding:4px 18px 16px;display:flex;flex-wrap:wrap;gap:8px}
.resv{display:inline-flex;align-items:center;gap:2px;background:var(--head);border:1px solid var(--line);border-radius:20px;padding:4px 12px;font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.resv b{color:var(--blue);font-family:monospace}
.resv-off{opacity:.5;text-decoration:line-through}
.acard-contact{display:flex;align-items:center;gap:10px;padding:10px 12px;background:var(--soft,#f4f6f8);border-radius:10px;margin-bottom:12px}
.acard-phone{font-size:13px;flex:1}
.acard-wa{display:inline-flex;align-items:center;gap:5px;background:#25D366;color:#fff;padding:5px 12px;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;transition:background .2s}
.acard-wa:hover{background:#1da851}
.acard-wa svg{flex-shrink:0}
.acard-footer{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px}
.acard-actions{display:flex;gap:6px;flex-wrap:wrap}

/* WhatsApp button in table view */
.wa-btn{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#25D366;transition:background .2s}
.wa-btn:hover{background:#1da851}
.wa-btn svg{display:block}

/* View toggle */
.view-toggle{padding:6px 14px !important;font-size:13px !important}
.view-toggle.active{background:var(--primary,#2E5FA8) !important;color:#fff !important}

/* Dark theme */
:root[data-theme="dark"] .acard{border-color:#333;background:#1a1a2e}
:root[data-theme="dark"] .acard:hover{box-shadow:0 4px 20px rgba(0,0,0,.3)}
:root[data-theme="dark"] .acard-contact{background:#12122a}
@media(prefers-color-scheme:dark){:root:not([data-theme="light"]) .acard{border-color:#333;background:#1a1a2e}:root:not([data-theme="light"]) .acard:hover{box-shadow:0 4px 20px rgba(0,0,0,.3)}:root:not([data-theme="light"]) .acard-contact{background:#12122a}}

@media(max-width:600px){
  .agent-cards{grid-template-columns:1fr;padding:0 10px 10px}
  .acard{padding:12px}
}
</style>

<script>
(function(){
  var desc = <?= json_encode($ROLE_DESC, JSON_UNESCAPED_UNICODE) ?>;
  var sel = document.getElementById('roleSel'), hint = document.getElementById('roleHint');
  function upd(){ if (sel && hint) hint.textContent = desc[sel.value] || ''; }
  if (sel) { sel.addEventListener('change', upd); upd(); }

  // View toggle: cards vs table
  window.setView = function(v) {
    document.getElementById('viewCards').style.display = v === 'cards' ? 'grid' : 'none';
    document.getElementById('viewTable').style.display = v === 'table' ? 'block' : 'none';
    document.querySelectorAll('.view-toggle').forEach(function(b){
      b.classList.toggle('active', b.dataset.view === v);
    });
    try { localStorage.setItem('shg_staff_view', v); } catch(e){}
  };
  // Restore preference
  try { var saved = localStorage.getItem('shg_staff_view'); if (saved === 'cards') window.setView('cards'); } catch(e){}
})();
</script>
<?php
admin_footer();
