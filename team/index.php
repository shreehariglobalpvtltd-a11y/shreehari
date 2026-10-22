<?php
/**
 * /team/ — the team portal door and home (22 Oct 2026, owner: one login for
 * Pvt. Ltd. partners, agents and S Hari staff, tied to the registered
 * mobile; "Password + forgot via WhatsApp").
 *
 *   (signed out)  mobile + password · ?v=forgot -> WhatsApp code ->
 *                 ?v=reset (code or "hi VERIFY", new password x2)
 *   (signed in)   home: Sell tickets (agent / counter account), My ID card,
 *                 My CV, My agreement, Change password (?v=password), Sign out
 *
 * Every POST carries the CSRF token. The logic lives in TeamPortal
 * (includes/teamportal.php); this page only routes and draws.
 */

declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/teamportal.php';
require_once INCLUDE_PATH . '/teamui.php';

$h  = static fn($v): string => Security::e((string) ($v ?? ''));
$v  = (string) ($_GET['v'] ?? '');
$me = TeamPortal::current();
$err = null;

if (!TeamPortal::ready()) {
    TeamUi::head('Team portal');
    echo '<div class="tp-card"><h1>Team portal</h1><p class="tp-sub">The portal is being set up. Please try again in a few minutes.</p></div>';
    TeamUi::foot();
    exit;
}

/* ---------------- actions ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    if (!Security::verifyCsrf()) {
        $err = 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · This page was open too long, please try again.';
    } else {
        try {
            if ($act === 'login') {
                $p = TeamPortal::phone10((string) ($_POST['mobile'] ?? ''));
                $r = TeamPortal::login($p, (string) ($_POST['password'] ?? ''), TeamPortal::cc($_POST['cc'] ?? '91'));
                if ($r['ok']) {
                    TeamUi::go('/team/');
                }
                $err = (string) $r['error'];
            } elseif ($act === 'forgot_send') {
                $p = TeamPortal::phone10((string) ($_POST['mobile'] ?? ''));
                if ($p === '') {
                    $err = '१० अङ्कको मोबाइल नम्बर लेख्नुहोस् · Enter your 10-digit mobile number.';
                    $v = 'forgot';
                } else {
                    // 22 Oct 2026 (review): the same next page for every number (sent or not, registered or not)
                    $cc = TeamPortal::cc($_POST['cc'] ?? '91');
                    $r = TeamPortal::sendResetCode($p, $cc);
                    if (!$r['ok']) {
                        $err = (string) $r['error'];
                        $v = 'forgot';
                    } else {
                        $_SESSION['team_reset'] = ['phone' => $p, 'cc' => $cc, 't' => time()]
                            + (isset($r['debugCode']) ? ['debug' => $r['debugCode']] : []);
                        TeamUi::go('/team/?v=reset');
                    }
                }
            } elseif ($act === 'verify_start') {
                $p = (string) ($_SESSION['team_reset']['phone'] ?? '');
                if ($p === '') {
                    TeamUi::go('/team/?v=forgot');
                }
                TeamPortal::startVerify($p, TeamPortal::cc($_SESSION['team_reset']['cc'] ?? '91'));   // keeps the token + the account's number in $_SESSION['team_reset']['wa']
                TeamUi::go('/team/?v=reset#wa');
            } elseif ($act === 'reset') {
                $p = (string) ($_SESSION['team_reset']['phone'] ?? '');
                if ($p === '') {
                    TeamUi::go('/team/?v=forgot');
                }
                $r = TeamPortal::resetPassword($p, (string) ($_POST['code'] ?? ''), (string) ($_POST['pw'] ?? ''), (string) ($_POST['pw2'] ?? ''));
                if ($r['ok']) {
                    unset($_SESSION['team_reset']);
                    if (!empty($r['twofa'])) {
                        TeamUi::setFlash('ok', '✅ नयाँ पासवर्ड राखियो। यो खातामा 2FA छ — कार्यालयको लगइनबाट जानुहोस् · Password saved; this account signs in at the office login (2FA).');
                        TeamUi::go('/admin/login.php');
                    }
                    TeamUi::setFlash('ok', '✅ नयाँ पासवर्ड राखियो, तपाईं भित्र आउनुभयो · New password saved — you are signed in.');
                    TeamUi::go('/team/');
                }
                $err = (string) $r['error'];
                $v = 'reset';
            } elseif ($act === 'password' && $me !== null) {
                TeamPortal::changePassword($me, (string) ($_POST['old'] ?? ''), (string) ($_POST['pw'] ?? ''), (string) ($_POST['pw2'] ?? ''));
                TeamUi::setFlash('ok', '✅ पासवर्ड बदलियो · Password changed.');
                TeamUi::go('/team/');
            } elseif ($act === 'logout') {
                TeamPortal::logout();
                TeamUi::setFlash('info', 'तपाईं बाहिर निस्कनुभयो · You are signed out.');
                TeamUi::go('/team/');
            }
        } catch (TeamPortalError $e) {
            $err = $e->getMessage();
        } catch (Throwable $e) {
            Logger::exception($e);
            $err = 'केही मिलेन — फेरि प्रयास गर्नुहोस् · Something went wrong, please try again.';
        }
    }
}
$csrf = Security::csrfField();

/* ---------------- signed in: home ---------------- */
if ($me !== null && $v !== 'reset') {
    $staff = $me['staff'];
    $agent = $me['agent'];
    $sell  = TeamPortal::sellUrl($me['admin']);
    $cards = TeamPortal::cardRefs($me);
    $kind  = $agent !== null ? ((string) $agent['type'] === 'org' ? 'partner' : 'agent') : ($staff !== null ? 'staff' : ($me['admin']['role'] ?? ''));
    $pic   = null;
    if ($kind === 'partner' && !empty($agent['logo'])) {
        $pic = (string) $agent['logo'];
    } elseif ($agent !== null && !empty($agent['photo'])) {
        $pic = (string) $agent['photo'];
    } elseif ($staff !== null && !empty($staff['photo_path']) && ($abs = StaffApps::absPath((string) $staff['photo_path'])) !== null) {
        require_once INCLUDE_PATH . '/idcard.php';
        $pic = IdCard::thumb((string) file_get_contents($abs), 160);
    }
    $agrUrl = TeamPortal::agreementsFor($me) !== [] ? '/team/agreement.php' : null;
    TeamUi::head('Home', 'home');
    TeamUi::flash();
    if ($err !== null) {
        echo '<div class="tp-msg bad">' . $h($err) . '</div>';
    }
    if ($v === 'password'): ?>
    <div class="tp-card">
      <h1>🔑 पासवर्ड बदल्नुहोस् · Change password</h1>
      <form method="post" action="/team/?v=password" autocomplete="on">
        <?= $csrf ?><input type="hidden" name="action" value="password">
        <input type="hidden" name="username" autocomplete="username" value="<?= $h($me['phone10']) ?>">
        <label class="f" for="old">अहिलेको पासवर्ड · Current password</label>
        <input class="i" id="old" name="old" type="password" required autocomplete="current-password">
        <label class="f" for="pw">नयाँ पासवर्ड · New password (<?= TeamPortal::PW_MIN ?>+)</label>
        <input class="i" id="pw" name="pw" type="password" required minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password">
        <label class="f" for="pw2">फेरि लेख्नुहोस् · Again</label>
        <input class="i" id="pw2" name="pw2" type="password" required minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password">
        <button class="b" type="submit">Save · सेभ गर्नुहोस्</button>
      </form>
      <p class="tp-links"><a href="/team/">← Back</a><a href="/team/?v=forgot">पुरानो पासवर्ड बिर्सनुभयो? · Forgot it?</a></p>
    </div>
    <?php else: ?>
    <div class="tp-card">
      <div class="tp-me">
        <?php if ($pic !== null): ?><img class="tp-av" src="<?= $h($pic) ?>" alt=""<?= $kind === 'partner' ? ' style="object-fit:contain"' : '' ?>>
        <?php else: ?><span class="tp-ini"><?= $h(mb_strtoupper(mb_substr(trim($me['name']), 0, 1)) ?: '★') ?></span><?php endif; ?>
        <div style="min-width:0">
          <h1 style="margin:0">नमस्ते, <?= $h($me['name']) ?></h1>
          <?php if ($agent !== null): ?><span class="tp-chip"><?= (string) $agent['type'] === 'org' ? '🏢 Pvt. Ltd. Partner' : '👤 Agent' ?> · <?= $h($agent['ref']) ?></span><?php endif; ?>
          <?php if ($staff !== null): ?><span class="tp-chip">🧑‍✈️ <?= $h(StaffApps::roleLabel((string) $staff['role_applied'])) ?> · <?= $h($staff['ref']) ?></span><?php endif; ?>
          <?php if ($me['admin'] !== null): ?><span class="tp-chip">🔐 <?= $h(ucfirst((string) $me['admin']['role'])) ?> · <?= $h($me['admin']['username']) ?></span><?php endif; ?>
        </div>
      </div>
      <?php if ($staff !== null && (string) $staff['status'] !== 'approved'): ?><div class="tp-msg bad" style="margin:12px 0 0">तपाईंको स्टाफ स्थिति: <?= $h(StaffApps::STATUS_LABELS[(string) $staff['status']] ?? $staff['status']) ?></div><?php endif; ?>
    </div>
    <div class="tp-grid">
      <?php if ($sell !== null): ?><a class="tp-tile hot" href="<?= $h($sell) ?>"><i>🎫</i><b>टिकट बेच्नुहोस्</b><small>Sell tickets · <?= match ((string) $me['admin']['role']) { 'agent' => 'agent panel', 'counter' => 'counter', default => 'SmartBook' } ?></small></a><?php endif; ?>
      <?php if ($cards !== []): ?><a class="tp-tile" href="/team/card.php"><i>🪪</i><b>मेरो परिचयपत्र</b><small>My ID card · PNG / print</small></a><?php endif; ?>
      <?php if ($staff !== null || $agent !== null): ?><a class="tp-tile" href="/team/cv.php"><i>📄</i><b>मेरो CV</b><small><?= $kind === 'partner' ? 'Company profile' : 'Education + experience' ?></small></a><?php endif; ?>
      <?php if ($agrUrl !== null && ($staff !== null || $agent !== null)): ?><a class="tp-tile" href="<?= $h($agrUrl) ?>"><i>📝</i><b>मेरो सम्झौता</b><small>My agreement</small></a><?php endif; ?>
      <a class="tp-tile" href="/team/?v=password"><i>🔑</i><b>पासवर्ड</b><small>Change password</small></a>
      <form method="post" action="/team/" style="display:contents"><?= $csrf ?><input type="hidden" name="action" value="logout">
        <button class="tp-tile" type="submit" style="font:inherit;text-align:left;cursor:pointer"><i>🚪</i><b>बाहिर निस्कनुहोस्</b><small>Sign out</small></button></form>
    </div>
    <?php if ($sell === null && $staff !== null): ?><p class="tp-sub" style="margin-top:12px">टिकट बेच्ने खाता चाहिए कार्यालयलाई भन्नुहोस् — एउटै मोबाइल र पासवर्डले चल्छ। · Need to sell tickets? Ask the office to link a selling account to your number.</p><?php endif; ?>
    <?php endif;
    TeamUi::foot();
    exit;
}

/* ---------------- signed out ---------------- */
$reset = is_array($_SESSION['team_reset'] ?? null) ? $_SESSION['team_reset'] : null;
if ($v === 'reset' && $reset === null) {
    $v = 'forgot';
}
TeamUi::head($v === '' ? 'Sign in' : 'Password');
TeamUi::flash();
if ($err !== null) {
    echo '<div class="tp-msg bad" role="alert">' . $h($err) . '</div>';
}
// 22 Oct 2026 (review): only what the person typed (the country they picked + the digits) — nothing from our records
$masked = $reset !== null && !empty($reset['phone']) ? '+' . TeamPortal::cc($reset['cc'] ?? '91') . ' ' . substr((string) $reset['phone'], 0, 2) . '•••••' . substr((string) $reset['phone'], -3) : '';
/* the country picker (owner: India first, Nepal as an option) */
$ccPick = static function (string $sel) use ($h): string {
    $o = '';
    foreach (TeamPortal::COUNTRIES as $k => $lbl) {
        $o .= '<option value="' . $h($k) . '"' . ($k === $sel ? ' selected' : '') . '>' . $h($lbl) . '</option>';
    }
    return '<select class="i" name="cc" aria-label="Country · देश" style="width:auto;min-width:104px">' . $o . '</select>';
};
$ccNow = TeamPortal::cc($_POST['cc'] ?? ($reset['cc'] ?? '91'));

if ($v === 'forgot'): ?>
<div class="tp-card">
  <h1>🔑 पासवर्ड बनाउनुहोस् / बिर्सनुभयो?</h1>
  <p class="tp-sub">Set or reset your password. दर्ता गरेको मोबाइल नम्बर लेख्नुहोस् — WhatsApp मा ६ अङ्कको कोड आउँछ।</p>
  <form method="post" action="/team/?v=forgot">
    <?= $csrf ?><input type="hidden" name="action" value="forgot_send">
    <label class="f" for="m">मोबाइल नम्बर · Mobile number</label>
    <div style="display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px"><?= $ccPick($ccNow) ?>
    <input class="i" id="m" name="mobile" type="tel" inputmode="numeric" autocomplete="tel" required maxlength="16" placeholder="98XXXXXXXX" value="<?= $h($reset['phone'] ?? '') ?>"></div>
    <button class="b wa" type="submit">📲 WhatsApp मा कोड पठाउनुहोस् · Send code</button>
  </form>
  <p class="tp-links"><a href="/team/">← Sign in · लगइन</a></p>
</div>
<?php elseif ($v === 'reset'): ?>
<div class="tp-card">
  <h1>नयाँ पासवर्ड · New password</h1>
  <div class="tp-msg info"><?= $h($masked) ?> हाम्रो टोलीमा दर्ता भएको नम्बर हो भने त्यसको WhatsApp मा ६ अङ्कको कोड पठाइन्छ। एक मिनेटमा आएन भने तलको "hi VERIFY" प्रयोग गर्नुहोस्। · If this number is registered with us, a 6-digit code is on its way on WhatsApp. No code within a minute? Use "hi VERIFY" below.</div>
  <div class="tp-msg bad" role="note">🔒 यो कोड पासवर्ड बदल्नको लागि हो — अरूले मागे नपठाउनुहोस्। · This code is for a PASSWORD RESET — never give it or send it to anyone who asks.</div>
  <p class="tp-sub" style="margin:0 0 6px">कोड र "hi VERIFY" दुवैले भएन भने देश (+91 / +977) मिलाएर फेरि हेर्नुहोस्, नत्र कार्यालयमा फोन गर्नुहोस् — कार्यालयले तपाईंको नम्बरको देश मिलाइदिन्छ। · Neither works? Check the country (+91 / +977) and try again, or call the office to set your number's country.</p>
  <?php if (!empty($reset['debug'])): ?><div class="tp-msg info">DEV code: <?= $h($reset['debug']) ?></div><?php endif; ?>
  <div id="waOk" class="tp-msg ok" hidden>✅ नम्बर प्रमाणित भयो — कोड चाहिँदैन, नयाँ पासवर्ड राख्नुहोस् · Number proven, set your password.</div>
  <form method="post" action="/team/?v=reset" autocomplete="on">
    <?= $csrf ?><input type="hidden" name="action" value="reset">
    <input type="hidden" name="username" autocomplete="username" value="<?= $h($reset['phone']) ?>">
    <div id="codeBox">
      <label class="f" for="code">WhatsApp कोड · Code (6 digits)</label>
      <input class="i mono" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" placeholder="______">
    </div>
    <label class="f" for="pw">नयाँ पासवर्ड · New password (<?= TeamPortal::PW_MIN ?>+)</label>
    <input class="i" id="pw" name="pw" type="password" required minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password">
    <label class="f" for="pw2">फेरि लेख्नुहोस् · Again</label>
    <input class="i" id="pw2" name="pw2" type="password" required minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password">
    <button class="b" type="submit">Save &amp; sign in · सेभ गरेर भित्र जानुहोस्</button>
  </form>
</div>
<div class="tp-card" id="wa">
  <h2>कोड आएन? · No code?</h2>
  <?php if (empty($reset['wa'])): ?>
  <p class="tp-sub">आफ्नै WhatsApp बाट हामीलाई एउटा सन्देश पठाउनुहोस् — नम्बर आफैं प्रमाणित हुन्छ। · Send one message from your own WhatsApp and your number is proven.</p>
  <p class="tp-sub">यो सन्देश पासवर्ड बदल्नको लागि मात्र हो — अरू कसैले पठाउन भने नपठाउनुहोस्। अझै आएन भने कार्यालयलाई तपाईंको नम्बरमा +91 / +977 राख्न भन्नुहोस्। · It is only for a PASSWORD RESET — never send it because someone else asked. Still nothing? Ask the office to add +91 / +977 to your number.</p>
  <form method="post" action="/team/?v=reset"><?= $csrf ?><input type="hidden" name="action" value="verify_start">
    <button class="b gh" type="submit">💬 "hi VERIFY" तयार गर्नुहोस् · Prepare it</button></form>
  <?php else: ?>
  <p class="tp-sub">तलको बटन थिच्नुहोस्, WhatsApp मा <b class="mono"><?= $h($reset['wa']['text']) ?></b> लेखिएर आउँछ — <b>Send</b> थिच्नुहोस्, अनि यहाँ फर्कनुहोस्।</p>
  <p class="tp-sub"><b>🔒 यो सन्देश तपाईंको पासवर्ड बदल्नको लागि हो — अरूले मागे नपठाउनुहोस्।</b> · This message resets YOUR password — never send it because someone else asked.</p>
  <a class="b wa" id="waGo" href="<?= $h($reset['wa']['url']) ?>" target="_blank" rel="noopener">WhatsApp खोल्नुहोस् · Open WhatsApp</a>
  <p class="tp-sub" id="waWait" style="margin-top:10px">⏳ पर्खँदै … · Waiting for your message …</p>
  <script>
  (function () {
    var tok = <?= json_encode((string) $reset['wa']['token']) ?>, csrf = document.querySelector('meta[name="csrf-token"]').content, n = 0;
    function done() {
      document.getElementById('waOk').hidden = false;
      document.getElementById('codeBox').hidden = true;
      document.getElementById('waWait').textContent = '✅ प्रमाणित · Verified';
      document.getElementById('pw').focus();
    }
    function tick() {
      if (++n > 200) return;
      fetch('/api/wa-verify.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
        body: JSON.stringify({ action: 'check', token: tok }) })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.status === 'verified') return done();
          if (d && d.status === 'mismatch') document.getElementById('waWait').textContent = '❌ अर्को नम्बरबाट आयो — दर्ता गरेको नम्बरको WhatsApp बाट पठाउनुहोस्।';
          if (d && d.status === 'expired') { document.getElementById('waWait').textContent = 'पुरानो भयो — फेरि तयार गर्नुहोस्।'; return; }
          setTimeout(tick, 3000);
        }).catch(function () { setTimeout(tick, 5000); });
    }
    setTimeout(tick, 2500);
  })();
  </script>
  <?php endif; ?>
  <p class="tp-links"><a href="/team/?v=forgot">← नम्बर बदल्नुहोस् · Change number</a></p>
</div>
<?php else: ?>
<div class="tp-card">
  <h1>🔐 टिम लगइन · Team sign in</h1>
  <p class="tp-sub">प्रा. लि. साझेदार, एजेन्ट र S Hari कर्मचारी — दर्ता गरेको मोबाइल र पासवर्ड। · Partners, agents and staff: your registered mobile and password.</p>
  <form method="post" action="/team/" autocomplete="on">
    <?= $csrf ?><input type="hidden" name="action" value="login">
    <label class="f" for="m">मोबाइल नम्बर · Mobile number</label>
    <div style="display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px"><?= $ccPick($ccNow) ?>
    <input class="i" id="m" name="mobile" type="tel" inputmode="numeric" autocomplete="username" required maxlength="16" placeholder="98XXXXXXXX" value="<?= $h($_POST['mobile'] ?? '') ?>"></div>
    <label class="f" for="pw">पासवर्ड · Password</label>
    <input class="i" id="pw" name="password" type="password" required autocomplete="current-password">
    <button class="b" type="submit">भित्र जानुहोस् · Sign in</button>
  </form>
  <a class="b gh" href="/team/?v=forgot">🔑 पासवर्ड बनाउनुहोस् / बिर्सनुभयो? · Set / forgot password</a>
  <p class="tp-links">
    <a href="/agreements/apply.html">✍️ एजेन्ट बन्नुहोस्</a>
    <a href="/agreements/partner-agreement-ne.html">🏢 साझेदार बन्नुहोस्</a>
    <a href="/agreements/staff.html">🧑‍✈️ स्टाफ फारम</a>
  </p>
  <p class="tp-links" style="font-weight:400"><a href="/admin/login.php?portal=admin">Office login (admin)</a></p>
</div>
<?php endif;
TeamUi::foot();
