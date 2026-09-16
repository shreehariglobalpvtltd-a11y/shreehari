<?php
/**
 * admin/login.php — staff sign-in, presented as one of two portals.
 *
 * The role gate in the customer app (RoleGate, app.template.html) asks which
 * portal you want and then sends you here with ?portal=agent or ?portal=admin.
 * That choice is cosmetic-with-intent: it brands this page so a counter agent
 * knows they are in the right place, and it decides where an ambiguous sign-in
 * lands. It is NEVER an authorisation input — what you may do is decided
 * entirely by the account you sign in with, server-side, exactly as before.
 * Arriving with no portal (a bookmark, a deep link) still works and simply
 * shows the neutral staff branding.
 */
declare(strict_types=1);

define('SHG_APP', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

$base = rtrim(APP_URL, '/');

// A "next" page to return to after signing in (e.g. a deep link from the
// legacy in-app admin mode straight to a specific pending payment).
// Restricted to a bare "<file>.php[?query]" inside admin/ — never a full
// URL or another host, so this can't become an open redirect.
$next     = (string) ($_GET['next'] ?? ($_POST['next'] ?? ''));
$safeNext = (preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next) === 1) ? $next : 'index.php';

/* ---- Which portal was chosen ---------------------------------------
   Whitelisted, because it is echoed back into the page and carried through
   the form post. Anything unrecognised degrades to the neutral branding. */
$portal = strtolower(trim((string) ($_GET['portal'] ?? ($_POST['portal'] ?? ''))));
if ($portal !== 'agent' && $portal !== 'admin') {
    $portal = '';
}
/* One login door (3 Sep 2026): a deep link into the agent's own pages
   (agent.php, agent-sales.php, ...) — the launcher in the customer app and
   Auth::requireAdmin() both arrive here without a portal — means an agent
   is knocking, so brand the page for them and open the code + OTP form
   rather than the username/password one. Still cosmetic: the account
   decides what the sign-in may do. */
if ($portal === '' && preg_match('/^agent[a-z\-]*\.php/i', $safeNext) === 1) {
    $portal = 'agent';
}

$PORTALS = [
    'agent' => [
        'icon'   => '🎟️',
        'title'  => 'Agent Portal',
        'sub'    => 'Counter sales, your collection &amp; commission',
        'sky'    => '#0C3B2A', 'sea' => '#178A50', 'accent' => '#F07C1F',
    ],
    'admin' => [
        'icon'   => '👑',
        'title'  => 'Admin Portal',
        'sub'    => 'Full control centre — revenue, fleet &amp; staff',
        'sky'    => '#12264E', 'sea' => '#2E5FA8', 'accent' => '#F07C1F',
    ],
];
$skin = $PORTALS[$portal] ?? [
    'icon'   => '🚌',
    'title'  => '',                       // falls back to the company name
    'sub'    => 'Staff administration',
    'sky'    => '#12264E', 'sea' => '#2E5FA8', 'accent' => '#F07C1F',
];

/**
 * Where a freshly signed-in staff member belongs.
 *
 * A deep link always wins — someone who clicked through to a specific
 * booking meant to go there. Otherwise a counter agent lands on their own
 * dashboard rather than the company one, which is also what admin/index.php
 * does when an agent reaches it, so the two agree.
 *
 * The chosen portal deliberately does NOT override this: picking "Admin"
 * cannot get an agent account onto the finance dashboard.
 */
function login_destination(string $safeNext): string
{
    if ($safeNext !== 'index.php') {
        return $safeNext;
    }

    return match ((string) (Auth::admin()['role'] ?? '')) {
        'agent'   => 'agent.php',
        // Counter staff have no dashboard.view — their day starts at the
        // tickets register (search by PNR / phone / name).
        'counter' => 'bookings.php',
        default   => 'index.php',
    };
}

// Already signed in? Go straight to the destination.
if (Auth::isAdmin()) {
    Response::redirect('admin/' . login_destination($safeNext));
}

$error  = '';
$notice = '';

/* ---- Agent flow state -----------------------------------------------
   4 Sep 2026: the agent portal signs in with EMAIL + USERNAME + PASSWORD —
   self-contained, no WhatsApp / SMS gateway in the loop (owner ask). The
   office sets and resets all three from Admin → Agents → Manage → Login
   credentials. The older code + mobile + WhatsApp-OTP door is kept one
   click away (?auth=otp) for agents who have not been given an email yet,
   and ?auth=pw still shows the plain office username/password form. The
   portal value stays cosmetic — the role comes from the account, enforced
   in Auth::agentPasswordLogin / Auth::agentLoginVerify. */
$authRaw     = (string) ($_GET['auth'] ?? ($_POST['auth'] ?? ''));
$authPw      = $authRaw === 'pw';
$authOtp     = $authRaw === 'otp';
$agentFlow   = ($portal === 'agent') && $authOtp;            // code + mobile + OTP
$agentPw     = ($portal === 'agent') && !$authOtp && !$authPw; // email + username + password (default)
$step        = 'start';                       // 'start' | 'otp'
$mode        = (string) ($_POST['mode'] ?? '');
$agentCode   = Security::clean((string) ($_POST['agent_code'] ?? ''), 12);
$agentMobile = Security::clean((string) ($_POST['mobile'] ?? ''), 20);
$agentEmail  = Security::clean((string) ($_POST['email'] ?? ''), 191);
$agentUser   = Security::clean((string) ($_POST['username'] ?? ''), 60);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($mode === 'agent_pw') {
        // Agent portal, default door: all three must match one active agent.
        $result = Auth::agentPasswordLogin($agentEmail, $agentUser, (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            Response::redirect('admin/' . login_destination($safeNext));
        }
        $error = $result['error'] ?? 'Sign in failed.';
    } elseif ($mode === 'agent_request') {
        $result = Auth::agentLoginStart($agentCode, $agentMobile);
        if ($result['ok']) {
            $step   = 'otp';
            $notice = 'Code sent to your WhatsApp' . (Settings::getBool('sms_send_otp', false) ? ' / SMS' : '') . '. Enter it below.';
        } else {
            $error = $result['error'] ?? 'Could not send the code.';
        }
    } elseif ($mode === 'agent_verify') {
        $result = Auth::agentLoginVerify($agentCode, $agentMobile, (string) ($_POST['otp'] ?? ''));
        if ($result['ok']) {
            Response::redirect('admin/' . login_destination($safeNext));
        }
        $step  = 'otp';
        $error = $result['error'] ?? 'That code is not right.';
    } elseif ($mode === 'admin_2fa_verify') {
        // Admin second factor, step 2 (opt-in 2FA). Identity is held in the
        // session pending marker set by adminLogin(); only the code is posted.
        $result = Auth::adminLogin2faVerify((string) ($_POST['otp'] ?? ''));
        if ($result['ok']) {
            Response::redirect('admin/' . login_destination($safeNext));
        }
        if (!empty($result['twofa'])) {
            $step  = 'admin_otp';
            $error = $result['error'] ?? 'That code is not right.';
        } else {
            // Pending step expired — send them back to username + password.
            $error = $result['error'] ?? 'Please sign in again.';
        }
    } elseif ($mode === 'admin_2fa_resend') {
        $result = Auth::adminLogin2faResend();
        if (!empty($result['ok'])) {
            $step   = 'admin_otp';
            $notice = 'A new code has been sent to your registered mobile.';
        } elseif (!empty($_SESSION['admin_2fa_pending'])) {
            $step  = 'admin_otp';
            $error = $result['error'] ?? 'Could not resend the code.';
        } else {
            $error = $result['error'] ?? 'Please sign in again.';
        }
    } else {
        $result = Auth::adminLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
        if ($result['ok']) {
            Response::redirect('admin/' . login_destination($safeNext));
        } elseif (!empty($result['twofa'])) {
            // Correct password, but this account has opted into 2FA — hold for
            // the code just sent to their registered mobile.
            $step   = 'admin_otp';
            $notice = 'Enter the one-time code sent to your registered mobile'
                    . (Settings::getBool('sms_send_otp', false) ? ' (WhatsApp / SMS).' : ' on WhatsApp.');
        } else {
            $error = $result['error'] ?? 'Sign in failed.';
        }
    }
}

$company = Settings::getString('company_name', APP_NAME);
$csrf    = Security::e(Security::csrfToken());
$csrfKey = CSRF_TOKEN_NAME;
$heading = $skin['title'] !== '' ? $skin['title'] : Security::e($company);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $skin['title'] !== '' ? Security::e($skin['title']) : 'Sign in' ?> · <?= Security::e($company) ?></title>
<meta name="robots" content="noindex,nofollow">
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;
     font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
     background:linear-gradient(135deg,<?= $skin['sky'] ?>,<?= $skin['sea'] ?>);
     padding:20px}
.box{background:#fff;width:100%;max-width:380px;padding:34px 30px;border-radius:18px;
     box-shadow:0 24px 60px rgba(0,0,0,.35)}
.logo{text-align:center;line-height:1;margin-bottom:4px}
.logo img{width:72px;height:72px;object-fit:contain;filter:drop-shadow(0 6px 16px rgba(18,38,78,.28))}
h1{font-size:19px;text-align:center;margin:10px 0 2px;color:<?= $skin['sky'] ?>}
.sub{text-align:center;color:#6b7688;font-size:13px;margin-bottom:22px}
.who{text-align:center;font-size:12px;color:#8a94a6;margin-bottom:18px}
label{display:block;font-size:13px;font-weight:700;color:#1b2436;margin:14px 0 6px}
input{width:100%;padding:13px 14px;border:1px solid #dde3ee;border-radius:10px;font-size:16px;min-height:48px}
input:focus{outline:none;border-color:<?= $skin['sea'] ?>;box-shadow:0 0 0 3px rgba(46,95,168,.15)}
button{width:100%;margin-top:22px;padding:14px;border:0;border-radius:10px;background:<?= $skin['accent'] ?>;color:#fff;
       font-weight:800;font-size:15px;cursor:pointer;min-height:50px}
button:hover{filter:brightness(1.05)}
.err{background:#f7dcdc;color:#8a1f1f;padding:11px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:8px}
.ok{background:#def4e4;color:#14602e;padding:11px 14px;border-radius:9px;font-size:13px;font-weight:600;margin-bottom:8px}
button.alt{background:none;color:<?= $skin['sea'] ?>;margin-top:10px;min-height:38px;padding:8px;font-weight:700;font-size:13px}
/* Role chooser — shown only on the neutral door (no ?portal), so every
   existing ?portal=… URL renders exactly as before. */
.choose{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:2px 0 6px}
.ch{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-height:82px;padding:10px 6px;border:1.5px solid #dde3ee;border-radius:12px;text-decoration:none;color:#1b2436;background:#fff;transition:border-color .15s,transform .15s}
.ch span{font-size:22px;line-height:1}
.ch b{font-size:13px}
.ch small{font-size:10.5px;color:#6b7688;text-align:center;line-height:1.25}
.ch:hover,.ch:focus{border-color:<?= $skin['sea'] ?>;transform:translateY(-1px)}
.ch-agent:hover,.ch-agent:focus{border-color:#178A50}
.or{text-align:center;font-size:11.5px;color:#8a94a6;margin:14px 0 2px;display:flex;align-items:center;gap:10px}
.or::before,.or::after{content:'';flex:1;border-top:1px solid #e8ecf4}
.foot{text-align:center;margin-top:18px;font-size:12px;color:#9aa4b5;display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
.foot a{color:<?= $skin['sea'] ?>;text-decoration:none}
.ceo-strip{display:flex;align-items:center;gap:10px;margin:14px 0 18px;padding:10px 14px;border-radius:12px;background:#F8F9FB;border:1px solid #E8ECF4}
.ceo-strip img{width:44px;height:44px;border-radius:50%;object-fit:cover;object-position:center top;border:2px solid #F07C1F;box-shadow:0 3px 10px rgba(0,0,0,.16);flex:0 0 auto}
.ceo-strip div{line-height:1.4}
.ceo-strip b{display:block;font-size:13px;color:#12264E;font-weight:700}
.ceo-strip span{font-size:11.5px;color:#8794AB;font-weight:600}
</style></head>
<body>
<form class="box" method="post" autocomplete="off">
  <div class="logo"><img src="/assets/img/logo.png" alt="<?= Security::e($company) ?>" width="72" height="72"></div>
  <h1><?= $heading ?></h1>
  <div class="sub"><?= $skin['sub'] ?></div>
  <div class="ceo-strip">
    <img src="/assets/img/ceo.jpg" alt="CEO" onerror="this.style.display='none'">
    <div><b>Sher Bahadur Bishwokarma</b><span>👑 Founder &amp; CEO · Director</span></div>
  </div>
  <?php if ($portal !== ''): ?>
    <div class="who"><?= Security::e($company) ?> · staff sign-in</div>
  <?php endif; ?>
  <?php if ($portal === '' && $step === 'start'): ?>
    <!-- One login system: pick who you are. Customer goes back to the
         website (phone + OTP lives there); Agent -> code + OTP; Admin ->
         username + password. The neutral form below stays for bookmarks. -->
    <div class="choose" role="navigation" aria-label="Choose sign-in">
      <a class="ch ch-customer" href="<?= $base ?>/#/my"><span>🎫</span><b>Customer · यात्री</b><small>Book &amp; My Bookings<br>(phone + OTP)</small></a>
      <a class="ch ch-agent" href="?portal=agent<?= $next !== '' ? '&amp;next=' . urlencode($next) : '' ?>"><span>🎟️</span><b>Agent · एजेन्ट</b><small>Email + username<br>+ password</small></a>
      <a class="ch ch-admin" href="?portal=admin<?= $next !== '' ? '&amp;next=' . urlencode($next) : '' ?>"><span>👑</span><b>Admin · अफिस</b><small>Username +<br>password</small></a>
    </div>
    <div class="or">or sign in with username &amp; password</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="err"><?= Security::e($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="ok"><?= Security::e($notice) ?></div><?php endif; ?>
  <input type="hidden" name="<?= $csrfKey ?>" value="<?= $csrf ?>">
  <input type="hidden" name="next" value="<?= Security::e($next) ?>">
  <input type="hidden" name="portal" value="<?= Security::e($portal) ?>">

  <?php if ($step === 'admin_otp'): ?>
    <!-- Admin flow · step 2: opt-in second factor (password already verified) -->
    <input type="hidden" name="mode" value="admin_2fa_verify">
    <label for="aotp">One-time code</label>
    <input id="aotp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="8" required autofocus
           autocomplete="one-time-code" placeholder="6-digit code">
    <button type="submit">Verify &amp; sign in</button>
    <button type="submit" class="alt" formnovalidate name="mode" value="admin_2fa_resend">Resend code</button>
  <?php elseif ($agentFlow && $step === 'otp'): ?>
    <!-- Agent flow · step 2: the OTP just sent to the registered mobile -->
    <input type="hidden" name="mode" value="agent_verify">
    <input type="hidden" name="auth" value="otp">
    <input type="hidden" name="agent_code" value="<?= Security::e($agentCode) ?>">
    <input type="hidden" name="mobile" value="<?= Security::e($agentMobile) ?>">
    <label for="otp">One-time code (WhatsApp)</label>
    <input id="otp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="8" required autofocus
           autocomplete="one-time-code" placeholder="6-digit code">
    <button type="submit">Verify &amp; sign in</button>
    <button type="submit" class="alt" formnovalidate
            name="mode" value="agent_request">Resend code</button>
  <?php elseif ($agentPw): ?>
    <!-- Agent portal · default door (4 Sep 2026): email + username + password.
         Self-contained — no OTP, no WhatsApp. All three must match. -->
    <input type="hidden" name="mode" value="agent_pw">
    <label for="ae">Email</label>
    <input id="ae" name="email" type="email" required autofocus autocapitalize="none" autocorrect="off"
           autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?= Security::e($agentEmail) ?>">
    <label for="au">Username</label>
    <input id="au" name="username" required autocapitalize="none" autocorrect="off"
           autocomplete="username" placeholder="your agent username" value="<?= Security::e($agentUser) ?>">
    <label for="ap">Password</label>
    <input id="ap" name="password" type="password" required autocomplete="current-password">
    <button type="submit">Sign in to Agent Portal</button>
    <div class="who" style="margin:12px 0 0">
      Forgot your details? The office can view or reset your email, username and password.<br>
      <b>No email yet?</b> <a href="?portal=agent&amp;auth=otp<?= $next !== '' ? '&amp;next=' . urlencode($next) : '' ?>">Sign in with your SHG code + WhatsApp code</a>.
    </div>
  <?php elseif ($agentFlow): ?>
    <!-- Agent flow · step 1: agent code + registered mobile -->
    <input type="hidden" name="auth" value="otp">
    <input type="hidden" name="mode" value="agent_request">
    <label for="ac">Agent code</label>
    <input id="ac" name="agent_code" required autofocus inputmode="numeric"
           placeholder="e.g. 027 or SHG-027" value="<?= Security::e($agentCode) ?>">
    <label for="mob">Registered mobile number</label>
    <input id="mob" name="mobile" type="tel" required inputmode="tel"
           placeholder="10-digit mobile" value="<?= Security::e($agentMobile) ?>">
    <button type="submit">Send WhatsApp code</button>
  <?php else: ?>
    <label for="u">Username</label>
    <input id="u" name="username" required autofocus autocapitalize="none" autocorrect="off"
           value="<?= Security::e($_POST['username'] ?? '') ?>">
    <label for="p">Password</label>
    <input id="p" name="password" type="password" required>
    <?php if ($authPw): ?><input type="hidden" name="auth" value="pw"><?php endif; ?>
    <button type="submit">Sign in<?= $skin['title'] !== '' ? ' to ' . Security::e($skin['title']) : '' ?></button>
  <?php endif; ?>

  <div class="foot">
    <a href="<?= $base ?>/">← Back to website</a>
    <?php if ($portal === 'agent' && $agentPw): ?>
      <a href="?portal=agent&amp;auth=otp<?= $next !== '' ? '&amp;next=' . urlencode($next) : '' ?>">WhatsApp code instead</a>
    <?php elseif ($portal === 'agent'): ?>
      <a href="?portal=agent<?= $next !== '' ? '&amp;next=' . urlencode($next) : '' ?>">Email + password sign-in</a>
    <?php endif; ?>
    <?php if ($portal !== ''): ?>
      <a href="login.php<?= $next !== '' ? '?next=' . urlencode($next) : '' ?>">Switch portal</a>
    <?php endif; ?>
  </div>
  <div class="foot" style="justify-content:center;margin-top:10px;font-size:13px">
    <span>Help / locked out? &nbsp;<b>📞 <a href="tel:+919104801507" style="white-space:nowrap">+91 91048 01507</a></b> — Mehsana Office</span>
  </div>
</form>
</body></html>
