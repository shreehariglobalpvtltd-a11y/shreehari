<?php
/**
 * admin/agent-quick.php — the office fills the agent form (29 Oct 2026,
 * owner: "agent digital form system lai admin le bharna milne, only
 * document number and photo required, ID card visiting card generate hos").
 *
 * One screen for an agent who came to the office or phoned in. Required:
 * name, mobile, the ID document number and a photo — everything else
 * (ID type, area, shop address, e-mail) is optional. On Save, through the
 * same code the Agreements dashboard uses:
 *   1. AgentApplications::adminCreate()  — the agent record (photo, ID no.)
 *   2. AgentApplications::update(approved) — approved at once
 *   3. KYC "verified" when the office ticks "I saw the original ID"
 *   4. optional login + SHG agent code (AgentWallet::createAgent) and the
 *      sign-in details on WhatsApp (Notify::agentCredentials), exactly like
 *      "Approve & put live"
 * …then the finished ID card and visiting card are shown, ready to print,
 * download as PDF or send.
 *
 * Who: a super-admin or a manager (the Agreements dashboard's rule).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentapplications.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/idcard.php';
$admin = admin_boot('customers.view');

$selfId  = (int) ($admin['id'] ?? 0);
$canEdit = Auth::isSuperadmin() || (string) ($admin['role'] ?? '') === 'manager';
$e       = static fn($v): string => Security::e((string) ($v ?? ''));
if (!$canEdit || !AgentApplications::available()) {
    admin_header('Quick agent & ID card', 'agent-quick');
    echo '<div class="flash bad">' . ($canEdit ? 'Agent applications are not set up on this database yet.' : 'Only a super-admin or a manager can add agents.') . '</div>';
    admin_footer();
    exit;
}

$flash = null;
$old   = [];
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $old = $_POST;
    try {
        if (!Security::verifyCsrf()) {
            throw new AgreementError('This page was open too long — nothing was saved. Please try again.');
        }
        $name = trim((string) ($_POST['name'] ?? ''));
        $idNo = trim((string) ($_POST['p_idno'] ?? ''));
        if (mb_strlen($name) < 2) {
            throw new AgreementError('Type the agent\'s full name.');
        }
        if (strlen((string) preg_replace('/\D/', '', (string) ($_POST['phone'] ?? ''))) < 10) {
            throw new AgreementError('Type a mobile number (10 digits, or with +91 / +977).');
        }
        if (mb_strlen($idNo) < 4) {
            throw new AgreementError('Type the ID document number (Aadhaar / citizenship / passport / voter ID).');
        }
        $photo = AgentApplications::photoFromUpload(isset($_FILES['photo']) && is_array($_FILES['photo']) ? $_FILES['photo'] : null);
        if ($photo === null) {
            throw new AgreementError('Add the agent\'s photo — take it with the camera or choose a picture (JPG / PNG / WebP).');
        }
        $seen = isset($_POST['id_seen']);
        $in = [
            'name'       => $name,
            'phone'      => (string) ($_POST['phone'] ?? ''),
            'type'       => (string) ($_POST['type'] ?? 'ind') === 'org' ? 'org' : 'ind',
            'area'       => (string) ($_POST['area'] ?? ''),
            'web_addr'   => (string) ($_POST['web_addr'] ?? ''),
            'email'      => (string) ($_POST['email'] ?? ''),
            'p_idtype'   => (string) ($_POST['p_idtype'] ?? ''),
            'p_idno'     => $idNo,
            'photo'      => $photo,
            'kyc_status' => $seen ? 'verified' : 'submitted',
            'public_ok'  => isset($_POST['web_live']) ? 1 : 0,
            'web_photo'  => isset($_POST['web_live']) ? 1 : 0,
            'web_live'   => isset($_POST['web_live']) ? 1 : 0,
        ];
        $made = AgentApplications::adminCreate($in, $selfId, true);
        AgentApplications::update((int) $made['id'], ['status' => 'approved', 'kyc_status' => $in['kyc_status']], $selfId);
        Logger::audit('agreement_app.quick_add', 'agent_application', $made['ref'], null,
            ['kyc' => $in['kyc_status'], 'login' => isset($_POST['mklogin'])], 'quick agent form (by admin #' . $selfId . ')');

        $done = ['ref' => $made['ref'], 'id' => (int) $made['id'], 'sent' => false, 'hand' => '', 'login' => ''];
        if (isset($_POST['mklogin'])) {
            $app = AgentApplications::find((int) $made['id']);
            if ($app !== null && empty($app['admin_id'])) {
                $pw = AgentWallet::tempPassword();
                try {
                    $newId = AgentWallet::createAgent((string) $app['name'], (string) (($app['phone_raw'] ?? '') ?: $app['phone']),
                        (string) $app['type'] === 'org' ? 'org' : 'person', (string) ($app['area'] ?? ''), (string) ($app['email'] ?? '') ?: null,
                        $selfId, ['password' => $pw, 'no_welcome' => true]);
                    AgentApplications::update((int) $app['id'], ['admin_id' => $newId], $selfId);
                    Auth::setStaffCredentials($newId, ['password' => $pw, 'must_change' => true], $selfId);
                    require_once INCLUDE_PATH . '/notify.php';
                    $sent = Notify::agentCredentials($newId, (string) $app['name'], (string) (($app['phone_raw'] ?? '') ?: $app['phone']),
                        preg_replace('/\D+/', '', (string) $app['phone']) ?: (string) $app['phone'], $pw, (string) $app['type'] === 'org' ? 'partner' : 'agent');
                    $done['sent']  = $sent === true;
                    $done['hand']  = is_string($sent) ? $sent : '';
                    $done['login'] = AgentWallet::agentCodeLabel($newId);
                } catch (RuntimeException $re) {
                    // The agent and the cards stand; only the login could not be made (e.g. that mobile already signs in).
                    $done['login'] = 'not made — ' . $re->getMessage();
                }
            }
        }
        $_SESSION['shg_quick_done'] = $done;
        Response::redirect('admin/agent-quick.php?done=' . rawurlencode($made['ref']));
    } catch (RuntimeException $ex) {   // AgreementError is one too
        $flash = ['bad', $ex->getMessage()];
    }
}

/* ---- The result: the cards, ready ------------------------------------ */
$doneRef = strtoupper(Security::clean((string) ($_GET['done'] ?? ''), 20));
$done    = $_SESSION['shg_quick_done'] ?? null;
$person  = $doneRef !== '' ? IdCard::person($doneRef) : null;
if ($done !== null && ($done['ref'] ?? '') !== $doneRef) {
    $done = null;
}

admin_header('Quick agent & ID card', 'agent-quick');
?>
<style>
.aq-wrap{max-width:980px}
.aq-grid{display:grid;grid-template-columns:260px minmax(0,1fr);gap:22px;align-items:start}
.aq-photo{display:flex;flex-direction:column;align-items:center;gap:10px;padding:18px;border:2px dashed var(--line);border-radius:18px;background:var(--head);text-align:center}
.aq-photo img{width:200px;height:230px;object-fit:cover;border-radius:16px;border:4px solid #fff;box-shadow:0 0 0 3px var(--orange);display:none;background:#eef2fb}
.aq-photo img.on{display:block}
.aq-photo .ph{width:200px;height:230px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:56px;background:#eef2fb;color:#9aa8c2}
.aq-photo .ph.off{display:none}
.aq-photo label.btn{min-height:48px;display:inline-flex;align-items:center;gap:8px;cursor:pointer}
.aq-photo input[type=file]{position:absolute;width:1px;height:1px;opacity:0}
.aq-f{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
.aq-f .full{grid-column:1 / -1}
.aq-f label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--mut);letter-spacing:.02em}
.aq-f label b{color:#c0392b}
.aq-f input,.aq-f select{min-height:48px;padding:10px 12px;border:1px solid var(--line);border-radius:12px;font-size:16px;font-weight:500;color:var(--ink);background:var(--card)}
.aq-f input:focus,.aq-f select:focus{outline:2px solid var(--blue);border-color:var(--blue)}
.aq-opts{display:flex;flex-direction:column;gap:10px;margin:16px 0 0}
.aq-opts label{display:flex;gap:10px;align-items:flex-start;font-size:14px;color:var(--ink);cursor:pointer}
.aq-opts input{width:20px;height:20px;margin-top:1px;accent-color:var(--blue)}
.aq-opts small{display:block;color:var(--mut);font-size:12px}
.aq-go{min-height:54px;font-size:16px;font-weight:800;padding:0 28px;border-radius:14px}
.aq-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px}
.aq-cards figure{margin:0;background:var(--head);border:1px solid var(--line);border-radius:16px;padding:12px;text-align:center}
.aq-cards img{max-width:100%;border-radius:10px;box-shadow:0 6px 18px rgba(9,27,84,.18);background:#fff}
.aq-cards figcaption{font-size:12px;color:var(--mut);margin-top:8px;font-weight:700}
.aq-cards .wide{grid-column:1 / -1}
.aq-acts{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0}
.aq-acts .btn{min-height:48px;display:inline-flex;align-items:center;gap:6px}
@media(max-width:760px){.aq-grid{grid-template-columns:1fr}.aq-f{grid-template-columns:1fr}.aq-cards{grid-template-columns:1fr}}
</style>

<div class="aq-wrap">
<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<?php if ($person !== null): ?>
  <div class="flash ok" style="font-size:15px">✅ <b><?= $e($person['name']) ?></b> is an approved <?= $person['kind'] === 'partner' ? 'partner' : 'agent' ?><?= ($person['agentCode'] ?? '') !== '' ? ' — agent code <b>' . $e($person['agentCode']) . '</b>' : '' ?> · card <?= $e($person['ref']) ?>.
    <?php if ($done !== null && (string) $done['login'] !== '' && str_starts_with((string) $done['login'], 'not made')): ?><br>⚠ Login <?= $e($done['login']) ?><?php endif; ?>
    <?php if ($done !== null && $done['sent']): ?><br>📲 Sign-in details were sent to the agent on WhatsApp.<?php endif; ?></div>
  <div class="panel">
    <h2>🪪 ID card &amp; visiting card · परिचयपत्र</h2>
    <div style="padding:6px 18px 18px">
      <div class="aq-acts">
        <a class="btn ok" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;pdf=all">⬇ Download PDF (print-ready)</a>
        <a class="btn" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=sheet&amp;dl=1">🖼 ID card PNG</a>
        <a class="btn" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=sheet&amp;v=1&amp;dl=1">🖼 Visiting card PNG</a>
        <?php if ($done !== null && (string) $done['hand'] !== ''): ?><a class="btn" style="background:#25d366;color:#fff" href="<?= $e($done['hand']) ?>" target="_blank" rel="noopener">📲 Send login on WhatsApp</a><?php endif; ?>
        <a class="btn ghost" href="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>">Send card / more →</a>
      </div>
      <div class="aq-cards">
        <figure><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=front" alt="ID card front"><figcaption>ID card · front</figcaption></figure>
        <figure><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=back" alt="ID card back"><figcaption>ID card · back</figcaption></figure>
        <figure class="wide"><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=front&amp;v=1" alt="Visiting card front"><figcaption>Visiting card · front</figcaption></figure>
        <figure class="wide"><img src="/admin/id-card.php?ref=<?= urlencode($person['ref']) ?>&amp;png=back&amp;v=1" alt="Visiting card back"><figcaption>Visiting card · back</figcaption></figure>
      </div>
      <div class="aq-acts">
        <a class="btn ghost" href="/admin/agreements.php?tab=apps&amp;id=<?= (int) ($done['id'] ?? (int) Database::scalar('SELECT id FROM agent_applications WHERE ref = :r', ['r' => $person['ref']], 0)) ?>#detail">Open the full record</a>
        <a class="btn" href="/admin/agent-quick.php">＋ Add another agent</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <h2>⚡ Add an agent · एजेन्ट दर्ता — the office fills it</h2>
    <p class="muted" style="padding:0 18px;margin:0 0 6px;font-size:13px">Only <b>name, mobile, ID number and photo</b> are needed. The agent is approved at once and gets an agent code, an ID card and a visiting card. Everything else can be added later on the record.</p>
    <form method="post" enctype="multipart/form-data" style="padding:10px 18px 20px" id="aqForm">
      <?= Security::csrfField() ?>
      <div class="aq-grid">
        <div class="aq-photo">
          <div class="ph" id="aqPh">👤</div>
          <img id="aqImg" alt="Photo preview">
          <label class="btn" for="aqFile">📷 Photo <b style="color:#c0392b">*</b></label>
          <input type="file" id="aqFile" name="photo" accept="image/jpeg,image/png,image/webp" capture="user" required>
          <small class="muted">Face clearly visible, plain background is best.</small>
        </div>
        <div>
          <div class="aq-f">
            <label class="full">Full name · पूरा नाम <b>*</b>
              <input name="name" maxlength="160" required autocomplete="off" value="<?= $e($old['name'] ?? '') ?>" placeholder="As on the ID document"></label>
            <label>Mobile / WhatsApp · मोबाइल <b>*</b>
              <input name="phone" type="tel" maxlength="20" required inputmode="tel" autocomplete="off" value="<?= $e($old['phone'] ?? '') ?>" placeholder="+977 98XXXXXXXX / +91 9XXXXXXXXX"></label>
            <label>ID document · कागजात
              <select name="p_idtype"><?php foreach (AgentApplications::ID_TYPES as $i => $l): ?><option value="<?= $i ?>"<?= (string) ($old['p_idtype'] ?? '') === (string) $i ? ' selected' : '' ?>><?= $e($l) ?></option><?php endforeach; ?></select></label>
            <label class="full">ID document number · कागजात नम्बर <b>*</b>
              <input name="p_idno" maxlength="40" required autocomplete="off" class="mono" value="<?= $e($old['p_idno'] ?? '') ?>" placeholder="Aadhaar / citizenship / passport / voter ID number"></label>
            <label>Area / city
              <input name="area" maxlength="160" value="<?= $e($old['area'] ?? '') ?>" placeholder="e.g. Nepalgunj"></label>
            <label>Agent kind
              <select name="type"><option value="ind">👤 Individual agent</option><option value="org"<?= (string) ($old['type'] ?? '') === 'org' ? ' selected' : '' ?>>🏢 Organization partner</option></select></label>
            <label>Shop / office address (public)
              <input name="web_addr" maxlength="200" value="<?= $e($old['web_addr'] ?? '') ?>"></label>
            <label>E-mail
              <input name="email" type="email" maxlength="160" value="<?= $e($old['email'] ?? '') ?>"></label>
          </div>
          <div class="aq-opts">
            <label><input type="checkbox" name="id_seen" value="1"<?= $old === [] || isset($old['id_seen']) ? ' checked' : '' ?>><span>I saw the original ID document <small>KYC is marked verified, so the card shows ACTIVE. Untick if you only took the number — the card then says KYC pending.</small></span></label>
            <label><input type="checkbox" name="mklogin" value="1"<?= $old === [] || isset($old['mklogin']) ? ' checked' : '' ?>><span>Make the login and agent code (SHG-…) <small>Sign-in details go to the agent on WhatsApp; they change the password at the first sign-in.</small></span></label>
            <label><input type="checkbox" name="web_live" value="1"<?= $old === [] || isset($old['web_live']) ? ' checked' : '' ?>><span>Show on the website (name, photo, number) <small>Agents-by-location cards on shreehariglobal.in.</small></span></label>
          </div>
          <div style="margin-top:18px"><button class="btn ok aq-go" type="submit">✓ Save &amp; make ID card</button></div>
        </div>
      </div>
    </form>
  </div>
  <script>
  (function () {
    var f = document.getElementById('aqFile'), img = document.getElementById('aqImg'), ph = document.getElementById('aqPh');
    if (!f) return;
    f.addEventListener('change', function () {
      var file = f.files && f.files[0];
      if (!file) { img.classList.remove('on'); ph.classList.remove('off'); return; }
      var r = new FileReader();
      r.onload = function () { img.src = r.result; img.classList.add('on'); ph.classList.add('off'); };
      r.readAsDataURL(file);
    });
    document.getElementById('aqForm').addEventListener('submit', function (ev) {
      var b = this.querySelector('button[type=submit]');
      if (b.disabled) { ev.preventDefault(); return; }
      b.disabled = true; b.textContent = 'Saving…';
    });
  })();
  </script>
<?php endif; ?>
</div>
<?php admin_footer();
