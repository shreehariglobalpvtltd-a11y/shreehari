<?php
/**
 * admin/agent-quick.php — ⚡ Quick Agent: NAME + NUMBER → ID + code at once.
 *
 * 6 Nov 2026 (owner: "admin le staff agent ko form ... direct admin le name
 * number matra halera ID banaun milne — details pachhi add garne, tatkal agent
 * code dine"). Two boxes and one button:
 *
 *   name + mobile (+ role chip, + optional starting PIN)
 *     → AgentPro draft (office) → the WhatsApp check skipped by the office
 *     → submit → approve: login + agent code / staff no. issued at once
 *     → the starting PIN (QuickUnlock) so the person opens with mobile + PIN
 *
 * Everything else (ID photo, address, bank, KYC) is "पछि थप्ने · add later"
 * on the same record: the result links to the full office form for this
 * draft (/join.php?office=1&d=<key>) and to the member page. Nothing new in
 * the database — the same AgentPro calls api/agentpro.php makes for the
 * office form, so the record is identical to one made there.
 *
 * Old links "?done=AGR-…" (the 29 Oct page) still show those cards.
 * Needs: AgentPro::officeAllowed (superadmin, manager or staff.manage).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
require_once INCLUDE_PATH . '/agentwallet.php';
require_once INCLUDE_PATH . '/agentpro.php';
require_once INCLUDE_PATH . '/quickunlock.php';
$admin = admin_boot('customers.view');
$e     = static fn($v): string => Security::e((string) ($v ?? ''));

/* ---- an old "?done=AGR-…" link: the 29 Oct card view, unchanged ---- */
$doneRef = strtoupper(Security::clean((string) ($_GET['done'] ?? ''), 20));
if ($doneRef !== '') {
    require_once INCLUDE_PATH . '/agentapplications.php';
    require_once INCLUDE_PATH . '/idcard.php';
    $person = null;
    try { $person = AgentApplications::available() ? IdCard::person($doneRef) : null; } catch (Throwable $ex) { $person = null; }
    if ($person !== null) {
        admin_header('Quick agent & ID card', 'agent-quick');
        echo '<div class="flash ok" style="font-size:15px">✅ <b>' . $e($person['name']) . '</b> — card ' . $e($person['ref'])
           . (($person['agentCode'] ?? '') !== '' ? ' · agent code <b>' . $e($person['agentCode']) . '</b>' : '') . '</div>'
           . '<p><a class="btn ok" href="/admin/id-card.php?ref=' . urlencode($person['ref']) . '&amp;pdf=all">⬇ ID card PDF</a> '
           . '<a class="btn ghost" href="/admin/id-card.php?ref=' . urlencode($person['ref']) . '">Send card / more →</a> '
           . '<a class="btn" href="/admin/agent-quick.php">＋ नयाँ · New quick agent</a></p>';
        admin_footer();
        exit;
    }
}

$allowed = AgentPro::available() && AgentPro::officeAllowed($admin);
$roles = [
    'agent'     => '🧑‍💼 एजेन्ट · Agent',
    'counter'   => '🏪 काउन्टर · Counter',
    'driver'    => '🚌 ड्राइभर · Driver',
    'conductor' => '🎫 कन्डक्टर · Conductor',
    'support'   => '📞 सपोर्ट · Support',
    'official'  => '🗂 अफिस स्टाफ · Office staff',
];
$in = [
    'name'  => Security::clean((string) ($_POST['name'] ?? ''), 120),
    'phone' => Security::clean((string) ($_POST['phone'] ?? ''), 20),
    'role'  => isset($roles[(string) ($_POST['role'] ?? '')]) ? (string) $_POST['role'] : 'agent',
    'pin'   => preg_replace('/\D/', '', (string) ($_POST['pin'] ?? '')) ?? '',
    'share' => !empty($_POST['share']),
];
$error = '';
$taken = null;      // ApPhoneTaken owners → "add another ID on this number?"
$done  = $_SESSION['shg_quick2_done'] ?? null;

if ($allowed && Security::isPost()) {
    if (!Security::verifyCsrf()) {
        $error = 'यो पेज धेरै बेर खुला थियो — फेरि थिच्नुहोस् · Page was open too long, press again.';
    } else {
        try {
            if (mb_strlen(trim($in['name'])) < 2) {
                throw new RuntimeException('नाम लेख्नुहोस् · Type the name.');
            }
            $digits = preg_replace('/\D/', '', $in['phone']) ?? '';
            if (strlen($digits) < 8) {
                throw new RuntimeException('मोबाइल नम्बर लेख्नुहोस् · Type the mobile number.');
            }
            if ($in['pin'] !== '' && ($why = QuickUnlock::pinProblem($in['pin'])) !== null) {
                throw new RuntimeException($why);
            }
            $by      = (int) $admin['id'];
            $country = resolvePhoneCountry('', $in['phone']) ?: 'IN';
            $draft   = AgentPro::newDraft('', $by);
            $key     = (string) $draft['draft_key'];
            if ($in['role'] !== 'agent') {
                AgentPro::officeSetRole($key, $in['role']);
            }
            AgentPro::saveDraft($key, ['full_name' => $in['name'], 'country' => $country], true);
            AgentPro::officeVerify($key, $by, 'Office quick add (name + number only) — details later', $in['phone'], $country, $in['share']);
            $app = AgentPro::submit($key, true, $by, $in['share']);
            $res = AgentPro::approve((int) $app['id'], $by, false);
            $aid = (int) $res['admin_id'];
            $m   = AgentPro::member($aid) ?? [];
            $pinSet = false;
            if ($in['pin'] !== '' && QuickUnlock::ready()) {
                QuickUnlock::set($aid, 'pin', $in['pin']);
                $pinSet = true;
            }
            Logger::audit('agent.quick_add', 'admin', (string) $aid, null,
                ['role' => $in['role'], 'pin' => $pinSet], 'Quick add (name + number) by admin #' . $by);
            $_SESSION['shg_quick2_done'] = [
                'id'    => $aid,
                'name'  => (string) ($m['name'] ?? $in['name']),
                'code'  => (string) (($res['code'] ?? '') !== '' ? $res['code'] : ($m['idNo'] ?? '')),
                'phone' => (string) ($m['phone'] ?? $in['phone']),
                'role'  => $in['role'],
                'key'   => $key,
                'pin'   => $pinSet ? $in['pin'] : '',     // shown ONCE on the next page, then dropped
                'pwd'   => isset($res['password']),
            ];
            Response::redirect('/admin/agent-quick.php?ok=1', 303);
        } catch (ApPhoneTaken $ex) {
            $taken = ['owners' => $ex->owners, 'canShare' => $ex->canShare, 'max' => $ex->max, 'msg' => $ex->getMessage()];
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

$showDone = isset($_GET['ok']) && is_array($done);
if ($showDone) {
    unset($_SESSION['shg_quick2_done']);   // the PIN is shown once
}
/* a random 4-digit starter PIN that passes QuickUnlock::pinProblem */
$suggestPin = static function (): string {
    for ($i = 0; $i < 30; $i++) {
        $p = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        if (QuickUnlock::pinProblem($p) === null) { return $p; }
    }
    return '2580';
};

admin_header('⚡ Quick Agent · नाम + नम्बर', 'agent-quick');
?>
<style>
.qa{max-width:560px}
.qa .card{background:var(--card,#fff);border:1px solid var(--line,#dcdcdc);border-radius:18px;padding:18px 18px 20px;box-shadow:0 8px 24px -14px rgba(9,27,84,.35)}
.qa label{display:block;font-weight:800;font-size:14px;margin:12px 0 6px}
.qa input[type=text],.qa input[type=tel]{width:100%;min-height:54px;font-size:19px;padding:10px 14px;border-radius:12px;border:1.5px solid var(--line,#9aa6ba);background:var(--bg,#f6f8fc);color:inherit}
.qa .roles{display:flex;flex-wrap:wrap;gap:8px}
.qa .roles label{margin:0;font-weight:700;font-size:13.5px;border:1.5px solid var(--line,#c9d3e3);border-radius:999px;padding:8px 12px;cursor:pointer}
.qa .roles input{display:none}
.qa .roles input:checked + span{color:#fff}
.qa .roles label:has(input:checked){background:#0054A8;border-color:#0054A8;color:#fff}
.qa .go{width:100%;min-height:58px;font-size:18px;font-weight:800;margin-top:16px;border-radius:14px}
.qa .muted{font-size:12.5px;color:var(--mut,#5c6b85);margin-top:8px;line-height:1.5}
.qa details{margin-top:12px}.qa summary{cursor:pointer;font-weight:700;font-size:13.5px}
.qa-done{max-width:620px;text-align:center}
.qa-done .code{font-size:42px;font-weight:900;letter-spacing:2px;color:#0054A8;margin:6px 0}
.qa-done .pin{display:inline-block;font-size:28px;font-weight:900;letter-spacing:8px;background:#FFF4E0;color:#7A4A00;border-radius:12px;padding:6px 16px}
.qa-done .acts{display:flex;flex-wrap:wrap;gap:10px;justify-content:center;margin-top:16px}
.qa-done .acts .btn{min-height:48px;display:inline-flex;align-items:center}
</style>

<?php if (!$allowed): ?>
  <div class="flash bad">यो काम म्यानेजर / सुपर एडमिनले मात्र गर्न मिल्छ · Only a manager or super-admin may add people here<?= AgentPro::available() ? '' : ' (Agent Pro is not installed)' ?>.</div>

<?php elseif ($showDone): ?>
  <div class="qa-done card" style="background:var(--card,#fff);border:1px solid var(--line);border-radius:18px;padding:22px">
    <div style="font-size:40px">✅</div>
    <h2 style="margin:4px 0"><?= $e($done['name']) ?></h2>
    <div class="muted"><?= $e($roles[$done['role']] ?? $done['role']) ?> · <?= $e($done['phone']) ?></div>
    <div class="code"><?= $e($done['code'] !== '' ? $done['code'] : '—') ?></div>
    <div class="muted">एजेन्ट कोड / ID नं. · Agent code / ID — तुरुन्त चालु · active now</div>
    <?php if ($done['pin'] !== ''): ?>
      <p style="margin:14px 0 4px;font-weight:800">लगइन PIN (एक पटक मात्र देखिन्छ · shown once)</p>
      <div class="pin"><?= $e($done['pin']) ?></div>
      <p class="muted">लगइन: मोबाइल नम्बर लेख्ने → PIN · Sign in: type the mobile → PIN. पछि आफैं pattern राख्न सकिन्छ।</p>
    <?php else: ?>
      <p class="muted">लगइन: मोबाइल नम्बर → WhatsApp मा "hi" वा कोड<?= $done['pwd'] ? ' (staff: temporary password WhatsApp मा गयो)' : '' ?>. भित्र गएपछि 🔐 PIN / Pattern राख्ने।</p>
    <?php endif; ?>
    <?php
      $waTxt = "🎉 " . Settings::getString('company_name', APP_NAME) . "\n" . $done['name'] . " — ID " . $done['code']
             . "\n🔐 " . appUrl('admin/login.php') . "\nमोबाइल: " . $done['phone']
             . ($done['pin'] !== '' ? "\nPIN: " . $done['pin'] : "\nWhatsApp मा hi / कोड बाट लगइन");
      $waTo = preg_replace('/\D/', '', $done['phone']) ?? '';
    ?>
    <div class="acts">
      <?php if ($waTo !== ''): ?><a class="btn" style="background:#25d366;color:#fff;border-color:#25d366" target="_blank" rel="noopener" href="https://wa.me/<?= $e($waTo) ?>?text=<?= rawurlencode($waTxt) ?>">📲 WhatsApp मा पठाउने</a><?php endif; ?>
      <a class="btn ghost" href="/join.php?office=1&amp;d=<?= $e($done['key']) ?>">✍️ बाँकी विवरण थप्ने · Add details later</a>
      <a class="btn ghost" href="/admin/team-member.php?id=<?= (int) $done['id'] ?>">🪪 ID card · record</a>
      <a class="btn ok" href="/admin/agent-quick.php">＋ अर्को · Add another</a>
    </div>
  </div>

<?php else: ?>
  <div class="qa">
    <?php if ($error !== ''): ?><div class="flash bad"><?= $e($error) ?></div><?php endif; ?>
    <?php if ($taken !== null): ?>
      <div class="flash bad">📱 <?= $e($taken['msg']) ?>
        <?php if ($taken['canShare']): ?><br><b>यही नम्बरमा अर्को ID चाहिन्छ भने तलको "एउटै नम्बरमा अर्को ID" टिक गरेर फेरि थिच्नुहोस्।</b><?php endif; ?>
      </div>
    <?php endif; ?>
    <form class="card" method="post" autocomplete="off">
      <?= Security::csrfField() ?>
      <label for="qn">नाम · Full name</label>
      <input id="qn" type="text" name="name" required autofocus maxlength="120" value="<?= $e($in['name']) ?>" placeholder="Ram Bahadur Thapa">
      <label for="qp">मोबाइल नम्बर · Mobile</label>
      <input id="qp" type="tel" name="phone" required inputmode="tel" maxlength="20" value="<?= $e($in['phone']) ?>" placeholder="98XXXXXXXX / +91 9XXXXXXXXX">
      <label>के हो? · Role</label>
      <div class="roles">
        <?php foreach ($roles as $k => $lbl): ?>
          <label><input type="radio" name="role" value="<?= $e($k) ?>"<?= $in['role'] === $k ? ' checked' : '' ?>><span><?= $e($lbl) ?></span></label>
        <?php endforeach; ?>
      </div>
      <?php if (QuickUnlock::ready()): ?>
      <label for="qpin">लगइन PIN (ऐच्छिक) · Starting PIN (optional)</label>
      <input id="qpin" type="text" name="pin" inputmode="numeric" maxlength="6" pattern="\d{4,6}" value="<?= $e($in['pin'] !== '' ? $in['pin'] : $suggestPin()) ?>" style="letter-spacing:6px;font-weight:800;max-width:200px">
      <div class="muted">यो PIN ले मोबाइल नम्बर + PIN बाट तुरुन्त लगइन हुन्छ। खाली छोडे WhatsApp कोडबाट लगइन।</div>
      <?php endif; ?>
      <?php if ($taken !== null && $taken['canShare']): ?>
        <label style="font-weight:700"><input type="checkbox" name="share" value="1"> एउटै नम्बरमा अर्को ID · Another ID on this number</label>
      <?php endif; ?>
      <button class="btn ok go" type="submit">⚡ ID बनाउने · Create ID now</button>
      <div class="muted">ID फोटो, ठेगाना, बैंक, KYC — सबै पछि थप्न सकिन्छ · Photo, address, bank, KYC can be added later. Code तुरुन्त दिइन्छ।</div>
      <details><summary>पूरा फारम चाहियो? · Need the full form?</summary>
        <p class="muted"><a href="/join.php?office=1">पूरा अफिस फारम (ID फोटो पढ्ने, सही, बैंक) खोल्नुहोस् →</a></p></details>
    </form>
  </div>
<?php endif; ?>
<?php admin_footer();
