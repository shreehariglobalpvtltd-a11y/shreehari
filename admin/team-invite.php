<?php
/**
 * admin/team-invite.php — invite many people at once + agreements to renew
 * (23 Oct 2026, owner prompt "Agent/Staff Registration" points 10, 13, 14).
 *
 *   Invite   paste rows from Excel (or upload a .csv saved from Excel):
 *              name, mobile, kind (agent | staff | partner), country (91 | 977, optional)
 *            Each person gets ONE WhatsApp with their own form link
 *            (/agreements/apply.html?kind=…&name=…&phone=…&cc=…) — the form
 *            opens on the right kind with the name and mobile filled in, and
 *            they finish it themselves (photo, ID, selfie, signature): an
 *            invite never creates an account or an application. A mobile
 *            already on file (application or login) is skipped and shown.
 *            No WhatsApp sender = a "Send" wa.me button per row. At most
 *            INVITE_MAX rows per batch; every invite is audited (team.invite).
 *   Renew    agreements ending within 30 days and the ones that ended, from
 *            TeamExpiry (cron/agreement-expiry.php sends the 30/15/7-day
 *            WhatsApps and marks the ended ones 'expired'). The super-admin
 *            switch "agreement_expiry_lock" also turns the login off on expiry.
 *
 * Who: super-admin or manager (the same people who decide on Agreements).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/staffapplications.php';
require_once INCLUDE_PATH . '/teamdupes.php';
require_once INCLUDE_PATH . '/teamexpiry.php';
require_once INCLUDE_PATH . '/notify.php';
$admin = admin_boot('customers.view');

const INVITE_MAX = 40;
$role    = (string) ($admin['role'] ?? '');
$selfId  = (int) ($admin['id'] ?? 0);
$canEdit = Auth::isSuperadmin() || $role === 'manager';
$h       = static fn($v): string => Security::e((string) ($v ?? ''));
$flash   = null;
$results = [];
$KINDS   = ['agent' => 'ind', 'staff' => 'staff', 'partner' => 'org'];

/** The rows of a paste / CSV: [name, digits10, kind, cc] or an error string. */
$parse = static function (string $raw) use ($KINDS): array {
    $out = [];
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;   // Excel's UTF-8 BOM
    foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $n => $line) {
        if (trim($line) === '') {
            continue;
        }
        $cols = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line, ',', '"', '\\');
        $cols = array_map(static fn($c) => trim((string) $c), $cols);
        if ($n === 0 && preg_match('/name|naam|नाम/i', $cols[0] ?? '') === 1) {
            continue;   // the header row
        }
        $name  = mb_substr(preg_replace('/\s+/', ' ', $cols[0] ?? '') ?? '', 0, 120);
        $dig   = preg_replace('/\D/', '', $cols[1] ?? '') ?? '';
        $kind  = strtolower($cols[2] ?? 'agent') ?: 'agent';
        $kind  = ['ind' => 'agent', 'individual' => 'agent', 'org' => 'partner', 'pvtltd' => 'partner', 'company' => 'partner',
                  'driver' => 'staff', 'conductor' => 'staff', 'counter' => 'staff', 'office' => 'staff'][$kind] ?? $kind;
        $ccIn  = preg_replace('/\D/', '', $cols[3] ?? '') ?? '';
        if (strlen($dig) > 10 && (str_starts_with($dig, '977') || str_starts_with($dig, '91'))) {
            $ccIn = $ccIn !== '' ? $ccIn : (str_starts_with($dig, '977') ? '977' : '91');
            $dig  = substr($dig, -10);
        }
        $dig = ltrim($dig, '0');
        $err = '';
        if ($name === '') {
            $err = 'no name';
        } elseif (strlen($dig) !== 10) {
            $err = 'mobile must be 10 digits';
        } elseif (!isset($KINDS[$kind])) {
            $err = 'kind must be agent, staff or partner';
        }
        // a Nepali mobile starts 97 / 98; anything else defaults to India
        $cc = in_array($ccIn, ['91', '977'], true) ? $ccIn : (preg_match('/^9[78]/', $dig) === 1 ? '977' : '91');
        $out[] = ['line' => $n + 1, 'name' => $name, 'phone' => $dig, 'kind' => $kind, 'cc' => $cc, 'err' => $err];
    }
    return $out;
};

/** Already on file? '' or the ref / login it matched. */
$onFile = static function (string $d10): string {
    $like = '%' . $d10;
    if (AgentApplications::available() && ($r = Database::scalar('SELECT ref FROM agent_applications WHERE phone LIKE :p ORDER BY id DESC LIMIT 1', ['p' => $like], null)) !== null) {
        return (string) $r;
    }
    if (StaffApps::available() && ($r = Database::scalar('SELECT id FROM staff_applications WHERE phone LIKE :p ORDER BY id DESC LIMIT 1', ['p' => $like], null)) !== null) {
        return StaffApps::refFor((int) $r);
    }
    if (($r = Database::scalar("SELECT username FROM admins WHERE phone LIKE :p AND role IN ('agent','counter','manager','operations') LIMIT 1", ['p' => $like], null)) !== null) {
        return 'login ' . (string) $r;
    }
    return '';
};

$inviteLink = static function (array $r) use ($KINDS): string {
    return appUrl('agreements/apply.html') . '?' . http_build_query(['kind' => $KINDS[$r['kind']], 'name' => $r['name'], 'phone' => $r['phone'], 'cc' => $r['cc']]);
};
$inviteText = static function (array $r, string $link): string {
    $what = ['agent' => 'एजेन्ट · Agent', 'staff' => 'कर्मचारी · Staff', 'partner' => 'प्रा. लि. साझेदार · Pvt. Ltd. partner'][$r['kind']];
    return '🙏 नमस्ते ' . $r['name'] . ' · ' . Settings::getString('company_name', APP_NAME) . "\n"
         . 'तपाईंलाई हाम्रो ' . $what . " फारम भर्न निमन्त्रणा छ।\nYou are invited to fill in our " . strtolower(explode(' · ', $what)[1]) . " form.\n\n"
         . "📝 " . $link . "\n\n"
         . "फोटो, ID कागज, सेल्फी र हस्ताक्षर फोनबाटै (५ मिनेट)। कार्यालयले हेरेपछि WhatsApp मा खबर आउँछ।\n"
         . 'Photo, ID, selfie and signature from your phone (5 minutes). The office replies on WhatsApp. Help: ' . Settings::officePhone();
};

/* ---------- actions (POST + CSRF) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        http_response_code(419);
        $flash = ['bad', 'This page was open too long — nothing was sent. Please try again.'];
    } elseif (!$canEdit) {
        $flash = ['bad', 'Only a super-admin or a manager can invite people.'];
    } elseif ($act === 'lock' && Auth::isSuperadmin()) {
        $on = !empty($_POST['on']);
        Settings::set(TeamExpiry::LOCK_KEY, $on ? '1' : '0', 'bool', 'agent', false);
        Logger::audit('agreement.expiry_lock', 'settings', TeamExpiry::LOCK_KEY, null, ['on' => $on], 'team-invite.php (by admin #' . $selfId . ')');
        $flash = ['ok', $on ? 'On: an agent whose agreement ends is also signed out (login off) until the office renews it.' : 'Off: an ended agreement shows "Agreement ended" on the ID card; the login stays on.'];
    } elseif ($act === 'invite') {
        $raw = (string) ($_POST['rows'] ?? '');
        $up  = $_FILES['csv'] ?? null;
        if (is_array($up) && (int) ($up['error'] ?? 4) === UPLOAD_ERR_OK && (int) $up['size'] > 0 && (int) $up['size'] <= 200000) {
            $raw .= "\n" . (string) file_get_contents((string) $up['tmp_name']);
        }
        $rows = $parse($raw);
        if ($rows === []) {
            $flash = ['bad', 'Nothing to invite — paste rows like:  Ram Thapa, 9800000000, agent'];
        } elseif (count($rows) > INVITE_MAX) {
            $flash = ['bad', count($rows) . ' rows — send at most ' . INVITE_MAX . ' at a time (WhatsApp sends one by one).'];
        } else {
            $seen = [];
            $sent = 0;
            foreach ($rows as $r) {
                $r['state'] = 'error';
                $r['link']  = '';
                $r['wa']    = '';
                if ($r['err'] === '') {
                    if (isset($seen[$r['phone']])) {
                        $r['err'] = 'same mobile twice in this list';
                    } elseif (($have = $onFile($r['phone'])) !== '') {
                        $r['state'] = 'skipped';
                        $r['err']   = 'already on file: ' . $have;
                    } else {
                        $link = $inviteLink($r);
                        $text = $inviteText($r, $link);
                        $res  = Notify::whatsapp($r['cc'] . $r['phone'], $text, null, null, [], null, ['purpose' => 'team_invite']);
                        $r['link']  = $link;
                        $r['state'] = $res === true ? 'sent' : 'manual';
                        $r['wa']    = 'https://wa.me/' . $r['cc'] . $r['phone'] . '?text=' . rawurlencode($text);
                        $sent += $res === true ? 1 : 0;
                        Logger::audit('team.invite', $r['kind'], '+' . $r['cc'] . ' ' . substr($r['phone'], 0, 2) . '•••••' . substr($r['phone'], -3), null,
                            ['kind' => $r['kind'], 'name' => $r['name'], 'sent' => $res === true], 'Bulk invite (by admin #' . $selfId . ')');
                    }
                    $seen[$r['phone']] = true;
                }
                $results[] = $r;
            }
            $manual = count(array_filter($results, static fn($r) => $r['state'] === 'manual'));
            $flash = ['ok', $sent . ' invite' . ($sent === 1 ? '' : 's') . ' sent on WhatsApp' . ($manual > 0 ? ' · ' . $manual . ' to send by hand (tap Send)' : '') . '.'];
        }
    }
}

/* ---------- renewals ---------- */
$today   = new DateTimeImmutable('today');
$soon    = [];
foreach (TeamExpiry::signed() as $p) {
    $days = (int) $today->diff(new DateTimeImmutable($p['end']))->format('%r%a');
    if ($days <= 30) {
        $soon[] = $p + ['days' => $days];
    }
}
usort($soon, static fn($a, $b) => $a['days'] <=> $b['days']);
$ended = [];
if (AgentApplications::profileColumnsAvailable()) {
    $ended = Database::fetchAll(
        "SELECT a.id, a.full_name AS name, a.phone, a.is_active, p.agreement_end AS end
           FROM admin_profiles p JOIN admins a ON a.id = p.admin_id AND a.role = 'agent'
          WHERE p.agreement_status = 'expired' ORDER BY p.agreement_end DESC LIMIT 100"
    );
}
$lockOn = Settings::getBool(TeamExpiry::LOCK_KEY, false);
$csrfField = Security::csrfField();

admin_header('Team invites & renewals', 'agreements');
if ($flash !== null) { echo '<div class="flash ' . $h($flash[0]) . '">' . $h($flash[1]) . '</div>'; }
?>
<style>
.ti-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:14px;align-items:start}
.ti-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px}
.ti-card h2{margin:0 0 6px;font-size:16px;display:flex;gap:8px;align-items:center}
.ti-card p.n{font-size:12.5px;color:var(--mut);margin:0 0 10px;line-height:1.5}
.ti-card textarea{width:100%;min-height:150px;padding:10px;border:1px solid var(--line);border-radius:10px;background:var(--card);color:var(--ink);font:13.5px/1.5 ui-monospace,Consolas,monospace}
.ti-row{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-top:8px}
.ti-t{width:100%;border-collapse:collapse;font-size:13px}
.ti-t th,.ti-t td{padding:7px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.ti-t th{font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}
.ti-pill{display:inline-block;padding:2px 8px;border-radius:999px;font-size:11.5px;font-weight:800}
.ti-sent{background:rgba(15,157,107,.14);color:#0f7a53}.ti-manual{background:rgba(47,107,255,.12);color:#1f4fe0}
.ti-skipped{background:rgba(178,106,0,.12);color:#8a5200}.ti-error{background:rgba(217,48,37,.12);color:#b3261e}
.ti-d{font-weight:800}.ti-d.bad{color:#b3261e}.ti-d.warn{color:#b26a00}
.ti-wrap{overflow-x:auto}
</style>

<p class="ti-row" style="margin:0 0 12px"><a class="btn ghost" href="agreements.php">🤝 Agent &amp; partner applications</a> <a class="btn ghost" href="staff-applications.php">🧑‍✈️ Staff applications</a> <a class="btn ghost" href="/agreements/apply.html" target="_blank" rel="noopener">📝 The form</a> <a class="btn ghost" href="/verify-id.php" target="_blank" rel="noopener">🔎 Verify page</a></p>

<div class="ti-grid">
  <section class="ti-card">
    <h2>📨 Invite many people</h2>
    <p class="n">Paste from Excel (columns: <b>name</b>, <b>mobile</b>, <b>kind</b> = agent / staff / partner, <b>country</b> 91 or 977 — optional) or upload a .csv saved from Excel. Each person gets one WhatsApp with their own form link, already set to their kind with the name and mobile filled in. Nothing is created until they send the form themselves. Up to <?= INVITE_MAX ?> rows at a time.</p>
    <?php if ($canEdit): ?>
    <form method="post" enctype="multipart/form-data" action="team-invite.php"><?= $csrfField ?><input type="hidden" name="action" value="invite">
      <textarea name="rows" placeholder="name, mobile, kind, country&#10;Ram Bahadur Thapa, 9800000000, agent&#10;Sita Sharma, 9725000000, staff, 91&#10;Hari Travels Pvt Ltd, 9811111111, partner"><?= $h($_POST['rows'] ?? '') ?></textarea>
      <div class="ti-row"><label class="muted text-xs">or CSV file <input type="file" name="csv" accept=".csv,text/csv"></label>
        <button class="btn ok" type="submit" onclick="return confirm('Send a WhatsApp invite to every row?')">📨 Send invites</button></div>
    </form>
    <?php else: ?><p class="muted">Only a super-admin or a manager can send invites.</p><?php endif; ?>
  </section>

  <section class="ti-card">
    <h2>🔁 Agreements to renew</h2>
    <p class="n">Ending within 30 days, from agent / partner agreements. They get a WhatsApp 30, 15 and 7 days before (cron/agreement-expiry.php, daily); the day after the end the agreement turns <b>Expired</b> and the ID card says “Agreement ended”. Renew: they sign again at /team/agreement.php, then set the agreement back to <b>Signed</b> on Agreements.</p>
    <?php if ($soon === []): ?><p class="muted">Nothing ends in the next 30 days.</p><?php else: ?>
    <div class="ti-wrap"><table class="ti-t"><thead><tr><th>Who</th><th>Ends</th><th>Left</th></tr></thead><tbody>
    <?php foreach ($soon as $p): $url = $p['src'] === 'account' ? 'agreements.php?tab=agents#ag' . (int) $p['id'] : 'agreements.php?tab=apps&id=' . (int) $p['id'] . '#detail'; ?>
      <tr><td><a href="<?= $h($url) ?>"><?= $h($p['name']) ?></a> <span class="muted text-xs"><?= $h($p['kind']) ?></span></td><td><?= $h(date('d M Y', strtotime($p['end']) ?: time())) ?></td>
        <td class="ti-d <?= $p['days'] < 0 ? 'bad' : ($p['days'] <= 7 ? 'warn' : '') ?>"><?= $p['days'] < 0 ? 'ended' : $p['days'] . ' d' ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
    <?php if ($ended !== []): ?>
    <details style="margin-top:10px"><summary><b><?= count($ended) ?></b> expired agreement<?= count($ended) === 1 ? '' : 's' ?></summary>
      <div class="ti-wrap"><table class="ti-t"><tbody><?php foreach ($ended as $x): ?><tr><td><a href="agreements.php?tab=agents#ag<?= (int) $x['id'] ?>"><?= $h($x['name']) ?></a></td><td><?= $h($x['end'] ? date('d M Y', strtotime((string) $x['end']) ?: time()) : '—') ?></td><td><?= (int) $x['is_active'] === 1 ? 'login on' : '<b>login off</b>' ?></td></tr><?php endforeach; ?></tbody></table></div>
    </details><?php endif; ?>
    <?php if (Auth::isSuperadmin()): ?>
    <form method="post" action="team-invite.php" class="ti-row" style="margin-top:12px"><?= $csrfField ?><input type="hidden" name="action" value="lock"><input type="hidden" name="on" value="<?= $lockOn ? '' : '1' ?>">
      <span class="text-xs"><?= $lockOn ? '🔒 <b>On</b> — an ended agreement also turns the agent\'s login off.' : '🔓 <b>Off</b> — an ended agreement leaves the login on (the ID card still says “Agreement ended”).' ?></span>
      <button class="btn ghost sm" type="submit"><?= $lockOn ? 'Turn off' : 'Turn on login lock' ?></button></form>
    <?php endif; ?>
  </section>
</div>

<?php if ($results !== []): ?>
<section class="ti-card" style="margin-top:14px">
  <h2>Result</h2>
  <div class="ti-wrap"><table class="ti-t"><thead><tr><th>#</th><th>Name</th><th>Mobile</th><th>Kind</th><th>State</th><th></th></tr></thead><tbody>
  <?php foreach ($results as $r): ?>
    <tr><td class="muted"><?= (int) $r['line'] ?></td><td><?= $h($r['name']) ?></td><td class="mono">+<?= $h($r['cc'] . ' ' . $r['phone']) ?></td><td><?= $h($r['kind']) ?></td>
      <td><span class="ti-pill ti-<?= $h($r['state']) ?>"><?= $h(['sent' => '✅ sent', 'manual' => '✋ send by hand', 'skipped' => 'skipped', 'error' => 'error'][$r['state']]) ?></span><?= $r['err'] !== '' ? ' <span class="muted text-xs">' . $h($r['err']) . '</span>' : '' ?></td>
      <td><?php if ($r['wa'] !== '' && $r['state'] === 'manual'): ?><a class="btn sm" href="<?= $h($r['wa']) ?>" target="_blank" rel="noopener">💬 Send</a><?php endif; ?><?php if ($r['link'] !== ''): ?> <a class="text-xs" href="<?= $h($r['link']) ?>" target="_blank" rel="noopener">form link</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
<?php endif; ?>
<?php admin_footer(); ?>
