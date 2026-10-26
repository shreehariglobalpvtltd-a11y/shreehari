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
require_once INCLUDE_PATH . '/passkeys.php';
require_once INCLUDE_PATH . '/brand.php';   // the emblem (no baked name, no white tile) — 9 Oct 2026

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
        // A scanner holds tickets.scan only and an official payments.* only:
        // neither may open index.php (dashboard.view), so sending them there
        // greeted them with a 403 the moment they signed in.
        'scanner'  => 'scan.php',
        'official' => 'payments.php',
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
/* 9 Oct 2026 (site facts): the CEO line and the help number are Admin's
   (Company details), not typed into this page. */
$ceoName   = trim(Settings::getString('company_ceo', '')) !== '' ? trim(Settings::getString('company_ceo', '')) : (string) Settings::company()['operator'];
$helpPhone = Settings::officePhone();
$helpTel   = CounterDesk::dialDigits($helpPhone, 'IN') ?: Settings::officeWhatsApp();
$csrf    = Security::e(Security::csrfToken());
$csrfKey = CSRF_TOKEN_NAME;
$heading = $skin['title'] !== '' ? $skin['title'] : Security::e($company);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $skin['title'] !== '' ? Security::e($skin['title']) : 'Sign in' ?> · <?= Security::e($company) ?></title>
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= $csrf ?>">
<?= Brand::iconLinks() ?>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;
     font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
     background:linear-gradient(135deg,<?= $skin['sky'] ?>,<?= $skin['sea'] ?>);
     padding:20px}
.box{background:#fff;width:100%;max-width:380px;padding:34px 30px;border-radius:18px;
     box-shadow:0 24px 60px rgba(0,0,0,.35)}
.logo{text-align:center;line-height:1;margin-bottom:4px}
.logo img{height:68px;width:auto;max-width:160px;object-fit:contain;background:none;filter:drop-shadow(0 6px 14px rgba(18,38,78,.26))}
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
/* Oct 2026 redesign: one brand on every door — the design-system light values
   (navy ground with the red rule, royal action, 1.5px control borders, a
   visible focus ring). The per-portal skin still sets the heading text. */
body{background:#0A2A8F !important;background-image:radial-gradient(90% 60% at 20% 0%,#1F4FD8 0%,rgba(31,79,216,0) 60%),linear-gradient(160deg,#0A2A8F 0%,#1036B4 60%,#1846CC 100%) !important;background-attachment:fixed !important;border-top:3px solid #F07800}
.box{box-shadow:0 14px 34px rgba(0,0,0,.28);border-radius:22px}
h1{color:#16233C}
.sub,.who,.ch small{color:#5C6B85}
input{border:1.5px solid #7D8AA0}
input:focus{border-color:#0054A8;box-shadow:0 0 0 3px #D9E7FA}
button:not(.alt){background:#0054A8 !important}
button:not(.alt):hover{background:#003C90 !important;filter:none}
button.alt,.foot a{color:#0054A8}
.ch{border-color:#D5DCE8}
.ch:hover,.ch:focus,.ch-agent:hover,.ch-agent:focus{border-color:#0054A8;transform:none}
.ceo-strip img{border-color:#0054A8}
:focus-visible{outline:3px solid #0054A8;outline-offset:2px}
/* 4 Oct 2026: the pop-out depth of the rest of the app (assets/css/depth.css):
   the card is a raised slab, the button sits on a royal edge and travels
   into it when pressed, inputs are inset wells. Follows the saved panel
   display (light / dark / AMOLED black). */
.box{border:1px solid #D5DEEB;border-top-color:#fff;box-shadow:inset 0 1px 0 #fff,0 2px 0 #C4CFE1,0 4px 0 #A8B6CE,0 7px 0 #8B9CBB,0 8px 0 #7B8DAE,0 20px 30px -6px rgba(0,0,0,.55)}
input{background:#F6F8FC;box-shadow:inset 0 2px 5px rgba(12,48,108,.14),inset 0 1px 0 rgba(12,48,108,.10)}
button:not(.alt){background:linear-gradient(180deg,#1F6CC8,#0054A8 58%,#004B98) !important;border:1px solid #3D82D6;box-shadow:inset 0 1px 0 rgba(255,255,255,.4),0 2px 0 #003C90,0 4px 0 #00347F,0 6px 0 #002B6A,0 13px 18px -5px rgba(0,52,130,.55);transition:transform .14s ease-out,box-shadow .14s ease-out;touch-action:manipulation}
button:not(.alt):active{transform:translateY(3px);box-shadow:inset 0 3px 7px rgba(0,24,70,.35),0 1px 0 #003C90,0 3px 0 #002B6A}
.ch{box-shadow:inset 0 1px 0 #fff,0 2px 0 #CCD6E5,0 3px 0 #B9C6DA,0 7px 9px -5px rgba(12,48,108,.28)}
.ch:active{transform:translateY(2px)}
:root[data-theme="dark"]{color-scheme:dark}
:root[data-theme="dark"] body{background:#0B1220 !important}
:root[data-display="amoled"] body{background:#000 !important}
:root[data-theme="dark"] .box{background:linear-gradient(160deg,#1C2E4D,#111D33 60%);color:#E8F0FB;border-color:#24375A;border-top-color:#4D6694;box-shadow:inset 0 1px 0 rgba(165,195,245,.24),0 2px 0 #1C2B47,0 4px 0 #16233B,0 6px 0 #101B2F,0 8px 0 #0A1323,0 20px 30px -6px #000}
:root[data-display="amoled"] .box{background:linear-gradient(150deg,#213146,#101822 60%);border-color:#33465F;border-top-color:#8A9DB9;box-shadow:inset 0 1px 0 rgba(183,197,220,.25),0 2px 0 #34465F,0 4px 0 #24344A,0 8px 0 #152032,0 20px 25px #000}
:root[data-theme="dark"] h1,:root[data-theme="dark"] label,:root[data-theme="dark"] .ch,:root[data-theme="dark"] .ceo-strip b{color:#E8F0FB}
:root[data-theme="dark"] .sub,:root[data-theme="dark"] .who,:root[data-theme="dark"] .ch small,:root[data-theme="dark"] .ceo-strip span{color:#ACBBD0}
:root[data-theme="dark"] input{background:#080C13;color:#EEF4FF;border-color:#607697;box-shadow:inset 0 3px 7px #000}
:root[data-theme="dark"] .ch,:root[data-theme="dark"] .ceo-strip{background:#15243E;border-color:#3A5178;box-shadow:inset 0 1px 0 rgba(165,195,245,.18),0 2px 0 #1B2A45,0 3px 0 #111D33,0 8px 10px -5px #000}
:root[data-theme="dark"] button.alt,:root[data-theme="dark"] .foot a{color:#9ABFFF}
:root[data-theme="dark"] .err{background:#30161B;color:#FFADB9}
:root[data-theme="dark"] .ok{background:#0F271B;color:#8CE4B8}
/* 9 Oct 2026: the emblem has no lettering and no tile any more - on a dark card it
   sits straight on the card with a soft blue glow, exactly like the app bar */
:root[data-theme="dark"] .logo img{background:none;border-radius:0;padding:0;box-shadow:none;filter:drop-shadow(0 0 16px rgba(120,170,255,.30)) drop-shadow(0 6px 10px rgba(0,0,0,.55))}
@media (prefers-reduced-motion:reduce){button:not(.alt):active,.ch:active{transform:none}}
/* 23 Oct 2026: the no-password doors (see $doors) */
.doors{margin:4px 0 0}
.doors label{margin-top:10px}
.cc-row{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px}
.cc-row select{min-height:48px;border:1.5px solid #7D8AA0;border-radius:10px;padding:0 8px;font-size:15px;background:#F6F8FC}
button.alt.door{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:10px;min-height:50px;padding:0 14px;border-radius:12px;font-size:14.5px;font-weight:800;color:#fff;border:0;box-shadow:0 4px 0 rgba(0,0,0,.25)}
button.alt.door:active{transform:translateY(2px);box-shadow:0 2px 0 rgba(0,0,0,.25)}
button.alt.d-pk{background:linear-gradient(120deg,#ffa24d,#f07a1e 55%,#d4570c)}
button.alt.d-face{background:linear-gradient(120deg,#e8457c,#7b5cff 60%,#2f6bff)}
button.alt.d-facts{background:#fff;color:#0054A8;border:1.5px dashed #0054A8;box-shadow:none}
button.alt.d-go{background:linear-gradient(180deg,#1F6CC8,#0054A8 58%,#004B98)}
#factsBox{margin-top:10px;padding:10px 12px;border:1.5px solid #D5DCE8;border-radius:12px}
#factsBox[hidden]{display:none}
.note{font-size:12px;color:#6b7688;margin:10px 0 0;line-height:1.4}
.note.bad{background:#f7dcdc;color:#8a1f1f;padding:10px 12px;border-radius:9px;font-weight:600}
.note.info{background:#def4e4;color:#14602e;padding:10px 12px;border-radius:9px;font-weight:600}
details.pwd{margin-top:14px;border:1.5px dashed #D5DCE8;border-radius:12px;padding:0 12px}
details.pwd>summary{cursor:pointer;list-style:none;padding:12px 0;font-weight:800;font-size:14px;color:#0054A8}
details.pwd>summary::-webkit-details-marker{display:none}
details.pwd[open]{padding-bottom:12px}
details.pwd label:first-of-type{margin-top:4px}
:root[data-theme="dark"] .cc-row select,:root[data-theme="dark"] #factsBox{background:#080C13;color:#EEF4FF;border-color:#607697}
:root[data-theme="dark"] button.alt.d-facts{background:#15243E;color:#9ABFFF;border-color:#9ABFFF}
:root[data-theme="dark"] .note{color:#ACBBD0}
:root[data-theme="dark"] details.pwd{border-color:#3A5178}
:root[data-theme="dark"] details.pwd>summary{color:#9ABFFF}
@media print{.box{box-shadow:none}}
@media (prefers-reduced-motion:reduce){*{transition:none !important}}
</style>
<script>(function(){try{var t=localStorage.getItem('shg_admin_theme');if(t==='dark'||t==='amoled'){document.documentElement.setAttribute('data-theme','dark');if(t==='amoled')document.documentElement.setAttribute('data-display','amoled');}}catch(e){}})();</script>
</head>
<?php
/* 23 Oct 2026 (owner: "admin, staff, counter mode - sabai login portal ma yei lock system; password last"):
   the same doors as /team/ - mobile, then the phone's lock (passkey), the camera (the face on the
   person's own record), birth date + ID number - and the username + password folded at the bottom.
   The doors call /api/team-passkey.php and /api/team-face-login.php; a success reloads this page,
   which sends the signed-in account to its own destination (login_destination). */
$doors = static function (string $title) use ($next): string {
    $h = static fn(string $s): string => Security::e($s);
    $me = '/admin/login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : '');
    $o  = '<div class="doors">';
    $o .= '<label for="m">मोबाइल नम्बर · Registered mobile</label>';
    $o .= '<div class="cc-row"><select name="cc" aria-label="Country"><option value="91">🇮🇳 +91</option><option value="977">🇳🇵 +977</option></select>';
    $o .= '<input id="m" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" placeholder="98XXXXXXXX"></div>';
    $o .= '<div id="pkMsg" class="ok" data-cls="note" hidden></div><div id="faceMsg" class="ok" data-cls="note" hidden></div>';
    if (Passkeys::ready()) {
        $o .= '<button type="button" class="alt door d-pk" data-pk="login">👆 Face ID / फिंगरप्रिन्ट (फोनको लक) · Phone lock</button><small>Device passkey: fingerprint / face stays on your device. An uploaded thumbprint image cannot sign you in.</small>';
    }
    $o .= '</div>';
    $o .= '<details class="pwd"' . ($GLOBALS['error'] !== '' || $GLOBALS['authPw'] ? ' open' : '') . '><summary>🔑 ' . $h($title) . '</summary>';
    return $o;
};
?>
<body>
<form class="box" method="post" autocomplete="off">
  <div class="logo"><img src="<?= Security::e(Brand::markUrl(68)) ?>" alt="<?= Security::e($company) ?>" width="131" height="68"></div>
  <h1><?= $heading ?></h1>
  <div class="sub"><?= $skin['sub'] ?></div>
  <div class="ceo-strip">
    <img src="/assets/img/ceo.jpg" alt="CEO" onerror="this.style.display='none'">
    <div><b><?= Security::e($ceoName) ?></b><span>👑 Founder &amp; CEO · Director</span></div>
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
    <?= $doors('Email + username + password') ?>
    <label for="ae">Email</label>
    <input id="ae" name="email" type="email" required autofocus autocapitalize="none" autocorrect="off"
           autocomplete="email" inputmode="email" placeholder="you@example.com" value="<?= Security::e($agentEmail) ?>">
    <label for="au">Username</label>
    <input id="au" name="username" required autocapitalize="none" autocorrect="off"
           autocomplete="username" placeholder="your agent username" value="<?= Security::e($agentUser) ?>">
    <label for="ap">Password</label>
    <input id="ap" name="password" type="password" required autocomplete="current-password">
    <button type="submit">Sign in to Agent Portal</button>
    </details>
    <div class="who" style="margin:12px 0 0">
      Forgot your password? <a href="/team/?v=forgot"><b>Reset it with a WhatsApp code</b></a> · पासवर्ड बिर्सनुभयो?<br>
      <b>Team portal:</b> <a href="/team/">mobile + password</a> (agents, partners, staff).<br>
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
    <?= $doors('Username + password') ?>
    <label for="u">Username</label>
    <input id="u" name="username" autocapitalize="none" autocorrect="off"
           value="<?= Security::e($_POST['username'] ?? '') ?>">
    <label for="p">Password</label>
    <input id="p" name="password" type="password">
    <?php if ($authPw): ?><input type="hidden" name="auth" value="pw"><?php endif; ?>
    <button type="submit">Sign in<?= $skin['title'] !== '' ? ' to ' . Security::e($skin['title']) : '' ?></button>
    </details>
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
    <span><a href="/team/?v=forgot">🔑 पासवर्ड बिर्सनुभयो? · Forgot password</a> &nbsp;·&nbsp; <a href="/team/">Team portal</a></span>
  </div>
  <div class="foot" style="justify-content:center;margin-top:6px;font-size:13px">
    <span>Help / locked out? &nbsp;<b>📞 <a href="tel:+<?= Security::e(preg_replace('/\D/', '', $helpTel) ?? '') ?>" style="white-space:nowrap"><?= Security::e($helpPhone) ?></a></b> — Head Office</span>
  </div>
</form>
<?php if ($step === 'start' && !$agentFlow): $meUrl = Security::e('/admin/login.php' . ($next !== '' ? '?next=' . rawurlencode($next) : '')); ?>
<?php if (Passkeys::ready()): ?><script src="/assets/js/team-passkey.js?v=20261026c" data-next="<?= $meUrl ?>"></script><?php endif; ?>
<?php endif; ?>
</body></html>
