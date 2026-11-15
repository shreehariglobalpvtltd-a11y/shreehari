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
require_once INCLUDE_PATH . '/quickunlock.php';   // PIN + pattern quick unlock — 1 Nov 2026
require_once INCLUDE_PATH . '/brand.php';   // the emblem (no baked name, no white tile) — 9 Oct 2026

$base = rtrim(APP_URL, '/');

// A "next" page to return to after signing in (e.g. a deep link from the
// legacy in-app admin mode straight to a specific pending payment).
// Restricted to a bare "<file>.php[?query]" inside admin/ — never a full
// URL or another host, so this can't become an open redirect.
$next     = (string) ($_GET['next'] ?? ($_POST['next'] ?? ''));
$safeNext = (preg_match('/^[A-Za-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $next) === 1) ? $next : 'index.php';

/* =====================================================================
   30 Oct 2026 — THE ONE DOOR. Owner: "login system dherai messy chha …
   number diyo bhane number + WhatsApp OTP, natra 'hi' pathaune; natra simple
   agent ID ra simple password. Login minimalistic, smooth, sabai ko lagi
   eutai — .network ko door jasto, .in ma pani." Agents, counters, staff and
   the office all start here, on both hosts; everyone then lands on the one
   home, admin/home.php. The decisions live in includes/onedoor.php.
   Switch: settings login_one_door_on (code default ON). Set to 0 and this
   block is skipped — the page below runs exactly as before.
   ===================================================================== */
if (Auth::oneDoorOn()) {
    require_once INCLUDE_PATH . '/onedoor.php';
    require_once INCLUDE_PATH . '/codelogin.php';   // 15 Nov 2026: code -> PIN / pattern -> own dashboard

    // where to go back to: only these two pages (never a URL from the request)
    $back = (string) ($_GET['back'] ?? ($_POST['back'] ?? ''));
    $back = in_array($back, ['me', 'team'], true) ? $back : '';
    $keepNext = $safeNext !== 'index.php' ? $safeNext : '';
    /* 15 Nov 2026: the code-first door leads (CodeLogin::on, setting login_code_first_on).
       ?classic=1 keeps the old one door (mobile / ID + password, WhatsApp code / hi) as
       the office's recovery route while everyone moves to a PIN / pattern; a sign-in
       there still has to set a PIN / pattern before any page opens (Auth::requireAdmin). */
    $codeFirst = CodeLogin::on();
    $classic   = $codeFirst && (string) ($_GET['classic'] ?? ($_POST['classic'] ?? '')) === '1';
    $odDest = static function (string $go = 'home') use ($keepNext, $back): string {
        if ($go === 'team') {
            return '/team/';
        }
        if ($keepNext !== '') {
            return '/admin/' . $keepNext;          // a deep link still wins
        }
        return match ($back) {
            'me'    => '/me/',
            'team'  => '/team/',
            default => Auth::homeUrl(),
        };
    };
    $odUrl = static function (array $q = []) use ($keepNext, $back, $classic): string {
        $q = array_filter(['step' => $q['step'] ?? null, 'classic' => ($q['classic'] ?? $classic) ? '1' : null, 'next' => $keepNext !== '' ? $keepNext : null, 'back' => $back !== '' ? $back : null],
            static fn($v): bool => $v !== null && $v !== '');
        return '/admin/login.php' . ($q !== [] ? '?' . http_build_query($q) : '');
    };

    // Already signed in? Straight on. (A staff member without a selling login lives at /team/.)
    if (Auth::isAdmin()) {
        Response::redirect($odDest());
    }
    if (TeamPortal::ready() && TeamPortal::current() !== null) {
        Response::redirect('/team/');
    }

    $step = 'start';
    $error = '';
    $notice = '';
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $mode = (string) ($_POST['mode'] ?? '');
        if (!Security::verifyCsrf()) {
            $error = 'यो पेज धेरै बेर खुला थियो — फेरि प्रयास गर्नुहोस् · This page was open too long, please try again.';
            $step  = (string) (OneDoor::state()['kind'] ?? 'start') ?: 'start';
        } else {
            $legacyMobile = static function (string $then): array {
                // an old cached agent page (code + mobile + OTP): the mobile alone now opens its IDs
                $w = OneDoor::who((string) ($_POST['mobile'] ?? ''), (string) ($_POST['cc'] ?? '91'));
                if (($w['step'] ?? '') !== 'mobile') {
                    return ['step' => 'start', 'error' => 'मोबाइल नम्बर लेख्नुहोस् · Type your mobile number.'];
                }
                return $then === 'send' ? OneDoor::codeSend() : OneDoor::codeVerify((string) ($_POST['otp'] ?? ''));
            };
            $r = match ($mode) {
                // ---- 15 Nov 2026: the code-first door (includes/codelogin.php holds every decision) ----
                'c_code'         => CodeLogin::enter((string) ($_POST['code'] ?? '')),
                'c_unlock'       => CodeLogin::unlock((string) ($_POST['qk_kind'] ?? ''), (string) ($_POST['qk_val'] ?? '')),
                'c_forgot'       => CodeLogin::forgot(),
                'c_identity'     => CodeLogin::identity((string) ($_POST['dob'] ?? ''), (string) ($_POST['mobile'] ?? '')),
                'c_hi'           => CodeLogin::hiStart(),
                'c_hi_done'      => CodeLogin::hiDone(),
                'c_code_send'    => CodeLogin::codeSend(),
                'c_otp'          => CodeLogin::codeCheck((string) ($_POST['otp'] ?? '')),
                'c_act'          => CodeLogin::activation((string) ($_POST['act'] ?? '')),
                'c_req'          => CodeLogin::request((string) ($_POST['note'] ?? '')),
                'c_create'       => CodeLogin::createFirst((string) ($_POST['qk_kind'] ?? ''), (string) ($_POST['qk_val'] ?? '')),
                'c_confirm'      => CodeLogin::createConfirm((string) ($_POST['qk_val'] ?? '')),
                'c_wl_send'      => CodeLogin::waLoginSend(),                                  // 15 Nov 2026: PIN / pattern not working → WhatsApp code
                'c_wl'           => CodeLogin::waLoginCheck((string) ($_POST['otp'] ?? '')),
                'who'            => OneDoor::who((string) ($_POST['who'] ?? ''), (string) ($_POST['cc'] ?? '91')),
                'pw'             => OneDoor::password((string) ($_POST['password'] ?? '')),
                'quick'          => OneDoor::quick((string) ($_POST['qk_kind'] ?? ''), (string) ($_POST['qk_val'] ?? '')),
                'quick_dev'      => OneDoor::quickDevice((string) ($_POST['qk_kind'] ?? ''), (string) ($_POST['qk_val'] ?? '')),   // 6 Nov 2026: remembered phone
                /* 13 Nov 2026 (mg18): the password on the lock screen. The account is the one the
                   signed device cookie names (never a typed or posted one); the check is the one
                   door's own (who + password: agents, staff, office 2FA all as before). A miss
                   stays on the lock screen. */
                'lock_code'      => OneDoor::lockOtp('code'),   // 13 Nov 2026 (mg19): WhatsApp code to the remembered account's own mobile
                'lock_hi'        => OneDoor::lockOtp('hi'),     // … or "hi" from that WhatsApp
                'lock_pw'        => (static function (): array {
                    $acc = QuickUnlock::rememberedDevice();
                    if ($acc === null) {
                        return ['step' => 'start', 'error' => 'मोबाइल नम्बर वा ID लेख्नुहोस् · Type your mobile number or ID.'];
                    }
                    $w = OneDoor::who($acc['username'], '91');
                    if (!in_array((string) ($w['step'] ?? ''), ['id', 'mobile'], true)) {
                        return ['step' => 'start', 'error' => (string) ($w['error'] ?? 'Please sign in again.')];
                    }
                    $r = OneDoor::password((string) ($_POST['password'] ?? ''));
                    if (!isset($r['go']) && in_array((string) ($r['step'] ?? ''), ['id', 'mobile', 'start'], true)) {
                        OneDoor::clear();
                        $r['step'] = 'start';
                    }
                    return $r;
                })(),
                'code_send'      => OneDoor::codeSend(),
                'code_verify'    => OneDoor::codeVerify((string) ($_POST['otp'] ?? '')),
                'wa_start'       => OneDoor::waStart(),
                'wa_finish'      => OneDoor::waFinish(),
                'pick'           => OneDoor::pick((int) ($_POST['id'] ?? 0)),
                'forgot_send'    => OneDoor::forgotSend((string) ($_POST['who'] ?? ''), (string) ($_POST['cc'] ?? '91')),
                'forgot_hi_first'=> OneDoor::forgotHiFirst((string) ($_POST['who'] ?? ''), (string) ($_POST['cc'] ?? '91')),
                'forgot_hi'      => OneDoor::forgotHi(),
                'forgot_prove'   => OneDoor::forgotProve((string) ($_POST['code'] ?? '')),
                'forgot_pick'    => OneDoor::forgotPick((string) ($_POST['who_id'] ?? '')),
                'forgot_set'     => OneDoor::forgotSet((string) ($_POST['pw'] ?? ''), (string) ($_POST['pw2'] ?? '')),
                'admin_2fa_verify' => (static function (): array {
                    $a = Auth::adminLogin2faVerify((string) ($_POST['otp'] ?? ''));
                    if ($a['ok']) {
                        OneDoor::clear();
                        return ['go' => 'home'];
                    }
                    return !empty($a['twofa'])
                        ? ['step' => 'admin_otp', 'error' => 'कोड मिलेन · ' . (string) ($a['error'] ?? 'That code is not right.')]
                        : ['step' => 'start', 'error' => 'फेरि लगइन गर्नुहोस् · ' . (string) ($a['error'] ?? 'Please sign in again.')];
                })(),
                'admin_2fa_resend' => (static function (): array {
                    $a = Auth::adminLogin2faResend();
                    if (!empty($a['ok'])) {
                        return ['step' => 'admin_otp', 'notice' => 'नयाँ कोड पठाइयो · A new code has been sent to your registered mobile.'];
                    }
                    return !empty($_SESSION['admin_2fa_pending'])
                        ? ['step' => 'admin_otp', 'error' => (string) ($a['error'] ?? 'Could not resend the code.')]
                        : ['step' => 'start', 'error' => (string) ($a['error'] ?? 'Please sign in again.')];
                })(),
                // ---- older pages still open in a browser tab ----
                'agent_pw'       => (static function (): array {
                    $a = Auth::agentPasswordLogin((string) ($_POST['email'] ?? ''), (string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                    return $a['ok'] ? ['go' => 'home'] : ['step' => 'start', 'error' => (string) ($a['error'] ?? 'Sign in failed.')];
                })(),
                'agent_request'  => $legacyMobile('send'),
                'agent_verify'   => $legacyMobile('verify'),
                default          => (static function (): array {
                    // the plain username + password post (bookmarks, scripts, every HTTP test)
                    $a = Auth::adminLogin((string) ($_POST['username'] ?? ''), (string) ($_POST['password'] ?? ''));
                    if ($a['ok']) {
                        return ['go' => 'home'];
                    }
                    return !empty($a['twofa'])
                        ? ['step' => 'admin_otp', 'notice' => 'तपाईंको WhatsApp मा ६ अङ्कको कोड पठाइयो · A 6-digit code was sent to your registered WhatsApp.']
                        : ['step' => 'start', 'error' => (string) ($a['error'] ?? 'Sign in failed.')];
                })(),
            };
            if (isset($r['go'])) {
                // 6 Nov 2026: remember WHO signed in on this browser — next time the
                // door opens straight on their pattern / PIN (QuickUnlock::rememberedAccount).
                if (($me = Auth::admin()) !== null) {
                    QuickUnlock::rememberDevice((int) $me['id']);
                }
                if (($r['flash'] ?? '') === 'pin_saved') {
                    $_SESSION['home_flash'] = '✅ PIN / pattern राखियो — अबदेखि कोड र PIN / pattern ले मात्र भित्र आउनुहोस् · Saved. From now on sign in with your code and PIN / pattern.';
                    if (($me = Auth::admin()) !== null && QuickUnlock::secReady() && QuickUnlock::secQuestion((int) $me['id']) === '') {
                        QuickUnlock::rememberDevice((int) $me['id']);
                        Response::redirect('/admin/pin-setup.php?secq=1');   // 15 Nov 2026: offer the security question once (optional)
                    }
                }
                if (($r['flash'] ?? '') === 'saved') {
                    $_SESSION['home_flash'] = '✅ नयाँ पासवर्ड राखियो, तपाईं भित्र आउनुभयो · New password saved — you are signed in.';
                    if ($r['go'] === 'team') {
                        $_SESSION['team_flash'] = ['ok', (string) $_SESSION['home_flash']];
                    }
                }
                Response::redirect($odDest((string) $r['go']));
            }
            $step   = (string) ($r['step'] ?? 'start');
            $error  = (string) ($r['error'] ?? '');
            $notice = (string) ($r['notice'] ?? '');
        }
    } else {
        $want = (string) ($_GET['step'] ?? '');
        if ($want === 'start') {
            OneDoor::clear();
        }
        if ((string) ($_GET['forget'] ?? '') === '1') {
            QuickUnlock::forgetDevice();   // "Not you?" — this browser forgets the account
            CodeLogin::clear();
        }
        $step = in_array($want, OneDoor::STEPS, true) || in_array($want, CodeLogin::STEPS, true) ? $want : 'start';
        if ($want === 'c_code') {
            CodeLogin::clear();
        }
        // a remembered phone opens straight on its own code (never a typed or posted one)
        if ($codeFirst && !$classic && $step === 'start' && (string) ($_GET['other'] ?? '') !== '1'
            && (string) ($_GET['forget'] ?? '') !== '1' && QuickUnlock::rememberedDevice() !== null) {
            $step = CodeLogin::enterRemembered()['step'];
        }
    }
    /* 15 Nov 2026: code-first steps show only with what they need; the classic "start"
       becomes the code box unless ?classic=1 asked for the old door. */
    if ($codeFirst) {
        $cs = CodeLogin::state();
        if ($step === 'start' && !$classic) {
            $step = 'c_code';
        }
        $step = match (true) {
            !in_array($step, CodeLogin::STEPS, true)                         => $step,
            $step === 'c_code'                                               => 'c_code',
            $cs === null                                                     => 'c_code',
            in_array($step, ['c_create', 'c_confirm'], true) && !CodeLogin::proved() => (isset($cs['idok']) ? 'c_prove' : 'c_unlock'),
            $step === 'c_confirm' && !is_array($cs['pend'] ?? null)          => 'c_create',
            $step === 'c_walogin' && empty($cs['wl'])                        => 'c_unlock',
            $step === 'c_prove' && !isset($cs['idok'])                       => (($cs['purpose'] ?? '') === 'reset' ? 'c_forgot' : (($cs['purpose'] ?? '') === 'setup' ? 'c_setup' : 'c_unlock')),
            default                                                          => $step,
        };
    } elseif (in_array($step, CodeLogin::STEPS, true)) {
        $step = 'start';
    }

    // a step only shows with what it needs; otherwise one step back
    $st    = OneDoor::state();
    $reset = OneDoor::reset();
    $kind  = (string) ($st['kind'] ?? '');
    $step = match (true) {
        in_array($step, ['mobile', 'code'], true) && $kind !== 'mobile'       => 'start',
        $step === 'wa' && ($kind !== 'mobile' || !is_array($st['wa'] ?? null)) => ($kind === 'mobile' ? 'mobile' : 'start'),
        $step === 'id' && $kind !== 'id'                                       => 'start',
        $step === 'pick' && OneDoor::pickList() === []                         => ($kind !== '' ? $kind : 'start'),
        $step === 'admin_otp' && empty($_SESSION['admin_2fa_pending'])         => 'start',
        $step === 'forgot_code' && $reset === null                             => 'forgot',
        $step === 'forgot_pick' && TeamPortal::resetChoices() === []           => ($reset !== null ? 'forgot_code' : 'forgot'),
        $step === 'forgot_pw' && OneDoor::forgotWho() === null                 => ($reset !== null ? 'forgot_code' : 'forgot'),
        default                                                                => $step,
    };
    $reset = OneDoor::reset();
    // 6 Nov 2026: a remembered phone opens on its pattern / PIN (?other=1 = the normal box)
    // 13 Nov 2026 (mg18): every remembered phone — with a PIN / pattern or only a password
    $remembered = ($step === 'start' && (string) ($_GET['other'] ?? '') !== '1') ? QuickUnlock::rememberedDevice() : null;

    $company   = Settings::getString('company_name', APP_NAME);
    $ceoName   = trim(Settings::getString('company_ceo', '')) !== '' ? trim(Settings::getString('company_ceo', '')) : (string) Settings::company()['operator'];
    $helpPhone = Settings::officePhone();
    $helpTel   = preg_replace('/\D/', '', CounterDesk::dialDigits($helpPhone, 'IN') ?: Settings::officeWhatsApp()) ?? '';
    $csrfTok   = Security::csrfToken();
    $h   = static fn($v): string => Security::e((string) ($v ?? ''));
    $hid = static fn(): string => Security::csrfField()
        . ($keepNext !== '' ? '<input type="hidden" name="next" value="' . Security::e($keepNext) . '">' : '')
        . ($back !== '' ? '<input type="hidden" name="back" value="' . Security::e($back) . '">' : '')
        . ($classic ? '<input type="hidden" name="classic" value="1">' : '');
    $ccSel = static function (string $sel) use ($h): string {
        $o = '';
        foreach (TeamPortal::COUNTRIES as $k => $lbl) {
            $o .= '<option value="' . $h($k) . '"' . ((string) $k === $sel ? ' selected' : '') . '>' . $h($lbl) . '</option>';
        }
        return '<select name="cc" aria-label="देश · Country">' . $o . '</select>';
    };
    $roleLbl = static fn(string $r): string => [
        'agent' => 'एजेन्ट · Agent', 'counter' => 'काउन्टर · Counter', 'scanner' => 'स्क्यानर · Scanner',
        'manager' => 'म्यानेजर · Manager', 'accountant' => 'एकाउन्टेन्ट · Accountant', 'support' => 'सपोर्ट · Support',
        'official' => 'अफिस · Official', 'operations' => 'अपरेसन · Operations', 'viewer' => 'हेर्ने मात्र · Viewer',
        'superadmin' => 'सुपर एडमिन · Super admin', 'staff' => 'स्टाफ · Staff',
    ][$r] ?? ucfirst($r);
    $prefill = $kind === 'mobile' ? (string) $st['phone'] : ($kind === 'id' ? (string) $st['id'] : '');
    $ccNow   = TeamPortal::cc($st['cc'] ?? ($reset['cc'] ?? ($_POST['cc'] ?? '91')));
    $hiOn    = OneDoor::hiOn();
    $waPoll  = $step === 'wa' ? (array) $st['wa'] : ($step === 'forgot_code' && is_array($reset['wa'] ?? null) ? (array) $reset['wa'] : null);
    $qr = '';
    if ($waPoll !== null) {
        try {
            require_once INCLUDE_PATH . '/qr.php';
            $qr = QrCode::dataUri((string) $waPoll['url'], 4, 2);
        } catch (Throwable $e) {
            $qr = '';
        }
    }
    $title = match ($step) {
        'forgot', 'forgot_code', 'forgot_pick', 'forgot_pw' => '🔑 पासवर्ड बिर्सनुभयो? · Forgot password',
        'pick'      => '🪪 कुन ID खोल्ने? · Which ID?',
        'admin_otp' => '🔐 WhatsApp कोड · Login code',
        'wa'        => '💬 WhatsApp मा hi पठाउनुहोस् · Send hi',
        'c_setup'   => '🆕 पहिलो पटक · First-time setup',
        'c_forgot'  => '🔑 PIN / Pattern बिर्सनुभयो? · Forgot PIN / Pattern',
        'c_prove'   => '✅ आफ्नो नम्बर पुष्टि · Confirm it is you',
        'c_create'  => '🔐 PIN वा Pattern राख्नुहोस् · Choose a PIN or pattern',
        'c_confirm' => '🔁 फेरि उही · Once more to confirm',
        default     => 'लगइन · Sign in',
    };
    $cs      = $codeFirst ? CodeLogin::state() : null;
    $cAid    = (int) ($cs['aid'] ?? 0);
    $cKinds  = CodeLogin::kinds($cAid);
    $cLock   = $step === 'c_unlock' && !empty($cs['dev']);   // remembered phone: Android-style lock look
    $cWa     = $step === 'c_prove' && is_array($cs['wa'] ?? null) ? (array) $cs['wa'] : null;
    if ($cWa !== null) {
        $waPoll = $cWa;   // same polling whether the details matched or not (the page tells nothing)
    }
    if ($cWa !== null && $qr === '') {
        try {
            require_once INCLUDE_PATH . '/qr.php';
            $qr = QrCode::dataUri((string) $cWa['url'], 4, 2);
        } catch (Throwable $e) {
            $qr = '';
        }
    }
    ?><!doctype html>
<html lang="ne"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>लगइन · Sign in · <?= $h($company) ?></title>
<meta name="robots" content="noindex,nofollow">
<meta name="csrf-token" content="<?= $h($csrfTok) ?>">
<?= Brand::iconLinks() ?>
<style>
*{box-sizing:border-box}
:root{--ink:#16233C;--mut:#5C6B85;--line:#C9D3E3;--royal:#0054A8;--wa:#128C4A;--card:#fff;--well:#F6F8FC}
body{margin:0;min-height:100vh;min-height:100dvh;display:flex;align-items:center;justify-content:center;padding:16px;
  font-family:system-ui,-apple-system,'Segoe UI',Roboto,'Noto Sans Devanagari',sans-serif;color:var(--ink);
  background:#0A2A8F;background-image:radial-gradient(90% 60% at 20% 0%,#1F4FD8 0%,rgba(31,79,216,0) 60%),linear-gradient(160deg,#0A2A8F 0%,#1036B4 60%,#1846CC 100%);background-attachment:fixed;border-top:3px solid #F07800}
.box{background:var(--card);width:100%;max-width:400px;padding:26px 22px 20px;border-radius:22px;border:1px solid #D5DEEB;
  box-shadow:inset 0 1px 0 #fff,0 2px 0 #C4CFE1,0 4px 0 #A8B6CE,0 7px 0 #8B9CBB,0 20px 30px -6px rgba(0,0,0,.55)}
.logo{text-align:center;line-height:1}
.logo img{height:58px;width:auto;max-width:150px;object-fit:contain;filter:drop-shadow(0 6px 14px rgba(18,38,78,.26))}
h1{font-size:20px;text-align:center;margin:10px 0 4px}
.sub{text-align:center;color:var(--mut);font-size:13px;margin:0 0 16px;line-height:1.45}
.ceo{display:flex;align-items:center;gap:9px;margin:0 0 14px;padding:7px 11px;border-radius:12px;background:#F8F9FB;border:1px solid #E8ECF4}
.ceo img{width:34px;height:34px;border-radius:50%;object-fit:cover;object-position:center top;border:2px solid var(--royal);flex:0 0 auto}
.ceo b{display:block;font-size:12.5px}.ceo span{font-size:11px;color:#8794AB;font-weight:600}
label{display:block;font-size:13.5px;font-weight:800;margin:12px 0 6px}
input,select{width:100%;min-height:50px;padding:12px 14px;border:1.5px solid #7D8AA0;border-radius:12px;font-size:17px;background:var(--well);color:inherit}
input:focus,select:focus{outline:none;border-color:var(--royal);box-shadow:0 0 0 3px #D9E7FA}
input::placeholder{font-size:14px;opacity:.7}
.cc-row{display:grid;grid-template-columns:auto minmax(0,1fr);gap:8px}
.cc-row select{width:auto;padding:0 8px;font-size:15px}
.btn{display:flex;align-items:center;justify-content:center;flex-direction:column;gap:2px;width:100%;margin-top:14px;padding:12px 14px;min-height:52px;border:0;border-radius:13px;
  background:linear-gradient(180deg,#1F6CC8,#0054A8 58%,#004B98);color:#fff;font:inherit;font-size:16px;font-weight:800;cursor:pointer;text-decoration:none;text-align:center;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.4),0 3px 0 #003C90,0 8px 14px -5px rgba(0,52,130,.5);touch-action:manipulation}
.btn:active{transform:translateY(2px)}
.btn small{font-size:11.5px;font-weight:700;opacity:.92}
.btn.wa{background:linear-gradient(180deg,#25B864,#128C4A 60%,#0E7A40);box-shadow:inset 0 1px 0 rgba(255,255,255,.35),0 3px 0 #0B5E31,0 8px 14px -5px rgba(14,122,64,.5);font-size:16.5px;min-height:62px}
.btn.alt{background:#fff;color:var(--royal);border:1.5px solid var(--royal);box-shadow:none;font-size:14.5px;min-height:48px}
.btn.pick{align-items:flex-start;text-align:left;background:#fff;color:var(--ink);border:1.5px solid var(--line);box-shadow:0 2px 0 #CCD6E5;font-size:15px}
.btn.pick small{color:var(--mut)}
.btn.pk{background:#fff;color:#B4560C;border:1.5px dashed #E39A5B;box-shadow:none;font-size:14px;min-height:46px;margin-top:10px}
.or{display:flex;align-items:center;gap:10px;margin:16px 0 0;font-size:12px;color:#8a94a6}
.or::before,.or::after{content:'';flex:1;border-top:1px solid #E1E6EF}
.who{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:0 0 6px;font-weight:800;font-size:15px}
.who a{font-size:13px;font-weight:700}
.msg{padding:10px 13px;border-radius:10px;font-size:13.5px;font-weight:650;margin:10px 0 0;line-height:1.45}
.msg.bad{background:#F7DCDC;color:#8A1F1F}.msg.ok{background:#DEF4E4;color:#14602E}.msg.warn{background:#FFF4E0;color:#7A4A00}
.note{font-size:12.5px;color:var(--mut);margin:10px 0 0;line-height:1.45;text-align:center}
.links{display:flex;flex-direction:column;align-items:center;gap:9px;margin-top:18px;font-size:13.5px}
a{color:var(--royal);font-weight:700;text-decoration:none}
.qr{display:none;text-align:center;margin-top:12px}.qr img{width:150px;height:150px;image-rendering:pixelated;border-radius:8px;border:1px solid #E1E6EF}
@media (min-width:760px) and (hover:hover){.qr{display:block}}
.wait{text-align:center;font-weight:700;margin:12px 0 0}
:focus-visible{outline:3px solid var(--royal);outline-offset:2px}
[hidden]{display:none !important}
:root[data-theme="dark"]{color-scheme:dark;--ink:#E8F0FB;--mut:#ACBBD0;--card:#15243E;--well:#080C13;--line:#3A5178}
:root[data-theme="dark"] body{background:#0B1220}
:root[data-theme="dark"] .box{border-color:#24375A;box-shadow:0 20px 30px -6px #000}
:root[data-theme="dark"] .ceo{background:#1C2E4D;border-color:#3A5178}
:root[data-theme="dark"] .btn.alt,:root[data-theme="dark"] .btn.pick,:root[data-theme="dark"] .btn.pk{background:#1C2E4D;color:#E8F0FB}
:root[data-theme="dark"] a{color:#9ABFFF}
:root[data-theme="dark"] .msg.bad{background:#30161B;color:#FFADB9}:root[data-theme="dark"] .msg.ok{background:#0F271B;color:#8CE4B8}:root[data-theme="dark"] .msg.warn{background:#2E2410;color:#FFD48A}
@media (prefers-reduced-motion:reduce){.btn:active{transform:none}}
/* 12 Nov 2026 — Android-style lock screen (a remembered phone) */
body.lock{background:#070B14;background-image:radial-gradient(120% 70% at 50% -10%,#16305F 0%,rgba(22,48,95,0) 62%);border-top:0;color:#E8F0FB}
body.lock .box{background:transparent;border:0;box-shadow:none;padding:8px 6px;max-width:340px;color:#E8F0FB}
body.lock h1,body.lock .sub,body.lock .ceo{display:none}
body.lock .logo img{height:40px;filter:none;opacity:.95}
.lk-clock{text-align:center;font-size:54px;font-weight:200;letter-spacing:1px;line-height:1;margin:14px 0 2px;font-variant-numeric:tabular-nums}
.lk-date{text-align:center;font-size:13px;color:#9FB2D0;margin:0 0 16px}
body.lock .who{font-size:16px;color:#fff;margin-bottom:2px}
body.lock .note{color:#9FB2D0}
body.lock .qk-pad{background:transparent;width:264px;height:264px}
body.lock .qk-dot{background:#5E7399;width:16px;height:16px;margin:-8px 0 0 -8px}
body.lock .qk-dot.on{background:#F07C1F;transform:scale(1.5)}
body.lock .qk-pad svg{color:#F07C1F}
body.lock .qk-tabs button{background:transparent;border-color:#2B3F63;color:#C9D6EA;min-height:38px;border-radius:99px;font-size:13px}
body.lock .qk-tabs button.on{background:#F07C1F;border-color:#F07C1F;color:#fff}
body.lock .links a{color:#9ABFFF;font-weight:600;font-size:13px}
body.lock .links~.links{display:none}body.lock .links a[href*="forget"]{color:#FF9AA5 !important;font-size:12px}
body.lock .msg.bad{background:#30161B;color:#FFADB9}body.lock .msg.ok{background:#0F271B;color:#8CE4B8}
.lk-dots{display:flex;gap:14px;justify-content:center;height:22px;margin:18px 0 14px}
body.lock .lk-otp{display:grid;gap:10px;margin-top:16px}
body.lock .lk-otp form{margin:0}
body.lock .lk-otp .btn{width:100%}
body.lock .lk-otp .btn.alt{background:rgba(255,255,255,.07);color:#E8F0FB;border:1px solid #2B3F63}
body.lock .lk-forgot{margin:0}
body.lock .lk-forgot button{background:none;border:0;padding:4px 0;color:#9ABFFF;font:inherit;font-weight:600;font-size:13px;cursor:pointer;text-align:center}
body.lock .lk-pw{display:grid;gap:10px;margin-top:14px}
body.lock .lk-pw[hidden]{display:none}
body.lock .lk-pw label{color:#9FB2D0;font-size:13px}
body.lock .lk-pw input{min-height:52px;border-radius:14px;border:1px solid #2B3F63;background:rgba(255,255,255,.06);color:#fff;font-size:18px;padding:0 16px;text-align:center;letter-spacing:.08em}
body.lock .lk-pw input:focus{outline:2px solid #F07C1F;outline-offset:2px}
body.lock .lk-pw .btn{min-height:52px;border-radius:14px;background:#F07C1F;border:0;color:#fff;font-weight:800}
.lk-dots i{width:14px;height:14px;border-radius:50%;border:2px solid #7C90B4}
.lk-dots i.on{background:#F07C1F;border-color:#F07C1F}
.lk-keys{display:grid;grid-template-columns:repeat(3,72px);gap:12px 22px;justify-content:center}
.lk-keys button{width:72px;height:72px;border-radius:50%;border:0;background:rgba(255,255,255,.08);color:#fff;font:400 26px/1 system-ui,sans-serif;cursor:pointer;touch-action:manipulation;-webkit-tap-highlight-color:transparent}
.lk-keys button:active{background:rgba(240,124,31,.55)}
.lk-keys button.s{background:transparent;font-size:15px;font-weight:700;color:#9ABFFF}
.lk-keys button.go{background:#F07C1F;font-size:17px;font-weight:800}
body.lock.shake .qk,body.lock.shake .lk-dots{animation:lkshake .35s}
@keyframes lkshake{20%,60%{transform:translateX(-9px)}40%,80%{transform:translateX(9px)}}
@media (max-height:640px){.lk-clock{font-size:40px;margin-top:6px}.lk-keys{grid-template-columns:repeat(3,62px);gap:9px 20px}.lk-keys button{width:62px;height:62px;font-size:22px}body.lock .qk-pad{width:232px;height:232px}}
@media (prefers-reduced-motion:reduce){body.lock.shake .qk,body.lock.shake .lk-dots{animation:none}}
/* 15 Nov 2026 — code-first door: the keypad on the white card too */
body:not(.lock) .lk-keys button{background:#EEF3FA;color:var(--ink);border:1px solid #D5DEEB}
body:not(.lock) .lk-keys button:active{background:#D9E7FA}
body:not(.lock) .lk-keys button.s{background:transparent;border-color:transparent;color:var(--royal)}
body:not(.lock) .lk-keys button.go{background:var(--royal);color:#fff;border-color:var(--royal)}
:root[data-theme="dark"] body:not(.lock) .lk-keys button{background:#1C2E4D;color:#E8F0FB;border-color:#3A5178}
.cl-more{margin-top:16px;border-top:1px solid #E1E6EF;padding-top:10px}
.cl-more summary{cursor:pointer;font-size:13px;font-weight:700;color:var(--mut);text-align:center;list-style:none}
.cl-more summary::-webkit-details-marker{display:none}
.cl-small{font-size:12.5px;font-weight:600;opacity:.85}
.cl-forgot{margin:0}.cl-forgot button{background:none;border:0;padding:6px 0;color:var(--royal);font:inherit;font-weight:600;font-size:13px;cursor:pointer}
body.lock .cl-forgot button,body.lock .who a{color:#9ABFFF}
.who span{word-break:break-all}
/* 15 Nov 2026 — calm, secure motion on the code-first door */
.cl-len{display:flex;gap:8px;justify-content:center;margin:4px 0 0}.cl-len[hidden]{display:none}
.cl-len button{min-height:34px;padding:0 14px;border-radius:99px;border:1px solid var(--line);background:transparent;color:var(--mut);font:700 13px/1 inherit;cursor:pointer}
.cl-len button.on{background:var(--royal);border-color:var(--royal);color:#fff}
body.cf .box{animation:cfIn .5s cubic-bezier(.2,.8,.2,1) both}
body.cf .logo{position:relative}
body.cf .logo::after{content:'';position:absolute;left:50%;top:50%;width:84px;height:84px;margin:-42px 0 0 -42px;border-radius:50%;border:2px solid rgba(240,124,31,.55);animation:cfRing 2.6s ease-out infinite;pointer-events:none}
body.cf .lk-dots i{transition:transform .18s,background .18s,border-color .18s}
body.cf .lk-dots i.on{animation:cfPop .22s ease-out}
.lk-dots.done i{background:#16A34A !important;border-color:#16A34A !important;transform:scale(1.15)}
body.cf .lk-keys button{transition:transform .08s,background .15s}
body.cf .lk-keys button:active{transform:scale(.92)}
body.cf .qk-pad{animation:cfIn .45s .08s both}
body.cf.shake .lk-dots,body.cf.shake .qk{animation:lkshake .35s}
@keyframes cfIn{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:none}}
@keyframes cfRing{0%{transform:scale(.7);opacity:.9}100%{transform:scale(1.35);opacity:0}}
@keyframes cfPop{0%{transform:scale(.6)}60%{transform:scale(1.3)}100%{transform:scale(1)}}
/* 15 Nov 2026: the other ways in, under the keypad (WhatsApp code / password) */
.cl-alt{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:16px 0 4px}
.cl-alt form{margin:0}
.cl-alt-b{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:52px;padding:8px 10px;border-radius:14px;border:1px solid #D5DEEC;background:#F6F8FC;color:#0B2A6F;font:inherit;font-size:15px;font-weight:800;text-decoration:none;cursor:pointer;transition:transform .12s,background .15s,border-color .15s}
.cl-alt-b span{display:flex;flex-direction:column;align-items:flex-start;line-height:1.15;text-align:left;white-space:nowrap}
.cl-alt-b small{font-size:11.5px;font-weight:600;opacity:.75}
.cl-alt-b.wa{background:#E9F9EF;border-color:#A7E3BD;color:#0E6B35}
.cl-alt-b:active{transform:scale(.96)}
body.lock .cl-alt-b{background:rgba(255,255,255,.12);border-color:rgba(255,255,255,.28);color:#fff}
body.lock .cl-alt-b.wa{background:rgba(37,211,102,.22);border-color:rgba(37,211,102,.55)}
@media (prefers-reduced-motion:reduce){.cl-alt-b{transition:none}}
@media (prefers-reduced-motion:reduce){body.cf .box,body.cf .qk-pad,body.cf .lk-dots i.on,body.cf .logo::after{animation:none}}
@media (max-width:359px){.lk-keys{grid-template-columns:repeat(3,62px);gap:9px 18px}.lk-keys button{width:62px;height:62px;font-size:22px}}
</style>
<script>(function(){try{var t=localStorage.getItem('shg_admin_theme');if(t==='dark'||t==='amoled')document.documentElement.setAttribute('data-theme','dark');}catch(e){}})();</script>
<link rel="manifest" href="/admin/app-manifest.php"><meta name="theme-color" content="#0B1220">
</head>
<body class="<?= trim((($remembered ?? null) !== null || $cLock ? 'lock ' : '') . (in_array($step, CodeLogin::STEPS, true) ? 'cf' : '')) ?>">
<main class="box" data-door="one">
  <div class="logo"><img src="<?= $h(Brand::markUrl(68)) ?>" alt="<?= $h($company) ?>" width="112" height="58"></div>
  <h1><?= $h($title) ?></h1>
  <?php if ($step === 'start'): ?>
    <p class="sub">एजेन्ट, काउन्टर, स्टाफ, अफिस — सबैको एउटै ढोका · One door for agents, counters, staff and office</p>
    <div class="ceo"><img src="/assets/img/ceo.jpg" alt="" onerror="this.style.display='none'"><div><b><?= $h($ceoName) ?></b><span>👑 Founder &amp; CEO · <?= $h($company) ?></span></div></div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="msg bad" role="alert"><?= $h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg ok" role="status"><?= $h($notice) ?></div><?php endif; ?>

  <?php
  /* ---- 15 Nov 2026: code-first screens. The PIN keypad and the pattern pad are the
     same widgets as the lock screen; the account is always the one in the server
     session (CodeLogin::state), never a field of these forms. ---- */
  $pinForm = static function (string $mode, string $btn, bool $hide = false, int $len = 0) use ($hid): string {
      return '<form method="post" action="/admin/login.php" data-qk-form="pin" data-len="' . $len . '"' . ($hide ? ' hidden' : '') . ' autocomplete="off">' . $hid()
          . '<input type="hidden" name="mode" value="' . Security::e($mode) . '"><input type="hidden" name="qk_kind" value="pin">'
          . '<label for="qkpin_' . Security::e($mode) . '">PIN</label>'
          . '<input id="qkpin_' . Security::e($mode) . '" class="qk-pin" name="qk_val" type="password" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" autocomplete="off" required>'
          . '<div class="lk-dots" hidden aria-hidden="true"></div>'
          . '<div class="lk-keys" hidden>' . implode('', array_map(static fn(string $k): string => '<button type="button" data-k="' . $k . '">' . $k . '</button>', ['1','2','3','4','5','6','7','8','9']))
          . '<button type="button" class="s" data-k="x" aria-label="Delete">⌫</button><button type="button" data-k="0">0</button><button type="button" class="go" data-k="ok" aria-label="OK">OK</button></div>'
          . '<button class="btn" type="submit">' . Security::e($btn) . '</button></form>';
  };
  $patForm = static function (string $mode, string $note, bool $hide = false) use ($hid): string {
      return '<form method="post" action="/admin/login.php" data-qk-form="pattern"' . ($hide ? ' hidden' : '') . '>' . $hid()
          . '<input type="hidden" name="mode" value="' . Security::e($mode) . '"><input type="hidden" name="qk_kind" value="pattern"><input type="hidden" name="qk_val" value="">'
          . '<div class="qk-pad" data-qk-pad aria-label="Pattern"></div><p class="note">' . Security::e($note) . '</p></form>';
  };
  $codeLine = static function (bool $lock = false) use ($h, $cs, $odUrl): string {
      return '<div class="who">' . ($lock ? '🔒 ' : '🪪 ') . '<span>' . $h($cs['code'] ?? '') . '</span> · <a href="' . $h($odUrl(['step' => 'c_code'])) . '&amp;forget=1">⇄ खाता बदल्ने · Change account</a></div>';
  };
  $officeBox = static function () use ($hid): string {
      return '<details class="cl-more"><summary>🏢 नम्बर छैन / विवरण मिलेन? कार्यालयबाट · No access to that number? Ask the office</summary>'
          . '<form method="post" action="/admin/login.php" autocomplete="off">' . $hid() . '<input type="hidden" name="mode" value="c_act">'
          . '<label for="act">कार्यालयले दिएको सक्रिय कोड · Activation code from the office</label>'
          . '<input id="act" name="act" maxlength="12" autocapitalize="characters" spellcheck="false" placeholder="XXXX-XXXX" style="text-align:center;letter-spacing:.2em">'
          . '<button class="btn alt" type="submit">कोडले सक्रिय गर्ने · Use activation code</button></form>'
          . '<form method="post" action="/admin/login.php" autocomplete="off">' . $hid() . '<input type="hidden" name="mode" value="c_req">'
          . '<label for="note">कार्यालयले कसरी सम्पर्क गर्ने? (ऐच्छिक) · How can the office reach you? (optional)</label>'
          . '<input id="note" name="note" maxlength="160" placeholder="नाम / अर्को नम्बर · name / another number">'
          . '<button class="btn alt" type="submit">📨 कार्यालयलाई प्रमाणित गर्न भन्ने · Ask the office to verify me</button></form>'
          . '<p class="note">कार्यालयले तपाईंलाई चिनेपछि मात्र कोड दिन्छ · The office gives a code only after confirming who you are.</p></details>';
  };
  ?>
  <?php if ($step === 'c_code'): ?>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $hid() ?><input type="hidden" name="mode" value="c_code">
    <label for="code">एजेन्ट / स्टाफ / एडमिन कोड · Agent / Staff / Admin code</label>
    <input id="code" name="code" autocomplete="username" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="60" required autofocus
           placeholder="SHG-0008 / SHG-S-0003" value="<?= $h($cs['code'] ?? '') ?>">
    <button class="btn" type="submit">अगाडि बढ्नुहोस् · Continue →</button>
  </form>

  <?php elseif ($step === 'c_unlock'): $cPat = $cKinds['pattern'] && !$cKinds['pin']; ?>
  <?php if ($cLock): ?><div class="lk-clock" id="lkClock" aria-hidden="true">--:--</div><div class="lk-date" id="lkDate"></div><?php endif; ?>
  <?= $codeLine($cLock) ?>
  <div class="qk" data-qk>
    <div class="qk-tabs" role="tablist">
      <button type="button"<?= $cPat ? '' : ' class="on"' ?> data-qk-tab="pin">🔢 PIN</button>
      <button type="button"<?= $cPat ? ' class="on"' : '' ?> data-qk-tab="pattern">◯ Pattern</button>
    </div>
    <?= $pinForm('c_unlock', 'PIN ले भित्र · Unlock', $cPat, $cAid > 0 ? QuickUnlock::pinLen($cAid) : 6) ?>
    <?= $patForm('c_unlock', 'आफ्नो pattern कोर्नुहोस् · Draw your pattern', !$cPat) ?>
  </div>
  <?php /* 15 Nov 2026 (owner: "PIN / pattern le khulos; tyo ni vayena code ra password, natra
           WhatsApp OTP; ani forget"): the other ways in, one tap each, under the keypad. */ ?>
  <div class="cl-alt" role="group" aria-label="Other ways to sign in">
    <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="c_wl_send">
      <button type="submit" class="cl-alt-b wa">📲 <span>WhatsApp<small>OTP कोड · code</small></span></button></form>
    <a class="cl-alt-b" href="/admin/login.php?classic=1&amp;other=1">🔑 <span>पासवर्ड<small>Code + password</small></span></a>
  </div>
  <div class="links">
    <form method="post" action="/admin/login.php" class="lk-forgot cl-forgot"><?= $hid() ?><input type="hidden" name="mode" value="c_forgot">
      <button type="submit">PIN / Pattern बिर्सनुभयो? · Forgot PIN / Pattern?</button></form>
  </div>

  <?php elseif ($step === 'c_walogin'): ?>
  <?= $codeLine() ?>
  <div class="msg warn" role="note">🔒 यो कोड आफैं लगइन गर्दा मात्र — अरूले मागे नदिनुहोस् · Use this code only to sign in yourself. Never give it to anyone.</div>
  <?php if (!empty($cs['debug']) && APP_DEBUG): ?><div class="msg ok">DEV code: <?= $h($cs['debug']) ?></div><?php endif; ?>
  <form method="post" action="/admin/login.php" autocomplete="off" id="wlForm"><?= $hid() ?><input type="hidden" name="mode" value="c_wl">
    <label for="wlotp">WhatsApp मा आएको ६ अङ्कको कोड · 6-digit code from WhatsApp</label>
    <input id="wlotp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus placeholder="______" style="letter-spacing:.35em;text-align:center;font-size:24px">
    <button class="btn" type="submit">भित्र जाने · Sign in</button></form>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="c_wl_send">
    <button class="btn alt" type="submit">📩 नयाँ कोड पठाउने · Send a new code</button></form>
  <p class="note">कार्यालय (admin / manager) खाता WhatsApp कोडले खुल्दैन — PIN / pattern वा "बिर्सनुभयो?" · Office accounts do not open by WhatsApp code: use PIN / pattern or "Forgot".</p>
  <div class="links"><a href="/admin/login.php?step=c_unlock">← PIN / Pattern मा फर्कने · Back to PIN / Pattern</a></div>

  <?php elseif ($step === 'c_setup' || $step === 'c_forgot'): ?>
  <?= $codeLine() ?>
  <p class="sub" style="display:block"><?= $step === 'c_setup'
      ? 'यो खातामा PIN / pattern छैन। एक पटक मात्र: दर्ता गरिएको जन्म मिति र मोबाइल, अनि त्यही नम्बरको WhatsApp बाट पुष्टि · No PIN / pattern yet. Once only: your registered date of birth and mobile, then a confirmation from that WhatsApp.'
      : 'दर्ता गरिएको जन्म मिति र मोबाइल लेख्नुहोस्, अनि त्यही नम्बरको WhatsApp बाट पुष्टि गरेर नयाँ PIN / pattern राख्नुहोस् · Enter your registered date of birth and mobile, confirm from that WhatsApp, then set a new PIN / pattern.' ?></p>
  <form method="post" action="/admin/login.php" autocomplete="off">
    <?= $hid() ?><input type="hidden" name="mode" value="c_identity">
    <label for="dob">जन्म मिति · Date of birth</label>
    <input id="dob" name="dob" type="date" max="<?= $h(date('Y-m-d')) ?>" min="1930-01-01" required autofocus>
    <label for="mob">दर्ता गरिएको मोबाइल / WhatsApp · Registered mobile / WhatsApp</label>
    <input id="mob" name="mobile" type="tel" inputmode="tel" maxlength="16" required placeholder="98XXXXXXXX" autocomplete="off">
    <button class="btn" type="submit">अगाडि · Continue →</button>
  </form>
  <?= $officeBox() ?>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'c_code'])) ?>">← फिर्ता · Back</a></div>

  <?php elseif ($step === 'c_prove'): ?>
  <?= $codeLine() ?>
  <div class="msg warn" role="note">🔒 आफैंले PIN राख्दा मात्र पठाउनुहोस् — अरूले मागे नपठाउनुहोस् · Only when YOU are setting your PIN. Never because someone asked.</div>
  <?php if ($cWa !== null): ?>
    <a class="btn wa" href="<?= $h($cWa['url']) ?>" target="_blank" rel="noopener">WhatsApp खोल्नुहोस् · Open WhatsApp<small><?= $h($cWa['text']) ?></small></a>
    <?php if ($qr !== ''): ?><div class="qr"><img src="<?= $h($qr) ?>" alt="WhatsApp QR" width="150" height="150"></div><?php endif; ?>
    <p class="note">दर्ता गरिएको नम्बरबाट Send थिच्नुहोस्, जवाफमा YES पठाउनुहोस्, अनि यहाँ फर्कनुहोस् · Send it from your REGISTERED number, reply YES, then come back.</p>
    <p class="wait" id="waWait">⏳ तपाईंको सन्देश पर्खँदै… · Waiting for your message…</p>
    <form method="post" action="/admin/login.php" id="waDone"><?= $hid() ?><input type="hidden" name="mode" value="c_hi_done">
      <button class="btn alt" type="submit">✅ मैले पठाएँ · I have sent it</button></form>
  <?php else: ?>
    <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="c_hi">
      <button class="btn wa" type="submit">💬 दर्ता गरिएको WhatsApp बाट hi पठाउने · Send hi from your registered WhatsApp<small>कोड टाइप गर्नु पर्दैन · No code to type</small></button></form>
  <?php endif; ?>
  <div class="or">वा कोड · or a code</div>
  <?php if (!empty($cs['debug']) && APP_DEBUG): ?><div class="msg ok">DEV code: <?= $h($cs['debug']) ?></div><?php endif; ?>
  <?php if (!empty($cs['sent'])): ?>
  <form method="post" action="/admin/login.php" autocomplete="off"><?= $hid() ?><input type="hidden" name="mode" value="c_otp">
    <label for="cotp">WhatsApp कोड · 6-digit code</label>
    <input id="cotp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required placeholder="______" style="letter-spacing:.35em;text-align:center;font-size:22px">
    <button class="btn" type="submit">पुष्टि गर्नुहोस् · Confirm</button></form>
  <?php endif; ?>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="c_code_send">
    <button class="btn alt" type="submit">📩 <?= !empty($cs['sent']) ? 'नयाँ कोड · Send a new code' : 'दर्ता गरिएको WhatsApp मा ६ अङ्कको कोड · Get a 6-digit code' ?></button></form>
  <?= $officeBox() ?>
  <div class="links"><a href="<?= $h($odUrl(['step' => (($cs['purpose'] ?? '') === 'reset' ? 'c_forgot' : 'c_setup')])) ?>">← विवरण फेरि · Re-enter details</a></div>

  <?php elseif ($step === 'c_create'): ?>
  <?= $codeLine() ?>
  <p class="note">यो PIN / pattern कसैलाई नदेखाउनुहोस् — फोनमा सेभ हुँदैन · Keep it to yourself; it is never saved on the phone.</p>
  <div class="qk" data-qk>
    <div class="qk-tabs" role="tablist">
      <button type="button" class="on" data-qk-tab="pin">🔢 PIN</button>
      <button type="button" data-qk-tab="pattern">◯ Pattern</button>
    </div>
    <div class="cl-len" data-cl-len role="group" aria-label="PIN length"><button type="button" class="on" data-l="4">४ अङ्क · 4</button><button type="button" data-l="6">६ अङ्क · 6</button></div>
    <?= $pinForm('c_create', 'अगाडि · Next →', false, 4) ?>
    <?= $patForm('c_create', 'कम्तीमा ४ थोप्ला जोड्नुहोस् · Join at least 4 dots', true) ?>
  </div>

  <?php elseif ($step === 'c_confirm'): $cpk = (string) ($cs['pend']['kind'] ?? 'pin'); ?>
  <?= $codeLine() ?>
  <div class="qk" data-qk>
    <?= $cpk === 'pattern'
        ? $patForm('c_confirm', 'उही pattern फेरि कोर्नुहोस् · Draw the same pattern again')
        : $pinForm('c_confirm', 'सेभ गरेर भित्र · Save & sign in', false, (int) ($cs['pend']['len'] ?? 6)) ?>
  </div>
  <p class="note"><?= $cpk === 'pattern' ? 'पक्का गर्न उही pattern फेरि · Same pattern once more' : 'पक्का गर्न उही PIN फेरि · Same PIN once more' ?></p>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'c_create'])) ?>">↺ फेरि सुरुदेखि · Start over</a></div>

  <?php elseif ($step === 'start' && $remembered !== null): ?>
  <?php /* 6 Nov 2026: this phone remembers the account — pattern / PIN first, like an Android lock screen. */ ?>
  <div class="lk-clock" id="lkClock" aria-hidden="true">--:--</div><div class="lk-date" id="lkDate"></div>
  <div class="who">🔒 <span><?= $h($remembered['name']) ?></span></div>
  <?php /* 13 Nov 2026 (mg19, owner: "lock screen ma password box nadekhiyos — PIN / pattern
           ma focus; natra WhatsApp OTP"): PIN / pattern lead; with neither, an agent /
           counter opens by WhatsApp ("hi" or a code to their own mobile); only an office
           account (which WhatsApp never opens) still sees the password box here. */
  $lkQuick = $remembered['pin'] || $remembered['pattern'];
  $lkOtp   = OneDoor::lockOtpOk($remembered);
  $lkHi    = $lkOtp && OneDoor::hiOn(); ?>
  <div class="qk" data-qk>
    <?php if ($remembered['pattern'] && $remembered['pin']): ?>
    <div class="qk-tabs" role="tablist">
      <button type="button" class="on" data-qk-tab="pattern">◯ Pattern</button>
      <button type="button" data-qk-tab="pin">🔢 PIN</button>
    </div>
    <?php endif; ?>
    <?php if ($remembered['pattern']): ?>
    <form method="post" action="/admin/login.php" data-qk-form="pattern">
      <?= $hid() ?><input type="hidden" name="mode" value="quick_dev"><input type="hidden" name="qk_kind" value="pattern"><input type="hidden" name="qk_val" value="">
      <div class="qk-pad" data-qk-pad aria-label="Pattern"></div>
      <p class="note">आफ्नो pattern कोर्नुहोस् · Draw your pattern</p>
    </form>
    <?php endif; ?>
    <?php if ($remembered['pin']): ?>
    <form method="post" action="/admin/login.php" data-qk-form="pin"<?= $remembered['pattern'] ? ' hidden' : '' ?>>
      <?= $hid() ?><input type="hidden" name="mode" value="quick_dev"><input type="hidden" name="qk_kind" value="pin">
      <label for="qkpin">PIN</label>
      <input id="qkpin" class="qk-pin" name="qk_val" type="password" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" autocomplete="off" required<?= $remembered['pattern'] ? '' : ' autofocus' ?>>
      <div class="lk-dots" hidden aria-hidden="true"></div>
      <div class="lk-keys" hidden><?php foreach (['1','2','3','4','5','6','7','8','9'] as $k): ?><button type="button" data-k="<?= $k ?>"><?= $k ?></button><?php endforeach; ?><button type="button" class="s" data-k="x" aria-label="Delete">⌫</button><button type="button" data-k="0">0</button><button type="button" class="go" data-k="ok" aria-label="Unlock">OK</button></div>
      <button class="btn" type="submit">PIN ले भित्र · Unlock</button>
    </form>
    <?php endif; ?>
    <?php if (!$lkQuick && $lkOtp): ?>
    <div class="lk-otp">
      <?php if ($lkHi): ?>
      <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="lock_hi">
        <button class="btn wa" type="submit">💬 WhatsApp मा hi पठाएर खोल्ने<small>एक ट्याप — कोड टाइप गर्नु पर्दैन · One tap, no code to type</small></button></form>
      <?php endif; ?>
      <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="lock_code">
        <button class="btn alt" type="submit">📩 WhatsApp मा ६ अङ्कको कोड · Get a code on WhatsApp</button></form>
      <p class="note">छिटो खोल्न भित्र गएर <b>म → लक: Pattern / PIN</b> राख्नुहोस् · Set a PIN or pattern inside (Me) to open faster.</p>
    </div>
    <?php elseif (!$lkQuick): ?>
    <form method="post" action="/admin/login.php" class="lk-pw">
      <?= $hid() ?><input type="hidden" name="mode" value="lock_pw">
      <label for="lkpw">पासवर्ड · Password</label>
      <input id="lkpw" name="password" type="password" autocomplete="current-password" required autofocus>
      <button class="btn" type="submit">🔓 खोल्नुहोस् · Unlock</button>
      <p class="note">छिटो खोल्न भित्र गएर <b>म → लक: Pattern / PIN</b> राख्न सक्नुहुन्छ · Optional: set a PIN or pattern inside, under Me.</p>
    </form>
    <?php endif; ?>
  </div>
  <div class="links">
    <?php if ($lkQuick && $lkOtp): /* forgot PIN: WhatsApp opens the door, then straight to "set a new PIN" */ ?>
    <form method="post" action="/admin/login.php" class="lk-forgot"><?= Security::csrfField() ?><input type="hidden" name="next" value="quick-unlock.php">
      <input type="hidden" name="mode" value="<?= $lkHi ? 'lock_hi' : 'lock_code' ?>">
      <button type="submit">❓ PIN / pattern बिर्सनुभयो? WhatsApp बाट खोल्ने · Forgot PIN? Open with WhatsApp</button></form>
    <?php endif; ?>
    <?php if ($lkQuick && QuickUnlock::secQuestion((int) $remembered['id']) !== ''): ?><a href="/admin/recover.php">🛡️ सुरक्षा प्रश्नले नयाँ PIN · New PIN by security question</a><?php endif; ?>
    <a href="<?= $h($odUrl(['step' => 'start'])) ?>&amp;other=1">🔑 पासवर्ड / अर्को खाता · Password or another account</a>
    <a href="/admin/login.php?forget=1&amp;other=1" style="color:#8A1F1F;font-weight:600">तपाईं होइन? यो फोनबाट हटाउने · Not you? Forget this phone</a>
  </div>

  <?php elseif ($step === 'start'): ?>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $hid() ?><input type="hidden" name="mode" value="who">
    <label for="who">मोबाइल नम्बर वा ID · Mobile number or ID</label>
    <div class="cc-row"><?= $ccSel($ccNow) ?>
      <input id="who" name="who" autocomplete="username" autocapitalize="characters" autocorrect="off" spellcheck="false" maxlength="60" required autofocus
             placeholder="98XXXXXXXX / SHG-29" value="<?= $h($prefill) ?>"></div>
    <button class="btn" type="submit">अगाडि बढ्नुहोस् · Continue →</button>
  </form>
  <?php if (Passkeys::ready()): ?>
    <div id="pkMsg" class="msg ok" data-cls="msg" hidden></div>
    <button type="button" class="btn pk" data-pk="login" data-pk-soft hidden>👆 फिंगरप्रिन्ट / Face ID ले भित्र · Fingerprint / Face ID</button>
  <?php endif; ?>

  <?php elseif ($step === 'mobile' || $step === 'id'): ?>
  <div class="who"><span><?= $h(OneDoor::masked($st)) ?></span> · <a href="<?= $h($odUrl(['step' => 'start'])) ?>">✎ बदल्नुहोस् · Change</a></div>
  <?php if ($step === 'mobile'): ?>
    <?php if ($hiOn): ?>
    <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="wa_start">
      <button class="btn wa" type="submit">💬 WhatsApp मा hi पठाएर भित्र · Sign in by sending hi<small>एक ट्याप — कोड टाइप गर्नु पर्दैन · One tap, no code to type</small></button></form>
    <?php endif; ?>
    <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="code_send">
      <button class="btn alt" type="submit">📩 WhatsApp मा ६ अङ्कको कोड मगाउनुहोस् · Get a 6-digit code</button></form>
    <div class="or">वा · or</div>
  <?php endif; ?>
  <?php if (QuickUnlock::on()): ?>
  <div class="qk" data-qk>
    <div class="qk-tabs" role="tablist">
      <button type="button" class="on" data-qk-tab="pattern">◯ Pattern</button>
      <button type="button" data-qk-tab="pin">🔢 PIN</button>
    </div>
    <form method="post" action="/admin/login.php" data-qk-form="pattern">
      <?= $hid() ?><input type="hidden" name="mode" value="quick"><input type="hidden" name="qk_kind" value="pattern"><input type="hidden" name="qk_val" value="">
      <div class="qk-pad" data-qk-pad aria-label="Pattern"></div>
      <p class="note">थोप्ला जोडेर आफ्नो pattern कोर्नुहोस् · Draw your pattern</p>
    </form>
    <form method="post" action="/admin/login.php" data-qk-form="pin" hidden>
      <?= $hid() ?><input type="hidden" name="mode" value="quick"><input type="hidden" name="qk_kind" value="pin">
      <label for="qkpin">PIN</label>
      <input id="qkpin" class="qk-pin" name="qk_val" type="password" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" autocomplete="off" required>
      <button class="btn" type="submit">PIN ले भित्र · Sign in with PIN</button>
    </form>
    <p class="note">PIN / pattern छैन? पासवर्डले भित्र गएर <b>🔐 PIN / Pattern</b> मा राख्नुहोस् · No PIN yet? Sign in with your password, then set one under 🔐 PIN / Pattern.</p>
  </div>
  <div class="or">वा पासवर्ड · or password</div>
  <?php endif; ?>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $hid() ?><input type="hidden" name="mode" value="pw">
    <input type="text" name="username" value="<?= $h($kind === 'mobile' ? $st['phone'] : $st['id']) ?>" autocomplete="username" hidden aria-hidden="true" tabindex="-1">
    <label for="pw">पासवर्ड · Password</label>
    <input id="pw" name="password" type="password" autocomplete="current-password" required<?= $step === 'id' && !QuickUnlock::on() ? ' autofocus' : '' ?>>
    <button class="btn" type="submit"><?= $step === 'mobile' ? 'पासवर्डले भित्र · Sign in with password' : 'भित्र जानुहोस् · Sign in' ?></button>
  </form>
  <?php if ($step === 'mobile'): ?><p class="note">एउटै मोबाइलमा २-३ ID भए, जुन ID को पासवर्ड हो त्यही खुल्छ · One mobile with 2-3 IDs? Each ID's own password opens that ID.</p><?php endif; ?>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'forgot'])) ?>">🔑 पासवर्ड बिर्सनुभयो? · Forgot password?</a></div>

  <?php elseif ($step === 'code'): ?>
  <div class="who"><span><?= $h(OneDoor::masked($st)) ?></span> · <a href="<?= $h($odUrl(['step' => 'start'])) ?>">✎ बदल्नुहोस् · Change</a></div>
  <?php if ($notice === '' && $error === ''): ?><div class="msg ok">यो नम्बर दर्ता छ भने WhatsApp मा कोड पठाइयो · If this number is registered, a code is on its way on WhatsApp.</div><?php endif; ?>
  <form method="post" action="/admin/login.php" autocomplete="off">
    <?= $hid() ?><input type="hidden" name="mode" value="code_verify">
    <label for="otp">WhatsApp कोड · 6-digit code</label>
    <input id="otp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" required autofocus placeholder="______" style="letter-spacing:.35em;text-align:center;font-size:22px">
    <button class="btn" type="submit">पुष्टि गरेर भित्र · Verify &amp; sign in</button>
  </form>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="code_send">
    <button class="btn alt" type="submit">फेरि पठाउनुहोस् · Resend</button></form>
  <p class="note">अफिस खाता (म्यानेजर, एकाउन्ट, सपोर्ट) आफ्नै पासवर्डले खुल्छ · Office accounts open with their own password.</p>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'mobile'])) ?>">← पासवर्डले · Use the password</a></div>

  <?php elseif ($step === 'wa'): ?>
  <div class="who"><span><?= $h(OneDoor::masked($st)) ?></span></div>
  <a class="btn wa" href="<?= $h($waPoll['url']) ?>" target="_blank" rel="noopener" id="waGo">WhatsApp खोल्नुहोस् · Open WhatsApp<small><?= $h($waPoll['text']) ?></small></a>
  <?php if ($qr !== ''): ?><div class="qr"><img src="<?= $h($qr) ?>" alt="WhatsApp QR" width="150" height="150"><p class="note">फोनको क्यामेराले स्क्यान गर्नुहोस् · Scan with your phone</p></div><?php endif; ?>
  <p class="note">Send थिचेर, जवाफमा YES पठाएर यहाँ फर्कनुहोस् · Tap Send, reply YES, then come back</p>
  <p class="wait" id="waWait">⏳ तपाईंको सन्देश पर्खँदै… · Waiting for your message…</p>
  <div class="msg warn">🔒 आफैं लगइन गर्दा मात्र पठाउनुहोस् — अरूले मागे नपठाउनुहोस् · Send it only when YOU are signing in.</div>
  <form method="post" action="/admin/login.php" id="waDone"><?= $hid() ?><input type="hidden" name="mode" value="wa_finish">
    <button class="btn alt" type="submit">✅ मैले पठाएँ · I have sent it</button></form>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'mobile'])) ?>">← फिर्ता · Back</a></div>

  <?php elseif ($step === 'pick'): ?>
  <p class="sub">यो मोबाइलमा एकभन्दा बढी ID छन् — खोल्ने ID छान्नुहोस् · This mobile has more than one ID — choose one.</p>
  <?php foreach (OneDoor::pickList() as $pk): ?>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="pick"><input type="hidden" name="id" value="<?= (int) $pk['id'] ?>">
    <button class="btn pick" type="submit"><span><?= $h($pk['name']) ?></span><small><?= $h(($pk['code'] ?? '') !== '' ? $pk['code'] : '#' . $pk['id']) ?> · <?= $h($roleLbl((string) $pk['role'])) ?></small></button></form>
  <?php endforeach; ?>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'start'])) ?>">← फेरि सुरु · Start again</a></div>

  <?php elseif ($step === 'admin_otp'): ?>
  <form method="post" action="/admin/login.php" autocomplete="off">
    <?= $hid() ?><input type="hidden" name="mode" value="admin_2fa_verify">
    <label for="aotp">WhatsApp कोड · One-time code</label>
    <input id="aotp" name="otp" inputmode="numeric" pattern="[0-9]*" maxlength="8" required autofocus autocomplete="one-time-code" placeholder="______" style="letter-spacing:.35em;text-align:center;font-size:22px">
    <button class="btn" type="submit">पुष्टि गरेर भित्र · Verify &amp; sign in</button>
  </form>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="admin_2fa_resend">
    <button class="btn alt" type="submit" formnovalidate>फेरि पठाउनुहोस् · Resend code</button></form>

  <?php elseif ($step === 'forgot'): ?>
  <p class="sub">दर्ता गरेको मोबाइल नम्बर लेख्नुहोस् — WhatsApp ले पुष्टि गर्छ, अनि नयाँ पासवर्ड राख्नुहोस् · Type your registered mobile; WhatsApp confirms it is you, then set a new password.</p>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $hid() ?>
    <label for="fm">मोबाइल नम्बर · Mobile number</label>
    <div class="cc-row"><?= $ccSel($ccNow) ?>
      <input id="fm" name="who" type="tel" inputmode="tel" autocomplete="tel" maxlength="20" required autofocus placeholder="98XXXXXXXX"
             value="<?= $h($reset['phone'] ?? ($kind === 'mobile' ? $st['phone'] : '')) ?>"></div>
    <button class="btn" type="submit" name="mode" value="forgot_send">📩 WhatsApp मा कोड पठाउनुहोस् · Send code</button>
    <?php if ($hiOn): ?><button class="btn wa" type="submit" name="mode" value="forgot_hi_first">💬 hi पठाएर पुष्टि · Send hi instead<small>कोड टाइप गर्नु पर्दैन · No code to type</small></button><?php endif; ?>
  </form>
  <p class="note">अफिस खाता (म्यानेजर, एकाउन्ट, एडमिन) को पासवर्ड WhatsApp बाट बदलिँदैन — सुपर एडमिनलाई भन्नुहोस् · Office accounts (manager, accounts, admin) are reset by a super admin, not by WhatsApp.</p>
  <div class="links"><a href="<?= $h($odUrl(['step' => 'start'])) ?>">← लगइन · Sign in</a></div>

  <?php elseif ($step === 'forgot_code'): ?>
  <div class="who"><span>+<?= $h(TeamPortal::cc($reset['cc'] ?? '91')) ?> <?= $h(substr((string) $reset['phone'], 0, 2) . '•••••' . substr((string) $reset['phone'], -3)) ?></span> · <a href="<?= $h($odUrl(['step' => 'forgot'])) ?>">✎ बदल्नुहोस् · Change</a></div>
  <div class="msg warn" role="note">🔒 यो पासवर्ड रिसेटको लागि हो — अरूले मागे कोड वा सन्देश नपठाउनुहोस् · PASSWORD RESET only — never send it because someone else asked.</div>
  <?php if (!empty($reset['debug']) && APP_DEBUG): ?><div class="msg ok">DEV code: <?= $h($reset['debug']) ?></div><?php endif; ?>
  <?php if (is_array($reset['wa'] ?? null)): ?>
    <a class="btn wa" href="<?= $h($reset['wa']['url']) ?>" target="_blank" rel="noopener">WhatsApp खोल्नुहोस् · Open WhatsApp<small><?= $h($reset['wa']['text']) ?></small></a>
    <?php if ($qr !== ''): ?><div class="qr"><img src="<?= $h($qr) ?>" alt="WhatsApp QR" width="150" height="150"></div><?php endif; ?>
    <p class="wait" id="waWait">⏳ तपाईंको सन्देश पर्खँदै… · Waiting for your message…</p>
    <form method="post" action="/admin/login.php" id="waDone"><?= $hid() ?><input type="hidden" name="mode" value="forgot_prove"><input type="hidden" name="code" value="">
      <button class="btn alt" type="submit">✅ मैले पठाएँ · I have sent it</button></form>
    <div class="or">वा कोड · or the code</div>
  <?php endif; ?>
  <form method="post" action="/admin/login.php" autocomplete="off">
    <?= $hid() ?><input type="hidden" name="mode" value="forgot_prove">
    <label for="fc">WhatsApp कोड · 6-digit code</label>
    <input id="fc" name="code" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" placeholder="______" style="letter-spacing:.35em;text-align:center;font-size:22px"<?= is_array($reset['wa'] ?? null) ? '' : ' autofocus required' ?>>
    <button class="btn" type="submit">पुष्टि गर्नुहोस् · Confirm</button>
  </form>
  <?php if ($hiOn && !is_array($reset['wa'] ?? null)): ?>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="forgot_hi">
    <button class="btn wa" type="submit">💬 कोड आएन? hi पठाउनुहोस् · No code? Send hi</button></form>
  <?php endif; ?>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="forgot_send"><input type="hidden" name="who" value="<?= $h($reset['phone']) ?>"><input type="hidden" name="cc" value="<?= $h(TeamPortal::cc($reset['cc'] ?? '91')) ?>">
    <button class="btn alt" type="submit">📩 नयाँ कोड पठाउनुहोस् · Send a new code</button></form>
  <p class="note">कोड र hi दुवैले भएन भने देश (+91 / +977) मिलाएर हेर्नुहोस्, नत्र कार्यालयमा फोन गर्नुहोस् · Neither works? Check the country (+91 / +977), or call the office.</p>

  <?php elseif ($step === 'forgot_pick'): ?>
  <p class="sub">नम्बर पुष्टि भयो ✅ — कुन ID को पासवर्ड बदल्ने? · Number confirmed. Whose password?</p>
  <?php foreach (TeamPortal::resetChoices() as $c): ?>
  <form method="post" action="/admin/login.php"><?= $hid() ?><input type="hidden" name="mode" value="forgot_pick"><input type="hidden" name="who_id" value="<?= $h($c['who']) ?>">
    <button class="btn pick" type="submit"><span><?= $h($c['name']) ?></span><small><?= $h($c['code'] !== '' ? $c['code'] : '#' . substr((string) $c['who'], strpos((string) $c['who'], ':') + 1)) ?> · <?= $h($roleLbl((string) $c['role'])) ?></small></button></form>
  <?php endforeach; ?>
  <p class="note">छानेको ID को मात्र पासवर्ड बदलिन्छ · Only the ID you choose gets the new password.</p>

  <?php elseif ($step === 'forgot_pw'): $fw = OneDoor::forgotWho(); ?>
  <div class="who"><span><?= $h($fw['name']) ?> · <?= $h($fw['code'] !== '' ? $fw['code'] : $roleLbl((string) $fw['role'])) ?></span></div>
  <form method="post" action="/admin/login.php" autocomplete="on">
    <?= $hid() ?><input type="hidden" name="mode" value="forgot_set">
    <input type="text" name="username" value="<?= $h($reset['phone'] ?? '') ?>" autocomplete="username" hidden aria-hidden="true" tabindex="-1">
    <label for="np">नयाँ पासवर्ड · New password (<?= TeamPortal::PW_MIN ?>+)</label>
    <input id="np" name="pw" type="password" minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password" required autofocus>
    <label for="np2">फेरि लेख्नुहोस् · Again</label>
    <input id="np2" name="pw2" type="password" minlength="<?= TeamPortal::PW_MIN ?>" autocomplete="new-password" required>
    <button class="btn" type="submit">सेभ गरेर भित्र · Save &amp; sign in</button>
  </form>
  <?php if ((bool) ($fw['twofa'] ?? false)): ?><p class="note">यो खातामा 2FA छ — सेभपछि WhatsApp कोड पनि चाहिन्छ · This account uses 2FA: a WhatsApp code follows.</p><?php endif; ?>
  <?php endif; ?>

  <?php if (!in_array($step, ['forgot', 'forgot_code', 'forgot_pick', 'forgot_pw', 'wa', 'c_code', 'c_unlock', 'c_setup', 'c_forgot', 'c_prove', 'c_create', 'c_confirm'], true)): ?>
  <div class="links">
    <?php if ($step === 'start' && $codeFirst): ?><a href="<?= $h($odUrl(['step' => 'c_code', 'classic' => false])) ?>">🪪 कोड + PIN / Pattern ले भित्र · Sign in with code + PIN</a><?php endif; ?>
    <?php if ($step === 'start'): ?><a href="<?= $h($odUrl(['step' => 'forgot'])) ?>">🔑 पासवर्ड बिर्सनुभयो? · Forgot password?</a><?php endif; ?>
    <a href="<?= $h(rtrim(APP_URL, '/')) ?>/">🎫 यात्री? लगइन बिना टिकट बुक गर्नुहोस् · Passenger? Book without signing in →</a>
    <a href="<?= $h(rtrim(APP_URL, '/')) ?>/guide/?v=agent">▶ एजेन्ट गाइड भिडियो — लगइन, टिकट, चलानी, पैसा निकाल्ने · Agent guide video</a>
  </div>
  <?php endif; ?>
  <?php if (!in_array($step, ['c_code', 'c_unlock', 'c_create', 'c_confirm'], true)): ?><p class="note">📞 मद्दत · Help: <a href="tel:+<?= $h($helpTel) ?>" style="white-space:nowrap"><?= $h($helpPhone) ?></a></p><?php endif; ?>
</main>
<?php if ($waPoll !== null): ?>
<script>
(function () {
  var tok = <?= json_encode((string) $waPoll['token']) ?>, csrf = <?= json_encode($csrfTok) ?>, n = 0, sent = false;
  var wait = document.getElementById('waWait'), done = document.getElementById('waDone');
  function tick() {
    if (sent || ++n > 200) return;
    fetch('/api/wa-verify.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ action: 'check', token: tok }) })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.status === 'verified') { sent = true; if (wait) wait.textContent = '✅ प्रमाणित — भित्र जाँदै… · Verified, signing in…'; if (done) done.submit(); return; }
        if (d && d.status === 'confirm' && wait) wait.textContent = '📩 WhatsApp को जवाफमा YES लेखेर पठाउनुहोस् · Now reply YES in WhatsApp.';
        if (d && d.status === 'mismatch' && wait) wait.textContent = '❌ अर्को नम्बरबाट आयो — दर्ता गरेको नम्बरको WhatsApp बाट पठाउनुहोस् · It came from a different number.';
        if (d && d.status === 'expired') { if (wait) wait.textContent = 'पुरानो भयो — फेरि सुरु गर्नुहोस् · Expired, please start again.'; return; }
        setTimeout(tick, 3000);
      }).catch(function () { setTimeout(tick, 5000); });
  }
  setTimeout(tick, 2500);
  document.addEventListener('visibilitychange', function () { if (!document.hidden && !sent) { n = Math.max(0, n - 1); } });
})();
</script>
<?php endif; ?>
<?php if ($step === 'start' && Passkeys::ready()): ?><script src="/assets/js/team-passkey.js?v=20261115c" data-next="<?= $h($odUrl()) ?>"></script><?php endif; ?>
<?php $cPad = in_array($step, ['c_unlock', 'c_create', 'c_confirm'], true); ?>
<?php if (($remembered ?? null) !== null || $cPad): ?><script>
(function(){
  var c=document.getElementById('lkClock'),d=document.getElementById('lkDate');
  function tick(){var n=new Date();if(c)c.textContent=('0'+n.getHours()).slice(-2)+':'+('0'+n.getMinutes()).slice(-2);
    if(d)d.textContent=n.toLocaleDateString(undefined,{weekday:'long',day:'numeric',month:'long'});}
  if(c){tick();setInterval(tick,15000);}
  if(document.querySelector('.msg.bad')){document.body.classList.add('shake');if(navigator.vibrate)try{navigator.vibrate(120)}catch(e){}}
  /* 15 Nov 2026: every PIN form with a keypad (lock screen, code door, choose / confirm).
     The digits live only in this form until it is posted; nothing goes to browser storage. */
  var pads=[];
  document.querySelectorAll('form[data-qk-form="pin"]').forEach(function(f){
    var inp=f.querySelector('input[name="qk_val"]'),dots=f.querySelector('.lk-dots'),keys=f.querySelector('.lk-keys'),busy=false;
    if(!inp||!dots||!keys)return;
    var len=parseInt(f.getAttribute('data-len')||'0',10)||0;   // 4 or 6: that many boxes, opens by itself when full (0 = older PIN: 6 boxes + OK)
    inp.type='hidden';inp.removeAttribute('required');var lab=f.querySelector('label');if(lab)lab.hidden=true;
    var old=f.querySelector('button.btn');if(old)old.hidden=true;dots.hidden=false;keys.hidden=false;
    var okb=keys.querySelector('[data-k="ok"]');
    function max(){return len||6;}
    function draw(){var h='';for(var i=0;i<max();i++)h+='<i'+(i<inp.value.length?' class="on"':'')+(!len&&i>3&&inp.value.length<=i?' style="opacity:.35"':'')+'></i>';dots.innerHTML=h;if(okb)okb.style.visibility=len?'hidden':'';}
    function send(){if(busy||inp.value.length<(len||4))return;busy=true;dots.classList.add('done');setTimeout(function(){f.submit();},len?180:0);}
    function press(k){if(busy)return;if(k==='x')inp.value=inp.value.slice(0,-1);else if(k==='ok'){send();return;}
      else if(inp.value.length<max())inp.value+=k;draw();if(inp.value.length===max())send();}
    f.setLen=function(l){len=l;inp.value='';draw();};
    keys.addEventListener('click',function(e){var b=e.target.closest('button');if(b)press(b.getAttribute('data-k'));});
    pads.push({f:f,press:press});draw();
  });
  document.querySelectorAll('[data-cl-len] button').forEach(function(b){b.addEventListener('click',function(){
    b.parentNode.querySelectorAll('button').forEach(function(x){x.classList.toggle('on',x===b);});
    var f=document.querySelector('form[data-qk-form="pin"]');if(f&&f.setLen)f.setLen(parseInt(b.getAttribute('data-l'),10));});});
  document.querySelectorAll('[data-qk-tab]').forEach(function(t){t.addEventListener('click',function(){var g=document.querySelector('[data-cl-len]');if(g)g.hidden=t.getAttribute('data-qk-tab')!=='pin';});});
  document.addEventListener('keydown',function(e){
    var t=e.target;if(t&&(t.tagName==='INPUT'||t.tagName==='TEXTAREA'))return;
    pads.forEach(function(p){if(p.f.hidden)return;if(/^\d$/.test(e.key))p.press(e.key);else if(e.key==='Backspace')p.press('x');else if(e.key==='Enter')p.press('ok');});
  });
})();
</script><?php endif; ?>
<?php if ((($step === 'mobile' || $step === 'id') && QuickUnlock::on()) || ($remembered ?? null) !== null || $cPad): ?><script src="/assets/js/quick-unlock.js?v=20261101a"></script><?php endif; ?>

</body></html>
<?php
    exit;
}

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
    } elseif (($mode === 'agent_request' || $mode === 'agent_verify') && trim($agentCode) === '') {
        // An empty code is the mobile-only door (one number, several IDs → a chooser),
        // which lives on /me/; this page opens exactly the one account the code names.
        $step  = $mode === 'agent_verify' ? 'otp' : 'start';
        $error = 'Enter your agent code. (One mobile with several IDs? Sign in at /me/ and pick the ID.)';
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
        if ($result['ok'] && empty($result['choose'])) {
            Response::redirect('admin/' . login_destination($safeNext));
        }
        if (!empty($result['choose'])) {
            // ok + choose = the number was proved but NO session was opened; never treat it as signed in.
            unset($_SESSION['ap_login_pick']);
            $step  = 'start';
            $error = 'Enter your agent code. (One mobile with several IDs? Sign in at /me/ and pick the ID.)';
        } else {
            $step  = 'otp';
            $error = $result['error'] ?? 'That code is not right.';
        }
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
/* 28 Oct 2026 (owner: fingerprint is the MAIN sign-in): the phone-lock hero door */
.pk-hero{display:flex;align-items:center;gap:13px;width:100%;margin:2px 0 4px;padding:16px 18px;border:0;border-radius:16px;color:#fff;text-align:left;cursor:pointer;min-height:66px;background:linear-gradient(120deg,#ffa24d,#f07a1e 55%,#d4570c) !important;box-shadow:inset 0 1px 0 rgba(255,255,255,.45),0 4px 0 #b3540a,0 8px 0 #93430a,0 14px 22px -6px rgba(210,87,12,.5)}
.pk-hero:hover{filter:brightness(1.04)}
.pk-hero:active{transform:translateY(3px);box-shadow:inset 0 3px 7px rgba(0,0,0,.28),0 1px 0 #b3540a}
.pk-hero .pk-ic{font-size:30px;line-height:1;filter:drop-shadow(0 2px 3px rgba(0,0,0,.3))}
.pk-hero .pk-tx{min-width:0}
.pk-hero b{display:block;font-size:16px;font-weight:900;line-height:1.15}
.pk-hero small{display:block;font-size:11.5px;font-weight:700;opacity:.93;margin-top:3px}
.or{text-align:center;font-size:11.5px;color:#8a94a6;margin:12px 0 2px;display:flex;align-items:center;gap:10px}
.or::before,.or::after{content:'';flex:1;border-top:1px solid #e8ecf4}
@media (prefers-reduced-motion:reduce){.pk-hero:active{transform:none}}
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
    $o  = '<div class="doors">';
    $o .= '<div id="pkMsg" class="ok" data-cls="note" hidden></div><div id="faceMsg" class="ok" data-cls="note" hidden></div>';
    // 28 Oct 2026 (owner: fingerprint is the MAIN sign-in; password last): the phone-lock door leads, one tap, no mobile needed
    if (Passkeys::ready()) {
        $o .= '<button type="button" class="pk-hero" data-pk="login" autofocus><span class="pk-ic" aria-hidden="true">👆</span><span class="pk-tx"><b>फिंगरप्रिन्ट / Face ID ले भित्र</b><small>एक ट्याप — पासवर्ड चाहिँदैन · One tap, no password</small></span></button>';
        $o .= '<p id="pkNo" class="note" hidden>👆 यो डिभाइसमा फिंगरप्रिन्ट / Face ID छैन — तलको मोबाइल वा पासवर्डले भित्र जानुहोस्। · No phone lock here — use the mobile or password below.</p>';
        $o .= '<div class="or">अन्य तरिका · or other ways</div>';
    }
    $o .= '<label for="m">मोबाइल नम्बर · Registered mobile</label>';
    $o .= '<div class="cc-row"><select name="cc" aria-label="Country"><option value="91">🇮🇳 +91</option><option value="977">🇳🇵 +977</option></select>';
    $o .= '<input id="m" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="14" placeholder="98XXXXXXXX"></div>';
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
<?php if (Passkeys::ready()): ?><script src="/assets/js/team-passkey.js?v=20261115c" data-next="<?= $meUrl ?>"></script><?php endif; ?>
<?php endif; ?>
</body></html>
