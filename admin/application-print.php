<?php
/**
 * admin/application-print.php — one application as an A4 form (22 Oct 2026).
 *
 * Owner: "agent, staff, sajhedari pvt ltd … apply now form digital form ani
 * export ni garna milos — photo sahit ko A4 size, logo haru, company details
 * haleko PDF jasto file". The office opens an application (agent, Pvt. Ltd.
 * partner or staff) as a printable A4 sheet: the company letterhead (logo,
 * legal name, CIN / GSTIN, address, phone, e-mail), the person's photo (and
 * the company logo of a partner), every field of the form, the KYC and
 * phone-proof status, the online signatures, a document seal and an
 * office-use box. "Print / Save as PDF" is the browser's own print dialog,
 * so the PDF is exactly this page.
 *
 *   ?type=agent&id=12         an agent / partner application (agent_applications)
 *   ?type=staff&id=3          a staff application (staff_applications)
 *   ?blank=agent|partner|staff  the same form, empty, for a walk-in to fill by hand
 *
 * Who: the same doors as the pages that list them — agents / partners need
 * customers.view, staff need drivers.view. Photo, ID, signature and the KYC /
 * bank values are printed only for a super-admin or a manager (masked for
 * everyone else, exactly as on the dashboards), and every print is written
 * to the audit log. Staff files are read through StaffApps::fileUrl()
 * (15-minute signed links), never an /uploads path.
 *
 * Seal: HMAC(APP_KEY) over the type, id and every stored field, so a printed
 * copy can be checked against the record (the office re-opens this page and
 * compares the 16 characters).
 */
declare(strict_types=1);
require __DIR__ . '/_guard.php';

/* 22 Oct 2026 (owner: "print garera ni bharna milos, online ni bharna milos ...
   bhareko form chha bhane tyo bhareko form rakhdeu"): agreements/form.php
   includes this page in PUBLIC mode (SHG_A4_PUBLIC) for two things only —
   ?kind=staff|agent|partner = the blank form for anyone to print and fill by
   hand, and ?mine=1 = the person's OWN application they just sent from this
   browser (api/staff-apply.php / api/agreement-apply.php leave
   $_SESSION['a4_mine'] after a successful send, good for 6 hours). Nothing
   about anyone else is reachable that way, and nothing travels in the URL. */
$public = defined('SHG_A4_PUBLIC');
$mine   = false;
$mineSince = 0;   // when the applicant sent it: their copy lists only what was signed from then on
if ($public) {
    $kindQ = (string) ($_GET['kind'] ?? '');
    $own   = is_array($_SESSION['a4_mine'] ?? null) ? $_SESSION['a4_mine'] : null;
    /* 23 Oct 2026 (owner: "agent staff haru lai tyo online rakhne"): team/form.php - the signed-in team member's own
       record, any time (it names the row; nothing comes from the URL) */
    if (defined('SHG_A4_TEAM') && is_array(SHG_A4_TEAM)) {
        $own = SHG_A4_TEAM + ['t' => time()];
        $_GET['mine'] = 1;
    }
    // 23 Oct 2026 (review): 1 hour, not 6 - long enough to save the copy right after sending, short on a shared
    // phone or office PC (signing out of /team/ or /admin/ also clears it)
    if (!empty($_GET['mine']) && $own !== null && (int) ($own['t'] ?? 0) > time() - 3600 && (int) ($own['id'] ?? 0) > 0) {
        $mine = true;
        $mineSince = defined('SHG_A4_TEAM') ? 0 : (int) $own['t'];   // the portal copy lists every signature of their own record
        $_GET['type'] = (string) ($own['type'] ?? '') === 'staff' ? 'staff' : 'agent';
        $_GET['id']   = (string) (int) $own['id'];
        unset($_GET['blank']);
    } elseif (!empty($_GET['mine'])) {
        // 23 Oct 2026 (review): said plainly, instead of quietly opening a blank STAFF form for an agent or partner
        http_response_code(410);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
        echo '<!doctype html><html lang="ne"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>फारम · Form</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;'
            . 'padding:16px;background:#eef3fc;color:#0d1b3e;font:16px/1.6 "Noto Sans","Noto Sans Devanagari",Arial,sans-serif;text-align:center}'
            . 'a{display:inline-block;margin:6px;padding:10px 14px;border-radius:12px;background:#0a2a8f;color:#fff;font-weight:700;text-decoration:none}</style></head><body><div>'
            . '<p>तपाईंले भरेको फारम यो ब्राउजरमा अब छैन (पठाएको १ घण्टासम्म मात्र राखिन्छ)। प्रतिलिपि चाहिए कार्यालयमा फोन गर्नुहोस्: '
            . Security::e(Settings::officePhone()) . '<br>Your filled copy is no longer kept in this browser. Please ask the office for a copy.</p>'
            . '<a href="/agreements/form.php?kind=staff">कर्मचारी · Staff</a><a href="/agreements/form.php?kind=agent">एजेन्ट · Agent</a>'
            . '<a href="/agreements/form.php?kind=partner">साझेदार · Partner</a><a href="/agreements/">← सम्झौता · Agreements</a></div></body></html>';
        exit;
    } else {
        $_GET['blank'] = in_array($kindQ, ['agent', 'partner', 'staff'], true) ? $kindQ : 'staff';
    }
}

$blank = (string) ($_GET['blank'] ?? '');
$blank = in_array($blank, ['agent', 'partner', 'staff'], true) ? $blank : '';
$type  = $blank !== '' ? ($blank === 'staff' ? 'staff' : 'agent') : ((string) ($_GET['type'] ?? '') === 'staff' ? 'staff' : 'agent');
$admin = $public ? [] : admin_boot($type === 'staff' ? 'drivers.view' : 'customers.view');

$role   = (string) ($admin['role'] ?? '');
$canKyc = $mine || (!$public && (Auth::isSuperadmin() || $role === 'manager'));   // the applicant sees their own details in full
$selfId = (int) ($admin['id'] ?? 0);
$h      = static fn($v): string => Security::e((string) ($v ?? ''));
$id     = (int) ($_GET['id'] ?? 0);
/* a staff file for this page: the office gets the 15-minute signed link; the
   applicant's own copy embeds the bytes (no admin session to sign for) */
$staffImg = static function (array $rec, string $slot, bool $thumb = false) use ($mine, $selfId): string {
    if (!$mine) {
        return StaffApps::fileUrl((int) $rec['id'], $slot, $selfId, $thumb);
    }
    $col = StaffApps::slotColumn($slot);
    $abs = $col !== null && (string) ($rec[$col] ?? '') !== '' ? StaffApps::absPath((string) $rec[$col]) : null;
    $bin = $abs !== null ? (string) @file_get_contents($abs) : '';
    $mt  = $bin !== '' ? (string) ((new finfo(FILEINFO_MIME_TYPE))->buffer($bin) ?: '') : '';
    return in_array($mt, ['image/jpeg', 'image/png'], true) ? 'data:' . $mt . ';base64,' . base64_encode($bin) : '';
};
$co     = Settings::companyFor(null, 'IN');
$logo   = Brand::markUrl(120);
$isImg  = static fn($v): bool => is_string($v) && preg_match('~^data:image/(png|jpe?g|webp);base64,~', $v) === 1;
$fmtD   = static fn($v): string => $v ? date('d M Y', (int) strtotime((string) $v)) : '';
$fmtDT  = static fn($v): string => $v ? date('d M Y, H:i', (int) strtotime((string) $v)) : '';
$selfieRow = static fn(string $fc): array => ['Live selfie', $fc === '' ? 'Not done' : (str_contains($fc, 'LIVE ✓') ? 'Live face verified' : 'Checked by the office'), str_contains($fc, 'LIVE ✓')];

$rec = null;          // the row
$sections = [];       // title => [[label, value, masked?], ...]
$photo = '';          // <img src>
$coLogo = '';         // a partner's own logo
$signs = [];          // [[label, img src|'', detail], ...]
$status = [];         // [label, value, ok?]
$kind = '';
$ref = '';
$party = [];          // the agreement's second party: [[label, value], ...] (22 Oct 2026)
$thumb = '';          // <img src> of the camera thumbprint
$sigImg = '';         // <img src> of the phone signature, for the agreement's signature box
$passkey = false;     // a phone fingerprint / Face ID lock was registered
$recLang = '';        // the language the applicant used
$wit = [['', ''], ['', '']];   // the two witnesses' name + address, when the form gave them (23 Oct 2026)

if ($blank === '') {
    if ($type === 'agent') {
        require_once INCLUDE_PATH . '/agentapplications.php';
        require_once INCLUDE_PATH . '/teamportal.php';
        $rec = AgentApplications::find($id);
        if ($rec === null) {
            http_response_code(404);
            exit('Application not found.');
        }
        $f     = is_array($rec['fields'] ?? null) ? $rec['fields'] : [];
        $isOrg = (string) $rec['type'] === 'org';
        $kind  = $isOrg ? 'partner' : 'agent';
        $ref   = (string) ($rec['ref'] ?: AgentApplications::refFor((int) $rec['id']));
        $groups = [
            $isOrg ? 'Company & representative' : 'Applicant' => ['p_name', 'p_type', 'p_reg', 'p_tax', 'p_rep', 'p_rep_desig', 'p_rep_phone', 'p_id', 'p_dob', 'p_occ', 'p_idtype', 'p_idno', 'p_pan', 'p_phone', 'p_email', 'p_area', 'p_addr', 'p_addr2', 'p_office'],
            'Agreement terms' => ['a_no', 'a_date', 'a_place', 'a_start', 'a_end', 'co_sig', 'co_desig', 'org_rate', 'org_pct', 'org_target', 'org_fixed', 'org_day', 'ind_target', 'ind_fixed', 'ind_pct', 'c73'],
            'Bank / payment'  => ['b_name', 'b_bank', 'b_acc', 'b_ifsc', 'b_upi'],
        ];
        // 23 Oct 2026 (owner: "no guarantors or witnesses for Staff/Agent"): a partner company only
        if ($isOrg) {
            $groups['Witnesses']  = ['w1_name', 'w1_addr', 'w2_name', 'w2_addr'];
            $groups['Guarantors'] = ['g1_name', 'g1_id', 'g1_addr', 'g2_name', 'g2_id', 'g2_addr', 'g3_name', 'g3_id', 'g3_addr', 'g4_name', 'g4_id', 'g4_addr'];
        }
        $base = [
            ['Name', (string) $rec['name'], false], ['Mobile', (string) $rec['phone'], false], ['E-mail', (string) ($rec['email'] ?? ''), false],
            ['Work area', (string) ($rec['area'] ?? ''), false], ['Applied on', $fmtDT($rec['created_at'] ?? null), false],
        ];
        $sections['Contact'] = array_values(array_filter($base, static fn($r) => trim((string) $r[1]) !== ''));
        foreach ($groups as $g => $names) {
            $rows = [];
            foreach ($names as $n) {
                if (!isset($f[$n]) || (string) $f[$n] === '') {
                    continue;
                }
                $masked = !$canKyc && AgentApplications::isKycField($n, (string) $rec['type']);
                $rows[] = [AgentApplications::FIELD_LABELS[$n] ?? $n, AgentApplications::officeValue($n, (string) $f[$n], (string) $rec['type'], $canKyc), $masked];
            }
            if ($rows !== []) {
                $sections[$g] = $rows;
            }
        }
        if (array_key_exists('cv_json', $rec)) {
            $cv = TeamPortal::cv($rec, 'agent');
            $line = static fn(array $parts): string => implode(' · ', array_filter(array_map(static fn($p) => trim(is_scalar($p) ? (string) $p : ''), $parts), static fn($p) => $p !== '' && $p !== '0'));
            $cvRows = [];
            foreach ((array) $cv['edu'] as $e) {
                if (is_array($e) && ($t = $line([AgentApplications::CV_QUALS[(string) ($e['level'] ?? '')] ?? ($e['level'] ?? ''), $e['school'] ?? '', $e['year'] ?? ''])) !== '') {
                    $cvRows[] = ['Education', $t, false];
                }
            }
            foreach ((array) $cv['exp'] as $e) {
                if (is_array($e) && ($t = $line([$e['org'] ?? '', $e['role'] ?? '', ($e['years'] ?? '') !== '' && ($e['years'] ?? '') !== '0' ? $e['years'] . ' years' : ''])) !== '') {
                    $cvRows[] = ['Experience', $t, false];
                }
            }
            foreach (['skills' => 'Skills', 'langs' => 'Languages', 'about' => 'About'] as $k => $l) {
                if (is_string($cv[$k] ?? null) && trim($cv[$k]) !== '') {
                    $cvRows[] = [$l, trim($cv[$k]), false];
                }
            }
            if ($cvRows !== []) {
                $sections[$isOrg ? 'Company profile' : 'Education & experience'] = $cvRows;
            }
        }
        if ($canKyc && $isImg($rec['photo'] ?? null)) {
            $photo = (string) $rec['photo'];
        }
        if ($canKyc && $isOrg && $isImg($rec['logo'] ?? null)) {
            $coLogo = (string) $rec['logo'];
        }
        $dv = (string) ($rec['doc_verify'] ?? '');
        $status = [
            ['Application', AgentApplications::STATUS_LABELS[(string) $rec['status']] ?? (string) $rec['status'], (string) $rec['status'] === 'approved'],
            ['KYC', AgentApplications::KYC_LABELS[(string) $rec['kyc_status']] ?? (string) $rec['kyc_status'], (string) $rec['kyc_status'] === 'verified'],
            ['Agreement', (AgentApplications::AGREEMENT_LABELS[(string) $rec['agreement_status']] ?? (string) $rec['agreement_status'])
                . (!empty($rec['agreement_end']) ? ' · ends ' . $fmtD($rec['agreement_end']) : ''), (string) $rec['agreement_status'] === 'signed'],
            ['WhatsApp number proof', (int) ($rec['phone_verified'] ?? 0) === 1 ? 'Verified (hi VERIFY)' : 'Not proven', (int) ($rec['phone_verified'] ?? 0) === 1],
            ['ID document check', $dv === '' ? ((string) ($rec['doc_photo'] ?? '') !== '' ? 'Not checked — compare by hand' : 'No ID photo sent') : (str_starts_with($dv, 'PASS') ? 'Name + number match the ID' : 'Not matched'), str_starts_with($dv, 'PASS')],
        ];
        if ($canKyc && $isImg($rec['doc_photo'] ?? null)) {
            $signs[] = [$isOrg ? 'Representative’s ID' : 'ID document', (string) $rec['doc_photo'], 'as sent with the form'];
        }
        foreach (['reg_cert' => 'Company registration certificate', 'tax_cert' => 'PAN / VAT / GST certificate'] as $ck => $cl) {
            if ($canKyc && $isOrg && $isImg($rec[$ck] ?? null)) {
                $signs[] = [$cl, (string) $rec[$ck], 'company KYC · as sent with the form'];
            }
        }
        if ($isOrg && AgentApplications::certReady()) {
            $status[] = ['Company papers', ($rec['reg_cert'] ?? '') !== '' && $rec['reg_cert'] !== null
                ? 'Registration' . (($rec['tax_cert'] ?? '') !== '' && $rec['tax_cert'] !== null ? ' + PAN/VAT' : '') . ' sent' : 'Not sent', ($rec['reg_cert'] ?? null) !== null && $rec['reg_cert'] !== ''];
        }
        if ($canKyc && $isImg($rec['signature'] ?? null)) {
            $signs[] = ['Signature on the form', (string) $rec['signature'], 'drawn on the phone · ' . $fmtDT($rec['created_at'] ?? null)];
            $sigImg  = (string) $rec['signature'];
        }
        if ($canKyc && !$isOrg && $isImg($rec['edu_cert'] ?? null)) {
            $signs[] = ['Marksheet / certificate', (string) $rec['edu_cert'], 'as sent with the form'];
        }
        // 22 Oct 2026: the camera thumbprint and the phone's fingerprint / Face ID lock
        if ($canKyc && $isImg($rec['thumb'] ?? null)) {
            $thumb   = (string) $rec['thumb'];
            $signs[] = ['Thumbprint (camera)', $thumb, 'right thumb, photographed on the phone'];
        }
        $passkey = (string) ($rec['webauthn_cred'] ?? '') !== '';
        if (array_key_exists('webauthn_cred', $rec)) {
            $status[] = ['Fingerprint / Face ID', $passkey ? 'Phone lock registered' : 'Not added', $passkey];
        }
        if (array_key_exists('face_check', $rec)) {   // 23 Oct 2026: the live selfie check (includes/facecheck.php)
            $status[] = $selfieRow((string) ($rec['face_check'] ?? ''));
        }
        $recLang = (string) ($rec['lang'] ?? '');
        $fv = static fn(string $n): string => trim((string) ($f[$n] ?? ''));
        $wit = [[$fv('w1_name'), $canKyc ? $fv('w1_addr') : ''], [$fv('w2_name'), $canKyc ? $fv('w2_addr') : '']];
        $party = $isOrg ? [
            ['कम्पनीको नाम · Company', (string) $rec['name']],
            ['दर्ता नं. · PAN / VAT · Registration · tax no.', trim($fv('p_reg') . ($fv('p_tax') !== '' ? ' · ' . $fv('p_tax') : ''))],
            ['रजिस्टर्ड ठेगाना · Registered office', $canKyc ? ($fv('p_addr') !== '' ? $fv('p_addr') : (string) ($rec['area'] ?? '')) : (string) ($rec['area'] ?? '')],
            ['अधिकृत प्रतिनिधि (नाम · पद) · Representative', trim($fv('p_rep') . ($fv('p_rep_desig') !== '' ? ' · ' . $fv('p_rep_desig') : ''))],
            ['मोबाइल · Mobile', (string) $rec['phone']],
        ] : [
            ['पूरा नाम · Full name', (string) $rec['name']],
            ['मोबाइल · Mobile', (string) $rec['phone']],
            ['ठेगाना · Address', $canKyc ? ($fv('p_addr') !== '' ? $fv('p_addr') : (string) ($rec['area'] ?? '')) : (string) ($rec['area'] ?? '')],
            ['परिचयपत्र र नम्बर · ID and number', $fv('p_idtype') === '' ? '' : AgentApplications::officeValue('p_idtype', $fv('p_idtype'), (string) $rec['type'], $canKyc)
                . ($fv('p_idno') !== '' ? ' · ' . AgentApplications::officeValue('p_idno', $fv('p_idno'), (string) $rec['type'], $canKyc) : '')],
        ];
        foreach (Database::fetchAll("SELECT * FROM agreement_signatures WHERE party_type = 'agent' AND party_id = :i ORDER BY id DESC LIMIT 4", ['i' => (int) $rec['id']]) as $sg) {
            if ($mine && (int) strtotime((string) ($sg['signed_at'] ?? '')) < $mineSince - 900) {
                continue;
            }
            $img = $canKyc ? (TeamPortal::signImage($sg) ?? '') : '';
            $signs[] = [ucfirst((string) $sg['doc']) . ' agreement v' . (string) $sg['version'], $img,
                'SIG-' . str_pad((string) (int) $sg['id'], 6, '0', STR_PAD_LEFT) . ' · ' . $fmtDT($sg['signed_at'] ?? null) . ($canKyc && !$mine ? ' · IP ' . (string) ($sg['ip_address'] ?? '') : '')];
        }
        $sealSrc = 'agent|' . (int) $rec['id'] . '|' . (string) $rec['name'] . '|' . (string) $rec['phone'] . '|' . (string) ($rec['fields_json'] ?? '') . '|' . (string) $rec['status'] . '|' . (string) $rec['kyc_status'];
    } else {
        require_once INCLUDE_PATH . '/staffapplications.php';
        $rec = StaffApps::find($id);
        if ($rec === null) {
            http_response_code(404);
            exit('Application not found.');
        }
        $kind = 'staff';
        $ref  = (string) $rec['ref'];
        $mask = static fn(string $v): string => $v === '' ? '' : (mb_strlen($v) >= 8 ? '••••' . mb_substr($v, -4) : '••••');
        $kyc  = static fn(string $v, bool $tail = true): string => $v === '' || $canKyc ? $v : ($tail ? $mask($v) : 'hidden');
        $refs = json_decode((string) ($rec['references_json'] ?? ''), true);
        $cc   = ['IN' => '+91 ', 'NP' => '+977 '][(string) ($rec['phone_country'] ?? '')] ?? '';   // the column keeps the country, not the code
        $sections['Applicant'] = [
            ['Name', (string) $rec['full_name'], false], ['Job applied for', StaffApps::roleLabel((string) $rec['role_applied']), false],
            ['Mobile', $cc . (string) $rec['phone'], false],
            ['WhatsApp', (string) ($rec['whatsapp'] ?? ''), false], ['E-mail', (string) ($rec['email'] ?? ''), false],
            ['Date of birth', $canKyc ? $fmtD($rec['dob'] ?? null) : 'hidden', !$canKyc], ['Gender', ucfirst((string) ($rec['gender'] ?? '')), false],
            ['Office', (string) ($rec['base_location'] ?? ''), false], ['Applied on', $fmtDT($rec['created_at'] ?? null), false],
        ];
        $sections['Address'] = [
            ['Permanent address', $kyc((string) ($rec['address_perm'] ?? ''), false), !$canKyc], ['Current address', $kyc((string) ($rec['address_current'] ?? ''), false), !$canKyc],
            ['District', (string) ($rec['district'] ?? ''), false], ['Province / state', (string) ($rec['province'] ?? ''), false], ['Country', (string) ($rec['country'] ?? ''), false],
        ];
        $sections['Identity'] = [
            ['ID type', StaffApps::ID_LABELS[(string) ($rec['id_type'] ?? '')] ?? (string) ($rec['id_type'] ?? ''), false],
            ['ID number', $kyc((string) ($rec['id_number'] ?? '')), !$canKyc],
            ['Driving licence', trim((string) ($rec['license_number'] ?? '') !== '' ? $kyc((string) $rec['license_number']) . ' · ' . (string) ($rec['license_category'] ?? '') . ((string) ($rec['license_expiry'] ?? '') !== '' ? ' · valid to ' . $fmtD($rec['license_expiry']) : '') : ''), !$canKyc],
        ];
        $sections['Education & experience'] = [
            ['Qualification', StaffApps::QUAL_LABELS[(string) ($rec['qualification'] ?? '')] ?? (string) ($rec['qualification'] ?? ''), false],
            ['Experience', (string) ($rec['experience_years'] ?? '') !== '' ? $rec['experience_years'] . ' years' : '', false],
            ['Previous employer', trim((string) ($rec['prev_employer'] ?? '') . ((string) ($rec['prev_role'] ?? '') !== '' ? ' · ' . $rec['prev_role'] : '')), false],
        ];
        $refRows = [];
        foreach (is_array($refs) ? array_slice($refs, 0, 4) : [] as $i => $r) {
            if (is_array($r) && trim(implode('', array_map('strval', $r))) !== '') {
                $refRows[] = [['guarantor' => 'Guarantor', 'witness' => 'Witness'][(string) ($r['relation'] ?? '')] ?? 'Reference ' . ($i + 1), implode(' · ', array_filter(array_map('strval', [$r['name'] ?? '', $r['phone'] ?? '', $r['relation'] ?? ($r['rel'] ?? '')]))), false];
            }
        }
        $refRows[] = ['Emergency contact', implode(' · ', array_filter([(string) ($rec['emergency_contact_name'] ?? ''), (string) ($rec['emergency_contact_phone'] ?? ''), (string) ($rec['emergency_relation'] ?? '')])), false];
        $sections['References'] = $refRows;
        $sections['Bank'] = [
            ['Account holder', $kyc((string) ($rec['bank_holder'] ?? ''), false), !$canKyc], ['Bank · branch', $kyc(implode(' · ', array_filter([trim((string) ($rec['bank_name'] ?? '')), trim((string) ($rec['bank_branch'] ?? ''))])), false), !$canKyc],
            ['Account number', $kyc((string) ($rec['bank_account'] ?? '')), !$canKyc], ['IFSC / SWIFT', $kyc((string) ($rec['bank_ifsc_or_swift'] ?? ''), false), !$canKyc],
        ];
        foreach ($sections as $k => $rows) {
            $sections[$k] = array_values(array_filter($rows, static fn($r) => trim((string) $r[1]) !== '' && trim((string) $r[1]) !== '·'));
            if ($sections[$k] === []) {
                unset($sections[$k]);
            }
        }
        if ($canKyc && (string) ($rec['photo_path'] ?? '') !== '') {
            $photo = $staffImg($rec, 'photo');
        }
        $status = [
            ['Application', StaffApps::STATUS_LABELS[(string) $rec['status']] ?? (string) $rec['status'], (string) $rec['status'] === 'approved'],
            ['WhatsApp number proof', (int) ($rec['phone_verified'] ?? 0) === 1 ? 'Verified' : 'Not proven', (int) ($rec['phone_verified'] ?? 0) === 1],
            ['ID document check', (string) ($rec['doc_verify_status'] ?? '') !== '' ? ucfirst(str_replace('_', ' ', (string) $rec['doc_verify_status'])) : 'Not checked', in_array((string) ($rec['doc_verify_status'] ?? ''), ['pass', 'passed', 'verified', 'ok'], true)],
            ['Agreement', 'Staff agreement v' . (string) ($rec['agreement_version'] ?? ''), (string) ($rec['agreement_version'] ?? '') !== ''],
        ];
        if ($canKyc) {
            foreach (['id_front', 'id_back', 'license'] as $slot) {
                $col = StaffApps::slotColumn($slot);
                if ($col !== null && preg_match('~\.(jpe?g|png)$~', (string) ($rec[$col] ?? '')) === 1) {
                    $signs[] = [StaffApps::FILE_LABELS[$slot], $staffImg($rec, $slot, true), 'as sent with the form'];
                }
            }
            if ((string) ($rec['signature_path'] ?? '') !== '') {
                $sigImg  = $staffImg($rec, 'signature');
                $signs[] = ['Signature on the form', $sigImg, 'drawn on the phone · ' . $fmtDT($rec['created_at'] ?? null)];
            }
            // 22 Oct 2026: the camera thumbprint
            if ((string) ($rec['thumb_path'] ?? '') !== '') {
                $thumb   = $staffImg($rec, 'thumb');
                $signs[] = ['Thumbprint (camera)', $thumb, 'right thumb, photographed on the phone'];
            }
        }
        $passkey = (string) ($rec['biometric_webauthn_cred'] ?? '') !== '';
        $status[] = ['Fingerprint / Face ID', $passkey ? 'Phone lock registered' : 'Not added', $passkey];
        if (array_key_exists('face_check', $rec)) {
            $status[] = $selfieRow((string) ($rec['face_check'] ?? ''));
        }
        $party = [
            ['पूरा नाम · Full name', (string) $rec['full_name']],
            ['मोबाइल · Mobile', $cc . (string) $rec['phone']],
            ['ठेगाना · Address', $kyc((string) ($rec['address_perm'] ?? ''), false)],
            ['परिचयपत्र र नम्बर · ID and number', trim((StaffApps::ID_LABELS[(string) ($rec['id_type'] ?? '')] ?? (string) ($rec['id_type'] ?? '')) . ' · ' . $kyc((string) ($rec['id_number'] ?? '')), ' ·')],
            ['पद · कार्यालय · Job · duty base', trim(StaffApps::roleLabel((string) $rec['role_applied']) . ((string) ($rec['base_location'] ?? '') !== '' ? ' · ' . $rec['base_location'] : ''))],
        ];
        foreach (StaffApps::signs((int) $rec['id']) as $sg) {
            // 23 Oct 2026 (review): a rejected applicant's number applied for again reuses the row - the new
            // applicant's own copy never shows the earlier person's signatures, and no copy of theirs shows IPs
            if ($mine && (int) strtotime((string) ($sg['signed_at'] ?? '')) < $mineSince - 900) {
                continue;
            }
            $signs[] = ['Staff agreement v' . (string) $sg['version'] . ' (' . (string) $sg['event'] . ')', '',
                'SIG-' . str_pad((string) (int) $sg['id'], 6, '0', STR_PAD_LEFT) . ' · ' . $fmtDT($sg['signed_at'] ?? null) . ((int) $sg['phone_verified'] === 1 ? ' · phone proven' : '') . ($canKyc && !$mine ? ' · IP ' . (string) ($sg['ip_address'] ?? '') : '')];
        }
        $sealSrc = 'staff|' . (int) $rec['id'] . '|' . json_encode(array_diff_key($rec, array_flip(['updated_at', 'reminded_at', 'last_login_at', 'failed_logins', 'locked_until', 'password_hash'])));
    }
    $seal = strtoupper(substr(hash_hmac('sha256', $sealSrc, APP_KEY . '|application-print'), 0, 16));
    $seal = implode('-', str_split($seal, 4));
    Logger::audit('application.print', $type === 'staff' ? 'staff_application' : 'agent_application', $ref, null, null,
        $mine ? (defined('SHG_A4_TEAM') ? 'A4 copy opened by the person in the team portal (/team/form.php)' : 'A4 copy opened by the applicant (own browser session, right after sending)')
              : 'A4 application printed by admin #' . $selfId . ($canKyc ? ' (full KYC)' : ' (masked)'));
} else {
    $kind = $blank;
    $blankRows = static fn(array $labels): array => array_map(static fn($l) => [$l, '', false], $labels);
    if ($blank === 'staff') {
        $sections = [
            'आवेदक · Applicant' => $blankRows(['पूरा नाम · Full name', 'कुन काम · Job (चालक / कन्डक्टर / हेल्पर / अफिस / लेखापाल / मेकानिक / अन्य)', 'मोबाइल · Mobile', 'WhatsApp', 'इमेल · E-mail', 'जन्म मिति (ईस्वी) · Date of birth (AD)', 'लिङ्ग · Gender']),
            'ठेगाना · Address' => $blankRows(['स्थायी ठेगाना · Permanent address', 'हालको ठेगाना · Current address', 'जिल्ला · प्रदेश · देश · District · province · country']),
            'परिचयपत्र · Identity' => $blankRows(['परिचयपत्र र नम्बर · ID type and number', 'लाइसेन्स नं. · वर्ग · म्याद (चालक) · Driving licence no. · category · valid to']),
            'शिक्षा र अनुभव · Education & experience' => $blankRows(['शिक्षा · Qualification', 'अनुभव (वर्ष) · पहिलेको काम · Experience · previous employer']),
            'चिनारी · References' => $blankRows(['चिनारी १ (नाम · फोन) · Reference 1', 'चिनारी २ (नाम · फोन) · Reference 2', 'आपतकालीन सम्पर्क (नाम · फोन · नाता) · Emergency contact']),
            'बैंक · Bank' => $blankRows(['खातावाला · बैंक · शाखा · Account holder · bank · branch', 'खाता नम्बर · IFSC / SWIFT · Account number']),
        ];
    } elseif ($blank === 'partner') {
        $sections = [
            'कम्पनी · Company' => $blankRows(['कम्पनीको नाम · Company name', 'प्रकार (प्रा. लि. / फर्म / अन्य) · Company type', 'दर्ता नं. (प्रमाणपत्र सहित) · Registration no.', 'PAN / VAT / GST नं. (प्रमाणपत्र सहित)', 'रजिस्टर्ड कार्यालय ठेगाना · Registered office', 'कम्पनीको फोन · इमेल · Company phone · e-mail']),
            'अधिकृत प्रतिनिधि · Authorised representative' => $blankRows(['नाम · Name', 'पद · Designation', 'मोबाइल · Mobile', 'परिचयपत्र र नम्बर (प्रतिलिपि सहित) · ID type and number']),
            'काम · Work' => $blankRows(['काम गर्ने ठाउँ / कार्यालय · Work area / office', 'कम्पनीको अनुभव · Company experience']),
            'बैंक / भुक्तानी · Bank / payment' => $blankRows(['खातावालाको नाम · Account name', 'बैंक · शाखा · Bank · branch', 'खाता नम्बर · IFSC · Account number', 'UPI / eSewa / Khalti']),
            'साक्षी · Witnesses' => $blankRows(['साक्षी १ (नाम · ठेगाना) · Witness 1', 'साक्षी २ (नाम · ठेगाना) · Witness 2']),
        ];
    } else {
        $sections = [
            'आवेदक · Applicant' => $blankRows(['पूरा नाम · Full name', 'मोबाइल · Mobile', 'इमेल · E-mail', 'जन्म मिति · Date of birth', 'पेशा · Occupation', 'काम गर्ने ठाउँ · Work area', 'घरको ठेगाना · Home address']),
            'परिचयपत्र · Identity' => $blankRows(['परिचयपत्र र नम्बर (प्रतिलिपि सहित) · ID type and number', 'PAN']),
            'शिक्षा र अनुभव · Education & experience' => $blankRows(['शिक्षा · Education', 'अनुभव · Experience']),
            'बैंक / भुक्तानी · Bank / payment' => $blankRows(['खातावालाको नाम · Account name', 'बैंक · शाखा · Bank · branch', 'खाता नम्बर · IFSC · Account number', 'UPI / eSewa / Khalti']),
        ];
    }
    $seal = '';
}

$title = ['agent' => 'Agent application', 'partner' => 'Partner company (Pvt. Ltd.) application', 'staff' => 'Staff application'][$kind] ?? 'Application';
$titleNe = ['agent' => 'एजेन्ट आवेदन फारम', 'partner' => 'साझेदार कम्पनी (प्रा. लि.) आवेदन फारम', 'staff' => 'कर्मचारी आवेदन फारम'][$kind] ?? '';
$needs = [
    'agent'   => ['Passport-size photo', 'Citizenship / Aadhaar / passport / voter ID (both sides)', 'PAN (if any)', 'Bank details or UPI', 'WhatsApp number proof (hi VERIFY)', 'Signed agent agreement'],
    'partner' => ['Company registration certificate', 'PAN / VAT / GST certificate', 'Board resolution or letter naming the representative', 'Representative’s ID + photo', 'Company logo', 'Company bank account', 'Signed partner agreement (two witnesses)'],
    'staff'   => ['Passport-size photo', 'ID (both sides)', 'Driving licence (drivers)', 'Education certificate / marksheet', 'Experience letter', 'Two references + emergency contact', 'Bank details', 'Signed staff agreement'],
][$kind] ?? [];
/* 22 Oct 2026 (owner: "2 3 page kati huncha agreement fill garna milos, tesko tala sabai niyam sart haru ni
   3 otai lekhdinu"): after the form, that kind's agreement in full - parties, every term, signatures and thumb
   boxes - in the language asked for (?lang=), read from the deployed agreement page (AgreementPrint). */
require_once INCLUDE_PATH . '/agreementprint.php';
$agrLang = AgreementPrint::lang($kind, (string) ($_GET['lang'] ?? ($recLang !== '' ? $recLang : 'ne')));
$agrSecs = AgreementPrint::sections($kind, $agrLang);
$agrDoc  = [
    'staff'   => ['कर्मचारी सम्झौता · Staff agreement', 'agreements/staff-agreement.html', 'agreements/apply.html?kind=staff', 'कर्मचारी · Staff member'],
    'agent'   => ['एजेन्ट सम्झौता · Agent agreement', 'agreements/agent-agreement-' . $agrLang . '.html', 'agreements/apply.html', 'एजेन्ट · Agent'],
    'partner' => ['साझेदार कम्पनी सम्झौता · Partner (Pvt. Ltd.) agreement', 'agreements/partner-agreement-' . $agrLang . '.html', 'agreements/apply.html?kind=partner', 'साझेदार कम्पनी · Partner company'],
][$kind] ?? null;
$kindBadge = ['staff' => 'कर्मचारी · STAFF', 'agent' => 'एजेन्ट · AGENT', 'partner' => 'साझेदार कम्पनी · PARTNER (PVT. LTD.)'][$kind] ?? '';
$kindShort = ['staff' => 'कर्मचारी · STAFF', 'agent' => 'एजेन्ट · AGENT', 'partner' => 'साझेदार · PARTNER'][$kind] ?? '';   // the letterhead's corner
$shortUrl  = static fn(string $p): string => (string) preg_replace('~^https?://(www\.)?~i', '', appUrl($p));
$qrImg = '';
/* 23 Oct 2026 (owner: "unique agreement ID and verification QR"): a filled agreement's QR opens
   verify-agreement.php, which shows whether THIS agreement is in force right now; a blank form's QR
   still opens the online form. */
$verifyUrl = '';
$standing  = null;
if ($blank === '' && $ref !== '') {
    require_once INCLUDE_PATH . '/agreementverify.php';
    $verifyUrl = AgreementVerify::url($ref);
    try {
        $standing = AgreementVerify::standing($ref);
    } catch (Throwable $e) {
        $standing = null;
    }
}
if ($agrDoc !== null) {
    try {
        $qrImg = QrCode::dataUri($verifyUrl !== '' ? $verifyUrl : appUrl($blank !== '' ? $agrDoc[2] : $agrDoc[1]), 4, 2);
    } catch (Throwable $e) {
        $qrImg = '';
    }
}
if ($party === []) {
    $party = $kind === 'partner'
        ? [['कम्पनीको नाम · Company', ''], ['दर्ता नं. · PAN / VAT · Registration · tax no.', ''], ['रजिस्टर्ड ठेगाना · Registered office', ''], ['अधिकृत प्रतिनिधि (नाम · पद) · Representative', ''], ['मोबाइल · Mobile', '']]
        : [['पूरा नाम · Full name', ''], ['मोबाइल · Mobile', ''], ['ठेगाना · Address', ''], ['परिचयपत्र र नम्बर · ID and number', '']];
    if ($kind === 'staff') {
        $party[] = ['पद · कार्यालय · Job · duty base', ''];
    }
}
/* 23 Oct 2026 (owner: "CV jasto form, Canva template colourful"): page 1 opens like a CV - photo, name and contact
   on the colour band; the paper form gets the CV's own lines (skills, languages, about) to fill by hand */
if ($blank !== '') {
    $cvAt = $kind === 'partner' ? 'काम · Work' : 'शिक्षा र अनुभव · Education & experience';
    foreach ($kind === 'partner' ? ['सेवा र शाखाहरू · Services & branches', 'कम्पनीको परिचय · About the company'] : ['सीप · Skills', 'भाषा · Languages'] as $l) {
        $sections[$cvAt][] = [$l, '', false];
    }
}
$pick = static function (string $needle) use ($party): string {
    foreach ($party as [$l, $v]) {
        if (str_contains($l, $needle) && trim((string) $v) !== '') {
            return trim((string) $v);
        }
    }
    return '';
};
$heroName  = $blank === '' ? $pick('Full name') . $pick('Company') : '';
$heroChips = $blank === '' ? array_values(array_filter([
    ($v = $pick('Mobile')) !== '' ? '📱 ' . $v : '',
    ($v = $pick('Job')) !== '' ? '🧭 ' . $v : '',
    ($v = $pick('Representative')) !== '' ? '👤 ' . $v : '',
    $ref !== '' ? '🔖 ' . $ref : '',
    !empty($rec['created_at']) ? '🗓 ' . $fmtD($rec['created_at']) : '',
])) : [];
$secPal  = [['#2F6BFF', '#EAF1FF'], ['#0EA5A0', '#E3F6F4'], ['#7B5CFF', '#F0ECFF'], ['#F07A1E', '#FFF0E3'], ['#E8457C', '#FDEAF0'], ['#11A35A', '#E6F6EC'], ['#D99A00', '#FFF5D6'], ['#0EA5E9', '#E4F5FD']];
$secName = ['Contact' => 'सम्पर्क · Contact', 'Applicant' => 'आवेदक · Applicant', 'Company & representative' => 'कम्पनी र प्रतिनिधि · Company & representative',
    'Agreement terms' => 'सम्झौताका सर्त · Agreement terms', 'Bank / payment' => 'बैंक / भुक्तानी · Bank / payment', 'Witnesses' => 'साक्षी · Witnesses',
    'Guarantors' => 'जमानीकर्ता · Guarantors', 'Company profile' => 'कम्पनीको परिचय · Company profile', 'Education & experience' => 'शिक्षा र अनुभव · Education & experience',
    'Address' => 'ठेगाना · Address', 'Identity' => 'परिचयपत्र · Identity', 'References' => 'चिनारी · References', 'Bank' => 'बैंक · Bank'];
$secIcon = static fn(string $t): string => match (true) {
    (bool) preg_match('~Company|कम्पनी~u', $t)      => '🏢',
    (bool) preg_match('~Address|ठेगाना~u', $t)      => '🏠',
    (bool) preg_match('~Identity|परिचयपत्र~u', $t)  => '🪪',
    (bool) preg_match('~Education|शिक्षा~u', $t)    => '🎓',
    (bool) preg_match('~Reference|चिनारी~u', $t)    => '🤝',
    (bool) preg_match('~Bank|बैंक~u', $t)           => '🏦',
    (bool) preg_match('~Witness|साक्षी~u', $t)      => '✍️',
    (bool) preg_match('~Guarantor~u', $t)           => '🛡️',
    (bool) preg_match('~Agreement~u', $t)           => '📜',
    (bool) preg_match('~Work|काम~u', $t)            => '🧭',
    (bool) preg_match('~Contact~u', $t)             => '📇',
    (bool) preg_match('~About|CV~u', $t)            => '✨',
    default                                          => '👤',
};
$fieldCls = static fn(string $l, string $v): string => preg_match('~About|परिचय~u', $l) ? 'w tall'
    : (preg_match('~ठेगाना|[Aa]ddress|office|Job \(|Representative|Services~u', $l) || mb_strlen($v) > 40 || mb_strlen($l) > 58 ? 'w' : '');
$pasteBoxes = [
    'agent'   => [['परिचयपत्र — अगाडि', 'ID copy — front'], ['परिचयपत्र — पछाडि', 'ID copy — back'], ['PAN / मार्कसिट', 'PAN card / marksheet'], ['बैंक चेक / UPI QR', 'Bank cheque / UPI QR']],
    'staff'   => [['परिचयपत्र — अगाडि', 'ID copy — front'], ['परिचयपत्र — पछाडि', 'ID copy — back'], ['लाइसेन्स (चालक)', 'Driving licence (drivers)'], ['मार्कसिट / अनुभव पत्र', 'Marksheet / experience letter']],
    'partner' => [['दर्ता प्रमाणपत्र', 'Registration certificate'], ['PAN / VAT / GST प्रमाणपत्र', 'PAN / VAT / GST certificate'], ['प्रतिनिधिको परिचयपत्र', 'Representative’s ID'], ['कम्पनीको छाप', 'Company stamp / seal']],
][$kind] ?? [];
$formUrl = $shortUrl((string) ($agrDoc[2] ?? 'agreements/apply.html'));
/* the same page in another language: the query this page was opened with (public: kind or mine only) */
$q = $public ? ($mine ? (defined('SHG_A4_TEAM') ? ['as' => $type] : ['mine' => 1]) : ['kind' => $kind]) : ($blank !== '' ? ['blank' => $blank] : ['type' => $type, 'id' => $id]);
/* 23 Oct 2026 (owner: "PDF B&W print ready, same details, format farak bhaye hunchha"): ?bw=1 = the same
   sheets in black and white for an ordinary printer - grey photos, black rules, no ink-heavy fills. The
   PDF / PNG / JPG export greys its own pictures too (html2canvas ignores the CSS filter). */
$bw = !empty($_GET['bw']);
$bwHref = '?' . http_build_query($q + (isset($_GET['lang']) ? ['lang' => (string) $_GET['lang']] : []) + ($bw ? [] : ['bw' => 1]));
if ($bw) {
    $q['bw'] = 1;
}
$langHref = static fn(string $l): string => '?' . http_build_query($q + ['lang' => $l]);
$footRef = ($ref !== '' ? 'Agreement No. <b>' . $h($ref) . '</b>' : 'फारम नं. · Form no. ________') . ($seal !== '' ? ' · Seal <b>' . $h($seal) . '</b>' : '');

$autoPrint = isset($_GET['print']);
$autoPdf   = (string) ($_GET['auto'] ?? '') === 'pdf';   // 23 Oct 2026: the "Download my form & agreement" button after sending
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="<?= $h($agrLang) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h(($ref !== '' ? $ref . ' · ' : '') . $title) ?> — <?= $h($co['name']) ?></title>
<style>
/* 22 Oct 2026: real A4 sheets (210 x 297 mm), each with the letterhead and a page footer, filled by the script
   below - the same sheets print, save as PDF and come out as one PNG / JPG per page. */
@page{size:A4;margin:0}
*{box-sizing:border-box}
html,body{margin:0;background:#E9EDF3;color:#0F1B33;font:12px/1.42 "Segoe UI",Roboto,Arial,"Noto Sans Devanagari",sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
[hidden]{display:none!important}
/* one colour per kind - staff green, agent blue, partner orange (22 Oct 2026, "3 prakar ko form differentiate garera") */
body{--k:#1F3C88}body.k-staff{--k:#11703F}body.k-partner{--k:#C25E00}
.bar{position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;padding:10px;background:#0F1B33;color:#fff}
.bar button,.bar a{font:600 13px inherit;font-family:inherit;padding:9px 14px;border-radius:9px;border:0;cursor:pointer;text-decoration:none;color:#0F1B33;background:#fff}
.bar button.pri{background:#F07A2E;color:#fff}
.bar a.on{outline:3px solid #F07A2E}
.bar .lg{display:inline-flex;gap:5px;align-items:center;flex-wrap:wrap;justify-content:center;font-size:12px}
.bar .lg a{padding:6px 10px;font-size:12px}
.bar small{opacity:.8;flex-basis:100%;text-align:center}
#pages{padding:4px 0 24px}
.sheet{width:210mm;height:297mm;margin:12px auto;background:#fff;box-shadow:0 6px 30px rgba(15,27,51,.18);position:relative;overflow:hidden;display:flex;flex-direction:column;padding:8mm 10mm 6mm}
.sheet .body{flex:1 1 auto;min-height:0;overflow:hidden;position:relative;z-index:1;padding-top:2.5mm}
/* 23 Oct 2026 (owner: "professional 2-4 page A4 PDF"): page 2 onward carry a slim letterhead - logo, name, kind */
.sheet.cont .lh{padding-bottom:4px;border-bottom-width:2px;gap:10px}.sheet.cont .lh img{height:28px}
.sheet.cont .lh .co b{font-size:12.5px}.sheet.cont .lh .co span,.sheet.cont .lh .idx{display:none}.sheet.cont .stripe{height:3px}
.pf{display:flex;justify-content:space-between;gap:8px;border-top:2px solid var(--k);padding-top:4px;margin-top:3px;font-size:9.5px;color:#3A4A66;position:relative;z-index:1}
.pf b{font-family:Consolas,monospace;color:#0F1B33;letter-spacing:.3px}
.lh{display:flex;align-items:center;gap:14px;border-bottom:3px solid var(--k);padding-bottom:7px;position:relative;z-index:1}
.lh img{height:58px;width:auto}
.lh .co{flex:1;min-width:0}
.lh .co b{display:block;font-size:18px;letter-spacing:.3px;color:var(--k)}
.lh .co span{display:block;font-size:10.5px;color:#3A4A66}
.lh .ids{text-align:right;font-size:10px;color:#3A4A66;white-space:nowrap}
.kbadge{display:inline-block;margin:0 0 4px;padding:3px 10px;border-radius:999px;background:var(--k);color:#fff;font-size:11px;font-weight:800;letter-spacing:.3px}
.stripe{height:4px;background:linear-gradient(90deg,var(--k) 0 60%,#F07A2E 60%);position:relative;z-index:1}
.wm{position:absolute;inset:auto 0 40% 0;text-align:center;font-size:64px;font-weight:800;color:rgba(31,60,136,.05);transform:rotate(-18deg);pointer-events:none;z-index:0}
#flow{width:190mm;margin:12px auto;background:#fff;padding:4mm}
.head{display:flex;gap:14px;align-items:flex-start}
.head .t{flex:1}
.head h1{margin:0;font-size:18px;color:#0F1B33}
.head h2{margin:1px 0 4px;font-size:13px;font-weight:600;color:#3A4A66}
.meta{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:11.5px;color:#3A4A66}
.meta b{color:#0F1B33}
.pics{display:flex;gap:8px}
.pic{width:28mm;height:35mm;border:1.5px solid var(--k);border-radius:6px;display:grid;place-items:center;overflow:hidden;background:#F6F8FC;font-size:10px;color:#6B7A99;text-align:center;padding:4px}
.pic img{width:100%;height:100%;object-fit:cover}
.pic.logo img{object-fit:contain}
.st{display:grid;grid-template-columns:repeat(auto-fit,minmax(34mm,1fr));gap:5px;margin:7px 0 2px}
.st div{border:1px solid #D5DCE8;border-radius:7px;padding:4px 7px;font-size:10px;color:#3A4A66}
.st div b{display:block;font-size:11px;color:#0F1B33}
.st div.ok{border-color:#1E9E5A;background:#EEF9F2}
.st div.ok b::before{content:"✔ ";color:#1E9E5A}
h3{margin:7px 0 3px;font-size:11.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--k);border-bottom:1px solid #D5DCE8;padding-bottom:2px}
table{width:100%;border-collapse:collapse}
td{padding:2.5px 5px;vertical-align:top;border-bottom:1px dotted #D5DCE8;font-size:11px}
td.l{width:36%;color:#3A4A66;font-size:10.5px}
td.v{font-weight:600;white-space:pre-wrap;word-break:break-word}
td.v.m{color:#8A96AD;font-weight:500}
.blank td.v{height:7mm;border-bottom:1px solid #9AA6BD}
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(42mm,1fr));gap:6px;margin-top:3px}
.sg figure{margin:0;border:1px solid #D5DCE8;border-radius:7px;padding:4px 5px}
.sg img{display:block;max-width:100%;max-height:24mm;margin:0 auto 3px;background:#fff}
.sg figcaption{font-size:9.5px;color:#3A4A66}
.sg figcaption b{display:block;color:#0F1B33;font-size:10.5px}
.needs{columns:3;margin:3px 0 0;padding-left:15px;font-size:10px}
.decl{margin-top:7px;font-size:10.5px;color:#3A4A66;border:1px solid #D5DCE8;border-radius:7px;padding:5px 8px}
.office{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:7px}
.office div{border:1px solid #9AA6BD;border-radius:7px;height:15mm;padding:4px 6px;font-size:9.5px;color:#3A4A66}
/* the agreement */
.agh{text-align:center;margin:0 0 6px}
.agh .big{margin:0;font-size:18px;font-weight:800;letter-spacing:1px;color:var(--k)}
.agsep{border-top:2px dashed #D5DCE8;margin:8px 0 6px}
.agh .ti{font-size:13px;font-weight:700}
.agh .sub{font-size:10.5px;color:#3A4A66}
.agr{display:flex;gap:10px;align-items:center;border:1.5px solid var(--k);border-radius:8px;padding:5px 9px;font-size:10.5px;color:#3A4A66;margin-top:5px}
.agr b{color:#0F1B33;font-size:12px}
.agr .u{font-family:Consolas,monospace;color:#0F1B33;word-break:break-all}
.agr img{width:20mm;height:20mm;image-rendering:pixelated;display:block}
.agr .qt{font-size:9.5px;text-align:center;color:#3A4A66}
.vst{display:inline-block;margin-top:6px;padding:5px 12px;border-radius:6px;font-size:11.5px;font-weight:700;border:1.5px solid}
.vst.ok{color:#0B6B47;border-color:#0B6B47;background:#E9F7F1}
.vst.pend{color:#8A5200;border-color:#C27A12;background:#FFF5E6}
.vst.bad{color:#A3231A;border-color:#A3231A;background:#FDECEA}
.parties td.l{width:34%}
.parties td.v{height:5.4mm}
.sec{margin:5px 0 2px;font-size:11px;font-weight:800;color:var(--k);border-bottom:1px solid #D5DCE8;padding-bottom:1px}
.pt{margin:0 0 2px;font-size:10px;line-height:1.42;text-align:justify}
.pt b.n{display:inline-block;min-width:24px;color:var(--k)}
.fill{display:inline-block;min-width:24mm;border-bottom:1px solid #5d6b86;height:1.05em;vertical-align:-3px}
.accept{margin:6px 0 5px;padding:5px 8px;border:1.5px solid var(--k);border-radius:7px;font-size:10.5px;background:#FAFBFE}
.sgrid{display:grid;grid-template-columns:1fr 1fr;gap:6px}
.sbox{border:1px solid #9AA6BD;border-radius:7px;padding:6px 8px;font-size:10.5px;color:#3A4A66}
.sbox b{display:block;color:#0F1B33;font-size:11.5px;margin-bottom:2px}
.sbox .ln{display:flex;gap:6px;align-items:flex-end;margin:3px 0}
.sbox .ln span{white-space:nowrap}
.sbox .ln i{flex:1;border-bottom:1px solid #5d6b86;height:6mm;font-style:normal;font-weight:700;color:#0F1B33;display:flex;align-items:flex-end;padding:0 4px 1px}
.sbox img.sig{display:block;max-height:15mm;max-width:100%;margin:2px 0 0}
.thumbs{display:flex;gap:8px;margin-top:4px}
.thumbs div{flex:1;text-align:center;font-size:9.5px}
.thumbs .tb{height:22mm;border:1px dashed #9AA6BD;border-radius:50% / 45%;display:grid;place-items:center;overflow:hidden;background:#fff;margin:0 auto 2px;width:20mm}
.thumbs .tb img{height:100%;width:100%;object-fit:cover}
.bio{margin-top:4px;font-size:10px;color:#1E9E5A;font-weight:700}
/* 23 Oct 2026 (owner: "world top designer / Canva template jasto colourful, CV jasto form; A4 4 page - 2 page
   bharne thau, 2 page sambjhauta-patra"): page 1 opens like a CV (photo, name, contact on a colour band), every
   section is a card with its own colour, page 2 = documents, signature, verification and office use, pages 3-4
   = the agreement as a letter. The script below shrinks the type a step at a time until each half fits 2 pages. */
body.k-agent{--k:#1F4FE0;--k2:#F07A1E}body.k-staff{--k:#0E7A4A;--k2:#14B8A6}body.k-partner{--k:#C25E00;--k2:#7B5CFF}
body{--tf:10px;--tlh:1.42;--fh:7.6mm;--ph:34mm;--gap:3.2mm}
/* the agreement's terms run in two columns (block columns, so the PNG / PDF draw them too) */
.sheet .body.agb{display:flex;flex-direction:column}
.cols{flex:1 1 0;min-height:0;display:flex;gap:5mm;margin-top:1.5mm}
.col{flex:1 1 0;min-width:0;overflow:hidden}
.col .sgrid{grid-template-columns:1fr}
.col .sec:first-child{margin-top:0}
.sheet.d1{--tf:9.6px;--tlh:1.4;--fh:7mm;--ph:31mm;--gap:2.8mm}
.sheet.d2{--tf:9.2px;--tlh:1.36;--fh:6.5mm;--ph:28mm;--gap:2.4mm}
.sheet.d3{--tf:8.8px;--tlh:1.32;--fh:6mm;--ph:25mm;--gap:2mm}
.sheet.d4{--tf:8.4px;--tlh:1.28;--fh:5.6mm;--ph:22mm;--gap:1.6mm}
.sheet.d4 .parties td{padding:1.5px 5px}
.sheet.d4 .sbox{padding:4px 8px}.sheet.d4 .sbox .ln{margin:1px 0}.sheet.d4 .sbox .ln i{height:5mm}.sheet.d4 .thumbs .tb{height:16mm;width:15mm}.sheet.d4 .agh .agr img{width:14mm;height:14mm}.sheet.d4 .accept{margin:3px 0;padding:3px 8px}
.stripe{background:linear-gradient(90deg,var(--k) 0 45%,var(--k2) 45% 75%,#F5B400 75%)}
.hero{display:flex;gap:5mm;align-items:center;margin-top:1mm;padding:4.5mm 5mm;border-radius:5mm;color:#fff;position:relative;overflow:hidden;
  background:radial-gradient(70mm 46mm at 100% 0%,rgba(255,255,255,.24),rgba(255,255,255,0) 70%),linear-gradient(118deg,var(--k) 0%,var(--k) 52%,var(--k2) 135%)}
.hero{padding:3.6mm 4.5mm}
.hero .o1,.hero .o2{position:absolute;border-radius:50%;pointer-events:none}
.hero .o1{width:46mm;height:46mm;right:-14mm;bottom:-22mm;border:5mm solid rgba(255,255,255,.12)}
.hero .o2{width:16mm;height:16mm;left:38mm;top:-7mm;background:rgba(255,255,255,.12)}
.hero .pic{width:28mm;height:35mm;border:0;border-radius:4mm;background:#fff;box-shadow:0 0 0 1.1mm rgba(255,255,255,.45),0 3mm 6mm rgba(0,0,0,.25);color:#6B7A99;font-size:9.5px;line-height:1.35;flex:0 0 auto;position:relative;z-index:1}
.hero .pic.logo{width:22mm;height:22mm;align-self:flex-start}
.hx{flex:1;min-width:0;position:relative;z-index:1}
.hx .kbadge{background:#fff;color:var(--k)}
.hx h1{margin:1mm 0 0;font-size:22px;line-height:1.15;color:#fff;letter-spacing:.2px;word-break:break-word}
.hx h2{margin:1mm 0 2mm;font-size:12.5px;font-weight:600;color:rgba(255,255,255,.88)}
.hx .chips{display:flex;flex-wrap:wrap;gap:1.4mm}
.hx .chips span{padding:.8mm 2.6mm;border-radius:99px;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.35);font-size:10px;font-weight:600;white-space:nowrap}
.hx .fl{display:flex;gap:4mm;margin-top:2mm;font-size:10.5px}
.hx .fl span{flex:1;border-bottom:1.2px solid rgba(255,255,255,.75);padding-bottom:4mm}
.hqr{position:relative;z-index:1;background:#fff;border-radius:3mm;padding:1.6mm;text-align:center;color:#0F1B33;font-size:8.5px;font-weight:700;flex:0 0 auto}
.hqr img{width:21mm;height:21mm;display:block;image-rendering:pixelated}
.sx{margin-top:var(--gap);border:1px solid #E1E7F2;border-radius:3.5mm;overflow:hidden;background:#fff}
.sxh{display:flex;align-items:center;gap:2.4mm;padding:1.1mm 3mm;background:var(--at,#EEF3FC);border-bottom:1.5px solid var(--a,var(--k))}
.sxh i{font-style:normal;width:6.4mm;height:6.4mm;border-radius:50%;display:grid;place-items:center;background:var(--a,var(--k));color:#fff;font-size:11px;flex:0 0 auto}
.sxh b{font-size:11.5px;color:#0F1B33;letter-spacing:.2px}
.sxh small{margin-left:auto;font-size:9px;color:#5A6A88;text-align:right}
.fg{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0 4.5mm;padding:.6mm 3mm 1.6mm}
.fi{min-width:0;padding:1.1mm 0;border-bottom:1px dotted #D5DCE8}
.fi.w{grid-column:1/-1}
.fi .l{display:block;font-size:8.8px;color:#5A6A88}
.fi .v{display:block;font-size:11.5px;font-weight:650;color:#0F1B33;white-space:pre-wrap;word-break:break-word;min-height:4mm}
.fi .v.m{color:#8A96AD;font-weight:500}
.fg.blank .fi{border-bottom:0;padding:.9mm 0}
.fg.blank .fi .v{height:var(--fh);border-bottom:1.2px solid #9AA6BD}
.fg.blank .fi.tall .v{height:calc(var(--fh) * 2);background:repeating-linear-gradient(180deg,transparent 0 calc(var(--fh) - 1px),#C9D2E3 calc(var(--fh) - 1px) var(--fh))}
.paste{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2.6mm;padding:2.4mm 3mm}
.paste div{height:var(--ph);border:1.5px dashed #9AA6BD;border-radius:3mm;display:grid;place-items:center;align-content:center;text-align:center;font-size:9.5px;color:#6B7A99;background:#FBFCFE;line-height:1.5}
.paste b{color:#0F1B33;font-size:10.5px}
.signrow{display:grid;grid-template-columns:minmax(0,2.2fr) minmax(0,1fr) minmax(0,1fr);gap:3mm;padding:2.6mm 3mm 3mm;align-items:end}
.signrow .sgn{height:24mm;border:1px solid #9AA6BD;border-radius:3mm;display:flex;flex-direction:column;justify-content:flex-end;padding:1.5mm 2.5mm;font-size:9.5px;color:#3A4A66;background:linear-gradient(180deg,#fff 0 70%,#F7F9FD)}
.signrow .sgn img{height:15mm;width:auto;max-width:100%;object-fit:contain;align-self:center;margin-bottom:auto}
.signrow .tw{text-align:center;font-size:9.5px;color:#3A4A66}
.signrow .tw .tb{width:19mm;height:24mm;margin:0 auto 1mm;border:1.4px dashed #9AA6BD;border-radius:50% / 44%;overflow:hidden;background:#fff}
.signrow .tw .tb img{width:100%;height:100%;object-fit:cover}
.sx .sg{padding:2.6mm 3mm 3mm}
.sx .sg img{height:22mm;width:100%;max-height:none;object-fit:contain}
.vbox{display:flex;gap:4mm;align-items:center;margin-top:var(--gap);padding:3mm 4mm;border-radius:3.5mm;border:1.5px solid #CFDAF0;background:linear-gradient(115deg,#EEF4FF 0%,#F6F0FF 55%,#FFF2E6 100%)}
.vbox .vt{flex:1;min-width:0;font-size:10px;color:#3A4A66}
.vbox .vt b{display:block;font-size:12px;color:#0F1B33;margin-bottom:1mm}
.vbox ul{margin:1mm 0 0;padding:0;list-style:none;display:grid;grid-template-columns:1fr 1fr;gap:.6mm 3mm}
.vbox li::before{content:"◆ ";color:var(--k2)}
.vbox .u{font-family:Consolas,monospace;color:#0F1B33;word-break:break-all}
.vbox img{width:22mm;height:22mm;image-rendering:pixelated;background:#fff;padding:1mm;border-radius:2mm}
.vbox .st{margin:1.5mm 0 0}
.st{display:flex;flex-wrap:wrap;gap:1.5mm}
.st div{flex:1 1 30mm;border-radius:2.5mm;background:#fff}
.chk2{columns:2;column-gap:6mm;list-style:none;margin:0;padding:2.2mm 3mm 2.6mm;font-size:10px}
.chk2 li{break-inside:avoid;padding:.5mm 0}
.chk2 li::before{content:"☐ ";font-size:12px;color:var(--k)}
.decl{border-radius:3mm;border-color:#E1E7F2;background:#FAFBFE;margin-top:var(--gap)}
.decl .dl{display:flex;gap:5mm;margin-top:2mm}.decl .dl span{flex:1;border-bottom:1px solid #9AA6BD;padding-bottom:3.5mm}
.office{margin-top:var(--gap)}
.office div{border-style:dashed;border-radius:3mm;background:#fff}
/* the agreement: a letter on its own sheets */
.agh{padding:2.2mm 4mm 2.6mm;border-radius:4mm;border:1.5px solid var(--k);background:linear-gradient(180deg,#F7F9FF,#fff 70%)}
.agh .big{display:inline-block;padding:.6mm 6mm;border-radius:99px;background:linear-gradient(110deg,var(--k),var(--k2));color:#fff;font-size:15px;letter-spacing:1.5px}
.agh .agr{padding:3px 8px;margin-top:3px}.agh .agr img{width:17mm;height:17mm}.agh .sub{line-height:1.35}
.agh .ti{margin-top:1mm;font-size:13px;color:#0F1B33}
.agh .agr{background:#fff}
.sec{margin:1.6mm 0 .5mm;padding:.3mm 2.2mm;font-size:10.5px;border-bottom:0;border-left:1.2mm solid var(--k2);background:#F3F6FC;border-radius:0 2mm 2mm 0}
.pt{font-size:var(--tf);line-height:var(--tlh)}
.accept{border-radius:3mm;background:linear-gradient(115deg,#EEF4FF,#FFF4EA)}
.sbox{border-radius:3mm}
.sbox img.sig{height:13mm;max-height:none;width:auto;max-width:100%}
/* black and white, print ready */
body.bw{--k:#111}
body.bw .sheet{filter:grayscale(1)}
body.bw .stripe{height:2px;background:#111}
body.bw .kbadge{background:#fff;color:#111;border:1.5px solid #111}
body.bw .wm{color:rgba(0,0,0,.045)}
body.bw .st div.ok{background:#fff;border-color:#111}
body.bw .st div.ok b::before,body.bw .bio{color:#111}
body.bw .vst{color:#111!important;border-color:#111!important;background:#fff!important}
body.bw .pic,body.bw .accept{background:#fff}
body.bw{--k2:#111}
body.bw .hero{background:#fff;color:#111;border:2px solid #111}body.bw .hero .o1,body.bw .hero .o2{display:none}
body.bw .hx h1,body.bw .hx h2{color:#111}body.bw .hx .kbadge{border:1.5px solid #111}
body.bw .hx .chips span{background:#fff;border-color:#111}body.bw .hx .fl span{border-color:#111}
body.bw .hero .pic{box-shadow:none;border:1.5px solid #111}body.bw .hqr{border:1px solid #111}
body.bw .sxh,body.bw .vbox,body.bw .agh,body.bw .accept,body.bw .sec,body.bw .decl{background:#fff!important}
body.bw .sxh{border-bottom-color:#111}body.bw .sxh i{background:#111}body.bw .vbox{border-color:#111}
body.bw .agh .big{background:#111}body.bw .sec{border-left-color:#111}
body.bw .lh .co span,body.bw .pf,body.bw td.l,body.bw .sbox,body.bw .sg figcaption,body.bw .decl,body.bw .office div,body.bw .agh .sub{color:#222}
@media print{html,body{background:#fff}.bar,#flow{display:none!important}#pages{zoom:1!important;padding:0}.sheet{margin:0;box-shadow:none;break-after:page;page-break-after:always}.sheet:last-child{break-after:auto;page-break-after:auto}}
</style>
</head>
<body class="k-<?= $h($kind) ?><?= $bw ? ' bw' : '' ?>">
<div class="bar">
  <a href="<?= $h($bwHref) ?>"<?= $bw ? ' class="on"' : '' ?>><?= $bw ? '🎨 रङ्गीन · Colour' : '⚫ कालो-सेतो · Black &amp; white' ?></a>
  <button class="pri" type="button" id="doPrint">🖨 छाप्नुहोस् · Print</button>
  <button class="pri" type="button" id="doPdf">📄 PDF</button>
  <button class="pri" type="button" id="doPng">🖼 PNG</button>
  <button class="pri" type="button" id="doJpg">JPG</button>
  <?php if ($public): ?>
    <?php if (defined('SHG_A4_TEAM')): ?><a href="/team/">← <?= $h('मेरो पोर्टल · My portal') ?></a><?php else: ?>
    <?php foreach (['staff' => 'कर्मचारी · Staff', 'agent' => 'एजेन्ट · Agent', 'partner' => 'साझेदार · Partner'] as $kk => $kl): ?><a href="?kind=<?= $h($kk) ?><?= $bw ? '&amp;bw=1' : '' ?>"<?= !$mine && $kind === $kk ? ' class="on"' : '' ?>><?= $h($kl) ?></a><?php endforeach; ?>
    <a href="/agreements/#forms">← <?= $h('फर्कनुहोस् · Back') ?></a><?php endif; ?>
  <?php else: ?>
    <?php if ($blank === ''): ?><a href="<?= $type === 'staff' ? 'staff-applications.php?id=' . (int) $id . '#detail' : 'agreements.php?tab=apps&amp;id=' . (int) $id . '#detail' ?>">← Back to the application</a><?php endif; ?>
    <a href="?blank=<?= $h($kind === 'partner' ? 'partner' : ($kind === 'staff' ? 'staff' : 'agent')) ?>">Blank form</a>
  <?php endif; ?>
  <?php if (count(AgreementPrint::LANGS[$kind] ?? []) > 1): ?>
  <span class="lg">सम्झौताको भाषा · Agreement in:
    <?php foreach (AgreementPrint::LANGS[$kind] as $l): ?><a href="<?= $h($langHref($l)) ?>"<?= $l === $agrLang ? ' class="on"' : '' ?>><?= $h(AgreementPrint::LANG_LABELS[$l]) ?></a><?php endforeach; ?>
  </span>
  <?php endif; ?>
  <small id="exMsg"><?= $mine ? 'तपाईंले भर्नुभएको फारम र सम्झौता — PNG, PDF वा छापेर राख्नुहोस्। · Your filled form and agreement.' : ($blank !== '' ? 'छापेर हातले भर्नुहोस्, वा अनलाइन भर्नुहोस्: ' . $h($shortUrl((string) ($agrDoc[2] ?? ''))) . ' · Print and fill by hand, or apply online.' : 'In the print dialog choose “Save as PDF”, paper A4, margins None.' . (!$canKyc ? ' Photo, ID, signatures and bank details print only for a manager.' : '')) ?></small>
</div>

<div id="pages" aria-label="A4 pages"></div>

<template id="chrome">
  <div class="sheet">
    <div class="wm"><?= $h($co['name']) ?></div>
    <div class="lh">
      <img src="<?= $h($logo) ?>" alt="<?= $h($co['name']) ?> logo">
      <div class="co"><b><?= $h(mb_strtoupper((string) $co['legal'])) ?></b>
        <span><?= $h($co['address']) ?></span>
        <span>📞 <?= $h($co['phone']) ?> · ✉ <?= $h($co['email']) ?> · 🌐 <?= $h($co['web']) ?></span></div>
      <div class="ids"><span class="kbadge"><?= $h($kindShort) ?></span><span class="idx"><br><?php if ((string) ($co['cin'] ?? '') !== ''): ?>CIN <?= $h($co['cin']) ?><br><?php endif; ?><?php if ((string) ($co['gstin'] ?? '') !== ''): ?>GSTIN <?= $h($co['gstin']) ?><br><?php endif; ?>India ⇄ Nepal bus service</span></div>
    </div>
    <div class="stripe"></div>
    <div class="body"></div>
    <div class="pf"><span><?= $footRef ?></span><span><?= $h($co['web']) ?></span><span class="pno"></span></div>
  </div>
</template>

<div id="flow">
  <div class="blk hero">
    <i class="o1"></i><i class="o2"></i>
    <div class="pic"><?= $photo !== '' ? '<img src="' . $h($photo) . '" alt="Photo">' : ($blank !== '' ? 'पासपोर्ट साइजको<br>फोटो टाँस्नुहोस्<br><b>Paste photo</b>' : 'Photo — ' . ($canKyc ? 'not sent' : 'manager only')) ?></div>
    <div class="hx">
      <span class="kbadge"><?= $h($kindBadge) ?></span>
      <?php if ($heroName !== ''): ?>
      <h1><?= $h($heroName) ?></h1>
      <h2><?= $h($titleNe) ?> · <?= $h($title) ?></h2>
      <div class="chips"><?php foreach ($heroChips as $c): ?><span><?= $h($c) ?></span><?php endforeach; ?></div>
      <?php else: ?>
      <h1><?= $h($titleNe) ?></h1>
      <h2><?= $h($title) ?> · <?= $h($co['legal']) ?></h2>
      <div class="fl"><span>फारम नं. · Form no.</span><span>मिति · Date</span></div>
      <?php endif; ?>
    </div>
    <?php if ($kind === 'partner'): ?><div class="pic logo"><?= $coLogo !== '' ? '<img src="' . $h($coLogo) . '" alt="Company logo">' : 'कम्पनीको<br>लोगो · Logo' ?></div><?php endif; ?>
    <?php if ($qrImg !== ''): ?><div class="hqr"><img src="<?= $h($qrImg) ?>" alt="QR"><?= $blank !== '' ? 'अनलाइन भर्नुहोस्<br>Fill online' : 'प्रमाणित · Verify' ?></div><?php endif; ?>
  </div>

  <?php $si = 0; foreach ($sections as $sec => $rows): [$ac, $at] = $secPal[$si++ % count($secPal)]; ?>
  <div class="blk sx" style="--a:<?= $ac ?>;--at:<?= $at ?>"><div class="sxh"><i><?= $secIcon($sec) ?></i><b><?= $h($secName[$sec] ?? $sec) ?></b></div>
  <div class="fg<?= $blank !== '' ? ' blank' : '' ?>"><?php foreach ($rows as [$l, $v, $m]): ?><div class="fi <?= $fieldCls((string) $l, (string) $v) ?>"><span class="l"><?= $h($l) ?></span><span class="v<?= $m ? ' m' : '' ?>"><?= $h($v) ?></span></div><?php endforeach; ?></div></div>
  <?php endforeach; ?>

  <div class="pgform2"></div>
  <div class="blk sx" style="--a:#7B5CFF;--at:#F0ECFF"><div class="sxh"><i>🗂️</i><b>कागजात · Documents</b><small><?= $blank !== '' ? 'प्रतिलिपि यहाँ टाँस्नुहोस् · paste copies here' : 'अनलाइन पठाइएको · as sent online' ?></small></div>
  <?php if ($blank !== ''): ?>
    <div class="paste"><?php foreach ($pasteBoxes as [$ne, $en]): ?><div><span><?= $h($ne) ?></span><b><?= $h($en) ?></b></div><?php endforeach; ?></div>
  <?php elseif ($signs !== []): ?>
    <div class="sg"><?php foreach ($signs as [$l, $img, $d]): ?><figure><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="<?= $h($l) ?>"><?php endif; ?><figcaption><b><?= $h($l) ?></b><?= $h($d) ?></figcaption></figure><?php endforeach; ?></div>
  <?php else: ?>
    <div class="sg"><figure><figcaption><b><?= $canKyc ? 'No documents sent online' : 'Documents print for a manager only' ?></b></figcaption></figure></div>
  <?php endif; ?></div>

  <div class="blk sx" style="--a:#E8457C;--at:#FDEAF0"><div class="sxh"><i>✍️</i><b>सही र औंठाछाप · Signature &amp; thumbprints</b><small><?= $passkey ? '✔ फोनको फिंगरप्रिन्ट लक दर्ता · phone fingerprint registered' : '' ?></small></div>
    <div class="signrow">
      <div class="sgn"><?php if ($sigImg !== ''): ?><img src="<?= $h($sigImg) ?>" alt="Signature"><?php endif; ?><span>आवेदकको सही · Applicant’s signature<?= $sigImg !== '' ? ' (अनलाइन · signed on the phone)' : '' ?></span></div>
      <div class="tw"><div class="tb"><?= $thumb !== '' ? '<img src="' . $h($thumb) . '" alt="Right thumb">' : '' ?></div>दायाँ औंठा · Right thumb</div>
      <div class="tw"><div class="tb"></div>बायाँ औंठा · Left thumb</div>
    </div></div>

  <div class="blk vbox">
    <div class="vt"><b>📲 डिजिटल प्रमाणीकरण · Digital verification</b>
    <?php if ($status !== []): ?>
      <div class="st"><?php foreach ($status as [$l, $v, $ok]): ?><div class="<?= $ok ? 'ok' : '' ?>"><?= $h($l) ?><b><?= $h($v) ?></b></div><?php endforeach; ?></div>
    <?php else: ?>
      यही फारम मोबाइलमा ५ मिनेटमा भर्न सकिन्छ · Fill this same form on a phone: <span class="u"><?= $h($formUrl) ?></span>
      <ul><li>WhatsApp नम्बर प्रमाण · number proof</li><li>AI ले ID जाँच · AI ID check</li><li>लाइभ सेल्फी · live selfie</li><li>फोनको फिंगरप्रिन्ट · phone fingerprint</li></ul>
    <?php endif; ?></div>
  </div>

  <div class="blk sx" style="--a:#11A35A;--at:#E6F6EC"><div class="sxh"><i>✅</i><b>संलग्न कागजात · KYC checklist <?= $kind === 'partner' ? '(company)' : '' ?></b></div>
  <ul class="chk2"><?php foreach ($needs as $n): ?><li><?= $h($n) ?></li><?php endforeach; ?></ul></div>

  <div class="blk decl">म माथिका विवरण साँचो हुन् र कागजात मेरै<?= $kind === 'partner' ? ' / कम्पनीकै' : '' ?> हुन् भनी घोषणा गर्छु। · I declare that the details above are true and the documents are mine<?= $kind === 'partner' ? ' / my company’s, and that I am authorised to sign for the company' : '' ?>. I agree to the <?= $h($kind === 'staff' ? 'staff' : ($kind === 'partner' ? 'partner' : 'agent')) ?> agreement of <?= $h($co['legal']) ?>.
  <?php if ($blank !== ''): ?><div class="dl"><span>स्थान · Place</span><span>मिति · Date</span><span>सही · Signature</span></div><?php endif; ?></div>

  <div class="blk office"><div>कार्यालय प्रयोग · Office use — checked by (name)</div><div>Original ID seen ☐ · KYC verified ☐ · Approved ☐</div><div>Signature, date &amp; stamp</div></div>

  <?php if ($agrDoc !== null): ?>
  <div class="pgbreak"></div>
  <div class="blk agh">
    <div class="big">सम्झौता-पत्र · AGREEMENT</div>
    <div class="ti"><?= $h($agrDoc[0]) ?></div>
    <div class="sub">यो सम्झौता <?= $h($co['legal']) ?> (पहिलो पक्ष) र तल लेखिएको <?= $h($agrDoc[3]) ?> (दोस्रो पक्ष) बीच भएको हो। · This agreement is between the Company (first party) and the <?= $h($kind === 'partner' ? 'partner company' : ($kind === 'staff' ? 'staff member' : 'agent')) ?> below (second party).</div>
    <?php if ($standing !== null):
        $vs = [
            'in_force'  => ['ok', '✔ स्वीकृत · VERIFIED — approved by the office' . ($standing['approved'] !== '' ? ' ' . $fmtD($standing['approved']) : '')],
            'received'  => ['pend', '⏳ कार्यालयको स्वीकृति बाँकी · PENDING office approval — not in force yet'],
            'suspended' => ['bad', '⛔ निलम्बित · SUSPENDED'],
            'ended'     => ['bad', '⛔ समाप्त · ENDED'],
            'refused'   => ['bad', '⛔ स्वीकार भएन · NOT ACCEPTED'],
        ][$standing['state']] ?? null; ?>
    <div class="vst <?= $vs !== null ? $vs[0] : 'pend' ?>">सम्झौता नं. · Agreement No. <b><?= $h($ref) ?></b><?= $vs !== null ? ' · ' . $h($vs[1]) : '' ?></div>
    <?php endif; ?>
    <div class="agr" style="text-align:left">
      <div style="flex:1;min-width:0"><b>अनलाइन पढ्नुहोस् र सही गर्नुहोस् · Read and sign online:</b><br><span class="u"><?= $h($shortUrl($agrDoc[1])) ?></span><br>
        <?php if ($blank !== ''): ?>यो फारम मोबाइलमै भर्न सकिन्छ · Fill this form online:<br><span class="u"><?= $h($shortUrl($agrDoc[2])) ?></span><?php else: ?><?php if ($verifyUrl !== ''): ?>सक्कल हो कि होइन QR स्क्यान गरी जाँच्नुहोस् · Scan the QR to check this agreement is genuine and in force.<br><?php endif; ?>कागजमा सही गर्ने भए हरेक पानामा छोटो सही, अन्तिममा पूरा सही र औंठाछाप · On paper: initials on every page, full signature and thumbprint at the end.<?php endif; ?></div>
      <?php if ($qrImg !== ''): ?><div><img src="<?= $h($qrImg) ?>" alt="QR"><div class="qt"><?= $blank !== '' ? 'स्क्यान: अनलाइन फारम' : ($verifyUrl !== '' ? 'स्क्यान: प्रमाणीकरण · Verify' : 'स्क्यान: सम्झौता') ?></div></div><?php endif; ?>
    </div>
  </div>

  <div class="blk colstart"><h3>पक्षहरू · The parties</h3>
  <table class="parties">
    <tr><td class="l">पहिलो पक्ष — कम्पनी · First party — the Company</td><td class="v"><?= $h($co['legal']) ?><br><span style="font-weight:500"><?= $h($co['address']) ?><?= (string) ($co['cin'] ?? '') !== '' ? ' · CIN ' . $h($co['cin']) : '' ?></span></td></tr>
    <tr><td class="l" colspan="2" style="font-weight:700;color:var(--k)">दोस्रो पक्ष — <?= $h($agrDoc[3]) ?> · Second party</td></tr>
    <?php foreach ($party as [$l, $v]): ?><tr><td class="l"><?= $h($l) ?></td><td class="v"><?= $h($v) ?></td></tr><?php endforeach; ?>
    <tr><td class="l">मिति · स्थान · Date · place</td><td class="v"><?= $mine || $blank === '' ? $h('Applied online ' . $fmtD($rec['created_at'] ?? null)) : '' ?></td></tr>
  </table></div>

  <?php foreach ($agrSecs as $sx): $first = true; ?>
    <?php foreach ($sx['points'] as $pt): ?>
  <div class="blk"><?php if ($first): $first = false; ?><div class="sec"><?= $h(trim(($sx['no'] !== '' ? $sx['no'] . '. ' : '') . $sx['title'])) ?></div><?php endif; ?><p class="pt"><?php if ($pt['num'] !== ''): ?><b class="n"><?= $h($pt['num']) ?></b> <?php endif; ?><?= $pt['html'] ?></p></div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <?php if ($agrSecs === []): ?><div class="blk pt">सम्झौता यहाँ खुलेन — पूरा सम्झौता <?= $h($shortUrl($agrDoc[1])) ?> मा पढ्नुहोस्। · Read the full agreement online.</div><?php endif; ?>

  <div class="blk accept tail">मैले/हामीले माथिका सबै नियम र सर्त पढें (वा पढेर सुनाइयो), बुझें र आफ्नै खुसीले स्वीकार गर्छु। · I/We have read (or had read to me), understood and freely accept every term above.</div>

  <div class="blk sgrid tail">
    <div class="sbox"><b>पहिलो पक्ष — कम्पनीको तर्फबाट · For the Company</b>
      <div class="ln"><span>सही · Signature</span><i></i></div>
      <div class="ln"><span>नाम · पद · Name · designation</span><i></i></div>
      <div class="ln"><span>मिति · Date</span><i></i></div>
      <div class="thumbs"><div><div class="tb" style="border-radius:8px;width:30mm">&nbsp;</div>कम्पनीको छाप · Company stamp</div></div>
    </div>
    <div class="sbox"><b>दोस्रो पक्ष — <?= $h($agrDoc[3]) ?> · Second party</b>
      <?php if ($sigImg !== ''): ?><img class="sig" src="<?= $h($sigImg) ?>" alt="Signature"><div style="font-size:9.5px">अनलाइन सही · signed on the phone</div><?php else: ?><div class="ln"><span>सही · Signature</span><i></i></div><?php endif; ?>
      <div class="ln"><span>नाम · Name</span><i><?= $h((string) ($party[0][1] ?? '')) ?></i></div>
      <div class="thumbs">
        <div><div class="tb"><?= $thumb !== '' ? '<img src="' . $h($thumb) . '" alt="Right thumb">' : '&nbsp;' ?></div>दायाँ औंठा · Right thumb</div>
        <div><div class="tb">&nbsp;</div>बायाँ औंठा · Left thumb</div>
      </div>
      <?php if ($passkey): ?><div class="bio">✔ फोनको फिंगरप्रिन्ट / Face ID लक दर्ता · Phone fingerprint / Face ID lock registered</div><?php endif; ?>
    </div>
  </div>
  <?php if ($kind === 'partner'): /* 23 Oct 2026 (owner): witnesses for a partner company only */ ?>
  <div class="blk sgrid tail" style="margin-top:var(--gap)">
    <?php foreach ([1 => 'साक्षी १ · Witness 1', 2 => 'साक्षी २ · Witness 2'] as $wn => $wl): ?>
    <div class="sbox"><b><?= $h($wl) ?></b>
      <div class="ln"><span>नाम · Name</span><i><?= $h($wit[$wn - 1][0]) ?></i></div><div class="ln"><span>ठेगाना / फोन · Address / phone</span><i><?= $h($wit[$wn - 1][1]) ?></i></div><div class="ln"><span>सही · Signature</span><i></i></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<script>
/* 22 Oct 2026: the blocks above are poured into A4 sheets (#chrome: letterhead + page footer); a block that
   does not fit starts the next sheet. On a phone the sheets are only shown smaller (zoom), never reflowed, so
   the PNG / PDF / print are the same pages everywhere. */
(function () {
  var flow = document.getElementById('flow'), pages = document.getElementById('pages'), tpl = document.getElementById('chrome');
  var msg = document.getElementById('exMsg'), busy = false;
  var name = <?= json_encode(($ref !== '' ? $ref : 'blank-' . $kind) . '-' . date('Ymd') . ($bw ? '-bw' : ''), JSON_UNESCAPED_SLASHES) ?>;

  var lvF = 0, lvA = 0, inAgr = false;   // the type step of the form half and of the agreement half
  function sheetBody(full) {
    var s = tpl.content.firstElementChild.cloneNode(true);
    if (inAgr ? lvA : lvF) s.classList.add('d' + (inAgr ? lvA : lvF));
    if (pages.querySelector('.sheet') && !full) s.classList.add('cont');   // a slim letterhead from page 2 (23 Oct 2026)
    pages.appendChild(s);
    return s.querySelector('.body');
  }
  /* 23 Oct 2026 (owner: "A4 4 page - 2 page bharne thau, 2 page sambjhauta"): the form is pages 1-2 (page 2 =
     documents, signature, verification, office use) and the agreement opens on a fresh sheet with the full
     letterhead, like a letter. When a half runs past 2 pages the type steps down (d1..d4) and it is poured again. */
  var master = flow.cloneNode(true);
  flow.parentNode.removeChild(flow);
  function used(body) {
    var last = body.lastElementChild;   // scrollHeight is never less than the box, so measure the content itself
    return last ? (last.getBoundingClientRect().bottom - body.getBoundingClientRect().top) / body.clientHeight : 0;
  }
  function makeCols(body) {
    body.classList.add('agb');
    var c = document.createElement('div'); c.className = 'cols';
    c.appendChild(document.createElement('div')).className = 'col';
    c.appendChild(document.createElement('div')).className = 'col';
    body.appendChild(c);
    return c;
  }
  function hOf(c) { var l = c.lastElementChild; return l ? l.getBoundingClientRect().bottom - c.getBoundingClientRect().top : 0; }
  /* the last columns of the agreement: even them out, then the box takes only their height */
  function closeCols(cols) {
    var L = cols.children[0], R = cols.children[1];
    for (var i = 0; i < 60 && L.children.length > 1; i++) {
      var lb = L.lastElementChild, bh = lb.getBoundingClientRect().height;
      if (hOf(R) + bh >= hOf(L)) break;
      R.insertBefore(lb, R.firstChild);
    }
    cols.style.flex = '0 0 auto';
    cols.style.height = Math.ceil(Math.max(hOf(L), hOf(R)) + 2) + 'px';
  }
  function pour() {
    pages.innerHTML = '';
    inAgr = false;
    var body = sheetBody(), agrAt = -1, inCols = false, cols = null, ci = 0;
    Array.prototype.slice.call(master.cloneNode(true).children).forEach(function (b) {
      if (b.classList.contains('pgbreak')) {   // the agreement: a fresh sheet with the full letterhead
        agrAt = pages.children.length - (body.children.length ? 0 : 1);   // sheets the form used
        inAgr = true;
        if (body.children.length) body = sheetBody(true);
        else { body.parentNode.className = 'sheet' + (lvA ? ' d' + lvA : ''); }
        return;
      }
      if (b.classList.contains('pgform2')) {   // documents + signature start page 2 when page 1 is mostly used
        if (body.children.length && used(body) > 0.55) body = sheetBody();
        return;
      }
      if (inCols && b.classList.contains('tail')) {   // the acceptance and signature boxes run full width under the terms
        if (cols) { closeCols(cols); cols = null; }
        body.appendChild(b);
        if (body.scrollHeight > body.clientHeight + 1 && body.children.length > 1) { body = sheetBody(); body.appendChild(b); }
        return;
      }
      if (inCols) {
        if (!cols) { cols = makeCols(body); ci = 0; }
        var c = cols.children[ci];
        c.appendChild(b);
        if (c.scrollHeight > c.clientHeight + 1 && c.children.length > 1) {
          if (ci === 0) ci = 1; else { body = sheetBody(); cols = makeCols(body); ci = 0; }
          cols.children[ci].appendChild(b);
        }
        return;
      }
      body.appendChild(b);
      if (body.scrollHeight > body.clientHeight + 1 && body.children.length > 1) { body = sheetBody(); body.appendChild(b); }
      if (b.classList.contains('colstart')) inCols = true;
    });
    var n = pages.children.length;
    return { form: agrAt < 0 ? n : agrAt, agr: agrAt < 0 ? 0 : n - agrAt };
  }
  function paginate() {
    var r;
    for (;;) {
      r = pour();
      var more = false;
      if (r.form > 2 && lvF < 4) { lvF++; more = true; }
      if (r.agr > 2 && lvA < 4) { lvA++; more = true; }
      if (!more) break;
    }
    var all = pages.querySelectorAll('.sheet');
    for (var i = 0; i < all.length; i++) all[i].querySelector('.pno').textContent = 'पाना ' + (i + 1) + ' / ' + all.length + ' · Page';
    fit();
    window.addEventListener('resize', fit);
  }
  function fit() {
    if (busy) return;
    var s = pages.querySelector('.sheet'); if (!s) return;
    var z = Math.min(1, (document.documentElement.clientWidth - 10) / s.offsetWidth);
    pages.style.zoom = z < 0.999 ? String(z) : '';
  }
  var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
  var loaded = new Promise(function (r) { if (document.readyState === 'complete') r(); else window.addEventListener('load', r); });
  var ready = Promise.all([fonts, loaded]).then(paginate);

  document.getElementById('doPrint').addEventListener('click', function () { ready.then(function () { window.print(); }); });

  /* PDF / PNG / JPG: each sheet exactly as shown, by html2canvas (cdnjs, jsdelivr fallback); the libraries
     load only on the first tap. PNG and JPG save one file per page. */
  function load(urls) {
    return new Promise(function (ok, bad) {
      var i = 0;
      (function next() {
        if (i >= urls.length) { bad(new Error('load')); return; }
        var s = document.createElement('script'); s.src = urls[i++]; s.onload = ok; s.onerror = next; document.head.appendChild(s);
      })();
    });
  }
  function libs(pdf) {
    var a = window.html2canvas ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js']);
    var b = !pdf || (window.jspdf && window.jspdf.jsPDF) ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js']);
    return Promise.all([a, b]);
  }
  function save(blob, file) {
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }
  function wait(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
  /* B&W: html2canvas does not apply the CSS filter, so the drawn page is greyed here (luma) */
  var BW = document.body.classList.contains('bw');
  function grey(cv) {
    try {
      var x = cv.getContext('2d'), im = x.getImageData(0, 0, cv.width, cv.height), d = im.data;
      for (var i = 0; i < d.length; i += 4) { var g = d[i] * 0.299 + d[i + 1] * 0.587 + d[i + 2] * 0.114; d[i] = d[i + 1] = d[i + 2] = g; }
      x.putImageData(im, 0, 0);
    } catch (e) {}   // a tainted canvas keeps its colours rather than failing the PDF
  }
  function run(kind, btn) {
    if (busy) return;
    busy = true;
    var label = btn.textContent, sy = window.scrollY, z = pages.style.zoom;
    btn.disabled = true; btn.textContent = '⏳ …';
    var all = [], pdf = null, scale = kind === 'png' ? 2.5 : 2;
    ready.then(function () { return libs(kind === 'pdf'); }).then(function () {
      pages.style.zoom = ''; window.scrollTo(0, 0);
      all = Array.prototype.slice.call(pages.querySelectorAll('.sheet'));
      if (kind === 'pdf') pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      var chain = Promise.resolve();
      all.forEach(function (sh, i) {
        chain = chain.then(function () {
          if (msg) msg.textContent = '⏳ पाना ' + (i + 1) + ' / ' + all.length + ' · page';
          return window.html2canvas(sh, { scale: scale, backgroundColor: '#ffffff', useCORS: true, logging: false,
            // html2canvas ignores object-fit, which stretched the photo, logo and thumbprint: in the copy it draws,
            // each becomes a background with cover / contain (the page and Print are untouched)
            onclone: function (doc) {
              Array.prototype.forEach.call(doc.querySelectorAll('.pic img, .thumbs .tb img, .signrow img, .sx .sg img'), function (im) {
                var d = doc.createElement('div'), src = String(im.getAttribute('src') || '').replace(/["\\\n\r]/g, '');
                d.style.cssText = 'width:100%;height:' + (im.closest('.sgn') ? '15mm' : im.closest('.sg') ? '22mm' : '100%') + ';margin-bottom:' + (im.closest('.sg') ? '1mm' : '0')
                  + ';background:#fff url("' + src + '") center/' + (im.closest('.logo, .sgn, .sg') ? 'contain' : 'cover') + ' no-repeat';
                im.parentNode.replaceChild(d, im);
              });
            } });
        }).then(function (cv) {
          if (BW) grey(cv);
          if (kind === 'pdf') {
            if (i > 0) pdf.addPage();
            pdf.addImage(cv.toDataURL('image/jpeg', 0.85), 'JPEG', 0, 0, 210, 297);   // ~0.4 MB a page: easy to send on WhatsApp
            return null;
          }
          return new Promise(function (r) {
            cv.toBlob(function (b) { save(b, name + (all.length > 1 ? '-p' + (i + 1) : '') + (kind === 'png' ? '.png' : '.jpg')); r(); },
              kind === 'png' ? 'image/png' : 'image/jpeg', 0.92);
          }).then(function () { return wait(700); });
        });
      });
      return chain;
    }).then(function () {
      if (pdf) save(pdf.output('blob'), name + '.pdf');
      if (msg) msg.textContent = '✓ ' + (kind === 'pdf' ? 'PDF' : all.length + ' ' + kind.toUpperCase()) + ' — डाउनलोडमा हेर्नुहोस् · saved to Downloads';
    }).catch(function () {
      if (msg) msg.textContent = 'अहिले बन्न सकेन — "छाप्नुहोस्" थिचेर "Save as PDF" रोज्नुहोस्। · Could not build it now: use Print → Save as PDF.';
    }).then(function () {
      pages.style.zoom = z; window.scrollTo(0, sy);
      busy = false; btn.disabled = false; btn.textContent = label; fit();
    });
  }
  document.getElementById('doPdf').addEventListener('click', function () { run('pdf', this); });
  document.getElementById('doPng').addEventListener('click', function () { run('png', this); });
  document.getElementById('doJpg').addEventListener('click', function () { run('jpg', this); });
<?php if ($autoPrint): ?>  ready.then(function () { setTimeout(function () { window.print(); }, 300); });
<?php elseif ($autoPdf): ?>  ready.then(function () { setTimeout(function () { run('pdf', document.getElementById('doPdf')); }, 400); });
<?php endif; ?>
})();
</script>
</body>
</html>
