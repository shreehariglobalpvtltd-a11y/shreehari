<?php
/**
 * agent-signup.php — Public agent self-registration.
 *
 * An interested counter-agent fills this form. Their application lands as a
 * pending (is_active = 0) agent account. The superadmin reviews it in
 * Admin → Staff and either approves (activates + assigns SHG-XXX code) or
 * deletes it. On approval a WhatsApp message is sent automatically with the
 * agent code and login instructions.
 *
 * No session, no auth — this page is intentionally public.
 */
declare(strict_types=1);
define('SHG_APP', true);
require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDE_PATH . '/agentwallet.php';   // AgentWallet::normaliseKind / cleanPhone / kindLabel

$flash   = null;
$success = false;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    /* Rate limit BEFORE any DB work (2026-08-29 §9). A public unauth POST
       that INSERTs into the `admins` table is a spam / DoS vector: three
       legitimate applications per IP per hour is far above what a single
       human ever needs, and one per phone per day stops IP-rotation abuse.
       Both throw a Security-owned 429 on breach so we never touch the DB. */
    try {
        Security::requireRateLimit('agent_signup_ip', Security::clientIp(), 3, 3600);
        $phoneForLimit = preg_replace('/\D/', '', $_POST['phone'] ?? '');
        if ($phoneForLimit !== '') {
            Security::requireRateLimit('agent_signup_phone', $phoneForLimit, 1, 86400);
        }
    } catch (Throwable $e) {
        $flash = 'Too many applications — please try again later.';
        goto skip_submit;
    }

    // 4 Sep 2026: a cross-site POST could plant pending agent accounts —
    // the form now carries the session CSRF token like every other form.
    if (!Security::verifyCsrf()) {
        $flash = 'Your session expired — please submit the form again.';
        goto skip_submit;
    }

    $name    = trim(Security::clean($_POST['full_name']  ?? '', 120));
    $phone   = preg_replace('/\D/', '', $_POST['phone']  ?? '');
    $city    = trim(Security::clean($_POST['city']       ?? '', 80));
    $address = trim(Security::clean($_POST['address']    ?? '', 255));
    $ref     = trim(Security::clean($_POST['referral']   ?? '', 20));
    // Agent CRM (5 Sep 2026): who is applying — a travel agency / counter
    // (organization, serial 1-20) or an individual (person, serial 21+).
    $kind    = AgentWallet::normaliseKind((string) ($_POST['agent_kind'] ?? 'person'));
    $contact = trim(Security::clean($_POST['contact_person'] ?? '', 120));
    $waNum   = AgentWallet::cleanPhone((string) ($_POST['whatsapp'] ?? ''));
    // 4 Sep 2026: agents sign in with email + username + password, so the
    // application captures the email the office will issue the login against.
    $emailRaw = trim((string) ($_POST['email'] ?? ''));
    $email    = Security::email($emailRaw);

    // Basic validation
    if (strlen($name) < 2) {
        $flash = 'Full name is required (at least 2 characters).';
    } elseif (strlen($phone) < 10 || strlen($phone) > 15) {
        $flash = 'Enter a valid 10-digit mobile number.';
    } elseif ($city === '') {
        $flash = 'Please enter your city / district.';
    } elseif ($email === '') {
        $flash = 'Enter a valid email address — it is part of your agent sign-in.';
    } elseif (Database::fetch('SELECT id FROM admins WHERE LOWER(email) = :e', ['e' => $email]) !== null) {
        $flash = 'That email is already registered. '
               . 'If you already have an account, sign in at '
               . '<a href="/admin/login.php?portal=agent">Agent Login</a>.';
    } elseif (Database::fetch('SELECT id FROM admins WHERE phone = :p', ['p' => $phone]) !== null) {
        $flash = 'This mobile number is already registered. '
               . 'If you already have an account, sign in at '
               . '<a href="/admin/login.php?portal=agent">Agent Login</a>.';
    } else {
        // username = phone (unique, no guessing needed for an agent-code login)
        $username = $phone;

        // Ensure username is unique in admins
        if (Database::fetch('SELECT id FROM admins WHERE username = :u', ['u' => $username]) !== null) {
            $username = 'agent_' . $phone;
        }

        /* An unguessable placeholder hash. An applicant never chooses their
           own password: the office issues one on approval (Agents -> Manage ->
           Login credentials), so until then nothing can sign in as them — the
           account is inactive as well. */
        $dummyHash = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT);

        $newId = Database::insert('admins', [
            'username'      => $username,
            'password_hash' => $dummyHash,
            'full_name'     => $name,
            'email'         => $email,
            'phone'         => $phone,
            'role'          => 'agent',
            'is_active'     => 0,           // pending approval
            'must_change_pw'=> 0,           // the office issues the password
        ]);

        // Store agent profile details in admin_profiles. The referral code
        // goes to notes (it used to be appended to the address).
        if ($newId) {
            try {
                Database::run(
                    "INSERT INTO admin_profiles (admin_id, display_phone, whatsapp, counter_name, agent_kind, contact_person, address, notes)
                     VALUES (:aid, :dp, :wa, :cn, :kind, :cp, :addr, :notes)
                     ON DUPLICATE KEY UPDATE display_phone=:dp2, whatsapp=:wa2, counter_name=:cn2, agent_kind=:kind2, contact_person=:cp2, address=:addr2, notes=:notes2",
                    [
                        'aid'  => $newId, 'dp' => $phone, 'wa' => $waNum ?: null, 'cn' => $city, 'kind' => $kind,
                        'cp'   => $contact ?: null, 'addr' => $address !== '' ? $address : null,
                        'notes' => $ref !== '' ? 'Referred by ' . $ref : null,
                        'dp2'  => $phone, 'wa2' => $waNum ?: null, 'cn2' => $city, 'kind2' => $kind,
                        'cp2'  => $contact ?: null, 'addr2' => $address !== '' ? $address : null,
                        'notes2' => $ref !== '' ? 'Referred by ' . $ref : null,
                    ]
                );
            } catch (Throwable $e) {
                // Non-fatal — the admins record was created; profile details can be set later
            }
        }

        // Notify superadmin via WhatsApp if possible
        try {
            $co   = Settings::getString('company_name', APP_NAME);
            $wa   = Settings::getString('whatsapp_notify_number', '');
            $msg  = "🟡 *New Agent Application — {$co}*\n"
                  . "Name: {$name}\nType: " . AgentWallet::kindLabel($kind) . ($contact !== '' ? " (contact: {$contact})" : '') . "\nPhone: {$phone}\nCity: {$city}\n"
                  . ($ref ? "Referral: {$ref}\n" : '')
                  . "Review: " . rtrim(APP_URL, '/') . "/admin/staff.php";
            if ($wa !== '') {
                Notify::sendWhatsApp($wa, $msg);
            }
        } catch (Throwable $e) { /* non-fatal */ }

        $success = true;
    }
}
skip_submit:

$company = Settings::getString('company_name', APP_NAME);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Become an Agent · <?= Security::e($company) ?></title>
<meta name="robots" content="noindex,nofollow">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/img/favicon-32.png?v=20260827b">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700;800&display=swap">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{min-height:100vh;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;
     background:linear-gradient(135deg,#0C3B2A 0%,#178A50 100%);
     display:flex;flex-direction:column;align-items:center;justify-content:flex-start;padding:30px 16px}

/* ── Header ── */
.brand{text-align:center;margin-bottom:28px}
.brand img.logo{width:64px;height:64px;object-fit:contain;filter:drop-shadow(0 8px 20px rgba(0,0,0,.4));margin-bottom:10px}
.brand img.ceo{width:76px;height:76px;border-radius:50%;object-fit:cover;object-position:center top;border:3px solid #F07C1F;box-shadow:0 6px 20px rgba(0,0,0,.35);margin-bottom:8px}
.brand h1{font-family:'Cinzel',Georgia,serif;font-size:20px;color:#FFF;font-weight:800;letter-spacing:.03em;line-height:1.2}
.brand p{color:rgba(255,255,255,.75);font-size:13px;margin-top:4px}

/* ── Card ── */
.card{background:#fff;width:100%;max-width:440px;border-radius:20px;
      box-shadow:0 24px 60px rgba(0,0,0,.4);padding:32px 28px}

/* ── Benefits strip ── */
.benefits{display:flex;flex-direction:column;gap:8px;margin-bottom:24px;
          background:#F0FBF4;border:1px solid #C3E8D0;border-radius:12px;padding:14px 16px}
.benefits h2{font-size:14px;font-weight:800;color:#0C3B2A;margin-bottom:6px}
.ben{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#2a5a3a;line-height:1.4}
.ben-ico{flex:0 0 auto;font-size:15px}

/* ── Form ── */
label{display:block;font-size:13px;font-weight:700;color:#1b2436;margin:14px 0 5px}
label span{color:#C00;font-size:11px}
input,select,textarea{width:100%;padding:12px 14px;border:1.5px solid #dde3ee;border-radius:10px;
     font-size:15px;min-height:46px;transition:border-color .15s,box-shadow .15s;
     background:#FAFBFD;color:#1b2436}
input:focus,select:focus,textarea:focus{outline:none;border-color:#178A50;box-shadow:0 0 0 3px rgba(23,138,80,.14)}
textarea{min-height:70px;resize:vertical;font-size:14px}
.hint{font-size:11.5px;color:#8a9ab5;margin-top:3px}

/* ── Submit ── */
.submit-btn{width:100%;margin-top:22px;padding:15px;border:0;border-radius:12px;
            background:linear-gradient(135deg,#0C3B2A,#178A50);color:#fff;
            font-weight:800;font-size:16px;cursor:pointer;min-height:52px;
            letter-spacing:.02em;box-shadow:0 8px 20px rgba(23,138,80,.35);
            transition:filter .15s,transform .1s}
.submit-btn:hover{filter:brightness(1.06)}
.submit-btn:active{transform:scale(.98)}

/* ── Flash ── */
.flash-err{background:#fde8e8;color:#8a1f1f;padding:12px 14px;border-radius:10px;
           font-size:13.5px;font-weight:600;margin-bottom:16px;line-height:1.4}
.flash-err a{color:#8a1f1f}

/* ── Success ── */
.success-box{text-align:center;padding:28px 20px}
.success-ico{font-size:56px;margin-bottom:12px}
.success-box h2{font-family:'Cinzel',Georgia,serif;font-size:22px;color:#0C3B2A;font-weight:800;margin-bottom:10px}
.success-box p{color:#4a7a5a;font-size:14px;line-height:1.6;margin-bottom:8px}
.success-box .steps-next{background:#F0FBF4;border-radius:10px;padding:14px 16px;text-align:left;margin:16px 0;font-size:13px;color:#2a5a3a;line-height:1.8}
.success-box .steps-next b{color:#0C3B2A}

/* ── Footer ── */
.foot{text-align:center;margin-top:18px;font-size:12px;color:rgba(255,255,255,.65);display:flex;gap:14px;justify-content:center;flex-wrap:wrap}
.foot a{color:rgba(255,255,255,.85);text-decoration:none}

/* ── Divider ── */
.divider{display:flex;align-items:center;gap:10px;margin:18px 0 6px;font-size:12px;color:#aab5c9}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e4e9f0}

/* Responsive */
@media(max-width:400px){.card{padding:24px 18px}.brand img.logo{width:54px;height:54px}}
</style>
</head>
<body>

<div class="brand">
  <div><img src="/assets/img/logo.png" class="logo" alt="<?= Security::e($company) ?>" width="64" height="64"></div>
  <div><img src="/assets/img/ceo.jpg" class="ceo" alt="CEO" onerror="this.style.display='none'"></div>
  <h1><?= Security::e($company) ?></h1>
  <p>Agent Registration · एजेंट रजिस्ट्रेशन</p>
</div>

<div class="card">

<?php if ($success): ?>
  <div class="success-box">
    <div class="success-ico">🎉</div>
    <h2>Application Submitted!</h2>
    <p>Thank you! Your agent application has been received and is under review.</p>
    <div class="steps-next">
      <b>Next steps:</b><br>
      ✅ Our team will verify your details.<br>
      📱 You'll receive your <b>Agent Code (SHG-XXX)</b> on WhatsApp.<br>
      🔑 Then sign in at <b>/admin/login.php?portal=agent</b> with your <b>email, username and password</b>.<br>
      💰 Track your sales &amp; commissions from the Agent Portal.
    </div>
    <p style="color:#888;font-size:12px">Approval typically takes 1–2 business days.</p>
    <a href="/" style="display:inline-block;margin-top:16px;padding:12px 28px;
       background:#178A50;color:#fff;border-radius:10px;text-decoration:none;font-weight:700;font-size:14px">
      ← Back to Home
    </a>
  </div>

<?php else: ?>

  <?php if ($flash !== null): ?>
    <div class="flash-err"><?= $flash ?></div>
  <?php endif; ?>

  <div class="benefits">
    <h2>🚌 Agent Benefits — एजेंट बेनिफिट्स</h2>
    <div class="ben"><span class="ben-ico">💰</span><span><b>₹200 commission</b> per passenger (direct bookings)</span></div>
    <div class="ben"><span class="ben-ico">🏆</span><span><b>₹400</b> for team/org bookings</span></div>
    <div class="ben"><span class="ben-ico">📊</span><span>Your own sales dashboard + collection view</span></div>
    <div class="ben"><span class="ben-ico">🎟️</span><span>Print tickets under your name (SHG-XXX)</span></div>
    <div class="ben"><span class="ben-ico">💸</span><span>Withdraw earnings anytime from Agent Portal</span></div>
  </div>

  <form method="post" novalidate>
    <input type="hidden" name="<?= Security::e(CSRF_TOKEN_NAME) ?>" value="<?= Security::e(Security::csrfToken()) ?>">
    <?php $kindSel = ($_POST['agent_kind'] ?? 'person') === 'org' ? 'org' : 'person'; ?>
    <label>I am applying as · म आवेदन गर्दैछु</label>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:6px 0 14px">
      <label style="display:flex;gap:8px;align-items:center;padding:12px;border:1.5px solid #dfe6f0;border-radius:12px;cursor:pointer;font-weight:600;margin:0">
        <input type="radio" name="agent_kind" value="person" <?= $kindSel === 'person' ? 'checked' : '' ?> onchange="var c=document.getElementById('cpWrap');if(c)c.style.display='none'"> 👤 Individual · व्यक्ति
      </label>
      <label style="display:flex;gap:8px;align-items:center;padding:12px;border:1.5px solid #dfe6f0;border-radius:12px;cursor:pointer;font-weight:600;margin:0">
        <input type="radio" name="agent_kind" value="org" <?= $kindSel === 'org' ? 'checked' : '' ?> onchange="var c=document.getElementById('cpWrap');if(c)c.style.display=''"> 🏢 Travel agency / organization
      </label>
    </div>

    <label for="fn">Full Name / Organization Name <span>*</span> · पूरा नाम</label>
    <input id="fn" name="full_name" type="text" required maxlength="120"
           placeholder="e.g. Ramesh Kumar Sharma / Krishna Travels"
           value="<?= Security::e($_POST['full_name'] ?? '') ?>">

    <div id="cpWrap" <?= $kindSel === 'org' ? '' : 'style="display:none"' ?>>
      <label for="cp">Contact Person · सम्पर्क व्यक्ति</label>
      <input id="cp" name="contact_person" type="text" maxlength="120"
             placeholder="who we should talk to at the agency"
             value="<?= Security::e($_POST['contact_person'] ?? '') ?>">
    </div>

    <label for="ph">Mobile Number <span>*</span> · मोबाइल नंबर</label>
    <input id="ph" name="phone" type="tel" required inputmode="numeric"
           maxlength="15" placeholder="10-digit number"
           value="<?= Security::e($_POST['phone'] ?? '') ?>">
    <div class="hint">Your Agent Code comes to this number on WhatsApp.</div>

    <label for="em">Email <span>*</span> · इमेल</label>
    <input id="em" name="email" type="email" required maxlength="191"
           placeholder="you@example.com" autocomplete="email"
           value="<?= Security::e($_POST['email'] ?? '') ?>">
    <div class="hint">Part of your sign-in: you will log in with this email, a username and a password the office gives you.</div>

    <label for="wa">WhatsApp Number · व्हाट्सएप <span style="color:#888;font-weight:400">(only if different)</span></label>
    <input id="wa" name="whatsapp" type="tel" inputmode="numeric" maxlength="15"
           placeholder="leave blank if same as mobile"
           value="<?= Security::e($_POST['whatsapp'] ?? '') ?>">

    <label for="ci">City / District <span>*</span> · शहर/जिला</label>
    <input id="ci" name="city" type="text" required maxlength="80"
           placeholder="e.g. Surat, Mehsana, Ahmedabad…"
           value="<?= Security::e($_POST['city'] ?? '') ?>">

    <label for="ad">Address · पता <span style="color:#888;font-weight:400">(optional)</span></label>
    <textarea id="ad" name="address" maxlength="255"
              placeholder="Shop/counter address (optional)"><?= Security::e($_POST['address'] ?? '') ?></textarea>

    <div class="divider">Already an agent's contact?</div>

    <label for="ref">Referral Agent Code <span style="color:#888;font-weight:400">(optional)</span></label>
    <input id="ref" name="referral" type="text" maxlength="20" placeholder="e.g. SHG-027"
           value="<?= Security::e($_POST['referral'] ?? '') ?>">
    <div class="hint">If someone referred you, enter their SHG code here.</div>

    <button type="submit" class="submit-btn">
      🚀 Submit Application · आवेदन दें
    </button>
  </form>

<?php endif; ?>

</div>

<div class="foot">
  <a href="/">← Customer Booking</a>
  <a href="/admin/login.php?portal=agent">Agent Login 🎟️</a>
  <span><?= Security::e($company) ?></span>
</div>

</body></html>
