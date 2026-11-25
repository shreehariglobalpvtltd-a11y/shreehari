<?php
/**
 * admin/_easy-door.php - the EASY door (25 Nov 2026), included by admin/login.php.
 * Mode (Agent / Admin / Staff / Counter / Driver-Conductor) -> code or mobile -> password.
 * Nothing else. See includes/easylogin.php for the rules. The old doors stay at ?classic=1.
 */
declare(strict_types=1);

if (!defined('SHG_APP')) {
    http_response_code(403);
    exit('Forbidden');
}
require_once INCLUDE_PATH . '/easylogin.php';

$h     = static fn($s): string => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$err   = '';
$mode  = (string) ($_POST['emode'] ?? ($_GET['as'] ?? ''));
$mode  = in_array($mode, EasyLogin::MODES, true) ? $mode : (string) ($_COOKIE['shg_easy_mode'] ?? 'agent');
$mode  = in_array($mode, EasyLogin::MODES, true) ? $mode : 'agent';
$user  = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $user = trim((string) ($_POST['euser'] ?? ''));
    if (!Security::verifyCsrf()) {
        $err = 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · This page was open too long, please try again.';
    } else {
        $r = EasyLogin::attempt($mode, $user, (string) ($_POST['epass'] ?? ''));
        if (!empty($r['ok'])) {
            if (!headers_sent()) {
                setcookie('shg_easy_mode', $mode, ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax']);
            }
            if ($mode !== 'driver' && Auth::admin() === null && Auth::deviceHold() !== null) {
                Response::redirect('admin/login.php?step=devices');   // the device-limit step (old door) - only when the office set a limit
            }
            $go = (string) $r['redirect'];
            if ($mode !== 'driver' && isset($safeNext) && $safeNext !== 'index.php') {
                $go = '/admin/' . $safeNext;   // a deep link still wins
            }
            header('Location: ' . $go, true, 303);
            exit;
        }
        $err = (string) ($r['error'] ?? 'मिलेन · Did not match.');
    }
}

$modes = [
    'agent'   => ['🧑‍💼', 'एजेन्ट', 'Agent', 'कोड (SHG001 = 1) वा मोबाइल'],
    'admin'   => ['🏢', 'एडमिन', 'Admin', 'username, कोड वा मोबाइल'],
    'staff'   => ['👷', 'स्टाफ', 'Staff', 'स्टाफ कोड (SHG-S-0003 = 3) वा मोबाइल'],
    'counter' => ['🎫', 'काउन्टर', 'Counter', 'कोड वा मोबाइल'],
    'driver'  => ['🚌', 'चालक / कन्डक्टर', 'Driver', 'मोबाइल वा चालक नम्बर'],
];
$csrf = Security::csrfField();
?><!doctype html>
<html lang="ne"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex">
<title>लगइन · Sign in — S Hari Global</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;font-family:Poppins,system-ui,-apple-system,"Segoe UI",sans-serif;background:radial-gradient(120% 80% at 50% 0%,#1d3f86 0%,#0b1f4d 60%,#071636 100%);color:#13244A;display:flex;align-items:flex-start;justify-content:center;padding:max(18px,env(safe-area-inset-top)) 14px 28px}
.card{width:100%;max-width:420px;background:#fff;border-radius:22px;padding:20px 18px 18px;box-shadow:0 24px 60px rgba(0,0,0,.35);margin-top:4vh}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:14px}
.brand img{width:62px;height:auto}
.brand b{display:block;font-size:17px;color:#0C306C;letter-spacing:.3px}
.brand small{display:block;font-size:12px;color:#5a6a8a}
h1{font-size:17px;margin:4px 0 10px}
.modes{display:grid;grid-template-columns:repeat(auto-fit,minmax(118px,1fr));gap:8px;margin-bottom:14px}
.modes label{position:relative;display:flex;align-items:center;gap:8px;min-height:52px;padding:8px 10px;border:2px solid #dbe3f1;border-radius:14px;background:#f6f8fd;cursor:pointer;font-weight:700;font-size:14px;line-height:1.2}
.modes label small{display:block;font-weight:600;font-size:11px;color:#5a6a8a}
.modes input{position:absolute;opacity:0;pointer-events:none}
.modes label:has(input:checked){border-color:#1F4FA3;background:#eaf1ff;box-shadow:0 0 0 3px rgba(31,79,163,.15)}
.modes .ic{font-size:20px}
.f{display:block;margin:0 0 12px}
.f span{display:block;font-size:13px;font-weight:700;margin:0 0 5px}
.f input{width:100%;min-height:52px;border:2px solid #dbe3f1;border-radius:14px;padding:0 14px;font-size:18px;font-family:inherit;color:#0f2550;background:#fff}
.f input:focus{outline:none;border-color:#1F4FA3;box-shadow:0 0 0 3px rgba(31,79,163,.15)}
.pw{position:relative}
.pw button{position:absolute;right:6px;top:6px;height:40px;min-width:44px;border:0;border-radius:10px;background:#eef3fb;font-size:16px;cursor:pointer}
.go{width:100%;min-height:54px;border:0;border-radius:14px;background:linear-gradient(135deg,#1F4FA3,#0C306C);color:#fff;font:800 17px/1 inherit;font-family:inherit;cursor:pointer;margin-top:2px}
.err{margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#fdecec;color:#9b1c1c;font-size:13.5px;font-weight:600}
.hint{font-size:12px;color:#5a6a8a;margin:-6px 0 12px}
.foot{display:flex;justify-content:space-between;gap:10px;margin-top:14px;font-size:12.5px}
.foot a{color:#1F4FA3;text-decoration:none;font-weight:600}
</style>
</head>
<body>
<main class="card">
  <div class="brand"><img src="/assets/img/logo-mark-tm.webp" alt="" width="62" height="32"><div><b>S HARI GLOBAL</b><small>कर्मचारी लगइन · Staff sign in</small></div></div>
  <h1>को हुनुहुन्छ? · Who are you?</h1>
  <?php if ($err !== ''): ?><p class="err" role="alert"><?= $h($err) ?></p><?php endif; ?>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $csrf ?>
    <input type="hidden" name="door" value="easy">
    <?php if (isset($safeNext) && $safeNext !== 'index.php'): ?><input type="hidden" name="next" value="<?= $h($safeNext) ?>"><?php endif; ?>
    <div class="modes" role="radiogroup" aria-label="Mode">
      <?php foreach ($modes as $k => [$ic, $ne, $en, $hint]): ?>
      <label><input type="radio" name="emode" value="<?= $h($k) ?>" data-hint="<?= $h($hint) ?>"<?= $k === $mode ? ' checked' : '' ?>><span class="ic" aria-hidden="true"><?= $ic ?></span><span><?= $h($ne) ?><small><?= $h($en) ?></small></span></label>
      <?php endforeach; ?>
    </div>
    <label class="f"><span>कोड वा मोबाइल · Code or mobile</span>
      <input name="euser" id="euser" value="<?= $h($user) ?>" required autocomplete="username" autocapitalize="characters" inputmode="text" placeholder="1  ·  98XXXXXXXX"></label>
    <p class="hint" id="ehint"><?= $h($modes[$mode][3]) ?></p>
    <label class="f"><span>पासवर्ड · Password</span>
      <span class="pw"><input name="epass" id="epass" type="password" required autocomplete="current-password" placeholder="••••••••"><button type="button" id="eshow" aria-label="पासवर्ड देखाउनुहोस् · Show password">👁</button></span></label>
    <button class="go" type="submit">भित्र जानुहोस् · Sign in</button>
  </form>
  <div class="foot">
    <span>पासवर्ड बिर्सनुभयो? कार्यालयलाई रिसेट गर्न भन्नुहोस्।</span>
    <a href="/admin/login.php?classic=1">पुरानो तरिका</a>
  </div>
</main>
<script>
(function () {
  var hint = document.getElementById('ehint'), u = document.getElementById('euser');
  document.querySelectorAll('input[name=emode]').forEach(function (r) {
    r.addEventListener('change', function () { if (hint) hint.textContent = r.getAttribute('data-hint') || ''; if (u) u.focus(); });
  });
  var b = document.getElementById('eshow'), p = document.getElementById('epass');
  if (b && p) b.addEventListener('click', function () { p.type = p.type === 'password' ? 'text' : 'password'; });
})();
</script>
</body></html>
