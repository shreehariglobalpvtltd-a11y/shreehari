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
if ($public) {
    $kindQ = (string) ($_GET['kind'] ?? '');
    $own   = is_array($_SESSION['a4_mine'] ?? null) ? $_SESSION['a4_mine'] : null;
    if (!empty($_GET['mine']) && $own !== null && (int) ($own['t'] ?? 0) > time() - 6 * 3600 && (int) ($own['id'] ?? 0) > 0) {
        $mine = true;
        $_GET['type'] = (string) ($own['type'] ?? '') === 'staff' ? 'staff' : 'agent';
        $_GET['id']   = (string) (int) $own['id'];
        unset($_GET['blank']);
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

$rec = null;          // the row
$sections = [];       // title => [[label, value, masked?], ...]
$photo = '';          // <img src>
$coLogo = '';         // a partner's own logo
$signs = [];          // [[label, img src|'', detail], ...]
$status = [];         // [label, value, ok?]
$kind = '';
$ref = '';

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
            'Witnesses'       => ['w1_name', 'w1_addr', 'w2_name', 'w2_addr'],
        ];
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
                if (is_array($e) && ($t = $line([$e['level'] ?? '', $e['school'] ?? '', $e['year'] ?? ''])) !== '') {
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
        }
        foreach (Database::fetchAll("SELECT * FROM agreement_signatures WHERE party_type = 'agent' AND party_id = :i ORDER BY id DESC LIMIT 4", ['i' => (int) $rec['id']]) as $sg) {
            $img = $canKyc ? (TeamPortal::signImage($sg) ?? '') : '';
            $signs[] = [ucfirst((string) $sg['doc']) . ' agreement v' . (string) $sg['version'], $img,
                'SIG-' . str_pad((string) (int) $sg['id'], 6, '0', STR_PAD_LEFT) . ' · ' . $fmtDT($sg['signed_at'] ?? null) . ($canKyc ? ' · IP ' . (string) ($sg['ip_address'] ?? '') : '')];
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
        $sections['Applicant'] = [
            ['Name', (string) $rec['full_name'], false], ['Job applied for', StaffApps::roleLabel((string) $rec['role_applied']), false],
            ['Mobile', trim(((string) ($rec['phone_country'] ?? '') !== '' ? '+' . $rec['phone_country'] . ' ' : '') . (string) $rec['phone']), false],
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
        foreach (is_array($refs) ? array_slice($refs, 0, 3) : [] as $i => $r) {
            if (is_array($r) && trim(implode('', array_map('strval', $r))) !== '') {
                $refRows[] = ['Reference ' . ($i + 1), implode(' · ', array_filter(array_map('strval', [$r['name'] ?? '', $r['phone'] ?? '', $r['relation'] ?? ($r['rel'] ?? '')]))), false];
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
                $signs[] = ['Signature on the form', $staffImg($rec, 'signature'), 'drawn on the phone · ' . $fmtDT($rec['created_at'] ?? null)];
            }
        }
        foreach (StaffApps::signs((int) $rec['id']) as $sg) {
            $signs[] = ['Staff agreement v' . (string) $sg['version'] . ' (' . (string) $sg['event'] . ')', '',
                'SIG-' . str_pad((string) (int) $sg['id'], 6, '0', STR_PAD_LEFT) . ' · ' . $fmtDT($sg['signed_at'] ?? null) . ((int) $sg['phone_verified'] === 1 ? ' · phone proven' : '') . ($canKyc ? ' · IP ' . (string) ($sg['ip_address'] ?? '') : '')];
        }
        $sealSrc = 'staff|' . (int) $rec['id'] . '|' . json_encode(array_diff_key($rec, array_flip(['updated_at', 'reminded_at', 'last_login_at', 'failed_logins', 'locked_until', 'password_hash'])));
    }
    $seal = strtoupper(substr(hash_hmac('sha256', $sealSrc, APP_KEY . '|application-print'), 0, 16));
    $seal = implode('-', str_split($seal, 4));
    Logger::audit('application.print', $type === 'staff' ? 'staff_application' : 'agent_application', $ref, null, null,
        $mine ? 'A4 copy opened by the applicant (own browser session, right after sending)'
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
            'साक्षी · Witnesses' => $blankRows(['साक्षी १ (नाम · ठेगाना) · Witness 1', 'साक्षी २ (नाम · ठेगाना) · Witness 2']),
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
$autoPrint = isset($_GET['print']);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $h(($ref !== '' ? $ref . ' · ' : '') . $title) ?> — <?= $h($co['name']) ?></title>
<style>
@page{size:A4;margin:11mm 11mm 13mm}
*{box-sizing:border-box}
html,body{margin:0;background:#E9EDF3;color:#0F1B33;font:12.5px/1.45 "Segoe UI",Roboto,Arial,"Noto Sans Devanagari",sans-serif}
[hidden]{display:none!important}
.bar{position:sticky;top:0;z-index:5;display:flex;gap:8px;align-items:center;justify-content:center;flex-wrap:wrap;padding:10px;background:#0F1B33;color:#fff}
.bar button,.bar a{font:600 13px inherit;font-family:inherit;padding:9px 16px;border-radius:9px;border:0;cursor:pointer;text-decoration:none;color:#0F1B33;background:#fff}
.bar button.pri{background:#F07A2E;color:#fff}
.bar small{opacity:.75;flex-basis:100%;text-align:center}
.sheet{width:210mm;min-height:297mm;margin:14px auto;background:#fff;padding:11mm 11mm 13mm;box-shadow:0 6px 30px rgba(15,27,51,.18);position:relative}
.lh{display:flex;align-items:center;gap:14px;border-bottom:3px solid #1F3C88;padding-bottom:8px}
.lh img{height:62px;width:auto}
.lh .co{flex:1;min-width:0}
.lh .co b{display:block;font-size:19px;letter-spacing:.3px;color:#1F3C88}
.lh .co span{display:block;font-size:11px;color:#3A4A66}
.lh .ids{text-align:right;font-size:10.5px;color:#3A4A66;white-space:nowrap}
.stripe{height:4px;background:linear-gradient(90deg,#1F3C88 0 60%,#F07A2E 60%)}
.head{display:flex;gap:14px;margin-top:12px;align-items:flex-start}
.head .t{flex:1}
.head h1{margin:0;font-size:20px;color:#0F1B33}
.head h2{margin:2px 0 6px;font-size:14px;font-weight:600;color:#3A4A66}
.meta{display:flex;flex-wrap:wrap;gap:6px 14px;font-size:11.5px;color:#3A4A66}
.meta b{color:#0F1B33}
.pics{display:flex;gap:8px}
.pic{width:32mm;height:40mm;border:1.5px solid #1F3C88;border-radius:6px;display:grid;place-items:center;overflow:hidden;background:#F6F8FC;font-size:10px;color:#6B7A99;text-align:center;padding:4px}
.pic img{width:100%;height:100%;object-fit:cover}
.pic.logo img{object-fit:contain}
.st{display:grid;grid-template-columns:repeat(auto-fit,minmax(36mm,1fr));gap:6px;margin:12px 0 4px}
.st div{border:1px solid #D5DCE8;border-radius:7px;padding:5px 8px;font-size:10.5px;color:#3A4A66}
.st div b{display:block;font-size:12px;color:#0F1B33}
.st div.ok{border-color:#1E9E5A;background:#EEF9F2}
.st div.ok b::before{content:"✔ ";color:#1E9E5A}
h3{margin:12px 0 4px;font-size:12.5px;text-transform:uppercase;letter-spacing:.6px;color:#1F3C88;border-bottom:1px solid #D5DCE8;padding-bottom:3px;break-after:avoid}
table{width:100%;border-collapse:collapse}
td{padding:4px 6px;vertical-align:top;border-bottom:1px dotted #D5DCE8}
td.l{width:38%;color:#3A4A66;font-size:11.5px}
td.v{font-weight:600;white-space:pre-wrap;word-break:break-word}
td.v.m{color:#8A96AD;font-weight:500}
.blank td.v{height:9mm;border-bottom:1px solid #9AA6BD}
.sg{display:grid;grid-template-columns:repeat(auto-fit,minmax(55mm,1fr));gap:8px;margin-top:4px}
.sg figure{margin:0;border:1px solid #D5DCE8;border-radius:7px;padding:6px;break-inside:avoid}
.sg img{display:block;max-width:100%;max-height:30mm;margin:0 auto 4px;background:#fff}
.sg figcaption{font-size:10.5px;color:#3A4A66}
.sg figcaption b{display:block;color:#0F1B33;font-size:11.5px}
.needs{columns:2;margin:4px 0 0;padding-left:16px;font-size:11.5px}
.decl{margin-top:10px;font-size:11px;color:#3A4A66;border:1px solid #D5DCE8;border-radius:7px;padding:7px 9px;break-inside:avoid}
.office{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-top:10px;break-inside:avoid}
.office div{border:1px solid #9AA6BD;border-radius:7px;height:22mm;padding:5px 7px;font-size:10.5px;color:#3A4A66}
.seal{display:flex;justify-content:space-between;gap:10px;margin-top:10px;font-size:10.5px;color:#3A4A66;border-top:2px solid #1F3C88;padding-top:5px}
.seal b{font-family:Consolas,monospace;color:#0F1B33;letter-spacing:.5px}
.wm{position:absolute;inset:auto 0 40% 0;text-align:center;font-size:64px;font-weight:800;color:rgba(31,60,136,.05);transform:rotate(-18deg);pointer-events:none}
@media print{html,body{background:#fff}.bar{display:none}.sheet{margin:0;width:auto;min-height:0;box-shadow:none;padding:0}}
@media (max-width:820px){.sheet{width:auto;margin:8px;padding:14px}.head{flex-direction:column-reverse}.lh{flex-wrap:wrap}.lh .ids{text-align:left}}
</style>
</head>
<body>
<div class="bar">
  <button class="pri" type="button" id="doPrint">🖨 छाप्नुहोस् · Print</button>
  <button class="pri" type="button" id="doPdf">📄 PDF</button>
  <button class="pri" type="button" id="doJpg">🖼 JPG</button>
  <?php if ($public): ?>
    <?php foreach (['staff' => 'कर्मचारी · Staff', 'agent' => 'एजेन्ट · Agent', 'partner' => 'साझेदार · Partner'] as $kk => $kl): ?><a href="?kind=<?= $h($kk) ?>"<?= !$mine && $kind === $kk ? ' style="outline:2px solid #F07A2E"' : '' ?>><?= $h($kl) ?></a><?php endforeach; ?>
    <a href="/agreements/">← <?= $h('फर्कनुहोस् · Back') ?></a>
  <?php else: ?>
    <?php if ($blank === ''): ?><a href="<?= $type === 'staff' ? 'staff-applications.php?id=' . (int) $id . '#detail' : 'agreements.php?tab=apps&amp;id=' . (int) $id . '#detail' ?>">← Back to the application</a><?php endif; ?>
    <a href="?blank=<?= $h($kind === 'partner' ? 'partner' : ($kind === 'staff' ? 'staff' : 'agent')) ?>">Blank form</a>
  <?php endif; ?>
  <small id="exMsg"><?= $mine ? 'तपाईंले भर्नुभएको फारम — PDF वा JPG मा राख्नुहोस्। · Your filled form.' : ($blank !== '' ? 'छापेर हातले भर्नुहोस्, वा अनलाइन भर्नुहोस्: ' . ($kind === 'staff' ? '/agreements/staff.html' : '/agreements/apply.html' . ($kind === 'partner' ? '?kind=partner' : '')) . ' · Print and fill by hand, or apply online.' : 'In the print dialog choose “Save as PDF”, paper A4, margins Default.' . (!$canKyc ? ' Photo, ID, signatures and bank details print only for a manager.' : '')) ?></small>
</div>
<div class="sheet">
  <div class="wm"><?= $h($co['name']) ?></div>
  <div class="lh">
    <img src="<?= $h($logo) ?>" alt="<?= $h($co['name']) ?> logo">
    <div class="co"><b><?= $h(mb_strtoupper((string) $co['legal'])) ?></b>
      <span><?= $h($co['address']) ?></span>
      <span>📞 <?= $h($co['phone']) ?> · ✉ <?= $h($co['email']) ?> · 🌐 <?= $h($co['web']) ?></span></div>
    <div class="ids"><?php if ((string) ($co['cin'] ?? '') !== ''): ?>CIN <?= $h($co['cin']) ?><br><?php endif; ?><?php if ((string) ($co['gstin'] ?? '') !== ''): ?>GSTIN <?= $h($co['gstin']) ?><br><?php endif; ?>India ⇄ Nepal bus service</div>
  </div>
  <div class="stripe"></div>

  <div class="head">
    <div class="t">
      <h1><?= $h($title) ?></h1>
      <h2><?= $h($titleNe) ?></h2>
      <div class="meta">
        <?php if ($ref !== ''): ?><span>Ref <b><?= $h($ref) ?></b></span><?php endif; ?>
        <span>Printed <b><?= $h(date('d M Y, H:i')) ?></b></span>
        <?php if ($mine): ?><span>Sent online by <b>the applicant</b></span><?php elseif ($blank === ''): ?><span>By <b><?= $h(($admin['full_name'] ?? '') ?: ($admin['username'] ?? '')) ?></b></span><?php else: ?><span>Form no. <b>_____________</b></span><?php endif; ?>
      </div>
    </div>
    <div class="pics">
      <?php if ($kind === 'partner'): ?><div class="pic logo"><?= $coLogo !== '' ? '<img src="' . $h($coLogo) . '" alt="Company logo">' : 'Company logo' ?></div><?php endif; ?>
      <div class="pic"><?= $photo !== '' ? '<img src="' . $h($photo) . '" alt="Photo">' : ($blank !== '' ? 'Paste passport-size photo' : 'Photo — ' . ($canKyc ? 'not sent' : 'manager only')) ?></div>
    </div>
  </div>

  <?php if ($status !== []): ?>
  <div class="st"><?php foreach ($status as [$l, $v, $ok]): ?><div class="<?= $ok ? 'ok' : '' ?>"><?= $h($l) ?><b><?= $h($v) ?></b></div><?php endforeach; ?></div>
  <?php endif; ?>

  <?php foreach ($sections as $sec => $rows): ?>
  <h3><?= $h($sec) ?></h3>
  <table class="<?= $blank !== '' ? 'blank' : '' ?>"><?php foreach ($rows as [$l, $v, $m]): ?><tr><td class="l"><?= $h($l) ?></td><td class="v<?= $m ? ' m' : '' ?>"><?= $h($v) ?></td></tr><?php endforeach; ?></table>
  <?php endforeach; ?>

  <h3>KYC checklist <?= $kind === 'partner' ? '(company)' : '' ?></h3>
  <ul class="needs"><?php foreach ($needs as $n): ?><li><?= $h($n) ?></li><?php endforeach; ?></ul>

  <h3>Signatures &amp; documents</h3>
  <?php if ($signs !== []): ?>
  <div class="sg"><?php foreach ($signs as [$l, $img, $d]): ?><figure><?php if ($img !== ''): ?><img src="<?= $h($img) ?>" alt="<?= $h($l) ?>"><?php endif; ?><figcaption><b><?= $h($l) ?></b><?= $h($d) ?></figcaption></figure><?php endforeach; ?></div>
  <?php else: ?>
  <div class="sg"><figure style="height:26mm"><figcaption><b>Applicant’s signature</b></figcaption></figure><figure style="height:26mm"><figcaption><b><?= $kind === 'partner' ? 'Company seal + representative' : 'Thumb impression' ?></b></figcaption></figure></div>
  <?php endif; ?>

  <div class="decl">I declare that the details above are true and the documents are mine<?= $kind === 'partner' ? ' / my company’s, and that I am authorised to sign for the company' : '' ?>. I agree to the <?= $h($kind === 'staff' ? 'staff' : ($kind === 'partner' ? 'partner' : 'agent')) ?> agreement of <?= $h($co['legal']) ?>.<br>
  म माथिका विवरण साँचो हुन् र कागजात मेरै<?= $kind === 'partner' ? ' / कम्पनीकै' : '' ?> हुन् भनी घोषणा गर्छु।</div>

  <div class="office"><div>Office use — checked by (name)</div><div>Original ID seen ☐ · KYC verified ☐ · Approved ☐</div><div>Signature, date &amp; stamp</div></div>

  <div class="seal">
    <span><?= $seal !== '' ? 'Document seal <b>' . $h($seal) . '</b> — matches this record only; re-open the application to check.' : 'Blank form — attach copies of every document in the checklist.' ?></span>
    <span><?= $h($co['web']) ?></span>
  </div>
</div>
<script>
document.getElementById('doPrint').addEventListener('click', function () { window.print(); });
/* 22 Oct 2026 (owner: "PDF ya JPG format ma letter head jasto A4"): the sheet
   exactly as shown, rendered by html2canvas (cdnjs, jsdelivr fallback — the
   same loader pattern as assets/js/11-pdf-ticket.js) and saved as one JPG or
   as an A4 PDF cut into pages. The libraries load only on the first tap. */
(function () {
  var msg = document.getElementById('exMsg'), sheet = document.querySelector('.sheet');
  var name = <?= json_encode(($ref !== '' ? $ref : 'blank-' . $kind) . '-' . date('Ymd'), JSON_UNESCAPED_SLASHES) ?>;
  function load(urls) {
    return new Promise(function (ok, bad) {
      var i = 0;
      (function next() {
        if (i >= urls.length) { bad(new Error('load')); return; }
        var s = document.createElement('script'); s.src = urls[i++]; s.onload = ok; s.onerror = next; document.head.appendChild(s);
      })();
    });
  }
  function libs() {
    var a = window.html2canvas ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js', 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js']);
    var b = (window.jspdf && window.jspdf.jsPDF) ? Promise.resolve() : load(['https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js', 'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js']);
    return Promise.all([a, b]);
  }
  function shot() {
    var imgs = Array.prototype.slice.call(sheet.querySelectorAll('img'));
    var ready = Promise.all(imgs.map(function (im) { return im.complete ? 0 : new Promise(function (r) { im.onload = im.onerror = r; }); }));
    var fonts = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
    return Promise.all([ready, fonts]).then(function () {
      // the A4 layout even on a phone: render as a wide window, at twice the pixels
      return window.html2canvas(sheet, { scale: 2, backgroundColor: '#ffffff', useCORS: true, windowWidth: 1000, logging: false,
        onclone: function (doc) { var s = doc.querySelector('.sheet'); s.style.width = '210mm'; s.style.margin = '0'; s.style.boxShadow = 'none'; } });
    });
  }
  function save(blob, file) {
    var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file;
    document.body.appendChild(a); a.click(); setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1500);
  }
  function run(kind, btn) {
    var label = btn.textContent; btn.disabled = true; btn.textContent = '⏳ …';
    libs().then(shot).then(function (cv) {
      if (kind === 'jpg') {
        return new Promise(function (r) { cv.toBlob(function (b) { save(b, name + '.jpg'); r(); }, 'image/jpeg', 0.92); });
      }
      var pdf = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
      var pageH = Math.floor(cv.width * 297 / 210), y = 0, first = true;
      while (y < cv.height) {
        var h = Math.min(pageH, cv.height - y), part = document.createElement('canvas');
        part.width = cv.width; part.height = h;
        var c = part.getContext('2d'); c.fillStyle = '#fff'; c.fillRect(0, 0, part.width, h); c.drawImage(cv, 0, y, cv.width, h, 0, 0, cv.width, h);
        if (!first) pdf.addPage();
        pdf.addImage(part.toDataURL('image/jpeg', 0.92), 'JPEG', 0, 0, 210, h * 210 / cv.width);
        first = false; y += pageH;
      }
      save(pdf.output('blob'), name + '.pdf');
    }).catch(function () {
      if (msg) msg.textContent = 'अहिले बन्न सकेन — "छाप्नुहोस्" थिचेर "Save as PDF" रोज्नुहोस्। · Could not build it now: use Print → Save as PDF.';
    }).then(function () { btn.disabled = false; btn.textContent = label; });
  }
  document.getElementById('doPdf').addEventListener('click', function () { run('pdf', this); });
  document.getElementById('doJpg').addEventListener('click', function () { run('jpg', this); });
})();
<?php if ($autoPrint): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
</script>
</body>
</html>
