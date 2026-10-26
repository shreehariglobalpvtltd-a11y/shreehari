<?php
/**
 * admin/company.php — the two companies' details, in one form (4 Oct 2026).
 *
 * Owner: "tyo fill haru aile fill garne type ko system rakhdeu … jati bela ni
 * update garna milos … aile khali rakhdeu paxi edit garxu … logo default yei
 * India vari … Nepal ko lagi account add gardinxu."
 *
 * One screen for everything a ticket, an invoice and the counter screen print
 * about WHO is selling:
 *   - the India company (company_* rows; a blank field keeps the built-in value)
 *   - the Nepal partner company (nepal_* rows; ALL blank until the office types
 *     them - a Nepal desk keeps the India letterhead until the name is filled)
 *   - the Nepal logo (blank = the India logo) and the Nepal payment account
 *     (blank = the India account, exactly as today)
 *
 * The greyed hints are what the homepage already publishes; they are never
 * saved unless someone types them. Desk details (phone, hours, address, map)
 * live on Admin -> Counters & collection, and each counter clerk can keep
 * their own desk's on "My desk".
 *
 * Who: anyone with dashboard.view may look; saving needs settings.manage (or
 * superadmin). Every change is one audit row with old -> new.
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';
$admin   = admin_boot('dashboard.view');
$canEdit = Auth::canManageSettings();
$flash   = null;

/* key => [label, hint, max length, multiline] */
$INDIA = [
    'company_name'     => ['Short name (ticket header)', 'S Hari Global Pvt Ltd', 120, false],
    'company_legal'    => ['Legal name', 'S Hari Global Private Limited', 160, false],
    'company_legal_ne' => ['Legal name in Nepali / Hindi', 'एस हरि ग्लोबल प्राइभेट लिमिटेड', 160, false],
    'company_address'  => ['Registered address', 'Near Shilpa Garage, Silver Complex, Mehsana - 384002, Gujarat, India', 255, true],
    'company_phone'    => ['Office phone', '+91 91048 01507', 40, false],
    'company_whatsapp' => ['WhatsApp (with +91)', '+91 91048 01507', 40, false],
    'company_email'    => ['Email', 'shreehariglobalpvtltd@gmail.com', 120, false],
    'company_cin'      => ['CIN', 'U52291GJ2026PTC174029', 40, false],
    'company_gstin'    => ['GSTIN (invoice says TAX INVOICE only when filled)', 'not registered yet', 20, false],
    'company_tm_no'    => ['Trade mark no. (shown in the footer once filled)', 'e.g. 6312845 · Class 39', 40, false],
    'upi_id'           => ['UPI ID customers pay to', '', 80, false],
    'esewa_id'         => ['eSewa ID (used by every desk until the Nepal one is filled)', '', 80, false],
];
$NEPAL = [
    'nepal_company'    => ['Company name (English) — fills the Nepal ticket letterhead', 'Shree Hari Global Pvt. Ltd.', 120, false],
    'nepal_company_ne' => ['Company name (Nepali)', 'श्री हरी ग्लोबल प्रा. लि.', 160, false],
    'nepal_reg'        => ['Registration no. (Companies Act 2063)', '', 60, false],
    'nepal_pan'        => ['PAN / VAT no.', '', 30, false],
    'nepal_office'     => ['Office address (English)', 'Pushpalal Chowk, Nepalgunj-2, Banke', 255, true],
    'nepal_office_ne'  => ['Office address (Nepali)', 'पुष्पलाल चोक, नेपालगञ्ज-२, बाँके', 255, true],
    'nepal_phone'      => ['Office phone (+977)', '+977 …', 40, false],
    'nepal_whatsapp'   => ['WhatsApp (+977)', '+977 98…', 40, false],
    'nepal_email'      => ['Email', '', 120, false],
    'nepal_esewa_id'   => ['eSewa ID (blank = India account above)', '', 80, false],
    'nepal_esewa_name' => ['Name on the eSewa account', '', 120, false],
    'nepal_bank'       => ['Bank account (bank, branch, account name, number)', '', 255, true],
];
/* 5 Oct 2026: the Nepal office's people on the home page (Settings::nepalTeam()).
   A row shows only once it has a number; the CEO alone falls back to the
   Director's published line. */
$NPTEAM = [
    'np_ceo_name'      => ['CEO — name', 'Sher Bahadur Bishwokarma', 80, false],
    'np_ceo_phone'     => ['CEO — phone (with +977 or +91)', '+91 97264 01507 (used until you type one)', 40, false],
    'np_ops_name'      => ['Operations, Nepalgunj — name', 'Lokesh Mama', 80, false],
    'np_ops_phone'     => ['Operations, Nepalgunj — phone', '+977 98… (hidden on the site until filled)', 40, false],
    'np_parking_name'  => ['Parking in-charge, Rupaidiha — name', 'Vishal Bhai', 80, false],
    'np_parking_phone' => ['Parking in-charge, Rupaidiha — phone', '+977 98… / +91 … (hidden until filled)', 40, false],
    // 7 Oct 2026: the Nepalgunj office line + two more Nepal numbers
    'np_office_name'   => ['Nepal office, Nepalgunj — name', 'Lok Bahadur Sunar', 80, false],
    'np_office_phone'  => ['Nepal office, Nepalgunj — phone', '+977 986-6201375 (used until you type one)', 40, false],
    'np_extra1_name'   => ['Extra Nepal number 1 — name / label', 'Nepal Office', 80, false],
    'np_extra1_phone'  => ['Extra Nepal number 1 — phone', '+977 98… (hidden until filled)', 40, false],
    'np_extra2_name'   => ['Extra Nepal number 2 — name / label', 'Nepal Office', 80, false],
    'np_extra2_phone'  => ['Extra Nepal number 2 — phone', '+977 98… (hidden until filled)', 40, false],
];
/* 9 Oct 2026 (site facts): what the WEBSITE says - one row each, read by
   includes/sitefacts.php for the home page, the SEO card, the WhatsApp
   answers and every page that names the CEO or a phone. A blank box keeps the
   built-in value. Office phones: a desk's own phone on Counters & collection,
   else the town's number in the "office numbers" line below. */
$WEB = [
    'company_ceo'      => ['CEO — name on the website', 'Sher Bahadur Bishwokarma', 80, false],
    'company_ceo_ne'   => ['CEO — name in Nepali', 'शेर बहादुर विश्वकर्मा', 120, false],
    'company_tagline'  => ['Tagline', 'India to Nepal Bus Transport | International Import & Export', 160, false],
    'support_phone'    => ['24×7 helpline (call + WhatsApp)', '+91 91734 01507 (used until you type one)', 40, false],
    'company_counters' => ['Office numbers line — "Town +91 … · Town +91 …" (printed on tickets; the website uses it when a desk has no phone)', 'Mehsana +91 … · Ahmedabad +91 … · Surat +91 … · Rajkot +91 …', 400, true],
];
$LOGO_REL = 'assets/img/brand-np-logo.png';

if ($canEdit && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!Security::verifyCsrf()) {
        $flash = ['bad', 'Session expired — please try again. / फेरि प्रयास गर्नुहोस्।'];
    } else {
        $action = (string) ($_POST['action'] ?? 'save');
        try {
            if ($action === 'logo_remove') {
                $old = Settings::getString('nepal_logo', '');
                Settings::set('nepal_logo', '', 'string', 'company');
                Logger::audit('company.nepal_logo', 'settings', 'nepal_logo', ['path' => $old], ['path' => ''], 'Nepal logo removed - India logo used');
                Settings::touchTicketIdentity();
                $flash = ['ok', 'Nepal logo removed — tickets use the India logo again.'];
            } elseif ($action === 'logo' && isset($_FILES['logo'])) {
                $f = $_FILES['logo'];
                $mime = (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) ? (mime_content_type((string) $f['tmp_name']) ?: '') : '';
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('Choose an image first.');
                }
                if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) || ($f['size'] ?? 0) > 5 * 1024 * 1024) {
                    throw new RuntimeException('PNG, JPG or WebP, up to 5 MB.');
                }
                $src = @imagecreatefromstring((string) file_get_contents((string) $f['tmp_name']));
                if ($src === false) {
                    throw new RuntimeException('That image could not be read.');
                }
                /* Stored as a PNG no wider than 600 px - tickets refuse a logo over 600 KB. */
                $w = imagesx($src); $h = imagesy($src);
                $scale = min(1.0, 600 / max(1, $w));
                $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 255, 255, 255, 127));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                $abs = dirname(__DIR__) . '/' . $LOGO_REL;
                if (!imagepng($dst, $abs . '.tmp', 9) || !rename($abs . '.tmp', $abs)) {
                    throw new RuntimeException('Could not save the logo.');
                }
                imagedestroy($src); imagedestroy($dst);
                Settings::set('nepal_logo', $LOGO_REL, 'string', 'company');
                Logger::audit('company.nepal_logo', 'settings', 'nepal_logo', null, ['path' => $LOGO_REL, 'bytes' => (int) filesize($abs)], 'Nepal logo uploaded');
                Settings::touchTicketIdentity();
                $flash = ['ok', 'Nepal logo saved. New and re-opened Nepal tickets carry it.'];
            } else {
                $old = []; $new = [];
                foreach ([$INDIA, $NEPAL, $NPTEAM, $WEB] as $set) {
                    foreach ($set as $key => [$label, , $max, $multi]) {
                        if (!array_key_exists($key, $_POST['s'] ?? [])) {
                            continue;
                        }
                        $v = trim(Security::clean((string) $_POST['s'][$key], $max));
                        $cur = Settings::getString($key, '');
                        $isIndia = isset($INDIA[$key]);
                        /* An India field left blank keeps the built-in value: a blank
                           company_name would print an empty ticket header. */
                        if ($isIndia && $v === '') {
                            continue;
                        }
                        if ($v !== $cur) {
                            $old[$key] = $cur;
                            $new[$key] = $v;
                            Settings::set($key, $v, 'string', 'company', in_array($key, ['upi_id', 'esewa_id', 'nepal_esewa_id', 'nepal_esewa_name', 'nepal_bank'], true) ? false : true);
                        }
                    }
                }
                Settings::flush();
                if ($new !== []) {
                    Logger::audit('company.details', 'settings', 'company', $old, $new, 'company details edited by admin #' . (int) $admin['id']);
                    Settings::touchTicketIdentity();
                    $flash = ['ok', count($new) . ' field(s) saved. Tickets opened from now on use them. / सेभ भयो।'];
                } else {
                    $flash = ['ok', 'Nothing changed.'];
                }
            }
        } catch (Throwable $e) {
            $flash = ['bad', $e->getMessage()];
        }
    }
}

$npName = trim(Settings::getString('nepal_company', ''));
$npLogo = trim(Settings::getString('nepal_logo', ''));
$e      = static fn($v): string => Security::e((string) $v);

admin_header('Company details', 'company');
?>
<style>
.co-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,420px),1fr));gap:16px;align-items:start}
.co-card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:16px 18px}
.co-card h2{margin:0 0 4px;font-size:17px;display:flex;align-items:center;gap:8px}
.co-card .sub{font-size:12.5px;color:var(--mut);margin:0 0 12px}
.co-f{display:flex;flex-direction:column;gap:4px;margin-bottom:10px;font-size:12.5px;font-weight:700;color:var(--mut)}
.co-f input,.co-f textarea{padding:9px 11px;border:1px solid var(--line);border-radius:9px;font-size:14.5px;font-weight:400;background:var(--card);color:var(--ink);min-height:44px;width:100%;box-sizing:border-box}
.co-f textarea{min-height:64px;resize:vertical}
.co-state{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:800;padding:3px 10px;border-radius:999px}
.co-state.on{background:#E4F6EC;color:#11703F}.co-state.off{background:#FFF4DE;color:#8A5300}
:root[data-theme="dark"] .co-state.on{background:#12321F;color:#5FD39A}:root[data-theme="dark"] .co-state.off{background:#3B2610;color:#FFC870}
.co-logo{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:6px 0 12px}
.co-logo img{width:96px;height:72px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:10px}
.co-save{position:sticky;bottom:0;background:var(--card);border-top:1px solid var(--line);padding:12px 0;margin-top:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
</style>

<?php if ($flash !== null): ?><div class="flash <?= $e($flash[0]) ?>"><?= $e($flash[1]) ?></div><?php endif; ?>

<p class="muted" style="margin:0 0 14px;font-size:13.5px">
  What a ticket, an invoice and the counter screen print about <b>who is selling</b>. Greyed text is only a hint
  (what the homepage shows) — nothing is saved until you type it. You can change any of it at any time.
  <?php if (!$canEdit): ?><br><b>View only</b> — saving needs the Settings permission.<?php endif; ?>
</p>

<form method="post" data-no-guard>
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="save">
  <div class="co-grid">
    <section class="co-card">
      <h2><?= Brand::flagUse('IN', 18, 'en', true) ?> India company</h2>
      <p class="sub">S Hari Global Pvt Ltd, Mehsana — every Gujarat desk, Rupaidiha and every online sale. A blank box keeps the built-in value.</p>
      <?php foreach ($INDIA as $key => [$label, $hint, $max, $multi]): $val = Settings::getString($key, ''); ?>
        <label class="co-f"><?= $e($label) ?>
          <?php if ($multi): ?>
            <textarea name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?>><?= $e($val) ?></textarea>
          <?php else: ?>
            <input type="text" name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" value="<?= $e($val) ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </section>

    <section class="co-card">
      <h2><?= Brand::flagUse('NP', 22, 'en', true) ?> Nepal company
        <span class="co-state <?= $npName !== '' ? 'on' : 'off' ?>"><?= $npName !== '' ? 'Nepal tickets use it' : 'Not filled — Nepal tickets use India' ?></span></h2>
      <p class="sub">Nepalgunj, Dhamboji and Kohalpur desks. Until the <b>Company name</b> is filled, their tickets keep the India letterhead.
        Logo blank = India logo · eSewa / bank blank = India account.</p>
      <?php foreach ($NEPAL as $key => [$label, $hint, $max, $multi]): $val = Settings::getString($key, ''); ?>
        <?php if ($key === 'nepal_esewa_id'): ?><h3 style="font-size:14px;margin:16px 0 8px">💳 Where Nepal customers pay</h3><?php endif; ?>
        <label class="co-f"><?= $e($label) ?>
          <?php if ($multi): ?>
            <textarea name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?>><?= $e($val) ?></textarea>
          <?php else: ?>
            <input type="text" name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" value="<?= $e($val) ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?>>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </section>

    <section class="co-card">
      <h2>📞 Nepal office contacts <span class="co-state <?= count(Settings::nepalTeam()) >= 2 ? 'on' : 'off' ?>"><?= count(Settings::nepalTeam()) ?> of 6 on the home page</span></h2>
      <p class="sub">The "Nepal Office" card at the top of the website: tap-to-call and WhatsApp for each person.
        The CEO is shown in a block of his own, apart from the Nepal office numbers. A person appears only after a <b>phone</b> is typed here. A bare 10-digit number is read as a Nepal mobile (+977) — type +91 for an Indian one.</p>
      <?php foreach ($NPTEAM as $key => [$label, $hint, $max]): $val = Settings::getString($key, ''); ?>
        <label class="co-f"><?= $e($label) ?>
          <input type="text" name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" value="<?= $e($val) ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?><?= str_ends_with($key, '_phone') ? ' inputmode="tel"' : '' ?>>
        </label>
      <?php endforeach; ?>
    </section>

    <section class="co-card">
      <h2>🌐 Website facts · वेबसाइटमा देखिने</h2>
      <p class="sub">One place for what the whole website says — the home page, the CEO card, the search-engine card, WhatsApp answers.
        Change it here and every page shows it on its next open. Fares come from Settings → Fares (per pickup); offices from
        <a href="/admin/counters.php">Counters &amp; collection</a> (tick “Show this office on the website”).</p>
      <?php foreach ($WEB as $key => [$label, $hint, $max, $multi]): $val = Settings::getString($key, ''); ?>
        <label class="co-f"><?= $e($label) ?>
          <?php if ($multi): ?>
            <textarea name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?>><?= $e($val) ?></textarea>
          <?php else: ?>
            <input type="text" name="s[<?= $e($key) ?>]" maxlength="<?= (int) $max ?>" value="<?= $e($val) ?>" placeholder="<?= $e($hint) ?>" <?= $canEdit ? '' : 'readonly' ?><?= str_ends_with($key, '_phone') ? ' inputmode="tel"' : '' ?>>
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </section>
  </div>
  <?php if ($canEdit): ?>
  <div class="co-save">
    <button class="btn ok" type="submit">💾 Save details · सेभ गर्नुहोस्</button>
    <span class="muted" style="font-size:12.5px">Every change is kept in the audit log (old → new).</span>
  </div>
  <?php endif; ?>
</form>

<section class="co-card" style="margin-top:16px">
  <h2>🖼️ Nepal company logo</h2>
  <p class="sub">Printed on Nepal-desk tickets once the Nepal company name is filled. No logo uploaded = the India logo.</p>
  <div class="co-logo">
    <img src="/<?= $e($npLogo !== '' ? $npLogo : (Settings::getString('company_logo', '') !== '' ? Settings::getString('company_logo', '') : 'assets/img/logo-tm.png')) ?>?t=<?= time() ?>" alt="Current logo">
    <span class="muted" style="font-size:13px"><?= $npLogo !== '' ? 'Nepal logo in use' : 'India logo in use (default)' ?></span>
  </div>
  <?php if ($canEdit): ?>
  <form method="post" enctype="multipart/form-data" data-no-guard style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="logo">
    <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" required>
    <button class="btn" type="submit">⬆️ Upload</button>
  </form>
  <?php if ($npLogo !== ''): ?>
  <form method="post" data-no-guard style="margin-top:8px" onsubmit="return confirm('Use the India logo again?')">
    <?= Security::csrfField() ?>
    <input type="hidden" name="action" value="logo_remove">
    <button class="btn ghost sm" type="submit">Use the India logo again</button>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</section>

<p class="muted" style="font-size:13px;margin-top:14px">
  Desk phone, hours, address and map link: <a href="/admin/counters.php">Counters &amp; collection</a> (office) or
  <a href="/admin/my-desk.php">My desk</a> (each counter keeps its own).
</p>
<?php admin_footer();
