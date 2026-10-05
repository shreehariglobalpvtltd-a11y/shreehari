<?php
/**
 * admin/settings.php — edit the live configuration held in the settings
 * table. Only superadmin/manager reach this. Unknown keys are ignored by
 * Settings::setMany, so the form can never create stray rows.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin = admin_boot('dashboard.view');

// Writing settings is a privileged action.
$canEdit = Auth::canManageSettings();

$flash = null;

/* ── CEO Photo upload (separate from the settings form) ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($canEdit) && isset($_FILES['ceo_photo'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $f = $_FILES['ceo_photo'];
        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        $mime = mime_content_type($f['tmp_name'] ?? '') ?: '';
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $flash = ['bad', 'Upload failed (error ' . (int)$f['error'] . ').'];
        } elseif (!isset($allowed[$mime])) {
            $flash = ['bad', 'Only JPG, PNG, or WebP images are accepted.'];
        } elseif (($f['size'] ?? 0) > 5 * 1024 * 1024) {
            $flash = ['bad', 'Image too large — maximum 5 MB.'];
        } else {
            // Always save as ceo.jpg for simplicity (browsers cache by filename)
            $dest = dirname(__DIR__) . '/assets/img/ceo.jpg';
            if ($mime === 'image/png' || $mime === 'image/webp') {
                // Convert to JPEG if GD is available, else just copy
                if (function_exists('imagecreatefromstring')) {
                    $src = imagecreatefromstring(file_get_contents($f['tmp_name']));
                    if ($src !== false) {
                        imagejpeg($src, $dest, 92);
                        imagedestroy($src);
                    } else {
                        copy($f['tmp_name'], $dest);
                    }
                } else {
                    copy($f['tmp_name'], $dest);
                }
            } else {
                copy($f['tmp_name'], $dest);
            }
            Logger::audit('settings.ceo_photo', 'admin', (string)($admin['username'] ?? ''), null, null, 'CEO photo updated');
            $flash = ['ok', 'CEO photo updated! It will appear on the splash screen and agent login page. Hard-refresh to see it.'];
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $canEdit && !isset($_FILES['ceo_photo'])) {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again.'];
    } else {
        $pairs = [];
        foreach (($_POST['s'] ?? []) as $key => $val) {
            $val = is_string($val) ? $val : (string) $val;
            /* Secrets are write-only: the form never carries the stored
               value, so an empty field means "keep what is there" and the
               word CLEAR means "remove it". Anything else is a new secret. */
            // 5 Oct 2026: the same test the form uses to hide a secret (it also
            // covers *_private / private_key — push_vapid_private was posted
            // blank by the masked field and wiped on every Settings save).
            if (Settings::isSecretKey((string) $key)) {
                if (trim($val) === '') { continue; }
                if (trim($val) === 'CLEAR') { $val = ''; }
            }
            $pairs[(string) $key] = $val;
        }
        // Checkboxes are absent from POST when unticked, so set every known
        // bool key explicitly from whether its box came through.
        foreach (explode(',', (string) ($_POST['_boolkeys'] ?? '')) as $bk) {
            $bk = trim($bk);
            if ($bk !== '') { $pairs[$bk] = isset($_POST['s'][$bk]) ? '1' : '0'; }
        }
        /* Fares panel (3 Sep 2026): the private-cabin prices live INSIDE the
           cabin_pricing JSON, so the two number fields are merged into it here
           rather than exposing the blob. Flat pricing: online == offline. */
        $fpSingle = isset($_POST['fp_private_single']) ? (float) $_POST['fp_private_single'] : 0.0;
        $fpDouble = isset($_POST['fp_private_double']) ? (float) $_POST['fp_private_double'] : 0.0;
        try {
            /* 4 Sep 2026: the private-cabin prices are merged into cabin_pricing
               AFTER the generic rows are saved, so an edited cabin_pricing
               textarea and the two number fields cannot overwrite each other
               (the number fields win, as they are the curated place). */
            Settings::setMany($pairs);
            if ($fpSingle > 0 || $fpDouble > 0) {
                Settings::flush();
                $cp = Settings::getArray('cabin_pricing', []);
                if (isset($cp['private']) && is_array($cp['private'])) {
                    if ($fpSingle > 0) { $cp['private']['single_1pax']['offline'] = $fpSingle; $cp['private']['single_1pax']['online'] = $fpSingle; }
                    if ($fpDouble > 0) { $cp['private']['double_2pax']['offline'] = $fpDouble; $cp['private']['double_2pax']['online'] = $fpDouble; }
                    Settings::set('cabin_pricing', $cp, 'json', 'pricing', true);
                }
            }
            /* The legacy in-app "Main-point fares" override (main_fares) would win
               over fare_to_* inside Fare::dirFares(); keep it in step when it exists. */
            if (isset($pairs['fare_to_nepal'], $pairs['fare_to_india']) && Settings::get('main_fares', null) !== null
                && (float) $pairs['fare_to_nepal'] > 0 && (float) $pairs['fare_to_india'] > 0) {
                Settings::set('main_fares', ['toNepal' => (float) $pairs['fare_to_nepal'], 'toIndia' => (float) $pairs['fare_to_india']], 'json', 'pricing', false);
            }
            Settings::flush();
            $flash = ['ok', 'Settings saved.'];
        } catch (InvalidArgumentException $e) {
            // A JSON textarea with a typo: nothing was written (the bulk write
            // is one transaction), so say which key and keep the old values.
            Settings::flush();
            $flash = ['bad', 'Not saved — ' . $e->getMessage() . '. Fix the JSON and press Save again.'];
        }
    }
}

$rows = Database::fetchAll('SELECT skey, svalue, stype, sgroup, label, is_public FROM settings ORDER BY sgroup, skey');
$groups = [];
foreach ($rows as $r) { $groups[$r['sgroup'] ?: 'general'][] = $r; }
ksort($groups);

$boolKeys = array_values(array_map(static fn($r) => $r['skey'],
    array_filter($rows, static fn($r) => $r['stype'] === 'bool')));

$csrf = Security::e(Security::csrfToken());
$k    = CSRF_TOKEN_NAME;

/* Oct 2026 redesign: each stored group gets a plain title and one sentence on
   what it controls. Unknown groups fall back to their stored name. */
$groupInfo = [
    'booking'    => ['Booking rules', 'Seat holds, limits and the discount switches every sale uses.'],
    'payment'    => ['Payment', 'How customers pay: UPI, eSewa, payment links and pay at boarding.'],
    'pricing'    => ['Fares (advanced)', 'Cabin prices and fare tables. Everyday fares are in "Fares & booking rules" above.'],
    'whatsapp'   => ['WhatsApp messaging', 'How tickets and notices are sent on WhatsApp (Meta Cloud API / Twilio).'],
    'aisensy'    => ['WhatsApp via AiSensy', 'The AiSensy sender used alongside or instead of the main WhatsApp line.'],
    'notify'     => ['Notifications', 'Which messages go out (ticket, reminders, office reports) and to whom.'],
    'company'    => ['Company details', 'Legal name, address, phone numbers and documents printed on tickets and chalani.'],
    'site'       => ['Website', 'What the public website shows: notices, contact numbers, features.'],
    'app'        => ['App', 'Customer app behaviour and switches.'],
    'agent'      => ['Agents', 'Agent sign-up, commission defaults and settlement.'],
    'agents'     => ['Agents', 'Agent sign-up, commission defaults and settlement.'],
    'ai'         => ['AI assistant', 'The Sahayak assistant on the website and in the office.'],
    'security'   => ['Access & security', 'Sign-in protection and who may do what.'],
    'reports'    => ['Reports', 'Scheduled office reports and what they include.'],
    'referral'   => ['Referrals', 'Customer referral rewards.'],
    'loyalty'    => ['Loyalty', 'Customer loyalty tiers and rewards.'],
    'realtime'   => ['Live updates', 'How often screens refresh seat and booking changes.'],
    'automation' => ['Automation', 'Background jobs: reminders, expiries, retries.'],
    'general'    => ['General', 'Everything else.'],
];

admin_header('Settings', 'settings');

if ($flash !== null) { echo '<div class="flash ' . $flash[0] . '">' . Security::e($flash[1]) . '</div>'; }
if (!$canEdit) { echo '<div class="flash bad">Your role can view settings but not change them.</div>'; }

$ceoPhoto = dirname(__DIR__) . '/assets/img/ceo.jpg';
?>
<nav class="sg set-jump" aria-label="Settings sections">
  <p class="set-lede">Each section says what it controls. Changes apply after <b>Save all settings</b>; the page then confirms with “Settings saved.” Pickup points and their fares are on <a href="routes.php#pickups">Routes → Pickup points &amp; fares</a>.</p>
  <div class="sg-chips">
    <a class="sg-chip" href="#faresPanel">Fares &amp; booking rules</a>
    <a class="sg-chip" href="routes.php#pickups">Pickup points &amp; fares</a>
    <a class="sg-chip" href="#agentRulesPanel">Agent rules</a>
    <?php foreach (array_keys($groups) as $g): ?><a class="sg-chip" href="#grp-<?= Security::e((string) $g) ?>"><?= Security::e($groupInfo[$g][0] ?? ucfirst((string) $g)) ?></a><?php endforeach; ?>
    <a class="sg-chip" href="#waTestPanel">Test messages</a>
  </div>
</nav>

<!-- ── CEO / Brand Photo Upload ── -->
<?php if ($canEdit): ?>
<div class="panel" style="border-left:4px solid #F07C1F">
  <h2>📸 CEO / Brand Photo</h2>
  <p class="muted" style="font-size:13px;margin-bottom:14px">
    This photo appears on the app splash screen and the agent/admin login page.
    Upload a clear portrait photo (JPG/PNG/WebP, max 5 MB). It is saved as
    <code>assets/img/ceo.jpg</code> — a hard refresh clears the browser cache.
  </p>
  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap">
    <?php if (file_exists($ceoPhoto)): ?>
      <img src="/assets/img/ceo.jpg?t=<?= filemtime($ceoPhoto) ?>" alt="CEO"
           style="width:80px;height:80px;border-radius:50%;object-fit:cover;object-position:center top;
                  border:3px solid #F07C1F;box-shadow:0 4px 14px rgba(0,0,0,.2)">
    <?php else: ?>
      <div style="width:80px;height:80px;border-radius:50%;background:#F0F4FF;border:2px dashed #ccc;
                  display:flex;align-items:center;justify-content:center;font-size:28px">👤</div>
    <?php endif; ?>
    <form method="post" enctype="multipart/form-data" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
      <input type="file" name="ceo_photo" accept="image/jpeg,image/png,image/webp" required
             style="font-size:14px">
      <button type="submit" class="btn btn-ok btn-sm">Upload Photo</button>
    </form>
  </div>
</div>
<?php endif; ?>

<form method="post">
  <input type="hidden" name="<?= $k ?>" value="<?= $csrf ?>">
  <input type="hidden" name="_boolkeys" value="<?= Security::e(implode(',', $boolKeys)) ?>">

  <?php
  /* -- Fares & booking rules (3 Sep 2026) ------------------------------
     One curated place for the numbers the office actually changes. The
     fare_to_* / max-seats / discount-cap fields post as normal s[key] rows
     (seeded by database/upgrade-2026-09-fares.php); the private-cabin prices
     are merged into the cabin_pricing JSON by the POST handler above. The
     generic key/value loop below skips these keys so nothing is shown twice. */
  $fpKeys   = ['fare_to_nepal', 'fare_to_india', 'max_seats_per_booking', 'counter_max_seats_per_booking', 'counter_max_discount_pct'];
  $fpHave   = static fn(string $key): bool => Settings::get($key, null) !== null;
  $dirFares = Fare::dirFares();
  $cpNow    = Settings::getArray('cabin_pricing', Fare::pricing());
  $fpSingle = (float) ($cpNow['private']['single_1pax']['offline'] ?? 3800);
  $fpDouble = (float) ($cpNow['private']['double_2pax']['offline'] ?? 7600);
  $fpRo     = $canEdit ? '' : 'readonly';
  $fpMissing = 'disabled title="Run database/upgrade-2026-09-fares.php once to enable this field"';
  ?>
  <div class="panel" id="faresPanel">
    <h2>💰 Fares &amp; booking rules <span class="muted" style="font-weight:400">· भाडा · the numbers that change, in one place</span></h2>
    <div class="fp-grid">
      <label>Default fare Gujarat → Rupaidiha (₹ / person)
        <input type="number" name="s[fare_to_nepal]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toNepal']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_nepal') ? '' : $fpMissing ?>>
        <small>toNepal · “jane” · for a pickup with no fare of its own</small></label>
      <label>Default fare Rupaidiha → Gujarat (₹ / person)
        <input type="number" name="s[fare_to_india]" min="1" max="100000" step="1" value="<?= (int) round($dirFares['toIndia']) ?>" <?= $fpRo ?> <?= $fpHave('fare_to_india') ? '' : $fpMissing ?>>
        <small>toIndia · “aune” · for a drop with no fare of its own</small></label>
      <label>Private Single cabin (₹)
        <input type="number" name="fp_private_single" min="1" max="100000" step="1" value="<?= (int) round($fpSingle) ?>" <?= $fpRo ?>>
        <small>1 passenger · online = offline</small></label>
      <label>Private Double cabin (₹)
        <input type="number" name="fp_private_double" min="1" max="100000" step="1" value="<?= (int) round($fpDouble) ?>" <?= $fpRo ?>>
        <small>2 passengers · online = offline</small></label>
      <label>Max seats per booking (customers)
        <input type="number" name="s[max_seats_per_booking]" min="1" max="20" step="1" value="<?= Settings::getInt('max_seats_per_booking', 6) ?>" <?= $fpRo ?> <?= $fpHave('max_seats_per_booking') ? '' : $fpMissing ?>>
        <small>online self-service cap</small></label>
      <label>Max seats per booking (counter / staff)
        <input type="number" name="s[counter_max_seats_per_booking]" min="1" max="40" step="1" value="<?= Settings::getInt('counter_max_seats_per_booking', 20) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_seats_per_booking') ? '' : str_replace('2026-09-fares.php', '2026-09-counter-bulk.sql', $fpMissing) ?>>
        <small>bulk booking — one party under one name at the desk</small></label>
      <label>Counter discount cap (%)
        <input type="number" name="s[counter_max_discount_pct]" min="0" max="100" step="0.5" value="<?= Security::e(rtrim(rtrim(number_format(Settings::getFloat('counter_max_discount_pct', 15.0), 2, '.', ''), '0'), '.')) ?>" <?= $fpRo ?> <?= $fpHave('counter_max_discount_pct') ? '' : $fpMissing ?>>
        <small>agents / admin may discount up to this</small></label>
    </div>
    <div style="padding:0 18px 14px"><small class="muted">Changes apply to the next search and the next counter sale. Agent commission (₹200 direct / ₹400 team) is set on the Agent Panel. Save with the button at the bottom.</small></div>
    <?php
    /* Pickup points & fares (owner ask, 1 Oct 2026): each pickup carries its
       own fare + ON/OFF in Admin -> Routes; the two numbers above are only the
       default for a pickup left blank. A link, not a form — this panel sits
       inside the settings form. */
    $pkLoc = Boarding::bootLocations();
    $pkGo  = (array) ($pkLoc['go'] ?? []);
    ?>
    <div class="pk-card">
      <div>
        <b>📍 Pickup points &amp; fares</b>
        <small class="muted">Every pickup's own fare, ON/OFF, order and edit — Rajkot included. Customers, agents, the counter and the AI see only the pickups switched ON, at the fare set there.</small>
        <?php if ($pkGo !== []): ?>
        <div class="pk-chips">
          <?php foreach ($pkGo as $pk): ?>
            <span><?= Security::e((string) $pk['name']) ?> · <b><?= Security::e(inr((float) ($pk['fare'] ?? $dirFares['toNepal']))) ?></b><?= ($pk['fare'] ?? null) === null ? ' <em class="muted">default</em>' : '' ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
      <?php if (Auth::can('routes.view')): ?>
      <a class="btn" href="routes.php#pickups">Manage pickup points →</a>
      <?php endif; ?>
    </div>
  </div>
  <style>.fp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;padding:16px 18px}.fp-grid label{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:var(--mut);text-transform:uppercase;letter-spacing:.3px}.fp-grid input{padding:10px 12px;border:1px solid var(--line);border-radius:9px;font-size:16px;font-weight:700;background:var(--card);color:var(--ink);min-width:0}.fp-grid input:disabled{opacity:.5}.fp-grid small{font-weight:500;text-transform:none;letter-spacing:0}.pk-card{display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin:0 18px 16px;padding:12px 14px;border:1px dashed var(--line);border-radius:10px;background:var(--hover)}.pk-card small{display:block;margin-top:2px}.pk-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.pk-chips span{font-size:12px;padding:3px 8px;border-radius:999px;background:var(--card);border:1px solid var(--line)}</style>


  <?php
  /* -- Agent rules (17 Sep 2026) ------------------------------------------
     The money rules the office asked to own, in one curated place. Every row
     is seeded by database/upgrade-2026-09-agent-rules.sql with TODAY'S
     behaviour as its default, so this panel changes nothing until the office
     edits a value. Bool rows post through the same _boolkeys mechanism as the
     generic loop (unticked = 0); the generic loop skips these keys. */
  $arKeys = ['agent_commission_mode', 'agent_flat_direct', 'agent_flat_joint', 'agent_commission_percent',
             'agent_advance_max', 'agent_advance_recover_pct', 'agent_payout_min', 'agent_settlement_due_days', 'agent_settle_reminder_days',
             'agent_cash_limit_enforce', 'agent_kyc_required', 'agent_notify_settlement',
             'agent_cash_limit_default', 'agent_daily_booking_limit'];
  $fpKeys  = array_merge($fpKeys, $arKeys);
  $arMissing = 'disabled title="Run database/upgrade-2026-09-agent-rules.sql once to enable this field"';
  $arNum = static function (string $key, string $label, string $help, float $def, string $min, string $max, string $step, string $unit = '') use ($fpHave, $fpRo, $arMissing): string {
      $val = Settings::get($key, null);
      $val = $val === null ? $def : (float) $val;
      $shown = rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.');
      return '<label>' . Security::e($label) . ($unit !== '' ? ' <span class="muted">(' . Security::e($unit) . ')</span>' : '')
           . '<input type="number" name="s[' . Security::e($key) . ']" min="' . $min . '" max="' . $max . '" step="' . $step . '" value="' . Security::e($shown) . '" ' . $fpRo . ' ' . ($fpHave($key) ? '' : $arMissing) . '>'
           . '<small>' . Security::e($help) . '</small></label>';
  };
  $arBool = static function (string $key, string $label, string $help) use ($fpHave, $canEdit, $arMissing): string {
      $on = Settings::getBool($key, false);
      return '<label class="ar-bool"><span>' . Security::e($label) . '</span>'
           . '<span class="row"><label class="switch"><input type="checkbox" name="s[' . Security::e($key) . ']" value="1" ' . ($on ? 'checked' : '') . ' ' . ($canEdit ? '' : 'disabled') . ' ' . ($fpHave($key) ? '' : $arMissing) . '><i></i></label>'
           . '<small>' . Security::e($help) . '</small></span></label>';
  };
  $arMode = (string) Settings::getString('agent_commission_mode', 'flat_per_seat');
  ?>
  <div class="panel" id="agentRulesPanel">
    <h2><svg class="a-ic"><use href="#a-handshake"/></svg> Agent rules <span class="muted" style="font-weight:400">· commission, advances, settlement, KYC — the office decides here</span></h2>
    <div class="fp-grid">
      <label>Commission scheme
        <select name="s[agent_commission_mode]" <?= $canEdit ? '' : 'disabled' ?> <?= $fpHave('agent_commission_mode') ? '' : $arMissing ?>>
          <option value="flat_per_seat" <?= $arMode === 'flat_per_seat' ? 'selected' : '' ?>>Flat ₹ per passenger (direct / team tiers)</option>
          <option value="percent" <?= $arMode === 'percent' ? 'selected' : '' ?>>Percent of ticket value</option>
        </select>
        <small>Switching to “percent” re-rates EVERY agent’s next sale at the company % (or their own override).</small></label>
      <?= $arNum('agent_flat_direct', 'Flat commission — direct agent', 'per passenger, flat scheme', 200, '0', '10000', '1', '₹') ?>
      <?= $arNum('agent_flat_joint', 'Flat commission — team / organisation', 'per passenger, flat scheme', 400, '0', '10000', '1', '₹') ?>
      <?= $arNum('agent_commission_percent', 'Company commission %', 'percent scheme default; a per-agent override on the Agent Panel wins', 5, '0', '100', '0.5', '%') ?>
      <?= $arNum('agent_advance_max', 'Advance / loan cap per agent', '0 = no cap. Refuses an advance that would take the agent above this', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_advance_recover_pct', 'Recovery share of each payout', 'Recorded as the agreed plan. The ledger always nets an advance from commission in full', 100, '0', '100', '1', '%') ?>
      <?= $arNum('agent_payout_min', 'Minimum payout', '0 = any amount. Paying the whole balance is always allowed', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_settlement_due_days', 'Settlement due in', '0 = never flag. Cash held longer shows “settlement overdue”', 0, '0', '365', '1', 'days') ?>
      <?= $arNum('agent_settle_reminder_days', 'WhatsApp reminder after', '0 = off. Once cash is overdue this many days, the daily cron (cron/wa-reminders.php) sends the agent one payment reminder a day', 0, '0', '365', '1', 'days') ?>
      <?= $arNum('agent_cash_limit_default', 'Default cash-in-hand limit', 'per-agent limit on the Agent Panel overrides this; 0 = no limit', 0, '0', '10000000', '1', '₹') ?>
      <?= $arNum('agent_daily_booking_limit', 'Default daily booking limit', 'bookings per agent per day; 0 = no limit', 0, '0', '1000', '1', '') ?>
    </div>
    <div class="fp-grid" style="padding-top:0">
      <?= $arBool('agent_cash_limit_enforce', 'Block selling above the cash limit', 'off = the limit only warns (today)') ?>
      <?= $arBool('agent_kyc_required', 'KYC must be verified before selling', 'off = KYC is informational; verify every agent first') ?>
      <?= $arBool('agent_notify_settlement', 'WhatsApp the agent on payout / cash handover', 'settlement receipt goes to the agent\'s number') ?>
    </div>
    <div style="padding:0 18px 14px"><small class="muted">Per-agent rates, overrides, salary, deposit and route permissions stay on the Agent Panel. Nothing here touches past ledger rows — rules apply from the next sale, payout or advance.</small></div>
  </div>
  <style>.ar-bool{display:flex;flex-direction:column;gap:6px}.ar-bool>span:first-child{font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--mut)}.ar-bool .row{gap:12px;align-items:center}.ar-bool small{font-weight:400;text-transform:none;letter-spacing:0;color:var(--mut)}</style>

  <?php
  /* 17 Sep 2026 — one-click WhatsApp (admin/api/wa-send.php): what the office
     must know under the keys that decide whether a message actually goes out.
     Help text only — the keys come from database/upgrade-2026-09-wa-templates.sql. */
  $waTplHelp = 'A business-initiated message (the office writes first) only reaches WhatsApp through an approved Twilio Content template — one per purpose, its HX… SID pasted here. Blank = free text, which WhatsApp delivers only inside the 24-hour window after this number last wrote to us; outside it the button hands the message to the staff phone (wa.me) instead of burning a send that would fail.';
  $waHelp = [
      'wa_admin_tools_enabled'              => 'Shows the "Send on WhatsApp" buttons on the agent, booking, manifest and dashboard pages. Every press previews first, then sends through the WhatsApp API (Twilio / Cloud API) or opens wa.me on the staff phone; each attempt is logged under Messages with its purpose.',
      'twilio_content_sid_agent_statement'  => $waTplHelp . ' Used for agent statements, booking history, commission, advance / loan balance and the daily / monthly summaries.',
      'twilio_content_sid_payment_reminder' => $waTplHelp . ' Used for agent and customer payment reminders and the outstanding-balance message.',
      'twilio_content_sid_settlement'       => $waTplHelp . ' Used for settlement receipts and cash-settlement details.',
      'twilio_content_sid_booking_detail'   => $waTplHelp . ' Used for booking status, passenger / seat details and the bus chalan picture.',
      'wa_statement_link_days'              => 'Statement PDFs and chalan pictures go out as signed download links, not attachments. A link stops opening after this many days — the message tells the recipient so.',
      // 5 Oct 2026: Google Ads conversion tracking (index.php boot + ShgAds in 05-router.js).
      'google_ads_conversion'               => 'Google Ads → Goals → Conversions → New → Website → "Purchase" → "Use Google tag" → copy the send_to value, e.g. AW-123456789/AbCdEfGhIjk. When set, every online booking is reported to Google Ads (value in ₹), so the campaign learns which clicks buy tickets. Blank = Google\'s tag is not loaded at all.',
      'google_ads_contact_conversion'       => 'Optional second conversion (category "Contact") for a tap on a phone number or WhatsApp button, same AW-…/label format. Leave blank if you only want bookings counted.',
  ];
  ?>
  <?php foreach ($groups as $group => $items): ?>
    <div class="panel set-group" id="grp-<?= Security::e((string) $group) ?>">
      <h2><?= Security::e($groupInfo[$group][0] ?? ucfirst((string) $group)) ?></h2>
      <p class="muted set-why"><?= Security::e($groupInfo[$group][1] ?? 'Settings stored under "' . $group . '".') ?></p>
      <table class="set-table no-card">
        <?php foreach ($items as $r):
          $key = (string) $r['skey'];
          if (in_array($key, $fpKeys, true)) { continue; }   // shown in the Fares panel above
          $label = $r['label'] ?: ucwords(str_replace('_', ' ', $key));
          $val = (string) ($r['svalue'] ?? '');
          $isJson = $r['stype'] === 'json';
          $isSecret = Settings::isSecretKey($key);   // one rule, shared with the audit mask
        ?>
        <tr>
          <th style="width:34%"><?= Security::e($label) ?>
            <div class="muted mono" style="font-weight:400;font-size:11px"><?= Security::e($key) ?></div>
          </th>
          <td>
            <?php if ($r['stype'] === 'bool'): ?>
              <label class="set-switch">
                <input type="checkbox" role="switch" name="s[<?= Security::e($key) ?>]" value="1" <?= $val === '1' ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?> data-saved="<?= $val === '1' ? '1' : '0' ?>">
                <span class="set-knob" aria-hidden="true"></span>
                <span class="set-state"><?= $val === '1' ? 'On' : 'Off' ?></span>
              </label>
            <?php elseif ($isJson): ?>
              <textarea name="s[<?= Security::e($key) ?>]" rows="2" style="width:100%;font-family:ui-monospace,monospace;font-size:12px;padding:8px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>><?= Security::e($val) ?></textarea>
            <?php elseif ($isSecret): ?>
              <?php /* Write-only: the stored value is never sent to the browser.
                       It used to be printed into value="…" for every dashboard.view
                       role — API keys and the WhatsApp token, readable in the page
                       source by support staff. */ ?>
              <input type="password" autocomplete="new-password" name="s[<?= Security::e($key) ?>]" value="" placeholder="<?= $val !== '' ? '•••••••• set — leave blank to keep' : 'not set' ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
              <small class="muted" style="display:block;margin-top:4px;font-size:11.5px"><?= $val !== '' ? 'Set. Type a new value to replace it, or CLEAR to remove it.' : 'Not set.' ?></small>
            <?php else: ?>
              <input type="text" name="s[<?= Security::e($key) ?>]" value="<?= Security::e($val) ?>" style="width:100%;max-width:420px;padding:9px 11px;border:1px solid var(--line);border-radius:8px" <?= $canEdit ? '' : 'readonly' ?>>
            <?php endif; ?>
            <?php if (isset($waHelp[$key])): ?><small class="muted" style="display:block;margin-top:6px;max-width:560px;line-height:1.45"><?= Security::e($waHelp[$key]) ?></small><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endforeach; ?>

  <?php if ($canEdit): ?>
    <div class="toolbar save-sticky"><span class="set-dirty" id="setDirty" hidden>You have unsaved changes</span><button class="btn" type="submit">Save all settings</button></div>
  <?php endif; ?>
</form>
<style>
.set-jump { margin: -6px 0 14px; display: grid; gap: 8px; }
.set-lede { margin: 0; color: var(--muted); font-size: 13.5px; }
.set-jump .sg-chip { text-decoration: none; }
.set-why { margin: -4px 0 10px; font-size: 13px; }
.set-switch { display: inline-flex; align-items: center; gap: 10px; min-height: 44px; cursor: pointer; position: relative; }
.set-switch input { position: absolute; opacity: 0; width: 52px; height: 32px; margin: 0; cursor: pointer; }
.set-knob { width: 52px; height: 32px; border-radius: 16px; background: var(--surface-sunken); border: 1.5px solid var(--line-control); position: relative; flex: none; transition: background-color .15s; }
.set-knob::after { content: ""; position: absolute; top: 3px; left: 3px; width: 23px; height: 23px; border-radius: 50%; background: var(--line-control); transition: transform .15s; }
.set-switch input:checked + .set-knob { background: var(--royal); border-color: var(--royal); }
.set-switch input:checked + .set-knob::after { transform: translateX(20px); background: var(--on-royal); }
.set-switch input:focus-visible + .set-knob { outline: 3px solid var(--focus); outline-offset: 2px; }
.set-state { font-weight: 700; font-size: 13.5px; }
.set-state.is-unsaved { color: var(--warning); }
.set-dirty { margin-right: auto; color: var(--warning); font-weight: 700; font-size: 13.5px; }
@media (max-width: 820px) {
  .set-table, .set-table tbody, .set-table tr, .set-table th, .set-table td { display: block; width: 100% !important; }
  .set-table tr { padding: 10px 0; border-bottom: 1px solid var(--line); }
  .set-table th { border: 0 !important; padding: 0 0 6px !important; background: none !important; text-align: left; }
  .set-table td { border: 0 !important; padding: 0 !important; }
  .set-table input[type=text], .set-table input[type=password], .set-table textarea { max-width: 100% !important; }
  .save-sticky { display: flex; gap: 10px; align-items: center; justify-content: flex-end; }
}
</style>
<script>
(function () {
  var dirty = document.getElementById('setDirty'), n = 0;
  document.querySelectorAll('.set-switch input').forEach(function (cb) {
    cb.addEventListener('change', function () {
      var st = cb.parentNode.querySelector('.set-state'), changed = (cb.checked ? '1' : '0') !== cb.getAttribute('data-saved');
      st.textContent = (cb.checked ? 'On' : 'Off') + (changed ? ' · not saved yet' : '');
      st.classList.toggle('is-unsaved', changed);
      mark();
    });
  });
  var form = dirty && dirty.closest('form');
  if (form) form.addEventListener('input', mark);
  function mark() { if (dirty) dirty.hidden = false; }
})();
</script>

<?php if ($canEdit):
  $testPhone = Settings::officePhone();
?>
<div class="panel" id="waTestPanel">
  <h2>📲 Test WhatsApp ticket delivery</h2>
  <?php
    // Notify is not bootstrap-loaded (events.php pulls it lazily), so this
    // page asks for it before reading the sender-breaker state.
    require_once INCLUDE_PATH . '/notify.php';
    // Only the Twilio driver can be paused by the breaker — a leftover row must
    // not accuse a Cloud API / click-to-chat setup of refusing anything.
    $waPause = Settings::getString('whatsapp_driver', 'click_to_chat') === 'twilio' ? Notify::twilioPause() : null;
  if ($waPause !== null): ?>
  <p style="margin:-4px 0 14px;padding:11px 13px;border-radius:9px;background:#FFF4E5;color:#8a4b00;border:1px solid #ffcc80;font-size:13.5px;line-height:1.55">
    ⏸️ <b>Automatic WhatsApp is paused.</b> Twilio refused the account
    <?= $waPause['message'] !== '' ? '(' . Security::e($waPause['message']) . ')' : '(code ' . (int) $waPause['code'] . ')' ?>,
    so tickets are going out as one-tap click-to-chat links instead of costing every sale a failed API call.
    It retries by itself after <b><?= Security::e(date('H:i', $waPause['until'])) ?></b> — or send a test below:
    a successful test resumes automatic delivery immediately.
  </p>
  <?php endif; ?>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real WhatsApp to the number below through your current settings, so you can confirm
    tickets will reach customers. Set <span class="mono">whatsapp_driver</span> = <b>twilio</b> and fill
    the Twilio SID / Auth Token / sender above (then <b>Save</b>) before testing.
    On the Twilio <b>sandbox</b>, the test phone must first send the join code to your sandbox number once.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="waTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="waTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="waTestBtn">Send test</button>
  </div>
  <div id="waTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('waTestBtn'), out=document.getElementById('waTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('waTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('waTestCountry').value);
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>

<div class="panel" id="smsTestPanel">
  <h2>📩 Test SMS ticket delivery</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real SMS through Twilio. Tick <span class="mono">sms_enabled</span>, fill the
    Twilio <b>SID / Auth Token</b> (same as WhatsApp) and <span class="mono">twilio_sms_from</span>
    (an <b>SMS-capable</b> Twilio number — not the WhatsApp sender) above, then <b>Save</b> before testing.
    Every send — success or failure — is recorded in the message log.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="tel" id="smsTestPhone" placeholder="+9198XXXXXXXX or +97798XXXXXXXX"
           value="<?= Security::e($testPhone) ?>"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <select id="smsTestCountry" style="padding:9px;border:1px solid var(--line);border-radius:8px">
      <option value="">Country: Auto</option>
      <option value="in">🇮🇳 India (+91)</option>
      <option value="np">🇳🇵 Nepal (+977)</option>
    </select>
    <button class="btn" type="button" id="smsTestBtn">Send test SMS</button>
  </div>
  <div id="smsTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('smsTestBtn'), out=document.getElementById('smsTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var phone=(document.getElementById('smsTestPhone').value||'').trim();
    if(!phone){ show('bad','Enter a phone number first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('phone', phone);
    fd.append('country', document.getElementById('smsTestCountry').value);
    fd.append('channel', 'sms');
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>

<div class="panel" id="emailTestPanel">
  <h2>📧 Test email (Gmail SMTP)</h2>
  <p class="muted" style="margin:-4px 0 14px;max-width:640px">
    Sends a real email through your SMTP settings above. For Gmail set
    <span class="mono">smtp_host</span> = smtp.gmail.com, <span class="mono">smtp_port</span> = 587,
    <span class="mono">smtp_secure</span> = tls, <span class="mono">smtp_user</span> = your Gmail address and
    <span class="mono">smtp_pass</span> = a 16-character <b>App Password</b>
    (Google Account → Security → 2-Step Verification → App Passwords — <b>not</b> the normal password),
    then <b>Save</b> before testing.
  </p>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="email" id="emailTestTo" placeholder="you@example.com"
           style="flex:1;min-width:210px;padding:9px 11px;border:1px solid var(--line);border-radius:8px">
    <button class="btn" type="button" id="emailTestBtn">Send test email</button>
  </div>
  <div id="emailTestResult" style="margin-top:12px;display:none;padding:11px 13px;border-radius:9px;font-size:13.5px;line-height:1.5"></div>
</div>
<script>
(function(){
  var btn=document.getElementById('emailTestBtn'), out=document.getElementById('emailTestResult');
  if(!btn) return;
  function show(kind,msg){
    out.style.display='block';
    out.style.background = kind==='ok' ? '#E8F5E9' : '#FFF4E5';
    out.style.color      = kind==='ok' ? '#1b5e20' : '#8a4b00';
    out.style.border     = '1px solid ' + (kind==='ok' ? '#a5d6a7' : '#ffcc80');
    out.textContent      = (kind==='ok' ? '✅ ' : '⚠️ ') + msg;
  }
  btn.onclick=function(){
    var to=(document.getElementById('emailTestTo').value||'').trim();
    if(!to){ show('bad','Enter an email address first.'); return; }
    btn.disabled=true; var old=btn.textContent; btn.textContent='Sending…';
    var fd=new FormData();
    fd.append('email', to);
    fd.append('channel', 'email');
    fd.append('<?= $k ?>', '<?= $csrf ?>');
    fetch('notify-test.php', { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){ show(j && j.ok ? 'ok' : 'bad', (j && j.detail) || (j && j.ok ? 'Sent.' : 'Send failed.')); })
      .catch(function(){ show('bad','Could not reach the server — check your connection and try again.'); })
      .finally(function(){ btn.disabled=false; btn.textContent=old; });
  };
})();
</script>
<?php endif; ?>
<?php
admin_footer();
