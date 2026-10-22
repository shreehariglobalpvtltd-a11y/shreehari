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

$blank = (string) ($_GET['blank'] ?? '');
$blank = in_array($blank, ['agent', 'partner', 'staff'], true) ? $blank : '';
$type  = $blank !== '' ? ($blank === 'staff' ? 'staff' : 'agent') : ((string) ($_GET['type'] ?? '') === 'staff' ? 'staff' : 'agent');
$admin = admin_boot($type === 'staff' ? 'drivers.view' : 'customers.view');

$role   = (string) ($admin['role'] ?? '');
$canKyc = Auth::isSuperadmin() || $role === 'manager';
$selfId = (int) ($admin['id'] ?? 0);
$h      = static fn($v): string => Security::e((string) ($v ?? ''));
$id     = (int) ($_GET['id'] ?? 0);
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
            $photo = StaffApps::fileUrl((int) $rec['id'], 'photo', $selfId);
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
                    $signs[] = [StaffApps::FILE_LABELS[$slot], StaffApps::fileUrl((int) $rec['id'], $slot, $selfId, true), 'as sent with the form'];
                }
            }
            if ((string) ($rec['signature_path'] ?? '') !== '') {
                $signs[] = ['Signature on the form', StaffApps::fileUrl((int) $rec['id'], 'signature', $selfId), 'drawn on the phone · ' . $fmtDT($rec['created_at'] ?? null)];
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
        'A4 application printed by admin #' . $selfId . ($canKyc ? ' (full KYC)' : ' (masked)'));
} else {
    $kind = $blank;
    $blankRows = static fn(array $labels): array => array_map(static fn($l) => [$l, '', false], $labels);
    if ($blank === 'staff') {
        $sections = [
            'Applicant' => $blankRows(['Full name', 'Job applied for (driver / conductor / helper / office / accountant / mechanic / other)', 'Mobile', 'WhatsApp', 'E-mail', 'Date of birth', 'Gender']),
            'Address' => $blankRows(['Permanent address', 'Current address', 'District · province · country']),
            'Identity' => $blankRows(['ID type and number', 'Driving licence no. · category · valid to (drivers)']),
            'Education & experience' => $blankRows(['Qualification', 'Experience (years) · previous employer']),
            'References' => $blankRows(['Reference 1 (name · phone)', 'Reference 2 (name · phone)', 'Emergency contact (name · phone · relation)']),
            'Bank' => $blankRows(['Account holder · bank · branch', 'Account number · IFSC / SWIFT']),
        ];
    } elseif ($blank === 'partner') {
        $sections = [
            'Company' => $blankRows(['Company name', 'Company type (Pvt. Ltd. / firm / other)', 'Registration no. (with certificate copy)', 'PAN / VAT / GST no. (with certificate copy)', 'Registered office address', 'Company phone · e-mail']),
            'Authorised representative' => $blankRows(['Name', 'Designation', 'Mobile', 'ID type and number (copy attached)']),
            'Work' => $blankRows(['Work area / office', 'Education & experience of the company']),
            'Bank / payment' => $blankRows(['Account name', 'Bank · branch', 'Account number · IFSC', 'UPI']),
            'Witnesses' => $blankRows(['Witness 1 (name · address)', 'Witness 2 (name · address)']),
        ];
    } else {
        $sections = [
            'Applicant' => $blankRows(['Full name', 'Mobile', 'E-mail', 'Date of birth', 'Occupation', 'Work area', 'Home address']),
            'Identity' => $blankRows(['ID type and number (copy attached)', 'PAN']),
            'Education & experience' => $blankRows(['Education', 'Experience']),
            'Bank / payment' => $blankRows(['Account name', 'Bank · branch', 'Account number · IFSC', 'UPI']),
            'Witnesses' => $blankRows(['Witness 1 (name · address)', 'Witness 2 (name · address)']),
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
  <button class="pri" type="button" id="doPrint">🖨 Print / Save as PDF (A4)</button>
  <?php if ($blank === ''): ?><a href="<?= $type === 'staff' ? 'staff-applications.php?id=' . (int) $id . '#detail' : 'agreements.php?tab=apps&amp;id=' . (int) $id . '#detail' ?>">← Back to the application</a><?php endif; ?>
  <a href="?blank=<?= $h($kind === 'partner' ? 'partner' : ($kind === 'staff' ? 'staff' : 'agent')) ?>">Blank form</a>
  <small>In the print dialog choose “Save as PDF”, paper A4, margins Default.<?= $blank === '' && !$canKyc ? ' Photo, ID, signatures and bank details print only for a manager.' : '' ?></small>
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
        <?php if ($blank === ''): ?><span>By <b><?= $h(($admin['full_name'] ?? '') ?: ($admin['username'] ?? '')) ?></b></span><?php else: ?><span>Form no. <b>_____________</b></span><?php endif; ?>
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
<?php if ($autoPrint): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
</script>
</body>
</html>
